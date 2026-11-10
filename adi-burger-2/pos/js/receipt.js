// Chek shablonlari. Oshxona va mijoz cheki admin paneldagi "Chek dizayni" bloklaridan quriladi.
// Printer xizmati bu qatorlarni ESC/POS ga aylantiradi (58 yoki 80 mm). Admin panelning jonli ko'rinishi ham shu fayldan foydalanadi.

import { fmt, dt, nowIso } from './util.js';
import { qrImage } from './qr.js';

const TYPE_LABEL = { table: 'STOL', takeaway: 'OLIB KETISH', phone: 'TELEFON', delivery: 'DOSTAVKA' };
const PAY_LABEL = { cash: 'Naqd', terminal: 'Terminal (karta)', click: 'Click', card_transfer: "Kartaga o'tkazma", hamyon: 'Hamyon' };

export const payLabel = (m) => PAY_LABEL[m] || m;

function typeLine(o, tables) {
  if (o.source === 'telegram') return `TELEGRAM ${o.number} · ${o.type === 'delivery' ? 'DOSTAVKA' : 'OLIB KETISH'}`;
  if (o.type === 'table') {
    const t = (tables || []).find(x => x.id === o.table_id);
    const nm = t ? (/^\d+$/.test(String(t.name).trim()) ? 'STOL ' + String(t.name).trim() : String(t.name).toUpperCase()) : 'STOL';
    return `${t && t.zone ? String(t.zone).toUpperCase() + ' · ' : ''}${nm}${o.bill_no > 1 ? ' (Hisob-' + o.bill_no + ')' : ''}`;
  }
  return TYPE_LABEL[o.type] || o.type;
}

/** Dizayn yo'q bo'lganda (eski kesh) ishlatiladigan eng sodda shablon. */
const FALLBACK = {
  font: 'a', line_pct: 100, margin_mm: 0, bold_all: false,
  kitchen: { copies: 1, blocks: [{ kind: 'items', on: true, align: 'l', size: 2, bold: true, o: { price: false } }, { kind: 'comment', on: true, align: 'l', size: 1, bold: true }] },
  customer: {
    copies: 1, blocks: [
      { kind: 'shop', on: true, align: 'c', size: 2, bold: true }, { kind: 'order_no', on: true, align: 'l', size: 1, bold: true }, { kind: 'datetime', on: true, align: 'l', size: 1 },
      { kind: 'separator', on: true, ch: '-' }, { kind: 'customer', on: true, align: 'l', size: 1, o: { name: true, phone: true, address: true } },
      { kind: 'items', on: true, align: 'l', size: 1, o: { price: true } }, { kind: 'delivery_fee', on: true }, { kind: 'separator', on: true, ch: '=' },
      { kind: 'total', on: true, align: 'l', size: 2, bold: true }, { kind: 'payments', on: true }, { kind: 'text', on: true, align: 'c', size: 1, bold: true, text: 'Xaridingiz uchun rahmat!' },
    ],
  },
};

