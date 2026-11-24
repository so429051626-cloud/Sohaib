<?php
/** @var App\Core\View $v */
use App\Core\Request;
use App\Services\CardRules;
use App\Services\Units;

$v->title = 'الكروت';
$v->subtitle = num($total) . ' كرت مطابق';
$req = Request::current();
$actions = '';
if (can('cards.export')) {
    $actions .= '<div class="dropdown"><button type="button" class="btn" data-dropdown>' . icon('download') . 'تصدير</button><div class="dropdown-menu">'
        . '<a class="dropdown-item" href="' . e(url('/cards/export', $filters + ['format' => 'xlsx'])) . '">' . icon('file-spreadsheet') . 'Excel (كل النتائج)</a>'
        . '<a class="dropdown-item" href="' . e(url('/cards/export', $filters + ['format' => 'csv'])) . '">' . icon('file-text') . 'CSV (كل النتائج)</a></div></div>';
}
if (can('cards.generate')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('/cards/generate')) . '">' . icon('wand-sparkles') . 'توليد كروت</a>';
}
$v->actions = $actions;
$statusTabs = ['' => 'الكل', 'available' => 'المتاحة', 'sold' => 'المباعة', 'active' => 'النشطة', 'ended' => 'المنتهية', 'disabled' => 'الموقوفة'];
$showPass = can('cards.passwords');
$hidden = static function (array $filters, array $skip = []): string {
    $h = '';
    foreach ($filters as $k => $val) {
        if ($val !== '' && !in_array($k, $skip, true)) {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($val) . '">';
        }
    }
    return $h;
};
?>
<?= $v->partial('cards-nav') ?>
<div class="panel" data-bulk-scope>
  <nav class="tabs">
    <?php foreach ($statusTabs as $key => $label): ?>
      <a class="tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e(url('/cards', array_merge($filters, ['status' => $key]))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="filters" method="get">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="رقم الكرت، المختصر، #الرقم، MAC"></div>
    <div class="field">
      <label for="category">الباقة</label>
      <select class="input" id="category" name="category">
        <option value="">الكل</option>
        <?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['category'], $id) ?>><?= e($name) ?></option><?php endforeach; ?>
        <?php if (!empty($hasAccounts)): ?><option value="custom"<?= selected($filters['category'], 'custom') ?>>الحسابات الخاصة</option><?php endif; ?>
      </select>
    </div>
    <div class="field">
      <label for="batch">الدفعة</label>
      <select class="input" id="batch" name="batch">
        <option value="">الكل</option>
        <?php foreach ($batches as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['batch'], $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="distributor">الموزع</label>
      <select class="input" id="distributor" name="distributor">
        <option value="">الكل</option>
        <?php foreach ($distributors as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($filters['distributor'], $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="min-width:120px;flex-basis:130px">
      <label for="printed">الطباعة</label>
      <select class="input" id="printed" name="printed">
        <option value="">الكل</option>
        <option value="no"<?= selected($filters['printed'], 'no') ?>>غير مطبوعة</option>
        <option value="yes"<?= selected($filters['printed'], 'yes') ?>>مطبوعة</option>
      </select>
    </div>
    <div class="field" style="min-width:120px;flex-basis:130px">
      <label for="short">المختصر</label>
      <select class="input" id="short" name="short">
        <option value="">الكل</option>
        <option value="yes"<?= selected($filters['short'], 'yes') ?>>لها رقم مختصر</option>
        <option value="no"<?= selected($filters['short'], 'no') ?>>بدون</option>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary"><?= icon('filter') ?>تصفية</button></div>
  </form>

  <div class="bulkbar">
    <span>المحدد: <strong data-selected-count>0</strong></span>
    <form method="post" action="<?= e(url('/cards/bulk')) ?>" data-bulk-form class="flex gap-1 wrap items-center" id="bulk-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="bulk-action">
      <?php if (can('cards.print')): ?><button type="button" class="btn btn-sm" data-bulk="print"><?= icon('printer') ?>طباعة</button><?php endif; ?>
      <?php if (can('sales.create')): ?><button type="button" class="btn btn-sm" data-bulk="sell"><?= icon('shopping-cart') ?>بيع</button><?php endif; ?>
      <?php if (can('cards.export')): ?><button type="button" class="btn btn-sm" data-bulk="export"><?= icon('download') ?>تصدير</button><?php endif; ?>
      <?php if (can('cards.manage')): ?>
      <div class="dropdown">
        <button type="button" class="btn btn-sm" data-dropdown><?= icon('ellipsis-vertical') ?>إجراءات</button>
        <div class="dropdown-menu align-start">
          <button type="button" class="dropdown-item" data-bulk="disable"><?= icon('ban') ?>إيقاف</button>
          <button type="button" class="dropdown-item" data-bulk="enable"><?= icon('play') ?>تفعيل</button>
          <button type="button" class="dropdown-item" data-modal-open="extend-modal"><?= icon('calendar-clock') ?>تمديد</button>
          <button type="button" class="dropdown-item" data-modal-open="move-modal"><?= icon('layers') ?>نقل إلى باقة</button>
          <button type="button" class="dropdown-item" data-bulk="unbind"><?= icon('link-2-off') ?>فك ربط الجهاز</button>
          <button type="button" class="dropdown-item" data-modal-open="push-modal"><?= icon('cloud-upload') ?>رفع إلى جهاز MikroTik</button>
          <div class="dropdown-sep"></div>
          <button type="button" class="dropdown-item" data-bulk="mark_printed"><?= icon('check') ?>تعليم كمطبوعة</button>
          <button type="button" class="dropdown-item" data-bulk="unmark_printed"><?= icon('x') ?>إلغاء تعليم الطباعة</button>
          <?php if (can('cards.short')): ?>
          <div class="dropdown-sep"></div>
          <button type="button" class="dropdown-item" data-modal-open="short-modal"><?= icon('link-2') ?>تخصيص أرقام مختصرة</button>
          <button type="button" class="dropdown-item" data-bulk="clear_short"><?= icon('link-2-off') ?>إزالة الأرقام المختصرة</button>
          <?php endif; ?>
          <?php if (can('cards.delete')): ?>
          <div class="dropdown-sep"></div>
          <button type="button" class="dropdown-item is-danger" data-bulk="delete" data-danger><?= icon('trash-2') ?>حذف</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <label class="check text-sm ms-auto"><input type="checkbox" name="all_matching" value="1" id="all-matching">تطبيق على كل النتائج (<?= num($total) ?>)</label>
      <?= $hidden($filters) ?>
      <div id="bulk-extra" hidden></div>
    </form>
  </div>

  <div class="table-wrap">
    <table class="table table-compact">
      <thead>
        <tr>
          <th class="w-check"><input type="checkbox" data-select-all aria-label="تحديد الكل"></th>
          <th class="hide-sm">#</th><th>اسم المستخدم</th><th>الحالة</th><th>كلمة المرور</th><th>المختصر</th><th>الباقة</th>
          <th>الاستخدام</th><th>الانتهاء</th><th>الجهاز</th><th>الدفعة</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td class="w-check"><input type="checkbox" data-row-check value="<?= (int) $c['id'] ?>" aria-label="تحديد"></td>
          <td class="mono text-sm text-muted hide-sm"><?= (int) $c['id'] ?></td>
          <td><a class="mono cell-title" href="<?= e(url('/check/card/' . $c['id'])) ?>"><?= e($c['username']) ?></a><?= $c['printed_at'] ? ' <span title="مطبوعة">' . icon('printer', 'icon-sm text-muted') . '</span>' : '' ?></td>
          <td class="nowrap"><?= CardRules::statusBadge($c) ?></td>
          <td><?php if ($c['password'] === $c['username']): ?><span class="text-muted text-sm nowrap">نفس الاسم</span><?php elseif ($showPass): ?><button type="button" class="btn btn-ghost btn-xs" data-reveal="<?= e($c['password']) ?>"><?= icon('eye') ?>إظهار</button><?php else: ?>••••<?php endif; ?></td>
          <td><span class="mono"><?= e($c['short_code'] ?? '—') ?></span><?php if (!empty($c['short_password'])): ?><div class="cell-sub mono"><?= $showPass ? e($c['short_password']) : '••••' ?></div><?php endif; ?></td>
          <td class="nowrap"><span class="swatch" style="background:<?= e($c['category_color']) ?>"></span> <?= e($c['category_name']) ?></td>
          <td class="nowrap text-sm"><?= (int) $c['used_seconds'] > 0 ? e(fmt_duration($c['used_seconds'], true)) . ' · ' . e(fmt_bytes(CardRules::usedBytes($c))) : '—' ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($c['expires_at'])) ?></td>
          <td><span class="mono text-sm"><?= e($c['bound_mac'] ?? '—') ?></span></td>
          <td class="text-sm"><a href="<?= e(url('/batches/' . $c['batch_id'])) ?>"><?= e(str_limit($c['batch_name'], 26)) ?></a><?= $c['distributor_name'] ? '<div class="cell-sub">' . e($c['distributor_name']) . '</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="11"><div class="empty"><?= icon('ticket') ?><div class="empty-title">لا توجد كروت</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>

<?php if (can('cards.manage')): ?>
<dialog class="modal modal-sm" id="extend-modal">
  <div class="modal-head"><h3><?= icon('calendar-clock') ?>تمديد الكروت المحددة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body stack" style="gap:12px" data-extra-for="extend">
    <div class="field"><label>إضافة وقت استخدام</label><div class="input-group"><input class="input" name="add_time" inputmode="decimal"><select class="input" name="add_time_unit"><?php foreach (Units::TIME_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'hour' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="field"><label>إضافة صلاحية</label><div class="input-group"><input class="input" name="add_validity" inputmode="decimal"><select class="input" name="add_validity_unit"><?php foreach (Units::TIME_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'day' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="field"><label>إضافة بيانات</label><div class="input-group"><input class="input" name="add_data" inputmode="decimal"><select class="input" name="add_data_unit"><?php foreach (Units::DATA_UNITS as $k => [$l]): ?><option value="<?= $k ?>"<?= $k === 'GB' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button type="button" class="btn btn-primary" data-bulk-submit="extend"><?= icon('check') ?>تمديد</button></div>
</dialog>
<dialog class="modal modal-sm" id="move-modal">
  <div class="modal-head"><h3><?= icon('layers') ?>نقل إلى باقة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body" data-extra-for="move">
    <div class="field"><label>الباقة الجديدة</label><select class="input" name="category_id"><?php foreach ($categories as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button type="button" class="btn btn-primary" data-bulk-submit="move"><?= icon('check') ?>نقل</button></div>
</dialog>
<dialog class="modal modal-sm" id="push-modal">
  <div class="modal-head"><h3><?= icon('cloud-upload') ?>رفع الكروت إلى جهاز MikroTik</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body stack" style="gap:8px" data-extra-for="push">
    <?php foreach ($routers as $id => $name): ?><label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $id ?>"><?= e($name) ?></label><?php endforeach; ?>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button type="button" class="btn btn-primary" data-bulk-submit="push"><?= icon('cloud-upload') ?>رفع</button></div>
</dialog>
<?php endif; ?>
<?php if (can('cards.short')): ?>
<dialog class="modal modal-sm" id="short-modal">
  <div class="modal-head"><h3><?= icon('link-2') ?>تخصيص أرقام مختصرة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body stack" style="gap:12px" data-extra-for="short">
    <div class="field"><label>طول اسم المستخدم المختصر</label><input class="input" name="short_length" value="6" inputmode="numeric"></div>
    <div class="field"><label>طول كلمة المرور المختصرة</label><input class="input" name="short_password_length" value="0" inputmode="numeric"><span class="hint">0 = بدون كلمة مرور</span></div>
    <div class="field"><label>طريقة التوليد</label><select class="input" name="short_mode"><?php foreach (\App\Services\ShortCodes::MODES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>رقم البداية (للتسلسلي)</label><input class="input" name="short_start" inputmode="numeric"></div>
    <label class="check"><input type="checkbox" name="overwrite" value="1">استبدال الأرقام الموجودة</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button type="button" class="btn btn-primary" data-bulk-submit="short"><?= icon('check') ?>تطبيق</button></div>
</dialog>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('bulk-form');
  if (!form) { return; }
  var actionInput = document.getElementById('bulk-action');
  var extra = document.getElementById('bulk-extra');
  function submit(action, danger) {
    var count = form.closest('[data-bulk-scope]').querySelectorAll('[data-row-check]:checked').length;
    var all = document.getElementById('all-matching').checked;
    if (!count && !all) { SN.toast('warning', 'حدد كرتاً واحداً على الأقل.'); return; }
    actionInput.value = action;
    extra.innerHTML = '';
    var src = document.querySelector('[data-extra-for="' + action + '"]');
    if (src) {
      src.querySelectorAll('input, select').forEach(function (el) {
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
        var h = document.createElement('input'); h.type = 'hidden'; h.name = el.name; h.value = el.value; extra.appendChild(h);
      });
    }
    var go = function () { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); form.submit(); };
    if (danger || all) {
      SN.confirm(all ? 'سيُطبَّق الإجراء على كل الكروت المطابقة للتصفية. متابعة؟' : 'تأكيد تنفيذ الإجراء على الكروت المحددة؟', { danger: !!danger }).then(function (y) { if (y) { go(); } });
    } else { go(); }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-bulk]');
    if (b) { e.preventDefault(); submit(b.getAttribute('data-bulk'), b.hasAttribute('data-danger')); }
    var s = e.target.closest('[data-bulk-submit]');
    if (s) { e.preventDefault(); SN.closeModal(s.closest('dialog')); submit(s.getAttribute('data-bulk-submit')); }
  });
});
</script>
