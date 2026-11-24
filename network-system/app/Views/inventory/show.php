<?php
/** @var App\Core\View $v */
use App\Services\Inventory;

[$catLabel, $catIcon] = Inventory::CATEGORIES[$item['category']] ?? [$item['category'], 'package'];
$unit = Inventory::UNITS[$item['unit']] ?? '';
$serial = (int) $item['track_serial'];
$canManage = can('inventory.manage');
$low = (int) $item['is_active'] && Inventory::isLow($item);
$v->title = $item['name'];
$v->breadcrumbs = [['المخزون', '/inventory'], [$item['name'], null]];
$v->subtitle = $catLabel . ($item['sku'] ? ' · ' . $item['sku'] : '') . (!(int) $item['is_active'] ? ' · موقوف' : '');
$actions = '';
if ($canManage) {
    $common = ' data-url="' . e(url('/inventory/' . $item['id'] . '/move')) . '" data-name="' . e($item['name']) . '" data-unit="' . e($unit) . '" data-serial="' . $serial . '" data-balance="' . e(Inventory::fmtQty($item['quantity'])) . '" data-cost="' . e((string) ($item['unit_cost'] ?? '')) . '"';
    $actions .= '<button type="button" class="btn" data-modal-open="item-edit">' . icon('pencil') . 'تعديل</button>';
    if (!$serial) {
        $actions .= '<button type="button" class="btn" data-modal-open="inv-move"' . $common . ' data-type="adjust">' . icon('sliders-horizontal') . 'جرد</button>';
        $actions .= '<button type="button" class="btn" data-modal-open="inv-move"' . $common . ' data-type="out">' . icon('package-minus') . 'صرف</button>';
    }
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="inv-move"' . $common . ' data-type="in">' . icon('package-plus') . 'إدخال</button>';
}
$v->actions = $actions;
$moveSign = static fn ($q) => (float) $q > 0 ? '+' . Inventory::fmtQty($q) : Inventory::fmtQty($q);
$unitBtn = static function (array $u, string $action, string $iconName, string $title, string $itemName) {
    return '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-open="inv-unit" data-url="' . e(url('/inventory/units/' . $u['id'] . '/action')) . '" data-action="' . $action
        . '" data-serial="' . e($u['serial']) . '" data-item="' . e($itemName) . '" title="' . e($title) . '">' . icon($iconName) . '</button>';
};
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $low ? 'is-warning' : 'is-success' ?>"><?= icon($catIcon) ?></div><div class="stat-body"><div class="stat-label"><?= $serial ? 'في المخزن' : 'الرصيد' ?></div><div class="stat-value<?= $low ? ' text-danger' : '' ?>"><?= e(Inventory::fmtQty($item['quantity'])) ?> <small><?= e($unit) ?></small></div><div class="stat-meta"><?= $item['min_qty'] !== null ? 'الحد الأدنى ' . e(Inventory::fmtQty($item['min_qty'])) : '&nbsp;' ?></div></div></div>
  <?php if ($serial): ?>
  <div class="stat"><div class="stat-icon is-info"><?= icon('hard-drive') ?></div><div class="stat-body"><div class="stat-label">مركّب</div><div class="stat-value"><?= num($unitCounts['installed'] ?? 0) ?></div><div class="stat-meta"><?= num(($unitCounts['faulty'] ?? 0)) ?> تالف · <?= num($unitCounts['lost'] ?? 0) ?> مفقود</div></div></div>
  <?php else: ?>
  <div class="stat"><div class="stat-icon is-info"><?= icon('package-minus') ?></div><div class="stat-body"><div class="stat-label">إجمالي المصروف</div><div class="stat-value"><?= e(Inventory::fmtQty($stats['issued'])) ?> <small><?= e($unit) ?></small></div><div class="stat-meta"><?= (float) $stats['damaged'] > 0 ? e(Inventory::fmtQty($stats['damaged'])) . ' تالف' : '&nbsp;' ?></div></div></div>
  <?php endif; ?>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('package-plus') ?></div><div class="stat-body"><div class="stat-label">إجمالي الوارد</div><div class="stat-value"><?= e(Inventory::fmtQty($stats['received'])) ?> <small><?= e($unit) ?></small></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">قيمة الرصيد</div><div class="stat-value"><?= $item['unit_cost'] !== null ? e(money((float) $item['quantity'] * (float) $item['unit_cost'])) : '—' ?></div><div class="stat-meta"><?= $item['unit_cost'] !== null ? e(money($item['unit_cost'])) . ' للوحدة' : '&nbsp;' ?></div></div></div>
</div>

