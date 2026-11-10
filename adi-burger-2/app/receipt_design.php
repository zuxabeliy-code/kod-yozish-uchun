<?php
declare(strict_types=1);
defined('ADI') || exit;

/*
 * CHEK DIZAYNI
 * Oshxona va mijoz cheki "bloklar" ro'yxatidan iborat. Har bir blokni yoqish/o'chirish, tartibini o'zgartirish,
 * tekislash (chap/o'rta/o'ng), o'lcham, qalinlik va matnni o'zgartirish mumkin. Logo — printer uchun 1-bitli rasmga aylantiriladi.
 * Dizayn JSON sifatida settings.receipt_design da saqlanadi, kassa uni katalog bilan oladi.
 */

const RC_PAPER_DOTS = 576; // 80 mm printer, 203 dpi. 58 mm da printer xizmati proporsional kichraytiradi.
const RC_KINDS = [
    'logo' => ['Logo', 'kc'],
    'text' => ['Matn (o\'zingiz yozasiz)', 'kc'],
    'shop' => ['Do\'kon nomi', 'c'],
    'shop_info' => ['Do\'kon manzili va telefoni', 'c'],
    'type_line' => ['Buyurtma turi (STOL / OLIB KETISH / DOSTAVKA)', 'kc'],
    'order_no' => ['Buyurtma raqami', 'kc'],
    'datetime' => ['Sana va vaqt', 'kc'],
    'cashier' => ['Kassir ismi', 'kc'],
    'customer' => ['Mijoz: ism, telefon, manzil', 'kc'],
    'comment' => ['Buyurtma izohi', 'kc'],
    'items' => ['Taomlar', 'kc'],
    'delivery_fee' => ['Yetkazish narxi', 'c'],
    'total' => ['Jami summa', 'c'],
    'payments' => ["To'lov turi va qaytim", 'c'],
    'qr' => ['QR-kod (onlayn chek)', 'c'],
    'separator' => ['Chiziq', 'kc'],
    'feed' => ["Bo'sh qatorlar", 'kc'],
];

function rc_block(string $id, string $kind, bool $on, string $align = 'l', int $size = 1, bool $bold = false, array $extra = []): array
{
    return array_merge(['id' => $id, 'kind' => $kind, 'on' => $on, 'align' => $align, 'size' => $size, 'bold' => $bold, 'text' => '', 'ch' => '-', 'n' => 1, 'o' => []], $extra);
}

function rc_default_design(): array
{
    $cust = ['name' => true, 'phone' => true, 'address' => true];
    $footer = trim(setting('receipt_footer')) ?: 'Xaridingiz uchun rahmat!';
    return [
        'v' => 2,
        'paper' => setting('paper_width') === '58' ? 58 : 80,
        'font' => 'a',
        'line_pct' => 100,
        'margin_mm' => 0,
        'bold_all' => false,
        'logo' => ['pct' => 60, 'dark' => 60],
        // Oshxona cheki: katta buyurtma raqami, taom nomi va soni. Qolganlarini kerak bo'lsa yoqing.
        'kitchen' => ['copies' => 1, 'blocks' => [
            rc_block('k_logo', 'logo', false, 'c'),
            rc_block('k_title', 'text', false, 'c', 2, true, ['text' => 'OSHXONA']),
            rc_block('k_type', 'type_line', false, 'c', 2, true),
            rc_block('k_no', 'order_no', true, 'c', 2, true),
            rc_block('k_time', 'datetime', false),
            rc_block('k_cashier', 'cashier', false),
            rc_block('k_sep1', 'separator', false, 'l', 1, false, ['ch' => '=']),
            rc_block('k_items', 'items', true, 'l', 2, true, ['o' => ['price' => false]]),
            rc_block('k_sep2', 'separator', false, 'l', 1, false, ['ch' => '=']),
            rc_block('k_comment', 'comment', true, 'l', 1, true),
            rc_block('k_customer', 'customer', false, 'l', 1, false, ['o' => $cust]),
        ]],
        // Mijoz cheki (to'lovda): mijoz ma'lumoti bor bo'lsa chiqadi.
        'customer' => ['copies' => 1, 'blocks' => [
            rc_block('c_logo', 'logo', true, 'c'),
            rc_block('c_shop', 'shop', true, 'c', 2, true),
            rc_block('c_info', 'shop_info', true, 'c'),
            rc_block('c_sep1', 'separator', true),
            rc_block('c_no', 'order_no', true, 'l', 1, true),
            rc_block('c_type', 'type_line', true),
            rc_block('c_time', 'datetime', true),
            rc_block('c_cashier', 'cashier', true),
            rc_block('c_sep2', 'separator', true),
            rc_block('c_customer', 'customer', true, 'l', 1, false, ['o' => $cust]),
            rc_block('c_items', 'items', true, 'l', 1, false, ['o' => ['price' => true]]),
            rc_block('c_comment', 'comment', false),
            rc_block('c_fee', 'delivery_fee', true),
            rc_block('c_sep3', 'separator', true, 'l', 1, false, ['ch' => '=']),
            rc_block('c_total', 'total', true, 'l', 2, true),
            rc_block('c_pay', 'payments', true),
            rc_block('c_sep4', 'separator', true),
            rc_block('c_qr', 'qr', true, 'c', 1, false, ['text' => 'Onlayn chek: QR-kodni skanerlang']),
            rc_block('c_thanks', 'text', true, 'c', 1, true, ['text' => $footer]),
        ]],
    ];
}

