// Buyurtma, to'lov, bekor qilish va smena mantig'i. Hammasi avval lokal bazaga yoziladi
// (internet shart emas), keyin outbox orqali serverga boradi.

import { db, kv, tx } from './db.js';
import { opPut, kick } from './sync.js';
import { uuid, nowIso, localDate } from './util.js';

export const S = {
  catalog: null,       // {categories, products, tables, employees, settings, ...}
  employee: null,      // kirgan xodim
  shift: null,         // ochiq smena
};

export const itemsTotal = (o) => o.items.reduce((s, i) => s + i.price * i.qty, 0);
export const orderTotal = (o) => (o.status === 'cancelled' ? 0 : itemsTotal(o) + (o.type === 'delivery' ? (o.delivery_fee || 0) : 0));
export const paidTotal = (o) => (o.payments || []).reduce((s, p) => s + p.amount, 0);
export const unsentLines = (o) => o.items.filter(i => i.qty > i.sent_qty);

export function snapshot(o) {
  const pick = ({ uuid, number, type, table_id, bill_no, shift_uuid, employee_id, customer_name, phone, address, comment, status, delivery_fee, created_at, closed_at, rev, cancel_reason, items, cancels, payments }) =>
    ({ uuid, number, type, table_id, bill_no, shift_uuid, employee_id, customer_name, phone, address, comment, status, delivery_fee, created_at, closed_at, rev, cancel_reason, items, cancels, payments });
  return JSON.parse(JSON.stringify(pick(o)));
}

/** Buyurtmani saqlaydi. Qoralama (draft) serverga yuborilmaydi. */
export async function saveOrder(o) {
  o.updated_at = nowIso();
  if (o.status !== 'draft') o.rev = (o.rev || 0) + 1;
  await tx(['orders', 'outbox'], 'readwrite', async s => {
    await db.req(s.orders.put(o));
    if (o.status !== 'draft') await opPut(s, 'order.save', snapshot(o));
  });
  if (o.status !== 'draft') kick();
  return o;
}

export async function nextNumber() {
  const key = 'counter:' + localDate();
  const n = ((await kv.get(key)) || 0) + 1;
  await kv.set(key, n);
  return String(n);
}

export function newDraft(type = 'takeaway', extra = {}) {
  return {
    uuid: uuid(), number: '', type, table_id: null, bill_no: 1, status: 'draft', source: 'pos',
    shift_uuid: S.shift?.uuid || null, employee_id: S.employee?.id || null,
    customer_name: '', phone: '', address: '', comment: '', delivery_fee: type === 'delivery' ? (S.catalog?.settings?.delivery_fee || 0) : 0,
    items: [], cancels: [], payments: [], rev: 0, created_at: nowIso(), closed_at: null, ...extra,
  };
}

export function addProduct(o, p) {
  let line = o.items.find(i => i.product_id === p.id && !i.comment);
  if (line) line.qty++;
  else {
    line = { uuid: uuid(), product_id: p.id, name: p.name, price: p.price, qty: 1, sent_qty: 0, cancelled_qty: 0, comment: '', created_at: nowIso() };
    o.items.push(line);
  }
  return line;
}

/** Miqdorni o'zgartiradi. Oshxonaga yuborilgan qismini kamaytirish uchun bekor qilish (sabab bilan) kerak. */
export function setQty(o, line, qty) {
  qty = Math.max(0, Math.min(999, Math.floor(qty)));
  if (qty < line.sent_qty) return { needsCancel: line.sent_qty - qty };
  line.qty = qty;
  if (line.qty === 0 && line.sent_qty === 0 && line.cancelled_qty === 0) o.items = o.items.filter(i => i !== line);
  return { ok: true };
}

/** Oshxonaga yuborilgan taomni bekor qilish (sabab saqlanadi, tarixdan o'chmaydi). */
export function cancelSent(o, line, qty, reason) {
  qty = Math.min(qty, line.sent_qty);
  if (qty < 1) return null;
  line.qty -= qty;
  line.sent_qty -= qty;
  line.cancelled_qty += qty;
  const c = { uuid: uuid(), item_uuid: line.uuid, name: line.name, price: line.price, qty, reason, employee_id: S.employee?.id || null, created_at: nowIso() };
  o.cancels.push(c);
  return c;
}

