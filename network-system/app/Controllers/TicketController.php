<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;

/**
 * الدخول إلى نظام الزبون بتذكرة من المنصة (الدخول الموحّد، التسجيل، دخول المسؤول).
 */
final class TicketController extends Controller
{
    public function redeem(Request $request, string $token): Response
    {
        $t = Platform::redeem($token, (int) Tenant::id());
        $emp = $t ? DB::row('SELECT * FROM employees WHERE id = ?', [$t['employee_id']]) : null;
        if (!$t || !$emp || $emp['status'] !== 'active') {
            flash('error', 'انتهت صلاحية رابط الدخول.');
            return redirect('/login');
        }
        if (Auth::check()) {
            Auth::logout();
            \App\Core\Session::resume();
        }
        // الدخول الموحّد تحقق من كلمة المرور فقط: الحساب المحمي بالتحقق بخطوتين يكمل برمزه (دخول مسؤول المنصة مستثنى، وله تحققه الخاص)
        if ($t['purpose'] === 'login' && \App\Services\TwoFactor::enabled($emp)) {
            Auth::holdForTwoFactor($emp);
            return redirect('/login/verify');
        }
        $impersonate = $t['purpose'] === 'impersonate';
        // دخول مسؤول المنصة صامت: لا يظهر للزبون في سجل العمليات ولا في التنبيهات ولا في «آخر دخول»
        // (يبقى مسجّلاً في سجل نشاط المنصة الخاص بالمسؤولين فقط)
        Auth::loginAs($emp, $request->ip(), $impersonate);
        if ($impersonate) {
            $admin = Platform::row('SELECT name FROM platform_admins WHERE id = ?', [(int) $t['admin_id']]);
            Session::set('impersonated_by', $admin['name'] ?? 'مسؤول المنصة');
        } elseif ($t['purpose'] === 'signup') {
            flash('success', 'مرحباً بك في ' . \App\Core\Brand::NAME . '.');
        }
        return redirect('/');
    }

    /**
     * الانتقال إلى شبكة أخرى للمالك نفسه دون إعادة إدخال كلمة المرور.
     * يُسمح به داخل مجموعة الشبكات المرتبطة فقط، وللمستخدم نفسه في الشبكة الأخرى:
     * مالك هنا ومالك هناك بالاسم نفسه، أو مستخدم بكلمة المرور المخزّنة نفسها.
     */
    public function switchNetwork(Request $request, int $id): Response
    {
        $current = Tenant::current();
        $user = Auth::user();
        $target = Platform::row('SELECT * FROM tenants WHERE id = ?', [$id]);
        if (!$current || !$user || !$target || empty($current['group_id']) || (int) $target['group_id'] !== (int) $current['group_id'] || (int) $target['id'] === (int) $current['id']) {
            throw new \App\Core\HttpException(404);
        }
        if (!Tenant::usable($target)) {
            flash('error', 'تلك الشبكة غير مفعّلة حالياً.');
            return back('/');
        }
        try {
            $st = Tenant::pdo($target)->prepare('SELECT id, password_hash, is_owner, status FROM employees WHERE username = ?');
            $st->execute([$user['username']]);
            $emp = $st->fetch() ?: null;
        } catch (\Throwable) {
            $emp = null;
        }
        $same = $emp && $emp['status'] === 'active'
            && (((int) $user['is_owner'] === 1 && (int) $emp['is_owner'] === 1) || hash_equals((string) $emp['password_hash'], (string) $user['password_hash']));
        if (!$same) {
            flash('error', 'لا يوجد لك مستخدم في تلك الشبكة.');
            return back('/');
        }
        Audit::log('auth.switch', 'الانتقال إلى شبكة ' . $target['name'], 'employee', (int) $user['id']);
        return Response::redirect(root_path() . '/' . $target['slug'] . '/auth/ticket/' . Platform::ticket((int) $target['id'], (int) $emp['id'], 'login'));
    }

    /** خروج مسؤول المنصة من حساب الزبون والعودة إلى لوحته */
    public function exitImpersonation(Request $request): Response
    {
        $id = Tenant::id();
        Auth::logout();
        return Response::redirect(root_path() . '/admin/tenants/' . $id);
    }
}
