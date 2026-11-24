<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Session;
use App\Core\Settings;
use RuntimeException;

/**
 * المساعد الذكي لصاحب الشبكة وموظفيه (الميزة 1).
 * يجيب من بيانات الشبكة الحقيقية بما تسمح به صلاحيات السائل فقط، وهو للقراءة:
 * أي إجراء يطلبه المستخدم (تجديد، إيقاف، توليد كروت، نص رسالة) يُجهَّز ويظهر له زر «تأكيد»،
 * ولا يُنفَّذ إلا بعد الضغط عليه وبصلاحيات المستخدم نفسه، ويُسجَّل في سجل العمليات.
 */
final class Assistant
{
    private const TTL = 900;

    /** يظهر المساعد في الواجهة: الذكاء مفعّل للحساب (ولو انتهى حد الشهر) والمستخدم مخوَّل */
    public static function visible(): bool
    {
        return Auth::can('assistant.use') && Ai::offered();
    }

    /** أسئلة مقترحة بحسب صلاحيات المستخدم */
    public static function samples(): array
    {
        $out = [];
        if (Auth::can('subscribers.view')) {
            $out[] = 'من ينتهي اشتراكه هذا الأسبوع؟';
        }
        if (Auth::can('distributors.view')) {
            $out[] = 'من الموزّع الأكثر دَيناً؟';
        }
        if (Auth::can('reports.financial')) {
            $out[] = 'كم بعنا اليوم وهذا الشهر؟';
        }
        if (Auth::can('routers.view')) {
            $out[] = 'ما حالة أجهزة MikroTik الآن؟';
        }
        if (Auth::can('cards.view')) {
            $out[] = 'أي الباقات أوشكت كروتها على النفاد؟';
        }
        if (Auth::can('sms.send')) {
            $out[] = 'اكتب رسالة تذكير لمن ينتهي اشتراكهم قريباً';
        }
        return array_slice($out, 0, 4);
    }

    // ------------------------------------------------------------------
    // بيانات الشبكة التي يراها السائل
    // ------------------------------------------------------------------