/** Kelgan dizaynni xavfsiz chegaralarga keltiradi (bilmagan blok turlari va noto'g'ri qiymatlar tashlab yuboriladi). */
function rc_sanitize(array $in): array
{
    $def = rc_default_design();
    $clean = static fn($s, int $len) => mb_substr(trim(preg_replace('~[\x00-\x08\x0B-\x1F\x7F]~u', '', (string)$s) ?? ''), 0, $len);
    $out = [
        'v' => 2,
        'paper' => (int)($in['paper'] ?? $def['paper']) === 58 ? 58 : 80,
        'font' => ($in['font'] ?? 'a') === 'b' ? 'b' : 'a',
        'line_pct' => max(50, min(200, (int)($in['line_pct'] ?? 100))),
        'margin_mm' => max(0, min(15, (int)($in['margin_mm'] ?? 0))),
        'bold_all' => !empty($in['bold_all']),
        'logo' => ['pct' => max(10, min(100, (int)($in['logo']['pct'] ?? 60))), 'dark' => max(0, min(100, (int)($in['logo']['dark'] ?? 60)))],
    ];
    foreach (['kitchen' => 'k', 'customer' => 'c'] as $which => $pfx) {
        $src = is_array($in[$which] ?? null) ? $in[$which] : $def[$which];
        $blocks = [];
        $seen = [];
        foreach (array_slice(is_array($src['blocks'] ?? null) ? $src['blocks'] : [], 0, 40) as $b) {
            if (!is_array($b) || !isset(RC_KINDS[$b['kind'] ?? ''])) {
                continue;
            }
            $kind = $b['kind'];
            $id = preg_replace('~[^a-z0-9_]~', '', strtolower((string)($b['id'] ?? ''))) ?: ($pfx . '_' . $kind);
            while (isset($seen[$id])) {
                $id .= 'x';
            }
            $seen[$id] = true;
            $o = is_array($b['o'] ?? null) ? $b['o'] : [];
            $blocks[] = [
                'id' => substr($id, 0, 24), 'kind' => $kind, 'on' => !empty($b['on']),
                'align' => in_array($b['align'] ?? 'l', ['l', 'c', 'r'], true) ? $b['align'] : 'l',
                'size' => (int)($b['size'] ?? 1) === 2 ? 2 : 1, 'bold' => !empty($b['bold']),
                'text' => in_array($kind, ['text', 'qr'], true) ? $clean($b['text'] ?? '', 200) : '',
                'ch' => in_array($b['ch'] ?? '-', ['-', '=', '*', '_', '.'], true) ? $b['ch'] : '-',
                'n' => max(1, min(5, (int)($b['n'] ?? 1))),
                'o' => ['price' => !empty($o['price']), 'name' => !array_key_exists('name', $o) || !empty($o['name']), 'phone' => !array_key_exists('phone', $o) || !empty($o['phone']), 'address' => !array_key_exists('address', $o) || !empty($o['address'])],
            ];
        }
        $out[$which] = ['copies' => max(1, min(3, (int)($src['copies'] ?? 1))), 'blocks' => $blocks ?: $def[$which]['blocks']];
    }
    return $out;
}

