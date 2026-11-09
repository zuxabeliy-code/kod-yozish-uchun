<?php
declare(strict_types=1);
defined('ADI') || exit;

/*
 * TIZIM DIAGNOSTIKASI VA TA'MIRLASH
 * Har bir tekshiruv haqiqiy sinov o'tkazadi. Sinov o'tkazib bo'lmasa holat "unchecked"
 * (Tekshirilmagan) bo'ladi — tizim o'zini "hammasi ishlayapti" deb ko'rsatmaydi.
 */

const DIAG_TITLES = [
    'php' => 'PHP server', 'mysql' => 'MySQL ulanishi', 'tables' => 'Jadvallar va migratsiyalar', 'api' => 'API endpointlar',
    'pos' => 'Kassa (POS)', 'agent' => 'Windows printer xizmati', 'printer' => 'Xprinter LAN ulanishi', 'print_queue' => 'Printer navbati',
    'bot' => 'Telegram bot', 'webhook' => 'Telegram webhook', 'webapp' => 'Telegram WebApp', 'hamyon' => "Hamyon to'lovi",
    'offline' => "Offline ma'lumotlar", 'sync' => 'Sinxronlash', 'shifts' => 'Smenalar', 'payments' => "To'lovlar",
    'orders' => 'Buyurtmalar', 'backup' => 'Zaxira (backup)', 'files' => 'Fayl ruxsatlari va himoya', 'php_errors' => 'PHP xatolar jurnali',
    'versions' => 'Versiyalar',
];

/** Har bir komponent uchun xavfsiz "Tuzatish" amali (bo'lsa). confirm=true — alohida tasdiq talab qilinadi. */
const DIAG_FIXES = [
    'tables' => ['label' => "Jadvallarni yaratish/yangilash", 'confirm' => true],
    'webhook' => ['label' => 'Webhookni qayta o\'rnatish', 'confirm' => false],
    'bot' => ['label' => 'Webhookni qayta o\'rnatish', 'confirm' => false],
    'sync' => ['label' => 'Xato amallarni qayta urinish', 'confirm' => false],
    'hamyon' => ['label' => "Kutilayotgan to'lovlarni tekshirish", 'confirm' => false],
    'backup' => ['label' => 'Hozir zaxira olish', 'confirm' => false],
    'files' => ['label' => 'Papkalar va himoya fayllarini tiklash', 'confirm' => false],
    'payments' => ["label" => "To'lov summalarini qayta hisoblash", 'confirm' => true],
    'api' => ['label' => 'Keshni xavfsiz yangilash', 'confirm' => false],
];

function diag_r(string $status, string $msg, string $advice = '', mixed $details = null): array
{
    return ['status' => $status, 'message' => $msg, 'advice' => $advice, 'details' => $details];
}

function diag_run(?array $only = null): array
{
    $out = [];
    foreach (array_keys(DIAG_TITLES) as $code) {
        if ($only && !in_array($code, $only, true)) {
            continue;
        }
        $fn = 'diag_check_' . $code;
        try {
            $r = $fn();
        } catch (Throwable $e) {
            $r = diag_r('error', 'Tekshiruvning o\'zi xato berdi: ' . $e->getMessage(), 'Xato tafsilotini ko\'ring.', basename($e->getFile()) . ':' . $e->getLine());
        }
        $r['code'] = $code;
        $r['title'] = DIAG_TITLES[$code];
        $out[$code] = $r;
        try {
            q('INSERT INTO diagnostic_checks (code, title, status, message, details, advice, checked_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
               ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status), message = VALUES(message), details = VALUES(details), advice = VALUES(advice), checked_at = NOW()',
                [$code, $r['title'], $r['status'], mb_substr($r['message'], 0, 500), $r['details'] === null ? null : mb_substr(is_string($r['details']) ? $r['details'] : json_encode(redact($r['details']), JSON_UNESCAPED_UNICODE), 0, 4000), mb_substr($r['advice'], 0, 1000)]);
            if ($r['status'] === 'error') {
                record_error($code, 'DIAG', $r['message'], $r['details'], $r['advice']);
            }
        } catch (Throwable) {
            // Baza ishlamasa ham natija ekranda ko'rinadi
        }
    }
    return $out;
}

