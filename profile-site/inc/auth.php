<?php
// الدخول إلى لوحة التحكم: كلمة مرور مشفّرة، رمز إعداد لأول مرة، جلسة آمنة، حماية CSRF،
// وإيقاف مؤقت بعد المحاولات الخاطئة.

declare(strict_types=1);

const MAX_FAILS        = 5;      // محاولات خاطئة مسموحة لنفس الجهاز
const GLOBAL_MAX_FAILS = 30;     // ومن كل الأجهزة معاً خلال 15 دقيقة
const LOCK_SECONDS     = 900;    // مدة الإيقاف: 15 دقيقة
const SESSION_IDLE     = 43200;  // خروج تلقائي بعد 12 ساعة بلا نشاط
const SETUP_FILE       = DATA_DIR . '/setup-code.php';

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // جلسات اللوحة تُحفظ داخل data/sessions، حتى لا تحذفها الاستضافة بعد 24 دقيقة
    // فيضيع ما كتبته في صفحة التعديل قبل الحفظ
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && !is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE);

    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
    session_name('sq_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $path,
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if (!empty($_SESSION['ok']) && (time() - (int) ($_SESSION['seen'] ?? 0)) > SESSION_IDLE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function auth(): array
{
    $a = array_merge(['hash' => '', 'fails' => []], store_read('auth'));
    if (!is_string($a['hash'])) {
        $a['hash'] = '';
    }
    if (!is_array($a['fails'])) {
        $a['fails'] = [];
    }
    return $a;
}

/**
 * none   = أول تشغيل، لم تُنشأ كلمة مرور بعد
 * ok     = توجد كلمة مرور
 * broken = ملف كلمة المرور موجود لكنه تالف: تبقى اللوحة مقفلة ولا يُعرض نموذج الإعداد لأي أحد
 */
function auth_state(): string
{
    if (auth()['hash'] !== '') {
        return 'ok';
    }
    $f = store_path('auth');
    if (is_file($f) && store_read('auth') === [] && (int) @filesize($f) > strlen(DATA_GUARD) + 2) {
        return 'broken';
    }
    return 'none';
}

function has_password(): bool
{
    return auth_state() !== 'none';
}

// بصمة كلمة المرور الحالية: تغيير كلمة المرور يُخرج كل الجلسات الأخرى
function pw_ver(): string
{
    return substr(hash('sha256', auth()['hash']), 0, 16);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['ok']) && hash_equals(pw_ver(), (string) ($_SESSION['pv'] ?? ''));
}

/* ───────── رمز الإعداد لأول مرة ─────────
 * حتى لا يسبقك أحد إلى اختيار كلمة المرور، يُنشأ رمز عشوائي داخل ملف
 * data/setup-code.php لا يقرؤه إلا من يملك مدير ملفات الاستضافة. */

function setup_code(): string
{
    if (is_file(SETUP_FILE) && preg_match('/SETUP-CODE:\s*([A-Z0-9]{8})/', (string) @file_get_contents(SETUP_FILE), $m)) {
        return $m[1];
    }
    ensure_dirs();
    $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
    $body = "<?php\n"
        . "// رمز إعداد لوحة التحكم: انسخ الرمز التالي والصقه في صفحة admin.php\n"
        . "// SETUP-CODE: {$code}\n"
        . "// يُحذف هذا الملف تلقائياً بعد إنشاء كلمة المرور.\n"
        . "http_response_code(404);\nexit;\n";
    return @file_put_contents(SETUP_FILE, $body) !== false ? $code : '';
}

function setup_code_ok(string $sent): bool
{
    $code = setup_code();
    return $code !== '' && hash_equals($code, strtoupper(trim($sent)));
}

function remove_setup_code(): void
{
    @unlink(SETUP_FILE);
}

/* ───────── CSRF ───────── */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = req_str($_POST, 'csrf');
    return $sent !== '' && !empty($_SESSION['csrf']) && is_string($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ───────── الحد من محاولات الدخول ───────── */

function ip_key(): string
{
    $ip  = client_ip();
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        $ip = bin2hex(substr($bin, 0, 8)); // عناوين IPv6: الشبكة /64 كاملة تُعامل كجهاز واحد
    }
    return substr(hash('sha256', 'login|' . $ip), 0, 20);
}

/**
 * تفحص الإيقاف وتحجز محاولة واحدة تحت نفس القفل، قبل فحص كلمة المرور.
 * ترجع عدد ثواني الانتظار (0 = المحاولة مسموحة وقد احتُسبت).
 * المحاولات من عناوين كثيرة لها سقف عام، وسجل المحاولات لا يتجاوز 500 سطر.
 */
function reserve_attempt(): int
{
    $key  = ip_key();
    $wait = 0;
    $saved = store_update('auth', function (array $a) use ($key, &$wait) {
        $a = array_merge(['hash' => '', 'fails' => []], $a);
        if (!is_array($a['fails'])) {
            $a['fails'] = [];
        }
        $now = time();
        $recent = 0;
        foreach ($a['fails'] as $k => $f) {
            if (!is_array($f) || (int) ($f['t'] ?? 0) < $now - 86400) {
                unset($a['fails'][$k]);
            } elseif ($k !== '*' && (int) ($f['t'] ?? 0) > $now - LOCK_SECONDS) {
                $recent += (int) ($f['c'] ?? 0);
            }
        }
        $g = ($a['fails']['*'] ?? []) + ['until' => 0, 't' => 0];
        $f = ($a['fails'][$key] ?? []) + ['n' => 0, 'c' => 0, 'until' => 0, 't' => 0];
        // جهاز صاحب الموقع (دخل منه سابقاً) لا يتأثر بالإيقاف العام، فقط بإيقاف جهازه نفسه
        $trusted = is_trusted_device($a);
        $wait = max(0, (int) $f['until'] - $now, $trusted ? 0 : (int) $g['until'] - $now);
        if ($wait > 0) {
            return null;
        }
        if (!$trusted && $recent >= GLOBAL_MAX_FAILS) {
            $a['fails']['*'] = ['until' => $now + LOCK_SECONDS, 't' => $now];
            $wait = LOCK_SECONDS;
            return $a;
        }
        $f['n'] = (int) $f['n'] + 1;
        $f['c'] = (int) $f['c'] + 1;
        $f['t'] = $now;
        if ($f['n'] >= MAX_FAILS) {
            $f['until'] = $now + LOCK_SECONDS;
            $f['n'] = 0;
        }
        $a['fails'][$key] = $f;
        if (count($a['fails']) > 500) {
            uasort($a['fails'], function ($x, $y) {
                return (int) ($y['t'] ?? 0) <=> (int) ($x['t'] ?? 0);
            });
            $a['fails'] = array_slice($a['fails'], 0, 500, true);
        }
        return $a;
    });
    // لو تعذّر تسجيل المحاولة (مساحة ممتلئة مثلاً) نرفضها احتياطاً
    return $saved ? $wait : max($wait, 60);
}

// جهاز موثوق: كوكي عشوائي يُعطى عند الدخول الصحيح، ونحفظ بصمته فقط (آخر 10 أجهزة)
function is_trusted_device(array $a): bool
{
    $token = req_str($_COOKIE, 'sq_dev');
    if ($token === '' || !isset($a['devices']) || !is_array($a['devices'])) {
        return false;
    }
    return in_array(hash('sha256', $token), $a['devices'], true);
}

function trust_this_device(): void
{
    $token = bin2hex(random_bytes(24));
    $ok = store_update('auth', function (array $a) use ($token) {
        $a = array_merge(['hash' => '', 'fails' => [], 'devices' => []], $a);
        $list = is_array($a['devices']) ? $a['devices'] : [];
        $list[] = hash('sha256', $token);
        $a['devices'] = array_slice($list, -10);
        return $a;
    });
    if ($ok) {
        setcookie('sq_dev', $token, [
            'expires'  => time() + 31536000,
            'path'     => rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// بعد دخول صحيح: نمسح سجل هذا الجهاز
function clear_fails(): void
{
    $key = ip_key();
    store_update('auth', function (array $a) use ($key) {
        $a = array_merge(['hash' => '', 'fails' => []], $a);
        if (!is_array($a['fails']) || !isset($a['fails'][$key])) {
            return null;
        }
        unset($a['fails'][$key]);
        return $a;
    });
}

function set_password(string $password): bool
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    return store_update('auth', function (array $a) use ($hash) {
        $a = array_merge(['hash' => '', 'fails' => []], $a);
        $a['hash'] = $hash;
        return $a;
    });
}

function login_now(): void
{
    session_regenerate_id(true);
    $_SESSION['ok'] = true;
    $_SESSION['pv'] = pw_ver();
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (!is_trusted_device(auth())) {
        trust_this_device();
    }
    // زيارات صاحب الموقع من هذا الجهاز لا تُحسب في الإحصائيات
    setcookie('sq_owner', '1', [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // أول دخول عبر https: نثبّت رابط الصفحة النهائي لجوجل لو لم يُضبط بعد
    if (is_https() && content()['seo']['site_url'] === '') {
        $url = base_url();
        store_update('content', function (array $c) use ($url) {
            $c = merge_content(default_content(), $c);
            if ($c['seo']['site_url'] !== '') {
                return null;
            }
            $c['seo']['site_url'] = $url;
            return $c;
        });
    }
}

// بعد تغيير كلمة المرور: الجلسة الحالية تبقى، وكل الجلسات الأخرى تخرج
function refresh_login(): void
{
    session_regenerate_id(true);
    $_SESSION['pv'] = pw_ver();
}

function logout_now(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['m' => $msg, 't' => $type];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}
