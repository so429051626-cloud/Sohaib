<?php
/**
 * @var App\Core\View $v @var array $rows @var bool $available @var int $show
 */
$v->title = 'الأنفاق الآمنة';
$v->breadcrumbs = [['أجهزة MikroTik', '/routers'], [$v->title, null]];
$connected = count(array_filter($rows, static fn ($r) => $r['state']['connected']));
?>
<?php if (!$available && !$rows): ?>
<div class="panel"><div class="empty"><?= icon('waypoints') ?><div class="empty-title">الأنفاق الآمنة غير متاحة على هذا الخادم</div></div></div>
<?php return; endif; ?>

<div class="stats">
  <div class="stat"><div class="stat-icon"><?= icon('waypoints') ?></div><div class="stat-body"><div class="stat-label">الأنفاق</div><div class="stat-value"><?= num(count($rows)) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('plug') ?></div><div class="stat-body"><div class="stat-label">متصل</div><div class="stat-value"><?= num($connected) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-danger"><?= icon('unplug') ?></div><div class="stat-body"><div class="stat-label">غير متصل</div><div class="stat-value"><?= num(count($rows) - $connected) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="panel">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>جهاز MikroTik</th><th>عنوان النفق</th><th>الحالة</th><th>في النظام</th><th class="w-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $st = $r['state']; ?>
          <tr>
            <td class="cell-title"><?= e($r['name']) ?></td>
            <td><button type="button" class="chip-copy mono" data-copy="<?= e($r['address']) ?>" title="نسخ"><?= e($r['address']) ?><?= icon('copy', 'icon-sm') ?></button></td>
            <td>
              <?php if ($st['connected']): ?>
                <span class="badge badge-success badge-dot">متصل</span>
              <?php elseif ($st['handshake'] > 0): ?>
                <span class="badge badge-warning badge-dot">انقطع <?= e(time_ago(date('Y-m-d H:i:s', $st['handshake']))) ?></span>
              <?php else: ?>
                <span class="badge badge-outline badge-dot">لم يتصل بعد</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['router'] !== null): ?>
                <span class="text-sm"><?= e($r['router']) ?></span>
              <?php elseif (can('routers.manage')): ?>
                <a class="btn btn-sm" href="<?= e(url('/routers/create', ['host' => $r['address'], 'name' => $r['name']])) ?>"><?= icon('plus') ?>إضافة جهاز MikroTik</a>
              <?php endif; ?>
            </td>
            <td class="w-actions">
              <div class="row-actions">
                <button type="button" class="btn btn-ghost btn-icon btn-sm" data-tunnel-script="<?= (int) $r['id'] ?>" title="أوامر جهاز MikroTik"><?= icon('square-terminal') ?></button>
                <form method="post" action="<?= e(url('/tunnels/' . $r['id'] . '/delete')) ?>" data-confirm="حذف النفق <?= e($r['name']) ?>؟">
                  <?= csrf_field() ?><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5"><div class="empty"><?= icon('waypoints') ?><div class="empty-title">لا توجد أنفاق</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($available): ?>
  <form method="post" action="<?= e(url('/tunnels')) ?>" class="panel">
    <?= csrf_field() ?>
    <div class="panel-head"><h3><?= icon('plus') ?>نفق جديد</h3></div>
    <div class="panel-body">
      <div class="field mb-2">
        <label for="name">اسم جهاز MikroTik <span class="req">*</span></label>
        <input class="input" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="100">
        <?= error_html('name') ?>
      </div>
      <button class="btn btn-primary"><?= icon('waypoints') ?>إنشاء</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<dialog class="modal" id="m-tunnel">
  <div class="modal-head"><h3><?= icon('square-terminal') ?><span data-t-name></span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body stack" style="gap:12px">
    <div class="meter-row"><span>أوامر RouterOS 7 · Terminal</span><strong class="mono" data-t-address></strong></div>
    <textarea class="input mono input-ltr tunnel-script" rows="9" readonly spellcheck="false" data-t-script></textarea>
  </div>
  <div class="modal-foot">
    <form method="post" data-t-regen data-confirm="إنشاء مفاتيح جديدة للنفق؟" style="margin-inline-end:auto">
      <?= csrf_field() ?><button class="btn btn-ghost"><?= icon('key-round') ?>مفاتيح جديدة</button>
    </form>
    <a class="btn" data-t-download><?= icon('download') ?>ملف ‎.rsc</a>
    <button type="button" class="btn btn-primary" data-t-copy><?= icon('copy') ?>نسخ الأوامر</button>
  </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('m-tunnel');
  function show(id) {
    SN.api(SN.base + '/tunnels/' + id + '/script').then(function (r) {
      m.querySelector('[data-t-name]').textContent = r.name;
      m.querySelector('[data-t-address]').textContent = r.address;
      m.querySelector('[data-t-script]').value = r.script;
      m.querySelector('[data-t-download]').href = SN.base + '/tunnels/' + id + '/download';
      m.querySelector('[data-t-regen]').action = SN.base + '/tunnels/' + id + '/regenerate';
      SN.openModal(m);
    }).catch(function () {});
  }
  SN.on(document, 'click', '[data-tunnel-script]', function (e, el) { show(el.getAttribute('data-tunnel-script')); });
  m.querySelector('[data-t-copy]').addEventListener('click', function () {
    SN.copy(m.querySelector('[data-t-script]').value).then(function () { SN.toast('success', 'تم النسخ.'); }, function () { SN.toast('error', 'تعذّر النسخ.'); });
  });
  <?php if ($show > 0): ?>show(<?= (int) $show ?>);<?php endif; ?>
});
</script>