/** Serverning o'ziga HTTP so'rov (loopback). */
function diag_http(string $url, int $timeout = 6): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false]);
    $t = microtime(true);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err, 'ms' => (int)((microtime(true) - $t) * 1000)];
}

function diag_device(): ?array
{
    return row('SELECT * FROM devices WHERE is_active = 1 ORDER BY last_seen_at DESC LIMIT 1');
}

function diag_device_status(): array
{
    $d = diag_device();
    $st = $d ? (json_decode((string)$d['status_json'], true) ?: []) : [];
    $age = $d && $d['last_seen_at'] ? time() - strtotime($d['last_seen_at']) : null;
    return [$d, $st, $age];
}

function diag_check_php(): array
{
    $need = ['pdo_mysql', 'mbstring', 'curl', 'json', 'openssl'];
    $opt = ['gd' => 'rasmlarni kichraytirish', 'zip' => 'kassa o\'rnatuvchisi ZIP', 'zlib' => 'zaxira siqish'];
    $miss = array_values(array_filter($need, fn($e) => !extension_loaded($e)));
    $missOpt = array_filter($opt, fn($e) => !extension_loaded($e), ARRAY_FILTER_USE_KEY);
    $det = ['version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize')];
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        return diag_r('error', 'PHP ' . PHP_VERSION . ' eski. Kamida 8.0 kerak (8.2 tavsiya).', 'Hosting panelida PHP versiyasini 8.2 ga o\'zgartiring.', $det);
    }
    if ($miss) {
        return diag_r('error', 'Majburiy PHP kengaytmalari yo\'q: ' . implode(', ', $miss), 'Hosting panelida (PHP sozlamalari / Extensions) shu kengaytmalarni yoqing.', $det);
    }
    if ($missOpt) {
        return diag_r('warn', 'PHP ' . PHP_VERSION . ' ishlayapti. Ixtiyoriy kengaytmalar yo\'q: ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($missOpt), $missOpt)), 'Hosting panelida yoqsangiz, qo\'shimcha imkoniyatlar ishlaydi.', $det);
    }
    return diag_r('ok', 'PHP ' . PHP_VERSION . ', barcha kengaytmalar bor.', '', $det);
}

function diag_check_mysql(): array
{
    $t = microtime(true);
    try {
        $v = (string)val('SELECT VERSION()');
    } catch (Throwable $e) {
        return diag_r('error', 'MySQL ga ulanib bo\'lmadi: ' . $e->getMessage(), 'config/config.php dagi baza nomi, foydalanuvchi va parolni tekshiring. Hosting panelida MySQL ishlayotganini tekshiring.');
    }
    $ms = (int)((microtime(true) - $t) * 1000);
    return $ms > 500 ? diag_r('warn', "MySQL $v ulangan, lekin sekin ($ms ms).", 'Hosting yuklamasi yuqori bo\'lishi mumkin.')
        : diag_r('ok', "MySQL $v ulangan ($ms ms).");
}

function diag_check_tables(): array
{
    $have = array_map('strtolower', q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $miss = array_values(array_diff(required_tables(), $have));
    $ver = (int)setting('schema_version');
    if ($miss) {
        return diag_r('error', 'Jadvallar yetishmaydi: ' . implode(', ', $miss), '"Tuzatish" tugmasi yetishmayotgan jadvallarni yaratadi (mavjud ma\'lumotlar o\'chmaydi).');
    }
    if ($ver < SCHEMA_VERSION) {
        return diag_r('warn', "Sxema versiyasi eski ($ver < " . SCHEMA_VERSION . ')', '"Tuzatish" tugmasini bosing.');
    }
    return diag_r('ok', count(required_tables()) . ' ta jadval joyida, sxema v' . $ver . '.');
}

function diag_check_api(): array
{
    if (!cfg('app.url')) {
        return diag_r('unchecked', 'Sayt manzili sozlanmagan.');
    }
    $r = diag_http(app_url('api/?r=ping'));
    if ($r['err'] !== '' || $r['code'] === 0) {
        return diag_r('unchecked', 'Server o\'ziga so\'rov yubora olmadi (hostingda loopback yopiq bo\'lishi mumkin): ' . $r['err'],
            'Bu xato emas bo\'lishi mumkin. Kassa ulanishi (POS) tekshiruviga qarang — kassa API orqali ishlayotgan bo\'lsa, API ishlayapti.');
    }
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($j['ok'])) {
        return diag_r('error', "API javobi noto'g'ri (HTTP {$r['code']}).", 'PHP xatolar jurnalini ko\'ring.', mb_substr($r['body'], 0, 300));
    }
    return diag_r('ok', "API ishlayapti ({$r['ms']} ms).");
}

