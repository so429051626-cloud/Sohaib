<?php
/** @var App\Core\View $v */
use App\Core\Brand;
use App\Core\Config;
use App\Core\Session;
use App\Core\Settings;

$networkName = Config::installed() ? Settings::networkName() : Brand::NAME;
$logo = Config::installed() ? (string) Settings::get('general.logo', '') : '';
$flash = Config::installed() || PHP_SAPI !== 'cli' ? (Session::pull('_flash', []) ?: []) : [];
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light"<?= \App\Core\Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="icons-url" content="<?= e(asset('img/icons.svg')) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($v->title !== '' ? $v->title . ' · ' : '') . $networkName) ?></title>
<?= $v->partial('rum', ['area' => 'login']) ?>
<script>
(function () {
  // الوضع فاتح دائماً ما لم يُختر الداكن يدوياً (لا يتبع إعداد الجهاز)
  var p = 'light';
  try { p = localStorage.getItem('sn-theme') === 'dark' ? 'dark' : 'light'; } catch (e) {}
  document.documentElement.setAttribute('data-theme', p);
  document.documentElement.setAttribute('data-theme-pref', p);
})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?= \App\Core\Theme::style() ?>
<?= Brand::headTags() ?>
</head>
<body>
<main class="auth-page">
  <div class="auth-card<?= $v->wide ? ' is-wide' : '' ?>">
    <div class="auth-brand">
      <?= Config::installed() ? Brand::networkMark() : Brand::mark(44) ?>
      <div>
        <div class="brand-name"><?= e(Config::installed() ? $networkName : Brand::NAME) ?></div>
        <div class="brand-sub"><?= Config::installed() ? Brand::wordmark() : e(Brand::TAGLINE) ?></div>
      </div>
    </div>
    <?= $content ?>
  </div>
  <div class="auth-credit"><?= $v->partial('madix-credit') ?><?php if (\App\Core\Lang::switchable()): ?> <a class="btn btn-ghost btn-xs" href="<?= e(\App\Core\Lang::switchUrl(\App\Core\Lang::isEn() ? 'ar' : 'en')) ?>" data-no-loading><?= icon('globe', 'icon-sm') ?><?= \App\Core\Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a><?php endif; ?></div>
</main>
<div class="toasts" aria-live="polite"></div>
<?php if ($flash): ?>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($v->scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
