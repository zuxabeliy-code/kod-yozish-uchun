<?php
/**
 * JARVIS API — Android ilova faqat shu manzil bilan gaplashadi.
 *
 *  GET  api/?a=ping   → server holati
 *  POST api/?a=chat   {message, history[]}      → {ok, reply}
 *  POST api/?a=stt    {audio(base64), mime}     → {ok, text}
 *  POST api/?a=tts    {text}                    → audio/wav
 *
 * Avtorizatsiya: "X-Jarvis-Token: <qurilma tokeni>" sarlavhasi (admin panelda yaratiladi).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/gemini.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function api_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function api_fail(int $status, string $code, string $message): void
{
    api_json($status, ['ok' => false, 'error' => $code, 'message' => $message]);
}

function api_log(int $deviceId, string $action, int $status, float $start, ?string $err = null): void
{
    try {
        $ms = (int)round((microtime(true) - $start) * 1000);
        jv_db()->prepare('INSERT INTO jv_requests (device_id, action, status, ms, err, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$deviceId, $action, $status, $ms, $err !== null ? mb_substr($err, 0, 250) : null, jv_now()]);
        if (random_int(1, 200) === 1) {
            jv_db()->exec("DELETE FROM jv_requests WHERE created_at < (NOW() - INTERVAL 30 DAY)");
            jv_db()->exec("DELETE FROM jv_login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Throwable $e) {
        error_log('JARVIS log xatosi: ' . $e->getMessage());
    }
}

if (!jv_installed()) {
    api_fail(503, 'not_installed', 'Server hali o\'rnatilmagan');
    exit;
}

$action = (string)($_GET['a'] ?? '');
$start = microtime(true);

// --- Avtorizatsiya ---
$token = (string)($_SERVER['HTTP_X_JARVIS_TOKEN'] ?? '');
if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $token = $m[1];
}
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    api_fail(401, 'no_token', 'Qurilma tokeni yo\'q yoki noto\'g\'ri');
    exit;
}

try {
    $st = jv_db()->prepare('SELECT id, name, active FROM jv_devices WHERE token_hash = ?');
    $st->execute([jv_token_hash($token)]);
    $device = $st->fetch();
} catch (Throwable $e) {
    error_log('JARVIS DB xatosi: ' . $e->getMessage());
    api_fail(500, 'db_error', 'Server bazasi bilan muammo');
    exit;
}
if (!$device || !(int)$device['active']) {
    api_fail(401, 'bad_token', 'Token noto\'g\'ri yoki o\'chirilgan');
    exit;
}
$deviceId = (int)$device['id'];
jv_db()->prepare('UPDATE jv_devices SET last_seen_at = ? WHERE id = ?')->execute([jv_now(), $deviceId]);

if ($action === 'ping') {
    api_json(200, ['ok' => true, 'server' => 'JARVIS', 'version' => JV_VERSION, 'device' => $device['name']]);
    exit;
}

if (!in_array($action, ['chat', 'stt', 'tts'], true)) {
    api_fail(404, 'unknown_action', 'Noma\'lum amal');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_fail(405, 'method', 'Faqat POST');
    exit;
}

// --- Limitlar ---
$perMin = max(1, (int)jv_setting('limit_per_min'));
$perDay = max(1, (int)jv_setting('limit_per_day'));
$st = jv_db()->prepare('SELECT
        SUM(created_at >= (NOW() - INTERVAL 1 MINUTE)) AS m,
        COUNT(*) AS d
    FROM jv_requests WHERE device_id = ? AND created_at >= (NOW() - INTERVAL 1 DAY)');
$st->execute([$deviceId]);
$usage = $st->fetch() ?: ['m' => 0, 'd' => 0];
if ((int)$usage['m'] >= $perMin) {
    api_fail(429, 'rate_minute', 'Juda ko\'p so\'rov. Bir daqiqa kuting.');
    exit;
}
if ((int)$usage['d'] >= $perDay) {
    api_fail(429, 'rate_day', 'Kunlik limit tugadi.');
    exit;
}

// --- So'rov tanasi ---
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > 4 * 1024 * 1024) {
    api_fail(413, 'too_large', 'So\'rov juda katta');
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    api_fail(400, 'bad_json', 'JSON noto\'g\'ri');
    exit;
}

$cfg = jv_config();
$status = 200;
$err = null;
try {
    $gemini = new GeminiClient((string)($cfg['gemini_api_key'] ?? ''), (string)($cfg['gemini_base'] ?? 'https://generativelanguage.googleapis.com/v1beta'));

    switch ($action) {
        case 'chat':
            $message = trim((string)($in['message'] ?? ''));
            if ($message === '' || mb_strlen($message) > 2000) {
                $status = 400;
                api_fail(400, 'bad_message', 'Xabar bo\'sh yoki juda uzun');
                break;
            }
            $history = [];
            foreach (array_slice(is_array($in['history'] ?? null) ? $in['history'] : [], -20) as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $role = ($t['role'] ?? '') === 'model' ? 'model' : 'user';
                $text = mb_substr(trim((string)($t['text'] ?? '')), 0, 2000);
                if ($text !== '') {
                    $history[] = ['role' => $role, 'text' => $text];
                }
            }
            $system = str_replace('{user}', jv_setting('user_name'), jv_setting('system_prompt'));
            $system .= "\nHozirgi vaqt: " . date('Y-m-d H:i') . ' (Toshkent).';
            $reply = $gemini->chat(jv_setting('chat_model'), $system, $history, $message);
            api_json(200, ['ok' => true, 'reply' => $reply]);
            break;

        case 'stt':
            $audio = base64_decode((string)($in['audio'] ?? ''), true);
            $mime = (string)($in['mime'] ?? 'audio/wav');
            if ($audio === false || strlen($audio) < 1000 || strlen($audio) > 3 * 1024 * 1024) {
                $status = 400;
                api_fail(400, 'bad_audio', 'Audio noto\'g\'ri yoki juda katta');
                break;
            }
            if (!in_array($mime, ['audio/wav', 'audio/x-wav'], true)) {
                $status = 400;
                api_fail(400, 'bad_mime', 'Faqat audio/wav qabul qilinadi');
                break;
            }
            $text = $gemini->transcribe(jv_setting('stt_model'), $audio, 'audio/wav');
            api_json(200, ['ok' => true, 'text' => $text]);
            break;

        case 'tts':
            $text = trim((string)($in['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 1500) {
                $status = 400;
                api_fail(400, 'bad_text', 'Matn bo\'sh yoki juda uzun');
                break;
            }
            $wav = $gemini->speak(jv_setting('tts_model'), jv_setting('tts_voice'), $text);
            http_response_code(200);
            header('Content-Type: audio/wav');
            header('Content-Length: ' . strlen($wav));
            echo $wav;
            break;
    }
} catch (GeminiException $e) {
    $status = ($e->httpCode === 429) ? 429 : 502;
    $err = $e->getMessage();
    api_fail($status, $e->httpCode === 429 ? 'gemini_quota' : 'gemini_error',
        $e->httpCode === 429 ? 'Gemini kvotasi tugadi, keyinroq urinib ko\'ring' : $e->getMessage());
} catch (Throwable $e) {
    $status = 500;
    $err = $e->getMessage();
    error_log('JARVIS API xatosi: ' . $e->getMessage());
    api_fail(500, 'server_error', 'Server xatosi');
}

api_log($deviceId, $action, $status, $start, $err);
