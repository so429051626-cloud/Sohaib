<?php
/**
 * محادثة بوت خدمة الزبائن: الرسائل والتحديث التلقائي والرد. مشتركة بين صندوق المسؤول وصندوق صاحب الشبكة.
 * @var App\Core\View $v
 * @var array $chat @var array $messages
 * @var string $base مسار المحادثة (/admin/bot/chats/5 أو /inbox/5)
 * @var string $viewer admin | network
 */
use App\Services\BotAgent;

$v->script('js/bot.js');
$lastId = $messages ? (int) end($messages)['id'] : 0;
$human = $chat['mode'] === 'human';
$waOld = $chat['channel'] === 'whatsapp' && $chat['last_inbound_at'] && strtotime((string) $chat['last_inbound_at']) < time() - 86400;
$userLabel = $viewer === 'network' ? 'المشترك' : (BotAgent::KINDS[$chat['kind']] ?? 'المرسل');
// صاحب الشبكة يرى ردود المنصة باسم «فريق المنصة» فقط، بلا اسم المسؤول
$staffLabel = static fn (array $m): string => (($m['side'] ?? '') === 'admin' && $viewer === 'network' ? '' : trim((string) ($m['sender'] ?? '')) . ' · ') . (($m['side'] ?? '') === 'network' ? 'صاحب الشبكة' : 'فريق المنصة');
$label = static fn (array $m): string => match ($m['role']) {
    'user' => $userLabel,
    'bot' => 'المساعد الآلي',
    'staff' => $staffLabel($m),
    default => 'ملاحظة',
};
?>
<div class="panel chat bot-thread" data-bot-thread data-poll="<?= e(url($base . '/poll')) ?>" data-reply="<?= e(url($base . '/reply')) ?>" data-last="<?= $lastId ?>" data-user-label="<?= e($userLabel) ?>">
  <div class="panel-head">
    <h3><?= icon('message-square') ?><?= e(BotAgent::CHANNELS[$chat['channel']] ?? $chat['channel']) ?></h3>
    <div class="chat-head-end">
      <span class="badge <?= $human ? 'badge-info' : 'badge-primary' ?>" data-bot-mode><?= $human ? 'مع شخص' : 'مع المساعد الآلي' ?></span>
      <span class="badge badge-danger badge-dot" data-bot-waiting<?= (int) $chat['needs_human'] && $chat['status'] === 'open' ? '' : ' hidden' ?>>بانتظار رد</span>
    </div>
  </div>
  <div class="chat-log" data-bot-log aria-live="polite">
    <?php foreach ($messages as $m): ?>
      <?php if ($m['role'] === 'note'): ?>
        <div class="bot-note" data-id="<?= (int) $m['id'] ?>"><?= icon('info') ?><span><?= e($m['body']) ?></span><small><?= $viewer === 'admin' && $m['sender'] ? e($m['sender']) . ' · ' : '' ?><?= e(fmt_dt($m['created_at'])) ?></small></div>
      <?php else: ?>
        <div class="chat-msg <?= $m['role'] === 'user' ? 'is-user' : 'is-assistant' ?><?= $m['role'] === 'staff' ? ' is-staff' : '' ?>" data-id="<?= (int) $m['id'] ?>"><span class="bot-msg-meta"><?= e($label($m)) ?> · <?= e(fmt_dt($m['created_at'])) ?><?php if ($viewer === 'admin' && $m['tools']): ?> · <span class="mono" title="الأدوات التي استعملها المساعد"><?= e($m['tools']) ?></span><?php endif; ?></span><?= e($m['body']) ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($chat['channel'] === 'test'): ?>
    <div class="bot-thread-note"><?= icon('info') ?>محادثة تجربة من صفحة الإعدادات.</div>
  <?php else: ?>
  <?php if ($waOld): ?><div class="bot-thread-note is-warning"><?= icon('triangle-alert') ?>مرّ أكثر من 24 ساعة على آخر رسالة منه؛ واتساب لا يسمح بالرد الحر حتى يراسلنا من جديد.</div><?php endif; ?>
  <form class="chat-form" method="post" action="<?= e(url($base . '/reply')) ?>" data-bot-reply>
    <?= csrf_field() ?>
    <textarea class="input" name="body" rows="1" maxlength="4000" placeholder="اكتب ردك" aria-label="ردك" required></textarea>
    <button class="btn btn-primary btn-icon" aria-label="إرسال"><?= icon('send') ?></button>
  </form>
  <?= error_html('body') ?>
  <?php endif; ?>
</div>
