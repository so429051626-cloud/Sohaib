<?php
declare(strict_types=1);

namespace App\Services;

/**
 * رموز التحقق بخطوتين لتطبيقات المصادقة (TOTP وفق RFC 6238) مع رموز استرجاع.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return self::base32(random_bytes($bytes));
    }

    public static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decode(string $secret): string
    {
        $secret = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secret, ?int $time = null, int $step = 30): string
    {
        $counter = intdiv($time ?? time(), $step);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** يقبل الرمز الحالي والسابق والتالي لتعويض فرق الساعة */
    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
    {
        $code = (string) preg_replace('/\D/', '', latin_digits($code));
        if (strlen($code) !== 6 || $secret === '') {
            return false;
        }
        $time ??= time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $time + $i * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return list<string> رموز استرجاع بصيغة xxxx-xxxx */
    public static function recoveryCodes(int $count = 8): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $h = bin2hex(random_bytes(4));
            $out[] = substr($h, 0, 4) . '-' . substr($h, 4, 4);
        }
        return $out;
    }

    public static function hashRecovery(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }
}
