<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
use App\Services\CardMonitor;
use App\Services\CardRules;

$v->title = 'متابعة الكروت';
$v->breadcrumbs = [['الكروت', can('cards.view') ? '/cards' : null], ['متابعة الكروت', null]];
$v->subtitle = num($total) . ' ' . ($tab === 'wrong' ? 'محاولة مطابقة' : 'كرت مطابق');
$showPass = can('cards.passwords');
$canDelete = can('cards.delete');
$wrongLabels = ['not_found' => 'رقم غير موجود', 'wrong_password' => 'كلمة مرور خاطئة'];
$endedLabels = ['expired' => 'منتهية الصلاحية', 'depleted' => 'مستنفدة الرصيد'];
$detailUrl = static fn (int $id): string => $request->urlWith(['card' => $id, 'find' => '']) . '#card-detail';
// سبب انتهاء الكرت بصيغة مقروءة
$endReason = static function (array $c): string {
    if ($c['status'] === 'expired') {
        return 'انتهت الصلاحية';
    }
    $r = $c['remaining'];
    if ($r['time'] !== null && $r['time'] <= 0) {
        return 'نفد رصيد الوقت';
    }
    if ($r['data'] !== null && $r['data'] <= 0) {
        return 'نفد رصيد البيانات';
    }
    return 'نفد الرصيد';
};
$hidden = static function (array $filters): string {
    $h = '';
    foreach ($filters as $k => $val) {
        if ($val !== '') {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($val) . '">';
        }
    }
    return $h;
};
$password = static function (array $c) use ($showPass): string {
    if ($c['password'] === $c['username']) {
        return '<span class="text-muted text-sm nowrap">نفس الرقم</span>';
    }
    return $showPass ? '<button type="button" class="btn btn-ghost btn-xs" data-reveal="' . e($c['password']) . '">' . icon('eye') . 'إظهار</button>' : '••••';
};
$device = static function (?string $host, ?string $mac, ?string $ip): string {
    if (!$mac && !$ip && !$host) {
        return '<span class="text-muted">—</span>';
    }
    $html = $host ? '<div class="text-sm">' . e($host) . '</div>' : '';
    $html .= $mac ? '<div class="mono text-sm">' . e($mac) . '</div>' : '';
    $html .= $ip ? '<div class="mono text-sm text-muted">' . e($ip) . '</div>' : '';
    return $html;
};
?>
<div class="stats">
  <a class="stat" href="<?= e(url('/cards/monitor', ['tab' => 'active'])) ?>">
    <div class="stat-icon is-success"><?= icon('circle-check') ?></div>
    <div class="stat-body"><div class="stat-label">الكروت النشطة</div><div class="stat-value"><?= num($counts['active']) ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/cards/monitor', ['tab' => 'active', 'online' => 'yes'])) ?>">
    <div class="stat-icon is-info"><?= icon('wifi') ?></div>
    <div class="stat-body"><div class="stat-label">متصلة الآن</div><div class="stat-value"><?= num($counts['online']) ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/cards/monitor', ['tab' => 'ended'])) ?>">
    <div class="stat-icon is-warning"><?= icon('hourglass') ?></div>
    <div class="stat-body"><div class="stat-label">الكروت المنتهية</div><div class="stat-value"><?= num($counts['ended']) ?></div></div>
  </a>
  <a class="stat" href="<?= e(url('/cards/monitor', ['tab' => 'wrong'])) ?>">
    <div class="stat-icon is-danger"><?= icon('shield-alert') ?></div>
    <div class="stat-body"><div class="stat-label">المُدخلة خطأ</div><div class="stat-value"><?= num($counts['wrong']) ?></div><div class="stat-meta">آخر 24 ساعة</div></div>
  </a>
</div>

<div class="panel mb-3">
  <form class="filters" method="get" action="<?= e(url('/cards/monitor')) ?>" style="border-bottom:0">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="field grow"><label for="find">البحث عن كرت</label><input class="input input-ltr" id="find" name="find" value="<?= e($find) ?>" placeholder="رقم الكرت، المختصر، #الرقم، MAC، IP" autocomplete="off" maxlength="64"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
    <?php if ($find !== '' || $detail): ?>
      <div class="field" style="flex:0 0 auto"><a class="btn" href="<?= e($request->urlWith(['card' => '', 'find' => ''])) ?>"><?= icon('x') ?>إغلاق</a></div>
    <?php endif; ?>
  </form>
</div>

