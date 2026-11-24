<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\RouterTasks;

final class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $where = '1 = 1';
        $params = [];
        if (($q = $request->str('q')) !== '') {
            $mac = normalize_mac($q);
            if ($mac) {
                $where .= ' AND d.mac = ?';
                $params[] = $mac;
            } else {
                $where .= ' AND (d.hostname LIKE ? OR d.last_ip LIKE ?)';
                array_push($params, DB::like($q), DB::like($q));
            }
        }
        if ($request->int('router') > 0) {
            $where .= ' AND d.router_id = ?';
            $params[] = $request->int('router');
        }
        if ($request->str('show') === 'blocked') {
            $where .= ' AND b.mac IS NOT NULL';
        }
        $base = "FROM devices d LEFT JOIN blocked_macs b ON b.mac = d.mac LEFT JOIN routers r ON r.id = d.router_id WHERE $where";
        $total = (int) DB::value("SELECT COUNT(*) $base", $params);
        $pager = Paginator::fromRequest($request, $total, 100);
        $rows = DB::rows(
            "SELECT d.*, r.name AS router_name, b.mac IS NOT NULL AS blocked, b.reason AS block_reason,
                    (SELECT c.username FROM cards c WHERE c.last_mac = d.mac ORDER BY c.last_login_at DESC LIMIT 1) AS last_card
             $base ORDER BY d.last_seen DESC" . $pager->limitSql(),
            $params
        );
        $blocked = DB::rows('SELECT b.*, e.name AS employee_name, d.hostname FROM blocked_macs b LEFT JOIN employees e ON e.id = b.created_by LEFT JOIN devices d ON d.mac = b.mac ORDER BY b.created_at DESC');
        $routers = DB::pairs('SELECT id, name FROM routers ORDER BY name');
        return view('devices/index', compact('rows', 'pager', 'blocked', 'routers'));
    }

    public function block(Request $request): Response
    {
        $mac = normalize_mac($request->str('mac'));
        if (!$mac) {
            return $this->backWithErrors($request, ['mac' => 'عنوان MAC غير صالح.'], '/devices');
        }
        DB::query(
            'INSERT INTO blocked_macs (mac, reason, created_by, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [$mac, $request->str('reason') ?: null, Auth::id(), now()]
        );
        // الحظر على كل أجهزة MikroTik عبر IP Binding، مع فصل الجهاز
        foreach (DB::column('SELECT id FROM routers WHERE is_active = 1') as $rid) {
            RouterTasks::dispatch((int) $rid, 'binding', ['mac' => $mac, 'type' => 'blocked', 'comment' => 'sn:block'], 'mac:' . $mac, false);
        }
        Audit::log('device.block', 'حظر الجهاز ' . $mac, 'device', null, ['reason' => $request->str('reason')]);
        return $this->ok('تم حظر الجهاز على كل أجهزة MikroTik.', '/devices');
    }

    public function unblock(Request $request): Response
    {
        $mac = normalize_mac($request->str('mac'));
        if (!$mac) {
            return back('/devices');
        }
        DB::delete('blocked_macs', 'mac = ?', [$mac]);
        foreach (DB::column('SELECT id FROM routers WHERE is_active = 1') as $rid) {
            RouterTasks::dispatch((int) $rid, 'binding', ['mac' => $mac, 'type' => 'remove'], 'mac:' . $mac, false);
        }
        Audit::log('device.unblock', 'إلغاء حظر الجهاز ' . $mac, 'device');
        return $this->ok('تم إلغاء حظر الجهاز.', '/devices');
    }
}
