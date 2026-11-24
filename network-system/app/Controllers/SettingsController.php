<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\BackupService;
use App\Services\Notifier;
use Throwable;

final class SettingsController extends Controller
{
    /** حقول كل قسم وقواعد التحقق */
    private const SECTIONS = [
        'general' => [
            'general.network_name'  => ['required|max:100', 'اسم الشبكة'],
            'general.currency'      => ['nullable|max:8', 'رمز العملة'],
            'general.currency_name' => ['nullable|max:30', 'اسم العملة'],
            'general.timezone'      => ['required|max:64', 'المنطقة الزمنية'],
            'general.phone'         => ['nullable|max:30', 'الهاتف'],
            'general.whatsapp'      => ['nullable|max:30', 'واتساب'],
            'general.address'       => ['nullable|max:200', 'العنوان'],
            'general.panel_url'     => ['nullable|max:255|regex:#^https?://#i', 'الرابط العام للنظام'],
            'ui.sidebar_hidden'     => ['bool', 'إخفاء القائمة الجانبية'],
            'map.google_key'        => ['nullable|max:120|regex:/^[A-Za-z0-9_\-]*$/', 'مفتاح خرائط Google'],
        ],
        'security' => [
            'security.idle_minutes'       => ['required|int|between:5,1440', 'مهلة الخمول'],
            'security.login_max_attempts' => ['required|int|between:3,20', 'محاولات الدخول'],
            'security.login_lock_minutes' => ['required|int|between:1,1440', 'مدة القفل'],
            'security.require_2fa'        => ['bool', 'فرض التحقق بخطوتين'],
        ],
        'sync' => [
            'sync.interval'         => ['required|int|between:1,60', 'فترة المزامنة'],
            'sync.leases_interval'  => ['required|int|between:1,120', 'فترة جلب أسماء الأجهزة'],
            'sync.metrics_interval' => ['required|int|between:1,120', 'فترة حفظ القياسات'],
            'sync.offline_after'    => ['required|int|between:1,20', 'عدد المحاولات قبل اعتبار جهاز MikroTik منقطعاً'],
            'sync.api_timeout'      => ['required|int|between:2,30', 'مهلة اتصال API'],
        ],
        'notify' => [
            'notify.router_down'      => ['bool', 'انقطاع جهاز MikroTik'],
            'notify.router_up'        => ['bool', 'عودة جهاز MikroTik'],
            'notify.low_stock'        => ['bool', 'نفاد المخزون'],
            'notify.inventory_low'    => ['bool', 'انخفاض مخزون المعدات'],
            'notify.expiring'         => ['bool', 'الاشتراكات المنتهية قريباً'],
            'notify.cpu_threshold'    => ['required|int|between:50,100', 'حد حِمل المعالج'],
            'notify.tamper'           => ['bool', 'تنبيهات التلاعب'],
            'maintenance.sms'         => ['bool', 'رسالة للفنّي عند إسناد مهمة'],
            'tamper.cancel_limit'     => ['required|int|between:2,100', 'عدد الإلغاءات'],
            'tamper.debt_days'        => ['required|int|between:3,180', 'مدة عدم السداد'],
            'tamper.debt_min'         => ['required|numeric|between:0,10000000', 'حد الدين'],
        ],
        'retention' => [
            'retention.metrics_days'   => ['required|int|between:1,365', 'قياسات أجهزة MikroTik'],
            'retention.sessions_days'  => ['required|int|between:7,3650', 'سجل الجلسات'],
            'retention.login_log_days' => ['required|int|between:1,365', 'سجل محاولات الدخول'],
            'retention.audit_days'     => ['required|int|between:30,3650', 'سجل العمليات'],
            'retention.tasks_days'     => ['required|int|between:1,90', 'سجل مهام أجهزة MikroTik'],
        ],
        'backup' => [
            'backup.auto' => ['bool', 'النسخ التلقائي اليومي'],
            'backup.keep' => ['required|int|between:1,90', 'عدد النسخ المحفوظة'],
        ],
    ];

