<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Kernel
{
    /** مسارات عامة بلا جلسة (واجهات الهوتسبوت وجهاز MikroTik والمهام المجدولة) */
    private const STATELESS = ['/api/hotspot', '/api/router', '/api/app', '/api/whatsapp', '/api/bot', '/api/rum', '/cron', '/hotspot/sdk', '/health'];

    /** أقصى مدة لوضع الصيانة؛ بعدها يُتجاهل الملف حتى لا يبقى النظام مغلقاً إن تعطّل التحديث */
    private const MAINTENANCE_MAX = 900;

    /** أعمال تُنفَّذ بعد إرسال الاستجابة للمتصفح */
    private static array $after = [];

    public static function afterResponse(callable $fn): void
    {
        self::$after[] = $fn;
    }

    /** هل النظام في وضع الصيانة (أثناء التحديث أو الاستعادة على الخادم) */
    public static function maintenance(): bool
    {
        $file = STORAGE_PATH . '/maintenance';
        return is_file($file) && time() - (int) @filemtime($file) < self::MAINTENANCE_MAX;
    }

    private static function maintenanceResponse(): Response
    {
        $request = Request::capture();
        $path = $request->path;
        if ($path === '/health') {
            return Response::json(['ok' => true, 'maintenance' => true, 'time' => time()]);
        }
        $headers = ['Retry-After' => '20', 'Cache-Control' => 'no-store'];
        if ($request->wantsJson() || str_contains($path, '/api/') || str_ends_with($path, '/cron')) {
            return Response::json(['ok' => false, 'maintenance' => true, 'message' => 'النظام قيد التحديث، حاول بعد لحظات.'], 503, $headers);
        }
        $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="8"><meta name="robots" content="noindex"><title>تحديث النظام</title><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#f4f6fb;color:#1b2540}'
            . '.c{text-align:center;padding:32px}.s{width:54px;height:54px;margin:0 auto 22px;border-radius:50%;border:4px solid #d8e0f3;border-top-color:#2453d6;animation:r 1s linear infinite}'
            . 'h1{font-size:20px;margin:0 0 8px}p{margin:0;color:#64708c;font-size:14.5px}@keyframes r{to{transform:rotate(360deg)}}'
            . '</style></head><body><div class="c"><div class="s"></div><h1>جارٍ تحديث النظام</h1><p>يعود خلال لحظات.</p></div></body></html>';
        return new Response($html, 503, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function handle(): void
    {
        if (self::maintenance()) {
            self::maintenanceResponse()->send();
            return;
        }
        if (Config::installed() && Platform::enabled()) {
            self::handlePlatform();
            return;
        }
        $request = Request::capture();
        try {
            $router = new Router();
            if (!Config::installed()) {
                Session::start($request->isHttps());
                (require APP_PATH . '/routes_install.php')($router);
                $response = str_starts_with($request->path, '/install')
                    ? $router->dispatch($request)
                    : redirect('/install');
            } else {
                if (!self::isStateless($request->path)) {
                    Session::start($request->isHttps());
                }
                Migrator::run();
                (require APP_PATH . '/routes.php')($router);
                $response = $router->dispatch($request);
            }
        } catch (Throwable $e) {
            $response = ErrorHandler::render($e, $request);
        }
        self::finish($response, $request);
    }

    /**
     * وضع المنصة: الجذر للمنصة (الصفحة الرئيسية، التسجيل، الدخول، لوحة المسؤول)،
     * و/{اسم-الحساب}/... لنظام الزبون بقاعدة بياناته المستقلة.
     */
    private static function handlePlatform(): void
    {
        Platform::boot();
        $request = Request::capture();
        try {
            $seg = explode('/', trim($request->path, '/'))[0];
            $isTenant = $seg !== '' && !in_array($seg, Platform::RESERVED, true) && preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $seg);
            if (!$isTenant) {
                if (!self::isStateless($request->path)) {
                    Session::start($request->isHttps(), 'MNXP');
                }
                Platform::migrate();
                $router = new Router();
                (require APP_PATH . '/routes_platform.php')($router);
                $response = $router->dispatch($request);
            } else {
                $tenant = Platform::tenantBySlug($seg);
                if (!$tenant && ($tenant = Platform::tenantByAlias($seg))) {
                    // اسم سابق للحساب: المتصفح يُحوَّل إلى الرابط الجديد، وما عداه (صفحات الهوتسبوت على الأجهزة، البوت، التطبيق) يُخدم كما هو
                    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                    $root = root_path();
                    $canonical = $root . '/' . $tenant['slug'] . substr($uri, strlen($root . '/' . $seg));
                    $inner = '/' . ltrim(substr($request->path, strlen('/' . $seg)), '/');
                    if ($request->method === 'GET' && !self::isStateless($inner) && !$request->wantsJson()) {
                        // تحويل مؤقت (302): الاسم قد يعود لصاحبه لاحقاً، والمتصفحات تحفظ التحويل الدائم
                        self::finish(Response::redirect($canonical), $request);
                        return;
                    }
                    $_SERVER['REQUEST_URI'] = $canonical;
                }
                if (!$tenant) {
                    throw new HttpException(404);
                }
                // /اسم-الحساب بلا شرطة أخيرة: ملف تعريف الجلسة مسجّل على المسار /اسم-الحساب/ فلا يُرسله المتصفح هنا،
                // فتظهر صفحة الدخول لمستخدم داخل فعلاً. التحويل إلى المسار الكامل قبل أي شيء آخر.
                $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
                if (in_array($request->method, ['GET', 'HEAD'], true) && $uriPath === root_path() . '/' . $seg) {
                    $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                    self::finish(Response::redirect(root_path() . '/' . $tenant['slug'] . '/' . ($query !== '' ? '?' . $query : '')), $request);
                    return;
                }
                Tenant::enter($tenant);
                $request = Request::capture();
                if (!Tenant::usable($tenant)) {
                    if (!self::isStateless($request->path)) {
                        Session::start($request->isHttps());
                    }
                    $response = self::isStateless($request->path)
                        ? Response::json(['ok' => false, 'message' => 'الخدمة متوقفة مؤقتاً.'], 403)
                        : view('platform/blocked', ['tenant' => $tenant], 'split');
                } else {
                    if (!self::isStateless($request->path)) {
                        Session::start($request->isHttps());
                    }
                    Migrator::run();
                    // دخول مسؤول المنصة لا يُحتسب نشاطاً للزبون
                    if (($tenant['last_seen_at'] === null || strtotime($tenant['last_seen_at']) < time() - 300) && !Auth::impersonating() && !str_starts_with($request->path, '/auth/ticket/')) {
                        Platform::exec('UPDATE tenants SET last_seen_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $tenant['id']]);
                    }
                    if (Tenant::readonlyBlocks($tenant, $request->method, $request->path)) {
                        // انتهى الاشتراك وفترة السماح: يبقى العرض متاحاً ويتوقف التعديل حتى التجديد
                        $message = 'الحساب في وضع القراءة فقط حتى تجديد الاشتراك.';
                        if (self::isStateless($request->path) || $request->wantsJson()) {
                            $response = Response::json(['ok' => false, 'readonly' => true, 'message' => $message], 403);
                        } else {
                            flash('error', $message);
                            $response = back('/billing');
                        }
                    } else {
                        $router = new Router();
                        (require APP_PATH . '/routes.php')($router);
                        $response = $router->dispatch($request);
                    }
                }
            }
        } catch (Throwable $e) {
            $response = ErrorHandler::render($e, $request);
        }
        self::finish($response, $request);
    }

    private static function finish(Response $response, Request $request): void
    {
        try {
            $response = I18n::response($response);
        } catch (Throwable $e) {
            ErrorHandler::report($e);
        }
        self::secureHeaders($response, $request);
        if (self::$after) {
            $response->headers['Connection'] = 'close';
            $response->headers['Content-Length'] = (string) strlen($response->body);
        }
        $response->send();
        if (self::$after) {
            Session::release();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }
            @ignore_user_abort(true);
            foreach (self::$after as $fn) {
                try {
                    $fn();
                } catch (Throwable $e) {
                    ErrorHandler::report($e);
                }
            }
        }
    }

    public static function isStateless(string $path): bool
    {
        foreach (self::STATELESS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function secureHeaders(Response $response, Request $request): void
    {
        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'same-origin',
        ];
        if (!self::isStateless($request->path)) {
            $response->headers += [
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cache-Control'   => 'no-store, private',
            ];
            // بعد أي تعديل (طلب غير GET) تُحذف الصفحات المحمّلة مسبقاً، فلا تظهر صفحة من قبل التعديل
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                $response->headers += ['Clear-Site-Data' => '"prefetchCache"'];
            }
        }
    }
}
