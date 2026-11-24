<?php
/** @var App\Core\View $v */
$v->title = 'استعادة كلمة المرور';
?>
<div class="login-head">
  <span class="login-badge"><?= icon('key-round') ?></span>
  <h1>استعادة كلمة المرور</h1>
  <p>اسم المستخدم ورقم الهاتف المسجّل في الحساب</p>
</div>
<form method="post" action="<?= e(url('/auth/forgot')) ?>" class="login-form" autocomplete="on">
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">اسم المستخدم</label>
    <div class="input-icon"><?= icon('circle-user') ?><input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" required autofocus maxlength="60" autocapitalize="off" spellcheck="false" autocomplete="username"></div>
  </div>
  <div class="field">
    <label for="phone">رقم الهاتف</label>
    <div class="input-icon"><?= icon('phone') ?><input class="input input-ltr" id="phone" name="phone" value="<?= e(old('phone')) ?>" required maxlength="30" inputmode="tel" autocomplete="tel" placeholder="05XXXXXXXX"></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('send') ?>إرسال رمز التحقق</button>
</form>
<div class="login-alt"><a href="<?= e(url('/login')) ?>">العودة إلى تسجيل الدخول</a></div>
