<?php
/** @var App\Core\View $v @var array $routers @var array $counts */
use App\Services\PowerMonitor;

$v->title = 'مراقبة الطاقة';
$volt = static fn ($x) => $x === null || $x === '' ? '—' : ltr(rtrim(rtrim(number_format((float) $x, 1, '.', ''), '0'), '.') . ' V');
/** خط صغير لجهد آخر 24 ساعة */
$spark = static function (array $values): string {
    if (count($values) < 2) {
        return '';
    }
    $min = min($values);
    $max = max($values);
    $span = max(0.2, $max - $min);
    $n = count($values) - 1;
    $pts = [];
    foreach ($values as $i => $val) {
        $pts[] = round($i / $n * 120, 1) . ',' . round(30 - (($val - $min) / $span) * 26 - 2, 1);
    }
    return '<svg class="spark" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/></svg>';
};
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('zap') ?></div><div class="stat-body"><div class="stat-label">جهد طبيعي</div><div class="stat-value"><?= num($counts['ok']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('triangle-alert') ?></div><div class="stat-body"><div class="stat-label">تحذير</div><div class="stat-value"><?= num($counts['warn']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('flame') ?></div><div class="stat-body"><div class="stat-label">خطر</div><div class="stat-value"><?= num($counts['crit']) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('circle-help') ?></div><div class="stat-body"><div class="stat-label">غير مدعوم</div><div class="stat-value"><?= num($counts['unsupported']) ?></div></div></div>
</div>

<?php if (!$routers): ?>
<div class="panel"><div class="empty"><?= icon('zap') ?><div class="empty-title">لا توجد أجهزة MikroTik</div></div></div>
<?php else: ?>
<div class="power-grid">
  <?php foreach ($routers as $r): [$sl, $sc] = PowerMonitor::STATES[$r['p_state']]; $st = $r['p_stats']; ?>
  <a class="panel power-card is-<?= e($r['p_state']) ?>" href="<?= e(url('/power/' . $r['id'])) ?>">
    <div class="power-card-head">
      <div class="power-card-name"><b><?= e($r['name']) ?></b><?php if ($r['site_name']): ?><span><?= e($r['site_name']) ?></span><?php endif; ?></div>
      <span class="badge <?= $sc ?>"><?= $sl ?></span>
    </div>
    <div class="power-card-main">
      <div class="power-volt"><?= $r['p_state'] === 'unsupported' ? '—' : $volt($r['voltage']) ?></div>
      <div class="power-spark"><?= $spark($r['p_spark']) ?></div>
    </div>
    <dl class="power-card-facts">
      <div><dt>أدنى 24 ساعة</dt><dd><?= $volt($st['mn'] ?? null) ?></dd></div>
      <div><dt>أعلى 24 ساعة</dt><dd><?= $volt($st['mx'] ?? null) ?></dd></div>
      <div><dt>حد التحذير</dt><dd><?= $volt($r['voltage_warn']) ?></dd></div>
      <div><dt>حد الخطر</dt><dd><?= $volt($r['voltage_crit']) ?></dd></div>
    </dl>
    <?php if ($r['p_eta'] !== null && $r['p_eta'] <= 6 * 3600): ?>
    <div class="power-eta"><?= icon('timer', 'icon-sm') ?>متوقع أن تفصل خلال نحو <?= e(PowerMonitor::etaLabel((int) $r['p_eta'])) ?></div>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
