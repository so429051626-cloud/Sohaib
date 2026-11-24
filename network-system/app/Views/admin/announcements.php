<?php
/** @var App\Core\View $v @var array $rows @var array $targets @var array $tenants */
use App\Platform\Support;

$v->title = 'إعلانات للزبائن';
$v->actions = '<button type="button" class="btn btn-primary" data-modal-open="m-ann" data-ann="">' . icon('plus') . 'إعلان جديد</button>';
$now = date('Y-m-d H:i:s');
?>
<div class="panel">
  <?php if (!$rows): ?><div class="empty"><?= icon('megaphone') ?><div class="empty-title">لا توجد إعلانات</div></div><?php else: ?>
  <ul class="list">
    <?php foreach ($rows as $a):
      $live = (int) $a['is_active'] && ($a['starts_at'] === null || $a['starts_at'] <= $now) && ($a['ends_at'] === null || $a['ends_at'] >= $now);
      $payload = $a + ['tenants' => $targets[(int) $a['id']] ?? []]; ?>
    <li class="list-item">
      <span class="list-icon is-<?= $a['level'] === 'warning' ? 'warning' : ($a['level'] === 'success' ? 'success' : 'info') ?>"><?= icon('megaphone') ?></span>
      <div class="list-item-main">
        <div class="list-item-title"><?= e($a['title']) ?></div>
        <div class="list-item-sub"><?= e(Support::LEVELS[$a['level']]) ?> · <?= $a['audience'] === 'all' ? 'كل الحسابات' : num((int) $a['targets']) . ' حساب' ?><?= $a['ends_at'] ? ' · حتى ' . e(substr((string) $a['ends_at'], 0, 10)) : '' ?></div>
        <?php if ($a['body']): ?><div class="text-sm text-muted"><?= e($a['body']) ?></div><?php endif; ?>
      </div>
      <div class="list-item-end">
        <span class="badge <?= $live ? 'badge-success' : 'badge-outline' ?>"><?= $live ? 'يظهر الآن' : ((int) $a['is_active'] ? 'خارج المدة' : 'متوقف') ?></span>
        <form method="post" action="<?= e(url('/admin/announcements/' . $a['id'] . '/toggle')) ?>" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="<?= (int) $a['is_active'] ? 'إيقاف' : 'تشغيل' ?>"><?= icon((int) $a['is_active'] ? 'pause' : 'play') ?></button></form>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-open="m-ann" data-ann="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>" title="تعديل"><?= icon('pencil') ?></button>
        <form method="post" action="<?= e(url('/admin/announcements/' . $a['id'] . '/delete')) ?>" data-confirm="حذف الإعلان؟" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<dialog class="modal" id="m-ann">
  <form method="post" action="<?= e(url('/admin/announcements')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-head"><h3><?= icon('megaphone') ?>إعلان</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-8"><label>العنوان</label><input class="input" name="title" required maxlength="120"></div>
        <div class="field col-4"><label>النوع</label><select class="input" name="level"><?php foreach (Support::LEVELS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field col-12"><label>النص</label><textarea class="input" name="body" rows="3" maxlength="600"></textarea></div>
        <div class="field col-6"><label>يبدأ</label><input class="input" type="date" name="starts_at"></div>
        <div class="field col-6"><label>ينتهي</label><input class="input" type="date" name="ends_at"></div>
        <div class="field col-12"><label>المستهدفون</label>
          <div class="segmented"><label><input type="radio" name="audience" value="all" checked><span>كل الحسابات</span></label><label><input type="radio" name="audience" value="selected"><span>حسابات محددة</span></label></div>
        </div>
        <div class="field col-12" data-show-when="audience=selected" hidden>
          <div class="check-grid check-grid-scroll">
            <?php foreach ($tenants as $tn): ?><label class="check"><input type="checkbox" name="tenants[]" value="<?= (int) $tn['id'] ?>"><span><?= e($tn['name']) ?></span></label><?php endforeach; ?>
          </div>
        </div>
        <div class="field col-12"><label class="switch"><input type="checkbox" name="is_active" value="1"><span class="track"></span><span>مفعّل</span></label></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-ann').addEventListener('modal:open', function (e) {
  var f = this.querySelector('form'), raw = e.detail.trigger.getAttribute('data-ann'), a = raw ? JSON.parse(raw) : null;
  f.elements.id.value = a ? a.id : ''; f.elements.title.value = a ? a.title : ''; f.elements.body.value = a && a.body ? a.body : '';
  f.elements.level.value = a ? a.level : 'info';
  f.elements.starts_at.value = a && a.starts_at ? a.starts_at.slice(0, 10) : ''; f.elements.ends_at.value = a && a.ends_at ? a.ends_at.slice(0, 10) : '';
  f.querySelectorAll('input[name=audience]').forEach(function (r) { r.checked = r.value === (a ? a.audience : 'all'); });
  var ids = a ? a.tenants.map(String) : [];
  f.querySelectorAll('input[name="tenants[]"]').forEach(function (c) { c.checked = ids.indexOf(c.value) !== -1; });
  f.elements.is_active.checked = !a || a.is_active == 1;
  SN.syncConditional();
});
</script>
