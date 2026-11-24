<?php
/** @var App\Core\View $v */
use App\Platform\Landing;

$v->title = 'صفحة الهبوط';
$v->subtitle = 'تتبع تصميم النظام المختار من «التصاميم» تلقائياً، وتظهر بلغة الزائر (العربية أو الإنجليزية)';
$v->actions = '<a class="btn" href="' . e(url('/')) . '" target="_blank" rel="noopener">' . icon('external-link') . 'عرض الصفحة</a>';
$long = ['hero_text', 'features_text', 'apps_text', 'cta_text'];
$enabled = Landing::enabled();
?>
<form method="post" action="<?= e(url('/admin/landing')) ?>" data-landing-form>
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel" data-tabs-wrap>
        <div class="tabs" data-tabs>
          <a class="tab is-active" href="#lp-ar" data-tab="ar"><?= icon('type') ?>النصوص بالعربية</a>
          <a class="tab" href="#lp-en" data-tab="en"><?= icon('globe') ?><span lang="en" dir="ltr">English</span></a>
        </div>
        <?php foreach (['ar' => '', 'en' => 'en_'] as $loc => $pf): ?>
        <div class="tab-panel<?= $loc === 'ar' ? ' is-active' : '' ?>" data-tab-panel="<?= $loc ?>">
          <div class="panel-body">
            <?php if ($loc === 'en'): ?>
            <div class="notice" style="margin-top:0"><?= icon('info') ?><div class="text-sm">ما تتركه فارغاً هنا يظهر للزائر الإنجليزي بترجمة النص العربي الافتراضي من قاموس النظام. إن عدّلت النص العربي فاكتب نسخته الإنجليزية هنا.</div></div>
            <?php endif; ?>
            <div class="form-grid"<?= $loc === 'en' ? ' dir="ltr" lang="en"' : '' ?>>
              <?php foreach (Landing::TEXT_FIELDS as $k => $label): $val = Landing::raw($k, $loc); $id = 'lf-' . $loc . '-' . $k; ?>
                <div class="field <?= in_array($k, $long, true) || $k === 'hero_title' ? 'col-12' : 'col-6' ?>">
                  <label for="<?= e($id) ?>"<?= $loc === 'en' ? ' dir="rtl"' : '' ?>><?= e($label) ?></label>
                  <?php if (in_array($k, $long, true)): ?>
                    <textarea class="input" id="<?= e($id) ?>" name="<?= $pf . $k ?>" rows="2" maxlength="400"><?= e($val) ?></textarea>
                  <?php else: ?>
                    <input class="input" id="<?= e($id) ?>" name="<?= $pf . $k ?>" value="<?= e($val) ?>" maxlength="400">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php foreach (Landing::LIST_FIELDS as $k => $label): ?>
          <div class="lp-list-block" data-rows="<?= $pf . $k ?>">
            <div class="panel-head"><h3><?= icon('list') ?><?= e($label) ?><?= $loc === 'en' ? ' <span class="badge badge-outline" lang="en">EN</span>' : '' ?></h3><button type="button" class="btn btn-sm" data-row-add><?= icon('plus') ?>إضافة</button></div>
            <div class="panel-body stack rows-list" style="gap:10px"<?= $loc === 'en' ? ' dir="ltr" lang="en"' : '' ?>>
              <?php foreach (Landing::rawItems($k, $loc) as [$a, $b]): ?>
              <div class="row-item">
                <input class="input" name="<?= $pf . $k ?>_title[]" value="<?= e($a) ?>" maxlength="120" aria-label="العنوان">
                <input class="input" name="<?= $pf . $k ?>_text[]" value="<?= e($b) ?>" maxlength="300" aria-label="الوصف">
                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" data-row-del title="حذف"><?= icon('trash-2') ?></button>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="stack design-side">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('palette') ?>تصميم الصفحة</h3></div>
        <div class="panel-body stack" style="gap:10px">
          <div class="choice-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
            <?php $style = (string) old('style', Landing::style()); foreach (Landing::STYLES as $k => $label): ?>
              <label class="choice"><input type="radio" name="style" value="<?= e($k) ?>"<?= checked($style === $k) ?>>
                <span class="choice-card" style="padding:8px"><img src="<?= e(asset('img/landing/style-' . $k . '.webp')) ?>" width="560" height="350" alt="" loading="lazy" style="display:block;width:100%;height:auto;border-radius:8px"><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="hint" style="margin:0">النصوص والأقسام نفسها في التصميمين، وتبدّل بينهما متى شئت. جولة النظام وفيديو العرض في التصميم الجديد.</p>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('globe') ?>حالة الصفحة</h3><?= $enabled ? '<span class="badge badge-success">مفعّلة</span>' : '<span class="badge badge-outline">موقوفة</span>' ?></div>
        <div class="panel-body stack" style="gap:10px">
          <label class="switch"><input type="checkbox" name="enabled" value="1"<?= checked($enabled) ?>><span class="track"></span><span>تفعيل صفحة الهبوط</span></label>
          <p class="hint" style="margin:0">عند الإيقاف يفتح رابط المنصة صفحة تسجيل الدخول مباشرة.</p>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('play') ?>فيديو العرض</h3><?= Landing::video() !== '' ? '<span class="badge badge-success">ظاهر</span>' : '' ?></div>
        <div class="panel-body stack" style="gap:8px">
          <div class="field">
            <label for="lf-video">رابط الفيديو على YouTube</label>
            <input class="input" id="lf-video" name="video" dir="ltr" value="<?= e((string) old('video', Landing::video() !== '' ? 'https://youtu.be/' . Landing::video() : '')) ?>" placeholder="https://youtu.be/..." maxlength="200" inputmode="url">
            <?= error_html('video') ?>
          </div>
          <p class="hint" style="margin:0">يظهر زر «شاهد العرض» في أعلى الصفحة ويُفتح الفيديو داخلها. اتركه فارغاً لإخفاء الزر.</p>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('layout-grid') ?>الأقسام الظاهرة</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <?php foreach (Landing::SECTIONS as $k => $label): ?>
            <label class="switch"><input type="checkbox" name="show_<?= $k ?>" value="1"<?= checked(Landing::on($k)) ?>><span class="track"></span><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel">
        <div class="panel-body stack text-sm" style="gap:8px">
          <p style="margin:0"><?= icon('palette', 'icon-sm') ?> الألوان والخط والزوايا والأزرار تتبع التصميم المختار في صفحة <a href="<?= e(url('/admin/design')) ?>">التصاميم</a>.</p>
          <p style="margin:0" class="text-muted">يبدّل الزائر اللغة من أعلى الصفحة متى سمحت بذلك في «التصاميم».</p>
        </div>
      </div>
      <button class="btn btn-primary btn-lg btn-block"><?= icon('save') ?>حفظ صفحة الهبوط</button>
    </div>
  </div>
</form>
<script>
document.querySelector('[data-landing-form]').addEventListener('click', function (e) {
  var add = e.target.closest('[data-row-add]');
  var del = e.target.closest('[data-row-del]');
  if (add) {
    var panel = add.closest('[data-rows]');
    var k = panel.getAttribute('data-rows');
    var row = document.createElement('div');
    row.className = 'row-item';
    row.innerHTML = '<input class="input" name="' + k + '_title[]" maxlength="120" aria-label="العنوان"><input class="input" name="' + k + '_text[]" maxlength="300" aria-label="الوصف">'
      + '<button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" data-row-del title="حذف">' + SN.icon('trash-2') + '</button>';
    panel.querySelector('.rows-list').appendChild(row);
    row.querySelector('input').focus();
  }
  if (del) { del.closest('.row-item').remove(); }
});
</script>
