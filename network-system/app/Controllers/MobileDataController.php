<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\CardGenerator;
use App\Services\CardQuery;
use App\Services\CardRules;
use App\Services\Inventory;
use App\Services\LoginRegistry;
use App\Services\ShortCodes;
use App\Services\SmsService;
use App\Services\SubscriberRules;
use App\Services\SubscriberService;
use App\Services\TweetSms;

/**
 * واجهة تطبيق الجوال لبيانات الكروت والمشتركين والمبيعات والرسائل والمخزون.
 */
final class MobileDataController extends Controller
{
    private const PER = MobileController::PER_PAGE;

    private static function offset(int $page): string
    {
        return ' LIMIT ' . self::PER . ' OFFSET ' . (($page - 1) * self::PER);
    }

    // ------------------------------------------------------------------
    // الكروت
    // ------------------------------------------------------------------

    public static function cardRow(array $c): array
    {
        return [
            'id'           => (int) $c['id'],
            'username'     => (string) $c['username'],
            'password'     => can('cards.passwords') ? (string) $c['password'] : null,
            'short_code'   => $c['short_code'],
            'status'       => (string) $c['status'],
            'disabled'     => (bool) $c['is_disabled'],
            'category'     => $c['category_name'] ?? null,
            'color'        => $c['category_color'] ?? null,
            'category_id'  => (int) $c['category_id'],
            'batch'        => $c['batch_name'] ?? null,
            'batch_id'     => (int) $c['batch_id'],
            'distributor'  => $c['distributor_name'] ?? null,
            'printed'      => $c['printed_at'] !== null,
            'first_login'  => $c['first_login_at'],
            'last_login'   => $c['last_login_at'],
            'expires_at'   => $c['expires_at'],
            'used_seconds' => (int) $c['used_seconds'],
            'used_bytes'   => (int) $c['used_bytes_in'] + (int) $c['used_bytes_out'],
            'created_at'   => $c['created_at'],
        ];
    }

