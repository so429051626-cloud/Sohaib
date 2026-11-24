<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use RuntimeException;

/**
 * إدارة المشتركين: الإنشاء، التجديد، الدفعات، الإيقاف، وتطبيق ذلك على جهاز MikroTik.
 */
final class SubscriberService
{
    public const TX_TYPES = [
        'renewal'  => ['تجديد', 'badge-primary'],
        'payment'  => ['دفعة', 'badge-success'],
        'charge'   => ['قيد مستحق', 'badge-warning'],
        'discount' => ['خصم', 'badge-info'],
        'refund'   => ['استرداد', 'badge-danger'],
        'compensation' => ['تعويض انقطاع', 'badge-info'],
    ];

    public const METHODS = ['cash' => 'نقداً', 'transfer' => 'تحويل', 'wallet' => 'محفظة إلكترونية', 'other' => 'أخرى'];

    /** تطبيق المشترك على جهازه فوراً (أو جدولته) */
    public static function sync(int $id): array
    {
        $sub = DB::row('SELECT id, router_id FROM subscribers WHERE id = ?', [$id]);
        if (!$sub || !$sub['router_id']) {
            return ['ok' => true, 'queued' => false, 'error' => null];
        }
        return RouterTasks::dispatch((int) $sub['router_id'], 'sub_provision', ['subscriber_id' => $id], 'sub:' . $id);
    }

    /** إعادة حساب الرصيد المستحق من القيود */
    public static function recalcBalance(int $id): float
    {
        $sum = DB::row(
            "SELECT
               COALESCE(SUM(CASE WHEN type IN ('renewal','charge','refund') THEN amount ELSE 0 END), 0) AS debit,
               COALESCE(SUM(CASE WHEN type IN ('payment','discount') THEN amount ELSE 0 END), 0) AS credit
             FROM subscriber_transactions WHERE subscriber_id = ?",
            [$id]
        );
        $balance = round((float) $sum['debit'] - (float) $sum['credit'], 2);
        DB::update('subscribers', ['balance' => $balance], 'id = ?', [$id]);
        return $balance;
    }

