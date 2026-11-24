<?php
/** @var App\Core\View $v */
use App\Controllers\SubscriberController;
use App\Services\SubscriberRules;

$v->title = 'المشتركون';
$v->subtitle = 'اشتراكات PPPoE والهوتسبوت الشهرية';
$actions = '';
if (can('subscribers.view')) {
    $actions .= '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'تصدير</button><div class="dropdown-menu">'
        . '<a class="dropdown-item" href="' . e(url('/subscribers/export', $filters + ['format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel</a>'
        . '<a class="dropdown-item" href="' . e(url('/subscribers/export', $filters + ['format' => 'csv'])) . '">' . icon('file-text') . 'CSV</a></div></div>';
}
if (can('subscribers.manage')) {
    $actions .= '<a class="btn' . (\App\Services\Ai::offered() ? ' btn-ai' : '') . '" href="' . e(url('/subscribers/import-smart')) . '">' . icon(\App\Services\Ai::offered() ? 'sparkles' : 'file-up') . 'استيراد ذكي</a>';
    $actions .= '<a class="btn btn-primary" href="' . e(url('/subscribers/create')) . '">' . icon('user-plus') . 'مشترك جديد</a>';
}
$v->actions = $actions;
$showPass = can('subscribers.passwords');
?>
<div class="panel">
  <nav class="tabs">
    <?php foreach (SubscriberController::STATES as $key => $label): ?>
      <a class="tab<?= $filters['state'] === $key ? ' is-active' : '' ?>" href="<?= e(url('/subscribers', array_merge($filters, ['state' => $key]))) ?>"><?= e($label) ?> <span class="tab-count"><?= num($counts[$key] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <input type="hidden" name="state" value="<?= e($filters['state']) ?>">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="الاسم، الهاتف، اسم المستخدم، IP، MAC"></div>
    <div class="field">
      <label for="plan">الباقة</label>
      <select class="input" id="plan" name="plan"><option value="">الكل</option>
        <?php foreach ($plans as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['plan'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="service">الخدمة</label>
      <select class="input" id="service" name="service"><option value="">الكل</option>
        <option value="ppp"<?= selected($filters['service'], 'ppp') ?>>PPPoE</option>
        <option value="hotspot"<?= selected($filters['service'], 'hotspot') ?>>هوتسبوت</option>
      </select>
    </div>
    <?php if ($areas): ?>
    <div class="field">
      <label for="area">المنطقة</label>
      <select class="input" id="area" name="area"><option value="">الكل</option>
        <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['area'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="router">جهاز MikroTik</label>
      <select class="input" id="router" name="router"><option value="">الكل</option>
        <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['router'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><?= icon('users') ?><div class="empty-title">لا يوجد مشتركون</div>
      <?php if (can('subscribers.manage') && $total === 0 && implode('', $filters) === ''): ?><a class="btn btn-primary" href="<?= e(url('/subscribers/create')) ?>"><?= icon('user-plus') ?>مشترك جديد</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>المشترك</th><th>اسم المستخدم</th><th>الباقة</th><th>الحالة</th><th>الانتهاء</th><th>جهاز MikroTik</th><th class="num">المستحق</th><th class="w-actions"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $s):
          $left = $s['expires_at'] ? seconds_until($s['expires_at']) : null; ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <span class="status-dot <?= (int) $s['online'] > 0 ? 'is-online' : 'is-unknown' ?>" title="<?= (int) $s['online'] > 0 ? 'متصل الآن' : 'غير متصل' ?>"></span>
              <div>
                <a class="cell-title" href="<?= e(url('/subscribers/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a>
                <div class="cell-sub"><?= $s['phone'] ? '<span class="mono">' . e($s['phone']) . '</span>' : '' ?><?= $s['area_name'] ? ($s['phone'] ? ' · ' : '') . e($s['area_name']) : '' ?></div>
              </div>
            </div>
          </td>
          <td>
            <span class="mono"><?= e($s['username']) ?></span>
            <?php if ($showPass): ?><div class="cell-sub mono"><?= e($s['password']) ?></div><?php endif; ?>
          </td>
          <td class="nowrap">
            <?= e($s['plan_name']) ?>
            <div class="cell-sub"><?= $s['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت' ?> · <?= e(money($s['plan_price'])) ?></div>
          </td>
          <td><?= SubscriberRules::badge($s) ?></td>
          <td class="nowrap">
            <?php if ($s['expires_at']): ?>
              <?= e(fmt_date($s['expires_at'])) ?>
              <div class="cell-sub"><?= $left > 0 ? 'متبقٍ ' . e(fmt_duration($left)) : 'منذ ' . e(fmt_duration(-$left)) ?></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="nowrap"><?= e($s['router_name'] ?? '—') ?></td>
          <td class="num nowrap"><?= (float) $s['balance'] > 0 ? '<span class="text-danger">' . e(money($s['balance'])) . '</span>' : ((float) $s['balance'] < 0 ? '<span class="text-success">' . e(money(-$s['balance'])) . ' رصيد</span>' : '—') ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (can('subscribers.renew')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/subscribers/' . $s['id']) . '#renew') ?>" title="تجديد"><?= icon('refresh-cw') ?></a><?php endif; ?>
              <?php if (can('subscribers.manage')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/subscribers/' . $s['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($request) ?>
  <?php endif; ?>
</div>
