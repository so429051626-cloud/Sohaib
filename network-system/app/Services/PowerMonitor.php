<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;

/**
 * مراقبة البطاريات والطاقة الشمسية: سجل قراءات الجهد لكل جهاز MikroTik،
 * حدود التحذير والخطر، وتقدير تقريبي لوقت بلوغ حد الخطر من ميل الانخفاض.
 */
final class PowerMonitor
{
    /** المدى => [عدد الثواني، حجم الخانة بالثواني، صيغة تسمية الوقت] */
    public const RANGES = [
        '24h' => [86400, 300, 'H:i'],
        '7d'  => [7 * 86400, 1800, 'm-d H:i'],
        '30d' => [30 * 86400, 7200, 'm-d H:i'],
    ];

    public const STATES = [
        'ok'          => ['طبيعي', 'badge-success'],
        'warn'        => ['تحذير', 'badge-warning'],
        'crit'        => ['خطر', 'badge-danger'],
        'none'        => ['بلا حدود', 'badge-outline'],
        'offline'     => ['غير متصل', 'badge-outline'],
        'unsupported' => ['غير مدعوم', 'badge-outline'],
    ];

    /** حالة الجهد قياساً على حدّي الجهاز */
    public static function level(?float $voltage, array $router): string
    {
        if ($voltage === null) {
            return 'unsupported';
        }
        $crit = self::num($router['voltage_crit'] ?? null);
        $warn = self::num($router['voltage_warn'] ?? null);
        if ($crit === null && $warn === null) {
            return 'none';
        }
        if ($crit !== null && $voltage <= $crit) {
            return 'crit';
        }
        return $warn !== null && $voltage <= $warn ? 'warn' : 'ok';
    }

    /** الحالة المعروضة: يُضاف «غير متصل» و«غير مدعوم» إلى حالة الجهد */
    public static function display(array $router, bool $hasReadings): string
    {
        $v = self::num($router['voltage'] ?? null);
        if ($router['status'] === 'offline') {
            return $hasReadings ? 'offline' : 'unsupported';
        }
        if ($v === null) {
            return 'unsupported';
        }
        return self::level($v, $router);
    }

