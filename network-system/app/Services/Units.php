<?php
declare(strict_types=1);

namespace App\Services;

/**
 * تحويل وحدات الإدخال (المدة، الحجم، السرعة) بين النماذج وقاعدة البيانات.
 */
final class Units
{
    public const TIME_UNITS = ['minute' => ['دقيقة', 60], 'hour' => ['ساعة', 3600], 'day' => ['يوم', 86400], 'month' => ['شهر (30 يوماً)', 2592000]];
    public const DATA_UNITS = ['MB' => ['ميغابايت', 1048576], 'GB' => ['غيغابايت', 1073741824]];

    public static function toSeconds(mixed $value, string $unit): ?int
    {
        if ($value === null || $value === '' || !is_numeric(latin_digits((string) $value))) {
            return null;
        }
        $n = (float) latin_digits((string) $value);
        if ($n <= 0) {
            return null;
        }
        return (int) round($n * (self::TIME_UNITS[$unit][1] ?? 60));
    }

    public static function toBytes(mixed $value, string $unit): ?int
    {
        if ($value === null || $value === '' || !is_numeric(latin_digits((string) $value))) {
            return null;
        }
        $n = (float) latin_digits((string) $value);
        if ($n <= 0) {
            return null;
        }
        return (int) round($n * (self::DATA_UNITS[$unit][1] ?? 1048576));
    }

    /** تفكيك ثوانٍ إلى [قيمة، وحدة] لعرضها في النموذج */
    public static function splitSeconds(?int $seconds): array
    {
        if (!$seconds) {
            return ['', 'hour'];
        }
        foreach (['month', 'day', 'hour', 'minute'] as $u) {
            $size = self::TIME_UNITS[$u][1];
            if ($seconds % $size === 0) {
                return [(string) intdiv($seconds, $size), $u];
            }
        }
        return [(string) round($seconds / 60), 'minute'];
    }

    public static function splitBytes(?int $bytes): array
    {
        if (!$bytes) {
            return ['', 'GB'];
        }
        if ($bytes % self::DATA_UNITS['GB'][1] === 0) {
            return [(string) intdiv($bytes, self::DATA_UNITS['GB'][1]), 'GB'];
        }
        return [(string) round($bytes / self::DATA_UNITS['MB'][1], 2), 'MB'];
    }

    /** وصف مقروء لمدة الكرت */
    public static function describeSeconds(?int $seconds): string
    {
        if (!$seconds) {
            return 'غير محدود';
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        $parts = [];
        if ($d) {
            $parts[] = ar_plural($d, 'يوم', 'يومان', 'أيام', 'يوماً');
        }
        if ($h) {
            $parts[] = ar_plural($h, 'ساعة', 'ساعتان', 'ساعات', 'ساعة');
        }
        if ($m) {
            $parts[] = ar_plural($m, 'دقيقة', 'دقيقتان', 'دقائق', 'دقيقة');
        }
        return implode(' و', $parts);
    }

    public static function describeBytes(?int $bytes): string
    {
        return $bytes ? fmt_bytes($bytes) : 'غير محدود';
    }

    /** تحويل سرعة مثل 2M أو 512k إلى بت/ثانية */
    public static function rateToBps(?string $rate): int
    {
        if ($rate === null || $rate === '' || !preg_match('/^(\d+(?:\.\d+)?)([kKmMgG]?)$/', trim($rate), $m)) {
            return 0;
        }
        $mult = match (strtolower($m[2])) {
            'k' => 1000,
            'm' => 1000000,
            'g' => 1000000000,
            default => 1,
        };
        return (int) round((float) $m[1] * $mult);
    }

    public static function normalizeRate(?string $rate): ?string
    {
        if ($rate === null) {
            return null;
        }
        $rate = trim(latin_digits($rate));
        if ($rate === '' || $rate === '0') {
            return null;
        }
        return preg_replace_callback('/[kKmMgG]$/', static fn ($m) => strtolower($m[0]) === 'k' ? 'k' : strtoupper($m[0]), $rate);
    }

    /** نص السرعة بصيغة RouterOS: رفع/تنزيل مع الدفعة (Burst) اختيارياً */
    public static function rateLimit(array $row): ?string
    {
        $up = self::normalizeRate($row['rate_up'] ?? null);
        $down = self::normalizeRate($row['rate_down'] ?? null);
        if ($up === null && $down === null) {
            return null;
        }
        $s = ($up ?? '0') . '/' . ($down ?? '0');
        $bu = self::normalizeRate($row['burst_rate_up'] ?? null);
        $bd = self::normalizeRate($row['burst_rate_down'] ?? null);
        if ($bu !== null || $bd !== null) {
            $tu = self::normalizeRate($row['burst_threshold_up'] ?? null) ?? ($up ?? '0');
            $td = self::normalizeRate($row['burst_threshold_down'] ?? null) ?? ($down ?? '0');
            $bt = max(1, (int) ($row['burst_time'] ?? 10));
            $s .= ' ' . ($bu ?? '0') . '/' . ($bd ?? '0') . ' ' . $tu . '/' . $td . ' ' . $bt . '/' . $bt;
        }
        return $s;
    }

    /** وصف مقروء للسرعة: تنزيل / رفع */
    public static function describeRate(array $row): string
    {
        $up = self::normalizeRate($row['rate_up'] ?? null);
        $down = self::normalizeRate($row['rate_down'] ?? null);
        if ($up === null && $down === null) {
            return 'غير محدودة';
        }
        $f = static fn (?string $r) => $r === null ? '∞' : fmt_rate(self::rateToBps($r));
        return $f($down) . ' / ' . $f($up);
    }
}
