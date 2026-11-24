<?php
/**
 * معاينة حيّة للتصميم: صفحة نموذجية من النظام بالقيم المختارة (تُعرض داخل إطار في صفحة التصاميم).
 * @var array $t القيم بعد التنقية
 * @var string $mode light|dark
 */
use App\Core\Theme;

$nav = [
    [null, [['لوحة التحكم', 'layout-dashboard', true], ['المساعد الذكي', 'sparkles', false]]],
    ['الشبكة', [['أجهزة MikroTik', 'router', false], ['فحص الكابلات', 'cable', false], ['المتصلون الآن', 'activity', false], ['خريطة الشبكة', 'map', false]]],
    ['الكروت', [['الكروت', 'ticket', false], ['توليد كروت', 'wand-sparkles', false], ['الباقات', 'layers', false]]],
    ['المبيعات', [['نقطة البيع', 'shopping-cart', false], ['الموزعون', 'store', false], ['التقارير', 'chart-column', false]]],
];
$rows = [
    ['602813927124', 'باقة يومية', 'badge-success', 'نشط', '1.2 GB'],
    ['602813920981', 'باقة أسبوعية', 'badge-info', 'متاح', '—'],
    ['602813915540', 'باقة شهرية', 'badge-primary', 'مباع', '—'],
    ['602813903377', 'باقة يومية', 'badge-warning', 'ينتهي قريباً', '3.8 GB'],
    ['602813899012', 'باقة ساعة', 'badge-danger', 'منتهي', '640 MB'],
];
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?> data-theme="<?= e($mode) ?>" data-theme-pref="<?= e($mode) ?>"<?= Theme::attrs($t) ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="icons-url" content="<?= e(asset('img/icons.svg')) ?>">
<title>معاينة التصميم</title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?= Theme::style($t) ?>
<style>
  html, body { overflow-x: hidden; }
  .content { padding-bottom: 40px; }
  /* بلا انتقال بين الصفحات داخل المعاينة */
  @view-transition { navigation: none; }