<?php if ($detail): ?>
  <?= $v->partial('card-monitor-detail', ['d' => $detail, 'request' => $request, 'wrongLabels' => $wrongLabels]) ?>
<?php elseif ($matches): ?>
  <div class="panel mb-3">
    <div class="panel-head"><h3><?= icon('search') ?>نتائج البحث (<?= num(count($matches)) ?>)</h3></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>رقم الكرت</th><th>الحالة</th><th>الباقة</th><th>آخر دخول</th><th>الانتهاء</th><th>الجهاز</th><th class="w-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($matches as $m): ?>
          <tr>
            <td><a class="mono cell-title" href="<?= e($detailUrl((int) $m['id'])) ?>"><?= e($m['username']) ?></a><?= $m['short_code'] ? '<div class="cell-sub mono">' . e($m['short_code']) . '</div>' : '' ?></td>
            <td class="nowrap"><?= CardRules::statusBadge($m) ?></td>
            <td class="nowrap"><span class="swatch" style="background:<?= e($m['category_color']) ?>"></span> <?= e($m['category_name']) ?></td>
            <td class="nowrap text-sm"><?= e(fmt_dt($m['last_login_at'])) ?></td>
            <td class="nowrap text-sm"><?= e(fmt_dt($m['expires_at'])) ?></td>
            <td><span class="mono text-sm"><?= e($m['bound_mac'] ?: ($m['last_mac'] ?: '—')) ?></span></td>
            <td class="w-actions"><a class="btn btn-sm" href="<?= e($detailUrl((int) $m['id'])) ?>"><?= icon('eye') ?>التفاصيل</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php elseif ($find !== ''): ?>
  <div class="notice notice-warning mb-3"><?= icon('circle-alert') ?>
    <div>
      <div class="notice-title">لا يوجد كرت بهذا الرقم: <span class="mono"><?= e($find) ?></span></div>
      <?php if ($findWrong > 0): ?>
        <div class="text-sm">أُدخل هذا الرقم خطأ <?= e(ar_plural($findWrong, 'مرة واحدة', 'مرتين', 'مرات', 'مرة')) ?> في صفحة الهوتسبوت. <a href="<?= e(url('/cards/monitor', ['tab' => 'wrong', 'q' => $find])) ?>">عرض المحاولات</a></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel" data-bulk-scope>
  <nav class="tabs">
    <?php foreach (CardMonitor::TABS as $key => $label): ?>
      <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('/cards/monitor', ['tab' => $key])) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
        <?= e($label) ?><?php if ($key !== 'wrong'): ?><span class="tab-count"><?= num($counts[$key]) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <form class="filters" method="get" action="<?= e(url('/cards/monitor')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <?php if ($tab === 'wrong'): ?>
      <div class="field grow"><label for="q">بحث</label><input class="input input-ltr" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="الرقم المُدخل، MAC، IP"></div>
      <div class="field">
        <label for="reason">السبب</label>
        <select class="input" id="reason" name="reason"><option value="">الكل</option>
          <?php foreach ($wrongLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($filters['reason'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="router">جهاز MikroTik</label>
        <select class="input" id="router" name="router"><option value="">الكل</option>
          <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['router'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <div class="field grow"><label for="q">بحث</label><input class="input input-ltr" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="رقم الكرت، المختصر، #الرقم، MAC، IP"></div>
      <div class="field">
        <label for="category">الباقة</label>
        <select class="input" id="category" name="category"><option value="">الكل</option>
          <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['category'], (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($tab === 'active'): ?>
        <div class="field">
          <label for="online">الاتصال</label>
          <select class="input" id="online" name="online"><option value="">الكل</option>
            <option value="yes"<?= selected($filters['online'], 'yes') ?>>متصلة الآن</option>
            <option value="no"<?= selected($filters['online'], 'no') ?>>غير متصلة</option>
          </select>
        </div>
        <div class="field">
          <label for="sort">الترتيب</label>
          <select class="input" id="sort" name="sort">
            <?php foreach (CardMonitor::SORTS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($filters['sort'] ?: 'recent', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <div class="field">
          <label for="status">نوع الانتهاء</label>
          <select class="input" id="status" name="status"><option value="">الكل</option>
            <?php foreach ($endedLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($filters['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($tab !== 'active'): ?>
      <div class="field"><label for="from">من</label><input class="input" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
      <div class="field"><label for="to">إلى</label><input class="input" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
    <?php endif; ?>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <?php if ($tab === 'active'): ?>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead>
        <tr>
          <th class="hide-sm">#</th><th>رقم الكرت</th><th>كلمة المرور</th><th>الباقة</th><th>الاتصال</th>
          <th>الانتهاء</th><th>المتبقي</th><th>المستخدم</th><th>الجهاز</th><th>جهاز MikroTik</th><th>الدفعة</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $c): $r = $c['remaining']; $until = seconds_until($c['expires_at']); ?>
        <tr>
          <td class="mono text-sm text-muted hide-sm"><?= (int) $c['id'] ?></td>
          <td><a class="mono cell-title" href="<?= e($detailUrl((int) $c['id'])) ?>"><?= e($c['username']) ?></a><?= $c['short_code'] ? '<div class="cell-sub">مختصر: <span class="mono">' . e($c['short_code']) . '</span></div>' : '' ?></td>
          <td><?= $password($c) ?></td>
          <td class="nowrap"><span class="swatch" style="background:<?= e($c['category_color']) ?>"></span> <?= e($c['category_name']) ?><div class="cell-sub"><?= e(money($c['category_price'])) ?></div></td>
          <td class="nowrap">
            <?php if ((int) $c['online_count'] > 0): ?><span class="badge badge-success badge-dot">متصلة<?= (int) $c['online_count'] > 1 ? ' (' . num($c['online_count']) . ')' : '' ?></span>
            <?php else: ?><span class="badge badge-outline">غير متصلة</span><?php endif; ?>
            <div class="cell-sub">آخر دخول <?= e(fmt_dt($c['last_login_at'])) ?></div>
          </td>
          <td class="nowrap text-sm">
            <?php if ($c['expires_at']): ?><?= e(fmt_dt($c['expires_at'])) ?><div class="cell-sub"><?= $until !== null && $until > 0 ? 'بعد ' . e(fmt_duration($until)) : 'حان موعد الانتهاء' ?></div>
            <?php else: ?><span class="text-muted">غير محدد</span><?php endif; ?>
            <div class="cell-sub">أول دخول <?= e(fmt_dt($c['first_login_at'])) ?></div>
          </td>
          <td class="nowrap text-sm">
            <div><?= icon('timer', 'icon-sm text-muted') ?> <?= $r['time'] === null ? 'وقت غير محدود' : e(fmt_duration(max(0, $r['time']))) ?></div>
            <div><?= icon('database', 'icon-sm text-muted') ?> <?= $r['data'] === null ? 'بيانات غير محدودة' : e(fmt_bytes(max(0, $r['data']))) ?></div>
          </td>
          <td class="nowrap text-sm"><?= e(fmt_duration($c['used_seconds'], true)) ?><div class="cell-sub"><?= e(fmt_bytes(CardRules::usedBytes($c))) ?></div></td>
          <td><?= $device($c['last_hostname'], $c['bound_mac'] ?: $c['last_mac'], $c['last_ip']) ?></td>
          <td class="text-sm"><?= e($c['last_router_name'] ?? '—') ?></td>
          <td class="text-sm"><?php if (can('cards.view')): ?><a href="<?= e(url('/batches/' . $c['batch_id'])) ?>"><?= e(str_limit($c['batch_name'], 26)) ?></a><?php else: ?><?= e(str_limit($c['batch_name'], 26)) ?><?php endif; ?><?= $c['distributor_name'] ? '<div class="cell-sub">' . e($c['distributor_name']) . '</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11"><div class="empty"><?= icon('ticket') ?><div class="empty-title">لا توجد كروت نشطة</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($tab === 'ended'): ?>
  <?php if ($canDelete): ?>
  <div class="bulkbar">
    <span>المحدد: <strong data-selected-count>0</strong></span>
    <form method="post" action="<?= e(url('/cards/monitor/delete')) ?>" data-bulk-form class="flex gap-1 wrap items-center" style="flex:1">
      <?= csrf_field() ?>
      <?= $hidden($filters) ?>
      <button type="submit" class="btn btn-sm btn-danger" data-confirm="سيتم حذف الكروت المنتهية المحددة نهائياً من النظام ومن أجهزة MikroTik. متابعة؟"><?= icon('trash-2') ?>حذف المحدد</button>
      <label class="check text-sm ms-auto"><input type="checkbox" name="all_matching" value="1">تحديد كل النتائج (<?= num($total) ?>)</label>
    </form>
  </div>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead>
        <tr>
          <?php if ($canDelete): ?><th class="w-check"><input type="checkbox" data-select-all aria-label="تحديد الكل"></th><?php endif; ?>
          <th class="hide-sm">#</th><th>رقم الكرت</th><th>كلمة المرور</th><th>الباقة</th><th>سبب الانتهاء</th>
          <th>تاريخ الانتهاء</th><th>الاستخدام</th><th>آخر جهاز</th><th>جهاز MikroTik</th><th>الدفعة</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <?php if ($canDelete): ?><td class="w-check"><input type="checkbox" data-row-check value="<?= (int) $c['id'] ?>" aria-label="تحديد"></td><?php endif; ?>
          <td class="mono text-sm text-muted hide-sm"><?= (int) $c['id'] ?></td>
          <td><a class="mono cell-title" href="<?= e($detailUrl((int) $c['id'])) ?>"><?= e($c['username']) ?></a><?= $c['short_code'] ? '<div class="cell-sub">مختصر: <span class="mono">' . e($c['short_code']) . '</span></div>' : '' ?></td>
          <td><?= $password($c) ?></td>
          <td class="nowrap"><span class="swatch" style="background:<?= e($c['category_color']) ?>"></span> <?= e($c['category_name']) ?><div class="cell-sub"><?= e(money($c['category_price'])) ?></div></td>
          <td class="nowrap"><?= CardRules::statusBadge($c) ?><div class="cell-sub"><?= e($endReason($c)) ?></div></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($c['ended_at'] ?: $c['expires_at'])) ?><div class="cell-sub">أول دخول <?= e(fmt_dt($c['first_login_at'])) ?></div></td>
          <td class="nowrap text-sm"><?= e(fmt_duration($c['used_seconds'], true)) ?><div class="cell-sub"><?= e(fmt_bytes(CardRules::usedBytes($c))) ?></div></td>
          <td><?= $device($c['last_hostname'], $c['bound_mac'] ?: $c['last_mac'], $c['last_ip']) ?></td>
          <td class="text-sm"><?= e($c['last_router_name'] ?? '—') ?></td>
          <td class="text-sm"><?php if (can('cards.view')): ?><a href="<?= e(url('/batches/' . $c['batch_id'])) ?>"><?= e(str_limit($c['batch_name'], 26)) ?></a><?php else: ?><?= e(str_limit($c['batch_name'], 26)) ?><?php endif; ?><?= $c['distributor_name'] ? '<div class="cell-sub">' . e($c['distributor_name']) . '</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= $canDelete ? 11 : 10 ?>"><div class="empty"><?= icon('hourglass') ?><div class="empty-title">لا توجد كروت منتهية</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php else: ?>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead>
        <tr><th>الوقت</th><th>الرقم المُدخل</th><th>السبب</th><th>الجهاز</th><th>MAC</th><th>IP</th><th>جهاز MikroTik</th><th class="num">محاولات الجهاز</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td class="nowrap text-sm"><?= e(fmt_dt($l['created_at'], true)) ?><div class="cell-sub"><?= e(time_ago($l['created_at'])) ?></div></td>
          <td>
            <span class="mono cell-title"><?= e($l['login']) ?></span>
            <?php if ($l['card_id'] && $l['card_username'] !== null): ?>
              <div class="cell-sub"><a href="<?= e($detailUrl((int) $l['card_id'])) ?>">الكرت <span class="mono"><?= e($l['card_username']) ?></span></a></div>
            <?php endif; ?>
          </td>
          <td class="nowrap"><span class="badge badge-danger"><?= e($wrongLabels[$l['reason']] ?? rtrim(CardRules::message((string) $l['reason']), '.')) ?></span></td>
          <td class="text-sm"><?= e($l['hostname'] ?: '—') ?></td>
          <td class="nowrap">
            <?php if ($l['mac']): ?>
              <?php if (can('devices.view')): ?><a class="mono text-sm" href="<?= e(url('/devices', ['q' => $l['mac']])) ?>"><?= e($l['mac']) ?></a><?php else: ?><span class="mono text-sm"><?= e($l['mac']) ?></span><?php endif; ?>
              <?php if ($l['mac_blocked']): ?> <span class="badge badge-danger"><?= icon('ban') ?>محظور</span><?php endif; ?>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td><span class="mono text-sm"><?= e($l['ip'] ?: '—') ?></span></td>
          <td class="text-sm"><?= e($l['router_name'] ?? '—') ?></td>
          <td class="num"><?php if ($l['mac']): ?><a href="<?= e(url('/cards/monitor', ['tab' => 'wrong', 'q' => $l['mac']])) ?>"><?= num($l['mac_attempts']) ?></a><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= icon('shield-check') ?><div class="empty-title">لا توجد محاولات خاطئة</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?= $pager->render($request) ?>
</div>
