<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\Brand;
use App\Core\Platform;
use App\Core\Tenant;
use App\Services\AdminNotify;
use App\Services\Ai;
use App\Services\Telegram;
use Throwable;

/**
 * أرقام المنصة للمسؤول: الدخل، التسجيلات، التحوّل، نشاط الحسابات، والحسابات المعرّضة للترك.
 * يغذّي صفحة التحليلات ومساعد المسؤول والتقرير الأسبوعي على تيليجرام.
 */
final class Insights
{
    /** @return array<string, float> آخر $n شهراً بالترتيب الزمني */
    private static function months(int $n): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = 0.0;
        }
        return $out;
    }

    public static function snapshot(): array
    {
        $now = time();
        $from = date('Y-m-01 00:00:00', strtotime('-11 months'));
        $revenue = self::months(12);
        foreach (Platform::rows("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(amount) AS s FROM tenant_payments WHERE created_at >= ? GROUP BY m", [$from]) as $r) {
            if (isset($revenue[$r['m']])) {
                $revenue[$r['m']] = (float) $r['s'];
            }
        }
        $signups = self::months(12);
        $activated = self::months(12);
        foreach (Platform::rows("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS c, SUM(activated_at IS NOT NULL) AS a FROM tenants WHERE created_at >= ? GROUP BY m", [$from]) as $r) {
            if (isset($signups[$r['m']])) {
                $signups[$r['m']] = (float) $r['c'];
                $activated[$r['m']] = (float) $r['a'];
            }
        }
        $counts = ['trial' => 0, 'active' => 0, 'expired' => 0, 'suspended' => 0, 'cancelled' => 0];
        foreach (Platform::rows('SELECT status, COUNT(*) AS c FROM tenants GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $total = array_sum($counts);
        $paid = (int) Platform::value('SELECT COUNT(*) FROM tenants WHERE activated_at IS NOT NULL');
        // التحوّل: الحسابات التي انتهت تجربتها (مضى على تسجيلها أكثر من مدة التجربة) وكم منها دفع
        $trialDays = max(1, (int) Platform::setting('platform.trial_days'));
        $matured = Platform::row('SELECT COUNT(*) AS c, SUM(activated_at IS NOT NULL) AS a FROM tenants WHERE created_at <= ?', [date('Y-m-d H:i:s', $now - $trialDays * 86400)]);
        $maturedCount = (int) ($matured['c'] ?? 0);
        $month = date('Y-m');
        $prev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $ai = Platform::row('SELECT COUNT(*) AS c, COALESCE(SUM(cost), 0) AS s FROM ai_usage WHERE ok = 1 AND created_at >= ?', [date('Y-m-01 00:00:00')]);
        return [
            'now' => date('Y-m-d H:i', $now),
            'currency' => Platform::setting('platform.currency'),
            'counts' => $counts,
            'total' => $total,
            'paid_ever' => $paid,
            'conversion' => $maturedCount > 0 ? round(100 * (int) $matured['a'] / $maturedCount, 1) : null,
            'conversion_base' => $maturedCount,
            'revenue' => $revenue,
            'revenue_month' => $revenue[$month] ?? 0.0,
            'revenue_prev' => $revenue[$prev] ?? 0.0,
            'revenue_total' => (float) Platform::value('SELECT COALESCE(SUM(amount), 0) FROM tenant_payments'),
            'signups' => $signups,
            'activated' => $activated,
            'signups_week' => (int) Platform::value('SELECT COUNT(*) FROM tenants WHERE created_at >= ?', [date('Y-m-d H:i:s', $now - 7 * 86400)]),
            'revenue_week' => (float) Platform::value('SELECT COALESCE(SUM(amount), 0) FROM tenant_payments WHERE created_at >= ?', [date('Y-m-d H:i:s', $now - 7 * 86400)]),
            'online' => (int) Platform::value('SELECT COUNT(*) FROM tenants WHERE last_seen_at >= ?', [date('Y-m-d H:i:s', $now - 900)]),
            'active_week' => (int) Platform::value("SELECT COUNT(*) FROM tenants WHERE status IN ('trial','active') AND last_seen_at >= ?", [date('Y-m-d H:i:s', $now - 7 * 86400)]),
            'pending_transfers' => (int) Platform::value("SELECT COUNT(*) FROM transfer_requests WHERE status = 'pending'"),
            'open_tickets' => (int) Platform::value("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'"),
            'ai_messages' => (int) ($ai['c'] ?? 0),
            'ai_cost' => (float) ($ai['s'] ?? 0),
        ];
    }

    /** صفوف نشاط الحسابات (من الأرقام التي تحدّثها المهمة المجدولة) */
    public static function accounts(?int $now = null): array
    {
        $now ??= time();
        $rows = Platform::rows(
            "SELECT t.*, p.name AS plan_name,
                    (SELECT COALESCE(SUM(amount), 0) FROM tenant_payments x WHERE x.tenant_id = t.id) AS paid_total,
                    (SELECT COUNT(*) FROM transfer_requests r WHERE r.tenant_id = t.id AND r.status = 'pending') AS pending
             FROM tenants t LEFT JOIN platform_plans p ON p.id = t.plan_id ORDER BY t.name"
        );
        foreach ($rows as &$t) {
            $t['phase'] = Tenant::phase($t, $now);
            $t['days_left'] = Tenant::daysLeft($t, $now);
            $t['idle_days'] = $t['last_seen_at'] ? (int) floor(($now - (int) strtotime($t['last_seen_at'])) / 86400) : null;
            [$t['risk'], $t['reasons']] = self::risk($t, $now);
        }
        unset($t);
        return $rows;
    }

    /** @return array{0:int, 1:list<string>} درجة الخطر (0–100) وأسبابها */
    public static function risk(array $t, ?int $now = null): array
    {
        $now ??= time();
        if (!in_array($t['status'], Tenant::USABLE, true)) {
            return [0, []];
        }
        $score = 0;
        $why = [];
        $ageDays = (int) floor(($now - (int) strtotime((string) $t['created_at'])) / 86400);
        $idle = $t['last_seen_at'] ? (int) floor(($now - (int) strtotime((string) $t['last_seen_at'])) / 86400) : null;
        if ($idle === null) {
            if ($ageDays >= 2) {
                $score += 40;
                $why[] = 'لم يدخل إلى النظام منذ التسجيل';
            }
        } elseif ($idle >= 14) {
            $score += 40;
            $why[] = 'لم يدخل منذ ' . $idle . ' يوماً';
        } elseif ($idle >= 7) {
            $score += 20;
            $why[] = 'لم يدخل منذ ' . $idle . ' أيام';
        }
        if ($t['stats_at'] !== null && $ageDays >= 3) {
            if ((int) $t['stat_routers'] === 0) {
                $score += 25;
                $why[] = 'لم يضف أي جهاز MikroTik';
            }
            if ((int) $t['stat_subscribers'] === 0 && (float) $t['stat_sales'] <= 0) {
                $score += 15;
                $why[] = 'لا مشتركين ولا مبيعات هذا الشهر';
            }
        }
        $phase = $t['phase'] ?? Tenant::phase($t, $now);
        $pending = (int) ($t['pending'] ?? 0) > 0;
        if (in_array($phase, ['grace', 'readonly'], true) && !$pending) {
            $score += 35;
            $why[] = $phase === 'grace' ? 'انتهى الاشتراك وهو في فترة السماح' : 'انتهى الاشتراك وهو للقراءة فقط';
        } elseif ($phase === 'ok') {
            $left = Tenant::daysLeft($t, $now);
            if ($left !== null && $left <= 5 && !$pending) {
                $score += 15;
                $why[] = ($t['status'] === 'trial' ? 'التجربة تنتهي' : 'الاشتراك ينتهي') . ' خلال ' . ($left <= 1 ? 'يوم' : $left . ' أيام') . ' دون طلب تجديد';
            }
        }
        return [min(100, $score), $why];
    }

    /** الحسابات المعرّضة لترك المنصة مرتبة من الأخطر */
    public static function atRisk(int $min = 40): array
    {
        $rows = array_values(array_filter(self::accounts(), static fn ($t) => $t['risk'] >= $min));
        usort($rows, static fn ($a, $b) => $b['risk'] <=> $a['risk'] ?: strcmp((string) $a['name'], (string) $b['name']));
        return $rows;
    }

    /** ملخص مضغوط يقرؤه مساعد المسؤول (قراءة فقط، دون أي أسرار) */
    public static function context(): array
    {
        $s = self::snapshot();
        $accounts = [];
        foreach (array_slice(self::accounts(), 0, 300) as $t) {
            $accounts[] = [
                'الشبكة' => $t['name'], 'الحساب' => $t['slug'], 'المالك' => $t['owner_name'], 'الحالة' => $t['status'], 'المرحلة' => $t['phase'],
                'الباقة' => $t['plan_name'], 'ينتهي' => $t['status'] === 'trial' ? $t['trial_ends_at'] : $t['paid_until'], 'الأيام_المتبقية' => $t['days_left'],
                'آخر_دخول' => $t['last_seen_at'], 'أيام_الخمول' => $t['idle_days'], 'أجهزة' => $t['stat_routers'], 'مشتركون' => $t['stat_subscribers'],
                'موظفون' => $t['stat_employees'], 'مبيعات_الشهر' => $t['stat_sales'], 'مدفوع_للمنصة' => (float) $t['paid_total'],
                'تاريخ_التسجيل' => substr((string) $t['created_at'], 0, 10), 'خطر_الترك' => $t['risk'], 'أسباب_الخطر' => $t['reasons'],
            ];
        }
        return ['ملخص' => $s, 'الحسابات' => $accounts];
    }

    // ------------------------------------------------------------------
    // التقرير الأسبوعي
    // ------------------------------------------------------------------

    public static function weeklyReport(): string
    {
        $s = self::snapshot();
        $cur = $s['currency'];
        $e = static fn ($v) => Telegram::esc((string) $v);
        $lines = [
            '<b>' . $e(Brand::NAME) . ' · التقرير الأسبوعي</b>',
            'الحسابات: ' . $s['total'] . ' (مفعّلة ' . $s['counts']['active'] . ' · تجريبية ' . $s['counts']['trial'] . ' · منتهية ' . $s['counts']['expired'] . ')',
            'تسجيلات الأسبوع: ' . $s['signups_week'] . ' · نشطة هذا الأسبوع: ' . $s['active_week'],
            'دخل الأسبوع: ' . number_format($s['revenue_week'], 2) . ' ' . $e($cur) . ' · دخل الشهر: ' . number_format($s['revenue_month'], 2) . ' ' . $e($cur),
            'تحويلات بانتظار الموافقة: ' . $s['pending_transfers'] . ' · تذاكر مفتوحة: ' . $s['open_tickets'],
        ];
        $ending = array_values(array_filter(self::accounts(), static fn ($t) => $t['phase'] === 'ok' && $t['days_left'] !== null && $t['days_left'] <= 7 && in_array($t['status'], Tenant::USABLE, true)));
        if ($ending) {
            $lines[] = '';
            $lines[] = '<b>تنتهي خلال 7 أيام</b>';
            foreach (array_slice($ending, 0, 8) as $t) {
                $lines[] = '• ' . $e($t['name']) . ' — ' . ($t['days_left'] <= 1 ? 'يوم' : $t['days_left'] . ' أيام');
            }
        }
        $risk = self::atRisk();
        if ($risk) {
            $lines[] = '';
            $lines[] = '<b>معرّضة للترك</b>';
            foreach (array_slice($risk, 0, 6) as $t) {
                $lines[] = '• ' . $e($t['name']) . ' — ' . $e($t['reasons'][0] ?? '');
            }
        }
        if (Ai::available(null)) {
            try {
                $r = Ai::ask('weekly', 'أنت محلل أعمال لمنصة اشتراكات لإدارة شبكات الإنترنت. اكتب بالعربية فقرة قصيرة من ثلاث إلى أربع جمل: ماذا حدث هذا الأسبوع، ما اللافت، وما المطلوب من المسؤول. بلا عناوين ولا تنسيق ولا أرقام غير موجودة في البيانات.',
                    [['role' => 'user', 'content' => json_encode(['ملخص' => $s, 'تنتهي_قريباً' => count($ending), 'معرّضة_للترك' => array_map(static fn ($t) => ['الشبكة' => $t['name'], 'الأسباب' => $t['reasons']], array_slice($risk, 0, 10))], JSON_UNESCAPED_UNICODE)]],
                    ['effort' => 'low', 'max_tokens' => 4000, 'timeout' => 60]);
                if ($r['ok']) {
                    $lines[] = '';
                    $lines[] = '<b>تحليل</b>';
                    $lines[] = $e($r['text']);
                }
            } catch (Throwable $e2) {
                log_message('warning', 'weekly ai: ' . Ai::scrub($e2->getMessage()));
            }
        }
        return implode("\n", $lines);
    }

    /** يُستدعى مع المهمة المجدولة: يرسل التقرير مرة في الأسبوع في اليوم المحدد */
    public static function scheduled(?int $now = null): bool
    {
        $now ??= time();
        if (!AdminNotify::configured() || !AdminNotify::on('weekly')) {
            return false;
        }
        $day = (int) Platform::setting('notify.weekly_day');
        $week = date('o-W', $now);
        if ((int) date('w', $now) !== $day || (int) date('G', $now) < 9 || Platform::setting('notify.weekly_last', '') === $week) {
            return false;
        }
        Platform::setSettings(['notify.weekly_last' => $week]);
        return AdminNotify::push(self::weeklyReport());
    }
}
