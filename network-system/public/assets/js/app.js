/* ==========================================================================
   MADIX NEXUS — السكربت العام
   ========================================================================== */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var csrf = (doc.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var base = (doc.querySelector('meta[name="base-url"]') || {}).content || '';
  var iconsUrl = (doc.querySelector('meta[name="icons-url"]') || {}).content || '';

  function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
  function on(el, ev, sel, fn) {
    el.addEventListener(ev, function (e) {
      var t = e.target.closest(sel);
      if (t && el.contains(t)) { fn.call(t, e, t); }
    });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function icon(name, cls) {
    return '<svg class="icon' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><use href="' + iconsUrl + '#i-' + name + '"></use></svg>';
  }

  // ------------------------------------------------------------------------
  // الوضع الفاتح/الداكن: فاتح دائماً ما لم يختر المستخدم الداكن، ولا يتبع إعداد الجهاز
  // ------------------------------------------------------------------------
  function applyTheme(pref) {
    var t = pref === 'dark' ? 'dark' : 'light';
    root.setAttribute('data-theme', t);
    root.setAttribute('data-theme-pref', t);
    doc.dispatchEvent(new CustomEvent('themechange', { detail: { theme: t } }));
  }
  on(doc, 'click', '[data-theme-set]', function (e, el) {
    e.preventDefault();
    var pref = el.getAttribute('data-theme-set') === 'dark' ? 'dark' : 'light';
    applyTheme(pref);
    try { localStorage.setItem('sn-theme', pref); } catch (err) {}
    if (doc.querySelector('meta[name="auth"]')) { api(base + '/account/theme', { method: 'POST', data: { theme: pref }, silent: true }).catch(function () {}); }
    closeDropdowns();
  });

  // ------------------------------------------------------------------------
  // الطلبات
  // ------------------------------------------------------------------------
  function api(url, opts) {
    opts = opts || {};
    var method = (opts.method || 'GET').toUpperCase();
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var body = null;
    if (method !== 'GET') {
      headers['X-CSRF-Token'] = csrf;
      if (opts.form) {
        body = opts.form instanceof FormData ? opts.form : new FormData(opts.form);
      } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.data || {});
      }
    }
    return fetch(url, { method: method, headers: headers, body: body, credentials: 'same-origin' })
      .then(function (res) {
        return res.text().then(function (text) {
          var data;
          try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { ok: false, message: 'استجابة غير صالحة من الخادم.' }; }
          if (!res.ok || data.ok === false) {
            var err = new Error(data.message || 'تعذّر تنفيذ الطلب.');
            err.data = data; err.status = res.status;
            if (res.status === 401) { window.location.href = base + '/login'; }
            throw err;
          }
          return data;
        });
      })
      .catch(function (err) {
        if (!err.status && !err.data) { err.message = 'تعذّر الاتصال بالخادم.'; }
        if (!opts.silent) { toast('error', err.message); }
        throw err;
      });
  }

  // ------------------------------------------------------------------------
  // الإشعارات العائمة
  // ------------------------------------------------------------------------
  function toast(type, message, timeout) {
    var box = $('.toasts');
    if (!box) { box = doc.createElement('div'); box.className = 'toasts'; doc.body.appendChild(box); }
    var icons = { success: 'circle-check', error: 'circle-x', danger: 'circle-x', warning: 'triangle-alert', info: 'info' };
    var el = doc.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = icon(icons[type] || 'info') + '<div>' + esc(message) + '</div><button type="button" class="toast-close" aria-label="إغلاق">' + icon('x', 'icon-sm') + '</button>';
    box.appendChild(el);
    var remove = function () {
      el.classList.add('is-leaving');
      setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 200);
    };
    el.querySelector('.toast-close').addEventListener('click', remove);
    setTimeout(remove, timeout || (type === 'error' ? 7000 : 4500));
  }

  // ------------------------------------------------------------------------
  // القوائم المنسدلة
  // ------------------------------------------------------------------------
  // القائمة المفتوحة تُنقل مؤقتاً إلى نهاية الصفحة وتُثبَّت تحت زرّها، فلا يغطيها أي قسم أو جدول أو بطاقة
  // ولا تُقص داخل حاوية قابلة للتمرير، وتعود إلى مكانها الأصلي عند الإغلاق.
  function menuOf(dd) { return dd._menu || $(':scope > .dropdown-menu', dd) || $('.dropdown-menu', dd); }
  function closeDropdowns(except) {
    $$('.dropdown.is-open').forEach(function (d) {
      if (d === except) { return; }
      d.classList.remove('is-open');
      var t = $('[data-dropdown]', d);
      if (t) { t.setAttribute('aria-expanded', 'false'); }
      var m = menuOf(d);
      if (m) {
        m.classList.remove('is-floating', 'is-up');
        m.style.left = m.style.top = m.style.width = m.style.maxHeight = '';
        if (m.parentNode !== d) { d.appendChild(m); }
      }
      d._menu = null;
    });
  }
  function placeDropdown(dd) {
    var m = dd._menu, t = $('[data-dropdown]', dd);
    if (!m || !t || !m.classList.contains('is-floating')) { return; }
    var r = t.getBoundingClientRect(), pad = 8, gap = 6;
    var vw = doc.documentElement.clientWidth, vh = window.innerHeight;
    if (r.bottom < 0 || r.top > vh || (!r.width && !r.height)) { closeDropdowns(); return; }
    m.style.maxHeight = '';
    m.style.width = '';
    var w = m.offsetWidth, h = m.offsetHeight;
    if (w > vw - pad * 2) { w = vw - pad * 2; m.style.width = w + 'px'; }
    var rtl = (doc.documentElement.getAttribute('dir') || 'rtl') === 'rtl';
    var alignStart = m.classList.contains('align-start');
    // مثل القائمة الأصلية: تُحاذى حافة القائمة مع حافة الزر (نهاية السطر افتراضياً، وبدايته مع align-start)
    var left = (rtl !== alignStart) ? r.left : r.right - w;
    left = Math.max(pad, Math.min(left, vw - pad - w));
    var below = vh - r.bottom - gap - pad, above = r.top - gap - pad;
    var up = h > below && above > below;
    var room = Math.max(120, up ? above : below);
    if (h > room) { m.style.maxHeight = room + 'px'; h = room; }
    m.classList.toggle('is-up', up);
    m.style.left = Math.round(left) + 'px';
    m.style.top = Math.round(up ? r.top - gap - h : r.bottom + gap) + 'px';
  }
  function openDropdown(dd) {
    var m = menuOf(dd);
    dd.classList.add('is-open');
    var t = $('[data-dropdown]', dd);
    if (t) { t.setAttribute('aria-expanded', 'true'); }
    if (!m) { return; }
    dd._menu = m;
    m.classList.add('is-floating');
    doc.body.appendChild(m);
    placeDropdown(dd);
  }
  var placeQueued = false;
  function placeOpen() {
    if (placeQueued) { return; }
    placeQueued = true;
    requestAnimationFrame(function () {
      placeQueued = false;
      $$('.dropdown.is-open').forEach(placeDropdown);
    });
  }
  window.addEventListener('scroll', placeOpen, true);
  window.addEventListener('resize', placeOpen);
  window.addEventListener('pagehide', function () { closeDropdowns(); });
  // مستمع واحد على المستند: فتح/إغلاق القائمة، وإغلاقها عند النقر خارجها أو على أحد عناصرها
  doc.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-dropdown]');
    if (toggle) {
      e.preventDefault();
      var dd = toggle.closest('.dropdown');
      var open = dd.classList.contains('is-open');
      closeDropdowns();
      if (!open) { openDropdown(dd); }
      return;
    }
    if (!e.target.closest('.dropdown-menu') || e.target.closest('.dropdown-item')) { closeDropdowns(); }
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeDropdowns(); doc.body.classList.remove('sidebar-open'); }
  });

  // ------------------------------------------------------------------------
  // القائمة الجانبية على الجوال
  // ------------------------------------------------------------------------
  // إظهار الرابط النشط داخل القائمة بتمرير القائمة وحدها، دون تحريك الصفحة
  function revealActiveNav() {
    var nav = $('.sidebar-nav'), act = nav && $('.nav-link.is-active', nav);
    if (!act) { return; }
    var n = nav.getBoundingClientRect(), a = act.getBoundingClientRect();
    if (a.top < n.top || a.bottom > n.bottom) {
      nav.scrollTop += (a.top - n.top) - (nav.clientHeight - a.height) / 2;
    }
  }
  on(doc, 'click', '[data-sidebar-toggle]', function () {
    if (doc.body.classList.toggle('sidebar-open')) { revealActiveNav(); }
  });
  on(doc, 'click', '.backdrop', function () { doc.body.classList.remove('sidebar-open'); });
  on(doc, 'click', '[data-sidebar-close]', function () { doc.body.classList.remove('sidebar-open'); });

  // ------------------------------------------------------------------------
  // القائمة الجانبية: طي الأقسام (يُحفظ في المتصفح)، البحث السريع (Ctrl+K)، وتلاشي حواف التمرير
  // ------------------------------------------------------------------------
  (function () {
    var side = $('.sidebar[data-nav]'), nav = side && $('[data-nav-scroll]', side);
    if (!nav) { return; }
    var store = 'sn-nav-' + side.getAttribute('data-nav');
    var search = $('[data-nav-search]', side), empty = $('[data-nav-empty]', nav);
    var groups = $$('.nav-group', nav);
    var closed = [];
    try { closed = JSON.parse(localStorage.getItem(store) || '[]'); } catch (err) { closed = []; }
    if (!Array.isArray(closed)) { closed = []; }

    // ظلّا حافتي التمرير على الغلاف (لا على حاوية التمرير): يُحدَّثان مرة لكل إطار وعند التغيّر فقط
    var shell = nav.closest('[data-nav-body]') || nav, fadeNow = '', fadeQueued = false, lastY = 0;
    function fadeApply() {
      fadeQueued = false;
      lastY = nav.scrollTop;
      var top = lastY > 2, bottom = lastY + nav.clientHeight < nav.scrollHeight - 2;
      var v = (top ? 'top ' : '') + (bottom ? 'bottom' : '');
      if (v === fadeNow) { return; }
      fadeNow = v;
      if (v) { shell.setAttribute('data-fade', v.trim()); } else { shell.removeAttribute('data-fade'); }
    }
    function fade() {
      if (fadeQueued) { return; }
      fadeQueued = true;
      requestAnimationFrame(fadeApply);
    }
    // موضع التمرير يُحفظ للجلسة ويُستعاد قبل رسم الصفحة التالية (partials/nav-restore)، فلا تقفز القائمة عند التنقل.
    // يُقرأ أثناء التمرير (في إطار الرسم) لا عند مغادرة الصفحة: القراءة عند المغادرة تفرض حساب التخطيط وتؤخر فتح الصفحة التالية
    var saveTimer = null;
    function savePos() {
      try { sessionStorage.setItem(store + '-y', String(Math.round(lastY))); } catch (err) {}
    }
    function savePosSoon() {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(savePos, 160);
    }
    function setGroup(g, collapse) {
      g.classList.toggle('is-collapsed', collapse);
      var t = $('[data-nav-toggle]', g);
      if (t) { t.setAttribute('aria-expanded', collapse ? 'false' : 'true'); }
    }

    // الأقسام المطوية سابقاً تُستعاد دون حركة، وقسم الصفحة الحالية يبقى مفتوحاً دائماً.
    // عادةً تكون مطوية مسبقاً قبل الرسم (partials/nav-restore): لا شيء يتغير فلا حاجة لإيقاف الحركة وإعادة حساب القائمة
    var pending = groups.filter(function (g) {
      var name = g.getAttribute('data-nav-group');
      return name && closed.indexOf(name) !== -1 && !$('.nav-link.is-active', g) && !g.classList.contains('is-collapsed');
    });
    if (pending.length) {
      nav.classList.add('no-anim');
      pending.forEach(function (g) { setGroup(g, true); });
      void nav.offsetHeight;
      nav.classList.remove('no-anim');
    }
    revealActiveNav();
    // ظلّا الحافتين يُضبطان فوراً عند فتح الصفحة بلا حركة تلاشٍ (الحركة للتمرير فقط)
    shell.classList.add('is-init');
    fadeApply();
    void shell.offsetHeight;
    requestAnimationFrame(function () { shell.classList.remove('is-init'); });
    nav.addEventListener('scroll', function () { fade(); savePosSoon(); }, { passive: true });
    window.addEventListener('resize', fade);
    window.addEventListener('pagehide', savePos);
    on(doc, 'click', '[data-sidebar-toggle]', function () { requestAnimationFrame(fade); });

    on(nav, 'click', '[data-nav-toggle]', function (e, t) {
      if (nav.classList.contains('is-searching')) { return; }
      var g = t.closest('.nav-group'), name = g.getAttribute('data-nav-group');
      var collapse = !g.classList.contains('is-collapsed');
      g.classList.add('is-toggling');
      setGroup(g, collapse);
      clearTimeout(g.navTimer);
      g.navTimer = setTimeout(function () { g.classList.remove('is-toggling'); fade(); }, 320);
      closed = closed.filter(function (n) { return n !== name; });
      if (collapse) { closed.push(name); }
      try { localStorage.setItem(store, JSON.stringify(closed)); } catch (err) {}
    });

    if (!search) { return; }
    // توحيد الكتابة العربية: الهمزات والتاء المربوطة والألف المقصورة والتشكيل
    function norm(s) {
      return String(s || '').toLowerCase().replace(/[ً-ْـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
        .replace(/\s+/g, ' ').trim();
    }
    var links = $$('.nav-link', nav).map(function (a) {
      var span = $('.nav-text', a), text = span ? span.textContent : '', g = a.closest('.nav-group');
      return { a: a, span: span, text: text, n: norm(text), g: g, gn: norm(g.getAttribute('data-nav-group') || '') };
    });
    function paint(it, words) {
      if (!it.span) { return; }
      if (!words.length || it.n.length !== it.text.length) { it.span.textContent = it.text; return; }
      var marks = [];
      words.forEach(function (w) { var i = it.n.indexOf(w); if (i >= 0) { marks.push([i, i + w.length]); } });
      marks.sort(function (x, y) { return x[0] - y[0]; });
      var html = '', at = 0;
      marks.forEach(function (m) {
        var from = Math.max(m[0], at);
        if (m[1] <= from) { return; }
        html += esc(it.text.slice(at, from)) + '<mark class="nav-hit">' + esc(it.text.slice(from, m[1])) + '</mark>';
        at = m[1];
      });
      it.span.innerHTML = html + esc(it.text.slice(at));
    }
    function visible() { return links.filter(function (it) { return !it.a.hidden && !it.g.hidden; }); }
    // كل كلمة يجب أن تظهر في اسم الصفحة أو اسم قسمها («سجل هوتسبوت» ← سجل الدخول في قسم الهوتسبوت)
    function filter() {
      var q = norm(search.value), words = q ? q.split(' ') : [], any = false;
      nav.classList.toggle('is-searching', q !== '');
      groups.forEach(function (g) { g.navShown = 0; });
      links.forEach(function (it) {
        var hay = it.n + ' ' + it.gn;
        var hit = words.every(function (w) { return hay.indexOf(w) !== -1; });
        it.a.hidden = !hit;
        paint(it, hit ? words : []);
        if (hit) { it.g.navShown++; any = true; }
      });
      groups.forEach(function (g) { g.hidden = !g.navShown; });
      if (empty) { empty.hidden = any || q === ''; }
      if (q) { nav.scrollTop = 0; }
      fade();
    }
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
      var list = visible();
      if (e.key === 'Enter') {
        e.preventDefault();
        if (search.value.trim() !== '' && list[0]) { list[0].a.click(); }
      } else if (e.key === 'ArrowDown' && list[0]) {
        e.preventDefault();
        list[0].a.focus();
      } else if (e.key === 'Escape' && search.value !== '') {
        e.stopPropagation();
        search.value = '';
        filter();
      }
    });
    // الأسهم تتنقل بين الروابط الظاهرة، والسهم للأعلى من أول رابط يعود إلى البحث
    nav.addEventListener('keydown', function (e) {
      if ((e.key !== 'ArrowDown' && e.key !== 'ArrowUp') || !e.target.classList.contains('nav-link')) { return; }
      var list = visible().map(function (it) { return it.a; }), i = list.indexOf(e.target);
      if (i === -1) { return; }
      e.preventDefault();
      var next = e.key === 'ArrowDown' ? list[i + 1] : (i === 0 ? search : list[i - 1]);
      if (next) { next.focus(); }
    });
    doc.addEventListener('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || (e.code !== 'KeyK' && e.key !== 'k' && e.key !== 'K')) { return; }
      e.preventDefault();
      if (getComputedStyle(side).visibility === 'hidden') { doc.body.classList.add('sidebar-open'); }
      requestAnimationFrame(function () { search.focus(); search.select(); });
    });
    if (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
      var kbd = $('.nav-kbd bdi', side);
      if (kbd) { kbd.textContent = '⌘ K'; }
    }
    // العودة للصفحة من ذاكرة المتصفح قد تُبقي نص البحث
    if (search.value) { filter(); }
    window.addEventListener('pageshow', function () { if (search.value || nav.classList.contains('is-searching')) { filter(); } });
  })();

  // ------------------------------------------------------------------------
  // النوافذ المنبثقة
  // ------------------------------------------------------------------------
  function openModal(id) {
    var m = typeof id === 'string' ? doc.getElementById(id) : id;
    if (!m) { return null; }
    if (typeof m.showModal === 'function') { m.showModal(); } else { m.setAttribute('open', ''); }
    var f = m.querySelector('[autofocus], input:not([type=hidden]), select, textarea');
    if (f) { setTimeout(function () { f.focus(); }, 30); }
    return m;
  }
  function closeModal(m) {
    if (!m) { return; }
    if (typeof m.close === 'function') { m.close(); } else { m.removeAttribute('open'); }
  }
  on(doc, 'click', '[data-modal-open]', function (e, el) {
    e.preventDefault();
    var m = openModal(el.getAttribute('data-modal-open'));
    if (m) { m.dispatchEvent(new CustomEvent('modal:open', { detail: { trigger: el } })); }
  });
  on(doc, 'click', '[data-modal-close]', function (e, el) { e.preventDefault(); closeModal(el.closest('dialog')); });
  doc.addEventListener('click', function (e) {
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('modal')) {
      var r = e.target.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) { closeModal(e.target); }
    }
  });

  // نافذة التأكيد العامة
  function confirmDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var dlg = doc.getElementById('confirm-dialog');
      if (!dlg) { resolve(window.confirm(message)); return; }
      dlg.querySelector('.confirm-text').textContent = message;
      var ok = dlg.querySelector('[data-confirm-ok]');
      ok.textContent = opts.okText || 'تأكيد';
      ok.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
      dlg.querySelector('.modal-icon').className = 'modal-icon' + (opts.danger === false ? ' is-primary' : '');
      var done = function (val) {
        ok.removeEventListener('click', yes);
        dlg.removeEventListener('close', no);
        closeModal(dlg);
        resolve(val);
      };
      var yes = function () { done(true); };
      var no = function () { done(false); };
      ok.addEventListener('click', yes);
      dlg.addEventListener('close', no);
      openModal(dlg);
      setTimeout(function () { ok.focus(); }, 40);
    });
  }

  on(doc, 'submit', 'form[data-confirm]', function (e, form) {
    if (form.dataset.confirmed === '1') { return; }
    e.preventDefault();
    confirmDialog(form.getAttribute('data-confirm'), { danger: form.getAttribute('data-confirm-type') !== 'primary', okText: form.getAttribute('data-confirm-ok') })
      .then(function (yes) {
        if (yes) { form.dataset.confirmed = '1'; if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); } }
      });
  });
  on(doc, 'click', 'button[data-confirm]:not([type=submit]), a[data-confirm]', function (e, el) {
    e.preventDefault();
    confirmDialog(el.getAttribute('data-confirm'), { danger: el.getAttribute('data-confirm-type') !== 'primary' }).then(function (yes) {
      if (!yes) { return; }
      if (el.tagName === 'A') { window.location.href = el.href; }
      else { el.dispatchEvent(new CustomEvent('confirmed', { bubbles: true })); }
    });
  });
  on(doc, 'click', 'button[type=submit][data-confirm]', function (e, el) {
    if (el.dataset.confirmed === '1') { el.dataset.confirmed = ''; return; }
    e.preventDefault();
    confirmDialog(el.getAttribute('data-confirm'), { danger: el.getAttribute('data-confirm-type') !== 'primary' }).then(function (yes) {
      if (yes) { el.dataset.confirmed = '1'; el.click(); }
    });
  });

  // منع الإرسال المزدوج وإظهار حالة التحميل
  on(doc, 'submit', 'form:not([data-no-loading])', function (e, form) {
    if (e.defaultPrevented) { return; }
    var btn = e.submitter || form.querySelector('[type=submit]');
    if (btn && !btn.hasAttribute('data-no-loading')) {
      setTimeout(function () { btn.classList.add('is-loading'); btn.disabled = true; }, 0);
      setTimeout(function () { btn.classList.remove('is-loading'); btn.disabled = false; }, 15000);
    }
  });

  // ------------------------------------------------------------------------
  // الجداول: تحديد الكل والإجراءات الجماعية
  // ------------------------------------------------------------------------
  function refreshBulk(scope) {
    var checks = $$('[data-row-check]', scope);
    var selected = checks.filter(function (c) { return c.checked; });
    checks.forEach(function (c) { var tr = c.closest('tr'); if (tr) { tr.classList.toggle('is-selected', c.checked); } });
    var all = $('[data-select-all]', scope);
    if (all) {
      all.checked = selected.length > 0 && selected.length === checks.length;
      all.indeterminate = selected.length > 0 && selected.length < checks.length;
    }
    var bar = $('.bulkbar', scope);
    if (bar) {
      bar.classList.toggle('is-visible', selected.length > 0);
      var c = $('[data-selected-count]', bar);
      if (c) { c.textContent = selected.length; }
    }
  }
  on(doc, 'change', '[data-select-all]', function (e, el) {
    var scope = el.closest('[data-bulk-scope]') || doc;
    $$('[data-row-check]', scope).forEach(function (c) { c.checked = el.checked; });
    refreshBulk(scope);
  });
  on(doc, 'change', '[data-row-check]', function (e, el) {
    refreshBulk(el.closest('[data-bulk-scope]') || doc);
  });
  // الإجراء الجماعي ينسخ المعرّفات المحددة إلى النموذج قبل الإرسال
  on(doc, 'submit', 'form[data-bulk-form]', function (e, form) {
    var scope = form.closest('[data-bulk-scope]') || doc;
    $$('input[data-bulk-id]', form).forEach(function (i) { i.parentNode.removeChild(i); });
    $$('[data-row-check]:checked', scope).forEach(function (c) {
      var i = doc.createElement('input');
      i.type = 'hidden'; i.name = 'ids[]'; i.value = c.value; i.setAttribute('data-bulk-id', '');
      form.appendChild(i);
    });
  });

  // ------------------------------------------------------------------------
  // أدوات صغيرة
  // ------------------------------------------------------------------------
  on(doc, 'change', '[data-autosubmit]', function (e, el) { el.form && el.form.submit(); });

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = doc.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      doc.body.appendChild(ta); ta.select();
      try { doc.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
      doc.body.removeChild(ta);
    });
  }
  on(doc, 'click', '[data-copy], [data-copy-target]', function (e, el) {
    e.preventDefault();
    var text = el.getAttribute('data-copy');
    if (text === null) {
      var t = $(el.getAttribute('data-copy-target'));
      text = t ? (t.value !== undefined && t.tagName !== 'PRE' && t.tagName !== 'DIV' ? t.value : t.textContent) : '';
    }
    copyText(text).then(function () { toast('success', 'تم النسخ.'); }, function () { toast('error', 'تعذّر النسخ.'); });
  });

  on(doc, 'click', '.toggle-pass', function (e, el) {
    e.preventDefault();
    var input = el.parentNode.querySelector('input');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    el.innerHTML = icon(show ? 'eye-off' : 'eye');
  });

  // كشف القيم المخفية (كلمات المرور في الجداول)
  on(doc, 'click', '[data-reveal]', function (e, el) {
    e.preventDefault();
    var v = el.getAttribute('data-reveal');
    el.outerHTML = '<span class="mono">' + esc(v) + '</span>';
  });

  // التبويب النشط يظهر كاملاً في شريط التبويبات القابل للتمرير (على الجوال)، بلا تحريك الصفحة
  // center: يوضع التبويب في وسط الشريط إن كان مخفياً (عند فتح الصفحة)
  function revealTab(el, center) {
    var g = el && el.closest('[data-tabs], .tabs');
    if (!g || g.scrollWidth <= g.clientWidth + 1) { return; }
    var gr = g.getBoundingClientRect(), r = el.getBoundingClientRect();
    if (r.left >= gr.left - 1 && r.right <= gr.right + 1) { return; }
    if (center) { g.scrollLeft += (r.left + r.width / 2) - (gr.left + gr.width / 2); }
    else if (r.left < gr.left) { g.scrollLeft -= gr.left - r.left + 16; }
    else { g.scrollLeft += r.right - gr.right + 16; }
  }

  // أشرطة التبويبات التي تنقل بين الصفحات (روابط ?tab=): يُحفظ موضع تمرير الشريط للجلسة ويُستعاد فور فتح الصفحة التالية،
  // ثم يُتأكد أن التبويب المختار ظاهر كاملاً، فلا يرجع الشريط إلى أول التبويبات عند الاختيار ولا بعد تحديث الصفحة
  (function () {
    $$('.tabs').forEach(function (g, i) {
      var key = 'sn-tabs:' + location.pathname + ':' + i, timer = null;
      var save = function () { try { sessionStorage.setItem(key, String(Math.round(g.scrollLeft))); } catch (err) {} };
      var active = $('.tab.is-active', g);
      if (g.scrollWidth > g.clientWidth + 1) {
        var saved = null;
        try { saved = sessionStorage.getItem(key); } catch (err) {}
        if (saved !== null && isFinite(+saved)) { g.scrollLeft = +saved; }
        if (active) { revealTab(active, true); }
      }
      // تغيّر عرض النص بعد تحميل الخطوط يغيّر مواضع التبويبات
      if (active && doc.fonts && doc.fonts.ready) { doc.fonts.ready.then(function () { revealTab(active, true); }); }
      g.addEventListener('scroll', function () { clearTimeout(timer); timer = setTimeout(save, 120); }, { passive: true });
      g.addEventListener('click', save);
      window.addEventListener('pagehide', save);
    });
  })();

  // التبويبات داخل الصفحة
  on(doc, 'click', '[data-tab]', function (e, el) {
    e.preventDefault();
    var group = el.closest('[data-tabs]');
    var name = el.getAttribute('data-tab');
    $$('[data-tab]', group).forEach(function (t) { t.classList.toggle('is-active', t === el); });
    var scope = group.parentNode;
    $$('[data-tab-panel]', scope).forEach(function (p) {
      if (p.closest('[data-tabs]') === group || p.parentNode === scope || scope.contains(p)) {
        p.classList.toggle('is-active', p.getAttribute('data-tab-panel') === name);
      }
    });
    if (group.hasAttribute('data-tabs-hash')) { history.replaceState(null, '', '#' + name); }
    revealTab(el);
  });

  // إظهار/إخفاء أقسام حسب قيمة حقل: data-show-when="field=value1,value2"
  function syncConditional() {
    $$('[data-show-when]').forEach(function (el) {
      var parts = el.getAttribute('data-show-when').split('=');
      var name = parts[0], values = (parts[1] || '').split(',');
      var inputs = $$('[name="' + name + '"]');
      var val = '';
      inputs.forEach(function (i) {
        if ((i.type === 'radio' || i.type === 'checkbox')) { if (i.checked) { val = i.value; } }
        else { val = i.value; }
      });
      if (inputs.length === 1 && inputs[0].type === 'checkbox') { val = inputs[0].checked ? '1' : '0'; }
      el.hidden = values.indexOf(val) === -1;
    });
  }
  doc.addEventListener('change', syncConditional);

  // البحث العام
  on(doc, 'keydown', '[data-global-search]', function (e, el) {
    if (e.key === 'Enter' && el.value.trim() !== '') {
      e.preventDefault();
      window.location.href = base + '/search?q=' + encodeURIComponent(el.value.trim());
    }
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test((doc.activeElement || {}).tagName)) {
      var s = $('[data-global-search]');
      if (s) { e.preventDefault(); s.focus(); }
    }
  });

  // ------------------------------------------------------------------------
  // تنسيق الأرقام
  // ------------------------------------------------------------------------
  function ltr(s) { return '\u2066' + s + '\u2069'; }
  function fmtBytes(b) {
    b = Number(b) || 0;
    if (b <= 0) { return ltr('0 B'); }
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), u.length - 1);
    var v = b / Math.pow(1024, i);
    return ltr((i === 0 ? v.toFixed(0) : v.toFixed(1).replace(/\.0$/, '')) + ' ' + u[i]);
  }
  function fmtRate(bps) {
    bps = Number(bps) || 0;
    if (bps < 1000) { return ltr(bps.toFixed(0) + ' bps'); }
    var u = ['Kbps', 'Mbps', 'Gbps'], i = -1;
    while (bps >= 1000 && i < 2) { bps /= 1000; i++; }
    return ltr((bps >= 100 ? bps.toFixed(0) : bps.toFixed(1).replace(/\.0$/, '')) + ' ' + u[i]);
  }
  function fmtDuration(s) {
    s = Math.max(0, Math.floor(Number(s) || 0));
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return (d > 0 ? d + (doc.documentElement.lang === 'en' ? 'd ' : 'ي ') : '') + p(h) + ':' + p(m) + (d > 0 ? '' : ':' + p(sec));
  }
  function fmtNum(n) { return (Number(n) || 0).toLocaleString('en-US'); }

  // ------------------------------------------------------------------------
  // الرسوم البيانية
  // ------------------------------------------------------------------------
  function cssVar(name) { return getComputedStyle(root).getPropertyValue(name).trim(); }
  var charts = [];
  function chartTheme() {
    return {
      text: cssVar('--muted'), grid: cssVar('--chart-grid'), border: cssVar('--border'),
      colors: [cssVar('--chart-1'), cssVar('--chart-2'), cssVar('--chart-3'), cssVar('--chart-4'), cssVar('--chart-5')],
      surface: cssVar('--surface'), textStrong: cssVar('--text')
    };
  }
  function makeChart(canvas, config) {
    if (!window.Chart || !canvas) { return null; }
    var t = chartTheme();
    Chart.defaults.font.family = cssVar('--font') || 'system-ui';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = t.text;
    var type = config.type || 'line';
    var datasets = (config.datasets || []).map(function (ds, i) {
      var c = ds.color || t.colors[i % t.colors.length];
      // نوع خاص لكل سلسلة (خط فوق أعمدة مثلاً)
      var dt = ds.type || type;
      var mixedLine = dt === 'line' && type !== 'line';
      var o = {
        label: ds.label, data: ds.data, borderColor: c,
        backgroundColor: dt === 'line' ? hexA(c, mixedLine || ds.fill === false ? 0 : 0.12) : (ds.colors || c),
        borderWidth: dt === 'line' ? 2 : 0, pointRadius: mixedLine ? 2 : 0, pointHoverRadius: 4, tension: 0.32,
        fill: dt === 'line' ? !mixedLine && ds.fill !== false : undefined, borderRadius: dt === 'bar' ? 5 : 0,
        maxBarThickness: 34, yAxisID: ds.yAxisID, order: mixedLine ? 0 : 1
      };
      if (ds.dash) { o.borderDash = ds.dash; o.borderWidth = 1.5; o.fill = false; o.backgroundColor = 'transparent'; o.tension = 0; }
      if (ds.spanGaps) { o.spanGaps = true; }
      if (ds.type) { o.type = ds.type; }
      return o;
    });
    var opts = {
      type: type,
      data: { labels: config.labels || [], datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: { duration: config.animate === false ? 0 : 300 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: config.legend !== false && datasets.length > 1, position: 'bottom', align: 'start', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 14 } },
          tooltip: {
            rtl: true, textDirection: 'rtl', backgroundColor: t.surface, titleColor: t.textStrong, bodyColor: t.textStrong,
            borderColor: t.border, borderWidth: 1, padding: 10, cornerRadius: 8, boxPadding: 4,
            callbacks: config.tooltip ? { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + config.tooltip(ctx.parsed.y); } } : {}
          }
        },
        scales: type === 'doughnut' ? {} : {
          x: { grid: { display: false }, border: { color: t.border }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: config.xTicks || 8 }, reverse: false },
          y: { beginAtZero: config.fromZero !== false, suggestedMin: config.yMin, suggestedMax: config.yMax, grid: { color: t.grid }, border: { display: false }, ticks: { maxTicksLimit: 6, callback: config.yFormat || undefined }, stacked: !!config.stacked }
        }
      }
    };
    if (type === 'doughnut') {
      opts.data.datasets[0].backgroundColor = config.colors || t.colors;
      opts.data.datasets[0].borderColor = t.surface;
      opts.data.datasets[0].borderWidth = 2;
      opts.options.cutout = '68%';
      opts.options.plugins.legend.display = config.legend !== false;
      opts.options.plugins.legend.position = 'bottom';
    }
    if (config.stacked && opts.options.scales.x) { opts.options.scales.x.stacked = true; }
    var ch = new Chart(canvas, opts);
    ch._snConfig = config;
    charts.push(ch);
    return ch;
  }
  function hexA(hex, a) {
    if (!hex || hex.charAt(0) !== '#') { return hex; }
    var h = hex.length === 4 ? hex.replace(/#(.)(.)(.)/, '#$1$1$2$2$3$3') : hex;
    var r = parseInt(h.substr(1, 2), 16), g = parseInt(h.substr(3, 2), 16), b = parseInt(h.substr(5, 2), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
  }
  doc.addEventListener('themechange', function () {
    charts.slice().forEach(function (ch) {
      var canvas = ch.canvas, cfg = ch._snConfig;
      cfg.labels = ch.data.labels;
      cfg.datasets.forEach(function (d, i) { d.data = ch.data.datasets[i].data; });
      ch.destroy();
      charts.splice(charts.indexOf(ch), 1);
      cfg.animate = false;
      var n = makeChart(canvas, cfg);
      canvas.dispatchEvent(new CustomEvent('chart:recreated', { detail: n }));
    });
  });

  // ------------------------------------------------------------------------
  // التهيئة
  // ------------------------------------------------------------------------
  // شريط التقدّم الناعم أثناء الانتقال بين الصفحات
  var progress = null;
  var progressTimer = null;
  function startProgress() {
    if (!progress) {
      progress = doc.createElement('div');
      progress.className = 'nav-progress';
      doc.body.appendChild(progress);
    }
    var p = 8;
    progress.style.setProperty('--p', p + '%');
    progress.classList.add('is-active');
    clearInterval(progressTimer);
    clearTimeout(startProgress.guard);
    startProgress.guard = setTimeout(stopProgress, 12000);
    progressTimer = setInterval(function () {
      p = Math.min(92, p + (92 - p) * 0.12);
      progress.style.setProperty('--p', p + '%');
    }, 180);
  }
  function stopProgress() {
    clearInterval(progressTimer);
    if (!progress) { return; }
    progress.style.setProperty('--p', '100%');
    setTimeout(function () { progress.classList.remove('is-active'); progress.style.setProperty('--p', '0%'); }, 260);
  }
  doc.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    if (a.target && a.target !== '_self') { return; }
    if (a.hasAttribute('download') || a.hasAttribute('data-no-loading')) { return; }
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) { return; }
    if (a.origin !== window.location.origin) { return; }
    if (/\/(export|download)(\/|\?|$)|[?&]format=/.test(a.pathname + a.search)) { return; }
    if (a.pathname === window.location.pathname && a.search === window.location.search && a.hash) { return; }
    startProgress();
  });
  doc.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || f.hasAttribute('data-no-loading') || f.target === '_blank') { return; }
    if (!/export|download|print/.test(f.action)) { startProgress(); }
  });
  window.addEventListener('pageshow', stopProgress);
  window.addEventListener('pagehide', function () { setTimeout(stopProgress, 4000); });

  // لوحة المفاتيح على الهاتف: شريط الحقوق السفلي يختفي ما دامت ظاهرة، ويعود عند إغلاقها.
  // الشريط ثابت أسفل الشاشة، ورفعه فوق لوحة المفاتيح مع كل تمرير يجعله يتحرك مع الصفحة ثم يرجع؛
  // الإخفاء لا يتبع التمرير إطلاقاً (لا يُقرأ حدث التمرير)، فلا يتحرك شيء أثناء الكتابة والتمرير.
  (function () {
    var bar = doc.querySelector('.app-footer');
    if (!bar) { return; }
    var root = doc.documentElement, vv = window.visualViewport;
    var touch = !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
    // open: الشريط مخفي · seen: ظهرت لوحة المفاتيح لهذا الحقل · armed: ركّز المستخدم على حقل كتابة
    var open = false, seen = false, armed = false, base = 0, lastW = 0, queued = false;
    function typing() {
      var a = doc.activeElement;
      if (!a || a === doc.body) { return null; }
      if (a.isContentEditable) { return a; }
      if (a.tagName === 'TEXTAREA') { return a.readOnly ? null : a; }
      if (a.tagName !== 'INPUT' || a.readOnly) { return null; }
      return /^(checkbox|radio|button|submit|reset|range|color|file|image|hidden)$/i.test(a.type) ? null : a;
    }
    // ارتفاع المنطقة الظاهرة وعرضها بمقياس الشاشة: لا يتغيران بالتكبير، والارتفاع ينقص بظهور لوحة المفاتيح
    function visibleHeight() { return vv ? vv.height * (vv.scale || 1) : window.innerHeight; }
    function visibleWidth() { return vv ? vv.width * (vv.scale || 1) : window.innerWidth; }
    function set(hide) {
      if (hide !== open) { open = hide; root.classList.toggle('kb-open', hide); }
    }
    function update() {
      queued = false;
      var field = typing(), h = visibleHeight(), w = visibleWidth();
      if (Math.abs(w - lastW) > 40) {
        // أول قراءة أو تدوير الشاشة: ارتفاع مرجعي جديد، وتبقى حالة الشريط كما هي حتى تتضح
        lastW = w; base = h; seen = false;
        if (!field) { armed = false; set(false); }
        return;
      }
      if (h > base) { base = h; }
      if (!field) { armed = false; seen = false; set(false); return; }
      if (base - h >= 120) { seen = true; set(true); return; }
      // حقل نشط ولوحة المفاتيح غير ظاهرة بعد: في الأجهزة اللمسية تظهر بعد لحظة من التركيز فيُخفى الشريط فوراً،
      // ويعود إن أُغلقت لوحة المفاتيح مع بقاء الحقل نشطاً (زر الرجوع في Android)
      set(touch && armed && !seen);
    }
    function schedule() { if (!queued) { queued = true; requestAnimationFrame(update); } }
    if (vv) { vv.addEventListener('resize', schedule); } else { window.addEventListener('resize', schedule); }
    doc.addEventListener('focusin', function () { if (typing()) { armed = true; seen = false; } schedule(); });
    doc.addEventListener('focusout', function () { setTimeout(schedule, 0); });
    update();
  })();

  // ظهور العناصر المتتابع حسب نمط الحركة المختار
  function stagger() {
    var root = doc.documentElement;
    var mode = root.getAttribute('data-motion');
    if (!mode || mode === 'none' || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) { return; }
    var content = doc.querySelector('.content, .admin-content, .login-card');
    if (!content) { return; }
    var list = [];
    var pick = function (el) { if (el && list.indexOf(el) === -1 && el.offsetParent !== null) { list.push(el); } };
    Array.prototype.forEach.call(content.children, function (el) {
      if (el.matches('.stats, .grid, .stack, .admin-grid')) {
        Array.prototype.forEach.call(el.children, function (c) {
          if (c.matches('.stack')) { Array.prototype.forEach.call(c.children, pick); } else { pick(c); }
        });
      } else if (!el.matches('script, dialog, style, .app-footer')) { pick(el); }
    });
    // أول أربع كتل فقط وبتأخير صغير، وصفوف الجداول تظهر مع جدولها: المحتوى يظهر فوراً والحركة لمسة خفيفة لا تؤخر الصفحة
    // التأخير يُضبط على العنصر نفسه (animation-delay لا يُورَّث) لا بمتغير CSS يُعيد حساب أنماط كل ما بداخل الكتلة
    list.slice(0, 4).forEach(function (el, i) {
      if (i) { el.style.animationDelay = 'calc(' + i + ' * var(--motion-stagger, 20ms))'; }
      el.classList.add('mx');
    });
  }
  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', stagger); } else { stagger(); }

  // اهتزاز خفيف عند اللمس (الهواتف والتطبيق)
  if (doc.documentElement.getAttribute('data-haptics') === '1' && navigator.vibrate && window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
    doc.addEventListener('pointerdown', function (e) {
      if (e.target.closest('.btn, .icon-btn, .nav-link, .tab, .dropdown-item, .switch, .segmented label, [data-haptic]')) {
        try { navigator.vibrate(8); } catch (x) { /* غير مدعوم */ }
      }
    }, { passive: true });
  }

  // نافذة Madix
  on(doc, 'click', '[data-madix-open]', function (e) {
    e.preventDefault();
    var d = doc.getElementById('madix-about');
    if (d && typeof d.showModal === 'function' && !d.open) { d.showModal(); }
  });
  on(doc, 'click', '[data-madix-close]', function (e, el) { e.preventDefault(); el.closest('dialog').close(); });
  doc.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('madix-dialog')) { e.target.close(); }
  });

  // معاينة شعار الشبكة قبل الحفظ
  // شعار النظام (لوحة المسؤول): معاينة قبل الحفظ، وملف SVG يُرسم في المتصفح صورةَ PNG شفافة 1024×1024 قبل الرفع
  // (الخادم لا يقبل SVG في مجلد الرفع لأنه قد يحوي شيفرة)
  on(doc, 'change', '[data-brand-logo-input]', function (e, input) {
    var form = input.closest('[data-brand-logo]');
    var file = input.files && input.files[0];
    var save = $('[data-brand-logo-save]', form), name = $('[data-brand-logo-name]', form);
    if (!file) { return; }
    save.disabled = true;
    var show = function (src) { $$('[data-logo-prev]').forEach(function (img) { img.src = src; }); };
    var isSvg = /svg/i.test(file.type) || /\.svg$/i.test(file.name);
    if (!isSvg) {
      name.textContent = file.name;
      var r = new FileReader();
      r.onload = function () { show(r.result); save.disabled = false; };
      r.readAsDataURL(file);
      return;
    }
    var url = URL.createObjectURL(file), img = new Image();
    img.onload = function () {
      var size = 1024, w = img.naturalWidth || size, h = img.naturalHeight || size;
      var k = Math.min(size / w, size / h);
      var c = doc.createElement('canvas');
      c.width = c.height = size;
      c.getContext('2d').drawImage(img, (size - w * k) / 2, (size - h * k) / 2, w * k, h * k);
      URL.revokeObjectURL(url);
      c.toBlob(function (blob) {
        if (!blob || typeof DataTransfer === 'undefined') { toast('error', 'تعذّر تحويل الملف إلى صورة. ارفع الشعار بصيغة PNG.'); return; }
        var dt = new DataTransfer();
        dt.items.add(new File([blob], file.name.replace(/\.svg$/i, '') + '.png', { type: 'image/png' }));
        input.files = dt.files;
        name.textContent = file.name + ' → PNG';
        show(c.toDataURL('image/png'));
        save.disabled = false;
      }, 'image/png');
    };
    img.onerror = function () { URL.revokeObjectURL(url); toast('error', 'تعذّرت قراءة الملف.'); };
    img.src = url;
  });

  on(doc, 'change', '[data-logo-input]', function (e, input) {
    var box = input.closest('[data-logo-picker]');
    var file = input.files && input.files[0];
    if (!box || !file) { return; }
    var name = box.querySelector('[data-logo-name]');
    if (name) { name.textContent = file.name; }
    var reader = new FileReader();
    reader.onload = function () {
      var prev = box.querySelector('[data-logo-preview]');
      if (prev) { prev.innerHTML = '<span class="net-mark net-mark-lg has-logo"><img alt="" src="' + reader.result + '"></span>'; }
    };
    reader.readAsDataURL(file);
  });

  doc.addEventListener('DOMContentLoaded', function () {
    syncConditional();
    var flash = doc.getElementById('flash-data');
    if (flash) {
      try { JSON.parse(flash.textContent).forEach(function (f) { toast(f.type === 'error' ? 'error' : f.type, f.message); }); } catch (e) {}
    }
    $$('[data-bulk-scope]').forEach(refreshBulk);
    // تبويب من الرابط
    if (location.hash) {
      var t = $('[data-tabs-hash] [data-tab="' + location.hash.substr(1) + '"]');
      if (t) { t.click(); }
    }
    $$('[data-tabs] [data-tab].is-active').forEach(revealTab);
    revealActiveNav();
  });

  window.SN = {
    api: api, toast: toast, confirm: confirmDialog, openModal: openModal, closeModal: closeModal,
    esc: esc, icon: icon, fmtBytes: fmtBytes, fmtRate: fmtRate, fmtDuration: fmtDuration, fmtNum: fmtNum,
    chart: makeChart, copy: copyText, base: base, csrf: csrf, $: $, $$: $$, on: on, syncConditional: syncConditional
  };
})();
