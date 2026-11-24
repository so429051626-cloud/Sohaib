<?php
/**
 * الاستهلاك الزائد: الأعلى سحباً الآن، أكثر المستهلكين، والسرعات المحددة.
 * @var App\Core\View $v @var int $days @var array $live @var array $top @var array $caps @var int $threshold @var array $defaults @var int $heavyNow
 */
use App\Controllers\CardAccountController;
use App\Controllers\UsageController;
use App\Core\Request;
use App\Core\Settings;
use App\Services\UsageGuard;

$v->title = 'الاستهلاك الزائد';
$v->subtitle = 'من يسحب أكثر الآن، وأكثر المستهلكين، وتحديد سرعة من يبطّئ البرج';
$v->script('js/usage.js');
$canLimit = can('usage.limit');
$return = '/usage' . ($days !== 1 ? '?days=' . $days : '');
$who = static function (array $r): string {
    $label = $r['name'] ?: $r['username'];
    $kind = $r['kind'] === 'subscriber' ? 'مشترك' : ($r['kind'] === 'card' ? 'كرت' : 'مستخدم محلي');
    $href = $r['kind'] === 'subscriber' ? url('/subscribers/' . $r['ref_id']) : ($r['kind'] === 'card' && can('check.use') ? url('/check/card/' . $r['ref_id']) : null);
    $title = $href ? '<a class="cell-title" href="' . e($href) . '">' . e($label) . '</a>' : '<span class="cell-title">' . e($label) . '</span>';
    return $title . '<div class="cell-sub">' . e($kind) . ($r['name'] ? ' · <span class="mono">' . e($r['username']) . '</span>' : '') . '</div>';
};
$capCell = static function (array $r) use ($canLimit, $return): string {
    if (!$r['kind']) {
        return '<span class="text-muted text-sm">—</span>';
    }
    $h = '';
    if ($r['cap']) {
        $h .= '<span class="badge badge-warning">' . icon('gauge', 'icon-sm') . e($r['cap']['rate_down']) . ' / ' . e($r['cap']['rate_up']) . '</span>';
        if ($canLimit) {
            $h .= ' <form method="post" action="' . e(url('/usage/uncap')) . '" class="cap-inline">' . csrf_field()
                . '<input type="hidden" name="kind" value="' . e($r['kind']) . '"><input type="hidden" name="ref_id" value="' . (int) $r['ref_id'] . '"><input type="hidden" name="return" value="' . e($return) . '">'
                . '<button class="btn btn-ghost btn-xs">' . icon('rotate-ccw') . 'رفع</button></form>';
        }
        return $h;
    }
    return $canLimit ? '<button type="button" class="btn btn-soft-danger btn-xs" data-cap-open data-kind="' . e($r['kind']) . '" data-ref="' . (int) $r['ref_id'] . '" data-label="' . e($r['name'] ?: $r['username']) . '">' . icon('gauge') . 'تحديد السرعة</button>' : '';
};
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $heavyNow > 0 ? 'is-danger' : 'is-success' ?>"><?= icon('flame') ?></div><div class="stat-body"><div class="stat-label">يسحبون فوق الحد الآن</div><div class="stat-value"><?= num($heavyNow) ?></div><div class="stat-meta"><?= $threshold > 0 ? 'الحد ' . fmt_rate($threshold) : 'الكشف موقوف' ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('activity') ?></div><div class="stat-body"><div class="stat-label">الأعلى سحباً الآن</div><div class="stat-value"><?= $live && (int) $live[0]['rate_down'] > 0 ? fmt_rate((int) $live[0]['rate_down']) : '—' ?></div><div class="stat-meta"><?= $live && (int) $live[0]['rate_down'] > 0 ? e($live[0]['name'] ?: $live[0]['username']) : 'لا سحب ملحوظ' ?></div></div></div>
  <div class="stat"><div class="stat-icon is-warning"><?= icon('gauge') ?></div><div class="stat-body"><div class="stat-label">سرعات محددة فعّالة</div><div class="stat-value"><?= num(count($caps)) ?></div></div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><h2><?= icon('activity') ?>الأعلى سحباً الآن</h2><span class="text-muted text-sm">من آخر مزامنة لأجهزة MikroTik</span></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>المستخدم</th><th>جهاز MikroTik</th><th>التنزيل الآن</th><th>الرفع الآن</th><th>من حمل البرج</th><th>استهلاك الجلسة</th><th>السرعة</th></tr></thead>
      <tbody>
      <?php foreach ($live as $s):
          $rate = (int) $s['rate_down'];
          $wan = (int) ($s['rx_bps'] ?? 0);
          $share = $wan > 0 && $rate > 0 ? min(100, (int) round($rate / $wan * 100)) : null;
          $isHeavy = $s['heavy_since'] !== null; ?>
        <tr<?= $isHeavy ? ' class="row-heavy"' : '' ?>>
          <td><?= $who($s) ?></td>
          <td class="text-sm"><?= e($s['router_name'] ?? '—') ?></td>
          <td class="nowrap"><?= $s['rate_down'] !== null ? '<b>' . fmt_rate($rate) . '</b>' : '<span class="text-muted">—</span>' ?><?php if ($isHeavy): ?> <span class="badge badge-danger"><?= icon('flame', 'icon-sm') ?><?= e(time_ago($s['heavy_since'])) ?></span><?php endif; ?></td>
          <td class="nowrap text-sm"><?= $s['rate_up'] !== null ? fmt_rate((int) $s['rate_up']) : '—' ?></td>
          <td style="min-width:110px"><?php if ($share !== null): ?><div class="meter-row"><span><?= $share ?>%</span></div><div class="meter <?= $share >= 50 ? 'is-danger' : ($share >= 25 ? 'is-warning' : 'is-success') ?>"><span style="width:<?= $share ?>%"></span></div><?php else: ?><span class="text-muted text-sm">—</span><?php endif; ?></td>
          <td class="nowrap text-sm"><?= e(fmt_bytes((int) $s['bytes_out'])) ?> ↓ · <?= e(fmt_bytes((int) $s['bytes_in'])) ?> ↑</td>
          <td class="nowrap"><?= $capCell($s) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$live): ?><tr><td colspan="7"><div class="empty"><?= icon('activity') ?><div class="empty-title">لا يوجد متصلون الآن</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="panel-head"><h2><?= icon('chart-column') ?>أكثر المستهلكين</h2></div>
    <nav class="tabs">
      <?php foreach (UsageController::PERIODS as $d => $label): ?>
        <a class="tab<?= $days === $d ? ' is-active' : '' ?>" href="<?= e(url('/usage', $d !== 1 ? ['days' => $d] : [])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th class="num">#</th><th>المستخدم</th><th>التنزيل</th><th>الرفع</th><th class="num">الجلسات</th><th>آخر نشاط</th><th>السرعة</th></tr></thead>
        <tbody>
        <?php foreach ($top as $i => $r): ?>
          <tr>
            <td class="num text-muted"><?= $i + 1 ?></td>
            <td><?= $who($r) ?><?php if ((int) $r['online'] === 1): ?> <span class="badge badge-success badge-dot">متصل</span><?php endif; ?></td>
            <td class="nowrap"><b><?= e(fmt_bytes((int) $r['down'])) ?></b></td>
            <td class="nowrap text-sm"><?= e(fmt_bytes((int) $r['up'])) ?></td>
            <td class="num"><?= num((int) $r['sessions']) ?></td>
            <td class="nowrap text-sm"><?= e(time_ago($r['last_at'])) ?></td>
            <td class="nowrap"><?= $capCell($r) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="7"><div class="empty"><?= icon('chart-column') ?><div class="empty-title">لا يوجد استهلاك في هذه الفترة</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('gauge') ?>السرعات المحددة</h3></div>
      <ul class="list">
        <?php foreach ($caps as $c): ?>
        <li class="list-item">
          <span class="list-icon is-warning"><?= icon('gauge') ?></span>
          <div class="list-item-main">
            <div class="list-item-title"><?= e($c['name'] ?: $c['username']) ?> <span class="mono text-sm"><?= e($c['rate_down']) ?> / <?= e($c['rate_up']) ?></span></div>
            <div class="list-item-sub"><?= $c['ends_at'] ? 'حتى ' . e(fmt_dt($c['ends_at'])) : 'حتى يُرفع' ?><?= (int) $c['auto'] === 1 ? ' · تلقائي' : ($c['by_name'] ? ' · ' . e($c['by_name']) : '') ?><?= $c['reason'] ? ' · ' . e($c['reason']) : '' ?></div>
          </div>
          <?php if ($canLimit): ?>
          <div class="list-item-end"><form method="post" action="<?= e(url('/usage/uncap')) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($c['kind']) ?>"><input type="hidden" name="ref_id" value="<?= (int) $c['ref_id'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>"><button class="btn btn-sm"><?= icon('rotate-ccw') ?>رفع</button></form></div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$caps): ?><li class="list-item text-muted">لا توجد سرعات محددة.</li><?php endif; ?>
      </ul>
    </div>

    <?php if (can('settings.manage')):
        [$cdv, $cdu] = CardAccountController::splitRate($defaults['down']);
        [$cuv, $cuu] = CardAccountController::splitRate($defaults['up']); ?>
    <form class="panel" method="post" action="<?= e(url('/usage/settings')) ?>">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('settings') ?>الكشف والتنبيه</h3></div>
      <div class="panel-body stack" style="gap:12px">
        <label class="switch"><input type="checkbox" name="heavy_enabled" value="1"<?= checked(Settings::bool('usage.heavy_enabled')) ?>><span class="track"></span><span>تنبيه عند السحب الزائد</span></label>
        <div class="form-grid">
          <div class="field col-6"><label for="heavy_mbps">إذا تجاوز التنزيل</label><div class="input-group"><input class="input" id="heavy_mbps" name="heavy_mbps" value="<?= e(old('heavy_mbps', (string) Settings::get('usage.heavy_mbps', '20'))) ?>" inputmode="decimal"><span class="input-addon">ميغابت</span></div><?= error_html('heavy_mbps') ?></div>
          <div class="field col-6"><label for="heavy_minutes">لمدة متواصلة</label><div class="input-group"><input class="input" id="heavy_minutes" name="heavy_minutes" value="<?= e(old('heavy_minutes', (string) UsageGuard::minutes())) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div><?= error_html('heavy_minutes') ?></div>
        </div>
        <div class="dropdown-sep"></div>
        <div class="text-sm" style="font-weight:600">السرعة المحددة الافتراضية</div>
        <div class="form-grid">
          <div class="field col-6"><label for="cap_down">التنزيل</label><div class="input-group"><input class="input" id="cap_down" name="cap_down" value="<?= e(old('cap_down', $cdv)) ?>" inputmode="decimal"><select class="input" name="cap_down_unit" style="max-width:110px"><?php foreach (CardAccountController::RATE_UNITS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('cap_down_unit', $cdu), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?= error_html('cap_down') ?></div>
          <div class="field col-6"><label for="cap_up">الرفع</label><div class="input-group"><input class="input" id="cap_up" name="cap_up" value="<?= e(old('cap_up', $cuv)) ?>" inputmode="decimal"><select class="input" name="cap_up_unit" style="max-width:110px"><?php foreach (CardAccountController::RATE_UNITS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('cap_up_unit', $cuu), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
          <div class="field col-12"><label for="cap_minutes">المدة</label><select class="input" id="cap_minutes" name="cap_minutes"><?php foreach (UsageGuard::DURATIONS as $m => $l): ?><option value="<?= $m ?>"<?= selected(old('cap_minutes', (string) $defaults['minutes']), $m) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="switch"><input type="checkbox" name="auto_cap" value="1"<?= checked(Settings::bool('usage.auto_cap')) ?>><span class="track"></span><span>تحديد سرعة من يتجاوز الحد تلقائياً</span></label>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canLimit):
    [$dv, $du] = CardAccountController::splitRate($defaults['down']);
    [$uv, $uu] = CardAccountController::splitRate($defaults['up']); ?>
<dialog class="modal modal-sm" id="cap-modal">
  <form method="post" action="<?= e(url('/usage/cap')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" data-cap-kind><input type="hidden" name="ref_id" data-cap-ref><input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="modal-head"><h3><?= icon('gauge') ?>تحديد سرعة <span data-cap-label></span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label>التنزيل</label><div class="input-group"><input class="input" name="down" value="<?= e($dv) ?>" inputmode="decimal" required><select class="input" name="down_unit" style="max-width:110px"><?php foreach (CardAccountController::RATE_UNITS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($du, $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label>الرفع</label><div class="input-group"><input class="input" name="up" value="<?= e($uv) ?>" inputmode="decimal" required><select class="input" name="up_unit" style="max-width:110px"><?php foreach (CardAccountController::RATE_UNITS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($uu, $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label>المدة</label><select class="input" name="minutes"><?php foreach (UsageGuard::DURATIONS as $m => $l): ?><option value="<?= $m ?>"<?= selected($defaults['minutes'], $m) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>السبب</label><input class="input" name="reason" maxlength="160" placeholder="تحميل ضخم يبطّئ البرج"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تحديد</button></div>
  </form>
</dialog>
<?php endif; ?>
