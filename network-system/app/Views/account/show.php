<?php
/** @var App\Core\View $v */
use App\Controllers\AccountController;
use App\Core\Audit;
use App\Permissions;

$v->title = 'حسابي';
$v->breadcrumbs = [['الرئيسية', '/'], ['حسابي', null]];
if ($twoFactor['setup'] !== null) {
    $v->script('vendor/qrcode.js');
}
?>
<div class="grid grid-main-side">
  <div class="stack">
  <form method="post" action="<?= e(url('/account')) ?>" class="panel">
    <?= csrf_field() ?>
    <div class="panel-head"><h2><?= icon('circle-user') ?>البيانات الشخصية</h2></div>
    <div class="panel-body">
      <div class="form-grid">
        <div class="field col-6">
          <label for="name">الاسم <span class="req">*</span></label>
          <input class="input" id="name" name="name" value="<?= e(old('name', $user['name'])) ?>" required>
          <?= error_html('name') ?>
        </div>
        <div class="field col-6">
          <label>اسم الدخول</label>
          <input class="input input-ltr" value="<?= e($user['username']) ?>" readonly>
        </div>
        <div class="field col-6">
          <label for="phone">الهاتف</label>
          <input class="input input-ltr" id="phone" name="phone" value="<?= e(old('phone', $user['phone'])) ?>">
        </div>
        <div class="field col-6">
          <label for="email">البريد الإلكتروني</label>
          <input class="input input-ltr" id="email" name="email" type="email" value="<?= e(old('email', $user['email'])) ?>">
          <?= error_html('email') ?>
        </div>
        <div class="field col-6">
          <label for="theme">المظهر</label>
          <select class="input" id="theme" name="theme">
            <option value="light"<?= selected($user['theme'], 'light') ?>>فاتح</option>
            <option value="dark"<?= selected($user['theme'], 'dark') ?>>داكن</option>
          </select>
        </div>

        <div class="form-section-title">تغيير كلمة المرور</div>
        <div class="field col-4">
          <label for="current_password">كلمة المرور الحالية</label>
          <input class="input input-ltr" id="current_password" name="current_password" type="password" autocomplete="current-password">
          <?= error_html('current_password') ?>
        </div>
        <div class="field col-4">
          <label for="new_password">كلمة المرور الجديدة</label>
          <input class="input input-ltr" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8">
          <?= error_html('new_password') ?>
        </div>
        <div class="field col-4">
          <label for="new_password_confirmation">تأكيد كلمة المرور</label>
          <input class="input input-ltr" id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button>
      </div>
    </div>
  </form>

  <div class="panel" id="two-factor">
    <div class="panel-head"><h2><?= icon('shield-check') ?>التحقق بخطوتين</h2><span class="badge <?= $twoFactor['enabled'] ? 'badge-success badge-dot' : ($twoFactor['required'] ? 'badge-danger' : 'badge-outline') ?>"><?= $twoFactor['enabled'] ? 'مفعّل' : ($twoFactor['required'] ? 'مطلوب' : 'غير مفعّل') ?></span></div>
    <div class="panel-body">
      <?php if ($twoFactor['codes']): ?>
        <div class="recovery">
          <div class="recovery-title"><?= icon('key-round') ?>رموز الاسترجاع</div>
          <div class="recovery-codes"><?php foreach ($twoFactor['codes'] as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div>
          <button type="button" class="btn btn-sm" data-copy="<?= e(implode("\n", $twoFactor['codes'])) ?>"><?= icon('copy') ?>نسخ الرموز</button>
        </div>
      <?php endif; ?>

      <?php if ($twoFactor['setup'] !== null): ?>
        <div class="totp-setup">
          <div class="totp-qr" id="totp-qr" data-uri="<?= e((string) $twoFactor['uri']) ?>" aria-label="رمز QR لتطبيق المصادقة"></div>
          <div class="totp-side">
            <div class="field"><label>المفتاح</label><div class="input-group"><input class="input input-ltr mono" value="<?= e(trim(chunk_split($twoFactor['setup'], 4, ' '))) ?>" readonly><button type="button" class="btn" data-copy="<?= e($twoFactor['setup']) ?>" aria-label="نسخ المفتاح"><?= icon('copy') ?></button></div></div>
            <form method="post" action="<?= e(url('/account/2fa/confirm')) ?>" autocomplete="off">
              <?= csrf_field() ?>
              <div class="field"><label for="totp-code">الرمز من التطبيق</label><input class="input input-ltr otp-input" id="totp-code" name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div>
              <div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?>تأكيد التفعيل</button><button class="btn btn-ghost" form="totp-cancel">إلغاء</button></div>
            </form>
            <form method="post" action="<?= e(url('/account/2fa/cancel')) ?>" id="totp-cancel"><?= csrf_field() ?></form>
          </div>
        </div>
      <?php elseif (!$twoFactor['enabled']): ?>
        <div class="tfa-intro">
          <span class="tfa-intro-icon"><?= icon('smartphone') ?></span>
          <div class="tfa-intro-text">
            <b>رمز من تطبيق المصادقة عند كل دخول</b>
            <span>Google Authenticator أو Microsoft Authenticator أو أي تطبيق مشابه</span>
          </div>
          <form method="post" action="<?= e(url('/account/2fa/start')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><?= icon('shield-check') ?>تفعيل</button></form>
        </div>
      <?php else: ?>
        <dl class="kv"><dt>تطبيق المصادقة</dt><dd>مربوط</dd><dt>رموز الاسترجاع المتبقية</dt><dd><?= num($twoFactor['left']) ?></dd></dl>
        <div class="sub-actions mt-3">
          <button type="button" class="btn" data-modal-open="m-totp" data-action="<?= e(url('/account/2fa/codes')) ?>" data-label="رموز استرجاع جديدة"><?= icon('refresh-cw') ?>رموز استرجاع جديدة</button>
          <?php if (!$twoFactor['required']): ?><button type="button" class="btn btn-ghost text-danger" data-modal-open="m-totp" data-action="<?= e(url('/account/2fa/disable')) ?>" data-label="إيقاف التحقق بخطوتين"><?= icon('shield-ban') ?>إيقاف</button><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('shield-check') ?>الدور والصلاحيات</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الدور</dt><dd><?= e(AccountController::roleLabel($user)) ?></dd>
          <dt>عدد الصلاحيات</dt><dd><?= num(count($permissions)) ?></dd>
          <dt>آخر دخول</dt><dd><?= e(fmt_dt($user['last_login_at'])) ?></dd>
          <dt>عنوان آخر دخول</dt><dd class="mono"><?= e($user['last_login_ip'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>
    <div class="panel" data-devices-panel>
      <div class="panel-head"><h3><?= icon('fingerprint') ?>الدخول بالبصمة</h3><span class="badge"><?= num(count($devices)) ?></span></div>
      <ul class="list">
        <?php foreach ($devices as $d): ?>
          <li class="list-item">
            <span class="list-icon is-success"><?= icon($d['platform'] === 'windows' ? 'monitor' : 'smartphone') ?></span>
            <div class="list-item-main">
              <div class="list-item-title"><?= e($d['name']) ?></div>
              <div class="list-item-sub"><?= e(\App\Services\TrustedDevices::PLATFORMS[$d['platform']] ?? $d['platform']) ?> · <?= $d['last_used_at'] ? 'آخر استخدام ' . e(time_ago($d['last_used_at'])) : 'أُضيف ' . e(time_ago($d['created_at'])) ?></div>
            </div>
            <form method="post" action="<?= e(url('/account/devices/' . $d['id'] . '/revoke')) ?>" data-confirm="إلغاء الدخول بالبصمة على «<?= e($d['name']) ?>»؟">
              <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="إلغاء"><?= icon('x') ?></button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if (!$devices): ?><li class="list-item text-muted">لا توجد أجهزة.</li><?php endif; ?>
      </ul>
      <div class="panel-body" data-bio-enroll hidden style="border-top:1px solid var(--border)">
        <button type="button" class="btn btn-block" data-bio-enable><?= icon('fingerprint') ?>تفعيل البصمة على هذا الجهاز</button>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('history') ?>سجل الدخول</h3></div>
      <ul class="list">
        <?php foreach ($logins as $l): ?>
          <li class="list-item">
            <span class="list-icon <?= $l['action'] !== 'auth.failed' ? 'is-success' : 'is-danger' ?>"><?= icon($l['action'] === 'auth.device' ? 'fingerprint' : ($l['action'] === 'auth.login' ? 'log-in' : 'shield-alert')) ?></span>
            <div class="list-item-main">
              <div class="list-item-title"><?= e(Audit::label($l['action'])) ?></div>
              <div class="list-item-sub mono"><?= e($l['ip']) ?></div>
            </div>
            <div class="list-item-end"><?= e(time_ago($l['created_at'])) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$logins): ?><li class="list-item text-muted">لا توجد سجلات.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<dialog class="modal modal-sm" id="m-totp">
  <form method="post" action="">
    <?= csrf_field() ?>
    <div class="modal-head"><h3 data-totp-title></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label for="totp-pass">كلمة المرور الحالية</label><input class="input input-ltr" id="totp-pass" type="password" name="password" required autocomplete="current-password"></div></div>
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
