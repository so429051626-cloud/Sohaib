<?php
// يستقبل نقرات الروابط (واتساب، فيسبوك، المشاريع...) من الصفحة ويسجّلها في الإحصائيات.

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // نقبل الطلبات القادمة من نفس الموقع فقط
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin === '' || strtolower((string) parse_url($origin, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host)) {
        track_click(substr(req_str($_POST, 'k'), 0, 40));
    }
}
http_response_code(204);
