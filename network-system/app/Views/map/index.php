<?php
/** @var App\Core\View $v */
use App\Services\Geo;

$v->title = 'خريطة الشبكة';
$v->wide = true;
$v->script('vendor/leaflet/leaflet.js');
$v->script('js/geo.js');
$v->script('js/map.js');
$canManage = can('map.manage');
if ($canManage) {
    $v->actions = '<button type="button" class="btn" data-map-act="move" aria-pressed="false">' . icon('move') . 'تحريك</button>'
        . '<button type="button" class="btn" data-map-act="link" aria-pressed="false">' . icon('waypoints') . 'رابط جديد</button>'
        . '<button type="button" class="btn" data-map-act="router" aria-pressed="false">' . icon('router') . 'إضافة جهاز</button>'
        . '<button type="button" class="btn btn-primary" data-map-act="add" aria-pressed="false">' . icon('plus') . 'موقع جديد</button>';
}
$config = [
    'view' => $view,
    'savedView' => \App\Core\Settings::get('map.zoom', '') !== '',
    'dataUrl' => url('/map/data'),
    'positionUrl' => url('/map/position'),
    'viewUrl' => url('/map/view'),
    'sitesUrl' => url('/sites'),
    'linksUrl' => url('/sites/links'),
    'base' => base_path(),
    'canManage' => $canManage,
    'canSubs' => can('subscribers.view'),
    'siteTypes' => array_map(static fn ($t) => ['label' => $t[0], 'icon' => $t[1]], Geo::SITE_TYPES),
    'linkTypes' => Geo::LINK_TYPES,
    'routers' => $routers,
    'routerUrl' => url('/map/router'),
    'canRename' => can('routers.manage'),
];
?>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<?= Geo::clientConfig() ?>
<div class="map-shell">
  <div class="map-canvas panel" id="net-map" data-mode=""></div>
  <aside class="map-panel panel">
    <div class="panel-body stack" style="gap:14px">
      <div class="topbar-search" style="max-width:none">
        <?= icon('search') ?>
        <input type="search" data-map-search placeholder="بحث في الخريطة" aria-label="بحث في الخريطة" autocomplete="off">
      </div>
      <div class="map-layers">
        <label class="map-layer"><input type="checkbox" data-layer="sites" checked><span class="mk mk-site mk-sm"><?= icon('radio-tower') ?></span><span class="grow">المواقع</span><b data-count="sites">0</b></label>
        <label class="map-layer"><input type="checkbox" data-layer="routers" checked><span class="mk mk-router is-online mk-sm"><?= icon('router') ?></span><span class="grow">أجهزة MikroTik</span><b data-count="routers">0</b></label>
        <?php if (can('subscribers.view')): ?>
        <label class="map-layer"><input type="checkbox" data-layer="subscribers" checked><span class="mk-dot is-active"></span><span class="grow">المشتركون</span><b data-count="subscribers">0</b></label>
        <?php endif; ?>
        <label class="map-layer"><input type="checkbox" data-layer="links" checked><span class="map-line is-wireless"></span><span class="grow">الروابط</span><b data-count="links">0</b></label>
        <label class="map-layer"><input type="checkbox" data-map-labels checked><span class="map-label-sample"><?= icon('tag') ?></span><span class="grow">أسماء الأجهزة والمواقع</span></label>
      </div>
      <div class="map-legend">
        <div class="map-legend-title">أجهزة MikroTik</div>
        <div><span class="mk-dot is-active"></span>متصل</div>
        <div><span class="mk-dot is-expired"></span>غير متصل</div>
        <div><span class="mk-dot is-new"></span>غير معروف</div>
        <?php if (can('subscribers.view')): ?>
        <div class="map-legend-title">المشتركون</div>
        <div><span class="mk-dot is-active"></span>فعّال</div>
        <div><span class="mk-dot is-expiring"></span>ينتهي قريباً</div>
        <div><span class="mk-dot is-expired"></span>منتهٍ أو موقوف</div>
        <div><span class="mk-dot is-active is-online"></span>متصل الآن</div>
        <?php endif; ?>
        <div class="map-legend-title">الروابط</div>
        <?php foreach (Geo::LINK_TYPES as $k => $label): ?><div><span class="map-line is-<?= e($k) ?>"></span><?= e($label) ?></div><?php endforeach; ?>
      </div>
      <div class="notice notice-warning" data-unplaced hidden style="margin:0"><?= icon('map-pin') ?><div data-unplaced-text></div></div>
      <?php if ($canManage): ?>
      <button type="button" class="btn btn-sm" data-map-act="save-view"><?= icon('save') ?>حفظ هذا العرض كافتراضي</button>
      <?php endif; ?>
    </div>
  </aside>
