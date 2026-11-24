<?php
/** @var App\Core\View $v */
$v->title = 'لوحة المسؤول';
?>
<div class="login-head">
  <span class="login-badge"><?= icon('shield-check') ?></span>
  <h1>لوحة المسؤول</h1>
  <p>MADIX NEXUS</p>
</div>
<form method="post" action="<?= e(url('/admin/login')) ?>" class="login-form" autocomplete="on">
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">اسم المستخدم</label>
    <div class="input-icon"><?= icon('circle-user') ?><input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" required autofocus autocapitalize="off" spellcheck="false" autocomplete="username"></div>
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <div class="input-icon password-field"><?= icon('lock') ?><input class="input input-ltr" id="password" name="password" type="password" required autocomplete="current-password"><button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('shield-check') ?>دخول</button>
</form>
