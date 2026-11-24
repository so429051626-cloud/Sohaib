<?php
/** @var App\Core\View $v */
use App\Services\JobRunner;

$v->title = $job['title'];
$v->breadcrumbs = [['العمليات الجارية', can('jobs.view') ? '/jobs' : null], ['#' . $job['id'], null]];
[$label, $cls] = JobRunner::STATUS[$job['status']] ?? [$job['status'], 'badge'];
$active = in_array($job['status'], ['pending', 'running'], true);
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head">
      <h2><?= icon('hourglass') ?>التقدم</h2>
      <span class="badge <?= $cls ?>" id="job-status"><?= e($label) ?></span>
    </div>
    <div class="panel-body">
      <div class="meter-row"><span id="job-count"><?= num($job['progress']) ?> / <?= num($job['total']) ?></span><strong id="job-pct">0%</strong></div>
      <div class="meter meter-lg mb-3"><span id="job-bar" style="width:0%"></span></div>
      <div class="notice<?= $job['status'] === 'failed' ? ' notice-danger' : ($job['status'] === 'done' ? ' notice-success' : '') ?>" id="job-msg"<?= $job['message'] ? '' : ' hidden' ?>><?= icon('info') ?><div><?= e($job['message'] ?? '') ?></div></div>
      <h3 class="mt-3 mb-1">السجل</h3>
      <pre class="code-block" id="job-log" style="max-height:340px;direction:rtl;text-align:right"><?= e(implode("\n", $state['log'] ?? [])) ?: '—' ?></pre>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('info') ?>التفاصيل</h3></div>
    <div class="panel-body">
      <dl class="kv">
        <dt>النوع</dt><dd><?= e(JobRunner::TYPES[$job['type']] ?? $job['type']) ?></dd>
        <dt>بواسطة</dt><dd><?= e($job['employee_name'] ?? 'النظام') ?></dd>
        <dt>البداية</dt><dd><?= e(fmt_dt($job['created_at'], true)) ?></dd>
        <dt>الأخطاء</dt><dd id="job-errors"><?= num($job['errors']) ?></dd>
      </dl>
      <?php if ($active): ?>
      <form method="post" action="<?= e(url('/jobs/' . $job['id'] . '/cancel')) ?>" class="mt-3" data-confirm="إلغاء هذه العملية؟ ما نُفّذ منها يبقى كما هو." id="cancel-form">
        <?= csrf_field() ?><button class="btn btn-soft-danger btn-block"><?= icon('square') ?>إلغاء العملية</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var running = <?= $active ? 'true' : 'false' ?>;
  function render(d) {
    var pct = d.total > 0 ? Math.round(d.progress / d.total * 100) : (d.status === 'done' ? 100 : 0);
    document.getElementById('job-bar').style.width = pct + '%';
    document.getElementById('job-pct').textContent = pct + '%';
    document.getElementById('job-count').textContent = SN.fmtNum(d.progress) + ' / ' + SN.fmtNum(d.total);
    document.getElementById('job-errors').textContent = SN.fmtNum(d.errors);
    var st = document.getElementById('job-status');
    st.textContent = d.label;
    st.className = 'badge ' + ({ done: 'badge-success', failed: 'badge-danger', cancelled: 'badge-outline', running: 'badge-primary', pending: 'badge-info' }[d.status] || 'badge');
    if (d.log && d.log.length) { document.getElementById('job-log').textContent = d.log.join('\n'); }
    var msg = document.getElementById('job-msg');
    if (d.message) {
      msg.hidden = false;
      msg.className = 'notice' + (d.status === 'failed' ? ' notice-danger' : (d.status === 'done' ? ' notice-success' : ''));
      msg.querySelector('div').textContent = d.message;
    }
  }
  function step() {
    SN.api(SN.base + '/jobs/<?= (int) $job['id'] ?>/step', { method: 'POST', silent: true }).then(function (d) {
      render(d);
      if (d.status === 'pending' || d.status === 'running') { setTimeout(step, 600); }
      else { var c = document.getElementById('cancel-form'); if (c) { c.remove(); } }
    }).catch(function () { setTimeout(step, 4000); });
  }
  document.addEventListener('DOMContentLoaded', function () {
    render({ progress: <?= (int) $job['progress'] ?>, total: <?= (int) $job['total'] ?>, errors: <?= (int) $job['errors'] ?>, status: '<?= e($job['status']) ?>', label: '<?= e($label) ?>', message: null });
    if (running) { step(); }
  });
})();
</script>
