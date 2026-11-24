<?php
/** @var App\Core\View $v @var string $state @var array $samples @var int $used @var int $limit */
use App\Services\Ai;

$v->title = 'المساعد الذكي';
$v->script('js/assistant.js');
$ok = $state === 'ok';
$pct = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
?>
<div class="panel chat chat-tall" data-chat data-url="<?= e(url('/assistant')) ?>" data-confirm-url="<?= e(url('/assistant/confirm')) ?>" data-store="mnx-assistant-<?= (int) App\Core\Auth::id() ?>"<?= $ok ? '' : ' data-disabled' ?>>
  <div class="panel-head">
    <h3><?= icon('sparkles') ?>المساعد الذكي</h3>
    <div class="chat-head-end">
      <span class="chat-usage" title="رسائل هذا الشهر" data-chat-usage><span class="meter<?= $pct >= 90 ? ' is-danger' : ($pct >= 70 ? ' is-warning' : '') ?>"><span style="width:<?= $pct ?>%" data-chat-usage-bar></span></span><b data-chat-usage-text><?= ltr(num($used) . ' / ' . num($limit)) ?></b></span>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-chat-clear title="محادثة جديدة" aria-label="محادثة جديدة" hidden><?= icon('rotate-ccw') ?></button>
    </div>
  </div>
  <div class="chat-log" data-chat-log aria-live="polite">
    <div class="chat-empty" data-chat-empty>
      <span class="chat-empty-icon"><?= icon('sparkles') ?></span>
      <?php if ($ok): ?>
      <div class="chat-samples"><?php foreach ($samples as $q): ?><button type="button" class="chip chip-btn" data-chat-sample><?= e($q) ?></button><?php endforeach; ?></div>
      <?php else: ?>
      <div class="empty-title">غير متاح</div><div class="text-muted text-sm"><?= e(Ai::stateLabel($state)) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <form class="chat-form" data-chat-form>
    <textarea class="input" name="q" rows="1" maxlength="2000" placeholder="اكتب سؤالك" aria-label="سؤالك"<?= $ok ? '' : ' disabled' ?>></textarea>
    <button class="btn btn-primary btn-icon" aria-label="إرسال"<?= $ok ? '' : ' disabled' ?>><?= icon('send') ?></button>
  </form>
</div>
