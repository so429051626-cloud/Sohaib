/* ==========================================================================
   مصمّم قوالب طباعة الكروت
   ========================================================================== */
(function (window, document) {
  'use strict';

  var root = document.getElementById('designer');
  var cfgNode = document.getElementById('designer-config');
  if (!root || !cfgNode || !window.SNCard) { return; }

  var SN = window.SN;
  var cfg = JSON.parse(cfgNode.textContent);
  var MM = 96 / 25.4;
  var SNAP = 1.2;

  var t = cfg.template;
  var d = normalizeDesign(cfg.design);
  var sample = cfg.sample || {};
  var selected = null;
  var zoom = 1;
  var fitMode = true;
  var showGrid = false;
  var snapOn = true;
  var undoStack = [];
  var redoStack = [];
  var savedState = '';
  var cardNode = null;
  var previewTimer = null;

  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function round(v) { return Math.round(v * 10) / 10; }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function esc(s) { return SN ? SN.esc(s) : String(s); }
  function icon(n) { return SN ? SN.icon(n) : ''; }
  function toNum(v, def) {
    var s = String(v == null ? '' : v).replace(/[٠-٩]/g, function (c) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(c); })
      .replace(/[۰-۹]/g, function (c) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(c); }).replace('٫', '.').replace(',', '.');
    var n = parseFloat(s);
    return isFinite(n) ? n : def;
  }

  function normalizeDesign(src) {
    var out = JSON.parse(JSON.stringify(src || {}));
    out.card = out.card || {};
    out.elements = Array.isArray(out.elements) ? out.elements : [];
    return out;
  }

  function snapshot() { return JSON.stringify({ t: t, d: d }); }
  function element(id) {
    for (var i = 0; i < d.elements.length; i++) { if (d.elements[i].id === id) { return d.elements[i]; } }
    return null;
  }
  function indexOf(id) {
    for (var i = 0; i < d.elements.length; i++) { if (d.elements[i].id === id) { return i; } }
    return -1;
  }

  // ------------------------------------------------------------------------
  // السجل: تراجع وإعادة
  // ------------------------------------------------------------------------
  var lastState = snapshot();
  savedState = lastState;

  function commit() {
    var now = snapshot();
    if (now === lastState) { return; }
    undoStack.push(lastState);
    if (undoStack.length > 100) { undoStack.shift(); }
    redoStack = [];
    lastState = now;
    syncHistoryButtons();
  }

  function restore(state) {
    var s = JSON.parse(state);
    t = s.t; d = s.d;
    lastState = state;
    if (selected && !element(selected)) { selected = null; }
    fillPageForm();
    fillCardForm();
    renderAll();
  }

  function undo() {
    if (!undoStack.length) { return; }
    redoStack.push(lastState);
    restore(undoStack.pop());
    syncHistoryButtons();
  }

  function redo() {
    if (!redoStack.length) { return; }
    undoStack.push(lastState);
    restore(redoStack.pop());
    syncHistoryButtons();
  }

  function syncHistoryButtons() {
    $('[data-undo]').disabled = !undoStack.length;
    $('[data-redo]').disabled = !redoStack.length;
  }

  // ------------------------------------------------------------------------
  // الرسم
  // ------------------------------------------------------------------------
  var stage = $('[data-stage]');
  var zoomBox = $('[data-zoom-box]');

  function size() { return SNCard.cardSize(t); }

  function renderCard() {
    var s = size();
    cardNode = SNCard.render(d, sample, { width: s.w, height: s.h, base: cfg.base, logo: cfg.logo, editable: true });
    cardNode.classList.add('designer-card');
    if (showGrid) { cardNode.classList.add('show-grid'); }
    zoomBox.innerHTML = '';
    zoomBox.appendChild(cardNode);
    SNCard.fitText(cardNode);
    applyZoom();
    markSelection();
    $('[data-card-size]').innerHTML = '<strong>' + ltr(fmt(s.w) + ' × ' + fmt(s.h)) + '</strong> ' + 'مم';
  }

  function fmt(n) { return String(Math.round(n * 10) / 10); }
  function ltr(s) { return '⁦' + s + '⁩'; }

  function applyZoom() {
    if (!cardNode) { return; }
    var s = size();
    var w = s.w * MM, h = s.h * MM;
    if (fitMode) {
      var avail = Math.max(200, stage.clientWidth - 48);
      zoom = clamp(Math.min(avail / w, 440 / h, 4), 0.25, 4);
    }
    zoomBox.style.width = (w * zoom) + 'px';
    zoomBox.style.height = (h * zoom) + 'px';
    cardNode.style.transform = 'scale(' + zoom + ')';
    cardNode.style.transformOrigin = '0 0';
    cardNode.style.setProperty('--z', zoom);
    $('[data-zoom-label]').textContent = Math.round(zoom * 100) + '%';
  }

  function markSelection() {
    if (!cardNode) { return; }
    $$('.sn-el', cardNode).forEach(function (n) {
      var on = n.getAttribute('data-id') === selected;
      n.classList.toggle('is-selected', on);
      $$('.sn-handle', n).forEach(function (h) { h.parentNode.removeChild(h); });
      if (on) {
        var el = element(selected);
        n.appendChild(handle('e'));
        if (el && el.type === 'rect') { n.appendChild(handle('s')); }
      }
    });
  }

  function handle(dir) {
    var h = document.createElement('span');
    h.className = 'sn-handle h-' + dir;
    h.setAttribute('data-handle', dir);
    return h;
  }

  function renderPreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(function () {
      var box = $('[data-page-preview]');
      var inner = $('[data-page-inner]');
      var per = t.cols * t.rows;
      var list = [];
      for (var i = 0; i < per; i++) { list.push(sample); }
      var pages = SNCard.pages(t, d, list, { base: cfg.base, logo: cfg.logo });
      inner.innerHTML = '';
      if (!pages.length) { return; }
      var page = pages[0];
      var pw = t.paper_width * MM, ph = t.paper_height * MM;
      var z = Math.min((box.clientWidth - 28) / pw, 620 / ph, 1);
      inner.style.width = (pw * z) + 'px';
      inner.style.height = (ph * z) + 'px';
      inner.style.direction = 'ltr';
      page.style.transform = 'scale(' + z + ')';
      page.style.transformOrigin = '0 0';
      inner.appendChild(page);
      SNCard.fitText(page);
      $('[data-page-info]').innerHTML = '<strong>' + ltr(String(per)) + '</strong> كرت في الصفحة · ' + ltr(t.cols + ' × ' + t.rows);
    }, 120);
  }

  var TYPE_LABELS = { text: 'نص', qr: 'رمز QR', logo: 'الشعار', image: 'صورة', rect: 'مستطيل' };
  var TYPE_ICONS = { text: 'type', qr: 'qr-code', logo: 'image', image: 'image', rect: 'square' };

  function layerLabel(el) {
    if (el.type === 'text') {
      var txt = (el.text || '').replace(/\{(\w+)\}/g, function (m, k) { return cfg.fields[k] ? '«' + cfg.fields[k] + '»' : m; });
      return txt.length > 40 ? txt.substr(0, 40) + '…' : (txt || TYPE_LABELS.text);
    }
    return TYPE_LABELS[el.type] || el.type;
  }

  function renderLayers() {
    var ul = $('[data-layers]');
    if (!d.elements.length) { ul.innerHTML = '<li class="list-item text-muted text-sm">لا توجد عناصر</li>'; return; }
    var html = '';
    for (var i = d.elements.length - 1; i >= 0; i--) {
      var el = d.elements[i];
      html += '<li class="list-item' + (el.id === selected ? ' is-selected' : '') + '" data-layer="' + esc(el.id) + '">'
        + icon(TYPE_ICONS[el.type] || 'square')
        + '<span class="list-item-title truncate">' + esc(layerLabel(el)) + '</span>'
        + '<button type="button" class="btn btn-xs btn-ghost btn-icon" data-layer-act="up" title="للأمام"' + (i === d.elements.length - 1 ? ' disabled' : '') + '>' + icon('chevron-up') + '</button>'
        + '<button type="button" class="btn btn-xs btn-ghost btn-icon" data-layer-act="down" title="للخلف"' + (i === 0 ? ' disabled' : '') + '>' + icon('chevron-down') + '</button>'
        + '<button type="button" class="btn btn-xs btn-ghost btn-icon" data-layer-act="delete" title="حذف">' + icon('trash-2') + '</button>'
        + '</li>';
    }
    ul.innerHTML = html;
  }

  function fieldOptions() {
    var html = '<option value="">إدراج متغير…</option>';
    Object.keys(cfg.fields).forEach(function (k) { html += '<option value="' + esc(k) + '">' + esc(cfg.fields[k]) + '</option>'; });
    return html;
  }

  function numField(label, prop, val, extra) {
    return '<div class="field"><label>' + label + '</label><input class="input" data-p="' + prop + '" value="' + esc(fmt(val || 0)) + '" inputmode="decimal"' + (extra || '') + '></div>';
  }
  function colorField(label, prop, val) {
    return '<div class="field"><label>' + label + '</label><input type="color" class="color-input" data-p="' + prop + '" value="' + esc(val || '#111111') + '"></div>';
  }
  function selectField(label, prop, val, options) {
    var html = '<div class="field"><label>' + label + '</label><select class="input" data-p="' + prop + '">';
    options.forEach(function (o) { html += '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; });
    return html + '</select></div>';
  }

  function renderProps() {
    var box = $('[data-props]');
    var el = selected ? element(selected) : null;
    if (!el) { box.innerHTML = ''; return; }
    var h = '<div class="label mb-1">' + esc(TYPE_LABELS[el.type] || '') + '</div><div class="prop-grid">';
    if (el.type === 'text') {
      h += '<div class="field full"><textarea class="input" data-p="text" rows="2" dir="auto" style="min-height:62px">' + esc(el.text || '') + '</textarea></div>';
      h += '<div class="field full"><select class="input" data-insert>' + fieldOptions() + '</select></div>';
      h += numField('حجم الخط (pt)', 'size', el.size);
      h += selectField('السماكة', 'weight', el.weight || 400, [[400, 'عادي'], [500, 'متوسط'], [600, 'شبه عريض'], [700, 'عريض']]);
      h += colorField('اللون', 'color', el.color);
      h += selectField('المحاذاة', 'align', el.align || 'center', [['right', 'يمين'], ['center', 'وسط'], ['left', 'يسار']]);
      h += selectField('نوع الخط', 'font', el.font || 'sans', [['sans', 'عادي'], ['mono', 'أرقام متساوية العرض']]);
      h += numField('تباعد الأحرف', 'ls', el.ls);
      h += '<label class="check full"><input type="checkbox" data-p="fit"' + (el.fit !== false ? ' checked' : '') + '>تصغير النص تلقائياً ليلائم العرض</label>';
      h += '<label class="check"><input type="checkbox" data-p="hasBg"' + (el.bg ? ' checked' : '') + '>خلفية للنص</label>';
      h += colorField('لون الخلفية', 'bg', el.bg || '#f1f5f9');
      h += numField('استدارة الخلفية (مم)', 'radius', el.radius);
    } else if (el.type === 'qr') {
      h += '<div class="field full"><label>محتوى الرمز</label><input class="input" data-p="data" dir="ltr" value="' + esc(el.data || '') + '"></div>';
      h += '<div class="field full"><select class="input" data-insert>' + fieldOptions() + '</select></div>';
      h += colorField('اللون', 'color', el.color || '#000000');
    } else if (el.type === 'image') {
      h += '<div class="field full"><label>الصورة</label><input type="file" class="input" accept="image/png,image/jpeg,image/webp" data-img-upload style="padding-top:5px"></div>';
    } else if (el.type === 'rect') {
      h += colorField('اللون', 'bg', el.bg || '#e2e8f0');
      h += numField('الاستدارة (مم)', 'radius', el.radius);
    }
    h += numField('الموضع الأفقي %', 'x', el.x);
    h += numField('الموضع الرأسي %', 'y', el.y);
    h += numField('العرض %', 'w', el.w);
    if (el.type === 'rect') { h += numField('الارتفاع %', 'h', el.h); }
    h += '</div><div class="flex gap-1 mt-2" style="flex-wrap:wrap">'
      + '<button type="button" class="btn btn-sm" data-act="center-x" title="توسيط أفقي">' + icon('align-center') + 'توسيط</button>'
      + '<button type="button" class="btn btn-sm" data-act="duplicate" title="Ctrl+D">' + icon('copy') + 'نسخ</button>'
      + '<button type="button" class="btn btn-sm btn-danger" data-act="delete" title="Delete">' + icon('trash-2') + 'حذف</button>'
      + '</div><hr class="divider" style="margin:14px 0 0">';
    box.innerHTML = h;
  }

  function renderSizeInfo() {
    var s = size();
    var info = $('[data-size-info]');
    var tooSmall = s.w < 10 || s.h < 8;
    info.innerHTML = 'مقاس الكرت <strong>' + ltr(fmt(s.w) + ' × ' + fmt(s.h)) + '</strong> مم · '
      + '<strong>' + ltr(String(t.cols * t.rows)) + '</strong> كرت في الصفحة'
      + (tooSmall ? ' · <span class="text-danger">المقاس صغير جداً</span>' : '');
  }

  function renderAll() {
    renderCard();
    renderLayers();
    renderProps();
    renderSizeInfo();
    renderPreview();
  }

  // تحديث خفيف بعد تعديل خاصية دون إعادة بناء لوحة الخصائص
  function refresh() {
    renderCard();
    renderLayers();
    renderPreview();
  }

  // ------------------------------------------------------------------------
  // نماذج الصفحة والتصميم
  // ------------------------------------------------------------------------
  function fillPageForm() {
    var paperSel = $('[data-t="paper"]');
    if (!paperSel.options.length) {
      Object.keys(cfg.papers).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = cfg.papers[k].label + (cfg.papers[k].w ? ' (' + cfg.papers[k].w + '×' + cfg.papers[k].h + ')' : '');
        paperSel.appendChild(o);
      });
    }
    t.orientation = t.paper_width > t.paper_height ? 'landscape' : 'portrait';
    $$('[data-t]').forEach(function (input) {
      var k = input.getAttribute('data-t');
      if (input.type === 'checkbox') { input.checked = !!Number(t[k]); }
      else { input.value = t[k] == null ? '' : (typeof t[k] === 'number' ? fmt(t[k]) : t[k]); }
    });
    $$('[data-custom-paper]').forEach(function (n) { n.style.display = t.paper === 'custom' ? '' : 'none'; });
  }

  function fillCardForm() {
    var c = d.card;
    $$('[data-c]').forEach(function (input) {
      var k = input.getAttribute('data-c');
      if (input.type === 'checkbox') { input.checked = !!c[k]; }
      else if (input.type === 'color') { input.value = c[k] || (k === 'bg' ? '#ffffff' : '#94a3b8'); }
      else { input.value = fmt(c[k] || 0); }
    });
    $('[data-bg-remove]').disabled = !c.image;
  }

  function orient(o) {
    var land = t.paper_width > t.paper_height;
    if ((o === 'landscape') !== land && t.paper_width !== t.paper_height) {
      var w = t.paper_width; t.paper_width = t.paper_height; t.paper_height = w;
    }
    t.orientation = o;
  }

  function onPageInput(input, final) {
    var k = input.getAttribute('data-t');
    if (k === 'name') { t.name = input.value; }
    else if (k === 'is_default') { t.is_default = input.checked ? 1 : 0; }
    else if (k === 'category_id') { t.category_id = input.value; }
    else if (k === 'paper') {
      t.paper = input.value;
      var p = cfg.papers[t.paper];
      if (p && p.w) { t.paper_width = p.w; t.paper_height = p.h; orient(t.orientation || 'portrait'); }
      fillPageForm();
    } else if (k === 'orientation') {
      orient(input.value);
      fillPageForm();
    } else {
      var limits = { paper_width: [30, 1000], paper_height: [30, 1000], cols: [1, 20], rows: [1, 40], gap_x: [0, 50], gap_y: [0, 50] };
      var lim = limits[k] || [0, 100];
      var v = toNum(input.value, null);
      if (v === null) { if (final) { input.value = fmt(t[k]); } return; }
      v = clamp(k === 'cols' || k === 'rows' ? Math.round(v) : v, lim[0], lim[1]);
      t[k] = v;
      if (final) { input.value = fmt(v); }
      if (k === 'paper_width' || k === 'paper_height') { t.orientation = t.paper_width > t.paper_height ? 'landscape' : 'portrait'; $('[data-t="orientation"]').value = t.orientation; }
    }
    if (k !== 'name' && k !== 'is_default' && k !== 'category_id') {
      if (fitMode) { applyZoom(); }
      renderCard();
      renderSizeInfo();
      renderPreview();
    }
    if (final) { commit(); }
  }

  function onCardInput(input, final) {
    var k = input.getAttribute('data-c');
    if (input.type === 'checkbox') { d.card[k] = input.checked; }
    else if (input.type === 'color') { d.card[k] = input.value; }
    else {
      var v = toNum(input.value, null);
      if (v === null) { return; }
      d.card[k] = clamp(v, 0, 20);
      if (final) { input.value = fmt(d.card[k]); }
    }
    renderCard();
    renderPreview();
    if (final) { commit(); }
  }

  root.addEventListener('input', function (e) {
    var n = e.target;
    if (n.hasAttribute('data-t') && n.type !== 'checkbox' && n.tagName !== 'SELECT') { onPageInput(n, false); }
    else if (n.hasAttribute('data-c') && n.type !== 'checkbox') { onCardInput(n, false); }
    else if (n.hasAttribute('data-p') && n.type !== 'checkbox' && n.tagName !== 'SELECT') { onPropInput(n, false); }
  });

  root.addEventListener('change', function (e) {
    var n = e.target;
    if (n.hasAttribute('data-t')) { onPageInput(n, true); }
    else if (n.hasAttribute('data-c')) { onCardInput(n, true); }
    else if (n.hasAttribute('data-p')) { onPropInput(n, true); }
    else if (n.hasAttribute('data-insert')) { insertField(n); }
    else if (n.hasAttribute('data-bg-upload')) { upload(n, function (path) { d.card.image = path; fillCardForm(); refresh(); commit(); }); }
    else if (n.hasAttribute('data-img-upload')) {
      var id = selected;
      upload(n, function (path) { var el = element(id); if (el) { el.src = path; refresh(); commit(); } });
    } else if (n.hasAttribute('data-grid')) { showGrid = n.checked; renderCard(); }
    else if (n.hasAttribute('data-snap')) { snapOn = n.checked; }
  });

  function onPropInput(input, final) {
    var el = element(selected);
    if (!el) { return; }
    var k = input.getAttribute('data-p');
    if (k === 'text' || k === 'data') { el[k] = input.value; }
    else if (k === 'fit') { el.fit = input.checked; }
    else if (k === 'hasBg') { el.bg = input.checked ? ($('[data-p="bg"]').value || '#f1f5f9') : ''; }
    else if (input.type === 'color') {
      if (k === 'bg' && el.type === 'text') {
        var cb = $('[data-p="hasBg"]');
        if (cb) { cb.checked = true; }
      }
      el[k] = input.value;
    } else if (input.tagName === 'SELECT') {
      el[k] = k === 'weight' ? parseInt(input.value, 10) : input.value;
    } else {
      var lim = { x: [-50, 150], y: [-50, 150], w: [1, 200], h: [0.5, 200], size: [3, 72], ls: [-2, 10], radius: [0, 50] }[k] || [0, 100];
      var v = toNum(input.value, null);
      if (v === null) { if (final) { input.value = fmt(el[k] || 0); } return; }
      el[k] = clamp(round(v), lim[0], lim[1]);
      if (final) { input.value = fmt(el[k]); }
    }
    refresh();
    if (final) { commit(); }
  }

  function insertField(sel) {
    var key = sel.value;
    sel.value = '';
    var el = element(selected);
    if (!key || !el) { return; }
    var prop = el.type === 'qr' ? 'data' : 'text';
    var input = $('[data-p="' + prop + '"]');
    var token = '{' + key + '}';
    var val = input.value;
    var start = typeof input.selectionStart === 'number' ? input.selectionStart : val.length;
    var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : val.length;
    input.value = val.slice(0, start) + token + val.slice(end);
    el[prop] = input.value;
    input.focus();
    try { input.setSelectionRange(start + token.length, start + token.length); } catch (err) { /* حقل لا يدعم التحديد */ }
    refresh();
    commit();
  }

  function upload(input, done) {
    var file = input.files && input.files[0];
    if (!file) { return; }
    var fd = new FormData();
    fd.append('image', file);
    input.disabled = true;
    SN.api(cfg.uploadUrl, { method: 'POST', form: fd })
      .then(function (res) { done(res.path); })
      .catch(function () {})
      .then(function () { input.disabled = false; input.value = ''; });
  }

  // ------------------------------------------------------------------------
  // العناصر
  // ------------------------------------------------------------------------
  var PRESETS = {
    username: { type: 'text', text: '{username}', size: 12, weight: 700, font: 'mono', ls: 1, w: 90, x: 5 },
    password: { type: 'text', text: '{password}', size: 10, weight: 600, font: 'mono', w: 90, x: 5 },
    userpass: { type: 'text', text: '{username} / {password}', size: 10, weight: 700, font: 'mono', w: 90, x: 5 },
    short: { type: 'text', text: '{short}', size: 15, weight: 700, font: 'mono', ls: 2, w: 60, x: 20 },
    shortpass: { type: 'text', text: '{short_password}', size: 12, weight: 700, font: 'mono', ls: 2, w: 50, x: 25 },
    price: { type: 'text', text: '{price}', size: 8, weight: 700, w: 40, x: 55 },
    category: { type: 'text', text: '{category}', size: 7, weight: 600, w: 60, x: 20 },
    validity: { type: 'text', text: '{validity}', size: 7, weight: 500, w: 60, x: 20 },
    serial: { type: 'text', text: '#{serial}', size: 6, weight: 400, font: 'mono', color: '#64748b', w: 30, x: 66 },
    network: { type: 'text', text: '{network}', size: 8, weight: 700, w: 60, x: 20 },
    text: { type: 'text', text: 'نص', size: 8, weight: 500, w: 50, x: 25 },
    qr: { type: 'qr', data: '{username}', color: '#000000', w: 22, x: 74, y: 30 },
    logo: { type: 'logo', w: 20, x: 4, y: 4 },
    image: { type: 'image', src: null, w: 30, x: 35, y: 30 },
    rect: { type: 'rect', w: 100, h: 20, x: 0, y: 80, bg: '#e2e8f0', radius: 0 }
  };

  function nextId() {
    var max = 0;
    d.elements.forEach(function (el) { var m = /^e(\d+)$/.exec(el.id); if (m) { max = Math.max(max, +m[1]); } });
    return 'e' + (max + 1);
  }

  function addElement(kind) {
    var p = PRESETS[kind];
    if (!p) { return; }
    var el = JSON.parse(JSON.stringify(p));
    el.id = nextId();
    if (el.type === 'text') {
      el.color = el.color || '#0f172a';
      el.align = 'center';
      el.font = el.font || 'sans';
      el.ls = el.ls || 0;
      el.bg = '';
      el.radius = 0;
    }
    if (el.y == null) {
      // وضع العنصر في أول مساحة رأسية خالية تقريباً
      var y = 40, tries = 0;
      while (tries++ < 12 && d.elements.some(function (o) { return o.type !== 'rect' && Math.abs((o.y || 0) - y) < 8; })) { y = (y + 12) % 84; }
      el.y = y;
    }
    if (el.type === 'rect') {
      d.elements.unshift(el);
    } else {
      d.elements.push(el);
    }
    selected = el.id;
    renderAll();
    commit();
  }

  function removeElement(id) {
    var i = indexOf(id);
    if (i < 0) { return; }
    d.elements.splice(i, 1);
    if (selected === id) { selected = null; }
    renderAll();
    commit();
  }

  function duplicateElement(id) {
    var i = indexOf(id);
    if (i < 0) { return; }
    var copy = JSON.parse(JSON.stringify(d.elements[i]));
    copy.id = nextId();
    copy.x = round(copy.x + 3);
    copy.y = round(copy.y + 6);
    d.elements.splice(i + 1, 0, copy);
    selected = copy.id;
    renderAll();
    commit();
  }

  function moveLayer(id, dir) {
    var i = indexOf(id), j = i + dir;
    if (i < 0 || j < 0 || j >= d.elements.length) { return; }
    var tmp = d.elements[i]; d.elements[i] = d.elements[j]; d.elements[j] = tmp;
    refresh();
    commit();
  }

  function select(id) {
    selected = id;
    markSelection();
    renderLayers();
    renderProps();
    if (id) {
      var tab = $('[data-tab="elements"]');
      if (tab && !tab.classList.contains('is-active')) { tab.click(); }
    }
  }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || !root.contains(b)) { return; }
    if (b.hasAttribute('data-add')) { addElement(b.getAttribute('data-add')); }
    else if (b.hasAttribute('data-act') && selected) {
      var act = b.getAttribute('data-act');
      if (act === 'delete') { removeElement(selected); }
      else if (act === 'duplicate') { duplicateElement(selected); }
      else if (act === 'center-x') {
        var el = element(selected);
        el.x = round((100 - el.w) / 2);
        refresh(); renderProps(); commit();
      }
    } else if (b.hasAttribute('data-layer-act')) {
      var id = b.closest('[data-layer]').getAttribute('data-layer');
      var a = b.getAttribute('data-layer-act');
      if (a === 'delete') { removeElement(id); }
      else { moveLayer(id, a === 'up' ? 1 : -1); }
    } else if (b.hasAttribute('data-bg-remove')) {
      d.card.image = null; fillCardForm(); refresh(); commit();
    } else if (b.hasAttribute('data-zoom')) {
      var z = parseInt(b.getAttribute('data-zoom'), 10);
      if (z === 0) { fitMode = true; }
      else { fitMode = false; zoom = clamp(Math.round((zoom + z * 0.25) * 4) / 4, 0.25, 6); }
      applyZoom();
    } else if (b.hasAttribute('data-undo')) { undo(); }
    else if (b.hasAttribute('data-redo')) { redo(); }
  });

  root.addEventListener('click', function (e) {
    var li = e.target.closest('[data-layer]');
    if (li && !e.target.closest('button')) { select(li.getAttribute('data-layer')); }
  });

  // ------------------------------------------------------------------------
  // السحب وتغيير الحجم
  // ------------------------------------------------------------------------
  var drag = null;

  function guide(dir, pos) {
    var g = document.createElement('div');
    g.className = 'designer-guide ' + dir;
    g.style[dir === 'v' ? 'left' : 'top'] = pos + '%';
    cardNode.appendChild(g);
  }

  function clearGuides() {
    if (cardNode) { $$('.designer-guide', cardNode).forEach(function (g) { g.parentNode.removeChild(g); }); }
  }

  function heightPct(node) {
    return cardNode.offsetHeight ? node.offsetHeight / cardNode.offsetHeight * 100 : 0;
  }

  /** محاذاة قيمة لأقرب خط من الخطوط المرشّحة */
  function snapAxis(edges, lines) {
    var best = null;
    edges.forEach(function (edge) {
      lines.forEach(function (line) {
        var dist = line - edge.v;
        if (Math.abs(dist) <= SNAP && (!best || Math.abs(dist) < Math.abs(best.d))) { best = { d: dist, line: line }; }
      });
    });
    return best;
  }

  stage.addEventListener('pointerdown', function (e) {
    if (e.button !== 0) { return; }
    var node = e.target.closest('.sn-el');
    if (!node || !cardNode || !cardNode.contains(node)) {
      if (selected) { select(null); }
      return;
    }
    e.preventDefault();
    var id = node.getAttribute('data-id');
    if (selected !== id) { select(id); }
    var el = element(id);
    var h = e.target.getAttribute('data-handle');
    var vLines = [0, 50, 100], hLines = [0, 50, 100];
    $$('.sn-el', cardNode).forEach(function (n) {
      if (n === node) { return; }
      var o = element(n.getAttribute('data-id'));
      if (!o) { return; }
      var oh = o.type === 'rect' ? o.h : heightPct(n);
      vLines.push(o.x, o.x + o.w / 2, o.x + o.w);
      hLines.push(o.y, o.y + oh / 2, o.y + oh);
    });
    drag = {
      id: id, node: node, mode: h || 'move', x0: e.clientX, y0: e.clientY,
      rect: cardNode.getBoundingClientRect(), start: { x: el.x, y: el.y, w: el.w, h: el.h || 0 },
      hp: el.type === 'rect' ? el.h : heightPct(node), vLines: vLines, hLines: hLines, moved: false
    };
    try { stage.setPointerCapture(e.pointerId); } catch (err) { /* غير مدعوم */ }
  });

  stage.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    var dx = e.clientX - drag.x0, dy = e.clientY - drag.y0;
    if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 3) { return; }
    drag.moved = true;
    var px = dx / drag.rect.width * 100, py = dy / drag.rect.height * 100;
    var el = element(drag.id);
    var snap = snapOn && !e.shiftKey;
    clearGuides();
    if (drag.mode === 'move') {
      var nx = drag.start.x + px, ny = drag.start.y + py;
      if (snap) {
        var sx = snapAxis([{ v: nx }, { v: nx + el.w / 2 }, { v: nx + el.w }], drag.vLines);
        var sy = snapAxis([{ v: ny }, { v: ny + drag.hp / 2 }, { v: ny + drag.hp }], drag.hLines);
        if (sx) { nx += sx.d; guide('v', sx.line); } else { nx = Math.round(nx * 2) / 2; }
        if (sy) { ny += sy.d; guide('h', sy.line); } else { ny = Math.round(ny * 2) / 2; }
      }
      // إبقاء العنصر داخل حدود الكرت
      el.x = clamp(round(nx), 0, Math.max(0, round(100 - el.w)));
      el.y = clamp(round(ny), 0, Math.max(0, round(100 - drag.hp)));
      drag.node.style.left = el.x + '%';
      drag.node.style.top = el.y + '%';
    } else if (drag.mode === 'e') {
      var nw = drag.start.w + px;
      if (snap) {
        var se = snapAxis([{ v: el.x + nw }], drag.vLines);
        if (se) { nw += se.d; guide('v', se.line); } else { nw = Math.round(nw * 2) / 2; }
      }
      el.w = clamp(round(nw), 2, Math.max(2, round(100 - el.x)));
      drag.node.style.width = el.w + '%';
    } else if (drag.mode === 's') {
      var nh = drag.start.h + py;
      if (snap) {
        var ss = snapAxis([{ v: el.y + nh }], drag.hLines);
        if (ss) { nh += ss.d; guide('h', ss.line); } else { nh = Math.round(nh * 2) / 2; }
      }
      el.h = clamp(round(nh), 0.5, Math.max(0.5, round(100 - el.y)));
      drag.node.style.height = el.h + '%';
    }
  });

  function endDrag() {
    if (!drag) { return; }
    var moved = drag.moved;
    drag = null;
    clearGuides();
    if (moved) {
      refresh();
      renderProps();
      commit();
    }
  }
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
  stage.addEventListener('lostpointercapture', endDrag);

  // ------------------------------------------------------------------------
  // لوحة المفاتيح
  // ------------------------------------------------------------------------
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
    var mod = e.ctrlKey || e.metaKey;
    if (mod && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(); return; }
    if (typing || document.querySelector('.modal.is-open, dialog[open]')) { return; }
    if (mod && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) { e.preventDefault(); undo(); return; }
    if (mod && (e.key === 'y' || e.key === 'Y' || ((e.key === 'z' || e.key === 'Z') && e.shiftKey))) { e.preventDefault(); redo(); return; }
    if (!selected) { return; }
    var el = element(selected);
    if (!el) { return; }
    if (mod && (e.key === 'd' || e.key === 'D')) { e.preventDefault(); duplicateElement(selected); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeElement(selected); return; }
    if (e.key === 'Escape') { select(null); return; }
    var step = e.shiftKey ? 2 : 0.5;
    var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[e.key]) {
      e.preventDefault();
      el.x = clamp(round(el.x + moves[e.key][0]), -50, 150);
      el.y = clamp(round(el.y + moves[e.key][1]), -50, 150);
      refresh();
      renderProps();
      clearTimeout(keyCommitTimer);
      keyCommitTimer = setTimeout(commit, 400);
    }
  });
  var keyCommitTimer = null;

  // ------------------------------------------------------------------------
  // الحفظ
  // ------------------------------------------------------------------------
  var saving = false;
  function save() {
    if (saving) { return; }
    var name = String(t.name || '').trim();
    if (!name) {
      $('[data-tab="page"]').click();
      $('[data-t="name"]').focus();
      SN.toast('error', 'أدخل اسماً للقالب.');
      return;
    }
    saving = true;
    var btns = $$('[data-save]', document);
    btns.forEach(function (b) { b.disabled = true; });
    var payload = JSON.parse(JSON.stringify(t));
    payload.name = name;
    payload.design = d;
    SN.api(cfg.saveUrl, { method: 'POST', data: payload })
      .then(function (res) {
        if (!t.id && res.id) {
          t.id = res.id;
          history.replaceState(null, '', cfg.editUrl + res.id);
          document.title = document.title.replace('قالب طباعة جديد', 'تصميم القالب: ' + name);
        }
        lastState = snapshot();
        savedState = lastState;
        SN.toast('success', res.message || 'تم حفظ القالب.');
      })
      .catch(function () {})
      .then(function () { saving = false; btns.forEach(function (b) { b.disabled = false; }); });
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-save]')) { e.preventDefault(); save(); }
  });

  window.addEventListener('beforeunload', function (e) {
    if (snapshot() !== savedState) { e.preventDefault(); e.returnValue = ''; }
  });

  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () { if (fitMode) { applyZoom(); } renderPreview(); }, 150);
  });

  // ------------------------------------------------------------------------
  // البدء
  // ------------------------------------------------------------------------
  fillPageForm();
  fillCardForm();
  $('[data-snap]').checked = snapOn;
  $('[data-grid]').checked = showGrid;
  renderAll();
  syncHistoryButtons();
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { renderCard(); renderPreview(); });
  }
})(window, document);
