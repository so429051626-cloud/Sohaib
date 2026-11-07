<?php
// يُخدَّم على /robots.txt عبر .htaccess

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

$base = base_url();
$path = (string) (parse_url($base, PHP_URL_PATH) ?: '/');

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: {$path}admin.php\n";
echo "Disallow: {$path}track.php\n";
echo "Disallow: {$path}contact.php\n";
echo "\n";
echo 'Sitemap: ' . $base . "sitemap.php\n";
