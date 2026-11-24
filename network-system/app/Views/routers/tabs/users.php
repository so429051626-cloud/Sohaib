<?php
/** @var App\Core\View $v */
use App\Services\Provisioner;

$rid = (int) $router['id'];
$managed = 0;
foreach ($rows as $u) {
    if (Provisioner::parseTag($u['comment'])) {
        $managed++;
    }
}
$q = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['show'] ?? 'all');
$list = array_values(array_filter($rows, static function ($u) use ($q, $filter) {
    $tag = Provisioner::parseTag($u['comment']);
    if ($filter === 'managed' && !$tag) {
        return false;
    }
    if ($filter === 'local' && $tag) {
        return false;
    }
    return $q === '' || stripos($u['name'], $q) !== false || stripos((string) $u['comment'], $q) !== false;
}));
$shown = array_slice($list, 0, 500);
?>
<div class="panel-body">
  <div class="flex items-center justify-between gap-2 wrap mb-2">
    <div class="text-sm text-muted">الإجمالي <?= num(count($rows)) ?> · مُدار من النظام <?= num($managed) ?> · محلي <?= num(count($rows) - $managed) ?></div>
    <button type="button" class="btn btn-primary btn-sm" data-modal-open="add-user"><?= icon('user-plus') ?>مستخدم محلي جديد</button>
  </div>
  <form method="get" class="flex gap-2 wrap mb-2">
    <input type="hidden" name="tab" value="users">
    <input class="input input-sm" name="q" value="<?= e($q) ?>" placeholder="بحث بالاسم" style="max-width:240px">
    <select class="input input-sm" name="show" style="max-width:180px" data-autosubmit>
      <option value="all"<?= selected($filter, 'all') ?>>الكل</option>
      <option value="managed"<?= selected($filter, 'managed') ?>>المُدار من النظام</option>
      <option value="local"<?= selected($filter, 'local') ?>>المحلي فقط</option>
    </select>
    <button class="btn btn-sm"><?= icon('search') ?></button>
  </form>
</div>
<div class="table-wrap">
  <table class="table table-compact">
    <thead><tr><th>الاسم</th><th>الملف</th><th>الاستهلاك</th><th>الحد</th><th>MAC</th><th>الحالة</th><th>المصدر</th><th class="w-actions"></th></tr></thead>
    <tbody>
    <?php foreach ($shown as $u):
        $tag = Provisioner::parseTag($u['comment']); ?>
      <tr>
        <td>
          <?php if ($tag && $tag[0] === 'card'): ?><a class="mono" href="<?= e(url('/check/card/' . $tag[1])) ?>"><?= e($u['name']) ?></a>
          <?php elseif ($tag): ?><a class="mono" href="<?= e(url('/subscribers/' . $tag[1])) ?>"><?= e($u['name']) ?></a>
          <?php else: ?><span class="mono"><?= e($u['name']) ?></span><?php endif; ?>
        </td>
        <td><span class="mono text-sm"><?= e($u['profile']) ?></span></td>
        <td class="nowrap text-sm"><?= e(fmt_duration($u['uptime'], true)) ?> · <?= e(fmt_bytes($u['bytes_in'] + $u['bytes_out'])) ?></td>
        <td class="nowrap text-sm"><?= e(\App\Services\RouterImport::describeUser($u)) ?></td>
        <td><span class="mono text-sm"><?= e($u['mac'] ?? '—') ?></span></td>
        <td><?= $u['disabled'] ? '<span class="badge badge-danger">موقوف</span>' : '<span class="badge badge-success">فعّال</span>' ?></td>
        <td><?= $tag ? '<span class="badge badge-primary">النظام</span>' : '<span class="badge badge-outline">محلي</span>' ?></td>
        <td class="w-actions">
          <div class="row-actions">
          <?php if (!$tag): ?>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>">
              <?= csrf_field() ?><input type="hidden" name="tab" value="users"><input type="hidden" name="op" value="user_toggle"><input type="hidden" name="ref" value="<?= e($u['id']) ?>"><input type="hidden" name="disable" value="<?= $u['disabled'] ? '0' : '1' ?>">
              <button class="btn btn-ghost btn-icon btn-sm" title="<?= $u['disabled'] ? 'تفعيل' : 'إيقاف' ?>"><?= icon($u['disabled'] ? 'play' : 'pause') ?></button>
            </form>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="تصفير عدّادات <?= e($u['name']) ?>؟" data-confirm-type="primary">
              <?= csrf_field() ?><input type="hidden" name="tab" value="users"><input type="hidden" name="op" value="user_reset"><input type="hidden" name="ref" value="<?= e($u['id']) ?>">
              <button class="btn btn-ghost btn-icon btn-sm" title="تصفير العدّادات"><?= icon('rotate-ccw') ?></button>
            </form>
            <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>" data-confirm="حذف المستخدم <?= e($u['name']) ?> من جهاز MikroTik؟">
              <?= csrf_field() ?><input type="hidden" name="tab" value="users"><input type="hidden" name="op" value="user_remove"><input type="hidden" name="ref" value="<?= e($u['id']) ?>">
              <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
            </form>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shown): ?><tr><td colspan="8"><div class="empty"><?= icon('users') ?><div>لا يوجد مستخدمون.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php if (count($list) > 500): ?><div class="panel-foot text-sm text-muted">يُعرض أول 500 من <?= num(count($list)) ?></div><?php endif; ?>

<dialog class="modal" id="add-user">
  <form method="post" action="<?= e(url('/routers/' . $rid . '/action')) ?>">
    <?= csrf_field() ?><input type="hidden" name="tab" value="users"><input type="hidden" name="op" value="user_add">
    <div class="modal-head"><h3><?= icon('user-plus') ?>مستخدم هوتسبوت محلي</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label>اسم المستخدم <span class="req">*</span></label><input class="input input-ltr" name="name" required></div>
        <div class="field col-6"><label>كلمة المرور</label><input class="input input-ltr" name="password"></div>
        <div class="field col-6">
          <label>ملف السرعة</label>
          <select class="input" name="profile"><?php foreach ($profiles as $p): ?><option value="<?= e($p) ?>"><?= e($p) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-6"><label>حد الوقت</label><input class="input input-ltr" name="limit_uptime" placeholder="1d / 5h / 30m"></div>
        <div class="field col-6"><label>حد البيانات (GB)</label><input class="input input-ltr" name="limit_gb" inputmode="decimal"></div>
        <div class="field col-6"><label>ربط بجهاز (MAC)</label><input class="input input-ltr" name="mac" placeholder="AA:BB:CC:DD:EE:FF"></div>
        <div class="field col-12"><label>تعليق</label><input class="input" name="comment"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('save') ?>إضافة</button></div>
  </form>
</dialog>
