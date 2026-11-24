<?php
/** @var App\Core\View $v */
use App\Services\Units;

$v->title = 'باقات الاشتراك';
$v->subtitle = 'باقات الاشتراك الشهري (PPPoE والهوتسبوت)';
if (can('plans.manage')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/plans/create')) . '">' . icon('plus') . 'باقة اشتراك جديدة</a>';
}
?>
<div class="panel">
  <?php if (!$plans): ?>
    <div class="empty"><?= icon('package') ?><div class="empty-title">لا توجد باقات اشتراك</div>
      <?php if (can('plans.manage')): ?><a class="btn btn-primary" href="<?= e(url('/plans/create')) ?>"><?= icon('plus') ?>باقة اشتراك جديدة</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>الباقة</th><th>الخدمة</th><th>السعر</th><th>المدة</th><th>السرعة (تنزيل / رفع)</th><th>البيانات</th><th>الأجهزة</th><th class="num">النشطون</th><th class="num">المشتركون</th><th class="w-actions"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($plans as $p): ?>
        <tr>
          <td>
            <a class="cell-title" href="<?= e(url('/subscribers', ['plan' => $p['id']])) ?>"><?= e($p['name']) ?></a>
            <div class="cell-sub"><?= $p['is_active'] ? '' : '<span class="badge badge-outline">موقوفة</span> ' ?><?= e($p['notes'] ?? '') ?></div>
          </td>
          <td><span class="badge <?= $p['service'] === 'ppp' ? 'badge-primary' : 'badge-info' ?>"><?= $p['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت' ?></span></td>
          <td class="nowrap"><?= e(money($p['price'])) ?></td>
          <td class="nowrap"><?= e(num($p['duration_days'])) ?> يوم</td>
          <td class="nowrap"><?= e(Units::describeRate($p)) ?></td>
          <td class="nowrap">
            <?= e(Units::describeBytes($p['data_limit'] !== null ? (int) $p['data_limit'] : null)) ?>
            <?php if ($p['data_limit'] && ($p['fup_rate_down'] || $p['fup_rate_up'])): ?>
              <div class="cell-sub">ثم <?= e(Units::describeRate(['rate_up' => $p['fup_rate_up'], 'rate_down' => $p['fup_rate_down']])) ?></div>
            <?php endif; ?>
          </td>
          <td><?= (int) $p['shared_users'] === 0 ? 'غير محدود' : num($p['shared_users']) ?></td>
          <td class="num"><span class="badge badge-success"><?= num($p['active_subs']) ?></span></td>
          <td class="num"><?= num($p['subscribers']) ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (can('subscribers.manage')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/subscribers/create', ['plan' => $p['id']])) ?>" title="مشترك جديد"><?= icon('user-plus') ?></a><?php endif; ?>
              <?php if (can('plans.manage')): ?>
              <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/plans/' . $p['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a>
              <?php if ((int) $p['subscribers'] === 0): ?>
              <form method="post" action="<?= e(url('/plans/' . $p['id'] . '/delete')) ?>" data-confirm="حذف الباقة <?= e($p['name']) ?>؟">
                <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
