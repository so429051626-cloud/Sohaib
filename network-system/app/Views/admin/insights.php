<?php
/** @var App\Core\View $v @var array $s @var array $accounts @var array $risk @var string $sort */
use App\Controllers\AdminController;

$v->title = 'التحليلات';
$v->script('vendor/chart.umd.min.js');
$cur = $s['currency'];
$money = static fn ($a) => ltr(number_format((float) $a, abs((float) $a - round((float) $a)) < 0.005 ? 0 : 2, '.', ',') . ' ' . $cur);
$delta = $s['revenue_prev'] > 0 ? round(100 * ($s['revenue_month'] - $s['revenue_prev']) / $s['revenue_prev']) : null;
usort($risk, static fn ($a, $b) => $b['risk'] <=> $a['risk']);
$phase = ['grace' => ['فترة سماح', 'badge-warning'], 'readonly' => ['قراءة فقط', 'badge-danger']];
$sorts = ['' => 'آخر نشاط', 'subscribers' => 'المشتركون', 'paid' => 'المدفوع', 'risk' => 'خطر الترك'];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">دخل هذا الشهر</div><div class="stat-value"><?= e($money($s['revenue_month'])) ?></div>
    <?php if ($delta !== null): ?><div class="stat-meta <?= $delta >= 0 ? 'text-success' : 'text-danger' ?>"><?= ltr(($delta >= 0 ? '+' : '') . $delta . '%') ?> عن الشهر الماضي</div><?php endif; ?></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('user-plus') ?></div><div class="stat-body"><div class="stat-label">تسجيلات هذا الأسبوع</div><div class="stat-value"><?= num($s['signups_week']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('percent') ?></div><div class="stat-body"><div class="stat-label">التحوّل من تجربة إلى مدفوع</div><div class="stat-value"><?= $s['conversion'] !== null ? ltr(e($s['conversion'] . '%')) : '—' ?></div><div class="stat-meta"><?= num($s['paid_ever']) ?> من <?= num($s['conversion_base']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">حسابات نشطة هذا الأسبوع</div><div class="stat-value"><?= num($s['active_week']) ?></div><div class="stat-meta"><?= num($s['online']) ?> الآن</div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('triangle-alert') ?></div><div class="stat-body"><div class="stat-label">معرّضة للترك</div><div class="stat-value"><?= num(count($risk)) ?></div></div></div>
</div>

<div class="grid grid-2 mb-3">
  <div class="panel"><div class="panel-head"><h3><?= icon('chart-column') ?>الدخل الشهري</h3></div><div class="panel-body"><div class="chart-box"><canvas id="ch-revenue"></canvas></div></div></div>
  <div class="panel"><div class="panel-head"><h3><?= icon('user-plus') ?>التسجيلات والتحوّل</h3></div><div class="panel-body"><div class="chart-box"><canvas id="ch-signups"></canvas></div></div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('triangle-alert') ?>حسابات معرّضة لترك المنصة</h3><span class="badge"><?= num(count($risk)) ?></span></div>
  <?php if (!$risk): ?><div class="empty"><?= icon('circle-check') ?><div class="empty-title">لا توجد حسابات معرّضة للترك</div></div><?php else: ?>
  <ul class="list">
    <?php foreach (array_slice($risk, 0, 30) as $t): ?>
    <li class="list-item">
      <span class="risk-score <?= $t['risk'] >= 70 ? 'is-high' : 'is-mid' ?>"><?= (int) $t['risk'] ?></span>
      <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="list-item-sub"><?= e(implode(' · ', $t['reasons'])) ?></div></div>
      <div class="list-item-end"><?php if ($t['phone']): ?><span class="mono text-sm"><?= ltr(e($t['phone'])) ?></span><?php endif; ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3><?= icon('building') ?>نشاط الحسابات</h3>
    <div class="segmented"><?php foreach ($sorts as $k => $l): ?><a class="seg<?= $sort === $k ? ' is-active' : '' ?>" href="<?= e(url('/admin/insights', $k === '' ? [] : ['sort' => $k])) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  </div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>الحساب</th><th>الحالة</th><th>الباقة</th><th>آخر نشاط</th><th>أجهزة MikroTik</th><th>المشتركون</th><th>الموظفون</th><th>مبيعات الشهر</th><th>المدفوع للمنصة</th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $t): [$sl, $sc] = AdminController::STATUS[$t['status']]; ?>
      <tr>
        <td><a class="cell-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="tenant-link">/<?= e($t['slug']) ?></div></td>
        <td class="nowrap"><span class="badge <?= $sc ?>"><?= $sl ?></span><?php if (isset($phase[$t['phase']])): ?> <span class="badge <?= $phase[$t['phase']][1] ?>"><?= $phase[$t['phase']][0] ?></span><?php endif; ?></td>
        <td class="text-sm"><?= e($t['plan_name'] ?? '—') ?></td>
        <td class="nowrap text-sm"><?= $t['last_seen_at'] ? e(time_ago($t['last_seen_at'])) : '—' ?></td>
        <td><?= $t['stats_at'] ? num((int) $t['stat_routers']) : '—' ?></td>
        <td><?= $t['stats_at'] ? num((int) $t['stat_subscribers']) : '—' ?></td>
        <td><?= $t['stats_at'] ? num((int) $t['stat_employees']) : '—' ?></td>
        <td class="nowrap"><?= $t['stats_at'] ? e(ltr(number_format((float) $t['stat_sales']))) : '—' ?></td>
        <td class="nowrap"><strong><?= e($money($t['paid_total'])) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="9"><div class="empty"><?= icon('building') ?><div class="empty-title">لا توجد حسابات</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var rev = <?= json_encode($s['revenue']) ?>, sign = <?= json_encode($s['signups']) ?>, act = <?= json_encode($s['activated']) ?>;
  SN.chart(document.getElementById('ch-revenue'), {
    type: 'bar', labels: Object.keys(rev), datasets: [{ label: 'الدخل', data: Object.values(rev) }],
    yFormat: function (v) { return SN.fmtNum(v); }, tooltip: function (v) { return SN.fmtNum(v) + ' <?= e($cur) ?>'; }
  });
  SN.chart(document.getElementById('ch-signups'), {
    type: 'bar', labels: Object.keys(sign),
    datasets: [{ label: 'تسجيلات جديدة', data: Object.values(sign) }, { label: 'تحوّلت إلى مدفوع', data: Object.values(act) }],
    yFormat: function (v) { return SN.fmtNum(v); }, tooltip: function (v) { return SN.fmtNum(v); }
  });
});
</script>
