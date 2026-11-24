<?php
/** @var App\Core\View $v */
/** @var App\Core\Request $request */
use App\Core\Auth;

$isEdit = $sub !== null;
$v->title = $isEdit ? 'تعديل المشترك' : 'مشترك جديد';
$v->breadcrumbs = $isEdit
    ? [['المشتركون', '/subscribers'], [$sub['full_name'], '/subscribers/' . $sub['id']], ['تعديل', null]]
    : [['المشتركون', '/subscribers'], ['مشترك جديد', null]];
$val = static fn (string $k, $d = '') => old($k, $sub[$k] ?? $d);
$planId = (int) $val('plan_id', $request->int('plan') ?: ($plans[0]['id'] ?? 0));
$routerId = (int) ($isEdit ? $val('router_id') : $val('router_id', count($routers) === 1 ? array_key_first($routers) : 0));
$showPass = !$isEdit || can('subscribers.passwords');
?>
<?php if (!$plans): ?>
  <div class="panel"><div class="empty"><?= icon('package') ?><div class="empty-title">أضف باقة اشتراك أولاً</div>
    <?php if (can('plans.manage')): ?><a class="btn btn-primary" href="<?= e(url('/plans/create')) ?>"><?= icon('plus') ?>باقة اشتراك جديدة</a><?php endif; ?></div></div>
