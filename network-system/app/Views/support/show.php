<?php
/** @var App\Core\View $v @var array $t @var array $messages */
use App\Platform\Support;

$v->title = $t['subject'];
$v->breadcrumbs = [['الدعم', '/support'], ['#' . $t['id']]];
[$sl, $sc] = Support::STATUS[$t['status']];
?>
<div class="panel">
  <div class="panel-head"><h3><?= icon('message-circle') ?><?= e(Support::CATEGORIES[$t['category']] ?? '') ?></h3><span class="badge <?= $sc ?>"><?= $sl ?></span></div>
  <div class="thread">
    <?php foreach ($messages as $m): ?>
    <div class="thread-msg is-<?= $m['sender'] === 'tenant' ? 'me' : 'them' ?>">
      <div class="thread-meta"><b><?= e($m['sender'] === 'admin' ? 'الدعم' : ($m['sender_name'] ?? '')) ?></b><span><?= e(fmt_dt($m['created_at'])) ?></span></div>
      <div class="thread-body"><?= nl2br(e($m['body'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($t['status'] !== 'closed'): ?>
  <form method="post" action="<?= e(url('/support/' . $t['id'] . '/reply')) ?>" class="thread-form">
    <?= csrf_field() ?>
    <textarea class="input" name="body" rows="4" maxlength="5000" required placeholder="اكتب ردك"><?= e(old('body')) ?></textarea>
    <?= error_html('body') ?>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('send') ?>إرسال</button>
      <button class="btn" formaction="<?= e(url('/support/' . $t['id'] . '/close')) ?>" formnovalidate data-confirm="إغلاق التذكرة؟" data-confirm-type="primary"><?= icon('check') ?>إغلاق التذكرة</button></div>
  </form>
  <?php endif; ?>
</div>
