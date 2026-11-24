<?php
/** @var App\Core\View $v @var array $plans */
use App\Core\Platform;
use App\Platform\Billing;

$v->title = 'الباقات';
$v->actions = '<button type="button" class="btn btn-primary" data-modal-open="m-plan" data-plan="">' . icon('plus') . 'باقة جديدة</button>';
$cur = Platform::setting('platform.currency');
$lim = static fn ($x) => $x === null ? 'بلا حد' : num((int) $x);
?>
<?php if (!$plans): ?>
<div class="panel"><div class="empty"><?= icon('package') ?><div class="empty-title">لا توجد باقات</div></div></div>
<?php else: ?>
<div class="plan-grid">
  <?php foreach ($plans as $p): $mods = Platform::planModules($p); ?>
  <div class="panel plan-card<?= (int) $p['is_featured'] ? ' is-featured' : '' ?><?= (int) $p['is_active'] ? '' : ' is-off' ?>">
    <div class="plan-card-head">
      <div>
        <div class="plan-card-name"><?= e($p['name']) ?></div>
        <?php if ($p['tagline']): ?><div class="text-muted text-sm"><?= e($p['tagline']) ?></div><?php endif; ?>
      </div>
      <div class="plan-card-badges">
        <?php if (!(int) $p['is_active']): ?><span class="badge badge-outline">متوقفة</span><?php endif; ?>
        <?php if ((int) $p['is_featured']): ?><span class="badge badge-success">مميّزة</span><?php endif; ?>
        <?php if (!(int) $p['is_public']): ?><span class="badge">غير معروضة</span><?php endif; ?>
      </div>
    </div>
    <div class="plan-card-price"><b><?= ltr(e(number_format((float) $p['price'], abs($p['price'] - round((float) $p['price'])) < 0.005 ? 0 : 2))) ?></b><span><?= e($cur) ?> / <?= e(Billing::monthsLabel((int) $p['months'])) ?></span></div>
    <dl class="kv plan-card-kv">
      <dt>أجهزة MikroTik</dt><dd><?= $lim($p['max_routers']) ?></dd>
      <dt>المشتركون</dt><dd><?= $lim($p['max_subscribers']) ?></dd>
      <dt>الموظفون</dt><dd><?= $lim($p['max_employees']) ?></dd>
      <dt>رسائل الذكاء الاصطناعي</dt><dd><?= (int) $p['ai_messages'] > 0 ? num((int) $p['ai_messages']) . ' شهرياً' : 'غير مشمولة' ?></dd>
      <dt>الأقسام</dt><dd><?= $mods === null ? 'كل الأقسام' : num(count($mods)) . ' من ' . num(count(Platform::MODULES)) ?></dd>
      <dt>الحسابات</dt><dd><?= num((int) $p['tenants']) ?></dd>
    </dl>
    <div class="plan-card-foot">
      <button type="button" class="btn btn-sm" data-modal-open="m-plan" data-plan="<?= e(json_encode($p + ['mods' => $mods], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('pencil') ?>تعديل</button>
      <form method="post" action="<?= e(url('/admin/plans/' . $p['id'] . '/delete')) ?>" data-confirm="حذف الباقة <?= e($p['name']) ?>؟"><?= csrf_field() ?><button class="btn btn-sm btn-ghost text-danger"><?= icon('trash-2') ?>حذف</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<dialog class="modal" id="m-plan">
  <form method="post" action="<?= e(url('/admin/plans')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-head"><h3><?= icon('package') ?>باقة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label>اسم الباقة</label><input class="input" name="name" required maxlength="80"></div>
        <div class="field col-6"><label>الوصف المختصر</label><input class="input" name="tagline" maxlength="160"></div>
        <div class="field col-4"><label>السعر</label><div class="input-group"><input class="input input-ltr" name="price" inputmode="decimal" required><span class="input-addon"><?= e($cur) ?></span></div></div>
        <div class="field col-4"><label>المدة</label><div class="input-group"><input class="input input-ltr" name="months" inputmode="numeric" required><span class="input-addon">شهر</span></div></div>
        <div class="field col-4"><label>الترتيب</label><input class="input input-ltr" name="sort" inputmode="numeric"></div>
        <div class="form-section-title"><?= icon('gauge') ?>الحدود</div>
        <div class="field col-3"><label>أجهزة MikroTik</label><input class="input input-ltr" name="max_routers" inputmode="numeric" placeholder="بلا حد"></div>
        <div class="field col-3"><label>المشتركون</label><input class="input input-ltr" name="max_subscribers" inputmode="numeric" placeholder="بلا حد"></div>
        <div class="field col-3"><label>الموظفون</label><input class="input input-ltr" name="max_employees" inputmode="numeric" placeholder="بلا حد"></div>
        <div class="field col-3"><label>رسائل الذكاء شهرياً</label><input class="input input-ltr" name="ai_messages" inputmode="numeric" placeholder="0"></div>
        <div class="form-section-title"><?= icon('layout-grid') ?>الأقسام المفعّلة</div>
        <div class="field col-12"><div class="check-grid">
          <?php foreach (Platform::MODULES as $key => [$label]): ?>
            <label class="check"><input type="checkbox" name="modules[]" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div></div>
        <div class="form-section-title"><?= icon('list-checks') ?>المزايا المعروضة</div>
        <div class="field col-12"><textarea class="input" name="features" rows="4" maxlength="2000"></textarea></div>
        <div class="field col-12 switch-row">
          <label class="switch"><input type="checkbox" name="is_active" value="1"><span class="track"></span><span>مفعّلة</span></label>
          <label class="switch"><input type="checkbox" name="is_public" value="1"><span class="track"></span><span>تظهر في صفحة الهبوط</span></label>
          <label class="switch"><input type="checkbox" name="is_featured" value="1"><span class="track"></span><span>مميّزة</span></label>
          <label class="switch" data-plan-apply><input type="checkbox" name="apply_limits" value="1"><span class="track"></span><span>تطبيق الحدود على حساباتها</span></label>
        </div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-plan').addEventListener('modal:open', function (e) {
  var f = this.querySelector('form'), raw = e.detail.trigger.getAttribute('data-plan'), p = raw ? JSON.parse(raw) : null;
  var set = function (n, v) { f.elements[n].value = v === null || v === undefined ? '' : v; };
  set('id', p ? p.id : ''); set('name', p ? p.name : ''); set('tagline', p ? p.tagline : ''); set('price', p ? parseFloat(p.price) : '');
  set('months', p ? p.months : 1); set('sort', p ? p.sort : 0); set('features', p ? p.features : '');
  ['max_routers', 'max_subscribers', 'max_employees', 'ai_messages'].forEach(function (n) { set(n, p ? p[n] : ''); });
  f.querySelectorAll('input[name="modules[]"]').forEach(function (c) { c.checked = !p || p.mods === null || p.mods.indexOf(c.value) !== -1; });
  f.elements.is_active.checked = !p || p.is_active == 1; f.elements.is_public.checked = !p || p.is_public == 1; f.elements.is_featured.checked = !!p && p.is_featured == 1;
  f.elements.apply_limits.checked = false; f.querySelector('[data-plan-apply]').hidden = !p;
});
</script>
