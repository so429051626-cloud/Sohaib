<?php
/** @var App\Core\View $v */
use App\Core\Settings;
use App\Services\CardGenerator;
use App\Services\ShortCodes;
use App\Services\Units;

$v->title = 'توليد كروت';
$v->breadcrumbs = [['الكروت', '/cards'], ['توليد كروت', null]];
$defLen = old('username_length', Settings::get('cards.username_length', '8'));
$defCharset = old('charset', Settings::get('cards.charset', 'digits'));
$defMode = old('password_mode', Settings::get('cards.password_mode', 'same'));
$defPassLen = old('password_length', Settings::get('cards.password_length', '4'));
?>
<?php if (!$categories): ?>
  <div class="panel"><div class="empty"><?= icon('layers') ?><div class="empty-title">أضف باقة أولاً</div>
    <?php if (can('categories.manage')): ?><a class="btn btn-primary" href="<?= e(url('/categories/create')) ?>"><?= icon('plus') ?>باقة جديدة</a><?php endif; ?></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/cards/generate')) ?>" id="gen-form">
  <?= csrf_field() ?>
  <div class="grid grid-main-side">
    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h2><?= icon('layers') ?>الباقة والعدد</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-12">
              <label>الباقة <span class="req">*</span></label>
              <div class="pkg-grid">
                <?php foreach ($categories as $i => $c):
                    $isSel = (int) old('category_id', $selected ?: $categories[0]['id']) === (int) $c['id']; ?>
                  <label class="pkg">
                    <input type="radio" name="category_id" value="<?= (int) $c['id'] ?>"<?= checked($isSel) ?>>
                    <span class="pkg-card">
                      <span class="pkg-head"><span class="swatch" style="background:<?= e($c['color']) ?>"></span><strong class="pkg-name"><?= e($c['name']) ?></strong><span class="pkg-price"><?= e(money($c['price'])) ?></span></span>
                      <span class="pkg-meta">
                        <span><?= e(Units::describeSeconds($c['time_limit'] !== null ? (int) $c['time_limit'] : null)) ?></span>
                        <span><?= e(Units::describeBytes($c['data_limit'] !== null ? (int) $c['data_limit'] : null)) ?></span>
                        <span dir="ltr"><?= e(Units::describeRate($c)) ?></span>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <?= error_html('category_id') ?>
            </div>
            <div class="field col-4">
              <label for="quantity">عدد الكروت <span class="req">*</span></label>
              <input class="input" id="quantity" name="quantity" value="<?= e(old('quantity', '100')) ?>" inputmode="numeric" required>
              <?= error_html('quantity') ?>
            </div>
            <div class="field col-8">
              <label for="batch_name">اسم الدفعة</label>
              <input class="input" id="batch_name" name="batch_name" value="<?= e(old('batch_name')) ?>" placeholder="يُنشأ تلقائياً عند تركه فارغاً">
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2><?= icon('router') ?>أجهزة MikroTik</h2></div>
        <div class="panel-body">
          <?php $rMode = old('router_mode', 'all'); $rSel = array_map('intval', (array) old('routers', [])); ?>
          <div class="segmented is-block">
            <label><input type="radio" name="router_mode" value="all"<?= checked($rMode !== 'selected') ?>><span>كل الأجهزة</span></label>
            <label><input type="radio" name="router_mode" value="selected"<?= checked($rMode === 'selected') ?><?= $routers ? '' : ' disabled' ?>><span>أجهزة محددة فقط</span></label>
          </div>
          <p class="hint mt-2" data-show-when="router_mode=all">تعمل الكروت على كل أجهزة MikroTik المسموحة للباقة، وتُجهَّز على الجهاز تلقائياً عند أول دخول.</p>
          <?php if (!$routers): ?>
            <p class="hint mt-2">لا توجد أجهزة MikroTik مفعّلة بعد.<?php if (can('routers.manage')): ?> <a href="<?= e(url('/routers/create')) ?>">إضافة جهاز</a><?php endif; ?></p>
          <?php else: ?>
          <div class="gen-routers mt-3" data-show-when="router_mode=selected" data-cat-routers='<?= e(json_encode($catRouters, JSON_FORCE_OBJECT)) ?>'>
            <div class="gen-routers-tools">
              <span class="text-sm text-muted" data-routers-count>لم يُختر جهاز</span>
              <button type="button" class="btn btn-ghost btn-xs" data-routers-all><?= icon('list-checks') ?>تحديد الكل</button>
            </div>
            <div class="router-pick">
              <?php foreach ($routers as $r): $on = $r['status'] === 'online'; ?>
              <label class="router-opt" data-router-opt="<?= (int) $r['id'] ?>">
                <input type="checkbox" name="routers[]" value="<?= (int) $r['id'] ?>"<?= checked(in_array((int) $r['id'], $rSel, true)) ?>>
                <span class="router-opt-card">
                  <span class="router-opt-icon <?= $on ? 'is-online' : ($r['status'] === 'offline' ? 'is-offline' : '') ?>"><?= icon('router') ?></span>
                  <span class="router-opt-text"><b><?= e($r['name']) ?></b><small><span class="router-dot <?= $on ? 'is-online' : ($r['status'] === 'offline' ? 'is-offline' : '') ?>"></span><?= $on ? 'متصل' : ($r['status'] === 'offline' ? 'غير متصل' : 'لم يُفحص') ?> · <bdi dir="ltr"><?= e($r['host']) ?></bdi></small></span>
                  <span class="router-opt-check"><?= icon('check') ?></span>
                </span>
              </label>
              <?php endforeach; ?>
            </div>
            <p class="hint" data-routers-limited hidden><?= icon('info', 'icon-sm') ?> هذه الباقة محددة بأجهزة معيّنة، فتظهر الأجهزة المسموحة لها فقط.</p>
            <?= error_html('routers') ?>
            <div class="notice mt-2" style="margin-bottom:0"><?= icon('shield-check') ?><div class="text-sm">الكروت تعمل على الأجهزة المختارة فقط، ويُرفض الدخول بها من أي جهاز آخر.</div></div>
            <label class="switch mt-3"><input type="checkbox" name="push_now" value="1"<?= checked(old('push_now', '1') === '1') ?>><span class="track"></span><span>رفع الكروت إلى الأجهزة المختارة الآن بعد التوليد</span></label>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2><?= icon('hash') ?>صيغة الأرقام</h2></div>
        <div class="panel-body">
          <div class="form-grid">
            <div class="field col-4">
              <label for="username_length">طول رقم الكرت</label>
              <input class="input" id="username_length" name="username_length" value="<?= e($defLen) ?>" inputmode="numeric">
              <?= error_html('username_length') ?>
            </div>
            <div class="field col-4">
              <label for="charset">نوع الرموز</label>
              <select class="input" id="charset" name="charset">
                <?php foreach (CardGenerator::CHARSETS as $k => $c): ?><option value="<?= e($k) ?>"<?= selected($defCharset, $k) ?>><?= e($c['label']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field col-4">
              <label for="prefix">بادئة ثابتة</label>
              <input class="input input-ltr" id="prefix" name="prefix" value="<?= e(old('prefix')) ?>" maxlength="10" placeholder="اختياري">
              <?= error_html('prefix') ?>
            </div>
            <div class="field col-6">
              <label>كلمة المرور</label>
              <div class="segmented">
                <label><input type="radio" name="password_mode" value="same"<?= checked($defMode === 'same') ?>><span>نفس رقم الكرت</span></label>
                <label><input type="radio" name="password_mode" value="separate"<?= checked($defMode === 'separate') ?>><span>كلمة مرور مستقلة</span></label>
              </div>
            </div>
            <div class="field col-6" data-show-when="password_mode=separate">
              <label for="password_length">طول كلمة المرور (أرقام)</label>
              <input class="input" id="password_length" name="password_length" value="<?= e($defPassLen) ?>" inputmode="numeric">
            </div>

            <div class="form-section-title">الرقم المختصر</div>
            <div class="field col-12">
              <label class="switch"><input type="checkbox" name="with_short" value="1"<?= checked(old('with_short')) ?>><span class="track"></span><span>إنشاء رقم مختصر لكل كرت</span></label>
            </div>
            <div class="field col-4" data-show-when="with_short=1">
              <label for="short_length">طول اسم المستخدم المختصر</label>
              <input class="input" id="short_length" name="short_length" value="<?= e(old('short_length', '5')) ?>" inputmode="numeric">
              <?= error_html('short_length') ?>
            </div>
            <div class="field col-4" data-show-when="with_short=1">
              <label for="short_password_length">طول كلمة المرور المختصرة</label>
              <input class="input" id="short_password_length" name="short_password_length" value="<?= e(old('short_password_length', '0')) ?>" inputmode="numeric">
              <span class="hint">0 = بدون كلمة مرور</span>
              <?= error_html('short_password_length') ?>
            </div>
            <div class="field col-4" data-show-when="with_short=1">
              <label for="short_mode">طريقة التوليد</label>
              <select class="input" id="short_mode" name="short_mode">
                <option value="random">عشوائي</option>
                <option value="sequence">تسلسلي</option>
              </select>
            </div>
            <div class="field col-12">
              <label for="notes">ملاحظات</label>
              <input class="input" id="notes" name="notes" value="<?= e(old('notes')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="panel-head"><h3><?= icon('eye') ?>معاينة</h3></div>
        <div class="panel-body">
          <div class="text-sm text-muted mb-1">مثال لرقم كرت:</div>
          <div class="mono" id="sample" style="font-size:22px;font-weight:600;letter-spacing:.06em">—</div>
          <div class="text-sm text-muted mt-2" id="capacity"></div>
        </div>
      </div>
      <div class="panel"><div class="panel-body">
        <button class="btn btn-primary btn-lg btn-block"><?= icon('wand-sparkles') ?>توليد الكروت</button>
      </div></div>
    </div>
  </div>
</form>
<script>
(function () {
  var sets = <?= json_encode(array_map(static fn ($c) => $c['chars'], CardGenerator::CHARSETS)) ?>;
  var f = document.getElementById('gen-form');
  function update() {
    var len = Math.max(1, Math.min(32, parseInt(f.username_length.value, 10) || 8));
    var chars = sets[f.charset.value] || '0123456789';
    var s = f.prefix.value;
    for (var i = 0; i < len; i++) { var c = chars.charAt(Math.floor(Math.random() * chars.length)); if (i === 0 && f.charset.value === 'digits' && c === '0') { c = '7'; } s += c; }
    document.getElementById('sample').textContent = s;
    var space = f.charset.value === 'digits' ? 9 * Math.pow(10, len - 1) : Math.pow(chars.length, len);
    var qty = parseInt(f.quantity.value, 10) || 0;
    document.getElementById('capacity').textContent = 'الأرقام الممكنة: ' + (space > 1e12 ? 'أكثر من تريليون' : Math.round(space).toLocaleString('en-US')) + (space < qty * 3 ? ' — غير كافية لهذا العدد' : '');
  }
  f.addEventListener('input', update);
  f.addEventListener('change', update);
  update();

  // أجهزة MikroTik: إظهار المسموحة للباقة المختارة فقط، وتحديد الكل، وعدد المختار
  var box = f.querySelector('[data-cat-routers]');
  if (box) {
    var map = {};
    try { map = JSON.parse(box.getAttribute('data-cat-routers') || '{}'); } catch (e) { map = {}; }
    var opts = Array.prototype.slice.call(box.querySelectorAll('[data-router-opt]'));
    var countEl = box.querySelector('[data-routers-count]');
    var limitedEl = box.querySelector('[data-routers-limited]');
    var syncRouters = function () {
      var catEl = f.querySelector('[name="category_id"]:checked');
      var allowed = catEl && map[catEl.value] ? map[catEl.value].map(String) : null;
      var n = 0;
      opts.forEach(function (o) {
        var ok = !allowed || allowed.indexOf(o.getAttribute('data-router-opt')) !== -1;
        var cb = o.querySelector('input');
        o.hidden = !ok;
        cb.disabled = !ok;
        if (!ok) { cb.checked = false; }
        if (cb.checked) { n++; }
      });
      if (limitedEl) { limitedEl.hidden = !allowed; }
      if (countEl) { countEl.textContent = n ? 'المختار: ' + n + (n === 1 ? ' جهاز' : ' أجهزة') : 'لم يُختر جهاز'; }
    };
    f.addEventListener('change', syncRouters);
    box.querySelector('[data-routers-all]').addEventListener('click', function () {
      var vis = opts.filter(function (o) { return !o.hidden; });
      var all = vis.every(function (o) { return o.querySelector('input').checked; });
      vis.forEach(function (o) { o.querySelector('input').checked = !all; });
      syncRouters();
    });
    syncRouters();
  }
})();
</script>
<?php endif; ?>
