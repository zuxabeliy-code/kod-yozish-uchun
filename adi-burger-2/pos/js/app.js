// ADI BURGER 2.0 — kassa (POS) ilovasi.

import { db, kv, tx, persist } from './db.js';
import { api, enqueue, kick, configureSync, onSync, syncState, outboxInfo, opPut } from './sync.js';
import * as agent from './agent.js';
import { kitchenDoc, customerDoc, cancelDoc, zDoc, xDoc, combinedDoc, payLabel } from './receipt.js';
import {
  S, saveOrder, newDraft, addProduct, setQty, cancelSent, confirmOrder, payOrder, cancelWholeOrder, openOrders, drafts,
  orderTotal, itemsTotal, paidTotal, unsentLines, openShift, shiftReport, closeShift, localReport, nextNumber,
} from './model.js';
import { uuid, fmt, som, h, dt, nowIso, localDate, toast, modal, confirmBox, promptBox, closeAllModals, hasModal, beep, alarmStart, alarmStop, pbkdf2Hex, debounce, $, $$ } from './util.js';

const VERSION = '2.0.0';
const app = document.getElementById('app');
const UI = { view: 'sale', cur: null, sel: null, cat: 'all', q: '', orderFilter: 'open', settingsTab: 'printer', reportPeriod: 'today' };
const TYPE_NAMES = { takeaway: 'Olib ketish', table: 'Stolda', phone: 'Telefon', delivery: 'Yetkazish' };
const CANCEL_REASONS = ['Mijoz voz kechdi', "Noto'g'ri kiritildi", 'Taom tugagan', 'Uzoq kutildi'];

// ====================================================================
// Ishga tushirish
// ====================================================================
async function boot() {
  persist();
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  const token = await kv.get('device_token');
  S.catalog = await kv.get('catalog');
  if (!token) return renderSetup();
  if (!S.catalog) {
    const r = await loadCatalog();
    if (!r) return renderSetup("Menyuni yuklab bo'lmadi. Internetni tekshiring va qayta urinib ko'ring.");
  } else {
    loadCatalog(); // fonda yangilanadi
  }
  S.shift = await kv.get('shift');
  configureSync({ status: collectStatus, onPull: handlePull, onAuthFail: (e) => { banner('Qurilma serverdan uzilgan: ' + e, 'red'); } });
  kick(200);
  agent.onAgentChange(renderStatus);
  agent.onPrint(onPrintChange);
  onSync(renderStatus);
  setInterval(() => agent.refreshStatus(), 10000);
  setInterval(pollPrintsLoop, 1500);
  setInterval(tickClock, 1000 * 15);
  agent.refreshStatus();
  renderLock();
}

async function loadCatalog() {
  const r = await api('catalog');
  if (r.ok) {
    S.catalog = r;
    await kv.set('catalog', r);
    if (S.employee) S.employee = r.employees.find(e => e.id === S.employee.id) || S.employee;
    // Serverda ochiq smena bor, lokalda yo'q bo'lsa (masalan, brauzer ma'lumoti tozalangan) — tiklaymiz
    if (!S.shift && r.open_shift) {
      const e = r.employees.find(x => x.id === Number(r.open_shift.employee_id));
      S.shift = { uuid: r.open_shift.uuid, number: Number(r.open_shift.number), employee_id: Number(r.open_shift.employee_id), employee: e ? e.name : '', opened_at: r.open_shift.opened_at.replace(' ', 'T'), opening_cash: Number(r.open_shift.opening_cash), status: 'open' };
      await kv.set('shift', S.shift);
    }
    if (UI.view === 'sale' && S.employee) renderView();
    return r;
  }
  return null;
}

// ====================================================================
// Qurilmani ulash (bir marta)
// ====================================================================
function renderSetup(err = '') {
  app.innerHTML = `<div class="lock"><div class="lock-card box" style="width:420px;text-align:left">
    <div style="display:flex;gap:10px;align-items:center"><div class="logo-mark">AB</div><div><h1 style="margin:0;font-size:20px">Kassani ulash</h1><div class="muted" style="font-size:13px">Bir marta, administrator logini bilan</div></div></div>
    <p class="muted" style="font-size:13px">Bu kompyuter kassa sifatida serverga ulanadi. Keyin kassirlar faqat PIN-kod bilan kiradi.</p>
    ${err ? `<div class="note bad" style="margin-bottom:12px">${h(err)}</div>` : ''}
    <div class="field"><label>Admin login</label><input class="inp" id="su-user" autocomplete="username"></div>
    <div class="field"><label>Parol</label><input class="inp" id="su-pass" type="password" autocomplete="current-password"></div>
    <div class="field"><label>Kassa nomi</label><input class="inp" id="su-name" value="Monoblok kassa"></div>
    <button class="btn-x pay" id="su-go" style="width:100%">Ulash</button>
  </div></div>`;
  const go = async () => {
    const b = $('#su-go');
    b.disabled = true; b.textContent = 'Ulanmoqda…';
    const r = await api('device.register', { method: 'POST', auth: false, body: { username: $('#su-user').value, password: $('#su-pass').value, name: $('#su-name').value } });
    if (!r.ok) { renderSetup(r.error || 'Xato'); return; }
    await kv.set('device_token', r.token);
    const c = await loadCatalog();
    if (!c) { renderSetup("Ulandi, lekin menyu yuklanmadi. Qayta urinib ko'ring."); return; }
    toast('Kassa serverga ulandi');
    boot();
  };
  $('#su-go').onclick = go;
  $('#su-pass').onkeydown = (e) => { if (e.key === 'Enter') go(); };
}

// ====================================================================
// PIN bilan kirish
// ====================================================================
async function pinLockInfo() { return (await kv.get('pin_lock')) || { fails: 0, until: 0 }; }

function renderLock() {
  S.employee = null;
  closeAllModals();
  alarmStop();
  let pin = '';
  app.innerHTML = `<div class="lock"><div class="lock-card">
    <div class="logo-mark" style="margin:0 auto;width:56px;height:56px;font-size:20px">AB</div>
    <h1>${h(S.catalog?.settings?.brand_name || 'ADI BURGER')}</h1>
    <div class="muted">PIN-kodni kiriting</div>
    <div class="pin-dots" id="dots"><i></i><i></i><i></i><i></i></div>
    <div class="note bad hidden" id="pin-err" style="margin-bottom:12px"></div>
    <div class="pad" id="pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button data-n="${n}">${n}</button>`).join('')}<button class="fn" data-c>Tozalash</button><button data-n="0">0</button><button class="fn" data-b>⌫</button></div>
    <div class="muted" style="margin-top:16px;font-size:12px">v${VERSION} · ${S.shift ? 'Smena #' + S.shift.number + ' ochiq' : 'Smena yopiq'}</div>
  </div></div>`;
  const dots = $$('#dots i');
  const draw = () => dots.forEach((d, i) => d.classList.toggle('on', i < pin.length));
  const err = (m) => { const e = $('#pin-err'); e.textContent = m; e.classList.remove('hidden'); $('#dots').classList.add('shake'); setTimeout(() => $('#dots').classList.remove('shake'), 400); };
  const press = async (n) => {
    const lock = await pinLockInfo();
    if (lock.until > Date.now()) { err(`Juda ko'p xato. ${Math.ceil((lock.until - Date.now()) / 1000)} soniyadan keyin urinib ko'ring.`); return; }
    if (pin.length >= 4) return;
    pin += n; draw();
    if (pin.length === 4) {
      const p = pin;
      const emp = await verifyPin(p);
      pin = ''; draw();
      if (!emp) {
        lock.fails++;
        if (lock.fails >= 5) { lock.until = Date.now() + Math.min(300, 30 * 2 ** (lock.fails - 5)) * 1000; }
        await kv.set('pin_lock', lock);
        err("PIN noto'g'ri");
        return;
      }
      await kv.set('pin_lock', { fails: 0, until: 0 });
      S.employee = emp;
      afterLogin();
    }
  };
  $('#pad').onclick = (e) => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.n !== undefined) press(b.dataset.n);
    else if (b.dataset.c !== undefined) { pin = ''; draw(); }
    else if (b.dataset.b !== undefined) { pin = pin.slice(0, -1); draw(); }
  };
  document.onkeydown = (e) => { if (!S.employee && /^\d$/.test(e.key)) press(e.key); if (!S.employee && e.key === 'Backspace') { pin = pin.slice(0, -1); draw(); } };
}

async function verifyPin(pin) {
  const emps = S.catalog?.employees || [];
  const it = S.catalog?.pin_iterations || 60000;
  for (const e of emps) {
    if ((await pbkdf2Hex(pin, e.salt, it)) === e.hash) return e;
  }
  return null;
}

async function afterLogin() {
  document.onkeydown = null;
  renderShell();
  if (!S.shift) await shiftOpenModal();
  else if (S.shift.employee_id !== S.employee.id) toast(`Smena #${S.shift.number} ochiq (${S.shift.employee || ''})`, 'info');
  restoreDraft();
}

async function restoreDraft() {
  const d = (await drafts()).sort((a, b) => b.updated_at?.localeCompare(a.updated_at || '') || 0)[0];
  if (d && d.items.length && !UI.cur) { UI.cur = d; renderView(); toast('Saqlanmagan buyurtma tiklandi', 'info'); }
}

function shiftOpenModal() {
  return new Promise(res => {
    const m = modal({
      title: 'Smenani ochish', closable: false,
      body: `<p class="muted" style="margin-top:0">Kassir: <b style="color:var(--text)">${h(S.employee.name)}</b>. Kassadagi boshlang'ich naqd pulni kiriting.</p>
        <div class="field"><label>Boshlang'ich naqd (so'm)</label><input class="inp amount-inp num" id="oc" inputmode="numeric" value="0"></div>`,
      foot: `<button class="btn-x ghost" data-l>Chiqish</button><button class="btn-x pay" style="grid-column:auto;min-width:200px" data-ok>Smenani ochish</button>`,
    });
    m.foot.querySelector('[data-l]').onclick = () => { m.close(); renderLock(); res(); };
    m.foot.querySelector('[data-ok]').onclick = async () => {
      const v = parseInt(String($('#oc').value).replace(/\D/g, '') || '0', 10);
      await openShift(v);
      m.close();
      toast(`Smena #${S.shift.number} ochildi`);
      renderShell();
      res();
    };
  });
}

// ====================================================================
// Asosiy oyna
// ====================================================================
const TABS = [
  ['sale', '🧾', 'Savdo'], ['tables', '🍽', 'Stollar'], ['orders', '📋', 'Buyurtmalar'], ['telegram', '✈️', 'Telegram'],
  ['shift', '🕘', 'Smena'], ['reports', '📊', 'Hisobot'], ['settings', '⚙', 'Sozlamalar'],
];

function renderShell() {
  app.innerHTML = `<div class="shell">
    <header class="topbar">
      <div class="brand"><div class="logo-mark">AB</div><span><b>${h(S.catalog?.settings?.brand_name || 'ADI BURGER')}</b></span></div>
      <nav class="tabs" id="tabs">${TABS.map(([k, i, l]) => `<button class="tab" data-tab="${k}"><span class="ico">${i}</span><span class="lbl">${l}</span>${k === 'telegram' ? '<span class="badge hidden" id="tg-badge">0</span>' : ''}</button>`).join('')}</nav>
      <div class="status">
        <div class="pill btn" id="st-net" title="Internet / server"><span class="dot"></span><span>Server</span></div>
        <div class="pill btn" id="st-sync" title="Serverga yuborilmagan amallar"><span>⇅</span><span class="num">0</span></div>
        <div class="pill btn" id="st-prn" title="Printer"><span class="dot"></span><span>Printer</span></div>
        <div class="pill who" title="Kassir"><span>👤</span><span>${h(S.employee.name)}</span></div>
        <div class="pill" id="st-shift"></div>
        <div class="pill clock num" id="clock"></div>
        <button class="pill btn" id="lockbtn" title="Qulflash">🔒</button>
      </div>
    </header>
    <main class="view" id="view"></main>
  </div>`;
  $('#tabs').onclick = (e) => { const t = e.target.closest('[data-tab]'); if (t) go(t.dataset.tab); };
  $('#lockbtn').onclick = () => renderLock();
  $('#st-prn').onclick = () => { UI.settingsTab = 'printer'; go('settings'); };
  $('#st-sync').onclick = () => { UI.settingsTab = 'sync'; go('settings'); };
  $('#st-net').onclick = () => { UI.settingsTab = 'sync'; go('settings'); };
  tickClock();
  renderStatus();
  renderView();
  updateTgBadge();
}

function go(view) { UI.view = view; renderView(); }

function tickClock() {
  const c = $('#clock');
  if (c) { const d = new Date(); c.textContent = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; }
}

