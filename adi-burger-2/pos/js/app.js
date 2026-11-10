// ADI BURGER 2.0 — kassa (POS) ilovasi.

import { db, kv, tx, persist } from './db.js';
import { api, enqueue, kick, configureSync, onSync, syncState, outboxInfo, opPut } from './sync.js';
import * as agent from './agent.js';
import { kitchenDoc, customerDoc, cancelDoc, zDoc, xDoc, combinedDoc, rulerDoc, payLabel } from './receipt.js';
import {
  S, saveOrder, newDraft, addProduct, setQty, cancelSent, confirmOrder, payOrder, cancelWholeOrder, openOrders, drafts,
  orderTotal, itemsTotal, paidTotal, unsentLines, openShift, shiftReport, closeShift, localReport, nextNumber,
} from './model.js';
import { uuid, fmt, som, h, dt, pad2, nowIso, localDate, toast, modal, confirmBox, promptBox, closeAllModals, hasModal, beep, alarmStart, alarmStop, pbkdf2Hex, debounce, audio, $, $$ } from './util.js';

const VERSION = '2.2.2';
const app = document.getElementById('app');
const UI = { view: 'tables', mode: 'table', zone: 'all', billMap: {}, showSearch: false, orderQ: '', orderType: 'all', cur: null, sel: null, cat: null, q: '', orderFilter: 'open', settingsTab: 'printer', reportPeriod: 'today' };
const TYPE_NAMES = { takeaway: 'Olib ketish', table: 'Stolda', phone: 'Telefon', delivery: 'Yetkazish' };
const CANCEL_REASONS = ['Mijoz voz kechdi', "Noto'g'ri kiritildi", 'Taom tugagan', 'Uzoq kutildi'];

// ====================================================================
// Ishga tushirish
// ====================================================================
async function boot() {
  persist();
  audio.muted = (await kv.get('sound_off')) === true;
  if ('serviceWorker' in navigator) {
    const had = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.register('sw.js').catch(() => {});
    // Yangi versiya yuklanganda kassa o'zi qayta ochilmaydi — kassir qulay paytda "Yangilash" ni bosadi
    navigator.serviceWorker.addEventListener('controllerchange', () => { if (had) updateBanner(); });
  }
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
  configureSync({ status: collectStatus, onPull: handlePull, onAuthFail: (e) => deviceLost(e) });
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
    if (S.employee && ['sale', 'tables', 'list'].includes(UI.view) && !hasModal() && document.activeElement?.tagName !== 'INPUT') renderView();
    return r;
  }
  return null;
}

