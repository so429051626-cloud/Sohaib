<?php
/** @var App\Core\View $v */
use App\Services\Notifier;

$v->title = 'لوحة التحكم';
$v->subtitle = 'نظرة شاملة على الشبكة — ' . date('Y-m-d');
$v->script('vendor/chart.umd.min.js');
$v->actions = '<a class="btn" href="' . e(url('/sessions')) . '">' . icon('activity') . 'المتصلون الآن</a>'
    . (can('cards.generate') ? '<a class="btn btn-primary" href="' . e(url('/cards/generate')) . '">' . icon('wand-sparkles') . 'توليد كروت</a>' : '');
$hourLabels = array_map(static fn ($r) => substr($r['h'], 11, 5), $online24);
$hourUsers = array_map(static fn ($r) => (int) $r['users'], $online24);
$dayLabels = array_map(static fn ($d) => substr($d, 5), array_keys($days));
?>
<?php if (!empty($onboard)): ?>
<div class="panel onboard-card">
  <div class="onboard-main">
    <span class="wizard-hero-icon"><?= icon('router') ?></span>
    <div>
      <div class="wizard-hero-title">اربط جهاز MikroTik بـ 3 خطوات</div>
      <div class="onboard-steps"><span><?= icon('circle-dot', 'icon-sm') ?>اسم الجهاز</span><span><?= icon('circle-dot', 'icon-sm') ?>أمر واحد في Terminal</span><span><?= icon('circle-dot', 'icon-sm') ?>متابعة حية حتى يجهز للكروت</span></div>
    </div>
  </div>
  <a class="btn btn-primary btn-lg" href="<?= e(url('/routers/connect')) ?>"><?= icon('link') ?>ابدأ الربط</a>
</div>
<?php endif; ?>
<?php if ($cronStale && can('settings.manage')): ?>
<div class="notice notice-warning mb-3"><?= icon('clock') ?><div><div class="notice-title">المهمة المجدولة لا تعمل بانتظام</div>
  المزامنة التلقائية مع أجهزة MikroTik متوقفة. <a href="<?= e(url('/settings', ['tab' => 'cron'])) ?>">إعداد المهمة المجدولة</a></div></div>
<?php endif; ?>
<?php if ($panelUrlMissing && can('settings.manage')): ?>
<div class="notice mb-3"><?= icon('link') ?><div>لم يُضبط الرابط العام للنظام. <a href="<?= e(url('/settings')) ?>">الإعدادات العامة</a></div></div>
<?php endif; ?>

