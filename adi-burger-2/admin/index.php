<?php
declare(strict_types=1);

/**
 * ADI BURGER 2.0 — admin panel. Barcha sahifalar: /admin/?p=<sahifa>
 * Amallar faqat POST + CSRF bilan, keyin redirect (sahifa yangilansa amal takrorlanmaydi).
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();
security_headers();
header('Cache-Control: no-store');
session_boot();

require __DIR__ . '/inc/ui.php';
require __DIR__ . '/inc/actions.php';
require __DIR__ . '/inc/pages.php';

$page = preg_replace('~[^a-z_]~', '', (string)($_GET['p'] ?? 'dashboard')) ?: 'dashboard';

try {
    // Kirish
    if ($page === 'login') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            csrf_check();
            admin_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            redirect('dashboard');
        }
        page_login();
        exit;
    }
    if ($page === 'logout') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            csrf_check();
            admin_logout();
        }
        redirect('login');
    }
    $me = current_admin();
    if (!$me) {
        redirect('login');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        $action = preg_replace('~[^a-z_.]~', '', (string)($_POST['a'] ?? ''));
        handle_action($action, $me);
        exit;
    }
    // Fayl yuklab olish (GET, lekin faqat admin sessiyasi bilan)
    if ($page === 'download') {
        handle_download((string)($_GET['f'] ?? ''), $me);
        exit;
    }
    $fn = 'page_' . $page;
    if (!function_exists($fn) || !isset(ADMIN_MENU[$page]) && !in_array($page, ['order', 'product', 'shift_view', 'employee'], true)) {
        $fn = 'page_dashboard';
        $page = 'dashboard';
    }
    $fn($me);
} catch (UserError $e) {
    flash($e->getMessage(), 'bad');
    if ($page === 'login') {
        page_login();
        exit;
    }
    redirect_back();
} catch (Throwable $e) {
    $id = log_exception($e, 'admin');
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;background:#0b1220;color:#eee;padding:40px"><h2>Xato yuz berdi</h2><p>Xato ID: ' . e($id) . '</p><p>Diagnostika bo\'limida tafsilotni ko\'ring.</p><a style="color:#f5a524" href="?p=dashboard">Bosh sahifa</a></body>';
}
