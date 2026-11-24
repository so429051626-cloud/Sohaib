<?php
/** صفحة الهبوط بالتصميم الكلاسيكي الداكن (يُختار من لوحة المسؤول ← صفحة الهبوط) */
/** @var App\Core\View $v */
use App\Core\Brand;
use App\Core\Platform;
use App\Platform\Billing;
use App\Platform\Landing as L;

$featureIcons = ['router', 'ticket', 'users', 'map', 'warehouse', 'bot', 'shield-check', 'chart-column', 'message-circle', 'zap', 'fingerprint', 'printer'];
$features = L::on('features') ? L::items('features') : [];
$steps = L::on('steps') ? L::items('steps') : [];
$stats = L::on('stats') ? L::items('stats') : [];
$faq = L::on('faq') ? L::items('faq') : [];
$plans = [];
if (L::on('pricing')) {
    try {
        $plans = Billing::plans(true);
    } catch (Throwable) {
        $plans = [];
    }
}
$apps = L::on('apps');
$currency = Platform::setting('platform.currency');
$signup = Platform::setting('platform.signup') === '1';
$whatsapp = Platform::whatsappLink('مرحباً، أريد الاستفسار عن ' . Brand::NAME);
$phone = trim(Platform::setting('platform.whatsapp'));
$facebook = trim(Platform::setting('platform.facebook'));
$price = static fn ($x) => number_format((float) $x, abs((float) $x - round((float) $x)) < 0.005 ? 0 : 2, '.', ',');
$links = array_filter([
    'features' => $features ? 'المميزات' : null,
    'steps' => $steps ? 'كيف تبدأ' : null,
    'pricing' => $plans ? 'الباقات' : null,
    'apps' => $apps ? 'التطبيقات' : null,
    'faq' => $faq ? 'الأسئلة' : null,
]);
$cta = $signup ? url('/signup') : url('/login');
?>
<a class="lp-skip" href="#main">تخطَّ إلى المحتوى</a>

<header class="lp-nav" data-lp-nav>
  <div class="lp-nav-in">
    <a class="lp-brand" href="<?= e(url('/')) ?>" aria-label="<?= e(Brand::NAME) ?>"><?= Brand::mark(34) ?><?= Brand::wordmark() ?></a>
    <nav class="lp-links" aria-label="أقسام الصفحة">
      <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <div class="lp-nav-cta">
      <?php if (\App\Core\Lang::switchable()): ?><a class="lp-btn lp-btn-ghost lp-lang" href="<?= e(\App\Core\Lang::switchUrl(\App\Core\Lang::isEn() ? 'ar' : 'en')) ?>" title="Language / اللغة"><?= icon('globe') ?><?= \App\Core\Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a><?php endif; ?>
      <a class="lp-btn lp-btn-ghost lp-nav-login" href="<?= e(url('/login')) ?>"><?= e(L::get('cta_secondary')) ?></a>
      <?php if ($signup): ?><a class="lp-btn lp-btn-primary lp-nav-signup" href="<?= e(url('/signup')) ?>"><?= e(L::get('cta_primary')) ?></a><?php endif; ?>
    </div>
    <?php if ($links): ?>
    <details class="lp-menu" data-lp-menu>
      <summary aria-label="القائمة"><?= icon('menu') ?></summary>
      <nav class="lp-menu-panel" aria-label="أقسام الصفحة">
        <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
        <a href="<?= e(url('/login')) ?>"><?= icon('log-in') ?> <?= e(L::get('cta_secondary')) ?></a>
        <?php if ($signup): ?><a class="lp-btn lp-btn-primary" href="<?= e(url('/signup')) ?>"><?= e(L::get('cta_primary')) ?></a><?php endif; ?>
        <?php if (\App\Core\Lang::switchable()): ?><a href="<?= e(\App\Core\Lang::switchUrl(\App\Core\Lang::isEn() ? 'ar' : 'en')) ?>"><?= icon('globe') ?> <?= \App\Core\Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a><?php endif; ?>
      </nav>
    </details>
    <?php endif; ?>
  </div>
</header>

