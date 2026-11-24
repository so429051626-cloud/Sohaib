<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use RuntimeException;

/**
 * المخزون: الأصناف، الأجهزة المرقّمة، وحركات الإدخال والصرف.
 * الأصناف المرقّمة رصيدها = عدد وحداتها في المخزن.
 */
final class Inventory
{
    public const CATEGORIES = [
        'router'    => ['أجهزة MikroTik', 'router'],
        'antenna'   => ['أجهزة بث وأنتينات', 'radio-tower'],
        'ap'        => ['نقاط وصول', 'wifi'],
        'switch'    => ['سويتشات', 'network'],
        'cable'     => ['كوابل', 'cable'],
        'connector' => ['وصلات وقطع', 'plug'],
        'power'     => ['طاقة ومحولات', 'zap'],
        'tool'      => ['عُدد وأدوات', 'wrench'],
        'other'     => ['أخرى', 'package'],
    ];

    public const UNITS = [
        'piece' => 'قطعة',
        'meter' => 'متر',
        'box'   => 'علبة',
        'roll'  => 'لفّة',
    ];

    public const UNIT_STATUS = [
        'stock'     => ['في المخزن', 'badge-info'],
        'installed' => ['مركّب', 'badge-success'],
        'faulty'    => ['تالف', 'badge-danger'],
        'lost'      => ['مفقود', 'badge-warning'],
    ];

    public const MOVE_TYPES = [
        'in'     => ['إدخال', 'badge-success', 'package-plus'],
        'out'    => ['صرف', 'badge-info', 'package-minus'],
        'return' => ['إرجاع', 'badge-outline', 'undo-2'],
        'damage' => ['تالف / فقد', 'badge-danger', 'triangle-alert'],
        'adjust' => ['تسوية جرد', 'badge-warning', 'sliders-horizontal'],
    ];

    public static function item(int $id): ?array
    {
        return DB::row('SELECT * FROM inventory_items WHERE id = ?', [$id]);
    }

    public static function fmtQty(mixed $qty): string
    {
        $q = (float) $qty;
        return fmod($q, 1.0) === 0.0 ? number_format($q, 0, '.', ',') : rtrim(rtrim(number_format($q, 2, '.', ','), '0'), '.');
    }

    public static function isLow(array $item): bool
    {
        return $item['min_qty'] !== null && (float) $item['quantity'] <= (float) $item['min_qty'];
    }

    // ------------------------------------------------------------------
    // الأصناف غير المرقّمة (كميات)
    // ------------------------------------------------------------------

    /**
     * حركة كمية على صنف غير مرقّم.
     * type: in|out|return|damage بكمية موجبة، adjust بالرصيد الفعلي بعد الجرد.
     */
    public static function move(int $itemId, string $type, float $qty, array $meta = []): int
    {
        if (!isset(self::MOVE_TYPES[$type])) {
            throw new RuntimeException('نوع الحركة غير صحيح.');
        }
        return DB::transaction(static function () use ($itemId, $type, $qty, $meta) {
            $item = DB::row('SELECT * FROM inventory_items WHERE id = ? FOR UPDATE', [$itemId]);
            if (!$item) {
                throw new RuntimeException('الصنف غير موجود.');
            }
            if ((int) $item['track_serial']) {
                throw new RuntimeException('هذا الصنف مرقّم؛ تُدار حركته من الأجهزة.');
            }
            $current = (float) $item['quantity'];
            if ($type === 'adjust') {
                if ($qty < 0) {
                    throw new RuntimeException('الرصيد الفعلي لا يكون سالباً.');
                }
                $delta = round($qty - $current, 2);
                if ($delta == 0.0) {
                    throw new RuntimeException('الرصيد الفعلي مطابق للرصيد الحالي.');
                }
            } else {
                if ($qty <= 0) {
                    throw new RuntimeException('أدخل كمية أكبر من صفر.');
                }
                $delta = in_array($type, ['out', 'damage'], true) ? -$qty : $qty;
                if ($current + $delta < 0) {
                    throw new RuntimeException('الكمية أكبر من الرصيد المتوفر (' . self::fmtQty($current) . ').');
                }
            }
            $balance = round($current + $delta, 2);
            $update = ['quantity' => $balance, 'updated_at' => now()];
            $cost = isset($meta['unit_cost']) && $meta['unit_cost'] !== null && $meta['unit_cost'] !== '' ? (float) $meta['unit_cost'] : null;
            if ($type === 'in' && $cost !== null) {
                $update['unit_cost'] = $cost;
            }
            DB::update('inventory_items', $update, 'id = ?', [$itemId]);
            $id = self::log($itemId, null, $type, $delta, $balance, $meta + ['unit_cost' => $cost ?? $item['unit_cost']]);
            self::checkLow($itemId);
            return $id;
        });
    }

