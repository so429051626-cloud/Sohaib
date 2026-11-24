<?php
/**
 * @var App\Core\View $v @var array $rows @var App\Core\Paginator $pager @var App\Core\Request $request @var array $f
 * @var float $filtered @var float $spent @var float $prev @var int $count @var array $byCategory @var ?array $profit
 * @var array $partners @var array $sites @var string $prevMonth @var ?string $nextMonth
 */
use App\Services\Finance;

$v->title = 'المصاريف والأرباح';
$manage = can('expenses.manage');
$managePartners = can('profit.manage');
$actions = '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'تصدير</button><div class="dropdown-menu">'
    . '<a class="dropdown-item" href="' . e(url('/expenses/export', ['month' => $f['month'], 'category' => $f['category'], 'q' => $f['q']])) . '">' . icon('file-spreadsheet') . 'Excel</a>'
    . '<a class="dropdown-item" href="' . e(url('/expenses/export', ['month' => $f['month'], 'category' => $f['category'], 'q' => $f['q'], 'format' => 'csv'])) . '">' . icon('file-text') . 'CSV</a></div></div>';
if (can('profit.view') && can('reports.view')) {
    $actions .= '<a class="btn" href="' . e(url('/reports', ['type' => 'profit', 'preset' => 'year'])) . '">' . icon('chart-column') . 'تقرير صافي الربح</a>';
}
if ($manage) {
    $actions .= '<button type="button" class="btn btn-primary" data-modal-open="m-expense">' . icon('plus') . 'مصروف جديد</button>';
}
$v->actions = $actions;

$months = ['01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'];
$monthLabel = $months[substr($f['month'], 5, 2)] . ' ' . substr($f['month'], 0, 4);
$diff = $prev > 0 ? ($spent - $prev) / $prev * 100 : null;
$pct = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
$top = $byCategory[0]['total'] ?? 0;
$sharesUsed = array_sum(array_map(static fn ($p) => (float) $p['share'], $partners));
// بعد خطأ في الإدخال تُفتح النافذة نفسها بما كُتب فيها
$expErr = field_error('amount') !== null || field_error('spent_on') !== null || field_error('category') !== null || field_error('description') !== null;
$parErr = field_error('share') !== null || field_error('name') !== null || field_error('phone') !== null || field_error('notes') !== null;
$ox = static fn (string $k, mixed $d = '') => $expErr ? old($k, $d) : $d;
$op = static fn (string $k) => $parErr ? (string) old($k) : '';
?>
<div class="month-nav">
  <a class="btn btn-icon" href="<?= e(url('/expenses', ['month' => $prevMonth])) ?>" aria-label="الشهر السابق" title="الشهر السابق"><?= icon('chevron-right') ?></a>
  <form method="get" class="month-nav-form">
    <label class="month-nav-label" for="f-month"><?= icon('calendar-days') ?><b><?= e($monthLabel) ?></b></label>
    <input class="input" id="f-month" type="month" name="month" value="<?= e($f['month']) ?>" max="<?= e(date('Y-m')) ?>" data-autosubmit aria-label="الشهر">
  </form>
  <?php if ($nextMonth !== null): ?>
  <a class="btn btn-icon" href="<?= e(url('/expenses', ['month' => $nextMonth])) ?>" aria-label="الشهر التالي" title="الشهر التالي"><?= icon('chevron-left') ?></a>
  <?php else: ?><span class="btn btn-icon is-disabled" aria-hidden="true"><?= icon('chevron-left') ?></span><?php endif; ?>
</div>

