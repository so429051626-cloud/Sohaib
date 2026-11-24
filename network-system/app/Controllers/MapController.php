<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Geo;
use App\Services\SubscriberRules;

final class MapController extends Controller
{
    public function index(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('r.id');
        return view('map/index', [
            'view' => Geo::view(),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'sites' => Geo::sitePairs(),
            // أجهزة MikroTik للإضافة على الخريطة: ما ليس له موقع أولاً
            'routers' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'placed' => (bool) $r['placed']], DB::rows(
                "SELECT r.id, r.name, (COALESCE(r.lat, s.lat) IS NOT NULL) AS placed FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE 1 = 1$rScope ORDER BY placed, r.name",
                $rParams
            )),
        ]);
    }

    /** وضع جهاز MikroTik على الخريطة (أو نقله)، مع تعديل اسمه لمن يملك صلاحية إدارة الأجهزة */
    public function placeRouter(Request $request): Response
    {
        $id = $request->int('id');
        $router = DB::row('SELECT id, name FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            return json_response(['ok' => false, 'message' => 'اختر جهاز MikroTik.'], 422);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        $lat = trim(latin_digits($request->str('lat')));
        $lng = trim(latin_digits($request->str('lng')));
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            return json_response(['ok' => false, 'message' => 'الإحداثيات غير صحيحة.'], 422);
        }
        $update = ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'updated_at' => now()];
        $name = trim($request->str('name'));
        if ($name !== '' && $name !== $router['name'] && can('routers.manage')) {
            if (mb_strlen($name) > 100) {
                return json_response(['ok' => false, 'message' => 'الاسم طويل (100 حرف على الأكثر).'], 422);
            }
            $update['name'] = $name;
        }
        DB::update('routers', $update, 'id = ?', [$id]);
        Audit::log('map.move', 'وضع جهاز MikroTik ' . ($update['name'] ?? $router['name']) . ' على الخريطة' . (isset($update['name']) ? ' (كان اسمه ' . $router['name'] . ')' : ''), 'router', $id);
        return json_response(['ok' => true, 'id' => $id, 'name' => $update['name'] ?? $router['name'], 'message' => 'تمت إضافة الجهاز إلى الخريطة.']);
    }

    /** إزالة الموقع الخاص بجهاز MikroTik (يعود إلى موقع برجه إن كان له برج على الخريطة) */
    public function unplaceRouter(Request $request, int $id): Response
    {
        $router = DB::row('SELECT id, name FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        DB::update('routers', ['lat' => null, 'lng' => null, 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('map.move', 'إزالة جهاز MikroTik ' . $router['name'] . ' من الخريطة', 'router', $id);
        return json_response(['ok' => true, 'message' => 'تمت إزالة الجهاز من الخريطة.']);
    }

    /** بيانات الخريطة: المواقع، أجهزة MikroTik بحالتها، المشتركون، الروابط */
    public function data(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('r.id');
        [$sScope, $sParams] = Auth::routerScope('s.router_id', true);

        $sites = array_map(static fn ($s) => [
            'id' => (int) $s['id'], 'name' => $s['name'], 'type' => $s['type'], 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'],
            'area' => $s['area_name'], 'height' => $s['height_m'] !== null ? (int) $s['height_m'] : null, 'address' => $s['address'], 'notes' => $s['notes'],
            'area_id' => $s['area_id'] !== null ? (int) $s['area_id'] : null,
            'routers' => (int) $s['routers'], 'subscribers' => (int) $s['subscribers'], 'units' => (int) $s['units'],
        ], DB::rows(
            'SELECT s.*, a.name AS area_name,
                    (SELECT COUNT(*) FROM routers r WHERE r.site_id = s.id) AS routers,
                    (SELECT COUNT(*) FROM subscribers x WHERE x.site_id = s.id) AS subscribers,
                    (SELECT COUNT(*) FROM inventory_units u WHERE u.site_id = s.id AND u.status = \'installed\') AS units
             FROM sites s LEFT JOIN areas a ON a.id = s.area_id WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL ORDER BY s.name'
        ));

        $routers = array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'],
            'lat' => (float) $r['plat'], 'lng' => (float) $r['plng'], 'own' => $r['lat'] !== null,
            'clients' => (int) $r['active_hotspot'] + (int) $r['active_ppp'], 'cpu' => $r['cpu_load'] !== null ? (int) $r['cpu_load'] : null,
            'site' => $r['site_name'],
        ], DB::rows(
            "SELECT r.id, r.name, r.status, r.lat, r.lng, r.active_hotspot, r.active_ppp, r.cpu_load, s.name AS site_name,
                    COALESCE(r.lat, s.lat) AS plat, COALESCE(r.lng, s.lng) AS plng
             FROM routers r LEFT JOIN sites s ON s.id = r.site_id
             WHERE COALESCE(r.lat, s.lat) IS NOT NULL AND COALESCE(r.lng, s.lng) IS NOT NULL$rScope ORDER BY r.name",
            $rParams
        ));

        $subscribers = [];
        if (can('subscribers.view')) {
            foreach (DB::rows(
                "SELECT s.id, s.full_name, s.status, s.expires_at, s.lat, s.lng, p.name AS plan_name, st.name AS site_name,
                        COALESCE(s.lat, st.lat) AS plat, COALESCE(s.lng, st.lng) AS plng,
                        EXISTS (SELECT 1 FROM sessions x WHERE x.subscriber_id = s.id AND x.stopped_at IS NULL) AS online
                 FROM subscribers s JOIN plans p ON p.id = s.plan_id LEFT JOIN sites st ON st.id = s.site_id
                 WHERE s.lat IS NOT NULL AND s.lng IS NOT NULL$sScope ORDER BY s.full_name LIMIT 5000",
                $sParams
            ) as $s) {
                $subscribers[] = [
                    'id' => (int) $s['id'], 'name' => $s['full_name'], 'plan' => $s['plan_name'], 'site' => $s['site_name'],
                    'state' => SubscriberRules::displayState($s), 'online' => (bool) $s['online'],
                    'lat' => (float) $s['plat'], 'lng' => (float) $s['plng'],
                ];
            }
        }

        $links = array_map(static fn ($l) => [
            'id' => (int) $l['id'], 'type' => $l['type'], 'capacity' => $l['capacity'], 'label' => $l['label'],
            'a' => ['id' => (int) $l['site_a'], 'name' => $l['a_name'], 'lat' => (float) $l['a_lat'], 'lng' => (float) $l['a_lng']],
            'b' => ['id' => (int) $l['site_b'], 'name' => $l['b_name'], 'lat' => (float) $l['b_lat'], 'lng' => (float) $l['b_lng']],
        ], DB::rows(
            'SELECT l.*, a.name AS a_name, a.lat AS a_lat, a.lng AS a_lng, b.name AS b_name, b.lat AS b_lat, b.lng AS b_lng
             FROM site_links l JOIN sites a ON a.id = l.site_a JOIN sites b ON b.id = l.site_b
             WHERE a.lat IS NOT NULL AND b.lat IS NOT NULL'
        ));

        return json_response([
            'ok' => true, 'sites' => $sites, 'routers' => $routers, 'subscribers' => $subscribers, 'links' => $links,
            'unplaced' => [
                'sites' => (int) DB::value('SELECT COUNT(*) FROM sites WHERE lat IS NULL OR lng IS NULL'),
                'routers' => (int) DB::value("SELECT COUNT(*) FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE COALESCE(r.lat, s.lat) IS NULL$rScope", $rParams),
            ],
        ]);
    }

    /** تحريك عنصر على الخريطة */
    public function position(Request $request): Response
    {
        $type = $request->str('type');
        $id = $request->int('id');
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return json_response(['ok' => false, 'message' => 'الإحداثيات غير صحيحة.'], 422);
        }
        $table = ['site' => 'sites', 'router' => 'routers', 'subscriber' => 'subscribers'][$type] ?? null;
        if ($table === null || !DB::value("SELECT id FROM $table WHERE id = ?", [$id])) {
            throw new HttpException(404);
        }
        if ($type === 'router' && !Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        if ($type === 'subscriber') {
            $routerId = DB::value('SELECT router_id FROM subscribers WHERE id = ?', [$id]);
            if (!can('subscribers.manage') || ($routerId && !Auth::canRouter((int) $routerId))) {
                throw new HttpException(403);
            }
        }
        DB::update($table, ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'updated_at' => now()], 'id = ?', [$id]);
        Audit::log('map.move', 'تحديث موقع على الخريطة', $type, $id);
        return json_response(['ok' => true]);
    }

    /** حفظ العرض الحالي كعرض افتراضي */
    public function saveView(Request $request): Response
    {
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        $zoom = max(3, min(19, $request->int('zoom', 12)));
        Settings::setMany(['map.lat' => (string) round($lat, 6), 'map.lng' => (string) round($lng, 6), 'map.zoom' => (string) $zoom]);
        return json_response(['ok' => true, 'message' => 'تم حفظ العرض الافتراضي.']);
    }
}
