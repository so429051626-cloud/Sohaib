<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * تجميع استهلاك الكرت من عدّادات كل أجهزة MikroTik.
 * عدّاد جهاز MikroTik تراكمي؛ إذا نقص (تصفير أو إعادة إنشاء) يُرحَّل القديم إلى carry.
 */
final class CardUsage
{
    /**
     * تسجيل عدّادات كرت على جهاز MikroTik.
     * @return bool هل تغيّر الاستهلاك
     */
    public static function record(int $cardId, int $routerId, int $uptime, int $bytesIn, int $bytesOut, string $state = 'present'): bool
    {
        $row = DB::row('SELECT * FROM card_routers WHERE card_id = ? AND router_id = ?', [$cardId, $routerId]);
        $now = now();
        if (!$row) {
            DB::insert('card_routers', [
                'card_id' => $cardId, 'router_id' => $routerId, 'state' => $state,
                'uptime' => $uptime, 'bytes_in' => $bytesIn, 'bytes_out' => $bytesOut,
                'carry_uptime' => 0, 'carry_bytes_in' => 0, 'carry_bytes_out' => 0,
                'provisioned_at' => null, 'synced_at' => $now,
            ]);
            return $uptime > 0 || $bytesIn > 0 || $bytesOut > 0;
        }
        $carry = [
            'carry_uptime'    => (int) $row['carry_uptime'],
            'carry_bytes_in'  => (int) $row['carry_bytes_in'],
            'carry_bytes_out' => (int) $row['carry_bytes_out'],
        ];
        // انخفاض العدّاد يعني أن مستخدم جهاز MikroTik أُعيد إنشاؤه أو صُفّر
        if ($uptime < (int) $row['uptime'] || $bytesIn < (int) $row['bytes_in'] || $bytesOut < (int) $row['bytes_out']) {
            $carry['carry_uptime'] += (int) $row['uptime'];
            $carry['carry_bytes_in'] += (int) $row['bytes_in'];
            $carry['carry_bytes_out'] += (int) $row['bytes_out'];
        }
        $changed = $uptime !== (int) $row['uptime'] || $bytesIn !== (int) $row['bytes_in'] || $bytesOut !== (int) $row['bytes_out'];
        DB::update('card_routers', $carry + [
            'uptime' => $uptime, 'bytes_in' => $bytesIn, 'bytes_out' => $bytesOut,
            'state' => $state, 'synced_at' => $now,
        ], 'card_id = ? AND router_id = ?', [$cardId, $routerId]);
        return $changed;
    }

    /** تعليم وجود الكرت على جهاز MikroTik كمحذوف مع الإبقاء على عدّاداته */
    public static function markRemoved(int $cardId, int $routerId): void
    {
        DB::update('card_routers', ['state' => 'removed', 'synced_at' => now()], 'card_id = ? AND router_id = ?', [$cardId, $routerId]);
    }

    /** إعادة حساب مجموع الاستهلاك لكروت محددة */
    public static function recompute(array $cardIds): void
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        foreach (array_chunk($cardIds, 500) as $chunk) {
            DB::query(
                'UPDATE cards c
                 JOIN (SELECT card_id,
                              SUM(carry_uptime + uptime) AS t,
                              SUM(carry_bytes_in + bytes_in) AS bi,
                              SUM(carry_bytes_out + bytes_out) AS bo
                       FROM card_routers WHERE card_id IN (' . DB::in($chunk) . ') GROUP BY card_id) x ON x.card_id = c.id
                 SET c.used_seconds = x.t, c.used_bytes_in = x.bi, c.used_bytes_out = x.bo, c.updated_at = ?',
                array_merge($chunk, [now()])
            );
        }
    }

    /** تصفير استهلاك كرت بالكامل */
    public static function reset(int $cardId): void
    {
        DB::delete('card_routers', 'card_id = ?', [$cardId]);
        DB::update('cards', ['used_seconds' => 0, 'used_bytes_in' => 0, 'used_bytes_out' => 0], 'id = ?', [$cardId]);
    }
}
