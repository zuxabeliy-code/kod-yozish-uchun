<?php
declare(strict_types=1);

/**
 * ADI BURGER 2.0 — integratsiya testlari (server qismi).
 * Ishlatish (faqat SINOV bazasida!):
 *   php tests/integration_test.php http://127.0.0.1:8088 admin admin12345
 * Talab: config.php da 'test' => ['tg_dry' => true] (Telegram'ga haqiqiy so'rov ketmaydi),
 *        Hamyon API taqlidi http://127.0.0.1:9200 da (shop_key=testkey).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
require_installed();
if (!cfg('test.tg_dry')) {
    exit("XAVFSIZLIK: config.php da test.tg_dry yoqilmagan — ishlab turgan tizimda test ishlatmang.\n");
}

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8088', '/');
$ADMIN = [$argv[2] ?? 'admin', $argv[3] ?? 'admin12345'];
$results = [];

function check(string $name, bool $ok, string $expected, mixed $actual = ''): void
{
    global $results;
    $results[] = [$name, $ok, $expected, is_string($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE)];
    echo ($ok ? "  ✅ O'TDI " : "  ❌ XATO  ") . $name . ($ok ? '' : "\n       kutilgan: $expected\n       haqiqiy:  " . mb_substr(is_string($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE), 0, 400)) . "\n";
}

function http(string $method, string $url, mixed $body = null, array $headers = [], bool $multipart = false): array
{
    $ch = curl_init($url);
    $h = $headers;
    if ($body !== null && !$multipart) {
        $body = is_string($body) ? $body : json_encode($body);
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($res, true) ?? $res];
}

function init_data(array $user, string $token, ?int $authDate = null): string
{
    $d = ['auth_date' => (string)($authDate ?? time()), 'query_id' => 'AAH' . random_int(1000, 9999), 'user' => json_encode($user)];
    ksort($d);
    $pairs = [];
    foreach ($d as $k => $v) {
        $pairs[] = "$k=$v";
    }
    $d['hash'] = hash_hmac('sha256', implode("\n", $pairs), hash_hmac('sha256', $token, 'WebAppData', true));
    return http_build_query($d);
}

echo "\n=== Tayyorgarlik ===\n";
$token = '123456789:AAEtestTOKENtestTOKENtestTOKEN12345';
foreach (['bot_token' => $token, 'bot_enabled' => '1', 'pay_cash_bot' => '1', 'pay_card_bot' => '1', 'pay_hamyon' => '1',
    'cards' => json_encode([['number' => '8600 1111 2222 3333', 'holder' => 'TEST', 'bank' => '']]), 'hamyon_shop_id' => 'shop1', 'hamyon_shop_key' => 'testkey',
    'hamyon_api_url' => 'http://127.0.0.1:9200', 'work_open' => '00:00', 'work_close' => '23:59', 'admin_tg_ids' => '111', 'webhook_secret' => 'whsecret123', 'min_order' => '0'] as $k => $v) {
    setting_set($k, $v);
}
check('Maxfiy sozlama bazada shifrlangan', str_starts_with((string)val("SELECT v FROM settings WHERE k = 'bot_token'"), 'enc1:') && setting('bot_token') === $token, 'enc1:… va to\'g\'ri ochiladi');

[$c, $r] = http('POST', "$BASE/api/?r=device.register", ['username' => $ADMIN[0], 'password' => $ADMIN[1], 'name' => 'Test kassa']);
$DEV = ['Authorization: Bearer ' . ($r['token'] ?? '')];
check('Qurilmani ro\'yxatdan o\'tkazish', $c === 200 && !empty($r['token']), '200 + token', $r);
[$c] = http('GET', "$BASE/api/?r=catalog", null, ['Authorization: Bearer noto_g_ri_token_1234567890']);
check("Noto'g'ri qurilma tokeni rad etiladi", $c === 401, '401', $c);
[$c, $r] = http('POST', "$BASE/api/?r=device.register", ['username' => $ADMIN[0], 'password' => 'xato-parol', 'name' => 'x']);
check("Noto'g'ri parol bilan qurilma ulanmaydi", $c === 400, '400', $r);

echo "\n=== Telegram WebApp ===\n";
$user = ['id' => 5550001, 'first_name' => 'Test', 'last_name' => 'Mijoz', 'username' => 'testmijoz'];
$ID = init_data($user, $token);
$wa = fn(array $b) => http('POST', "$BASE/telegram/webapp/api.php", $b + ['initData' => $ID]);
[$c, $r] = http('POST', "$BASE/telegram/webapp/api.php", ['action' => 'init', 'initData' => init_data($user, 'boshqa:token')]);
check('Soxta initData rad etiladi', $c === 401, '401', $c);
[$c, $r] = http('POST', "$BASE/telegram/webapp/api.php", ['action' => 'init', 'initData' => init_data($user, $token, time() - 90000)]);
check('Eskirgan initData rad etiladi', $c === 401, '401', $c);
[$c, $r] = $wa(['action' => 'init']);
check('WebApp: katalog va to\'lov usullari', $c === 200 && count($r['products'] ?? []) > 10 && count($r['pays'] ?? []) === 3, '200, mahsulotlar > 10, 3 ta to\'lov usuli', ['code' => $c, 'pays' => array_column($r['pays'] ?? [], 'id')]);
$pid = $r['products'][0]['id'];
$price = $r['products'][0]['price'];

[$c, $r] = $wa(['action' => 'order.create', 'type' => 'takeaway', 'phone' => '123', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 1]]]);
check("Noto'g'ri telefon rad etiladi", $c === 400 && str_contains($r['error'] ?? '', 'Telefon'), '400 Telefon…', $r);
[$c, $r] = $wa(['action' => 'order.create', 'type' => 'delivery', 'phone' => '+998901234567', 'address' => '', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 1]]]);
check('Yetkazishda manzilsiz buyurtma rad etiladi', $c === 400, '400', $r);
$u1 = uuid4();
[$c, $r] = $wa(['action' => 'order.create', 'uuid' => $u1, 'type' => 'delivery', 'phone' => '90 123 45 67', 'address' => 'Andijon, Navoiy 12', 'name' => 'Ali', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 2]]]);
$cashOrder = $r['order'] ?? [];
check('Naqd buyurtma yaratildi (narx serverdan, yetkazish qo\'shildi)', $c === 200 && ($cashOrder['status'] ?? '') === 'new' && $cashOrder['total'] === $price * 2 + setting_int('delivery_fee'), 'status=new, total=narx*2+yetkazish', $cashOrder);
[$c, $r] = $wa(['action' => 'order.create', 'uuid' => $u1, 'type' => 'delivery', 'phone' => '901234567', 'address' => 'Andijon', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 2]]]);
check('Tugma ikki marta bosilsa — takroriy buyurtma yaratilmaydi', ($r['order']['id'] ?? 0) === ($cashOrder['id'] ?? -1) && (int)val('SELECT COUNT(*) FROM orders WHERE uuid = ?', [$u1]) === 1, 'bitta buyurtma', $r);

// Karta (chek rasmi)
[$c, $r] = $wa(['action' => 'order.create', 'type' => 'takeaway', 'phone' => '+998901112233', 'pay' => 'card', 'items' => [['id' => $pid, 'qty' => 1]]]);
$cardOrder = $r['order'] ?? [];
check("Kartaga o'tkazma: chek kelguncha kassaga chiqmaydi", ($cardOrder['status'] ?? '') === 'pending_pay', 'pending_pay', $cardOrder);
$img = tempnam(sys_get_temp_dir(), 'rc') . '.jpg';
$im = imagecreatetruecolor(300, 500);
imagestring($im, 5, 20, 20, 'CHEK 33000', imagecolorallocate($im, 255, 255, 255));
imagejpeg($im, $img);
$fake = tempnam(sys_get_temp_dir(), 'rc') . '.jpg';
file_put_contents($fake, "<?php echo 'hack'; ?>");
[$c, $r] = http('POST', "$BASE/telegram/webapp/api.php", ['action' => 'receipt.upload', 'initData' => $ID, 'uuid' => $cardOrder['uuid'], 'file' => new CURLFile($fake, 'image/jpeg', 'x.jpg')], [], true);
check('Rasm bo\'lmagan fayl (PHP kod) yuklanmaydi', $c === 400, '400', $r);
[$c, $r] = http('POST', "$BASE/telegram/webapp/api.php", ['action' => 'receipt.upload', 'initData' => $ID, 'uuid' => $cardOrder['uuid'], 'file' => new CURLFile($img, 'image/jpeg', 'chek.jpg')], [], true);
check("Chek yuklandi → buyurtma kassaga chiqdi, to'lov 'tekshirilmoqda'", $c === 200 && ($r['order']['status'] ?? '') === 'new' && ($r['order']['pay_status'] ?? '') === 'checking', 'new + checking', $r);
$rid = (int)val('SELECT id FROM card_receipts WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$cardOrder['id']]);
$file = (string)val('SELECT file FROM card_receipts WHERE id = ?', [$rid]);
[$c] = http('GET', "$BASE/api/?r=receipt.image&id=$rid", null, $DEV);
check('Kassa chek rasmini ko\'ra oladi (token bilan)', $c === 200, '200', $c);
[$c] = http('GET', "$BASE/api/?r=receipt.image&id=$rid");
check('Tokensiz chek rasmi ochilmaydi', $c === 401, '401', $c);

$WH0 = ['X-Telegram-Bot-Api-Secret-Token: whsecret123'];
http('POST', "$BASE/telegram/webhook.php", ['update_id' => 90, 'callback_query' => ['id' => 'q0', 'from' => ['id' => 111], 'data' => 'o:' . $cardOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 111]]]], $WH0);
check("To'lovi tasdiqlanmagan karta buyurtmasini admin Telegram'dan qabul qila olmaydi", order_get((int)$cardOrder['id'])['status'] === 'new', 'new qoladi', order_get((int)$cardOrder['id'])['status']);
try {
    tg_order_set_status((int)$cardOrder['id'], 'accepted');
    check("To'lanmagan karta buyurtmasini qabul qilish server tomonida bloklanadi", false, 'UserError', 'qabul qilindi');
} catch (UserError $e) {
    check("To'lanmagan karta buyurtmasini qabul qilish server tomonida bloklanadi", true, 'UserError');
}

echo "\n=== Hamyon ===\n";
[$c, $r] = $wa(['action' => 'order.create', 'type' => 'takeaway', 'phone' => '+998907776655', 'pay' => 'hamyon', 'items' => [['id' => $pid, 'qty' => 1]]]);
$hOrder = $r['order'] ?? [];
[$c, $r] = $wa(['action' => 'hamyon.start', 'uuid' => $hOrder['uuid'] ?? '']);
$hp = $r['order']['hamyon'] ?? null;
check("Hamyon to'lovi yaratildi (karta va aniq summa)", $c === 200 && $hp && $hp['card'] !== '' && $hp['amount'] >= $hOrder['total'], 'card + amount', $r);
$pay = row('SELECT * FROM hamyon_payments WHERE order_id = ?', [$hOrder['id']]);
[$c] = http('POST', "$BASE/pay/hamyon.php", http_build_query(['payment_id' => $pay['payment_id'], 'amount' => $pay['pay_amount'], 'status' => 'paid', 'sign' => 'notogri']), ['Content-Type: application/x-www-form-urlencoded']);
check('Soxta imzoli callback rad etiladi', $c === 403, '403', $c);
$sign = md5('shop1' . $pay['payment_id'] . $pay['pay_amount'] . 'testkey');
$cb = http_build_query(['payment_id' => $pay['payment_id'], 'amount' => $pay['pay_amount'], 'status' => 'paid', 'sign' => $sign]);
http('POST', "$BASE/pay/hamyon.php", $cb, ['Content-Type: application/x-www-form-urlencoded']);
check("To'g'ri imzo, lekin Hamyon'da hali to'lanmagan — to'lov qabul qilinmaydi", order_get((int)$hOrder['id'])['pay_status'] === 'unpaid', 'unpaid', order_get((int)$hOrder['id'])['pay_status']);
file_get_contents('http://127.0.0.1:9200/_pay?shop_key=testkey&payment_id=' . urlencode($pay['payment_id']));
[$c] = http('POST', "$BASE/pay/hamyon.php", $cb, ['Content-Type: application/x-www-form-urlencoded']);
http('POST', "$BASE/pay/hamyon.php", $cb, ['Content-Type: application/x-www-form-urlencoded']);
$o = order_get((int)$hOrder['id']);
check("Hamyon to'landi → buyurtma kassaga chiqdi, to'lov 1 marta yozildi", $o['status'] === 'new' && $o['pay_status'] === 'paid' && (int)val('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$o['id']]) === 1, 'new + paid + 1 ta to\'lov', [$o['status'], $o['pay_status']]);

echo "\n=== Telegram webhook ===\n";
[$c] = http('POST', "$BASE/telegram/webhook.php", ['update_id' => 1, 'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1], 'text' => '/start']]);
check('Maxfiy tokensiz webhook so\'rovi rad etiladi', $c === 403, '403', $c);
$WH = ['X-Telegram-Bot-Api-Secret-Token: whsecret123'];
[$c] = http('POST', "$BASE/telegram/webhook.php", ['update_id' => 2, 'message' => ['message_id' => 1, 'chat' => ['id' => 5550001, 'type' => 'private'], 'from' => $user, 'text' => '/start']], $WH);
check('/start ishlaydi', $c === 200, '200', $c);
[$c] = http('POST', "$BASE/telegram/webhook.php", ['update_id' => 3, 'callback_query' => ['id' => 'q1', 'from' => ['id' => 999], 'data' => 'o:' . $cashOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 999]]]], $WH);
check('Admin bo\'lmagan foydalanuvchi buyurtmani qabul qila olmaydi', order_get((int)$cashOrder['id'])['status'] === 'new', 'new', order_get((int)$cashOrder['id'])['status']);
http('POST', "$BASE/telegram/webhook.php", ['update_id' => 4, 'callback_query' => ['id' => 'q2', 'from' => ['id' => 111], 'data' => 'o:' . $cashOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 111]]]], $WH);
$o = order_get((int)$cashOrder['id']);
check('Admin Telegram\'dan qabul qildi → kassada oshxona cheki kerak (kitchen_needed)', $o['status'] === 'accepted' && (int)$o['kitchen_needed'] === 1, 'accepted + kitchen_needed=1', [$o['status'], $o['kitchen_needed']]);

echo "\n=== Kassa sinxronlash (Telegram buyurtmalari) ===\n";
$op = fn(string $type, array $data) => ['op_uuid' => uuid4(), 'type' => $type, 'data' => $data, 'created_at' => date('c')];
[$c, $r] = http('POST', "$BASE/api/?r=sync", ['since' => '', 'ops' => []], $DEV);
$inPull = array_values(array_filter($r['tg_orders'] ?? [], fn($x) => $x['uuid'] === $cashOrder['uuid']));
check('Kassa pull: Telegram buyurtmasi kitchen_needed bilan keldi', $c === 200 && $inPull && $inPull[0]['kitchen_needed'] === true, 'kitchen_needed=true', $inPull[0] ?? $r);
$k = $op('tg.kitchen_done', ['order_uuid' => $cashOrder['uuid']]);
http('POST', "$BASE/api/?r=sync", ['ops' => [$k]], $DEV);
[$c, $r] = http('POST', "$BASE/api/?r=sync", ['ops' => [$k]], $DEV);
check('Takroriy amal ikkinchi marta bajarilmaydi (idempotency)', ($r['results'][0]['status'] ?? '') === 'duplicate', 'duplicate', $r['results'] ?? $r);
check('Oshxona cheki chiqqani serverga yetdi', (int)order_get((int)$cashOrder['id'])['kitchen_needed'] === 0, '0');
$pays = [['uuid' => uuid4(), 'method' => 'cash', 'amount' => (int)$cashOrder['total'], 'received' => 100000, 'change' => 100000 - (int)$cashOrder['total']]];
http('POST', "$BASE/api/?r=sync", ['ops' => [$op('tg.status', ['order_uuid' => $cashOrder['uuid'], 'status' => 'ready', 'employee_id' => 1]), $op('tg.payment', ['order_uuid' => $cashOrder['uuid'], 'payments' => $pays, 'employee_id' => 1]), $op('tg.status', ['order_uuid' => $cashOrder['uuid'], 'status' => 'done', 'employee_id' => 1])]], $DEV);
$o = order_get((int)$cashOrder['id']);
check('Telegram buyurtma: tayyor → naqd to\'lov → yakunlandi', $o['status'] === 'done' && $o['pay_status'] === 'paid', 'done + paid', [$o['status'], $o['pay_status']]);
[$c, $r] = http('POST', "$BASE/api/?r=sync", ['ops' => [$op('card.review', ['receipt_id' => $rid, 'approve' => true, 'employee_id' => 1])]], $DEV);
check("Kassa karta chekini tasdiqladi → to'langan", order_get((int)$cardOrder['id'])['pay_status'] === 'paid', 'paid', order_get((int)$cardOrder['id'])['pay_status']);
http('POST', "$BASE/api/?r=sync", ['ops' => [$op('tg.status', ['order_uuid' => $cardOrder['uuid'], 'status' => 'accepted', 'employee_id' => 1])]], $DEV);
check("To'lov tasdiqlangach karta buyurtmasi qabul qilinadi", order_get((int)$cardOrder['id'])['status'] === 'accepted', 'accepted', order_get((int)$cardOrder['id'])['status']);
[$c, $r] = http('POST', "$BASE/api/?r=sync", ['ops' => [$op('tg.status', ['order_uuid' => $hOrder['uuid'], 'status' => 'done', 'employee_id' => 1])]], $DEV);
check("Ruxsat etilmagan holat o'tishi bloklanadi (new → done)", order_get((int)$hOrder['id'])['status'] === 'new', 'new qoladi', order_get((int)$hOrder['id'])['status']);

echo "\n=== Sinxronlash xatolari va diagnostika ===\n";
$bad = $op('order.save', ['uuid' => uuid4(), 'type' => 'noto_g_ri', 'items' => []]);
[$c, $r] = http('POST', "$BASE/api/?r=sync", ['ops' => [$bad]], $DEV);
check('Noto\'g\'ri amal "failed" bo\'lib saqlanadi', ($r['results'][0]['status'] ?? '') === 'failed' && (int)val("SELECT COUNT(*) FROM sync_operations WHERE op_uuid = ? AND status = 'failed'", [$bad['op_uuid']]) === 1, 'failed', $r['results'] ?? $r);
check('Xato diagnostika tarixiga yozildi', (int)val("SELECT COUNT(*) FROM error_logs WHERE component = 'sync' AND fix_status = 'open'") > 0, '> 0');
$d = diag_run(['sync', 'payments', 'tables', 'hamyon']);
check('Diagnostika sinxronlash xatosini ko\'rsatadi', $d['sync']['status'] === 'error', 'error', $d['sync']);
check('Diagnostika: Hamyon ulanishi', $d['hamyon']['status'] === 'ok', 'ok', $d['hamyon']);
q("DELETE FROM sync_operations WHERE op_uuid = ?", [$bad['op_uuid']]);
q("UPDATE error_logs SET fix_status = 'fixed' WHERE component = 'sync'");

echo "\n=== Xavfsizlik ===\n";
[$c, $r] = http('GET', "$BASE/install/");
check("O'rnatuvchi qayta ishlamaydi", is_string($r) && str_contains($r, 'allaqachon'), '"allaqachon o\'rnatilgan"', is_string($r) ? mb_substr(strip_tags($r), 0, 80) : $r);
$ok = true;
foreach (glob(ROOT . '/uploads/receipts/*.jpg') as $f) {
    $ok = $ok && str_starts_with((string)file_get_contents($f, false, null, 0, 3), "\xFF\xD8\xFF");
}
check('Yuklangan cheklar qayta kodlangan JPEG', $ok, 'JPEG');
$v = 'admin-bf-' . random_int(1, 99999);
$blocked = false;
for ($i = 0; $i < 10; $i++) {
    try {
        admin_verify($v, 'x');
    } catch (UserError $e) {
        if (str_contains($e->getMessage(), "ko'p urinish")) {
            $blocked = true;
        }
    }
}
rate_limit_reset('login|' . client_ip());
check('Parolni tanlab topishga urinish bloklanadi', $blocked, "8 urinishdan keyin blok");
check('PIN bazada ochiq saqlanmaydi', !val("SELECT COUNT(*) FROM employees WHERE pin_hash REGEXP '^[0-9]{4}$'") && strlen((string)val('SELECT pin_hash FROM employees LIMIT 1')) === 64, '64 belgili xesh');
$b = backup_create();
$gz = gzopen(ROOT . '/storage/backups/' . $b['name'], 'r');
$head = gzread($gz, 200);
gzclose($gz);
check('Zaxira yaratildi va o\'qiladi', str_contains($head, 'ADI BURGER'), 'gzip SQL', $b);
check('Redact: token logda yashiriladi', !str_contains((string)redact("tok $token"), 'AAEtest'), 'yashirilgan');

// Natija
$pass = count(array_filter($results, fn($r) => $r[1]));
echo "\nJAMI: " . count($results) . " ta test, o'tdi: $pass, xato: " . (count($results) - $pass) . "\n";
file_put_contents(ROOT . '/storage/cache/test-results.json', json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
exit($pass === count($results) ? 0 : 1);
