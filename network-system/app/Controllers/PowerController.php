<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PowerMonitor;

/**
 * مراقبة البطاريات والطاقة الشمسية: جهد كل جهاز MikroTik وسجله وحدوده.
 */
final class PowerController extends Controller
{
    private function router(int $id): array
    {
        $r = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        return $r;
    }

    public function index(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('r.id');
        $routers = DB::rows("SELECT r.*, s.name AS site_name FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE 1 = 1 $scope ORDER BY r.name", $params);
        $since = date('Y-m-d H:i:s', time() - 86400);
        $stats = [];
        foreach (DB::rows('SELECT router_id, MIN(voltage) AS mn, MAX(voltage) AS mx, COUNT(*) AS c FROM router_power WHERE created_at >= ? GROUP BY router_id', [$since]) as $s) {
            $stats[(int) $s['router_id']] = $s;
        }
        $spark = [];
        foreach (DB::rows('SELECT router_id, FLOOR(UNIX_TIMESTAMP(created_at) / 1800) AS t, AVG(voltage) AS v FROM router_power WHERE created_at >= ? GROUP BY router_id, t ORDER BY t', [$since]) as $p) {
            $spark[(int) $p['router_id']][] = round((float) $p['v'], 2);
        }
        $ever = array_flip(array_map('intval', DB::column('SELECT DISTINCT router_id FROM router_power')));
        $counts = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'unsupported' => 0];
        foreach ($routers as &$r) {
            $id = (int) $r['id'];
            $r['p_state'] = PowerMonitor::display($r, isset($ever[$id]));
            $r['p_stats'] = $stats[$id] ?? null;
            $r['p_spark'] = $spark[$id] ?? [];
            $r['p_eta'] = $r['voltage_crit'] !== null && $r['status'] === 'online' && $r['p_state'] !== 'crit' ? PowerMonitor::eta($id, (float) $r['voltage_crit']) : null;
            if (isset($counts[$r['p_state']])) {
                $counts[$r['p_state']]++;
            } elseif ($r['p_state'] === 'none') {
                $counts['ok']++;
            }
        }
        unset($r);
        return view('power/index', ['routers' => $routers, 'counts' => $counts]);
    }

    public function show(Request $request, int $id): Response
    {
        $r = $this->router($id);
        $range = isset(PowerMonitor::RANGES[$request->str('range')]) ? $request->str('range') : '24h';
        $has = (bool) DB::value('SELECT 1 FROM router_power WHERE router_id = ? LIMIT 1', [$id]);
        return view('power/show', [
            'router' => $r, 'range' => $range, 'series' => PowerMonitor::series($id, $range), 'state' => PowerMonitor::display($r, $has),
            'eta' => $r['voltage_crit'] !== null && $r['status'] === 'online' ? PowerMonitor::eta($id, (float) $r['voltage_crit']) : null,
            'last' => DB::row('SELECT * FROM router_power WHERE router_id = ? ORDER BY id DESC LIMIT 1', [$id]),
        ]);
    }

    public function data(Request $request, int $id): Response
    {
        $this->router($id);
        $range = isset(PowerMonitor::RANGES[$request->str('range')]) ? $request->str('range') : '24h';
        return json_response(['ok' => true, 'range' => $range] + PowerMonitor::series($id, $range));
    }

    /** حدّا التحذير والخطر للجهاز */
    public function update(Request $request, int $id): Response
    {
        $r = $this->router($id);
        $d = $this->validate($request, ['voltage_warn' => 'nullable|numeric|between:1,400', 'voltage_crit' => 'nullable|numeric|between:1,400'],
            ['voltage_warn' => 'حد التحذير', 'voltage_crit' => 'حد الخطر'], function ($v) use ($request) {
                $w = $request->str('voltage_warn');
                $c = $request->str('voltage_crit');
                if ($w !== '' && $c !== '' && is_numeric(latin_digits($w)) && is_numeric(latin_digits($c)) && (float) latin_digits($c) >= (float) latin_digits($w)) {
                    $v->addError('voltage_crit', 'حد الخطر يجب أن يكون أقل من حد التحذير.');
                }
            });
        DB::update('routers', [
            'voltage_warn' => $d['voltage_warn'] !== null ? round((float) $d['voltage_warn'], 1) : null,
            'voltage_crit' => $d['voltage_crit'] !== null ? round((float) $d['voltage_crit'], 1) : null,
            'power_state' => null, 'updated_at' => now(),
        ], 'id = ?', [$id]);
        Audit::log('power.thresholds', 'حدود جهد ' . $r['name'] . ': تحذير ' . ($d['voltage_warn'] ?? '—') . ' · خطر ' . ($d['voltage_crit'] ?? '—'), 'router', $id);
        return $this->ok('تم حفظ الحدود.', '/power/' . $id);
    }
}
