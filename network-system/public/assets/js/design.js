/* صفحة التصاميم في لوحة المسؤول: التصاميم الجاهزة والمعاينة الحيّة */
(function (document, window) {
  'use strict';
  var form = document.querySelector('[data-design-form]');
  if (!form) { return; }
  var presets = {};
  try { presets = JSON.parse((form.querySelector('[data-design-presets]') || {}).textContent || '{}'); } catch (e) { presets = {}; }
  var FIELDS = ['skin', 'palette', 'font', 'font_latin', 'radius', 'density', 'sidebar', 'sidebar_shape', 'nav_style', 'topbar', 'background', 'buttons', 'font_size', 'motion'];
  var NAMES = {};
  Array.prototype.forEach.call(form.querySelectorAll('[data-preset]'), function (b) {
    NAMES[b.getAttribute('data-preset')] = (b.querySelector('.dz-preset-text b') || {}).textContent || '';
  });

  var stage = form.querySelector('[data-pv-stage]');
  var wrap = form.querySelector('[data-pv-frame-wrap]');
  var frame = form.querySelector('[data-pv-frame]');
  var loading = form.querySelector('[data-pv-loading]');
  var state = form.querySelector('[data-preset-state]');
  var base = form.getAttribute('data-preview-url');

  function val(name) {
    var el = form.querySelector('[name="' + name + '"]:checked');
    if (el) { return el.value; }
    el = form.querySelector('[name="' + name + '"]');
    if (!el) { return ''; }
    if (el.type === 'checkbox') { return el.checked ? '1' : '0'; }
    return el.value;
  }
  function setVal(name, v) {
    var el = form.querySelector('[name="' + name + '"][value="' + v + '"]');
    if (el) { el.checked = true; }
  }
  function current() {
    var o = {};
    FIELDS.forEach(function (f) { o[f] = val(f); });
    return o;
  }
  function matchPreset(o) {
    for (var k in presets) {
      if (!Object.prototype.hasOwnProperty.call(presets, k)) { continue; }
      var p = presets[k], same = true;
      for (var f in p) { if (Object.prototype.hasOwnProperty.call(p, f) && o[f] !== p[f]) { same = false; break; } }
      if (same) { return k; }
    }
    return 'custom';
  }
  function markPreset() {
    var k = matchPreset(current());
    Array.prototype.forEach.call(form.querySelectorAll('[data-preset]'), function (b) {
      var on = b.getAttribute('data-preset') === k;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (state) { state.textContent = k === 'custom' ? 'تصميم مخصص' : 'المختار: ' + (NAMES[k] || k); }
  }

  // ---------------- المعاينة ----------------
  var mode = 'light', device = 'desktop', timer = null, lastUrl = '';
  var DIM = { desktop: [1280, 820], mobile: [390, 780] };
  function previewUrl() {
    var q = [];
    FIELDS.concat(['stagger', 'speed', 'page_fx', 'haptics', 'locale']).forEach(function (f) { q.push(encodeURIComponent(f) + '=' + encodeURIComponent(val(f))); });
    q.push('mode=' + mode);
    return base + '?' + q.join('&');
  }
  function fit() {
    if (!stage || !wrap) { return; }
    var d = DIM[device], avail = stage.clientWidth;
    if (!avail) { return; }
    var maxH = device === 'mobile' ? 640 : 99999;
    var s = Math.min(1, avail / d[0], maxH / d[1]);
    wrap.style.width = d[0] + 'px';
    wrap.style.height = d[1] + 'px';
    wrap.style.transform = 'scale(' + s + ')';
    stage.style.height = Math.ceil(d[1] * s) + 'px';
    stage.classList.toggle('is-mobile', device === 'mobile');
    wrap.style.insetInlineStart = device === 'mobile' ? Math.max(0, (avail - d[0] * s) / 2) + 'px' : '0px';
  }
  function load() {
    if (!frame || !base) { return; }
    var url = previewUrl();
    if (url === lastUrl) { return; }
    lastUrl = url;
    if (loading) { loading.classList.add('is-on'); }
    frame.src = url;
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(load, 220);
  }
  if (frame) {
    frame.addEventListener('load', function () { if (loading) { loading.classList.remove('is-on'); } });
  }

  form.addEventListener('change', function (e) {
    var n = e.target.name || '';
    if (n === '_pv_mode') { mode = e.target.value; lastUrl = ''; load(); return; }
    if (n === '_pv_device') { device = e.target.value; fit(); return; }
    markPreset();
    schedule();
  });
  form.addEventListener('input', function (e) {
    if (e.target.name === 'stagger' || e.target.name === 'speed') { schedule(); }
  });
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-preset]');
    if (!b) { return; }
    var p = presets[b.getAttribute('data-preset')];
    if (!p) { return; }
    for (var f in p) { if (Object.prototype.hasOwnProperty.call(p, f)) { setVal(f, p[f]); } }
    markPreset();
    load();
    // على الهاتف: إظهار المعاينة بعد اختيار التصميم
    if (window.innerWidth < 1100 && stage) {
      var r = stage.getBoundingClientRect();
      if (r.top > window.innerHeight || r.bottom < 0) { stage.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
  });
  // منع إرسال حقول المعاينة الداخلية مع النموذج
  form.addEventListener('submit', function () {
    Array.prototype.forEach.call(form.querySelectorAll('[name^="_pv_"]'), function (el) { el.disabled = true; });
  });
  window.addEventListener('pageshow', function () {
    Array.prototype.forEach.call(form.querySelectorAll('[name^="_pv_"]'), function (el) { el.disabled = false; });
  });

  var rq = false;
  window.addEventListener('resize', function () {
    if (rq) { return; }
    rq = true;
    requestAnimationFrame(function () { rq = false; fit(); });
  });
  if (window.ResizeObserver && stage) { new ResizeObserver(function () { fit(); }).observe(stage); }
  markPreset();
  fit();
  load();
})(document, window);
