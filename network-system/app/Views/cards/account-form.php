<?php
/**
 * إنشاء حساب خاص أو تعديله.
 * @var App\Core\View $v @var ?array $acc @var array $routers @var string $codeCase
 */
use App\Controllers\CardAccountController;
use App\Core\Session;
use App\Services\CardRules;
use App\Services\CustomAccounts;
use App\Services\Units;

$isEdit = $acc !== null;
$card = $acc['card'] ?? null;
$cat = $acc['category'] ?? null;
$v->title = $isEdit ? 'تعديل الحساب ' . $card['username'] : 'حساب خاص جديد';
$v->breadcrumbs = [['الحسابات الخاصة', '/cards/accounts'], [$isEdit ? $card['username'] : 'حساب جديد', null]];

// بعد خطأ في التحقق تُعرض القيم المُرسلة كما هي، ومنها مفاتيح التشغيل غير المحددة
$old = Session::get('_old_now', []);
$posted = is_array($old) && $old !== [];
$val = static fn (string $k, mixed $d = '') => old($k, $d);
$on = static fn (string $k, bool $d): bool => $posted ? !empty($old[$k]) : $d;

$holder = $isEdit && $acc['batch']['name'] !== CustomAccounts::NAME_PREFIX . $card['username'] ? (string) $acc['batch']['name'] : '';
$password = $isEdit && !CustomAccounts::usernameOnly($card) ? (string) $card['password'] : '';
[$tv, $tu] = Units::splitSeconds($cat && $cat['time_limit'] !== null ? (int) $cat['time_limit'] : null);
[$vv, $vu] = Units::splitSeconds($cat && $cat['validity'] !== null ? (int) $cat['validity'] : null);
[$dv, $du] = Units::splitBytes($cat && $cat['data_limit'] !== null ? (int) $cat['data_limit'] : null);
if (!$cat || $cat['validity'] === null) {
    $vu = 'day';
}
$unlimited = $on('unlimited', $cat ? CustomAccounts::isUnlimited($cat) : false);
$scope = old('router_scope', $acc && $acc['routers'] ? 'selected' : 'all');
$chosen = array_map('intval', (array) old('routers', $acc['routers'] ?? []));

