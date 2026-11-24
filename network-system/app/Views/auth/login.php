<?php
/** @var App\Core\View $v */
$v->title = 'تسجيل الدخول';
?>
<div class="login-head">
  <span class="login-badge"><?= icon('log-in') ?></span>
  <h1>تسجيل الدخول</h1>
  <p>مرحباً بعودتك</p>
</div>
<?php if (!empty($message)): ?>
<div class="notice notice-warning mb-3"><?= icon('clock') ?><div><?= e($message) ?></div></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/login')) ?>" class="login-form" autocomplete="on" data-login-form>
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">اسم المستخدم</label>
    <div class="input-icon">
      <?= icon('circle-user') ?>
      <input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
    </div>
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <div class="input-icon password-field">
      <?= icon('lock') ?>
      <input class="input input-ltr" id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button>
    </div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('log-in') ?>دخول</button>
  <button type="button" class="btn btn-lg btn-block login-bio" data-biometric-login hidden><?= icon('fingerprint') ?>الدخول بالبصمة</button>
  <?php if (\App\Core\Platform::enabled() && \App\Platform\PlatformSms::enabled('reset')): ?>
  <div class="login-forgot"><a href="<?= e(root_path() . '/auth/forgot') ?>">نسيت كلمة المرور؟</a></div>
  <?php endif; ?>
</form>
<?php if (\App\Core\Platform::enabled() && \App\Core\Tenant::current()): ?>
<div class="login-alt"><a href="<?= e(root_path() . '/login') ?>">الدخول إلى شبكة أخرى</a></div>
<?php endif; ?>
