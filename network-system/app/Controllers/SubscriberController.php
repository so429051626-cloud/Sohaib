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
use App\Services\Geo;
use App\Services\LoginRegistry;
use App\Services\RouterTasks;
use App\Services\SubscriberRules;
use App\Services\SubscriberService;
use App\Support\Spreadsheet;
use RuntimeException;

final class SubscriberController extends Controller
{
    public const FILTERS = ['q', 'plan', 'area', 'router', 'state', 'service'];

    public const STATES = [
        ''          => 'الكل',
        'active'    => 'الفعّالون',
        'expiring'  => 'ينتهون قريباً',
        'expired'   => 'المنتهون',
        'suspended' => 'الموقوفون',
        'new'       => 'بانتظار التفعيل',
        'online'    => 'المتصلون الآن',
        'debt'      => 'عليهم مستحقات',
    ];

    private function filters(Request $request): array
    {
        $f = [];
        foreach (self::FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        return $f;
    }

    /** شرط الاستعلام حسب الفلاتر ونطاق أجهزة MikroTik للموظف */
    public static function listWhere(array $f, bool $withState = true): array
    {
        [$scope, $params] = Auth::routerScope('s.router_id', true);
        $sql = '1 = 1' . $scope;
        $now = now();
        if ($f['q'] !== '') {
            $q = latin_digits(trim($f['q']));
            $mac = normalize_mac($q);
            $sql .= ' AND (s.full_name LIKE ? OR s.phone LIKE ? OR s.phone2 LIKE ? OR s.username LIKE ? OR s.static_ip = ? OR s.last_ip = ?'
                . ($mac ? ' OR s.bound_mac = ? OR s.last_mac = ?' : '') . ')';
            array_push($params, DB::like($q), DB::like($q), DB::like($q), DB::like($q), $q, $q);
            if ($mac) {
                array_push($params, $mac, $mac);
            }
        }
        foreach (['plan' => 's.plan_id', 'area' => 's.area_id', 'router' => 's.router_id'] as $k => $col) {
            if ($f[$k] !== '' && ctype_digit($f[$k])) {
                $sql .= " AND $col = ?";
                $params[] = (int) $f[$k];
            }
        }
        if (in_array($f['service'], ['ppp', 'hotspot'], true)) {
            $sql .= ' AND s.plan_id IN (SELECT id FROM plans WHERE service = ?)';
            $params[] = $f['service'];
        }
        if ($withState) {
            [$stateSql, $stateParams] = self::stateSql($f['state'], $now);
            $sql .= $stateSql;
            array_push($params, ...$stateParams);
        }
        return [$sql, $params];
    }

    public static function stateSql(string $state, string $now): array
    {
        $soon = date('Y-m-d H:i:s', time() + max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400);
        return match ($state) {
            'active'    => [" AND s.status = 'active' AND s.expires_at > ?", [$now]],
            'expiring'  => [" AND s.status = 'active' AND s.expires_at > ? AND s.expires_at <= ?", [$now, $soon]],
            'expired'   => [" AND s.status = 'active' AND s.expires_at IS NOT NULL AND s.expires_at <= ?", [$now]],
            'suspended' => [" AND s.status = 'suspended'", []],
            'new'       => [" AND s.status = 'active' AND s.expires_at IS NULL", []],
            'online'    => [' AND EXISTS (SELECT 1 FROM sessions x WHERE x.subscriber_id = s.id AND x.stopped_at IS NULL)', []],
            'debt'      => [' AND s.balance > 0', []],
            default     => ['', []],
        };
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        [$where, $params] = self::listWhere($f);
        $total = (int) DB::value("SELECT COUNT(*) FROM subscribers s WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        // أرقام الصفحة أولاً من الفهرس، ثم تفاصيلها واتصالها: لا جدول مؤقت لكل المشتركين ولا عدّ للجلسات لكل واحد منهم
        $ids = array_map('intval', DB::column("SELECT s.id FROM subscribers s WHERE $where ORDER BY s.id DESC" . $pager->limitSql(), $params));
        $rows = $ids ? DB::rows(
            'SELECT s.*, p.name AS plan_name, p.service, p.price AS plan_price, a.name AS area_name, r.name AS router_name
             FROM subscribers s JOIN plans p ON p.id = s.plan_id
             LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN routers r ON r.id = s.router_id
             WHERE s.id IN (' . DB::in($ids) . ') ORDER BY s.id DESC',
            $ids
        ) : [];
        $online = $ids ? DB::pairs('SELECT subscriber_id, COUNT(*) FROM sessions WHERE stopped_at IS NULL AND subscriber_id IN (' . DB::in($ids) . ') GROUP BY subscriber_id', $ids) : [];
        foreach ($rows as $i => $row) {
            $rows[$i]['online'] = (int) ($online[$row['id']] ?? 0);
        }
        // عدد كل حالة لعرضه على التبويبات
        [$baseWhere, $baseParams] = self::listWhere($f, false);
        $counts = [];
        foreach (array_keys(self::STATES) as $state) {
            [$st, $sp] = self::stateSql($state, now());
            $counts[$state] = (int) DB::value("SELECT COUNT(*) FROM subscribers s WHERE $baseWhere$st", array_merge($baseParams, $sp));
        }
        [$rScope, $rParams] = Auth::routerScope('id');
        return view('subscribers/index', [
            'rows' => $rows, 'pager' => $pager, 'filters' => $f, 'counts' => $counts, 'total' => $total,
            'plans' => DB::pairs('SELECT id, name FROM plans ORDER BY sort, name'),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'routers' => DB::pairs("SELECT id, name FROM routers WHERE 1 = 1$rScope ORDER BY name", $rParams),
            'request' => $request,
        ]);
    }

    public function export(Request $request): Response
    {
        [$where, $params] = self::listWhere($this->filters($request));
        $showPass = can('subscribers.passwords');
        $rows = [];
        foreach (DB::rows(
            "SELECT s.*, p.name AS plan_name, p.service, a.name AS area_name, r.name AS router_name
             FROM subscribers s JOIN plans p ON p.id = s.plan_id LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN routers r ON r.id = s.router_id
             WHERE $where ORDER BY s.id",
            $params
        ) as $s) {
            $rows[] = [
                (int) $s['id'], $s['full_name'], $s['phone'] ?? '', $s['username'], $showPass ? $s['password'] : '',
                $s['plan_name'], $s['service'] === 'ppp' ? 'PPPoE' : 'هوتسبوت', $s['area_name'] ?? '', $s['router_name'] ?? '',
                $s['status'] === 'suspended' ? 'موقوف' : 'فعّال', $s['expires_at'] ?? '', (float) $s['balance'],
                $s['static_ip'] ?? '', $s['bound_mac'] ?? '', $s['address'] ?? '', $s['created_at'],
            ];
        }
        $header = ['#', 'الاسم', 'الهاتف', 'اسم المستخدم', 'كلمة المرور', 'الباقة', 'الخدمة', 'المنطقة', 'جهاز MikroTik', 'الحالة', 'تاريخ الانتهاء', 'الرصيد المستحق', 'IP ثابت', 'MAC', 'العنوان', 'تاريخ الإضافة'];
        $name = 'subscribers-' . date('Ymd-His');
        Audit::log('subscriber.export', 'تصدير ' . count($rows) . ' مشترك', 'subscriber');
        if ($request->str('format') === 'csv' || !class_exists(\ZipArchive::class)) {
            return Response::download(Spreadsheet::csv($header, $rows), $name . '.csv', 'text/csv; charset=UTF-8');
        }
        return Response::download(Spreadsheet::xlsx($header, $rows, 'المشتركون'), $name . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ---------------------------------------------------------------------
    // الإضافة والتعديل
    // ---------------------------------------------------------------------

    private function formData(?array $sub, Request $request): array
    {
        [$rScope, $rParams] = Auth::routerScope('id');
        return [
            'sub' => $sub,
            'plans' => DB::rows('SELECT * FROM plans WHERE is_active = 1' . ($sub ? ' OR id = ' . (int) $sub['plan_id'] : '') . ' ORDER BY sort, price, name'),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'sites' => Geo::sitePairs(),
            'routers' => DB::pairs("SELECT id, name FROM routers WHERE is_active = 1$rScope ORDER BY name", $rParams),
            'methods' => SubscriberService::METHODS,
            'request' => $request,
        ];
    }

    public function create(Request $request): Response
    {
        return view('subscribers/form', $this->formData(null, $request));
    }

    public function edit(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        return view('subscribers/form', $this->formData($sub, $request));
    }

    private function find(int $id): array
    {
        $sub = DB::row('SELECT s.*, p.service FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$id]);
        $this->notFoundUnless($sub);
        if ($sub['router_id'] && !Auth::canRouter((int) $sub['router_id'])) {
            throw new HttpException(403);
        }
        return $sub;
    }

    private function data(Request $request, ?array $sub): array
    {
        [$siteId, $lat, $lng, $geoError] = Geo::fromRequest($request);
        $d = $this->validate($request, [
            'full_name'   => 'required|max:120',
            'phone'       => 'nullable|max:30',
            'phone2'      => 'nullable|max:30',
            'national_id' => 'nullable|max:30',
            'address'     => 'nullable|max:255',
            'area_id'     => 'nullable|int',
            'router_id'   => 'nullable|int',
            'username'    => 'nullable|login',
            'password'    => 'nullable|max:64|regex:/^[\x21-\x7E]+$/',
            'plan_id'     => 'required|int',
            'static_ip'   => 'nullable|ipv4',
            'bound_mac'   => 'nullable|mac',
            'notes'       => 'nullable|max:500',
        ], [
            'full_name' => 'الاسم', 'phone' => 'الهاتف', 'phone2' => 'الهاتف الثاني', 'national_id' => 'رقم الهوية',
            'address' => 'العنوان', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'plan_id' => 'الباقة',
            'static_ip' => 'عنوان IP الثابت', 'bound_mac' => 'عنوان MAC', 'router_id' => 'جهاز MikroTik',
        ], function ($v) use ($request, $sub, $geoError) {
            if ($geoError !== null) {
                $v->addError('lat', $geoError);
            }
            $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$request->int('plan_id')]);
            if (!$plan) {
                $v->addError('plan_id', 'اختر باقة.');
            } elseif (!$plan['is_active'] && (!$sub || (int) $sub['plan_id'] !== (int) $plan['id'])) {
                $v->addError('plan_id', 'الباقة المختارة موقوفة.');
            }
            $routerId = $request->int('router_id');
            if ($routerId > 0 && (!DB::value('SELECT id FROM routers WHERE id = ?', [$routerId]) || !Auth::canRouter($routerId))) {
                $v->addError('router_id', 'جهاز MikroTik غير متاح.');
            }
            if ($routerId <= 0 && Auth::routerIds() !== null) {
                $v->addError('router_id', 'اختر جهاز MikroTik.');
            }
            $username = $request->str('username');
            if ($username !== '' && LoginRegistry::isTaken($username, 'subscriber', $sub ? (int) $sub['id'] : null)) {
                $v->addError('username', 'اسم المستخدم مستخدم لكرت أو مشترك آخر.');
            }
            $ip = $request->str('static_ip');
            if ($ip !== '' && DB::value('SELECT id FROM subscribers WHERE static_ip = ? AND id <> ?', [$ip, $sub['id'] ?? 0])) {
                $v->addError('static_ip', 'العنوان مخصص لمشترك آخر.');
            }
        });
        $areaId = $d['area_id'] && DB::value('SELECT id FROM areas WHERE id = ?', [$d['area_id']]) ? (int) $d['area_id'] : null;
        return [
            'full_name'   => $d['full_name'],
            'phone'       => $d['phone'] ? latin_digits($d['phone']) : null,
            'phone2'      => $d['phone2'] ? latin_digits($d['phone2']) : null,
            'national_id' => $d['national_id'] ? latin_digits($d['national_id']) : null,
            'address'     => $d['address'],
            'area_id'     => $areaId,
            'site_id'     => $siteId,
            'lat'         => $lat,
            'lng'         => $lng,
            'router_id'   => $d['router_id'] ? (int) $d['router_id'] : null,
            'username'    => $d['username'],
            'password'    => $d['password'],
            'plan_id'     => (int) $d['plan_id'],
            'static_ip'   => $d['static_ip'] ?: null,
            'lock_mac'    => $request->bool('lock_mac') ? 1 : 0,
            'bound_mac'   => $request->bool('lock_mac') && $d['bound_mac'] ? $d['bound_mac'] : null,
            'notes'       => $d['notes'],
        ];
    }

    /** توليد اسم مستخدم غير مكرر */
    private function generateUsername(): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9._-]/', '', (string) Settings::get('subscribers.username_prefix', ''));
        for ($i = 0; $i < 50; $i++) {
            $candidate = $prefix . random_int(100000, 999999);
            if (!LoginRegistry::isTaken($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('تعذّر توليد اسم مستخدم فريد.');
    }

    public function store(Request $request): Response
    {
        $data = $this->data($request, null);
        if (\App\Core\Tenant::limitReached('max_subscribers', 'SELECT COUNT(*) FROM subscribers')) {
            return $this->backWithErrors($request, ['full_name' => 'بلغ الحساب الحد الأقصى للمشتركين.'], '/subscribers/create');
        }
        $data['username'] ??= $this->generateUsername();
        $data['password'] ??= (string) random_int(10000000, 99999999);
        try {
            $id = DB::transaction(static function () use ($data) {
                $id = DB::insert('subscribers', $data + ['status' => 'active', 'created_by' => Auth::id(), 'created_at' => now()]);
                LoginRegistry::add($data['username'], 'subscriber', $id);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->backWithErrors($request, ['username' => 'اسم المستخدم مستخدم لكرت أو مشترك آخر.'], '/subscribers/create');
            }
            throw $e;
        }
        Audit::log('subscriber.create', 'إضافة المشترك ' . $data['full_name'] . ' (' . $data['username'] . ')', 'subscriber', $id);

        $message = 'تمت إضافة المشترك.';
        if ($request->bool('activate') && can('subscribers.renew')) {
            try {
                $r = SubscriberService::renew(
                    $id, $data['plan_id'], max(1, $request->int('periods', 1)),
                    $request->str('amount') !== '' ? max(0.0, (float) latin_digits($request->str('amount'))) : null,
                    max(0.0, (float) latin_digits($request->str('paid', '0'))),
                    $this->method($request), null, 'now'
                );
                $message = 'تمت إضافة المشترك وتفعيله حتى ' . fmt_date($r['expires_at']) . '.';
                $message .= $this->routerNote($r['router']);
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        } else {
            $message .= $this->routerNote(SubscriberService::sync($id));
        }
        return $this->ok($message, '/subscribers/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        $data = $this->data($request, $sub);
        $data['username'] ??= $sub['username'];
        $data['password'] ??= $sub['password'];
        $newService = (string) DB::value('SELECT service FROM plans WHERE id = ?', [$data['plan_id']]);

        // إزالة المشترك من أجهزة MikroTik القديمة عند تغيير الاسم أو جهاز MikroTik أو نوع الخدمة
        $moved = $data['username'] !== $sub['username'] || (int) $data['router_id'] !== (int) $sub['router_id'] || $newService !== $sub['service'];
        try {
            DB::transaction(static function () use ($id, $data, $sub) {
                DB::update('subscribers', $data + ['updated_at' => now()], 'id = ?', [$id]);
                if ($data['username'] !== $sub['username']) {
                    LoginRegistry::replace('subscriber', $id, $data['username']);
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->backWithErrors($request, ['username' => 'اسم المستخدم مستخدم لكرت أو مشترك آخر.'], '/subscribers/' . $id . '/edit');
            }
            throw $e;
        }
        if ($moved) {
            $routers = array_map('intval', DB::column("SELECT router_id FROM subscriber_routers WHERE subscriber_id = ? AND state = 'present'", [$id]));
            if ($sub['router_id'] && !in_array((int) $sub['router_id'], $routers, true)) {
                $routers[] = (int) $sub['router_id'];
            }
            foreach ($routers as $rid) {
                RouterTasks::dispatch($rid, 'sub_remove', ['subscriber_id' => $id, 'username' => $sub['username'], 'service' => $sub['service']], 'sub:' . $id);
            }
        }
        Audit::log('subscriber.update', 'تعديل المشترك ' . $data['full_name'], 'subscriber', $id);
        return $this->ok('تم حفظ بيانات المشترك.' . $this->routerNote(SubscriberService::sync($id)), '/subscribers/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        SubscriberService::delete($id);
        return $this->ok('تم حذف المشترك ' . $sub['full_name'] . '.', '/subscribers');
    }

    // ---------------------------------------------------------------------
    // صفحة المشترك
    // ---------------------------------------------------------------------

    public function show(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        $sub += DB::row(
            'SELECT a.name AS area_name, si.name AS site_name, r.name AS router_name, lr.name AS last_router_name, e.name AS created_by_name, d.hostname AS last_hostname
             FROM subscribers s LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN sites si ON si.id = s.site_id LEFT JOIN routers r ON r.id = s.router_id
             LEFT JOIN routers lr ON lr.id = s.last_router_id LEFT JOIN employees e ON e.id = s.created_by
             LEFT JOIN devices d ON d.mac = s.last_mac WHERE s.id = ?',
            [$id]
        );
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$sub['plan_id']]);
        $transactions = DB::rows(
            'SELECT t.*, p.name AS plan_name, e.name AS employee_name FROM subscriber_transactions t
             LEFT JOIN plans p ON p.id = t.plan_id LEFT JOIN employees e ON e.id = t.employee_id
             WHERE t.subscriber_id = ? ORDER BY t.created_at DESC, t.id DESC LIMIT 200',
            [$id]
        );
        $sessions = DB::rows(
            'SELECT x.*, r.name AS router_name, d.hostname FROM sessions x LEFT JOIN routers r ON r.id = x.router_id
             LEFT JOIN devices d ON d.mac = x.mac WHERE x.subscriber_id = ? ORDER BY x.started_at DESC LIMIT 50',
            [$id]
        );
        $routers = DB::rows(
            'SELECT sr.*, r.name AS router_name FROM subscriber_routers sr JOIN routers r ON r.id = sr.router_id WHERE sr.subscriber_id = ? ORDER BY r.name',
            [$id]
        );
        $pending = (int) DB::value("SELECT COUNT(*) FROM router_tasks WHERE target = ? AND status = 'pending'", ['sub:' . $id]);
        $usage = DB::row(
            'SELECT COALESCE(SUM(uptime), 0) AS uptime, COALESCE(SUM(bytes_in), 0) AS bytes_in, COALESCE(SUM(bytes_out), 0) AS bytes_out
             FROM sessions WHERE subscriber_id = ? AND started_at >= ?',
            [$id, $sub['period_started_at'] ?: $sub['created_at']]
        );
        return view('subscribers/show', [
            'sub' => $sub, 'plan' => $plan, 'transactions' => $transactions, 'sessions' => $sessions, 'routers' => $routers,
            'pending' => $pending, 'usage' => $usage, 'reason' => SubscriberRules::blockReason($sub, $plan),
            'plans' => DB::rows('SELECT * FROM plans WHERE (is_active = 1 AND service = ?) OR id = ? ORDER BY sort, price, name', [$plan['service'], $plan['id']]),
            'methods' => SubscriberService::METHODS,
            'units' => can('inventory.view') ? DB::rows(
                "SELECT u.id, u.serial, u.mac, u.installed_at, i.name AS item_name FROM inventory_units u JOIN inventory_items i ON i.id = u.item_id
                 WHERE u.subscriber_id = ? AND u.status = 'installed' ORDER BY u.installed_at DESC",
                [$id]
            ) : [],
        ]);
    }

    private function method(Request $request): string
    {
        $m = $request->str('method', 'cash');
        return isset(SubscriberService::METHODS[$m]) ? $m : 'cash';
    }

    /** ملاحظة نتيجة التطبيق على جهاز MikroTik */
    private function routerNote(array $r): string
    {
        if (!empty($r['queued'])) {
            return ' تعذّر الوصول إلى جهاز MikroTik الآن، وسيُطبَّق التغيير تلقائياً عند عودته.';
        }
        return '';
    }

    public function renew(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        $planId = $request->int('plan_id', (int) $sub['plan_id']);
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$planId]);
        if (!$plan || $plan['service'] !== $sub['service']) {
            return $this->backWithErrors($request, ['plan_id' => 'اختر باقة من نفس نوع الخدمة.'], '/subscribers/' . $id);
        }
        $amount = $request->str('amount') !== '' ? (float) latin_digits($request->str('amount')) : null;
        if ($amount !== null && $amount < 0) {
            return $this->backWithErrors($request, ['amount' => 'المبلغ غير صالح.'], '/subscribers/' . $id);
        }
        $paid = max(0.0, (float) latin_digits($request->str('paid', '0')));
        try {
            $r = SubscriberService::renew(
                $id, $planId, $request->int('periods', 1), $amount, $paid, $this->method($request),
                $request->str('notes') !== '' ? mb_substr($request->str('notes'), 0, 255) : null,
                $request->str('from') === 'now' ? 'now' : 'auto'
            );
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['periods' => $e->getMessage()], '/subscribers/' . $id);
        }
        return $this->ok('تم تجديد الاشتراك حتى ' . fmt_date($r['expires_at']) . '.' . $this->routerNote($r['router']), '/subscribers/' . $id);
    }

    public function transaction(Request $request, int $id): Response
    {
        $this->find($id);
        try {
            SubscriberService::addTransaction(
                $id, $request->str('type', 'payment'), (float) latin_digits($request->str('amount', '0')),
                $this->method($request), $request->str('notes') !== '' ? mb_substr($request->str('notes'), 0, 255) : null
            );
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['amount' => $e->getMessage()], '/subscribers/' . $id);
        }
        return $this->ok('تم تسجيل القيد.', '/subscribers/' . $id);
    }

    public function status(Request $request, int $id): Response
    {
        $this->find($id);
        $status = $request->str('status') === 'suspended' ? 'suspended' : 'active';
        $reason = $request->str('reason') !== '' ? mb_substr($request->str('reason'), 0, 255) : null;
        $r = SubscriberService::setStatus($id, $status, $reason);
        return $this->ok(($status === 'suspended' ? 'تم إيقاف المشترك.' : 'تم تفعيل المشترك.') . $this->routerNote($r), '/subscribers/' . $id);
    }

    public function action(Request $request, int $id): Response
    {
        $sub = $this->find($id);
        switch ($request->str('action')) {
            case 'disconnect':
                SubscriberService::disconnect($sub);
                return $this->ok('تم إرسال أمر الفصل.', '/subscribers/' . $id);
            case 'unbind':
                DB::update('subscribers', ['bound_mac' => null, 'updated_at' => now()], 'id = ?', [$id]);
                Audit::log('subscriber.update', 'فك ربط جهاز المشترك ' . $sub['full_name'], 'subscriber', $id);
                return $this->ok('تم فك ربط الجهاز.' . $this->routerNote(SubscriberService::sync($id)), '/subscribers/' . $id);
            case 'bind':
                if (!$sub['last_mac']) {
                    return $this->backWithErrors($request, ['mac' => 'لا يوجد جهاز معروف لهذا المشترك.'], '/subscribers/' . $id);
                }
                DB::update('subscribers', ['bound_mac' => $sub['last_mac'], 'lock_mac' => 1, 'updated_at' => now()], 'id = ?', [$id]);
                Audit::log('subscriber.update', 'ربط المشترك ' . $sub['full_name'] . ' بالجهاز ' . $sub['last_mac'], 'subscriber', $id);
                return $this->ok('تم ربط المشترك بجهازه الحالي.' . $this->routerNote(SubscriberService::sync($id)), '/subscribers/' . $id);
            case 'resync':
                $r = SubscriberService::sync($id);
                if (!$sub['router_id']) {
                    return $this->backWithErrors($request, ['router_id' => 'حدّد جهاز MikroTik الخاص بالمشترك أولاً.'], '/subscribers/' . $id);
                }
                return $r['ok'] && empty($r['queued'])
                    ? $this->ok('تم تطبيق المشترك على جهاز MikroTik.', '/subscribers/' . $id)
                    : $this->ok('تعذّر الوصول إلى جهاز MikroTik الآن' . ($r['error'] ? ' (' . $r['error'] . ')' : '') . '، وسيُعاد المحاولة تلقائياً.', '/subscribers/' . $id);
            case 'password':
                $password = $request->str('password') !== '' ? $request->str('password') : (string) random_int(10000000, 99999999);
                if (!preg_match('/^[\x21-\x7E]{1,64}$/', $password)) {
                    return $this->backWithErrors($request, ['password' => 'كلمة المرور تقبل الأحرف الإنجليزية والأرقام والرموز فقط.'], '/subscribers/' . $id);
                }
                DB::update('subscribers', ['password' => $password, 'updated_at' => now()], 'id = ?', [$id]);
                Audit::log('subscriber.update', 'تغيير كلمة مرور المشترك ' . $sub['full_name'], 'subscriber', $id);
                return $this->ok('تم تغيير كلمة المرور.' . $this->routerNote(SubscriberService::sync($id)), '/subscribers/' . $id);
        }
        throw new HttpException(400);
    }
}
