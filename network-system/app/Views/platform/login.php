<?php
/** @var App\Core\View $v @var array $networks */
$v->title = 'تسجيل الدخول';
$status = ['trial' => 'تجريبي', 'active' => 'مفعّل', 'expired' => 'منتهٍ', 'suspended' => 'موقوف'];
?>
<?php if ($networks): ?>
<div class="login-head">
  <span class="login-badge"><?= icon('network') ?></span>
  <h1>اختر الشبكة</h1>
  <p>حسابك مرتبط بأكثر من شبكة</p>
</div>
<form method="post" action="<?= e(url('/login/choose')) ?>" class="login-form">
  <?= csrf_field() ?>
  <div class="net-choices">
    <?php foreach ($networks as $n): ?>
    <button type="submit" name="tenant_id" value="<?= (int) $n['id'] ?>" class="net-choice">
      <span class="net-choice-mark"><?= e(mb_substr(trim((string) $n['name']), 0, 1)) ?></span>
      <span class="net-choice-text"><b><?= e($n['name']) ?></b><small>/<?= e($n['slug']) ?> · <?= e($status[$n['status']] ?? '') ?></small></span>
      <?= icon('chevron-left') ?>
    </button>
    <?php endforeach; ?>
  </div>
</form>
<div class="login-alt"><a href="<?= e(url('/login')) ?>">الدخول بمستخدم آخر</a></div>
<?php else: ?>
<div class="login-head">
  <span class="login-badge"><?= icon('log-in') ?></span>
  <h1>تسجيل الدخول</h1>
  <p>مرحباً بعودتك</p>
</div>
<form method="post" action="<?= e(url('/login')) ?>" class="login-form" autocomplete="on">
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">اسم المستخدم</label>
    <div class="input-icon"><?= icon('circle-user') ?><input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" required autofocus autocapitalize="off" spellcheck="false" autocomplete="username"></div>
  </div>
  <div class="field">
    <label for="password">كلمة المرور</label>
    <div class="input-icon password-field"><?= icon('lock') ?><input class="input input-ltr" id="password" name="password" type="password" required autocomplete="current-password"><button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار كلمة المرور"><?= icon('eye') ?></button></div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('log-in') ?>دخول</button>
  <?php if (\App\Platform\PlatformSms::enabled('reset')): ?>
  <div class="login-forgot"><a href="<?= e(url('/auth/forgot')) ?>">نسيت كلمة المرور؟</a></div>
  <?php endif; ?>
</form>
<div class="login-alt">ليس لديك حساب؟ <a href="<?= e(url('/signup')) ?>">أنشئ حساباً مجاناً</a></div>
<?php endif; ?>
