<?php
// خريطة الموقع لجوجل: ترسلها مرة واحدة من Google Search Console.
// تذكر النسختين العربية والإنجليزية وتربط بينهما (hreflang).

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

$file = store_path('content');
$modified = date('c', (int) (is_file($file) ? filemtime($file) : filemtime(__DIR__ . '/index.php')));
$ar = page_url('ar');
$en = page_url('en');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php foreach ([[$ar, '1.0'], [$en, '0.8']] as [$loc, $prio]): ?>
  <url>
    <loc><?= e($loc) ?></loc>
    <xhtml:link rel="alternate" hreflang="ar" href="<?= e($ar) ?>"/>
    <xhtml:link rel="alternate" hreflang="en" href="<?= e($en) ?>"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="<?= e($ar) ?>"/>
    <lastmod><?= $modified ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority><?= $prio ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
