<?php
/**
 * @var App\Core\View $v @var string $state @var string $provider @var bool $hasKey @var string $model @var bool $enabled @var string $savedAt @var string $month
 * @var bool $hasGemini @var string $geminiSavedAt @var string $geminiModel
 * @var array $usage @var array $monthly @var array $byFeature @var array $tenants @var array $used
 */
use App\Core\Platform;
use App\Services\Ai;

$v->title = 'الذكاء الاصطناعي';
$provider ??= Ai::provider();
$hasGemini ??= Ai::hasKey('gemini');
$geminiSavedAt ??= '';
$geminiModel ??= '';
$v->script('vendor/chart.umd.min.js');
$usd = static fn ($x) => ltr('$' . number_format((float) $x, (float) $x < 1 ? 4 : 2));
$features = ['test' => 'اختبار الاتصال', 'admin_chat' => 'مساعد المسؤول', 'weekly' => 'التقرير الأسبوعي', 'ticket_reply' => 'اقتراح رد تذكرة', 'chat' => 'المساعد الذكي', 'router_check' => 'فحص جهاز MikroTik', 'digest' => 'الملخص اليومي', 'import' => 'الاستيراد الذكي', 'bot' => 'بوت خدمة الزبائن'];
$totalCost = array_sum(array_map(static fn ($u) => (float) $u['cost'], $usage));
$totalMsgs = array_sum(array_map(static fn ($u) => (int) $u['messages'], $usage));
$badge = ['ok' => 'badge-success', 'off' => 'badge-danger', 'no_key' => 'badge-warning'][$state] ?? 'badge-outline';
?>
<div class="stats">
  <div class="stat"><div class="stat-icon <?= $state === 'ok' ? 'is-success' : 'is-warning' ?>"><?= icon('sparkles') ?></div><div class="stat-body"><div class="stat-label">الحالة</div><div class="stat-value stat-value-sm"><span class="badge <?= $badge ?>"><?= e(Ai::stateLabel($state)) ?></span></div></div></div>
  <div class="stat"><div class="stat-icon"><?= icon('message-circle') ?></div><div class="stat-body"><div class="stat-label">رسائل <?= e($month) ?></div><div class="stat-value"><?= num($totalMsgs) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('coins') ?></div><div class="stat-body"><div class="stat-label">تكلفة <?= e($month) ?></div><div class="stat-value"><?= $usd($totalCost) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-success"><?= icon('building') ?></div><div class="stat-body"><div class="stat-label">حسابات مفعّل لها</div><div class="stat-value"><?= num(count(array_filter($tenants, static fn ($t) => (int) $t['ai_enabled']))) ?></div></div></div>
</div>

