<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Geo;

final class SiteController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->str('q'));
        $sql = "SELECT s.*, a.name AS area_name,
                       (SELECT COUNT(*) FROM routers r WHERE r.site_id = s.id) AS routers,
                       (SELECT COUNT(*) FROM subscribers x WHERE x.site_id = s.id) AS subscribers,
                       (SELECT COUNT(*) FROM inventory_units u WHERE u.site_id = s.id AND u.status = 'installed') AS units,
                       (SELECT COUNT(*) FROM site_links l WHERE l.site_a = s.id OR l.site_b = s.id) AS links
                FROM sites s LEFT JOIN areas a ON a.id = s.area_id";
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE s.name LIKE ? OR s.address LIKE ?';
            $params = [DB::like($q), DB::like($q)];
        }
        return view('sites/index', [
            'sites' => DB::rows($sql . ' ORDER BY s.name', $params),
            'q' => $q,
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $site = DB::row('SELECT s.*, a.name AS area_name FROM sites s LEFT JOIN areas a ON a.id = s.area_id WHERE s.id = ?', [$id]);
        $this->notFoundUnless($site);
        [$rScope, $rParams] = Auth::routerScope('r.id');
        return view('sites/show', [
            'site' => $site,
            'routers' => DB::rows("SELECT r.id, r.name, r.status, r.host, r.active_hotspot, r.active_ppp FROM routers r WHERE r.site_id = ?$rScope ORDER BY r.name", array_merge([$id], $rParams)),
            'subscribers' => can('subscribers.view') ? DB::rows('SELECT s.id, s.full_name, s.phone, s.status, s.expires_at, p.name AS plan_name FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.site_id = ? ORDER BY s.full_name LIMIT 500', [$id]) : [],
            'units' => can('inventory.view') ? DB::rows("SELECT u.*, i.name AS item_name FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id WHERE u.site_id = ? AND u.status = 'installed' ORDER BY i.name", [$id]) : [],
            'links' => DB::rows(
                'SELECT l.*, IF(l.site_a = ?, b.name, a.name) AS other_name, IF(l.site_a = ?, l.site_b, l.site_a) AS other_id
                 FROM site_links l JOIN sites a ON a.id = l.site_a JOIN sites b ON b.id = l.site_b WHERE l.site_a = ? OR l.site_b = ? ORDER BY other_name',
                [$id, $id, $id, $id]
            ),
            'sites' => Geo::sitePairs(),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
        ]);
    }

    private function data(Request $request, ?array $site): array|Response
    {
        $d = $this->validate($request, [
            'name'     => 'required|max:120',
            'type'     => 'required|in:' . implode(',', array_keys(Geo::SITE_TYPES)),
            'area_id'  => 'nullable|int',
            'height_m' => 'nullable|int|between:0,1000',
            'address'  => 'nullable|max:255',
            'notes'    => 'nullable|max:500',
        ], ['name' => 'اسم الموقع', 'type' => 'النوع', 'height_m' => 'الارتفاع', 'address' => 'العنوان']);
        [, $lat, $lng, $err] = Geo::fromRequest($request);
        if ($err !== null) {
            return $this->backWithErrors($request, ['lat' => $err], $site ? '/sites/' . $site['id'] : '/sites');
        }
        return [
            'name' => $d['name'], 'type' => $d['type'],
            'area_id' => $d['area_id'] && DB::value('SELECT id FROM areas WHERE id = ?', [$d['area_id']]) ? (int) $d['area_id'] : null,
            'height_m' => $d['height_m'], 'address' => $d['address'], 'notes' => $d['notes'], 'lat' => $lat, 'lng' => $lng,
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        if ($data instanceof Response) {
            return $data;
        }
        $id = DB::insert('sites', $data + ['created_at' => now()]);
        Audit::log('site.save', 'إضافة الموقع ' . $data['name'], 'site', $id);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'id' => $id, 'message' => 'تمت إضافة الموقع.']);
        }
        return $this->ok('تمت إضافة الموقع.', '/sites/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $site = DB::row('SELECT * FROM sites WHERE id = ?', [$id]);
        $this->notFoundUnless($site);
        $data = $this->data($request, $site);
        if ($data instanceof Response) {
            return $data;
        }
        DB::update('sites', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('site.save', 'تعديل الموقع ' . $data['name'], 'site', $id);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'id' => $id, 'message' => 'تم حفظ الموقع.']);
        }
        return $this->ok('تم حفظ الموقع.', '/sites/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $site = DB::row('SELECT * FROM sites WHERE id = ?', [$id]);
        $this->notFoundUnless($site);
        DB::delete('sites', 'id = ?', [$id]);
        Audit::log('site.delete', 'حذف الموقع ' . $site['name'], 'site', $id);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'message' => 'تم حذف الموقع.']);
        }
        return $this->ok('تم حذف الموقع.', '/sites');
    }

    public function linkStore(Request $request): Response
    {
        $d = $this->validate($request, [
            'site_a'   => 'required|int',
            'site_b'   => 'required|int',
            'type'     => 'required|in:' . implode(',', array_keys(Geo::LINK_TYPES)),
            'capacity' => 'nullable|max:40',
            'label'    => 'nullable|max:120',
        ], ['site_a' => 'الموقع الأول', 'site_b' => 'الموقع الثاني', 'type' => 'نوع الرابط', 'capacity' => 'السعة', 'label' => 'الوصف'],
            function ($v) use ($request) {
                $a = $request->int('site_a');
                $b = $request->int('site_b');
                if ($a === $b) {
                    $v->addError('site_b', 'اختر موقعين مختلفين.');
                } elseif ((int) DB::value('SELECT COUNT(*) FROM sites WHERE id IN (?, ?)', [$a, $b]) !== 2) {
                    $v->addError('site_b', 'الموقع غير موجود.');
                } elseif (DB::value('SELECT id FROM site_links WHERE (site_a = ? AND site_b = ?) OR (site_a = ? AND site_b = ?)', [$a, $b, $b, $a])) {
                    $v->addError('site_b', 'يوجد رابط بين هذين الموقعين.');
                }
            });
        $id = DB::insert('site_links', [
            'site_a' => (int) $d['site_a'], 'site_b' => (int) $d['site_b'], 'type' => $d['type'],
            'capacity' => $d['capacity'], 'label' => $d['label'], 'created_at' => now(),
        ]);
        Audit::log('site.save', 'إضافة رابط بين موقعين', 'site_link', $id);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'id' => $id, 'message' => 'تمت إضافة الرابط.']);
        }
        return $this->ok('تمت إضافة الرابط.', '/sites/' . (int) $d['site_a']);
    }

    public function linkDestroy(Request $request, int $id): Response
    {
        $link = DB::row('SELECT * FROM site_links WHERE id = ?', [$id]);
        $this->notFoundUnless($link);
        DB::delete('site_links', 'id = ?', [$id]);
        Audit::log('site.delete', 'حذف رابط بين موقعين', 'site_link', $id);
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'message' => 'تم حذف الرابط.']);
        }
        return $this->ok('تم حذف الرابط.', '/sites/' . (int) $link['site_a']);
    }
}
