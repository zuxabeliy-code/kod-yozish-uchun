// Chek shablonlari. Printer xizmati bu qatorlarni ESC/POS ga aylantiradi (58 yoki 80 mm).

import { fmt, dt, nowIso } from './util.js';

const TYPE_LABEL = { table: 'STOL', takeaway: 'OLIB KETISH', phone: 'TELEFON', delivery: 'DOSTAVKA' };
const PAY_LABEL = { cash: 'Naqd', terminal: 'Terminal (karta)', click: 'Click', card_transfer: "Kartaga o'tkazma", hamyon: 'Hamyon' };

export const payLabel = (m) => PAY_LABEL[m] || m;

function typeLine(o, tables) {
  if (o.source === 'telegram') return `TELEGRAM ${o.number} · ${o.type === 'delivery' ? 'DOSTAVKA' : 'OLIB KETISH'}`;
  if (o.type === 'table') {
    const t = tables.find(x => x.id === o.table_id);
    return `${(t ? t.name : 'STOL').toUpperCase()}${o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : ''}`;
  }
  return TYPE_LABEL[o.type] || o.type;
}

/** Oshxona cheki: faqat yangi (hali yuborilmagan) taomlar, narxsiz, katta yozuv. */
export function kitchenDoc(o, lines, ctx) {
  const L = [
    { t: `${ctx.brand} — OSHXONA`, align: 'c', b: true },
    { t: typeLine(o, ctx.tables), align: 'c', b: true, s: 2 },
    { t: `Buyurtma #${o.number}`, align: 'c', b: true, s: 2 },
  ];
  if (ctx.isAddition) L.push({ t: "*** QO'SHIMCHA ***", align: 'c', b: true });
  L.push({ type: 'lr', l: dt(nowIso()), r: ctx.cashier || '' }, { type: 'sep', ch: '=' });
  for (const it of lines) {
    L.push({ t: `${it.qty} x ${it.name}`, b: true, s: 2 });
    if (it.comment) L.push({ t: `  > ${it.comment}`, b: true });
  }
  L.push({ type: 'sep', ch: '=' });
  if (o.comment) L.push({ t: `IZOH: ${o.comment}`, b: true });
  if (o.type === 'delivery' || o.source === 'telegram') {
    if (o.phone) L.push({ t: `Tel: ${o.phone}` });
    if (o.address) L.push({ t: `Manzil: ${o.address}` });
  }
  return { lines: L, beep: true };
}

/** Bekor qilish cheki (oshxonaga). */
export function cancelDoc(o, c, ctx) {
  return {
    beep: true,
    lines: [
      { t: 'BEKOR QILINDI', align: 'c', b: true, s: 2 },
      { t: `${typeLine(o, ctx.tables)} · #${o.number}`, align: 'c', b: true },
      { type: 'sep', ch: '=' },
      { t: `${c.qty} x ${c.name}`, b: true, s: 2 },
      { type: 'sep' },
      { t: `Sabab: ${c.reason}`, b: true },
      { type: 'lr', l: dt(c.created_at), r: ctx.cashier || '' },
    ],
  };
}

function header(ctx) {
  const L = [{ t: ctx.brand, align: 'c', b: true, s: 2 }];
  if (ctx.address) L.push({ t: ctx.address, align: 'c' });
  if (ctx.phone) L.push({ t: 'Tel: ' + ctx.phone, align: 'c' });
  return L;
}

/** Mijoz cheki: narxlar, jami, to'lov turi. */
export function customerDoc(o, ctx) {
  const items = o.items.filter(i => i.qty > 0);
  const total = items.reduce((s, i) => s + i.price * i.qty, 0) + (o.delivery_fee || 0);
  const L = [...header(ctx), { type: 'sep' },
    { type: 'lr', l: `Buyurtma #${o.number}`, r: typeLine(o, ctx.tables), b: true },
    { type: 'lr', l: dt(o.closed_at || nowIso()), r: ctx.cashier || '' },
    { type: 'sep' }];
  for (const it of items) {
    L.push({ t: it.name });
    L.push({ type: 'lr', l: `  ${it.qty} x ${fmt(it.price)}`, r: fmt(it.price * it.qty) });
  }
  if (o.delivery_fee) L.push({ type: 'lr', l: 'Yetkazish', r: fmt(o.delivery_fee) });
  L.push({ type: 'sep', ch: '=' }, { type: 'lr', l: 'JAMI:', r: fmt(total) + " so'm", b: true, s: 2 }, { type: 'sep' });
  let change = 0;
  for (const p of (o.payments || [])) {
    L.push({ type: 'lr', l: payLabel(p.method), r: fmt(p.amount) });
    if (p.method === 'cash' && p.received) {
      L.push({ type: 'lr', l: '  Berildi', r: fmt(p.received) });
      change += p.change || 0;
    }
  }
  if (change > 0) L.push({ type: 'lr', l: 'Qaytim', r: fmt(change), b: true });
  if (o.customer_name || o.phone) L.push({ type: 'sep' }, { t: [o.customer_name, o.phone].filter(Boolean).join(' · ') });
  if (o.address) L.push({ t: 'Manzil: ' + o.address });
  L.push({ type: 'sep' }, { t: ctx.footer || 'Xaridingiz uchun rahmat!', align: 'c', b: true });
  return { lines: L };
}

