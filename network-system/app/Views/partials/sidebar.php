<?php
use App\Core\Request;

// قسم الكروت صفحة واحدة بأقسام داخلها (القائمة، الاستيراد، الطباعة، الإعدادات): الرابط يفتح أول قسم مسموح للمستخدم
$cardsHome = can('cards.view') ? '/cards' : (can('cards.import') ? '/cards/import' : '/print');

$nav = [
    [null, array_merge(
        [['لوحة التحكم', '/', 'layout-dashboard', 'dashboard.view']],
        \App\Services\Assistant::visible() ? [['المساعد الذكي', '/assistant', 'sparkles', 'assistant.use', 'ai']] : []
    )],
    ['الشبكة', [
        ['أجهزة MikroTik', '/routers', 'router', 'routers.view'],
        ['فحص الكابلات', '/cables', 'cable', 'routers.cables'],
        ['الأنفاق الآمنة', '/tunnels', 'waypoints', 'routers.manage', 'tunnel'],
        ['المتصلون الآن', '/sessions', 'activity', 'sessions.view'],
        ['الاستهلاك الزائد', '/usage', 'gauge', 'usage.view'],
        ['سجل الجلسات', '/sessions/history', 'history', 'sessions.view'],
        ['الأجهزة', '/devices', 'smartphone', 'devices.view'],
        ['خريطة الشبكة', '/map', 'map', 'map.view'],
        ['الأبراج والمواقع', '/sites', 'radio-tower', 'map.view'],
        ['مراقبة الطاقة', '/power', 'zap', 'power.view'],
        ['سجل الانقطاعات', '/outages', 'unplug', 'outages.view'],
        ['إعدادات الانقطاعات', '/outages/settings', 'settings', 'settings.manage', 'outages'],
        ['المناطق', '/areas', 'map-pin', 'areas.manage'],
        ['مهام الصيانة', '/maintenance', 'wrench', ['maintenance.view', 'maintenance.manage', 'maintenance.work'], 'maintenance'],
    ]],
    ['الكروت', [
        ['الكروت', $cardsHome, 'ticket', ['cards.view', 'cards.import', 'cards.print', 'print.templates'], null, ['/cards', '/cards/import', '/cards/settings', '/print']],
        ['متابعة الكروت', '/cards/monitor', 'credit-card', 'cards.monitor'],
        ['توليد كروت', '/cards/generate', 'wand-sparkles', 'cards.generate'],
        ['الحسابات الخاصة', '/cards/accounts', 'user-plus', 'cards.accounts'],
        ['اختصار الأرقام', '/shortener', 'link-2', 'cards.short'],
        ['الدفعات', '/batches', 'boxes', 'cards.view'],
        ['الباقات', '/categories', 'layers', 'categories.view'],
        ['فحص الكرت', '/check', 'scan-line', 'check.use'],
    ]],
    ['المخزون', [
        ['الأصناف', '/inventory', 'warehouse', 'inventory.view'],
        ['الأجهزة المرقّمة', '/inventory/units', 'barcode', 'inventory.view'],
        ['حركات المخزون', '/inventory/movements', 'arrow-left-right', 'inventory.view'],
    ]],
    ['المشتركون', [
        ['المشتركون', '/subscribers', 'users', 'subscribers.view'],
        ['باقات الاشتراك', '/plans', 'package', 'plans.view'],
        ['رسائل المشتركين', '/inbox', 'message-square', 'subscribers.messages', 'inbox'],
        ['إعدادات المشتركين', '/subscribers/settings', 'settings', 'settings.manage'],
    ]],
    ['المبيعات والمالية', [
        ['المبيعات', '/sales', 'shopping-cart', 'sales.view'],
        ['الموزعون', '/distributors', 'store', 'distributors.view'],
        ['المصاريف والأرباح', '/expenses', 'receipt', 'expenses.view'],
    ]],
    ['الهوتسبوت', [
        ['ربط الهوتسبوت', '/hotspot', 'wifi', 'hotspot.manage'],
        ['سجل الدخول', '/hotspot/log', 'list-checks', 'hotspot.log'],
        ['إعلانات الصفحة', '/hotspot/ads', 'megaphone', 'hotspot.ads'],
        ['إعدادات الهوتسبوت', '/hotspot/settings', 'settings', 'settings.manage'],
    ]],
    ['الرسائل النصية', [
        ['إرسال رسالة', '/sms', 'send', 'sms.send'],
        ['سجل الرسائل', '/sms/log', 'history', ['sms.log', 'sms.send']],
        ['إعدادات الرسائل', '/sms/settings', 'settings', 'sms.manage'],
    ]],
    ['بوت تيليجرام', [
        ['إعداد البوت والتقارير', '/telegram', 'bot', 'telegram.manage'],
        ['المشتركون المرتبطون', '/telegram/links', 'link', 'telegram.manage'],
    ]],
    ['التقارير', [
        ['التقارير', '/reports', 'chart-column', ['reports.view', 'reports.financial', 'inventory.view']],
    ]],
    ['الإدارة', [
        ['الموظفون', '/employees', 'user-cog', 'employees.manage'],
        ['سجل العمليات', '/audit', 'clipboard-list', 'audit.view'],
        ['التنبيهات', '/alerts', 'bell', 'alerts.view', 'alerts'],
        ['العمليات الجارية', '/jobs', 'hourglass', 'jobs.view'],
        ['الإعدادات', '/settings', 'settings', 'settings.manage'],
    ]],
];
if (\App\Core\Platform::enabled() && \App\Core\Tenant::current()) {
    $supportUnread = can('support.use') ? \App\Platform\Support::unreadForTenant((int) \App\Core\Tenant::id()) : 0;
    $nav[] = ['المنصة', [
        ['الاشتراك', '/billing', 'badge-check', 'billing.manage'],
        ['الدعم', '/support', 'message-circle', 'support.use', 'support'],
    ]];
}
if (!\App\Core\Platform::enabled() && \App\Core\Auth::isOwner() && \App\Core\Server::managed()) {
    foreach ($nav as $gi => [$gt]) {
        if ($gt === 'الإدارة') {
            $nav[$gi][1][] = ['الخادم', '/server', 'server', 'settings.manage'];
        }
    }
}

