<?php
declare(strict_types=1);
defined('ADI') || exit;

/**
 * Telegram Bot API chaqiruvi. Xatoda null qaytaradi va oxirgi xatoni $GLOBALS['TG_ERR'] ga yozadi.
 * Test rejimida (config: test.tg_dry) tashqariga so'rov yuborilmaydi.
 */
function tg_api(string $method, array $params = [], int $timeout = 8): ?array
{
    $GLOBALS['TG_ERR'] = '';
    $token = setting('bot_token');
    if ($token === '') {
        $GLOBALS['TG_ERR'] = 'Bot tokeni kiritilmagan';
        return null;
    }
    if (cfg('test.tg_dry')) {
        $GLOBALS['TG_SENT'][] = [$method, $params];
        return ['ok' => true, 'result' => $method === 'getMe' ? ['id' => 1, 'username' => 'test_bot'] : ['message_id' => count($GLOBALS['TG_SENT'])]];
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($data) || empty($data['ok'])) {
        $GLOBALS['TG_ERR'] = $err !== '' ? "Tarmoq: $err" : ('Telegram: ' . ($data['description'] ?? "HTTP $code"));
        if (!in_array($method, ['sendMessage', 'editMessageText', 'answerCallbackQuery'], true) || $code === 401 || $err !== '') {
            record_error('telegram', 'TG_' . strtoupper($method), $GLOBALS['TG_ERR'], ['http' => $code],
                $code === 401 ? 'Bot tokeni noto\'g\'ri. Admin → Telegram bot bo\'limida yangi token kiriting.' : 'Internet yoki Telegram serveri bilan aloqani tekshiring.');
        }
        return null;
    }
    return $data;
}

function tg_send(int|string $chatId, string $text, ?array $markup = null): ?int
{
    if (!$chatId) {
        return null;
    }
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($markup) {
        $p['reply_markup'] = $markup;
    }
    $r = tg_api('sendMessage', $p);
    return $r ? (int)($r['result']['message_id'] ?? 0) : null;
}

function tg_admin_ids(): array
{
    return array_values(array_filter(array_map('trim', preg_split('~[\s,;]+~', setting('admin_tg_ids')) ?: []), fn($x) => preg_match('~^-?\d+$~', $x)));
}

function tg_webapp_url(): string
{
    return app_url('telegram/webapp/');
}

