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
     * $rawCode و$rawPassword: ما كتبه المستخدم قبل توحيد حالة الأحرف (من نسخ المكتبة الأحدث)، لمستخدمي MikroTik المحليين فقط.
     * @return array{ok:bool, error?:string, message?:string, username?:string, password?:string, fallback?:bool, info?:array}
     */
    public static function login(string $key, string $code, string $password, ?string $macRaw, ?string $ip, ?string $rawCode = null, ?string $rawPassword = null): array
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
            // ليس كرتاً ولا مشتركاً في النظام: قد يكون مستخدماً أُنشئ يدوياً على جهاز MikroTik
            if (Settings::bool('hotspot.local_users')) {
                $user = self::rawValue($rawCode, $code) ?? $code;
                $pass = self::rawValue($rawPassword, $password) ?? $password;
                return self::localLogin($router, $user, $pass, $mac, $ip);
            }
            return self::fail($router, $code, $mac, $ip, 'not_found');
        }
        [$type, $id] = $owner;
        if ($type === 'subscriber') {
            return self::subscriberLogin($router, $id, $code, $password, $mac, $ip);
        }
        return self::cardLogin($router, $id, $type === 'card_short', $code, $password, $mac, $ip);
    }

    /**
     * القيمة كما كتبها المستخدم (بأرقام لاتينية) إن كانت تطابق القيمة الموحّدة بعد التوحيد، وإلا null.
     * توحيد حالة الأحرف يناسب الكروت، أما مستخدم MikroTik المحلي فاسمه وكلمة مروره بحالة أحرفهما الأصلية.
     */
    private static function rawValue(?string $raw, string $normalized): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = trim(latin_digits($raw));
        if ($raw === '' || strlen($raw) > 64 || preg_match('/[\x00-\x1F]/', $raw)) {
            return null;
        }
        // المكتبة توحّد الاسم وكلمة المرور بنفس الطريقة (الأرقام والمسافات وحالة الأحرف)
        return hash_equals($normalized, self::normalizeCode($raw)) ? $raw : null;
    }

    /**
     * مستخدم هوتسبوت محلي على جهاز MikroTik (أُنشئ يدوياً، غير موجود في النظام).
     * يُفحص عبر واجهة API إن أمكن ليُعرض سبب الرفض بالعربية، ثم تُكمل الصفحة الدخول على جهاز MikroTik مباشرة.
     * محاولات المستخدم المحلي لا تُحسب محاولات خاطئة ولا تقفل الجهاز؛ أما الرمز غير الموجود في النظام ولا على الجهاز
     * فيُعامل كرقم كرت خاطئ كما كان (حماية الكروت من التخمين).
     */
    private static function localLogin(array $router, string $username, string $password, ?string $mac, ?string $ip): array
    {
        $check = self::localUser($router, $username);
        if ($check['state'] === 'missing') {
            return self::fail($router, $username, $mac, $ip, 'not_found');
        }
        $submit = $password !== '' ? $password : $username;
        $info = null;
        if ($check['state'] === 'found') {
            $u = $check['user'];
            if ($u['password'] !== null) {
                if ($password === '') {
                    // بلا كلمة مرور: يدخل إن كانت كلمة مروره فارغة أو هي اسمه، وإلا تطلب الصفحة كلمة المرور
                    if ($u['password'] === '') {
                        $submit = '';
                    } elseif (!hash_equals($u['password'], $username)) {
                        return ['ok' => false, 'error' => 'need_password', 'message' => CardRules::message('need_password'), 'need_password' => true];
                    }
                } elseif (!hash_equals($u['password'], $password)) {
                    return self::fail($router, $username, $mac, $ip, 'wrong_password', null, null, false);
                }
            }
            $reason = match (true) {
                $u['disabled'] => 'disabled',
                $u['limit_uptime'] > 0 && $u['uptime'] >= $u['limit_uptime'] => 'time_depleted',
                $u['limit_bytes'] > 0 && $u['bytes'] >= $u['limit_bytes'] => 'data_depleted',
                default => null,
            };
            if ($reason !== null) {
                return self::fail($router, $username, $mac, $ip, $reason, null, null, false);
            }
            $info = self::localInfo($u);
            if ($mac) {
                Throttle::clear('hs:' . $router['id'] . ':' . $mac);
            }
        }
        DB::insert('login_log', [
            'created_at' => now(), 'router_id' => $router['id'], 'source' => 'portal', 'event' => 'check',
            'login' => mb_substr($username, 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'accept', 'reason' => 'local',
        ]);
        return ['ok' => true, 'username' => $username, 'password' => $submit, 'type' => 'local', 'provisioned' => false, 'info' => $info];
    }

    /**
     * البحث عن مستخدم محلي على جهاز MikroTik.
     * @return array{state:string, user?:array} found: موجود · missing: غير موجود والهوتسبوت لا يستخدم RADIUS · unknown: تعذّر التحقق
     */
    private static function localUser(array $router, string $username): array
    {
        try {
            $gw = Gateway::for($router, 4.0);
            $c = $gw->client();
            $row = $c->first('/ip/hotspot/user', ['name' => $username]);
            if ($row === null) {
                // خوادم الهوتسبوت التي تتحقق عبر RADIUS: المستخدم قد يكون هناك، فيُترك القرار لجهاز MikroTik
                $radius = false;
                foreach ($c->print('/ip/hotspot/profile', [], ['name', 'use-radius']) as $p) {
                    if (($p['use-radius'] ?? 'false') === 'true') {
                        $radius = true;
                    }
                }
                $gw->close();
                return ['state' => $radius ? 'unknown' : 'missing'];
            }
            $u = Gateway::normalizeHotspotUser($row);
            $rate = null;
            if ($u['profile'] !== '') {
                $rate = (string) ($c->first('/ip/hotspot/user/profile', ['name' => $u['profile']], ['rate-limit'])['rate-limit'] ?? '');
            }
            $gw->close();
            return ['state' => 'found', 'user' => [
                'name' => $u['name'], 'profile' => $u['profile'],
                // كلمة المرور لا تُقرأ إن لم تملك صلاحية API قراءة البيانات الحساسة: لا يُتحقق منها هنا
                'password' => array_key_exists('password', $row) ? (string) $row['password'] : null,
                'disabled' => $u['disabled'], 'uptime' => $u['uptime'], 'limit_uptime' => $u['limit_uptime'],
                'bytes' => $u['bytes_in'] + $u['bytes_out'], 'limit_bytes' => $u['limit_bytes_total'], 'rate' => $rate,
            ]];
        } catch (\Throwable $e) {
            if (!$e instanceof MikroTikException || !$e->isConnection()) {
                log_message('portal', 'local user check router ' . $router['id'] . ': ' . $e->getMessage());
            }
            return ['state' => 'unknown'];
        }
    }

    /** معلومات المستخدم المحلي بنفس شكل معلومات الكرت لصفحة الهوتسبوت */
    private static function localInfo(array $u): array
    {
        $up = $down = null;
        if (($u['rate'] ?? '') !== '' && preg_match('#^\s*([0-9.]+[kKmMgG]?)/([0-9.]+[kKmMgG]?)#', (string) $u['rate'], $m)) {
            [$up, $down] = [$m[1], $m[2]];
        }
        return [
            'category'       => $u['profile'] !== 'default' ? $u['profile'] : null,
            'expires_at'     => null,
            'remaining_time' => $u['limit_uptime'] > 0 ? max(0, $u['limit_uptime'] - $u['uptime']) : null,
            'remaining_data' => $u['limit_bytes'] > 0 ? max(0, $u['limit_bytes'] - $u['bytes']) : null,
            'used_time'      => (int) $u['uptime'],
            'used_data'      => (int) $u['bytes'],
            'speed'          => Units::describeRate(['rate_up' => $up, 'rate_down' => $down]),
        ];
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
            return Settings::bool('hotspot.local_users') ? self::localStatus($router, trim($username), $mac) : ['ok' => false, 'message' => CardRules::message('not_found')];
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

    /** حالة مستخدم MikroTik المحلي: تُعرض فقط للجهاز المتصل به الآن */
    private static function localStatus(array $router, string $username, ?string $mac): array
    {
        if ($username === '' || strlen($username) > 64) {
            return ['ok' => false, 'message' => CardRules::message('not_found')];
        }
        try {
            $gw = Gateway::for($router, 4.0);
            $active = $gw->client()->print('/ip/hotspot/active', ['user' => $username], ['mac-address']);
            $gw->close();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => CardRules::message('unavailable')];
        }
        $here = false;
        foreach ($active as $a) {
            if ($mac === null || normalize_mac($a['mac-address'] ?? '') === $mac) {
                $here = true;
            }
        }
        $check = $here ? self::localUser($router, $username) : ['state' => 'missing'];
        if ($check['state'] !== 'found') {
            return ['ok' => false, 'message' => CardRules::message('not_found')];
        }
        return ['ok' => true, 'type' => 'local', 'info' => self::localInfo($check['user'])];
    }

    /** بيانات عامة لصفحة الهوتسبوت: الاسم والباقات والأسعار */
    public static function info(string $key): array
    {
        $router = self::routerByKey($key);
        if (!$router) {
            return ['ok' => false, 'message' => CardRules::message('router')];
        }
        $cats = DB::rows('SELECT * FROM categories WHERE is_active = 1 AND show_on_hotspot = 1 AND is_custom = 0 ORDER BY sort, price');
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
