<?php
/** @var App\Core\View $v */
$isEdit = $dist !== null;
$v->title = $isEdit ? 'تعديل موزع' : 'موزع جديد';
$v->breadcrumbs = $isEdit
    ? [['الموزعون', '/distributors'], [$dist['name'], '/distributors/' . $dist['id']], ['تعديل', null]]
    : [['الموزعون', '/distributors'], ['موزع جديد', null]];
$val = static fn (string $k, $d = '') => old($k, $dist[$k] ?? $d);
?>
<form method="post" action="<?= e(url($isEdit ? '/distributors/' . $dist['id'] : '/distributors')) ?>">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="panel">
      <div class="panel-head"><h2><?= icon('store') ?>بيانات الموزع</h2></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-6">
            <label for="name">الاسم <span class="req">*</span></label>
            <input class="input" id="name" name="name" value="<?= e($val('name')) ?>" required>
            <?= error_html('name') ?>
          </div>
          <div class="field col-6">
            <label for="phone">الهاتف</label>
            <input class="input input-ltr" id="phone" name="phone" value="<?= e($val('phone')) ?>" inputmode="tel">
          </div>
          <div class="field col-6">
            <label for="area_id">المنطقة</label>
            <select class="input" id="area_id" name="area_id">
              <option value="">—</option>
              <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) $val('area_id'), (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field col-6">
            <label for="discount_percent">نسبة الخصم الافتراضية %</label>
            <input class="input" id="discount_percent" name="discount_percent" value="<?= e($val('discount_percent', '0')) ?>" inputmode="decimal">
            <?= error_html('discount_percent') ?>
          </div>
          <div class="field col-12">
            <label for="address">العنوان</label>
            <input class="input" id="address" name="address" value="<?= e($val('address')) ?>">
          </div>
          <div class="field col-12">
            <label for="notes">ملاحظات</label>
            <input class="input" id="notes" name="notes" value="<?= e($val('notes')) ?>">
          </div>
        </div>
      </div>
    </div>
    <div class="stack">
      <div class="panel"><div class="panel-body">
        <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($val('is_active', 1)) ?>><span class="track"></span><span>الموزع فعّال</span></label>
      </div></div>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <a class="btn" href="<?= e(url($isEdit ? '/distributors/' . $dist['id'] : '/distributors')) ?>">إلغاء</a>
      </div></div>
      <?php if ($isEdit && can('distributors.manage')): ?>
      <div class="panel"><div class="panel-body">
        <button type="submit" form="delete-form" class="btn btn-ghost text-danger"><?= icon('trash-2') ?>حذف الموزع</button>
      </div></div>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php if ($isEdit && can('distributors.manage')): ?>
<form method="post" id="delete-form" action="<?= e(url('/distributors/' . $dist['id'] . '/delete')) ?>" data-confirm="حذف الموزع <?= e($dist['name']) ?>؟"><?= csrf_field() ?></form>
<?php endif; ?>
