<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * تشفير الأسرار المخزّنة (كلمات مرور واجهة جهاز MikroTik) بمفتاح التطبيق.
 * يعتمد libsodium المدمج في PHP، ويرجع إلى OpenSSL إن لم يتوفر.
 */
final class Crypto
{
    private static function key(?string $raw = null): string
    {
        $raw ??= (string) Config::get('app.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = (string) base64_decode(substr($raw, 7), true);
        }
        if (strlen($raw) < 16) {
            throw new RuntimeException('مفتاح التطبيق غير مضبوط في ملف الإعداد.');
        }
        return hash('sha256', $raw, true);
    }

    /** @param ?string $rawKey مفتاح بديل عن مفتاح التطبيق الحالي (مفتاح المنصة الجذري مثلاً) */
    public static function encrypt(string $plain, ?string $rawKey = null): string
    {
        $key = self::key($rawKey);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'g1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $value, ?string $rawKey = null): string
    {
        $key = self::key($rawKey);
        if (str_starts_with($value, 's1:')) {
            $bin = (string) base64_decode(substr($value, 3), true);
            $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException('تعذّر فك تشفير القيمة المخزّنة.');
            }
            return $plain;
        }
        if (str_starts_with($value, 'g1:')) {
            $bin = (string) base64_decode(substr($value, 3), true);
            $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
            if ($plain === false) {
                throw new RuntimeException('تعذّر فك تشفير القيمة المخزّنة.');
            }
            return $plain;
        }
        return $value;
    }
}
