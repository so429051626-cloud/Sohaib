<?php
/** @var App\Core\View $v @var array $t @var array $parts @var array $items @var array $history */
use App\Services\Inventory;
use App\Services\Maintenance;

[$typeLabel, $typeIcon] = Maintenance::TYPES[$t['type']];
$v->title = '#' . $t['id'] . ' · ' . $t['title'];
$v->breadcrumbs = [['مهام الصيانة', '/maintenance'], ['#' . $t['id'], null]];
$work = Maintenance::canWork($t);
$manage = can('maintenance.manage');
$open = in_array($t['status'], ['new', 'in_progress'], true);
$actions = '';
if ($manage) {
    $actions .= '<a class="btn" href="' . e(url('/maintenance/' . $t['id'] . '/edit')) . '">' . icon('pencil') . 'تعديل</a>';
}
$v->actions = $actions;
$map = Maintenance::mapUrl($t);
$late = $open && $t['due_on'] !== null && $t['due_on'] < date('Y-m-d');
$cost = array_sum(array_map(static fn ($p) => (float) $p['qty'] * (float) $p['unit_cost'], $parts));
?>
<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon($typeIcon) ?><?= e($typeLabel) ?></h3>
        <div class="panel-tools"><span class="badge <?= Maintenance::STATUSES[$t['status']][1] ?>"><?= e(Maintenance::STATUSES[$t['status']][0]) ?></span><?php if ($t['priority'] !== 'normal'): ?><span class="badge <?= Maintenance::PRIORITIES[$t['priority']][1] ?>"><?= e(Maintenance::PRIORITIES[$t['priority']][0]) ?></span><?php endif; ?></div></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الفنّي</dt><dd><?= $t['assignee_name'] !== null ? e($t['assignee_name']) . ($t['assignee_phone'] ? ' · <a class="mono" href="tel:' . e($t['assignee_phone']) . '">' . e($t['assignee_phone']) . '</a>' : '') : '<span class="text-muted">غير مسندة</span>' ?></dd>
          <?php if ($t['site_name'] !== null): ?><dt>البرج أو الموقع</dt><dd><?= e($t['site_name']) ?></dd><?php endif; ?>
          <?php if ($t['router_name'] !== null): ?><dt>جهاز MikroTik</dt><dd><?= can('routers.view') ? '<a href="' . e(url('/routers/' . $t['router_id'])) . '">' . e($t['router_name']) . '</a>' : e($t['router_name']) ?></dd><?php endif; ?>
          <?php if ($t['subscriber_name'] !== null): ?><dt>المشترك</dt><dd><?= can('subscribers.view') ? '<a href="' . e(url('/subscribers/' . $t['subscriber_id'])) . '">' . e($t['subscriber_name']) . '</a>' : e($t['subscriber_name']) ?><?= $t['subscriber_phone'] ? ' · <a class="mono" href="tel:' . e($t['subscriber_phone']) . '">' . e($t['subscriber_phone']) . '</a>' : '' ?></dd><?php endif; ?>
          <?php if ($t['location'] !== null): ?><dt>المكان</dt><dd><?= e($t['location']) ?></dd><?php endif; ?>
          <?php if ($t['due_on'] !== null): ?><dt>الموعد</dt><dd class="<?= $late ? 'text-danger' : '' ?>"><?= e($t['due_on']) ?><?= $late ? ' · متأخرة' : '' ?></dd><?php endif; ?>
          <?php if ($t['started_at'] !== null): ?><dt>بدأت</dt><dd><?= e(fmt_dt($t['started_at'])) ?></dd><?php endif; ?>
          <?php if ($t['done_at'] !== null): ?><dt>انتهت</dt><dd><?= e(fmt_dt($t['done_at'])) ?></dd><?php endif; ?>
          <dt>أنشأها</dt><dd><?= e(($t['creator_name'] ?? '—') . ' · ' . fmt_dt($t['created_at'])) ?></dd>
        </dl>
        <?php if ($map): ?><a class="btn btn-sm mt-3" href="<?= e($map) ?>" target="_blank" rel="noopener"><?= icon('map-pin') ?>الموقع على الخريطة</a><?php endif; ?>
        <?php if ($t['description'] !== null): ?><div class="mt-desc"><?= nl2br(e($t['description'])) ?></div><?php endif; ?>
        <?php if ($t['result'] !== null): ?><div class="notice notice-success mt-3"><?= icon('circle-check') ?><div><div class="notice-title">النتيجة</div><?= e($t['result']) ?></div></div><?php endif; ?>
      </div>
      <?php if ($work): ?>
      <div class="panel-body mt-actions">
        <?php if ($t['status'] === 'new'): ?>
          <form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="in_progress"><button class="btn btn-primary"><?= icon('circle-play') ?>بدء التنفيذ</button></form>
        <?php endif; ?>
        <?php if ($open): ?>
          <button type="button" class="btn btn-success" data-modal-open="m-done"><?= icon('circle-check') ?>إنهاء المهمة</button>
          <?php if ($manage): ?><form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/status')) ?>" data-confirm="إلغاء المهمة #<?= (int) $t['id'] ?>؟"><?= csrf_field() ?><input type="hidden" name="status" value="cancelled"><button class="btn btn-ghost text-danger"><?= icon('circle-stop') ?>إلغاء المهمة</button></form><?php endif; ?>
        <?php else: ?>
          <form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="new"><button class="btn"><?= icon('rotate-ccw') ?>إعادة فتح</button></form>
        <?php endif; ?>
        <?= error_html('status') ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="panel" id="parts">
      <div class="panel-head"><h3><?= icon('package') ?>القطع المصروفة</h3><?php if ($parts && $cost > 0): ?><span class="badge"><?= e(money($cost)) ?></span><?php endif; ?></div>
      <?php if ($parts): ?>
      <ul class="list">
        <?php foreach ($parts as $p): ?>
        <li class="list-item">
          <span class="list-icon is-info"><?= icon('package-minus') ?></span>
          <div class="list-item-main"><div class="list-item-title"><?= e($p['name']) ?></div><div class="list-item-sub"><?= e(Inventory::fmtQty($p['qty']) . ' ' . (Inventory::UNITS[$p['unit']] ?? $p['unit'])) ?> · <?= e(($p['by_name'] ?? '—') . ' · ' . time_ago($p['created_at'])) ?></div></div>
          <?php if ($work): ?><form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/parts/' . $p['id'] . '/delete')) ?>" data-confirm="إرجاع <?= e($p['name']) ?> إلى المخزون؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="إرجاع إلى المخزون"><?= icon('undo-2') ?></button></form><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($work && $open): ?>
      <form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/parts')) ?>" class="panel-body mt-part-form">
        <?= csrf_field() ?>
        <?php if ($items): ?>
        <div class="field grow"><label for="p-item">الصنف</label><select class="input" id="p-item" name="item_id" required><?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?> (المتوفر <?= e(Inventory::fmtQty($i['quantity'])) ?>)</option><?php endforeach; ?></select></div>
        <div class="field"><label for="p-qty">الكمية</label><input class="input input-ltr" id="p-qty" name="qty" value="1" inputmode="decimal" required></div>
        <button class="btn"><?= icon('package-minus') ?>صرف من المخزون</button>
        <?php else: ?><div class="text-muted text-sm">لا توجد أصناف متوفرة في المخزون.</div><?php endif; ?>
        <?= error_html('qty') ?>
      </form>
      <?php elseif (!$parts): ?><div class="panel-body text-muted text-sm">لم تُصرف قطع.</div><?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>السجل</h3></div>
      <ul class="list">
        <?php foreach ($history as $h): ?><li class="list-item"><div class="list-item-main"><div class="list-item-title mt-h"><?= e($h['summary']) ?></div><div class="list-item-sub"><?= e(($h['name'] ?? '—') . ' · ' . time_ago($h['created_at'])) ?></div></div></li><?php endforeach; ?>
      </ul>
    </div>
    <?php if ($manage): ?>
    <form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/delete')) ?>" data-confirm="حذف المهمة #<?= (int) $t['id'] ?> نهائياً؟ القطع المصروفة لها تعود إلى المخزون."><?= csrf_field() ?><button class="btn btn-ghost text-danger btn-block"><?= icon('trash-2') ?>حذف المهمة</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($work && $open): ?>
<dialog class="modal modal-sm" id="m-done">
  <form method="post" action="<?= e(url('/maintenance/' . $t['id'] . '/status')) ?>">
    <?= csrf_field() ?><input type="hidden" name="status" value="done">
    <div class="modal-head"><h3><?= icon('circle-check') ?>إنهاء المهمة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label for="d-result">ما الذي تم؟</label><textarea class="input" id="d-result" name="result" rows="4" maxlength="500"></textarea></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-success"><?= icon('check') ?>إنهاء</button></div>
  </form>
</dialog>
<?php endif; ?>
