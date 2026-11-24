<?php
/** @var App\Core\View $v @var int $trialDays */
$v->title = 'إنشاء حساب';
$v->bodyClass = 'is-wide-card';
$v->script('js/signup.js');
?>
<div class="login-head">
  <span class="login-badge"><?= icon('user-plus') ?></span>
  <h1>إنشاء حساب جديد</h1>
  <p>تجربة مجانية لمدة <?= num($trialDays) ?> يوماً</p>
</div>
<form method="post" action="<?= e(url('/signup')) ?>" class="login-form" autocomplete="on" data-signup data-check="<?= e(url('/signup/check')) ?>" data-user-check="<?= e(url('/signup/username')) ?>">
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="field col-12">
      <label for="name">اسم الشبكة</label>
      <div class="input-icon"><?= icon('radio-tower') ?><input class="input" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="100" autofocus></div>
      <?= error_html('name') ?>
    </div>
    <div class="field col-12">
      <label for="slug">رابط حسابك</label>
      <div class="slug-field"><span><?= e(preg_replace('#^https?://#', '', absolute_url('/'))) ?></span><input id="slug" name="slug" value="<?= e(old('slug')) ?>" required maxlength="40" autocapitalize="off" spellcheck="false" pattern="[a-z0-9\-]{3,40}" data-slug></div>
      <div class="slug-status" data-slug-status></div>
      <?= error_html('slug') ?>
    </div>
    <div class="field col-6">
      <label for="owner_name">الاسم الكامل</label>
      <div class="input-icon"><?= icon('circle-user') ?><input class="input" id="owner_name" name="owner_name" value="<?= e(old('owner_name')) ?>" required maxlength="120" autocomplete="name"></div>
      <?= error_html('owner_name') ?>
    </div>
    <div class="field col-6">
      <label for="phone">رقم الهاتف</label>
      <div class="input-icon"><?= icon('phone') ?><input class="input input-ltr" id="phone" name="phone" value="<?= e(old('phone')) ?>" required maxlength="30" inputmode="tel" autocomplete="tel" placeholder="05XXXXXXXX"></div>
      <?= error_html('phone') ?>
    </div>
    <div class="field col-12">
      <label for="email">البريد الإلكتروني <span class="text-muted">(اختياري)</span></label>
      <div class="input-icon"><?= icon('mail') ?><input class="input input-ltr" id="email" name="email" type="email" value="<?= e(old('email')) ?>" maxlength="120" autocomplete="email"></div>
    </div>
    <div class="field col-12">
      <label for="username">اسم المستخدم للدخول</label>
      <div class="input-icon" data-user-field><?= icon('user') ?><input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" required maxlength="60" autocapitalize="off" spellcheck="false" autocomplete="username" data-username></div>
      <div class="slug-status" data-user-status aria-live="polite"></div>
      <?= error_html('username') ?>
    </div>
    <div class="field col-6">
      <label for="password">كلمة المرور</label>
      <div class="input-icon password-field"><?= icon('lock') ?><input class="input input-ltr" id="password" name="password" type="password" required minlength="8" autocomplete="new-password"><button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار"><?= icon('eye') ?></button></div>
      <?= error_html('password') ?>
    </div>
    <div class="field col-6">
      <label for="password_confirmation">تأكيد كلمة المرور</label>
      <div class="input-icon"><?= icon('lock') ?><input class="input input-ltr" id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></div>
    </div>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit"><?= icon('user-plus') ?>إنشاء الحساب</button>
</form>
<div class="login-alt">لديك حساب؟ <a href="<?= e(url('/login')) ?>">تسجيل الدخول</a></div>