<div class="stats">
  <a class="stat" href="<?= e(url('/sessions')) ?>">
    <div class="stat-icon is-success"><?= icon('activity') ?></div>
    <div class="stat-body"><div class="stat-label">المتصلون الآن</div><div class="stat-value" data-k="online"><?= num($kpi['online']) ?></div>
      <div class="stat-meta">هوتسبوت <span data-k="online_hs"><?= num($kpi['online_hs']) ?></span> · PPP <span data-k="online_ppp"><?= num($kpi['online_ppp']) ?></span></div></div>
  </a>
  <a class="stat" href="<?= e(url('/routers')) ?>">
    <div class="stat-icon"><?= icon('router') ?></div>
    <div class="stat-body"><div class="stat-label">أجهزة MikroTik المتصلة</div><div class="stat-value"><span data-k="routers_online"><?= num($kpi['routers_online']) ?></span> / <?= num($kpi['routers_total']) ?></div>
      <div class="stat-meta"><?= $kpi['routers_offline'] > 0 ? '<span class="down">' . num($kpi['routers_offline']) . ' منقطع</span>' : 'كل أجهزة MikroTik تعمل' ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/cards', ['status' => 'active'])) ?>">
    <div class="stat-icon is-info"><?= icon('ticket') ?></div>
    <div class="stat-body"><div class="stat-label">الكروت النشطة</div><div class="stat-value"><?= num($kpi['cards_active']) ?></div>
      <div class="stat-meta">فُعّلت اليوم <?= num($kpi['activated_today']) ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/cards', ['status' => 'new'])) ?>">
    <div class="stat-icon is-violet"><?= icon('boxes') ?></div>
    <div class="stat-body"><div class="stat-label">الكروت المتاحة</div><div class="stat-value"><?= num($kpi['cards_available']) ?></div>
      <div class="stat-meta">مباعة غير مستخدمة <?= num($kpi['cards_sold']) ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/subscribers')) ?>">
    <div class="stat-icon is-success"><?= icon('users') ?></div>
    <div class="stat-body"><div class="stat-label">المشتركون الفعّالون</div><div class="stat-value"><?= num($kpi['subs_active']) ?></div>
      <div class="stat-meta"><?= $kpi['subs_expiring'] ? '<span class="down">' . num($kpi['subs_expiring']) . ' ينتهي قريباً</span> · ' : '' ?>منتهي <?= num($kpi['subs_expired']) ?></div></div>
  </a>
  <?php if ($financial): ?>
  <a class="stat" href="<?= e(url('/sales')) ?>">
    <div class="stat-icon is-warning"><?= icon('banknote') ?></div>
    <div class="stat-body"><div class="stat-label">مبيعات الكروت اليوم</div><div class="stat-value"><?= e(money($kpi['sales_today'])) ?></div>
      <div class="stat-meta"><?= num($kpi['sales_qty_today']) ?> كرت</div></div>
  </a>
  <div class="stat">
    <div class="stat-icon is-success"><?= icon('wallet') ?></div>
    <div class="stat-body"><div class="stat-label">المحصّل اليوم</div><div class="stat-value"><?= e(money($kpi['collected_today'])) ?></div>
      <div class="stat-meta">ذمم مستحقة <?= e(money($kpi['debts'])) ?></div></div>
  </div>
  <?php endif; ?>
  <div class="stat">
    <div class="stat-icon is-info"><?= icon('arrow-up-down') ?></div>
    <div class="stat-body"><div class="stat-label">حركة الإنترنت الآن</div><div class="stat-value" style="font-size:18px"><span data-k="rx"><?= e(fmt_rate($kpi['rx'])) ?></span></div>
      <div class="stat-meta">رفع <span data-k="tx"><?= e(fmt_rate($kpi['tx'])) ?></span></div></div>
  </div>
</div>

<div class="grid grid-main-side mb-3">
  <div class="panel">
    <div class="panel-head"><h2><?= icon('chart-line') ?>المتصلون خلال 24 ساعة</h2></div>
    <div class="panel-body">
      <?php if ($online24): ?><div class="chart-box"><canvas id="online-chart"></canvas></div>
      <?php else: ?><div class="empty"><?= icon('chart-line') ?><div>تظهر البيانات بعد أول مزامنة لأجهزة MikroTik.</div></div><?php endif; ?>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('router') ?>حالة أجهزة MikroTik</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('/routers')) ?>">الكل</a></div>
    <ul class="list" style="max-height:330px;overflow:auto">
      <?php foreach ($routers as $r): ?>
        <li class="list-item" data-router="<?= (int) $r['id'] ?>">
          <span class="status-dot is-<?= e($r['status']) ?>"></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
            <div class="list-item-sub"><?= e($r['area_name'] ?? '') ?><?= $r['status'] === 'online' ? ($r['area_name'] ? ' · ' : '') . 'CPU <span data-r="cpu">' . (int) $r['cpu_load'] . '</span>%' : ' منقطع ' . e(time_ago($r['last_seen_at'])) ?></div>
          </div>
          <div class="list-item-end"><strong data-r="users"><?= num((int) $r['active_hotspot'] + (int) $r['active_ppp']) ?></strong> <?= icon('users', 'icon-sm') ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$routers): ?><li><div class="empty"><?= icon('router') ?><div>لا توجد أجهزة MikroTik.</div><?php if (can('routers.manage')): ?><a class="btn btn-primary btn-sm" href="<?= e(url('/routers/create')) ?>"><?= icon('plus') ?>إضافة جهاز MikroTik</a><?php endif; ?></div></li><?php endif; ?>
    </ul>
  </div>