// ====================================================================
// Qurilmani ulash (bir marta)
// ====================================================================
/** Server bu kassani tanimasa (baza yangilangan, qurilma o'chirilgan): kassa o'zi qayta ulash oynasini ochadi. Lokal ma'lumot saqlanadi. */
function deviceLost(e) {
  const msg = "Server bu kassani tanimayapti (baza yangilangan yoki qurilma o'chirilgan). Administrator logini bilan qayta ulang. Savdo ma'lumotlari saqlanadi.";
  if (!S.employee) return renderSetup(msg);
  if ($('#banner')) return;
  banner('Server kassani tanimayapti.', 'red');
  const b = $('#banner'); if (!b) return;
  const r = document.createElement('button'); r.textContent = 'Qayta ulash'; r.onclick = () => { b.remove(); closeAllModals(); S.employee = null; renderSetup(msg); };
  b.insertBefore(r, b.lastChild);
}

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
    await kv.set('pin_lock', { fails: 0, until: 0 });
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
    <img src="icons/logo.png" alt="ADI BURGER" style="height:74px;display:block;margin:0 auto 6px" draggable="false">
    <div class="muted">PIN-kodni kiriting</div>
    <div class="pin-dots" id="dots"><i></i><i></i><i></i><i></i></div>
    <div class="note bad hidden" id="pin-err" style="margin-bottom:12px"></div>
    <div class="pad" id="pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map(n => `<button data-n="${n}">${n}</button>`).join('')}<button class="fn" data-c>Tozalash</button><button data-n="0">0</button><button class="fn" data-b>⌫</button></div>
    <button class="link-btn" id="to-login">Login va parol bilan kirish</button>
    <div class="muted" style="margin-top:10px;font-size:12px">v${VERSION} · ${S.shift ? 'Smena #' + S.shift.number + ' ochiq' : 'Smena yopiq'}</div>
  </div></div>`;
  $('#to-login').onclick = () => renderLoginForm();
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

/** Login va parol bilan kirish (kassir yoki administrator). Parol offline ham tekshiriladi (PBKDF2). */
function renderLoginForm(err = '') {
  document.onkeydown = null;
  app.innerHTML = `<div class="lock"><div class="lock-card box" style="width:380px;text-align:left">
    <div style="display:flex;gap:10px;align-items:center"><div class="logo-mark">AB</div><div><h1 style="margin:0;font-size:20px">Login bilan kirish</h1><div class="muted" style="font-size:13px">Admin panelda berilgan login va parol</div></div></div>
    <div id="lg-err" class="note bad ${err ? '' : 'hidden'}" style="margin:12px 0">${h(err)}</div>
    <div class="field" style="margin-top:12px"><label>Login</label><input class="inp" id="lg-user" autocomplete="username" autocapitalize="none"></div>
    <div class="field"><label>Parol</label><input class="inp" id="lg-pass" type="password" autocomplete="current-password"></div>
    <button class="btn-x pay" id="lg-go" style="width:100%">Kirish</button>
    <button class="link-btn" id="lg-back">← PIN bilan kirish</button>
  </div></div>`;
  const fail = (m) => { const e = $('#lg-err'); e.textContent = m; e.classList.remove('hidden'); };
  const submit = async () => {
    const lock = await pinLockInfo();
    if (lock.until > Date.now()) return fail(`Juda ko'p xato. ${Math.ceil((lock.until - Date.now()) / 1000)} soniyadan keyin urinib ko'ring.`);
    const login = $('#lg-user').value.trim().toLowerCase();
    const pass = $('#lg-pass').value;
    if (!login || !pass) return fail('Login va parolni kiriting');
    const emp = (S.catalog?.employees || []).find(x => x.login && x.lhash && x.login.toLowerCase() === login);
    const ok = emp && (await pbkdf2Hex(pass, emp.lsalt, S.catalog.pin_iterations || 60000)) === emp.lhash;
    if (!ok) {
      lock.fails++;
      if (lock.fails >= 5) lock.until = Date.now() + Math.min(300, 30 * 2 ** (lock.fails - 5)) * 1000;
      await kv.set('pin_lock', lock);
      return fail("Login yoki parol noto'g'ri");
    }
    await kv.set('pin_lock', { fails: 0, until: 0 });
    S.employee = emp;
    afterLogin();
  };
  $('#lg-go').onclick = submit;
  $('#lg-pass').onkeydown = (e) => { if (e.key === 'Enter') submit(); };
  $('#lg-back').onclick = () => renderLock();
  setTimeout(() => $('#lg-user').focus(), 30);
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
const svg = (d) => `<svg class="ic" viewBox="0 0 24 24">${d}</svg>`;
const IC = {
  table: svg('<path d="M3 7h18M5 7v12M19 7v12M8 7v6h8V7"/>'),
  bag: svg('<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8M10 12v4M14 12v4"/>'),
  moto: svg('<circle cx="6" cy="17" r="2.5"/><circle cx="18" cy="17" r="2.5"/><path d="M8.5 17h7l-2-7h-3M13.5 12.5H18l2 3M3 12h5.5l1.5 5"/>'),
  search: svg('<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>'),
  refresh: svg('<path d="M20 5v5h-5M4 19v-5h5"/><path d="M19.4 10A8 8 0 0 0 5.6 7.5M4.6 14a8 8 0 0 0 13.8 2.5"/>'),
  lock: svg('<rect x="5" y="10.5" width="14" height="10" rx="2" fill="currentColor"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>'),
  user: svg('<circle cx="12" cy="8.5" r="4" fill="currentColor"/><path d="M4.5 20.5c1-4 4-5.5 7.5-5.5s6.500 1.500 7.500 5.500" fill="currentColor"/>'),
  usr: svg('<circle cx="12" cy="8" r="3.500"/><path d="M5.500 20c.8-3.500 3.200-5 6.500-5s5.700 1.500 6.500 5"/>'),
  dish: svg('<path d="M3 17h18M5 17a7 7 0 0 1 14 0M12 7.500V10M10.500 7.500h3"/><path d="M3 20h18"/>'),
  grid: svg('<rect x="3.500" y="3.500" width="17" height="17" rx="3"/><circle cx="8.500" cy="8.500" r="2"/><circle cx="15.500" cy="8.500" r="2"/><circle cx="8.500" cy="15.500" r="2"/><path d="M15.500 13.500l2 2-2 2-2-2z"/>'),
  clock: svg('<circle cx="12" cy="12" r="8.500"/><path d="M12 7.500V12l3 2"/>'),
  pin: svg('<path d="M12 21s6.500-5.600 6.500-10.500a6.500 6.500 0 0 0-13 0C5.500 15.400 12 21 12 21z"/><circle cx="12" cy="10.500" r="2.200"/>'),
  phone: svg('<path d="M6 3.500h3l1.500 4-2 1.500a11 11 0 0 0 6.500 6.500l1.500-2 4 1.500v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5.500a2 2 0 0 1 2-2z"/>'),
  comment: svg('<path d="M4 5.500h16v10.500H10l-4 3.500v-3.500H4z"/><path d="M8 9.500h8M8 12.500h5"/>'),
  swap: svg('<rect x="3.500" y="3.500" width="7" height="7" rx="1"/><rect x="13.500" y="13.500" width="7" height="7" rx="1"/><path d="M14 4.500h4.500a2 2 0 0 1 2 2V10M10 19.500H5.500a2 2 0 0 1-2-2V14"/>'),
  receipt: svg('<path d="M6 3.500h12v17l-2-1.500-2 1.500-2-1.500-2 1.500-2-1.500-2 1.500z"/><path d="M9 8h6M9 11.500h6M9 15h4"/>'),
  cart: svg('<path d="M3 4.500h2.500l2.200 10h9.800l2-7.500H7"/><circle cx="9.500" cy="19" r="1.500" fill="currentColor"/><circle cx="16.500" cy="19" r="1.500" fill="currentColor"/>'),
  cash: svg('<rect x="3" y="6.500" width="18" height="11" rx="1.500" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="2.500" fill="#fff" stroke="none"/>'),
  x: svg('<path d="M6 6l12 12M18 6L6 18"/>'),
  expand: svg('<path d="M14 4h6v6M20 4l-7 7M10 20H4v-6M4 20l7-7"/>'),
  tg: svg('<path d="M20.500 4.500L3.500 11l5.500 2 2 6 3-4 4.500 3.500z"/><path d="M9 13l8-6"/>'),
  list: svg('<path d="M8 6.500h12M8 12h12M8 17.500h12"/><circle cx="4.200" cy="6.500" r="1" fill="currentColor"/><circle cx="4.200" cy="12" r="1" fill="currentColor"/><circle cx="4.200" cy="17.500" r="1" fill="currentColor"/>'),
  chart: svg('<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>'),
  gear: svg('<circle cx="12" cy="12" r="3"/><path d="M12 3v2.500M12 18.500V21M3 12h2.500M18.500 12H21M5.600 5.600l1.800 1.800M16.600 16.600l1.800 1.800M5.600 18.400l1.800-1.800M16.600 7.400l1.800-1.800"/>'),
  out: svg('<path d="M10 4.500H5.500v15H10M15 8l4 4-4 4M19 12H9.500"/>'),
};
const MODES = [['tables', 'table', 'Stolda', 'n-table'], ['takeaway', 'bag', 'Olib ketish', 'n-takeaway'], ['delivery', 'moto', 'Yetkazish', 'n-delivery']];

function logoHtml() {
  return `<img class="logo-img" id="logo" src="icons/logo.png" alt="${h(S.catalog?.settings?.brand_name || 'ADI BURGER')}" draggable="false">`;
}

/** Logoni 7 marta ketma-ket bosish — admin panelni ochadi (yashirin kirish, parol baribir so'raladi). */
let logoTaps = [];
function onLogoTap() {
  const now = Date.now();
  logoTaps = logoTaps.filter(t => now - t < 4000);
  logoTaps.push(now);
  if (logoTaps.length >= 7) { logoTaps = []; window.open(new URL('../admin/', location.href).href, 'adi-admin'); }
}

function renderShell() {
  if (!S.catalog.tables.length && UI.view === 'tables') { UI.view = 'list'; UI.mode = 'takeaway'; }
  app.innerHTML = `<div class="shell">
    <header class="topbar">
      <div class="brand">${logoHtml()}</div>
      <nav class="modes" id="tabs">${MODES.map(([k, i, l, bid]) => `<button class="mtab tab mode" data-tab="${k}">${IC[i]}<span class="lbl">${l}</span><span class="badge hidden" id="${bid}">0</span></button>`).join('')}</nav>
      <div class="tools">
        <button class="tool" id="searchbtn" title="Qidirish">${IC.search}</button>
        <button class="tool" id="refreshbtn" title="Yangilash">${IC.refresh}</button>
        <button class="tool lk" id="lockbtn" title="Qulflash">${IC.lock}</button>
        <button class="tool user" id="userbtn" title="Menyu">${IC.user}<span class="badge blue hidden" id="tg-badge">0</span></button>
      </div>
    </header>
    <main class="view" id="view"></main>
    <footer class="statusbar">
      <span class="pill" id="st-net" title="Server bilan aloqa"><span class="dot"></span><span>Server</span></span>
      <span class="pill" id="st-inet" title="Internet"><span class="dot"></span><span>Internet</span></span>
      <span class="pill" id="st-prn" title="Printer"><span class="dot"></span><span>Printer</span></span>
      <span class="pill" id="st-sync" title="Serverga yuborilmagan amallar"><span>⇅</span><span class="num">0</span></span>
      <span class="sp"></span>
      <span id="st-shift"></span>
      <span class="mono">Versiya: ${VERSION}</span>
      <span class="mono" id="clock"></span>
      <button class="fsbtn" id="fsbtn" title="To'liq ekran">${IC.expand}</button>
    </footer>
  </div>`;
  $('#tabs').onclick = (e) => { const t = e.target.closest('[data-tab]'); if (t) go(t.dataset.tab); };
  $('#logo').onclick = onLogoTap;
  $('#lockbtn').onclick = () => { leaveSale(); renderLock(); };
  $('#userbtn').onclick = (e) => { e.stopPropagation(); userMenu(); };
  $('#searchbtn').onclick = () => toggleSearch();
  $('#refreshbtn').onclick = () => refreshAll();
  $('#fsbtn').onclick = () => { if (document.fullscreenElement) document.exitFullscreen?.(); else document.documentElement.requestFullscreen?.().catch(() => {}); };
  $('#st-prn').onclick = () => { UI.settingsTab = 'printer'; go('settings'); };
  $('#st-sync').onclick = () => { UI.settingsTab = 'sync'; go('settings'); };
  $('#st-net').onclick = () => { UI.settingsTab = 'sync'; go('settings'); };
  $('#st-inet').onclick = () => { UI.settingsTab = 'sync'; go('settings'); };
  tickClock();
  renderStatus();
  renderView();
  updateTgBadge();
}

/** O'ng tepadagi foydalanuvchi menyusi: Telegram, buyurtmalar, smena, hisobot, sozlamalar, chiqish. */
function userMenu() {
  const old = $('#umenu');
  if (old) return old.remove();
  const el = document.createElement('div');
  el.id = 'umenu'; el.className = 'umenu';
  const items = [['telegram', 'tg', 'Telegram buyurtmalari'], ['orders', 'list', 'Buyurtmalar tarixi'], ['shift', 'clock', 'Smena (ochish / yopish)'], ['settings', 'receipt', 'Printer va aloqa']];
  const tgN = $('#tg-badge')?.classList.contains('hidden') ? 0 : Number($('#tg-badge')?.textContent || 0);
  el.innerHTML = `<div class="who"><b>${h(S.employee.name)}</b><span>${S.employee.role === 'admin' ? 'Administrator' : 'Kassir'} · ${S.shift ? 'Smena #' + S.shift.number : 'Smena yopiq'}</span></div>
    ${items.map(([k, i, l]) => `<button data-go="${k}">${IC[i]}<span>${l}</span>${k === 'telegram' && tgN ? `<span class="badge blue">${tgN}</span>` : ''}</button>`).join('')}
    <button class="out" data-go="lock">${IC.out}<span>Chiqish (qulflash)</span></button>`;
  document.body.append(el);
  el.onclick = (e) => {
    const b = e.target.closest('[data-go]'); if (!b) return;
    el.remove();
    if (b.dataset.go === 'lock') { leaveSale(); return renderLock(); }
    go(b.dataset.go);
  };
  setTimeout(() => document.addEventListener('pointerdown', function off(e) {
    if (!el.isConnected) return document.removeEventListener('pointerdown', off);
    if (!el.contains(e.target) && !e.target.closest('#userbtn')) { el.remove(); document.removeEventListener('pointerdown', off); }
  }), 0);
}

function toggleSearch() {
  if (UI.view === 'sale') {
    UI.showSearch = !UI.showSearch;
    if (!UI.showSearch) UI.q = '';
    renderView();
    if (UI.showSearch) setTimeout(() => $('#q')?.focus(), 30);
  } else {
    go('orders');
    setTimeout(() => $('#oq')?.focus(), 60);
  }
}

async function refreshAll() {
  const b = $('#refreshbtn');
  b.classList.add('spin');
  kick(10);
  agent.refreshStatus();
  const r = await loadCatalog();
  b.classList.remove('spin');
  toast(r ? 'Yangilandi' : "Server bilan aloqa yo'q", r ? 'ok' : 'warn', r ? '' : "Kassa lokal ma'lumot bilan ishlayapti. Internet qaytganda o'zi yangilanadi.");
  if (!hasModal()) renderView();
}

/** Savdo oynasidan chiqishda joriy hisob lokal saqlanadi (bo'sh qoralama o'chadi). */
function leaveSale() {
  $('#umenu')?.remove();
  if (UI.view === 'sale' && UI.cur) persistCur();
  UI.cur = null; UI.sel = null;
}

function go(view) {
  if (view !== 'sale') leaveSale();
  if (view === 'takeaway' || view === 'delivery') { UI.mode = view; UI.view = 'list'; }
  else if (view === 'tables') { UI.mode = 'table'; UI.view = 'tables'; }
  else UI.view = view;
  renderView();
}

function openSale(o) {
  if (UI.view === 'sale' && UI.cur && UI.cur !== o) persistCur();
  UI.cur = o; UI.sel = null; UI.view = 'sale';
  UI.mode = o.type === 'table' ? 'table' : o.type === 'delivery' ? 'delivery' : 'takeaway';
  renderView();
}

/** Tasdiqlash, to'lov yoki bekor qilishdan keyin shu rejimning ro'yxatiga qaytadi. */
function backToList(type) {
  UI.cur = null; UI.sel = null;
  if (type === 'table') { UI.mode = 'table'; UI.view = 'tables'; }
  else { UI.mode = type === 'delivery' ? 'delivery' : 'takeaway'; UI.view = 'list'; }
  renderView();
}

/** Qaysi tepadagi tab faol. */
function activeTab() {
  if (UI.view === 'sale' && UI.cur) { const t = UI.cur.type; return t === 'table' ? 'tables' : t === 'delivery' ? 'delivery' : 'takeaway'; }
  if (UI.view === 'list') return UI.mode;
  return UI.view;
}

const TG_ACTIVE = ['new', 'accepted', 'ready'];

/** Tepadagi tablarda faol (ochiq) buyurtmalar soni. Telegram buyurtmalari ham qo'shiladi. */
async function updateCounts() {
  if (!S.employee) return;
  const n = { table: 0, takeaway: 0, delivery: 0 };
  const busy = new Set();
  for (const o of await openOrders()) {
    if (o.type === 'table') busy.add(o.table_id);
    else n[o.type === 'delivery' ? 'delivery' : 'takeaway']++;
  }
  n.table = busy.size;
  for (const o of await db.all('tg')) if (TG_ACTIVE.includes(o.status)) n[o.type === 'delivery' ? 'delivery' : 'takeaway']++;
  for (const [k, id] of [['table', 'n-table'], ['takeaway', 'n-takeaway'], ['delivery', 'n-delivery']]) {
    const b = $('#' + id);
    if (b) { b.textContent = n[k]; b.classList.toggle('hidden', !n[k]); }
  }
}

function tickClock() {
  const c = $('#clock');
  if (c) { const d = new Date(); c.textContent = `${pad2(d.getDate())}.${pad2(d.getMonth() + 1)}.${d.getFullYear()} | ${pad2(d.getHours())}:${pad2(d.getMinutes())}`; }
}

function renderStatus() {
  if (!S.employee || !$('#st-net')) return;
  const net = $('#st-net');
  const online = syncState.online && navigator.onLine;
  net.querySelector('.dot').className = 'dot ' + (syncState.lastOk ? (online ? 'ok' : 'bad') : (online ? 'warn' : 'bad'));
  net.querySelector('span:last-child').textContent = online ? 'Server' : 'Offline';
  $('#st-inet .dot').className = 'dot ' + (navigator.onLine ? 'ok' : 'bad');
  const sy = $('#st-sync');
  sy.querySelector('.num').textContent = syncState.outbox;
  sy.title = `Serverga yuborilmagan: ${syncState.outbox} ta amal` + (syncState.lastError ? `\nOxirgi xato: ${syncState.lastError}` : '');
  sy.style.color = syncState.outbox > 0 ? '#c97a00' : '';
  sy.style.fontWeight = syncState.outbox > 0 ? '700' : '';
  const a = agent.agentState;
  const p = $('#st-prn');
  let cls = 'warn', txt = 'Printer';
  if (a.reachable === false) { cls = 'bad'; txt = 'Printer xizmati yo\'q'; }
  else if (a.printer && !a.printer.configured) { cls = 'warn'; txt = 'Printer sozlanmagan'; }
  else if (a.printer && a.printer.ok) { cls = 'ok'; txt = 'Printer'; }
  else if (a.printer) { cls = 'bad'; txt = 'Printer xato'; }
  const bad = (a.queue?.error || 0) + (a.queue?.unknown || 0);
  p.querySelector('.dot').className = 'dot ' + (bad && cls === 'ok' ? 'warn' : cls);
  p.querySelector('span:last-child').textContent = txt + (bad ? ` (${bad})` : '');
  p.title = a.error || a.printer?.error || (a.printer?.target ? 'Ulangan: ' + a.printer.target : '');
  $('#st-shift').textContent = S.shift ? `Smena #${S.shift.number} · ${S.employee.name}` : 'Smena yopiq';
  if (UI.view === 'settings') refreshSettingsLive();
}

