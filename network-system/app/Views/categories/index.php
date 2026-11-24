<?php
/** @var App\Core\View $v */
use App\Services\Units;

$v->title = 'الباقات';
$v->subtitle = 'السعر والمدة والسرعة وحدود كل باقة';
if (can('categories.manage')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/categories/create')) . '">' . icon('plus') . 'باقة جديدة</a>';
}
?>
<div class="panel">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>الباقة</th><th>السعر</th><th>مدة الاستخدام</th><th>الصلاحية</th><th>البيانات</th><th>السرعة (تنزيل / رفع)</th><th>الأجهزة</th><th class="num">المتاح</th><th class="num">النشط</th><th class="num">الإجمالي</th><th class="w-actions"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $c):
          $low = $c['low_stock'] !== null && (int) $c['available'] <= (int) $c['low_stock']; ?>
        <tr>
          <td class="nowrap">
            <div class="flex items-center gap-2">
              <span class="swatch" style="background:<?= e($c['color']) ?>"></span>
              <div>
                <a class="cell-title" href="<?= e(url('/cards', ['category' => $c['id']])) ?>"><?= e($c['name']) ?></a>
                <div class="cell-sub">
                  <?= $c['is_active'] ? '' : '<span class="badge badge-outline">موقوفة</span> ' ?>
                  <?= $c['lock_mac'] ? 'مرتبطة بأول جهاز' : 'غير مرتبطة بجهاز' ?>
                  <?= (int) $c['router_limit'] > 0 ? ' · ' . num($c['router_limit']) . ' جهاز MikroTik' : '' ?>
                </div>
              </div>
            </div>
          </td>
          <td class="nowrap"><?= e(money($c['price'])) ?></td>
          <td class="nowrap"><?= e(Units::describeSeconds($c['time_limit'] !== null ? (int) $c['time_limit'] : null)) ?></td>
          <td class="nowrap"><?= e(Units::describeSeconds($c['validity'] !== null ? (int) $c['validity'] : null)) ?></td>
          <td class="nowrap"><?= e(Units::describeBytes($c['data_limit'] !== null ? (int) $c['data_limit'] : null)) ?></td>
          <td class="nowrap"><?= e(Units::describeRate($c)) ?></td>
          <td><?= (int) $c['shared_users'] === 0 ? 'غير محدود' : num($c['shared_users']) ?></td>
          <td class="num"><span class="badge <?= $low ? 'badge-danger' : 'badge-success' ?>"><?= num($c['available']) ?></span></td>
          <td class="num"><?= num($c['active']) ?></td>
          <td class="num"><?= num($c['total']) ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (can('cards.generate')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/cards/generate', ['category' => $c['id']])) ?>" title="توليد كروت"><?= icon('wand-sparkles') ?></a><?php endif; ?>
              <?php if (can('categories.manage')): ?>
              <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/categories/' . $c['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a>
              <form method="post" action="<?= e(url('/categories/' . $c['id'] . '/sync')) ?>" data-confirm="إعادة تطبيق ملف السرعة على كل أجهزة MikroTik؟" data-confirm-type="primary">
                <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="مزامنة السرعة مع أجهزة MikroTik"><?= icon('refresh-cw') ?></button>
              </form>
              <?php if ((int) $c['total'] === 0): ?>
              <form method="post" action="<?= e(url('/categories/' . $c['id'] . '/delete')) ?>" data-confirm="حذف الباقة <?= e($c['name']) ?>؟">
                <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$categories): ?>
        <tr><td colspan="11"><div class="empty"><?= icon('layers') ?><div class="empty-title">لا توجد باقات</div>
          <?php if (can('categories.manage')): ?><a class="btn btn-primary" href="<?= e(url('/categories/create')) ?>"><?= icon('plus') ?>باقة جديدة</a><?php endif; ?></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
