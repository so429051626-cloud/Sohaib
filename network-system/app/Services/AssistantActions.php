<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use RuntimeException;

/**
 * إجراءات المساعد الذكي داخل نظام الشبكة.
 *
 * النموذج يقترح الإجراء فقط؛ النظام يبني له «نافذة» فيها ما سيُنفَّذ بالضبط وحقول قابلة للتعديل
 * (مثلاً: توليد كروت ← قائمة الباقات الموجودة، والعدد، وأجهزة MikroTik)، ولا يُنفَّذ شيء إلا بعد
 * ضغط المستخدم «موافق»، وبصلاحياته هو وقت التنفيذ، مع تحقق كامل من القيم، وتسجيل في سجل العمليات.
 */
final class AssistantActions
{
    /** النوع => [الصلاحية، وصف للنموذج بصيغة JSON المطلوبة] */
    public const LIST = [
        'generate'            => ['cards.generate', 'توليد كروت: {"type":"generate","category":"اسم الباقة","quantity":50,"routers":["اسم جهاز MikroTik (اختياري)"]}'],
        'renew'               => ['subscribers.renew', 'تجديد اشتراك مشترك: {"type":"renew","username":"اسم دخول المشترك","periods":1,"paid":0}'],
        'suspend'             => ['subscribers.status', 'إيقاف مشترك: {"type":"suspend","username":"اسم دخول المشترك","reason":"السبب"}'],
        'activate'            => ['subscribers.status', 'تفعيل مشترك موقوف: {"type":"activate","username":"اسم دخول المشترك"}'],
        'card_disable'        => ['cards.manage', 'إيقاف كرت: {"type":"card_disable","card":"رقم الكرت أو المختصر","reason":"السبب"}'],
        'card_enable'         => ['cards.manage', 'تفعيل كرت موقوف: {"type":"card_enable","card":"رقم الكرت أو المختصر"}'],
        'card_extend'         => ['cards.manage', 'تمديد كرت: {"type":"card_extend","card":"رقم الكرت","days":1,"hours":0}'],
        'disconnect'          => ['sessions.disconnect', 'فصل متصل: {"type":"disconnect","user":"رقم الكرت أو اسم دخول المشترك"}'],
        'block_device'        => ['devices.block', 'حظر جهاز من الشبكة: {"type":"block_device","mac":"AA:BB:CC:DD:EE:FF","reason":"السبب"}'],
        'router_reboot'       => ['routers.control', 'إعادة تشغيل جهاز MikroTik: {"type":"router_reboot","router":"اسم الجهاز"}'],
        'router_sync'         => ['routers.view', 'مزامنة جهاز MikroTik الآن: {"type":"router_sync","router":"اسم الجهاز"}'],
        'router_backup'       => ['routers.backup', 'نسخة احتياطية على جهاز MikroTik: {"type":"router_backup","router":"اسم الجهاز"}'],
        'routeros'            => ['routers.control', 'أمر RouterOS مباشر على جهاز MikroTik (لصاحب الشبكة فقط؛ القراءة بـ print): {"type":"routeros","router":"اسم الجهاز","command":"/ip/hotspot/active/print","params":{"مفتاح":"قيمة"}}'],
        'sale'                => ['sales.create', 'بيع كروت (لموزع أو مباشر): {"type":"sale","category":"اسم الباقة","quantity":10,"distributor":"اسم الموزع أو فارغ للبيع المباشر","paid":0}'],
        'distributor_payment' => ['distributors.payments', 'دفعة من موزع: {"type":"distributor_payment","distributor":"اسم الموزع","amount":100}'],
        'expense'             => ['expenses.manage', 'تسجيل مصروف: {"type":"expense","category":"fuel|batteries|rent|provider|salaries|equipment|maintenance|transport|other","amount":50,"description":"البيان"}'],
        'task'                => ['maintenance.manage', 'مهمة صيانة: {"type":"task","title":"العنوان","assignee":"اسم الفني","priority":"normal|high|urgent","location":"المكان"}'],
        'sms'                 => ['sms.send', 'نص رسالة نصية يراجعه المستخدم ثم يرسله: {"type":"sms","text":"نص الرسالة"}'],
    ];

    public const MAX_GENERATE = 500;

    /** أوامر RouterOS: الأفعال المسموحة، والمسارات الممنوعة كتابتها (تحمي اتصال النظام والجهاز نفسه) */
    private const ROS_VERBS = ['print', 'getall', 'add', 'set', 'remove', 'enable', 'disable', 'reset-counters', 'unset', 'comment', 'move'];
    private const ROS_DENY_WRITE = ['/user', '/ip/service', '/system', '/password', '/certificate', '/file', '/tool/fetch', '/tool/e-mail', '/ppp/secret/export', '/interface/wireguard/peers'];
    private const ROS_DENY_ANY = ['/system/reset-configuration', '/system/shutdown', '/system/reboot', '/system/package', '/user/ssh-keys', '/export', '/import'];

    /** هل يحق للمستخدم الحالي هذا الإجراء؟ */
    public static function allowed(string $type): bool
    {
        if (!isset(self::LIST[$type]) || !Auth::can(self::LIST[$type][0])) {
            return false;
        }
        // الأوامر المباشرة على الجهاز لصاحب الشبكة وحده
        return $type !== 'routeros' || (bool) (Auth::user()['is_owner'] ?? false);
    }

