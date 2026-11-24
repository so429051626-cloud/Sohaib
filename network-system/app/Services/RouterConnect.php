<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Server;
use App\Core\Settings;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use RuntimeException;
use Throwable;

/**
 * ربط جهاز MikroTik بخطوة واحدة: ينشئ النظام الجهاز ونفقه الآمن وبيانات API بنفسه، ويولّد سكربتاً واحداً
 * يجلبه الجهاز بأمر واحد من Terminal، ثم يتابع الربط خطوة بخطوة حتى يصبح الجهاز جاهزاً للكروت.
 *
 * حالة كل ربط تُحفظ في الإعدادات (connect.{id}) ورمز جلب السكربت في (connect.token.{sha256})،
 * والرمز صالح 24 ساعة ويُلغى عند اكتمال الربط.
 */
final class RouterConnect
{
    public const API_USER = 'madix-api';
    public const API_GROUP = 'sn-api';
    public const FILE = 'madix-nexus.rsc';
    public const TOKEN_TTL = 86400;

    public static function available(): bool
    {
        return Tunnel::available();
    }

    private static function key(int $routerId): string
    {
        return 'connect.' . $routerId;
    }

    private static function tokenKey(string $token): string
    {
        return 'connect.token.' . hash('sha256', $token);
    }

    public static function state(int $routerId): ?array
    {
        $raw = Settings::get(self::key($routerId));
        $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($s) ? $s : null;
    }

