<?php
/**
 * @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var array $f
 * @var array $routers @var array $areas @var array $stats @var bool $compEnabled
 */
use App\Services\OutageService;

$v->title = 'سجل الانقطاعات';
if (can('outages.manage')) {
    $v->actions = '<button type="button" class="btn btn-primary" data-modal-open="m-outage">' . icon('plus') . 'تسجيل انقطاع</button>';
}
$causeBadge = ['unknown' => 'badge-outline', 'power' => 'badge-warning', 'fault' => 'badge-danger', 'damage' => 'badge-danger', 'maintenance' => 'badge-info'];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $stats['open'] ? 'is-danger' : 'is-success' ?>"><?= icon('unplug') ?></div><div class="stat-body"><div class="stat-label">انقطاعات مفتوحة</div><div class="stat-value"><?= num($stats['open']) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('calendar') ?></div><div class="stat-body"><div class="stat-label">انقطاعات هذا الشهر</div><div class="stat-value"><?= num($stats['month']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('timer') ?></div><div class="stat-body"><div class="stat-label">مدة الانقطاع هذا الشهر</div><div class="stat-value"><?= e(fmt_duration($stats['seconds'], true)) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">متأثرون هذا الشهر</div><div class="stat-value"><?= num($stats['affected']) ?></div></div></div>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field"><label for="f-state">الحالة</label><select class="input" id="f-state" name="state" data-autosubmit><option value="">الكل</option><option value="open"<?= selected($f['state'], 'open') ?>>مفتوح</option><option value="closed"<?= selected($f['state'], 'closed') ?>>مغلق</option></select></div>
    <div class="field"><label for="f-cause">السبب</label><select class="input" id="f-cause" name="cause" data-autosubmit><option value="">الكل</option><?php foreach (OutageService::CAUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($f['cause'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="f-router">جهاز MikroTik</label><select class="input" id="f-router" name="router" data-autosubmit><option value="">الكل</option><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($f['router'], (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>الانقطاع</th><th>السبب</th><th>البداية</th><th>المدة</th><th>المتأثرون</th><th>الحالة</th><?php if ($compEnabled): ?><th>التعويض</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $o): $open = $o['ended_at'] === null; ?>
      <tr>
        <td><a class="cell-title" href="<?= e(url('/outages/' . $o['id'])) ?>"><?= e($o['title']) ?></a>
          <div class="cell-sub"><?= e($o['router_name'] ?? ($o['area_name'] ?? 'عام')) ?> · <?= $o['source'] === 'auto' ? 'تلقائي' : 'يدوي' ?></div></td>
        <td><span class="badge <?= $causeBadge[$o['cause']] ?>"><?= e(OutageService::CAUSES[$o['cause']]) ?></span></td>
        <td class="nowrap text-sm"><?= e(fmt_dt($o['started_at'])) ?></td>
        <td class="nowrap"><?= e(fmt_duration(OutageService::duration($o), true)) ?></td>
        <td><?= num((int) $o['affected']) ?></td>
        <td><span class="badge <?= $open ? 'badge-danger' : 'badge-success' ?>"><?= $open ? 'مفتوح' : 'مغلق' ?></span></td>
        <?php if ($compEnabled): ?><td class="nowrap text-sm"><?= $o['compensated_at'] !== null ? num((int) $o['compensated_days']) . ' يوم · ' . num((int) $o['compensated_count']) . ' مشترك' : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $compEnabled ? 7 : 6 ?>"><div class="empty"><?= icon('circle-check') ?><div class="empty-title">لا توجد انقطاعات</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?= $pager->render($request) ?>
</div>

<?php if (can('outages.manage')): ?>
<dialog class="modal" id="m-outage">
  <form method="post" action="<?= e(url('/outages')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('unplug') ?>تسجيل انقطاع</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label>جهاز MikroTik</label><select class="input" name="router_id"><option value="">—</option><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) old('router_id'), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select><?= error_html('router_id') ?></div>
        <div class="field col-6"><label>المنطقة</label><select class="input" name="area_id"><option value="">—</option><?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) old('area_id'), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="field col-6"><label>السبب</label><select class="input" name="cause"><?php foreach (OutageService::CAUSES as $k => $l): if ($k === 'unknown') { continue; } ?><option value="<?= $k ?>"<?= selected(old('cause', 'power'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-6"><label>العنوان</label><input class="input" name="title" value="<?= e(old('title')) ?>" maxlength="160"></div>
        <div class="field col-6"><label>بداية الانقطاع</label><input class="input" type="datetime-local" name="started_at" value="<?= e(old('started_at', date('Y-m-d\TH:i'))) ?>" required><?= error_html('started_at') ?></div>
        <div class="field col-6"><label>نهاية الانقطاع</label><input class="input" type="datetime-local" name="ended_at" value="<?= e(old('ended_at')) ?>"><?= error_html('ended_at') ?></div>
        <div class="field col-6"><label>عدد المتأثرين</label><input class="input input-ltr" name="affected" value="<?= e(old('affected')) ?>" inputmode="numeric" placeholder="تلقائي"></div>
        <div class="field col-12"><label>ملاحظات</label><input class="input" name="notes" value="<?= e(old('notes')) ?>" maxlength="500"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
