<?php
/** @var App\Core\View $v */
$rid = (int) $router['id'];
?>
<div class="panel-body">
  <div class="grid grid-2">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>اختبار الاتصال (Ping) من جهاز MikroTik</h3></div>
      <div class="panel-body">
        <form id="ping-form" class="flex gap-2 wrap">
          <input class="input input-ltr" name="address" value="8.8.8.8" style="max-width:240px" required>
          <select class="input" name="count" style="max-width:110px">
            <?php foreach ([4, 6, 10] as $n): ?><option value="<?= $n ?>"><?= $n ?> مرات</option><?php endforeach; ?>
          </select>
          <button class="btn btn-primary"><?= icon('send') ?>تنفيذ</button>
        </form>
        <div id="ping-result" class="mt-2"></div>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('server-cog') ?>خدمات جهاز MikroTik</h3></div>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>الخدمة</th><th>المنفذ</th><th>العناوين المسموحة</th><th>الحالة</th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td><span class="mono"><?= e($s['name'] ?? '') ?></span></td>
              <td><span class="mono"><?= e($s['port'] ?? '') ?></span></td>
              <td><span class="mono text-sm"><?= e(($s['address'] ?? '') ?: 'الكل') ?></span></td>
              <td><?= ($s['disabled'] ?? 'false') === 'true' ? '<span class="badge badge-outline">معطلة</span>' : '<span class="badge badge-success">مفعّلة</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('ping-form').addEventListener('submit', function (e) {
  e.preventDefault();
  var f = this, box = document.getElementById('ping-result'), btn = f.querySelector('button');
  btn.classList.add('is-loading');
  box.innerHTML = '<div class="loading-block"><span class="spinner"></span></div>';
  SN.api(SN.base + '/routers/<?= $rid ?>/ping', { method: 'POST', form: new FormData(f), silent: true }).then(function (r) {
    var ok = r.rows.filter(function (x) { return x.time; }).length;
    var html = '<table class="table table-compact"><thead><tr><th>#</th><th>المضيف</th><th>الزمن</th><th>TTL</th></tr></thead><tbody>';
    r.rows.forEach(function (x) {
      html += '<tr><td>' + SN.esc(x.seq || '') + '</td><td class="mono">' + SN.esc(x.host || '') + '</td><td>' + (x.time ? SN.esc(x.time) : '<span class="badge badge-danger">' + SN.esc(x.status || 'timeout') + '</span>') + '</td><td>' + SN.esc(x.ttl || '') + '</td></tr>';
    });
    box.innerHTML = '<div class="mb-1 text-sm">نجح ' + ok + ' من ' + r.rows.length + '</div>' + html + '</tbody></table>';
  }).catch(function (err) {
    box.innerHTML = '<div class="notice notice-danger">' + SN.icon('circle-x') + '<div>' + SN.esc(err.message) + '</div></div>';
  }).then(function () { btn.classList.remove('is-loading'); });
});
</script>
