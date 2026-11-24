<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Platform;
use App\Core\Settings;
use App\Core\Tenant;

/**
 * الكوكيز (الدخول التلقائي للجهاز المعروف MAC Cookie) على أجهزة MikroTik.
 *
 * ترتيب الأولوية:
 *   1) ما يعيّنه مسؤول المنصة لحساب معيّن (تفعيل بمدة، أو إيقاف)
 *   2) سياسة المنصة العامة لكل الحسابات (تفعيل بمدة، أو إيقاف)
 *   3) إعداد الشبكة نفسها (الإعدادات → الهوتسبوت)
 *
 * أي تغيير في القيمة الفعلية يُطبَّق على ملفات السرعة في أجهزة MikroTik تلقائياً من المهمة الدورية،
 * وعند الإيقاف تُحذف الكوكيز المحفوظة على الأجهزة فيتوقف الدخول التلقائي فوراً.
 */
final class CookiePolicy
{
    /** سياسة المنصة العامة */
    public const MODES = [
        'tenant' => 'كل شبكة تتحكم بها من إعداداتها',
        'on'     => 'تفعيل لكل الشبكات بمدة محددة',
        'off'    => 'إيقاف لكل الشبكات',
    ];

    /** تعيين حساب واحد */
    public const TENANT_MODES = [
        ''    => 'حسب سياسة المنصة',
        'on'  => 'تفعيل بمدة محددة',
        'off' => 'إيقاف الكوكيز',
    ];

    /** المدد الجاهزة (صيغة RouterOS) */
    public const DURATIONS = [
        '30m' => '30 دقيقة',
        '1h'  => 'ساعة',
        '3h'  => '3 ساعات',
        '6h'  => '6 ساعات',
        '12h' => '12 ساعة',
        '1d'  => 'يوم',
        '2d'  => 'يومان',
        '3d'  => '3 أيام',
        '1w'  => 'أسبوع',
        '2w'  => 'أسبوعان',
        '30d' => '30 يوماً',
    ];

    public const DEFAULT_DURATION = '1d';

    /** المدة بصيغة RouterOS صالحة؟ (رقم ثم m أو h أو d أو w، من دقيقة حتى 365 يوماً) */
    public static function validDuration(string $d): bool
    {
        if (!preg_match('/^([1-9]\d{0,4})([mhdw])$/', $d, $m)) {
            return false;
        }
        $minutes = (int) $m[1] * ['m' => 1, 'h' => 60, 'd' => 1440, 'w' => 10080][$m[2]];
        return $minutes >= 1 && $minutes <= 525600;
    }

    public static function durationLabel(string $d): string
    {
        if (isset(self::DURATIONS[$d])) {
            return self::DURATIONS[$d];
        }
        if (!preg_match('/^(\d+)([mhdw])$/', $d, $m)) {
            return $d;
        }
        return $m[1] . ' ' . ['m' => 'دقيقة', 'h' => 'ساعة', 'd' => 'يوم', 'w' => 'أسبوع'][$m[2]];
    }

    /** سياسة المنصة العامة: ['mode' => tenant|on|off, 'duration' => ...] */
    public static function platform(): array
    {
        $mode = Platform::setting('cookies.mode', 'tenant');
        $duration = Platform::setting('cookies.duration', self::DEFAULT_DURATION);
        return [
            'mode'     => isset(self::MODES[$mode]) ? $mode : 'tenant',
            'duration' => self::validDuration($duration) ? $duration : self::DEFAULT_DURATION,
        ];
    }

    /** تعيين حساب واحد: ['mode' => ''|on|off, 'duration' => ...] */
    public static function forTenant(int $tenantId): array
    {
        $raw = Platform::setting('cookies.tenant.' . $tenantId, '');
        [$mode, $duration] = array_pad(explode(':', $raw, 2), 2, '');
        if (!in_array($mode, ['on', 'off'], true)) {
            return ['mode' => '', 'duration' => self::DEFAULT_DURATION];
        }
        return ['mode' => $mode, 'duration' => self::validDuration($duration) ? $duration : self::DEFAULT_DURATION];
    }

