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
use App\Core\Settings;
use App\Services\OutageService;
use Throwable;

/**
 * سجل الانقطاعات وتعويض المشتركين.
 */
final class OutageController extends Controller
{
    private function outage(int $id): array
    {
        $o = DB::row('SELECT o.*, r.name AS router_name, a.name AS area_name, e.name AS comp_by FROM outages o LEFT JOIN routers r ON r.id = o.router_id LEFT JOIN areas a ON a.id = o.area_id LEFT JOIN employees e ON e.id = o.compensated_by WHERE o.id = ?', [$id]);
        if (!$o) {
            throw new HttpException(404);
        }
        if ($o['router_id'] !== null && !Auth::canRouter((int) $o['router_id'])) {
            throw new HttpException(403);
        }
        return $o;
    }

    private static function stamp(string $value): ?string
    {
        $value = trim(str_replace('T', ' ', latin_digits($value)));
        $t = $value !== '' ? strtotime($value) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    public function index(Request $request): Response
    {
        $f = ['state' => $request->str('state'), 'cause' => $request->str('cause'), 'router' => $request->int('router')];
        [$scope, $params] = Auth::routerScope('o.router_id', true);
        $where = '1 = 1' . $scope;
        if ($f['state'] === 'open') {
            $where .= ' AND o.ended_at IS NULL';
        } elseif ($f['state'] === 'closed') {
            $where .= ' AND o.ended_at IS NOT NULL';
        }
        if (isset(OutageService::CAUSES[$f['cause']])) {
            $where .= ' AND o.cause = ?';
            $params[] = $f['cause'];
        }
        if ($f['router'] > 0) {
            $where .= ' AND o.router_id = ?';
            $params[] = $f['router'];
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM outages o WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $rows = DB::rows("SELECT o.*, r.name AS router_name, a.name AS area_name FROM outages o LEFT JOIN routers r ON r.id = o.router_id LEFT JOIN areas a ON a.id = o.area_id WHERE $where ORDER BY o.ended_at IS NULL DESC, o.started_at DESC" . $pager->limitSql(), $params);
        $month = date('Y-m-01 00:00:00');
        [$rs, $rp] = Auth::routerScope('router_id', true);
        return view('outages/index', [
            'rows' => $rows, 'pager' => $pager, 'request' => $request, 'f' => $f,
            'routers' => DB::pairs('SELECT r.id, r.name FROM routers r WHERE 1 = 1' . Auth::routerScope('r.id')[0] . ' ORDER BY r.name', Auth::routerScope('r.id')[1]),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'stats' => [
                'open' => (int) DB::value('SELECT COUNT(*) FROM outages WHERE ended_at IS NULL' . $rs, $rp),
                'month' => (int) DB::value('SELECT COUNT(*) FROM outages WHERE started_at >= ?' . $rs, array_merge([$month], $rp)),
                'seconds' => (int) DB::value('SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW()))), 0) FROM outages WHERE started_at >= ?' . $rs, array_merge([$month], $rp)),
                'affected' => (int) DB::value('SELECT COALESCE(SUM(affected), 0) FROM outages WHERE started_at >= ?' . $rs, array_merge([$month], $rp)),
            ],
            'compEnabled' => OutageService::compEnabled(),
        ]);
    }

    public function store(Request $request): Response
    {
        $d = $this->validate($request, [
            'title' => 'nullable|max:160', 'cause' => 'required|in:' . implode(',', array_keys(OutageService::CAUSES)), 'router_id' => 'nullable|int', 'area_id' => 'nullable|int',
            'started_at' => 'required|max:30', 'ended_at' => 'nullable|max:30', 'affected' => 'nullable|int|between:0,10000000', 'notes' => 'nullable|max:500',
        ], ['title' => 'العنوان', 'cause' => 'السبب', 'started_at' => 'بداية الانقطاع', 'ended_at' => 'نهاية الانقطاع', 'affected' => 'عدد المتأثرين', 'notes' => 'ملاحظات']);
        $routerId = !empty($d['router_id']) ? (int) $d['router_id'] : null;
        $areaId = !empty($d['area_id']) ? (int) $d['area_id'] : null;
        if ($routerId !== null && (!Auth::canRouter($routerId) || !DB::value('SELECT id FROM routers WHERE id = ?', [$routerId]))) {
            return $this->backWithErrors($request, ['router_id' => 'جهاز MikroTik غير موجود.'], '/outages');
        }
        if ($areaId !== null && !DB::value('SELECT id FROM areas WHERE id = ?', [$areaId])) {
            $areaId = null;
        }
        $start = self::stamp((string) $d['started_at']);
        $end = $d['ended_at'] !== null ? self::stamp((string) $d['ended_at']) : null;
        if ($start === null || strtotime($start) > time() + 300) {
            return $this->backWithErrors($request, ['started_at' => 'وقت البداية غير صالح.'], '/outages');
        }
        if ($end !== null && strtotime($end) < strtotime($start)) {
            return $this->backWithErrors($request, ['ended_at' => 'النهاية يجب أن تكون بعد البداية.'], '/outages');
        }
        $name = $routerId !== null ? (string) DB::value('SELECT name FROM routers WHERE id = ?', [$routerId]) : ($areaId !== null ? (string) DB::value('SELECT name FROM areas WHERE id = ?', [$areaId]) : 'عام');
        $id = DB::insert('outages', [
            'router_id' => $routerId, 'area_id' => $areaId, 'title' => $d['title'] ?: 'انقطاع ' . $name, 'cause' => $d['cause'], 'source' => 'manual',
            'started_at' => $start, 'ended_at' => null, 'affected' => $d['affected'] !== null ? (int) $d['affected'] : OutageService::affectedCount($routerId, $areaId),
            'notes' => $d['notes'], 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        Audit::log('outage.create', 'تسجيل انقطاع: ' . ($d['title'] ?: 'انقطاع ' . $name), 'outage', $id);
        if ($end !== null) {
            OutageService::close($id, (int) strtotime($end), false);
        }
        return $this->ok('تم تسجيل الانقطاع.', '/outages/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $o = $this->outage($id);
        $enabled = OutageService::compEnabled();
        return view('outages/show', [
            'o' => $o, 'duration' => OutageService::duration($o), 'compEnabled' => $enabled,
            'preview' => $enabled && can('outages.compensate') ? OutageService::preview($o) : null,
            'applied' => $o['compensated_at'] !== null ? DB::rows('SELECT c.*, s.full_name, s.username FROM outage_compensations c JOIN subscribers s ON s.id = c.subscriber_id WHERE c.outage_id = ? ORDER BY s.full_name', [$id]) : [],
            'rule' => OutageService::rule(),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $o = $this->outage($id);
        $d = $this->validate($request, [
            'title' => 'required|max:160', 'cause' => 'required|in:' . implode(',', array_keys(OutageService::CAUSES)),
            'started_at' => 'required|max:30', 'ended_at' => 'nullable|max:30', 'affected' => 'required|int|between:0,10000000', 'notes' => 'nullable|max:500',
        ], ['title' => 'العنوان', 'cause' => 'السبب', 'started_at' => 'بداية الانقطاع', 'ended_at' => 'نهاية الانقطاع', 'affected' => 'عدد المتأثرين', 'notes' => 'ملاحظات']);
        $data = ['title' => $d['title'], 'cause' => $d['cause'], 'affected' => (int) $d['affected'], 'notes' => $d['notes'], 'updated_at' => now()];
        // بعد التعويض تبقى الأوقات كما هي لأنها أساس الأيام المضافة
        if ($o['compensated_at'] === null) {
            $start = self::stamp((string) $d['started_at']);
            $end = $d['ended_at'] !== null ? self::stamp((string) $d['ended_at']) : null;
            if ($start === null || ($end !== null && strtotime($end) < strtotime($start))) {
                return $this->backWithErrors($request, ['ended_at' => 'النهاية يجب أن تكون بعد البداية.'], '/outages/' . $id);
            }
            $data['started_at'] = $start;
            if ($o['ended_at'] !== null && $end !== null) {
                $data['ended_at'] = $end;
            }
        }
        DB::update('outages', $data, 'id = ?', [$id]);
        Audit::log('outage.update', 'تعديل الانقطاع: ' . $d['title'], 'outage', $id);
        if ($o['ended_at'] === null && $d['ended_at'] !== null && ($end = self::stamp((string) $d['ended_at'])) !== null) {
            OutageService::close($id, (int) strtotime($end));
        }
        return $this->ok('تم الحفظ.', '/outages/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->outage($id);
        OutageService::close($id);
        return $this->ok('تم إغلاق الانقطاع.', '/outages/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $o = $this->outage($id);
        if ($o['compensated_at'] !== null) {
            flash('error', 'لا يُحذف انقطاع تم تعويضه.');
            return redirect('/outages/' . $id);
        }
        DB::delete('outages', 'id = ?', [$id]);
        Audit::log('outage.delete', 'حذف الانقطاع: ' . $o['title'], 'outage', $id);
        return $this->ok('تم حذف الانقطاع.', '/outages');
    }

    /** تطبيق التعويض بعد المعاينة والتأكيد */
    public function compensate(Request $request, int $id): Response
    {
        $this->outage($id);
        try {
            $r = OutageService::compensate($id);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            return redirect('/outages/' . $id);
        }
        return $this->ok('تم تمديد اشتراك ' . num($r['count']) . ' مشترك بمقدار ' . num($r['days']) . ' يوم.', '/outages/' . $id);
    }

    // ------------------------------------------------------------------
    // الإعدادات (التعويض يفعّله صاحب الشبكة نفسه)
    // ------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        return view('outages/settings', ['rule' => OutageService::rule(), 'owner' => Auth::isOwner()]);
    }

    public function settingsSave(Request $request): Response
    {
        $values = ['outages.auto' => $request->bool('auto') ? '1' : '0', 'notify.power' => $request->bool('notify_power') ? '1' : '0'];
        if (Auth::isOwner()) {
            $d = $this->validate($request, ['min_hours' => 'required|numeric|between:0,720', 'hours_per_day' => 'required|numeric|between:1,720', 'max_days' => 'required|int|between:1,60'],
                ['min_hours' => 'أقل مدة انقطاع', 'hours_per_day' => 'ساعات الانقطاع لكل يوم', 'max_days' => 'أقصى أيام التعويض']);
            $enabled = $request->bool('comp_enabled');
            $values += [
                'outages.comp_enabled' => $enabled ? '1' : '0', 'outages.comp_auto' => $enabled && $request->bool('comp_auto') ? '1' : '0',
                'outages.comp_min_hours' => (string) (float) $d['min_hours'], 'outages.comp_hours_per_day' => (string) (float) $d['hours_per_day'], 'outages.comp_max_days' => (string) (int) $d['max_days'],
            ];
        }
        Settings::setMany($values);
        Audit::log('outage.settings', 'تعديل إعدادات الانقطاعات' . (Auth::isOwner() ? ' والتعويض (' . (($values['outages.comp_enabled'] ?? '0') === '1' ? 'مفعّل' : 'مطفأ') . ')' : ''), 'settings');
        return $this->ok('تم حفظ الإعدادات.', '/outages/settings');
    }
}