function renderStatus() {
  if (!S.employee || !$('#st-net')) return;
  const net = $('#st-net');
  const online = syncState.online && navigator.onLine;
  net.querySelector('.dot').className = 'dot ' + (syncState.lastOk ? (online ? 'ok' : 'bad') : (online ? 'warn' : 'bad'));
  net.querySelector('span:last-child').textContent = online ? 'Onlayn' : 'Offline';
  const sy = $('#st-sync');
  sy.querySelector('.num').textContent = syncState.outbox;
  sy.title = `Serverga yuborilmagan: ${syncState.outbox} ta amal` + (syncState.lastError ? `\nOxirgi xato: ${syncState.lastError}` : '');
  sy.style.color = syncState.outbox > 0 ? 'var(--yellow)' : '';
  const a = agent.agentState;
  const p = $('#st-prn');
  let cls = 'warn', txt = 'Printer';
  if (a.reachable === false) { cls = 'bad'; txt = 'Xizmat yo\'q'; }
  else if (a.printer && !a.printer.configured) { cls = 'warn'; txt = 'Sozlanmagan'; }
  else if (a.printer && a.printer.ok) { cls = 'ok'; txt = 'Printer'; }
  else if (a.printer) { cls = 'bad'; txt = 'Printer xato'; }
  const bad = (a.queue?.error || 0) + (a.queue?.unknown || 0);
  p.querySelector('.dot').className = 'dot ' + (bad && cls === 'ok' ? 'warn' : cls);
  p.querySelector('span:last-child').textContent = txt + (bad ? ` (${bad})` : '');
  p.title = a.error || a.printer?.error || (a.printer?.target ? 'Ulangan: ' + a.printer.target : '');
  $('#st-shift').textContent = S.shift ? `Smena #${S.shift.number}` : 'Smena yopiq';
  if (UI.view === 'settings') refreshSettingsLive();
}

function renderView() {
  if (!S.employee) return;
  $$('#tabs .tab').forEach(t => t.classList.toggle('on', t.dataset.tab === UI.view));
  const v = $('#view');
  if (!v) return;
  ({ sale: viewSale, tables: viewTables, orders: viewOrders, telegram: viewTelegram, shift: viewShift, reports: viewReports, settings: viewSettings }[UI.view])(v);
}

function banner(text, cls = '') {
  let b = $('#banner');
  if (!b) { b = document.createElement('div'); b.id = 'banner'; document.body.append(b); }
  b.className = 'banner ' + cls;
  b.innerHTML = `<span>${h(text)}</span><button>✕</button>`;
  b.querySelector('button').onclick = () => b.remove();
}

// ====================================================================
// SAVDO
// ====================================================================
function cur() {
  if (!UI.cur) UI.cur = newDraft('takeaway');
  return UI.cur;
}

function tableName(id) { return S.catalog.tables.find(t => t.id === id)?.name || 'Stol'; }

function viewSale(v) {
  const o = cur();
  const cats = S.catalog.categories;
  v.innerHTML = `<div class="sale">
    <section class="panel order" id="order"></section>
    <section class="center">
      <div class="searchbar"><div class="search"><span class="s-ico">🔍</span><input id="q" placeholder="Mahsulot qidirish…" value="${h(UI.q)}" autocomplete="off"></div></div>
      <div class="products" id="prods"></div>
      <div class="actionbar" id="acts">
        <button class="act" data-a="new"><span class="i">＋</span>Yangi</button>
        <button class="act" data-a="qty"><span class="i">#</span>Miqdor</button>
        <button class="act red" data-a="del"><span class="i">🗑</span>O'chirish</button>
        <button class="act" data-a="note"><span class="i">✎</span>Izoh</button>
        <button class="act" data-a="cust"><span class="i">👤</span>Mijoz</button>
        <button class="act" data-a="bill"><span class="i">🧾</span>Hisob cheki</button>
      </div>
    </section>
    <aside class="panel cats" id="cats">
      <button class="cat ${UI.cat === 'all' ? 'on' : ''}" data-cat="all"><span class="ci">★</span>Hammasi</button>
      ${cats.map(c => `<button class="cat ${UI.cat === c.id ? 'on' : ''}" data-cat="${c.id}"><span class="ci">${h(c.icon || '•')}</span>${h(c.name)}</button>`).join('')}
    </aside>
  </div>`;
  renderOrderPanel();
  renderProducts();
  $('#cats').onclick = (e) => { const b = e.target.closest('[data-cat]'); if (!b) return; UI.cat = b.dataset.cat === 'all' ? 'all' : Number(b.dataset.cat); $$('#cats .cat').forEach(x => x.classList.toggle('on', x === b)); renderProducts(); };
  $('#q').oninput = debounce((e) => { UI.q = e.target.value; renderProducts(); }, 120);
  $('#prods').onclick = (e) => { const b = e.target.closest('[data-pid]'); if (b) onProduct(Number(b.dataset.pid)); };
  $('#acts').onclick = (e) => { const b = e.target.closest('[data-a]'); if (b) onAction(b.dataset.a); };
}

function renderProducts() {
  const box = $('#prods');
  if (!box) return;
  const q = UI.q.trim().toLowerCase();
  const o = cur();
  const inCart = {};
  o.items.forEach(i => { if (i.product_id) inCart[i.product_id] = (inCart[i.product_id] || 0) + i.qty; });
  const list = S.catalog.products.filter(p => (UI.cat === 'all' || p.category_id === UI.cat) && (!q || p.name.toLowerCase().includes(q)));
  box.innerHTML = list.length ? list.map(p => `<button class="prod ${p.available ? '' : 'off'}" data-pid="${p.id}">
      <div class="img" ${p.image ? `style="background-image:url('${h(p.image)}')"` : ''}>${p.image ? '' : (S.catalog.categories.find(c => c.id === p.category_id)?.icon || '🍔')}</div>
      <div class="info"><div class="nm">${h(p.name)}</div><div class="pr num">${fmt(p.price)}</div></div>
      ${inCart[p.id] ? `<span class="inc">${inCart[p.id]}</span>` : ''}</button>`).join('')
    : `<div class="muted" style="grid-column:1/-1;text-align:center;padding:40px">Hech narsa topilmadi</div>`;
}

function orderTitle(o) {
  if (o.status === 'draft' && !o.number) return 'Yangi buyurtma';
  return `Buyurtma #${o.number || '—'}`;
}

function renderOrderPanel() {
  const box = $('#order');
  if (!box) return;
  const o = cur();
  const total = orderTotal(o);
  const unsent = unsentLines(o).length;
  const locked = o.status === 'done' || o.status === 'cancelled';
  const typeDisabled = (t) => (o.status !== 'draft' && t !== o.type) ? 'disabled' : '';
  box.innerHTML = `
    <div class="order-head">
      <div class="order-title"><h2>${h(orderTitle(o))}</h2>
        <span class="tag">${o.status === 'draft' ? 'Tasdiqlanmagan' : o.status === 'accepted' ? '<span class="st yellow">Ochiq</span>' : o.status === 'done' ? '<span class="st green">To\'langan</span>' : '<span class="st red">Bekor</span>'}</span></div>
      <div class="seg" id="types">${Object.entries(TYPE_NAMES).map(([k, l]) => `<button class="${o.type === k ? 'on' : ''}" data-type="${k}" ${typeDisabled(k)}>${l}</button>`).join('')}</div>
      <div class="order-meta">
        ${o.type === 'table' ? `<button class="chip btn" data-m="table">🍽 <b>${o.table_id ? h(tableName(o.table_id)) : 'Stol tanlang'}</b>${o.bill_no > 1 ? ' · Hisob-' + o.bill_no : ''}</button>` : ''}
        ${(o.type === 'phone' || o.type === 'delivery' || o.phone || o.customer_name) ? `<button class="chip btn" data-m="cust">👤 <b>${h(o.customer_name || o.phone || 'Mijoz')}</b></button>` : ''}
        ${o.type === 'delivery' ? `<button class="chip btn" data-m="cust">📍 <b>${h(o.address ? o.address.slice(0, 26) : 'Manzil')}</b></button>` : ''}
        ${o.comment ? `<button class="chip btn" data-m="note">💬 <b>${h(o.comment.slice(0, 30))}</b></button>` : ''}
      </div>
    </div>
    <div class="lines" id="lines">${o.items.length ? o.items.map(lineHtml).join('') : `<div class="empty"><div class="big">🍔</div><div>Mahsulotni tanlang</div></div>`}</div>
    <div class="totals">
      <div class="r"><span>Taomlar (${o.items.reduce((s, i) => s + i.qty, 0)} ta)</span><span class="num">${fmt(itemsTotal(o))}</span></div>
      ${o.type === 'delivery' ? `<div class="r"><span>Yetkazish</span><span class="num">${fmt(o.delivery_fee)}</span></div>` : ''}
      ${paidTotal(o) ? `<div class="r"><span>To'langan</span><span class="num">${fmt(paidTotal(o))}</span></div>` : ''}
      <div class="r grand"><span>JAMI</span><span class="num">${fmt(total)}</span></div>
    </div>
    <div class="order-actions">
      ${locked ? `<button class="btn-x" data-o="new" style="grid-column:1/-1">＋ Yangi buyurtma</button>` : `
      <button class="btn-x confirm" data-o="confirm" ${unsent ? '' : 'disabled'}>✓ Tasdiqlash${unsent && o.status !== 'draft' ? ' (+' + unsent + ')' : ''}</button>
      <button class="btn-x danger" data-o="cancel" ${o.items.length || o.status !== 'draft' ? '' : 'disabled'}>${o.status === 'draft' ? 'Tozalash' : 'Bekor qilish'}</button>
      <button class="btn-x pay" data-o="pay" ${total > 0 ? '' : 'disabled'}>💳 To'lov · <span class="num">${fmt(total - paidTotal(o))}</span></button>`}
    </div>`;
  box.onclick = (e) => {
    const t = e.target.closest('[data-type]'); if (t && !t.disabled) return setType(t.dataset.type);
    const m = e.target.closest('[data-m]'); if (m) return onAction(m.dataset.m === 'table' ? 'table' : m.dataset.m);
    const q = e.target.closest('[data-q]'); if (q) return changeQty(q.dataset.l, Number(q.dataset.q));
    const ln = e.target.closest('.line'); if (ln) { UI.sel = ln.dataset.l; $$('#lines .line').forEach(x => x.classList.toggle('sel', x === ln)); return; }
    const b = e.target.closest('[data-o]'); if (b) onOrderBtn(b.dataset.o);
  };
}

function lineHtml(i) {
  const unsent = i.qty - i.sent_qty;
  return `<div class="line ${UI.sel === i.uuid ? 'sel' : ''} ${i.qty === 0 ? 'cancelled' : ''}" data-l="${i.uuid}">
    <div class="nm">${h(i.name)}</div><div class="sum num">${fmt(i.price * i.qty)}</div>
    <div class="sub"><span class="num">${fmt(i.price)} × ${i.qty}</span>
      ${i.sent_qty ? `<span class="sent">✓ ${i.sent_qty} oshxonada</span>` : ''}${unsent > 0 && i.sent_qty ? `<span class="new">+${unsent} yangi</span>` : ''}
      ${i.cancelled_qty ? `<span style="color:var(--red)">✕ ${i.cancelled_qty} bekor</span>` : ''}</div>
    <div class="qty"><button class="qbtn" data-l="${i.uuid}" data-q="-1">−</button><span class="q num">${i.qty}</span><button class="qbtn" data-l="${i.uuid}" data-q="1">+</button></div>
    ${i.comment ? `<div class="cmt">💬 ${h(i.comment)}</div>` : ''}
  </div>`;
}

async function persistCur() {
  const o = cur();
  // Qoralama va tasdiqlanmagan o'zgarishlar faqat lokal saqlanadi (kompyuter o'chsa ham yo'qolmaydi)
  if (o.status === 'draft' && !o.items.length && !o.number) { await db.del('orders', o.uuid).catch(() => {}); return; }
  if (o.status === 'draft' || o.status === 'accepted') { o.updated_at = nowIso(); await db.put('orders', o); }
}

function refreshSale() { renderOrderPanel(); renderProducts(); persistCur(); }

function onProduct(pid) {
  const o = cur();
  if (o.status === 'done' || o.status === 'cancelled') { UI.cur = newDraft(o.type === 'table' ? 'takeaway' : o.type); }
  const p = S.catalog.products.find(x => x.id === pid);
  if (!p || !p.available) return;
  const line = addProduct(cur(), p);
  UI.sel = line.uuid;
  beep(1200, 25, 0.05);
  refreshSale();
  const el = $(`#lines .line[data-l="${line.uuid}"]`); if (el) el.scrollIntoView({ block: 'nearest' });
}

async function changeQty(lineUuid, delta) {
  const o = cur();
  const line = o.items.find(i => i.uuid === lineUuid);
  if (!line) return;
  UI.sel = lineUuid;
  const r = setQty(o, line, line.qty + delta);
  if (r.needsCancel) return cancelLineFlow(o, line, r.needsCancel);
  refreshSale();
}

async function cancelLineFlow(o, line, qty) {
  const reason = await promptBox('Taomni bekor qilish', `"${line.name}" oshxonaga yuborilgan. ${qty} tasini bekor qilish sababi:`, { presets: CANCEL_REASONS });
  if (!reason) return;
  const c = cancelSent(o, line, qty, reason);
  if (!c) return;
  await saveOrder(o);
  refreshSale();
  toast('Bekor qilindi: ' + line.name, 'warn');
  printDoc('cancel', o, cancelDoc(o, c, ctx()), `Bekor: ${line.name}`);
}