function diag_check_pos(): array
{
    [$d, $st, $age] = diag_device_status();
    if (!$d) {
        return diag_r('unchecked', 'Hali birorta kassa ulanmagan.', 'Monoblokda kassani oching va administrator logini bilan qurilmani ulang.');
    }
    if ($age === null) {
        return diag_r('unchecked', "\"{$d['name']}\" ro'yxatdan o'tgan, lekin hali aloqaga chiqmagan.");
    }
    $when = date('d.m H:i', strtotime($d['last_seen_at']));
    if ($age < 120) {
        return diag_r('ok', "\"{$d['name']}\" onlayn (oxirgi aloqa: $when). Versiya: " . ($d['app_version'] ?: '?'));
    }
    if ($age < 3600) {
        return diag_r('warn', "\"{$d['name']}\" " . intdiv($age, 60) . " daqiqadan beri aloqaga chiqmadi ($when).", 'Kassa yopiq yoki internet uzilgan bo\'lishi mumkin. Kassa offline ham ishlaydi — internet qaytsa ma\'lumotlar o\'zi yuboriladi.');
    }
    return diag_r('error', "\"{$d['name']}\" uzoq vaqtdan beri aloqaga chiqmadi ($when).", 'Monoblok yoqilganini, kassa ochiq ekanini va internetni tekshiring.');
}

function diag_stale_note(?int $age): string
{
    return $age === null ? '' : ' (kassa xabari: ' . ($age < 120 ? 'hozir' : intdiv($age, 60) . ' daqiqa oldin') . ')';
}

function diag_check_agent(): array
{
    [$d, $st, $age] = diag_device_status();
    if (!$d || !isset($st['agent']) || $age === null || $age > 900) {
        return diag_r('unchecked', 'Printer xizmati holati noma\'lum: kassadan yangi xabar yo\'q.', 'Monoblokda kassani oching. Ushbu sahifani monoblokda ochsangiz, xizmat to\'g\'ridan-to\'g\'ri tekshiriladi.');
    }
    $a = $st['agent'];
    if (empty($a['ok'])) {
        return diag_r('error', 'Printer xizmati ishlamayapti: ' . ($a['error'] ?? 'javob yo\'q') . diag_stale_note($age),
            'Monoblokda "kassa-ornatish.bat" ni qayta ishga tushiring yoki kompyuterni qayta yoqing. Xizmat Windows yoqilganda o\'zi ishga tushadi.', $a);
    }
    return diag_r('ok', 'Printer xizmati ishlayapti, versiya ' . ($a['version'] ?? '?') . diag_stale_note($age), '', $a);
}

