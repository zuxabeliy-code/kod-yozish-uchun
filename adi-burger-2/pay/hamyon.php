<?php
declare(strict_types=1);

/**
 * Hamyon callback (prepare va complete). Hamyon kabinetida shu manzil ko'rsatiladi:
 * https://SAYT/pay/hamyon.php
 * Imzo tekshiriladi va to'lov holati Hamyon'ning o'zidan qayta so'raladi (soxta callback ishlamaydi).
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();

$p = $_POST ?: json_input();
log_msg('hamyon', ['callback' => array_diff_key($p, ['sign' => 1])]);

if (!hamyon_enabled() || !hamyon_sign_ok($p)) {
    record_error('hamyon', 'BAD_SIGN', "Hamyon callback imzosi noto'g'ri", ['ip' => client_ip()], "shop_key Hamyon kabinetidagi bilan bir xilligini tekshiring.");
    json_out(['error' => 'imzo'], 403);
}

$pid = (string)($p['payment_id'] ?? '');
$status = (string)($p['status'] ?? '');
$pay = row('SELECT * FROM hamyon_payments WHERE payment_id = ?', [$pid]);

// Callback create javobidan oldin kelishi mumkin: order_id bo'yicha yozib qo'yamiz
if (!$pay && ctype_digit((string)($p['order_id'] ?? ''))) {
    $o = order_get((int)$p['order_id']);
    if ($o && $o['pay_method'] === 'hamyon') {
        q('INSERT IGNORE INTO hamyon_payments (payment_id, order_id, amount, pay_amount, card, expire_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$pid, $o['id'], $o['total'], (int)($p['amount'] ?? 0), '', time() + 300]);
        $pay = row('SELECT * FROM hamyon_payments WHERE payment_id = ?', [$pid]);
    }
}
if (!$pay) {
    json_out(['result' => 'ok']); // bizda yo'q to'lov — qabul qilamiz, lekin hech narsa bermaymiz
}

try {
    if ($status === 'paid') {
        $remote = hamyon_remote_status($pid);
        if ($remote === null) {
            json_out(['error' => 'retry'], 503); // Hamyon keyinroq qayta yuboradi
        }
        hamyon_mark_paid($pay, $remote);
    } elseif ($status === 'cancel') {
        hamyon_mark_cancel($pay);
    }
} catch (Throwable $e) {
    log_exception($e, 'hamyon');
    json_out(['error' => 'retry'], 503);
}
json_out(['result' => 'ok']);