async function setType(type) {
  const o = cur();
  if (o.status !== 'draft') return;
  o.type = type;
  if (type === 'delivery') o.delivery_fee = S.catalog.settings.delivery_fee || 0;
  if (type !== 'table') { o.table_id = null; o.bill_no = 1; }
  if (type === 'table' && !o.table_id) { await pickTable(o); }
  if ((type === 'delivery' || type === 'phone') && !o.phone) await customerModal(o);
  refreshSale();
}

function pickTable(o) {
  return new Promise(async res => {
    const open = await openOrders();
    const m = modal({
      title: 'Stolni tanlang', cls: 'wide',
      body: `<div class="grid-tables">${S.catalog.tables.map(t => {
        const bills = open.filter(x => x.type === 'table' && x.table_id === t.id);
        return `<button class="tcard ${bills.length ? 'busy' : ''}" data-t="${t.id}"><div class="tn">${h(t.name)}</div><div class="ts">${bills.length ? bills.length + ' ta hisob' : "Bo'sh"}</div></button>`;
      }).join('')}</div>`,
      foot: null, onClose: () => res(),
    });
    m.body.onclick = async (e) => {
      const b = e.target.closest('[data-t]'); if (!b) return;
      const tid = Number(b.dataset.t);
      const bills = open.filter(x => x.type === 'table' && x.table_id === tid);
      o.table_id = tid;
      o.bill_no = bills.length ? Math.max(...bills.map(x => x.bill_no || 1)) + 1 : 1;
      m.close();
    };
  });
}

function customerModal(o) {
  return new Promise(res => {
    const m = modal({
      title: 'Mijoz ma\'lumotlari',
      body: `<div class="row2"><div class="field"><label>Telefon</label><input class="inp" id="c-phone" inputmode="tel" value="${h(o.phone)}" placeholder="+998 __ ___ __ __"></div>
        <div class="field"><label>Ismi</label><input class="inp" id="c-name" value="${h(o.customer_name)}"></div></div>
        ${o.type === 'delivery' ? `<div class="field"><label>Yetkazish manzili</label><textarea class="inp" id="c-addr">${h(o.address)}</textarea></div>
        <div class="field"><label>Yetkazish narxi (so'm)</label><input class="inp num" id="c-fee" inputmode="numeric" value="${o.delivery_fee || 0}"></div>` : ''}`,
      foot: `<button class="btn-x confirm" data-ok>Saqlash</button>`, onClose: () => res(),
    });
    m.foot.querySelector('[data-ok]').onclick = () => {
      o.phone = $('#c-phone').value.trim().slice(0, 20);
      o.customer_name = $('#c-name').value.trim().slice(0, 100);
      if (o.type === 'delivery') { o.address = $('#c-addr').value.trim().slice(0, 255); o.delivery_fee = parseInt($('#c-fee').value.replace(/\D/g, '') || '0', 10); }
      m.close();
      refreshSale();
    };
  });
}

async function onAction(a) {
  const o = cur();
  const line = o.items.find(i => i.uuid === UI.sel);
  switch (a) {
    case 'new': return newOrder();
    case 'table': return o.status === 'draft' ? pickTable(o).then(refreshSale) : null;
    case 'cust': return customerModal(o);
    case 'qty': {
      if (!line) return toast('Avval taomni tanlang', 'warn');
      const v = await promptBox('Miqdor', line.name, { value: String(line.qty) });
      if (v === null) return;
      const n = parseInt(v, 10);
      if (!(n >= 0)) return;
      const r = setQty(o, line, n);
      if (r.needsCancel) return cancelLineFlow(o, line, r.needsCancel);
      return refreshSale();
    }
    case 'del': {
      if (!line) return toast('Avval taomni tanlang', 'warn');
      if (line.qty > line.sent_qty) {
        // Oshxonaga yuborilmagan qismi oddiy o'chiriladi (bekor qilish cheki chiqmaydi)
        setQty(o, line, line.sent_qty);
        if (!o.items.includes(line)) UI.sel = null;
        return refreshSale();
      }
      if (line.sent_qty > 0) return cancelLineFlow(o, line, line.sent_qty);
      return refreshSale();
    }
    case 'note': {
      if (line) {
        const v = await promptBox('Taom izohi', line.name, { value: line.comment, required: false, presets: ['Piyozsiz', 'Achchiq', 'Sousiz', 'Ikki marta pishloq'] });
        if (v === null) return;
        if (line.sent_qty > 0 && v !== line.comment) {
          // Yuborilgan taomga izoh: yangi qator sifatida emas, keyingi chekda chiqishi uchun alohida qator
          return toast("Oshxonaga yuborilgan taom izohini o'zgartirib bo'lmaydi. Buyurtma izohidan foydalaning.", 'warn');
        }
        line.comment = v;
      } else {
        const v = await promptBox('Buyurtma izohi', 'Oshxona chekida chiqadi', { value: o.comment, required: false, textarea: true });
        if (v === null) return;
        o.comment = v;
      }
      return refreshSale();
    }
    case 'bill': {
      if (!o.items.some(i => i.qty > 0)) return toast("Buyurtmada taom yo'q", 'warn');
      const d = customerDoc({ ...o, payments: [] }, ctx());
      d.lines.unshift({ t: "HISOB (to'lanmagan)", align: 'c', b: true });
      return printDoc('customer', o, d, 'Hisob cheki');
    }
  }
}

async function newOrder() {
  const o = UI.cur;
  if (o && o.status === 'draft' && o.items.length) {
    if (!(await confirmBox('Yangi buyurtma', 'Joriy buyurtma tasdiqlanmagan. U o\'chiriladimi?', "O'chirish", true))) return;
    await db.del('orders', o.uuid);
  }
  if (o && o.status === 'accepted' && unsentLines(o).length) {
    if (!(await confirmBox('Tasdiqlanmagan taomlar', "Bu hisobda oshxonaga yuborilmagan taomlar bor. Ular hisobda saqlanib qoladi (oshxonaga ketmaydi). Davom etasizmi?", 'Davom etish'))) return;
  }
  UI.cur = newDraft('takeaway');
  UI.sel = null;
  renderView();
}

async function onOrderBtn(b) {
  const o = cur();
  if (b === 'new') return newOrder();
  if (b === 'confirm') return doConfirm(o);
  if (b === 'pay') return payModal(o);
  if (b === 'cancel') {
    if (o.status === 'draft') {
      if (!(await confirmBox('Tozalash', 'Buyurtmadagi barcha taomlar olib tashlansinmi?', 'Tozalash', true))) return;
      await db.del('orders', o.uuid);
      UI.cur = newDraft(o.type, o.type === 'table' ? { table_id: o.table_id } : {});
      return renderView();
    }
    return cancelOrderFlow(o);
  }
}

function validateBeforeConfirm(o) {
  if (o.type === 'table' && !o.table_id) return 'Stolni tanlang';
  if (o.type === 'delivery' && (!o.phone || !o.address)) return 'Yetkazish uchun telefon va manzilni kiriting';
  if (o.type === 'phone' && !o.phone) return 'Telefon raqamini kiriting';
  return '';
}

async function doConfirm(o, silent = false) {
  const err = validateBeforeConfirm(o);
  if (err) { toast(err, 'warn'); if (o.type === 'table' && !o.table_id) { await pickTable(o); refreshSale(); } else if (o.type !== 'table') await customerModal(o); return false; }
  let res;
  try { res = await confirmOrder(o); } catch (e) { toast(e.message, 'bad'); return false; }
  if (!silent) toast('Buyurtma tasdiqlandi', 'ok', `#${o.number} saqlandi`);
  if (res.delta.length && S.catalog.settings.kitchen_auto) {
    printDoc('kitchen', o, kitchenDoc(o, res.delta, { ...ctx(), isAddition: res.isAddition }), 'Oshxona cheki', S.catalog.settings.kitchen_copies);
  }
  refreshSale();
  return true;
}

async function cancelOrderFlow(o) {
  const reason = await promptBox('Buyurtmani bekor qilish', `#${o.number} — bekor qilish sababi (tarixda saqlanadi):`, { presets: CANCEL_REASONS });
  if (!reason) return;
  if (paidTotal(o) > 0) return toast("To'langan buyurtmani bekor qilib bo'lmaydi", 'bad');
  const cancels = await cancelWholeOrder(o, reason);
  toast(`#${o.number} bekor qilindi`, 'warn');
  if (cancels.length) {
    const doc = { beep: true, lines: [{ t: 'BEKOR QILINDI', align: 'c', b: true, s: 2 }, { t: `BUTUN BUYURTMA #${o.number}`, align: 'c', b: true }, { type: 'sep', ch: '=' },
      ...cancels.map(c => ({ t: `${c.qty} x ${c.name}`, b: true, s: 2 })), { type: 'sep' }, { t: 'Sabab: ' + reason, b: true }, { type: 'lr', l: dt(nowIso()), r: S.employee.name }] };
    printDoc('cancel', o, doc, 'Bekor qilish cheki');
  }
  UI.cur = newDraft('takeaway');
  renderView();
}

// ---------- To'lov ----------
function payModal(o, { onDone = null, total: forcedTotal = null, title = null } = {}) {
  const total = forcedTotal ?? (orderTotal(o) - paidTotal(o));
  const methods = (S.catalog.settings.pay_methods || ['cash', 'terminal', 'click']);
  const ICON = { cash: '💵', terminal: '💳', click: '📱' };
  let method = methods[0] || 'cash';
  let lines = [];
  let input = '';
  const remaining = () => total - lines.reduce((s, l) => s + l.amount, 0);
  return new Promise(res => {
    let finished = false;
    const m = modal({
      title: title || `To'lov — #${o.number || 'yangi'}`, cls: 'wide',
      body: `<div class="pay-grid"><div>
          <div class="muted">To'lanadigan summa</div><div class="pay-total num">${som(total)}</div>
          <div class="methods">${methods.map(k => `<button class="method" data-m="${k}"><span class="mi">${ICON[k]}</span>${payLabel(k).replace(' (karta)', '')}</button>`).join('')}</div>
          <div class="field"><label id="amt-l">Summa</label><input class="inp amount-inp num" id="amt" inputmode="numeric" data-no-focus="1" readonly></div>
          <div class="quick" id="quick"></div>
          <div class="paylines" id="plines"></div>
          <div style="margin-top:10px;display:flex;justify-content:space-between"><span class="muted">Qoldiq:</span><b class="num" id="rem"></b></div>
          <div style="display:flex;justify-content:space-between"><span class="muted">Qaytim:</span><span class="change num" id="chg">0</span></div>
        </div>
        <div><div class="keypad" id="kp">${[1, 2, 3, 4, 5, 6, 7, 8, 9, '00', 0, '⌫'].map(k => `<button data-k="${k}">${k}</button>`).join('')}</div>
          <button class="btn-x" id="addline" style="width:100%;margin-top:8px">＋ Aralash: shu summani qo'shish</button></div></div>`,
      foot: `<button class="btn-x ghost" data-x2>Bekor</button><button class="btn-x pay" style="min-width:260px" id="payok">✓ To'lovni tasdiqlash</button>`,
      onClose: () => { if (!finished) res(false); },
    });
    const draw = () => {
      $$('.method', m.el).forEach(b => b.classList.toggle('on', b.dataset.m === method));
      const rem = remaining();
      $('#amt', m.el).value = input ? fmt(parseInt(input, 10)) : fmt(Math.max(0, rem));
      $('#amt-l', m.el).textContent = method === 'cash' ? 'Mijoz bergan naqd' : payLabel(method) + ' summasi';
      $('#rem', m.el).textContent = fmt(Math.max(0, rem));
      const cur = input ? parseInt(input, 10) : Math.max(0, rem);
      $('#chg', m.el).textContent = method === 'cash' && cur > rem ? fmt(cur - rem) : '0';
      $('#plines', m.el).innerHTML = lines.map((l, i) => `<div class="payline"><span>${payLabel(l.method)}</span><span><b class="num">${fmt(l.amount)}</b> <button data-rm="${i}" style="margin-left:8px;color:var(--red)">✕</button></span></div>`).join('');
      const q = method === 'cash' ? [...new Set([rem, Math.ceil(rem / 10000) * 10000, Math.ceil(rem / 50000) * 50000, Math.ceil(rem / 100000) * 100000].filter(x => x > 0))].slice(0, 4) : [];
      $('#quick', m.el).innerHTML = q.map(x => `<button data-qv="${x}">${fmt(x)}</button>`).join('');
    };
    m.el.onclick = (e) => {
      const mb = e.target.closest('[data-m]'); if (mb) { method = mb.dataset.m; input = ''; draw(); return; }
      const k = e.target.closest('[data-k]');
      if (k) { const v = k.dataset.k; input = v === '⌫' ? input.slice(0, -1) : (input + v).replace(/^0+/, '').slice(0, 10); draw(); return; }
      const qv = e.target.closest('[data-qv]'); if (qv) { input = qv.dataset.qv; draw(); return; }
      const rm = e.target.closest('[data-rm]'); if (rm) { lines.splice(Number(rm.dataset.rm), 1); draw(); return; }
    };
    $('#addline', m.el).onclick = () => {
      const amt = input ? parseInt(input, 10) : 0;
      const rem = remaining();
      if (!amt || amt <= 0) return toast('Summani kiriting', 'warn');
      if (amt >= rem && method !== 'cash') return toast("Bu summa qoldiqni to'liq yopadi — to'g'ridan-to'g'ri tasdiqlang", 'info');
      lines.push({ method, amount: method === 'cash' ? amt : Math.min(amt, rem) });
      input = '';
      method = methods.find(x => x !== method) || method;
      draw();
    };
    m.foot.querySelector('[data-x2]').onclick = () => m.close();
    $('#payok', m.el).onclick = async () => {
      const rem = remaining();
      const amt = input ? parseInt(input, 10) : Math.max(0, rem);
      const all = [...lines];
      if (rem > 0) {
        if (amt < rem && method === 'cash') return toast(`Naqd yetarli emas: yana ${fmt(rem - amt)} kerak`, 'bad');
        all.push({ method, amount: method === 'cash' ? amt : rem });
      }
      // Naqd har doim oxirida: qaytim faqat naqddan beriladi
      all.sort((a, b) => (a.method === 'cash') - (b.method === 'cash'));
      const btn = $('#payok', m.el); btn.disabled = true;
      try {
        if (onDone) await onDone(all);
        else await completePayment(o, all);
        finished = true;
        res(true);
        m.close();
      } catch (e) { toast(e.message || String(e), 'bad'); btn.disabled = false; }
    };
    draw();
  });
}

async function completePayment(o, payLines) {
  if (unsentLines(o).length || o.status === 'draft') {
    const ok = await doConfirm(o, true);
    if (!ok) throw new Error('Buyurtma tasdiqlanmadi');
  }
  await payOrder(o, payLines);
  const change = o.payments.reduce((s, p) => s + (p.change || 0), 0);
  toast("To'lov qabul qilindi", 'ok', change ? `Qaytim: ${som(change)}` : `#${o.number} yopildi`, change ? 8000 : 3200);
  if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc(o, ctx()), 'Mijoz cheki', S.catalog.settings.customer_copies);
  UI.cur = newDraft('takeaway');
  UI.sel = null;
  renderView();
}

