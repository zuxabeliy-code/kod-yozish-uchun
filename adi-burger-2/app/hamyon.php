<?php
declare(strict_types=1);
defined('ADI') || exit;

/*
 * Hamyon avto to'lov (hamyon-api.uz).
 * Jarayon: buyurtma (pending_pay) → /payment/create → mijozga karta va ANIQ summa ko'rsatiladi →
 * mijoz o'tkazadi → Hamyon pay/hamyon.php ga callback yuboradi → holat Hamyon'ning o'zidan qayta
 * tekshiriladi → buyurtma to'langan bo'lib kassaga chiqadi.
 * Callback yetib kelmasa ham: WebApp har necha soniyada holatni so'raydi, kassa sinxronlashida
 * esa qotib qolgan to'lovlar tekshiriladi (cron shart emas).
 */

const HAMYON_SUM_OFFSETS = 10; // Bir xil summa band bo'lsa, +1..+10 so'm bilan qayta urinish

function hamyon_enabled(): bool
{
    return setting('hamyon_shop_id') !== '' && setting('hamyon_shop_key') !== '';
}

/** @return array{0: ?array, 1: string, 2: int, 3: int} [javob, xato, http kod, ms] */
function hamyon_request(string $method, string $path, array $params): array
{
    if (cfg('test.hamyon_mock')) {
        return (cfg('test.hamyon_mock'))($method, $path, $params);
    }
    $url = rtrim(setting('hamyon_api_url') ?: 'https://hamyon-api.uz', '/') . $path;
    $ch = curl_init($method === 'GET' ? $url . '?' . http_build_query($params) : $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $t = microtime(true);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int)((microtime(true) - $t) * 1000);
    $data = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($data)) {
        $msg = $err !== '' ? "Ulanib bo'lmadi: $err" : "Javob JSON emas (HTTP $code)";
        return [null, $msg, $code, $ms];
    }
    return [$data, '', $code, $ms];
}

function hamyon_creds(): array
{
    return ['shop_id' => setting('hamyon_shop_id'), 'shop_key' => setting('hamyon_shop_key')];
}

function hamyon_sign_ok(array $p): bool
{
    $calc = md5(setting('hamyon_shop_id') . ($p['payment_id'] ?? '') . ($p['amount'] ?? '') . setting('hamyon_shop_key'));
    return hash_equals($calc, strtolower((string)($p['sign'] ?? '')));
}

function hamyon_open_payment(int $orderId): ?array
{
    return row("SELECT * FROM hamyon_payments WHERE order_id = ? AND status = 'pending' AND expire_at > ? ORDER BY id DESC LIMIT 1", [$orderId, time()]);
}

/** Buyurtma uchun to'lov yaratadi (yoki ochig'ini qaytaradi). */
function hamyon_create(array $order): array
{
    if (!setting_on('pay_hamyon') || !hamyon_enabled()) {
        throw new UserError("Hamyon to'lovi hozir o'chirilgan. Boshqa to'lov turini tanlang.");
    }
    if ($open = hamyon_open_payment((int)$order['id'])) {
        return $open;
    }
    $base = (int)$order['total'];
    $busy = array_map('intval', array_column(rows("SELECT pay_amount FROM hamyon_payments WHERE status = 'pending' AND expire_at > ?", [time()]), 'pay_amount'));
    $lastErr = 'Hamyon javob bermadi';
    for ($off = 0; $off <= HAMYON_SUM_OFFSETS; $off++) {
        $amount = $base + $off;
        if (in_array($amount, $busy, true)) {
            continue;
        }
        [$r, $err, $code] = hamyon_request('POST', '/payment/create', hamyon_creds() + ['amount' => $amount, 'order_id' => (string)$order['id']]);
        if ($r === null) {
            $lastErr = $err;
            break;
        }
        if (isset($r['error'])) {
            $lastErr = (string)$r['error'];
            if (preg_match('~ochiq|summa|exists|busy~iu', $lastErr)) {
                continue; // Bu summa band — keyingisi
            }
            break;
        }
        if (empty($r['payment_id']) || empty($r['card'])) {
            $lastErr = "Hamyon javobi to'liq emas (payment_id/card yo'q)";
            break;
        }
        $expire = (int)($r['expire_at'] ?? (time() + (int)($r['expires_in'] ?? 300)));
        q('INSERT INTO hamyon_payments (payment_id, order_id, amount, pay_amount, card, expire_at) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE order_id = VALUES(order_id), pay_amount = VALUES(pay_amount), card = VALUES(card), expire_at = VALUES(expire_at)',
            [(string)$r['payment_id'], $order['id'], $base, $amount, (string)$r['card'], $expire]);
        return row('SELECT * FROM hamyon_payments WHERE payment_id = ?', [(string)$r['payment_id']]);
    }
    record_error('hamyon', 'CREATE_FAIL', "To'lov yaratilmadi: $lastErr", ['order' => $order['id']],
        "Admin → To'lovlar: shop_id va shop_key to'g'riligini tekshiring. Diagnostika → Hamyon → \"Tekshirish\".");
    throw new UserError("To'lov tizimi javob bermadi. Birozdan so'ng urinib ko'ring yoki naqd to'lang.");
}

