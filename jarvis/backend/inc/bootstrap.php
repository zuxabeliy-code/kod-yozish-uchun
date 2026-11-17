<?php
/**
 * JARVIS backend — umumiy yadro: konfiguratsiya, baza, sozlamalar, yordamchi funksiyalar.
 * Bu papka .htaccess orqali brauzerdan yopiq.
 */
declare(strict_types=1);

define('JV_VERSION', '1.0.0');
define('JV_INC', __DIR__);

// Xatolarni foydalanuvchiga ko'rsatmaymiz, faqat logga yozamiz.
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tashkent');

/** Standart sozlamalar (admin paneldan o'zgartiriladi). */
const JV_DEFAULT_SETTINGS = [
    'chat_model'     => 'gemini-3.8-flash',
    'stt_model'      => 'gemini-3.8-flash',
    'tts_model'      => 'gemini-3.8-flash-tts',
    'tts_voice'      => 'Kore',
    'user_name'      => 'Zuxa',
    'limit_per_min'  => '20',
    'limit_per_day'  => '500',
    'system_prompt'  => "Sen JARVIS — {user}ning shaxsiy sun'iy intellekt yordamchisisan.\n"
        . "Qoidalar:\n"
        . "- Faqat o'zbek tilida, lotin alifbosida javob ber.\n"
        . "- Javobing ovoz bilan o'qiladi: 1–3 ta qisqa, tabiiy gap yoz.\n"
        . "- Markdown, ro'yxat, jadval, emoji va maxsus belgilar ishlatma.\n"
        . "- Foydalanuvchiga '{user}' yoki 'jigar' deb murojaat qilishing mumkin.\n"
        . "- Bilmagan narsangni o'ylab topma, ochiq ayt.\n"
        . "- Telefon funksiyalarini (qo'ng'iroq, SMS, ilovalar) o'zing bajarolmaysan; bunday buyruqlarni ilova o'zi bajaradi.",
];

function jv_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $file = JV_INC . '/config.php';
        $cfg = is_file($file) ? require $file : null;
        if (!is_array($cfg)) {
            $cfg = null;
        }
    }
    return $cfg;
}

function jv_installed(): bool
{
    return is_file(JV_INC . '/installed.lock') && jv_config() !== null;
}

function jv_pdo(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)$c['db_port'], $c['db_name']);
    return new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
}

function jv_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = jv_config();
        if ($c === null) {
            throw new RuntimeException('Config topilmadi');
        }
        $pdo = jv_pdo($c);
    }
    return $pdo;
}

function jv_settings(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = JV_DEFAULT_SETTINGS;
        foreach (jv_db()->query('SELECT k, v FROM jv_settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache;
}

function jv_setting(string $key): string
{
    return (string)(jv_settings()[$key] ?? '');
}

function jv_set_setting(string $key, string $value): void
{
    $st = jv_db()->prepare('INSERT INTO jv_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    $st->execute([$key, $value]);
    jv_settings(true);
}

/** Konfiguratsiyani xavfsiz yozish (faqat installer va admin). */
function jv_write_config(array $cfg): bool
{
    $file = JV_INC . '/config.php';
    $code = "<?php\n// Avtomatik yaratilgan. Bu fayl maxfiy: brauzerdan ochilmaydi.\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $code, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    return true;
}

function jv_schema_sql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS jv_admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            pass_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jv_devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jv_settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jv_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id INT UNSIGNED NOT NULL,
            action VARCHAR(16) NOT NULL,
            status SMALLINT NOT NULL,
            ms INT UNSIGNED NOT NULL DEFAULT 0,
            err VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_dev_time (device_id, created_at),
            KEY idx_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS jv_login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ip_time (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function jv_now(): string
{
    return date('Y-m-d H:i:s');
}

function jv_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jv_client_ip(): string
{
    // Proksi sarlavhalariga ishonmaymiz (soxtalashtirish oson).
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function jv_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function jv_new_token(): string
{
    return bin2hex(random_bytes(32));
}

function jv_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function jv_mask(string $secret): string
{
    $len = strlen($secret);
    return $len <= 4 ? '****' : str_repeat('•', 8) . substr($secret, -4);
}

/** Admin va installer uchun xavfsiz sessiya. */
function jv_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('JVSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => jv_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function jv_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function jv_csrf_check(): bool
{
    $sent = (string)($_POST['csrf'] ?? '');
    return $sent !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $sent);
}
