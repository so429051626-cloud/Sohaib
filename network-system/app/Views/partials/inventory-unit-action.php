<?php
/**
 * نافذة تغيير حالة جهاز مرقّم: تركيب، إرجاع، تلف، فقد.
 * @var App\Core\View $v
 * @var array $sites
 * @var array $subscribers
 * @var array $routers
 * @var string $back
 */
$v->script('js/inventory.js');
?>
<dialog class="modal" id="inv-unit">
  <form method="post" action="" data-inv-unit>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="">
    <input type="hidden" name="back" value="<?= e($back ?? '') ?>">
    <div class="modal-head">
      <h3><?= icon('hard-drive') ?><span data-unit-title></span></h3>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
      <div class="inv-move-head">
        <span class="list-icon"><?= icon('barcode') ?></span>
        <div class="grow"><div class="list-item-title mono" data-unit-serial></div><div class="list-item-sub" data-unit-item></div></div>
      </div>
      <div class="form-grid">
        <div class="field col-6" data-show="install">
          <label for="iu-site">الموقع / البرج</label>
          <select class="input" id="iu-site" name="site_id"><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6" data-show="install">
          <label for="iu-router">جهاز MikroTik</label>
          <select class="input" id="iu-router" name="router_id"><option value="">—</option><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6" data-show="install">
          <label for="iu-sub">المشترك</label>
          <select class="input" id="iu-sub" name="subscriber_id"><option value="">—</option><?php foreach ($subscribers as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6" data-show="install">
          <label for="iu-receiver">المستلم / الفنّي</label>
          <input class="input" id="iu-receiver" name="receiver" maxlength="120" autocomplete="off">
        </div>
        <div class="field col-12">
          <label for="iu-notes">ملاحظات</label>
          <input class="input" id="iu-notes" name="notes" maxlength="255" autocomplete="off">
        </div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary" data-unit-submit><?= icon('check') ?>تأكيد</button></div>
  </form>
</dialog>