<?php else: ?>
<form method="post" action="<?= e(url($isEdit ? '/subscribers/' . $sub['id'] : '/subscribers')) ?>">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('user') ?>البيانات الشخصية</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="full_name">الاسم الكامل <span class="req">*</span></label>
              <input class="input" id="full_name" name="full_name" value="<?= e($val('full_name')) ?>" required>
              <?= error_html('full_name') ?>
            </div>
            <div class="field col-3">
              <label for="phone">الهاتف</label>
              <input class="input input-ltr" id="phone" name="phone" value="<?= e($val('phone')) ?>" inputmode="tel">
              <?= error_html('phone') ?>
            </div>
            <div class="field col-3">
              <label for="phone2">هاتف إضافي</label>
              <input class="input input-ltr" id="phone2" name="phone2" value="<?= e($val('phone2')) ?>" inputmode="tel">
            </div>
            <div class="field col-4">
              <label for="national_id">رقم الهوية</label>
              <input class="input input-ltr" id="national_id" name="national_id" value="<?= e($val('national_id')) ?>" inputmode="numeric">
            </div>
            <div class="field col-4">
              <label for="area_id">المنطقة</label>
              <select class="input" id="area_id" name="area_id">
                <option value="">—</option>
                <?php foreach ($areas as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) $val('area_id'), (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field col-4">
              <label for="address">العنوان</label>
              <input class="input" id="address" name="address" value="<?= e($val('address')) ?>">
            </div>
            <?= $v->partial('location', ['sites' => $sites, 'siteId' => $val('site_id'), 'lat' => $val('lat'), 'lng' => $val('lng'), 'withSite' => true]) ?>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2><?= icon('key-round') ?>الاشتراك والاتصال</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-6">
              <label for="plan_id">الباقة <span class="req">*</span></label>
              <select class="input" id="plan_id" name="plan_id" required>
                <?php foreach ($plans as $p): ?>
                  <option value="<?= (int) $p['id'] ?>" data-price="<?= e((string) (float) $p['price']) ?>"<?= selected((string) $planId, (string) $p['id']) ?>><?= e($p['name']) ?> · <?= $p['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت' ?> · <?= e(money($p['price'])) ?> / <?= e(num($p['duration_days'])) ?> يوم</option>
                <?php endforeach; ?>
              </select>
              <?= error_html('plan_id') ?>
            </div>
            <div class="field col-6">
              <label for="router_id">جهاز MikroTik<?= Auth::routerIds() !== null ? ' <span class="req">*</span>' : '' ?></label>
              <select class="input" id="router_id" name="router_id">
                <?php if (Auth::routerIds() === null): ?><option value="">—</option><?php endif; ?>
                <?php foreach ($routers as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected((string) $routerId, (string) $id) ?>><?= e($name) ?></option><?php endforeach; ?>
              </select>
              <?= error_html('router_id') ?>
            </div>
            <div class="field col-6">
              <label for="username">اسم المستخدم</label>
              <input class="input input-ltr" id="username" name="username" value="<?= e($val('username')) ?>" autocomplete="off" placeholder="<?= $isEdit ? '' : 'تلقائي' ?>"<?= $isEdit ? ' required' : '' ?>>
              <?= error_html('username') ?>
            </div>
            <div class="field col-6">
              <label for="password">كلمة المرور</label>
              <?php if ($showPass): ?>
                <input class="input input-ltr" id="password" name="password" value="<?= e($isEdit ? $val('password') : old('password')) ?>" autocomplete="off" placeholder="<?= $isEdit ? '' : 'تلقائية' ?>">
              <?php else: ?>
                <input class="input input-ltr" id="password" name="password" value="" autocomplete="new-password" placeholder="••••••••">
              <?php endif; ?>
              <?= error_html('password') ?>
            </div>
            <div class="field col-4">
              <label for="static_ip">عنوان IP ثابت</label>
              <input class="input input-ltr" id="static_ip" name="static_ip" value="<?= e($val('static_ip')) ?>" placeholder="10.20.0.15">
              <?= error_html('static_ip') ?>
            </div>
            <div class="field col-4" style="align-self:end">
              <label class="switch"><input type="checkbox" name="lock_mac" value="1"<?= checked((int) $val('lock_mac', 0) === 1) ?>><span class="track"></span><span>ربط بجهاز واحد</span></label>
            </div>
            <div class="field col-4" data-show-when="lock_mac=1">
              <label for="bound_mac">عنوان MAC</label>
              <input class="input input-ltr" id="bound_mac" name="bound_mac" value="<?= e($val('bound_mac')) ?>" placeholder="أول جهاز يتصل">
              <?= error_html('bound_mac') ?>
            </div>
            <div class="field col-12">
              <label for="notes">ملاحظات</label>
              <input class="input" id="notes" name="notes" value="<?= e($val('notes')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <?php if (!$isEdit && can('subscribers.renew')): ?>
      <div class="panel">
        <div class="panel-head"><h3><?= icon('calendar-clock') ?>التفعيل</h3></div>
        <div class="panel-body stack" style="gap:12px">
          <label class="switch"><input type="checkbox" name="activate" value="1"<?= checked((string) old('activate', '1') === '1') ?>><span class="track"></span><span>تفعيل الاشتراك الآن</span></label>
          <div class="stack" style="gap:12px" data-show-when="activate=1">
            <div class="field">
              <label for="periods">عدد الفترات</label>
              <input class="input" id="periods" name="periods" value="<?= e(old('periods', '1')) ?>" inputmode="numeric">
            </div>
            <div class="field">
              <label for="amount">المبلغ المستحق</label>
              <input class="input" id="amount" name="amount" value="<?= e(old('amount')) ?>" inputmode="decimal" placeholder="سعر الباقة">
            </div>
            <div class="field">
              <label for="paid">المدفوع الآن</label>
              <input class="input" id="paid" name="paid" value="<?= e(old('paid', '0')) ?>" inputmode="decimal">
            </div>
            <div class="field">
              <label for="method">طريقة الدفع</label>
              <select class="input" id="method" name="method">
                <?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"<?= selected(old('method', 'cash'), $k) ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="panel"><div class="panel-body flex gap-2">
        <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
        <a class="btn" href="<?= e(url($isEdit ? '/subscribers/' . $sub['id'] : '/subscribers')) ?>">إلغاء</a>
      </div></div>
    </div>
  </div>
</form>
<?php endif; ?>
