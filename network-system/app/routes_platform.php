<?php
declare(strict_types=1);

use App\Controllers\AdminAiController;
use App\Controllers\AdminBillingController;
use App\Controllers\AdminBotController;
use App\Controllers\AdminController;
use App\Controllers\AdminSupportController;
use App\Controllers\AdminSmsController;
use App\Controllers\BotPublicController;
use App\Controllers\BrandController;
use App\Controllers\DesignController;
use App\Controllers\MobileController;
use App\Controllers\PlatformController;
use App\Controllers\ServerController;
use App\Core\Router;

/*
 * مسارات المنصة (وضع تعدد الزبائن): الصفحات العامة ولوحة المسؤول.
 * أنظمة الزبائن على /{اسم-الحساب}/... بمسارات routes.php.
 */
return static function (Router $r): void {
    $pub = ['auth' => false];
    $r->get('/api/app/v1/info', [MobileController::class, 'info'], ['auth' => false, 'csrf' => false]);
    $r->get('/', [PlatformController::class, 'home'], $pub);
    $r->get('/signup', [PlatformController::class, 'signupForm'], $pub);
    $r->post('/signup', [PlatformController::class, 'signup'], $pub);
    $r->get('/signup/check', [PlatformController::class, 'checkSlug'], $pub);
    $r->get('/signup/username', [PlatformController::class, 'checkUsername'], $pub);
    $r->post('/signup/code', [PlatformController::class, 'signupCode'], $pub);
    $r->get('/signup/verify', [PlatformController::class, 'signupVerifyForm'], $pub);
    $r->post('/signup/verify', [PlatformController::class, 'signupVerify'], $pub);
    // استعادة كلمة المرور برمز تحقق إلى الهاتف
    $r->get('/auth/forgot', [PlatformController::class, 'forgotForm'], $pub);
    $r->post('/auth/forgot', [PlatformController::class, 'forgotSend'], $pub);
    $r->get('/auth/reset', [PlatformController::class, 'resetForm'], $pub);
    $r->post('/auth/reset', [PlatformController::class, 'resetSave'], $pub);
    $r->post('/auth/resend', [PlatformController::class, 'resetResend'], $pub);
    $r->post('/login/choose', [PlatformController::class, 'loginChoose'], $pub);
    $r->get('/login', [PlatformController::class, 'loginForm'], $pub);
    $r->post('/login', [PlatformController::class, 'login'], $pub);
    $r->get('/manifest.webmanifest', [BrandController::class, 'manifest'], $pub + ['csrf' => false]);
    $r->get('/lang/{code:ar|en}', [\App\Controllers\LangController::class, 'switch'], ['auth' => false, 'csrf' => false]);
    $r->get('/cron', [PlatformController::class, 'cron'], ['auth' => false, 'csrf' => false]);
    $r->get('/health', [PlatformController::class, 'health'], ['auth' => false, 'csrf' => false]);
    // بوت خدمة الزبائن: Webhook واتساب، ونافذة المحادثة (في موقع المنصة أو أي موقع)، وصفحة المحادثة
    $open = ['auth' => false, 'csrf' => false];
    $r->get('/api/whatsapp/{hook:[a-f0-9]{32}}', [BotPublicController::class, 'whatsappVerify'], $open);
    $r->post('/api/whatsapp/{hook:[a-f0-9]{32}}', [BotPublicController::class, 'whatsappWebhook'], $open);
    $r->post('/api/bot/{key:[a-f0-9]{32}}/send', [BotPublicController::class, 'webSend'], $open);
    $r->get('/api/bot/{key:[a-f0-9]{32}}/poll', [BotPublicController::class, 'webPoll'], $open);
    $r->get('/help', [BotPublicController::class, 'webPage'], $open);
    // قياس سرعة الصفحات من جهاز المستخدم (لوحة المسؤول وأنظمة الشبكات وصفحات الدخول)
    $r->post('/api/rum', [\App\Controllers\RumController::class, 'store'], $open);

    $a = ['auth' => false, 'admin' => true];
    $r->get('/admin/login', [AdminController::class, 'loginForm'], ['auth' => false, 'adminGuest' => true]);
    $r->post('/admin/login', [AdminController::class, 'login'], ['auth' => false, 'adminGuest' => true]);
    $r->post('/admin/logout', [AdminController::class, 'logout'], $a);
    $r->get('/admin', [AdminController::class, 'dashboard'], $a);
    $r->get('/admin/tenants', [AdminController::class, 'tenants'], $a + ['aperm' => ['tenants.view', 'tenants.manage', 'tenants.access']]);
    $r->get('/admin/tenants/create', [AdminController::class, 'tenant_create'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants', [AdminController::class, 'tenant_store'], $a + ['aperm' => 'tenants.manage']);
    $r->get('/admin/tenants/{id}', [AdminController::class, 'tenant_show'], $a + ['aperm' => ['tenants.view', 'tenants.manage', 'tenants.access']]);
    $r->post('/admin/tenants/{id}', [AdminController::class, 'tenant_update'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/{id}/status', [AdminController::class, 'tenant_status'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/{id}/impersonate', [AdminController::class, 'tenant_impersonate'], $a + ['aperm' => 'tenants.access']);
    $r->post('/admin/tenants/{id}/password', [AdminController::class, 'tenant_password'], $a + ['aperm' => 'tenants.access']);
    $r->post('/admin/tenants/{id}/twofactor', [AdminController::class, 'tenant_twofactor'], $a + ['aperm' => 'tenants.access']);
    $r->post('/admin/tenants/{id}/alias/delete', [AdminController::class, 'tenant_alias_delete'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/{id}/delete', [AdminController::class, 'tenant_destroy'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/{id}/backup', [AdminController::class, 'tenant_backup'], $a + ['aperm' => 'tenants.manage']);
    $r->get('/admin/payments', [AdminController::class, 'payments'], $a + ['aperm' => ['billing.view', 'billing.manage']]);
    $r->post('/admin/payments/{id}/delete', [AdminController::class, 'paymentDelete'], $a + ['aperm' => 'billing.manage']);
    $r->get('/admin/users', [AdminController::class, 'users'], $a + ['aperm' => ['tenants.view', 'tenants.manage', 'tenants.access']]);
    $r->get('/admin/activity', [AdminController::class, 'activity'], $a + ['aperm' => ['tenants.view', 'tenants.manage', 'tenants.access']]);
    $r->get('/admin/design', [DesignController::class, 'index'], $a + ['aperm' => 'design.manage']);
    $r->post('/admin/design', [DesignController::class, 'save'], $a + ['aperm' => 'design.manage']);
    $r->get('/admin/design/preview', [DesignController::class, 'preview'], $a + ['aperm' => 'design.manage']);
    $r->get('/admin/landing', [DesignController::class, 'landing'], $a + ['aperm' => 'design.manage']);
    $r->post('/admin/landing', [DesignController::class, 'saveLanding'], $a + ['aperm' => 'design.manage']);
    $r->get('/admin/sms', [AdminSmsController::class, 'index'], $a + ['aperm' => ['messages.send', 'settings.manage']]);
    $r->post('/admin/sms', [AdminSmsController::class, 'send'], $a + ['aperm' => 'messages.send']);
    $r->post('/admin/sms/settings', [AdminSmsController::class, 'settings'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/sms/features', [AdminSmsController::class, 'features'], $a + ['aperm' => 'settings.manage']);
    $r->get('/admin/sms/recipients', [AdminSmsController::class, 'recipients'], $a + ['aperm' => 'messages.send']);
    $r->get('/admin/mail', [\App\Controllers\AdminMailController::class, 'index'], $a + ['aperm' => ['messages.send', 'settings.manage']]);
    $r->post('/admin/mail/settings', [\App\Controllers\AdminMailController::class, 'settings'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/mail/features', [\App\Controllers\AdminMailController::class, 'features'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/mail/test', [\App\Controllers\AdminMailController::class, 'test'], $a + ['aperm' => 'settings.manage']);
    $r->get('/admin/mail/preview', [\App\Controllers\AdminMailController::class, 'preview'], $a + ['aperm' => ['messages.send', 'settings.manage']]);
    $r->post('/admin/mail/preview', [\App\Controllers\AdminMailController::class, 'preview'], $a + ['aperm' => ['messages.send', 'settings.manage']]);
    $r->post('/admin/mail/broadcast', [\App\Controllers\AdminMailController::class, 'broadcast'], $a + ['aperm' => 'messages.send']);
    $r->post('/admin/sms/status', [AdminSmsController::class, 'status'], $a + ['aperm' => 'messages.send']);
    $r->get('/admin/server', [ServerController::class, 'index'], $a + ['aperm' => 'settings.manage']);
    $r->get('/admin/server/status', [ServerController::class, 'status'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/server/backup', [ServerController::class, 'backup'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/server/settings', [ServerController::class, 'settings'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/server/test', [ServerController::class, 'test'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/server/detect', [ServerController::class, 'detect'], $a + ['aperm' => 'settings.manage']);
    $r->get('/admin/tenants/import', [AdminController::class, 'tenant_import'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/import', [AdminController::class, 'tenant_import_store'], $a + ['aperm' => 'tenants.manage']);
    // الباقات والاشتراك والتحويلات والفواتير
    $r->get('/admin/plans', [AdminBillingController::class, 'plans'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/plans', [AdminBillingController::class, 'planSave'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/plans/{id}/delete', [AdminBillingController::class, 'planDelete'], $a + ['aperm' => 'billing.manage']);
    $r->get('/admin/billing', [AdminBillingController::class, 'settings'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/billing', [AdminBillingController::class, 'settingsSave'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/banks', [AdminBillingController::class, 'bankSave'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/banks/{id}/delete', [AdminBillingController::class, 'bankDelete'], $a + ['aperm' => 'billing.manage']);
    $r->get('/admin/transfers', [AdminBillingController::class, 'transfers'], $a + ['aperm' => ['billing.view', 'billing.manage']]);
    $r->get('/admin/transfers/{id}/file', [AdminBillingController::class, 'transferFile'], $a + ['aperm' => ['billing.view', 'billing.manage']]);
    $r->post('/admin/transfers/{id}/approve', [AdminBillingController::class, 'transferApprove'], $a + ['aperm' => 'billing.manage']);
    $r->post('/admin/transfers/{id}/reject', [AdminBillingController::class, 'transferReject'], $a + ['aperm' => 'billing.manage']);
    $r->get('/admin/payments/{id}/invoice', [AdminBillingController::class, 'invoice'], $a + ['aperm' => ['billing.view', 'billing.manage']]);
    // الذكاء الاصطناعي ومساعد المسؤول والتحليلات
    $r->get('/admin/ai', [AdminAiController::class, 'index'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/ai', [AdminAiController::class, 'save'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/ai/toggle', [AdminAiController::class, 'toggle'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/ai/test', [AdminAiController::class, 'test'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/ai/gemini-models', [AdminAiController::class, 'geminiModels'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/ai/tenants', [AdminAiController::class, 'tenants'], $a + ['aperm' => 'ai.manage']);
    $r->get('/admin/assistant', [AdminAiController::class, 'assistant'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/assistant', [AdminAiController::class, 'ask'], $a + ['aperm' => 'ai.manage']);
    $r->post('/admin/assistant/confirm', [AdminAiController::class, 'assistantConfirm'], $a + ['aperm' => 'ai.manage']);
    $r->get('/admin/insights', [AdminAiController::class, 'insights'], $a + ['aperm' => 'ai.manage']);
    // تذاكر الدعم والإعلانات
    $r->get('/admin/tickets', [AdminSupportController::class, 'tickets'], $a + ['aperm' => 'support.manage']);
    $r->get('/admin/tickets/{id}', [AdminSupportController::class, 'ticketShow'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/tickets/{id}/reply', [AdminSupportController::class, 'ticketReply'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/tickets/{id}/state', [AdminSupportController::class, 'ticketState'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/tickets/{id}/suggest', [AdminSupportController::class, 'ticketSuggest'], $a + ['aperm' => 'support.manage']);
    $r->get('/admin/announcements', [AdminSupportController::class, 'announcements'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/announcements', [AdminSupportController::class, 'announcementSave'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/announcements/{id}/delete', [AdminSupportController::class, 'announcementDelete'], $a + ['aperm' => 'support.manage']);
    $r->post('/admin/announcements/{id}/toggle', [AdminSupportController::class, 'announcementToggle'], $a + ['aperm' => 'support.manage']);
    // بوت خدمة الزبائن للمنصة: المحادثات والإعدادات
    $bc = $a + ['aperm' => ['bot.chats', 'bot.manage']];
    $bm = $a + ['aperm' => 'bot.manage'];
    $r->get('/admin/bot', [AdminBotController::class, 'index'], $bc);
    $r->get('/admin/bot/chats/{id}', [AdminBotController::class, 'show'], $bc);
    $r->get('/admin/bot/chats/{id}/poll', [AdminBotController::class, 'poll'], $bc);
    $r->post('/admin/bot/chats/{id}/reply', [AdminBotController::class, 'reply'], $bc);
    $r->post('/admin/bot/chats/{id}/mode', [AdminBotController::class, 'mode'], $bc);
    $r->post('/admin/bot/chats/{id}/handler', [AdminBotController::class, 'handler'], $bc);
    $r->post('/admin/bot/chats/{id}/close', [AdminBotController::class, 'close'], $bc);
    $r->post('/admin/bot/chats/{id}/identify', [AdminBotController::class, 'identify'], $bc);
    $r->get('/admin/bot/settings', [AdminBotController::class, 'settings'], $bm);
    $r->post('/admin/bot/settings', [AdminBotController::class, 'save'], $bm);
    $r->post('/admin/bot/settings/try', [AdminBotController::class, 'try'], $bm);
    $r->post('/admin/bot/settings/whatsapp-test', [AdminBotController::class, 'whatsappTest'], $bm);
    $r->post('/admin/bot/settings/erp-test', [AdminBotController::class, 'erpTest'], $bm);
    $r->post('/admin/bot/settings/web-key', [AdminBotController::class, 'webKey'], $bm);
    // الأمان والإشعارات
    $r->get('/admin/login/verify', [AdminController::class, 'verifyForm'], ['auth' => false]);
    $r->post('/admin/login/verify', [AdminController::class, 'verify'], ['auth' => false]);
    $r->get('/admin/security', [AdminController::class, 'security'], $a);
    $r->post('/admin/security/totp', [AdminController::class, 'totpStart'], $a);
    $r->post('/admin/security/totp/confirm', [AdminController::class, 'totpConfirm'], $a);
    $r->post('/admin/security/totp/disable', [AdminController::class, 'totpDisable'], $a);
    $r->post('/admin/security/totp/codes', [AdminController::class, 'totpCodes'], $a);
    $r->get('/admin/notifications', [AdminController::class, 'notifications'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/notifications', [AdminController::class, 'notificationsSave'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/notifications/test', [AdminController::class, 'notificationsTest'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/notifications/detect', [AdminController::class, 'notificationsDetect'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/notifications/report', [AdminController::class, 'notificationsReport'], $a + ['aperm' => 'settings.manage']);
    // شبكة إضافية لمالك حساب قائم
    $r->post('/admin/tenants/{id}/network', [AdminController::class, 'tenant_network'], $a + ['aperm' => 'tenants.manage']);
    $r->post('/admin/tenants/{id}/group', [AdminController::class, 'tenant_group'], $a + ['aperm' => 'tenants.manage']);
    // الكوكيز (الدخول التلقائي بالجهاز) لكل الشبكات أو لحساب محدد
    $r->get('/admin/cookies', [\App\Controllers\AdminCookieController::class, 'index'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/cookies', [\App\Controllers\AdminCookieController::class, 'save'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/cookies/tenants/{id}', [\App\Controllers\AdminCookieController::class, 'tenant'], $a + ['aperm' => 'settings.manage']);
    $r->get('/admin/settings', [AdminController::class, 'settings'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/settings', [AdminController::class, 'settingsSave'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/settings/logo', [AdminController::class, 'logoSave'], $a + ['aperm' => 'settings.manage']);
    $r->post('/admin/settings/logo/reset', [AdminController::class, 'logoReset'], $a + ['aperm' => 'settings.manage']);
    // فريق الإدارة: المالك وحده
    $r->get('/admin/team', [\App\Controllers\AdminTeamController::class, 'index'], $a + ['owner' => true]);
    $r->post('/admin/team', [\App\Controllers\AdminTeamController::class, 'save'], $a + ['owner' => true]);
    $r->post('/admin/team/{id}/status', [\App\Controllers\AdminTeamController::class, 'status'], $a + ['owner' => true]);
    $r->post('/admin/team/{id}/delete', [\App\Controllers\AdminTeamController::class, 'destroy'], $a + ['owner' => true]);
    // كلمة مرور المسؤول نفسه
    $r->post('/admin/security/password', [AdminController::class, 'ownPassword'], $a);
};
