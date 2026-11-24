<?php
/** @var App\Core\View $v */
$v->title = 'التحقق بخطوتين';
?>
<div class="login-head">
  <span class="login-badge"><?= icon('key-round') ?></span>
  <h1>التحقق بخطوتين</h1>
  <p>رمز تطبيق المصادقة أو رمز استرجاع</p>
</div>
<form method="post" action="<?= e(url('/admin/login/verify')) ?>" class="login-form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="code">رمز التحقق</label>
    <div class="input-icon"><?= icon('key-round') ?><input class="input input-ltr otp-input" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12" spellcheck="false"></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('check') ?>تأكيد</button>
</form>
<div class="login-alt"><a href="<?= e(url('/admin/login')) ?>">العودة إلى تسجيل الدخول</a></div>
