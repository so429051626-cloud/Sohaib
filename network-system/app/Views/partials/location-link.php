<?php
/**
 * رابط موقع عنصر: اسم البرج + فتح على خريطة الشبكة.
 * @var string $kind site|router|sub
 * @var int $id
 * @var mixed $siteId
 * @var ?string $siteName
 * @var mixed $lat
 * @var mixed $lng
 */
$parts = [];
if ($siteId && $siteName !== null) {
    $parts[] = can('map.view') ? '<a href="' . e(url('/sites/' . (int) $siteId)) . '">' . e($siteName) . '</a>' : e($siteName);
}
if ($lat !== null && can('map.view')) {
    $parts[] = '<a class="btn btn-ghost btn-xs" href="' . e(url('/map', ['focus' => $kind . ':' . (int) $id])) . '">' . icon('map-pin') . 'الخريطة</a>';
}
echo implode(' ', $parts) ?: '—';
