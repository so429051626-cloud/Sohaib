<?php
/** @var App\Core\View $v */
$isEdit = $router !== null;
$v->title = $isEdit ? 'تعديل جهاز MikroTik' : 'إضافة جهاز MikroTik';
$v->breadcrumbs = [['أجهزة MikroTik', '/routers'], [$v->title, null]];
$req = App\Core\Request::current();
$val = static fn (string $k, $d = '') => old($k, $router[$k] ?? (!$isEdit && $req && in_array($k, ['host', 'name', 'lat', 'lng'], true) && $req->str($k) !== '' ? $req->str($k) : $d));
$tunnels = can('routers.manage') && App\Services\Tunnel::available() ? App\Services\Tunnel::all() : [];
?>
<form method="post" action="<?= e(url($isEdit ? '/routers/' . $router['id'] : '/routers')) ?>" autocomplete="off" id="router-form">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="router_id" value="<?= (int) $router['id'] ?>"><?php endif; ?>
  <div class="grid grid-main-side">
    <div class="panel">
      <div class="panel-head"><h2><?= icon('router') ?>بيانات جهاز MikroTik</h2></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-6">
            <label for="name">اسم جهاز MikroTik <span class="req">*</span></label>
            <input class="input" id="name" name="name" value="<?= e($val('name')) ?>" required>
            <?= error_html('name') ?>
          </div>
          <div class="field col-6">
            <label for="area_id">المنطقة</label>
            <select class="input" id="area_id" name="area_id">
              <option value="">—</option>
              <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($val('area_id'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?= $v->partial('location', ['sites' => $sites, 'siteId' => $val('site_id'), 'lat' => $val('lat'), 'lng' => $val('lng'), 'withSite' => true]) ?>

          <div class="form-section-title">الاتصال عبر API</div>
          <div class="field col-8">
            <label for="host">العنوان (IP عام أو نطاق) <span class="req">*</span></label>
            <input class="input input-ltr" id="host" name="host" value="<?= e($val('host')) ?>" required placeholder="203.0.113.10">
            <?= error_html('host') ?>
            <?php if ($tunnels): ?>
            <div class="tunnel-pick">
              <?= icon('waypoints', 'icon-sm') ?>
              <select class="input input-sm" data-tunnel-pick aria-label="نفق آمن">
                <option value="">نفق آمن…</option>
                <?php foreach ($tunnels as $t): ?><option value="<?= e($t['address']) ?>"<?= selected($val('host'), $t['address']) ?>><?= e($t['name']) ?> · <?= e($t['address']) ?></option><?php endforeach; ?>
              </select>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('/tunnels')) ?>">الأنفاق</a>
            </div>
            <?php elseif (can('routers.manage') && App\Services\Tunnel::available()): ?>
            <div class="tunnel-pick"><?= icon('waypoints', 'icon-sm') ?><a href="<?= e(url('/tunnels')) ?>">نفق آمن</a></div>
            <?php endif; ?>
          </div>
          <div class="field col-4">
            <label for="api_port">المنفذ <span class="req">*</span></label>
            <input class="input input-ltr" id="api_port" name="api_port" value="<?= e($val('api_port', '8728')) ?>" inputmode="numeric" required>
            <?= error_html('api_port') ?>
          </div>
          <div class="field col-6">
            <label for="api_username">مستخدم API <span class="req">*</span></label>
            <input class="input input-ltr" id="api_username" name="api_username" value="<?= e($val('api_username', 'sn-api')) ?>" required>
            <?= error_html('api_username') ?>
          </div>
          <div class="field col-6">
            <label for="api_password">كلمة مرور API <?= $isEdit ? '' : '<span class="req">*</span>' ?></label>
            <div class="password-field">
              <input class="input input-ltr" id="api_password" name="api_password" type="password" autocomplete="new-password" <?= $isEdit ? 'placeholder="اتركها فارغة للإبقاء على الحالية"' : 'required' ?>>
              <button type="button" class="btn btn-ghost btn-icon btn-sm toggle-pass" aria-label="إظهار"><?= icon('eye') ?></button>
            </div>
            <?= error_html('api_password') ?>
          </div>
          <div class="field col-12">
            <label class="switch"><input type="checkbox" name="api_ssl" value="1"<?= checked($val('api_ssl', 0)) ?> data-ssl><span class="track"></span><span>اتصال مشفّر (API-SSL)</span></label>
          </div>

          <div class="form-section-title">الإعدادات</div>
          <div class="field col-6">
            <label for="wan_interface">واجهة الإنترنت (WAN)</label>
            <input class="input input-ltr" id="wan_interface" name="wan_interface" value="<?= e($val('wan_interface')) ?>" list="iface-list" placeholder="ether1">
            <datalist id="iface-list"></datalist>
          </div>
          <div class="field col-6" style="justify-content:flex-end">
            <label class="switch"><input type="checkbox" name="is_active" value="1"<?= checked($val('is_active', 1)) ?>><span class="track"></span><span>مفعّل في النظام</span></label>
          </div>
          <div class="field col-12">
            <label for="notes">ملاحظات</label>
            <textarea class="input" id="notes" name="notes" rows="3"><?= e($val('notes')) ?></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <button type="button" class="btn" data-test-connection><?= icon('plug') ?>اختبار الاتصال</button>
          <a class="btn btn-ghost" href="<?= e(url($isEdit ? '/routers/' . $router['id'] : '/routers')) ?>">إلغاء</a>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('activity') ?>نتيجة اختبار الاتصال</h3></div>
        <div class="panel-body" id="test-result"><div class="text-muted text-sm">—</div></div>
      </div>
      <?php if ($isEdit && can('routers.manage')): ?>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('trash-2') ?>حذف جهاز MikroTik</h3></div>
        <div class="panel-body">
          <button type="submit" form="delete-router" class="btn btn-soft-danger"><?= icon('trash-2') ?>حذف</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php if ($isEdit): ?>
<form id="delete-router" method="post" action="<?= e(url('/routers/' . $router['id'] . '/delete')) ?>" data-confirm="حذف جهاز MikroTik <?= e($router['name']) ?> من النظام؟"><?= csrf_field() ?></form>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('router-form');
  var ssl = form.querySelector('[data-ssl]');
  var pick = form.querySelector('[data-tunnel-pick]');
  if (pick) { pick.addEventListener('change', function () { if (pick.value) { form.querySelector('#host').value = pick.value; } }); }
  var port = form.querySelector('#api_port');
  ssl.addEventListener('change', function () {
    if (ssl.checked && port.value === '8728') { port.value = '8729'; }
    if (!ssl.checked && port.value === '8729') { port.value = '8728'; }
  });
  form.querySelector('[data-test-connection]').addEventListener('click', function () {
    var btn = this, box = document.getElementById('test-result');
    btn.classList.add('is-loading');
    box.innerHTML = '<div class="loading-block"><span class="spinner"></span>جارٍ الاتصال…</div>';
    SN.api(SN.base + '/routers/test', { method: 'POST', form: new FormData(form), silent: true }).then(function (r) {
      var rows = [['الحالة', '<span class="badge badge-success badge-dot">' + SN.esc(r.message) + '</span>'], ['الهوية', SN.esc(r.identity)], ['الإصدار', '<span class="mono">' + SN.esc(r.version) + '</span>'],
        ['اللوحة', SN.esc(r.board || '—')], ['مدة التشغيل', SN.esc(r.uptime)], ['زمن الاستجابة', r.latency + ' ms']];
      box.innerHTML = '<dl class="kv">' + rows.map(function (x) { return '<dt>' + x[0] + '</dt><dd>' + x[1] + '</dd>'; }).join('') + '</dl>';
      var dl = document.getElementById('iface-list');
      dl.innerHTML = (r.interfaces || []).map(function (i) { return '<option value="' + SN.esc(i.name) + '">' + SN.esc(i.type) + '</option>'; }).join('');
    }).catch(function (e) {
      box.innerHTML = '<div class="notice notice-danger">' + SN.icon('circle-x') + '<div>' + SN.esc(e.message) + '</div></div>';
    }).then(function () { btn.classList.remove('is-loading'); });
  });
});
</script>
