<?php
declare(strict_types=1);

namespace App\Core;

/**
 * دخول مسؤولي المنصة (قاعدة المنصة وجلسة مستقلة).
 */
final class AdminAuth
{
    private static ?array $admin = null;
    private static bool $loaded = false;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$admin;
        }
        self::$loaded = true;
        $id = (int) Session::get('admin_id', 0);
        if ($id <= 0) {
            return null;
        }
        $a = DB::row('SELECT * FROM platform_admins WHERE id = ? AND is_active = 1', [$id]);
        if (!$a || (int) $a['session_version'] !== (int) Session::get('admin_sv', -1)) {
            Session::forget('admin_id');
            return null;
        }
        $idle = 4 * 3600;
        $last = (int) Session::get('admin_last', 0);
        if ($last > 0 && time() - $last > $idle) {
            Session::forget('admin_id');
            return null;
        }
        Session::set('admin_last', time());
        return self::$admin = $a;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    /** مالك المنصة: كل الصلاحيات وإدارة الفريق */
    public static function isOwner(): bool
    {
        return !empty(self::user()['is_owner']);
    }

    /** هل للمسؤول الحالي صلاحية (أو إحدى صلاحيات القائمة) */
    public static function can(string|array $permission): bool
    {
        $u = self::user();
        if ($u === null) {
            return false;
        }
        $have = \App\AdminPermissions::of($u);
        foreach ((array) $permission as $p) {
            if (in_array($p, $have, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{ok:bool, message?:string} */
    public static function attempt(string $username, string $password, string $ip): array
    {
        foreach (['admin:ip:' . $ip, 'admin:user:' . mb_strtolower($username)] as $k) {
            $wait = Throttle::lockedFor($k);
            if ($wait > 0) {
                return ['ok' => false, 'message' => 'تم إيقاف الدخول مؤقتاً. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
            }
        }
        $a = DB::row('SELECT * FROM platform_admins WHERE username = ?', [$username]);
        if (!$a || !password_verify($password, $a['password_hash']) || !(int) $a['is_active']) {
            Throttle::fail('admin:ip:' . $ip, 15, 900, 1800);
            Throttle::fail('admin:user:' . mb_strtolower($username), 5, 900, 1800);
            Platform::log(null, 'visitor', 'admin.failed', 'محاولة دخول فاشلة إلى لوحة المسؤول: ' . $username);
            return ['ok' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة.'];
        }
        Throttle::clear('admin:user:' . mb_strtolower($username));
        if ((int) ($a['totp_enabled'] ?? 0) === 1 && (string) ($a['totp_secret'] ?? '') !== '') {
            // كلمة المرور صحيحة؛ يكتمل الدخول بعد رمز تطبيق المصادقة
            Session::regenerate();
            Session::set('admin_2fa', ['id' => (int) $a['id'], 'at' => time()]);
            return ['ok' => true, 'twofa' => true];
        }
        self::complete($a, $ip);
        return ['ok' => true];
    }

    private static function complete(array $a, string $ip): void
    {
        Session::regenerate();
        Session::forget('admin_2fa');
        Session::set('admin_id', (int) $a['id']);
        Session::set('admin_sv', (int) $a['session_version']);
        Session::set('admin_last', time());
        DB::update('platform_admins', ['last_login_at' => now(), 'last_login_ip' => $ip], 'id = ?', [$a['id']]);
        self::$loaded = false;
        Platform::log(null, 'admin', 'admin.login', 'دخول لوحة المسؤول', $a['name']);
    }

    /** المسؤول الذي أدخل كلمة المرور وينتظر رمز التحقق (صالح خمس دقائق) */
    public static function pendingTwoFactor(): ?array
    {
        $p = Session::get('admin_2fa');
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > 300) {
            Session::forget('admin_2fa');
            return null;
        }
        return DB::row('SELECT * FROM platform_admins WHERE id = ? AND is_active = 1 AND totp_enabled = 1', [(int) $p['id']]);
    }

    /** @return array{ok:bool, message?:string} */
    public static function verifyTwoFactor(string $code, string $ip): array
    {
        $a = self::pendingTwoFactor();
        if (!$a) {
            return ['ok' => false, 'expired' => true, 'message' => 'انتهت مهلة التحقق. سجّل الدخول من جديد.'];
        }
        $key = 'admin:2fa:' . $a['id'];
        if (($wait = Throttle::lockedFor($key)) > 0) {
            return ['ok' => false, 'message' => 'تم إيقاف التحقق مؤقتاً. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
        }
        $secret = Platform::unseal((string) $a['totp_secret']);
        $ok = \App\Services\Totp::verify($secret, $code);
        if (!$ok) {
            // رمز استرجاع يُستخدم مرة واحدة
            $hashes = json_decode((string) ($a['recovery_codes'] ?? ''), true);
            $hash = \App\Services\Totp::hashRecovery($code);
            if (is_array($hashes) && strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $code)) === 8 && in_array($hash, $hashes, true)) {
                DB::update('platform_admins', ['recovery_codes' => json_encode(array_values(array_diff($hashes, [$hash])))], 'id = ?', [$a['id']]);
                Platform::log(null, 'admin', 'admin.recovery', 'دخول برمز استرجاع', $a['name']);
                $ok = true;
            }
        }
        if (!$ok) {
            Throttle::fail($key, 6, 900, 900);
            Platform::log(null, 'visitor', 'admin.failed', 'رمز تحقق خاطئ للمسؤول: ' . $a['username']);
            return ['ok' => false, 'message' => 'رمز التحقق غير صحيح.'];
        }
        Throttle::clear($key);
        self::complete($a, $ip);
        return ['ok' => true];
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$admin = null;
        self::$loaded = true;
    }
}
