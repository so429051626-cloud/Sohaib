<?php
/** @var App\Core\View $v */
use App\Services\CardRules;
use App\Services\Units;

$isAccount = (int) ($category['is_custom'] ?? 0) === 1;
$v->title = ($isAccount ? 'الحساب ' : 'كرت ') . $card['username'];
$v->breadcrumbs = [['فحص الكرت', '/check'], [$card['username'], null]];
$v->subtitle = $isAccount ? 'حساب خاص' . ($card['batch_name'] !== \App\Services\CustomAccounts::NAME_PREFIX . $card['username'] ? ' · ' . $card['batch_name'] : '') : $card['category_name'] . ' · الدفعة: ' . $card['batch_name'];
if ($isAccount && can('cards.accounts')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/cards/accounts/' . $card['id'] . '/edit')) . '">' . icon('pencil') . 'تعديل الحساب</a>';
}
$reason = CardRules::blockReason($card, $category);
$open = array_filter($sessions, static fn ($s) => $s['stopped_at'] === null);
$pct = static function (?int $limit, int $used): ?int {
    if (!$limit) {
        return null;
    }
    return (int) max(0, min(100, round($used / $limit * 100)));
};
$timePct = $pct($timeLimit, (int) $card['used_seconds']);
$dataPct = $pct($dataLimit, CardRules::usedBytes($card));
$action = url('/check/card/' . $card['id']);
?>
<div class="panel mb-3">
  <div class="panel-body flex items-center gap-3 wrap">
    <div class="stat-icon" style="width:56px;height:56px;border-radius:14px;background:<?= e($card['category_color']) ?>22;color:<?= e($card['category_color']) ?>"><?= icon('ticket', 'icon-lg') ?></div>
    <div style="flex:1;min-width:200px">
      <div class="mono" style="font-size:24px;font-weight:700;letter-spacing:.04em"><?= e($card['username']) ?></div>
      <div class="flex items-center gap-1 wrap mt-1">
        <?= CardRules::statusBadge($card) ?>
        <?php if ($open): ?><span class="badge badge-success badge-dot">متصلة الآن</span><?php endif; ?>
        <?php if ($card['short_code']): ?><span class="badge badge-primary"><?= icon('link-2') ?>مختصر: <span class="mono"><?= e($card['short_code']) ?></span></span><?php endif; ?>
        <?php if ($card['printed_at']): ?><span class="badge badge-outline"><?= icon('printer') ?>مطبوع</span><?php endif; ?>
        <?php if ($pending): ?><span class="badge badge-warning"><?= icon('clock') ?><?= num($pending) ?> مهمة معلّقة</span><?php endif; ?>
        <span class="text-muted text-sm">#<?= (int) $card['id'] ?></span>
      </div>
    </div>
    <?php if ($reason && $reason !== 'disabled'): ?><div class="notice notice-danger" style="margin:0"><?= icon('circle-x') ?><div><?= e(CardRules::message($reason)) ?></div></div><?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat">
    <div class="stat-icon is-info"><?= icon('timer') ?></div>
    <div class="stat-body"><div class="stat-label">الوقت المتبقي</div>
      <div class="stat-value" style="font-size:18px"><?= $remaining['time'] === null ? 'غير محدود' : e(fmt_duration(max(0, $remaining['time']))) ?></div>
      <div class="stat-meta">المستخدم <?= e(fmt_duration($card['used_seconds'])) ?><?= $timeLimit ? ' من ' . e(Units::describeSeconds($timeLimit)) : '' ?></div>
      <?php if ($timePct !== null): ?><div class="meter mt-1 <?= $timePct >= 90 ? 'is-danger' : '' ?>"><span style="width:<?= $timePct ?>%"></span></div><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-violet"><?= icon('database') ?></div>
    <div class="stat-body"><div class="stat-label">البيانات المتبقية</div>
      <div class="stat-value" style="font-size:18px"><?= $remaining['data'] === null ? 'غير محدودة' : e(fmt_bytes(max(0, $remaining['data']))) ?></div>
      <div class="stat-meta">تنزيل <?= e(fmt_bytes($card['used_bytes_out'])) ?> · رفع <?= e(fmt_bytes($card['used_bytes_in'])) ?></div>
      <?php if ($dataPct !== null): ?><div class="meter mt-1 <?= $dataPct >= 90 ? 'is-danger' : '' ?>"><span style="width:<?= $dataPct ?>%"></span></div><?php endif; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-warning"><?= icon('calendar-clock') ?></div>
    <div class="stat-body"><div class="stat-label">الانتهاء</div>
      <div class="stat-value" style="font-size:18px"><?= $card['expires_at'] ? e(fmt_dt($card['expires_at'])) : ($validity ? 'بعد أول دخول' : 'غير محدد') ?></div>
      <div class="stat-meta"><?= $card['expires_at'] ? (seconds_until($card['expires_at']) > 0 ? 'بعد ' . e(fmt_duration(seconds_until($card['expires_at']))) : 'منتهي') : ($validity ? 'الصلاحية ' . e(Units::describeSeconds($validity)) : '') ?></div>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-success"><?= icon('smartphone') ?></div>
    <div class="stat-body"><div class="stat-label">الجهاز</div>
      <div class="stat-value" style="font-size:16px"><?= e($card['last_hostname'] ?: ($card['bound_hostname'] ?: '—')) ?></div>
      <div class="stat-meta mono"><?= e($card['bound_mac'] ?: ($card['last_mac'] ?: '—')) ?></div>
    </div>
  </div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>بيانات الكرت</h3></div>
      <div class="panel-body">
        <dl class="kv kv-2">
          <dt>رقم الكرت</dt><dd><span class="mono"><?= e($card['username']) ?></span></dd>
          <dt>كلمة المرور</dt><dd><?php if ($card['password'] === $card['username']): ?>نفس الرقم<?php elseif (can('cards.passwords') || can('check.password')): ?><span class="mono"><?= e($card['password']) ?></span><?php else: ?>••••<?php endif; ?></dd>
          <dt>الرقم المختصر</dt><dd><span class="mono"><?= e($card['short_code'] ?? '—') ?></span></dd>
          <?php if (!empty($card['short_password'])): ?><dt>كلمة المرور المختصرة</dt><dd><?php if (can('cards.passwords') || can('check.password')): ?><span class="mono"><?= e($card['short_password']) ?></span><?php else: ?>••••<?php endif; ?></dd><?php endif; ?>
          <dt>الرقم التسلسلي الخارجي</dt><dd><span class="mono"><?= e($card['ext_serial'] ?? '—') ?></span></dd>
          <dt>الباقة</dt><dd><span class="swatch" style="background:<?= e($card['category_color']) ?>"></span> <?= e($card['category_name']) ?> · <?= e(money($card['category_price'])) ?></dd>
          <dt>السرعة</dt><dd><?= e(Units::describeRate($category)) ?></dd>
          <dt>عدد الأجهزة</dt><dd><?= (int) $category['shared_users'] === 0 ? 'غير محدود' : num($category['shared_users']) ?><?= $category['lock_mac'] ? ' · مرتبط بأول جهاز' : '' ?></dd>
          <dt>تاريخ الإنشاء</dt><dd><?= e(fmt_dt($card['created_at'])) ?></dd>
          <dt>البيع</dt><dd><?= $card['sold_at'] ? e(fmt_dt($card['sold_at'])) . ($card['sale_price'] !== null ? ' · ' . e(money($card['sale_price'])) : '') : '—' ?></dd>
          <dt>الموزع</dt><dd><?= $card['distributor_id'] ? '<a href="' . e(url('/distributors/' . $card['distributor_id'])) . '">' . e($card['distributor_name']) . '</a>' : '—' ?></dd>
          <dt>أول دخول</dt><dd><?= e(fmt_dt($card['first_login_at'])) ?></dd>
          <dt>آخر دخول</dt><dd><?= e(fmt_dt($card['last_login_at'])) ?></dd>
          <dt>آخر جهاز MikroTik</dt><dd><?= e($card['last_router_name'] ?? '—') ?></dd>
          <dt>آخر IP</dt><dd><span class="mono"><?= e($card['last_ip'] ?? '—') ?></span></dd>
          <dt>آخر جهاز</dt><dd><?= e($card['last_hostname'] ?? '—') ?> <span class="mono text-sm"><?= e($card['last_mac'] ?? '') ?></span></dd>
          <dt>الجهاز المرتبط</dt><dd><?= $card['bound_mac'] ? '<span class="mono">' . e($card['bound_mac']) . '</span>' . ($card['bound_hostname'] ? ' · ' . e($card['bound_hostname']) : '') : '—' ?></dd>
          <?php if ($card['is_disabled']): ?><dt>سبب الإيقاف</dt><dd><?= e($card['disabled_reason'] ?? '—') ?></dd><?php endif; ?>
          <?php if ((int) $card['bonus_time'] || (int) $card['bonus_data'] || (int) $card['bonus_validity']): ?>
          <dt>التمديدات</dt><dd><?= $card['bonus_time'] ? e(Units::describeSeconds((int) $card['bonus_time'])) . ' وقت · ' : '' ?><?= $card['bonus_validity'] ? e(Units::describeSeconds((int) $card['bonus_validity'])) . ' صلاحية · ' : '' ?><?= $card['bonus_data'] ? e(fmt_bytes($card['bonus_data'])) : '' ?></dd>
          <?php endif; ?>
        </dl>
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
          <?php if (!$sessions): ?><tr><td colspan="8"><div class="empty"><?= icon('activity') ?><div>لم يُستخدم الكرت بعد.</div></div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('router') ?>وجودها على أجهزة MikroTik</h3></div>
        <ul class="list">
          <?php foreach ($presence as $p): ?>
            <li class="list-item">
              <span class="list-icon <?= $p['state'] === 'present' ? 'is-success' : '' ?>"><?= icon('router') ?></span>
              <div class="list-item-main">
                <div class="list-item-title"><?= e($p['router_name']) ?></div>
                <div class="list-item-sub"><?= e(fmt_duration((int) $p['uptime'] + (int) $p['carry_uptime'], true)) ?> · <?= e(fmt_bytes((int) $p['bytes_in'] + (int) $p['bytes_out'] + (int) $p['carry_bytes_in'] + (int) $p['carry_bytes_out'])) ?></div>
              </div>
              <div class="list-item-end"><?= $p['state'] === 'present' ? '<span class="badge badge-success">موجودة</span>' : '<span class="badge">محذوفة</span>' ?><div><?= e(time_ago($p['synced_at'])) ?></div></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$presence): ?><li><div class="empty"><?= icon('router') ?><div>لم تُطبَّق على أي جهاز MikroTik بعد.</div></div></li><?php endif; ?>
        </ul>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('list-checks') ?>سجل الدخول</h3></div>
        <ul class="list" style="max-height:320px;overflow:auto">
          <?php foreach ($log as $l): ?>
            <li class="list-item">
              <span class="list-icon <?= $l['result'] === 'reject' ? 'is-danger' : ($l['result'] === 'accept' ? 'is-success' : 'is-info') ?>"><?= icon($l['event'] === 'logout' ? 'log-out' : ($l['result'] === 'reject' ? 'circle-x' : 'log-in')) ?></span>
              <div class="list-item-main">
                <div class="list-item-title"><?= $l['event'] === 'logout' ? 'خروج' : ($l['result'] === 'reject' ? e(CardRules::message((string) $l['reason'])) : ($l['event'] === 'login' ? 'دخول' : 'تحقق مقبول')) ?></div>
                <div class="list-item-sub"><?= e($l['router_name'] ?? '') ?> <span class="mono"><?= e($l['mac'] ?? '') ?></span></div>
              </div>
              <div class="list-item-end"><?= e(fmt_dt($l['created_at'])) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$log): ?><li><div class="empty"><?= icon('list-checks') ?><div>لا توجد سجلات.</div></div></li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="stack">
    <?php if (can('check.actions') || can('check.password')): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('wrench') ?>الإجراءات</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <?php if (can('check.actions')): ?>
          <?php if ($open): ?>
          <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="disconnect"><button class="btn btn-block"><?= icon('unplug') ?>فصل الجلسة الحالية</button></form>
          <?php endif; ?>
          <?php if ($card['is_disabled']): ?>
          <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="enable"><button class="btn btn-success btn-block"><?= icon('play') ?>تفعيل الكرت</button></form>
          <?php else: ?>
          <button type="button" class="btn btn-soft-danger btn-block" data-modal-open="disable-modal"><?= icon('ban') ?>إيقاف الكرت</button>
          <?php endif; ?>
          <?php if ($card['bound_mac']): ?>
          <form method="post" action="<?= e($action) ?>" data-confirm="فك ربط الكرت بالجهاز الحالي؟ يمكن بعدها استخدامه على جهاز آخر." data-confirm-type="primary"><?= csrf_field() ?><input type="hidden" name="op" value="unbind"><button class="btn btn-block"><?= icon('link-2-off') ?>فك ربط الجهاز</button></form>
          <?php endif; ?>
          <button type="button" class="btn btn-block" data-modal-open="extend-modal"><?= icon('calendar-clock') ?>تمديد</button>
          <form method="post" action="<?= e($action) ?>" data-confirm="إعادة الكرت كأنه جديد؟ يُصفَّر الاستهلاك والتواريخ والجهاز المرتبط."><?= csrf_field() ?><input type="hidden" name="op" value="reset"><button class="btn btn-block"><?= icon('rotate-ccw') ?>تصفير الكرت</button></form>
        <?php endif; ?>
        <?php if (can('check.password')): ?>
          <button type="button" class="btn btn-block" data-modal-open="password-modal"><?= icon('key-round') ?>تغيير كلمة المرور</button>
        <?php endif; ?>
        <?php if (can('cards.manage') && $routers): ?>
          <button type="button" class="btn btn-block" data-modal-open="push-modal"><?= icon('cloud-upload') ?>تطبيق على جهاز MikroTik</button>
        <?php endif; ?>
        <?php if (can('cards.print')): ?>
          <a class="btn btn-block" href="<?= e(url('/print', ['source' => 'ids', 'ids' => $card['id']])) ?>"><?= icon('printer') ?>طباعة</a>
        <?php endif; ?>
        <?php if (can('cards.delete')): ?>
          <form method="post" action="<?= e($action) ?>" data-confirm="حذف الكرت نهائياً من النظام وأجهزة MikroTik؟"><?= csrf_field() ?><input type="hidden" name="op" value="delete"><button class="btn btn-ghost btn-block text-danger"><?= icon('trash-2') ?>حذف الكرت</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="panel">
      <?= csrf_field() ?><input type="hidden" name="op" value="notes">
      <div class="panel-head"><h3><?= icon('pencil') ?>ملاحظات</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <textarea class="input" name="notes" rows="3"><?= e($card['notes'] ?? '') ?></textarea>
        <button class="btn btn-sm"><?= icon('save') ?>حفظ</button>
      </div>
    </form>
    <?php if ($sale): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('receipt') ?>عملية البيع</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>رقم العملية</dt><dd><a href="<?= e(url('/sales/' . $sale['id'])) ?>">#<?= (int) $sale['id'] ?></a></dd>
          <dt>التاريخ</dt><dd><?= e(fmt_dt($sale['created_at'])) ?></dd>
          <dt>البائع</dt><dd><?= e($sale['employee_name'] ?? '—') ?></dd>
        </dl>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<dialog class="modal modal-sm" id="disable-modal">
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="disable">
    <div class="modal-head"><h3><?= icon('ban') ?>إيقاف الكرت</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>السبب</label><input class="input" name="reason"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-danger"><?= icon('ban') ?>إيقاف</button></div>
  </form>