</div>

<?php if ($canManage): ?>
<dialog class="modal" id="site-modal">
  <form data-site-form>
    <div class="modal-head"><h3><?= icon('radio-tower') ?><span data-site-title>موقع جديد</span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field col-8"><label for="sm-name">الاسم</label><input class="input" id="sm-name" name="name" required maxlength="120"></div>
        <div class="field col-4"><label for="sm-type">النوع</label>
          <select class="input" id="sm-type" name="type"><?php foreach (Geo::SITE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="sm-area">المنطقة</label>
          <select class="input" id="sm-area" name="area_id"><option value="">—</option><?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="sm-height">الارتفاع (م)</label><input class="input" id="sm-height" name="height_m" inputmode="numeric"></div>
        <div class="field col-6"><label for="sm-lat">Lat</label><input class="input input-ltr" id="sm-lat" name="lat" inputmode="decimal"></div>
        <div class="field col-6"><label for="sm-lng">Lng</label><input class="input input-ltr" id="sm-lng" name="lng" inputmode="decimal"></div>
        <div class="field col-12"><label for="sm-address">العنوان</label><input class="input" id="sm-address" name="address" maxlength="255"></div>
        <div class="field col-12"><label for="sm-notes">ملاحظات</label><input class="input" id="sm-notes" name="notes" maxlength="500"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost text-danger" data-site-delete hidden><?= icon('trash-2') ?>حذف</button>
      <span class="grow"></span>
      <button type="button" class="btn" data-modal-close>إلغاء</button>
      <button type="submit" class="btn btn-primary"><?= icon('check') ?>حفظ</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="router-modal">
  <form data-router-form>
    <div class="modal-head"><h3><?= icon('router') ?>إضافة جهاز MikroTik إلى الخريطة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <input type="hidden" name="lat"><input type="hidden" name="lng">
      <?php if ($routers): ?>
      <div class="form-grid">
        <div class="field col-12"><label for="rm-id">الجهاز</label>
          <select class="input" id="rm-id" name="id" required>
            <option value="">—</option>
            <?php foreach ($routers as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?><?= $r['placed'] ? ' · على الخريطة' : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field col-12"><label for="rm-name">الاسم على الخريطة</label>
          <input class="input" id="rm-name" name="name" maxlength="100" required<?= can('routers.manage') ? '' : ' readonly' ?>>
          <span class="hint"><?= can('routers.manage') ? 'يتغير اسم الجهاز في النظام كله.' : 'تعديل الاسم لمن يملك صلاحية إدارة أجهزة MikroTik.' ?></span>
        </div>
      </div>
      <?php else: ?>
      <div class="empty" style="padding:18px 0"><?= icon('router') ?><div>لا توجد أجهزة MikroTik في النظام بعد.</div></div>
      <?php endif; ?>
      <div class="map-coords"><?= icon('map-pin') ?><span class="mono" dir="ltr" data-router-coords>—</span></div>
    </div>
    <div class="modal-foot">
      <?php if (can('routers.manage')): ?><a class="btn btn-ghost" href="<?= e(url('/routers/create')) ?>" data-router-new><?= icon('plus') ?>جهاز جديد</a><?php endif; ?>
      <span class="grow"></span>
      <button type="button" class="btn" data-modal-close>إلغاء</button>
      <?php if ($routers): ?><button type="submit" class="btn btn-primary"><?= icon('check') ?>إضافة</button><?php endif; ?>
    </div>
  </form>
</dialog>

<dialog class="modal" id="link-modal">
  <form data-link-form>
    <div class="modal-head"><h3><?= icon('waypoints') ?>رابط بين موقعين</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label for="lm-a">من</label>
          <select class="input" id="lm-a" name="site_a" required><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="lm-b">إلى</label>
          <select class="input" id="lm-b" name="site_b" required><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="lm-type">النوع</label>
          <select class="input" id="lm-type" name="type"><?php foreach (Geo::LINK_TYPES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="lm-cap">السعة</label><input class="input input-ltr" id="lm-cap" name="capacity" maxlength="40" placeholder="300 Mbps"></div>
        <div class="field col-12"><label for="lm-label">الوصف</label><input class="input" id="lm-label" name="label" maxlength="120"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn" data-modal-close>إلغاء</button>
      <button type="submit" class="btn btn-primary"><?= icon('check') ?>حفظ</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<script type="application/json" id="map-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