/** Tasdiqlash: buyurtma saqlanadi va oshxonaga faqat yangi taomlar ketadi. */
export async function confirmOrder(o) {
  const delta = unsentLines(o).map(i => ({ uuid: i.uuid, name: i.name, qty: i.qty - i.sent_qty, comment: i.comment }));
  if (!o.items.some(i => i.qty > 0)) throw new Error("Buyurtmada taom yo'q");
  const isAddition = o.status !== 'draft' && o.items.some(i => i.sent_qty > 0);
  if (!o.number) o.number = await nextNumber();
  if (o.status === 'draft') o.status = 'accepted';
  o.shift_uuid = o.shift_uuid || S.shift?.uuid || null;
  o.employee_id = o.employee_id || S.employee?.id || null;
  for (const i of o.items) i.sent_qty = i.qty;
  await saveOrder(o);
  return { delta, isAddition };
}

/** To'lov: summalar aniq saqlanadi, buyurtma yopiladi. */
export async function payOrder(o, lines) {
  const total = orderTotal(o);
  const sum = lines.reduce((s, l) => s + l.amount, 0);
  if (sum < total) throw new Error("To'lov summasi yetarli emas");
  const now = nowIso();
  let remaining = total - paidTotal(o);
  o.payments = o.payments || [];
  for (const l of lines) {
    if (remaining <= 0) break;
    const amount = Math.min(l.amount, remaining);
    const p = { uuid: uuid(), method: l.method, amount, employee_id: S.employee?.id || null, shift_uuid: S.shift?.uuid || null, created_at: now };
    if (l.method === 'cash') { p.received = l.amount; p.change = Math.max(0, l.amount - amount); }
    o.payments.push(p);
    remaining -= amount;
  }
  o.status = 'done';
  o.closed_at = now;
  o.shift_uuid = S.shift?.uuid || o.shift_uuid;
  await saveOrder(o);
  return o;
}

export async function cancelWholeOrder(o, reason) {
  if (o.status === 'draft') {
    await db.del('orders', o.uuid);
    return [];
  }
  const cancels = [];
  for (const line of o.items) {
    if (line.sent_qty > 0) cancels.push(cancelSent(o, line, line.sent_qty, reason));
    line.qty = 0;
  }
  o.status = 'cancelled';
  o.cancel_reason = reason;
  o.closed_at = nowIso();
  await saveOrder(o);
  return cancels.filter(Boolean);
}

export async function openOrders() {
  return (await db.byIndex('orders', 'status', 'accepted')).sort((a, b) => a.created_at.localeCompare(b.created_at));
}
export async function drafts() {
  return db.byIndex('orders', 'status', 'draft');
}

// ---------- Smena ----------

export async function openShift(openingCash) {
  const last = Math.max(S.catalog?.last_shift_number || 0, (await kv.get('last_shift_number')) || 0);
  const sh = { uuid: uuid(), number: last + 1, employee_id: S.employee.id, employee: S.employee.name, opened_at: nowIso(), opening_cash: openingCash, status: 'open' };
  await tx(['kv', 'shifts', 'outbox'], 'readwrite', async s => {
    await db.req(s.kv.put(sh, 'shift'));
    await db.req(s.kv.put(sh.number, 'last_shift_number'));
    await db.req(s.shifts.put(sh));
    await opPut(s, 'shift.open', { uuid: sh.uuid, number: sh.number, employee_id: sh.employee_id, opened_at: sh.opened_at, opening_cash: openingCash });
  });
  S.shift = sh;
  kick();
  return sh;
}