    private static function save(int $routerId, array $state): void
    {
        Settings::set(self::key($routerId), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** كلمة مرور عشوائية بأحرف وأرقام فقط (لا تحتاج تهريباً داخل سكربت RouterOS) */
    private static function password(int $length = 24): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    /**
     * إنشاء الجهاز ونفقه (إن اختير) وحالة الربط.
     * @param string $method tunnel | public
     * @return int رقم الجهاز
     */
    public static function start(string $name, string $method, ?string $host, int $port, ?int $areaId, ?string $by): int
    {
        if ($method === 'tunnel' && !self::available()) {
            throw new RuntimeException('النفق الآمن غير متاح على هذا الخادم؛ اختر الربط بعنوان عام.');
        }
        if ($method === 'public' && ($host === null || $host === '')) {
            throw new RuntimeException('أدخل العنوان العام لجهاز MikroTik.');
        }
        $peer = null;
        if ($method === 'tunnel') {
            $peer = Tunnel::create($name, $by);
            $host = (string) $peer['address'];
            $port = 8728;
        }
        $password = self::password();
        try {
            $id = DB::insert('routers', [
                'name' => mb_substr($name, 0, 100), 'area_id' => $areaId, 'host' => (string) $host, 'api_port' => $port,
                'api_ssl' => 0, 'api_username' => self::API_USER, 'api_password' => Crypto::encrypt($password),
                // غير مفعّل حتى ينجح الاتصال: لا يمرّ عليه الفحص الدوري فلا تصدر تنبيهات انقطاع أثناء التجهيز
                'router_key' => bin2hex(random_bytes(16)), 'is_active' => 0, 'status' => 'unknown',
                'notes' => null, 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            if ($peer) {
                Tunnel::delete((int) $peer['id']);
            }
            throw $e;
        }
        self::save($id, ['method' => $method, 'tunnel_id' => $peer ? (int) $peer['id'] : null, 'created_at' => time()]);
        self::newToken($id);
        Audit::log('router.connect', 'بدء ربط جهاز MikroTik ' . $name . ($method === 'tunnel' ? ' عبر نفق آمن (' . $host . ')' : ' بالعنوان ' . $host), 'router', $id);
        return $id;
    }

    /** رمز جديد لجلب السكربت (يُلغي السابق) */
    public static function newToken(int $routerId): string
    {
        $state = self::state($routerId) ?? [];
        if (!empty($state['token'])) {
            try {
                Settings::set(self::tokenKey(Crypto::decrypt((string) $state['token'])), null);
            } catch (Throwable) {
            }
        }
        $token = bin2hex(random_bytes(20));
        Settings::set(self::tokenKey($token), (string) $routerId);
        $state['token'] = Crypto::encrypt($token);
        $state['expires'] = time() + self::TOKEN_TTL;
        $state['fetched_at'] = null;
        self::save($routerId, $state);
        return $token;
    }

    public static function token(int $routerId): ?string
    {
        $state = self::state($routerId);
        if (!$state || empty($state['token']) || (int) ($state['expires'] ?? 0) < time()) {
            return null;
        }
        try {
            return Crypto::decrypt((string) $state['token']);
        } catch (Throwable) {
            return null;
        }
    }

    /** إلغاء رمز الجلب (بعد اكتمال الربط) */
    private static function dropToken(int $routerId, array &$state): void
    {
        if (!empty($state['token'])) {
            try {
                Settings::set(self::tokenKey(Crypto::decrypt((string) $state['token'])), null);
            } catch (Throwable) {
            }
        }
        $state['token'] = null;
    }

    /** الجهاز صاحب رمز الجلب، إن كان الرمز صالحاً */
    public static function routerForToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $id = (int) Settings::get(self::tokenKey($token), '0');
        if ($id <= 0 || self::token($id) !== $token) {
            return null;
        }
        return DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
    }

    /** تسجيل جلب الجهاز للسكربت (ومن أي عنوان، وأي نسخة RouterOS إن ظهرت) */
    public static function markFetched(int $routerId, ?string $ip, string $agent): void
    {
        $state = self::state($routerId) ?? [];
        $state['fetched_at'] = time();
        $state['fetch_ip'] = $ip;
        $state['agent'] = mb_substr($agent, 0, 120);
        if (preg_match('/mikrotik\/(\d+)/i', $agent, $m)) {
            $state['ros'] = (int) $m[1];
        }
        self::save($routerId, $state);
    }

    /** عنوان الخادم العام الذي يتصل منه النظام بجهاز MikroTik (لقاعدة الجدار الناري) */
    public static function serverIp(): ?string
    {
        $ip = Server::managed() ? (string) (Server::status()['host']['ip'] ?? '') : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $host = (string) parse_url(Settings::panelUrl(), PHP_URL_HOST);
            $resolved = $host !== '' ? gethostbyname($host) : '';
            $ip = filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $resolved : '';
        }
        return $ip !== '' ? $ip : null;
    }

    /** أمر واحد يُلصق في Terminal جهاز MikroTik: يجلب السكربت وينفذه ثم يحذف ملفه */
    public static function command(int $routerId): ?string
    {
        $token = self::token($routerId);
        $panel = Settings::panelUrl();
        if ($token === null || !preg_match('#^https?://[A-Za-z0-9.\-:/_]+$#', $panel)) {
            return null;
        }
        $url = $panel . '/routers/setup/' . $token . '.rsc';
        return '/tool fetch url="' . $url . '" dst-path=' . self::FILE . '; :delay 1s; /import file-name=' . self::FILE . '; /file remove [find name="' . self::FILE . '"]';
    }

    /** السكربت الكامل: النفق (إن وُجد)، مستخدم API، خدمة API، الجدار الناري، Walled Garden */
    public static function script(array $router): string
    {
        $state = self::state((int) $router['id']) ?? [];
        $password = Crypto::decrypt((string) $router['api_password']);
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $password)) {
            throw new RuntimeException('كلمة مرور API لهذا الجهاز لا تصلح للسكربت التلقائي.');
        }
        $user = (string) $router['api_username'];
        $group = self::API_GROUP;
        $port = (int) $router['api_port'];
        $panelHost = HotspotSdk::panelHost();
        $L = [];
        $ascii = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]/', '', (string) $router['name'])));
        $L[] = '# MADIX NEXUS - connect router #' . (int) $router['id'] . ($ascii !== '' ? ' (' . str_replace(['"', '$', '\\'], '', $ascii) . ')' : '');
        $L[] = ':log info "MADIX NEXUS: setup started"';
        if (($state['method'] ?? '') === 'tunnel' && !empty($state['tunnel_id'])) {
            $peer = Tunnel::find((int) $state['tunnel_id']);
            if (!$peer) {
                throw new RuntimeException('النفق الآمن لهذا الجهاز محذوف. ابدأ الربط من جديد.');
            }
            $L[] = '# 1) secure tunnel (RouterOS 7)';
            $L[] = ':if ([:tonum [:pick [/system resource get version] 0 1]] < 7) do={ :log error "MADIX NEXUS: RouterOS 7 is required"; :error "MADIX NEXUS: RouterOS 7 is required for the secure tunnel. Upgrade RouterOS, then run this again." }';
            foreach (preg_split('/\r?\n/', trim(Tunnel::script($peer))) as $line) {
                $L[] = $line;
            }
        }
        $L[] = '# 2) API user for the panel';
        $L[] = ':if ([:len [/user group find where name="' . $group . '"]] = 0) do={ /user group add name=' . $group . ' policy=read,write,api,test,reboot,ftp,sensitive,policy comment="MADIX NEXUS" } else={ /user group set [find where name="' . $group . '"] policy=read,write,api,test,reboot,ftp,sensitive,policy }';
        $L[] = ':if ([:len [/user find where name="' . $user . '"]] = 0) do={ /user add name=' . $user . ' group=' . $group . ' password="' . $password . '" comment="MADIX NEXUS" } else={ /user set [find where name="' . $user . '"] group=' . $group . ' password="' . $password . '" disabled=no }';
        $L[] = '# 3) API service';
        $L[] = '/ip service set api disabled=no port=' . $port;
        if (($state['method'] ?? '') !== 'tunnel') {
            $ip = self::serverIp();
            if ($ip !== null) {
                $rule = '/ip firewall filter add chain=input protocol=tcp dst-port=' . $port . ' src-address=' . $ip . ' action=accept comment="MADIX NEXUS API"';
                $L[] = ':do { /ip firewall filter remove [find where comment="MADIX NEXUS API"] } on-error={}';
                $L[] = ':local r [/ip firewall filter find where dynamic=no]; :if ([:len $r] > 0) do={ ' . $rule . ' place-before=[:pick $r 0] } else={ ' . $rule . ' }';
            }
        }
        if ($panelHost !== '' && preg_match('/^[A-Za-z0-9.\-]+$/', $panelHost)) {
            $L[] = '# 4) hotspot page can reach the panel before login';
            $L[] = ':if ([:len [/ip hotspot walled-garden find where dst-host="' . $panelHost . '"]] = 0) do={ /ip hotspot walled-garden add dst-host=' . $panelHost . ' action=allow comment="sn:panel" }';
            $L[] = ':if ([:len [/ip hotspot walled-garden ip find where dst-host="' . $panelHost . '"]] = 0) do={ /ip hotspot walled-garden ip add dst-host=' . $panelHost . ' action=accept comment="sn:panel" }';
        }
        $L[] = ':log info "MADIX NEXUS: setup done"';
        $L[] = ':put "MADIX NEXUS: done. Return to the panel."';
        return implode("\r\n", $L) . "\r\n";
    }

    /**
     * تقدّم الربط: يُستدعى كل بضع ثوانٍ من صفحة المعالج، ويتصل بالجهاز فقط حتى يكتمل كل جزء.
     * @return array{steps: array, done: bool, router: array}
     */
    public static function progress(array $router): array
    {
        $id = (int) $router['id'];
        $state = self::state($id) ?? ['method' => 'public'];
        $tunnel = ($state['method'] ?? '') === 'tunnel';
        $steps = [];

        // النفق لا ينشئه إلا السكربت: اتصاله يعني أن السكربت نُفّذ (ولو لُصق كاملاً بدل الأمر الواحد)
        $peer = $tunnel && !empty($state['tunnel_id']) ? Tunnel::find((int) $state['tunnel_id']) : null;
        $up = $peer ? Tunnel::state($peer)['connected'] : false;

        // 1) استلام السكربت
        $fetched = !empty($state['fetched_at']) || !empty($state['api_at']) || $up;
        $steps['fetched'] = ['state' => $fetched ? 'done' : 'wait', 'detail' => !empty($state['fetched_at']) ? date('H:i:s', (int) $state['fetched_at']) : null];

        // 2) النفق الآمن
        if ($tunnel) {
            if ($up || !empty($state['api_at'])) {
                $steps['tunnel'] = ['state' => 'done', 'detail' => $peer['address'] ?? null];
            } elseif (!$peer) {
                $steps['tunnel'] = ['state' => 'error', 'detail' => 'النفق محذوف'];
            } elseif (($state['ros'] ?? 7) < 7) {
                $steps['tunnel'] = ['state' => 'error', 'detail' => 'النفق يحتاج RouterOS 7'];
            } else {
                $steps['tunnel'] = ['state' => $fetched ? 'work' : 'wait', 'detail' => null];
            }
        }

        // 3) الاتصال بواجهة API (محاولة كل 5 ثوانٍ على الأكثر)
        if (empty($state['api_at'])) {
            $steps['api'] = ['state' => $fetched ? 'work' : 'wait', 'detail' => $state['api_error'] ?? null];
            $mayTry = ($tunnel ? ($steps['tunnel']['state'] === 'done') : true) && time() - (int) ($state['api_try'] ?? 0) >= 5;
            if ($mayTry) {
                $state['api_try'] = time();
                self::save($id, $state);
                // فحص خفيف أولاً (بلا آثار جانبية)، ثم يُفعَّل الجهاز وتُجرى أول مزامنة كاملة
                $error = null;
                try {
                    $gw = Gateway::for($router, 4.0);
                    $gw->resource();
                    $gw->close();
                } catch (MikroTikException $e) {
                    $error = $e->friendly();
                }
                if ($error === null) {
                    DB::update('routers', ['is_active' => 1, 'fail_count' => 0, 'next_poll_at' => null, 'updated_at' => now()], 'id = ?', [$id]);
                    $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]) ?? $router;
                    $r = RouterSync::run($router, true);
                    $router = DB::row('SELECT * FROM routers WHERE id = ?', [$id]) ?? $router;
                    if ($r['ok']) {
                        $state['api_at'] = time();
                        $state['fetched_at'] ??= time();
                        Audit::log('router.connect', 'اكتمل الاتصال بجهاز MikroTik ' . $router['name'], 'router', $id);
                    } else {
                        $error = (string) $r['error'];
                    }
                }
                $state['api_error'] = $error !== null && $fetched ? $error : null;
                $steps['api']['detail'] = $state['api_error'];
                self::save($id, $state);
            }
        }
        if (!empty($state['api_at'])) {
            $steps['fetched']['state'] = 'done';
            $steps['api'] = ['state' => 'done', 'detail' => trim(($router['identity'] ?: $router['name']) . ($router['version'] ? ' · RouterOS ' . $router['version'] : ''))];
        }

        // 4) الهوتسبوت والكروت: نشر ملفات الصفحة، Walled Garden، وملفات سرعة الباقات
        if (!empty($state['ready_at'])) {
            $steps['cards'] = ['state' => 'done', 'detail' => $state['ready_detail'] ?? null];
        } elseif (!empty($state['api_at'])) {
            // آخر نتيجة تبقى ظاهرة بين المحاولات
            $steps['cards'] = ['state' => (string) ($state['cards_state'] ?? 'work'), 'detail' => $state['cards_detail'] ?? null];
            if (time() - (int) ($state['cards_try'] ?? 0) >= 8) {
                $state['cards_try'] = time();
                self::save($id, $state);
                $result = self::prepareCards($router);
                if ($result['ok']) {
                    $state['ready_at'] = time();
                    $state['ready_detail'] = $result['detail'];
                    self::dropToken($id, $state);
                    $steps['cards'] = ['state' => 'done', 'detail' => $result['detail']];
                    Audit::log('router.connect', 'جهاز MikroTik ' . $router['name'] . ' جاهز للكروت', 'router', $id);
                } else {
                    $steps['cards'] = ['state' => $result['blocking'] ? 'error' : 'work', 'detail' => $result['detail']];
                }
                $state['cards_state'] = $steps['cards']['state'];
                $state['cards_detail'] = $steps['cards']['detail'];
                self::save($id, $state);
            }
        } else {
            $steps['cards'] = ['state' => 'wait', 'detail' => null];
        }

        $done = !empty($state['ready_at']);
        return ['steps' => $steps, 'done' => $done, 'router' => ['id' => $id, 'name' => $router['name'], 'status' => $router['status'], 'identity' => $router['identity'] ?? null]];
    }

    /**
     * تجهيز الجهاز للكروت: خادم هوتسبوت موجود، ملفات ربط الصفحة، وملفات سرعة الباقات الفعّالة.
     * @return array{ok:bool, blocking:bool, detail:?string}
     */
    private static function prepareCards(array $router): array
    {
        try {
            $gw = Gateway::for($router, 8.0);
            $servers = $gw->hotspotServers();
            if (!$servers) {
                $gw->close();
                return ['ok' => false, 'blocking' => true, 'detail' => 'لا يوجد خادم هوتسبوت على الجهاز. أنشئه من Winbox (افتح IP ثم Hotspot ثم Hotspot Setup)، وتكتمل المتابعة تلقائياً.'];
            }
            $profiles = 0;
            foreach (DB::rows('SELECT * FROM categories WHERE is_active = 1 AND is_custom = 0') as $cat) {
                ProfileManager::ensureCategory($gw, $router, $cat);
                $profiles++;
            }
            $gw->close();
        } catch (MikroTikException $e) {
            return ['ok' => false, 'blocking' => false, 'detail' => $e->friendly()];
        }
        $deploy = HotspotSdk::deploy($router);
        if (!$deploy['ok']) {
            return ['ok' => false, 'blocking' => true, 'detail' => $deploy['message']];
        }
        return ['ok' => true, 'blocking' => false, 'detail' => num(count($servers)) . ' خادم هوتسبوت · ' . num($profiles) . ' باقة كروت'];
    }

    /** إلغاء معالج جهاز (عند حذف الجهاز) */
    public static function forget(int $routerId): void
    {
        $state = self::state($routerId);
        if ($state) {
            self::dropToken($routerId, $state);
            Settings::set(self::key($routerId), null);
        }
    }
}
