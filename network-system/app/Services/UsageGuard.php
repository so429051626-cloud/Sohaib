<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use RuntimeException;

/**
 * كشف الاستهلاك الزائد وتحديد السرعة:
 *  - السرعة الحالية لكل جلسة تُحسب أثناء المزامنة من فرق العدّادات (SessionTracker).
 *  - من يتجاوز حد التنزيل مدة متواصلة يصدر له تنبيه (مرة لكل موجة سحب)، ويمكن تحديد سرعته تلقائياً.
 *  - تحديد السرعة ينقل المستخدم إلى ملف سرعة مخفّض على جهاز MikroTik ويُعيد اتصاله، ويُرفع عند انتهاء مدته.
 */
final class UsageGuard
{
    public const DURATIONS = [30 => 'نصف ساعة', 60 => 'ساعة', 180 => '3 ساعات', 720 => '12 ساعة', 1440 => 'يوم', 0 => 'حتى أرفعه'];

    /** حد سرعة التنزيل للكشف (بت/ثانية)، صفر عند الإيقاف */
    public static function threshold(): int
    {
        if (!Settings::bool('usage.heavy_enabled')) {
            return 0;
        }
        return (int) round(max(1.0, (float) Settings::get('usage.heavy_mbps', '20')) * 1000000);
    }

    public static function minutes(): int
    {
        return max(1, min(60, Settings::int('usage.heavy_minutes', 3)));
    }