    public static function setTenant(int $tenantId, string $mode, string $duration): void
    {
        $value = in_array($mode, ['on', 'off'], true) ? $mode . ':' . (self::validDuration($duration) ? $duration : self::DEFAULT_DURATION) : '';
        Platform::setSettings(['cookies.tenant.' . $tenantId => $value]);
    }

    /**
     * القيمة المفروضة من مسؤول المنصة على الشبكة الحالية، أو null إن كانت الشبكة تتحكم بنفسها.
     * @return array{enabled: bool, duration: string, source: string}|null
     */
    public static function forced(?int $tenantId = null): ?array
    {
        try {
            if (!Platform::enabled()) {
                return null;
            }
            $tenantId ??= Tenant::id();
            if ($tenantId) {
                $own = self::forTenant((int) $tenantId);
                if ($own['mode'] !== '') {
                    return ['enabled' => $own['mode'] === 'on', 'duration' => $own['duration'], 'source' => 'tenant'];
                }
            }
            $p = self::platform();
            if ($p['mode'] !== 'tenant') {
                return ['enabled' => $p['mode'] === 'on', 'duration' => $p['duration'], 'source' => 'platform'];
            }
        } catch (\Throwable) {
            // المنصة غير جاهزة: إعداد الشبكة هو المرجع
        }
        return null;
    }

    /** هل الدخول التلقائي بالكوكيز مفعّل فعلياً لهذه الشبكة؟ */
    public static function enabled(): bool
    {
        $f = self::forced();
        return $f !== null ? $f['enabled'] : Settings::bool('hotspot.mac_cookie');
    }

    /** مدة الكوكيز الفعلية بصيغة RouterOS */
    public static function duration(): string
    {
        $f = self::forced();
        if ($f !== null) {
            return $f['duration'];
        }
        $d = (string) Settings::get('hotspot.mac_cookie_timeout', self::DEFAULT_DURATION);
        return self::validDuration($d) ? $d : self::DEFAULT_DURATION;
    }

    /** بصمة القيمة الفعلية؛ تغيّرها يعني وجوب التطبيق على الأجهزة */
    public static function signature(): string
    {
        return (self::enabled() ? 'on' : 'off') . ':' . self::duration();
    }

    /**
     * من المهمة الدورية لكل شبكة: إن تغيّرت القيمة الفعلية منذ آخر تطبيق تُنشأ عملية
     * تحدّث ملفات السرعة على كل أجهزة MikroTik (وتحذف الكوكيز المحفوظة عند الإيقاف أو تقصير المدة).
     */
    public static function syncIfChanged(): void
    {
        $now = self::signature();
        $applied = (string) Settings::get('hotspot.cookie_applied', '');
        if ($applied === $now) {
            return;
        }
        if ($applied === '') {
            // أول تشغيل بعد التحديث: ملفات الأجهزة مبنية على إعداد الشبكة نفسه، فلا داعي لعملية
            Settings::set('hotspot.cookie_applied', $now);
            if (self::forced() === null) {
                return;
            }
        } else {
            Settings::set('hotspot.cookie_applied', $now);
        }
        if (!DB::value('SELECT id FROM routers WHERE is_active = 1 LIMIT 1')) {
            return;
        }
        [$oldState, $oldDur] = array_pad(explode(':', $applied, 2), 2, '');
        $clear = !self::enabled() || ($oldState === 'on' && self::minutes($oldDur) > self::minutes(self::duration()));
        // عملية واحدة فقط بالانتظار؛ الأحدث يحلّ محل السابقة
        DB::query("UPDATE jobs SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE type = 'cookie_sync' AND status IN ('pending','running')", [now(), now()]);
        JobRunner::create(
            'cookie_sync',
            self::enabled() ? 'تطبيق الكوكيز (' . self::durationLabel(self::duration()) . ') على أجهزة MikroTik' : 'إيقاف الكوكيز على أجهزة MikroTik',
            ['enabled' => self::enabled(), 'clear' => $clear],
            0,
            null,
            false
        );
    }

    private static function minutes(string $d): int
    {
        if (!preg_match('/^(\d+)([mhdw])$/', $d, $m)) {
            return 0;
        }
        return (int) $m[1] * ['m' => 1, 'h' => 60, 'd' => 1440, 'w' => 10080][$m[2]];
    }
}