/** Saqlangan dizayn (yoki standart). */
function rc_design(): array
{
    $raw = json_decode(setting('receipt_design'), true);
    if (!is_array($raw)) {
        return rc_default_design();
    }
    $d = rc_sanitize($raw);
    // v1 dan qolgan dizayn: mijoz chekiga QR-kod bloki qo'shiladi (keyin dizaynerda o'chirish mumkin)
    if ((int)($raw['v'] ?? 1) < 2 && !in_array('qr', array_column($d['customer']['blocks'], 'kind'), true)) {
        $qr = rc_block('c_qr', 'qr', true, 'c', 1, false, ['text' => 'Onlayn chek: QR-kodni skanerlang']);
        $n = count($d['customer']['blocks']);
        $last = $n ? $d['customer']['blocks'][$n - 1] : null;
        $pos = ($last && $last['kind'] === 'text') ? $n - 1 : $n;
        array_splice($d['customer']['blocks'], $pos, 0, [$qr]);
    }
    return $d;
}

function rc_logo(): ?array
{
    $l = json_decode(setting('receipt_logo'), true);
    return is_array($l) && !empty($l['b64']) ? $l : null;
}

/**
 * Logoni printer rasmiga aylantiradi: oq-qora (1 bit), qatorda 8 pikselga karrali kenglik.
 * $pct — qog'oz eniga nisbatan kattaligi, $dark — qoraligi (100 = eng to'q).
 */
function rc_logo_raster(string $file, int $pct, int $dark): array
{
    if (!extension_loaded('gd')) {
        throw new UserError("Serverda GD kengaytmasi yo'q — logo qo'shib bo'lmaydi. Hosting panelida GD ni yoqing.");
    }
    $info = @getimagesize($file);
    if (!$info) {
        throw new UserError("Logo rasmini o'qib bo'lmadi.");
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($file),
        default => false,
    };
    if (!$src) {
        throw new UserError('Logo formati qo\'llanmaydi. PNG yoki JPG yuklang.');
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $w = max(8, (int)(round(RC_PAPER_DOTS * max(10, min(100, $pct)) / 100 / 8) * 8));
    $h = (int)max(1, round($sh * $w / $sw));
    if ($h > 360) {
        $w = max(8, (int)(floor($w * 360 / $h / 8) * 8));
        $h = (int)max(1, round($sh * $w / $sw));
    }
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    $thr = (int)(40 + max(0, min(100, $dark)) * 2.1);
    $rowBytes = intdiv($w, 8);
    $data = '';
    for ($y = 0; $y < $h; $y++) {
        for ($bx = 0; $bx < $rowBytes; $bx++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $c = imagecolorat($dst, $bx * 8 + $bit, $y);
                $lum = (int)(0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255));
                if ($lum < $thr) {
                    $byte |= 0x80 >> $bit;
                }
            }
            $data .= chr($byte);
        }
    }
    return ['w' => $w, 'h' => $h, 'b64' => base64_encode($data)];
}

/** Admin yuklagan logoni PNG qilib saqlaydi va printer rasmini yangilaydi. */
function rc_logo_save_upload(array $f): void
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1024 * 1024) {
        throw new UserError('Logo yuklanmadi (5 MB gacha PNG/JPG).');
    }
    $raster = rc_logo_raster($f['tmp_name'], rc_design()['logo']['pct'], rc_design()['logo']['dark']); // formatni tekshiradi
    $dir = ROOT . '/uploads/logo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $info = getimagesize($f['tmp_name']);
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG => imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($f['tmp_name']),
        default => imagecreatefromgif($f['tmp_name']),
    };
    $name = 'logo-' . bin2hex(random_bytes(4)) . '.png';
    imagesavealpha($img, true);
    imagepng($img, "$dir/$name");
    foreach (glob("$dir/logo-*.png") ?: [] as $old) {
        if (basename($old) !== $name) {
            @unlink($old);
        }
    }
    setting_set('receipt_logo_src', "uploads/logo/$name");
    setting_set('receipt_logo', json_encode($raster));
}

function rc_logo_refresh(): void
{
    $src = setting('receipt_logo_src');
    if ($src !== '' && is_file(ROOT . '/' . $src)) {
        $d = rc_design();
        setting_set('receipt_logo', json_encode(rc_logo_raster(ROOT . '/' . $src, $d['logo']['pct'], $d['logo']['dark'])));
    }
}