/** Smena hisobotini lokal ma'lumotdan hisoblaydi (internet shart emas). */
export async function shiftReport(sh) {
  const all = await db.all('orders');
  const tg = await db.all('tg');
  const pay = { cash: 0, terminal: 0, click: 0, online: 0 };
  let orders = 0, sales = 0, cancels = 0, cancelsSum = 0;
  const top = {};
  for (const o of all) {
    if (o.shift_uuid !== sh.uuid) continue;
    for (const c of o.cancels || []) { cancels++; cancelsSum += c.price * c.qty; }
    if (o.status !== 'done') continue;
    orders++;
    for (const p of o.payments) { pay[p.method] = (pay[p.method] || 0) + p.amount; sales += p.amount; }
    for (const i of o.items) if (i.qty > 0) { top[i.name] = (top[i.name] || 0) + i.qty; }
  }
  for (const o of tg) {
    if (o.local_shift !== sh.uuid || !['accepted', 'ready', 'done'].includes(o.status)) continue;
    orders++;
    for (const p of o.local_payments || []) { pay[p.method] = (pay[p.method] || 0) + p.amount; sales += p.amount; }
    if (o.pay_status === 'paid' && !(o.local_payments || []).length) { pay.online += o.total; sales += o.total; }
    for (const i of o.items) if (i.qty > 0) { top[i.name] = (top[i.name] || 0) + i.qty; }
  }
  const expected = (sh.opening_cash || 0) + pay.cash;
  return {
    number: sh.number, cashier: sh.employee, opened_at: sh.opened_at, closed_at: sh.closed_at || nowIso(),
    pay, sales, orders, avg: orders ? Math.round(sales / orders) : 0, cancels, cancels_sum: cancelsSum,
    opening_cash: sh.opening_cash || 0, expected_cash: expected, counted_cash: sh.counted_cash ?? expected, diff: (sh.counted_cash ?? expected) - expected,
    top: Object.entries(top).map(([name, qty]) => ({ name, qty })).sort((a, b) => b.qty - a.qty),
  };
}

export async function closeShift(counted) {
  const sh = { ...S.shift, closed_at: nowIso(), counted_cash: counted, status: 'closed', closed_by: S.employee.id };
  const report = await shiftReport(sh);
  sh.report = report;
  await tx(['kv', 'shifts', 'outbox'], 'readwrite', async s => {
    await db.req(s.kv.delete('shift'));
    await db.req(s.shifts.put(sh));
    await opPut(s, 'shift.close', { uuid: sh.uuid, closed_at: sh.closed_at, employee_id: S.employee.id, counted_cash: counted, expected_cash: report.expected_cash, report });
  });
  S.shift = null;
  kick();
  return { sh, report };
}

/** Kun bo'yicha lokal hisobot (offline). */
export async function localReport(fromDate, toDate) {
  const all = await db.all('orders');
  const tg = await db.all('tg');
  const r = { orders: 0, sales: 0, pay: {}, byDay: {}, top: {}, cancels: 0, cancelsSum: 0, byType: {} };
  const inRange = (iso) => { const d = localDate(new Date(iso)); return d >= fromDate && d <= toDate; };
  for (const o of all) {
    for (const c of o.cancels || []) if (inRange(c.created_at)) { r.cancels++; r.cancelsSum += c.price * c.qty; }
    if (o.status !== 'done' || !inRange(o.closed_at || o.created_at)) continue;
    const t = orderTotal(o);
    r.orders++; r.sales += t;
    const d = localDate(new Date(o.closed_at || o.created_at));
    r.byDay[d] = (r.byDay[d] || 0) + t;
    r.byType[o.type] = (r.byType[o.type] || 0) + t;
    for (const p of o.payments) r.pay[p.method] = (r.pay[p.method] || 0) + p.amount;
    for (const i of o.items) if (i.qty > 0) r.top[i.name] = (r.top[i.name] || 0) + i.qty;
  }
  for (const o of tg) {
    if (!['accepted', 'ready', 'done'].includes(o.status) || !inRange(o.created_at)) continue;
    r.orders++; r.sales += o.total;
    const d = localDate(new Date(String(o.created_at).replace(' ', 'T')));
    r.byDay[d] = (r.byDay[d] || 0) + o.total;
    r.byType.telegram = (r.byType.telegram || 0) + o.total;
    for (const i of o.items) if (i.qty > 0) r.top[i.name] = (r.top[i.name] || 0) + i.qty;
  }
  return r;
}
