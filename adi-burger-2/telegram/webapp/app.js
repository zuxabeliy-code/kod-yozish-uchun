// ADI BURGER — Telegram WebApp (mijoz uchun menyu va buyurtma)
(function () {
  'use strict';
  var TG = window.Telegram && window.Telegram.WebApp;
  var initData = TG ? TG.initData : '';
  var app = document.getElementById('app');
  var D = null; // init ma'lumoti
  var cart = {}; // {productId: qty}
  var view = 'menu', cat = 0, form = {};
  var pollTimer = null;
  try { cart = JSON.parse(localStorage.getItem('adi_cart') || '{}') || {}; } catch (e) { cart = {}; }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n) { return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function som(n) { return fmt(n) + " so'm"; }
  function note(t) { t = String(t || '').trim(); return t ? '<div class="note">' + esc(t).replace(/\n/g, '<br>') + '</div>' : ''; }
  function saveCart() { try { localStorage.setItem('adi_cart', JSON.stringify(cart)); } catch (e) {} }
  function toast(m) { var t = document.getElementById('toast'); t.innerHTML = '<div>' + esc(m) + '</div>'; setTimeout(function () { t.innerHTML = ''; }, 2600); }
  function haptic(t) { try { TG.HapticFeedback.impactOccurred(t || 'light'); } catch (e) {} }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
  }

  function api(action, body) {
    body = body || {};
    body.action = action; body.initData = initData;
    return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server javobi noto\'g\'ri' }; }); })
      .catch(function () { return { ok: false, error: "Internet bilan aloqa yo'q" }; });
  }

  function prod(id) { for (var i = 0; i < D.products.length; i++) if (D.products[i].id === +id) return D.products[i]; return null; }
  function cartItems() { var out = []; Object.keys(cart).forEach(function (id) { var p = prod(id); if (p && p.ok && cart[id] > 0) out.push({ p: p, q: cart[id] }); else delete cart[id]; }); return out; }
  function cartSum() { return cartItems().reduce(function (s, x) { return s + x.p.price * x.q; }, 0); }
  function cartCount() { return cartItems().reduce(function (s, x) { return s + x.q; }, 0); }

  function back(fn) {
    if (!TG) return;
    TG.BackButton.offClick(window.__back || function () {});
    window.__back = fn;
    if (fn) { TG.BackButton.onClick(fn); TG.BackButton.show(); } else TG.BackButton.hide();
  }

  // ---------------- Menyu ----------------
  function renderMenu() {
    view = 'menu'; back(null); stopPoll();
    if (!D.enabled) { app.innerHTML = '<div class="closed"><div style="font-size:48px">⏸</div><h2>Buyurtma vaqtincha qabul qilinmayapti</h2><p>' + esc(D.contact) + '</p></div>'; return; }
    var cats = D.categories;
    var html = '<div class="wrap"><div class="hero"><div><h1>' + esc(D.brand) + '</h1><div class="sub">' + (D.open ? '🟢 Ochiq · ' : '🔴 Yopiq · ') + esc(D.work) + '</div></div><button class="link" data-go="orders">Buyurtmalarim</button></div>';
    if (!D.open) html += '<div class="err">Hozir ish vaqti emas. Menyuni ko\'rishingiz mumkin, buyurtma ish vaqtida qabul qilinadi.</div>';
    html += '<div class="cats"><button class="cat ' + (cat === 0 ? 'on' : '') + '" data-cat="0">Hammasi</button>' + cats.map(function (c) { return '<button class="cat ' + (cat === c.id ? 'on' : '') + '" data-cat="' + c.id + '">' + esc(c.icon) + ' ' + esc(c.name) + '</button>'; }).join('') + '</div>';
    cats.forEach(function (c) {
      if (cat && cat !== c.id) return;
      var ps = D.products.filter(function (p) { return p.cat === c.id; });
      if (!ps.length) return;
      html += '<div class="sec">' + esc(c.icon) + ' ' + esc(c.name) + '</div><div class="grid">' + ps.map(function (p) {
        var q = cart[p.id] || 0;
        return '<div class="card ' + (p.ok ? '' : 'off') + '"><div class="img" ' + (p.img ? 'style="background-image:url(\'' + esc(p.img) + '\')"' : '') + '>' + (p.img ? '' : esc(c.icon)) + '</div>' +
          '<div class="b"><div class="nm">' + esc(p.name) + '</div>' + (p.desc ? '<div class="ds">' + esc(p.desc) + '</div>' : '') +
          '<div class="row"><span class="pr">' + fmt(p.price) + '</span>' + (!p.ok ? '<span class="ds">Tugagan</span>' : q ? '<span class="stepper"><button data-dec="' + p.id + '">−</button><b>' + q + '</b><button data-inc="' + p.id + '">+</button></span>' : '<button class="add" data-inc="' + p.id + '">+</button>') + '</div></div></div>';
      }).join('') + '</div>';
    });
    html += '</div>';
    var n = cartCount();
    html += '<div class="bar"><button data-go="cart" ' + (n ? '' : 'disabled') + '><span>🛒 Savatcha · ' + n + ' ta</span><span>' + som(cartSum()) + '</span></button></div>';
    app.innerHTML = html;
  }

  // ---------------- Savatcha / rasmiylashtirish ----------------
  function renderCart() {
    view = 'cart'; back(renderMenu);
    var items = cartItems();
    if (!items.length) return renderMenu();
    if (!form.type) form.type = D.types[0];
    if (!form.pay && D.pays.length) form.pay = D.pays[0].id;
    if (form.phone == null) form.phone = D.customer.phone || '';
    if (form.name == null) form.name = D.customer.name || '';
    if (form.address == null) form.address = D.customer.address || '';
    var fee = form.type === 'delivery' ? D.delivery_fee : 0;
    var sum = cartSum();
    var html = '<div class="wrap"><div class="hero"><h1>Savatcha</h1><button class="link" data-clear>Tozalash</button></div><div class="box">' +
      items.map(function (x) { return '<div class="line"><span class="n">' + esc(x.p.name) + '<br><small style="color:var(--hint)">' + fmt(x.p.price) + '</small></span><span class="stepper"><button data-dec="' + x.p.id + '">−</button><b>' + x.q + '</b><button data-inc="' + x.p.id + '">+</button></span><span class="s">' + fmt(x.p.price * x.q) + '</span></div>'; }).join('') + '</div>';
    html += '<div class="box"><h3>Qanday olasiz?</h3><div class="seg">' + D.types.map(function (t) { return '<button class="opt ' + (form.type === t ? 'on' : '') + '" data-type="' + t + '">' + (t === 'delivery' ? '🚚 Yetkazib berish<small>' + (D.delivery_fee ? som(D.delivery_fee) : 'Bepul') + '</small>' : '🛍 Olib ketish<small>O\'zim boraman</small>') + '</button>'; }).join('') + '</div>' +
      (note(form.type === 'delivery' ? D.notes.delivery : D.notes.pickup)) +
      '<label class="f">Telefon raqam</label><input class="inp" id="f-phone" inputmode="tel" placeholder="+998 90 123 45 67" value="' + esc(form.phone) + '">' +
      '<label class="f">Ismingiz</label><input class="inp" id="f-name" value="' + esc(form.name) + '">' +
      (form.type === 'delivery' ? '<label class="f">Yetkazish manzili</label><textarea class="inp" id="f-addr" placeholder="Mahalla, ko\'cha, uy, mo\'ljal">' + esc(form.address) + '</textarea>' : '') +
      '<label class="f">Izoh (ixtiyoriy)</label><input class="inp" id="f-cmt" value="' + esc(form.comment || '') + '" placeholder="Masalan: piyozsiz"></div>';
    html += '<div class="box"><h3>To\'lov usuli</h3><div class="pays">' + (D.pays.length ? D.pays.map(function (p) { return '<button class="opt ' + (form.pay === p.id ? 'on' : '') + '" data-pay="' + p.id + '">' + esc(p.name) + '<small>' + esc(p.hint) + '</small></button>'; }).join('') : '<div class="err">To\'lov usullari sozlanmagan</div>') + note(D.notes.pay) + '</div></div>';
    html += '<div class="box"><div class="sum"><span>Taomlar</span><span>' + som(sum) + '</span></div>' + (fee ? '<div class="sum"><span>Yetkazish</span><span>' + som(fee) + '</span></div>' : '') + '<div class="sum t"><span>Jami</span><span>' + som(sum + fee) + '</span></div></div>';
    html += '<div id="err"></div></div><div class="bar"><button data-order ' + (D.open ? '' : 'disabled') + '><span>' + (D.open ? '✅ Buyurtma berish' : 'Hozir yopiq') + '</span><span>' + som(sum + fee) + '</span></button></div>';
    app.innerHTML = html;
  }

  function readForm() {
    var g = function (id) { var e = document.getElementById(id); return e ? e.value : null; };
    if (g('f-phone') !== null) form.phone = g('f-phone');
    if (g('f-name') !== null) form.name = g('f-name');
    if (g('f-addr') !== null) form.address = g('f-addr');
    if (g('f-cmt') !== null) form.comment = g('f-cmt');
  }

  var sending = false;
  function placeOrder() {
    if (sending) return;
    readForm();
    var digits = (form.phone || '').replace(/\D/g, '');
    var errBox = document.getElementById('err');
    var err = function (m) { errBox.innerHTML = '<div class="err">' + esc(m) + '</div>'; errBox.scrollIntoView({ behavior: 'smooth' }); };
    if (!(digits.length === 9 || (digits.length === 12 && digits.indexOf('998') === 0))) return err("Telefon raqamini to'g'ri kiriting: +998 XX XXX XX XX");
    if (form.type === 'delivery' && (form.address || '').trim().length < 5) return err('Yetkazish manzilini to\'liq yozing');
    if (!form.pay) return err("To'lov usulini tanlang");
    sending = true;
    form.uuid = form.uuid || uuid(); // tugma ikki marta bosilsa ham bitta buyurtma
    var btn = document.querySelector('[data-order]'); if (btn) btn.disabled = true;
    api('order.create', { uuid: form.uuid, type: form.type, phone: form.phone, name: form.name, address: form.address, comment: form.comment, pay: form.pay,
      items: cartItems().map(function (x) { return { id: x.p.id, qty: x.q }; }) }).then(function (r) {
      sending = false;
      if (!r.ok) { if (btn) btn.disabled = false; return err(r.error); }
      haptic('medium');
      cart = {}; saveCart();
      var o = r.order; form = {};
      if (o.pay_method === 'hamyon') { api('hamyon.start', { uuid: o.uuid }).then(function (h) { renderOrder(h.ok ? h.order : o, h.ok ? '' : h.error); }); }
      else renderOrder(o);
    });
  }

  // ---------------- Buyurtma holati va to'lov ----------------
  var ICON = { pending_pay: '💳', new: '⏳', accepted: '👨‍🍳', ready: '✅', done: '🎉', rejected: '❌', cancelled: '❌' };
  function renderOrder(o, errMsg) {
    view = 'order'; back(renderMenu);
    var html = '<div class="wrap"><div class="status"><div class="ic">' + (ICON[o.status] || '📦') + '</div><h2>Buyurtma ' + esc(o.number) + '</h2><span class="pill">' + esc(o.status_label) + '</span></div>';
    if (errMsg) html += '<div class="err">' + esc(errMsg) + '</div>';
    if (o.status === 'rejected' && o.reject_reason) html += '<div class="err">Sabab: ' + esc(o.reject_reason) + '</div>';
    // Kartaga o'tkazma
    if (o.pay_method === 'card' && o.pay_status !== 'paid' && ['rejected', 'cancelled', 'done'].indexOf(o.status) < 0 && o.cards) {
      var r = o.receipt;
      html += '<div class="box"><h3>💳 Kartaga o\'tkazma</h3><div class="sum t"><span>To\'lanadigan summa</span><span class="big">' + som(o.total) + '</span></div>' +
        o.cards.map(function (c) { return '<div class="line"><span class="n"><span class="cardnum">' + esc(c.number) + '</span><br><small style="color:var(--hint)">' + esc(c.holder || '') + (c.bank ? ' · ' + esc(c.bank) : '') + '</small></span><button class="copy" data-copy="' + esc(String(c.number).replace(/\s/g, '')) + '">Nusxa</button></div>'; }).join('') +
        note(D.notes.pay) + '<p style="color:var(--hint);font-size:13px">1) Summani shu kartaga o\'tkazing. 2) To\'lov chekining skrinshotini pastdan yuboring. Kassir tekshirgach buyurtma tayyorlanadi.</p>' +
        (r && r.status === 'checking' ? '<div class="ok">🧾 Chek yuborildi — kassir tekshirmoqda.</div>' : '') +
        (r && r.status === 'rejected' ? '<div class="err">Chek tasdiqlanmadi' + (r.note ? ': ' + esc(r.note) : '') + '. Qayta yuboring.</div>' : '') +
        '<label class="upl" for="rfile">📎 ' + (r ? 'Boshqa chek yuborish' : 'To\'lov chekini yuborish') + '</label><input type="file" id="rfile" accept="image/*" hidden></div>';
    }
    // Hamyon
    if (o.pay_method === 'hamyon' && o.status === 'pending_pay') {
      if (o.hamyon) {
        html += '<div class="box"><h3>💳 Hamyon orqali to\'lov</h3><p style="color:var(--hint);font-size:13px;margin-top:0">Quyidagi kartaga <b>aynan shu summani</b> o\'tkazing. To\'lov avtomatik tasdiqlanadi.</p>' +
          '<div class="line"><span class="n"><span class="cardnum">' + esc(o.hamyon.card) + '</span></span><button class="copy" data-copy="' + esc(String(o.hamyon.card).replace(/\s/g, '')) + '">Nusxa</button></div>' +
          '<div class="line"><span class="n"><span class="big">' + fmt(o.hamyon.amount) + '</span> so\'m</span><button class="copy" data-copy="' + o.hamyon.amount + '">Nusxa</button></div>' +
          '<p style="color:var(--hint)">⏱ Qolgan vaqt: <b id="tmr">' + Math.floor(o.hamyon.expires_in / 60) + ':' + String(o.hamyon.expires_in % 60).padStart(2, '0') + '</b></p></div>';
      } else {
        html += '<div class="box"><h3>💳 Hamyon</h3><p>To\'lov hali yaratilmagan.</p><button class="btn1" data-hstart="' + o.uuid + '">To\'lovni boshlash</button></div>';
      }
      html += '<button class="btn2" data-cancel="' + o.uuid + '">Buyurtmani bekor qilish</button>';
    }
    if (o.status === 'pending_pay' && o.pay_method === 'card') html += '<button class="btn2" data-cancel="' + o.uuid + '">Buyurtmani bekor qilish</button>';
    if (o.can_cancel && o.status === 'new') html += '<button class="btn2" data-cancel="' + o.uuid + '">Buyurtmani bekor qilish</button>';
    html += '<div class="box"><h3>Tarkibi</h3>' + o.items.map(function (i) { return '<div class="line"><span class="n">' + esc(i.name) + ' × ' + i.qty + '</span><span class="s">' + fmt(i.price * i.qty) + '</span></div>'; }).join('') +
      (o.delivery_fee ? '<div class="line"><span class="n">Yetkazish</span><span class="s">' + fmt(o.delivery_fee) + '</span></div>' : '') +
      '<div class="sum t"><span>Jami</span><span>' + som(o.total) + '</span></div>' + (o.address ? '<p style="color:var(--hint)">📍 ' + esc(o.address) + '</p>' : '') + '</div>';
    html += '<button class="btn2" data-go="menu">Menyuga qaytish</button></div>';
    app.innerHTML = html;
    var f = document.getElementById('rfile');
    if (f) f.onchange = function () { upload(o, f.files[0]); };
    stopPoll();
    if (['pending_pay', 'new', 'accepted', 'ready'].indexOf(o.status) >= 0) {
      var left = o.hamyon ? o.hamyon.expires_in : 0;
      pollTimer = setInterval(function () {
        if (left > 0) { left--; var t = document.getElementById('tmr'); if (t) t.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0'); }
      }, 1000);
      window.__poll = setInterval(function () {
        api('order.get', { uuid: o.uuid }).then(function (r) {
          if (r.ok && view === 'order' && (r.order.status !== o.status || r.order.pay_status !== o.pay_status || JSON.stringify(r.order.receipt || null) !== JSON.stringify(o.receipt || null))) { haptic('medium'); renderOrder(r.order); }
        });
      }, 5000);
    }
  }
  function stopPoll() { clearInterval(pollTimer); clearInterval(window.__poll); }

  function upload(o, file) {
    if (!file) return;
    if (file.size > 8 * 1024 * 1024) return toast('Rasm juda katta (8 MB gacha)');
    var fd = new FormData();
    fd.append('action', 'receipt.upload'); fd.append('initData', initData); fd.append('uuid', o.uuid); fd.append('file', file);
    toast('Yuborilmoqda…');
    fetch('api.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (r) {
      if (!r.ok) return toast(r.error || 'Xato');
      haptic('medium'); toast('Chek yuborildi ✅'); renderOrder(r.order);
    }).catch(function () { toast("Internet bilan aloqa yo'q"); });
  }

  // ---------------- Buyurtmalarim ----------------
  function renderOrders() {
    view = 'orders'; back(renderMenu); stopPoll();
    app.innerHTML = '<div class="loading"><div class="spin"></div></div>';
    api('orders').then(function (r) {
      if (!r.ok) { app.innerHTML = '<div class="wrap"><div class="err">' + esc(r.error) + '</div></div>'; return; }
      app.innerHTML = '<div class="wrap"><div class="hero"><h1>Buyurtmalarim</h1></div>' + (r.orders.length ? r.orders.map(function (o) {
        return '<button class="box" style="width:100%;text-align:left" data-order-open="' + o.uuid + '"><div style="display:flex;justify-content:space-between"><b>' + esc(o.number) + '</b><span class="pill">' + esc(o.status_label) + '</span></div><div style="color:var(--hint);font-size:13px;margin-top:4px">' + esc(o.created_at) + ' · ' + som(o.total) + '</div></button>';
      }).join('') : '<div class="closed">Hali buyurtma yo\'q</div>') + '<button class="btn2" data-go="menu">Menyuga qaytish</button></div>';
    });
  }

  // ---------------- Hodisalar ----------------
  app.addEventListener('click', function (e) {
    var t = e.target.closest('button,label'); if (!t) return;
    var d = t.dataset;
    if (d.cat !== undefined) { cat = +d.cat; renderMenu(); return; }
    if (d.inc) { cart[d.inc] = (cart[d.inc] || 0) + 1; saveCart(); haptic(); view === 'cart' ? (readForm(), renderCart()) : renderMenu(); return; }
    if (d.dec) { cart[d.dec] = Math.max(0, (cart[d.dec] || 0) - 1); if (!cart[d.dec]) delete cart[d.dec]; saveCart(); haptic(); view === 'cart' ? (readForm(), renderCart()) : renderMenu(); return; }
    if (d.go === 'cart') return renderCart();
    if (d.go === 'menu') return renderMenu();
    if (d.go === 'orders') return renderOrders();
    if (d.clear !== undefined) { cart = {}; saveCart(); return renderMenu(); }
    if (d.type) { readForm(); form.type = d.type; return renderCart(); }
    if (d.pay) { readForm(); form.pay = d.pay; return renderCart(); }
    if (d.order !== undefined) return placeOrder();
    if (d.copy) { (navigator.clipboard ? navigator.clipboard.writeText(d.copy) : Promise.reject()).then(function () { toast('Nusxa olindi'); }, function () { toast(d.copy); }); return; }
    if (d.orderOpen) { api('order.get', { uuid: d.orderOpen }).then(function (r) { r.ok ? renderOrder(r.order) : toast(r.error); }); return; }
    if (d.hstart) { api('hamyon.start', { uuid: d.hstart }).then(function (r) { r.ok ? renderOrder(r.order) : toast(r.error); }); return; }
    if (d.cancel) {
      var go = function () { api('order.cancel', { uuid: d.cancel }).then(function (r) { if (r.ok) { toast('Bekor qilindi'); renderOrders(); } else toast(r.error); }); };
      if (TG && TG.showConfirm) TG.showConfirm('Buyurtma bekor qilinsinmi?', function (ok) { if (ok) go(); }); else if (confirm('Buyurtma bekor qilinsinmi?')) go();
    }
  });

  // ---------------- Start ----------------
  if (TG) { TG.ready(); TG.expand(); try { TG.setHeaderColor('secondary_bg_color'); } catch (e) {} }
  if (!initData) { app.innerHTML = '<div class="closed"><div style="font-size:48px">🤖</div><h2>Bu sahifa Telegram bot ichida ochiladi</h2><p>Botga kiring, «🍔 Buyurtma berish» tugmasini bosing, so\'ng chiqqan <b>«Menyuni ochish»</b> tugmasini bosing. Havolani brauzerda ochmang.</p></div>'; return; }
  api('init').then(function (r) {
    if (!r.ok) { app.innerHTML = '<div class="wrap"><div class="err">' + esc(r.error) + '</div><button class="btn1" onclick="location.reload()">Qayta urinish</button></div>'; return; }
    D = r;
    renderMenu();
  });
})();
