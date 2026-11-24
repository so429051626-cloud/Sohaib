<?php
declare(strict_types=1);

namespace App\Core;

/**
 * موجّه الطلبات.
 * الخيارات لكل مسار:
 *   perm  => صلاحية مطلوبة (نص أو مصفوفة: يكفي امتلاك إحداها)
 *   auth  => يتطلب تسجيل دخول (افتراضياً true)
 *   csrf  => التحقق من رمز CSRF لطلبات POST (افتراضياً true)
 *   guest => للزوار فقط (صفحة الدخول)
 *   cors  => إضافة ترويسات CORS (واجهات الهوتسبوت العامة)
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    public function any(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
        $this->add('POST', $path, $handler, $options);
    }

    private function add(string $method, string $path, array $handler, array $options): void
    {
        $regex = preg_replace_callback('#\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}#', static function ($m) {
            $pattern = $m[2] ?? ($m[1] === 'id' || str_ends_with($m[1], 'Id') ? '\d+' : '[^/]+');
            return '(?P<' . $m[1] . '>' . $pattern . ')';
        }, rtrim($path, '/') ?: '/');
        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'handler' => $handler,
            'options' => $options + ['auth' => true, 'csrf' => true, 'perm' => null, 'guest' => false, 'cors' => false],
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path;
        $allowed = [];

        if ($request->method === 'OPTIONS') {
            foreach ($this->routes as $route) {
                if ($route['options']['cors'] && preg_match($route['regex'], $path)) {
                    return self::cors(new Response('', 204));
                }
            }
        }

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[] = ($k === 'id' || str_ends_with($k, 'Id')) ? (int) $v : $v;
                }
            }
            $response = $this->run($route, $request, $params);
            return $route['options']['cors'] ? self::cors($response) : $response;
        }

        if ($allowed) {
            throw new HttpException(405);
        }
        throw new HttpException(404);
    }

    private function run(array $route, Request $request, array $params): Response
    {
        $o = $route['options'];

        // لوحة مسؤول المنصة
        if (!empty($o['admin']) && !AdminAuth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            return redirect('/admin/login');
        }
        if (!empty($o['adminGuest']) && AdminAuth::check()) {
            return redirect('/admin');
        }
        // صلاحيات فريق الإدارة: aperm (إحدى الصلاحيات)، وowner لإدارة الفريق
        if (!empty($o['admin']) && ((!empty($o['aperm']) && !AdminAuth::can($o['aperm'])) || (!empty($o['owner']) && !AdminAuth::isOwner()))) {
            throw new HttpException(403);
        }

        // تطبيق الجوال: الدخول برمز التطبيق بدل الجلسة
        if (!empty($o['token'])) {
            Auth::actingAs(AppTokens::authenticate($request));
        }

        if ($o['guest'] && Auth::check()) {
            return redirect('/');
        }

        if ($o['auth']) {
            if (!Auth::check()) {
                if ($request->wantsJson()) {
                    throw new HttpException(401);
                }
                Session::set('_intended', $request->method === 'GET' ? $request->urlWith([]) : null);
                return redirect('/login');
            }
            // المالك يفرض التحقق بخطوتين: الموظف الذي لم يفعّله لا يصل إلا إلى صفحة حسابه لتفعيله
            if (\App\Services\TwoFactor::mustEnroll(Auth::user()) && !Session::get('impersonated_by')
                && !preg_match('#^/(account(/.*)?|logout)$#', $request->path)) {
                $message = 'صاحب الشبكة يطلب تفعيل التحقق بخطوتين لمتابعة العمل.';
                if ($request->wantsJson() || !empty($o['token'])) {
                    return Response::json(['ok' => false, 'message' => $message . ' فعّله من صفحة «حسابي» في المتصفح.', 'two_factor_required' => true], 403);
                }
                flash('error', $message);
                return redirect('/account');
            }
            if ($o['perm'] !== null) {
                $perms = (array) $o['perm'];
                $ok = false;
                foreach ($perms as $p) {
                    if (Auth::can($p)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    throw new HttpException(403);
                }
            }
        }

        if ($request->method === 'POST' && $o['csrf']) {
            $token = (string) ($request->post['_token'] ?? $request->header('X-CSRF-Token') ?? '');
            if (!Csrf::verify($token)) {
                throw new HttpException(419);
            }
        }

        [$class, $method] = $route['handler'];
        $controller = new $class();
        try {
            $result = $controller->$method($request, ...$params);
        } catch (\App\Controllers\ValidationFailed $e) {
            $errors = $e->validator->errors();
            if ($request->wantsJson()) {
                return Response::json(['ok' => false, 'message' => (string) reset($errors), 'errors' => $errors], 422);
            }
            Session::flashInput($request->all(), $errors);
            flash('error', (string) reset($errors));
            return back('/');
        }

        if ($result instanceof Response) {
            return !empty($o['token']) ? self::forApp($result) : $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return new Response((string) $result);
    }

    /**
     * إجراءات الموقع تعيد توجيهاً مع رسالة؛ للتطبيق تُحوَّل إلى JSON بالرسالة والمسار.
     */
    private static function forApp(Response $response): Response
    {
        if ($response->status < 300 || $response->status >= 400) {
            return $response;
        }
        $flashes = (array) ($_SESSION['_flash'] ?? []);
        $last = $flashes ? end($flashes) : null;
        $ok = !$last || ($last['type'] ?? '') !== 'error';
        $path = (string) parse_url((string) ($response->headers['Location'] ?? ''), PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        unset($_SESSION['_flash']);
        return Response::json([
            'ok'       => $ok,
            'message'  => (string) ($last['message'] ?? ($ok ? 'تم.' : 'تعذّر تنفيذ العملية.')),
            'redirect' => $path,
        ], $ok ? 200 : 422);
    }

    public static function cors(Response $response): Response
    {
        $response->headers['Access-Control-Allow-Origin'] = '*';
        $response->headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
        $response->headers['Access-Control-Allow-Headers'] = 'Content-Type';
        $response->headers['Access-Control-Max-Age'] = '600';
        return $response;
    }
}
