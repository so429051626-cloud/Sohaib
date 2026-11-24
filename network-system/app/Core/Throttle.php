<?php
declare(strict_types=1);

namespace App\Core;

/**
 * تقييد المحاولات والمعدّل عبر جدول throttle.
 */
final class Throttle
{
    /** الثواني المتبقية على القفل (0 = غير مقفل) */
    public static function lockedFor(string $key): int
    {
        $until = DB::value('SELECT locked_until FROM throttle WHERE `key` = ?', [$key]);
        if (!$until) {
            return 0;
        }
        $left = (int) strtotime((string) $until) - time();
        return max(0, $left);
    }

    /** تسجيل محاولة فاشلة؛ يُقفل المفتاح عند بلوغ الحد داخل النافذة */
    public static function fail(string $key, int $max, int $windowSeconds, int $lockSeconds): void
    {
        $now = time();
        $row = DB::row('SELECT attempts, window_start FROM throttle WHERE `key` = ?', [$key]);
        if (!$row || $now - (int) strtotime($row['window_start']) > $windowSeconds) {
            DB::query(
                'INSERT INTO throttle (`key`, attempts, window_start, locked_until) VALUES (?, 1, ?, NULL)
                 ON DUPLICATE KEY UPDATE attempts = 1, window_start = VALUES(window_start), locked_until = NULL',
                [$key, date('Y-m-d H:i:s', $now)]
            );
            $attempts = 1;
        } else {
            DB::query('UPDATE throttle SET attempts = attempts + 1 WHERE `key` = ?', [$key]);
            $attempts = (int) $row['attempts'] + 1;
        }
        if ($attempts >= $max) {
            DB::query('UPDATE throttle SET locked_until = ? WHERE `key` = ?', [date('Y-m-d H:i:s', $now + $lockSeconds), $key]);
        }
    }

    public static function clear(string $key): void
    {
        DB::query('DELETE FROM throttle WHERE `key` = ?', [$key]);
    }

    /**
     * تحديد معدل عام: يعيد false إذا تجاوز الطلبات المسموحة في النافذة.
     */
    public static function rate(string $key, int $max, int $windowSeconds): bool
    {
        $now = time();
        $row = DB::row('SELECT attempts, window_start FROM throttle WHERE `key` = ?', [$key]);
        if (!$row || $now - (int) strtotime($row['window_start']) >= $windowSeconds) {
            DB::query(
                'INSERT INTO throttle (`key`, attempts, window_start) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE attempts = 1, window_start = VALUES(window_start)',
                [$key, date('Y-m-d H:i:s', $now)]
            );
            return true;
        }
        if ((int) $row['attempts'] >= $max) {
            return false;
        }
        DB::query('UPDATE throttle SET attempts = attempts + 1 WHERE `key` = ?', [$key]);
        return true;
    }

    public static function cleanup(): void
    {
        DB::query(
            'DELETE FROM throttle WHERE window_start < ? AND (locked_until IS NULL OR locked_until < ?)',
            [date('Y-m-d H:i:s', time() - 86400), now()]
        );
    }
}
