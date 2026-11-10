<?php
declare(strict_types=1);
defined('ADI') || exit;

/*
 * MCP (Model Context Protocol) — Claude shu orqali sayt, bot va kodni boshqaradi.
 * Standart holatda O'CHIQ. Admin panel → "Claude (MCP)" bo'limida yoqiladi va kalit yaratiladi.
 * Kalit bazada ochiq saqlanmaydi (faqat SHA-256 xeshi). Har bir yozishdan oldin fayl zaxirasi olinadi,
 * PHP fayllar sintaksisi tekshiriladi (xato bo'lsa yozilmaydi). Baza faqat o'qiladi.
 */

const MCP_PROTOCOL = '2025-03-26';
const MCP_MAX_FILE = 600000;
/** Tashqaridan o'qish/yozish taqiqlangan joylar (parollar, zaxiralar, yuklangan to'lov cheklari). */
const MCP_DENY = ['config/', 'storage/backups/', 'storage/mcp/', 'uploads/receipts/', '.git/'];

function mcp_enabled(): bool
{
    return setting('mcp_enabled') === '1' && setting('mcp_key_hash') !== '';
}

function mcp_new_key(): string
{
    $key = 'adi_' . bin2hex(random_bytes(24));
    setting_set('mcp_key_hash', hash('sha256', $key));
    return $key;
}

function mcp_auth(): bool
{
    if (!mcp_enabled()) {
        return false;
    }
    $key = '';
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($h, 'Bearer ') === 0) {
        $key = trim(substr($h, 7));
    }
    if ($key === '') {
        $key = (string)($_GET['k'] ?? '');
    }
    return $key !== '' && hash_equals(setting('mcp_key_hash'), hash('sha256', $key));
}

function mcp_log(string $msg): void
{
    log_msg('mcp', $msg);
}

/** Yo'lni loyiha ichidagi xavfsiz nisbiy yo'lga keltiradi. */
function mcp_path(string $p, bool $mustExist = true): array
{
    $p = str_replace('\\', '/', trim($p));
    $p = ltrim($p, '/');
    if ($p === '' || $p === '.') {
        return ['', ROOT];
    }
    if (str_contains($p, "\0") || preg_match('~(^|/)\.\.(/|$)~', $p)) {
        throw new UserError("Yo'l noto'g'ri: «..» ishlatib bo'lmaydi.");
    }
    foreach (MCP_DENY as $d) {
        if (stripos($p . '/', $d) === 0) {
            throw new UserError("Bu joy himoyalangan: $d (parollar va zaxiralar). MCP orqali ochilmaydi.");
        }
    }
    $abs = ROOT . '/' . $p;
    if ($mustExist) {
        $real = realpath($abs);
        if ($real === false || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', realpath(ROOT) ?: ROOT))) {
            throw new UserError("Topilmadi: $p");
        }
    }
    return [$p, $abs];
}

/** PHP kod sintaksisini jarayon ichida tekshiradi (hostingda exec o'chiq bo'lsa ham ishlaydi). */
function mcp_php_lint(string $code): ?string
{
    try {
        token_get_all($code, TOKEN_PARSE);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage() . ' (qator ' . $e->getLine() . ')';
    }
}

function mcp_backup_file(string $rel, string $abs): string
{
    $dir = ROOT . '/storage/mcp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    $name = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '__' . str_replace('/', '~', $rel) . '.bak';
    if (is_file($abs)) {
        copy($abs, $dir . '/' . $name);
    } else {
        file_put_contents($dir . '/' . $name, "\0YANGI_FAYL\0");
    }
    // Eng eski zaxiralar tozalanadi (oxirgi 300 tasi qoladi)
    $all = glob($dir . '/*.bak') ?: [];
    if (count($all) > 300) {
        sort($all);
        foreach (array_slice($all, 0, count($all) - 300) as $f) {
            @unlink($f);
        }
    }
    return $name;
}

