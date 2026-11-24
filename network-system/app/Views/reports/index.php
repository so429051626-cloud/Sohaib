<?php
/** @var App\Core\View $v */
use App\Controllers\ReportController;

$v->title = 'التقارير';
$v->script('vendor/chart.umd.min.js');
$query = ['type' => $type] + ($preset !== '' ? ['preset' => $preset] : ['from' => $from, 'to' => $to]);
$v->actions = '<a class="btn btn-primary" href="' . e(url('/reports/print', $query)) . '">' . icon('printer') . 'طباعة التقرير</a>';
$cell = ReportController::cell(...);
$numeric = ReportController::NUMERIC;
?>
<div class="panel mb-3">
  <nav class="tabs">
    <?php foreach ($types as $k => [$label, $ic]): ?>
      <a class="tab<?= $k === $type ? ' is-active' : '' ?>" href="<?= e(url('/reports', ['type' => $k] + ($preset !== '' ? ['preset' => $preset] : ['from' => $from, 'to' => $to]))) ?>"><?= icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="field">
      <label for="preset">الفترة</label>
      <select class="input" id="preset" name="preset" data-autosubmit>
        <?php foreach (ReportController::PRESETS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= selected($preset, $k) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
        <option value=""<?= selected($preset, '') ?>>فترة مخصصة</option>
      </select>
    </div>
    <div class="field"><label for="from">من</label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>" data-custom-range></div>
    <div class="field"><label for="to">إلى</label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>" data-custom-range></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>عرض</button></div>
  </form>
</div>

<div class="stats">
  <?php foreach ($report['stats'] as $s): ?>
    <div class="stat">
      <div class="stat-icon <?= e($s['tone']) ?>"><?= icon($s['icon']) ?></div>
      <div class="stat-body">
        <div class="stat-label"><?= e($s['label']) ?></div>
        <div class="stat-value"><?= e($s['value']) ?></div>
        <?php if ($s['meta'] !== ''): ?><div class="stat-meta"><?= e($s['meta']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($report['chart']['labels'])): ?>
<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('chart-column') ?><?= e($types[$type][0]) ?></h3><span class="mini-stat"><?= ltr(e($from)) ?> — <?= ltr(e($to)) ?></span></div>
  <div class="panel-body"><div class="chart-box is-lg"><canvas id="report-chart"></canvas></div></div>
</div>
<?php endif; ?>

<?php if (!empty($report['heatmap'])): $hm = $report['heatmap']; $max = max(1, (float) $hm['max']); ?>
<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('calendar-days') ?><?= e($hm['title']) ?></h3><span class="heat-legend"><span>أقل</span><i></i><span>أكثر</span></span></div>
  <div class="panel-body heat-wrap">
    <div class="heat" role="table" aria-label="<?= e($hm['title']) ?>">
      <div class="heat-row heat-hours" role="row"><span class="heat-day" role="columnheader"></span><?php foreach (range(0, 23) as $h): ?><span class="heat-h" role="columnheader"><?= $h % 3 === 0 ? ltr(str_pad((string) $h, 2, '0', STR_PAD_LEFT)) : '' ?></span><?php endforeach; ?></div>
      <?php foreach ($hm['rows'] as $row): ?>
      <div class="heat-row" role="row"><span class="heat-day" role="rowheader"><?= e($row['label']) ?></span>
        <?php foreach ($row['values'] as $h => $val): ?><span class="heat-cell<?= $val === null ? ' is-empty' : '' ?>" role="cell" style="--a:<?= $val === null ? 0 : round(0.08 + 0.92 * $val / $max, 3) ?>" title="<?= e($row['label'] . ' ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00 · ' . ($val === null ? 'لا قياسات' : num($val, 1) . ' متصل')) ?>"></span><?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="stack">
  <?php foreach ($report['tables'] as $ti => $table): ?>
    <div class="panel">
      <div class="panel-head">
        <h3><?= icon('file-text') ?><?= e($table['title']) ?></h3>
        <div class="panel-tools">
          <span class="badge"><?= num(count($table['rows'])) ?></span>
          <?php if ($table['rows']): ?>
          <div class="dropdown">
            <button type="button" class="btn btn-sm" data-dropdown><?= icon('download') ?>تصدير</button>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="<?= e(url('/reports/export', $query + ['t' => $ti, 'format' => 'xlsx'])) ?>"><?= icon('file-spreadsheet') ?>Excel</a>
              <a class="dropdown-item" href="<?= e(url('/reports/export', $query + ['t' => $ti, 'format' => 'csv'])) ?>"><?= icon('file-text') ?>CSV</a>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$table['rows']): ?>
        <div class="empty"><?= icon('file-text') ?><div>لا توجد بيانات في هذه الفترة.</div></div>
      <?php else: ?>
      <div class="table-wrap" style="max-height:560px">
        <table class="table table-compact">
          <thead><tr>
            <?php foreach ($table['columns'] as [$label, $kind]): ?><th<?= in_array($kind, $numeric, true) ? ' class="num"' : '' ?>><?= e($label) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($table['rows'] as $row): ?>
            <tr>
              <?php foreach ($table['columns'] as $i => [, $kind]): ?>
                <td class="<?= in_array($kind, $numeric, true) ? 'num ' : '' ?>nowrap"><?= $cell($row[$i] ?? null, $kind) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php if (!empty($table['total'])): ?>
          <tfoot><tr>
            <?php foreach ($table['columns'] as $i => [, $kind]): ?>
              <th class="<?= in_array($kind, $numeric, true) ? 'num ' : '' ?>nowrap"><?= $i === 0 ? e((string) $table['total'][0]) : $cell($table['total'][$i] ?? null, $kind) ?></th>
            <?php endforeach; ?>
          </tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<script>
// تعديل التاريخ يدوياً يحوّل الفترة إلى مخصصة
document.querySelectorAll('[data-custom-range]').forEach(function (input) {
  input.addEventListener('change', function () { document.getElementById('preset').value = ''; });
});
</script>
<?php if (!empty($report['chart']['labels'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var cfg = <?= json_encode($report['chart'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var currency = <?= json_encode((string) \App\Core\Settings::get('general.currency', '₪'), JSON_UNESCAPED_UNICODE) ?>;
  var fmt = {
    money: function (v) { return SN.fmtNum(Math.round(v * 100) / 100) + (currency ? ' ' + currency : ''); },
    bytes: function (v) { return SN.fmtBytes(v); },
    num: function (v) { return SN.fmtNum(v); }
  }[cfg.format] || function (v) { return SN.fmtNum(v); };
  SN.chart(document.getElementById('report-chart'), {
    type: cfg.type, labels: cfg.labels, datasets: cfg.datasets, xTicks: 12,
    yFormat: function (v) { return fmt(v); }, tooltip: function (v) { return fmt(v); }
  });
});
</script>
<?php endif; ?>
