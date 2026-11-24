<?php
/** @var App\Core\View $v */
use App\Services\PrintTemplates;

$isEdit = (int) $template['id'] > 0;
$v->title = $isEdit ? 'تصميم القالب: ' . $template['name'] : 'قالب طباعة جديد';
$v->breadcrumbs = [['طباعة الكروت', '/print'], [$isEdit ? $template['name'] : 'قالب جديد', null]];
$v->wide = true;
$v->actions = '<a class="btn" href="' . e(url('/print')) . '">' . icon('chevron-right') . 'رجوع</a>'
    . '<button type="button" class="btn btn-primary" data-save>' . icon('save') . 'حفظ القالب</button>';
$v->script('vendor/qrcode.js');
$v->script('js/card-render.js');
$v->script('js/designer.js');
$orientation = (float) $template['paper_width'] > (float) $template['paper_height'] ? 'landscape' : 'portrait';
$config = [
    'template' => [
        'id' => (int) $template['id'], 'name' => $template['name'], 'paper' => $template['paper'],
        'orientation' => $orientation,
        'paper_width' => (float) $template['paper_width'], 'paper_height' => (float) $template['paper_height'],
        'cols' => (int) $template['cols'], 'rows' => (int) $template['rows'],
        'margin_top' => (float) $template['margin_top'], 'margin_right' => (float) $template['margin_right'],
        'margin_bottom' => (float) $template['margin_bottom'], 'margin_left' => (float) $template['margin_left'],
        'gap_x' => (float) $template['gap_x'], 'gap_y' => (float) $template['gap_y'],
        'category_id' => $template['category_id'] ? (int) $template['category_id'] : '', 'is_default' => (int) $template['is_default'],
    ],
    'design' => $design,
    'sample' => $sample,
    'papers' => array_map(static fn ($p) => ['label' => $p[0], 'w' => $p[1], 'h' => $p[2]], PrintTemplates::PAPERS),
    'fields' => PrintTemplates::FIELDS,
    'base' => root_path(),
    'logo' => $logo,
    'saveUrl' => url('/print/templates'),
    'uploadUrl' => url('/print/upload'),
    'editUrl' => url('/print/templates/'),
];
?>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<div class="designer" id="designer">
  <div class="stack">
    <div class="panel">
      <div class="panel-head">
        <h2><?= icon('mouse-pointer-2') ?>الكرت</h2>
        <div class="panel-tools">
          <span class="mini-stat" data-card-size></span>
          <div class="btn-group">
            <button type="button" class="btn btn-sm btn-icon" data-undo title="تراجع (Ctrl+Z)"><?= icon('undo-2') ?></button>
            <button type="button" class="btn btn-sm btn-icon" data-redo title="إعادة (Ctrl+Y)"><?= icon('redo-2') ?></button>
          </div>
          <div class="btn-group">
            <button type="button" class="btn btn-sm btn-icon" data-zoom="-1" title="تصغير"><?= icon('zoom-out') ?></button>
            <button type="button" class="btn btn-sm" data-zoom="0" title="ملاءمة"><span data-zoom-label>100%</span></button>
            <button type="button" class="btn btn-sm btn-icon" data-zoom="1" title="تكبير"><?= icon('zoom-in') ?></button>
          </div>
          <label class="check text-sm"><input type="checkbox" data-grid>خطوط الشبكة</label>
          <label class="check text-sm"><input type="checkbox" data-snap checked>المحاذاة التلقائية</label>
        </div>
      </div>
      <div class="panel-body">
        <div class="designer-stage" data-stage>
          <div class="designer-zoom" data-zoom-box></div>
        </div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('layout-template') ?>معاينة الصفحة</h3><span class="mini-stat" data-page-info></span></div>
      <div class="panel-body"><div class="page-preview" data-page-preview><div class="page-preview-inner" data-page-inner></div></div></div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <nav class="tabs" data-tabs>
        <button type="button" class="tab is-active" data-tab="elements"><?= icon('layers') ?>العناصر</button>
        <button type="button" class="tab" data-tab="page"><?= icon('file-text') ?>الصفحة</button>
        <button type="button" class="tab" data-tab="card"><?= icon('square') ?>التصميم</button>
      </nav>

      <div class="tab-panel is-active panel-body" data-tab-panel="elements">
        <div class="label mb-1">إضافة عنصر</div>
        <div class="palette mb-3">
          <button type="button" class="btn" data-add="username"><?= icon('hash') ?>رقم الكرت</button>
          <button type="button" class="btn" data-add="password"><?= icon('key-round') ?>كلمة المرور</button>
          <button type="button" class="btn" data-add="userpass"><?= icon('link') ?>الرقم وكلمة المرور</button>
          <button type="button" class="btn" data-add="short"><?= icon('link-2') ?>الرقم المختصر</button>
          <button type="button" class="btn" data-add="shortpass"><?= icon('lock') ?>كلمة المرور المختصرة</button>
          <button type="button" class="btn" data-add="price"><?= icon('banknote') ?>السعر</button>
          <button type="button" class="btn" data-add="category"><?= icon('tag') ?>الباقة</button>
          <button type="button" class="btn" data-add="validity"><?= icon('calendar-clock') ?>الصلاحية</button>
          <button type="button" class="btn" data-add="serial"><?= icon('binary') ?>الرقم التسلسلي</button>
          <button type="button" class="btn" data-add="network"><?= icon('wifi') ?>اسم الشبكة</button>
          <button type="button" class="btn" data-add="text"><?= icon('type') ?>نص حر</button>
          <button type="button" class="btn" data-add="qr"><?= icon('qr-code') ?>رمز QR</button>
          <button type="button" class="btn" data-add="logo"><?= icon('image') ?>الشعار</button>
          <button type="button" class="btn" data-add="image"><?= icon('image') ?>صورة</button>
          <button type="button" class="btn" data-add="rect"><?= icon('square') ?>مستطيل</button>
        </div>
        <div data-props></div>
        <div class="label mt-3 mb-1">الطبقات</div>
        <ul class="list layers" data-layers style="border:1px solid var(--border);border-radius:10px;max-height:260px;overflow:auto"></ul>
      </div>

      <div class="tab-panel panel-body" data-tab-panel="page">
        <div class="prop-grid">
          <div class="field full"><label>اسم القالب</label><input class="input" data-t="name"></div>
          <div class="field"><label>الورق</label><select class="input" data-t="paper"></select></div>
          <div class="field"><label>الاتجاه</label><select class="input" data-t="orientation"><option value="portrait">عمودي</option><option value="landscape">أفقي</option></select></div>
          <div class="field" data-custom-paper><label>العرض (مم)</label><input class="input" data-t="paper_width" inputmode="decimal"></div>
          <div class="field" data-custom-paper><label>الارتفاع (مم)</label><input class="input" data-t="paper_height" inputmode="decimal"></div>
          <div class="field"><label>عدد الأعمدة</label><input class="input" data-t="cols" type="number" min="1" max="20"></div>
          <div class="field"><label>عدد الصفوف</label><input class="input" data-t="rows" type="number" min="1" max="40"></div>
          <div class="field"><label>الهامش العلوي</label><input class="input" data-t="margin_top" inputmode="decimal"></div>
          <div class="field"><label>الهامش السفلي</label><input class="input" data-t="margin_bottom" inputmode="decimal"></div>
          <div class="field"><label>الهامش الأيمن</label><input class="input" data-t="margin_right" inputmode="decimal"></div>
          <div class="field"><label>الهامش الأيسر</label><input class="input" data-t="margin_left" inputmode="decimal"></div>
          <div class="field"><label>المسافة الأفقية</label><input class="input" data-t="gap_x" inputmode="decimal"></div>
          <div class="field"><label>المسافة الرأسية</label><input class="input" data-t="gap_y" inputmode="decimal"></div>
          <div class="full notice" style="margin:0"><?= icon('maximize-2') ?><div data-size-info></div></div>
          <div class="field full"><label>قالب افتراضي للباقة</label><select class="input" data-t="category_id"><option value="">—</option><?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
          <label class="check full"><input type="checkbox" data-t="is_default">القالب الافتراضي للطباعة</label>
        </div>
      </div>

      <div class="tab-panel panel-body" data-tab-panel="card">
        <div class="prop-grid">
          <div class="field"><label>لون الخلفية</label><input type="color" class="color-input" data-c="bg"></div>
          <div class="field"><label>زوايا الكرت (مم)</label><input class="input" data-c="radius" inputmode="decimal"></div>
          <div class="field full">
            <label>صورة الخلفية</label>
            <div class="flex gap-1">
              <input type="file" accept="image/png,image/jpeg,image/webp" class="input" data-bg-upload style="padding-top:5px">
              <button type="button" class="btn btn-sm" data-bg-remove><?= icon('x') ?></button>
            </div>
          </div>
          <label class="check"><input type="checkbox" data-c="border">إطار</label>
          <div class="field"><label>لون الإطار</label><input type="color" class="color-input" data-c="borderColor"></div>
          <label class="check full"><input type="checkbox" data-c="cutMarks">خطوط القص بين الكروت</label>
        </div>
      </div>
    </div>
  </div>
</div>
<script type="application/json" id="designer-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