// رسائل المشتركين: تظهر حين يعمل بوت المنصة، وشارتها عدد المحادثات التي تنتظر رد الشبكة
$inboxOn = can('subscribers.messages') && \App\Controllers\InboxController::available();
$inboxWaiting = $inboxOn ? \App\Controllers\InboxController::waiting() : 0;
$path = Request::current() ? Request::current()->path : '/';
$active = null;
$bestLen = -1;
foreach ($nav as [$g, $items]) {
    foreach ($items as $item) {
        $href = $item[1];
        // العنصر يُعلَّم نشطاً على رابطه وعلى المسارات التابعة له (العنصر السادس)، والأطول تطابقاً يفوز
        foreach (array_merge([$href], $item[5] ?? []) as $prefix) {
            $match = $prefix === '/' ? $path === '/' : ($path === $prefix || str_starts_with($path, $prefix . '/'));
            if ($match && strlen($prefix) > $bestLen) {
                $bestLen = strlen($prefix);
                $active = $href;
            }
        }
    }
}
$allowed = static function ($perm): bool {
    foreach ((array) $perm as $p) {
        if (can($p)) {
            return true;
        }
    }
    return false;
};
?>
<aside class="sidebar" aria-label="القائمة الرئيسية" data-nav="tenant">
  <div class="sidebar-head">
    <a class="sidebar-brand" href="<?= e(url('/')) ?>">
      <?= \App\Core\Brand::networkMark() ?>
      <span class="brand-text">
        <span class="brand-name d-block"><?= e($networkName) ?></span>
        <span class="brand-sub d-block"><?= \App\Core\Brand::wordmark() ?></span>
      </span>
    </a>
    <button type="button" class="sidebar-close" data-sidebar-close aria-label="إغلاق القائمة"><?= icon('x') ?></button>
  </div>
  <?= $v->partial('nav-search') ?>
  <div class="sidebar-body" data-nav-body>
  <nav class="sidebar-nav" data-nav-scroll>
    <?php foreach ($nav as [$groupTitle, $items]):
        $visible = array_filter($items, static fn ($i) => $allowed($i[3]) && (($i[4] ?? null) !== 'tunnel' || \App\Services\Tunnel::available()) && (($i[4] ?? null) !== 'outages' || can('outages.view')) && (($i[4] ?? null) !== 'inbox' || $inboxOn));
        if (!$visible) { continue; } ?>
      <div class="nav-group"<?= $groupTitle ? ' data-nav-group="' . e($groupTitle) . '"' : '' ?>>
        <?php if ($groupTitle): ?><button type="button" class="nav-group-title" data-nav-toggle aria-expanded="true"><span><?= e($groupTitle) ?></span><?= icon('chevron-down', 'nav-chevron') ?></button><?php endif; ?>
        <div class="nav-group-items"><div class="nav-group-inner">
        <?php foreach ($visible as $item): ?>
          <a class="nav-link<?= $item[1] === $active ? ' is-active' : '' ?>" href="<?= e(url($item[1])) ?>"<?= $item[1] === $active ? ' aria-current="page"' : '' ?>>
            <?= icon($item[2]) ?><span class="nav-text"><?= e($item[0]) ?></span>
            <?php if (($item[4] ?? null) === 'ai'): ?><span class="nav-badge is-ai">AI</span><?php endif; ?>
            <?php if (($item[4] ?? null) === 'inbox' && $inboxWaiting > 0): ?><span class="nav-badge is-danger" title="بانتظار ردك"><?= $inboxWaiting > 99 ? '99+' : $inboxWaiting ?></span><?php endif; ?>
            <?php if (($item[4] ?? null) === 'maintenance' && ($myTasks = \App\Services\Maintenance::mineOpen()) > 0): ?><span class="nav-badge is-danger" title="مهامي المفتوحة"><?= $myTasks > 99 ? '99+' : $myTasks ?></span><?php endif; ?>
            <?php if (($item[4] ?? null) === 'alerts' && $unreadAlerts > 0): ?><span class="nav-badge is-danger"><?= $unreadAlerts > 99 ? '99+' : $unreadAlerts ?></span><?php endif; ?>
            <?php if (($item[4] ?? null) === 'support' && ($supportUnread ?? 0) > 0): ?><span class="nav-badge is-danger"><?= (int) $supportUnread ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        </div></div>
      </div>
    <?php endforeach; ?>
    <div class="nav-empty" data-nav-empty hidden><?= icon('search') ?><span>لا توجد صفحة بهذا الاسم</span></div>
  </nav>
  </div>
  <div class="sidebar-foot">
    <span class="foot-date"><?= icon('calendar', 'icon-sm') ?><?= e(date('Y-m-d')) ?></span>
    <span class="ver-chip" title="إصدار النظام">الإصدار <?= e(APP_VERSION) ?></span>
  </div>
</aside>
<?= $v->partial('nav-restore') ?>
