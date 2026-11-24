<?php
/** @var App\Core\View $v @var array $plans @var array $routers @var bool $aiOffered @var bool $aiOk @var string $aiState */
$v->title = 'استيراد ذكي للمشتركين';
$v->breadcrumbs = [['المشتركون', '/subscribers'], ['استيراد ذكي', null]];
$v->script('js/smart-import.js');
$needRouter = App\Core\Auth::routerIds() !== null;
?>
<div class="si" data-si data-parse="<?= e(url('/subscribers/import-smart/parse')) ?>" data-save="<?= e(url('/subscribers/import-smart/save')) ?>" data-plans="<?= e(json_encode($plans, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="panel si-step" data-si-input>
    <div class="panel-head"><h3><?= icon($aiOffered ? 'sparkles' : 'file-up') ?>القائمة</h3>
      <?php if ($aiOffered): ?><span class="badge <?= $aiOk ? 'badge-primary' : 'badge-outline' ?>"><?= icon('sparkles') ?><?= e($aiOk ? 'ترتيب بالذكاء الاصطناعي' : $aiState) ?></span><?php endif; ?></div>
    <div class="panel-body">
      <div class="si-sources">
        <div class="field si-paste"><label for="si-text">الصق القائمة من واتساب أو إكسل</label>
          <textarea class="input" id="si-text" rows="9" placeholder="أحمد محمد 0599123456&#10;سارة علي 0567778899 ينتهي 2026-12-01"></textarea></div>
        <div class="field si-photo"><label>صورة الدفتر</label>
          <label class="si-drop<?= $aiOk ? '' : ' is-disabled' ?>" data-si-drop>
            <input type="file" accept="image/jpeg,image/png,image/webp" multiple data-si-files<?= $aiOk ? '' : ' disabled' ?>>
            <span class="si-drop-icon"><?= icon('camera') ?></span>
            <b><?= $aiOk ? 'التقط صورة أو اختر حتى 3 صور' : 'تحتاج الذكاء الاصطناعي' ?></b>
            <span class="text-muted text-sm"><?= $aiOk ? 'JPG · PNG · WEBP' : e($aiOffered ? $aiState : 'غير مفعّل للحساب') ?></span>
          </label>
          <div class="si-thumbs" data-si-thumbs></div>
        </div>
      </div>
      <div class="form-grid mt-3">
        <div class="field col-6"><label for="si-plan">الباقة لمن لم تُذكر باقته</label><select class="input" id="si-plan" data-si-plan><option value="">—</option><?php foreach ($plans as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="field col-6"><label for="si-router">جهاز MikroTik<?= $needRouter ? ' <span class="req">*</span>' : '' ?></label><select class="input" id="si-router" data-si-router><option value=""><?= $needRouter ? 'اختر' : '—' ?></option><?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="slug-status" data-si-status aria-live="polite"></div>
      <div class="form-actions"><button type="button" class="btn btn-primary" data-si-parse><?= icon('table') ?>ترتيب في جدول</button></div>
    </div>
  </div>

  <div class="panel si-step" data-si-review hidden>
    <div class="panel-head"><h3><?= icon('list-checks') ?>المراجعة</h3><div class="panel-tools"><span class="badge" data-si-count></span><span class="badge badge-danger" data-si-bad hidden></span></div></div>
    <div class="table-wrap"><table class="table si-table">
      <thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>اسم الدخول</th><th>كلمة المرور</th><th>الباقة</th><th>ينتهي في</th><th>العنوان</th><th></th></tr></thead>
      <tbody data-si-rows></tbody>
    </table></div>
    <div class="panel-body si-foot">
      <button type="button" class="btn btn-ghost" data-si-add><?= icon('plus') ?>صف</button>
      <span class="si-foot-gap"></span>
      <button type="button" class="btn" data-si-back><?= icon('chevron-right') ?>رجوع</button>
      <button type="button" class="btn btn-primary" data-si-save><?= icon('save') ?><span data-si-save-label>حفظ</span></button>
    </div>
    <div class="panel-body si-msg" data-si-msg hidden></div>
  </div>
</div>
