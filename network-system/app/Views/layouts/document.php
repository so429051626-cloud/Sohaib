<?php
/**
 * مستند قابل للطباعة (A4) بترويسة الشبكة: التقارير وكشوف الحساب.
 * @var App\Core\View $v
 * @var string $content
 */
use App\Core\Auth;
use App\Core\Brand;
use App\Core\Settings;

$user = Auth::user();
$logo = (string) Settings::get('general.logo', '');
$docTitle = $docTitle ?? $v->title;
$docMeta = $docMeta ?? [];
$back = $back ?? '/';
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e($docTitle . ' · ' . Settings::networkName()) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/document.css')) ?>">
<?= \App\Core\Theme::style() ?>
<?= Brand::headTags() ?>
<style id="doc-page">@page { size: A4 portrait; margin: 12mm 11mm 14mm; }</style>
</head>
<body class="doc-body">
<div class="doc-toolbar no-print">
  <button type="button" class="btn btn-primary" data-doc-print><?= icon('printer') ?>طباعة</button>
  <div class="segmented" role="radiogroup" aria-label="اتجاه الصفحة">
    <label><input type="radio" name="doc-orient" value="portrait" checked><span>طولي</span></label>
    <label><input type="radio" name="doc-orient" value="landscape"><span>عرضي</span></label>
  </div>
  <a class="btn" href="<?= e(url($back)) ?>"><?= icon('chevron-right') ?>رجوع</a>
</div>
<div class="doc-stage">
  <article class="doc-sheet" data-orient="portrait">
    <header class="doc-head">
      <div class="doc-brand">
        <?= Brand::networkMark('doc-logo') ?>
        <div>
          <div class="doc-network"><?= e(Settings::networkName()) ?></div>
          <div class="doc-system"><?= e(Brand::TAGLINE) ?></div>
        </div>
      </div>
      <div class="doc-titlebox">
        <h1><?= e($docTitle) ?></h1>
        <?php foreach ($docMeta as $label => $value): ?><div class="doc-meta"><span><?= e($label) ?></span><b><?= $value ?></b></div><?php endforeach; ?>
      </div>
    </header>
    <?= $content ?>
    <footer class="doc-foot">
      <span>أُعدّ في <?= ltr(e(date('Y-m-d H:i'))) ?><?= $user ? ' · ' . e($user['name']) : '' ?></span>
      <span class="doc-powered" dir="ltr">Powered by <?= Brand::mark(12) ?><b>Madix</b> — Developed by <?= Brand::developer(12) ?></span>
    </footer>
  </article>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/app-bridge.js')) ?>"></script>
<?php foreach ($v->scripts as $src): ?><script src="<?= e(asset($src)) ?>"></script>
<?php endforeach; ?>
<script>
(function () {
  'use strict';
  var sheet = document.querySelector('.doc-sheet');
  var pageCss = document.getElementById('doc-page');
  function orient(o) {
    sheet.setAttribute('data-orient', o);
    pageCss.textContent = '@page { size: A4 ' + o + '; margin: 12mm 11mm 14mm; }';
    window.dispatchEvent(new Event('resize'));
  }
  document.querySelectorAll('input[name=doc-orient]').forEach(function (r) {
    r.addEventListener('change', function () { if (r.checked) { orient(r.value); } });
  });
  document.querySelector('[data-doc-print]').addEventListener('click', function () { window.print(); });
  if (/[?&]autoprint=1/.test(location.search)) { window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); }); }
})();
</script>
</body>
</html>
