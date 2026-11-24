<?php
declare(strict_types=1);

namespace App\Core;

use App\Permissions;
use App\Services\TwoFactor;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;
    private static array $permissions = [];
    private static ?array $routerIds = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $id = Session::get('employee_id');
        if (!$id || !Config::installed()) {
            return null;
        }
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [(int) $id]);
        if (!$emp || $emp['status'] !== 'active' || (int) $emp['session_version'] !== (int) Session::get('session_version')) {
            self::clearSession();
            return null;
        }

        $idle = max(5, (int) Settings::get('security.idle_minutes', 120)) * 60;
        $last = (int) Session::get('last_activity', 0);
        if ($last > 0 && time() - $last > $idle) {
            self::clearSession();
            Session::set('_auth_message', 'انتهت الجلسة لعدم النشاط. سجّل الدخول مجدداً.');
            return null;
        }
        Session::set('last_activity', time());

        self::$user = $emp;
        self::$permissions = $emp['is_owner']
            ? Permissions::all()
            : DB::column('SELECT permission FROM employee_permissions WHERE employee_id = ?', [$emp['id']]);
        self::$routerIds = ($emp['is_owner'] || $emp['router_scope'] === 'all')
            ? null
            : array_map('intval', DB::column('SELECT router_id FROM employee_routers WHERE employee_id = ?', [$emp['id']]));
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function isOwner(): bool
    {
        $u = self::user();
        return $u !== null && (bool) $u['is_owner'];
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        if ($u === null) {
            return false;
        }
        if (Platform::enabled() && Platform::permissionBlocked($permission)) {
            return false;
        }
        return $u['is_owner'] || in_array($permission, self::$permissions, true);
    }

    public static function permissions(): array
    {
        self::user();
        return self::$permissions;
    }

    /** null = كل أجهزة MikroTik */
    public static function routerIds(): ?array
    {
        self::user();
        return self::$routerIds;
    }

    public static function canRouter(?int $routerId): bool
    {
        $ids = self::routerIds();
        return $ids === null || ($routerId !== null && in_array($routerId, $ids, true));
    }

    /**
     * شرط SQL لتقييد النتائج بأجهزة MikroTik الموظف.
     * @return array{0:string,1:array}
     */
    public static function routerScope(string $column, bool $allowNull = false): array
    {
        $ids = self::routerIds();
        if ($ids === null) {
            return ['', []];
        }
        if (!$ids) {
            return [$allowNull ? " AND $column IS NULL" : ' AND 1 = 0', []];
        }
        $sql = " AND ($column IN (" . DB::in($ids) . ')' . ($allowNull ? " OR $column IS NULL" : '') . ')';
        return [$sql, $ids];
    }

    /**
     * محاولة تسجيل الدخول مع تقييد المحاولات.
     * @return array{ok:bool,message?:string}
     */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $r = self::verifyCredentials($username, $password, $ip);
        if (!$r['ok']) {
            return $r;
        }
        if (TwoFactor::enabled($r['employee'])) {
            self::holdForTwoFactor($r['employee']);
            return ['ok' => true, 'twofa' => true];
        }
        self::loginAs($r['employee'], $ip);
        return ['ok' => true];
    }

    /** كلمة المرور صحيحة والحساب محمي بالتحقق بخطوتين: ينتظر الرمز (خمس دقائق) قبل إنشاء الجلسة */
    public static function holdForTwoFactor(array $emp): void
    {
        Session::regenerate();
        Session::set('login_2fa', ['id' => (int) $emp['id'], 'sv' => (int) $emp['session_version'], 'at' => time()]);
    }

    public static function pendingTwoFactor(): ?array
    {
        $p = Session::get('login_2fa');
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > 300) {
            Session::forget('login_2fa');
            return null;
        }
        $emp = DB::row("SELECT * FROM employees WHERE id = ? AND status = 'active'", [(int) $p['id']]);
        if (!$emp || (int) $emp['session_version'] !== (int) $p['sv'] || !TwoFactor::enabled($emp)) {
            Session::forget('login_2fa');
            return null;
        }
        return $emp;
    }

    /** @return array{ok:bool, message?:string, expired?:bool} */
    public static function completeTwoFactor(string $code, string $ip): array
    {
        $emp = self::pendingTwoFactor();
        if (!$emp) {
            return ['ok' => false, 'expired' => true, 'message' => 'انتهت مهلة التحقق. سجّل الدخول من جديد.'];
        }
        $r = TwoFactor::check($emp, $code);
        if (!$r['ok']) {
            return $r;
        }
        Session::forget('login_2fa');
        self::loginAs($emp, $ip);
        return ['ok' => true];
    }

    /**
     * التحقق من بيانات الدخول مع تقييد المحاولات، دون إنشاء جلسة (للمتصفح والتطبيق).
     * @return array{ok:bool,message?:string,employee?:array}
     */
    public static function verifyCredentials(string $username, string $password, string $ip): array
    {
        $max = max(3, (int) Settings::get('security.login_max_attempts', 5));
        $lock = max(1, (int) Settings::get('security.login_lock_minutes', 15)) * 60;
        $keyIp = 'login:ip:' . $ip;
        $keyUser = 'login:user:' . mb_strtolower($username);

        foreach ([$keyIp, $keyUser] as $k) {
            $wait = Throttle::lockedFor($k);
            if ($wait > 0) {
                return ['ok' => false, 'message' => 'تم إيقاف الدخول مؤقتاً بسبب كثرة المحاولات. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
            }
        }

        $emp = DB::row('SELECT * FROM employees WHERE username = ?', [$username]);
        $valid = $emp && password_verify($password, $emp['password_hash']);

        if (!$valid) {
            Throttle::fail($keyIp, $max * 3, $lock, $lock);
            Throttle::fail($keyUser, $max, $lock, $lock);
            if ($emp) {
                Audit::log('auth.failed', 'محاولة دخول فاشلة للمستخدم ' . $username, 'employee', (int) $emp['id'], [], (int) $emp['id']);
            }
            return ['ok' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة.'];
        }
        if ($emp['status'] !== 'active') {
            return ['ok' => false, 'message' => 'هذا الحساب موقوف.'];
        }

        Throttle::clear($keyUser);
        Throttle::clear($keyIp);

        if (password_needs_rehash($emp['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('employees', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$emp['id']]);
        }
        return ['ok' => true, 'employee' => $emp];
    }

    /**
     * فتح جلسة للموظف. الدخول الصامت (دخول مسؤول المنصة إلى نظام الزبون) لا يترك أثراً عند الزبون:
     * لا يُسجَّل في سجل العمليات ولا في «آخر دخول» ولا يظهر في التنبيهات.
     */
    public static function loginAs(array $emp, string $ip, bool $silent = false): void
    {
        Session::regenerate();
        Session::set('employee_id', (int) $emp['id']);
        Session::set('session_version', (int) $emp['session_version']);
        Session::set('last_activity', time());
        Session::forget('_csrf');
        self::$loaded = false;
        self::$user = null;
        if ($silent) {
            return;
        }
        DB::update('employees', ['last_login_at' => now(), 'last_login_ip' => $ip], 'id = ?', [$emp['id']]);
        Audit::log('auth.login', 'تسجيل دخول', 'employee', (int) $emp['id'], [], (int) $emp['id']);
    }

    /** هل الجلسة الحالية لمسؤول المنصة داخل نظام الزبون؟ */
    public static function impersonating(): bool
    {
        return (string) Session::get('impersonated_by', '') !== '';
    }

    public static function logout(): void
    {
        $id = self::id();
        // خروج مسؤول المنصة من نظام الزبون صامت كدخوله
        if ($id && !self::impersonating()) {
            Audit::log('auth.logout', 'تسجيل خروج', 'employee', $id);
        }
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    private static function clearSession(): void
    {
        Session::forget('employee_id');
        Session::forget('session_version');
        Session::forget('last_activity');
        self::$user = null;
    }

    /** تفريغ الذاكرة المؤقتة عند تبديل سياق الزبون */
    public static function reset(): void
    {
        self::$user = null;
        self::$loaded = false;
        self::$permissions = [];
        self::$routerIds = null;
    }

    /** لاختبارات سطر الأوامر */
    public static function actingAs(?array $emp): void
    {
        self::$loaded = true;
        self::$user = $emp;
        if ($emp) {
            self::$permissions = $emp['is_owner']
                ? Permissions::all()
                : DB::column('SELECT permission FROM employee_permissions WHERE employee_id = ?', [$emp['id']]);
            self::$routerIds = ($emp['is_owner'] || $emp['router_scope'] === 'all')
                ? null
                : array_map('intval', DB::column('SELECT router_id FROM employee_routers WHERE employee_id = ?', [$emp['id']]));
        }
    }
}
