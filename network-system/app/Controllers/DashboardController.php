<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\CronService;

final class DashboardController extends Controller
{
    private function scheduleCron(): void
    {
        if (CronService::isStale(150)) {
            Kernel::afterResponse(static fn () => CronService::run(20.0, 'auto'));
        }
    }

    public function index(Request $request): Response
    {
        // أول دخول بلا أي جهاز MikroTik: معالج «اربط جهازك بـ 3 خطوات» مرة واحدة، ثم بطاقة دائمة في اللوحة حتى يُربط جهاز
        $onboard = can('routers.manage') && !DB::value('SELECT 1 FROM routers LIMIT 1');
        if ($onboard && !is_prefetch() && !Settings::bool('onboarding.connect_seen')) {
            Settings::set('onboarding.connect_seen', '1');
            return redirect('/routers/connect');
        }
        $this->scheduleCron();
        [$rScope, $rParams] = Auth::routerScope('r.id');
        [$sScope, $sParams] = Auth::routerScope('s.router_id', true);
        $today = date('Y-m-d 00:00:00');
        $financial = can('reports.financial') || can('sales.view');

        $kpi = [];
        $sess = DB::row("SELECT COUNT(*) AS n, SUM(s.service = 'hotspot') AS hs, SUM(s.service = 'ppp') AS ppp FROM sessions s WHERE s.stopped_at IS NULL" . $sScope, $sParams);
        $kpi['online'] = (int) $sess['n'];
        $kpi['online_hs'] = (int) $sess['hs'];
        $kpi['online_ppp'] = (int) $sess['ppp'];
        $routerCounts = DB::row("SELECT COUNT(*) AS total, SUM(r.status = 'online') AS online, SUM(r.status = 'offline') AS offline FROM routers r WHERE r.is_active = 1" . $rScope, $rParams);
        $kpi['routers_total'] = (int) $routerCounts['total'];
        $kpi['routers_online'] = (int) $routerCounts['online'];
        $kpi['routers_offline'] = (int) $routerCounts['offline'];
        $cards = DB::pairs("SELECT status, COUNT(*) FROM cards WHERE is_disabled = 0 GROUP BY status");
        $kpi['cards_active'] = (int) ($cards['active'] ?? 0);
        $kpi['cards_available'] = (int) ($cards['new'] ?? 0);
        $kpi['cards_sold'] = (int) ($cards['sold'] ?? 0);
        $kpi['activated_today'] = (int) DB::value('SELECT COUNT(*) FROM cards WHERE first_login_at >= ?', [$today]);
        $subs = DB::row(
            "SELECT SUM(status = 'active' AND expires_at > ?) AS active, SUM(expires_at IS NOT NULL AND expires_at <= ?) AS expired,
                    SUM(status = 'active' AND expires_at BETWEEN ? AND ?) AS expiring, COUNT(*) AS total FROM subscribers",
            [now(), now(), now(), date('Y-m-d H:i:s', time() + max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400)]
        );
        $kpi['subs_active'] = (int) $subs['active'];
        $kpi['subs_expired'] = (int) $subs['expired'];
        $kpi['subs_expiring'] = (int) $subs['expiring'];
        $kpi['subs_total'] = (int) $subs['total'];
        if ($financial) {
            $sales = DB::row("SELECT COALESCE(SUM(total), 0) AS total, COALESCE(SUM(quantity), 0) AS qty FROM sales WHERE status = 'completed' AND created_at >= ?", [$today]);
            $subPay = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ?", [$today]);
            $distPay = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE created_at >= ?', [$today]);
            $kpi['sales_today'] = (float) $sales['total'];
            $kpi['sales_qty_today'] = (int) $sales['qty'];
            $kpi['collected_today'] = (float) DB::value("SELECT COALESCE(SUM(paid), 0) FROM sales WHERE status = 'completed' AND created_at >= ?", [$today]) + $subPay + $distPay;
            $kpi['debts'] = (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM distributors WHERE balance > 0') + (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM subscribers WHERE balance > 0');
        }

        // أجهزة MikroTik
        $routers = DB::rows(
            "SELECT r.id, r.name, r.status, r.cpu_load, r.memory_total, r.memory_free, r.active_hotspot, r.active_ppp, r.uptime_seconds, r.last_seen_at, r.rx_bps, r.tx_bps, r.wan_interface, a.name AS area_name
             FROM routers r LEFT JOIN areas a ON a.id = r.area_id WHERE r.is_active = 1" . $rScope . ' ORDER BY r.status = \'online\', r.name',
            $rParams
        );
        $kpi['rx'] = array_sum(array_column($routers, 'rx_bps'));
        $kpi['tx'] = array_sum(array_column($routers, 'tx_bps'));

        // المتصلون خلال 24 ساعة (مجموع كل أجهزة MikroTik لكل ساعة)
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

        // المبيعات والتفعيلات خلال 30 يوماً
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days"))] = ['sales' => 0.0, 'activations' => 0];
        }
        $from = array_key_first($days) . ' 00:00:00';
        foreach (DB::rows('SELECT DATE(first_login_at) AS d, COUNT(*) AS n FROM cards WHERE first_login_at >= ? GROUP BY DATE(first_login_at)', [$from]) as $r) {
            if (isset($days[$r['d']])) {
                $days[$r['d']]['activations'] = (int) $r['n'];
            }
        }
        if ($financial) {
            foreach (DB::rows("SELECT DATE(created_at) AS d, SUM(total) AS t FROM sales WHERE status = 'completed' AND created_at >= ? GROUP BY DATE(created_at)", [$from]) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']]['sales'] += (float) $r['t'];
                }
            }
            foreach (DB::rows("SELECT DATE(created_at) AS d, SUM(amount) AS t FROM subscriber_transactions WHERE type = 'renewal' AND created_at >= ? GROUP BY DATE(created_at)", [$from]) as $r) {
                if (isset($days[$r['d']])) {
                    $days[$r['d']]['sales'] += (float) $r['t'];
                }
            }
        }

        $stock = DB::rows(
            "SELECT c.id, c.name, c.color, c.price, c.low_stock,
                    SUM(k.status = 'new' AND k.is_disabled = 0) AS available, SUM(k.status = 'sold') AS sold,
                    SUM(k.status = 'active') AS active
             FROM categories c LEFT JOIN cards k ON k.category_id = c.id WHERE c.is_active = 1 AND c.is_custom = 0 GROUP BY c.id ORDER BY c.sort, c.price"
        );
        $recent = DB::rows(
            "SELECT l.*, r.name AS router_name FROM login_log l LEFT JOIN routers r ON r.id = l.router_id
             WHERE l.event IN ('check','login') ORDER BY l.id DESC LIMIT 8"
        );
        $topUsers = DB::rows(
            "SELECT s.id, s.username, s.card_id, s.subscriber_id, s.mac, s.bytes_in + s.bytes_out AS bytes, s.uptime, d.hostname, r.name AS router_name
             FROM sessions s LEFT JOIN devices d ON d.mac = s.mac LEFT JOIN routers r ON r.id = s.router_id
             WHERE s.stopped_at IS NULL" . $sScope . ' ORDER BY bytes DESC LIMIT 8',
            $sParams
        );
        $expiring = can('subscribers.view') ? DB::rows(
            "SELECT s.id, s.full_name, s.phone, s.expires_at, p.name AS plan_name FROM subscribers s JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND s.expires_at BETWEEN ? AND ? ORDER BY s.expires_at LIMIT 8",
            [date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() + 7 * 86400)]
        ) : [];
        $alerts = can('alerts.view') ? DB::rows('SELECT * FROM alerts ORDER BY id DESC LIMIT 6') : [];
        $cronStale = !CronService::managed() && CronService::isStale(300);
        $panelUrlMissing = trim((string) Settings::get('general.panel_url', '')) === '';

        return view('dashboard/index', compact('kpi', 'routers', 'online24', 'days', 'stock', 'recent', 'topUsers', 'expiring', 'alerts', 'financial', 'cronStale', 'panelUrlMissing', 'onboard'));
    }

    /** تحديث دوري للأرقام الحية */
    public function data(Request $request): Response
    {
        $this->scheduleCron();
        [$rScope, $rParams] = Auth::routerScope('r.id');
        [$sScope, $sParams] = Auth::routerScope('s.router_id', true);
        $sess = DB::row("SELECT COUNT(*) AS n, SUM(s.service = 'hotspot') AS hs, SUM(s.service = 'ppp') AS ppp FROM sessions s WHERE s.stopped_at IS NULL" . $sScope, $sParams);
        $routers = DB::rows('SELECT r.id, r.status, r.cpu_load, r.active_hotspot, r.active_ppp, r.rx_bps, r.tx_bps FROM routers r WHERE r.is_active = 1' . $rScope, $rParams);
        return json_response([
            'ok' => true,
            'online' => (int) $sess['n'],
            'online_hs' => (int) $sess['hs'],
            'online_ppp' => (int) $sess['ppp'],
            'routers_online' => count(array_filter($routers, static fn ($r) => $r['status'] === 'online')),
            'rx' => array_sum(array_column($routers, 'rx_bps')),
            'tx' => array_sum(array_column($routers, 'tx_bps')),
            'routers' => $routers,
            'time' => date('H:i:s'),
        ]);
    }
}