// ====================================================================
// Chop etish
// ====================================================================
function ctx() {
  const st = S.catalog.settings;
  return { brand: st.brand_name || 'ADI BURGER', address: st.brand_address, phone: st.brand_phone, footer: st.receipt_footer, tables: S.catalog.tables, cashier: S.employee?.name || '' };
}

async function printDoc(kind, o, doc, title, copies = 1, id = uuid()) {
  const rec = await agent.print({ id, kind, orderUuid: o?.uuid || null, orderRef: o ? '#' + o.number : '', title, doc, copies });
  if (rec && (rec.status === 'agent_down' || rec.status === 'error')) toast(`${title} chiqmadi`, 'bad', rec.error, 7000);
  else toast(`${title} printerga yuborildi`, 'info', '', 2200);
  return rec;
}

const LOGGABLE = ['sent', 'error', 'unknown', 'reprinted'];
async function onPrintChange(p) {
  if (LOGGABLE.includes(p.status) && p.logged !== p.status) {
    p.logged = p.status;
    await tx(['prints', 'outbox'], 'readwrite', async s => {
      await db.req(s.prints.put(p));
      await opPut(s, 'print.log', { uuid: p.id, order_uuid: p.order_uuid, kind: p.kind, status: p.status, attempts: 1, error: p.error || '', reprint_of: p.reprint_of || null, created_at: p.created_at });
    });
    kick(500);
    if (p.status === 'error') toast(`${p.title || 'Chek'} chiqmadi`, 'bad', p.error, 8000);
    if (p.status === 'unknown') toast(`${p.title || 'Chek'}: natija noma'lum`, 'warn', "Chek qog'ozga chiqqanini tekshiring. Kerak bo'lsa Sozlamalar → Printer navbati → Qayta chiqarish.", 9000);
  }
}

let polling = false;
async function pollPrintsLoop() {
  if (polling) return;
  polling = true;
  try { await agent.pollPrints(); } catch {} finally { polling = false; }
}

// ====================================================================
// STOLLAR
// ====================================================================
async function viewTables(v) {
  const open = await openOrders();
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Stollar</h1><span class="muted">Band stol sariq. Stolni bosing — hisob ochiladi.</span></div>
    <div class="grid-tables" id="tg">${S.catalog.tables.map(t => {
      const bills = open.filter(o => o.type === 'table' && o.table_id === t.id);
      const sum = bills.reduce((s, o) => s + orderTotal(o), 0);
      return `<button class="tcard ${bills.length ? 'busy' : ''}" data-t="${t.id}"><div class="tn">${h(t.name)}</div>
        <div>${bills.length ? `<div class="tsum num">${fmt(sum)}</div><div class="ts">${bills.length} ta hisob · ${dt(bills[0].created_at, false)} dan</div>` : `<div class="ts">Bo'sh</div>`}</div></button>`;
    }).join('')}</div></div>`;
  $('#tg').onclick = async (e) => {
    const b = e.target.closest('[data-t]'); if (!b) return;
    const tid = Number(b.dataset.t);
    const bills = open.filter(o => o.type === 'table' && o.table_id === tid);
    if (!bills.length) { UI.cur = newDraft('table', { table_id: tid }); UI.sel = null; return go('sale'); }
    const m = modal({
      title: tableName(tid),
      body: `<div class="list">${bills.map(o => `<button class="ocard" data-o="${o.uuid}"><div class="no">Hisob-${o.bill_no}</div><div><div>#${h(o.number)} · ${o.items.reduce((s, i) => s + i.qty, 0)} ta taom</div><div class="sm">${dt(o.created_at, false)} dan ochiq</div></div><div class="amt num">${fmt(orderTotal(o))}</div></button>`).join('')}</div>`,
      foot: `<button class="btn-x" data-new>＋ Yangi hisob (Hisob-${Math.max(...bills.map(x => x.bill_no || 1)) + 1})</button>`,
    });
    m.body.onclick = (ev) => { const ob = ev.target.closest('[data-o]'); if (!ob) return; UI.cur = bills.find(x => x.uuid === ob.dataset.o); UI.sel = null; m.close(); go('sale'); };
    m.foot.querySelector('[data-new]').onclick = () => { UI.cur = newDraft('table', { table_id: tid, bill_no: Math.max(...bills.map(x => x.bill_no || 1)) + 1 }); m.close(); go('sale'); };
  };
}

// ====================================================================
// BUYURTMALAR
// ====================================================================
async function viewOrders(v) {
  const all = (await db.all('orders')).sort((a, b) => (b.updated_at || b.created_at).localeCompare(a.updated_at || a.created_at));
  const today = localDate();
  const F = {
    open: ['Ochiq', o => o.status === 'accepted'],
    done: ['Bugun yopilgan', o => o.status === 'done' && localDate(new Date(o.closed_at || o.created_at)) === today],
    cancelled: ['Bekor qilingan', o => o.status === 'cancelled'],
    draft: ['Tasdiqlanmagan', o => o.status === 'draft' && o.items.length],
    all: ['Hammasi', () => true],
  };
  const list = all.filter(F[UI.orderFilter][1]).slice(0, 200);
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Buyurtmalar</h1>
    <div class="filters" id="of">${Object.entries(F).map(([k, [l]]) => `<button class="fbtn ${UI.orderFilter === k ? 'on' : ''}" data-f="${k}">${l} (${all.filter(F[k][1]).length})</button>`).join('')}</div></div>
    <div class="list" id="ol">${list.map(o => `<button class="ocard" data-o="${o.uuid}"><div class="no">#${h(o.number || '—')}</div>
      <div><div>${TYPE_NAMES[o.type]}${o.type === 'table' && o.table_id ? ' · ' + h(tableName(o.table_id)) + (o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : '') : ''} ${statusBadge(o)}</div>
      <div class="sm">${dt(o.created_at)} · ${o.items.filter(i => i.qty > 0).map(i => h(i.name) + ' ×' + i.qty).join(', ').slice(0, 90)}</div></div>
      <div class="amt num">${fmt(orderTotal(o))}</div></button>`).join('') || '<div class="muted" style="padding:30px;text-align:center">Buyurtma yo\'q</div>'}</div></div>`;
  $('#of').onclick = (e) => { const b = e.target.closest('[data-f]'); if (b) { UI.orderFilter = b.dataset.f; renderView(); } };
  $('#ol').onclick = (e) => { const b = e.target.closest('[data-o]'); if (b) orderDetail(all.find(x => x.uuid === b.dataset.o)); };
}

function statusBadge(o) {
  return { draft: '<span class="st">Tasdiqlanmagan</span>', accepted: '<span class="st yellow">Ochiq</span>', done: '<span class="st green">To\'langan</span>', cancelled: '<span class="st red">Bekor</span>' }[o.status] || '';
}

async function orderDetail(o) {
  const prints = (await db.byIndex('prints', 'order_uuid', o.uuid)).sort((a, b) => a.created_at.localeCompare(b.created_at));
  const m = modal({
    title: `Buyurtma #${o.number || '—'}`, cls: 'wide',
    body: `<div class="cards2"><div>
        <div class="kv"><span>Turi</span><b>${TYPE_NAMES[o.type]}${o.type === 'table' ? ' · ' + h(tableName(o.table_id)) : ''}</b></div>
        <div class="kv"><span>Holat</span><b>${statusBadge(o)}</b></div>
        <div class="kv"><span>Ochilgan</span><b>${dt(o.created_at)}</b></div>
        ${o.closed_at ? `<div class="kv"><span>Yopilgan</span><b>${dt(o.closed_at)}</b></div>` : ''}
        ${o.phone || o.customer_name ? `<div class="kv"><span>Mijoz</span><b>${h(o.customer_name)} ${h(o.phone)}</b></div>` : ''}
        ${o.address ? `<div class="kv"><span>Manzil</span><b>${h(o.address)}</b></div>` : ''}
        ${o.comment ? `<div class="kv"><span>Izoh</span><b>${h(o.comment)}</b></div>` : ''}
        ${o.cancel_reason ? `<div class="kv"><span>Bekor sababi</span><b>${h(o.cancel_reason)}</b></div>` : ''}
        <h3 style="margin:14px 0 6px;font-size:15px">To'lovlar</h3>
        ${(o.payments || []).map(p => `<div class="kv"><span>${payLabel(p.method)}</span><b class="num">${fmt(p.amount)}${p.change ? ' (qaytim ' + fmt(p.change) + ')' : ''}</b></div>`).join('') || '<div class="muted">Yo\'q</div>'}
      </div><div>
        <table class="t"><tr><th>Taom</th><th class="r">Soni</th><th class="r">Summa</th></tr>
        ${o.items.map(i => `<tr><td>${h(i.name)}${i.cancelled_qty ? ` <span class="st red">−${i.cancelled_qty}</span>` : ''}${i.comment ? `<div class="muted" style="font-size:12px">${h(i.comment)}</div>` : ''}</td><td class="r num">${i.qty}</td><td class="r num">${fmt(i.price * i.qty)}</td></tr>`).join('')}
        <tr><td><b>Jami</b></td><td></td><td class="r num"><b>${fmt(orderTotal(o))}</b></td></tr></table>
        ${(o.cancels || []).length ? `<h3 style="margin:14px 0 6px;font-size:15px">Bekor qilishlar</h3>${o.cancels.map(c => `<div class="kv"><span>${dt(c.created_at, false)} · ${h(c.name)} ×${c.qty}</span><b>${h(c.reason)}</b></div>`).join('')}` : ''}
        <h3 style="margin:14px 0 6px;font-size:15px">Cheklar</h3>
        ${prints.map(p => { const [l, c] = agent.PRINT_STATUS[p.status] || [p.status, '']; return `<div class="kv"><span>${dt(p.created_at, false)} · ${h(p.title || p.kind)}</span><b><span class="st ${c}">${l}</span>${['error', 'unknown', 'sent', 'agent_down'].includes(p.status) ? ` <button class="fbtn" data-rp="${p.id}" style="height:28px">Qayta</button>` : ''}</b></div>`; }).join('') || '<div class="muted">Chek chiqarilmagan</div>'}
      </div></div>`,
    foot: `${o.status === 'accepted' || o.status === 'draft' ? `<button class="btn-x confirm" data-a="open">Ochish</button>` : ''}
      ${o.status === 'accepted' ? `<button class="btn-x danger" data-a="cancel">Bekor qilish</button>` : ''}
      ${o.status !== 'draft' && o.status !== 'cancelled' ? `<button class="btn-x" data-a="kitchen">Oshxona cheki</button><button class="btn-x" data-a="customer">Mijoz cheki</button>` : ''}`,
  });
  m.el.onclick = async (e) => {
    const rp = e.target.closest('[data-rp]');
    if (rp) {
      const p = prints.find(x => x.id === rp.dataset.rp);
      if (p.status === 'agent_down') { await agent.pollPrints(); return toast('Qayta yuborildi', 'info'); }
      if (!(await confirmBox('Chekni qayta chiqarish', p.status === 'sent' || p.status === 'unknown' ? "Bu chek allaqachon printerga yuborilgan bo'lishi mumkin. Qog'ozni tekshirdingizmi? Qayta chiqarilsinmi?" : 'Chek qayta chiqarilsinmi?', 'Qayta chiqarish'))) return;
      const r = await agent.reprintJob(p.id);
      return r.ok ? (toast('Qayta chiqarish navbatga qo\'yildi'), m.close()) : toast(r.error, 'bad');
    }
    const b = e.target.closest('[data-a]'); if (!b) return;
    if (b.dataset.a === 'open') { UI.cur = o; UI.sel = null; m.close(); go('sale'); }
    if (b.dataset.a === 'cancel') { m.close(); UI.cur = o; await cancelOrderFlow(o); }
    if (b.dataset.a === 'kitchen') { await printDoc('kitchen', o, kitchenDoc(o, o.items.filter(i => i.qty > 0), { ...ctx(), isAddition: false }), 'Oshxona cheki (nusxa)'); }
    if (b.dataset.a === 'customer') { await printDoc('customer', o, customerDoc(o, ctx()), 'Mijoz cheki (nusxa)'); }
  };
}

