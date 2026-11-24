<?php
/**
 * رسائل المشتركين على واتساب المنصة.
 * @var App\Core\View $v
 * @var App\Core\Request $request
 * @var array $chats @var array $names @var App\Core\Paginator $pager @var string $f @var array $filters @var int $waiting
 */
$v->title = 'رسائل المشتركين';
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="f">العرض</label>
      <select class="input" id="f" name="f" onchange="this.form.submit()">
        <?php foreach ($filters as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= selected($f, $k) ?>><?= e($label) ?><?= $k === 'waiting' && $waiting > 0 ? ' (' . num($waiting) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <?php if (!$chats): ?>
    <div class="empty"><?= icon('message-square') ?><div class="empty-title"><?= $f === 'waiting' ? 'لا توجد رسائل بانتظار ردك' : 'لا توجد رسائل' ?></div></div>
  <?php else: ?>
  <div class="bot-list">
    <?php foreach ($chats as $c):
        $mine = (int) $c['needs_human'] && $c['status'] === 'open' && $c['handler'] === 'network'; ?>
      <a class="bot-row<?= $mine ? ' is-waiting' : '' ?>" href="<?= e(url('/inbox/' . $c['id'])) ?>">
        <span class="bot-row-icon is-whatsapp"><?= icon('phone') ?></span>
        <span class="bot-row-main">
          <span class="bot-row-title">
            <b><?= e($names[(int) $c['subscriber_id']] ?? ($c['name'] ?: $c['phone'])) ?></b>
            <?php if ($mine): ?><span class="badge badge-danger badge-dot">بانتظار ردك</span>
            <?php elseif ($c['mode'] === 'human' && $c['status'] === 'open'): ?><span class="badge badge-info"><?= $c['handler'] === 'network' ? 'معك' : 'مع فريق المنصة' ?></span>
            <?php elseif ($c['status'] === 'closed'): ?><span class="badge badge-outline">مغلقة</span>
            <?php else: ?><span class="badge badge-primary">مع المساعد الآلي</span><?php endif; ?>
          </span>
          <span class="bot-row-last"><?= e((string) $c['last_message']) ?></span>
        </span>
        <span class="bot-row-end">
          <span class="text-sm text-muted nowrap"><?= e(fmt_dt($c['last_at'])) ?></span>
          <?php if ((int) $c['unread'] > 0 && $c['handler'] === 'network'): ?><span class="nav-badge is-danger"><?= (int) $c['unread'] > 99 ? '99+' : (int) $c['unread'] ?></span><?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
