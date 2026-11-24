<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Platform\TenantProvisioner;
use Throwable;

/**
 * لوحة مسؤول المنصة: الحسابات، المستخدمون، النشاط، الإعدادات.
 */
final class AdminController extends Controller
{
    public const STATUS = [
        'trial'     => ['تجريبي', 'badge-info'],
        'active'    => ['مفعّل', 'badge-success'],
        'expired'   => ['منتهي', 'badge-warning'],
        'suspended' => ['موقوف', 'badge-danger'],
        'cancelled' => ['ملغى', 'badge-outline'],
    ];

    // ------------------------------------------------------------------
    // الدخول
    // ------------------------------------------------------------------

    public function loginForm(Request $request): Response
    {
        return view('admin/login', [
            'paneTitle' => \App\Core\Brand::NAME, 'paneMark' => \App\Core\Brand::mark(96, 'login-brand-mark'), 'paneSub' => 'لوحة مسؤول المنصة',
        ], 'split');
    }

    public function login(Request $request): Response
    {
        $r = AdminAuth::attempt($request->str('username'), (string) $request->input('password', ''), $request->ip());
        if (!$r['ok']) {
            Session::flashInput(['username' => $request->str('username')]);
            flash('error', $r['message']);
            return redirect('/admin/login');
        }
        return redirect(!empty($r['twofa']) ? '/admin/login/verify' : '/admin');
    }

    public function verifyForm(Request $request): Response
    {
        if (AdminAuth::check()) {
            return redirect('/admin');
        }
        if (!AdminAuth::pendingTwoFactor()) {
            return redirect('/admin/login');
        }
        return view('admin/verify', [
            'paneTitle' => \App\Core\Brand::NAME, 'paneMark' => \App\Core\Brand::mark(96, 'login-brand-mark'), 'paneSub' => 'لوحة مسؤول المنصة',
        ], 'split');
    }

    public function verify(Request $request): Response
    {
        $r = AdminAuth::verifyTwoFactor($request->str('code'), $request->ip());
        if (!$r['ok']) {
            flash('error', $r['message']);
            return redirect(!empty($r['expired']) ? '/admin/login' : '/admin/login/verify');
        }
        return redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        AdminAuth::logout();
        Session::resume();
        return redirect('/admin/login');
    }

    // ------------------------------------------------------------------
    // الرئيسية
    // ------------------------------------------------------------------

    public function dashboard(Request $request): Response
    {
        $counts = DB::pairs('SELECT status, COUNT(*) FROM tenants GROUP BY status');
        $signups = DB::pairs('SELECT DATE(created_at), COUNT(*) FROM tenants WHERE created_at >= ? GROUP BY DATE(created_at)', [date('Y-m-d', strtotime('-29 days'))]);
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[$d] = (int) ($signups[$d] ?? 0);
        }
        return view('admin/dashboard', [
            'counts' => $counts,
            'total' => array_sum(array_map('intval', $counts)),
            'series' => $series,
            'ending' => DB::rows("SELECT * FROM tenants WHERE (status = 'trial' AND trial_ends_at <= ?) OR (status = 'active' AND paid_until IS NOT NULL AND paid_until <= ?) ORDER BY COALESCE(trial_ends_at, paid_until) LIMIT 10",
                [date('Y-m-d H:i:s', time() + 4 * 86400), date('Y-m-d H:i:s', time() + 7 * 86400)]),
            'latest' => DB::rows('SELECT * FROM tenants ORDER BY id DESC LIMIT 8'),
            'activity' => DB::rows('SELECT a.*, t.name AS tenant_name, t.slug FROM platform_activity a LEFT JOIN tenants t ON t.id = a.tenant_id ORDER BY a.id DESC LIMIT 12'),
            'online' => (int) DB::value('SELECT COUNT(*) FROM tenants WHERE last_seen_at >= ?', [date('Y-m-d H:i:s', time() - 900)]),
            'revenue' => (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM tenant_payments WHERE created_at >= ?', [date('Y-m-01 00:00:00')]),
        ], 'admin');
    }

    // ------------------------------------------------------------------
    // الحسابات
    // ------------------------------------------------------------------

    public function tenants(Request $request): Response
    {
        $f = ['q' => $request->str('q'), 'status' => $request->str('status')];
        $where = '1 = 1';
        $params = [];
        if ($f['q'] !== '') {
            $where .= ' AND (name LIKE ? OR slug LIKE ? OR owner_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            array_push($params, ...array_fill(0, 5, DB::like($f['q'])));
        }
        if (isset(self::STATUS[$f['status']])) {
            $where .= ' AND status = ?';
            $params[] = $f['status'];
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM tenants WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 30);
        $rows = DB::rows("SELECT * FROM tenants WHERE $where ORDER BY id DESC" . $pager->limitSql(), $params);
        $counts = DB::pairs('SELECT status, COUNT(*) FROM tenants GROUP BY status');
        return view('admin/tenants', compact('rows', 'pager', 'f', 'counts', 'request'), 'admin');
    }

    private function tenant(int $id): array
    {
        $t = DB::row('SELECT * FROM tenants WHERE id = ?', [$id]);
        if (!$t) {
            throw new HttpException(404);
        }
        return $t;
    }

