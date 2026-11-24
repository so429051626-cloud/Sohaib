<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\JobRunner;
use App\Services\Units;

final class PlanController extends Controller
{
    /** الحقول التي يؤدي تغييرها إلى تحديث ملف السرعة على أجهزة MikroTik */
    private const PROFILE_KEYS = [
        'rate_up', 'rate_down', 'burst_rate_up', 'burst_rate_down', 'burst_threshold_up', 'burst_threshold_down',
        'burst_time', 'fup_rate_up', 'fup_rate_down', 'shared_users', 'local_address', 'address_pool', 'dns_server',
    ];

    public function index(Request $request): Response
    {
        $plans = DB::rows(
            "SELECT p.*,
                    COUNT(s.id) AS subscribers,
                    COALESCE(SUM(s.status = 'active' AND s.expires_at > ?), 0) AS active_subs
             FROM plans p LEFT JOIN subscribers s ON s.plan_id = p.id
             GROUP BY p.id ORDER BY p.sort, p.price, p.name",
            [now()]
        );
        return view('plans/index', compact('plans'));
    }

    public function create(Request $request): Response
    {
        return view('plans/form', ['plan' => null, 'subscribers' => 0]);
    }

    public function edit(Request $request, int $id): Response
    {
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$id]);
        $this->notFoundUnless($plan);
        return view('plans/form', ['plan' => $plan, 'subscribers' => (int) DB::value('SELECT COUNT(*) FROM subscribers WHERE plan_id = ?', [$id])]);
    }

    private function data(Request $request, ?array $plan): array
    {
        $d = $this->validate($request, [
            'name'                 => 'required|max:100',
            'service'              => 'required|in:ppp,hotspot',
            'price'                => 'required|numeric|min:0',
            'duration_days'        => 'required|int|between:1,3650',
            'rate_up'              => 'nullable|rate',
            'rate_down'            => 'nullable|rate',
            'burst_rate_up'        => 'nullable|rate',
            'burst_rate_down'      => 'nullable|rate',
            'burst_threshold_up'   => 'nullable|rate',
            'burst_threshold_down' => 'nullable|rate',
            'burst_time'           => 'nullable|int|between:1,3600',
            'data_value'           => 'nullable|numeric|min:0',
            'fup_rate_up'          => 'nullable|rate',
            'fup_rate_down'        => 'nullable|rate',
            'shared_users'         => 'required|int|between:0,100',
            'local_address'        => 'nullable|ipv4',
            'address_pool'         => 'nullable|max:64|regex:/^[A-Za-z0-9._-]+$/',
            'dns_server'           => 'nullable|max:100|regex:/^[0-9.,]+$/',
            'sort'                 => 'nullable|int',
            'notes'                => 'nullable|max:255',
        ], [
            'name' => 'اسم الباقة', 'service' => 'نوع الخدمة', 'price' => 'السعر', 'duration_days' => 'مدة الاشتراك',
            'rate_up' => 'سرعة الرفع', 'rate_down' => 'سرعة التنزيل', 'burst_rate_up' => 'سرعة الدفعة للرفع',
            'burst_rate_down' => 'سرعة الدفعة للتنزيل', 'burst_threshold_up' => 'عتبة الرفع', 'burst_threshold_down' => 'عتبة التنزيل',
            'burst_time' => 'زمن الدفعة', 'data_value' => 'حجم البيانات', 'fup_rate_up' => 'سرعة الرفع بعد الاستهلاك',
            'fup_rate_down' => 'سرعة التنزيل بعد الاستهلاك', 'shared_users' => 'عدد الأجهزة', 'local_address' => 'العنوان المحلي',
            'address_pool' => 'مجمع العناوين', 'dns_server' => 'خوادم DNS',
        ], function ($v) use ($request, $plan) {
            if (DB::value('SELECT id FROM plans WHERE name = ? AND id <> ?', [$request->str('name'), $plan['id'] ?? 0])) {
                $v->addError('name', 'توجد باقة بهذا الاسم.');
            }
            if ($plan && $plan['service'] !== $request->str('service') && DB::value('SELECT 1 FROM subscribers WHERE plan_id = ? LIMIT 1', [$plan['id']])) {
                $v->addError('service', 'لا يمكن تغيير نوع الخدمة لباقة عليها مشتركون.');
            }
        });
        return [
            'name'                 => $d['name'],
            'service'              => $d['service'],
            'price'                => round((float) $d['price'], 2),
            'duration_days'        => (int) $d['duration_days'],
            'rate_up'              => Units::normalizeRate($d['rate_up']),
            'rate_down'            => Units::normalizeRate($d['rate_down']),
            'burst_rate_up'        => Units::normalizeRate($d['burst_rate_up']),
            'burst_rate_down'      => Units::normalizeRate($d['burst_rate_down']),
            'burst_threshold_up'   => Units::normalizeRate($d['burst_threshold_up']),
            'burst_threshold_down' => Units::normalizeRate($d['burst_threshold_down']),
            'burst_time'           => $d['burst_time'],
            'data_limit'           => Units::toBytes($request->str('data_value'), $request->str('data_unit', 'GB')),
            'fup_rate_up'          => Units::normalizeRate($d['fup_rate_up']),
            'fup_rate_down'        => Units::normalizeRate($d['fup_rate_down']),
            'shared_users'         => (int) $d['shared_users'],
            'local_address'        => $d['service'] === 'ppp' ? ($d['local_address'] ?: null) : null,
            'address_pool'         => $d['service'] === 'ppp' ? ($d['address_pool'] ?: null) : null,
            'dns_server'           => $d['service'] === 'ppp' ? ($d['dns_server'] ?: null) : null,
            'is_active'            => $request->bool('is_active') ? 1 : 0,
            'sort'                 => (int) ($d['sort'] ?? 0),
            'notes'                => $d['notes'],
        ];
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        $id = DB::insert('plans', $data + ['created_at' => now()]);
        Audit::log('plan.save', 'إضافة الباقة ' . $data['name'], 'plan', $id);
        return $this->ok('تمت إضافة الباقة.', '/plans');
    }

    public function update(Request $request, int $id): Response
    {
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$id]);
        $this->notFoundUnless($plan);
        $data = $this->data($request, $plan);
        DB::update('plans', $data + ['updated_at' => now()], 'id = ?', [$id]);
        Audit::log('plan.save', 'تعديل الباقة ' . $data['name'], 'plan', $id);

        $changed = false;
        foreach (self::PROFILE_KEYS as $k) {
            if ((string) $plan[$k] !== (string) $data[$k]) {
                $changed = true;
                break;
            }
        }
        if ($changed && DB::value('SELECT 1 FROM subscribers WHERE plan_id = ? AND router_id IS NOT NULL LIMIT 1', [$id])) {
            $job = JobRunner::create('profile_sync', 'تحديث ملف سرعة الباقة ' . $data['name'] . ' على أجهزة MikroTik', ['kind' => 'plan', 'id' => $id], 0, Auth::id());
            flash('success', 'تم حفظ الباقة، ويجري تحديث السرعة على أجهزة MikroTik.');
            return redirect('/jobs/' . $job);
        }
        return $this->ok('تم حفظ الباقة.', '/plans');
    }

    public function destroy(Request $request, int $id): Response
    {
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$id]);
        $this->notFoundUnless($plan);
        if (DB::value('SELECT 1 FROM subscribers WHERE plan_id = ? LIMIT 1', [$id])) {
            return $this->backWithErrors($request, ['id' => 'لا يمكن حذف باقة اشتراك عليها مشتركون. يمكنك إيقافها بدلاً من ذلك.'], '/plans');
        }
        DB::delete('router_profiles', "kind IN ('plan','plan_ppp') AND ref_id IN (?, ?)", [$id, $id + 1000000]);
        DB::delete('plans', 'id = ?', [$id]);
        Audit::log('plan.delete', 'حذف الباقة ' . $plan['name'], 'plan', $id);
        return $this->ok('تم حذف الباقة.', '/plans');
    }
}