<div class="stats">
  <?php if ($profit !== null): ?>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">الدخل المحصّل</div><div class="stat-value"><?= e(money($profit['income'])) ?></div></div></div>
  <?php endif; ?>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('receipt') ?></div><div class="stat-body"><div class="stat-label">المصاريف</div><div class="stat-value"><?= e(money($spent)) ?></div>
    <div class="stat-meta"><?= $count > 0 ? e(ar_plural($count, 'مصروف واحد', 'مصروفان', 'مصاريف', 'مصروفاً')) : 'لا مصاريف' ?><?php if ($diff !== null): ?> · <span class="<?= $diff > 0 ? 'text-danger' : 'text-success' ?>"><?= ltr(($diff > 0 ? '+' : '') . number_format($diff, 0) . '%') ?></span> عن الشهر السابق<?php endif; ?></div></div></div>
  <?php if ($profit !== null): ?>
  <div class="stat"><div class="stat-icon <?= $profit['net'] >= 0 ? 'is-info' : 'is-warning' ?>"><?= icon($profit['net'] >= 0 ? 'trending-up' : 'trending-down') ?></div><div class="stat-body"><div class="stat-label"><?= $profit['net'] >= 0 ? 'صافي الربح' : 'صافي الخسارة' ?></div><div class="stat-value <?= $profit['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(money(abs($profit['net']))) ?></div>
    <?php if ($profit['income'] > 0): ?><div class="stat-meta">هامش <?= ltr(number_format($profit['net'] / $profit['income'] * 100, 0) . '%') ?></div><?php endif; ?></div></div>
  <?php endif; ?>
  <div class="stat"><div class="stat-icon is-violet"><?= icon($byCategory ? Finance::CATEGORIES[$byCategory[0]['category']][1] ?? 'ellipsis' : 'chart-pie') ?></div><div class="stat-body"><div class="stat-label">أكبر بند</div><div class="stat-value stat-value-sm"><?= $byCategory ? e(Finance::label($byCategory[0]['category'])) : '—' ?></div>
    <?php if ($byCategory): ?><div class="stat-meta"><?= e(money($byCategory[0]['total'])) ?></div><?php endif; ?></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <form class="filters" method="get">
      <input type="hidden" name="month" value="<?= e($f['month']) ?>">
      <div class="field"><label for="f-category">الفئة</label><select class="input" id="f-category" name="category" data-autosubmit><option value="">الكل</option><?php foreach (Finance::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>"<?= selected($f['category'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field grow"><label for="f-q">بحث</label><input class="input" id="f-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="البيان"></div>
      <button class="btn"><?= icon('search') ?>بحث</button>
    </form>
    <div class="table-wrap"><table class="table exp-table">
      <thead><tr><th>التاريخ</th><th>الفئة</th><th>البيان</th><th class="text-end">المبلغ</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): [$label, $ic, $badge] = Finance::CATEGORIES[$x['category']] ?? Finance::CATEGORIES['other'];
        $json = json_encode(['id' => (int) $x['id'], 'category' => $x['category'], 'amount' => (float) $x['amount'], 'spent_on' => $x['spent_on'], 'description' => (string) $x['description'], 'site_id' => (int) $x['site_id']], JSON_UNESCAPED_UNICODE); ?>
        <tr>
          <td class="nowrap exp-date"><?= e(fmt_date($x['spent_on'])) ?></td>
          <td class="exp-kind"><span class="badge <?= $badge ?>"><?= icon($ic) ?><?= e($label) ?></span></td>
          <td class="exp-desc"><div class="cell-title"><?= $x['description'] !== null ? e($x['description']) : '<span class="text-muted">—</span>' ?></div>
            <?php if ($x['site_name'] !== null || $x['by_name'] !== null): ?><div class="cell-sub"><?= e(implode(' · ', array_filter([$x['site_name'], $x['by_name']]))) ?></div><?php endif; ?></td>
          <td class="text-end nowrap exp-amount"><b><?= e(money((float) $x['amount'])) ?></b></td>
          <?php if ($manage): ?>
          <td class="w-actions"><div class="row-actions">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="تعديل" data-modal-open="m-expense" data-expense="<?= e($json) ?>"><?= icon('pencil') ?></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="تكرار بتاريخ اليوم" data-modal-open="m-expense" data-expense="<?= e($json) ?>" data-copy-expense><?= icon('copy') ?></button>
            <form method="post" action="<?= e(url('/expenses/' . $x['id'] . '/delete')) ?>" data-confirm="حذف هذا المصروف (<?= e(money((float) $x['amount'])) ?>)؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr class="exp-empty"><td colspan="<?= $manage ? 5 : 4 ?>"><div class="empty"><?= icon('receipt') ?><div class="empty-title">لا توجد مصاريف في <?= e($monthLabel) ?></div></div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($rows && ($f['category'] !== '' || $f['q'] !== '')): ?>
      <tfoot><tr><td colspan="3">مجموع النتائج</td><td class="text-end nowrap"><b><?= e(money($filtered)) ?></b></td><?php if ($manage): ?><td></td><?php endif; ?></tr></tfoot>
      <?php endif; ?>
    </table></div>
    <?= $pager->render($request) ?>
  </div>

  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('chart-pie') ?>حسب الفئة</h3></div>
      <div class="panel-body stack" style="gap:14px">
        <?php foreach ($byCategory as $c): [$label, $ic] = Finance::CATEGORIES[$c['category']] ?? Finance::CATEGORIES['other']; ?>
        <div>
          <div class="meter-row"><span class="exp-cat"><?= icon($ic, 'icon-sm') ?><?= e($label) ?></span><b class="text"><?= e(money($c['total'])) ?></b></div>
          <div class="meter"><span style="width:<?= $top > 0 ? max(2, round($c['total'] / $top * 100)) : 0 ?>%"></span></div>
        </div>
        <?php endforeach; ?>
        <?php if (!$byCategory): ?><div class="text-muted text-sm">لا توجد مصاريف هذا الشهر.</div><?php endif; ?>
      </div>
    </div>

    <?php if ($profit !== null): ?>
    <div class="panel" id="partners">
      <div class="panel-head"><h3><?= icon('handshake') ?>حصص الشركاء</h3>
        <?php if ($managePartners && $sharesUsed < 100): ?><button type="button" class="btn btn-sm" data-modal-open="m-partner"><?= icon('plus') ?>شريك</button><?php endif; ?></div>
      <ul class="list">
        <?php foreach ($partners as $i => $p): $amount = $profit['split']['rows'][$i]['amount'] ?? 0; ?>
        <li class="list-item">
          <span class="list-icon is-primary share-chip"><b><?= ltr($pct((float) $p['share']) . '%') ?></b></span>
          <div class="list-item-main"><div class="list-item-title"><?= e($p['name']) ?></div>
            <div class="list-item-sub <?= $amount < 0 ? 'text-danger' : '' ?>"><?= $amount < 0 ? 'يتحمّل ' : '' ?><?= e(money(abs($amount))) ?></div></div>
          <?php if ($managePartners): ?>
          <div class="row-actions">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="تعديل" data-modal-open="m-partner" data-partner="<?= e(json_encode(['id' => (int) $p['id'], 'name' => $p['name'], 'share' => (float) $p['share'], 'phone' => (string) $p['phone'], 'notes' => (string) $p['notes']], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('pencil') ?></button>
            <form method="post" action="<?= e(url('/expenses/partners/' . $p['id'] . '/delete')) ?>" data-confirm="حذف الشريك <?= e($p['name']) ?>؟"><?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form>
          </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if ($partners && $profit['split']['rest']['share'] > 0): ?>
        <li class="list-item"><span class="list-icon share-chip"><b><?= ltr($pct($profit['split']['rest']['share']) . '%') ?></b></span>
          <div class="list-item-main"><div class="list-item-title text-muted">غير موزّع</div><div class="list-item-sub"><?= e(money(abs($profit['split']['rest']['amount']))) ?></div></div></li>
        <?php endif; ?>
        <?php if (!$partners): ?><li class="list-item text-muted">لا يوجد شركاء.</li><?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($manage): ?>
