<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
$v->title = 'طباعة الكروت';
$v->breadcrumbs = [['الكروت', '/cards'], ['طباعة الكروت', null]];
if (can('print.templates')) {
    $v->actions = '<a class="btn btn-primary" href="' . e(url('/print/templates/create')) . '">' . icon('plus') . 'قالب جديد</a>';
}
$source = $request->str('source') ?: ($selection ? 'selection' : 'batch');
if ($source === 'selection' && !$selection) {
    $source = 'batch';
}
$defaultTemplate = 0;
foreach ($templates as $t) {
    if ((int) $t['is_default'] === 1) {
        $defaultTemplate = (int) $t['id'];
        break;
    }
}
$sources = ['batch' => ['package', 'دفعة'], 'category' => ['layers', 'باقة'], 'range' => ['binary', 'نطاق تسلسلي']];
if ($selection) {
    $sources = ['selection' => ['list-checks', 'الكروت المحددة']] + $sources;
}
?>
<?= $v->partial('cards-nav') ?>
<div class="grid grid-main-side">
  <?php if (can('cards.print')): ?>
  <form class="panel" method="get" action="<?= e(url('/print/render')) ?>" target="_blank">
    <div class="panel-head"><h2><?= icon('printer') ?>طباعة</h2></div>
    <div class="panel-body">
      <div class="form-grid">
        <div class="field col-12">
          <label>المصدر</label>
          <div class="segmented">
            <?php foreach ($sources as $k => [$ic, $label]): ?>
              <label><input type="radio" name="source" value="<?= e($k) ?>"<?= checked($source === $k) ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($selection): ?>
        <div class="field col-12" data-show-when="source=selection">
          <div class="notice" style="margin:0"><?= icon('list-checks') ?><div><strong><?= e(num(count($selection))) ?></strong> كرت محدد من قائمة الكروت.</div></div>
        </div>
        <?php endif; ?>

        <div class="field col-8" data-show-when="source=batch">
          <label for="p-batch">الدفعة</label>
          <select class="input" id="p-batch" name="batch">
            <?php foreach ($batches as $b): ?>
              <option value="<?= (int) $b['id'] ?>"<?= selected((string) $request->int('batch'), (string) $b['id']) ?>>#<?= (int) $b['id'] ?> · <?= e($b['name']) ?> — <?= e(num($b['cards'])) ?> كرت<?= (int) $b['unprinted'] > 0 ? ' (' . e(num($b['unprinted'])) . ' غير مطبوعة)' : '' ?></option>
            <?php endforeach; ?>
            <?php if (!$batches): ?><option value="">—</option><?php endif; ?>
          </select>
        </div>

        <div class="field col-8" data-show-when="source=category">
          <label for="p-category">الباقة</label>
          <select class="input" id="p-category" name="category">
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> — <?= e(num($c['unprinted'])) ?> غير مطبوعة</option>
            <?php endforeach; ?>
            <?php if (!$categories): ?><option value="">—</option><?php endif; ?>
          </select>
        </div>
        <div class="field col-4" data-show-when="source=category">
          <label for="p-count">العدد</label>
          <input class="input" id="p-count" name="count" value="40" inputmode="numeric">
        </div>

        <div class="field col-4" data-show-when="source=range">
          <label for="p-from">من الرقم التسلسلي</label>
          <input class="input input-ltr" id="p-from" name="from" inputmode="numeric">
        </div>
        <div class="field col-4" data-show-when="source=range">
          <label for="p-to">إلى الرقم التسلسلي</label>
          <input class="input input-ltr" id="p-to" name="to" inputmode="numeric">
        </div>

        <div class="field col-12" data-show-when="source=batch,category">
          <input type="hidden" name="unprinted" value="0">
          <label class="check"><input type="checkbox" name="unprinted" value="1" checked>الكروت غير المطبوعة فقط</label>
        </div>

        <div class="field col-8">
          <label for="p-template">القالب</label>
          <select class="input" id="p-template" name="template">
            <?php foreach ($templates as $t): ?>
              <option value="<?= (int) $t['id'] ?>"<?= selected((string) $defaultTemplate, (string) $t['id']) ?>><?= e($t['name']) ?> · <?= e(num((int) $t['cols'] * (int) $t['rows'])) ?> كرت/صفحة</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field col-4" style="align-self:end">
          <input type="hidden" name="mark" value="0">
          <label class="check"><input type="checkbox" name="mark" value="1" checked>تعليمها كمطبوعة</label>
        </div>
      </div>
    </div>
    <div class="panel-foot">
      <button class="btn btn-primary" type="submit"><?= icon('printer') ?>معاينة وطباعة</button>
    </div>
  </form>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><h2><?= icon('layout-template') ?>قوالب الطباعة</h2><span class="badge"><?= e(num(count($templates))) ?></span></div>
    <ul class="list">
      <?php foreach ($templates as $t): ?>
        <li class="list-item">
          <span class="list-icon is-primary"><?= icon('layout-template') ?></span>
          <div class="list-item-main">
            <div class="list-item-title">
              <?php if (can('print.templates')): ?><a href="<?= e(url('/print/templates/' . $t['id'])) ?>"><?= e($t['name']) ?></a><?php else: ?><?= e($t['name']) ?><?php endif; ?>
              <?php if ((int) $t['is_default'] === 1): ?><span class="badge badge-primary">افتراضي</span><?php endif; ?>
            </div>
            <div class="list-item-sub">
              <?= e($t['paper'] === 'custom' ? 'مخصص' : $t['paper']) ?> · <?= ltr(e((int) $t['cols'] . ' × ' . (int) $t['rows'])) ?>
              <?php if ($t['category_name']): ?> · <?= e($t['category_name']) ?><?php endif; ?>
            </div>
          </div>
          <?php if (can('print.templates')): ?>
          <div class="list-item-end flex gap-1">
            <a class="btn btn-xs btn-ghost btn-icon" href="<?= e(url('/print/templates/' . $t['id'])) ?>" title="تعديل"><?= icon('pencil') ?></a>
            <form method="post" action="<?= e(url('/print/templates/' . $t['id'] . '/duplicate')) ?>">
              <?= csrf_field() ?><button class="btn btn-xs btn-ghost btn-icon" type="submit" title="نسخ"><?= icon('copy') ?></button>
            </form>
            <?php if (count($templates) > 1): ?>
            <form method="post" action="<?= e(url('/print/templates/' . $t['id'] . '/delete')) ?>" data-confirm="حذف القالب «<?= e($t['name']) ?>»؟">
              <?= csrf_field() ?><button class="btn btn-xs btn-ghost btn-icon" type="submit" title="حذف"><?= icon('trash-2') ?></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
