<?php
/** @var App\Core\View $v @var array $policy @var array $rows @var string $q @var int $total */
use App\Controllers\AdminController;
use App\Services\CookiePolicy;

$v->title = 'الكوكيز';
$v->subtitle = 'الدخول التلقائي للجهاز المعروف على أجهزة MikroTik لكل الشبكات';
$durations = CookiePolicy::DURATIONS;
$durSelect = static function (string $current, string $id) use ($durations): string {
    $isCustom = !isset($durations[$current]);
    $h = '<div class="cookie-duration" data-cookie-duration>'
        . '<select class="input" name="duration" id="' . e($id) . '" data-cookie-duration-select>';
    foreach ($durations as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . selected($current, $k) . '>' . e($l) . '</option>';
    }
    $h .= '<option value="custom"' . ($isCustom ? ' selected' : '') . '>مدة أخرى…</option></select>'
        . '<input class="input input-ltr" name="duration_custom" value="' . e($isCustom ? $current : '') . '" placeholder="مثل 8h أو 5d" maxlength="8" data-cookie-duration-custom' . ($isCustom ? '' : ' hidden') . '>'
        . '</div>';
    return $h;
};
$state = static function (?array $f, array $own): array {
    if ($f !== null) {
        return $f['enabled']
            ? ['مفعّلة · ' . CookiePolicy::durationLabel($f['duration']), 'badge-success', $f['source'] === 'tenant' ? 'معيّنة لهذا الحساب' : 'من سياسة المنصة']
            : ['موقوفة', 'badge-danger', $f['source'] === 'tenant' ? 'موقوفة لهذا الحساب' : 'من سياسة المنصة'];
    }
    if ($own['enabled'] === null) {
        return ['غير معروف', 'badge-outline', 'تعذّر قراءة إعداد الشبكة'];
    }
    return $own['enabled']
        ? ['مفعّلة · ' . CookiePolicy::durationLabel($own['duration']), 'badge-info', 'من إعدادات الشبكة']
        : ['موقوفة', 'badge-outline', 'من إعدادات الشبكة'];
};
?>
<div class="grid grid-main-side">
  <div class="stack">
    <form method="post" action="<?= e(url('/admin/cookies')) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('cookie') ?>السياسة العامة لكل الشبكات</h3></div>
      <div class="panel-body">
        <div class="cookie-modes">
          <?php foreach (['tenant' => ['sliders-horizontal', 'كل شبكة تتحكم بنفسها', 'يُتبع إعداد «الدخول التلقائي» في إعدادات الهوتسبوت لكل شبكة.'],
                          'on' => ['circle-check', 'تفعيل لكل الشبكات', 'الجهاز الذي دخل بكرت يعود تلقائياً دون إدخال الكرت طوال المدة المحددة.'],
                          'off' => ['ban', 'إيقاف لكل الشبكات', 'يلزم إدخال الكرت في كل مرة، وتُحذف الكوكيز المحفوظة على الأجهزة فوراً.']] as $k => [$ic, $label, $hint]): ?>
          <label class="cookie-mode">
            <input type="radio" name="mode" value="<?= e($k) ?>"<?= checked($policy['mode'] === $k) ?> data-cookie-mode>
            <span class="cookie-mode-card"><span class="cookie-mode-icon is-<?= e($k) ?>"><?= icon($ic) ?></span><span class="cookie-mode-text"><b><?= e($label) ?></b><small><?= e($hint) ?></small></span></span>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="form-grid mt-3" data-cookie-when-on<?= $policy['mode'] === 'on' ? '' : ' hidden' ?>>
          <div class="field col-6"><label for="cookie-duration">مدة الكوكيز</label><?= $durSelect($policy['duration'], 'cookie-duration') ?><?= error_html('duration') ?></div>
          <div class="field col-6"><label>&nbsp;</label><p class="text-sm text-muted" style="margin:0">بعد انتهاء المدة يُطلب الكرت من جديد على الجهاز نفسه.</p></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ وتطبيق</button></div>
      </div>
    </form>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('building') ?>تعيين لكل حساب</h3><span class="text-sm text-muted"><?= num($total) ?> حساب</span></div>
      <form class="filters" method="get">
        <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="اسم الحساب أو رابطه"></div>
        <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('search') ?>بحث</button></div>
      </form>
      <?php if (!$rows): ?>
        <div class="empty"><?= icon('building') ?><div class="empty-title">لا توجد حسابات</div></div>
      <?php else: ?>
      <div class="cookie-list">
        <?php foreach ($rows as $r): [$stLabel, $stClass, $stSrc] = $state($r['forced'], $r['own']); $fid = 'ck-' . (int) $r['id']; ?>
        <form method="post" action="<?= e(url('/admin/cookies/tenants/' . (int) $r['id'])) ?>" class="cookie-row" data-cookie-row>
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e('/admin/cookies' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">
          <div class="cookie-row-head">
            <div class="cookie-row-name">
              <a href="<?= e(url('/admin/tenants/' . (int) $r['id'])) ?>" class="cell-title"><?= e($r['name']) ?></a>
              <div class="cell-sub"><span class="tenant-link">/<?= e($r['slug']) ?></span> <span class="badge <?= AdminController::STATUS[$r['status']][1] ?? 'badge-outline' ?>"><?= e(AdminController::STATUS[$r['status']][0] ?? $r['status']) ?></span></div>
            </div>
            <div class="cookie-row-state"><span class="badge <?= e($stClass) ?>"><?= icon('cookie', 'icon-sm') ?><?= e($stLabel) ?></span><small><?= e($stSrc) ?></small></div>
          </div>
          <div class="cookie-row-form">
            <div class="field"><label for="<?= e($fid) ?>-m">التعيين</label>
              <select class="input" name="mode" id="<?= e($fid) ?>-m" data-cookie-row-mode>
                <?php foreach (CookiePolicy::TENANT_MODES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($r['assign']['mode'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field" data-cookie-row-duration<?= $r['assign']['mode'] === 'on' ? '' : ' hidden' ?>><label for="<?= e($fid) ?>-d">المدة</label><?= $durSelect($r['assign']['duration'], $fid . '-d') ?></div>
            <div class="field cookie-row-save"><label>&nbsp;</label><button class="btn"><?= icon('check') ?>تطبيق</button></div>
          </div>
        </form>
        <?php endforeach; ?>
      </div>
      <?php if ($total > count($rows)): ?><div class="panel-foot text-sm text-muted">يُعرض <?= num(count($rows)) ?> من <?= num($total) ?> · استخدم البحث للوصول إلى حساب محدد</div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>كيف تعمل الكوكيز؟</h3></div>
      <div class="panel-body stack text-sm" style="gap:10px">
        <p style="margin:0">عند الدخول بكرت يحفظ جهاز MikroTik «كوكيز» لجهاز المستخدم (حسب عنوان MAC)، فيدخل تلقائياً في المرات التالية دون إدخال الكرت حتى تنتهي المدة.</p>
        <p style="margin:0"><b>الأولوية:</b> تعيين الحساب ← سياسة المنصة ← إعداد الشبكة نفسها.</p>
        <p style="margin:0"><b>التطبيق:</b> تتحدّث ملفات السرعة على كل أجهزة MikroTik للحساب تلقائياً خلال دقيقة. عند الإيقاف أو تقصير المدة تُحذف الكوكيز المحفوظة على الأجهزة فوراً.</p>
        <p class="text-muted" style="margin:0">لا يظهر هذا الإجراء في تنبيهات الزبون ولا في سجل عملياته.</p>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  function sync(scope) {
    scope.querySelectorAll('[data-cookie-duration]').forEach(function (box) {
      var sel = box.querySelector('[data-cookie-duration-select]');
      var custom = box.querySelector('[data-cookie-duration-custom]');
      custom.hidden = sel.value !== 'custom';
    });
  }
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-cookie-duration-select]')) { sync(t.closest('[data-cookie-duration]').parentNode); var c = t.closest('[data-cookie-duration]').querySelector('[data-cookie-duration-custom]'); if (!c.hidden) { c.focus(); } }
    if (t.matches('[data-cookie-mode]')) { var on = document.querySelector('[data-cookie-when-on]'); if (on) { on.hidden = t.value !== 'on'; } }
    if (t.matches('[data-cookie-row-mode]')) { var d = t.closest('[data-cookie-row]').querySelector('[data-cookie-row-duration]'); if (d) { d.hidden = t.value !== 'on'; } }
  });
})();
</script>
