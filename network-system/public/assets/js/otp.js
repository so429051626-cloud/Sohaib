/*
 * رمز التحقق في ستة مربعات: الكتابة تنتقل للمربع التالي، واللصق يملؤها كلها، والرمز الكامل يُرسل تلقائياً.
 * عند صحته تلتف المربعات في دائرة خضراء وتدور، ثم الدخول إلى الحساب بعد 3 ثوانٍ (أو data-otp-delay على النموذج).
 * نص النجاح من data-otp-done على النموذج.
 * عند خطئه تهتز المربعات باللون الأحمر وتُفرَّغ.
 */
(function (document, window) {
  'use strict';
  var form = document.querySelector('[data-otp-form]');
  var wrap = form && form.querySelector('[data-otp]');
  if (!wrap || !window.fetch) { return; }
  var ring = wrap.querySelector('[data-otp-ring]');
  var boxes = Array.prototype.slice.call(ring.querySelectorAll('.otp-box'));
  var fallback = form.querySelector('[data-otp-fallback]');
  var hidden = fallback.querySelector('input');
  var status = form.querySelector('[data-otp-status]');
  var submit = form.querySelector('[data-otp-submit]');
  var busy = false;
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };

  // المربعات بدل الحقل الواحد
  fallback.hidden = true;
  hidden.removeAttribute('required');
  hidden.type = 'hidden';
  fallback.parentNode.insertBefore(hidden, fallback);
  wrap.hidden = false;
  var err = form.querySelector('.field-error');

  function digits(s) { return String(s || '').replace(/[٠-٩۰-۹]/g, function (c) { return DIGITS[c]; }).replace(/\D/g, ''); }
  function code() { return boxes.map(function (b) { return b.value; }).join(''); }
  function say(ok, msg) { status.className = 'slug-status ' + (ok ? 'is-ok' : 'is-bad'); status.textContent = msg || ''; }
  function fill(from, text) {
    var d = digits(text);
    for (var i = 0; i < d.length && from + i < boxes.length; i++) { boxes[from + i].value = d[i]; }
    var next = Math.min(from + d.length, boxes.length - 1);
    boxes[next].focus();
    sync();
  }
  function sync() {
    hidden.value = code();
    boxes.forEach(function (b) { b.classList.toggle('is-filled', b.value !== ''); });
    if (hidden.value.length === boxes.length && !busy) { send(); }
  }

  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      var d = digits(b.value);
      b.value = '';
      if (d.length > 1) { fill(i, d); return; }
      b.value = d;
      if (d && i < boxes.length - 1) { boxes[i + 1].focus(); }
      sync();
    });
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !b.value && i > 0) { e.preventDefault(); boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); }
      // المربعات بترتيب الأرقام من اليسار إلى اليمين
      if (e.key === 'ArrowLeft' && i > 0) { e.preventDefault(); boxes[i - 1].focus(); }
      if (e.key === 'ArrowRight' && i < boxes.length - 1) { e.preventDefault(); boxes[i + 1].focus(); }
    });
    b.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text');
      if (digits(t)) { e.preventDefault(); fill(i, t); }
    });
    b.addEventListener('focus', function () { b.select(); });
  });
  boxes[0].focus();

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) { return; }
    if (code().length < boxes.length) { say(false, 'أدخل الرمز كاملاً.'); boxes[Math.min(code().length, boxes.length - 1)].focus(); return; }
    send();
  });

  function wrong(msg) {
    if (err) { err.hidden = true; }
    say(false, msg);
    wrap.classList.remove('is-wrong');
    void wrap.offsetWidth;
    wrap.classList.add('is-wrong');
    setTimeout(function () {
      wrap.classList.remove('is-wrong');
      boxes.forEach(function (b) { b.value = ''; });
      sync();
      boxes[0].focus();
    }, 650);
  }

  function send() {
    busy = true;
    submit.disabled = true;
    submit.classList.add('is-loading');
    say(true, '');
    var body = new FormData(form);
    fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok && d.ok !== false, d: d }; }); })
      .then(function (res) {
        if (res.ok) { done(res.d.redirect); return; }
        busy = false;
        submit.disabled = false;
        submit.classList.remove('is-loading');
        if (res.d.redirect) { window.location.href = res.d.redirect; return; }
        wrong(res.d.message || 'رمز التحقق غير صحيح أو منتهي الصلاحية.');
      })
      .catch(function () {
        busy = false;
        submit.disabled = false;
        submit.classList.remove('is-loading');
        say(false, 'تعذّر الاتصال بالخادم. حاول مجدداً.');
      });
  }

  // النجاح: المربعات تلتف حول بعضها في دائرة خضراء وتدور، ثم الدخول بعد 3 ثوانٍ
  function done(to) {
    boxes.forEach(function (b) {
      b.readOnly = true;
      try { b.setSelectionRange(0, 0); } catch (e) { /* بعض المتصفحات لا تدعمها لهذا النوع */ }
      b.blur();
    });
    if (window.getSelection) { window.getSelection().removeAllRanges(); }
    say(true, form.getAttribute('data-otp-done') || 'تم تأكيد رقم الهاتف.');
    submit.classList.remove('is-loading');
    wrap.classList.add('is-verified');
    if (!calm) {
      var start = boxes.map(function (b) { return { left: b.offsetLeft, top: b.offsetTop }; });
      ring.style.height = ring.offsetHeight + 'px';
      ring.classList.add('is-ring');
      boxes.forEach(function (b, i) { b.style.left = start[i].left + 'px'; b.style.top = start[i].top + 'px'; });
      void ring.offsetWidth;
      var h = 188, size = boxes[0].offsetWidth, r = 66, cx = ring.clientWidth / 2, cy = h / 2;
      ring.style.height = h + 'px';
      boxes.forEach(function (b, i) {
        var a = (i / boxes.length) * Math.PI * 2 - Math.PI / 2;
        b.style.left = (cx + r * Math.cos(a) - size / 2) + 'px';
        b.style.top = (cy + r * Math.sin(a) - size / 2) + 'px';
      });
      setTimeout(function () { ring.classList.add('is-spinning'); }, 650);
    }
    setTimeout(function () { window.location.href = to; }, parseInt(form.getAttribute('data-otp-delay'), 10) || 3000);
  }
})(document, window);
