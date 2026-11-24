<?php
/** @var App\Core\View $v */
$memUsed = $router['memory_total'] ? (int) round(100 - $router['memory_free'] / $router['memory_total'] * 100) : null;
$hddUsed = $router['hdd_total'] ? (int) round(100 - $router['hdd_free'] / $router['hdd_total'] * 100) : null;
$labels = [];
$cpu = [];
$users = [];
foreach ($metrics as $m) {
    $labels[] = date('H:i', strtotime($m['created_at']));
    $cpu[] = $m['online'] ? (int) $m['cpu_load'] : null;
    $users[] = $m['online'] ? (int) $m['active_hotspot'] + (int) $m['active_ppp'] : null;
}
$v->script('vendor/chart.umd.min.js');
?>
<div class="panel-body">
  <div class="stats" style="margin-bottom:18px">
    <div class="stat"><div class="stat-icon is-success"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">المتصلون الآن</div><div class="stat-value" data-live="active"><?= num((int) $router['active_hotspot'] + (int) $router['active_ppp']) ?></div><div class="stat-meta">هوتسبوت <?= num($router['active_hotspot']) ?> · PPP <?= num($router['active_ppp']) ?></div></div></div>
    <div class="stat"><div class="stat-icon"><?= icon('cpu') ?></div><div class="stat-body"><div class="stat-label">المعالج</div><div class="stat-value"><span data-live="cpu"><?= $router['cpu_load'] !== null ? (int) $router['cpu_load'] : '—' ?></span>%</div><div class="stat-meta"><?= e($router['cpu_count'] ? $router['cpu_count'] . ' أنوية' : '') ?></div></div></div>
    <div class="stat"><div class="stat-icon is-violet"><?= icon('memory-stick') ?></div><div class="stat-body"><div class="stat-label">الذاكرة المستخدمة</div><div class="stat-value"><span data-live="memory"><?= $memUsed ?? '—' ?></span>%</div><div class="stat-meta"><?= $router['memory_total'] ? e(fmt_bytes($router['memory_total'])) : '' ?></div></div></div>
    <div class="stat"><div class="stat-icon is-info"><?= icon('clock') ?></div><div class="stat-body"><div class="stat-label">مدة التشغيل</div><div class="stat-value" style="font-size:17px" data-live="uptime"><?= $router['uptime_seconds'] ? e(fmt_duration($router['uptime_seconds'])) : '—' ?></div></div></div>
  </div>

  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head">
          <h3><?= icon('chart-line') ?>حركة الإنترنت المباشرة</h3>
          <div class="panel-tools">
            <span class="badge badge-success badge-dot" id="live-state">مباشر الآن</span>
            <span class="text-sm text-muted">الواجهة: <span class="mono"><?= e($router['wan_interface'] ?: '—') ?></span></span>
          </div>
        </div>
        <div class="panel-body">
          <?php if ($router['wan_interface']): ?>
            <div class="flex gap-3 mb-2 text-sm">
              <span>تنزيل: <strong class="mono" id="rx-now">—</strong></span>
              <span>رفع: <strong class="mono" id="tx-now">—</strong></span>
            </div>
            <div class="chart-box"><canvas id="live-chart"></canvas></div>
          <?php else: ?>
            <div class="empty"><?= icon('cable') ?><div>حدد واجهة الإنترنت في إعدادات جهاز MikroTik لعرض الحركة.</div></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('chart-column') ?>آخر 24 ساعة</h3></div>
        <div class="panel-body">
          <?php if ($metrics): ?><div class="chart-box is-sm"><canvas id="history-chart"></canvas></div>
          <?php else: ?><div class="empty"><?= icon('chart-column') ?><div>لا توجد قياسات محفوظة بعد.</div></div><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('info') ?>معلومات الجهاز</h3></div>
        <div class="panel-body">
          <dl class="kv">
            <dt>الهوية</dt><dd><?= e($router['identity'] ?: '—') ?></dd>
            <dt>الموديل</dt><dd><?= e($router['model'] ?: ($router['board_name'] ?: '—')) ?></dd>
            <dt>الإصدار</dt><dd><span class="mono"><?= e($router['version'] ?: '—') ?></span></dd>
            <dt>المعمارية</dt><dd><?= e($router['architecture'] ?: '—') ?></dd>
            <dt>الرقم التسلسلي</dt><dd><span class="mono"><?= e($router['serial_number'] ?: '—') ?></span></dd>
            <dt>الحرارة</dt><dd><?= $router['temperature'] !== null ? e($router['temperature']) . ' °C' : '—' ?></dd>
            <dt>الجهد</dt><dd><?= $router['voltage'] !== null ? e($router['voltage']) . ' V' : '—' ?></dd>
            <dt>التخزين</dt><dd><?= $hddUsed !== null ? $hddUsed . '% من ' . e(fmt_bytes($router['hdd_total'])) : '—' ?></dd>
            <dt>مجلد الهوتسبوت</dt><dd><span class="mono"><?= e($router['hotspot_dir'] ?: '—') ?></span></dd>
          </dl>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('database') ?>في النظام</h3></div>
        <div class="panel-body">
          <dl class="kv">
            <dt>كروت على جهاز MikroTik</dt><dd><?= num($stats['cards_present']) ?></dd>
            <dt>مشتركون مرتبطون</dt><dd><?= num($stats['subscribers']) ?></dd>
            <dt>جلسات اليوم</dt><dd><?= num($stats['sessions_today']) ?></dd>
            <dt>مهام معلّقة</dt><dd><?= $stats['pending_tasks'] > 0 ? '<a href="' . e(url('/routers/' . $router['id'], ['tab' => 'tasks'])) . '">' . num($stats['pending_tasks']) . '</a>' : '0' ?></dd>
            <dt>آخر ظهور</dt><dd><?= e(fmt_dt($router['last_seen_at'])) ?></dd>
            <?php if ($router['site_id'] || $router['lat'] !== null): ?><dt>الموقع</dt><dd><?= $v->partial('location-link', ['kind' => 'router', 'id' => $router['id'], 'siteId' => $router['site_id'], 'siteName' => $router['site_name'], 'lat' => $router['lat'], 'lng' => $router['lng']]) ?></dd><?php endif; ?>
          </dl>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var hist = document.getElementById('history-chart');
  if (hist) {
    SN.chart(hist, { type: 'line', labels: <?= json_encode($labels) ?>, datasets: [
      { label: 'المتصلون', data: <?= json_encode($users) ?> },
      { label: 'المعالج %', data: <?= json_encode($cpu) ?>, fill: false }
    ] });
  }
  var canvas = document.getElementById('live-chart');
  if (!canvas) { return; }
  var labels = [], rx = [], tx = [];
  var chart = SN.chart(canvas, { type: 'line', labels: labels, datasets: [{ label: 'تنزيل', data: rx }, { label: 'رفع', data: tx }], yFormat: function (v) { return SN.fmtRate(v); }, tooltip: function (v) { return SN.fmtRate(v); }, animate: false });
  canvas.addEventListener('chart:recreated', function (e) { chart = e.detail; });
  var state = document.getElementById('live-state'), failures = 0, timer = null;
  function tick() {
    if (document.hidden) { timer = setTimeout(tick, 3000); return; }
    SN.api(SN.base + '/routers/<?= (int) $router['id'] ?>/live', { silent: true }).then(function (d) {
      if (!d.ok) { throw new Error(d.message); }
      failures = 0;
      state.className = 'badge badge-success badge-dot'; state.textContent = 'مباشر الآن';
      labels.push(d.time); rx.push(d.rx); tx.push(d.tx);
      if (labels.length > 40) { labels.shift(); rx.shift(); tx.shift(); }
      chart.data.labels = labels; chart.data.datasets[0].data = rx; chart.data.datasets[1].data = tx; chart.update('none');
      document.getElementById('rx-now').textContent = SN.fmtRate(d.rx);
      document.getElementById('tx-now').textContent = SN.fmtRate(d.tx);
      [['cpu', d.cpu], ['memory', d.memory], ['uptime', d.uptime], ['active', d.active]].forEach(function (p) {
        var el = document.querySelector('[data-live="' + p[0] + '"]'); if (el && p[1] !== null && p[1] !== undefined) { el.textContent = p[1]; }
      });
    }).catch(function () {
      failures++;
      state.className = 'badge badge-danger badge-dot'; state.textContent = 'منقطع';
    }).then(function () { timer = setTimeout(tick, failures > 3 ? 15000 : 3000); });
  }
  tick();
});
</script>
