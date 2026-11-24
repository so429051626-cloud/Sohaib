<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use App\Services\Geo;
use App\Services\HotspotSdk;
use App\Services\JobRunner;
use App\Services\ProfileManager;
use App\Services\RouterImport;
use App\Services\RouterSync;
use App\Services\RouterTasks;
use Throwable;

final class RouterController extends Controller
{
    public const TABS = [
        'overview'   => ['نظرة عامة', 'gauge'],
        'active'     => ['المتصلون', 'activity'],
        'hosts'      => ['الأجهزة', 'smartphone'],
        'users'      => ['المستخدمون المحليون', 'users'],
        'ppp'        => ['PPP', 'cable'],
        'bindings'   => ['ربط الأجهزة', 'link'],
        'walled'     => ['Walled Garden', 'shield'],
        'interfaces' => ['المنافذ', 'ethernet-port'],
        'queues'     => ['السرعات', 'gauge'],
        'neighbors'  => ['الأجهزة المجاورة', 'radio-tower'],
        'logs'       => ['السجل', 'list'],
        'tools'      => ['الأدوات', 'wrench'],
        'files'      => ['الملفات والنسخ', 'archive'],
        'import'     => ['الاستيراد', 'download'],
        'setup'      => ['التجهيز', 'square-terminal'],
        'tasks'      => ['المهام المعلّقة', 'list-checks'],
    ];

    private function findRouter(int $id): array
    {
        $router = DB::row('SELECT r.*, a.name AS area_name, si.name AS site_name FROM routers r LEFT JOIN areas a ON a.id = r.area_id LEFT JOIN sites si ON si.id = r.site_id WHERE r.id = ?', [$id]);
        if (!$router) {
            throw new HttpException(404);
        }
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        return $router;
    }

    public function index(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('r.id');
        $where = '1 = 1' . $scope;
        if ($request->int('area') > 0) {
            $where .= ' AND r.area_id = ?';
            $params[] = $request->int('area');
        }
        if (in_array($request->str('status'), ['online', 'offline', 'unknown'], true)) {
            $where .= ' AND r.status = ?';
            $params[] = $request->str('status');
        }
        if (($q = $request->str('q')) !== '') {
            $where .= ' AND (r.name LIKE ? OR r.host LIKE ? OR r.identity LIKE ?)';
            array_push($params, DB::like($q), DB::like($q), DB::like($q));
        }
        $routers = DB::rows(
            "SELECT r.*, a.name AS area_name,
                    (SELECT COUNT(*) FROM router_tasks t WHERE t.router_id = r.id AND t.status = 'pending') AS pending_tasks
             FROM routers r LEFT JOIN areas a ON a.id = r.area_id WHERE $where ORDER BY r.name",
            $params
        );
        $areas = DB::pairs('SELECT id, name FROM areas ORDER BY name');
        $totals = [
            'all'     => count($routers),
            'online'  => count(array_filter($routers, static fn ($r) => $r['status'] === 'online')),
            'offline' => count(array_filter($routers, static fn ($r) => $r['status'] === 'offline')),
            'users'   => array_sum(array_map(static fn ($r) => (int) $r['active_hotspot'] + (int) $r['active_ppp'], $routers)),
        ];
        return view('routers/index', compact('routers', 'areas', 'totals'));
    }