    /** وصف الإجراءات المتاحة للمستخدم الحالي (للنموذج) */
    public static function hints(): array
    {
        $out = [];
        foreach (self::LIST as $type => [, $how]) {
            if (self::allowed($type)) {
                $out[] = '- ' . $how;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // بناء النافذة
    // ------------------------------------------------------------------

    /**
     * يبني نافذة الإجراء من اقتراح النموذج: العنوان، ما سيُنفَّذ، والحقول بقيمها المقترحة.
     * @return array{title:string, summary:string, fields:list<array>, button:string, danger?:bool, url?:string}
     */
    public static function form(string $type, array $a): array
    {
        if (!self::allowed($type)) {
            throw new RuntimeException('لا تملك صلاحية هذا الإجراء.');
        }
        switch ($type) {
            case 'generate':
                $cats = DB::rows('SELECT id, name, price FROM categories WHERE is_active = 1 AND is_custom = 0 ORDER BY sort, price');
                if (!$cats) {
                    throw new RuntimeException('لا توجد باقات كروت فعّالة.');
                }
                $cat = self::pickByName($cats, (string) ($a['category'] ?? ''));
                $routers = self::routerOptions();
                $chosen = [];
                foreach ((array) ($a['routers'] ?? []) as $rn) {
                    foreach ($routers as $r) {
                        if (mb_strtolower(trim((string) $rn)) === mb_strtolower($r['l'])) {
                            $chosen[] = $r['v'];
                        }
                    }
                }
                return [
                    'title' => 'توليد كروت',
                    'summary' => 'سيُنشئ النظام كروتاً جديدة من الباقة المختارة بإعدادات التوليد الافتراضية' . ($routers ? '، ويرفعها إلى أجهزة MikroTik المختارة فقط (أو تعمل على كل الأجهزة إن لم تختر).' : '.'),
                    'fields' => array_values(array_filter([
                        ['name' => 'category_id', 'label' => 'الباقة', 'type' => 'select', 'required' => true, 'value' => $cat ? (int) $cat['id'] : (int) $cats[0]['id'],
                            'options' => array_map(static fn ($c) => ['v' => (int) $c['id'], 'l' => $c['name'] . ' · ' . strip_tags(money((float) $c['price']))], $cats)],
                        ['name' => 'quantity', 'label' => 'عدد الكروت', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => self::MAX_GENERATE,
                            'value' => max(1, min(self::MAX_GENERATE, (int) ($a['quantity'] ?? 10)))],
                        $routers ? ['name' => 'routers', 'label' => 'أجهزة MikroTik', 'type' => 'multi', 'value' => $chosen, 'options' => $routers, 'hint' => 'اتركها فارغة لتعمل الكروت على كل الأجهزة.'] : null,
                        $routers ? ['name' => 'push_now', 'label' => 'رفع الكروت إلى الأجهزة المختارة الآن', 'type' => 'checkbox', 'value' => true] : null,
                        ['name' => 'batch_name', 'label' => 'اسم الدفعة', 'type' => 'text', 'value' => '', 'hint' => 'يُنشأ تلقائياً عند تركه فارغاً'],
                    ])),
                    'button' => 'موافق، ولّد الكروت',
                ];
            case 'renew':
                $sub = self::subscriber((string) ($a['username'] ?? ''));
                $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$sub['plan_id']]);
                return [
                    'title' => 'تجديد اشتراك ' . $sub['full_name'],
                    'summary' => 'يُجدَّد اشتراك المشترك «' . $sub['username'] . '» على باقته (' . ($plan['name'] ?? '—') . ' · ' . strip_tags(money((float) ($plan['price'] ?? 0))) . ' للفترة)، وتُضاف القيمة إلى حسابه ويُسجَّل المدفوع.',
                    'fields' => [
                        ['name' => 'username', 'label' => 'اسم دخول المشترك', 'type' => 'text', 'value' => $sub['username'], 'readonly' => true],
                        ['name' => 'periods', 'label' => 'عدد الفترات', 'type' => 'number', 'min' => 1, 'max' => 12, 'value' => max(1, min(12, (int) ($a['periods'] ?? 1)))],
                        ['name' => 'paid', 'label' => 'المبلغ المدفوع الآن', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'value' => max(0, (float) ($a['paid'] ?? 0))],
                    ],
                    'button' => 'موافق، جدّد الاشتراك',
                ];
            case 'suspend':
            case 'activate':
                $sub = self::subscriber((string) ($a['username'] ?? ''));
                $suspend = $type === 'suspend';
                if ($suspend === ($sub['status'] === 'suspended')) {
                    throw new RuntimeException($suspend ? 'المشترك موقوف أصلاً.' : 'المشترك مفعّل أصلاً.');
                }
                return [
                    'title' => ($suspend ? 'إيقاف المشترك ' : 'تفعيل المشترك ') . $sub['full_name'],
                    'summary' => $suspend ? 'يُوقف اشتراك «' . $sub['username'] . '» ويُفصل من جهاز MikroTik فوراً.' : 'يُعاد تفعيل اشتراك «' . $sub['username'] . '» ويُطبَّق على جهاز MikroTik.',
                    'fields' => array_values(array_filter([
                        ['name' => 'username', 'label' => 'اسم دخول المشترك', 'type' => 'text', 'value' => $sub['username'], 'readonly' => true],
                        $suspend ? ['name' => 'reason', 'label' => 'السبب', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['reason'] ?? '')), 0, 190)] : null,
                    ])),
                    'button' => $suspend ? 'موافق، أوقفه' : 'موافق، فعّله',
                    'danger' => $suspend,
                ];
            case 'card_disable':
            case 'card_enable':
            case 'card_extend':
                $card = self::card((string) ($a['card'] ?? ''));
                $fields = [['name' => 'card', 'label' => 'رقم الكرت', 'type' => 'text', 'value' => $card['username'], 'readonly' => true]];
                if ($type === 'card_disable') {
                    $fields[] = ['name' => 'reason', 'label' => 'السبب', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['reason'] ?? '')), 0, 190)];
                    return ['title' => 'إيقاف الكرت ' . $card['username'], 'summary' => 'يُوقف الكرت ويُفصل من كل أجهزة MikroTik الموجود عليها.', 'fields' => $fields, 'button' => 'موافق، أوقف الكرت', 'danger' => true];
                }
                if ($type === 'card_enable') {
                    return ['title' => 'تفعيل الكرت ' . $card['username'], 'summary' => 'يُعاد تفعيل الكرت على أجهزة MikroTik.', 'fields' => $fields, 'button' => 'موافق، فعّل الكرت'];
                }
                $fields[] = ['name' => 'days', 'label' => 'أيام تُضاف للصلاحية', 'type' => 'number', 'min' => 0, 'max' => 365, 'value' => max(0, (int) ($a['days'] ?? 1))];
                $fields[] = ['name' => 'hours', 'label' => 'ساعات استخدام تُضاف', 'type' => 'number', 'min' => 0, 'max' => 1000, 'value' => max(0, (int) ($a['hours'] ?? 0))];
                return ['title' => 'تمديد الكرت ' . $card['username'], 'summary' => 'يُمدَّد الكرت بالمقدار المحدد ويُحدَّث على أجهزة MikroTik.', 'fields' => $fields, 'button' => 'موافق، مدّد الكرت'];
            case 'disconnect':
                $who = self::userTarget((string) ($a['user'] ?? ''));
                return [
                    'title' => 'فصل ' . $who['label'],
                    'summary' => 'تُفصل الجلسة الحالية من جهاز MikroTik (يستطيع الدخول من جديد إن كان اشتراكه أو كرته صالحاً).',
                    'fields' => [['name' => 'user', 'label' => 'المستخدم', 'type' => 'text', 'value' => $who['login'], 'readonly' => true]],
                    'button' => 'موافق، افصله', 'danger' => true,
                ];
            case 'block_device':
                $mac = normalize_mac((string) ($a['mac'] ?? ''));
                if (!$mac) {
                    throw new RuntimeException('عنوان MAC غير صالح.');
                }
                return [
                    'title' => 'حظر الجهاز ' . $mac,
                    'summary' => 'يُحظر الجهاز من استخدام الشبكة على كل أجهزة MikroTik ويُفصل فوراً.',
                    'fields' => [
                        ['name' => 'mac', 'label' => 'عنوان MAC', 'type' => 'text', 'value' => $mac, 'readonly' => true],
                        ['name' => 'reason', 'label' => 'السبب', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['reason'] ?? '')), 0, 190)],
                    ],
                    'button' => 'موافق، احظره', 'danger' => true,
                ];
            case 'router_reboot':
            case 'router_sync':
            case 'router_backup':
                $routers = self::routerOptions();
                if (!$routers) {
                    throw new RuntimeException('لا توجد أجهزة MikroTik.');
                }
                $r = self::pickOption($routers, (string) ($a['router'] ?? ''));
                $meta = [
                    'router_reboot' => ['إعادة تشغيل جهاز MikroTik', 'يُعاد تشغيل الجهاز المختار، فينقطع كل المتصلين عليه نحو دقيقة حتى يعود.', 'موافق، أعد التشغيل', true],
                    'router_sync'   => ['مزامنة جهاز MikroTik الآن', 'يقرأ النظام حالة الجهاز والمتصلين والاستهلاك منه الآن.', 'موافق، زامن الآن', false],
                    'router_backup' => ['نسخة احتياطية على جهاز MikroTik', 'يُنشئ الجهاز نسخة احتياطية كاملة من إعداداته وتُحفظ في ملفاته.', 'موافق، أنشئ النسخة', false],
                ][$type];
                return [
                    'title' => $meta[0], 'summary' => $meta[1], 'button' => $meta[2], 'danger' => $meta[3],
                    'fields' => [['name' => 'router_id', 'label' => 'الجهاز', 'type' => 'select', 'required' => true, 'value' => $r ? $r['v'] : $routers[0]['v'], 'options' => $routers]],
                ];
            case 'routeros':
                $routers = self::routerOptions();
                if (!$routers) {
                    throw new RuntimeException('لا توجد أجهزة MikroTik.');
                }
                $r = self::pickOption($routers, (string) ($a['router'] ?? ''));
                $cmd = trim((string) ($a['command'] ?? ''));
                $params = [];
                foreach ((array) ($a['params'] ?? []) as $k => $val) {
                    if (is_scalar($val)) {
                        $params[] = $k . '=' . $val;
                    }
                }
                self::checkRouterOs($cmd, self::parseParams(implode("\n", $params)));
                return [
                    'title' => 'أمر مباشر على جهاز MikroTik',
                    'summary' => 'سيُرسل الأمر التالي كما هو إلى الجهاز المختار عبر واجهة API. راجع الأمر وقيمه قبل الموافقة.',
                    'fields' => [
                        ['name' => 'router_id', 'label' => 'الجهاز', 'type' => 'select', 'required' => true, 'value' => $r ? $r['v'] : $routers[0]['v'], 'options' => $routers],
                        ['name' => 'command', 'label' => 'الأمر', 'type' => 'text', 'mono' => true, 'value' => $cmd, 'required' => true],
                        ['name' => 'params', 'label' => 'القيم (سطر لكل مفتاح=قيمة)', 'type' => 'textarea', 'mono' => true, 'value' => implode("\n", $params)],
                    ],
                    'button' => 'موافق، نفّذ الأمر',
                    'danger' => !str_ends_with($cmd, '/print') && !str_ends_with($cmd, '/getall'),
                ];
            case 'sale':
                $cats = DB::rows('SELECT id, name, price FROM categories WHERE is_active = 1 AND is_custom = 0 ORDER BY sort, price');
                if (!$cats) {
                    throw new RuntimeException('لا توجد باقات كروت فعّالة.');
                }
                $cat = self::pickByName($cats, (string) ($a['category'] ?? ''));
                $dists = DB::rows('SELECT id, name FROM distributors WHERE is_active = 1 ORDER BY name');
                $dOpts = array_merge([['v' => 0, 'l' => 'بيع مباشر']], array_map(static fn ($d) => ['v' => (int) $d['id'], 'l' => $d['name']], $dists));
                $d = self::pickOption($dOpts, (string) ($a['distributor'] ?? ''));
                return [
                    'title' => 'بيع كروت',
                    'summary' => 'تُسجَّل عملية بيع لكروت متاحة من الباقة المختارة (الأقدم أولاً)، ويُضاف الباقي على الموزع إن لم يُدفع كاملاً.',
                    'fields' => [
                        ['name' => 'category_id', 'label' => 'الباقة', 'type' => 'select', 'required' => true, 'value' => $cat ? (int) $cat['id'] : (int) $cats[0]['id'],
                            'options' => array_map(static fn ($c) => ['v' => (int) $c['id'], 'l' => $c['name'] . ' · ' . strip_tags(money((float) $c['price']))], $cats)],
                        ['name' => 'quantity', 'label' => 'العدد', 'type' => 'number', 'min' => 1, 'max' => 5000, 'value' => max(1, (int) ($a['quantity'] ?? 1))],
                        ['name' => 'distributor_id', 'label' => 'المشتري', 'type' => 'select', 'value' => $d ? $d['v'] : 0, 'options' => $dOpts],
                        ['name' => 'paid', 'label' => 'المدفوع الآن', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'value' => max(0, (float) ($a['paid'] ?? 0))],
                    ],
                    'button' => 'موافق، سجّل البيع',
                ];
            case 'distributor_payment':
                $dists = DB::rows('SELECT id, name, balance FROM distributors WHERE is_active = 1 ORDER BY name');
                if (!$dists) {
                    throw new RuntimeException('لا يوجد موزعون.');
                }
                $opts = array_map(static fn ($d) => ['v' => (int) $d['id'], 'l' => $d['name'] . ' · ' . strip_tags(money((float) $d['balance']))], $dists);
                $d = self::pickOption(array_map(static fn ($x) => ['v' => (int) $x['id'], 'l' => $x['name']], $dists), (string) ($a['distributor'] ?? ''));
                return [
                    'title' => 'تسجيل دفعة موزع',
                    'summary' => 'تُسجَّل دفعة نقدية من الموزع وتُخصم من الرصيد المستحق عليه.',
                    'fields' => [
                        ['name' => 'distributor_id', 'label' => 'الموزع', 'type' => 'select', 'required' => true, 'value' => $d ? $d['v'] : (int) $dists[0]['id'], 'options' => $opts],
                        ['name' => 'amount', 'label' => 'المبلغ', 'type' => 'number', 'min' => 0.01, 'step' => '0.01', 'required' => true, 'value' => max(0, (float) ($a['amount'] ?? 0)) ?: ''],
                        ['name' => 'notes', 'label' => 'ملاحظات', 'type' => 'text', 'value' => ''],
                    ],
                    'button' => 'موافق، سجّل الدفعة',
                ];
            case 'expense':
                $opts = [];
                foreach (Finance::CATEGORIES as $k => [$label]) {
                    $opts[] = ['v' => $k, 'l' => $label];
                }
                $cat = (string) ($a['category'] ?? 'other');
                return [
                    'title' => 'تسجيل مصروف',
                    'summary' => 'يُسجَّل مصروف بتاريخ اليوم ويدخل في تقرير صافي الربح.',
                    'fields' => [
                        ['name' => 'category', 'label' => 'الفئة', 'type' => 'select', 'required' => true, 'value' => isset(Finance::CATEGORIES[$cat]) ? $cat : 'other', 'options' => $opts],
                        ['name' => 'amount', 'label' => 'المبلغ', 'type' => 'number', 'min' => 0.01, 'step' => '0.01', 'required' => true, 'value' => max(0, (float) ($a['amount'] ?? 0)) ?: ''],
                        ['name' => 'description', 'label' => 'البيان', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['description'] ?? '')), 0, 255)],
                    ],
                    'button' => 'موافق، سجّل المصروف',
                ];
            case 'task':
                $staff = DB::rows("SELECT id, name FROM employees WHERE status = 'active' ORDER BY name");
                $sOpts = array_merge([['v' => 0, 'l' => 'غير مسندة']], array_map(static fn ($e) => ['v' => (int) $e['id'], 'l' => $e['name']], $staff));
                $who = self::pickOption($sOpts, (string) ($a['assignee'] ?? ''));
                $pri = (string) ($a['priority'] ?? 'normal');
                return [
                    'title' => 'إنشاء مهمة صيانة',
                    'summary' => 'تُنشأ مهمة صيانة جديدة وتُرسل للفني المسند إليه إن وُجد.',
                    'fields' => [
                        ['name' => 'title', 'label' => 'عنوان المهمة', 'type' => 'text', 'required' => true, 'value' => mb_substr(trim((string) ($a['title'] ?? '')), 0, 160)],
                        ['name' => 'assignee_id', 'label' => 'الفني', 'type' => 'select', 'value' => $who ? $who['v'] : 0, 'options' => $sOpts],
                        ['name' => 'priority', 'label' => 'الأولوية', 'type' => 'select', 'value' => isset(Maintenance::PRIORITIES[$pri]) ? $pri : 'normal',
                            'options' => array_map(static fn ($k, $v) => ['v' => $k, 'l' => $v[0]], array_keys(Maintenance::PRIORITIES), Maintenance::PRIORITIES)],
                        ['name' => 'location', 'label' => 'المكان', 'type' => 'text', 'value' => mb_substr(trim((string) ($a['location'] ?? '')), 0, 190)],
                    ],
                    'button' => 'موافق، أنشئ المهمة',
                ];
            default: // sms: يُفتح في صفحة الإرسال للمراجعة
                $text = trim(mb_substr((string) ($a['text'] ?? ''), 0, 600));
                if ($text === '') {
                    throw new RuntimeException('نص الرسالة فارغ.');
                }
                return ['title' => 'نص رسالة نصية', 'summary' => $text, 'fields' => [], 'button' => 'مراجعة وإرسال', 'url' => url('/sms', ['text' => $text])];
        }
    }

    // ------------------------------------------------------------------
    // التنفيذ بعد «موافق»
    // ------------------------------------------------------------------

    /**
     * تنفيذ الإجراء بالقيم التي في النافذة (بعد تعديل المستخدم لها)، مع التحقق الكامل منها.
     * @return array{message:string, rows?:list<array>, url?:string}
     */
    public static function run(string $type, array $v, string $title): array
    {
        if (!self::allowed($type)) {
            throw new RuntimeException('لا تملك صلاحية هذا الإجراء.');
        }
        $note = 'عبر المساعد الذكي';
        switch ($type) {
            case 'generate':
                $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_active = 1 AND is_custom = 0', [(int) ($v['category_id'] ?? 0)]);
                if (!$cat) {
                    throw new RuntimeException('اختر باقة صالحة.');
                }
                $qty = (int) ($v['quantity'] ?? 0);
                if ($qty < 1 || $qty > self::MAX_GENERATE) {
                    throw new RuntimeException('عدد الكروت عبر المساعد بين 1 و' . self::MAX_GENERATE . '.');
                }
                $routerIds = self::validRouters((array) ($v['routers'] ?? []), (int) $cat['id']);
                $r = CardGenerator::generate([
                    'category_id' => (int) $cat['id'], 'quantity' => $qty,
                    'username_length' => (int) Settings::get('cards.username_length', '8'), 'charset' => (string) Settings::get('cards.charset', 'digits'),
                    'password_mode' => (string) Settings::get('cards.password_mode', 'same') === 'separate' ? 'separate' : 'same',
                    'password_length' => (int) Settings::get('cards.password_length', '4'),
                    'prefix' => '', 'batch_name' => mb_substr(trim((string) ($v['batch_name'] ?? '')), 0, 120), 'notes' => $note,
                ], Auth::id());
                $msg = 'تم توليد ' . ar_plural((int) $r['count'], 'كرت واحد', 'كرتين', 'كروت', 'كرتاً') . ' من «' . $cat['name'] . '».';
                $out = ['url' => url('/batches/' . (int) $r['batch_id'])];
                if ($routerIds) {
                    DB::insertMany('batch_routers', array_map(static fn ($id) => ['batch_id' => (int) $r['batch_id'], 'router_id' => $id], $routerIds));
                    $msg .= ' تعمل على الأجهزة المختارة فقط.';
                    if (!empty($v['push_now'])) {
                        $ids = array_map('intval', DB::column('SELECT id FROM cards WHERE batch_id = ? ORDER BY id', [(int) $r['batch_id']]));
                        $out['url'] = url('/jobs/' . CardService::pushToRouters($ids, $routerIds));
                        $msg .= ' جارٍ رفعها إلى الأجهزة.';
                    }
                }
                return ['message' => $msg] + $out;
            case 'renew':
                $sub = self::subscriber((string) ($v['username'] ?? ''));
                $periods = (int) ($v['periods'] ?? 1);
                if ($periods < 1 || $periods > 12) {
                    throw new RuntimeException('عدد الفترات يجب أن يكون بين 1 و12.');
                }
                $paid = max(0.0, round((float) ($v['paid'] ?? 0), 2));
                $r = SubscriberService::renew((int) $sub['id'], (int) $sub['plan_id'], $periods, null, $paid, 'cash', $note);
                return ['message' => 'تم تجديد اشتراك ' . $sub['full_name'] . ' حتى ' . substr((string) $r['expires_at'], 0, 10) . '.', 'url' => url('/subscribers/' . (int) $sub['id'])];
            case 'suspend':
            case 'activate':
                $sub = self::subscriber((string) ($v['username'] ?? ''));
                $suspend = $type === 'suspend';
                $reason = mb_substr(trim((string) ($v['reason'] ?? '')), 0, 190);
                SubscriberService::setStatus((int) $sub['id'], $suspend ? 'suspended' : 'active', $suspend ? ($reason !== '' ? $reason : $note) : null);
                return ['message' => ($suspend ? 'تم إيقاف المشترك ' : 'تم تفعيل المشترك ') . $sub['full_name'] . '.', 'url' => url('/subscribers/' . (int) $sub['id'])];
            case 'card_disable':
                $card = self::card((string) ($v['card'] ?? ''));
                CardService::disable([(int) $card['id']], mb_substr(trim((string) ($v['reason'] ?? '')), 0, 190) ?: $note);
                return ['message' => 'تم إيقاف الكرت.', 'url' => url('/check/card/' . (int) $card['id'])];
            case 'card_enable':
                $card = self::card((string) ($v['card'] ?? ''));
                CardService::enable([(int) $card['id']]);
                return ['message' => 'تم تفعيل الكرت.', 'url' => url('/check/card/' . (int) $card['id'])];
            case 'card_extend':
                $card = self::card((string) ($v['card'] ?? ''));
                $days = max(0, min(365, (int) ($v['days'] ?? 0)));
                $hours = max(0, min(1000, (int) ($v['hours'] ?? 0)));
                if ($days === 0 && $hours === 0) {
                    throw new RuntimeException('حدد مقدار التمديد.');
                }
                CardService::extend([(int) $card['id']], $hours * 3600, 0, $days * 86400);
                return ['message' => 'تم تمديد الكرت.', 'url' => url('/check/card/' . (int) $card['id'])];
            case 'disconnect':
                $who = self::userTarget((string) ($v['user'] ?? ''));
                if ($who['kind'] === 'card') {
                    $n = CardService::disconnect($who['row']);
                    return ['message' => $n > 0 ? 'تم فصل جلسات الكرت.' : 'لا توجد جلسات نشطة للكرت.'];
                }
                SubscriberService::disconnect($who['row']);
                return ['message' => 'تم إرسال أمر الفصل.'];
            case 'block_device':
                $mac = normalize_mac((string) ($v['mac'] ?? ''));
                if (!$mac) {
                    throw new RuntimeException('عنوان MAC غير صالح.');
                }
                $reason = mb_substr(trim((string) ($v['reason'] ?? '')), 0, 190);
                DB::query('INSERT INTO blocked_macs (mac, reason, created_by, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)', [$mac, $reason ?: null, Auth::id(), now()]);
                foreach (DB::column('SELECT id FROM routers WHERE is_active = 1') as $rid) {
                    RouterTasks::dispatch((int) $rid, 'binding', ['mac' => $mac, 'type' => 'blocked', 'comment' => 'sn:block'], 'mac:' . $mac, false);
                }
                Audit::log('device.block', 'حظر الجهاز ' . $mac, 'device', null, ['reason' => $reason]);
                return ['message' => 'تم حظر الجهاز على كل أجهزة MikroTik.'];
            case 'router_reboot':
            case 'router_sync':
            case 'router_backup':
                $router = self::router((int) ($v['router_id'] ?? 0));
                if ($type === 'router_sync') {
                    $r = RouterSync::run($router, true);
                    if (empty($r['ok'])) {
                        throw new RuntimeException((string) ($r['error'] ?? 'تعذّر الوصول إلى جهاز MikroTik الآن'));
                    }
                    return ['message' => 'تمت مزامنة جهاز MikroTik.', 'url' => url('/routers/' . (int) $router['id'])];
                }
                try {
                    $gw = Gateway::for($router, 10.0);
                    if ($type === 'router_reboot') {
                        $gw->reboot();
                        Audit::log('router.action', 'إعادة تشغيل جهاز MikroTik ' . $router['name'] . ' (' . $note . ')', 'router', (int) $router['id']);
                        return ['message' => 'تم إرسال أمر إعادة التشغيل.'];
                    }
                    $name = 'madix-' . date('Ymd-His');
                    $gw->backup($name);
                    $gw->close();
                    Audit::log('router.action', 'نسخة احتياطية على جهاز MikroTik ' . $router['name'] . ' (' . $note . ')', 'router', (int) $router['id']);
                    return ['message' => 'تم إنشاء النسخة على جهاز MikroTik: ' . $name];
                } catch (MikroTikException $e) {
                    throw new RuntimeException($e->friendly());
                }
            case 'routeros':
                $router = self::router((int) ($v['router_id'] ?? 0));
                $cmd = trim((string) ($v['command'] ?? ''));
                $params = self::parseParams((string) ($v['params'] ?? ''));
                self::checkRouterOs($cmd, $params);
                try {
                    $gw = Gateway::for($router, 12.0);
                    $rows = $gw->client()->command($cmd, $params);
                    $gw->close();
                } catch (MikroTikException $e) {
                    throw new RuntimeException($e->friendly());
                }
                Audit::log('router.action', 'أمر RouterOS على ' . $router['name'] . ': ' . mb_substr($cmd . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 200), 'router', (int) $router['id']);
                $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, 60);
                return ['message' => $rows ? 'تم تنفيذ الأمر. النتيجة (' . count($rows) . ' صف):' : 'تم تنفيذ الأمر.', 'rows' => $rows];
            case 'sale':
                $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_active = 1 AND is_custom = 0', [(int) ($v['category_id'] ?? 0)]);
                if (!$cat) {
                    throw new RuntimeException('اختر باقة صالحة.');
                }
                $qty = (int) ($v['quantity'] ?? 0);
                if ($qty < 1 || $qty > 5000) {
                    throw new RuntimeException('الكمية يجب أن تكون أكبر من صفر.');
                }
                $dist = (int) ($v['distributor_id'] ?? 0);
                $paid = max(0.0, round((float) ($v['paid'] ?? 0), 2));
                $r = SalesService::sell($dist > 0 ? 'distributor' : 'direct', $dist > 0 ? $dist : null, (int) $cat['id'], $qty, null, 0.0, $paid, null, $note);
                $saleId = (int) ($r['sale_id'] ?? $r['id'] ?? 0);
                return ['message' => 'تم بيع ' . ar_plural($qty, 'كرت واحد', 'كرتين', 'كروت', 'كرتاً') . ' من «' . $cat['name'] . '».'] + ($saleId ? ['url' => url('/sales/' . $saleId)] : []);
            case 'distributor_payment':
                $dist = DB::row('SELECT * FROM distributors WHERE id = ? AND is_active = 1', [(int) ($v['distributor_id'] ?? 0)]);
                if (!$dist) {
                    throw new RuntimeException('اختر موزعاً فعالاً.');
                }
                $amount = round((float) ($v['amount'] ?? 0), 2);
                if ($amount <= 0 || $amount > 9999999999) {
                    throw new RuntimeException('المبلغ غير صالح.');
                }
                SalesService::payment((int) $dist['id'], $amount, 'cash', mb_substr(trim((string) ($v['notes'] ?? '')), 0, 255) ?: $note);
                return ['message' => 'تم تسجيل الدفعة.', 'url' => url('/distributors/' . (int) $dist['id'])];
            case 'expense':
                $cat = (string) ($v['category'] ?? '');
                if (!isset(Finance::CATEGORIES[$cat])) {
                    throw new RuntimeException('اختر الفئة.');
                }
                $amount = round((float) ($v['amount'] ?? 0), 2);
                if ($amount <= 0 || $amount > 9999999999.99) {
                    throw new RuntimeException('اكتب مبلغاً صحيحاً أكبر من صفر.');
                }
                $desc = mb_substr(trim((string) ($v['description'] ?? '')), 0, 255);
                $id = DB::insert('expenses', ['category' => $cat, 'amount' => $amount, 'spent_on' => date('Y-m-d'), 'description' => $desc !== '' ? $desc : null, 'site_id' => null,
                    'created_by' => Auth::id(), 'created_at' => now()]);
                Audit::log('expense.create', 'تسجيل مصروف: ' . Finance::label($cat) . ' — ' . strip_tags(money($amount)), 'expense', $id);
                return ['message' => 'تم تسجيل المصروف.', 'url' => url('/expenses')];
            case 'task':
                $t = mb_substr(trim((string) ($v['title'] ?? '')), 0, 160);
                if ($t === '') {
                    throw new RuntimeException('اكتب عنوان المهمة.');
                }
                $assignee = (int) ($v['assignee_id'] ?? 0);
                if ($assignee > 0 && !DB::value("SELECT id FROM employees WHERE id = ? AND status = 'active'", [$assignee])) {
                    throw new RuntimeException('الفنّي غير موجود.');
                }
                $pri = (string) ($v['priority'] ?? 'normal');
                $id = Maintenance::save(null, ['title' => $t, 'type' => 'repair', 'priority' => isset(Maintenance::PRIORITIES[$pri]) ? $pri : 'normal', 'assignee_id' => $assignee ?: null,
                    'site_id' => null, 'router_id' => null, 'subscriber_id' => null, 'location' => mb_substr(trim((string) ($v['location'] ?? '')), 0, 190), 'description' => $note, 'due_on' => null]);
                return ['message' => 'تم إنشاء المهمة #' . $id . '.', 'url' => url('/maintenance/' . $id)];
        }
        throw new RuntimeException('إجراء غير معروف.');
    }

    // ------------------------------------------------------------------
    // مساعدات
    // ------------------------------------------------------------------

    /** أجهزة MikroTik المسموحة للمستخدم */
    private static function routerOptions(): array
    {
        [$scope, $sp] = Auth::routerScope('id');
        return array_map(static fn ($r) => ['v' => (int) $r['id'], 'l' => $r['name']], DB::rows('SELECT id, name FROM routers WHERE is_active = 1' . $scope . ' ORDER BY name', $sp));
    }

    private static function router(int $id): array
    {
        [$scope, $sp] = Auth::routerScope('id');
        $r = DB::row('SELECT * FROM routers WHERE id = ? AND is_active = 1' . $scope, array_merge([$id], $sp));
        if (!$r) {
            throw new RuntimeException('جهاز MikroTik غير موجود.');
        }
        return $r;
    }

    /** أجهزة صالحة للدفعة: مسموحة للمستخدم، مفعّلة، ومسموحة لباقة الكروت */
    private static function validRouters(array $ids, int $categoryId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));
        if (!$ids) {
            return [];
        }
        $allowed = array_column(self::routerOptions(), 'v');
        if (array_diff($ids, $allowed)) {
            throw new RuntimeException('أحد الأجهزة المختارة غير موجود أو غير مفعّل.');
        }
        $cat = array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ?', [$categoryId]));
        if ($cat && array_diff($ids, $cat)) {
            throw new RuntimeException('هذه الباقة لا تعمل إلا على أجهزة محددة؛ اختر من الأجهزة المسموحة لها فقط.');
        }
        return $ids;
    }

    private static function pickByName(array $rows, string $name): ?array
    {
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return null;
        }
        foreach ($rows as $r) {
            if (mb_strtolower(trim((string) $r['name'])) === $name || (string) $r['id'] === $name) {
                return $r;
            }
        }
        foreach ($rows as $r) {
            if (str_contains(mb_strtolower((string) $r['name']), $name) || str_contains($name, mb_strtolower((string) $r['name']))) {
                return $r;
            }
        }
        return null;
    }

    private static function pickOption(array $opts, string $label): ?array
    {
        $label = mb_strtolower(trim($label));
        if ($label === '') {
            return null;
        }
        foreach ($opts as $o) {
            if (mb_strtolower((string) $o['l']) === $label || (string) $o['v'] === $label) {
                return $o;
            }
        }
        foreach ($opts as $o) {
            if ($o['v'] !== 0 && (str_contains(mb_strtolower((string) $o['l']), $label) || str_contains($label, mb_strtolower((string) $o['l'])))) {
                return $o;
            }
        }
        return null;
    }

    private static function subscriber(string $username): array
    {
        $username = trim($username);
        [$scope, $sp] = Auth::routerScope('router_id', true);
        $sub = $username !== '' ? DB::row("SELECT * FROM subscribers WHERE (username = ? OR full_name = ?)$scope ORDER BY username = ? DESC LIMIT 1", array_merge([$username, $username], $sp, [$username])) : null;
        if (!$sub) {
            throw new RuntimeException('لا يوجد مشترك باسم الدخول «' . mb_substr($username, 0, 60) . '».');
        }
        return $sub;
    }

    private static function card(string $code): array
    {
        $code = trim($code);
        $card = $code !== '' ? DB::row('SELECT * FROM cards WHERE username = ? OR short_code = ? ORDER BY username = ? DESC LIMIT 1', [$code, $code, $code]) : null;
        if (!$card) {
            throw new RuntimeException('لا يوجد كرت بهذا الرقم: ' . mb_substr($code, 0, 40));
        }
        return $card;
    }

    /** كرت أو مشترك بالاسم */
    private static function userTarget(string $login): array
    {
        $login = trim($login);
        if ($login === '') {
            throw new RuntimeException('حدد المستخدم.');
        }
        $card = DB::row('SELECT * FROM cards WHERE username = ? OR short_code = ? LIMIT 1', [$login, $login]);
        if ($card) {
            return ['kind' => 'card', 'row' => $card, 'login' => (string) $card['username'], 'label' => 'الكرت ' . $card['username']];
        }
        $sub = self::subscriber($login);
        return ['kind' => 'subscriber', 'row' => $sub, 'login' => (string) $sub['username'], 'label' => 'المشترك ' . $sub['full_name']];
    }

    /** «مفتاح=قيمة» في كل سطر */
    private static function parseParams(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $val] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^\.?[a-z0-9][a-z0-9\-.]{0,40}$/i', $k)) {
                throw new RuntimeException('مفتاح غير صالح: ' . mb_substr($k, 0, 40));
            }
            $out[$k] = mb_substr($val, 0, 500);
        }
        return array_slice($out, 0, 30, true);
    }

    /** حماية الأوامر المباشرة: صيغة المسار، والفعل المسموح، والمسارات الممنوعة */
    private static function checkRouterOs(string $cmd, array $params): void
    {
        if (!preg_match('#^(/[a-z0-9][a-z0-9\-]*){2,6}$#', $cmd)) {
            throw new RuntimeException('صيغة الأمر غير صالحة. مثال: /ip/hotspot/active/print');
        }
        $verb = substr($cmd, strrpos($cmd, '/') + 1);
        if (!in_array($verb, self::ROS_VERBS, true)) {
            throw new RuntimeException('الأمر «' . $verb . '» غير مسموح عبر المساعد. المسموح: ' . implode('، ', self::ROS_VERBS));
        }
        $path = substr($cmd, 0, strrpos($cmd, '/'));
        foreach (self::ROS_DENY_ANY as $deny) {
            if ($cmd === $deny || str_starts_with($cmd, $deny . '/') || $path === $deny) {
                throw new RuntimeException('هذا الأمر ممنوع عبر المساعد حمايةً للجهاز.');
            }
        }
        if (!in_array($verb, ['print', 'getall'], true)) {
            foreach (self::ROS_DENY_WRITE as $deny) {
                if ($path === $deny || str_starts_with($path, $deny . '/')) {
                    throw new RuntimeException('تعديل «' . $path . '» ممنوع عبر المساعد حتى لا ينقطع اتصال النظام بالجهاز أو تتغير حماياته.');
                }
            }
        }
    }
}