/** Hamyon'dan holatni so'raydi: ['status' => pending|paid|cancel, 'amount' => int] yoki null. */
function hamyon_remote_status(string $paymentId): ?array
{
    [$r] = hamyon_request('GET', '/payment/status', hamyon_creds() + ['payment_id' => $paymentId]);
    return is_array($r) && isset($r['status']) ? $r : null;
}

/** "paid" ni qabul qiladi. Idempotent: faqat birinchi marta true. */
function hamyon_mark_paid(array $pay, array $remote): bool
{
    if ($pay['status'] === 'paid') {
        return false;
    }
    if (($remote['status'] ?? '') !== 'paid' || (int)($remote['amount'] ?? -1) !== (int)$pay['pay_amount']) {
        log_msg('hamyon', ['rad' => $pay['payment_id'], 'remote' => $remote]);
        return false;
    }
    $first = tx(function () use ($pay) {
        if (!q("UPDATE hamyon_payments SET status = 'paid', paid_at = NOW() WHERE id = ? AND status <> 'paid'", [$pay['id']])->rowCount()) {
            return false;
        }
        $o = row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$pay['order_id']]);
        payment_insert((int)$o['id'], ['uuid' => uuid4(), 'method' => 'hamyon', 'amount' => (int)$o['total'], 'created_at' => date('c')], null);
        order_recalc_payment((int)$o['id']);
        return true;
    });
    if ($first) {
        $o = order_get((int)$pay['order_id']);
        if ($o['status'] === 'pending_pay') {
            $o = tg_order_set_status((int)$o['id'], 'new');
            tg_notify_admins_new($o);
        } else {
            tg_send_admins("⚠️ Hamyon: {$o['number']} buyurtma uchun pul tushdi, lekin buyurtma holati: " . (ORDER_STATUSES[$o['status']] ?? $o['status']) . ". Mijoz bilan bog'laning.");
        }
    }
    return $first;
}

function hamyon_mark_cancel(array $pay): void
{
    if (!q("UPDATE hamyon_payments SET status = 'cancel' WHERE id = ? AND status = 'pending'", [$pay['id']])->rowCount()) {
        return;
    }
    $o = order_get((int)$pay['order_id']);
    if ($o && $o['status'] === 'pending_pay' && !hamyon_open_payment((int)$o['id'])) {
        try {
            tg_order_set_status((int)$o['id'], 'cancelled', "To'lov muddati tugadi");
        } catch (UserError) {
        }
    }
}

/** Holatni Hamyon'dan yangilaydi (ko'pi bilan har $every soniyada bir marta). */
function hamyon_refresh(array $pay, int $every = 5): array
{
    if ($pay['status'] !== 'pending') {
        return $pay;
    }
    if (!q('UPDATE hamyon_payments SET checked_at = ? WHERE id = ? AND (checked_at IS NULL OR checked_at <= ?)', [time(), $pay['id'], time() - $every])->rowCount()) {
        return $pay;
    }
    $r = hamyon_remote_status($pay['payment_id']);
    if ($r && $r['status'] === 'paid') {
        hamyon_mark_paid($pay, $r);
    } elseif (($r && $r['status'] === 'cancel') || (!$r && (int)$pay['expire_at'] < time() - 3600)) {
        hamyon_mark_cancel($pay);
    }
    return row('SELECT * FROM hamyon_payments WHERE id = ?', [$pay['id']]) ?? $pay;
}

/** Kutilayotgan barcha to'lovlarni tekshiradi (diagnostika "Tuzatish" va fon vazifasi). */
function hamyon_reconcile(int $limit = 30): int
{
    if (!hamyon_enabled()) {
        return 0;
    }
    $n = 0;
    foreach (rows("SELECT * FROM hamyon_payments WHERE status = 'pending' AND created_at < NOW() - INTERVAL 15 SECOND ORDER BY id LIMIT " . (int)$limit) as $p) {
        if (hamyon_refresh($p, 20)['status'] !== 'pending') {
            $n++;
        }
    }
    return $n;
}

/** Diagnostika: kalitlar va API ulanishi. */
function hamyon_ping(): array
{
    if (!hamyon_enabled()) {
        return ['ok' => false, 'msg' => 'shop_id yoki shop_key kiritilmagan'];
    }
    [$r, $err, $code, $ms] = hamyon_request('GET', '/payment/status', hamyon_creds() + ['payment_id' => 'diag-' . time()]);
    if ($r === null) {
        return ['ok' => false, 'msg' => "Hamyon serveriga ulanib bo'lmadi: $err", 'ms' => $ms];
    }
    if ($code === 401 || $code === 403 || preg_match('~key|auth|ruxsat|shop~iu', (string)($r['error'] ?? ''))) {
        return ['ok' => false, 'msg' => 'Hamyon kalitlarni rad etdi: ' . ($r['error'] ?? "HTTP $code"), 'ms' => $ms];
    }
    return ['ok' => true, 'msg' => "Hamyon serveri javob berdi ({$ms} ms)", 'ms' => $ms];
}

function tg_send_admins(string $text): void
{
    foreach (tg_admin_ids() as $id) {
        tg_send($id, $text);
    }
}
