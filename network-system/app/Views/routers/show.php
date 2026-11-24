<?php
/** @var App\Core\View $v */
use App\Controllers\RouterController;

$v->title = $router['name'];
$v->breadcrumbs = [['أجهزة MikroTik', '/routers'], [$router['name'], null]];
$statusBadge = $router['status'] === 'online'
    ? '<span class="badge badge-success badge-dot">متصل</span>'
    : ($router['status'] === 'offline' ? '<span class="badge badge-danger badge-dot">منقطع</span>' : '<span class="badge badge-dot">غير معروف</span>');
$v->subtitle = trim(($router['identity'] ?: '') . ' · ' . $router['host'] . ':' . $router['api_port'] . ($router['area_name'] ? ' · ' . $router['area_name'] : ''), ' ·');
$actions = '';
if (\App\Services\Ai::offered()) {
    // الفحص الذكي: يظهر عند تفعيل الذكاء للحساب، وبحالة «غير متاح» إن تعذّر استخدامه مؤقتاً
    $aiOk = \App\Services\Ai::available();
    $actions .= '<button type="button" class="btn btn-ai" data-router-check="' . e(url('/routers/' . $router['id'] . '/diagnose')) . '"' . ($aiOk ? '' : ' disabled title="' . e(\App\Services\Ai::stateLabel(\App\Services\Ai::status())) . '"') . '>'
        . icon('sparkles') . ($aiOk ? 'فحص ذكي' : 'الفحص الذكي غير متاح') . '</button>';
}
$actions .= '<form method="post" action="' . e(url('/routers/' . $router['id'] . '/sync')) . '">' . csrf_field() . '<button class="btn">' . icon('refresh-cw') . 'مزامنة الآن</button></form>';
if (can('routers.reboot')) {
    $actions .= '<form method="post" action="' . e(url('/routers/' . $router['id'] . '/reboot')) . '" data-confirm="إعادة تشغيل جهاز MikroTik ' . e($router['name']) . '؟ سينقطع جميع المتصلين مؤقتاً.">' . csrf_field() . '<button class="btn btn-soft-danger">' . icon('power') . 'إعادة تشغيل</button></form>';
}
if (can('routers.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/routers/' . $router['id'] . '/edit')) . '">' . icon('pencil') . 'تعديل</a>';
}
$connectState = can('routers.manage') ? \App\Services\RouterConnect::state((int) $router['id']) : null;
if ($connectState !== null && empty($connectState['ready_at'])) {
    $actions = '<a class="btn btn-primary" href="' . e(url('/routers/connect/' . $router['id'])) . '">' . icon('link') . 'أكمل الربط</a>' . $actions;
}
$v->actions = $actions;
$visibleTabs = RouterController::TABS;
if (!can('routers.control')) {
    unset($visibleTabs['users'], $visibleTabs['bindings'], $visibleTabs['walled'], $visibleTabs['tools'], $visibleTabs['ppp']);
}
if (!can('routers.backup')) {
    unset($visibleTabs['files']);
}
if (!can('routers.manage')) {
    unset($visibleTabs['setup'], $visibleTabs['tasks']);
}
if (!can('cards.import') && !can('subscribers.manage')) {
    unset($visibleTabs['import']);
}
?>
<div class="flex items-center gap-2 wrap mb-2">
  <?= $statusBadge ?>
  <?php if (!$router['is_active']): ?><span class="badge badge-outline">معطّل في النظام</span><?php endif; ?>
  <?php if ($router['last_error']): ?><span class="badge badge-warning badge-wrap" title="<?= e($router['last_error']) ?>"><?= icon('triangle-alert') ?><?= e(str_limit($router['last_error'], 70)) ?></span><?php endif; ?>
  <span class="text-muted text-sm">آخر مزامنة: <?= e(time_ago($router['last_polled_at'])) ?></span>
</div>
<div class="panel">
  <nav class="tabs">
    <?php foreach ($visibleTabs as $key => [$label, $ic]): ?>
      <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('/routers/' . $router['id'], ['tab' => $key])) ?>"><?= icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($error): ?>
    <div class="panel-body">
      <div class="notice notice-danger"><?= icon('wifi-off') ?><div><div class="notice-title">تعذّر جلب البيانات من جهاز MikroTik</div><?= e($error) ?></div></div>
    </div>
  <?php elseif (isset($visibleTabs[$tab])): ?>
    <?= $v->renderFile('routers/tabs/' . $tab, get_defined_vars()) ?>
  <?php endif; ?>
