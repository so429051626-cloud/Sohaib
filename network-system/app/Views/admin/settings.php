<?php
/** @var App\Core\View $v @var array $admins */
use App\Core\AdminAuth;
use App\Core\Platform;

$v->title = 'الإعدادات';
$p = static fn (string $k) => old(str_replace('platform.', '', $k), Platform::setting($k));
$zones = ['Asia/Gaza', 'Asia/Hebron', 'Asia/Jerusalem', 'Asia/Amman', 'Africa/Cairo', 'Asia/Riyadh', 'Asia/Baghdad', 'Asia/Damascus', 'Asia/Beirut', 'Asia/Dubai', 'Europe/Istanbul', 'UTC'];
?>
<div class="grid grid-main-side">
  <form method="post" action="<?= e(url('/admin/settings')) ?>" class="panel">
    <?= csrf_field() ?>
    <div class="panel-head"><h3><?= icon('settings') ?>المنصة</h3></div>
    <div class="panel-body">
      <div class="form-grid">
        <div class="field col-6"><label>اسم المنصة</label><input class="input" name="platform_name" value="<?= e(old('platform_name', Platform::setting('platform.name'))) ?>" required maxlength="60"></div>
        <div class="field col-6"><label>مدة التجربة المجانية</label><div class="input-group"><input class="input" name="trial_days" value="<?= e(old('trial_days', Platform::setting('platform.trial_days'))) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div></div>
        <div class="field col-6"><label>رقم واتساب (للعرض)</label><input class="input input-ltr" name="whatsapp" value="<?= e(old('whatsapp', Platform::setting('platform.whatsapp'))) ?>" maxlength="30"></div>
        <div class="field col-6"><label>رقم واتساب الدولي</label><input class="input input-ltr" name="whatsapp_intl" value="<?= e(old('whatsapp_intl', Platform::setting('platform.whatsapp_intl'))) ?>" maxlength="20" placeholder="970595679770"></div>
        <div class="field col-12"><label>رابط فيسبوك</label><input class="input input-ltr" name="facebook" value="<?= e(old('facebook', Platform::setting('platform.facebook'))) ?>" maxlength="255"></div>
        <div class="field col-6"><label>عملة الاشتراكات</label><input class="input" name="currency" value="<?= e(old('currency', Platform::setting('platform.currency'))) ?>" required maxlength="8"><?= error_html('currency') ?></div>
        <div class="field col-6"><label>المنطقة الزمنية للحسابات الجديدة</label><select class="input" name="timezone"><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= selected(Platform::setting('platform.timezone'), $z) ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
        <div class="field col-12"><label class="switch"><input type="checkbox" name="signup" value="1"<?= checked(Platform::setting('platform.signup') === '1') ?>><span class="track"></span><span>السماح بإنشاء حسابات جديدة</span></label></div>
        <div class="form-section-title"><?= icon('map') ?>خرائط Google</div>
        <div class="field col-12">
          <label for="gmk">مفتاح خرائط Google لكل الحسابات</label>
          <input class="input input-ltr" id="gmk" name="google_maps_key" value="<?= e(old('google_maps_key', Platform::setting('maps.google_key'))) ?>" maxlength="120" autocomplete="off" spellcheck="false" placeholder="AIza...">
          <?= error_html('google_maps_key') ?>
          <span class="hint">من Google Cloud: فعّل Map Tiles API وأنشئ مفتاحاً مقيّداً بنطاق المنصة. تظهر بعده خرائط Google (خريطة، قمر صناعي، تضاريس) في خريطة الشبكة واختيار المواقع، ويمكن لكل حساب وضع مفتاحه الخاص من إعداداته.</span>
        </div>
        <div class="form-section-title"><?= icon('megaphone') ?>إعلان لكل الأنظمة</div>
        <div class="field col-9"><label>نص الإعلان</label><input class="input" name="announcement" value="<?= e(old('announcement', Platform::setting('platform.announcement'))) ?>" maxlength="300"></div>
        <div class="field col-3"><label>النوع</label><select class="input" name="announcement_level"><?php foreach (['info' => 'معلومة', 'success' => 'إيجابي', 'warning' => 'تنبيه'] as $k => $l): ?><option value="<?= $k ?>"<?= selected(Platform::setting('platform.announcement_level'), $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
    </div>
  </form>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('image') ?>شعار النظام</h3></div>
      <div class="panel-body stack" style="gap:12px">
        <div class="logo-preview">
          <span class="logo-preview-tile is-light"><img src="<?= e(\App\Core\Brand::markSrc()) ?>" alt="" data-logo-prev></span>
          <span class="logo-preview-tile is-dark"><img src="<?= e(\App\Core\Brand::markSrc()) ?>" alt="" data-logo-prev></span>
        </div>
        <p class="text-sm text-muted" style="margin:0">يظهر في كل الحسابات: القائمة الجانبية وشاشات الدخول وصفحة الهبوط وأيقونة المتصفح والتطبيق. استخدم صورة مربعة بخلفية شفافة (PNG أو WebP أو SVG) بمقاس 512×512 أو أكبر.</p>
        <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" enctype="multipart/form-data" class="stack" style="gap:10px" data-brand-logo>
          <?= csrf_field() ?>
          <label class="btn btn-block"><?= icon('upload') ?>اختيار صورة الشعار<input type="file" name="logo" accept="image/png,image/webp,image/svg+xml,.svg" hidden data-brand-logo-input></label>
          <span class="text-sm text-muted text-center" data-brand-logo-name>PNG · WebP · SVG</span>
          <?= error_html('logo') ?>
          <button class="btn btn-primary btn-block" data-brand-logo-save disabled><?= icon('save') ?>حفظ الشعار</button>
        </form>
        <?php if (\App\Services\BrandLogo::dir() !== null): ?>
        <form method="post" action="<?= e(url('/admin/settings/logo/reset')) ?>" data-confirm="استعادة شعار النظام الأصلي في كل الحسابات؟" data-confirm-type="primary">
          <?= csrf_field() ?>
          <button class="btn btn-ghost btn-block"><?= icon('rotate-ccw') ?>استعادة الشعار الأصلي</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('user-cog') ?>فريق الإدارة</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <p class="text-sm text-muted" style="margin:0">المسؤولون وأدوارهم وصلاحياتهم يديرها مالك المنصة من صفحة «فريق الإدارة»، وكل مسؤول يغيّر كلمة مروره من صفحة «الأمان».</p>
        <?php if (AdminAuth::isOwner()): ?><a class="btn btn-block" href="<?= e(url('/admin/team')) ?>"><?= icon('user-cog') ?>فريق الإدارة</a><?php endif; ?>
        <a class="btn btn-block btn-ghost" href="<?= e(url('/admin/security')) ?>"><?= icon('shield-check') ?>الأمان وكلمة المرور</a>
      </div>
    </div>
  </div>
</div>
