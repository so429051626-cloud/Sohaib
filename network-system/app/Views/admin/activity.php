<?php
/** @var App\Core\View $v @var array $rows @var array $tenants @var App\Core\Request $request */
$v->title = 'النشاط';
$actors = ['' => 'الكل', 'admin' => 'المسؤول', 'tenant' => 'الزبائن', 'system' => 'النظام', 'visitor' => 'الزوار'];
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="tenant">الحساب</label><select class="input" id="tenant" name="tenant"><option value="">الكل</option><?php foreach ($tenants as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($request->str('tenant'), (string) $id) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="actor">المصدر</label><select class="input" id="actor" name="actor"><?php foreach ($actors as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($request->str('actor'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('activity') ?><div class="empty-title">لا يوجد نشاط</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>التاريخ</th><th>الوصف</th><th>الحساب</th><th>بواسطة</th><th>العنوان</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($a['created_at'])) ?></td>
          <td class="text-sm"><?= e($a['description']) ?></td>
          <td class="text-sm"><?= $a['tenant_id'] ? '<a href="' . e(url('/admin/tenants/' . $a['tenant_id'])) . '">' . e($a['tenant_name'] ?? '#' . $a['tenant_id']) . '</a>' : '—' ?></td>
          <td class="text-sm"><?= e($actors[$a['actor']] ?? $a['actor']) ?><?= $a['actor_name'] ? '<div class="cell-sub">' . e($a['actor_name']) . '</div>' : '' ?></td>
          <td class="mono text-sm"><?= e($a['ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
