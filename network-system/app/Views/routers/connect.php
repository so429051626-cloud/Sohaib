<?php
/**
 * معالج ربط جهاز MikroTik — الخطوة 1: الاسم وطريقة الاتصال.
 * @var App\Core\View $v @var bool $tunnel @var array $areas @var bool $panelOk @var bool $first
 */
$v->title = 'اربط جهازك بـ 3 خطوات';
$v->breadcrumbs = [['أجهزة MikroTik', '/routers'], ['ربط جهاز', null]];
$method = (string) old('method', $tunnel ? 'tunnel' : 'public');
if (!$tunnel) {
    $method = 'public';
}
?>
<ol class="wizard-steps" aria-label="خطوات الربط">
  <li class="is-current"><span class="wizard-num">1</span><span>اسم الجهاز وطريقة الاتصال</span></li>
  <li><span class="wizard-num">2</span><span>أمر واحد في Terminal</span></li>
  <li><span class="wizard-num">3</span><span>متابعة حية حتى يجهز</span></li>
</ol>

<?php if ($first): ?>
<div class="panel wizard-hero">
  <span class="wizard-hero-icon"><?= icon('router') ?></span>
  <div>
    <div class="wizard-hero-title">أهلاً بك! لنربط أول جهاز MikroTik</div>
    <div class="wizard-hero-sub">النظام ينشئ النفق الآمن ومستخدم الربط والسكربت بنفسه، وأنت تلصق أمراً واحداً فقط.</div>
  </div>
</div>
<?php endif; ?>

<?php if (!$panelOk): ?>
<div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?><div>
  <div class="notice-title">الرابط العام للنظام غير مضبوط</div>
  <div class="text-sm">يحتاجه جهاز MikroTik لجلب إعدادات الربط.<?php if (can('settings.manage')): ?> <a href="<?= e(url('/settings')) ?>">اضبطه من الإعدادات</a>.<?php endif; ?></div>
</div></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/routers/connect')) ?>" class="panel wizard-card">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="form-grid">
      <div class="field col-6">
        <label for="name">اسم الجهاز <span class="req">*</span></label>
        <input class="input" id="name" name="name" value="<?= e(old('name')) ?>" maxlength="100" required placeholder="البرج الرئيسي" autofocus>
        <?= error_html('name') ?>
      </div>
      <?php if ($areas): ?>
      <div class="field col-6">
        <label for="area_id">المنطقة</label>
        <select class="input" id="area_id" name="area_id">
          <option value="">بدون</option>
          <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected(old('area_id'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-section-title">كيف يتصل النظام بالجهاز؟</div>
      <div class="col-12 wizard-choices">
        <label class="wizard-choice<?= $tunnel ? '' : ' is-disabled' ?>">
          <input type="radio" name="method" value="tunnel"<?= checked($method === 'tunnel') ?><?= $tunnel ? '' : ' disabled' ?>>
          <span class="wizard-choice-icon"><?= icon('waypoints') ?></span>
          <span class="wizard-choice-body">
            <b>نفق آمن <?php if ($tunnel): ?><span class="badge badge-success">موصى به</span><?php endif; ?></b>
            <span><?= $tunnel ? 'يعمل حتى بدون عنوان IP عام، ودون فتح أي منفذ. يحتاج RouterOS 7.' : 'غير متاح على هذا الخادم.' ?></span>
          </span>
          <?= icon('circle-check', 'wizard-choice-mark') ?>
        </label>
        <label class="wizard-choice">
          <input type="radio" name="method" value="public"<?= checked($method === 'public') ?>>
          <span class="wizard-choice-icon"><?= icon('globe') ?></span>
          <span class="wizard-choice-body">
            <b>عنوان IP عام</b>
            <span>للجهاز عنوان عام ثابت أو اسم DDNS يصل إليه الخادم.</span>
          </span>
          <?= icon('circle-check', 'wizard-choice-mark') ?>
        </label>
      </div>

      <div class="col-12 form-grid" data-show-when="method=public"<?= $method === 'public' ? '' : ' hidden' ?>>
        <div class="field col-8">
          <label for="host">العنوان العام للجهاز</label>
          <input class="input input-ltr" id="host" name="host" value="<?= e(old('host')) ?>" maxlength="255" placeholder="203.0.113.10 أو router.example.com">
          <?= error_html('host') ?>
        </div>
        <div class="field col-4">
          <label for="api_port">منفذ API</label>
          <input class="input input-ltr" id="api_port" name="api_port" value="<?= e(old('api_port', '8728')) ?>" inputmode="numeric">
          <?= error_html('api_port') ?>
        </div>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary btn-lg"<?= $panelOk ? '' : ' disabled' ?>><?= icon('arrow-left') ?>متابعة</button>
      <a class="btn btn-ghost" href="<?= e(url('/routers/create')) ?>"><?= icon('sliders-horizontal') ?>إضافة يدوية ببيانات API</a>
    </div>
  </div>
</form>
