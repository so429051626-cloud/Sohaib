<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Permissions;
use App\Core\Settings;
use App\Services\Totp;
use App\Services\TrustedDevices;
use App\Services\TwoFactor;

final class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = Auth::user();
        $logins = DB::rows(
            "SELECT action, ip, created_at FROM audit_log WHERE employee_id = ? AND action IN ('auth.login','auth.failed','auth.device') ORDER BY id DESC LIMIT 10",
            [$user['id']]
        );
        $permissions = Auth::permissions();
        $devices = TrustedDevices::list((int) $user['id']);
        $setup = Session::get('totp_setup');
        $twoFactor = [
            'enabled'  => TwoFactor::enabled($user),
            'required' => TwoFactor::required() && !(int) $user['is_owner'],
            'setup'    => is_string($setup) && !TwoFactor::enabled($user) ? $setup : null,
            'codes'    => Session::pull('totp_codes'),
            'left'     => TwoFactor::recoveryLeft($user),
        ];
        $twoFactor['uri'] = $twoFactor['setup'] !== null ? Totp::uri($twoFactor['setup'], (string) $user['username'], (string) Settings::get('general.network_name', \App\Core\Brand::NAME)) : null;
        return view('account/show', compact('user', 'logins', 'permissions', 'devices', 'twoFactor'));
    }

    // ------------------------------------------------------------------
    // التحقق بخطوتين
    // ------------------------------------------------------------------

    public function totpStart(Request $request): Response
    {
        if (!TwoFactor::enabled(Auth::user())) {
            Session::set('totp_setup', Totp::secret());
        }
        return redirect('/account#two-factor');
    }

    public function totpCancel(Request $request): Response
    {
        Session::forget('totp_setup');
        return redirect('/account#two-factor');
    }

    public function totpConfirm(Request $request): Response
    {
        $user = Auth::user();
        $secret = Session::get('totp_setup');
        if (TwoFactor::enabled($user) || !is_string($secret)) {
            return redirect('/account#two-factor');
        }
        if (!Totp::verify($secret, $request->str('code'))) {
            flash('error', 'رمز التحقق غير صحيح.');
            return redirect('/account#two-factor');
        }
        Session::set('totp_codes', TwoFactor::enable((int) $user['id'], $secret));
        Session::forget('totp_setup');
        Audit::log('auth.2fa_on', 'تفعيل التحقق بخطوتين', 'employee', (int) $user['id']);
        flash('success', 'تم تفعيل التحقق بخطوتين.');
        return redirect('/account#two-factor');
    }

    public function totpDisable(Request $request): Response
    {
        $user = Auth::user();
        if (!password_verify((string) $request->input('password', ''), (string) $user['password_hash'])) {
            flash('error', 'كلمة المرور غير صحيحة.');
            return redirect('/account#two-factor');
        }
        if (TwoFactor::required() && !(int) $user['is_owner']) {
            flash('error', 'صاحب الشبكة يفرض التحقق بخطوتين على الموظفين.');
            return redirect('/account#two-factor');
        }
        TwoFactor::disable((int) $user['id']);
        Session::forget('totp_setup');
        Audit::log('auth.2fa_off', 'إيقاف التحقق بخطوتين', 'employee', (int) $user['id']);
        flash('success', 'تم إيقاف التحقق بخطوتين.');
        return redirect('/account#two-factor');
    }

    public function totpCodes(Request $request): Response
    {
        $user = Auth::user();
        if (!TwoFactor::enabled($user) || !password_verify((string) $request->input('password', ''), (string) $user['password_hash'])) {
            flash('error', 'كلمة المرور غير صحيحة.');
            return redirect('/account#two-factor');
        }
        Session::set('totp_codes', TwoFactor::newCodes((int) $user['id']));
        Audit::log('auth.2fa_codes', 'إصدار رموز استرجاع جديدة', 'employee', (int) $user['id']);
        flash('success', 'صدرت رموز استرجاع جديدة، والرموز السابقة لم تعد تعمل.');
        return redirect('/account#two-factor');
    }

    public function update(Request $request): Response
    {
        $user = Auth::user();
        $data = $this->validate($request, [
            'name'  => 'required|max:120',
            'phone' => 'nullable|max:30',
            'email' => 'nullable|email|max:120',
            'theme' => 'required|in:system,light,dark',
        ], ['name' => 'الاسم', 'phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'theme' => 'المظهر']);

        $update = [
            'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'],
            // الوضع فاتح ما لم يُختر الداكن: «حسب الجهاز» لم يعد خياراً
            'theme' => $data['theme'] === 'dark' ? 'dark' : 'light', 'updated_at' => now(),
        ];

        $newPass = (string) $request->input('new_password', '');
        if ($newPass !== '') {
            if (!password_verify((string) $request->input('current_password', ''), $user['password_hash'])) {
                return $this->backWithErrors($request, ['current_password' => 'كلمة المرور الحالية غير صحيحة.'], '/account');
            }
            if (mb_strlen($newPass) < 8) {
                return $this->backWithErrors($request, ['new_password' => 'كلمة المرور الجديدة يجب ألا تقل عن 8 أحرف.'], '/account');
            }
            if ($newPass !== (string) $request->input('new_password_confirmation', '')) {
                return $this->backWithErrors($request, ['new_password' => 'تأكيد كلمة المرور غير مطابق.'], '/account');
            }
            $update['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            $update['session_version'] = (int) $user['session_version'] + 1;
        }

        DB::update('employees', $update, 'id = ?', [$user['id']]);
        if (isset($update['session_version'])) {
            Session::set('session_version', $update['session_version']);
            TrustedDevices::revokeAll((int) $user['id']);
            Audit::log('employee.update', 'تغيير كلمة مرور الحساب', 'employee', (int) $user['id']);
        }
        return $this->ok('تم حفظ بيانات الحساب.', '/account');
    }

    /** تفعيل الدخول بالبصمة على جهاز (من التطبيق) */
    public function deviceEnroll(Request $request): Response
    {
        $d = TrustedDevices::enroll((int) Auth::id(), $request->str('name'), $request->str('platform'));
        Audit::log('auth.device_add', 'تفعيل الدخول بالبصمة على ' . ($request->str('name') ?: 'جهاز'), 'employee', (int) Auth::id());
        return json_response(['ok' => true, 'device_id' => $d['id'], 'token' => $d['token'], 'message' => 'تم تفعيل الدخول بالبصمة على هذا الجهاز.']);
    }

    public function deviceRevoke(Request $request, int $id): Response
    {
        $ok = TrustedDevices::revoke((int) Auth::id(), $id);
        if ($ok) {
            Audit::log('auth.device_revoke', 'إلغاء جهاز موثوق', 'employee', (int) Auth::id(), ['device' => $id]);
        }
        if ($request->wantsJson()) {
            return json_response(['ok' => $ok]);
        }
        return $this->ok('تم إلغاء الجهاز.', '/account');
    }

    public function theme(Request $request): Response
    {
        $theme = $request->str('theme');
        if (!in_array($theme, ['system', 'light', 'dark'], true)) {
            return json_response(['ok' => false, 'message' => 'قيمة غير صالحة.'], 422);
        }
        DB::update('employees', ['theme' => $theme === 'dark' ? 'dark' : 'light'], 'id = ?', [Auth::id()]);
        return json_response(['ok' => true]);
    }

    public static function roleLabel(array $user): string
    {
        if ($user['is_owner']) {
            return 'المالك';
        }
        return Permissions::PRESETS[$user['role_preset'] ?? '']['label'] ?? 'موظف';
    }
}
