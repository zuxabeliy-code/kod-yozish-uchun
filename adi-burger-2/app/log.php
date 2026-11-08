<?php
declare(strict_types=1);
defined('ADI') || exit;

/** Maxfiy ma'lumotlarni (tokenlar, parollar, kalitlar) yashiradi. */
function redact(mixed $data): mixed
{
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = (is_string($k) && preg_match('~pass|token|secret|key|pin|hash|sign|authorization~i', $k)) ? '***' : redact($v);
        }
        return $out;
    }
    if (is_string($data)) {
        $data = preg_replace('~\d{6,12}:[A-Za-z0-9_-]{30,}~', '***BOT_TOKEN***', $data) ?? $data;
        $data = preg_replace('~(bot)\d{6,12}:[^/\s]+~', '$1***', $data) ?? $data;
        $data = preg_replace('~(Bearer\s+)[A-Za-z0-9._-]+~i', '$1***', $data) ?? $data;
        $data = preg_replace('~\b(\d{4})[ -]?\d{4}[ -]?\d{4}[ -]?(\d{4})\b~', '$1 **** **** $2', $data) ?? $data;
    }
    return $data;
}

/**
 * Fayl logi. Fayl nomi .php bilan tugaydi va birinchi qatori exit — shuning uchun
 * server .htaccess ni o'qimasa ham brauzerda ochilmaydi.
 */
function log_msg(string $channel, mixed $msg): void
{
    $channel = preg_replace('~[^a-z0-9_]~', '', strtolower($channel)) ?: 'app';
    $dir = ROOT . '/storage/logs';
    $f = $dir . '/' . $channel . '-' . date('Ymd') . '.log.php';
    $line = date('Y-m-d H:i:s') . ' ' . (is_string($msg) ? redact($msg) : json_encode(redact($msg), JSON_UNESCAPED_UNICODE)) . "\n";
    if (!is_file($f)) {
        $line = "<?php exit; ?>\n" . $line;
    }
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Diagnostika uchun xatolar tarixi (error_logs jadvali).
 * Bir xil xato 10 daqiqada takrorlansa, yangi qator yaratilmaydi, hisoblagich oshadi.
 */
function record_error(string $component, string $code, string $message, mixed $details = null, string $advice = ''): void
{
    log_msg('errors', "[$component/$code] $message " . (is_string($details) ? $details : json_encode(redact($details), JSON_UNESCAPED_UNICODE)));
    try {
        if (!db_ready()) {
            return;
        }
        $det = $details === null ? null : mb_substr(is_string($details) ? (string)redact($details) : (string)json_encode(redact($details), JSON_UNESCAPED_UNICODE), 0, 4000);
        $upd = q("UPDATE error_logs SET occurrences = occurrences + 1, last_seen_at = NOW(), details = ?
                  WHERE component = ? AND code = ? AND fix_status = 'open' AND last_seen_at > NOW() - INTERVAL 10 MINUTE", [$det, $component, $code])->rowCount();
        if (!$upd) {
            q('INSERT INTO error_logs (component, code, message, details, advice) VALUES (?, ?, ?, ?, ?)',
                [$component, $code, mb_substr($message, 0, 500), $det, mb_substr($advice, 0, 1000)]);
        }
    } catch (Throwable) {
        // Xatoni yozishdagi xato tizimni to'xtatmasligi kerak
    }
}

/** Kutilmagan istisno: logga yoziladi, foydalanuvchiga umumiy xabar. */
function log_exception(Throwable $ex, string $component = 'php'): string
{
    $id = substr(bin2hex(random_bytes(4)), 0, 8);
    record_error($component, 'EXCEPTION', get_class($ex) . ': ' . $ex->getMessage(),
        ['id' => $id, 'file' => basename($ex->getFile()) . ':' . $ex->getLine(), 'trace' => mb_substr($ex->getTraceAsString(), 0, 2000)],
        'Texnik xato. Xato ID: ' . $id . '. Diagnostika bo\'limida tafsilotni ko\'ring.');
    return $id;
}
