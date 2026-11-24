<?php
/** @var App\Core\View $v */
use App\Services\Inventory;

[$stL, $stC] = Inventory::UNIT_STATUS[$unit['status']];
$canManage = can('inventory.manage');
$v->title = $unit['serial'];
$v->breadcrumbs = [['المخزون', '/inventory'], [$unit['item_name'], '/inventory/' . $unit['item_id']], [$unit['serial'], null]];
$btn = static fn (string $action, string $iconName, string $label, string $cls = 'btn') => '<button type="button" class="' . $cls . '" data-modal-open="inv-unit" data-url="'
    . e(url('/inventory/units/' . $unit['id'] . '/action')) . '" data-action="' . $action . '" data-serial="' . e($unit['serial']) . '" data-item="' . e($unit['item_name']) . '">' . icon($iconName) . $label . '</button>';
if ($canManage) {
    $a = '<button type="button" class="btn" data-modal-open="unit-edit">' . icon('pencil') . 'تعديل</button>';
    if (!in_array($unit['status'], ['faulty', 'lost'], true)) {
        $a .= $btn('faulty', 'triangle-alert', 'تالف');
    }
    if ($unit['status'] !== 'lost') {
        $a .= $btn('lost', 'circle-alert', 'مفقود');
    }
    $a .= $unit['status'] === 'stock' ? $btn('install', 'package-minus', 'تركيب', 'btn btn-primary') : $btn('return', 'undo-2', 'إرجاع إلى المخزن', 'btn btn-primary');
    $v->actions = $a;
}
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('history') ?>سجل الجهاز</h3></div>
    <?php if (!$history): ?>
      <div class="empty"><?= icon('history') ?><div>لا توجد حركات.</div></div>
    <?php else: ?>
    <ul class="timeline">
      <?php foreach ($history as $m): [$mL, $mC, $mI] = Inventory::MOVE_TYPES[$m['type']]; ?>
        <li class="timeline-item">
          <span class="timeline-dot <?= $mC ?>"><?= icon($mI) ?></span>
          <div class="timeline-body">
            <div class="flex items-center gap-2 wrap"><strong><?= e($mL) ?></strong>
              <?php $to = array_filter([$m['site_name'], $m['subscriber_name'], $m['receiver']]); if ($to): ?><span class="text-muted">· <?= e(implode(' · ', $to)) ?></span><?php endif; ?></div>
            <?php if ($m['notes']): ?><div class="text-sm"><?= e($m['notes']) ?></div><?php endif; ?>
            <div class="cell-sub"><?= e(fmt_dt($m['created_at'])) ?><?= $m['employee_name'] ? ' · ' . e($m['employee_name']) : '' ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('barcode') ?>البيانات</h3><span class="badge <?= $stC ?>"><?= $stL ?></span></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الصنف</dt><dd><a href="<?= e(url('/inventory/' . $unit['item_id'])) ?>"><?= e($unit['item_name']) ?></a></dd>
          <dt>الرقم التسلسلي</dt><dd><span class="mono"><?= e($unit['serial']) ?></span> <button type="button" class="btn btn-ghost btn-icon btn-xs" data-copy="<?= e($unit['serial']) ?>" title="نسخ"><?= icon('copy') ?></button></dd>
          <dt>MAC</dt><dd><span class="mono"><?= e($unit['mac'] ?? '—') ?></span></dd>
          <?php if ($unit['site_id']): ?><dt>الموقع</dt><dd><a href="<?= e(url('/sites/' . $unit['site_id'])) ?>"><?= e($unit['site_name']) ?></a></dd><?php endif; ?>
          <?php if ($unit['subscriber_id']): ?><dt>المشترك</dt><dd><a href="<?= e(url('/subscribers/' . $unit['subscriber_id'])) ?>"><?= e($unit['subscriber_name']) ?></a></dd><?php endif; ?>
          <?php if ($unit['router_id']): ?><dt>جهاز MikroTik</dt><dd><a href="<?= e(url('/routers/' . $unit['router_id'])) ?>"><?= e($unit['router_name']) ?></a></dd><?php endif; ?>
          <?php if ($unit['installed_at']): ?><dt>تاريخ التركيب</dt><dd><?= e(fmt_dt($unit['installed_at'])) ?></dd><?php endif; ?>
          <dt>تاريخ الإدخال</dt><dd><?= e(fmt_dt($unit['created_at'])) ?></dd>
          <?php if ($unit['unit_cost'] !== null): ?><dt>التكلفة</dt><dd><?= e(money($unit['unit_cost'])) ?></dd><?php endif; ?>
          <?php if ($unit['notes']): ?><dt>ملاحظات</dt><dd><?= e($unit['notes']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if ($canManage): ?>
    <form method="post" action="<?= e(url('/inventory/units/' . $unit['id'] . '/delete')) ?>" data-confirm="حذف الجهاز <?= e($unit['serial']) ?> وسجلّه؟" class="text-center">
      <?= csrf_field() ?><button class="btn btn-ghost text-danger"><?= icon('trash-2') ?>حذف الجهاز</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
<?= $v->partial('inventory-unit-action', ['sites' => $sites, 'subscribers' => $subscribers, 'routers' => $routers, 'back' => '']) ?>
<dialog class="modal" id="unit-edit">
  <form method="post" action="<?= e(url('/inventory/units/' . $unit['id'])) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('pencil') ?>تعديل الجهاز</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label for="ue-serial">الرقم التسلسلي</label><input class="input input-ltr" id="ue-serial" name="serial" value="<?= e(old('serial', $unit['serial'])) ?>" required maxlength="80"></div>
        <div class="field col-6"><label for="ue-mac">MAC</label><input class="input input-ltr" id="ue-mac" name="mac" value="<?= e(old('mac', $unit['mac'] ?? '')) ?>" maxlength="17"></div>
        <div class="field col-12"><label for="ue-notes">ملاحظات</label><input class="input" id="ue-notes" name="notes" value="<?= e(old('notes', $unit['notes'] ?? '')) ?>" maxlength="255"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