    public function index(Request $request): Response
    {
        $tab = $request->str('tab', 'general');
        $moved = ['cards' => '/cards/settings', 'subscribers' => '/subscribers/settings', 'hotspot' => '/hotspot/settings', 'telegram' => '/telegram'];
        if (isset($moved[$tab])) {
            return redirect($moved[$tab]);
        }
        if ((!isset(self::SECTIONS[$tab]) && $tab !== 'cron') || ($tab === 'cron' && \App\Services\CronService::managed())) {
            $tab = 'general';
        }
        return view('settings/index', [
            'tab'      => $tab,
            'backups'  => can('backup.manage') ? BackupService::list() : [],
            'cronKey'  => (string) Config::get('app.cron_key', ''),
            'cronPath' => BASE_PATH . '/cli/cron.php',
        ]);
    }

    public function update(Request $request): Response
    {
        $section = $request->str('section');
        if (!isset(self::SECTIONS[$section])) {
            throw new HttpException(400);
        }
        $fields = self::SECTIONS[$section];
        if (\App\Core\Platform::enabled()) {
            // في المنصة يحدد المسؤول الرابط العام لكل حساب (صفحة الهوتسبوت وأجهزة MikroTik وتيليجرام تعتمد عليه)
            unset($fields['general.panel_url']);
        }
        $input = $this->settingsInput($request, $fields, function ($v) use ($request, $section) {
            if ($section === 'general' && !in_array($request->str('general__timezone'), timezone_identifiers_list(), true)) {
                $v->addError('general__timezone', 'المنطقة الزمنية غير صالحة.');
            }
        });
        if ($section === 'general') {
            if (isset($input['general.panel_url'])) {
                $input['general.panel_url'] = rtrim($input['general.panel_url'], '/');
            }
            if ($err = $request->uploadError('logo')) {
                return $this->backWithErrors($request, ['logo' => $err], '/settings');
            }
            $logo = $request->file('logo');
            if ($logo) {
                $saved = $this->saveImage($logo, 'logo');
                if ($saved === null) {
                    return $this->backWithErrors($request, ['logo' => 'الشعار يجب أن يكون صورة PNG أو JPG أو WEBP بحجم لا يتجاوز 1 ميغابايت.'], '/settings');
                }
                $old = (string) Settings::get('general.logo', '');
                if ($old !== '' && str_starts_with($old, 'uploads/')) {
                    @unlink(PUBLIC_PATH . '/' . $old);
                }
                $input['general.logo'] = $saved;
            } elseif ($request->bool('remove_logo')) {
                $old = (string) Settings::get('general.logo', '');
                if ($old !== '' && str_starts_with($old, 'uploads/')) {
                    @unlink(PUBLIC_PATH . '/' . $old);
                }
                $input['general.logo'] = '';
            }
        }
        Settings::setMany($input);
        $safe = $input;
        unset($safe['notify.telegram_token']);
        Audit::log('settings.update', 'تعديل الإعدادات: ' . $section, 'settings', null, $safe);
        return $this->ok('تم حفظ الإعدادات.', '/settings', ['tab' => $section]);
    }

