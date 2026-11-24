<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Brand;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\Throttle;
use App\Platform\PlatformSms;
use App\Platform\TenantProvisioner;
use App\Services\CronService;
use App\Services\TweetSms;
use Throwable;

/**
 * صفحات المنصة العامة: الرئيسية، إنشاء حساب بتجربة مجانية، الدخول الموحّد، والمهمة المجدولة لكل الحسابات.
 */
final class PlatformController extends Controller
{
    private function pane(): array
    {
        return ['paneTitle' => Brand::NAME, 'paneMark' => Brand::mark(96, 'login-brand-mark'), 'paneSub' => Brand::TAGLINE];
    }

    public function home(Request $request): Response
    {
        // صفحة الهبوط موقوفة من لوحة المسؤول: يُفتح تسجيل الدخول مباشرة
        if (!\App\Platform\Landing::enabled()) {
            return redirect('/login');
        }
        return view(\App\Platform\Landing::style() === 'classic' ? 'platform/home-classic' : 'platform/home', [], 'landing');
    }

    // ------------------------------------------------------------------
    // إنشاء حساب
    // ------------------------------------------------------------------

    public function signupForm(Request $request): Response
    {
        if (Platform::setting('platform.signup') !== '1') {
            return view('platform/closed', $this->pane(), 'split');
        }
        if ($request->bool('edit') && ($pending = $this->pendingSignup()) !== null) {
            // العودة من خطوة التحقق لتعديل البيانات: تُعبّأ الحقول من جديد دون كلمة المرور
            Session::forget('signup_pending');
            Session::flashInput(array_diff_key($pending, ['password_hash' => 1, 'ip' => 1]));
            return redirect('/signup');
        }
        return view('platform/signup', $this->pane() + ['trialDays' => (int) Platform::setting('platform.trial_days')], 'split');
    }

    /** هل يُطلب تأكيد رقم الهاتف عند التسجيل (مفعّل والرسائل النصية مضبوطة) */
    public static function phoneVerification(): bool
    {
        return PlatformSms::enabled('verify');
    }

    /** التحقق من رمز تأكيد التسجيل: آخر رمز غير منتهٍ، وخمس محاولات كحد أقصى */
    public static function phoneCodeValid(string $phone, string $code): bool
    {
        return PlatformSms::codeValid($phone, $code, 'signup');
    }