function renderView() {
  if (!S.employee) return;
  const at = activeTab();
  $$('#tabs .tab').forEach(t => t.classList.toggle('on', t.dataset.tab === at));
  updateCounts();
  const v = $('#view');
  if (!v) return;
  v.onclick = null;
  ({ sale: viewSale, tables: viewTables, list: viewList, orders: viewOrders, telegram: viewTelegram, shift: viewShift, reports: viewReports, settings: viewSettings }[UI.view])(v);
}

function updateBanner() {
  banner('Kassaning yangi versiyasi tayyor.', '');
  const b = $('#banner'); if (!b) return;
  const r = document.createElement('button'); r.textContent = 'Yangilash'; r.onclick = () => location.reload();
  b.insertBefore(r, b.lastChild);
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

const tableById = (id) => S.catalog.tables.find(t => t.id === id);
const zoneOf = (t) => String(t?.zone || '').trim();
/** Stol raqami: "Stol 3" -> "3" (kassada # bilan ko'rsatiladi). */
const tableNo = (t) => (t ? String(t.name).replace(/^\s*stol\s*/i, '') || t.name : '?');
function tableName(id) {
  const t = tableById(id);
  if (!t) return 'Stol';
  return (/^\d+$/.test(t.name.trim()) ? 'Stol ' + t.name.trim() : t.name) + (zoneOf(t) ? ' · ' + zoneOf(t) : '');
}
const empName = (id) => (S.catalog.employees.find(e => e.id === Number(id))?.name || '').toUpperCase();
const sameKind = (a, b) => (a === 'phone' ? 'takeaway' : a) === (b === 'phone' ? 'takeaway' : b);

function viewSale(v) {
  const cats = S.catalog.categories;
  if (UI.cat === null) UI.cat = cats[0]?.id ?? 'all';
  v.innerHTML = `<div class="sale">
    <aside class="side" id="cats"><button class="sico ${UI.cat === 'all' ? 'on' : ''}" data-cat="all" title="Hammasi">${IC.dish}</button>
      ${cats.map(c => `<button class="cat ${UI.cat === c.id ? 'on' : ''}" data-cat="${c.id}">${h(c.name)}</button>`).join('')}</aside>
    <section class="center">
      <div class="searchbar ${UI.showSearch ? '' : 'hidden'}"><div class="search"><span class="s-ico">${IC.search}</span><input id="q" placeholder="Mahsulot qidirish…" value="${h(UI.q)}" autocomplete="off"><button class="s-x" id="qx">✕</button></div></div>
      <div class="products" id="prods"></div>
    </section>
    <section class="order" id="order"></section>
  </div>`;
  renderOrderPanel();
  renderProducts();
  refreshBills();
  $('#cats').onclick = (e) => {
    const b = e.target.closest('[data-cat]'); if (!b) return;
    UI.cat = b.dataset.cat === 'all' ? 'all' : Number(b.dataset.cat);
    $$('#cats [data-cat]').forEach(x => x.classList.toggle('on', x === b));
    renderProducts();
    $('#prods').scrollTop = 0;
  };
  $('#q').oninput = debounce((e) => { UI.q = e.target.value; renderProducts(); }, 120);
  $('#qx').onclick = () => { UI.showSearch = false; UI.q = ''; renderView(); };
  $('#prods').onclick = (e) => { const b = e.target.closest('[data-pid]'); if (b) onProduct(Number(b.dataset.pid)); };
}

function renderProducts() {
  const box = $('#prods');
  if (!box) return;
  const q = UI.q.trim().toLowerCase();
  const o = cur();
  const inCart = {};
  o.items.forEach(i => { if (i.product_id) inCart[i.product_id] = (inCart[i.product_id] || 0) + i.qty; });
  // Qidiruvda barcha bo'limlardan izlanadi
  const list = S.catalog.products.filter(p => (q ? p.name.toLowerCase().includes(q) : (UI.cat === 'all' || p.category_id === UI.cat)));
  box.innerHTML = list.length ? list.map(p => `<button class="prod ${p.available ? '' : 'off'}" data-pid="${p.id}">
      <div class="img" ${p.image ? `style="background-image:url('${h(p.image)}')"` : ''}>${p.image ? '' : (S.catalog.categories.find(c => c.id === p.category_id)?.icon || '🍔')}</div>
      <div class="info"><div class="nm">${h(p.name)}</div><div class="un">dona</div><div class="pr num">${fmt(p.price)}<small>UZS</small></div></div>
      ${inCart[p.id] ? `<span class="inc">${inCart[p.id]}</span>` : ''}</button>`).join('')
    : `<div class="muted" style="grid-column:1/-1;text-align:center;padding:40px">Hech narsa topilmadi</div>`;
}

/** Hisob-1..4 tablari: stolda — shu stolning hisoblari; olib ketish/yetkazishda — parallel tasdiqlanmagan buyurtmalar. */
async function refreshBills() {
  const o = UI.cur;
  if (!o || UI.view !== 'sale') return;
  const open = await openOrders();
  const dr = (await drafts()).filter(d => d.items.length);
  const same = (x) => x.uuid !== o.uuid && (o.type === 'table'
    ? x.type === 'table' && x.table_id === o.table_id
    : o.status === 'draft' && x.status === 'draft' && sameKind(x.type, o.type));
  const others = [...open, ...dr].filter(same);
  if (UI.cur !== o) return;
  UI.billMap = {};
  for (const x of others) UI.billMap[x.bill_no || 1] = x;
  const bn = o.bill_no || 1;
  $$('#order .bill').forEach(b => b.classList.toggle('has', Number(b.dataset.bill) === bn ? o.items.length > 0 : !!UI.billMap[b.dataset.bill]));
  const el = $('#onums');
  if (el) {
    const nums = [...others.filter(x => o.type === 'table'), o].filter(x => x.number).sort((a, b) => (a.bill_no || 1) - (b.bill_no || 1)).map(x => x.number);
    el.innerHTML = nums.length ? 'Buyurtma: ' + nums.map(n => `<i>${h(n)}</i>`).join('') : '';
    el.classList.toggle('hidden', !nums.length);
  }
}

async function switchBill(n) {
  const o = cur();
  if ((o.bill_no || 1) === n) return;
  if (o.type !== 'table' && o.status !== 'draft') return;
  await persistCur();
  await refreshBills();
  if (UI.cur !== o) return;
  UI.cur = UI.billMap[n] || newDraft(o.type, o.type === 'table' ? { table_id: o.table_id, bill_no: n } : { bill_no: n });
  UI.sel = null;
  renderOrderPanel(); renderProducts(); refreshBills();
}

function statusBadge(o) {
  return { draft: '<span class="st">Tasdiqlanmagan</span>', accepted: '<span class="st yellow">Ochiq</span>', done: '<span class="st green">To\'langan</span>', cancelled: '<span class="st red">Bekor</span>' }[o.status] || '';
}

function renderOrderPanel() {
  const box = $('#order');
  if (!box) return;
  const o = cur();
  const total = orderTotal(o);
  const unsent = unsentLines(o).length;
  const locked = o.status === 'done' || o.status === 'cancelled';
  const isDraft = o.status === 'draft';
  const bn = o.bill_no || 1;
  const t = o.type === 'table' ? tableById(o.table_id) : null;
  let title;
  if (o.type === 'table') title = `<span class="hash">#</span><b>${h(tableNo(t))}</b>${t && zoneOf(t) ? ' - ' + h(zoneOf(t)) : (t ? '' : ' Stol tanlanmagan')}`;
  else if (isDraft && !o.number) title = 'Yangi buyurtma';
  else title = o.type === 'delivery' ? 'Yetkazish' : 'Olib ketish';
  const showCust = o.type === 'delivery' || o.phone || o.customer_name;
  box.innerHTML = `
    <div class="bills" id="bills">${[1, 2, 3, 4].map(n => `<button class="bill ${n === bn ? 'on' : ''}" data-bill="${n}" ${n !== bn && (locked || (o.type !== 'table' && !isDraft)) ? 'disabled' : ''}>Hisob-${n}</button>`).join('')}</div>
    <div class="order-head">
      <h2>${title}${locked ? statusBadge(o) : ''}</h2>
      <div class="onums ${o.number ? '' : 'hidden'}" id="onums">${o.number ? `Buyurtma: <i>${h(o.number)}</i>` : ''}</div>
      ${showCust ? `<button class="cust" data-m="cust"><b>${h(o.phone || 'Telefon kiritilmagan')}${o.customer_name ? ' · ' + h(o.customer_name) : ''}</b>${o.type === 'delivery' ? `<span>${h(o.address || 'Manzil kiritilmagan')}</span>` : ''}</button>` : ''}
      ${o.comment ? `<button class="cmt-row" data-a="note0">💬 ${h(o.comment)}</button>` : ''}
    </div>
    <div class="lhead"><span>Soni</span><span>Nomi</span><span>Narxi</span></div>
    <div class="lines" id="lines">${o.items.length ? o.items.map(i => lineHtml(i, o)).join('') : `<div class="empty"><div>Chapdan mahsulot tanlang</div></div>`}</div>
    <div class="totals">
      <div class="r"><span>Tovarlar</span><span class="num">${fmt(itemsTotal(o))}</span></div>
      ${o.type === 'delivery' ? `<div class="r fee"><span>Yetkazish</span><input id="fee" class="fee-inp num" inputmode="numeric" placeholder="0" autocomplete="off" value="${o.delivery_fee ? o.delivery_fee : ''}" ${locked ? 'disabled' : ''}></div>` : ''}
      ${paidTotal(o) ? `<div class="r"><span>To'langan</span><span class="num">${fmt(paidTotal(o))}</span></div>` : ''}
      <div class="r grand"><span>Jami:</span><span class="num" id="grand-v">${fmt(total)}</span></div>
    </div>
    ${locked ? `<div class="order-actions"><button class="btn-x confirm" data-o="new">Yangi buyurtma</button><button class="btn-x ghost" data-a="rebill">${IC.receipt} Chek nusxasi</button></div>` : `
    <div class="sbtns">
      <button class="sbtn" data-a="note"><span>Izoh</span>${IC.comment}</button>
      <button class="sbtn" data-a="type"><span>Tur</span>${IC.swap}</button>
      <button class="sbtn ${o.bill_printed ? 'on' : ''}" data-a="bill" ${o.items.some(i => i.qty > 0) ? '' : 'disabled'}><span>Chek</span>${IC.receipt}</button>
      <button class="sbtn dng" data-o="cancel" ${o.items.length || !isDraft ? '' : 'disabled'}><span>${isDraft ? 'Tozalash' : 'Bekor'}</span>${IC.x}</button>
    </div>
    <div class="order-actions">
      <button class="btn-x confirm" data-o="confirm" ${unsent ? '' : 'disabled'}>Tasdiqlash${unsent && !isDraft ? ' (+' + unsent + ')' : ''}</button>
      ${o.type === 'table' ? `<button class="btn-x togo ${o.togo ? 'on' : ''}" data-a="togo" title="Taomlar olib ketishga tayyorlanadi"><span>Olib ketish</span>${IC.bag}</button>` : ''}
      <button class="btn-x pay" data-o="pay" id="pay-btn" ${total > 0 ? '' : 'disabled'}>To'lash</button>
    </div>`}`;
  box.onclick = (e) => {
    const bl = e.target.closest('[data-bill]'); if (bl) return bl.disabled ? null : switchBill(Number(bl.dataset.bill));
    const m = e.target.closest('[data-m]'); if (m && !m.disabled) return onAction(m.dataset.m);
    const q = e.target.closest('[data-q]'); if (q) return changeQty(q.dataset.l, Number(q.dataset.q));
    const qn = e.target.closest('[data-qn]'); if (qn && !locked) { UI.sel = qn.dataset.qn; return onAction('qty'); }
    const a = e.target.closest('[data-a]'); if (a) return onAction(a.dataset.a);
    const b = e.target.closest('[data-o]'); if (b) return onOrderBtn(b.dataset.o);
    const ln = e.target.closest('.line'); if (ln) { UI.sel = UI.sel === ln.dataset.l ? null : ln.dataset.l; $$('#lines .line').forEach(x => x.classList.toggle('sel', x.dataset.l === UI.sel)); }
  };
  // Yetkazish narxi: kassir o'zi yozadi (bo'sh yoki 0 — bepul). Yozayotganda panel qayta chizilmaydi.
  box.oninput = (e) => {
    if (e.target.id !== 'fee') return;
    const c = cur();
    c.delivery_fee = Math.min(10_000_000, parseInt(String(e.target.value).replace(/\D/g, '') || '0', 10));
    const g = $('#grand-v'); if (g) g.textContent = fmt(orderTotal(c));
    const pb = $('#pay-btn'); if (pb) pb.disabled = orderTotal(c) <= 0;
    persistCur();
  };
  box.onchange = async (e) => { if (e.target.id === 'fee' && cur().status === 'accepted') await saveOrder(cur()); };
}

function lineHtml(i, o) {
  const unsent = i.qty - i.sent_qty;
  const sub = [
    unsent > 0 && o.status !== 'draft' ? `<span class="new">+${unsent} yangi</span>` : '',
    i.cancelled_qty ? `<span class="cx">✕ ${i.cancelled_qty} bekor</span>` : '',
    i.comment ? `<span>💬 ${h(i.comment)}</span>` : '',
  ].join('');
  return `<div class="line ${UI.sel === i.uuid ? 'sel' : ''} ${i.qty === 0 ? 'cancelled' : ''}" data-l="${i.uuid}">
    <div class="qty"><button class="qbtn" data-l="${i.uuid}" data-q="-1">−</button><span class="q num" data-qn="${i.uuid}">${i.qty}</span><button class="qbtn" data-l="${i.uuid}" data-q="1">+</button></div>
    <div class="nm"><div>${h(i.name)}</div>${sub ? `<div class="sub">${sub}</div>` : ''}</div>
    <div class="sum">${fmt(i.price * i.qty)}</div>
  </div>`;
}

async function persistCur() {
  const o = UI.cur;
  if (!o) return;
  // Qoralama va tasdiqlanmagan o'zgarishlar faqat lokal saqlanadi (kompyuter o'chsa ham yo'qolmaydi)
  if (o.status === 'draft' && !o.items.length && !o.number) { await db.del('orders', o.uuid).catch(() => {}); return; }
  if (o.status === 'draft' || o.status === 'accepted') { o.updated_at = nowIso(); await db.put('orders', o); }
}

/** Mahsulot kartalaridagi "savatda nechta" belgisini yangilaydi (butun ro'yxat qayta chizilmaydi — tez ishlaydi). */
function updateInc() {
  const box = $('#prods'); if (!box) return;
  const inCart = {};
  cur().items.forEach(i => { if (i.product_id) inCart[i.product_id] = (inCart[i.product_id] || 0) + i.qty; });
  for (const el of box.children) {
    const n = inCart[el.dataset.pid] || 0;
    let b = el.querySelector('.inc');
    if (!n) { if (b) b.remove(); continue; }
    if (!b) { b = document.createElement('span'); b.className = 'inc'; el.append(b); }
    if (b.textContent !== String(n)) b.textContent = n;
  }
}

function refreshSale() { renderOrderPanel(); updateInc(); persistCur().then(() => { updateCounts(); refreshBills(); }); }

function onProduct(pid) {
  const o = cur();
  if (o.status === 'done' || o.status === 'cancelled') return toast('Bu buyurtma yopilgan', 'warn', '"Yangi buyurtma" ni bosing');
  const p = S.catalog.products.find(x => x.id === pid);
  if (!p || !p.available) return;
  const line = addProduct(o, p);
  UI.sel = null;
  beep(1200, 25, 0.05);
  refreshSale();
  const el = $(`#lines .line[data-l="${line.uuid}"]`); if (el) el.scrollIntoView({ block: 'nearest' });
}

async function changeQty(lineUuid, delta) {
  const o = cur();
  if (o.status === 'done' || o.status === 'cancelled') return;
  const line = o.items.find(i => i.uuid === lineUuid);
  if (!line) return;
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

/** Stol tanlash (buyurtma turini "Stolda" ga o'zgartirish yoki boshqa stolga ko'chirish). */
function pickTable(o) {
  return new Promise(async res => {
    const open = [...await openOrders(), ...(await drafts()).filter(d => d.items.length)].filter(x => x.type === 'table' && x.uuid !== o.uuid);
    let picked = false;
    const zones = [...new Set(S.catalog.tables.map(zoneOf))];
    const m = modal({
      title: 'Stolni tanlang', cls: 'wide',
      body: zones.map(z => `<section class="zone" style="margin-bottom:10px"><h2 style="font-size:18px"><span class="hash">#</span>${h(z || 'Stollar')}</h2><div class="grid-tables">${S.catalog.tables.filter(t => zoneOf(t) === z).map(t => {
        const bills = open.filter(x => x.table_id === t.id);
        return `<button class="tcard ${bills.length ? 'busy' : 'free'}" style="min-height:76px" data-t="${t.id}"><div class="top"><div class="tn"><span class="hash">#</span>${h(tableNo(t))}</div></div><div class="ts">${bills.length ? bills.length + ' ta hisob' : "Bo'sh"}</div></button>`;
      }).join('')}</div></section>`).join('') || '<div class="note">Stollar yo\'q. Admin panelda qo\'shing.</div>',
      foot: null, onClose: () => res(picked),
    });
    m.body.onclick = (e) => {
      const b = e.target.closest('[data-t]'); if (!b) return;
      const tid = Number(b.dataset.t);
      const used = open.filter(x => x.table_id === tid).map(x => x.bill_no || 1);
      o.table_id = tid;
      o.bill_no = [1, 2, 3, 4].find(n => !used.includes(n)) || Math.max(...used) + 1;
      picked = true;
      m.close();
    };
  });
}

/** "Tur": buyurtma turini o'zgartirish (stolda / olib ketish / yetkazish) yoki boshqa stolga ko'chirish. */
function typeModal(o) {
  const kind = o.type === 'table' ? 'table' : o.type === 'delivery' ? 'delivery' : 'takeaway';
  const m = modal({
    title: 'Buyurtma turi', cls: 'slim',
    body: `<div class="type-grid">${[['table', 'table', 'Stolda'], ['takeaway', 'bag', 'Olib ketish'], ['delivery', 'moto', 'Yetkazish']].map(([k, i, l]) => `<button class="type-btn ${k === kind ? 'on' : ''}" data-ty="${k}">${IC[i]}<span>${l}</span></button>`).join('')}</div>
      ${o.type === 'table' ? '<p class="muted" style="margin:12px 0 0;font-size:13px">Boshqa stolga ko\'chirish uchun «Stolda» ni bosing.</p>' : ''}`,
    foot: null,
  });
  m.body.onclick = async (e) => {
    const b = e.target.closest('[data-ty]'); if (!b) return;
    const ty = b.dataset.ty;
    m.close();
    if (ty === 'table') {
      if (!S.catalog.tables.length) return toast("Stollar yo'q", 'warn', 'Admin panel → Stollar');
      if (!(await pickTable(o))) return;
      o.type = 'table'; o.delivery_fee = 0;
    } else {
      if (ty === kind) return ty === 'delivery' ? deliveryModal(o, false).then(refreshSale) : null;
      o.type = ty; o.table_id = null; o.bill_no = 1; o.togo = false;
      if (ty === 'delivery') await deliveryModal(o, false);
    }
    if (o.status === 'accepted') await saveOrder(o); else await persistCur();
    toast('Buyurtma turi: ' + TYPE_NAMES[o.type] + (o.type === 'table' ? ' — ' + tableName(o.table_id) : ''), 'info');
    renderView();
  };
}

// ---------- Mijoz: telefon, saqlangan manzillar ----------
const phoneKey = (p) => { const d = String(p || '').replace(/\D/g, ''); return d.length >= 9 ? d.slice(-9) : ''; };
function fmtPhone(v) {
  let d = String(v || '').replace(/\D/g, '');
  if (d.startsWith('998')) d = d.slice(3);
  else if (d.length > 9 && d.slice(1, 4) === '998') d = d[0] + d.slice(4); // kursor prefiksdan oldin turib qolgan bo'lsa
  d = d.slice(0, 9);
  return ('+998 ' + [d.slice(0, 2), d.slice(2, 5), d.slice(5, 7), d.slice(7, 9)].filter(Boolean).join(' ')).trimEnd() + (d.length ? '' : ' ');
}
/** Shu telefon raqami bilan avval berilgan buyurtmalardagi manzillar (kassa va Telegram). */
async function addressBook(phone) {
  const k = phoneKey(phone);
  if (!k) return { addrs: [], name: '' };
  const all = [...await db.all('orders'), ...await db.all('tg')].filter(x => phoneKey(x.phone) === k)
    .sort((a, b) => String(b.created_at).replace(' ', 'T').localeCompare(String(a.created_at).replace(' ', 'T')));
  return { addrs: [...new Set(all.map(x => String(x.address || '').trim()).filter(Boolean))].slice(0, 4), name: all.find(x => x.customer_name)?.customer_name || '' };
}

/** Yetkazish buyurtmasi oynasi: telefon, saqlangan manzil yoki yangi manzil, izoh. Hammasi ixtiyoriy. */
function deliveryModal(o, isNew) {
  return new Promise(res => {
    let saved = false;
    const m = modal({
      title: isNew ? 'Buyurtma yaratish — Yetkazish' : "Yetkazish ma'lumotlari", cls: 'slim',
      body: `<div class="field"><label>Telefon raqami</label><div class="phone-row"><span class="flag"><i></i><i></i><i></i></span><input class="inp" id="c-phone" inputmode="tel" autocomplete="off" value="${h(fmtPhone(o.phone))}"></div></div>
        <div class="field hidden" id="c-addrs-f"><label>Manzilni tanlang yoki qo'lda kiriting</label><div class="addr-list" id="c-addrs"></div></div>
        <div class="field"><label>Manzil</label><textarea class="inp" id="c-addr" placeholder="Manzilni kiriting" style="height:60px" maxlength="255">${h(o.address)}</textarea></div>
        <div class="field"><label>Mijoz ismi (ixtiyoriy)</label><input class="inp" id="c-name" maxlength="100" value="${h(o.customer_name)}"></div>
        <div class="field" style="margin:0"><label>Izoh</label><textarea class="inp" id="c-cmt" placeholder="Izoh kiriting" style="height:60px" maxlength="250">${h(o.comment)}</textarea></div>`,
      foot: `<button class="btn-x confirm" data-ok>Saqlash</button><button class="btn-x ghost" data-no>Orqaga</button>`,
      onClose: () => res(saved),
    });
    m.foot.classList.add('split');
    const ph = $('#c-phone', m.el), ad = $('#c-addr', m.el), nm = $('#c-name', m.el);
    const lookup = async () => {
      const { addrs, name } = await addressBook(ph.value);
      if (!m.el.isConnected) return;
      $('#c-addrs-f', m.el).classList.toggle('hidden', !addrs.length);
      $('#c-addrs', m.el).innerHTML = addrs.map(a => `<button class="addr ${a === ad.value.trim() ? 'on' : ''}" data-addr="${h(a)}">${h(a)}</button>`).join('');
      if (name && !nm.value.trim()) nm.value = name;
    };
    ph.oninput = () => { ph.value = fmtPhone(ph.value); lookup(); };
    ph.onfocus = () => setTimeout(() => ph.setSelectionRange(ph.value.length, ph.value.length), 0);
    ad.oninput = () => $$('.addr', m.el).forEach(x => x.classList.toggle('on', x.dataset.addr === ad.value.trim()));
    m.body.onclick = (e) => {
      const b = e.target.closest('[data-addr]'); if (!b) return;
      ad.value = b.dataset.addr;
      $$('.addr', m.el).forEach(x => x.classList.toggle('on', x === b));
    };
    m.foot.querySelector('[data-no]').onclick = () => m.close();
    m.foot.querySelector('[data-ok]').onclick = () => {
      o.phone = phoneKey(ph.value) ? fmtPhone(ph.value).trim() : (ph.value.replace(/\D/g, '').length > 3 ? ph.value.trim().slice(0, 20) : '');
      o.address = ad.value.trim().slice(0, 255);
      o.customer_name = nm.value.trim().slice(0, 100);
      o.comment = $('#c-cmt', m.el).value.trim().slice(0, 250);
      saved = true;
      m.close();
    };
    if (o.phone) lookup();
  });
}

function customerModal(o) {
  if (o.type === 'delivery') return deliveryModal(o, false).then(async ok => { if (ok && o.status === 'accepted') await saveOrder(o); refreshSale(); });
  return new Promise(res => {
    const m = modal({
      title: 'Mijoz ma\'lumotlari', cls: 'slim',
      body: `<div class="field"><label>Telefon</label><div class="phone-row"><span class="flag"><i></i><i></i><i></i></span><input class="inp" id="c-phone" inputmode="tel" autocomplete="off" value="${h(fmtPhone(o.phone))}"></div></div>
        <div class="field" style="margin:0"><label>Ismi</label><input class="inp" id="c-name" maxlength="100" value="${h(o.customer_name)}"></div>`,
      foot: `<button class="btn-x confirm" data-ok>Saqlash</button>`, onClose: () => res(),
    });
    const ph = $('#c-phone', m.el);
    ph.oninput = () => { ph.value = fmtPhone(ph.value); };
    ph.onfocus = () => setTimeout(() => ph.setSelectionRange(ph.value.length, ph.value.length), 0);
    m.foot.querySelector('[data-ok]').onclick = async () => {
      o.phone = phoneKey(ph.value) ? fmtPhone(ph.value).trim() : '';
      o.customer_name = $('#c-name', m.el).value.trim().slice(0, 100);
      m.close();
      if (o.status === 'accepted') await saveOrder(o);
      refreshSale();
    };
  });
}

async function onAction(a) {
  const o = cur();
  const line = o.items.find(i => i.uuid === UI.sel);
  switch (a) {
    case 'cust': return customerModal(o);
    case 'type': return typeModal(o);
    case 'togo': {
      o.togo = !o.togo;
      toast(o.togo ? 'Olib ketishga: oshxona chekida belgilanadi' : 'Olib ketish belgisi olib tashlandi', 'info');
      return refreshSale();
    }
    case 'qty': {
      if (!line) return;
      const v = await promptBox('Miqdor', line.name, { value: String(line.qty), numeric: true });
      if (v === null) return;
      const n = parseInt(v, 10);
      if (!(n >= 0)) return;
      const r = setQty(o, line, n);
      if (r.needsCancel) return cancelLineFlow(o, line, r.needsCancel);
      return refreshSale();
    }
    case 'note0':
    case 'note': {
      if (line && a === 'note') {
        const v = await promptBox('Taom izohi', line.name, { value: line.comment, required: false, presets: ['Piyozsiz', 'Achchiq', 'Sousiz', 'Ikki marta pishloq'] });
        if (v === null) return;
        if (line.sent_qty > 0 && v !== line.comment) return toast("Oshxonaga yuborilgan taom izohini o'zgartirib bo'lmaydi. Buyurtma izohidan foydalaning.", 'warn');
        line.comment = v;
      } else {
        const v = await promptBox('Buyurtma izohi', 'Oshxona chekida chiqadi. Bitta taomga izoh: avval taomni bosib tanlang.', { value: o.comment, required: false, textarea: true });
        if (v === null) return;
        o.comment = v;
        if (o.status === 'accepted') await saveOrder(o);
      }
      return refreshSale();
    }
    case 'bill': {
      if (!o.items.some(i => i.qty > 0)) return toast("Buyurtmada taom yo'q", 'warn');
      const d = customerDoc({ ...o, payments: [] }, ctx());
      d.lines.unshift({ t: "HISOB (to'lanmagan)", align: 'c', b: true });
      await printDoc('customer', o, d, 'Hisob cheki');
      if (o.status === 'accepted') { o.bill_printed = true; await db.put('orders', o); renderOrderPanel(); refreshBills(); }
      return;
    }
    case 'rebill': return printDoc('customer', o, customerDoc(o, ctx()), 'Mijoz cheki (nusxa)');
  }
}

async function onOrderBtn(b) {
  const o = cur();
  if (b === 'new') return backToList(o.type);
  if (b === 'confirm') return doConfirm(o);
  if (b === 'pay') return payModal(o);
  if (b === 'cancel') {
    if (o.status === 'draft') {
      if (!o.items.length) return;
      if (!(await confirmBox('Tozalash', 'Buyurtmadagi barcha taomlar olib tashlansinmi?', 'Tozalash', true))) return;
      await db.del('orders', o.uuid);
      UI.cur = newDraft(o.type, { bill_no: o.bill_no || 1, ...(o.type === 'table' ? { table_id: o.table_id } : { phone: o.phone, address: o.address, customer_name: o.customer_name }) });
      UI.sel = null;
      return renderView();
    }
    return cancelOrderFlow(o);
  }
}

function validateBeforeConfirm(o) {
  // Telefon va manzil majburiy emas. Faqat stol buyurtmasida stol tanlangan bo'lishi kerak.
  if (o.type === 'table' && !o.table_id) return 'Stolni tanlang';
  return '';
}

/** Oshxona cheki uchun buyurtma: "Olib ketish" belgisi izohga qo'shiladi. */
const kitchenOrder = (o) => (o.togo ? { ...o, comment: 'OLIB KETISHGA' + (o.comment ? ' · ' + o.comment : '') } : o);

async function doConfirm(o, silent = false) {
  const err = validateBeforeConfirm(o);
  if (err) { toast(err, 'warn'); if (await pickTable(o)) refreshSale(); return false; }
  if (o.type !== 'table') o.bill_no = 1;
  let res;
  try {
    if (unsentLines(o).length) o.bill_printed = false;
    res = await confirmOrder(o);
  } catch (e) { toast(e.message, 'bad'); return false; }
  if (res.delta.length && S.catalog.settings.kitchen_auto) {
    printDoc('kitchen', o, kitchenDoc(kitchenOrder(o), res.delta, { ...ctx(), isAddition: res.isAddition }), 'Oshxona cheki', S.catalog.settings.kitchen_copies, uuid(), true);
  }
  if (!silent) {
    toast(res.isAddition ? `#${o.number}: qo'shimcha taomlar oshxonaga yuborildi` : `Buyurtma #${o.number} tasdiqlandi`, 'ok', res.delta.length ? 'Oshxona cheki chiqarildi' : '');
    refreshSale(); renderView();
  }
  return true;
}

async function cancelOrderFlow(o) {
  if (paidTotal(o) > 0) return toast("To'langan buyurtmani bekor qilib bo'lmaydi", 'bad');
  const reason = await promptBox('Buyurtmani bekor qilish', `#${o.number} — bekor qilish sababi (tarixda saqlanadi):`, { presets: CANCEL_REASONS });
  if (!reason) return;
  const cancels = await cancelWholeOrder(o, reason);
  toast(`#${o.number} bekor qilindi`, 'warn');
  if (cancels.length) {
    const doc = { beep: true, lines: [{ t: 'BEKOR QILINDI', align: 'c', b: true, s: 2 }, { t: `BUTUN BUYURTMA #${o.number}`, align: 'c', b: true }, { type: 'sep', ch: '=' },
      ...cancels.map(c => ({ t: `${c.qty} x ${c.name}`, b: true, s: 2 })), { type: 'sep' }, { t: 'Sabab: ' + reason, b: true }, { type: 'lr', l: dt(nowIso()), r: S.employee.name }] };
    printDoc('cancel', o, doc, 'Bekor qilish cheki');
  }
  backToList(o.type);
}

// ---------- To'lov (to'liq ekran) ----------
const PAY_NAME = { cash: 'Naqd', terminal: 'Terminal', click: 'Click' };

function payModal(o, { onDone = null, total: forcedTotal = null, title = null } = {}) {
  const total = forcedTotal ?? (orderTotal(o) - paidTotal(o));
  const methods = (S.catalog.settings.pay_methods || ['cash', 'terminal', 'click']).filter(k => PAY_NAME[k]);
  if (!methods.length) methods.push('cash');
  const amt = {};
  methods.forEach(k => { amt[k] = 0; });
  let active = null, fresh = true;
  const sum = () => methods.reduce((s, k) => s + amt[k], 0);
  return new Promise(res => {
    let finished = false;
    const onKey = (e) => {
      if (/^\d$/.test(e.key)) key(e.key);
      else if (e.key === 'Backspace' || e.key === 'Delete') key('AC');
      else if (e.key === 'Enter') { e.preventDefault(); submit(); }
      else if (e.key === 'Escape') m.close();
    };
    const m = modal({
      bare: true, cls: 'payfull', ovCls: 'payov',
      body: `<div class="pay-top"><button class="back" data-x2>Orqaga</button><button class="go" id="payok">To'lash</button></div>
        <div class="pay-sums"><div><div class="l">Jami:</div><div class="v num" id="pay-total">${fmt(total)} UZS</div></div>
          <div class="due" id="due"><div class="l" id="due-l">To'lovga:</div><div class="v num" id="due-v"></div></div></div>
        <div class="pay-grid"><div class="pay-left">
            <div class="prow ord"><span class="pi">${IC.cart}</span><div class="pn">${h(title || 'Buyurtma ' + (o.number ? '#' + o.number : '(yangi)'))}</div><span></span></div>
            ${methods.map(k => `<div class="prow" data-m="${k}"><span class="pi">${IC.cash}</span><div><div class="pn">${PAY_NAME[k]}</div><div class="pv num" data-v="${k}">0 UZS</div></div><button class="plus" data-plus="${k}" title="Qolgan summani shu turga yozish">+</button></div>`).join('')}
            <div class="pay-hint">«+» — qolgan summani shu to'lov turiga yozadi. Boshqa summa (masalan, mijoz bergan naqd yoki aralash to'lov) uchun turni bosib, raqamlarni tering.</div>
          </div>
          <div class="keypad" id="kp">${[7, 8, 9, 4, 5, 6, 1, 2, 3, 0, '000', 'AC'].map(k => `<button data-k="${k}">${k}</button>`).join('')}</div></div>`,
      foot: null,
      onClose: () => { document.removeEventListener('keydown', onKey); if (!finished) res(false); },
    });
    const draw = () => {
      for (const k of methods) {
        const row = $(`.prow[data-m="${k}"]`, m.el);
        row.classList.toggle('on', active === k);
        row.classList.toggle('has', amt[k] > 0);
        $(`[data-v="${k}"]`, m.el).textContent = fmt(amt[k]) + ' UZS';
      }
      const rem = total - sum();
      $('#due', m.el).classList.toggle('chg', rem < 0);
      $('#due-l', m.el).textContent = rem < 0 ? 'Qaytim:' : "To'lovga:";
      $('#due-v', m.el).textContent = fmt(Math.abs(rem)) + ' UZS';
    };
    const key = (v) => {
      if (!active) active = methods.includes('cash') ? 'cash' : methods[0];
      if (v === 'AC') amt[active] = 0;
      else amt[active] = parseInt(((fresh ? '' : String(amt[active] || '')) + v).replace(/^0+/, '').slice(0, 9) || '0', 10);
      fresh = false;
      draw();
    };
    const submit = async () => {
      const s = sum();
      if (s <= 0) return toast("To'lov turini tanlang", 'warn', 'Kerakli tur yonidagi «+» ni bosing');
      if (s < total) return toast(`To'lov yetarli emas: yana ${fmt(total - s)} UZS kerak`, 'bad');
      const cash = amt.cash || 0;
      if (s - cash > total) return toast('Karta/Click summasi jamidan oshib ketdi', 'bad', "Qaytim faqat naqd puldan beriladi");
      if (cash > 0 && s - cash >= total) return toast("Naqd summa ortiqcha", 'bad', "Karta/Click to'lovi jamini to'liq yopgan");
      // Naqd har doim oxirida: qaytim faqat naqddan beriladi
      const all = methods.filter(k => k !== 'cash' && amt[k] > 0).map(k => ({ method: k, amount: amt[k] }));
      if (cash > 0) all.push({ method: 'cash', amount: cash });
      const btn = $('#payok', m.el);
      if (btn.disabled) return;
      btn.disabled = true;
      try {
        if (onDone) await onDone(all);
        else await completePayment(o, all);
        finished = true;
        res(true);
        m.close();
      } catch (e) { toast(e.message || String(e), 'bad'); btn.disabled = false; }
    };
    m.el.onclick = (e) => {
      if (e.target.closest('[data-x2]')) return m.close();
      if (e.target.closest('#payok')) return submit();
      const pl = e.target.closest('[data-plus]');
      if (pl) { active = pl.dataset.plus; amt[active] = Math.max(0, total - (sum() - amt[active])); fresh = true; return draw(); }
      const row = e.target.closest('.prow[data-m]');
      if (row) { active = row.dataset.m; fresh = true; return draw(); }
      const k = e.target.closest('[data-k]');
      if (k) key(k.dataset.k);
    };
    document.addEventListener('keydown', onKey);
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
  toast("To'lov qabul qilindi", 'ok', change ? `Qaytim: ${fmt(change)} UZS` : `#${o.number} yopildi`, change ? 8000 : 3200);
  if (S.catalog.settings.customer_auto) printDoc('customer', o, customerDoc(o, ctx()), 'Mijoz cheki', S.catalog.settings.customer_copies, uuid(), true);
  backToList(o.type);
}

// ====================================================================
// Chop etish
// ====================================================================
function ctx() {
  const st = S.catalog.settings;
  return { brand: st.brand_name || 'ADI BURGER', address: st.brand_address, phone: st.brand_phone, footer: st.receipt_footer, tables: S.catalog.tables, cashier: S.employee?.name || '',
    design: S.catalog.receipt?.design || null, logo: S.catalog.receipt?.logo || null, base: new URL('../', location.href).href };
}

/** quiet: muvaffaqiyatli yuborilganda xabar chiqarilmaydi (xato har doim ko'rsatiladi). */
async function printDoc(kind, o, doc, title, copies = 1, id = uuid(), quiet = false) {
  const rec = await agent.print({ id, kind, orderUuid: o?.uuid || null, orderRef: o ? '#' + o.number : '', title, doc, copies });
  if (rec && (rec.status === 'agent_down' || rec.status === 'error')) toast(`${title} chiqmadi`, 'bad', rec.error, 7000);
  else if (!quiet) toast(`${title} printerga yuborildi`, 'info', '', 2200);
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
// STOLLAR (zallar bo'yicha)
// ====================================================================
async function viewTables(v) {
  const open = (await openOrders()).filter(o => o.type === 'table');
  const dr = (await drafts()).filter(d => d.type === 'table' && d.items.length);
  if (UI.view !== 'tables') return;
  const map = {};
  for (const o of [...open, ...dr]) (map[o.table_id] = map[o.table_id] || []).push(o);
  for (const k in map) map[k].sort((a, b) => (a.bill_no || 1) - (b.bill_no || 1));
  const tables = S.catalog.tables;
  const zones = [...new Set(tables.map(zoneOf))];
  const named = zones.filter(Boolean);
  if (UI.zone !== 'all' && !zones.includes(UI.zone)) UI.zone = 'all';
  const busyN = (z) => tables.filter(t => zoneOf(t) === z && (map[t.id] || []).some(o => o.status === 'accepted')).length;
  const card = (t) => {
    const bills = map[t.id] || [];
    const head = `<div class="tn"><span class="hash">#</span>${h(tableNo(t))}</div>`;
    if (!bills.length) return `<button class="tcard free" data-t="${t.id}"><div class="top">${head}</div><div class="meta"></div><div class="ts">Bo'sh</div></button>`;
    const real = bills.filter(o => o.status === 'accepted');
    const sum = bills.reduce((s, o) => s + orderTotal(o), 0);
    const cls = !real.length ? 'draft' : real.every(o => o.bill_printed && !unsentLines(o).length) ? 'billed' : 'busy';
    const pill = cls === 'draft' ? 'Tasdiqlanmagan' : cls === 'billed' ? 'Chek berildi' : 'Buyurtma qabul qilinmoqda';
    return `<button class="tcard ${cls}" data-t="${t.id}"><div class="top">${head}<div class="tsum num">${fmt(sum)}</div></div>
      <div class="meta"><div>${IC.usr}${h(empName(bills[0].employee_id))}</div><div>${IC.clock}${dt(bills[0].created_at, false)}${bills.length > 1 ? ` · ${bills.length} ta hisob` : ''}</div></div>
      <div class="pillx">${IC.clock}${pill}</div></button>`;
  };
  const show = zones.filter(z => UI.zone === 'all' || z === UI.zone);
  v.innerHTML = `<div class="tv ${named.length ? '' : 'nozone'}"><aside class="side ${named.length ? '' : 'hidden'}" id="zones"><div class="sico">${IC.grid}</div>
      <button class="cat ${UI.zone === 'all' ? 'on' : ''}" data-z="all">Hammasi</button>
      ${named.map(z => `<button class="cat ${UI.zone === z ? 'on' : ''}" data-z="${h(z)}">${h(z)}${busyN(z) ? `<span class="badge">${busyN(z)}</span>` : ''}</button>`).join('')}</aside>
    <div class="tmain" id="tg">${tables.length
      ? show.map(z => `<section class="zone">${named.length ? `<h2><span class="hash">#</span>${h(z || 'Stollar')}</h2>` : ''}<div class="grid-tables">${tables.filter(t => zoneOf(t) === z).map(card).join('')}</div></section>`).join('')
      : `<div class="note" style="margin:10px 0">Stollar yo'q. Admin panel → Stollar bo'limidan qo'shing (zal nomi bilan).</div>`}</div></div>`;
  $('#zones').onclick = (e) => { const b = e.target.closest('[data-z]'); if (b) { UI.zone = b.dataset.z; renderView(); } };
  $('#tg').onclick = (e) => {
    const b = e.target.closest('[data-t]'); if (!b) return;
    const tid = Number(b.dataset.t);
    const bills = map[tid] || [];
    openSale(bills[0] || newDraft('table', { table_id: tid }));
  };
}

// ====================================================================
// OLIB KETISH / YETKAZISH — faol buyurtmalar kartalari (kassa va Telegram)
// ====================================================================
async function viewList(v) {
  const type = UI.mode === 'delivery' ? 'delivery' : 'takeaway';
  const mine = (o) => (type === 'delivery' ? o.type === 'delivery' : (o.type === 'takeaway' || o.type === 'phone'));
  const open = (await openOrders()).filter(mine).reverse();
  const dr = (await drafts()).filter(d => mine(d) && d.items.length).sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || ''));
  const tgAll = await db.all('tg');
  if (UI.view !== 'list' || UI.mode !== type) return;
  const tg = tgAll.filter(o => TG_ACTIVE.includes(o.status) && (type === 'delivery' ? o.type === 'delivery' : o.type !== 'delivery')).sort((a, b) => b.id - a.id);
  // Ko'rilgan Telegram buyurtmalari uchun ovoz to'xtaydi
  let seenChanged = false;
  for (const o of tg) if (!o.seen) { o.seen = true; await db.put('tg', o); seenChanged = true; }
  if (seenChanged) updateTgBadge();
  const row = (ic, txt) => (txt ? `<div>${ic}<span>${h(txt)}</span></div>` : '');
  const card = (o) => `<button class="ocard2" data-o="${o.uuid}"><div class="top"><div class="tn"><span class="hash">#</span>${h(o.number)}</div><div class="tsum num">${fmt(orderTotal(o))}</div></div>
      <div class="meta">${row(IC.usr, empName(o.employee_id))}${row(IC.clock, dt(o.created_at, false))}${row(IC.pin, o.address)}${!o.address ? row(IC.usr, o.customer_name) : ''}</div>
      <div class="pillx">${o.phone ? IC.phone + h(o.phone) : (unsentLines(o).length ? "Yuborilmagan taom bor" : `${o.items.reduce((s, i) => s + i.qty, 0)} ta taom`)}</div></button>`;
  const draftCard = (o) => `<button class="ocard2 draft" data-o="${o.uuid}"><div class="top"><div class="tn">Hisob-${o.bill_no || 1}</div><div class="tsum num">${fmt(orderTotal(o))}</div></div>
      <div class="meta">${row(IC.clock, dt(o.created_at, false))}${row(IC.pin, o.address)}<div>${o.items.reduce((s, i) => s + i.qty, 0)} ta taom</div></div>
      <div class="pillx">Tasdiqlanmagan</div></button>`;
  const tgCard = (o) => {
    const [l] = TG_ST[o.status] || [o.status];
    const pay = o.pay_status === 'paid' ? "To'langan" : o.receipt?.status === 'checking' ? 'Chekni tekshiring!' : "To'lanmagan";
    return `<button class="ocard2 tg ${o.status === 'new' ? 'fresh' : ''}" data-tg="${o.uuid}"><div class="top"><div class="tn">${h(o.number)}</div><div class="tsum num">${fmt(o.total)}</div></div>
      <div class="meta">${row(IC.tg, 'TELEGRAM')}${row(IC.clock, dt(o.created_at, false))}${row(IC.pin, o.address)}${row(IC.phone, o.phone)}</div>
      <div class="pillx">${h(l)} · ${pay}</div></button>`;
  };
  v.innerHTML = `<div class="cards" id="cl">
    <button class="ocard2 new" data-new><div class="plus">+</div><div class="pillx">yangi buyurtma</div></button>
    ${tg.map(tgCard).join('')}${dr.map(draftCard).join('')}${open.map(card).join('')}</div>`;
  $('#cl').onclick = (e) => {
    if (e.target.closest('[data-new]')) return newInMode(type, dr);
    const t = e.target.closest('[data-tg]'); if (t) return tgDetail(tg.find(x => x.uuid === t.dataset.tg));
    const b = e.target.closest('[data-o]'); if (b) openSale([...open, ...dr].find(x => x.uuid === b.dataset.o));
  };
}

/** "+ yangi buyurtma": bo'sh Hisob (1..4) ochiladi. Hech narsa so'ralmaydi. */
async function newInMode(type, dr) {
  const used = dr.map(d => d.bill_no || 1);
  const slot = [1, 2, 3, 4].find(n => !used.includes(n));
  if (!slot) { toast('4 ta tasdiqlanmagan buyurtma bor', 'warn', 'Avval ularni tasdiqlang yoki tozalang'); return openSale(dr[dr.length - 1]); }
  const o = newDraft(type, { bill_no: slot });
  openSale(o); // telefon va manzil so'ralmaydi: kerak bo'lsa buyurtma panelidagi tugma orqali kiritiladi
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
  const q = UI.orderQ.trim().toLowerCase();
  const typeOk = (o) => UI.orderType === 'all' || (UI.orderType === 'takeaway' ? (o.type === 'takeaway' || o.type === 'phone') : o.type === UI.orderType);
  const textOk = (o) => !q || [o.number, o.phone, o.customer_name, o.address, o.comment].some(x => String(x || '').toLowerCase().includes(q));
  const list = all.filter(F[UI.orderFilter][1]).filter(typeOk).filter(textOk).slice(0, 200);
  const sum = list.filter(o => o.status === 'done').reduce((s, o) => s + orderTotal(o), 0);
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Buyurtmalar</h1>
    <div class="filters" id="of">${Object.entries(F).map(([k, [l]]) => `<button class="fbtn ${UI.orderFilter === k ? 'on' : ''}" data-f="${k}">${l} (${all.filter(F[k][1]).length})</button>`).join('')}</div></div>
    <div class="filters" style="margin-bottom:12px;align-items:center"><div class="search" style="flex:1;min-width:220px"><span class="s-ico">🔍</span><input id="oq" class="inp" style="padding-left:42px" placeholder="Raqam, telefon, ism yoki manzil bo'yicha qidirish…" value="${h(UI.orderQ)}" autocomplete="off"></div>
      <div class="filters" id="ot">${[['all', 'Hammasi'], ['table', 'Stolda'], ['takeaway', 'Olib ketish'], ['delivery', 'Yetkazish']].map(([k, l]) => `<button class="fbtn ${UI.orderType === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('')}</div>
      <span class="muted">Jami (to'langan): <b class="num" style="color:var(--text)">${fmt(sum)}</b></span></div>
    <div class="list" id="ol">${list.map(o => `<button class="ocard" data-o="${o.uuid}"><div class="no">#${h(o.number || '—')}</div>
      <div><div>${TYPE_NAMES[o.type]}${o.type === 'table' && o.table_id ? ' · ' + h(tableName(o.table_id)) + (o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : '') : ''} ${statusBadge(o)}</div>
      <div class="sm">${dt(o.created_at)} · ${o.items.filter(i => i.qty > 0).map(i => h(i.name) + ' ×' + i.qty).join(', ').slice(0, 90)}</div></div>
      <div class="amt num">${fmt(orderTotal(o))}</div></button>`).join('') || '<div class="muted" style="padding:30px;text-align:center">Buyurtma yo\'q</div>'}</div></div>`;
  $('#of').onclick = (e) => { const b = e.target.closest('[data-f]'); if (b) { UI.orderFilter = b.dataset.f; renderView(); } };
  $('#ot').onclick = (e) => { const b = e.target.closest('[data-t]'); if (b) { UI.orderType = b.dataset.t; renderView(); } };
  $('#oq').oninput = debounce((e) => { UI.orderQ = e.target.value; renderView(); const i = $('#oq'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 250);
  $('#ol').onclick = (e) => { const b = e.target.closest('[data-o]'); if (b) orderDetail(all.find(x => x.uuid === b.dataset.o)); };
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
    if (b.dataset.a === 'open') { m.close(); openSale(o); }
    if (b.dataset.a === 'cancel') { m.close(); UI.cur = o; await cancelOrderFlow(o); }
    if (b.dataset.a === 'kitchen') { await printDoc('kitchen', o, kitchenDoc(kitchenOrder(o), o.items.filter(i => i.qty > 0), { ...ctx(), isAddition: false }), 'Oshxona cheki (nusxa)'); }
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
  if ((UI.view === 'telegram' || UI.view === 'list') && !hasModal()) renderView(); else updateCounts();
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
    UI.cur = null;
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
  v.innerHTML = `<div class="page"><div class="page-head"><h1>Printer va aloqa</h1>
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
      <div class="btns" style="margin-top:14px"><button class="btn-x confirm" id="p-save">💾 Saqlash</button><button class="btn-x" id="p-check">🔌 Ulanishni tekshirish</button><button class="btn-x pay" style="grid-column:auto" id="p-test">🧾 Test chek chiqarish</button><button class="btn-x" id="p-re">↻ Qayta ulash</button><button class="btn-x" id="p-ruler">📏 O'lchov cheki</button></div>
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
  $('#p-ruler').onclick = async () => { const r = await printDoc('test', null, rulerDoc(ctx()), "O'lchov cheki"); if (r && r.status === 'agent_down') return; toast("O'lchov cheki yuborildi", 'info', "Qog'ozdagi chizg'ichni haqiqiy chizg'ich bilan solishtiring."); };
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
    <label class="check" style="margin-top:10px"><input type="checkbox" id="d-sound" ${audio.muted ? '' : 'checked'}> Ovoz (yangi Telegram buyurtmasi va tugmalar)</label>
    <div class="btns" style="margin-top:12px"><button class="btn-x" id="d-cat">Menyuni yangilash</button><button class="btn-x danger" id="d-unlink">Qurilmani uzish</button></div>
    </div></div>`;
  $('#d-sound').onchange = async (e) => { audio.muted = !e.target.checked; await kv.set('sound_off', audio.muted); if (audio.muted) alarmStop(); else beep(1000, 80); };
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
