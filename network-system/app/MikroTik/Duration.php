<?php
declare(strict_types=1);

namespace App\MikroTik;

/**
 * تحويل صيغ الوقت في RouterOS: 1w2d3h4m5s · 00:01:02 · 1d02:03:04 · 5s250ms
 */
final class Duration
{
    public static function toSeconds(?string $value): int
    {
        if ($value === null) {
            return 0;
        }
        $value = trim($value);
        if ($value === '' || $value === 'none' || $value === 'never') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $total = 0;
        // صيغة الساعة hh:mm:ss مع أيام/أسابيع اختيارية قبلها
        if (preg_match('/^(?:(\d+)w)?\s*(?:(\d+)d)?\s*(\d{1,3}):(\d{2}):(\d{2})(?:\.\d+)?$/', $value, $m)) {
            return (int) $m[1] * 604800 + (int) $m[2] * 86400 + (int) $m[3] * 3600 + (int) $m[4] * 60 + (int) $m[5];
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)(ms|us|w|d|h|m|s)/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $n = (float) $m[1];
                $total += match ($m[2]) {
                    'w' => $n * 604800,
                    'd' => $n * 86400,
                    'h' => $n * 3600,
                    'm' => $n * 60,
                    's' => $n,
                    default => 0,
                };
            }
            return (int) floor($total);
        }
        return 0;
    }

    /** ثوانٍ ← صيغة RouterOS المقبولة (مثل 1d2h3m4s) */
    public static function format(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $out = '';
        foreach (['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($seconds >= $size) {
                $out .= intdiv($seconds, $size) . $unit;
                $seconds %= $size;
            }
        }
        return $out;
    }
}