    /** بيانات التسجيل التي تنتظر تأكيد رقم الهاتف (ربع ساعة كحد أقصى) */
    private function pendingSignup(): ?array
    {
        $p = Session::get('signup_pending');
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > 900 || !is_array($p['data'] ?? null)) {
            Session::forget('signup_pending');
            return null;
        }
        return $p['data'];
    }

    /** حد إرسال الرموز: لكل عنوان ولكل رقم في الساعة */
    private static function codeRate(string $kind, string $ip, string $phone): bool
    {
        return Throttle::rate($kind . '-code:ip:' . $ip, 6, 3600) && Throttle::rate($kind . '-code:phone:' . $phone, 3, 3600);
    }

    /** خطوة التحقق: إدخال الرمز المرسل إلى الهاتف */
    public function signupVerifyForm(Request $request): Response
    {
        $pending = $this->pendingSignup();
        if ($pending === null || Platform::setting('platform.signup') !== '1') {
            return redirect('/signup');
        }
        return view('platform/verify', $this->pane() + ['phone' => PlatformSms::mask((string) $pending['phone'])], 'split');
    }

    /** إعادة إرسال رمز التحقق إلى رقم التسجيل المنتظر */
    public function signupCode(Request $request): Response
    {
        $pending = $this->pendingSignup();
        if ($pending === null || !self::phoneVerification()) {
            return json_response(['ok' => false, 'message' => 'انتهت مهلة التسجيل. أعد إدخال بياناتك.'], 422);
        }
        $phone = (string) TweetSms::normalize((string) $pending['phone']);
        if (!self::codeRate('signup', $request->ip(), $phone)) {
            return json_response(['ok' => false, 'message' => 'طلبت رموزاً كثيرة. حاول لاحقاً.'], 429);
        }
        Session::release();
        $r = PlatformSms::sendCode($phone, 'signup', $request->ip());
        return json_response(['ok' => $r['ok'], 'message' => $r['ok'] ? 'أرسلنا رمز التحقق إلى هاتفك.' : 'تعذّر إرسال الرمز. حاول لاحقاً.'], $r['ok'] ? 200 : 422);
    }

    /** هل اسم المستخدم مستعمل في أي حساب على المنصة (بلا تمييز لحالة الأحرف) */
    public static function usernameTaken(string $username): bool
    {
        foreach (self::employeesNamed(trim($username)) as $_) {
            return true;
        }
        return false;
    }

    /** فحص اسم المستخدم أثناء الكتابة في صفحة التسجيل */
    public function checkUsername(Request $request): Response
    {
        $u = trim($request->str('username'));
        if ($u === '' || mb_strlen($u) > 60 || !preg_match('/^[A-Za-z0-9._@-]+$/', $u)) {
            return json_response(['ok' => false, 'state' => 'invalid', 'message' => 'يقبل الأحرف الإنجليزية والأرقام والرموز . _ - @ فقط.']);
        }
        if (!Throttle::rate('uname:' . $request->ip(), 90, 600)) {
            return json_response(['ok' => false, 'state' => 'wait', 'message' => 'طلبات كثيرة. حاول بعد قليل.'], 429);
        }
        Session::release();
        return self::usernameTaken($u)
            ? json_response(['ok' => false, 'state' => 'taken', 'message' => 'اسم المستخدم مستخدم'])
            : json_response(['ok' => true, 'state' => 'free', 'message' => 'اسم المستخدم متاح']);
    }

    public function checkSlug(Request $request): Response
    {
        $slug = strtolower($request->str('slug'));
        if ($slug === '' && $request->str('name') !== '') {
            $base = TenantProvisioner::suggest($request->str('name'));
            $slug = $base;
            for ($i = 2; TenantProvisioner::slugError($slug) !== null && $i < 50; $i++) {
                $slug = substr($base, 0, 34) . '-' . $i;
            }
        }
        $error = TenantProvisioner::slugError($slug);
        return json_response(['ok' => $error === null, 'slug' => $slug, 'message' => $error ?? 'متاح', 'url' => absolute_url('/' . $slug)]);
    }

    public function signup(Request $request): Response
    {
        if (Platform::setting('platform.signup') !== '1') {
            return redirect('/signup');
        }
        $ip = $request->ip();
        if (!Throttle::rate('signup:' . $ip, 5, 3600)) {
            return $this->backWithErrors($request, ['slug' => 'تم تجاوز عدد محاولات التسجيل. حاول لاحقاً.'], '/signup');
        }
        $slug = strtolower(trim($request->str('slug')));
        $verify = self::phoneVerification();
        $d = $this->validate($request, [
            'name'       => 'required|max:100',
            'slug'       => 'required|max:40',
            'owner_name' => 'required|max:120',
            'phone'      => 'required|max:30',
            'email'      => 'nullable|email|max:120',
            'username'   => 'required|username|max:60',
            'password'   => 'required|min:8|max:100|confirmed',
        ], [
            'name' => 'اسم الشبكة', 'slug' => 'اسم الحساب', 'owner_name' => 'الاسم الكامل', 'phone' => 'رقم الهاتف',
            'email' => 'البريد الإلكتروني', 'username' => 'اسم المستخدم', 'password' => 'كلمة المرور',
        ], function ($v) use ($slug, $request, $verify) {
            if (($e = TenantProvisioner::slugError($slug)) !== null) {
                $v->addError('slug', $e);
            }
            if ($verify && TweetSms::normalize($request->str('phone')) === null) {
                $v->addError('phone', 'رقم الهاتف غير صالح. اكتبه بالصيغة 05XXXXXXXX.');
            }
            $u = trim($request->str('username'));
            if ($u !== '' && preg_match('/^[A-Za-z0-9._@-]+$/', $u) && self::usernameTaken($u)) {
                $v->addError('username', 'اسم المستخدم مستخدم، اختر اسماً آخر.');
            }
        });
        // الرقم الصالح يُحفظ بصيغته المحلية الموحّدة 05XXXXXXXX، وغيره يُحفظ كما كُتب
        $mobile = TweetSms::normalize((string) $d['phone']);
        $data = [
            'slug' => $slug, 'name' => $d['name'], 'owner_name' => $d['owner_name'], 'phone' => $mobile !== null ? '0' . substr($mobile, 3) : latin_digits(trim((string) $d['phone'])),
            'email' => $d['email'], 'username' => $d['username'], 'password_hash' => password_hash((string) $request->input('password'), PASSWORD_DEFAULT), 'ip' => $ip,
        ];
        if (!$verify) {
            return $this->createAccount($request, $data, false);
        }
        // تأكيد رقم الهاتف: تُحفظ البيانات مؤقتاً ويُرسل الرمز، ثم يُنشأ الحساب بعد إدخاله
        $phone = (string) TweetSms::normalize($data['phone']);
        if (!self::codeRate('signup', $ip, $phone)) {
            return $this->backWithErrors($request, ['phone' => 'طلبت رموزاً كثيرة لهذا الرقم. حاول لاحقاً.'], '/signup');
        }
        Session::set('signup_pending', ['at' => time(), 'data' => $data]);
        Session::release();
        $sent = PlatformSms::sendCode($phone, 'signup', $ip);
        Session::resume();
        if (!$sent['ok']) {
            Session::forget('signup_pending');
            return $this->backWithErrors($request, ['phone' => 'تعذّر إرسال رمز التحقق إلى هذا الرقم. تأكد من الرقم وحاول مجدداً.'], '/signup');
        }
        return redirect('/signup/verify');
    }

    /** إدخال رمز التحقق ثم إنشاء الحساب */
    public function signupVerify(Request $request): Response
    {
        $pending = $this->pendingSignup();
        if ($pending === null || Platform::setting('platform.signup') !== '1') {
            flash('error', 'انتهت مهلة التسجيل. أعد إدخال بياناتك.');
            return $request->wantsJson()
                ? json_response(['ok' => false, 'message' => 'انتهت مهلة التسجيل. أعد إدخال بياناتك.', 'redirect' => url('/signup')], 422)
                : redirect('/signup');
        }
        if (!Throttle::rate('signup-verify:' . $request->ip(), 20, 3600)
            || !PlatformSms::codeValid((string) $pending['phone'], $request->str('phone_code'), 'signup')) {
            return $this->backWithErrors($request, ['phone_code' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.'], '/signup/verify');
        }
        Session::forget('signup_pending');
        $done = $this->createAccount($request, $pending, true);
        if (!$request->wantsJson()) {
            return $done;
        }
        // مربعات الرمز تُظهر حركة النجاح ثم تنتقل إلى الحساب الجديد
        $to = (string) ($done->headers['Location'] ?? '');
        $ok = str_contains($to, '/auth/ticket/');
        return json_response(['ok' => $ok, 'redirect' => $to, 'message' => $ok ? 'تم تأكيد رقم الهاتف.' : 'تعذّر إنشاء الحساب حالياً. حاول لاحقاً.'], $ok ? 200 : 422);
    }

    /** إنشاء الحساب بعد اكتمال البيانات (والتحقق إن كان مطلوباً)، ثم الترحيب والدخول إليه */
    private function createAccount(Request $request, array $d, bool $verified): Response
    {
        try {
            $tenant = TenantProvisioner::create([
                'slug' => $d['slug'], 'name' => $d['name'], 'owner_name' => $d['owner_name'], 'phone' => $d['phone'],
                'email' => $d['email'], 'username' => $d['username'], 'password' => '', 'password_hash' => $d['password_hash'], 'ip' => $d['ip'],
            ]);
        } catch (Throwable $e) {
            Session::flashInput(array_diff_key($d, ['password_hash' => 1, 'ip' => 1]), ['slug' => $e->getMessage()]);
            flash('error', $e->getMessage());
            return redirect('/signup');
        }
        if ($verified) {
            DB::update('tenants', ['phone_verified_at' => now()], 'id = ?', [$tenant['id']]);
        }
        Platform::log((int) $tenant['id'], 'tenant', 'tenant.signup', 'إنشاء حساب جديد: ' . $tenant['name'] . ' (' . $tenant['slug'] . ')', $d['owner_name']);
        Session::release();
        \App\Services\AdminNotify::send('signup', \App\Services\Telegram::esc($tenant['name']) . ' (' . \App\Services\Telegram::esc($tenant['slug']) . ')' . "\n"
            . \App\Services\Telegram::esc($d['owner_name']) . ' · ' . \App\Services\Telegram::esc($d['phone']) . ($verified ? ' ✓' : ''));
        PlatformSms::welcome($tenant, (string) $d['username']);
        \App\Platform\PlatformMail::welcome($tenant, (string) $d['username']);
        $token = Platform::ticket((int) $tenant['id'], (int) $tenant['owner_id'], 'signup');
        return redirect('/' . $tenant['slug'] . '/auth/ticket/' . $token);
    }

    // ------------------------------------------------------------------
    // الدخول الموحّد: اسم المستخدم وكلمة المرور فقط، والنظام يجد الشبكة
    // ------------------------------------------------------------------

    public function loginForm(Request $request): Response
    {
        $choices = Session::get('login_choices');
        $networks = [];
        if (is_array($choices) && time() - (int) ($choices['at'] ?? 0) < 300) {
            foreach ((array) $choices['items'] as $tenantId => $empId) {
                $t = DB::row('SELECT id, name, slug, status FROM tenants WHERE id = ?', [(int) $tenantId]);
                if ($t) {
                    $networks[] = $t;
                }
            }
        } else {
            Session::forget('login_choices');
        }
        return view('platform/login', $this->pane() + ['networks' => $networks], 'split');
    }

    /**
     * المستخدمون بهذا الاسم في كل الحسابات غير الملغاة، الأحدث نشاطاً أولاً.
     * اتصال واحد بخادم قواعد الحسابات واستعلام مفهرس لكل حساب.
     * @return \Generator<int, array{0:array, 1:array}> [الحساب، المستخدم]
     */
    private static function employeesNamed(string $username): \Generator
    {
        $cfg = Platform::tenantDb();
        try {
            $pdo = new \PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['user'], $cfg['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            log_message('error', 'platform login: ' . $e->getMessage());
            return;
        }
        foreach (DB::rows("SELECT * FROM tenants WHERE status <> 'cancelled' ORDER BY COALESCE(last_seen_at, created_at) DESC") as $t) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $t['db_name'])) {
                continue;
            }
            try {
                $st = $pdo->prepare('SELECT id, username, password_hash, phone, status, is_owner FROM `' . $t['db_name'] . '`.employees WHERE username = ? LIMIT 1');
                $st->execute([$username]);
                $emp = $st->fetch();
            } catch (Throwable) {
                continue;
            }
            if ($emp) {
                yield [$t, $emp];
            }
        }
    }

    /**
     * الحسابات التي فيها هذا المستخدم بكلمة المرور الصحيحة (التحقق من كلمة المرور للمطابقين بالاسم فقط).
     * @return list<array{tenant:array, emp:array}>
     */
    public static function findAccounts(string $username, string $password): array
    {
        $found = [];
        $checked = 0;
        foreach (self::employeesNamed($username) as [$t, $emp]) {
            if (++$checked > 25) {
                break;
            }
            if (password_verify($password, (string) $emp['password_hash'])) {
                $found[] = ['tenant' => $t, 'emp' => $emp];
            }
        }
        return $found;
    }

    /**
     * الحسابات التي فيها مستخدم فعّال بهذا الاسم ورقم هاتفه المسجّل هو هذا الرقم:
     * رقم المستخدم نفسه، وللمالك الذي لم يسجّل رقماً في حسابه رقمُ الحساب عند التسجيل.
     * @return list<array{tenant:array, emp:array}>
     */
    public static function findByPhone(string $username, string $phone): array
    {
        $phone = TweetSms::normalize($phone);
        if ($phone === null || trim($username) === '') {
            return [];
        }
        $found = [];
        $checked = 0;
        foreach (self::employeesNamed(trim($username)) as [$t, $emp]) {
            if (++$checked > 25) {
                break;
            }
            if ($emp['status'] !== 'active') {
                continue;
            }
            $own = TweetSms::normalize((string) $emp['phone']);
            if ($own === null && (int) $emp['is_owner'] === 1) {
                $own = TweetSms::normalize((string) $t['phone']);
            }
            if ($own !== null && hash_equals($own, $phone)) {
                $found[] = ['tenant' => $t, 'emp' => $emp];
            }
        }
        return $found;
    }

    public function login(Request $request): Response
    {
        $username = trim($request->str('username'));
        $password = (string) $request->input('password', '');
        Session::flashInput(['username' => $username]);
        Session::forget('login_choices');
        if ($username === '' || $password === '') {
            flash('error', 'أدخل اسم المستخدم وكلمة المرور.');
            return redirect('/login');
        }
        $ip = $request->ip();
        $userKey = 'plogin:user:' . mb_strtolower($username);
        foreach (['plogin:ip:' . $ip, $userKey] as $k) {
            if (($wait = Throttle::lockedFor($k)) > 0) {
                flash('error', 'تم إيقاف الدخول مؤقتاً بسبب كثرة المحاولات. حاول بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.');
                return redirect('/login');
            }
        }
        $matches = self::findAccounts($username, $password);
        if (!$matches) {
            Throttle::fail('plogin:ip:' . $ip, 30, 900, 900);
            Throttle::fail($userKey, 8, 900, 900);
            flash('error', 'اسم المستخدم أو كلمة المرور غير صحيحة.');
            return redirect('/login');
        }
        $active = array_values(array_filter($matches, static fn ($m) => $m['emp']['status'] === 'active'));
        if (!$active) {
            flash('error', 'هذا المستخدم موقوف.');
            return redirect('/login');
        }
        Throttle::clear($userKey);
        Session::pull('_old');
        $usable = array_values(array_filter($active, static fn ($m) => Tenant::usable($m['tenant'])));
        $pool = $usable ?: $active;
        $groups = array_unique(array_map(static fn ($m) => (int) ($m['tenant']['group_id'] ?? 0) ?: -(int) $m['tenant']['id'], $pool));
        if (count($pool) > 1 && count($groups) > 1) {
            // الاسم وكلمة المرور نفساهما في شبكات غير مرتبطة: يختار المستخدم شبكته
            $items = [];
            foreach ($pool as $m) {
                $items[(int) $m['tenant']['id']] = (int) $m['emp']['id'];
            }
            Session::set('login_choices', ['at' => time(), 'items' => $items]);
            return redirect('/login');
        }
        return $this->enter($pool[0]['tenant'], (int) $pool[0]['emp']['id']);
    }

    /** اختيار الشبكة بعد التحقق من كلمة المرور */
    public function loginChoose(Request $request): Response
    {
        $choices = Session::pull('login_choices');
        $id = $request->int('tenant_id');
        if (!is_array($choices) || time() - (int) ($choices['at'] ?? 0) >= 300 || !isset($choices['items'][$id])) {
            flash('error', 'انتهت مهلة الاختيار. سجّل الدخول من جديد.');
            return redirect('/login');
        }
        $tenant = DB::row('SELECT * FROM tenants WHERE id = ?', [$id]);
        if (!$tenant) {
            return redirect('/login');
        }
        return $this->enter($tenant, (int) $choices['items'][$id]);
    }

    private function enter(array $tenant, int $employeeId): Response
    {
        if (!Tenant::usable($tenant)) {
            return redirect('/' . $tenant['slug'] . '/');
        }
        return redirect('/' . $tenant['slug'] . '/auth/ticket/' . Platform::ticket((int) $tenant['id'], $employeeId, 'login'));
    }

    // ------------------------------------------------------------------
    // استعادة كلمة المرور: اسم المستخدم ورقم الهاتف ← رمز تحقق ← كلمة مرور جديدة
    // ------------------------------------------------------------------

    public function forgotForm(Request $request): Response
    {
        if (!PlatformSms::enabled('reset')) {
            return redirect('/login');
        }
        return view('platform/forgot', $this->pane(), 'split');
    }

    public function forgotSend(Request $request): Response
    {
        if (!PlatformSms::enabled('reset')) {
            return redirect('/login');
        }
        $username = trim($request->str('username'));
        $phone = TweetSms::normalize($request->str('phone'));
        $ip = $request->ip();
        $fail = static function (string $message) use ($username, $request): Response {
            Session::resume();
            Session::forget('password_reset');
            Session::flashInput(['username' => $username, 'phone' => $request->str('phone')]);
            flash('error', $message);
            return redirect('/auth/forgot');
        };
        if ($username === '' || $phone === null) {
            return $fail('أدخل اسم المستخدم ورقم الهاتف المسجّل في الحساب.');
        }
        if (!Throttle::rate('reset:ip:' . $ip, 8, 3600) || !Throttle::rate('reset:user:' . mb_strtolower($username), 5, 3600)) {
            return $fail('محاولات كثيرة. حاول لاحقاً.');
        }
        if (!self::findByPhone($username, $phone)) {
            return $fail('اسم المستخدم أو رقم الهاتف غير مطابق لأي حساب.');
        }
        if (!self::codeRate('reset', $ip, $phone)) {
            return $fail('طلبت رموزاً كثيرة. حاول لاحقاً.');
        }
        Session::set('password_reset', ['at' => time(), 'username' => $username, 'phone' => $phone]);
        Session::release();
        $sent = PlatformSms::sendCode($phone, 'reset', $ip);
        if (!$sent['ok']) {
            return $fail('تعذّر إرسال رمز التحقق. حاول لاحقاً.');
        }
        return redirect('/auth/reset');
    }

    /** طلب استعادة ينتظر الرمز (ربع ساعة كحد أقصى) */
    private function pendingReset(): ?array
    {
        $p = Session::get('password_reset');
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > 900 || empty($p['username']) || empty($p['phone'])) {
            Session::forget('password_reset');
            return null;
        }
        return $p;
    }

    public function resetForm(Request $request): Response
    {
        $p = PlatformSms::enabled('reset') ? $this->pendingReset() : null;
        if ($p === null) {
            return redirect('/auth/forgot');
        }
        return view('platform/reset', $this->pane() + ['phone' => PlatformSms::mask((string) $p['phone'])], 'split');
    }

    /** إعادة إرسال رمز الاستعادة */
    public function resetResend(Request $request): Response
    {
        $p = PlatformSms::enabled('reset') ? $this->pendingReset() : null;
        if ($p === null) {
            return json_response(['ok' => false, 'message' => 'انتهت المهلة. ابدأ من جديد.'], 422);
        }
        if (!self::codeRate('reset', $request->ip(), (string) $p['phone'])) {
            return json_response(['ok' => false, 'message' => 'طلبت رموزاً كثيرة. حاول لاحقاً.'], 429);
        }
        Session::release();
        $r = PlatformSms::sendCode((string) $p['phone'], 'reset', $request->ip());
        return json_response(['ok' => $r['ok'], 'message' => $r['ok'] ? 'أرسلنا رمز التحقق إلى هاتفك.' : 'تعذّر إرسال الرمز. حاول لاحقاً.'], $r['ok'] ? 200 : 422);
    }

    public function resetSave(Request $request): Response
    {
        $p = PlatformSms::enabled('reset') ? $this->pendingReset() : null;
        if ($p === null) {
            flash('error', 'انتهت المهلة. ابدأ من جديد.');
            return redirect('/auth/forgot');
        }
        $back = static function (array $old, string $field, string $message): Response {
            Session::flashInput($old, [$field => $message]);
            flash('error', $message);
            return redirect('/auth/reset');
        };
        // كلمة المرور تُفحص قبل الرمز حتى لا يُستهلك الرمز بسبب خطأ في كتابتها، ويبقى الرمز المكتوب في حقله
        $code = $request->str('phone_code');
        $pass = (string) $request->input('password', '');
        if (mb_strlen($pass) < 8 || mb_strlen($pass) > 100) {
            return $back(['phone_code' => $code], 'password', 'كلمة المرور يجب ألا تقل عن 8 أحرف.');
        }
        if ($pass !== (string) $request->input('password_confirmation', '')) {
            return $back(['phone_code' => $code], 'password', 'تأكيد كلمة المرور غير مطابق.');
        }
        if (!Throttle::rate('reset-verify:' . $request->ip(), 20, 3600) || !PlatformSms::codeValid((string) $p['phone'], $code, 'reset')) {
            return $back([], 'phone_code', 'رمز التحقق غير صحيح أو منتهي الصلاحية.');
        }
        $changed = self::setPassword((string) $p['username'], (string) $p['phone'], $pass);
        Session::forget('password_reset');
        if ($changed === 0) {
            flash('error', 'اسم المستخدم أو رقم الهاتف غير مطابق لأي حساب.');
            return redirect('/auth/forgot');
        }
        Throttle::clear('plogin:user:' . mb_strtolower((string) $p['username']));
        Session::flashInput(['username' => $p['username']]);
        flash('success', 'تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة.');
        return redirect('/login');
    }

    /**
     * تعيين كلمة مرور جديدة للمستخدم في كل حساباته المطابقة للاسم ورقم الهاتف (شبكات المالك الواحد تبقى بكلمة واحدة).
     * يُخرج كل الجلسات والأجهزة المسجّلة كما في تغيير كلمة المرور من داخل النظام.
     * @return int عدد الحسابات التي تغيّرت فيها
     */
    public static function setPassword(string $username, string $phone, string $password): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $n = 0;
        foreach (self::findByPhone($username, $phone) as $m) {
            try {
                $pdo = Tenant::pdo($m['tenant']);
                $pdo->prepare('UPDATE employees SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?')->execute([$hash, now(), $m['emp']['id']]);
                $pdo->prepare('UPDATE employee_devices SET revoked_at = ? WHERE employee_id = ? AND revoked_at IS NULL')->execute([now(), $m['emp']['id']]);
                Platform::log((int) $m['tenant']['id'], 'tenant', 'tenant.password_reset', 'استعادة كلمة المرور برمز الهاتف للمستخدم ' . $m['emp']['username'], (string) $m['emp']['username']);
                $n++;
            } catch (Throwable $e) {
                log_message('error', 'password reset ' . $m['tenant']['slug'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // المهمة المجدولة لكل الحسابات
    // ------------------------------------------------------------------

    public function cron(Request $request): Response
    {
        $key = Platform::cronKey();
        if ($key === '' || !hash_equals($key, $request->str('key'))) {
            return json_response(['ok' => false, 'message' => 'مفتاح غير صحيح.'], 403);
        }
        Session::release();
        return json_response(self::runAll(50.0, 'web'));
    }

    /** فحص الصحة لأدوات المراقبة: يعمل الموقع وقاعدة البيانات */
    public function health(Request $request): Response
    {
        try {
            DB::value('SELECT 1');
            return json_response(['ok' => true, 'time' => time()]);
        } catch (Throwable) {
            return json_response(['ok' => false, 'time' => time()], 503);
        }
    }

    /**
     * تشغيل المهمة المجدولة على كل الحسابات الصالحة بالتناوب ضمن وقت محدد.
     * @return array{ok:bool, tenants:int, ran:int, expired:int, duration:float}
     */
    public static function runAll(float $budget, string $source): array
    {
        $started = microtime(true);
        $life = \App\Platform\Billing::lifecycle();
        $expired = $life['expired'];
        DB::query('DELETE FROM login_tickets WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        DB::query('DELETE FROM phone_codes WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 2 * 86400)]);
        DB::query('DELETE FROM platform_mail_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 180 * 86400)]);
        try {
            \App\Platform\Insights::scheduled();
        } catch (Throwable $e) {
            log_message('error', 'weekly report: ' . $e->getMessage());
        }
        try {
            \App\Services\BotAgent::cleanup();
        } catch (Throwable $e) {
            log_message('error', 'bot cleanup: ' . $e->getMessage());
        }
        $tenants = DB::rows("SELECT * FROM tenants WHERE status IN ('trial', 'active') ORDER BY COALESCE(last_cron_at, '1970-01-01') LIMIT 500");
        $ran = 0;
        foreach ($tenants as $t) {
            $left = $budget - (microtime(true) - $started);
            if ($left < 6) {
                break;
            }
            Platform::exec('UPDATE tenants SET last_cron_at = ? WHERE id = ?', [now(), $t['id']]);
            try {
                Tenant::enter($t);
                \App\Core\Migrator::run();
                CronService::run(min(25.0, $left - 3), $source);
                \App\Platform\Billing::refreshStats($t);
                $ran++;
            } catch (Throwable $e) {
                log_message('error', 'tenant cron ' . $t['slug'] . ': ' . $e->getMessage());
            } finally {
                Tenant::leave();
            }
        }
        return ['ok' => true, 'tenants' => count($tenants), 'ran' => $ran, 'expired' => $expired, 'duration' => round(microtime(true) - $started, 2)];
    }
}
