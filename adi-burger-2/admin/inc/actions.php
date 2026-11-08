<?php
declare(strict_types=1);
defined('ADI') || exit;

function post(string $k, string $default = ''): string
{
    return trim((string)($_POST[$k] ?? $default));
}

function post_int(string $k, int $default = 0): int
{
    return (int)preg_replace('~[^\d-]~', '', (string)($_POST[$k] ?? (string)$default));
}

function handle_action(string $a, array $me): void
{
    $emp = (int)$me['id'];
    switch ($a) {
        case 'logout':
            admin_logout();
            redirect('login');

        // ---------- Mahsulotlar ----------
        case 'product.save':
            $id = post_int('id');
            $name = str_limit(post('name'), 150);
            $cat = post_int('category_id');
            $price = post_int('price');
            if ($name === '' || !val('SELECT id FROM categories WHERE id = ?', [$cat])) {
                throw new UserError('Nomi va kategoriyasini kiriting.');
            }
            if ($price < 0 || $price > 100_000_000) {
                throw new UserError("Narx noto'g'ri.");
            }
            $f = [$cat, $name, str_limit(post('description'), 255) ?: null, $price, post_int('sort'), post_int('is_active') ? 1 : 0, post_int('in_pos') ? 1 : 0, post_int('in_bot') ? 1 : 0, post_int('is_available') ? 1 : 0];
            if ($id) {
                q('UPDATE products SET category_id = ?, name = ?, description = ?, price = ?, sort = ?, is_active = ?, in_pos = ?, in_bot = ?, is_available = ? WHERE id = ?', [...$f, $id]);
            } else {
                q('INSERT INTO products (category_id, name, description, price, sort, is_active, in_pos, in_bot, is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $f);
                $id = (int)db()->lastInsertId();
            }
            if (!empty($_FILES['image']['name'])) {
                $img = product_image_save($_FILES['image'], $id);
                q('UPDATE products SET image = ? WHERE id = ?', [$img, $id]);
            }
            catalog_bump();
            audit('product.save', 'product', $id, ['name' => $name, 'price' => $price], $emp);
            flash('Mahsulot saqlandi');
            redirect('products');

        case 'product.toggle':
            q('UPDATE products SET is_available = 1 - is_available WHERE id = ?', [post_int('id')]);
            catalog_bump();
            audit('product.toggle', 'product', post_int('id'), [], $emp);
            flash('Holat o\'zgartirildi');
            redirect_back();

        case 'product.hide':
            // Mahsulot o'chirilmaydi (eski buyurtmalar tarixi buzilmasin) — faqat yashiriladi
            q('UPDATE products SET is_active = 0 WHERE id = ?', [post_int('id')]);
            catalog_bump();
            audit('product.hide', 'product', post_int('id'), [], $emp);
            flash('Mahsulot yashirildi (tarixda qoladi)');
            redirect('products');

        // ---------- Kategoriyalar ----------
        case 'category.save':
            $id = post_int('id');
            $name = str_limit(post('name'), 100);
            if ($name === '') {
                throw new UserError('Kategoriya nomini kiriting.');
            }
            $f = [$name, str_limit(post('icon'), 16), post_int('sort'), post_int('is_active') ? 1 : 0];
            $id ? q('UPDATE categories SET name = ?, icon = ?, sort = ?, is_active = ? WHERE id = ?', [...$f, $id])
                : q('INSERT INTO categories (name, icon, sort, is_active) VALUES (?, ?, ?, ?)', $f);
            catalog_bump();
            audit('category.save', 'category', $id, ['name' => $name], $emp);
            flash('Kategoriya saqlandi');
            redirect('categories');

        // ---------- Stollar ----------
        case 'table.save':
            $id = post_int('id');
            $name = str_limit(post('name'), 50);
            if ($name === '') {
                throw new UserError('Stol nomini kiriting.');
            }
            $f = [$name, post_int('sort'), post_int('is_active') ? 1 : 0];
            $id ? q('UPDATE dining_tables SET name = ?, sort = ?, is_active = ? WHERE id = ?', [...$f, $id])
                : q('INSERT INTO dining_tables (name, sort, is_active) VALUES (?, ?, ?)', $f);
            catalog_bump();
            flash('Stol saqlandi');
            redirect('tables');

        case 'table.bulk':
            $n = max(1, min(100, post_int('count')));
            $start = (int)val('SELECT COUNT(*) FROM dining_tables') + 1;
            for ($i = 0; $i < $n; $i++) {
                q('INSERT INTO dining_tables (name, sort) VALUES (?, ?)', ['Stol ' . ($start + $i), $start + $i]);
            }
            catalog_bump();
            flash("$n ta stol qo'shildi");
            redirect('tables');

        // ---------- Xodimlar ----------
        case 'employee.save':
            $id = post_int('id');
            $name = str_limit(post('name'), 100);
            $role = post('role') === 'admin' ? 'admin' : 'cashier';
            $pin = post('pin');
            if ($name === '') {
                throw new UserError('Ismini kiriting.');
            }
            if ($id && $id === $emp && ($role !== 'admin' || !post_int('is_active'))) {
                throw new UserError("O'zingizni administratorlikdan chiqara olmaysiz.");
            }
            if ($pin !== '') {
                if (!pin_valid_format($pin)) {
                    throw new UserError('PIN 4 ta raqam bo\'lsin.');
                }
                if (pin_taken($pin, $id)) {
                    throw new UserError('Bu PIN boshqa xodimda bor. Boshqa PIN tanlang.');
                }
            } elseif (!$id) {
                throw new UserError('Yangi xodim uchun PIN kiriting.');
            }
            tx(function () use (&$id, $name, $role, $pin) {
                if ($id) {
                    q('UPDATE employees SET name = ?, role = ?, is_active = ? WHERE id = ?', [$name, $role, post_int('is_active') ? 1 : 0, $id]);
                } else {
                    q('INSERT INTO employees (name, role, pin_salt, pin_hash) VALUES (?, ?, \'\', \'\')', [$name, $role]);
                    $id = (int)db()->lastInsertId();
                }
                if ($pin !== '') {
                    [$s, $h] = pin_make($pin);
                    q('UPDATE employees SET pin_salt = ?, pin_hash = ? WHERE id = ?', [$s, $h, $id]);
                }
                $user = post('username');
                $pass = (string)($_POST['password'] ?? '');
                if ($role === 'admin' && $user !== '') {
                    if (!preg_match('~^[a-zA-Z0-9_.-]{3,50}$~', $user)) {
                        throw new UserError('Login 3–50 lotin harf/raqam bo\'lsin.');
                    }
                    $ex = row('SELECT * FROM users WHERE employee_id = ?', [$id]);
                    if (val('SELECT id FROM users WHERE username = ? AND employee_id <> ?', [$user, $id])) {
                        throw new UserError('Bu login band.');
                    }
                    if ($ex) {
                        q('UPDATE users SET username = ? WHERE id = ?', [$user, $ex['id']]);
                        if ($pass !== '') {
                            if (mb_strlen($pass) < 8) {
                                throw new UserError('Parol kamida 8 belgi.');
                            }
                            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $ex['id']]);
                        }
                    } else {
                        if (mb_strlen($pass) < 8) {
                            throw new UserError('Admin panel uchun parol kamida 8 belgi.');
                        }
                        q('INSERT INTO users (employee_id, username, password_hash) VALUES (?, ?, ?)', [$id, $user, password_hash($pass, PASSWORD_DEFAULT)]);
                    }
                }
                if ($role !== 'admin') {
                    q('UPDATE users SET is_active = 0 WHERE employee_id = ?', [$id]);
                } else {
                    q('UPDATE users SET is_active = 1 WHERE employee_id = ?', [$id]);
                }
            });
            catalog_bump();
            audit('employee.save', 'employee', $id, ['name' => $name, 'role' => $role, 'pin_changed' => $pin !== ''], $emp);
            flash('Xodim saqlandi. Kassa yangi PIN ni keyingi sinxronlashda oladi.');
            redirect('employees');

        // ---------- Telegram buyurtmalari ----------
        case 'order.status':
            $o = order_get(post_int('id'));
            if (!$o) {
                throw new UserError('Buyurtma topilmadi');
            }
            tg_order_set_status((int)$o['id'], post('to'), post('reason'), $emp);
            flash('Buyurtma holati o\'zgardi');
            redirect('order', ['id' => $o['id']]);

        case 'receipt.review':
            $r = tx(fn() => card_receipt_review(post_int('id'), post('approve') === '1', post('note'), $emp));
            flash(post('approve') === '1' ? "To'lov tasdiqlandi" : 'Chek rad etildi');
            redirect('order', ['id' => $r['id'] ?? 0]);

        // ---------- Sozlamalar ----------
        case 'settings.payments':
            foreach (['pay_cash_bot', 'pay_card_bot', 'pay_hamyon', 'pos_pay_cash', 'pos_pay_terminal', 'pos_pay_click'] as $k) {
                setting_set($k, post_int($k) ? '1' : '0');
            }
            $cards = [];
            foreach ((array)($_POST['card_number'] ?? []) as $i => $num) {
                $num = trim(preg_replace('~[^\d ]~', '', (string)$num));
                if (strlen(str_replace(' ', '', $num)) >= 16) {
                    $cards[] = ['number' => trim(chunk_split(str_replace(' ', '', $num), 4, ' ')), 'holder' => str_limit($_POST['card_holder'][$i] ?? '', 60), 'bank' => str_limit($_POST['card_bank'][$i] ?? '', 40)];
                }
            }
            setting_set('cards', json_encode($cards, JSON_UNESCAPED_UNICODE));
            setting_set('hamyon_shop_id', str_limit(post('hamyon_shop_id'), 64));
            if (post('hamyon_shop_key') !== '') {
                setting_set('hamyon_shop_key', str_limit(post('hamyon_shop_key'), 128));
            }
            $u = post('hamyon_api_url') ?: 'https://hamyon-api.uz';
            if (!preg_match('~^https://[a-z0-9.-]+(/.*)?$~i', $u)) {
                throw new UserError('Hamyon API manzili https:// bilan boshlansin.');
            }
            setting_set('hamyon_api_url', $u);
            if (setting_on('pay_card_bot') && !$cards) {
                flash("Kartaga o'tkazma yoqilgan, lekin karta raqami kiritilmagan — mijozga ko'rinmaydi.", 'warn');
            }
            catalog_bump();
            audit('settings.payments', 'settings', '', [], $emp);
            flash("To'lov sozlamalari saqlandi");
            redirect('payments');

        case 'hamyon.test':
            $p = hamyon_ping();
            flash($p['msg'], $p['ok'] ? 'ok' : 'bad');
            redirect('payments');

        case 'settings.telegram':
            $tok = post('bot_token');
            if ($tok !== '') {
                if (!preg_match('~^\d{6,12}:[A-Za-z0-9_-]{30,}$~', $tok)) {
                    throw new UserError("Bot tokeni noto'g'ri ko'rinishda. @BotFather bergan tokenni to'liq nusxalang.");
                }
                setting_set('bot_token', $tok);
            }
            setting_set('admin_tg_ids', implode(',', array_filter(preg_split('~[\s,;]+~', post('admin_tg_ids')) ?: [], fn($x) => preg_match('~^-?\d+$~', $x))));
            foreach (['bot_enabled', 'bot_pickup', 'bot_delivery'] as $k) {
                setting_set($k, post_int($k) ? '1' : '0');
            }
            setting_set('contact_text', str_limit(post('contact_text'), 1000));
            setting_set('delivery_fee', (string)max(0, post_int('delivery_fee')));
            setting_set('min_order', (string)max(0, post_int('min_order')));
            foreach (['work_open', 'work_close'] as $k) {
                if (preg_match('~^\d{2}:\d{2}$~', post($k))) {
                    setting_set($k, post($k));
                }
            }
            audit('settings.telegram', 'settings', '', ['token_changed' => $tok !== ''], $emp);
            if ($tok !== '') {
                $r = tg_webhook_set();
                flash($r['ok'] ? 'Bot ulandi va webhook o\'rnatildi' : 'Token saqlandi, lekin webhook o\'rnatilmadi: ' . $r['error'], $r['ok'] ? 'ok' : 'bad');
            } else {
                flash('Telegram sozlamalari saqlandi');
            }
            catalog_bump();
            redirect('telegram');

        case 'telegram.webhook':
            $r = tg_webhook_set();
            flash($r['ok'] ? "Webhook qayta o'rnatildi" : 'Xato: ' . $r['error'], $r['ok'] ? 'ok' : 'bad');
            redirect_back();

        case 'telegram.test':
            $me2 = tg_api('getMe', [], 6);
            if (!$me2) {
                flash('Bot javob bermadi: ' . ($GLOBALS['TG_ERR'] ?? ''), 'bad');
            } else {
                $n = 0;
                foreach (tg_admin_ids() as $aid) {
                    $n += tg_send($aid, "✅ Test xabar: admin panel botga ulangan.") ? 1 : 0;
                }
                flash('@' . ($me2['result']['username'] ?? '') . " ishlayapti. Test xabar $n ta adminga yuborildi.");
            }
            redirect('telegram');

        case 'settings.pos':
            foreach (['brand_name' => 60, 'brand_phone' => 40, 'brand_address' => 120, 'receipt_footer' => 200] as $k => $len) {
                setting_set($k, str_limit(post($k), $len));
            }
            setting_set('paper_width', post('paper_width') === '58' ? '58' : '80');
            foreach (['kitchen_auto', 'customer_auto'] as $k) {
                setting_set($k, post_int($k) ? '1' : '0');
            }
            setting_set('kitchen_copies', (string)max(1, min(3, post_int('kitchen_copies'))));
            setting_set('customer_copies', (string)max(1, min(3, post_int('customer_copies'))));
            catalog_bump();
            audit('settings.pos', 'settings', '', [], $emp);
            flash('POS sozlamalari saqlandi. Kassa ularni bir necha soniyada oladi.');
            redirect('pos');

        case 'device.toggle':
            q('UPDATE devices SET is_active = 1 - is_active WHERE id = ?', [post_int('id')]);
            audit('device.toggle', 'device', post_int('id'), [], $emp);
            flash('Qurilma holati o\'zgardi');
            redirect('pos');

        // ---------- Diagnostika ----------
        case 'diag.run':
            $only = post('code') !== '' ? [post('code')] : null;
            diag_run($only);
            flash($only ? 'Qayta tekshirildi' : 'Barcha komponentlar tekshirildi');
            redirect('diagnostics');

        case 'diag.fix':
            $code = post('code');
            if (!isset(DIAG_FIXES[$code])) {
                throw new UserError('Bu komponent uchun avtomatik tuzatish yo\'q.');
            }
            if (DIAG_FIXES[$code]['confirm'] && post('confirmed') !== '1') {
                throw new UserError('Bu amal uchun alohida tasdiq kerak.');
            }
            $msg = diag_fix($code);
            audit('diag.fix', 'diag', $code, ['result' => $msg], $emp);
            diag_run([$code]);
            q("UPDATE error_logs SET fix_status = 'fixed', fixed_at = NOW() WHERE component = ? AND fix_status = 'open'", [$code === 'webhook' ? 'telegram' : $code]);
            flash('Tuzatish: ' . $msg);
            redirect('diagnostics');

        case 'error.resolve':
            q("UPDATE error_logs SET fix_status = ?, fixed_at = NOW() WHERE id = ?", [post('to') === 'ignored' ? 'ignored' : 'fixed', post_int('id')]);
            flash('Belgilandi');
            redirect('diagnostics');

        // ---------- Zaxira ----------
        case 'backup.create':
            $b = backup_create();
            audit('backup.create', 'backup', $b['name'], [], $emp);
            flash('Zaxira olindi: ' . $b['name']);
            redirect('backup');
    }
    throw new UserError("Noma'lum amal");
}

