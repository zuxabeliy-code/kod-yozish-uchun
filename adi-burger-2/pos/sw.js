// ADI BURGER POS Service Worker: kassa interfeysi internet bo'lmasa ham ochiladi.
const VERSION = 'adi-pos-2.2.0';
const SHELL = [
  './', './index.html', './manifest.json', './css/pos.css?v=2.2.0', './js/app.js?v=2.2.0',
  './js/db.js', './js/util.js', './js/sync.js', './js/agent.js', './js/model.js', './js/receipt.js', './js/qr.js',
  './icons/icon-192.png', './icons/favicon.png', './icons/logo.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION && k !== 'adi-img').map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return; // API POST va printer xizmati — keshsiz
  if (url.pathname.includes('/api/')) return;
  // Mahsulot rasmlari: avval keshdan (tez), keyin tarmoqdan
  if (url.pathname.includes('/uploads/products/')) {
    e.respondWith(caches.open('adi-img').then(async c => {
      const hit = await c.match(e.request);
      if (hit) return hit;
      try { const r = await fetch(e.request); if (r.ok) c.put(e.request, r.clone()); return r; } catch { return new Response('', { status: 504 }); }
    }));
    return;
  }
  // Kassa fayllari: darhol keshdan (kassa bir zumda ochiladi), yangisi fonda yuklab qo'yiladi.
  // Yangi versiya chiqqanda sw.js o'zgaradi -> yangi kesh to'liq yuklanadi -> kassada "Yangilash" tugmasi chiqadi.
  if (url.pathname.includes('/pos/')) {
    e.respondWith(caches.open(VERSION).then(async c => {
      const hit = (await c.match(e.request)) || (await c.match(e.request, { ignoreSearch: true })) || (e.request.mode === 'navigate' ? await c.match('./index.html') : null);
      const net = fetch(e.request).then(r => { if (r.ok) c.put(e.request, r.clone()); return r; });
      if (hit) { net.catch(() => {}); return hit; }
      return net.catch(() => new Response('', { status: 504 }));
    }));
  }
});