    private static function num(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    /**
     * تُستدعى مع كل مزامنة ناجحة للجهاز: تحفظ قراءة كل فترة وتطلق التنبيهات عند تغيّر الحالة.
     */
    public static function record(array $router, ?float $voltage, ?float $temperature, ?int $now = null): bool
    {
        if ($voltage === null || $voltage <= 0) {
            return false;
        }
        $now ??= time();
        $interval = max(1, Settings::int('power.interval', 5)) * 60;
        $last = !empty($router['power_at']) ? (int) strtotime((string) $router['power_at']) : 0;
        if ($now - $last < $interval - 20) {
            return false;
        }
        $stamp = date('Y-m-d H:i:s', $now);
        DB::insert('router_power', ['router_id' => $router['id'], 'created_at' => $stamp, 'voltage' => round($voltage, 1), 'temperature' => $temperature !== null ? round($temperature, 1) : null]);
        $state = self::level($voltage, $router);
        $prev = (string) ($router['power_state'] ?? '');
        DB::update('routers', ['power_at' => $stamp, 'power_state' => $state], 'id = ?', [$router['id']]);
        if (!Settings::bool('notify.power')) {
            return true;
        }
        $id = (int) $router['id'];
        $name = (string) $router['name'];
        $volts = rtrim(rtrim(number_format($voltage, 1, '.', ''), '0'), '.') . ' V';
        if ($state === 'crit' && $prev !== 'crit') {
            Notifier::alert('power', 'danger', 'بطارية ' . $name . ' وصلت حد الخطر', 'الجهد الحالي ' . $volts, $id, 'power-crit:' . $id, 30);
        } elseif ($state === 'warn' && !in_array($prev, ['warn', 'crit'], true)) {
            Notifier::alert('power', 'warning', 'بطارية ' . $name . ' تنخفض', 'الجهد الحالي ' . $volts, $id, 'power-warn:' . $id, 60);
        } elseif ($state === 'ok' && in_array($prev, ['warn', 'crit'], true)) {
            Notifier::alert('power', 'success', 'عاد جهد ' . $name . ' إلى الطبيعي', 'الجهد الحالي ' . $volts, $id, 'power-ok:' . $id, 30);
        }
        $crit = self::num($router['voltage_crit'] ?? null);
        if ($crit !== null && $state !== 'crit') {
            $eta = self::eta($id, $crit, $now);
            if ($eta !== null && $eta <= 3 * 3600) {
                Notifier::alert('power', 'warning', 'بطارية ' . $name . ' تنخفض', 'متوقع أن تفصل خلال نحو ' . self::etaLabel($eta) . ' (الجهد ' . $volts . ')', $id, 'power-eta:' . $id, 120);
            }
        }
        return true;
    }

    /**
     * ميل تغيّر الجهد (فولت في الثانية) بانحدار خطي بسيط.
     * @param array<int, array{0:int, 1:float}> $points [الوقت بالثواني، الجهد]
     */
    public static function slope(array $points): ?float
    {
        $n = count($points);
        if ($n < 4) {
            return null;
        }
        $t0 = $points[0][0];
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($points as [$t, $v]) {
            $x = (float) ($t - $t0);
            $sx += $x;
            $sy += $v;
            $sxx += $x * $x;
            $sxy += $x * $v;
        }
        $den = $n * $sxx - $sx * $sx;
        return abs($den) < 1e-9 ? null : ($n * $sxy - $sx * $sy) / $den;
    }

    /**
     * الثواني المتوقعة حتى بلوغ حد الخطر، من ميل آخر 90 دقيقة. null إن لم يكن الجهد ينخفض أو البيانات لا تكفي.
     */
    public static function eta(int $routerId, float $crit, ?int $now = null): ?int
    {
        $now ??= time();
        $rows = DB::rows('SELECT created_at, voltage FROM router_power WHERE router_id = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at',
            [$routerId, date('Y-m-d H:i:s', $now - 5400), date('Y-m-d H:i:s', $now)]);
        $points = array_map(static fn ($r) => [(int) strtotime((string) $r['created_at']), (float) $r['voltage']], $rows);
        if (count($points) < 4 || end($points)[0] - $points[0][0] < 1200) {
            return null;
        }
        $slope = self::slope($points);
        if ($slope === null || $slope > -0.2 / 3600) {
            // أقل من 0.2 فولت في الساعة لا يُعدّ انخفاضاً
            return null;
        }
        $current = (float) end($points)[1];
        if ($current <= $crit) {
            return 0;
        }
        $seconds = (int) round(($current - $crit) / -$slope);
        return $seconds > 48 * 3600 ? null : $seconds;
    }

    public static function etaLabel(int $seconds): string
    {
        if ($seconds < 2700) {
            return max(5, (int) (round($seconds / 300) * 5)) . ' دقيقة';
        }
        $hours = (int) max(1, round($seconds / 3600));
        return match (true) {
            $hours === 1 => 'ساعة',
            $hours === 2 => 'ساعتين',
            $hours <= 10 => $hours . ' ساعات',
            default => $hours . ' ساعة',
        };
    }

    /**
     * سلسلة الجهد لرسمها: متوسط وأدنى قيمة لكل خانة زمنية.
     * @return array{labels:list<string>, avg:list<float>, min:list<float>, temp:list<?float>, count:int, low:?float, high:?float}
     */
    public static function series(int $routerId, string $range, ?int $now = null): array
    {
        [$span, $bucket, $format] = self::RANGES[$range] ?? self::RANGES['24h'];
        $now ??= time();
        $rows = DB::rows(
            'SELECT FLOOR(UNIX_TIMESTAMP(created_at) / ' . (int) $bucket . ') * ' . (int) $bucket . ' AS t, AVG(voltage) AS a, MIN(voltage) AS mn, MAX(voltage) AS mx, AVG(temperature) AS tp
             FROM router_power WHERE router_id = ? AND created_at >= ? GROUP BY t ORDER BY t',
            [$routerId, date('Y-m-d H:i:s', $now - $span)]
        );
        $out = ['labels' => [], 'avg' => [], 'min' => [], 'temp' => [], 'count' => count($rows), 'low' => null, 'high' => null];
        foreach ($rows as $r) {
            $out['labels'][] = date($format, (int) $r['t']);
            $out['avg'][] = round((float) $r['a'], 2);
            $out['min'][] = round((float) $r['mn'], 1);
            $out['temp'][] = $r['tp'] !== null ? round((float) $r['tp'], 1) : null;
            $out['low'] = $out['low'] === null ? (float) $r['mn'] : min($out['low'], (float) $r['mn']);
            $out['high'] = $out['high'] === null ? (float) $r['mx'] : max($out['high'], (float) $r['mx']);
        }
        return $out;
    }

    public static function prune(): void
    {
        DB::query('DELETE FROM router_power WHERE created_at < ?', [date('Y-m-d H:i:s', time() - max(31, Settings::int('retention.power_days', 35)) * 86400)]);
    }
}
