<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use RuntimeException;
use Throwable;

/**
 * سجل الانقطاعات: يُفتح تلقائياً عند انقطاع جهاز MikroTik ويُغلق عند عودته، ويمكن إضافته يدوياً.
 * تعويض المشتركين مبني كاملاً لكنه مطفأ حتى يفعّله صاحب الشبكة، ولا يُطبَّق مرتين على الانقطاع نفسه.
 */
final class OutageService
{
    public const CAUSES = ['unknown' => 'غير محدد', 'power' => 'كهرباء', 'fault' => 'عطل', 'damage' => 'أضرار', 'maintenance' => 'صيانة'];

    // ------------------------------------------------------------------
    // الفتح والإغلاق
    // ------------------------------------------------------------------

    /** عدد المشتركين المتأثرين بانقطاع جهاز أو منطقة (الكل عند غيابهما) */
    public static function affectedCount(?int $routerId, ?int $areaId): int
    {
        [$where, $params] = self::scope($routerId, $areaId);
        return (int) DB::value("SELECT COUNT(*) FROM subscribers s WHERE s.status = 'active' AND $where", $params);
    }

    /** @return array{0:string, 1:array} */
    private static function scope(?int $routerId, ?int $areaId): array
    {
        if ($routerId !== null) {
            return ['s.router_id = ?', [$routerId]];
        }
        if ($areaId !== null) {
            return ['s.area_id = ?', [$areaId]];
        }
        return ['1 = 1', []];
    }

