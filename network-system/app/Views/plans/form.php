<?php
/** @var App\Core\View $v */
use App\Services\Units;

$isEdit = $plan !== null;
$v->title = $isEdit ? 'تعديل باقة اشتراك' : 'باقة اشتراك جديدة';
$v->breadcrumbs = [['باقات الاشتراك', '/plans'], [$v->title, null]];
$val = static fn (string $k, $d = '') => old($k, $plan[$k] ?? $d);
[$dv, $du] = Units::splitBytes($plan && $plan['data_limit'] !== null ? (int) $plan['data_limit'] : null);
$service = (string) $val('service', 'ppp');
?>
<form method="post" action="<?= e(url($isEdit ? '/plans/' . $plan['id'] : '/plans')) ?>">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('package') ?>بيانات الباقة</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="name">اسم الباقة <span class="req">*</span></label>
              <input class="input" id="name" name="name" value="<?= e($val('name')) ?>" required placeholder="باقة 10 ميغا">
              <?= error_html('name') ?>
            </div>
            <div class="field col-6">
              <label>نوع الخدمة</label>
              <div class="segmented">
                <label><input type="radio" name="service" value="ppp"<?= checked($service === 'ppp') ?><?= $isEdit && $subscribers > 0 && $service !== 'ppp' ? ' disabled' : '' ?>><span>PPPoE</span></label>
                <label><input type="radio" name="service" value="hotspot"<?= checked($service === 'hotspot') ?><?= $isEdit && $subscribers > 0 && $service !== 'hotspot' ? ' disabled' : '' ?>><span>هوتسبوت</span></label>
              </div>
              <?= error_html('service') ?>
            </div>
            <div class="field col-4">
              <label for="price">السعر <span class="req">*</span></label>
              <input class="input" id="price" name="price" value="<?= e($val('price', '0')) ?>" inputmode="decimal" required>
              <?= error_html('price') ?>
            </div>
            <div class="field col-4">
              <label for="duration_days">مدة الاشتراك (يوم) <span class="req">*</span></label>
              <input class="input" id="duration_days" name="duration_days" value="<?= e($val('duration_days', '30')) ?>" inputmode="numeric" required>
              <?= error_html('duration_days') ?>
            </div>
            <div class="field col-4">
              <label for="shared_users">عدد الأجهزة في نفس الوقت</label>
              <input class="input" id="shared_users" name="shared_users" value="<?= e($val('shared_users', '1')) ?>" inputmode="numeric">
              <span class="hint">0 = غير محدود</span>
              <?= error_html('shared_users') ?>
            </div>

            <div class="form-section-title">السرعة</div>
            <div class="field col-6">
              <label for="rate_down">التنزيل</label>
              <input class="input input-ltr" id="rate_down" name="rate_down" value="<?= e($val('rate_down')) ?>" placeholder="10M">
              <?= error_html('rate_down') ?>
            </div>
            <div class="field col-6">
              <label for="rate_up">الرفع</label>
              <input class="input input-ltr" id="rate_up" name="rate_up" value="<?= e($val('rate_up')) ?>" placeholder="2M">
              <?= error_html('rate_up') ?>
            </div>
            <div class="col-12">
              <details<?= $val('burst_rate_down') || $val('burst_rate_up') ? ' open' : '' ?>>
                <summary style="cursor:pointer;font-weight:500;font-size:13.5px">السرعة المؤقتة (Burst)</summary>
                <div class="form-grid mt-2">
                  <div class="field col-3"><label>سرعة الدفعة تنزيل</label><input class="input input-ltr" name="burst_rate_down" value="<?= e($val('burst_rate_down')) ?>" placeholder="20M"><?= error_html('burst_rate_down') ?></div>
                  <div class="field col-3"><label>سرعة الدفعة رفع</label><input class="input input-ltr" name="burst_rate_up" value="<?= e($val('burst_rate_up')) ?>" placeholder="4M"><?= error_html('burst_rate_up') ?></div>
                  <div class="field col-2"><label>عتبة التنزيل</label><input class="input input-ltr" name="burst_threshold_down" value="<?= e($val('burst_threshold_down')) ?>" placeholder="8M"></div>
                  <div class="field col-2"><label>عتبة الرفع</label><input class="input input-ltr" name="burst_threshold_up" value="<?= e($val('burst_threshold_up')) ?>" placeholder="1M"></div>
                  <div class="field col-2"><label>الزمن (ثانية)</label><input class="input input-ltr" name="burst_time" value="<?= e($val('burst_time')) ?>" placeholder="10"></div>
                </div>
              </details>
            </div>

            <div class="form-section-title">حجم البيانات والاستهلاك العادل</div>
            <div class="field col-4">
              <label>البيانات لكل فترة</label>
              <div class="input-group">
                <input class="input" name="data_value" value="<?= e(old('data_value', $dv)) ?>" inputmode="decimal" placeholder="غير محدود">
                <select class="input" name="data_unit" style="max-width:110px">
                  <?php foreach (Units::DATA_UNITS as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected(old('data_unit', $du ?: 'GB'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
              </div>
              <?= error_html('data_value') ?>
            </div>
            <div class="field col-4">
              <label for="fup_rate_down">التنزيل بعد نفاد البيانات</label>
              <input class="input input-ltr" id="fup_rate_down" name="fup_rate_down" value="<?= e($val('fup_rate_down')) ?>" placeholder="1M">
              <?= error_html('fup_rate_down') ?>
            </div>
            <div class="field col-4">
              <label for="fup_rate_up">الرفع بعد نفاد البيانات</label>
              <input class="input input-ltr" id="fup_rate_up" name="fup_rate_up" value="<?= e($val('fup_rate_up')) ?>" placeholder="512k">
              <?= error_html('fup_rate_up') ?>
            </div>

            <div class="form-section-title" data-show-when="service=ppp">إعدادات PPP</div>
            <div class="field col-4" data-show-when="service=ppp">
              <label for="local_address">العنوان المحلي</label>
              <input class="input input-ltr" id="local_address" name="local_address" value="<?= e($val('local_address')) ?>" placeholder="10.10.0.1">
              <?= error_html('local_address') ?>
            </div>
            <div class="field col-4" data-show-when="service=ppp">
              <label for="address_pool">مجمع العناوين (Pool)</label>
              <input class="input input-ltr" id="address_pool" name="address_pool" value="<?= e($val('address_pool')) ?>" placeholder="pppoe-pool">
              <?= error_html('address_pool') ?>
            </div>
            <div class="field col-4" data-show-when="service=ppp">
              <label for="dns_server">خوادم DNS</label>
              <input class="input input-ltr" id="dns_server" name="dns_server" value="<?= e($val('dns_server')) ?>" placeholder="8.8.8.8,1.1.1.1">
              <?= error_html('dns_server') ?>
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
          <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($val('is_active', 1)) ?>><span class="track"></span><span>الباقة متاحة للمشتركين الجدد</span></label>
          <div class="field">
            <label for="sort">ترتيب العرض</label>
            <input class="input" id="sort" name="sort" value="<?= e($val('sort', '0')) ?>" inputmode="numeric">
          </div>
          <?php if ($isEdit): ?>
            <div class="mini-stat text-sm text-muted"><?= e(num($subscribers)) ?> مشترك على هذه الباقة</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <a class="btn" href="<?= e(url('/plans')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
