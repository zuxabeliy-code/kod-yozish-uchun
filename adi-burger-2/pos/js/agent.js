// Windows printer xizmati (127.0.0.1:9123) bilan aloqa.
// Chek ID si bir xil bo'lsa xizmat uni ikkinchi marta chop etmaydi — shuning uchun
// aloqa uzilganda qayta yuborish xavfsiz.

import { kv, db } from './db.js';
import { nowIso, uuid } from './util.js';

const BASE = 'http://127.0.0.1:9123';
let token = null;
export const agentState = {
  reachable: null, // null — hali tekshirilmagan
  version: null,
  error: '',
  printer: null, // {configured, ok, error, target, paper}
  config: null,
  queue: null,
  checkedAt: null,
};
const listeners = new Set();
export const onAgentChange = (fn) => listeners.add(fn);
const emit = () => listeners.forEach(f => { try { f(agentState); } catch {} });

async function call(path, { method = 'GET', body = null, timeout = 5000 } = {}) {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeout);
  try {
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['X-Agent-Token'] = token;
    const r = await fetch(BASE + path, { method, headers, body: body ? JSON.stringify(body) : null, signal: ctl.signal, cache: 'no-store' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Printer xizmati javobi noto\'g\'ri' }));
    if (r.status === 401 && j.code === 'TOKEN') {
      token = null;
      await kv.del('agent_token');
    }
    return { status: r.status, ...j };
  } catch (e) {
    return { ok: false, status: 0, error: 'Printer xizmatiga ulanib bo\'lmadi', offline: true };
  } finally {
    clearTimeout(t);
  }
}

async function ensureToken() {
  if (token) return true;
  token = await kv.get('agent_token');
  if (token) return true;
  const r = await call('/pair', { method: 'POST', body: {} });
  if (r.ok && r.token) {
    token = r.token;
    await kv.set('agent_token', token);
    return true;
  }
  agentState.error = r.offline ? '' : (r.error || 'Ulash rad etildi');
  return false;
}

function agentDownError() {
  return "Printer xizmati ishlamayapti (127.0.0.1:9123). Kompyuterni qayta yoqing yoki ish stolidagi o'rnatuvchini qayta ishga tushiring.";
}

/** Holatni yangilaydi (yuqori paneldagi printer belgisi). */
export async function refreshStatus() {
  const okTok = await ensureToken();
  let r = okTok ? await call('/status') : await call('/ping');
  if (r.status === 401) r = (await ensureToken()) ? await call('/status') : r;
  agentState.checkedAt = nowIso();
  if (r.offline) {
    Object.assign(agentState, { reachable: false, error: agentDownError(), printer: null, queue: null });
  } else if (!r.ok) {
    Object.assign(agentState, { reachable: true, error: r.error || 'Printer xizmati xatosi' });
  } else {
    Object.assign(agentState, { reachable: true, error: okTok ? '' : agentState.error, version: r.version, printer: r.printer || agentState.printer, config: r.printer_config || agentState.config, queue: r.queue || agentState.queue });
  }
  emit();
  return agentState;
}

export async function checkPrinter() {
  if (!(await ensureToken())) return refreshStatus();
  const r = await call('/check', { timeout: 8000 });
  if (r.ok) Object.assign(agentState, { reachable: true, printer: r.printer, queue: r.queue, error: '' });
  else if (r.offline) Object.assign(agentState, { reachable: false, error: agentDownError() });
  emit();
  return r;
}

export async function saveConfig(cfg) {
  if (!(await ensureToken())) return { ok: false, error: agentState.error || agentDownError() };
  const r = await call('/config', { method: 'POST', body: cfg, timeout: 9000 });
  if (r.ok) Object.assign(agentState, { config: r.printer_config, printer: r.printer });
  emit();
  return r.offline ? { ok: false, error: agentDownError() } : r;
}

export async function testPrint() {
  if (!(await ensureToken())) return { ok: false, error: agentState.error || agentDownError() };
  const r = await call('/test', { method: 'POST', body: {} });
  return r.offline ? { ok: false, error: agentDownError() } : r;
}

