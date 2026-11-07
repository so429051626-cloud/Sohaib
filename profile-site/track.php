<?php
// يستقبل من الصفحة: النقرات على الروابط (واتساب، فيسبوك، المشاريع...)، وملخص الزيارة
// (الأقسام المقروءة، عمق التمرير، وقت القراءة، مراحل النموذج، المنطقة الزمنية لمعرفة الدولة).

declare(strict_types=1);
require __DIR__ . '/inc/core.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // نقبل الطلبات القادمة من نفس الموقع فقط
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin === '' || strtolower((string) parse_url($origin, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host)) {
        if (req_str($_POST, 'e') === 'visit') {
            $raw = req_str($_POST, 'd');
            if ($raw !== '' && strlen($raw) <= 4096) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    track_visit_details($data);
                }
            }
        } else {
            track_click(substr(req_str($_POST, 'k'), 0, 40));
        }
    }
}
http_response_code(204);
