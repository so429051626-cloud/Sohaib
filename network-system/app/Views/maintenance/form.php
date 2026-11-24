<?php
/** @var App\Core\View $v @var ?array $task @var array $prefill @var array $employees @var array $sites @var array $routers */
use App\Services\Maintenance;

$v->title = $task ? 'تعديل المهمة #' . $task['id'] : 'مهمة صيانة جديدة';
$v->breadcrumbs = [['مهام الصيانة', '/maintenance'], [$task ? '#' . $task['id'] : 'مهمة جديدة', null]];
$val = static fn (string $k, mixed $d = '') => old($k, $task[$k] ?? $d);
?>
<form method="post" action="<?= e(url($task ? '/maintenance/' . $task['id'] : '/maintenance')) ?>" class="panel" autocomplete="off">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-12"><label for="m-title">عنوان المهمة <span class="req">*</span></label><input class="input" id="m-title" name="title" value="<?= e((string) $val('title')) ?>" required maxlength="160"><?= error_html('title') ?></div>
      <div class="field col-12"><label>النوع</label>
        <div class="segmented is-block mt-types">
          <?php foreach (Maintenance::TYPES as $k => [$label, $ic]): ?><label><input type="radio" name="type" value="<?= $k ?>"<?= checked($val('type', 'repair') === $k) ?>><span><?= icon($ic) ?><?= e($label) ?></span></label><?php endforeach; ?>
        </div></div>
      <div class="field col-6"><label for="m-assignee">الفنّي</label><select class="input" id="m-assignee" name="assignee_id"><option value="">غير مسندة</option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) $val('assignee_id', 0), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select><?= error_html('assignee_id') ?></div>
      <div class="field col-3"><label>الأولوية</label><select class="input" name="priority"><?php foreach (Maintenance::PRIORITIES as $k => [$label]): ?><option value="<?= $k ?>"<?= selected($val('priority', 'normal'), $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field col-3"><label for="m-due">الموعد</label><input class="input" id="m-due" type="date" name="due_on" value="<?= e((string) $val('due_on')) ?>"><?= error_html('due_on') ?></div>
      <div class="form-section-title"><?= icon('map-pin') ?>المكان</div>
      <?php if ($sites): ?><div class="field col-4"><label for="m-site">البرج أو الموقع</label><select class="input" id="m-site" name="site_id"><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) $val('site_id', $prefill['site_id'] ?? 0), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <?php if ($routers): ?><div class="field col-4"><label for="m-router">جهاز MikroTik</label><select class="input" id="m-router" name="router_id"><option value="">—</option><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) $val('router_id', $prefill['router_id'] ?? 0), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field col-4"><label for="m-sub">المشترك</label><input class="input input-ltr" id="m-sub" name="subscriber" value="<?= e((string) old('subscriber', $task['subscriber_username'] ?? ($prefill['subscriber'] ?? ''))) ?>" maxlength="64" placeholder="اسم الدخول أو الهاتف" spellcheck="false"><?= error_html('subscriber') ?></div>
      <div class="field col-12"><label for="m-loc">وصف المكان</label><input class="input" id="m-loc" name="location" value="<?= e((string) $val('location')) ?>" maxlength="255"><?= error_html('location') ?></div>
      <div class="field col-12"><label for="m-desc">التفاصيل</label><textarea class="input" id="m-desc" name="description" rows="5" maxlength="4000"><?= e((string) $val('description')) ?></textarea></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary"><?= icon('save') ?><?= $task ? 'حفظ' : 'إنشاء المهمة' ?></button>
      <a class="btn" href="<?= e(url($task ? '/maintenance/' . $task['id'] : '/maintenance')) ?>">إلغاء</a>
    </div>
  </div>
</form>
