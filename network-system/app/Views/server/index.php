<?php
/**
 * @var App\Core\View $v @var array $data @var string $base @var array $accounts @var int $dbTotal @var array $config
 */
$v->title = 'الخادم';
$v->script('js/telegram-tools.js');
$v->actions = '<button type="button" class="btn btn-primary" data-srv-backup>' . icon('database') . 'نسخة احتياطية الآن</button>';
$services = ['web' => ['خادم الويب', 'globe'], 'db' => ['قاعدة البيانات', 'database'], 'cron' => ['المهام المجدولة', 'calendar-clock'],
    'firewall' => ['الجدار الناري', 'shield-check'], 'guard' => ['الحماية من التخمين', 'shield-ban'], 'tunnel' => ['نفق أجهزة MikroTik', 'waypoints']];
$ring = static fn (string $key, string $label, string $ic) => '<div class="gauge" data-gauge="' . $key . '"><div class="gauge-ring"><svg viewBox="0 0 84 84"><circle class="track" cx="42" cy="42" r="34"/><circle class="bar" cx="42" cy="42" r="34" stroke-dasharray="213.63" stroke-dashoffset="213.63"/></svg><b>—</b></div>'
    . '<div class="gauge-body"><div class="gauge-label">' . icon($ic, 'icon-sm') . $label . '</div><div class="gauge-value" data-g-value>—</div><div class="gauge-sub" data-g-sub></div></div></div>';
?>
<div class="srv-hero" data-srv-hero>
  <div class="srv-pulse"><?= icon('server') ?></div>
  <div class="srv-hero-main">
    <div class="srv-hero-title" data-srv-title>—</div>
    <div class="srv-hero-sub mono" data-srv-host></div>
  </div>
  <div class="srv-hero-end"><span class="status-dot" data-srv-dot></span><span data-srv-age>—</span></div>
</div>

