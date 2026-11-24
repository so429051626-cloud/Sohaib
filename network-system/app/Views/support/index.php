<?php
/** @var App\Core\View $v @var array $rows */
use App\Platform\Support;

$v->title = 'الدعم';
$v->actions = '<button type="button" class="btn btn-primary" data-modal-open="m-ticket">' . icon('plus') . 'تذكرة جديدة</button>';
?>
<div class="panel">
  <?php if (!$rows): ?><div class="empty"><?= icon('message-circle') ?><div class="empty-title">لا توجد تذاكر</div></div><?php else: ?>
  <ul class="list">
    <?php foreach ($rows as $t): [$sl, $sc] = Support::STATUS[$t['status']]; ?>
    <li class="list-item">
      <span class="list-icon <?= (int) $t['tenant_unread'] ? 'is-success' : '' ?>"><?= icon('message-circle') ?></span>
      <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/support/' . $t['id'])) ?>"><?= e($t['subject']) ?></a>
        <div class="list-item-sub"><?= e(Support::CATEGORIES[$t['category']] ?? '') ?> · <?= e(time_ago($t['last_message_at'])) ?></div></div>
      <div class="list-item-end"><?php if ((int) $t['tenant_unread']): ?><span class="badge badge-info">رد جديد</span><?php endif; ?><span class="badge <?= $sc ?>"><?= $sl ?></span></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<dialog class="modal" id="m-ticket">
  <form method="post" action="<?= e(url('/support')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('message-circle') ?>تذكرة دعم</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-8"><label>العنوان</label><input class="input" name="subject" value="<?= e(old('subject')) ?>" required maxlength="160"><?= error_html('subject') ?></div>
        <div class="field col-4"><label>التصنيف</label><select class="input" name="category"><?php foreach (Support::CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= selected(old('category', 'general'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-12"><label>التفاصيل</label><textarea class="input" name="body" rows="6" maxlength="5000" required><?= e(old('body')) ?></textarea></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('send') ?>إرسال</button></div>
  </form>
</dialog>
