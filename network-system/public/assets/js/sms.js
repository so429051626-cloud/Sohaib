/* الرسائل النصية: عدّاد المقاطع، المتغيرات، عدد المستلمين، الجدولة، الرصيد */
(function (document) {
  'use strict';
  var SN = window.SN;

  // نفس خوارزمية التنظيف في الخادم: 972 + 9 خانات تبدأ بـ 5
  function normalize(raw) {
    var s = String(raw).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
      .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/\D+/g, '');
    if (s.indexOf('00') === 0) { s = s.slice(2); }
    if (s.indexOf('970') === 0 || s.indexOf('972') === 0) { s = s.slice(3); }
    s = s.replace(/^0+/, '');
    return /^5\d{8}$/.test(s) ? '972' + s : null;
  }
  function segments(text) {
    if (!text.length) { return 0; }
    return Math.ceil(text.length / (/[^\x00-\x7F]/.test(text) ? 70 : 160));
  }

  // بطاقة الرصيد (صفحة الإرسال والإعدادات)
  var card = document.querySelector('[data-balance-card][data-status-url]');
  if (card) {
    SN.api(card.getAttribute('data-status-url'), { method: 'POST', silent: true }).then(function (r) {
      card.querySelector('[data-balance]').textContent = r.balance !== null && r.balance !== undefined ? SN.fmtNum(r.balance) : (r.balance_text || '—');
      var dl = document.querySelector('[data-sender-list]');
      if (dl && r.senders) { dl.innerHTML = r.senders.map(function (x) { return '<option value="' + SN.esc(x) + '">'; }).join(''); }
    }).catch(function (e) {
      card.querySelector('[data-balance]').textContent = '—';
      card.classList.add('is-error');
      card.setAttribute('title', e.message || '');
    });
  }

  var form = document.querySelector('[data-sms-form]');
  if (!form) { return; }
  var body = form.querySelector('[data-body]');
  var numbers = form.querySelector('[data-numbers]');
  var total = 0;
  var timer = null;

  function meter() {
    var text = body.value;
    var seg = segments(text);
    form.querySelector('[data-chars]').textContent = SN.fmtNum(text.length);
    form.querySelector('[data-segments]').textContent = SN.fmtNum(seg);
    form.querySelector('[data-units]').textContent = SN.fmtNum(seg * total);
    var pv = form.querySelector('[data-preview]');
    pv.hidden = text.trim() === '';
    form.querySelector('[data-preview-text]').textContent = text
      .replace(/\{الاسم\}/g, 'أحمد خالد').replace(/\{اسم_المستخدم\}/g, 'ahmad').replace(/\{الباقة\}/g, 'باقة 20M')
      .replace(/\{تاريخ_الانتهاء\}/g, '2026-12-01').replace(/\{الأيام_المتبقية\}/g, '3').replace(/\{المستحق\}/g, '50 ₪')
      .replace(/\{الشبكة\}/g, document.title.split('·').pop().trim());
  }

  function numbersState() {
    var parts = numbers.value.split(/[\s,،;]+/).filter(Boolean);
    var ok = {}, bad = 0;
    parts.forEach(function (p) { var n = normalize(p); if (n) { ok[n] = 1; } else { bad++; } });
    var box = form.querySelector('[data-numbers-state]');
    var okCount = Object.keys(ok).length;
    box.innerHTML = '';
    if (okCount) { box.insertAdjacentHTML('beforeend', '<span class="badge badge-success">' + SN.fmtNum(okCount) + ' صالح</span>'); }
    if (bad) { box.insertAdjacentHTML('beforeend', '<span class="badge badge-danger">' + SN.fmtNum(bad) + ' غير صالح</span>'); }
  }

  function count() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var fd = new FormData(form);
      var q = new URLSearchParams({ state: fd.get('state') || 'all', plan: fd.get('plan') || '', area: fd.get('area') || '', router: fd.get('router') || '', numbers: fd.get('numbers') || '' });
      fd.getAll('pick[]').forEach(function (v) { q.append('pick[]', v); });
      fetch(form.getAttribute('data-count') + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          total = d.total || 0;
          form.querySelector('[data-total]').innerHTML = SN.icon('users') + '<b>' + SN.fmtNum(total) + '</b>';
          meter();
        }).catch(function () {});
    }, 250);
  }

  function syncFilters() {
    var st = (form.querySelector('[name=state]:checked') || {}).value;
    form.querySelector('[data-sub-filters]').hidden = st === 'none' || st === 'pick';
    var pickBox = form.querySelector('[data-pick-box]');
    if (pickBox) { pickBox.hidden = st !== 'pick'; }
  }

  form.querySelector('[data-tokens]').addEventListener('click', function (e) {
    var b = e.target.closest('[data-token]');
    if (!b) { return; }
    var tok = b.getAttribute('data-token');
    var s = body.selectionStart || body.value.length, en = body.selectionEnd || s;
    body.value = body.value.slice(0, s) + tok + body.value.slice(en);
    body.focus();
    body.selectionStart = body.selectionEnd = s + tok.length;
    meter();
  });
  var tpl = form.querySelector('[data-template]');
  if (tpl) { tpl.addEventListener('change', function () { if (tpl.value) { body.value = tpl.value; meter(); } tpl.value = ''; }); }

  var sched = form.querySelector('[data-schedule]');
  var schedSync = function () { form.querySelectorAll('[data-schedule-field]').forEach(function (f) { f.hidden = !sched.checked; }); };
  sched.addEventListener('change', schedSync);

  body.addEventListener('input', meter);
  numbers.addEventListener('input', function () { numbersState(); count(); });
  form.addEventListener('picker:change', count);
  form.addEventListener('change', function (e) { if (/^(state|plan|area|router)$/.test(e.target.name)) { syncFilters(); count(); } });

  var confirmed = false;
  form.addEventListener('submit', function (e) {
    if (confirmed) { return; }
    e.preventDefault();
    if (!body.value.trim()) { body.focus(); return; }
    if (!total) { SN.toast('error', 'لا يوجد مستلمون.'); return; }
    var units = segments(body.value) * total;
    SN.confirm('إرسال الرسالة إلى ' + SN.fmtNum(total) + ' مستلم (' + SN.fmtNum(units) + ' رسالة تقريباً)؟', { okText: 'إرسال', danger: false }).then(function (ok) {
      if (!ok) { return; }
      confirmed = true;
      var btn = form.querySelector('[data-send]');
      btn.classList.add('is-loading');
      btn.disabled = true;
      form.submit();
    });
  });

  syncFilters();
  schedSync();
  numbersState();
  meter();
  count();
})(document);
