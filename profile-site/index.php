<?php
// الصفحة التعريفية العامة. كل النصوص تُطبع من الخادم مباشرة (وليس عبر JavaScript)
// حتى يقرأها جوجل ويؤرشفها كاملة.

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

// روابط مثل /index.php/x/ تكسر مسارات الملفات: نحوّلها للرابط الصحيح
if ((string) ($_SERVER['PATH_INFO'] ?? '') !== '') {
    header('Location: ' . base_url(), true, 301);
    exit;
}

$c    = content();
$seo  = $c['seo'];
$hero = $c['hero'];
$ct   = $c['contact'];
$base = base_url();
$canonical = $base;
$avatar    = avatar_path();
$avatarAbs = $base . $avatar;
$ogImage   = $base . 'assets/og.png';
$waUrl     = wa_link($ct['whatsapp'], $ct['whatsapp_text']);
$fbUrl     = safe_url($ct['facebook']);
$ttUrl     = safe_url($ct['tiktok']);

track_view();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// البيانات المنظّمة: تعرّف جوجل أن هذه الصفحة هي الملف الشخصي لصهيب قديح
// وتربطها بحساباته (sameAs)، وتذكر كل أشكال كتابة الاسم (alternateName).
$sameAs = array_values(array_filter([$fbUrl, $ttUrl]));
$person = [
    '@type'         => 'Person',
    '@id'           => $canonical . '#person',
    'name'          => $hero['name'],
    'honorificPrefix' => 'م.',
    'alternateName' => array_values(array_unique(array_merge([$hero['name_en']], lines_of($seo['alt_names'])))),
    'jobTitle'      => $hero['headline'],
    'description'   => $c['about']['text'],
    'url'           => $canonical,
    'image'         => $avatarAbs,
    'sameAs'        => $sameAs,
    'knowsAbout'    => array_values(array_filter(array_map(function ($s) { return $s['title']; }, $c['services']['items']))),
];
if (digits($ct['whatsapp']) !== '') {
    $person['telephone'] = '+' . digits($ct['whatsapp']);
}
$madix = null;
foreach ($c['projects']['items'] as $p) {
    if (stripos((string) $p['name'], 'MADIX') !== false && safe_url((string) $p['url']) !== '') {
        $madix = ['@type' => 'Organization', 'name' => $p['name'], 'url' => safe_url((string) $p['url'])];
        break;
    }
}
if ($madix) {
    $person['worksFor'] = $madix + ['founder' => ['@id' => $canonical . '#person']];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'      => 'ProfilePage',
            '@id'        => $canonical . '#page',
            'url'        => $canonical,
            'name'       => $seo['title'],
            'inLanguage' => 'ar',
            'dateCreated'  => '2026-10-19T00:00:00+03:00',
            'dateModified' => date('c', (int) (@filemtime(store_path('content')) ?: @filemtime(__FILE__) ?: time())),
            'isPartOf'   => ['@id' => $canonical . '#website'],
            'mainEntity' => ['@id' => $canonical . '#person'],
        ],
        $person,
        [
            '@type'      => 'WebSite',
            '@id'        => $canonical . '#website',
            'url'        => $canonical,
            'name'       => $hero['name'],
            'alternateName' => [$hero['name_en']],
            'inLanguage' => 'ar',
        ],
    ],
];

$codeLines = preg_split('/\R/u', $c['code']['lines']) ?: [];
$nameParts = preg_split('/\s+/u', trim($hero['name']), 2) ?: [$hero['name']];
$chips     = lines_of($c['about']['chips']);
$year      = date('Y');
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($seo['title']) ?></title>
<meta name="description" content="<?= e($seo['description']) ?>">
<meta name="keywords" content="<?= e($seo['keywords']) ?>">
<meta name="author" content="<?= e($hero['name']) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<?php if (trim($seo['google_verification']) !== ''): ?>
<meta name="google-site-verification" content="<?= e(trim($seo['google_verification'])) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<meta name="theme-color" content="#070b14">
<meta name="color-scheme" content="dark">

<meta property="og:type" content="profile">
<meta property="og:site_name" content="<?= e($hero['name']) ?>">
<meta property="og:locale" content="ar_AR">
<meta property="og:title" content="<?= e($seo['title']) ?>">
<meta property="og:description" content="<?= e($seo['description']) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($hero['name'] . ' – ' . $hero['badge']) ?>">
<meta property="profile:first_name" content="<?= e($nameParts[0]) ?>">
<?php if (!empty($nameParts[1])): ?><meta property="profile:last_name" content="<?= e($nameParts[1]) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($seo['title']) ?>">
<meta name="twitter:description" content="<?= e($seo['description']) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('assets/icon-192.png')) ?>" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="<?= e(asset('assets/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php $fonts = 'https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap'; ?>
<link rel="stylesheet" href="<?= e($fonts) ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?= e($fonts) ?>"></noscript>
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
<link rel="preload" as="image" href="<?= e(asset($avatar)) ?>">

