<?php
/** @var App\Core\View $v @var array $router @var string $range @var array $series @var string $state @var ?int $eta @var ?array $last */
use App\Services\PowerMonitor;

$v->title = $router['name'];
$v->breadcrumbs = [['مراقبة الطاقة', '/power'], [$router['name']]];
$v->script('vendor/chart.umd.min.js');
$volt = static fn ($x) => $x === null || $x === '' ? '—' : ltr(rtrim(rtrim(number_format((float) $x, 1, '.', ''), '0'), '.') . ' V');
[$sl, $sc] = PowerMonitor::STATES[$state];
$ranges = ['24h' => '24 ساعة', '7d' => '7 أيام', '30d' => '30 يوماً'];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $state === 'crit' ? 'is-danger' : ($state === 'warn' ? 'is-warning' : 'is-success') ?>"><?= icon('zap') ?></div><div class="stat-body"><div class="stat-label">الجهد الحالي</div><div class="stat-value"><?= $state === 'unsupported' ? '—' : $volt($router['voltage']) ?></div><div class="stat-meta"><span class="badge <?= $sc ?>"><?= $sl ?></span></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('trending-down') ?></div><div class="stat-body"><div class="stat-label">الأدنى في المدة</div><div class="stat-value" data-power-low><?= $volt($series['low']) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('trending-up') ?></div><div class="stat-body"><div class="stat-label">الأعلى في المدة</div><div class="stat-value" data-power-high><?= $volt($series['high']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('thermometer') ?></div><div class="stat-body"><div class="stat-label">الحرارة</div><div class="stat-value"><?= $router['temperature'] !== null ? ltr(e($router['temperature']) . ' °C') : '—' ?></div><?php if ($last): ?><div class="stat-meta">آخر قراءة <?= e(time_ago($last['created_at'])) ?></div><?php endif; ?></div></div>
</div>

<?php if ($eta !== null && $eta <= 6 * 3600 && $state !== 'crit'): ?>
<div class="notice notice-warning mb-3"><?= icon('timer') ?><div>بطارية <?= e($router['name']) ?> تنخفض، متوقع أن تفصل خلال نحو <?= e(PowerMonitor::etaLabel($eta)) ?>.</div></div>
<?php endif; ?>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('chart-line') ?>الجهد</h3>
      <div class="segmented" data-power-ranges data-url="<?= e(url('/power/' . $router['id'] . '/data')) ?>">
        <?php foreach ($ranges as $k => $l): ?><a class="<?= $range === $k ? 'is-active' : '' ?>" href="<?= e(url('/power/' . $router['id'], ['range' => $k])) ?>" data-range="<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?>
      </div>
    </div>
    <div class="panel-body">
      <?php if ($state === 'unsupported'): ?>
        <div class="empty"><?= icon('circle-help') ?><div class="empty-title">غير مدعوم</div><div class="text-muted text-sm">هذا الجهاز لا يرسل قراءة الجهد.</div></div>
      <?php else: ?>
        <div class="chart-box is-lg"><canvas id="power-chart"></canvas></div>
        <div class="empty" data-power-empty<?= $series['count'] ? ' hidden' : '' ?>><?= icon('chart-line') ?><div class="empty-title">لا توجد قراءات في هذه المدة</div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <form method="post" action="<?= e(url('/power/' . $router['id'])) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('sliders-horizontal') ?>حدود الجهد</h3></div>
      <div class="panel-body stack" style="gap:14px">
        <div class="field"><label>حد التحذير</label><div class="input-group"><input class="input input-ltr" name="voltage_warn" value="<?= e(old('voltage_warn', $router['voltage_warn'] ?? '')) ?>" inputmode="decimal"<?= can('power.manage') ? '' : ' disabled' ?>><span class="input-addon">V</span></div><?= error_html('voltage_warn') ?></div>
        <div class="field"><label>حد الخطر</label><div class="input-group"><input class="input input-ltr" name="voltage_crit" value="<?= e(old('voltage_crit', $router['voltage_crit'] ?? '')) ?>" inputmode="decimal"<?= can('power.manage') ? '' : ' disabled' ?>><span class="input-addon">V</span></div><?= error_html('voltage_crit') ?></div>
        <?php if (can('power.manage')): ?><button class="btn btn-primary"><?= icon('save') ?>حفظ</button><?php endif; ?>
      </div>
    </form>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('router') ?>الجهاز</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الحالة</dt><dd><?= $router['status'] === 'online' ? 'متصل' : ($router['status'] === 'offline' ? 'غير متصل' : '—') ?></dd>
          <dt>الطراز</dt><dd><?= e($router['model'] ?: ($router['board_name'] ?: '—')) ?></dd>
          <dt>آخر قراءة</dt><dd><?= $last ? e(fmt_dt($last['created_at'])) : '—' ?></dd>
        </dl>
        <?php if (can('routers.view')): ?><a class="btn btn-sm mt-3" href="<?= e(url('/routers/' . $router['id'])) ?>"><?= icon('external-link') ?>صفحة الجهاز</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php if ($state !== 'unsupported'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var canvas = document.getElementById('power-chart'), chart = null;
  var warn = <?= json_encode($router['voltage_warn'] !== null ? (float) $router['voltage_warn'] : null) ?>, crit = <?= json_encode($router['voltage_crit'] !== null ? (float) $router['voltage_crit'] : null) ?>;
  var fmt = function (v) { return v === null || v === undefined ? '—' : '⁦' + (Math.round(v * 10) / 10) + ' V⁩'; };
  function draw(s) {
    document.querySelector('[data-power-empty]').hidden = s.count > 0;
    canvas.parentNode.hidden = s.count === 0;
    document.querySelector('[data-power-low]').textContent = fmt(s.low);
    document.querySelector('[data-power-high]').textContent = fmt(s.high);
    if (chart) { chart.destroy(); }
    if (!s.count) { return; }
    var flat = function (v) { return s.labels.map(function () { return v; }); };
    var sets = [{ label: 'متوسط الجهد', data: s.avg, spanGaps: true }, { label: 'أدنى جهد', data: s.min, fill: false, spanGaps: true }];
    if (warn !== null) { sets.push({ label: 'حد التحذير', data: flat(warn), dash: [6, 5], color: '#d97706' }); }
    if (crit !== null) { sets.push({ label: 'حد الخطر', data: flat(crit), dash: [6, 5], color: '#dc2626' }); }
    var lows = [s.low]; if (crit !== null) { lows.push(crit); }
    var highs = [s.high]; if (warn !== null) { highs.push(warn); }
    chart = SN.chart(canvas, { type: 'line', labels: s.labels, datasets: sets, fromZero: false, animate: false,
      yMin: Math.min.apply(null, lows) - 0.5, yMax: Math.max.apply(null, highs) + 0.5, xTicks: 8,
      yFormat: function (v) { return '\u2066' + (Math.round(v * 10) / 10) + ' V\u2069'; }, tooltip: function (v) { return '\u2066' + (Math.round(v * 100) / 100) + ' V\u2069'; } });
  }
  draw(<?= json_encode($series) ?>);
  var box = document.querySelector('[data-power-ranges]');
  box.addEventListener('click', function (e) {
    var a = e.target.closest('[data-range]');
    if (!a) { return; }
    e.preventDefault();
    box.querySelectorAll('[data-range]').forEach(function (x) { x.classList.toggle('is-active', x === a); });
    SN.api(box.getAttribute('data-url') + '?range=' + a.getAttribute('data-range')).then(function (s) {
      draw(s);
      history.replaceState(null, '', a.getAttribute('href'));
    }).catch(function () {});
  });
});
</script>
<?php endif; ?>
