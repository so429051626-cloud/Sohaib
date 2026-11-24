<?php
/** @var App\Core\View $v @var string $name اسم المستخدم الذي أدخل كلمة المرور */
$v->title = 'التحقق بخطوتين';
$v->script('js/otp.js');
?>
<div class="login-head">
  <span class="login-badge"><?= icon('shield-check') ?></span>
  <h1>التحقق بخطوتين</h1>
  <p>الرمز من تطبيق المصادقة</p>
  <span class="tfa-user"><?= icon('circle-user') ?><?= e($name) ?></span>
</div>
<form method="post" action="<?= e(url('/login/verify')) ?>" class="login-form" autocomplete="off" data-otp-form data-otp-done="تم التحقق." data-otp-delay="1500" data-tfa-app>
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">رمز التحقق</label>
    <?php /* حقل واحد يعمل دون جافاسكربت (ويقبل رمز الاسترجاع)، ويُستبدل بستة مربعات عند توفرها */ ?>
    <div class="input-icon" data-otp-fallback><?= icon('key-round') ?><input class="input input-ltr otp-input" id="code" name="code" required maxlength="12" autocomplete="one-time-code" spellcheck="false" autofocus></div>
    <div class="otp" data-otp hidden>
      <div class="otp-ring" data-otp-ring dir="ltr">
        <?php for ($i = 1; $i <= 6; $i++): ?><input class="otp-box" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" aria-label="الرقم <?= $i ?> من 6"><?php endfor; ?>
      </div>
      <span class="otp-done" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
    </div>
    <div class="slug-status" data-otp-status aria-live="polite"></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit" data-otp-submit><?= icon('check') ?>تأكيد</button>
</form>
<form method="post" action="<?= e(url('/login/verify')) ?>" class="login-form" autocomplete="off" data-tfa-recovery hidden>
  <?= csrf_field() ?>
  <div class="field">
    <label for="recovery">رمز الاسترجاع</label>
    <div class="input-icon"><?= icon('key-round') ?><input class="input input-ltr mono" id="recovery" name="code" required maxlength="12" placeholder="xxxx-xxxx" autocomplete="off" autocapitalize="off" spellcheck="false"></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('check') ?>تأكيد</button>
</form>
<div class="tfa-switch" data-tfa-switch hidden>
  <button type="button" data-tfa-to="recovery">استخدام رمز استرجاع</button>
  <button type="button" data-tfa-to="app" hidden>استخدام تطبيق المصادقة</button>
</div>
<div class="login-alt"><a href="<?= e(url('/login')) ?>">العودة إلى تسجيل الدخول</a></div>
<script>
(function () {
  var app = document.querySelector('[data-tfa-app]'), rec = document.querySelector('[data-tfa-recovery]'), sw = document.querySelector('[data-tfa-switch]');
  if (!app || !rec || !sw) { return; }
  sw.hidden = false;
  sw.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tfa-to]');
    if (!b) { return; }
    var toRec = b.getAttribute('data-tfa-to') === 'recovery';
    app.hidden = toRec; rec.hidden = !toRec;
    sw.querySelector('[data-tfa-to="recovery"]').hidden = toRec;
    sw.querySelector('[data-tfa-to="app"]').hidden = !toRec;
    var f = (toRec ? rec : app).querySelector('input:not([type=hidden])');
    if (f) { f.focus(); }
  });
})();
</script>
