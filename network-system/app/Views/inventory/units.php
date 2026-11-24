<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
use App\Services\Inventory;

$v->title = 'الأجهزة المرقّمة';
$v->breadcrumbs = [['المخزون', '/inventory'], ['الأجهزة المرقّمة', null]];
$base = array_filter($filters, static fn ($x) => $x !== '');
?>
<div class="panel">
  <nav class="tabs">
    <a class="tab<?= $filters['status'] === '' ? ' is-active' : '' ?>" href="<?= e(url('/inventory/units', array_diff_key($base, ['status' => 1]))) ?>">الكل <span class="tab-count"><?= num(array_sum(array_map('intval', $counts))) ?></span></a>
    <?php foreach (Inventory::UNIT_STATUS as $k => [$label]): ?>
      <a class="tab<?= $filters['status'] === $k ? ' is-active' : '' ?>" href="<?= e(url('/inventory/units', ['status' => $k] + $base)) ?>"><?= e($label) ?> <span class="tab-count"><?= num($counts[$k] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="الرقم التسلسلي أو MAC أو المشترك"></div>
    <div class="field">
      <label for="item">الصنف</label>
      <select class="input" id="item" name="item"><option value="">الكل</option><?php foreach ($items as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['item'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field">
      <label for="site">الموقع</label>
      <select class="input" id="site" name="site"><option value="">الكل</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['site'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= icon('barcode') ?><div class="empty-title">لا توجد أجهزة</div></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>الجهاز</th><th>الرقم التسلسلي</th><th>MAC</th><th>الحالة</th><th>المكان</th><th>آخر تحديث</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): [$stL, $stC] = Inventory::UNIT_STATUS[$u['status']]; ?>
        <tr>
          <td><span class="flex items-center gap-2"><span class="list-icon"><?= icon(Inventory::CATEGORIES[$u['category']][1] ?? 'package') ?></span><a href="<?= e(url('/inventory/' . $u['item_id'])) ?>"><?= e($u['item_name']) ?></a></span></td>
          <td><a class="cell-title mono" href="<?= e(url('/inventory/units/' . $u['id'])) ?>"><?= e($u['serial']) ?></a></td>
          <td class="mono text-sm"><?= e($u['mac'] ?? '—') ?></td>
          <td><span class="badge <?= $stC ?>"><?= $stL ?></span></td>
          <td class="text-sm"><?= e(implode(' · ', array_filter([$u['site_name'], $u['subscriber_name'], $u['router_name']])) ?: '—') ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($u['updated_at'] ?? $u['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