<main id="main">
<section class="lp-hero">
  <div class="lp-hero-bg" aria-hidden="true">
    <span class="lp-aurora lp-aurora-a"></span><span class="lp-aurora lp-aurora-b"></span><span class="lp-aurora lp-aurora-c"></span>
    <span class="lp-stars"></span>
    <span class="lp-floor"><i></i></span>
  </div>
  <div class="lp-wrap lp-hero-in">
    <div class="lp-hero-text">
      <span class="lp-eyebrow"><span class="lp-pulse"></span><?= e(L::get('hero_badge')) ?></span>
      <h1 class="lp-title"><?= e(L::get('hero_title')) ?></h1>
      <p class="lp-lead"><?= e(L::get('hero_text')) ?></p>
      <div class="lp-hero-cta">
        <a class="lp-btn lp-btn-primary lp-btn-lg lp-btn-shine" href="<?= e($cta) ?>"><?= e($signup ? L::get('cta_primary') : L::get('cta_secondary')) ?><?= icon('chevron-left', 'lp-arrow') ?></a>
        <?php if ($signup): ?><a class="lp-btn lp-btn-glass lp-btn-lg" href="<?= e(url('/login')) ?>"><?= icon('log-in') ?><?= e(L::get('cta_secondary')) ?></a><?php endif; ?>
      </div>
      <?php if ($signup): ?><div class="lp-note"><?= icon('circle-check') ?><span><?= e(L::get('trial_note')) ?></span></div><?php endif; ?>
    </div>

    <div class="lp-stage" aria-hidden="true" data-lp-tilt>
      <div class="lp-window">
        <div class="lp-window-bar"><i></i><i></i><i></i><span class="lp-window-url" dir="ltr">madix.org / <b>nexus</b></span></div>
        <div class="lp-app">
          <div class="lp-app-side"><span class="lp-app-logo"><?= Brand::mark(22) ?></span><i class="on"></i><i></i><i></i><i></i><i></i></div>
          <div class="lp-app-main">
            <div class="lp-app-top"><b>لوحة التحكم</b><span class="lp-live"><i></i>مباشر الآن</span></div>
            <div class="lp-kpis">
              <div class="lp-kpi"><small>المتصلون الآن</small><b dir="ltr">248</b><em class="up" dir="ltr">+12%</em></div>
              <div class="lp-kpi"><small>مبيعات اليوم</small><b dir="ltr">1,350 <?= e($currency) ?></b><em class="up" dir="ltr">+8%</em></div>
              <div class="lp-kpi"><small>أجهزة MikroTik</small><b dir="ltr">6 / 6</b><em>متصلة</em></div>
            </div>
            <div class="lp-chart-card">
              <div class="lp-chart-head"><span>مبيعات هذا الأسبوع</span><b dir="ltr">8,420 <?= e($currency) ?></b></div>
              <svg class="lp-chart" viewBox="0 0 320 84" preserveAspectRatio="none">
                <defs><linearGradient id="lp-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".38"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>
                <path d="M0 62 C26 58 38 40 64 44 S104 66 128 50 168 18 192 26 232 48 256 34 296 10 320 14 V84 H0 Z" fill="url(#lp-area)"/>
                <path class="lp-chart-line" d="M0 62 C26 58 38 40 64 44 S104 66 128 50 168 18 192 26 232 48 256 34 296 10 320 14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" pathLength="1"/>
              </svg>
            </div>
            <div class="lp-rows">
              <?php foreach ([['برج الشمال', '26.4 V', 'متصل', 'ok'], ['السوق المركزي', '25.1 V', 'متصل', 'ok'], ['حي الجامعة', '24.2 V', 'تحذير', 'warn']] as [$n, $volt, $s, $c]): ?>
              <div class="lp-row"><i class="<?= $c ?>"></i><span><?= e($n) ?></span><em dir="ltr"><?= e($volt) ?></em><b class="<?= $c ?>"><?= e($s) ?></b></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="lp-float lp-float-a"><span class="lp-float-icon"><?= icon('ticket') ?></span><span>بيع 50 كرتاً</span></div>
      <div class="lp-float lp-float-b"><span class="lp-float-icon is-warm"><?= icon('zap') ?></span><span>بطارية برج الشمال <b dir="ltr">26.4 V</b></span></div>
    </div>
  </div>
  <div class="lp-wrap lp-marquee" aria-hidden="true">
    <div class="lp-marquee-track">
      <?php for ($r = 0; $r < 2; $r++): ?>
      <?php foreach ([['router', 'MikroTik'], ['wifi', 'Hotspot'], ['cable', 'PPPoE'], ['shield-check', 'WireGuard'], ['send', 'Telegram'], ['message-circle', 'SMS'], ['smartphone', 'Android'], ['monitor', 'Windows'], ['map', 'Google Maps'], ['printer', 'QR']] as [$ic, $t]): ?>
      <span class="lp-chip"><?= icon($ic) ?><span dir="ltr"><?= e($t) ?></span></span>
      <?php endforeach; ?>
      <?php endfor; ?>
    </div>
  </div>
