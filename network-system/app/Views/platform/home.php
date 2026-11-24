<?php
/** @var App\Core\View $v */
use App\Core\Brand;
use App\Core\I18n;
use App\Core\Lang;
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
$video = L::video();
$price = static fn ($x) => number_format((float) $x, abs((float) $x - round((float) $x)) < 0.005 ? 0 : 2, '.', ',');
// جولة داخل النظام: لقطات حقيقية من النظام بلغة الصفحة
$tour = [
    ['map', 'map', 'خريطة الشبكة', 'شبكتك كلها على خريطة حيّة', 'الأبراج وأجهزة MikroTik والروابط ومواقع المشتركين، مع حالة كل جهاز لحظة بلحظة.', ['روابط لاسلكية وفايبر وكابل', 'حالة المشتركين بالألوان', 'خريطة القمر الصناعي وخرائط Google']],
    ['design', 'printer', 'تصميم الكروت', 'صمّم كروتك واطبعها بنفسك', 'محرّر مرئي للكرت: الرقم وكلمة المرور والسعر والصلاحية ورمز QR وشعارك، بالمقاس الذي تريده.', ['قوالب جاهزة للطباعة', 'رقم مختصر لدخول أسرع', 'معاينة الصفحة قبل الطباعة']],
    ['router', 'router', 'أجهزة MikroTik', 'كل جهاز MikroTik تحت عينك', 'المتصلون والمعالج والذاكرة والحرارة وحركة الإنترنت المباشرة، مع المزامنة وإعادة التشغيل بضغطة.', ['يدعم RouterOS 6 و 7', 'ربط آمن عبر نفق WireGuard', 'هوتسبوت و PPPoE']],
    ['cables', 'cable', 'فحص الكابلات', 'اكتشف عطل الكابل قبل أن يتصل بك المشترك', 'فحص المنافذ عن بُعد: حالة الرابط وسرعته وأخطاؤه، وطول الكابل ومكان القطع في الأجهزة التي تدعم ذلك.', ['مكان القطع بالمتر لكل زوج', 'عدّادات الأخطاء ومرات الانقطاع', 'تنبيه عند تراجع جودة الرابط']],
    ['power', 'zap', 'مراقبة الطاقة', 'بطاريات الأبراج تحت المراقبة', 'جهد كل برج على مدار الساعة مع حدود التحذير والخطر، وتقدير للوقت المتبقي قبل أن تنطفئ.', ['منحنى 24 ساعة لكل برج', 'تنبيهات فورية على تيليجرام', 'سجل للانقطاعات وأسبابها']],
    ['reports', 'chart-column', 'التقارير', 'أرقامك واضحة كل يوم', 'المبيعات والتحصيل والتجديدات والمستحقات بالفترة التي تختارها، مع الطباعة والتصدير.', ['مبيعات الكروت والاشتراكات', 'المصاريف وصافي الربح', 'تقارير يومية على تيليجرام']],
];
$lang = Lang::isEn() ? 'en' : 'ar';
$shot = static fn (string $n, bool $small = false) => asset('img/landing/' . $n . '-' . $lang . ($small ? '-sm' : '') . '.webp');
// العنوان: آخر كلماته بلون الهوية المتدرّج (يُترجم أولاً ثم يُقسم، حتى تبقى الترجمة جملة كاملة)
$title = I18n::t(L::get('hero_title'));
$words = preg_split('/\s+/u', trim($title)) ?: [];
$cut = count($words) >= 4 ? count($words) - (int) ceil(count($words) / 2.5) : count($words);
$titleHtml = e(implode(' ', array_slice($words, 0, $cut))) . ($cut < count($words) ? ' <span class="lp-hl">' . e(implode(' ', array_slice($words, $cut))) . '</span>' : '');
$links = array_filter([
    'features' => $features ? 'المميزات' : null,
    'tour' => 'جولة في النظام',
    'pricing' => $plans ? 'الباقات' : null,
    'apps' => $apps ? 'التطبيقات' : null,
    'faq' => $faq ? 'الأسئلة' : null,
]);
$cta = $signup ? url('/signup') : url('/login');
?>
<a class="lp-skip" href="#main">تخطَّ إلى المحتوى</a>
<div class="lp-progress" aria-hidden="true"><i data-lp-progress></i></div>

