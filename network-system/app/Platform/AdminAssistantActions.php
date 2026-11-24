<?php
declare(strict_types=1);

namespace App\Platform;

use App\Core\AdminAuth;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Session;
use RuntimeException;

/**
 * إجراءات مساعد مسؤول المنصة: يقترحها النموذج، وتُعرض في نافذة فيها ما سيُنفَّذ وحقول يراجعها المسؤول،
 * ولا تُنفَّذ إلا بضغط «موافق» وبصلاحيات المسؤول نفسه، وتُسجَّل في سجل نشاط المنصة.
 */
final class AdminAssistantActions
{
    /** النوع => [الصلاحية، الوصف للنموذج] */
    public const LIST = [
        'extend_trial'    => ['tenants.manage', 'تمديد الفترة التجريبية لحساب: {"type":"extend_trial","account":"اسم الحساب أو رابطه","days":7}'],
        'activate_tenant' => ['tenants.manage', 'تفعيل/تجديد اشتراك حساب: {"type":"activate_tenant","account":"اسم الحساب","months":1,"plan":"اسم الباقة (اختياري)","amount":0}'],
        'suspend_tenant'  => ['tenants.manage', 'إيقاف حساب: {"type":"suspend_tenant","account":"اسم الحساب","reason":"السبب"}'],
        'announcement'    => ['support.manage', 'إعلان يظهر في أنظمة الزبائن: {"type":"announcement","title":"العنوان","body":"النص","level":"info|success|warning","account":"اسم حساب واحد أو فارغ للكل"}'],
    ];
    private const TTL = 900;

    public static function hints(): array
    {
        $out = [];
        foreach (self::LIST as [$perm, $how]) {
            if (AdminAuth::can($perm)) {
                $out[] = '- ' . $how;
            }
        }
        return $out;
    }

    /** يستخرج الإجراءات من رد النموذج ويجهّز نوافذها @return array{0:string,1:list<array>,2:list<string>} */
    public static function extract(string $reply): array
    {
        $actions = [];
        $notes = [];
        if (preg_match_all('/<<\s*ACTION\s*(\{.*?\})\s*>>/su', $reply, $m)) {
            foreach (array_slice($m[1], 0, 3) as $json) {
                $a = json_decode($json, true);
                if (!is_array($a)) {
                    continue;
                }
                try {
                    $actions[] = self::prepare($a);
                } catch (RuntimeException $e) {
                    $notes[] = 'تعذّر تجهيز الإجراء: ' . $e->getMessage();
                }
            }
        }
        $text = trim((string) preg_replace(['/<<\s*ACTION\b.*?>>/su', '/[ \t]+\n/', '/\n{3,}/'], ['', "\n", "\n\n"], $reply));
        return [$text, $actions, $notes];
    }

    private static function allowed(string $type): bool
    {
        return isset(self::LIST[$type]) && AdminAuth::can(self::LIST[$type][0]);
    }

    private static function prepare(array $a): array
    {
        $type = (string) ($a['type'] ?? '');
        if (!self::allowed($type)) {
            throw new RuntimeException(isset(self::LIST[$type]) ? 'لا تملك صلاحية هذا الإجراء.' : 'إجراء غير معروف.');
        }
        $form = self::form($type, $a);
        $id = bin2hex(random_bytes(8));
        $all = array_filter((array) Session::get('admin_assistant_actions', []), static fn ($x) => is_array($x) && time() - (int) ($x['at'] ?? 0) < self::TTL);
        $all[$id] = ['type' => $type, 'title' => $form['title'], 'at' => time(), 'by' => (int) (AdminAuth::user()['id'] ?? 0)];
        Session::set('admin_assistant_actions', array_slice($all, -12, null, true));
        return ['id' => $id, 'type' => $type] + $form + ['detail' => $form['summary']];
    }

    private static function tenants(): array
    {
        return array_map(static fn ($t) => ['v' => (int) $t['id'], 'l' => $t['name'] . ' (/' . $t['slug'] . ')'], DB::rows('SELECT id, name, slug FROM tenants ORDER BY name LIMIT 500'));
    }

    private static function pickTenant(array $opts, string $q): int
    {
        $q = mb_strtolower(trim($q, " /\t"));
        if ($q === '') {
            return 0;
        }
        foreach ($opts as $o) {
            if (str_contains(mb_strtolower($o['l']), $q)) {
                return (int) $o['v'];
            }
        }
        return 0;
    }

