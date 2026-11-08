<?php
declare(strict_types=1);
defined('ADI') || exit;

const PIN_ITERATIONS = 60000;

/**
 * PIN xeshi: PBKDF2-SHA256. Kassa (brauzer) ham internet bo'lmaganda xuddi shu
 * algoritm bilan WebCrypto orqali tekshiradi, shuning uchun parametrlar ikki tomonda bir xil.
 */
function pin_hash(string $pin, string $salt): string
{
    return hash_pbkdf2('sha256', $pin, $salt, PIN_ITERATIONS, 64);
}

function pin_valid_format(string $pin): bool
{
    return (bool)preg_match('~^\d{4}$~', $pin);
}

function pin_make(string $pin): array
{
    $salt = bin2hex(random_bytes(16));
    return [$salt, pin_hash($pin, $salt)];
}

/** Bir xil PIN ikki xodimda bo'lmasligi kerak (kassada PIN bo'yicha kim kirganini aniqlaymiz). */
function pin_taken(string $pin, int $exceptId = 0): bool
{
    foreach (rows('SELECT id, pin_salt, pin_hash FROM employees WHERE is_active = 1 AND id <> ?', [$exceptId]) as $e) {
        if (hash_equals($e['pin_hash'], pin_hash($pin, $e['pin_salt']))) {
            return true;
        }
    }
    return false;
}

// ---------- Admin panel sessiyasi ----------

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ADI_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (base_path() ?: '') . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    // 12 soat harakatsizlikdan keyin sessiya tugaydi
    if (isset($_SESSION['t']) && time() - (int)$_SESSION['t'] > 43200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['t'] = time();
}

function current_admin(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    $u = null;
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['uid'])) {
        return null;
    }
    $r = row('SELECT u.id AS user_id, u.username, e.id, e.name, e.role FROM users u JOIN employees e ON e.id = u.employee_id
              WHERE u.id = ? AND u.is_active = 1 AND e.is_active = 1', [(int)$_SESSION['uid']]);
    if ($r && ($_SESSION['ph'] ?? '') === admin_pw_fingerprint((int)$r['user_id'])) {
        $u = $r;
    }
    return $u;
}

/** Parol o'zgarsa, eski sessiyalar avtomatik bekor bo'ladi. */
function admin_pw_fingerprint(int $userId): string
{
    return substr(hash('sha256', (string)val('SELECT password_hash FROM users WHERE id = ?', [$userId])), 0, 16);
}

function admin_login(string $username, string $password): array
{
    $r = admin_verify($username, $password);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$r['id'];
    $_SESSION['ph'] = admin_pw_fingerprint((int)$r['id']);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$r['id']]);
    audit('admin.login', 'user', $r['id'], [], (int)$r['employee_id']);
    return $r;
}

/** Login/parolni tekshiradi (sessiyasiz). Faqat administrator. */
function admin_verify(string $username, string $password): array
{
    $key = 'login|' . client_ip();
    if (!rate_limit($key, 8, 600)) {
        throw new UserError("Juda ko'p urinish. 10 daqiqadan keyin qayta urinib ko'ring.");
    }
    $r = row('SELECT u.*, e.role, e.is_active AS emp_active FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.username = ?', [trim($username)]);
    if (!$r || !$r['is_active'] || !$r['emp_active'] || !password_verify($password, $r['password_hash'])) {
        audit('admin.login_fail', 'user', '', ['username' => mb_substr($username, 0, 50)], null);
        throw new UserError("Login yoki parol noto'g'ri.");
    }
    if ($r['role'] !== 'admin') {
        throw new UserError('Admin panelga faqat administrator kira oladi.');
    }
    if (password_needs_rehash($r['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $r['id']]);
    }
    rate_limit_reset($key);
    return $r;
}

function admin_logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(24);
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($t === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
        throw new UserError('Sahifa eskirgan (CSRF). Sahifani yangilab, qayta urinib ko\'ring.');
    }
}

// ---------- Kassa qurilmasi (POS) autentifikatsiyasi ----------

function device_token_from_request(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = (string)$v;
            }
        }
    }
    // Ba'zi hostinglar Authorization sarlavhasini o'chiradi — zaxira sarlavha
    if ($h === '') {
        $h = 'Bearer ' . (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
    }
    return preg_match('~^Bearer\s+([A-Za-z0-9_-]{20,})$~', trim($h), $m) ? $m[1] : '';
}

function current_device(): ?array
{
    static $d = false;
    if ($d !== false) {
        return $d;
    }
    $t = device_token_from_request();
    $d = $t === '' ? null : row('SELECT * FROM devices WHERE token_hash = ? AND is_active = 1', [hash('sha256', $t)]);
    if ($d) {
        // Har so'rovda emas, 30 soniyada bir marta yangilanadi
        if (!$d['last_seen_at'] || strtotime($d['last_seen_at']) < time() - 30) {
            q('UPDATE devices SET last_seen_at = NOW(), last_ip = ? WHERE id = ?', [client_ip(), $d['id']]);
        }
    }
    return $d;
}

function device_register(string $username, string $password, string $name): array
{
    $u = admin_verify($username, $password);
    $token = random_token(32);
    q('INSERT INTO devices (name, token_hash) VALUES (?, ?)', [str_limit($name ?: 'Kassa', 100), hash('sha256', $token)]);
    $id = (int)db()->lastInsertId();
    audit('device.register', 'device', $id, ['name' => $name], (int)$u['employee_id']);
    return ['id' => $id, 'token' => $token];
}