    // ------------------------------------------------------------------
    // الأجهزة المرقّمة
    // ------------------------------------------------------------------

    /**
     * إدخال أجهزة مرقّمة: كل سطر «الرقم التسلسلي [MAC]».
     * @return array{added:int, skipped:string[]}
     */
    public static function addUnits(int $itemId, string $lines, array $meta = []): array
    {
        $item = self::item($itemId);
        if (!$item || !(int) $item['track_serial']) {
            throw new RuntimeException('الصنف غير مرقّم.');
        }
        $entries = self::parseUnits($lines);
        if (!$entries) {
            throw new RuntimeException('أدخل رقماً تسلسلياً واحداً على الأقل.');
        }
        $added = 0;
        $skipped = [];
        DB::transaction(static function () use ($itemId, $entries, $meta, $item, &$added, &$skipped) {
            foreach ($entries as [$serial, $mac]) {
                if (DB::value('SELECT id FROM inventory_units WHERE item_id = ? AND serial = ?', [$itemId, $serial])) {
                    $skipped[] = $serial;
                    continue;
                }
                $unitId = DB::insert('inventory_units', [
                    'item_id' => $itemId, 'serial' => $serial, 'mac' => $mac, 'status' => 'stock',
                    'notes' => null, 'created_at' => now(),
                ]);
                $balance = self::recount($itemId);
                self::log($itemId, $unitId, 'in', 1, $balance, $meta + ['unit_cost' => $meta['unit_cost'] ?? $item['unit_cost']]);
                $added++;
            }
            $cost = $meta['unit_cost'] ?? null;
            if ($added && $cost !== null && $cost !== '') {
                DB::update('inventory_items', ['unit_cost' => (float) $cost], 'id = ?', [$itemId]);
            }
        });
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return array<int, array{0:string,1:?string}> */
    public static function parseUnits(string $text): array
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(latin_digits($line));
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/[\s,;\t]+/u', $line);
            $serial = mb_substr((string) $parts[0], 0, 80);
            $mac = null;
            foreach (array_slice($parts, 1) as $p) {
                $norm = strtoupper(str_replace('-', ':', $p));
                if (preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $norm)) {
                    $mac = $norm;
                    break;
                }
            }
            if ($mac === null && preg_match('/^([0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/i', $serial)) {
                $mac = strtoupper(str_replace('-', ':', $serial));
            }
            if (isset($seen[$serial])) {
                continue;
            }
            $seen[$serial] = true;
            $out[] = [$serial, $mac];
        }
        return $out;
    }

    /**
     * تغيير حالة جهاز مرقّم.
     * action: install|return|faulty|lost
     */
    public static function unitAction(int $unitId, string $action, array $meta = []): void
    {
        DB::transaction(static function () use ($unitId, $action, $meta) {
            $unit = DB::row('SELECT * FROM inventory_units WHERE id = ? FOR UPDATE', [$unitId]);
            if (!$unit) {
                throw new RuntimeException('الجهاز غير موجود.');
            }
            $from = $unit['status'];
            [$to, $type] = match ($action) {
                'install' => ['installed', 'out'],
                'return'  => ['stock', 'return'],
                'faulty'  => ['faulty', 'damage'],
                'lost'    => ['lost', 'damage'],
                default   => throw new RuntimeException('إجراء غير صحيح.'),
            };
            if ($action === 'install' && $from !== 'stock') {
                throw new RuntimeException('الجهاز ليس في المخزن.');
            }
            if ($action === 'return' && $from === 'stock') {
                throw new RuntimeException('الجهاز في المخزن بالفعل.');
            }
            if ($from === $to) {
                throw new RuntimeException('الجهاز بهذه الحالة بالفعل.');
            }
            $update = ['status' => $to, 'updated_at' => now()];
            if ($action === 'install') {
                $siteId = $meta['site_id'] ?? null;
                $subId = $meta['subscriber_id'] ?? null;
                $routerId = $meta['router_id'] ?? null;
                if (!$siteId && !$subId && !$routerId && trim((string) ($meta['receiver'] ?? '')) === '') {
                    throw new RuntimeException('حدّد مكان التركيب أو المستلم.');
                }
                $update += ['site_id' => $siteId, 'subscriber_id' => $subId, 'router_id' => $routerId, 'installed_at' => now()];
            } elseif ($to === 'stock') {
                $update += ['site_id' => null, 'subscriber_id' => null, 'router_id' => null, 'installed_at' => null];
            }
            DB::update('inventory_units', $update, 'id = ?', [$unitId]);
            $delta = ($to === 'stock' ? 1 : 0) - ($from === 'stock' ? 1 : 0);
            $balance = self::recount((int) $unit['item_id']);
            $notes = trim((string) ($meta['notes'] ?? ''));
            if ($action === 'lost') {
                $notes = trim('مفقود. ' . $notes);
            }
            self::log((int) $unit['item_id'], $unitId, $type, $delta, $balance, [
                'site_id' => $meta['site_id'] ?? ($action === 'return' ? $unit['site_id'] : null),
                'subscriber_id' => $meta['subscriber_id'] ?? ($action === 'return' ? $unit['subscriber_id'] : null),
                'receiver' => $meta['receiver'] ?? null,
                'notes' => $notes,
            ]);
            self::checkLow((int) $unit['item_id']);
        });
    }

    /** إعادة احتساب رصيد صنف مرقّم */
    public static function recount(int $itemId): float
    {
        $count = (int) DB::value("SELECT COUNT(*) FROM inventory_units WHERE item_id = ? AND status = 'stock'", [$itemId]);
        DB::update('inventory_items', ['quantity' => $count, 'updated_at' => now()], 'id = ?', [$itemId]);
        return (float) $count;
    }

    // ------------------------------------------------------------------

    private static function log(int $itemId, ?int $unitId, string $type, float $qty, float $balance, array $meta): int
    {
        return DB::insert('inventory_movements', [
            'item_id'       => $itemId,
            'unit_id'       => $unitId,
            'type'          => $type,
            'qty'           => $qty,
            'balance'       => $balance,
            'unit_cost'     => isset($meta['unit_cost']) && $meta['unit_cost'] !== '' ? $meta['unit_cost'] : null,
            'site_id'       => $meta['site_id'] ?? null,
            'subscriber_id' => $meta['subscriber_id'] ?? null,
            'receiver'      => ($r = trim((string) ($meta['receiver'] ?? ''))) !== '' ? mb_substr($r, 0, 120) : null,
            'notes'         => ($n = trim((string) ($meta['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 255) : null,
            'employee_id'   => Auth::id(),
            'created_at'    => now(),
        ]);
    }

    /** تنبيه عند وصول الرصيد إلى الحد الأدنى */
    private static function checkLow(int $itemId): void
    {
        $item = self::item($itemId);
        if ($item && (int) $item['is_active'] && self::isLow($item) && \App\Core\Settings::bool('notify.inventory_low')) {
            Notifier::alert(
                'inventory_low', 'warning',
                'مخزون منخفض: ' . $item['name'],
                'الرصيد ' . self::fmtQty($item['quantity']) . ' ' . (self::UNITS[$item['unit']] ?? '') . ' · الحد الأدنى ' . self::fmtQty($item['min_qty']),
                null, 'inv-low-' . $itemId, 720
            );
        }
    }

    /** ملخص للوحة التحكم والتقارير والبوت */
    public static function summary(): array
    {
        $row = DB::row(
            "SELECT COUNT(*) AS items,
                    COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) AS value,
                    SUM(CASE WHEN min_qty IS NOT NULL AND quantity <= min_qty THEN 1 ELSE 0 END) AS low
             FROM inventory_items WHERE is_active = 1"
        ) ?? [];
        return [
            'items'     => (int) ($row['items'] ?? 0),
            'value'     => (float) ($row['value'] ?? 0),
            'low'       => (int) ($row['low'] ?? 0),
            'installed' => (int) DB::value("SELECT COUNT(*) FROM inventory_units WHERE status = 'installed'"),
            'faulty'    => (int) DB::value("SELECT COUNT(*) FROM inventory_units WHERE status = 'faulty'"),
        ];
    }

    public static function lowItems(int $limit = 20): array
    {
        return DB::rows(
            'SELECT * FROM inventory_items WHERE is_active = 1 AND min_qty IS NOT NULL AND quantity <= min_qty ORDER BY (quantity - min_qty), name LIMIT ' . max(1, $limit)
        );
    }
}
