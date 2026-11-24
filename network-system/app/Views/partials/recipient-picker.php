<?php
/**
 * اختيار مستلمين بأعينهم (public/assets/js/picker.js).
 * @var string $url رابط البحث
 * @var array $selected المختارون مسبقاً [{id,label,sub,phone}]
 * @var array $types أنواع المستلمين [المفتاح => التسمية] (اختياري)
 */
$types = $types ?? [];
?>
<div class="picker" data-picker data-url="<?= e($url) ?>" data-name="pick[]" data-selected="<?= e(json_encode(array_values($selected ?? []), JSON_UNESCAPED_UNICODE)) ?>">
  <?php if ($types): ?>
  <div class="segmented picker-types" role="group">
    <?php $first = true; foreach ($types as $k => $label): ?>
      <button type="button" class="<?= $first ? 'is-active' : '' ?>" data-picker-type="<?= e($k) ?>" aria-pressed="<?= $first ? 'true' : 'false' ?>"><?= e($label) ?></button>
    <?php $first = false; endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="input-icon"><?= icon('search') ?><input class="input" type="search" data-picker-q placeholder="ابحث بالاسم أو اسم المستخدم أو الرقم" autocomplete="off"></div>
  <div class="picker-chips" data-picker-chips></div>
  <div class="picker-results" data-picker-results></div>
  <div class="picker-foot">
    <span class="text-sm">المحدد: <b data-picker-count>0</b></span>
    <span class="flex gap-1">
      <button type="button" class="btn btn-ghost btn-sm" data-picker-all><?= icon('list-checks') ?>تحديد كل النتائج</button>
      <button type="button" class="btn btn-ghost btn-sm" data-picker-clear><?= icon('x') ?>إلغاء التحديد</button>
    </span>
  </div>
</div>
