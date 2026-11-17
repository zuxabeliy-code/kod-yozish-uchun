<?php
/**
 * JARVIS admin panel: qurilma tokenlari, model sozlamalari, Gemini kaliti, statistika va loglar.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/gemini.php';
require dirname(__DIR__) . '/inc/ui.php';

if (!jv_installed()) {
    header('Location: ../install/');
    exit;
}

jv_session_start();
$db = jv_db();
$page = (string)($_GET['p'] ?? 'home');
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = [$type, $text];
}

function go(string $page): void
{
    header('Location: ?p=' . rawurlencode($page));
    exit;
}

// --- Sessiya muddati: 2 soat harakatsizlik ---
if (!empty($_SESSION['admin_id']) && (time() - (int)($_SESSION['seen'] ?? 0)) > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (!empty($_SESSION['admin_id'])) {
    $_SESSION['seen'] = time();
}

// --- Chiqish ---
if ($page === 'logout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && jv_csrf_check()) {
    $_SESSION = [];
    session_regenerate_id(true);
    go('home');
}

// --- Kirish ---
if (empty($_SESSION['admin_id'])) {
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $ip = jv_client_ip();
        $st = $db->prepare('SELECT COUNT(*) FROM jv_login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)');
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 5) {
            $error = 'Juda ko\'p urinish. 15 daqiqadan keyin qayta urinib ko\'ring.';
        } elseif (!jv_csrf_check()) {
            $error = 'Sessiya eskirgan, qayta urinib ko\'ring.';
        } else {
            $st = $db->prepare('SELECT id, pass_hash FROM jv_admins WHERE username = ?');
            $st->execute([trim((string)($_POST['user'] ?? ''))]);
            $admin = $st->fetch();
            if ($admin && password_verify((string)($_POST['pass'] ?? ''), $admin['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['seen'] = time();
                $db->prepare('DELETE FROM jv_login_attempts WHERE ip = ?')->execute([$ip]);
                go('home');
            }
            $db->prepare('INSERT INTO jv_login_attempts (ip, created_at) VALUES (?, ?)')->execute([$ip, jv_now()]);
            $error = 'Login yoki parol noto\'g\'ri.';
        }
    }
    jv_page_start('Admin kirish');
    if ($error) {
        echo '<div class="msg bad">' . jv_h($error) . '</div>';
    }
    ?>
    <form method="post" class="card" style="max-width:420px">
      <input type="hidden" name="csrf" value="<?= jv_h(jv_csrf_token()) ?>">
      <h2>Kirish</h2>
      <label>Login</label><input name="user" autocomplete="username" required>
      <label>Parol</label><input name="pass" type="password" autocomplete="current-password" required>
      <button class="btn" type="submit">Kirish</button>
    </form>
    <?php
    jv_page_end();
    exit;
}

// --- POST amallar ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!jv_csrf_check()) {
        flash('bad', 'Sessiya eskirgan. Qayta urinib ko\'ring.');
        go($page);
    }
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'device_create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = $name === '' ? 'Telefon' : mb_substr($name, 0, 100);
        $token = jv_new_token();
        $db->prepare('INSERT INTO jv_devices (name, token_hash, active, created_at) VALUES (?, ?, 1, ?)')
            ->execute([$name, jv_token_hash($token), jv_now()]);
        $_SESSION['new_token'] = ['name' => $name, 'token' => $token];
        go('devices');
    }
    if ($act === 'device_toggle') {
        $db->prepare('UPDATE jv_devices SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Qurilma holati o\'zgartirildi.');
        go('devices');
    }
    if ($act === 'device_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM jv_devices WHERE id = ?')->execute([$id]);
        $db->prepare('DELETE FROM jv_requests WHERE device_id = ?')->execute([$id]);
        flash('ok', 'Qurilma o\'chirildi.');
        go('devices');
    }
    if ($act === 'settings_save') {
        $errs = [];
        foreach (['chat_model', 'stt_model', 'tts_model'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,80}$/', $v)) {
                $errs[] = $k . ': model nomi noto\'g\'ri';
            }
        }
        $voice = trim((string)($_POST['tts_voice'] ?? ''));
        if (!preg_match('/^[A-Za-z]{2,40}$/', $voice)) {
            $errs[] = 'Ovoz nomi noto\'g\'ri';
        }
        $pm = (int)($_POST['limit_per_min'] ?? 0);
        $pd = (int)($_POST['limit_per_day'] ?? 0);
        if ($pm < 1 || $pm > 600 || $pd < 1 || $pd > 100000) {
            $errs[] = 'Limitlar noto\'g\'ri';
        }
        $prompt = trim((string)($_POST['system_prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 6000) {
            $errs[] = 'System prompt bo\'sh yoki juda uzun';
        }
        if ($errs) {
            foreach ($errs as $e) {
                flash('bad', $e);
            }
        } else {
            foreach (['chat_model', 'stt_model', 'tts_model'] as $k) {
                jv_set_setting($k, trim((string)$_POST[$k]));
            }
            jv_set_setting('tts_voice', $voice);
            jv_set_setting('limit_per_min', (string)$pm);
            jv_set_setting('limit_per_day', (string)$pd);
            jv_set_setting('system_prompt', $prompt);
            jv_set_setting('user_name', mb_substr(trim((string)($_POST['user_name'] ?? 'Zuxa')), 0, 40));
            flash('ok', 'Sozlamalar saqlandi.');
        }
        go('settings');
    }
    if ($act === 'settings_reset_prompt') {
        jv_set_setting('system_prompt', JV_DEFAULT_SETTINGS['system_prompt']);
        flash('ok', 'System prompt standart holatga qaytarildi.');
        go('settings');
    }
    if ($act === 'key_save') {
        $key = trim((string)($_POST['gemini_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-]{20,200}$/', $key)) {
            flash('bad', 'Kalit noto\'g\'ri ko\'rinishda.');
        } else {
            $cfg = jv_config();
            $cfg['gemini_api_key'] = $key;
            if (jv_write_config($cfg)) {
                $ok = false;
                try {
                    $ok = (new GeminiClient($key, (string)$cfg['gemini_base']))->ping();
                } catch (Throwable $e) {
                }
                flash($ok ? 'ok' : 'warn', $ok ? 'Kalit saqlandi va tekshirildi ✔' : 'Kalit saqlandi, lekin Gemini tekshiruvidan o\'tmadi.');
            } else {
                flash('bad', 'config.php yozilmadi (ruxsatlarni tekshiring).');
            }
        }
        go('settings');
    }
    if ($act === 'key_test') {
        $cfg = jv_config();
        $ok = false;
        $msg = '';
        try {
            $ok = (new GeminiClient((string)$cfg['gemini_api_key'], (string)$cfg['gemini_base']))->ping();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
        }
        flash($ok ? 'ok' : 'bad', $ok ? 'Gemini bilan aloqa bor ✔' : 'Gemini tekshiruvi xato. ' . $msg);
        go('settings');
    }
    if ($act === 'password_change') {
        $st = $db->prepare('SELECT pass_hash FROM jv_admins WHERE id = ?');
        $st->execute([$_SESSION['admin_id']]);
        $hash = (string)$st->fetchColumn();
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $hash)) {
            flash('bad', 'Joriy parol noto\'g\'ri.');
        } elseif (strlen($new) < 8 || $new !== (string)($_POST['new2'] ?? '')) {
            flash('bad', 'Yangi parol kamida 8 belgi va ikkalasi bir xil bo\'lsin.');
        } else {
            $db->prepare('UPDATE jv_admins SET pass_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            session_regenerate_id(true);
            flash('ok', 'Parol o\'zgartirildi.');
        }
        go('settings');
    }
    go($page);
}

// --- Sahifalar ---
jv_page_start('Admin panel');
$csrf = jv_h(jv_csrf_token());
$tabs = ['home' => 'Bosh sahifa', 'devices' => 'Qurilmalar', 'settings' => 'Sozlamalar', 'logs' => 'Loglar'];
echo '<nav>';
foreach ($tabs as $k => $label) {
    echo '<a href="?p=' . $k . '"' . ($page === $k ? ' class="on"' : '') . '>' . $label . '</a>';
}
echo '<form method="post" action="?p=logout" style="margin-left:auto"><input type="hidden" name="csrf" value="' . $csrf . '">'
    . '<button class="btn ghost sm" type="submit">Chiqish</button></form></nav>';

foreach ($flash as [$type, $text]) {
    echo '<div class="msg ' . jv_h($type) . '">' . jv_h($text) . '</div>';
}

if ($page === 'devices') {
    if (!empty($_SESSION['new_token'])) {
        $nt = $_SESSION['new_token'];
        unset($_SESSION['new_token']);
        echo '<div class="card"><h2>Yangi token: ' . jv_h($nt['name']) . '</h2>'
            . '<p class="warn">Bu token faqat HOZIR ko\'rsatiladi. Nusxa olib, JARVIS ilovasi → Sozlamalar → Token maydoniga qo\'ying.</p>'
            . '<div class="token" id="tk">' . jv_h($nt['token']) . '</div>'
            . '<button class="btn sm" style="margin-top:10px" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'tk\').textContent);this.textContent=\'Nusxalandi ✔\'">Nusxa olish</button></div>';
    }
    ?>
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="device_create">
      <h2>Yangi qurilma</h2>
      <label>Nomi</label><input name="name" placeholder="Galaxy S10">
      <button class="btn" type="submit">Token yaratish</button>
    </form>
    <div class="card scroll"><h2>Qurilmalar</h2><table>
      <tr><th>Nomi</th><th>Holat</th><th>Oxirgi faollik</th><th>Bugun</th><th></th></tr>
    <?php
    $rows = $db->query("SELECT d.*, (SELECT COUNT(*) FROM jv_requests r WHERE r.device_id = d.id AND r.created_at >= CURDATE()) AS today
                        FROM jv_devices d ORDER BY d.id DESC")->fetchAll();
    foreach ($rows as $d): ?>
      <tr>
        <td><?= jv_h($d['name']) ?><br><span class="muted" style="font-size:12px"><?= jv_h($d['created_at']) ?></span></td>
        <td class="<?= $d['active'] ? 'ok' : 'bad' ?>"><?= $d['active'] ? 'Faol' : 'O\'chiq' ?></td>
        <td><?= jv_h($d['last_seen_at'] ?? '—') ?></td>
        <td><?= (int)$d['today'] ?></td>
        <td><div class="row">
          <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="device_toggle"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn ghost sm"><?= $d['active'] ? 'O\'chirish' : 'Yoqish' ?></button></form>
          <form method="post" onsubmit="return confirm('Qurilma butunlay o\'chirilsinmi?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="device_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn danger sm">Yo'q qilish</button></form>
        </div></td>
      </tr>
    <?php endforeach;
    if (!$rows) {
        echo '<tr><td colspan="5" class="muted">Hali qurilma yo\'q.</td></tr>';
    }
    echo '</table></div>';
} elseif ($page === 'settings') {
    $s = jv_settings();
    $cfg = jv_config();
    ?>
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="settings_save">
      <h2>AI sozlamalari</h2>
      <label>Suhbat modeli</label><input name="chat_model" value="<?= jv_h($s['chat_model']) ?>">
      <label>Nutqni tanish (STT) modeli</label><input name="stt_model" value="<?= jv_h($s['stt_model']) ?>">
      <label>Ovoz (TTS) modeli</label><input name="tts_model" value="<?= jv_h($s['tts_model']) ?>">
      <label>TTS ovozi (masalan: Kore, Puck, Charon, Fenrir, Orus)</label><input name="tts_voice" value="<?= jv_h($s['tts_voice']) ?>">
      <label>Foydalanuvchi ismi</label><input name="user_name" value="<?= jv_h($s['user_name']) ?>">
      <div class="grid">
        <div><label>Daqiqalik limit</label><input name="limit_per_min" type="number" min="1" value="<?= jv_h($s['limit_per_min']) ?>"></div>
        <div><label>Kunlik limit</label><input name="limit_per_day" type="number" min="1" value="<?= jv_h($s['limit_per_day']) ?>"></div>
      </div>
      <label>System prompt ({user} — ism bilan almashtiriladi)</label>
      <textarea name="system_prompt"><?= jv_h($s['system_prompt']) ?></textarea>
      <button class="btn" type="submit">Saqlash</button>
    </form>
    <form method="post" class="card" style="margin-top:-6px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="settings_reset_prompt">
      <button class="btn ghost sm" type="submit">System promptni standartga qaytarish</button>
    </form>

    <div class="card">
      <h2>Gemini API kaliti</h2>
      <p>Joriy kalit: <span class="token" style="display:inline-block;padding:4px 8px"><?= jv_h(jv_mask((string)$cfg['gemini_api_key'])) ?></span></p>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="key_test">
        <button class="btn ghost sm" type="submit">Aloqani tekshirish</button></form>
      <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="key_save">
        <label>Yangi kalit</label><input name="gemini_key" type="password">
        <button class="btn" type="submit">Kalitni almashtirish</button></form>
    </div>

    <form method="post" class="card" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="password_change">
      <h2>Admin paroli</h2>
      <label>Joriy parol</label><input name="old" type="password" autocomplete="current-password">
      <label>Yangi parol</label><input name="new" type="password" minlength="8" autocomplete="new-password">
      <label>Takrorlang</label><input name="new2" type="password" minlength="8" autocomplete="new-password">
      <button class="btn" type="submit">O'zgartirish</button>
    </form>
    <?php
} elseif ($page === 'logs') {
    $rows = $db->query('SELECT r.*, d.name FROM jv_requests r LEFT JOIN jv_devices d ON d.id = r.device_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
    echo '<div class="card scroll"><h2>Oxirgi 100 so\'rov</h2><p class="muted" style="font-size:13px">Maxfiylik uchun suhbat matni saqlanmaydi.</p><table>'
        . '<tr><th>Vaqt</th><th>Qurilma</th><th>Amal</th><th>Holat</th><th>ms</th><th>Xato</th></tr>';
    foreach ($rows as $r) {
        $cls = (int)$r['status'] === 200 ? 'ok' : 'bad';
        echo '<tr><td>' . jv_h($r['created_at']) . '</td><td>' . jv_h($r['name'] ?? '—') . '</td><td>' . jv_h($r['action'])
            . '</td><td class="' . $cls . '">' . (int)$r['status'] . '</td><td>' . (int)$r['ms'] . '</td><td style="font-size:12px">' . jv_h($r['err'] ?? '') . '</td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="6" class="muted">Hali so\'rov yo\'q.</td></tr>';
    }
    echo '</table></div>';
} else {
    $stats = $db->query("SELECT
        (SELECT COUNT(*) FROM jv_devices WHERE active = 1) AS devices,
        (SELECT COUNT(*) FROM jv_requests WHERE created_at >= CURDATE()) AS today,
        (SELECT COUNT(*) FROM jv_requests WHERE created_at >= CURDATE() AND status <> 200) AS errors,
        (SELECT COALESCE(ROUND(AVG(ms)),0) FROM jv_requests WHERE created_at >= CURDATE() AND status = 200) AS avg_ms")->fetch();
    $base = (jv_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'domen') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/');
    ?>
    <div class="grid">
      <div class="card stat">Faol qurilmalar<b><?= (int)$stats['devices'] ?></b></div>
      <div class="card stat">Bugungi so'rovlar<b><?= (int)$stats['today'] ?></b></div>
      <div class="card stat">Bugungi xatolar<b><?= (int)$stats['errors'] ?></b></div>
      <div class="card stat">O'rtacha javob<b><?= (int)$stats['avg_ms'] ?> ms</b></div>
    </div>
    <div class="card">
      <h2>Ilovaga ulash</h2>
      <p>JARVIS ilovasi → Sozlamalar → <b>Server manzili</b>:</p>
      <div class="token"><?= jv_h($base) ?></div>
      <?php if (!jv_is_https()): ?><p class="warn">Diqqat: sayt HTTPS emas. Ilova faqat https:// manzil bilan ishlaydi — hostingda bepul SSL (Let's Encrypt) yoqing.</p><?php endif; ?>
      <p class="muted">Token olish: <a href="?p=devices" style="color:var(--cyan)">Qurilmalar</a> bo'limi.</p>
    </div>
    <?php
}
jv_page_end();
