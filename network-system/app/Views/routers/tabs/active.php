<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
$ownerLink = static function (string $user) use ($owners): string {
    $o = $owners[$user] ?? null;
    if (!$o) {
        return '<span class="mono">' . e($user) . '</span>';
    }
    $href = $o[0] === 'card' ? url('/check/card/' . $o[1]) : url('/subscribers/' . $o[1]);
    return '<a class="mono" href="' . e($href) . '">' . e($user) . '</a> ' . ($o[0] === 'card' ? '<span class="badge badge-info">كرت</span>' : '<span class="badge badge-violet">مشترك</span>');
};
?>
<div class="panel-body tight">
  <div class="panel-head" style="border-top:0"><h3><?= icon('wifi') ?>متصلو الهوتسبوت <span class="sub">(<?= num(count($rows)) ?>)</span></h3>
    <div class="panel-tools"><a class="btn btn-sm" href="<?= e(url('/routers/' . $rid, ['tab' => 'active'])) ?>"><?= icon('refresh-cw') ?>تحديث</a></div>
  </div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>المستخدم</th><th>الجهاز</th><th>MAC</th><th>IP</th><th>مدة الجلسة</th><th>المتبقي</th><th class="num">تنزيل</th><th class="num">رفع</th><th>الدخول</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr>
          <td><?= $ownerLink($a['user']) ?></td>
          <td><?= e($hostnames[$a['mac']] ?? '—') ?></td>
          <td><span class="mono"><?= e($a['mac']) ?></span></td>
          <td><span class="mono"><?= e($a['address']) ?></span></td>
          <td class="nowrap"><?= e(fmt_duration($a['uptime'], true)) ?></td>
          <td class="nowrap"><?= $a['session_time_left'] !== null ? e(fmt_duration($a['session_time_left'], true)) : '—' ?></td>
          <td class="num"><?= e(fmt_bytes($a['bytes_out'])) ?></td>
          <td class="num"><?= e(fmt_bytes($a['bytes_in'])) ?></td>
          <td><?= e($a['login_by']) ?></td>
          <td class="w-actions">
            <div class="row-actions">
            <?php if (can('sessions.disconnect')): ?>
              <form method="post" action="<?= e(url('/routers/' . $rid . '/disconnect')) ?>" data-confirm="فصل <?= e($a['user']) ?>؟" data-confirm-type="primary">
                <?= csrf_field() ?><input type="hidden" name="type" value="hotspot"><input type="hidden" name="ref" value="<?= e($a['id']) ?>"><input type="hidden" name="user" value="<?= e($a['user']) ?>">
                <button class="btn btn-ghost btn-icon btn-sm" title="فصل"><?= icon('unplug') ?></button>
              </form>
            <?php endif; ?>
            <?php if (can('devices.block') && $a['mac']): ?>
              <form method="post" action="<?= e(url('/devices/block')) ?>" data-confirm="حظر الجهاز <?= e($a['mac']) ?> على كل أجهزة MikroTik؟">
                <?= csrf_field() ?><input type="hidden" name="mac" value="<?= e($a['mac']) ?>">
                <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حظر الجهاز"><?= icon('ban') ?></button>
              </form>
            <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10"><div class="empty"><?= icon('wifi') ?><div>لا يوجد متصلون على الهوتسبوت.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="panel-head"><h3><?= icon('cable') ?>جلسات PPP <span class="sub">(<?= num(count($ppp)) ?>)</span></h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الحساب</th><th>الخدمة</th><th>MAC</th><th>IP</th><th>مدة الجلسة</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($ppp as $p): ?>
        <tr>
          <td><?= $ownerLink($p['name']) ?></td>
          <td><?= e($p['service']) ?></td>
          <td><span class="mono"><?= e($p['caller_id']) ?></span></td>
          <td><span class="mono"><?= e($p['address']) ?></span></td>
          <td class="nowrap"><?= e(fmt_duration($p['uptime'], true)) ?></td>
          <td class="w-actions">
            <?php if (can('sessions.disconnect')): ?>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/disconnect')) ?>" data-confirm="فصل <?= e($p['name']) ?>؟" data-confirm-type="primary">
              <?= csrf_field() ?><input type="hidden" name="type" value="ppp"><input type="hidden" name="ref" value="<?= e($p['id']) ?>"><input type="hidden" name="user" value="<?= e($p['name']) ?>">
              <button class="btn btn-ghost btn-icon btn-sm" title="فصل"><?= icon('unplug') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ppp): ?><tr><td colspan="6"><div class="empty"><?= icon('cable') ?><div>لا توجد جلسات PPP.</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
