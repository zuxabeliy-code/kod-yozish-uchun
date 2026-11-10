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

/** Webhook javobni darhol qaytaradi, ishlov esa keyin tugaydi — shuning uchun biroz kutamiz. */
function wh(string $base, array $body, array $headers = []): array
{
    $r = http('POST', "$base/telegram/webhook.php", $body, $headers);
    usleep(600000);
    return $r;
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


/** Admin panelga kirib, amallarni bajaradi (cookie + CSRF). */
function admin_session(string $base, string $user, string $pass): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cj');
    $get = function (string $url) use ($jar): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
        $r = (string)curl_exec($ch);
        curl_close($ch);
        return $r;
    };
    $post = function (string $url, array $data) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
        $r = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $r];
    };
    $csrf = fn(string $html) => preg_match('~name="_csrf" value="([^"]+)"~', $html, $m) ? $m[1] : '';
    $login = $get("$base/admin/?p=login");
    $post("$base/admin/?p=login", ['_csrf' => $csrf($login), 'username' => $user, 'password' => $pass]);
    return [
        'act' => function (string $page, string $action, array $data = []) use ($get, $post, $csrf, $base): string {
            $html = $get("$base/admin/?p=$page");
            [, $r] = $post("$base/admin/?p=$page", ['_csrf' => $csrf($html), 'a' => $action] + $data);
            return $r;
        },
        'get' => fn(string $page) => $get("$base/admin/?p=$page"),
    ];
}

$UB = random_int(100_000_000, 900_000_000); // har safar yangi update_id (takroriy xabar bloklanadi)
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
wh($BASE, ['update_id' => $UB + 90, 'callback_query' => ['id' => 'q0', 'from' => ['id' => 111], 'data' => 'o:' . $cardOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 111]]]], $WH0);
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
[$c] = wh($BASE, ['update_id' => $UB + 1, 'message' => ['chat' => ['id' => 1, 'type' => 'private'], 'from' => ['id' => 1], 'text' => '/start']]);
check('Maxfiy tokensiz webhook so\'rovi rad etiladi', $c === 403, '403', $c);
$WH = ['X-Telegram-Bot-Api-Secret-Token: whsecret123'];
[$c] = wh($BASE, ['update_id' => $UB + 2, 'message' => ['message_id' => 1, 'chat' => ['id' => 5550001, 'type' => 'private'], 'from' => $user, 'text' => '/start']], $WH);
check('/start ishlaydi', $c === 200, '200', $c);
[$c] = wh($BASE, ['update_id' => $UB + 3, 'callback_query' => ['id' => 'q1', 'from' => ['id' => 999], 'data' => 'o:' . $cashOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 999]]]], $WH);
check('Admin bo\'lmagan foydalanuvchi buyurtmani qabul qila olmaydi', order_get((int)$cashOrder['id'])['status'] === 'new', 'new', order_get((int)$cashOrder['id'])['status']);
wh($BASE, ['update_id' => $UB + 4, 'callback_query' => ['id' => 'q2', 'from' => ['id' => 111], 'data' => 'o:' . $cashOrder['id'] . ':acc', 'message' => ['message_id' => 1, 'chat' => ['id' => 111]]]], $WH);
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


echo "\n=== Telegram bot: ikki marta javob bermaslik, Buyurtma berish tugmasi ===\n";
$dry = ROOT . '/storage/cache/tg_dry.log';
$sent = fn() => is_file($dry) ? array_values(array_filter(array_map(fn($l) => json_decode($l, true), file($dry, FILE_IGNORE_NEW_LINES)))) : [];
@unlink($dry);
$upd = ['update_id' => $UB + 777001, 'message' => ['message_id' => 5, 'chat' => ['id' => 5550001, 'type' => 'private'], 'from' => $user, 'text' => '/start']];
$t0 = microtime(true);
[$c] = wh($BASE, $upd, $WH);
$ms = (int)((microtime(true) - $t0) * 1000);
wh($BASE, $upd, $WH); // Telegram bir xil xabarni qayta yuboradi
wh($BASE, $upd, $WH);
usleep(500000);
$msgs = array_values(array_filter($sent(), fn($x) => $x[0] === 'sendMessage'));
check('/start ikki-uch marta kelsa ham bot FAQAT BIR marta javob beradi', count($msgs) === 1 && $c === 200, '1 ta xabar', ['xabarlar' => count($msgs), 'http' => $c]);
check("Pastki menyuda web_app tugmasi yo'q (u initData bermaydi va xatoga olib keladi)", !str_contains(json_encode($msgs[0][1]['reply_markup'] ?? []), 'web_app'), 'oddiy matn tugmalar', $msgs[0][1]['reply_markup'] ?? []);
@unlink($dry);
wh($BASE, ['update_id' => $UB + 777002, 'message' => ['message_id' => 6, 'chat' => ['id' => 5550001, 'type' => 'private'], 'from' => $user, 'text' => '🍔 Buyurtma berish']], $WH);
usleep(400000);
$msgs = array_values(array_filter($sent(), fn($x) => $x[0] === 'sendMessage'));
$kb = json_encode($msgs[0][1]['reply_markup'] ?? []);
check('«Buyurtma berish» bosilganda inline web_app tugmasi yuboriladi (initData keladi)', count($msgs) === 1 && str_contains($kb, 'inline_keyboard') && str_contains($kb, 'web_app') && str_contains($kb, 'telegram\/webapp'), 'inline_keyboard + web_app', $kb);
check('Telegram update_id bazaga yozildi (takroriy xabarni bloklash)', (int)val('SELECT COUNT(*) FROM tg_updates WHERE update_id IN (?, ?)', [$UB + 777001, $UB + 777002]) === 2, '2');


