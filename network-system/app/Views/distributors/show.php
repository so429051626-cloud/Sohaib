<?php
/** @var App\Core\View $v */
$v->title = $dist['name'];
$v->breadcrumbs = [['الموزعون', '/distributors'], [$dist['name'], null]];
$actions = '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'كشف الحساب</button><div class="dropdown-menu">'
    . '<a class="dropdown-item" href="' . e(url('/distributors/' . $dist['id'] . '/statement', ['format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel</a>'
    . '<a class="dropdown-item" href="' . e(url('/distributors/' . $dist['id'] . '/statement', ['format' => 'csv'])) . '">' . icon('file-text') . 'CSV</a></div></div>';
if (can('distributors.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/distributors/' . $dist['id'] . '/edit')) . '">' . icon('pencil') . 'تعديل</a>';
}
if (can('distributors.payments')) {
    $actions .= '<button type="button" class="btn" data-modal-open="pay-modal">' . icon('banknote') . 'تسجيل دفعة</button>';
}
if (can('sales.create') && $dist['is_active']) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('/sales/create', ['distributor' => $dist['id']])) . '">' . icon('shopping-cart') . 'بيع كروت</a>';
}
$v->actions = $actions;
$balance = (float) $dist['balance'];
$types = ['sale' => ['بيع', 'badge-primary'], 'sale_paid' => ['مدفوع عند البيع', 'badge-success'], 'payment' => ['دفعة', 'badge-success']];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $balance > 0 ? 'is-danger' : 'is-success' ?>"><?= icon('wallet') ?></div><div class="stat-body"><div class="stat-label"><?= $balance >= 0 ? 'المستحق' : 'رصيد له' ?></div><div class="stat-value"><?= e(money(abs($balance))) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">الكروت المباعة</div><div class="stat-value"><?= num($stats['cards']) ?></div><div class="stat-meta"><?= num($stats['ops']) ?> عملية</div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('receipt') ?></div><div class="stat-body"><div class="stat-label">قيمة المبيعات</div><div class="stat-value"><?= e(money($stats['total'])) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">إجمالي المدفوع</div><div class="stat-value"><?= e(money($paid)) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('receipt') ?>كشف الحساب</h3></div>
    <div class="table-wrap" style="max-height:640px">
      <table class="table table-compact">
        <thead><tr><th>التاريخ</th><th>النوع</th><th>البيان</th><th class="num">عليه</th><th class="num">له</th><th class="num">الرصيد</th></tr></thead>
        <tbody>
        <?php foreach ($statement as $r): [$label, $badge] = $types[$r['type']] ?? [$r['type'], 'badge-outline']; ?>
          <tr>
            <td class="nowrap text-sm"><?= e(fmt_dt($r['date'])) ?></td>
            <td><span class="badge <?= e($badge) ?>"><?= e($label) ?></span></td>
            <td class="text-sm"><?= $r['type'] !== 'payment' ? '<a href="' . e(url('/sales/' . $r['ref'])) . '">#' . (int) $r['ref'] . '</a> · ' : '' ?><?= e($r['description']) ?></td>
            <td class="num nowrap"><?= $r['debit'] > 0 ? e(money($r['debit'])) : '' ?></td>
            <td class="num nowrap"><?= $r['credit'] > 0 ? '<span class="text-success">' . e(money($r['credit'])) . '</span>' : '' ?></td>
            <td class="num nowrap"><strong><?= e(money($r['balance'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$statement): ?><tr><td colspan="6"><div class="empty"><?= icon('receipt') ?><div>لا توجد حركات.</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>البيانات</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الهاتف</dt><dd><?= $dist['phone'] ? '<a class="mono" href="tel:' . e($dist['phone']) . '">' . e($dist['phone']) . '</a>' : '—' ?></dd>
          <dt>المنطقة</dt><dd><?= e($dist['area_name'] ?? '—') ?></dd>
          <dt>العنوان</dt><dd><?= e($dist['address'] ?: '—') ?></dd>
          <dt>الخصم الافتراضي</dt><dd><?= (float) $dist['discount_percent'] > 0 ? ltr(e((string) (float) $dist['discount_percent'] . '%')) : '—' ?></dd>
          <dt>الحالة</dt><dd><?= $dist['is_active'] ? '<span class="badge badge-success">فعّال</span>' : '<span class="badge badge-outline">موقوف</span>' ?></dd>
          <?php if ($dist['notes']): ?><dt>ملاحظات</dt><dd><?= e($dist['notes']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if ($stock): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('layers') ?>الكروت حسب الباقة</h3></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>الباقة</th><th class="num">المباعة</th><th class="num">المستخدمة</th><th class="num">المتبقية لديه</th></tr></thead>
          <tbody>
          <?php foreach ($stock as $s): ?>
            <tr>
              <td><span class="swatch" style="background:<?= e($s['color']) ?>"></span> <?= e($s['name']) ?></td>
              <td class="num"><?= num($s['sold']) ?></td>
              <td class="num"><?= num($s['used']) ?></td>
              <td class="num"><strong><?= num((int) $s['sold'] - (int) $s['used']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('distributors.payments')): ?>
<dialog class="modal modal-sm" id="pay-modal">
  <form method="post" action="<?= e(url('/distributors/' . $dist['id'] . '/payment')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('banknote') ?>تسجيل دفعة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label for="p-amount">المبلغ</label><input class="input" id="p-amount" name="amount" value="<?= $balance > 0 ? e((string) $balance) : '' ?>" inputmode="decimal" required></div>
      <div class="field"><label for="p-method">طريقة الدفع</label>
        <select class="input" id="p-method" name="method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label for="p-notes">ملاحظات</label><input class="input" id="p-notes" name="notes"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تسجيل</button></div>
  </form>
</dialog>
<?php endif; ?>
