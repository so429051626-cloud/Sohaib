<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\MikroTik\MikroTikException;
use App\Services\CableDoctor;
use RuntimeException;

/**
 * صفحة فحص الكابلات: اختيار الجهاز، منافذه، فحص منفذ (قراءة فقط)، الفحص العميق لقياس الطول ومكان العطل،
 * سجل الفحوص، وإعداد الفحص الدوري مع تنبيه تيليجرام.
 */
final class CableController extends Controller
{
    private function router(int $id): array
    {
        $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        return $router;
    }

    public function index(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('r.id');
        $routers = DB::rows('SELECT r.id, r.name, r.status, r.wan_interface, r.is_active FROM routers r WHERE 1 = 1' . $scope . ' ORDER BY r.is_active DESC, r.name', $params);
        $selected = $request->int('router');
        if (!$selected || !in_array($selected, array_map('intval', array_column($routers, 'id')), true)) {
            $online = array_values(array_filter($routers, static fn ($r) => $r['status'] === 'online' && (int) $r['is_active'] === 1));
            $selected = (int) (($online[0] ?? $routers[0] ?? ['id' => 0])['id']);
        }
        $history = DB::rows("SELECT c.id, c.router_id, c.port, c.level, c.summary, c.length_m, c.source, c.created_at, r.name AS router_name, e.name AS by_name
            FROM cable_checks c JOIN routers r ON r.id = c.router_id LEFT JOIN employees e ON e.id = c.employee_id
            WHERE (c.source <> 'auto' OR c.level <> 'ok')" . $scope . ' ORDER BY c.id DESC LIMIT 40', $params);
        // آخر حالة لكل منفذ: كم منفذاً فيه خلل أو يحتاج انتباهاً
        $latest = DB::rows('SELECT c.level, COUNT(*) AS n FROM cable_checks c JOIN (SELECT MAX(id) AS id FROM cable_checks GROUP BY router_id, port) x ON x.id = c.id
            JOIN routers r ON r.id = c.router_id WHERE 1 = 1' . $scope . ' GROUP BY c.level', $params);
        $levels = array_column($latest, 'n', 'level');
        $scanned = json_decode((string) Settings::get('cable.scanned', '{}'), true);
        $lastScan = is_array($scanned) && $scanned ? max(array_map('intval', $scanned)) : 0;
        return view('cables/index', [
            'routers' => $routers, 'selected' => $selected, 'history' => $history, 'request' => $request,
            'stats' => ['bad' => (int) ($levels['bad'] ?? 0), 'warn' => (int) ($levels['warn'] ?? 0), 'ok' => (int) ($levels['ok'] ?? 0),
                'checks' => (int) DB::value("SELECT COUNT(*) FROM cable_checks c JOIN routers r ON r.id = c.router_id WHERE c.source <> 'auto' AND c.created_at >= ?" . $scope, array_merge([date('Y-m-d H:i:s', time() - 30 * 86400)], $params))],
            'lastScan' => $lastScan, 'canDeep' => Auth::can('routers.control'),
            'links' => CableDoctor::links(array_map('intval', array_column($routers, 'id'))),
        ]);
    }

    /** منافذ الجهاز (اتصال مباشر به) */
    public function ports(Request $request): Response
    {
        $router = $this->router($request->int('router'));
        Session::release();
        try {
            return json_response(['ok' => true, 'router' => ['id' => (int) $router['id'], 'name' => $router['name']], 'ports' => CableDoctor::ports($router)]);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()], 422);
        }
    }

