<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
?>
<div class="panel-body">
  <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="tab" value="walled"><input type="hidden" name="op" value="walled_add">
    <div class="field col-6"><label>النطاق المسموح قبل تسجيل الدخول</label><input class="input input-ltr" name="host" placeholder="*.example.com" required></div>
    <div class="field col-4"><label>تعليق</label><input class="input" name="comment"></div>
    <div class="field col-2" style="justify-content:flex-end"><button class="btn btn-primary"><?= icon('plus') ?>إضافة</button></div>
  </form>
</div>
<div class="panel-head"><h3><?= icon('globe') ?>نطاقات HTTP</h3></div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>النطاق</th><th>الإجراء</th><th class="num">الاستخدام</th><th>تعليق</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $w): ?>
      <tr>
        <td><span class="mono"><?= e($w['dst-host'] ?? '—') ?></span></td>
        <td><?= ($w['action'] ?? 'allow') === 'allow' ? '<span class="badge badge-success">سماح</span>' : '<span class="badge badge-danger">منع</span>' ?></td>
        <td class="num"><?= num($w['hits'] ?? 0) ?></td>
        <td><?= e($w['comment'] ?? '') ?></td>
        <td class="w-actions">
          <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="حذف هذا العنصر؟">
            <?= csrf_field() ?><input type="hidden" name="tab" value="walled"><input type="hidden" name="op" value="walled_remove"><input type="hidden" name="kind" value="http"><input type="hidden" name="ref" value="<?= e($w['.id']) ?>">
            <button class="btn btn-ghost btn-icon btn-sm text-danger"><?= icon('trash-2') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty"><?= icon('globe') ?><div>لا توجد عناصر.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<div class="panel-head"><h3><?= icon('shield') ?>عناوين IP</h3></div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الوجهة</th><th>العنوان</th><th>الإجراء</th><th>تعليق</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($ips as $w): ?>
      <tr>
        <td><span class="mono"><?= e($w['dst-host'] ?? '—') ?></span></td>
        <td><span class="mono"><?= e($w['dst-address'] ?? '—') ?></span></td>
        <td><?= e($w['action'] ?? 'accept') ?></td>
        <td><?= e($w['comment'] ?? '') ?></td>
        <td class="w-actions">
          <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="حذف هذا العنصر؟">
            <?= csrf_field() ?><input type="hidden" name="tab" value="walled"><input type="hidden" name="op" value="walled_remove"><input type="hidden" name="kind" value="ip"><input type="hidden" name="ref" value="<?= e($w['.id']) ?>">
            <button class="btn btn-ghost btn-icon btn-sm text-danger"><?= icon('trash-2') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ips): ?><tr><td colspan="5"><div class="empty"><?= icon('shield') ?><div>لا توجد عناصر.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