echo "\n=== Mijoz bekor qilishi va tayyorlanish vaqti ===\n";
rate_limit_reset('wa-order|5550001'); rate_limit_reset('webapp|5550001');
setting_set('cancel_minutes', '3');
setting_set('prep_minutes', '25');
[$c, $r] = $wa(['action' => 'order.create', 'type' => 'takeaway', 'phone' => '+998901119988', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 1]]]);
$co1 = $r['order'] ?? [];
[$c, $r] = $wa(['action' => 'order.get', 'uuid' => $co1['uuid'] ?? '']);
check('Yangi (qabul qilinmagan) buyurtmada "bekor qilish" imkoni ko\'rinadi', !empty($r['order']['can_cancel']), 'can_cancel=true', $r['order'] ?? $r);
[$c, $r] = $wa(['action' => 'order.cancel', 'uuid' => $co1['uuid'] ?? '']);
check('Mijoz qabul qilinmagan buyurtmani qisqa vaqt ichida bekor qila oladi', $c === 200 && order_get((int)$co1['id'])['status'] === 'cancelled', 'cancelled', $r);
[$c, $r] = $wa(['action' => 'order.create', 'type' => 'takeaway', 'phone' => '+998901119988', 'pay' => 'cash', 'items' => [['id' => $pid, 'qty' => 1]]]);
$co2 = $r['order'] ?? [];
q("UPDATE orders SET created_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?", [$co2['id']]);
[$c, $r] = $wa(['action' => 'order.cancel', 'uuid' => $co2['uuid']]);
check("3 daqiqadan o'tgach mijoz bekor qila olmaydi", $c === 400 && order_get((int)$co2['id'])['status'] === 'new', '400', $r);
@unlink($dry);
tg_order_set_status((int)$co2['id'], 'accepted');
$last = array_values(array_filter($sent(), fn($x) => $x[0] === 'sendMessage' && str_contains($x[1]['text'] ?? '', 'tayyorlanmoqda')));
check("Qabul qilinganda mijozga tayyorlanish vaqti yoziladi", $last && str_contains($last[0][1]['text'], '25 daqiqada'), '25 daqiqada', $last[0][1]['text'] ?? null);
[$c, $r] = $wa(['action' => 'order.cancel', 'uuid' => $co2['uuid']]);
check('Qabul qilingan buyurtmani mijoz bekor qila olmaydi', $c === 400 && order_get((int)$co2['id'])['status'] === 'accepted', '400', $r);

