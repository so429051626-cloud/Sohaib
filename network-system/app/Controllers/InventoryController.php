<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Geo;
use App\Services\Inventory;
use App\Support\Spreadsheet;
use RuntimeException;

final class InventoryController extends Controller
{
    // ------------------------------------------------------------------
    // الأصناف
    // ------------------------------------------------------------------

    public function index(Request $request): Response
    {
        $f = ['q' => $request->str('q'), 'category' => $request->str('category'), 'state' => $request->str('state')];
        $where = '1 = 1';
        $params = [];
        if ($f['q'] !== '') {
            $where .= ' AND (i.name LIKE ? OR i.sku LIKE ?)';
            array_push($params, DB::like($f['q']), DB::like($f['q']));
        }
        if (isset(Inventory::CATEGORIES[$f['category']])) {
            $where .= ' AND i.category = ?';
            $params[] = $f['category'];
        }
        if ($f['state'] === 'low') {
            $where .= ' AND i.is_active = 1 AND i.min_qty IS NOT NULL AND i.quantity <= i.min_qty';
        } elseif ($f['state'] === 'inactive') {
            $where .= ' AND i.is_active = 0';
        }
        $items = DB::rows(
            "SELECT i.*,
                    (SELECT COUNT(*) FROM inventory_units u WHERE u.item_id = i.id AND u.status = 'installed') AS installed,
                    (SELECT COUNT(*) FROM inventory_units u WHERE u.item_id = i.id AND u.status = 'faulty') AS faulty,
                    (SELECT MAX(m.created_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_move
             FROM inventory_items i WHERE $where ORDER BY i.is_active DESC, i.category, i.name",
            $params
        );
        return view('inventory/index', [
            'items' => $items, 'filters' => $f, 'summary' => Inventory::summary(),
            'sites' => Geo::sitePairs(), 'subscribers' => $this->subscriberPairs(),
        ]);
    }

    private function itemData(Request $request, ?array $item): array
    {
        $id = $item ? (int) $item['id'] : 0;
        $d = $this->validate($request, [
            'name'      => 'required|max:120',
            'category'  => 'required|in:' . implode(',', array_keys(Inventory::CATEGORIES)),
            'unit'      => 'required|in:' . implode(',', array_keys(Inventory::UNITS)),
            'sku'       => 'nullable|max:60',
            'min_qty'   => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes'     => 'nullable|max:255',
        ], ['name' => 'اسم الصنف', 'category' => 'التصنيف', 'unit' => 'الوحدة', 'sku' => 'الرمز', 'min_qty' => 'الحد الأدنى', 'unit_cost' => 'التكلفة'], function ($v) use ($request, $id) {
            if (DB::value('SELECT id FROM inventory_items WHERE name = ? AND id <> ?', [$request->str('name'), $id])) {
                $v->addError('name', 'يوجد صنف بهذا الاسم.');
            }
        });
        $track = $request->bool('track_serial') && $d['unit'] === 'piece';
        return [
            'name' => $d['name'], 'category' => $d['category'], 'unit' => $track ? 'piece' : $d['unit'], 'sku' => $d['sku'],
            'track_serial' => $item ? (int) $item['track_serial'] : ($track ? 1 : 0),
            'min_qty' => $d['min_qty'] !== null && $d['min_qty'] !== '' ? (float) $d['min_qty'] : null,
            'unit_cost' => $d['unit_cost'] !== null && $d['unit_cost'] !== '' ? (float) $d['unit_cost'] : null,
            'is_active' => $item ? ($request->bool('is_active') ? 1 : 0) : 1,
            'notes' => $d['notes'],
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->itemData($request, null);
        $id = DB::insert('inventory_items', $data + ['quantity' => 0, 'created_at' => now()]);
        Audit::log('inventory.item', 'إضافة الصنف ' . $data['name'], 'inventory_item', $id);
        $opening = $request->float('opening_qty');
        if (!$data['track_serial'] && $opening > 0) {
            Inventory::move($id, 'in', $opening, ['unit_cost' => $data['unit_cost'], 'notes' => 'رصيد افتتاحي']);
        }
        if ($data['track_serial'] && trim($request->str('serials')) !== '') {
            Inventory::addUnits($id, (string) $request->input('serials'), ['unit_cost' => $data['unit_cost'], 'notes' => 'رصيد افتتاحي']);
        }
        return $this->ok('تمت إضافة الصنف.', '/inventory/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $item = Inventory::item($id);
        $this->notFoundUnless($item);
        $units = [];
        $unitCounts = [];
        $status = $request->str('status');
        if ((int) $item['track_serial']) {
            $where = 'u.item_id = ?';
            $params = [$id];
            if (isset(Inventory::UNIT_STATUS[$status])) {
                $where .= ' AND u.status = ?';
                $params[] = $status;
            }
            $units = DB::rows(
                "SELECT u.*, si.name AS site_name, s.full_name AS subscriber_name, r.name AS router_name
                 FROM inventory_units u LEFT JOIN sites si ON si.id = u.site_id LEFT JOIN subscribers s ON s.id = u.subscriber_id
                 LEFT JOIN routers r ON r.id = u.router_id WHERE $where ORDER BY FIELD(u.status, 'stock', 'installed', 'faulty', 'lost'), u.serial LIMIT 500",
                $params
            );
            $unitCounts = DB::pairs('SELECT status, COUNT(*) FROM inventory_units WHERE item_id = ? GROUP BY status', [$id]);
        }
        $movements = DB::rows(
            'SELECT m.*, u.serial, si.name AS site_name, s.full_name AS subscriber_name, e.name AS employee_name
             FROM inventory_movements m LEFT JOIN inventory_units u ON u.id = m.unit_id LEFT JOIN sites si ON si.id = m.site_id
             LEFT JOIN subscribers s ON s.id = m.subscriber_id LEFT JOIN employees e ON e.id = m.employee_id
             WHERE m.item_id = ? ORDER BY m.id DESC LIMIT 100',
            [$id]
        );
        $stats = DB::row(
            "SELECT COALESCE(SUM(CASE WHEN qty > 0 AND type IN ('in') THEN qty END), 0) AS received,
                    COALESCE(SUM(CASE WHEN type = 'out' THEN -qty END), 0) AS issued,
                    COALESCE(SUM(CASE WHEN type = 'damage' THEN -qty END), 0) AS damaged
             FROM inventory_movements WHERE item_id = ?",
            [$id]
        );
        return view('inventory/show', [
            'item' => $item, 'units' => $units, 'unitCounts' => $unitCounts, 'status' => $status, 'movements' => $movements, 'stats' => $stats,
            'sites' => Geo::sitePairs(), 'subscribers' => $this->subscriberPairs(),
            'routers' => DB::pairs('SELECT id, name FROM routers ORDER BY name'),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $item = Inventory::item($id);
        $this->notFoundUnless($item);
        $data = $this->itemData($request, $item);
        DB::update('inventory_items', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('inventory.item', 'تعديل الصنف ' . $data['name'], 'inventory_item', $id);
        return $this->ok('تم حفظ الصنف.', '/inventory/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $item = Inventory::item($id);
        $this->notFoundUnless($item);
        if (DB::value("SELECT id FROM inventory_units WHERE item_id = ? AND status = 'installed' LIMIT 1", [$id])) {
            flash('error', 'لا يمكن حذف صنف له أجهزة مركّبة؛ أوقفه بدلاً من ذلك.');
            return redirect('/inventory/' . $id);
        }
        DB::delete('inventory_items', 'id = ?', [$id]);
        Audit::log('inventory.item', 'حذف الصنف ' . $item['name'], 'inventory_item', $id);
        return $this->ok('تم حذف الصنف.', '/inventory');
    }

    /** حركة على صنف: كمية أو أجهزة مرقّمة جديدة */
    public function move(Request $request, int $id): Response
    {
        $item = Inventory::item($id);
        $this->notFoundUnless($item);
        $type = $request->str('type');
        $meta = $this->movementMeta($request);
        $back = $request->str('back') === 'index' ? '/inventory' : '/inventory/' . $id;
        try {
            if ((int) $item['track_serial']) {
                $r = Inventory::addUnits($id, (string) $request->input('serials', ''), $meta);
                $msg = 'تم إدخال ' . num($r['added']) . ' جهاز.';
                if ($r['skipped']) {
                    $msg .= ' أرقام مكررة لم تُضف: ' . implode('، ', array_slice($r['skipped'], 0, 10)) . (count($r['skipped']) > 10 ? '…' : '');
                }
                Audit::log('inventory.move', 'إدخال ' . $r['added'] . ' جهاز من ' . $item['name'], 'inventory_item', $id);
                flash($r['added'] ? 'success' : 'warning', $msg);
                return redirect($back);
            }
            $qty = $request->float('qty', -1);
            Inventory::move($id, $type, $qty, $meta);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['qty' => $e->getMessage()], $back);
        }
        $label = Inventory::MOVE_TYPES[$type][0] ?? $type;
        Audit::log('inventory.move', $label . ' ' . Inventory::fmtQty($qty) . ' ' . (Inventory::UNITS[$item['unit']] ?? '') . ' من ' . $item['name'], 'inventory_item', $id);
        return $this->ok('تم تسجيل الحركة.', $back);
    }

    private function movementMeta(Request $request): array
    {
        $siteId = $request->int('site_id');
        $subId = $request->int('subscriber_id');
        $routerId = $request->int('router_id');
        $cost = latin_digits($request->str('unit_cost'));
        return [
            'site_id' => $siteId > 0 && DB::value('SELECT id FROM sites WHERE id = ?', [$siteId]) ? $siteId : null,
            'subscriber_id' => $subId > 0 && DB::value('SELECT id FROM subscribers WHERE id = ?', [$subId]) ? $subId : null,
            'router_id' => $routerId > 0 && DB::value('SELECT id FROM routers WHERE id = ?', [$routerId]) ? $routerId : null,
            'receiver' => mb_substr($request->str('receiver'), 0, 120),
            'notes' => mb_substr($request->str('notes'), 0, 255),
            'unit_cost' => is_numeric($cost) && (float) $cost >= 0 ? (float) $cost : null,
        ];
    }

    private function subscriberPairs(): array
    {
        return DB::pairs('SELECT id, CONCAT(full_name, " · ", username) FROM subscribers ORDER BY full_name LIMIT 3000');
    }

    // ------------------------------------------------------------------
    // الأجهزة المرقّمة
    // ------------------------------------------------------------------

    public function units(Request $request): Response
    {
        $f = ['q' => $request->str('q'), 'status' => $request->str('status'), 'item' => $request->str('item'), 'site' => $request->str('site')];
        $where = '1 = 1';
        $params = [];
        if ($f['q'] !== '') {
            $where .= ' AND (u.serial LIKE ? OR u.mac LIKE ? OR s.full_name LIKE ?)';
            array_push($params, DB::like($f['q']), DB::like(strtoupper(str_replace('-', ':', $f['q']))), DB::like($f['q']));
        }
        if (isset(Inventory::UNIT_STATUS[$f['status']])) {
            $where .= ' AND u.status = ?';
            $params[] = $f['status'];
        }
        if ((int) $f['item'] > 0) {
            $where .= ' AND u.item_id = ?';
            $params[] = (int) $f['item'];
        }
        if ((int) $f['site'] > 0) {
            $where .= ' AND u.site_id = ?';
            $params[] = (int) $f['site'];
        }
        $from = "FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id LEFT JOIN sites si ON si.id = u.site_id
                 LEFT JOIN subscribers s ON s.id = u.subscriber_id LEFT JOIN routers r ON r.id = u.router_id WHERE $where";
        $total = (int) DB::value("SELECT COUNT(*) $from", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT u.*, i.name AS item_name, i.category, si.name AS site_name, s.full_name AS subscriber_name, r.name AS router_name
             $from ORDER BY u.updated_at IS NULL, u.updated_at DESC, u.id DESC" . $pager->limitSql(),
            $params
        );
        $counts = DB::pairs('SELECT status, COUNT(*) FROM inventory_units GROUP BY status');
        return view('inventory/units', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $f, 'counts' => $counts,
            'items' => DB::pairs('SELECT id, name FROM inventory_items WHERE track_serial = 1 ORDER BY name'),
            'sites' => Geo::sitePairs(), 'request' => $request,
        ]);
    }

    public function unit(Request $request, int $id): Response
    {
        $unit = DB::row(
            'SELECT u.*, i.name AS item_name, i.category, i.unit_cost, si.name AS site_name, s.full_name AS subscriber_name, s.username AS subscriber_username, r.name AS router_name
             FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id LEFT JOIN sites si ON si.id = u.site_id
             LEFT JOIN subscribers s ON s.id = u.subscriber_id LEFT JOIN routers r ON r.id = u.router_id WHERE u.id = ?',
            [$id]
        );
        $this->notFoundUnless($unit);
        $history = DB::rows(
            'SELECT m.*, si.name AS site_name, s.full_name AS subscriber_name, e.name AS employee_name
             FROM inventory_movements m LEFT JOIN sites si ON si.id = m.site_id LEFT JOIN subscribers s ON s.id = m.subscriber_id
             LEFT JOIN employees e ON e.id = m.employee_id WHERE m.unit_id = ? ORDER BY m.id DESC',
            [$id]
        );
        return view('inventory/unit', [
            'unit' => $unit, 'history' => $history, 'sites' => Geo::sitePairs(), 'subscribers' => $this->subscriberPairs(),
            'routers' => DB::pairs('SELECT id, name FROM routers ORDER BY name'),
        ]);
    }

    public function unitUpdate(Request $request, int $id): Response
    {
        $unit = DB::row('SELECT * FROM inventory_units WHERE id = ?', [$id]);
        $this->notFoundUnless($unit);
        $d = $this->validate($request, ['serial' => 'required|max:80', 'mac' => 'nullable|mac', 'notes' => 'nullable|max:255'], ['serial' => 'الرقم التسلسلي', 'mac' => 'MAC'], function ($v) use ($request, $unit) {
            if (DB::value('SELECT id FROM inventory_units WHERE item_id = ? AND serial = ? AND id <> ?', [$unit['item_id'], latin_digits($request->str('serial')), $unit['id']])) {
                $v->addError('serial', 'الرقم التسلسلي مسجّل لجهاز آخر من الصنف نفسه.');
            }
        });
        DB::update('inventory_units', [
            'serial' => latin_digits($d['serial']), 'mac' => $d['mac'] ? strtoupper(str_replace('-', ':', $d['mac'])) : null,
            'notes' => $d['notes'], 'updated_at' => now(),
        ], 'id = ?', [$id]);
        Audit::log('inventory.unit', 'تعديل الجهاز ' . $d['serial'], 'inventory_unit', $id);
        return $this->ok('تم حفظ الجهاز.', '/inventory/units/' . $id);
    }

    public function unitAction(Request $request, int $id): Response
    {
        $unit = DB::row('SELECT u.*, i.name AS item_name FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id WHERE u.id = ?', [$id]);
        $this->notFoundUnless($unit);
        $action = $request->str('action');
        $back = $request->str('back') !== '' && str_starts_with($request->str('back'), '/') ? $request->str('back') : '/inventory/units/' . $id;
        try {
            Inventory::unitAction($id, $action, $this->movementMeta($request));
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['action' => $e->getMessage()], $back);
        }
        $labels = ['install' => 'تركيب', 'return' => 'إرجاع', 'faulty' => 'تسجيل تلف', 'lost' => 'تسجيل فقد'];
        Audit::log('inventory.unit', ($labels[$action] ?? $action) . ' الجهاز ' . $unit['serial'] . ' (' . $unit['item_name'] . ')', 'inventory_unit', $id);
        return $this->ok('تم تحديث حالة الجهاز.', $back);
    }

    public function unitDestroy(Request $request, int $id): Response
    {
        $unit = DB::row('SELECT * FROM inventory_units WHERE id = ?', [$id]);
        $this->notFoundUnless($unit);
        DB::transaction(static function () use ($unit, $id) {
            DB::delete('inventory_units', 'id = ?', [$id]);
            Inventory::recount((int) $unit['item_id']);
        });
        Audit::log('inventory.unit', 'حذف الجهاز ' . $unit['serial'], 'inventory_unit', $id);
        return $this->ok('تم حذف الجهاز.', '/inventory/' . $unit['item_id']);
    }

    // ------------------------------------------------------------------
    // سجل الحركات
    // ------------------------------------------------------------------

    private function movementFilters(Request $request): array
    {
        $f = [
            'from' => $request->str('from', date('Y-m-01')), 'to' => $request->str('to', date('Y-m-d')),
            'type' => $request->str('type'), 'item' => $request->str('item'), 'employee' => $request->str('employee'), 'site' => $request->str('site'),
        ];
        $where = 'm.created_at >= ? AND m.created_at < ?';
        $params = [$f['from'] . ' 00:00:00', date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
        if (isset(Inventory::MOVE_TYPES[$f['type']])) {
            $where .= ' AND m.type = ?';
            $params[] = $f['type'];
        }
        foreach (['item' => 'm.item_id', 'employee' => 'm.employee_id', 'site' => 'm.site_id'] as $k => $col) {
            if ((int) $f[$k] > 0) {
                $where .= " AND $col = ?";
                $params[] = (int) $f[$k];
            }
        }
        return [$f, $where, $params];
    }

    private const MOVEMENT_SQL = 'FROM inventory_movements m JOIN inventory_items i ON i.id = m.item_id LEFT JOIN inventory_units u ON u.id = m.unit_id
        LEFT JOIN sites si ON si.id = m.site_id LEFT JOIN subscribers s ON s.id = m.subscriber_id LEFT JOIN employees e ON e.id = m.employee_id';

    public function movements(Request $request): Response
    {
        [$f, $where, $params] = $this->movementFilters($request);
        $total = (int) DB::value('SELECT COUNT(*) ' . self::MOVEMENT_SQL . " WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            'SELECT m.*, i.name AS item_name, i.unit, u.serial, si.name AS site_name, s.full_name AS subscriber_name, e.name AS employee_name '
            . self::MOVEMENT_SQL . " WHERE $where ORDER BY m.id DESC" . $pager->limitSql(),
            $params
        );
        $totals = DB::pairs('SELECT m.type, COUNT(*) ' . self::MOVEMENT_SQL . " WHERE $where GROUP BY m.type", $params);
        $value = (float) DB::value('SELECT COALESCE(SUM(-m.qty * COALESCE(m.unit_cost, i.unit_cost, 0)), 0) ' . self::MOVEMENT_SQL . " WHERE $where AND m.type = 'out'", $params);
        return view('inventory/movements', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $f, 'totals' => $totals, 'issuedValue' => $value, 'request' => $request,
            'items' => DB::pairs('SELECT id, name FROM inventory_items ORDER BY name'),
            'employees' => DB::pairs('SELECT id, name FROM employees ORDER BY name'),
            'sites' => Geo::sitePairs(),
        ]);
    }

    public function export(Request $request): Response
    {
        if ($request->str('what') === 'stock') {
            $header = ['الصنف', 'التصنيف', 'الرمز', 'الوحدة', 'الرصيد', 'الحد الأدنى', 'التكلفة', 'القيمة', 'مركّب', 'تالف', 'الحالة'];
            $rows = array_map(static fn ($i) => [
                $i['name'], Inventory::CATEGORIES[$i['category']][0] ?? $i['category'], $i['sku'], Inventory::UNITS[$i['unit']] ?? $i['unit'],
                (float) $i['quantity'], $i['min_qty'] !== null ? (float) $i['min_qty'] : '', $i['unit_cost'] !== null ? (float) $i['unit_cost'] : '',
                round((float) $i['quantity'] * (float) $i['unit_cost'], 2), (int) $i['installed'], (int) $i['faulty'],
                !(int) $i['is_active'] ? 'موقوف' : (Inventory::isLow($i) ? 'منخفض' : 'متوفر'),
            ], DB::rows(
                "SELECT i.*, (SELECT COUNT(*) FROM inventory_units u WHERE u.item_id = i.id AND u.status = 'installed') AS installed,
                        (SELECT COUNT(*) FROM inventory_units u WHERE u.item_id = i.id AND u.status = 'faulty') AS faulty
                 FROM inventory_items i ORDER BY i.category, i.name"
            ));
            $name = 'inventory-' . date('Ymd');
            $sheet = 'المخزون';
        } else {
            [, $where, $params] = $this->movementFilters($request);
            $header = ['التاريخ', 'الصنف', 'الرقم التسلسلي', 'الحركة', 'الكمية', 'الرصيد بعدها', 'التكلفة', 'الموقع', 'المشترك', 'المستلم', 'الموظف', 'ملاحظات'];
            $rows = array_map(static fn ($m) => [
                $m['created_at'], $m['item_name'], $m['serial'], Inventory::MOVE_TYPES[$m['type']][0] ?? $m['type'], (float) $m['qty'], (float) $m['balance'],
                $m['unit_cost'] !== null ? (float) $m['unit_cost'] : '', $m['site_name'], $m['subscriber_name'], $m['receiver'], $m['employee_name'], $m['notes'],
            ], DB::rows(
                'SELECT m.*, i.name AS item_name, u.serial, si.name AS site_name, s.full_name AS subscriber_name, e.name AS employee_name '
                . self::MOVEMENT_SQL . " WHERE $where ORDER BY m.id DESC LIMIT 50000",
                $params
            ));
            $name = 'inventory-movements-' . date('Ymd');
            $sheet = 'حركات المخزون';
        }
        Audit::log('inventory.export', 'تصدير ' . $sheet);
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv($header, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx($header, $rows, $sheet), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
