<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use RuntimeException;

/**
 * بيع الكروت (مباشر أو لموزع) وحسابات الموزعين.
 */
final class SalesService
{
    /**
     * تسجيل عملية بيع.
     * @param array|null $cardIds كروت محددة؛ وإلا تُختار أقدم الكروت المتاحة من الباقة
     */
    public static function sell(string $type, ?int $distributorId, int $categoryId, int $quantity, ?float $unitPrice, float $discount, float $paid, ?string $customer, ?string $notes, ?array $cardIds = null): array
    {
        if (!in_array($type, ['direct', 'distributor'], true)) {
            throw new RuntimeException('نوع البيع غير صالح.');
        }
        $category = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [$categoryId]);
        if (!$category) {
            throw new RuntimeException('الباقة غير موجودة.');
        }
        $distributor = null;
        if ($type === 'distributor') {
            $distributor = DB::row('SELECT * FROM distributors WHERE id = ? AND is_active = 1', [(int) $distributorId]);
            if (!$distributor) {
                throw new RuntimeException('اختر موزعاً فعالاً.');
            }
        }
        $discount = max(0.0, min(100.0, $discount));

        return DB::transaction(static function () use ($type, $distributor, $category, $quantity, $unitPrice, $discount, $paid, $customer, $notes, $cardIds) {
            if ($cardIds) {
                $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
                $cards = DB::column(
                    "SELECT id FROM cards WHERE category_id = ? AND status = 'new' AND is_disabled = 0 AND id IN (" . DB::in($cardIds) . ') ORDER BY id FOR UPDATE',
                    array_merge([$category['id']], $cardIds)
                );
            } else {
                if ($quantity < 1) {
                    throw new RuntimeException('الكمية يجب أن تكون أكبر من صفر.');
                }
                $cards = DB::column(
                    "SELECT id FROM cards WHERE category_id = ? AND status = 'new' AND is_disabled = 0 ORDER BY id LIMIT " . (int) $quantity . ' FOR UPDATE',
                    [$category['id']]
                );
                if (count($cards) < $quantity) {
                    throw new RuntimeException('المتاح من باقة «' . $category['name'] . '» ' . count($cards) . ' كرت فقط.');
                }
            }
            if (!$cards) {
                throw new RuntimeException('لا توجد كروت متاحة للبيع ضمن التحديد.');
            }
            $qty = count($cards);
            $price = $unitPrice ?? (float) $category['price'];
            if ($type === 'distributor' && $unitPrice === null && $discount <= 0) {
                $discount = (float) $distributor['discount_percent'];
            }
            $net = round($price * (1 - $discount / 100), 2);
            $total = round($net * $qty, 2);
            $paid = round(max(0.0, min($paid, $total)), 2);
            $saleId = DB::insert('sales', [
                'type' => $type, 'distributor_id' => $distributor['id'] ?? null, 'category_id' => $category['id'],
                'quantity' => $qty, 'unit_price' => $price, 'discount_percent' => $discount, 'total' => $total,
                'paid' => $type === 'direct' ? $total : $paid, 'customer_name' => $customer, 'notes' => $notes,
                'status' => 'completed', 'employee_id' => Auth::id(), 'created_at' => now(),
            ]);
            foreach (array_chunk($cards, 1000) as $chunk) {
                DB::query(
                    "UPDATE cards SET status = 'sold', sale_id = ?, distributor_id = ?, sold_at = ?, sale_price = ?, updated_at = ? WHERE status = 'new' AND id IN (" . DB::in($chunk) . ')',
                    array_merge([$saleId, $distributor['id'] ?? null, now(), $net, now()], $chunk)
                );
            }
            if ($distributor) {
                self::recalcDistributor((int) $distributor['id']);
            }
            Audit::log('sale.create', 'بيع ' . $qty . ' كرت ' . $category['name'] . ($distributor ? ' للموزع ' . $distributor['name'] : ''), 'sale', $saleId, ['total' => $total, 'paid' => $paid]);
            return ['sale_id' => $saleId, 'quantity' => $qty, 'total' => $total];
        });
    }

    /** إلغاء بيع: يعيد الكروت غير المستخدمة إلى المخزون */
    public static function cancel(int $saleId): void
    {
        $sale = DB::row('SELECT * FROM sales WHERE id = ?', [$saleId]);
        if (!$sale || $sale['status'] !== 'completed') {
            throw new RuntimeException('عملية البيع غير موجودة أو ملغاة مسبقاً.');
        }
        $used = (int) DB::value('SELECT COUNT(*) FROM cards WHERE sale_id = ? AND first_login_at IS NOT NULL', [$saleId]);
        if ($used > 0) {
            throw new RuntimeException('لا يمكن الإلغاء: ' . $used . ' كرت من هذه العملية استُخدم فعلاً.');
        }
        DB::transaction(static function () use ($sale) {
            DB::query("UPDATE cards SET status = 'new', sale_id = NULL, distributor_id = NULL, sold_at = NULL, sale_price = NULL, updated_at = ? WHERE sale_id = ?", [now(), $sale['id']]);
            DB::update('sales', ['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => Auth::id()], 'id = ?', [$sale['id']]);
            if ($sale['distributor_id']) {
                self::recalcDistributor((int) $sale['distributor_id']);
            }
        });
        Audit::log('sale.cancel', 'إلغاء عملية البيع رقم ' . $saleId, 'sale', $saleId);
    }

    public static function payment(int $distributorId, float $amount, ?string $method, ?string $notes): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر.');
        }
        DB::insert('distributor_payments', [
            'distributor_id' => $distributorId, 'amount' => round($amount, 2), 'method' => $method,
            'notes' => $notes, 'employee_id' => Auth::id(), 'created_at' => now(),
        ]);
        self::recalcDistributor($distributorId);
        Audit::log('distributor.payment', 'دفعة موزع بقيمة ' . $amount, 'distributor', $distributorId);
    }

    /** رصيد الموزع المستحق = مجموع المتبقي من المبيعات − الدفعات */
    public static function recalcDistributor(int $id): float
    {
        $due = (float) DB::value("SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE distributor_id = ? AND status = 'completed'", [$id]);
        $paid = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE distributor_id = ?', [$id]);
        $balance = round($due - $paid, 2);
        DB::update('distributors', ['balance' => $balance, 'updated_at' => now()], 'id = ?', [$id]);
        return $balance;
    }

    /** كشف حساب الموزع مرتّباً زمنياً مع الرصيد التراكمي */
    public static function statement(int $distributorId): array
    {
        $rows = [];
        foreach (DB::rows("SELECT s.*, c.name AS category_name FROM sales s JOIN categories c ON c.id = s.category_id WHERE s.distributor_id = ? AND s.status = 'completed'", [$distributorId]) as $s) {
            $rows[] = ['date' => $s['created_at'], 'type' => 'sale', 'ref' => $s['id'], 'description' => $s['quantity'] . ' × ' . $s['category_name'], 'debit' => (float) $s['total'], 'credit' => 0.0];
            if ((float) $s['paid'] > 0) {
                $rows[] = ['date' => $s['created_at'], 'type' => 'sale_paid', 'ref' => $s['id'], 'description' => 'مدفوع عند البيع', 'debit' => 0.0, 'credit' => (float) $s['paid']];
            }
        }
        foreach (DB::rows('SELECT * FROM distributor_payments WHERE distributor_id = ?', [$distributorId]) as $p) {
            $rows[] = ['date' => $p['created_at'], 'type' => 'payment', 'ref' => $p['id'], 'description' => 'دفعة' . ($p['notes'] ? ' — ' . $p['notes'] : ''), 'debit' => 0.0, 'credit' => (float) $p['amount']];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        $balance = 0.0;
        foreach ($rows as &$r) {
            $balance += $r['debit'] - $r['credit'];
            $r['balance'] = round($balance, 2);
        }
        return $rows;
    }
}
