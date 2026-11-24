<?php
/** @var App\Core\View $v */
$v->title = 'تثبيت النظام';
$v->wide = true;
$zones = ['Asia/Gaza', 'Asia/Hebron', 'Asia/Jerusalem', 'Asia/Amman', 'Africa/Cairo', 'Asia/Riyadh', 'Asia/Baghdad', 'Asia/Damascus', 'Asia/Beirut', 'Asia/Dubai', 'Europe/Istanbul', 'UTC'];
?>
<?php if ($done): ?>
  <h1 class="auth-title">اكتمل التثبيت</h1>
  <p class="auth-sub">تم إنشاء قاعدة البيانات وحساب المدير.</p>
  <?php if (!$done['written']): ?>
    <div class="notice notice-warning mb-3"><?= icon('triangle-alert') ?>
      <div><div class="notice-title">تعذّر كتابة ملف الإعداد تلقائياً</div>
      أنشئ الملف <span class="mono">config/config.php</span> بالمحتوى التالي ثم حدّث الصفحة.</div>
    </div>
    <div class="copyable mb-3">
      <pre class="code-block" id="cfg"><?= e($done['content']) ?></pre>
      <button type="button" class="btn btn-sm copy-btn" data-copy-target="#cfg"><?= icon('copy') ?>نسخ</button>
    </div>
  <?php endif; ?>
  <div class="notice mb-3"><?= icon('clock') ?>
    <div>
      <div class="notice-title">المهمة المجدولة (Cron)</div>
      أضف في لوحة الاستضافة مهمة مجدولة كل دقيقة بأحد الأمرين:
    </div>
  </div>
  <div class="copyable mb-2">
    <pre class="code-block" id="cron1">php <?= e(BASE_PATH) ?>/cli/cron.php >/dev/null 2>&1</pre>
    <button type="button" class="btn btn-sm copy-btn" data-copy-target="#cron1"><?= icon('copy') ?>نسخ</button>
  </div>
  <div class="copyable mb-3">
    <pre class="code-block" id="cron2">curl -s "<?= e(rtrim($done['url'], '/')) ?>/cron?key=<?= e($done['cron_key']) ?>" >/dev/null 2>&1</pre>
    <button type="button" class="btn btn-sm copy-btn" data-copy-target="#cron2"><?= icon('copy') ?>نسخ</button>
  </div>
  <a class="btn btn-primary btn-lg btn-block" href="<?= e(url('/login')) ?>"><?= icon('log-in') ?>الانتقال إلى تسجيل الدخول</a>
<?php else: ?>
  <h1 class="auth-title">تثبيت النظام</h1>

  <ul class="req-list mb-3">
    <?php foreach ($requirements as $r): ?>
      <li>
        <?php if ($r['ok']): ?><span class="ok"><?= icon('circle-check') ?></span>
        <?php elseif ($r['required']): ?><span class="bad"><?= icon('circle-x') ?></span>
        <?php else: ?><span class="warn"><?= icon('triangle-alert') ?></span><?php endif; ?>
        <span><?= e($r['label']) ?></span>
        <?php if (!$r['ok'] && !empty($r['note'])): ?><span class="text-muted text-sm ms-auto"><?= e($r['note']) ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if (!$canInstall): ?>
    <div class="notice notice-danger"><?= icon('circle-x') ?><div>لا يمكن المتابعة قبل استيفاء المتطلبات الإلزامية.</div></div>
  <?php else: ?>
  <form method="post" action="<?= e(url('/install')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="form-section-title">بيانات النظام</div>
      <div class="field col-6">
        <label for="network_name">اسم الشبكة <span class="req">*</span></label>
        <input class="input" id="network_name" name="network_name" value="<?= e(old('network_name', 'SOHAIB NET')) ?>" required>
        <?= error_html('network_name') ?>
      </div>
      <div class="field col-6">
        <label for="timezone">المنطقة الزمنية <span class="req">*</span></label>
        <select class="input" id="timezone" name="timezone">
          <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= selected(old('timezone', 'Asia/Gaza'), $z) ?>><?= e($z) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field col-12">
        <label for="app_url">رابط النظام <span class="req">*</span></label>
        <input class="input input-ltr" id="app_url" name="app_url" value="<?= e(old('app_url', $defaultUrl)) ?>" required>
        <?= error_html('app_url') ?>
      </div>

      <div class="form-section-title">قاعدة البيانات (MySQL)</div>
      <div class="field col-8">
        <label for="db_host">الخادم <span class="req">*</span></label>
        <input class="input input-ltr" id="db_host" name="db_host" value="<?= e(old('db_host', 'localhost')) ?>" required>
      </div>
      <div class="field col-4">
        <label for="db_port">المنفذ <span class="req">*</span></label>
        <input class="input input-ltr" id="db_port" name="db_port" value="<?= e(old('db_port', '3306')) ?>" inputmode="numeric" required>
      </div>
      <div class="field col-4">
        <label for="db_name">اسم قاعدة البيانات <span class="req">*</span></label>
        <input class="input input-ltr" id="db_name" name="db_name" value="<?= e(old('db_name')) ?>" required>
        <?= error_html('db_name') ?>
      </div>
      <div class="field col-4">
        <label for="db_user">المستخدم <span class="req">*</span></label>
        <input class="input input-ltr" id="db_user" name="db_user" value="<?= e(old('db_user')) ?>" required>
      </div>
      <div class="field col-4">
        <label for="db_pass">كلمة المرور</label>
        <input class="input input-ltr" id="db_pass" name="db_pass" type="password">
      </div>

      <div class="form-section-title">حساب المدير</div>
      <div class="field col-6">
        <label for="admin_name">الاسم الكامل <span class="req">*</span></label>
        <input class="input" id="admin_name" name="admin_name" value="<?= e(old('admin_name')) ?>" required>
        <?= error_html('admin_name') ?>
      </div>
      <div class="field col-6">
        <label for="admin_username">اسم الدخول <span class="req">*</span></label>
        <input class="input input-ltr" id="admin_username" name="admin_username" value="<?= e(old('admin_username', 'admin')) ?>" required>
        <?= error_html('admin_username') ?>
      </div>
      <div class="field col-6">
        <label for="admin_password">كلمة المرور <span class="req">*</span></label>
        <input class="input input-ltr" id="admin_password" name="admin_password" type="password" minlength="8" required>
        <?= error_html('admin_password') ?>
      </div>
      <div class="field col-6">
        <label for="admin_password_confirmation">تأكيد كلمة المرور <span class="req">*</span></label>
        <input class="input input-ltr" id="admin_password_confirmation" name="admin_password_confirmation" type="password" minlength="8" required>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-lg"><?= icon('database') ?>تثبيت</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
