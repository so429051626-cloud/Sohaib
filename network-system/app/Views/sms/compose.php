<?php
/**
 * @var App\Core\View $v @var bool $configured @var array $plans @var array $areas @var array $routers @var array $templates @var array $recent @var string $prefill @var array $picked
 */
use App\Services\SmsService;

$v->title = 'إرسال رسالة';
$v->script('js/sms.js');
$v->script('js/picker.js');
$picked ??= [];
$v->actions = '<a class="btn" href="' . e(url('/sms/log')) . '">' . icon('history') . 'سجل الرسائل</a>';
$stateOld = old('state', $prefill !== '' ? 'none' : 'active');
$statusIcon = ['queued' => 'clock', 'sending' => 'send', 'done' => 'circle-check', 'failed' => 'circle-x'];
?>
<?php if (!$configured): ?>
<div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?><div class="grow">إعدادات الرسائل النصية غير مكتملة.</div>
  <?php if (can('sms.manage')): ?><a class="btn btn-sm" href="<?= e(url('/sms/settings')) ?>"><?= icon('settings') ?>الإعدادات</a><?php endif; ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/sms')) ?>" data-sms-form data-count="<?= e(url('/sms/count')) ?>" data-no-loading>
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('users') ?>المستلمون</h3><span class="sms-total" data-total>—</span></div>
        <div class="panel-body">
          <div class="chip-select" role="radiogroup">
            <?php foreach (SmsService::STATES as $k => $label): ?>
              <label><input type="radio" name="state" value="<?= e($k) ?>"<?= checked($stateOld === $k) ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="mt-3" data-pick-box<?= $stateOld === 'pick' ? '' : ' hidden' ?>>
            <?= $v->partial('recipient-picker', ['url' => url('/sms/recipients'), 'selected' => $picked]) ?>
          </div>
          <div class="form-grid mt-3" data-sub-filters>
            <div class="field col-4"><label for="plan">الباقة</label><select class="input" id="plan" name="plan"><option value="">الكل</option><?php foreach ($plans as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected((string) old('plan'), (string) $id) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label for="area">المنطقة</label><select class="input" id="area" name="area"><option value="">الكل</option><?php foreach ($areas as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected((string) old('area'), (string) $id) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field col-4"><label for="router">جهاز MikroTik</label><select class="input" id="router" name="router"><option value="">الكل</option><?php foreach ($routers as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected((string) old('router'), (string) $id) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="field mt-3">
            <label for="numbers">أرقام إضافية</label>
            <textarea class="input input-ltr" id="numbers" name="numbers" rows="3" placeholder="0599123456, 0567778899" data-numbers><?= e(old('numbers', $prefill)) ?></textarea>
            <div class="sms-numbers-state" data-numbers-state></div>
            <?= error_html('numbers') ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('message-circle') ?>الرسالة</h3>
          <?php if ($templates): ?>
          <select class="input input-sm sms-template" data-template aria-label="القوالب">
            <option value="">القوالب</option>
            <?php foreach ($templates as $t): ?><option value="<?= e($t['body']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
        <div class="panel-body">
          <div class="sms-tokens" data-tokens>
            <?php foreach (SmsService::PLACEHOLDERS as $token => $label): ?><button type="button" class="sms-token" data-token="<?= e($token) ?>"><?= icon('plus', 'icon-sm') ?><?= e($label) ?></button><?php endforeach; ?>
          </div>
          <textarea class="input sms-body" name="body" rows="6" maxlength="1000" required data-body><?= e(old('body', $draft ?? '')) ?></textarea>
          <?= error_html('body') ?>
          <div class="sms-meter">
            <span><b data-chars>0</b> حرف</span>
            <span><b data-segments>0</b> مقطع</span>
            <span class="sms-units"><b data-units>0</b> رسالة تقريباً</span>
          </div>
          <div class="sms-preview" data-preview hidden><div class="sms-bubble" data-preview-text></div></div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12"><label class="switch"><input type="checkbox" name="schedule" value="1" data-schedule<?= checked(old('schedule') === '1') ?>><span class="track"></span><span>جدولة الإرسال</span></label></div>
            <div class="field col-6" data-schedule-field hidden><label for="date">التاريخ</label><input class="input" type="date" id="date" name="date" value="<?= e(old('date', date('Y-m-d'))) ?>"><?= error_html('date') ?></div>
            <div class="field col-6" data-schedule-field hidden><label for="time">الوقت</label><input class="input" type="time" id="time" name="time" value="<?= e(old('time', date('H:i', time() + 3600))) ?>"></div>
            <div class="field col-12"><label for="template_name">حفظ النص كقالب</label><input class="input" id="template_name" name="template_name" maxlength="80" placeholder="اسم القالب"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack design-side">
      <div class="panel sms-balance" data-balance-card<?= $configured ? ' data-status-url="' . e(url('/sms/status')) . '"' : '' ?>>
        <div class="panel-body">
          <div class="sms-balance-row"><span class="sms-balance-icon"><?= icon('banknote') ?></span>
            <div><div class="text-sm text-muted">رصيد الرسائل</div><div class="sms-balance-value" data-balance>—</div></div>
          </div>
          <div class="text-sm text-muted mt-2" dir="ltr" data-sender><?= e(SmsService::sender()) ?></div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg btn-block" data-send<?= $configured ? '' : ' disabled' ?>><?= icon('send') ?>إرسال</button>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('history') ?>آخر الرسائل</h3></div>
        <ul class="list">
          <?php foreach ($recent as $m): ?>
            <li class="list-item">
              <span class="list-icon <?= $m['status'] === 'done' ? 'is-success' : ($m['status'] === 'failed' ? 'is-danger' : '') ?>"><?= icon($statusIcon[$m['status']] ?? 'send') ?></span>
              <div class="list-item-main"><a class="list-item-title text-sm" href="<?= e(url('/sms/log/' . $m['id'])) ?>"><?= e(mb_strimwidth($m['body'], 0, 46, '…')) ?></a><div class="list-item-sub"><?= num((int) $m['sent']) ?>/<?= num((int) $m['recipients']) ?> · <?= e(time_ago($m['created_at'])) ?></div></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$recent): ?><li class="list-item text-muted">لا توجد رسائل.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</form>
