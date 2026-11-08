<?php
declare(strict_types=1);

/**
 * ADI BURGER 2.0 — brauzer orqali o'rnatish.
 * O'rnatish tugagach config/config.php va storage/install.lock yaratiladi va bu sahifa qayta ishlamaydi.
 */

require dirname(__DIR__) . '/bootstrap.php';
security_headers();

$lock = ROOT . '/storage/install.lock';
$installed = is_file(ROOT . '/config/config.php') || is_file($lock);

session_name('ADI_INSTALL');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function inst_checks(): array
{
    $c = [];
    $c[] = ['PHP versiyasi ' . PHP_VERSION . ' (8.1+ kerak, 8.2 tavsiya)', version_compare(PHP_VERSION, '8.1.0', '>='), true];
    foreach (['pdo_mysql' => 'MySQL bilan ishlash', 'mbstring' => 'O\'zbekcha matnlar', 'curl' => 'Telegram va Hamyon', 'openssl' => 'Maxfiy sozlamalarni shifrlash', 'json' => 'API'] as $ext => $why) {
        $c[] = ["PHP kengaytmasi: $ext ($why)", extension_loaded($ext), true];
    }
    foreach (['gd' => 'Rasmlarni kichraytirish', 'zlib' => 'Zaxirani siqish'] as $ext => $why) {
        $c[] = ["Ixtiyoriy kengaytma: $ext ($why)", extension_loaded($ext), false];
    }
    foreach (['config', 'storage', 'uploads', 'uploads/products'] as $d) {
        $p = ROOT . '/' . $d;
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        $c[] = ["Papka yozishga ochiq: $d/", is_dir($p) && is_writable($p), true];
    }
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $c[] = ['HTTPS (Telegram bot va kassa uchun shart)', $https, false];
    return $c;
}

function inst_seed(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() > 0) {
        return; // Ma'lumot bor — qayta qo'shilmaydi
    }
    $seed = require ROOT . '/database/seed.php';
    $map = [];
    $st = $pdo->prepare('INSERT INTO categories (name, icon, sort) VALUES (?, ?, ?)');
    foreach ($seed['categories'] as $n => [$name, $icon]) {
        $st->execute([$name, $icon, $n]);
        $map[$n] = (int)$pdo->lastInsertId();
    }
    $st = $pdo->prepare('INSERT INTO products (category_id, name, description, price, image, sort, in_bot) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($seed['products'] as $i => [$cat, $name, $desc, $price, $img, $posOnly]) {
        if ($img && !is_file(ROOT . '/' . $img)) {
            $img = null;
        }
        $st->execute([$map[$cat], $name, $desc, $price, $img, $i + 1, $posOnly ? 0 : 1]);
    }
    $st = $pdo->prepare('INSERT INTO dining_tables (name, sort) VALUES (?, ?)');
    for ($i = 1; $i <= 10; $i++) {
        $st->execute(["Stol $i", $i]);
    }
}

