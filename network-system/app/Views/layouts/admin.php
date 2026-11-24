<?php
/**
 * لوحة مسؤول المنصة.
 * @var App\Core\View $v
 * @var string $content
 */
use App\Core\AdminAuth;
use App\Core\Brand;
use App\Core\Request;
use App\Core\Session;
use App\Core\Theme;

$admin = AdminAuth::user();
$flash = Session::pull('_flash', []);
$path = Request::current() ? Request::current()->path : '/admin';
$pendingTransfers = (int) \App\Core\DB::value("SELECT COUNT(*) FROM transfer_requests WHERE status = 'pending'");
$openTickets = (int) \App\Core\DB::value('SELECT COUNT(*) FROM support_tickets WHERE admin_unread = 1');
$botWaiting = \App\Services\BotAgent::waiting();
// كل عنصر: [الاسم، الرابط، الأيقونة، العدّاد، الصلاحية (أو 'owner' لمالك المنصة، أو null للجميع)]
$tv = ['tenants.view', 'tenants.manage', 'tenants.access'];
$bv = ['billing.view', 'billing.manage'];
$ms = ['messages.send', 'settings.manage'];
$nav = [
    ['المنصة', [
        ['الرئيسية', '/admin', 'layout-dashboard', 0, null],
        ['الحسابات', '/admin/tenants', 'building', 0, $tv],
        ['المستخدمون', '/admin/users', 'users', 0, $tv],
        ['التحليلات', '/admin/insights', 'chart-line', 0, 'ai.manage'],
        ['النشاط', '/admin/activity', 'activity', 0, $tv],
    ]],
    ['الاشتراكات', [
        ['الباقات', '/admin/plans', 'package', 0, 'billing.manage'],
        ['تحويلات الزبائن', '/admin/transfers', 'hand-coins', $pendingTransfers, $bv],
        ['المدفوعات', '/admin/payments', 'banknote', 0, $bv],
        ['الاشتراك والفوترة', '/admin/billing', 'calendar-clock', 0, 'billing.manage'],
    ]],
    ['الذكاء الاصطناعي', [
        ['الإعداد والاستهلاك', '/admin/ai', 'sparkles', 0, 'ai.manage'],
        ['مساعد المسؤول', '/admin/assistant', 'bot', 0, 'ai.manage'],
    ]],
    ['التواصل', [
        ['تذاكر الدعم', '/admin/tickets', 'message-circle', $openTickets, 'support.manage'],
        ['محادثات البوت', '/admin/bot', 'bot-message-square', $botWaiting, ['bot.chats', 'bot.manage']],
        ['إعلانات للزبائن', '/admin/announcements', 'megaphone', 0, 'support.manage'],
        ['الرسائل النصية', '/admin/sms', 'send', 0, $ms],
        ['البريد الإلكتروني', '/admin/mail', 'mail', 0, $ms],
        ['إشعارات تيليجرام', '/admin/notifications', 'bell', 0, 'settings.manage'],
    ]],
    ['المظهر', [
        ['التصاميم والحركة', '/admin/design', 'palette', 0, 'design.manage'],
        ['صفحة الهبوط', '/admin/landing', 'layout-grid', 0, 'design.manage'],
    ]],
    ['الإدارة', array_merge(
        [['فريق الإدارة', '/admin/team', 'user-cog', 0, 'owner']],
        \App\Core\Server::managed() ? [['الخادم', '/admin/server', 'server', 0, 'settings.manage']] : [],
        [['الكوكيز', '/admin/cookies', 'cookie', 0, 'settings.manage'], ['الأمان', '/admin/security', 'shield-check', 0, null], ['الإعدادات', '/admin/settings', 'settings', 0, 'settings.manage']]
    )],
];
// ما لا يحق للمسؤول يختفي من القائمة (والمسار نفسه مرفوض في الخادم)
foreach ($nav as $gi => [, $items]) {
    $nav[$gi][1] = array_values(array_filter($items, static fn ($it) => $it[4] === null || ($it[4] === 'owner' ? \App\Core\AdminAuth::isOwner() : \App\Core\AdminAuth::can($it[4]))));
}
$nav = array_values(array_filter($nav, static fn ($g) => $g[1] !== []));
$active = '/admin';
foreach ($nav as [, $items]) {
    foreach ($items as $item) {
        $href = $item[1];
        if ($href !== '/admin' && ($path === $href || str_starts_with($path, $href . '/'))) {
            $active = $href;
        }
    }
}
$initial = mb_substr(trim((string) $admin['name']), 0, 1);
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="light" data-theme-pref="light"<?= Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="icons-url" content="<?= e(asset('img/icons.svg')) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($v->title !== '' ? $v->title . ' · ' : '') . 'لوحة المسؤول') ?></title>
<?= $v->partial('prefetch-fresh') ?>
<?= $v->partial('rum', ['area' => 'admin']) ?>
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
<?= Theme::style() ?>
<?= Brand::headTags() ?>
</head>
<body class="admin-body">
<div class="shell">
  <aside class="sidebar" aria-label="القائمة الرئيسية" data-nav="admin">
    <div class="sidebar-head">
      <a class="sidebar-brand" href="<?= e(url('/admin')) ?>">
        <?= Brand::mark(38) ?>
        <span class="brand-text">
          <span class="brand-name d-block"><?= e(Brand::NAME) ?></span>
          <span class="brand-sub d-block">لوحة المسؤول</span>
        </span>
      </a>
      <button type="button" class="sidebar-close" data-sidebar-close aria-label="إغلاق القائمة"><?= icon('x') ?></button>
    </div>
    <?= $v->partial('nav-search') ?>
    <div class="sidebar-body" data-nav-body>
  <nav class="sidebar-nav" data-nav-scroll>
      <?php foreach ($nav as [$groupTitle, $items]): ?>
      <div class="nav-group" data-nav-group="<?= e($groupTitle) ?>">
        <button type="button" class="nav-group-title" data-nav-toggle aria-expanded="true"><span><?= e($groupTitle) ?></span><?= icon('chevron-down', 'nav-chevron') ?></button>
        <div class="nav-group-items"><div class="nav-group-inner">
        <?php foreach ($items as $item): [$label, $href, $ic] = $item; ?>
          <a class="nav-link<?= $active === $href ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span class="nav-text"><?= e($label) ?></span><?php if (!empty($item[3])): ?><span class="nav-badge is-danger"><?= (int) $item[3] > 99 ? '99+' : (int) $item[3] ?></span><?php endif; ?></a>
        <?php endforeach; ?>
        </div></div>
      </div>
      <?php endforeach; ?>
      <div class="nav-empty" data-nav-empty hidden><?= icon('search') ?><span>لا توجد صفحة بهذا الاسم</span></div>
    </nav>
  </div>
    <div class="sidebar-foot">
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external-link', 'icon-sm') ?>عرض المنصة</a>
      <span class="ver-chip" title="إصدار النظام">الإصدار <?= e(APP_VERSION) ?></span>
    </div>
  </aside>
