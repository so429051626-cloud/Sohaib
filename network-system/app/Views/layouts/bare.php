<?php
/**
 * مستند مستقل قابل للطباعة بهوية المنصة (الفواتير).
 * @var App\Core\View $v
 * @var string $content
 */
use App\Core\Brand;
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($v->title) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/document.css')) ?>">
<?= \App\Core\Theme::style() ?>
<?= Brand::headTags() ?>
<style>@page { size: A4 portrait; margin: 12mm 11mm 14mm; }</style>
</head>
<body class="doc-body">
<?= $content ?>
<script>
(function () {
  'use strict';
  var b = document.querySelector('[data-doc-print]');
  if (b) { b.addEventListener('click', function () { window.print(); }); }
  if (/[?&]autoprint=1/.test(location.search)) { window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); }); }
})();
</script>
</body>
</html>
