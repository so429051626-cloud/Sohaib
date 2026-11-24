<?php
/** @var App\Core\View $v @var bool $configured @var array $counts @var array $history @var array $picked */
use App\Controllers\AdminSmsController;
use App\Core\Platform;
use App\Platform\PlatformSms;

$v->title = 'الرسائل النصية';
$v->script('js/sms.js');
$v->script('js/picker.js');
$v->script('js/tpl.js');
$picked ??= [];
?>
<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/admin/sms')) ?>" class="stack" data-admin-sms>
    <?= csrf_field() ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('users') ?>المستلمون</h3></div>
      <div class="panel-body">
        <div class="chip-select">
          <?php foreach (AdminSmsController::AUDIENCE as $k => $label): ?>
            <label><input type="radio" name="audience" value="<?= e($k) ?>"<?= checked(old('audience', 'all') === $k) ?>><span><?= e($label) ?><?= !in_array($k, ['none', 'pick'], true) ? ' · ' . num($counts[$k]) : '' ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="mt-3" data-show-when="audience=pick"<?= old('audience', 'all') === 'pick' ? '' : ' hidden' ?>>
          <?= $v->partial('recipient-picker', ['url' => url('/admin/sms/recipients'), 'selected' => $picked, 'types' => App\Controllers\AdminSmsController::PICK_TYPES]) ?>
        </div>
        <div class="field mt-3"><label for="numbers">أرقام إضافية</label><textarea class="input input-ltr" id="numbers" name="numbers" rows="2" placeholder="0599123456, 0567778899"><?= e(old('numbers')) ?></textarea><?= error_html('numbers') ?></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('message-circle') ?>الرسالة</h3></div>
      <div class="panel-body">
        <div class="sms-tokens" data-admin-tokens>
          <?php foreach (AdminSmsController::PLACEHOLDERS as $t => $l): ?><button type="button" class="sms-token" data-token="<?= e($t) ?>"><?= icon('plus', 'icon-sm') ?><?= e($l) ?></button><?php endforeach; ?>
        </div>
        <textarea class="input sms-body" name="body" rows="5" maxlength="1000" required data-admin-body><?= e(old('body')) ?></textarea>
        <?= error_html('body') ?>
        <div class="sms-meter"><span><b data-a-chars>0</b> حرف</span><span><b data-a-seg>0</b> مقطع</span></div>
        <div class="form-actions"><button class="btn btn-primary"<?= $configured ? '' : ' disabled' ?>><?= icon('send') ?>إرسال</button></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>السجل</h3></div>
      <?php if (!$history): ?><div class="empty"><?= icon('message-circle') ?><div>لا توجد رسائل.</div></div><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>الرسالة</th><th>المستلمون</th><th class="num">أُرسلت</th><th class="num">فشلت</th><th>بواسطة</th><th>التاريخ</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><div class="cell-title"><?= e(mb_strimwidth($h['body'], 0, 70, '…')) ?></div><?php if ($h['error']): ?><div class="cell-sub text-danger"><?= e($h['error']) ?></div><?php endif; ?></td>
            <td class="text-sm"><?= e($h['audience']) ?><div class="cell-sub"><?= num((int) $h['recipients']) ?> مستلم</div></td>
            <td class="num"><?= num((int) $h['sent']) ?></td>
            <td class="num<?= (int) $h['failed'] ? ' text-danger' : '' ?>"><?= num((int) $h['failed']) ?></td>
            <td class="text-sm"><?= e($h['admin_name'] ?? '—') ?></td>
            <td class="nowrap text-sm"><?= e(fmt_dt($h['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </form>

  <div class="stack design-side">
    <div class="panel sms-balance" data-balance-card<?= $configured ? ' data-status-url="' . e(url('/admin/sms/status')) . '"' : '' ?>>
      <div class="panel-body">
        <div class="sms-balance-row"><span class="sms-balance-icon"><?= icon('banknote') ?></span>
          <div><div class="text-sm text-muted">رصيد الرسائل</div><div class="sms-balance-value" data-balance>—</div></div></div>
      </div>
    </div>
    <form method="post" action="<?= e(url('/admin/sms/settings')) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('key-round') ?>الربط مع TweetSMS</h3><?php if ($configured): ?><span class="badge badge-success badge-dot">مربوط</span><?php endif; ?></div>
      <div class="panel-body stack" style="gap:12px">
        <div class="field"><label>مفتاح الربط</label><input class="input input-ltr" name="api_key" type="password" autocomplete="new-password" placeholder="<?= Platform::setting('sms.api_key', '') !== '' ? '••••••••••••' : '' ?>"></div>
        <div class="field"><label>اسم المرسل</label><input class="input input-ltr" name="sender" value="<?= e(Platform::setting('sms.sender', '')) ?>" maxlength="11" list="sender-list"><datalist id="sender-list" data-sender-list></datalist><?= error_html('sender') ?></div>
        <button class="btn btn-block"><?= icon('save') ?>حفظ</button>
      </div>
    </form>
    <form method="post" action="<?= e(url('/admin/sms/features')) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('bot') ?>الرسائل التلقائية</h3><?php if (!$configured): ?><span class="badge badge-outline">الرسائل النصية غير مضبوطة</span><?php endif; ?></div>
      <div class="panel-body stack" style="gap:14px">
        <?php foreach (PlatformSms::FEATURES as $key => [$setting, $label]): $tid = 'tpl-' . $key; $err = field_error('tpl_' . $key); ?>
        <div class="tpl-item">
          <label class="switch"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked(Platform::setting($setting) === '1') ?>><span class="track"></span><span><?= e($label) ?></span></label>
          <details class="tpl-edit"<?= $err !== null ? ' open' : '' ?>>
            <summary><?= icon('pencil', 'icon-sm') ?>نص الرسالة</summary>
            <div class="sms-tokens">
              <?php foreach (PlatformSms::PLACEHOLDERS[$key] as $t => $l): ?><button type="button" class="sms-token" data-tpl-token="<?= e($t) ?>" data-tpl-target="<?= e($tid) ?>"><?= icon('plus', 'icon-sm') ?><?= e($l) ?></button><?php endforeach; ?>
            </div>
            <textarea class="input sms-body" id="<?= e($tid) ?>" name="tpl_<?= e($key) ?>" rows="6" maxlength="<?= PlatformSms::TEMPLATE_MAX ?>" data-tpl-text><?= e(old('tpl_' . $key, PlatformSms::template($key))) ?></textarea>
            <?= error_html('tpl_' . $key) ?>
            <div class="tpl-foot"><span class="text-sm text-muted"><b data-tpl-count>0</b> حرف</span><button type="button" class="btn btn-ghost btn-sm" data-tpl-default="<?= e(PlatformSms::TEMPLATES[$key]) ?>" data-tpl-target="<?= e($tid) ?>"><?= icon('rotate-ccw') ?>النص الافتراضي</button></div>
          </details>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-block"><?= icon('save') ?>حفظ</button>
      </div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var f = document.querySelector('[data-admin-sms]'), body = f.querySelector('[data-admin-body]');
  var meter = function () {
    var t = body.value, uni = /[^\x00-\x7F]/.test(t);
    f.querySelector('[data-a-chars]').textContent = SN.fmtNum(t.length);
    f.querySelector('[data-a-seg]').textContent = SN.fmtNum(t.length ? Math.ceil(t.length / (uni ? 70 : 160)) : 0);
  };
  body.addEventListener('input', meter);
  f.querySelector('[data-admin-tokens]').addEventListener('click', function (e) {
    var b = e.target.closest('[data-token]'); if (!b) { return; }
    var s = body.selectionStart || body.value.length, tok = b.getAttribute('data-token');
    body.value = body.value.slice(0, s) + tok + body.value.slice(body.selectionEnd || s); body.focus(); meter();
  });
  meter();
});
</script>
