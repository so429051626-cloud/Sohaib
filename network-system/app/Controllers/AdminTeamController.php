<?php
declare(strict_types=1);

namespace App\Controllers;

use App\AdminPermissions;
use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * فريق إدارة المنصة (للمالك وحده): إضافة المسؤولين وتعديلهم، أدوارهم وصلاحياتهم، إيقافهم وحذفهم.
 * المالك لا يُوقف ولا يُحذف ولا تُقيَّد صلاحياته، ولا يحذف المسؤول نفسه.
 */
final class AdminTeamController extends Controller
{
    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    private function member(int $id): array
    {
        $a = DB::row('SELECT * FROM platform_admins WHERE id = ?', [$id]);
        if (!$a) {
            throw new HttpException(404);
        }
        return $a;
    }

    public function index(Request $request): Response
    {
        return view('admin/team', [
            'team' => DB::rows('SELECT * FROM platform_admins ORDER BY is_owner DESC, is_active DESC, name'),
        ], 'admin');
    }

    public function save(Request $request): Response
    {
        $id = $request->int('id');
        $existing = $id ? $this->member($id) : null;
        $d = $this->validate($request, [
            'name'     => 'required|max:120',
            'username' => 'required|username|max:60',
            'email'    => 'nullable|email|max:190',
            'password' => ($id ? 'nullable' : 'required') . '|min:8|max:100',
        ], ['name' => 'الاسم', 'username' => 'اسم المستخدم', 'email' => 'البريد', 'password' => 'كلمة المرور'], function ($v) use ($request, $id) {
            if (DB::value('SELECT id FROM platform_admins WHERE username = ? AND id <> ?', [$request->str('username'), $id])) {
                $v->addError('username', 'اسم المستخدم مستخدم.');
            }
        });
        $data = ['name' => $d['name'], 'username' => $d['username'], 'email' => ($d['email'] ?? '') !== '' ? $d['email'] : null];
        // الدور والصلاحيات: المالك دائماً كامل الصلاحيات
        if (!$existing || !(int) $existing['is_owner']) {
            $role = $request->str('role');
            if ($role === 'custom') {
                $perms = AdminPermissions::filter($request->arr('perms'));
                if (!$perms) {
                    return $this->backWithErrors($request, ['perms' => 'اختر صلاحية واحدة على الأقل.'], '/admin/team');
                }
                $data['permissions'] = json_encode($perms);
            } elseif (isset(AdminPermissions::PRESETS[$role])) {
                $p = AdminPermissions::PRESETS[$role][2];
                $data['permissions'] = $p === '*' ? null : json_encode($p);
            } else {
                return $this->backWithErrors($request, ['perms' => 'اختر الدور.'], '/admin/team');
            }
        }
        $password = (string) $request->input('password', '');
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($existing) {
            $changedAccess = $password !== '' || ($data['permissions'] ?? $existing['permissions']) !== $existing['permissions'];
            DB::update('platform_admins', $data, 'id = ?', [$id]);
            if ($changedAccess) {
                // كلمة مرور جديدة أو صلاحيات أخرى: تنتهي جلساته المفتوحة (إلا جلستي إن كنت أعدّل نفسي)
                DB::query('UPDATE platform_admins SET session_version = session_version + 1 WHERE id = ?', [$id]);
                if ($id === AdminAuth::id()) {
                    Session::set('admin_sv', (int) DB::value('SELECT session_version FROM platform_admins WHERE id = ?', [$id]));
                }
            }
            Platform::log(null, 'admin', 'platform.admin', 'تعديل المسؤول ' . $d['username'] . ' (' . AdminPermissions::roleOf(['permissions' => $data['permissions'] ?? $existing['permissions'], 'is_owner' => $existing['is_owner']]) . ')', $this->admin());
            return $this->ok('تم حفظ المسؤول ' . $d['name'] . '.', '/admin/team');
        }
        DB::insert('platform_admins', $data + ['is_active' => 1, 'is_owner' => 0, 'created_at' => now()]);
        Platform::log(null, 'admin', 'platform.admin', 'إضافة المسؤول ' . $d['username'] . ' (' . AdminPermissions::roleOf(['permissions' => $data['permissions'], 'is_owner' => 0]) . ')', $this->admin());
        return $this->ok('تمت إضافة المسؤول ' . $d['name'] . '.', '/admin/team');
    }

    /** إيقاف مسؤول أو إعادة تفعيله: الإيقاف ينهي جلساته فوراً */
    public function status(Request $request, int $id): Response
    {
        $a = $this->member($id);
        if ((int) $a['is_owner'] || $id === AdminAuth::id()) {
            flash('error', 'لا يمكن إيقاف ' . ((int) $a['is_owner'] ? 'مالك المنصة.' : 'حسابك.'));
            return redirect('/admin/team');
        }
        $active = $request->bool('active');
        DB::query('UPDATE platform_admins SET is_active = ?, session_version = session_version + ? WHERE id = ?', [$active ? 1 : 0, $active ? 0 : 1, $id]);
        Platform::log(null, 'admin', 'platform.admin', ($active ? 'تفعيل' : 'إيقاف') . ' المسؤول ' . $a['username'], $this->admin());
        return $this->ok($active ? 'تم تفعيل ' . $a['name'] . '.' : 'تم إيقاف ' . $a['name'] . ' وإنهاء جلساته.', '/admin/team');
    }

    public function destroy(Request $request, int $id): Response
    {
        $a = $this->member($id);
        if ((int) $a['is_owner'] || $id === AdminAuth::id()) {
            flash('error', 'لا يمكن حذف ' . ((int) $a['is_owner'] ? 'مالك المنصة.' : 'حسابك.'));
            return redirect('/admin/team');
        }
        DB::delete('platform_admins', 'id = ?', [$id]);
        Platform::log(null, 'admin', 'platform.admin', 'حذف المسؤول ' . $a['username'], $this->admin());
        return $this->ok('تم حذف المسؤول ' . $a['name'] . '.', '/admin/team');
    }
}
