<?php
/**
 * @var App\Core\View $v @var array $t @var array $stats @var array $employees @var array $audit @var array $activity @var array $payments @var int $dbSize
 */
use App\Controllers\AdminController;
use App\Core\Audit;
use App\Core\Tenant;
use App\Permissions;

[$sl, $sc] = AdminController::STATUS[$t['status']];
$left = Tenant::daysLeft($t);
$usable = Tenant::usable($t);
$v->title = $t['name'];
$v->breadcrumbs = [['الحسابات', '/admin/tenants'], [$t['name'], null]];
$v->subtitle = '/' . $t['slug'];
$cur = App\Core\Platform::setting('platform.currency');
$amount = static fn ($a) => ltr(number_format((float) $a, abs((float) $a - round((float) $a)) < 0.005 ? 0 : 2, '.', ',') . ' ' . $cur);
$v->actions = '<form method="post" action="' . e(url('/admin/tenants/' . $t['id'] . '/backup')) . '" style="display:inline">' . csrf_field() . '<button class="btn" data-no-loading>' . icon('database') . 'نسخة احتياطية</button></form>'
    . '<a class="btn" href="' . e(url('/' . $t['slug'] . '/')) . '" target="_blank" rel="noopener">' . icon('external-link') . 'فتح الرابط</a>'
    . ($usable ? '<form method="post" action="' . e(url('/admin/tenants/' . $t['id'] . '/impersonate')) . '" style="display:inline">' . csrf_field() . '<button class="btn btn-primary">' . icon('log-in') . 'الدخول إلى النظام</button></form>' : '');
$s = static fn (string $k) => $stats[$k] ?? 0;
?>
<div class="kpi-row">
  <div class="stat"><div class="stat-icon <?= $usable ? 'is-success' : 'is-warning' ?>"><?= icon($usable ? 'circle-check' : 'clock') ?></div><div class="stat-body"><div class="stat-label">الحالة</div><div class="stat-value" style="font-size:18px"><span class="badge <?= $sc ?>"><?= $sl ?></span></div><div class="stat-meta"><?= in_array($t['status'], ['trial', 'active'], true) ? ($left === null ? 'دون تاريخ انتهاء' : 'متبقي ' . num((int) $left) . ' يوم') : e($t['status_reason'] ?? '') ?></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('router') ?></div><div class="stat-body"><div class="stat-label">أجهزة MikroTik</div><div class="stat-value"><?= num($s('routers')) ?></div><div class="stat-meta"><?= num($s('online_routers')) ?> متصل</div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('ticket') ?></div><div class="stat-body"><div class="stat-label">الكروت</div><div class="stat-value"><?= num($s('cards')) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-violet"><?= icon('users') ?></div><div class="stat-body"><div class="stat-label">المشتركون</div><div class="stat-value"><?= num($s('subscribers')) ?></div><div class="stat-meta"><?= num($s('sessions')) ?> متصل الآن</div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('banknote') ?></div><div class="stat-body"><div class="stat-label">مبيعات الشهر</div><div class="stat-value"><?= e(number_format((float) $s('sales'), 0)) ?></div></div></div>
</div>