function diag_check_printer(): array
{
    [$d, $st, $age] = diag_device_status();
    if (!$d || !isset($st['printer']) || $age === null || $age > 900) {
        return diag_r('unchecked', 'Printer holati noma\'lum: kassadan yangi xabar yo\'q.');
    }
    $p = $st['printer'];
    if (empty($p['configured'])) {
        return diag_r('warn', 'Printer sozlanmagan.', 'Kassada ⚙ → Printer: IP manzil, port (odatda 9100) va chek o\'lchamini kiriting, "Saqlash" va "Test chek" ni bosing.');
    }
    if (empty($p['ok'])) {
        return diag_r('error', 'Printer javob bermayapti: ' . ($p['error'] ?? '') . diag_stale_note($age),
            "1) Printer yoqilganini va qog'oz borligini tekshiring. 2) LAN kabelini tekshiring. 3) Printer IP manzili o'zgarmaganini tekshiring (printer o'chirib yoqilganda o'z sozlamasini chop etadi). 4) Kassada \"Qayta ulash\" ni bosing.", $p);
    }
    return diag_r('ok', 'Printer ulangan: ' . ($p['target'] ?? '') . diag_stale_note($age), '', $p);
}

function diag_check_print_queue(): array
{
    [$d, $st, $age] = diag_device_status();
    $srvErr = (int)val("SELECT COUNT(*) FROM print_jobs WHERE status IN ('error','unknown') AND created_at > NOW() - INTERVAL 1 DAY");
    if (!$d || !isset($st['queue']) || $age === null || $age > 900) {
        return $srvErr ? diag_r('warn', "Oxirgi 24 soatda $srvErr ta chek xato yoki noma'lum holatda (kassa xabari eskirgan).", 'Kassada ⚙ → Printer navbati bo\'limini oching.')
            : diag_r('unchecked', 'Navbat holati noma\'lum: kassadan yangi xabar yo\'q.');
    }
    $q = $st['queue'];
    $bad = (int)($q['error'] ?? 0) + (int)($q['unknown'] ?? 0);
    if ($bad > 0) {
        return diag_r('warn', "Navbatda $bad ta chek xato yoki natijasi noma'lum. Kutilmoqda: " . (int)($q['pending'] ?? 0),
            "Kassada ⚙ → Printer navbati: chek qog'ozga chiqmagan bo'lsa \"Qayta chiqarish\" ni bosing. Noma'lum holatdagi chekni avval qog'ozdan tekshiring — ikki marta chiqmasin.", $q);
    }
    return diag_r('ok', 'Navbat toza. Kutilmoqda: ' . (int)($q['pending'] ?? 0) . diag_stale_note($age), '', $q);
}

function diag_check_bot(): array
{
    if (setting('bot_token') === '') {
        return diag_r(setting_on('bot_enabled') ? 'error' : 'unchecked', 'Bot tokeni kiritilmagan.', 'Admin → Telegram bot bo\'limida @BotFather bergan tokenni kiriting.');
    }
    $r = tg_api('getMe', [], 6);
    if (!$r) {
        return diag_r('error', 'Bot javob bermadi: ' . ($GLOBALS['TG_ERR'] ?? ''), 'Token to\'g\'riligini tekshiring. Token o\'zgargan bo\'lsa, yangisini kiriting.');
    }
    $u = $r['result']['username'] ?? '';
    if (!setting_on('bot_enabled')) {
        return diag_r('warn', "@$u ulangan, lekin buyurtma qabul qilish o'chirilgan.", 'Admin → Telegram bot → "Botni yoqish".');
    }
    return diag_r('ok', "@$u ishlayapti.");
}

function diag_check_webhook(): array
{
    if (setting('bot_token') === '') {
        return diag_r('unchecked', 'Bot tokeni yo\'q — webhook tekshirilmadi.');
    }
    $r = tg_api('getWebhookInfo', [], 6);
    if (!$r) {
        return diag_r('error', 'Webhook ma\'lumotini olib bo\'lmadi: ' . ($GLOBALS['TG_ERR'] ?? ''));
    }
    $i = $r['result'];
    $want = app_url('telegram/webhook.php');
    $det = ['url' => $i['url'] ?? '', 'pending' => $i['pending_update_count'] ?? 0, 'last_error' => $i['last_error_message'] ?? null,
        'last_error_at' => isset($i['last_error_date']) ? date('d.m H:i', (int)$i['last_error_date']) : null];
    if (($i['url'] ?? '') !== $want) {
        return diag_r('error', 'Webhook boshqa manzilga o\'rnatilgan yoki o\'rnatilmagan.', '"Tuzatish" tugmasi webhookni to\'g\'ri manzilga qayta o\'rnatadi.', $det);
    }
    if (!empty($i['last_error_date']) && $i['last_error_date'] > time() - 600) {
        return diag_r('error', 'Telegram webhookka yetib bora olmayapti: ' . ($i['last_error_message'] ?? ''),
            'SSL sertifikat va sayt ishlayotganini tekshiring, so\'ng "Tuzatish" ni bosing.', $det);
    }
    if (($i['pending_update_count'] ?? 0) > 20) {
        return diag_r('warn', 'Telegramda ' . $i['pending_update_count'] . ' ta yetkazilmagan xabar navbatda.', 'Sayt sekin javob berayotgan bo\'lishi mumkin.', $det);
    }
    return diag_r('ok', 'Webhook to\'g\'ri o\'rnatilgan, xato yo\'q.', '', $det);
}

