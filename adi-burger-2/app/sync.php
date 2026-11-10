<?php
declare(strict_types=1);
defined('ADI') || exit;

/*
 * Offline sinxronlash.
 * Kassa har bir amalni (buyurtma, to'lov, smena, chek) o'zida "outbox"ga yozadi va tartib bilan
 * shu yerga yuboradi. Har amalning noyob op_uuid si bor: ikkinchi marta kelsa qayta bajarilmaydi
 * (idempotency). Xato bo'lgan amal "failed" bo'lib saqlanadi va diagnostikada ko'rinadi.
 */

const SYNC_TYPES = ['order.save', 'tg.status', 'tg.payment', 'tg.kitchen_done', 'card.review', 'shift.open', 'shift.close', 'print.log'];

/** @return array{status: string, error?: string, result?: array} */
function sync_apply_op(array $op, array $device): array
{
    $uuid = (string)($op['op_uuid'] ?? '');
    $type = (string)($op['type'] ?? '');
    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
    if (!is_uuid($uuid) || !in_array($type, SYNC_TYPES, true)) {
        return ['status' => 'rejected', 'error' => "Noto'g'ri amal: $type"];
    }
    $ex = row('SELECT status, error FROM sync_operations WHERE op_uuid = ?', [$uuid]);
    if ($ex && $ex['status'] === 'applied') {
        return ['status' => 'duplicate'];
    }
    $emp = !empty($data['employee_id']) ? (int)$data['employee_id'] : null;
    try {
        $result = tx(fn() => sync_dispatch($type, $data, $device, $emp));
        $status = 'applied';
        $error = null;
    } catch (UserError $e) {
        $status = 'failed';
        $error = $e->getMessage();
        $result = [];
    } catch (Throwable $e) {
        $status = 'failed';
        $error = 'Server xatosi: ' . log_exception($e, 'sync');
        $result = [];
    }
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
    q('INSERT INTO sync_operations (op_uuid, device_id, type, payload, status, error, client_time) VALUES (?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error), payload = VALUES(payload), attempts = attempts + 1',
        [$uuid, $device['id'], $type, $payload, $status, $error, to_dt($op['created_at'] ?? '')]);
    if ($status === 'failed') {
        record_error('sync', 'OP_FAILED', "Sinxronlash amali bajarilmadi ($type): $error", ['op' => $uuid],
            "Diagnostika → Sinxronlash bo'limida \"Qayta urinish\" tugmasini bosing. Takrorlansa, xato matnini administratorga yuboring.");
        return ['status' => 'failed', 'error' => $error];
    }
    return ['status' => 'applied', 'result' => $result];
}

