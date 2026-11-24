<?php
/** @var App\Core\View $v */
use App\Core\Request;
use App\Services\Notifier;

$v->title = 'التنبيهات';
$v->subtitle = $unread > 0 ? num($unread) . ' تنبيه غير مقروء' : 'لا توجد تنبيهات جديدة';
$v->actions = '<form method="post" action="' . e(url('/alerts/read-all')) . '">' . csrf_field()
    . '<button class="btn">' . icon('check') . 'تعليم الكل كمقروء</button></form>'
    . '<form method="post" action="' . e(url('/alerts/clear')) . '" data-confirm="حذف كل التنبيهات المقروءة؟">' . csrf_field()
    . '<button class="btn btn-soft-danger">' . icon('trash-2') . 'حذف المقروءة</button></form>';
$req = Request::current();
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field">
      <label for="type">النوع</label>
      <select class="input" id="type" name="type" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach (Notifier::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($req->str('type'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="state">الحالة</label>
      <select class="input" id="state" name="state" data-autosubmit>
        <option value="">الكل</option>
        <option value="unread"<?= selected($req->str('state'), 'unread') ?>>غير المقروءة</option>
      </select>
    </div>
  </form>
  <ul class="list alert-list">
    <?php foreach ($rows as $a): ?>
    <li class="list-item" style="<?= $a['is_read'] ? '' : 'background:var(--primary-soft)' ?>">
      <span class="list-icon is-<?= e($a['level'] === 'danger' ? 'danger' : ($a['level'] === 'warning' ? 'warning' : ($a['level'] === 'success' ? 'success' : 'info'))) ?>"><?= icon(Notifier::levelIcon($a['level'])) ?></span>
      <div class="list-item-main">
        <div class="list-item-title"><?= e($a['title']) ?></div>
        <div class="list-item-sub"><?= e($a['message'] ?? '') ?><?= $a['router_name'] ? ' · ' . e($a['router_name']) : '' ?></div>
      </div>
      <div class="list-item-end">
        <div><?= e(time_ago($a['created_at'])) ?></div>
        <div class="text-xs"><?= e(Notifier::TYPES[$a['type']] ?? $a['type']) ?></div>
      </div>
      <?php if (!$a['is_read']): ?>
      <form method="post" action="<?= e(url('/alerts/' . $a['id'] . '/read')) ?>">
        <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="تعليم كمقروء"><?= icon('check') ?></button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
    <?php if (!$rows): ?><li><div class="empty"><?= icon('bell') ?><div class="empty-title">لا توجد تنبيهات</div></div></li><?php endif; ?>
  </ul>
  <?= $pager->render($req) ?>
</div>
