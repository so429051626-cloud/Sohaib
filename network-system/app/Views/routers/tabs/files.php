<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
?>
<div class="panel-body">
  <div class="flex gap-2 wrap">
    <form method="post" action="<?= e(url('/routers/' . $rid . '/backup')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="backup"><button class="btn btn-primary"><?= icon('archive') ?>نسخة احتياطية كاملة (.backup)</button></form>
    <form method="post" action="<?= e(url('/routers/' . $rid . '/backup')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="export"><button class="btn"><?= icon('file-code-2') ?>تصدير الإعدادات (.rsc)</button></form>
  </div>
</div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الملف</th><th>النوع</th><th class="num">الحجم</th><th>التاريخ</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $f): ?>
      <tr>
        <td><span class="mono"><?= e($f['name'] ?? '') ?></span></td>
        <td><?= e($f['type'] ?? '') ?></td>
        <td class="num"><?= isset($f['size']) && is_numeric($f['size']) ? e(fmt_bytes((int) $f['size'])) : e($f['size'] ?? '') ?></td>
        <td class="nowrap text-sm"><?= e($f['creation-time'] ?? '') ?></td>
        <td class="w-actions">
          <?php if (($f['type'] ?? '') !== 'directory' && ($f['type'] ?? '') !== 'disk'): ?>
          <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="حذف الملف <?= e($f['name'] ?? '') ?> من جهاز MikroTik؟">
            <?= csrf_field() ?><input type="hidden" name="tab" value="files"><input type="hidden" name="op" value="file_remove"><input type="hidden" name="ref" value="<?= e($f['.id']) ?>">
            <button class="btn btn-ghost btn-icon btn-sm text-danger"><?= icon('trash-2') ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty"><?= icon('archive') ?><div>لا توجد ملفات.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
