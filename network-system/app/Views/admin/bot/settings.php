<?php
/**
 * لوحة المسؤول: إعدادات بوت خدمة الزبائن للمنصة — التشغيل، المعلومات، نافذة الموقع، واتساب، والربط مع MADIX Cloud، وتجربة المساعد.
 * @var App\Core\View $v
 * @var array $has @var string $aiState
 */
use App\Controllers\BotPublicController;
use App\Core\Platform;
use App\Services\Ai;
use App\Services\BotAgent;
use App\Services\WhatsApp;

$v->title = 'إعدادات بوت خدمة الزبائن';
$v->breadcrumbs = [['محادثات البوت', '/admin/bot'], ['الإعدادات', null]];
$v->script('js/bot.js');
$v->actions = '<a class="btn" href="' . e(url('/admin/bot')) . '">' . icon('message-square') . 'المحادثات</a>';
$name = static fn (string $k) => str_replace('.', '__', $k);
$s = static fn (string $k) => old(str_replace('.', '__', $k), Platform::setting($k));
$on = static fn (string $k): bool => Platform::setting($k) === '1';
$switch = static function (string $key, string $label) use ($name, $on): string {
    return '<label class="switch"><input type="checkbox" name="' . e($name($key)) . '" value="1"' . checked($on($key)) . '><span class="track"></span><span>' . e($label) . '</span></label>';
};
$secret = static function (string $key, string $label, string $placeholder) use ($name, $has): string {
    $f = $name($key);
    return '<label for="' . e($f) . '">' . e($label) . '</label>'
        . '<input class="input input-ltr" type="password" id="' . e($f) . '" name="' . e($f) . '" autocomplete="new-password" placeholder="' . e($has[$key] ? '•••••••• محفوظ' : $placeholder) . '">'
        . ($has[$key] ? '<label class="check text-sm"><input type="checkbox" name="' . e($f) . '_clear" value="1"> حذف المحفوظ</label>' : '')
        . error_html($f);
};
$botTitle = BotAgent::name();
$widgetSrc = BotAgent::publicUrl() . strtok(asset('widget/bot-widget.js'), '?');
$embed = '<script src="' . $widgetSrc . '" data-endpoint="' . BotPublicController::endpoint() . '" data-title="' . $botTitle . '" defer></script>';
$helpPage = BotAgent::publicUrl() . '/help';
$aiOk = $aiState === 'ok';
?>
<div class="bot-settings">
<form method="post" action="<?= e(url('/admin/bot/settings')) ?>" class="panel" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-12">
        <div class="tg-status">
          <span class="tg-status-icon"><?= icon('bot-message-square') ?></span>
          <div class="grow">
            <div class="tg-status-title"><?= e($botTitle) ?></div>
            <div class="tg-status-sub">
              <?php if (BotAgent::enabled()): ?><span class="badge badge-success badge-dot">يعمل</span><?php else: ?><span class="badge badge-outline">متوقف</span><?php endif; ?>
              <span class="badge <?= $aiOk ? 'badge-primary' : 'badge-warning' ?>"><?= icon('sparkles') ?><?= e($aiOk ? Ai::modelLabel() : Ai::stateLabel($aiState)) ?></span>
              <?php if (!$aiOk): ?><a class="text-sm" href="<?= e(url('/admin/ai')) ?>">إعداد الذكاء الاصطناعي</a><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="field col-12"><?= $switch('bot.enabled', 'تشغيل بوت خدمة الزبائن') ?></div>
      <div class="field col-6">
        <label for="<?= e($name('bot.name')) ?>">اسم المساعد</label>
        <input class="input" id="<?= e($name('bot.name')) ?>" name="<?= e($name('bot.name')) ?>" value="<?= e($s('bot.name')) ?>" maxlength="60" placeholder="مساعد <?= e(Platform::setting('platform.name')) ?>">
        <?= error_html($name('bot.name')) ?>
      </div>
      <div class="field col-3">
        <label for="<?= e($name('bot.per_day')) ?>">حد رسائل المرسل اليومي</label>
        <div class="input-group"><input class="input" id="<?= e($name('bot.per_day')) ?>" name="<?= e($name('bot.per_day')) ?>" value="<?= e($s('bot.per_day')) ?>" inputmode="numeric"><span class="input-addon">رسالة</span></div>
        <?= error_html($name('bot.per_day')) ?>
      </div>
      <div class="field col-3">
        <label for="<?= e($name('bot.retention_days')) ?>">الاحتفاظ بالمحادثات</label>
        <div class="input-group"><input class="input" id="<?= e($name('bot.retention_days')) ?>" name="<?= e($name('bot.retention_days')) ?>" value="<?= e($s('bot.retention_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div>
        <?= error_html($name('bot.retention_days')) ?>
      </div>
      <div class="field col-12"><span class="hint">إشعار المحادثات التي تحتاج رداً من الفريق يصل على تيليجرام من <a href="<?= e(url('/admin/notifications')) ?>">إشعارات تيليجرام</a>، ورسائل المشتركين تصل أصحاب شبكاتهم في صفحة «رسائل المشتركين» في أنظمتهم.</span></div>

      <div class="form-section-title">معلومات للمساعد</div>
      <div class="field col-12">
        <label for="<?= e($name('bot.knowledge')) ?>">ما يجب أن يعرفه المساعد</label>
        <textarea class="input bot-knowledge" id="<?= e($name('bot.knowledge')) ?>" name="<?= e($name('bot.knowledge')) ?>" rows="12" maxlength="20000" placeholder="طرق الدفع وتجديد الاشتراك: …&#10;ساعات الدعم: …&#10;س: كيف أربط جهاز MikroTik؟ ج: …"><?= e($s('bot.knowledge')) ?></textarea>
        <?= error_html($name('bot.knowledge')) ?>
        <span class="hint" data-count-for="<?= e($name('bot.knowledge')) ?>" data-max="20000"></span>
      </div>

      <div class="form-section-title"><?= icon('phone') ?>واتساب (WhatsApp Cloud API)</div>
      <div class="field col-12"><?= $switch('bot.whatsapp', 'تفعيل الرد على واتساب') ?></div>
      <div class="field col-6">
        <label for="<?= e($name('bot.wa_phone_id')) ?>">معرّف رقم الهاتف (Phone number ID)</label>
        <input class="input input-ltr" id="<?= e($name('bot.wa_phone_id')) ?>" name="<?= e($name('bot.wa_phone_id')) ?>" value="<?= e($s('bot.wa_phone_id')) ?>" inputmode="numeric" placeholder="123456789012345">
        <?= error_html($name('bot.wa_phone_id')) ?>
      </div>
      <div class="field col-6"><?= $secret('bot.wa_token', 'رمز الوصول الدائم (Access Token)', 'EAA…') ?></div>
      <div class="field col-6"><?= $secret('bot.wa_secret', 'سر التطبيق (App Secret)', 'لفحص توقيع رسائل Meta') ?></div>
      <div class="field col-6 bot-test-cell">
        <button type="button" class="btn" data-bot-test="<?= e(url('/admin/bot/settings/whatsapp-test')) ?>" data-fields='{"phone_id":"#<?= e($name('bot.wa_phone_id')) ?>","token":"#<?= e($name('bot.wa_token')) ?>"}'><?= icon('scan-line') ?>فحص الرقم</button>
      </div>
      <div class="field col-12">
        <label>رابط Webhook (Callback URL)</label>
        <div class="field-row">
          <input class="input input-ltr mono" id="bot-wa-hook" value="<?= e(WhatsApp::webhookUrl()) ?>" readonly>
          <button type="button" class="btn" data-copy-target="#bot-wa-hook"><?= icon('copy') ?>نسخ</button>
        </div>
      </div>
      <div class="field col-6">
        <label>رمز التحقق (Verify token)</label>
        <div class="field-row">
          <input class="input input-ltr mono" id="bot-wa-verify" value="<?= e(Platform::setting('bot.wa_verify')) ?>" readonly>
          <button type="button" class="btn" data-copy-target="#bot-wa-verify"><?= icon('copy') ?>نسخ</button>
        </div>
      </div>
      <div class="field col-6">
        <label>حقل الاشتراك في Webhook</label>
        <input class="input input-ltr mono" value="messages" readonly>
      </div>

      <div class="form-section-title"><?= icon('globe') ?>نافذة المحادثة في الموقع</div>
      <div class="field col-12"><?= $switch('bot.web', 'تفعيل النافذة في صفحة المنصة الرئيسية وصفحة المحادثة') ?></div>
      <div class="field col-12">
        <label>صفحة المحادثة</label>
        <div class="field-row">
          <input class="input input-ltr mono" id="bot-page" value="<?= e($helpPage) ?>" readonly>
          <button type="button" class="btn" data-copy-target="#bot-page"><?= icon('copy') ?>نسخ</button>
          <a class="btn" href="<?= e($helpPage) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?>فتح</a>
        </div>
      </div>
      <div class="field col-12">
        <label>كود النافذة لموقع آخر</label>
        <div class="field-row">
          <input class="input input-ltr mono" id="bot-embed" value="<?= e($embed) ?>" readonly>
          <button type="button" class="btn" data-copy-target="#bot-embed"><?= icon('copy') ?>نسخ</button>
          <button type="submit" class="btn btn-ghost" formaction="<?= e(url('/admin/bot/settings/web-key')) ?>" data-confirm="إنشاء رابط جديد؟ الكود الحالي في المواقع الأخرى سيتوقف."><?= icon('refresh-cw') ?>رابط جديد</button>
        </div>
      </div>

      <div class="form-section-title"><?= icon('store') ?>MADIX ERP (لوحة MADIX Cloud)</div>
      <div class="field col-12"><?= $switch('bot.erp', 'المساعد يجيب أصحاب المحلات عن ترخيص MADIX ERP وأجهزتهم ويفتح تذاكر الدعم') ?></div>
      <div class="field col-6">
        <label for="<?= e($name('bot.erp_url')) ?>">رابط MADIX Cloud</label>
        <input class="input input-ltr" id="<?= e($name('bot.erp_url')) ?>" name="<?= e($name('bot.erp_url')) ?>" value="<?= e($s('bot.erp_url')) ?>" placeholder="https://cpanel.madix.org">
        <?= error_html($name('bot.erp_url')) ?>
      </div>
      <div class="field col-6"><?= $secret('bot.erp_key', 'مفتاح بوت (API key)', 'mxk_…') ?></div>
      <div class="field col-12">
        <button type="button" class="btn" data-bot-test="<?= e(url('/admin/bot/settings/erp-test')) ?>" data-fields='{"url":"#<?= e($name('bot.erp_url')) ?>","key":"#<?= e($name('bot.erp_key')) ?>"}'><?= icon('scan-line') ?>فحص الاتصال</button>
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </div>
</form>

<div class="panel chat bot-try" data-bot-try data-url="<?= e(url('/admin/bot/settings/try')) ?>">
  <div class="panel-head">
    <h3><?= icon('sparkles') ?>تجربة المساعد</h3>
    <div class="chat-head-end"><button type="button" class="btn btn-ghost btn-icon btn-sm" data-bot-try-reset title="محادثة جديدة" aria-label="محادثة جديدة"><?= icon('rotate-ccw') ?></button></div>
  </div>
  <div class="bot-try-as">
    <label for="bot-try-phone">جرّب برقم</label>
    <input class="input input-ltr" id="bot-try-phone" data-bot-try-phone inputmode="tel" maxlength="20" placeholder="رقم صاحب شبكة أو مشترك، أو اتركه فارغاً كزائر">
  </div>
  <div class="chat-log" data-bot-try-log aria-live="polite"></div>
  <form class="chat-form" data-bot-try-form>
    <textarea class="input" name="text" rows="1" maxlength="1000" placeholder="اكتب كما يكتب المرسل" aria-label="رسالة تجريبية"></textarea>
    <button class="btn btn-primary btn-icon" aria-label="إرسال"><?= icon('send') ?></button>
  </form>
</div>
</div>