function sync_dispatch(string $type, array $d, array $device, ?int $emp): array
{
    switch ($type) {
        case 'order.save':
            return order_apply_snapshot($d, $device);

        case 'shift.open':
            if (!is_uuid($d['uuid'] ?? null)) {
                throw new UserError('Smena UUID noto\'g\'ri');
            }
            if (!val('SELECT id FROM employees WHERE id = ?', [$emp])) {
                throw new UserError('Kassir topilmadi');
            }
            q('INSERT IGNORE INTO shifts (uuid, number, employee_id, device_id, opened_at, opening_cash) VALUES (?, ?, ?, ?, ?, ?)', [
                $d['uuid'], max(1, (int)($d['number'] ?? 1)), $emp, $device['id'], to_dt($d['opened_at'] ?? '') ?? date('Y-m-d H:i:s'), max(0, (int)($d['opening_cash'] ?? 0)),
            ]);
            return ['id' => (int)val('SELECT id FROM shifts WHERE uuid = ?', [$d['uuid']])];

        case 'shift.close':
            $s = row('SELECT * FROM shifts WHERE uuid = ? FOR UPDATE', [(string)($d['uuid'] ?? '')]);
            if (!$s) {
                throw new UserError('Smena topilmadi');
            }
            if ($s['status'] === 'closed') {
                return ['id' => (int)$s['id']]; // Yopilgan smena qayta ochilmaydi va qayta yopilmaydi
            }
            q("UPDATE shifts SET status = 'closed', closed_at = ?, closed_by = ?, counted_cash = ?, expected_cash = ?, report_json = ? WHERE id = ?", [
                to_dt($d['closed_at'] ?? '') ?? date('Y-m-d H:i:s'), $emp ? val('SELECT id FROM employees WHERE id = ?', [$emp]) : null,
                (int)($d['counted_cash'] ?? 0), (int)($d['expected_cash'] ?? 0),
                json_encode($d['report'] ?? [], JSON_UNESCAPED_UNICODE), $s['id'],
            ]);
            audit('shift.close', 'shift', $s['id'], ['counted' => $d['counted_cash'] ?? 0], $emp);
            return ['id' => (int)$s['id']];

        case 'tg.status':
            $o = order_by_uuid((string)($d['order_uuid'] ?? ''));
            if (!$o) {
                throw new UserError('Buyurtma topilmadi');
            }
            try {
                tg_order_set_status((int)$o['id'], (string)($d['status'] ?? ''), (string)($d['reason'] ?? ''), $emp);
            } catch (UserError $e) {
                // Holat boshqa joyda (masalan admin paneldan) allaqachon o'zgargan bo'lsa — amalni xato deb hisoblamaymiz
                return ['warning' => $e->getMessage()];
            }
            return ['id' => (int)$o['id']];

        case 'tg.payment':
            $o = order_by_uuid((string)($d['order_uuid'] ?? ''));
            if (!$o) {
                throw new UserError('Buyurtma topilmadi');
            }
            $shiftId = is_uuid($d['shift_uuid'] ?? null) ? val('SELECT id FROM shifts WHERE uuid = ?', [$d['shift_uuid']]) : null;
            foreach ((array)($d['payments'] ?? []) as $p) {
                payment_insert((int)$o['id'], $p + ['employee_id' => $emp], $shiftId);
            }
            if ($shiftId) {
                q('UPDATE orders SET shift_id = COALESCE(shift_id, ?) WHERE id = ?', [$shiftId, $o['id']]);
            }
            order_recalc_payment((int)$o['id']);
            return ['id' => (int)$o['id']];

        case 'tg.kitchen_done':
            $o = order_by_uuid((string)($d['order_uuid'] ?? ''));
            if ($o) {
                q('UPDATE orders SET kitchen_needed = 0 WHERE id = ?', [$o['id']]);
                q('UPDATE order_items SET sent_qty = qty WHERE order_id = ?', [$o['id']]);
            }
            return [];

        case 'card.review':
            return card_receipt_review((int)($d['receipt_id'] ?? 0), !empty($d['approve']), (string)($d['note'] ?? ''), $emp);

        case 'print.log':
            if (!is_uuid($d['uuid'] ?? null)) {
                throw new UserError('Chek UUID noto\'g\'ri');
            }
            $kind = (string)($d['kind'] ?? '');
            $st = (string)($d['status'] ?? '');
            if (!in_array($kind, ['kitchen', 'customer', 'cancel', 'zreport', 'test', 'report'], true)
                || !in_array($st, ['pending', 'sending', 'sent', 'error', 'unknown', 'reprinted'], true)) {
                throw new UserError('Chek holati noto\'g\'ri');
            }
            $orderId = is_uuid($d['order_uuid'] ?? null) ? val('SELECT id FROM orders WHERE uuid = ?', [$d['order_uuid']]) : null;
            q('INSERT INTO print_jobs (uuid, order_id, device_id, kind, status, attempts, error, reprint_of, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE status = VALUES(status), attempts = VALUES(attempts), error = VALUES(error)', [
                $d['uuid'], $orderId, $device['id'], $kind, $st, max(0, (int)($d['attempts'] ?? 0)), str_limit($d['error'] ?? '', 255) ?: null,
                is_uuid($d['reprint_of'] ?? null) ? $d['reprint_of'] : null, to_dt($d['created_at'] ?? '') ?? date('Y-m-d H:i:s'),
            ]);
            return [];
    }
    throw new UserError('Noma\'lum amal');
}

