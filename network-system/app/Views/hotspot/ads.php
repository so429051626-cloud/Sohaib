<?php
/**
 * إعلانات صفحة الهوتسبوت.
 * @var App\Core\View $v @var array $ads @var array $routers @var int $interval
 */
use App\Services\HotspotAds;

$v->title = 'إعلانات صفحة الهوتسبوت';
$v->subtitle = 'عروض الشبكة وإعلانات محلات المنطقة على صفحة الدخول';
$v->actions = '<a class="btn btn-primary" href="' . e(url('/hotspot/ads/create')) . '">' . icon('plus') . 'إعلان جديد</a>';
$today = date('Y-m-d');
$counts = ['active' => 0, 'scheduled' => 0, 'expired' => 0, 'off' => 0];
$views = $clicks = 0;
foreach ($ads as $a) {
    $counts[HotspotAds::state($a, $today)]++;
    $views += (int) $a['views'];
    $clicks += (int) $a['clicks'];
}
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('megaphone') ?></div><div class="stat-body"><div class="stat-label">يُعرض الآن</div><div class="stat-value"><?= num($counts['active']) ?></div><div class="stat-meta">مجدول <?= num($counts['scheduled']) ?> · موقوف <?= num($counts['off']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('eye') ?></div><div class="stat-body"><div class="stat-label">مرات الظهور</div><div class="stat-value"><?= num($views) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('mouse-pointer-2') ?></div><div class="stat-body"><div class="stat-label">الضغطات</div><div class="stat-value"><?= num($clicks) ?></div><div class="stat-meta">نسبة الضغط <?= $views > 0 ? e(number_format($clicks / $views * 100, 1)) . '%' : '—' ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <?php if ($ads): ?>
    <ul class="ad-list">
      <?php foreach ($ads as $a):
          [$label, $badge] = HotspotAds::STATES[HotspotAds::state($a, $today)];
          $only = HotspotAds::routerIds($a); ?>
      <li class="ad-item">
        <div class="ad-thumb"><?php if ($a['image']): ?><img src="<?= e(upload_url((string) $a['image'])) ?>" alt="" loading="lazy"><?php else: ?><?= icon('megaphone') ?><?php endif; ?></div>
        <div class="ad-main">
          <div class="ad-title"><?= e($a['title']) ?> <span class="badge <?= $badge ?>"><?= e($label) ?></span></div>
          <?php if ($a['advertiser'] || $a['body']): ?><div class="ad-sub"><?= $a['advertiser'] ? '<b>' . e($a['advertiser']) . '</b>' . ($a['body'] ? ' · ' : '') : '' ?><?= e(str_limit((string) $a['body'], 110)) ?></div><?php endif; ?>
          <div class="ad-meta">
            <span><?= icon('calendar', 'icon-sm') ?><?= $a['starts_on'] || $a['ends_on'] ? ltr(e(($a['starts_on'] ?: '…') . ' → ' . ($a['ends_on'] ?: '…'))) : 'بلا مدة محددة' ?></span>
            <span><?= icon('router', 'icon-sm') ?><?= $only ? e(implode('، ', array_map(static fn ($id) => $routers[$id] ?? ('#' . $id), $only))) : 'كل أجهزة MikroTik' ?></span>
            <?php if ($a['phone']): ?><span class="mono"><?= icon('phone', 'icon-sm') ?><?= e($a['phone']) ?></span><?php endif; ?>
            <span><?= icon('eye', 'icon-sm') ?><?= num((int) $a['views']) ?></span>
            <span><?= icon('mouse-pointer-2', 'icon-sm') ?><?= num((int) $a['clicks']) ?></span>
          </div>
        </div>
        <div class="row-actions">
          <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/hotspot/ads/' . $a['id'] . '/edit')) ?>" title="تعديل"><?= icon('pencil') ?></a>
          <form method="post" action="<?= e(url('/hotspot/ads/' . $a['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm" title="<?= (int) $a['is_active'] === 1 ? 'إيقاف' : 'تشغيل' ?>"><?= icon((int) $a['is_active'] === 1 ? 'pause' : 'play') ?></button></form>
          <form method="post" action="<?= e(url('/hotspot/ads/' . $a['id'] . '/delete')) ?>" data-confirm="حذف الإعلان «<?= e($a['title']) ?>»؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <div class="empty"><?= icon('megaphone') ?><div class="empty-title">لا توجد إعلانات بعد</div>
        <a class="btn btn-primary" href="<?= e(url('/hotspot/ads/create')) ?>"><?= icon('plus') ?>إعلان جديد</a></div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('file-code-2') ?>إظهارها على صفحة الدخول</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <div class="text-sm">ضع هذا السطر في <span class="mono">login.html</span> حيث تريد ظهور الإعلانات:</div>
        <div class="copyable"><pre class="code-block" id="ads-snippet" dir="ltr">&lt;div id="sn-ads"&gt;&lt;/div&gt;</pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#ads-snippet"><?= icon('copy') ?>نسخ</button></div>
        <div class="text-sm text-muted">تعمل مع مكتبة الهوتسبوت المنشورة على الجهاز (حدّثها من «ربط الهوتسبوت» إن نُشرت قبل هذا التحديث).</div>
      </div>
    </div>
    <form class="panel" method="post" action="<?= e(url('/hotspot/ads/settings')) ?>">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('timer') ?>العرض</h3></div>
      <div class="panel-body">
        <div class="field"><label for="interval">مدة عرض كل إعلان</label>
          <div class="input-group"><input class="input" id="interval" name="interval" value="<?= e(old('interval', (string) $interval)) ?>" inputmode="numeric"><span class="input-addon">ثانية</span></div>
          <?= error_html('interval') ?></div>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
      </div>
    </form>
  </div>
</div>
