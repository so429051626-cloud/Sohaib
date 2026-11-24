<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustomAccounts;
use App\Services\JobRunner;
use App\Services\Units;

final class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $categories = DB::rows(
            "SELECT c.*,
                    COUNT(k.id) AS total,
                    SUM(k.status = 'new' AND k.is_disabled = 0) AS available,
                    SUM(k.status = 'sold') AS sold,
                    SUM(k.status = 'active') AS active,
                    SUM(k.status IN ('expired','depleted')) AS ended,
                    (SELECT COUNT(*) FROM category_routers cr WHERE cr.category_id = c.id) AS router_limit
             FROM categories c LEFT JOIN cards k ON k.category_id = c.id
             WHERE c.is_custom = 0
             GROUP BY c.id ORDER BY c.sort, c.price, c.name"
        );
        return view('categories/index', compact('categories'));
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$id]);
        $this->notFoundUnless($cat);
        if ((int) $cat['is_custom'] === 1) {
            // باقة حساب خاص: تُعدَّل من صفحة الحساب نفسه
            $card = CustomAccounts::cardForCategory($id);
            $this->notFoundUnless($card);
            return redirect('/cards/accounts/' . $card . '/edit');
        }
        return $this->form($cat);
    }

    private function form(?array $cat): Response
    {
        $routers = DB::pairs('SELECT id, name FROM routers ORDER BY name');
        $selected = $cat ? array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [$cat['id']])) : [];
        return view('categories/form', compact('cat', 'routers', 'selected'));
    }

    private function data(Request $request, ?array $cat): array
    {
        $d = $this->validate($request, [
            'name'                 => 'required|max:100',
            'price'                => 'required|numeric|min:0',
            'color'                => 'required|color',
            'rate_up'              => 'nullable|rate',
            'rate_down'            => 'nullable|rate',
            'burst_rate_up'        => 'nullable|rate',
            'burst_rate_down'      => 'nullable|rate',
            'burst_threshold_up'   => 'nullable|rate',
            'burst_threshold_down' => 'nullable|rate',
            'burst_time'           => 'nullable|int|between:1,3600',
            'shared_users'         => 'required|int|between:0,100',
            'low_stock'            => 'nullable|int|min:0',
            'sort'                 => 'nullable|int',
            'notes'                => 'nullable|max:255',
            'time_value'           => 'nullable|numeric|min:0',
            'validity_value'       => 'nullable|numeric|min:0',
            'data_value'           => 'nullable|numeric|min:0',
            'idle_value'           => 'nullable|int|min:0',
        ], [
            'name' => 'اسم الباقة', 'price' => 'السعر', 'color' => 'اللون', 'rate_up' => 'سرعة الرفع', 'rate_down' => 'سرعة التنزيل',
            'burst_rate_up' => 'سرعة الدفعة للرفع', 'burst_rate_down' => 'سرعة الدفعة للتنزيل', 'burst_threshold_up' => 'عتبة الرفع',
            'burst_threshold_down' => 'عتبة التنزيل', 'burst_time' => 'زمن الدفعة', 'shared_users' => 'عدد الأجهزة',
            'low_stock' => 'حد التنبيه', 'time_value' => 'مدة الاستخدام', 'validity_value' => 'الصلاحية', 'data_value' => 'حجم البيانات',
        ], function ($v) use ($request, $cat) {
            if (DB::value('SELECT id FROM categories WHERE name = ? AND id <> ?', [$request->str('name'), $cat['id'] ?? 0])) {
                $v->addError('name', 'توجد باقة بهذا الاسم.');
            }
        });
        return [
            'name'                 => $d['name'],
            'price'                => round((float) $d['price'], 2),
            'color'                => $d['color'],
            'time_limit'           => Units::toSeconds($request->str('time_value'), $request->str('time_unit', 'hour')),
            'validity'             => Units::toSeconds($request->str('validity_value'), $request->str('validity_unit', 'day')),
            'data_limit'           => Units::toBytes($request->str('data_value'), $request->str('data_unit', 'GB')),
            'rate_up'              => Units::normalizeRate($d['rate_up']),
            'rate_down'            => Units::normalizeRate($d['rate_down']),
            'burst_rate_up'        => Units::normalizeRate($d['burst_rate_up']),
            'burst_rate_down'      => Units::normalizeRate($d['burst_rate_down']),
            'burst_threshold_up'   => Units::normalizeRate($d['burst_threshold_up']),
            'burst_threshold_down' => Units::normalizeRate($d['burst_threshold_down']),
            'burst_time'           => $d['burst_time'],
            'shared_users'         => (int) $d['shared_users'],
            'lock_mac'             => $request->bool('lock_mac') ? 1 : 0,
            'idle_timeout'         => $d['idle_value'] ? (int) $d['idle_value'] * 60 : null,
            'show_on_hotspot'      => $request->bool('show_on_hotspot') ? 1 : 0,
            'low_stock'            => $d['low_stock'],
            'is_active'            => $request->bool('is_active') ? 1 : 0,
            'sort'                 => (int) ($d['sort'] ?? 0),
            'notes'                => $d['notes'],
        ];
    }

    private function syncRouters(int $id, Request $request): void
    {
        DB::delete('category_routers', 'category_id = ?', [$id]);
        if ($request->str('router_scope') === 'selected') {
            $ids = $request->ids('routers');
            $valid = $ids ? DB::column('SELECT id FROM routers WHERE id IN (' . DB::in($ids) . ')', $ids) : [];
            DB::insertMany('category_routers', array_map(static fn ($r) => ['category_id' => $id, 'router_id' => (int) $r], $valid));
        }
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::insert('categories', $data + ['created_at' => now()]);
            $this->syncRouters($id, $request);
            return $id;
        });
        Audit::log('category.create', 'إضافة الباقة ' . $data['name'], 'category', $id);
        return $this->ok('تمت إضافة الباقة.', '/categories');
    }

    public function update(Request $request, int $id): Response
    {
        $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [$id]);
        $this->notFoundUnless($cat);
        $data = $this->data($request, $cat);
        DB::transaction(function () use ($id, $data, $request) {
            DB::update('categories', $data + ['updated_at' => now()], 'id = ?', [$id]);
            $this->syncRouters($id, $request);
        });
        Audit::log('category.update', 'تعديل الباقة ' . $data['name'], 'category', $id);
        // تحديث ملف السرعة على أجهزة MikroTik إن تغيّر ما يخصه
        $profileKeys = ['rate_up', 'rate_down', 'burst_rate_up', 'burst_rate_down', 'burst_threshold_up', 'burst_threshold_down', 'burst_time', 'shared_users', 'idle_timeout'];
        $changed = false;
        foreach ($profileKeys as $k) {
            if ((string) $cat[$k] !== (string) $data[$k]) {
                $changed = true;
            }
        }
        if ($changed && DB::value('SELECT 1 FROM routers WHERE is_active = 1 LIMIT 1')) {
            $job = JobRunner::create('profile_sync', 'تحديث ملف سرعة الباقة ' . $data['name'] . ' على أجهزة MikroTik', ['kind' => 'category', 'id' => $id], 0, Auth::id());
            flash('success', 'تم حفظ الباقة، ويجري تحديث السرعة على أجهزة MikroTik.');
            return redirect('/jobs/' . $job);
        }
        return $this->ok('تم حفظ الباقة.', '/categories');
    }

    public function destroy(Request $request, int $id): Response
    {
        $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [$id]);
        $this->notFoundUnless($cat);
        if (DB::value('SELECT 1 FROM cards WHERE category_id = ? LIMIT 1', [$id]) || DB::value('SELECT 1 FROM sales WHERE category_id = ? LIMIT 1', [$id])) {
            return $this->backWithErrors($request, ['id' => 'لا يمكن حذف باقة تحتوي على كروت أو مبيعات. يمكنك إيقافها بدلاً من ذلك.'], '/categories');
        }
        DB::delete('batches', 'category_id = ?', [$id]);
        DB::delete('categories', 'id = ?', [$id]);
        Audit::log('category.delete', 'حذف الباقة ' . $cat['name'], 'category', $id);
        return $this->ok('تم حذف الباقة.', '/categories');
    }

    public function sync(Request $request, int $id): Response
    {
        $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [$id]);
        $this->notFoundUnless($cat);
        $job = JobRunner::create('profile_sync', 'مزامنة ملف سرعة الباقة ' . $cat['name'], ['kind' => 'category', 'id' => $id], 0, Auth::id());
        return redirect('/jobs/' . $job);
    }
}
