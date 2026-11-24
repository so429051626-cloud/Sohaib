<?php
/** @var App\Core\View $v */
use App\Services\Inventory;

$v->title = 'المخزون';
$canManage = can('inventory.manage');
$actions = (can('inventory.view') ? '<a class="btn" href="' . e(url('/reports', ['type' => 'inventory', 'preset' => 'month'])) . '">' . icon('chart-column') . 'التقرير</a>' : '') . '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'تصدير</button><div class="dropdown-menu">'
    . '<a class="dropdown-item" href="' . e(url('/inventory/export', ['what' => 'stock', 'format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel</a>'
    . '<a class="dropdown-item" href="' . e(url('/inventory/export', ['what' => 'stock', 'format' => 'csv'])) . '">' . icon('file-text') . 'CSV</a></div></div>';
if ($canManage) {
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="item-create">' . icon('plus') . 'صنف جديد</button>';
}
$v->actions = $actions;
$states = ['' => 'الكل', 'low' => 'منخفض', 'inactive' => 'موقوف'];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('warehouse') ?></div><div class="stat-body"><div class="stat-label">الأصناف</div><div class="stat-value"><?= num($summary['items']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">قيمة المخزون</div><div class="stat-value"><?= e(money($summary['value'])) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('hard-drive') ?></div><div class="stat-body"><div class="stat-label">أجهزة مركّبة</div><div class="stat-value"><?= num($summary['installed']) ?></div><?php if ($summary['faulty']): ?><div class="stat-meta"><?= num($summary['faulty']) ?> تالف</div><?php endif; ?></div></div>
  <a class="stat<?= $summary['low'] ? ' is-alert' : '' ?>" href="<?= e(url('/inventory', ['state' => 'low'])) ?>"><div class="stat-icon is-warning"><?= icon('triangle-alert') ?></div><div class="stat-body"><div class="stat-label">أصناف منخفضة</div><div class="stat-value"><?= num($summary['low']) ?></div></div></a>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="اسم الصنف أو الرمز"></div>
    <div class="field">
      <label for="category">التصنيف</label>
      <select class="input" id="category" name="category"><option value="">الكل</option>
        <?php foreach (Inventory::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected($filters['category'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="state">الحالة</label>
      <select class="input" id="state" name="state"><?php foreach ($states as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($filters['state'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <?php if (!$items): ?>
    <div class="empty"><?= icon('warehouse') ?><div class="empty-title">لا توجد أصناف</div>
      <?php if ($canManage && $filters['q'] === '' && $filters['category'] === '' && $filters['state'] === ''): ?><button type="button" class="btn btn-primary" data-modal-open="item-create"><?= icon('plus') ?>صنف جديد</button><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الصنف</th><th>التصنيف</th><th class="num">الرصيد</th><th class="num">مركّب</th><th class="num">التكلفة</th><th class="num">القيمة</th><th>آخر حركة</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $i):
          [$catLabel, $catIcon] = Inventory::CATEGORIES[$i['category']] ?? [$i['category'], 'package'];
          $unit = Inventory::UNITS[$i['unit']] ?? '';
          $low = (int) $i['is_active'] && Inventory::isLow($i);
          $serial = (int) $i['track_serial']; ?>
        <tr<?= !(int) $i['is_active'] ? ' class="is-muted"' : '' ?>>
          <td>
            <div class="flex items-center gap-2">
              <span class="list-icon"><?= icon($catIcon) ?></span>
              <div><a class="cell-title" href="<?= e(url('/inventory/' . $i['id'])) ?>"><?= e($i['name']) ?></a>
                <div class="cell-sub"><?= $i['sku'] ? '<span class="mono">' . e($i['sku']) . '</span> · ' : '' ?><?= $serial ? 'مرقّم' : e($unit) ?></div></div>
            </div>
          </td>
          <td class="text-sm"><?= e($catLabel) ?></td>
          <td class="num nowrap">
            <strong<?= $low ? ' class="text-danger"' : '' ?>><?= e(Inventory::fmtQty($i['quantity'])) ?></strong> <span class="text-muted text-sm"><?= e($unit) ?></span>
            <?php if ($low): ?><div><span class="badge badge-warning">منخفض</span></div><?php elseif ($i['min_qty'] !== null): ?><div class="cell-sub">الحد <?= e(Inventory::fmtQty($i['min_qty'])) ?></div><?php endif; ?>
          </td>
          <td class="num"><?= $serial ? num($i['installed']) . ((int) $i['faulty'] ? '<div class="cell-sub text-danger">' . num($i['faulty']) . ' تالف</div>' : '') : '—' ?></td>
          <td class="num nowrap"><?= $i['unit_cost'] !== null ? e(money($i['unit_cost'])) : '—' ?></td>
          <td class="num nowrap"><?= $i['unit_cost'] !== null ? e(money((float) $i['quantity'] * (float) $i['unit_cost'])) : '—' ?></td>
          <td class="nowrap text-sm"><?= $i['last_move'] ? e(fmt_dt($i['last_move'])) : '—' ?></td>
          <?php if ($canManage): ?>
          <td class="nowrap text-end">
            <?php if ((int) $i['is_active']): ?>
            <button type="button" class="btn btn-sm" data-modal-open="inv-move" data-url="<?= e(url('/inventory/' . $i['id'] . '/move')) ?>" data-name="<?= e($i['name']) ?>" data-unit="<?= e($unit) ?>" data-serial="<?= $serial ?>" data-balance="<?= e(Inventory::fmtQty($i['quantity'])) ?>" data-cost="<?= e((string) ($i['unit_cost'] ?? '')) ?>" data-type="in" title="إدخال"><?= icon('package-plus') ?></button>
            <?php if ($serial): ?>
            <a class="btn btn-sm" href="<?= e(url('/inventory/' . $i['id'], ['status' => 'stock'])) ?>" title="صرف"><?= icon('package-minus') ?></a>
            <?php else: ?>
            <button type="button" class="btn btn-sm" data-modal-open="inv-move" data-url="<?= e(url('/inventory/' . $i['id'] . '/move')) ?>" data-name="<?= e($i['name']) ?>" data-unit="<?= e($unit) ?>" data-serial="0" data-balance="<?= e(Inventory::fmtQty($i['quantity'])) ?>" data-type="out" title="صرف"><?= icon('package-minus') ?></button>
            <?php endif; ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<?= $v->partial('inventory-move', ['sites' => $sites, 'subscribers' => $subscribers, 'back' => 'index']) ?>
<dialog class="modal modal-lg" id="item-create">
  <form method="post" action="<?= e(url('/inventory')) ?>" data-item-form>
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('package-plus') ?>صنف جديد</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <?= $v->partial('inventory-item-fields', ['item' => null, 'prefix' => 'ic']) ?>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