    public function tenant_show(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        $stats = [];
        $employees = [];
        $audit = [];
        $panelUrl = '';
        try {
            $pdo = Tenant::pdo($t);
            $q = static fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
            $stats = [
                'routers' => $q('SELECT COUNT(*) FROM routers'),
                'online_routers' => $q("SELECT COUNT(*) FROM routers WHERE status = 'online'"),
                'cards' => $q('SELECT COUNT(*) FROM cards'),
                'subscribers' => $q('SELECT COUNT(*) FROM subscribers'),
                'employees' => $q('SELECT COUNT(*) FROM employees'),
                'sales' => (float) $pdo->query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE status = 'completed' AND created_at >= '" . date('Y-m-01') . "'")->fetchColumn(),
                'sessions' => $q('SELECT COUNT(*) FROM sessions WHERE stopped_at IS NULL'),
            ];
            // SELECT * لأن قاعدة حساب لم يُزر بعد التحديث قد لا تحوي الأعمدة الجديدة (totp_enabled)
            $employees = array_map(static function (array $e): array {
                unset($e['password_hash'], $e['totp_secret'], $e['recovery_codes']);
                return $e;
            }, $pdo->query('SELECT * FROM employees ORDER BY is_owner DESC, name')->fetchAll());
            $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
            $st->execute(['general.panel_url']);
            $panelUrl = (string) ($st->fetchColumn() ?: '');
            $audit = $pdo->query('SELECT a.action, a.summary AS description, a.ip, a.created_at, e.name AS employee_name FROM audit_log a LEFT JOIN employees e ON e.id = a.employee_id ORDER BY a.id DESC LIMIT 25')->fetchAll();
        } catch (Throwable $e) {
            log_message('error', 'admin tenant stats: ' . $e->getMessage());
        }
        $activity = DB::rows('SELECT * FROM platform_activity WHERE tenant_id = ? ORDER BY id DESC LIMIT 25', [$id]);
        $payments = DB::rows('SELECT * FROM tenant_payments WHERE tenant_id = ? ORDER BY id DESC LIMIT 50', [$id]);
        $dbSize = (int) (\App\Core\Server::databaseSizes()[$t['db_name']] ?? 0);
        $plans = DB::rows('SELECT * FROM platform_plans WHERE is_active = 1 OR id = ? ORDER BY sort, price, id', [(int) ($t['plan_id'] ?? 0)]);
        $siblings = !empty($t['group_id']) ? DB::rows('SELECT id, name, slug, status FROM tenants WHERE group_id = ? AND id <> ? ORDER BY name', [$t['group_id'], $id]) : [];
        $aiUsed = \App\Services\Ai::used($id);
        $phase = Tenant::phase($t);
        $panelDefault = Platform::rootUrl() . '/' . $t['slug'];
        $aliases = Platform::aliases($id);
        return view('admin/tenant', compact('t', 'stats', 'employees', 'audit', 'activity', 'payments', 'dbSize', 'plans', 'siblings', 'aiUsed', 'phase', 'panelUrl', 'panelDefault', 'aliases'), 'admin');
    }

    public function tenant_create(Request $request): Response
    {
        return view('admin/tenant-create', [], 'admin');
    }

