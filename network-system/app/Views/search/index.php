<?php
/** @var App\Core\View $v */
use App\Services\CardRules;
use App\Services\Geo;
use App\Services\Inventory;
use App\Services\SubscriberRules;

$v->title = 'نتائج البحث';
$v->subtitle = $q !== '' ? '«' . $q . '»' : null;
$total = array_sum(array_map('count', $results));
?>
<form method="get" class="panel mb-3"><div class="panel-body flex gap-2">
  <input class="input" name="q" value="<?= e($q) ?>" placeholder="رقم كرت، رقم مختصر، اسم مشترك، هاتف، MAC..." autofocus>
  <button class="btn btn-primary"><?= icon('search') ?>بحث</button>
</div></form>

<?php if ($q !== '' && $total === 0): ?>
  <div class="panel"><div class="empty"><?= icon('search') ?><div class="empty-title">لا توجد نتائج مطابقة</div></div></div>
<?php endif; ?>

<div class="grid grid-2">
<?php if ($results['cards']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('ticket') ?>الكروت</h3></div>
    <ul class="list">
      <?php foreach ($results['cards'] as $c): ?>
        <li class="list-item">
          <span class="list-icon is-primary"><?= icon('ticket') ?></span>
          <div class="list-item-main">
            <a class="list-item-title mono" href="<?= e(url('/check/card/' . $c['id'])) ?>"><?= e($c['username']) ?></a>
            <div class="list-item-sub"><?= e($c['category_name']) ?><?= $c['short_code'] ? ' · مختصر: ' . e($c['short_code']) : '' ?></div>
          </div>
          <div class="list-item-end"><?= CardRules::statusBadge($c) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($results['subscribers']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('users') ?>المشتركون</h3></div>
    <ul class="list">
      <?php foreach ($results['subscribers'] as $s): ?>
        <li class="list-item">
          <span class="list-icon is-info"><?= icon('user') ?></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/subscribers/' . $s['id'])) ?>"><?= e($s['full_name']) ?></a>
            <div class="list-item-sub"><span class="mono"><?= e($s['username']) ?></span> · <?= e($s['plan_name']) ?><?= $s['phone'] ? ' · ' . e($s['phone']) : '' ?></div>
          </div>
          <div class="list-item-end"><?= SubscriberRules::badge($s) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($results['devices']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('smartphone') ?>الأجهزة</h3></div>
    <ul class="list">
      <?php foreach ($results['devices'] as $d): ?>
        <li class="list-item">
          <span class="list-icon"><?= icon('smartphone') ?></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/sessions/history', ['q' => $d['mac']])) ?>"><?= e($d['hostname'] ?: $d['mac']) ?></a>
            <div class="list-item-sub mono"><?= e($d['mac']) ?> · <?= e($d['last_ip'] ?? '') ?></div>
          </div>
          <div class="list-item-end"><?= e(time_ago($d['last_seen'])) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($results['routers']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik</h3></div>
    <ul class="list">
      <?php foreach ($results['routers'] as $r): ?>
        <li class="list-item">
          <span class="status-dot is-<?= e($r['status']) ?>"></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/routers/' . $r['id'])) ?>"><?= e($r['name']) ?></a>
            <div class="list-item-sub mono"><?= e($r['host']) ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($results['units']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('barcode') ?>أجهزة المخزون</h3></div>
    <ul class="list">
      <?php foreach ($results['units'] as $u): [$stL, $stC] = Inventory::UNIT_STATUS[$u['status']]; ?>
        <li class="list-item">
          <span class="list-icon"><?= icon('hard-drive') ?></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/inventory/units/' . $u['id'])) ?>"><?= e($u['item_name']) ?></a>
            <div class="list-item-sub mono"><?= e($u['serial']) ?><?= $u['mac'] ? ' · ' . e($u['mac']) : '' ?></div>
          </div>
          <div class="list-item-end"><span class="badge <?= $stC ?>"><?= $stL ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($results['sites']): ?>
  <div class="panel">
    <div class="panel-head"><h3><?= icon('radio-tower') ?>الأبراج والمواقع</h3></div>
    <ul class="list">
      <?php foreach ($results['sites'] as $s): [$tL, $tI] = Geo::SITE_TYPES[$s['type']] ?? [$s['type'], 'map-pin']; ?>
        <li class="list-item">
          <span class="list-icon"><?= icon($tI) ?></span>
          <div class="list-item-main">
            <a class="list-item-title" href="<?= e(url('/sites/' . $s['id'])) ?>"><?= e($s['name']) ?></a>
            <div class="list-item-sub"><?= e($tL) ?><?= $s['address'] ? ' · ' . e($s['address']) : '' ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
</div>
