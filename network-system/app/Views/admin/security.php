<?php
/** @var App\Core\View $v @var bool $enabled @var ?string $setup @var ?array $codes @var int $left @var ?string $uri */
$v->title = 'الأمان';
if ($setup !== null) {
    $v->script('vendor/qrcode.js');
}
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('shield-check') ?>التحقق بخطوتين</h3><span class="badge <?= $enabled ? 'badge-success' : 'badge-outline' ?>"><?= $enabled ? 'مفعّل' : 'غير مفعّل' ?></span></div>
    <div class="panel-body">
      <?php if ($codes): ?>
        <div class="recovery">
          <div class="recovery-title"><?= icon('key-round') ?>رموز الاسترجاع</div>
          <div class="recovery-codes" id="recovery-codes"><?php foreach ($codes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div>
          <button type="button" class="btn btn-sm" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?>نسخ الرموز</button>
        </div>
      <?php endif; ?>

      <?php if ($setup !== null): ?>
        <div class="totp-setup">
          <div class="totp-qr" id="totp-qr" data-uri="<?= e((string) $uri) ?>" aria-label="رمز QR لتطبيق المصادقة"></div>
          <div class="totp-side">
            <div class="field"><label>المفتاح</label><div class="input-group"><input class="input input-ltr mono" value="<?= e(trim(chunk_split($setup, 4, ' '))) ?>" readonly><button type="button" class="btn" data-copy="<?= e($setup) ?>"><?= icon('copy') ?></button></div></div>
            <form method="post" action="<?= e(url('/admin/security/totp/confirm')) ?>" autocomplete="off">
              <?= csrf_field() ?>
              <div class="field"><label>الرمز من التطبيق</label><input class="input input-ltr otp-input" name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus></div>
              <div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?>تأكيد التفعيل</button></div>
            </form>
          </div>
        </div>
      <?php elseif (!$enabled): ?>
        <form method="post" action="<?= e(url('/admin/security/totp')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><?= icon('shield-check') ?>تفعيل التحقق بخطوتين</button></form>
      <?php else: ?>
        <dl class="kv"><dt>تطبيق المصادقة</dt><dd>مربوط</dd><dt>رموز الاسترجاع المتبقية</dt><dd><?= num($left) ?></dd></dl>
        <div class="sub-actions mt-3">
          <button type="button" class="btn" data-modal-open="m-totp" data-action="<?= e(url('/admin/security/totp/codes')) ?>" data-label="رموز استرجاع جديدة"><?= icon('refresh-cw') ?>رموز استرجاع جديدة</button>
          <button type="button" class="btn btn-ghost text-danger" data-modal-open="m-totp" data-action="<?= e(url('/admin/security/totp/disable')) ?>" data-label="إيقاف التحقق بخطوتين"><?= icon('shield-ban') ?>إيقاف</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/security/password')) ?>" class="panel mt-3" autocomplete="off" style="max-width:640px">
  <?= csrf_field() ?>
  <div class="panel-head"><h3><?= icon('key-round') ?>كلمة المرور</h3></div>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-12"><label for="op-cur">كلمة المرور الحالية</label><input class="input input-ltr" id="op-cur" type="password" name="current" required autocomplete="current-password"><?= error_html('current') ?></div>
      <div class="field col-6"><label for="op-new">الجديدة</label><input class="input input-ltr" id="op-new" type="password" name="password" required minlength="8" autocomplete="new-password"><?= error_html('password') ?></div>
      <div class="field col-6"><label for="op-conf">تأكيدها</label><input class="input input-ltr" id="op-conf" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>تغيير كلمة المرور</button></div>
  </div>
</form>

<dialog class="modal modal-sm" id="m-totp">
  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="modal-head"><h3 data-totp-title></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>كلمة المرور الحالية</label><input class="input input-ltr" type="password" name="password" required autocomplete="current-password"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary">تأكيد</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-totp').addEventListener('modal:open', function (e) {
  this.querySelector('form').action = e.detail.trigger.getAttribute('data-action');
  this.querySelector('[data-totp-title]').textContent = e.detail.trigger.getAttribute('data-label');
});
document.addEventListener('DOMContentLoaded', function () {
  var box = document.getElementById('totp-qr');
  if (!box || !window.qrcode) { return; }
  var qr = window.qrcode(0, 'M');
  qr.addData(box.getAttribute('data-uri'));
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
});
</script>
