<?php
/** @var App\Core\View $v */
$v->title = 'الموزعون';
$v->subtitle = 'نقاط البيع وحساباتها';
if (can('distributors.manage')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/distributors/create')) . '">' . icon('plus') . 'موزع جديد</a>';
}
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('store') ?></div><div class="stat-body"><div class="stat-label">عدد الموزعين</div><div class="stat-value"><?= num($totals['n']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('wallet') ?></div><div class="stat-body"><div class="stat-label">المستحق على الموزعين</div><div class="stat-value"><?= e(money($totals['due'])) ?></div></div></div>
</div>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="الاسم أو الهاتف"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('store') ?><div class="empty-title">لا يوجد موزعون</div>
      <?php if (can('distributors.manage') && $q === ''): ?><a class="btn btn-primary" href="<?= e(url('/distributors/create')) ?>"><?= icon('plus') ?>موزع جديد</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الموزع</th><th>المنطقة</th><th class="num">الخصم</th><th class="num">الكروت المباعة</th><th class="num">قيمة المبيعات</th><th>آخر عملية</th><th class="num">المستحق</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $d): ?>
        <tr<?= $d['is_active'] ? '' : ' class="is-muted"' ?>>
          <td>
            <a class="cell-title" href="<?= e(url('/distributors/' . $d['id'])) ?>"><?= e($d['name']) ?></a>
            <div class="cell-sub"><?= $d['phone'] ? '<span class="mono">' . e($d['phone']) . '</span>' : '' ?><?= $d['is_active'] ? '' : ' <span class="badge badge-outline">موقوف</span>' ?></div>
          </td>
          <td><?= e($d['area_name'] ?? '—') ?></td>
          <td class="num"><?= (float) $d['discount_percent'] > 0 ? ltr(e((string) (float) $d['discount_percent'] . '%')) : '—' ?></td>
          <td class="num"><?= num($d['cards_sold']) ?></td>
          <td class="num nowrap"><?= e(money($d['sales_total'])) ?></td>
          <td class="nowrap text-sm"><?= $d['last_sale'] ? e(time_ago($d['last_sale'])) : '—' ?></td>
          <td class="num nowrap"><?= (float) $d['balance'] > 0 ? '<strong class="text-danger">' . e(money($d['balance'])) . '</strong>' : ((float) $d['balance'] < 0 ? '<span class="text-success">' . e(money(-$d['balance'])) . ' رصيد</span>' : '—') ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (can('sales.create') && $d['is_active']): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/sales/create', ['distributor' => $d['id']])) ?>" title="بيع"><?= icon('shopping-cart') ?></a><?php endif; ?>
              <?php if (can('distributors.manage')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/distributors/' . $d['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