function diag_check_webapp(): array
{
    if (!str_starts_with(app_url(), 'https://')) {
        return diag_r('error', 'Sayt manzili https emas. Telegram WebApp faqat HTTPS bilan ishlaydi.', 'Hostingda SSL sertifikatni yoqing va config/config.php da app.url ni https:// ga o\'zgartiring.');
    }
    $r = diag_http(tg_webapp_url());
    if ($r['code'] === 0) {
        return diag_r('unchecked', 'Server o\'ziga so\'rov yubora olmadi: ' . $r['err'], 'Telefoningizda botni ochib, "Menyu" tugmasini bosib tekshiring.');
    }
    if ($r['code'] !== 200 || !str_contains($r['body'], 'telegram-web-app')) {
        return diag_r('error', "WebApp sahifasi ochilmadi (HTTP {$r['code']}).", 'telegram/webapp papkasi to\'liq yuklanganini tekshiring.');
    }
    return diag_r('ok', "WebApp sahifasi ochilyapti ({$r['ms']} ms).");
}

function diag_check_hamyon(): array
{
    if (!setting_on('pay_hamyon')) {
        return diag_r('unchecked', "Hamyon to'lovi o'chirilgan.", "Kerak bo'lsa: Admin → To'lovlar → Hamyon.");
    }
    $p = hamyon_ping();
    $stuck = (int)val("SELECT COUNT(*) FROM hamyon_payments WHERE status = 'pending' AND expire_at < ?", [time() - 600]);
    $det = ['ping' => $p, 'stuck' => $stuck, 'callback' => app_url('pay/hamyon.php')];
    if (!$p['ok']) {
        return diag_r('error', $p['msg'], "Hamyon kabinetidagi shop_id va shop_key ni Admin → To'lovlar bo'limiga qayta kiriting. Callback manzili (prepare va complete): " . app_url('pay/hamyon.php'), $det);
    }
    if ($stuck > 0) {
        return diag_r('warn', "$stuck ta to'lov natijasi kelmagan.", "Hamyon kabinetida callback manzili to'g'riligini tekshiring: " . app_url('pay/hamyon.php') . '. "Tuzatish" bu to\'lovlarni Hamyon\'dan qayta so\'raydi.', $det);
    }
    return diag_r('ok', $p['msg'], '', $det);
}

function diag_check_offline(): array
{
    [$d, $st, $age] = diag_device_status();
    if (!$d || !isset($st['outbox']) || $age === null) {
        return diag_r('unchecked', 'Kassadan offline navbat haqida ma\'lumot yo\'q.');
    }
    $n = (int)$st['outbox']['count'];
    $oldest = (int)($st['outbox']['oldest_sec'] ?? 0);
    if ($age > 600) {
        return diag_r('warn', "Kassa {$age} soniyadan beri aloqada emas. Oxirgi ma'lum: yuborilmagan $n ta amal.", 'Internet qaytsa ma\'lumotlar avtomatik yuboriladi. Kassa kompyuterini o\'chirmang.');
    }
    if ($n > 0 && $oldest > 600) {
        return diag_r('warn', "Kassada $n ta amal " . intdiv($oldest, 60) . ' daqiqadan beri yuborilmagan.', 'Sinxronlash xatolarini ko\'ring.', $st['outbox']);
    }
    return diag_r('ok', $n ? "Kassada $n ta amal navbatda (yuborilmoqda)." : 'Barcha offline ma\'lumotlar serverga yuborilgan.', '', $st['outbox']);
}