<header class="lp-nav" data-lp-nav>
  <div class="lp-nav-in">
    <a class="lp-brand" href="<?= e(url('/')) ?>" aria-label="<?= e(Brand::NAME) ?>"><?= Brand::mark(36) ?><?= Brand::wordmark() ?></a>
    <nav class="lp-links" aria-label="أقسام الصفحة">
      <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>" data-lp-link><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <div class="lp-nav-cta">
      <?php if (Lang::switchable()): ?><a class="lp-btn lp-btn-ghost lp-lang" href="<?= e(Lang::switchUrl(Lang::isEn() ? 'ar' : 'en')) ?>" title="Language / اللغة"><?= icon('globe') ?><?= Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a><?php endif; ?>
      <a class="lp-btn lp-btn-ghost lp-nav-login" href="<?= e(url('/login')) ?>"><?= e(L::get('cta_secondary')) ?></a>
      <?php if ($signup): ?><a class="lp-btn lp-btn-primary lp-nav-signup" href="<?= e(url('/signup')) ?>"><?= e(L::get('cta_primary')) ?></a><?php endif; ?>
    </div>
    <details class="lp-menu" data-lp-menu>
      <summary aria-label="القائمة"><?= icon('menu') ?></summary>
      <nav class="lp-menu-panel" aria-label="أقسام الصفحة">
        <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
        <a href="<?= e(url('/login')) ?>"><?= icon('log-in') ?> <?= e(L::get('cta_secondary')) ?></a>
        <?php if ($signup): ?><a class="lp-btn lp-btn-primary" href="<?= e(url('/signup')) ?>"><?= e(L::get('cta_primary')) ?></a><?php endif; ?>
        <?php if (Lang::switchable()): ?><a href="<?= e(Lang::switchUrl(Lang::isEn() ? 'ar' : 'en')) ?>"><?= icon('globe') ?> <?= Lang::isEn() ? '<span lang="ar">العربية</span>' : '<span lang="en" dir="ltr">English</span>' ?></a><?php endif; ?>
      </nav>
    </details>
  </div>
</header>