<dialog class="modal" id="m-expense"<?= $expErr ? ' data-auto-open' : '' ?>>
  <form method="post" action="<?= e(url('/expenses')) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $ox('id')) ?>">
    <div class="modal-head"><h3><?= icon('receipt') ?><span data-expense-title><?= $expErr && (int) old('id') > 0 ? 'تعديل مصروف' : 'مصروف جديد' ?></span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-12"><label>الفئة</label>
          <div class="exp-cats">
            <?php foreach (Finance::CATEGORIES as $k => [$label, $ic]): ?>
            <label class="exp-cat-pick"><input type="radio" name="category" value="<?= $k ?>"<?= checked($ox('category', 'fuel') === $k) ?>><span><?= icon($ic) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div><?= error_html('category') ?></div>
        <div class="field col-6"><label for="x-amount">المبلغ</label><div class="input-group"><input class="input input-ltr" id="x-amount" name="amount" value="<?= e((string) $ox('amount')) ?>" inputmode="decimal" required><span class="input-addon"><?= e((string) setting('general.currency', '₪')) ?></span></div><?= error_html('amount') ?></div>
        <div class="field col-6"><label for="x-date">التاريخ</label><input class="input" id="x-date" type="date" name="spent_on" value="<?= e((string) $ox('spent_on', date('Y-m-d'))) ?>" max="<?= e(date('Y-m-d')) ?>" required><?= error_html('spent_on') ?></div>
        <div class="field col-12"><label for="x-desc">البيان</label><input class="input" id="x-desc" name="description" value="<?= e((string) $ox('description')) ?>" maxlength="255"></div>
        <?php if ($sites): ?>
        <div class="field col-12"><label for="x-site">الموقع</label><select class="input" id="x-site" name="site_id"><option value="">—</option><?php foreach ($sites as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((int) $ox('site_id', 0), (int) $id) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($managePartners): ?>
<dialog class="modal modal-sm" id="m-partner"<?= $parErr ? ' data-auto-open' : '' ?>>
  <form method="post" action="<?= e(url('/expenses/partners')) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($op('id')) ?>">
    <div class="modal-head"><h3><?= icon('handshake') ?><span data-partner-title><?= $parErr && (int) old('id') > 0 ? 'تعديل شريك' : 'شريك جديد' ?></span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-12"><label for="p-name">اسم الشريك</label><input class="input" id="p-name" name="name" value="<?= e($op('name')) ?>" required maxlength="120"><?= $parErr ? error_html('name') : '' ?></div>
        <div class="field col-6"><label for="p-share">النسبة</label><div class="input-group"><input class="input input-ltr" id="p-share" name="share" value="<?= e($op('share')) ?>" inputmode="decimal" required><span class="input-addon">%</span></div><?= error_html('share') ?></div>
        <div class="field col-6"><label for="p-phone">الهاتف</label><input class="input input-ltr" id="p-phone" name="phone" value="<?= e($op('phone')) ?>" maxlength="30" inputmode="tel"></div>
        <div class="field col-12"><label for="p-notes">ملاحظات</label><input class="input" id="p-notes" name="notes" value="<?= e($op('notes')) ?>" maxlength="255"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<?php endif; ?>
<script>
(function () {
  var today = <?= json_encode(date('Y-m-d')) ?>;
  var m = document.getElementById('m-expense');
  if (m) {
    m.addEventListener('modal:open', function (e) {
      var f = m.querySelector('form'), t = e.detail && e.detail.trigger, raw = t && t.getAttribute('data-expense');
      if (!t) { return; } // أُعيد فتحها بعد خطأ: تبقى القيم المدخلة
      var x = raw ? JSON.parse(raw) : null, copy = !!(t && t.hasAttribute('data-copy-expense'));
      f.elements.id.value = x && !copy ? x.id : '';
      f.elements.amount.value = x ? x.amount : '';
      f.elements.spent_on.value = x && !copy ? x.spent_on : today;
      f.elements.description.value = x ? x.description : '';
      if (f.elements.site_id) { f.elements.site_id.value = x && x.site_id ? x.site_id : ''; }
      var c = f.querySelector('input[name=category][value="' + (x ? x.category : 'fuel') + '"]');
      if (c) { c.checked = true; }
      m.querySelector('[data-expense-title]').textContent = x && !copy ? 'تعديل مصروف' : 'مصروف جديد';
    });
  }
  var p = document.getElementById('m-partner');
  if (p) {
    p.addEventListener('modal:open', function (e) {
      var f = p.querySelector('form'), t = e.detail && e.detail.trigger, raw = t && t.getAttribute('data-partner'), x = raw ? JSON.parse(raw) : null;
      if (!t) { return; }
      ['id', 'name', 'share', 'phone', 'notes'].forEach(function (k) { f.elements[k].value = x ? x[k] : ''; });
      p.querySelector('[data-partner-title]').textContent = x ? 'تعديل شريك' : 'شريك جديد';
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    var d = document.querySelector('dialog[data-auto-open]');
    if (d && typeof d.showModal === 'function' && !d.open) { d.showModal(); }
  });
})();
</script>
