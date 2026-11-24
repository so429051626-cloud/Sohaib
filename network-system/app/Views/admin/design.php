<?php
/** @var App\Core\View $v @var array $t */
use App\Core\Theme;

$v->title = 'التصاميم';
$v->subtitle = 'اختر تصميماً جاهزاً أو خصّص كل تفصيلة، وشاهد النتيجة مباشرة قبل التطبيق على كل الأنظمة';
$v->script('js/design.js');
$faces = '';
foreach (array_keys(Theme::FONTS) as $fk) {
    $faces .= Theme::fontFaces($fk, [400, 700]);
}
foreach (array_keys(Theme::FONTS_LATIN) as $fk) {
    $faces .= Theme::latinFaces($fk, [400, 700]);
}
$locale = \App\Core\Lang::default();
$localeSwitch = \App\Core\Lang::switchable();

// ألوان المصغّرات لكل تصميم جاهز
$mini = static function (array $p): string {
    [, $c1, , $c2] = Theme::PALETTES[$p['palette']];
    $bg = ['plain' => '#f3f5f9', 'tinted' => 'color-mix(in srgb, ' . $c1 . ' 9%, #f3f5f9)', 'mesh' => 'radial-gradient(80% 70% at 100% 0%, color-mix(in srgb, ' . $c1 . ' 26%, transparent), transparent 70%), radial-gradient(70% 60% at 0% 100%, color-mix(in srgb, ' . $c2 . ' 22%, transparent), transparent 70%), #f1f3f9',
        'dots' => 'radial-gradient(color-mix(in srgb, #0f172a 14%, transparent) 1px, transparent 1.4px) 0 0 / 8px 8px, #f6f4ef', 'grid' => 'linear-gradient(rgba(15,23,42,.06) 1px, transparent 1px) 0 0 / 10px 10px, linear-gradient(90deg, rgba(15,23,42,.06) 1px, transparent 1px) 0 0 / 10px 10px, #fff'][$p['background']];
    if ($p['skin'] === 'outline' && $p['background'] === 'plain') {
        $bg = '#fff';
    }
    $side = ['light' => '#ffffff', 'dark' => 'linear-gradient(180deg, #0a1120, #111b31)', 'brand' => 'linear-gradient(180deg, color-mix(in srgb, ' . $c1 . ' 82%, #000), ' . $c1 . ')',
        'gradient' => 'linear-gradient(165deg, color-mix(in srgb, ' . $c1 . ' 70%, #000), ' . $c1 . ' 50%, color-mix(in srgb, ' . $c1 . ' 35%, ' . $c2 . '))', 'glass' => 'rgba(255,255,255,.62)', 'minimal' => 'transparent'][$p['sidebar']];
    $onSide = in_array($p['sidebar'], ['dark', 'brand', 'gradient'], true) ? 'rgba(255,255,255,.55)' : 'color-mix(in srgb, #0f172a 16%, transparent)';
    $top = ['glass' => 'rgba(255,255,255,.85)', 'solid' => '#fff', 'brand' => 'linear-gradient(120deg, ' . $c1 . ', color-mix(in srgb, ' . $c1 . ' 50%, ' . $c2 . '))', 'blend' => 'transparent'][$p['topbar']];
    $btn = $p['buttons'] === 'gradient' ? 'linear-gradient(135deg, ' . $c1 . ', color-mix(in srgb, ' . $c1 . ' 55%, ' . $c2 . '))' : $c1;
    $r = ['sharp' => '3px', 'normal' => '5px', 'round' => '8px'][$p['radius']];
    $card = ['classic' => '#fff;box-shadow:0 1px 2px rgba(15,23,42,.08);border:1px solid #e3e8ef', 'soft' => '#fff;box-shadow:0 4px 10px -4px rgba(15,23,42,.18)', 'glass' => 'rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.8);box-shadow:0 6px 14px -8px rgba(15,23,42,.3)',
        'elevated' => '#fff;box-shadow:0 6px 14px -6px rgba(15,23,42,.28)', 'flat' => '#fff;border:1px solid #e2e7ee', 'outline' => '#fff;border:1px solid #d6d9df'][$p['skin']];
    return '--mz-bg:' . $bg . ';--mz-side:' . $side . ';--mz-on-side:' . $onSide . ';--mz-top:' . $top . ';--mz-c1:' . $c1 . ';--mz-btn:' . $btn . ';--mz-r:' . $r . ';--mz-card:' . $card
        . ';--mz-pill:' . ($p['buttons'] === 'pill' ? '99px' : $r) . ';--mz-float:' . ($p['sidebar_shape'] === 'floating' ? '4px' : '0px');
};
$presets = [];
foreach (Theme::PRESETS as $k => $p) {
    $presets[$k] = $p[2];
}
$fields = static function (string $name, array $opts) use ($t): string {
    $h = '<div class="segmented is-block dz-seg">';
    foreach ($opts as $k => $l) {
        $h .= '<label><input type="radio" name="' . e($name) . '" value="' . e((string) $k) . '"' . checked($t[$name] === (string) $k) . '><span>' . e($l) . '</span></label>';
    }
    return $h . '</div>';
};
?>
<style><?= $faces ?></style>
<form method="post" action="<?= e(url('/admin/design')) ?>" class="dz" data-design-form data-preview-url="<?= e(url('/admin/design/preview')) ?>">
  <?= csrf_field() ?>
  <script type="application/json" data-design-presets><?= json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

  <div class="panel">
    <div class="panel-head"><h3><?= icon('swatch-book') ?>التصاميم الجاهزة</h3><span class="dz-state" data-preset-state><?= $t['preset'] === 'custom' ? 'تصميم مخصص' : 'المطبّق: ' . e(Theme::PRESETS[$t['preset']][0]) ?></span></div>
    <div class="panel-body">
      <div class="dz-presets">
        <?php foreach (Theme::PRESETS as $k => [$name, $desc, $vals]): ?>
        <button type="button" class="dz-preset<?= $t['preset'] === $k ? ' is-active' : '' ?>" data-preset="<?= e($k) ?>" aria-pressed="<?= $t['preset'] === $k ? 'true' : 'false' ?>">
          <span class="dz-mini" style="<?= e($mini($vals)) ?>" aria-hidden="true">
            <span class="dz-mini-side"><i></i><i class="on"></i><i></i><i></i></span>
            <span class="dz-mini-main"><span class="dz-mini-top"></span><span class="dz-mini-cards"><i></i><i></i><i></i></span><span class="dz-mini-panel"><b></b><s></s><s></s></span></span>
          </span>
          <span class="dz-preset-text"><b><?= e($name) ?></b><small><?= e($desc) ?></small></span>
          <span class="dz-check" aria-hidden="true"><?= icon('check') ?></span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="dz-layout">
    <div class="stack dz-controls">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('layers') ?>نمط النظام</h3></div>
        <div class="panel-body">
          <div class="choice-grid dz-skins">
            <?php foreach (Theme::SKINS as $k => $label): ?>
              <label class="choice"><input type="radio" name="skin" value="<?= e($k) ?>"<?= checked($t['skin'] === $k) ?>>
                <span class="choice-card"><span class="dz-skin is-<?= e($k) ?>"><i></i><i></i></span><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('palette') ?>اللون الرئيسي</h3></div>
        <div class="panel-body">
          <div class="choice-grid is-colors">
            <?php foreach (Theme::PALETTES as $k => [$label, $light, , $light2]): ?>
              <label class="choice"><input type="radio" name="palette" value="<?= e($k) ?>"<?= checked($t['palette'] === $k) ?>>
                <span class="choice-card"><span class="color-dot dz-dot" style="background:linear-gradient(135deg, <?= e($light) ?> 55%, <?= e($light2) ?>)"></span><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('panel-left') ?>القائمة الجانبية</h3></div>
        <div class="panel-body">
          <div class="choice-grid dz-sidebars">
            <?php foreach (Theme::SIDEBAR as $k => $label): ?>
              <label class="choice"><input type="radio" name="sidebar" value="<?= e($k) ?>"<?= checked($t['sidebar'] === $k) ?>>
                <span class="choice-card"><span class="dz-side is-<?= e($k) ?>"><i></i><i class="on"></i><i></i></span><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-grid mt-3">
            <div class="field col-12"><label>شكل القائمة</label><?= $fields('sidebar_shape', Theme::SIDEBAR_SHAPE) ?></div>
            <div class="field col-12"><label>شكل الروابط</label><?= $fields('nav_style', Theme::NAV_STYLE) ?></div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('panel-top') ?>الشريط العلوي والخلفية</h3></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12"><label>الشريط العلوي</label><?= $fields('topbar', Theme::TOPBAR) ?></div>
            <div class="field col-12"><label>خلفية الصفحات</label><?= $fields('background', Theme::BACKGROUND) ?></div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('type') ?>الخط</h3></div>
        <div class="panel-body">
          <div class="choice-grid is-fonts">
            <?php foreach (Theme::FONTS as $k => [$label, $family]): ?>
              <label class="choice"><input type="radio" name="font" value="<?= e($k) ?>"<?= checked($t['font'] === $k) ?>>
                <span class="choice-card"><span class="font-sample" style="font-family:'<?= e($family) ?>'">أبجد هوز 123</span><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-grid mt-3">
            <div class="field col-12"><label>حجم الخط</label><?= $fields('font_size', Theme::FONT_SIZE) ?></div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('globe') ?>اللغة والخطوط العالمية</h3></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12"><label>لغة الأنظمة الأساسية</label>
              <div class="segmented is-block dz-seg">
                <label><input type="radio" name="locale" value="ar"<?= checked($locale === 'ar') ?>><span>العربية</span></label>
                <label><input type="radio" name="locale" value="en"<?= checked($locale === 'en') ?>><span lang="en" dir="ltr">English</span></label>
              </div>
              <span class="hint">اللغة التي تظهر بها لوحة المسؤول وأنظمة كل الزبائن وصفحة الهبوط افتراضياً. الترجمة مكتوبة داخل النظام ولا تعتمد على ترجمة جوجل.</span>
            </div>
            <div class="field col-12"><label class="switch"><input type="checkbox" name="locale_switch" value="1"<?= checked($localeSwitch) ?>><span class="track"></span><span>السماح لكل مستخدم بالتبديل بين العربية والإنجليزية من الشريط العلوي</span></label></div>
          </div>
          <div class="field mt-3"><label>الخط العالمي للحروف اللاتينية والأرقام</label></div>
          <div class="choice-grid is-fonts dz-latin">
            <?php foreach (Theme::FONTS_LATIN as $k => [$label, $family]): ?>
              <label class="choice"><input type="radio" name="font_latin" value="<?= e($k) ?>"<?= checked($t['font_latin'] === $k) ?>>
                <span class="choice-card"><span class="font-sample" dir="ltr"<?= $family !== '' ? ' style="font-family:\'' . e($family) . '\'"' : '' ?>>Aa 2026</span><small<?= $k !== 'auto' ? ' dir="ltr"' : '' ?>><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('layout-grid') ?>الأزرار والزوايا والكثافة</h3></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12"><label>الأزرار</label><?= $fields('buttons', Theme::BUTTONS) ?></div>
            <div class="field col-6"><label>الزوايا</label><?= $fields('radius', Theme::RADIUS) ?></div>
            <div class="field col-6"><label>كثافة العرض</label><?= $fields('density', Theme::DENSITY) ?></div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3><?= icon('sparkles') ?>الحركة والانتقالات</h3></div>
        <div class="panel-body">
          <div class="choice-grid is-motion">
            <?php foreach (Theme::MOTION as $k => $label): ?>
              <label class="choice"><input type="radio" name="motion" value="<?= e($k) ?>"<?= checked($t['motion'] === $k) ?>>
                <span class="choice-card"><span class="motion-demo is-<?= e($k) ?>"><i></i><i></i><i></i></span><small><?= e($label) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-grid mt-3">
            <div class="field col-6"><label for="dz-stagger">التأخير بين العناصر المتتابعة</label><div class="input-group"><input class="input" id="dz-stagger" type="number" name="stagger" min="0" max="30" step="5" value="<?= e($t['stagger']) ?>"><span class="input-addon">ملي ثانية</span></div></div>
            <div class="field col-6"><label for="dz-speed">مدة الحركة</label><div class="input-group"><input class="input" id="dz-speed" type="number" name="speed" min="120" max="260" step="20" value="<?= e($t['speed']) ?>"><span class="input-addon">ملي ثانية</span></div></div>
            <div class="field col-6"><label class="switch"><input type="checkbox" name="page_fx" value="1"<?= checked($t['page_fx'] === '1') ?>><span class="track"></span><span>انتقال ناعم بين الصفحات</span></label></div>
            <div class="field col-6"><label class="switch"><input type="checkbox" name="haptics" value="1"<?= checked($t['haptics'] === '1') ?>><span class="track"></span><span>الاهتزاز عند اللمس</span></label></div>
          </div>
        </div>
      </div>
    </div>

    <div class="dz-side-col">
      <div class="panel dz-preview-panel">
        <div class="panel-head">
          <h3><?= icon('eye') ?>معاينة حيّة</h3>
          <div class="panel-tools">
            <div class="segmented dz-seg-sm" role="group" aria-label="الوضع">
              <label><input type="radio" name="_pv_mode" value="light" checked data-pv-mode><span><?= icon('sun', 'icon-sm') ?>فاتح</span></label>
              <label><input type="radio" name="_pv_mode" value="dark" data-pv-mode><span><?= icon('moon', 'icon-sm') ?>داكن</span></label>
            </div>
            <div class="segmented dz-seg-sm" role="group" aria-label="الشاشة">
              <label><input type="radio" name="_pv_device" value="desktop" checked data-pv-device><span><?= icon('monitor', 'icon-sm') ?></span></label>
              <label><input type="radio" name="_pv_device" value="mobile" data-pv-device><span><?= icon('smartphone', 'icon-sm') ?></span></label>
            </div>
          </div>
        </div>
        <div class="dz-stage" data-pv-stage>
          <div class="dz-frame" data-pv-frame-wrap><iframe title="معاينة التصميم" data-pv-frame loading="lazy" tabindex="-1"></iframe></div>
          <div class="dz-loading" data-pv-loading><span class="spinner"></span></div>
        </div>
      </div>
      <div class="dz-apply">
        <button class="btn btn-primary btn-lg btn-block"><?= icon('check') ?>تطبيق على كل الأنظمة</button>
        <p class="text-sm text-muted">يُطبَّق التصميم فوراً على لوحة المسؤول وعلى أنظمة كل الزبائن. يبقى لكل مستخدم اختيار الوضع الفاتح أو الداكن.</p>
      </div>
    </div>
  </div>
</form>
