<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        if ($request->int('employee') > 0) {
            $where[] = 'a.employee_id = ?';
            $params[] = $request->int('employee');
        }
        $action = $request->str('action');
        if ($action !== '' && isset(Audit::ACTIONS[$action])) {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if (($from = $request->str('from')) !== '' && strtotime($from)) {
            $where[] = 'a.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime($from));
        }
        if (($to = $request->str('to')) !== '' && strtotime($to)) {
            $where[] = 'a.created_at <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime($to));
        }
        if (($q = $request->str('q')) !== '') {
            $where[] = '(a.summary LIKE ? OR a.ip LIKE ?)';
            $params[] = DB::like($q);
            $params[] = DB::like($q);
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM audit_log a WHERE $sqlWhere", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT a.*, e.name AS employee_name FROM audit_log a LEFT JOIN employees e ON e.id = a.employee_id
             WHERE $sqlWhere ORDER BY a.id DESC" . $pager->limitSql(),
            $params
        );
        $employees = DB::pairs('SELECT id, name FROM employees ORDER BY name');
        return view('audit/index', compact('rows', 'pager', 'employees'));
    }
}
