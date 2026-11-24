<?php
/** @var App\Core\View $v */
$v->title = 'استيراد حساب';
$v->breadcrumbs = [['الحسابات', '/admin/tenants'], [$v->title, null]];
?>
<form method="post" action="<?= e(url('/admin/tenants/import')) ?>" class="panel" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="panel-head"><h3><?= icon('cloud-upload') ?>من نسخة احتياطية لنظام سابق</h3></div>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-6"><label for="slug">اسم الحساب (الرابط) <span class="req">*</span></label><input class="input input-ltr" id="slug" name="slug" value="<?= e(old('slug')) ?>" required maxlength="40" pattern="[a-z0-9\-]{3,40}"><?= error_html('slug') ?></div>
      <div class="field col-6"><label for="status">الحالة</label><select class="input" id="status" name="status"><option value="active"<?= selected(old('status', 'active'), 'active') ?>>مفعّل</option><option value="trial"<?= selected(old('status'), 'trial') ?>>تجريبي</option></select></div>
      <div class="field col-6"><label for="backup">ملف النسخة الاحتياطية (‎.sql.gz) <span class="req">*</span></label><input class="input" id="backup" type="file" name="backup" accept=".gz,.sql" required style="padding-top:7px"><?= error_html('backup') ?></div>
      <div class="field col-6"><label for="old_config">ملف config.php للنظام السابق</label><input class="input" id="old_config" type="file" name="old_config" accept=".php" style="padding-top:7px"><?= error_html('old_config') ?></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('cloud-upload') ?>استيراد</button><a class="btn btn-ghost" href="<?= e(url('/admin/tenants')) ?>">إلغاء</a></div>
  </div>
</form>
