<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Throttle;
use App\Services\CronService;
use App\Services\HotspotSdk;
use App\Services\PortalService;
use App\Services\SessionTracker;

/**
 * الواجهات العامة: صفحة الهوتسبوت، أحداث جهاز MikroTik، المهمة المجدولة عبر الويب.
 */
final class ApiController extends Controller
{
    public function hotspotLogin(Request $request): Response
    {
        $result = PortalService::login(
            $request->str('key'),
            $request->str('code'),
            $request->str('password'),
            $request->str('mac'),
            $request->str('ip'),
            $request->str('raw_code') ?: null,
            $request->str('raw_password') ?: null
        );
        return json_response($result);
    }

    public function hotspotStatus(Request $request): Response
    {
        if (!Throttle::rate('hs:status:' . $request->ip(), 300, 60)) {
            return json_response(['ok' => false, 'message' => 'طلبات كثيرة.'], 429);
        }
        return json_response(PortalService::status($request->str('key'), $request->str('u'), $request->str('mac')));
    }

    /** إعلانات صفحة الهوتسبوت لنقطة الاتصال (عروض الشبكة وإعلانات المحلات) */
    public function hotspotAds(Request $request): Response
    {
        $router = PortalService::routerByKey($request->str('key'));
        if (!$router) {
            return json_response(['ok' => false, 'ads' => []], 404);
        }
        if (!Throttle::rate('hs:ads:' . $request->ip(), 120, 60)) {
            return json_response(['ok' => false, 'ads' => []], 429);
        }
        $ads = \App\Services\HotspotAds::forRouter($router);
        \App\Services\HotspotAds::recordViews($ads, (int) $router['id'], $request->ip() . '|' . (string) normalize_mac($request->str('mac')));
        return json_response([
            'ok' => true, 'interval' => \App\Services\HotspotAds::interval(),
            'ads' => array_map([\App\Services\HotspotAds::class, 'payload'], $ads),
        ])->withHeader('Cache-Control', 'no-store');
    }

    /** ضغطة على إعلان (تُرسلها المكتبة قبل فتح رابطه أو الاتصال برقمه) */
    public function hotspotAdClick(Request $request, int $id): Response
    {
        $router = PortalService::routerByKey($request->str('key'));
        if (!$router) {
            return json_response(['ok' => false], 404);
        }
        $ok = \App\Services\HotspotAds::recordClick($id, (int) $router['id'], $request->ip() . '|' . (string) normalize_mac($request->str('mac')));
        return json_response(['ok' => $ok]);
    }

    public function hotspotInfo(Request $request): Response
    {
        return json_response(PortalService::info($request->str('key')))->withHeader('Cache-Control', 'public, max-age=300');
    }

    /** حدث دخول/خروج من سكربت ملف المستخدم على جهاز MikroTik */
    public function routerEvent(Request $request): Response
    {
        $router = PortalService::routerByKey($request->str('k'));
        if (!$router) {
            return Response::text('unknown', 404);
        }
        $event = $request->str('e');
        $user = $request->str('u');
        if (!in_array($event, ['in', 'out'], true) || $user === '' || strlen($user) > 64) {
            return Response::text('bad', 400);
        }
        if (!Throttle::rate('ev:' . $router['id'], 3000, 60)) {
            return Response::text('rate', 429);
        }
        SessionTracker::event((int) $router['id'], $event, $user, normalize_mac($request->str('m')), is_valid_ip($request->str('a')) ? $request->str('a') : null);
        DB::update('routers', ['last_seen_at' => now()], 'id = ?', [$router['id']]);
        return Response::text('ok');
    }

    public function sdkConfig(Request $request, string $key): Response
    {
        $router = PortalService::routerByKey($key);
        if (!$router) {
            return Response::text('/* unknown router */', 404, 'application/javascript; charset=UTF-8');
        }
        return Response::text(HotspotSdk::configJs($router), 200, 'application/javascript; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function sdkFile(Request $request): Response
    {
        return Response::text((string) file_get_contents(HotspotSdk::sdkPath()), 200, 'application/javascript; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** تشغيل المهمة المجدولة عبر رابط (للاستضافات بلا Cron) */
    public function cron(Request $request): Response
    {
        $key = (string) Config::get('app.cron_key', '');
        if ($key === '' || !hash_equals($key, $request->str('key'))) {
            return Response::text('forbidden', 403);
        }
        $result = CronService::run(25.0, 'web');
        return json_response($result);
    }

    /** فحص الصحة لأدوات المراقبة: يعمل الموقع وقاعدة البيانات */
    public function health(Request $request): Response
    {
        try {
            DB::value('SELECT 1');
            return json_response(['ok' => true, 'time' => time()]);
        } catch (\Throwable) {
            return json_response(['ok' => false, 'time' => time()], 503);
        }
    }
}