/** Mahsulot rasmi: qayta kodlanadi, kichik nusxasi yaratiladi. */
function product_image_save(array $f, int $id): string
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 10 * 1024 * 1024) {
        throw new UserError('Rasm yuklanmadi (10 MB gacha JPG/PNG/WEBP).');
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new UserError('Faqat JPG, PNG yoki WEBP rasm.');
    }
    $name = 'p' . $id . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $dir = ROOT . '/uploads/products';
    if (!extension_loaded('gd')) {
        if ($info[2] !== IMAGETYPE_JPEG) {
            throw new UserError('Serverda GD yo\'q — faqat JPG yuklang.');
        }
        move_uploaded_file($f['tmp_name'], "$dir/$name");
        return "uploads/products/$name";
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG => imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($f['tmp_name']),
    };
    foreach ([[1000, "$dir/$name", 84], [360, "$dir/thumb/$name", 78]] as [$max, $path, $qual]) {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1, $max / max($w, $h));
        $d = imagecreatetruecolor(max(1, (int)($w * $s)), max(1, (int)($h * $s)));
        imagefill($d, 0, 0, imagecolorallocate($d, 255, 255, 255));
        imagecopyresampled($d, $src, 0, 0, 0, 0, imagesx($d), imagesy($d), $w, $h);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        imagejpeg($d, $path, $qual);
    }
    return "uploads/products/$name";
}

