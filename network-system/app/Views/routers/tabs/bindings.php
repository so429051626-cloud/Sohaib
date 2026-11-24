<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
$types = ['blocked' => ['محظور', 'badge-danger'], 'bypassed' => ['متجاوز لصفحة الدخول', 'badge-violet'], 'regular' => ['عادي', 'badge']];
?>
<div class="panel-body">
  <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="tab" value="bindings"><input type="hidden" name="op" value="binding_add">
    <div class="field col-4"><label>عنوان MAC</label><input class="input input-ltr" name="mac" placeholder="AA:BB:CC:DD:EE:FF" required></div>
    <div class="field col-3">
      <label>النوع</label>
      <select class="input" name="type">
        <option value="blocked">حظر الجهاز</option>
        <option value="bypassed">تجاوز صفحة الدخول</option>
        <option value="regular">عادي</option>
      </select>
    </div>
    <div class="field col-3"><label>تعليق</label><input class="input" name="comment"></div>
    <div class="field col-2" style="justify-content:flex-end"><button class="btn btn-primary"><?= icon('plus') ?>إضافة</button></div>
  </form>
</div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>MAC</th><th>العنوان</th><th>النوع</th><th>الخادم</th><th>تعليق</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b):
        $t = $types[$b['type'] ?? 'regular'] ?? $types['regular']; ?>
      <tr>
        <td><span class="mono"><?= e($b['mac-address'] ?? '—') ?></span></td>
        <td><span class="mono"><?= e($b['address'] ?? '—') ?></span></td>
        <td><span class="badge <?= $t[1] ?>"><?= e($t[0]) ?></span></td>
        <td><?= e($b['server'] ?? 'all') ?></td>
        <td><?= e($b['comment'] ?? '') ?></td>
        <td class="w-actions">
          <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="حذف هذا الربط؟">
            <?= csrf_field() ?><input type="hidden" name="tab" value="bindings"><input type="hidden" name="op" value="binding_remove"><input type="hidden" name="ref" value="<?= e($b['.id']) ?>">
            <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= icon('link') ?><div>لا توجد أجهزة مربوطة.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
