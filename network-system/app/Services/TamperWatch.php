<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;

/**
 * تنبيهات التلاعب (الميزة 4): ما يدل على استغلال أو خطأ متعمّد، يظهر في صفحة التنبيهات ويصل إلى تيليجرام عند تفعيله.
 *  - كرت يعمل على أجهزة أكثر مما تسمح به باقته خلال يوم.
 *  - موظف يلغي عمليات بيع كثيرة خلال يوم.
 *  - موزّع يكبر دَينه بمبيعات جديدة ولا يسدّد منذ مدة.
 * كل تنبيه يتكرر مرة واحدة على الأكثر في مدّته (يوم للكروت والإلغاءات، وأسبوع للديون).
 */
final class TamperWatch
{
    public const TYPE = 'tamper';

    /** @return array{cards:int, cancels:int, debts:int} عدد التنبيهات الجديدة */
    public static function run(?int $now = null): array
    {
        $out = ['cards' => 0, 'cancels' => 0, 'debts' => 0];
        if (!Settings::bool('notify.tamper')) {
            return $out;
        }
        $now ??= time();
        foreach (self::sharedCards($now) as $c) {
            $macs = explode(',', (string) $c['macs']);
            $id = Notifier::alert(self::TYPE, 'danger',
                'الكرت ' . $c['username'] . ' يعمل على ' . ar_plural(count($macs), 'جهاز واحد', 'جهازين', 'أجهزة', 'جهازاً'),
                'المسموح لباقة «' . $c['category'] . '»: ' . max(1, (int) $c['allowed']) . ' · الأجهزة: ' . implode('، ', array_slice($macs, 0, 6)),
                $c['router_id'] !== null ? (int) $c['router_id'] : null, 'tamper:card:' . $c['card_id'], 1440);
            $out['cards'] += $id !== null ? 1 : 0;
        }
        foreach (self::cancellations($now) as $e) {
            $id = Notifier::alert(self::TYPE, 'warning',
                'الموظف ' . $e['name'] . ' ألغى ' . ar_plural((int) $e['n'], 'عملية بيع واحدة', 'عمليتي بيع', 'عمليات بيع', 'عملية بيع') . ' خلال 24 ساعة',
                'قيمتها ' . money((float) $e['total']) . ' · آخرها ' . substr((string) $e['last_at'], 0, 16),
                null, 'tamper:cancel:' . $e['id'] . ':' . date('Y-m-d', $now), 1440);
            $out['cancels'] += $id !== null ? 1 : 0;
        }
        $days = max(1, Settings::int('tamper.debt_days', 14));
        foreach (self::debts($now) as $d) {
            $id = Notifier::alert(self::TYPE, 'warning',
                'دين الموزّع ' . $d['name'] . ' يكبر ولا يسدّد',
                'الدين ' . money((float) $d['balance']) . ' · أُضيف إليه ' . money((float) $d['added']) . ' خلال ' . $days . ' يوماً · '
                    . ($d['last_paid'] !== null ? 'آخر دفعة ' . substr((string) $d['last_paid'], 0, 10) : 'لم يدفع أي دفعة'),
                null, 'tamper:debt:' . $d['id'], 7 * 1440);
            $out['debts'] += $id !== null ? 1 : 0;
        }
        return $out;
    }

    /** كروت استُخدمت خلال 24 ساعة من أجهزة (MAC) أكثر مما تسمح به باقتها */
    public static function sharedCards(int $now): array
    {
        return DB::rows("SELECT s.card_id, c.username, MAX(cat.name) AS category, MAX(cat.shared_users) AS allowed,
                GROUP_CONCAT(DISTINCT s.mac ORDER BY s.mac SEPARATOR ',') AS macs, COUNT(DISTINCT s.mac) AS devices, MAX(s.router_id) AS router_id
            FROM sessions s JOIN cards c ON c.id = s.card_id JOIN categories cat ON cat.id = c.category_id
            WHERE s.card_id IS NOT NULL AND s.mac IS NOT NULL AND s.mac <> '' AND s.started_at >= ?
            GROUP BY s.card_id, c.username
            HAVING COUNT(DISTINCT s.mac) > GREATEST(1, MAX(cat.shared_users))
            ORDER BY devices DESC LIMIT 50", [date('Y-m-d H:i:s', $now - 86400)]);
    }

    /** موظفون (غير المالك) ألغوا خلال 24 ساعة عدداً من عمليات البيع يبلغ الحد */
    public static function cancellations(int $now): array
    {
        $limit = max(2, Settings::int('tamper.cancel_limit', 3));
        return DB::rows("SELECT e.id, e.name, COUNT(*) AS n, COALESCE(SUM(s.total), 0) AS total, MAX(s.cancelled_at) AS last_at
            FROM sales s JOIN employees e ON e.id = s.cancelled_by
            WHERE s.status = 'cancelled' AND s.cancelled_at >= ? AND e.is_owner = 0
            GROUP BY e.id, e.name HAVING COUNT(*) >= ? ORDER BY n DESC", [date('Y-m-d H:i:s', $now - 86400), $limit]);
    }

    /** موزعون دينهم فوق الحد، زاد بمبيعات آجلة خلال المدة، ولم يدفعوا فيها شيئاً */
    public static function debts(int $now): array
    {
        $since = date('Y-m-d H:i:s', $now - max(1, Settings::int('tamper.debt_days', 14)) * 86400);
        $min = max(0.0, (float) Settings::get('tamper.debt_min', '100'));
        return DB::rows("SELECT d.id, d.name, d.balance,
                (SELECT COALESCE(SUM(s.total - s.paid), 0) FROM sales s WHERE s.distributor_id = d.id AND s.status = 'completed' AND s.created_at >= ?) AS added,
                (SELECT MAX(p.created_at) FROM distributor_payments p WHERE p.distributor_id = d.id) AS last_paid
            FROM distributors d
            WHERE d.is_active = 1 AND d.balance >= ? AND d.balance > 0
              AND NOT EXISTS (SELECT 1 FROM distributor_payments p WHERE p.distributor_id = d.id AND p.created_at >= ?)
            HAVING added > 0 ORDER BY d.balance DESC LIMIT 50", [$since, $min, $since]);
    }
}
