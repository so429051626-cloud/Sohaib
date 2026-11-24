<?php
/** @var App\Core\View $v */
use App\Services\CardRules;
use App\Services\HotspotSdk;

$v->title = 'ربط الهوتسبوت';
if (can('hotspot.log')) {
    $v->actions = '<a class="btn" href="' . e(url('/hotspot/log')) . '">' . icon('list') . 'سجل الدخول</a>';
}
$sdkUrl = $panel !== '' ? rtrim($panel, '/') . '/hotspot/sdk/' . HotspotSdk::SDK_FILE : '';

$loginSnippet = <<<'HTML'
<!-- login.html — مكان إعلانات الصفحة (اختياري، تُدار من «إعلانات الصفحة») -->
<div id="sn-ads"></div>

<!-- login.html — قبل </body> -->
<script>
  window.SN_MT = {
    linkLogin: '$(link-login-only)',
    linkOrig: '$(link-orig)',
    chapId: '$(chap-id)',
    chapChallenge: '$(chap-challenge)',
    mac: '$(mac)',
    ip: '$(ip)',
    error: '$(error)'
  };
</script>
<script src="sn-config.js"></script>
<script src="sohaibnet.js"></script>
<script>
  // form: نموذج الدخول في صفحتك، code: حقل رقم الكرت، password: حقل كلمة المرور (اختياري)
  document.getElementById('form').onsubmit = function (e) {
    e.preventDefault();
    SNHotspot.login({
      code: document.getElementById('code').value,
      password: document.getElementById('password') ? document.getElementById('password').value : '',
      onLoading: function (busy) { /* إظهار/إخفاء مؤشر التحميل */ },
      onError: function (err) {
        // err.code: رمز السبب، err.message: رسالة جاهزة بالعربية، err.needPassword: الكرت يتطلب كلمة مرور
        document.getElementById('message').textContent = err.message;
      }
    });
  };
</script>
HTML;

$statusSnippet = <<<'HTML'
<!-- status.html — قبل </body> -->
<script>
  window.SN_MT = { username: '$(username)', mac: '$(mac)' };
</script>
<script src="sn-config.js"></script>
<script src="sohaibnet.js"></script>
<script>
  SNHotspot.status({
    onResult: function (res) {
      if (!res.ok) { return; }
      var i = res.info; // كرت: category, expires_at, remaining_time, remaining_data, used_time, used_data, speed
                        // مشترك: name, plan, expires_at, remaining_data
      if (i.remaining_time != null) { document.getElementById('time-left').textContent = SNHotspot.formatDuration(i.remaining_time); }
      if (i.remaining_data != null) { document.getElementById('data-left').textContent = SNHotspot.formatBytes(i.remaining_data); }
    }
  });
</script>
HTML;

$infoSnippet = <<<'HTML'
<!-- قائمة الأسعار من النظام (اختياري) -->
<script>
  SNHotspot.info(function (res) {
    if (!res.ok) { return; }
    // res.network, res.phone, res.whatsapp, res.currency
    // res.categories[]: name, price, price_text, time, validity, data, speed, color
    var html = '';
    for (var k = 0; k < res.categories.length; k++) {
      var c = res.categories[k];
      html += '<li>' + c.name + ' — ' + c.price_text + '</li>';
    }
    document.getElementById('prices').innerHTML = html;
  });
