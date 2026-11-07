<?php
// النواة المشتركة: التخزين في ملفات (بلا قاعدة بيانات)، المحتوى، الإحصائيات، وأدوات عامة.
// متوافق مع PHP 7.4 فما فوق.

declare(strict_types=1);

require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/icons.php';

date_default_timezone_set('Asia/Gaza');
// على الاستضافة الحقيقية لا تُعرض أخطاء PHP للزوار (تكشف مسارات الخادم)
@ini_set('display_errors', '0');

const APP_ROOT   = __DIR__ . '/..';
const DATA_DIR   = APP_ROOT . '/data';
const UPLOAD_DIR = APP_ROOT . '/uploads';
// كل ملف بيانات يبدأ بهذا السطر، فلو طلبه أحد من المتصفح لا يظهر منه شيء
const DATA_GUARD = "<?php http_response_code(404); exit; ?>\n";

// بديل بسيط لو كانت إضافة mbstring غير مفعّلة على الاستضافة
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string
    {
        preg_match_all('/./us', $s, $m);
        return implode('', array_slice($m[0], $start, $length));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        return (int) preg_match_all('/./us', $s);
    }
}

// قراءة نص من $_GET أو $_POST (لو أُرسلت مصفوفة بدل نص نتجاهلها بدل ظهور تحذير)
function req_str(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

/* ───────────── التخزين ───────────── */

function ensure_dirs(): void
{
    foreach ([DATA_DIR, UPLOAD_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    // نستعمل mod_rewrite وليس Require، لأن بعض الاستضافات ترفض Require في .htaccess بخطأ 500
    if (!is_file(DATA_DIR . '/.htaccess')) {
        @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^ - [F,L]\n</IfModule>\n");
    }
    if (!is_file(DATA_DIR . '/index.html')) {
        @file_put_contents(DATA_DIR . '/index.html', '');
    }
}

function store_path(string $name): string
{
    return DATA_DIR . '/' . $name . '.json.php';
}

function store_decode(string $raw): array
{
    if (strncmp($raw, DATA_GUARD, strlen(DATA_GUARD)) === 0) {
        $raw = substr($raw, strlen(DATA_GUARD));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function store_read(string $name): array
{
    $file = store_path($name);
    if (!is_file($file)) {
        return [];
    }
    return store_decode((string) @file_get_contents($file));
}

/**
 * قراءة وتعديل وكتابة تحت قفل واحد، فلا تضيع زيارة لو دخل زائران بنفس اللحظة.
 * الكتابة في ملف مؤقت ثم استبداله دفعة واحدة: لو امتلأت المساحة أو انقطع الطلب
 * يبقى الملف القديم سليماً ولا يُمسح أبداً. ترجع true فقط لو تم الحفظ فعلاً.
 * لو رجعت الدالة $fn بقيمة غير مصفوفة لا يُكتب شيء.
 */
function store_update(string $name, callable $fn): bool
{
    ensure_dirs();
    $lock = @fopen(DATA_DIR . '/' . $name . '.lock', 'c');
    if (!$lock) {
        return false;
    }
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return false;
    }
    $ok  = true;
    $new = $fn(store_read($name));
    if (is_array($new)) {
        $json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
        $ok = false;
        if ($json !== false) {
            $body = DATA_GUARD . $json;
            $tmp  = DATA_DIR . '/' . $name . '-' . bin2hex(random_bytes(4)) . '.tmp.php';
            $ok   = @file_put_contents($tmp, $body) === strlen($body) && @rename($tmp, store_path($name));
            if (!$ok) {
                @unlink($tmp);
            }
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $ok;
}

/* ───────────── المحتوى ───────────── */

// دمج المحفوظ فوق الافتراضي: المفاتيح الناقصة تأخذ القيمة الافتراضية،
// والقوائم (items) تؤخذ كما حُفظت.
function merge_content(array $base, array $saved): array
{
    foreach ($base as $key => $value) {
        if (!array_key_exists($key, $saved)) {
            continue;
        }
        if (is_array($value) && is_array($saved[$key])) {
            $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
            if (!$isList) {
                $base[$key] = merge_content($value, $saved[$key]);
                continue;
            }
            // كل عنصر محفوظ يُكمَّل بمفاتيح العنصر الافتراضي الأول، فلا ينقص حقل أبداً
            $template = isset($value[0]) && is_array($value[0]) ? array_fill_keys(array_keys($value[0]), '') : null;
            $list = [];
            foreach ($saved[$key] as $item) {
                if ($template === null) {
                    $list[] = $item;
                } elseif (is_array($item)) {
                    $list[] = merge_content($template, $item);
                }
            }
            $base[$key] = $list;
        } elseif (is_string($value) && is_string($saved[$key])) {
            $base[$key] = $saved[$key];
        }
    }
    return $base;
}

function content(): array
{
    static $c = null;
    if ($c === null) {
        $c = normalize_lists(merge_content(default_content(), store_read('content')));
    }
    return $c;
}

// كل عنصر في القوائم يأخذ كل حقول القالب (نصوص فقط)، فلا ينقص حقل ولا يظهر تحذير
function normalize_lists(array $c): array
{
    foreach (list_templates() as $sec => $tpl) {
        $items = isset($c[$sec]['items']) && is_array($c[$sec]['items']) ? $c[$sec]['items'] : [];
        $list = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($tpl as $k => $def) {
                $v = $item[$k] ?? $def;
                $row[$k] = is_scalar($v) ? (string) $v : $def;
            }
            $list[] = $row;
        }
        $c[$sec]['items'] = $list;
    }
    return $c;
}

/* ───────────── اللغة ───────────── */

// الإنجليزية هي اللغة الافتراضية، والعربية عبر ?lang=ar (زر AR في الصفحة)
function lang(): string
{
    static $l = null;
    if ($l === null) {
        $l = req_str($_GET, 'lang') === 'ar' ? 'ar' : 'en';
    }
    return $l;
}

function is_en(): bool
{
    return lang() === 'en';
}

// قيمة الحقل باللغة الحالية: النسخة الإنجليزية (key_en) لو موجودة وغير فارغة، وإلا العربية
function tx(array $a, string $key): string
{
    if (is_en()) {
        $en = $a[$key . '_en'] ?? '';
        if (is_string($en) && trim($en) !== '') {
            return $en;
        }
    }
    $v = $a[$key] ?? '';
    return is_string($v) ? $v : '';
}

// نص آمن مع تلوين ما بين *نجمتين* بالأحمر
function accent(string $s): string
{
    return preg_replace('/\*([^*\n]+)\*/u', '<em>$1</em>', e($s)) ?? e($s);
}

// النص بدون نجوم التلوين (للعناوين في جوجل وما شابه)
function plain(string $s): string
{
    return trim(str_replace('*', '', $s));
}

// فقرات مفصولة بسطر فارغ
function paragraphs(string $s): array
{
    $out = [];
    foreach (preg_split('/\R\s*\R/u', trim($s)) ?: [] as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

// رابط الصفحة بلغة معيّنة
function page_url(string $lang): string
{
    return base_url() . ($lang === 'ar' ? '?lang=ar' : '');
}

/* ───────────── أدوات عامة ───────────── */

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// تحويل نص متعدد الأسطر إلى قائمة بلا أسطر فارغة
function lines_of(string $s): array
{
    $out = [];
    foreach (preg_split('/\R/u', $s) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

// يقبل روابط http/https فقط (ويضيف https:// لو نُسيت)، وإلا يرجع نصاً فارغاً
function safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    if (!preg_match('#^https?://[^\s/$.?\#][^\s<>"\']*$#i', $url)) {
        return '';
    }
    return $url;
}

function digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

function wa_link(string $number, string $text = ''): string
{
    $n = digits($number);
    if ($n === '') {
        return '';
    }
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

// 970595679770 ← +970 59 567 9770
function phone_display(string $number): string
{
    $n = digits($number);
    if (preg_match('/^(970|972)(\d{2})(\d{3})(\d{4})$/', $n, $m)) {
        return "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }
    return $n === '' ? '' : '+' . $n;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"') !== false) {
        return true;
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// رابط مجلد الموقع، ينتهي دائماً بـ "/". يُفضَّل ضبطه من لوحة التحكم ليكون ثابتاً لجوجل.
function base_url(): string
{
    // الرابط المضبوط من اللوحة يُنظَّف: بدون index.php أو ?... أو #...
    $set = safe_url(content()['seo']['site_url']);
    $p = $set !== '' ? parse_url($set) : false;
    if (is_array($p) && !empty($p['host'])) {
        $path = preg_replace('#/[^/]*\.php$#i', '/', $p['path'] ?? '/') ?? '/';
        return strtolower((string) $p['scheme']) . '://' . strtolower((string) $p['host'])
            . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($path, '/') . '/';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', $host) ?: 'localhost';
    $dir  = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir  = rtrim($dir, '/.') . '/';
    return (is_https() ? 'https' : 'http') . '://' . $host . $dir;
}

// صورة الموقع (جوجل والمشاركة واللوحة)
function avatar_path(): string
{
    $a = content()['avatar'];
    if ($a !== '' && preg_match('#^uploads/[a-z0-9_-]+\.(jpe?g|png|webp)$#', $a) && is_file(APP_ROOT . '/' . $a)) {
        return $a;
    }
    return 'assets/img/site-photo.jpg';
}

// صورة قسم النبذة داخل الدائرة المتحركة
function about_photo_path(): string
{
    $a = content()['about_photo'];
    if ($a !== '' && preg_match('#^uploads/about-[a-f0-9]+\.(jpe?g|png|webp)$#', $a) && is_file(APP_ROOT . '/' . $a)) {
        return $a;
    }
    return '';
}

// صورة الواجهة المقصوصة بخلفية شفافة
function hero_photo_path(): string
{
    $a = content()['hero_photo'];
    if ($a !== '' && preg_match('#^uploads/hero-[a-z0-9]+\.(png|webp)$#', $a) && is_file(APP_ROOT . '/' . $a)) {
        return $a;
    }
    return '';
}

// رقم إصدار للملفات الثابتة حتى يحمّل المتصفح النسخة الجديدة بعد أي تعديل
function asset(string $path): string
{
    $file = APP_ROOT . '/' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/* ───────────── الإحصائيات ───────────── */

// نطاقات Cloudflare (https://www.cloudflare.com/ips/): نثق بـ CF-Connecting-IP فقط لو جاء
// الطلب فعلاً من خوادم Cloudflare، وإلا يستطيع أي أحد تزويره. X-Forwarded-For لا نثق به أبداً.
const CF_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bytes = intdiv((int) $bits, 8);
    $rem   = (int) $bits % 8;
    if (strncmp($a, $b, $bytes) !== 0) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cf     = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        foreach (CF_RANGES as $range) {
            if (ip_in_cidr($remote, $range)) {
                return $cf;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
}

function is_bot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match('/(?<!cu)bot\b|bot\/|google-|googleother|crawl|spider|slurp|mediapartners|facebookexternalhit|facebookcatalog|embedly|quora link|whatsapp\/|telegrambot|preview|lighthouse|pagespeed|headless|phantom|curl|wget|python|java\/|okhttp|go-http|httpclient|axios|node-fetch|monitor|uptime|pingdom|scan|check|validator|feed|archive/i', $ua);
}

function device_of(string $ua): string
{
    if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobi|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) {
        return 'mobile';
    }
    // أندرويد بدون كلمة Mobile غالباً تابلت (متصفحات ماسنجر وإنستغرام تكتب Mobile فتُحسب جوالاً)
    return stripos($ua, 'android') !== false ? 'tablet' : 'desktop';
}

function source_of(string $referer, string $ownHost, string $utm): string
{
    $utm = strtolower(trim($utm));
    if ($utm !== '') {
        foreach (['google', 'facebook', 'tiktok', 'whatsapp', 'instagram', 'telegram', 'youtube'] as $s) {
            if (strpos($utm, $s) !== false) {
                return $s;
            }
        }
        return 'other';
    }
    $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
    if ($host === '') {
        return 'direct';
    }
    if ($ownHost !== '' && ($host === $ownHost || substr($host, -strlen('.' . $ownHost)) === '.' . $ownHost)) {
        return 'internal';
    }
    $map = [
        'google'    => '/(^|\.)google\.|googleusercontent|android-app:\/\/com\.google/',
        'bing'      => '/(^|\.)bing\.com$/',
        'facebook'  => '/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/',
        'instagram' => '/(^|\.)instagram\.com$/',
        'tiktok'    => '/(^|\.)tiktok\.com$/',
        'whatsapp'  => '/(^|\.)(whatsapp\.com|whatsapp\.net|wa\.me)$/',
        'telegram'  => '/(^|\.)(t\.me|telegram\.org)$/',
        'youtube'   => '/(^|\.)(youtube\.com|youtu\.be)$/',
        'x'         => '/(^|\.)(t\.co|twitter\.com|x\.com)$/',
    ];
    foreach ($map as $name => $re) {
        if (preg_match($re, $host)) {
            return $name;
        }
    }
    return 'other';
}

function stats_blank(): array
{
    return [
        'since'         => date('Y-m-d'),
        'salt'          => bin2hex(random_bytes(16)),
        'total_views'   => 0,
        'total_uniques' => 0,
        'days'          => [],
        'today'         => ['d' => '', 'h' => []],
        'sources'       => [],
        'devices'       => [],
        'referrers'     => [],
        'clicks'        => [],
    ];
}

// يصلح أي ملف إحصائيات تالف أو معدَّل يدوياً حتى لا تتعطل الصفحة بسببه
function stats_normalize(array $s): array
{
    $b = stats_blank();
    foreach ($b as $k => $v) {
        if (!isset($s[$k]) || gettype($s[$k]) !== gettype($v)) {
            $s[$k] = $v;
        }
    }
    if (!is_string($s['today']['d'] ?? null) || !is_array($s['today']['h'] ?? null)) {
        $s['today'] = $b['today'];
    }
    foreach ($s['days'] as $d => $row) {
        if (!is_array($row)) {
            unset($s['days'][$d]);
        }
    }
    return $s;
}

function stats(): array
{
    return stats_normalize(store_read('stats'));
}

// تسجيل زيارة للصفحة الرئيسية (تُتجاهل الروبوتات وزيارات صاحب الموقع نفسه)
function track_view(): void
{
    $ua = user_agent();
    if (is_bot($ua) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !empty($_COOKIE['sq_owner'])) {
        return;
    }
    $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    if (strpos($purpose, 'prefetch') !== false || strpos($purpose, 'prerender') !== false) {
        return;
    }

    $today   = date('Y-m-d');
    $referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
    $ownHost = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
    $source  = source_of($referer, $ownHost, req_str($_GET, 'utm_source'));
    $device  = device_of($ua);

    store_update('stats', function (array $s) use ($ua, $today, $referer, $source, $device) {
        $s = stats_normalize($s);
        if ($s['today']['d'] !== $today) {
            $s['today'] = ['d' => $today, 'h' => []];
        }
        $hash = substr(hash('sha256', $s['salt'] . '|' . client_ip() . '|' . $ua . '|' . $today), 0, 16);
        $isNew = !in_array($hash, $s['today']['h'], true);

        $s['total_views']++;
        $day = ($s['days'][$today] ?? []) + ['v' => 0, 'u' => 0];
        $day['v'] = (int) $day['v'] + 1;

        if ($isNew) {
            if (count($s['today']['h']) < 20000) {
                $s['today']['h'][] = $hash;
            }
            $day['u'] = (int) $day['u'] + 1;
            $s['total_uniques']++;
            if ($source !== 'internal') {
                $s['sources'][$source] = (int) ($s['sources'][$source] ?? 0) + 1;
            }
            $s['devices'][$device] = (int) ($s['devices'][$device] ?? 0) + 1;
            $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
            if ($host !== '' && $source !== 'internal') {
                $host = (string) preg_replace('/^(www|m|l|lm|mobile)\./', '', $host);
                if (isset($s['referrers'][$host]) || count($s['referrers']) < 300) {
                    $s['referrers'][$host] = (int) ($s['referrers'][$host] ?? 0) + 1;
                }
            }
        }
        $s['days'][$today] = $day;

        // الاحتفاظ بآخر 400 يوم فقط حتى يبقى الملف صغيراً
        if (count($s['days']) > 400) {
            ksort($s['days']);
            $s['days'] = array_slice($s['days'], -400, null, true);
        }
        return $s;
    });
}

// مفتاح ثابت لكل مشروع (من رابطه أو اسمه)، فلا تنتقل نقراته لمشروع آخر لو حُذف أو تغيّر الترتيب
function project_key(array $p): string
{
    $id = safe_url((string) ($p['url'] ?? ''));
    if ($id === '') {
        $id = (string) ($p['name'] ?? '');
    }
    return 'p-' . substr(md5(strtolower(rtrim($id, '/'))), 0, 10);
}

// مفاتيح النقرات المسموح تسجيلها (أي مفتاح آخر يُرفض)
function click_keys(): array
{
    $keys = [
        'whatsapp' => 'واتساب', 'facebook' => 'فيسبوك', 'tiktok' => 'تيك توك', 'instagram' => 'إنستغرام',
        'github' => 'GitHub', 'linkedin' => 'لينكدإن', 'email' => 'البريد الإلكتروني', 'phone' => 'اتصال هاتفي',
        'form' => 'نموذج التواصل', 'lang' => 'زر اللغة', 'chat' => 'زر المحادثة',
    ];
    foreach (content()['projects']['items'] as $p) {
        $keys[project_key($p)] = (string) ($p['name'] ?? '');
    }
    return $keys;
}

function track_click(string $key): void
{
    if (!array_key_exists($key, click_keys()) || is_bot(user_agent()) || !empty($_COOKIE['sq_owner'])) {
        return;
    }
    store_update('stats', function (array $s) use ($key) {
        $s = stats_normalize($s);
        $s['clicks'][$key] = (int) ($s['clicks'][$key] ?? 0) + 1;
        return $s;
    });
}

/* ───────────── رسائل نموذج التواصل ───────────── */

function messages(): array
{
    $m = store_read('messages');
    $list = isset($m['list']) && is_array($m['list']) ? $m['list'] : [];
    return array_values(array_filter($list, 'is_array'));
}

/**
 * حفظ رسالة جديدة. ترجع '' عند النجاح أو سبب الرفض.
 * حماية من الإغراق: 5 رسائل في الساعة لكل جهاز، و60 رسالة في اليوم من الجميع، وآخر 300 رسالة فقط.
 */
function message_add(array $fields): string
{
    $ipKey = substr(hash('sha256', 'msg|' . client_ip()), 0, 16);
    $now   = time();
    $err   = '';
    $ok = store_update('messages', function (array $m) use ($fields, $ipKey, $now, &$err) {
        $list = isset($m['list']) && is_array($m['list']) ? array_values(array_filter($m['list'], 'is_array')) : [];
        $mine = 0;
        $day  = 0;
        foreach ($list as $row) {
            $t = (int) ($row['t'] ?? 0);
            if ($t > $now - 86400) {
                $day++;
                if (($row['k'] ?? '') === $ipKey && $t > $now - 3600) {
                    $mine++;
                }
            }
        }
        if ($mine >= 5 || $day >= 60) {
            $err = 'limit';
            return null;
        }
        array_unshift($list, $fields + ['id' => bin2hex(random_bytes(6)), 't' => $now, 'k' => $ipKey, 'new' => 1]);
        $m['list'] = array_slice($list, 0, 300);
        return $m;
    });
    if ($err !== '') {
        return $err;
    }
    return $ok ? '' : 'save';
}
