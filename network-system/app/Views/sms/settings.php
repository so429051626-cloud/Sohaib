<?php
/** @var App\Core\View $v @var bool $hasKey @var array $templates */
use App\Core\Settings;
use App\Services\SmsService;

$v->title = 'إعدادات الرسائل';
$v->script('js/sms.js');
$s = static fn (string $k) => old(str_replace('sms.', '', $k), (string) Settings::get($k, ''));
$on = static fn (string $k) => Settings::bool($k);
?>
<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/sms/settings')) ?>" class="stack">
    <?= csrf_field() ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('key-round') ?>الربط مع TweetSMS</h3><?php if ($hasKey): ?><span class="badge badge-success badge-dot">مربوط</span><?php endif; ?></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-6">
            <label for="api_key">مفتاح الربط (API Key)</label>
            <input class="input input-ltr" id="api_key" name="api_key" type="password" autocomplete="new-password" placeholder="<?= $hasKey ? '••••••••••••' : '' ?>">
          </div>
          <div class="field col-6">
            <label for="sender">اسم المرسل</label>
            <input class="input input-ltr" id="sender" name="sender" value="<?= e($s('sms.sender')) ?>" maxlength="11" list="sender-list" data-sender-input>
            <datalist id="sender-list" data-sender-list></datalist>
            <?= error_html('sender') ?>
          </div>
          <?php if ($hasKey): ?><div class="field col-12"><label class="check"><input type="checkbox" name="remove_key" value="1">إزالة المفتاح</label></div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('bot') ?>الرسائل التلقائية</h3></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-8"><label class="switch"><input type="checkbox" name="auto_remind" value="1"<?= checked($on('sms.auto_remind')) ?>><span class="track"></span><span><?= e(SmsService::AUTO['remind'][0]) ?></span></label></div>
          <div class="field col-4"><div class="input-group"><input class="input" name="remind_days" value="<?= e($s('sms.remind_days')) ?>" inputmode="numeric" aria-label="أيام التذكير"><span class="input-addon">يوم</span></div></div>
          <div class="field col-12"><textarea class="input" name="text_remind" rows="2" maxlength="600" aria-label="نص التذكير"><?= e(SmsService::autoText('remind')) ?></textarea></div>
          <div class="field col-12"><label class="switch"><input type="checkbox" name="auto_expired" value="1"<?= checked($on('sms.auto_expired')) ?>><span class="track"></span><span><?= e(SmsService::AUTO['expired'][0]) ?></span></label></div>
          <div class="field col-12"><textarea class="input" name="text_expired" rows="2" maxlength="600" aria-label="نص الانتهاء"><?= e(SmsService::autoText('expired')) ?></textarea></div>
          <div class="field col-12"><label class="switch"><input type="checkbox" name="auto_renew" value="1"<?= checked($on('sms.auto_renew')) ?>><span class="track"></span><span><?= e(SmsService::AUTO['renew'][0]) ?></span></label></div>
          <div class="field col-12"><textarea class="input" name="text_renew" rows="2" maxlength="600" aria-label="نص التجديد"><?= e(SmsService::autoText('renew')) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
      </div>
    </div>
  </form>

  <div class="stack">
    <div class="panel sms-balance" data-balance-card<?= $hasKey ? ' data-status-url="' . e(url('/sms/status')) . '"' : '' ?>>
      <div class="panel-body">
        <div class="sms-balance-row"><span class="sms-balance-icon"><?= icon('banknote') ?></span>
          <div><div class="text-sm text-muted">رصيد الرسائل</div><div class="sms-balance-value" data-balance>—</div></div>
        </div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('send') ?>رسالة تجريبية</h3></div>
      <div class="panel-body">
        <div class="input-group"><input class="input input-ltr" data-test-phone placeholder="0599123456" inputmode="tel"><button type="button" class="btn" data-test-send data-url="<?= e(url('/sms/test')) ?>"><?= icon('send') ?>إرسال</button></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('file-text') ?>القوالب</h3><button type="button" class="btn btn-sm" data-modal-open="m-template" data-id="" data-name="" data-body=""><?= icon('plus') ?>قالب</button></div>
      <ul class="list">
        <?php foreach ($templates as $t): ?>
          <li class="list-item">
            <div class="list-item-main"><div class="list-item-title"><?= e($t['name']) ?></div><div class="list-item-sub"><?= e(mb_strimwidth($t['body'], 0, 60, '…')) ?></div></div>
            <div class="list-item-end">
              <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-open="m-template" data-id="<?= (int) $t['id'] ?>" data-name="<?= e($t['name']) ?>" data-body="<?= e($t['body']) ?>" title="تعديل"><?= icon('pencil') ?></button>
              <form method="post" action="<?= e(url('/sms/templates/' . $t['id'] . '/delete')) ?>" data-confirm="حذف القالب «<?= e($t['name']) ?>»؟" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$templates): ?><li class="list-item text-muted">لا توجد قوالب.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<dialog class="modal" id="m-template">
  <form method="post" action="<?= e(url('/sms/templates')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-head"><h3><?= icon('file-text') ?>قالب رسالة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label>الاسم</label><input class="input" name="name" required maxlength="80"></div>
      <div class="field"><label>النص</label><textarea class="input" name="body" rows="4" required maxlength="1000"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-template').addEventListener('modal:open', function (e) {
  var t = e.detail.trigger, f = this.querySelector('form');
  f.id.value = t.getAttribute('data-id'); f.name.value = t.getAttribute('data-name'); f.body.value = t.getAttribute('data-body');
});
document.querySelector('[data-test-send]').addEventListener('click', function () {
  var b = this, phone = document.querySelector('[data-test-phone]').value;
  b.classList.add('is-loading');
  SN.api(b.getAttribute('data-url'), { method: 'POST', data: { phone: phone } }).then(function (r) { SN.toast('success', r.message); }).catch(function () {}).then(function () { b.classList.remove('is-loading'); });
});
</script>
