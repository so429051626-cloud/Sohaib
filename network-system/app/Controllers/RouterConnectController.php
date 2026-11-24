<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Tenant;
use App\Core\Throttle;
use App\Services\RouterConnect;
use App\Services\Tunnel;
use RuntimeException;
use Throwable;

/**
 * معالج «اربط جهازك بـ 3 خطوات»: الاسم وطريقة الاتصال، ثم أمر واحد في Terminal، ثم متابعة حية حتى يجهز الجهاز للكروت.
 */
final class RouterConnectController extends Controller
{
    private function router(int $id): array
    {
        $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        if (!$router) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        return $router;
    }

    /** الخطوة 1: اسم الجهاز وطريقة الاتصال */
    public function create(Request $request): Response
    {
        return view('routers/connect', [
            'tunnel' => RouterConnect::available(),
            'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'),
            'panelOk' => (bool) preg_match('#^https?://[A-Za-z0-9.\-:/_]+$#', Settings::panelUrl()),
            'first' => !DB::value('SELECT 1 FROM routers LIMIT 1'),
        ]);
    }

    public function store(Request $request): Response
    {
        $tunnel = RouterConnect::available();
        $method = $tunnel && $request->str('method', 'tunnel') !== 'public' ? 'tunnel' : 'public';
        $rules = ['name' => 'required|max:100', 'area_id' => 'nullable|int'];
        if ($method === 'public') {
            $rules += ['host' => 'required|host|max:255', 'api_port' => 'required|int|between:1,65535'];
        }
        $d = $this->validate($request, $rules, ['name' => 'اسم الجهاز', 'host' => 'العنوان العام', 'api_port' => 'منفذ API'], static function ($v) use ($request, $method) {
            if ($method === 'public' && Tunnel::foreign(trim($request->str('host')))) {
                $v->addError('host', 'هذا العنوان ليس من أنفاق حسابك.');
            }
        });
        if (Tenant::limitReached('max_routers', 'SELECT COUNT(*) FROM routers')) {
            return $this->backWithErrors($request, ['name' => 'بلغ الحساب الحد الأقصى لأجهزة MikroTik.'], '/routers/connect');
        }
        $areaId = !empty($d['area_id']) && DB::value('SELECT id FROM areas WHERE id = ?', [(int) $d['area_id']]) ? (int) $d['area_id'] : null;
        try {
            $id = RouterConnect::start(
                (string) $d['name'], $method,
                $method === 'public' ? trim((string) $d['host']) : null,
                $method === 'public' ? (int) $d['api_port'] : 8728,
                $areaId, Auth::user()['name'] ?? null
            );
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['name' => $e->getMessage()], '/routers/connect');
        }
        return redirect('/routers/connect/' . $id);
    }

    /** الخطوتان 2 و3: الأمر الواحد والمتابعة الحية */
    public function show(Request $request, int $id): Response
    {
        $router = $this->router($id);
        $state = RouterConnect::state($id);
        if ($state === null) {
            return redirect('/routers/' . $id);
        }
        $script = null;
        $scriptError = null;
        try {
            $script = RouterConnect::script($router);
        } catch (Throwable $e) {
            $scriptError = $e->getMessage();
        }
        return view('routers/connect-progress', [
            'router' => $router, 'state' => $state, 'command' => RouterConnect::command($id),
            'script' => $script, 'scriptError' => $scriptError, 'tunnel' => ($state['method'] ?? '') === 'tunnel',
        ]);
    }

    /** المتابعة الحية (تطلبها الصفحة كل بضع ثوانٍ) */
    public function status(Request $request, int $id): Response
    {
        $router = $this->router($id);
        if (RouterConnect::state($id) === null) {
            return json_response(['ok' => false, 'message' => 'لا يوجد ربط جارٍ لهذا الجهاز.'], 404);
        }
        Session::release();
        // طلب واحد في كل مرة لكل جهاز: تبويبان مفتوحان لا يتصلان بالجهاز معاً
        $lock = storage_path('locks/connect-' . (int) (Tenant::id() ?? 0) . '-' . $id . '.lock');
        if (!is_dir(dirname($lock))) {
            @mkdir(dirname($lock), 0775, true);
        }
        $fh = @fopen($lock, 'c');
        if ($fh) {
            flock($fh, LOCK_EX);
        }
        try {
            $progress = RouterConnect::progress($router);
        } catch (Throwable $e) {
            log_message('error', 'router connect ' . $id . ': ' . $e->getMessage());
            $progress = null;
        } finally {
            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        if ($progress === null) {
            return json_response(['ok' => false, 'message' => 'تعذّرت المتابعة الآن. تُعاد المحاولة تلقائياً.'], 500);
        }
        return json_response(['ok' => true] + $progress);
    }

    /** رابط جلب جديد (بعد انتهاء صلاحية السابق) */
    public function token(Request $request, int $id): Response
    {
        $router = $this->router($id);
        if (RouterConnect::state($id) === null) {
            return redirect('/routers/' . $id);
        }
        RouterConnect::newToken($id);
        Audit::log('router.connect', 'رابط ربط جديد لجهاز MikroTik ' . $router['name'], 'router', $id);
        return $this->ok('تم إنشاء أمر ربط جديد.', '/routers/connect/' . $id);
    }

    /** تنزيل السكربت الكامل (لمن يفضّل رفع الملف عبر Winbox) */
    public function download(Request $request, int $id): Response
    {
        $router = $this->router($id);
        if (RouterConnect::state($id) === null) {
            throw new HttpException(404);
        }
        try {
            $script = RouterConnect::script($router);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            return redirect('/routers/connect/' . $id);
        }
        Audit::log('router.connect', 'تنزيل سكربت ربط جهاز MikroTik ' . $router['name'], 'router', $id);
        return Response::download($script, RouterConnect::FILE, 'text/plain; charset=utf-8');
    }

    /** يجلبه جهاز MikroTik بأمر /tool fetch (عام، برمز سري صالح 24 ساعة) */
    public function fetch(Request $request, string $token): Response
    {
        if (!Throttle::rate('connect:fetch:' . $request->ip(), 30, 60)) {
            return Response::text("# too many requests\r\n", 429);
        }
        $router = RouterConnect::routerForToken($token);
        if (!$router) {
            return Response::text(":error \"MADIX NEXUS: this link is invalid or expired. Create a new command from the panel.\"\r\n", 404)
                ->withHeader('Cache-Control', 'no-store');
        }
        try {
            $script = RouterConnect::script($router);
        } catch (Throwable $e) {
            return Response::text(":error \"MADIX NEXUS: setup is not available for this device. Open the panel.\"\r\n", 409)
                ->withHeader('Cache-Control', 'no-store');
        }
        RouterConnect::markFetched((int) $router['id'], $request->ip(), $request->userAgent());
        return Response::text($script, 200, 'text/plain; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
