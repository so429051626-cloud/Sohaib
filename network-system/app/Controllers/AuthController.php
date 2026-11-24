<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\TrustedDevices;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return view('auth/login', ['message' => Session::pull('_auth_message')], 'split');
    }

    public function login(Request $request): Response
    {
        $username = $request->str('username');
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            Session::flashInput(['username' => $username]);
            flash('error', 'أدخل اسم المستخدم وكلمة المرور.');
            return redirect('/login');
        }
        $result = Auth::attempt($username, $password, $request->ip());
        if (!$result['ok']) {
            Session::flashInput(['username' => $username]);
            flash('error', $result['message']);
            return redirect('/login');
        }
        if (!empty($result['twofa'])) {
            return redirect('/login/verify');
        }
        return $this->enter();
    }

    /** بعد اكتمال الدخول: الصفحة التي كان يقصدها المستخدم، أو الرئيسية */
    private function enter(): Response
    {
        $intended = Session::pull('_intended');
        if (is_string($intended) && str_starts_with($intended, base_path() . '/') && !str_contains($intended, '/login')) {
            return Response::redirect($intended);
        }
        return redirect(can('dashboard.view') ? '/' : '/account');
    }

    /** الخطوة الثانية: رمز تطبيق المصادقة أو رمز استرجاع */
    public function verifyForm(Request $request): Response
    {
        $emp = Auth::pendingTwoFactor();
        if (!$emp) {
            return redirect('/login');
        }
        return view('auth/verify', ['name' => (string) $emp['name']], 'split');
    }

    public function verify(Request $request): Response
    {
        $r = Auth::completeTwoFactor($request->str('code'), $request->ip());
        if (!$r['ok']) {
            $to = !empty($r['expired']) ? '/login' : '/login/verify';
            if ($request->wantsJson()) {
                return json_response(['ok' => false, 'message' => $r['message'], 'redirect' => !empty($r['expired']) ? url('/login') : null], 422);
            }
            flash('error', $r['message']);
            return redirect($to);
        }
        $to = $this->enter();
        if ($request->wantsJson()) {
            return json_response(['ok' => true, 'redirect' => (string) ($to->headers['Location'] ?? url('/'))]);
        }
        return $to;
    }

    /** الدخول بالبصمة من تطبيقات النظام: رمز الجهاز بعد التحقق المحلي بالبصمة */
    public function deviceLogin(Request $request): Response
    {
        $r = TrustedDevices::verify($request->int('device_id'), $request->str('token'), $request->ip());
        if (!$r['ok']) {
            return json_response(['ok' => false, 'message' => $r['message'], 'reset' => $r['reset'] ?? false], 422);
        }
        Auth::loginAs($r['employee'], $request->ip());
        \App\Core\Audit::log('auth.device', 'دخول بالبصمة', 'employee', (int) $r['employee']['id'], ['device' => $request->int('device_id')], (int) $r['employee']['id']);
        $intended = Session::pull('_intended');
        $to = is_string($intended) && str_starts_with($intended, base_path() . '/') && !str_contains($intended, '/login')
            ? $intended : url(can('dashboard.view') ? '/' : '/account');
        return json_response(['ok' => true, 'token' => $r['token'], 'redirect' => $to]);
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        Session::resume();
        flash('success', 'تم تسجيل الخروج.');
        return redirect('/login');
    }
}
