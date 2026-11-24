<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\SalesService;
use App\Services\SubscriberService;
use App\Support\Spreadsheet;
use RuntimeException;

final class DistributorController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->str('q'));
        $sql = 'SELECT d.*, a.name AS area_name,
                       (SELECT COALESCE(SUM(s.quantity), 0) FROM sales s WHERE s.distributor_id = d.id AND s.status = \'completed\') AS cards_sold,
                       (SELECT COALESCE(SUM(s.total), 0) FROM sales s WHERE s.distributor_id = d.id AND s.status = \'completed\') AS sales_total,
                       (SELECT MAX(s.created_at) FROM sales s WHERE s.distributor_id = d.id AND s.status = \'completed\') AS last_sale
                FROM distributors d LEFT JOIN areas a ON a.id = d.area_id';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE d.name LIKE ? OR d.phone LIKE ?';
            $params = [DB::like($q), DB::like(latin_digits($q))];
        }
        $rows = DB::rows($sql . ' ORDER BY d.is_active DESC, d.name', $params);
        $totals = DB::row("SELECT COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS due, COUNT(*) AS n FROM distributors");
        return view('distributors/index', ['rows' => $rows, 'q' => $q, 'totals' => $totals]);
    }

    public function create(Request $request): Response
    {
        return view('distributors/form', ['dist' => null, 'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name')]);
    }

    public function edit(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT * FROM distributors WHERE id = ?', [$id]);
        $this->notFoundUnless($dist);
        return view('distributors/form', ['dist' => $dist, 'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name')]);
    }

    private function data(Request $request, ?array $dist): array
    {
        $d = $this->validate($request, [
            'name'             => 'required|max:120',
            'phone'            => 'nullable|max:30',
            'address'          => 'nullable|max:255',
            'area_id'          => 'nullable|int',
            'discount_percent' => 'nullable|numeric|between:0,100',
            'notes'            => 'nullable|max:255',
        ], [
            'name' => 'الاسم', 'phone' => 'الهاتف', 'address' => 'العنوان', 'discount_percent' => 'نسبة الخصم',
        ], function ($v) use ($request, $dist) {
            if (DB::value('SELECT id FROM distributors WHERE name = ? AND id <> ?', [$request->str('name'), $dist['id'] ?? 0])) {
                $v->addError('name', 'يوجد موزع بهذا الاسم.');
            }
        });
        return [
            'name'             => $d['name'],
            'phone'            => $d['phone'] ? latin_digits($d['phone']) : null,
            'address'          => $d['address'],
            'area_id'          => $d['area_id'] && DB::value('SELECT id FROM areas WHERE id = ?', [$d['area_id']]) ? (int) $d['area_id'] : null,
            'discount_percent' => round((float) ($d['discount_percent'] ?? 0), 2),
            'is_active'        => $request->bool('is_active') ? 1 : 0,
            'notes'            => $d['notes'],
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        $id = DB::insert('distributors', $data + ['created_at' => now()]);
        Audit::log('distributor.save', 'إضافة الموزع ' . $data['name'], 'distributor', $id);
        return $this->ok('تمت إضافة الموزع.', '/distributors/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT * FROM distributors WHERE id = ?', [$id]);
        $this->notFoundUnless($dist);
        $data = $this->data($request, $dist);
        DB::update('distributors', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('distributor.save', 'تعديل الموزع ' . $data['name'], 'distributor', $id);
        return $this->ok('تم حفظ بيانات الموزع.', '/distributors/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT * FROM distributors WHERE id = ?', [$id]);
        $this->notFoundUnless($dist);
        if (DB::value('SELECT 1 FROM sales WHERE distributor_id = ? LIMIT 1', [$id])) {
            return $this->backWithErrors($request, ['id' => 'لا يمكن حذف موزع له عمليات بيع. يمكنك إيقافه بدلاً من ذلك.'], '/distributors/' . $id);
        }
        DB::delete('distributors', 'id = ?', [$id]);
        Audit::log('distributor.delete', 'حذف الموزع ' . $dist['name'], 'distributor', $id);
        return $this->ok('تم حذف الموزع.', '/distributors');
    }

    public function show(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT d.*, a.name AS area_name FROM distributors d LEFT JOIN areas a ON a.id = d.area_id WHERE d.id = ?', [$id]);
        $this->notFoundUnless($dist);
        $statement = SalesService::statement($id);
        $stats = DB::row(
            "SELECT COALESCE(SUM(quantity), 0) AS cards, COALESCE(SUM(total), 0) AS total, COUNT(*) AS ops FROM sales WHERE distributor_id = ? AND status = 'completed'",
            [$id]
        );
        $paid = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE distributor_id = ?', [$id])
            + (float) DB::value("SELECT COALESCE(SUM(paid), 0) FROM sales WHERE distributor_id = ? AND status = 'completed'", [$id]);
        $byCategory = DB::rows(
            "SELECT c.name, c.color, SUM(s.quantity) AS qty, SUM(s.total) AS total FROM sales s JOIN categories c ON c.id = s.category_id
             WHERE s.distributor_id = ? AND s.status = 'completed' GROUP BY c.id, c.name, c.color ORDER BY qty DESC",
            [$id]
        );
        $stock = DB::rows(
            "SELECT c.name, c.color, COUNT(*) AS sold, SUM(k.first_login_at IS NOT NULL) AS used FROM cards k JOIN categories c ON c.id = k.category_id
             WHERE k.distributor_id = ? GROUP BY c.id, c.name, c.color ORDER BY c.sort, c.name",
            [$id]
        );
        return view('distributors/show', [
            'dist' => $dist, 'statement' => array_reverse($statement), 'stats' => $stats, 'paid' => $paid,
            'byCategory' => $byCategory, 'stock' => $stock, 'methods' => SubscriberService::METHODS,
        ]);
    }

    /** تصدير كشف الحساب */
    public function statement(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT * FROM distributors WHERE id = ?', [$id]);
        $this->notFoundUnless($dist);
        $types = ['sale' => 'بيع', 'sale_paid' => 'مدفوع عند البيع', 'payment' => 'دفعة'];
        $rows = array_map(static fn ($r) => [
            $r['date'], $types[$r['type']] ?? $r['type'], (int) $r['ref'], $r['description'], $r['debit'], $r['credit'], $r['balance'],
        ], SalesService::statement($id));
        $header = ['التاريخ', 'النوع', 'المرجع', 'البيان', 'عليه', 'له', 'الرصيد'];
        $name = 'statement-' . $id . '-' . date('Ymd');
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv($header, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx($header, $rows, 'كشف الحساب'), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function payment(Request $request, int $id): Response
    {
        $dist = DB::row('SELECT * FROM distributors WHERE id = ?', [$id]);
        $this->notFoundUnless($dist);
        $method = $request->str('method', 'cash');
        try {
            SalesService::payment(
                $id, (float) latin_digits($request->str('amount', '0')),
                isset(SubscriberService::METHODS[$method]) ? $method : 'cash',
                $request->str('notes') !== '' ? mb_substr($request->str('notes'), 0, 255) : null
            );
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['amount' => $e->getMessage()], '/distributors/' . $id);
        }
        return $this->ok('تم تسجيل الدفعة.', '/distributors/' . $id);
    }
}
