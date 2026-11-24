<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * المصاريف وصافي الربح: فئات المصاريف، الدخل المحصّل، وحصص الشركاء.
 * الدخل = ما قُبض فعلاً (المدفوع عند بيع الكروت + دفعات المشتركين + دفعات الموزعين − المبالغ المردودة)،
 * وهو نفسه «المبالغ المحصّلة» في تقرير المبيعات.
 */
final class Finance
{
    /** الفئة => [الاسم، الأيقونة، لون الشارة] */
    public const CATEGORIES = [
        'fuel'        => ['سولار ووقود', 'fuel', 'badge-warning'],
        'batteries'   => ['بطاريات وطاقة', 'battery-charging', 'badge-success'],
        'rent'        => ['إيجار أبراج ومواقع', 'radio-tower', 'badge-violet'],
        'provider'    => ['فاتورة المزوّد', 'globe', 'badge-info'],
        'salaries'    => ['رواتب وأجور', 'users', 'badge-primary'],
        'equipment'   => ['أجهزة ومعدات', 'hard-drive', 'badge-info'],
        'maintenance' => ['صيانة وتصليح', 'wrench', 'badge-warning'],
        'transport'   => ['مواصلات', 'truck', 'badge-outline'],
        'other'       => ['أخرى', 'ellipsis', 'badge-outline'],
    ];

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? self::CATEGORIES['other'][0];
    }

    /**
     * الدخل المحصّل مجمّعاً بتعبير SQL للتاريخ (يوم أو شهر).
     * @param string $bucket تعبير يحوي {col} مكان عمود التاريخ، مثل "DATE({col})"
     * @return array<string,float>
     */
    public static function collectedBy(string $bucket, string $from, string $to): array
    {
        $b = str_replace('{col}', 'created_at', $bucket);
        $out = [];
        $add = static function (array $pairs, int $sign = 1) use (&$out): void {
            foreach ($pairs as $k => $v) {
                $out[$k] = ($out[$k] ?? 0.0) + $sign * (float) $v;
            }
        };
        $add(DB::pairs("SELECT $b AS k, SUM(paid) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY k", [$from, $to]));
        $add(DB::pairs("SELECT $b AS k, SUM(amount) FROM subscriber_transactions WHERE type = 'payment' AND created_at BETWEEN ? AND ? GROUP BY k", [$from, $to]));
        $add(DB::pairs("SELECT $b AS k, SUM(amount) FROM distributor_payments WHERE created_at BETWEEN ? AND ? GROUP BY k", [$from, $to]));
        $add(DB::pairs("SELECT $b AS k, SUM(amount) FROM subscriber_transactions WHERE type = 'refund' AND created_at BETWEEN ? AND ? GROUP BY k", [$from, $to]), -1);
        return $out;
    }

    /** الدخل المحصّل في فترة (تاريخان Y-m-d) */
    public static function collected(string $from, string $to): float
    {
        return round(array_sum(self::collectedBy("'all'", $from . ' 00:00:00', $to . ' 23:59:59')), 2);
    }

    public static function spent(string $from, string $to): float
    {
        return round((float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE spent_on BETWEEN ? AND ?', [$from, $to]), 2);
    }

    /** @return list<array{category:string, count:int, total:float}> من الأكبر إلى الأصغر */
    public static function byCategory(string $from, string $to): array
    {
        return array_map(static fn ($r) => ['category' => (string) $r['category'], 'count' => (int) $r['c'], 'total' => (float) $r['t']],
            DB::rows('SELECT category, COUNT(*) AS c, SUM(amount) AS t FROM expenses WHERE spent_on BETWEEN ? AND ? GROUP BY category ORDER BY t DESC', [$from, $to]));
    }

    /** @return list<array> الشركاء بترتيب النسبة */
    public static function partners(): array
    {
        return DB::rows('SELECT * FROM partners ORDER BY share DESC, name');
    }

    public static function sharesTotal(?int $exceptId = null): float
    {
        return round((float) DB::value('SELECT COALESCE(SUM(share), 0) FROM partners WHERE id <> ?', [(int) $exceptId]), 2);
    }

    /**
     * توزيع صافي الربح على الشركاء بنسبهم، والباقي غير الموزّع إن لم تكتمل النسب 100%.
     * @return array{rows: list<array{name:string, share:float, amount:float}>, rest: array{share:float, amount:float}}
     */
    public static function split(float $net): array
    {
        $rows = [];
        $used = 0.0;
        foreach (self::partners() as $p) {
            $share = (float) $p['share'];
            $used += $share;
            $rows[] = ['name' => (string) $p['name'], 'share' => $share, 'amount' => round($net * $share / 100, 2)];
        }
        $rest = max(0.0, round(100 - $used, 2));
        return ['rows' => $rows, 'rest' => ['share' => $rest, 'amount' => round($net * $rest / 100, 2)]];
    }

    /** مبلغ مكتوب بأرقام عربية أو فواصل: «١٬٢٥٠٫٥» → 1250.5، وغير الصالح null */
    public static function amount(string $raw): ?float
    {
        $v = str_replace(['٫', '٬', ',', ' ', "\u{00A0}"], ['.', '', '', '', ''], latin_digits(trim($raw)));
        if (!preg_match('/^\d+(\.\d+)?$/', $v)) {
            return null;
        }
        return round((float) $v, 2);
    }
}