<?php if ($serial): ?>
<div class="panel">
  <div class="panel-head"><h3><?= icon('barcode') ?>الأجهزة</h3></div>
  <nav class="tabs">
    <a class="tab<?= $status === '' ? ' is-active' : '' ?>" href="<?= e(url('/inventory/' . $item['id'])) ?>">الكل <span class="tab-count"><?= num(array_sum(array_map('intval', $unitCounts))) ?></span></a>
    <?php foreach (Inventory::UNIT_STATUS as $k => [$label]): ?>
      <a class="tab<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(url('/inventory/' . $item['id'], ['status' => $k])) ?>"><?= e($label) ?> <span class="tab-count"><?= num($unitCounts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$units): ?>
    <div class="empty"><?= icon('barcode') ?><div>لا توجد أجهزة.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الرقم التسلسلي</th><th>MAC</th><th>الحالة</th><th>المكان</th><th>منذ</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($units as $u): [$stL, $stC] = Inventory::UNIT_STATUS[$u['status']]; ?>
        <tr>
          <td><a class="cell-title mono" href="<?= e(url('/inventory/units/' . $u['id'])) ?>"><?= e($u['serial']) ?></a></td>
          <td class="mono text-sm"><?= e($u['mac'] ?? '—') ?></td>
          <td><span class="badge <?= $stC ?>"><?= $stL ?></span></td>
          <td class="text-sm">
            <?php $place = array_filter([
                $u['site_name'] ? '<a href="' . e(url('/sites/' . $u['site_id'])) . '">' . e($u['site_name']) . '</a>' : null,
                $u['subscriber_name'] ? '<a href="' . e(url('/subscribers/' . $u['subscriber_id'])) . '">' . e($u['subscriber_name']) . '</a>' : null,
                $u['router_name'] ? '<a href="' . e(url('/routers/' . $u['router_id'])) . '">' . e($u['router_name']) . '</a>' : null,
            ]); echo $place ? implode(' · ', $place) : '—'; ?>
          </td>
          <td class="nowrap text-sm"><?= e(fmt_date($u['installed_at'] ?? $u['updated_at'] ?? $u['created_at'])) ?></td>
          <?php if ($canManage): ?>
          <td class="nowrap text-end">
            <?php if ($u['status'] === 'stock'): ?>
              <button type="button" class="btn btn-sm" data-modal-open="inv-unit" data-url="<?= e(url('/inventory/units/' . $u['id'] . '/action')) ?>" data-action="install" data-serial="<?= e($u['serial']) ?>" data-item="<?= e($item['name']) ?>"><?= icon('package-minus') ?>تركيب</button>
            <?php else: ?>
              <?= $unitBtn($u, 'return', 'undo-2', 'إرجاع إلى المخزن', $item['name']) ?>
            <?php endif; ?>
            <?php if (!in_array($u['status'], ['faulty', 'lost'], true)): ?><?= $unitBtn($u, 'faulty', 'triangle-alert', 'تسجيل تلف', $item['name']) ?><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><h3><?= icon('history') ?>الحركات</h3><a class="btn btn-sm" href="<?= e(url('/inventory/movements', ['item' => $item['id'], 'from' => date('Y-01-01')])) ?>"><?= icon('external-link') ?>السجل الكامل</a></div>
  <?php if (!$movements): ?>
    <div class="empty"><?= icon('history') ?><div>لا توجد حركات.</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>التاريخ</th><th>الحركة</th><?php if ($serial): ?><th>الجهاز</th><?php endif; ?><th class="num">الكمية</th><th class="num">الرصيد</th><th>إلى / من</th><th>الموظف</th><th>ملاحظات</th></tr></thead>
      <tbody>
      <?php foreach ($movements as $m): [$mL, $mC] = Inventory::MOVE_TYPES[$m['type']]; ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($m['created_at'])) ?></td>
          <td><span class="badge <?= $mC ?>"><?= e($mL) ?></span></td>
          <?php if ($serial): ?><td class="mono text-sm"><?= $m['unit_id'] ? '<a href="' . e(url('/inventory/units/' . $m['unit_id'])) . '">' . e($m['serial']) . '</a>' : '—' ?></td><?php endif; ?>
          <td class="num nowrap <?= (float) $m['qty'] > 0 ? 'text-success' : ((float) $m['qty'] < 0 ? 'text-danger' : '') ?>"><?= ltr(e($moveSign($m['qty']))) ?></td>
          <td class="num"><?= e(Inventory::fmtQty($m['balance'])) ?></td>
          <td class="text-sm"><?= e(implode(' · ', array_filter([$m['site_name'], $m['subscriber_name'], $m['receiver']])) ?: '—') ?></td>
          <td class="text-sm"><?= e($m['employee_name'] ?? '—') ?></td>
          <td class="text-sm"><?= e($m['notes'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($item['notes']): ?>
<div class="panel"><div class="panel-body"><dl class="kv"><dt>ملاحظات</dt><dd><?= e($item['notes']) ?></dd></dl></div></div>
<?php endif; ?>

<?php if ($canManage): ?>
<form method="post" action="<?= e(url('/inventory/' . $item['id'] . '/delete')) ?>" data-confirm="حذف الصنف «<?= e($item['name']) ?>» وكل حركاته؟" class="text-center">
  <?= csrf_field() ?>
  <button class="btn btn-ghost text-danger"><?= icon('trash-2') ?>حذف الصنف</button>
</form>

<?= $v->partial('inventory-move', ['sites' => $sites, 'subscribers' => $subscribers, 'back' => '']) ?>
<?php if ($serial): ?><?= $v->partial('inventory-unit-action', ['sites' => $sites, 'subscribers' => $subscribers, 'routers' => $routers, 'back' => '/inventory/' . $item['id'] . ($status !== '' ? '?status=' . $status : '')]) ?><?php endif; ?>

<dialog class="modal modal-lg" id="item-edit">
  <form method="post" action="<?= e(url('/inventory/' . $item['id'])) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('pencil') ?>تعديل الصنف</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><?= $v->partial('inventory-item-fields', ['item' => $item, 'prefix' => 'ie']) ?></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
