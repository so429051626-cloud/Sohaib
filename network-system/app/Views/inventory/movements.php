<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
use App\Services\Inventory;

$v->title = 'حركات المخزون';
$v->breadcrumbs = [['المخزون', '/inventory'], ['حركات المخزون', null]];
$q = array_filter($filters, static fn ($x) => $x !== '');
$v->actions = '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'تصدير</button><div class="dropdown-menu">'
    . '<a class="dropdown-item" href="' . e(url('/inventory/export', $q + ['format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel</a>'
    . '<a class="dropdown-item" href="' . e(url('/inventory/export', $q + ['format' => 'csv'])) . '">' . icon('file-text') . 'CSV</a></div></div>';
?>
<div class="stats">
  <?php foreach (['in' => 'is-success', 'out' => 'is-info', 'return' => '', 'damage' => 'is-danger'] as $k => $cls): [$label, , $ic] = Inventory::MOVE_TYPES[$k]; ?>
  <div class="stat"><div class="stat-icon <?= $cls ?>"><?= icon($ic) ?></div><div class="stat-body"><div class="stat-label"><?= e($label) ?></div><div class="stat-value"><?= num($totals[$k] ?? 0) ?></div>
    <?php if ($k === 'out'): ?><div class="stat-meta">بقيمة <?= e(money($issuedValue)) ?></div><?php endif; ?></div></div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="from">من تاريخ</label><input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="to">إلى تاريخ</label><input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
    <div class="field">
      <label for="type">الحركة</label>
      <select class="input" id="type" name="type"><option value="">الكل</option><?php foreach (Inventory::MOVE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected($filters['type'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field">
      <label for="item">الصنف</label>
      <select class="input" id="item" name="item"><option value="">الكل</option><?php foreach ($items as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['item'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field">
      <label for="site">الموقع</label>
      <select class="input" id="site" name="site"><option value="">الكل</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['site'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field">
      <label for="employee">الموظف</label>
      <select class="input" id="employee" name="employee"><option value="">الكل</option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['employee'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= icon('arrow-left-right') ?><div class="empty-title">لا توجد حركات في هذه الفترة</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>التاريخ</th><th>الصنف</th><th>الحركة</th><th class="num">الكمية</th><th class="num">الرصيد</th><th>إلى / من</th><th>الموظف</th><th>ملاحظات</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): [$mL, $mC] = Inventory::MOVE_TYPES[$m['type']]; $qty = (float) $m['qty']; ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($m['created_at'])) ?></td>
          <td><a class="cell-title" href="<?= e(url('/inventory/' . $m['item_id'])) ?>"><?= e($m['item_name']) ?></a>
            <?php if ($m['unit_id']): ?><div class="cell-sub mono"><a href="<?= e(url('/inventory/units/' . $m['unit_id'])) ?>"><?= e($m['serial']) ?></a></div><?php endif; ?></td>
          <td><span class="badge <?= $mC ?>"><?= e($mL) ?></span></td>
          <td class="num nowrap <?= $qty > 0 ? 'text-success' : ($qty < 0 ? 'text-danger' : '') ?>"><?= ltr(e(($qty > 0 ? '+' : '') . Inventory::fmtQty($qty))) ?> <span class="text-muted text-sm"><?= e(Inventory::UNITS[$m['unit']] ?? '') ?></span></td>
          <td class="num"><?= e(Inventory::fmtQty($m['balance'])) ?></td>
          <td class="text-sm"><?php
            $to = [];
            if ($m['site_name']) { $to[] = '<a href="' . e(url('/sites/' . $m['site_id'])) . '">' . e($m['site_name']) . '</a>'; }
            if ($m['subscriber_name']) { $to[] = '<a href="' . e(url('/subscribers/' . $m['subscriber_id'])) . '">' . e($m['subscriber_name']) . '</a>'; }
            if ($m['receiver']) { $to[] = e($m['receiver']); }
            echo $to ? implode(' · ', $to) : '—'; ?></td>
          <td class="text-sm"><?= e($m['employee_name'] ?? '—') ?></td>
          <td class="text-sm"><?= e($m['notes'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
