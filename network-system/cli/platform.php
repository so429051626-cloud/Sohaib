<?php
declare(strict_types=1);

/*
 * أدوات منصة MADIX NEXUS (وضع تعدد الحسابات):
 *   php cli/platform.php setup  --name="الاسم" --username=admin --password=كلمة_المرور
 *   php cli/platform.php admin  --username=admin --password=كلمة_جديدة [--name="الاسم"] [--owner]   (--owner: يصبح مالك المنصة)
 *   php cli/platform.php tenants
 *   php cli/platform.php import --slug=اسم-الحساب --file=backup.sql.gz [--config=config.php] [--status=active]
 *   php cli/platform.php cron
 * يمكن تحديد ملف الإعداد بالمتغير SN_CONFIG.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Core\Platform;

$cmd = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/s', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z_]+)$/', $a, $m)) {
        $opts[$m[1]] = '1';
    }
}
$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
};

if (!App\Core\Config::installed() || !Platform::enabled()) {
    $fail("وضع المنصة غير مفعّل في ملف الإعداد (platform.enabled).");
}
Platform::boot();
Platform::migrate();

switch ($cmd) {
    case 'setup':
    case 'admin':
        $username = trim($opts['username'] ?? '');
        $password = (string) ($opts['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) || mb_strlen($password) < 8) {
            $fail('استخدم --username (3 أحرف على الأقل) و--password (8 أحرف على الأقل).');
        }
        $existing = DB::row('SELECT * FROM platform_admins WHERE username = ?', [$username]);
        if ($existing) {
            DB::query('UPDATE platform_admins SET password_hash = ?, session_version = session_version + 1, is_active = 1' . (isset($opts['name']) ? ', name = ?' : '') . ' WHERE id = ?',
                array_merge([password_hash($password, PASSWORD_DEFAULT)], isset($opts['name']) ? [$opts['name']] : [], [$existing['id']]));
            echo "تم تحديث المسؤول {$username}." . PHP_EOL;
        } else {
            DB::insert('platform_admins', ['name' => $opts['name'] ?? $username, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => now()]);
            echo "تم إنشاء المسؤول {$username}." . PHP_EOL;
        }
        // أول مسؤول هو مالك المنصة، و--owner ينقل الملكية إلى هذا المسؤول (استعادة الإدارة)
        if (isset($opts['owner'])) {
            DB::query('UPDATE platform_admins SET is_owner = (username = ?), permissions = IF(username = ?, NULL, permissions)', [$username, $username]);
            echo "{$username} الآن مالك المنصة." . PHP_EOL;
        }
        Platform::ensureOwner();
        if ($cmd === 'setup') {
            foreach (['tenants', 'sessions', 'logs', 'locks'] as $d) {
                @mkdir(STORAGE_PATH . '/' . $d, 0775, true);
            }
            @mkdir(PUBLIC_PATH . '/uploads/t', 0775, true);
            echo 'المنصة جاهزة: ' . Platform::rootUrl() . '/admin' . PHP_EOL;
        }
        break;
    case 'tenants':
        foreach (DB::rows('SELECT slug, name, status, trial_ends_at, paid_until FROM tenants ORDER BY id') as $t) {
            echo str_pad($t['slug'], 24) . str_pad($t['status'], 11) . $t['name'] . PHP_EOL;
        }
        break;
    case 'import':
        $slug = strtolower(trim($opts['slug'] ?? ''));
        $file = (string) ($opts['file'] ?? '');
        if ($slug === '' || !is_file($file)) {
            $fail('استخدم --slug=اسم-الحساب و--file=مسار ملف النسخة الاحتياطية.');
        }
        $oldKey = null;
        if (isset($opts['config'])) {
            $oldKey = App\Services\BackupService::keyFromConfig((string) @file_get_contents($opts['config']));
            if ($oldKey === null) {
                $fail('ملف الإعداد لا يحتوي على مفتاح النظام.');
            }
        }
        try {
            $t = App\Platform\TenantProvisioner::import(['slug' => $slug, 'status' => $opts['status'] ?? 'active'], $file, $oldKey);
        } catch (Throwable $e) {
            $fail($e->getMessage());
        }
        Platform::log((int) $t['id'], 'system', 'tenant.import', 'استيراد حساب ' . $t['name'] . ' من نسخة احتياطية');
        echo 'تم استيراد الحساب: ' . Platform::rootUrl() . '/' . $t['slug'] . PHP_EOL;
        if ($t['secrets_ok'] === false) {
            echo 'تنبيه: كلمات مرور أجهزة MikroTik مشفّرة بمفتاح النظام السابق.' . PHP_EOL;
        }
        break;
    case 'cron':
        echo json_encode(App\Controllers\PlatformController::runAll(55.0, 'cli'), JSON_UNESCAPED_UNICODE) . PHP_EOL;
        break;
    default:
        $fail("الأوامر: setup | admin | tenants | import | cron");
}