    /**
     * بعد مزامنة جهاز: تنبيه لكل جلسة تجاوزت الحد مدة متواصلة (ومرة واحدة لكل موجة سحب).
     * @return int عدد التنبيهات الجديدة
     */
    public static function check(array $router): int
    {
        if (self::threshold() <= 0) {
            return 0;
        }
        $since = date('Y-m-d H:i:s', time() - self::minutes() * 60);
        $rows = DB::rows(
            'SELECT * FROM sessions WHERE router_id = ? AND stopped_at IS NULL AND heavy_alerted = 0 AND heavy_since IS NOT NULL AND heavy_since <= ?',
            [$router['id'], $since]
        );
        $n = 0;
        foreach ($rows as $s) {
            DB::update('sessions', ['heavy_alerted' => 1], 'id = ?', [$s['id']]);
            $rate = (int) $s['rate_down'];
            // نسبة السحب من حمل الجهاز: تنزيل واجهة الإنترنت (إن ضُبطت واجهة الإنترنت للجهاز)
            $wan = (int) ($router['rx_bps'] ?? 0);
            $share = $wan > 0 ? min(100, (int) round($rate / $wan * 100)) : null;
            $owner = self::ownerLabel($s);
            $capped = null;
            if (Settings::bool('usage.auto_cap') && ($s['card_id'] || $s['subscriber_id'])) {
                try {
                    $kind = $s['card_id'] ? 'card' : 'subscriber';
                    $ref = (int) ($s['card_id'] ?: $s['subscriber_id']);
                    if (!self::activeCap($kind, $ref)) {
                        self::cap($kind, $ref, self::defaultCap()['down'], self::defaultCap()['up'], self::defaultCap()['minutes'], 'تحديد تلقائي بعد سحب ' . fmt_rate($rate), true);
                        $capped = self::defaultCap();
                    }
                } catch (\Throwable $e) {
                    log_message('usage', 'auto cap failed: ' . $e->getMessage());
                }
            }
            $title = $owner . ' يسحب تحميلاً ضخماً على ' . $router['name'];
            $msg = 'سرعة التنزيل ' . fmt_rate($rate) . ' لمدة ' . ar_plural(self::minutes(), 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة') . ' متواصلة'
                . ($share !== null ? ' (نحو ' . $share . '% من حمل الجهاز)' : '')
                . ($capped ? '. حُددت سرعته تلقائياً إلى ' . $capped['down'] . ' لمدة ' . (self::DURATIONS[$capped['minutes']] ?? $capped['minutes'] . ' دقيقة') . '.' : '.');
            Notifier::alert('heavy_usage', 'warning', $title, $msg, (int) $router['id'], 'heavy:' . $s['id'], 60);
            $n++;
        }
        return $n;
    }

    /** اسم المستهلك للعرض: اسم المشترك، أو الكرت/الحساب، أو اسم الدخول */
    public static function ownerLabel(array $s): string
    {
        if (!empty($s['subscriber_id'])) {
            $name = DB::value('SELECT full_name FROM subscribers WHERE id = ?', [(int) $s['subscriber_id']]);
            if ($name) {
                return 'المشترك ' . $name;
            }
        }
        return (!empty($s['card_id']) ? 'الكرت ' : 'المستخدم ') . $s['username'];
    }

    public static function defaultCap(): array
    {
        $norm = static fn (string $v, string $d) => Units::normalizeRate($v) ?? $d;
        return [
            'down' => $norm((string) Settings::get('usage.cap_down', '2M'), '2M'),
            'up' => $norm((string) Settings::get('usage.cap_up', '1M'), '1M'),
            'minutes' => max(0, Settings::int('usage.cap_minutes', 60)),
        ];
    }

    public static function activeCap(string $kind, int $refId): ?array
    {
        return DB::row(
            'SELECT * FROM speed_caps WHERE kind = ? AND ref_id = ? AND removed_at IS NULL AND (ends_at IS NULL OR ends_at > ?) ORDER BY id DESC LIMIT 1',
            [$kind, $refId, now()]
        );
    }

    /** نص السرعة بصيغة RouterOS (رفع/تنزيل) */
    public static function rateLimit(array $cap): string
    {
        return $cap['rate_up'] . '/' . $cap['rate_down'];
    }

    /**
     * تحديد سرعة كرت أو مشترك.
     * @param int $minutes صفر = حتى يُرفع يدوياً
     */
    public static function cap(string $kind, int $refId, string $down, string $up, int $minutes, ?string $reason = null, bool $auto = false): int
    {
        $down = Units::normalizeRate($down);
        $up = Units::normalizeRate($up);
        if ($down === null || $up === null || !preg_match('/^\d+(\.\d+)?[kMG]?$/', $down) || !preg_match('/^\d+(\.\d+)?[kMG]?$/', $up)) {
            throw new RuntimeException('حدد سرعة التنزيل والرفع.');
        }
        $target = self::target($kind, $refId);
        DB::query('UPDATE speed_caps SET removed_at = ? WHERE kind = ? AND ref_id = ? AND removed_at IS NULL', [now(), $kind, $refId]);
        $id = DB::insert('speed_caps', [
            'kind' => $kind, 'ref_id' => $refId, 'username' => $target['username'],
            'rate_down' => $down, 'rate_up' => $up, 'reason' => $reason !== null ? mb_substr($reason, 0, 160) : null, 'auto' => $auto ? 1 : 0,
            'ends_at' => $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null,
            'created_by' => $auto ? null : Auth::id(), 'created_at' => now(),
        ]);
        self::apply($kind, $refId);
        Audit::log('usage.cap', 'تحديد سرعة ' . $target['label'] . ' إلى ' . $down . ' تنزيل / ' . $up . ' رفع' . ($minutes > 0 ? ' لمدة ' . (self::DURATIONS[$minutes] ?? $minutes . ' دقيقة') : ''), $kind, $refId, ['auto' => $auto]);
        return $id;
    }

    /** رفع تحديد السرعة */
    public static function uncap(string $kind, int $refId, bool $expired = false): bool
    {
        $old = DB::rows('SELECT * FROM speed_caps WHERE kind = ? AND ref_id = ? AND removed_at IS NULL', [$kind, $refId]);
        $n = DB::query('UPDATE speed_caps SET removed_at = ? WHERE kind = ? AND ref_id = ? AND removed_at IS NULL', [now(), $kind, $refId])->rowCount();
        if ($n === 0) {
            return false;
        }
        self::apply($kind, $refId);
        self::cleanProfiles($kind, $refId, $old);
        $target = DB::value($kind === 'card' ? 'SELECT username FROM cards WHERE id = ?' : 'SELECT username FROM subscribers WHERE id = ?', [$refId]);
        Audit::log('usage.cap', ($expired ? 'انتهت مدة تحديد سرعة ' : 'رفع تحديد سرعة ') . ($kind === 'card' ? 'الكرت ' : 'المشترك ') . ($target ?? '#' . $refId), $kind, $refId);
        return true;
    }

    /** حذف ملفات السرعة المخفّضة التي لم تعد مستخدمة (مهمة مؤجلة؛ لا يُحذف ملف ما زال عليه مستخدم) */
    private static function cleanProfiles(string $kind, int $refId, array $caps): void
    {
        if ($kind === 'card') {
            $catId = (int) DB::value('SELECT category_id FROM cards WHERE id = ?', [$refId]);
            $routers = array_map('intval', DB::column('SELECT router_id FROM card_routers WHERE card_id = ?', [$refId]));
            $base = ProfileManager::categoryProfile($catId);
            $target = 'card:' . $refId;
        } else {
            $sub = DB::row('SELECT s.router_id, s.plan_id, p.service FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$refId]);
            if (!$sub || !$sub['router_id'] || $sub['service'] === 'ppp') {
                return;
            }
            $routers = [(int) $sub['router_id']];
            $base = ProfileManager::planProfile((int) $sub['plan_id']);
            $target = 'sub:' . $refId;
        }
        foreach ($caps as $c) {
            $name = ProfileManager::cappedName($base, $c);
            // ملف مشترك بين أكثر من مستخدم محدد بنفس السرعة: يبقى ما دام عليه تحديد فعّال
            foreach ($routers as $rid) {
                RouterTasks::queue($rid, 'profile_remove', ['name' => $name], $target);
            }
        }
    }

    /** رفع التحديدات التي انتهت مدتها (من المهمة المجدولة) */
    public static function expireCaps(): int
    {
        $n = 0;
        foreach (DB::rows('SELECT DISTINCT kind, ref_id FROM speed_caps WHERE removed_at IS NULL AND ends_at IS NOT NULL AND ends_at <= ? LIMIT 200', [now()]) as $c) {
            if (self::uncap($c['kind'], (int) $c['ref_id'], true)) {
                $n++;
            }
        }
        return $n;
    }

    /** بيانات الكرت/المشترك المستهدف */
    private static function target(string $kind, int $refId): array
    {
        if ($kind === 'card') {
            $c = DB::row('SELECT id, username FROM cards WHERE id = ?', [$refId]);
            if (!$c) {
                throw new RuntimeException('الكرت غير موجود.');
            }
            return ['username' => $c['username'], 'label' => 'الكرت ' . $c['username']];
        }
        if ($kind === 'subscriber') {
            $s = DB::row('SELECT id, username, full_name FROM subscribers WHERE id = ?', [$refId]);
            if (!$s) {
                throw new RuntimeException('المشترك غير موجود.');
            }
            return ['username' => $s['username'], 'label' => 'المشترك ' . $s['full_name']];
        }
        throw new RuntimeException('نوع غير معروف.');
    }

    /**
     * تطبيق السرعة الحالية (المحددة أو العادية) على أجهزة MikroTik، ثم إعادة الاتصال لتسري فوراً.
     * المستخدم يعود تلقائياً إن كان الدخول التلقائي بالجهاز مفعّلاً.
     */
    public static function apply(string $kind, int $refId): void
    {
        if ($kind === 'card') {
            $card = DB::row('SELECT id, username FROM cards WHERE id = ?', [$refId]);
            if (!$card) {
                return;
            }
            $routers = array_map('intval', DB::column("SELECT router_id FROM card_routers WHERE card_id = ? AND state = 'present'", [$refId]));
            foreach ($routers as $rid) {
                RouterTasks::dispatch($rid, 'card_provision', ['card_id' => $refId], 'card:' . $refId);
                RouterTasks::dispatch($rid, 'hs_disconnect', ['user' => $card['username']], 'card:' . $refId);
            }
            return;
        }
        $sub = DB::row('SELECT s.id, s.username, s.router_id, p.service FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$refId]);
        if (!$sub || !$sub['router_id']) {
            return;
        }
        $rid = (int) $sub['router_id'];
        RouterTasks::dispatch($rid, 'sub_provision', ['subscriber_id' => $refId], 'sub:' . $refId);
        RouterTasks::dispatch($rid, $sub['service'] === 'ppp' ? 'ppp_disconnect' : 'hs_disconnect', $sub['service'] === 'ppp' ? ['name' => $sub['username']] : ['user' => $sub['username']], 'sub:' . $refId);
    }

    /**
     * أكثر المستهلكين خلال فترة (من الجلسات التي كانت نشطة فيها).
     * @return array صفوف: kind, ref_id, username, name, down, up, sessions, last_at, online
     */
    public static function top(int $days, int $limit = 30): array
    {
        $from = date('Y-m-d H:i:s', time() - $days * 86400);
        [$scope, $params] = Auth::routerScope('s.router_id', true);
        $rows = DB::rows(
            "SELECT s.username, MAX(s.card_id) AS card_id, MAX(s.subscriber_id) AS subscriber_id,
                    SUM(s.bytes_out) AS down, SUM(s.bytes_in) AS up, COUNT(*) AS sessions, MAX(s.updated_at) AS last_at,
                    MAX(s.stopped_at IS NULL) AS online
             FROM sessions s WHERE s.updated_at >= ?" . $scope . '
             GROUP BY s.username ORDER BY down DESC LIMIT ' . (int) $limit,
            array_merge([$from], $params)
        );
        return self::names($rows);
    }

    /** المتصلون الآن مرتبين بسرعة التنزيل الحالية */
    public static function live(int $limit = 20): array
    {
        [$scope, $params] = Auth::routerScope('s.router_id', true);
        $rows = DB::rows(
            'SELECT s.*, r.name AS router_name, r.rx_bps, r.tx_bps FROM sessions s LEFT JOIN routers r ON r.id = s.router_id
             WHERE s.stopped_at IS NULL AND s.updated_at >= ?' . $scope . ' ORDER BY COALESCE(s.rate_down, 0) DESC, s.bytes_out DESC LIMIT ' . (int) $limit,
            array_merge([date('Y-m-d H:i:s', time() - 900)], $params)
        );
        return self::names($rows);
    }

    /** أسماء المشتركين والتحديد الفعّال لكل صف */
    private static function names(array $rows): array
    {
        $subIds = array_values(array_filter(array_map(static fn ($r) => (int) ($r['subscriber_id'] ?? 0), $rows)));
        $names = $subIds ? DB::pairs('SELECT id, full_name FROM subscribers WHERE id IN (' . DB::in($subIds) . ')', $subIds) : [];
        $caps = [];
        foreach (DB::rows('SELECT * FROM speed_caps WHERE removed_at IS NULL AND (ends_at IS NULL OR ends_at > ?)', [now()]) as $c) {
            $caps[$c['kind'] . ':' . $c['ref_id']] = $c;
        }
        foreach ($rows as &$r) {
            $r['kind'] = !empty($r['subscriber_id']) ? 'subscriber' : (!empty($r['card_id']) ? 'card' : null);
            $r['ref_id'] = (int) ($r['subscriber_id'] ?: ($r['card_id'] ?: 0));
            $r['name'] = $r['kind'] === 'subscriber' ? ($names[(int) $r['subscriber_id']] ?? null) : null;
            $r['cap'] = $r['kind'] ? ($caps[$r['kind'] . ':' . $r['ref_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    /** التحديدات الفعّالة */
    public static function activeCaps(): array
    {
        $rows = DB::rows('SELECT c.*, e.name AS by_name FROM speed_caps c LEFT JOIN employees e ON e.id = c.created_by
                          WHERE c.removed_at IS NULL AND (c.ends_at IS NULL OR c.ends_at > ?) ORDER BY c.id DESC', [now()]);
        $subIds = array_map(static fn ($c) => (int) $c['ref_id'], array_filter($rows, static fn ($c) => $c['kind'] === 'subscriber'));
        $names = $subIds ? DB::pairs('SELECT id, full_name FROM subscribers WHERE id IN (' . DB::in(array_values($subIds)) . ')', array_values($subIds)) : [];
        foreach ($rows as &$c) {
            $c['name'] = $c['kind'] === 'subscriber' ? ($names[(int) $c['ref_id']] ?? null) : null;
        }
        unset($c);
        return $rows;
    }
}