    /**
     * تجديد الاشتراك.
     * @param int $periods عدد الفترات (كل فترة = مدة الباقة)
     * @param float|null $amount المبلغ المستحق (افتراضياً سعر الباقة × الفترات)
     * @param float $paid المدفوع الآن
     */
    public static function renew(int $id, int $planId, int $periods, ?float $amount, float $paid, string $method, ?string $notes, string $from = 'auto'): array
    {
        $sub = DB::row('SELECT * FROM subscribers WHERE id = ?', [$id]);
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$planId]);
        if (!$sub || !$plan) {
            throw new RuntimeException('بيانات غير صالحة.');
        }
        if ($periods < 1 || $periods > 36) {
            throw new RuntimeException('عدد الفترات يجب أن يكون بين 1 و36.');
        }
        $amount ??= round((float) $plan['price'] * $periods, 2);
        $now = time();
        $currentEnd = $sub['expires_at'] ? (int) strtotime($sub['expires_at']) : 0;
        $start = ($from === 'now' || $currentEnd < $now) ? $now : $currentEnd;
        $end = $start + (int) $plan['duration_days'] * 86400 * $periods;

        DB::transaction(static function () use ($sub, $plan, $periods, $amount, $paid, $method, $notes, $start, $end) {
            DB::insert('subscriber_transactions', [
                'subscriber_id' => $sub['id'], 'type' => 'renewal', 'amount' => $amount, 'plan_id' => $plan['id'],
                'period_from' => date('Y-m-d H:i:s', $start), 'period_to' => date('Y-m-d H:i:s', $end),
                'notes' => $notes ?: ($periods > 1 ? $periods . ' فترات' : null), 'employee_id' => Auth::id(), 'created_at' => now(),
            ]);
            if ($paid > 0) {
                DB::insert('subscriber_transactions', [
                    'subscriber_id' => $sub['id'], 'type' => 'payment', 'amount' => $paid, 'method' => $method,
                    'notes' => 'دفعة مع التجديد', 'employee_id' => Auth::id(), 'created_at' => now(),
                ]);
            }
            DB::update('subscribers', [
                'plan_id' => $plan['id'], 'expires_at' => date('Y-m-d H:i:s', $end), 'status' => 'active', 'suspend_reason' => null,
                'period_started_at' => date('Y-m-d H:i:s', $start), 'period_bytes' => 0, 'fup_active' => 0, 'updated_at' => now(),
            ], 'id = ?', [$sub['id']]);
        });
        self::recalcBalance($id);
        $result = self::sync($id);
        Audit::log('subscriber.renew', 'تجديد اشتراك ' . $sub['full_name'] . ' حتى ' . date('Y-m-d', $end), 'subscriber', $id, ['amount' => $amount, 'paid' => $paid]);
        try {
            CustomerBot::renewed($id, date('Y-m-d H:i:s', $end));
            SmsService::renewed($id, date('Y-m-d H:i:s', $end));
        } catch (\Throwable $e) {
            log_message('warning', 'customer bot renew notice: ' . $e->getMessage());
        }
        return ['expires_at' => date('Y-m-d H:i:s', $end), 'router' => $result];
    }

    public static function addTransaction(int $id, string $type, float $amount, ?string $method, ?string $notes): void
    {
        if (!isset(self::TX_TYPES[$type]) || in_array($type, ['renewal', 'compensation'], true)) {
            throw new RuntimeException('نوع القيد غير صالح.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر.');
        }
        DB::insert('subscriber_transactions', [
            'subscriber_id' => $id, 'type' => $type, 'amount' => round($amount, 2),
            'method' => $type === 'payment' ? $method : null, 'notes' => $notes,
            'employee_id' => Auth::id(), 'created_at' => now(),
        ]);
        self::recalcBalance($id);
        Audit::log('subscriber.payment', self::TX_TYPES[$type][0] . ' بقيمة ' . $amount, 'subscriber', $id);
    }

    public static function setStatus(int $id, string $status, ?string $reason): array
    {
        $sub = DB::row('SELECT * FROM subscribers WHERE id = ?', [$id]);
        if (!$sub) {
            throw new RuntimeException('المشترك غير موجود.');
        }
        DB::update('subscribers', ['status' => $status, 'suspend_reason' => $status === 'suspended' ? $reason : null, 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('subscriber.status', ($status === 'suspended' ? 'إيقاف' : 'تفعيل') . ' المشترك ' . $sub['full_name'], 'subscriber', $id, ['reason' => $reason]);
        return self::sync($id);
    }

    public static function disconnect(array $sub): void
    {
        $plan = DB::row('SELECT service FROM plans WHERE id = ?', [$sub['plan_id']]);
        $routers = DB::column('SELECT DISTINCT router_id FROM sessions WHERE subscriber_id = ? AND stopped_at IS NULL', [$sub['id']]);
        if (!$routers && $sub['router_id']) {
            $routers = [$sub['router_id']];
        }
        foreach ($routers as $rid) {
            if ($plan['service'] === 'ppp') {
                RouterTasks::dispatch((int) $rid, 'ppp_disconnect', ['name' => $sub['username']], 'sub:' . $sub['id']);
            } else {
                RouterTasks::dispatch((int) $rid, 'hs_disconnect', ['user' => $sub['username']], 'sub:' . $sub['id']);
            }
        }
        Audit::log('session.disconnect', 'فصل المشترك ' . $sub['full_name'], 'subscriber', (int) $sub['id']);
    }

    public static function delete(int $id): void
    {
        $sub = DB::row('SELECT s.*, p.service FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$id]);
        if (!$sub) {
            return;
        }
        $routers = array_map('intval', DB::column("SELECT router_id FROM subscriber_routers WHERE subscriber_id = ? AND state = 'present'", [$id]));
        if ($sub['router_id'] && !in_array((int) $sub['router_id'], $routers, true)) {
            $routers[] = (int) $sub['router_id'];
        }
        foreach ($routers as $rid) {
            RouterTasks::dispatch($rid, 'sub_remove', ['subscriber_id' => $id, 'username' => $sub['username'], 'service' => $sub['service']], 'sub:' . $id);
        }
        DB::transaction(static function () use ($id) {
            LoginRegistry::remove('subscriber', $id);
            DB::delete('subscribers', 'id = ?', [$id]);
        });
        Audit::log('subscriber.delete', 'حذف المشترك ' . $sub['full_name'], 'subscriber', $id);
    }
}