$errors = [];
$done = false;
$f = [
    'url' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/\\'),
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'brand' => 'ADI BURGER', 'admin_name' => 'Administrator', 'username' => 'admin', 'password' => '', 'pin' => '', 'cashier_name' => 'Kassir', 'cashier_pin' => '',
];

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($f as $k => $v) {
        $f[$k] = trim((string)($_POST[$k] ?? $v));
    }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Sahifa eskirgan. Qayta yuboring.';
    }
    $checks = inst_checks();
    foreach ($checks as [$label, $ok, $required]) {
        if ($required && !$ok) {
            $errors[] = "Talab bajarilmagan: $label";
        }
    }
    if (!preg_match('~^https?://[^\s/]+(/[^\s]*)?$~', $f['url'])) {
        $errors[] = 'Sayt manzili noto\'g\'ri. Masalan: https://adiburger.uz';
    }
    if ($f['db_name'] === '' || $f['db_user'] === '') {
        $errors[] = 'Baza nomi va foydalanuvchisini kiriting.';
    }
    if (!preg_match('~^[a-zA-Z0-9_.-]{3,50}$~', $f['username'])) {
        $errors[] = 'Login 3–50 ta lotin harf/raqamdan iborat bo\'lsin.';
    }
    if (mb_strlen($f['password']) < 8) {
        $errors[] = 'Admin paroli kamida 8 belgi bo\'lsin.';
    }
    if (!pin_valid_format($f['pin']) || ($f['cashier_pin'] !== '' && !pin_valid_format($f['cashier_pin']))) {
        $errors[] = 'PIN-kod 4 ta raqam bo\'lsin.';
    }
    if ($f['cashier_pin'] !== '' && $f['cashier_pin'] === $f['pin']) {
        $errors[] = 'Kassir va administrator PIN-kodlari har xil bo\'lsin.';
    }
    if (in_array($f['pin'], ['0000', '1234', '1111'], true)) {
        $errors[] = 'Juda oson PIN. Boshqa PIN tanlang.';
    }
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = db_connect(['host' => $f['db_host'], 'port' => (int)$f['db_port'], 'name' => $f['db_name'], 'user' => $f['db_user'], 'pass' => $f['db_pass']]);
        } catch (Throwable $e) {
            $errors[] = 'Bazaga ulanib bo\'lmadi: ' . $e->getMessage() . '. Baza nomi, foydalanuvchi va parolni tekshiring. Foydalanuvchiga bazaga ALL PRIVILEGES berilganini tekshiring.';
        }
    }
    if (!$errors && $pdo) {
        try {
            $secret = bin2hex(random_bytes(32));
            $GLOBALS['ADI_CFG'] = [
                'db' => ['host' => $f['db_host'], 'port' => (int)$f['db_port'], 'name' => $f['db_name'], 'user' => $f['db_user'], 'pass' => $f['db_pass']],
                'app' => ['url' => rtrim($f['url'], '/'), 'secret' => $secret, 'tz' => 'Asia/Tashkent'],
            ];
            db_migrate($pdo);
            $pdo->beginTransaction();
            inst_seed($pdo);
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
                [$salt, $hash] = pin_make($f['pin']);
                $pdo->prepare("INSERT INTO employees (name, role, pin_salt, pin_hash) VALUES (?, 'admin', ?, ?)")->execute([mb_substr($f['admin_name'], 0, 100), $salt, $hash]);
                $empId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO users (employee_id, username, password_hash) VALUES (?, ?, ?)')->execute([$empId, $f['username'], password_hash($f['password'], PASSWORD_DEFAULT)]);
                if ($f['cashier_pin'] !== '') {
                    [$salt, $hash] = pin_make($f['cashier_pin']);
                    $pdo->prepare("INSERT INTO employees (name, role, pin_salt, pin_hash) VALUES (?, 'cashier', ?, ?)")->execute([mb_substr($f['cashier_name'] ?: 'Kassir', 0, 100), $salt, $hash]);
                }
            }
            $pdo->prepare("INSERT INTO settings (k, v) VALUES ('brand_name', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([mb_substr($f['brand'], 0, 60)]);
            $pdo->commit();

            $cfg = "<?php\n// ADI BURGER 2.0 sozlamalari. Bu fayl maxfiy — hech kimga bermang.\ndefined('ADI') || exit;\nreturn " . var_export($GLOBALS['ADI_CFG'], true) . ";\n";
            if (@file_put_contents(ROOT . '/config/config.php', $cfg, LOCK_EX) === false) {
                throw new RuntimeException('config/config.php yozilmadi. config papkasiga yozish ruxsatini bering (755).');
            }
            install_protect_dirs();
            file_put_contents($lock, date('c') . "\n");
            $done = true;
            $installed = true;
        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'O\'rnatishda xato: ' . $e->getMessage();
        }
    }
}
$checks = inst_checks();
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ADI BURGER 2.0 — O'rnatish</title>
<style>
:root{--bg:#0b1220;--card:#121b2e;--line:#22304a;--text:#e8eefc;--mute:#8a9bbd;--acc:#f5a524;--ok:#22c55e;--bad:#ef4444}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px}h1{font-size:24px;margin:0 0 4px}h1 b{color:var(--acc)}.sub{color:var(--mute);margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:16px}h2{font-size:16px;margin:0 0 12px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:600px){.row{grid-template-columns:1fr}}
label{display:block;font-size:13px;color:var(--mute);margin:0 0 4px}input{width:100%;padding:11px 12px;border-radius:10px;border:1px solid var(--line);background:#0d1526;color:var(--text);font-size:15px;margin-bottom:12px}
input:focus{outline:2px solid var(--acc);border-color:transparent}.chk{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed var(--line);font-size:14px}
.chk:last-child{border:0}.y{color:var(--ok)}.n{color:var(--bad)}.w{color:var(--acc)}.err{background:#3a1218;border:1px solid #7f1d1d;border-radius:12px;padding:12px 16px;margin-bottom:16px}
button{width:100%;padding:14px;border:0;border-radius:12px;background:var(--ok);color:#04130a;font-weight:700;font-size:16px;cursor:pointer}
.ok{text-align:center}.ok a{display:inline-block;margin:6px;padding:12px 18px;border-radius:12px;background:var(--acc);color:#1a1200;font-weight:700;text-decoration:none}
.hint{font-size:12px;color:var(--mute);margin:-8px 0 12px}
</style>
</head>
<body>
<div class="wrap">
<h1><b>ADI BURGER</b> 2.0 — o'rnatish</h1>
<p class="sub">Kassa, admin panel va Telegram bot. Bir marta to'ldiriladi.</p>

<?php if ($done): ?>
  <div class="card ok">
    <h2>✅ O'rnatish muvaffaqiyatli tugadi</h2>
    <p>Menyu (rasmlar bilan), 10 ta stol va administrator hisobi yaratildi. O'rnatuvchi endi bloklangan.</p>
    <a href="../admin/">Admin panel</a> <a href="../pos/">Kassa (POS)</a>
    <p class="sub" style="margin-top:12px">Keyingi qadam: Admin → Telegram bot bo'limida bot tokenini kiriting.</p>
  </div>
<?php elseif ($installed): ?>
  <div class="card ok">
    <h2>🔒 Tizim allaqachon o'rnatilgan</h2>
    <p>Xavfsizlik uchun o'rnatuvchi qayta ishlamaydi.</p>
    <a href="../admin/">Admin panel</a> <a href="../pos/">Kassa (POS)</a>
  </div>
<?php else: ?>
  <?php if ($errors): ?><div class="err"><?php foreach ($errors as $er): ?>❌ <?= e($er) ?><br><?php endforeach ?></div><?php endif ?>
  <div class="card">
    <h2>1. Server tekshiruvi</h2>
    <?php foreach ($checks as [$label, $ok, $req]): ?>
      <div class="chk"><span><?= e($label) ?></span><b class="<?= $ok ? 'y' : ($req ? 'n' : 'w') ?>"><?= $ok ? '✓ bor' : ($req ? '✗ yo\'q' : '! yo\'q') ?></b></div>
    <?php endforeach ?>
  </div>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($_SESSION['csrf']) ?>">
    <div class="card">
      <h2>2. Sayt va baza</h2>
      <label>Sayt manzili</label><input name="url" value="<?= e($f['url']) ?>" required>
      <div class="hint">Hosting panelida yaratgan MySQL bazangiz ma'lumotlari:</div>
      <div class="row">
        <div><label>Baza serveri</label><input name="db_host" value="<?= e($f['db_host']) ?>" required></div>
        <div><label>Port</label><input name="db_port" value="<?= e($f['db_port']) ?>" required></div>
      </div>
      <div class="row">
        <div><label>Baza nomi</label><input name="db_name" value="<?= e($f['db_name']) ?>" required></div>
        <div><label>Baza foydalanuvchisi</label><input name="db_user" value="<?= e($f['db_user']) ?>" required></div>
      </div>
      <label>Baza paroli</label><input type="password" name="db_pass" value="">
    </div>
    <div class="card">
      <h2>3. Administrator va kassir</h2>
      <label>Do'kon nomi</label><input name="brand" value="<?= e($f['brand']) ?>" required>
      <div class="row">
        <div><label>Administrator ismi</label><input name="admin_name" value="<?= e($f['admin_name']) ?>" required></div>
        <div><label>Admin panel logini</label><input name="username" value="<?= e($f['username']) ?>" required></div>
      </div>
      <div class="row">
        <div><label>Admin panel paroli (kamida 8 belgi)</label><input type="password" name="password" minlength="8" required></div>
        <div><label>Administrator PIN (kassaga kirish, 4 raqam)</label><input name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" required></div>
      </div>
      <div class="row">
        <div><label>Kassir ismi (ixtiyoriy)</label><input name="cashier_name" value="<?= e($f['cashier_name']) ?>"></div>
        <div><label>Kassir PIN (4 raqam, ixtiyoriy)</label><input name="cashier_pin" inputmode="numeric" pattern="\d{4}" maxlength="4"></div>
      </div>
    </div>
    <button type="submit">O'rnatish</button>
  </form>
<?php endif ?>
</div>
</body>
</html>
