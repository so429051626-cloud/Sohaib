<?php
/**
 * نافذة حركة مخزون (إدخال/صرف/إرجاع/تالف/تسوية) لصنف يُحدَّد من الزر المفعِّل.
 * @var App\Core\View $v
 * @var array $sites
 * @var array $subscribers
 * @var string $back
 */
use App\Services\Inventory;

$v->script('js/inventory.js');
?>
<dialog class="modal" id="inv-move">
  <form method="post" action="" data-inv-move>
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back ?? '') ?>">
    <div class="modal-head">
      <h3><?= icon('arrow-left-right') ?><span data-inv-title>حركة مخزون</span></h3>
      <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
      <div class="inv-move-head" data-inv-head>
        <span class="list-icon"><?= icon('package') ?></span>
        <div class="grow"><div class="list-item-title" data-inv-name></div><div class="list-item-sub" data-inv-balance></div></div>
      </div>
      <div class="form-grid">
        <div class="field col-12" data-mode="qty">
          <label>نوع الحركة</label>
          <div class="segmented" role="radiogroup">
            <?php foreach (Inventory::MOVE_TYPES as $k => [$label, , $ic]): ?>
              <label class="segmented-item"><input type="radio" name="type" value="<?= e($k) ?>"<?= $k === 'in' ? ' checked' : '' ?>><span><?= icon($ic) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field col-6" data-mode="qty">
          <label for="im-qty" data-qty-label>الكمية</label>
          <div class="input-group"><input class="input" id="im-qty" name="qty" inputmode="decimal" autocomplete="off"><span class="input-addon" data-inv-unit></span></div>
        </div>
        <div class="field col-6" data-show="in">
          <label for="im-cost">تكلفة الوحدة</label>
          <input class="input" id="im-cost" name="unit_cost" inputmode="decimal" autocomplete="off">
        </div>
        <div class="field col-12" data-mode="serial">
          <label for="im-serials">الأرقام التسلسلية</label>
          <textarea class="input input-ltr" id="im-serials" name="serials" rows="6" placeholder="SN-0001  AA:BB:CC:DD:EE:01&#10;SN-0002  AA:BB:CC:DD:EE:02"></textarea>
        </div>
        <div class="field col-6" data-show="out,return">
          <label for="im-site">الموقع / البرج</label>
          <select class="input" id="im-site" name="site_id"><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6" data-show="out,return">
          <label for="im-sub">المشترك</label>
          <select class="input" id="im-sub" name="subscriber_id"><option value="">—</option><?php foreach ($subscribers as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6" data-show="out">
          <label for="im-receiver">المستلم</label>
          <input class="input" id="im-receiver" name="receiver" maxlength="120" autocomplete="off">
        </div>
        <div class="field col-6" data-show="in,out,return,damage,adjust" data-wide-when="in,out">
          <label for="im-notes">ملاحظات</label>
          <input class="input" id="im-notes" name="notes" maxlength="255" autocomplete="off">
        </div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تسجيل</button></div>
  </form>
</dialog>
