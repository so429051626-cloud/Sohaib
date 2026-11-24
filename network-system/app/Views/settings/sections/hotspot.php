<?php
/** @var callable $s @var callable $name @var callable $switch */
$cookieForced = \App\Services\CookiePolicy::forced();
?>
<?php if ($cookieForced !== null): ?>
      <div class="field col-12">
        <div class="notice<?= $cookieForced['enabled'] ? '' : ' notice-warning' ?>"><?= icon('cookie') ?><div>
          <div class="notice-title"><?= $cookieForced['enabled'] ? 'الدخول التلقائي بالجهاز (الكوكيز) مفعّل من إدارة المنصة بمدة ' . e(\App\Services\CookiePolicy::durationLabel($cookieForced['duration'])) : 'الدخول التلقائي بالجهاز (الكوكيز) موقوف من إدارة المنصة' ?></div>
          <div class="text-sm">يُطبَّق هذا على أجهزة MikroTik بدلاً من خياري «الدخول التلقائي» و«مدته» أدناه، ويعود العمل بهما عند إعادة التحكم إلى الشبكة.</div>
        </div></div>
      </div>
<?php endif; ?>
      <div class="field col-4">
        <label>حالة أحرف الرمز المُدخل</label>
        <select class="input" name="<?= e($name('hotspot.code_case')) ?>">
          <option value="none"<?= selected($s('hotspot.code_case'), 'none') ?>>كما أُدخل</option>
          <option value="lower"<?= selected($s('hotspot.code_case'), 'lower') ?>>تحويل إلى أحرف صغيرة</option>
          <option value="upper"<?= selected($s('hotspot.code_case'), 'upper') ?>>تحويل إلى أحرف كبيرة</option>
        </select>
      </div>
      <div class="field col-4">
        <label>مهلة اتصال الصفحة بالنظام</label>
        <div class="input-group"><input class="input" name="<?= e($name('hotspot.timeout')) ?>" value="<?= e($s('hotspot.timeout')) ?>" inputmode="numeric"><span class="input-addon">ثانية</span></div>
      </div>
      <div class="field col-4">
        <label>مدة الدخول التلقائي بالجهاز</label>
        <input class="input input-ltr" name="<?= e($name('hotspot.mac_cookie_timeout')) ?>" value="<?= e($s('hotspot.mac_cookie_timeout')) ?>">
        <?= error_html($name('hotspot.mac_cookie_timeout')) ?>
      </div>
      <div class="field col-4">
        <label>حد المحاولات الفاشلة لكل جهاز</label>
        <input class="input" name="<?= e($name('hotspot.fail_limit')) ?>" value="<?= e($s('hotspot.fail_limit')) ?>" inputmode="numeric">
      </div>
      <div class="field col-4">
        <label>خلال مدة</label>
        <div class="input-group"><input class="input" name="<?= e($name('hotspot.fail_window')) ?>" value="<?= e($s('hotspot.fail_window')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
      </div>
      <div class="field col-4">
        <label>مدة الحظر المؤقت للجهاز</label>
        <div class="input-group"><input class="input" name="<?= e($name('hotspot.lock_minutes')) ?>" value="<?= e($s('hotspot.lock_minutes')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
      </div>
      <div class="field col-6"><?= $switch('hotspot.mac_cookie', 'الدخول التلقائي للجهاز المعروف (MAC Cookie)') ?></div>
      <div class="field col-6"><?= $switch('hotspot.events', 'إرسال أحداث الدخول والخروج من جهاز MikroTik إلى النظام') ?></div>
      <div class="field col-6"><?= $switch('hotspot.fallback', 'الدخول المباشر عبر جهاز MikroTik عند تعذّر الوصول إلى النظام') ?></div>
      <div class="field col-6"><?= $switch('hotspot.allow_unsold', 'قبول الكروت غير المسجّل بيعها') ?></div>
      <div class="field col-6"><?= $switch('hotspot.local_users', 'دخول المستخدمين المنشأين يدوياً على جهاز MikroTik (غير الموجودين في النظام) مباشرة عبر الجهاز، دون حسابها محاولات خاطئة') ?></div>