</div>

<div class="grid grid-main-side mb-3">
  <div class="panel">
    <div class="panel-head"><h2><?= icon('chart-column') ?><?= $financial ? 'المبيعات والتفعيلات خلال 30 يوماً' : 'تفعيل الكروت خلال 30 يوماً' ?></h2></div>
    <div class="panel-body"><div class="chart-box"><canvas id="days-chart"></canvas></div></div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('layers') ?>مخزون الباقات</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('/categories')) ?>">الباقات</a></div>
    <ul class="list" style="max-height:330px;overflow:auto">
      <?php foreach ($stock as $c):
          $low = $c['low_stock'] !== null && (int) $c['available'] <= (int) $c['low_stock']; ?>
        <li class="list-item">
          <span class="swatch" style="background:<?= e($c['color']) ?>"></span>
          <div class="list-item-main">
            <div class="list-item-title"><?= e($c['name']) ?></div>
            <div class="list-item-sub"><?= e(money($c['price'])) ?> · نشطة <?= num($c['active']) ?> · مباعة <?= num($c['sold']) ?></div>
          </div>
          <div class="list-item-end"><span class="badge <?= $low ? 'badge-danger' : 'badge-success' ?>"><?= num($c['available']) ?> متاحة</span></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$stock): ?><li><div class="empty"><?= icon('layers') ?><div>لا توجد باقات.</div></div></li><?php endif; ?>
    </ul>
  </div>
</div>