<div class="panel" data-tabs-wrap>
  <div class="tabs" data-tabs data-tabs-hash>
    <a class="tab is-active" href="#settings" data-tab="settings"><?= icon('settings') ?>الإعداد</a>
    <a class="tab" href="#accounts" data-tab="accounts"><?= icon('building') ?>الحسابات</a>
    <a class="tab" href="#usage" data-tab="usage"><?= icon('chart-column') ?>الاستهلاك</a>
  </div>

  <div class="tab-panel is-active" data-tab-panel="settings">
    <div class="panel-body">
      <form method="post" action="<?= e(url('/admin/ai/toggle')) ?>" class="kill-switch<?= $enabled ? ' is-on' : '' ?>"<?= $enabled ? ' data-confirm="إيقاف الذكاء الاصطناعي عن المنصة كلها الآن؟"' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <div class="kill-switch-text"><?= icon($enabled ? 'circle-check' : 'power') ?><div><b><?= $enabled ? 'الذكاء الاصطناعي يعمل' : 'الذكاء الاصطناعي متوقف' ?></b><span><?= e(Ai::PROVIDERS[$provider]) ?></span></div></div>
        <button class="btn <?= $enabled ? 'btn-danger' : 'btn-primary' ?>"><?= icon('power') ?><?= $enabled ? 'إيقاف عام' : 'تشغيل' ?></button>
      </form>

      <form method="post" action="<?= e(url('/admin/ai')) ?>" autocomplete="off" id="ai-form">
        <?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $enabled ? '1' : '0' ?>">
        <div class="form-grid">
          <div class="field col-12">
            <label>المزوّد</label>
            <div class="chip-select">
              <?php foreach (Ai::PROVIDERS as $k => $label): ?>
                <label><input type="radio" name="provider" value="<?= e($k) ?>"<?= checked(old('provider', $provider) === $k) ?>><span><?= e($label) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="field col-6" data-show-when="provider=anthropic">
            <label for="ai-key">مفتاح Anthropic</label>
            <input class="input input-ltr" id="ai-key" type="password" name="api_key" value="" autocomplete="new-password" spellcheck="false" placeholder="<?= $hasKey ? '••••••••••••••••' : 'sk-ant-…' ?>">
            <?= error_html('api_key') ?>
            <div class="field-state"><?php if ($hasKey): ?><span class="badge badge-success"><?= icon('lock', 'icon-sm') ?>محفوظ ومشفّر</span><?php if ($savedAt !== ''): ?><span class="text-muted text-sm"><?= e(fmt_dt($savedAt)) ?></span><?php endif; ?><?php else: ?><span class="badge badge-warning">غير مضبوط</span><?php endif; ?></div>
          </div>
          <div class="field col-6" data-show-when="provider=anthropic">
            <label for="ai-model">النموذج</label>
            <select class="input" id="ai-model" name="model">
              <?php foreach (Ai::MODELS as $id => [$name, $pin, $pout]): ?>
                <option value="<?= e($id) ?>"<?= selected($model, $id) ?>><?= e($name) ?> — $<?= e(rtrim(rtrim(number_format($pin, 2), '0'), '.')) ?> / $<?= e(rtrim(rtrim(number_format($pout, 2), '0'), '.')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($hasKey): ?><div class="field col-12" data-show-when="provider=anthropic"><label class="check"><input type="checkbox" name="remove_key" value="1"><span>حذف المفتاح المحفوظ</span></label></div><?php endif; ?>

          <div class="field col-6" data-show-when="provider=gemini">
            <label for="ai-gkey">مفتاح Gemini</label>
            <input class="input input-ltr" id="ai-gkey" type="password" name="gemini_key" value="" autocomplete="new-password" spellcheck="false" placeholder="<?= $hasGemini ? '••••••••••••••••' : 'AIza…' ?>">
            <?= error_html('gemini_key') ?>
            <div class="field-state"><?php if ($hasGemini): ?><span class="badge badge-success"><?= icon('lock', 'icon-sm') ?>محفوظ ومشفّر</span><?php if ($geminiSavedAt !== ''): ?><span class="text-muted text-sm"><?= e(fmt_dt($geminiSavedAt)) ?></span><?php endif; ?><?php else: ?><span class="badge badge-warning">غير مضبوط</span><?php endif; ?></div>
          </div>
          <div class="field col-6" data-show-when="provider=gemini">
            <label for="ai-gmodel">النموذج</label>
            <div class="field-row">
              <input class="input input-ltr" id="ai-gmodel" name="gemini_model" value="<?= e(old('gemini_model', $geminiModel)) ?>" list="ai-gmodels" spellcheck="false" autocapitalize="off" placeholder="<?= e(Ai::GEMINI_DEFAULT) ?>">
              <button type="button" class="btn" id="ai-gload"><?= icon('refresh-cw') ?>تحميل النماذج</button>
            </div>
            <datalist id="ai-gmodels"></datalist>
            <?= error_html('gemini_model') ?>
          </div>
          <?php if ($hasGemini): ?><div class="field col-12" data-show-when="provider=gemini"><label class="check"><input type="checkbox" name="remove_gemini_key" value="1"><span>حذف المفتاح المحفوظ</span></label></div><?php endif; ?>

          <div class="form-section-title">الحسابات الجديدة</div>
          <div class="field col-6">
            <label class="switch"><input type="checkbox" name="new_enabled" value="1"<?= checked(Platform::setting('ai.new_enabled') === '1') ?>><span class="track"></span><span>تفعيل المساعد الذكي تلقائياً لكل حساب جديد</span></label>
          </div>
          <div class="field col-6">
            <label for="ai-default-limit">الحد الشهري الافتراضي للرسائل</label>
            <div class="input-group"><input class="input input-ltr" id="ai-default-limit" name="default_limit" value="<?= e(old('default_limit', Platform::setting('ai.default_limit'))) ?>" inputmode="numeric"><span class="input-addon">رسالة</span></div>
            <span class="hint">للحساب الذي لا تحدد باقته ولا إعداداته حداً</span>
            <?= error_html('default_limit') ?>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary"><?= icon('save') ?>حفظ</button>
          <button type="button" class="btn" id="ai-test"><?= icon('plug') ?>اختبار الاتصال</button>
          <span id="ai-test-result" class="text-sm"></span>
        </div>
      </form>
    </div>
  </div>

  <div class="tab-panel" data-tab-panel="accounts">
    <form method="post" action="<?= e(url('/admin/ai/tenants')) ?>">
      <?= csrf_field() ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>الحساب</th><th>الباقة</th><th>مفعّل</th><th>الحد الشهري</th><th>المستخدم هذا الشهر</th></tr></thead>
        <tbody>
        <?php foreach ($tenants as $t): $limit = App\Core\Tenant::aiLimit($t); $inherited = App\Core\Tenant::aiLimit(['ai_limit' => null] + $t); $u = (int) ($used[$t['id']] ?? 0); ?>
          <tr>
            <td><a class="cell-title" href="<?= e(url('/admin/tenants/' . $t['id'])) ?>"><?= e($t['name']) ?></a><div class="tenant-link">/<?= e($t['slug']) ?></div></td>
            <td class="text-sm"><?= e($t['plan_name'] ?? '—') ?></td>
            <td><label class="switch"><input type="checkbox" name="enabled[]" value="<?= (int) $t['id'] ?>"<?= checked((int) $t['ai_enabled']) ?>><span class="track"></span></label></td>
            <td><input class="input input-ltr input-sm" name="limit[<?= (int) $t['id'] ?>]" value="<?= $t['ai_limit'] !== null ? (int) $t['ai_limit'] : '' ?>" inputmode="numeric" placeholder="<?= (int) $inherited ?>" style="max-width:120px"></td>
            <td class="nowrap"><?= num($u) ?> / <?= num($limit) ?><?php if ($limit > 0 && $u >= $limit): ?> <span class="badge badge-danger">انتهى الحد</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tenants): ?><tr><td colspan="5"><div class="empty"><?= icon('building') ?><div class="empty-title">لا توجد حسابات فعّالة</div></div></td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php if ($tenants): ?><div class="panel-body"><div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div></div><?php endif; ?>
    </form>
  </div>

  <div class="tab-panel" data-tab-panel="usage">
    <form class="filters" method="get" action="<?= e(url('/admin/ai')) ?>#usage">
      <div class="field"><label for="ai-month">الشهر</label><input class="input" id="ai-month" type="month" name="month" value="<?= e($month) ?>" data-autosubmit></div>
    </form>
    <div class="panel-body"><div class="chart-box"><canvas id="ai-chart"></canvas></div></div>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>الحساب</th><th>الرسائل</th><th>رموز الإدخال</th><th>رموز الإخراج</th><th>التكلفة</th></tr></thead>
      <tbody>
      <?php foreach ($usage as $u): ?>
        <tr>
          <td><?php if ($u['tenant_id'] === null): ?><span class="badge">لوحة المسؤول</span><?php elseif ($u['name'] !== null): ?><a class="cell-title" href="<?= e(url('/admin/tenants/' . $u['tenant_id'])) ?>"><?= e($u['name']) ?></a><?php else: ?><span class="text-muted">حساب محذوف #<?= (int) $u['tenant_id'] ?></span><?php endif; ?></td>
          <td><?= num((int) $u['messages']) ?></td>
          <td><?= num((int) $u['input_tokens']) ?></td>
          <td><?= num((int) $u['output_tokens']) ?></td>
          <td class="nowrap"><strong><?= $usd($u['cost']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$usage): ?><tr><td colspan="5"><div class="empty"><?= icon('sparkles') ?><div class="empty-title">لا يوجد استهلاك في هذا الشهر</div></div></td></tr><?php endif; ?>
      </tbody>
      <?php if ($usage): ?><tfoot><tr><th>الإجمالي</th><th><?= num($totalMsgs) ?></th><th></th><th></th><th><?= $usd($totalCost) ?></th></tr></tfoot><?php endif; ?>
    </table></div>
    <?php if ($byFeature): ?>
    <div class="panel-body"><div class="chips">
      <?php foreach ($byFeature as $f): ?><span class="chip"><?= e($features[$f['feature']] ?? $f['feature']) ?> · <?= num((int) $f['messages']) ?> · <?= $usd($f['cost']) ?></span><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var data = <?= json_encode($monthly) ?>;
  SN.chart(document.getElementById('ai-chart'), {
    type: 'bar', labels: Object.keys(data),
    datasets: [{ label: 'الرسائل', data: Object.values(data).map(function (m) { return m.messages; }) }],
    yFormat: function (v) { return SN.fmtNum(v); }, tooltip: function (v) { return SN.fmtNum(v); }
  });
  var btn = document.getElementById('ai-test'), out = document.getElementById('ai-test-result');
  if (btn) {
    btn.addEventListener('click', function () {
      btn.classList.add('is-loading'); btn.disabled = true; out.textContent = ''; out.className = 'text-sm';
      var f = document.getElementById('ai-form');
      var prov = (f.querySelector('[name=provider]:checked') || {}).value || '';
      var data = { provider: prov, api_key: f.api_key.value.trim(), model: f.model.value, gemini_key: f.gemini_key.value.trim(), gemini_model: f.gemini_model.value.trim() };
      SN.api(SN.base + '/admin/ai/test', { method: 'POST', silent: true, data: data }).then(function (r) {
        out.textContent = r.message + (r.ms ? ' · ' + r.ms + ' ms' : ''); out.className = 'text-sm text-success';
      }).catch(function (e) { out.textContent = e.message; out.className = 'text-sm text-danger'; })
        .then(function () { btn.classList.remove('is-loading'); btn.disabled = false; });
    });
  }
  // نماذج Gemini المتاحة للمفتاح: تُملأ القائمة، ويُقترح أحدث Flash مستقر إن كان الحقل فارغاً
  var gload = document.getElementById('ai-gload');
  if (gload) {
    gload.addEventListener('click', function () {
      var f = document.getElementById('ai-form'), list = document.getElementById('ai-gmodels'), field = document.getElementById('ai-gmodel');
      gload.classList.add('is-loading'); gload.disabled = true;
      SN.api(SN.base + '/admin/ai/gemini-models', { method: 'POST', data: { key: f.gemini_key.value.trim() } }).then(function (r) {
        list.innerHTML = '';
        r.models.forEach(function (m) { var o = document.createElement('option'); o.value = m.id; o.label = m.name; list.appendChild(o); });
        if (!field.value.trim() && r.suggested) { field.value = r.suggested; }
        SN.toast('success', r.message + (r.suggested ? ' المقترح: ' + r.suggested : ''));
      }).catch(function () {}).then(function () { gload.classList.remove('is-loading'); gload.disabled = false; });
    });
  }
});
</script>
