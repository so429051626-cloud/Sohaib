<?php
/**
 * حقول الصنف (إضافة / تعديل).
 * @var ?array $item
 * @var string $prefix
 */
use App\Services\Inventory;

$isEdit = $item !== null;
$val = static fn (string $k, $d = '') => old($k, $item[$k] ?? $d);
$fmt = static fn ($x) => $x === null || $x === '' ? '' : Inventory::fmtQty($x);
$p = $prefix;
?>
<div class="form-grid" data-item-fields>
  <div class="field col-8"><label for="<?= $p ?>-name">اسم الصنف <span class="req">*</span></label><input class="input" id="<?= $p ?>-name" name="name" value="<?= e($val('name')) ?>" required maxlength="120"></div>
  <div class="field col-4"><label for="<?= $p ?>-sku">الرمز / الموديل</label><input class="input input-ltr" id="<?= $p ?>-sku" name="sku" value="<?= e($val('sku')) ?>" maxlength="60"></div>
  <div class="field col-6">
    <label for="<?= $p ?>-category">التصنيف</label>
    <select class="input" id="<?= $p ?>-category" name="category"><?php foreach (Inventory::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected($val('category', 'router'), $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
  </div>
  <div class="field col-6">
    <label for="<?= $p ?>-unit">وحدة القياس</label>
    <select class="input" id="<?= $p ?>-unit" name="unit" data-unit-select<?= $isEdit && (int) $item['track_serial'] ? ' disabled' : '' ?>><?php foreach (Inventory::UNITS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($val('unit', 'piece'), $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <?php if ($isEdit && (int) $item['track_serial']): ?><input type="hidden" name="unit" value="piece"><?php endif; ?>
  </div>
  <?php if (!$isEdit): ?>
  <div class="field col-12">
    <label class="switch"><input type="checkbox" name="track_serial" value="1" data-track-serial<?= checked(old('track_serial', '')) ?>><span class="track"></span><span>تتبّع كل جهاز برقمه التسلسلي</span></label>
  </div>
  <?php endif; ?>
  <div class="field col-4"><label for="<?= $p ?>-cost">تكلفة الوحدة</label><input class="input" id="<?= $p ?>-cost" name="unit_cost" value="<?= e($fmt($val('unit_cost'))) ?>" inputmode="decimal"></div>
  <div class="field col-4"><label for="<?= $p ?>-min">التنبيه عند انخفاض الرصيد إلى</label><input class="input" id="<?= $p ?>-min" name="min_qty" value="<?= e($fmt($val('min_qty'))) ?>" inputmode="decimal"></div>
  <?php if (!$isEdit): ?>
  <div class="field col-4" data-opening="qty"><label for="<?= $p ?>-opening">الرصيد الافتتاحي</label><input class="input" id="<?= $p ?>-opening" name="opening_qty" value="<?= e(old('opening_qty')) ?>" inputmode="decimal"></div>
  <div class="field col-12" data-opening="serial" hidden><label for="<?= $p ?>-serials">الأرقام التسلسلية الموجودة</label><textarea class="input input-ltr" id="<?= $p ?>-serials" name="serials" rows="4" placeholder="SN-0001  AA:BB:CC:DD:EE:01"><?= e(old('serials')) ?></textarea></div>
  <?php else: ?>
  <div class="field col-4" style="align-self:end">
    <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($val('is_active', 1)) ?>><span class="track"></span><span>صنف فعّال</span></label>
  </div>
  <?php endif; ?>
  <div class="field col-12"><label for="<?= $p ?>-notes">ملاحظات</label><input class="input" id="<?= $p ?>-notes" name="notes" value="<?= e($val('notes')) ?>" maxlength="255"></div>
</div>