<div class="srv-gauges">
  <?= $ring('cpu', 'المعالج', 'cpu') ?>
  <?= $ring('mem', 'الذاكرة', 'memory-stick') ?>
  <?= $ring('disk', 'القرص', 'hard-drive') ?>
  <div class="gauge"><div class="gauge-ring gauge-icon"><?= icon('clock') ?></div>
    <div class="gauge-body"><div class="gauge-label">مدة التشغيل</div><div class="gauge-value" data-srv-uptime>—</div><div class="gauge-sub" data-srv-load></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>الخدمات</h3></div>
      <div class="panel-body"><div class="srv-services">
        <?php foreach ($services as $key => [$label, $ic]): ?>
          <div class="srv-service" data-service="<?= $key ?>"><?= icon($ic) ?><span><?= $label ?></span><span class="status-dot"></span></div>
        <?php endforeach; ?>
      </div></div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('archive') ?>النسخ الاحتياطي</h3><span class="badge" data-srv-backup-count>0</span></div>
      <div class="panel-body">
        <dl class="kv kv-2">
          <dt>آخر نسخة</dt><dd data-srv-backup-last>—</dd>
          <dt>الحجم الكلي</dt><dd data-srv-backup-size>—</dd>
          <dt>التكرار</dt><dd>كل ليلة 03:17</dd>
          <dt>النسخ الخارجي</dt><dd data-srv-offsite>—</dd>
        </dl>
      </div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>النسخة</th><th>قواعد البيانات</th><th>الحجم</th></tr></thead>
        <tbody data-srv-backups></tbody>
      </table></div>
    </div>

    <?php if ($accounts): $max = max(1, (int) $accounts[0]['size']); ?>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('database') ?>بيانات الحسابات</h3><span class="badge"><?= e(fmt_bytes($dbTotal)) ?></span></div>
      <div class="panel-body stack" style="gap:12px">
        <?php foreach ($accounts as $a): ?>
          <div>
            <div class="meter-row"><a href="<?= e(url('/admin/tenants/' . $a['id'])) ?>"><strong><?= e($a['name']) ?></strong> <span class="tenant-link">/<?= e($a['slug']) ?></span></a><span><?= e(fmt_bytes($a['size'])) ?></span></div>
            <div class="meter"><span style="width:<?= (int) round(100 * $a['size'] / $max) ?>%"></span></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('server-cog') ?>النظام</h3></div>
      <div class="panel-body"><dl class="kv" data-srv-system></dl></div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('bell') ?>التنبيهات</h3></div>
      <ul class="list" data-srv-alerts></ul>
    </div>

    <form method="post" action="<?= e(url($base . '/settings')) ?>" class="panel" autocomplete="off">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('bot') ?>تيليجرام</h3></div>
      <div class="panel-body stack" style="gap:12px">
        <div class="field">
          <label for="tg_token">رمز البوت</label>
          <input class="input input-ltr" id="tg_token" name="tg_token" type="password" autocomplete="new-password" placeholder="<?= $config['token_set'] ? '••••••••  محفوظ' : '123456:ABC…' ?>">
          <?= error_html('tg_token') ?>
        </div>
        <div class="field">
          <label for="tg_chat">معرّف المحادثة</label>
          <div class="field-row"><input class="input input-ltr" id="tg_chat" name="tg_chat" value="<?= e(old('tg_chat', $config['chat'])) ?>" placeholder="123456789"><button type="button" class="btn" data-tg-detect="<?= e(url($base . '/detect')) ?>" data-tg-token="#tg_token" data-tg-chat="#tg_chat"><?= icon('search') ?>اكتشاف</button></div>
          <div class="tg-found" data-tg-found="#tg_chat"></div>
          <?= error_html('tg_chat') ?>
        </div>
        <label class="switch"><input type="checkbox" name="alerts" value="1"<?= checked($config['alerts']) ?>><span class="track"></span><span>تنبيهات الخادم</span></label>
        <label class="switch"><input type="checkbox" name="offsite" value="1"<?= checked($config['offsite']) ?>><span class="track"></span><span>نسخة احتياطية مشفّرة كل ليلة</span></label>
        <div class="field">
          <label for="offsite_pass">كلمة مرور النسخ</label>
          <input class="input input-ltr" id="offsite_pass" name="offsite_pass" type="password" autocomplete="new-password" minlength="8" placeholder="<?= $config['pass_set'] ? '••••••••  محفوظة' : '' ?>">
          <?= error_html('offsite_pass') ?>
        </div>
        <div class="flex gap-2 wrap">
          <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <button type="button" class="btn" data-tg-test="<?= e(url($base . '/test')) ?>" data-tg-token="#tg_token" data-tg-chat="#tg_chat"><?= icon('send') ?>رسالة تجريبية</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="srv-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var base = SN.base + <?= json_encode($base) ?>;
  var data = JSON.parse(document.getElementById('srv-data').textContent);
  var skew = Math.round(Date.now() / 1000) - data.now;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var C = 213.63;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function stamp(t) { if (!t) { return '—'; } var d = new Date(t * 1000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function ago(t) {
    if (!t) { return '—'; }
    var s = Math.max(0, Math.round(Date.now() / 1000) - skew - t);
    if (s < 60) { return 'قبل ' + s + ' ث'; }
    if (s < 3600) { return 'قبل ' + Math.round(s / 60) + ' د'; }
    if (s < 86400) { return 'قبل ' + Math.round(s / 3600) + ' س'; }
    return 'قبل ' + Math.round(s / 86400) + ' يوم';
  }
  function span(t) {
    var d = Math.floor(t / 86400), h = Math.floor((t % 86400) / 3600), m = Math.floor((t % 3600) / 60);
    return d > 0 ? d + ' يوم ' + h + ' س' : (h > 0 ? h + ' س ' + m + ' د' : m + ' د');
  }
  function ltr(s) { return '\u2066' + s + '\u2069'; }
  function gb(mb) { return ltr(mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB'); }
  function pair(used, total) {
    var big = total >= 1024, f = function (v) { return big ? (v / 1024).toFixed(1) : Math.round(v); };
    return ltr(f(used || 0) + ' / ' + f(total || 0) + (big ? ' GB' : ' MB'));
  }
  function gauge(key, pct, value, sub) {
    var g = $('[data-gauge="' + key + '"]');
    pct = Math.max(0, Math.min(100, Math.round(pct || 0)));
    $('.bar', g).style.strokeDashoffset = (C * (1 - pct / 100)).toFixed(2);
    $('b', g).textContent = pct + '%';
    g.classList.remove('is-success', 'is-warning', 'is-danger');
    g.classList.add(pct >= 90 ? 'is-danger' : (pct >= 75 ? 'is-warning' : 'is-success'));
    $('[data-g-value]', g).textContent = value;
    $('[data-g-sub]', g).textContent = sub;
  }
  function row(k, v) { return '<dt>' + k + '</dt><dd>' + v + '</dd>'; }

  function render(d) {
    var cpu = d.cpu || {}, mem = d.mem || {}, disk = d.disk || {}, host = d.host || {}, sv = d.services || {}, up = d.updates || {}, bk = d.backup || {};
    var down = ['web', 'db', 'cron'].filter(function (k) { return sv[k] === false; });
    var danger = (d.alerts || []).some(function (a) { return a.level === 'danger' && a.time > d.now - 3600; });
    var hero = $('[data-srv-hero]');
    hero.classList.remove('is-warn', 'is-bad');
    var title = 'كل شيء يعمل';
    if (!d.fresh) { hero.classList.add('is-warn'); title = 'لا تصل بيانات حديثة من الخادم'; }
    else if (down.length || danger) { hero.classList.add('is-bad'); title = 'يوجد ما يحتاج انتباهك'; }
    else if (up.reboot) { hero.classList.add('is-warn'); title = 'الخادم يحتاج إعادة تشغيل لإكمال التحديثات'; }
    $('[data-srv-title]').textContent = title;
    $('[data-srv-host]').textContent = [host.domain || host.ip || '', host.domain ? host.ip : ''].filter(Boolean).join(' · ');
    $('[data-srv-dot]').className = 'status-dot ' + (d.fresh ? 'is-online' : 'is-offline');
    $('[data-srv-age]').textContent = d.time ? ago(d.time) : '—';

    gauge('cpu', cpu.usage, (cpu.cores || 1) + ' أنوية', 'الحمل ' + ((cpu.load || [0])[0]));
    gauge('mem', mem.total ? 100 * mem.used / mem.total : 0, pair(mem.used, mem.total), mem.swap_total ? ltr('Swap ') + pair(mem.swap_used, mem.swap_total) : '');
    gauge('disk', disk.total ? 100 * disk.used / disk.total : 0, pair(disk.used, disk.total), 'المتاح ' + gb(Math.max(0, (disk.total || 0) - (disk.used || 0))));
    $('[data-srv-uptime]').textContent = host.uptime ? span(host.uptime) : '—';
    $('[data-srv-load]').textContent = ltr((cpu.load || []).join(' · '));

    document.querySelectorAll('[data-service]').forEach(function (el) {
      var s = sv[el.getAttribute('data-service')];
      el.style.display = s === null || s === undefined ? 'none' : '';
      $('.status-dot', el).className = 'status-dot ' + (s ? 'is-online' : 'is-offline');
    });

    $('[data-srv-backup-count]').textContent = bk.count || 0;
    $('[data-srv-backup-last]').textContent = bk.last ? stamp(bk.last) + ' · ' + ago(bk.last) : '—';
    $('[data-srv-backup-size]').textContent = SN.fmtBytes(bk.size || 0);
    var off = d.offsite || {};
    $('[data-srv-offsite]').innerHTML = !off.enabled ? '<span class="badge badge-outline">غير مفعّل</span>'
      : (off.status === 'ok' ? '<span class="badge badge-success badge-dot">أُرسلت ' + SN.esc(off.last) + '</span>'
      : (off.status ? '<span class="badge badge-danger badge-dot" title="' + SN.esc(off.status) + '">فشل الإرسال</span>' : '<span class="badge badge-info">مفعّل</span>'));
    $('[data-srv-backups]').innerHTML = (bk.list || []).map(function (b) {
      return '<tr><td class="mono nowrap">' + stamp(b.time) + '</td><td>' + b.dbs + '</td><td class="nowrap">' + SN.fmtBytes(b.size) + '</td></tr>';
    }).join('') || '<tr><td colspan="3"><div class="empty">' + SN.icon('archive') + '<div class="empty-title">لا توجد نسخ بعد</div></div></td></tr>';
    var btn = $('[data-srv-backup]');
    if (btn) { btn.classList.toggle('is-loading', !!d.backup_pending); btn.disabled = !!d.backup_pending; }

    var ssl = d.ssl, days = ssl ? Math.floor((ssl.expires - d.now) / 86400) : null, cron = (d.cron || {}).last, tn = d.tunnel || {};
    $('[data-srv-system]').innerHTML =
      row('الإصدار', '<span class="mono">' + SN.esc(d.version || '—') + '</span>') +
      row('نظام التشغيل', SN.esc(host.os || '—')) +
      row('PHP', '<span class="mono">' + SN.esc((d.software || {}).php || '—') + '</span>') +
      row('قاعدة البيانات', '<span class="mono">' + SN.esc(String((d.software || {}).db || '—').replace(/^(\d+\.\d+\.\d+)-MariaDB.*$/, 'MariaDB $1')) + '</span>') +
      row('شهادة SSL', ssl ? '<span class="badge ' + (days < 7 ? 'badge-danger' : (days < 15 ? 'badge-warning' : 'badge-success')) + '">' + days + ' يوم</span>' : '<span class="badge badge-outline">لا توجد</span>') +
      row('تحديثات النظام', up.pending ? up.pending + (up.security ? ' · ' + up.security + ' أمنية' : '') : '<span class="badge badge-success">محدّث</span>') +
      row('آخر مهمة مجدولة', cron ? ago(cron) : '—') +
      (tn.available ? row('أنفاق أجهزة MikroTik', tn.connected + ' / ' + tn.total + ' متصل') : '');

    var icons = { danger: ['circle-x', 'is-danger'], warning: ['triangle-alert', 'is-warning'], info: ['info', ''] };
    $('[data-srv-alerts]').innerHTML = (d.alerts || []).slice(0, 12).map(function (a) {
      var ic = icons[a.level] || icons.info;
      return '<li class="list-item"><span class="list-icon ' + ic[1] + '">' + SN.icon(ic[0]) + '</span><div class="list-item-main"><div class="list-item-title text-sm">' + SN.esc(a.text) + '</div><div class="list-item-sub">' + stamp(a.time) + '</div></div></li>';
    }).join('') || '<li class="list-item text-muted">لا توجد تنبيهات.</li>';
  }

  render(data);
  function refresh() {
    SN.api(base + '/status', { silent: true }).then(function (d) { data = d; skew = Math.round(Date.now() / 1000) - d.now; render(d); }).catch(function () {});
  }
  setInterval(refresh, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
  setInterval(function () { $('[data-srv-age]').textContent = data.time ? ago(data.time) : '—'; }, 1000);

  var bbtn = $('[data-srv-backup]');
  if (bbtn) {
    bbtn.addEventListener('click', function () {
      SN.confirm('إنشاء نسخة احتياطية كاملة للخادم الآن؟', { danger: false, okText: 'إنشاء' }).then(function (yes) {
        if (!yes) { return; }
        bbtn.classList.add('is-loading'); bbtn.disabled = true;
        SN.api(base + '/backup', { method: 'POST' }).then(function (r) { SN.toast('success', r.message); setTimeout(refresh, 4000); })
          .catch(function () { bbtn.classList.remove('is-loading'); bbtn.disabled = false; });
      });
    });
  }
});
</script>