function handle_download(string $what, array $me): void
{
    if ($what === 'backup') {
        $p = backup_path((string)($_GET['name'] ?? ''));
        if (!$p) {
            throw new UserError('Fayl topilmadi');
        }
        audit('backup.download', 'backup', basename($p), [], (int)$me['id']);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        return;
    }
    if ($what === 'bat') {
        // O'rnatuvchi: sayt manzili ichiga yozilgan holda
        $bat = (string)file_get_contents(ROOT . '/windows/kassa-ornatish.bat');
        $bat = str_replace('__SERVER__', rtrim(app_url(), '/'), $bat);
        $bat = preg_replace("~\r?\n~", "\r\n", $bat);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="kassa-ornatish.bat"');
        echo $bat;
        return;
    }
    if ($what === 'diag') {
        $checks = rows('SELECT code, title, status, message, advice, details, checked_at FROM diagnostic_checks ORDER BY code');
        $errors = rows("SELECT created_at, last_seen_at, component, code, message, advice, occurrences, fix_status FROM error_logs ORDER BY id DESC LIMIT 200");
        $txt = "ADI BURGER 2.0 — diagnostika hisoboti\nSana: " . date('Y-m-d H:i:s') . "\nServer versiyasi: " . ADI_VERSION . "\n\n";
        foreach ($checks as $c) {
            $txt .= sprintf("[%s] %s: %s\n", strtoupper($c['status']), $c['title'], $c['message']) . ($c['advice'] ? "   Tavsiya: {$c['advice']}\n" : '') . "   Tekshirilgan: {$c['checked_at']}\n";
        }
        $txt .= "\nXatolar tarixi:\n";
        foreach ($errors as $er) {
            $txt .= "{$er['last_seen_at']} [{$er['component']}/{$er['code']}] x{$er['occurrences']} {$er['fix_status']}: {$er['message']}\n";
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="diagnostika-' . date('Ymd-His') . '.txt"');
        echo redact($txt);
        return;
    }
    if ($what === 'receipt') {
        $r = row('SELECT * FROM card_receipts WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
        $p = $r ? ROOT . '/uploads/receipts/' . basename($r['file']) : '';
        if (!$r || !is_file($p)) {
            throw new UserError('Rasm topilmadi');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=600');
        readfile($p);
        return;
    }
    if ($what === 'report') {
        $from = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d');
        $to = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="savdo-' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Raqam', 'Sana', 'Manba', 'Turi', 'Holat', "To'lov", 'Jami', "To'langan", 'Kassir'], ';');
        foreach (rows('SELECT o.*, e.name AS emp FROM orders o LEFT JOIN employees e ON e.id = o.employee_id WHERE o.created_at BETWEEN ? AND ? ORDER BY o.id', [$from . ' 00:00:00', $to . ' 23:59:59']) as $o) {
            fputcsv($out, [$o['number'], $o['created_at'], $o['source'], ORDER_TYPES[$o['type']] ?? $o['type'], ORDER_STATUSES[$o['status']] ?? $o['status'], $o['pay_method'], $o['total'], $o['paid_total'], $o['emp']], ';');
        }
        return;
    }
    throw new UserError('Noma\'lum fayl');
}
