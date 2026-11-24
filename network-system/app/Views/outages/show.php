<?php
/**
 * @var App\Core\View $v @var array $o @var int $duration @var bool $compEnabled @var ?array $preview @var array $applied @var array $rule
 */
use App\Services\OutageService;

$v->title = $o['title'];
$v->breadcrumbs = [['سجل الانقطاعات', '/outages'], ['#' . $o['id']]];
$open = $o['ended_at'] === null;
$manage = can('outages.manage');
$dt = static fn (?string $x) => $x ? str_replace(' ', 'T', substr($x, 0, 16)) : '';
$locked = $o['compensated_at'] !== null;
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $open ? 'is-danger' : 'is-success' ?>"><?= icon($open ? 'unplug' : 'plug') ?></div><div class="stat-body"><div class="stat-label">الحالة</div><div class="stat-value stat-value-sm"><span class="badge <?= $open ? 'badge-danger' : 'badge-success' ?>"><?= $open ? 'مفتوح' : 'مغلق' ?></span></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('timer') ?></div><div class="stat-body"><div class="stat-label">المدة</div><div class="stat-value"><?= e(fmt_duration($duration, true)) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">المتأثرون</div><div class="stat-value"><?= num((int) $o['affected']) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('router') ?></div><div class="stat-body"><div class="stat-label">الموقع</div><div class="stat-value stat-value-sm"><?= e($o['router_name'] ?? ($o['area_name'] ?? 'عام')) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <form method="post" action="<?= e(url('/outages/' . $o['id'])) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('clipboard-list') ?>بيانات الانقطاع</h3><span class="badge badge-outline"><?= $o['source'] === 'auto' ? 'تلقائي' : 'يدوي' ?></span></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-8"><label>العنوان</label><input class="input" name="title" value="<?= e(old('title', $o['title'])) ?>" required maxlength="160"<?= $manage ? '' : ' disabled' ?>></div>
          <div class="field col-4"><label>السبب</label><select class="input" name="cause"<?= $manage ? '' : ' disabled' ?>><?php foreach (OutageService::CAUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected(old('cause', $o['cause']), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="field col-4"><label>البداية</label><input class="input" type="datetime-local" name="started_at" value="<?= e(old('started_at', $dt($o['started_at']))) ?>" required<?= $manage && !$locked ? '' : ' readonly' ?>></div>
          <div class="field col-4"><label>النهاية</label><input class="input" type="datetime-local" name="ended_at" value="<?= e(old('ended_at', $dt($o['ended_at']))) ?>"<?= $manage && !$locked ? '' : ' readonly' ?>><?= error_html('ended_at') ?></div>
          <div class="field col-4"><label>عدد المتأثرين</label><input class="input input-ltr" name="affected" value="<?= e(old('affected', (string) $o['affected'])) ?>" inputmode="numeric" required<?= $manage ? '' : ' disabled' ?>></div>
          <div class="field col-12"><label>ملاحظات</label><input class="input" name="notes" value="<?= e(old('notes', $o['notes'] ?? '')) ?>" maxlength="500"<?= $manage ? '' : ' disabled' ?>></div>
        </div>
        <?php if ($manage): ?>
        <div class="form-actions">
          <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <?php if ($open): ?><button class="btn" formaction="<?= e(url('/outages/' . $o['id'] . '/close')) ?>" formnovalidate><?= icon('plug') ?>إغلاق الآن</button><?php endif; ?>
          <?php if (!$locked): ?><button class="btn btn-ghost text-danger" formaction="<?= e(url('/outages/' . $o['id'] . '/delete')) ?>" formnovalidate data-confirm="حذف هذا الانقطاع من السجل؟"><?= icon('trash-2') ?>حذف</button><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($compEnabled && $locked): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('badge-check') ?>تم التعويض</h3><span class="badge badge-success"><?= num((int) $o['compensated_days']) ?> يوم · <?= num((int) $o['compensated_count']) ?> مشترك</span></div>
      <div class="panel-body text-sm text-muted"><?= e(fmt_dt($o['compensated_at'])) ?> · <?= (int) $o['compensated_auto'] ? 'تلقائي' : e($o['comp_by'] ?? '') ?></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>المشترك</th><th>الأيام</th><th>كان ينتهي</th><th>صار ينتهي</th></tr></thead>
        <tbody><?php foreach ($applied as $c): ?><tr>
          <td><a class="cell-title" href="<?= e(url('/subscribers/' . $c['subscriber_id'])) ?>"><?= e($c['full_name']) ?></a><div class="cell-sub mono"><?= e($c['username']) ?></div></td>
          <td><?= num((int) $c['days']) ?></td><td class="nowrap text-sm"><?= e(fmt_dt($c['old_expires_at'])) ?></td><td class="nowrap text-sm"><?= e(fmt_dt($c['new_expires_at'])) ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </div>
    <?php elseif ($preview !== null): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('hand-coins') ?>معاينة التعويض</h3><?php if ($preview['eligible']): ?><span class="badge badge-info"><?= num($preview['days']) ?> يوم × <?= num(count($preview['rows'])) ?> مشترك</span><?php endif; ?></div>
      <?php if (!$preview['eligible']): ?>
        <div class="empty"><?= icon('info') ?><div class="empty-title"><?= e($preview['reason']) ?></div></div>
      <?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>المشترك</th><th>الأيام المضافة</th><th>ينتهي الآن</th><th>بعد التعويض</th></tr></thead>
        <tbody><?php foreach (array_slice($preview['rows'], 0, 300) as $r): ?><tr>
          <td><a class="cell-title" href="<?= e(url('/subscribers/' . $r['id'])) ?>"><?= e($r['full_name']) ?></a><div class="cell-sub mono"><?= e($r['username']) ?></div></td>
          <td><?= num((int) $r['days']) ?></td><td class="nowrap text-sm"><?= e(fmt_dt($r['expires_at'])) ?></td><td class="nowrap text-sm"><strong><?= e(fmt_dt($r['new_expires_at'])) ?></strong></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
      <div class="panel-body">
        <form method="post" action="<?= e(url('/outages/' . $o['id'] . '/compensate')) ?>" data-confirm="تمديد اشتراك <?= num(count($preview['rows'])) ?> مشترك بمقدار <?= num($preview['days']) ?> يوم؟" data-confirm-type="primary">
          <?= csrf_field() ?><button class="btn btn-primary"><?= icon('check') ?>تأكيد التعويض</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>التفاصيل</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>جهاز MikroTik</dt><dd><?= $o['router_id'] && can('routers.view') ? '<a href="' . e(url('/routers/' . $o['router_id'])) . '">' . e($o['router_name']) . '</a>' : e($o['router_name'] ?? '—') ?></dd>
          <dt>المنطقة</dt><dd><?= e($o['area_name'] ?? '—') ?></dd>
          <dt>البداية</dt><dd><?= e(fmt_dt($o['started_at'])) ?></dd>
          <dt>النهاية</dt><dd><?= $o['ended_at'] ? e(fmt_dt($o['ended_at'])) : '—' ?></dd>
        </dl>
      </div>
    </div>
    <?php if ($compEnabled): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('sliders-horizontal') ?>قاعدة التعويض</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>أقل مدة انقطاع</dt><dd><?= e(rtrim(rtrim(number_format($rule['min_hours'], 1), '0'), '.')) ?> ساعة</dd>
          <dt>يوم لكل</dt><dd><?= e(rtrim(rtrim(number_format($rule['hours_per_day'], 1), '0'), '.')) ?> ساعة</dd>
          <dt>الحد الأقصى</dt><dd><?= num($rule['max_days']) ?> يوم</dd>
        </dl>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
