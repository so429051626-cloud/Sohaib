<?php
/**
 * إعلان على صفحة الهوتسبوت: إضافة أو تعديل.
 * @var App\Core\View $v @var ?array $ad @var array $routers
 */
use App\Core\Session;
use App\Services\HotspotAds;

$isEdit = $ad !== null;
$v->title = $isEdit ? 'تعديل إعلان' : 'إعلان جديد';
$v->breadcrumbs = [['إعلانات صفحة الهوتسبوت', '/hotspot/ads'], [$v->title, null]];
$v->script('js/ads.js');
$old = Session::get('_old_now', []);
$posted = is_array($old) && $old !== [];
$val = static fn (string $k, $d = '') => old($k, $ad[$k] ?? $d);
$only = $ad ? HotspotAds::routerIds($ad) : [];
$scope = old('router_scope', $only ? 'selected' : 'all');
$chosen = array_map('intval', (array) old('routers', $only));
$active = $posted ? !empty($old['is_active']) : ($ad ? (int) $ad['is_active'] === 1 : true);
?>
<form method="post" action="<?= e(url($isEdit ? '/hotspot/ads/' . $ad['id'] : '/hotspot/ads')) ?>" enctype="multipart/form-data" data-ad-form>
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('megaphone') ?>الإعلان</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-8">
              <label for="title">العنوان <span class="req">*</span></label>
              <input class="input" id="title" name="title" value="<?= e($val('title')) ?>" maxlength="120" required placeholder="خصم 20% على باقة الشهر" data-preview="title">
              <?= error_html('title') ?>
            </div>
            <div class="field col-4">
              <label for="advertiser">المعلن</label>
              <input class="input" id="advertiser" name="advertiser" value="<?= e($val('advertiser')) ?>" maxlength="120" placeholder="اسم المحل أو الشبكة" data-preview="advertiser">
              <?= error_html('advertiser') ?>
            </div>
            <div class="field col-12">
              <label for="body">النص</label>
              <textarea class="input" id="body" name="body" rows="3" maxlength="500" data-preview="body"><?= e($val('body')) ?></textarea>
              <?= error_html('body') ?>
            </div>
            <div class="field col-6">
              <label for="phone">رقم التواصل</label>
              <input class="input input-ltr" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30" inputmode="tel" placeholder="0599000000" data-preview="phone">
              <?= error_html('phone') ?>
            </div>
            <div class="field col-6">
              <label for="link">الرابط</label>
              <input class="input input-ltr" id="link" name="link" value="<?= e($val('link')) ?>" maxlength="255" placeholder="https://" data-preview="link">
              <?= error_html('link') ?>
            </div>
            <div class="field col-12">
              <label for="image">الصورة</label>
              <input class="input" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" data-preview="image">
              <span class="hint">PNG أو JPG أو WEBP حتى 2 ميغابايت · الأنسب بعرض أكبر من الارتفاع</span>
              <?= error_html('image') ?>
              <?php if ($isEdit && $ad['image']): ?><label class="check mt-2"><input type="checkbox" name="remove_image" value="1">حذف الصورة الحالية</label><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2><?= icon('calendar-days') ?>مدة العرض</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-4"><label for="starts_on">من تاريخ</label><input class="input" type="date" id="starts_on" name="starts_on" value="<?= e($val('starts_on')) ?>"><?= error_html('starts_on') ?></div>
            <div class="field col-4"><label for="ends_on">إلى تاريخ</label><input class="input" type="date" id="ends_on" name="ends_on" value="<?= e($val('ends_on')) ?>"><?= error_html('ends_on') ?></div>
            <div class="field col-4"><label for="sort">الترتيب</label><input class="input" id="sort" name="sort" value="<?= e($val('sort', '0')) ?>" inputmode="numeric"><?= error_html('sort') ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('smartphone') ?>المعاينة</h3></div>
        <div class="panel-body">
          <div class="ad-preview" data-ad-preview>
            <div class="ad-preview-img"<?= $isEdit && $ad['image'] ? '' : ' hidden' ?>><img src="<?= $isEdit && $ad['image'] ? e(upload_url((string) $ad['image'])) : '' ?>" alt=""></div>
            <div class="ad-preview-body">
              <div class="ad-preview-adv" data-p="advertiser"><?= e((string) ($ad['advertiser'] ?? '')) ?></div>
              <div class="ad-preview-title" data-p="title"><?= e((string) ($ad['title'] ?? '')) ?: 'عنوان الإعلان' ?></div>
              <div class="ad-preview-text" data-p="body"><?= e((string) ($ad['body'] ?? '')) ?></div>
              <div class="ad-preview-actions">
                <span class="ad-preview-btn" data-p="phone"<?= ($ad['phone'] ?? '') !== '' ? '' : ' hidden' ?>><?= icon('phone', 'icon-sm') ?>اتصال</span>
                <span class="ad-preview-btn is-alt" data-p="link"<?= ($ad['link'] ?? '') !== '' ? '' : ' hidden' ?>><?= icon('external-link', 'icon-sm') ?>التفاصيل</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('settings') ?>الإعدادات</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($active) ?>><span class="track"></span><span>الإعلان مفعّل</span></label>
          <div class="dropdown-sep"></div>
          <label class="check"><input type="radio" name="router_scope" value="all"<?= checked($scope === 'all') ?>>كل أجهزة MikroTik</label>
          <label class="check"><input type="radio" name="router_scope" value="selected"<?= checked($scope === 'selected') ?>>أجهزة MikroTik محددة</label>
          <div data-show-when="router_scope=selected" class="stack" style="gap:8px;padding-inline-start:26px"<?= $scope === 'selected' ? '' : ' hidden' ?>>
            <?php foreach ($routers as $id => $name): ?>
              <label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $id ?>"<?= checked(in_array((int) $id, $chosen, true)) ?>><?= e($name) ?></label>
            <?php endforeach; ?>
            <?php if (!$routers): ?><span class="text-muted text-sm">لا توجد أجهزة MikroTik.</span><?php endif; ?>
          </div>
          <?= error_html('routers') ?>
        </div>
      </div>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <a class="btn" href="<?= e(url('/hotspot/ads')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
