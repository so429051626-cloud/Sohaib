<?php
/**
 * بوت تيليجرام: الإدارة والتقارير وخدمة الزبائن.
 * @var App\Core\View $v
 * @var array $stats
 */
use App\Core\Settings;

$v->title = 'بوت تيليجرام';
$v->script('js/telegram-tools.js');
$v->actions = '<a class="btn" href="' . e(url('/telegram/links')) . '">' . icon('link') . 'المشتركون المرتبطون <span class="badge">' . num($stats['links']) . '</span></a>';
$s = static fn (string $k) => old(str_replace('.', '__', $k), (string) Settings::get($k, ''));
$name = static fn (string $k) => str_replace('.', '__', $k);
$switch = static function (string $key, string $label, string $hint = '') use ($name): string {
    return '<label class="switch"><input type="checkbox" name="' . e($name($key)) . '" value="1"' . checked(Settings::bool($key)) . '><span class="track"></span><span>' . e($label)
        . ($hint !== '' ? '<span class="d-block hint">' . e($hint) . '</span>' : '') . '</span></label>';
};
$tgMode = (string) Settings::get('telegram.mode', '');
$tgBot = (string) Settings::get('telegram.bot_username', '');
$days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
?>
<form method="post" action="<?= e(url('/telegram')) ?>" class="panel">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-12">
        <div class="tg-status">
          <span class="tg-status-icon"><?= icon('bot') ?></span>
          <div class="grow">
            <div class="tg-status-title"><?= $tgBot !== '' ? '@' . e($tgBot) : 'بوت تيليجرام' ?></div>
            <div class="tg-status-sub">
              <?php if (\App\Services\Telegram::enabled()): ?><span class="badge badge-success badge-dot">مفعّل</span><?php else: ?><span class="badge badge-outline">غير مفعّل</span><?php endif; ?>
              <?php if ($tgMode === 'webhook'): ?><span class="badge badge-info">Webhook</span><?php elseif ($tgMode === 'poll'): ?><span class="badge badge-info">عبر المهمة المجدولة</span><?php endif; ?>
            </div>
          </div>
          <button type="button" class="btn" data-tg="connect"><?= icon('link') ?>ربط الأوامر</button>
          <button type="button" class="btn" data-tg-test="<?= e(url('/telegram/test')) ?>" data-tg-token="#tg-token" data-tg-chat="#tg-chat"><?= icon('send') ?>رسالة تجريبية</button>
        </div>
      </div>
      <div class="field col-12"><?= $switch('notify.telegram_enabled', 'تفعيل البوت والتنبيهات') ?></div>
      <div class="field col-6">
        <label>رمز البوت (Bot Token)</label>
        <input class="input input-ltr" id="tg-token" name="<?= e($name('notify.telegram_token')) ?>" value="<?= e($s('notify.telegram_token')) ?>" autocomplete="off" placeholder="123456789:AA...">
        <?= error_html($name('notify.telegram_token')) ?>
      </div>
      <div class="field col-6">
        <label>معرّفات المحادثات (Chat ID)</label>
        <div class="field-row"><input class="input input-ltr" id="tg-chat" name="<?= e($name('notify.telegram_chat')) ?>" value="<?= e($s('notify.telegram_chat')) ?>" placeholder="123456789, -1001234567890"><button type="button" class="btn" data-tg-detect="<?= e(url('/telegram/detect')) ?>" data-tg-token="#tg-token" data-tg-chat="#tg-chat"><?= icon('search') ?>اكتشاف</button></div>
        <div class="tg-found" data-tg-found="#tg-chat"></div>
      </div>
      <div class="field col-12"><?= $switch('telegram.commands', 'الرد على الأوامر (التقارير، أجهزة MikroTik، فحص الكروت، المشتركين...)') ?></div>

      <div class="form-section-title">التقارير التلقائية</div>
      <div class="tg-schedule col-12">
        <div class="tg-sched-row">
          <?= $switch('telegram.report_daily', 'التقرير اليومي') ?>
          <div class="tg-sched-when"><span>الساعة</span><input class="input input-ltr" type="time" name="<?= e($name('telegram.daily_time')) ?>" value="<?= e($s('telegram.daily_time')) ?>"></div>
          <button type="button" class="btn btn-sm" data-tg="report" data-type="daily"><?= icon('send') ?>إرسال الآن</button>
        </div>
        <?php if (\App\Services\Ai::offered()): $aiOk = \App\Services\Ai::available(); ?>
        <div class="tg-sched-row tg-ai-row">
          <?= $switch('telegram.ai_summary', 'تحليل ذكي في التقرير اليومي') ?>
          <span class="badge <?= $aiOk ? 'badge-primary' : 'badge-outline' ?>"><?= icon('sparkles') ?><?= e($aiOk ? 'ما حدث · اللافت · المطلوب منك' : \App\Services\Ai::stateLabel(\App\Services\Ai::status())) ?></span>
        </div>
        <?php endif; ?>
        <div class="tg-sched-row">
          <?= $switch('telegram.report_weekly', 'التقرير الأسبوعي') ?>
          <div class="tg-sched-when">
            <select class="input" name="<?= e($name('telegram.weekly_day')) ?>"><?php foreach ($days as $i => $d): ?><option value="<?= $i ?>"<?= selected($s('telegram.weekly_day'), (string) $i) ?>><?= e($d) ?></option><?php endforeach; ?></select>
            <input class="input input-ltr" type="time" name="<?= e($name('telegram.weekly_time')) ?>" value="<?= e($s('telegram.weekly_time')) ?>">
          </div>
          <button type="button" class="btn btn-sm" data-tg="report" data-type="weekly"><?= icon('send') ?>إرسال الآن</button>
        </div>
        <div class="tg-sched-row">
          <?= $switch('telegram.report_monthly', 'التقرير الشهري') ?>
          <div class="tg-sched-when"><span>أول كل شهر</span><input class="input input-ltr" type="time" name="<?= e($name('telegram.monthly_time')) ?>" value="<?= e($s('telegram.monthly_time')) ?>"></div>
          <button type="button" class="btn btn-sm" data-tg="report" data-type="monthly"><?= icon('send') ?>إرسال الآن</button>
        </div>
      </div>

      <div class="form-section-title">بوت الزبائن</div>
      <div class="field col-12"><?= $switch('telegram.customers', 'خدمة الزبائن عبر تيليجرام (رصيد الكروت، حالة الاشتراك، ربط الحساب)') ?></div>
      <div class="field col-6">
        <label>رمز بوت منفصل للزبائن</label>
        <div class="field-row"><input class="input input-ltr" id="tg-ctoken" name="<?= e($name('telegram.customer_token')) ?>" value="<?= e($s('telegram.customer_token')) ?>" autocomplete="off" placeholder="نفس بوت الإدارة"><button type="button" class="btn" data-tg-test="<?= e(url('/telegram/customer-test')) ?>" data-tg-token="#tg-ctoken"><?= icon('scan-line') ?>فحص</button></div>
        <?= error_html($name('telegram.customer_token')) ?>
        <?php if (($cu = (string) Settings::get('telegram.customer_username', '')) !== '' && \App\Services\Telegram::customerToken() !== ''): ?><span class="hint" dir="ltr">@<?= e($cu) ?></span><?php endif; ?>
      </div>
      <div class="field col-6">
        <label>تذكير المشترك قبل الانتهاء بـ</label>
        <div class="input-group"><input class="input" name="<?= e($name('telegram.remind_days')) ?>" value="<?= e($s('telegram.remind_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div>
      </div>
      <div class="field col-12"><?= $switch('telegram.remind', 'إرسال تذكير الانتهاء وإشعار التجديد للمشتركين المرتبطين') ?></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </div>
</form>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-tg]');
  if (!b) { return; }
  var act = b.getAttribute('data-tg');
  var url = { connect: '/telegram/connect', report: '/telegram/report' }[act];
  b.classList.add('is-loading');
  SN.api(SN.base + url, { method: 'POST', data: act === 'report' ? { type: b.getAttribute('data-type') } : {} })
    .then(function (r) { SN.toast('success', r.message); if (act === 'connect') { setTimeout(function () { location.reload(); }, 900); } })
    .catch(function () {})
    .then(function () { b.classList.remove('is-loading'); });
});
</script>