    /** حفظ صورة مرفوعة في public/uploads وإرجاع المسار النسبي */
    public static function saveImage(array $file, string $prefix, int $maxBytes = 1048576): ?string
    {
        if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
            return null;
        }
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]])) {
            return null;
        }
        [$dir, $rel] = upload_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            return null;
        }
        @chmod($dir . '/' . $name, 0644);
        return $rel . '/' . $name;
    }

    // ---------------------------------------------------------------------
    // النسخ الاحتياطي
    // ---------------------------------------------------------------------

    public function backupCreate(Request $request): Response
    {
        try {
            $name = BackupService::create(true);
        } catch (Throwable $e) {
            flash('error', 'تعذّر إنشاء النسخة: ' . $e->getMessage());
            return redirect('/settings', ['tab' => 'backup']);
        }
        BackupService::prune(max(Settings::int('backup.keep', 14), 1) + 5);
        Audit::log('backup.download', 'إنشاء نسخة احتياطية ' . $name, 'backup');
        return $this->ok('تم إنشاء النسخة الاحتياطية.', '/settings', ['tab' => 'backup']);
    }

    public function backupDownload(Request $request): Response
    {
        $path = BackupService::path($request->str('file'));
        $this->notFoundUnless($path);
        Audit::log('backup.download', 'تنزيل نسخة احتياطية ' . basename($path), 'backup');
        return Response::download((string) file_get_contents($path), basename($path), 'application/gzip');
    }

    public function backupDelete(Request $request): Response
    {
        $path = BackupService::path($request->str('file'));
        $this->notFoundUnless($path);
        @unlink($path);
        return $this->ok('تم حذف النسخة.', '/settings', ['tab' => 'backup']);
    }

    public function backupRestore(Request $request): Response
    {
        if (!Auth::isOwner()) {
            throw new HttpException(403, 'الاستعادة متاحة لحساب المالك فقط.');
        }
        if ($request->str('confirm') !== 'استعادة') {
            return $this->backWithErrors($request, ['confirm' => 'اكتب كلمة «استعادة» للتأكيد.'], '/settings?tab=backup');
        }
        if ($err = $request->uploadError('backup')) {
            return $this->backWithErrors($request, ['backup' => $err], '/settings?tab=backup');
        }
        $file = $request->file('backup');
        $source = null;
        if ($file) {
            $source = $file['tmp_name'];
        } elseif ($request->str('file') !== '') {
            $source = BackupService::path($request->str('file'));
        }
        if (!$source) {
            return $this->backWithErrors($request, ['backup' => 'اختر ملف النسخة الاحتياطية.'], '/settings?tab=backup');
        }
        // ملف إعداد النظام السابق (اختياري): يُقرأ منه مفتاح التشفير لتبقى كلمات مرور أجهزة MikroTik صالحة
        $oldKey = null;
        $config = $request->file('old_config');
        if ($config) {
            $oldKey = BackupService::keyFromConfig((string) @file_get_contents($config['tmp_name'], false, null, 0, 200000));
            if ($oldKey === null) {
                return $this->backWithErrors($request, ['old_config' => 'ملف الإعداد لا يحتوي على مفتاح النظام.'], '/settings?tab=backup');
            }
        }
        $panelUrl = Settings::panelUrl();
        try {
            // نسخة أمان قبل الاستعادة
            BackupService::create(true);
            BackupService::restore($source);
        } catch (Throwable $e) {
            flash('error', 'فشلت الاستعادة: ' . $e->getMessage());
            return redirect('/settings', ['tab' => 'backup']);
        }
        Settings::flush();
        try {
            \App\Core\Migrator::reset();
            \App\Core\Migrator::run();
            if ($oldKey !== null) {
                BackupService::rekey($oldKey);
            }
            if ($file) {
                // نسخة مرفوعة قد تكون من عنوان آخر: الرابط العام يتبع هذا النظام
                Settings::set('general.panel_url', $panelUrl);
            }
        } catch (Throwable $e) {
            log_message('error', 'restore finish: ' . $e->getMessage());
        }
        Audit::log('backup.restore', 'استعادة نسخة احتياطية', 'backup');
        if (BackupService::secretsReadable() === false) {
            flash('warning', 'تمت الاستعادة. كلمات مرور أجهزة MikroTik مشفّرة بمفتاح نظام آخر.');
            return redirect('/settings', ['tab' => 'backup']);
        }
        return $this->ok('تمت الاستعادة بنجاح.', '/settings', ['tab' => 'backup']);
    }
}
