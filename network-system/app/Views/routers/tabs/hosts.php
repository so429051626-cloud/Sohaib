<?php
/** @var App\Core\View $v */
$names = [];
foreach ($leases as $l) {
    if ($l['mac']) {
        $names[$l['mac']] = $l['host_name'];
    }
}
?>
<div class="panel-body tight">
  <div class="panel-head" style="border-top:0"><h3><?= icon('smartphone') ?>أجهزة الهوتسبوت <span class="sub">(<?= num(count($rows)) ?>)</span></h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>اسم الجهاز</th><th>MAC</th><th>IP</th><th>الحالة</th><th>مدة الاتصال</th><th>الخمول</th><th class="num">تنزيل</th><th class="num">رفع</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $h): ?>
        <tr>
          <td><?= e($names[$h['mac']] ?? '—') ?></td>
          <td><span class="mono"><?= e($h['mac']) ?></span></td>
          <td><span class="mono"><?= e($h['address']) ?></span></td>
          <td>
            <?php if ($h['bypassed']): ?><span class="badge badge-violet">متجاوز</span>
            <?php elseif ($h['authorized']): ?><span class="badge badge-success">مسجّل</span>
            <?php else: ?><span class="badge">غير مسجّل</span><?php endif; ?>
          </td>
          <td class="nowrap"><?= e(fmt_duration($h['uptime'], true)) ?></td>
          <td class="nowrap"><?= e(fmt_duration($h['idle'], true)) ?></td>
          <td class="num"><?= e(fmt_bytes($h['bytes_out'])) ?></td>
          <td class="num"><?= e(fmt_bytes($h['bytes_in'])) ?></td>
          <td class="w-actions">
            <?php if (can('devices.block') && $h['mac']): ?>
            <form method="post" action="<?= e(url('/devices/block')) ?>" data-confirm="حظر الجهاز <?= e($h['mac']) ?> على كل أجهزة MikroTik؟">
              <?= csrf_field() ?><input type="hidden" name="mac" value="<?= e($h['mac']) ?>">
              <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حظر"><?= icon('ban') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9"><div class="empty"><?= icon('smartphone') ?><div>لا توجد أجهزة.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-head"><h3><?= icon('network') ?>عناوين DHCP <span class="sub">(<?= num(count($leases)) ?>)</span></h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>اسم الجهاز</th><th>MAC</th><th>IP</th><th>الخادم</th><th>الحالة</th><th>آخر ظهور</th><th>النوع</th></tr></thead>
      <tbody>
      <?php foreach ($leases as $l): ?>
        <tr>
          <td><?= e($l['host_name'] ?: '—') ?></td>
          <td><span class="mono"><?= e($l['mac']) ?></span></td>
          <td><span class="mono"><?= e($l['address']) ?></span></td>
          <td><?= e($l['server']) ?></td>
          <td><?= $l['status'] === 'bound' ? '<span class="badge badge-success">مرتبط</span>' : '<span class="badge">' . e($l['status']) . '</span>' ?></td>
          <td class="nowrap"><?= $l['last_seen'] ? 'منذ ' . e(fmt_duration($l['last_seen'])) : '—' ?></td>
          <td><?= $l['dynamic'] ? 'ديناميكي' : 'ثابت' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$leases): ?><tr><td colspan="7"><div class="empty"><?= icon('network') ?><div>لا توجد عناوين موزّعة.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
