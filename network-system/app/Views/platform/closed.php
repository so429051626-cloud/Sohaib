<?php
/** @var App\Core\View $v */
use App\Core\Platform;

$v->title = 'إنشاء حساب';
?>
<div class="blocked-card">
  <div class="blocked-icon"><?= icon('user-plus') ?></div>
  <h1>التسجيل متوقف حالياً</h1>
  <p>لإنشاء حساب جديد تواصل معنا.</p>
  <a class="btn btn-lg btn-block btn-whatsapp" href="<?= e(Platform::whatsappLink('مرحباً، أريد إنشاء حساب')) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>تواصل عبر واتساب</a>
</div>
