<?php
/** @var App\Core\View $v */
use App\Services\Units;

$isEdit = $cat !== null;
$v->title = $isEdit ? 'تعديل باقة' : 'باقة جديدة';
$v->breadcrumbs = [['الباقات', '/categories'], [$v->title, null]];
$val = static fn (string $k, $d = '') => old($k, $cat[$k] ?? $d);
[$tv, $tu] = Units::splitSeconds($cat && $cat['time_limit'] !== null ? (int) $cat['time_limit'] : null);
[$vv, $vu] = Units::splitSeconds($cat && $cat['validity'] !== null ? (int) $cat['validity'] : null);
[$dv, $du] = Units::splitBytes($cat && $cat['data_limit'] !== null ? (int) $cat['data_limit'] : null);
if (!$isEdit) {
    $vu = 'day';
}
$scope = old('router_scope', $selected ? 'selected' : 'all');
$unitSelect = static function (string $name, string $current, array $units): string {
    $h = '<select class="input" name="' . e($name) . '" style="max-width:140px">';
    foreach ($units as $k => [$label]) {
        $h .= '<option value="' . e($k) . '"' . selected($current, $k) . '>' . e($label) . '</option>';
    }
    return $h . '</select>';
};
?>
<form method="post" action="<?= e(url($isEdit ? '/categories/' . $cat['id'] : '/categories')) ?>">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('layers') ?>بيانات الباقة</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="name">اسم الباقة <span class="req">*</span></label>
              <input class="input" id="name" name="name" value="<?= e($val('name')) ?>" required placeholder="باقة يوم">
              <?= error_html('name') ?>
            </div>
            <div class="field col-3">
              <label for="price">السعر <span class="req">*</span></label>
              <input class="input" id="price" name="price" value="<?= e($val('price', '0')) ?>" inputmode="decimal" required>
              <?= error_html('price') ?>
            </div>
            <div class="field col-3">
              <label for="color">اللون</label>
              <input class="color-input" type="color" id="color" name="color" value="<?= e($val('color', '#2453d6')) ?>">
            </div>

            <div class="form-section-title">الحدود</div>
            <div class="field col-4">
              <label>مدة الاستخدام الفعلي</label>
              <div class="input-group">
                <input class="input" name="time_value" value="<?= e(old('time_value', $tv)) ?>" inputmode="decimal" placeholder="غير محدود">
                <?= $unitSelect('time_unit', old('time_unit', $tu), Units::TIME_UNITS) ?>
              </div>
              <?= error_html('time_value') ?>
            </div>
            <div class="field col-4">
              <label>الصلاحية من أول دخول</label>
              <div class="input-group">
                <input class="input" name="validity_value" value="<?= e(old('validity_value', $vv)) ?>" inputmode="decimal" placeholder="غير محدودة">
                <?= $unitSelect('validity_unit', old('validity_unit', $vu), Units::TIME_UNITS) ?>
              </div>
            </div>
            <div class="field col-4">
              <label>حجم البيانات</label>
              <div class="input-group">
                <input class="input" name="data_value" value="<?= e(old('data_value', $dv)) ?>" inputmode="decimal" placeholder="غير محدود">
                <?= $unitSelect('data_unit', old('data_unit', $du), Units::DATA_UNITS) ?>
              </div>
            </div>

            <div class="form-section-title">السرعة</div>
            <div class="field col-4">
              <label for="rate_down">التنزيل</label>
              <input class="input input-ltr" id="rate_down" name="rate_down" value="<?= e($val('rate_down')) ?>" placeholder="4M">
              <?= error_html('rate_down') ?>
            </div>
            <div class="field col-4">
              <label for="rate_up">الرفع</label>
              <input class="input input-ltr" id="rate_up" name="rate_up" value="<?= e($val('rate_up')) ?>" placeholder="1M">
              <?= error_html('rate_up') ?>
            </div>
            <div class="field col-4">
              <label for="shared_users">عدد الأجهزة في نفس الوقت</label>
              <input class="input" id="shared_users" name="shared_users" value="<?= e($val('shared_users', '1')) ?>" inputmode="numeric">
              <span class="hint">0 = غير محدود</span>
            </div>
            <div class="col-12">
              <details<?= $val('burst_rate_down') || $val('burst_rate_up') ? ' open' : '' ?>>
                <summary style="cursor:pointer;font-weight:500;font-size:13.5px">السرعة المؤقتة (Burst)</summary>
                <div class="form-grid mt-2">
                  <div class="field col-3"><label>سرعة الدفعة تنزيل</label><input class="input input-ltr" name="burst_rate_down" value="<?= e($val('burst_rate_down')) ?>" placeholder="8M"><?= error_html('burst_rate_down') ?></div>
                  <div class="field col-3"><label>سرعة الدفعة رفع</label><input class="input input-ltr" name="burst_rate_up" value="<?= e($val('burst_rate_up')) ?>" placeholder="2M"></div>
                  <div class="field col-2"><label>عتبة التنزيل</label><input class="input input-ltr" name="burst_threshold_down" value="<?= e($val('burst_threshold_down')) ?>" placeholder="3M"></div>
                  <div class="field col-2"><label>عتبة الرفع</label><input class="input input-ltr" name="burst_threshold_up" value="<?= e($val('burst_threshold_up')) ?>" placeholder="768k"></div>
                  <div class="field col-2"><label>الزمن (ثانية)</label><input class="input input-ltr" name="burst_time" value="<?= e($val('burst_time')) ?>" placeholder="10"></div>
                </div>
              </details>
            </div>

            <div class="form-section-title">الاستخدام</div>
            <div class="field col-6">
              <label class="switch"><input type="checkbox" name="lock_mac" value="1"<?= checked($val('lock_mac', 1)) ?>><span class="track"></span><span>ربط الكرت بأول جهاز يستخدمه</span></label>
            </div>
            <div class="field col-6">
              <label for="idle_value">قطع الاتصال عند الخمول بعد (دقيقة)</label>
              <input class="input" id="idle_value" name="idle_value" value="<?= e(old('idle_value', $cat && $cat['idle_timeout'] ? (int) ($cat['idle_timeout'] / 60) : '')) ?>" inputmode="numeric" placeholder="بدون">
            </div>
            <div class="field col-12">
              <label for="notes">ملاحظات</label>
              <input class="input" id="notes" name="notes" value="<?= e($val('notes')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('settings') ?>الإعدادات</h3></div>
        <div class="panel-body stack" style="gap:14px">
          <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($val('is_active', 1)) ?>><span class="track"></span><span>الباقة فعّالة</span></label>
          <label class="switch"><input type="checkbox" name="show_on_hotspot" value="1"<?= checked($val('show_on_hotspot', 1)) ?>><span class="track"></span><span>إظهارها في قائمة أسعار صفحة الهوتسبوت</span></label>
          <div class="field">
            <label for="low_stock">التنبيه عند انخفاض المتاح إلى</label>
            <input class="input" id="low_stock" name="low_stock" value="<?= e($val('low_stock')) ?>" inputmode="numeric" placeholder="بدون تنبيه">
          </div>
          <div class="field">
            <label for="sort">ترتيب العرض</label>
            <input class="input" id="sort" name="sort" value="<?= e($val('sort', '0')) ?>" inputmode="numeric">
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik المسموحة</h3></div>
        <div class="panel-body stack" style="gap:10px">
          <label class="check"><input type="radio" name="router_scope" value="all"<?= checked($scope === 'all') ?>>كل أجهزة MikroTik</label>
          <label class="check"><input type="radio" name="router_scope" value="selected"<?= checked($scope === 'selected') ?>>أجهزة MikroTik محددة</label>
          <div data-show-when="router_scope=selected" class="stack" style="gap:8px;padding-inline-start:26px">
            <?php foreach ($routers as $id => $name): ?>
              <label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $id ?>"<?= checked(in_array((int) $id, array_map('intval', (array) old('routers', $selected)), true)) ?>><?= e($name) ?></label>
            <?php endforeach; ?>
            <?php if (!$routers): ?><span class="text-muted text-sm">لا توجد أجهزة MikroTik.</span><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <a class="btn" href="<?= e(url('/categories')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
