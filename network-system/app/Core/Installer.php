<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * منطق التثبيت والترقية: فحص المتطلبات، إنشاء الجداول، كتابة ملف الإعداد.
 */
final class Installer
{
    /** @return array<int, array{label:string, ok:bool, required:bool, note?:string}> */
    public static function requirements(): array
    {
        $configDir = BASE_PATH . '/config';
        $checks = [
            ['label' => 'PHP 8.1 أو أحدث (الحالي ' . PHP_VERSION . ')', 'ok' => PHP_VERSION_ID >= 80100, 'required' => true],
            ['label' => 'إضافة PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'required' => true],
            ['label' => 'إضافة mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true],
            ['label' => 'إضافة OpenSSL', 'ok' => extension_loaded('openssl'), 'required' => true],
            ['label' => 'إضافة JSON', 'ok' => function_exists('json_encode'), 'required' => true],
            ['label' => 'الاتصال الشبكي (stream_socket_client)', 'ok' => function_exists('stream_socket_client') && !self::disabled('stream_socket_client'), 'required' => true],
            ['label' => 'إضافة Sodium (تشفير)', 'ok' => function_exists('sodium_crypto_secretbox'), 'required' => false, 'note' => 'يُستخدم OpenSSL بديلاً'],
            ['label' => 'إضافة Zip (ملفات Excel)', 'ok' => class_exists('ZipArchive'), 'required' => false, 'note' => 'بدونها يقتصر الاستيراد والتصدير على CSV'],
            ['label' => 'إضافة cURL (إشعارات تيليجرام)', 'ok' => function_exists('curl_init'), 'required' => false],
            ['label' => 'مجلد storage قابل للكتابة', 'ok' => self::writable(STORAGE_PATH), 'required' => true],
            ['label' => 'مجلد public/uploads قابل للكتابة', 'ok' => self::writable(PUBLIC_PATH . '/uploads'), 'required' => true],
            ['label' => 'مجلد رفع الملفات المؤقت', 'ok' => self::uploadTmpOk(), 'required' => false, 'note' => 'لرفع الشعار وملفات الكروت'],
            ['label' => 'مجلد config قابل للكتابة', 'ok' => self::writable($configDir), 'required' => false, 'note' => 'سيُعرض محتوى ملف الإعداد لنسخه يدوياً'],
        ];
        return $checks;
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    private static function disabled(string $fn): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($fn, $list, true);
    }

    private static function uploadTmpOk(): bool
    {
        $dir = (string) ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        return @is_dir($dir) && @is_writable($dir);
    }

    private static function writable(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        return $pdo;
    }

    /** تقسيم ملف SQL إلى عبارات منفصلة */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $sql) as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $parts = preg_split('/;\s*$/mu', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    public static function migrate(PDO $pdo): void
    {
        $sql = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        foreach (self::statements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    public static function configContent(array $config): string
    {
        return "<?php\n// ملف إعداد النظام — أُنشئ تلقائياً عند التثبيت. لا تشاركه مع أحد.\nreturn "
            . var_export($config, true) . ";\n";
    }

    public static function writeConfig(array $config): bool
    {
        $file = BASE_PATH . '/config/config.php';
        $content = self::configContent($config);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return true;
    }

    /**
     * التثبيت الكامل.
     * @return array{config: array, written: bool}
     */
    public static function install(array $input): array
    {
        $pdo = self::connect($input['db_host'], (int) $input['db_port'], $input['db_name'], $input['db_user'], $input['db_pass']);

        $existing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'employees'")->fetchColumn();
        if ($existing > 0) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
            if ($count > 0) {
                throw new RuntimeException('قاعدة البيانات تحتوي على تثبيت سابق. استخدم قاعدة بيانات فارغة أو أعد ملف الإعداد السابق.');
            }
        }

        self::migrate($pdo);

        $now = date('Y-m-d H:i:s');
        $settings = [
            'general.network_name' => $input['network_name'],
            'general.panel_url'    => rtrim($input['app_url'], '/'),
            'general.timezone'     => $input['timezone'],
            'app.schema_version'   => (string) Migrator::VERSION,
        ];
        $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v, $now]);
        }

        $pdo->prepare('INSERT INTO employees (name, username, password_hash, is_owner, role_preset, router_scope, status, theme, created_at) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)')
            ->execute([$input['admin_name'], $input['admin_username'], password_hash($input['admin_password'], PASSWORD_DEFAULT), 'manager', 'all', 'active', 'light', $now]);

        $config = [
            'app' => [
                'url'      => rtrim($input['app_url'], '/'),
                'key'      => 'base64:' . base64_encode(random_bytes(32)),
                'debug'    => false,
                'timezone' => $input['timezone'],
                'cron_key' => bin2hex(random_bytes(20)),
            ],
            'db' => [
                'host'    => $input['db_host'],
                'port'    => (int) $input['db_port'],
                'name'    => $input['db_name'],
                'user'    => $input['db_user'],
                'pass'    => $input['db_pass'],
                'charset' => 'utf8mb4',
            ],
        ];

        foreach (['logs', 'tmp', 'backups', 'locks', 'cache', 'sessions', 'imports'] as $d) {
            if (!is_dir(STORAGE_PATH . '/' . $d)) {
                @mkdir(STORAGE_PATH . '/' . $d, 0775, true);
            }
        }

        return ['config' => $config, 'written' => self::writeConfig($config)];
    }
}
