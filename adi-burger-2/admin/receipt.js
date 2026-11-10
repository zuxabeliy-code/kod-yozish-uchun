// Chek dizayneri (admin panel). Chap tomonda sozlamalar, o'ng tomonda jonli ko'rinish.
// Ko'rinish kassadagi chek quruvchi bilan bir xil modul (../pos/js/receipt.js) orqali chiziladi.

import { renderReceipt, previewHtml, paintLogos, rulerDoc } from '../pos/js/receipt.js';

const D = JSON.parse(document.getElementById('rc-data').textContent);
const design = D.design;
let logo = D.logo;
let tab = 'kitchen';
let sample = 'table';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const ITEMS = [
  { name: 'Cheese Burger', qty: 2, price: 33000, comment: 'Piyozsiz' },
  { name: 'Lavash cheese', qty: 1, price: 33000, comment: '' },
  { name: 'Coca-Cola 0.5L', qty: 2, price: 8000, comment: '' },
];
const now = new Date().toISOString();
const SAMPLES = {
  table: { uuid: '00000000-0000-4000-8000-000000000012', type: 'table', table_id: 1, bill_no: 1, number: '12', comment: 'Tezroq iltimos', customer_name: '', phone: '', address: '', delivery_fee: 0, closed_at: now, payments: [{ method: 'cash', amount: 115000, received: 120000, change: 5000 }] },
  takeaway: { uuid: '00000000-0000-4000-8000-000000000013', type: 'takeaway', number: '13', comment: '', customer_name: '', phone: '', address: '', delivery_fee: 0, closed_at: now, payments: [{ method: 'terminal', amount: 115000 }] },
  delivery: { uuid: '00000000-0000-4000-8000-000000000014', type: 'delivery', number: '14', comment: 'Qo\'ng\'iroq qiling', customer_name: 'Ali', phone: '+998 90 123 45 67', address: 'Andijon sh., Navoiy ko\'chasi 12, 5-uy', delivery_fee: 10000, closed_at: now, payments: [{ method: 'cash', amount: 125000, received: 130000, change: 5000 }] },
};

function ctx() {
  return { brand: D.brand.name, address: D.brand.address, phone: D.brand.phone, tables: [{ id: 1, name: 'Stol 1' }], cashier: 'Kassir', design, logo, base: new URL('../', location.href).href };
}

