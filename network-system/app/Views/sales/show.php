<?php
/** @var App\Core\View $v */
$v->title = 'عملية البيع #' . $sale['id'];
$v->breadcrumbs = [['المبيعات', '/sales'], ['#' . $sale['id'], null]];
$actions = '';
if (can('cards.print') && $cards) {
    $actions .= '<a class="btn" target="_blank" href="' . e(url('/print/render', ['source' => 'sale', 'sale' => $sale['id'], 'mark' => 1])) . '">' . icon('printer') . 'طباعة الكروت</a>';
}
if (can('cards.export') && $cards) {
    $actions .= '<a class="btn" href="' . e(url('/sales/' . $sale['id'] . '/export')) . '">' . icon('download') . 'تصدير</a>';
}
if (can('sales.cancel') && $sale['status'] === 'completed' && $used === 0) {
    $actions .= '<form method="post" action="' . e(url('/sales/' . $sale['id'] . '/cancel')) . '" data-confirm="إلغاء عملية البيع وإعادة الكروت إلى المخزون؟">'
        . csrf_field() . '<button class="btn btn-danger">' . icon('x') . 'إلغاء العملية</button></form>';
}
$v->actions = $actions;
$showPass = can('cards.passwords');
?>
<?php if ($sale['status'] === 'cancelled'): ?>
  <div class="notice notice-danger mb-3"><?= icon('circle-x') ?><div>ملغاة في <?= e(fmt_dt($sale['cancelled_at'])) ?><?= $sale['cancelled_by_name'] ? ' بواسطة ' . e($sale['cancelled_by_name']) : '' ?>.</div></div>
<?php endif; ?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-info"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">الكمية</div><div class="stat-value"><?= num($sale['quantity']) ?></div><div class="stat-meta"><?= e($sale['category_name']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">الإجمالي</div><div class="stat-value"><?= e(money($sale['total'])) ?></div><div class="stat-meta"><?= e(money($sale['unit_price'])) ?> للوحدة<?= (float) $sale['discount_percent'] > 0 ? ' · خصم ' . ltr(e((string) (float) $sale['discount_percent'] . '%')) : '' ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('wallet') ?></div><div class="stat-body"><div class="stat-label">المدفوع</div><div class="stat-value"><?= e(money($sale['paid'])) ?></div><div class="stat-meta"><?= (float) $sale['paid'] < (float) $sale['total'] ? 'المتبقي ' . e(money((float) $sale['total'] - (float) $sale['paid'])) : 'مدفوعة بالكامل' ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">المستخدمة</div><div class="stat-value"><?= num($used) ?></div><div class="stat-meta">من <?= num(count($cards)) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('ticket') ?>الكروت</h3><span class="badge"><?= num(count($cards)) ?></span></div>
    <div class="table-wrap" style="max-height:620px">
      <table class="table table-compact">
        <thead><tr><th>#</th><th>رقم الكرت</th><?php if ($showPass): ?><th>كلمة المرور</th><?php endif; ?><th>المختصر</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php foreach ($cards as $c): ?>
          <tr>
            <td class="text-muted text-sm"><?= (int) $c['id'] ?></td>
            <td><a class="mono" href="<?= e(url('/check/card/' . $c['id'])) ?>"><?= e($c['username']) ?></a></td>
            <?php if ($showPass): ?><td class="mono"><?= $c['password'] === $c['username'] ? '<span class="text-muted">=</span>' : e($c['password']) ?></td><?php endif; ?>
            <td class="mono"><?= e($c['short_code'] ?? '—') ?><?php if (!empty($c['short_password'])): ?> <span class="text-muted">/</span> <?= $showPass ? e($c['short_password']) : '••••' ?><?php endif; ?></td>
            <td><?= $c['first_login_at'] ? '<span class="badge badge-info">مستخدم</span>' : '<span class="badge badge-outline">لم يُستخدم</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cards): ?><tr><td colspan="5"><div class="empty"><?= icon('ticket') ?><div>لا توجد كروت مرتبطة.</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('info') ?>التفاصيل</h3></div>
    <div class="panel-body">
      <dl class="kv">
        <dt>التاريخ</dt><dd><?= e(fmt_dt($sale['created_at'])) ?></dd>
        <dt>النوع</dt><dd><?= $sale['type'] === 'distributor' ? 'لموزع' : 'مباشر' ?></dd>
        <?php if ($sale['type'] === 'distributor'): ?>
          <dt>الموزع</dt><dd><a href="<?= e(url('/distributors/' . $sale['distributor_id'])) ?>"><?= e($sale['distributor_name']) ?></a></dd>
          <dt>رصيد الموزع</dt><dd><?= e(money($sale['distributor_balance'])) ?></dd>
        <?php else: ?>
          <dt>الزبون</dt><dd><?= e($sale['customer_name'] ?: '—') ?></dd>
        <?php endif; ?>
        <dt>الباقة</dt><dd><span class="swatch" style="background:<?= e($sale['category_color']) ?>"></span> <?= e($sale['category_name']) ?></dd>
        <dt>الموظف</dt><dd><?= e($sale['employee_name'] ?? '—') ?></dd>
        <dt>الحالة</dt><dd><?= $sale['status'] === 'cancelled' ? '<span class="badge badge-danger">ملغاة</span>' : '<span class="badge badge-success">مكتملة</span>' ?></dd>
        <?php if ($sale['notes']): ?><dt>ملاحظات</dt><dd><?= e($sale['notes']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>
</div>