$unitSelect = static function (string $name, string $current, array $units): string {
    $h = '<select class="input" name="' . e($name) . '" style="max-width:150px" aria-label="الوحدة">';
    foreach ($units as $k => $u) {
        $label = is_array($u) ? $u[0] : $u;
        $h .= '<option value="' . e((string) $k) . '"' . selected($current, $k) . '>' . e($label) . '</option>';
    }
    return $h . '</select>';
};
$rateField = static function (string $name, string $label, ?string $stored, string $placeholder) use ($val, $unitSelect): string {
    [$num, $unit] = CardAccountController::splitRate($stored);
    return '<div class="field col-6"><label for="' . e($name) . '">' . e($label) . '</label><div class="input-group">'
        . '<input class="input input-ltr" id="' . e($name) . '" name="' . e($name) . '" value="' . e((string) $val($name, $num)) . '" inputmode="decimal" placeholder="' . e($placeholder) . '">'
        . $unitSelect($name . '_unit', (string) $val($name . '_unit', $unit), CardAccountController::RATE_UNITS)
        . '</div>' . error_html($name) . '</div>';
};
$hasBurst = $cat && ($cat['burst_rate_down'] || $cat['burst_rate_up']);
?>
<form method="post" action="<?= e(url($isEdit ? '/cards/accounts/' . $card['id'] : '/cards/accounts')) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('key-round') ?>بيانات الدخول</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="username">اسم المستخدم <?php if (!$isEdit): ?><span class="req">*</span><?php endif; ?></label>
              <?php if ($isEdit): ?>
                <input class="input input-ltr mono" id="username" value="<?= e($card['username']) ?>" disabled>
              <?php else: ?>
                <input class="input input-ltr mono" id="username" name="username" value="<?= e($val('username')) ?>" required maxlength="64" autocapitalize="off" spellcheck="false" placeholder="ahmad">
                <?php if ($codeCase !== 'none'): ?><span class="hint"><?= $codeCase === 'lower' ? 'يُحفظ بأحرف صغيرة' : 'يُحفظ بأحرف كبيرة' ?></span><?php endif; ?>
              <?php endif; ?>
              <?= error_html('username') ?>
            </div>
            <div class="field col-6">
              <label for="password">كلمة المرور</label>
              <input class="input input-ltr mono" id="password" name="password" value="<?= e($val('password', $password)) ?>" maxlength="64" autocomplete="new-password" autocapitalize="off" spellcheck="false" placeholder="بدون — الدخول باسم المستخدم فقط">
              <?= error_html('password') ?>
            </div>
            <div class="field col-6">
              <label for="holder">اسم صاحب الحساب</label>
              <input class="input" id="holder" name="holder" value="<?= e($val('holder', $holder)) ?>" maxlength="120">
              <?= error_html('holder') ?>
            </div>
            <div class="field col-6">
              <label for="notes">ملاحظات</label>
              <input class="input" id="notes" name="notes" value="<?= e($val('notes', $card['notes'] ?? '')) ?>" maxlength="255">
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2><?= icon('gauge') ?>السرعة والمدة والحدود</h2></div>
        <div class="panel-body">
          <label class="switch account-unlimited"><input type="checkbox" name="unlimited" value="1"<?= checked($unlimited) ?>><span class="track"></span><span><b>حساب غير محدود</b> — بلا حد للسرعة والوقت والأيام والبيانات وعدد الأجهزة</span></label>

          <div class="form-grid mt-3" data-show-when="unlimited=0"<?= $unlimited ? ' hidden' : '' ?>>
            <div class="form-section-title">السرعة</div>
            <?= $rateField('rate_down', 'سرعة التنزيل', $cat['rate_down'] ?? null, 'غير محدودة') ?>
            <?= $rateField('rate_up', 'سرعة الرفع', $cat['rate_up'] ?? null, 'غير محدودة') ?>
            <div class="col-12">
              <details<?= $hasBurst || $val('burst_rate_down') !== '' || $val('burst_rate_up') !== '' ? ' open' : '' ?>>
                <summary style="cursor:pointer;font-weight:500;font-size:13.5px">السرعة المؤقتة (Burst)</summary>
                <div class="form-grid mt-2">
                  <?= $rateField('burst_rate_down', 'سرعة الدفعة للتنزيل', $cat['burst_rate_down'] ?? null, 'بدون') ?>
                  <?= $rateField('burst_rate_up', 'سرعة الدفعة للرفع', $cat['burst_rate_up'] ?? null, 'بدون') ?>
                  <?= $rateField('burst_threshold_down', 'عتبة التنزيل', $cat['burst_threshold_down'] ?? null, 'سرعة التنزيل') ?>
                  <?= $rateField('burst_threshold_up', 'عتبة الرفع', $cat['burst_threshold_up'] ?? null, 'سرعة الرفع') ?>
                  <div class="field col-6"><label for="burst_time">زمن الدفعة (ثانية)</label><input class="input input-ltr" id="burst_time" name="burst_time" value="<?= e($val('burst_time', $cat['burst_time'] ?? '')) ?>" inputmode="numeric" placeholder="10"><?= error_html('burst_time') ?></div>
                </div>
              </details>
            </div>

            <div class="form-section-title">المدة والصلاحية</div>
            <div class="field col-4">
              <label for="time_value">مدة الاستخدام الفعلي</label>
              <div class="input-group">
                <input class="input" id="time_value" name="time_value" value="<?= e($val('time_value', $tv)) ?>" inputmode="decimal" placeholder="غير محدودة">
                <?= $unitSelect('time_unit', (string) $val('time_unit', $tu), Units::TIME_UNITS) ?>
              </div>
              <?= error_html('time_value') ?>
            </div>
            <div class="field col-4">
              <label for="validity_value">الصلاحية من أول دخول</label>
              <div class="input-group">
                <input class="input" id="validity_value" name="validity_value" value="<?= e($val('validity_value', $vv)) ?>" inputmode="decimal" placeholder="غير محدودة">
                <?= $unitSelect('validity_unit', (string) $val('validity_unit', $vu), Units::TIME_UNITS) ?>
              </div>
              <?= error_html('validity_value') ?>
            </div>
            <div class="field col-4">
              <label for="data_value">حجم البيانات</label>
              <div class="input-group">
                <input class="input" id="data_value" name="data_value" value="<?= e($val('data_value', $dv)) ?>" inputmode="decimal" placeholder="غير محدود">
                <?= $unitSelect('data_unit', (string) $val('data_unit', $du), Units::DATA_UNITS) ?>
              </div>
              <?= error_html('data_value') ?>
            </div>

            <div class="form-section-title">الاستخدام</div>
            <div class="field col-4">
              <label for="shared_users">عدد الأجهزة في نفس الوقت</label>
              <input class="input" id="shared_users" name="shared_users" value="<?= e($val('shared_users', $cat ? (string) (int) $cat['shared_users'] : '1')) ?>" inputmode="numeric">
              <span class="hint">0 = غير محدود</span>
              <?= error_html('shared_users') ?>
            </div>
            <div class="field col-4">
              <label for="idle_value">قطع الاتصال عند الخمول بعد (دقيقة)</label>
              <input class="input" id="idle_value" name="idle_value" value="<?= e($val('idle_value', $cat && $cat['idle_timeout'] ? (string) intdiv((int) $cat['idle_timeout'], 60) : '')) ?>" inputmode="numeric" placeholder="بدون">
              <?= error_html('idle_value') ?>
            </div>
            <div class="field col-4 account-switch-cell">
              <label class="switch"><input type="checkbox" name="lock_mac" value="1"<?= checked($on('lock_mac', $cat ? (int) $cat['lock_mac'] === 1 : false)) ?>><span class="track"></span><span>ربط الحساب بأول جهاز يستخدمه</span></label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('settings') ?>الحالة</h3><?php if ($isEdit): ?><?= $card['status'] === 'sold' ? '<span class="badge badge-info">جاهز</span>' : CardRules::statusBadge(['is_disabled' => 0] + $card) ?><?php endif; ?></div>
        <div class="panel-body stack" style="gap:14px">
          <label class="switch"><input type="checkbox" name="enabled" value="1"<?= checked($on('enabled', $card ? (int) $card['is_disabled'] === 0 : true)) ?>><span class="track"></span><span>الحساب مفعّل</span></label>
          <?php if ($isEdit):
              $r = CardRules::remaining($card, $cat); ?>
          <dl class="account-facts">
            <div><dt>أول دخول</dt><dd><?= e(fmt_dt($card['first_login_at'])) ?></dd></div>
            <div><dt>آخر دخول</dt><dd><?= e(fmt_dt($card['last_login_at'])) ?></dd></div>
            <div><dt>ينتهي في</dt><dd><?= $card['expires_at'] ? e(fmt_dt($card['expires_at'])) : ($cat['validity'] !== null ? 'من أول دخول' : 'غير محدود') ?></dd></div>
            <div><dt>الوقت المستخدم</dt><dd><?= (int) $card['used_seconds'] > 0 ? e(fmt_duration((int) $card['used_seconds'])) : '—' ?><?= $r['time'] !== null ? ' · متبقٍ ' . e(fmt_duration(max(0, (int) $r['time']))) : '' ?></dd></div>
            <div><dt>البيانات المستخدمة</dt><dd><?= CardRules::usedBytes($card) > 0 ? e(fmt_bytes(CardRules::usedBytes($card))) : '—' ?><?= $r['data'] !== null ? ' · متبقٍ ' . e(fmt_bytes(max(0, (int) $r['data']))) : '' ?></dd></div>
            <?php if ($card['bound_mac']): ?><div><dt>الجهاز المرتبط</dt><dd class="mono"><?= e($card['bound_mac']) ?></dd></div><?php endif; ?>
          </dl>
          <?php if (can('check.use')): ?><a class="btn btn-block" href="<?= e(url('/check/card/' . $card['id'])) ?>"><?= icon('scan-line') ?>فحص الحساب والجلسات</a><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik المسموحة</h3></div>
        <div class="panel-body stack" style="gap:10px">
          <label class="check"><input type="radio" name="router_scope" value="all"<?= checked($scope === 'all') ?>>كل أجهزة MikroTik</label>
          <label class="check"><input type="radio" name="router_scope" value="selected"<?= checked($scope === 'selected') ?>>أجهزة MikroTik محددة</label>
          <div data-show-when="router_scope=selected" class="stack" style="gap:8px;padding-inline-start:26px"<?= $scope === 'selected' ? '' : ' hidden' ?>>
            <?php foreach ($routers as $id => $name): ?>
              <label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $id ?>"<?= checked(in_array((int) $id, $chosen, true)) ?>><?= e($name) ?></label>
            <?php endforeach; ?>
            <?php if (!$routers): ?><span class="text-muted text-sm">لا توجد أجهزة MikroTik.</span><?php endif; ?>
          </div>
          <?= error_html('routers') ?>
          <?php if ($routers): ?>
          <div class="dropdown-sep"></div>
          <label class="check"><input type="checkbox" name="push_now" value="1"<?= checked($posted && !empty($old['push_now'])) ?>>رفع الحساب إلى أجهزة MikroTik الآن</label>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?><?= $isEdit ? 'حفظ' : 'إنشاء الحساب' ?></button>
        <a class="btn" href="<?= e(url('/cards/accounts')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
<?php if ($isEdit): ?>
<form class="mt-3" method="post" action="<?= e(url('/cards/accounts/' . $card['id'] . '/delete')) ?>" data-confirm="حذف الحساب <?= e($card['username']) ?> نهائياً من النظام ومن أجهزة MikroTik؟">
  <?= csrf_field() ?><button class="btn btn-ghost text-danger"><?= icon('trash-2') ?>حذف الحساب</button>
</form>
<?php endif; ?>
