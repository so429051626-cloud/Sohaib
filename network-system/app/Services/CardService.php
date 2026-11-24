<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use RuntimeException;

/**
 * العمليات على الكروت (فردية وجماعية) مع تطبيقها على أجهزة MikroTik.
 * العمليات الكبيرة تتحول إلى عملية طويلة تُتابَع من صفحة العمليات.
 */
final class CardService
{
    /** الحد الذي بعده تُنفَّذ تغييرات أجهزة MikroTik كعملية طويلة */
    private const IMMEDIATE_LIMIT = 15;

    public static function find(int $id): ?array
    {
        return DB::row(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, b.name AS batch_name
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id WHERE c.id = ?',
            [$id]
        );
    }

    /** أزواج (جهاز MikroTik، كرت) الحاضرة لكروت */
    private static function presentPairs(array $cardIds): array
    {
        $pairs = [];
        foreach (array_chunk(array_map('intval', $cardIds), 1000) as $chunk) {
            foreach (DB::rows("SELECT cr.router_id, cr.card_id, c.username FROM card_routers cr JOIN cards c ON c.id = cr.card_id WHERE cr.state = 'present' AND cr.card_id IN (" . DB::in($chunk) . ')', $chunk) as $r) {
                $pairs[] = [(int) $r['router_id'], (int) $r['card_id'], $r['username']];
            }
        }
        return $pairs;
    }

    /**
     * تطبيق إجراء على أجهزة MikroTik: فوري للأعداد الصغيرة، أو عملية طويلة.
     * @return int|null رقم العملية الطويلة إن أُنشئت
     */
    private static function applyToRouters(array $cardIds, string $mode, string $title): ?int
    {
        $pairs = self::presentPairs($cardIds);
        if (!$pairs) {
            return null;
        }
        if (count($pairs) <= self::IMMEDIATE_LIMIT) {
            foreach ($pairs as [$routerId, $cardId, $username]) {
                if ($mode === 'remove') {
                    RouterTasks::dispatch($routerId, 'card_remove', ['card_id' => $cardId, 'username' => $username], 'card:' . $cardId);
                } else {
                    RouterTasks::dispatch($routerId, 'card_provision', ['card_id' => $cardId], 'card:' . $cardId);
                    if ($mode === 'disable') {
                        RouterTasks::dispatch($routerId, 'hs_disconnect', ['user' => $username], 'card:' . $cardId);
                    }
                }
            }
            return null;
        }
        if ($mode === 'remove') {
            return JobRunner::create('remove_cards', $title, ['items' => array_map(static fn ($p) => [$p[0], $p[1], $p[2]], $pairs)], count($pairs), Auth::id());
        }
        return JobRunner::create('sync_cards', $title, ['card_ids' => array_values(array_unique(array_column($pairs, 1)))], count($pairs), Auth::id());
    }