// ====================================================================
// TELEGRAM buyurtmalari
// ====================================================================
const TG_ST = { new: ['Yangi', 'blue'], accepted: ['Tayyorlanmoqda', 'yellow'], ready: ['Tayyor', 'violet'], done: ['Yakunlandi', 'green'], rejected: ['Rad etildi', 'red'], cancelled: ['Bekor', 'red'] };

async function handlePull(r) {
  if (r.catalog_version && S.catalog && r.catalog_version !== S.catalog.version) loadCatalog();
  let newCount = 0;
  for (const so of r.tg_orders || []) {
    const lo = await db.get('tg', so.uuid);
    const merged = { ...(lo || {}), ...so, status: so.status };
    if (lo?.pending_status) {
      if (so.status === lo.pending_status || !['new', 'accepted', 'ready'].includes(so.status)) delete merged.pending_status;
      else merged.status = lo.pending_status; // server hali yangilanmagan — lokal holatni ko'rsatamiz
    }
    if (!lo && so.status === 'new') { merged.seen = false; newCount++; }
    await db.put('tg', merged);
    // Telegram'dan (admin) qabul qilingan buyurtma — oshxona cheki shu yerda chiqadi
    if (so.status === 'accepted' && so.kitchen_needed && !merged.kitchen_printed) await tgKitchen(merged);
  }
  if (newCount) {
    toast(`Yangi Telegram buyurtma: ${newCount} ta`, 'info', '', 6000);
  }
  updateTgBadge();
  if (UI.view === 'telegram') renderView();
}

async function updateTgBadge() {
  const all = await db.all('tg');
  const unseen = all.filter(o => o.status === 'new' && !o.seen && !o.pending_status);
  const pend = all.filter(o => (o.status === 'new' && !o.pending_status) || (o.receipt && o.receipt.status === 'checking'));
  const b = $('#tg-badge');
  if (b) { b.textContent = pend.length; b.classList.toggle('hidden', !pend.length); b.classList.toggle('pulse', unseen.length > 0); }
  if (unseen.length && S.employee) alarmStart(); else alarmStop();
}

async function viewTelegram(v) {
  const all = (await db.all('tg')).sort((a, b) => b.id - a.id);
  for (const o of all) if (!o.seen) { o.seen = true; await db.put('tg', o); }
  updateTgBadge();
  const active = all.filter(o => ['new', 'accepted', 'ready'].includes(o.status));
  const rest = all.filter(o => !['new', 'accepted', 'ready'].includes(o.status)).slice(0, 30);
  const card = (o) => {
    const [l, c] = TG_ST[o.status] || [o.status, ''];
    const pay = o.pay_status === 'paid' ? '<span class="st green">To\'langan</span>' : o.receipt?.status === 'checking' ? '<span class="st violet">Chek tekshirilsin!</span>' : `<span class="st">${o.pay_method === 'cash' ? 'Naqd (to\'lanmagan)' : "To'lanmagan"}</span>`;
    return `<button class="ocard ${o.status === 'new' ? 'tg-new' : ''}" data-o="${o.uuid}"><div class="no">${h(o.number)}</div>
      <div><div><span class="st ${c}">${l}</span> ${pay} ${o.type === 'delivery' ? '🚚 Yetkazish' : '🛍 Olib ketish'}</div>
      <div class="sm">${dt(o.created_at)} · ${h(o.customer_name || '')} ${h(o.phone || '')} · ${o.items.map(i => h(i.name) + ' ×' + i.qty).join(', ').slice(0, 80)}</div></div>
      <div class="amt num">${fmt(o.total)}</div></button>`;
  };
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Telegram buyurtmalari</h1><span class="muted">Yangi buyurtma kelganda ovoz chaladi</span></div>
    <div class="list" id="tl">${active.map(card).join('') || '<div class="muted" style="padding:20px;text-align:center">Faol buyurtma yo\'q</div>'}</div>
    ${rest.length ? `<h3 style="margin:20px 0 10px">Oxirgilari</h3><div class="list" id="tl2">${rest.map(card).join('')}</div>` : ''}</div>`;
  v.onclick = (e) => { const b = e.target.closest('[data-o]'); if (b) tgDetail(all.find(x => x.uuid === b.dataset.o)); };
}

async function tgSetStatus(o, status, reason = '') {
  o.pending_status = status;
  o.status = status;
  if (status === 'accepted') o.local_shift = S.shift?.uuid || null;
  await tx(['tg', 'outbox'], 'readwrite', async s => {
    await db.req(s.tg.put(o));
    await opPut(s, 'tg.status', { order_uuid: o.uuid, status, reason, employee_id: S.employee.id });
  });
  kick();
}

/** Telegram buyurtmasi oshxona chekining doimiy ID si (UUID ko'rinishida): qayta urinilsa ham chek bir marta chiqadi. */
function tgKitchenId(orderUuid) { return '7e1e9a00' + orderUuid.slice(8); }

async function tgKitchen(o) {
  const doc = kitchenDoc({ ...o, source: 'telegram' }, o.items.filter(i => i.qty > 0).map(i => ({ name: i.name, qty: i.qty, comment: i.comment })), { ...ctx(), isAddition: false });
  // Bir xil ID: aloqa uzilib qayta urinilsa ham chek bir marta chiqadi
  await printDoc('kitchen', o, doc, `Oshxona cheki ${o.number}`, S.catalog.settings.kitchen_copies, tgKitchenId(o.uuid));
  o.kitchen_printed = true;
  await tx(['tg', 'outbox'], 'readwrite', async s => {
    await db.req(s.tg.put(o));
    await opPut(s, 'tg.kitchen_done', { order_uuid: o.uuid, employee_id: S.employee?.id });
  });
  kick();
}

async function tgDetail(o) {
  const needPay = ['card', 'hamyon'].includes(o.pay_method) && o.pay_status !== 'paid';
  const canAccept = o.status === 'new';
  const m = modal({
    title: `Telegram buyurtma ${o.number}`, cls: 'wide',
    body: `<div class="cards2"><div>
      <div class="kv"><span>Holat</span><b><span class="st ${(TG_ST[o.status] || [])[1]}">${(TG_ST[o.status] || [o.status])[0]}</span></b></div>
      <div class="kv"><span>Turi</span><b>${o.type === 'delivery' ? 'Yetkazib berish' : 'Olib ketish'}</b></div>
      <div class="kv"><span>Mijoz</span><b>${h(o.customer_name || '')}</b></div>
      <div class="kv"><span>Telefon</span><b>${h(o.phone || '')}</b></div>
      ${o.address ? `<div class="kv"><span>Manzil</span><b>${h(o.address)}</b></div>` : ''}
      ${o.comment ? `<div class="kv"><span>Izoh</span><b>${h(o.comment)}</b></div>` : ''}
      <div class="kv"><span>To'lov</span><b>${{ cash: 'Naqd', card: "Kartaga o'tkazma", hamyon: 'Hamyon', mixed: 'Aralash' }[o.pay_method] || '-'} · ${o.pay_status === 'paid' ? '<span class="st green">To\'langan</span>' : '<span class="st">To\'lanmagan</span>'}</b></div>
      <div class="kv"><span>Vaqt</span><b>${dt(o.created_at)}</b></div>
      ${needPay && o.status === 'new' ? `<div class="note warn" style="margin-top:10px"><b>To'lov tasdiqlanmagan.</b> Kartangizga (bank ilovasida) pul tushganini tekshiring. Pul tushgandan keyingina buyurtmani qabul qiling.</div>` : ''}
      ${o.receipt ? `<h3 style="margin:14px 0 8px;font-size:15px">To'lov cheki (mijoz yuborgan)</h3><div id="rimg" class="muted">Yuklanmoqda…</div>` : ''}
    </div><div>
      <table class="t"><tr><th>Taom</th><th class="r">Soni</th><th class="r">Summa</th></tr>
      ${o.items.map(i => `<tr><td>${h(i.name)}</td><td class="r num">${i.qty}</td><td class="r num">${fmt(i.price * i.qty)}</td></tr>`).join('')}
      ${o.delivery_fee ? `<tr><td>Yetkazish</td><td></td><td class="r num">${fmt(o.delivery_fee)}</td></tr>` : ''}
      <tr><td><b>Jami</b></td><td></td><td class="r num"><b>${fmt(o.total)}</b></td></tr></table></div></div>`,
    foot: `${canAccept ? `<button class="btn-x danger" data-a="reject">Rad etish</button><button class="btn-x blue" data-a="accept" ${needPay ? 'disabled title="Avval to\'lovni tasdiqlang"' : ''}>✓ Qabul qilish</button>` : ''}
      ${o.receipt?.status === 'checking' ? `<button class="btn-x danger" data-a="rcpt-no">Chek noto'g'ri</button><button class="btn-x confirm" data-a="rcpt-ok">✓ To'lov tushdi</button>` : ''}
      ${o.status === 'accepted' ? `<button class="btn-x" data-a="ready">Tayyor</button>` : ''}
      ${['accepted', 'ready'].includes(o.status) ? `<button class="btn-x pay" style="min-width:200px" data-a="done">${o.pay_status === 'paid' ? 'Yakunlash' : "To'lov va yakunlash"}</button>` : ''}
      ${['accepted', 'ready', 'done'].includes(o.status) ? `<button class="btn-x" data-a="kitchen">Oshxona cheki</button>` : ''}`,
  });
  if (o.receipt) {
    const tok = await kv.get('device_token');
    fetch(new URL('../api/?r=receipt.image&id=' + o.receipt.id, location.href), { headers: { Authorization: 'Bearer ' + tok, 'X-Device-Token': tok } })
      .then(r => r.ok ? r.blob() : Promise.reject()).then(b => { const el = $('#rimg', m.el); if (el) el.innerHTML = `<img class="receipt-img" src="${URL.createObjectURL(b)}" alt="to'lov cheki">`; })
      .catch(() => { const el = $('#rimg', m.el); if (el) el.textContent = navigator.onLine ? 'Rasmni yuklab bo\'lmadi' : 'Internet yo\'q — rasm ko\'rinmaydi'; });
  }
  m.el.onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const a = b.dataset.a;
    if (a === 'accept') {
      if (needPay) return toast("To'lov hali tasdiqlanmagan", 'bad', "Avval mijoz chekini tekshirib, \"To'lov tushdi\" ni bosing. Pul tushmagan bo'lsa — \"Chek noto'g'ri\".", 7000);
      await tgSetStatus(o, 'accepted');
      toast(`${o.number} qabul qilindi`, 'ok');
      await tgKitchen(o);
    }
    if (a === 'reject') {
      const r = await promptBox('Rad etish sababi', 'Mijozga yuboriladi', { presets: ['Mahsulot tugagan', 'Yetkazish hududidan tashqari', 'Ish vaqti tugadi', "Mijoz bilan bog'lanib bo'lmadi"] });
      if (!r) return;
      await tgSetStatus(o, 'rejected', r);
      toast(`${o.number} rad etildi`, 'warn');
    }
    if (a === 'ready') { await tgSetStatus(o, 'ready'); toast(`${o.number} tayyor — mijozga xabar ketdi`); }
    if (a === 'kitchen') await printDoc('kitchen', o, kitchenDoc({ ...o, source: 'telegram' }, o.items, ctx()), `Oshxona cheki ${o.number} (nusxa)`);
    if (a === 'rcpt-ok' || a === 'rcpt-no') {
      let note = '';
      if (a === 'rcpt-no') { note = await promptBox("Chek rad etildi", 'Sabab (mijozga yuboriladi)', { presets: ['Summa mos emas', "Pul tushmadi", 'Chek o\'qilmaydi'] }); if (!note) return; }
      o.receipt.status = a === 'rcpt-ok' ? 'approved' : 'rejected';
      if (a === 'rcpt-ok') o.pay_status = 'paid';
      await tx(['tg', 'outbox'], 'readwrite', async s => {
        await db.req(s.tg.put(o));
        await opPut(s, 'card.review', { receipt_id: o.receipt.id, approve: a === 'rcpt-ok', note, employee_id: S.employee.id });
      });
      kick();
      toast(a === 'rcpt-ok' ? "To'lov tasdiqlandi" : 'Chek rad etildi', a === 'rcpt-ok' ? 'ok' : 'warn');
    }
    if (a === 'done') {
      if (o.pay_status !== 'paid') {
        m.close();
        const ok = await payModal(o, {
          total: o.total, title: `To'lov — ${o.number}`, onDone: async (lines) => {
            let rem = o.total;
            const pays = [];
            for (const l of lines) {
              if (rem <= 0) break;
              const amount = Math.min(rem, l.amount);
              const p = { uuid: uuid(), method: l.method, amount, created_at: nowIso() };
              if (l.method === 'cash') { p.received = l.amount; p.change = Math.max(0, l.amount - amount); }
              pays.push(p); rem -= amount;
            }
            o.local_payments = pays;
            o.local_shift = o.local_shift || S.shift?.uuid;
            o.pay_status = 'paid';
            await tx(['tg', 'outbox'], 'readwrite', async s => {
              await db.req(s.tg.put(o));
              await opPut(s, 'tg.payment', { order_uuid: o.uuid, shift_uuid: S.shift?.uuid, payments: pays, employee_id: S.employee.id });
            });
            await tgSetStatus(o, 'done');
            toast("To'lov qabul qilindi", 'ok', pays.some(p => p.change) ? 'Qaytim: ' + som(pays.reduce((s, p) => s + (p.change || 0), 0)) : '');
            if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc({ ...o, payments: pays, closed_at: nowIso() }, ctx()), `Mijoz cheki ${o.number}`);
          },
        });
        if (ok) renderView();
        return;
      }
      await tgSetStatus(o, 'done');
      toast(`${o.number} yakunlandi`);
      if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc({ ...o, payments: [{ method: o.pay_method === 'hamyon' ? 'hamyon' : 'card_transfer', amount: o.total }], closed_at: nowIso() }, ctx()), `Mijoz cheki ${o.number}`);
    }
    m.close();
    renderView();
  };
}