/** Karta to'lovi chekini tasdiqlash yoki rad etish. */
function card_receipt_review(int $receiptId, bool $approve, string $note, ?int $emp): array
{
    $r = row('SELECT * FROM card_receipts WHERE id = ? FOR UPDATE', [$receiptId]);
    if (!$r) {
        throw new UserError('To\'lov cheki topilmadi');
    }
    if ($r['status'] !== 'checking') {
        return ['already' => $r['status']];
    }
    $o = order_get((int)$r['order_id']);
    q('UPDATE card_receipts SET status = ?, note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
        [$approve ? 'approved' : 'rejected', str_limit($note, 255) ?: null, $emp ? val('SELECT id FROM employees WHERE id = ?', [$emp]) : null, $r['id']]);
    if ($approve) {
        payment_insert((int)$o['id'], ['uuid' => uuid4(), 'method' => 'card_transfer', 'amount' => (int)$o['total'], 'created_at' => date('c'), 'employee_id' => $emp], $o['shift_id'] ? (int)$o['shift_id'] : null);
        order_recalc_payment((int)$o['id']);
        q('UPDATE orders SET rev = rev + 1 WHERE id = ?', [$o['id']]);
        $tg = val('SELECT tg_id FROM customers WHERE id = ?', [$o['customer_id']]);
        if ($tg) {
            tg_send($tg, "✅ {$o['number']} buyurtma uchun to'lovingiz tasdiqlandi. Rahmat!");
        }
    } else {
        q("UPDATE orders SET pay_status = 'unpaid', rev = rev + 1 WHERE id = ?", [$o['id']]);
        $tg = val('SELECT tg_id FROM customers WHERE id = ?', [$o['customer_id']]);
        if ($tg) {
            tg_send($tg, "❗ {$o['number']} buyurtma uchun yuborgan to'lov chekingiz tasdiqlanmadi." . ($note ? "\nSabab: " . e($note) : '')
                . "\nIltimos, WebApp'da \"Buyurtmalarim\" bo'limidan chekni qayta yuboring yoki kassaga murojaat qiling.");
        }
    }
    audit('card_receipt.review', 'order', $o['id'], ['approve' => $approve], $emp);
    return ['id' => (int)$o['id']];
}