</script>
HTML;
?>
<?php if ($panel === '' || !preg_match('#^https?://#', $panel)): ?>
  <div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?><div>الرابط العام للنظام غير مضبوط. <?php if (can('settings.manage')): ?><a href="<?= e(url('/settings#hotspot')) ?>">الإعدادات</a><?php endif; ?></div></div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('log-in') ?></div><div class="stat-body"><div class="stat-label">محاولات الدخول (24 ساعة)</div><div class="stat-value"><?= num($stats['total']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('circle-check') ?></div><div class="stat-body"><div class="stat-label">مقبولة</div><div class="stat-value"><?= num($stats['accepted']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('circle-x') ?></div><div class="stat-body"><div class="stat-label">مرفوضة</div><div class="stat-value"><?= num($stats['rejected']) ?></div>
    <?php if ($reasons): ?><div class="stat-meta"><?php foreach (array_slice($reasons, 0, 2) as $i => $r): ?><?= $i ? ' · ' : '' ?><?= e(CardRules::message((string) $r['reason'])) ?> (<?= num($r['n']) ?>)<?php endforeach; ?></div><?php endif; ?>
  </div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('smartphone') ?></div><div class="stat-body"><div class="stat-label">الأجهزة</div><div class="stat-value"><?= num($stats['devices']) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <form class="panel" method="post" action="<?= e(url('/hotspot/deploy')) ?>" data-bulk-scope>
      <?= csrf_field() ?>
      <div class="panel-head">
        <h2><?= icon('router') ?>أجهزة MikroTik</h2>
        <div class="panel-tools">
          <button class="btn btn-primary btn-sm"><?= icon('cloud-upload') ?>نشر الملفات</button>
        </div>
      </div>
      <?php if (!$routers): ?>
        <div class="empty"><?= icon('router') ?><div class="empty-title">لا توجد أجهزة MikroTik</div></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th class="w-check"><input type="checkbox" data-select-all title="تحديد الكل"></th><th>جهاز MikroTik</th><th>مجلد الصفحات</th><th>آخر نشر</th><th>مفتاح الربط</th><th class="w-actions"></th></tr></thead>
          <tbody>
          <?php foreach ($routers as $r): ?>
            <tr<?= $r['is_active'] ? '' : ' class="is-muted"' ?>>
              <td class="w-check"><input type="checkbox" name="routers[]" value="<?= (int) $r['id'] ?>" data-row-check<?= $r['is_active'] ? '' : ' disabled' ?>></td>
              <td>
                <div class="flex items-center gap-2">
                  <span class="status-dot <?= $r['status'] === 'online' ? 'is-online' : ($r['status'] === 'offline' ? 'is-offline' : 'is-unknown') ?>"></span>
                  <a class="cell-title" href="<?= e(url('/routers/' . $r['id'], ['tab' => 'setup'])) ?>"><?= e($r['name']) ?></a>
                </div>
              </td>
              <td><span class="mono text-sm"><?= e($r['hotspot_dir'] ?: '—') ?></span></td>
              <td class="nowrap text-sm"><?= $r['deployed_at'] ? e(time_ago($r['deployed_at'])) : '<span class="badge badge-warning">لم تُنشر</span>' ?></td>
              <td>
                <span class="mono text-sm" id="key-<?= (int) $r['id'] ?>"><?= e(substr($r['router_key'], 0, 8)) ?>…</span>
                <button type="button" class="btn btn-ghost btn-icon btn-xs" data-copy="<?= e($r['router_key']) ?>" title="نسخ المفتاح"><?= icon('copy') ?></button>
              </td>
              <td class="w-actions">
                <div class="row-actions">
                  <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/hotspot/download/' . $r['id'])) ?>" title="تنزيل الملفات"><?= icon('download') ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </form>

    <div class="panel">
      <div class="panel-head"><h2><?= icon('code-xml') ?>كود الربط</h2></div>
      <nav class="tabs" data-tabs>
        <button type="button" class="tab is-active" data-tab="login">login.html</button>
        <button type="button" class="tab" data-tab="status">status.html</button>
        <button type="button" class="tab" data-tab="info">الأسعار</button>
      </nav>
      <div class="tab-panel is-active panel-body" data-tab-panel="login">
        <div class="copyable"><pre class="code-block" dir="ltr" id="snip-login"><?= e($loginSnippet) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#snip-login"><?= icon('copy') ?>نسخ</button></div>
      </div>
      <div class="tab-panel panel-body" data-tab-panel="status">
        <div class="copyable"><pre class="code-block" dir="ltr" id="snip-status"><?= e($statusSnippet) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#snip-status"><?= icon('copy') ?>نسخ</button></div>
      </div>
      <div class="tab-panel panel-body" data-tab-panel="info">
        <div class="copyable"><pre class="code-block" dir="ltr" id="snip-info"><?= e($infoSnippet) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#snip-info"><?= icon('copy') ?>نسخ</button></div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('info') ?>الإعداد</h3></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>رابط النظام</dt><dd><span class="mono text-sm"><?= e($panel ?: '—') ?></span></dd>
          <dt>ملف المكتبة</dt><dd><?php if ($sdkUrl): ?><a class="mono text-sm" href="<?= e($sdkUrl) ?>" target="_blank" rel="noopener"><?= e(HotspotSdk::SDK_FILE) ?></a><?php else: ?><span class="mono text-sm"><?= e(HotspotSdk::SDK_FILE) ?></span><?php endif; ?></dd>
          <dt>تحديث المكتبة</dt><dd><?= e($sdkVersion) ?></dd>
          <dt>مهلة الاتصال</dt><dd><?= num(max(2, (int) \App\Core\Settings::get('hotspot.timeout', 8))) ?> ثانية</dd>
          <dt>عند تعذّر الوصول</dt><dd><?= \App\Core\Settings::bool('hotspot.fallback') ? 'دخول مباشر عبر جهاز MikroTik' : 'رفض الدخول' ?></dd>
          <dt>مستخدمو MikroTik المحليون</dt><dd><?= \App\Core\Settings::bool('hotspot.local_users') ? 'يدخلون مباشرة عبر الجهاز' : 'مرفوضون' ?></dd>
        </dl>
        <?php if (can('settings.manage')): ?><a class="btn btn-block mt-2" href="<?= e(url('/settings#hotspot')) ?>"><?= icon('settings') ?>إعدادات الهوتسبوت</a><?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('file-code-2') ?>دوال المكتبة</h3></div>
      <div class="table-wrap">
        <table class="table table-compact" dir="ltr">
          <tbody>
            <tr><td class="mono text-sm">SNHotspot.login(options)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.status(options)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.info(callback)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.renderAds('#sn-ads')</td></tr>
            <tr><td class="mono text-sm">SNHotspot.ads(callback)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.submitLogin(user, pass)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.normalize(code)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.formatBytes(bytes)</td></tr>
            <tr><td class="mono text-sm">SNHotspot.formatDuration(seconds)</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