    /**
     * فتح انقطاع تلقائياً عند انقطاع الاتصال بجهاز.
     * يبدأ من آخر اتصال ناجح بالجهاز (لا من لحظة اكتشافه)، وسببه «كهرباء» إن كانت بطاريته منخفضة قبل انقطاعه.
     */
    public static function openAuto(array $router, ?int $now = null): ?int
    {
        if (!Settings::bool('outages.auto')) {
            return null;
        }
        $id = (int) $router['id'];
        if (DB::value('SELECT id FROM outages WHERE router_id = ? AND ended_at IS NULL LIMIT 1', [$id])) {
            return null;
        }
        $now ??= time();
        $start = $now;
        $seen = !empty($router['last_seen_at']) ? (int) strtotime((string) $router['last_seen_at']) : 0;
        if ($seen > 0 && $seen <= $now && $now - $seen <= 30 * 86400) {
            $start = $seen;
        }
        // قراءة البطارية تُعتمد إن كانت قبل الانقطاع بنصف ساعة على الأكثر
        $powerAt = !empty($router['power_at']) ? (int) strtotime((string) $router['power_at']) : 0;
        $lowPower = in_array((string) ($router['power_state'] ?? ''), ['warn', 'crit'], true) && $powerAt > 0 && $powerAt <= $now && $start - $powerAt <= 1800;
        $cause = $lowPower ? 'power' : 'unknown';
        $error = trim((string) ($router['last_error'] ?? ''));
        return DB::insert('outages', [
            'router_id' => $id, 'area_id' => $router['area_id'] ?? null, 'title' => 'انقطاع ' . $router['name'], 'cause' => $cause, 'source' => 'auto',
            'started_at' => date('Y-m-d H:i:s', $start), 'affected' => self::affectedCount($id, null),
            'notes' => $error !== '' ? mb_substr('آخر خطأ اتصال: ' . $error, 0, 500) : null,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * أجهزة منقطعة بلا انقطاع مسجّل (انقطعت قبل تفعيل الفتح التلقائي أو قبل التحديث): يُفتح لها انقطاع.
     * لا يُعاد فتح انقطاع أغلقه المالك يدوياً والجهاز ما زال منقطعاً.
     * @return int عدد الانقطاعات المفتوحة
     */
    public static function catchUp(?int $now = null): int
    {
        if (!Settings::bool('outages.auto')) {
            return 0;
        }
        $now ??= time();
        $rows = DB::rows(
            "SELECT r.* FROM routers r
             WHERE r.is_active = 1 AND r.status = 'offline'
               AND NOT EXISTS (SELECT 1 FROM outages o WHERE o.router_id = r.id
                               AND (o.ended_at IS NULL OR o.started_at >= COALESCE(r.last_seen_at, r.status_changed_at, r.created_at) - INTERVAL 5 MINUTE))
             LIMIT 200"
        );
        $n = 0;
        foreach ($rows as $r) {
            if (self::openAuto($r, $now) !== null) {
                $n++;
            }
        }
        return $n;
    }

    /** إغلاق الانقطاع التلقائي المفتوح لجهاز عند عودته */
    public static function closeAuto(array $router, ?int $now = null): int
    {
        $rows = DB::rows("SELECT id FROM outages WHERE router_id = ? AND ended_at IS NULL AND source = 'auto'", [$router['id']]);
        foreach ($rows as $o) {
            self::close((int) $o['id'], $now, false);
        }
        return count($rows);
    }

    /** إغلاق انقطاع، ثم التعويض التلقائي إن كان مفعّلاً */
    public static function close(int $id, ?int $at = null, bool $log = true): void
    {
        $o = DB::row('SELECT * FROM outages WHERE id = ?', [$id]);
        if (!$o || $o['ended_at'] !== null) {
            return;
        }
        $end = max((int) strtotime((string) $o['started_at']), $at ?? time());
        DB::update('outages', ['ended_at' => date('Y-m-d H:i:s', $end), 'updated_at' => now()], 'id = ?', [$id]);
        if ($log) {
            Audit::log('outage.close', 'إغلاق الانقطاع: ' . $o['title'], 'outage', $id);
        }
        if (self::compEnabled() && Settings::bool('outages.comp_auto')) {
            try {
                self::compensate($id, null, true);
            } catch (Throwable $e) {
                log_message('warning', 'auto compensation #' . $id . ': ' . $e->getMessage());
            }
        }
    }

    public static function duration(array $o, ?int $now = null): int
    {
        $end = $o['ended_at'] !== null ? (int) strtotime((string) $o['ended_at']) : ($now ?? time());
        return max(0, $end - (int) strtotime((string) $o['started_at']));
    }

    // ------------------------------------------------------------------
    // التعويض
    // ------------------------------------------------------------------

    public static function compEnabled(): bool
    {
        return Settings::bool('outages.comp_enabled');
    }

    /** القاعدة التي يحددها المالك: حد أدنى لمدة الانقطاع، كل كم ساعة تساوي يوماً، وحد أقصى للأيام */
    public static function rule(): array
    {
        return [
            'min_hours' => max(0.0, (float) Settings::get('outages.comp_min_hours', '6')),
            'hours_per_day' => max(1.0, (float) Settings::get('outages.comp_hours_per_day', '24')),
            'max_days' => max(1, min(60, Settings::int('outages.comp_max_days', 7))),
        ];
    }

    /** عدد أيام التعويض لمدة انقطاع بالثواني */
    public static function daysFor(int $seconds, ?array $rule = null): int
    {
        $rule ??= self::rule();
        $hours = $seconds / 3600;
        if ($hours <= 0 || $hours < $rule['min_hours']) {
            return 0;
        }
        return (int) min($rule['max_days'], max(1, (int) ceil($hours / $rule['hours_per_day'])));
    }

    /**
     * معاينة التعويض: المتأثرون وعدد الأيام المضافة وتاريخ الانتهاء الجديد لكل منهم (دون أي تعديل).
     * @return array{eligible:bool, reason:string, days:int, rows:array<int, array>}
     */
    public static function preview(array $o, ?int $now = null): array
    {
        $now ??= time();
        $out = ['eligible' => false, 'reason' => '', 'days' => 0, 'rows' => []];
        if (!self::compEnabled()) {
            $out['reason'] = 'التعويض غير مفعّل.';
            return $out;
        }
        if ($o['ended_at'] === null) {
            $out['reason'] = 'الانقطاع ما زال مفتوحاً.';
            return $out;
        }
        if ($o['compensated_at'] !== null) {
            $out['reason'] = 'تم تعويض هذا الانقطاع.';
            return $out;
        }
        $days = self::daysFor(self::duration($o));
        $out['days'] = $days;
        if ($days < 1) {
            $out['reason'] = 'مدة الانقطاع أقل من الحد الأدنى للتعويض.';
            return $out;
        }
        [$where, $params] = self::scope($o['router_id'] !== null ? (int) $o['router_id'] : null, $o['area_id'] !== null ? (int) $o['area_id'] : null);
        // من كان اشتراكه سارياً وقت الانقطاع
        $subs = DB::rows(
            "SELECT s.id, s.full_name, s.username, s.phone, s.expires_at FROM subscribers s
             WHERE s.status = 'active' AND s.expires_at IS NOT NULL AND s.expires_at > ? AND s.created_at <= ? AND $where ORDER BY s.full_name",
            array_merge([$o['started_at'], $o['ended_at']], $params)
        );
        foreach ($subs as $s) {
            $base = max((int) strtotime((string) $s['expires_at']), $now);
            $out['rows'][] = $s + ['days' => $days, 'new_expires_at' => date('Y-m-d H:i:s', $base + $days * 86400)];
        }
        $out['eligible'] = $out['rows'] !== [];
        if (!$out['eligible']) {
            $out['reason'] = 'لا يوجد مشتركون متأثرون باشتراك سارٍ وقت الانقطاع.';
        }
        return $out;
    }

    /**
     * تطبيق التعويض: تمديد اشتراك كل متأثر. يُحجز الانقطاع أولاً فلا يُطبَّق مرتين.
     * @return array{count:int, days:int}
     */
    public static function compensate(int $outageId, ?int $employeeId = null, bool $auto = false): array
    {
        if (!self::compEnabled()) {
            throw new RuntimeException('التعويض غير مفعّل.');
        }
        $o = DB::row('SELECT * FROM outages WHERE id = ?', [$outageId]);
        if (!$o) {
            throw new RuntimeException('الانقطاع غير موجود.');
        }
        $preview = self::preview($o);
        if (!$preview['eligible']) {
            throw new RuntimeException($preview['reason']);
        }
        $employeeId ??= Auth::id();
        $stamp = now();
        $done = DB::transaction(static function () use ($o, $preview, $employeeId, $auto, $stamp) {
            $claimed = DB::query('UPDATE outages SET compensated_at = ?, compensated_days = ?, compensated_by = ?, compensated_auto = ? WHERE id = ? AND compensated_at IS NULL',
                [$stamp, $preview['days'], $employeeId, $auto ? 1 : 0, $o['id']])->rowCount();
            if ($claimed !== 1) {
                throw new RuntimeException('تم تعويض هذا الانقطاع.');
            }
            $count = 0;
            foreach ($preview['rows'] as $r) {
                DB::insert('outage_compensations', [
                    'outage_id' => $o['id'], 'subscriber_id' => $r['id'], 'days' => $r['days'],
                    'old_expires_at' => $r['expires_at'], 'new_expires_at' => $r['new_expires_at'], 'created_at' => $stamp,
                ]);
                DB::update('subscribers', ['expires_at' => $r['new_expires_at'], 'updated_at' => $stamp], 'id = ?', [$r['id']]);
                DB::insert('subscriber_transactions', [
                    'subscriber_id' => $r['id'], 'type' => 'compensation', 'amount' => 0, 'period_from' => $r['expires_at'], 'period_to' => $r['new_expires_at'],
                    'notes' => mb_substr('تعويض ' . $r['days'] . ' يوم عن ' . $o['title'], 0, 255), 'employee_id' => $employeeId, 'created_at' => $stamp,
                ]);
                $count++;
            }
            DB::update('outages', ['compensated_count' => $count], 'id = ?', [$o['id']]);
            return $count;
        });
        Audit::log('outage.compensate', ($auto ? 'تعويض تلقائي: ' : 'تعويض: ') . $preview['days'] . ' يوم لعدد ' . $done . ' مشترك عن ' . $o['title'], 'outage', (int) $o['id'],
            ['days' => $preview['days'], 'subscribers' => $done, 'auto' => $auto], $employeeId);
        return ['count' => (int) $done, 'days' => $preview['days']];
    }
}
