<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

/**
 * قواعد صلاحية المشترك.
 */
final class SubscriberRules
{
    public static function graceSeconds(): int
    {
        return max(0, Settings::int('subscribers.grace_hours', 0)) * 3600;
    }

    public static function isExpired(array $sub, ?int $now = null): bool
    {
        $now ??= time();
        if (!$sub['expires_at']) {
            return false;
        }
        return (int) strtotime($sub['expires_at']) + self::graceSeconds() <= $now;
    }

    /** سبب منع المشترك من الاتصال (null = مسموح) */
    public static function blockReason(array $sub, array $plan, ?int $now = null): ?string
    {
        if ($sub['status'] === 'suspended') {
            return 'sub_suspended';
        }
        if (!$sub['expires_at'] || self::isExpired($sub, $now)) {
            return 'sub_expired';
        }
        if ($plan['data_limit'] && (int) $sub['period_bytes'] >= (int) $plan['data_limit'] && ProfileManager::fupRate($plan) === null) {
            return 'sub_quota';
        }
        return null;
    }

    /** الحالة المعروضة: active · expiring · expired · suspended · new */
    public static function displayState(array $sub, ?int $now = null): string
    {
        $now ??= time();
        if ($sub['status'] === 'suspended') {
            return 'suspended';
        }
        if (!$sub['expires_at']) {
            return 'new';
        }
        $left = (int) strtotime($sub['expires_at']) - $now;
        if ($left <= 0) {
            return 'expired';
        }
        if ($left <= max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400) {
            return 'expiring';
        }
        return 'active';
    }

    public static function badge(array $sub): string
    {
        return match (self::displayState($sub)) {
            'suspended' => '<span class="badge badge-danger">' . icon('ban') . 'موقوف</span>',
            'expired'   => '<span class="badge badge-danger badge-dot">منتهي</span>',
            'expiring'  => '<span class="badge badge-warning badge-dot">ينتهي قريباً</span>',
            'new'       => '<span class="badge badge-info badge-dot">بانتظار التفعيل</span>',
            default     => '<span class="badge badge-success badge-dot">فعّال</span>',
        };
    }

    public static function message(string $reason): string
    {
        return CardRules::message($reason);
    }
}
