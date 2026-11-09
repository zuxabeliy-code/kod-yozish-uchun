<?php
declare(strict_types=1);

/**
 * Telegram WebApp API. Har bir so'rov Telegram initData imzosi bilan serverda tekshiriladi.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require_installed();
security_headers(false);

try {
    $isUpload = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $in = $isUpload ? $_POST : json_input();
    $user = tg_check_init_data((string)($in['initData'] ?? ''));
    if (!$user) {
        json_out(['ok' => false, 'error' => "Sessiya eskirgan. Botni yopib, qayta oching."], 401);
    }
    if (!rate_limit('webapp|' . $user['id'], 60, 60)) {
        json_out(['ok' => false, 'error' => "Juda ko'p so'rov. Birozdan keyin urinib ko'ring."], 429);
    }
    $cust = tg_customer($user);
    if ((int)$cust['is_blocked'] === 1) {
        json_out(['ok' => false, 'error' => 'Hisobingiz bloklangan.'], 403);
    }
    $action = (string)($in['action'] ?? '');

    switch ($action) {
        case 'init':
            json_out(['ok' => true] + webapp_init($cust));

        case 'order.create':
            if (!rate_limit('wa-order|' . $user['id'], 6, 600)) {
                throw new UserError("Juda ko'p buyurtma. 10 daqiqadan keyin urinib ko'ring.");
            }
            $o = tg_order_create($cust, $in);
            json_out(['ok' => true, 'order' => webapp_order($o, true)]);

        case 'order.get':
            $o = webapp_find($cust, (string)($in['uuid'] ?? ''));
            json_out(['ok' => true, 'order' => webapp_order($o, true)]);

        case 'orders':
            $list = rows("SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 15", [$cust['id']]);
            json_out(['ok' => true, 'orders' => array_map(fn($o) => webapp_order($o), $list)]);

        case 'hamyon.start':
            $o = webapp_find($cust, (string)($in['uuid'] ?? ''));
            if ($o['pay_method'] !== 'hamyon' || $o['status'] !== 'pending_pay') {
                throw new UserError("Bu buyurtma uchun Hamyon to'lovi kerak emas.");
            }
            hamyon_create($o);
            json_out(['ok' => true, 'order' => webapp_order(order_get((int)$o['id']), true)]);

        case 'order.cancel':
            $o = webapp_find($cust, (string)($in['uuid'] ?? ''));
            // To'lov kutilayotgan buyurtma doim bekor qilinadi; kassaga tushgan, lekin hali qabul qilinmagan va to'lanmagan buyurtma — qisqa vaqt ichida
            $mins = max(0, setting_int('cancel_minutes'));
            $fresh = $o['status'] === 'new' && $o['pay_status'] === 'unpaid' && time() - strtotime($o['created_at']) <= $mins * 60;
            if ($o['status'] !== 'pending_pay' && !$fresh) {
                throw new UserError("Buyurtma kassaga yuborilgan" . ($mins ? " ($mins daqiqadan oshdi yoki qabul qilindi)" : '') . ". Bekor qilish uchun qo'ng'iroq qiling.");
            }
            foreach (rows("SELECT * FROM hamyon_payments WHERE order_id = ? AND status = 'pending'", [$o['id']]) as $hp) {
                hamyon_request('POST', '/payment/cancel', hamyon_creds() + ['payment_id' => $hp['payment_id']]);
            }
            tg_order_set_status((int)$o['id'], 'cancelled', 'Mijoz bekor qildi');
            if ($fresh) {
                tg_send_admins("❌ Mijoz {$o['number']} buyurtmani bekor qildi (qabul qilinmasdan oldin).");
            }
            json_out(['ok' => true]);

        case 'receipt.upload':
            $o = webapp_find($cust, (string)($in['uuid'] ?? ''));
            if ($o['pay_method'] !== 'card' || $o['pay_status'] === 'paid' || in_array($o['status'], ['rejected', 'cancelled', 'done'], true)) {
                throw new UserError("Bu buyurtma uchun chek kerak emas.");
            }
            if (!rate_limit('wa-upload|' . $user['id'], 8, 600)) {
                throw new UserError("Juda ko'p urinish. Keyinroq urinib ko'ring.");
            }
            $file = receipt_save($_FILES['file'] ?? null);
            tx(function () use ($o, $file) {
                q("UPDATE card_receipts SET status = 'rejected', note = 'Yangi chek yuborildi' WHERE order_id = ? AND status = 'checking'", [$o['id']]);
                q('INSERT INTO card_receipts (order_id, file) VALUES (?, ?)', [$o['id'], $file]);
                q("UPDATE orders SET pay_status = 'checking', rev = rev + 1 WHERE id = ?", [$o['id']]);
            });
            if ($o['status'] === 'pending_pay') {
                $o = tg_order_set_status((int)$o['id'], 'new');
                tg_notify_admins_new($o);
            } else {
                tg_send_admins("🧾 {$o['number']} buyurtma uchun yangi to'lov cheki yuborildi. Kassada tekshiring.");
            }
            json_out(['ok' => true, 'order' => webapp_order(order_get((int)$o['id']), true)]);
    }
    json_out(['ok' => false, 'error' => "Noma'lum so'rov"], 404);
} catch (UserError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $id = log_exception($e, 'webapp');
    json_out(['ok' => false, 'error' => "Serverda xato. Birozdan keyin urinib ko'ring. (ID: $id)"], 500);
}

function webapp_init(array $cust): array
{
    $cats = rows('SELECT c.id, c.name, c.icon FROM categories c WHERE c.is_active = 1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1 AND p.in_bot = 1) ORDER BY c.sort, c.id');
    $products = rows('SELECT p.id, p.category_id, p.name, p.description, p.price, p.image, p.is_available FROM products p JOIN categories c ON c.id = p.category_id
                      WHERE p.is_active = 1 AND p.in_bot = 1 AND c.is_active = 1 ORDER BY p.sort, p.id');
    $pays = [];
    if (setting_on('pay_cash_bot')) {
        $pays[] = ['id' => 'cash', 'name' => 'Naqd pul', 'hint' => 'Olganda yoki yetkazilganda to\'laysiz'];
    }
    if (setting_on('pay_card_bot') && cards_list()) {
        $pays[] = ['id' => 'card', 'name' => "Kartaga o'tkazma", 'hint' => "Karta raqamiga o'tkazib, chekni shu yerga yuborasiz"];
    }
    if (setting_on('pay_hamyon') && hamyon_enabled()) {
        $pays[] = ['id' => 'hamyon', 'name' => 'Hamyon (avto to\'lov)', 'hint' => "To'lov avtomatik tasdiqlanadi"];
    }
    return [
        'brand' => setting('brand_name'),
        'enabled' => setting_on('bot_enabled'),
        'open' => is_work_time(),
        'work' => setting('work_open') . ' – ' . setting('work_close'),
        'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'icon' => $c['icon']], $cats),
        'products' => array_map(fn($p) => ['id' => (int)$p['id'], 'cat' => (int)$p['category_id'], 'name' => $p['name'], 'desc' => $p['description'],
            'price' => (int)$p['price'], 'img' => product_thumb($p['image']), 'ok' => (int)$p['is_available'] === 1], $products),
        'types' => array_values(array_filter([setting_on('bot_pickup') ? 'takeaway' : null, setting_on('bot_delivery') ? 'delivery' : null])),
        'delivery_fee' => setting_int('delivery_fee'),
        'notes' => ['delivery' => setting('bot_note_delivery'), 'pickup' => setting('bot_note_pickup'), 'pay' => setting('bot_note_pay')],
        'min_order' => setting_int('min_order'),
        'pays' => $pays,
        'customer' => ['name' => $cust['name'], 'phone' => $cust['phone'], 'address' => $cust['last_address']],
        'contact' => setting('contact_text'),
    ];
}

function webapp_find(array $cust, string $uuid): array
{
    $o = is_uuid($uuid) ? row('SELECT * FROM orders WHERE uuid = ? AND customer_id = ?', [$uuid, $cust['id']]) : null;
    if (!$o) {
        throw new UserError('Buyurtma topilmadi');
    }
    return $o;
}

function webapp_order(array $o, bool $detail = false): array
{
    $out = order_export($o, false);
    $out['status_label'] = ORDER_STATUSES[$o['status']] ?? $o['status'];
    $out['address'] = $o['address'];
    $out['reject_reason'] = $o['reject_reason'];
    if ($detail) {
        $out['can_cancel'] = $o['status'] === 'pending_pay' || ($o['status'] === 'new' && $o['pay_status'] === 'unpaid' && time() - strtotime($o['created_at']) <= max(0, setting_int('cancel_minutes')) * 60);
        if ($o['pay_method'] === 'card' && $o['pay_status'] !== 'paid') {
            $out['cards'] = cards_list();
            $r = row('SELECT status, note FROM card_receipts WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$o['id']]);
            $out['receipt'] = $r ? ['status' => $r['status'], 'note' => $r['note']] : null;
        }
        if ($o['pay_method'] === 'hamyon' && $o['status'] === 'pending_pay') {
            $hp = hamyon_open_payment((int)$o['id']);
            if ($hp) {
                $hp = hamyon_refresh($hp);
                $o = order_get((int)$o['id']);
                $out['status'] = $o['status'];
                $out['pay_status'] = $o['pay_status'];
                $out['status_label'] = ORDER_STATUSES[$o['status']] ?? $o['status'];
            }
            $out['hamyon'] = $hp && $hp['status'] === 'pending' ? ['card' => $hp['card'], 'amount' => (int)$hp['pay_amount'], 'expires_in' => max(0, (int)$hp['expire_at'] - time())] : null;
        }
    }
    return $out;
}

/** To'lov cheki rasmini xavfsiz saqlaydi: faqat rasm, 8 MB gacha, qayta kodlanadi (EXIF va yashirin kod olib tashlanadi). */
function receipt_save(?array $f): string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new UserError('Chek rasmini tanlang.');
    }
    if ($f['size'] > 8 * 1024 * 1024) {
        throw new UserError('Rasm juda katta (8 MB dan oshmasin).');
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new UserError('Faqat rasm (JPG, PNG yoki WEBP) yuboring.');
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.jpg';
    $dest = ROOT . '/uploads/receipts/' . $name;
    if (extension_loaded('gd')) {
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
            IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false,
        };
        if (!$src) {
            throw new UserError("Rasmni o'qib bo'lmadi. Boshqa rasm yuboring.");
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 1600 / max($w, $h));
        $dst = imagecreatetruecolor((int)($w * $scale), (int)($h * $scale));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, (int)($w * $scale), (int)($h * $scale), $w, $h);
        imagejpeg($dst, $dest, 82);
    } else {
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new UserError('Iltimos, JPG formatidagi rasm yuboring.');
        }
        move_uploaded_file($f['tmp_name'], $dest);
    }
    return $name;
}
