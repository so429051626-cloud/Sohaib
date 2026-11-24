<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\Core\Throttle;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;

/**
 * منطق واجهة الهوتسبوت: التحقق من الكرت/المشترك، ثم تجهيزه على جهاز MikroTik
 * وإعادة بيانات الدخول لصفحة الهوتسبوت لتكمل الدخول عبر جهاز MikroTik.
 */
final class PortalService
{
    public static function routerByKey(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }
        return DB::row('SELECT * FROM routers WHERE router_key = ? AND is_active = 1', [$key]);
    }

    public static function normalizeCode(string $code): string
    {
        $code = trim(latin_digits($code));
        $code = preg_replace('/\s+/', '', $code);
        return match ((string) Settings::get('hotspot.code_case', 'none')) {
            'lower' => strtolower($code),
            'upper' => strtoupper($code),
            default => $code,
        };
    }

    private static function fail(?array $router, string $login, ?string $mac, ?string $ip, string $reason, ?int $cardId = null, ?int $subId = null, bool $countFailure = true, array $extra = []): array
    {
        if ($router && $countFailure && $mac) {
            $limit = max(3, Settings::int('hotspot.fail_limit', 8));
            $window = max(1, Settings::int('hotspot.fail_window', 10)) * 60;
            $lock = max(1, Settings::int('hotspot.lock_minutes', 15)) * 60;
            Throttle::fail('hs:' . $router['id'] . ':' . $mac, $limit, $window, $lock);
        }
        DB::insert('login_log', [
            'created_at' => now(), 'router_id' => $router['id'] ?? null, 'source' => 'portal', 'event' => 'check',
            'login' => mb_substr($login !== '' ? $login : '-', 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'reject', 'reason' => $reason,
            'card_id' => $cardId, 'subscriber_id' => $subId,
        ]);
        return ['ok' => false, 'error' => $reason, 'message' => CardRules::message($reason)] + $extra;
    }

    /**
     * التحقق والتجهيز.
     * @return array{ok:bool, error?:string, message?:string, username?:string, password?:string, fallback?:bool, info?:array}
     */
    public static function login(string $key, string $code, string $password, ?string $macRaw, ?string $ip): array
    {
        $router = self::routerByKey($key);
        if (!$router) {
            return ['ok' => false, 'error' => 'router', 'message' => CardRules::message('router'), 'fallback' => true];
        }
        $mac = normalize_mac($macRaw);
        $ip = is_valid_ip($ip) ? $ip : null;
        $code = self::normalizeCode($code);
        $password = trim(latin_digits($password));

        if ($code === '' || strlen($code) > 64) {
            return ['ok' => false, 'error' => 'invalid', 'message' => CardRules::message('invalid')];
        }
        if (!Throttle::rate('hs:rate:' . $router['id'], 600, 60)) {
            return ['ok' => false, 'error' => 'rate', 'message' => CardRules::message('rate')];
        }
        if ($mac) {
            $wait = Throttle::lockedFor('hs:' . $router['id'] . ':' . $mac);
            if ($wait > 0) {
                return ['ok' => false, 'error' => 'locked', 'message' => CardRules::message('locked') . ' حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
            }
            if (DB::value('SELECT 1 FROM blocked_macs WHERE mac = ?', [$mac])) {
                return self::fail($router, $code, $mac, $ip, 'blocked', null, null, false);
            }
        }

        $owner = LoginRegistry::owner($code);
        if (!$owner) {
            return self::fail($router, $code, $mac, $ip, 'not_found');
        }
        [$type, $id] = $owner;
        if ($type === 'subscriber') {
            return self::subscriberLogin($router, $id, $code, $password, $mac, $ip);
        }
        return self::cardLogin($router, $id, $type === 'card_short', $code, $password, $mac, $ip);
    }

    private static function cardLogin(array $router, int $cardId, bool $viaShort, string $code, string $password, ?string $mac, ?string $ip): array
    {
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
        if (!$card) {
            return self::fail($router, $code, $mac, $ip, 'not_found');
        }
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);

        if ($viaShort) {
            // الرقم المختصر مع كلمة مرور مختصرة: تُقبل المختصرة أو الأصلية
            $short = (string) ($card['short_password'] ?? '');
            if ($short !== '') {
                if ($password === '') {
                    return ['ok' => false, 'error' => 'need_password', 'message' => CardRules::message('need_password'), 'need_password' => true];
                }
                if (!hash_equals($short, $password) && !hash_equals($card['password'], $password)) {
                    return self::fail($router, $code, $mac, $ip, 'wrong_password', $cardId);
                }
            }
        } else {
            if ($password === '') {
                if ($card['password'] !== $card['username']) {
                    return ['ok' => false, 'error' => 'need_password', 'message' => CardRules::message('need_password'), 'need_password' => true];
                }
            } elseif (!hash_equals($card['password'], $password)) {
                return self::fail($router, $code, $mac, $ip, 'wrong_password', $cardId);
            }
        }

        if (!Settings::bool('hotspot.allow_unsold') && $card['status'] === 'new') {
            return self::fail($router, $code, $mac, $ip, 'unsold', $cardId, null, false);
        }
        $reason = CardRules::blockReason($card, $cat);
        if ($reason !== null) {
            if (in_array($reason, ['expired', 'time_depleted', 'data_depleted'], true)) {
                Enforcer::cards([$cardId]);
            }
            return self::fail($router, $code, $mac, $ip, $reason, $cardId, null, false);
        }
        $allowed = DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [$cat['id']]);
        if ($allowed && !in_array((string) $router['id'], array_map('strval', $allowed), true)) {
            return self::fail($router, $code, $mac, $ip, 'not_allowed', $cardId, null, false);
        }
        // دفعة مرتبطة بأجهزة محددة: الكرت يعمل عليها فقط
        $batchRouters = DB::column('SELECT router_id FROM batch_routers WHERE batch_id = ?', [(int) $card['batch_id']]);
        if ($batchRouters && !in_array((string) $router['id'], array_map('strval', $batchRouters), true)) {
            return self::fail($router, $code, $mac, $ip, 'not_allowed', $cardId, null, false);
        }
        if ((int) $cat['lock_mac'] === 1 && $card['bound_mac'] && $mac && $card['bound_mac'] !== $mac) {
            return self::fail($router, $code, $mac, $ip, 'mac_mismatch', $cardId, null, false);
        }
        $shared = (int) $cat['shared_users'];
        if ($shared > 0) {
            $others = (int) DB::value(
                'SELECT COUNT(*) FROM sessions WHERE card_id = ? AND stopped_at IS NULL AND (mac IS NULL OR mac <> ?) AND updated_at >= ?',
                [$cardId, $mac ?? '', date('Y-m-d H:i:s', time() - 900)]
            );
            if ($others >= $shared) {
                return self::fail($router, $code, $mac, $ip, 'in_use', $cardId, null, false);
            }
        }

        // التجهيز على جهاز MikroTik
        try {
            $gw = Gateway::for($router, 6.0);
            Provisioner::provisionCard($gw, $router, $cardId, $mac);
            $gw->close();
            if (!$card['first_login_at']) {
                // بداية الصلاحية من هذا الدخول
                $validity = $cat['validity'] !== null ? (int) $cat['validity'] + (int) $card['bonus_validity'] : null;
                DB::update('cards', [
                    'first_login_at' => now(),
                    'expires_at'     => $validity !== null ? date('Y-m-d H:i:s', time() + $validity) : null,
                    'status'         => 'active',
                    'bound_mac'      => ((int) $cat['lock_mac'] === 1 && $mac) ? $mac : $card['bound_mac'],
                    'updated_at'     => now(),
                ], 'id = ? AND first_login_at IS NULL', [$cardId]);
            } elseif ((int) $cat['lock_mac'] === 1 && $mac && !$card['bound_mac']) {
                DB::update('cards', ['bound_mac' => $mac], 'id = ? AND bound_mac IS NULL', [$cardId]);
            }
        } catch (MikroTikException $e) {
            log_message('portal', 'provision failed router ' . $router['id'] . ': ' . $e->getMessage());
            DB::insert('login_log', [
                'created_at' => now(), 'router_id' => $router['id'], 'source' => 'portal', 'event' => 'check',
                'login' => mb_substr($code, 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'reject', 'reason' => 'router_error', 'card_id' => $cardId,
            ]);
            // تعذّر الوصول لجهاز MikroTik: صفحة الهوتسبوت تكمل بالبيانات الأصلية مباشرة
            return [
                'ok' => true, 'username' => $card['username'], 'password' => $card['password'],
                'type' => 'card', 'provisioned' => false, 'info' => self::cardInfo(DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]), $cat),
            ];
        }

        if ($mac) {
            Throttle::clear('hs:' . $router['id'] . ':' . $mac);
        }
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
        DB::update('cards', ['last_router_id' => $router['id'], 'last_mac' => $mac ?? $card['last_mac'], 'last_ip' => $ip ?? $card['last_ip']], 'id = ?', [$cardId]);
        DB::insert('login_log', [
            'created_at' => now(), 'router_id' => $router['id'], 'source' => 'portal', 'event' => 'check',
            'login' => mb_substr($code, 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'accept', 'reason' => $viaShort ? 'short' : null, 'card_id' => $cardId,
        ]);
        return [
            'ok' => true, 'username' => $card['username'], 'password' => $card['password'],
            'type' => 'card', 'provisioned' => true, 'info' => self::cardInfo($card, $cat),
        ];
    }

    private static function subscriberLogin(array $router, int $subId, string $code, string $password, ?string $mac, ?string $ip): array
    {
        $sub = DB::row('SELECT * FROM subscribers WHERE id = ?', [$subId]);
        if (!$sub) {
            return self::fail($router, $code, $mac, $ip, 'not_found');
        }
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$sub['plan_id']]);
        if ($password === '') {
            return ['ok' => false, 'error' => 'need_password', 'message' => CardRules::message('need_password'), 'need_password' => true];
        }
        if (!hash_equals($sub['password'], $password)) {
            return self::fail($router, $code, $mac, $ip, 'wrong_password', null, $subId);
        }
        if ($plan['service'] !== 'hotspot') {
            return self::fail($router, $code, $mac, $ip, 'wrong_service', null, $subId, false);
        }
        $reason = SubscriberRules::blockReason($sub, $plan);
        if ($reason !== null) {
            return self::fail($router, $code, $mac, $ip, $reason, null, $subId, false);
        }
        if ((int) $sub['lock_mac'] === 1 && $sub['bound_mac'] && $mac && $sub['bound_mac'] !== $mac) {
            return self::fail($router, $code, $mac, $ip, 'mac_mismatch', null, $subId, false);
        }
        try {
            if ((int) $sub['lock_mac'] === 1 && $mac && !$sub['bound_mac']) {
                DB::update('subscribers', ['bound_mac' => $mac], 'id = ? AND bound_mac IS NULL', [$subId]);
            }
            $gw = Gateway::for($router, 6.0);
            Provisioner::provisionSubscriber($gw, $router, $subId);
            $gw->close();
        } catch (MikroTikException $e) {
            log_message('portal', 'subscriber provision failed router ' . $router['id'] . ': ' . $e->getMessage());
        }
        if ($mac) {
            Throttle::clear('hs:' . $router['id'] . ':' . $mac);
        }
        DB::insert('login_log', [
            'created_at' => now(), 'router_id' => $router['id'], 'source' => 'portal', 'event' => 'check',
            'login' => mb_substr($code, 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'accept', 'reason' => null, 'subscriber_id' => $subId,
        ]);
        return [
            'ok' => true, 'username' => $sub['username'], 'password' => $sub['password'], 'type' => 'subscriber',
            'info' => ['name' => $sub['full_name'], 'plan' => $plan['name'], 'expires_at' => $sub['expires_at']],
        ];
    }

    public static function cardInfo(array $card, array $cat): array
    {
        $r = CardRules::remaining($card, $cat);
        return [
            'category'       => $cat['name'],
            'expires_at'     => $card['expires_at'],
            'remaining_time' => $r['time'] !== null ? max(0, $r['time']) : null,
            'remaining_data' => $r['data'] !== null ? max(0, $r['data']) : null,
            'used_time'      => (int) $card['used_seconds'],
            'used_data'      => CardRules::usedBytes($card),
            'speed'          => Units::describeRate($cat),
        ];
    }

    /** معلومات الحالة لصفحة status في الهوتسبوت */
    public static function status(string $key, string $username, ?string $macRaw): array
    {
        $router = self::routerByKey($key);
        if (!$router) {
            return ['ok' => false, 'message' => CardRules::message('router')];
        }
        $mac = normalize_mac($macRaw);
        $owner = LoginRegistry::owner(self::normalizeCode($username));
        if (!$owner) {
            return ['ok' => false, 'message' => CardRules::message('not_found')];
        }
        [$type, $id] = $owner;
        if ($type === 'subscriber') {
            $sub = DB::row('SELECT s.*, p.name AS plan_name, p.data_limit FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?', [$id]);
            if (!$sub || ($mac && $sub['last_mac'] && $sub['last_mac'] !== $mac)) {
                return ['ok' => false, 'message' => CardRules::message('not_found')];
            }
            return ['ok' => true, 'type' => 'subscriber', 'info' => [
                'name' => $sub['full_name'], 'plan' => $sub['plan_name'], 'expires_at' => $sub['expires_at'],
                'remaining_data' => $sub['data_limit'] ? max(0, (int) $sub['data_limit'] - (int) $sub['period_bytes']) : null,
            ]];
        }
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$id]);
        if (!$card || ($mac && $card['last_mac'] && $card['last_mac'] !== $mac)) {
            return ['ok' => false, 'message' => CardRules::message('not_found')];
        }
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
        return ['ok' => true, 'type' => 'card', 'info' => self::cardInfo($card, $cat)];
    }

    /** بيانات عامة لصفحة الهوتسبوت: الاسم والباقات والأسعار */
    public static function info(string $key): array
    {
        $router = self::routerByKey($key);
        if (!$router) {
            return ['ok' => false, 'message' => CardRules::message('router')];
        }
        $cats = DB::rows('SELECT * FROM categories WHERE is_active = 1 AND show_on_hotspot = 1 ORDER BY sort, price');
        $currency = (string) Settings::get('general.currency', '');
        return [
            'ok' => true,
            'network' => Settings::networkName(),
            'phone' => (string) Settings::get('general.phone', ''),
            'whatsapp' => (string) Settings::get('general.whatsapp', ''),
            'currency' => $currency,
            'categories' => array_map(static fn ($c) => [
                'name' => $c['name'], 'price' => (float) $c['price'], 'price_text' => money($c['price']),
                'time' => Units::describeSeconds($c['time_limit'] !== null ? (int) $c['time_limit'] : null),
                'validity' => Units::describeSeconds($c['validity'] !== null ? (int) $c['validity'] : null),
                'data' => Units::describeBytes($c['data_limit'] !== null ? (int) $c['data_limit'] : null),
                'speed' => Units::describeRate($c), 'color' => $c['color'],
            ], $cats),
        ];
    }
}
