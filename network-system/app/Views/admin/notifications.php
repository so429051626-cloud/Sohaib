<?php
/** @var App\Core\View $v @var bool $hasToken @var bool $configured @var bool $shared */
use App\Core\Platform;
use App\Services\AdminNotify;

$v->title = 'إشعارات تيليجرام';
$v->script('js/telegram-tools.js');
$days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
?>
<form method="post" action="<?= e(url('/admin/notifications')) ?>" class="panel" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel-head"><h3><?= icon('bot') ?>بوت إشعارات المسؤول</h3><span class="badge <?= $configured ? 'badge-success' : 'badge-outline' ?>"><?= $configured ? ($shared ? 'يعمل ببوت الخادم' : 'يعمل') : 'غير مضبوط' ?></span></div>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-6"><label for="n-token">رمز البوت</label><input class="input input-ltr" id="n-token" type="password" name="token" value="" autocomplete="new-password" spellcheck="false" placeholder="<?= $hasToken ? '••••••••••••••••' : '123456789:ABC…' ?>"><?= error_html('token') ?>
        <?php if ($hasToken): ?><label class="check mt-2"><input type="checkbox" name="remove_token" value="1"><span>حذف الرمز المحفوظ</span></label><?php endif; ?></div>
      <div class="field col-6"><label for="n-chat">معرّف المحادثة</label>
        <div class="field-row"><input class="input input-ltr" id="n-chat" name="chat" value="<?= e(old('chat', Platform::setting('notify.chat'))) ?>" maxlength="200" placeholder="123456789"><button type="button" class="btn" data-tg-detect="<?= e(url('/admin/notifications/detect')) ?>" data-tg-token="#n-token" data-tg-chat="#n-chat"><?= icon('search') ?>اكتشاف</button></div>
        <div class="tg-found" data-tg-found="#n-chat"></div><?= error_html('chat') ?></div>
      <div class="form-section-title"><?= icon('bell') ?>الأحداث</div>
      <div class="field col-12"><div class="check-grid">
        <?php foreach (AdminNotify::EVENTS as $k => $l): ?>
          <label class="switch"><input type="checkbox" name="on_<?= e($k) ?>" value="1"<?= checked(Platform::setting('notify.' . $k) === '1') ?>><span class="track"></span><span><?= e($l) ?></span></label>
        <?php endforeach; ?>
      </div></div>
      <div class="field col-4"><label>يوم التقرير الأسبوعي</label><select class="input" name="weekly_day"><?php foreach ($days as $i => $d): ?><option value="<?= $i ?>"<?= selected((int) Platform::setting('notify.weekly_day'), $i) ?>><?= e($d) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
      <button type="button" class="btn" data-tg-test="<?= e(url('/admin/notifications/test')) ?>" data-tg-token="#n-token" data-tg-chat="#n-chat"><?= icon('send') ?>رسالة تجريبية</button>
      <button type="button" class="btn" data-notify-action="<?= e(url('/admin/notifications/report')) ?>"<?= $configured ? '' : ' disabled' ?>><?= icon('chart-column') ?>إرسال التقرير الأسبوعي الآن</button>
    </div>
  </div>
</form>
<script>
document.querySelectorAll('[data-notify-action]').forEach(function (b) {
  b.addEventListener('click', function () {
    b.classList.add('is-loading'); b.disabled = true;
    SN.api(b.getAttribute('data-notify-action'), { method: 'POST' }).then(function (r) { SN.toast('success', r.message); })
      .catch(function () {}).then(function () { b.classList.remove('is-loading'); b.disabled = false; });
  });
});
</script>
