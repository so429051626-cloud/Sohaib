<?php
/** @var App\Core\View $v */
$v->title = 'حساب جديد';
$v->breadcrumbs = [['الحسابات', '/admin/tenants'], ['حساب جديد', null]];
?>
<form method="post" action="<?= e(url('/admin/tenants')) ?>" class="panel">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-6"><label for="name">اسم الشبكة <span class="req">*</span></label><input class="input" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="100"><?= error_html('name') ?></div>
      <div class="field col-6"><label for="slug">اسم الحساب (الرابط) <span class="req">*</span></label><input class="input input-ltr" id="slug" name="slug" value="<?= e(old('slug')) ?>" required maxlength="40" pattern="[a-z0-9\-]{3,40}"><?= error_html('slug') ?></div>
      <div class="field col-6"><label for="owner_name">اسم المالك <span class="req">*</span></label><input class="input" id="owner_name" name="owner_name" value="<?= e(old('owner_name')) ?>" required maxlength="120"></div>
      <div class="field col-3"><label for="phone">الهاتف</label><input class="input input-ltr" id="phone" name="phone" value="<?= e(old('phone')) ?>" maxlength="30"></div>
      <div class="field col-3"><label for="email">البريد</label><input class="input input-ltr" id="email" name="email" value="<?= e(old('email')) ?>" maxlength="120"></div>
      <div class="field col-4"><label for="username">اسم المستخدم <span class="req">*</span></label><input class="input input-ltr" id="username" name="username" value="<?= e(old('username')) ?>" required maxlength="60"><?= error_html('username') ?></div>
      <div class="field col-4"><label for="password">كلمة المرور <span class="req">*</span></label><input class="input input-ltr" id="password" name="password" required minlength="8"><?= error_html('password') ?></div>
      <div class="field col-4"><label for="status">الحالة</label><select class="input" id="status" name="status"><option value="trial"<?= selected(old('status', 'trial'), 'trial') ?>>تجريبي</option><option value="active"<?= selected(old('status'), 'active') ?>>مفعّل</option></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('check') ?>إنشاء الحساب</button></div>
  </div>
</form>
