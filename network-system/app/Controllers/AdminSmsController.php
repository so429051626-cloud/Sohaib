<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Platform\PlatformSms;
use App\Services\TweetSms;
use Throwable;

/**
 * الرسائل النصية من لوحة المسؤول إلى أصحاب الحسابات أو أرقام مكتوبة.
 */
final class AdminSmsController extends Controller
{
    public const AUDIENCE = [
        'all' => 'كل الحسابات', 'trial' => 'التجريبية', 'active' => 'المفعّلة', 'ending' => 'تنتهي خلال 3 أيام',
        'expired' => 'المنتهية', 'suspended' => 'الموقوفة', 'pick' => 'اختيار محدد', 'none' => 'بدون حسابات',
    ];

    /** أنواع المستلمين في الاختيار المحدد */
    public const PICK_TYPES = ['accounts' => 'أصحاب الحسابات', 'users' => 'المستخدمون'];

    public const PLACEHOLDERS = ['{الاسم}' => 'اسم المالك', '{الشبكة}' => 'اسم الشبكة', '{الحساب}' => 'اسم الحساب', '{الأيام_المتبقية}' => 'الأيام المتبقية'];

    private static function apiKey(): string
    {
        $enc = Platform::setting('sms.api_key', '');
        try {
            return $enc !== '' ? (string) Crypto::decrypt($enc) : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function tenants(string $aud): array
    {
        $where = match ($aud) {
            'trial' => "status = 'trial'",
            'active' => "status = 'active'",
            'ending' => "((status = 'trial' AND trial_ends_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)) OR (status = 'active' AND paid_until IS NOT NULL AND paid_until <= DATE_ADD(NOW(), INTERVAL 3 DAY)))",
            'expired' => "status = 'expired'",
            'suspended' => "status IN ('suspended', 'cancelled')",
            'none', 'pick' => '0 = 1',
            default => '1 = 1',
        };
        $out = [];
        foreach (DB::rows("SELECT * FROM tenants WHERE $where ORDER BY name") as $t) {
            $n = TweetSms::normalize((string) $t['phone']);
            if ($n !== null) {
                $out[] = $t + ['msisdn' => $n];
            }
        }
        return $out;
    }

    public function index(Request $request): Response
    {
        $counts = [];
        foreach (array_keys(self::AUDIENCE) as $k) {
            $counts[$k] = count($this->tenants($k));
        }
        return view('admin/sms', [
            'configured' => self::apiKey() !== '' && Platform::setting('sms.sender', '') !== '',
            'counts' => $counts,
            'picked' => array_map(static fn ($x) => ['id' => $x['id'], 'label' => $x['label'], 'sub' => $x['sub'], 'phone' => $x['phone']],
                self::pick('', '', array_values(array_filter((array) old('pick', []), 'is_string')))),
            'history' => DB::rows('SELECT s.*, a.name AS admin_name FROM platform_sms s LEFT JOIN platform_admins a ON a.id = s.admin_id ORDER BY s.id DESC LIMIT 30'),
        ], 'admin');
    }

    /**
     * مستلمون محددون: أصحاب حسابات (t:الحساب) أو مستخدمون داخل الحسابات (u:الحساب:المستخدم)، ممن لهم رقم صالح.
     * بالبحث، أو بمعرّفاتهم (لإعادة عرض ما اختير وللإرسال: تُقرأ الأرقام من قواعد البيانات لا من النموذج).
     * @return list<array{id:string, label:string, sub:string, phone:string, msisdn:string, tenant:?array, name:string}>
     */
    public static function pick(string $type, string $q, array $ids = [], int $limit = 50): array
    {
        $q = mb_strtolower(trim(latin_digits($q)));
        $want = [];
        foreach ($ids as $v) {
            if (is_string($v) && preg_match('/^(t|u):(\d+)(?::(\d+))?$/', $v, $m)) {
                $want[$m[1] === 't' ? 'accounts' : 'users'][(int) $m[2]][] = isset($m[3]) ? (int) $m[3] : 0;
            }
        }
        $byIds = $ids !== [];
        $out = [];
        $tenants = DB::rows("SELECT * FROM tenants WHERE status <> 'cancelled' ORDER BY name");
        if (($byIds && isset($want['accounts'])) || (!$byIds && $type === 'accounts')) {
            foreach ($tenants as $t) {
                if ($byIds && !isset($want['accounts'][(int) $t['id']])) {
                    continue;
                }
                $n = TweetSms::normalize((string) $t['phone']);
                $hay = mb_strtolower($t['owner_name'] . ' ' . $t['name'] . ' ' . $t['slug'] . ' ' . $t['phone']);
                if ($n === null || (!$byIds && $q !== '' && !str_contains($hay, $q))) {
                    continue;
                }
                $out[] = ['id' => 't:' . $t['id'], 'label' => (string) $t['owner_name'], 'sub' => $t['name'] . ' · /' . $t['slug'], 'phone' => '0' . substr($n, 3),
                    'msisdn' => $n, 'tenant' => $t, 'name' => (string) $t['owner_name']];
                if (!$byIds && count($out) >= $limit) {
                    return $out;
                }
            }
        }
        if (($byIds && isset($want['users'])) || (!$byIds && $type === 'users')) {
            foreach ($tenants as $t) {
                if ($byIds && !isset($want['users'][(int) $t['id']])) {
                    continue;
                }
                try {
                    $rows = Tenant::pdo($t)->query("SELECT id, name, username, phone, is_owner FROM employees WHERE status = 'active' ORDER BY is_owner DESC, name")->fetchAll();
                } catch (Throwable) {
                    continue;
                }
                foreach ($rows as $e) {
                    if ($byIds && !in_array((int) $e['id'], $want['users'][(int) $t['id']], true)) {
                        continue;
                    }
                    $n = TweetSms::normalize((string) $e['phone']);
                    $hay = mb_strtolower($e['name'] . ' ' . $e['username'] . ' ' . $e['phone'] . ' ' . $t['name'] . ' ' . $t['slug']);
                    if ($n === null || (!$byIds && $q !== '' && !str_contains($hay, $q))) {
                        continue;
                    }
                    $out[] = ['id' => 'u:' . $t['id'] . ':' . $e['id'], 'label' => (string) $e['name'], 'sub' => '@' . $e['username'] . ' · ' . $t['name'], 'phone' => '0' . substr($n, 3),
                        'msisdn' => $n, 'tenant' => $t, 'name' => (string) $e['name']];
                    if (!$byIds && count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /** البحث عن مستلمين للاختيار المحدد */
    public function recipients(Request $request): Response
    {
        Session::release();
        $type = array_key_exists($request->str('type'), self::PICK_TYPES) ? $request->str('type') : 'accounts';
        $items = array_map(static fn ($x) => ['id' => $x['id'], 'label' => $x['label'], 'sub' => $x['sub'], 'phone' => $x['phone']], self::pick($type, $request->str('q')));
        return json_response(['ok' => true, 'items' => $items]);
    }

    public function show(Request $request, int $id): Response
    {
        $sms = DB::row('SELECT * FROM platform_sms WHERE id = ?', [$id]);
        $this->notFoundUnless($sms);
        return json_response(['ok' => true, 'items' => DB::rows('SELECT name, phone, ok, code, description FROM platform_sms_items WHERE sms_id = ? ORDER BY id', [$id])]);
    }

    public function send(Request $request): Response
    {
        $key = self::apiKey();
        $sender = Platform::setting('sms.sender', '');
        if ($key === '' || $sender === '') {
            return $this->backWithErrors($request, ['body' => 'أكمل إعدادات الرسائل أولاً.'], '/admin/sms');
        }
        $body = trim((string) $request->input('body', ''));
        if ($body === '' || mb_strlen($body) > 1000) {
            return $this->backWithErrors($request, ['body' => 'اكتب نص الرسالة.'], '/admin/sms');
        }
        $aud = array_key_exists($request->str('audience'), self::AUDIENCE) ? $request->str('audience') : 'none';
        $targets = [];
        foreach ($this->tenants($aud) as $t) {
            $targets[$t['msisdn']] = ['tenant' => $t, 'name' => $t['owner_name']];
        }
        $picked = $aud === 'pick' ? self::pick('', '', array_slice(array_values(array_filter($request->arr('pick'), 'is_string')), 0, 2000)) : [];
        foreach ($picked as $p) {
            $targets[$p['msisdn']] ??= ['tenant' => $p['tenant'], 'name' => $p['name']];
        }
        [$numbers] = TweetSms::parseNumbers($request->str('numbers'));
        foreach ($numbers as $n) {
            $targets[$n] ??= ['tenant' => null, 'name' => null];
        }
        if (!$targets) {
            return $this->backWithErrors($request, ['numbers' => 'لا يوجد مستلمون.'], '/admin/sms');
        }
        Session::release();
        @set_time_limit(0);
        $personal = false;
        foreach (array_keys(self::PLACEHOLDERS) as $p) {
            $personal = $personal || str_contains($body, $p);
        }
        $smsId = DB::insert('platform_sms', [
            'audience' => self::AUDIENCE[$aud] . ($picked ? ' (' . count($picked) . ')' : '') . ($numbers ? ' · ' . count($numbers) . ' رقم' : ''), 'body' => $body, 'segments' => max(1, TweetSms::segments($body)),
            'recipients' => count($targets), 'admin_id' => AdminAuth::id(), 'created_at' => now(),
        ]);
        $sent = $failed = 0;
        $error = null;
        $record = static function (string $phone, array $tg, array $r) use ($smsId, &$sent, &$failed): void {
            DB::insert('platform_sms_items', [
                'sms_id' => $smsId, 'tenant_id' => $tg['tenant']['id'] ?? null, 'name' => $tg['name'], 'phone' => $phone,
                'ok' => $r['ok'] ? 1 : 0, 'code' => $r['code'], 'description' => mb_substr(TweetSms::sendMessage($r), 0, 255),
            ]);
            $r['ok'] ? $sent++ : $failed++;
        };
        if ($personal) {
            foreach ($targets as $phone => $tg) {
                $phone = (string) $phone;
                if ($error !== null) {
                    $record($phone, $tg, ['ok' => false, 'code' => null, 'desc' => $error, 'transport' => false]);
                    continue;
                }
                $t = $tg['tenant'];
                $text = strtr($body, [
                    '{الاسم}' => (string) ($tg['name'] ?? ''), '{الشبكة}' => (string) ($t['name'] ?? ''), '{الحساب}' => (string) ($t['slug'] ?? ''),
                    '{الأيام_المتبقية}' => $t ? (string) (Tenant::daysLeft($t) ?? '') : '',
                ]);
                $text = trim((string) preg_replace(['/[ \t]{2,}/u', '/[ \t]+([،,.!؟?:؛])/u'], [' ', '$1'], $text));
                $r = TweetSms::sendOnce($key, $sender, $text, [$phone]);
                $record($phone, $tg, $r);
                if (!$r['ok'] && in_array($r['code'], TweetSms::FATAL, true)) {
                    $error = TweetSms::sendMessage($r);
                }
            }
        } else {
            foreach (TweetSms::sendBatches($key, $sender, $body, array_map('strval', array_keys($targets))) as $batch) {
                foreach ($batch['numbers'] as $phone) {
                    $record((string) $phone, $targets[$phone], $batch['result']);
                }
                if (!$batch['result']['ok']) {
                    $error = TweetSms::sendMessage($batch['result']);
                }
            }
        }
        DB::update('platform_sms', ['sent' => $sent, 'failed' => $failed, 'error' => $error], 'id = ?', [$smsId]);
        Platform::log(null, 'admin', 'platform.sms', 'إرسال رسالة نصية إلى ' . count($targets) . ' مستلم', AdminAuth::user()['name']);
        flash($sent > 0 ? 'success' : 'error', $sent > 0 ? 'تم إرسال ' . num($sent) . ' من ' . num(count($targets)) . '.' : ($error ?? 'تعذّر الإرسال.'));
        return redirect('/admin/sms');
    }

    public function settings(Request $request): Response
    {
        $d = $this->validate($request, ['sender' => 'nullable|max:11|regex:/^[0-9A-Za-z_.,;:?@ -]{2,11}$/'], ['sender' => 'اسم المرسل']);
        $values = ['sms.sender' => (string) $d['sender']];
        $key = trim((string) $request->input('api_key', ''));
        if ($key !== '') {
            $values['sms.api_key'] = Crypto::encrypt($key);
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل إعدادات الرسائل النصية', AdminAuth::user()['name']);
        return $this->ok('تم حفظ الإعدادات.', '/admin/sms');
    }

    /** تشغيل وإيقاف رسائل المنصة التلقائية: التحقق عند التسجيل، الترحيب، استعادة كلمة المرور */
    public function features(Request $request): Response
    {
        $values = [];
        $errors = [];
        foreach (PlatformSms::FEATURES as $key => [$setting]) {
            $values[$setting] = $request->bool($key) ? '1' : '0';
            $raw = $request->input('tpl_' . $key);
            if ($raw === null) {
                continue;
            }
            // النص المكتوب، والنص الافتراضي أو الفارغ يُحفظ فارغاً فيتبع الافتراضي
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $raw));
            if (($err = PlatformSms::templateError($key, $text)) !== null) {
                $errors['tpl_' . $key] = $err;
                continue;
            }
            $values['sms.tpl_' . $key] = $text === PlatformSms::TEMPLATES[$key] ? '' : $text;
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, '/admin/sms');
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل الرسائل التلقائية', AdminAuth::user()['name']);
        return $this->ok('تم حفظ الإعدادات.', '/admin/sms');
    }

    public function status(Request $request): Response
    {
        Session::release();
        $key = self::apiKey();
        if ($key === '') {
            return json_response(['ok' => false, 'message' => 'أدخل مفتاح الربط.'], 422);
        }
        $u = TweetSms::call('chk_user', [], $key, 15);
        if (!$u['ok']) {
            return json_response(['ok' => false, 'message' => TweetSms::otherMessage($u)], 422);
        }
        [$okBal, $bal, $txt] = TweetSms::balance($key);
        return json_response(['ok' => true, 'balance' => $okBal ? $bal : null, 'balance_text' => $txt, 'senders' => TweetSms::senders($key)]);
    }
}
