<?php
/** @var App\Core\View $v */
use App\Core\Auth;
use App\Permissions;

$v->title = 'الموظفون';
$v->subtitle = 'حسابات الدخول والصلاحيات';
$v->actions = '<a class="btn btn-primary" href="' . e(url('/employees/create')) . '">' . icon('user-plus') . 'إضافة موظف</a>';
?>
<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>الموظف</th>
          <th>اسم الدخول</th>
          <th>الدور</th>
          <th class="num">الصلاحيات</th>
          <th>أجهزة MikroTik</th>
          <th>الحالة</th>
          <th>آخر دخول</th>
          <th class="w-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <span class="avatar"><?= e(mb_substr($emp['name'], 0, 1)) ?></span>
              <div>
                <div class="cell-title"><?= e($emp['name']) ?></div>
                <?php if ($emp['phone']): ?><div class="cell-sub mono"><?= e($emp['phone']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><span class="mono"><?= e($emp['username']) ?></span></td>
          <td>
            <?php if ($emp['is_owner']): ?><span class="badge badge-violet"><?= icon('shield-check') ?>المالك</span>
            <?php else: ?><?= e(Permissions::PRESETS[$emp['role_preset'] ?? '']['label'] ?? 'مخصص') ?><?php endif; ?>
          </td>
          <td class="num"><?= $emp['is_owner'] ? 'الكل' : num($emp['perm_count']) ?></td>
          <td><?= $emp['is_owner'] || $emp['router_scope'] === 'all' ? 'جميع أجهزة MikroTik' : num($emp['router_count']) . ' جهاز MikroTik' ?></td>
          <td><?= $emp['status'] === 'active' ? '<span class="badge badge-success badge-dot">نشط</span>' : '<span class="badge badge-danger badge-dot">موقوف</span>' ?><?php if ((int) $emp['totp_enabled'] === 1): ?> <span class="badge badge-primary" title="التحقق بخطوتين مفعّل"><?= icon('shield-check') ?>خطوتان</span><?php endif; ?></td>
          <td class="nowrap"><?= e(time_ago($emp['last_login_at'])) ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (!$emp['is_owner'] || Auth::isOwner()): ?>
              <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/employees/' . $emp['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a>
              <form method="post" action="<?= e(url('/employees/' . $emp['id'] . '/logout')) ?>" data-confirm="إنهاء جميع جلسات هذا الموظف؟" data-confirm-type="primary">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-icon btn-sm" title="إنهاء الجلسات"><?= icon('log-out') ?></button>
              </form>
              <?php if ((int) $emp['totp_enabled'] === 1 && (int) $emp['id'] !== Auth::id()): ?>
              <form method="post" action="<?= e(url('/employees/' . $emp['id'] . '/2fa/reset')) ?>" data-confirm="إلغاء التحقق بخطوتين للمستخدم <?= e($emp['name']) ?>؟ سيدخل بكلمة المرور فقط حتى يفعّله من جديد.">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-icon btn-sm" title="إلغاء التحقق بخطوتين"><?= icon('shield-ban') ?></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
              <?php if (!$emp['is_owner'] && (int) $emp['id'] !== Auth::id()): ?>
              <form method="post" action="<?= e(url('/employees/' . $emp['id'] . '/delete')) ?>" data-confirm="حذف الموظف <?= e($emp['name']) ?> نهائياً؟">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