<div class="grid grid-3">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('log-in') ?>آخر عمليات الدخول</h3><?php if (can('hotspot.log')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/hotspot/log')) ?>">السجل</a><?php endif; ?></div>
    <ul class="list">
      <?php foreach ($recent as $l): ?>
        <li class="list-item">
          <span class="list-icon <?= $l['result'] === 'reject' ? 'is-danger' : 'is-success' ?>"><?= icon($l['result'] === 'reject' ? 'circle-x' : 'circle-check') ?></span>
          <div class="list-item-main">
            <div class="list-item-title mono"><?= e($l['login']) ?></div>
            <div class="list-item-sub"><?= e($l['router_name'] ?? '') ?><?= $l['reason'] && $l['result'] === 'reject' ? ' · ' . e(\App\Services\CardRules::message($l['reason'])) : '' ?></div>
          </div>
          <div class="list-item-end"><?= e(time_ago($l['created_at'])) ?></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li><div class="empty"><?= icon('log-in') ?><div>لا توجد عمليات دخول بعد.</div></div></li><?php endif; ?>
    </ul>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('trending-up') ?>الأعلى استهلاكاً الآن</h3></div>
    <ul class="list">
      <?php foreach ($topUsers as $t): ?>
        <li class="list-item">
          <span class="list-icon is-info"><?= icon('smartphone') ?></span>
          <div class="list-item-main">
            <div class="list-item-title">
              <?php if ($t['card_id']): ?><a class="mono" href="<?= e(url('/check/card/' . $t['card_id'])) ?>"><?= e($t['username']) ?></a>
              <?php elseif ($t['subscriber_id']): ?><a class="mono" href="<?= e(url('/subscribers/' . $t['subscriber_id'])) ?>"><?= e($t['username']) ?></a>
              <?php else: ?><span class="mono"><?= e($t['username']) ?></span><?php endif; ?>
            </div>
            <div class="list-item-sub"><?= e($t['hostname'] ?: $t['mac']) ?> · <?= e($t['router_name'] ?? '') ?></div>
          </div>
          <div class="list-item-end"><strong><?= e(fmt_bytes($t['bytes'])) ?></strong><div><?= e(fmt_duration($t['uptime'], true)) ?></div></div>
        </li>
      <?php endforeach; ?>
      <?php if (!$topUsers): ?><li><div class="empty"><?= icon('trending-up') ?><div>لا توجد جلسات نشطة.</div></div></li><?php endif; ?>
    </ul>
  </div>
  <div class="panel">
    <?php if ($expiring): ?>
      <div class="panel-head"><h3><?= icon('calendar-clock') ?>اشتراكات تنتهي قريباً</h3><a class="btn btn-ghost btn-sm" href="<?= e(url('/subscribers', ['state' => 'expiring'])) ?>">الكل</a></div>
      <ul class="list">
        <?php foreach ($expiring as $s): $left = seconds_until($s['expires_at']); ?>
          <li class="list-item">
            <span class="list-icon <?= $left <= 0 ? 'is-danger' : 'is-warning' ?>"><?= icon('calendar-clock') ?></span>
            <div class="list-item-main">
              <a class="list-item-title" href="<?= e(url('/subscribers/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a>
              <div class="list-item-sub"><?= e($s['plan_name']) ?><?= $s['phone'] ? ' · <span class="mono">' . e($s['phone']) . '</span>' : '' ?></div>
            </div>
            <div class="list-item-end"><?= $left <= 0 ? '<span class="badge badge-danger">منتهي</span>' : e(fmt_duration($left)) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="panel-head"><h3><?= icon('bell') ?>آخر التنبيهات</h3><?php if (can('alerts.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/alerts')) ?>">الكل</a><?php endif; ?></div>
      <ul class="list">
        <?php foreach ($alerts as $a): ?>
          <li class="list-item">
            <span class="list-icon is-<?= e($a['level'] === 'danger' ? 'danger' : ($a['level'] === 'warning' ? 'warning' : ($a['level'] === 'success' ? 'success' : 'info'))) ?>"><?= icon(Notifier::levelIcon($a['level'])) ?></span>
            <div class="list-item-main"><div class="list-item-title"><?= e($a['title']) ?></div><div class="list-item-sub"><?= e($a['message'] ?? '') ?></div></div>
            <div class="list-item-end"><?= e(time_ago($a['created_at'])) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$alerts): ?><li><div class="empty"><?= icon('bell') ?><div>لا توجد تنبيهات.</div></div></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var oc = document.getElementById('online-chart');
  if (oc) {
    SN.chart(oc, { type: 'line', labels: <?= json_encode($hourLabels) ?>, datasets: [{ label: 'المتصلون', data: <?= json_encode($hourUsers) ?> }] });
  }
  SN.chart(document.getElementById('days-chart'), {
    type: 'bar',
    labels: <?= json_encode($dayLabels) ?>,
    xTicks: 10,
    datasets: <?= json_encode(array_values(array_filter([
        $financial ? ['label' => 'المبيعات', 'data' => array_map(static fn ($d) => round($d['sales'], 2), array_values($days))] : null,
        ['label' => 'التفعيلات', 'data' => array_map(static fn ($d) => $d['activations'], array_values($days))],
    ])), JSON_UNESCAPED_UNICODE) ?>
  });
  function refresh() {
    if (document.hidden) { return; }
    SN.api(SN.base + '/dashboard/data', { silent: true }).then(function (d) {
      ['online', 'online_hs', 'online_ppp', 'routers_online'].forEach(function (k) {
        var el = document.querySelector('[data-k="' + k + '"]'); if (el) { el.textContent = SN.fmtNum(d[k]); }
      });
      var rx = document.querySelector('[data-k="rx"]'), tx = document.querySelector('[data-k="tx"]');
      if (rx) { rx.textContent = SN.fmtRate(d.rx); } if (tx) { tx.textContent = SN.fmtRate(d.tx); }
      (d.routers || []).forEach(function (r) {
        var li = document.querySelector('[data-router="' + r.id + '"]'); if (!li) { return; }
        li.querySelector('.status-dot').className = 'status-dot is-' + r.status;
        var u = li.querySelector('[data-r="users"]'); if (u) { u.textContent = SN.fmtNum((+r.active_hotspot || 0) + (+r.active_ppp || 0)); }
        var c = li.querySelector('[data-r="cpu"]'); if (c && r.cpu_load !== null) { c.textContent = r.cpu_load; }
      });
    }).catch(function () {});
  }
  setInterval(refresh, 30000);
});
</script>