/** Kassa uchun yangilanishlar: Telegram buyurtmalari, menyu versiyasi, sozlamalar. */
function sync_pull(array $device, string $since): array
{
    $since = to_dt($since) ?? date('Y-m-d H:i:s', time() - 86400 * 2);
    // 5 soniyalik ustma-ust oyna: bir soniyada o'zgargan yozuvlar tushib qolmasin
    $from = date('Y-m-d H:i:s', strtotime($since) - 5);
    $now = (string)val('SELECT NOW()');
    $orders = rows("SELECT * FROM orders WHERE source = 'telegram' AND status <> 'pending_pay' AND (updated_at >= ? OR status IN ('new','accepted','ready'))
                    ORDER BY id LIMIT 200", [$from]);
    return [
        'server_time' => $now,
        'catalog_version' => (int)setting('catalog_version'),
        'tg_orders' => array_map(fn($o) => order_export($o), $orders),
        'failed_ops' => (int)val("SELECT COUNT(*) FROM sync_operations WHERE device_id = ? AND status = 'failed'", [$device['id']]),
    ];
}

/** Kassa uchun to'liq katalog (menyu, stollar, xodimlar, sozlamalar). */
function sync_catalog(): array
{
    $s = settings_all();
    return [
        'version' => (int)setting('catalog_version'),
        'categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'sort' => (int)$c['sort']],
            rows('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort, id')),
        'products' => array_map(fn($p) => [
            'id' => (int)$p['id'], 'category_id' => (int)$p['category_id'], 'name' => $p['name'], 'price' => (int)$p['price'],
            'image' => product_thumb($p['image']), 'available' => (int)$p['is_available'] === 1, 'sort' => (int)$p['sort'],
        ], rows('SELECT p.* FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND p.in_pos = 1 AND c.is_active = 1 ORDER BY p.sort, p.id')),
        'tables' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'zone' => (string)($t['zone'] ?? '')], rows('SELECT * FROM dining_tables WHERE is_active = 1 ORDER BY sort, id')),
        // PIN xeshlari kassaga internet bo'lmaganda ham kirish uchun beriladi (faqat ro'yxatdan o'tgan qurilmaga)
        'employees' => array_map(fn($e) => ['id' => (int)$e['id'], 'name' => $e['name'], 'role' => $e['role'], 'salt' => $e['pin_salt'], 'hash' => $e['pin_hash'],
            'login' => $e['login'], 'lsalt' => $e['pw_salt'], 'lhash' => $e['pw_hash']],
            rows('SELECT * FROM employees WHERE is_active = 1 ORDER BY id')),
        'pin_iterations' => PIN_ITERATIONS,
        'receipt' => ['design' => rc_design(), 'logo' => rc_logo()],
        'settings' => [
            'brand_name' => $s['brand_name'], 'brand_phone' => $s['brand_phone'], 'brand_address' => $s['brand_address'],
            'receipt_footer' => $s['receipt_footer'], 'paper_width' => (int)$s['paper_width'],
            'kitchen_auto' => $s['kitchen_auto'] === '1', 'customer_auto' => $s['customer_auto'] === '1',
            'kitchen_copies' => rc_design()['kitchen']['copies'], 'customer_copies' => rc_design()['customer']['copies'],
            'delivery_fee' => (int)$s['delivery_fee'],
            'pay_methods' => array_keys(array_filter(['cash' => $s['pos_pay_cash'] === '1', 'terminal' => $s['pos_pay_terminal'] === '1', 'click' => $s['pos_pay_click'] === '1'])),
        ],
        'last_shift_number' => (int)val('SELECT COALESCE(MAX(number), 0) FROM shifts'),
        'open_shift' => ($sh = row("SELECT uuid, number, employee_id, opened_at, opening_cash FROM shifts WHERE status = 'open' ORDER BY id DESC LIMIT 1")) ? $sh : null,
    ];
}

/** Ishlab turgan saytda cron bo'lmasa ham fon vazifalari (ko'pi bilan 5 daqiqada bir). */
function maintenance_tick(): void
{
    $last = (int)setting('maintenance_at');
    if ($last > time() - 300) {
        return;
    }
    if (!q("UPDATE settings SET v = ? WHERE k = 'maintenance_at' AND v = ?", [(string)time(), (string)$last])->rowCount()) {
        if ($last === 0) {
            q("INSERT IGNORE INTO settings (k, v) VALUES ('maintenance_at', ?)", [(string)time()]);
        }
        return;
    }
    try {
        hamyon_reconcile(10);
        // Eski fayl cheklovlari va loglarni tozalash
        foreach (glob(ROOT . '/storage/cache/rl_*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        foreach (glob(ROOT . '/storage/logs/*.log.php') ?: [] as $f) {
            if (filemtime($f) < time() - 86400 * 30) {
                @unlink($f);
            }
        }
        // Avtomatik zaxira: kuniga bir marta
        if (strtotime(setting('last_backup_at') ?: '2000-01-01') < time() - 86400) {
            backup_create();
        }
    } catch (Throwable $e) {
        log_exception($e, 'maintenance');
    }
}

/** Mahsulot rasmining kichik nusxasi (bo'lsa), aks holda asl rasm. */
function product_thumb(?string $image): ?string
{
    if (!$image) {
        return null;
    }
    $t = 'uploads/products/thumb/' . basename($image);
    return app_url(is_file(ROOT . '/' . $t) ? $t : $image);
}
