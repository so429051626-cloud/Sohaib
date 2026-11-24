<?php
/** @var App\Core\View $v */
use App\Services\CardRules;

$v->title = $batch['name'];
$v->breadcrumbs = [['الدفعات', '/batches'], ['#' . $batch['id'], null]];
$v->subtitle = $batch['category_name'] . ' · ' . money($batch['price']) . ' · ' . fmt_dt($batch['created_at']) . ($batch['employee_name'] ? ' · ' . $batch['employee_name'] : '');
$actions = '<a class="btn" href="' . e(url('/cards', ['batch' => $batch['id']])) . '">' . icon('list') . 'عرض الكروت</a>';
if (can('cards.export')) {
    $actions .= '<a class="btn" href="' . e(url('/cards/export', ['batch' => $batch['id'], 'format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel</a>';
}
if (can('cards.print')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('/print', ['source' => 'batch', 'batch' => $batch['id']])) . '">' . icon('printer') . 'طباعة</a>';
}
$v->actions = $actions;
$showPass = can('cards.passwords');
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">الكروت</div><div class="stat-value"><?= num($stats['total']) ?></div><div class="stat-meta mono">#<?= (int) $stats['first_id'] ?> — #<?= (int) $stats['last_id'] ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('boxes') ?></div><div class="stat-body"><div class="stat-label">المتاحة</div><div class="stat-value"><?= num($stats['available']) ?></div><div class="stat-meta">مباعة <?= num($stats['sold']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">المستخدمة</div><div class="stat-value"><?= num((int) $stats['active'] + (int) $stats['ended']) ?></div><div class="stat-meta">نشطة <?= num($stats['active']) ?> · منتهية <?= num($stats['ended']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('printer') ?></div><div class="stat-body"><div class="stat-label">المطبوعة</div><div class="stat-value"><?= num($stats['printed']) ?></div><div class="stat-meta">أرقام مختصرة <?= num($stats['short_codes']) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('list') ?>أول الكروت في الدفعة</h3></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>#</th><th>اسم المستخدم</th><th>كلمة المرور</th><th>المختصر</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php foreach ($sample as $c): ?>
          <tr>
            <td class="mono text-sm text-muted"><?= (int) $c['id'] ?></td>
            <td><a class="mono" href="<?= e(url('/check/card/' . $c['id'])) ?>"><?= e($c['username']) ?></a></td>
            <td><?= $c['password'] === $c['username'] ? '<span class="text-muted text-sm">نفس الاسم</span>' : ($showPass ? '<span class="mono">' . e($c['password']) . '</span>' : '••••') ?></td>
            <td><span class="mono"><?= e($c['short_code'] ?? '—') ?></span><?php if (!empty($c['short_password'])): ?> <span class="text-muted">/</span> <span class="mono"><?= $showPass ? e($c['short_password']) : '••••' ?></span><?php endif; ?></td>
            <td><?= CardRules::statusBadge($c) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="stack">
    <?php if (can('cards.manage')): ?>
    <form method="post" action="<?= e(url('/batches/' . $batch['id'])) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('pencil') ?>بيانات الدفعة</h3></div>
      <div class="panel-body stack" style="gap:12px">
        <div class="field"><label>الاسم</label><input class="input" name="name" value="<?= e($batch['name']) ?>" required></div>
        <div class="field"><label>ملاحظات</label><input class="input" name="notes" value="<?= e($batch['notes'] ?? '') ?>"></div>
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
      </div>
    </form>
    <?php endif; ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik للدفعة</h3><?= $linked ? '<span class="badge badge-primary">' . num(count($linked)) . ' محددة</span>' : '<span class="badge badge-outline">كل الأجهزة</span>' ?></div>
      <div class="panel-body stack" style="gap:10px">
        <?php if ($linked): ?>
          <div class="chips-wrap"><?php foreach ($linked as $rid): ?><span class="badge badge-info"><?= icon('router') ?><?= e($routers[$rid] ?? ('#' . $rid)) ?></span><?php endforeach; ?></div>
          <p class="hint" style="margin:0">كروت هذه الدفعة تعمل على هذه الأجهزة فقط.</p>
        <?php else: ?>
          <p class="hint" style="margin:0">كروت هذه الدفعة تعمل على كل الأجهزة المسموحة لباقتها.</p>
        <?php endif; ?>
        <?php if (can('cards.manage') && $routers): $selMode = old('router_mode', $linked ? 'selected' : 'all'); ?>
        <form method="post" action="<?= e(url('/batches/' . $batch['id'] . '/routers')) ?>" class="stack" style="gap:10px">
          <?= csrf_field() ?>
          <div class="segmented is-block">
            <label><input type="radio" name="router_mode" value="all"<?= checked($selMode !== 'selected') ?>><span>كل الأجهزة</span></label>
            <label><input type="radio" name="router_mode" value="selected"<?= checked($selMode === 'selected') ?>><span>أجهزة محددة</span></label>
          </div>
          <div class="stack" style="gap:6px" data-show-when="router_mode=selected"<?= $selMode === 'selected' ? '' : ' hidden' ?>>
            <?php foreach ($routers as $rid => $rname): if ($catLimit && !in_array((int) $rid, $catLimit, true)) { continue; } ?>
              <label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $rid ?>"<?= checked(in_array((int) $rid, $linked, true)) ?>><?= e($rname) ?></label>
            <?php endforeach; ?>
            <label class="switch mt-2"><input type="checkbox" name="push_now" value="1" checked><span class="track"></span><span class="text-sm">رفع الكروت إلى الأجهزة المضافة الآن</span></label>
            <p class="hint" style="margin:0">الأجهزة التي تُزال تُحذف منها كروت الدفعة.</p>
          </div>
          <?= error_html('routers') ?>
          <button class="btn btn-block"><?= icon('save') ?>حفظ أجهزة الدفعة</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('zap') ?>إجراءات سريعة</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <?php if (can('cards.short')): ?><a class="btn btn-block" href="<?= e(url('/shortener', ['batch' => $batch['id']])) ?>"><?= icon('link-2') ?>اختصار أرقام الدفعة</a><?php endif; ?>
        <?php if (can('sales.create')): ?><a class="btn btn-block" href="<?= e(url('/sales/create', ['category' => $batch['category_id']])) ?>"><?= icon('shopping-cart') ?>بيع من هذه الباقة</a><?php endif; ?>
        <?php if (can('cards.manage') && $routers): ?>
        <form method="post" action="<?= e(url('/cards/bulk')) ?>" class="stack" style="gap:8px">
          <?= csrf_field() ?><input type="hidden" name="action" value="push"><input type="hidden" name="all_matching" value="1"><input type="hidden" name="batch" value="<?= (int) $batch['id'] ?>">
          <select class="input" name="routers[]" multiple size="<?= min(5, count($routers)) ?>"><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
          <button class="btn btn-block"><?= icon('cloud-upload') ?>رفع الدفعة إلى أجهزة MikroTik المحددة</button>
        </form>
        <?php endif; ?>
        <?php if (can('cards.delete')): ?>
        <form method="post" action="<?= e(url('/batches/' . $batch['id'] . '/delete')) ?>" data-confirm="حذف الدفعة وكل كروتها (<?= num($stats['total']) ?>) نهائياً؟">
          <?= csrf_field() ?>
          <?php if ((int) $stats['total'] > (int) $stats['available'] + (int) $stats['disabled']): ?><label class="check text-sm mb-1"><input type="checkbox" name="force" value="1">حذف رغم وجود كروت مستخدمة أو مباعة</label><?php endif; ?>
          <button class="btn btn-soft-danger btn-block"><?= icon('trash-2') ?>حذف الدفعة</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
