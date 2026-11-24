<?php
/**
 * شاشات الدخول والتسجيل: عمود النموذج، وعلى الحاسوب لوحة عرض بألوان التصميم المختار فيها جهاز يعرض لوحة النظام.
 * على الجوال لا تُرسم لوحة العرض أصلاً: تصميم مستقل بلا صور.
 * كل الألوان والخطوط والزوايا والأزرار من التصميم المطبّق (Theme)، فتتبع الشاشة أي تصميم يختاره المسؤول.
 * @var App\Core\View $v
 * @var string $content
 * @var ?string $paneTitle  اسم الشبكة أو المنصة
 * @var ?string $paneMark   علامة HTML (شعار الشبكة)
 * @var ?string $paneSub
 */
use App\Core\Brand;
use App\Core\Config;
use App\Core\Lang;
use App\Core\Session;

$paneTitle = $paneTitle ?? Brand::networkName();
$paneMark = $paneMark ?? Brand::networkMark('net-mark-xl');
$paneSub = $paneSub ?? Brand::TAGLINE;
$flash = Config::installed() || PHP_SAPI !== 'cli' ? (Session::pull('_flash', []) ?: []) : [];
$tools = static function (string $cls) {
    ob_start(); ?>
    <div class="login-tools <?= e($cls) ?>">
      <?php if (Lang::switchable()): ?>
      <a class="login-tool" href="<?= e(Lang::switchUrl(Lang::isEn() ? 'ar' : 'en')) ?>" data-no-loading aria-label="<?= Lang::isEn() ? 'العربية' : 'English' ?>"><?= icon('globe') ?><?= Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a>
      <?php endif; ?>
      <div class="dropdown">
        <button type="button" class="login-tool" data-dropdown aria-label="المظهر"><?= icon('sun-moon') ?><span>المظهر</span></button>
        <div class="dropdown-menu">
          <button type="button" class="dropdown-item" data-theme-set="light"><?= icon('sun') ?>فاتح</button>
          <button type="button" class="dropdown-item" data-theme-set="dark"><?= icon('moon') ?>داكن</button>
        </div>
      </div>
    </div>
    <?php return (string) ob_get_clean();
};
?><!doctype html>
<html <?= Lang::htmlAttrs() ?> data-theme="light"<?= \App\Core\Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="icons-url" content="<?= e(asset('img/icons.svg')) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($v->title !== '' ? $v->title . ' · ' : '') . $paneTitle) ?></title>
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
<body class="login-body <?= e($v->bodyClass) ?>">
<div class="auth-shell">
  <main class="auth-main">
    <div class="auth-top">
      <div class="auth-brand"><?= $paneMark ?><div class="auth-brand-text"><b><?= e($paneTitle) ?></b><small><?= e($paneSub) ?></small></div></div>
      <?= $tools('') ?>
    </div>
    <div class="auth-body">
      <div class="login-card">
        <?= $content ?>
        <div class="auth-secure"><?= icon('shield-check') ?>اتصال مشفّر وآمن</div>
      </div>
    </div>
    <div class="login-foot">
      <?= $v->partial('madix-credit') ?>
    </div>
  </main>
  <aside class="auth-show" aria-hidden="true">
    <div class="auth-show-fx"><span></span><span></span></div>
    <div class="auth-show-head">
      <div class="auth-show-brand"><?= Brand::mark(30, 'is-glass') ?><?= Brand::wordmark('is-light') ?></div>
      <h2>شبكتك كاملة، في مكان واحد</h2>
      <p>الكروت والمشتركون وأجهزة MikroTik والمبيعات والتقارير، في لوحة واحدة أنيقة تعمل على كل الأجهزة.</p>
    </div>
    <div class="auth-stage"><?= $v->partial('auth-device') ?></div>
  </aside>
</div>
<div class="toasts" aria-live="polite"></div>
<?php if ($flash): ?>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/app-bridge.js')) ?>"></script>
<?php foreach ($v->scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