    /** فحص منفذ: قراءة فقط */
    public function test(Request $request): Response
    {
        $router = $this->router($request->int('router_id'));
        $port = trim($request->str('port'));
        if ($port === '' || mb_strlen($port) > 60) {
            return json_response(['ok' => false, 'message' => 'اختر منفذاً.'], 422);
        }
        Session::release();
        try {
            $r = CableDoctor::run($router, $port);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()], 422);
        } catch (RuntimeException $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if (isset($r['deep_offer']) && !Auth::can('routers.control')) {
            $r['deep_offer'] = ['ok' => false, 'reason' => 'الفحص العميق يحتاج صلاحية التحكم بجهاز MikroTik.', 'poe' => (bool) ($r['deep_offer']['poe'] ?? false)];
        }
        Audit::log('router.cable', 'فحص كابل المنفذ ' . $port . ' على ' . $router['name'] . ' — ' . CableDoctor::LEVELS[$r['level']], 'router', (int) $router['id']);
        return json_response(['ok' => true, 'result' => $r]);
    }

    /** الفحص العميق: يعطّل المنفذ ثانيتين ليقيس الأزواج ثم يعيده (بتأكيد من المستخدم) */
    public function deep(Request $request): Response
    {
        if (!Auth::can('routers.control')) {
            throw new HttpException(403);
        }
        $router = $this->router($request->int('router_id'));
        $port = trim($request->str('port'));
        if ($port === '' || mb_strlen($port) > 60) {
            return json_response(['ok' => false, 'message' => 'اختر منفذاً.'], 422);
        }
        if (!$request->bool('confirm')) {
            return json_response(['ok' => false, 'message' => 'أكّد الفحص العميق أولاً: يفصل المنفذ ثوانيَ معدودة.'], 422);
        }
        Session::release();
        try {
            $r = CableDoctor::deep($router, $port);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()], 422);
        } catch (RuntimeException $e) {
            return json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        Audit::log('router.cable', 'فحص عميق لكابل المنفذ ' . $port . ' على ' . $router['name'] . ' (فُصل المنفذ ثوانيَ وأعيد) — ' . CableDoctor::LEVELS[$r['level']]
            . ($r['length'] !== null ? ' · الطول ' . $r['length'] . ' م' : ''), 'router', (int) $router['id']);
        return json_response(['ok' => true, 'result' => $r]);
    }

    /** نتيجة فحص محفوظ */
    public function show(Request $request, int $id): Response
    {
        $row = DB::row('SELECT c.*, r.name AS router_name FROM cable_checks c JOIN routers r ON r.id = c.router_id WHERE c.id = ?', [$id]);
        if (!$row) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter((int) $row['router_id'])) {
            throw new HttpException(403);
        }
        $r = json_decode((string) $row['result'], true) ?: [];
        $r += ['level' => $row['level'], 'headline' => $row['summary'], 'findings' => [], 'pairs' => [], 'link' => [], 'counters' => [], 'links' => [], 'sfp' => [], 'port' => $row['port']];
        $r['router'] = $row['router_name'];
        $r['deep_offer'] = null; // فحص قديم: يُعاد الفحص من قائمة المنافذ
        $r['saved'] = ['at' => $row['created_at'], 'ago' => time_ago($row['created_at']), 'source' => $row['source']];
        $r['id'] = (int) $row['id'];
        return json_response(['ok' => true, 'result' => $r]);
    }

    public function settings(Request $request): Response
    {
        $d = $this->validate($request, [
            'interval_hours' => 'required|int|min:1|max:168',
            'errors_min'     => 'required|int|min:1|max:100000',
            'downs_min'      => 'required|int|min:1|max:1000',
        ], ['interval_hours' => 'مدة الفحص الدوري', 'errors_min' => 'حد الأخطاء', 'downs_min' => 'حد الانقطاعات']);
        Settings::setMany([
            'cable.auto' => $request->bool('auto') ? '1' : '0',
            'cable.interval_hours' => (string) (int) $d['interval_hours'],
            'cable.errors_min' => (string) (int) $d['errors_min'],
            'cable.downs_min' => (string) (int) $d['downs_min'],
        ]);
        Audit::log('settings.update', 'إعدادات فحص الكابلات الدوري', 'settings', null);
        return $this->ok('تم حفظ إعدادات الفحص الدوري.', '/cables');
    }
}
