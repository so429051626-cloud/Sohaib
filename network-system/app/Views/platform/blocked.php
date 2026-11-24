<?php
/** @var App\Core\View $v @var array $tenant */
use App\Core\Platform;

$titles = [
    'trial' => ['انتهت الفترة التجريبية', 'clock', ''],
    'expired' => ['انتهت مدة الاشتراك', 'clock', ''],
    'suspended' => ['الحساب موقوف', 'ban', 'is-danger'],
    'cancelled' => ['الحساب ملغى', 'circle-x', 'is-danger'],
];
[$title, $ic, $cls] = $titles[$tenant['status']] ?? $titles['expired'];
if ($tenant['status'] === 'expired' && str_contains((string) $tenant['status_reason'], 'التجريبية')) {
    $title = 'انتهت الفترة التجريبية';
}
$v->title = $title;
?>
<div class="blocked-card">
  <div class="blocked-icon <?= $cls ?>"><?= icon($ic) ?></div>
  <h1><?= e($title) ?></h1>
  <p><?= e($tenant['status_reason'] && !in_array($tenant['status_reason'], ['انتهت الفترة التجريبية', 'انتهت مدة الاشتراك'], true) ? $tenant['status_reason'] : 'لتفعيل حسابك ومتابعة استخدام النظام تواصل معنا.') ?></p>
  <a class="btn btn-lg btn-block btn-whatsapp" href="<?= e(Platform::whatsappLink('مرحباً، أريد تفعيل حساب ' . $tenant['name'] . ' (' . $tenant['slug'] . ')')) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>تواصل عبر واتساب</a>
</div>
