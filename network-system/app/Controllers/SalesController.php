<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\CardQuery;
use App\Services\SalesService;
use App\Support\Spreadsheet;
use RuntimeException;

final class SalesController extends Controller
{
    public const FILTERS = ['from', 'to', 'type', 'distributor', 'category', 'employee', 'status'];

    private function filters(Request $request): array
    {
        $f = [];
        foreach (self::FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        return $f;
    }

    private function where(array $f): array
    {
        $sql = '1 = 1';
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $sql .= ' AND s.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $sql .= ' AND s.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        if (in_array($f['type'], ['direct', 'distributor'], true)) {
            $sql .= ' AND s.type = ?';
            $params[] = $f['type'];
        }
        if (in_array($f['status'], ['completed', 'cancelled'], true)) {
            $sql .= ' AND s.status = ?';
            $params[] = $f['status'];
        }
        foreach (['distributor' => 's.distributor_id', 'category' => 's.category_id', 'employee' => 's.employee_id'] as $k => $col) {
            if ($f[$k] !== '' && ctype_digit($f[$k])) {
                $sql .= " AND $col = ?";
                $params[] = (int) $f[$k];
            }
        }
        return [$sql, $params];
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        [$where, $params] = $this->where($f);
        $total = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT s.*, c.name AS category_name, c.color AS category_color, d.name AS distributor_name, e.name AS employee_name
             FROM sales s JOIN categories c ON c.id = s.category_id
             LEFT JOIN distributors d ON d.id = s.distributor_id LEFT JOIN employees e ON e.id = s.employee_id
             WHERE $where ORDER BY s.id DESC" . $pager->limitSql(),
            $params
        );
        $summary = DB::row(
            "SELECT COALESCE(SUM(s.quantity), 0) AS cards, COALESCE(SUM(s.total), 0) AS total, COALESCE(SUM(s.paid), 0) AS paid, COUNT(*) AS ops
             FROM sales s WHERE $where AND s.status = 'completed'",
            $params
        );
        return view('sales/index', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $f, 'summary' => $summary, 'request' => $request,
            'categories' => DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name'),
            'distributors' => DB::pairs('SELECT id, name FROM distributors ORDER BY name'),
            'employees' => DB::pairs('SELECT id, name FROM employees ORDER BY name'),
        ]);
    }

    public function create(Request $request): Response
    {
        $selection = [];
        $selCategory = null;
        if ($request->str('source') === 'selection') {
            $ids = array_map('intval', (array) Session::get('sale_selection', []));
            if ($ids) {
                $selection = DB::rows(
                    "SELECT c.category_id, cat.name, COUNT(*) AS n FROM cards c JOIN categories cat ON cat.id = c.category_id
                     WHERE c.status = 'new' AND c.is_disabled = 0 AND c.id IN (" . DB::in($ids) . ') GROUP BY c.category_id, cat.name',
                    $ids
                );
            }
            if (count($selection) === 1) {
                $selCategory = (int) $selection[0]['category_id'];
            }
        }
        $categories = DB::rows(
            "SELECT c.*, (SELECT COUNT(*) FROM cards k WHERE k.category_id = c.id AND k.status = 'new' AND k.is_disabled = 0) AS available
             FROM categories c WHERE c.is_active = 1 AND c.is_custom = 0 ORDER BY c.sort, c.price, c.name"
        );
        return view('sales/create', [
            'categories' => $categories,
            'distributors' => DB::rows('SELECT id, name, discount_percent, balance FROM distributors WHERE is_active = 1 ORDER BY name'),
            'selection' => $selection,
            'selCategory' => $selCategory,
            'request' => $request,
        ]);
    }

    public function store(Request $request): Response
    {
        $d = $this->validate($request, [
            'type'           => 'required|in:direct,distributor',
            'distributor_id' => 'nullable|int',
            'category_id'    => 'required|int',
            'quantity'       => 'nullable|int|between:1,100000',
            'unit_price'     => 'nullable|numeric|min:0',
            'discount'       => 'nullable|numeric|between:0,100',
            'paid'           => 'nullable|numeric|min:0',
            'customer_name'  => 'nullable|max:120',
            'notes'          => 'nullable|max:255',
        ], [
            'type' => 'نوع البيع', 'distributor_id' => 'الموزع', 'category_id' => 'الباقة', 'quantity' => 'الكمية',
            'unit_price' => 'سعر الوحدة', 'discount' => 'الخصم', 'paid' => 'المدفوع', 'customer_name' => 'اسم الزبون',
        ]);
        $cardIds = null;
        if ($request->str('source') === 'selection') {
            $cardIds = array_map('intval', (array) Session::get('sale_selection', []));
            if (!$cardIds) {
                return $this->backWithErrors($request, ['category_id' => 'انتهت صلاحية التحديد. حدّد الكروت مجدداً.'], '/cards');
            }
        } elseif (!$d['quantity']) {
            return $this->backWithErrors($request, ['quantity' => 'حقل الكمية مطلوب.'], '/sales/create');
        }
        try {
            $r = SalesService::sell(
                $d['type'], $d['distributor_id'] ? (int) $d['distributor_id'] : null, (int) $d['category_id'],
                (int) ($d['quantity'] ?? 0), $d['unit_price'] !== null ? (float) $d['unit_price'] : null,
                (float) ($d['discount'] ?? 0), (float) ($d['paid'] ?? 0), $d['customer_name'], $d['notes'], $cardIds
            );
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['quantity' => $e->getMessage()], '/sales/create');
        }
        if ($cardIds) {
            Session::forget('sale_selection');
        }
        return $this->ok('تم بيع ' . ar_plural((int) $r['quantity'], 'كرت واحد', 'كرتين', 'كروت', 'كرت') . ' بقيمة ' . money($r['total']) . '.', '/sales/' . $r['sale_id']);
    }

    public function show(Request $request, int $id): Response
    {
        $sale = DB::row(
            'SELECT s.*, c.name AS category_name, c.color AS category_color, d.name AS distributor_name, d.balance AS distributor_balance,
                    e.name AS employee_name, ce.name AS cancelled_by_name
             FROM sales s JOIN categories c ON c.id = s.category_id LEFT JOIN distributors d ON d.id = s.distributor_id
             LEFT JOIN employees e ON e.id = s.employee_id LEFT JOIN employees ce ON ce.id = s.cancelled_by WHERE s.id = ?',
            [$id]
        );
        $this->notFoundUnless($sale);
        $cards = DB::rows(
            'SELECT id, username, password, short_code, short_password, status, first_login_at, printed_at FROM cards WHERE sale_id = ? ORDER BY id LIMIT 2000',
            [$id]
        );
        $used = (int) DB::value('SELECT COUNT(*) FROM cards WHERE sale_id = ? AND first_login_at IS NOT NULL', [$id]);
        return view('sales/show', compact('sale', 'cards', 'used'));
    }

    /** تصدير كروت عملية البيع */
    public function export(Request $request, int $id): Response
    {
        $sale = DB::row('SELECT id FROM sales WHERE id = ?', [$id]);
        $this->notFoundUnless($sale);
        $ids = array_map('intval', DB::column('SELECT id FROM cards WHERE sale_id = ? ORDER BY id', [$id]));
        if (!$ids) {
            flash('error', 'لا توجد كروت في هذه العملية.');
            return redirect('/sales/' . $id);
        }
        $rows = CardQuery::exportRows($ids, can('cards.passwords'));
        Audit::log('cards.export', 'تصدير كروت عملية البيع رقم ' . $id, 'sale', $id);
        $name = 'sale-' . $id . '-' . date('Ymd');
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv(CardQuery::EXPORT_HEADER, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx(CardQuery::EXPORT_HEADER, $rows, 'الكروت'), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function cancel(Request $request, int $id): Response
    {
        try {
            SalesService::cancel($id);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['id' => $e->getMessage()], '/sales/' . $id);
        }
        return $this->ok('تم إلغاء عملية البيع وإعادة الكروت إلى المخزون.', '/sales/' . $id);
    }
}
