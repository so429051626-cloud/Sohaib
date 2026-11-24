<?php
/**
 * شريط أقسام الكروت: القائمة، الحسابات الخاصة، الاستيراد، الطباعة، الإعدادات — يظهر أعلى صفحات القسم.
 * كل قسم يظهر لمن يملك صلاحيته فقط.
 */
use App\Core\Request;

$items = [
    ['list',     'الكروت',         '/cards',          'ticket',   can('cards.view')],
    ['accounts', 'الحسابات الخاصة', '/cards/accounts', 'user-plus', can('cards.accounts')],
    ['import',   'استيراد كروت',   '/cards/import',   'file-up',  can('cards.import')],
    ['print',    'طباعة الكروت',   '/print',          'printer',  can('cards.print') || can('print.templates')],
    ['settings', 'إعدادات الكروت', '/cards/settings', 'settings', can('settings.manage')],
];
$items = array_values(array_filter($items, static fn (array $i): bool => $i[4]));
if (count($items) < 2) {
    return;
}
$path = Request::current() ? Request::current()->path : '/cards';
$current = match (true) {
    $path === '/cards/import' || str_starts_with($path, '/cards/import/') => 'import',
    $path === '/print' || str_starts_with($path, '/print/') => 'print',
    $path === '/cards/settings' => 'settings',
    $path === '/cards/accounts' || str_starts_with($path, '/cards/accounts/') => 'accounts',
    default => 'list',
};
?>
<nav class="section-nav" aria-label="أقسام الكروت">
  <?php foreach ($items as [$key, $label, $href, $ic]): ?>
    <a class="section-link<?= $key === $current ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>>
      <span class="section-icon"><?= icon($ic) ?></span><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