</section>

<?php if ($stats): ?>
<section class="lp-stats" aria-label="أرقام">
  <div class="lp-wrap">
    <div class="lp-stats-in">
      <?php foreach ($stats as $i => [$num, $label]): ?>
      <div class="lp-stat" data-lp-anim style="--i:<?= $i ?>"><b><?= e($num) ?></b><span><?= e($label) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($features): ?>
<section class="lp-section" id="features">
  <div class="lp-wrap">
    <div class="lp-head">
      <span class="lp-kicker"><?= icon('sparkles') ?>المميزات</span>
      <h2><?= e(L::get('features_title')) ?></h2>
      <p><?= e(L::get('features_text')) ?></p>
    </div>
    <div class="lp-bento">
      <?php foreach ($features as $i => [$title, $text]): ?>
      <article class="lp-feature lp-spot" data-lp-anim style="--i:<?= $i % 3 ?>">
        <span class="lp-feature-icon is-<?= $i % 6 ?>"><?= icon($featureIcons[$i] ?? 'zap') ?></span>
        <h3><?= e($title) ?></h3>
        <p><?= e($text) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($steps): ?>
<section class="lp-section lp-section-alt" id="steps">
  <div class="lp-wrap">
    <div class="lp-head">
      <span class="lp-kicker"><?= icon('zap') ?>طريقة العمل</span>
      <h2><?= e(L::get('steps_title')) ?></h2>
    </div>
    <ol class="lp-steps">
      <?php foreach ($steps as $i => [$title, $text]): ?>
      <li class="lp-step" data-lp-anim style="--i:<?= $i ?>">
        <span class="lp-step-num"><?= $i + 1 ?></span>
        <h3><?= e($title) ?></h3>
        <p><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<?php if ($plans): ?>
<section class="lp-section" id="pricing">
  <div class="lp-wrap">
    <div class="lp-head">
      <span class="lp-kicker"><?= icon('ticket') ?>الباقات والأسعار</span>
      <h2><?= e(L::get('pricing_title')) ?></h2>
      <p><?= e(L::get('pricing_text')) ?></p>
    </div>
    <div class="lp-plans">
      <?php foreach ($plans as $i => $p): $hot = (int) $p['is_featured'] === 1; ?>
      <article class="lp-plan lp-spot<?= $hot ? ' is-hot' : '' ?>" data-lp-anim style="--i:<?= $i ?>">
        <?php if ($hot): ?><span class="lp-plan-tag"><?= icon('sparkles') ?>الأكثر طلباً</span><?php endif; ?>
        <h3><?= e($p['name']) ?></h3>
        <?php if ($p['tagline']): ?><p class="lp-plan-sub"><?= e($p['tagline']) ?></p><?php endif; ?>
        <div class="lp-plan-price"><b dir="ltr"><?= e($price($p['price'])) ?></b><span><?= e($currency) ?> / <?= e(Billing::monthsLabel((int) $p['months'])) ?></span></div>
        <ul class="lp-plan-list">
          <?php foreach (array_merge(Billing::planLimits($p), Billing::planFeatures($p)) as $line): ?><li><?= icon('check') ?><span><?= e($line) ?></span></li><?php endforeach; ?>
        </ul>
        <a class="lp-btn <?= $hot ? 'lp-btn-primary lp-btn-shine' : 'lp-btn-glass' ?> lp-btn-block" href="<?= e($cta) ?>"><?= e($signup ? L::get('cta_primary') : L::get('cta_secondary')) ?></a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($apps): ?>
