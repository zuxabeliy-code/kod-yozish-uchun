<?php
declare(strict_types=1);
defined('ADI') || exit;

/** Foydalanuvchiga ko'rsatiladigan (texnik bo'lmagan) xato. */
final class UserError extends RuntimeException
{
}

function cfg(string $path, mixed $default = null): mixed
{
    $cur = $GLOBALS['ADI_CFG'] ?? null;
    foreach (explode('.', $path) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return $cur;
}

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Sayt ildiz yo'li: "/" da o'rnatilgan bo'lsa "", papkada bo'lsa "/papka". */
function base_path(): string
{
    $url = (string)cfg('app.url', '');
    if ($url !== '') {
        return rtrim((string)parse_url($url, PHP_URL_PATH), '/');
    }
    $doc = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    $root = realpath(ROOT) ?: ROOT;
    if ($doc !== '' && str_starts_with($root, $doc)) {
        return rtrim(str_replace('\\', '/', substr($root, strlen($doc))), '/');
    }
    return '';
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)cfg('app.url', ''), '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 2_000_000) {
        throw new UserError("So'rov juda katta");
    }
    $d = $raw === '' ? [] : json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function money(int|float|string $sum): string
{
    return number_format((int)$sum, 0, '.', ' ') . " so'm";
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function is_uuid(mixed $s): bool
{
    return is_string($s) && (bool)preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $s);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

function str_limit(mixed $s, int $len): string
{
    return mb_substr(trim((string)$s), 0, $len);
}

/** Telefon raqamini +998XXXXXXXXX ko'rinishiga keltiradi. Noto'g'ri bo'lsa ''. */
function normalize_phone(string $p): string
{
    $d = preg_replace('~\D~', '', $p) ?? '';
    if (strlen($d) === 9) {
        $d = '998' . $d;
    }
    return preg_match('~^998\d{9}$~', $d) ? '+' . $d : '';
}

/** ISO yoki "Y-m-d H:i:s" vaqtni MySQL DATETIME ko'rinishiga o'tkazadi. */
function to_dt(mixed $s): ?string
{
    if (!is_string($s) || $s === '') {
        return null;
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Oddiy fayl asosidagi urinishlar cheklovi (login, PIN, webapp). */
function rate_limit(string $key, int $max, int $windowSec): bool
{
    $dir = ROOT . '/storage/cache';
    $f = $dir . '/rl_' . hash('sha256', $key) . '.json';
    $now = time();
    $d = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $d = array_values(array_filter($d, fn($t) => $t > $now - $windowSec));
    if (count($d) >= $max) {
        return false;
    }
    $d[] = $now;
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return true;
}

function rate_limit_reset(string $key): void
{
    @unlink(ROOT . '/storage/cache/rl_' . hash('sha256', $key) . '.json');
}

function security_headers(bool $html = true): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if ($html) {
        header('X-Frame-Options: SAMEORIGIN');
    }
}
