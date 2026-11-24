<?php
/** @var App\Core\View $v */
use App\Services\SubscriberRules;
use App\Services\SubscriberService;
use App\Services\Units;

$v->title = $sub['full_name'];
$v->breadcrumbs = [['المشتركون', '/subscribers'], [$sub['full_name'], null]];
$actions = '';
if (can('subscribers.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/subscribers/' . $sub['id'] . '/edit')) . '">' . icon('pencil') . 'تعديل</a>';
}
if (can('subscribers.renew')) {
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="renew-modal">' . icon('refresh-cw') . 'تجديد</button>';
}
$v->actions = $actions;
$open = array_filter($sessions, static fn ($s) => $s['stopped_at'] === null);
$left = $sub['expires_at'] ? seconds_until($sub['expires_at']) : null;
$periodLen = (int) $plan['duration_days'] * 86400;
$timePct = $left !== null && $left > 0 && $periodLen > 0 ? (int) max(0, min(100, round((1 - $left / $periodLen) * 100))) : null;
$usedBytes = max((int) $sub['period_bytes'], (int) $usage['bytes_in'] + (int) $usage['bytes_out']);
$dataPct = $plan['data_limit'] ? (int) max(0, min(100, round($usedBytes / (int) $plan['data_limit'] * 100))) : null;
$showPass = can('subscribers.passwords');
$balance = (float) $sub['balance'];
$base = url('/subscribers/' . $sub['id']);
?>
<div class="panel mb-3">
  <div class="panel-body flex items-center gap-3 wrap">
    <div class="stat-icon" style="width:56px;height:56px;border-radius:14px"><?= icon($plan['service'] === 'ppp' ? 'cable' : 'wifi', 'icon-lg') ?></div>
    <div style="flex:1;min-width:200px">
      <div style="font-size:21px;font-weight:700"><?= e($sub['full_name']) ?></div>
      <div class="flex items-center gap-1 wrap mt-1">
        <?= SubscriberRules::badge($sub) ?>
        <?php if ($open): ?><span class="badge badge-success badge-dot">متصل الآن</span><?php endif; ?>
        <span class="badge badge-outline"><?= $plan['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت' ?></span>
        <?php if ($sub['router_state'] === 'disabled'): ?><span class="badge badge-warning"><?= icon('ban') ?>معطّل على جهاز MikroTik</span><?php endif; ?>
        <?php if ($pending): ?><span class="badge badge-warning"><?= icon('clock') ?><?= num($pending) ?> مهمة معلّقة</span><?php endif; ?>
        <span class="text-muted text-sm">#<?= (int) $sub['id'] ?></span>
      </div>
    </div>
    <div class="flex gap-1 wrap">
      <?php if (can('subscribers.payments')): ?><button type="button" class="btn" data-modal-open="tx-modal"><?= icon('banknote') ?>دفعة</button><?php endif; ?>
      <?php if (can('subscribers.status')): ?>
        <?php if ($sub['status'] === 'suspended'): ?>
          <form method="post" action="<?= e($base . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="active"><button class="btn"><?= icon('play') ?>تفعيل</button></form>
        <?php else: ?>
          <button type="button" class="btn" data-modal-open="suspend-modal"><?= icon('pause') ?>إيقاف</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (can('subscribers.manage')): ?>
      <div class="dropdown">
        <button type="button" class="btn btn-icon" data-dropdown title="المزيد"><?= icon('ellipsis-vertical') ?></button>
        <div class="dropdown-menu">
          <?php if ($open): ?>
          <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button class="dropdown-item"><?= icon('unplug') ?>فصل الاتصال</button></form>
          <?php endif; ?>
          <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="resync"><button class="dropdown-item"><?= icon('refresh-cw') ?>إعادة التطبيق على جهاز MikroTik</button></form>
          <button type="button" class="dropdown-item" data-modal-open="password-modal"><?= icon('key-round') ?>تغيير كلمة المرور</button>
          <?php if ($sub['bound_mac']): ?>
          <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="unbind"><button class="dropdown-item"><?= icon('link-2-off') ?>فك ربط الجهاز</button></form>
          <?php elseif ($sub['last_mac']): ?>
          <form method="post" action="<?= e($base . '/action') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="bind"><button class="dropdown-item"><?= icon('link') ?>ربط بالجهاز الحالي</button></form>
          <?php endif; ?>
          <?php if (can('subscribers.delete')): ?>
          <div class="dropdown-sep"></div>
          <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="حذف المشترك <?= e($sub['full_name']) ?> وإزالته من جهاز MikroTik؟"><?= csrf_field() ?><button class="dropdown-item is-danger"><?= icon('trash-2') ?>حذف المشترك</button></form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($reason && $sub['status'] !== 'suspended'): ?>
    <div class="panel-body" style="padding-top:0"><div class="notice notice-danger" style="margin:0"><?= icon('circle-x') ?><div><?= e(SubscriberRules::message($reason)) ?></div></div></div>
  <?php elseif ($sub['status'] === 'suspended' && $sub['suspend_reason']): ?>
    <div class="panel-body" style="padding-top:0"><div class="notice notice-warning" style="margin:0"><?= icon('pause') ?><div>سبب الإيقاف: <?= e($sub['suspend_reason']) ?></div></div></div>
  <?php endif; ?>
</div>

<div class="stats">
  <div class="stat">
    <div class="stat-icon is-warning"><?= icon('calendar-clock') ?></div>
    <div class="stat-body"><div class="stat-label">الانتهاء</div>
      <div class="stat-value" style="font-size:18px"><?= $sub['expires_at'] ? e(fmt_date($sub['expires_at'])) : 'غير مفعّل' ?></div>
      <div class="stat-meta"><?= $left === null ? '' : ($left > 0 ? 'متبقٍ ' . e(fmt_duration($left)) : 'منتهٍ منذ ' . e(fmt_duration(-$left))) ?></div>
      <?php if ($timePct !== null): ?><div class="meter mt-1 <?= $timePct >= 85 ? 'is-danger' : '' ?>"><span style="width:<?= $timePct ?>%"></span></div><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon <?= $balance > 0 ? 'is-danger' : 'is-success' ?>"><?= icon('wallet') ?></div>
    <div class="stat-body"><div class="stat-label"><?= $balance >= 0 ? 'المستحق' : 'رصيد مسبق' ?></div>
      <div class="stat-value" style="font-size:18px"><?= e(money(abs($balance))) ?></div>
      <div class="stat-meta"><?= e($plan['name']) ?> · <?= e(money($plan['price'])) ?></div>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-violet"><?= icon('database') ?></div>
    <div class="stat-body"><div class="stat-label">الاستهلاك في الفترة الحالية</div>
      <div class="stat-value" style="font-size:18px"><?= e(fmt_bytes($usedBytes)) ?></div>
      <div class="stat-meta"><?= $plan['data_limit'] ? 'من ' . e(fmt_bytes((int) $plan['data_limit'])) . ((int) $sub['fup_active'] ? ' · سرعة مخفّضة' : '') : 'بدون حد للبيانات' ?></div>
      <?php if ($dataPct !== null): ?><div class="meter mt-1 <?= $dataPct >= 90 ? 'is-danger' : '' ?>"><span style="width:<?= $dataPct ?>%"></span></div><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-success"><?= icon('smartphone') ?></div>
    <div class="stat-body"><div class="stat-label">الجهاز</div>
      <div class="stat-value" style="font-size:16px"><?= e($sub['last_hostname'] ?: '—') ?></div>
      <div class="stat-meta mono"><?= e($sub['bound_mac'] ?: ($sub['last_mac'] ?: '—')) ?></div>
    </div>
  </div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('receipt') ?>الحساب</h3>
        <?php if (can('subscribers.payments')): ?><button type="button" class="btn btn-sm" data-modal-open="tx-modal"><?= icon('plus') ?>قيد جديد</button><?php endif; ?>
      </div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>التاريخ</th><th>النوع</th><th>البيان</th><th class="num">عليه</th><th class="num">له</th><th>الموظف</th></tr></thead>
          <tbody>
          <?php foreach ($transactions as $t):
              [$label, $badge] = SubscriberService::TX_TYPES[$t['type']];
              $debit = in_array($t['type'], ['renewal', 'charge', 'refund'], true); ?>
            <tr>
              <td class="nowrap text-sm"><?= e(fmt_dt($t['created_at'])) ?></td>
              <td><span class="badge <?= e($badge) ?>"><?= e($label) ?></span></td>
              <td class="text-sm">
                <?php if ($t['type'] === 'renewal'): ?>
                  <?= e($t['plan_name'] ?? '') ?> · <?= e(fmt_date($t['period_from'])) ?> ← <?= e(fmt_date($t['period_to'])) ?>
                <?php endif; ?>
                <?php if ($t['method']): ?><?= e(SubscriberService::METHODS[$t['method']] ?? $t['method']) ?><?php endif; ?>
                <?php if ($t['notes']): ?><div class="text-muted"><?= e($t['notes']) ?></div><?php endif; ?>
              </td>
              <td class="num nowrap"><?= $debit ? e(money($t['amount'])) : '' ?></td>
              <td class="num nowrap"><?= !$debit ? '<span class="text-success">' . e(money($t['amount'])) . '</span>' : '' ?></td>
              <td class="text-sm"><?= e($t['employee_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$transactions): ?><tr><td colspan="6"><div class="empty"><?= icon('receipt') ?><div>لا توجد قيود.</div></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>الجلسات</h3></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>جهاز MikroTik</th><th>الجهاز</th><th>MAC / IP</th><th>البداية</th><th>النهاية</th><th>المدة</th><th class="num">البيانات</th><th class="w-actions"></th></tr></thead>
          <tbody>
          <?php foreach ($sessions as $s): ?>
            <tr>
              <td><?= e($s['router_name'] ?? '—') ?></td>
              <td><?= e($s['hostname'] ?: '—') ?></td>
              <td><div class="mono text-sm"><?= e($s['mac'] ?? '—') ?></div><div class="mono text-sm text-muted"><?= e($s['ip'] ?? '') ?></div></td>
              <td class="nowrap text-sm"><?= e(fmt_dt($s['started_at'])) ?></td>
              <td class="nowrap text-sm"><?= $s['stopped_at'] ? e(fmt_dt($s['stopped_at'])) : '<span class="badge badge-success badge-dot">متصل</span>' ?></td>
              <td class="nowrap"><?= e(fmt_duration($s['uptime'], true)) ?></td>
              <td class="num"><?= e(fmt_bytes((int) $s['bytes_in'] + (int) $s['bytes_out'])) ?></td>
              <td class="w-actions">
                <?php if (!$s['stopped_at'] && can('sessions.disconnect')): ?>
                <form method="post" action="<?= e(url('/sessions/' . $s['id'] . '/disconnect')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="فصل"><?= icon('unplug') ?></button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$sessions): ?><tr><td colspan="8"><div class="empty"><?= icon('activity') ?><div>لا توجد جلسات.</div></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>البيانات</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>اسم المستخدم</dt><dd><span class="mono"><?= e($sub['username']) ?></span> <button type="button" class="btn btn-ghost btn-icon btn-xs" data-copy="<?= e($sub['username']) ?>" title="نسخ"><?= icon('copy') ?></button></dd>
          <dt>كلمة المرور</dt><dd><?php if ($showPass): ?><span class="mono"><?= e($sub['password']) ?></span><?php else: ?>••••••<?php endif; ?></dd>
          <dt>الهاتف</dt><dd><?= $sub['phone'] ? '<a class="mono" href="tel:' . e($sub['phone']) . '">' . e($sub['phone']) . '</a>' : '—' ?><?= $sub['phone2'] ? ' · <span class="mono">' . e($sub['phone2']) . '</span>' : '' ?></dd>
          <?php if ($sub['national_id']): ?><dt>رقم الهوية</dt><dd><span class="mono"><?= e($sub['national_id']) ?></span></dd><?php endif; ?>
          <dt>المنطقة</dt><dd><?= e($sub['area_name'] ?? '—') ?></dd>
          <?php if ($sub['address']): ?><dt>العنوان</dt><dd><?= e($sub['address']) ?></dd><?php endif; ?>
          <?php if ($sub['site_id'] || $sub['lat'] !== null): ?><dt>الموقع</dt><dd><?= $v->partial('location-link', ['kind' => 'sub', 'id' => $sub['id'], 'siteId' => $sub['site_id'], 'siteName' => $sub['site_name'], 'lat' => $sub['lat'], 'lng' => $sub['lng']]) ?></dd><?php endif; ?>
          <dt>الباقة</dt><dd><?= e($plan['name']) ?></dd>
          <dt>السرعة</dt><dd><?= e(Units::describeRate($plan)) ?></dd>
          <dt>جهاز MikroTik</dt><dd><?= $sub['router_id'] ? '<a href="' . e(url('/routers/' . $sub['router_id'])) . '">' . e($sub['router_name']) . '</a>' : '—' ?></dd>
          <dt>IP ثابت</dt><dd><span class="mono"><?= e($sub['static_ip'] ?? '—') ?></span></dd>
          <dt>آخر IP</dt><dd><span class="mono"><?= e($sub['last_ip'] ?? '—') ?></span></dd>
          <dt>آخر اتصال</dt><dd><?= e(fmt_dt($sub['last_online_at'])) ?></dd>
          <dt>ربط الجهاز</dt><dd><?= (int) $sub['lock_mac'] ? ($sub['bound_mac'] ? '<span class="mono">' . e($sub['bound_mac']) . '</span>' : 'أول جهاز يتصل') : 'غير مربوط' ?></dd>
          <dt>تاريخ الإضافة</dt><dd><?= e(fmt_dt($sub['created_at'])) ?><?= $sub['created_by_name'] ? ' · ' . e($sub['created_by_name']) : '' ?></dd>
          <?php if ($sub['notes']): ?><dt>ملاحظات</dt><dd><?= e($sub['notes']) ?></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if ($units): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('hard-drive') ?>الأجهزة المركّبة</h3></div>
      <ul class="list">
        <?php foreach ($units as $u): ?>
          <li class="list-item">
            <span class="list-icon is-success"><?= icon('barcode') ?></span>
            <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/inventory/units/' . $u['id'])) ?>"><?= e($u['item_name']) ?></a><div class="list-item-sub mono"><?= e($u['serial']) ?><?= $u['mac'] ? ' · ' . e($u['mac']) : '' ?></div></div>
            <div class="list-item-end text-sm"><?= e(fmt_date($u['installed_at'])) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php if ($routers): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('router') ?>على أجهزة MikroTik</h3></div>
      <ul class="list">
        <?php foreach ($routers as $r): ?>
          <li class="list-item">
            <span class="list-icon <?= $r['state'] === 'present' ? 'is-success' : '' ?>"><?= icon('router') ?></span>
            <div class="list-item-main">
              <div class="list-item-title"><?= e($r['router_name']) ?></div>
              <div class="list-item-sub"><?= $r['state'] === 'present' ? 'موجود' : 'أُزيل' ?> · <?= e(fmt_dt($r['provisioned_at'])) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('subscribers.renew')): ?>
<dialog class="modal modal-sm" id="renew-modal">
  <form method="post" action="<?= e($base . '/renew') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('refresh-cw') ?>تجديد الاشتراك</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field">
        <label for="r-plan">الباقة</label>
        <select class="input" id="r-plan" name="plan_id">
          <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected((string) $plan['id'], (string) $p['id']) ?>><?= e($p['name']) ?> · <?= e(money($p['price'])) ?> / <?= e(num($p['duration_days'])) ?> يوم</option><?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-2" style="gap:12px">
        <div class="field"><label for="r-periods">عدد الفترات</label><input class="input" id="r-periods" name="periods" value="1" inputmode="numeric"></div>
        <div class="field"><label for="r-amount">المبلغ المستحق</label><input class="input" id="r-amount" name="amount" inputmode="decimal" placeholder="سعر الباقة"></div>
        <div class="field"><label for="r-paid">المدفوع الآن</label><input class="input" id="r-paid" name="paid" value="<?= e((string) (float) $plan['price']) ?>" inputmode="decimal"></div>
        <div class="field"><label for="r-method">طريقة الدفع</label>
          <select class="input" id="r-method" name="method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <div class="field">
        <label>بداية الفترة الجديدة</label>
        <div class="segmented">
          <label><input type="radio" name="from" value="auto" checked><span>من نهاية الاشتراك الحالي</span></label>
          <label><input type="radio" name="from" value="now"><span>من الآن</span></label>
        </div>
      </div>
      <div class="field"><label for="r-notes">ملاحظات</label><input class="input" id="r-notes" name="notes"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تجديد</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if (can('subscribers.payments')): ?>
<dialog class="modal modal-sm" id="tx-modal">
  <form method="post" action="<?= e($base . '/transaction') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('banknote') ?>قيد مالي</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field">
        <label for="t-type">النوع</label>
        <select class="input" id="t-type" name="type">
          <?php foreach (SubscriberService::TX_TYPES as $k => [$label]): if ($k === 'renewal' || $k === 'compensation') { continue; } ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label for="t-amount">المبلغ</label><input class="input" id="t-amount" name="amount" value="<?= $balance > 0 ? e((string) $balance) : '' ?>" inputmode="decimal" required></div>
      <div class="field" data-show-when="type=payment"><label for="t-method">طريقة الدفع</label>
        <select class="input" id="t-method" name="method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label for="t-notes">ملاحظات</label><input class="input" id="t-notes" name="notes"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تسجيل</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if (can('subscribers.status')): ?>
<dialog class="modal modal-sm" id="suspend-modal">
  <form method="post" action="<?= e($base . '/status') ?>">
    <?= csrf_field() ?><input type="hidden" name="status" value="suspended">
    <div class="modal-head"><h3><?= icon('pause') ?>إيقاف المشترك</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label for="s-reason">السبب</label><input class="input" id="s-reason" name="reason"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-danger"><?= icon('pause') ?>إيقاف</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if (can('subscribers.manage')): ?>
<dialog class="modal modal-sm" id="password-modal">
  <form method="post" action="<?= e($base . '/action') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="modal-head"><h3><?= icon('key-round') ?>تغيير كلمة المرور</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label for="p-pass">كلمة المرور الجديدة</label><input class="input input-ltr" id="p-pass" name="password" autocomplete="off" placeholder="تلقائية"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (location.hash === '#renew' && document.getElementById('renew-modal') && window.SN) { SN.openModal('renew-modal'); }
});
</script>