<section class="lp-section lp-section-alt" id="apps">
  <div class="lp-wrap lp-apps">
    <div class="lp-apps-text">
      <span class="lp-kicker"><?= icon('smartphone') ?>التطبيقات</span>
      <h2><?= e(L::get('apps_title')) ?></h2>
      <p><?= e(L::get('apps_text')) ?></p>
      <div class="lp-badges">
        <span class="lp-store"><?= icon('smartphone') ?><span><small>متوفر على</small><b dir="ltr">Android</b></span></span>
        <span class="lp-store"><?= icon('monitor') ?><span><small>متوفر على</small><b dir="ltr">Windows</b></span></span>
      </div>
    </div>
    <div class="lp-devices" aria-hidden="true">
      <div class="lp-laptop">
        <div class="lp-laptop-screen">
          <div class="lp-mini-side"><i class="on"></i><i></i><i></i><i></i></div>
          <div class="lp-mini-main"><div class="lp-mini-cards"><i></i><i></i><i></i></div><div class="lp-mini-chart"><?php foreach ([42, 60, 48, 74, 58, 86, 70, 92] as $h): ?><span style="--h:<?= $h ?>%"></span><?php endforeach; ?></div><div class="lp-mini-lines"><i></i><i></i><i></i></div></div>
        </div>
        <div class="lp-laptop-base"></div>
      </div>
      <div class="lp-phone">
        <div class="lp-phone-screen">
          <?= Brand::mark(44) ?>
          <b dir="ltr">MADIX NEXUS</b>
          <i></i><i></i>
          <span class="lp-phone-btn"></span>
          <span class="lp-phone-bio"><?= icon('fingerprint') ?></span>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faq): ?>
<section class="lp-section" id="faq">
  <div class="lp-wrap lp-faq-wrap">
    <div class="lp-head">
      <span class="lp-kicker"><?= icon('message-circle') ?>الأسئلة الشائعة</span>
      <h2><?= e(L::get('faq_title')) ?></h2>
    </div>
    <div class="lp-faq">
      <?php foreach ($faq as $i => [$q, $a]): ?>
      <details class="lp-qa"<?= $i === 0 ? ' open' : '' ?>>
        <summary><span><?= e($q) ?></span><?= icon('chevron-down') ?></summary>
        <p><?= e($a) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="lp-section lp-section-cta">
  <div class="lp-wrap">
    <div class="lp-cta">
      <span class="lp-cta-glow" aria-hidden="true"></span>
      <div class="lp-cta-text">
        <h2><?= e(L::get('cta_title')) ?></h2>
        <p><?= e(L::get('cta_text')) ?></p>
      </div>
      <div class="lp-cta-btns">
        <a class="lp-btn lp-btn-white lp-btn-lg" href="<?= e($cta) ?>"><?= e($signup ? L::get('cta_primary') : L::get('cta_secondary')) ?></a>
        <a class="lp-btn lp-btn-glass lp-btn-lg" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>واتساب</a>
      </div>
    </div>
  </div>
</section>
</main>

<footer class="lp-footer">
  <div class="lp-wrap lp-footer-in">
    <div class="lp-footer-brand">
      <a class="lp-brand" href="<?= e(url('/')) ?>"><?= Brand::mark(34) ?><?= Brand::wordmark() ?></a>
      <p><?= e(L::get('footer_text')) ?></p>
    </div>
    <?php if ($links): ?>
    <nav class="lp-footer-col" aria-label="روابط">
      <h4>الصفحة</h4>
      <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="lp-footer-col">
      <h4>الحساب</h4>
      <a href="<?= e(url('/login')) ?>"><?= e(L::get('cta_secondary')) ?></a>
      <?php if ($signup): ?><a href="<?= e(url('/signup')) ?>"><?= e(L::get('cta_primary')) ?></a><?php endif; ?>
    </div>
    <div class="lp-footer-col">
      <h4>تواصل معنا</h4>
      <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>واتساب<?php if ($phone !== ''): ?> <span dir="ltr"><?= e($phone) ?></span><?php endif; ?></a>
      <?php if ($phone !== ''): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= icon('phone') ?><span dir="ltr"><?= e($phone) ?></span></a><?php endif; ?>
      <?php if ($facebook !== '' && preg_match('#^https?://#i', $facebook)): ?><a href="<?= e($facebook) ?>" target="_blank" rel="noopener"><?= icon('globe') ?>فيسبوك</a><?php endif; ?>
    </div>
  </div>
  <div class="lp-wrap lp-footer-bar">
    <span>&copy; <?= date('Y') ?> <span dir="ltr"><?= e(Brand::NAME) ?></span></span>
    <span class="lp-credit" dir="ltr">Powered by <?= Brand::mark(14) ?><b>Madix</b> — Developed by <?= Brand::developer(14) ?></span>
  </div>
</footer>

<a class="lp-wa" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener" aria-label="تواصل عبر واتساب"><?= icon('message-circle') ?></a>
