<?php
/** @var App\Core\View $v @var array $banks */
use App\Core\Platform;
use App\Platform\PlatformSms;

$v->title = 'الاشتراك والفوترة';
$s = static fn (string $k) => old(str_replace('billing.', '', $k), Platform::setting($k));
?>
<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/admin/billing')) ?>" class="panel">
    <?= csrf_field() ?>
    <div class="panel-head"><h3><?= icon('calendar-clock') ?>مسار الاشتراك</h3></div>
    <div class="panel-body">
      <div class="flow-steps">
        <span class="flow-step is-info"><?= icon('bell') ?>تذكير</span><?= icon('chevron-left', 'icon-sm text-muted') ?>
        <span class="flow-step is-warning"><?= icon('hourglass') ?>سماح</span><?= icon('chevron-left', 'icon-sm text-muted') ?>
        <span class="flow-step"><?= icon('eye') ?>قراءة فقط</span><?= icon('chevron-left', 'icon-sm text-muted') ?>
        <span class="flow-step is-danger"><?= icon('ban') ?>إيقاف</span>
      </div>
      <div class="form-grid">
        <div class="field col-4"><label>التذكير قبل الانتهاء</label><div class="input-group"><input class="input input-ltr" name="remind_days" value="<?= e($s('billing.remind_days')) ?>" maxlength="40"><span class="input-addon">يوم</span></div><?= error_html('remind_days') ?></div>
        <div class="field col-4"><label>فترة السماح</label><div class="input-group"><input class="input input-ltr" name="grace_days" value="<?= e($s('billing.grace_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div><?= error_html('grace_days') ?></div>
        <div class="field col-4"><label>مدة القراءة فقط</label><div class="input-group"><input class="input input-ltr" name="readonly_days" value="<?= e($s('billing.readonly_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div><?= error_html('readonly_days') ?></div>
        <div class="field col-12"><label class="switch"><input type="checkbox" name="remind_sms" value="1"<?= checked(Platform::setting('billing.remind_sms') === '1') ?>><span class="track"></span><span>إرسال التذكير برسالة نصية</span></label>
          <?php if (!PlatformSms::configured()): ?><span class="badge badge-outline">الرسائل النصية غير مضبوطة</span><?php endif; ?></div>
        <div class="form-section-title"><?= icon('receipt-text') ?>الفاتورة</div>
        <div class="field col-4"><label>بادئة رقم الفاتورة</label><input class="input input-ltr" name="invoice_prefix" value="<?= e($s('billing.invoice_prefix')) ?>" maxlength="8" required><?= error_html('invoice_prefix') ?></div>
        <div class="field col-8"><label>ملاحظة أسفل الفاتورة</label><input class="input" name="invoice_note" value="<?= e($s('billing.invoice_note')) ?>" maxlength="300"></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
    </div>
  </form>

  <div class="panel">
    <div class="panel-head"><h3><?= icon('wallet') ?>الحسابات البنكية</h3><button type="button" class="btn btn-sm" data-modal-open="m-bank" data-bank=""><?= icon('plus') ?>إضافة</button></div>
    <ul class="list">
      <?php foreach ($banks as $b): ?>
        <li class="list-item">
          <span class="list-icon <?= (int) $b['is_active'] ? 'is-success' : '' ?>"><?= icon('banknote') ?></span>
          <div class="list-item-main"><div class="list-item-title"><?= e($b['label']) ?><?= (int) $b['is_active'] ? '' : ' <span class="badge badge-outline">متوقف</span>' ?></div>
            <div class="list-item-sub"><?= e($b['holder']) ?> · <span class="mono"><?= ltr(e($b['number'])) ?></span></div></div>
          <div class="list-item-end">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-open="m-bank" data-bank="<?= e(json_encode($b, JSON_UNESCAPED_UNICODE)) ?>" title="تعديل"><?= icon('pencil') ?></button>
            <form method="post" action="<?= e(url('/admin/banks/' . $b['id'] . '/delete')) ?>" data-confirm="حذف الحساب <?= e($b['label']) ?>؟" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$banks): ?><li class="list-item text-muted">لا توجد حسابات.</li><?php endif; ?>
    </ul>
  </div>
</div>

<dialog class="modal modal-sm" id="m-bank">
  <form method="post" action="<?= e(url('/admin/banks')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-head"><h3><?= icon('wallet') ?>حساب بنكي</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label>البنك أو المحفظة</label><input class="input" name="label" required maxlength="80"></div>
      <div class="field"><label>اسم صاحب الحساب</label><input class="input" name="holder" required maxlength="120"></div>
      <div class="field"><label>رقم الحساب</label><input class="input input-ltr" name="number" required maxlength="80"></div>
      <div class="field"><label>تفاصيل إضافية</label><input class="input" name="details" maxlength="255"></div>
      <label class="switch"><input type="checkbox" name="is_active" value="1"><span class="track"></span><span>يظهر للزبائن</span></label>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-bank').addEventListener('modal:open', function (e) {
  var f = this.querySelector('form'), raw = e.detail.trigger.getAttribute('data-bank'), b = raw ? JSON.parse(raw) : null;
  ['id', 'label', 'holder', 'number', 'details'].forEach(function (n) { f.elements[n].value = b && b[n] !== null ? b[n] : ''; });
  f.elements.is_active.checked = !b || b.is_active == 1;
});
</script>
