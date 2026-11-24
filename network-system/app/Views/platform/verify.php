<?php
/** @var App\Core\View $v @var string $phone رقم الهاتف مع إخفاء وسطه */
$v->title = 'تأكيد رقم الهاتف';
$v->script('js/code.js');
$v->script('js/otp.js');
?>
<div class="login-head">
  <span class="login-badge"><?= icon('shield-check') ?></span>
  <h1>تأكيد رقم الهاتف</h1>
  <p>أرسلنا رمز التحقق إلى <b dir="ltr"><?= e($phone) ?></b></p>
</div>
<form method="post" action="<?= e(url('/signup/verify')) ?>" class="login-form" autocomplete="off" data-otp-form>
  <?= csrf_field() ?>
  <div class="field">
    <label for="phone_code">رمز التحقق</label>
    <?php /* حقل واحد يعمل دون جافاسكربت، ويُستبدل بستة مربعات عند توفرها */ ?>
    <div class="input-icon" data-otp-fallback><?= icon('key-round') ?><input class="input input-ltr otp-input" id="phone_code" name="phone_code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus></div>
    <div class="otp" data-otp hidden>
      <div class="otp-ring" data-otp-ring dir="ltr">
        <?php for ($i = 1; $i <= 6; $i++): ?><input class="otp-box" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 1 ? 'one-time-code' : 'off' ?>" aria-label="الرقم <?= $i ?> من 6"><?php endfor; ?>
      </div>
      <span class="otp-done" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
    </div>
    <div class="slug-status" data-otp-status aria-live="polite"></div>
    <?= error_html('phone_code') ?>
  </div>
  <button type="submit" class="btn btn-primary btn-lg btn-block login-submit" data-otp-submit><?= icon('user-check') ?>تأكيد وإنشاء الحساب</button>
  <button type="button" class="btn btn-lg btn-block code-resend" data-resend="<?= e(url('/signup/code')) ?>" data-wait="60"><?= icon('send') ?>إعادة إرسال الرمز</button>
  <div class="slug-status" data-code-status></div>
</form>
<div class="login-alt"><a href="<?= e(url('/signup', ['edit' => 1])) ?>">تعديل البيانات</a></div>
