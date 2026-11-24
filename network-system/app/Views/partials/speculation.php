<?php
/*
 * تحميل مسبق لصفحات التنقل (Chrome وEdge وAndroid): عند المرور على رابط في القائمة أو التبويبات أو أقسام الصفحة
 * (أو بدء الضغط على الهاتف) يجلب المتصفح الصفحة في الخلفية، فتظهر فور الضغط.
 * يُجلب HTML الصفحة فقط ولا تُنفَّذ سكربتاتها. مستثنى كل رابط له أثر عند فتحه: الخروج واللغة والتصدير والتنزيل
 * والطباعة والتذاكر والدخول والواجهات البرمجية، وأي رابط عليه data-no-prefetch أو يفتح نافذة أخرى.
 */
$skip = ['/logout', '/lang/', 'export', 'download', '/print', '/auth/', '/login', '/api/', '/cron', '/support/', '/tickets/', '/impersonat', '/check/card'];
$not = '';
foreach ($skip as $s) {
    $not .= ":not([href*='" . $s . "'])";
}
$not .= ':not([data-no-prefetch]):not([data-no-loading]):not([target]):not([download])';
$sel = implode(', ', array_map(static fn ($b) => $b . '[href^="/"]' . $not, ['.sidebar a.nav-link', '.tabs a.tab', '.section-nav a', '.crumbs a']));
$rules = ['prefetch' => [['where' => ['selector_matches' => $sel], 'eagerness' => 'moderate']]];
?>
<script type="speculationrules"><?= json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
