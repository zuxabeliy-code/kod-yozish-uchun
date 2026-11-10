<?php
declare(strict_types=1);

/**
 * ADI BURGER 2.0 — umumiy ishga tushirish fayli.
 * Har bir kirish nuqtasi (api, admin, telegram, pos) shu faylni ulaydi.
 */

define('ADI', true);
define('ADI_VERSION', '2.2.3');
define('ROOT', __DIR__);

mb_internal_encoding('UTF-8');

$cfgFile = ROOT . '/config/config.php';
$GLOBALS['ADI_CFG'] = is_file($cfgFile) ? (require $cfgFile) : null;

date_default_timezone_set((string)($GLOBALS['ADI_CFG']['app']['tz'] ?? 'Asia/Tashkent'));

foreach (['helpers', 'log', 'db', 'settings', 'auth', 'orders', 'tg', 'hamyon', 'receipt_design', 'sync', 'reports', 'diag', 'backup', 'mcp'] as $lib) {
    require ROOT . '/app/' . $lib . '.php';
}

// Xatolar foydalanuvchiga emas, logga yoziladi
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// Faqat o'rnatilgan tizimda ishlaydi. Installer bu tekshiruvni chetlab o'tadi.
function require_installed(): void
{
    if (!$GLOBALS['ADI_CFG']) {
        if (PHP_SAPI !== 'cli') {
            header('Location: ' . base_path() . '/install/');
        }
        exit("Tizim hali o'rnatilmagan. /install/ sahifasini oching.\n");
    }
}
