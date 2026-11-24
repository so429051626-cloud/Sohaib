<?php
/** @var App\Core\View $v */
use App\Core\Request;

$v->title = 'دليل الأجهزة';
$req = Request::current();
if (can('devices.block')) {
    $v->actions = '<button type="button" class="btn btn-soft-danger" data-modal-open="block-modal">' . icon('ban') . 'حظر جهاز</button>';
}
?>
<?php if ($blocked): ?>
<div class="panel mb-3">
  <div class="panel-head"><h3><?= icon('shield-ban') ?>الأجهزة المحظورة <span class="sub">(<?= num(count($blocked)) ?>)</span></h3></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>MAC</th><th>الجهاز</th><th>السبب</th><th>بواسطة</th><th>التاريخ</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($blocked as $b): ?>
        <tr>
          <td><span class="mono"><?= e($b['mac']) ?></span></td>
          <td><?= e($b['hostname'] ?: '—') ?></td>
          <td><?= e($b['reason'] ?: '—') ?></td>
          <td><?= e($b['employee_name'] ?? '—') ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($b['created_at'])) ?></td>
          <td class="w-actions">
            <?php if (can('devices.block')): ?>
            <form method="post" action="<?= e(url('/devices/unblock')) ?>" data-confirm="إلغاء حظر <?= e($b['mac']) ?>؟" data-confirm-type="primary">
              <?= csrf_field() ?><input type="hidden" name="mac" value="<?= e($b['mac']) ?>">
              <button class="btn btn-soft btn-sm"><?= icon('lock-open') ?>إلغاء الحظر</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <form class="filters" method="get">
    <div class="field grow"><label for="q">بحث</label><input class="input" id="q" name="q" value="<?= e($req->str('q')) ?>" placeholder="MAC، اسم الجهاز، IP"></div>
    <div class="field">
      <label for="router">جهاز MikroTik</label>
      <select class="input" id="router" name="router" data-autosubmit>
        <option value="">الكل</option>
        <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($req->int('router'), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto"><button class="btn"><?= icon('search') ?>بحث</button></div>
  </form>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>اسم الجهاز</th><th>MAC</th><th>آخر IP</th><th>جهاز MikroTik</th><th>آخر كرت</th><th>أول ظهور</th><th>آخر ظهور</th><th class="w-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $d): ?>
        <tr>
          <td class="cell-title"><?= e($d['hostname'] ?: '—') ?><?= $d['blocked'] ? ' <span class="badge badge-danger">محظور</span>' : '' ?></td>
          <td><a class="mono" href="<?= e(url('/sessions/history', ['q' => $d['mac']])) ?>"><?= e($d['mac']) ?></a></td>
          <td><span class="mono text-sm"><?= e($d['last_ip'] ?? '—') ?></span></td>
          <td><?= e($d['router_name'] ?? '—') ?></td>
          <td><?= $d['last_card'] ? '<a class="mono" href="' . e(url('/check', ['q' => $d['last_card']])) . '">' . e($d['last_card']) . '</a>' : '—' ?></td>
          <td class="nowrap text-sm"><?= e(fmt_dt($d['first_seen'])) ?></td>
          <td class="nowrap text-sm"><?= e(time_ago($d['last_seen'])) ?></td>
          <td class="w-actions">
            <?php if (can('devices.block') && !$d['blocked']): ?>
            <form method="post" action="<?= e(url('/devices/block')) ?>" data-confirm="حظر الجهاز <?= e($d['mac']) ?> على كل أجهزة MikroTik؟">
              <?= csrf_field() ?><input type="hidden" name="mac" value="<?= e($d['mac']) ?>">
              <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حظر"><?= icon('ban') ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8"><div class="empty"><?= icon('smartphone') ?><div class="empty-title">لا توجد أجهزة مسجّلة بعد</div></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $pager->render($req) ?>
</div>

<?php if (can('devices.block')): ?>
<dialog class="modal modal-sm" id="block-modal">
  <form method="post" action="<?= e(url('/devices/block')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('ban') ?>حظر جهاز</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="field mb-2"><label>عنوان MAC <span class="req">*</span></label><input class="input input-ltr" name="mac" required placeholder="AA:BB:CC:DD:EE:FF"></div>
      <div class="field"><label>السبب</label><input class="input" name="reason"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-danger"><?= icon('ban') ?>حظر</button></div>
  </form>
</dialog>
<?php endif; ?>
