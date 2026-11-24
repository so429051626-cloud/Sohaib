<?php
/** @var App\Core\View $v */
use App\Core\Auth;
use App\Core\Settings;

$v->title = 'الإعدادات';
$v->subtitle = 'إعدادات النظام والربط والتنبيهات';
$tabs = [
    'general'     => ['عام', 'settings'],
    'security'    => ['الأمان', 'shield'],
    'sync'        => ['المزامنة', 'refresh-cw'],
    'notify'      => ['التنبيهات', 'bell'],
    'retention'   => ['الاحتفاظ بالبيانات', 'archive'],
    'backup'      => ['النسخ الاحتياطي', 'database'],
    'cron'        => ['المهام المجدولة', 'clock'],
];
if (\App\Services\CronService::managed()) {
    unset($tabs['cron']);
}
$s = static fn (string $k) => old(str_replace('.', '__', $k), (string) Settings::get($k, ''));
$name = static fn (string $k) => str_replace('.', '__', $k);
$on = static fn (string $k) => Settings::bool($k);
$zones = ['Asia/Gaza', 'Asia/Hebron', 'Asia/Jerusalem', 'Asia/Amman', 'Africa/Cairo', 'Asia/Riyadh', 'Asia/Baghdad', 'Asia/Damascus', 'Asia/Beirut', 'Asia/Dubai', 'Europe/Istanbul', 'UTC'];
$switch = static function (string $key, string $label, string $hint = '') use ($name, $on): string {
    return '<label class="switch"><input type="checkbox" name="' . e($name($key)) . '" value="1"' . checked($on($key)) . '><span class="track"></span><span>' . e($label)
        . ($hint !== '' ? '<span class="d-block hint">' . e($hint) . '</span>' : '') . '</span></label>';
};
$lastRun = (string) Settings::get('cron.last_run', '');
?>
<div class="panel">
  <nav class="tabs" aria-label="أقسام الإعدادات">
    <?php foreach ($tabs as $key => [$label, $ic]): ?>
      <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('/settings', ['tab' => $key])) ?>"><?= icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'backup'): ?>
  <div class="panel-body">
    <form method="post" action="<?= e(url('/settings')) ?>" class="mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="backup">
      <div class="form-grid">
        <div class="field col-6"><?= $switch('backup.auto', 'نسخة احتياطية تلقائية يومية') ?></div>
        <div class="field col-3">
          <label for="bk">عدد النسخ المحفوظة</label>
          <input class="input" id="bk" name="<?= e($name('backup.keep')) ?>" value="<?= e($s('backup.keep')) ?>" inputmode="numeric">
        </div>
        <div class="field col-3" style="justify-content:flex-end"><button class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
      </div>
    </form>
    <?php if (can('backup.manage')): ?>
    <div class="flex items-center justify-between gap-2 wrap mb-2">
      <h3>النسخ المتوفرة</h3>
      <form method="post" action="<?= e(url('/settings/backup')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-soft"><?= icon('database') ?>إنشاء نسخة الآن</button>
      </form>
    </div>
    <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px">
      <table class="table">
        <thead><tr><th>الملف</th><th>الحجم</th><th>التاريخ</th><th class="w-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><span class="mono"><?= e($b['name']) ?></span></td>
            <td><?= e(fmt_bytes($b['size'])) ?></td>
            <td class="nowrap"><?= e(date('Y-m-d H:i', $b['time'])) ?></td>
            <td class="w-actions">
              <div class="row-actions">
                <a class="btn btn-ghost btn-icon btn-sm" href="<?= e(url('/settings/backup/download', ['file' => $b['name']])) ?>" title="تنزيل"><?= icon('download') ?></a>
                <form method="post" action="<?= e(url('/settings/backup/delete')) ?>" data-confirm="حذف هذه النسخة؟">
                  <?= csrf_field() ?><input type="hidden" name="file" value="<?= e($b['name']) ?>">
                  <button class="btn btn-ghost btn-icon btn-sm text-danger" title="حذف"><?= icon('trash-2') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$backups): ?><tr><td colspan="4"><div class="empty"><?= icon('archive') ?><div>لا توجد نسخ احتياطية.</div></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (Auth::isOwner()): ?>
    <hr>
    <h3 class="mb-2">استعادة نسخة احتياطية</h3>
    <div class="notice notice-warning mb-2"><?= icon('triangle-alert') ?><div>الاستعادة تستبدل كل البيانات الحالية.</div></div>
    <form method="post" action="<?= e(url('/settings/backup/restore')) ?>" enctype="multipart/form-data" data-confirm="سيتم استبدال جميع البيانات الحالية. متابعة؟">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field col-4">
          <label for="restore-file">من النسخ المتوفرة</label>
          <select class="input" id="restore-file" name="file">
            <option value="">—</option>
            <?php foreach ($backups as $b): ?><option value="<?= e($b['name']) ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field col-4">
          <label for="restore-upload">أو رفع ملف</label>
          <input class="input" id="restore-upload" type="file" name="backup" accept=".gz,.sql" style="padding-top:7px">
          <?= error_html('backup') ?>
        </div>
        <div class="field col-4">
          <label for="confirm">اكتب «استعادة» للتأكيد</label>
          <input class="input" id="confirm" name="confirm" autocomplete="off">
          <?= error_html('confirm') ?>
        </div>
        <div class="field col-8">
          <label for="restore-config">ملف config.php للنظام السابق</label>
          <input class="input" id="restore-config" type="file" name="old_config" accept=".php" style="padding-top:7px">
          <?= error_html('old_config') ?>
        </div>
      </div>
      <div class="form-actions"><button class="btn btn-danger"><?= icon('rotate-ccw') ?>استعادة</button></div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'cron'): ?>
  <div class="panel-body">
    <dl class="kv mb-3">
      <dt>آخر تشغيل</dt><dd><?= $lastRun !== '' ? e(fmt_dt($lastRun, true)) . ' — ' . e(time_ago($lastRun)) : '<span class="badge badge-danger">لم يعمل بعد</span>' ?></dd>
      <dt>مدة التشغيل</dt><dd><?= e(Settings::get('cron.last_duration', '') !== '' ? Settings::get('cron.last_duration') . ' ثانية' : '—') ?></dd>
      <dt>المصدر</dt><dd><?= e(['cli' => 'سطر الأوامر', 'web' => 'رابط ويب', 'auto' => 'تلقائي من اللوحة'][Settings::get('cron.last_source', '')] ?? '—') ?></dd>
    </dl>
    <?php if ($lastRun === '' || time() - strtotime($lastRun) > 300): ?>
    <div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?><div>المهمة المجدولة لا تعمل بانتظام.</div></div>
    <?php endif; ?>
    <?php $cronUrl = Settings::panelUrl() . '/cron?key=' . $cronKey; ?>
    <div class="form-section-title mb-2">الطريقة الأولى: Cron Jobs في لوحة الاستضافة</div>
    <dl class="kv mb-2">
      <dt>Minute</dt><dd class="mono">*</dd>
      <dt>Hour</dt><dd class="mono">*</dd>
      <dt>Day of Month</dt><dd class="mono">*</dd>
      <dt>Month</dt><dd class="mono">*</dd>
      <dt>Day of Week</dt><dd class="mono">*</dd>
    </dl>
    <div class="field mb-2">
      <label>Command</label>
      <div class="copyable"><pre class="code-block is-wrap" id="c1">php <?= e($cronPath) ?> >/dev/null 2>&1</pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#c1"><?= icon('copy') ?>نسخ</button></div>
    </div>
    <div class="field mb-3">
      <label>Command بديل (عند عدم عمل الأمر السابق)</label>
      <div class="copyable"><pre class="code-block is-wrap" id="c3">wget -q -O /dev/null "<?= e($cronUrl) ?>"</pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#c3"><?= icon('copy') ?>نسخ</button></div>
    </div>
    <div class="form-section-title mb-2">الطريقة الثانية: خدمة Cron خارجية</div>
    <div class="field">
      <label>رابط التشغيل (كل دقيقة)</label>
      <div class="copyable"><pre class="code-block is-wrap" id="c2"><?= e($cronUrl) ?></pre><button type="button" class="btn btn-sm copy-btn" data-copy-target="#c2"><?= icon('copy') ?>نسخ</button></div>
    </div>
  </div>

  <?php else: ?>
  <form method="post" action="<?= e(url('/settings')) ?>" enctype="multipart/form-data" class="panel-body">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="<?= e($tab) ?>">
    <div class="form-grid">
    <?php if ($tab === 'general'):
        $logo = (string) Settings::get('general.logo', ''); ?>
      <div class="field col-6">
        <label for="f1">اسم الشبكة <span class="req">*</span></label>
        <input class="input" id="f1" name="<?= e($name('general.network_name')) ?>" value="<?= e($s('general.network_name')) ?>" required>
        <?= error_html($name('general.network_name')) ?>
      </div>
      <div class="field col-6">
        <label for="f2">المنطقة الزمنية</label>
        <select class="input" id="f2" name="<?= e($name('general.timezone')) ?>">
          <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= selected($s('general.timezone'), $z) ?>><?= e($z) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field col-3">
        <label for="f3">رمز العملة</label>
        <input class="input" id="f3" name="<?= e($name('general.currency')) ?>" value="<?= e($s('general.currency')) ?>">
      </div>
      <div class="field col-3">
        <label for="f4">اسم العملة</label>
        <input class="input" id="f4" name="<?= e($name('general.currency_name')) ?>" value="<?= e($s('general.currency_name')) ?>">
      </div>
      <div class="field col-3">
        <label for="f5">الهاتف</label>
        <input class="input input-ltr" id="f5" name="<?= e($name('general.phone')) ?>" value="<?= e($s('general.phone')) ?>">
      </div>
      <div class="field col-3">
        <label for="f6">واتساب</label>
        <input class="input input-ltr" id="f6" name="<?= e($name('general.whatsapp')) ?>" value="<?= e($s('general.whatsapp')) ?>">
      </div>
      <div class="field col-12">
        <label for="f7">العنوان</label>
        <input class="input" id="f7" name="<?= e($name('general.address')) ?>" value="<?= e($s('general.address')) ?>">
      </div>
      <?php if (!\App\Core\Platform::enabled()): ?>
      <div class="field col-12">
        <label for="f8">الرابط العام للنظام</label>
        <input class="input input-ltr" id="f8" name="<?= e($name('general.panel_url')) ?>" value="<?= e($s('general.panel_url')) ?>" placeholder="https://net.example.com">
        <?= error_html($name('general.panel_url')) ?>
      </div>
      <?php endif; ?>
      <div class="field col-12">
        <label>شعار الشبكة</label>
        <div class="logo-picker" data-logo-picker>
          <span data-logo-preview><?= \App\Core\Brand::networkMark('net-mark-lg') ?></span>
          <div class="logo-picker-body">
            <label class="btn"><?= icon('image') ?>اختيار صورة<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" hidden data-logo-input></label>
            <?php if ($logo !== ''): ?><label class="check"><input type="checkbox" name="remove_logo" value="1">إزالة الشعار</label><?php endif; ?>
            <span class="text-muted text-sm" data-logo-name>PNG · JPG · WEBP</span>
          </div>
        </div>
        <?= error_html('logo') ?>
      </div>
      <?php if (can('map.view')): ?>
      <div class="field col-12">
        <label for="f9">مفتاح خرائط Google</label>
        <input class="input input-ltr" id="f9" name="<?= e($name('map.google_key')) ?>" value="<?= e($s('map.google_key')) ?>" maxlength="120" autocomplete="off" spellcheck="false" placeholder="AIza...">
        <?= error_html($name('map.google_key')) ?>
        <span class="hint"><?= \App\Core\Platform::enabled() ? 'اختياري: اتركه فارغاً لاستخدام خرائط Google التي توفرها المنصة إن وُجدت. ' : '' ?>من Google Cloud: فعّل Map Tiles API وأنشئ مفتاحاً مقيّداً برابط نظامك، فتظهر خرائط Google في خريطة الشبكة واختيار المواقع.</span>
      </div>
      <?php endif; ?>
      <div class="field col-12"><?= $switch('ui.sidebar_hidden', 'إخفاء القائمة الجانبية على الشاشات الكبيرة') ?></div>

    <?php elseif ($tab === 'security'): ?>
      <div class="field col-4">
        <label>مهلة الخمول قبل إنهاء الجلسة</label>
        <div class="input-group"><input class="input" name="<?= e($name('security.idle_minutes')) ?>" value="<?= e($s('security.idle_minutes')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
        <?= error_html($name('security.idle_minutes')) ?>
      </div>
      <div class="field col-4">
        <label>محاولات الدخول المسموحة</label>
        <input class="input" name="<?= e($name('security.login_max_attempts')) ?>" value="<?= e($s('security.login_max_attempts')) ?>" inputmode="numeric">
        <?= error_html($name('security.login_max_attempts')) ?>
      </div>
      <div class="field col-4">
        <label>مدة القفل بعد تجاوز المحاولات</label>
        <div class="input-group"><input class="input" name="<?= e($name('security.login_lock_minutes')) ?>" value="<?= e($s('security.login_lock_minutes')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
        <?= error_html($name('security.login_lock_minutes')) ?>
      </div>
      <div class="field col-12"><?= $switch('security.require_2fa', 'فرض التحقق بخطوتين على الموظفين') ?></div>

    <?php elseif ($tab === 'sync'): ?>
      <div class="field col-4">
        <label>مزامنة كل جهاز MikroTik كل</label>
        <div class="input-group"><input class="input" name="<?= e($name('sync.interval')) ?>" value="<?= e($s('sync.interval')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
      </div>
      <div class="field col-4">
        <label>جلب أسماء الأجهزة كل</label>
        <div class="input-group"><input class="input" name="<?= e($name('sync.leases_interval')) ?>" value="<?= e($s('sync.leases_interval')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
      </div>
      <div class="field col-4">
        <label>حفظ قياسات الأداء كل</label>
        <div class="input-group"><input class="input" name="<?= e($name('sync.metrics_interval')) ?>" value="<?= e($s('sync.metrics_interval')) ?>" inputmode="numeric"><span class="input-addon">دقيقة</span></div>
      </div>
      <div class="field col-4">
        <label>اعتبار جهاز MikroTik منقطعاً بعد</label>
        <div class="input-group"><input class="input" name="<?= e($name('sync.offline_after')) ?>" value="<?= e($s('sync.offline_after')) ?>" inputmode="numeric"><span class="input-addon">محاولة</span></div>
      </div>
      <div class="field col-4">
        <label>مهلة الاتصال بواجهة API</label>
        <div class="input-group"><input class="input" name="<?= e($name('sync.api_timeout')) ?>" value="<?= e($s('sync.api_timeout')) ?>" inputmode="numeric"><span class="input-addon">ثانية</span></div>
      </div>

    <?php elseif ($tab === 'notify'): ?>
      <div class="form-section-title">أنواع التنبيهات</div>
      <div class="field col-6"><?= $switch('notify.router_down', 'انقطاع جهاز MikroTik') ?></div>
      <div class="field col-6"><?= $switch('notify.router_up', 'عودة جهاز MikroTik للعمل') ?></div>
      <div class="field col-6"><?= $switch('notify.low_stock', 'انخفاض مخزون باقة') ?></div>
      <div class="field col-6"><?= $switch('notify.expiring', 'اشتراكات قاربت على الانتهاء') ?></div>
      <div class="field col-6"><?= $switch('notify.inventory_low', 'انخفاض مخزون المعدات') ?></div>
      <div class="field col-6"><?= $switch('maintenance.sms', 'رسالة نصية للفنّي عند إسناد مهمة صيانة إليه') ?></div>
      <div class="field col-4">
        <label>حد حِمل المعالج للتنبيه</label>
        <div class="input-group"><input class="input" name="<?= e($name('notify.cpu_threshold')) ?>" value="<?= e($s('notify.cpu_threshold')) ?>" inputmode="numeric"><span class="input-addon">%</span></div>
      </div>

      <div class="form-section-title">تنبيهات التلاعب</div>
      <div class="field col-12"><?= $switch('notify.tamper', 'كرت على أجهزة أكثر من المسموح، إلغاءات بيع كثيرة، ودين موزّع يكبر ولا يسدّد') ?></div>
      <div class="field col-4" data-show-when="<?= e($name('notify.tamper')) ?>=1">
        <label>إلغاءات موظف خلال 24 ساعة</label>
        <div class="input-group"><input class="input" name="<?= e($name('tamper.cancel_limit')) ?>" value="<?= e($s('tamper.cancel_limit')) ?>" inputmode="numeric"><span class="input-addon">عملية</span></div>
        <?= error_html($name('tamper.cancel_limit')) ?>
      </div>
      <div class="field col-4" data-show-when="<?= e($name('notify.tamper')) ?>=1">
        <label>دين موزّع بلا سداد منذ</label>
        <div class="input-group"><input class="input" name="<?= e($name('tamper.debt_days')) ?>" value="<?= e($s('tamper.debt_days')) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div>
        <?= error_html($name('tamper.debt_days')) ?>
      </div>
      <div class="field col-4" data-show-when="<?= e($name('notify.tamper')) ?>=1">
        <label>بدءاً من دين قدره</label>
        <div class="input-group"><input class="input" name="<?= e($name('tamper.debt_min')) ?>" value="<?= e($s('tamper.debt_min')) ?>" inputmode="decimal"><span class="input-addon"><?= e((string) setting('general.currency', '₪')) ?></span></div>
        <?= error_html($name('tamper.debt_min')) ?>
      </div>

    <?php elseif ($tab === 'retention'): ?>
      <?php foreach (['retention.metrics_days' => 'قياسات أداء أجهزة MikroTik', 'retention.sessions_days' => 'سجل الجلسات', 'retention.login_log_days' => 'سجل محاولات الدخول', 'retention.audit_days' => 'سجل العمليات', 'retention.tasks_days' => 'سجل مهام أجهزة MikroTik'] as $k => $label): ?>
      <div class="field col-4">
        <label><?= e($label) ?></label>
        <div class="input-group"><input class="input" name="<?= e($name($k)) ?>" value="<?= e($s($k)) ?>" inputmode="numeric"><span class="input-addon">يوم</span></div>
        <?= error_html($name($k)) ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('save') ?>حفظ</button></div>
  </form>
  <?php endif; ?>
</div>

