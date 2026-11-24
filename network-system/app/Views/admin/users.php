<?php
/** @var App\Core\View $v @var array $rows @var int $total @var string $q */
use App\Controllers\AdminController;
use App\Permissions;

$v->title = 'المستخدمون';
$v->subtitle = 'كل مستخدمي الأنظمة في المنصة';
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="الاسم أو اسم المستخدم أو الحساب"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('users') ?><div class="empty-title">لا يوجد مستخدمون</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>المستخدم</th><th>الحساب</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td><div class="cell-title"><?= e($u['name']) ?></div><div class="cell-sub mono"><?= e($u['username']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></div></td>
          <td><a href="<?= e(url('/admin/tenants/' . $u['tenant_id'])) ?>"><?= e($u['tenant_name']) ?></a><div class="tenant-link">/<?= e($u['slug']) ?></div></td>
          <td class="text-sm"><?= $u['is_owner'] ? 'المالك' : e(Permissions::PRESETS[$u['role_preset'] ?? '']['label'] ?? 'موظف') ?></td>
          <td><?= $u['status'] === 'active' ? '<span class="badge badge-success">فعّال</span>' : '<span class="badge badge-outline">موقوف</span>' ?> <span class="badge <?= AdminController::STATUS[$u['tenant_status']][1] ?>"><?= AdminController::STATUS[$u['tenant_status']][0] ?></span></td>
          <td class="nowrap text-sm"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > count($rows)): ?><div class="panel-foot text-sm text-muted">يُعرض <?= num(count($rows)) ?> من <?= num($total) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