// ---------- Jonli ko'rinish ----------
function renderPreview() {
  const o = { ...SAMPLES[sample], items: ITEMS };
  const doc = renderReceipt(tab, o, ITEMS, ctx());
  const box = $('#rc-preview');
  box.innerHTML = previewHtml(doc, design.paper);
  paintLogos(box);
  $('#rc-preview-title').textContent = tab === 'kitchen' ? 'Oshxona cheki' : 'Mijoz cheki';
  $$('[data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === tab));
  $$('[data-sample]').forEach(b => b.classList.toggle('on', b.dataset.sample === sample));
}

// ---------- Umumiy sozlamalar ----------
function bindGlobal() {
  const set = (id, fn) => { const el = $('#' + id); if (!el) return; const h = () => { fn(el); out(id); renderPreview(); }; el.addEventListener('input', h); el.addEventListener('change', h); };
  const out = (id) => { const o = $('#' + id + '-v'); if (o) o.textContent = $('#' + id).value + (id === 'g-line' || id === 'l-pct' || id === 'l-dark' ? '%' : id === 'g-margin' ? ' mm' : ''); };
  set('g-paper', el => { design.paper = +el.value === 58 ? 58 : 80; });
  set('g-font', el => { design.font = el.value === 'b' ? 'b' : 'a'; });
  set('g-line', el => { design.line_pct = +el.value; });
  set('g-margin', el => { design.margin_mm = +el.value; });
  set('g-bold', el => { design.bold_all = el.checked; });
  set('k-copies', el => { design.kitchen.copies = +el.value; });
  set('c-copies', el => { design.customer.copies = +el.value; });
  let t = null;
  const relogo = () => { clearTimeout(t); t = setTimeout(async () => {
    if (!D.has_logo) return;
    const r = await fetch(`?p=logo_raster&pct=${design.logo.pct}&dark=${design.logo.dark}`).then(x => x.json()).catch(() => null);
    if (r && r.ok) { logo = r.logo; renderPreview(); }
  }, 250); };
  set('l-pct', el => { design.logo.pct = +el.value; relogo(); });
  set('l-dark', el => { design.logo.dark = +el.value; relogo(); });
  ['g-line', 'g-margin', 'l-pct', 'l-dark'].forEach(out);
}

// ---------- Bloklar ro'yxati ----------
const ALIGN = { l: 'Chap', c: "O'rta", r: "O'ng" };
function blockRow(which, b, i, n) {
  const k = D.kinds[b.kind] || [b.kind];
  const removable = ['text', 'separator', 'feed'].includes(b.kind);
  let extra = '';
  if (b.kind === 'text') extra = `<input class="txt" data-f="text" value="${esc(b.text)}" placeholder="Matn yozing (bir nechta qator uchun ⏎ bilan ajrating)" maxlength="200">`;
  if (b.kind === 'qr') extra = `<input class="txt" data-f="text" value="${esc(b.text)}" placeholder="QR ostidagi yozuv" maxlength="200">`;
  if (b.kind === 'separator') extra = `<select data-f="ch">${['-', '=', '*', '_', '.'].map(c => `<option ${b.ch === c ? 'selected' : ''}>${c}</option>`).join('')}</select>`;
  if (b.kind === 'feed') extra = `<input type="number" data-f="n" min="1" max="5" value="${b.n || 1}" style="width:70px"> qator`;
  if (b.kind === 'customer') extra = ['name:Ism', 'phone:Telefon', 'address:Manzil'].map(x => { const [f, l] = x.split(':'); return `<label class="check"><input type="checkbox" data-o="${f}" ${b.o && b.o[f] !== false ? 'checked' : ''}> ${l}</label>`; }).join('');
  if (b.kind === 'items' && which === 'customer') extra = `<label class="check"><input type="checkbox" data-o="price" ${b.o && b.o.price ? 'checked' : ''}> Narxlari bilan</label>`;
  const noStyle = ['logo', 'separator', 'feed'].includes(b.kind);
  return `<div class="blk ${b.on ? '' : 'off'}" data-i="${i}">
    <div class="blk-h">
      <label class="check"><input type="checkbox" data-f="on" ${b.on ? 'checked' : ''}> <b>${esc(k[0])}</b></label>
      <span class="blk-btns"><button type="button" class="btn sm" data-mv="-1" ${i === 0 ? 'disabled' : ''} title="Yuqoriga">▲</button><button type="button" class="btn sm" data-mv="1" ${i === n - 1 ? 'disabled' : ''} title="Pastga">▼</button>
      ${removable ? '<button type="button" class="btn sm danger" data-rm title="Olib tashlash">🗑</button>' : ''}</span>
    </div>
    <div class="blk-b">
      ${b.kind === 'separator' || b.kind === 'feed' ? '' : `<select data-f="align" title="Tekislash">${Object.entries(ALIGN).map(([v, l]) => `<option value="${v}" ${b.align === v ? 'selected' : ''}>${l}</option>`).join('')}</select>`}
      ${noStyle ? '' : `<select data-f="size" title="O'lcham"><option value="1" ${b.size !== 2 ? 'selected' : ''}>Oddiy</option><option value="2" ${b.size === 2 ? 'selected' : ''}>Katta (2×)</option></select>
      <label class="check"><input type="checkbox" data-f="bold" ${b.bold ? 'checked' : ''}> Qalin</label>`}
      ${extra}
    </div></div>`;
}

function renderBlocks(which) {
  const box = $('#blocks-' + which);
  const list = design[which].blocks;
  box.innerHTML = list.map((b, i) => blockRow(which, b, i, list.length)).join('');
  const add = $('#add-' + which);
  if (add && !add.dataset.ready) {
    add.dataset.ready = 1;
    add.innerHTML = '<option value="">＋ Blok qo\'shish…</option>' + Object.entries(D.kinds).filter(([, v]) => v[1].includes(which === 'kitchen' ? 'k' : 'c')).map(([k, v]) => `<option value="${k}">${esc(v[0])}</option>`).join('');
    add.addEventListener('change', () => {
      if (!add.value) return;
      const kind = add.value; add.value = '';
      list.push({ id: 'x' + Math.random().toString(36).slice(2, 8), kind, on: true, align: ['shop', 'shop_info', 'text', 'qr'].includes(kind) ? 'c' : 'l', size: 1, bold: false, text: kind === 'text' ? 'Yangi matn' : kind === 'qr' ? 'Onlayn chek: QR-kodni skanerlang' : '', ch: '-', n: 1, o: { price: which === 'customer', name: true, phone: true, address: true } });
      renderBlocks(which); renderPreview();
    });
  }
}

function bindBlocks(which) {
  const box = $('#blocks-' + which);
  box.addEventListener('click', (e) => {
    const row = e.target.closest('.blk'); if (!row) return;
    const i = +row.dataset.i, list = design[which].blocks;
    const mv = e.target.closest('[data-mv]');
    if (mv) { const j = i + +mv.dataset.mv; if (j >= 0 && j < list.length) { [list[i], list[j]] = [list[j], list[i]]; renderBlocks(which); renderPreview(); } return; }
    if (e.target.closest('[data-rm]')) { list.splice(i, 1); renderBlocks(which); renderPreview(); }
  });
  const onchange = (e) => {
    const row = e.target.closest('.blk'); if (!row) return;
    const b = design[which].blocks[+row.dataset.i];
    const f = e.target.dataset.f, o = e.target.dataset.o;
    if (o) { b.o = b.o || {}; b.o[o] = e.target.checked; }
    else if (f === 'on' || f === 'bold') b[f] = e.target.checked;
    else if (f === 'size' || f === 'n') b[f] = +e.target.value;
    else if (f) b[f] = e.target.value;
    if (f === 'on') row.classList.toggle('off', !b.on);
    renderPreview();
  };
  box.addEventListener('change', onchange);
  box.addEventListener('input', (e) => { if (e.target.dataset.f === 'text' || e.target.dataset.f === 'n') onchange(e); });
}

// ---------- Printer xizmati bilan (faqat shu kompyuterda) ----------
const AGENT = 'http://127.0.0.1:9123';
let token = null;
async function agent(path, method = 'GET', body = null) {
  const h = { 'Content-Type': 'application/json' }; if (token) h['X-Agent-Token'] = token;
  const r = await fetch(AGENT + path, { method, headers: h, body: body ? JSON.stringify(body) : null, cache: 'no-store' });
  return r.json();
}
async function printDoc(doc, title) {
  try {
    if (!token) { const p = await agent('/pair', 'POST', {}); if (!p.ok) throw new Error(p.error || 'Ulash rad etildi'); token = p.token; }
    const id = crypto.randomUUID();
    const r = await agent('/print', 'POST', { id, kind: 'test', title, doc });
    if (!r.ok) throw new Error(r.error);
    alert(title + ' printer navbatiga qo\'yildi.');
  } catch (e) {
    alert("Printer xizmatiga ulanib bo'lmadi: " + (e.message || e) + "\n\nBu tugma faqat kassa monoblokida ishlaydi (printer xizmati shu kompyuterda o'rnatilgan bo'lishi kerak).");
  }
}

document.addEventListener('DOMContentLoaded', () => {});
bindGlobal();
['kitchen', 'customer'].forEach(w => { renderBlocks(w); bindBlocks(w); });
$$('[data-tab]').forEach(b => b.addEventListener('click', () => { tab = b.dataset.tab; renderPreview(); }));
$$('[data-sample]').forEach(b => b.addEventListener('click', () => { sample = b.dataset.sample; renderPreview(); }));
$('#rc-form').addEventListener('submit', () => { $('#rc-design').value = JSON.stringify(design); });
// Logo yuklash/olib tashlash sahifani qayta yuklaydi: saqlanmagan o'zgarishlar yo'qolmasligi uchun dizayn ham birga yuboriladi
$$('form[data-keepdesign]').forEach(f => f.addEventListener('submit', () => {
  let i = f.querySelector('input[name=design]');
  if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = 'design'; f.append(i); }
  i.value = JSON.stringify(design);
}));
$('#rc-print').addEventListener('click', () => printDoc(renderReceipt(tab, { ...SAMPLES[sample], items: ITEMS }, ITEMS, ctx()), 'Namuna chek'));
$('#rc-ruler').addEventListener('click', () => printDoc(rulerDoc(ctx()), "O'lchov cheki"));
renderPreview();
