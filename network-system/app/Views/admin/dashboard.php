<?php
/**
 * @var App\Core\View $v @var array $counts @var int $total @var array $series @var array $ending @var array $latest @var array $activity @var int $online @var float $revenue
 */
use App\Controllers\AdminController;
use App\Core\Tenant;

$v->title = 'الرئيسية';
$v->script('vendor/chart.umd.min.js');
$v->actions = '<a class="btn btn-primary" href="' . e(url('/admin/tenants/create')) . '">' . icon('plus') . 'حساب جديد</a>';
$c = static fn (string $k) => (int) ($counts[$k] ?? 0);
?>
<div class="kpi-row">
  <a class="stat" href="<?= e(url('/admin/tenants')) ?>"><div class="stat-icon"><?= icon('building') ?></div><div class="stat-body"><div class="stat-label">كل الحسابات</div><div class="stat-value"><?= num($total) ?></div></div></a>
  <a class="stat" href="<?= e(url('/admin/tenants', ['status' => 'trial'])) ?>"><div class="stat-icon is-info"><?= icon('clock') ?></div><div class="stat-body"><div class="stat-label">تجريبية</div><div class="stat-value"><?= num($c('trial')) ?></div></div></a>
  <a class="stat" href="<?= e(url('/admin/tenants', ['status' => 'active'])) ?>"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">مفعّلة</div><div class="stat-value"><?= num($c('active')) ?></div></div></a>
  <a class="stat" href="<?= e(url('/admin/tenants', ['status' => 'expired'])) ?>"><div class="stat-icon is-warning"><?= icon('triangle-alert') ?></div><div class="stat-body"><div class="stat-label">منتهية أو موقوفة</div><div class="stat-value"><?= num($c('expired') + $c('suspended')) ?></div></div></a>
<?php if (App\Core\AdminAuth::can(['billing.view', 'billing.manage'])): ?>
  <a class="stat" href="<?= e(url('/admin/payments')) ?>"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">إيرادات الشهر</div><div class="stat-value"><?= e(ltr(number_format($revenue, 0, '.', ',') . ' ' . App\Core\Platform::setting('platform.currency'))) ?></div><div class="stat-meta"><?= num($online) ?> نشطة الآن</div></div></a>
  <?php endif; ?>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('chart-column') ?>الحسابات الجديدة خلال 30 يوماً</h3></div>
      <div class="panel-body"><div class="chart-box"><canvas id="signups-chart"></canvas></div></div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('building') ?>أحدث الحسابات</h3><a class="btn btn-sm" href="<?= e(url('/admin/tenants')) ?>">الكل</a></div>
      <?php if (!$latest): ?><div class="empty"><?= icon('building') ?><div>لا توجد حسابات.</div></div><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>الحساب</th><th>المالك</th><th>الحالة</th><th>التسجيل</th></tr></thead>
        <tbody>
        <?php foreach ($latest as $t): [$sl, $sc] = AdminController::STATUS[$t['status']]; ?>
          <tr>
            <td><a class="cell-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="tenant-link">/<?= e($t['slug']) ?></div></td>
            <td class="text-sm"><?= e($t['owner_name']) ?><?= $t['phone'] ? '<div class="cell-sub mono">' . e($t['phone']) . '</div>' : '' ?></td>
            <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
            <td class="nowrap text-sm"><?= e(time_ago($t['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('clock') ?>تنتهي قريباً</h3><span class="badge"><?= num(count($ending)) ?></span></div>
      <ul class="list">
        <?php foreach ($ending as $t): $left = Tenant::daysLeft($t); ?>
          <li class="list-item">
            <span class="list-icon <?= $t['status'] === 'trial' ? '' : 'is-success' ?>"><?= icon($t['status'] === 'trial' ? 'clock' : 'circle-check') ?></span>
            <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="list-item-sub"><?= $t['status'] === 'trial' ? 'تجريبي' : 'مفعّل' ?></div></div>
            <div class="list-item-end"><span class="badge <?= $left <= 1 ? 'badge-danger' : 'badge-warning' ?>"><?= $left === 0 ? 'اليوم' : num((int) $left) . ' يوم' ?></span></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$ending): ?><li class="list-item text-muted">لا يوجد.</li><?php endif; ?>
      </ul>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>آخر النشاط</h3><a class="btn btn-sm" href="<?= e(url('/admin/activity')) ?>">الكل</a></div>
      <ul class="list">
        <?php foreach ($activity as $a): ?>
          <li class="list-item">
            <span class="list-icon"><?= icon($a['actor'] === 'admin' ? 'shield-check' : ($a['actor'] === 'tenant' ? 'building' : 'activity')) ?></span>
            <div class="list-item-main"><div class="list-item-title text-sm"><?= e($a['description']) ?></div><div class="list-item-sub"><?= e($a['tenant_name'] ?? ($a['actor_name'] ?? '')) ?></div></div>
            <div class="list-item-end text-sm"><?= e(time_ago($a['created_at'])) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$activity): ?><li class="list-item text-muted">لا يوجد نشاط.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var data = <?= json_encode($series) ?>;
  SN.chart(document.getElementById('signups-chart'), {
    type: 'bar', labels: Object.keys(data).map(function (d) { return d.slice(5); }),
    datasets: [{ label: 'حسابات جديدة', data: Object.values(data) }], xTicks: 10,
    yFormat: function (v) { return SN.fmtNum(v); }, tooltip: function (v) { return SN.fmtNum(v); }
  });
});
</script>