/** Bloklardan chek hujjatini quradi. items: kitchen uchun {name, qty, comment}; customer uchun {name, qty, price}. */
export function renderReceipt(which, o, items, ctx) {
  const d = ctx.design || FALLBACK;
  const blocks = (d[which] || FALLBACK[which]).blocks;
  const L = [];
  const push = (blk, line) => { L.push({ align: blk.align || 'l', b: !!blk.bold || !!d.bold_all, s: blk.size === 2 ? 2 : 1, ...line }); };
  const priced = items.filter(i => i.qty > 0);
  const itemsTotal = priced.reduce((s, i) => s + (i.price || 0) * i.qty, 0);
  const fee = o.type === 'delivery' ? (o.delivery_fee || 0) : 0;
  for (const b of blocks) {
    if (!b.on) continue;
    const o2 = b.o || {};
    switch (b.kind) {
      case 'logo':
        if (ctx.logo && ctx.logo.b64) L.push({ type: 'img', w: ctx.logo.w, h: ctx.logo.h, b64: ctx.logo.b64, align: b.align || 'c' });
        break;
      case 'text':
        for (const ln of String(b.text || '').split('\n')) if (ln.trim()) push(b, { t: ln });
        break;
      case 'shop': push(b, { t: ctx.brand }); break;
      case 'shop_info':
        if (ctx.address) push(b, { t: ctx.address });
        if (ctx.phone) push(b, { t: 'Tel: ' + ctx.phone });
        break;
      case 'type_line': push(b, { t: typeLine(o, ctx.tables) }); break;
      case 'order_no': if (o.number) push(b, { t: `Buyurtma #${o.number}` }); break;
      case 'datetime': push(b, { t: dt(o.closed_at || nowIso()) }); break;
      case 'cashier': if (ctx.cashier) push(b, { t: 'Kassir: ' + ctx.cashier }); break;
      case 'customer': {
        if (o2.name !== false && o.customer_name) push(b, { t: o.customer_name });
        if (o2.phone !== false && o.phone) push(b, { t: 'Tel: ' + o.phone });
        if (o2.address !== false && o.address) push(b, { t: 'Manzil: ' + o.address });
        break;
      }
      case 'comment': if (o.comment) push(b, { t: 'IZOH: ' + o.comment }); break;
      case 'items':
        for (const it of priced) {
          if (which === 'customer' && o2.price !== false) {
            push(b, { t: it.name });
            push(b, { type: 'lr', l: `  ${it.qty} x ${fmt(it.price)}`, r: fmt(it.price * it.qty) });
          } else {
            push(b, { t: `${it.qty} x ${it.name}` });
          }
          if (it.comment) push(b, { t: `  > ${it.comment}` });
        }
        break;
      case 'delivery_fee': if (fee) push(b, { type: 'lr', l: 'Yetkazish', r: fmt(fee) }); break;
      case 'total': push(b, { type: 'lr', l: 'JAMI:', r: fmt(itemsTotal + fee) + " so'm" }); break;
      case 'payments': {
        let change = 0;
        for (const p of (o.payments || [])) {
          push(b, { type: 'lr', l: payLabel(p.method), r: fmt(p.amount) });
          if (p.method === 'cash' && p.received) { push(b, { type: 'lr', l: '  Berildi', r: fmt(p.received) }); change += p.change || 0; }
        }
        if (change > 0) push(b, { type: 'lr', l: 'Qaytim', r: fmt(change) });
        break;
      }
      case 'qr': {
        // Onlayn chek havolasi (buyurtma UUID si — taxmin qilib bo'lmaydi). Rasm sifatida chiqadi: har qanday ESC/POS printerda ishlaydi.
        if (which !== 'customer' || !o.uuid || !ctx.base) break;
        const q = qrImage(ctx.base + 'r/?o=' + o.uuid, b.size === 2 ? 300 : 216);
        L.push({ type: 'img', w: q.w, h: q.h, b64: q.b64, align: b.align || 'c' });
        for (const ln of String(b.text || '').split('\n')) if (ln.trim()) push({ ...b, size: 1 }, { t: ln });
        break;
      }
      case 'separator': L.push({ type: 'sep', ch: b.ch || '-' }); break;
      case 'feed': L.push({ type: 'feed', n: b.n || 1 }); break;
    }
  }
  return withOpts({ lines: L, beep: which === 'kitchen' }, d);
}

/** Dizayndagi umumiy sozlamalar (shrift, qator oralig'i, chet) hujjatga qo'shiladi. */
export function withOpts(doc, design) {
  const d = design || FALLBACK;
  doc.font = d.font === 'b' ? 'b' : 'a';
  doc.ls = Math.round(30 * (d.line_pct || 100) / 100);
  doc.ml = Math.round((d.margin_mm || 0) * 8);
  return doc;
}

/** Oshxona cheki: faqat yangi (hali yuborilmagan) taomlar. */
export function kitchenDoc(o, lines, ctx) {
  const doc = renderReceipt('kitchen', o, lines, ctx);
  if (ctx.isAddition && ctx.design && !ctx.design.kitchen.blocks.some(b => b.on && b.kind === 'order_no')) { /* qo'shimcha belgisi kerak emas: faqat yangi taomlar chiqadi */ }
  return doc;
}

/** Mijoz cheki: narxlar, jami, to'lov turi; mijoz ma'lumoti bo'lsa manzil va telefon ham. */
export function customerDoc(o, ctx) {
  return renderReceipt('customer', o, o.items, ctx);
}

/** Bekor qilish cheki (oshxonaga). */
export function cancelDoc(o, c, ctx) {
  return withOpts({
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
  }, ctx.design);
}

/** Smena yopishda bir nechta stol uchun bitta umumiy chek. */
export function combinedDoc(orders, ctx) {
  const L = [{ t: ctx.brand, align: 'c', b: true, s: 2 }, { t: 'UMUMIY CHEK (stollar yopildi)', align: 'c', b: true }, { type: 'lr', l: dt(nowIso()), r: ctx.cashier || '' }, { type: 'sep', ch: '=' }];
  let grand = 0;
  for (const o of orders) {
    const sum = o.items.reduce((s, i) => s + i.price * i.qty, 0);
    grand += sum;
    L.push({ type: 'lr', l: `${typeLine(o, ctx.tables)} #${o.number}`, r: fmt(sum), b: true });
    for (const it of o.items.filter(i => i.qty > 0)) L.push({ type: 'lr', l: `  ${it.qty} x ${it.name}`, r: fmt(it.price * it.qty) });
    for (const p of o.payments || []) L.push({ type: 'lr', l: `  ${payLabel(p.method)}`, r: fmt(p.amount) });
    L.push({ type: 'sep' });
  }
  L.push({ type: 'lr', l: 'JAMI:', r: fmt(grand) + " so'm", b: true, s: 2 }, { type: 'sep' }, { t: 'Rahmat!', align: 'c' });
  return withOpts({ lines: L }, ctx.design);
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
  return withOpts({ lines: L }, ctx.design);
}

/** X-hisobot (oraliq, smena yopilmaydi). */
export function xDoc(r, ctx) {
  const d = zDoc(r, ctx);
  d.lines[1] = { t: 'ORALIQ HISOBOT (X)', align: 'c', b: true };
  return d;
}

