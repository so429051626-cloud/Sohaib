<?php
/** @var App\Core\View $v */
use App\Services\Geo;

$v->title = 'الأبراج والمواقع';
$actions = '<a class="btn" href="' . e(url('/map')) . '">' . icon('map') . 'الخريطة</a>';
if (can('map.manage')) {
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="site-create">' . icon('plus') . 'موقع جديد</button>';
}
$v->actions = $actions;
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="الاسم أو العنوان"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$sites): ?>
    <div class="empty"><?= icon('radio-tower') ?><div class="empty-title">لا توجد مواقع</div>
      <?php if (can('map.manage') && $q === ''): ?><button type="button" class="btn btn-primary" data-modal-open="site-create"><?= icon('plus') ?>موقع جديد</button><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الموقع</th><th>النوع</th><th>المنطقة</th><th>الإحداثيات</th><th class="num">أجهزة MikroTik</th><th class="num">المشتركون</th><th class="num">الأجهزة</th><th class="num">الروابط</th></tr></thead>
      <tbody>
      <?php foreach ($sites as $s): [$typeLabel, $typeIcon] = Geo::SITE_TYPES[$s['type']] ?? [$s['type'], 'map-pin']; ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <span class="mk mk-site mk-sm"><?= icon($typeIcon) ?></span>
              <div><a class="cell-title" href="<?= e(url('/sites/' . $s['id'])) ?>"><?= e($s['name']) ?></a>
                <?php if ($s['address']): ?><div class="cell-sub"><?= e($s['address']) ?></div><?php endif; ?></div>
            </div>
          </td>
          <td><?= e($typeLabel) ?><?= $s['height_m'] ? '<div class="cell-sub">' . (int) $s['height_m'] . ' م</div>' : '' ?></td>
          <td><?= e($s['area_name'] ?? '—') ?></td>
          <td class="nowrap"><?= $s['lat'] !== null ? '<span class="mono text-sm">' . e(round((float) $s['lat'], 5) . ', ' . round((float) $s['lng'], 5)) . '</span>' : '<span class="badge badge-warning">غير محددة</span>' ?></td>
          <td class="num"><?= num($s['routers']) ?></td>
          <td class="num"><?= num($s['subscribers']) ?></td>
          <td class="num"><?= num($s['units']) ?></td>
          <td class="num"><?= num($s['links']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (can('map.manage')): ?>
<dialog class="modal modal-lg" id="site-create">
  <form method="post" action="<?= e(url('/sites')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('radio-tower') ?>موقع جديد</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-8"><label for="sc-name">الاسم <span class="req">*</span></label><input class="input" id="sc-name" name="name" value="<?= e(old('name')) ?>" required maxlength="120"></div>
        <div class="field col-4"><label for="sc-type">النوع</label>
          <select class="input" id="sc-type" name="type"><?php foreach (Geo::SITE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected(old('type', 'tower'), $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="sc-area">المنطقة</label>
          <select class="input" id="sc-area" name="area_id"><option value="">—</option><?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) old('area_id'), (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="sc-height">الارتفاع (م)</label><input class="input" id="sc-height" name="height_m" value="<?= e(old('height_m')) ?>" inputmode="numeric"></div>
        <?= $v->partial('location', ['sites' => [], 'siteId' => null, 'lat' => old('lat'), 'lng' => old('lng'), 'withSite' => false]) ?>
        <div class="field col-12"><label for="sc-address">العنوان</label><input class="input" id="sc-address" name="address" value="<?= e(old('address')) ?>" maxlength="255"></div>
        <div class="field col-12"><label for="sc-notes">ملاحظات</label><input class="input" id="sc-notes" name="notes" value="<?= e(old('notes')) ?>" maxlength="500"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