<?= $v->partial('nav-restore') ?>
  <div class="backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button type="button" class="icon-btn menu-toggle" data-sidebar-toggle aria-label="القائمة"><?= icon('menu') ?></button>
      <div class="topbar-spacer"></div>
      <div class="topbar-actions">
        <?php if (\App\Core\Lang::switchable()): $curLang = \App\Core\Lang::current(); ?>
        <div class="dropdown">
          <button type="button" class="icon-btn lang-btn" data-dropdown aria-label="Language / اللغة" title="Language / اللغة"><?= icon('globe') ?><span class="lang-code"><?= $curLang === 'en' ? 'EN' : 'ع' ?></span></button>
          <div class="dropdown-menu">
            <a class="dropdown-item<?= $curLang === 'ar' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('ar')) ?>" lang="ar" data-no-loading><?= icon($curLang === 'ar' ? 'circle-check' : 'globe') ?>العربية</a>
            <a class="dropdown-item<?= $curLang === 'en' ? ' is-current' : '' ?>" href="<?= e(\App\Core\Lang::switchUrl('en')) ?>" lang="en" dir="ltr" data-no-loading><?= icon($curLang === 'en' ? 'circle-check' : 'globe') ?>English</a>
          </div>
        </div>
        <?php endif; ?>
        <div class="dropdown">
          <button type="button" class="icon-btn" data-dropdown aria-label="المظهر"><?= icon('sun-moon') ?></button>
          <div class="dropdown-menu">
            <button type="button" class="dropdown-item" data-theme-set="light"><?= icon('sun') ?>فاتح</button>
            <button type="button" class="dropdown-item" data-theme-set="dark"><?= icon('moon') ?>داكن</button>
          </div>
        </div>
        <div class="dropdown">
          <button type="button" class="user-chip" data-dropdown>
            <span class="avatar"><?= e($initial) ?></span>
            <span class="user-chip-text"><span class="user-chip-name d-block"><?= e($admin['name']) ?></span><span class="user-chip-role d-block">مسؤول المنصة</span></span>
            <?= icon('chevron-down', 'icon-sm text-muted') ?>
          </button>
          <div class="dropdown-menu">
            <div class="dropdown-head"><?= e($admin['username']) ?></div>
            <?php if (\App\Core\AdminAuth::can('settings.manage')): ?><a class="dropdown-item" href="<?= e(url('/admin/settings')) ?>"><?= icon('settings') ?>الإعدادات</a><?php endif; ?>
            <a class="dropdown-item" href="<?= e(url('/admin/security')) ?>"><?= icon('shield-check') ?>الأمان وكلمة المرور</a>
            <div class="dropdown-sep"></div>
            <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button type="submit" class="dropdown-item is-danger" data-no-loading><?= icon('log-out') ?>تسجيل الخروج</button></form>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
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
      <?= $content ?>
      <footer class="app-footer"><?= $v->partial('madix-credit') ?></footer>
    </main>
  </div>
</div>
<dialog class="modal modal-sm" id="confirm-dialog">
  <div class="modal-body"><div class="confirm-body"><div class="modal-icon"><?= icon('triangle-alert', 'icon-lg') ?></div><div class="confirm-text"></div></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button type="button" class="btn btn-danger" data-confirm-ok>تأكيد</button></div>
</dialog>
<div class="toasts" aria-live="polite"></div>
<?php if ($flash): ?>
<script type="application/json" id="flash-data"><?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?= $v->partial('speculation') ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($v->scripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