</style>
</head>
<body class="design-preview-page">
<div class="shell">
  <aside class="sidebar" aria-label="القائمة" data-nav="preview">
    <div class="sidebar-head">
      <a class="sidebar-brand" href="#">
        <span class="net-mark" aria-hidden="true">S</span>
        <span class="brand-text"><span class="brand-name d-block">SO NET</span><span class="brand-sub d-block">MADIX NEXUS</span></span>
      </a>
    </div>
    <div class="nav-search" role="search"><?= icon('search') ?><input type="search" placeholder="ابحث في القائمة" aria-label="ابحث في القائمة" autocomplete="off"><kbd class="nav-kbd" aria-hidden="true"><bdi dir="ltr">Ctrl K</bdi></kbd></div>
    <div class="sidebar-body">
      <nav class="sidebar-nav">
        <?php foreach ($nav as [$title, $items]): ?>
        <div class="nav-group">
          <?php if ($title): ?><button type="button" class="nav-group-title"><span><?= e($title) ?></span><?= icon('chevron-down', 'nav-chevron') ?></button><?php endif; ?>
          <div class="nav-group-items"><div class="nav-group-inner">
            <?php foreach ($items as [$label, $ic, $on]): ?>
            <a class="nav-link<?= $on ? ' is-active' : '' ?>" href="#"><?= icon($ic) ?><span class="nav-text"><?= e($label) ?></span><?= $ic === 'sparkles' ? '<span class="nav-badge is-ai">AI</span>' : '' ?><?= $ic === 'activity' ? '<span class="nav-badge is-danger">12</span>' : '' ?></a>
            <?php endforeach; ?>
          </div></div>
        </div>
        <?php endforeach; ?>
      </nav>
    </div>
    <div class="sidebar-foot"><span class="foot-date"><?= icon('calendar', 'icon-sm') ?>اليوم</span><span class="ver-chip">الإصدار <?= e(APP_VERSION) ?></span></div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button type="button" class="icon-btn menu-toggle" aria-label="القائمة"><?= icon('menu') ?></button>
      <a class="topbar-brand" href="#"><span class="net-mark" aria-hidden="true">S</span><span class="topbar-brand-name">SO NET</span></a>
      <div class="topbar-search"><?= icon('search') ?><input type="search" placeholder="بحث عن كرت، مشترك، عنوان MAC..." aria-label="بحث"></div>
      <div class="topbar-spacer"></div>
      <div class="topbar-actions">
        <a class="icon-btn ai-btn" href="#" aria-label="المساعد الذكي"><?= icon('sparkles') ?></a>
        <span class="icon-btn"><?= icon('sun-moon') ?></span>
        <a class="icon-btn" href="#" aria-label="التنبيهات"><?= icon('bell') ?><span class="dot">6</span></a>
        <span class="user-chip"><span class="avatar">S</span><span class="user-chip-text"><span class="user-chip-name d-block">صهيب</span><span class="user-chip-role d-block">المالك</span></span><?= icon('chevron-down', 'icon-sm text-muted') ?></span>
      </div>
    </header>
    <main class="content">
      <div class="page-head">
        <div class="page-title"><h1>لوحة التحكم</h1><div class="page-sub">نظرة عامة على الشبكة اليوم</div></div>
        <div class="page-actions">
          <div class="dropdown"><button type="button" class="btn" data-dropdown><?= icon('download') ?>تصدير</button><div class="dropdown-menu"><a class="dropdown-item" href="#"><?= icon('file-spreadsheet') ?>Excel</a><a class="dropdown-item" href="#"><?= icon('file-text') ?>CSV</a></div></div>
          <a class="btn btn-primary" href="#"><?= icon('wand-sparkles') ?>توليد كروت</a>
        </div>
      </div>
      <nav class="section-nav">
        <a class="section-link is-active" href="#"><span class="section-icon"><?= icon('ticket') ?></span><span>الكروت</span></a>
        <a class="section-link" href="#"><span class="section-icon"><?= icon('file-up') ?></span><span>استيراد كروت</span></a>
        <a class="section-link" href="#"><span class="section-icon"><?= icon('printer') ?></span><span>طباعة الكروت</span></a>
        <a class="section-link" href="#"><span class="section-icon"><?= icon('settings') ?></span><span>إعدادات الكروت</span></a>
      </nav>
      <div class="stats">
        <a class="stat" href="#"><span class="stat-icon"><?= icon('activity') ?></span><span class="stat-body"><span class="stat-label d-block">المتصلون الآن</span><span class="stat-value d-block">248</span><span class="stat-meta d-block"><span class="up">▲ 12%</span> عن أمس</span></span></a>
        <a class="stat" href="#"><span class="stat-icon is-success"><?= icon('banknote') ?></span><span class="stat-body"><span class="stat-label d-block">مبيعات اليوم</span><span class="stat-value d-block">1,350 ₪</span><span class="stat-meta d-block"><span class="up">▲ 8%</span> عن أمس</span></span></a>
        <a class="stat" href="#"><span class="stat-icon is-warning"><?= icon('ticket') ?></span><span class="stat-body"><span class="stat-label d-block">كروت متاحة</span><span class="stat-value d-block">4,120</span><span class="stat-meta d-block">في 6 باقات</span></span></a>
        <a class="stat" href="#"><span class="stat-icon is-violet"><?= icon('router') ?></span><span class="stat-body"><span class="stat-label d-block">أجهزة MikroTik</span><span class="stat-value d-block">5 / 6</span><span class="stat-meta d-block"><span class="down">جهاز واحد غير متصل</span></span></span></a>
      </div>
      <div class="grid grid-main-side">
        <div class="panel">
          <nav class="tabs"><a class="tab is-active" href="#">الكل <span class="tab-count">407</span></a><a class="tab" href="#">المتاحة</a><a class="tab" href="#">المباعة</a><a class="tab" href="#">النشطة</a><a class="tab" href="#">المنتهية</a></nav>
          <div class="filters">
            <div class="field grow"><label>بحث</label><input class="input" placeholder="رقم الكرت، المختصر، #الرقم، MAC"></div>
            <div class="field"><label>الباقة</label><select class="input"><option>الكل</option><option>باقة يومية</option></select></div>
            <div class="field" style="flex:0 0 auto"><label>&nbsp;</label><button type="button" class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th>اسم المستخدم</th><th>الباقة</th><th>الحالة</th><th class="num">الاستهلاك</th></tr></thead>
              <tbody>
              <?php foreach ($rows as [$u, $cat, $cls, $st, $use]): ?>
                <tr><td><span class="cell-title mono"><?= e($u) ?></span></td><td><?= e($cat) ?></td><td><span class="badge <?= e($cls) ?>"><?= e($st) ?></span></td><td class="num"><?= e($use) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="stack">
          <div class="panel">
            <div class="panel-head"><h3><?= icon('router') ?>أجهزة MikroTik</h3><span class="badge badge-success">5 متصلة</span></div>
            <ul class="list">
              <?php foreach ([['برج الشمال', 'CPU 12%', 'is-success'], ['برج الجنوب', 'CPU 31%', 'is-success'], ['السوق', 'غير متصل', 'is-danger']] as [$n, $m, $c]): ?>
              <li class="list-item"><span class="list-icon <?= e($c) ?>"><?= icon('router') ?></span><div class="list-item-main"><div class="list-item-title"><?= e($n) ?></div><div class="text-sm text-muted"><?= e($m) ?></div></div></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="panel">
            <div class="panel-head"><h3><?= icon('sliders-horizontal') ?>إعدادات سريعة</h3></div>
            <div class="panel-body stack" style="gap:12px">
              <div class="field"><label>اسم الشبكة</label><input class="input" value="SO NET"></div>
              <label class="switch"><input type="checkbox" checked><span class="track"></span><span>الدخول التلقائي بالجهاز</span></label>
              <div class="segmented"><label><input type="radio" name="pv-seg" checked><span>يومي</span></label><label><input type="radio" name="pv-seg"><span>أسبوعي</span></label><label><input type="radio" name="pv-seg"><span>شهري</span></label></div>
              <div class="meter-row"><span>استهلاك الباقة</span><strong>68%</strong></div><div class="meter"><span style="width:68%"></span></div>
              <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-primary"><?= icon('save') ?>حفظ</button><button type="button" class="btn btn-soft">ثانوي</button><button type="button" class="btn">عادي</button></div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script>
(function () {
  'use strict';
  // المعاينة لا تنتقل إلى أي صفحة ولا ترسل أي نموذج
  document.addEventListener('click', function (e) { var a = e.target.closest('a'); if (a) { e.preventDefault(); } }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);
})();
</script>
</body>
</html>
