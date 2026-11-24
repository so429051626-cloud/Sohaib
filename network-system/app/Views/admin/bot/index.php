<?php
/**
 * لوحة المسؤول: صندوق محادثات بوت خدمة الزبائن.
 * @var App\Core\View $v
 * @var App\Core\Request $request
 * @var array $chats @var App\Core\Paginator $pager @var string $f @var string $channel @var string $kind @var string $q @var array $counts @var bool $enabled
 */
use App\Controllers\AdminBotController;
use App\Core\AdminAuth;
use App\Services\BotAgent;

$v->title = 'محادثات البوت';
if (AdminAuth::can('bot.manage')) {
    $v->actions = '<a class="btn" href="' . e(url('/admin/bot/settings')) . '">' . icon('settings') . 'إعدادات البوت</a>';
}
$channelIcons = ['whatsapp' => 'phone', 'web' => 'globe', 'test' => 'sparkles'];
$kindIcons = ['owner' => 'building', 'subscriber' => 'user-check', 'guest' => 'user'];
?>
<?php if (!$enabled): ?>
  <div class="alert alert-warning"><?= icon('triangle-alert') ?><span>البوت متوقف: لا يرد على الرسائل الجديدة. <?php if (AdminAuth::can('bot.manage')): ?><a href="<?= e(url('/admin/bot/settings')) ?>">تشغيله من الإعدادات</a><?php endif; ?></span></div>
<?php endif; ?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="f">العرض</label>
      <select class="input" id="f" name="f" onchange="this.form.submit()">
        <?php foreach (AdminBotController::FILTERS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= selected($f, $k) ?>><?= e($label) ?><?= isset($counts[$k]) && (int) $counts[$k] > 0 && in_array($k, ['waiting', 'network'], true) ? ' (' . num($counts[$k]) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="kind">المرسل</label>
      <select class="input" id="kind" name="kind" onchange="this.form.submit()">
        <option value="">الكل</option>
        <?php foreach (BotAgent::KINDS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= selected($kind, $k) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="channel">القناة</label>
      <select class="input" id="channel" name="channel" onchange="this.form.submit()">
        <option value="">كل القنوات</option>
        <?php foreach (BotAgent::CHANNELS as $k => $label): if ($k === 'test') { continue; } ?>
          <option value="<?= e($k) ?>"<?= selected($channel, $k) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="الاسم أو الرقم أو الشبكة أو نص الرسالة"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$chats): ?>
    <div class="empty"><?= icon('message-square') ?><div class="empty-title"><?= $f === 'waiting' ? 'لا توجد محادثات بانتظار الفريق' : 'لا توجد محادثات' ?></div></div>
  <?php else: ?>
  <div class="bot-list">
    <?php foreach ($chats as $c):
        $waitingAdmin = (int) $c['needs_human'] && $c['status'] === 'open' && $c['handler'] === 'admin'; ?>
      <a class="bot-row<?= $waitingAdmin ? ' is-waiting' : '' ?>" href="<?= e(url('/admin/bot/chats/' . $c['id'])) ?>">
        <span class="bot-row-icon is-<?= e($c['channel']) ?>" title="<?= e(BotAgent::CHANNELS[$c['channel']] ?? $c['channel']) ?>"><?= icon($channelIcons[$c['channel']] ?? 'message-circle') ?></span>
        <span class="bot-row-main">
          <span class="bot-row-title">
            <b><?= e($c['name'] ?: ($c['phone'] ?: ($c['channel'] === 'web' ? 'زائر الموقع' : $c['external_id']))) ?></b>
            <span class="badge badge-outline"><?= icon($kindIcons[$c['kind']] ?? 'user') ?><?= e(BotAgent::KINDS[$c['kind']] ?? $c['kind']) ?><?= $c['network'] ? ' · ' . e($c['network']) : '' ?></span>
            <?php if ($waitingAdmin): ?><span class="badge badge-danger badge-dot">بانتظار الفريق</span>
            <?php elseif ($c['status'] === 'open' && $c['handler'] === 'network' && $c['mode'] === 'human'): ?><span class="badge badge-warning">عند صاحب الشبكة</span>
            <?php elseif ($c['mode'] === 'human' && $c['status'] === 'open'): ?><span class="badge badge-info">مع الفريق</span>
            <?php elseif ($c['status'] === 'closed'): ?><span class="badge badge-outline">مغلقة</span><?php endif; ?>
          </span>
          <span class="bot-row-last"><?= e((string) $c['last_message']) ?></span>
        </span>
        <span class="bot-row-end">
          <span class="text-sm text-muted nowrap"><?= e(fmt_dt($c['last_at'])) ?></span>
          <?php if ((int) $c['unread'] > 0 && $c['handler'] === 'admin'): ?><span class="nav-badge is-danger"><?= (int) $c['unread'] > 99 ? '99+' : (int) $c['unread'] ?></span><?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
