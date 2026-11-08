(function () {
  'use strict';
  var body = document.body, dr = document.getElementById('drawer'), bg = document.getElementById('drawer-bg'), burger = document.getElementById('burger');
  var wide = function () { return window.matchMedia('(min-width: 1200px)').matches; };

  // ☰ tugmasi: yon menyuni ochadi/yopadi
  if (burger) burger.addEventListener('click', function () {
    if (wide()) { body.classList.toggle('dr-closed'); try { localStorage.setItem('adi_dr', body.classList.contains('dr-closed') ? '1' : '0'); } catch (e) {} }
    else { dr.classList.toggle('open'); bg.classList.toggle('open'); }
  });
  if (bg) bg.addEventListener('click', function () { dr.classList.remove('open'); bg.classList.remove('open'); });
  try { if (localStorage.getItem('adi_dr') === '1') body.classList.add('dr-closed'); } catch (e) {}

  // Tasdiq talab qiladigan amallar
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute('data-confirm');
    if (m && !confirm(m)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a,button,form')) location.href = tr.getAttribute('data-href');
  });

  // Xodim formasi: admin login maydonlari faqat administrator uchun
  var role = document.querySelector('select[name=role]'), ao = document.querySelector('.admin-only');
  if (role && ao) { var f = function () { ao.style.display = role.value === 'admin' ? '' : 'none'; }; role.addEventListener('change', f); f(); }

  // Shu kompyuterdagi printer xizmati (faqat monoblokda ochilganda ishlaydi)
  var box = document.getElementById('local-agent');
  var token = null;
  function agent(path, method) {
    var h = {}; if (token) h['X-Agent-Token'] = token;
    return fetch('http://127.0.0.1:9123' + path, { method: method || 'GET', headers: h, cache: 'no-store' }).then(function (r) { return r.json(); });
  }
  function pair() { return agent('/pair', 'POST').then(function (r) { if (r.ok) token = r.token; return r; }); }
  if (box) {
    pair().then(function (r) {
      if (!r.ok) { box.className = 'note bad'; box.textContent = 'Printer xizmati bu saytga ruxsat bermadi: ' + (r.error || ''); return; }
      return agent('/status').then(function (s) {
        var p = s.printer || {};
        box.className = 'note ' + (p.ok ? 'ok' : 'bad');
        box.innerHTML = '<b>Printer xizmati v' + s.version + ' ishlayapti.</b> ' + (p.configured ? (p.ok ? 'Printer ulangan: ' + p.target : 'Printer xatosi: ' + (p.error || '')) : 'Printer sozlanmagan (kassada ⚙ → Printer).')
          + '<br>Navbat: kutilmoqda ' + (s.queue.pending || 0) + ', xato ' + (s.queue.error || 0) + ", noma'lum " + (s.queue.unknown || 0);
      });
    }).catch(function () {
      box.className = 'note warn';
      box.textContent = "Bu kompyuterda printer xizmati topilmadi. Bu normal holat, agar sahifa monoblokdan boshqa qurilmada ochilgan bo'lsa. Kassa monoblokida tekshiring.";
    });
  }
  var tb = document.getElementById('agent-test');
  if (tb) tb.addEventListener('click', function () {
    tb.disabled = true;
    (token ? Promise.resolve() : pair()).then(function () { return agent('/test', 'POST'); }).then(function (r) {
      alert(r.ok ? 'Test chek printer navbatiga qo\'yildi.' : 'Xato: ' + r.error);
    }).catch(function () { alert("Printer xizmati bu kompyuterda topilmadi. Test chekni kassa monoblokidan chiqaring."); }).then(function () { tb.disabled = false; });
  });
})();