echo "\n=== Admin: xodim login/parol, stollarni o'chirish, chek dizayni, yozuvlar ===\n";
$adm = admin_session($BASE, $ADMIN[0], $ADMIN[1]);
$adm['act']('employees', 'employee.save', ['name' => 'Sinov Kassir', 'role' => 'cashier', 'pin' => '7391', 'username' => 'sinovkassir', 'password' => 'kassir123', 'is_active' => '1']);
$emp = row("SELECT * FROM employees WHERE login = 'sinovkassir'");
check('Kassirga login va parol yozildi (kassir roli)', $emp && $emp['role'] === 'cashier' && $emp['pw_hash'] && strlen($emp['pw_hash']) === 64, 'login + 64 belgili xesh', $emp ? array_diff_key($emp, ['pw_hash' => 1, 'pin_hash' => 1]) : null);
check('Kassir paroli ochiq saqlanmaydi, kassa tekshira oladigan PBKDF2 xesh', $emp && hash_equals($emp['pw_hash'], pin_hash('kassir123', $emp['pw_salt'])), 'PBKDF2 mos');
[$c, $r] = http('GET', "$BASE/api/?r=catalog", null, $DEV);
$ce = array_values(array_filter($r['employees'] ?? [], fn($e) => ($e['login'] ?? '') === 'sinovkassir'));
check('Kassa katalogida kassir logini va parol xeshi bor (offline kirish uchun)', $ce && !empty($ce[0]['lhash']) && !empty($ce[0]['lsalt']), 'login+lsalt+lhash', $ce);
$adm['act']('employees', 'employee.save', ['name' => 'Boshqa', 'role' => 'cashier', 'pin' => '7391', 'username' => 'boshqa', 'password' => 'abcdef1', 'is_active' => '1']);
check('Bir xil PIN ikki xodimda bo\'lmaydi', !val("SELECT id FROM employees WHERE name = 'Boshqa'"), 'yaratilmadi');
$adm['act']('employees', 'employee.save', ['name' => 'Boshqa', 'role' => 'cashier', 'pin' => '7392', 'username' => 'sinovkassir', 'password' => 'abcdef1', 'is_active' => '1']);
check('Band login qayta berilmaydi', !val("SELECT id FROM employees WHERE name = 'Boshqa'"), 'yaratilmadi');
$adm['act']('employees', 'employee.save', ['name' => 'Qisqa', 'role' => 'cashier', 'pin' => '7393', 'username' => 'qisqaparol', 'password' => '123', 'is_active' => '1']);
check('Juda qisqa parol rad etiladi', !val("SELECT id FROM employees WHERE login = 'qisqaparol'"), 'yaratilmadi');
[$c, $r] = http('POST', "$BASE/admin/?p=login", 'username=sinovkassir&password=kassir123');
check('Kassir admin panelga kira olmaydi', !str_contains(strtolower((string)(is_string($r) ? $r : '')), 'dashboard'), 'rad etiladi');

// Stollar
$before = (int)val('SELECT COUNT(*) FROM dining_tables');
$adm['act']('tables', 'table.bulk', ['count' => '2']);
$new = rows('SELECT id FROM dining_tables ORDER BY id DESC LIMIT 2');
check("Stollar qo'shildi", (int)val('SELECT COUNT(*) FROM dining_tables') === $before + 2, (string)($before + 2));
$adm['act']('tables', 'table.delete', ['id' => $new[0]['id']]);
check("Ishlatilmagan stol O'CHIRILADI", !val('SELECT id FROM dining_tables WHERE id = ?', [$new[0]['id']]), "bazadan o'chdi");
$usedTable = (int)val('SELECT table_id FROM orders WHERE table_id IS NOT NULL LIMIT 1');
if ($usedTable) {
    $adm['act']('tables', 'table.delete', ['id' => $usedTable]);
    $t = row('SELECT * FROM dining_tables WHERE id = ?', [$usedTable]);
    check('Tarixda ishlatilgan stol yashiriladi (hisobotlar buzilmaydi)', $t && (int)$t['is_active'] === 0, 'is_active=0', $t);
    q('UPDATE dining_tables SET is_active = 1 WHERE id = ?', [$usedTable]);
}
$adm['act']('tables', 'table.delete', ['id' => $new[1]['id']]);

// Yetkazish narxi va yozuvlar
[$c, $r] = $wa(['action' => 'init']);
check("Yetkazish narxi standartda 0 (bepul), 10 000 emas", $r['delivery_fee'] === 0, '0', $r['delivery_fee'] ?? null);
$adm['act']('telegram', 'settings.telegram', ['admin_tg_ids' => '111', 'bot_enabled' => '1', 'bot_pickup' => '1', 'bot_delivery' => '1', 'delivery_fee' => '12000', 'min_order' => '0', 'work_open' => '00:00', 'work_close' => '23:59',
    'contact_text' => 'Aloqa', 'bot_note_delivery' => "Yetkazish 12 000 so'm.\nOperator qo'ng'iroq qiladi.", 'bot_note_pickup' => 'Manzil: Andijon', 'bot_note_pay' => "Chekni shu yerga yuboring"]);
