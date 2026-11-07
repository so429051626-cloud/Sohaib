<?php
// خريطة الموقع لجوجل: ترسلها مرة واحدة من Google Search Console.

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

$file = store_path('content');
$modified = is_file($file) ? filemtime($file) : filemtime(__DIR__ . '/index.php');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= e(base_url()) ?></loc>
    <lastmod><?= date('c', (int) $modified) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
