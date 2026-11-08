<?php
declare(strict_types=1);
defined('ADI') || exit;

const ADMIN_MENU = [
    'dashboard' => ['📊', 'Dashboard'],
    'orders' => ['🧾', 'Buyurtmalar'],
    'products' => ['🍔', 'Mahsulotlar'],
    'categories' => ['🗂', 'Kategoriyalar'],
    'tables' => ['🍽', 'Stollar'],
    'employees' => ['👤', 'Kassir va administrator'],
    'shifts' => ['🕘', 'Smenalar'],
    'reports' => ['📈', 'Savdo hisobotlari'],
    'payments' => ['💳', "To'lovlar"],
    'telegram' => ['✈️', 'Telegram bot'],
    'pos' => ['🖨', 'POS va printer'],
    'diagnostics' => ['🩺', 'Diagnostika va ta\'mirlash'],
    'backup' => ['💾', 'Zaxira'],
];

function url(string $page, array $q = []): string
{
    return '?' . http_build_query(['p' => $page] + $q);
}

function redirect(string $page, array $q = []): never
{
    header('Location: ' . url($page, $q));
    exit;
}

function redirect_back(): never
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)parse_url(app_url(), PHP_URL_HOST);
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host && str_contains($ref, '/admin/')) {
        header('Location: ' . $ref);
        exit;
    }
    redirect('dashboard');
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** POST forma ochuvchi teg (amal nomi bilan). */
function form_open(string $action, array $extra = [], string $attrs = ''): string
{
    $h = '<form method="post" action="?p=' . e($_GET['p'] ?? 'dashboard') . '" ' . $attrs . '>' . csrf_field() . '<input type="hidden" name="a" value="' . e($action) . '">';
    foreach ($extra as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h;
}

/** Bitta tugmali forma (tasdiq so'rash bilan). */
function action_btn(string $action, array $data, string $label, string $cls = 'btn', string $confirm = ''): string
{
    return form_open($action, $data, 'class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '')) . '<button class="' . e($cls) . '">' . $label . '</button></form>';
}

function field(string $label, string $name, mixed $value = '', string $type = 'text', string $attrs = '', string $hint = ''): string
{
    $id = 'f_' . preg_replace('~\W~', '_', $name);
    if ($type === 'textarea') {
        $input = '<textarea id="' . $id . '" name="' . e($name) . '" ' . $attrs . '>' . e($value) . '</textarea>';
    } elseif ($type === 'checkbox') {
        return '<label class="check"><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1" ' . ($value ? 'checked' : '') . ' ' . $attrs . '> ' . e($label) . '</label>' . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '');
    } else {
        $input = '<input id="' . $id . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '" ' . $attrs . '>';
    }
    return '<div class="field"><label for="' . $id . '">' . e($label) . '</label>' . $input . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . '</div>';
}

function select_field(string $label, string $name, array $options, mixed $value): string
{
    $o = '';
    foreach ($options as $k => $v) {
        $o .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return '<div class="field"><label>' . e($label) . '</label><select name="' . e($name) . '">' . $o . '</select></div>';
}

function badge(string $status): string
{
    $map = [
        'ok' => ['green', 'Ishlayapti'], 'warn' => ['yellow', 'Ogohlantirish'], 'error' => ['red', 'Xato'], 'unchecked' => ['gray', 'Tekshirilmagan'],
        'new' => ['blue', 'Yangi'], 'pending_pay' => ['gray', "To'lov kutilmoqda"], 'accepted' => ['yellow', 'Qabul qilingan'], 'ready' => ['violet', 'Tayyor'],
        'done' => ['green', 'Yakunlangan'], 'rejected' => ['red', 'Rad etilgan'], 'cancelled' => ['red', 'Bekor'],
        'paid' => ['green', "To'langan"], 'unpaid' => ['gray', "To'lanmagan"], 'partial' => ['yellow', 'Qisman'], 'checking' => ['violet', 'Tekshirilmoqda'], 'refunded' => ['gray', 'Qaytarilgan'],
        'open' => ['yellow', 'Ochiq'], 'closed' => ['gray', 'Yopilgan'], 'fixed' => ['green', 'Tuzatildi'], 'ignored' => ['gray', "E'tiborsiz"],
    ];
    [$c, $l] = $map[$status] ?? ['gray', $status];
    return '<span class="badge ' . $c . '">' . e($l) . '</span>';
}

function layout(string $page, string $title, string $content, array $me): void
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $newTg = (int)val("SELECT COUNT(*) FROM orders WHERE source = 'telegram' AND status = 'new'");
    $diagErr = (int)val("SELECT COUNT(*) FROM diagnostic_checks WHERE status = 'error'");
    $brand = setting('brand_name') ?: 'ADI BURGER';
    echo '<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
    echo '<title>' . e($title) . ' · ' . e($brand) . ' admin</title><link rel="icon" href="../pos/icons/favicon.png"><link rel="stylesheet" href="admin.css?v=' . ADI_VERSION . '"></head><body>';
    echo '<div class="drawer-bg" id="drawer-bg"></div><aside class="drawer" id="drawer"><div class="dr-head"><div class="logo">AB</div><div><b>' . e($brand) . '</b><small>Admin panel</small></div></div><nav>';
    foreach (ADMIN_MENU as $k => [$ic, $label]) {
        $extra = $k === 'orders' && $newTg ? '<span class="cnt blue">' . $newTg . '</span>' : ($k === 'diagnostics' && $diagErr ? '<span class="cnt red">' . $diagErr . '</span>' : '');
        echo '<a href="' . url($k) . '" class="' . ($k === $page ? 'on' : '') . '"><span class="ic">' . $ic . '</span><span>' . e($label) . '</span>' . $extra . '</a>';
    }
    echo '<a href="../pos/" target="_blank"><span class="ic">🧮</span><span>Kassani ochish</span></a></nav>';
    echo '<div class="dr-foot">' . form_open('logout') . '<button class="btn ghost w100">🚪 Chiqish (' . e($me['name']) . ')</button></form></div></aside>';
    echo '<div class="main"><header class="top"><button class="burger" id="burger" aria-label="Menyu"><span></span><span></span><span></span></button><h1>' . e($title) . '</h1><div class="top-r"><span class="who">👤 ' . e($me['name']) . '</span></div></header><div class="content">';
    foreach ($flash as [$t, $m]) {
        echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>';
    }
    echo $content;
    echo '</div></div><script src="admin.js?v=' . ADI_VERSION . '"></script></body></html>';
}

function page_login(): void
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    echo '<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Kirish · Admin</title><link rel="stylesheet" href="admin.css?v=' . ADI_VERSION . '"></head><body class="login-body">';
    echo '<form method="post" action="?p=login" class="login card">' . csrf_field() . '<div class="logo big">AB</div><h1>' . e(setting('brand_name') ?: 'ADI BURGER') . '</h1><p class="muted">Admin panelga kirish</p>';
    foreach ($flash as [$t, $m]) {
        echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>';
    }
    echo field('Login', 'username', $_POST['username'] ?? '', 'text', 'autocomplete="username" required autofocus')
        . field('Parol', 'password', '', 'password', 'autocomplete="current-password" required')
        . '<button class="btn primary w100">Kirish</button></form></body></html>';
}

function pager(int $total, int $per, int $cur, array $q): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $h = '<div class="pager">';
    for ($i = max(1, $cur - 4); $i <= min($pages, $cur + 4); $i++) {
        $h .= '<a class="' . ($i === $cur ? 'on' : '') . '" href="' . e(url($_GET['p'] ?? '', $q + ['pg' => $i])) . '">' . $i . '</a>';
    }
    return $h . '</div>';
}
