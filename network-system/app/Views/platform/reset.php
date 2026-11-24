<?php
/** @var App\Core\View $v @var string $phone رقم الهاتف مع إخفاء وسطه */
$v->title = 'كلمة مرور جديدة';
$v->script('js/code.js');
?>
<div class="login-head">
  <span class="login-badge"><?= icon('lock-open') ?></span>
  <h1>كلمة مرور جديدة</h1>
  <p>أرسلنا رمز التحقق إلى <b dir="ltr"><?= e($phone) ?></b></p>
</div>
<form method="post" action="<?= e(url('/auth/reset')) ?>" class="login-form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="phone_code">رمز التحقق</label>
    <div class="input-icon"><?= icon('key-round') ?><input class="input input-ltr otp-input" id="phone_code" name="phone_code" value="<?= e(old('phone_code')) ?>" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus></div>
    <?= error_html('phone_code') ?>
  </div>
  <div class="field">
    <label for="password">كلمة المرور الجديدة</label>
    <div class="input-icon password-field"><?= icon('lock') ?><input class="input input-ltr" id="password" name="password" type="password" required minlength="8" maxlength="100" autocomplete="new-password"><button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></div>
    <?= error_html('password') ?>
  </div>
  <div class="field">
    <label for="password_confirmation">تأكيد كلمة المرور</label>
    <div class="input-icon"><?= icon('lock') ?><input class="input input-ltr" id="password_confirmation" name="password_confirmation" type="password" required minlength="8" maxlength="100" autocomplete="new-password"></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('check') ?>حفظ كلمة المرور</button>
  <button type="button" class="btn btn-lg btn-block code-resend" data-resend="<?= e(url('/auth/resend')) ?>" data-wait="60"><?= icon('send') ?>إعادة إرسال الرمز</button>
  <div class="slug-status" data-code-status></div>
</form>
<div class="login-alt"><a href="<?= e(url('/login')) ?>">العودة إلى تسجيل الدخول</a></div>