    /** لقطة من بيانات الشبكة بحسب صلاحيات المستخدم الحالي وأجهزة MikroTik المسموحة له */
    public static function context(): array
    {
        $can = static fn (string $p): bool => Auth::can($p);
        $today = date('Y-m-d');
        $ctx = ['الشبكة' => Settings::networkName(), 'التاريخ' => date('Y-m-d H:i'), 'العملة' => (string) Settings::get('general.currency', '₪')];

        if ($can('subscribers.view')) {
            [$scope, $sp] = Auth::routerScope('s.router_id', true);
            $counts = DB::row("SELECT COUNT(*) AS total, SUM(s.status = 'active' AND (s.expires_at IS NULL OR s.expires_at >= NOW())) AS active,
                SUM(s.status = 'suspended') AS suspended, SUM(s.status = 'active' AND s.expires_at < NOW()) AS expired FROM subscribers s WHERE 1 = 1$scope", $sp);
            $list = static fn (string $where, array $params, string $order, int $limit) => array_map(static fn ($r) => array_filter([
                'الاسم' => $r['full_name'], 'اسم_الدخول' => $r['username'], 'الباقة' => $r['plan'], 'ينتهي' => $r['expires_at'] ? substr((string) $r['expires_at'], 0, 10) : null,
                'الهاتف' => $r['phone'], 'الرصيد_المستحق' => (float) $r['balance'] != 0.0 ? (float) $r['balance'] : null, 'الحالة' => $r['status'] === 'suspended' ? 'موقوف' : null,
            ], static fn ($v) => $v !== null && $v !== ''), DB::rows("SELECT s.full_name, s.username, s.phone, s.expires_at, s.balance, s.status, p.name AS plan
                FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE $where$scope ORDER BY $order LIMIT $limit", array_merge($params, $sp)));
            $ctx['المشتركون'] = [
                'العدد' => (int) ($counts['total'] ?? 0), 'نشطون' => (int) ($counts['active'] ?? 0), 'موقوفون' => (int) ($counts['suspended'] ?? 0), 'منتهون' => (int) ($counts['expired'] ?? 0),
                'ينتهون_خلال_7_أيام' => $list("s.status = 'active' AND s.expires_at BETWEEN ? AND ?", [$today . ' 00:00:00', date('Y-m-d 23:59:59', strtotime('+7 days'))], 's.expires_at', 40),
                'انتهوا_آخر_14_يوماً' => $list("s.status = 'active' AND s.expires_at BETWEEN ? AND ?", [date('Y-m-d 00:00:00', strtotime('-14 days')), date('Y-m-d H:i:s')], 's.expires_at DESC', 30),
                'الأكثر_دَيناً' => $list('s.balance > 0', [], 's.balance DESC', 15),
                'موقوفون_قائمة' => $list("s.status = 'suspended'", [], 's.updated_at DESC', 15),
            ];
            if ($can('plans.view')) {
                $ctx['باقات_الاشتراك'] = array_map(static fn ($r) => ['الاسم' => $r['name'], 'السعر' => (float) $r['price'], 'المدة_بالأيام' => (int) $r['duration_days'], 'المشتركون' => (int) $r['c']],
                    DB::rows('SELECT p.name, p.price, p.duration_days, (SELECT COUNT(*) FROM subscribers s WHERE s.plan_id = p.id) AS c FROM plans p WHERE p.is_active = 1 ORDER BY p.sort, p.price LIMIT 30'));
            }
        }

        if ($can('cards.view') || $can('categories.view')) {
            $ctx['باقات_الكروت'] = array_map(static fn ($r) => array_filter([
                'الاسم' => $r['name'], 'السعر' => (float) $r['price'], 'جديدة_غير_مباعة' => (int) $r['fresh'], 'مباعة_لم_تُستخدم' => (int) $r['sold'], 'نشطة' => (int) $r['active'],
                'منتهية' => (int) $r['ended'], 'حد_التنبيه_للمخزون' => $r['low_stock'] !== null ? (int) $r['low_stock'] : null,
            ], static fn ($v) => $v !== null), DB::rows("SELECT c.name, c.price, c.low_stock,
                SUM(k.status = 'new') AS fresh, SUM(k.status = 'sold') AS sold, SUM(k.status = 'active') AS active, SUM(k.status IN ('expired', 'depleted')) AS ended
                FROM categories c LEFT JOIN cards k ON k.category_id = c.id WHERE c.is_active = 1 AND c.is_custom = 0 GROUP BY c.id, c.name, c.price, c.low_stock, c.sort ORDER BY c.sort, c.price LIMIT 40"));
        }

        if ($can('sales.view')) {
            $money = $can('reports.financial');
            $sum = static fn (string $from) => DB::row("SELECT COUNT(*) AS ops, COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(paid), 0) AS paid
                FROM sales WHERE status = 'completed' AND created_at >= ?", [$from]);
            $fmt = static fn (array $r) => ['العمليات' => (int) $r['ops'], 'الكروت' => (int) $r['qty']] + ($money ? ['الإجمالي' => (float) $r['total'], 'المدفوع' => (float) $r['paid']] : []);
            $ctx['مبيعات_الكروت'] = ['اليوم' => $fmt($sum($today . ' 00:00:00')), 'آخر_7_أيام' => $fmt($sum(date('Y-m-d 00:00:00', strtotime('-6 days')))), 'هذا_الشهر' => $fmt($sum(date('Y-m-01 00:00:00')))];
            $ctx['عمليات_بيع_ملغاة_هذا_الشهر'] = (int) DB::value("SELECT COUNT(*) FROM sales WHERE status = 'cancelled' AND cancelled_at >= ?", [date('Y-m-01 00:00:00')]);
        }

        if ($can('distributors.view')) {
            $ctx['الموزعون'] = array_map(static fn ($r) => array_filter(['الاسم' => $r['name'], 'الهاتف' => $r['phone'], 'الدين_المستحق' => (float) $r['balance'], 'موقوف' => (int) $r['is_active'] === 1 ? null : true],
                static fn ($v) => $v !== null && $v !== ''), DB::rows('SELECT name, phone, balance, is_active FROM distributors ORDER BY balance DESC, name LIMIT 40'));
        }

        if ($can('routers.view')) {
            [$scope, $sp] = Auth::routerScope('r.id');
            $ctx['أجهزة_MikroTik'] = array_map(static fn ($r) => array_filter([
                'الاسم' => $r['name'], 'الحالة' => ['online' => 'متصل', 'offline' => 'منقطع'][$r['status']] ?? 'غير معروف', 'آخر_اتصال' => $r['last_seen_at'],
                'متصلون_هوتسبوت' => (int) $r['active_hotspot'], 'متصلون_PPP' => (int) $r['active_ppp'], 'حمل_المعالج' => $r['cpu_load'] !== null ? (int) $r['cpu_load'] . '%' : null,
                'الجهد' => $r['voltage'] !== null ? (float) $r['voltage'] : null, 'آخر_خطأ' => $r['status'] === 'offline' ? $r['last_error'] : null,
            ], static fn ($v) => $v !== null && $v !== ''), DB::rows("SELECT r.name, r.status, r.last_seen_at, r.active_hotspot, r.active_ppp, r.cpu_load, r.voltage, r.last_error
                FROM routers r WHERE r.is_active = 1$scope ORDER BY r.name LIMIT 40", $sp));
        }

        if ($can('outages.view')) {
            [$scope, $sp] = Auth::routerScope('o.router_id', true);
            $ctx['الانقطاعات'] = [
                'مفتوحة_الآن' => array_map(static fn ($r) => ['العنوان' => $r['title'], 'منذ' => $r['started_at'], 'المتأثرون' => (int) $r['affected']],
                    DB::rows("SELECT o.title, o.started_at, o.affected FROM outages o WHERE o.ended_at IS NULL$scope ORDER BY o.started_at DESC LIMIT 15", $sp)),
                'عددها_هذا_الشهر' => (int) DB::value("SELECT COUNT(*) FROM outages o WHERE o.started_at >= ?$scope", array_merge([date('Y-m-01 00:00:00')], $sp)),
            ];
        }

        if ($can('profit.view')) {
            $from = date('Y-m-01');
            $to = date('Y-m-t');
            $income = Finance::collected($from, $to);
            $spent = Finance::spent($from, $to);
            $ctx['مالية_هذا_الشهر'] = ['الدخل_المحصّل' => $income, 'المصاريف' => $spent, 'الصافي' => round($income - $spent, 2),
                'المصاريف_حسب_الفئة' => array_map(static fn ($c) => ['الفئة' => Finance::label($c['category']), 'الإجمالي' => $c['total']], Finance::byCategory($from, $to))];
        } elseif ($can('expenses.view')) {
            $ctx['مصاريف_هذا_الشهر'] = Finance::spent(date('Y-m-01'), date('Y-m-t'));
        }

        if ($can('inventory.view')) {
            $ctx['مخزون_منخفض'] = array_map(static fn ($r) => ['الصنف' => $r['name'], 'الكمية' => (float) $r['quantity'], 'الحد_الأدنى' => (float) $r['min_qty']],
                DB::rows('SELECT name, quantity, min_qty FROM inventory_items WHERE is_active = 1 AND min_qty > 0 AND quantity <= min_qty ORDER BY name LIMIT 20'));
        }

        if ($can('alerts.view')) {
            $ctx['تنبيهات_غير_مقروءة'] = array_map(static fn ($r) => ['العنوان' => $r['title'], 'التفاصيل' => $r['message'], 'الوقت' => $r['created_at']],
                DB::rows('SELECT title, message, created_at FROM alerts WHERE is_read = 0 ORDER BY id DESC LIMIT 15'));
        }
        return $ctx;
    }

    /** تعليمات النموذج: الدور، القواعد، الإجراءات المتاحة لهذا المستخدم، ثم البيانات */
    public static function system(): string
    {
        $user = Auth::user();
        $actions = AssistantActions::hints();
        $lang = \App\Core\Lang::isEn() ? 'أجب بالإنجليزية' : 'أجب بالعربية';
        $s = 'أنت المساعد الذكي داخل نظام إدارة شبكة الإنترنت «' . Settings::networkName() . '». تتحدث مع ' . ($user['is_owner'] ? 'صاحب الشبكة' : 'موظف في الشبكة') . ' (' . $user['name'] . ').' . "\n"
            . $lang . ' بإيجاز ووضوح ودقة اعتماداً على بيانات الشبكة أدناه فقط، واذكر الأسماء والأرقام كما هي تماماً. إن لم تكن المعلومة ضمن البيانات فقل إنها غير متوفرة لديك واقترح أين يجدها في النظام، ولا تخمّن أبداً. '
            . 'البيانات أدناه هي كل ما يحق لهذا المستخدم رؤيته؛ لا تذكر شيئاً خارجها. التسمية «أجهزة MikroTik» وليس «راوتر». النص عادي بلا تنسيق Markdown.' . "\n"
            . 'لشرح طريقة عمل شيء في النظام: صفحات النظام هي القائمة الجانبية (لوحة التحكم، أجهزة MikroTik، فحص الكابلات، المتصلون الآن، الكروت، توليد كروت، الباقات، المشتركون، المبيعات، الموزعون، التقارير، المخزون، مهام الصيانة، الإعدادات).' . "\n";
        if ($actions) {
            $s .= 'لا تنفّذ أي شيء بنفسك ولا تقل إنك نفّذته. إذا طلب المستخدم أمراً من القائمة التالية فجهّزه بسطر مستقل في آخر ردّك بهذه الصيغة تماماً: '
                . '<<ACTION {...}>> فتفتح للمستخدم نافذة فيها ما سيُنفَّذ وحقول يراجعها ويعدّلها ثم يضغط «موافق». جهّز إجراءً واحداً لكل سطر، وثلاثة على الأكثر، ولا تجهّز إجراءً لم يطلبه المستخدم صراحة. '
                . 'إن نقصت معلومة (مثل الباقة أو العدد) فجهّز الإجراء بأفضل قيمة مقترحة، فالمستخدم يختار ويعدّل في النافذة. الإجراءات المتاحة:' . "\n"
                . implode("\n", $actions) . "\n";
        } else {
            $s .= 'أنت للقراءة فقط: لا تنفّذ أي إجراء ولا تدّعِ تنفيذه.' . "\n";
        }
        return $s . "\n" . 'بيانات الشبكة (JSON):' . "\n" . json_encode(self::context(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // المحادثة
    // ------------------------------------------------------------------

    /** تنظيف سجل المحادثة القادم من المتصفح: آخر 12 رسالة، أدوار متناوبة تبدأ بالمستخدم وتنتهي به */
    public static function messages(array $raw): array
    {
        $messages = [];
        foreach (array_slice($raw, -12) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = trim(mb_substr((string) ($m['content'] ?? ''), 0, 4000));
            if ($text === '') {
                continue;
            }
            if ($messages && $messages[count($messages) - 1]['role'] === $role) {
                $messages[count($messages) - 1]['content'] .= "\n" . $text;
            } else {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }
        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        return $messages;
    }

    /**
     * سؤال المساعد.
     * @return array{ok:bool, text:string, message:string, actions:list<array>, used?:int, limit?:int}
     */
    public static function ask(array $rawMessages): array
    {
        $messages = self::messages($rawMessages);
        if (!$messages || $messages[count($messages) - 1]['role'] !== 'user') {
            return ['ok' => false, 'text' => '', 'message' => 'اكتب سؤالك.', 'actions' => []];
        }
        $state = Ai::status();
        if ($state !== 'ok') {
            return ['ok' => false, 'text' => '', 'message' => Ai::stateLabel($state), 'actions' => []];
        }
        $user = Auth::user();
        $r = Ai::ask('assistant', self::system(), $messages, ['effort' => 'medium', 'max_tokens' => 4000, 'actor' => (string) $user['name']]);
        if (!$r['ok']) {
            return ['ok' => false, 'text' => '', 'message' => $r['message'], 'actions' => []];
        }
        [$text, $actions, $notes] = self::extract($r['text']);
        if ($notes) {
            $text = trim($text . "\n\n" . implode("\n", $notes));
        }
        if ($text === '' && $actions) {
            $text = 'جهّزت لك الإجراء التالي، ولن يُنفَّذ قبل تأكيدك.';
        }
        return ['ok' => true, 'text' => $text, 'message' => '', 'actions' => $actions];
    }

    // ------------------------------------------------------------------
    // الإجراءات: تُجهَّز ثم ينفّذها المستخدم بزر «تأكيد»
    // ------------------------------------------------------------------

    /**
     * يستخرج الإجراءات من ردّ النموذج ويتحقق منها ويحفظها معلّقة في الجلسة.
     * @return array{0:string, 1:list<array{id:string, type:string, title:string, detail:string, button:string, url?:string}>, 2:list<string>}
     */
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
        // كل وسم إجراء يُحذف من النص المعروض، حتى المشوّه الذي لم يُفهم
        $text = trim((string) preg_replace(['/<<\s*ACTION\b.*?>>/su', '/[ \t]+\n/', '/\n{3,}/'], ['', "\n", "\n\n"], $reply));
        return [$text, $actions, $notes];
    }

    /** التحقق من إجراء وتجهيز نافذته؛ يُحفظ معلّقاً في الجلسة بمعرّف لا يُنفَّذ إلا بموافقة المستخدم */
    private static function prepare(array $a): array
    {
        $type = (string) ($a['type'] ?? '');
        if (!isset(AssistantActions::LIST[$type])) {
            throw new RuntimeException('إجراء غير معروف.');
        }
        $form = AssistantActions::form($type, $a);
        if (isset($form['url'])) {
            return ['id' => '', 'type' => $type, 'title' => $form['title'], 'summary' => $form['summary'], 'detail' => $form['summary'], 'fields' => [], 'button' => $form['button'], 'url' => $form['url']];
        }
        $id = bin2hex(random_bytes(8));
        $all = array_filter((array) Session::get('assistant_actions', []), static fn ($x) => is_array($x) && time() - (int) ($x['at'] ?? 0) < self::TTL);
        $all[$id] = ['type' => $type, 'title' => $form['title'], 'at' => time(), 'by' => (int) Auth::id()];
        Session::set('assistant_actions', array_slice($all, -12, null, true));
        return ['id' => $id, 'type' => $type, 'title' => $form['title'], 'summary' => $form['summary'], 'detail' => $form['summary'],
            'fields' => $form['fields'], 'button' => $form['button'], 'danger' => (bool) ($form['danger'] ?? false)];
    }

    /**
     * تنفيذ إجراء جهّزه المساعد بعد ضغط المستخدم «موافق» في النافذة: مرة واحدة، خلال ربع ساعة،
     * بالقيم التي راجعها المستخدم، وبصلاحياته وقت التنفيذ.
     * @return array{ok:bool, message:string, rows?:list<array>, url?:string}
     */
    public static function confirm(string $id, array $values = []): array
    {
        $all = (array) Session::get('assistant_actions', []);
        $a = $all[$id] ?? null;
        if (!is_array($a) || time() - (int) ($a['at'] ?? 0) >= self::TTL || (int) ($a['by'] ?? 0) !== (int) Auth::id()) {
            return ['ok' => false, 'message' => 'انتهت مهلة هذا الإجراء. اطلبه من المساعد مجدداً.'];
        }
        unset($all[$id]);
        Session::set('assistant_actions', $all);
        $type = (string) $a['type'];
        try {
            $r = AssistantActions::run($type, $values, (string) $a['title']);
        } catch (RuntimeException $e) {
            // تعذّر التنفيذ: يبقى الإجراء معلّقاً ليصحّح المستخدم القيم ويعيد المحاولة
            $all[$id] = $a;
            Session::set('assistant_actions', $all);
            return ['ok' => false, 'message' => $e->getMessage(), 'retry' => true];
        }
        Audit::log('assistant.action', 'إجراء عبر المساعد الذكي: ' . $a['title'], 'assistant', null, ['type' => $type]);
        return ['ok' => true] + $r;
    }
}
