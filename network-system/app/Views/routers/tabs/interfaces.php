<?php
/** @var App\Core\View $v */
// تعطيل المنافذ وتشغيلها لمن يملك صلاحية التحكم بالجهاز. المنافذ المحمية (الإنترنت واتصال النظام) لا تُعطَّل.
$canToggle = can('routers.control');
$locked = $locked ?? [];
$rid = (int) $router['id'];
$shownLocked = array_filter($locked, static function ($reason, $name) use ($rows) {
    foreach ($rows as $i) {
        if ($i['name'] === $name) {
            return true;
        }
    }
    return false;
}, ARRAY_FILTER_USE_BOTH);
?>
<?php if ($canToggle && $shownLocked): ?>
<div class="panel-body">
  <div class="notice notice-info iface-locked">
    <?= icon('lock') ?>
    <div>
      <div class="notice-title">منافذ لا تُعطَّل من النظام</div>
      <ul class="iface-locked-list">
        <?php foreach ($shownLocked as $name => $reason): ?>
          <li><span class="mono" dir="ltr"><?= e($name) ?></span> — <?= e($reason) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الواجهة</th><th>النوع</th><th>الحالة</th><th>MAC</th><th class="num">المستلم</th><th class="num">المرسل</th><th>آخر اتصال</th><th class="num">مرات الانقطاع</th><th>تعليق</th><?php if ($canToggle): ?><th class="w-actions"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): ?>
      <tr<?= $i['disabled'] ? ' class="is-muted-row"' : '' ?>>
        <td><span class="mono"><?= e($i['name']) ?></span><?= $i['name'] === $router['wan_interface'] ? ' <span class="badge badge-primary">WAN</span>' : '' ?></td>
        <td><?= e($i['type']) ?></td>
        <td>
          <?php if ($i['disabled']): ?><span class="badge badge-outline">معطلة</span>
          <?php elseif ($i['running']): ?><span class="badge badge-success badge-dot">تعمل</span>
          <?php else: ?><span class="badge badge-danger badge-dot">متوقفة</span><?php endif; ?>
        </td>
        <td><span class="mono text-sm"><?= e($i['mac'] ?: '—') ?></span></td>
        <td class="num"><?= e(fmt_bytes($i['rx_byte'])) ?></td>
        <td class="num"><?= e(fmt_bytes($i['tx_byte'])) ?></td>
        <td class="nowrap text-sm"><?= e($i['link_up'] ?: '—') ?></td>
        <td class="num"><?= num($i['link_downs']) ?></td>
        <td><?= e($i['comment']) ?></td>
        <?php if ($canToggle): ?>
        <td class="w-actions">
          <div class="row-actions">
          <?php if ($i['dynamic']): ?>
            <span class="text-muted text-sm nowrap" title="واجهة مؤقتة ينشئها الجهاز تلقائياً">تلقائية</span>
          <?php elseif ($i['disabled']): ?>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="تشغيل المنفذ <?= e($i['name']) ?>؟" data-confirm-type="primary" data-confirm-ok="تشغيل">
              <?= csrf_field() ?><input type="hidden" name="tab" value="interfaces"><input type="hidden" name="op" value="iface_toggle"><input type="hidden" name="ref" value="<?= e($i['id']) ?>"><input type="hidden" name="disable" value="0">
              <button class="btn btn-sm nowrap"><?= icon('play') ?>تشغيل</button>
            </form>
          <?php elseif (isset($locked[$i['name']])): ?>
            <span class="badge badge-outline nowrap" title="<?= e($locked[$i['name']]) ?>"><?= icon('lock') ?>محمي</span>
          <?php else: ?>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="تعطيل المنفذ <?= e($i['name']) ?>؟ سينقطع كل ما يتصل عبره حتى تشغّله من جديد." data-confirm-ok="تعطيل">
              <?= csrf_field() ?><input type="hidden" name="tab" value="interfaces"><input type="hidden" name="op" value="iface_toggle"><input type="hidden" name="ref" value="<?= e($i['id']) ?>"><input type="hidden" name="disable" value="1">
              <button class="btn btn-sm btn-soft-danger nowrap"><?= icon('power') ?>تعطيل</button>
            </form>
          <?php endif; ?>
          </div>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $canToggle ? 10 : 9 ?>"><div class="empty"><?= icon('ethernet-port') ?><div>لا توجد منافذ.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
