<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
$v->title = 'المبيعات';
$v->subtitle = 'عمليات بيع الكروت المباشرة وللموزعين';
if (can('sales.create')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/sales/create')) . '">' . icon('shopping-cart') . 'عملية بيع</a>';
}
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('receipt') ?></div><div class="stat-body"><div class="stat-label">العمليات</div><div class="stat-value"><?= num($summary['ops']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">الكروت المباعة</div><div class="stat-value"><?= num($summary['cards']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">إجمالي المبيعات</div><div class="stat-value"><?= e(money($summary['total'])) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('wallet') ?></div><div class="stat-body"><div class="stat-label">غير المحصّل</div><div class="stat-value"><?= e(money((float) $summary['total'] - (float) $summary['paid'])) ?></div></div></div>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="from">من تاريخ</label><input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="to">إلى تاريخ</label><input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
    <div class="field">
      <label for="type">النوع</label>
      <select class="input" id="type" name="type"><option value="">الكل</option>
        <option value="direct"<?= selected($filters['type'], 'direct') ?>>مباشر</option>
        <option value="distributor"<?= selected($filters['type'], 'distributor') ?>>موزع</option>
      </select>
    </div>
    <div class="field">
      <label for="distributor">الموزع</label>
      <select class="input" id="distributor" name="distributor"><option value="">الكل</option>
        <?php foreach ($distributors as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['distributor'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="category">الباقة</label>
      <select class="input" id="category" name="category"><option value="">الكل</option>
        <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['category'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="employee">الموظف</label>
      <select class="input" id="employee" name="employee"><option value="">الكل</option>
        <?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['employee'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="status">الحالة</label>
      <select class="input" id="status" name="status"><option value="">الكل</option>
        <option value="completed"<?= selected($filters['status'], 'completed') ?>>مكتملة</option>
        <option value="cancelled"<?= selected($filters['status'], 'cancelled') ?>>ملغاة</option>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= icon('shopping-cart') ?><div class="empty-title">لا توجد عمليات بيع</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>التاريخ</th><th>المشتري</th><th>الباقة</th><th class="num">الكمية</th><th class="num">سعر الوحدة</th><th class="num">الإجمالي</th><th class="num">المدفوع</th><th>الموظف</th><th>الحالة</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr<?= $s['status'] === 'cancelled' ? ' class="is-muted"' : '' ?>>
          <td><a class="cell-title" href="<?= e(url('/sales/' . $s['id'])) ?>">#<?= (int) $s['id'] ?></a></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($s['created_at'])) ?></td>
          <td>
            <?php if ($s['type'] === 'distributor'): ?>
              <a href="<?= e(url('/distributors/' . $s['distributor_id'])) ?>"><?= e($s['distributor_name']) ?></a><div class="cell-sub">موزع</div>
            <?php else: ?>
              <?= e($s['customer_name'] ?: 'بيع مباشر') ?><div class="cell-sub">مباشر</div>
            <?php endif; ?>
          </td>
          <td class="nowrap"><span class="swatch" style="background:<?= e($s['category_color']) ?>"></span> <?= e($s['category_name']) ?></td>
          <td class="num"><?= num($s['quantity']) ?></td>
          <td class="num nowrap"><?= e(money($s['unit_price'])) ?><?= (float) $s['discount_percent'] > 0 ? '<div class="cell-sub">خصم ' . ltr(e(rtrim(rtrim(number_format((float) $s['discount_percent'], 2, '.', ''), '0'), '.') . '%')) . '</div>' : '' ?></td>
          <td class="num nowrap"><strong><?= e(money($s['total'])) ?></strong></td>
          <td class="num nowrap"><?= (float) $s['paid'] < (float) $s['total'] ? '<span class="text-warning">' . e(money($s['paid'])) . '</span>' : e(money($s['paid'])) ?></td>
          <td class="text-sm"><?= e($s['employee_name'] ?? '—') ?></td>
          <td><?= $s['status'] === 'cancelled' ? '<span class="badge badge-danger">ملغاة</span>' : '<span class="badge badge-success">مكتملة</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
