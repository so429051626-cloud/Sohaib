<?php
/** @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var string $status @var array $counts */
use App\Platform\Support;

$v->title = 'تذاكر الدعم';
$tabs = ['open' => 'بانتظار الرد', 'answered' => 'تم الرد', 'closed' => 'مغلقة', 'all' => 'الكل'];
?>
<div class="panel">
  <div class="tabs">
    <?php foreach ($tabs as $k => $l): ?>
      <a class="tab<?= $status === $k ? ' is-active' : '' ?>" href="<?= e(url('/admin/tickets', ['status' => $k])) ?>"><?= e($l) ?><?php if ($k !== 'all'): ?><span class="tab-count"><?= num((int) ($counts[$k] ?? 0)) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= icon('message-circle') ?><div class="empty-title">لا توجد تذاكر</div></div><?php else: ?>
  <ul class="list">
    <?php foreach ($rows as $t): [$sl, $sc] = Support::STATUS[$t['status']]; ?>
    <li class="list-item">
      <span class="list-icon <?= (int) $t['admin_unread'] ? 'is-warning' : '' ?>"><?= icon('message-circle') ?></span>
      <div class="list-item-main">
        <a class="list-item-title" href="<?= e(url('/admin/tickets/' . $t['id'])) ?>"><?= e($t['subject']) ?></a>
        <div class="list-item-sub"><?= e($t['tenant_name']) ?> · <?= e(Support::CATEGORIES[$t['category']] ?? '') ?> · <?= e(time_ago($t['last_message_at'])) ?></div>
      </div>
      <div class="list-item-end"><span class="badge <?= $sc ?>"><?= $sl ?></span></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
