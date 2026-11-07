<?php
// الصفحة التعريفية العامة. كل النصوص تُطبع من الخادم مباشرة (وليس عبر JavaScript)
// حتى يقرأها جوجل ويؤرشفها كاملة. الإنجليزية هي الأساس، والعربية عبر ?lang=ar (زر AR)

declare(strict_types=1);
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/ui.php';

// روابط مثل /index.php/x/ تكسر مسارات الملفات: نحوّلها للرابط الصحيح
if ((string) ($_SERVER['PATH_INFO'] ?? '') !== '') {
    header('Location: ' . base_url(), true, 301);
    exit;
}

$c     = content();
$L     = lang();
$en    = is_en();
$seo   = $c['seo'];
$nav   = $c['nav'];
$hero  = $c['hero'];
$ab    = $c['about'];
$why   = $c['why'];
$svc   = $c['services'];
$sec   = $c['sectors'];
$st    = $c['stats'];
$pj    = $c['projects'];
$rv    = $c['reviews'];
$ct    = $c['contact'];
$ft    = $c['footer'];

$base      = base_url();
$canonical = page_url($L);
$avatarAbs = $base . avatar_path();
$ogImage   = $base . 'assets/og.jpg';
$name      = $en ? $hero['name_en'] : $hero['name'];
$nameEn    = $hero['name_en'];
$title     = plain(tx($seo, 'title'));
$desc      = plain(tx($seo, 'description'));

$waText = tx($ct, 'whatsapp_text');
$waUrl  = wa_link($ct['whatsapp'], $waText);
$phone  = digits($ct['phone']);
$email  = (string) (filter_var(trim($ct['email']), FILTER_VALIDATE_EMAIL) ?: '');
$location = tx($ct, 'location');

// الحسابات (تظهر فقط لو الرابط موجود)
$socials = [];
foreach ([
    'whatsapp'  => $waUrl,
    'facebook'  => safe_url($ct['facebook']),
    'instagram' => safe_url($ct['instagram']),
    'tiktok'    => safe_url($ct['tiktok']),
    'linkedin'  => safe_url($ct['linkedin']),
    'github'    => safe_url($ct['github']),
] as $k => $u) {
    if ($u !== '') {
        $socials[$k] = $u;
    }
}

$aboutCustom = about_photo_path();

// صورة الواجهة: المرفوعة من اللوحة، أو الصورة الافتراضية المقصوصة
$heroCustom = hero_photo_path();
if ($heroCustom !== '') {
    $hs = @getimagesize(APP_ROOT . '/' . $heroCustom) ?: [800, 1100];
    $heroImg = ['src' => asset($heroCustom), 'small' => asset($heroCustom), 'w' => (int) $hs[0], 'h' => (int) $hs[1], 'webp' => ''];
} else {
    $heroImg = ['src' => asset('assets/img/hero.png'), 'small' => asset('assets/img/hero-640.webp'), 'w' => 1061, 'h' => 1254,
        'webp' => asset('assets/img/hero-640.webp') . ' 640w, ' . asset('assets/img/hero.webp') . ' 1061w'];
}
function hero_picture(array $img, string $class, string $alt, string $sizes, string $loading = 'lazy', bool $small = false): string
{
    $h = '<picture class="' . e($class) . '">';
    if ($img['webp'] !== '') {
        $h .= $small
            ? '<source type="image/webp" srcset="' . e($img['small']) . '">'
            : '<source type="image/webp" srcset="' . e($img['webp']) . '" sizes="' . e($sizes) . '">';
    }
    $h .= '<img src="' . e($img['src']) . '" width="' . $img['w'] . '" height="' . $img['h'] . '" alt="' . e($alt) . '" loading="' . $loading . '" decoding="async"'
        . ($loading === 'eager' ? ' fetchpriority="high"' : '') . '></picture>';
    return $h;
}

function wave_svg(string $pos): string
{
    $d = 'M0 38 C240 8 480 8 720 34 C960 60 1200 62 1440 38 C1680 8 1920 8 2160 34 C2400 60 2640 62 2880 38 L2880 72 L0 72 Z';
    $d2 = 'M0 46 C300 20 560 18 820 40 C1080 62 1260 60 1440 46 C1740 20 2000 18 2260 40 C2520 62 2700 60 2880 46 L2880 72 L0 72 Z';
    return '<div class="wave wave--' . $pos . '" aria-hidden="true"><svg viewBox="0 0 2880 72" preserveAspectRatio="none">'
        . '<path class="wave__back" d="' . $d2 . '"/><path class="wave__front" d="' . $d . '"/></svg></div>';
}

track_view();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Language: ' . $L);

// البيانات المنظّمة: تعرّف جوجل أن هذه الصفحة هي الملف الشخصي لصهيب شوقي
// وتربطها بحساباته (sameAs)، وتذكر كل أشكال كتابة الاسم (alternateName).
$sameAs = array_values(array_filter($socials, function ($k) { return $k !== 'whatsapp'; }, ARRAY_FILTER_USE_KEY));
$aboutText = implode(' ', paragraphs(tx($ab, 'text')));
$person = [
    '@type'         => 'Person',
    '@id'           => $base . '#person',
    'name'          => $name,
    'alternateName' => array_values(array_unique(array_merge([$en ? $hero['name'] : $nameEn], lines_of($seo['alt_names'])))),
    'jobTitle'      => tx($hero, 'headline'),
    'description'   => $aboutText,
    'url'           => $base,
    'image'         => $avatarAbs,
    'sameAs'        => $sameAs,
    'knowsAbout'    => array_values(array_filter(array_map(function ($s) { return tx($s, 'title'); }, $svc['items']))),
];
if (digits($ct['whatsapp']) !== '') {
    $person['telephone'] = '+' . digits($ct['whatsapp']);
}
if ($email !== '') {
    $person['email'] = 'mailto:' . $email;
}
foreach ($pj['items'] as $p) {
    if (stripos((string) $p['name'], 'MADIX') !== false && safe_url((string) $p['url']) !== '') {
        $person['worksFor'] = ['@type' => 'Organization', 'name' => $p['name'], 'url' => safe_url((string) $p['url']), 'founder' => ['@id' => $base . '#person']];
        break;
    }
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'        => 'ProfilePage',
            '@id'          => $canonical . '#page',
            'url'          => $canonical,
            'name'         => $title,
            'inLanguage'   => $L,
            'dateCreated'  => '2026-10-19T00:00:00+03:00',
            'dateModified' => date('c', (int) (@filemtime(store_path('content')) ?: @filemtime(__FILE__) ?: time())),
            'isPartOf'     => ['@id' => $base . '#website'],
            'mainEntity'   => ['@id' => $base . '#person'],
        ],
        $person,
        [
            '@type'         => 'WebSite',
            '@id'           => $base . '#website',
            'url'           => $base,
            'name'          => $hero['name'],
            'alternateName' => [$nameEn],
            'inLanguage'    => ['ar', 'en'],
        ],
    ],
];

