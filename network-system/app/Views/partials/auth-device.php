<?php
/**
 * جهاز لوحي مائل يعرض لوحة تحكم النظام مصغّرة (توضيحية) في لوحة العرض بشاشات الدخول على الحاسوب.
 * مبنية بعناصر الصفحة وألوان التصميم المطبّق، فتتبع أي تصميم والوضع الداكن واللغة.
 */
use App\Core\Brand;

$nav = [['house', 'الرئيسية', true], ['ticket', 'الكروت', false], ['users', 'المشتركون', false], ['router', 'أجهزة MikroTik', false], ['map', 'الخريطة', false], ['chart-column', 'التقارير', false], ['settings', 'الإعدادات', false]];
$rows = [['84512367', 'كرت ساعتين', 'فعّال', 'ok'], ['99001122', 'كرت يوم', 'فعّال', 'ok'], ['77310045', 'كرت أسبوع', 'جديد', 'new'], ['84512390', 'كرت ساعتين', 'منتهٍ', 'end']];
?>
<div class="auth-device">
  <div class="auth-device-screen">
    <div class="ad-side">
      <div class="ad-brand"><?= Brand::mark(22) ?><span dir="ltr">MADIX</span></div>
      <?php foreach ($nav as [$ic, $label, $on]): ?>
        <div class="ad-nav<?= $on ? ' is-on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="ad-main">
      <div class="ad-top"><div><b>لوحة التحكم</b><small>نظرة عامة على شبكتك اليوم</small></div><span class="ad-live"><i></i>مباشر الآن</span></div>
      <div class="ad-kpis">
        <div class="ad-kpi"><span class="ad-kpi-ic"><?= icon('activity') ?></span><small>المتصلون الآن</small><b dir="ltr">248</b></div>
        <div class="ad-kpi"><span class="ad-kpi-ic is-2"><?= icon('banknote') ?></span><small>مبيعات اليوم</small><b dir="ltr">1,350</b></div>
        <div class="ad-kpi"><span class="ad-kpi-ic is-3"><?= icon('router') ?></span><small>أجهزة MikroTik</small><b dir="ltr">6 / 6</b></div>
      </div>
      <div class="ad-chart">
        <div class="ad-card-head"><b>المبيعات هذا الأسبوع</b><small dir="ltr">8,420</small></div>
        <svg viewBox="0 0 300 70" preserveAspectRatio="none" aria-hidden="true">
          <path d="M0 52 C24 48 34 32 58 36 S96 56 118 42 156 12 178 20 214 42 236 28 274 6 300 10 V70 H0 Z" class="ad-area"/>
          <path d="M0 52 C24 48 34 32 58 36 S96 56 118 42 156 12 178 20 214 42 236 28 274 6 300 10" class="ad-line"/>
        </svg>
      </div>
      <div class="ad-table">
        <div class="ad-card-head"><b>آخر الكروت المباعة</b></div>
        <?php foreach ($rows as [$code, $cat, $st, $cls]): ?>
          <div class="ad-row"><span class="ad-code" dir="ltr"><?= e($code) ?></span><span><?= e($cat) ?></span><span class="ad-st is-<?= $cls ?>"><?= e($st) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