    public function cards(Request $request): Response
    {
        $f = [];
        foreach (CardQuery::FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        [$where, $params] = CardQuery::fromArray($f);
        $total = (int) DB::value("SELECT COUNT(*) FROM cards c WHERE $where", $params);
        $page = MobileController::page($request);
        $rows = DB::rows(
            "SELECT c.*, cat.name AS category_name, cat.color AS category_color, b.name AS batch_name, d.name AS distributor_name
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
             LEFT JOIN distributors d ON d.id = c.distributor_id
             WHERE $where ORDER BY c.id DESC" . self::offset($page),
            $params
        );
        $counts = DB::pairs("SELECT c.status, COUNT(*) FROM cards c WHERE $where GROUP BY c.status", $params);
        return MobileController::paged(array_map([self::class, 'cardRow'], $rows), $total, $page, self::PER, [
            'counts' => (object) array_map('intval', $counts),
        ]);
    }

    public function card(Request $request, int $id): Response
    {
        $c = DB::row(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.price AS category_price, b.name AS batch_name,
                    d.name AS distributor_name, r.name AS last_router_name, dev.hostname AS last_hostname
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
             LEFT JOIN distributors d ON d.id = c.distributor_id LEFT JOIN routers r ON r.id = c.last_router_id
             LEFT JOIN devices dev ON dev.mac = c.last_mac WHERE c.id = ?',
            [$id]
        );
        $this->notFoundUnless($c);
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$c['category_id']]);
        [$timeLimit, $dataLimit, $validity] = CardRules::limits($c, $cat);
        $rem = CardRules::remaining($c, $cat);
        $sessions = DB::rows(
            'SELECT s.*, r.name AS router_name, d.hostname FROM sessions s LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN devices d ON d.mac = s.mac
             WHERE s.card_id = ? ORDER BY s.started_at DESC LIMIT 30',
            [$id]
        );
        $row = self::cardRow($c) + [
            'price'        => (float) $c['category_price'],
            'sale_price'   => MobileController::f($c['sale_price']),
            'sold_at'      => $c['sold_at'],
            'bound_mac'    => $c['bound_mac'],
            'last_mac'     => $c['last_mac'],
            'last_ip'      => $c['last_ip'],
            'last_device'  => $c['last_hostname'],
            'last_router'  => $c['last_router_name'],
            'notes'        => $c['notes'],
            'time_limit'   => $timeLimit,
            'data_limit'   => $dataLimit,
            'validity'     => $validity,
            'time_left'    => $rem['time'],
            'data_left'    => $rem['data'],
            'until_expiry' => $rem['until_expiry'],
            'block_reason' => ($reason = CardRules::blockReason($c, $cat)) !== null ? CardRules::message($reason) : null,
            'online'       => (int) DB::value('SELECT COUNT(*) FROM sessions WHERE card_id = ? AND stopped_at IS NULL', [$id]),
        ];
        return json_response([
            'ok'       => true,
            'card'     => $row,
            'sessions' => array_map([MobileController::class, 'sessionRow'], $sessions),
            'can'      => [
                'actions'  => can('check.actions'),
                'password' => can('check.password'),
                'delete'   => can('cards.delete'),
            ],
        ]);
    }

    /** البحث السريع: رقم كرت أو مشترك */
    public function lookup(Request $request): Response
    {
        $q = trim(latin_digits($request->str('q')));
        if ($q === '') {
            return json_response(['ok' => true, 'items' => []]);
        }
        $owner = LoginRegistry::owner($q);
        if ($owner) {
            return json_response(['ok' => true, 'match' => ['type' => $owner[0] === 'subscriber' ? 'subscriber' : 'card', 'id' => (int) $owner[1]], 'items' => []]);
        }
        $params = [$q, DB::like($q)];
        $sql = 'SELECT c.*, cat.name AS category_name, cat.color AS category_color FROM cards c JOIN categories cat ON cat.id = c.category_id
                WHERE c.ext_serial = ? OR c.username LIKE ?';
        if (($mac = normalize_mac($q)) !== null) {
            $sql .= ' OR c.bound_mac = ? OR c.last_mac = ?';
            array_push($params, $mac, $mac);
        }
        $rows = DB::rows($sql . ' ORDER BY c.last_login_at DESC, c.id DESC LIMIT 30', $params);
        foreach ($rows as &$r) {
            $r['batch_name'] = null;
        }
        unset($r);
        return json_response(['ok' => true, 'items' => array_map([self::class, 'cardRow'], $rows)]);
    }

    public function categories(Request $request): Response
    {
        $rows = DB::rows(
            "SELECT cat.*, (SELECT COUNT(*) FROM cards c WHERE c.category_id = cat.id AND c.status = 'new' AND c.is_disabled = 0) AS available
             FROM categories cat WHERE cat.is_custom = 0 ORDER BY cat.sort, cat.price"
        );
        return json_response(['ok' => true, 'items' => array_map(static fn ($c) => [
            'id'         => (int) $c['id'],
            'name'       => (string) $c['name'],
            'price'      => (float) $c['price'],
            'color'      => $c['color'],
            'time_limit' => MobileController::i($c['time_limit']),
            'validity'   => MobileController::i($c['validity']),
            'data_limit' => MobileController::i($c['data_limit']),
            'rate'       => trim(($c['rate_down'] ?: '') . ($c['rate_up'] ? ' / ' . $c['rate_up'] : '')),
            'active'     => (bool) $c['is_active'],
            'available'  => (int) $c['available'],
        ], $rows)]);
    }

    public function generateOptions(Request $request): Response
    {
        $charsets = [];
        foreach (CardGenerator::CHARSETS as $k => $c) {
            $charsets[] = ['key' => $k, 'label' => $c['label']];
        }
        return json_response([
            'ok'        => true,
            'charsets'  => $charsets,
            'max'       => CardGenerator::MAX_QUANTITY,
            'short_min' => ShortCodes::MIN_LENGTH,
            'short_max' => ShortCodes::MAX_LENGTH,
            'defaults'  => [
                'username_length' => (int) Settings::get('cards.username_length', 8),
                'charset'         => (string) Settings::get('cards.charset', 'digits'),
                'password_mode'   => (string) Settings::get('cards.password_mode', 'same'),
                'password_length' => (int) Settings::get('cards.password_length', 4),
            ],
        ]);
    }

    public function batches(Request $request): Response
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM batches');
        $page = MobileController::page($request);
        $rows = DB::rows(
            "SELECT b.*, cat.name AS category_name, cat.color AS category_color, e.name AS employee_name,
                    (SELECT COUNT(*) FROM cards c WHERE c.batch_id = b.id) AS cards,
                    (SELECT COUNT(*) FROM cards c WHERE c.batch_id = b.id AND c.status = 'new') AS available
             FROM batches b JOIN categories cat ON cat.id = b.category_id LEFT JOIN employees e ON e.id = b.created_by
             ORDER BY b.id DESC" . self::offset($page)
        );
        return MobileController::paged(array_map(static fn ($b) => [
            'id' => (int) $b['id'], 'name' => (string) $b['name'], 'category' => $b['category_name'], 'color' => $b['category_color'],
            'source' => $b['source'], 'cards' => (int) $b['cards'], 'available' => (int) $b['available'],
            'employee' => $b['employee_name'], 'created_at' => $b['created_at'],
        ], $rows), $total, $page);
    }

    // ------------------------------------------------------------------
    // المشتركون
    // ------------------------------------------------------------------

    public static function subRow(array $s): array
    {
        $now = time();
        $exp = $s['expires_at'] ? strtotime((string) $s['expires_at']) : null;
        $state = $s['status'] === 'suspended' ? 'suspended' : ($exp === null ? 'new' : ($exp <= $now ? 'expired' : 'active'));
        return [
            'id'         => (int) $s['id'],
            'name'       => (string) $s['full_name'],
            'phone'      => $s['phone'],
            'username'   => (string) $s['username'],
            'plan'       => $s['plan_name'] ?? null,
            'plan_id'    => (int) $s['plan_id'],
            'service'    => $s['service'] ?? null,
            'area'       => $s['area_name'] ?? null,
            'router'     => $s['router_name'] ?? null,
            'status'     => (string) $s['status'],
            'state'      => $state,
            'expires_at' => $s['expires_at'],
            'days_left'  => $exp !== null ? (int) floor(($exp - $now) / 86400) : null,
            'balance'    => (float) $s['balance'],
            'online'     => (bool) ($s['online'] ?? false),
        ];
    }

    public function subscribers(Request $request): Response
    {
        $f = [];
        foreach (SubscriberController::FILTERS as $k) {
            $f[$k] = $request->str($k);
        }
        [$where, $params] = SubscriberController::listWhere($f);
        $total = (int) DB::value("SELECT COUNT(*) FROM subscribers s WHERE $where", $params);
        $page = MobileController::page($request);
        $rows = DB::rows(
            "SELECT s.*, p.name AS plan_name, p.service, a.name AS area_name, r.name AS router_name,
                    (SELECT COUNT(*) FROM sessions x WHERE x.subscriber_id = s.id AND x.stopped_at IS NULL) AS online
             FROM subscribers s JOIN plans p ON p.id = s.plan_id LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN routers r ON r.id = s.router_id
             WHERE $where ORDER BY s.id DESC" . self::offset($page),
            $params
        );
        [$baseWhere, $baseParams] = SubscriberController::listWhere($f, false);
        $counts = [];
        foreach (array_keys(SubscriberController::STATES) as $state) {
            [$st, $sp] = SubscriberController::stateSql((string) $state, now());
            $counts[$state === '' ? 'all' : $state] = (int) DB::value("SELECT COUNT(*) FROM subscribers s WHERE $baseWhere$st", array_merge($baseParams, $sp));
        }
        return MobileController::paged(array_map([self::class, 'subRow'], $rows), $total, $page, self::PER, ['counts' => $counts]);
    }

    public function subscriber(Request $request, int $id): Response
    {
        $s = DB::row(
            'SELECT s.*, p.name AS plan_name, p.service, p.price AS plan_price, a.name AS area_name, r.name AS router_name,
                    lr.name AS last_router_name, d.hostname AS last_hostname
             FROM subscribers s JOIN plans p ON p.id = s.plan_id LEFT JOIN areas a ON a.id = s.area_id LEFT JOIN routers r ON r.id = s.router_id
             LEFT JOIN routers lr ON lr.id = s.last_router_id LEFT JOIN devices d ON d.mac = s.last_mac WHERE s.id = ?',
            [$id]
        );
        $this->notFoundUnless($s);
        if ($s['router_id'] && !Auth::canRouter((int) $s['router_id'])) {
            throw new HttpException(403);
        }
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$s['plan_id']]);
        $s['online'] = (int) DB::value('SELECT COUNT(*) FROM sessions WHERE subscriber_id = ? AND stopped_at IS NULL', [$id]);
        $row = self::subRow($s) + [
            'phone2'       => $s['phone2'],
            'national_id'  => $s['national_id'],
            'address'      => $s['address'],
            'password'     => can('subscribers.manage') ? $s['password'] : null,
            'plan_price'   => (float) $s['plan_price'],
            'static_ip'    => $s['static_ip'],
            'lock_mac'     => (bool) $s['lock_mac'],
            'bound_mac'    => $s['bound_mac'],
            'last_mac'     => $s['last_mac'],
            'last_ip'      => $s['last_ip'],
            'last_device'  => $s['last_hostname'],
            'last_online'  => $s['last_online_at'],
            'area_id'      => MobileController::i($s['area_id']),
            'router_id'    => MobileController::i($s['router_id']),
            'notes'        => $s['notes'],
            'created_at'   => $s['created_at'],
            'block_reason' => ($reason = SubscriberRules::blockReason($s, $plan)) !== null ? CardRules::message($reason) : null,
        ];
        $tx = DB::rows(
            'SELECT t.*, p.name AS plan_name, e.name AS employee_name FROM subscriber_transactions t
             LEFT JOIN plans p ON p.id = t.plan_id LEFT JOIN employees e ON e.id = t.employee_id
             WHERE t.subscriber_id = ? ORDER BY t.created_at DESC, t.id DESC LIMIT 50',
            [$id]
        );
        $sessions = DB::rows(
            'SELECT x.*, r.name AS router_name, d.hostname FROM sessions x LEFT JOIN routers r ON r.id = x.router_id
             LEFT JOIN devices d ON d.mac = x.mac WHERE x.subscriber_id = ? ORDER BY x.started_at DESC LIMIT 20',
            [$id]
        );
        $plans = DB::rows('SELECT id, name, price, duration_days FROM plans WHERE (is_active = 1 AND service = ?) OR id = ? ORDER BY sort, price, name', [$plan['service'], $plan['id']]);
        return json_response([
            'ok'           => true,
            'subscriber'   => $row,
            'transactions' => array_map(static fn ($t) => [
                'id' => (int) $t['id'], 'type' => $t['type'], 'amount' => (float) $t['amount'], 'plan' => $t['plan_name'],
                'from' => $t['period_from'], 'to' => $t['period_to'], 'method' => $t['method'], 'notes' => $t['notes'],
                'employee' => $t['employee_name'], 'created_at' => $t['created_at'],
            ], $tx),
            'sessions'     => array_map([MobileController::class, 'sessionRow'], $sessions),
            'plans'        => array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'price' => (float) $p['price'], 'days' => (int) $p['duration_days']], $plans),
            'methods'      => self::methods(),
            'can'          => [
                'manage'   => can('subscribers.manage'),
                'renew'    => can('subscribers.renew'),
                'payments' => can('subscribers.payments'),
                'status'   => can('subscribers.status'),
                'delete'   => can('subscribers.delete'),
            ],
        ]);
    }

    private static function methods(): array
    {
        $out = [];
        foreach (SubscriberService::METHODS as $k => $label) {
            $out[] = ['key' => $k, 'label' => is_array($label) ? (string) $label[0] : (string) $label];
        }
        return $out;
    }

    /** قوائم نموذج المشترك */
    public function subscriberOptions(Request $request): Response
    {
        [$rScope, $rParams] = Auth::routerScope('id');
        return json_response([
            'ok'      => true,
            'plans'   => array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'price' => (float) $p['price'], 'service' => $p['service'], 'days' => (int) $p['duration_days']],
                DB::rows('SELECT id, name, price, service, duration_days FROM plans WHERE is_active = 1 ORDER BY sort, price, name')),
            'areas'   => array_map(static fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name']], DB::rows('SELECT id, name FROM areas ORDER BY name')),
            'routers' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], DB::rows("SELECT id, name FROM routers WHERE is_active = 1$rScope ORDER BY name", $rParams)),
            'methods' => self::methods(),
        ]);
    }

    // ------------------------------------------------------------------
    // المبيعات
    // ------------------------------------------------------------------

    public function sales(Request $request): Response
    {
        $where = '1 = 1';
        $params = [];
        if ($request->int('distributor') > 0) {
            $where .= ' AND s.distributor_id = ?';
            $params[] = $request->int('distributor');
        }
        $total = (int) DB::value("SELECT COUNT(*) FROM sales s WHERE $where", $params);
        $page = MobileController::page($request);
        $rows = DB::rows(
            "SELECT s.*, cat.name AS category_name, cat.color AS category_color, d.name AS distributor_name, e.name AS employee_name
             FROM sales s JOIN categories cat ON cat.id = s.category_id LEFT JOIN distributors d ON d.id = s.distributor_id
             LEFT JOIN employees e ON e.id = s.employee_id WHERE $where ORDER BY s.id DESC" . self::offset($page),
            $params
        );
        $today = DB::row("SELECT COALESCE(SUM(total), 0) AS total, COALESCE(SUM(quantity), 0) AS qty FROM sales WHERE status = 'completed' AND created_at >= ?", [date('Y-m-d 00:00:00')]);
        return MobileController::paged(array_map([self::class, 'saleRow'], $rows), $total, $page, self::PER, [
            'today_total' => (float) $today['total'], 'today_qty' => (int) $today['qty'],
        ]);
    }

    public static function saleRow(array $s): array
    {
        return [
            'id'          => (int) $s['id'],
            'type'        => (string) $s['type'],
            'category'    => $s['category_name'] ?? null,
            'color'       => $s['category_color'] ?? null,
            'distributor' => $s['distributor_name'] ?? null,
            'customer'    => $s['customer_name'],
            'quantity'    => (int) $s['quantity'],
            'unit_price'  => (float) $s['unit_price'],
            'discount'    => (float) $s['discount_percent'],
            'total'       => (float) $s['total'],
            'paid'        => (float) $s['paid'],
            'status'      => (string) $s['status'],
            'employee'    => $s['employee_name'] ?? null,
            'notes'       => $s['notes'],
            'created_at'  => $s['created_at'],
        ];
    }

    public function sale(Request $request, int $id): Response
    {
        $s = DB::row(
            'SELECT s.*, cat.name AS category_name, cat.color AS category_color, d.name AS distributor_name, e.name AS employee_name
             FROM sales s JOIN categories cat ON cat.id = s.category_id LEFT JOIN distributors d ON d.id = s.distributor_id
             LEFT JOIN employees e ON e.id = s.employee_id WHERE s.id = ?',
            [$id]
        );
        $this->notFoundUnless($s);
        $cards = DB::rows(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, NULL AS batch_name FROM cards c JOIN categories cat ON cat.id = c.category_id
             WHERE c.sale_id = ? ORDER BY c.id LIMIT 500',
            [$id]
        );
        return json_response([
            'ok'    => true,
            'sale'  => self::saleRow($s),
            'cards' => array_map([self::class, 'cardRow'], $cards),
            'can'   => ['cancel' => can('sales.cancel') && $s['status'] === 'completed'],
        ]);
    }

    public function saleOptions(Request $request): Response
    {
        $cats = DB::rows(
            "SELECT cat.id, cat.name, cat.price, cat.color, (SELECT COUNT(*) FROM cards c WHERE c.category_id = cat.id AND c.status = 'new' AND c.is_disabled = 0) AS available
             FROM categories cat WHERE cat.is_active = 1 AND cat.is_custom = 0 ORDER BY cat.sort, cat.price"
        );
        return json_response([
            'ok'           => true,
            'categories'   => array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'price' => (float) $c['price'], 'color' => $c['color'], 'available' => (int) $c['available']], $cats),
            'distributors' => array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name'], 'balance' => (float) $d['balance']], DB::rows('SELECT id, name, balance FROM distributors ORDER BY name')),
        ]);
    }

    public function distributors(Request $request): Response
    {
        $rows = DB::rows(
            "SELECT d.*, a.name AS area_name, (SELECT COALESCE(SUM(quantity), 0) FROM sales s WHERE s.distributor_id = d.id AND s.status = 'completed') AS sold
             FROM distributors d LEFT JOIN areas a ON a.id = d.area_id ORDER BY d.name"
        );
        return json_response(['ok' => true, 'items' => array_map(static fn ($d) => [
            'id' => (int) $d['id'], 'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'area' => $d['area_name'],
            'balance' => (float) $d['balance'], 'sold' => (int) $d['sold'],
        ], $rows)]);
    }

    // ------------------------------------------------------------------
    // الرسائل النصية
    // ------------------------------------------------------------------

    public function smsInfo(Request $request): Response
    {
        $states = [];
        foreach (SmsService::STATES as $k => $label) {
            if ($k === 'pick') {
                continue;
            }
            $states[] = ['key' => $k, 'label' => $label];
        }
        [$rScope, $rParams] = Auth::routerScope('id');
        return json_response([
            'ok'           => true,
            'configured'   => SmsService::configured(),
            'states'       => $states,
            'placeholders' => array_keys(SmsService::PLACEHOLDERS),
            'templates'    => array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'body' => $t['body']], DB::rows('SELECT id, name, body FROM sms_templates ORDER BY name')),
            'plans'        => array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['name']], DB::rows('SELECT id, name FROM plans ORDER BY sort, name')),
            'areas'        => array_map(static fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name']], DB::rows('SELECT id, name FROM areas ORDER BY name')),
            'routers'      => array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], DB::rows("SELECT id, name FROM routers WHERE 1 = 1$rScope ORDER BY name", $rParams)),
        ]);
    }

    public function smsLog(Request $request): Response
    {
        $total = (int) DB::value('SELECT COUNT(*) FROM sms_messages');
        $page = MobileController::page($request);
        $rows = DB::rows('SELECT m.*, e.name AS employee_name FROM sms_messages m LEFT JOIN employees e ON e.id = m.employee_id ORDER BY m.id DESC' . self::offset($page));
        return MobileController::paged(array_map([self::class, 'smsRow'], $rows), $total, $page);
    }

    public static function smsRow(array $m): array
    {
        return [
            'id'          => (int) $m['id'],
            'body'        => (string) $m['body'],
            'kind'        => (string) $m['kind'],
            'audience'    => $m['audience'] ?? null,
            'recipients'  => (int) $m['recipients'],
            'sent'        => (int) $m['sent'],
            'failed'      => (int) $m['failed'],
            'status'      => (string) $m['status'],
            'error'       => $m['error'] ?? null,
            'employee'    => $m['employee_name'] ?? null,
            'scheduled'   => $m['scheduled_at'] ?? null,
            'created_at'  => $m['created_at'],
        ];
    }

    public function smsShow(Request $request, int $id): Response
    {
        $m = DB::row('SELECT m.*, e.name AS employee_name FROM sms_messages m LEFT JOIN employees e ON e.id = m.employee_id WHERE m.id = ?', [$id]);
        $this->notFoundUnless($m);
        $rcpt = DB::rows('SELECT r.phone, r.status, r.name FROM sms_recipients r WHERE r.message_id = ? ORDER BY r.id LIMIT 300', [$id]);
        return json_response([
            'ok'         => true,
            'message'    => self::smsRow($m),
            'recipients' => array_map(static fn ($r) => ['phone' => $r['phone'], 'name' => $r['name'] ?? null, 'status' => $r['status']], $rcpt),
            'segments'   => TweetSms::segments((string) $m['body']),
        ]);
    }

    // ------------------------------------------------------------------
    // المخزون
    // ------------------------------------------------------------------

    public function inventory(Request $request): Response
    {
        $where = 'i.is_active = 1';
        $params = [];
        $q = trim($request->str('q'));
        if ($q !== '') {
            $where .= ' AND (i.name LIKE ? OR i.sku LIKE ?)';
            array_push($params, DB::like($q), DB::like($q));
        }
        if ($request->str('category') !== '' && isset(Inventory::CATEGORIES[$request->str('category')])) {
            $where .= ' AND i.category = ?';
            $params[] = $request->str('category');
        }
        $rows = DB::rows("SELECT i.* FROM inventory_items i WHERE $where ORDER BY i.name", $params);
        $cats = [];
        foreach (Inventory::CATEGORIES as $k => [$label]) {
            $cats[] = ['key' => $k, 'label' => $label];
        }
        return json_response([
            'ok'         => true,
            'items'      => array_map([self::class, 'itemRow'], $rows),
            'categories' => $cats,
            'summary'    => Inventory::summary(),
        ]);
    }

    public static function itemRow(array $i): array
    {
        return [
            'id'        => (int) $i['id'],
            'name'      => (string) $i['name'],
            'sku'       => $i['sku'],
            'category'  => (string) $i['category'],
            'category_label' => Inventory::CATEGORIES[$i['category']][0] ?? $i['category'],
            'unit'      => Inventory::UNITS[$i['unit']] ?? $i['unit'],
            'qty'       => (float) $i['quantity'],
            'min_qty'   => MobileController::f($i['min_qty'] ?? null),
            'low'       => Inventory::isLow($i),
            'serial'    => (bool) $i['track_serial'],
            'cost'      => MobileController::f($i['unit_cost'] ?? null),
        ];
    }

    public function inventoryItem(Request $request, int $id): Response
    {
        $item = Inventory::item($id);
        $this->notFoundUnless($item);
        $moves = DB::rows(
            'SELECT m.*, e.name AS employee_name, s.full_name AS subscriber_name, si.name AS site_name FROM inventory_movements m
             LEFT JOIN employees e ON e.id = m.employee_id LEFT JOIN subscribers s ON s.id = m.subscriber_id LEFT JOIN sites si ON si.id = m.site_id
             WHERE m.item_id = ? ORDER BY m.id DESC LIMIT 40',
            [$id]
        );
        $types = [];
        foreach (Inventory::MOVE_TYPES as $k => [$label]) {
            $types[] = ['key' => $k, 'label' => $label];
        }
        return json_response([
            'ok'        => true,
            'item'      => self::itemRow($item) + ['notes' => $item['notes'] ?? null],
            'movements' => array_map(static fn ($m) => [
                'id' => (int) $m['id'], 'type' => $m['type'], 'label' => Inventory::MOVE_TYPES[$m['type']][0] ?? $m['type'],
                'qty' => (float) $m['qty'], 'balance' => MobileController::f($m['balance'] ?? null),
                'subscriber' => $m['subscriber_name'], 'site' => $m['site_name'], 'notes' => $m['notes'],
                'employee' => $m['employee_name'], 'created_at' => $m['created_at'],
            ], $moves),
            'types'     => $types,
            'can'       => ['manage' => can('inventory.manage')],
        ]);
    }
}