<main id="main">
<section class="lp-hero">
  <div class="lp-sky" aria-hidden="true">
    <span class="lp-blob lp-blob-a"></span><span class="lp-blob lp-blob-b"></span><span class="lp-blob lp-blob-c"></span><span class="lp-blob lp-blob-d"></span>
    <span class="lp-dots"></span>
    <svg class="lp-net" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
      <g fill="none" stroke-width="1.6" stroke-dasharray="2 8" stroke-linecap="round">
        <path d="M-20 150 C 260 60, 480 200, 720 120 S 1150 40, 1460 140"/>
        <path d="M-40 780 C 240 700, 420 840, 700 810 S 1160 700, 1480 820"/>
      </g>
      <g class="lp-net-nodes"><circle cx="250" cy="96" r="5"/><circle cx="720" cy="120" r="4"/><circle cx="1150" cy="66" r="5"/><circle cx="420" cy="796" r="4.5"/><circle cx="1160" cy="736" r="5"/></g>
    </svg>
    <svg class="lp-spark lp-spark-a" viewBox="0 0 24 24"><path d="M12 0C13 7.4 16.6 11 24 12 16.6 13 13 16.6 12 24 11 16.6 7.4 13 0 12 7.4 11 11 7.4 12 0Z"/></svg>
    <svg class="lp-spark lp-spark-b" viewBox="0 0 24 24"><path d="M12 0C13 7.4 16.6 11 24 12 16.6 13 13 16.6 12 24 11 16.6 7.4 13 0 12 7.4 11 11 7.4 12 0Z"/></svg>
    <svg class="lp-spark lp-spark-c" viewBox="0 0 24 24"><path d="M12 0C13 7.4 16.6 11 24 12 16.6 13 13 16.6 12 24 11 16.6 7.4 13 0 12 7.4 11 11 7.4 12 0Z"/></svg>
    <svg class="lp-flower lp-flower-a" viewBox="0 0 40 40"><g><ellipse cx="20" cy="9" rx="6" ry="8.5"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(72 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(144 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(216 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(288 20 20)"/></g><circle cx="20" cy="20" r="4.5"/></svg>
    <svg class="lp-flower lp-flower-b" viewBox="0 0 40 40"><g><ellipse cx="20" cy="9" rx="6" ry="8.5"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(72 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(144 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(216 20 20)"/><ellipse cx="20" cy="9" rx="6" ry="8.5" transform="rotate(288 20 20)"/></g><circle cx="20" cy="20" r="4.5"/></svg>
  </div>
  <div class="lp-wrap lp-hero-in">
    <div class="lp-hero-text">
      <span class="lp-eyebrow"><span class="lp-pulse"></span><?= e(L::get('hero_badge')) ?></span>
      <h1 class="lp-title"><?= $titleHtml ?></h1>
      <p class="lp-lead"><?= e(L::get('hero_text')) ?></p>
      <div class="lp-hero-cta">
        <a class="lp-btn lp-btn-primary lp-btn-lg lp-btn-shine" href="<?= e($cta) ?>"><?= e($signup ? L::get('cta_primary') : L::get('cta_secondary')) ?><?= icon('chevron-left', 'lp-arrow') ?></a>
        <?php if ($video !== ''): ?>
        <a class="lp-btn lp-btn-soft lp-btn-lg" href="https://www.youtube.com/watch?v=<?= e($video) ?>" target="_blank" rel="noopener" data-lp-video="<?= e($video) ?>"><span class="lp-play"><?= icon('play') ?></span>شاهد العرض</a>
        <?php elseif ($signup): ?>
        <a class="lp-btn lp-btn-soft lp-btn-lg" href="<?= e(url('/login')) ?>"><?= icon('log-in') ?><?= e(L::get('cta_secondary')) ?></a>
        <?php endif; ?>
      </div>
      <?php if ($signup): ?><div class="lp-note"><?= icon('circle-check') ?><span><?= e(L::get('trial_note')) ?></span></div><?php endif; ?>
    </div>

    <div class="lp-stage" aria-hidden="true" data-lp-tilt>
      <div class="lp-window">
        <div class="lp-window-bar"><i></i><i></i><i></i><span class="lp-window-url" dir="ltr">MADIX NEXUS · <b>Dashboard</b></span></div>
        <img class="lp-shot" src="<?= e($shot('dash', true)) ?>" srcset="<?= e($shot('dash', true)) ?> 1080w, <?= e($shot('dash')) ?> 2160w" sizes="(min-width: 1000px) 640px, 94vw" width="2160" height="1350" alt="" fetchpriority="high" decoding="async">
      </div>
      <div class="lp-phone-mock">
        <div class="lp-phone-screen">
          <div class="lp-phone-status" dir="ltr"><span>9:41</span><em></em><s><b></b><b></b><b></b><b></b></s></div>
          <img src="<?= e($shot('phone')) ?>" width="780" height="1328" alt="" decoding="async">
        </div>
      </div>
      <div class="lp-float lp-float-live"><span class="lp-float-icon is-green"><?= icon('activity') ?></span><span><small>المتصلون الآن</small><b dir="ltr">124</b><em><i></i>مباشر الآن</em></span></div>
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
      <div class="lp-stat" data-lp-anim style="--i:<?= $i ?>"><b data-lp-count><?= e($num) ?></b><span><?= e($label) ?></span></div>
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

<section class="lp-section lp-section-tint" id="tour">
  <div class="lp-wrap">
    <div class="lp-head">
      <span class="lp-kicker"><?= icon('monitor') ?>جولة في النظام</span>
      <h2>شاهد النظام من الداخل</h2>
      <p>لقطات حقيقية من النظام كما يراها أصحاب الشبكات كل يوم.</p>
    </div>
    <div class="lp-tour" data-lp-tour>
      <div class="lp-tabs" role="tablist" aria-label="جولة في النظام">
        <?php foreach ($tour as $i => [$key, $ic, $tab]): ?>
        <button type="button" class="lp-tab" role="tab" id="lp-tab-<?= $key ?>" aria-controls="lp-panel-<?= $key ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= icon($ic) ?><span><?= e($tab) ?></span></button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($tour as $i => [$key, $ic, $tab, $head, $text, $points]): ?>
      <div class="lp-panel" role="tabpanel" id="lp-panel-<?= $key ?>" aria-labelledby="lp-tab-<?= $key ?>">
        <div class="lp-panel-text">
          <h3><?= e($head) ?></h3>
          <p><?= e($text) ?></p>
          <ul><?php foreach ($points as $pt): ?><li><?= icon('check') ?><span><?= e($pt) ?></span></li><?php endforeach; ?></ul>
        </div>
        <figure class="lp-panel-shot">
          <div class="lp-window-bar"><i></i><i></i><i></i></div>
          <img src="<?= e($shot($key, true)) ?>" srcset="<?= e($shot($key, true)) ?> 800w, <?= e($shot($key)) ?> 1600w" sizes="(min-width: 1000px) 760px, 94vw" width="1600" height="1000" alt="<?= e($head) ?>" loading="lazy" decoding="async">
        </figure>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($steps): ?>
<section class="lp-section" id="steps">
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
<section class="lp-section lp-section-tint" id="pricing">
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
        <a class="lp-btn <?= $hot ? 'lp-btn-primary lp-btn-shine' : 'lp-btn-soft' ?> lp-btn-block" href="<?= e($cta) ?>"><?= e($signup ? L::get('cta_primary') : L::get('cta_secondary')) ?></a>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($apps): ?>
<section class="lp-section" id="apps">
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
        <div class="lp-laptop-screen"><img src="<?= e($shot('dash', true)) ?>" width="1080" height="675" alt="" loading="lazy" decoding="async"></div>
        <div class="lp-laptop-base"></div>
      </div>
      <div class="lp-phone-mock lp-phone-sm">
        <div class="lp-phone-screen">
          <div class="lp-phone-status" dir="ltr"><span>9:41</span><em></em><s><b></b><b></b><b></b><b></b></s></div>
          <img src="<?= e($shot('phone')) ?>" width="780" height="1328" alt="" loading="lazy" decoding="async">
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($faq): ?>
<section class="lp-section lp-section-tint" id="faq">
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
      <svg class="lp-spark lp-cta-spark" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 0C13 7.4 16.6 11 24 12 16.6 13 13 16.6 12 24 11 16.6 7.4 13 0 12 7.4 11 11 7.4 12 0Z"/></svg>
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
    <nav class="lp-footer-col" aria-label="روابط">
      <h4>الصفحة</h4>
      <?php foreach ($links as $id => $label): ?><a href="#<?= $id ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
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
<a class="lp-top" href="#main" aria-label="العودة إلى الأعلى" data-lp-top><?= icon('arrow-up') ?></a>
<?php if ($video !== ''): ?>
<dialog class="lp-video" data-lp-video-dialog aria-label="شاهد العرض">
  <button type="button" class="lp-video-close" data-lp-video-close aria-label="إغلاق"><?= icon('x') ?></button>
  <div class="lp-video-frame" data-lp-video-frame></div>
</dialog>
<?php endif; ?>