    private static function form(string $type, array $a): array
    {
        $tenants = self::tenants();
        if (!$tenants && $type !== 'announcement') {
            throw new RuntimeException('لا توجد حسابات.');
        }
        $tid = self::pickTenant($tenants, (string) ($a['account'] ?? ''));
        $acc = ['name' => 'tenant_id', 'label' => 'الحساب', 'type' => 'select', 'required' => true, 'value' => $tid ?: ($tenants[0]['v'] ?? 0), 'options' => $tenants];
        switch ($type) {
            case 'extend_trial':
                return ['title' => 'تمديد الفترة التجريبية', 'summary' => 'تُمدَّد الفترة التجريبية للحساب المختار بعدد الأيام المحدد ابتداءً من نهايتها الحالية (أو من اليوم إن انتهت).',
                    'fields' => [$acc, ['name' => 'days', 'label' => 'عدد الأيام', 'type' => 'number', 'min' => 1, 'max' => 365, 'value' => max(1, min(365, (int) ($a['days'] ?? 7)))]],
                    'button' => 'موافق، مدّد التجربة'];
            case 'activate_tenant':
                $plans = array_map(static fn ($p) => ['v' => (int) $p['id'], 'l' => $p['name']], Billing::plans(false));
                $planId = 0;
                foreach ($plans as $p) {
                    if (($a['plan'] ?? '') !== '' && str_contains(mb_strtolower($p['l']), mb_strtolower((string) $a['plan']))) {
                        $planId = $p['v'];
                    }
                }
                return ['title' => 'تفعيل اشتراك حساب', 'summary' => 'يُفعَّل اشتراك الحساب المختار (أو يُمدَّد) بعدد الأشهر المحدد، وتُسجَّل الدفعة وتصدر فاتورتها إن أُدخل مبلغ.',
                    'fields' => [$acc,
                        ['name' => 'plan_id', 'label' => 'الباقة', 'type' => 'select', 'value' => $planId, 'options' => array_merge([['v' => 0, 'l' => 'بدون باقة']], $plans)],
                        ['name' => 'months', 'label' => 'عدد الأشهر', 'type' => 'number', 'min' => 1, 'max' => 60, 'value' => max(1, min(60, (int) ($a['months'] ?? 1)))],
                        ['name' => 'amount', 'label' => 'المبلغ المدفوع', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'value' => max(0, (float) ($a['amount'] ?? 0))]],
                    'button' => 'موافق، فعّل الاشتراك'];
            case 'suspend_tenant':
                return ['title' => 'إيقاف حساب', 'summary' => 'يُوقف الحساب المختار فيتوقف نظامه عن العمل لزبائنه حتى إعادة تفعيله.', 'danger' => true,
                    'fields' => [$acc, ['name' => 'reason', 'label' => 'السبب', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['reason'] ?? '')), 0, 255)]],
                    'button' => 'موافق، أوقف الحساب'];
            default: // announcement
                $level = (string) ($a['level'] ?? 'info');
                return ['title' => 'إعلان للزبائن', 'summary' => 'يُنشر إعلان يظهر أعلى أنظمة الزبائن (كل الحسابات أو حساب واحد).',
                    'fields' => [
                        ['name' => 'title', 'label' => 'العنوان', 'type' => 'text', 'required' => true, 'value' => mb_substr(trim((string) ($a['title'] ?? '')), 0, 120)],
                        ['name' => 'body', 'label' => 'النص', 'type' => 'textarea', 'value' => mb_substr(trim((string) ($a['body'] ?? '')), 0, 600)],
                        ['name' => 'level', 'label' => 'النوع', 'type' => 'select', 'value' => in_array($level, ['info', 'success', 'warning'], true) ? $level : 'info',
                            'options' => [['v' => 'info', 'l' => 'معلومة'], ['v' => 'success', 'l' => 'إيجابي'], ['v' => 'warning', 'l' => 'تنبيه']]],
                        ['name' => 'tenant_id', 'label' => 'المستهدفون', 'type' => 'select', 'value' => $tid, 'options' => array_merge([['v' => 0, 'l' => 'كل الحسابات']], $tenants)],
                    ],
                    'button' => 'موافق، انشر الإعلان'];
        }
    }

    /** @return array{ok:bool, message:string, url?:string, retry?:bool} */
    public static function confirm(string $id, array $v): array
    {
        $all = (array) Session::get('admin_assistant_actions', []);
        $a = $all[$id] ?? null;
        $me = AdminAuth::user();
        if (!is_array($a) || time() - (int) ($a['at'] ?? 0) >= self::TTL || (int) ($a['by'] ?? 0) !== (int) ($me['id'] ?? -1)) {
            return ['ok' => false, 'message' => 'انتهت مهلة هذا الإجراء. اطلبه من المساعد مجدداً.'];
        }
        unset($all[$id]);
        Session::set('admin_assistant_actions', $all);
        $type = (string) $a['type'];
        if (!self::allowed($type)) {
            return ['ok' => false, 'message' => 'لا تملك صلاحية هذا الإجراء.'];
        }
        $admin = (string) $me['name'];
        try {
            $tenant = $type !== 'announcement' || (int) ($v['tenant_id'] ?? 0) > 0 ? DB::row('SELECT * FROM tenants WHERE id = ?', [(int) ($v['tenant_id'] ?? 0)]) : null;
            if ($type !== 'announcement' && !$tenant) {
                throw new RuntimeException('الحساب غير موجود.');
            }
            switch ($type) {
                case 'extend_trial':
                    $days = max(1, min(365, (int) ($v['days'] ?? 7)));
                    $from = $tenant['trial_ends_at'] && strtotime($tenant['trial_ends_at']) > time() ? strtotime($tenant['trial_ends_at']) : time();
                    DB::update('tenants', ['status' => 'trial', 'trial_ends_at' => date('Y-m-d H:i:s', $from + $days * 86400), 'status_reason' => null, 'updated_at' => now()], 'id = ?', [(int) $tenant['id']]);
                    Platform::log((int) $tenant['id'], 'admin', 'tenant.extend', 'تمديد الفترة التجريبية ' . $days . ' يوم (عبر مساعد المسؤول)', $admin);
                    $r = ['message' => 'تم تمديد الفترة التجريبية للحساب ' . $tenant['name'] . '.', 'url' => url('/admin/tenants/' . (int) $tenant['id'])];
                    break;
                case 'activate_tenant':
                    $plan = Platform::plan((int) ($v['plan_id'] ?? 0) ?: null);
                    $months = max(1, min(60, (int) ($v['months'] ?? 1)));
                    $amount = max(0.0, round((float) ($v['amount'] ?? 0), 2));
                    $res = Billing::activate($tenant, ['months' => $months, 'until' => '', 'plan' => $plan, 'admin' => $admin, 'amount' => $amount, 'method' => 'cash', 'note' => 'عبر مساعد المسؤول']);
                    Platform::log((int) $tenant['id'], 'admin', 'tenant.activate', 'تفعيل الحساب' . ($res['until'] ? ' حتى ' . substr((string) $res['until'], 0, 10) : '') . ' (عبر مساعد المسؤول)', $admin);
                    $r = ['message' => 'تم تفعيل اشتراك ' . $tenant['name'] . ($res['until'] ? ' حتى ' . substr((string) $res['until'], 0, 10) : '') . '.', 'url' => url('/admin/tenants/' . (int) $tenant['id'])];
                    break;
                case 'suspend_tenant':
                    $reason = mb_substr(trim((string) ($v['reason'] ?? '')), 0, 255);
                    DB::update('tenants', ['status' => 'suspended', 'status_reason' => $reason !== '' ? $reason : null, 'updated_at' => now()], 'id = ?', [(int) $tenant['id']]);
                    Platform::log((int) $tenant['id'], 'admin', 'tenant.suspend', 'إيقاف الحساب' . ($reason !== '' ? ': ' . $reason : '') . ' (عبر مساعد المسؤول)', $admin);
                    $r = ['message' => 'تم إيقاف الحساب ' . $tenant['name'] . '.', 'url' => url('/admin/tenants/' . (int) $tenant['id'])];
                    break;
                default:
                    $title = mb_substr(trim((string) ($v['title'] ?? '')), 0, 120);
                    if ($title === '') {
                        throw new RuntimeException('اكتب عنوان الإعلان.');
                    }
                    $level = in_array($v['level'] ?? '', ['info', 'success', 'warning'], true) ? (string) $v['level'] : 'info';
                    $annId = DB::insert('platform_announcements', ['title' => $title, 'body' => mb_substr(trim((string) ($v['body'] ?? '')), 0, 600) ?: null, 'level' => $level,
                        'audience' => $tenant ? 'selected' : 'all', 'starts_at' => null, 'ends_at' => null, 'is_active' => 1, 'admin_name' => mb_substr($admin, 0, 120), 'created_at' => now()]);
                    if ($tenant) {
                        DB::insert('announcement_tenants', ['announcement_id' => $annId, 'tenant_id' => (int) $tenant['id']]);
                    }
                    Platform::log(null, 'admin', 'announcement.save', 'إعلان للزبائن: ' . $title . ' (عبر مساعد المسؤول)', $admin);
                    $r = ['message' => 'تم نشر الإعلان.', 'url' => url('/admin/announcements')];
            }
        } catch (RuntimeException $e) {
            $all[$id] = $a;
            Session::set('admin_assistant_actions', $all);
            return ['ok' => false, 'message' => $e->getMessage(), 'retry' => true];
        }
        return ['ok' => true] + $r;
    }
}
