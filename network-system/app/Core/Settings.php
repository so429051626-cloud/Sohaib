<?php
declare(strict_types=1);

namespace App\Core;

final class Settings
{
    /** القيم الافتراضية لكل الإعدادات */
    public const DEFAULTS = [
        // عام
        'general.network_name'        => 'SOHAIB NET',
        'general.logo'                => '',
        'general.currency'            => '₪',
        'general.currency_name'       => 'شيكل',
        'general.timezone'            => 'Asia/Gaza',
        'general.phone'               => '',
        'general.whatsapp'            => '',
        'general.address'             => '',
        'general.panel_url'           => '',
        'ui.sidebar_hidden'           => '1',
        // الأمان
        'security.idle_minutes'       => '120',
        'security.login_max_attempts' => '5',
        'security.login_lock_minutes' => '15',
        // الكروت
        'cards.username_length'       => '8',
        'cards.password_mode'         => 'same',
        'cards.password_length'       => '4',
        'cards.charset'               => 'digits',
        'cards.remove_ended'          => '1',
        // الهوتسبوت
        'hotspot.code_case'           => 'none',
        'hotspot.fail_limit'          => '8',
        'hotspot.fail_window'         => '10',
        'hotspot.lock_minutes'        => '15',
        'hotspot.mac_cookie'          => '1',
        'hotspot.mac_cookie_timeout'  => '1d',
        'hotspot.events'              => '1',
        'hotspot.fallback'            => '1',
        'hotspot.timeout'             => '8',
        'hotspot.allow_unsold'        => '1',
        'hotspot.local_users'         => '1',
        'hotspot.ads_interval'        => '6',
        // كشف الاستهلاك الزائد وتحديد السرعة
        'usage.heavy_enabled'         => '1',
        'usage.heavy_mbps'            => '20',
        'usage.heavy_minutes'         => '3',
        'usage.cap_down'              => '2M',
        'usage.cap_up'                => '1M',
        'usage.cap_minutes'           => '60',
        'usage.auto_cap'              => '0',
        // المزامنة مع أجهزة MikroTik
        'sync.interval'               => '1',
        'sync.leases_interval'        => '5',
        'sync.metrics_interval'       => '5',
        'sync.offline_after'          => '2',
        'sync.api_timeout'            => '5',
        // المشتركون
        'subscribers.expiry_alert_days' => '3',
        'subscribers.grace_hours'       => '0',
        // التنبيهات
        'notify.telegram_enabled'     => '0',
        'notify.telegram_token'       => '',
        'notify.telegram_chat'        => '',
        'notify.router_down'          => '1',
        'notify.router_up'            => '1',
        'notify.low_stock'            => '1',
        'notify.inventory_low'        => '1',
        'notify.expiring'             => '1',
        'notify.cpu_threshold'        => '90',
        'notify.tamper'               => '1',
        'maintenance.sms'             => '1',
        'tamper.cancel_limit'         => '3',
        'tamper.debt_days'            => '14',
        'tamper.debt_min'             => '100',
        'cable.auto'                  => '1',
        'cable.interval_hours'        => '6',
        'cable.errors_min'            => '20',
        'cable.downs_min'             => '5',
        // بوت تيليجرام
        'telegram.commands'           => '1',
        'telegram.report_daily'       => '1',
        'telegram.daily_time'         => '23:00',
        'telegram.ai_summary'         => '1',
        'telegram.report_weekly'      => '1',
        'telegram.weekly_day'         => '6',
        'telegram.weekly_time'        => '22:00',
        'telegram.report_monthly'     => '1',
        'telegram.monthly_time'       => '09:00',
        // الرسائل النصية
        'sms.api_key'                 => '',
        'sms.sender'                  => '',
        'sms.auto_remind'             => '0',
        'sms.remind_days'             => '3',
        'sms.auto_expired'            => '0',
        'sms.auto_renew'              => '0',
        'telegram.customers'          => '1',
        'telegram.customer_token'     => '',
        'telegram.remind'             => '1',
        'telegram.remind_days'        => '3',
        // مراقبة الطاقة والانقطاعات
        'power.interval'              => '5',
        'notify.power'                => '1',
        'outages.auto'                => '1',
        // تعويض المشتركين عن الانقطاعات: مطفأ حتى يفعّله صاحب الشبكة
        'outages.comp_enabled'        => '0',
        'outages.comp_auto'           => '0',
        'outages.comp_min_hours'      => '6',
        'outages.comp_hours_per_day'  => '24',
        'outages.comp_max_days'       => '7',
        // الاحتفاظ بالبيانات
        'retention.power_days'        => '35',
        'retention.metrics_days'      => '30',
        'retention.sessions_days'     => '180',
        'retention.login_log_days'    => '60',
        'retention.audit_days'        => '365',
        'retention.tasks_days'        => '14',
        // النسخ الاحتياطي
        'backup.auto'                 => '1',
        'backup.keep'                 => '14',
        // حالة داخلية
        'cron.last_run'               => '',
        'cron.last_duration'          => '',
        'cron.last_source'            => '',
        'cron.last_daily'             => '',
        'cron.last_backup'            => '',
        'app.schema_version'          => '1',
    ];

    private static ?array $cache = null;

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        if (!Config::installed()) {
            return;
        }
        foreach (DB::rows('SELECT `key`, `value` FROM settings') as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($key, self::$cache) && self::$cache[$key] !== null) {
            return self::$cache[$key];
        }
        return self::DEFAULTS[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return in_array((string) self::get($key, '0'), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::load();
        $value = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
        DB::query(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $value, now()]
        );
        self::$cache[$key] = $value;
    }

    public static function setMany(array $values): void
    {
        DB::transaction(static function () use ($values) {
            foreach ($values as $k => $v) {
                self::set($k, $v);
            }
        });
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function networkName(): string
    {
        $name = trim((string) self::get('general.network_name', ''));
        return $name !== '' ? $name : 'MADIX NEXUS';
    }

    /** رابط اللوحة العام الذي تستخدمه أجهزة MikroTik وصفحات الهوتسبوت */
    public static function panelUrl(): string
    {
        $url = trim((string) self::get('general.panel_url', ''));
        if ($url === '') {
            $url = (string) Config::get('app.url', '');
        }
        if ($url === '') {
            $req = Request::current();
            $url = $req ? $req->origin() . base_path() : '';
        }
        return rtrim($url, '/');
    }
}