    public function create(Request $request): Response
    {
        return view('routers/form', ['router' => null, 'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'), 'sites' => Geo::sitePairs()]);
    }

    public function edit(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        return view('routers/form', ['router' => $router, 'areas' => DB::pairs('SELECT id, name FROM areas ORDER BY name'), 'sites' => Geo::sitePairs()]);
    }

    private function validated(Request $request, ?array $router): array
    {
        [$siteId, $lat, $lng, $geoError] = Geo::fromRequest($request);
        return ['site_id' => $siteId, 'lat' => $lat, 'lng' => $lng] + $this->validate($request, [
            'name'          => 'required|max:100',
            'area_id'       => 'nullable|int',
            'host'          => 'required|host|max:255',
            'api_port'      => 'required|int|between:1,65535',
            'api_username'  => 'required|max:64',
            'api_password'  => ($router ? 'nullable' : 'required') . '|max:128',
            'wan_interface' => 'nullable|max:64',
            'notes'         => 'nullable|max:500',
        ], [
            'name' => 'اسم جهاز MikroTik', 'host' => 'العنوان', 'api_port' => 'منفذ API', 'api_username' => 'مستخدم API',
            'api_password' => 'كلمة مرور API', 'wan_interface' => 'واجهة الإنترنت', 'notes' => 'ملاحظات',
        ], static function ($v) use ($geoError, $request) {
            if ($geoError !== null) {
                $v->addError('lat', $geoError);
            }
            if (\App\Services\Tunnel::foreign(trim($request->str('host')))) {
                $v->addError('host', 'هذا العنوان ليس من أنفاق حسابك.');
            }
        });
    }

    public function store(Request $request): Response
    {
        $d = $this->validated($request, null);
        if (\App\Core\Tenant::limitReached('max_routers', 'SELECT COUNT(*) FROM routers')) {
            return $this->backWithErrors($request, ['name' => 'بلغ الحساب الحد الأقصى لأجهزة MikroTik.'], '/routers/create');
        }
        $id = DB::insert('routers', [
            'name'          => $d['name'],
            'area_id'       => $d['area_id'] ?: null,
            'site_id'       => $d['site_id'],
            'lat'           => $d['lat'],
            'lng'           => $d['lng'],
            'host'          => $d['host'],
            'api_port'      => $d['api_port'],
            'api_ssl'       => $request->bool('api_ssl') ? 1 : 0,
            'api_username'  => $d['api_username'],
            'api_password'  => Crypto::encrypt((string) $request->input('api_password')),
            'router_key'    => bin2hex(random_bytes(16)),
            'wan_interface' => $d['wan_interface'],
            'is_active'     => $request->bool('is_active') ? 1 : 0,
            'notes'         => $d['notes'],
            'created_at'    => now(),
        ]);
        Audit::log('router.create', 'إضافة جهاز MikroTik ' . $d['name'], 'router', $id);
        $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        Session::release();
        $r = RouterSync::run($router, true);
        flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'تمت إضافة جهاز MikroTik والاتصال به بنجاح.' : 'تمت إضافة جهاز MikroTik، لكن تعذّر الاتصال: ' . $r['error']);
        return redirect('/routers/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $d = $this->validated($request, $router);
        $update = [
            'name'          => $d['name'],
            'area_id'       => $d['area_id'] ?: null,
            'site_id'       => $d['site_id'],
            'lat'           => $d['lat'],
            'lng'           => $d['lng'],
            'host'          => $d['host'],
            'api_port'      => $d['api_port'],
            'api_ssl'       => $request->bool('api_ssl') ? 1 : 0,
            'api_username'  => $d['api_username'],
            'wan_interface' => $d['wan_interface'],
            'is_active'     => $request->bool('is_active') ? 1 : 0,
            'notes'         => $d['notes'],
            'next_poll_at'  => null,
            'updated_at'    => now(),
        ];
        if ((string) $request->input('api_password', '') !== '') {
            $update['api_password'] = Crypto::encrypt((string) $request->input('api_password'));
        }
        DB::update('routers', $update, 'id = ?', [$id]);
        Audit::log('router.update', 'تعديل جهاز MikroTik ' . $d['name'], 'router', $id);
        return $this->ok('تم حفظ إعدادات جهاز MikroTik.', '/routers/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        // معالج ربط لم يكتمل: يُلغى رمزه، ويُحذف نفقه الذي أنشأه المعالج ولم يُستخدم
        $connect = \App\Services\RouterConnect::state($id);
        DB::delete('routers', 'id = ?', [$id]);
        if ($connect !== null) {
            \App\Services\RouterConnect::forget($id);
            if (empty($connect['ready_at']) && !empty($connect['tunnel_id'])) {
                \App\Services\Tunnel::delete((int) $connect['tunnel_id']);
            }
        }
        Audit::log('router.delete', 'حذف جهاز MikroTik ' . $router['name'], 'router', $id);
        return $this->ok('تم حذف جهاز MikroTik من النظام.', '/routers');
    }

    /** اختبار الاتصال بالبيانات المدخلة قبل الحفظ */
    public function test(Request $request): Response
    {
        $host = $request->str('host');
        $port = $request->int('api_port', 8728);
        $user = $request->str('api_username');
        $pass = (string) $request->input('api_password', '');
        if ($pass === '' && $request->int('router_id') > 0) {
            $existing = $this->findRouter($request->int('router_id'));
            $pass = Crypto::decrypt((string) $existing['api_password']);
        }
        if ($host === '' || $user === '') {
            return json_response(['ok' => false, 'message' => 'أدخل العنوان ومستخدم API.'], 422);
        }
        if (\App\Services\Tunnel::foreign(trim($host))) {
            return json_response(['ok' => false, 'message' => 'هذا العنوان ليس من أنفاق حسابك.'], 422);
        }
        Session::release();
        try {
            $started = microtime(true);
            $gw = Gateway::probe($host, $port, $request->bool('api_ssl'), $user, $pass, 6.0);
            $res = $gw->resource();
            $identity = $gw->identity();
            $interfaces = array_map(static fn ($i) => ['name' => $i['name'], 'type' => $i['type'], 'running' => $i['running']], $gw->interfaces());
            $gw->close();
            return json_response([
                'ok' => true,
                'message' => 'تم الاتصال بنجاح.',
                'latency' => (int) round((microtime(true) - $started) * 1000),
                'identity' => $identity,
                'version' => $res['version'],
                'board' => $res['board_name'],
                'uptime' => fmt_duration($res['uptime_seconds']),
                'interfaces' => $interfaces,
            ]);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()], 422);
        }
    }

    public function show(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $tab = $request->str('tab', 'overview');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }
        $data = ['router' => $router, 'tab' => $tab, 'error' => null, 'rows' => []];
        if ($tab === 'overview') {
            $data['metrics'] = DB::rows(
                'SELECT created_at, cpu_load, memory_used_pct, active_hotspot, active_ppp, rx_bps, tx_bps, online FROM router_metrics
                 WHERE router_id = ? AND created_at >= ? ORDER BY created_at',
                [$id, date('Y-m-d H:i:s', time() - 86400)]
            );
            $data['stats'] = [
                'cards_present' => (int) DB::value("SELECT COUNT(*) FROM card_routers WHERE router_id = ? AND state = 'present'", [$id]),
                'subscribers'   => (int) DB::value('SELECT COUNT(*) FROM subscribers WHERE router_id = ?', [$id]),
                'sessions_today'=> (int) DB::value('SELECT COUNT(*) FROM sessions WHERE router_id = ? AND started_at >= ?', [$id, date('Y-m-d 00:00:00')]),
                'pending_tasks' => RouterTasks::pending($id),
            ];
        } elseif ($tab === 'setup') {
            $data['script'] = HotspotSdk::setupScript($router, Auth::can('routers.manage'));
        } elseif ($tab === 'tasks') {
            $data['rows'] = DB::rows('SELECT * FROM router_tasks WHERE router_id = ? ORDER BY id DESC LIMIT 200', [$id]);
        } else {
            Session::release();
            try {
                // بيانات الجهاز تحل محل القيم الافتراضية (rows الفارغة)؛ «+=» كان يُبقي الفارغة فتظهر التبويبات بلا بيانات
                $data = array_replace($data, $this->loadTab($router, $tab));
            } catch (MikroTikException $e) {
                $data['error'] = $e->friendly();
            }
        }
        return view('routers/show', $data);
    }

    private function loadTab(array $router, string $tab): array
    {
        $gw = Gateway::for($router);
        $out = [];
        switch ($tab) {
            case 'active':
                $hosts = [];
                foreach (DB::rows('SELECT mac, hostname FROM devices WHERE router_id = ?', [$router['id']]) as $d) {
                    $hosts[$d['mac']] = $d['hostname'];
                }
                foreach ($gw->leases() as $l) {
                    if ($l['mac'] && $l['host_name'] !== '') {
                        $hosts[$l['mac']] = $l['host_name'];
                    }
                }
                $out['rows'] = $gw->hotspotActive();
                $out['ppp'] = $gw->pppActive();
                $out['hostnames'] = $hosts;
                $owners = \App\Services\SessionTracker::owners(array_merge(array_column($out['rows'], 'user'), array_column($out['ppp'], 'name')));
                $out['owners'] = $owners;
                break;
            case 'hosts':
                $out['rows'] = $gw->hotspotHosts();
                $out['leases'] = $gw->leases();
                break;
            case 'users':
                $out['rows'] = $gw->hotspotUsers();
                $out['profiles'] = array_column($gw->hotspotUserProfiles(), 'name');
                break;
            case 'ppp':
                $out['rows'] = $gw->pppSecrets();
                $out['profiles'] = $gw->pppProfiles();
                break;
            case 'bindings':
                $out['rows'] = $gw->ipBindings();
                break;
            case 'walled':
                $out['rows'] = $gw->walledGarden();
                $out['ips'] = $gw->walledGardenIp();
                break;
            case 'interfaces':
                $out['rows'] = $gw->interfaces();
                $out['locked'] = Auth::can('routers.control') ? $gw->lockedInterfaces() : [];
                break;
            case 'queues':
                $out['rows'] = $gw->queues();
                break;
            case 'neighbors':
                $out['rows'] = $gw->neighbors();
                break;
            case 'logs':
                $out['rows'] = $gw->logs(300);
                break;
            case 'files':
                $out['rows'] = $gw->files();
                break;
            case 'import':
                $out['hsProfiles'] = RouterImport::hotspotProfiles($gw);
                $out['pppProfiles'] = RouterImport::pppProfiles($gw);
                $out['categories'] = DB::pairs('SELECT id, name FROM categories WHERE is_custom = 0 ORDER BY sort, name');
                $out['plans'] = DB::pairs('SELECT id, name FROM plans ORDER BY sort, name');
                break;
            case 'tools':
                $out['services'] = $gw->services();
                break;
        }
        $gw->close();
        return $out;
    }

    /** بيانات لحظية للرسم البياني */
    public function live(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        Session::release();
        try {
            $gw = Gateway::for($router, 4.0);
            $res = $gw->resource();
            $iface = $request->str('iface') ?: (string) $router['wan_interface'];
            $traffic = $iface !== '' ? $gw->traffic($iface) : ['rx' => 0, 'tx' => 0];
            $active = $gw->client()->count('/ip/hotspot/active');
            $gw->close();
            $memUsed = $res['memory_total'] ? 100 - round($res['memory_free'] / $res['memory_total'] * 100) : null;
            return json_response([
                'ok' => true, 'time' => date('H:i:s'), 'cpu' => $res['cpu_load'], 'memory' => $memUsed,
                'uptime' => fmt_duration($res['uptime_seconds']), 'rx' => $traffic['rx'], 'tx' => $traffic['tx'], 'active' => $active,
            ]);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()]);
        }
    }

    public function sync(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        Session::release();
        ProfileManager::forget($id);
        $r = RouterSync::run($router, true);
        if ($request->wantsJson()) {
            return json_response(['ok' => $r['ok'], 'message' => $r['ok'] ? 'تمت المزامنة.' : $r['error']]);
        }
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'تمت مزامنة جهاز MikroTik.' : $r['error']);
        return back('/routers/' . $id);
    }

    public function reboot(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        try {
            Gateway::for($router)->reboot();
            Audit::log('router.action', 'إعادة تشغيل جهاز MikroTik ' . $router['name'], 'router', $id);
            return $this->ok('تم إرسال أمر إعادة التشغيل.', '/routers/' . $id);
        } catch (MikroTikException $e) {
            flash('error', $e->friendly());
            return back('/routers/' . $id);
        }
    }

    public function disconnect(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $type = $request->str('type');
        $ref = $request->str('ref');
        try {
            $gw = Gateway::for($router);
            if ($type === 'ppp') {
                $gw->client()->remove('/ppp/active', $ref);
            } else {
                $gw->removeHotspotActive($ref);
            }
            Audit::log('session.disconnect', 'فصل جلسة على جهاز MikroTik ' . $router['name'] . ': ' . $request->str('user'), 'router', $id);
            return $request->wantsJson() ? json_response(['ok' => true, 'message' => 'تم الفصل.']) : $this->ok('تم فصل الجلسة.', '/routers/' . $id . '?tab=active');
        } catch (MikroTikException $e) {
            return $request->wantsJson() ? json_response(['ok' => false, 'message' => $e->friendly()], 422) : $this->backWithErrors($request, ['x' => $e->friendly()]);
        }
    }

    /** إجراءات أدوات جهاز MikroTik: المستخدمون المحليون، الربط، Walled Garden */
    public function action(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $op = $request->str('op');
        $tab = $request->str('tab', 'overview');
        try {
            $gw = Gateway::for($router);
            $c = $gw->client();
            switch ($op) {
                case 'binding_add':
                    $mac = normalize_mac($request->str('mac'));
                    $type = $request->str('type');
                    if (!$mac || !in_array($type, ['blocked', 'bypassed', 'regular'], true)) {
                        throw new \InvalidArgumentException('أدخل عنوان MAC صالحاً ونوع الربط.');
                    }
                    $gw->setBinding($mac, $type, $request->str('comment'));
                    if ($type === 'blocked') {
                        $gw->disconnectHotspotMac($mac);
                    }
                    $msg = 'تم حفظ ربط الجهاز.';
                    break;
                case 'binding_remove':
                    $c->remove('/ip/hotspot/ip-binding', $request->str('ref'));
                    $msg = 'تم حذف الربط.';
                    break;
                case 'walled_add':
                    $host = $request->str('host');
                    if ($host === '' || !preg_match('/^[A-Za-z0-9*.\-]+$/', $host)) {
                        throw new \InvalidArgumentException('أدخل نطاقاً صالحاً.');
                    }
                    $c->add('/ip/hotspot/walled-garden', ['dst-host' => $host, 'action' => 'allow', 'comment' => $request->str('comment')]);
                    $msg = 'تمت الإضافة.';
                    break;
                case 'walled_remove':
                    $c->remove($request->str('kind') === 'ip' ? '/ip/hotspot/walled-garden/ip' : '/ip/hotspot/walled-garden', $request->str('ref'));
                    $msg = 'تم الحذف.';
                    break;
                case 'user_add':
                    $name = $request->str('name');
                    if (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $name)) {
                        throw new \InvalidArgumentException('اسم المستخدم غير صالح.');
                    }
                    $props = ['name' => $name, 'password' => $request->str('password'), 'profile' => $request->str('profile') ?: 'default'];
                    if (($lu = $request->str('limit_uptime')) !== '') {
                        $props['limit-uptime'] = $lu;
                    }
                    if ($request->float('limit_gb') > 0) {
                        $props['limit-bytes-total'] = (string) (int) ($request->float('limit_gb') * 1073741824);
                    }
                    if (($m = normalize_mac($request->str('mac'))) !== null) {
                        $props['mac-address'] = $m;
                    }
                    $props['comment'] = $request->str('comment');
                    $c->add('/ip/hotspot/user', $props);
                    $msg = 'تمت إضافة المستخدم المحلي.';
                    break;
                case 'user_toggle':
                    $c->set('/ip/hotspot/user', $request->str('ref'), ['disabled' => $request->bool('disable') ? 'yes' : 'no']);
                    $msg = $request->bool('disable') ? 'تم إيقاف المستخدم.' : 'تم تفعيل المستخدم.';
                    break;
                case 'user_remove':
                    $c->remove('/ip/hotspot/user', $request->str('ref'));
                    $msg = 'تم حذف المستخدم.';
                    break;
                case 'user_reset':
                    $c->exec('/ip/hotspot/user/reset-counters', ['.id' => $request->str('ref')]);
                    $msg = 'تم تصفير العدّادات.';
                    break;
                case 'iface_toggle':
                    // تعطيل منفذ أو تشغيله: يُقرأ المنفذ من الجهاز نفسه، ولا يُعطَّل منفذ يقطع الإنترنت أو اتصال النظام بالجهاز
                    $ref = $request->str('ref');
                    $iface = null;
                    foreach ($gw->interfaces() as $i) {
                        if ($i['id'] === $ref) {
                            $iface = $i;
                        }
                    }
                    if ($iface === null) {
                        throw new \InvalidArgumentException('المنفذ غير موجود على الجهاز. حدّث الصفحة.');
                    }
                    $disable = $request->bool('disable');
                    if ($iface['dynamic']) {
                        throw new \InvalidArgumentException('هذه واجهة مؤقتة ينشئها الجهاز تلقائياً، فلا تُعطَّل.');
                    }
                    if ($disable) {
                        $locked = $gw->lockedInterfaces();
                        if (isset($locked[$iface['name']])) {
                            throw new \InvalidArgumentException($iface['name'] . ': ' . $locked[$iface['name']]);
                        }
                    }
                    if ($iface['disabled'] !== $disable) {
                        $gw->setInterfaceDisabled($iface['id'], $disable);
                    }
                    $msg = ($disable ? 'تم تعطيل المنفذ ' : 'تم تشغيل المنفذ ') . $iface['name'] . '.';
                    break;
                case 'ppp_toggle':
                    $c->set('/ppp/secret', $request->str('ref'), ['disabled' => $request->bool('disable') ? 'yes' : 'no']);
                    $msg = 'تم الحفظ.';
                    break;
                case 'file_remove':
                    $gw->removeFile($request->str('ref'));
                    $msg = 'تم حذف الملف.';
                    break;
                case 'deploy_sdk':
                    $r = HotspotSdk::deploy($router);
                    if (!$r['ok']) {
                        throw new \InvalidArgumentException($r['message']);
                    }
                    $msg = $r['message'];
                    break;
                default:
                    throw new HttpException(400);
            }
            Audit::log('router.action', $msg . ' (' . $router['name'] . ')', 'router', $id, ['op' => $op]);
            return $this->ok($msg, '/routers/' . $id, ['tab' => $tab]);
        } catch (MikroTikException $e) {
            flash('error', $e->friendly());
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        return redirect('/routers/' . $id, ['tab' => $tab]);
    }

    /** الفحص الذكي: قراءة حالة الجهاز وواجهاته وسجله ثم تشخيص بالعربية مع حل مقترح (لا يغيّر شيئاً على الجهاز) */
    public function diagnose(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        \App\Core\Session::release();
        $r = \App\Services\RouterDoctor::diagnose($router);
        if ($r['ok']) {
            Audit::log('router.diagnose', 'فحص ذكي لجهاز MikroTik ' . $router['name'] . ' — ' . $r['level_label'], 'router', $id);
        }
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    public function ping(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $address = $request->str('address');
        if ($address === '' || !preg_match('/^[A-Za-z0-9.:\-]+$/', $address)) {
            return json_response(['ok' => false, 'message' => 'أدخل عنواناً صالحاً.'], 422);
        }
        Session::release();
        try {
            $rows = Gateway::for($router, 5.0)->ping($address, max(1, min(10, $request->int('count', 4))));
            return json_response(['ok' => true, 'rows' => $rows]);
        } catch (MikroTikException $e) {
            return json_response(['ok' => false, 'message' => $e->friendly()], 422);
        }
    }

    public function backup(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $name = 'sn-' . date('Ymd-His');
        try {
            $gw = Gateway::for($router);
            if ($request->str('kind') === 'export') {
                $gw->export($name);
            } else {
                $gw->backup($name);
            }
            Audit::log('router.action', 'نسخة احتياطية على جهاز MikroTik ' . $router['name'], 'router', $id);
            return $this->ok('تم إنشاء النسخة على جهاز MikroTik: ' . $name, '/routers/' . $id, ['tab' => 'files']);
        } catch (MikroTikException $e) {
            flash('error', $e->friendly());
            return redirect('/routers/' . $id, ['tab' => 'files']);
        }
    }

    public function tasks(Request $request, int $id): Response
    {
        $this->findRouter($id);
        $op = $request->str('op');
        if ($op === 'retry') {
            DB::query("UPDATE router_tasks SET status = 'pending', run_after = ?, attempts = 0 WHERE router_id = ? AND status IN ('pending','failed')", [now(), $id]);
            DB::update('routers', ['next_poll_at' => null], 'id = ?', [$id]);
            $msg = 'ستُعاد محاولة تنفيذ المهام في المزامنة القادمة.';
        } elseif ($op === 'clear') {
            DB::query("DELETE FROM router_tasks WHERE router_id = ? AND status IN ('pending','failed')", [$id]);
            $msg = 'تم حذف المهام المعلّقة.';
        } else {
            throw new HttpException(400);
        }
        return $this->ok($msg, '/routers/' . $id, ['tab' => 'tasks']);
    }

    public function regenerateKey(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        DB::update('routers', ['router_key' => bin2hex(random_bytes(16))], 'id = ?', [$id]);
        ProfileManager::forget($id);
        Audit::log('router.update', 'تجديد مفتاح ربط جهاز MikroTik ' . $router['name'], 'router', $id);
        return $this->ok('تم تجديد المفتاح. أعد نشر ملفات الربط على جهاز MikroTik.', '/routers/' . $id, ['tab' => 'setup']);
    }

    /** بدء استيراد المستخدمين من جهاز MikroTik */
    public function import(Request $request, int $id): Response
    {
        $router = $this->findRouter($id);
        $kind = $request->str('kind');
        $map = [];
        foreach ($request->arr('map') as $profile => $target) {
            if ((int) $target > 0) {
                $map[(string) $profile] = (int) $target;
            }
        }
        if (!$map) {
            return $this->backWithErrors($request, ['map' => 'حدد باقة كروت أو باقة اشتراك لملف واحد على الأقل.'], '/routers/' . $id . '?tab=import');
        }
        if ($kind === 'ppp') {
            if (!can('subscribers.manage')) {
                throw new HttpException(403);
            }
            $job = JobRunner::create('import_ppp_secrets', 'استيراد مشتركي PPP من ' . $router['name'], [
                'router_id' => $id, 'map' => $map, 'retag' => $request->bool('retag'), 'expires_at' => $request->str('expires_at'),
            ], 0, Auth::id());
        } else {
            if (!can('cards.import')) {
                throw new HttpException(403);
            }
            $job = JobRunner::create('import_router_users', 'استيراد مستخدمي هوتسبوت من ' . $router['name'], [
                'router_id' => $id, 'map' => $map, 'retag' => $request->bool('retag'),
            ], 0, Auth::id());
        }
        return redirect('/jobs/' . $job);
    }
}
