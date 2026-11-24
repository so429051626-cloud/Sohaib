<?php
/**
 * @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var array $f @var array $stats @var bool $all @var array $employees
 */
use App\Services\Maintenance;

$v->title = $all ? 'مهام الصيانة' : 'مهامي';
if (can('maintenance.manage')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/maintenance/create')) . '">' . icon('plus') . 'مهمة جديدة</a>';
}
$tabs = ['open' => 'المفتوحة', 'done' => 'المنتهية', 'cancelled' => 'الملغاة', 'all' => 'الكل'];
$q = static fn (array $over) => url('/maintenance', array_filter($over + ['state' => $f['state'], 'type' => $f['type'], 'assignee' => $f['assignee'] ?: null, 'q' => $f['q']], static fn ($v) => $v !== null && $v !== '' && $v !== 0));
$today = date('Y-m-d');
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-info"><?= icon('list-todo') ?></div><div class="stat-body"><div class="stat-label">جديدة</div><div class="stat-value"><?= num($stats['new']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('circle-play') ?></div><div class="stat-body"><div class="stat-label">قيد التنفيذ</div><div class="stat-value"><?= num($stats['progress']) ?></div></div></div>
  <div class="stat"><div class="stat-icon <?= $stats['late'] ? 'is-danger' : '' ?>"><?= icon('calendar-clock') ?></div><div class="stat-body"><div class="stat-label">متأخرة عن موعدها</div><div class="stat-value"><?= num($stats['late']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">منتهية هذا الشهر</div><div class="stat-value"><?= num($stats['done']) ?></div></div></div>
</div>

<div class="panel">
  <nav class="tabs">
    <?php foreach ($tabs as $k => $label): ?><a class="tab<?= $f['state'] === $k ? ' is-active' : '' ?>" href="<?= e($q(['state' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <input type="hidden" name="state" value="<?= e($f['state']) ?>">
    <div class="field"><label for="f-type">النوع</label><select class="input" id="f-type" name="type" data-autosubmit><option value="">الكل</option><?php foreach (Maintenance::TYPES as $k => [$label]): ?><option value="<?= $k ?>"<?= selected($f['type'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <?php if ($all): ?>
    <div class="field"><label for="f-assignee">الفنّي</label><select class="input" id="f-assignee" name="assignee" data-autosubmit><option value="">الكل</option><option value="-1"<?= selected($f['assignee'], -1) ?>>غير مسندة</option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($f['assignee'], (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field grow"><label for="f-q">بحث</label><input class="input" id="f-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="العنوان أو المكان أو الرقم"></div>
    <button class="btn"><?= icon('search') ?>بحث</button>
  </form>
  <div class="mt-list">
    <?php foreach ($rows as $t): [$typeLabel, $typeIcon] = Maintenance::TYPES[$t['type']]; $late = in_array($t['status'], ['new', 'in_progress'], true) && $t['due_on'] !== null && $t['due_on'] < $today; $where = Maintenance::where($t); ?>
    <a class="mt-row prio-<?= e($t['priority']) ?>" href="<?= e(url('/maintenance/' . $t['id'])) ?>">
      <span class="mt-icon"><?= icon($typeIcon) ?></span>
      <span class="mt-main">
        <span class="mt-title"><span class="mt-no">#<?= (int) $t['id'] ?></span> <?= e($t['title']) ?></span>
        <span class="mt-meta">
          <span><?= e($typeLabel) ?></span>
          <?php if ($where !== ''): ?><span><?= icon('map-pin', 'icon-sm') ?><?= e($where) ?></span><?php endif; ?>
          <span><?= icon('user-round-check', 'icon-sm') ?><?= $t['assignee_name'] !== null ? e($t['assignee_name']) : '<i class="text-muted">غير مسندة</i>' ?></span>
          <?php if ((int) $t['parts'] > 0): ?><span><?= icon('package', 'icon-sm') ?><?= num((int) $t['parts']) ?> قطعة</span><?php endif; ?>
        </span>
      </span>
      <span class="mt-end">
        <span class="badge <?= Maintenance::STATUSES[$t['status']][1] ?>"><?= e(Maintenance::STATUSES[$t['status']][0]) ?></span>
        <?php if ($t['priority'] !== 'normal' && in_array($t['status'], ['new', 'in_progress'], true)): ?><span class="badge <?= Maintenance::PRIORITIES[$t['priority']][1] ?>"><?= e(Maintenance::PRIORITIES[$t['priority']][0]) ?></span><?php endif; ?>
        <?php if ($t['due_on'] !== null && $t['status'] !== 'cancelled'): ?><span class="mt-due<?= $late ? ' is-late' : '' ?>"><?= icon('calendar-clock', 'icon-sm') ?><?= e($t['due_on']) ?></span><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="empty"><?= icon('wrench') ?><div class="empty-title"><?= $f['state'] === 'open' ? 'لا توجد مهام مفتوحة' : 'لا توجد مهام' ?></div></div><?php endif; ?>
  </div>
  <?= $pager->render($request) ?>
</div>