$nameParts  = preg_split('/\s+/u', trim($nameEn)) ?: [$nameEn];
$ribbon     = lines_of(tx($hero, 'ribbon'));
$tools      = [];
foreach (lines_of(tx($ab, 'tools')) as $line) {
    $parts = array_map('trim', explode('|', $line, 2));
    $tools[] = ['code' => $parts[0], 'name' => $parts[1] ?? $parts[0]];
}
$chips      = lines_of(tx($ab, 'chips'));
$services   = array_values(array_filter($svc['items'], function ($s) { return trim($s['title']) !== ''; }));
$sectors    = array_values(array_filter($sec['items'], function ($s) { return trim($s['name']) !== ''; }));
$stats      = array_values(array_filter($st['items'], function ($s) { return trim($s['num']) !== ''; }));
$projects   = array_values(array_filter($pj['items'], function ($p) { return trim($p['name']) !== ''; }));
$reviews    = array_values(array_filter($rv['items'], function ($r) { return trim($r['quote']) !== '' && trim($r['name']) !== ''; }));
$whyItems   = array_values(array_filter($why['items'], function ($w) { return trim($w['title']) !== ''; }));
$year       = date('Y');
$otherLang  = $en ? 'ar' : 'en';
$iconOk     = function (string $i, string $fallback): string { return isset(ICONS[$i]) ? $i : $fallback; };

// خط Fraunces يُحمَّل فقط بالأحرف المستعملة فعلاً (الاسم الكبير والأرقام ورموز الأدوات): ملف صغير جداً
$frChars = $nameEn . strtoupper($nameEn) . $hero['loader_word'] . '0123456789+/%.,·';
foreach ($tools as $t) {
    $frChars .= $t['code'];
}
foreach ($stats as $s) {
    $frChars .= $s['num'] . $s['suffix'];
}
$uniq = function (string $s): string { return implode('', array_unique(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [])); };
$frChars = $uniq($frChars);
$fontLinks = [];
if ($en) {
    // الإنجليزية: Fraunces للعناوين والاسم (مع المائل للكلمات المميّزة) و Plus Jakarta Sans للنصوص
    $fontLinks[] = 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500..900;1,9..144,500..900&family=Plus+Jakarta+Sans:wght@400..800&display=swap';
} else {
    // العربية: Cairo لكل الصفحة، و Fraunces فقط لأحرف الاسم الكبير والأرقام (ملف صغير جداً)
    $fontLinks[] = 'https://fonts.googleapis.com/css2?family=Cairo:wght@400..900&display=swap';
    $fontLinks[] = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800&display=swap&text=' . rawurlencode($frChars);
}
// Lalezar فقط لأحرف الكلمات الكبيرة على بطاقات الخدمات
$covers = '';
foreach ($services as $s) {
    $covers .= $s['cover'];
}
if (trim($covers) !== '') {
    $fontLinks[] = 'https://fonts.googleapis.com/css2?family=Lalezar&display=swap&text=' . rawurlencode($uniq($covers));
}
$langHref = $otherLang === 'ar' ? './?lang=ar' : './';
// السرعة الفائقة: الخطوط من الموقع نفسه (مع تحميل مسبق) بدل جوجل، والملفات المضغوطة
$speedOn = feature_on('speed') && is_file(APP_ROOT . '/assets/fonts/fonts.css');
$preloadFonts = [];
if ($speedOn) {
    $fontLinks = [];
    $preloadFonts = $en
        ? ['fraunces-latin-normal-500-900.woff2', 'plusjakartasans-latin-normal-400-800.woff2']
        : ['cairo-arabic-normal-400-900.woff2', 'fraunces-latin-normal-500-900.woff2'];
}
// الملف المضغوط (min) يحمل بصمة الملف الأصلي في أول سطر؛ يُستعمل فقط لو كانت البصمة مطابقة،
// فلو عُدّل الأصلي لاحقاً يُستعمل الأصلي تلقائياً ولا يظهر تصميم قديم أبداً
$pickAsset = function (string $src, string $min) use ($speedOn): string {
    $s = APP_ROOT . '/' . $src;
    $m = APP_ROOT . '/' . $min;
    if (!$speedOn || !is_file($m) || !is_file($s)) {
        return $src;
    }
    $head = (string) @file_get_contents($m, false, null, 0, 60);
    return (preg_match('/^\/\*src:([a-f0-9]{32})\*\//', $head, $mm) && hash_equals($mm[1], (string) md5_file($s))) ? $min : $src;
};
$palette = current_palette();
$palInfo = palette_list()[$palette];
if (!$speedOn && feature_on('code') && trim((string) ($c['code']['lines'] ?? '')) !== '') {
    $fontLinks[] = 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&display=swap';
}

$navItems = [['home', '#home', tx($nav, 'home')], ['about', '#about', tx($nav, 'about')], ['services', '#services', tx($nav, 'services')]];
if ($sectors) {
    $navItems[] = ['sectors', '#sectors', tx($nav, 'sectors')];
}
if ($projects) {
    $navItems[] = ['projects', '#projects', tx($nav, 'projects')];
}
$navItems[] = ['contact', '#contact', tx($nav, 'contact')];

$fx = [];
foreach (array_keys(feature_list()) as $k) {
    $fx[$k] = feature_on($k);
}
$codeData  = $c['code'] ?? ['filename' => '', 'lines' => '', 'output' => '', 'output_en' => ''];
$codeLines = preg_split('/\R/u', rtrim((string) $codeData['lines'])) ?: [];
$fx['code'] = $fx['code'] && trim((string) $codeData['lines']) !== '';
$fx['orbit'] = $fx['orbit'] && count($tools) > 0;
$heroMask = $heroImg['webp'] !== '' ? asset('assets/img/hero-640.webp') : $heroImg['src'];