// ====================================================================
// SMENA
// ====================================================================
async function viewShift(v) {
  const history = (await db.all('shifts')).filter(s => s.status === 'closed').sort((a, b) => b.number - a.number).slice(0, 20);
  if (!S.shift) {
    v.innerHTML = `<div class="page"><div class="page-head"><h1>Smena</h1></div><div class="box" style="max-width:520px"><h3>Smena yopiq</h3><p class="muted">Savdo qilish uchun smenani oching.</p><button class="btn-x pay" id="so">Smenani ochish</button></div>${historyHtml(history)}</div>`;
    $('#so').onclick = () => shiftOpenModal().then(renderView);
    bindHistory(v, history);
    return;
  }
  const r = await shiftReport(S.shift);
  const open = await openOrders();
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Smena #${S.shift.number}</h1><span class="muted">Ochildi: ${dt(S.shift.opened_at)} · ${h(S.shift.employee)}</span></div>
    <div class="stats">
      <div class="stat"><div class="l">Jami savdo</div><div class="v num">${fmt(r.sales)}</div></div>
      <div class="stat"><div class="l">Naqd</div><div class="v num">${fmt(r.pay.cash)}</div></div>
      <div class="stat"><div class="l">Terminal</div><div class="v num">${fmt(r.pay.terminal)}</div></div>
      <div class="stat"><div class="l">Click</div><div class="v num">${fmt(r.pay.click)}</div></div>
      <div class="stat"><div class="l">Buyurtmalar</div><div class="v num">${r.orders}</div></div>
      <div class="stat"><div class="l">Bekor qilishlar</div><div class="v num">${r.cancels}</div></div>
    </div>
    <div class="cards2"><div class="box"><h3>Kassa naqd puli</h3>
      <div class="kv"><span>Boshlang'ich naqd</span><b class="num">${fmt(r.opening_cash)}</b></div>
      <div class="kv"><span>Naqd tushum</span><b class="num">${fmt(r.pay.cash)}</b></div>
      <div class="kv"><span>Kassada bo'lishi kerak</span><b class="num">${fmt(r.expected_cash)}</b></div>
      ${r.pay.online ? `<div class="kv"><span>Telegram (onlayn to'lov)</span><b class="num">${fmt(r.pay.online)}</b></div>` : ''}
      <div class="kv"><span>Ochiq hisoblar</span><b>${open.length} ta · <span class="num">${fmt(open.reduce((s, o) => s + orderTotal(o), 0))}</span></b></div>
      <div class="btns" style="margin-top:14px"><button class="btn-x" id="xr">X-hisobot chiqarish</button><button class="btn-x danger" id="sc">Smenani yopish</button></div>
    </div>
    <div class="box"><h3>Eng ko'p sotilganlar</h3>${r.top.slice(0, 10).map(t => `<div class="kv"><span>${h(t.name)}</span><b class="num">${t.qty} ta</b></div>`).join('') || '<div class="muted">Hali savdo yo\'q</div>'}</div></div>
    ${historyHtml(history)}</div>`;
  $('#xr').onclick = () => printDoc('report', null, xDoc(r, ctx()), 'X-hisobot');
  $('#sc').onclick = () => closeShiftFlow();
  bindHistory(v, history);
}

function historyHtml(history) {
  if (!history.length) return '';
  return `<h3 style="margin:22px 0 10px">Yopilgan smenalar (shu kassada)</h3><div class="box" style="padding:6px 12px"><table class="t"><tr><th>№</th><th>Kassir</th><th>Ochildi</th><th>Yopildi</th><th class="r">Savdo</th><th class="r">Naqd</th><th class="r">Karta</th><th class="r">Buyurtma</th><th></th></tr>
    ${history.map(s => `<tr><td>#${s.number}</td><td>${h(s.employee)}</td><td>${dt(s.opened_at)}</td><td>${dt(s.closed_at)}</td><td class="r num">${fmt(s.report?.sales)}</td><td class="r num">${fmt(s.report?.pay?.cash)}</td><td class="r num">${fmt((s.report?.pay?.terminal || 0) + (s.report?.pay?.click || 0))}</td><td class="r">${s.report?.orders ?? ''}</td><td class="r"><button class="fbtn" data-z="${s.uuid}" style="height:30px">Z qayta</button></td></tr>`).join('')}</table></div>`;
}
function bindHistory(v, history) {
  v.querySelectorAll('[data-z]').forEach(b => b.onclick = () => { const s = history.find(x => x.uuid === b.dataset.z); if (s?.report) printDoc('zreport', null, zDoc(s.report, ctx()), `Z-hisobot #${s.number} (nusxa)`); });
}

async function closeShiftFlow() {
  const dr = (await drafts()).filter(d => d.items.length);
  if (dr.length) {
    if (!(await confirmBox('Tasdiqlanmagan buyurtmalar', `${dr.length} ta tasdiqlanmagan buyurtma bor (oshxonaga yuborilmagan). Ular o'chiriladi.`, "O'chirish va davom etish", true))) return;
    for (const d of dr) await db.del('orders', d.uuid);
    if (UI.cur && UI.cur.status === 'draft') UI.cur = null;
  }
  const open = await openOrders();
  if (open.length) {
    const ok = await closeOpenTables(open);
    if (!ok) return;
  }
  const r = await shiftReport(S.shift);
  const m = modal({
    title: `Smena #${S.shift.number} ni yopish`, closable: true,
    body: `<div class="kv"><span>Jami savdo</span><b class="num">${som(r.sales)}</b></div>
      <div class="kv"><span>Naqd</span><b class="num">${fmt(r.pay.cash)}</b></div><div class="kv"><span>Terminal</span><b class="num">${fmt(r.pay.terminal)}</b></div><div class="kv"><span>Click</span><b class="num">${fmt(r.pay.click)}</b></div>
      <div class="kv"><span>Buyurtmalar</span><b>${r.orders}</b></div>
      <div class="kv"><span>Kassada bo'lishi kerak (naqd)</span><b class="num">${som(r.expected_cash)}</b></div>
      <div class="field" style="margin-top:12px"><label>Kassadagi naqd pulni sanab kiriting</label><input class="inp amount-inp num" id="cc" inputmode="numeric" value="${r.expected_cash}"></div>
      <div id="diff" class="note"></div>`,
    foot: `<button class="btn-x ghost" data-no>Bekor</button><button class="btn-x danger" data-ok style="min-width:240px">Smenani yopish va Z-hisobot</button>`,
  });
  const cc = $('#cc', m.el);
  const upd = () => { const v = parseInt(cc.value.replace(/\D/g, '') || '0', 10); const d = v - r.expected_cash; $('#diff', m.el).className = 'note ' + (d === 0 ? 'ok' : 'warn'); $('#diff', m.el).textContent = d === 0 ? 'Farq yo\'q' : `Farq: ${d > 0 ? '+' : ''}${fmt(d)} so'm (${d > 0 ? 'ortiqcha' : 'kam'})`; };
  cc.oninput = upd; upd();
  m.foot.querySelector('[data-no]').onclick = () => m.close();
  m.foot.querySelector('[data-ok]').onclick = async () => {
    const counted = parseInt(cc.value.replace(/\D/g, '') || '0', 10);
    if (!(await confirmBox('Tasdiqlash', 'Smena yopilgandan keyin qayta ochilmaydi. Davom etasizmi?', 'Ha, yopish', true))) return;
    const { sh, report } = await closeShift(counted);
    m.close();
    toast(`Smena #${sh.number} yopildi`);
    printDoc('zreport', null, zDoc(report, ctx()), `Z-hisobot #${sh.number}`);
    setTimeout(renderLock, 1200);
  };
}

/** Smena yopishda ochiq stollar: birma-bir yoki "HAMMASINI YOPISH". */
function closeOpenTables(open) {
  return new Promise(res => {
    let idx = 0;
    const label = (o) => o.type === 'table' ? `${tableName(o.table_id)}${o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : ''}` : `${TYPE_NAMES[o.type]} #${o.number}`;
    const totalAll = () => open.reduce((s, o) => s + orderTotal(o) - paidTotal(o), 0);
    let finished = false;
    const m = modal({ title: 'Ochiq hisoblarni yopish', cls: 'wide', body: '', foot: '<span></span>', onClose: () => { if (!finished) res(false); } });
    const step = () => {
      if (idx >= open.length) { finished = true; m.close(); res(true); return; }
      const o = open[idx];
      m.body.innerHTML = `<div class="note warn" style="margin-bottom:12px">Smenani yopishdan oldin ${open.length} ta ochiq hisob yopilishi kerak. Jami: <b class="num">${som(totalAll())}</b></div>
        <div class="page-head" style="margin:0 0 8px"><h1 style="font-size:18px">${idx + 1}/${open.length}: ${h(label(o))} · #${h(o.number)}</h1><b class="num" style="font-size:22px;color:var(--brand2)">${som(orderTotal(o) - paidTotal(o))}</b></div>
        <table class="t">${o.items.filter(i => i.qty > 0).map(i => `<tr><td>${h(i.name)}</td><td class="r">${i.qty}</td><td class="r num">${fmt(i.qty * i.price)}</td></tr>`).join('')}</table>
        <div class="methods" style="margin-top:14px">${['cash', 'terminal', 'click'].filter(x => S.catalog.settings.pay_methods.includes(x)).map(k => `<button class="method" data-pm="${k}">${payLabel(k).replace(' (karta)', '')}</button>`).join('')}<button class="method" data-pm="mixed">Aralash</button></div>`;
      m.foot.innerHTML = `<button class="btn-x blue" data-all>HAMMASINI YOPISH</button>`;
      m.body.onclick = async (e) => {
        const b = e.target.closest('[data-pm]'); if (!b) return;
        const pm = b.dataset.pm;
        if (pm === 'mixed') {
          const ok = await payModal(o, { onDone: async (lines) => { await payOrder(o, lines); } });
          if (ok) { if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc(o, ctx()), `Mijoz cheki #${o.number}`); idx++; step(); }
          return;
        }
        if (!(await confirmBox('To\'lovni tasdiqlash', `${label(o)}: ${som(orderTotal(o) - paidTotal(o))} — ${payLabel(pm)} orqali qabul qilindimi?`, 'Ha, qabul qilindi'))) return;
        if (unsentLines(o).length) await confirmOrder(o);
        await payOrder(o, [{ method: pm, amount: orderTotal(o) - paidTotal(o) }]);
        if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc(o, ctx()), `Mijoz cheki #${o.number}`);
        idx++; step();
      };
      m.foot.querySelector('[data-all]').onclick = () => closeAllTablesModal(open.slice(idx), () => { idx = open.length; step(); });
    };
    step();
  });
}

