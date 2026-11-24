<?php
/**
 * صفحات المنصة العامة البسيطة (رسائل الخطأ).
 * @var App\Core\View $v
 * @var string $content
 */
use App\Core\Brand;
use App\Core\Theme;
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light"<?= Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e(($v->title !== '' ? $v->title . ' · ' : '') . Brand::NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?= Theme::style() ?>
<?= Brand::headTags() ?>
</head>
<body class="public-body">
<main class="public-page">
  <a class="public-logo" href="<?= e(url('/')) ?>"><?= Brand::mark(44) ?><?= Brand::wordmark() ?></a>
  <div class="public-card"><?= $content ?></div>
  <?= $v->partial('madix-credit') ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
