<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Platform\PlatformMail;
use App\Services\Mailer;

/**
 * لوحة المسؤول ← البريد الإلكتروني: خادم SMTP ورسالة تجريبية، الرسائل التلقائية (التشغيل والعنوان والنص والمعاينة
 * وإرسال تجربة لكل رسالة)، البريد الجماعي لأصحاب الحسابات، وسجل كل ما أُرسل.
 */
final class AdminMailController extends Controller
{
    public const AUDIENCE = [
        'all' => 'كل الحسابات', 'trial' => 'التجريبية', 'active' => 'المفعّلة', 'ending' => 'تنتهي خلال 3 أيام',
        'expired' => 'المنتهية', 'suspended' => 'الموقوفة', 'none' => 'العناوين المكتوبة فقط',
    ];

    public const PLACEHOLDERS = ['{الاسم}' => 'اسم المالك', '{الشبكة}' => 'اسم الشبكة', '{الحساب}' => 'اسم الحساب', '{الأيام_المتبقية}' => 'الأيام المتبقية'];

    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    /** أصحاب الحسابات في الفئة ممن لهم بريد صالح */
    public static function audience(string $aud): array
    {
        $where = match ($aud) {
            'trial' => "status = 'trial'",
            'active' => "status = 'active'",
            'ending' => "((status = 'trial' AND trial_ends_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)) OR (status = 'active' AND paid_until IS NOT NULL AND paid_until <= DATE_ADD(NOW(), INTERVAL 3 DAY)))",
            'expired' => "status = 'expired'",
            'suspended' => "status IN ('suspended', 'cancelled')",
            'none' => '0 = 1',
            default => '1 = 1',
        };
        return array_values(array_filter(DB::rows("SELECT * FROM tenants WHERE $where AND email IS NOT NULL AND email <> '' ORDER BY name"),
            static fn ($t) => filter_var(trim((string) $t['email']), FILTER_VALIDATE_EMAIL) !== false));
    }