/** O'lchov cheki: qog'ozdagi chizg'ichni haqiqiy chizg'ich bilan solishtirib, chek eni va chetlarini sozlash uchun. */
export function rulerDoc(ctx) {
  const L = [{ t: "O'LCHOV CHEKI", align: 'c', b: true, s: 2 }, { t: 'Chizg\'ich bilan o\'lchang: 1 sm = 1 sm', align: 'c' }, { type: 'sep', ch: '=' }];
  for (let cm = 0; cm <= 6; cm++) L.push({ t: `|${'.'.repeat(5)}| ${cm} sm`, align: 'l' });
  L.push({ type: 'sep', ch: '=' }, { t: '1234567890'.repeat(5), align: 'l' }, { t: 'Chap chet  ◄─────────►  O\'ng chet'.replace(/[◄►─]/g, '-'), align: 'c' }, { type: 'sep', ch: '=' }, { t: 'Yozuv chetdan chiqsa — chet (mm) ni oshiring.', align: 'l' });
  return withOpts({ lines: L }, ctx.design);
}

// ====================================================================
// Jonli ko'rinish (admin panel): hujjatni qog'oz ko'rinishida HTMLga aylantiradi. Printer xizmatidagi joylashuvga yaqin.
// ====================================================================
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function wrapText(s, width) {
  const out = [];
  for (const para of String(s).split('\n')) {
    let cur = '';
    for (let w of para.split(/\s+/).filter(Boolean)) {
      while (w.length > width) { if (cur) { out.push(cur); cur = ''; } out.push(w.slice(0, width)); w = w.slice(width); }
      if (!cur) cur = w; else if (cur.length + 1 + w.length <= width) cur += ' ' + w; else { out.push(cur); cur = w; }
    }
    out.push(cur);
  }
  return out;
}

export function previewColumns(paper, font) {
  return paper === 58 ? (font === 'b' ? 42 : 32) : (font === 'b' ? 64 : 48);
}

export function previewHtml(doc, paper, logoDots = 576) {
  const dots = paper === 58 ? 384 : 576;
  const ml = Math.max(0, Math.min(120, doc.ml || 0));
  const cols = Math.max(8, Math.floor((dots - 2 * ml) / (doc.font === 'b' ? 9 : 12)));
  const parts = [];
  for (const ln of doc.lines) {
    if (ln.type === 'img') {
      const pct = Math.min(100, ln.w / logoDots * 100); // qog'oz eniga nisbatan
      parts.push(`<div class="pl img" style="text-align:${{ l: 'left', c: 'center', r: 'right' }[ln.align || 'c']}"><canvas data-b64="${esc(ln.b64)}" data-w="${ln.w}" data-h="${ln.h}" style="width:calc(var(--pw) * ${(pct / 100).toFixed(4)})"></canvas></div>`);
      continue;
    }
    const size = ln.s === 2 ? 2 : 1;
    const width = Math.max(4, Math.floor(cols / size));
    let rows = [];
    if (ln.type === 'sep') rows = [String((ln.ch || '-')[0]).repeat(width)];
    else if (ln.type === 'feed') rows = Array(Math.max(1, ln.n || 1)).fill(' ');
    else if (ln.type === 'lr') {
      const l = ln.l || '', r = ln.r || '';
      if (r.length >= width - 2) rows = [...wrapText(l, width), r.padStart(width)];
      else { const ls = wrapText(l, width - r.length - 1); ls[ls.length - 1] = ls[ls.length - 1].padEnd(width - r.length) + r; rows = ls; }
    } else rows = wrapText(ln.t || '', width);
    const al = { l: 'left', c: 'center', r: 'right' }[ln.align || 'l'];
    for (const r of rows) parts.push(`<div class="pl${ln.b ? ' b' : ''}${size === 2 ? ' dbl' : ''}" style="text-align:${al}">${esc(r) || '&nbsp;'}</div>`);
  }
  return `<div class="paper p${paper}${doc.font === 'b' ? ' fb' : ''}" style="--ls:${(doc.ls || 30) / 30};padding:0 ${(ml / dots * 100).toFixed(2)}%">${parts.join('')}<div class="cut">✂ - - - - - - - - - - - -</div></div>`;
}

/** previewHtml ichidagi logo canvaslarini chizadi (1-bitli rasm). */
export function paintLogos(root) {
  root.querySelectorAll('canvas[data-b64]').forEach(cv => {
    const w = +cv.dataset.w, h = +cv.dataset.h;
    const bin = atob(cv.dataset.b64);
    cv.width = w; cv.height = h;
    const ctx = cv.getContext('2d');
    const img = ctx.createImageData(w, h);
    const rowBytes = w / 8;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const on = (bin.charCodeAt(y * rowBytes + (x >> 3)) >> (7 - (x & 7))) & 1;
      const i = (y * w + x) * 4;
      img.data[i] = img.data[i + 1] = img.data[i + 2] = on ? 0 : 255; img.data[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
  });
}
