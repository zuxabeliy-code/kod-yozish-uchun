<?php
declare(strict_types=1);
defined('ADI') || exit;

/** Standart sozlamalar. Admin panelda o'zgartiriladi. */
function settings_defaults(): array
{
    return [
        'brand_name' => 'ADI BURGER',
        'brand_phone' => '',
        'brand_address' => '',
        'receipt_footer' => 'Xaridingiz uchun rahmat!',
        'paper_width' => '80',
        'kitchen_auto' => '1',
        'customer_auto' => '1',
        'kitchen_copies' => '1',
        'customer_copies' => '1',
        'delivery_fee' => '10000',
        'min_order' => '0',
        'work_open' => '09:00',
        'work_close' => '23:59',
        'bot_enabled' => '0',
        'bot_token' => '',
        'bot_username' => '',
        'admin_tg_ids' => '',
        'webhook_secret' => '',
        'contact_text' => "📞 Telefon: \n📍 Manzil: ",
        'bot_pickup' => '1',
        'bot_delivery' => '1',
        'pay_cash_bot' => '1',
        'pay_card_bot' => '0',
        'cards' => '[]',
        'pay_hamyon' => '0',
        'hamyon_shop_id' => '',
        'hamyon_shop_key' => '',
        'hamyon_api_url' => 'https://hamyon-api.uz',
        'pos_pay_cash' => '1',
        'pos_pay_terminal' => '1',
        'pos_pay_click' => '1',
        'catalog_version' => '1',
        'last_backup_at' => '',
        'maintenance_at' => '0',
    ];
}

/** Shifrlab saqlanadigan maxfiy sozlamalar. */
const SECRET_SETTINGS = ['bot_token', 'hamyon_shop_key', 'webhook_secret'];

function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $cache = settings_defaults();
        try {
            foreach (rows('SELECT k, v FROM settings') as $r) {
                $cache[$r['k']] = (string)$r['v'];
            }
        } catch (Throwable) {
            // Baza hali tayyor emas — standart qiymatlar
        }
    }
    return $cache;
}

function setting(string $k): string
{
    $v = settings_all()[$k] ?? '';
    if (in_array($k, SECRET_SETTINGS, true) && $v !== '') {
        return secret_decrypt($v);
    }
    return $v;
}

function setting_int(string $k): int
{
    return (int)setting($k);
}

function setting_on(string $k): bool
{
    return setting($k) === '1';
}

function setting_set(string $k, string $v): void
{
    if (in_array($k, SECRET_SETTINGS, true) && $v !== '') {
        $v = secret_encrypt($v);
    }
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    settings_all(true);
}

function catalog_bump(): void
{
    q("INSERT INTO settings (k, v) VALUES ('catalog_version', '2') ON DUPLICATE KEY UPDATE v = CAST(v AS UNSIGNED) + 1");
    settings_all(true);
}

function secret_key(): string
{
    $s = (string)cfg('app.secret', '');
    if ($s === '') {
        throw new RuntimeException('config: app.secret yo\'q');
    }
    return hash('sha256', 'adi-settings|' . $s, true);
}

function secret_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc1:' . base64_encode($iv . $tag . $c);
}

function secret_decrypt(string $v): string
{
    if (!str_starts_with($v, 'enc1:')) {
        return $v;
    }
    $raw = base64_decode(substr($v, 5), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

/** Maxfiy qiymatni ekranda ko'rsatish uchun: "1234…wxyz" */
function mask_secret(string $v): string
{
    if ($v === '') {
        return '';
    }
    return strlen($v) <= 8 ? '••••' : substr($v, 0, 4) . '••••••' . substr($v, -4);
}

function cards_list(): array
{
    $c = json_decode(setting('cards'), true);
    return is_array($c) ? array_values(array_filter($c, fn($x) => !empty($x['number']))) : [];
}

function is_work_time(): bool
{
    $now = date('H:i');
    $o = setting('work_open');
    $c = setting('work_close');
    return $o <= $c ? ($now >= $o && $now <= $c) : ($now >= $o || $now <= $c);
}
