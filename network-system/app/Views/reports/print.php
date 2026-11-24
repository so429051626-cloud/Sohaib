<?php
/** @var App\Core\View $v */
use App\Controllers\ReportController;

$v->title = $docTitle;
$cell = ReportController::cell(...);
$numeric = ReportController::NUMERIC;
$hasChart = !empty($report['chart']['labels']) && array_sum(array_map(static fn ($d) => array_sum(array_map('abs', $d['data'])), $report['chart']['datasets'])) > 0;
if ($hasChart) {
    $v->script('vendor/chart.umd.min.js');
}
?>
<section class="doc-stats">
  <?php foreach ($report['stats'] as $s): ?>
    <div class="doc-stat">
      <div class="doc-stat-label"><?= e($s['label']) ?></div>
      <div class="doc-stat-value"><?= e($s['value']) ?></div>
      <?php if ($s['meta'] !== ''): ?><div class="doc-stat-meta"><?= e($s['meta']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($hasChart): ?>
<section class="doc-section doc-chart-wrap">
  <div class="doc-chart"><canvas id="doc-chart"></canvas></div>
</section>
<?php endif; ?>

<?php foreach ($report['tables'] as $table): if (!$table['rows']) { continue; } ?>
<section class="doc-section<?= count($table['rows']) <= 24 ? ' is-keep' : '' ?>">
  <h2><?= e($table['title']) ?> <small><?= num(count($table['rows'])) ?></small></h2>
  <table class="doc-table">
    <thead><tr>
      <?php foreach ($table['columns'] as [$label, $kind]): ?><th<?= in_array($kind, $numeric, true) ? ' class="num"' : '' ?>><?= e($label) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($table['rows'] as $row): ?>
      <tr>
        <?php foreach ($table['columns'] as $i => [, $kind]): ?><td<?= in_array($kind, $numeric, true) ? ' class="num"' : '' ?>><?= $cell($row[$i] ?? null, $kind) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if (!empty($table['total'])): ?>
    <tfoot><tr>
      <?php foreach ($table['columns'] as $i => [, $kind]): ?><th<?= in_array($kind, $numeric, true) ? ' class="num"' : '' ?>><?= $i === 0 ? e((string) $table['total'][0]) : ($table['total'][$i] === null ? '' : $cell($table['total'][$i], $kind)) ?></th><?php endforeach; ?>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</section>
<?php endforeach; ?>

<?php if (!array_filter($report['tables'], static fn ($t) => $t['rows'])): ?>
<section class="doc-section"><div class="doc-empty">لا توجد بيانات في هذه الفترة.</div></section>
<?php endif; ?>

<?php if ($hasChart): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var cfg = <?= json_encode($report['chart'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var currency = <?= json_encode((string) \App\Core\Settings::get('general.currency', '₪'), JSON_UNESCAPED_UNICODE) ?>;
  var fmt = {
    money: function (v) { return SN.fmtNum(Math.round(v * 100) / 100) + (currency ? ' ' + currency : ''); },
    bytes: function (v) { return SN.fmtBytes(v); },
    num: function (v) { return SN.fmtNum(v); }
  }[cfg.format] || function (v) { return SN.fmtNum(v); };
  SN.chart(document.getElementById('doc-chart'), {
    type: cfg.type, labels: cfg.labels, datasets: cfg.datasets, xTicks: 16, animate: false,
    yFormat: function (v) { return fmt(v); }, tooltip: function (v) { return fmt(v); }
  });
});
</script>
<?php endif; ?>
