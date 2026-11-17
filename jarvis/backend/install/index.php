<?php
/**
 * JARVIS backend — brauzer orqali o'rnatuvchi.
 * O'rnatilgandan keyin qayta ishga tushmaydi (inc/installed.lock).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/gemini.php';
require dirname(__DIR__) . '/inc/ui.php';

jv_session_start();

if (jv_installed()) {
    http_response_code(403);
    jv_page_start('O\'rnatilgan');
    echo '<div class="card"><h2>Tizim allaqachon o\'rnatilgan</h2>'
        . '<p>Installer bloklangan. Xavfsizlik uchun hostingdan <b>install/</b> papkasini o\'chirib qo\'yishingiz mumkin.</p>'
        . '<a class="btn" href="../admin/">Admin panel</a></div>';
    jv_page_end();
    exit;
}

// --- Talablar ---
$checks = [
    ['PHP 8.0 yoki yuqori (hozir ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.0.0', '>=')],
    ['PDO MySQL kengaytmasi', extension_loaded('pdo_mysql')],
    ['cURL kengaytmasi (Gemini uchun)', extension_loaded('curl')],
    ['mbstring kengaytmasi', extension_loaded('mbstring')],
    ['JSON kengaytmasi', function_exists('json_encode')],
    ['inc/ papkasiga yozish ruxsati', is_writable(JV_INC)],
];
$allOk = !in_array(false, array_column($checks, 1), true);

$errors = [];
$warnings = [];
$done = false;
$f = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'admin_user' => 'admin',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $allOk) {
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $apiKey = trim((string)($_POST['gemini_key'] ?? ''));
    $pass1 = (string)($_POST['admin_pass'] ?? '');
    $pass2 = (string)($_POST['admin_pass2'] ?? '');

    if (!jv_csrf_check()) {
        $errors[] = 'Sessiya eskirgan. Sahifani yangilab qayta urinib ko\'ring.';
    }
    if ($f['db_name'] === '' || $f['db_user'] === '') {
        $errors[] = 'Baza nomi va foydalanuvchisini kiriting.';
    }
    if (!ctype_digit($f['db_port'])) {
        $errors[] = 'Port faqat raqam bo\'lishi kerak.';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $apiKey)) {
        $errors[] = 'Gemini API kaliti noto\'g\'ri ko\'rinishda.';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $f['admin_user'])) {
        $errors[] = 'Admin login: 3–64 ta lotin harf/raqam.';
    }
    if (strlen($pass1) < 8) {
        $errors[] = 'Admin paroli kamida 8 ta belgi bo\'lsin.';
    } elseif ($pass1 !== $pass2) {
        $errors[] = 'Parollar bir xil emas.';
    }

    $cfg = [
        'db_host' => $f['db_host'], 'db_port' => (int)$f['db_port'], 'db_name' => $f['db_name'],
        'db_user' => $f['db_user'], 'db_pass' => $dbPass,
        'gemini_api_key' => $apiKey,
        'gemini_base' => 'https://generativelanguage.googleapis.com/v1beta',
    ];

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = jv_pdo($cfg);
        } catch (Throwable $e) {
            $errors[] = 'Bazaga ulanib bo\'lmadi: ' . $e->getMessage();
        }
    }

    if (!$errors && $pdo) {
        try {
            foreach (jv_schema_sql() as $sql) {
                $pdo->exec($sql);
            }
            $ins = $pdo->prepare('INSERT IGNORE INTO jv_settings (k, v) VALUES (?, ?)');
            foreach (JV_DEFAULT_SETTINGS as $k => $v) {
                $ins->execute([$k, $v]);
            }
            $pdo->prepare('INSERT INTO jv_admins (username, pass_hash, created_at) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash)')
                ->execute([$f['admin_user'], password_hash($pass1, PASSWORD_DEFAULT), jv_now()]);

            if (!jv_write_config($cfg)) {
                $errors[] = 'inc/config.php yozilmadi. inc/ papkasiga yozish ruxsatini bering (755).';
            } elseif (file_put_contents(JV_INC . '/installed.lock', 'installed ' . jv_now() . "\n") === false) {
                $errors[] = 'inc/installed.lock yozilmadi.';
            } else {
                $done = true;
                try {
                    if (!(new GeminiClient($apiKey))->ping()) {
                        $warnings[] = 'Gemini kaliti tekshiruvdan o\'tmadi. Admin panel → Sozlamalar orqali to\'g\'ri kalitni kiriting.';
                    }
                } catch (Throwable $e) {
                    $warnings[] = 'Gemini bilan aloqa tekshirilmadi: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Jadvallarni yaratishda xato: ' . $e->getMessage();
        }
    }
}

jv_page_start('O\'rnatish');

if ($done) {
    session_regenerate_id(true);
    echo '<div class="msg ok">✅ JARVIS backend muvaffaqiyatli o\'rnatildi.</div>';
    foreach ($warnings as $w) {
        echo '<div class="msg warn">' . jv_h($w) . '</div>';
    }
    echo '<div class="card"><h2>Keyingi qadamlar</h2><ol>'
        . '<li>Admin panelga kiring.</li>'
        . '<li><b>Qurilmalar</b> bo\'limida yangi token yarating.</li>'
        . '<li>JARVIS ilovasi → Sozlamalar: server manzili va tokenni kiriting.</li>'
        . '<li>Xavfsizlik uchun <b>install/</b> papkasini o\'chiring (installer baribir bloklangan).</li>'
        . '</ol><a class="btn" href="../admin/">Admin panelga o\'tish</a></div>';
    jv_page_end();
    exit;
}

foreach ($errors as $e) {
    echo '<div class="msg bad">' . jv_h($e) . '</div>';
}
?>
<div class="card">
  <h2>1. Server talablari</h2>
  <table>
  <?php foreach ($checks as [$name, $ok]): ?>
    <tr><td><?= jv_h($name) ?></td><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✔ OK' : '✘ Yo\'q' ?></td></tr>
  <?php endforeach; ?>
  </table>
  <?php if (!$allOk): ?><p class="bad">Talablar bajarilmagan. Hosting panelida PHP sozlamalarini to'g'rilang va sahifani yangilang.</p><?php endif; ?>
</div>

<?php if ($allOk): ?>
<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= jv_h(jv_csrf_token()) ?>">
  <h2>2. MySQL baza</h2>
  <label>Host</label><input name="db_host" value="<?= jv_h($f['db_host']) ?>" required>
  <label>Port</label><input name="db_port" value="<?= jv_h($f['db_port']) ?>" required>
  <label>Baza nomi</label><input name="db_name" value="<?= jv_h($f['db_name']) ?>" required>
  <label>Foydalanuvchi</label><input name="db_user" value="<?= jv_h($f['db_user']) ?>" required>
  <label>Parol</label><input name="db_pass" type="password">

  <h2 style="margin-top:22px">3. Gemini API kaliti</h2>
  <label>Kalit (faqat serverda saqlanadi, ilovaga yuborilmaydi)</label>
  <input name="gemini_key" type="password" required>
  <p class="muted" style="font-size:13px">Kalitni Google AI Studio'dan olasiz: aistudio.google.com → Get API key.</p>

  <h2 style="margin-top:22px">4. Admin hisobi</h2>
  <label>Login</label><input name="admin_user" value="<?= jv_h($f['admin_user']) ?>" required>
  <label>Parol (kamida 8 belgi)</label><input name="admin_pass" type="password" minlength="8" required>
  <label>Parolni takrorlang</label><input name="admin_pass2" type="password" minlength="8" required>

  <button class="btn" type="submit">O'rnatish</button>
</form>
<?php endif;
jv_page_end();