function diag_check_sync(): array
{
    $failed = (int)val("SELECT COUNT(*) FROM sync_operations WHERE status = 'failed'");
    $last = val("SELECT MAX(received_at) FROM sync_operations WHERE status = 'applied'");
    if ($failed) {
        $ex = rows("SELECT type, error FROM sync_operations WHERE status = 'failed' ORDER BY id DESC LIMIT 5");
        return diag_r('error', "$failed ta amal serverda bajarilmadi.", '"Tuzatish" ularni qayta bajarib ko\'radi. Qayta xato bo\'lsa, xato matnini o\'qing.', $ex);
    }
    return diag_r('ok', 'Xato amal yo\'q.' . ($last ? ' Oxirgi sinxronlash: ' . date('d.m H:i', strtotime((string)$last)) : ''));
}

function diag_check_shifts(): array
{
    $open = rows("SELECT number, opened_at FROM shifts WHERE status = 'open' ORDER BY id");
    if (count($open) > 1) {
        return diag_r('warn', count($open) . ' ta smena ochiq turibdi.', 'Kassada eski smenani yoping.', $open);
    }
    if ($open && strtotime($open[0]['opened_at']) < time() - 86400) {
        return diag_r('warn', "{$open[0]['number']}-smena 24 soatdan ko'p ochiq.", 'Kun oxirida smenani yoping — Z-hisobot chiqadi.');
    }
    return diag_r('ok', $open ? "{$open[0]['number']}-smena ochiq (" . date('d.m H:i', strtotime($open[0]['opened_at'])) . ')' : 'Ochiq smena yo\'q.');
}

