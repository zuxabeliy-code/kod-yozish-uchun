<?php
declare(strict_types=1);

/**
 * Kassa (POS) API. Barcha so'rovlar: /api/?r=<yo'nalish>
 * Autentifikatsiya: qurilma tokeni (Authorization: Bearer ...), ping va device.register bundan mustasno.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();
security_headers(false);

$route = (string)($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($route === 'ping') {
        json_out(['ok' => true, 'version' => ADI_VERSION, 'time' => date('c'), 'db' => db_ready()]);
    }

    if ($route === 'device.register') {
        if ($method !== 'POST') {
            json_out(['ok' => false, 'error' => 'POST kerak'], 405);
        }
        $in = json_input();
        $r = device_register((string)($in['username'] ?? ''), (string)($in['password'] ?? ''), (string)($in['name'] ?? 'Kassa'));
        json_out(['ok' => true, 'token' => $r['token'], 'device_id' => $r['id']]);
    }

    $device = current_device();
    if (!$device) {
        json_out(['ok' => false, 'error' => 'Qurilma ulanmagan yoki o\'chirilgan. Administrator logini bilan qayta ulang.', 'code' => 'DEVICE_AUTH'], 401);
    }

    schema_autoupdate();
    switch ($route) {
        case 'catalog':
            json_out(['ok' => true] + sync_catalog());

        case 'sync':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'error' => 'POST kerak'], 405);
            }
            $in = json_input();
            // 1) Kassa holati (printer xizmati, printer, navbat, offline outbox) — diagnostika uchun
            if (is_array($in['status'] ?? null)) {
                $st = $in['status'];
                q('UPDATE devices SET status_json = ?, app_version = ? WHERE id = ?', [
                    mb_substr((string)json_encode(redact($st), JSON_UNESCAPED_UNICODE), 0, 8000), str_limit($st['version'] ?? '', 20), $device['id'],
                ]);
            }
            // 2) Offline amallar (tartib bilan)
            $results = [];
            $ops = is_array($in['ops'] ?? null) ? array_slice($in['ops'], 0, 50) : [];
            foreach ($ops as $op) {
                $r = sync_apply_op(is_array($op) ? $op : [], $device);
                $results[] = ['op_uuid' => (string)($op['op_uuid'] ?? ''), 'status' => $r['status'], 'error' => $r['error'] ?? null, 'result' => $r['result'] ?? null];
            }
            // 3) Yangiliklar (Telegram buyurtmalari, menyu versiyasi)
            $pull = sync_pull($device, (string)($in['since'] ?? ''));
            maintenance_tick();
            json_out(['ok' => true, 'results' => $results] + $pull);

        case 'receipt.image':
            $r = row('SELECT * FROM card_receipts WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
            $path = $r ? ROOT . '/uploads/receipts/' . basename($r['file']) : '';
            if (!$r || !is_file($path)) {
                json_out(['ok' => false, 'error' => 'Rasm topilmadi'], 404);
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
    }
    json_out(['ok' => false, 'error' => 'Noma\'lum so\'rov'], 404);
} catch (UserError $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $id = log_exception($e, 'api');
    json_out(['ok' => false, 'error' => 'Server xatosi (ID: ' . $id . ')'], 500);
}
