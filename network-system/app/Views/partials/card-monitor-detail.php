<?php
/**
 * تفاصيل كرت داخل صفحة متابعة الكروت.
 * @var array $d نتيجة CardMonitor::details
 * @var App\Core\Request $request
 * @var array $wrongLabels
 */
use App\Services\CardRules;
use App\Services\Units;

$card = $d['card'];
$category = $d['category'];
$remaining = $d['remaining'];
$limits = $d['limits'];
$showPass = can('cards.passwords') || can('check.password');
$pct = static function (?int $limit, int $used): ?int {
    return $limit ? (int) max(0, min(100, round($used / $limit * 100))) : null;
};
$timePct = $pct($limits['time'], (int) $card['used_seconds']);
$dataPct = $pct($limits['data'], CardRules::usedBytes($card));
$until = seconds_until($card['expires_at']);
?>
<div class="panel mb-3" id="card-detail" style="scroll-margin-top:calc(var(--topbar-h) + 16px)">
  <div class="panel-body flex items-center gap-3 wrap">
    <div class="stat-icon" style="width:56px;height:56px;border-radius:14px;background:<?= e($card['category_color']) ?>22;color:<?= e($card['category_color']) ?>"><?= icon('ticket', 'icon-lg') ?></div>
    <div style="flex:1;min-width:200px">
      <div class="mono" style="font-size:24px;font-weight:700;letter-spacing:.04em;overflow-wrap:anywhere"><?= e($card['username']) ?></div>
      <div class="flex items-center gap-1 wrap mt-1">
        <?= CardRules::statusBadge($card) ?>
        <?php if ($d['online']): ?><span class="badge badge-success badge-dot">متصلة الآن</span><?php endif; ?>
        <?php if ($card['short_code']): ?><span class="badge badge-primary"><?= icon('link-2') ?>مختصر: <span class="mono"><?= e($card['short_code']) ?></span></span><?php endif; ?>
        <?php if ($card['printed_at']): ?><span class="badge badge-outline"><?= icon('printer') ?>مطبوع</span><?php endif; ?>
        <span class="text-muted text-sm">#<?= (int) $card['id'] ?></span>
      </div>
    </div>
    <div class="flex gap-1 wrap">
      <?php if (can('check.use')): ?><a class="btn" href="<?= e(url('/check/card/' . $card['id'])) ?>"><?= icon('scan-line') ?>فحص الكرت والإجراءات</a><?php endif; ?>
      <a class="btn btn-ghost btn-icon" href="<?= e($request->urlWith(['card' => '', 'find' => ''])) ?>" title="إغلاق" aria-label="إغلاق"><?= icon('x') ?></a>
    </div>
    <?php if ($d['reason'] && $d['reason'] !== 'disabled'): ?><div class="notice notice-danger" style="margin:0;flex-basis:100%"><?= icon('circle-x') ?><div><?= e(CardRules::message($d['reason'])) ?></div></div><?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat">
    <div class="stat-icon is-info"><?= icon('timer') ?></div>
    <div class="stat-body"><div class="stat-label">الوقت المتبقي</div>
      <div class="stat-value" style="font-size:18px"><?= $remaining['time'] === null ? 'غير محدود' : e(fmt_duration(max(0, $remaining['time']))) ?></div>
      <div class="stat-meta">المستخدم <?= e(fmt_duration($card['used_seconds'])) ?><?= $limits['time'] ? ' من ' . e(Units::describeSeconds($limits['time'])) : '' ?></div>
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
      <div class="stat-value" style="font-size:18px"><?= $card['expires_at'] ? e(fmt_dt($card['expires_at'])) : ($limits['validity'] ? 'بعد أول دخول' : 'غير محدد') ?></div>
      <div class="stat-meta"><?= $card['expires_at'] ? ($until > 0 ? 'بعد ' . e(fmt_duration($until)) : 'منتهي') : ($limits['validity'] ? 'الصلاحية ' . e(Units::describeSeconds($limits['validity'])) : '') ?></div>
    </div>
  </div>
  <div class="stat">
    <div class="stat-icon is-success"><?= icon('smartphone') ?></div>
    <div class="stat-body"><div class="stat-label">الجهاز</div>
      <div class="stat-value" style="font-size:16px"><?= e($card['last_hostname'] ?: ($card['bound_hostname'] ?: '—')) ?></div>
      <div class="stat-meta mono"><?= e($card['bound_mac'] ?: ($card['last_mac'] ?: '—')) ?><?= $card['last_ip'] ? ' · ' . e($card['last_ip']) : '' ?></div>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-3">
  <div class="panel">
    <div class="panel-head"><h3><?= icon('info') ?>بيانات الكرت</h3></div>
    <div class="panel-body">
      <dl class="kv">
        <dt>رقم الكرت</dt><dd><span class="mono"><?= e($card['username']) ?></span></dd>
        <dt>كلمة المرور</dt><dd><?php if ($card['password'] === $card['username']): ?>نفس الرقم<?php elseif ($showPass): ?><span class="mono"><?= e($card['password']) ?></span><?php else: ?>••••<?php endif; ?></dd>
        <dt>الرقم المختصر</dt><dd><span class="mono"><?= e($card['short_code'] ?? '—') ?></span></dd>
        <?php if (!empty($card['short_password'])): ?><dt>كلمة المرور المختصرة</dt><dd><?php if ($showPass): ?><span class="mono"><?= e($card['short_password']) ?></span><?php else: ?>••••<?php endif; ?></dd><?php endif; ?>
        <?php if (!empty($card['ext_serial'])): ?><dt>الرقم التسلسلي الخارجي</dt><dd><span class="mono"><?= e($card['ext_serial']) ?></span></dd><?php endif; ?>
        <dt>الباقة</dt><dd><span class="swatch" style="background:<?= e($card['category_color']) ?>"></span> <?= e($card['category_name']) ?> · <?= e(money($card['category_price'])) ?></dd>
        <dt>السرعة</dt><dd><?= e(Units::describeRate($category)) ?></dd>
        <dt>عدد الأجهزة</dt><dd><?= (int) $category['shared_users'] === 0 ? 'غير محدود' : num($category['shared_users']) ?><?= $category['lock_mac'] ? ' · مرتبط بأول جهاز' : '' ?></dd>
        <dt>الدفعة</dt><dd><?php if (can('cards.view')): ?><a href="<?= e(url('/batches/' . $card['batch_id'])) ?>"><?= e($card['batch_name']) ?></a><?php else: ?><?= e($card['batch_name']) ?><?php endif; ?></dd>
        <dt>الموزع</dt><dd><?= e($card['distributor_name'] ?? '—') ?></dd>
        <dt>تاريخ الإنشاء</dt><dd><?= e(fmt_dt($card['created_at'])) ?></dd>
        <dt>البيع</dt><dd><?= $card['sold_at'] ? e(fmt_dt($card['sold_at'])) . ($card['sale_price'] !== null ? ' · ' . e(money($card['sale_price'])) : '') : '—' ?></dd>
        <?php if ($card['is_disabled']): ?><dt>سبب الإيقاف</dt><dd><?= e($card['disabled_reason'] ?? '—') ?></dd><?php endif; ?>
        <?php if (!empty($card['notes'])): ?><dt>ملاحظات</dt><dd><?= e($card['notes']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('activity') ?>الاستخدام والاتصال</h3></div>
    <div class="panel-body">
      <dl class="kv">
        <dt>أول دخول</dt><dd><?= e(fmt_dt($card['first_login_at'])) ?></dd>
        <dt>آخر دخول</dt><dd><?= e(fmt_dt($card['last_login_at'])) ?></dd>
        <?php if ($card['ended_at']): ?><dt>تاريخ الانتهاء الفعلي</dt><dd><?= e(fmt_dt($card['ended_at'])) ?></dd><?php endif; ?>
        <dt>الوقت المستخدم</dt><dd><?= e(fmt_duration($card['used_seconds'])) ?></dd>
        <dt>البيانات المستخدمة</dt><dd><?= e(fmt_bytes(CardRules::usedBytes($card))) ?></dd>
        <dt>عدد الجلسات</dt><dd><?= num($d['sessions']) ?></dd>
        <dt>الجهاز المرتبط</dt><dd><?= $card['bound_mac'] ? '<span class="mono">' . e($card['bound_mac']) . '</span>' . ($card['bound_hostname'] ? ' · ' . e($card['bound_hostname']) : '') : '—' ?></dd>
        <dt>آخر جهاز</dt><dd><?= e($card['last_hostname'] ?? '—') ?> <span class="mono text-sm"><?= e($card['last_mac'] ?? '') ?></span></dd>
        <dt>آخر IP</dt><dd><span class="mono"><?= e($card['last_ip'] ?? '—') ?></span></dd>
        <dt>آخر جهاز MikroTik</dt><dd><?= e($card['last_router_name'] ?? '—') ?></dd>
        <dt>كلمة مرور خاطئة</dt><dd><?= $d['wrong'] > 0 ? e(ar_plural($d['wrong'], 'مرة واحدة', 'مرتين', 'مرات', 'مرة')) : '—' ?></dd>
        <?php if ((int) $card['bonus_time'] || (int) $card['bonus_data'] || (int) $card['bonus_validity']): ?>
        <dt>التمديدات</dt><dd><?= $card['bonus_time'] ? e(Units::describeSeconds((int) $card['bonus_time'])) . ' وقت · ' : '' ?><?= $card['bonus_validity'] ? e(Units::describeSeconds((int) $card['bonus_validity'])) . ' صلاحية · ' : '' ?><?= $card['bonus_data'] ? e(fmt_bytes($card['bonus_data'])) : '' ?></dd>
        <?php endif; ?>
      </dl>
    </div>
  </div>
</div>

<?php if ($d['online']): ?>
<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('wifi') ?>متصلة الآن</h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>جهاز MikroTik</th><th>الجهاز</th><th>MAC</th><th>IP</th><th>البداية</th><th>المدة</th><th class="num">البيانات</th></tr></thead>
      <tbody>
      <?php foreach ($d['online'] as $s): ?>
        <tr>
          <td><?= e($s['router_name'] ?? '—') ?></td>
          <td><?= e($s['hostname'] ?: '—') ?></td>
          <td><span class="mono text-sm"><?= e($s['mac'] ?? '—') ?></span></td>
          <td><span class="mono text-sm"><?= e($s['ip'] ?? '—') ?></span></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($s['started_at'])) ?></td>
          <td class="nowrap"><?= e(fmt_duration($s['uptime'], true)) ?></td>
          <td class="num"><?= e(fmt_bytes((int) $s['bytes_in'] + (int) $s['bytes_out'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('log-in') ?>آخر محاولات الدخول</h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>الوقت</th><th>المُدخل</th><th>النتيجة</th><th>الجهاز</th><th>MAC</th><th>IP</th><th>جهاز MikroTik</th></tr></thead>
      <tbody>
      <?php foreach ($d['attempts'] as $l): ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($l['created_at'], true)) ?></td>
          <td><span class="mono text-sm"><?= e($l['login']) ?></span></td>
          <td class="nowrap">
            <?php if ($l['result'] === 'accept'): ?><span class="badge badge-success">مقبول</span>
            <?php elseif ($l['result'] === 'reject'): ?><span class="badge badge-danger">مرفوض</span>
            <?php else: ?><span class="badge badge-outline">معلومة</span><?php endif; ?>
            <?php if ($l['reason'] && $l['result'] !== 'accept'): ?><div class="cell-sub"><?= e($wrongLabels[$l['reason']] ?? rtrim(CardRules::message((string) $l['reason']), '.')) ?></div><?php endif; ?>
          </td>
          <td class="text-sm"><?= e($l['hostname'] ?: '—') ?></td>
          <td><span class="mono text-sm"><?= e($l['mac'] ?? '—') ?></span></td>
          <td><span class="mono text-sm"><?= e($l['ip'] ?? '—') ?></span></td>
          <td class="text-sm"><?= e($l['router_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$d['attempts']): ?><tr><td colspan="7"><div class="empty"><?= icon('log-in') ?><div class="empty-title">لا توجد محاولات دخول</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
