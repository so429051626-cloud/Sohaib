<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppTokens;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Brand;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Theme;
use App\Core\Throttle;
use App\Services\TwoFactor;

/**
 * واجهة تطبيق الجوال (القراءة والدخول). الإجراءات تمر عبر متحكمات الموقع نفسها.
 */
final class MobileController extends Controller
{
    public const PER_PAGE = 30;

    // ------------------------------------------------------------------
    // أدوات مشتركة
    // ------------------------------------------------------------------

    public static function page(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    /** صفحة من النتائج بصيغة موحدة */
    public static function paged(array $items, int $total, int $page, int $per = self::PER_PAGE, array $extra = []): Response
    {
        return json_response(['ok' => true, 'items' => $items, 'page' => $page, 'total' => $total, 'has_more' => $page * $per < $total] + $extra);
    }

    public static function abs(Request $request, string $path): string
    {
        return $path === '' ? '' : $request->origin() . $path;
    }

    public static function i(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    public static function f(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    private function network(Request $request): array
    {
        $logo = Brand::networkLogo();
        $theme = Theme::current();
        $palette = Theme::PALETTES[$theme['palette']] ?? Theme::PALETTES['royal'];
        return [
            'name'     => Brand::networkName(),
            'logo'     => $logo !== '' ? self::abs($request, upload_url($logo)) : '',
            'currency' => (string) Settings::get('general.currency', '₪'),
            'phone'    => (string) Settings::get('general.phone', ''),
            'primary'  => $palette[1],
            'accent'   => $palette[2],
        ];
    }

    private function user(array $emp): array
    {
        return [
            'id'          => (int) $emp['id'],
            'name'        => (string) $emp['name'],
            'username'    => (string) $emp['username'],
            'is_owner'    => (bool) $emp['is_owner'],
            'permissions' => array_values(Auth::permissions()),
        ];
    }

    // ------------------------------------------------------------------
    // الدخول
    // ------------------------------------------------------------------

    public function info(Request $request): Response
    {
        return json_response([
            'ok'       => true,
            'platform' => Brand::onPlatform(),
            'tenant'   => \App\Core\Tenant::slug(),
            'api'      => 1,
            'version'  => APP_VERSION,
            'network'  => Brand::onPlatform() ? ['name' => Brand::NAME, 'logo' => '', 'currency' => '', 'phone' => '', 'primary' => '#2453d6', 'accent' => '#5383ff'] : $this->network($request),
        ]);
    }

    public function login(Request $request): Response
    {
        $username = trim(latin_digits($request->str('username')));
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            return json_response(['ok' => false, 'message' => 'أدخل اسم المستخدم وكلمة المرور.'], 422);
        }
        if (!Throttle::rate('app-login:' . $request->ip(), 30, 600)) {
            return json_response(['ok' => false, 'message' => 'محاولات كثيرة. حاول بعد قليل.'], 429);
        }
        // التحقق بخطوتين: الرمز في حقل code، أو ملتصقاً بآخر كلمة المرور للتطبيقات التي لا تعرض حقله
        $code = trim(latin_digits($request->str('code')));
        $probe = DB::row('SELECT password_hash, totp_enabled, totp_secret FROM employees WHERE username = ?', [$username]);
        if ($code === '' && TwoFactor::enabled($probe) && !password_verify($password, (string) $probe['password_hash'])
            && preg_match('/^(.+?)\s*([0-9٠-٩]{6})$/us', $password, $m)) {
            [$password, $code] = [$m[1], latin_digits($m[2])];
        }
        $r = Auth::verifyCredentials($username, $password, $request->ip());
        if (!$r['ok']) {
            return json_response(['ok' => false, 'message' => $r['message']], 422);
        }
        $emp = $r['employee'];
        if (TwoFactor::enabled($emp)) {
            if ($code === '') {
                return json_response(['ok' => false, 'two_factor' => true, 'message' => 'هذا الحساب محمي بالتحقق بخطوتين. اكتب رمز تطبيق المصادقة (6 أرقام) مباشرة بعد كلمة المرور.'], 422);
            }
            $t = TwoFactor::check($emp, $code);
            if (!$t['ok']) {
                return json_response(['ok' => false, 'two_factor' => true, 'message' => $t['message']], 422);
            }
        } elseif (TwoFactor::mustEnroll($emp)) {
            return json_response(['ok' => false, 'two_factor_required' => true, 'message' => 'صاحب الشبكة يطلب تفعيل التحقق بخطوتين. فعّله من صفحة «حسابي» في المتصفح ثم سجّل الدخول.'], 422);
        }
        $token = AppTokens::issue($emp, $request->str('device'), $request->str('platform', 'android'), $request->ip());
        DB::update('employees', ['last_login_at' => now(), 'last_login_ip' => $request->ip()], 'id = ?', [$emp['id']]);
        Auth::actingAs($emp);
        Audit::log('auth.login', 'تسجيل دخول من تطبيق الجوال', 'employee', (int) $emp['id'], ['device' => $request->str('device')], (int) $emp['id']);
        return json_response(['ok' => true, 'token' => $token, 'user' => $this->user($emp), 'network' => $this->network($request)]);
    }

    public function logout(Request $request): Response
    {
        AppTokens::revokeCurrent();
        return json_response(['ok' => true]);
    }

    public function me(Request $request): Response
    {
        $emp = Auth::user();
        return json_response([
            'ok'      => true,
            'user'    => $this->user($emp),
            'network' => $this->network($request),
            'alerts'  => can('alerts.view') ? (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0') : 0,
        ]);
    }

    public function devices(Request $request): Response
    {
        $current = (int) (AppTokens::current()['id'] ?? 0);
        $items = array_map(static fn ($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'platform' => $t['platform'], 'created_at' => $t['created_at'],
            'last_used_at' => $t['last_used_at'], 'last_ip' => $t['last_ip'], 'current' => (int) $t['id'] === $current,
        ], AppTokens::forEmployee((int) Auth::id()));
        return json_response(['ok' => true, 'items' => $items]);
    }

    public function deviceRevoke(Request $request, int $id): Response
    {
        $ok = AppTokens::revoke((int) Auth::id(), $id);
        return json_response(['ok' => $ok, 'message' => $ok ? 'تم إلغاء دخول الجهاز.' : 'الجهاز غير موجود.'], $ok ? 200 : 404);
    }

    // ------------------------------------------------------------------
    // الرئيسية
    // ------------------------------------------------------------------

    public function dashboard(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('r.id');
        [$sScope, $sParams] = Auth::routerScope('s.router_id', true);
        $today = date('Y-m-d 00:00:00');
        $now = now();
        $financial = can('reports.financial') || can('sales.view');

        $sess = DB::row("SELECT COUNT(*) AS n, SUM(s.service = 'hotspot') AS hs, SUM(s.service = 'ppp') AS ppp FROM sessions s WHERE s.stopped_at IS NULL" . $sScope, $sParams);
        $rc = DB::row("SELECT COUNT(*) AS total, SUM(r.status = 'online') AS online FROM routers r WHERE r.is_active = 1" . $rScope, $rParams);
        $cards = DB::pairs('SELECT status, COUNT(*) FROM cards WHERE is_disabled = 0 GROUP BY status');
        $soon = date('Y-m-d H:i:s', time() + max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400);
        $subs = DB::row(
            "SELECT SUM(status = 'active' AND expires_at > ?) AS active, SUM(status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?) AS expired,
                    SUM(status = 'active' AND expires_at > ? AND expires_at <= ?) AS expiring, COUNT(*) AS total FROM subscribers",
            [$now, $now, $now, $soon]
        );
        $kpi = [
            'online'          => (int) $sess['n'],
            'online_hotspot'  => (int) $sess['hs'],
            'online_ppp'      => (int) $sess['ppp'],
            'routers_total'   => (int) $rc['total'],
            'routers_online'  => (int) $rc['online'],
            'cards_available' => (int) ($cards['new'] ?? 0),
            'cards_active'    => (int) ($cards['active'] ?? 0),
            'cards_sold'      => (int) ($cards['sold'] ?? 0),
            'activated_today' => (int) DB::value('SELECT COUNT(*) FROM cards WHERE first_login_at >= ?', [$today]),
            'subs_total'      => (int) $subs['total'],
            'subs_active'     => (int) $subs['active'],
            'subs_expiring'   => (int) $subs['expiring'],
            'subs_expired'    => (int) $subs['expired'],
            'financial'       => $financial,
            'sales_today'     => 0.0,
            'collected_today' => 0.0,
            'debts'           => 0.0,
        ];
        $sales7 = [];
        if ($financial) {
            $kpi['sales_today'] = (float) DB::value("SELECT COALESCE(SUM(total), 0) FROM sales WHERE status = 'completed' AND created_at >= ?", [$today]);
            $kpi['collected_today'] = (float) DB::value("SELECT COALESCE(SUM(paid), 0) FROM sales WHERE status = 'completed' AND created_at >= ?", [$today])
                + (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ?", [$today])
                + (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE created_at >= ?', [$today]);
            $kpi['debts'] = (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM distributors WHERE balance > 0')
                + (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM subscribers WHERE balance > 0');
            $from = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $byDay = DB::pairs("SELECT DATE(created_at), COALESCE(SUM(total), 0) FROM sales WHERE status = 'completed' AND created_at >= ? GROUP BY DATE(created_at)", [$from]);
            $subDay = DB::pairs("SELECT DATE(created_at), COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ? GROUP BY DATE(created_at)", [$from]);
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $sales7[] = ['date' => $d, 'value' => round((float) ($byDay[$d] ?? 0) + (float) ($subDay[$d] ?? 0), 2)];
            }
        }

        [$mScope, $mParams] = Auth::routerScope('m.router_id');
        $online24 = DB::rows(
            "SELECT x.h, ROUND(SUM(x.users)) AS users FROM (
                SELECT m.router_id, DATE_FORMAT(m.created_at, '%Y-%m-%d %H:00') AS h,
                       AVG(COALESCE(m.active_hotspot, 0) + COALESCE(m.active_ppp, 0)) AS users
                FROM router_metrics m WHERE m.created_at >= ? AND m.online = 1" . $mScope . "
                GROUP BY m.router_id, h
             ) x GROUP BY x.h ORDER BY x.h",
            array_merge([date('Y-m-d H:i:s', time() - 86400)], $mParams)
        );

        $routers = DB::rows(
            "SELECT r.id, r.name, r.status, r.cpu_load, r.memory_total, r.memory_free, r.active_hotspot, r.active_ppp, r.last_seen_at
             FROM routers r WHERE r.is_active = 1" . $rScope . " ORDER BY r.status = 'online', r.name LIMIT 6",
            $rParams
        );
        $alerts = can('alerts.view') ? DB::rows('SELECT id, type, level, title, message, is_read, created_at FROM alerts ORDER BY id DESC LIMIT 5') : [];

        return json_response([
            'ok'       => true,
            'kpi'      => $kpi,
            'online24' => array_map(static fn ($r) => ['hour' => substr((string) $r['h'], 11, 2), 'value' => (int) $r['users']], $online24),
            'sales7'   => $sales7,
            'routers'  => array_map([self::class, 'routerRow'], $routers),
            'alerts'   => array_map([self::class, 'alertRow'], $alerts),
            'unread'   => can('alerts.view') ? (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0') : 0,
        ]);
    }

    // ------------------------------------------------------------------
    // أجهزة MikroTik والجلسات
    // ------------------------------------------------------------------

    public static function routerRow(array $r): array
    {
        $mem = !empty($r['memory_total']) ? (int) round(100 - ((float) $r['memory_free'] / (float) $r['memory_total'] * 100)) : null;
        return [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'status'      => (string) $r['status'],
            'cpu'         => self::i($r['cpu_load'] ?? null),
            'memory'      => $mem,
            'hotspot'     => (int) ($r['active_hotspot'] ?? 0),
            'ppp'         => (int) ($r['active_ppp'] ?? 0),
            'last_seen'   => $r['last_seen_at'] ?? null,
            'area'        => $r['area_name'] ?? null,
            'host'        => $r['host'] ?? null,
            'model'       => $r['board_name'] ?? null,
            'version'     => $r['version'] ?? null,
            'uptime'      => self::i($r['uptime_seconds'] ?? null),
            'rx'          => self::i($r['rx_bps'] ?? null),
            'tx'          => self::i($r['tx_bps'] ?? null),
            'last_error'  => $r['last_error'] ?? null,
        ];
    }

    public function routers(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('r.id');
        $rows = DB::rows(
            "SELECT r.*, a.name AS area_name FROM routers r LEFT JOIN areas a ON a.id = r.area_id
             WHERE r.is_active = 1" . $scope . " ORDER BY r.status = 'online' DESC, r.name",
            $params
        );
        return json_response(['ok' => true, 'items' => array_map([self::class, 'routerRow'], $rows)]);
    }

    public function router(Request $request, int $id): Response
    {
        if (!Auth::canRouter($id)) {
            throw new HttpException(403);
        }
        $r = DB::row('SELECT r.*, a.name AS area_name FROM routers r LEFT JOIN areas a ON a.id = r.area_id WHERE r.id = ?', [$id]);
        $this->notFoundUnless($r);
        $row = self::routerRow($r);
        $row += [
            'identity'     => $r['identity'],
            'serial'       => $r['serial_number'],
            'architecture' => $r['architecture'],
            'cpu_count'    => self::i($r['cpu_count']),
            'memory_total' => self::i($r['memory_total']),
            'hdd_total'    => self::i($r['hdd_total']),
            'hdd_free'     => self::i($r['hdd_free']),
            'temperature'  => self::f($r['temperature']),
            'voltage'      => self::f($r['voltage']),
            'wan'          => $r['wan_interface'],
            'status_since' => $r['status_changed_at'],
        ];
        $sessions = DB::rows(
            'SELECT s.*, d.hostname, sub.full_name FROM sessions s LEFT JOIN devices d ON d.mac = s.mac LEFT JOIN subscribers sub ON sub.id = s.subscriber_id
             WHERE s.router_id = ? AND s.stopped_at IS NULL ORDER BY s.started_at DESC LIMIT 200',
            [$id]
        );
        $metrics = DB::rows(
            'SELECT created_at, cpu_load, COALESCE(active_hotspot, 0) + COALESCE(active_ppp, 0) AS users FROM router_metrics
             WHERE router_id = ? AND created_at >= ? ORDER BY created_at',
            [$id, date('Y-m-d H:i:s', time() - 86400)]
        );
        $step = max(1, (int) ceil(count($metrics) / 48));
        $points = [];
        foreach ($metrics as $i => $m) {
            if ($i % $step === 0) {
                $points[] = ['time' => substr((string) $m['created_at'], 11, 5), 'cpu' => (int) $m['cpu_load'], 'users' => (int) $m['users']];
            }
        }
        return json_response([
            'ok'       => true,
            'router'   => $row,
            'sessions' => array_map([self::class, 'sessionRow'], $sessions),
            'metrics'  => $points,
            'can'      => [
                'reboot'     => can('routers.reboot'),
                'disconnect' => can('sessions.disconnect'),
            ],
        ]);
    }

    public static function sessionRow(array $s): array
    {
        $uptime = (int) ($s['uptime'] ?? 0);
        if ($s['stopped_at'] === null && !empty($s['started_at'])) {
            $uptime = max($uptime, time() - strtotime((string) $s['started_at']));
        }
        return [
            'id'         => (int) $s['id'],
            'service'    => (string) $s['service'],
            'username'   => (string) $s['username'],
            'owner'      => $s['full_name'] ?? null,
            'card_id'    => self::i($s['card_id'] ?? null),
            'sub_id'     => self::i($s['subscriber_id'] ?? null),
            'category'   => $s['category_name'] ?? null,
            'router'     => $s['router_name'] ?? null,
            'router_id'  => self::i($s['router_id'] ?? null),
            'mac'        => $s['mac'] ?? null,
            'ip'         => $s['ip'] ?? ($s['address'] ?? null),
            'device'     => $s['hostname'] ?? null,
            'started_at' => $s['started_at'] ?? null,
            'uptime'     => $uptime,
            'bytes_in'   => (int) ($s['bytes_in'] ?? 0),
            'bytes_out'  => (int) ($s['bytes_out'] ?? 0),
        ];
    }

    public function sessions(Request $request): Response
    {
        [$scope, $params] = Auth::routerScope('s.router_id', true);
        $where = 's.stopped_at IS NULL' . $scope;
        $q = trim(latin_digits($request->str('q')));
        if ($q !== '') {
            $where .= ' AND (s.username LIKE ? OR d.hostname LIKE ? OR sub.full_name LIKE ? OR s.mac = ? OR s.ip = ?)';
            array_push($params, DB::like($q), DB::like($q), DB::like($q), normalize_mac($q) ?? $q, $q);
        }
        if (in_array($request->str('service'), ['hotspot', 'ppp'], true)) {
            $where .= ' AND s.service = ?';
            $params[] = $request->str('service');
        }
        if ($request->int('router') > 0) {
            $where .= ' AND s.router_id = ?';
            $params[] = $request->int('router');
        }
        $from = 'FROM sessions s LEFT JOIN devices d ON d.mac = s.mac LEFT JOIN routers r ON r.id = s.router_id
                 LEFT JOIN cards c ON c.id = s.card_id LEFT JOIN categories cat ON cat.id = c.category_id
                 LEFT JOIN subscribers sub ON sub.id = s.subscriber_id WHERE ' . $where;
        $total = (int) DB::value("SELECT COUNT(*) $from", $params);
        $page = self::page($request);
        $rows = DB::rows(
            "SELECT s.*, d.hostname, r.name AS router_name, cat.name AS category_name, sub.full_name $from
             ORDER BY s.started_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $sum = DB::row("SELECT SUM(s.service = 'hotspot') AS hs, SUM(s.service = 'ppp') AS ppp $from", $params);
        return self::paged(array_map([self::class, 'sessionRow'], $rows), $total, $page, self::PER_PAGE, [
            'hotspot' => (int) $sum['hs'], 'ppp' => (int) $sum['ppp'],
        ]);
    }

    // ------------------------------------------------------------------
    // التنبيهات
    // ------------------------------------------------------------------

    public static function alertRow(array $a): array
    {
        return [
            'id'         => (int) $a['id'],
            'type'       => (string) $a['type'],
            'level'      => (string) $a['level'],
            'title'      => (string) $a['title'],
            'message'    => $a['message'],
            'read'       => (bool) $a['is_read'],
            'created_at' => $a['created_at'],
        ];
    }

    public function alerts(Request $request): Response
    {
        $where = '1 = 1';
        $params = [];
        if ($request->bool('unread')) {
            $where .= ' AND is_read = 0';
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM alerts WHERE $where", $params);
        $page = self::page($request);
        $rows = DB::rows("SELECT * FROM alerts WHERE $where ORDER BY id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return self::paged(array_map([self::class, 'alertRow'], $rows), $total, $page, self::PER_PAGE, [
            'unread' => (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0'),
        ]);
    }

    // ------------------------------------------------------------------
    // التقارير
    // ------------------------------------------------------------------

    public function report(Request $request): Response
    {
        $period = $request->str('period', 'month');
        [$from, $label] = match ($period) {
            'today' => [date('Y-m-d 00:00:00'), 'اليوم'],
            'week'  => [date('Y-m-d 00:00:00', strtotime('-6 days')), 'آخر 7 أيام'],
            'year'  => [date('Y-01-01 00:00:00'), 'هذه السنة'],
            default => [date('Y-m-01 00:00:00'), 'هذا الشهر'],
        };
        $financial = can('reports.financial') || can('sales.view');
        $out = ['ok' => true, 'period' => $period, 'label' => $label, 'from' => $from, 'financial' => $financial];

        $out['cards_activated'] = (int) DB::value('SELECT COUNT(*) FROM cards WHERE first_login_at >= ?', [$from]);
        $out['cards_generated'] = (int) DB::value('SELECT COUNT(*) FROM cards WHERE created_at >= ?', [$from]);
        $out['subs_new'] = (int) DB::value('SELECT COUNT(*) FROM subscribers WHERE created_at >= ?', [$from]);
        $out['subs_renewed'] = (int) DB::value("SELECT COUNT(*) FROM subscriber_transactions WHERE type = 'renewal' AND created_at >= ?", [$from]);
        $out['sessions'] = (int) DB::value('SELECT COUNT(*) FROM sessions WHERE started_at >= ?', [$from]);
        $out['traffic'] = (int) DB::value('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM sessions WHERE started_at >= ?', [$from]);

        $byCat = DB::rows(
            'SELECT cat.name, cat.color, COUNT(*) AS n FROM cards c JOIN categories cat ON cat.id = c.category_id
             WHERE c.first_login_at >= ? GROUP BY cat.id ORDER BY n DESC LIMIT 8',
            [$from]
        );
        $out['by_category'] = array_map(static fn ($r) => ['name' => $r['name'], 'color' => $r['color'], 'value' => (int) $r['n']], $byCat);

        if ($financial) {
            $s = DB::row("SELECT COALESCE(SUM(total), 0) AS total, COALESCE(SUM(paid), 0) AS paid, COALESCE(SUM(quantity), 0) AS qty, COUNT(*) AS n FROM sales WHERE status = 'completed' AND created_at >= ?", [$from]);
            $subPay = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ?", [$from]);
            $distPay = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE created_at >= ?', [$from]);
            $out['sales_total'] = round((float) $s['total'], 2);
            $out['sales_count'] = (int) $s['n'];
            $out['sales_qty'] = (int) $s['qty'];
            $out['collected'] = round((float) $s['paid'] + $subPay + $distPay, 2);
            $out['sub_payments'] = round($subPay, 2);
            $group = $period === 'year' ? '%Y-%m' : '%Y-%m-%d';
            $series = DB::pairs("SELECT DATE_FORMAT(created_at, '$group') AS k, COALESCE(SUM(total), 0) FROM sales WHERE status = 'completed' AND created_at >= ? GROUP BY k ORDER BY k", [$from]);
            $subSeries = DB::pairs("SELECT DATE_FORMAT(created_at, '$group') AS k, COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ? GROUP BY k ORDER BY k", [$from]);
            $keys = [];
            if ($period === 'year') {
                for ($m = 1; $m <= (int) date('n'); $m++) {
                    $keys[] = date('Y') . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
                }
            } else {
                for ($t = strtotime(substr($from, 0, 10)); $t <= time(); $t += 86400) {
                    $keys[] = date('Y-m-d', $t);
                }
            }
            $out['series'] = array_map(static fn ($k) => ['key' => $k, 'value' => round((float) ($series[$k] ?? 0) + (float) ($subSeries[$k] ?? 0), 2)], $keys);
            $top = DB::rows(
                "SELECT cat.name, COALESCE(SUM(s.total), 0) AS total, COALESCE(SUM(s.quantity), 0) AS qty FROM sales s JOIN categories cat ON cat.id = s.category_id
                 WHERE s.status = 'completed' AND s.created_at >= ? GROUP BY cat.id ORDER BY total DESC LIMIT 6",
                [$from]
            );
            $out['top_categories'] = array_map(static fn ($r) => ['name' => $r['name'], 'total' => round((float) $r['total'], 2), 'qty' => (int) $r['qty']], $top);
        }
        return json_response($out);
    }
}
