<?php
/**
 * @var App\Core\View $v @var array $config @var bool $hasPass @var bool $configured @var array $counts @var array $log @var array $filter @var array $stats
 */
use App\Controllers\AdminMailController;
use App\Core\Platform;
use App\Platform\PlatformMail;
use App\Services\Mailer;

$v->title = 'البريد الإلكتروني';
$v->script('js/tpl.js');
$c = static fn (string $k) => old($k, (string) $config[$k]);
$adminEmail = (string) (App\Core\AdminAuth::user()['email'] ?? '');
$autoErr = false;
foreach (array_keys($_SESSION['_errors'] ?? []) as $k) {
    $autoErr = $autoErr || str_starts_with((string) $k, 'tpl_');
}
$bErr = field_error('b_subject') ?? field_error('b_body') ?? field_error('emails');
// تبويبات حسب صلاحيات المسؤول: الإعداد والرسائل التلقائية للإعدادات، والبريد الجماعي لمن يرسل
$canSet = App\Core\AdminAuth::can('settings.manage');
$canSend = App\Core\AdminAuth::can('messages.send');
$first = $autoErr && $canSet ? 'auto' : ($bErr !== null && $canSend ? 'broadcast' : ($canSet ? 'settings' : ($canSend ? 'broadcast' : 'log')));
?>
<div class="stats">
  <div class="stat"><div class="stat-icon is-success"><?= icon('send') ?></div><div class="stat-body"><div class="stat-label">أُرسلت خلال 30 يوماً</div><div class="stat-value"><?= num($stats['sent']) ?></div></div></div>
  <div class="stat"><div class="stat-icon <?= $stats['failed'] ? 'is-danger' : '' ?>"><?= icon('circle-x') ?></div><div class="stat-body"><div class="stat-label">تعذّر إرسالها</div><div class="stat-value"><?= num($stats['failed']) ?></div></div></div>
  <div class="stat"><div class="stat-icon is-info"><?= icon('building') ?></div><div class="stat-body"><div class="stat-label">حسابات لها بريد</div><div class="stat-value"><?= num($stats['with_email']) ?></div></div></div>
  <div class="stat"><div class="stat-icon <?= $configured ? 'is-success' : 'is-warning' ?>"><?= icon('bot') ?></div><div class="stat-body"><div class="stat-label">رسائل تلقائية مفعّلة</div><div class="stat-value"><?= num($stats['on']) ?> <small class="text-muted" style="font-size:.6em;font-weight:600">من <?= num(count(PlatformMail::FEATURES)) ?></small></div></div></div>
</div>

<?php if (!$configured): ?>
<div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?><div>خادم البريد غير مضبوط: لن تُرسل أي رسالة حتى تضبطه وتحفظه من تبويب «الإعداد».</div></div>
<?php endif; ?>

