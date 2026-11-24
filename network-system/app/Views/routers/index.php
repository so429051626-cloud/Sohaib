<?php
/** @var App\Core\View $v */
use App\Core\Request;

$v->title = 'أجهزة MikroTik';
if (can('routers.manage')) {
    $v->actions = '<a class="btn" href="' . e(url('/routers/create')) . '">' . icon('plus') . 'إضافة يدوية</a>'
        . '<a class="btn btn-primary" href="' . e(url('/routers/connect')) . '">' . icon('link') . 'ربط جهاز بـ 3 خطوات</a>';
}
// أجهزة لم يكتمل معالج ربطها
$pendingConnect = [];
if (can('routers.manage')) {
    foreach ($routers as $r) {
        $cs = \App\Services\RouterConnect::state((int) $r['id']);
        if ($cs !== null && empty($cs['ready_at'])) {
            $pendingConnect[(int) $r['id']] = true;
        }
    }
}
$req = Request::current();
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('router') ?></div><div class="stat-body"><div class="stat-label">إجمالي أجهزة MikroTik</div><div class="stat-value"><?= num($totals['all']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('wifi') ?></div><div class="stat-body"><div class="stat-label">متصل</div><div class="stat-value"><?= num($totals['online']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('wifi-off') ?></div><div class="stat-body"><div class="stat-label">منقطع</div><div class="stat-value"><?= num($totals['offline']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">المتصلون الآن</div><div class="stat-value"><?= num($totals['users']) ?></div></div></div>
</div>

<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($req->str('q')) ?>" placeholder="الاسم أو العنوان"></div>
    <div class="field">
      <label for="area">المنطقة</label>
      <select class="input" id="area" name="area" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('area'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="status">الحالة</label>
      <select class="input" id="status" name="status" data-autosubmit>
        <option value="">الكل</option>
        <option value="online"<?= selected($req->str('status'), 'online') ?>>متصل</option>
        <option value="offline"<?= selected($req->str('status'), 'offline') ?>>منقطع</option>
        <option value="unknown"<?= selected($req->str('status'), 'unknown') ?>>غير معروف</option>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn"><?= icon('search') ?>بحث</button></div>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>جهاز MikroTik</th><th>المنطقة</th><th>الحالة</th><th>الموديل / الإصدار</th>
          <th>المعالج</th><th>الذاكرة</th><th class="num">المتصلون</th><th>مدة التشغيل</th><th>آخر اتصال</th><th class="w-actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($routers as $r):
          $mem = $r['memory_total'] ? (int) round(100 - $r['memory_free'] / $r['memory_total'] * 100) : null; ?>
        <tr>
          <td>
            <a class="cell-title" href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
            <div class="cell-sub"><span class="mono"><?= e($r['host']) ?>:<?= (int) $r['api_port'] ?></span><?= $r['identity'] ? ' · ' . e($r['identity']) : '' ?></div>
          </td>
          <td><?= e($r['area_name'] ?? '—') ?></td>
          <td class="nowrap">
            <?php if (isset($pendingConnect[(int) $r['id']])): ?><a class="badge badge-warning" href="<?= e(url('/routers/connect/' . $r['id'])) ?>"><?= icon('link', 'icon-sm') ?>أكمل الربط</a>
            <?php elseif (!$r['is_active']): ?><span class="badge badge-outline">معطّل</span>
            <?php elseif ($r['status'] === 'online'): ?><span class="badge badge-success badge-dot">متصل</span>
            <?php elseif ($r['status'] === 'offline'): ?><span class="badge badge-danger badge-dot">منقطع</span>
            <?php else: ?><span class="badge badge-dot">غير معروف</span><?php endif; ?>
            <?php if ((int) $r['pending_tasks'] > 0): ?><span class="badge badge-warning" title="مهام معلّقة"><?= icon('clock') ?><?= (int) $r['pending_tasks'] ?></span><?php endif; ?>
          </td>
          <td><div><?= e($r['model'] ?: ($r['board_name'] ?: '—')) ?></div><div class="cell-sub mono"><?= e($r['version'] ?? '') ?></div></td>
          <td style="min-width:110px">
            <?php if ($r['cpu_load'] !== null): ?>
              <div class="meter-row"><span><?= (int) $r['cpu_load'] ?>%</span></div>
              <div class="meter <?= $r['cpu_load'] >= 85 ? 'is-danger' : ($r['cpu_load'] >= 60 ? 'is-warning' : 'is-success') ?>"><span style="width:<?= (int) $r['cpu_load'] ?>%"></span></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="min-width:110px">
            <?php if ($mem !== null): ?>
              <div class="meter-row"><span><?= $mem ?>%</span></div>
              <div class="meter <?= $mem >= 90 ? 'is-danger' : ($mem >= 75 ? 'is-warning' : '') ?>"><span style="width:<?= $mem ?>%"></span></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num"><?= num((int) $r['active_hotspot'] + (int) $r['active_ppp']) ?></td>
          <td class="nowrap"><?= $r['uptime_seconds'] ? e(fmt_duration($r['uptime_seconds'])) : '—' ?></td>
          <td class="nowrap"><?= e(time_ago($r['last_seen_at'])) ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <form method="post" action="<?= e(url('/routers/' . $r['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="مزامنة الآن"><?= icon('refresh-cw') ?></button></form>
              <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/routers/' . $r['id'])) ?>" title="عرض"><?= icon('eye') ?></a>
              <?php if (can('routers.manage')): ?><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/routers/' . $r['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$routers): ?>
        <tr><td colspan="10"><div class="empty"><?= icon('router') ?><div class="empty-title">لا توجد أجهزة MikroTik</div>
          <?php if (can('routers.manage')): ?><a class="btn btn-primary" href="<?= e(url('/routers/connect')) ?>"><?= icon('link') ?>ربط جهاز بـ 3 خطوات</a><?php endif; ?></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
