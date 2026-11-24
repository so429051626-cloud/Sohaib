<?php
/**
 * صفحة الهبوط للمنصة: صفحة مستقلة بأنماطها وسكربتها الخفيف (لا تحمّل أنماط النظام ولا سكربته).
 * كل المحتوى ظاهر بلا جافاسكربت، والحركة تحسين فقط.
 * @var App\Core\View $v
 * @var string $content
 */
use App\Core\Brand;
use App\Core\Session;
use App\Core\Theme;
use App\Platform\Landing;

$flash = Session::pull('_flash', []) ?: [];
// التصميم المختار من لوحة المسؤول: الجديد (landing.css) أو الكلاسيكي الداكن (landing-classic.css)
$classic = Landing::style() === 'classic';
// مساعد الموقع (إن فُعّل) في الزاوية المقابلة لزر واتساب العائم حتى لا يتراكبا
$bot = \App\Controllers\BotPublicController::webOn();
?><!doctype html>
<html <?= \App\Core\Lang::htmlAttrs() ?><?= Theme::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="<?= e(Landing::get('hero_text')) ?>">
<meta property="og:title" content="<?= e(Brand::NAME . ' — ' . Landing::get('hero_title')) ?>">
<meta property="og:description" content="<?= e(Landing::get('hero_text')) ?>">
<meta property="og:type" content="website">
<meta property="og:image" content="<?= e(absolute_url('assets/img/landing/og.jpg')) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="<?= $classic ? '#05070f' : '#f7f6ff' ?>">
<title><?= e(Brand::NAME . ' — ' . Brand::TAGLINE) ?></title>
<?= $v->partial('rum', ['area' => 'landing']) ?>
<?php if ($classic): ?>
<link rel="stylesheet" href="<?= e(asset('css/landing-classic.css')) ?>">
<?php else: $shotLang = \App\Core\Lang::isEn() ? 'en' : 'ar'; ?>
<link rel="preload" as="image" href="<?= e(asset('img/landing/dash-' . $shotLang . '-sm.webp')) ?>" imagesrcset="<?= e(asset('img/landing/dash-' . $shotLang . '-sm.webp')) ?> 1080w, <?= e(asset('img/landing/dash-' . $shotLang . '.webp')) ?> 2160w" imagesizes="(min-width: 1000px) 640px, 94vw" fetchpriority="high">
<script>document.documentElement.classList.add('lp-js');</script>
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<?php endif; ?>
<?= Theme::style() ?>
<?= Brand::headTags() ?>
</head>
<body class="lp<?= $bot ? ' lp-has-bot' : '' ?>">
<?php foreach ($flash as $f): ?>
<div class="lp-flash is-<?= e(($f['type'] ?? '') === 'error' ? 'error' : 'ok') ?>" role="status"><?= e((string) ($f['message'] ?? '')) ?></div>
<?php endforeach; ?>
<?= $content ?>
<script src="<?= e(asset($classic ? 'js/landing-classic.js' : 'js/landing.js')) ?>" defer></script>
<?php if ($bot): ?>
<script src="<?= e(asset('widget/bot-widget.js')) ?>" data-side="<?= \App\Core\Lang::isEn() ? 'left' : 'right' ?>" data-endpoint="<?= e(\App\Controllers\BotPublicController::endpoint(false)) ?>" data-title="<?= e(\App\Services\BotAgent::name()) ?>" data-greeting="<?= e('أهلاً بك! اسألني عن ' . \App\Core\Platform::setting('platform.name') . ' وباقاتها، أو عن اشتراكك أو كرتك.') ?>" defer></script>
<?php endif; ?>
</body>
</html>
