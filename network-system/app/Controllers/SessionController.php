<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\RouterTasks;

final class SessionController extends Controller
{
    /** شروط التصفية المشتركة */
    private function filters(Request $request, bool $open): array
    {
        [$scope, $params] = Auth::routerScope('s.router_id', true);
        $where = ($open ? 's.stopped_at IS NULL' : 's.stopped_at IS NOT NULL') . $scope;
        if ($request->int('router') > 0) {
            $where .= ' AND s.router_id = ?';
            $params[] = $request->int('router');
        }
        if (in_array($request->str('service'), ['hotspot', 'ppp'], true)) {
            $where .= ' AND s.service = ?';
            $params[] = $request->str('service');
        }
        if (($q = $request->str('q')) !== '') {
            $mac = normalize_mac($q);
            if ($mac) {
                $where .= ' AND s.mac = ?';
                $params[] = $mac;
            } else {
                $where .= ' AND (s.username LIKE ? OR s.ip LIKE ? OR d.hostname LIKE ?)';
                array_push($params, DB::like($q), DB::like($q), DB::like($q));
            }
        }
        if (!$open) {
            if (($from = $request->str('from')) !== '' && strtotime($from)) {
                $where .= ' AND s.started_at >= ?';
                $params[] = date('Y-m-d 00:00:00', strtotime($from));
            }
            if (($to = $request->str('to')) !== '' && strtotime($to)) {
                $where .= ' AND s.started_at <= ?';
                $params[] = date('Y-m-d 23:59:59', strtotime($to));
            }
        }
        return [$where, $params];
    }

    public function online(Request $request): Response
    {
        [$where, $params] = $this->filters($request, true);
        $base = "FROM sessions s LEFT JOIN devices d ON d.mac = s.mac LEFT JOIN routers r ON r.id = s.router_id WHERE $where";
        $total = (int) DB::value("SELECT COUNT(*) $base", $params);
        $pager = Paginator::fromRequest($request, $total, 100);
        $rows = DB::rows(
            "SELECT s.*, d.hostname, r.name AS router_name, c.category_id, cat.name AS category_name, sub.full_name
             FROM sessions s
             LEFT JOIN devices d ON d.mac = s.mac
             LEFT JOIN routers r ON r.id = s.router_id
             LEFT JOIN cards c ON c.id = s.card_id
             LEFT JOIN categories cat ON cat.id = c.category_id
             LEFT JOIN subscribers sub ON sub.id = s.subscriber_id
             WHERE $where
             ORDER BY s.started_at DESC" . $pager->limitSql(),
            $params
        );
        $summary = DB::row(
            "SELECT COUNT(*) AS n, SUM(s.service = 'hotspot') AS hs, SUM(s.service = 'ppp') AS ppp,
                    COALESCE(SUM(s.bytes_in + s.bytes_out), 0) AS bytes
             $base",
            $params
        );
        $routers = $this->routerList();
        return view('sessions/online', compact('rows', 'pager', 'summary', 'routers'));
    }

    public function history(Request $request): Response
    {
        [$where, $params] = $this->filters($request, false);
        $base = "FROM sessions s LEFT JOIN devices d ON d.mac = s.mac LEFT JOIN routers r ON r.id = s.router_id WHERE $where";
        $total = (int) DB::value("SELECT COUNT(*) $base", $params);
        $pager = Paginator::fromRequest($request, $total, 100);
        $rows = DB::rows("SELECT s.*, d.hostname, r.name AS router_name $base ORDER BY s.started_at DESC" . $pager->limitSql(), $params);
        $routers = $this->routerList();
        return view('sessions/history', compact('rows', 'pager', 'routers'));
    }

    private function routerList(): array
    {
        [$scope, $params] = Auth::routerScope('id');
        return DB::pairs('SELECT id, name FROM routers WHERE 1 = 1' . $scope . ' ORDER BY name', $params);
    }

    public function disconnect(Request $request, int $id): Response
    {
        $s = DB::row('SELECT * FROM sessions WHERE id = ?', [$id]);
        $this->notFoundUnless($s);
        if (!$s['router_id'] || !Auth::canRouter((int) $s['router_id'])) {
            throw new HttpException(403);
        }
        $result = $s['service'] === 'ppp'
            ? RouterTasks::dispatch((int) $s['router_id'], 'ppp_disconnect', ['name' => $s['username']])
            : RouterTasks::dispatch((int) $s['router_id'], 'hs_disconnect', ['user' => $s['username'], 'mac' => $s['mac']]);
        if ($result['ok'] && !$result['queued']) {
            DB::update('sessions', ['stopped_at' => now(), 'stop_reason' => 'admin'], 'id = ?', [$id]);
        }
        Audit::log('session.disconnect', 'فصل جلسة ' . $s['username'], 'session', $id);
        $msg = $result['queued'] ? 'جهاز MikroTik غير متاح حالياً؛ سيُنفَّذ الفصل عند عودة الاتصال.' : ($result['ok'] ? 'تم فصل الجلسة.' : (string) $result['error']);
        if ($request->wantsJson()) {
            return json_response(['ok' => $result['ok'], 'message' => $msg]);
        }
        flash($result['ok'] ? 'success' : 'error', $msg);
        return back('/sessions');
    }
}