</dialog>
<dialog class="modal modal-sm" id="password-modal">
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="password">
    <div class="modal-head"><h3><?= icon('key-round') ?>تغيير كلمة المرور</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>كلمة المرور الجديدة</label><input class="input input-ltr" name="password" placeholder="تُولَّد تلقائياً عند تركها فارغة" autocomplete="off"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </form>
</dialog>
<dialog class="modal modal-sm" id="extend-modal">
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="extend">
    <div class="modal-head"><h3><?= icon('calendar-clock') ?>تمديد الكرت</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label>إضافة وقت استخدام</label><div class="input-group"><input class="input" name="add_time" inputmode="decimal"><select class="input" name="add_time_unit"><?php foreach (Units::TIME_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'hour' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label>إضافة صلاحية</label><div class="input-group"><input class="input" name="add_validity" inputmode="decimal"><select class="input" name="add_validity_unit"><?php foreach (Units::TIME_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'day' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label>إضافة بيانات</label><div class="input-group"><input class="input" name="add_data" inputmode="decimal"><select class="input" name="add_data_unit"><?php foreach (Units::DATA_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'GB' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تمديد</button></div>
  </form>
</dialog>
<?php if ($routers): ?>
<dialog class="modal modal-sm" id="push-modal">
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="push">
    <div class="modal-head"><h3><?= icon('cloud-upload') ?>تطبيق الكرت على جهاز MikroTik</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:8px">
      <?php foreach ($routers as $id => $name): ?><label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $id ?>"><?= e($name) ?></label><?php endforeach; ?>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('cloud-upload') ?>تطبيق</button></div>
  </form>
</dialog>
<?php endif; ?>
