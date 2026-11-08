// IndexedDB: kassaning lokal (offline) bazasi. Kompyuter o'chsa ham ma'lumot saqlanadi.

const DB_NAME = 'adi-pos';
const DB_VER = 1;
let dbp = null;

function open() {
  if (dbp) return dbp;
  dbp = new Promise((resolve, reject) => {
    const r = indexedDB.open(DB_NAME, DB_VER);
    r.onupgradeneeded = () => {
      const d = r.result;
      if (!d.objectStoreNames.contains('kv')) d.createObjectStore('kv');
      if (!d.objectStoreNames.contains('orders')) {
        const s = d.createObjectStore('orders', { keyPath: 'uuid' });
        s.createIndex('status', 'status');
        s.createIndex('created_at', 'created_at');
      }
      if (!d.objectStoreNames.contains('outbox')) d.createObjectStore('outbox', { keyPath: 'seq', autoIncrement: true });
      if (!d.objectStoreNames.contains('tg')) d.createObjectStore('tg', { keyPath: 'uuid' });
      if (!d.objectStoreNames.contains('prints')) {
        const s = d.createObjectStore('prints', { keyPath: 'id' });
        s.createIndex('order_uuid', 'order_uuid');
      }
      if (!d.objectStoreNames.contains('shifts')) d.createObjectStore('shifts', { keyPath: 'uuid' });
    };
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
    r.onblocked = () => reject(new Error('Kassa boshqa oynada ochiq. Boshqa oynalarni yoping.'));
  });
  return dbp;
}

function req(r) {
  return new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
}

/** Bir nechta store bilan bitta tranzaksiya: hammasi saqlanadi yoki hech biri. */
export async function tx(stores, mode, fn) {
  const d = await open();
  return new Promise((resolve, reject) => {
    const t = d.transaction(stores, mode);
    let out;
    t.oncomplete = () => resolve(out);
    t.onerror = () => reject(t.error);
    t.onabort = () => reject(t.error || new Error('Tranzaksiya bekor qilindi'));
    const s = {};
    for (const n of [].concat(stores)) s[n] = t.objectStore(n);
    Promise.resolve(fn(s, t)).then(v => { out = v; }).catch(e => { try { t.abort(); } catch {} reject(e); });
  });
}

export const db = {
  async get(store, key) { return tx(store, 'readonly', s => req(s[store].get(key))); },
  async put(store, val, key) { return tx(store, 'readwrite', s => req(key === undefined ? s[store].put(val) : s[store].put(val, key))); },
  async del(store, key) { return tx(store, 'readwrite', s => req(s[store].delete(key))); },
  async all(store) { return tx(store, 'readonly', s => req(s[store].getAll())); },
  async count(store) { return tx(store, 'readonly', s => req(s[store].count())); },
  async clear(store) { return tx(store, 'readwrite', s => req(s[store].clear())); },
  async byIndex(store, index, value) { return tx(store, 'readonly', s => req(s[store].index(index).getAll(value))); },
  async first(store, n) {
    return tx(store, 'readonly', s => new Promise((res, rej) => {
      const out = [];
      const c = s[store].openCursor();
      c.onsuccess = () => { const cur = c.result; if (cur && out.length < n) { out.push(cur.value); cur.continue(); } else res(out); };
      c.onerror = () => rej(c.error);
    }));
  },
  req,
};

export const kv = {
  get: (k) => db.get('kv', k),
  set: (k, v) => db.put('kv', v, k),
  del: (k) => db.del('kv', k),
};

/** Brauzer ma'lumotni o'zi o'chirmasligi uchun doimiy saqlashni so'raymiz. */
export async function persist() {
  try { return navigator.storage && navigator.storage.persist ? await navigator.storage.persist() : false; } catch { return false; }
}
