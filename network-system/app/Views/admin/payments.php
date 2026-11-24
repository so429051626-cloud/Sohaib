<?php
/**
 * @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var string $month
 * @var array $series @var float $sum @var float $all @var float $thisMonth
 */
use App\Core\Platform;

$v->title = 'المدفوعات';
$v->script('vendor/chart.umd.min.js');
$cur = Platform::setting('platform.currency');
$amount = static fn ($a) => ltr(number_format((float) $a, abs((float) $a - round((float) $a)) < 0.005 ? 0 : 2, '.', ',') . ' ' . $cur);
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">هذا الشهر</div><div class="stat-value"><?= e($amount($thisMonth)) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('wallet') ?></div><div class="stat-body"><div class="stat-label">الإجمالي</div><div class="stat-value"><?= e($amount($all)) ?></div></div></div>
  <?php if ($month !== ''): ?><div class="stat"><div class="stat-icon is-info"><?= icon('calendar') ?></div><div class="stat-body"><div class="stat-label"><?= e($month) ?></div><div class="stat-value"><?= e($amount($sum)) ?></div></div></div><?php endif; ?>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('chart-column') ?>الإيرادات خلال 12 شهراً</h3></div>
  <div class="panel-body"><div class="chart-box"><canvas id="revenue-chart"></canvas></div></div>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="month">الشهر</label><input class="input" id="month" type="month" name="month" value="<?= e($month) ?>" data-autosubmit></div>
    <?php if ($month !== ''): ?><div class="field" style="flex:0 0 auto"><a class="btn" href="<?= e(url('/admin/payments')) ?>">الكل</a></div><?php endif; ?>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>التاريخ</th><th>الحساب</th><th>المبلغ</th><th>المدة</th><th>حتى</th><th>الطريقة</th><th>ملاحظة</th><th>المسؤول</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td class="nowrap text-sm"><?= e(fmt_dt($p['created_at'])) ?></td>
        <td class="nowrap"><?php if ($p['tenant_id'] && $p['slug']): ?><a class="cell-title" href="<?= e(url('/admin/tenants/' . $p['tenant_id'])) ?>"><?= e($p['tenant_name']) ?></a><?php else: ?><span class="text-muted"><?= e($p['tenant_name']) ?></span><?php endif; ?></td>
        <td class="nowrap"><strong><?= e($amount($p['amount'])) ?></strong></td>
        <td class="nowrap"><?= $p['months'] ? num((int) $p['months']) . ' شهر' : '—' ?></td>
        <td class="nowrap text-sm"><?= $p['paid_until'] ? e(substr($p['paid_until'], 0, 10)) : '—' ?></td>
        <td class="nowrap"><?= e($p['method'] ?? '—') ?></td>
        <td class="text-muted text-sm"><?= e($p['note'] ?? '') ?></td>
        <td class="nowrap text-sm"><?= e($p['admin_name'] ?? '') ?></td>
        <td class="w-actions"><div class="row-actions">
          <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/admin/payments/' . $p['id'] . '/invoice')) ?>" title="الفاتورة" data-no-loading><?= icon('receipt-text') ?></a>
          <form method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/delete')) ?>" data-confirm="حذف هذه الدفعة من السجل؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9"><div class="empty"><?= icon('banknote') ?><div class="empty-title">لا توجد مدفوعات</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= $pager->render($request) ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var data = <?= json_encode($series) ?>;
  SN.chart(document.getElementById('revenue-chart'), {
    type: 'bar', labels: Object.keys(data), datasets: [{ label: 'الإيرادات', data: Object.values(data) }],
    yFormat: function (v) { return SN.fmtNum(v); }, tooltip: function (v) { return SN.fmtNum(v) + ' <?= e($cur) ?>'; }
  });
});
</script>
