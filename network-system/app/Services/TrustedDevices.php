<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Throttle;

/**
 * الأجهزة الموثوقة للدخول بالبصمة من تطبيقات Windows وAndroid.
 * يحفظ التطبيق رمز الجهاز في مخزن آمن لا يُفتح إلا بالبصمة، والخادم يحفظ بصمته (SHA-256) فقط.
 * يُستبدل الرمز بعد كل دخول، ويُلغى عند تغيير كلمة المرور.
 */
final class TrustedDevices
{
    public const PLATFORMS = ['android' => 'Android', 'windows' => 'Windows', 'ios' => 'iOS', 'other' => 'جهاز آخر'];

    /** @return array{id:int, token:string} */
    public static function enroll(int $employeeId, string $name, string $platform): array
    {
        $platform = isset(self::PLATFORMS[$platform]) ? $platform : 'other';
        $name = trim(mb_substr(preg_replace('/[\x00-\x1F]/u', '', $name) ?? '', 0, 120)) ?: self::PLATFORMS[$platform];
        $token = bin2hex(random_bytes(32));
        $id = DB::insert('employee_devices', [
            'employee_id' => $employeeId, 'name' => $name, 'platform' => $platform,
            'token_hash' => hash('sha256', $token), 'created_at' => now(),
        ]);
        return ['id' => $id, 'token' => $token];
    }

    /**
     * التحقق من رمز جهاز واستبداله.
     * @return array{ok:bool, message?:string, employee?:array, token?:string}
     */
    public static function verify(int $deviceId, string $token, string $ip): array
    {
        $key = 'device:ip:' . $ip;
        $wait = Throttle::lockedFor($key);
        if ($wait > 0) {
            return ['ok' => false, 'message' => 'تم إيقاف الدخول مؤقتاً. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
        }
        $device = DB::row('SELECT * FROM employee_devices WHERE id = ?', [$deviceId]);
        if (!$device || $device['revoked_at'] !== null || !preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals((string) $device['token_hash'], hash('sha256', $token))) {
            Throttle::fail($key, 10, 900, 900);
            return ['ok' => false, 'message' => 'انتهت صلاحية الدخول بالبصمة على هذا الجهاز. سجّل الدخول بكلمة المرور.', 'reset' => true];
        }
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$device['employee_id']]);
        if (!$emp || $emp['status'] !== 'active') {
            return ['ok' => false, 'message' => 'هذا الحساب موقوف.', 'reset' => true];
        }
        Throttle::clear($key);
        $new = bin2hex(random_bytes(32));
        DB::update('employee_devices', ['token_hash' => hash('sha256', $new), 'last_used_at' => now(), 'last_ip' => $ip], 'id = ?', [$deviceId]);
        return ['ok' => true, 'employee' => $emp, 'token' => $new];
    }

    public static function list(int $employeeId): array
    {
        return DB::rows('SELECT id, name, platform, created_at, last_used_at, last_ip FROM employee_devices WHERE employee_id = ? AND revoked_at IS NULL ORDER BY COALESCE(last_used_at, created_at) DESC', [$employeeId]);
    }

    public static function revoke(int $employeeId, int $deviceId): bool
    {
        return DB::query('UPDATE employee_devices SET revoked_at = ? WHERE id = ? AND employee_id = ? AND revoked_at IS NULL', [now(), $deviceId, $employeeId])->rowCount() > 0;
    }

    public static function revokeAll(int $employeeId): int
    {
        return DB::query('UPDATE employee_devices SET revoked_at = ? WHERE employee_id = ? AND revoked_at IS NULL', [now(), $employeeId])->rowCount();
    }
}
