<?php
/** @var App\Core\View $v @var array $team */
use App\AdminPermissions;
use App\Core\AdminAuth;

$v->title = 'فريق الإدارة';
$v->actions = '<button type="button" class="btn btn-primary" data-modal-open="m-member" data-member="">' . icon('user-plus') . 'إضافة مسؤول</button>';
$all = AdminPermissions::all();
?>
<p class="text-muted text-sm mb-3"><?= icon('info', 'icon-sm') ?> أنت مالك المنصة: تضيف المسؤولين وتحدد لكل واحد ما يراه ويفعله. ما لا يحق للمسؤول يختفي من قائمته ويُرفض إن حاوله، وتغيير صلاحياته أو كلمة مروره أو إيقافه ينهي جلساته المفتوحة فوراً.</p>
<div class="team-grid">
  <?php foreach ($team as $a): $perms = AdminPermissions::of($a); $owner = (int) $a['is_owner'] === 1; $me = (int) $a['id'] === AdminAuth::id(); $full = count($perms) === count($all);
    $payload = ['id' => (int) $a['id'], 'name' => $a['name'], 'username' => $a['username'], 'email' => (string) $a['email'], 'owner' => $owner, 'perms' => $perms,
        'role' => $owner ? 'owner' : (($a['permissions'] ?? null) === null ? 'full' : (array_search(AdminPermissions::roleOf($a), array_map(static fn ($p) => $p[0], AdminPermissions::PRESETS), true) ?: 'custom'))]; ?>
  <div class="panel team-card<?= (int) $a['is_active'] ? '' : ' is-off' ?><?= $owner ? ' is-owner' : '' ?>">
    <div class="team-head">
      <span class="team-avatar"><?= e(mb_substr(trim((string) $a['name']), 0, 1)) ?></span>
      <div class="team-id">
        <b><?= e($a['name']) ?><?= $me ? ' <span class="badge">أنت</span>' : '' ?></b>
        <span class="mono text-sm text-muted"><?= e($a['username']) ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?></span>
      </div>
      <span class="badge <?= $owner ? 'badge-warning' : 'badge-info' ?>"><?= icon($owner ? 'crown' : 'user-cog', 'icon-sm') ?><?= e(AdminPermissions::roleOf($a)) ?></span>
    </div>
    <div class="team-meta">
      <span><?= icon((int) $a['totp_enabled'] ? 'shield-check' : 'lock', 'icon-sm') ?><?= (int) $a['totp_enabled'] ? 'تحقق بخطوتين مفعّل' : 'بلا تحقق بخطوتين' ?></span>
      <span><?= icon('history', 'icon-sm') ?><?= $a['last_login_at'] ? 'آخر دخول ' . e(time_ago($a['last_login_at'])) : 'لم يدخل بعد' ?></span>
      <?php if (!(int) $a['is_active']): ?><span class="badge badge-danger">موقوف</span><?php endif; ?>
    </div>
    <div class="team-perms">
      <?php if ($full): ?><span class="team-perm is-all"><?= icon('check', 'icon-sm') ?>كل الصلاحيات<?= $owner ? ' وإدارة الفريق' : '' ?></span>
      <?php else: foreach ($perms as $p): ?><span class="team-perm"><?= icon('check', 'icon-sm') ?><?= e(AdminPermissions::label($p)) ?></span><?php endforeach; endif; ?>
    </div>
    <div class="team-actions">
      <button type="button" class="btn btn-sm" data-modal-open="m-member" data-member="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>"><?= icon('pencil') ?>تعديل</button>
      <?php if (!$owner && !$me): ?>
        <form method="post" action="<?= e(url('/admin/team/' . $a['id'] . '/status')) ?>"<?= (int) $a['is_active'] ? ' data-confirm="إيقاف ' . e($a['name']) . ' وإنهاء جلساته الآن؟"' : '' ?>><?= csrf_field() ?><input type="hidden" name="active" value="<?= (int) $a['is_active'] ? '0' : '1' ?>"><button class="btn btn-sm btn-ghost"><?= icon((int) $a['is_active'] ? 'user-x' : 'user-check') ?><?= (int) $a['is_active'] ? 'إيقاف' : 'تفعيل' ?></button></form>
        <form method="post" action="<?= e(url('/admin/team/' . $a['id'] . '/delete')) ?>" data-confirm="حذف المسؤول <?= e($a['name']) ?> نهائياً؟"><?= csrf_field() ?><button class="btn btn-sm btn-ghost text-danger"><?= icon('trash-2') ?>حذف</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<dialog class="modal" id="m-member" aria-labelledby="m-member-title">
  <form method="post" action="<?= e(url('/admin/team')) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="id" value="">
    <div class="modal-head"><h3 id="m-member-title"><?= icon('user-cog') ?><span data-member-title>مسؤول جديد</span></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close aria-label="إغلاق"><?= icon('x') ?></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field col-6"><label for="mm-name">الاسم</label><input class="input" id="mm-name" name="name" required maxlength="120"></div>
        <div class="field col-6"><label for="mm-user">اسم المستخدم</label><input class="input input-ltr" id="mm-user" name="username" required maxlength="60" autocapitalize="off" spellcheck="false"></div>
        <div class="field col-6"><label for="mm-email">البريد (اختياري)</label><input class="input input-ltr" id="mm-email" type="email" name="email" maxlength="190"></div>
        <div class="field col-6"><label for="mm-pass">كلمة المرور</label><input class="input input-ltr" id="mm-pass" type="password" name="password" minlength="8" autocomplete="new-password"><span class="text-sm text-muted" data-member-passhint hidden>اتركها فارغة لإبقاء الحالية</span></div>
      </div>
      <div class="team-owner-note" data-member-owner hidden><?= icon('crown') ?>مالك المنصة له كل الصلاحيات دائماً، وهو وحده من يدير الفريق.</div>
      <div data-member-roles>
        <div class="field mt-3"><label>الدور</label></div>
        <div class="team-roles">
          <?php foreach (AdminPermissions::PRESETS as $k => [$label, $desc, $p]): ?>
          <label class="team-role"><input type="radio" name="role" value="<?= e($k) ?>" data-perms="<?= e(json_encode($p === '*' ? $all : $p)) ?>"><span><b><?= e($label) ?></b><small><?= e($desc) ?></small></span></label>
          <?php endforeach; ?>
          <label class="team-role"><input type="radio" name="role" value="custom"><span><b>مخصّص</b><small>تختار الصلاحيات بنفسك</small></span></label>
        </div>
        <div class="team-perm-groups">
          <?php foreach (AdminPermissions::GROUPS as $group => $items): ?>
          <fieldset><legend><?= e($group) ?></legend>
            <?php foreach ($items as $k => $label): ?><label class="check"><input type="checkbox" name="perms[]" value="<?= e($k) ?>"><span><?= e($label) ?></span></label><?php endforeach; ?>
          </fieldset>
          <?php endforeach; ?>
        </div>
        <?= error_html('perms') ?>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('m-member'), f = m.querySelector('form');
  var boxes = Array.prototype.slice.call(f.querySelectorAll('input[name="perms[]"]'));
  var set = function (list) { boxes.forEach(function (b) { b.checked = list.indexOf(b.value) !== -1; }); };
  f.addEventListener('change', function (e) {
    if (e.target.name === 'role' && e.target.value !== 'custom') { set(JSON.parse(e.target.getAttribute('data-perms'))); }
    if (e.target.name === 'perms[]') { f.querySelector('input[name=role][value=custom]').checked = true; }
  });
  m.addEventListener('modal:open', function (e) {
    var raw = e.detail.trigger.getAttribute('data-member'), a = raw ? JSON.parse(raw) : null;
    f.reset();
    f.id.value = a ? a.id : '';
    f.name.value = a ? a.name : ''; f.username.value = a ? a.username : ''; f.email.value = a ? a.email : '';
    f.password.required = !a;
    m.querySelector('[data-member-passhint]').hidden = !a;
    m.querySelector('[data-member-title]').textContent = a ? 'تعديل ' + a.name : 'مسؤول جديد';
    var owner = !!(a && a.owner);
    m.querySelector('[data-member-owner]').hidden = !owner;
    m.querySelector('[data-member-roles]').hidden = owner;
    var role = a ? a.role : 'support';
    var r = f.querySelector('input[name=role][value="' + role + '"]') || f.querySelector('input[name=role][value=custom]');
    if (!owner) { r.checked = true; set(a ? a.perms : JSON.parse(r.getAttribute('data-perms') || '[]')); }
  });
});
</script>
