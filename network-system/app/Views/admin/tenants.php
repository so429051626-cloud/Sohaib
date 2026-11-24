<?php
/** @var App\Core\View $v @var array $rows @var array $f @var array $counts @var App\Core\Request $request */
use App\Controllers\AdminController;
use App\Core\Tenant;

$v->title = 'الحسابات';
$v->actions = '<a class="btn" href="' . e(url('/admin/tenants/import')) . '">' . icon('cloud-upload') . 'استيراد حساب</a>'
    . '<a class="btn btn-primary" href="' . e(url('/admin/tenants/create')) . '">' . icon('plus') . 'حساب جديد</a>';
?>
<div class="panel">
  <nav class="tabs">
    <a class="tab<?= $f['status'] === '' ? ' is-active' : '' ?>" href="<?= e(url('/admin/tenants', ['q' => $f['q']])) ?>">الكل <span class="tab-count"><?= num(array_sum(array_map('intval', $counts))) ?></span></a>
    <?php foreach (AdminController::STATUS as $k => [$label]): ?>
      <a class="tab<?= $f['status'] === $k ? ' is-active' : '' ?>" href="<?= e(url('/admin/tenants', ['status' => $k, 'q' => $f['q']])) ?>"><?= e($label) ?> <span class="tab-count"><?= num($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="اسم الشبكة أو الحساب أو المالك أو الهاتف"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty"><?= icon('building') ?><div class="empty-title">لا توجد حسابات</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الحساب</th><th>المالك</th><th>الحالة</th><th>ينتهي</th><th>آخر نشاط</th><th>التسجيل</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $t): [$sl, $sc] = AdminController::STATUS[$t['status']]; $left = Tenant::daysLeft($t); ?>
        <tr>
          <td><a class="cell-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="tenant-link">/<?= e($t['slug']) ?></div></td>
          <td class="text-sm"><?= e($t['owner_name']) ?><?php if ($t['phone']): ?><div class="cell-sub"><a class="mono" href="https://wa.me/<?= e(preg_replace('/\D/', '', preg_replace('/^0/', '970', $t['phone']))) ?>" target="_blank" rel="noopener"><?= e($t['phone']) ?></a></div><?php endif; ?></td>
          <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
          <td class="nowrap text-sm"><?php if (in_array($t['status'], ['trial', 'active'], true)): ?><?= $left === null ? 'مفتوح' : ($left === 0 ? '<span class="text-danger">اليوم</span>' : num((int) $left) . ' يوم') ?><?php else: ?>—<?php endif; ?></td>
          <td class="nowrap text-sm"><?= $t['last_seen_at'] ? e(time_ago($t['last_seen_at'])) : '—' ?></td>
          <td class="nowrap text-sm"><?= e(fmt_date($t['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