    public static function disable(array $ids, ?string $reason = null): array
    {
        $ids = array_map('intval', $ids);
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += DB::query('UPDATE cards SET is_disabled = 1, disabled_reason = ?, updated_at = ? WHERE is_disabled = 0 AND id IN (' . DB::in($chunk) . ')', array_merge([$reason], [now()], $chunk))->rowCount();
        }
        $job = self::applyToRouters($ids, 'disable', 'إيقاف كروت على أجهزة MikroTik');
        Audit::log('cards.bulk', 'إيقاف ' . $n . ' كرت', 'card', count($ids) === 1 ? $ids[0] : null, ['reason' => $reason]);
        return ['count' => $n, 'job' => $job];
    }

    public static function enable(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += DB::query('UPDATE cards SET is_disabled = 0, disabled_reason = NULL, updated_at = ? WHERE is_disabled = 1 AND id IN (' . DB::in($chunk) . ')', array_merge([now()], $chunk))->rowCount();
        }
        $job = self::applyToRouters($ids, 'sync', 'تفعيل كروت على أجهزة MikroTik');
        Audit::log('cards.bulk', 'تفعيل ' . $n . ' كرت', 'card', count($ids) === 1 ? $ids[0] : null);
        return ['count' => $n, 'job' => $job];
    }

    public static function delete(array $ids): array
    {
        $ids = array_map('intval', $ids);
        // باقات الحسابات الخاصة المحذوفة تُنظَّف بعد حذف كروتها
        $custom = CustomAccounts::categoriesOf($ids);
        $job = self::applyToRouters($ids, 'remove', 'حذف كروت من أجهزة MikroTik');
        $n = 0;
        DB::transaction(static function () use ($ids, &$n) {
            LoginRegistry::removeCards($ids);
            foreach (array_chunk($ids, 1000) as $chunk) {
                $n += DB::query('DELETE FROM cards WHERE id IN (' . DB::in($chunk) . ')', $chunk)->rowCount();
            }
            // تحديث كميات الدفعات
            DB::query('UPDATE batches b SET quantity = (SELECT COUNT(*) FROM cards c WHERE c.batch_id = b.id)');
        });
        if ($custom) {
            CustomAccounts::purge($custom);
        }
        Audit::log('cards.bulk', 'حذف ' . $n . ' كرت', 'card');
        return ['count' => $n, 'job' => $job];
    }

    public static function move(array $ids, int $categoryId): array
    {
        $cat = DB::row('SELECT id, name FROM categories WHERE id = ? AND is_custom = 0', [$categoryId]);
        if (!$cat) {
            throw new RuntimeException('الباقة غير موجودة.');
        }
        // الحسابات الخاصة لها حدودها الخاصة: لا تُنقل إلى باقة (تُعدَّل من صفحتها)
        $ids = array_values(array_diff(array_map('intval', $ids), array_values(CustomAccounts::categoriesOf($ids))));
        if (!$ids) {
            throw new RuntimeException('الحسابات الخاصة لا تُنقل إلى باقة؛ عدّل حدودها من صفحة الحسابات الخاصة.');
        }
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += DB::query('UPDATE cards SET category_id = ?, updated_at = ? WHERE id IN (' . DB::in($chunk) . ')', array_merge([$categoryId, now()], $chunk))->rowCount();
        }
        Enforcer::cards($ids);
        $job = self::applyToRouters($ids, 'sync', 'نقل كروت إلى باقة ' . $cat['name']);
        Audit::log('cards.bulk', 'نقل ' . $n . ' كرت إلى باقة ' . $cat['name'], 'card');
        return ['count' => $n, 'job' => $job];
    }

    /** تمديد: إضافة وقت/بيانات/صلاحية */
    public static function extend(array $ids, int $addTime, int $addData, int $addValidity): array
    {
        if ($addTime <= 0 && $addData <= 0 && $addValidity <= 0) {
            throw new RuntimeException('حدد مقدار التمديد.');
        }
        $ids = array_map('intval', $ids);
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += DB::query(
                'UPDATE cards SET bonus_time = bonus_time + ?, bonus_data = bonus_data + ?, bonus_validity = bonus_validity + ?,
                        expires_at = IF(expires_at IS NULL, NULL, DATE_ADD(expires_at, INTERVAL ? SECOND)), updated_at = ?
                 WHERE id IN (' . DB::in($chunk) . ')',
                array_merge([$addTime, $addData, $addValidity, $addValidity, now()], $chunk)
            )->rowCount();
        }
        Enforcer::cards($ids);
        $job = self::applyToRouters($ids, 'sync', 'تمديد كروت');
        Audit::log('cards.bulk', 'تمديد ' . $n . ' كرت', 'card', count($ids) === 1 ? $ids[0] : null, ['time' => $addTime, 'data' => $addData, 'validity' => $addValidity]);
        return ['count' => $n, 'job' => $job];
    }

    public static function markPrinted(array $ids, bool $printed = true): int
    {
        $n = 0;
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            $n += $printed
                ? DB::query('UPDATE cards SET printed_at = COALESCE(printed_at, ?), print_count = print_count + 1 WHERE id IN (' . DB::in($chunk) . ')', array_merge([now()], $chunk))->rowCount()
                : DB::query('UPDATE cards SET printed_at = NULL WHERE id IN (' . DB::in($chunk) . ')', $chunk)->rowCount();
        }
        return $n;
    }

    public static function unbind(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $n = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $n += DB::query('UPDATE cards SET bound_mac = NULL, updated_at = ? WHERE bound_mac IS NOT NULL AND id IN (' . DB::in($chunk) . ')', array_merge([now()], $chunk))->rowCount();
        }
        $job = self::applyToRouters($ids, 'sync', 'فك ربط كروت بالأجهزة');
        Audit::log('cards.bulk', 'فك ربط الجهاز لـ ' . $n . ' كرت', 'card', count($ids) === 1 ? $ids[0] : null);
        return ['count' => $n, 'job' => $job];
    }

    /** إعادة الكرت كأنه جديد (الاستهلاك والتواريخ والجهاز) مع الإبقاء على بيانات البيع */
    public static function reset(int $id): void
    {
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$id]);
        if (!$card) {
            throw new RuntimeException('الكرت غير موجود.');
        }
        self::applyToRouters([$id], 'remove', 'تصفير كرت');
        DB::transaction(static function () use ($card) {
            CardUsage::reset((int) $card['id']);
            DB::update('cards', [
                'first_login_at' => null, 'last_login_at' => null, 'expires_at' => null, 'ended_at' => null,
                'bound_mac' => null, 'bonus_time' => 0, 'bonus_data' => 0, 'bonus_validity' => 0,
                'status' => $card['sale_id'] || $card['distributor_id'] || $card['sold_at'] ? 'sold' : 'new',
                'updated_at' => now(),
            ], 'id = ?', [$card['id']]);
        });
        Audit::log('card.update', 'تصفير الكرت ' . $card['username'], 'card', (int) $card['id']);
    }

    public static function changePassword(int $id, string $password): void
    {
        $password = trim(latin_digits($password));
        if ($password === '' || strlen($password) > 64 || preg_match('/[\s"\'\\\\]/', $password)) {
            throw new RuntimeException('كلمة المرور غير صالحة.');
        }
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$id]);
        if (!$card) {
            throw new RuntimeException('الكرت غير موجود.');
        }
        DB::update('cards', ['password' => $password, 'updated_at' => now()], 'id = ?', [$id]);
        RouterTasks::forCardRouters($card, 'card_set', ['username' => $card['username'], 'props' => ['password' => $password]]);
        Audit::log('card.password', 'تغيير كلمة مرور الكرت ' . $card['username'], 'card', $id);
    }

    public static function updateNotes(int $id, ?string $notes): void
    {
        DB::update('cards', ['notes' => $notes !== null ? mb_substr($notes, 0, 255) : null, 'updated_at' => now()], 'id = ?', [$id]);
    }

    /** فصل جلسات الكرت الحالية على كل أجهزة MikroTik */
    public static function disconnect(array $card): int
    {
        $routers = DB::column('SELECT DISTINCT router_id FROM sessions WHERE card_id = ? AND stopped_at IS NULL', [$card['id']]);
        foreach ($routers as $rid) {
            RouterTasks::dispatch((int) $rid, 'hs_disconnect', ['user' => $card['username']], 'card:' . $card['id']);
        }
        Audit::log('session.disconnect', 'فصل جلسات الكرت ' . $card['username'], 'card', (int) $card['id']);
        return count($routers);
    }

    /** رفع كروت إلى أجهزة MikroTik مسبقاً (تعمل حتى دون اتصال بالنظام) */
    public static function pushToRouters(array $cardIds, array $routerIds): int
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        $routerIds = array_values(array_unique(array_map('intval', $routerIds)));
        if (!$cardIds || !$routerIds) {
            throw new RuntimeException('حدد الكروت وأجهزة MikroTik.');
        }
        return JobRunner::create('push_cards', 'رفع ' . count($cardIds) . ' كرت إلى ' . count($routerIds) . ' جهاز MikroTik', ['card_ids' => $cardIds, 'router_ids' => $routerIds], count($cardIds) * count($routerIds), Auth::id());
    }
}