<div class="grid grid-main-side">
  <div class="stack">
    <div class="panel">
      <div class="panel-head"><h3><?= icon('key-round') ?>الاشتراك</h3></div>
      <div class="panel-body">
        <div class="sub-actions">
          <button type="button" class="btn btn-primary" data-modal-open="m-activate"><?= icon('circle-check') ?>تفعيل / تجديد</button>
          <button type="button" class="btn" data-modal-open="m-extend"><?= icon('calendar-plus') ?>تمديد التجربة</button>
          <?php if ($t['status'] !== 'suspended'): ?><button type="button" class="btn" data-modal-open="m-suspend"><?= icon('ban') ?>إيقاف</button><?php endif; ?>
          <?php if ($t['status'] !== 'cancelled'): ?>
          <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/status')) ?>" data-confirm="إلغاء اشتراك «<?= e($t['name']) ?>»؟" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost text-danger"><?= icon('circle-x') ?>إلغاء</button>
          </form>
          <?php endif; ?>
        </div>
        <?php if (($inv = (int) ($_GET['invoice'] ?? 0)) > 0): ?>
        <div class="announce-bar is-success mt-3"><?= icon('receipt-text') ?><span>صدرت فاتورة الدفعة.</span><a class="btn btn-sm" href="<?= e(url('/admin/payments/' . $inv . '/invoice')) ?>" data-no-loading>فتح الفاتورة</a></div>
        <?php endif; ?>
        <dl class="kv mt-3">
          <dt>الباقة</dt><dd><?php $pl = App\Core\Platform::plan(isset($t['plan_id']) ? (int) $t['plan_id'] : null); ?><?= $pl ? e($pl['name']) : '—' ?>
            <?php if ($phase === 'grace'): ?> <span class="badge badge-warning">فترة سماح</span><?php elseif ($phase === 'readonly'): ?> <span class="badge badge-danger">قراءة فقط</span><?php endif; ?></dd>
          <dt>الفترة التجريبية حتى</dt><dd><?= e(fmt_dt($t['trial_ends_at'])) ?></dd>
          <dt>مفعّل حتى</dt><dd><?= $t['status'] === 'active' ? ($t['paid_until'] ? e(fmt_dt($t['paid_until'])) : 'دون تاريخ انتهاء') : '—' ?></dd>
          <dt>تاريخ التفعيل</dt><dd><?= e(fmt_dt($t['activated_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('users') ?>مستخدمو النظام</h3><span class="badge"><?= num(count($employees)) ?></span></div>
      <?php if (!$employees): ?><div class="empty"><?= icon('users') ?><div>لا يوجد مستخدمون.</div></div><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>المستخدم</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
          <tr>
            <td><div class="cell-title"><?= e($e['name']) ?></div><div class="cell-sub mono"><?= e($e['username']) ?></div></td>
            <td class="text-sm"><?= $e['is_owner'] ? 'المالك' : e(Permissions::PRESETS[$e['role_preset'] ?? '']['label'] ?? 'موظف') ?></td>
            <td><?= $e['status'] === 'active' ? '<span class="badge badge-success">فعّال</span>' : '<span class="badge badge-outline">موقوف</span>' ?><?php if ((int) ($e['totp_enabled'] ?? 0) === 1): ?> <span class="badge badge-primary" title="التحقق بخطوتين مفعّل"><?= icon('shield-check') ?>خطوتان</span><?php endif; ?></td>
            <td class="nowrap text-sm"><?= $e['last_login_at'] ? e(time_ago($e['last_login_at'])) : '—' ?></td>
            <td class="nowrap text-end">
              <?php if ($usable && $e['status'] === 'active'): ?>
              <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/impersonate')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= (int) $e['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm" title="الدخول باسمه"><?= icon('log-in') ?></button></form>
              <?php endif; ?>
              <button type="button" class="btn btn-ghost btn-icon btn-sm" title="كلمة مرور جديدة" data-modal-open="m-password" data-emp="<?= (int) $e['id'] ?>" data-name="<?= e($e['name']) ?>"><?= icon('key-round') ?></button>
              <?php if ((int) ($e['totp_enabled'] ?? 0) === 1): ?>
              <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/twofactor')) ?>" style="display:inline" data-confirm="إلغاء التحقق بخطوتين للمستخدم <?= e($e['name']) ?>؟"><?= csrf_field() ?><input type="hidden" name="employee_id" value="<?= (int) $e['id'] ?>"><button class="btn btn-ghost btn-icon btn-sm" title="إلغاء التحقق بخطوتين"><?= icon('shield-ban') ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><?= icon('clipboard-list') ?>آخر العمليات داخل النظام</h3></div>
      <?php if (!$audit): ?><div class="empty"><?= icon('clipboard-list') ?><div>لا توجد عمليات.</div></div><?php else: ?>
      <div class="table-wrap" style="max-height:420px"><table class="table table-compact">
        <thead><tr><th>التاريخ</th><th>العملية</th><th>الوصف</th><th>المستخدم</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
          <tr><td class="nowrap text-sm"><?= e(fmt_dt($a['created_at'])) ?></td><td class="nowrap text-sm"><?= e(Audit::label($a['action'])) ?></td><td class="text-sm"><?= e($a['description']) ?></td><td class="text-sm"><?= e($a['employee_name'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'])) ?>" class="panel">
      <?= csrf_field() ?>
      <div class="panel-head"><h3><?= icon('info') ?>البيانات</h3></div>
      <div class="panel-body">
        <div class="form-grid">
          <div class="field col-12"><label>اسم الشبكة</label><input class="input" name="name" value="<?= e(old('name', $t['name'])) ?>" required maxlength="100"></div>
          <div class="field col-12"><label for="t-slug">اسم الحساب في الرابط</label>
            <div class="input-group slug-group" dir="ltr"><span class="input-addon"><?= e(preg_replace('#^https?://#', '', App\Core\Platform::rootUrl())) ?>/</span><input class="input" id="t-slug" name="slug" value="<?= e(old('slug', $t['slug'])) ?>" required maxlength="40" pattern="[a-z0-9\-]{3,40}" autocapitalize="off" autocomplete="off" spellcheck="false"></div>
            <?= error_html('slug') ?>
            <?php if (!empty($aliases)): ?>
            <div class="alias-list"><span class="text-muted text-sm">أسماء سابقة ما زالت روابطها تعمل:</span>
              <?php foreach ($aliases as $a): ?><span class="alias-chip"><bdi dir="ltr">/<?= e($a) ?></bdi><button type="submit" form="alias-del" name="slug" value="<?= e($a) ?>" title="حذف الاسم السابق" aria-label="حذف الاسم السابق <?= e($a) ?>"><?= icon('x', 'icon-sm') ?></button></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="field col-12"><label>المالك</label><input class="input" name="owner_name" value="<?= e(old('owner_name', $t['owner_name'])) ?>" required maxlength="120"></div>
          <div class="field col-6"><label>الهاتف</label><input class="input input-ltr" name="phone" value="<?= e(old('phone', $t['phone'] ?? '')) ?>" maxlength="30"></div>
          <div class="field col-6"><label>البريد</label><input class="input input-ltr" name="email" value="<?= e(old('email', $t['email'] ?? '')) ?>" maxlength="120"></div>
          <div class="field col-12"><label for="t-panel">الرابط العام للنظام</label><input class="input input-ltr" id="t-panel" name="panel_url" value="<?= e(old('panel_url', $panelUrl ?? '')) ?>" maxlength="255" placeholder="<?= e($panelDefault ?? '') ?>"><?= error_html('panel_url') ?></div>
          <div class="field col-6"><label>حد أجهزة MikroTik</label><input class="input" name="max_routers" value="<?= e(old('max_routers', $t['max_routers'] ?? '')) ?>" inputmode="numeric" placeholder="بلا حد"><?= error_html('max_routers') ?></div>
          <div class="field col-6"><label>حد المشتركين</label><input class="input" name="max_subscribers" value="<?= e(old('max_subscribers', $t['max_subscribers'] ?? '')) ?>" inputmode="numeric" placeholder="بلا حد"><?= error_html('max_subscribers') ?></div>
          <div class="field col-6"><label>حد الموظفين</label><input class="input" name="max_employees" value="<?= e(old('max_employees', $t['max_employees'] ?? '')) ?>" inputmode="numeric" placeholder="بلا حد"><?= error_html('max_employees') ?></div>
          <div class="field col-6"><label>الباقة</label><select class="input" name="plan_id"><option value="">بدون باقة</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected((int) ($t['plan_id'] ?? 0), (int) $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="form-section-title"><?= icon('sparkles') ?>الذكاء الاصطناعي</div>
          <div class="field col-6"><label class="switch"><input type="checkbox" name="ai_enabled" value="1"<?= checked((int) ($t['ai_enabled'] ?? 0)) ?>><span class="track"></span><span>مفعّل لهذا الحساب</span></label></div>
          <div class="field col-6"><label>الحد الشهري للرسائل</label><input class="input" name="ai_limit" value="<?= e(old('ai_limit', $t['ai_limit'] ?? '')) ?>" inputmode="numeric" placeholder="<?= (int) App\Core\Tenant::aiLimit(['ai_limit' => null] + $t) ?>"><?= error_html('ai_limit') ?><div class="text-muted text-sm">المستخدم هذا الشهر: <?= num($aiUsed) ?></div></div>
          <div class="field col-12"><label>ملاحظات</label><textarea class="input" name="notes" rows="3" maxlength="2000"><?= e(old('notes', $t['notes'] ?? '')) ?></textarea></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <?php if ($t['phone']): ?><a class="btn btn-whatsapp" href="https://wa.me/<?= e(preg_replace('/\D/', '', preg_replace('/^0/', '970', $t['phone']))) ?>" target="_blank" rel="noopener"><?= icon('message-circle') ?>واتساب</a><?php endif; ?>
        </div>
        <dl class="kv mt-3">
          <dt>قاعدة البيانات</dt><dd class="mono"><?= e($t['db_name']) ?></dd>
          <dt>حجم البيانات</dt><dd><?= e(fmt_bytes($dbSize)) ?></dd>
          <dt>التسجيل</dt><dd><?= e(fmt_dt($t['created_at'])) ?></dd>
          <dt>آخر نشاط</dt><dd><?= $t['last_seen_at'] ? e(time_ago($t['last_seen_at'])) : '—' ?></dd>
          <dt>آخر مزامنة</dt><dd><?= $t['last_cron_at'] ? e(time_ago($t['last_cron_at'])) : '—' ?></dd>
          <dt>عنوان التسجيل</dt><dd class="mono"><?= e($t['signup_ip'] ?? '—') ?></dd>
        </dl>
      </div>
    </form>
    <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/alias/delete')) ?>" id="alias-del" data-confirm="حذف الاسم السابق؟ ستتوقف الروابط القديمة عليه (صفحات الهوتسبوت المنشورة به مثلاً)." hidden><?= csrf_field() ?></form>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('network') ?>شبكات المالك</h3><button type="button" class="btn btn-sm" data-modal-open="m-network"><?= icon('plus') ?>شبكة إضافية</button></div>
      <ul class="list">
        <?php foreach ($siblings as $sb): [$sbl, $sbc] = AdminController::STATUS[$sb['status']]; ?>
          <li class="list-item"><span class="list-icon"><?= icon('building') ?></span>
            <div class="list-item-main"><a class="list-item-title" href="<?= e(url('/admin/tenants/' . $sb['id'])) ?>"><?= e($sb['name']) ?></a><div class="list-item-sub mono">/<?= e($sb['slug']) ?></div></div>
            <div class="list-item-end"><span class="badge <?= $sbc ?>"><?= $sbl ?></span></div></li>
        <?php endforeach; ?>
        <?php if (!$siblings): ?><li class="list-item text-muted">شبكة واحدة.</li><?php endif; ?>
      </ul>
      <div class="panel-body">
        <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/group')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <div class="input-group"><input class="input input-ltr" name="slug" placeholder="اسم حساب قائم" maxlength="40" autocapitalize="off" spellcheck="false" aria-label="اسم حساب قائم"><button class="btn"><?= icon('link') ?>ربط</button></div>
          <?= error_html('link_slug') ?>
        </form>
        <?php if (!empty($t['group_id'])): ?>
        <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/group')) ?>" data-confirm="فصل هذا الحساب عن شبكات المالك؟" class="mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="unlink"><button class="btn btn-ghost btn-sm text-danger"><?= icon('link-2-off') ?>فصل عن المجموعة</button></form>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('banknote') ?>المدفوعات</h3><span class="badge"><?= e($amount(array_sum(array_column($payments, 'amount')))) ?></span></div>
      <ul class="list">
        <?php foreach ($payments as $p): ?>
          <li class="list-item">
            <span class="list-icon is-success"><?= icon('banknote') ?></span>
            <div class="list-item-main"><div class="list-item-title"><?= e($amount($p['amount'])) ?><?= $p['months'] ? ' <span class="badge">' . num((int) $p['months']) . ' شهر</span>' : '' ?></div><div class="list-item-sub"><?= e(fmt_dt($p['created_at'])) ?><?= $p['method'] ? ' · ' . e($p['method']) : '' ?><?= $p['note'] ? ' · ' . e($p['note']) : '' ?></div></div>
            <div class="list-item-end"><a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/admin/payments/' . $p['id'] . '/invoice')) ?>" title="الفاتورة" data-no-loading><?= icon('receipt-text') ?></a><form method="post" action="<?= e(url('/admin/payments/' . $p['id'] . '/delete')) ?>" data-confirm="حذف هذه الدفعة من السجل؟"><?= csrf_field() ?><input type="hidden" name="back" value="tenant"><button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button></form></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$payments): ?><li class="list-item text-muted">لا توجد مدفوعات.</li><?php endif; ?>
      </ul>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><?= icon('activity') ?>سجل الحساب</h3></div>
      <ul class="list">
        <?php foreach ($activity as $a): ?>
          <li class="list-item"><div class="list-item-main"><div class="list-item-title text-sm"><?= e($a['description']) ?></div><div class="list-item-sub"><?= e($a['actor_name'] ?? '') ?></div></div><div class="list-item-end text-sm"><?= e(time_ago($a['created_at'])) ?></div></li>
        <?php endforeach; ?>
        <?php if (!$activity): ?><li class="list-item text-muted">لا يوجد.</li><?php endif; ?>
      </ul>
    </div>
    <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/delete')) ?>" class="panel danger-zone" data-confirm="حذف الحساب «<?= e($t['name']) ?>» وقاعدة بياناته نهائياً؟">
      <?= csrf_field() ?>
      <div class="panel-head"><h3 class="text-danger"><?= icon('trash-2') ?>حذف نهائي</h3></div>
      <div class="panel-body stack" style="gap:10px">
        <input class="input input-ltr" name="confirm" placeholder="<?= e($t['slug']) ?>" autocomplete="off">
        <?= error_html('confirm') ?>
        <button class="btn btn-danger btn-block"><?= icon('trash-2') ?>حذف الحساب وقاعدة بياناته</button>
      </div>
    </form>
  </div>
</div>

<dialog class="modal modal-sm" id="m-activate">
  <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/status')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="activate">
    <div class="modal-head"><h3><?= icon('circle-check') ?>تفعيل الحساب</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <?php if ($plans): ?>
      <div class="field"><label>الباقة</label>
        <select class="input" name="plan_id" data-activate-plan><option value="" data-price="" data-months="">بدون باقة</option>
          <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" data-price="<?= e((string) (float) $p['price']) ?>" data-months="<?= (int) $p['months'] ?>"<?= selected((int) ($t['plan_id'] ?? 0), (int) $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field"><label>المدة</label>
        <select class="input" name="months">
          <option value="1">شهر</option><option value="3">3 أشهر</option><option value="6">6 أشهر</option><option value="12" selected>سنة</option><option value="0">دون تاريخ انتهاء / تاريخ محدد</option>
        </select>
      </div>
      <div class="field"><label>حتى تاريخ</label><input class="input" type="date" name="until"></div>
      <div class="field"><label>المبلغ المدفوع</label><div class="input-group"><input class="input" name="amount" inputmode="decimal" placeholder="0"><span class="input-addon"><?= e($cur) ?></span></div></div>
      <div class="field"><label>طريقة الدفع</label><input class="input" name="method" maxlength="40" list="pay-methods"><datalist id="pay-methods"><option value="نقداً"><option value="تحويل بنكي"><option value="محفظة إلكترونية"></datalist></div>
      <div class="field"><label>ملاحظة</label><input class="input" name="note" maxlength="255"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تفعيل</button></div>
  </form>
</dialog>
<dialog class="modal modal-sm" id="m-network">
  <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/network')) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3><?= icon('network') ?>شبكة إضافية للمالك</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body stack" style="gap:12px">
      <div class="field"><label>اسم الشبكة</label><input class="input" name="name" value="<?= e(old('name') !== $t['name'] ? old('name') : '') ?>" required maxlength="100"></div>
      <div class="field"><label>اسم الحساب في الرابط</label><input class="input input-ltr" name="slug" value="<?= e(old('slug')) ?>" required maxlength="40" pattern="[a-z0-9\-]{3,40}" autocapitalize="off" spellcheck="false"><?= error_html('slug') ?></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>إنشاء</button></div>
  </form>
</dialog>
<script>
(function () {
  var sel = document.querySelector('[data-activate-plan]');
  if (!sel) { return; }
  var f = sel.form;
  sel.addEventListener('change', function () {
    var o = sel.options[sel.selectedIndex], m = o.getAttribute('data-months'), pr = o.getAttribute('data-price');
    if (m) {
      var has = Array.prototype.some.call(f.elements.months.options, function (x) { return x.value === m; });
      if (!has) { var n = document.createElement('option'); n.value = m; n.textContent = m + ' شهر'; f.elements.months.appendChild(n); }
      f.elements.months.value = m;
    }
    if (pr !== '' && pr !== null) { f.elements.amount.value = pr; }
  });
})();
</script>
<dialog class="modal modal-sm" id="m-extend">
  <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/status')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="extend">
    <div class="modal-head"><h3><?= icon('calendar-plus') ?>تمديد الفترة التجريبية</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>عدد الأيام</label><div class="input-group"><input class="input" name="days" value="7" inputmode="numeric"><span class="input-addon">يوم</span></div></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>تمديد</button></div>
  </form>
</dialog>
<dialog class="modal modal-sm" id="m-suspend">
  <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/status')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="suspend">
    <div class="modal-head"><h3><?= icon('ban') ?>إيقاف الحساب</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label>السبب</label><input class="input" name="reason" maxlength="255"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-danger"><?= icon('ban') ?>إيقاف</button></div>
  </form>
</dialog>
<dialog class="modal modal-sm" id="m-password">
  <form method="post" action="<?= e(url('/admin/tenants/' . $t['id'] . '/password')) ?>">
    <?= csrf_field() ?><input type="hidden" name="employee_id" value="">
    <div class="modal-head"><h3><?= icon('key-round') ?>كلمة مرور جديدة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
    <div class="modal-body"><div class="field"><label data-emp-name></label><input class="input input-ltr" name="password" minlength="8" required autocomplete="new-password"></div></div>
    <div class="modal-foot"><button type="button" class="btn" data-modal-close>إلغاء</button><button class="btn btn-primary"><?= icon('check') ?>حفظ</button></div>
  </form>
</dialog>
<script>
document.getElementById('m-password').addEventListener('modal:open', function (e) {
  var t = e.detail.trigger;
  this.querySelector('[name=employee_id]').value = t.getAttribute('data-emp');
  this.querySelector('[data-emp-name]').textContent = t.getAttribute('data-name');
});
</script>