function closeAllTablesModal(list, done) {
  const sum = list.reduce((s, o) => s + orderTotal(o) - paidTotal(o), 0);
  const m = modal({
    title: 'HAMMASINI YOPISH', cls: 'wide',
    body: `<div class="note">Quyidagi ${list.length} ta hisob bitta to'lov usuli bilan yopiladi. To'lovlar bazada har bir stol bo'yicha alohida saqlanadi.</div>
      <table class="t" style="margin:10px 0">${list.map(o => `<tr><td>${o.type === 'table' ? h(tableName(o.table_id)) + (o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : '') : TYPE_NAMES[o.type]} #${h(o.number)}</td><td class="r num">${fmt(orderTotal(o) - paidTotal(o))}</td></tr>`).join('')}
      <tr><td><b>JAMI</b></td><td class="r num"><b>${som(sum)}</b></td></tr></table>
      <div class="field"><label>To'lov usuli</label><div class="methods">${['cash', 'terminal', 'click'].filter(x => S.catalog.settings.pay_methods.includes(x)).map((k, i) => `<button class="method ${i === 0 ? 'on' : ''}" data-pm="${k}">${payLabel(k).replace(' (karta)', '')}</button>`).join('')}</div></div>
      <div class="field"><label>Chek</label>
        <label class="check"><input type="radio" name="rc" value="each" checked> Har bir stol uchun alohida chek</label>
        <label class="check"><input type="radio" name="rc" value="one"> Barcha stollar uchun bitta umumiy chek</label></div>
      <label class="check"><input type="checkbox" id="realpaid"> <b>To'lovlar haqiqatda qabul qilinganini tasdiqlayman</b></label>`,
    foot: `<button class="btn-x ghost" data-no>Bekor</button><button class="btn-x pay" style="min-width:260px" data-ok>Hammasini yopish · <span class="num">${fmt(sum)}</span></button>`,
  });
  let pm = $('.method.on', m.el)?.dataset.pm || 'cash';
  m.body.onclick = (e) => { const b = e.target.closest('[data-pm]'); if (b) { pm = b.dataset.pm; $$('.method', m.el).forEach(x => x.classList.toggle('on', x === b)); } };
  m.foot.querySelector('[data-no]').onclick = () => m.close();
  m.foot.querySelector('[data-ok]').onclick = async () => {
    if (!$('#realpaid', m.el).checked) return toast("To'lovlar qabul qilinganini belgilang", 'warn');
    const mode = $('input[name=rc]:checked', m.el).value;
    const btn = m.foot.querySelector('[data-ok]'); btn.disabled = true;
    for (const o of list) {
      if (unsentLines(o).length) await confirmOrder(o);
      await payOrder(o, [{ method: pm, amount: orderTotal(o) - paidTotal(o) }]);
      if (mode === 'each') printDoc('customer', o, customerDoc(o, ctx()), `Mijoz cheki #${o.number}`);
    }
    if (mode === 'one') printDoc('customer', null, combinedDoc(list, ctx()), 'Umumiy chek');
    toast(`${list.length} ta hisob yopildi`, 'ok', som(sum));
    m.close();
    done();
  };
}

// ====================================================================
// HISOBOT (lokal, offline)
// ====================================================================
async function viewReports(v) {
  const now = new Date();
  const day = 864e5;
  const P = { today: ['Bugun', localDate(now)], week: ['7 kun', localDate(new Date(now - 6 * day))], month: ['30 kun', localDate(new Date(now - 29 * day))] };
  const [label, from] = P[UI.reportPeriod];
  const r = await localReport(from, localDate(now));
  const top = Object.entries(r.top).sort((a, b) => b[1] - a[1]).slice(0, 15);
  const days = Object.entries(r.byDay).sort();
  const maxDay = Math.max(1, ...days.map(d => d[1]));
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Hisobot — ${label}</h1>
    <div class="filters" id="rp">${Object.entries(P).map(([k, [l]]) => `<button class="fbtn ${UI.reportPeriod === k ? 'on' : ''}" data-p="${k}">${l}</button>`).join('')}</div></div>
    <div class="note" style="margin-bottom:12px">Bu kassadagi lokal ma'lumot (internetsiz ham ishlaydi). To'liq hisobotlar admin panelda.</div>
    <div class="stats">
      <div class="stat"><div class="l">Savdo</div><div class="v num">${fmt(r.sales)}</div></div>
      <div class="stat"><div class="l">Buyurtmalar</div><div class="v num">${r.orders}</div></div>
      <div class="stat"><div class="l">O'rtacha chek</div><div class="v num">${fmt(r.orders ? r.sales / r.orders : 0)}</div></div>
      <div class="stat"><div class="l">Naqd</div><div class="v num">${fmt(r.pay.cash || 0)}</div></div>
      <div class="stat"><div class="l">Karta (terminal+click)</div><div class="v num">${fmt((r.pay.terminal || 0) + (r.pay.click || 0))}</div></div>
      <div class="stat"><div class="l">Bekor qilishlar</div><div class="v num">${r.cancels} · ${fmt(r.cancelsSum)}</div></div>
    </div>
    <div class="cards2"><div class="box"><h3>Kunlar bo'yicha</h3>${days.map(([d, s]) => `<div class="kv"><span>${d}</span><b class="num">${fmt(s)}</b></div><div style="height:6px;border-radius:4px;background:var(--brand);opacity:.7;width:${Math.round(s / maxDay * 100)}%;margin:-2px 0 6px"></div>`).join('') || '<div class="muted">Ma\'lumot yo\'q</div>'}</div>
    <div class="box"><h3>Eng ko'p sotilgan mahsulotlar</h3>${top.map(([n, q]) => `<div class="kv"><span>${h(n)}</span><b class="num">${q} ta</b></div>`).join('') || '<div class="muted">Ma\'lumot yo\'q</div>'}</div>
    <div class="box"><h3>Turlar bo'yicha</h3>${Object.entries(r.byType).map(([t, s]) => `<div class="kv"><span>${TYPE_NAMES[t] || 'Telegram'}</span><b class="num">${fmt(s)}</b></div>`).join('') || '<div class="muted">Ma\'lumot yo\'q</div>'}</div></div></div>`;
  $('#rp').onclick = (e) => { const b = e.target.closest('[data-p]'); if (b) { UI.reportPeriod = b.dataset.p; renderView(); } };
}

// ====================================================================
// SOZLAMALAR: printer, navbat, sinxronlash, qurilma
// ====================================================================
async function viewSettings(v) {
  const T = { printer: '🖨 Printer', queue: '📄 Printer navbati', sync: '⇅ Sinxronlash', device: '💻 Qurilma' };
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Sozlamalar</h1>
    <div class="filters" id="stabs">${Object.entries(T).map(([k, l]) => `<button class="fbtn ${UI.settingsTab === k ? 'on' : ''}" data-s="${k}">${l}</button>`).join('')}</div></div><div id="sbody"></div></div>`;
  $('#stabs').onclick = (e) => { const b = e.target.closest('[data-s]'); if (b) { UI.settingsTab = b.dataset.s; renderView(); } };
  const body = $('#sbody');
  if (UI.settingsTab === 'printer') return settingsPrinter(body);
  if (UI.settingsTab === 'queue') return settingsQueue(body);
  if (UI.settingsTab === 'sync') return settingsSync(body);
  return settingsDevice(body);
}

function printerStatusHtml() {
  const a = agent.agentState;
  if (a.reachable === false) return `<div class="note bad"><b>Printer xizmati ishlamayapti.</b><br>${h(a.error)}<br><br>Tekshiring: 1) Kompyuterni qayta yoqing — xizmat Windows bilan birga ishga tushadi. 2) Ish stolidagi o'rnatuvchini (kassa-ornatish.bat) qayta ishga tushiring. 3) Antivirus adi-printer.exe ni bloklamaganini tekshiring.</div>`;
  if (a.reachable === null) return `<div class="note">Printer xizmati tekshirilmoqda…</div>`;
  const p = a.printer;
  if (a.error && !p) return `<div class="note bad">${h(a.error)}</div>`;
  if (!p || !p.configured) return `<div class="note warn"><b>Printer sozlanmagan.</b> IP manzil, port va chek o'lchamini kiriting va "Saqlash" ni bosing.</div>`;
  if (!p.ok) return `<div class="note bad"><b>Printer ishlamayapti:</b> ${h(p.error)}<br><span class="muted">Oxirgi tekshiruv: ${dt(p.checked_at)}</span></div>`;
  return `<div class="note ok"><b>Printer ulangan</b> — ${h(p.target)}${p.latency_ms ? ` (${p.latency_ms} ms)` : ''}${p.paper && p.paper !== 'unknown' ? ` · Qog'oz: ${p.paper === 'ok' ? 'bor' : p.paper === 'near_end' ? 'tugayapti' : 'tugagan'}` : ''}<br><span class="muted">Xizmat v${h(a.version || '?')} · tekshirildi ${dt(p.checked_at, false)}</span></div>`;
}

function refreshSettingsLive() {
  const el = $('#prn-status'); if (el) el.innerHTML = printerStatusHtml();
}

async function settingsPrinter(body) {
  await agent.refreshStatus();
  const c = agent.agentState.config || { mode: 'lan', ip: '', port: 9100, width: 80, cut: true, status_query: true, model: 'Xprinter', printer_name: '' };
  body.innerHTML = `<div class="cards2"><div class="box"><h3>🖨 Printer ulanishi</h3>
      <div id="prn-status">${printerStatusHtml()}</div>
      <div class="field" style="margin-top:14px"><label>Ulanish turi</label><div class="seg" style="grid-template-columns:1fr 1fr" id="pmode"><button data-mode="lan" class="${c.mode !== 'usb' ? 'on' : ''}">LAN (tarmoq)</button><button data-mode="usb" class="${c.mode === 'usb' ? 'on' : ''}">USB (Windows printer)</button></div></div>
      <div id="lanf" class="${c.mode === 'usb' ? 'hidden' : ''}"><div class="row3"><div class="field"><label>Printer IP manzili</label><input class="inp" id="p-ip" value="${h(c.ip)}" placeholder="masalan 192.168.1.87" inputmode="decimal"></div>
        <div class="field"><label>Port</label><input class="inp" id="p-port" value="${h(c.port || 9100)}" inputmode="numeric"></div>
        <div class="field"><label>Model</label><input class="inp" id="p-model" value="${h(c.model || 'Xprinter')}"></div></div>
        <div class="note" style="margin-bottom:12px">IP manzilni bilish: printerni o'chirib, FEED tugmasini bosib turgan holda yoqing — printer o'z sozlamalarini (IP) chop etadi.</div></div>
      <div id="usbf" class="${c.mode === 'usb' ? '' : 'hidden'}"><div class="field"><label>Windows printeri</label><select class="inp" id="p-name"><option>${h(c.printer_name || '')}</option></select></div></div>
      <div class="field"><label>Chek o'lchami</label><div class="seg" style="grid-template-columns:1fr 1fr" id="pw"><button data-w="80" class="${c.width !== 58 ? 'on' : ''}">80 mm</button><button data-w="58" class="${c.width === 58 ? 'on' : ''}">58 mm</button></div></div>
      <label class="check"><input type="checkbox" id="p-cut" ${c.cut !== false ? 'checked' : ''}> Chekni avtomatik kesish</label>
      <label class="check"><input type="checkbox" id="p-sq" ${c.status_query !== false ? 'checked' : ''}> Qog'oz holatini tekshirish (qog'oz tugasa chek yuborilmaydi)</label>
      <div class="btns" style="margin-top:14px"><button class="btn-x confirm" id="p-save">💾 Saqlash</button><button class="btn-x" id="p-check">🔌 Ulanishni tekshirish</button><button class="btn-x pay" style="grid-column:auto" id="p-test">🧾 Test chek chiqarish</button><button class="btn-x" id="p-re">↻ Qayta ulash</button></div>
    </div>
    <div class="box"><h3>Avtomatik chek</h3>
      <div class="kv"><span>Buyurtma tasdiqlanganda oshxona cheki</span><b>${S.catalog.settings.kitchen_auto ? '✅ Yoqilgan' : "O'chirilgan"}</b></div>
      <div class="kv"><span>To'lovda mijoz cheki</span><b>${S.catalog.settings.customer_auto ? '✅ Yoqilgan' : "O'chirilgan"}</b></div>
      <div class="kv"><span>Oshxona cheki nusxasi</span><b>${S.catalog.settings.kitchen_copies}</b></div>
      <p class="muted" style="font-size:13px">Bu sozlamalar admin panel → POS va printer bo'limida o'zgartiriladi. Chrome chop etish oynasi ishlatilmaydi: cheklar printer xizmati orqali to'g'ridan-to'g'ri printerga boradi.</p>
      <p class="muted" style="font-size:13px">Printer sozlamalari shu kompyuterda (printer xizmatida) saqlanadi. Kompyuter qayta yoqilganda xizmat o'zi ishga tushadi va printerga avtomatik ulanadi.</p></div></div>`;
  let mode = c.mode === 'usb' ? 'usb' : 'lan', width = c.width === 58 ? 58 : 80;
  const loadPrinters = async () => { const r = await agent.listPrinters(); if (r.ok) $('#p-name').innerHTML = (r.printers || []).map(n => `<option ${n === c.printer_name ? 'selected' : ''}>${h(n)}</option>`).join('') || '<option value="">Printer topilmadi</option>'; };
  $('#pmode').onclick = (e) => { const b = e.target.closest('[data-mode]'); if (!b) return; mode = b.dataset.mode; $$('#pmode button').forEach(x => x.classList.toggle('on', x === b)); $('#lanf').classList.toggle('hidden', mode === 'usb'); $('#usbf').classList.toggle('hidden', mode !== 'usb'); if (mode === 'usb') loadPrinters(); };
  if (mode === 'usb') loadPrinters();
  $('#pw').onclick = (e) => { const b = e.target.closest('[data-w]'); if (!b) return; width = Number(b.dataset.w); $$('#pw button').forEach(x => x.classList.toggle('on', x === b)); };
  const busy = (b, t) => { b.disabled = true; const o = b.innerHTML; b.textContent = t; return () => { b.disabled = false; b.innerHTML = o; }; };
  $('#p-save').onclick = async (e) => {
    const done = busy(e.currentTarget, 'Saqlanmoqda…');
    const r = await agent.saveConfig({ mode, ip: $('#p-ip').value.trim(), port: parseInt($('#p-port').value, 10) || 9100, model: $('#p-model').value.trim(), printer_name: $('#p-name').value, width, cut: $('#p-cut').checked, status_query: $('#p-sq').checked });
    done();
    if (!r.ok) return toast('Saqlanmadi', 'bad', r.error, 7000);
    toast('Printer sozlamalari saqlandi', 'ok', r.printer?.ok ? 'Printer ulandi' : (r.printer?.error || ''), 6000);
    refreshSettingsLive(); renderStatus();
  };
  $('#p-check').onclick = async (e) => { const done = busy(e.currentTarget, 'Tekshirilmoqda…'); const r = await agent.checkPrinter(); done(); refreshSettingsLive(); renderStatus(); toast(r.ok && r.printer?.ok ? 'Printer javob berdi' : 'Printer javob bermadi', r.ok && r.printer?.ok ? 'ok' : 'bad', r.printer?.error || r.error || '', 6000); };
  $('#p-test').onclick = async (e) => { const done = busy(e.currentTarget, 'Yuborilmoqda…'); const r = await agent.testPrint(); done(); if (!r.ok) return toast('Test chek yuborilmadi', 'bad', r.error, 7000); toast('Test chek navbatga qo\'yildi', 'info'); watchJob(r.job.id, 'Test chek'); };
  $('#p-re').onclick = async (e) => {
    const done = busy(e.currentTarget, 'Ulanmoqda…');
    await agent.refreshStatus();
    const r = await agent.checkPrinter();
    let n = 0;
    if (r.ok && r.printer?.ok) n = (await agent.retryErrors()).count || 0;
    await agent.pollPrints();
    done(); refreshSettingsLive(); renderStatus();
    toast(r.ok && r.printer?.ok ? 'Printer qayta ulandi' : 'Ulanib bo\'lmadi', r.ok && r.printer?.ok ? 'ok' : 'bad', r.ok && r.printer?.ok ? (n ? `${n} ta xato chek qayta yuborildi` : 'Navbatda xato chek yo\'q') : (r.printer?.error || r.error || ''), 7000);
  };
}

