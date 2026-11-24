<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Gateway;

/**
 * تطبيق انتهاء الكروت والاشتراكات على أجهزة MikroTik.
 */
final class Enforcer
{
    /**
     * مراجعة كروت بعد تغيّر استهلاكها: تحديث الحالة، وحذف/إيقاف المنتهية من أجهزة MikroTik.
     * @param array $gateways بوابات متصلة مسبقاً (router_id => Gateway) للتنفيذ الفوري
     */
    public static function cards(array $cardIds, array $gateways = []): void
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        if (!$cardIds) {
            return;
        }
        $remove = Settings::bool('cards.remove_ended');
        $categories = [];
        foreach (array_chunk($cardIds, 500) as $chunk) {
            $cards = DB::rows('SELECT * FROM cards WHERE id IN (' . DB::in($chunk) . ')', $chunk);
            foreach ($cards as $card) {
                $catId = (int) $card['category_id'];
                $categories[$catId] ??= DB::row('SELECT * FROM categories WHERE id = ?', [$catId]);
                $cat = $categories[$catId];
                $status = CardRules::lifecycleStatus($card, $cat);
                if ($status !== $card['status']) {
                    $update = ['status' => $status, 'updated_at' => now()];
                    if (in_array($status, ['expired', 'depleted'], true) && !$card['ended_at']) {
                        $update['ended_at'] = now();
                    } elseif (!in_array($status, ['expired', 'depleted'], true)) {
                        $update['ended_at'] = null;
                    }
                    DB::update('cards', $update, 'id = ?', [$card['id']]);
                    $card['status'] = $status;
                }
                $reason = CardRules::blockReason($card, $cat);
                if (!in_array($reason, ['expired', 'time_depleted', 'data_depleted'], true)) {
                    continue;
                }
                $routers = DB::column("SELECT router_id FROM card_routers WHERE card_id = ? AND state = 'present'", [$card['id']]);
                foreach ($routers as $rid) {
                    $rid = (int) $rid;
                    $gw = $gateways[$rid] ?? null;
                    if ($remove) {
                        RouterTasks::dispatch($rid, 'card_remove', ['card_id' => (int) $card['id'], 'username' => $card['username']], 'card:' . $card['id'], $gw !== null, $gw);
                    } else {
                        RouterTasks::dispatch($rid, 'card_disable', ['username' => $card['username'], 'disabled' => true], 'card:' . $card['id'], $gw !== null, $gw);
                    }
                }
            }
        }
    }

    /** الكروت التي حان موعد انتهاء صلاحيتها */
    public static function expireDueCards(array $gateways = []): int
    {
        $ids = array_map('intval', DB::column(
            "SELECT id FROM cards WHERE status IN ('new','sold','active') AND expires_at IS NOT NULL AND expires_at <= ? LIMIT 2000",
            [now()]
        ));
        // كروت منتهية ما زالت موجودة على أجهزة MikroTik (لم يُنفَّذ حذفها بعد)
        $stale = array_map('intval', DB::column(
            "SELECT DISTINCT c.id FROM cards c JOIN card_routers cr ON cr.card_id = c.id AND cr.state = 'present'
             WHERE c.status IN ('expired','depleted') AND c.ended_at < ?
               AND NOT EXISTS (SELECT 1 FROM router_tasks t WHERE t.target = CONCAT('card:', c.id) AND t.status = 'pending')
             LIMIT 500",
            [date('Y-m-d H:i:s', time() - 600)]
        ));
        self::cards(array_merge($ids, $stale), $gateways);
        return count($ids);
    }

    /**
     * مزامنة حالة المشتركين مع أجهزة MikroTik: المنتهي يُعطَّل، والمجدَّد يُفعَّل.
     */
    public static function subscribers(): int
    {
        $count = 0;
        $plans = [];
        foreach (DB::rows('SELECT * FROM plans') as $p) {
            $plans[(int) $p['id']] = $p;
        }
        $rows = DB::rows("SELECT * FROM subscribers WHERE router_id IS NOT NULL AND router_state <> 'none'");
        foreach ($rows as $s) {
            $plan = $plans[(int) $s['plan_id']] ?? null;
            if (!$plan) {
                continue;
            }
            $shouldEnable = SubscriberRules::blockReason($s, $plan) === null;
            $isEnabled = $s['router_state'] === 'enabled';
            if ($shouldEnable !== $isEnabled) {
                $pending = DB::value("SELECT 1 FROM router_tasks WHERE target = ? AND status = 'pending' LIMIT 1", ['sub:' . $s['id']]);
                if (!$pending) {
                    RouterTasks::queue((int) $s['router_id'], 'sub_provision', ['subscriber_id' => (int) $s['id']], 'sub:' . $s['id']);
                    $count++;
                }
            }
        }
        return $count;
    }

    /** حساب استهلاك الفترة للمشتركين ذوي باقات البيانات وتطبيق السرعة المخفّضة */
    public static function subscriberQuotas(): void
    {
        $subs = DB::rows(
            'SELECT s.id, s.router_id, s.period_started_at, s.period_bytes, s.fup_active, s.plan_id, p.data_limit
             FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE p.data_limit IS NOT NULL AND s.status = \'active\''
        );
        foreach ($subs as $s) {
            $from = $s['period_started_at'] ?: '1970-01-01 00:00:00';
            $used = (int) DB::value('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM sessions WHERE subscriber_id = ? AND started_at >= ?', [$s['id'], $from]);
            $update = [];
            if ($used !== (int) $s['period_bytes']) {
                $update['period_bytes'] = $used;
            }
            $over = $used >= (int) $s['data_limit'];
            $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$s['plan_id']]);
            $hasFup = ProfileManager::fupRate($plan) !== null;
            if ($over && $hasFup && !(int) $s['fup_active']) {
                $update['fup_active'] = 1;
            }
            if (!$over && (int) $s['fup_active']) {
                $update['fup_active'] = 0;
            }
            if ($update) {
                DB::update('subscribers', $update, 'id = ?', [$s['id']]);
                if (isset($update['fup_active']) || ($over && !$hasFup)) {
                    if ($s['router_id']) {
                        RouterTasks::queue((int) $s['router_id'], 'sub_provision', ['subscriber_id' => (int) $s['id']], 'sub:' . $s['id']);
                        if ($plan['service'] === 'ppp') {
                            RouterTasks::queue((int) $s['router_id'], 'ppp_disconnect', ['name' => DB::value('SELECT username FROM subscribers WHERE id = ?', [$s['id']])], 'sub:' . $s['id']);
                        }
                    }
                }
            }
        }
    }

    /** للاستخدام الفوري بعد إجراء من الواجهة */
    public static function applyCard(int $cardId, ?Gateway $gw = null, ?int $routerId = null): void
    {
        self::cards([$cardId], $gw && $routerId ? [$routerId => $gw] : []);
    }
}
