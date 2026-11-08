// Serverga sinxronlash: outbox (yuborilmagan amallar navbati), qayta urinish, yangiliklarni olish.
// Har amal noyob op_uuid bilan — server uni ikkinchi marta bajarmaydi (idempotency).

import { db, kv, tx } from './db.js';
import { uuid, nowIso } from './util.js';

export const API = new URL('../api/', location.href).href;
export const syncState = { online: navigator.onLine, lastOk: null, lastError: '', outbox: 0, failedOps: 0, serverTime: null, busy: false };
const listeners = new Set();
export const onSync = (fn) => listeners.add(fn);
const emit = () => listeners.forEach(f => { try { f(syncState); } catch {} });

export async function api(route, { method = 'GET', body = null, timeout = 15000, auth = true } = {}) {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeout);
  const headers = { 'Content-Type': 'application/json' };
  if (auth) {
    const tok = await kv.get('device_token');
    if (tok) { headers.Authorization = 'Bearer ' + tok; headers['X-Device-Token'] = tok; }
  }
  try {
    const r = await fetch(API + '?r=' + route, { method, headers, body: body ? JSON.stringify(body) : null, signal: ctl.signal, cache: 'no-store', credentials: 'omit' });
    const j = await r.json().catch(() => ({ ok: false, error: `Server javobi noto'g'ri (HTTP ${r.status})` }));
    j.http = r.status;
    return j;
  } catch (e) {
    return { ok: false, http: 0, network: true, error: navigator.onLine ? 'Server bilan aloqa yo\'q' : 'Internet yo\'q' };
  } finally {
    clearTimeout(t);
  }
}

/** Amalni outboxga yozadi. Boshqa store o'zgarishlari bilan bitta tranzaksiyada chaqirish uchun opPut ham bor. */
export function opPut(stores, type, data) {
  return db.req(stores.outbox.add({ op_uuid: uuid(), type, data, created_at: nowIso(), tries: 0, last_error: '' }));
}
export async function enqueue(type, data) {
  await tx(['outbox'], 'readwrite', s => opPut(s, type, data));
  kick();
}

let timer = null;
let running = false;
let statusProvider = async () => ({});
let pullHandler = async () => {};
let authFail = () => {};
export function configureSync({ status, onPull, onAuthFail }) {
  statusProvider = status;
  pullHandler = onPull;
  authFail = onAuthFail;
}

export function kick(delay = 50) {
  clearTimeout(timer);
  timer = setTimeout(run, delay);
}

export async function outboxInfo() {
  const items = await db.first('outbox', 1);
  const count = await db.count('outbox');
  return { count, oldest_sec: items.length ? Math.round((Date.now() - new Date(items[0].created_at).getTime()) / 1000) : 0 };
}

async function run() {
  if (running) return;
  running = true;
  syncState.busy = true;
  let next = 5000;
  try {
    const ops = await db.first('outbox', 25);
    const since = (await kv.get('pull_since')) || '';
    const status = await statusProvider();
    status.outbox = await outboxInfo();
    const r = await api('sync', { method: 'POST', body: { ops: ops.map(o => ({ op_uuid: o.op_uuid, type: o.type, data: o.data, created_at: o.created_at })), since, status } });
    if (r.network || !r.ok) {
      syncState.online = false;
      syncState.lastError = r.error || 'Xato';
      if (r.http === 401 && r.code === 'DEVICE_AUTH') authFail(r.error);
      // Qayta urinish oralig'i asta-sekin oshadi (5s → 30s)
      const fails = (syncState._fails = (syncState._fails || 0) + 1);
      next = Math.min(30000, 3000 * fails);
      for (const o of ops) { o.tries++; o.last_error = syncState.lastError; }
      if (ops.length) await tx(['outbox'], 'readwrite', s => Promise.all(ops.map(o => db.req(s.outbox.put(o)))));
    } else {
      syncState._fails = 0;
      syncState.online = true;
      syncState.lastOk = nowIso();
      syncState.lastError = '';
      syncState.serverTime = r.server_time;
      syncState.failedOps = r.failed_ops || 0;
      const done = new Map((r.results || []).map(x => [x.op_uuid, x]));
      const log = (await kv.get('sync_log')) || [];
      await tx(['outbox'], 'readwrite', s => Promise.all(ops.filter(o => done.has(o.op_uuid)).map(o => db.req(s.outbox.delete(o.seq)))));
      for (const o of ops) {
        const res = done.get(o.op_uuid);
        if (res && res.status !== 'applied' && res.status !== 'duplicate') {
          log.unshift({ at: nowIso(), type: o.type, status: res.status, error: res.error || '' });
        }
      }
      if (ops.length) log.unshift({ at: nowIso(), type: 'sync', status: 'ok', error: `${ops.length} ta amal yuborildi` });
      await kv.set('sync_log', log.slice(0, 60));
      await kv.set('pull_since', r.server_time);
      await pullHandler(r);
      const left = await db.count('outbox');
      next = left > 0 ? 300 : 4000;
    }
  } catch (e) {
    syncState.lastError = String(e.message || e);
  } finally {
    syncState.outbox = await db.count('outbox');
    syncState.busy = false;
    running = false;
    emit();
    clearTimeout(timer);
    timer = setTimeout(run, next);
  }
}

window.addEventListener('online', () => { syncState.online = true; kick(10); });
window.addEventListener('offline', () => { syncState.online = false; emit(); });
