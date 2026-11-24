<?php
/** @var App\Core\View $v */
$v->title = 'المناطق';
?>
<div class="grid grid-main-side">
  <div class="panel">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>المنطقة</th><th class="num">أجهزة MikroTik</th><th class="num">المشتركون</th><th class="num">الموزعون</th><th>ملاحظات</th><th class="w-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($areas as $a): ?>
          <tr>
            <td class="cell-title"><?= e($a['name']) ?></td>
            <td class="num"><a href="<?= e(url('/routers', ['area' => $a['id']])) ?>"><?= num($a['routers']) ?></a></td>
            <td class="num"><a href="<?= e(url('/subscribers', ['area' => $a['id']])) ?>"><?= num($a['subscribers']) ?></a></td>
            <td class="num"><?= num($a['distributors']) ?></td>
            <td class="text-muted"><?= e($a['notes'] ?? '') ?></td>
            <td class="w-actions">
              <div class="row-actions">
                <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/areas', ['edit' => $a['id']])) ?>" title="تعديل"><?= icon('pencil') ?></a>
                <form method="post" action="<?= e(url('/areas/' . $a['id'] . '/delete')) ?>" data-confirm="حذف المنطقة <?= e($a['name']) ?>؟ تبقى أجهزة MikroTik والمشتركون دون منطقة.">
                  <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$areas): ?><tr><td colspan="6"><div class="empty"><?= icon('map-pin') ?><div class="empty-title">لا توجد مناطق</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <form method="post" action="<?= e(url('/areas')) ?>" class="panel">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="panel-head"><h3><?= icon($edit ? 'pencil' : 'plus') ?><?= $edit ? 'تعديل منطقة' : 'منطقة جديدة' ?></h3></div>
    <div class="panel-body">
      <div class="field mb-2">
        <label for="name">الاسم <span class="req">*</span></label>
        <input class="input" id="name" name="name" value="<?= e(old('name', $edit['name'] ?? '')) ?>" required>
        <?= error_html('name') ?>
      </div>
      <div class="field mb-2">
        <label for="notes">ملاحظات</label>
        <input class="input" id="notes" name="notes" value="<?= e(old('notes', $edit['notes'] ?? '')) ?>">
      </div>
      <div class="flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <?php if ($edit): ?><a class="btn" href="<?= e(url('/areas')) ?>">إلغاء</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>
