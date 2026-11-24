<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use Throwable;

/**
 * التنبيهات داخل النظام وإرسالها إلى تيليجرام.
 */
final class Notifier
{
    public const TYPES = [
        'router_down'  => 'انقطاع جهاز MikroTik',
        'router_up'    => 'عودة جهاز MikroTik',
        'router_cpu'   => 'حِمل مرتفع على المعالج',
        'router_mem'   => 'ذاكرة منخفضة',
        'power'        => 'الطاقة والبطاريات',
        'outage'       => 'انقطاع',
        'low_stock'    => 'نفاد مخزون باقة',
        'inventory_low' => 'انخفاض مخزون المعدات',
        'expiring'     => 'اشتراكات قاربت على الانتهاء',
        'task_failed'  => 'تعذّر تنفيذ عملية على جهاز MikroTik',
        'job'          => 'عملية مكتملة',
        'backup'       => 'النسخ الاحتياطي',
        'security'     => 'الأمان',
        'tamper'       => 'تنبيهات التلاعب',
        'cable'        => 'خلل في كابل أو منفذ',
        'heavy_usage'  => 'استهلاك زائد',
        'system'       => 'النظام',
        'messages'     => 'رسائل المشتركين',
    ];

    /**
     * إنشاء تنبيه. مع ref يُمنع تكرار التنبيه نفسه خلال $dedupeMinutes.
     */
    public static function alert(
        string $type,
        string $level,
        string $title,
        ?string $message = null,
        ?int $routerId = null,
        ?string $ref = null,
        int $dedupeMinutes = 0,
        bool $telegram = true
    ): ?int {
        try {
            if ($ref !== null && $dedupeMinutes > 0) {
                $exists = DB::value(
                    'SELECT id FROM alerts WHERE ref = ? AND created_at >= ? LIMIT 1',
                    [$ref, date('Y-m-d H:i:s', time() - $dedupeMinutes * 60)]
                );
                if ($exists) {
                    return null;
                }
            }
            $id = DB::insert('alerts', [
                'type'       => $type,
                'level'      => $level,
                'title'      => mb_substr($title, 0, 160),
                'message'    => $message !== null ? mb_substr($message, 0, 500) : null,
                'router_id'  => $routerId,
                'ref'        => $ref,
                'is_read'    => 0,
                'created_at' => now(),
            ]);
            if ($telegram) {
                self::telegram("<b>" . htmlspecialchars($title) . "</b>" . ($message ? "\n" . htmlspecialchars($message) : ''));
            }
            return $id;
        } catch (Throwable $e) {
            log_message('error', 'Notifier failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function telegramEnabled(): bool
    {
        return Telegram::enabled();
    }

    /** @return array{ok:bool, message:string} */
    public static function telegram(string $html, bool $force = false): array
    {
        if (!$force && !self::telegramEnabled()) {
            return ['ok' => false, 'message' => 'إشعارات تيليجرام غير مفعّلة.'];
        }
        return Telegram::broadcast('<b>تنبيه — ' . Telegram::esc(Settings::networkName()) . "</b>\n" . $html);
    }

    public static function levelBadge(string $level): string
    {
        return match ($level) {
            'danger' => 'badge-danger',
            'warning' => 'badge-warning',
            'success' => 'badge-success',
            default => 'badge-info',
        };
    }

    public static function levelIcon(string $level): string
    {
        return match ($level) {
            'danger' => 'circle-x',
            'warning' => 'triangle-alert',
            'success' => 'circle-check',
            default => 'info',
        };
    }
}
