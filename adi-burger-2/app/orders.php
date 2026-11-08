<?php
declare(strict_types=1);
defined('ADI') || exit;

const ORDER_TYPES = ['table' => 'Stolda', 'takeaway' => 'Olib ketish', 'phone' => 'Telefon', 'delivery' => 'Yetkazish'];
const ORDER_STATUSES = [
    'pending_pay' => "To'lov kutilmoqda", 'new' => 'Yangi', 'accepted' => 'Qabul qilindi', 'ready' => 'Tayyor',
    'done' => 'Yakunlandi', 'rejected' => 'Rad etildi', 'cancelled' => 'Bekor qilindi',
];
const PAY_STATUSES = ['unpaid' => "To'lanmagan", 'checking' => 'Tekshirilmoqda', 'partial' => "Qisman to'langan", 'paid' => "To'langan", 'refunded' => 'Qaytarilgan'];
const PAY_METHODS = ['cash' => 'Naqd', 'terminal' => 'Terminal', 'click' => 'Click', 'card_transfer' => "Kartaga o'tkazma", 'hamyon' => 'Hamyon'];

/** Telegram buyurtmasi holatlari o'tishi (server nazorat qiladi). */
const TG_FLOW = [
    'pending_pay' => ['new', 'cancelled'],
    'new' => ['accepted', 'rejected', 'cancelled'],
    'accepted' => ['ready', 'done', 'cancelled'],
    'ready' => ['done', 'cancelled'],
];

/** Savdoga kiradigan buyurtma holatlari */
const SOLD_STATUSES_SQL = "('accepted','ready','done')";

function order_by_uuid(string $uuid): ?array
{
    return row('SELECT * FROM orders WHERE uuid = ?', [$uuid]);
}

function order_get(int $id): ?array
{
    return row('SELECT * FROM orders WHERE id = ?', [$id]);
}

