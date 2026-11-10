<?php
declare(strict_types=1);

/**
 * QR MENYU. Stoldagi QR-kod shu sahifani ochadi: /menu/?t=<stol raqami>
 * Faqat menyuni ko'rsatadi (buyurtma berilmaydi). Telegram shart emas — oddiy brauzerda ochiladi.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_installed();
security_headers();
header('Cache-Control: public, max-age=60');

$brand = setting('brand_name') ?: 'ADI BURGER';
$base = base_path();
$cats = rows('SELECT id, name, icon FROM categories WHERE is_active = 1 ORDER BY sort, id');
$prods = rows('SELECT id, category_id, name, description, price, image, is_available FROM products WHERE is_active = 1 AND in_bot = 1 ORDER BY sort, id');
$by = [];
foreach ($prods as $p) {
    $by[(int)$p['category_id']][] = $p;
}
$cats = array_values(array_filter($cats, static fn($c) => !empty($by[(int)$c['id']])));
$table = preg_replace('~[^\p{L}\p{N} .\-]~u', '', mb_substr((string)($_GET['t'] ?? ''), 0, 30));
$bot = ltrim(setting('bot_username'), '@');
$n = static fn($v) => number_format((int)$v, 0, '.', ' ');
?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#16110d">
<title><?= e($brand) ?> — menyu</title>
<link rel="icon" href="<?= e($base) ?>/pos/icons/favicon.png">
<style>
:root{--o:#ff6a00;--o2:#ff8f3a;--bg:#14100c;--card:#211a14;--line:#33291f;--tx:#fff6ec;--mu:#b5a595}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth;scroll-padding-top:74px}
body{margin:0;background:var(--bg);color:var(--tx);font:16px/1.4 "Segoe UI",system-ui,-apple-system,Roboto,sans-serif;overflow-x:hidden}
/* Kirish animatsiyasi */
.splash{position:fixed;inset:0;z-index:50;background:radial-gradient(circle at 50% 42%,#3a2413,#14100c 68%);display:grid;place-items:center;animation:splashOut .55s 1.55s cubic-bezier(.7,0,.3,1) forwards}
.splash img{width:min(68vw,330px);animation:logoIn 1s cubic-bezier(.2,.9,.25,1.25) both,glow 1.6s ease-in-out}
.splash i{position:absolute;bottom:22vh;width:120px;height:4px;border-radius:4px;background:#3a2b1e;overflow:hidden}
.splash i:after{content:"";position:absolute;inset:0;background:var(--o);transform:translateX(-100%);animation:bar 1.3s .2s ease forwards}
@keyframes logoIn{0%{opacity:0;transform:scale(.55) rotate(-6deg)}60%{opacity:1}100%{transform:none}}
@keyframes glow{50%{filter:drop-shadow(0 0 26px rgba(255,106,0,.55))}}
@keyframes bar{to{transform:none}}
@keyframes splashOut{to{opacity:0;visibility:hidden;transform:scale(1.08)}}
/* Bosh qism */
.hero{position:relative;padding:34px 18px 26px;text-align:center;background:radial-gradient(120% 90% at 50% 0%,#4a2a10 0%,#22170e 55%,var(--bg) 100%);overflow:hidden}
.hero:before,.hero:after{content:"";position:absolute;border-radius:50%;background:var(--o);opacity:.14;filter:blur(40px);animation:float 9s ease-in-out infinite}
.hero:before{width:220px;height:220px;left:-70px;top:-60px}.hero:after{width:180px;height:180px;right:-60px;bottom:-80px;animation-delay:-4s}
@keyframes float{50%{transform:translate(18px,22px) scale(1.15)}}
.hero img{height:86px;position:relative;z-index:1;animation:rise .7s 1.7s both}
.hero p{margin:12px 0 0;color:var(--mu);font-size:15px;position:relative;z-index:1;animation:rise .7s 1.85s both}
.tbl{display:inline-block;margin-top:12px;padding:6px 16px;border-radius:20px;background:rgba(255,106,0,.16);color:var(--o2);font-weight:700;font-size:14px;border:1px solid rgba(255,106,0,.35);position:relative;z-index:1;animation:rise .7s 2s both}
/* Bo'limlar tasmasi */
.nav{position:sticky;top:0;z-index:20;background:rgba(20,16,12,.88);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--line);display:flex;gap:8px;overflow-x:auto;padding:12px 14px;scrollbar-width:none}
.nav::-webkit-scrollbar{display:none}
.nav a{flex:none;padding:9px 16px;border-radius:22px;background:var(--card);color:var(--mu);font-weight:600;font-size:14.5px;text-decoration:none;border:1px solid var(--line);transition:all .25s}
.nav a.on{background:var(--o);color:#fff;border-color:var(--o);box-shadow:0 6px 18px rgba(255,106,0,.4);transform:translateY(-1px)}
main{max-width:980px;margin:0 auto;padding:6px 14px 40px}
h2{font-size:23px;margin:26px 2px 12px;display:flex;align-items:center;gap:10px}
h2:after{content:"";flex:1;height:2px;border-radius:2px;background:linear-gradient(90deg,var(--o),transparent)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:12px}
.p{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;opacity:0;transform:translateY(26px) scale(.97);transition:opacity .55s ease,transform .55s cubic-bezier(.2,.8,.3,1),box-shadow .25s,border-color .25s}
.p.in{opacity:1;transform:none}
.p:active{transform:scale(.97);border-color:var(--o)}
.p .im{aspect-ratio:1/0.82;background:#17120d center/cover no-repeat;display:grid;place-items:center;font-size:52px;position:relative}
.p .im img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s}
.p:hover .im img{transform:scale(1.07)}
.p .b{padding:11px 12px 13px;display:flex;flex-direction:column;gap:5px;flex:1}
.p .n{font-weight:700;font-size:15.5px;line-height:1.2}
.p .d{color:var(--mu);font-size:12.5px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.p .pr{margin-top:auto;padding-top:6px;font-weight:800;font-size:17px;color:var(--o2);font-variant-numeric:tabular-nums}
.p .pr small{font-size:12px;font-weight:600;color:var(--mu)}
.p.off{filter:grayscale(.9);}
.p.off.in{opacity:.5}
.p.off .im:after{content:"Tugagan";position:absolute;top:10px;left:10px;background:#d33;color:#fff;font-size:12px;font-weight:700;padding:3px 9px;border-radius:10px}
/* Kattalashtirib ko'rish */
.sheet{position:fixed;inset:0;z-index:40;background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;opacity:0;visibility:hidden;transition:.3s}
.sheet.on{opacity:1;visibility:visible}
.sheet .box{width:100%;max-width:520px;background:var(--card);border-radius:26px 26px 0 0;overflow:hidden;transform:translateY(100%);transition:transform .4s cubic-bezier(.2,.85,.3,1);max-height:92vh;overflow-y:auto}
.sheet.on .box{transform:none}
.sheet .im{aspect-ratio:1/0.78;background:#17120d;display:grid;place-items:center;font-size:90px}
.sheet .im img{width:100%;height:100%;object-fit:cover;display:block}
.sheet .b{padding:18px 20px 26px}
.sheet .n{font-size:23px;font-weight:800}
.sheet .d{color:var(--mu);margin-top:8px}
.sheet .pr{font-size:26px;font-weight:800;color:var(--o2);margin-top:14px}
.sheet button{margin-top:18px;width:100%;height:52px;border:0;border-radius:16px;background:var(--o);color:#fff;font:700 16px inherit;font-family:inherit}
footer{text-align:center;color:var(--mu);font-size:13.5px;padding:10px 16px 44px;border-top:1px solid var(--line);margin-top:10px}
footer a{color:var(--o2);font-weight:700;text-decoration:none}
.empty{text-align:center;color:var(--mu);padding:60px 10px}
@keyframes rise{from{opacity:0;transform:translateY(18px)}}
@media (min-width:700px){.grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}.hero img{height:110px}}
@media (prefers-reduced-motion:reduce){*{animation-duration:.01s!important;animation-delay:0s!important;transition-duration:.01s!important}}
</style>
</head>
<body>
<div class="splash" aria-hidden="true"><img src="<?= e($base) ?>/pos/icons/logo.png" alt=""><i></i></div>
<header class="hero">
  <img src="<?= e($base) ?>/pos/icons/logo.png" alt="<?= e($brand) ?>">
  <p>Xush kelibsiz! Menyumiz bilan tanishing 🍔</p>
  <?php if ($table !== ''): ?><div class="tbl">Stol: <?= e($table) ?></div><?php endif; ?>
</header>
<?php if ($cats): ?>
<nav class="nav" id="nav">
  <?php foreach ($cats as $c): ?><a href="#c<?= (int)$c['id'] ?>" data-c="c<?= (int)$c['id'] ?>"><?= e(trim($c['icon'] . ' ' . $c['name'])) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<main>
<?php if (!$cats): ?><div class="empty">Menyu hozircha bo'sh.</div><?php endif; ?>
<?php foreach ($cats as $c): ?>
  <section id="c<?= (int)$c['id'] ?>">
    <h2><?= e(trim($c['icon'] . ' ' . $c['name'])) ?></h2>
    <div class="grid">
    <?php foreach ($by[(int)$c['id']] as $p): $img = $p['image'] ? $base . '/' . ltrim((string)$p['image'], '/') : ''; ?>
      <article class="p<?= $p['is_available'] ? '' : ' off' ?>" data-n="<?= e($p['name']) ?>" data-d="<?= e($p['description'] ?? '') ?>" data-p="<?= $n($p['price']) ?>" data-i="<?= e($img) ?>" data-e="<?= e($c['icon'] ?: '🍔') ?>">
        <div class="im"><?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><?= e($c['icon'] ?: '🍔') ?><?php endif; ?></div>
        <div class="b"><div class="n"><?= e($p['name']) ?></div><?php if (!empty($p['description'])): ?><div class="d"><?= e($p['description']) ?></div><?php endif; ?>
          <div class="pr"><?= $n($p['price']) ?> <small>so'm</small></div></div>
      </article>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</main>
<footer>
  <b><?= e($brand) ?></b><?= setting('brand_address') ? ' · ' . e(setting('brand_address')) : '' ?>
  <?= setting('brand_phone') ? '<br>Tel: <a href="tel:' . e(preg_replace('~[^\d+]~', '', setting('brand_phone'))) . '">' . e(setting('brand_phone')) . '</a>' : '' ?>
  <?= $bot !== '' ? '<br><br>Uyga buyurtma: <a href="https://t.me/' . e($bot) . '">Telegram botimiz @' . e($bot) . '</a>' : '' ?>
  <br><br>Buyurtma berish uchun ofitsiant yoki kassaga murojaat qiling.
</footer>
<div class="sheet" id="sheet"><div class="box"><div class="im" id="s-im"></div><div class="b"><div class="n" id="s-n"></div><div class="d" id="s-d"></div><div class="pr" id="s-p"></div><button type="button" id="s-x">Yopish</button></div></div></div>
<script>
(function () {
  // Kartalar ekranga kirganda birma-bir paydo bo'ladi
  var cards = [].slice.call(document.querySelectorAll('.p'));
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) {
      var k = 0;
      es.forEach(function (en) { if (en.isIntersecting) { en.target.style.transitionDelay = (k++ * 70) + 'ms'; en.target.classList.add('in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -6% 0px' });
    setTimeout(function () { cards.forEach(function (c) { io.observe(c); }); }, 1500);
  } else cards.forEach(function (c) { c.classList.add('in'); });
  // Faol bo'lim tasmada belgilanadi
  var links = [].slice.call(document.querySelectorAll('.nav a')), nav = document.getElementById('nav');
  function mark() {
    var cur = links[0];
    links.forEach(function (a) { var s = document.getElementById(a.dataset.c); if (s && s.getBoundingClientRect().top < 130) cur = a; });
    links.forEach(function (a) { a.classList.toggle('on', a === cur); });
    if (cur && nav) nav.scrollTo({ left: cur.offsetLeft - nav.clientWidth / 2 + cur.clientWidth / 2, behavior: 'smooth' });
  }
  var tick = 0;
  window.addEventListener('scroll', function () { if (!tick) { tick = 1; requestAnimationFrame(function () { tick = 0; mark(); }); } }, { passive: true });
  mark();
  // Taomni kattalashtirib ko'rish
  var sh = document.getElementById('sheet');
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  document.querySelector('main').addEventListener('click', function (e) {
    var p = e.target.closest('.p'); if (!p) return;
    document.getElementById('s-im').innerHTML = p.dataset.i ? '<img src="' + esc(p.dataset.i) + '" alt="">' : esc(p.dataset.e);
    document.getElementById('s-n').textContent = p.dataset.n;
    document.getElementById('s-d').textContent = p.dataset.d;
    document.getElementById('s-p').textContent = p.dataset.p + " so'm";
    sh.classList.add('on');
  });
  sh.addEventListener('click', function (e) { if (e.target === sh || e.target.id === 's-x') sh.classList.remove('on'); });
})();
</script>
</body>
</html>