function diag_check_payments(): array
{
    $mismatch = rows("SELECT o.id, o.number, o.paid_total, COALESCE(SUM(p.amount), 0) AS real_paid FROM orders o
                      LEFT JOIN payments p ON p.order_id = o.id AND p.status = 'ok'
                      WHERE o.created_at > NOW() - INTERVAL 30 DAY GROUP BY o.id HAVING o.paid_total <> real_paid LIMIT 20");
    $checking = (int)val("SELECT COUNT(*) FROM card_receipts WHERE status = 'checking' AND created_at < NOW() - INTERVAL 20 MINUTE");
    if ($mismatch) {
        return diag_r('error', count($mismatch) . " ta buyurtmada to'lov summasi mos emas.", '"Tuzatish" summalarni to\'lovlar jadvalidan qayta hisoblaydi (to\'lovlar o\'chmaydi).', $mismatch);
    }
    if ($checking) {
        return diag_r('warn', "$checking ta karta to'lov cheki 20 daqiqadan ko'p tekshirilmay turibdi.", 'Kassada "Telegram" bo\'limida chekni ko\'rib tasdiqlang yoki rad eting.');
    }
    return diag_r('ok', "To'lov summalari mos.");
}

function diag_check_orders(): array
{
    $stuck = (int)val("SELECT COUNT(*) FROM orders WHERE source = 'telegram' AND status = 'new' AND created_at < NOW() - INTERVAL 15 MINUTE");
    $today = (int)val("SELECT COUNT(*) FROM orders WHERE created_at >= CURDATE()");
    if ($stuck) {
        return diag_r('warn', "$stuck ta Telegram buyurtmasi 15 daqiqadan ko'p qabul qilinmagan.", 'Kassa ochiqligini va ovoz yoqilganini tekshiring.');
    }
    return diag_r('ok', "Bugun $today ta buyurtma. Qotib qolgan buyurtma yo'q.");
}

function diag_check_backup(): array
{
    $last = setting('last_backup_at');
    if ($last === '') {
        return diag_r('warn', 'Hali birorta zaxira olinmagan.', '"Tuzatish" — hozir zaxira oladi.');
    }
    $age = time() - strtotime($last);
    if ($age > 86400 * 3) {
        return diag_r('warn', 'Oxirgi zaxira ' . intdiv($age, 86400) . ' kun oldin olingan.', '"Tuzatish" — hozir zaxira oladi. Kassa ishlab tursa, zaxira har kuni o\'zi olinadi.');
    }
    return diag_r('ok', 'Oxirgi zaxira: ' . date('d.m.Y H:i', strtotime($last)) . ' (' . count(backup_list()) . ' ta nusxa).');
}

function diag_check_files(): array
{
    $bad = [];
    foreach (['storage', 'storage/logs', 'storage/backups', 'storage/cache', 'uploads', 'uploads/products', 'uploads/receipts'] as $dir) {
        if (!is_dir(ROOT . '/' . $dir) || !is_writable(ROOT . '/' . $dir)) {
            $bad[] = "$dir yozib bo'lmaydi";
        }
    }
    foreach (['app/.htaccess', 'config/.htaccess', 'storage/.htaccess', 'database/.htaccess', 'uploads/.htaccess'] as $f) {
        if (!is_file(ROOT . '/' . $f)) {
            $bad[] = "$f yo'q";
        }
    }
    if ($bad) {
        return diag_r('error', implode('; ', $bad), '"Tuzatish" papkalarni yaratadi va himoya fayllarini tiklaydi. Yordam bermasa, hosting File Manager orqali papkaga 755 ruxsat bering.');
    }
    // Maxfiy papkalar internetdan ochilmasligini haqiqiy so'rov bilan tekshiramiz
    $leaks = [];
    $unchecked = false;
    foreach (['database/schema.sql', 'storage/backups/', 'app/db.php'] as $p) {
        $r = diag_http(app_url($p), 4);
        if ($r['code'] === 0) {
            $unchecked = true;
            continue;
        }
        if ($r['code'] === 200 && strlen($r['body']) > 0 && !str_contains($r['body'], '<html')) {
            $leaks[] = $p;
        }
    }
    if ($leaks) {
        return diag_r('error', 'Maxfiy fayllar internetdan ochilyapti: ' . implode(', ', $leaks),
            'Hosting .htaccess fayllarini o\'qimayapti (nginx bo\'lishi mumkin). Hosting yordamiga yozing: "app, config, database, storage papkalariga tashqaridan kirishni yoping".');
    }
    return $unchecked ? diag_r('warn', 'Papkalar yozishga ochiq. Tashqi kirish himoyasini tekshirib bo\'lmadi (loopback yopiq).')
        : diag_r('ok', 'Papkalar yozishga ochiq, maxfiy fayllar internetdan yopiq.');
}

function diag_check_php_errors(): array
{
    $n = (int)val("SELECT COUNT(*) FROM error_logs WHERE fix_status = 'open' AND last_seen_at > NOW() - INTERVAL 1 DAY AND component IN ('php','sync','api','webhook','webapp','maintenance')");
    $f = ROOT . '/storage/logs/errors-' . date('Ymd') . '.log.php';
    $lines = is_file($f) ? max(0, count(file($f)) - 1) : 0;
    if ($n > 0) {
        return diag_r('warn', "Oxirgi 24 soatda $n ta ochiq texnik xato.", 'Pastdagi "Xatolar tarixi" jadvalida tafsilotni ko\'ring.', ['today_log_lines' => $lines]);
    }
    return diag_r('ok', 'Oxirgi 24 soatda ochiq texnik xato yo\'q.', '', ['today_log_lines' => $lines]);
}

function diag_check_versions(): array
{
    [$d, $st] = diag_device_status();
    $det = ['server' => ADI_VERSION, 'php' => PHP_VERSION, 'pos' => $d['app_version'] ?? null, 'agent' => $st['agent']['version'] ?? null];
    $msg = 'Server ' . ADI_VERSION . ' · Kassa ' . ($det['pos'] ?? '?') . ' · Printer xizmati ' . ($det['agent'] ?? '?');
    if ($det['pos'] && $det['pos'] !== ADI_VERSION) {
        return diag_r('warn', $msg, 'Kassa eski versiyada. Kassada Ctrl+F5 bosing yoki kassani qayta oching.', $det);
    }
    return diag_r($det['pos'] ? 'ok' : 'unchecked', $msg, '', $det);
}

// ---------- Tuzatish amallari ----------

function diag_fix(string $code): string
{
    switch ($code) {
        case 'tables':
            db_migrate();
            return 'Jadvallar tekshirildi va yetishmaganlari yaratildi.';
        case 'bot':
        case 'webhook':
            $r = tg_webhook_set();
            if (!$r['ok']) {
                throw new UserError('Webhook o\'rnatilmadi: ' . $r['error']);
            }
            return 'Webhook qayta o\'rnatildi.';
        case 'sync':
            $n = 0;
            $fail = 0;
            foreach (rows("SELECT * FROM sync_operations WHERE status = 'failed' ORDER BY id LIMIT 100") as $op) {
                $dev = row('SELECT * FROM devices WHERE id = ?', [$op['device_id']]) ?? ['id' => null];
                q("DELETE FROM sync_operations WHERE id = ? AND status = 'failed'", [$op['id']]);
                $r = sync_apply_op(['op_uuid' => $op['op_uuid'], 'type' => $op['type'], 'data' => json_decode($op['payload'], true) ?: [], 'created_at' => $op['client_time']], $dev);
                $r['status'] === 'applied' ? $n++ : $fail++;
            }
            return "Qayta urinildi: $n ta bajarildi, $fail ta yana xato.";
        case 'hamyon':
            return hamyon_reconcile(50) . " ta to'lov holati yangilandi.";
        case 'backup':
            $b = backup_create();
            return 'Zaxira olindi: ' . $b['name'];
        case 'files':
            install_protect_dirs();
            return 'Papkalar va himoya fayllari tiklandi.';
        case 'payments':
            $n = 0;
            foreach (rows("SELECT o.id FROM orders o LEFT JOIN payments p ON p.order_id = o.id AND p.status = 'ok'
                           GROUP BY o.id HAVING MAX(o.paid_total) <> COALESCE(SUM(p.amount), 0)") as $o) {
                order_recalc_payment((int)$o['id']);
                $n++;
            }
            return "$n ta buyurtma summasi qayta hisoblandi.";
        case 'api':
            catalog_bump();
            foreach (glob(ROOT . '/storage/cache/*.json') ?: [] as $f) {
                @unlink($f);
            }
            return 'Kesh yangilandi. Kassa menyuni keyingi sinxronlashda qayta yuklaydi.';
    }
    throw new UserError('Bu komponent uchun avtomatik tuzatish yo\'q. Tavsiyaga amal qiling.');
}

/** Himoya fayllari: Apache/LiteSpeed uchun .htaccess (nginx uchun PHP fayllarning o'zi exit bilan himoyalangan). */
function install_protect_dirs(): void
{
    $deny = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    foreach (['storage', 'storage/logs', 'storage/backups', 'storage/cache', 'uploads/products', 'uploads/receipts'] as $d) {
        if (!is_dir(ROOT . '/' . $d)) {
            @mkdir(ROOT . '/' . $d, 0755, true);
        }
    }
    foreach (['app', 'config', 'storage', 'database', 'tests', 'docs'] as $d) {
        if (is_dir(ROOT . '/' . $d)) {
            @file_put_contents(ROOT . '/' . $d . '/.htaccess', $deny);
        }
    }
    // Yuklangan fayllar ichida PHP hech qachon bajarilmasin
    @file_put_contents(ROOT . '/uploads/.htaccess', "<FilesMatch \"\\.(php|phtml|php\\d|phar|pl|py|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes -ExecCGI\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n");
    // Mijoz to'lov cheklari faqat admin/kassa orqali ko'rinadi
    @file_put_contents(ROOT . '/uploads/receipts/.htaccess', $deny);
}