foreach (['speed', 'waves', 'progress', 'aurora', 'smooth', 'bigtext', 'share', 'analytics'] as $k) {
    $fx[$k] = feature_on($k);
}
$appData = [
    'fx'    => $fx,
    'share' => ['url' => page_url($L), 'title' => $title, 'qr' => asset('assets/vendor/qrcode.min.js'), 'file' => 'qr-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($nameEn)) . '.png'],
    'lang'  => $L,
    'wa'    => digits($ct['whatsapp']),
    'ui'    => [
        'head' => ui('wa_head'), 'name' => ui('form_name'), 'wa' => ui('form_wa'), 'email' => ui('form_email'),
        'company' => ui('form_company'), 'service' => ui('form_service'), 'budget' => ui('form_budget'),
        'time' => ui('form_time'), 'details' => ui('form_details'), 'ok' => ui('form_ok'), 'saved' => ui('form_saved'),
        'err' => ui('form_err'), 'limit' => ui('form_limit'), 'less' => ui('less'),
        'copy' => ui('copy'), 'copied' => ui('copied'),
    ],
];
?><!doctype html>
<html lang="<?= $L ?>" dir="<?= $en ? 'ltr' : 'rtl' ?>" class="no-js notranslate" translate="no" data-palette="<?= e($palette) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="keywords" content="<?= e($seo['keywords']) ?>">
<meta name="author" content="<?= e($name) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<!-- منع الترجمة التلقائية من المتصفح: الترجمة فقط من زر اللغة في الصفحة -->
<meta name="google" content="notranslate">
<?php if (trim($seo['google_verification']) !== ''): ?>
<meta name="google-site-verification" content="<?= e(trim($seo['google_verification'])) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="alternate" hreflang="ar" href="<?= e(page_url('ar')) ?>">
<link rel="alternate" hreflang="en" href="<?= e(page_url('en')) ?>">
<link rel="alternate" hreflang="x-default" href="<?= e(page_url('en')) ?>">
<meta name="theme-color" content="<?= e($palInfo[2]) ?>">
<meta name="color-scheme" content="light">

<meta property="og:type" content="profile">
<meta property="og:site_name" content="<?= e($name) ?>">
<meta property="og:locale" content="<?= $en ? 'en_US' : 'ar_AR' ?>">
<meta property="og:locale:alternate" content="<?= $en ? 'ar_AR' : 'en_US' ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($name . ' – ' . tx($hero, 'badge')) ?>">
<meta property="profile:first_name" content="<?= e($nameParts[0]) ?>">
<?php if (!empty($nameParts[1])): ?><meta property="profile:last_name" content="<?= e($nameParts[1]) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">

<link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('assets/icon-192.png')) ?>" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="<?= e(asset('assets/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">

<?php if ($speedOn): ?>
<?php foreach ($preloadFonts as $pf): ?>
<link rel="preload" as="font" type="font/woff2" href="<?= e('assets/fonts/' . $pf) ?>" crossorigin>
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('assets/fonts/fonts.css')) ?>">
<?php else: ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php endif; ?>
<?php foreach ($fontLinks as $f): ?>
<link rel="stylesheet" href="<?= e($f) ?>" media="print" onload="this.media='all'">
<?php endforeach; ?>
<noscript><?php foreach ($fontLinks as $f): ?><link rel="stylesheet" href="<?= e($f) ?>"><?php endforeach; ?></noscript>
<link rel="stylesheet" href="<?= e(asset($pickAsset('assets/style.css', 'assets/style.min.css'))) ?>">
<?php if ($heroImg['webp'] !== ''): ?>
<link rel="preload" as="image" type="image/webp" imagesrcset="<?= e($heroImg['webp']) ?>" imagesizes="(max-width: 760px) 90vw, 560px">
<?php endif; ?>

<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function(d){var h=d.documentElement,t=null;h.className=h.className.replace('no-js','js');
try{t=localStorage.getItem('sq-theme')}catch(e){}
if(t!=='dark')t='light';
h.setAttribute('data-theme',t);
var r=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches,s=null;
try{s=sessionStorage.getItem('sq-loaded')}catch(e){}
if(!r)h.classList.add(s?'is-quick':'is-loading');
setTimeout(function(){if(!window.__appOk){h.classList.remove('is-loading','is-quick');h.classList.add('is-ready','no-reveal')}},5000);
})(document);
</script>
</head>
<body>