[$c, $r] = $wa(['action' => 'init']);
check("Admin yozgan yetkazish/olib ketish/to'lov yozuvlari mini ilovaga keldi", str_contains($r['notes']['delivery'] ?? '', 'Operator') && ($r['notes']['pickup'] ?? '') === 'Manzil: Andijon' && ($r['notes']['pay'] ?? '') !== '', '3 ta yozuv', $r['notes'] ?? null);
check('Yetkazish narxi admin o\'zgartirgach yangilandi', ($r['delivery_fee'] ?? 0) === 12000, '12000', $r['delivery_fee'] ?? null);
setting_set('delivery_fee', '0');

// Chek dizayni
$d = rc_default_design();
check('Standart oshxona cheki: buyurtma raqami, taomlar, izoh (mijoz telefoni yo\'q)', array_values(array_map(fn($b) => $b['kind'], array_filter($d['kitchen']['blocks'], fn($b) => $b['on']))) === ['order_no', 'items', 'comment'], 'order_no, items, comment', array_map(fn($b) => $b['kind'], array_filter($d['kitchen']['blocks'], fn($b) => $b['on'])));
$custom = $d;
$custom['kitchen']['blocks'][] = rc_block('x1', 'text', true, 'c', 2, true, ['text' => 'TEZ!']);
$custom['customer']['copies'] = 2;
$custom['font'] = 'b';
$custom['hack'] = ['<script>' => 1];
$custom['kitchen']['blocks'][] = ['kind' => 'qo_shimcha_hack', 'on' => true];
$adm['act']('receipt', 'receipt.save', ['design' => json_encode($custom)]);
[$c, $r] = http('GET', "$BASE/api/?r=catalog", null, $DEV);
$kb = $r['receipt']['design']['kitchen']['blocks'] ?? [];
check("Chek dizayni saqlandi va kassa katalogiga yetdi", ($r['receipt']['design']['font'] ?? '') === 'b' && ($r['settings']['customer_copies'] ?? 0) === 2 && end($kb)['text'] === 'TEZ!', 'font=b, 2 nusxa, TEZ! bloki', ['font' => $r['receipt']['design']['font'] ?? null]);
check("Noma'lum blok turlari va ortiqcha maydonlar dizaynga o'tmaydi", !in_array('qo_shimcha_hack', array_column($kb, 'kind'), true) && !isset($r['receipt']['design']['hack']), 'tozalangan');
// Logo
$png = tempnam(sys_get_temp_dir(), 'lg') . '.png';
$im = imagecreatetruecolor(300, 120);
imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
imagefilledellipse($im, 150, 60, 200, 90, imagecolorallocate($im, 0, 0, 0));
imagepng($im, $png);
$ch = curl_init("$BASE/admin/?p=receipt");
$jarFile = null;
$adm2 = admin_session($BASE, $ADMIN[0], $ADMIN[1]);
$html = $adm2['get']('receipt');
check('Chek dizayni sahifasi ochiladi (bloklar va jonli ko\'rinish)', str_contains($html, 'rc-preview') && str_contains($html, 'receipt.js') && str_contains($html, 'Oshxona cheki'), 'sahifa mazmuni');
$ras = rc_logo_raster($png, 60, 60);
check('Logo printer rasmiga (1 bit, 8 piksel karrali) aylantirildi', $ras['w'] % 8 === 0 && $ras['w'] <= 576 && strlen(base64_decode($ras['b64'])) === $ras['w'] / 8 * $ras['h'], 'w%8=0, bayt soni mos', ['w' => $ras['w'], 'h' => $ras['h']]);
$ras2 = rc_logo_raster($png, 30, 60);
check('Logo kattaligi foizi ishlaydi', $ras2['w'] < $ras['w'], 'kichikroq', [$ras['w'], $ras2['w']]);
$dark = rc_logo_raster($png, 60, 100);
$light = rc_logo_raster($png, 60, 0);
check('Logo qoraligi ishlaydi', strlen(str_replace("\0", '', base64_decode($dark['b64']))) >= strlen(str_replace("\0", '', base64_decode($light['b64']))), 'to\'qroq >= och');
$adm['act']('receipt', 'receipt.reset');
check('Dizaynni standartga qaytarish', setting('receipt_design') === '', "bo'sh");

// Natija
$pass = count(array_filter($results, fn($r) => $r[1]));
echo "\nJAMI: " . count($results) . " ta test, o'tdi: $pass, xato: " . (count($results) - $pass) . "\n";
file_put_contents(ROOT . '/storage/cache/test-results.json', json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
exit($pass === count($results) ? 0 : 1);
