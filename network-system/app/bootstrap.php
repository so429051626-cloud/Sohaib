<?php
declare(strict_types=1);

/*
 * نقطة الإقلاع المشتركة بين الويب وسطر الأوامر.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('APP_VERSION', '2.2.1');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('يتطلب النظام PHP 8.1 أو أحدث.');
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

$configFile = getenv('SN_CONFIG') ?: BASE_PATH . '/config/config.php';
App\Core\Config::load(is_file($configFile) ? (require $configFile) : null);

date_default_timezone_set(App\Core\Config::get('app.timezone', 'Asia/Gaza'));

App\Core\ErrorHandler::register();

if (PHP_SAPI !== 'cli') {
    ensure_php_temp_dirs();
}

if (App\Core\Config::installed()) {
    // المنطقة الزمنية من الإعدادات تتقدّم على ملف الإعداد
    try {
        $tz = App\Core\Settings::get('general.timezone');
        if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
            App\Core\DB::syncTimezone();
        }
    } catch (Throwable $e) {
        // قاعدة البيانات غير متاحة بعد؛ سيُعالَج الخطأ عند أول استعلام
    }
}
