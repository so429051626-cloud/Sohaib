<?php
/** @var App\Core\View $v */
use App\Core\Request;

$v->title = 'سجل الجلسات';
$v->subtitle = 'الجلسات المنتهية على كل أجهزة MikroTik';
$req = Request::current();
$reasons = ['logout' => 'خروج', 'reconnect' => 'إعادة اتصال', 'router_offline' => 'انقطاع جهاز MikroTik', 'admin' => 'فصل إداري'];
?>
<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($req->str('q')) ?>" placeholder="المستخدم، IP، MAC، اسم الجهاز"></div>
    <div class="field">
      <label for="router">جهاز MikroTik</label>
      <select class="input" id="router" name="router">
        <option value="">الكل</option>
        <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('router'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="service">الخدمة</label>
      <select class="input" id="service" name="service">
        <option value="">الكل</option>
        <option value="hotspot"<?= selected($req->str('service'), 'hotspot') ?>>هوتسبوت</option>
        <option value="ppp"<?= selected($req->str('service'), 'ppp') ?>>PPP</option>
      </select>
    </div>
    <div class="field"><label for="from">من</label><input class="input" type="date" id="from" name="from" value="<?= e($req->str('from')) ?>"></div>
    <div class="field"><label for="to">إلى</label><input class="input" type="date" id="to" name="to" value="<?= e($req->str('to')) ?>"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>المستخدم</th><th>الخدمة</th><th>الجهاز</th><th>MAC / IP</th><th>جهاز MikroTik</th><th>البداية</th><th>النهاية</th><th>المدة</th><th class="num">البيانات</th><th>السبب</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr>
          <td>
            <?php if ($s['card_id']): ?><a class="mono" href="<?= e(url('/check/card/' . $s['card_id'])) ?>"><?= e($s['username']) ?></a>
            <?php elseif ($s['subscriber_id']): ?><a class="mono" href="<?= e(url('/subscribers/' . $s['subscriber_id'])) ?>"><?= e($s['username']) ?></a>
            <?php else: ?><span class="mono"><?= e($s['username']) ?></span><?php endif; ?>
          </td>
          <td><?= $s['service'] === 'ppp' ? 'PPP' : 'هوتسبوت' ?></td>
          <td><?= e($s['hostname'] ?: '—') ?></td>
          <td><div class="mono text-sm"><?= e($s['mac'] ?? '—') ?></div><div class="mono text-sm text-muted"><?= e($s['ip'] ?? '') ?></div></td>
          <td><?= e($s['router_name'] ?? '—') ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($s['started_at'])) ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($s['stopped_at'])) ?></td>
          <td class="nowrap"><?= e(fmt_duration(max((int) $s['uptime'], strtotime((string) $s['stopped_at']) - strtotime($s['started_at'])), true)) ?></td>
          <td class="num"><?= e(fmt_bytes((int) $s['bytes_in'] + (int) $s['bytes_out'])) ?></td>
          <td><?= e($reasons[$s['stop_reason'] ?? ''] ?? ($s['stop_reason'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10"><div class="empty"><?= icon('history') ?><div class="empty-title">لا توجد جلسات مطابقة</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>
