<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\DB;
use App\Core\Installer;
use App\Core\Migrator;
use App\Core\Platform;
use App\Core\Tenant;
use PDO;
use RuntimeException;
use Throwable;

/**
 * إنشاء حساب زبون جديد: قاعدة بيانات مستقلة، الجداول، حساب المالك، والإعدادات الأولى.
 */
final class TenantProvisioner
{
    /** التحقق من اسم الحساب (يظهر في الرابط) */
    public static function slugError(string $slug, ?int $exceptTenantId = null): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug) || str_contains($slug, '--')) {
            return 'اسم الحساب من 3 إلى 40 حرفاً إنجليزياً صغيراً أو أرقاماً أو شرطة (-).';
        }
        if (in_array($slug, Platform::RESERVED, true)) {
            return 'هذا الاسم محجوز.';
        }
        if (Platform::tenantBySlug($slug)) {
            return 'اسم الحساب مستخدم.';
        }
        // اسم سابق لحساب آخر ما زالت روابطه القديمة تعمل عليه
        $old = Platform::tenantByAlias($slug);
        if ($old && (int) $old['id'] !== (int) $exceptTenantId) {
            return 'اسم الحساب مستخدم.';
        }
        return null;
    }

    /**
     * تغيير اسم الحساب في الرابط. تنتقل ملفاته إلى الاسم الجديد، ويبقى الاسم القديم يعمل
     * (صفحات الهوتسبوت على الأجهزة، بوت تيليجرام، والتطبيق مربوطة به) ويُحوَّل المتصفح إلى الجديد.
     * @return array صف الحساب بعد التغيير
     */
    public static function rename(array $tenant, string $slug): array
    {
        $slug = strtolower(trim($slug));
        $old = (string) $tenant['slug'];
        if ($slug === $old) {
            return $tenant;
        }
        $error = self::slugError($slug, (int) $tenant['id']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        // مجلدات الحساب (الملفات المرفوعة، النسخ الاحتياطية، الجلسات) تحمل اسمه
        $moves = [];
        foreach ([STORAGE_PATH . '/tenants/', PUBLIC_PATH . '/uploads/t/'] as $base) {
            if (is_dir($base . $old)) {
                if (file_exists($base . $slug)) {
                    throw new RuntimeException('يوجد على الخادم مجلد بهذا الاسم من حساب سابق.');
                }
                $moves[] = [$base . $old, $base . $slug];
            }
        }
        $done = [];
        foreach ($moves as [$from, $to]) {
            if (!@rename($from, $to)) {
                foreach (array_reverse($done) as [$f, $t]) {
                    @rename($t, $f);
                }
                throw new RuntimeException('تعذّر نقل ملفات الحساب إلى الاسم الجديد.');
            }
            $done[] = [$from, $to];
        }
        try {
            DB::transaction(static function () use ($tenant, $slug, $old): void {
                DB::query('DELETE FROM tenant_aliases WHERE slug = ?', [$slug]);
                DB::update('tenants', ['slug' => $slug, 'updated_at' => now()], 'id = ?', [$tenant['id']]);
                DB::query('INSERT INTO tenant_aliases (slug, tenant_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)', [$old, $tenant['id'], now()]);
            });
        } catch (\Throwable $e) {
            foreach (array_reverse($done) as [$f, $t]) {
                @rename($t, $f);
            }
            throw new RuntimeException('تعذّر تغيير اسم الحساب.');
        }
        // إعدادات الحساب التي تحمل الاسم: الرابط العام الافتراضي ومسارات الملفات المرفوعة (الشعار)
        try {
            $pdo = Tenant::pdo($tenant);
            $root = Platform::rootUrl();
            $pdo->prepare('UPDATE settings SET `value` = ?, updated_at = ? WHERE `key` = ? AND (`value` = ? OR `value` = ?)')
                ->execute([$root . '/' . $slug, now(), 'general.panel_url', $root . '/' . $old, '']);
            $pdo->prepare('UPDATE settings SET `value` = REPLACE(`value`, ?, ?), updated_at = ? WHERE `value` LIKE ?')
                ->execute(['uploads/t/' . $old . '/', 'uploads/t/' . $slug . '/', now(), '%uploads/t/' . $old . '/%']);
        } catch (\Throwable $e) {
            log_message('error', 'tenant rename settings ' . $old . ' -> ' . $slug . ': ' . $e->getMessage());
        }
        return (array) DB::row('SELECT * FROM tenants WHERE id = ?', [$tenant['id']]);
    }

    /** اقتراح اسم حساب من اسم الشبكة */
    public static function suggest(string $name): string
    {
        $map = ['ا' => 'a', 'أ' => 'a', 'إ' => 'e', 'آ' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'th', 'ر' => 'r', 'ز' => 'z',
            'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ه' => 'h', 'ة' => 'a', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ئ' => 'e', 'ؤ' => 'o', 'ء' => ''];
        $clean = trim((string) preg_replace('/(^|\s)(شبكة|شبكه|شبكات|network|net)(?=\s|$)/iu', ' ', $name));
        $s = mb_strtolower(strtr($clean !== '' ? $clean : trim($name), $map));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = substr($s, 0, 30) ?: 'net';
        return strlen($s) < 3 ? $s . '-net' : $s;
    }

    /**
     * @param array{slug:string,name:string,owner_name:string,phone:?string,email:?string,username:string,password:string,ip?:string,status?:string} $d
     * @return array الزبون المنشأ
     */
    public static function create(array $d): array
    {
        $error = self::slugError($d['slug']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $cfg = Platform::tenantDb();
        $dbName = $cfg['prefix'] . str_replace('-', '_', $d['slug']);
        if (strlen($dbName) > 64) {
            throw new RuntimeException('اسم الحساب طويل.');
        }
        $days = max(1, (int) Platform::setting('platform.trial_days'));
        $now = date('Y-m-d H:i:s');
        $status = $d['status'] ?? 'trial';
        $id = DB::insert('tenants', [
            'slug' => $d['slug'], 'name' => $d['name'], 'owner_name' => $d['owner_name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null,
            'status' => $status, 'trial_ends_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'activated_at' => $status === 'active' ? $now : null,
            'db_name' => $dbName, 'app_key' => 'base64:' . base64_encode(random_bytes(32)), 'cron_key' => bin2hex(random_bytes(20)),
            'signup_ip' => $d['ip'] ?? null, 'created_at' => $now,
            // المساعد الذكي مفعّل للحساب الجديد افتراضياً (بالحد الافتراضي ما لم تحدده باقة)
            'ai_enabled' => Platform::setting('ai.new_enabled') === '1' ? 1 : 0,
        ]);
        $tenant = DB::row('SELECT * FROM tenants WHERE id = ?', [$id]);
        $created = false;
        try {
            $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            $pdo = Tenant::pdo($tenant);
            Installer::migrate($pdo);
            $settings = [
                'general.network_name' => $d['name'],
                'general.panel_url'    => Platform::rootUrl() . '/' . $d['slug'],
                'general.timezone'     => Platform::setting('platform.timezone'),
                'general.phone'        => $d['phone'] ?? '',
                'general.whatsapp'     => $d['phone'] ?? '',
                'app.schema_version'   => (string) Migrator::VERSION,
            ];
            $st = $pdo->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            foreach ($settings as $k => $v) {
                $st->execute([$k, $v, $now]);
            }
            $pdo->prepare('INSERT INTO employees (name, username, password_hash, phone, email, is_owner, role_preset, router_scope, status, theme, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)')
                ->execute([$d['owner_name'], $d['username'], $d['password_hash'] ?? password_hash($d['password'], PASSWORD_DEFAULT), $d['phone'] ?? null, $d['email'] ?? null, 'manager', 'all', 'active', 'light', $now]);
            $tenant['owner_id'] = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            if ($created) {
                try {
                    $server->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
                } catch (Throwable) {
                }
            }
            DB::delete('tenants', 'id = ?', [$id]);
            log_message('error', 'tenant provision: ' . $e->getMessage());
            throw new RuntimeException('تعذّر إنشاء الحساب حالياً. حاول لاحقاً.');
        }
        return $tenant;
    }

    /**
     * شبكة إضافية لمالك حساب قائم: قاعدة بيانات مستقلة بكل بياناتها، ويدخلها المالك باسم المستخدم وكلمة المرور نفسيهما.
     * @param array{slug:string, name:string, ip?:?string} $d
     */
    public static function addNetwork(array $source, array $d): array
    {
        $owner = Tenant::pdo($source)->query("SELECT name, username, password_hash, phone, email FROM employees WHERE is_owner = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetch();
        if (!$owner) {
            throw new RuntimeException('لا يوجد مالك فعّال في هذا الحساب.');
        }
        $new = self::create([
            'slug' => $d['slug'], 'name' => $d['name'], 'owner_name' => (string) $owner['name'], 'phone' => $owner['phone'] ?: ($source['phone'] ?? null),
            'email' => $owner['email'] ?: ($source['email'] ?? null), 'username' => (string) $owner['username'], 'password' => '',
            'password_hash' => (string) $owner['password_hash'], 'ip' => $d['ip'] ?? null,
        ]);
        $group = (int) ($source['group_id'] ?? 0) ?: (int) $source['id'];
        DB::update('tenants', ['group_id' => $group], 'id IN (?, ?)', [$source['id'], $new['id']]);
        $new['group_id'] = $group;
        return $new;
    }

    /**
     * إنشاء حساب من نسخة احتياطية لنظام سابق (النقل من استضافة أخرى): البيانات والمستخدمون وكلمات المرور كما هي.
     * مفتاح النظام السابق (من ملف config.php) يُبقي كلمات مرور أجهزة MikroTik المشفّرة صالحة.
     * @param array{slug:string, status?:string, ip?:string} $d
     * @return array الزبون المنشأ مع secrets_ok (هل تُفك الأسرار المخزّنة)
     */
    public static function import(array $d, string $sqlFile, ?string $oldKey = null): array
    {
        $error = self::slugError($d['slug']);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        if (!is_file($sqlFile)) {
            throw new RuntimeException('اختر ملف النسخة الاحتياطية.');
        }
        $cfg = Platform::tenantDb();
        $dbName = $cfg['prefix'] . str_replace('-', '_', $d['slug']);
        if (strlen($dbName) > 64) {
            throw new RuntimeException('اسم الحساب طويل.');
        }
        $days = max(1, (int) Platform::setting('platform.trial_days'));
        $now = date('Y-m-d H:i:s');
        $status = ($d['status'] ?? 'active') === 'trial' ? 'trial' : 'active';
        $id = DB::insert('tenants', [
            'slug' => $d['slug'], 'name' => $d['slug'], 'owner_name' => '—',
            'status' => $status, 'trial_ends_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'activated_at' => $status === 'active' ? $now : null,
            'db_name' => $dbName, 'app_key' => $oldKey ?? ('base64:' . base64_encode(random_bytes(32))), 'cron_key' => bin2hex(random_bytes(20)),
            'signup_ip' => $d['ip'] ?? null, 'created_at' => $now,
            // المساعد الذكي مفعّل للحساب الجديد افتراضياً (بالحد الافتراضي ما لم تحدده باقة)
            'ai_enabled' => Platform::setting('ai.new_enabled') === '1' ? 1 : 0,
        ]);
        $tenant = DB::row('SELECT * FROM tenants WHERE id = ?', [$id]);
        $created = false;
        $server = null;
        $info = [];
        try {
            $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $created = true;
            Tenant::enter($tenant);
            \App\Services\BackupService::restore($sqlFile);
            \App\Core\Settings::flush();
            $owner = DB::row("SELECT name, phone, email FROM employees WHERE is_owner = 1 ORDER BY id LIMIT 1");
            if (!$owner) {
                throw new RuntimeException('النسخة لا تحتوي على حساب مالك.');
            }
            Migrator::reset();
            Migrator::run();
            \App\Core\Settings::set('general.panel_url', Platform::rootUrl() . '/' . $d['slug']);
            $info = [
                'name' => \App\Core\Settings::networkName(), 'owner_name' => (string) $owner['name'],
                'phone' => $owner['phone'] ?: null, 'email' => $owner['email'] ?: null,
                'secrets_ok' => \App\Services\BackupService::secretsReadable(),
            ];
        } catch (Throwable $e) {
            Tenant::leave();
            if ($created && $server) {
                try {
                    $server->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
                } catch (Throwable) {
                }
            }
            DB::delete('tenants', 'id = ?', [$id]);
            log_message('error', 'tenant import: ' . $e->getMessage());
            throw new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'تعذّر استيراد النسخة: الملف غير متوافق.');
        }
        Tenant::leave();
        DB::update('tenants', [
            'name' => mb_substr($info['name'], 0, 100), 'owner_name' => mb_substr($info['owner_name'], 0, 120),
            'phone' => $info['phone'] !== null ? mb_substr((string) $info['phone'], 0, 30) : null,
            'email' => $info['email'] !== null ? mb_substr((string) $info['email'], 0, 120) : null,
        ], 'id = ?', [$id]);
        return (array) DB::row('SELECT * FROM tenants WHERE id = ?', [$id]) + ['secrets_ok' => $info['secrets_ok']];
    }

    /** حذف حساب زبون نهائياً مع قاعدة بياناته وملفاته */
    public static function destroy(array $tenant): void
    {
        $cfg = Platform::tenantDb();
        $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (preg_match('/^[A-Za-z0-9_]+$/', $tenant['db_name'])) {
            $server->exec('DROP DATABASE IF EXISTS `' . $tenant['db_name'] . '`');
        }
        DB::delete('tenants', 'id = ?', [$tenant['id']]);
        DB::delete('tenant_aliases', 'tenant_id = ?', [$tenant['id']]);
        \App\Services\Tunnel::purgeTenant((int) $tenant['id']);
        foreach ([STORAGE_PATH . '/tenants/' . $tenant['slug'], PUBLIC_PATH . '/uploads/t/' . $tenant['slug']] as $dir) {
            self::rmdir($dir);
        }
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir) || !str_contains($dir, '/' . 'tenants/') && !str_contains($dir, '/uploads/t/')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