export async function jobs() {
  if (!(await ensureToken())) return { ok: false, error: agentDownError(), jobs: [] };
  return call('/jobs');
}
export async function retryJob(id) { await ensureToken(); return call('/jobs/retry', { method: 'POST', body: { id } }); }
export async function retryErrors() { await ensureToken(); return call('/jobs/retry-errors', { method: 'POST', body: {} }); }
export async function reprintJob(id) {
  await ensureToken();
  const newId = uuid();
  const r = await call('/jobs/reprint', { method: 'POST', body: { id, new_id: newId } });
  if (r.ok) {
    const old = await db.get('prints', id);
    if (old) {
      await db.put('prints', { ...old, status: 'reprinted' });
      await savePrint({ ...old, id: r.job.id, status: r.job.status, reprint_of: id, created_at: nowIso(), error: '' });
    }
  }
  return r;
}
export async function listPrinters() { await ensureToken(); return call('/printers'); }

async function savePrint(p) {
  await db.put('prints', p);
  printListeners.forEach(f => f(p));
}
const printListeners = new Set();
export const onPrint = (fn) => printListeners.add(fn);

/**
 * Chek yuborish. Avval lokal "prints" ro'yxatiga yoziladi (kompyuter o'chsa ham yo'qolmaydi),
 * keyin xizmatga yuboriladi. Xizmat ishlamasa — "agent_down" holatida qoladi va xizmat tiklanganda
 * avtomatik yuboriladi (bir xil ID — ikki marta chiqmaydi).
 */
export async function print({ id = uuid(), kind, orderUuid = null, orderRef = '', title = '', doc, copies = 1 }) {
  const rec = { id, kind, order_uuid: orderUuid, order_ref: orderRef, title, doc, copies, status: 'queued', error: '', created_at: nowIso(), synced: 0 };
  const ex = await db.get('prints', id);
  if (ex && ex.status !== 'queued' && ex.status !== 'agent_down') return ex;
  await savePrint(ex ? { ...ex, doc, copies } : rec);
  return deliver(id);
}

async function deliver(id) {
  const p = await db.get('prints', id);
  if (!p) return null;
  if (!(await ensureToken())) {
    await savePrint({ ...p, status: 'agent_down', error: agentState.error || agentDownError() });
    return db.get('prints', id);
  }
  const r = await call('/print', { method: 'POST', body: { id: p.id, kind: p.kind, order_ref: p.order_ref, title: p.title, doc: p.doc, copies: p.copies } });
  if (r.ok && r.job) {
    await savePrint({ ...p, status: r.job.status, error: r.job.error || '', synced: 0 });
  } else {
    await savePrint({ ...p, status: 'agent_down', error: r.offline ? agentDownError() : (r.error || 'Xato') });
  }
  return db.get('prints', id);
}

/** Navbatdagi cheklar holatini xizmatdan o'qib, lokal yozuvlarni yangilaydi. */
export async function pollPrints() {
  const all = await db.all('prints');
  const pending = all.filter(p => ['queued', 'agent_down', 'pending', 'sending'].includes(p.status));
  if (!pending.length) return [];
  const changed = [];
  for (const p of pending) {
    if (p.status === 'queued' || p.status === 'agent_down') {
      const r = await deliver(p.id);
      if (r && r.status !== p.status) changed.push(r);
      continue;
    }
    const r = await call('/job?id=' + encodeURIComponent(p.id));
    if (r.ok && r.job && (r.job.status !== p.status || r.job.error !== p.error)) {
      const n = { ...p, status: r.job.status, error: r.job.error || '', synced: 0 };
      await savePrint(n);
      changed.push(n);
    }
  }
  return changed;
}

export const PRINT_STATUS = {
  queued: ['Kutilmoqda', 'yellow'], agent_down: ['Xizmat ishlamayapti', 'red'], pending: ['Kutilmoqda', 'yellow'],
  sending: ['Yuborilmoqda', 'blue'], sent: ['Yuborildi', 'green'], error: ['Xato', 'red'], unknown: ["Natija noma'lum", 'violet'], reprinted: ['Qayta chiqarildi', ''],
};