function order_items(int $orderId): array
{
    return rows('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

function order_payments(int $orderId): array
{
    return rows("SELECT * FROM payments WHERE order_id = ? AND status = 'ok' ORDER BY id", [$orderId]);
}

/** To'lovlar yig'indisidan to'lov holatini hisoblaydi. */
function order_recalc_payment(int $orderId): void
{
    $o = order_get($orderId);
    if (!$o) {
        return;
    }
    $paid = (int)val("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'ok'", [$orderId]);
    $methods = array_column(rows("SELECT DISTINCT method FROM payments WHERE order_id = ? AND status = 'ok'", [$orderId]), 'method');
    $payStatus = $o['pay_status'];
    if ($paid >= (int)$o['total'] && (int)$o['total'] > 0) {
        $payStatus = 'paid';
    } elseif ($paid > 0) {
        $payStatus = 'partial';
    } elseif ($payStatus !== 'checking') {
        $payStatus = 'unpaid';
    }
    $pm = $o['pay_method'];
    if ($methods) {
        $pm = count($methods) > 1 ? 'mixed' : (match ($methods[0]) {
            'cash' => 'cash', 'hamyon' => 'hamyon', default => 'card',
        });
    }
    q('UPDATE orders SET paid_total = ?, pay_status = ?, pay_method = ? WHERE id = ?', [$paid, $payStatus, $pm, $orderId]);
}

/**
 * Kassadan kelgan buyurtma snapshotini saqlaydi (offline sinxronlash).
 * Idempotent: bir xil yoki eskiroq rev qayta kelsa hech narsa o'zgarmaydi.
 */
function order_apply_snapshot(array $s, ?array $device): array
{
    if (!is_uuid($s['uuid'] ?? null)) {
        throw new UserError('Buyurtma UUID noto\'g\'ri');
    }
    $type = (string)($s['type'] ?? '');
    if (!isset(ORDER_TYPES[$type])) {
        throw new UserError('Buyurtma turi noto\'g\'ri');
    }
    $status = (string)($s['status'] ?? 'accepted');
    if (!in_array($status, ['accepted', 'done', 'cancelled'], true)) {
        throw new UserError('Kassa buyurtmasi holati noto\'g\'ri: ' . $status);
    }
    $rev = max(1, (int)($s['rev'] ?? 1));
    $items = is_array($s['items'] ?? null) ? $s['items'] : [];
    if (count($items) > 300) {
        throw new UserError('Buyurtmada taomlar juda ko\'p');
    }

    return tx(function () use ($s, $type, $status, $rev, $items, $device) {
        $ex = row('SELECT * FROM orders WHERE uuid = ? FOR UPDATE', [$s['uuid']]);
        if ($ex && $ex['source'] !== 'pos') {
            throw new UserError('Bu buyurtma kassada yaratilmagan');
        }
        if ($ex && (int)$ex['rev'] >= $rev) {
            return ['id' => (int)$ex['id'], 'skipped' => true];
        }
        $shiftId = null;
        if (is_uuid($s['shift_uuid'] ?? null)) {
            $shiftId = val('SELECT id FROM shifts WHERE uuid = ?', [$s['shift_uuid']]);
        }
        $tableId = null;
        if ($type === 'table' && !empty($s['table_id'])) {
            $tableId = val('SELECT id FROM dining_tables WHERE id = ?', [(int)$s['table_id']]);
        }
        $empId = !empty($s['employee_id']) ? val('SELECT id FROM employees WHERE id = ?', [(int)$s['employee_id']]) : null;

        // Summalarni serverda qayta hisoblaymiz
        $itemsTotal = 0;
        $clean = [];
        foreach ($items as $it) {
            if (!is_uuid($it['uuid'] ?? null)) {
                throw new UserError('Taom UUID noto\'g\'ri');
            }
            $qty = max(0, min(999, (int)($it['qty'] ?? 0)));
            $price = max(0, min(100_000_000, (int)($it['price'] ?? 0)));
            $pid = !empty($it['product_id']) ? val('SELECT id FROM products WHERE id = ?', [(int)$it['product_id']]) : null;
            $clean[] = [
                'uuid' => $it['uuid'], 'product_id' => $pid, 'name' => str_limit($it['name'] ?? '?', 150) ?: '?',
                'price' => $price, 'qty' => $qty,
                'sent_qty' => max(0, min(999, (int)($it['sent_qty'] ?? 0))),
                'cancelled_qty' => max(0, min(999, (int)($it['cancelled_qty'] ?? 0))),
                'comment' => str_limit($it['comment'] ?? '', 255) ?: null,
                'created_at' => to_dt($it['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
            ];
            $itemsTotal += $price * $qty;
        }
        $fee = $type === 'delivery' ? max(0, (int)($s['delivery_fee'] ?? 0)) : 0;
        $total = $status === 'cancelled' ? 0 : $itemsTotal + $fee;

        $fields = [
            'number' => str_limit($s['number'] ?? '?', 20) ?: '?',
            'type' => $type, 'table_id' => $tableId, 'bill_no' => max(1, min(20, (int)($s['bill_no'] ?? 1))),
            'shift_id' => $shiftId, 'employee_id' => $empId, 'device_id' => $device['id'] ?? null,
            'customer_name' => str_limit($s['customer_name'] ?? '', 100) ?: null,
            'phone' => str_limit($s['phone'] ?? '', 20) ?: null,
            'address' => str_limit($s['address'] ?? '', 255) ?: null,
            'comment' => str_limit($s['comment'] ?? '', 500) ?: null,
            'status' => $status, 'items_total' => $itemsTotal, 'delivery_fee' => $fee, 'total' => $total, 'rev' => $rev,
            'cancel_reason' => str_limit($s['cancel_reason'] ?? '', 255) ?: null,
            'created_at' => to_dt($s['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
            'accepted_at' => to_dt($s['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
            'closed_at' => to_dt($s['closed_at'] ?? ''),
        ];
        if ($ex) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            q("UPDATE orders SET $sets WHERE id = ?", [...array_values($fields), $ex['id']]);
            $orderId = (int)$ex['id'];
        } else {
            $fields['uuid'] = $s['uuid'];
            $fields['source'] = 'pos';
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            q("INSERT INTO orders ($cols) VALUES ($ph)", array_values($fields));
            $orderId = (int)db()->lastInsertId();
        }

        // Taomlar: uuid bo'yicha upsert
        $keep = [];
        foreach ($clean as $it) {
            q('INSERT INTO order_items (uuid, order_id, product_id, name, price, qty, sent_qty, cancelled_qty, comment, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), name = VALUES(name), price = VALUES(price), qty = VALUES(qty),
                 sent_qty = VALUES(sent_qty), cancelled_qty = VALUES(cancelled_qty), comment = VALUES(comment)',
                [$it['uuid'], $orderId, $it['product_id'], $it['name'], $it['price'], $it['qty'], $it['sent_qty'], $it['cancelled_qty'], $it['comment'], $it['created_at']]);
            $keep[] = $it['uuid'];
        }
        // Snapshotda yo'q (oshxonaga yuborilmagan, bekor tarixi yo'q) taomlar o'chiriladi
        $params = [$orderId];
        $notIn = '';
        if ($keep) {
            $notIn = ' AND uuid NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
            $params = array_merge($params, $keep);
        }
        q("DELETE FROM order_items WHERE order_id = ? $notIn AND sent_qty = 0 AND id NOT IN (SELECT order_item_id FROM item_cancels WHERE order_item_id IS NOT NULL)", $params);

        // Bekor qilishlar tarixi (faqat qo'shiladi, o'chirilmaydi)
        foreach ((array)($s['cancels'] ?? []) as $c) {
            if (!is_uuid($c['uuid'] ?? null)) {
                continue;
            }
            $itemId = is_uuid($c['item_uuid'] ?? null) ? val('SELECT id FROM order_items WHERE uuid = ?', [$c['item_uuid']]) : null;
            q('INSERT IGNORE INTO item_cancels (uuid, order_id, order_item_id, name, price, qty, reason, employee_id, shift_id, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $c['uuid'], $orderId, $itemId, str_limit($c['name'] ?? '?', 150), max(0, (int)($c['price'] ?? 0)), max(1, (int)($c['qty'] ?? 1)),
                str_limit($c['reason'] ?? '-', 255) ?: '-',
                !empty($c['employee_id']) ? val('SELECT id FROM employees WHERE id = ?', [(int)$c['employee_id']]) : null,
                $shiftId, to_dt($c['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
            ]);
        }

        // To'lovlar (takroriy to'lov uuid tufayli yaratilmaydi)
        foreach ((array)($s['payments'] ?? []) as $p) {
            payment_insert($orderId, $p, $shiftId);
        }

        if ($type === 'delivery' && $fields['address']) {
            q('INSERT INTO deliveries (order_id, address, fee, status) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE address = VALUES(address), fee = VALUES(fee), status = VALUES(status)',
                [$orderId, $fields['address'], $fee, $status === 'done' ? 'delivered' : ($status === 'cancelled' ? 'cancelled' : 'waiting')]);
        }
        order_recalc_payment($orderId);
        return ['id' => $orderId, 'skipped' => false];
    });
}

function payment_insert(int $orderId, array $p, ?int $shiftId): bool
{
    if (!is_uuid($p['uuid'] ?? null)) {
        throw new UserError('To\'lov UUID noto\'g\'ri');
    }
    $method = (string)($p['method'] ?? '');
    if (!isset(PAY_METHODS[$method])) {
        throw new UserError('To\'lov turi noto\'g\'ri: ' . $method);
    }
    $amount = (int)($p['amount'] ?? 0);
    if ($amount <= 0 || $amount > 1_000_000_000) {
        throw new UserError('To\'lov summasi noto\'g\'ri');
    }
    if ($shiftId === null && is_uuid($p['shift_uuid'] ?? null)) {
        $shiftId = val('SELECT id FROM shifts WHERE uuid = ?', [$p['shift_uuid']]);
    }
    return q('INSERT IGNORE INTO payments (uuid, order_id, shift_id, method, amount, received, change_amount, employee_id, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $p['uuid'], $orderId, $shiftId, $method, $amount,
        isset($p['received']) ? max(0, (int)$p['received']) : null, max(0, (int)($p['change'] ?? 0)),
        !empty($p['employee_id']) ? val('SELECT id FROM employees WHERE id = ?', [(int)$p['employee_id']]) : null,
        to_dt($p['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
    ])->rowCount() === 1;
}

/**
 * Telegram buyurtmasi holatini o'zgartiradi (kassa yoki admin paneldan).
 * Ruxsat etilmagan o'tishda UserError. Mijozga Telegram xabari yuboriladi.
 */
function tg_order_set_status(int $id, string $to, string $reason = '', ?int $employeeId = null): array
{
    $o = tx(function () use ($id, $to, $reason, $employeeId) {
        $o = row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$o || $o['source'] !== 'telegram') {
            throw new UserError('Buyurtma topilmadi');
        }
        if ($o['status'] === $to) {
            return null; // Takroriy so'rov — hech narsa qilmaymiz
        }
        if (!in_array($to, TG_FLOW[$o['status']] ?? [], true)) {
            throw new UserError('Buyurtma holati allaqachon o\'zgargan: ' . (ORDER_STATUSES[$o['status']] ?? $o['status']));
        }
        // To'lov oldindan bo'lishi kerak bo'lgan buyurtma (karta, Hamyon) pul tasdiqlanmaguncha qabul qilinmaydi
        if (in_array($to, ['accepted', 'ready', 'done'], true) && in_array($o['pay_method'], ['card', 'hamyon'], true) && $o['pay_status'] !== 'paid') {
            throw new UserError("To'lov hali tasdiqlanmagan. Avval mijoz yuborgan chekni tekshirib, \"To'lov tushdi\" ni bosing.");
        }
        $sets = ['status = ?', 'rev = rev + 1'];
        $p = [$to];
        if ($to === 'accepted') {
            $sets[] = 'accepted_at = NOW()';
            $sets[] = 'kitchen_needed = 1';
            if ($employeeId) {
                $sets[] = 'employee_id = ?';
                $p[] = $employeeId;
            }
        }
        if ($to === 'rejected') {
            $sets[] = 'reject_reason = ?';
            $p[] = str_limit($reason, 255);
        }
        if ($to === 'cancelled') {
            $sets[] = 'cancel_reason = ?';
            $p[] = str_limit($reason, 255);
        }
        if (in_array($to, ['done', 'rejected', 'cancelled'], true)) {
            $sets[] = 'closed_at = NOW()';
        }
        $p[] = $id;
        q('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $p);
        if ($to === 'done') {
            q("UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE order_id = ?", [$id]);
        }
        if (in_array($to, ['rejected', 'cancelled'], true)) {
            q("UPDATE deliveries SET status = 'cancelled' WHERE order_id = ?", [$id]);
            // Hamyon ochiq to'lovi bo'lsa — bekor
            foreach (rows("SELECT payment_id FROM hamyon_payments WHERE order_id = ? AND status = 'pending'", [$id]) as $h) {
                q("UPDATE hamyon_payments SET status = 'cancel' WHERE payment_id = ?", [$h['payment_id']]);
            }
        }
        return order_get($id);
    });
    if ($o === null) {
        return order_get($id) ?? [];
    }
    audit('order.status', 'order', $id, ['to' => $to, 'reason' => $reason], $employeeId);
    tg_notify_order($o);
    return $o;
}

/** Telegram buyurtmasini yaratadi (WebApp). Narxlar serverdagi menyudan olinadi. */
function tg_order_create(array $customer, array $in): array
{
    if (!setting_on('bot_enabled')) {
        throw new UserError('Buyurtma qabul qilish vaqtincha to\'xtatilgan.');
    }
    if (!is_work_time()) {
        throw new UserError('Hozir ish vaqti emas. Ish vaqti: ' . setting('work_open') . ' – ' . setting('work_close'));
    }
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, ['takeaway', 'delivery'], true) || ($type === 'takeaway' && !setting_on('bot_pickup')) || ($type === 'delivery' && !setting_on('bot_delivery'))) {
        throw new UserError('Buyurtma turini tanlang.');
    }
    $phone = normalize_phone((string)($in['phone'] ?? ''));
    if ($phone === '') {
        throw new UserError('Telefon raqamini to\'g\'ri kiriting: +998 XX XXX XX XX');
    }
    $address = str_limit($in['address'] ?? '', 255);
    if ($type === 'delivery' && mb_strlen($address) < 5) {
        throw new UserError('Yetkazish manzilini to\'liq yozing.');
    }
    $pay = (string)($in['pay'] ?? 'cash');
    $allowed = array_keys(array_filter(['cash' => setting_on('pay_cash_bot'), 'card' => setting_on('pay_card_bot') && cards_list(), 'hamyon' => setting_on('pay_hamyon') && hamyon_enabled()]));
    if (!in_array($pay, $allowed, true)) {
        throw new UserError('To\'lov turini tanlang.');
    }
    $cart = is_array($in['items'] ?? null) ? $in['items'] : [];
    if (!$cart || count($cart) > 50) {
        throw new UserError('Savatcha bo\'sh.');
    }
    $uuid = is_uuid($in['uuid'] ?? null) ? strtolower($in['uuid']) : uuid4();
    if ($ex = order_by_uuid($uuid)) {
        return $ex; // Tugma ikki marta bosilsa — bitta buyurtma
    }
    $lines = [];
    $itemsTotal = 0;
    foreach ($cart as $c) {
        $p = row('SELECT p.* FROM products p JOIN categories c ON c.id = p.category_id
                  WHERE p.id = ? AND p.is_active = 1 AND p.in_bot = 1 AND p.is_available = 1 AND c.is_active = 1', [(int)($c['id'] ?? 0)]);
        $qty = (int)($c['qty'] ?? 0);
        if (!$p || $qty < 1 || $qty > 50) {
            throw new UserError('Ba\'zi taomlar mavjud emas. Savatchani yangilang.');
        }
        $lines[] = [$p, $qty];
        $itemsTotal += (int)$p['price'] * $qty;
    }
    if ($itemsTotal < setting_int('min_order')) {
        throw new UserError('Minimal buyurtma: ' . money(setting_int('min_order')));
    }
    $fee = $type === 'delivery' ? setting_int('delivery_fee') : 0;
    $total = $itemsTotal + $fee;
    $o = tx(function () use ($uuid, $customer, $type, $phone, $address, $in, $pay, $itemsTotal, $fee, $total, $lines) {
        q('UPDATE customers SET phone = ?, name = COALESCE(NULLIF(?, \'\'), name), last_address = COALESCE(NULLIF(?, \'\'), last_address) WHERE id = ?',
            [$phone, str_limit($in['name'] ?? '', 100), $address, $customer['id']]);
        // Karta va Hamyon: to'lov (yoki chek rasmi) kelguncha kassaga chiqmaydi
        $status = $pay === 'cash' ? 'new' : 'pending_pay';
        q("INSERT INTO orders (uuid, number, source, type, customer_id, customer_name, phone, address, comment, status, pay_status, pay_method,
                               items_total, delivery_fee, total, created_at)
           VALUES (?, '', 'telegram', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [
            $uuid, $type, $customer['id'], str_limit($in['name'] ?? ($customer['name'] ?? ''), 100) ?: null, $phone,
            $type === 'delivery' ? $address : null, str_limit($in['comment'] ?? '', 500) ?: null,
            $status, 'unpaid', $pay, $itemsTotal, $fee, $total,
        ]);
        $id = (int)db()->lastInsertId();
        q('UPDATE orders SET number = ? WHERE id = ?', ['T-' . $id, $id]);
        foreach ($lines as [$p, $qty]) {
            q('INSERT INTO order_items (uuid, order_id, product_id, name, price, qty, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [uuid4(), $id, $p['id'], $p['name'], $p['price'], $qty]);
        }
        if ($type === 'delivery') {
            q('INSERT INTO deliveries (order_id, address, fee) VALUES (?, ?, ?)', [$id, $address, $fee]);
        }
        return order_get($id);
    });
    audit('order.tg_create', 'order', $o['id'], ['total' => $total, 'pay' => $pay]);
    if ($o['status'] === 'new') {
        tg_notify_admins_new($o);
    }
    return $o;
}

/** Buyurtmani kassa/WebApp uchun to'liq ko'rinishda qaytaradi. */
function order_export(array $o, bool $withPrivate = true): array
{
    $items = array_map(fn($i) => [
        'uuid' => $i['uuid'], 'product_id' => $i['product_id'] ? (int)$i['product_id'] : null, 'name' => $i['name'],
        'price' => (int)$i['price'], 'qty' => (int)$i['qty'], 'sent_qty' => (int)$i['sent_qty'], 'cancelled_qty' => (int)$i['cancelled_qty'],
        'comment' => $i['comment'],
    ], order_items((int)$o['id']));
    $out = [
        'id' => (int)$o['id'], 'uuid' => $o['uuid'], 'number' => $o['number'], 'source' => $o['source'], 'type' => $o['type'],
        'table_id' => $o['table_id'] ? (int)$o['table_id'] : null, 'status' => $o['status'], 'pay_status' => $o['pay_status'],
        'pay_method' => $o['pay_method'], 'items_total' => (int)$o['items_total'], 'delivery_fee' => (int)$o['delivery_fee'],
        'total' => (int)$o['total'], 'paid_total' => (int)$o['paid_total'], 'comment' => $o['comment'], 'rev' => (int)$o['rev'],
        'created_at' => $o['created_at'], 'items' => $items,
    ];
    if ($withPrivate) {
        $out += [
            'customer_name' => $o['customer_name'], 'phone' => $o['phone'], 'address' => $o['address'],
            'kitchen_needed' => (int)$o['kitchen_needed'] === 1, 'reject_reason' => $o['reject_reason'],
            'payments' => array_map(fn($p) => ['uuid' => $p['uuid'], 'method' => $p['method'], 'amount' => (int)$p['amount'], 'created_at' => $p['created_at']], order_payments((int)$o['id'])),
            'receipt' => ($r = row("SELECT id, status FROM card_receipts WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$o['id']])) ? ['id' => (int)$r['id'], 'status' => $r['status']] : null,
        ];
    }
    return $out;
}
