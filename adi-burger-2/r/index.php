<?php
declare(strict_types=1);

/**
 * ONLAYN CHEK. Mijoz chekidagi QR-kod shu sahifani ochadi: /r/?o=<buyurtma UUID>
 * UUID tasodifiy (taxmin qilib bo'lmaydi). Sahifada telefon va manzil ko'rsatilmaydi.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();
security_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$uuid = strtolower(trim((string)($_GET['o'] ?? '')));
$o = null;
$err = '';
if (!rate_limit('rcpt:' . client_ip(), 60, 60)) {
    http_response_code(429);
    $err = "Juda ko'p so'rov. Bir daqiqadan keyin urinib ko'ring.";
} elseif (!is_uuid($uuid)) {
    http_response_code(404);
    $err = "Chek topilmadi. QR-kodni qayta skanerlang.";
} else {
    $o = row('SELECT o.*, t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id WHERE o.uuid = ?', [$uuid]);
    if (!$o) {
        http_response_code(404);
        $err = "Chek hali serverga yetib kelmagan. Bir-ikki daqiqadan keyin sahifani yangilang.";
    }
}

$brand = setting('brand_name') ?: 'ADI BURGER';
$base = base_path();
$items = $o ? rows('SELECT name, price, qty FROM order_items WHERE order_id = ? AND qty > 0 ORDER BY id', [$o['id']]) : [];
$pays = $o ? rows("SELECT method, amount, received, change_amount FROM payments WHERE order_id = ? AND status = 'ok' ORDER BY id", [$o['id']]) : [];
$PAY = ['cash' => 'Naqd', 'terminal' => 'Terminal (karta)', 'click' => 'Click', 'card_transfer' => "Kartaga o'tkazma", 'hamyon' => 'Hamyon'];
$TYPE = ['table' => 'Stolda', 'takeaway' => 'Olib ketish', 'phone' => 'Olib ketish', 'delivery' => 'Yetkazib berish'];
$ST = ['done' => ["To'langan", 'ok'], 'cancelled' => ['Bekor qilingan', 'bad'], 'rejected' => ['Rad etilgan', 'bad']];
$n = static fn($v) => number_format((int)$v, 0, '.', ' ');
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f56a00">
<title><?= $o ? 'Chek #' . e($o['number']) . ' · ' : '' ?><?= e($brand) ?></title>
<link rel="icon" href="<?= e($base) ?>/pos/icons/favicon.png">
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f1ee;color:#26221e;font:16px/1.4 "Segoe UI",system-ui,-apple-system,Roboto,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:22px 12px 40px}
.logo{height:64px;margin:4px 0 16px;animation:drop .6s cubic-bezier(.2,.9,.3,1.3) both}
.paper{width:100%;max-width:420px;background:#fff;border-radius:18px 18px 0 0;padding:22px 20px 26px;box-shadow:0 14px 40px rgba(60,40,20,.12);position:relative;animation:rise .55s .12s cubic-bezier(.2,.8,.3,1) both}
.paper:after{content:"";position:absolute;left:0;right:0;bottom:-11px;height:12px;background:linear-gradient(-45deg,transparent 8px,#fff 0) 0 0/16px 12px,linear-gradient(45deg,transparent 8px,#fff 0) 0 0/16px 12px}
h1{margin:0;font-size:24px;text-align:center}
.sub{text-align:center;color:#8b8177;font-size:14px;margin-top:3px}
.st{display:inline-block;margin:10px auto 0;padding:5px 14px;border-radius:20px;font-weight:700;font-size:13px;background:#fff1d6;color:#8a5a00}
.st.ok{background:#e3f6d6;color:#35730c}.st.bad{background:#fde3e0;color:#b3261a}
.c{text-align:center}
hr{border:0;border-top:2px dashed #e4ddd5;margin:16px 0}
.it{display:grid;grid-template-columns:1fr auto;gap:2px 12px;padding:7px 0;animation:fade .4s both}
.it b{font-weight:600}.it small{grid-column:1;color:#8b8177;font-size:13px}.it .s{grid-row:1/3;grid-column:2;align-self:center;font-weight:700;font-variant-numeric:tabular-nums}
.kv{display:flex;justify-content:space-between;gap:12px;padding:4px 0;color:#5c544c;font-variant-numeric:tabular-nums}
.tot{display:flex;justify-content:space-between;align-items:baseline;font-size:22px;font-weight:800;margin-top:6px}
.tot span:last-child{color:#f56a00}
.foot{color:#8b8177;font-size:13px;text-align:center;margin-top:30px;max-width:420px}
.foot a{color:#f56a00;font-weight:700;text-decoration:none}
.err{font-size:17px;text-align:center;padding:26px 6px}
@keyframes drop{from{opacity:0;transform:translateY(-18px) scale(.9)}}@keyframes rise{from{opacity:0;transform:translateY(26px)}}@keyframes fade{from{opacity:0;transform:translateX(-8px)}}
@media print{body{background:#fff;padding:0}.paper{box-shadow:none}.foot{display:none}}
</style>
</head>
<body>
<img class="logo" src="<?= e($base) ?>/pos/icons/logo.png" alt="<?= e($brand) ?>">
<main class="paper">
<?php if (!$o): ?>
  <div class="err">🧾<br><?= e($err) ?></div>
<?php else:
    [$stl, $stc] = $ST[$o['status']] ?? [$o['pay_status'] === 'paid' ? "To'langan" : "To'lov kutilmoqda", $o['pay_status'] === 'paid' ? 'ok' : ''];
    $when = $o['closed_at'] ?: $o['created_at'];
?>
  <h1>Chek #<?= e($o['number']) ?></h1>
  <div class="sub"><?= e(date('d.m.Y H:i', strtotime((string)$when))) ?> · <?= e($TYPE[$o['type']] ?? '') ?><?= $o['type'] === 'table' && $o['table_name'] ? ' · ' . e(preg_match('~^\d+$~', $o['table_name']) ? 'Stol ' . $o['table_name'] : $o['table_name']) : '' ?></div>
  <div class="c"><span class="st <?= e($stc) ?>"><?= e($stl) ?></span></div>
  <hr>
  <?php foreach ($items as $i => $it): ?>
  <div class="it" style="animation-delay:<?= 0.25 + $i * 0.05 ?>s"><b><?= e($it['name']) ?></b><small><?= (int)$it['qty'] ?> × <?= $n($it['price']) ?></small><span class="s"><?= $n($it['price'] * $it['qty']) ?></span></div>
  <?php endforeach; ?>
  <hr>
  <?php if ((int)$o['delivery_fee'] > 0): ?>
  <div class="kv"><span>Taomlar</span><span><?= $n($o['items_total']) ?></span></div>
  <div class="kv"><span>Yetkazib berish</span><span><?= $n($o['delivery_fee']) ?></span></div>
  <?php endif; ?>
  <div class="tot"><span>Jami</span><span><?= $n($o['total']) ?> so'm</span></div>
  <?php if ($pays): ?>
  <hr>
  <?php foreach ($pays as $p): ?>
  <div class="kv"><span><?= e($PAY[$p['method']] ?? $p['method']) ?></span><span><?= $n($p['amount']) ?></span></div>
  <?php if ((int)$p['change_amount'] > 0): ?><div class="kv"><span>Berildi / qaytim</span><span><?= $n($p['received']) ?> / <?= $n($p['change_amount']) ?></span></div><?php endif; ?>
  <?php endforeach; endif; ?>
<?php endif; ?>
</main>
<div class="foot">
  <b><?= e($brand) ?></b><?= setting('brand_address') ? ' · ' . e(setting('brand_address')) : '' ?><?= setting('brand_phone') ? '<br>Tel: ' . e(setting('brand_phone')) : '' ?>
  <br><br>Xaridingiz uchun rahmat! · <a href="<?= e($base) ?>/menu/">Menyuni ko'rish</a>
</div>
</body>
</html>
