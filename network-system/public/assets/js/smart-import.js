/*
 * الاستيراد الذكي للمشتركين: النص أو صور الدفتر → جدول قابل للتعديل → الحفظ بعد الموافقة.
 * الصور تُصغَّر في المتصفح (1800 بكسل) قبل رفعها، فتصل أسرع وتُقرأ أوضح.
 */
(function (window, document) {
  'use strict';
  var box = document.querySelector('[data-si]');
  if (!box || !window.SN) { return; }
  var FIELDS = ['name', 'phone', 'username', 'password', 'plan', 'expires', 'address'];
  var plans = {};
  try { plans = JSON.parse(box.getAttribute('data-plans') || '{}') || {}; } catch (e) { plans = {}; }
  var $ = function (sel) { return box.querySelector(sel); };
  var input = $('[data-si-input]'), review = $('[data-si-review]'), tbody = $('[data-si-rows]');
  var status = $('[data-si-status]'), msg = $('[data-si-msg]');
  var files = $('[data-si-files]'), thumbs = $('[data-si-thumbs]');
  var images = [];
  var notes = []; // الملاحظات لا تُعرض في الجدول وتُحفظ كما وردت

  function say(el, ok, text) { el.className = (el === status ? 'slug-status ' : 'panel-body si-msg ') + (ok ? 'is-ok' : 'is-bad'); el.textContent = text || ''; el.hidden = !text; }

  // ---------------- الصور ----------------
  function shrink(file) {
    return new Promise(function (resolve) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var max = 1800, s = Math.min(1, max / Math.max(img.width, img.height));
        var c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.86);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function drawThumbs() {
    thumbs.innerHTML = '';
    images.forEach(function (b, i) {
      var t = document.createElement('div');
      t.className = 'si-thumb';
      var im = document.createElement('img');
      im.src = URL.createObjectURL(b); im.alt = '';
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'si-thumb-x'; x.setAttribute('aria-label', 'إزالة الصورة');
      x.innerHTML = SN.icon('x');
      x.addEventListener('click', function () { images.splice(i, 1); drawThumbs(); });
      t.appendChild(im); t.appendChild(x); thumbs.appendChild(t);
    });
  }
  if (files) {
    files.addEventListener('change', function () {
      var list = Array.prototype.slice.call(files.files || []);
      files.value = '';
      if (images.length + list.length > 3) { say(status, false, 'ثلاث صور على الأكثر.'); list = list.slice(0, 3 - images.length); }
      Promise.all(list.map(shrink)).then(function (bs) { images = images.concat(bs); drawThumbs(); });
    });
  }

  // ---------------- الجدول ----------------
  function planSelect(value) {
    var s = document.createElement('select');
    s.className = 'input'; s.setAttribute('data-f', 'plan');
    var o = document.createElement('option'); o.value = ''; o.textContent = 'الافتراضية'; s.appendChild(o);
    var hit = false;
    Object.keys(plans).forEach(function (id) {
      var op = document.createElement('option');
      op.value = plans[id]; op.textContent = plans[id];
      if (value && (plans[id] === value || value.indexOf(plans[id]) !== -1 || plans[id].indexOf(value) !== -1) && !hit) { op.selected = true; hit = true; }
      s.appendChild(op);
    });
    if (value && !hit) {
      var x = document.createElement('option'); x.value = value; x.textContent = value + ' (غير معروفة)'; x.selected = true; s.appendChild(x);
    }
    return s;
  }
  function addRow(r) {
    r = r || {};
    var tr = document.createElement('tr');
    var n = document.createElement('td'); n.className = 'si-n'; tr.appendChild(n);
    FIELDS.forEach(function (f) {
      var td = document.createElement('td');
      td.setAttribute('data-label', { name: 'الاسم', phone: 'الهاتف', username: 'اسم الدخول', password: 'كلمة المرور', plan: 'الباقة', expires: 'ينتهي في', address: 'العنوان' }[f]);
      var el;
      if (f === 'plan') { el = planSelect(r.plan || ''); }
      else {
        el = document.createElement('input');
        el.className = 'input' + (f === 'name' || f === 'address' ? '' : ' input-ltr');
        el.setAttribute('data-f', f);
        el.type = f === 'expires' ? 'date' : 'text';
        el.value = r[f] || '';
        if (f === 'phone') { el.inputMode = 'tel'; }
        if (f === 'username' || f === 'password') { el.placeholder = 'تلقائي'; el.autocomplete = 'off'; el.spellcheck = false; }
      }
      el.addEventListener('input', function () { td.classList.remove('is-bad'); td.removeAttribute('title'); counts(); });
      td.appendChild(el); tr.appendChild(td);
    });
    var act = document.createElement('td'); act.className = 'w-actions';
    var del = document.createElement('button');
    del.type = 'button'; del.className = 'btn btn-ghost btn-icon btn-sm text-danger'; del.title = 'حذف الصف';
    del.innerHTML = SN.icon('trash-2');
    del.addEventListener('click', function () { var i = rowsOf().indexOf(tr); notes.splice(i, 1); tr.remove(); counts(); });
    act.appendChild(del); tr.appendChild(act);
    tbody.appendChild(tr);
    notes.push(r.notes || '');
    return tr;
  }
  function rowsOf() { return Array.prototype.slice.call(tbody.querySelectorAll('tr')); }
  function counts() {
    var rows = rowsOf();
    rows.forEach(function (tr, i) { tr.firstChild.textContent = SN.fmtNum(i + 1); });
    $('[data-si-count]').textContent = SN.fmtNum(rows.length) + ' صف';
    var bad = tbody.querySelectorAll('td.is-bad').length;
    var b = $('[data-si-bad]');
    b.hidden = !bad; b.textContent = SN.fmtNum(tbody.querySelectorAll('tr.has-error').length) + ' للتصحيح';
    var n = rows.length;
    $('[data-si-save-label]').textContent = !n ? 'حفظ' : n === 1 ? 'حفظ مشترك واحد' : n === 2 ? 'حفظ مشتركَين'
      : 'حفظ ' + SN.fmtNum(n) + (n <= 10 ? ' مشتركين' : ' مشتركاً');
    $('[data-si-save]').disabled = !rows.length;
  }
  function collect() {
    return rowsOf().map(function (tr, i) {
      var r = { notes: notes[i] || '' };
      tr.querySelectorAll('[data-f]').forEach(function (el) { r[el.getAttribute('data-f')] = el.value.trim(); });
      return r;
    });
  }

  // ---------------- الخطوات ----------------
  $('[data-si-parse]').addEventListener('click', function () {
    var btn = this, text = $('#si-text').value.trim();
    if (!text && !images.length) { say(status, false, 'الصق القائمة أو أضف صورة الدفتر.'); return; }
    var fd = new FormData();
    fd.append('text', text);
    images.forEach(function (b, i) { fd.append('images[]', b, 'page-' + (i + 1) + '.jpg'); });
    btn.classList.add('is-loading'); btn.disabled = true; say(status, true, '');
    SN.api(box.getAttribute('data-parse'), { method: 'POST', form: fd, silent: true })
      .then(function (r) {
        tbody.innerHTML = ''; notes = [];
        r.rows.forEach(addRow);
        counts();
        input.hidden = true; review.hidden = false; msg.hidden = true;
        review.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (e) { say(status, false, e.message); })
      .then(function () { btn.classList.remove('is-loading'); btn.disabled = false; });
  });
  $('[data-si-add]').addEventListener('click', function () { var tr = addRow({}); counts(); tr.querySelector('input').focus(); });
  $('[data-si-back]').addEventListener('click', function () { review.hidden = true; input.hidden = false; });
  $('[data-si-save]').addEventListener('click', function () {
    var btn = this;
    rowsOf().forEach(function (tr) { tr.classList.remove('has-error'); tr.querySelectorAll('td.is-bad').forEach(function (td) { td.classList.remove('is-bad'); td.removeAttribute('title'); }); });
    btn.classList.add('is-loading'); btn.disabled = true; msg.hidden = true;
    var plan = box.querySelector('[data-si-plan]').value, router = box.querySelector('[data-si-router]').value;
    SN.api(box.getAttribute('data-save'), { method: 'POST', data: { rows: collect(), plan_id: plan, router_id: router }, silent: true })
      .then(function (r) { window.location.href = r.redirect; })
      .catch(function (e) {
        var errs = (e.data && e.data.errors) || {}, rows = rowsOf(), first = null;
        Object.keys(errs).forEach(function (i) {
          var tr = rows[+i];
          if (!tr) { return; }
          tr.classList.add('has-error');
          Object.keys(errs[i]).forEach(function (f) {
            var el = tr.querySelector('[data-f="' + f + '"]'), td = el && el.parentNode;
            if (td) { td.classList.add('is-bad'); td.title = errs[i][f]; if (!first) { first = el; } }
          });
        });
        say(msg, false, e.message);
        counts();
        if (first) { first.focus(); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        btn.classList.remove('is-loading'); btn.disabled = false;
      });
  });
})(window, document);