/** Webhookni ro'yxatdan o'tkazadi. Maxfiy token har safar yangilanadi. */
function tg_webhook_set(): array
{
    $secret = setting('webhook_secret');
    if ($secret === '') {
        $secret = random_token(24);
        setting_set('webhook_secret', $secret);
    }
    $url = app_url('telegram/webhook.php');
    if (!str_starts_with($url, 'https://')) {
        return ['ok' => false, 'error' => 'Sayt manzili https:// bilan boshlanishi kerak (Telegram faqat HTTPS bilan ishlaydi).'];
    }
    $r = tg_api('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false, 'max_connections' => 20]);
    if (!$r) {
        return ['ok' => false, 'error' => $GLOBALS['TG_ERR'] ?? 'Noma\'lum xato'];
    }
    tg_api('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'Menyu', 'web_app' => ['url' => tg_webapp_url()]]]);
    $me = tg_api('getMe');
    if ($me) {
        setting_set('bot_username', (string)($me['result']['username'] ?? ''));
    }
    return ['ok' => true];
}

/** WebApp initData imzosini tekshiradi. To'g'ri bo'lsa Telegram user massivini qaytaradi. */
function tg_check_init_data(string $initData, int $maxAge = 86400): ?array
{
    if ($initData === '' || strlen($initData) > 4096) {
        return null;
    }
    parse_str($initData, $d);
    $hash = (string)($d['hash'] ?? '');
    unset($d['hash']);
    if ($hash === '') {
        return null;
    }
    ksort($d);
    $pairs = [];
    foreach ($d as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $secret = hash_hmac('sha256', setting('bot_token'), 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
    if (!hash_equals($calc, $hash)) {
        return null;
    }
    if ((int)($d['auth_date'] ?? 0) < time() - $maxAge) {
        return null;
    }
    $u = json_decode((string)($d['user'] ?? ''), true);
    return is_array($u) && !empty($u['id']) ? $u : null;
}

/** Telegram foydalanuvchisini mijozlar jadvaliga yozadi/yangilaydi. */
function tg_customer(array $u): array
{
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    q('INSERT INTO customers (tg_id, tg_username, name) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE tg_username = VALUES(tg_username), name = COALESCE(name, VALUES(name))',
        [(int)$u['id'], str_limit($u['username'] ?? '', 64) ?: null, str_limit($name, 100) ?: null]);
    return row('SELECT * FROM customers WHERE tg_id = ?', [(int)$u['id']]);
}

function tg_order_text(array $o, bool $forAdmin = false): string
{
    $t = ORDER_TYPES[$o['type']] ?? $o['type'];
    $lines = ["<b>Buyurtma {$o['number']}</b> · " . e(ORDER_STATUSES[$o['status']] ?? $o['status']), "🧾 Turi: " . e($t)];
    foreach (order_items((int)$o['id']) as $i) {
        if ((int)$i['qty'] > 0) {
            $lines[] = '• ' . e($i['name']) . ' × ' . (int)$i['qty'] . ' = ' . money((int)$i['price'] * (int)$i['qty']);
        }
    }
    if ((int)$o['delivery_fee'] > 0) {
        $lines[] = '🚚 Yetkazish: ' . money((int)$o['delivery_fee']);
    }
    $pm = ['cash' => 'Naqd', 'card' => 'Karta', 'hamyon' => 'Hamyon', 'mixed' => 'Aralash'][$o['pay_method'] ?? ''] ?? '';
    $lines[] = '<b>Jami: ' . money((int)$o['total']) . '</b>' . ($pm ? ' · ' . $pm : '') . ($o['pay_status'] === 'paid' ? " ✅ to'langan" : '');
    if ($forAdmin) {
        $lines[] = '👤 ' . e($o['customer_name'] ?? '') . ' ' . e($o['phone'] ?? '');
        if ($o['address']) {
            $lines[] = '📍 ' . e($o['address']);
        }
    }
    if ($o['comment']) {
        $lines[] = '💬 ' . e($o['comment']);
    }
    if ($o['status'] === 'rejected' && $o['reject_reason']) {
        $lines[] = 'Sabab: ' . e($o['reject_reason']);
    }
    return implode("\n", $lines);
}

/** Mijozga buyurtma holati haqida xabar. */
function tg_notify_order(array $o): void
{
    if ($o['source'] !== 'telegram' || !$o['customer_id']) {
        return;
    }
    $tgId = val('SELECT tg_id FROM customers WHERE id = ?', [$o['customer_id']]);
    if (!$tgId) {
        return;
    }
    $head = match ($o['status']) {
        'new' => "✅ Buyurtmangiz qabul qilindi va kassaga yuborildi.",
        'accepted' => "👨‍🍳 Buyurtmangiz tayyorlanmoqda.",
        'ready' => $o['type'] === 'delivery' ? '🚚 Buyurtmangiz tayyor, yo\'lga chiqadi.' : '🛍 Buyurtmangiz tayyor! Olib ketishingiz mumkin.',
        'done' => '🎉 Buyurtma yakunlandi. Yoqimli ishtaha!',
        'rejected' => '❌ Afsuski, buyurtmangiz rad etildi.',
        'cancelled' => '❌ Buyurtma bekor qilindi.',
        default => '',
    };
    if ($head !== '') {
        tg_send($tgId, $head . "\n\n" . tg_order_text($o));
    }
}

/** Adminlarga yangi buyurtma xabari (qabul/rad tugmalari bilan). */
function tg_notify_admins_new(array $o): void
{
    $unpaid = in_array($o['pay_method'], ['card', 'hamyon'], true) && $o['pay_status'] !== 'paid';
    $text = "🔔 <b>Yangi buyurtma!</b>\n" . tg_order_text($o, true)
        . ($unpaid ? "\n\n⚠️ <b>To'lov hali tasdiqlanmagan.</b> Mijoz yuborgan chekni kassada yoki admin panelda tekshiring — shundan keyingina qabul qilinadi." : '');
    $kb = $unpaid ? [[['text' => '❌ Rad etish', 'callback_data' => 'o:' . $o['id'] . ':rej']]]
        : [[['text' => '✅ Qabul qilish', 'callback_data' => 'o:' . $o['id'] . ':acc'], ['text' => '❌ Rad etish', 'callback_data' => 'o:' . $o['id'] . ':rej']]];
    foreach (tg_admin_ids() as $id) {
        tg_send($id, $text, ['inline_keyboard' => $kb]);
    }
}
