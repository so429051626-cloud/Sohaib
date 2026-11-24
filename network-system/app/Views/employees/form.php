<?php
/** @var App\Core\View $v */
use App\Permissions;

$isEdit = $emp !== null;
$isOwner = $isEdit && $emp['is_owner'];
$v->title = $isEdit ? 'تعديل موظف' : 'إضافة موظف';
$v->breadcrumbs = [['الموظفون', '/employees'], [$v->title, null]];
$action = $isEdit ? url('/employees/' . $emp['id']) : url('/employees');
$selectedPerms = old('permissions', $assigned);
$selectedRouters = array_map('intval', (array) old('routers', $assignedRouters));
$presets = [];
foreach (Permissions::PRESETS as $key => $p) {
    $presets[$key] = Permissions::presetPermissions($key);
}
?>
<form method="post" action="<?= e($action) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('user') ?>بيانات الموظف</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="name">الاسم الكامل <span class="req">*</span></label>
              <input class="input" id="name" name="name" value="<?= e(old('name', $emp['name'] ?? '')) ?>" required>
              <?= error_html('name') ?>
            </div>
            <div class="field col-6">
              <label for="username">اسم الدخول <span class="req">*</span></label>
              <input class="input input-ltr" id="username" name="username" value="<?= e(old('username', $emp['username'] ?? '')) ?>" required autocapitalize="off">
              <?= error_html('username') ?>
            </div>
            <div class="field col-6">
              <label for="phone">الهاتف</label>
              <input class="input input-ltr" id="phone" name="phone" value="<?= e(old('phone', $emp['phone'] ?? '')) ?>">
            </div>
            <div class="field col-6">
              <label for="email">البريد الإلكتروني</label>
              <input class="input input-ltr" id="email" name="email" type="email" value="<?= e(old('email', $emp['email'] ?? '')) ?>">
              <?= error_html('email') ?>
            </div>
            <div class="field col-6">
              <label for="password">كلمة المرور <?= $isEdit ? '' : '<span class="req">*</span>' ?></label>
              <input class="input input-ltr" id="password" name="password" type="password" minlength="8" autocomplete="new-password" <?= $isEdit ? 'placeholder="اتركه فارغاً للإبقاء على الحالية"' : 'required' ?>>
              <?= error_html('password') ?>
            </div>
            <div class="field col-6">
              <label for="password_confirmation">تأكيد كلمة المرور</label>
              <input class="input input-ltr" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
            </div>
          </div>
        </div>
      </div>

      <?php if (!$isOwner): ?>
      <div class="panel">
        <div class="panel-head">
          <h2><?= icon('shield-check') ?>الصلاحيات</h2>
          <div class="panel-tools">
            <button type="button" class="btn btn-sm" data-perm-all>تحديد الكل</button>
            <button type="button" class="btn btn-sm" data-perm-none>إلغاء الكل</button>
          </div>
        </div>
        <div class="panel-body">
          <div class="grid grid-2">
          <?php foreach (Permissions::GROUPS as $group => $perms): ?>
            <fieldset class="perm-group" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin:0">
              <legend class="flex items-center gap-2" style="padding:0 6px;font-weight:600;font-size:13.5px">
                <label class="check"><input type="checkbox" data-perm-group><?= e($group) ?></label>
              </legend>
              <div class="stack" style="gap:8px">
              <?php foreach ($perms as $key => $label):
                  $disabled = !in_array($key, $grantable, true); ?>
                <label class="check<?= $disabled ? ' text-muted' : '' ?>">
                  <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" data-perm<?= checked(in_array($key, (array) $selectedPerms, true)) ?><?= $disabled ? ' disabled' : '' ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
              </div>
            </fieldset>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="stack">
      <?php if ($isOwner): ?>
        <div class="notice"><?= icon('shield-check') ?><div>حساب المالك يملك جميع الصلاحيات على كل أجهزة MikroTik.</div></div>
      <?php else: ?>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('user-cog') ?>الدور والحالة</h3></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12">
              <label for="role_preset">الدور</label>
              <select class="input" id="role_preset" name="role_preset" data-role-preset>
                <option value="">مخصص</option>
                <?php foreach (Permissions::PRESETS as $key => $p): ?>
                  <option value="<?= e($key) ?>"<?= selected(old('role_preset', $emp['role_preset'] ?? 'seller'), $key) ?>><?= e($p['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field col-12">
              <label for="status">الحالة</label>
              <select class="input" id="status" name="status">
                <option value="active"<?= selected(old('status', $emp['status'] ?? 'active'), 'active') ?>>نشط</option>
                <option value="disabled"<?= selected(old('status', $emp['status'] ?? 'active'), 'disabled') ?>>موقوف</option>
              </select>
              <?= error_html('status') ?>
            </div>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('router') ?>نطاق أجهزة MikroTik</h3></div>
        <div class="panel-body">
          <div class="stack" style="gap:10px">
            <label class="check"><input type="radio" name="router_scope" value="all"<?= checked(old('router_scope', $emp['router_scope'] ?? 'all') === 'all') ?>>جميع أجهزة MikroTik</label>
            <label class="check"><input type="radio" name="router_scope" value="selected"<?= checked(old('router_scope', $emp['router_scope'] ?? 'all') === 'selected') ?>>أجهزة MikroTik محددة</label>
            <div data-show-when="router_scope=selected" class="stack" style="gap:8px;padding-inline-start:26px">
              <?php foreach ($routers as $r): ?>
                <label class="check"><input type="checkbox" name="routers[]" value="<?= (int) $r['id'] ?>"<?= checked(in_array((int) $r['id'], $selectedRouters, true)) ?>><?= e($r['name']) ?></label>
              <?php endforeach; ?>
              <?php if (!$routers): ?><span class="text-muted text-sm">لا توجد أجهزة MikroTik مضافة.</span><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="panel">
        <div class="panel-body flex gap-2">
          <button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <a class="btn" href="<?= e(url('/employees')) ?>">إلغاء</a>
        </div>
      </div>
    </div>
  </div>
</form>
<script type="application/json" id="role-presets"><?= json_encode($presets, JSON_HEX_TAG) ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var presets = JSON.parse(document.getElementById('role-presets').textContent);
  var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-perm]:not(:disabled)')); };
  function syncGroups() {
    document.querySelectorAll('.perm-group').forEach(function (g) {
      var items = g.querySelectorAll('[data-perm]:not(:disabled)'), on = g.querySelectorAll('[data-perm]:not(:disabled):checked');
      var head = g.querySelector('[data-perm-group]');
      head.checked = items.length > 0 && on.length === items.length;
      head.indeterminate = on.length > 0 && on.length < items.length;
    });
  }
  var sel = document.querySelector('[data-role-preset]');
  if (sel) {
    sel.addEventListener('change', function () {
      var list = presets[sel.value];
      if (!list) { return; }
      boxes().forEach(function (b) { b.checked = list.indexOf(b.value) !== -1; });
      syncGroups();
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-perm-group]')) {
      e.target.closest('.perm-group').querySelectorAll('[data-perm]:not(:disabled)').forEach(function (b) { b.checked = e.target.checked; });
    }
    if (e.target.matches('[data-perm], [data-perm-group]') && sel) { sel.value = ''; }
    syncGroups();
  });
  var all = document.querySelector('[data-perm-all]'), none = document.querySelector('[data-perm-none]');
  if (all) { all.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = true; }); if (sel) { sel.value = ''; } syncGroups(); }); }
  if (none) { none.addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); if (sel) { sel.value = ''; } syncGroups(); }); }
  syncGroups();
});
</script>
