<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Maintenance;
use RuntimeException;

/**
 * مهام الصيانة: القائمة، الإنشاء والتعديل والإسناد، الحالة، والقطع المصروفة من المخزون.
 */
final class MaintenanceController extends Controller
{
    private function task(int $id): array
    {
        $t = Maintenance::find($id);
        if (!$t) {
            throw new HttpException(404);
        }
        if (!Maintenance::canSee($t)) {
            throw new HttpException(403);
        }
        return $t;
    }

    public function index(Request $request): Response
    {
        $all = Maintenance::seesAll();
        $f = ['state' => $request->str('state', 'open'), 'type' => $request->str('type'), 'assignee' => $all ? $request->int('assignee') : 0, 'q' => trim($request->str('q'))];
        $where = '1 = 1';
        $params = [];
        if (!$all) {
            $where .= ' AND t.assignee_id = ?';
            $params[] = Auth::id();
        } elseif ($f['assignee'] > 0) {
            $where .= ' AND t.assignee_id = ?';
            $params[] = $f['assignee'];
        } elseif ($f['assignee'] === -1) {
            $where .= ' AND t.assignee_id IS NULL';
        }
        $states = ['open' => "t.status IN ('new', 'in_progress')", 'done' => "t.status = 'done'", 'cancelled' => "t.status = 'cancelled'", 'all' => '1 = 1'];
        if (!isset($states[$f['state']])) {
            $f['state'] = 'open';
        }
        $where .= ' AND ' . $states[$f['state']];
        if (isset(Maintenance::TYPES[$f['type']])) {
            $where .= ' AND t.type = ?';
            $params[] = $f['type'];
        } else {
            $f['type'] = '';
        }
        if ($f['q'] !== '') {
            $where .= ' AND (t.title LIKE ? OR t.location LIKE ? OR t.id = ?)';
            array_push($params, DB::like($f['q']), DB::like($f['q']), (int) ltrim($f['q'], '#'));
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks t WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $order = $f['state'] === 'open'
            ? "FIELD(t.priority, 'urgent', 'high', 'normal'), t.due_on IS NULL, t.due_on, t.id DESC"
            : 'COALESCE(t.done_at, t.updated_at, t.created_at) DESC';
        $rows = DB::rows("SELECT t.*, e.name AS assignee_name, si.name AS site_name, r.name AS router_name, s.full_name AS subscriber_name,
                (SELECT COUNT(*) FROM maintenance_parts p WHERE p.task_id = t.id) AS parts
            FROM maintenance_tasks t LEFT JOIN employees e ON e.id = t.assignee_id LEFT JOIN sites si ON si.id = t.site_id
            LEFT JOIN routers r ON r.id = t.router_id LEFT JOIN subscribers s ON s.id = t.subscriber_id
            WHERE $where ORDER BY $order" . $pager->limitSql(), $params);
        $mine = $all ? '' : ' AND assignee_id = ' . (int) Auth::id();
        $stats = [
            'new' => (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks WHERE status = 'new'$mine"),
            'progress' => (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks WHERE status = 'in_progress'$mine"),
            'late' => (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks WHERE status IN ('new', 'in_progress') AND due_on < CURDATE()$mine"),
            'done' => (int) DB::value("SELECT COUNT(*) FROM maintenance_tasks WHERE status = 'done' AND done_at >= ?$mine", [date('Y-m-01 00:00:00')]),
        ];
        return view('maintenance/index', [
            'rows' => $rows, 'pager' => $pager, 'request' => $request, 'f' => $f, 'stats' => $stats, 'all' => $all,
            'employees' => $all ? DB::pairs("SELECT id, name FROM employees WHERE status = 'active' ORDER BY is_owner DESC, name") : [],
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = ['subscriber' => $request->str('subscriber'), 'router_id' => $request->int('router'), 'site_id' => $request->int('site')];
        return view('maintenance/form', ['task' => null, 'prefill' => $prefill] + $this->lists());
    }

    public function edit(Request $request, int $id): Response
    {
        return view('maintenance/form', ['task' => $this->task($id), 'prefill' => []] + $this->lists());
    }

    private function lists(): array
    {
        return [
            'employees' => DB::pairs("SELECT id, name FROM employees WHERE status = 'active' ORDER BY is_owner DESC, name"),
            'sites' => DB::pairs('SELECT id, name FROM sites ORDER BY name'),
            'routers' => DB::pairs('SELECT r.id, r.name FROM routers r WHERE 1 = 1' . Auth::routerScope('r.id')[0] . ' ORDER BY r.name', Auth::routerScope('r.id')[1]),
        ];
    }

    public function store(Request $request): Response
    {
        $d = $this->input($request, '/maintenance/create');
        if ($d instanceof Response) {
            return $d;
        }
        $id = Maintenance::save(null, $d);
        return $this->ok('تم إنشاء المهمة #' . $id . '.', '/maintenance/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->task($id);
        $d = $this->input($request, '/maintenance/' . $id . '/edit');
        if ($d instanceof Response) {
            return $d;
        }
        Maintenance::save($id, $d);
        return $this->ok('تم حفظ المهمة.', '/maintenance/' . $id);
    }

    /** @return array|Response البيانات المتحقق منها، أو رد بالأخطاء */
    private function input(Request $request, string $back): array|Response
    {
        $d = $this->validate($request, [
            'title'       => 'required|max:160',
            'type'        => 'required|in:' . implode(',', array_keys(Maintenance::TYPES)),
            'priority'    => 'required|in:' . implode(',', array_keys(Maintenance::PRIORITIES)),
            'assignee_id' => 'nullable|int',
            'site_id'     => 'nullable|int',
            'router_id'   => 'nullable|int',
            'subscriber'  => 'nullable|max:64',
            'location'    => 'nullable|max:255',
            'description' => 'nullable|max:4000',
            'due_on'      => 'nullable|date',
        ], ['title' => 'عنوان المهمة', 'type' => 'النوع', 'priority' => 'الأولوية', 'assignee_id' => 'الفنّي', 'subscriber' => 'المشترك', 'location' => 'المكان', 'description' => 'التفاصيل', 'due_on' => 'الموعد']);
        $errors = [];
        $d['assignee_id'] = (int) ($d['assignee_id'] ?? 0);
        if ($d['assignee_id'] && !DB::value("SELECT 1 FROM employees WHERE id = ? AND status = 'active'", [$d['assignee_id']])) {
            $errors['assignee_id'] = 'الفنّي غير موجود.';
        }
        $d['site_id'] = (int) ($d['site_id'] ?? 0);
        if ($d['site_id'] && !DB::value('SELECT 1 FROM sites WHERE id = ?', [$d['site_id']])) {
            $d['site_id'] = 0;
        }
        $d['router_id'] = (int) ($d['router_id'] ?? 0);
        if ($d['router_id'] && (!DB::value('SELECT 1 FROM routers WHERE id = ?', [$d['router_id']]) || !Auth::canRouter($d['router_id']))) {
            $d['router_id'] = 0;
        }
        $d['subscriber_id'] = 0;
        $sub = trim((string) ($d['subscriber'] ?? ''));
        if ($sub !== '') {
            $d['subscriber_id'] = (int) DB::value('SELECT id FROM subscribers WHERE username = ? OR phone = ? ORDER BY username = ? DESC LIMIT 1', [$sub, $sub, $sub]);
            if (!$d['subscriber_id']) {
                $errors['subscriber'] = 'لا يوجد مشترك بهذا الاسم أو الهاتف.';
            }
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, $back);
        }
        $d['location'] = trim((string) ($d['location'] ?? ''));
        $d['description'] = trim(str_replace(["\r\n", "\r"], "\n", (string) ($d['description'] ?? '')));
        $d['due_on'] = !empty($d['due_on']) ? date('Y-m-d', (int) strtotime((string) $d['due_on'])) : null;
        return $d;
    }

    public function show(Request $request, int $id): Response
    {
        $t = $this->task($id);
        $parts = DB::rows('SELECT p.*, i.name, i.unit, e.name AS by_name FROM maintenance_parts p JOIN inventory_items i ON i.id = p.item_id LEFT JOIN employees e ON e.id = p.created_by WHERE p.task_id = ? ORDER BY p.id', [$id]);
        // من يعمل على المهمة يصرف لها من الأصناف غير المرقّمة المتوفرة
        $items = Maintenance::canWork($t)
            ? DB::rows('SELECT id, name, unit, quantity FROM inventory_items WHERE is_active = 1 AND track_serial = 0 AND quantity > 0 ORDER BY name')
            : [];
        $history = DB::rows("SELECT a.summary, a.created_at, e.name FROM audit_log a LEFT JOIN employees e ON e.id = a.employee_id
            WHERE a.entity_type = 'maintenance' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 30", [$id]);
        return view('maintenance/show', ['t' => $t, 'parts' => $parts, 'items' => $items, 'history' => $history]);
    }

    public function status(Request $request, int $id): Response
    {
        $t = $this->task($id);
        if (!Maintenance::canWork($t)) {
            throw new HttpException(403);
        }
        try {
            Maintenance::setStatus($t, $request->str('status'), trim($request->str('result')));
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['status' => $e->getMessage()], '/maintenance/' . $id);
        }
        $done = ['in_progress' => 'بدأ تنفيذ المهمة.', 'done' => 'تم إنهاء المهمة.', 'cancelled' => 'تم إلغاء المهمة.', 'new' => 'أُعيد فتح المهمة.'][$request->str('status')] ?? 'تم.';
        return $this->ok($done, '/maintenance/' . $id);
    }

    public function addPart(Request $request, int $id): Response
    {
        $t = $this->task($id);
        if (!Maintenance::canWork($t)) {
            throw new HttpException(403);
        }
        $qty = (float) str_replace(['٫', ','], '.', latin_digits($request->str('qty', '0')));
        try {
            if ($qty <= 0) {
                throw new RuntimeException('أدخل كمية أكبر من صفر.');
            }
            Maintenance::addPart($t, $request->int('item_id'), $qty);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['qty' => $e->getMessage()], '/maintenance/' . $id);
        }
        return $this->ok('تم صرف القطعة من المخزون.', '/maintenance/' . $id);
    }

    public function removePart(Request $request, int $id, int $partId): Response
    {
        $t = $this->task($id);
        if (!Maintenance::canWork($t)) {
            throw new HttpException(403);
        }
        try {
            Maintenance::removePart($t, $partId);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['qty' => $e->getMessage()], '/maintenance/' . $id);
        }
        return $this->ok('أُعيدت القطعة إلى المخزون.', '/maintenance/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $t = $this->task($id);
        Maintenance::delete($t);
        return $this->ok('تم حذف المهمة، وأُعيدت قطعها إلى المخزون.', '/maintenance');
    }
}
