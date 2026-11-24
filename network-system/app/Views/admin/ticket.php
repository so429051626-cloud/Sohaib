<?php
/** @var App\Core\View $v @var array $t @var array $messages @var string $aiState */
use App\Platform\Support;
use App\Services\Ai;

$v->title = $t['subject'];
$v->breadcrumbs = [['تذاكر الدعم', '/admin/tickets'], ['#' . $t['id']]];
[$sl, $sc] = Support::STATUS[$t['status']];
$ai = $aiState === 'ok';
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('message-circle') ?><?= e(Support::CATEGORIES[$t['category']] ?? '') ?></h3><span class="badge <?= $sc ?>"><?= $sl ?></span></div>
    <div class="thread">
      <?php foreach ($messages as $m): ?>
      <div class="thread-msg is-<?= $m['sender'] === 'admin' ? 'me' : 'them' ?>">
        <div class="thread-meta"><b><?= e($m['sender_name'] ?? '') ?></b><span><?= e(fmt_dt($m['created_at'])) ?></span></div>
        <div class="thread-body"><?= nl2br(e($m['body'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="<?= e(url('/admin/tickets/' . $t['id'] . '/reply')) ?>" class="thread-form">
      <?= csrf_field() ?>
      <textarea class="input" name="body" id="reply-body" rows="5" maxlength="5000" required placeholder="اكتب الرد"><?= e(old('body')) ?></textarea>
      <?= error_html('body') ?>
      <div class="form-actions">
        <button class="btn btn-primary"><?= icon('send') ?>إرسال الرد</button>
        <label class="check"><input type="checkbox" name="close" value="1"><span>إغلاق التذكرة بعد الرد</span></label>
        <button type="button" class="btn" id="ai-suggest" data-url="<?= e(url('/admin/tickets/' . $t['id'] . '/suggest')) ?>"<?= $ai ? '' : ' disabled title="' . e(Ai::stateLabel($aiState)) . '"' ?>><?= icon('sparkles') ?><?= $ai ? 'اقتراح رد' : 'اقتراح رد · غير متاح' ?></button>
      </div>
    </form>
  </div>
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('building') ?>الحساب</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الشبكة</dt><dd><a href="<?= e(url('/admin/tenants/' . $t['tenant_id'])) ?>"><?= e($t['tenant_name']) ?></a></dd>
          <dt>الحساب</dt><dd class="mono">/<?= e($t['slug']) ?></dd>
          <dt>فتحها</dt><dd><?= e($t['opened_by'] ?? '—') ?></dd>
          <dt>الهاتف</dt><dd class="mono"><?= $t['phone'] ? ltr(e($t['phone'])) : '—' ?></dd>
          <dt>التاريخ</dt><dd><?= e(fmt_dt($t['created_at'])) ?></dd>
        </dl>
      </div>
    </div>
    <form method="post" action="<?= e(url('/admin/tickets/' . $t['id'] . '/state')) ?>" class="panel panel-body">
      <?= csrf_field() ?>
      <?php if ($t['status'] === 'closed'): ?>
        <input type="hidden" name="action" value="reopen"><button class="btn w-100"><?= icon('rotate-ccw') ?>إعادة فتح التذكرة</button>
      <?php else: ?>
        <input type="hidden" name="action" value="close"><button class="btn w-100"><?= icon('check') ?>إغلاق التذكرة</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('ai-suggest'), ta = document.getElementById('reply-body');
  if (!b || b.disabled) { return; }
  b.addEventListener('click', function () {
    b.classList.add('is-loading'); b.disabled = true;
    SN.api(b.getAttribute('data-url'), { method: 'POST' }).then(function (r) { ta.value = r.text; ta.focus(); })
      .catch(function () {}).then(function () { b.classList.remove('is-loading'); b.disabled = false; });
  });
})();
</script>
