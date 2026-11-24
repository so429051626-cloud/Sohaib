<?php
/**
 * لوحة المسؤول: محادثة واحدة مع البوت، والرد، واستلامها أو إعادتها للبوت أو تحويلها لصاحب الشبكة.
 * @var App\Core\View $v
 * @var array $chat @var array $messages @var ?array $network @var array $owned @var array $subscribedIn
 */
use App\Services\BotAgent;

$who = $chat['name'] ?: ($chat['phone'] ?: ($chat['channel'] === 'web' ? 'زائر الموقع' : $chat['external_id']));
$v->title = $who;
$v->breadcrumbs = [['محادثات البوت', '/admin/bot'], [$who, null]];
$base = '/admin/bot/chats/' . $chat['id'];
$human = $chat['mode'] === 'human';
$post = static fn (string $path, array $fields, string $label, string $icon, string $cls = 'btn btn-block'): string =>
    '<form method="post" action="' . e(url($path)) . '">' . csrf_field()
    . implode('', array_map(static fn ($k, $val) => '<input type="hidden" name="' . e($k) . '" value="' . e($val) . '">', array_keys($fields), $fields))
    . '<button class="' . $cls . '">' . icon($icon) . e($label) . '</button></form>';
?>
<div class="bot-thread-wrap">
  <?= $v->partial('bot-thread', ['chat' => $chat, 'messages' => $messages, 'base' => $base, 'viewer' => 'admin']) ?>

  <aside class="panel bot-side">
    <div class="panel-body">
      <div class="bot-side-actions">
        <?= $human ? $post($base . '/mode', ['mode' => 'bot'], 'إعادتها للمساعد الآلي', 'bot') : $post($base . '/mode', ['mode' => 'human'], 'استلام المحادثة', 'user-check', 'btn btn-primary btn-block') ?>
        <?php if ($chat['tenant_id'] && $chat['channel'] !== 'test'): ?>
          <?= $chat['handler'] === 'network' && $human
              ? $post($base . '/handler', ['handler' => 'admin'], 'إعادتها لفريق المنصة', 'undo-2')
              : $post($base . '/handler', ['handler' => 'network'], 'تحويلها لصاحب الشبكة', 'forward') ?>
        <?php endif; ?>
        <?= $chat['status'] === 'open' ? $post($base . '/close', [], 'إغلاق المحادثة', 'circle-check') : $post($base . '/close', ['status' => 'open'], 'إعادة فتحها', 'rotate-ccw') ?>
      </div>
      <?= error_html('handler') ?>

      <dl class="bot-facts">
        <dt>القناة</dt><dd><?= e(BotAgent::CHANNELS[$chat['channel']] ?? $chat['channel']) ?></dd>
        <?php if ($chat['phone']): ?><dt>الرقم</dt><dd><?= ltr(e($chat['phone'])) ?></dd><?php endif; ?>
        <dt>المرسل</dt><dd><?= e(BotAgent::KINDS[$chat['kind']] ?? $chat['kind']) ?></dd>
        <?php if ($network): ?><dt>الشبكة المعنية</dt><dd><a href="<?= e(url('/admin/tenants/' . $network['id'])) ?>"><?= e($network['name']) ?></a> <span class="mono text-muted">/<?= e($network['slug']) ?></span></dd><?php endif; ?>
        <dt>يرد عليها</dt><dd><?= $chat['handler'] === 'network' ? 'صاحب الشبكة' : 'فريق المنصة' ?><?= $chat['assigned_to'] ? ' · ' . e($chat['assigned_to']) : '' ?></dd>
        <dt>بدأت</dt><dd><?= e(fmt_dt($chat['created_at'])) ?></dd>
      </dl>

      <?php if ($chat['channel'] === 'whatsapp'): ?>
      <div class="form-section-title">هوية الرقم</div>
      <?php if ($owned): ?>
        <div class="text-sm">صاحب: <?php foreach ($owned as $i => $t): ?><?= $i ? '، ' : '' ?><a href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($subscribedIn): ?>
        <div class="text-sm">مشترك في: <?php foreach ($subscribedIn as $i => $t): ?><?= $i ? '، ' : '' ?><a href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><?php endforeach; ?></div>
      <?php endif; ?>
      <?php if (!$owned && !$subscribedIn): ?><div class="text-muted text-sm">غير مسجّل لصاحب شبكة ولا لمشترك.</div><?php endif; ?>
      <div class="bot-side-actions" style="margin-top:.75rem"><?= $post($base . '/identify', [], 'إعادة التعرّف على الرقم', 'refresh-cw', 'btn btn-sm btn-block') ?></div>
      <?php endif; ?>
    </div>
  </aside>
</div>
