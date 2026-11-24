<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * سياق الزبون الحالي في وضع المنصة: قاعدة البيانات والمفاتيح والمسارات والجلسات منفصلة لكل زبون.
 */
final class Tenant
{
    private static ?array $current = null;

    /** حالات تسمح باستخدام النظام */
    public const USABLE = ['trial', 'active'];

    public static function current(): ?array
    {
        return self::$current;
    }

    public static function slug(): ?string
    {
        return self::$current['slug'] ?? null;
    }

    public static function id(): ?int
    {
        return isset(self::$current['id']) ? (int) self::$current['id'] : null;
    }

    /**
     * هل بلغ الحساب الحد الذي حدّده مسؤول المنصة (null = بلا حد).
     * @param string $column max_routers | max_subscribers
     */
    public static function limitReached(string $column, string $countSql): bool
    {
        $max = self::$current[$column] ?? null;
        if ($max === null || $max === '') {
            return false;
        }
        return (int) DB::value($countSql) >= (int) $max;
    }

    /** إعداد قاعدة بيانات الزبون انطلاقاً من إعداد المنصة */
    public static function dbConfig(array $tenant): array
    {
        $t = Platform::tenantDb();
        return [
            'host' => $t['host'], 'port' => (int) $t['port'], 'name' => $tenant['db_name'],
            'user' => $t['user'], 'pass' => $t['pass'], 'charset' => 'utf8mb4',
        ];
    }

    /** الدخول في سياق زبون: كل ما يلي يعمل على قاعدته وملفاته */
    public static function enter(array $tenant): void
    {
        self::$current = $tenant;
        Config::set('db', self::dbConfig($tenant));
        Config::set('app.key', $tenant['app_key']);
        Config::set('app.cron_key', $tenant['cron_key']);
        Config::set('app.url', Platform::rootUrl() . '/' . $tenant['slug']);
        self::reset();
        try {
            $tz = Settings::get('general.timezone');
            if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
                date_default_timezone_set($tz);
                DB::syncTimezone();
            }
        } catch (\Throwable) {
            // قاعدة الزبون غير جاهزة بعد
        }
    }

    /** العودة إلى سياق المنصة */
    public static function leave(): void
    {
        if (self::$current === null) {
            return;
        }
        self::$current = null;
        Config::set('db', Platform::rootDb());
        Config::set('app.key', Platform::rootKey());
        Config::set('app.cron_key', Platform::cronKey());
        Config::set('app.url', Platform::rootUrl());
        self::reset();
    }

    private static function reset(): void
    {
        DB::reconnect();
        Settings::flush();
        Auth::reset();
        Migrator::reset();
        base_path(true);
    }

    /** اتصال مستقل بقاعدة زبون (دون تغيير السياق) */
    public static function pdo(array $tenant): PDO
    {
        $c = self::dbConfig($tenant);
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']), $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        return $pdo;
    }

    /**
     * مرحلة الحساب في مسار الاشتراك:
     * ok (سارٍ) ← grace (فترة سماح بعد الانتهاء) ← readonly (قراءة فقط) ← blocked (موقوف).
     */
    public static function phase(array $tenant, ?int $now = null): string
    {
        $now ??= time();
        if (!in_array($tenant['status'], self::USABLE, true)) {
            return 'blocked';
        }
        $end = $tenant['status'] === 'trial' ? $tenant['trial_ends_at'] : $tenant['paid_until'];
        if ($end === null) {
            return $tenant['status'] === 'trial' ? 'blocked' : 'ok';
        }
        $over = $now - (int) strtotime($end);
        if ($over < 0) {
            return 'ok';
        }
        [$grace, $readonly] = Platform::billingWindows();
        if ($over < $grace) {
            return 'grace';
        }
        return $over < $grace + $readonly ? 'readonly' : 'blocked';
    }

    /** نهاية المرحلة الحالية بعد انتهاء المدة (نهاية السماح أو نهاية القراءة فقط) */
    public static function phaseEnds(array $tenant, ?int $now = null): ?int
    {
        $end = $tenant['status'] === 'trial' ? $tenant['trial_ends_at'] : $tenant['paid_until'];
        if ($end === null) {
            return null;
        }
        [$grace, $readonly] = Platform::billingWindows();
        return match (self::phase($tenant, $now)) {
            'grace' => (int) strtotime($end) + $grace,
            'readonly' => (int) strtotime($end) + $grace + $readonly,
            default => (int) strtotime($end),
        };
    }

    /** هل الحساب صالح للاستخدام الآن (يشمل فترتي السماح والقراءة فقط) */
    public static function usable(array $tenant, ?int $now = null): bool
    {
        return self::phase($tenant, $now) !== 'blocked';
    }

    /** الطلبات التي تبقى مسموحة في وضع القراءة فقط */
    private const READONLY_POST = ['/login', '/login/device', '/login/verify', '/logout', '/impersonation/exit', '/account/theme', '/billing', '/support'];

    /** هل يُمنع هذا الطلب لأن الحساب في وضع القراءة فقط */
    public static function readonlyBlocks(array $tenant, string $method, string $path): bool
    {
        if ($method !== 'POST' || self::phase($tenant) !== 'readonly') {
            return false;
        }
        foreach (self::READONLY_POST as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return false;
            }
        }
        if (str_starts_with($path, '/api/app/')) {
            return !preg_match('#^/api/app/v\d+/(login|logout)$#', $path);
        }
        // واجهات الهوتسبوت وأحداث الأجهزة وبوت تيليجرام تبقى تعمل ليستمر تشغيل الشبكة
        return !(Kernel::isStateless($path) || str_starts_with($path, '/telegram/'));
    }

    /** شبكات المالك نفسه الأخرى (المرتبطة بهذا الحساب من لوحة المسؤول) */
    public static function siblings(?array $tenant = null): array
    {
        $tenant ??= self::$current;
        if ($tenant === null || empty($tenant['group_id'])) {
            return [];
        }
        try {
            return Platform::rows('SELECT id, name, slug, status, trial_ends_at, paid_until FROM tenants WHERE group_id = ? AND id <> ? ORDER BY name', [$tenant['group_id'], $tenant['id']]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** الحد الشهري لرسائل الذكاء الاصطناعي: حد الحساب، ثم حد باقته، ثم الحد الافتراضي للمنصة (50 ما لم يغيّره المسؤول) */
    public static function aiLimit(array $tenant): int
    {
        if (isset($tenant['ai_limit']) && $tenant['ai_limit'] !== null && $tenant['ai_limit'] !== '') {
            return (int) $tenant['ai_limit'];
        }
        $plan = Platform::plan(isset($tenant['plan_id']) ? (int) $tenant['plan_id'] : null);
        if ($plan && $plan['ai_messages'] !== null) {
            return (int) $plan['ai_messages'];
        }
        return self::aiDefaultLimit();
    }

    public static function aiDefaultLimit(): int
    {
        return max(0, (int) Platform::setting('ai.default_limit'));
    }

    /** الأيام المتبقية من التجربة أو الاشتراك (null = مفتوح) */
    public static function daysLeft(array $tenant, ?int $now = null): ?int
    {
        $now ??= time();
        $end = $tenant['status'] === 'trial' ? $tenant['trial_ends_at'] : $tenant['paid_until'];
        if ($end === null) {
            return null;
        }
        return max(0, (int) ceil((strtotime($end) - $now) / 86400));
    }
}
