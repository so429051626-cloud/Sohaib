<?php
/** @var App\Core\View $v @var array $rule @var bool $owner */
use App\Core\Settings;

$v->title = 'إعدادات الانقطاعات';
$on = static fn (string $k) => Settings::bool($k);
$num = static fn (float $x) => rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
?>
<form method="post" action="<?= e(url('/outages/settings')) ?>" class="stack">
  <?= csrf_field() ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('unplug') ?>السجل والتنبيهات</h3></div>
    <div class="panel-body stack" style="gap:14px">
      <label class="switch"><input type="checkbox" name="auto" value="1"<?= checked($on('outages.auto')) ?>><span class="track"></span><span>فتح الانقطاع وإغلاقه تلقائياً مع انقطاع جهاز MikroTik وعودته</span></label>
      <label class="switch"><input type="checkbox" name="notify_power" value="1"<?= checked($on('notify.power')) ?>><span class="track"></span><span>تنبيهات انخفاض جهد البطاريات</span></label>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><?= icon('hand-coins') ?>تعويض المشتركين</h3><span class="badge <?= $on('outages.comp_enabled') ? 'badge-success' : 'badge-outline' ?>"><?= $on('outages.comp_enabled') ? 'مفعّل' : 'مطفأ' ?></span></div>
    <div class="panel-body">
      <?php if (!$owner): ?>
        <div class="empty"><?= icon('lock') ?><div class="empty-title">يفعّله مالك الشبكة فقط</div></div>
      <?php else: ?>
      <div class="form-grid">
        <div class="field col-12"><label class="switch"><input type="checkbox" name="comp_enabled" value="1"<?= checked($on('outages.comp_enabled')) ?>><span class="track"></span><span>تفعيل تعويض المشتركين عن الانقطاعات</span></label></div>
        <div class="field col-4" data-show-when="comp_enabled=1"><label>أقل مدة انقطاع تستحق التعويض</label><div class="input-group"><input class="input input-ltr" name="min_hours" value="<?= e(old('min_hours', $num($rule['min_hours']))) ?>" inputmode="decimal"><span class="input-addon">ساعة</span></div><?= error_html('min_hours') ?></div>
        <div class="field col-4" data-show-when="comp_enabled=1"><label>يوم تعويض لكل</label><div class="input-group"><input class="input input-ltr" name="hours_per_day" value="<?= e(old('hours_per_day', $num($rule['hours_per_day']))) ?>" inputmode="decimal"><span class="input-addon">ساعة انقطاع</span></div><?= error_html('hours_per_day') ?></div>
        <div class="field col-4" data-show-when="comp_enabled=1"><label>أقصى أيام التعويض</label><div class="input-group"><input class="input input-ltr" name="max_days" value="<?= e(old('max_days', (string) $rule['max_days'])) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div><?= error_html('max_days') ?></div>
        <div class="field col-12" data-show-when="comp_enabled=1"><label class="switch"><input type="checkbox" name="comp_auto" value="1"<?= checked($on('outages.comp_auto')) ?>><span class="track"></span><span>تطبيق تلقائي عند إغلاق الانقطاع</span></label></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
</form>