<!-- الشاشة الافتتاحية: تظهر أول ما يفتح الموقع، ثم تنسحب للأعلى بعد 3 ثوانٍ -->
<div class="loader" aria-hidden="true">
  <div class="loader__glow"></div>
  <p class="loader__corner loader__corner--a" dir="ltr">PORTFOLIO</p>
  <p class="loader__corner loader__corner--b" dir="ltr">© <?= e($year) ?></p>
  <div class="loader__in">
    <?= logo_mark('mark loader__mark') ?>
    <p class="loader__word" dir="ltr"><?php foreach (preg_split('//u', $hero['loader_word'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $ch): ?><span style="--i:<?= $i ?>"><?= $ch === ' ' ? '&nbsp;' : e($ch) ?></span><?php endforeach; ?></p>
    <p class="loader__tag" dir="ltr"><i></i><span><?= e($hero['loader_tag']) ?></span><i></i></p>
  </div>
  <div class="loader__foot" dir="ltr"><span class="loader__bar"><i></i></span><b class="loader__count">0</b><small>%</small></div>
</div>

<a class="skip" href="#main"><?= e(ui('skip')) ?></a>

<!-- الشريط العلوي العائم -->
<header class="nav" id="top">
  <a class="nav__logo" href="#home" aria-label="<?= e($name) ?>"><?= logo_mark('mark') ?><span dir="ltr"><?= e(strtoupper($nameEn)) ?></span></a>
  <nav class="nav__links" id="nav-links" aria-label="<?= e(ui('sections')) ?>">
    <span class="nav__ind" aria-hidden="true"></span>
    <?php foreach ($navItems as $i => [$key, $href, $label]): ?>
    <a href="<?= e($href) ?>" data-spy="<?= e($key) ?>"<?= $i === 0 ? ' class="is-active" aria-current="true"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($waUrl): ?>
    <a class="nav__menu-cta" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><?= icon('whatsapp') ?><span><?= e(tx($nav, 'cta')) ?></span></a>
    <?php endif; ?>
  </nav>
  <div class="nav__tools">
    <?php if ($fx['share']): ?><button class="nav__share" type="button" data-share aria-label="<?= e(ui('share')) ?>" title="<?= e(ui('share')) ?>"><?= icon('share-2') ?></button><?php endif; ?>
    <button class="nav__theme" type="button" aria-label="<?= e(ui('theme')) ?>" title="<?= e(ui('theme')) ?>"><?= icon('sun', 'i i-sun') ?><?= icon('moon', 'i i-moon') ?></button>
    <a class="nav__lang" href="<?= e($langHref) ?>" hreflang="<?= $otherLang ?>" lang="<?= $otherLang ?>" aria-label="<?= e(ui('lang_code') . ' – ' . ui('lang_name')) ?>" data-track="lang"><span><?= e(ui('lang_code')) ?></span><?= flag_svg($otherLang) ?></a>
    <?php if ($waUrl): ?>
    <a class="nav__cta" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><?= icon('whatsapp') ?><span><?= e(tx($nav, 'cta')) ?></span></a>
    <?php endif; ?>
    <button class="nav__burger" type="button" aria-label="<?= e(ui('menu')) ?>" aria-expanded="false" aria-controls="nav-links"><span></span><span></span><span></span></button>
  </div>
</header>

<?php if ($socials): ?>
<!-- أيقونات الحسابات الثابتة على جانب الشاشة -->
<ul class="rail" aria-label="<?= e(ui('socials')) ?>">
  <?php foreach ($socials as $k => $u): ?>
  <li><a href="<?= e($u) ?>" target="_blank" rel="noopener<?= $k !== 'whatsapp' ? ' me' : '' ?>" aria-label="<?= e(ui($k)) ?>" data-track="<?= e($k) ?>"><?= icon($k) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($fx['progress']): ?>
<div class="readbar" aria-hidden="true"><i></i></div>
<nav class="dots" aria-label="<?= e(ui('sections')) ?>">
  <?php foreach ($navItems as [$key, $href, $label]): ?>
  <a href="<?= e($href) ?>" data-dot="<?= e($key) ?>"><span><?= e($label) ?></span><i></i></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<main id="main">

  <!-- ───────── الواجهة ───────── -->
  <section class="hero" id="home" data-section="home" data-read="home"<?= $heroCustom !== '' && $heroImg['h'] > 0 ? ' style="--ratio:' . round($heroImg['w'] / $heroImg['h'], 4) . '"' : '' ?>>
    <div class="hero__bg" aria-hidden="true"></div>
    <?php if ($fx['aurora']): ?><div class="aurora aurora--hero" aria-hidden="true"><i></i><i></i><i></i></div><?php endif; ?>
    <h1 class="hero__name"><span class="sr-only"><?= e($hero['name']) ?> – </span><span class="hero__word" dir="ltr" lang="en"><?php foreach ($nameParts as $wi => $word): ?><span class="hero__w"><?php foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ci => $ch): ?><span class="hero__ch" style="--i:<?= $wi * 6 + $ci ?>"><?= e($ch) ?></span><?php endforeach; ?></span><?= $wi < count($nameParts) - 1 ? ' ' : '' ?><?php endforeach; ?></span></h1>

    <div class="hero__photo">
      <div class="hero__tilt">
        <span class="hero__halo" aria-hidden="true"></span>
        <?= hero_picture($heroImg, 'hero__pic', ui('photo_alt') . ' – ' . $nameEn, '(max-width: 760px) 90vw, 560px', 'eager') ?>
        <?php if ($fx['tilt']): ?><span class="hero__glare" aria-hidden="true" style="-webkit-mask-image:url('<?= e($heroMask) ?>');mask-image:url('<?= e($heroMask) ?>')"></span><?php endif; ?>
      </div>
    </div>

    <?= ribbon_html($ribbon, 'tilt') ?>

    <div class="hero__copy hero__copy--a">
      <p class="hero__hello"><?= e(tx($hero, 'hello')) ?> <strong><?= e($name) ?></strong></p>
      <h2 class="hero__title"><span><?= accent(tx($hero, 'title_a')) ?></span> <span class="hero__title-m"><?= accent(tx($hero, 'title_b')) ?></span></h2>
      <?php if (tx($hero, 'status') !== ''): ?>
      <p class="hero__status"><i aria-hidden="true"></i><?= e(tx($hero, 'status')) ?></p>
      <?php endif; ?>
      <p class="hero__intro"><?= e(tx($hero, 'intro')) ?></p>
      <div class="hero__btns">
        <a class="btn" href="#contact"><span><?= e(tx($hero, 'btn_primary')) ?></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i btn__arrow') ?></a>
        <a class="btn btn--alt" href="#projects"><span><?= e(tx($hero, 'btn_secondary')) ?></span></a>
      </div>
    </div>

    <div class="hero__copy hero__copy--b">
      <p class="hero__title"><?= accent(tx($hero, 'title_b')) ?></p>
      <?php if (tx($hero, 'badge') !== ''): ?>
      <p class="hero__badge"><?= icon('crown') ?><span><?= e(tx($hero, 'badge')) ?></span></p>
      <?php endif; ?>
    </div>

    <?= ribbon_html($ribbon, 'flat') ?>
  </section>

  <!-- ───────── نبذة ───────── -->
  <section class="section" id="about" data-section="about" data-read="about">
    <?php if ($fx['aurora']): ?><div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div><?php endif; ?>
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($ab, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($ab, 'title')) ?></h2>
        <span class="head__line" aria-hidden="true"></span>
      </header>

      <div class="about">
        <figure class="about__photo reveal">
          <div class="blob">
            <span class="blob__glow" aria-hidden="true"></span>
            <span class="blob__ring" aria-hidden="true"></span>
            <span class="blob__ring blob__ring--2" aria-hidden="true"></span>
            <div class="blob__pic">
              <?php if ($aboutCustom !== ''): ?>
              <img src="<?= e(asset($aboutCustom)) ?>" width="900" height="900" alt="<?= e(ui('photo_alt') . ' – ' . tx($hero, 'badge')) ?>" loading="lazy" decoding="async">
              <?php else: ?>
              <picture><source type="image/webp" srcset="<?= e(asset('assets/img/about.webp')) ?>"><img src="<?= e(asset('assets/img/about.jpg')) ?>" width="900" height="900" alt="<?= e(ui('photo_alt') . ' – ' . tx($hero, 'badge')) ?>" loading="lazy" decoding="async"></picture>
              <?php endif; ?>
              <span class="blob__shine" aria-hidden="true"></span>
            </div>
            <?php if ($fx['orbit']): $n = count($tools); ?>
            <div class="orbit" aria-hidden="true">
              <?php foreach ($tools as $ti => $t): ?>
              <span class="orbit__item" style="--a:<?= round($ti * 360 / $n, 2) ?>deg"><b dir="ltr"><?= e($t['code']) ?></b></span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span class="blob__chip blob__chip--a" aria-hidden="true"><?= icon('code-xml') ?></span>
            <span class="blob__chip blob__chip--b" aria-hidden="true"><?= icon('crown') ?><b>MADIX</b></span>
            <?php endif; ?>
          </div>
        </figure>
        <article class="about__card card reveal">
          <?php if (trim($ab['badge_value']) !== ''): ?>
          <p class="pill"><span><?= e(tx($ab, 'badge_label')) ?></span><b dir="ltr"><?= e($ab['badge_value']) ?></b></p>
          <?php endif; ?>
          <h3 class="about__name"><?= accent(tx($ab, 'card_title')) ?></h3>
          <p class="about__lead"><?= e(tx($ab, 'lead')) ?></p>
          <div class="about__text">
            <?php foreach (paragraphs(tx($ab, 'text')) as $para): ?><p><?= nl2br(e($para)) ?></p><?php endforeach; ?>
          </div>
        </article>
      </div>

      <?php if ($tools): ?>
      <h3 class="subtitle reveal"><?= e(tx($ab, 'tools_title')) ?></h3>
      <ul class="tools">
        <?php foreach ($tools as $t): ?>
        <li class="tool reveal"><b dir="ltr"><?= e($t['code']) ?></b><span><?= e($t['name']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if ($fx['code']): $fname = trim((string) $codeData['filename']) !== '' ? (string) $codeData['filename'] : 'code.js'; ?>
      <div class="code-win reveal" dir="ltr" translate="no">
        <div class="code-win__bar">
          <span class="code-win__dots" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="code-win__file"><?= icon('code-xml') ?><?= e($fname) ?></span>
          <span class="code-win__lang" aria-hidden="true">UTF-8 · JS</span>
        </div>
        <pre class="code-win__body"><code id="code-out"><?php foreach ($codeLines as $i => $line): ?><span class="ln"><?= e($line) ?></span><?php endforeach; ?></code></pre>
        <div class="code-win__out" aria-live="polite">
          <p class="code-win__cmd"><b>$</b> node <?= e($fname) ?></p>
          <p class="code-win__ok"><?= icon('check') ?><span dir="auto"><?= e(tx($codeData, 'output')) ?></span></p>
        </div>
      </div>
      <script type="application/json" id="code-src"><?= json_encode(array_values($codeLines), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <?php endif; ?>
    </div>
  </section>

  <!-- ───────── لماذا أنا ───────── -->
  <?php if ($whyItems): ?>
  <section class="section<?= $fx['waves'] ? ' section--alt' : '' ?>" id="why" data-section="about" data-read="why">
    <?php if ($fx['waves']): ?><?= wave_svg('top') ?><?php endif; ?>
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($why, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($why, 'title')) ?></h2>
        <p class="head__text"><?= e(tx($why, 'text')) ?></p>
        <span class="head__line" aria-hidden="true"></span>
      </header>
      <div class="timeline">
        <span class="timeline__rail" aria-hidden="true"><span class="timeline__fill"></span></span>
        <ol class="timeline__list">
        <?php foreach ($whyItems as $i => $w): ?>
        <li class="tl reveal">
          <span class="tl__dot" aria-hidden="true"></span>
          <span class="tl__num" dir="ltr"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <div class="tl__body">
            <h3><?= e(tx($w, 'title')) ?></h3>
            <p><?= e(tx($w, 'text')) ?></p>
          </div>
        </li>
        <?php endforeach; ?>
        </ol>
      </div>

      <?php if ($chips): ?>
      <h3 class="subtitle reveal"><?= e(tx($ab, 'chips_title')) ?></h3>
      <ul class="chips reveal">
        <?php foreach ($chips as $chip): ?><li><?= e($chip) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php if ($fx['waves']): ?><?= wave_svg('bottom') ?><?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ───────── الخدمات ───────── -->
  <?php if ($services): ?>
  <section class="section" id="services" data-section="services" data-read="services">
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($svc, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($svc, 'title')) ?></h2>
        <span class="head__line" aria-hidden="true"></span>
      </header>
      <div class="svcs">
        <?php foreach ($services as $i => $s): $points = lines_of(tx($s, 'points')); $cover = trim($s['cover']) !== '' ? $s['cover'] : mb_substr(tx($s, 'title'), 0, 8); ?>
        <article class="svc svc--v<?= $i % 3 ?> reveal">
          <div class="svc__cover" aria-hidden="true">
            <span class="svc__word" dir="ltr"><?= e($cover) ?></span>
            <?= hero_picture($heroImg, 'svc__pic', '', '', 'lazy', true) ?>
            <span class="svc__icon"><?= icon($iconOk($s['icon'], 'code-xml')) ?></span>
          </div>
          <div class="svc__body">
            <h3><?= e(tx($s, 'title')) ?></h3>
            <?php if ($points): ?>
            <ul class="svc__points"><?php foreach ($points as $pt): ?><li><?= e($pt) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <a class="svc__more" href="#projects"><span><?= e(tx($svc, 'more')) ?></span><i><?= icon($en ? 'arrow-right' : 'arrow-left') ?></i></a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ───────── القطاعات ───────── -->
  <?php if ($sectors): $half = (int) ceil(count($sectors) / 2); $rows = count($sectors) > 5 ? [array_slice($sectors, 0, $half), array_slice($sectors, $half)] : [$sectors]; ?>
  <section class="section<?= $fx['waves'] ? ' section--alt section--alt-start' : '' ?>" id="sectors" data-section="sectors" data-read="sectors">
    <?php if ($fx['waves']): ?><?= wave_svg('top') ?><?php endif; ?>
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($sec, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($sec, 'title')) ?></h2>
        <span class="head__line" aria-hidden="true"></span>
      </header>
    </div>
    <div class="logos reveal">
      <ul class="sr-only"><?php foreach ($sectors as $s): ?><li><?= e(tx($s, 'name')) ?></li><?php endforeach; ?></ul>
      <?php foreach ($rows as $ri => $row): ?>
      <div class="logos__row logos__row--<?= $ri % 2 ? 'b' : 'a' ?>" aria-hidden="true">
        <div class="logos__track" dir="ltr">
          <?php for ($g = 0; $g < 2; $g++): ?>
          <div class="logos__group">
            <?php for ($rep = 0; $rep < 2; $rep++): foreach ($row as $s): ?>
            <span class="logo-item"><?= icon($iconOk($s['icon'], 'store')) ?><b dir="auto"><?= e(tx($s, 'name')) ?></b></span>
            <?php endforeach; endfor; ?>
          </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ───────── الأرقام ───────── -->
  <?php if ($stats): ?>
  <section class="section section--tight<?= $fx['waves'] ? ' section--alt section--alt-end' : '' ?>" data-section="sectors" data-read="stats">
    <div class="wrap">
      <ul class="stats">
        <?php foreach ($stats as $s): $num = preg_replace('/[^\d]/', '', $s['num']); ?>
        <li class="stat reveal">
          <span class="stat__icon"><?= icon($iconOk($s['icon'], 'star')) ?></span>
          <b class="stat__num" dir="ltr"><span class="count" data-to="<?= e($num !== '' ? $num : '0') ?>"><?= e($s['num']) ?></span><small><?= e($s['suffix']) ?></small></b>
          <span class="stat__label"><?= e(tx($s, 'label')) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if ($fx['waves']): ?><?= wave_svg('bottom') ?><?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ───────── الأعمال ───────── -->
  <?php if ($projects): ?>
  <section class="section" id="projects" data-section="projects" data-read="projects">
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($pj, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($pj, 'title')) ?></h2>
        <span class="head__line" aria-hidden="true"></span>
      </header>
      <div class="works">
        <?php foreach ($projects as $i => $p):
            $url = safe_url($p['url']);
            $img = safe_url($p['image']);
            if ($img === '' && preg_match('#^uploads/[a-z0-9_-]+\.(jpe?g|png|webp)$#', $p['image']) && is_file(APP_ROOT . '/' . $p['image'])) {
                $img = $p['image'];
            }
            $host = $url !== '' ? preg_replace('#^https?://(www\.)?#', '', rtrim($url, '/')) : '';
            $tag = $url !== '' ? 'a' : 'div';
        ?>
        <<?= $tag ?> class="work work--t<?= $i % 4 ?> reveal<?= $i >= 6 ? ' work--extra' : '' ?>"<?php if ($url): ?> href="<?= e($url) ?>" target="_blank" rel="noopener" data-track="<?= e(project_key($p)) ?>" data-cursor="<?= e(ui('view')) ?>"<?php endif; ?>>
          <div class="work__art" aria-hidden="true">
            <?php if ($img !== ''): ?>
            <img src="<?= e($img) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
            <span class="work__grid"></span>
            <span class="work__big" dir="auto"><?= e(tx($p, 'name')) ?></span>
            <span class="work__win">
              <span class="work__bar"><i></i><i></i><i></i><em dir="ltr"><?= e($host) ?></em></span>
              <span class="work__ico"><?= icon($iconOk($p['icon'], 'globe')) ?></span>
              <span class="work__lines"><i></i><i></i><i></i></span>
            </span>
            <?php endif; ?>
          </div>
          <div class="work__info">
            <?php if (tx($p, 'tag') !== ''): ?><span class="work__tag"><?= e(tx($p, 'tag')) ?></span><?php endif; ?>
            <h3><?= e(tx($p, 'name')) ?></h3>
            <p><?= e(tx($p, 'text')) ?></p>
            <?php if ($url): ?><span class="work__link"><span><?= e(tx($pj, 'cta')) ?></span><?= icon('external-link') ?><small dir="ltr"><?= e($host) ?></small></span><?php endif; ?>
          </div>
        </<?= $tag ?>>
        <?php endforeach; ?>
      </div>
      <?php if (count($projects) > 6): ?>
      <div class="center reveal"><button class="btn" type="button" data-more aria-expanded="false"><span><?= e(tx($pj, 'more')) ?></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i btn__arrow') ?></button></div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ───────── آراء العملاء (يظهر عند إضافة آراء حقيقية من اللوحة) ───────── -->
  <?php if ($reviews): ?>
  <section class="section" id="reviews" data-section="projects" data-read="reviews">
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($rv, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($rv, 'title')) ?></h2>
        <span class="head__line" aria-hidden="true"></span>
      </header>
      <div class="reviews reveal" data-slider>
        <div class="reviews__track">
          <?php foreach ($reviews as $i => $r): $rate = max(1, min(5, (int) $r['rating'] ?: 5)); $logo = safe_url($r['logo']); ?>
          <figure class="review<?= $i === 0 ? ' is-on' : '' ?>"<?= $i === 0 ? '' : ' hidden' ?>>
            <div class="review__logo">
              <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($r['name']) ?>" loading="lazy"><?php else: ?><span><?= e(mb_substr(trim($r['name']), 0, 1)) ?></span><?php endif; ?>
            </div>
            <div class="review__body">
              <figcaption><b><?= e($r['name']) ?></b><small><?= e(tx($r, 'role') !== '' ? tx($r, 'role') : ui('client')) ?></small></figcaption>
              <p class="review__stars" aria-label="<?= e(ui('rating') . ' ' . $rate . '/5') ?>"><?php for ($k = 1; $k <= 5; $k++): ?><?= icon('star-fill', 'i' . ($k > $rate ? ' is-off' : '')) ?><?php endfor; ?><span dir="ltr"><?= $rate ?>.0</span></p>
              <blockquote><?= icon('quote', 'i review__q') ?><p><?= e(tx($r, 'quote')) ?></p></blockquote>
            </div>
          </figure>
          <?php endforeach; ?>
        </div>
        <?php if (count($reviews) > 1): ?>
        <div class="reviews__nav">
          <button type="button" data-prev aria-label="<?= e(ui('prev')) ?>"><?= icon($en ? 'chevron-left' : 'chevron-right') ?></button>
          <button type="button" data-next aria-label="<?= e(ui('next')) ?>"><?= icon($en ? 'chevron-right' : 'chevron-left') ?></button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ───────── تواصل ───────── -->
  <section class="section" id="contact" data-section="contact" data-read="contact">
    <?php if ($fx['aurora']): ?><div class="aurora aurora--soft" aria-hidden="true"><i></i><i></i><i></i></div><?php endif; ?>
    <div class="wrap">
      <header class="head reveal">
        <p class="kicker"><?= e(tx($ct, 'kicker')) ?></p>
        <h2 class="title"><?= accent(tx($ct, 'title')) ?></h2>
        <p class="head__text"><?= e(tx($ct, 'text')) ?></p>
        <span class="head__line" aria-hidden="true"></span>
      </header>

      <div class="contact">
        <form class="form card reveal" method="post" action="contact.php<?= $en ? '' : '?lang=ar' ?>" novalidate>
          <input type="hidden" name="lang" value="<?= $L ?>">
          <div class="form__hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="form__row">
            <label class="field"><span><?= e(ui('form_name')) ?></span><input type="text" name="name" maxlength="80" required autocomplete="name" placeholder="<?= e(ui('form_name_ph')) ?>"></label>
            <label class="field"><span><?= e(ui('form_wa')) ?></span><input type="tel" name="whatsapp" maxlength="30" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="<?= e(ui('form_wa_ph')) ?>"></label>
          </div>
          <div class="form__row">
            <label class="field"><span><?= e(ui('form_email')) ?> <small><?= e(ui('optional')) ?></small></span><input type="email" name="email" maxlength="120" dir="ltr" autocomplete="email" placeholder="<?= e(ui('form_email_ph')) ?>"></label>
            <label class="field"><span><?= e(ui('form_company')) ?> <small><?= e(ui('optional')) ?></small></span><input type="text" name="company" maxlength="100" autocomplete="organization" placeholder="<?= e(ui('form_company_ph')) ?>"></label>
          </div>
          <label class="field"><span><?= e(ui('form_service')) ?></span>
            <select name="service">
              <?php foreach ($services as $s): ?><option><?= e(tx($s, 'title')) ?></option><?php endforeach; ?>
              <option><?= e(ui('form_other')) ?></option>
            </select>
          </label>
          <div class="form__row">
            <label class="field"><span><?= e(ui('form_budget')) ?></span>
              <select name="budget"><option value=""><?= e(ui('form_pick')) ?></option><?php for ($k = 1; $k <= 5; $k++): ?><option><?= e(ui('budget_' . $k)) ?></option><?php endfor; ?></select>
            </label>
            <label class="field"><span><?= e(ui('form_time')) ?></span>
              <select name="timeline"><option value=""><?= e(ui('form_pick')) ?></option><?php for ($k = 1; $k <= 4; $k++): ?><option><?= e(ui('time_' . $k)) ?></option><?php endfor; ?></select>
            </label>
          </div>
          <label class="field"><span><?= e(ui('form_details')) ?></span><textarea name="details" rows="4" maxlength="2000" required placeholder="<?= e(ui('form_details_ph')) ?>"></textarea></label>
          <button class="btn btn--block" type="submit"><?= icon('send') ?><span><?= e(ui('form_send')) ?></span></button>
          <p class="form__note" role="status" aria-live="polite"><?= e(req_str($_GET, 'sent') === '1' ? ui('form_saved') : ui('form_note')) ?></p>
        </form>

        <div class="info">
          <?php if ($location !== ''): ?>
          <div class="info__card info__card--hl reveal"><span class="info__ic"><?= icon('map-pin') ?></span><span class="info__tx"><small><?= e(ui('location')) ?></small><b><?= e($location) ?></b></span></div>
          <?php endif; ?>
          <?php if ($waUrl): ?>
          <a class="info__card reveal" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><span class="info__ic"><?= icon('whatsapp') ?></span><span class="info__tx"><small><?= e(ui('whatsapp')) ?></small><b dir="ltr"><?= e(phone_display($ct['whatsapp'])) ?></b></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i info__go') ?></a>
          <?php endif; ?>
          <?php if ($phone !== ''): ?>
          <a class="info__card reveal" href="tel:+<?= e($phone) ?>" data-track="phone"><span class="info__ic"><?= icon('phone') ?></span><span class="info__tx"><small><?= e(ui('phone')) ?></small><b dir="ltr"><?= e(phone_display($phone)) ?></b></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i info__go') ?></a>
          <?php endif; ?>
          <?php if ($email !== ''): ?>
          <a class="info__card reveal" href="mailto:<?= e($email) ?>" data-track="email"><span class="info__ic"><?= icon('mail') ?></span><span class="info__tx"><small><?= e(ui('email')) ?></small><b dir="ltr"><?= e($email) ?></b></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i info__go') ?></a>
          <?php endif; ?>
          <?php foreach (['facebook', 'tiktok', 'instagram'] as $k): if (empty($socials[$k])) continue; ?>
          <a class="info__card reveal" href="<?= e($socials[$k]) ?>" target="_blank" rel="noopener me" data-track="<?= $k ?>"><span class="info__ic"><?= icon($k) ?></span><span class="info__tx"><small><?= e(ui($k)) ?></small><b<?= $k === 'facebook' ? '' : ' dir="ltr"' ?>><?= e($k === 'tiktok' && preg_match('#/(@[^/?\#]+)#', $socials[$k], $m) ? $m[1] : $name) ?></b></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i info__go') ?></a>
          <?php endforeach; ?>
          <?php if ($socials): ?>
          <div class="info__card info__follow reveal"><small><?= e(ui('follow')) ?></small>
            <span class="soc"><?php foreach ($socials as $k => $u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ui($k)) ?>" data-track="<?= e($k) ?>"><?= icon($k) ?></a><?php endforeach; ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
  <?php $bigText = trim(tx($c['bigtext'] ?? [], 'text')); if ($fx['bigtext'] && $bigText !== ''): ?>
  <!-- ───────── النص العملاق المتحرك ───────── -->
  <section class="mega" aria-label="<?= e($bigText) ?>">
    <?php foreach (['a', 'b'] as $row): ?>
    <div class="mega__row mega__row--<?= $row ?>" aria-hidden="true">
      <div class="mega__track">
        <?php for ($g = 0; $g < 2; $g++): ?>
        <div class="mega__group"><?php for ($k = 0; $k < 3; $k++): ?><span dir="auto"><?= e($bigText) ?></span><?= icon('sparkle', 'mega__star') ?><?php endfor; ?></div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</main>

<!-- ───────── التذييل ───────── -->
<footer class="foot" data-read="cta">
  <div class="foot__card reveal">
    <div class="foot__cta">
      <p class="foot__kicker"><?= e(tx($ct, 'cta_kicker')) ?></p>
      <h2 class="foot__title"><?= accent(tx($ct, 'cta_title')) ?></h2>
      <p class="foot__text"><?= e(tx($ct, 'cta_text')) ?></p>
      <div class="foot__btns">
        <a class="btn" href="#contact"><span><?= e(tx($ct, 'cta_btn')) ?></span><?= icon($en ? 'arrow-right' : 'arrow-left', 'i btn__arrow') ?></a>
        <?php if ($waUrl): ?><a class="btn btn--alt" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="whatsapp"><span><?= e(tx($ct, 'cta_btn2')) ?></span></a><?php endif; ?>
      </div>
    </div>
    <div class="foot__cut" aria-hidden="true"></div>
    <div class="foot__cols">
      <div class="foot__brand">
        <a class="foot__logo" href="#home" aria-label="<?= e($name) ?>"><?= logo_mark('mark') ?><b><?= e(tx($ft, 'name')) ?></b></a>
        <p><?= e(tx($ft, 'desc')) ?></p>
        <?php if ($socials): ?>
        <span class="soc soc--plain"><?php foreach ($socials as $k => $u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ui($k)) ?>" data-track="<?= e($k) ?>"><?= icon($k) ?></a><?php endforeach; ?></span>
        <?php endif; ?>
      </div>
      <nav class="foot__links" aria-label="<?= e(ui('browse')) ?>">
        <h3><?= e(ui('browse')) ?></h3>
        <ul><?php foreach ($navItems as [$key, $href, $label]): if ($key === 'home') continue; ?><li><a href="<?= e($href) ?>"><?= e($label) ?></a></li><?php endforeach; ?><li><a href="<?= e($langHref) ?>" hreflang="<?= $otherLang ?>"><?= e(ui('lang_name')) ?></a></li><?php if ($fx['share']): ?><li><button type="button" class="foot__share" data-share><?= icon('share-2') ?><?= e(ui('share')) ?></button></li><?php endif; ?></ul>
      </nav>
      <div class="foot__contact">
        <h3><?= e(ui('get_in_touch')) ?></h3>
        <ul>
          <?php if ($email !== ''): ?><li><a href="mailto:<?= e($email) ?>" dir="ltr" data-track="email"><?= e($email) ?></a></li><?php endif; ?>
          <?php if ($waUrl): ?><li><a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" dir="ltr" data-track="whatsapp"><?= e(phone_display($ct['whatsapp'])) ?></a></li><?php endif; ?>
          <?php if ($phone !== '' && $phone !== digits($ct['whatsapp'])): ?><li><a href="tel:+<?= e($phone) ?>" dir="ltr" data-track="phone"><?= e(phone_display($phone)) ?></a></li><?php endif; ?>
          <?php if ($location !== ''): ?><li><?= e($location) ?></li><?php endif; ?>
        </ul>
        <a class="btn btn--sm" href="#home"><span><?= e(ui('top')) ?></span><?= icon('arrow-up', 'i btn__arrow') ?></a>
      </div>
    </div>
  </div>
  <div class="foot__bottom">
    <p>© <?= e(tx($ft, 'name')) ?>. <?= e(tx($ft, 'rights')) ?>.</p>
    <p><?= e(ui('credit')) ?> <?= e(tx($ft, 'name')) ?></p>
  </div>
  <?php if ($ft['en_line'] !== '' && !$en): ?><p class="foot__seo" lang="en" dir="ltr"><?= e($ft['en_line']) ?></p><?php endif; ?>
</footer>

<!-- زر المحادثة السريعة وزر العودة للأعلى -->
<?php if ($waUrl): ?>
<div class="chat">
  <div class="chat__pop" id="chat-pop" hidden>
    <div class="chat__head">
      <span class="chat__av"><?= logo_mark('mark') ?><i></i></span>
      <span><b><?= e($name) ?></b><small><?= e(ui('online')) ?></small></span>
      <button type="button" class="chat__x" aria-label="<?= e(ui('close')) ?>"><?= icon('x') ?></button>
    </div>
    <div class="chat__msg"><b><?= e(ui('chat_hello')) ?></b><p><?= e(ui('chat_text')) ?></p></div>
    <a class="btn btn--block btn--wa" href="<?= e($waUrl) ?>" target="_blank" rel="noopener" data-track="chat"><?= icon('whatsapp') ?><span><?= e(ui('chat_btn')) ?></span></a>
  </div>
  <button class="chat__btn" type="button" aria-label="<?= e(ui('chat_label')) ?>" aria-expanded="false" aria-controls="chat-pop"><?= icon('message-circle-more', 'i chat__i1') ?><?= icon('x', 'i chat__i2') ?></button>
</div>
<?php endif; ?>
<?php if ($fx['share']): $shareUrl = page_url($L); ?>
<div class="share" id="share" hidden>
  <div class="share__backdrop" data-share-close></div>
  <div class="share__card" role="dialog" aria-modal="true" aria-labelledby="share-title">
    <button type="button" class="share__x" data-share-close aria-label="<?= e(ui('close')) ?>"><?= icon('x') ?></button>
    <p class="share__kicker"><?= e(ui('share_kicker')) ?></p>
    <h2 class="share__title" id="share-title"><?= e(ui('share_title')) ?></h2>
    <div class="share__qr">
      <div class="share__qrbox" id="share-qr" role="img" aria-label="QR"></div>
      <span class="share__logo" aria-hidden="true"><?= logo_mark('mark') ?></span>
    </div>
    <div class="share__url">
      <span dir="ltr" id="share-url"><?= e(preg_replace('#^https?://#', '', $shareUrl)) ?></span>
      <button type="button" class="share__copy" data-copy><?= icon('copy') ?><b><?= e(ui('copy')) ?></b></button>
    </div>
    <div class="share__nets">
      <a data-net="whatsapp" target="_blank" rel="noopener" aria-label="<?= e(ui('whatsapp')) ?>"><?= icon('whatsapp') ?></a>
      <a data-net="facebook" target="_blank" rel="noopener" aria-label="<?= e(ui('facebook')) ?>"><?= icon('facebook') ?></a>
      <a data-net="telegram" target="_blank" rel="noopener" aria-label="<?= e(ui('telegram')) ?>"><?= icon('send') ?></a>
      <a data-net="x" target="_blank" rel="noopener" aria-label="X"><?= icon('x-brand') ?></a>
      <button type="button" data-native hidden aria-label="<?= e(ui('more')) ?>"><?= icon('share-2') ?></button>
    </div>
    <button type="button" class="btn btn--block share__dl" data-qr-download><?= icon('download') ?><span><?= e(ui('qr_download')) ?></span></button>
  </div>
</div>
<?php endif; ?>
<a class="totop" href="#home" aria-label="<?= e(ui('to_top')) ?>"><svg class="totop__ring" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="22" pathLength="1"/></svg><?= icon('arrow-up') ?></a>

<script type="application/json" id="app-data"><?= json_encode($appData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if ($fx['smooth']): ?><script src="<?= e(asset('assets/vendor/lenis.min.js')) ?>" defer></script><?php endif; ?>
<script src="<?= e(asset($pickAsset('assets/app.js', 'assets/app.min.js'))) ?>" defer></script>
</body>
</html>