</div>

<?php if (\App\Services\Ai::offered()): ?>
<dialog class="modal" id="m-router-check">
  <div class="modal-head"><h3><?= icon('sparkles') ?>الفحص الذكي · <?= e($router['name']) ?></h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close><?= icon('x') ?></button></div>
  <div class="modal-body">
    <div class="ai-check" data-check-state="loading">
      <div class="ai-check-loading"><span class="ai-check-orb"><?= icon('sparkles') ?></span><b>يقرأ حالة الجهاز وواجهاته وسجله</b><span class="text-muted text-sm">قراءة فقط، لا يتغير شيء على الجهاز</span></div>
      <div class="ai-check-result" hidden>
        <div class="ai-check-level"><span class="badge" data-check-level></span><span class="text-muted text-sm" data-check-note></span></div>
        <div class="ai-check-text" data-check-text></div>
      </div>
      <div class="notice notice-danger ai-check-error" hidden><?= icon('triangle-alert') ?><div data-check-error></div></div>
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-modal-close>إغلاق</button><button type="button" class="btn" data-check-copy hidden><?= icon('copy') ?>نسخ</button><button type="button" class="btn btn-primary" data-check-again hidden><?= icon('refresh-cw') ?>فحص من جديد</button></div>
</dialog>
<script>
(function () {
  var btn = document.querySelector('[data-router-check]'), m = document.getElementById('m-router-check');
  if (!btn || !m) { return; }
  var box = m.querySelector('.ai-check'), busy = false, last = '';
  var show = function (state) {
    box.setAttribute('data-check-state', state);
    m.querySelector('.ai-check-loading').hidden = state !== 'loading';
    m.querySelector('.ai-check-result').hidden = state !== 'done';
    m.querySelector('.ai-check-error').hidden = state !== 'error';
    m.querySelector('[data-check-again]').hidden = state === 'loading';
    m.querySelector('[data-check-copy]').hidden = state !== 'done';
  };
  // النص يُعرض عناوين ونقاطاً: «الملاحظات:» و«الحل المقترح:» عناوين، والأسطر التي تبدأ بشرطة أو رقم نقاط
  var render = function (text) {
    var out = m.querySelector('[data-check-text]');
    out.innerHTML = '';
    var list = null;
    String(text).split(/\n+/).forEach(function (line) {
      line = line.trim();
      if (!line) { return; }
      var item = line.match(/^(?:[-•–]|\d+[.)\-]|[٠-٩]+[.)\-])\s*(.+)$/);
      if (item) {
        if (!list) { list = document.createElement(/^\d|^[٠-٩]/.test(line) ? 'ol' : 'ul'); out.appendChild(list); }
        var li = document.createElement('li'); li.textContent = item[1]; list.appendChild(li);
        return;
      }
      list = null;
      var el = document.createElement(/[:：]$/.test(line) && line.length < 40 ? 'h4' : 'p');
      el.textContent = line.replace(/[:：]$/, '');
      out.appendChild(el);
    });
  };
  var run = function () {
    if (busy) { return; }
    busy = true;
    show('loading');
    SN.api(btn.getAttribute('data-router-check'), { method: 'POST', silent: true })
      .then(function (r) {
        var lv = m.querySelector('[data-check-level]');
        lv.className = 'badge badge-dot ' + ({ ok: 'badge-success', warn: 'badge-warning', bad: 'badge-danger' }[r.level] || '');
        lv.textContent = r.level_label;
        m.querySelector('[data-check-note]').textContent = r.reachable ? '' : 'تعذّر الاتصال بالجهاز الآن: التشخيص من آخر بياناته في النظام';
        last = r.level_label + '\n\n' + r.text;
        render(r.text);
        show('done');
      })
      .catch(function (e) { m.querySelector('[data-check-error]').textContent = e.message; show('error'); })
      .then(function () { busy = false; });
  };
  btn.addEventListener('click', function () { if (typeof m.showModal === 'function' && !m.open) { m.showModal(); } run(); });
  m.querySelector('[data-check-again]').addEventListener('click', run);
  m.querySelector('[data-check-copy]').addEventListener('click', function () {
    if (navigator.clipboard) { navigator.clipboard.writeText(last).then(function () { SN.toast('success', 'تم النسخ.'); }); }
  });
})();
</script>
<?php endif; ?>
