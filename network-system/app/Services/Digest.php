<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;

/**
 * ملخص أداء الشبكة لفترة: للتقارير المجدولة وأوامر البوت.
 */
final class Digest
{
    /** @return array<string, mixed> */
    public static function period(string $from, string $to): array
    {
        $f = $from . ' 00:00:00';
        $t = $to . ' 23:59:59';
        $sales = DB::row("SELECT COUNT(*) AS ops, COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(paid), 0) AS paid
                          FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$f, $t]) ?? [];
        $tx = static fn (string $type) => DB::row('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM subscriber_transactions WHERE type = ? AND created_at BETWEEN ? AND ?', [$type, $f, $t]) ?? ['n' => 0, 'amount' => 0];
        $renew = $tx('renewal');
        $subPay = $tx('payment');
        $refund = $tx('refund');
        $distPay = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE created_at BETWEEN ? AND ?', [$f, $t]);
        $topCats = DB::rows("SELECT c.name, SUM(s.quantity) AS qty FROM sales s JOIN categories c ON c.id = s.category_id
                             WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY c.id, c.name ORDER BY qty DESC LIMIT 3", [$f, $t]);
        $days = max(1, Settings::int('subscribers.expiry_alert_days', 3));
        $routers = DB::rows('SELECT name, status FROM routers WHERE is_active = 1 ORDER BY name');
        $issued = (float) DB::value("SELECT COALESCE(SUM(-m.qty * COALESCE(m.unit_cost, i.unit_cost, 0)), 0) FROM inventory_movements m
                                     JOIN inventory_items i ON i.id = m.item_id WHERE m.type = 'out' AND m.created_at BETWEEN ? AND ?", [$f, $t]);
        return [
            'from' => $from, 'to' => $to,
            'sales_ops' => (int) ($sales['ops'] ?? 0),
            'cards_sold' => (int) ($sales['qty'] ?? 0),
            'sales_total' => (float) ($sales['total'] ?? 0),
            'renewals' => (int) $renew['n'],
            'renewals_amount' => (float) $renew['amount'],
            'collected' => (float) ($sales['paid'] ?? 0) + (float) $subPay['amount'] + $distPay - (float) $refund['amount'],
            'cards_activated' => (int) DB::value('SELECT COUNT(*) FROM cards WHERE first_login_at BETWEEN ? AND ?', [$f, $t]),
            'cards_available' => (int) DB::value("SELECT COUNT(*) FROM cards WHERE status = 'new' AND is_disabled = 0"),
            'top_categories' => $topCats,
            'new_subscribers' => (int) DB::value('SELECT COUNT(*) FROM subscribers WHERE created_at BETWEEN ? AND ?', [$f, $t]),
            'active_subscribers' => (int) DB::value("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND expires_at > ?", [now()]),
            'expiring' => (int) DB::value("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND expires_at > ? AND expires_at <= ?", [now(), date('Y-m-d H:i:s', time() + $days * 86400)]),
            'expiring_days' => $days,
            'expired' => (int) DB::value("SELECT COUNT(*) FROM subscribers WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?", [now()]),
            'due' => (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM distributors WHERE balance > 0') + (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM subscribers WHERE balance > 0'),
            'routers_total' => count($routers),
            'routers_online' => count(array_filter($routers, static fn ($r) => $r['status'] === 'online')),
            'routers_down' => array_values(array_map(static fn ($r) => $r['name'], array_filter($routers, static fn ($r) => $r['status'] === 'offline'))),
            'online_now' => (int) DB::value('SELECT COUNT(*) FROM sessions WHERE stopped_at IS NULL'),
            'sessions' => (int) DB::value('SELECT COUNT(*) FROM sessions WHERE started_at BETWEEN ? AND ?', [$f, $t]),
            'bytes' => (int) DB::value('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM sessions WHERE started_at BETWEEN ? AND ?', [$f, $t]),
            'inventory_issued' => $issued,
            'inventory_low' => count(Inventory::lowItems(100)),
            'alerts' => (int) DB::value('SELECT COUNT(*) FROM alerts WHERE created_at BETWEEN ? AND ?', [$f, $t]),
            'alerts_danger' => (int) DB::value("SELECT COUNT(*) FROM alerts WHERE level = 'danger' AND created_at BETWEEN ? AND ?", [$f, $t]),
        ];
    }

    /**
     * الملخص اليومي الذكي (الميزة 3): فقرة تحليل قصيرة تُضاف إلى التقرير اليومي — ماذا حدث، وما الغريب، وما المطلوب من المالك.
     * يقارن اليوم بمتوسط الأيام السبعة السابقة. لا يُكتب إن كان الذكاء غير متاح أو الخيار مطفأً، ويبقى التقرير كما هو.
     */
    public static function smart(string $day): ?string
    {
        if (!Settings::bool('telegram.ai_summary') || !Ai::available()) {
            return null;
        }
        $today = self::period($day, $day);
        $keys = ['cards_sold', 'sales_total', 'renewals', 'renewals_amount', 'collected', 'cards_activated', 'new_subscribers', 'sessions', 'bytes', 'alerts', 'alerts_danger'];
        $avg = array_fill_keys($keys, 0.0);
        for ($i = 1; $i <= 7; $i++) {
            $d = date('Y-m-d', strtotime($day . ' -' . $i . ' days'));
            $p = self::period($d, $d);
            foreach ($keys as $k) {
                $avg[$k] += (float) $p[$k] / 7;
            }
        }
        $f = $day . ' 00:00:00';
        $t = $day . ' 23:59:59';
        $facts = [
            'اليوم' => $day,
            'أرقام_اليوم' => array_intersect_key($today, array_flip(array_merge($keys, ['cards_available', 'active_subscribers', 'expiring', 'expiring_days', 'expired', 'due', 'routers_total', 'routers_online', 'routers_down', 'online_now', 'inventory_low']))),
            'متوسط_الأيام_السبعة_السابقة' => array_map(static fn ($v) => round($v, 1), $avg),
            'أكثر_الباقات_مبيعاً' => array_map(static fn ($c) => $c['name'] . ' (' . (int) $c['qty'] . ')', $today['top_categories']),
            'انقطاعات_اليوم' => array_map(static fn ($o) => $o['title'] . ' — ' . ((int) $o['minutes']) . ' دقيقة', DB::rows(
                'SELECT title, TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW())) AS minutes FROM outages WHERE started_at BETWEEN ? AND ? ORDER BY started_at LIMIT 10', [$f, $t])),
            'عمليات_بيع_ملغاة_اليوم' => (int) DB::value("SELECT COUNT(*) FROM sales WHERE status = 'cancelled' AND cancelled_at BETWEEN ? AND ?", [$f, $t]),
            'أعلى_ديون_الموزعين' => array_map(static fn ($r) => $r['name'] . ': ' . (float) $r['balance'], DB::rows('SELECT name, balance FROM distributors WHERE balance > 0 ORDER BY balance DESC LIMIT 3')),
            'تنبيهات_اليوم' => array_column(DB::rows('SELECT title FROM alerts WHERE created_at BETWEEN ? AND ? ORDER BY level = \'danger\' DESC, id DESC LIMIT 8', [$f, $t]), 'title'),
            'العملة' => (string) Settings::get('general.currency', '₪'),
        ];
        $system = 'أنت محلل يكتب لصاحب شبكة إنترنت فقرة قصيرة تُرفق بتقريره اليومي على تيليجرام. اكتب بالعربية بنص عادي بلا تنسيق ولا رموز تعبيرية، وبهذه الأسطر الثلاثة فقط:' . "\n"
            . 'ما حدث: جملة أو جملتان عن أبرز ما جرى اليوم مقارنة بمتوسط الأيام السابقة (اذكر الأرقام).' . "\n"
            . 'اللافت: أغرب ما في اليوم أو ما يستحق الانتباه (ارتفاع أو انخفاض غير معتاد، انقطاع، إلغاءات، ديون). إن لم يوجد شيء لافت فقل ذلك.' . "\n"
            . 'المطلوب منك: خطوة أو خطوتان عمليتان لصاحب الشبكة غداً.' . "\n"
            . 'اعتمد على البيانات المرفقة فقط ولا تخمّن. لا تتجاوز 600 حرف. التسمية «أجهزة MikroTik» وليس «راوتر».';
        $r = Ai::ask('daily_summary', $system, [['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            ['effort' => 'low', 'max_tokens' => 1200, 'timeout' => 60, 'actor' => 'التقرير اليومي']);
        if (!$r['ok']) {
            return null;
        }
        $text = trim((string) preg_replace(['/[*_#`]+/u', '/\n{3,}/'], ['', "\n\n"], $r['text']));
        return $text !== '' ? mb_substr($text, 0, 900) : null;
    }

    /** نص التقرير بتنسيق تيليجرام (رسمي، دون رموز تعبيرية)؛ مع $smart تُضاف فقرة التحليل الذكي إن أمكن */
    public static function telegram(string $title, string $from, string $to, bool $smart = false): string
    {
        $d = self::period($from, $to);
        $x = static fn ($s) => Telegram::esc($s);
        $period = $from === $to ? $from : $from . ' إلى ' . $to;
        $lines = [];
        $lines[] = '<b>' . $x($title) . '</b>';
        $lines[] = $x(Settings::networkName()) . ' — <code>' . $x($period) . '</code>';
        $lines[] = '';
        $lines[] = '<b>المالية</b>';
        $lines[] = '- مبيعات الكروت: ' . num($d['cards_sold']) . ' كرت بقيمة <b>' . $x(money($d['sales_total'])) . '</b>';
        $lines[] = '- تجديد الاشتراكات: ' . num($d['renewals']) . ' بقيمة <b>' . $x(money($d['renewals_amount'])) . '</b>';
        $lines[] = '- المبالغ المحصّلة: <b>' . $x(money($d['collected'])) . '</b>';
        if ($d['due'] > 0) {
            $lines[] = '- المستحقات القائمة: ' . $x(money($d['due']));
        }
        $lines[] = '';
        $lines[] = '<b>الكروت</b>';
        $lines[] = '- الكروت المفعّلة: ' . num($d['cards_activated']);
        $lines[] = '- المتاح للبيع: ' . num($d['cards_available']);
        if ($d['top_categories']) {
            $lines[] = '- الأكثر مبيعاً: ' . implode('، ', array_map(static fn ($c) => $x($c['name']) . ' (' . num((int) $c['qty']) . ')', $d['top_categories']));
        }
        $lines[] = '';
        $lines[] = '<b>المشتركون</b>';
        $lines[] = '- الفعّالون: ' . num($d['active_subscribers']) . ($d['new_subscribers'] ? '، الجدد: ' . num($d['new_subscribers']) : '');
        $lines[] = '- تنتهي خلال ' . num($d['expiring_days']) . ' أيام: ' . num($d['expiring']);
        $lines[] = '- المنتهية: ' . num($d['expired']);
        $lines[] = '';
        $lines[] = '<b>الشبكة</b>';
        $lines[] = '- أجهزة MikroTik المتصلة: ' . num($d['routers_online']) . ' من ' . num($d['routers_total']);
        if ($d['routers_down']) {
            $lines[] = '- المنقطعة: ' . $x(implode('، ', array_slice($d['routers_down'], 0, 8)));
        }
        $lines[] = '- المتصلون الآن: ' . num($d['online_now']);
        $lines[] = '- الجلسات: ' . num($d['sessions']) . '، الاستهلاك: ' . $x(fmt_bytes($d['bytes']));
        if ($d['inventory_issued'] > 0 || $d['inventory_low'] > 0) {
            $lines[] = '';
            $lines[] = '<b>المخزون</b>';
            if ($d['inventory_issued'] > 0) {
                $lines[] = '- قيمة المصروف: ' . $x(money($d['inventory_issued']));
            }
            if ($d['inventory_low'] > 0) {
                $lines[] = '- أصناف منخفضة: ' . num($d['inventory_low']);
            }
        }
        if ($d['alerts'] > 0) {
            $lines[] = '';
            $lines[] = '<b>التنبيهات:</b> ' . num($d['alerts']) . ($d['alerts_danger'] ? '، منها حرجة: ' . num($d['alerts_danger']) : '');
        }
        $analysis = $smart && $from === $to ? self::smart($from) : null;
        if ($analysis !== null) {
            $lines[] = '';
            $lines[] = '<b>تحليل ذكي</b>';
            foreach (preg_split('/\R/u', $analysis) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // «ما حدث:» و«اللافت:» و«المطلوب منك:» بخط عريض
                $lines[] = preg_match('/^(ما حدث|اللافت|المطلوب منك)\s*[:：]\s*(.*)$/u', $line, $m)
                    ? '<b>' . $x($m[1]) . ':</b> ' . $x($m[2])
                    : $x($line);
            }
        }
        $lines[] = '';
        $lines[] = '<i>MADIX NEXUS</i>';
        return implode("\n", $lines);
    }
}