<div class="panel mail-page" data-tabs-wrap>
  <div class="tabs" data-tabs data-tabs-hash>
    <?php if ($canSet): ?><a class="tab<?= $first === 'settings' ? ' is-active' : '' ?>" href="#settings" data-tab="settings"><?= icon('settings') ?>الإعداد</a>
    <a class="tab<?= $first === 'auto' ? ' is-active' : '' ?>" href="#auto" data-tab="auto"><?= icon('bot') ?>الرسائل التلقائية</a><?php endif; ?>
    <?php if ($canSend): ?><a class="tab<?= $first === 'broadcast' ? ' is-active' : '' ?>" href="#broadcast" data-tab="broadcast"><?= icon('megaphone') ?>بريد جماعي</a><?php endif; ?>
    <a class="tab<?= $first === 'log' ? ' is-active' : '' ?>" href="#log" data-tab="log"><?= icon('history') ?>السجل</a>
  </div>

  <?php if ($canSet): ?>
  <!-- الإعداد -->
  <div class="tab-panel<?= $first === 'settings' ? ' is-active' : '' ?>" data-tab-panel="settings">
    <div class="panel-body">
      <div class="grid grid-main-side">
        <form method="post" action="<?= e(url('/admin/mail/settings')) ?>" autocomplete="off" id="mail-form">
          <?= csrf_field() ?>
          <h3 class="mail-h"><?= icon('mail') ?>خادم البريد (SMTP) <span class="badge <?= $configured ? 'badge-success badge-dot' : 'badge-outline' ?>"><?= $configured ? 'مضبوط' : 'غير مضبوط' ?></span></h3>
          <div class="form-grid">
            <div class="field col-8"><label for="m-host">الخادم</label><input class="input input-ltr" id="m-host" name="host" value="<?= e($c('host')) ?>" placeholder="smtp.gmail.com" spellcheck="false" autocapitalize="off"><?= error_html('host') ?></div>
            <div class="field col-4"><label for="m-port">المنفذ</label><input class="input input-ltr" id="m-port" name="port" value="<?= e($c('port')) ?>" inputmode="numeric"><?= error_html('port') ?></div>
            <div class="field col-12"><label>التشفير</label>
              <div class="segmented">
                <?php foreach (Mailer::SECURE as $k => $label): ?><label><input type="radio" name="secure" value="<?= e($k) ?>"<?= checked($c('secure') === $k) ?>><span><?= e($label) ?></span></label><?php endforeach; ?>
              </div>
            </div>
            <div class="field col-6"><label for="m-user">اسم المستخدم</label><input class="input input-ltr" id="m-user" name="user" value="<?= e($c('user')) ?>" placeholder="you@gmail.com" spellcheck="false" autocapitalize="off" autocomplete="off"><?= error_html('user') ?></div>
            <div class="field col-6"><label for="m-pass">كلمة المرور</label><input class="input input-ltr" id="m-pass" name="pass" type="password" value="" autocomplete="new-password" placeholder="<?= $hasPass ? '••••••••••••' : '' ?>">
              <?php if ($hasPass): ?><label class="check mt-2"><input type="checkbox" name="remove_pass" value="1"><span>حذف كلمة المرور المحفوظة</span></label><?php endif; ?></div>
            <div class="field col-6"><label for="m-from">بريد المرسل</label><input class="input input-ltr" id="m-from" name="from" value="<?= e($c('from')) ?>" placeholder="no-reply@example.com" spellcheck="false" autocapitalize="off"><?= error_html('from') ?></div>
            <div class="field col-6"><label for="m-name">اسم المرسل</label><input class="input" id="m-name" name="from_name" value="<?= e(old('from_name', Platform::setting('mail.from_name', ''))) ?>" placeholder="<?= e(App\Platform\PlatformSms::name()) ?>"><?= error_html('from_name') ?></div>
          </div>
          <p class="text-sm text-muted mt-3"><?= icon('info', 'icon-sm') ?> مثال Gmail: الخادم smtp.gmail.com والمنفذ 587 مع TLS، واسم المستخدم بريدك، وكلمة المرور «كلمة مرور التطبيقات» من إعدادات حساب Google (لا كلمة مرور الحساب).</p>
          <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
        </form>
        <div class="mail-side">
          <h3 class="mail-h"><?= icon('send') ?>رسالة تجريبية</h3>
          <p class="text-sm text-muted">تُرسل بما كُتب في النموذج قبل حفظه، لتتأكد أن الخادم يعمل.</p>
          <div class="field"><label for="m-to">إلى</label><input class="input input-ltr" id="m-to" type="email" placeholder="you@example.com" value="<?= e($adminEmail) ?>"></div>
          <button type="button" class="btn btn-block" id="mail-test"><?= icon('send') ?>إرسال رسالة تجريبية</button>
          <div class="slug-status" id="mail-test-result" aria-live="polite"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- الرسائل التلقائية -->
  <div class="tab-panel<?= $first === 'auto' ? ' is-active' : '' ?>" data-tab-panel="auto">
    <form method="post" action="<?= e(url('/admin/mail/features')) ?>" class="panel-body" id="mail-auto">
      <?= csrf_field() ?>
      <div class="mail-auto-head">
        <p class="text-sm text-muted"><?= icon('info', 'icon-sm') ?> تصل كل رسالة إلى البريد المسجّل في حساب الزبون. لكل رسالة مفتاح تشغيل، وعنوان ونص تكتبهما بالرموز، ومعاينة، وإرسال تجربة ببيانات نموذجية.</p>
        <div class="field mail-try"><label for="m-try">بريد التجربة</label><input class="input input-ltr" id="m-try" type="email" placeholder="you@example.com" value="<?= e($adminEmail) ?>"></div>
      </div>
      <div class="mail-auto-list">
        <?php foreach (PlatformMail::FEATURES as $key => [$setting, $label, $ic, $templates]): $on = Platform::setting($setting) === '1'; ?>
        <div class="mail-card<?= $on ? ' is-on' : '' ?>">
          <div class="mail-card-head">
            <span class="mail-card-icon"><?= icon($ic) ?></span>
            <label class="switch"><input type="checkbox" name="<?= e($key) ?>" value="1"<?= checked($on) ?>><span class="track"></span><span><?= e($label) ?></span></label>
          </div>
          <?php foreach ($templates as $tpl => $tplLabel): $hasErr = field_error('tpl_' . $tpl) !== null || field_error('tpl_' . $tpl . '_subject') !== null; ?>
          <details class="tpl-edit"<?= $hasErr ? ' open' : '' ?>>
            <summary><?= icon('pencil', 'icon-sm') ?>العنوان والنص<?= $tplLabel !== '' ? ' — ' . e($tplLabel) : '' ?></summary>
            <div class="field mb-2"><label for="tpl-<?= e($tpl) ?>-subject">العنوان</label><input class="input" id="tpl-<?= e($tpl) ?>-subject" name="tpl_<?= e($tpl) ?>_subject" maxlength="<?= PlatformMail::SUBJECT_MAX ?>" value="<?= e(old('tpl_' . $tpl . '_subject', PlatformMail::template($tpl . '_subject'))) ?>"><?= error_html('tpl_' . $tpl . '_subject') ?></div>
            <div class="sms-tokens">
              <?php foreach (PlatformMail::tokens($tpl) as $t => $l): ?><button type="button" class="sms-token" data-tpl-token="<?= e($t) ?>" data-tpl-target="tpl-<?= e($tpl) ?>"><?= icon('plus', 'icon-sm') ?><?= e($l) ?></button><?php endforeach; ?>
            </div>
            <textarea class="input sms-body" id="tpl-<?= e($tpl) ?>" name="tpl_<?= e($tpl) ?>" rows="9" maxlength="<?= PlatformMail::BODY_MAX ?>" data-tpl-text><?= e(old('tpl_' . $tpl, PlatformMail::template($tpl))) ?></textarea>
            <?= error_html('tpl_' . $tpl) ?>
            <div class="tpl-foot">
              <span class="text-sm text-muted"><b data-tpl-count>0</b> حرف</span>
              <span class="mail-tpl-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-mail-preview="<?= e($tpl) ?>"><?= icon('eye') ?>معاينة</button>
                <button type="button" class="btn btn-ghost btn-sm" data-mail-try="<?= e($tpl) ?>"><?= icon('send') ?>إرسال تجربة</button>
                <button type="button" class="btn btn-ghost btn-sm" data-tpl-default="<?= e(PlatformMail::TEMPLATES[$tpl]) ?>" data-tpl-default-subject="<?= e(PlatformMail::TEMPLATES[$tpl . '_subject']) ?>" data-tpl-target="tpl-<?= e($tpl) ?>"><?= icon('rotate-ccw') ?>النص الافتراضي</button>
              </span>
            </div>
          </details>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon('save') ?>حفظ الرسائل التلقائية</button></div>
    </form>
  </div>

  <?php endif; ?>
  <?php if ($canSend): ?>
  <!-- بريد جماعي -->
  <div class="tab-panel<?= $first === 'broadcast' ? ' is-active' : '' ?>" data-tab-panel="broadcast">
    <form method="post" action="<?= e(url('/admin/mail/broadcast')) ?>" class="panel-body" data-confirm="إرسال هذا البريد إلى كل المستلمين المختارين؟">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field col-6"><label for="b-aud">إلى أصحاب الحسابات</label>
          <select class="input" id="b-aud" name="audience">
            <?php foreach (AdminMailController::AUDIENCE as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('audience', 'all'), $k) ?>><?= e($l) ?><?= $k !== 'none' ? ' (' . num($counts[$k]) . ')' : '' ?></option><?php endforeach; ?>
          </select>
          <div class="text-sm text-muted mt-1">العدد بين القوسين: الحسابات التي لها بريد صحيح.</div>
        </div>
        <div class="field col-6"><label for="b-emails">وعناوين أخرى (اختياري)</label><textarea class="input input-ltr" id="b-emails" name="emails" rows="2" placeholder="a@example.com, b@example.com"><?= e(old('emails', '')) ?></textarea><?= error_html('emails') ?></div>
        <div class="field col-12"><label for="b-subject">العنوان</label><input class="input" id="b-subject" name="b_subject" maxlength="<?= PlatformMail::SUBJECT_MAX ?>" value="<?= e(old('b_subject', '')) ?>"><?= error_html('b_subject') ?></div>
        <div class="field col-12">
          <label for="b-body">النص</label>
          <div class="sms-tokens">
            <?php foreach (AdminMailController::PLACEHOLDERS as $t => $l): ?><button type="button" class="sms-token" data-tpl-token="<?= e($t) ?>" data-tpl-target="b-body"><?= icon('plus', 'icon-sm') ?><?= e($l) ?></button><?php endforeach; ?>
          </div>
          <textarea class="input sms-body" id="b-body" name="b_body" rows="9" maxlength="<?= PlatformMail::BODY_MAX ?>" data-tpl-text placeholder="مرحباً {الاسم}،&#10;&#10;..."><?= e(old('b_body', '')) ?></textarea>
          <?= error_html('b_body') ?>
          <div class="tpl-foot"><span class="text-sm text-muted"><b data-tpl-count>0</b> حرف · الرموز تُستبدل لكل مستلم، ويُضاف زر الدخول لأصحاب الحسابات.</span></div>
        </div>
      </div>
      <div class="form-actions"><button class="btn btn-primary"<?= $configured ? '' : ' disabled' ?>><?= icon('send') ?>إرسال البريد</button></div>
    </form>
  </div>

  <?php endif; ?>
  <!-- السجل -->
  <div class="tab-panel<?= $first === 'log' ? ' is-active' : '' ?>" data-tab-panel="log">
    <form class="filters" method="get" action="<?= e(url('/admin/mail')) ?>#log">
      <div class="field"><label for="l-kind">النوع</label><select class="input" id="l-kind" name="kind" data-autosubmit><option value="">الكل</option><?php foreach (PlatformMail::KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($filter['kind'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="l-state">الحالة</label><select class="input" id="l-state" name="state" data-autosubmit><option value="">الكل</option><option value="ok"<?= selected($filter['state'], 'ok') ?>>أُرسلت</option><option value="failed"<?= selected($filter['state'], 'failed') ?>>تعذّر إرسالها</option></select></div>
      <div class="field grow"><label for="l-q">بحث</label><input class="input" id="l-q" type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="البريد أو العنوان أو الحساب"></div>
      <button class="btn"><?= icon('search') ?>بحث</button>
    </form>
    <div class="mail-log">
      <?php foreach ($log as $m): ?>
      <div class="mail-log-row<?= (int) $m['ok'] ? '' : ' is-failed' ?>">
        <span class="mail-log-state" title="<?= (int) $m['ok'] ? 'أُرسلت' : 'تعذّر إرسالها' ?>"><?= icon((int) $m['ok'] ? 'circle-check' : 'circle-x') ?></span>
        <span class="mail-log-main">
          <span class="mail-log-subject"><?= e($m['subject']) ?></span>
          <span class="mail-log-meta">
            <bdi class="mail-log-to"><?= e($m['to_email']) ?></bdi>
            <?php if ($m['tenant_name'] !== null): ?><a href="<?= e(url('/admin/tenants/' . (int) $m['tenant_id'])) ?>"><?= icon('building', 'icon-sm') ?><?= e($m['tenant_name']) ?></a><?php endif; ?>
            <?php if ($m['admin_name'] !== null): ?><span><?= icon('user', 'icon-sm') ?><?= e($m['admin_name']) ?></span><?php endif; ?>
          </span>
          <?php if (!(int) $m['ok'] && $m['error']): ?><span class="mail-log-error"><?= e($m['error']) ?></span><?php endif; ?>
        </span>
        <span class="mail-log-end">
          <span class="badge badge-outline"><?= e(PlatformMail::KINDS[$m['kind']] ?? $m['kind']) ?></span>
          <span class="text-muted text-sm" title="<?= e(fmt_dt($m['created_at'])) ?>"><?= e(time_ago($m['created_at'])) ?></span>
        </span>
      </div>
      <?php endforeach; ?>
      <?php if (!$log): ?><div class="empty"><?= icon('mail') ?><div class="empty-title">لا رسائل في السجل</div><div class="empty-text">كل رسالة تُرسل من المنصة أو يتعذّر إرسالها تظهر هنا مع السبب.</div></div><?php endif; ?>
    </div>
  </div>
</div>

<dialog class="modal modal-lg" id="m-mail-preview" aria-labelledby="m-mail-preview-title">
  <div class="modal-head"><h3 id="m-mail-preview-title"><?= icon('eye') ?>معاينة الرسالة</h3><button type="button" class="btn btn-ghost btn-icon btn-sm" data-modal-close aria-label="إغلاق"><?= icon('x') ?></button></div>
  <div class="modal-body mail-preview-body"><iframe title="معاينة الرسالة" sandbox="" class="mail-preview-frame" id="mail-preview-frame"></iframe></div>
  <div class="modal-foot"><span class="text-sm text-muted">ببيانات نموذجية · كما تظهر في صندوق البريد</span><button type="button" class="btn" data-modal-close>إغلاق</button></div>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('mail-test'), out = document.getElementById('mail-test-result'), f = document.getElementById('mail-form');
  if (btn) btn.addEventListener('click', function () {
    var data = { to: document.getElementById('m-to').value.trim() };
    ['host', 'port', 'user', 'pass', 'from', 'from_name'].forEach(function (k) { data[k] = f.elements[k].value.trim(); });
    data.secure = (f.querySelector('[name=secure]:checked') || {}).value || '';
    btn.classList.add('is-loading'); btn.disabled = true; out.className = 'slug-status'; out.textContent = '';
    SN.api(SN.base + '/admin/mail/test', { method: 'POST', silent: true, data: data })
      .then(function (r) { out.className = 'slug-status is-ok'; out.textContent = r.message; })
      .catch(function (e) { out.className = 'slug-status is-bad'; out.textContent = e.message; })
      .then(function () { btn.classList.remove('is-loading'); btn.disabled = false; });
  });
  // القالب كما هو مكتوب الآن في النموذج (قبل الحفظ)
  var current = function (tpl) {
    var d = { tpl: tpl };
    d['tpl_' + tpl + '_subject'] = document.getElementById('tpl-' + tpl + '-subject').value;
    d['tpl_' + tpl] = document.getElementById('tpl-' + tpl).value;
    return d;
  };
  document.addEventListener('click', function (e) {
    var pv = e.target.closest('[data-mail-preview]');
    var tr = e.target.closest('[data-mail-try]');
    if (pv) {
      var form = new FormData();
      var d = current(pv.getAttribute('data-mail-preview'));
      Object.keys(d).forEach(function (k) { form.append(k, d[k]); });
      pv.classList.add('is-loading');
      fetch(SN.base + '/admin/mail/preview', { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-Token': SN.csrf } })
        .then(function (r) { return r.text(); })
        .then(function (html) { document.getElementById('mail-preview-frame').srcdoc = html; SN.openModal('m-mail-preview'); })
        .catch(function () { SN.toast('error', 'تعذّرت المعاينة.'); })
        .then(function () { pv.classList.remove('is-loading'); });
    }
    if (tr) {
      var data = current(tr.getAttribute('data-mail-try'));
      data.to = document.getElementById('m-try').value.trim();
      tr.classList.add('is-loading'); tr.disabled = true;
      SN.api(SN.base + '/admin/mail/test', { method: 'POST', data: data })
        .then(function (r) { SN.toast('success', r.message); })
        .catch(function () {})
        .then(function () { tr.classList.remove('is-loading'); tr.disabled = false; });
    }
  });
});
</script>
