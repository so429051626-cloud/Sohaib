<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Throttle;

/**
 * التحقق بخطوتين لمستخدمي نظام الشبكة (المالك وموظفوه):
 * تطبيق مصادقة (TOTP) مع رموز استرجاع تُستخدم مرة واحدة. اختياري لكل مستخدم، ويستطيع المالك فرضه على موظفيه.
 * السر يُحفظ مشفّراً بمفتاح التطبيق، ورموز الاسترجاع تُحفظ كبصمات فقط.
 */
final class TwoFactor
{
    public static function enabled(?array $emp): bool
    {
        return $emp !== null && (int) ($emp['totp_enabled'] ?? 0) === 1 && (string) ($emp['totp_secret'] ?? '') !== '';
    }

    /** المالك يفرضه على موظفيه */
    public static function required(): bool
    {
        return Settings::bool('security.require_2fa');
    }

    /** موظف عليه تفعيله قبل استخدام النظام */
    public static function mustEnroll(?array $emp): bool
    {
        return $emp !== null && !(int) $emp['is_owner'] && !self::enabled($emp) && self::required();
    }

    public static function secret(array $emp): string
    {
        try {
            return Crypto::decrypt((string) ($emp['totp_secret'] ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function recoveryLeft(array $emp): int
    {
        return count((array) json_decode((string) ($emp['recovery_codes'] ?? '[]'), true));
    }

    /** @return list<string> رموز الاسترجاع (تُعرض مرة واحدة) */
    public static function enable(int $employeeId, string $secret): array
    {
        $codes = Totp::recoveryCodes();
        DB::update('employees', [
            'totp_secret' => Crypto::encrypt($secret), 'totp_enabled' => 1,
            'recovery_codes' => json_encode(array_map([Totp::class, 'hashRecovery'], $codes)), 'updated_at' => now(),
        ], 'id = ?', [$employeeId]);
        return $codes;
    }

    public static function disable(int $employeeId): void
    {
        DB::update('employees', ['totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null, 'updated_at' => now()], 'id = ?', [$employeeId]);
        Throttle::clear('login:2fa:' . $employeeId);
    }

    /** @return list<string> */
    public static function newCodes(int $employeeId): array
    {
        $codes = Totp::recoveryCodes();
        DB::update('employees', ['recovery_codes' => json_encode(array_map([Totp::class, 'hashRecovery'], $codes))], 'id = ?', [$employeeId]);
        return $codes;
    }

    /**
     * فحص رمز التطبيق أو رمز استرجاع (يُستهلك) مع إيقاف مؤقت بعد محاولات خاطئة متكررة.
     * @return array{ok:bool, message?:string, recovery?:bool}
     */
    public static function check(array $emp, string $code): array
    {
        $key = 'login:2fa:' . $emp['id'];
        if (($wait = Throttle::lockedFor($key)) > 0) {
            return ['ok' => false, 'message' => 'تم إيقاف التحقق مؤقتاً بسبب كثرة المحاولات. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
        }
        $code = trim(latin_digits($code));
        if (Totp::verify(self::secret($emp), $code)) {
            Throttle::clear($key);
            return ['ok' => true];
        }
        $hashes = json_decode((string) ($emp['recovery_codes'] ?? ''), true);
        $hash = Totp::hashRecovery($code);
        if (is_array($hashes) && strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $code)) === 8 && in_array($hash, $hashes, true)) {
            // الحذف المشروط يمنع استخدام الرمز نفسه في طلبين متزامنين
            $left = json_encode(array_values(array_diff($hashes, [$hash])));
            if (DB::query('UPDATE employees SET recovery_codes = ? WHERE id = ? AND recovery_codes = ?', [$left, $emp['id'], (string) $emp['recovery_codes']])->rowCount() === 1) {
                Throttle::clear($key);
                Audit::log('auth.recovery', 'دخول برمز استرجاع', 'employee', (int) $emp['id'], [], (int) $emp['id']);
                return ['ok' => true, 'recovery' => true];
            }
        }
        Throttle::fail($key, 6, 900, 900);
        Audit::log('auth.failed', 'رمز تحقق خاطئ للمستخدم ' . $emp['username'], 'employee', (int) $emp['id'], [], (int) $emp['id']);
        return ['ok' => false, 'message' => 'رمز التحقق غير صحيح.'];
    }
}