/** Smena yopishda bir nechta stol uchun bitta umumiy chek. */
export function combinedDoc(orders, ctx) {
  const L = [...header(ctx), { t: "UMUMIY CHEK (stollar yopildi)", align: 'c', b: true }, { type: 'lr', l: dt(nowIso()), r: ctx.cashier || '' }, { type: 'sep', ch: '=' }];
  let grand = 0;
  for (const o of orders) {
    const sum = o.items.reduce((s, i) => s + i.price * i.qty, 0);
    grand += sum;
    L.push({ type: 'lr', l: `${typeLine(o, ctx.tables)} #${o.number}`, r: fmt(sum), b: true });
    for (const it of o.items.filter(i => i.qty > 0)) L.push({ type: 'lr', l: `  ${it.qty} x ${it.name}`, r: fmt(it.price * it.qty) });
    for (const p of o.payments || []) L.push({ type: 'lr', l: `  ${payLabel(p.method)}`, r: fmt(p.amount) });
    L.push({ type: 'sep' });
  }
  L.push({ type: 'lr', l: 'JAMI:', r: fmt(grand) + " so'm", b: true, s: 2 }, { type: 'sep' }, { t: ctx.footer || 'Rahmat!', align: 'c' });
  return { lines: L };
}

/** Z-hisobot (ichki boshqaruv hisoboti, fiskal emas). */
export function zDoc(r, ctx) {
  const L = [
    { t: ctx.brand, align: 'c', b: true, s: 2 },
    { t: 'SMENA HISOBOTI (Z)', align: 'c', b: true },
    { t: '(ichki hisobot, fiskal emas)', align: 'c' },
    { type: 'sep', ch: '=' },
    { type: 'lr', l: 'Smena', r: '#' + r.number },
    { type: 'lr', l: 'Kassir', r: r.cashier },
    { type: 'lr', l: 'Ochildi', r: dt(r.opened_at) },
    { type: 'lr', l: 'Yopildi', r: dt(r.closed_at) },
    { type: 'sep' },
    { type: 'lr', l: 'JAMI SAVDO', r: fmt(r.sales), b: true },
    { type: 'lr', l: 'Naqd', r: fmt(r.pay.cash) },
    { type: 'lr', l: 'Terminal (karta)', r: fmt(r.pay.terminal) },
    { type: 'lr', l: 'Click', r: fmt(r.pay.click) },
  ];
  if (r.pay.online) L.push({ type: 'lr', l: 'Telegram (onlayn)', r: fmt(r.pay.online) });
  L.push(
    { type: 'lr', l: 'Buyurtmalar soni', r: String(r.orders) },
    { type: 'lr', l: "O'rtacha chek", r: fmt(r.avg) },
    { type: 'lr', l: 'Bekor qilishlar', r: `${r.cancels} ta / ${fmt(r.cancels_sum)}` },
    { type: 'sep' },
    { type: 'lr', l: "Boshlang'ich naqd", r: fmt(r.opening_cash) },
    { type: 'lr', l: 'Hisoblangan naqd', r: fmt(r.expected_cash), b: true },
    { type: 'lr', l: 'Sanalgan naqd', r: fmt(r.counted_cash), b: true },
    { type: 'lr', l: 'Farq', r: (r.diff > 0 ? '+' : '') + fmt(r.diff), b: true },
    { type: 'sep', ch: '=' },
  );
  if (r.top && r.top.length) {
    L.push({ t: 'Eng ko\'p sotilganlar:', b: true });
    for (const t of r.top.slice(0, 10)) L.push({ type: 'lr', l: `${t.name}`, r: `${t.qty} ta` });
    L.push({ type: 'sep' });
  }
  L.push({ t: 'Imzo: ____________', align: 'l' });
  return { lines: L };
}

/** X-hisobot (oraliq, smena yopilmaydi) — shu hisobotni qog'ozga chiqarish. */
export function xDoc(r, ctx) {
  const d = zDoc(r, ctx);
  d.lines[1] = { t: 'ORALIQ HISOBOT (X)', align: 'c', b: true };
  return d;
}