    public function index(Request $request): Response
    {
        $counts = [];
        foreach (array_keys(self::AUDIENCE) as $k) {
            $counts[$k] = count(self::audience($k));
        }
        $kind = array_key_exists($request->str('kind'), PlatformMail::KINDS) ? $request->str('kind') : '';
        $state = in_array($request->str('state'), ['ok', 'failed'], true) ? $request->str('state') : '';
        $where = '1 = 1';
        $params = [];
        if ($kind !== '') {
            $where .= ' AND l.kind = ?';
            $params[] = $kind;
        }
        if ($state !== '') {
            $where .= ' AND l.ok = ' . ($state === 'ok' ? '1' : '0');
        }
        $q = trim($request->str('q'));
        if ($q !== '') {
            $where .= ' AND (l.to_email LIKE ? OR l.subject LIKE ? OR t.name LIKE ?)';
            array_push($params, DB::like($q), DB::like($q), DB::like($q));
        }
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        return view('admin/mail', [
            'config' => PlatformMail::config(), 'hasPass' => Platform::setting('mail.pass', '') !== '', 'configured' => PlatformMail::configured(),
            'counts' => $counts,
            'log' => DB::rows("SELECT l.*, t.name AS tenant_name, t.slug, a.name AS admin_name FROM platform_mail_log l LEFT JOIN tenants t ON t.id = l.tenant_id
                LEFT JOIN platform_admins a ON a.id = l.admin_id WHERE $where ORDER BY l.id DESC LIMIT 100", $params),
            'filter' => ['kind' => $kind, 'state' => $state, 'q' => $q],
            'stats' => [
                'sent' => (int) DB::value('SELECT COUNT(*) FROM platform_mail_log WHERE ok = 1 AND created_at >= ?', [$since]),
                'failed' => (int) DB::value('SELECT COUNT(*) FROM platform_mail_log WHERE ok = 0 AND created_at >= ?', [$since]),
                'with_email' => (int) DB::value("SELECT COUNT(*) FROM tenants WHERE email IS NOT NULL AND email <> ''"),
                'on' => count(array_filter(PlatformMail::FEATURES, static fn ($f) => Platform::setting($f[0]) === '1')),
            ],
        ], 'admin');
    }

    public function settings(Request $request): Response
    {
        $d = $this->validate($request, [
            'host'      => 'nullable|max:190|regex:/^[A-Za-z0-9.\\-]+$/',
            'port'      => 'required|int|between:1,65535',
            'secure'    => 'required|in:' . implode(',', array_keys(Mailer::SECURE)),
            'user'      => 'nullable|max:190',
            'from'      => 'nullable|email|max:190',
            'from_name' => 'nullable|max:80',
        ], ['host' => 'خادم البريد', 'port' => 'المنفذ', 'secure' => 'التشفير', 'user' => 'اسم المستخدم', 'from' => 'بريد المرسل', 'from_name' => 'اسم المرسل']);
        $values = [
            'mail.host' => (string) $d['host'], 'mail.port' => (string) $d['port'], 'mail.secure' => $d['secure'],
            'mail.user' => (string) $d['user'], 'mail.from' => (string) $d['from'], 'mail.from_name' => (string) $d['from_name'],
        ];
        $pass = (string) $request->input('pass', '');
        if ($pass !== '') {
            $values['mail.pass'] = Platform::seal($pass);
        } elseif ($request->bool('remove_pass')) {
            $values['mail.pass'] = '';
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل إعدادات البريد الإلكتروني', $this->admin());
        return $this->ok('تم حفظ الإعدادات.', '/admin/mail');
    }

    /** الرسائل التلقائية: التشغيل، وعنوان كل قالب ونصه (القالب الافتراضي يُحفظ فارغاً فيتبع التحديثات) */
    public function features(Request $request): Response
    {
        $values = [];
        $errors = [];
        foreach (PlatformMail::FEATURES as $key => [$setting, , , $templates]) {
            $values[$setting] = $request->bool($key) ? '1' : '0';
            foreach (array_keys($templates) as $tpl) {
                foreach ([$tpl . '_subject' => PlatformMail::SUBJECT_MAX, $tpl => PlatformMail::BODY_MAX] as $field => $max) {
                    if ($request->input('tpl_' . $field) === null) {
                        continue; // لم يُرسل الحقل: يبقى المحفوظ
                    }
                    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $request->input('tpl_' . $field, '')));
                    if (mb_strlen($text) > $max) {
                        $errors['tpl_' . $field] = ($field === $tpl ? 'النص' : 'العنوان') . ' أطول من ' . $max . ' حرف.';
                        continue;
                    }
                    $values['mail.tpl_' . $field] = $text === (PlatformMail::TEMPLATES[$field] ?? null) ? '' : $text;
                }
            }
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, '/admin/mail#auto');
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'platform.settings', 'تعديل رسائل البريد التلقائية', $this->admin());
        return $this->ok('تم حفظ الرسائل التلقائية.', '/admin/mail#auto');
    }

    /** معاينة قالب ببيانات تجريبية (القالب المحفوظ، أو ما كُتب في النموذج قبل الحفظ) */
    public function preview(Request $request): Response
    {
        $tpl = $request->str('tpl');
        if (!self::knownTemplate($tpl)) {
            return Response::text('قالب غير معروف.', 404);
        }
        [$subject, $text, $rows, $button, $label] = $this->sampleWith($request, $tpl);
        return Response::text(PlatformMail::html($subject, $text, $button, $label, $rows, $tpl === 'invoice' || $tpl === 'transfer'), 200, 'text/html; charset=UTF-8');
    }

    private static function knownTemplate(string $tpl): bool
    {
        foreach (PlatformMail::FEATURES as [, , , $templates]) {
            if (isset($templates[$tpl])) {
                return true;
            }
        }
        return false;
    }

    /** العيّنة مع ما كتبه المسؤول قبل الحفظ */
    private function sampleWith(Request $request, string $tpl): array
    {
        $over = [];
        foreach ([$tpl . '_subject', $tpl] as $f) {
            $v = $request->input('tpl_' . $f);
            if (is_string($v) && trim($v) !== '') {
                $over['mail.tpl_' . $f] = trim(str_replace(["\r\n", "\r"], "\n", $v));
            }
        }
        if (!$over) {
            return PlatformMail::sample($tpl);
        }
        $saved = [];
        foreach (array_keys($over) as $k) {
            $saved[$k] = Platform::setting($k, '');
        }
        Platform::setSettings($over);
        try {
            return PlatformMail::sample($tpl);
        } finally {
            Platform::setSettings($saved);
        }
    }

    /** رسالة تجريبية: بإعداد الخادم كما كُتب في النموذج، أو قالب رسالة تلقائية بعينة بيانات */
    public function test(Request $request): Response
    {
        Session::release();
        $to = trim($request->str('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return json_response(['ok' => false, 'message' => 'اكتب عنوان بريد صحيحاً لاستلام الرسالة التجريبية.'], 422);
        }
        $tpl = $request->str('tpl');
        if ($tpl !== '') {
            if (!self::knownTemplate($tpl)) {
                return json_response(['ok' => false, 'message' => 'قالب غير معروف.'], 422);
            }
            if (!PlatformMail::configured()) {
                return json_response(['ok' => false, 'message' => 'اضبط خادم البريد واحفظه أولاً.'], 422);
            }
            [$subject, $text, $rows, $button, $label] = $this->sampleWith($request, $tpl);
            $r = PlatformMail::deliver('test', null, $to, 'تجربة: ' . $subject, $text, $button, $label, $rows, $tpl === 'invoice' || $tpl === 'transfer', $this->adminId());
        } else {
            $with = [];
            foreach (['host', 'port', 'secure', 'user', 'pass', 'from', 'from_name'] as $k) {
                $with[$k] = (string) $request->input($k, '');
            }
            [$subject, $text] = PlatformMail::welcomeContent((string) (AdminAuth::user()['name'] ?? 'المسؤول'), 'شبكة تجريبية', 'username');
            $r = PlatformMail::send($to, 'رسالة تجريبية: ' . $subject, $text, Platform::rootUrl() . '/login', $with);
            PlatformMail::log('test', null, $to, 'رسالة تجريبية: ' . $subject, $r, $this->adminId());
        }
        if ($r['ok']) {
            $r['message'] = 'تم الإرسال إلى ' . $to . '.';
        }
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    private function adminId(): ?int
    {
        $id = AdminAuth::user()['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /** بريد جماعي لأصحاب الحسابات و/أو عناوين مكتوبة، بعنوان ونص فيهما رموز شخصية */
    public function broadcast(Request $request): Response
    {
        $back = '/admin/mail#broadcast';
        if (!PlatformMail::configured()) {
            return $this->backWithErrors($request, ['b_body' => 'اضبط خادم البريد واحفظه أولاً.'], $back);
        }
        $subject = trim((string) $request->input('b_subject', ''));
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $request->input('b_body', '')));
        $errors = [];
        if ($subject === '' || mb_strlen($subject) > PlatformMail::SUBJECT_MAX) {
            $errors['b_subject'] = $subject === '' ? 'اكتب عنوان الرسالة.' : 'العنوان أطول من ' . PlatformMail::SUBJECT_MAX . ' حرف.';
        }
        if ($body === '' || mb_strlen($body) > PlatformMail::BODY_MAX) {
            $errors['b_body'] = $body === '' ? 'اكتب نص الرسالة.' : 'النص أطول من ' . PlatformMail::BODY_MAX . ' حرف.';
        }
        $aud = array_key_exists($request->str('audience'), self::AUDIENCE) ? $request->str('audience') : 'none';
        $targets = [];
        foreach (self::audience($aud) as $t) {
            $targets[mb_strtolower(trim((string) $t['email']))] = ['email' => trim((string) $t['email']), 'tenant' => $t];
        }
        $bad = [];
        foreach (preg_split('/[\s,،;]+/u', (string) $request->input('emails', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $bad[] = $e;
                continue;
            }
            $targets[mb_strtolower($e)] ??= ['email' => $e, 'tenant' => null];
        }
        if ($bad) {
            $errors['emails'] = 'عناوين غير صحيحة: ' . implode('، ', array_slice($bad, 0, 5));
        } elseif (!$targets && !$errors) {
            $errors['emails'] = 'لا يوجد مستلمون: اختر فئة فيها حسابات لها بريد، أو اكتب عناوين.';
        }
        if (count($targets) > 2000) {
            $errors['emails'] = 'المستلمون أكثر من 2000.';
        }
        if ($errors) {
            return $this->backWithErrors($request, $errors, $back);
        }
        Session::release();
        @set_time_limit(0);
        $ok = 0;
        foreach ($targets as $x) {
            $t = $x['tenant'];
            $vars = ['{الاسم}' => $t ? trim((string) $t['owner_name']) : '', '{الشبكة}' => $t ? trim((string) $t['name']) : '', '{الحساب}' => $t ? (string) $t['slug'] : '',
                '{الأيام_المتبقية}' => $t ? (string) (Tenant::daysLeft($t) ?? '') : '', '{المنصة}' => \App\Platform\PlatformSms::name()];
            $r = PlatformMail::deliver('broadcast', $t ? (int) $t['id'] : null, $x['email'], trim(strtr($subject, $vars)), trim(strtr($body, $vars)),
                $t ? Platform::rootUrl() . '/login' : null, null, [], false, $this->adminId());
            $ok += $r['ok'] ? 1 : 0;
        }
        $total = count($targets);
        Platform::log(null, 'admin', 'mail.broadcast', 'بريد جماعي «' . mb_substr($subject, 0, 80) . '» إلى ' . $total . ' (نجح ' . $ok . ')', $this->admin());
        if ($ok === 0) {
            return $this->backWithErrors($request, ['b_body' => 'تعذّر إرسال الرسائل. راجع سجل البريد لمعرفة السبب.'], $back);
        }
        return $this->ok('أُرسلت ' . $ok . ' من ' . $total . ' رسالة' . ($ok < $total ? '، وتعذّر ' . ($total - $ok) . ' (التفاصيل في السجل)' : '') . '.', '/admin/mail#log');
    }
}
