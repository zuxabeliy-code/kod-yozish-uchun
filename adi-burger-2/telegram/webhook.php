<?php
declare(strict_types=1);

/**
 * Telegram webhook. Faqat Telegram'dan kelgan (maxfiy token bilan) so'rovlar qabul qilinadi.
 * Bot o'chirilgan yoki xato bo'lsa ham kassa va admin panel ishlashda davom etadi.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();

$secret = setting('webhook_secret');
if ($secret === '' || !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit;
}

$u = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($u)) {
    exit;
}

// Telegram'ga darhol 200 qaytaramiz (u qayta yubormasligi uchun), xato bo'lsa logga yoziladi
try {
    if (isset($u['message'])) {
        bot_message($u['message']);
    } elseif (isset($u['callback_query'])) {
        bot_callback($u['callback_query']);
    }
} catch (Throwable $e) {
    log_exception($e, 'webhook');
}
http_response_code(200);
echo 'ok';

function bot_menu(): array
{
    return ['keyboard' => [
        [['text' => '🍔 Buyurtma berish', 'web_app' => ['url' => tg_webapp_url()]]],
        [['text' => '📦 Buyurtmalarim'], ['text' => "📞 Bog'lanish"]],
    ], 'resize_keyboard' => true, 'is_persistent' => true];
}

function bot_message(array $m): void
{
    $chat = (int)($m['chat']['id'] ?? 0);
    if (!$chat || ($m['chat']['type'] ?? '') !== 'private' || empty($m['from']['id'])) {
        return;
    }
    $cust = tg_customer($m['from']);
    if ((int)$cust['is_blocked'] === 1) {
        return;
    }
    $text = trim((string)($m['text'] ?? ''));
    $brand = setting('brand_name');

    if (isset($m['contact']['phone_number']) && (int)($m['contact']['user_id'] ?? 0) === (int)$m['from']['id']) {
        $ph = normalize_phone((string)$m['contact']['phone_number']);
        if ($ph !== '') {
            q('UPDATE customers SET phone = ? WHERE id = ?', [$ph, $cust['id']]);
            tg_send($chat, "✅ Raqamingiz saqlandi: $ph", bot_menu());
        }
        return;
    }
    if (!setting_on('bot_enabled')) {
        tg_send($chat, "Kechirasiz, $brand bot orqali buyurtma qabul qilish vaqtincha to'xtatilgan.\n\n" . setting('contact_text'));
        return;
    }
    if (str_starts_with($text, '/start') || $text === '/menu') {
        tg_send($chat, "Assalomu alaykum! <b>" . e($brand) . "</b> ga xush kelibsiz 🍔\n\n"
            . "Buyurtma berish uchun pastdagi <b>«🍔 Buyurtma berish»</b> tugmasini bosing.\n"
            . 'Ish vaqti: ' . e(setting('work_open')) . ' – ' . e(setting('work_close')), bot_menu());
        return;
    }
    if ($text === '📦 Buyurtmalarim' || $text === '/orders') {
        $list = rows("SELECT * FROM orders WHERE customer_id = ? AND status <> 'pending_pay' ORDER BY id DESC LIMIT 5", [$cust['id']]);
        if (!$list) {
            tg_send($chat, "Sizda hali buyurtma yo'q. «🍔 Buyurtma berish» tugmasini bosing.", bot_menu());
            return;
        }
        foreach (array_reverse($list) as $o) {
            tg_send($chat, tg_order_text($o) . "\n🕐 " . date('d.m.Y H:i', strtotime($o['created_at'])));
        }
        return;
    }
    if ($text === "📞 Bog'lanish" || $text === '/contact') {
        tg_send($chat, setting('contact_text') ?: $brand, bot_menu());
        return;
    }
    tg_send($chat, 'Buyurtma berish uchun «🍔 Buyurtma berish» tugmasini bosing.', bot_menu());
}

/** Admin xabaridagi tugmalar: buyurtmani qabul qilish / rad etish (faqat admin ID lar uchun). */
function bot_callback(array $cb): void
{
    $from = (string)($cb['from']['id'] ?? '');
    $data = (string)($cb['data'] ?? '');
    $msg = $cb['message'] ?? null;
    if (!in_array($from, tg_admin_ids(), true)) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Ruxsat yo'q"]);
        return;
    }
    if (!preg_match('~^o:(\d+):(acc|rej|rejr):?(\d*)$~', $data, $mm)) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        return;
    }
    $id = (int)$mm[1];
    $reasons = ['Mahsulot tugagan', 'Yetkazish hududidan tashqari', 'Ish vaqti tugadi', "Mijoz bilan bog'lanib bo'lmadi"];
    $note = '';
    try {
        if ($mm[2] === 'acc') {
            tg_order_set_status($id, 'accepted');
            $note = "✅ Qabul qilindi. Oshxona cheki kassada avtomatik chiqadi.";
        } elseif ($mm[2] === 'rej') {
            $kb = array_map(fn($r, $i) => [['text' => $r, 'callback_data' => "o:$id:rejr:$i"]], $reasons, array_keys($reasons));
            tg_api('editMessageReplyMarkup', ['chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id'], 'reply_markup' => ['inline_keyboard' => $kb]]);
            tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Sababni tanlang']);
            return;
        } else {
            tg_order_set_status($id, 'rejected', $reasons[(int)$mm[3]] ?? 'Rad etildi');
            $note = '❌ Rad etildi';
        }
    } catch (UserError $e) {
        $note = '⚠️ ' . $e->getMessage();
    }
    tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => mb_substr($note, 0, 190)]);
    $o = order_get($id);
    if ($o && $msg) {
        tg_api('editMessageText', ['chat_id' => $msg['chat']['id'], 'message_id' => $msg['message_id'], 'parse_mode' => 'HTML',
            'text' => tg_order_text($o, true) . "\n\n" . e($note)]);
    }
}
