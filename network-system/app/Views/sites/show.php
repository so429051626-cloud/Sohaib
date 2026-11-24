<?php
/** @var App\Core\View $v */
use App\Services\Geo;
use App\Services\SubscriberRules;

[$typeLabel, $typeIcon] = Geo::SITE_TYPES[$site['type']] ?? [$site['type'], 'map-pin'];
$v->title = $site['name'];
$v->breadcrumbs = [['الأبراج والمواقع', '/sites'], [$site['name'], null]];
$actions = '<a class="btn" href="' . e(url('/map')) . '">' . icon('map') . 'الخريطة</a>';
if (can('map.manage')) {
    $actions .= '<button type="button" class="btn" data-modal-open="link-create">' . icon('waypoints') . 'رابط جديد</button>'
        . '<button type="button" class="btn btn-primary" data-modal-open="site-edit">' . icon('pencil') . 'تعديل</button>';
}
$v->actions = $actions;
$routerStatus = ['online' => ['متصل', 'badge-success'], 'offline' => ['غير متصل', 'badge-danger'], 'unknown' => ['غير معروف', 'badge-outline']];
?>
<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon($typeIcon) ?></div><div class="stat-body"><div class="stat-label">النوع</div><div class="stat-value" style="font-size:18px"><?= e($typeLabel) ?></div><div class="stat-meta"><?= e(implode(' · ', array_filter([$site['area_name'] ?? '', $site['height_m'] ? (int) $site['height_m'] . ' م' : '']))) ?: '&nbsp;' ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('router') ?></div><div class="stat-body"><div class="stat-label">أجهزة MikroTik</div><div class="stat-value"><?= num(count($routers)) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">المشتركون</div><div class="stat-value"><?= num(count($subscribers)) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('hard-drive') ?></div><div class="stat-body"><div class="stat-label">الأجهزة المركّبة</div><div class="stat-value"><?= num(count($units)) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik</h3></div>
      <?php if (!$routers): ?><div class="empty"><?= icon('router') ?><div>لا توجد أجهزة MikroTik في هذا الموقع.</div></div><?php else: ?>
      <ul class="list">
        <?php foreach ($routers as $r): [$stL, $stC] = $routerStatus[$r['status']] ?? $routerStatus['unknown']; ?>
          <li class="list-item">
            <span class="list-icon <?= $r['status'] === 'online' ? 'is-success' : ($r['status'] === 'offline' ? 'is-danger' : '') ?>"><?= icon('router') ?></span>
            <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a><div class="list-item-sub mono"><?= e($r['host']) ?></div></div>
            <div class="list-item-end"><span class="badge <?= $stC ?>"><?= $stL ?></span><div><?= num((int) $r['active_hotspot'] + (int) $r['active_ppp']) ?> متصل</div></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <?php if (can('subscribers.view')): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('users') ?>المشتركون</h3><span class="badge"><?= num(count($subscribers)) ?></span></div>
      <?php if (!$subscribers): ?><div class="empty"><?= icon('users') ?><div>لا يوجد مشتركون مرتبطون بهذا الموقع.</div></div><?php else: ?>
      <div class="table-wrap" style="max-height:420px">
        <table class="table table-compact">
          <thead><tr><th>المشترك</th><th>الهاتف</th><th>الباقة</th><th>الحالة</th></tr></thead>
          <tbody>
          <?php foreach ($subscribers as $s): ?>
            <tr><td><a href="<?= e(url('/subscribers/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a></td><td class="mono"><?= e($s['phone'] ?? '—') ?></td><td><?= e($s['plan_name']) ?></td><td><?= SubscriberRules::badge($s) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (can('inventory.view')): ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('hard-drive') ?>الأجهزة المركّبة</h3></div>
      <?php if (!$units): ?><div class="empty"><?= icon('hard-drive') ?><div>لا توجد أجهزة مركّبة.</div></div><?php else: ?>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>الجهاز</th><th>الرقم التسلسلي</th><th>MAC</th><th>التركيب</th></tr></thead>
          <tbody>
          <?php foreach ($units as $u): ?>
            <tr><td><a href="<?= e(url('/inventory/units/' . $u['id'])) ?>"><?= e($u['item_name']) ?></a></td><td class="mono"><?= e($u['serial'] ?? '—') ?></td><td class="mono"><?= e($u['mac'] ?? '—') ?></td><td class="nowrap text-sm"><?= e(fmt_date($u['installed_at'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>البيانات</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>الإحداثيات</dt><dd><?= $site['lat'] !== null ? '<span class="mono">' . e(round((float) $site['lat'], 6) . ', ' . round((float) $site['lng'], 6)) . '</span>' : '—' ?></dd>
          <dt>العنوان</dt><dd><?= e($site['address'] ?: '—') ?></dd>
          <?php if ($site['notes']): ?><dt>ملاحظات</dt><dd><?= e($site['notes']) ?></dd><?php endif; ?>
          <dt>تاريخ الإضافة</dt><dd><?= e(fmt_date($site['created_at'])) ?></dd>
        </dl>
        <?php if ($site['lat'] !== null): ?>
          <a class="btn btn-block mt-2" target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= e((float) $site['lat'] . ',' . (float) $site['lng']) ?>"><?= icon('external-link') ?>فتح في خرائط Google</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('waypoints') ?>الروابط</h3></div>
      <?php if (!$links): ?><div class="empty"><?= icon('waypoints') ?><div>لا توجد روابط.</div></div><?php else: ?>
      <ul class="list">
        <?php foreach ($links as $l): ?>
          <li class="list-item">
            <span class="map-line is-<?= e($l['type']) ?>"></span>
            <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/sites/' . $l['other_id'])) ?>"><?= e($l['other_name']) ?></a>
              <div class="list-item-sub"><?= e(Geo::LINK_TYPES[$l['type']] ?? $l['type']) ?><?= $l['capacity'] ? ' · <span dir="ltr">' . e($l['capacity']) . '</span>' : '' ?><?= $l['label'] ? ' · ' . e($l['label']) : '' ?></div></div>
            <?php if (can('map.manage')): ?>
            <form method="post" action="<?= e(url('/sites/links/' . $l['id'] . '/delete')) ?>" data-confirm="حذف الرابط مع <?= e($l['other_name']) ?>؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php if (can('map.manage')): ?>
    <form method="post" action="<?= e(url('/sites/' . $site['id'] . '/delete')) ?>" data-confirm="حذف الموقع «<?= e($site['name']) ?>»؟ تبقى أجهزة MikroTik والمشتركون، وتُحذف الروابط.">
      <?= csrf_field() ?><button class="btn btn-ghost text-danger btn-block"><?= icon('trash-2') ?>حذف الموقع</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (can('map.manage')): ?>
<dialog class="modal modal-lg" id="site-edit">
  <form method="post" action="<?= e(url('/sites/' . $site['id'])) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('pencil') ?>تعديل الموقع</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-8"><label for="se-name">الاسم <span class="req">*</span></label><input class="input" id="se-name" name="name" value="<?= e(old('name', $site['name'])) ?>" required maxlength="120"></div>
        <div class="field col-4"><label for="se-type">النوع</label>
          <select class="input" id="se-type" name="type"><?php foreach (Geo::SITE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>"<?= selected(old('type', $site['type']), $k) ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="se-area">المنطقة</label>
          <select class="input" id="se-area" name="area_id"><option value="">—</option><?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) old('area_id', $site['area_id']), (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="se-height">الارتفاع (م)</label><input class="input" id="se-height" name="height_m" value="<?= e(old('height_m', $site['height_m'])) ?>" inputmode="numeric"></div>
        <?= $v->partial('location', ['sites' => [], 'siteId' => null, 'lat' => old('lat', $site['lat']), 'lng' => old('lng', $site['lng']), 'withSite' => false]) ?>
        <div class="field col-12"><label for="se-address">العنوان</label><input class="input" id="se-address" name="address" value="<?= e(old('address', $site['address'])) ?>" maxlength="255"></div>
        <div class="field col-12"><label for="se-notes">ملاحظات</label><input class="input" id="se-notes" name="notes" value="<?= e(old('notes', $site['notes'])) ?>" maxlength="500"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<dialog class="modal" id="link-create">
  <form method="post" action="<?= e(url('/sites/links')) ?>">
    <?= csrf_field() ?><input type="hidden" name="site_a" value="<?= (int) $site['id'] ?>">
    <div class="modal-head"><h3><?= icon('waypoints') ?>رابط جديد</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-12"><label for="lc-b">إلى</label>
          <select class="input" id="lc-b" name="site_b" required><option value="">—</option><?php foreach ($sites as $id => $name): if ((int) $id === (int) $site['id']) { continue; } ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="lc-type">النوع</label>
          <select class="input" id="lc-type" name="type"><?php foreach (Geo::LINK_TYPES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label for="lc-cap">السعة</label><input class="input input-ltr" id="lc-cap" name="capacity" maxlength="40"></div>
        <div class="field col-12"><label for="lc-label">الوصف</label><input class="input" id="lc-label" name="label" maxlength="120"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