async function watchJob(id, title) {
  for (let i = 0; i < 20; i++) {
    await new Promise(r => setTimeout(r, 700));
    const r = await agent.jobs();
    const j = (r.jobs || []).find(x => x.id === id);
    if (!j) continue;
    if (j.status === 'sent') return toast(`${title} printerga yuborildi`, 'ok', `${j.sent_ms || 0} ms`);
    if (j.status === 'error' || j.status === 'unknown') return toast(`${title} chiqmadi`, 'bad', j.error, 8000);
    if (j.error && j.status === 'pending') toast(`${title}: printer javob bermayapti, qayta urinilmoqda`, 'warn', j.error, 4000);
  }
}

async function settingsQueue(body) {
  const r = await agent.jobs();
  const local = (await db.all('prints')).filter(p => p.status === 'agent_down');
  const S2 = agent.PRINT_STATUS;
  body.innerHTML = `<div class="box">
    ${!r.ok ? `<div class="note bad">${h(r.error)}</div>` : ''}
    ${local.length ? `<div class="note warn" style="margin-bottom:10px">${local.length} ta chek printer xizmatiga yetib bormagan. Xizmat ishga tushganda avtomatik yuboriladi.</div>` : ''}
    <div class="btns" style="margin-bottom:10px"><button class="btn-x" id="q-ref">↻ Yangilash</button><button class="btn-x" id="q-retry">Xato cheklarni qayta yuborish</button></div>
    <div class="note" style="margin-bottom:10px">"Natija noma'lum" — chek printerga qisman yuborilgan bo'lishi mumkin. Avval qog'ozni tekshiring, chiqmagan bo'lsa "Qayta chiqarish" ni bosing. Tizim bunday chekni o'zi qayta chiqarmaydi.</div>
    <table class="t"><tr><th>Vaqt</th><th>Chek</th><th>Buyurtma</th><th>Holat</th><th>Xato</th><th></th></tr>
    ${(r.jobs || []).map(j => { const [l, c] = S2[j.status] || [j.status, '']; return `<tr><td>${dt(j.created_at, false)}</td><td>${h(j.title || j.kind)}</td><td>${h(j.order_ref || '')}</td><td><span class="st ${c}">${l}</span></td><td class="muted" style="font-size:12px;max-width:320px">${h(j.error || '')}</td>
      <td class="r">${j.status === 'error' ? `<button class="fbtn" data-retry="${j.id}">Qayta yuborish</button>` : ''}${['sent', 'unknown', 'error'].includes(j.status) ? ` <button class="fbtn" data-rp="${j.id}" data-st="${j.status}">Qayta chiqarish</button>` : ''}</td></tr>`; }).join('') || '<tr><td colspan="6" class="muted">Navbat bo\'sh</td></tr>'}</table></div>`;
  $('#q-ref').onclick = () => renderView();
  $('#q-retry').onclick = async () => { const x = await agent.retryErrors(); toast(`${x.count || 0} ta chek qayta navbatga qo'yildi`, 'info'); setTimeout(renderView, 800); };
  body.onclick = async (e) => {
    const rt = e.target.closest('[data-retry]');
    if (rt) { const x = await agent.retryJob(rt.dataset.retry); toast(x.ok ? 'Qayta yuborildi' : x.error, x.ok ? 'ok' : 'bad'); return setTimeout(renderView, 800); }
    const rp = e.target.closest('[data-rp]');
    if (rp) {
      if (!(await confirmBox('Qayta chiqarish', rp.dataset.st === 'error' ? 'Chek qayta chiqarilsinmi?' : "Bu chek printerga yuborilgan bo'lishi mumkin. Qog'ozni tekshirdingizmi? Qayta chiqarilsinmi?", 'Qayta chiqarish'))) return;
      const x = await agent.reprintJob(rp.dataset.rp);
      toast(x.ok ? 'Qayta chiqarish navbatga qo\'yildi' : x.error, x.ok ? 'ok' : 'bad');
      setTimeout(renderView, 800);
    }
  };
}

async function settingsSync(body) {
  const ob = await outboxInfo();
  const log = (await kv.get('sync_log')) || [];
  const pending = await db.first('outbox', 30);
  body.innerHTML = `<div class="cards2"><div class="box"><h3>Holat</h3>
      <div class="kv"><span>Server</span><b>${syncState.online ? '🟢 Aloqa bor' : '🔴 Aloqa yo\'q (offline rejim)'}</b></div>
      <div class="kv"><span>Oxirgi muvaffaqiyatli sinxronlash</span><b>${syncState.lastOk ? dt(syncState.lastOk) : '—'}</b></div>
      <div class="kv"><span>Serverga yuborilmagan amallar</span><b>${ob.count}${ob.count ? ` (eng eskisi ${Math.round(ob.oldest_sec / 60)} daqiqa)` : ''}</b></div>
      <div class="kv"><span>Serverda xato amallar</span><b>${syncState.failedOps}</b></div>
      ${syncState.lastError ? `<div class="note bad" style="margin-top:8px">Oxirgi xato: ${h(syncState.lastError)}</div>` : ''}
      <p class="muted" style="font-size:13px">Internet bo'lmasa ham savdo davom etadi: hamma narsa shu kompyuterda saqlanadi va internet qaytganda o'zi yuboriladi. Kassir tugma bosishi shart emas.</p>
      <button class="btn-x confirm" id="sy-now">⇅ Hozir sinxronlash</button></div>
    <div class="box"><h3>Navbatdagi amallar</h3><table class="t">${pending.map(o => `<tr><td>${dt(o.created_at, false)}</td><td>${h(o.type)}</td><td class="muted" style="font-size:12px">${o.tries ? o.tries + ' urinish · ' + h(o.last_error) : ''}</td></tr>`).join('') || '<tr><td class="muted">Navbat bo\'sh — hammasi yuborilgan</td></tr>'}</table></div>
    <div class="box"><h3>Sinxronlash jurnali</h3><table class="t">${log.slice(0, 30).map(l => `<tr><td>${dt(l.at, false)}</td><td>${h(l.type)}</td><td><span class="st ${l.status === 'ok' ? 'green' : 'red'}">${h(l.status)}</span></td><td class="muted" style="font-size:12px">${h(l.error)}</td></tr>`).join('') || '<tr><td class="muted">Bo\'sh</td></tr>'}</table></div></div>`;
  $('#sy-now').onclick = () => { kick(10); toast('Sinxronlash boshlandi', 'info'); setTimeout(renderView, 1500); };
}

async function settingsDevice(body) {
  const est = navigator.storage?.estimate ? await navigator.storage.estimate() : null;
  const persisted = navigator.storage?.persisted ? await navigator.storage.persisted() : false;
  body.innerHTML = `<div class="cards2"><div class="box"><h3>Qurilma</h3>
    <div class="kv"><span>Kassa versiyasi</span><b>${VERSION}</b></div>
    <div class="kv"><span>Server</span><b>${h(new URL('../', location.href).href)}</b></div>
    <div class="kv"><span>Lokal saqlash</span><b>${persisted ? '✅ Doimiy (brauzer o\'chirmaydi)' : '⚠️ Oddiy'}</b></div>
    ${est ? `<div class="kv"><span>Ishlatilgan joy</span><b>${(est.usage / 1048576).toFixed(1)} MB</b></div>` : ''}
    <div class="kv"><span>Menyu versiyasi</span><b>${S.catalog.version}</b></div>
    <div class="btns" style="margin-top:12px"><button class="btn-x" id="d-cat">Menyuni yangilash</button><button class="btn-x danger" id="d-unlink">Qurilmani uzish</button></div>
    </div></div>`;
  $('#d-cat').onclick = async () => { const r = await loadCatalog(); toast(r ? 'Menyu yangilandi' : "Server bilan aloqa yo'q", r ? 'ok' : 'bad'); };
  $('#d-unlink').onclick = async () => {
    const n = await db.count('outbox');
    if (n > 0) return toast(`${n} ta amal hali serverga yuborilmagan. Avval internetni ulang.`, 'bad', '', 6000);
    if (!(await confirmBox('Qurilmani uzish', "Kassa serverdan uziladi. Qayta ulash uchun admin logini kerak bo'ladi.", 'Uzish', true))) return;
    await kv.del('device_token');
    location.reload();
  };
}

// ====================================================================
// Server diagnostikasi uchun holat
// ====================================================================
async function collectStatus() {
  const a = agent.agentState;
  return {
    version: VERSION,
    agent: { ok: a.reachable === true, version: a.version, error: a.reachable === false ? a.error : '' },
    printer: a.printer ? { configured: a.printer.configured, ok: a.printer.ok, error: a.printer.error || '', target: a.printer.target, paper: a.printer.paper } : undefined,
    queue: a.queue || undefined,
    shift: S.shift ? { number: S.shift.number, opened_at: S.shift.opened_at } : null,
  };
}

// Klaviatura: Enter — tasdiqlash (modal bo'lmasa)
document.addEventListener('keydown', (e) => {
  if (!S.employee || hasModal() || UI.view !== 'sale') return;
  if (e.key === 'F2') { e.preventDefault(); onOrderBtn('confirm'); }
  if (e.key === 'F4') { e.preventDefault(); onOrderBtn('pay'); }
});

boot().catch(e => {
  app.innerHTML = `<div class="lock"><div class="box" style="max-width:520px"><h2>Kassa ishga tushmadi</h2><div class="note bad">${h(e.message || e)}</div><p class="muted">Sahifani yangilang (F5). Takrorlansa, administratorga murojaat qiling.</p></div></div>`;
});
