<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Permissions;

final class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $employees = DB::rows(
            'SELECT e.*, (SELECT COUNT(*) FROM employee_permissions p WHERE p.employee_id = e.id) AS perm_count,
                    (SELECT COUNT(*) FROM employee_routers r WHERE r.employee_id = e.id) AS router_count
             FROM employees e ORDER BY e.is_owner DESC, e.status, e.name'
        );
        return view('employees/index', compact('employees'));
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->notFoundUnless($emp);
        return $this->form($emp);
    }

    private function form(?array $emp): Response
    {
        $routers = DB::rows('SELECT id, name FROM routers ORDER BY name');
        $assigned = $emp ? DB::column('SELECT permission FROM employee_permissions WHERE employee_id = ?', [$emp['id']]) : Permissions::presetPermissions('seller');
        $assignedRouters = $emp ? array_map('intval', DB::column('SELECT router_id FROM employee_routers WHERE employee_id = ?', [$emp['id']])) : [];
        $grantable = $this->grantable();
        return view('employees/form', compact('emp', 'routers', 'assigned', 'assignedRouters', 'grantable'));
    }

    /** الصلاحيات التي يحق للموظف الحالي منحها (لا يمنح ما لا يملكه) */
    private function grantable(): array
    {
        return Auth::isOwner() ? Permissions::all() : Auth::permissions();
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, null);
        if (\App\Core\Tenant::limitReached('max_employees', 'SELECT COUNT(*) FROM employees')) {
            return $this->backWithErrors($request, ['name' => 'بلغ الحساب الحد الأقصى لعدد الموظفين.'], '/employees/create');
        }
        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::insert('employees', [
                'name'          => $data['name'],
                'username'      => $data['username'],
                'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
                'phone'         => $data['phone'],
                'email'         => $data['email'],
                'is_owner'      => 0,
                'role_preset'   => $data['role_preset'],
                'router_scope'  => $data['router_scope'],
                'status'        => $data['status'],
                'theme'         => 'light',
                'created_at'    => now(),
            ]);
            $this->syncAccess($id, $request, $data['router_scope']);
            return $id;
        });
        Audit::log('employee.create', 'إضافة الموظف ' . $data['name'], 'employee', $id);
        return $this->ok('تمت إضافة الموظف.', '/employees');
    }

    public function update(Request $request, int $id): Response
    {
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->notFoundUnless($emp);
        if ($emp['is_owner'] && !Auth::isOwner()) {
            throw new HttpException(403, 'لا يمكن تعديل حساب المالك.');
        }
        $data = $this->validated($request, $emp);

        DB::transaction(function () use ($emp, $data, $request) {
            $update = [
                'name'       => $data['name'],
                'username'   => $data['username'],
                'phone'      => $data['phone'],
                'email'      => $data['email'],
                'updated_at' => now(),
            ];
            $bump = false;
            if (!$emp['is_owner']) {
                $update['role_preset'] = $data['role_preset'];
                $update['router_scope'] = $data['router_scope'];
                $update['status'] = $data['status'];
                if ($data['status'] !== $emp['status']) {
                    $bump = true;
                }
                $this->syncAccess((int) $emp['id'], $request, $data['router_scope']);
            }
            $password = (string) $request->input('password', '');
            if ($password !== '') {
                $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $bump = true;
            }
            if ($bump && (int) $emp['id'] !== Auth::id()) {
                $update['session_version'] = (int) $emp['session_version'] + 1;
            }
            DB::update('employees', $update, 'id = ?', [$emp['id']]);
        });
        Audit::log('employee.update', 'تعديل بيانات الموظف ' . $data['name'], 'employee', (int) $emp['id']);
        return $this->ok('تم حفظ التعديلات.', '/employees');
    }

    private function validated(Request $request, ?array $emp): array
    {
        $rules = [
            'name'         => 'required|max:120',
            'username'     => 'required|username|min:3|max:60',
            'phone'        => 'nullable|max:30',
            'email'        => 'nullable|email|max:120',
            'status'       => 'required|in:active,disabled',
            'role_preset'  => 'nullable|in:' . implode(',', array_keys(Permissions::PRESETS)),
            'router_scope' => 'required|in:all,selected',
            'password'     => ($emp ? 'nullable' : 'required') . '|min:8|max:100|confirmed',
        ];
        $labels = [
            'name' => 'الاسم', 'username' => 'اسم الدخول', 'phone' => 'الهاتف', 'email' => 'البريد',
            'status' => 'الحالة', 'role_preset' => 'الدور', 'router_scope' => 'نطاق أجهزة MikroTik', 'password' => 'كلمة المرور',
        ];
        return $this->validate($request, $rules, $labels, function ($v) use ($request, $emp) {
            $dup = DB::value('SELECT id FROM employees WHERE username = ? AND id <> ?', [$request->str('username'), $emp['id'] ?? 0]);
            if ($dup) {
                $v->addError('username', 'اسم الدخول مستخدم لموظف آخر.');
            }
            if ($emp && (int) $emp['id'] === Auth::id() && $request->str('status') === 'disabled') {
                $v->addError('status', 'لا يمكنك إيقاف حسابك.');
            }
        });
    }

    private function syncAccess(int $employeeId, Request $request, string $scope): void
    {
        $grantable = $this->grantable();
        $requested = Permissions::filter($request->arr('permissions'));
        $permissions = array_values(array_intersect($requested, $grantable));
        // صلاحيات يملكها الموظف ولا يملك المحرّر منحها تبقى كما هي
        $kept = array_diff(DB::column('SELECT permission FROM employee_permissions WHERE employee_id = ?', [$employeeId]), $grantable);
        DB::delete('employee_permissions', 'employee_id = ?', [$employeeId]);
        $rows = [];
        foreach (array_unique(array_merge($permissions, $kept)) as $p) {
            $rows[] = ['employee_id' => $employeeId, 'permission' => $p];
        }
        DB::insertMany('employee_permissions', $rows);

        DB::delete('employee_routers', 'employee_id = ?', [$employeeId]);
        if ($scope === 'selected') {
            $routerIds = $request->ids('routers');
            $allowedRouters = Auth::routerIds();
            if ($allowedRouters !== null) {
                $routerIds = array_values(array_intersect($routerIds, $allowedRouters));
            }
            $valid = $routerIds ? array_map('intval', DB::column('SELECT id FROM routers WHERE id IN (' . DB::in($routerIds) . ')', $routerIds)) : [];
            DB::insertMany('employee_routers', array_map(static fn ($r) => ['employee_id' => $employeeId, 'router_id' => $r], $valid));
        }
    }

    public function destroy(Request $request, int $id): Response
    {
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->notFoundUnless($emp);
        if ($emp['is_owner']) {
            return $this->backWithErrors($request, ['id' => 'لا يمكن حذف حساب المالك.'], '/employees');
        }
        if ((int) $emp['id'] === Auth::id()) {
            return $this->backWithErrors($request, ['id' => 'لا يمكنك حذف حسابك.'], '/employees');
        }
        DB::delete('employees', 'id = ?', [$id]);
        Audit::log('employee.delete', 'حذف الموظف ' . $emp['name'], 'employee', $id);
        return $this->ok('تم حذف الموظف.', '/employees');
    }

    public function logoutAll(Request $request, int $id): Response
    {
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->notFoundUnless($emp);
        if ($emp['is_owner'] && !Auth::isOwner()) {
            throw new HttpException(403);
        }
        DB::query('UPDATE employees SET session_version = session_version + 1 WHERE id = ?', [$id]);
        Audit::log('employee.logout', 'إنهاء جلسات الموظف ' . $emp['name'], 'employee', $id);
        if ($id === Auth::id()) {
            Auth::logout();
            return redirect('/login');
        }
        return $this->ok('تم إنهاء جميع جلسات الموظف.', '/employees');
    }

    /** موظف فقد هاتفه ورموز الاسترجاع: يُلغى تحققه ليدخل بكلمة المرور ويفعّله من جديد */
    public function resetTwoFactor(Request $request, int $id): Response
    {
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$id]);
        $this->notFoundUnless($emp);
        if ($emp['is_owner'] && !Auth::isOwner()) {
            throw new HttpException(403);
        }
        \App\Services\TwoFactor::disable($id);
        Audit::log('auth.2fa_reset', 'إلغاء التحقق بخطوتين للمستخدم ' . $emp['name'], 'employee', $id);
        return $this->ok('تم إلغاء التحقق بخطوتين لهذا المستخدم.', '/employees');
    }
}
