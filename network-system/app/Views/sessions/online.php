<?php
/** @var App\Core\View $v */
use App\Core\Request;

$v->title = 'المتصلون الآن';
$v->subtitle = 'كل الجلسات النشطة على أجهزة MikroTik في الشبكة';
$v->actions = '<a class="btn" href="' . e(Request::current()->urlWith([])) . '">' . icon('refresh-cw') . 'تحديث</a>';
$req = Request::current();
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">الجلسات النشطة</div><div class="stat-value"><?= num($summary['n'] ?? 0) ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('wifi') ?></div><div class="stat-body"><div class="stat-label">هوتسبوت</div><div class="stat-value"><?= num($summary['hs'] ?? 0) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('cable') ?></div><div class="stat-body"><div class="stat-label">PPP</div><div class="stat-value"><?= num($summary['ppp'] ?? 0) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('arrow-up-down') ?></div><div class="stat-body"><div class="stat-label">بيانات الجلسات الحالية</div><div class="stat-value"><?= e(fmt_bytes($summary['bytes'] ?? 0)) ?></div></div></div>
</div>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($req->str('q')) ?>" placeholder="المستخدم، IP، MAC، اسم الجهاز"></div>
    <div class="field">
      <label for="router">جهاز MikroTik</label>
      <select class="input" id="router" name="router" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('router'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="service">الخدمة</label>
      <select class="input" id="service" name="service" data-autosubmit>
        <option value="">الكل</option>
        <option value="hotspot"<?= selected($req->str('service'), 'hotspot') ?>>هوتسبوت</option>
        <option value="ppp"<?= selected($req->str('service'), 'ppp') ?>>PPP</option>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn"><?= icon('search') ?>بحث</button></div>
  </form>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>المستخدم</th><th>النوع</th><th>الجهاز</th><th>MAC / IP</th><th>جهاز MikroTik</th><th>بدأت</th><th>المدة</th><th class="num">تنزيل</th><th class="num">رفع</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr>
          <td>
            <?php if ($s['card_id']): ?><a class="mono" href="<?= e(url('/check/card/' . $s['card_id'])) ?>"><?= e($s['username']) ?></a>
            <?php elseif ($s['subscriber_id']): ?><a href="<?= e(url('/subscribers/' . $s['subscriber_id'])) ?>"><?= e($s['full_name'] ?? $s['username']) ?></a>
            <?php else: ?><span class="mono"><?= e($s['username']) ?></span><?php endif; ?>
            <?php if ($s['category_name']): ?><div class="cell-sub"><?= e($s['category_name']) ?></div><?php endif; ?>
          </td>
          <td><?= $s['service'] === 'ppp' ? '<span class="badge badge-violet">PPP</span>' : '<span class="badge badge-primary">هوتسبوت</span>' ?></td>
          <td><?= e($s['hostname'] ?: '—') ?></td>
          <td><div class="mono text-sm"><?= e($s['mac'] ?? '—') ?></div><div class="mono text-sm text-muted"><?= e($s['ip'] ?? '') ?></div></td>
          <td><?= e($s['router_name'] ?? '—') ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($s['started_at'])) ?></td>
          <td class="nowrap"><?= e(fmt_duration(max((int) $s['uptime'], time() - strtotime($s['started_at'])), true)) ?></td>
          <td class="num"><?= e(fmt_bytes($s['bytes_out'])) ?></td>
          <td class="num"><?= e(fmt_bytes($s['bytes_in'])) ?></td>
          <td class="w-actions">
            <div class="row-actions">
              <?php if (can('sessions.disconnect')): ?>
              <form method="post" action="<?= e(url('/sessions/' . $s['id'] . '/disconnect')) ?>" data-confirm="فصل هذه الجلسة؟" data-confirm-type="primary">
                <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="فصل"><?= icon('unplug') ?></button>
              </form>
              <?php endif; ?>
              <?php if (can('devices.block') && $s['mac']): ?>
              <form method="post" action="<?= e(url('/devices/block')) ?>" data-confirm="حظر الجهاز <?= e($s['mac']) ?> على كل أجهزة MikroTik؟">
                <?= csrf_field() ?><input type="hidden" name="mac" value="<?= e($s['mac']) ?>">
                <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حظر الجهاز"><?= icon('ban') ?></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10"><div class="empty"><?= icon('activity') ?><div class="empty-title">لا توجد جلسات نشطة</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>
<script>setTimeout(function () { if (!document.hidden) { location.reload(); } }, 60000);</script>
