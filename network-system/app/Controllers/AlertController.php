<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Notifier;

final class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $where = ['1 = 1'];
        $params = [];
        $type = $request->str('type');
        if ($type !== '' && isset(Notifier::TYPES[$type])) {
            $where[] = 'a.type = ?';
            $params[] = $type;
        }
        if ($request->str('state') === 'unread') {
            $where[] = 'a.is_read = 0';
        }
        $sql = implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) FROM alerts a WHERE $sql", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT a.*, r.name AS router_name FROM alerts a LEFT JOIN routers r ON r.id = a.router_id
             WHERE $sql ORDER BY a.id DESC" . $pager->limitSql(),
            $params
        );
        $unread = (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0');
        return view('alerts/index', compact('rows', 'pager', 'unread'));
    }

    public function readAll(Request $request): Response
    {
        DB::query('UPDATE alerts SET is_read = 1 WHERE is_read = 0');
        return $this->ok('تم تعليم كل التنبيهات كمقروءة.', '/alerts');
    }

    public function read(Request $request, int $id): Response
    {
        DB::update('alerts', ['is_read' => 1], 'id = ?', [$id]);
        return $request->wantsJson() ? json_response(['ok' => true]) : back('/alerts');
    }

    public function clear(Request $request): Response
    {
        DB::query('DELETE FROM alerts WHERE is_read = 1');
        return $this->ok('تم حذف التنبيهات المقروءة.', '/alerts');
    }
}
