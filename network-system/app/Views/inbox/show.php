<?php
/**
 * محادثة مشترك على واتساب المنصة: الرد، واستلامها من المساعد الآلي أو إعادتها له.
 * @var App\Core\View $v
 * @var array $chat @var array $messages @var array $subscribers
 */
use App\Services\SubscriberRules;

$who = $subscribers[0]['full_name'] ?? ($chat['name'] ?: $chat['phone']);
$v->title = $who;
$v->breadcrumbs = [['رسائل المشتركين', '/inbox'], [$who, null]];
$base = '/inbox/' . $chat['id'];
$human = $chat['mode'] === 'human';
?>
<div class="bot-thread-wrap">
  <?= $v->partial('bot-thread', ['chat' => $chat, 'messages' => $messages, 'base' => $base, 'viewer' => 'network']) ?>

  <aside class="panel bot-side">
    <div class="panel-body">
      <div class="bot-side-actions">
        <?php if ($human): ?>
          <form method="post" action="<?= e(url($base . '/mode')) ?>"><?= csrf_field() ?><input type="hidden" name="mode" value="bot"><button class="btn btn-block"><?= icon('bot') ?>إعادتها للمساعد الآلي</button></form>
        <?php else: ?>
          <form method="post" action="<?= e(url($base . '/mode')) ?>"><?= csrf_field() ?><input type="hidden" name="mode" value="human"><button class="btn btn-primary btn-block"><?= icon('user-check') ?>استلام المحادثة</button></form>
        <?php endif; ?>
        <?php if ($chat['status'] === 'open'): ?>
          <form method="post" action="<?= e(url($base . '/close')) ?>"><?= csrf_field() ?><button class="btn btn-block"><?= icon('circle-check') ?>إغلاق المحادثة</button></form>
        <?php endif; ?>
      </div>
      <dl class="bot-facts">
        <dt>واتساب</dt><dd><?= ltr(e((string) ($chat['phone'] ?: '+' . $chat['external_id']))) ?></dd>
        <?php if ($chat['name']): ?><dt>الاسم في واتساب</dt><dd><?= e($chat['name']) ?></dd><?php endif; ?>
        <dt>بدأت</dt><dd><?= e(fmt_dt($chat['created_at'])) ?></dd>
      </dl>
      <div class="form-section-title">المشترك</div>
      <?php foreach ($subscribers as $s): ?>
        <div class="bot-sub">
          <?php if (can('subscribers.view')): ?><a class="cell-title" href="<?= e(url('/subscribers/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a><?php else: ?><b><?= e($s['full_name']) ?></b><?php endif; ?>
          <div class="cell-sub"><?= e($s['plan']) ?> · <?= SubscriberRules::badge($s) ?></div>
          <div class="cell-sub">ينتهي: <?= e(fmt_dt($s['expires_at'])) ?><?= (float) $s['balance'] > 0 ? ' · مستحق ' . e(money($s['balance'])) : '' ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$subscribers): ?><div class="text-muted text-sm">لم يُعرف المشترك من رقمه.</div><?php endif; ?>
    </div>
  </aside>
</div>
