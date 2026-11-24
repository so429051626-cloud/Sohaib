<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * الخادم المُدار: حالة الخادم التي يكتبها محرك MADIX (بصلاحية الجذر) في مجلد للقراءة فقط،
 * والطلبات التي يكتبها النظام ليطبّقها المحرك، وإعدادات الإشعارات والنسخ الخارجي.
 */
final class Server
{
    public const DEFAULT_DIR = '/var/lib/madix';

    /** الطلبات المسموح للنظام بإرسالها إلى المحرك */
    public const REQUESTS = ['backup', 'collect'];

    public static function dir(): string
    {
        return rtrim((string) Config::get('server.dir', self::DEFAULT_DIR), '/');
    }

    /** هل يعمل النظام على خادم يديره محرك MADIX */
    public static function managed(): bool
    {
        return @is_readable(self::dir() . '/status.json');
    }

    private static function json(string $name): array
    {
        $raw = @file_get_contents(self::dir() . '/' . $name);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function status(): array
    {
        return self::json('status.json');
    }

    /** الحالة حديثة إذا كُتبت خلال آخر ثلاث دقائق */
    public static function fresh(array $status): bool
    {
        return isset($status['time']) && time() - (int) $status['time'] < 180;
    }

    /** @return array{last_id:int, items:array<int,array>} */
    public static function alerts(): array
    {
        $a = self::json('alerts.json');
        return ['last_id' => (int) ($a['last_id'] ?? 0), 'items' => is_array($a['items'] ?? null) ? $a['items'] : []];
    }

    /** مجلد ملفات النظام التي يقرؤها المحرك */
    public static function spool(): string
    {
        $dir = BASE_PATH . '/storage/server';
        if (!is_dir($dir . '/requests')) {
            @mkdir($dir . '/requests', 0770, true);
        }
        return $dir;
    }

    /** طلب عملية من المحرك (تُنفَّذ خلال لحظات) */
    public static function request(string $name): bool
    {
        if (!in_array($name, self::REQUESTS, true)) {
            return false;
        }
        $dir = self::spool();
        if (@file_put_contents($dir . '/requests/' . $name, (string) time(), LOCK_EX) === false) {
            return false;
        }
        @file_put_contents($dir . '/queue.flag', (string) microtime(true), LOCK_EX);
        return true;
    }

    public static function requested(string $name): bool
    {
        return is_file(BASE_PATH . '/storage/server/requests/' . $name);
    }

    // ------------------------------------------------------------------
    // الإعدادات: في قاعدة المنصة، أو في إعدادات النظام عند تثبيت شبكة واحدة
    // ------------------------------------------------------------------

    public static function setting(string $key, string $default = ''): string
    {
        if (Platform::enabled()) {
            return Platform::setting('server.' . $key, $default);
        }
        $v = Settings::get('server.' . $key);
        return $v === null || $v === '' ? $default : (string) $v;
    }

    public static function setSettings(array $values): void
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out['server.' . $k] = (string) $v;
        }
        if (Platform::enabled()) {
            Platform::setSettings($out);
        } else {
            Settings::setMany($out);
        }
    }

    public static function secret(string $key): string
    {
        $enc = self::setting($key);
        if ($enc === '') {
            return '';
        }
        try {
            return Crypto::decrypt($enc);
        } catch (Throwable) {
            return '';
        }
    }

    public static function setSecret(string $key, string $plain): void
    {
        self::setSettings([$key => $plain === '' ? '' : Crypto::encrypt($plain)]);
    }

    /** أحجام قواعد البيانات (المنصة والحسابات) بالبايت */
    public static function databaseSizes(): array
    {
        try {
            if (Platform::enabled()) {
                $root = Platform::rootDb()['name'];
                $prefix = Platform::tenantDb()['prefix'];
                $st = Platform::pdo()->prepare('SELECT table_schema AS db, SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = ? OR table_schema LIKE ? GROUP BY table_schema');
                $st->execute([$root, str_replace('_', '\\_', $prefix) . '%']);
            } else {
                $st = DB::pdo()->prepare('SELECT table_schema AS db, SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE() GROUP BY table_schema');
                $st->execute();
            }
            $out = [];
            foreach ($st->fetchAll() as $r) {
                $out[(string) $r['db']] = (int) $r['size'];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
