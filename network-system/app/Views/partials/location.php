<?php
/**
 * حقول الموقع: البرج/الموقع + الإحداثيات + اختيار من الخريطة.
 * @var App\Core\View $v
 * @var array $sites [id => name]
 * @var mixed $siteId
 * @var mixed $lat
 * @var mixed $lng
 * @var bool $withSite
 */
use App\Services\Geo;

$v->script('vendor/leaflet/leaflet.js');
$v->script('js/geo.js');
[$dLat, $dLng, $dZoom] = Geo::view();
$fmt = static fn ($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 7, '.', ''), '0'), '.');
?>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<?= Geo::clientConfig() ?>
<?php if ($withSite ?? true): ?>
<div class="field col-6">
  <label for="site_id">البرج / الموقع</label>
  <select class="input" id="site_id" name="site_id">
    <option value="">—</option>
    <?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) $siteId, (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
  </select>
</div>
<?php endif; ?>
<div class="field <?= ($withSite ?? true) ? 'col-6' : 'col-12' ?>" data-location data-default="<?= e(json_encode([$dLat, $dLng, $dZoom])) ?>">
  <label>الإحداثيات</label>
  <div class="input-group">
    <input class="input input-ltr" name="lat" value="<?= e($fmt($lat)) ?>" placeholder="Lat" inputmode="decimal" data-lat>
    <input class="input input-ltr" name="lng" value="<?= e($fmt($lng)) ?>" placeholder="Lng" inputmode="decimal" data-lng>
    <button type="button" class="btn" data-pick title="تحديد على الخريطة"><?= icon('map-pin') ?></button>
  </div>
  <?= error_html('lat') ?>
</div>
