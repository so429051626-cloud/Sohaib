<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AlertController;
use App\Controllers\ApiController;
use App\Controllers\AreaController;
use App\Controllers\MapController;
use App\Controllers\MobileController;
use App\Controllers\MobileDataController;
use App\Controllers\ModuleSettingsController;
use App\Controllers\SiteController;
use App\Controllers\SmsController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CableController;
use App\Controllers\BatchController;
use App\Controllers\BrandController;
use App\Controllers\CardAccountController;
use App\Controllers\CardController;
use App\Controllers\CardMonitorController;
use App\Controllers\CategoryController;
use App\Controllers\CheckController;
use App\Controllers\DashboardController;
use App\Controllers\DeviceController;
use App\Controllers\DistributorController;
use App\Controllers\EmployeeController;
use App\Controllers\HotspotAdController;
use App\Controllers\HotspotController;
use App\Controllers\InventoryController;
use App\Controllers\InstallController;
use App\Controllers\JobController;
use App\Controllers\PlanController;
use App\Controllers\PrintController;
use App\Controllers\ReportController;
use App\Controllers\RouterConnectController;
use App\Controllers\RouterController;
use App\Controllers\SalesController;
use App\Controllers\SearchController;
use App\Controllers\ServerController;
use App\Controllers\SessionController;
use App\Controllers\SettingsController;
use App\Controllers\ShortenerController;
use App\Controllers\SubscriberController;
use App\Controllers\TelegramController;
use App\Controllers\TicketController;
use App\Controllers\TunnelController;
use App\Controllers\UsageController;
use App\Core\Router;