function mcp_write_file(string $rel, string $abs, string $content): string
{
    if (strlen($content) > MCP_MAX_FILE) {
        throw new UserError('Fayl juda katta (600 KB dan oshmasin).');
    }
    if (preg_match('~\.php$~i', $rel) && ($err = mcp_php_lint($content)) !== null) {
        throw new UserError("PHP sintaksis xatosi — fayl YOZILMADI: $err");
    }
    if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0775, true)) {
        throw new UserError('Papka yaratib bo\'lmadi: ' . dirname($rel));
    }
    $bak = mcp_backup_file($rel, $abs);
    $tmp = $abs . '.mcp-tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $abs)) {
        @unlink($tmp);
        throw new UserError("Faylga yozib bo'lmadi (ruxsat yo'q): $rel");
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($abs, true);
    }
    // Kassa fayllari o'zgarsa — kassalar yangi versiyani olsin
    if (str_starts_with($rel, 'pos/') && $rel !== 'pos/sw.js') {
        mcp_touch_sw();
    }
    return $bak;
}

/** pos/sw.js dagi kesh nomiga belgi qo'shadi: kassalar o'zgargan fayllarni qayta yuklaydi. */
function mcp_touch_sw(): void
{
    $f = ROOT . '/pos/sw.js';
    $s = (string)@file_get_contents($f);
    $n = preg_replace("~const VERSION = '([^'+]+)(\+[0-9]+)?';~", "const VERSION = '$1+" . time() . "';", $s, 1);
    if ($n && $n !== $s) {
        @file_put_contents($f, $n, LOCK_EX);
    }
}

