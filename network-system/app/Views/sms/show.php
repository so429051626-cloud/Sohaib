<?php
/** @var App\Core\View $v @var array $msg @var array $recipients @var array $requests @var string $status */
$v->title = 'رسالة #' . $msg['id'];
$v->breadcrumbs = [['سجل الرسائل', '/sms/log'], [$v->title, null]];
$states = ['queued' => ['في الانتظار', 'badge-outline'], 'sending' => ['جارٍ الإرسال', 'badge-info'], 'done' => ['تم', 'badge-success'], 'failed' => ['فشل', 'badge-danger']];
$rs = ['pending' => ['بانتظار الإرسال', 'badge-outline'], 'sent' => ['أُرسلت', 'badge-success'], 'failed' => ['لم تُرسل', 'badge-danger']];
[$sl, $sc] = $states[$msg['status']];
if ((int) $msg['failed'] > 0 && can('sms.send') && in_array($msg['status'], ['done', 'failed'], true)) {
    $v->actions = '<form method="post" action="' . e(url('/sms/log/' . $msg['id'] . '/retry')) . '" data-confirm="إعادة الإرسال إلى ' . num((int) $msg['failed']) . ' رقم؟" data-confirm-type="primary">' . csrf_field()
        . '<button class="btn">' . icon('refresh-cw') . 'إعادة إرسال غير المرسلة</button></form>';
}
$live = in_array($msg['status'], ['queued', 'sending'], true);
?>
<div class="stats"<?= $live ? ' data-sms-live="' . e(url('/sms/log/' . $msg['id'])) . '"' : '' ?>>
  <div class="stat"><div class="stat-icon"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">المستلمون</div><div class="stat-value"><?= num((int) $msg['recipients']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">أُرسلت</div><div class="stat-value" data-live-sent><?= num((int) $msg['sent']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('circle-x') ?></div><div class="stat-body"><div class="stat-label">لم تُرسل</div><div class="stat-value" data-live-failed><?= num((int) $msg['failed']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">الحالة</div><div class="stat-value" style="font-size:18px"><span class="badge <?= $sc ?>" data-live-status><?= $sl ?></span></div><?php if ($msg['error']): ?><div class="stat-meta text-danger"><?= e($msg['error']) ?></div><?php endif; ?></div></div>
</div>
<div class="grid grid-main-side">
  <div class="panel">
    <nav class="tabs">
      <a class="tab<?= $status === '' ? ' is-active' : '' ?>" href="<?= e(url('/sms/log/' . $msg['id'])) ?>">الكل</a>
      <?php foreach ($rs as $k => [$l]): ?><a class="tab<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(url('/sms/log/' . $msg['id'], ['status' => $k])) ?>"><?= $l ?></a><?php endforeach; ?>
    </nav>
    <?php if (!$recipients): ?><div class="empty"><?= icon('users') ?><div>لا يوجد.</div></div><?php else: ?>
    <div class="table-wrap" style="max-height:640px">
      <table class="table table-compact">
        <thead><tr><th>المستلم</th><th>الرقم</th><th>الحالة</th><th>الرد</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $r): [$rl, $rc] = $rs[$r['status']]; ?>
          <tr>
            <td class="text-sm"><?= $r['subscriber_id'] ? '<a href="' . e(url('/subscribers/' . $r['subscriber_id'])) . '">' . e($r['name'] ?: '—') . '</a>' : e($r['name'] ?: '—') ?></td>
            <td class="mono text-sm"><?= e($r['phone']) ?></td>
            <td><span class="badge <?= $rc ?>"><?= $rl ?></span></td>
            <td class="text-sm"><?= e($r['description'] ?? '') ?><?= $r['code'] !== null && (int) $r['code'] !== 999 ? ' <span class="mono text-muted">(' . (int) $r['code'] . ')</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('message-circle') ?>النص</h3><span class="badge"><?= num((int) $msg['segments']) ?> مقطع</span></div>
      <div class="panel-body"><div class="sms-bubble"><?= nl2br(e($msg['body'])) ?></div>
        <dl class="kv mt-3">
          <dt>المستلمون</dt><dd><?= e($msg['audience'] ?: '—') ?></dd>
          <dt>بواسطة</dt><dd><?= e($msg['employee_name'] ?? ($msg['kind'] === 'auto' ? 'النظام' : '—')) ?></dd>
          <dt>التاريخ</dt><dd><?= e(fmt_dt($msg['created_at'])) ?></dd>
          <?php if ($msg['scheduled_at']): ?><dt>موعد الإرسال</dt><dd><?= e(fmt_dt($msg['scheduled_at'])) ?></dd><?php endif; ?>
          <?php if ($msg['finished_at']): ?><dt>اكتمل</dt><dd><?= e(fmt_dt($msg['finished_at'])) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if ($requests && can('sms.manage')): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>طلبات البوابة</h3></div>
      <ul class="list">
        <?php foreach ($requests as $q): ?>
          <li class="list-item"><span class="list-icon <?= (int) $q['ok'] ? 'is-success' : 'is-danger' ?>"><?= icon((int) $q['ok'] ? 'circle-check' : 'circle-x') ?></span>
            <div class="list-item-main"><div class="list-item-title text-sm"><?= e($q['description']) ?></div><div class="list-item-sub"><?= num((int) $q['numbers']) ?> رقم<?= $q['code'] !== null ? ' · <span class="mono">' . (int) $q['code'] . '</span>' : '' ?></div></div>
            <div class="list-item-end text-sm"><?= e(date('H:i:s', strtotime($q['created_at']))) ?></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($live): ?>
<script>
(function () {
  var box = document.querySelector('[data-sms-live]');
  var url = box.getAttribute('data-sms-live');
  var tick = function () {
    SN.api(url, { silent: true }).then(function (d) {
      box.querySelector('[data-live-sent]').textContent = SN.fmtNum(d.sent);
      box.querySelector('[data-live-failed]').textContent = SN.fmtNum(d.failed);
      if (d.status === 'done' || d.status === 'failed') { window.location.reload(); } else { setTimeout(tick, 2000); }
    }).catch(function () { setTimeout(tick, 4000); });
  };
  setTimeout(tick, 1500);
})();
</script>
<?php endif; ?>
