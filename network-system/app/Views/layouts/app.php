<?php
/** @var App\Core\View $v */
use App\Core\Auth;
use App\Core\Brand;
use App\Core\DB;
use App\Core\Session;
use App\Core\Settings;
use App\Permissions;

$user = Auth::user();
// الوضع فاتح دائماً ما لم يختر المستخدم الداكن بنفسه (لا يتبع إعداد الجهاز)
$themePref = ($user['theme'] ?? '') === 'dark' ? 'dark' : 'light';
$networkName = Settings::networkName();
$logo = (string) Settings::get('general.logo', '');
$flash = Session::pull('_flash', []);
$unreadAlerts = can('alerts.view') ? (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0') : 0;
$presetLabel = $user['is_owner'] ? 'المالك' : (Permissions::PRESETS[$user['role_preset'] ?? '']['label'] ?? 'موظف');
$initial = mb_substr(trim((string) $user['name']), 0, 1);
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light" data-theme-pref="<?= e($themePref) ?>"<?= \App\Core\Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="icons-url" content="<?= e(asset('img/icons.svg')) ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="auth" content="1">
<title><?= e(($v->title !== '' ? $v->title . ' · ' : '') . $networkName) ?></title>
<?= $v->partial('prefetch-fresh') ?>
<?= $v->partial('rum', ['area' => 'net']) ?>
<script>
(function () {
  var p = document.documentElement.getAttribute('data-theme-pref') === 'dark' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', p);
})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?= \App\Core\Theme::style() ?>
<?= Brand::headTags() ?>
</head>
<body class="<?= e(trim($v->bodyClass . (Settings::bool('ui.sidebar_hidden') ? ' sidebar-hidden' : ''))) ?>">
<div class="shell">
  <?= $v->partial('sidebar', ['networkName' => $networkName, 'logo' => $logo, 'unreadAlerts' => $unreadAlerts]) ?>
  <div class="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button type="button" class="icon-btn menu-toggle" data-sidebar-toggle aria-label="القائمة"><?= icon('menu') ?></button>
      <a class="topbar-brand" href="<?= e(url('/')) ?>"><?= Brand::networkMark() ?><span class="topbar-brand-name"><?= e($networkName) ?></span></a>
      <div class="topbar-search">
        <?= icon('search') ?>
        <input type="search" data-global-search placeholder="بحث عن كرت، مشترك، عنوان MAC..." aria-label="بحث" autocomplete="off">
      </div>
      <div class="topbar-spacer"></div>
      <div class="topbar-actions">
        <?php if (\App\Services\Assistant::visible()): ?>
        <a class="icon-btn ai-btn" href="<?= e(url('/assistant')) ?>" aria-label="المساعد الذكي" title="المساعد الذكي"><?= icon('sparkles') ?></a>
        <?php endif; ?>
        <?php if (\App\Core\Lang::switchable()): $curLang = \App\Core\Lang::current(); ?>
        <div class="dropdown lang-dd">
          <button type="button" class="icon-btn lang-btn" data-dropdown aria-label="Language / اللغة" title="Language / اللغة"><?= icon('globe') ?><span class="lang-code"><?= $curLang === 'en' ? 'EN' : 'ع' ?></span></button>
          <div class="dropdown-menu">
            <a class="dropdown-item<?= $curLang === 'ar' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('ar')) ?>" lang="ar" data-no-loading><?= icon($curLang === 'ar' ? 'circle-check' : 'globe') ?>العربية</a>
            <a class="dropdown-item<?= $curLang === 'en' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('en')) ?>" lang="en" dir="ltr" data-no-loading><?= icon($curLang === 'en' ? 'circle-check' : 'globe') ?>English</a>
          </div>
        </div>
        <?php endif; ?>
        <div class="dropdown theme-dd">
          <button type="button" class="icon-btn" data-dropdown aria-label="المظهر" title="المظهر">
            <?= icon('sun-moon') ?>
          </button>
          <div class="dropdown-menu">
            <div class="dropdown-head">المظهر</div>
            <button type="button" class="dropdown-item" data-theme-set="light"><?= icon('sun') ?>فاتح</button>
            <button type="button" class="dropdown-item" data-theme-set="dark"><?= icon('moon') ?>داكن</button>
          </div>
        </div>
        <?php $siblingNets = \App\Core\Platform::enabled() && \App\Core\Auth::isOwner() ? \App\Core\Tenant::siblings() : []; ?>
        <?php if ($siblingNets): ?>
        <div class="dropdown">
          <button type="button" class="net-switch" data-dropdown aria-label="تبديل الشبكة" title="تبديل الشبكة"><?= icon('network') ?><span><?= e($networkName) ?></span><?= icon('chevron-down', 'icon-sm') ?></button>
          <div class="dropdown-menu">
            <div class="dropdown-head">شبكاتك</div>
            <span class="dropdown-item is-current"><?= icon('circle-check') ?><?= e($networkName) ?></span>
            <?php foreach ($siblingNets as $sn): ?>
            <form method="post" action="<?= e(url('/networks/' . $sn['id'] . '/switch')) ?>"><?= csrf_field() ?><button type="submit" class="dropdown-item" data-no-loading><?= icon('building') ?><?= e($sn['name']) ?></button></form>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if (can('alerts.view')): ?>
        <a class="icon-btn" href="<?= e(url('/alerts')) ?>" aria-label="التنبيهات" title="التنبيهات">
          <?= icon('bell') ?>
          <?php if ($unreadAlerts > 0): ?><span class="dot"><?= $unreadAlerts > 99 ? '99+' : $unreadAlerts ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <div class="dropdown">
          <button type="button" class="user-chip" data-dropdown>
            <span class="avatar"><?= e($initial) ?></span>
            <span class="user-chip-text">
              <span class="user-chip-name d-block"><?= e($user['name']) ?></span>
              <span class="user-chip-role d-block"><?= e($presetLabel) ?></span>
            </span>
            <?= icon('chevron-down', 'icon-sm text-muted') ?>
          </button>
          <div class="dropdown-menu">
            <div class="dropdown-head"><?= e($user['username']) ?></div>
            <a class="dropdown-item" href="<?= e(url('/account')) ?>"><?= icon('circle-user') ?>حسابي</a>
            <?php if (can('settings.manage')): ?>
            <a class="dropdown-item" href="<?= e(url('/settings')) ?>"><?= icon('settings') ?>الإعدادات</a>
            <?php endif; ?>
            <?php /* الشاشات الضيقة: المظهر واللغة هنا بدل زرّيهما في الشريط العلوي */ ?>
            <div class="narrow-only">
              <div class="dropdown-sep"></div>
              <div class="dropdown-head">المظهر</div>
              <button type="button" class="dropdown-item" data-theme-set="light"><?= icon('sun') ?>فاتح</button>
              <button type="button" class="dropdown-item" data-theme-set="dark"><?= icon('moon') ?>داكن</button>
              <?php if (\App\Core\Lang::switchable()): $curLang = \App\Core\Lang::current(); ?>
              <div class="dropdown-sep"></div>
              <a class="dropdown-item<?= $curLang === 'ar' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('ar')) ?>" lang="ar" data-no-loading><?= icon($curLang === 'ar' ? 'circle-check' : 'globe') ?>العربية</a>
              <a class="dropdown-item<?= $curLang === 'en' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('en')) ?>" lang="en" dir="ltr" data-no-loading><?= icon($curLang === 'en' ? 'circle-check' : 'globe') ?>English</a>
              <?php endif; ?>
            </div>
            <div class="dropdown-sep"></div>
            <form method="post" action="<?= e(url('/logout')) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="dropdown-item is-danger" data-no-loading><?= icon('log-out') ?>تسجيل الخروج</button>
            </form>
          </div>
        </div>
      </div>
    </header>
    <?php if (\App\Core\Platform::enabled() && ($tn = \App\Core\Tenant::current())): ?>
      <?php if (Session::get('impersonated_by')): ?>
      <div class="imp-bar"><?= icon('shield-check') ?><span>أنت داخل الحساب كمسؤول المنصة · <?= e((string) Session::get('impersonated_by')) ?></span>
        <form method="post" action="<?= e(url('/impersonation/exit')) ?>"><?= csrf_field() ?><button class="btn btn-sm" data-no-loading><?= icon('log-out') ?>العودة إلى لوحة المسؤول</button></form></div>
      <?php endif; ?>
      <?php
        $phase = \App\Core\Tenant::phase($tn);
        $dl = \App\Core\Tenant::daysLeft($tn);
        $ends = \App\Core\Tenant::phaseEnds($tn);
        $bill = can('billing.manage');
        $remind = \App\Platform\Billing::remindDays();
      ?>
      <?php if ($phase === 'readonly'): ?>
      <div class="announce-bar is-danger"><?= icon('eye') ?><span>انتهى الاشتراك: النظام للقراءة فقط حتى <b><?= ltr(e(date('Y-m-d', (int) $ends))) ?></b></span><?php if ($bill): ?><a class="btn btn-sm" href="<?= e(url('/billing')) ?>">تجديد الاشتراك</a><?php endif; ?></div>
      <?php elseif ($phase === 'grace'): ?>
      <div class="announce-bar is-warning"><?= icon('hourglass') ?><span>انتهى الاشتراك: فترة سماح حتى <b><?= ltr(e(date('Y-m-d', (int) $ends))) ?></b></span><?php if ($bill): ?><a class="btn btn-sm" href="<?= e(url('/billing')) ?>">تجديد الاشتراك</a><?php endif; ?></div>
      <?php elseif ($tn['status'] === 'trial'): ?>
      <div class="trial-bar"><?= icon('clock') ?><span>الفترة التجريبية: متبقي <b><?= (int) $dl === 0 ? 'أقل من يوم' : num((int) $dl) . ' يوم' ?></b></span>
        <?php if ($bill): ?><a href="<?= e(url('/billing')) ?>">تفعيل الاشتراك</a><?php else: ?><a href="<?= e(\App\Core\Platform::whatsappLink('مرحباً، أريد تفعيل اشتراك حساب ' . $tn['name'] . ' (' . $tn['slug'] . ')')) ?>" target="_blank" rel="noopener">تفعيل الاشتراك</a><?php endif; ?></div>
      <?php elseif ($dl !== null && $remind && $dl <= max($remind)): ?>
      <div class="trial-bar"><?= icon('calendar-clock') ?><span>الاشتراك ينتهي خلال <b><?= (int) $dl <= 1 ? 'يوم' : num((int) $dl) . ' أيام' ?></b></span><?php if ($bill): ?><a href="<?= e(url('/billing')) ?>">تجديد الاشتراك</a><?php endif; ?></div>
      <?php endif; ?>
      <?php if (($ann = \App\Core\Platform::setting('platform.announcement')) !== ''): ?>
      <div class="announce-bar is-<?= e(\App\Core\Platform::setting('platform.announcement_level')) ?>"><?= icon('megaphone') ?><span><?= e($ann) ?></span></div>
      <?php endif; ?>
      <?php foreach (\App\Platform\Support::announcements((int) $tn['id']) as $an): ?>
      <div class="announce-bar is-<?= e($an['level']) ?>" data-announce="<?= (int) $an['id'] ?>" hidden><?= icon('megaphone') ?><span><b><?= e($an['title']) ?></b><?= $an['body'] ? ' — ' . e($an['body']) : '' ?></span><button type="button" class="announce-close" data-announce-close aria-label="إخفاء"><?= icon('x', 'icon-sm') ?></button></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <main class="content<?= $v->wide ? ' is-wide' : '' ?>">
      <?php if ($v->title !== '' || $v->actions !== ''): ?>
      <div class="page-head">
        <div class="page-title">
          <?php if ($v->breadcrumbs): ?>
          <nav class="crumbs" aria-label="المسار">
            <?php foreach ($v->breadcrumbs as $i => $crumb): ?>
              <?php if ($i > 0): ?><?= icon('chevron-left') ?><?php endif; ?>
              <?php if (!empty($crumb[1])): ?><a href="<?= e(url($crumb[1])) ?>"><?= e($crumb[0]) ?></a><?php else: ?><span><?= e($crumb[0]) ?></span><?php endif; ?>
            <?php endforeach; ?>
          </nav>
          <?php endif; ?>
          <h1><?= e($v->title) ?></h1>
          <?php if ($v->subtitle): ?><div class="page-sub"><?= e($v->subtitle) ?></div><?php endif; ?>
        </div>
        <?php if ($v->actions !== ''): ?><div class="page-actions"><?= $v->actions ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?= $content ?>
      <footer class="app-footer"><?= $v->partial('madix-credit') ?></footer>
    </main>
  </div>
</div>

<dialog class="modal modal-sm" id="confirm-dialog">
  <div class="modal-body">
    <div class="confirm-body">
      <div class="modal-icon"><?= icon('triangle-alert', 'icon-lg') ?></div>
      <div class="confirm-text"></div>
    </div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn" data-modal-close>إلغاء</button>
    <button type="button" class="btn btn-danger" data-confirm-ok>تأكيد</button>
  </div>
</dialog>
<div class="toasts" aria-live="polite"></div>
<?php if ($flash): ?>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?= $v->partial('speculation') ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/app-bridge.js')) ?>"></script>
<?php foreach ($v->scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
