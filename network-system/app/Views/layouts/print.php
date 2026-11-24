<?php
/** @var App\Core\View $v */
use App\Core\Auth;
use App\Core\Settings;

$user = Auth::user();
// الوضع فاتح دائماً ما لم يختر المستخدم الداكن بنفسه (لا يتبع إعداد الجهاز)
$themePref = ($user['theme'] ?? '') === 'dark' ? 'dark' : 'light';
$perPage = max(1, (int) $template['cols'] * (int) $template['rows']);
$pageCount = (int) ceil(count($cards) / $perPage);
$pw = rtrim(rtrim(number_format((float) $template['paper_width'], 2, '.', ''), '0'), '.');
$ph = rtrim(rtrim(number_format((float) $template['paper_height'], 2, '.', ''), '0'), '.');
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light" data-theme-pref="<?= e($themePref) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e($v->title . ' · ' . Settings::networkName()) ?></title>
<script>
(function () {
  var p = document.documentElement.getAttribute('data-theme-pref') === 'dark' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', p);
})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<style>@page { size: <?= e($pw) ?>mm <?= e($ph) ?>mm; margin: 0; }</style>
</head>
<body class="print-body">
<div class="print-toolbar">
  <button type="button" class="btn btn-primary" id="print-btn" disabled><?= icon('printer') ?>طباعة</button>
  <div class="print-pdf">
    <select class="input input-sm" id="pdf-dpi" aria-label="دقة الملف">
      <option value="150">150 DPI · عادية</option>
      <option value="300" selected>300 DPI · عالية</option>
      <option value="600">600 DPI · عالية جداً</option>
    </select>
    <button type="button" class="btn" id="pdf-btn" disabled><?= icon('file-down') ?>تنزيل PDF عالي الدقة</button>
  </div>
  <a class="btn" href="<?= e(url('/print')) ?>"><?= icon('chevron-right') ?>رجوع</a>
  <span class="info">
    <strong><?= e(num(count($cards))) ?></strong> كرت ·
    <?= e(ar_plural($pageCount, 'صفحة واحدة', 'صفحتان', 'صفحات', 'صفحة')) ?> ·
    <?= e($template['name']) ?> · <?= ltr(e($pw . ' × ' . $ph)) ?> مم
  </span>
  <span class="info" id="print-status"></span>
</div>
<?= $content ?>
<script src="<?= e(asset('vendor/qrcode.js')) ?>"></script>
<script src="<?= e(asset('js/card-render.js')) ?>"></script>
<script src="<?= e(asset('js/card-export.js')) ?>"></script>
<script src="<?= e(asset('js/app-bridge.js')) ?>"></script>
<script>
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('print-config').textContent);
  var box = document.getElementById('print-pages');
  var btn = document.getElementById('print-btn');
  var status = document.getElementById('print-status');
  var pdfBtn = document.getElementById('pdf-btn');
  var dpiSel = document.getElementById('pdf-dpi');
  var marked = false;
  try { var savedDpi = localStorage.getItem('sn-pdf-dpi'); if (savedDpi) { dpiSel.value = savedDpi; } } catch (e) { /* التخزين غير متاح */ }
  dpiSel.addEventListener('change', function () { try { localStorage.setItem('sn-pdf-dpi', dpiSel.value); } catch (e) { /* التخزين غير متاح */ } });

  function build() {
    box.innerHTML = '';
    var pages = SNCard.pages(cfg.template, cfg.design, cfg.cards, { base: cfg.base, logo: cfg.logo });
    pages.forEach(function (p) { box.appendChild(p); });
    SNCard.fitText(box);
    fitPreview();
    btn.disabled = false;
    pdfBtn.disabled = false;
  }

  // تصغير المعاينة لتناسب عرض الشاشة (الهاتف) دون أي أثر على الطباعة أو الملف
  function fitPreview() {
    var pagePx = cfg.template.paper_width * 96 / 25.4 + 24;
    var z = Math.min(1, document.documentElement.clientWidth / pagePx);
    box.style.setProperty('--preview-zoom', z.toFixed(3));
  }
  window.addEventListener('resize', fitPreview);

  function waitImages() {
    var imgs = Array.prototype.slice.call(box.querySelectorAll('img'));
    return Promise.all(imgs.map(function (img) {
      return img.complete ? null : new Promise(function (res) { img.onload = img.onerror = res; });
    }));
  }

  function mark() {
    if (!cfg.mark || marked || !cfg.ids.length) { return Promise.resolve(); }
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    return fetch(cfg.markUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
      body: JSON.stringify({ ids: cfg.ids })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.ok) { marked = true; status.textContent = 'تم تعليم الكروت كمطبوعة.'; }
    }).catch(function () { status.textContent = 'تعذّر تعليم الكروت كمطبوعة.'; });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    waitImages().then(function () {
      window.print();
      return mark();
    }).then(function () { btn.disabled = false; });
  });

  // ملف PDF بالدقة المختارة: كل صفحة تُرسم كصورة بنفس التصميم
  pdfBtn.addEventListener('click', function () {
    var dpi = parseInt(dpiSel.value, 10) || 300;
    pdfBtn.disabled = btn.disabled = dpiSel.disabled = true;
    status.textContent = 'جارٍ تجهيز الملف…';
    SNCardExport.exportPdf(cfg.template, cfg.design, cfg.cards, {
      dpi: dpi, base: cfg.base, logo: cfg.logo,
      onProgress: function (done, total) { status.textContent = 'جارٍ تجهيز الصفحة ' + done + ' من ' + total + '…'; }
    }).then(function (blob) {
      var a = document.createElement('a');
      var d = new Date();
      a.href = URL.createObjectURL(blob);
      a.download = 'cards-' + d.getFullYear() + ('0' + (d.getMonth() + 1)).slice(-2) + ('0' + d.getDate()).slice(-2) + '-' + dpi + 'dpi.pdf';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 60000);
      status.textContent = 'تم تنزيل الملف (' + (blob.size / 1048576).toFixed(1) + ' ميغابايت).';
      return mark();
    }).catch(function () {
      status.textContent = dpi > 150
        ? 'تعذّر إنشاء الملف بهذه الدقة على هذا الجهاز. اختر دقة أقل.'
        : 'تعذّر إنشاء الملف.';
    }).then(function () { pdfBtn.disabled = btn.disabled = dpiSel.disabled = false; });
  });

  var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
  ready.then(build, build);
})();
</script>
</body>
</html>