return static function (Router $r): void {
    $public = ['auth' => false, 'csrf' => false, 'cors' => true];

    // ------------------------------------------------------------------
    // الواجهات العامة (صفحة الهوتسبوت، أحداث جهاز MikroTik، المهمة المجدولة)
    // ------------------------------------------------------------------
    $r->post('/api/hotspot/login', [ApiController::class, 'hotspotLogin'], $public);
    $r->get('/api/hotspot/status', [ApiController::class, 'hotspotStatus'], $public);
    $r->get('/api/hotspot/info', [ApiController::class, 'hotspotInfo'], $public);
    $r->get('/api/hotspot/ads', [ApiController::class, 'hotspotAds'], $public);
    $r->post('/api/hotspot/ads/{id}/click', [ApiController::class, 'hotspotAdClick'], $public);
    $r->get('/api/router/event', [ApiController::class, 'routerEvent'], $public);
    $r->get('/hotspot/sdk/config/{key:[a-f0-9]{32}}.js', [ApiController::class, 'sdkConfig'], $public);
    $r->get('/hotspot/sdk/sohaibnet.js', [ApiController::class, 'sdkFile'], $public);
    // سكربت ربط جهاز MikroTik يجلبه الجهاز بنفسه (رمز سري صالح 24 ساعة)
    $r->get('/routers/setup/{token:[a-f0-9]{40}}.rsc', [RouterConnectController::class, 'fetch'], ['auth' => false, 'csrf' => false]);
    $r->get('/cron', [ApiController::class, 'cron'], ['auth' => false, 'csrf' => false]);
    if (!\App\Core\Platform::enabled()) {
        $r->get('/health', [ApiController::class, 'health'], ['auth' => false, 'csrf' => false]);
        // صفحة الخادم لمالك النظام (تثبيت شبكة واحدة على خادم مُدار)
        $r->get('/server', [ServerController::class, 'index']);
        $r->get('/server/status', [ServerController::class, 'status']);
        $r->post('/server/backup', [ServerController::class, 'backup']);
        $r->post('/server/settings', [ServerController::class, 'settings']);
        $r->post('/server/test', [ServerController::class, 'test']);
        $r->post('/server/detect', [ServerController::class, 'detect']);
    }
    $r->get('/manifest.webmanifest', [BrandController::class, 'manifest'], ['auth' => false, 'csrf' => false]);
    $r->get('/lang/{code:ar|en}', [\App\Controllers\LangController::class, 'switch'], ['auth' => false, 'csrf' => false]);
    // قياس سرعة الصفحات من جهاز المستخدم (في وضع المنصة يستقبله مسار المنصة الجذري)
    $r->post('/api/rum', [\App\Controllers\RumController::class, 'store'], ['auth' => false, 'csrf' => false]);
    $r->post('/telegram/webhook/{secret:[a-f0-9]{32}}', [TelegramController::class, 'webhook'], ['auth' => false, 'csrf' => false]);
    $r->post('/telegram/customer/{secret:[a-f0-9]{32}}', [TelegramController::class, 'customerWebhook'], ['auth' => false, 'csrf' => false]);

    // ------------------------------------------------------------------
    // تطبيق الجوال (الدخول برمز التطبيق، والصلاحيات نفسها في الموقع)
    // ------------------------------------------------------------------
    $v = '/api/app/v1';
    $app = static fn (string|array|null $perm = null): array => ['token' => true, 'csrf' => false, 'perm' => $perm];
    $r->get($v . '/info', [MobileController::class, 'info'], ['auth' => false, 'csrf' => false]);
    $r->post($v . '/login', [MobileController::class, 'login'], ['auth' => false, 'csrf' => false]);
    $r->post($v . '/logout', [MobileController::class, 'logout'], $app());
    $r->get($v . '/me', [MobileController::class, 'me'], $app());
    $r->get($v . '/devices', [MobileController::class, 'devices'], $app());
    $r->post($v . '/devices/{id}/revoke', [MobileController::class, 'deviceRevoke'], $app());
    $r->get($v . '/dashboard', [MobileController::class, 'dashboard'], $app());
    $r->get($v . '/routers', [MobileController::class, 'routers'], $app('routers.view'));
    $r->get($v . '/routers/{id}', [MobileController::class, 'router'], $app('routers.view'));
    $r->get($v . '/routers/{id}/live', [RouterController::class, 'live'], $app('routers.view'));
    $r->post($v . '/routers/{id}/sync', [RouterController::class, 'sync'], $app('routers.view'));
    $r->post($v . '/routers/{id}/reboot', [RouterController::class, 'reboot'], $app('routers.reboot'));
    $r->get($v . '/sessions', [MobileController::class, 'sessions'], $app('sessions.view'));
    $r->post($v . '/sessions/{id}/disconnect', [SessionController::class, 'disconnect'], $app('sessions.disconnect'));
    $r->get($v . '/alerts', [MobileController::class, 'alerts'], $app('alerts.view'));
    $r->post($v . '/alerts/read-all', [AlertController::class, 'readAll'], $app('alerts.view'));
    $r->post($v . '/alerts/{id}/read', [AlertController::class, 'read'], $app('alerts.view'));
    $r->get($v . '/reports', [MobileController::class, 'report'], $app(['reports.view', 'reports.financial']));
    $r->get($v . '/cards', [MobileDataController::class, 'cards'], $app('cards.view'));
    $r->get($v . '/cards/lookup', [MobileDataController::class, 'lookup'], $app(['check.use', 'cards.view']));
    $r->get($v . '/cards/options', [MobileDataController::class, 'generateOptions'], $app('cards.generate'));
    $r->post($v . '/cards/generate', [CardController::class, 'generate'], $app('cards.generate'));
    $r->post($v . '/cards/bulk', [CardController::class, 'bulk'], $app('cards.view'));
    $r->get($v . '/cards/{id}', [MobileDataController::class, 'card'], $app(['check.use', 'cards.view']));
    $r->post($v . '/cards/{id}/action', [CheckController::class, 'cardAction'], $app('check.use'));
    $r->get($v . '/categories', [MobileDataController::class, 'categories'], $app(['categories.view', 'cards.view', 'cards.generate', 'sales.create']));
    $r->get($v . '/batches', [MobileDataController::class, 'batches'], $app('cards.view'));
    $r->get($v . '/subscribers', [MobileDataController::class, 'subscribers'], $app('subscribers.view'));
    $r->get($v . '/subscribers/options', [MobileDataController::class, 'subscriberOptions'], $app(['subscribers.manage', 'subscribers.view']));
    $r->post($v . '/subscribers', [SubscriberController::class, 'store'], $app('subscribers.manage'));
    $r->get($v . '/subscribers/{id}', [MobileDataController::class, 'subscriber'], $app('subscribers.view'));
    $r->post($v . '/subscribers/{id}', [SubscriberController::class, 'update'], $app('subscribers.manage'));
    $r->post($v . '/subscribers/{id}/delete', [SubscriberController::class, 'destroy'], $app('subscribers.delete'));
    $r->post($v . '/subscribers/{id}/renew', [SubscriberController::class, 'renew'], $app('subscribers.renew'));
    $r->post($v . '/subscribers/{id}/transaction', [SubscriberController::class, 'transaction'], $app('subscribers.payments'));
    $r->post($v . '/subscribers/{id}/status', [SubscriberController::class, 'status'], $app('subscribers.status'));
    $r->post($v . '/subscribers/{id}/action', [SubscriberController::class, 'action'], $app('subscribers.manage'));
    $r->get($v . '/sales', [MobileDataController::class, 'sales'], $app('sales.view'));
    $r->get($v . '/sales/options', [MobileDataController::class, 'saleOptions'], $app('sales.create'));
    $r->post($v . '/sales', [SalesController::class, 'store'], $app('sales.create'));
    $r->get($v . '/sales/{id}', [MobileDataController::class, 'sale'], $app('sales.view'));
    $r->post($v . '/sales/{id}/cancel', [SalesController::class, 'cancel'], $app('sales.cancel'));
    $r->get($v . '/distributors', [MobileDataController::class, 'distributors'], $app(['distributors.view', 'sales.create']));
    $r->get($v . '/sms', [MobileDataController::class, 'smsInfo'], $app('sms.send'));
    $r->get($v . '/sms/count', [SmsController::class, 'count'], $app('sms.send'));
    $r->post($v . '/sms', [SmsController::class, 'send'], $app('sms.send'));
    $r->get($v . '/sms/log', [MobileDataController::class, 'smsLog'], $app(['sms.log', 'sms.send']));
    $r->get($v . '/sms/log/{id}', [MobileDataController::class, 'smsShow'], $app(['sms.log', 'sms.send']));
    $r->get($v . '/inventory', [MobileDataController::class, 'inventory'], $app('inventory.view'));
    $r->get($v . '/inventory/{id}', [MobileDataController::class, 'inventoryItem'], $app('inventory.view'));
    $r->post($v . '/inventory/{id}/move', [InventoryController::class, 'move'], $app('inventory.manage'));
    $r->post($v . '/jobs/{id}/step', [JobController::class, 'step'], $app());

    // ------------------------------------------------------------------
    // الدخول والحساب
    // ------------------------------------------------------------------
    $r->get('/login', [AuthController::class, 'showLogin'], ['auth' => false, 'guest' => true]);
    $r->post('/login', [AuthController::class, 'login'], ['auth' => false, 'guest' => true]);
    $r->get('/login/verify', [AuthController::class, 'verifyForm'], ['auth' => false, 'guest' => true]);
    $r->post('/login/verify', [AuthController::class, 'verify'], ['auth' => false, 'guest' => true]);
    $r->post('/login/device', [AuthController::class, 'deviceLogin'], ['auth' => false, 'guest' => true]);
    $r->post('/logout', [AuthController::class, 'logout']);
    if (\App\Core\Platform::enabled()) {
        $r->get('/auth/ticket/{token:[a-f0-9]{64}}', [TicketController::class, 'redeem'], ['auth' => false]);
        $r->post('/impersonation/exit', [TicketController::class, 'exitImpersonation']);
        $r->post('/networks/{id}/switch', [TicketController::class, 'switchNetwork']);
        // اشتراك المنصة والدعم من داخل نظام الزبون
        $r->get('/billing', [\App\Controllers\BillingController::class, 'index'], ['perm' => 'billing.manage']);
        $r->post('/billing', [\App\Controllers\BillingController::class, 'submit'], ['perm' => 'billing.manage']);
        $r->post('/billing/email', [\App\Controllers\BillingController::class, 'email'], ['perm' => 'billing.manage']);
        $r->get('/billing/receipt/{id}', [\App\Controllers\BillingController::class, 'receipt'], ['perm' => 'billing.manage']);
        $r->get('/billing/invoice/{id}', [\App\Controllers\BillingController::class, 'invoice'], ['perm' => 'billing.manage']);
        $r->get('/support', [\App\Controllers\SupportController::class, 'index'], ['perm' => 'support.use']);
        $r->post('/support', [\App\Controllers\SupportController::class, 'store'], ['perm' => 'support.use']);
        $r->get('/support/{id}', [\App\Controllers\SupportController::class, 'show'], ['perm' => 'support.use']);
        $r->post('/support/{id}/reply', [\App\Controllers\SupportController::class, 'reply'], ['perm' => 'support.use']);
        $r->post('/support/{id}/close', [\App\Controllers\SupportController::class, 'close'], ['perm' => 'support.use']);
        // رسائل المشتركين على واتساب المنصة التي حوّلها مساعد المنصة لصاحب الشبكة
        $r->get('/inbox', [\App\Controllers\InboxController::class, 'index'], ['perm' => 'subscribers.messages']);
        $r->get('/inbox/{id}', [\App\Controllers\InboxController::class, 'show'], ['perm' => 'subscribers.messages']);
        $r->get('/inbox/{id}/poll', [\App\Controllers\InboxController::class, 'poll'], ['perm' => 'subscribers.messages']);
        $r->post('/inbox/{id}/reply', [\App\Controllers\InboxController::class, 'reply'], ['perm' => 'subscribers.messages']);
        $r->post('/inbox/{id}/mode', [\App\Controllers\InboxController::class, 'mode'], ['perm' => 'subscribers.messages']);
        $r->post('/inbox/{id}/close', [\App\Controllers\InboxController::class, 'close'], ['perm' => 'subscribers.messages']);
    }
    $r->get('/install', [InstallController::class, 'done'], ['auth' => false]);
    $r->get('/account', [AccountController::class, 'show']);
    $r->post('/account', [AccountController::class, 'update']);
    $r->post('/account/theme', [AccountController::class, 'theme']);
    $r->post('/account/devices', [AccountController::class, 'deviceEnroll']);
    $r->post('/account/devices/{id}/revoke', [AccountController::class, 'deviceRevoke']);
    $r->post('/account/2fa/start', [AccountController::class, 'totpStart']);
    $r->post('/account/2fa/cancel', [AccountController::class, 'totpCancel']);
    $r->post('/account/2fa/confirm', [AccountController::class, 'totpConfirm']);
    $r->post('/account/2fa/disable', [AccountController::class, 'totpDisable']);
    $r->post('/account/2fa/codes', [AccountController::class, 'totpCodes']);
    $r->get('/search', [SearchController::class, 'index']);

    // ------------------------------------------------------------------
    // لوحة التحكم
    // ------------------------------------------------------------------
    $r->get('/', [DashboardController::class, 'index'], ['perm' => 'dashboard.view']);
    $r->get('/dashboard/data', [DashboardController::class, 'data'], ['perm' => 'dashboard.view']);

    // ------------------------------------------------------------------
    // أجهزة MikroTik
    // ------------------------------------------------------------------
    $r->get('/routers', [RouterController::class, 'index'], ['perm' => 'routers.view']);
    $r->get('/routers/create', [RouterController::class, 'create'], ['perm' => 'routers.manage']);
    $r->get('/routers/connect', [RouterConnectController::class, 'create'], ['perm' => 'routers.manage']);
    $r->post('/routers/connect', [RouterConnectController::class, 'store'], ['perm' => 'routers.manage']);
    $r->get('/routers/connect/{id}', [RouterConnectController::class, 'show'], ['perm' => 'routers.manage']);
    $r->get('/routers/connect/{id}/status', [RouterConnectController::class, 'status'], ['perm' => 'routers.manage']);
    $r->post('/routers/connect/{id}/token', [RouterConnectController::class, 'token'], ['perm' => 'routers.manage']);
    $r->get('/routers/connect/{id}/script', [RouterConnectController::class, 'download'], ['perm' => 'routers.manage']);
    $r->post('/routers', [RouterController::class, 'store'], ['perm' => 'routers.manage']);
    $r->post('/routers/test', [RouterController::class, 'test'], ['perm' => 'routers.manage']);
    $r->get('/routers/{id}', [RouterController::class, 'show'], ['perm' => 'routers.view']);
    $r->get('/routers/{id}/edit', [RouterController::class, 'edit'], ['perm' => 'routers.manage']);
    $r->post('/routers/{id}', [RouterController::class, 'update'], ['perm' => 'routers.manage']);
    $r->post('/routers/{id}/delete', [RouterController::class, 'destroy'], ['perm' => 'routers.manage']);
    $r->get('/routers/{id}/live', [RouterController::class, 'live'], ['perm' => 'routers.view']);
    $r->post('/routers/{id}/sync', [RouterController::class, 'sync'], ['perm' => 'routers.view']);
    $r->post('/routers/{id}/reboot', [RouterController::class, 'reboot'], ['perm' => 'routers.reboot']);
    $r->post('/routers/{id}/action', [RouterController::class, 'action'], ['perm' => 'routers.control']);
    $r->post('/routers/{id}/disconnect', [RouterController::class, 'disconnect'], ['perm' => 'sessions.disconnect']);
    $r->post('/routers/{id}/backup', [RouterController::class, 'backup'], ['perm' => 'routers.backup']);
    $r->post('/routers/{id}/ping', [RouterController::class, 'ping'], ['perm' => 'routers.control']);
    $r->post('/routers/{id}/diagnose', [RouterController::class, 'diagnose'], ['perm' => 'routers.view']);
    // فحص الكابلات
    $r->get('/usage', [UsageController::class, 'index'], ['perm' => 'usage.view']);
    $r->post('/usage/cap', [UsageController::class, 'cap'], ['perm' => 'usage.limit']);
    $r->post('/usage/uncap', [UsageController::class, 'uncap'], ['perm' => 'usage.limit']);
    $r->post('/usage/settings', [UsageController::class, 'settings'], ['perm' => 'settings.manage']);
    $r->get('/cables', [CableController::class, 'index'], ['perm' => 'routers.cables']);
    $r->get('/cables/ports', [CableController::class, 'ports'], ['perm' => 'routers.cables']);
    $r->post('/cables/test', [CableController::class, 'test'], ['perm' => 'routers.cables']);
    $r->post('/cables/deep', [CableController::class, 'deep'], ['perm' => 'routers.cables']);
    $r->get('/cables/checks/{id}', [CableController::class, 'show'], ['perm' => 'routers.cables']);
    $r->post('/cables/settings', [CableController::class, 'settings'], ['perm' => 'routers.cables']);
    $r->post('/routers/{id}/tasks', [RouterController::class, 'tasks'], ['perm' => 'routers.manage']);
    $r->post('/routers/{id}/key', [RouterController::class, 'regenerateKey'], ['perm' => 'routers.manage']);
    $r->post('/routers/{id}/import', [RouterController::class, 'import'], ['perm' => ['cards.import', 'subscribers.manage']]);

    // الأنفاق الآمنة لأجهزة MikroTik التي لا تملك عنواناً عاماً
    $r->get('/tunnels', [TunnelController::class, 'index'], ['perm' => 'routers.manage']);
    $r->post('/tunnels', [TunnelController::class, 'store'], ['perm' => 'routers.manage']);
    $r->get('/tunnels/{id}/script', [TunnelController::class, 'script'], ['perm' => 'routers.manage']);
    $r->get('/tunnels/{id}/download', [TunnelController::class, 'download'], ['perm' => 'routers.manage']);
    $r->post('/tunnels/{id}/regenerate', [TunnelController::class, 'regenerate'], ['perm' => 'routers.manage']);
    $r->post('/tunnels/{id}/delete', [TunnelController::class, 'destroy'], ['perm' => 'routers.manage']);

    // مراقبة البطاريات والطاقة الشمسية
    $r->get('/power', [\App\Controllers\PowerController::class, 'index'], ['perm' => 'power.view']);
    $r->get('/power/{id}', [\App\Controllers\PowerController::class, 'show'], ['perm' => 'power.view']);
    $r->get('/power/{id}/data', [\App\Controllers\PowerController::class, 'data'], ['perm' => 'power.view']);
    $r->post('/power/{id}', [\App\Controllers\PowerController::class, 'update'], ['perm' => 'power.manage']);
    // سجل الانقطاعات وتعويض المشتركين
    $r->get('/outages', [\App\Controllers\OutageController::class, 'index'], ['perm' => 'outages.view']);
    $r->post('/outages', [\App\Controllers\OutageController::class, 'store'], ['perm' => 'outages.manage']);
    $r->get('/outages/settings', [\App\Controllers\OutageController::class, 'settings'], ['perm' => 'settings.manage']);
    $r->post('/outages/settings', [\App\Controllers\OutageController::class, 'settingsSave'], ['perm' => 'settings.manage']);
    $r->get('/outages/{id}', [\App\Controllers\OutageController::class, 'show'], ['perm' => 'outages.view']);
    $r->post('/outages/{id}', [\App\Controllers\OutageController::class, 'update'], ['perm' => 'outages.manage']);
    $r->post('/outages/{id}/close', [\App\Controllers\OutageController::class, 'close'], ['perm' => 'outages.manage']);
    $r->post('/outages/{id}/delete', [\App\Controllers\OutageController::class, 'destroy'], ['perm' => 'outages.manage']);
    $r->post('/outages/{id}/compensate', [\App\Controllers\OutageController::class, 'compensate'], ['perm' => 'outages.compensate']);

    $r->get('/areas', [AreaController::class, 'index'], ['perm' => 'areas.manage']);
    $r->post('/areas', [AreaController::class, 'save'], ['perm' => 'areas.manage']);
    $r->post('/areas/{id}/delete', [AreaController::class, 'destroy'], ['perm' => 'areas.manage']);

    // خريطة الشبكة والمواقع
    $r->get('/map', [MapController::class, 'index'], ['perm' => 'map.view']);
    $r->get('/map/data', [MapController::class, 'data'], ['perm' => 'map.view']);
    $r->post('/map/position', [MapController::class, 'position'], ['perm' => 'map.manage']);
    $r->post('/map/view', [MapController::class, 'saveView'], ['perm' => 'map.manage']);
    $r->post('/map/router', [MapController::class, 'placeRouter'], ['perm' => 'map.manage']);
    $r->post('/map/router/{id}/remove', [MapController::class, 'unplaceRouter'], ['perm' => 'map.manage']);
    $r->get('/sites', [SiteController::class, 'index'], ['perm' => 'map.view']);
    $r->post('/sites/links', [SiteController::class, 'linkStore'], ['perm' => 'map.manage']);
    $r->post('/sites/links/{id}/delete', [SiteController::class, 'linkDestroy'], ['perm' => 'map.manage']);
    $r->post('/sites', [SiteController::class, 'store'], ['perm' => 'map.manage']);
    $r->get('/sites/{id}', [SiteController::class, 'show'], ['perm' => 'map.view']);
    $r->post('/sites/{id}', [SiteController::class, 'update'], ['perm' => 'map.manage']);
    $r->post('/sites/{id}/delete', [SiteController::class, 'destroy'], ['perm' => 'map.manage']);

    $r->get('/inventory', [InventoryController::class, 'index'], ['perm' => 'inventory.view']);
    $r->post('/inventory', [InventoryController::class, 'store'], ['perm' => 'inventory.manage']);
    $r->get('/inventory/export', [InventoryController::class, 'export'], ['perm' => 'inventory.view']);
    $r->get('/inventory/movements', [InventoryController::class, 'movements'], ['perm' => 'inventory.view']);
    $r->get('/inventory/units', [InventoryController::class, 'units'], ['perm' => 'inventory.view']);
    $r->get('/inventory/units/{id}', [InventoryController::class, 'unit'], ['perm' => 'inventory.view']);
    $r->post('/inventory/units/{id}', [InventoryController::class, 'unitUpdate'], ['perm' => 'inventory.manage']);
    $r->post('/inventory/units/{id}/action', [InventoryController::class, 'unitAction'], ['perm' => 'inventory.manage']);
    $r->post('/inventory/units/{id}/delete', [InventoryController::class, 'unitDestroy'], ['perm' => 'inventory.manage']);
    $r->get('/inventory/{id}', [InventoryController::class, 'show'], ['perm' => 'inventory.view']);
    $r->post('/inventory/{id}', [InventoryController::class, 'update'], ['perm' => 'inventory.manage']);
    $r->post('/inventory/{id}/move', [InventoryController::class, 'move'], ['perm' => 'inventory.manage']);
    $r->post('/inventory/{id}/delete', [InventoryController::class, 'destroy'], ['perm' => 'inventory.manage']);

    // ------------------------------------------------------------------
    // المتصلون والأجهزة
    // ------------------------------------------------------------------
    $r->get('/sessions', [SessionController::class, 'online'], ['perm' => 'sessions.view']);
    $r->get('/sessions/history', [SessionController::class, 'history'], ['perm' => 'sessions.view']);
    $r->post('/sessions/{id}/disconnect', [SessionController::class, 'disconnect'], ['perm' => 'sessions.disconnect']);
    $r->get('/devices', [DeviceController::class, 'index'], ['perm' => 'devices.view']);
    $r->post('/devices/block', [DeviceController::class, 'block'], ['perm' => 'devices.block']);
    $r->post('/devices/unblock', [DeviceController::class, 'unblock'], ['perm' => 'devices.block']);

    // ------------------------------------------------------------------
    // الباقات والكروت
    // ------------------------------------------------------------------
    $r->get('/categories', [CategoryController::class, 'index'], ['perm' => 'categories.view']);
    $r->get('/categories/create', [CategoryController::class, 'create'], ['perm' => 'categories.manage']);
    $r->post('/categories', [CategoryController::class, 'store'], ['perm' => 'categories.manage']);
    $r->get('/categories/{id}/edit', [CategoryController::class, 'edit'], ['perm' => 'categories.manage']);
    $r->post('/categories/{id}', [CategoryController::class, 'update'], ['perm' => 'categories.manage']);
    $r->post('/categories/{id}/delete', [CategoryController::class, 'destroy'], ['perm' => 'categories.manage']);
    $r->post('/categories/{id}/sync', [CategoryController::class, 'sync'], ['perm' => 'categories.manage']);

    $r->get('/cards', [CardController::class, 'index'], ['perm' => 'cards.view']);
    $r->get('/cards/export', [CardController::class, 'export'], ['perm' => 'cards.export']);
    $r->get('/cards/generate', [CardController::class, 'generateForm'], ['perm' => 'cards.generate']);
    $r->post('/cards/generate', [CardController::class, 'generate'], ['perm' => 'cards.generate']);
    $r->get('/cards/import', [CardController::class, 'importForm'], ['perm' => 'cards.import']);
    $r->post('/cards/import', [CardController::class, 'importStage'], ['perm' => 'cards.import']);
    $r->get('/cards/import/{token:[a-f0-9]{24}}', [CardController::class, 'importPreview'], ['perm' => 'cards.import']);
    $r->post('/cards/import/{token:[a-f0-9]{24}}', [CardController::class, 'importCommit'], ['perm' => 'cards.import']);
    $r->post('/cards/bulk', [CardController::class, 'bulk'], ['perm' => 'cards.view']);
    // متابعة الكروت: النشطة، والمنتهية، والمُدخلة خطأ في الهوتسبوت
    $r->get('/cards/accounts', [CardAccountController::class, 'index'], ['perm' => 'cards.accounts']);
    $r->get('/cards/accounts/create', [CardAccountController::class, 'create'], ['perm' => 'cards.accounts']);
    $r->post('/cards/accounts', [CardAccountController::class, 'store'], ['perm' => 'cards.accounts']);
    $r->get('/cards/accounts/{id}/edit', [CardAccountController::class, 'edit'], ['perm' => 'cards.accounts']);
    $r->post('/cards/accounts/{id}', [CardAccountController::class, 'update'], ['perm' => 'cards.accounts']);
    $r->post('/cards/accounts/{id}/state', [CardAccountController::class, 'state'], ['perm' => 'cards.accounts']);
    $r->post('/cards/accounts/{id}/delete', [CardAccountController::class, 'destroy'], ['perm' => 'cards.accounts']);
    $r->get('/cards/monitor', [CardMonitorController::class, 'index'], ['perm' => 'cards.monitor']);
    $r->post('/cards/monitor/delete', [CardMonitorController::class, 'delete'], ['perm' => 'cards.monitor']);
    $r->get('/cards/{id}', [CardController::class, 'show'], ['perm' => 'cards.view']);

    $r->get('/batches', [BatchController::class, 'index'], ['perm' => 'cards.view']);
    $r->get('/batches/{id}', [BatchController::class, 'show'], ['perm' => 'cards.view']);
    $r->post('/batches/{id}', [BatchController::class, 'update'], ['perm' => 'cards.manage']);
    $r->post('/batches/{id}/routers', [BatchController::class, 'routers'], ['perm' => 'cards.manage']);
    $r->post('/batches/{id}/delete', [BatchController::class, 'destroy'], ['perm' => 'cards.delete']);

    $r->get('/shortener', [ShortenerController::class, 'index'], ['perm' => 'cards.short']);
    $r->post('/shortener', [ShortenerController::class, 'apply'], ['perm' => 'cards.short']);

    $r->get('/check', [CheckController::class, 'index'], ['perm' => 'check.use']);
    $r->get('/check/card/{id}', [CheckController::class, 'card'], ['perm' => 'check.use']);
    $r->post('/check/card/{id}', [CheckController::class, 'cardAction'], ['perm' => 'check.use']);

    // ------------------------------------------------------------------
    // الطباعة
    // ------------------------------------------------------------------
    $r->get('/print', [PrintController::class, 'index'], ['perm' => ['cards.print', 'print.templates']]);
    $r->get('/print/render', [PrintController::class, 'render'], ['perm' => 'cards.print']);
    $r->post('/print/mark', [PrintController::class, 'mark'], ['perm' => 'cards.print']);
    $r->get('/print/templates/create', [PrintController::class, 'create'], ['perm' => 'print.templates']);
    $r->get('/print/templates/{id}', [PrintController::class, 'edit'], ['perm' => 'print.templates']);
    $r->post('/print/templates', [PrintController::class, 'save'], ['perm' => 'print.templates']);
    $r->post('/print/templates/{id}/delete', [PrintController::class, 'destroy'], ['perm' => 'print.templates']);
    $r->post('/print/templates/{id}/duplicate', [PrintController::class, 'duplicate'], ['perm' => 'print.templates']);
    $r->post('/print/upload', [PrintController::class, 'upload'], ['perm' => 'print.templates']);

    // ------------------------------------------------------------------
    // المشتركون والباقات
    // ------------------------------------------------------------------
    $r->get('/plans', [PlanController::class, 'index'], ['perm' => 'plans.view']);
    $r->get('/plans/create', [PlanController::class, 'create'], ['perm' => 'plans.manage']);
    $r->post('/plans', [PlanController::class, 'store'], ['perm' => 'plans.manage']);
    $r->get('/plans/{id}/edit', [PlanController::class, 'edit'], ['perm' => 'plans.manage']);
    $r->post('/plans/{id}', [PlanController::class, 'update'], ['perm' => 'plans.manage']);
    $r->post('/plans/{id}/delete', [PlanController::class, 'destroy'], ['perm' => 'plans.manage']);

    $r->get('/subscribers', [SubscriberController::class, 'index'], ['perm' => 'subscribers.view']);
    $r->get('/subscribers/export', [SubscriberController::class, 'export'], ['perm' => 'subscribers.view']);
    $r->get('/subscribers/create', [SubscriberController::class, 'create'], ['perm' => 'subscribers.manage']);
    $r->get('/subscribers/import-smart', [\App\Controllers\SmartImportController::class, 'index'], ['perm' => 'subscribers.manage']);
    $r->post('/subscribers/import-smart/parse', [\App\Controllers\SmartImportController::class, 'parse'], ['perm' => 'subscribers.manage']);
    $r->post('/subscribers/import-smart/save', [\App\Controllers\SmartImportController::class, 'save'], ['perm' => 'subscribers.manage']);
    $r->post('/subscribers', [SubscriberController::class, 'store'], ['perm' => 'subscribers.manage']);
    $r->get('/subscribers/{id}', [SubscriberController::class, 'show'], ['perm' => 'subscribers.view']);
    $r->get('/subscribers/{id}/edit', [SubscriberController::class, 'edit'], ['perm' => 'subscribers.manage']);
    $r->post('/subscribers/{id}', [SubscriberController::class, 'update'], ['perm' => 'subscribers.manage']);
    $r->post('/subscribers/{id}/delete', [SubscriberController::class, 'destroy'], ['perm' => 'subscribers.delete']);
    $r->post('/subscribers/{id}/renew', [SubscriberController::class, 'renew'], ['perm' => 'subscribers.renew']);
    $r->post('/subscribers/{id}/transaction', [SubscriberController::class, 'transaction'], ['perm' => 'subscribers.payments']);
    $r->post('/subscribers/{id}/status', [SubscriberController::class, 'status'], ['perm' => 'subscribers.status']);
    $r->post('/subscribers/{id}/action', [SubscriberController::class, 'action'], ['perm' => 'subscribers.manage']);

    // ------------------------------------------------------------------
    // المبيعات والموزعون
    // ------------------------------------------------------------------
    $r->get('/sales', [SalesController::class, 'index'], ['perm' => 'sales.view']);
    $r->get('/sales/create', [SalesController::class, 'create'], ['perm' => 'sales.create']);
    $r->post('/sales', [SalesController::class, 'store'], ['perm' => 'sales.create']);
    $r->get('/sales/{id}', [SalesController::class, 'show'], ['perm' => 'sales.view']);
    $r->get('/sales/{id}/export', [SalesController::class, 'export'], ['perm' => 'cards.export']);
    $r->post('/sales/{id}/cancel', [SalesController::class, 'cancel'], ['perm' => 'sales.cancel']);

    $r->get('/distributors', [DistributorController::class, 'index'], ['perm' => 'distributors.view']);
    $r->get('/distributors/create', [DistributorController::class, 'create'], ['perm' => 'distributors.manage']);
    $r->post('/distributors', [DistributorController::class, 'store'], ['perm' => 'distributors.manage']);
    $r->get('/distributors/{id}', [DistributorController::class, 'show'], ['perm' => 'distributors.view']);
    $r->get('/distributors/{id}/statement', [DistributorController::class, 'statement'], ['perm' => 'distributors.view']);
    $r->get('/distributors/{id}/edit', [DistributorController::class, 'edit'], ['perm' => 'distributors.manage']);
    $r->post('/distributors/{id}', [DistributorController::class, 'update'], ['perm' => 'distributors.manage']);
    $r->post('/distributors/{id}/delete', [DistributorController::class, 'destroy'], ['perm' => 'distributors.manage']);
    $r->post('/distributors/{id}/payment', [DistributorController::class, 'payment'], ['perm' => 'distributors.payments']);

    // ------------------------------------------------------------------
    // ربط الهوتسبوت
    // ------------------------------------------------------------------
    $r->get('/hotspot', [HotspotController::class, 'index'], ['perm' => 'hotspot.manage']);
    $r->post('/hotspot/deploy', [HotspotController::class, 'deploy'], ['perm' => 'hotspot.manage']);
    $r->get('/hotspot/download/{id}', [HotspotController::class, 'download'], ['perm' => 'hotspot.manage']);
    $r->get('/hotspot/log', [HotspotController::class, 'log'], ['perm' => 'hotspot.log']);
    $r->get('/hotspot/ads', [HotspotAdController::class, 'index'], ['perm' => 'hotspot.ads']);
    $r->get('/hotspot/ads/create', [HotspotAdController::class, 'create'], ['perm' => 'hotspot.ads']);
    $r->post('/hotspot/ads', [HotspotAdController::class, 'store'], ['perm' => 'hotspot.ads']);
    $r->post('/hotspot/ads/settings', [HotspotAdController::class, 'settings'], ['perm' => 'hotspot.ads']);
    $r->get('/hotspot/ads/{id}/edit', [HotspotAdController::class, 'edit'], ['perm' => 'hotspot.ads']);
    $r->post('/hotspot/ads/{id}', [HotspotAdController::class, 'update'], ['perm' => 'hotspot.ads']);
    $r->post('/hotspot/ads/{id}/toggle', [HotspotAdController::class, 'toggle'], ['perm' => 'hotspot.ads']);
    $r->post('/hotspot/ads/{id}/delete', [HotspotAdController::class, 'destroy'], ['perm' => 'hotspot.ads']);

    // ------------------------------------------------------------------
    // التقارير
    // ------------------------------------------------------------------
    $r->get('/reports', [ReportController::class, 'index'], ['perm' => ['reports.view', 'reports.financial', 'inventory.view']]);
    $r->get('/reports/export', [ReportController::class, 'export'], ['perm' => ['reports.view', 'reports.financial', 'inventory.view']]);
    $r->get('/reports/print', [ReportController::class, 'print'], ['perm' => ['reports.view', 'reports.financial', 'inventory.view']]);

    // ------------------------------------------------------------------
    // الإدارة
    // ------------------------------------------------------------------
    $r->get('/employees', [EmployeeController::class, 'index'], ['perm' => 'employees.manage']);
    $r->get('/employees/create', [EmployeeController::class, 'create'], ['perm' => 'employees.manage']);
    $r->post('/employees', [EmployeeController::class, 'store'], ['perm' => 'employees.manage']);
    $r->get('/employees/{id}/edit', [EmployeeController::class, 'edit'], ['perm' => 'employees.manage']);
    $r->post('/employees/{id}', [EmployeeController::class, 'update'], ['perm' => 'employees.manage']);
    $r->post('/employees/{id}/delete', [EmployeeController::class, 'destroy'], ['perm' => 'employees.manage']);
    $r->post('/employees/{id}/logout', [EmployeeController::class, 'logoutAll'], ['perm' => 'employees.manage']);
    $r->post('/employees/{id}/2fa/reset', [EmployeeController::class, 'resetTwoFactor'], ['perm' => 'employees.manage']);

    // ------------------------------------------------------------------
    // المساعد الذكي (يظهر عند تفعيل الذكاء الاصطناعي للحساب)
    // ------------------------------------------------------------------
    $r->get('/assistant', [\App\Controllers\AssistantController::class, 'index'], ['perm' => 'assistant.use']);
    $r->post('/assistant', [\App\Controllers\AssistantController::class, 'ask'], ['perm' => 'assistant.use']);
    $r->post('/assistant/confirm', [\App\Controllers\AssistantController::class, 'confirm'], ['perm' => 'assistant.use']);

    // ------------------------------------------------------------------
    // مهام الصيانة
    // ------------------------------------------------------------------
    $mt = \App\Controllers\MaintenanceController::class;
    $mtAny = ['perm' => ['maintenance.view', 'maintenance.manage', 'maintenance.work']];
    $r->get('/maintenance', [$mt, 'index'], $mtAny);
    $r->get('/maintenance/create', [$mt, 'create'], ['perm' => 'maintenance.manage']);
    $r->post('/maintenance', [$mt, 'store'], ['perm' => 'maintenance.manage']);
    $r->get('/maintenance/{id}', [$mt, 'show'], $mtAny);
    $r->get('/maintenance/{id}/edit', [$mt, 'edit'], ['perm' => 'maintenance.manage']);
    $r->post('/maintenance/{id}', [$mt, 'update'], ['perm' => 'maintenance.manage']);
    $r->post('/maintenance/{id}/status', [$mt, 'status'], $mtAny);
    $r->post('/maintenance/{id}/parts', [$mt, 'addPart'], $mtAny);
    $r->post('/maintenance/{id}/parts/{partId}/delete', [$mt, 'removePart'], $mtAny);
    $r->post('/maintenance/{id}/delete', [$mt, 'destroy'], ['perm' => 'maintenance.manage']);

    // ------------------------------------------------------------------
    // المصاريف وصافي الربح
    // ------------------------------------------------------------------
    $r->get('/expenses', [\App\Controllers\ExpenseController::class, 'index'], ['perm' => 'expenses.view']);
    $r->get('/expenses/export', [\App\Controllers\ExpenseController::class, 'export'], ['perm' => 'expenses.view']);
    $r->post('/expenses', [\App\Controllers\ExpenseController::class, 'save'], ['perm' => 'expenses.manage']);
    $r->post('/expenses/{id}/delete', [\App\Controllers\ExpenseController::class, 'destroy'], ['perm' => 'expenses.manage']);
    $r->post('/expenses/partners', [\App\Controllers\ExpenseController::class, 'partnerSave'], ['perm' => 'profit.manage']);
    $r->post('/expenses/partners/{id}/delete', [\App\Controllers\ExpenseController::class, 'partnerDestroy'], ['perm' => 'profit.manage']);

    $r->get('/audit', [AuditController::class, 'index'], ['perm' => 'audit.view']);

    $r->get('/alerts', [AlertController::class, 'index'], ['perm' => 'alerts.view']);
    $r->post('/alerts/read-all', [AlertController::class, 'readAll'], ['perm' => 'alerts.view']);
    $r->post('/alerts/clear', [AlertController::class, 'clear'], ['perm' => 'alerts.view']);
    $r->post('/alerts/{id}/read', [AlertController::class, 'read'], ['perm' => 'alerts.view']);

    $r->get('/jobs', [JobController::class, 'index'], ['perm' => 'jobs.view']);
    $r->get('/jobs/{id}', [JobController::class, 'show']);
    $r->post('/jobs/{id}/step', [JobController::class, 'step']);
    $r->post('/jobs/{id}/cancel', [JobController::class, 'cancel']);

    $r->get('/cards/settings', [ModuleSettingsController::class, 'cards'], ['perm' => 'settings.manage']);
    $r->post('/cards/settings', [ModuleSettingsController::class, 'saveCards'], ['perm' => 'settings.manage']);
    $r->get('/subscribers/settings', [ModuleSettingsController::class, 'subscribers'], ['perm' => 'settings.manage']);
    $r->post('/subscribers/settings', [ModuleSettingsController::class, 'saveSubscribers'], ['perm' => 'settings.manage']);
    $r->get('/hotspot/settings', [ModuleSettingsController::class, 'hotspot'], ['perm' => 'settings.manage']);
    $r->post('/hotspot/settings', [ModuleSettingsController::class, 'saveHotspot'], ['perm' => 'settings.manage']);
    $r->get('/sms', [SmsController::class, 'compose'], ['perm' => 'sms.send']);
    $r->post('/sms', [SmsController::class, 'send'], ['perm' => 'sms.send']);
    $r->get('/sms/count', [SmsController::class, 'count'], ['perm' => 'sms.send']);
    $r->get('/sms/recipients', [SmsController::class, 'recipients'], ['perm' => 'sms.send']);
    $r->get('/sms/log', [SmsController::class, 'log'], ['perm' => ['sms.log', 'sms.send']]);
    $r->get('/sms/log/{id}', [SmsController::class, 'show'], ['perm' => ['sms.log', 'sms.send']]);
    $r->post('/sms/log/{id}/retry', [SmsController::class, 'retry'], ['perm' => 'sms.send']);
    $r->get('/sms/settings', [SmsController::class, 'settings'], ['perm' => 'sms.manage']);
    $r->post('/sms/settings', [SmsController::class, 'settingsSave'], ['perm' => 'sms.manage']);
    $r->post('/sms/status', [SmsController::class, 'status'], ['perm' => ['sms.manage', 'sms.send']]);
    $r->post('/sms/test', [SmsController::class, 'test'], ['perm' => 'sms.manage']);
    $r->post('/sms/templates', [SmsController::class, 'templateSave'], ['perm' => 'sms.manage']);
    $r->post('/sms/templates/{id}/delete', [SmsController::class, 'templateDelete'], ['perm' => 'sms.manage']);
    $r->get('/telegram', [TelegramController::class, 'index'], ['perm' => 'telegram.manage']);
    $r->post('/telegram', [TelegramController::class, 'save'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/test', [TelegramController::class, 'test'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/detect', [TelegramController::class, 'detect'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/customer-test', [TelegramController::class, 'customerTest'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/connect', [TelegramController::class, 'connect'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/report', [TelegramController::class, 'report'], ['perm' => 'telegram.manage']);
    $r->get('/telegram/links', [TelegramController::class, 'links'], ['perm' => 'telegram.manage']);
    $r->post('/telegram/links/{id}/delete', [TelegramController::class, 'unlink'], ['perm' => 'telegram.manage']);

    $r->get('/settings', [SettingsController::class, 'index'], ['perm' => 'settings.manage']);
    $r->post('/settings', [SettingsController::class, 'update'], ['perm' => 'settings.manage']);

    $r->post('/settings/backup', [SettingsController::class, 'backupCreate'], ['perm' => 'backup.manage']);
    $r->get('/settings/backup/download', [SettingsController::class, 'backupDownload'], ['perm' => 'backup.manage']);
    $r->post('/settings/backup/delete', [SettingsController::class, 'backupDelete'], ['perm' => 'backup.manage']);
    $r->post('/settings/backup/restore', [SettingsController::class, 'backupRestore'], ['perm' => 'backup.manage']);
};