<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>document.documentElement.classList.add('js');setTimeout(function(){if(!window.__revealOk)document.documentElement.classList.remove('js')},2500)</script>
</head>
<body>
<canvas id="bg-code" aria-hidden="true"></canvas>
<div class="bg-grid" aria-hidden="true"></div>
<div class="bg-orb bg-orb--a" aria-hidden="true"></div>
<div class="bg-orb bg-orb--b" aria-hidden="true"></div>

<a class="skip" href="#main">تخطَّ إلى المحتوى</a>

<header class="nav" id="top">
  <div class="wrap nav__in">
    <a class="brand" href="#top" aria-label="<?= e($hero['name']) ?>">
      <img src="<?= e(asset($avatar)) ?>" alt="" width="36" height="36">
      <span><?= e($hero['name']) ?></span>
    </a>
    <nav class="nav__links" aria-label="الأقسام">
      <a href="#about"><?= e($c['nav']['about']) ?></a>
      <a href="#services"><?= e($c['nav']['services']) ?></a>
      <a href="#projects"><?= e($c['nav']['projects']) ?></a>
      <a href="#contact"><?= e($c['nav']['contact']) ?></a>
    </nav>
    <?php if ($waUrl): ?>
    <a class="btn btn--sm btn--wa" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><?= icon('whatsapp') ?><span><?= e($c['nav']['cta']) ?></span></a>
    <?php endif; ?>
  </div>
</header>

