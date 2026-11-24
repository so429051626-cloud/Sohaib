<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CardService;
use App\Services\CustomAccounts;
use App\Services\LoginRegistry;
use App\Services\Units;
use RuntimeException;

/**
 * الحسابات الخاصة (ضمن قسم الكروت): حساب هوتسبوت باسم مستخدم وكلمة مرور اختيارية،
 * بسرعة تنزيل ورفع ومدة وصلاحية وبيانات وعدد أجهزة خاصة به، أو بلا أي حد.
 */
final class CardAccountController extends Controller
{
    public const RATE_UNITS = ['M' => 'ميغابت', 'k' => 'كيلوبت'];

    public function index(Request $request): Response
    {
        $q = trim(latin_digits($request->str('q')));
        $state = $request->str('state');
        $where = ['c.is_custom = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(k.username LIKE ? OR b.name LIKE ? OR k.notes LIKE ? OR k.bound_mac = ? OR k.last_mac = ?)';
            $mac = normalize_mac($q) ?? $q;
            array_push($params, DB::like($q), DB::like($q), DB::like($q), $mac, $mac);
        }
        $where[] = match ($state) {
            'usable'   => "k.is_disabled = 0 AND k.status NOT IN ('expired','depleted')",
            'disabled' => 'k.is_disabled = 1',
            'ended'    => "k.status IN ('expired','depleted')",
            default    => '1 = 1',
        };
        $from = 'FROM cards k JOIN categories c ON c.id = k.category_id JOIN batches b ON b.id = k.batch_id WHERE ' . implode(' AND ', $where);
        $total = (int) DB::value("SELECT COUNT(*) $from", $params);
        $pager = Paginator::fromRequest($request, $total, 50);
        $rows = DB::rows(
            "SELECT k.*, b.name AS holder, c.name AS category_name, c.time_limit, c.validity, c.data_limit, c.rate_up, c.rate_down,
                    c.burst_rate_up, c.burst_rate_down, c.shared_users, c.lock_mac, c.idle_timeout,
                    (SELECT COUNT(*) FROM category_routers cr WHERE cr.category_id = c.id) AS router_limit
             $from ORDER BY k.id DESC" . $pager->limitSql(),
            $params
        );
        $online = [];
        if ($rows) {
            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            $online = array_map('intval', DB::pairs(
                'SELECT card_id, COUNT(*) FROM sessions WHERE stopped_at IS NULL AND updated_at >= ? AND card_id IN (' . DB::in($ids) . ') GROUP BY card_id',
                array_merge([date('Y-m-d H:i:s', time() - 900)], $ids)
            ));
        }
        $counts = DB::row(
            "SELECT COUNT(*) AS total, COALESCE(SUM(k.is_disabled = 0 AND k.status NOT IN ('expired','depleted')), 0) AS usable,
                    COALESCE(SUM(k.is_disabled = 1), 0) AS disabled, COALESCE(SUM(k.status IN ('expired','depleted')), 0) AS ended
             FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.is_custom = 1"
        );
        return view('cards/accounts', [
            'rows' => $rows, 'pager' => $pager, 'total' => $total, 'online' => $online, 'counts' => $counts,
            'filters' => ['q' => $q, 'state' => in_array($state, ['usable', 'disabled', 'ended'], true) ? $state : ''],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, int $id): Response
    {
        $acc = CustomAccounts::find($id);
        $this->notFoundUnless($acc);
        return $this->form($acc);
    }

    private function form(?array $acc): Response
    {
        return view('cards/account-form', [
            'acc' => $acc,
            'routers' => DB::pairs('SELECT id, name FROM routers WHERE is_active = 1 ORDER BY name'),
            'codeCase' => CustomAccounts::codeCase(),
        ]);
    }

    /** سرعة من رقم ووحدة إلى صيغة RouterOS (4M، 512k). الكسور تتحول إلى كيلوبت لأن RouterOS لا يقبلها */
    public static function rate(string $value, string $unit): ?string
    {
        $value = str_replace(',', '.', latin_digits(trim($value)));
        if ($value === '' || !is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        $n = (float) $value;
        if ($unit !== 'k') {
            if (abs($n - round($n)) < 0.0001) {
                return (int) round($n) . 'M';
            }
            $n *= 1000;
        }
        return max(1, (int) round($n)) . 'k';
    }

    /** تفكيك سرعة محفوظة إلى [رقم، وحدة] للنموذج */
    public static function splitRate(?string $rate): array
    {
        if ($rate === null || $rate === '' || !preg_match('/^(\d+(?:\.\d+)?)([kKmMgG]?)$/', $rate, $m)) {
            return ['', 'M'];
        }
        $num = static fn (float $n): string => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        // من ألف كيلوبت فأكثر تُعرض بالميغابت (1500k = 1.5 ميغابت)
        $k = match (strtolower($m[2])) {
            'g' => (float) $m[1] * 1000000,
            'm' => (float) $m[1] * 1000,
            'k' => (float) $m[1],
            default => (float) $m[1] / 1000,
        };
        return $k >= 1000 ? [$num($k / 1000), 'M'] : [$num(max(1, round($k))), 'k'];
    }

    /**
     * مدخلات النموذج بعد التحقق.
     * @throws ValidationFailed
     */
    private function input(Request $request, ?array $acc): array
    {
        $rateFields = ['rate_down', 'rate_up', 'burst_rate_down', 'burst_rate_up', 'burst_threshold_down', 'burst_threshold_up'];
        $data = [
            'username' => $request->str('username'), 'password' => $request->str('password'),
            'holder' => $request->str('holder'), 'notes' => $request->str('notes'),
            'shared_users' => $request->str('shared_users', '1'), 'burst_time' => $request->str('burst_time'),
            'time_value' => $request->str('time_value'), 'validity_value' => $request->str('validity_value'),
            'data_value' => $request->str('data_value'), 'idle_value' => $request->str('idle_value'),
        ];
        foreach ($rateFields as $f) {
            $data[$f] = str_replace(',', '.', $request->str($f));
        }
        $unlimited = $request->bool('unlimited');
        $rules = [
            'password' => 'nullable|max:64', 'holder' => 'nullable|max:120', 'notes' => 'nullable|max:255',
            'shared_users' => 'required|int|between:0,100', 'burst_time' => 'nullable|int|between:1,3600',
            'time_value' => 'nullable|numeric|min:0', 'validity_value' => 'nullable|numeric|min:0',
            'data_value' => 'nullable|numeric|min:0', 'idle_value' => 'nullable|int|between:0,1440',
        ];
        foreach ($rateFields as $f) {
            $rules[$f] = 'nullable|numeric|min:0';
        }
        if ($acc === null) {
            $rules['username'] = 'required|max:64';
        }
        $labels = [
            'username' => 'اسم المستخدم', 'password' => 'كلمة المرور', 'holder' => 'اسم صاحب الحساب', 'notes' => 'الملاحظات',
            'shared_users' => 'عدد الأجهزة', 'burst_time' => 'زمن الدفعة', 'time_value' => 'مدة الاستخدام',
            'validity_value' => 'الصلاحية', 'data_value' => 'حجم البيانات', 'idle_value' => 'قطع الخمول',
            'rate_down' => 'سرعة التنزيل', 'rate_up' => 'سرعة الرفع', 'burst_rate_down' => 'سرعة الدفعة للتنزيل',
            'burst_rate_up' => 'سرعة الدفعة للرفع', 'burst_threshold_down' => 'عتبة التنزيل', 'burst_threshold_up' => 'عتبة الرفع',
        ];
        $v = Validator::make($data, $unlimited ? array_intersect_key($rules, array_flip(['username', 'password', 'holder', 'notes'])) : $rules, $labels);

        $username = '';
        if ($acc === null) {
            $raw = trim(latin_digits($data['username']));
            if ($raw !== '' && preg_match('/\s/u', $raw)) {
                $v->addError('username', 'اسم المستخدم بدون مسافات.');
            } elseif ($raw !== '') {
                $username = CustomAccounts::normalizeUsername($raw);
                if ($err = CustomAccounts::usernameError($username)) {
                    $v->addError('username', $err);
                } elseif (LoginRegistry::isTaken($username)) {
                    $v->addError('username', 'اسم المستخدم مستخدم لكرت أو مشترك آخر. اختر اسماً غيره.');
                }
            }
        } else {
            $username = (string) $acc['card']['username'];
        }
        $password = CustomAccounts::normalizePassword($data['password']);
        if ($err = CustomAccounts::passwordError($password)) {
            $v->addError('password', $err);
        }
        if ($password === $username) {
            $password = '';
        }

        $timeUnit = array_key_exists($request->str('time_unit'), Units::TIME_UNITS) ? $request->str('time_unit') : 'hour';
        $validityUnit = array_key_exists($request->str('validity_unit'), Units::TIME_UNITS) ? $request->str('validity_unit') : 'day';
        $dataUnit = array_key_exists($request->str('data_unit'), Units::DATA_UNITS) ? $request->str('data_unit') : 'GB';
        $unitOf = static fn (string $f): string => $request->str($f . '_unit') === 'k' ? 'k' : 'M';

        // أجهزة MikroTik المسموحة: كلها، أو أجهزة محددة مفعّلة
        $routerIds = [];
        if ($request->str('router_scope') === 'selected') {
            $routerIds = array_values($request->ids('routers'));
            if (!$routerIds) {
                $v->addError('routers', 'اختر جهاز MikroTik واحداً على الأقل، أو اختر «كل أجهزة MikroTik».');
            } elseif (count(DB::column('SELECT id FROM routers WHERE is_active = 1 AND id IN (' . DB::in($routerIds) . ')', $routerIds)) !== count($routerIds)) {
                $v->addError('routers', 'أحد الأجهزة المختارة غير موجود أو غير مفعّل.');
            }
        }
        if ($v->fails()) {
            throw new ValidationFailed($v, $request);
        }
        $clean = $v->validated();

        $d = [
            'username' => $username, 'password' => $password,
            'holder' => ($clean['holder'] ?? '') !== '' ? $clean['holder'] : null,
            'notes' => ($clean['notes'] ?? '') !== '' ? mb_substr((string) $clean['notes'], 0, 255) : null,
            'enabled' => $request->bool('enabled'), 'router_ids' => $routerIds,
            'time_limit' => null, 'validity' => null, 'data_limit' => null,
            'rate_up' => null, 'rate_down' => null, 'burst_rate_up' => null, 'burst_rate_down' => null,
            'burst_threshold_up' => null, 'burst_threshold_down' => null, 'burst_time' => null,
            'shared_users' => 0, 'lock_mac' => 0, 'idle_timeout' => null,
        ];
        if ($unlimited) {
            return $d;
        }
        $d['time_limit'] = Units::toSeconds($data['time_value'], $timeUnit);
        $d['validity'] = Units::toSeconds($data['validity_value'], $validityUnit);
        $d['data_limit'] = Units::toBytes($data['data_value'], $dataUnit);
        foreach ($rateFields as $f) {
            $d[$f] = self::rate($data[$f], $unitOf($f));
        }
        if ($d['burst_rate_up'] !== null || $d['burst_rate_down'] !== null) {
            $d['burst_time'] = $clean['burst_time'] !== null ? (int) $clean['burst_time'] : 10;
        } else {
            $d['burst_threshold_up'] = $d['burst_threshold_down'] = null;
        }
        $d['shared_users'] = (int) $clean['shared_users'];
        $d['lock_mac'] = $request->bool('lock_mac') ? 1 : 0;
        $d['idle_timeout'] = !empty($clean['idle_value']) ? (int) $clean['idle_value'] * 60 : null;
        return $d;
    }

    /** أجهزة الرفع الفوري: المحددة للحساب، أو كل الأجهزة المفعّلة */
    private function pushTargets(array $routerIds): array
    {
        return $routerIds ?: array_map('intval', DB::column('SELECT id FROM routers WHERE is_active = 1 ORDER BY id'));
    }

    public function store(Request $request): Response
    {
        $d = $this->input($request, null);
        try {
            $id = CustomAccounts::create($d, \App\Core\Auth::id());
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['username' => $e->getMessage()], '/cards/accounts/create');
        }
        $msg = 'تم إنشاء الحساب الخاص ' . $d['username'] . ($d['password'] === '' ? '، ويدخل باسم المستخدم فقط.' : '، ويدخل باسم المستخدم وكلمة المرور.');
        if ($request->bool('push_now') && $d['enabled'] && ($targets = $this->pushTargets($d['router_ids']))) {
            $job = CardService::pushToRouters([$id], $targets);
            flash('success', $msg . ' جارٍ رفعه إلى أجهزة MikroTik.');
            return redirect('/jobs/' . $job);
        }
        return $this->ok($msg, '/cards/accounts');
    }

    public function update(Request $request, int $id): Response
    {
        $acc = CustomAccounts::find($id);
        $this->notFoundUnless($acc);
        $d = $this->input($request, $acc);
        try {
            $r = CustomAccounts::update($id, $d);
        } catch (RuntimeException $e) {
            return $this->backWithErrors($request, ['password' => $e->getMessage()], '/cards/accounts/' . $id . '/edit');
        }
        $msg = 'تم حفظ الحساب ' . $acc['card']['username'] . '.';
        if ($r['ended']) {
            $msg .= ' الحساب منتهٍ بالحدود الجديدة.';
        } elseif ($r['applied'] > 0) {
            $msg .= ' يجري تطبيق التغيير على ' . num($r['applied']) . ' جهاز MikroTik' . ($r['disconnected'] ? '، وفُصلت جلسته الحالية لتعمل الحدود الجديدة فوراً.' : '.');
        }
        if ($r['removed'] > 0) {
            $msg .= ' حُذف من ' . num($r['removed']) . ' جهاز MikroTik لم يعد مسموحاً عليه.';
        }
        if ($request->bool('push_now') && $d['enabled'] && !$r['ended'] && ($targets = $this->pushTargets($d['router_ids']))) {
            $job = CardService::pushToRouters([$id], $targets);
            flash('success', $msg . ' جارٍ رفعه إلى أجهزة MikroTik.');
            return redirect('/jobs/' . $job);
        }
        return $this->ok($msg, '/cards/accounts');
    }

    public function state(Request $request, int $id): Response
    {
        $acc = CustomAccounts::find($id);
        $this->notFoundUnless($acc);
        $enable = $request->bool('enable');
        try {
            $r = CustomAccounts::setEnabled($id, $enable);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            return back('/cards/accounts');
        }
        $msg = ($enable ? 'تم تفعيل الحساب ' : 'تم إيقاف الحساب ') . $acc['card']['username'] . '.';
        if (!empty($r['job'])) {
            flash('success', $msg . ' يجري تطبيق التغيير على أجهزة MikroTik.');
            return redirect('/jobs/' . $r['job']);
        }
        flash('success', $msg);
        return back('/cards/accounts');
    }

    public function destroy(Request $request, int $id): Response
    {
        $acc = CustomAccounts::find($id);
        $this->notFoundUnless($acc);
        try {
            $job = CustomAccounts::delete($id);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            return back('/cards/accounts');
        }
        if ($job) {
            flash('success', 'تم حذف الحساب ' . $acc['card']['username'] . '، ويجري حذفه من أجهزة MikroTik.');
            return redirect('/jobs/' . $job);
        }
        return $this->ok('تم حذف الحساب ' . $acc['card']['username'] . '.', '/cards/accounts');
    }
}
