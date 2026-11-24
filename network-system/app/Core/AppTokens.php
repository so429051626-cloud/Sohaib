<?php
declare(strict_types=1);

namespace App\Core;

/**
 * رموز دخول تطبيق الجوال: رمز عشوائي يُعطى للتطبيق مرة واحدة، ويُحفظ في القاعدة مُجزّأً.
 * يُلغى الرمز بتسجيل الخروج، أو بتغيير كلمة المرور (session_version)، أو بانتهاء مدته دون استخدام.
 */
final class AppTokens
{
    public const TTL_DAYS = 60;

    private static ?array $current = null;

    public static function issue(array $emp, string $name, string $platform, string $ip): string
    {
        $token = bin2hex(random_bytes(32));
        DB::insert('app_tokens', [
            'employee_id'     => (int) $emp['id'],
            'token_hash'      => hash('sha256', $token),
            'name'            => mb_substr(trim($name) !== '' ? trim($name) : 'Android', 0, 120),
            'platform'        => in_array($platform, ['android', 'ios', 'windows'], true) ? $platform : 'android',
            'session_version' => (int) $emp['session_version'],
            'created_at'      => now(),
            'last_used_at'    => now(),
            'last_ip'         => $ip,
            'expires_at'      => date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400),
        ]);
        return $token;
    }

    /** الرمز المرسل مع الطلب (Authorization: Bearer أو X-App-Token) */
    public static function fromRequest(Request $request): string
    {
        $h = (string) ($request->header('Authorization') ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m)) {
            return strtolower($m[1]);
        }
        $x = strtolower(trim((string) $request->header('X-App-Token')));
        return preg_match('/^[a-f0-9]{64}$/', $x) ? $x : '';
    }

    /** التحقق من رمز الطلب وإرجاع الموظف، وإلا 401 */
    public static function authenticate(Request $request): array
    {
        $token = self::fromRequest($request);
        if ($token === '') {
            throw new HttpException(401, 'سجّل الدخول من جديد.');
        }
        $row = DB::row(
            'SELECT t.*, e.status AS emp_status, e.session_version AS emp_version FROM app_tokens t JOIN employees e ON e.id = t.employee_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL',
            [hash('sha256', $token)]
        );
        if (!$row || strtotime((string) $row['expires_at']) < time()) {
            throw new HttpException(401, 'انتهت الجلسة. سجّل الدخول من جديد.');
        }
        if ($row['emp_status'] !== 'active' || (int) $row['emp_version'] !== (int) $row['session_version']) {
            DB::update('app_tokens', ['revoked_at' => now()], 'id = ?', [$row['id']]);
            throw new HttpException(401, 'انتهت الجلسة. سجّل الدخول من جديد.');
        }
        // تمديد المدة مع الاستخدام (مرة كل 10 دقائق كحد أعلى)
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 600) {
            DB::update('app_tokens', [
                'last_used_at' => now(),
                'last_ip'      => $request->ip(),
                'expires_at'   => date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400),
            ], 'id = ?', [$row['id']]);
        }
        self::$current = $row;
        $emp = DB::row('SELECT * FROM employees WHERE id = ?', [$row['employee_id']]);
        if (!$emp) {
            throw new HttpException(401);
        }
        return $emp;
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    public static function revokeCurrent(): void
    {
        if (self::$current) {
            DB::update('app_tokens', ['revoked_at' => now()], 'id = ?', [self::$current['id']]);
        }
    }

    public static function forEmployee(int $employeeId): array
    {
        return DB::rows(
            'SELECT id, name, platform, created_at, last_used_at, last_ip FROM app_tokens
             WHERE employee_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_used_at DESC',
            [$employeeId, now()]
        );
    }

    public static function revoke(int $employeeId, int $id): bool
    {
        return DB::update('app_tokens', ['revoked_at' => now()], 'id = ? AND employee_id = ? AND revoked_at IS NULL', [$id, $employeeId]) > 0;
    }
}
