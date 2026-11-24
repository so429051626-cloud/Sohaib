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
use App\Platform\Insights;
use App\Services\Ai;

/**
 * لوحة المسؤول: إعداد الذكاء الاصطناعي واستهلاكه، مساعد المسؤول، والتحليلات.
 */
final class AdminAiController extends Controller
{
    private function admin(): string
    {
        return (string) (AdminAuth::user()['name'] ?? '');
    }

    public function index(Request $request): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $request->str('month')) ? $request->str('month') : date('Y-m');
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-01 00:00:00', strtotime($from . ' +1 month'));
        $usage = DB::rows(
            'SELECT u.tenant_id, t.name, t.slug, COUNT(*) AS requests, SUM(u.ok) AS messages, SUM(u.input_tokens) AS input_tokens, SUM(u.output_tokens) AS output_tokens, SUM(u.cost) AS cost
             FROM ai_usage u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.created_at >= ? AND u.created_at < ? GROUP BY u.tenant_id, t.name, t.slug ORDER BY cost DESC',
            [$from, $to]
        );
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthly[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = ['messages' => 0, 'cost' => 0.0];
        }
        foreach (DB::rows("SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(ok) AS c, SUM(cost) AS s FROM ai_usage WHERE created_at >= ? GROUP BY m", [date('Y-m-01', strtotime('-11 months'))]) as $r) {
            if (isset($monthly[$r['m']])) {
                $monthly[$r['m']] = ['messages' => (int) $r['c'], 'cost' => (float) $r['s']];
            }
        }
        $tenants = DB::rows("SELECT t.id, t.name, t.slug, t.status, t.ai_enabled, t.ai_limit, t.plan_id, p.name AS plan_name, p.ai_messages AS plan_ai FROM tenants t LEFT JOIN platform_plans p ON p.id = t.plan_id WHERE t.status IN ('trial', 'active') ORDER BY t.name");
        $used = DB::pairs('SELECT tenant_id, COUNT(*) FROM ai_usage WHERE ok = 1 AND tenant_id IS NOT NULL AND created_at >= ? GROUP BY tenant_id', [date('Y-m-01 00:00:00')]);
        return view('admin/ai', [
            'state' => Ai::status(null), 'provider' => Ai::provider(), 'enabled' => Platform::setting('ai.enabled') === '1',
            'hasKey' => Ai::hasKey('anthropic'), 'savedAt' => Platform::setting('ai.key_saved_at', ''),
            'model' => (string) (isset(Ai::MODELS[Platform::setting('ai.model')]) ? Platform::setting('ai.model') : Ai::DEFAULT_MODEL),
            'hasGemini' => Ai::hasKey('gemini'), 'geminiSavedAt' => Platform::setting('ai.gemini_key_saved_at', ''),
            'geminiModel' => Ai::cleanGeminiModel(Platform::setting('ai.gemini_model', '')), 'month' => $month, 'usage' => $usage, 'monthly' => $monthly,
            'byFeature' => DB::rows('SELECT feature, SUM(ok) AS messages, SUM(cost) AS cost FROM ai_usage WHERE created_at >= ? AND created_at < ? GROUP BY feature ORDER BY cost DESC', [$from, $to]),
            'tenants' => $tenants, 'used' => $used,
        ], 'admin');
    }

    private static function validKey(string $key): bool
    {
        return strlen($key) >= 20 && strlen($key) <= 300 && !preg_match('/\s/', $key);
    }

    public function save(Request $request): Response
    {
        $provider = $request->str('provider', Ai::provider());
        $d = $this->validate($request, [
            'provider' => 'nullable|in:' . implode(',', array_keys(Ai::PROVIDERS)),
            'model'    => 'required|in:' . implode(',', array_keys(Ai::MODELS)),
        ], ['provider' => 'المزوّد', 'model' => 'النموذج']);
        $provider = isset(Ai::PROVIDERS[$provider]) ? $provider : 'anthropic';
        $key = trim((string) $request->input('api_key', ''));
        $gkey = trim((string) $request->input('gemini_key', ''));
        $rawModel = trim($request->str('gemini_model'));
        $gmodel = Ai::cleanGeminiModel($rawModel);
        if ($key !== '' && !self::validKey($key)) {
            return $this->backWithErrors($request, ['api_key' => 'المفتاح غير صالح.'], '/admin/ai');
        }
        if ($gkey !== '' && !self::validKey($gkey)) {
            return $this->backWithErrors($request, ['gemini_key' => 'المفتاح غير صالح.'], '/admin/ai');
        }
        if ($rawModel !== '' && $gmodel === '') {
            return $this->backWithErrors($request, ['gemini_model' => 'اسم النموذج غير صالح.'], '/admin/ai');
        }
        if ($key !== '') {
            Ai::setKey($key, 'anthropic');
        }
        if ($gkey !== '') {
            Ai::setKey($gkey, 'gemini');
        }
        if ($request->bool('remove_key')) {
            Platform::setSettings(['ai.api_key' => '', 'ai.key_saved_at' => '']);
        }
        if ($request->bool('remove_gemini_key')) {
            Platform::setSettings(['ai.gemini_key' => '', 'ai.gemini_key_saved_at' => '']);
        }
        // مفتاح Gemini بلا نموذج مختار: يُختار أحدث نموذج Flash مستقر من قائمة المفتاح
        if ($gmodel === '' && Ai::hasKey('gemini') && Platform::setting('ai.gemini_model', '') === '') {
            Session::release();
            $list = Ai::geminiModels();
            Session::resume();
            $gmodel = $list['ok'] ? $list['suggested'] : '';
        }
        $values = ['ai.provider' => $provider, 'ai.model' => $d['model'], 'ai.enabled' => $request->bool('enabled') ? '1' : '0'];
        // الحسابات الجديدة: تفعيل تلقائي والحد الشهري الافتراضي
        if ($request->input('default_limit') !== null) {
            $limit = trim(latin_digits($request->str('default_limit')));
            if ($limit === '' || !ctype_digit($limit) || (int) $limit > 1000000) {
                return $this->backWithErrors($request, ['default_limit' => 'الحد الافتراضي رقم من 0 إلى 1000000.'], '/admin/ai');
            }
            $values['ai.default_limit'] = (string) (int) $limit;
            $values['ai.new_enabled'] = $request->bool('new_enabled') ? '1' : '0';
        }
        if ($gmodel !== '') {
            $values['ai.gemini_model'] = $gmodel;
        }
        Platform::setSettings($values);
        Platform::log(null, 'admin', 'ai.settings', 'تعديل إعدادات الذكاء الاصطناعي (' . Ai::PROVIDERS[$provider] . ')' . ($key !== '' || $gkey !== '' ? ' وتحديث المفتاح' : ''), $this->admin());
        Session::pull('_old');
        return $this->ok('تم حفظ الإعدادات.', '/admin/ai');
    }

    /** نماذج Gemini المتاحة للمفتاح (المكتوب أو المحفوظ) */
    public function geminiModels(Request $request): Response
    {
        Session::release();
        $r = Ai::geminiModels(['key' => trim((string) $request->input('key', ''))]);
        $r['message'] = Ai::scrub($r['message']);
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    /** زر الإيقاف العام: يطفئ الذكاء الاصطناعي عن المنصة كلها فوراً */
    public function toggle(Request $request): Response
    {
        $on = $request->bool('enabled');
        Platform::setSettings(['ai.enabled' => $on ? '1' : '0']);
        Platform::log(null, 'admin', 'ai.toggle', $on ? 'تشغيل الذكاء الاصطناعي' : 'إيقاف الذكاء الاصطناعي عن المنصة كلها', $this->admin());
        return $this->ok($on ? 'تم تشغيل الذكاء الاصطناعي.' : 'تم إيقاف الذكاء الاصطناعي عن المنصة كلها.', '/admin/ai');
    }

    public function test(Request $request): Response
    {
        Session::release();
        $provider = $request->str('provider');
        $r = Ai::test($this->admin(), [
            'provider' => $provider,
            'key'      => trim((string) $request->input($provider === 'gemini' ? 'gemini_key' : 'api_key', '')),
            'model'    => $provider === 'gemini' ? Ai::cleanGeminiModel($request->str('gemini_model')) : $request->str('model'),
        ]);
        return json_response(['ok' => $r['ok'], 'message' => $r['message'], 'ms' => $r['ms'] ?? null], $r['ok'] ? 200 : 422);
    }

    /** تفعيل الذكاء الاصطناعي لكل حساب على حدة مع حده الشهري */
    public function tenants(Request $request): Response
    {
        $enabled = array_flip(array_map('intval', $request->arr('enabled')));
        $limits = $request->arr('limit');
        foreach (DB::rows("SELECT id FROM tenants WHERE status IN ('trial', 'active')") as $t) {
            $id = (int) $t['id'];
            $raw = trim(latin_digits((string) ($limits[$id] ?? '')));
            DB::update('tenants', ['ai_enabled' => isset($enabled[$id]) ? 1 : 0, 'ai_limit' => $raw === '' ? null : max(0, min(1000000, (int) $raw))], 'id = ?', [$id]);
        }
        Platform::log(null, 'admin', 'ai.tenants', 'تعديل تفعيل الذكاء الاصطناعي وحدوده للحسابات', $this->admin());
        return $this->ok('تم الحفظ.', '/admin/ai#accounts');
    }

    // ------------------------------------------------------------------
    // مساعد المسؤول (قراءة فقط)
    // ------------------------------------------------------------------

    public function assistant(Request $request): Response
    {
        return view('admin/assistant', ['state' => Ai::status(null)], 'admin');
    }

    public function ask(Request $request): Response
    {
        $in = $request->json();
        $messages = [];
        foreach (array_slice((array) ($in['messages'] ?? []), -12) as $m) {
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
        if (!$messages || $messages[count($messages) - 1]['role'] !== 'user') {
            return json_response(['ok' => false, 'message' => 'اكتب سؤالك.'], 422);
        }
        if (!Ai::available(null)) {
            return json_response(['ok' => false, 'message' => Ai::stateLabel(Ai::status(null))], 422);
        }
        Session::release();
        $hints = \App\Platform\AdminAssistantActions::hints();
        $system = 'أنت مساعد مسؤول منصة ' . \App\Core\Brand::NAME . ' لإدارة اشتراكات شبكات الإنترنت. ' . (\App\Core\Lang::isEn() ? 'أجب بالإنجليزية' : 'أجب بالعربية') . ' بإيجاز ووضوح ودقة اعتماداً على بيانات المنصة التالية فقط، واذكر الأرقام والأسماء كما هي. '
            . 'إن لم تكن المعلومة ضمن البيانات فقل إنها غير متوفرة ولا تخمّن. العملة: ' . Platform::setting('platform.currency') . ". التاريخ الآن: " . date('Y-m-d H:i') . ".\n"
            . ($hints
                ? 'لا تنفّذ أي شيء بنفسك ولا تقل إنك نفّذته. إذا طلب المسؤول أمراً من القائمة التالية فجهّزه بسطر مستقل في آخر ردّك بهذه الصيغة تماماً: <<ACTION {...}>> فتفتح له نافذة فيها ما سيُنفَّذ وحقول يراجعها ثم يضغط «موافق». ثلاثة إجراءات على الأكثر، ولا تجهّز ما لم يُطلب صراحة. الإجراءات المتاحة:' . "\n" . implode("\n", $hints) . "\n"
                : 'دورك للقراءة والتحليل فقط: لا تنفّذ أي إجراء ولا تدّعِ تنفيذه.' . "\n")
            . "\n" . 'بيانات المنصة (JSON):' . "\n" . json_encode(Insights::context(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = Ai::ask('admin_chat', $system, $messages, ['effort' => 'medium', 'actor' => $this->admin()]);
        if (!$r['ok']) {
            return json_response(['ok' => false, 'text' => '', 'message' => $r['message']], 422);
        }
        Session::resume();
        [$text, $actions, $notes] = \App\Platform\AdminAssistantActions::extract((string) $r['text']);
        Session::release();
        if ($notes) {
            $text = trim($text . "\n\n" . implode("\n", $notes));
        }
        if ($text === '' && $actions) {
            $text = 'جهّزت لك الإجراء التالي، ولن يُنفَّذ قبل موافقتك.';
        }
        return json_response(['ok' => true, 'text' => $text, 'message' => '', 'actions' => $actions]);
    }

    /** تنفيذ إجراء جهّزه مساعد المسؤول بعد «موافق» في النافذة */
    public function assistantConfirm(Request $request): Response
    {
        $in = $request->json();
        $r = \App\Platform\AdminAssistantActions::confirm((string) ($in['id'] ?? ''), is_array($in['values'] ?? null) ? $in['values'] : []);
        return json_response($r, $r['ok'] ? 200 : 422);
    }

    // ------------------------------------------------------------------
    // التحليلات وصحة الحسابات
    // ------------------------------------------------------------------

    public function insights(Request $request): Response
    {
        $accounts = Insights::accounts();
        $sort = $request->str('sort');
        usort($accounts, static function ($a, $b) use ($sort) {
            return match ($sort) {
                'subscribers' => (int) $b['stat_subscribers'] <=> (int) $a['stat_subscribers'],
                'paid' => (float) $b['paid_total'] <=> (float) $a['paid_total'],
                'risk' => $b['risk'] <=> $a['risk'],
                default => strcmp((string) ($b['last_seen_at'] ?? ''), (string) ($a['last_seen_at'] ?? '')),
            };
        });
        return view('admin/insights', [
            's' => Insights::snapshot(), 'accounts' => $accounts, 'sort' => $sort,
            'risk' => array_values(array_filter($accounts, static fn ($t) => $t['risk'] >= 40)),
        ], 'admin');
    }
}
