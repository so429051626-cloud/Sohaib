<?php
/** @var App\Core\View $v */
$v->title = 'خطأ ' . $status;
$icons = [403 => 'shield-ban', 404 => 'circle-help', 419 => 'clock', 429 => 'hourglass'];
?>
<div class="<?= isset($inApp) ? 'panel' : '' ?>">
  <div class="empty" style="padding: 36px 10px">
    <?= icon($icons[$status] ?? 'triangle-alert') ?>
    <div class="empty-title"><?= e($status) ?></div>
    <div><?= e($message) ?></div>
    <?php if (!empty($ref)): ?><div class="text-sm">رقم المرجع: <span class="mono"><?= e($ref) ?></span></div><?php endif; ?>
    <div class="flex gap-2 mt-2">
      <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('house') ?>الصفحة الرئيسية</a>
      <a class="btn" href="javascript:history.back()"><?= icon('chevron-right') ?>رجوع</a>
    </div>
    <?php if (!empty($debug)): ?><pre class="code-block mt-3" dir="auto" style="max-width:100%;text-align:start;white-space:pre-wrap"><?= e($debug) ?></pre><?php endif; ?>
  </div>
</div>