    public function tenant_store(Request $request): Response
    {
        $slug = strtolower(trim($request->str('slug')));
        $d = $this->validate($request, [
            'name' => 'required|max:100', 'slug' => 'required|max:40', 'owner_name' => 'required|max:120', 'phone' => 'nullable|max:30',
            'email' => 'nullable|email|max:120', 'username' => 'required|username|max:60', 'password' => 'required|min:8|max:100',
            'status' => 'required|in:trial,active',
        ], ['name' => 'اسم الشبكة', 'slug' => 'اسم الحساب', 'owner_name' => 'اسم المالك', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور'], function ($v) use ($slug) {
            if (($e = TenantProvisioner::slugError($slug)) !== null) {
                $v->addError('slug', $e);
            }
        });
        try {
            $t = TenantProvisioner::create(['slug' => $slug, 'name' => $d['name'], 'owner_name' => $d['owner_name'], 'phone' => $d['phone'], 'email' => $d['email'],
                'username' => $d['username'], 'password' => (string) $request->input('password'), 'status' => $d['status'], 'ip' => $request->ip()]);
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['slug' => $e->getMessage()], '/admin/tenants/create');
        }
        Platform::log((int) $t['id'], 'admin', 'tenant.create', 'إنشاء حساب ' . $t['name'] . ' من لوحة المسؤول', AdminAuth::user()['name']);
        return $this->ok('تم إنشاء الحساب.', '/admin/tenants/' . $t['id']);
    }

    public function tenant_import(Request $request): Response
    {
        return view('admin/tenant-import', [], 'admin');
    }

    /** إنشاء حساب من نسخة احتياطية لنظام سابق */
    public function tenant_import_store(Request $request): Response
    {
        $slug = strtolower(trim($request->str('slug')));
        $errors = [];
        if (($e = TenantProvisioner::slugError($slug)) !== null) {
            $errors['slug'] = $e;
        }
        $file = $request->file('backup');
        if ($err = $request->uploadError('backup')) {
            $errors['backup'] = $err;
        } elseif (!$file) {
            $errors['backup'] = 'اختر ملف النسخة الاحتياطية.';
        }
        $oldKey = null;
        if ($config = $request->file('old_config')) {
            $oldKey = \App\Services\BackupService::keyFromConfig((string) @file_get_contents($config['tmp_name'], false, null, 0, 200000));
            if ($oldKey === null) {
                $errors['old_config'] = 'ملف الإعداد لا يحتوي على مفتاح النظام.';
            }
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, '/admin/tenants/import');
        }
        Session::release();
        try {
            $t = TenantProvisioner::import(['slug' => $slug, 'status' => $request->str('status') === 'trial' ? 'trial' : 'active', 'ip' => $request->ip()], $file['tmp_name'], $oldKey);
        } catch (Throwable $e) {
            Session::resume();
            return $this->backWithErrors($request, ['backup' => $e->getMessage()], '/admin/tenants/import');
        }
        Session::resume();
        Platform::log((int) $t['id'], 'admin', 'tenant.import', 'استيراد حساب ' . $t['name'] . ' من نسخة احتياطية', AdminAuth::user()['name']);
        if ($t['secrets_ok'] === false) {
            flash('warning', 'تم استيراد الحساب. كلمات مرور أجهزة MikroTik مشفّرة بمفتاح النظام السابق.');
            return redirect('/admin/tenants/' . $t['id']);
        }
        return $this->ok('تم استيراد الحساب بكل بياناته.', '/admin/tenants/' . $t['id']);
    }

    /** تفعيل، تمديد، إيقاف، إلغاء */
    public function tenant_status(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        $action = $request->str('action');
        $admin = AdminAuth::user()['name'];
        $now = now();
        switch ($action) {
            case 'activate':
                $plan = Platform::plan($request->int('plan_id') ?: null);
                $months = $request->int('months');
                if ($months <= 0 && $plan && $request->str('until') === '') {
                    $months = (int) $plan['months'];
                }
                $r = \App\Platform\Billing::activate($t, [
                    'months' => $months, 'until' => $request->str('until'), 'plan' => $plan, 'admin' => (string) $admin,
                    'amount' => (float) str_replace(',', '.', latin_digits($request->str('amount'))),
                    'method' => $request->str('method'), 'note' => $request->str('note'),
                ]);
                Platform::log($id, 'admin', 'tenant.activate', 'تفعيل الحساب' . ($r['until'] ? ' حتى ' . substr($r['until'], 0, 10) : ' دون تاريخ انتهاء') . ($plan ? ' — ' . $plan['name'] : ''), $admin);
                flash('success', 'تم تفعيل الحساب.');
                return redirect('/admin/tenants/' . $id, $r['payment_id'] ? ['invoice' => $r['payment_id']] : []);
            case 'extend':
                $days = max(1, min(365, $request->int('days', 7)));
                $from = $t['trial_ends_at'] && strtotime($t['trial_ends_at']) > time() ? strtotime($t['trial_ends_at']) : time();
                DB::update('tenants', ['status' => 'trial', 'trial_ends_at' => date('Y-m-d H:i:s', $from + $days * 86400), 'status_reason' => null, 'updated_at' => $now], 'id = ?', [$id]);
                Platform::log($id, 'admin', 'tenant.extend', 'تمديد الفترة التجريبية ' . $days . ' يوم', $admin);
                return $this->ok('تم تمديد الفترة التجريبية.', '/admin/tenants/' . $id);
            case 'suspend':
            case 'cancel':
                $status = $action === 'suspend' ? 'suspended' : 'cancelled';
                DB::update('tenants', ['status' => $status, 'status_reason' => mb_substr($request->str('reason'), 0, 255) ?: null, 'updated_at' => $now], 'id = ?', [$id]);
                Platform::log($id, 'admin', 'tenant.' . $action, ($action === 'suspend' ? 'إيقاف' : 'إلغاء') . ' الحساب' . ($request->str('reason') !== '' ? ': ' . $request->str('reason') : ''), $admin);
                return $this->ok($action === 'suspend' ? 'تم إيقاف الحساب.' : 'تم إلغاء الحساب.', '/admin/tenants/' . $id);
        }
        throw new HttpException(400);
    }

    public function tenant_update(Request $request, int $id): Response
    {
        $this->tenant($id);
        $d = $this->validate($request, ['name' => 'required|max:100', 'owner_name' => 'required|max:120', 'phone' => 'nullable|max:30', 'email' => 'nullable|email|max:120', 'notes' => 'nullable|max:2000',
            'max_routers' => 'nullable|int|between:0,100000', 'max_subscribers' => 'nullable|int|between:0,10000000', 'max_employees' => 'nullable|int|between:0,100000',
            'ai_limit' => 'nullable|int|between:0,1000000', 'panel_url' => 'nullable|max:255|regex:#^https?://[^\\s]+$#i', 'slug' => 'nullable|max:40'],
            ['panel_url' => 'الرابط العام للنظام', 'name' => 'اسم الشبكة', 'owner_name' => 'اسم المالك', 'max_routers' => 'حد أجهزة MikroTik', 'max_subscribers' => 'حد المشتركين', 'max_employees' => 'حد الموظفين', 'ai_limit' => 'حد رسائل الذكاء الاصطناعي']);
        // اسم الحساب في الرابط: تغييره يغيّر رابط نظام الزبون، والرابط القديم يبقى يعمل ويُحوَّل إلى الجديد
        $current = $this->tenant($id);
        $slug = strtolower(trim((string) ($d['slug'] ?? '')));
        $renamed = false;
        if ($slug !== '' && $slug !== $current['slug']) {
            try {
                TenantProvisioner::rename($current, $slug);
                $renamed = true;
            } catch (\RuntimeException $e) {
                return $this->backWithErrors($request, ['slug' => $e->getMessage()], '/admin/tenants/' . $id);
            }
            Platform::log($id, 'admin', 'tenant.rename', 'تغيير اسم الحساب في الرابط من ' . $current['slug'] . ' إلى ' . $slug, AdminAuth::user()['name']);
        }
        $plan = Platform::plan($request->int('plan_id') ?: null);
        DB::update('tenants', ['name' => $d['name'], 'owner_name' => $d['owner_name'], 'phone' => $d['phone'], 'email' => $d['email'], 'notes' => $d['notes'],
            'max_routers' => $d['max_routers'] !== null ? (int) $d['max_routers'] : null, 'max_subscribers' => $d['max_subscribers'] !== null ? (int) $d['max_subscribers'] : null,
            'max_employees' => $d['max_employees'] !== null ? (int) $d['max_employees'] : null,
            'plan_id' => $plan ? (int) $plan['id'] : null, 'ai_enabled' => $request->bool('ai_enabled') ? 1 : 0, 'ai_limit' => $d['ai_limit'] !== null ? (int) $d['ai_limit'] : null,
            'updated_at' => now()], 'id = ?', [$id]);
        // الرابط العام لنظام الحساب (الفارغ يعني الرابط الافتراضي على المنصة)
        $panel = rtrim(trim((string) ($d['panel_url'] ?? '')), '/');
        if ($renamed && $panel === Platform::rootUrl() . '/' . $current['slug']) {
            $panel = ''; // الرابط الافتراضي القديم في النموذج: يتبع الاسم الجديد
        }
        if ($request->input('panel_url') !== null) {
            $t = $this->tenant($id);
            try {
                Tenant::pdo($t)->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)')
                    ->execute(['general.panel_url', $panel !== '' ? $panel : Platform::rootUrl() . '/' . $t['slug'], now()]);
            } catch (Throwable $e) {
                log_message('error', 'admin tenant panel url: ' . $e->getMessage());
            }
        }
        Platform::log($id, 'admin', 'tenant.update', 'تعديل بيانات الحساب', AdminAuth::user()['name']);
        $message = $renamed ? 'تم حفظ البيانات، وصار رابط الحساب: ' . Platform::rootUrl() . '/' . $slug : 'تم حفظ البيانات.';
        if ($request->bool('ai_enabled') && empty($current['ai_enabled'])) {
            // تفعيل الذكاء للحساب يُظهر المساعد الذكي في قائمته الجانبية وشريطه العلوي فوراً
            $message .= \App\Services\Ai::status(null) === 'ok' ? ' المساعد الذكي يظهر الآن في قائمة هذا الحساب.' : ' يظهر المساعد الذكي في قائمة الحساب بعد ضبط الذكاء الاصطناعي من صفحة «الإعداد والاستهلاك».';
        }
        return $this->ok($message, '/admin/tenants/' . $id);
    }

    /** دخول المسؤول إلى نظام الزبون بحساب المالك أو مستخدم محدد */
    public function tenant_impersonate(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        $pdo = Tenant::pdo($t);
        $empId = $request->int('employee_id');
        $st = $pdo->prepare($empId > 0 ? "SELECT id, name FROM employees WHERE id = ? AND status = 'active'" : "SELECT id, name FROM employees WHERE is_owner = 1 AND status = 'active' ORDER BY id LIMIT 1");
        $st->execute($empId > 0 ? [$empId] : []);
        $emp = $st->fetch();
        if (!$emp) {
            flash('error', 'لا يوجد مستخدم فعّال.');
            return redirect('/admin/tenants/' . $id);
        }
        if (!Tenant::usable($t)) {
            flash('error', 'الحساب غير مفعّل؛ فعّله أولاً.');
            return redirect('/admin/tenants/' . $id);
        }
        $admin = AdminAuth::user();
        Platform::log($id, 'admin', 'tenant.impersonate', 'الدخول إلى الحساب باسم ' . $emp['name'], $admin['name']);
        return redirect('/' . $t['slug'] . '/auth/ticket/' . Platform::ticket($id, (int) $emp['id'], 'impersonate', (int) $admin['id']));
    }

    /** تعيين كلمة مرور جديدة لمستخدم في نظام الزبون */
    public function tenant_password(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        $pass = (string) $request->input('password', '');
        if (mb_strlen($pass) < 8) {
            return $this->backWithErrors($request, ['password' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.'], '/admin/tenants/' . $id);
        }
        $pdo = Tenant::pdo($t);
        $st = $pdo->prepare('UPDATE employees SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?');
        $st->execute([password_hash($pass, PASSWORD_DEFAULT), now(), $request->int('employee_id')]);
        if ($st->rowCount() === 0) {
            throw new HttpException(404);
        }
        $pdo->prepare('UPDATE employee_devices SET revoked_at = ? WHERE employee_id = ? AND revoked_at IS NULL')->execute([now(), $request->int('employee_id')]);
        Platform::log($id, 'admin', 'tenant.password', 'تعيين كلمة مرور جديدة لمستخدم #' . $request->int('employee_id'), AdminAuth::user()['name']);
        return $this->ok('تم تعيين كلمة المرور.', '/admin/tenants/' . $id);
    }

    /** حذف اسم سابق للحساب: روابطه القديمة تتوقف ويصير الاسم متاحاً لغيره */
    public function tenant_alias_delete(Request $request, int $id): Response
    {
        $this->tenant($id);
        $slug = $request->str('slug');
        if (DB::delete('tenant_aliases', 'tenant_id = ? AND slug = ?', [$id, $slug]) === 1) {
            Platform::log($id, 'admin', 'tenant.rename', 'حذف الاسم السابق ' . $slug, AdminAuth::user()['name']);
        }
        return $this->ok('تم حذف الاسم السابق.', '/admin/tenants/' . $id);
    }

    /** مستخدم فقد هاتفه ورموز الاسترجاع: يُلغى تحققه بخطوتين ليدخل بكلمة المرور */
    public function tenant_twofactor(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        try {
            $st = Tenant::pdo($t)->prepare('UPDATE employees SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL, updated_at = ? WHERE id = ? AND totp_enabled = 1');
            $st->execute([now(), $request->int('employee_id')]);
            $done = $st->rowCount() === 1;
        } catch (\Throwable) {
            $done = false;
        }
        if (!$done) {
            flash('error', 'التحقق بخطوتين غير مفعّل لهذا المستخدم.');
            return redirect('/admin/tenants/' . $id);
        }
        Platform::log($id, 'admin', 'tenant.twofactor', 'إلغاء التحقق بخطوتين لمستخدم #' . $request->int('employee_id'), AdminAuth::user()['name']);
        return $this->ok('تم إلغاء التحقق بخطوتين لهذا المستخدم.', '/admin/tenants/' . $id);
    }

    /** نسخة احتياطية فورية لبيانات حساب، تُنزَّل كملف */
    public function tenant_backup(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        Session::release();
        try {
            Tenant::enter($t);
            $name = \App\Services\BackupService::create(true);
            $content = (string) file_get_contents((string) \App\Services\BackupService::path($name));
        } catch (Throwable $e) {
            Tenant::leave();
            Session::resume();
            flash('error', 'تعذّر إنشاء النسخة: ' . $e->getMessage());
            return redirect('/admin/tenants/' . $id);
        }
        Tenant::leave();
        Session::resume();
        Platform::log($id, 'admin', 'tenant.backup', 'تنزيل نسخة احتياطية للحساب', AdminAuth::user()['name']);
        return Response::download($content, $t['slug'] . '-' . $name, 'application/gzip');
    }

    /** إنشاء شبكة إضافية لمالك حساب قائم: قاعدة بيانات مستقلة ودخول بنفس بيانات المالك */
    public function tenant_network(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        $slug = strtolower(trim($request->str('slug')));
        $d = $this->validate($request, ['name' => 'required|max:100', 'slug' => 'required|max:40'], ['name' => 'اسم الشبكة', 'slug' => 'اسم الحساب'], function ($v) use ($slug) {
            if (($e = TenantProvisioner::slugError($slug)) !== null) {
                $v->addError('slug', $e);
            }
        });
        try {
            $new = TenantProvisioner::addNetwork($t, ['slug' => $slug, 'name' => $d['name'], 'ip' => $request->ip()]);
        } catch (Throwable $e) {
            return $this->backWithErrors($request, ['slug' => $e->getMessage()], '/admin/tenants/' . $id);
        }
        Platform::log((int) $new['id'], 'admin', 'tenant.network', 'إضافة شبكة ' . $new['name'] . ' لمالك ' . $t['name'], AdminAuth::user()['name']);
        return $this->ok('تمت إضافة الشبكة.', '/admin/tenants/' . $new['id']);
    }

    /** ربط الحساب بمجموعة شبكات مالك آخر أو فصله عنها */
    public function tenant_group(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        if ($request->str('action') === 'unlink') {
            DB::update('tenants', ['group_id' => null], 'id = ?', [$id]);
            if (!empty($t['group_id']) && (int) DB::value('SELECT COUNT(*) FROM tenants WHERE group_id = ?', [$t['group_id']]) === 1) {
                DB::update('tenants', ['group_id' => null], 'group_id = ?', [$t['group_id']]);
            }
            Platform::log($id, 'admin', 'tenant.group', 'فصل الحساب عن شبكات المالك', AdminAuth::user()['name']);
            return $this->ok('تم الفصل.', '/admin/tenants/' . $id);
        }
        $other = Platform::tenantBySlug(strtolower(trim($request->str('slug'))));
        if (!$other || (int) $other['id'] === $id) {
            return $this->backWithErrors($request, ['link_slug' => 'اسم الحساب غير موجود.'], '/admin/tenants/' . $id);
        }
        $group = (int) ($other['group_id'] ?: ($t['group_id'] ?: min($id, (int) $other['id'])));
        if (!empty($t['group_id']) && (int) $t['group_id'] !== $group) {
            DB::update('tenants', ['group_id' => $group], 'group_id = ?', [$t['group_id']]);
        }
        DB::update('tenants', ['group_id' => $group], 'id IN (?, ?)', [$id, $other['id']]);
        Platform::log($id, 'admin', 'tenant.group', 'ربط الحساب بشبكات مالك ' . $other['name'], AdminAuth::user()['name']);
        return $this->ok('تم الربط.', '/admin/tenants/' . $id);
    }

    // ------------------------------------------------------------------
    // الأمان: التحقق بخطوتين لدخول المسؤول
    // ------------------------------------------------------------------

    public function security(Request $request): Response
    {
        $a = AdminAuth::user();
        $setup = Session::get('totp_setup');
        $codes = Session::pull('totp_codes');
        return view('admin/security', [
            'enabled' => (int) $a['totp_enabled'] === 1, 'setup' => is_string($setup) ? $setup : null, 'codes' => is_array($codes) ? $codes : null,
            'left' => count((array) json_decode((string) ($a['recovery_codes'] ?? '[]'), true)),
            'uri' => is_string($setup) ? \App\Services\Totp::uri($setup, (string) $a['username'], \App\Core\Brand::NAME) : null,
        ], 'admin');
    }

    public function totpStart(Request $request): Response
    {
        Session::set('totp_setup', \App\Services\Totp::secret());
        return redirect('/admin/security');
    }

    public function totpConfirm(Request $request): Response
    {
        $secret = Session::get('totp_setup');
        if (!is_string($secret) || !\App\Services\Totp::verify($secret, $request->str('code'))) {
            flash('error', 'رمز التحقق غير صحيح.');
            return redirect('/admin/security');
        }
        $codes = \App\Services\Totp::recoveryCodes();
        DB::update('platform_admins', [
            'totp_secret' => Platform::seal($secret), 'totp_enabled' => 1,
            'recovery_codes' => json_encode(array_map([\App\Services\Totp::class, 'hashRecovery'], $codes)),
        ], 'id = ?', [AdminAuth::id()]);
        Session::forget('totp_setup');
        Session::set('totp_codes', $codes);
        Platform::log(null, 'admin', 'admin.totp', 'تفعيل التحقق بخطوتين', AdminAuth::user()['name']);
        return $this->ok('تم تفعيل التحقق بخطوتين.', '/admin/security');
    }

    private function passwordOk(Request $request): bool
    {
        return password_verify((string) $request->input('password', ''), (string) AdminAuth::user()['password_hash']);
    }

    public function totpDisable(Request $request): Response
    {
        if (!$this->passwordOk($request)) {
            flash('error', 'كلمة المرور غير صحيحة.');
            return redirect('/admin/security');
        }
        DB::update('platform_admins', ['totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null], 'id = ?', [AdminAuth::id()]);
        Session::forget('totp_setup');
        Platform::log(null, 'admin', 'admin.totp', 'إيقاف التحقق بخطوتين', AdminAuth::user()['name']);
        return $this->ok('تم إيقاف التحقق بخطوتين.', '/admin/security');
    }

    public function totpCodes(Request $request): Response
    {
        if (!$this->passwordOk($request) || (int) AdminAuth::user()['totp_enabled'] !== 1) {
            flash('error', 'كلمة المرور غير صحيحة.');
            return redirect('/admin/security');
        }
        $codes = \App\Services\Totp::recoveryCodes();
        DB::update('platform_admins', ['recovery_codes' => json_encode(array_map([\App\Services\Totp::class, 'hashRecovery'], $codes))], 'id = ?', [AdminAuth::id()]);
        Session::set('totp_codes', $codes);
        return $this->ok('صدرت رموز استرجاع جديدة.', '/admin/security');
    }

    // ------------------------------------------------------------------
    // إشعارات المسؤول على تيليجرام
    // ------------------------------------------------------------------

    public function notifications(Request $request): Response
    {
        return view('admin/notifications', [
            'hasToken' => \App\Services\AdminNotify::hasOwnToken(), 'configured' => \App\Services\AdminNotify::configured(),
            'shared' => !\App\Services\AdminNotify::hasOwnToken() && \App\Services\AdminNotify::token() !== '',
        ], 'admin');
    }

    public function notificationsSave(Request $request): Response
    {
        $d = $this->validate($request, ['chat' => 'nullable|max:200', 'weekly_day' => 'required|int|between:0,6'], ['chat' => 'معرّف المحادثة', 'weekly_day' => 'يوم التقرير الأسبوعي']);
        $values = ['notify.chat' => (string) $d['chat'], 'notify.weekly_day' => (string) $d['weekly_day']];
        foreach (array_keys(\App\Services\AdminNotify::EVENTS) as $event) {
            $values['notify.' . $event] = $request->bool('on_' . $event) ? '1' : '0';
        }
        $raw = trim((string) $request->input('token', ''));
        if ($raw !== '') {
            $token = \App\Services\Telegram::cleanToken($raw);
            if ($token === '') {
                return $this->backWithErrors($request, ['token' => 'رمز البوت غير صالح.'], '/admin/notifications');
            }
            $values['notify.token'] = Platform::seal($token);
        }
        if ($request->bool('remove_token')) {
            $values['notify.token'] = '';
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل إشعارات المسؤول', AdminAuth::user()['name']);
        Session::pull('_old');
        return $this->ok('تم حفظ الإعدادات.', '/admin/notifications');
    }

    public function notificationsTest(Request $request): Response
    {
        Session::release();
        if (($typed = \App\Services\Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $r = \App\Services\AdminNotify::test($typed, $request->str('chat'));
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    /** المحادثات التي راسلت بوت الإشعارات مؤخراً (لتعبئة معرّف المحادثة) */
    public function notificationsDetect(Request $request): Response
    {
        Session::release();
        if (($typed = \App\Services\Telegram::typedToken($request->str('token'))) === null) {
            return json_response(['ok' => false, 'message' => 'رمز البوت غير صالح.'], 422);
        }
        $token = $typed !== '' ? $typed : \App\Services\AdminNotify::token();
        $r = \App\Services\Telegram::recentChats($token);
        $r['message'] = str_replace($token, '***', $r['message']);
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    public function notificationsReport(Request $request): Response
    {
        Session::release();
        if (!\App\Services\AdminNotify::configured()) {
            return json_response(['ok' => false, 'message' => 'أدخل رمز البوت ومعرّف المحادثة.'], 422);
        }
        $ok = \App\Services\AdminNotify::push(\App\Platform\Insights::weeklyReport());
        return json_response(['ok' => $ok, 'message' => $ok ? 'تم إرسال التقرير.' : 'تعذّر الإرسال.'], $ok ? 200 : 422);
    }

    // ------------------------------------------------------------------
    // المدفوعات
    // ------------------------------------------------------------------

    public function payments(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $request->str('month')) ? $request->str('month') : '';
        $where = '1 = 1';
        $params = [];
        if ($month !== '') {
            $where = 'p.created_at >= ? AND p.created_at < ?';
            $params = [$month . '-01 00:00:00', date('Y-m-01 00:00:00', strtotime($month . '-01 +1 month'))];
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM tenant_payments p WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $monthly = DB::pairs("SELECT DATE_FORMAT(created_at, '%Y-%m'), SUM(amount) FROM tenant_payments WHERE created_at >= ? GROUP BY 1", [date('Y-m-01', strtotime('-11 months'))]);
        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
            $series[$m] = (float) ($monthly[$m] ?? 0);
        }
        return view('admin/payments', [
            'rows' => DB::rows("SELECT p.*, t.slug FROM tenant_payments p LEFT JOIN tenants t ON t.id = p.tenant_id WHERE $where ORDER BY p.id DESC" . $pager->limitSql(), $params),
            'pager' => $pager, 'request' => $request, 'month' => $month, 'series' => $series,
            'sum' => (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM tenant_payments p WHERE $where", $params),
            'all' => (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM tenant_payments'),
            'thisMonth' => (float) ($series[date('Y-m')] ?? 0),
        ], 'admin');
    }

    public function paymentDelete(Request $request, int $id): Response
    {
        $p = DB::row('SELECT * FROM tenant_payments WHERE id = ?', [$id]);
        $this->notFoundUnless($p);
        DB::delete('tenant_payments', 'id = ?', [$id]);
        Platform::log($p['tenant_id'] !== null ? (int) $p['tenant_id'] : null, 'admin', 'payment.delete', 'حذف دفعة ' . $p['amount'] . ' ' . $p['currency'] . ' — ' . $p['tenant_name'], AdminAuth::user()['name']);
        return $this->ok('تم حذف الدفعة.', $request->str('back') === 'tenant' && $p['tenant_id'] ? '/admin/tenants/' . $p['tenant_id'] : '/admin/payments');
    }

    public function tenant_destroy(Request $request, int $id): Response
    {
        $t = $this->tenant($id);
        if ($request->str('confirm') !== $t['slug']) {
            return $this->backWithErrors($request, ['confirm' => 'اكتب اسم الحساب للتأكيد.'], '/admin/tenants/' . $id);
        }
        TenantProvisioner::destroy($t);
        Platform::log(null, 'admin', 'tenant.delete', 'حذف الحساب ' . $t['name'] . ' (' . $t['slug'] . ') نهائياً', AdminAuth::user()['name']);
        return $this->ok('تم حذف الحساب وقاعدة بياناته.', '/admin/tenants');
    }

    // ------------------------------------------------------------------
    // المستخدمون والنشاط عبر كل الحسابات
    // ------------------------------------------------------------------

    public function users(Request $request): Response
    {
        $q = mb_strtolower($request->str('q'));
        $rows = [];
        foreach (DB::rows('SELECT * FROM tenants ORDER BY name') as $t) {
            try {
                foreach (Tenant::pdo($t)->query('SELECT id, name, username, phone, is_owner, role_preset, status, last_login_at FROM employees ORDER BY is_owner DESC, name')->fetchAll() as $e) {
                    $hay = mb_strtolower($e['name'] . ' ' . $e['username'] . ' ' . $e['phone'] . ' ' . $t['name'] . ' ' . $t['slug']);
                    if ($q === '' || str_contains($hay, $q)) {
                        $rows[] = $e + ['tenant_id' => $t['id'], 'tenant_name' => $t['name'], 'slug' => $t['slug'], 'tenant_status' => $t['status']];
                    }
                }
            } catch (Throwable) {
                // قاعدة غير متاحة
            }
        }
        usort($rows, static fn ($a, $b) => strcmp((string) $b['last_login_at'], (string) $a['last_login_at']));
        return view('admin/users', ['rows' => array_slice($rows, 0, 500), 'total' => count($rows), 'q' => $request->str('q')], 'admin');
    }

    public function activity(Request $request): Response
    {
        $where = '1 = 1';
        $params = [];
        if ($request->int('tenant') > 0) {
            $where .= ' AND a.tenant_id = ?';
            $params[] = $request->int('tenant');
        }
        if (in_array($request->str('actor'), ['admin', 'tenant', 'system', 'visitor'], true)) {
            $where .= ' AND a.actor = ?';
            $params[] = $request->str('actor');
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM platform_activity a WHERE $where", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows("SELECT a.*, t.name AS tenant_name, t.slug FROM platform_activity a LEFT JOIN tenants t ON t.id = a.tenant_id WHERE $where ORDER BY a.id DESC" . $pager->limitSql(), $params);
        return view('admin/activity', [
            'rows' => $rows, 'pager' => $pager, 'request' => $request,
            'tenants' => DB::pairs('SELECT id, name FROM tenants ORDER BY name'),
        ], 'admin');
    }

    // ------------------------------------------------------------------
    // إعدادات المنصة والمسؤولون
    // ------------------------------------------------------------------

    public function settings(Request $request): Response
    {
        return view('admin/settings', ['admins' => DB::rows('SELECT * FROM platform_admins ORDER BY id')], 'admin');
    }

    public function settingsSave(Request $request): Response
    {
        $d = $this->validate($request, [
            'platform_name' => 'required|max:60', 'whatsapp' => 'nullable|max:30', 'whatsapp_intl' => 'nullable|max:20', 'facebook' => 'nullable|max:255',
            'trial_days' => 'required|int|between:1,90', 'timezone' => 'required|max:64', 'announcement' => 'nullable|max:300', 'announcement_level' => 'required|in:info,warning,success',
            'currency' => 'required|max:8', 'google_maps_key' => 'nullable|max:120|regex:/^[A-Za-z0-9_\-]*$/',
        ], ['platform_name' => 'اسم المنصة', 'trial_days' => 'أيام التجربة', 'announcement' => 'الإعلان', 'currency' => 'عملة الاشتراكات', 'google_maps_key' => 'مفتاح خرائط Google']);
        Platform::setSettings([
            'platform.name' => $d['platform_name'], 'platform.whatsapp' => (string) $d['whatsapp'], 'platform.whatsapp_intl' => preg_replace('/\D/', '', (string) $d['whatsapp_intl']),
            'platform.facebook' => (string) $d['facebook'], 'platform.trial_days' => (string) $d['trial_days'], 'platform.timezone' => $d['timezone'],
            'platform.signup' => $request->bool('signup') ? '1' : '0', 'platform.announcement' => (string) $d['announcement'], 'platform.announcement_level' => $d['announcement_level'],
            'platform.currency' => $d['currency'], 'maps.google_key' => trim((string) $d['google_maps_key']),
        ]);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل إعدادات المنصة', AdminAuth::user()['name']);
        return $this->ok('تم حفظ الإعدادات.', '/admin/settings');
    }

    /** شعار النظام لكل الحسابات (PNG أو WebP بخلفية شفافة؛ ملف SVG يحوّله المتصفح إلى PNG قبل الرفع) */
    public function logoSave(Request $request): Response
    {
        if ($err = $request->uploadError('logo')) {
            return $this->backWithErrors($request, ['logo' => $err], '/admin/settings');
        }
        $file = $request->file('logo');
        if (!$file) {
            return $this->backWithErrors($request, ['logo' => 'اختر صورة الشعار.'], '/admin/settings');
        }
        $r = \App\Services\BrandLogo::store((string) $file['tmp_name'], (int) $file['size']);
        if (!$r['ok']) {
            return $this->backWithErrors($request, ['logo' => $r['error']], '/admin/settings');
        }
        Platform::log(null, 'admin', 'platform.logo', 'تغيير شعار النظام', AdminAuth::user()['name']);
        return $this->ok('تم تغيير شعار النظام في كل الحسابات.', '/admin/settings');
    }

    public function logoReset(Request $request): Response
    {
        \App\Services\BrandLogo::reset();
        Platform::log(null, 'admin', 'platform.logo', 'استعادة شعار النظام الأصلي', AdminAuth::user()['name']);
        return $this->ok('عاد شعار النظام الأصلي.', '/admin/settings');
    }

    /** كلمة مرور المسؤول نفسه: بالحالية، وتنتهي جلساته الأخرى */
    public function ownPassword(Request $request): Response
    {
        $me = AdminAuth::user();
        if (!password_verify((string) $request->input('current', ''), (string) $me['password_hash'])) {
            return $this->backWithErrors($request, ['current' => 'كلمة المرور الحالية غير صحيحة.'], '/admin/security');
        }
        $new = (string) $request->input('password', '');
        if (mb_strlen($new) < 8 || mb_strlen($new) > 100) {
            return $this->backWithErrors($request, ['password' => 'كلمة المرور الجديدة 8 أحرف على الأقل.'], '/admin/security');
        }
        if ($new !== (string) $request->input('password_confirmation', '')) {
            return $this->backWithErrors($request, ['password' => 'التأكيد لا يطابق كلمة المرور الجديدة.'], '/admin/security');
        }
        DB::query('UPDATE platform_admins SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        Session::set('admin_sv', (int) DB::value('SELECT session_version FROM platform_admins WHERE id = ?', [$me['id']]));
        Platform::log(null, 'admin', 'platform.admin', 'تغيير كلمة مرور المسؤول ' . $me['username'], (string) $me['name']);
        return $this->ok('تم تغيير كلمة المرور، وانتهت جلساتك الأخرى.', '/admin/security');
    }
}
