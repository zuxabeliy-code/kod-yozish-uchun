// Umumiy yordamchi funksiyalar: format, HTML xavfsizligi, bildirishnoma, modal, ovoz, PIN xeshi.

export const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
  const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
  return (c === 'x' ? r : (r & 3) | 8).toString(16);
}));

export const fmt = (n) => Math.round(Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
export const som = (n) => fmt(n) + " so'm";

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const h = (s) => String(s ?? '').replace(/[&<>"']/g, c => ESC[c]);

export const pad2 = (n) => String(n).padStart(2, '0');
export const nowIso = () => new Date().toISOString();
export function localDate(d = new Date()) { return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; }
export function dt(iso, withDate = true) {
  if (!iso) return '';
  const d = new Date(String(iso).includes('T') || String(iso).includes('Z') ? iso : String(iso).replace(' ', 'T'));
  if (isNaN(d)) return String(iso);
  const t = `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
  return withDate ? `${pad2(d.getDate())}.${pad2(d.getMonth() + 1)}.${d.getFullYear()} ${t}` : t;
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// ---------- Bildirishnomalar ----------
export function toast(msg, type = 'ok', sub = '', ms = 3200) {
  const box = document.getElementById('toasts');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = `<span class="ti">${{ ok: '✅', bad: '⛔', warn: '⚠️', info: 'ℹ️' }[type] || ''}</span><div>${h(msg)}${sub ? `<small>${h(sub)}</small>` : ''}</div>`;
  box.prepend(el);
  while (box.children.length > 4) box.lastChild.remove();
  setTimeout(() => el.remove(), ms);
  el.onclick = () => el.remove();
}

// ---------- Modal oynalar ----------
let modalStack = [];
export function modal({ title, body, foot = '', cls = '', onClose = null, closable = true }) {
  const root = document.getElementById('modal-root');
  const ov = document.createElement('div');
  ov.className = 'overlay';
  ov.innerHTML = `<div class="modal ${cls}" role="dialog"><div class="modal-h"><h2>${h(title)}</h2>${closable ? '<button class="modal-x" data-x>✕</button>' : ''}</div>
    <div class="modal-b"></div>${foot !== null ? '<div class="modal-f"></div>' : ''}</div>`;
  const b = ov.querySelector('.modal-b');
  if (typeof body === 'string') b.innerHTML = body; else if (body) b.append(body);
  const f = ov.querySelector('.modal-f');
  if (f) { if (typeof foot === 'string') f.innerHTML = foot; else if (foot) f.append(foot); if (!f.innerHTML && !f.children.length) f.remove(); }
  const close = () => { ov.remove(); modalStack = modalStack.filter(x => x !== api); onClose && onClose(); };
  const api = { el: ov, body: b, foot: f, close };
  if (closable) {
    ov.querySelector('[data-x]').onclick = close;
    ov.addEventListener('pointerdown', e => { if (e.target === ov) close(); });
  }
  root.append(ov);
  modalStack.push(api);
  setTimeout(() => { const i = ov.querySelector('input:not([type=hidden]),textarea'); if (i && !i.dataset.noFocus) i.focus(); }, 30);
  return api;
}
export function closeAllModals() { [...modalStack].forEach(m => m.close()); }
export const hasModal = () => modalStack.length > 0;

export function confirmBox(title, text, okLabel = 'Ha', danger = false) {
  return new Promise(res => {
    const m = modal({
      title, body: `<p style="margin:0;font-size:16px">${h(text)}</p>`,
      foot: `<button class="btn-x ghost" data-no>Yo'q</button><button class="btn-x ${danger ? 'danger' : 'confirm'}" data-ok>${h(okLabel)}</button>`,
      onClose: () => res(false),
    });
    m.foot.querySelector('[data-no]').onclick = () => m.close();
    m.foot.querySelector('[data-ok]').onclick = () => { res(true); m.close(); };
  });
}

export function promptBox(title, label, { value = '', placeholder = '', textarea = false, required = true, presets = [] } = {}) {
  return new Promise(res => {
    let done = false;
    const presetHtml = presets.length ? `<div class="filters" style="margin-bottom:10px">${presets.map(p => `<button class="fbtn" data-p="${h(p)}">${h(p)}</button>`).join('')}</div>` : '';
    const m = modal({
      title,
      body: `${presetHtml}<div class="field"><label>${h(label)}</label>${textarea ? `<textarea class="inp" maxlength="250" placeholder="${h(placeholder)}">${h(value)}</textarea>` : `<input class="inp" maxlength="250" value="${h(value)}" placeholder="${h(placeholder)}">`}</div>`,
      foot: `<button class="btn-x ghost" data-no>Bekor</button><button class="btn-x confirm" data-ok>Saqlash</button>`,
      onClose: () => { if (!done) res(null); },
    });
    const inp = m.body.querySelector('.inp');
    m.body.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { inp.value = b.dataset.p; inp.focus(); });
    const ok = () => {
      const v = inp.value.trim();
      if (required && !v) { inp.focus(); inp.style.borderColor = 'var(--red)'; return; }
      done = true; res(v); m.close();
    };
    m.foot.querySelector('[data-no]').onclick = () => m.close();
    m.foot.querySelector('[data-ok]').onclick = ok;
    inp.addEventListener('keydown', e => { if (e.key === 'Enter' && !textarea) ok(); });
  });
}

// ---------- Ovoz (fayl kerak emas — WebAudio) ----------
let actx = null;
export function beep(freq = 880, ms = 160, vol = 0.25) {
  try {
    actx = actx || new (window.AudioContext || window.webkitAudioContext)();
    if (actx.state === 'suspended') actx.resume();
    const o = actx.createOscillator(), g = actx.createGain();
    o.type = 'square'; o.frequency.value = freq; g.gain.value = vol;
    o.connect(g); g.connect(actx.destination);
    o.start(); o.stop(actx.currentTime + ms / 1000);
  } catch {}
}
let alarmTimer = null;
export function alarmStart() {
  if (alarmTimer) return;
  const ring = () => { beep(988, 140); setTimeout(() => beep(1319, 220), 170); };
  ring();
  alarmTimer = setInterval(ring, 1600);
}
export function alarmStop() { clearInterval(alarmTimer); alarmTimer = null; }

// ---------- PIN xeshi (serverdagi PHP hash_pbkdf2 bilan bir xil) ----------
export async function pbkdf2Hex(pin, saltHex, iterations) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey('raw', enc.encode(pin), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: enc.encode(saltHex), iterations }, key, 256);
  return [...new Uint8Array(bits)].map(b => b.toString(16).padStart(2, '0')).join('');
}

export function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
