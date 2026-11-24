<?php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // تنبيهات الإيقاف في إصدارات PHP الأحدث تُسجَّل فقط ولا توقف النظام (إلا في وضع التطوير)
            if (($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) && !Config::get('app.debug')) {
                static $seen = [];
                $key = $file . ':' . $line;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    log_message('deprecated', $message . ' @ ' . $file . ':' . $line);
                }
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function report(Throwable $e): string
    {
        $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        log_message('error', '[' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), [
            'uri'   => $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : ''),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
        return $ref;
    }

    private static function shortPath(string $file): string
    {
        $base = str_replace('\\', '/', BASE_PATH);
        $file = str_replace('\\', '/', $file);
        return str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/') : basename($file);
    }

    /** تحويل الاستثناء إلى استجابة مناسبة لنوع الطلب */
    public static function render(Throwable $e, ?Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        $ref = null;
        if (!$e instanceof HttpException) {
            $ref = self::report($e);
        }
        $message = $e instanceof HttpException ? $e->getMessage() : HttpException::defaultMessage(500);

        if ($request && $request->wantsJson()) {
            $payload = ['ok' => false, 'message' => $message];
            if ($ref) {
                $payload['ref'] = $ref;
            }
            if (Config::get('app.debug') && !$e instanceof HttpException) {
                $payload['debug'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            }
            return Response::json($payload, $status);
        }

        try {
            $layout = Platform::enabled() && Tenant::current() === null ? 'public' : (Auth::check() ? 'app' : 'auth');
            $html = View::render('errors/error', [
                'status'  => $status,
                'message' => $message,
                'ref'     => $ref,
                'debug'   => $e instanceof HttpException ? null : (Config::get('app.debug')
                    ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
                    // قبل التثبيت لا توجد بيانات تُحمى: يُعرض سبب الخطأ مباشرة
                    : (!Config::installed() ? $e->getMessage() . "\n" . self::shortPath($e->getFile()) . ':' . $e->getLine() : null)),
            ], $layout);
        } catch (Throwable $inner) {
            self::report($inner);
            $html = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ</title>'
                . '<body style="font-family:system-ui;padding:40px"><h1>' . $status . '</h1><p>' . e($message) . '</p>'
                . ($ref ? '<p>رقم المرجع: ' . e($ref) . '</p>' : '') . '</body></html>';
        }
        return new Response($html, $status);
    }
}
