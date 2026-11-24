<?php
/**
 * صفحة إعدادات قسم.
 * @var App\Core\View $v
 * @var string $module
 * @var array $m
 */
use App\Core\Settings;

$v->title = $m['title'];
$v->breadcrumbs = [$m['parent'], [$m['title'], null]];
$s = static fn (string $k) => old(str_replace('.', '__', $k), (string) Settings::get($k, ''));
$name = static fn (string $k) => str_replace('.', '__', $k);
$switch = static function (string $key, string $label, string $hint = '') use ($name): string {
    return '<label class="switch"><input type="checkbox" name="' . e($name($key)) . '" value="1"' . checked(Settings::bool($key)) . '><span class="track"></span><span>' . e($label)
        . ($hint !== '' ? '<span class="d-block hint">' . e($hint) . '</span>' : '') . '</span></label>';
};
?>
<?= $module === 'cards' ? $v->partial('cards-nav') : '' ?>
<form method="post" action="<?= e(url($m['url'])) ?>" class="panel">
  <?= csrf_field() ?>
  <div class="panel-head"><h2><?= icon($m['icon']) ?><?= e($m['title']) ?></h2></div>
  <div class="panel-body">
    <div class="form-grid">
      <?= $v->renderFile('settings/sections/' . $module, ['s' => $s, 'name' => $name, 'switch' => $switch]) ?>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </div>
</form>