function mcp_tools(): array
{
    $s = static fn(array $props = [], array $req = []) => ['type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $req];
    $str = static fn(string $d) => ['type' => 'string', 'description' => $d];
    $int = static fn(string $d) => ['type' => 'integer', 'description' => $d];
    return [
        ['name' => 'holat', 'description' => 'ADI BURGER tizimining umumiy holati: versiya, PHP, bugungi savdo, ochiq smena, kassa qurilmalari, bot, xatolar soni. Avval shuni chaqiring.', 'inputSchema' => $s()],
        ['name' => 'diagnostika', 'description' => "To'liq texnik tekshiruv (baza, bot, webhook, printer, sinxronlash va boshqalar). Har bir band: holat, xabar, tavsiya.", 'inputSchema' => $s()],
        ['name' => 'tuzatish', 'description' => "Diagnostikadagi bitta bandni avtomatik tuzatadi (masalan: tables, webhook, cache). 'kod' — diagnostika bandining kodi.", 'inputSchema' => $s(['kod' => $str('Band kodi')], ['kod'])],
        ['name' => 'fayllar', 'description' => "Papkadagi fayllar ro'yxati (hajmi va o'zgargan vaqti bilan). 'yol' bo'sh bo'lsa — loyiha ildizi.", 'inputSchema' => $s(['yol' => $str('Papka, masalan: app yoki pos/js')])],
        ['name' => 'oqish', 'description' => "Fayl matnini o'qiydi (qator raqamlari bilan). Katta fayl uchun 'boshi' va 'soni' bering.", 'inputSchema' => $s(['yol' => $str('Fayl yo\'li, masalan: app/tg.php'), 'boshi' => $int('Qaysi qatordan (1 dan)'), 'soni' => $int('Nechta qator (standart 400)')], ['yol'])],
        ['name' => 'qidirish', 'description' => "Kod ichidan matn qidiradi (katta-kichik harf farqsiz). Natija: fayl:qator: matn.", 'inputSchema' => $s(['matn' => $str('Qidiriladigan matn'), 'yol' => $str('Qaysi papkada (ixtiyoriy)')], ['matn'])],
        ['name' => 'yozish', 'description' => "Faylni to'liq yozadi yoki yangi fayl yaratadi. Avval zaxira olinadi; PHP sintaksisi xato bo'lsa yozilmaydi. Kichik o'zgarish uchun 'almashtirish' qulayroq.", 'inputSchema' => $s(['yol' => $str('Fayl yo\'li'), 'matn' => $str('Faylning to\'liq yangi matni')], ['yol', 'matn'])],
        ['name' => 'almashtirish', 'description' => "Fayl ichidagi bir bo'lakni boshqasiga almashtiradi. 'eski' faylda aynan bir marta uchrashi kerak. Zaxira olinadi, PHP sintaksisi tekshiriladi.", 'inputSchema' => $s(['yol' => $str('Fayl yo\'li'), 'eski' => $str('Hozirgi matn (aniq nusxa)'), 'yangi' => $str('Yangi matn')], ['yol', 'eski', 'yangi'])],
        ['name' => 'zaxiralar', 'description' => "MCP orqali o'zgartirilgan fayllarning zaxiralari (eng yangilari tepada).", 'inputSchema' => $s(['yol' => $str('Faqat shu fayl uchun (ixtiyoriy)')])],
        ['name' => 'qaytarish', 'description' => "Faylni zaxiradan tiklaydi. 'zaxira' — zaxiralar ro'yxatidagi nom.", 'inputSchema' => $s(['zaxira' => $str('Zaxira nomi')], ['zaxira'])],
        ['name' => 'sql', 'description' => "Bazadan FAQAT O'QISH: bitta SELECT / SHOW / DESCRIBE / EXPLAIN so'rovi (200 qatorgacha). Parol va kalitlar yashiriladi.", 'inputSchema' => $s(['sorov' => $str('SQL so\'rovi')], ['sorov'])],
        ['name' => 'jurnal', 'description' => "Xato va ish jurnallari. 'kanal' bo'sh bo'lsa — kanallar ro'yxati. Masalan: php, telegram, sync, mcp.", 'inputSchema' => $s(['kanal' => $str('Jurnal kanali'), 'soni' => $int('Oxirgi nechta qator (standart 80)')])],
        ['name' => 'sayt_sinov', 'description' => "Saytning ichki sahifasini ochib ko'radi (GET): HTTP kodi va matn boshi. O'zgarishdan keyin tekshirish uchun. Masalan: menu/ yoki api/?r=ping", 'inputSchema' => $s(['yol' => $str('Sayt ichidagi yo\'l')], ['yol'])],
        ['name' => 'bot_sinov', 'description' => 'Telegram bot holati: getMe va webhook ma\'lumoti (oxirgi xato bilan).', 'inputSchema' => $s()],
    ];
}

function mcp_call(string $name, array $a): string
{
    $j = static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    switch ($name) {
        case 'holat':
            $today = date('Y-m-d');
            $sh = row("SELECT number, opened_at FROM shifts WHERE status = 'open' ORDER BY id DESC LIMIT 1");
            $diag = diag_run();
            $bad = array_values(array_filter($diag, static fn($d) => in_array($d['status'], ['error', 'warn'], true)));
            return $j([
                'versiya' => ADI_VERSION, 'php' => PHP_VERSION, 'vaqt' => date('c'), 'sayt' => app_url(),
                'bugun' => [
                    'buyurtmalar' => (int)val("SELECT COUNT(*) FROM orders WHERE status = 'done' AND DATE(closed_at) = ?", [$today]),
                    'savdo' => (int)val("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'done' AND DATE(closed_at) = ?", [$today]),
                    'ochiq_hisoblar' => (int)val("SELECT COUNT(*) FROM orders WHERE status = 'accepted'"),
                    'yangi_telegram' => (int)val("SELECT COUNT(*) FROM orders WHERE source = 'telegram' AND status = 'new'"),
                ],
                'smena' => $sh ? ('#' . $sh['number'] . ' ochiq, ' . $sh['opened_at']) : 'yopiq',
                'kassalar' => rows('SELECT name, app_version, last_seen_at FROM devices WHERE is_active = 1 ORDER BY id'),
                'muammolar' => array_map(static fn($d) => ['kod' => $d['code'], 'nomi' => $d['title'], 'holat' => $d['status'], 'xabar' => $d['message']], $bad),
            ]);

        case 'diagnostika':
            return $j(array_map(static fn($d) => ['kod' => $d['code'], 'nomi' => $d['title'], 'holat' => $d['status'], 'xabar' => $d['message'], 'tavsiya' => $d['advice'] ?? ''], diag_run()));

        case 'tuzatish':
            return diag_fix(preg_replace('~[^a-z_]~', '', (string)($a['kod'] ?? '')));

        case 'fayllar':
            [$rel, $abs] = mcp_path((string)($a['yol'] ?? ''));
            if (!is_dir($abs)) {
                throw new UserError('Bu papka emas: ' . $rel);
            }
            $out = [];
            foreach (scandir($abs) ?: [] as $f) {
                if ($f === '.' || $f === '..' || $f === '.git') {
                    continue;
                }
                $p = $abs . '/' . $f;
                $out[] = is_dir($p) ? "[papka] $f/" : sprintf('%-40s %8d bayt  %s', $f, filesize($p), date('Y-m-d H:i', (int)filemtime($p)));
            }
            return ($rel === '' ? '(loyiha ildizi)' : $rel) . "\n" . implode("\n", $out);

        case 'oqish':
            [$rel, $abs] = mcp_path((string)($a['yol'] ?? ''));
            if (!is_file($abs)) {
                throw new UserError('Fayl topilmadi: ' . $rel);
            }
            if (filesize($abs) > 3000000) {
                throw new UserError('Fayl juda katta.');
            }
            $raw = (string)file_get_contents($abs);
            if (str_contains(substr($raw, 0, 4000), "\0")) {
                return "$rel — ikkilik fayl (" . strlen($raw) . ' bayt), matn sifatida ko\'rsatilmaydi.';
            }
            $lines = explode("\n", $raw);
            $from = max(1, (int)($a['boshi'] ?? 1));
            $cnt = max(1, min(1500, (int)($a['soni'] ?? 400)));
            $part = array_slice($lines, $from - 1, $cnt);
            $txt = '';
            foreach ($part as $i => $ln) {
                $txt .= ($from + $i) . "\t" . $ln . "\n";
            }
            return "$rel — jami " . count($lines) . " qator, ko'rsatilgan: {$from}–" . ($from + count($part) - 1) . "\n" . $txt;

        case 'qidirish':
            $needle = (string)($a['matn'] ?? '');
            if (mb_strlen($needle) < 2) {
                throw new UserError('Kamida 2 ta belgi yozing.');
            }
            [$rel, $abs] = mcp_path((string)($a['yol'] ?? ''));
            $hits = [];
            $it = is_file($abs) ? [new SplFileInfo($abs)] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = str_replace('\\', '/', $f->getPathname());
                $r = ltrim(substr($p, strlen(ROOT)), '/');
                if (!$f->isFile() || $f->getSize() > 800000 || !preg_match('~\.(php|js|css|html|sql|md|json|bat|go|txt|htaccess)$~i', $p)) {
                    continue;
                }
                foreach (array_merge(MCP_DENY, ['storage/']) as $d) {
                    if (stripos($r, $d) === 0) {
                        continue 2;
                    }
                }
                foreach (explode("\n", (string)file_get_contents($p)) as $i => $ln) {
                    if (mb_stripos($ln, $needle) !== false) {
                        $hits[] = $r . ':' . ($i + 1) . ': ' . mb_substr(trim($ln), 0, 220);
                        if (count($hits) >= 120) {
                            break 2;
                        }
                    }
                }
            }
            return $hits ? implode("\n", $hits) . (count($hits) >= 120 ? "\n… (natija ko'p, aniqroq qidiring)" : '') : 'Hech narsa topilmadi.';

        case 'yozish':
            [$rel, $abs] = mcp_path((string)($a['yol'] ?? ''), false);
            if ($rel === '' || is_dir($abs)) {
                throw new UserError('Fayl yo\'lini kiriting.');
            }
            $bak = mcp_write_file($rel, $abs, (string)($a['matn'] ?? ''));
            audit('mcp.write', 'file', $rel, ['backup' => $bak]);
            return "Yozildi: $rel (" . strlen((string)($a['matn'] ?? '')) . " bayt). Zaxira: $bak";

        case 'almashtirish':
            [$rel, $abs] = mcp_path((string)($a['yol'] ?? ''));
            if (!is_file($abs)) {
                throw new UserError('Fayl topilmadi: ' . $rel);
            }
            $old = (string)($a['eski'] ?? '');
            $src = (string)file_get_contents($abs);
            $n = $old === '' ? 0 : substr_count($src, $old);
            if ($n !== 1) {
                throw new UserError($n === 0 ? "«eski» matn faylda topilmadi (bo'sh joy va qator ko'chishlarini tekshiring)." : "«eski» matn $n marta uchraydi — ko'proq atrof matni bilan aniqlashtiring.");
            }
            $pos = strpos($src, $old);
            $new = substr($src, 0, $pos) . (string)($a['yangi'] ?? '') . substr($src, $pos + strlen($old));
            $bak = mcp_write_file($rel, $abs, $new);
            audit('mcp.replace', 'file', $rel, ['backup' => $bak]);
            return "O'zgartirildi: $rel. Zaxira: $bak";

        case 'zaxiralar':
            $only = str_replace('/', '~', ltrim((string)($a['yol'] ?? ''), '/'));
            $all = glob(ROOT . '/storage/mcp/*.bak') ?: [];
            rsort($all);
            $out = [];
            foreach ($all as $f) {
                $b = basename($f);
                if ($only !== '' && !str_contains($b, '__' . $only . '.bak')) {
                    continue;
                }
                $out[] = $b . '  →  ' . str_replace('~', '/', substr($b, strpos($b, '__') + 2, -4));
                if (count($out) >= 60) {
                    break;
                }
            }
            return $out ? implode("\n", $out) : 'Zaxira yo\'q.';

        case 'qaytarish':
            $b = basename((string)($a['zaxira'] ?? ''));
            $f = ROOT . '/storage/mcp/' . $b;
            if (!preg_match('~^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}__.+\.bak$~', $b) || !is_file($f)) {
                throw new UserError('Zaxira topilmadi: ' . $b);
            }
            [$rel, $abs] = mcp_path(str_replace('~', '/', substr($b, strpos($b, '__') + 2, -4)), false);
            $data = (string)file_get_contents($f);
            if ($data === "\0YANGI_FAYL\0") {
                mcp_backup_file($rel, $abs);
                @unlink($abs);
                audit('mcp.restore', 'file', $rel, ['removed' => true]);
                return "Fayl o'chirildi (u MCP yaratgan yangi fayl edi): $rel";
            }
            $bak = mcp_write_file($rel, $abs, $data);
            audit('mcp.restore', 'file', $rel, ['from' => $b]);
            return "Tiklandi: $rel ← $b. Hozirgi holat zaxirasi: $bak";

        case 'sql':
            $sql = trim((string)($a['sorov'] ?? ''));
            $sql = rtrim($sql, "; \n\t");
            if (!preg_match('~^(select|show|describe|desc|explain)\b~i', $sql) || str_contains($sql, ';') || preg_match('~\b(into\s+(outfile|dumpfile)|load_file|sleep\s*\(|benchmark\s*\()~i', $sql)) {
                throw new UserError("Faqat bitta SELECT / SHOW / DESCRIBE / EXPLAIN so'rovi mumkin. Bazani o'zgartirish MCP orqali yopiq.");
            }
            $st = db()->query($sql);
            $out = [];
            while (($r = $st->fetch()) !== false && count($out) < 200) {
                foreach ($r as $k => $v) {
                    if ($v !== null && preg_match('~hash|salt|password|token|secret|_key$~i', (string)$k)) {
                        $r[$k] = '***';
                    }
                }
                if (isset($r['k'], $r['v']) && preg_match('~token|secret|key|hash|password~i', (string)$r['k'])) {
                    $r['v'] = '***';
                }
                $out[] = $r;
            }
            return count($out) . " qator\n" . $j($out);

        case 'jurnal':
            $dir = ROOT . '/storage/logs';
            $ch = preg_replace('~[^a-z0-9_]~', '', strtolower((string)($a['kanal'] ?? '')));
            if ($ch === '') {
                $names = [];
                foreach (glob($dir . '/*.log.php') ?: [] as $f) {
                    $names[preg_replace('~-\d{8}\.log\.php$~', '', basename($f))] = date('Y-m-d H:i', (int)filemtime($f));
                }
                arsort($names);
                return $names ? "Kanallar (oxirgi yozuv vaqti):\n" . implode("\n", array_map(static fn($k, $v) => "$k  ($v)", array_keys($names), $names)) : 'Jurnal bo\'sh.';
            }
            $files = glob($dir . '/' . $ch . '-*.log.php') ?: [];
            if (!$files) {
                return "«$ch» jurnali bo'sh.";
            }
            sort($files);
            $lines = array_slice(explode("\n", trim((string)file_get_contents(end($files)))), 1);
            return basename(end($files)) . "\n" . implode("\n", array_slice($lines, -max(1, min(400, (int)($a['soni'] ?? 80)))));

        case 'sayt_sinov':
            $path = ltrim((string)($a['yol'] ?? ''), '/');
            if (preg_match('~^[a-z]+://~i', $path) || str_contains($path, '..')) {
                throw new UserError('Faqat shu sayt ichidagi yo\'l mumkin.');
            }
            $base = app_url();
            if ($base === '') {
                $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
            }
            $url = rtrim($base, '/') . '/' . $path;
            $chh = curl_init($url);
            curl_setopt_array($chh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false]);
            $body = (string)curl_exec($chh);
            $code = (int)curl_getinfo($chh, CURLINFO_HTTP_CODE);
            $err = curl_error($chh);
            $loc = (string)curl_getinfo($chh, CURLINFO_REDIRECT_URL);
            curl_close($chh);
            $bad = preg_match('~Fatal error|Parse error|Warning:|Notice:|Xato yuz berdi~', $body) ? "\n⚠ Sahifada xato matni bor!" : '';
            return "GET $url\nHTTP $code" . ($err ? " ($err)" : '') . ($loc ? "\nYo'naltirish: $loc" : '') . ', ' . strlen($body) . " bayt$bad\n---\n" . mb_substr(trim(preg_replace('~\s+~u', ' ', strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', '', $body) ?? '')) ?? ''), 0, 1500);

        case 'bot_sinov':
            if (setting('bot_token') === '') {
                return 'Bot tokeni kiritilmagan (admin panel → Telegram bot).';
            }
            $me = tg_api('getMe');
            $wh = tg_api('getWebhookInfo');
            return $j(['bot' => $me ? ['username' => $me['username'] ?? '', 'name' => $me['first_name'] ?? ''] : ('XATO: ' . ($GLOBALS['TG_ERR'] ?? '')),
                'webhook' => $wh ? ['url' => preg_replace('~([?&](secret|token|k)=)[^&]+~', '$1***', (string)($wh['url'] ?? '')), 'kutayotgan' => $wh['pending_update_count'] ?? 0, 'oxirgi_xato' => $wh['last_error_message'] ?? '', 'xato_vaqti' => isset($wh['last_error_date']) ? date('Y-m-d H:i:s', (int)$wh['last_error_date']) : ''] : 'XATO']);
    }
    throw new UserError("Noma'lum vosita: $name");
}

/** Bitta JSON-RPC so'rovini bajaradi. Bildirishnoma (id yo'q) uchun null qaytaradi. */
function mcp_handle(array $req): ?array
{
    $id = $req['id'] ?? null;
    $method = (string)($req['method'] ?? '');
    $ok = static fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    if (!array_key_exists('id', $req)) {
        return null;
    }
    switch ($method) {
        case 'initialize':
            return $ok([
                'protocolVersion' => in_array($req['params']['protocolVersion'] ?? '', ['2024-11-05', '2025-03-26', '2025-06-18'], true) ? $req['params']['protocolVersion'] : MCP_PROTOCOL,
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'adi-burger', 'version' => ADI_VERSION],
                'instructions' => "ADI BURGER 2.0 (kassa, Telegram bot, admin panel, QR menyu) — hostingdagi jonli tizim. Avval 'holat' ni chaqiring. Kodni o'zgartirishdan oldin 'oqish' bilan o'qing; 'yozish'/'almashtirish' har safar zaxira oladi va PHP sintaksisini tekshiradi, muammo bo'lsa 'qaytarish'. O'zgarishdan keyin 'sayt_sinov' va 'jurnal' (php) bilan tekshiring. Baza faqat o'qiladi. Tizim ishlab turibdi — ehtiyot bo'ling, katta o'zgarishdan oldin egasidan so'rang.",
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            return $ok(['tools' => mcp_tools()]);
        case 'tools/call':
            $name = (string)($req['params']['name'] ?? '');
            $args = is_array($req['params']['arguments'] ?? null) ? $req['params']['arguments'] : [];
            try {
                $text = mcp_call($name, $args);
                mcp_log("OK $name " . mb_substr(json_encode(array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 80) : $v, $args), JSON_UNESCAPED_UNICODE), 0, 300));
                return $ok(['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
            } catch (Throwable $e) {
                mcp_log("ERR $name " . $e->getMessage());
                $msg = $e instanceof UserError ? $e->getMessage() : ('Ichki xato: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                return $ok(['content' => [['type' => 'text', 'text' => $msg]], 'isError' => true]);
            }
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
}
