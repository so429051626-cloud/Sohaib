<?php
declare(strict_types=1);

/*
 * المهمة المجدولة — تُشغَّل كل دقيقة:
 *   * * * * * php /path/to/cli/cron.php >/dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

if (!App\Core\Config::installed()) {
    fwrite(STDERR, "System is not installed.\n");
    exit(1);
}

// أثناء التحديث أو الاستعادة تُؤجَّل المهام إلى الدقيقة التالية
if (App\Core\Kernel::maintenance()) {
    echo json_encode(['ran' => false, 'maintenance' => true]) . PHP_EOL;
    exit(0);
}

$budget = (float) ($argv[1] ?? 55);

// وضع المنصة: تشغيل المهمة لكل الحسابات بالتناوب
if (App\Core\Platform::enabled()) {
    App\Core\Platform::boot();
    App\Core\Platform::migrate();
    echo json_encode(App\Controllers\PlatformController::runAll(max(10.0, min(300.0, $budget)), 'cli'), JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

App\Core\Migrator::run();

$result = App\Services\CronService::run(max(10.0, min(300.0, $budget)), 'cli');
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
