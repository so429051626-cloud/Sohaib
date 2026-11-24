<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Support\Spreadsheet;
use App\Services\Finance;

/**
 * المصاريف والشركاء: تسجيل مصاريف الشبكة شهراً بشهر، وصافي ربح الشهر وحصة كل شريك بنسبته.
 */
final class ExpenseController extends Controller
{
    /** الشهر المطلوب (YYYY-MM) أو الحالي */
    private static function month(Request $request): string
    {
        $m = latin_digits($request->str('month'));
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
    }

    /** @return array{0:string, 1:array, 2:array} شرط SQL ومعاملاته والمرشّحات */
    private function filter(Request $request): array
    {
        $month = self::month($request);
        $f = ['month' => $month, 'category' => $request->str('category'), 'q' => trim($request->str('q'))];
        $where = 'x.spent_on BETWEEN ? AND ?';
        $params = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
        if (isset(Finance::CATEGORIES[$f['category']])) {
            $where .= ' AND x.category = ?';
            $params[] = $f['category'];
        } else {
            $f['category'] = '';
        }
        if ($f['q'] !== '') {
            $where .= ' AND x.description LIKE ?';
            $params[] = DB::like($f['q']);
        }
        return [$where, $params, $f];
    }

    public function index(Request $request): Response
    {
        [$where, $params, $f] = $this->filter($request);
        $from = $f['month'] . '-01';
        $to = date('Y-m-t', strtotime($from));
        $total = (int) DB::value("SELECT COUNT(*) FROM expenses x WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $rows = DB::rows("SELECT x.*, s.name AS site_name, e.name AS by_name FROM expenses x LEFT JOIN sites s ON s.id = x.site_id LEFT JOIN employees e ON e.id = x.created_by
                          WHERE $where ORDER BY x.spent_on DESC, x.id DESC" . $pager->limitSql(), $params);
        $spent = Finance::spent($from, $to);
        $prevFrom = date('Y-m-01', strtotime($from . ' -1 month'));
        $prev = Finance::spent($prevFrom, date('Y-m-t', strtotime($prevFrom)));
        $profit = null;
        if (can('profit.view')) {
            $income = Finance::collected($from, $to);
            $profit = ['income' => $income, 'net' => round($income - $spent, 2), 'split' => Finance::split(round($income - $spent, 2))];
        }
        return view('expenses/index', [
            'rows' => $rows, 'pager' => $pager, 'request' => $request, 'f' => $f,
            'filtered' => (float) DB::value("SELECT COALESCE(SUM(x.amount), 0) FROM expenses x WHERE $where", $params),
            'spent' => $spent, 'prev' => $prev, 'count' => (int) DB::value('SELECT COUNT(*) FROM expenses WHERE spent_on BETWEEN ? AND ?', [$from, $to]),
            'byCategory' => Finance::byCategory($from, $to), 'profit' => $profit,
            'partners' => can('profit.view') ? Finance::partners() : [],
            'sites' => DB::pairs('SELECT id, name FROM sites ORDER BY name'),
            'prevMonth' => substr($prevFrom, 0, 7),
            'nextMonth' => $f['month'] < date('Y-m') ? date('Y-m', strtotime($from . ' +1 month')) : null,
        ]);
    }

    /** إضافة مصروف أو تعديله (id في النموذج) */
    public function save(Request $request): Response
    {
        $id = $request->int('id');
        $back = '/expenses';
        $d = $this->validate($request, [
            'category'    => 'required|in:' . implode(',', array_keys(Finance::CATEGORIES)),
            'amount'      => 'required|max:20',
            'spent_on'    => 'required|date',
            'description' => 'nullable|max:255',
            'site_id'     => 'nullable|int',
        ], ['category' => 'الفئة', 'amount' => 'المبلغ', 'spent_on' => 'التاريخ', 'description' => 'البيان', 'site_id' => 'الموقع']);
        $amount = Finance::amount((string) $d['amount']);
        $errors = [];
        if ($amount === null || $amount <= 0) {
            $errors['amount'] = 'اكتب مبلغاً صحيحاً أكبر من صفر.';
        } elseif ($amount > 9999999999.99) {
            $errors['amount'] = 'المبلغ أكبر من الحد المسموح.';
        }
        $day = date('Y-m-d', (int) strtotime((string) $d['spent_on']));
        if ($day > date('Y-m-d')) {
            $errors['spent_on'] = 'تاريخ المصروف لا يكون في المستقبل.';
        }
        $site = (int) ($d['site_id'] ?? 0);
        if ($site > 0 && !DB::value('SELECT 1 FROM sites WHERE id = ?', [$site])) {
            $site = 0;
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, $back);
        }
        $data = ['category' => $d['category'], 'amount' => $amount, 'spent_on' => $day, 'description' => $d['description'] !== null && $d['description'] !== '' ? $d['description'] : null, 'site_id' => $site ?: null];
        $text = Finance::label((string) $d['category']) . ' — ' . money($amount);
        if ($id > 0) {
            $old = DB::row('SELECT * FROM expenses WHERE id = ?', [$id]);
            $this->notFoundUnless($old);
            DB::update('expenses', $data + ['updated_at' => now()], 'id = ?', [$id]);
            Audit::log('expense.update', 'تعديل مصروف: ' . $text, 'expense', $id, ['before' => (float) $old['amount'], 'after' => $amount]);
            return $this->ok('تم حفظ المصروف.', '/expenses', ['month' => substr($day, 0, 7)]);
        }
        $id = DB::insert('expenses', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('expense.create', 'تسجيل مصروف: ' . $text, 'expense', $id);
        return $this->ok('تم تسجيل المصروف.', '/expenses', ['month' => substr($day, 0, 7)]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $x = DB::row('SELECT * FROM expenses WHERE id = ?', [$id]);
        $this->notFoundUnless($x);
        DB::delete('expenses', 'id = ?', [$id]);
        Audit::log('expense.delete', 'حذف مصروف: ' . Finance::label((string) $x['category']) . ' — ' . money((float) $x['amount']), 'expense', $id, ['date' => $x['spent_on'], 'description' => $x['description']]);
        return $this->ok('تم حذف المصروف.', '/expenses', ['month' => substr((string) $x['spent_on'], 0, 7)]);
    }

    /** تصدير مصاريف الشهر (بالمرشّحات الحالية) */
    public function export(Request $request): Response
    {
        [$where, $params, $f] = $this->filter($request);
        $rows = array_map(static fn ($r) => [
            $r['spent_on'], Finance::label((string) $r['category']), Spreadsheet::plain((string) ($r['description'] ?? '')), Spreadsheet::plain((string) ($r['site_name'] ?? '')),
            round((float) $r['amount'], 2), Spreadsheet::plain((string) ($r['by_name'] ?? '')),
        ], DB::rows("SELECT x.*, s.name AS site_name, e.name AS by_name FROM expenses x LEFT JOIN sites s ON s.id = x.site_id LEFT JOIN employees e ON e.id = x.created_by WHERE $where ORDER BY x.spent_on, x.id", $params));
        $header = ['التاريخ', 'الفئة', 'البيان', 'الموقع', 'المبلغ', 'سجّله'];
        Audit::log('report.export', 'تصدير مصاريف ' . $f['month'], 'report');
        $name = 'expenses-' . $f['month'];
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv($header, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx($header, $rows, 'المصاريف'), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ------------------------------------------------------------------
    // الشركاء ونسبهم
    // ------------------------------------------------------------------

    public function partnerSave(Request $request): Response
    {
        $id = $request->int('id');
        $d = $this->validate($request, [
            'name'  => 'required|max:120',
            'share' => 'required|max:10',
            'phone' => 'nullable|max:30',
            'notes' => 'nullable|max:255',
        ], ['name' => 'اسم الشريك', 'share' => 'النسبة', 'phone' => 'الهاتف', 'notes' => 'ملاحظات']);
        $share = Finance::amount((string) $d['share']);
        if ($share === null || $share <= 0 || $share > 100) {
            return $this->backWithErrors($request, ['share' => 'النسبة بين 0.01 و100.'], '/expenses');
        }
        $left = round(100 - Finance::sharesTotal($id ?: null), 2);
        if ($share > $left + 0.001) {
            return $this->backWithErrors($request, ['share' => 'مجموع النسب يتجاوز 100%. المتاح ' . rtrim(rtrim(number_format($left, 2, '.', ''), '0'), '.') . '%.'], '/expenses');
        }
        $data = ['name' => $d['name'], 'share' => $share, 'phone' => $d['phone'] ?: null, 'notes' => $d['notes'] ?: null];
        if ($id > 0) {
            $this->notFoundUnless(DB::row('SELECT id FROM partners WHERE id = ?', [$id]));
            DB::update('partners', $data + ['updated_at' => now()], 'id = ?', [$id]);
            Audit::log('partner.update', 'تعديل شريك: ' . $d['name'] . ' (' . $share . '%)', 'partner', $id);
        } else {
            $id = DB::insert('partners', $data + ['created_at' => now()]);
            Audit::log('partner.create', 'إضافة شريك: ' . $d['name'] . ' (' . $share . '%)', 'partner', $id);
        }
        return $this->ok('تم حفظ الشريك.', '/expenses');
    }

    public function partnerDestroy(Request $request, int $id): Response
    {
        $p = DB::row('SELECT * FROM partners WHERE id = ?', [$id]);
        $this->notFoundUnless($p);
        DB::delete('partners', 'id = ?', [$id]);
        Audit::log('partner.delete', 'حذف شريك: ' . $p['name'], 'partner', $id);
        return $this->ok('تم حذف الشريك.', '/expenses');
    }
}