<main id="main">

  <!-- الواجهة -->
  <section class="hero wrap">
    <div class="hero__text">
      <div class="avatar reveal">
        <div class="avatar__ring"></div>
        <img src="<?= e(asset($avatar)) ?>" alt="<?= e($hero['name'] . ' – ' . $hero['name_en']) ?>" width="132" height="132">
        <?php if ($hero['status'] !== ''): ?>
        <span class="avatar__status" title="<?= e($hero['status']) ?>"></span>
        <?php endif; ?>
      </div>

      <p class="hero__hello reveal"><?= e($hero['hello']) ?> <?php if ($hero['status'] !== ''): ?><span class="pill"><i></i><?= e($hero['status']) ?></span><?php endif; ?></p>
      <h1 class="hero__name reveal">
        <span class="grad"><?= e($hero['name']) ?></span>
        <span class="hero__en" lang="en" dir="ltr"><?= e($hero['name_en']) ?></span>
      </h1>

      <?php if ($hero['badge'] !== ''): ?>
      <p class="badge reveal"><span class="badge__in"><?= icon('crown') ?><strong><?= e($hero['badge']) ?></strong></span></p>
      <?php endif; ?>

      <h2 class="hero__headline reveal"><?= e($hero['headline']) ?></h2>
      <p class="hero__sub reveal"><?= e($hero['subline']) ?></p>

      <div class="hero__cta reveal">
        <?php if ($waUrl): ?>
        <a class="btn btn--primary" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><?= icon('whatsapp') ?><span><?= e($hero['btn_primary']) ?></span></a>
        <?php endif; ?>
        <a class="btn btn--ghost" href="#projects"><span><?= e($hero['btn_secondary']) ?></span><?= icon('arrow-up-left') ?></a>
      </div>

      <ul class="social reveal" aria-label="حساباتي">
        <?php if ($waUrl): ?><li><a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" aria-label="<?= e($ct['whatsapp_label']) ?>" data-track="whatsapp" class="s-wa"><?= icon('whatsapp') ?></a></li><?php endif; ?>
        <?php if ($fbUrl): ?><li><a href="<?= e($fbUrl) ?>" target="_blank" rel="noopener me" aria-label="<?= e($ct['facebook_label']) ?>" data-track="facebook" class="s-fb"><?= icon('facebook') ?></a></li><?php endif; ?>
        <?php if ($ttUrl): ?><li><a href="<?= e($ttUrl) ?>" target="_blank" rel="noopener me" aria-label="<?= e($ct['tiktok_label']) ?>" data-track="tiktok" class="s-tt"><?= icon('tiktok') ?></a></li><?php endif; ?>
      </ul>
    </div>

    <div class="hero__code reveal">
      <div class="term" dir="ltr">
        <div class="term__bar">
          <span class="dot dot--r"></span><span class="dot dot--y"></span><span class="dot dot--g"></span>
          <span class="term__file"><?= icon('code-xml') ?><?= e($c['code']['filename']) ?></span>
        </div>
        <pre class="term__body" aria-label="<?= e($hero['name_en']) ?>"><code id="code-out"><?php foreach ($codeLines as $i => $line): ?><span class="ln" data-n="<?= $i + 1 ?>"><?= e($line) ?></span><?php endforeach; ?></code></pre>
      </div>
      <script type="application/json" id="code-src"><?= json_encode(array_values($codeLines), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <div class="chips-float" aria-hidden="true">
        <span class="fchip fchip--1">&lt;POS/&gt;</span>
        <span class="fchip fchip--2">{ AI }</span>
        <span class="fchip fchip--3">SaaS()</span>
      </div>
    </div>
  </section>

  <!-- نبذة -->
  <section class="section wrap" id="about">
    <div class="about card reveal">
      <p class="kicker"><?= e($c['about']['kicker']) ?></p>
      <h2 class="title"><?= e($c['about']['title']) ?></h2>
      <p class="about__lead"><?= e($c['about']['lead']) ?></p>
      <p class="about__text"><?= nl2br(e($c['about']['text'])) ?></p>
      <?php if ($chips): ?>
      <ul class="chips">
        <?php foreach ($chips as $chip): ?><li><?= icon('check') ?><?= e($chip) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </section>

  <!-- الخدمات -->
  <section class="section wrap" id="services">
    <header class="section__head reveal">
      <p class="kicker"><?= e($c['services']['kicker']) ?></p>
      <h2 class="title"><?= e($c['services']['title']) ?></h2>
    </header>
    <div class="grid grid--3">
      <?php foreach ($c['services']['items'] as $s): if (trim($s['title']) === '') continue; ?>
      <article class="svc card reveal">
        <div class="svc__icon"><?= icon(isset(ICONS[$s['icon']]) ? $s['icon'] : 'code-xml') ?></div>
        <h3><?= e($s['title']) ?></h3>
        <p><?= e($s['text']) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- المشاريع -->
  <section class="section wrap" id="projects">
    <header class="section__head reveal">
      <p class="kicker"><?= e($c['projects']['kicker']) ?></p>
      <h2 class="title"><?= e($c['projects']['title']) ?></h2>
    </header>
    <div class="grid grid--3">
      <?php foreach ($c['projects']['items'] as $i => $p): if (trim($p['name']) === '') continue; $url = safe_url($p['url']); ?>
      <article class="proj card reveal">
        <div class="proj__top">
          <div class="proj__icon"><?= icon(isset(ICONS[$p['icon']]) ? $p['icon'] : 'globe') ?></div>
          <?php if ($p['tag'] !== ''): ?><span class="proj__tag"><?= e($p['tag']) ?></span><?php endif; ?>
        </div>
        <h3><?= e($p['name']) ?></h3>
        <p><?= e($p['text']) ?></p>
        <?php if ($url): ?>
        <a class="proj__link" href="<?= e($url) ?>" target="_blank" rel="noopener" data-track="<?= e(project_key($p)) ?>">
          <span><?= e($c['projects']['cta']) ?></span><?= icon('external-link') ?>
          <small dir="ltr"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/'))) ?></small>
        </a>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- تواصل -->
  <section class="section wrap" id="contact">
    <div class="contact card reveal">
      <div class="contact__text">
        <p class="kicker"><?= e($ct['kicker']) ?></p>
        <h2 class="title"><?= e($ct['title']) ?></h2>
        <p><?= e($ct['text']) ?></p>
      </div>
      <div class="contact__links">
        <?php if ($waUrl): ?>
        <a class="clink clink--wa" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp">
          <?= icon('whatsapp') ?><span><b><?= e($ct['whatsapp_label']) ?></b><small dir="ltr"><?= e(phone_display($ct['whatsapp'])) ?></small></span>
        </a>
        <?php endif; ?>
        <?php if ($fbUrl): ?>
        <a class="clink clink--fb" href="<?= e($fbUrl) ?>" target="_blank" rel="noopener me" data-track="facebook">
          <?= icon('facebook') ?><span><b><?= e($ct['facebook_label']) ?></b><small><?= e($hero['name']) ?></small></span>
        </a>
        <?php endif; ?>
        <?php if ($ttUrl): ?>
        <a class="clink clink--tt" href="<?= e($ttUrl) ?>" target="_blank" rel="noopener me" data-track="tiktok">
          <?= icon('tiktok') ?><span><b><?= e($ct['tiktok_label']) ?></b><small dir="ltr"><?= e(preg_match('#/(@[^/?\#]+)#', $ttUrl, $m) ? $m[1] : 'TikTok') ?></small></span>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <div class="wrap footer__in">
    <p class="footer__name">© <?= e($year) ?> <strong><?= e($c['footer']['name']) ?></strong> · <?= e($c['footer']['rights']) ?></p>
    <?php if ($c['footer']['en_line'] !== ''): ?><p class="footer__en" lang="en" dir="ltr"><?= e($c['footer']['en_line']) ?></p><?php endif; ?>
  </div>
</footer>

<script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
