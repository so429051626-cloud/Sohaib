<?php
declare(strict_types=1);

/*
 * أوامر يستدعيها محرك الخادم بحساب مستخدم الويب:
 *   php cli/server.php alerts            إرسال التنبيهات الجديدة إلى تيليجرام
 *   php cli/server.php offsite-enabled   رمز الخروج 0 إذا كان النسخ الخارجي مفعّلاً
 *   php cli/server.php offsite FILE      إرسال أرشيف نسخة احتياطية مشفّراً
 *   php cli/server.php tunnel-export     إعادة كتابة قائمة أنفاق أجهزة MikroTik للمحرك
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Platform;
use App\Services\ServerPush;
use App\Services\Tunnel;

if (!Config::installed()) {
    exit(1);
}
if (Platform::enabled()) {
    Platform::boot();
    Platform::migrate();
}

switch ($argv[1] ?? '') {
    case 'alerts':
        echo ServerPush::alerts() . PHP_EOL;
        exit(0);
    case 'offsite-enabled':
        exit(ServerPush::offsiteEnabled() ? 0 : 1);
    case 'offsite':
        $r = ServerPush::offsite((string) ($argv[2] ?? ''));
        echo $r['message'] . PHP_EOL;
        exit($r['ok'] ? 0 : 1);
    case 'tunnel-export':
        Tunnel::export();
        exit(0);
    default:
        fwrite(STDERR, "alerts | offsite-enabled | offsite FILE | tunnel-export\n");
        exit(1);
}
