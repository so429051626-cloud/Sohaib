<?php
declare(strict_types=1);

namespace App\Services;

/**
 * قواعد صلاحية الكرت: الحدود الفعلية، المتبقي، وأسباب الرفض.
 */
final class CardRules
{
    public const STATUS = [
        'new'      => ['جديد', 'badge-info'],
        'sold'     => ['مباع', 'badge-violet'],
        'active'   => ['نشط', 'badge-success'],
        'expired'  => ['منتهي', 'badge-danger'],
        'depleted' => ['مستنفد', 'badge-warning'],
    ];

    public const REASONS = [
        'not_found'      => 'رقم الكرت غير صحيح.',
        'wrong_password' => 'كلمة المرور غير صحيحة.',
        'need_password'  => 'أدخل كلمة المرور.',
        'disabled'       => 'هذا الكرت موقوف.',
        'expired'        => 'انتهت صلاحية الكرت.',
        'time_depleted'  => 'انتهى رصيد الوقت في الكرت.',
        'data_depleted'  => 'انتهى رصيد البيانات في الكرت.',
        'mac_mismatch'   => 'هذا الكرت مرتبط بجهاز آخر.',
        'in_use'         => 'الكرت مستخدم حالياً على جهاز آخر.',
        'not_allowed'    => 'هذا الكرت غير صالح على نقطة الاتصال هذه.',
        'unsold'         => 'هذا الكرت غير مفعّل بعد.',
        'blocked'        => 'هذا الجهاز محظور من استخدام الشبكة.',
        'locked'         => 'تم إيقاف المحاولات مؤقتاً بسبب كثرة الأخطاء.',
        'sub_suspended'  => 'الاشتراك موقوف.',
        'sub_expired'    => 'انتهى الاشتراك.',
        'sub_quota'      => 'انتهت باقة البيانات للاشتراك.',
        'wrong_service'  => 'هذا الحساب غير مخصص لخدمة الهوتسبوت.',
        'wrong_router'   => 'هذا الاشتراك غير مفعّل على نقطة الاتصال هذه.',
        'router'         => 'تعذّر التعرف على نقطة الاتصال.',
        'unavailable'    => 'تعذّر إتمام الدخول حالياً. حاول مجدداً.',
        'rate'           => 'طلبات كثيرة. انتظر قليلاً ثم حاول مجدداً.',
        'invalid'        => 'أدخل رقم الكرت.',
        'local'          => 'مستخدم محلي على جهاز MikroTik.',
    ];

    /** @return array{0:?int,1:?int,2:?int} [الوقت، البيانات، الصلاحية] — null = غير محدود */
    public static function limits(array $card, array $category): array
    {
        $time = $category['time_limit'] !== null ? (int) $category['time_limit'] + (int) $card['bonus_time'] : null;
        $data = $category['data_limit'] !== null ? (int) $category['data_limit'] + (int) $card['bonus_data'] : null;
        $validity = $category['validity'] !== null ? (int) $category['validity'] + (int) $card['bonus_validity'] : null;
        return [$time, $data, $validity];
    }

    public static function usedBytes(array $card): int
    {
        return (int) $card['used_bytes_in'] + (int) $card['used_bytes_out'];
    }

    /**
     * المتبقي من الكرت.
     * @return array{time:?int, data:?int, until_expiry:?int, expires_at:?string}
     */
    public static function remaining(array $card, array $category, ?int $now = null): array
    {
        $now ??= time();
        [$time, $data, $validity] = self::limits($card, $category);
        $expiresAt = $card['expires_at'] ?? null;
        $until = null;
        if ($expiresAt) {
            $until = (int) strtotime($expiresAt) - $now;
        } elseif ($validity !== null) {
            $until = $validity;
        }
        return [
            'time'         => $time !== null ? $time - (int) $card['used_seconds'] : null,
            'data'         => $data !== null ? $data - self::usedBytes($card) : null,
            'until_expiry' => $until,
            'expires_at'   => $expiresAt,
        ];
    }

    /**
     * سبب عدم صلاحية الكرت للاستخدام الآن (null = صالح).
     */
    public static function blockReason(array $card, array $category, ?int $now = null): ?string
    {
        if ((int) $card['is_disabled'] === 1) {
            return 'disabled';
        }
        $r = self::remaining($card, $category, $now);
        if ($card['expires_at'] && $r['until_expiry'] !== null && $r['until_expiry'] <= 0) {
            return 'expired';
        }
        if ($r['time'] !== null && $r['time'] <= 0) {
            return 'time_depleted';
        }
        if ($r['data'] !== null && $r['data'] <= 0) {
            return 'data_depleted';
        }
        return null;
    }

    /** الحالة الدورية المناسبة بعد تغيّر الاستهلاك أو التمديد */
    public static function lifecycleStatus(array $card, array $category, ?int $now = null): string
    {
        $reason = self::blockReason(['is_disabled' => 0] + $card, $category, $now);
        if ($reason === 'expired') {
            return 'expired';
        }
        if ($reason === 'time_depleted' || $reason === 'data_depleted') {
            return 'depleted';
        }
        if ($card['first_login_at']) {
            return 'active';
        }
        return $card['sale_id'] || $card['distributor_id'] || $card['sold_at'] ? 'sold' : 'new';
    }

    /**
     * حدود مستخدم جهاز MikroTik: نافذة الوقت والبيانات المتبقية بعد إضافة عدّاد جهاز MikroTik الحالي.
     * @return array{limit_uptime:int, limit_bytes:int} 0 = بلا حد
     */
    public static function routerLimits(array $card, array $category, int $routerUptime, int $routerBytes, ?int $now = null): array
    {
        $r = self::remaining($card, $category, $now);
        $window = null;
        foreach ([$r['time'], $r['until_expiry']] as $v) {
            if ($v !== null) {
                $window = $window === null ? $v : min($window, $v);
            }
        }
        return [
            'limit_uptime' => $window !== null ? $routerUptime + max(1, $window) : 0,
            'limit_bytes'  => $r['data'] !== null ? $routerBytes + max(1, $r['data']) : 0,
        ];
    }

    public static function statusBadge(array $card): string
    {
        [$label, $class] = self::STATUS[$card['status']] ?? [$card['status'], 'badge-outline'];
        $html = '<span class="badge ' . $class . '">' . e($label) . '</span>';
        if ((int) ($card['is_disabled'] ?? 0) === 1) {
            $html .= ' <span class="badge badge-danger">' . icon('ban') . 'موقوف</span>';
        }
        return $html;
    }

    public static function message(string $reason): string
    {
        return self::REASONS[$reason] ?? self::REASONS['unavailable'];
    }
}
