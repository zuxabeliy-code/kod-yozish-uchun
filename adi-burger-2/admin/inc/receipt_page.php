<?php
declare(strict_types=1);
defined('ADI') || exit;

/** Logo raster ko'rinishi (jonli ko'rinish uchun): ?p=logo_raster&pct=60&dark=60 */
function page_logo_raster(array $me): void
{
    $src = setting('receipt_logo_src');
    if ($src === '' || !is_file(ROOT . '/' . $src)) {
        json_out(['ok' => false, 'error' => 'Logo yuklanmagan'], 404);
    }
    try {
        json_out(['ok' => true, 'logo' => rc_logo_raster(ROOT . '/' . $src, max(10, min(100, (int)($_GET['pct'] ?? 60))), max(0, min(100, (int)($_GET['dark'] ?? 60))))]);
    } catch (UserError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function page_receipt(array $me): void
{
    $d = rc_design();
    $logo = rc_logo();
    $src = setting('receipt_logo_src');
    $hasLogo = $src !== '' && is_file(ROOT . '/' . $src);
    $kinds = [];
    foreach (RC_KINDS as $k => [$label, $where]) {
        $kinds[$k] = [$label, $where];
    }
    $data = json_encode(['design' => $d, 'logo' => $logo, 'kinds' => $kinds, 'has_logo' => $hasLogo,
        'brand' => ['name' => setting('brand_name'), 'address' => setting('brand_address'), 'phone' => setting('brand_phone')]],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $range = fn(string $id, string $label, int $min, int $max, int $step, int $val) => '<div class="field"><label>' . e($label) . ' <b id="' . $id . '-v"></b></label><input type="range" id="' . $id . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . $val . '"></div>';

    $h = '<div class="note" style="margin-bottom:14px">Chekni o\'zingiz xohlagandek sozlang. Bloklarni <b>▲ ▼</b> bilan suring, yoqing/o\'chiring, tekislash va o\'lchamini tanlang, o\'zingizning matn va logongizni qo\'shing. O\'ng tomonda chek <b>darhol</b> o\'zgaradi. Saqlagandan keyin kassa yangi dizaynda chop etadi.</div>';
    $h .= '<div class="rc-layout"><div class="rc-left">';

    $h .= '<div class="card"><h3>Umumiy sozlamalar</h3><div class="row2">'
        . '<div class="field"><label>Chek eni (printerdagi qog\'oz bilan bir xil)</label><select id="g-paper"><option value="80"' . ($d['paper'] === 80 ? ' selected' : '') . '>80 mm</option><option value="58"' . ($d['paper'] === 58 ? ' selected' : '') . '>58 mm</option></select></div>'
        . '<div class="field"><label>Shrift</label><select id="g-font"><option value="a"' . ($d['font'] === 'a' ? ' selected' : '') . '>Oddiy (A)</option><option value="b"' . ($d['font'] === 'b' ? ' selected' : '') . '>Kichik (B) — qatorga ko\'proq belgi</option></select></div></div>'
        . $range('g-margin', 'Chap va o\'ng chet', 0, 15, 1, $d['margin_mm']) . $range('g-line', 'Qatorlar orasi', 50, 200, 5, $d['line_pct'])
        . '<label class="check"><input type="checkbox" id="g-bold"' . ($d['bold_all'] ? ' checked' : '') . '> Hamma yozuvni qalin qilish (och chiqayotgan bo\'lsa)</label></div>';

    // Logo
    $h .= '<div class="card"><h3>Logo</h3>';
    if ($hasLogo) {
        $h .= '<p class="muted small">Logo yuklangan. Chekda 1 rangli (oq-qora) bo\'lib chiqadi.</p>' . $range('l-pct', 'Logo kattaligi (qog\'oz eniga nisbatan)', 10, 100, 5, $d['logo']['pct']) . $range('l-dark', 'Logo qoraligi (100% — eng to\'q)', 0, 100, 5, $d['logo']['dark'])
            . '<div class="btns" style="margin-bottom:10px">' . str_replace('<form ', '<form data-keepdesign ', action_btn('receipt.logo_delete', [], '🗑 Logoni olib tashlash', 'btn sm danger', 'Logoni olib tashlaysizmi?')) . '</div>';
    } else {
        $h .= '<p class="muted small">Logo hali yo\'q. PNG yoki JPG yuklang (oq fonda, oddiy shakl yaxshi chiqadi). Keyin pastdagi blok ro\'yxatida «Logo» blokini yoqing.</p>'
            . (extension_loaded('gd') ? '' : '<div class="flash bad">Serverda GD kengaytmasi yo\'q — logo qo\'shib bo\'lmaydi. Hosting panelida GD ni yoqing.</div>');
    }
    $h .= form_open('receipt.logo', [], 'enctype="multipart/form-data" class="btns" data-keepdesign') . '<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required style="max-width:260px"><button class="btn primary">⬆ ' . ($hasLogo ? 'Boshqa logo yuklash' : 'Logo yuklash') . '</button></form></div>';

    foreach (['kitchen' => ['Oshxona cheki', 'Tasdiqlashda oshxonaga chiqadi. Hozir faqat taom nomi va soni (narxsiz). Kerak bo\'lsa boshqa bloklarni yoqing.', 'k-copies'],
        'customer' => ['Mijoz cheki', 'To\'lovda chiqadi. Mijoz ma\'lumoti (ism, telefon, manzil) kiritilgan bo\'lsa chekda ko\'rinadi.', 'c-copies']] as $w => [$title, $hint, $cid]) {
        $h .= '<div class="card"><h3>' . e($title) . '</h3><p class="muted small">' . e($hint) . '</p><div class="field" style="max-width:220px"><label>Nusxalar soni</label><select id="' . $cid . '">';
        for ($i = 1; $i <= 3; $i++) {
            $h .= '<option value="' . $i . '"' . ($d[$w]['copies'] === $i ? ' selected' : '') . '>' . $i . '</option>';
        }
        $h .= '</select></div><div id="blocks-' . $w . '" class="blocks"></div><div class="field" style="margin-top:10px;max-width:320px"><select id="add-' . $w . '"></select></div></div>';
    }

    $h .= form_open('receipt.save', [], 'id="rc-form"') . '<input type="hidden" name="design" id="rc-design"><div class="btns"><button class="btn primary">💾 Saqlash</button></div></form>'
        . '<div class="btns" style="margin-top:10px">' . action_btn('receipt.reset', [], '↺ Standart holatga qaytarish', 'btn sm', 'Hamma sozlama standart holatga qaytadi. Davom etasizmi?') . '</div>';

    // O'ng: jonli ko'rinish
    $h .= '</div><div class="rc-right"><div class="card sticky"><h3><span id="rc-preview-title">Ko\'rinish</span></h3>'
        . '<div class="filters" style="margin-bottom:8px"><button type="button" class="btn sm" data-tab="kitchen">Oshxona</button><button type="button" class="btn sm" data-tab="customer">Mijoz</button></div>'
        . '<div class="filters" style="margin-bottom:10px"><button type="button" class="btn sm" data-sample="table">Stolda</button><button type="button" class="btn sm" data-sample="takeaway">Olib ketish</button><button type="button" class="btn sm" data-sample="delivery">Yetkazish</button></div>'
        . '<div class="paper-wrap"><div id="rc-preview"></div></div>'
        . '<div class="btns" style="margin-top:12px"><button type="button" class="btn sm" id="rc-print">🖨 Namuna chek chiqarish</button><button type="button" class="btn sm" id="rc-ruler">📏 O\'lchov cheki</button></div>'
        . '<p class="muted small">Printer tugmalari faqat kassa monoblokida ishlaydi. Ko\'rinish taxminiy: haqiqiy chekda shrift printer modeliga bog\'liq. Avval «O\'lchov cheki»ni chiqarib chizg\'ich bilan tekshiring.</p></div></div></div>';
    $h .= '<script type="application/json" id="rc-data">' . $data . '</script><script type="module" src="receipt.js?v=' . ADI_VERSION . '"></script>';
    layout('receipt', 'Chek dizayni', $h, $me);
}
