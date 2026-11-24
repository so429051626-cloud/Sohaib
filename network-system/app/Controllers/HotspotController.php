<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\CardRules;
use App\Services\HotspotSdk;
use App\Services\JobRunner;

final class HotspotController extends Controller
{
    public const LOG_FILTERS = ['q', 'router', 'result', 'reason', 'from', 'to'];

    public function index(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('r.id');
        $routers = DB::rows(
            "SELECT r.id, r.name, r.router_key, r.hotspot_dir, r.status, r.is_active,
                    (SELECT MAX(a.created_at) FROM audit_log a WHERE a.action = 'hotspot.deploy' AND a.entity_type = 'router' AND a.entity_id = r.id) AS deployed_at
             FROM routers r WHERE 1 = 1$scope ORDER BY r.name",
            $params
        );
        $since = date('Y-m-d H:i:s', time() - 86400);
        $stats = DB::row(
            "SELECT COUNT(*) AS total, COALESCE(SUM(result = 'accept'), 0) AS accepted, COALESCE(SUM(result = 'reject'), 0) AS rejected,
                    COUNT(DISTINCT mac) AS devices
             FROM login_log WHERE created_at >= ? AND event <> 'logout'",
            [$since]
        );
        $reasons = DB::rows(
            "SELECT reason, COUNT(*) AS n FROM login_log WHERE created_at >= ? AND event <> 'logout' AND result = 'reject' AND reason IS NOT NULL GROUP BY reason ORDER BY n DESC LIMIT 6",
            [$since]
        );
        $panel = Settings::panelUrl();
        return view('hotspot/index', [
            'routers' => $routers, 'stats' => $stats, 'reasons' => $reasons, 'panel' => $panel,
            'sdkVersion' => is_file(HotspotSdk::sdkPath()) ? date('Y-m-d H:i', (int) filemtime(HotspotSdk::sdkPath())) : '—',
        ]);
    }

    /** نشر ملفات الربط على أجهزة MikroTik المحددة (أو كلها) */
    public function deploy(Request $request): Response
    {
        $ids = $request->ids('routers');
        [$scope, $params] = Auth::routerScope('id');
        $sql = "SELECT id FROM routers WHERE is_active = 1$scope";
        if ($ids) {
            $sql .= ' AND id IN (' . DB::in($ids) . ')';
            $params = array_merge($params, $ids);
        }
        $targets = array_map('intval', DB::column($sql . ' ORDER BY name', $params));
        if (!$targets) {
            return $this->backWithErrors($request, ['routers' => 'لا توجد أجهزة MikroTik فعّالة ضمن التحديد.'], '/hotspot');
        }
        $panel = Settings::panelUrl();
        if ($panel === '' || !preg_match('#^https?://#', $panel)) {
            return $this->backWithErrors($request, ['panel' => 'اضبط الرابط العام للنظام في الإعدادات أولاً.'], '/hotspot');
        }
        $job = JobRunner::create('deploy_sdk', 'نشر ملفات ربط الهوتسبوت على ' . count($targets) . ' جهاز MikroTik', ['router_ids' => $targets], count($targets), Auth::id());
        return redirect('/jobs/' . $job);
    }

    /** تنزيل ملفات الربط لجهاز MikroTik محدد في ملف مضغوط */
    public function download(Request $request, int $id): Response
    {
        $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        $this->notFoundUnless($router);
        if (!Auth::canRouter($id)) {
            throw new \App\Core\HttpException(403);
        }
        $sdk = (string) file_get_contents(HotspotSdk::sdkPath());
        $config = HotspotSdk::configJs($router);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $router['name']) ?: 'router-' . $id;
        $slug = trim($slug, '-') ?: 'router-' . $id;
        if (!class_exists(\ZipArchive::class)) {
            // بدون إضافة Zip: تنزيل ملف الإعداد فقط (ملف المكتبة متاح من رابط ثابت)
            return Response::download($config, HotspotSdk::CONFIG_FILE, 'application/javascript; charset=UTF-8');
        }
        $tmp = temp_file('snzip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString(HotspotSdk::SDK_FILE, $sdk);
        $zip->addFromString(HotspotSdk::CONFIG_FILE, $config);
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);
        return Response::download($content, 'hotspot-' . $slug . '.zip', 'application/zip');
    }

    /** سجل محاولات الدخول من صفحة الهوتسبوت وأجهزة MikroTik */
    public function log(Request $request): Response
    {
        $f = [];
        foreach (self::LOG_FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        [$scope, $params] = Auth::routerScope('l.router_id', true);
        $where = '1 = 1' . $scope;
        if ($f['q'] !== '') {
            $q = latin_digits(trim($f['q']));
            $mac = normalize_mac($q);
            $where .= $mac ? ' AND l.mac = ?' : ' AND (l.login = ? OR l.ip = ?)';
            array_push($params, ...($mac ? [$mac] : [$q, $q]));
        }
        if ($f['router'] !== '' && ctype_digit($f['router'])) {
            $where .= ' AND l.router_id = ?';
            $params[] = (int) $f['router'];
        }
        if (in_array($f['result'], ['accept', 'reject', 'info'], true)) {
            $where .= ' AND l.result = ?';
            $params[] = $f['result'];
        }
        if ($f['reason'] !== '' && isset(CardRules::REASONS[$f['reason']])) {
            $where .= ' AND l.reason = ?';
            $params[] = $f['reason'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $where .= ' AND l.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $where .= ' AND l.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM login_log l WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 100);
        $rows = DB::rows(
            "SELECT l.*, r.name AS router_name, d.hostname, c.username AS card_username, s.full_name AS subscriber_name
             FROM login_log l LEFT JOIN routers r ON r.id = l.router_id LEFT JOIN devices d ON d.mac = l.mac
             LEFT JOIN cards c ON c.id = l.card_id LEFT JOIN subscribers s ON s.id = l.subscriber_id
             WHERE $where ORDER BY l.id DESC" . $pager->limitSql(),
            $params
        );
        [$rScope, $rParams] = Auth::routerScope('id');
        return view('hotspot/log', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $f, 'request' => $request,
            'routers' => DB::pairs("SELECT id, name FROM routers WHERE 1 = 1$rScope ORDER BY name", $rParams),
        ]);
    }
}
