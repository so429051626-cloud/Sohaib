<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Platform;
use App\Core\Tenant;
use Throwable;

/**
 * عميل الذكاء الاصطناعي: Claude من Anthropic (واجهة Messages) أو Gemini من Google (واجهة generateContent)،
 * يختار المسؤول المزوّد ويحفظ مفتاح كلٍّ منهما منفصلاً، مع الضوابط نفسها للاثنين:
 * المفتاح مشفّر بمفتاح المنصة، زر إيقاف عام، تفعيل لكل حساب، حد شهري للرسائل، وتسجيل الاستهلاك والتكلفة.
 * عند أي تعذّر يرجع نتيجة «غير متاح» دون أن يتأثر باقي النظام.
 */
final class Ai
{
    public const API_VERSION = '2026-10-21';
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** النماذج المتاحة: المعرّف => [الاسم، سعر الإدخال لكل مليون رمز بالدولار، سعر الإخراج، يدعم البديل التلقائي عند الاعتذار] */
    public const MODELS = [
        'claude-opus-5-5'   => ['Claude Opus 5.5', 4.00, 20.00, true],
        'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 2.00, 10.00, true],
        'claude-haiku-5-5'  => ['Claude Haiku 5.5', 0.10, 0.50, false],
        'claude-fable-5-2'  => ['Claude Fable 5.2', 8.00, 40.00, true],
    ];

    public const STATES = [
        'ok'         => 'متاح',
        'off'        => 'الذكاء الاصطناعي متوقف',
        'no_key'     => 'لم يُضبط مفتاح الذكاء الاصطناعي',
        'tenant_off' => 'غير مفعّل لهذا الحساب',
        'limit'      => 'انتهى الحد الشهري للرسائل',
    ];

    public const EFFORTS = ['low', 'medium', 'high'];

    public const PROVIDERS = ['anthropic' => 'Claude · Anthropic', 'gemini' => 'Gemini · Google'];

    /** الاسم المستعار لأحدث نموذج Flash، يُستعمل حتى يختار المسؤول نموذجاً من قائمة مفتاحه */
    public const GEMINI_DEFAULT = 'gemini-flash-latest';

    /** أسعار تقديرية لعائلات Gemini بالدولار لكل مليون رمز [إدخال، إخراج] — للتكلفة التقديرية في الاستهلاك فقط */
    private const GEMINI_PRICES = ['flash-lite' => [0.10, 0.40], 'flash' => [0.30, 2.50], 'pro' => [1.25, 10.00]];

    /** إعدادات مؤقتة للاختبار قبل الحفظ (المزوّد والمفتاح والنموذج) */
    private static ?array $override = null;

    private static function base(): string
    {
        return rtrim((string) Config::get('ai.api_base', 'https://api.anthropic.com'), '/');
    }

    private static function geminiBase(): string
    {
        return rtrim((string) Config::get('ai.gemini_base', 'https://generativelanguage.googleapis.com'), '/');
    }

    /** المزوّد المختار: anthropic أو gemini */
    public static function provider(): string
    {
        $p = self::$override['provider'] ?? (Platform::enabled() ? Platform::setting('ai.provider', 'anthropic') : 'anthropic');
        return isset(self::PROVIDERS[$p]) ? $p : 'anthropic';
    }

    private static function keySetting(string $provider): string
    {
        return $provider === 'gemini' ? 'ai.gemini_key' : 'ai.api_key';
    }

    /** المفتاح بعد فك تشفيره (لا يُعرض ولا يُسجَّل في أي مكان) */
    private static function key(?string $provider = null): string
    {
        $provider ??= self::provider();
        if (self::$override !== null && ($provider === self::provider()) && (self::$override['key'] ?? '') !== '') {
            return (string) self::$override['key'];
        }
        return Platform::enabled() ? trim(Platform::secret(self::keySetting($provider))) : '';
    }

    public static function hasKey(?string $provider = null): bool
    {
        return self::key($provider) !== '';
    }

    public static function setKey(string $plain, string $provider = 'anthropic'): void
    {
        $values = [self::keySetting($provider) => Platform::seal(trim($plain)), ($provider === 'gemini' ? 'ai.gemini_key_saved_at' : 'ai.key_saved_at') => date('Y-m-d H:i:s')];
        if ($provider !== 'gemini') {
            $values['ai.no_fallback'] = '0';
        }
        Platform::setSettings($values);
    }

    /** اسم نموذج Gemini بعد تنظيفه (يقبل الصيغة models/…) أو فارغ إن كان غير صالح */
    public static function cleanGeminiModel(string $raw): string
    {
        $m = strtolower(trim($raw));
        if (str_starts_with($m, 'models/')) {
            $m = substr($m, 7);
        }
        return preg_match('/^[a-z0-9][a-z0-9.\-]{2,80}$/', $m) ? $m : '';
    }

    public static function model(): string
    {
        if (self::provider() === 'gemini') {
            $m = self::cleanGeminiModel((string) (self::$override['model'] ?? '') ?: Platform::setting('ai.gemini_model', ''));
            return $m !== '' ? $m : self::GEMINI_DEFAULT;
        }
        $m = (string) (self::$override['model'] ?? '') ?: Platform::setting('ai.model');
        return isset(self::MODELS[$m]) ? $m : self::DEFAULT_MODEL;
    }

    /** اسم النموذج للعرض */
    public static function modelLabel(?string $model = null): string
    {
        $model ??= self::model();
        return self::MODELS[$model][0] ?? $model;
    }

    /** حالة التوفّر للسياق الحالي (حساب الزبون الحالي أو لوحة المسؤول) */
    public static function status(?array $tenant = null): string
    {
        if (!Platform::enabled() || Platform::setting('ai.enabled') !== '1') {
            return 'off';
        }
        if (!self::hasKey()) {
            return 'no_key';
        }
        $tenant ??= Tenant::current();
        if ($tenant !== null) {
            if (empty($tenant['ai_enabled'])) {
                return 'tenant_off';
            }
            if (self::used((int) $tenant['id']) >= Tenant::aiLimit($tenant)) {
                return 'limit';
            }
        }
        return 'ok';
    }

    /**
     * الميزات الذكية معروضة في هذا السياق: الذكاء مشغّل في المنصة ومفعّل للحساب.
     * إن تعذّر استخدامه مؤقتاً (لا مفتاح، انتهى حد الشهر) تبقى أزراره ظاهرة بحالة «غير متاح».
     */
    public static function offered(?array $tenant = null): bool
    {
        return !in_array(self::status($tenant), ['off', 'tenant_off'], true);
    }

    public static function available(?array $tenant = null): bool
    {
        return self::status($tenant) === 'ok';
    }

    public static function stateLabel(string $state): string
    {
        return self::STATES[$state] ?? 'غير متاح';
    }

    /** عدد الرسائل الناجحة لحساب في شهر (الافتراضي الشهر الحالي) */
    public static function used(int $tenantId, ?string $month = null): int
    {
        $from = ($month ?? date('Y-m')) . '-01 00:00:00';
        $to = date('Y-m-01 00:00:00', strtotime($from . ' +1 month'));
        try {
            $r = Platform::row('SELECT COUNT(*) AS c FROM ai_usage WHERE tenant_id = ? AND ok = 1 AND created_at >= ? AND created_at < ?', [$tenantId, $from, $to]);
            return (int) ($r['c'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** حذف المفاتيح وأي رمز يشبهها من نص قبل عرضه أو تسجيله */
    public static function scrub(string $text): string
    {
        foreach (array_unique([self::key('anthropic'), self::key('gemini'), (string) (self::$override['key'] ?? '')]) as $key) {
            if ($key !== '') {
                $text = str_replace($key, '***', $text);
            }
        }
        return (string) preg_replace(['/sk-ant-[A-Za-z0-9_\-]{6,}/', '/AIza[0-9A-Za-z_\-]{20,}/'], '***', $text);
    }

    public static function cost(string $model, int $input, int $output): float
    {
        if (isset(self::MODELS[$model])) {
            $p = self::MODELS[$model];
            return round(($input * $p[1] + $output * $p[2]) / 1000000, 6);
        }
        if (str_starts_with($model, 'gemini')) {
            $family = str_contains($model, 'flash-lite') ? 'flash-lite' : (str_contains($model, 'pro') ? 'pro' : 'flash');
            [$in, $out] = self::GEMINI_PRICES[$family];
            return round(($input * $in + $output * $out) / 1000000, 6);
        }
        $p = self::MODELS[self::DEFAULT_MODEL];
        return round(($input * $p[1] + $output * $p[2]) / 1000000, 6);
    }

    /** سعر قراءة الذاكرة المؤقتة لكل مليون رمز (غير المذكور: عُشر سعر الإدخال) */
    private const CACHE_READ = ['claude-opus-5-5' => 0.20, 'claude-sonnet-5-5' => 0.10, 'claude-fable-5-2' => 0.20];

    /** التكلفة مع الذاكرة المؤقتة: الكتابة فيها بـ 1.25 من سعر الإدخال، والقراءة منها بسعرها المخفّض */
    public static function cachedCost(string $model, int $input, int $output, int $cacheWrite, int $cacheRead): float
    {
        if (!isset(self::MODELS[$model])) {
            return self::cost($model, $input + $cacheWrite + $cacheRead, $output);
        }
        $p = self::MODELS[$model];
        $read = self::CACHE_READ[$model] ?? $p[1] / 10;
        return round(($input * $p[1] + $cacheWrite * $p[1] * 1.25 + $cacheRead * $read + $output * $p[2]) / 1000000, 6);
    }

    /**
     * محادثة بأدوات: يطلب النموذج أداة فينفّذها النظام ويعيد نتيجتها، حتى يكتب الرد النهائي.
     * معلومات النظام تُخزَّن مؤقتاً لدى المزوّد فلا تُحتسب كاملة في كل رسالة، والاستهلاك يُسجَّل مرة واحدة للرد كله.
     * $system نص، أو [الثابت، المتغيّر]: الثابت وحده يُخزَّن مؤقتاً، والمتغيّر (الوقت وبيانات الزبون) يأتي بعده.
     * @param array<int, array{role:string, content:string}> $messages
     * @param list<array{name:string, description:string, input_schema:array}> $tools
     * @param callable(string, array): mixed $run تنفيذ أداة باسمها ومدخلاتها، ويرجع مصفوفة أو نصاً
     * @param array{effort?:string, max_tokens?:int, timeout?:int, actor?:string, steps?:int} $opts
     * @return array{ok:bool, state:string, text:string, message:string, tools:list<string>, model?:string, input?:int, output?:int, cost?:float}
     */
    public static function converse(string $feature, string|array $system, array $messages, array $tools, callable $run, array $opts = []): array
    {
        $state = self::status();
        if ($state !== 'ok') {
            return ['ok' => false, 'state' => $state, 'text' => '', 'message' => self::stateLabel($state), 'tools' => []];
        }
        $opts['steps'] = max(1, min(8, (int) ($opts['steps'] ?? 5)));
        $system = array_values(array_filter(is_array($system) ? array_map('strval', $system) : [$system], static fn ($s) => trim($s) !== ''));
        return self::provider() === 'gemini'
            ? self::converseGemini($feature, $system, $messages, $tools, $run, $opts)
            : self::converseClaude($feature, $system, $messages, $tools, $run, $opts);
    }

    /** تنفيذ أداة بأمان: الخطأ يرجع للنموذج نصاً بدل أن يوقف الرد */
    private static function runTool(callable $run, string $name, array $input): array
    {
        try {
            $out = $run($name, $input);
            return [is_string($out) ? $out : (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false];
        } catch (Throwable $e) {
            log_message('warning', 'ai tool ' . $name . ': ' . self::scrub($e->getMessage()));
            return ['تعذّر تنفيذ هذه الأداة الآن.', true];
        }
    }

    /** الكائن الفارغ يبقى كائناً في JSON (المدخلات والخصائص) */
    private static function objectify(array $schema): array
    {
        if (array_key_exists('properties', $schema) && $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }
        return $schema;
    }

    private static function converseClaude(string $feature, array $system, array $messages, array $tools, callable $run, array $opts): array
    {
        $model = self::model();
        $effort = in_array($opts['effort'] ?? '', self::EFFORTS, true) ? $opts['effort'] : 'low';
        $base = [
            'model' => $model,
            'max_tokens' => max(512, min(32000, (int) ($opts['max_tokens'] ?? 4000))),
            'output_config' => ['effort' => $effort],
        ];
        if ($system) {
            $base['system'] = [];
            foreach ($system as $i => $part) {
                $base['system'][] = ['type' => 'text', 'text' => $part] + ($i === 0 ? ['cache_control' => ['type' => 'ephemeral']] : []);
            }
        }
        if ($tools) {
            $base['tools'] = array_map(static fn (array $t) => [
                'name' => $t['name'], 'description' => $t['description'], 'input_schema' => self::objectify($t['input_schema']),
            ], $tools);
        }
        $timeout = max(10, min(300, (int) ($opts['timeout'] ?? 90)));
        @set_time_limit($timeout * ($opts['steps'] + 1) + 30);
        $tenantId = Tenant::id();
        $actor = isset($opts['actor']) ? mb_substr((string) $opts['actor'], 0, 120) : null;
        $msgs = array_values($messages);
        $used = [];
        $in = $out = $cw = $cr = 0;
        $served = $model;
        $text = '';
        $refused = false;
        for ($step = 0; $step < $opts['steps']; $step++) {
            $body = $base + ['messages' => $msgs];
            if ($tools && $step === $opts['steps'] - 1) {
                // آخر خطوة: لا أدوات، يكتب الرد بما جمعه
                $body['tool_choice'] = ['type' => 'none'];
            }
            $res = self::request($body, $timeout);
            if ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500) {
                usleep(1200000);
                $res = self::request($body, $timeout);
            }
            $data = $res['data'];
            if ($res['status'] !== 200 || !is_array($data)) {
                log_message('warning', 'ai ' . $feature . ': HTTP ' . $res['status'] . ' ' . self::scrub($res['error']));
                self::record($tenantId, $feature, $served, $in + $cw + $cr, $out, false, $actor);
                return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => self::errorMessage($res), 'tools' => $used];
            }
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $in += (int) ($usage['input_tokens'] ?? 0);
            $cw += (int) ($usage['cache_creation_input_tokens'] ?? 0);
            $cr += (int) ($usage['cache_read_input_tokens'] ?? 0);
            $out += (int) ($usage['output_tokens'] ?? 0);
            $served = isset(self::MODELS[$data['model'] ?? '']) ? (string) $data['model'] : $served;
            $content = array_values(array_filter((array) ($data['content'] ?? []), 'is_array'));
            $stop = (string) ($data['stop_reason'] ?? '');
            if ($stop === 'refusal') {
                $refused = true;
                break;
            }
            $calls = array_values(array_filter($content, static fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            if ($stop === 'tool_use' && $calls) {
                // الرد يُعاد كما هو (ومعه كتل التفكير) ثم نتائج كل الأدوات في رسالة واحدة
                foreach ($content as $i => $b) {
                    if (($b['type'] ?? '') === 'tool_use' && ($b['input'] ?? []) === []) {
                        $content[$i]['input'] = new \stdClass();
                    }
                }
                $msgs[] = ['role' => 'assistant', 'content' => $content];
                $results = [];
                foreach ($calls as $c) {
                    $name = (string) ($c['name'] ?? '');
                    $used[] = $name;
                    [$result, $isError] = self::runTool($run, $name, is_array($c['input'] ?? null) ? $c['input'] : []);
                    $block = ['type' => 'tool_result', 'tool_use_id' => (string) ($c['id'] ?? ''), 'content' => $result];
                    if ($isError) {
                        $block['is_error'] = true;
                    }
                    $results[] = $block;
                }
                $msgs[] = ['role' => 'user', 'content' => $results];
                continue;
            }
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $text .= (string) ($b['text'] ?? '');
                }
            }
            break;
        }
        $text = trim($text);
        $ok = !$refused && $text !== '';
        $cost = self::cachedCost($served, $in, $out, $cw, $cr);
        self::record($tenantId, $feature, $served, $in + $cw + $cr, $out, $ok, $actor, $cost);
        if (!$ok) {
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => $refused ? 'اعتذر النموذج عن الإجابة على هذا الطلب.' : 'لم يصل رد من النموذج.', 'tools' => $used];
        }
        return ['ok' => true, 'state' => 'ok', 'text' => $text, 'message' => '', 'tools' => $used, 'model' => $served, 'input' => $in + $cw + $cr, 'output' => $out, 'cost' => $cost];
    }

    /** مخطط مدخلات الأداة بصيغة Gemini (مجموعة جزئية من OpenAPI) */
    private static function geminiSchema(array $s): array
    {
        $out = [];
        if (isset($s['type'])) {
            $out['type'] = strtoupper((string) $s['type']);
        }
        foreach (['description', 'enum', 'format'] as $k) {
            if (isset($s[$k])) {
                $out[$k] = $s[$k];
            }
        }
        if (!empty($s['properties']) && is_array($s['properties'])) {
            $out['properties'] = [];
            foreach ($s['properties'] as $name => $p) {
                $out['properties'][$name] = self::geminiSchema((array) $p);
            }
            if (!empty($s['required'])) {
                $out['required'] = array_values((array) $s['required']);
            }
        }
        if (isset($s['items']) && is_array($s['items'])) {
            $out['items'] = self::geminiSchema($s['items']);
        }
        return $out;
    }

    private static function converseGemini(string $feature, array $system, array $messages, array $tools, callable $run, array $opts): array
    {
        $model = self::model();
        $contents = self::geminiContents($messages);
        $base = ['generationConfig' => ['maxOutputTokens' => max(512, min(32000, (int) ($opts['max_tokens'] ?? 4000)))]];
        if ($system) {
            $base['systemInstruction'] = ['parts' => array_map(static fn ($t) => ['text' => $t], $system)];
        }
        if ($tools) {
            $base['tools'] = [['functionDeclarations' => array_map(static function (array $t): array {
                $d = ['name' => $t['name'], 'description' => $t['description']];
                if (!empty($t['input_schema']['properties'])) {
                    $d['parameters'] = self::geminiSchema($t['input_schema']);
                }
                return $d;
            }, $tools)]];
        }
        $timeout = max(10, min(300, (int) ($opts['timeout'] ?? 90)));
        @set_time_limit($timeout * ($opts['steps'] + 1) + 30);
        $tenantId = Tenant::id();
        $actor = isset($opts['actor']) ? mb_substr((string) $opts['actor'], 0, 120) : null;
        $url = self::geminiBase() . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $used = [];
        $in = $out = 0;
        $served = $model;
        $text = '';
        $blocked = false;
        for ($step = 0; $step < $opts['steps']; $step++) {
            $body = $base + ['contents' => $contents];
            if ($tools && $step === $opts['steps'] - 1) {
                $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'NONE']];
            }
            $res = self::geminiHttp('POST', $url, $body, $timeout);
            if ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500) {
                usleep(1200000);
                $res = self::geminiHttp('POST', $url, $body, $timeout);
            }
            $data = $res['data'];
            if ($res['status'] !== 200 || !is_array($data)) {
                log_message('warning', 'ai ' . $feature . ' (gemini): HTTP ' . $res['status'] . ' ' . self::scrub($res['error']));
                self::record($tenantId, $feature, $served, $in, $out, false, $actor);
                return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => self::geminiError($res), 'tools' => $used];
            }
            $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
            $in += (int) ($usage['promptTokenCount'] ?? 0);
            $out += (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
            $served = self::cleanGeminiModel((string) ($data['modelVersion'] ?? '')) ?: $served;
            $cand = (array) ($data['candidates'][0] ?? []);
            $parts = array_values(array_filter((array) ($cand['content']['parts'] ?? []), 'is_array'));
            $blocked = ($data['promptFeedback']['blockReason'] ?? '') !== ''
                || in_array((string) ($cand['finishReason'] ?? ''), ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true);
            $calls = array_values(array_filter($parts, static fn ($p) => isset($p['functionCall']['name'])));
            if ($calls && !$blocked) {
                // أجزاء رد النموذج تُعاد كما هي (مع بصمات التفكير) ثم نتائج الأدوات
                $contents[] = ['role' => 'model', 'parts' => $parts];
                $responses = [];
                foreach ($calls as $c) {
                    $name = (string) $c['functionCall']['name'];
                    $used[] = $name;
                    [$result] = self::runTool($run, $name, is_array($c['functionCall']['args'] ?? null) ? $c['functionCall']['args'] : []);
                    $decoded = json_decode($result, true);
                    $responses[] = ['functionResponse' => ['name' => $name, 'response' => ['result' => $decoded ?? $result]]];
                }
                $contents[] = ['role' => 'user', 'parts' => $responses];
                continue;
            }
            foreach ($parts as $p) {
                if (!($p['thought'] ?? false) && isset($p['text'])) {
                    $text .= (string) $p['text'];
                }
            }
            break;
        }
        $text = trim($text);
        $ok = $text !== '';
        self::record($tenantId, $feature, $served, $in, $out, $ok, $actor);
        if (!$ok) {
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => $blocked ? 'اعتذر النموذج عن الإجابة على هذا الطلب.' : 'لم يصل رد من النموذج.', 'tools' => $used];
        }
        return ['ok' => true, 'state' => 'ok', 'text' => $text, 'message' => '', 'tools' => $used, 'model' => $served, 'input' => $in, 'output' => $out, 'cost' => self::cost($served, $in, $out)];
    }

    /**
     * سؤال النموذج.
     * @param array<int, array{role:string, content:mixed}> $messages
     * @param array{effort?:string, max_tokens?:int, timeout?:int, actor?:string} $opts
     * @return array{ok:bool, state:string, text:string, message:string, model?:string, input?:int, output?:int, cost?:float}
     */
    public static function ask(string $feature, string $system, array $messages, array $opts = []): array
    {
        $state = self::status();
        if ($state !== 'ok') {
            return ['ok' => false, 'state' => $state, 'text' => '', 'message' => self::stateLabel($state)];
        }
        return self::run($feature, $system, $messages, $opts);
    }

    /**
     * اختبار الاتصال بالمفتاح والنموذج المحددين (يعمل حتى والذكاء متوقف).
     * @param array{provider?:string, key?:string, model?:string} $with قيم النموذج قبل حفظها (الفارغ يعني المحفوظ)
     */
    public static function test(?string $actor = null, array $with = []): array
    {
        if (!Platform::enabled()) {
            return ['ok' => false, 'state' => 'no_key', 'text' => '', 'message' => self::stateLabel('no_key')];
        }
        $provider = isset(self::PROVIDERS[$with['provider'] ?? '']) ? (string) $with['provider'] : self::provider();
        self::$override = ['provider' => $provider, 'key' => trim((string) ($with['key'] ?? '')), 'model' => trim((string) ($with['model'] ?? ''))];
        try {
            if (!self::hasKey()) {
                return ['ok' => false, 'state' => 'no_key', 'text' => '', 'message' => self::stateLabel('no_key')];
            }
            $started = microtime(true);
            $r = self::run('test', '', [['role' => 'user', 'content' => 'أجب بكلمة واحدة فقط: جاهز']], ['effort' => 'low', 'max_tokens' => 2048, 'timeout' => 45, 'actor' => $actor]);
            $r['ms'] = (int) round((microtime(true) - $started) * 1000);
            if ($r['ok']) {
                $r['message'] = 'الاتصال يعمل · ' . self::modelLabel($r['model'] ?? null);
            }
            return $r;
        } finally {
            self::$override = null;
        }
    }

    private static function run(string $feature, string $system, array $messages, array $opts): array
    {
        if (self::provider() === 'gemini') {
            return self::runGemini($feature, $system, $messages, $opts);
        }
        $model = self::model();
        $effort = in_array($opts['effort'] ?? '', self::EFFORTS, true) ? $opts['effort'] : 'medium';
        $body = [
            'model' => $model,
            'max_tokens' => max(256, min(32000, (int) ($opts['max_tokens'] ?? 16000))),
            'messages' => array_values($messages),
            'output_config' => ['effort' => $effort],
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        $timeout = max(10, min(300, (int) ($opts['timeout'] ?? 120)));
        @set_time_limit($timeout * 2 + 30);
        $tenantId = Tenant::id();
        $actor = isset($opts['actor']) ? mb_substr((string) $opts['actor'], 0, 120) : null;

        $res = self::request($body, $timeout);
        if ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500) {
            usleep(1200000);
            $res = self::request($body, $timeout);
        }
        $data = $res['data'];
        if ($res['status'] !== 200 || !is_array($data)) {
            $message = self::errorMessage($res);
            log_message('warning', 'ai ' . $feature . ': HTTP ' . $res['status'] . ' ' . self::scrub($res['error']));
            self::record($tenantId, $feature, $model, 0, 0, false, $actor);
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => $message];
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $in = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        $served = isset(self::MODELS[$data['model'] ?? '']) ? (string) $data['model'] : $model;
        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $text = trim($text);
        $refused = ($data['stop_reason'] ?? '') === 'refusal';
        $ok = !$refused && $text !== '';
        self::record($tenantId, $feature, $served, $in, $out, $ok, $actor);
        if (!$ok) {
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => $refused ? 'اعتذر النموذج عن الإجابة على هذا الطلب.' : 'لم يصل رد من النموذج.'];
        }
        return ['ok' => true, 'state' => 'ok', 'text' => $text, 'message' => '', 'model' => $served, 'input' => $in, 'output' => $out, 'cost' => self::cost($served, $in, $out)];
    }

    /**
     * طلب HTTP واحد. مع النماذج التي تدعمه يُفعَّل البديل التلقائي عند اعتذار النموذج،
     * وإن رفضه الحساب يُعاد الطلب دونه ويُحفظ ذلك.
     * @return array{status:int, data:?array, error:string}
     */
    private static function request(array $body, int $timeout): array
    {
        $fallback = (self::MODELS[$body['model']][3] ?? false) && Platform::setting('ai.no_fallback', '0') !== '1';
        $res = self::http($fallback ? $body + ['fallbacks' => 'default'] : $body, $timeout, $fallback);
        if ($fallback && $res['status'] === 400 && stripos($res['error'], 'fallback') !== false) {
            Platform::setSettings(['ai.no_fallback' => '1']);
            $res = self::http($body, $timeout, false);
        }
        return $res;
    }

    private static function http(array $body, int $timeout, bool $beta): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'cURL غير متوفر'];
        }
        $headers = ['Content-Type: application/json', 'x-api-key: ' . self::key(), 'anthropic-version: ' . self::API_VERSION];
        if ($beta) {
            $headers[] = 'anthropic-beta: ' . self::FALLBACK_BETA;
        }
        $ch = curl_init(self::base() . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false) {
            return ['status' => 0, 'data' => null, 'error' => $err !== '' ? $err : 'connection failed'];
        }
        $data = json_decode((string) $raw, true);
        $error = '';
        if (is_array($data) && isset($data['error'])) {
            $error = (string) (is_array($data['error']) ? (($data['error']['type'] ?? '') . ': ' . ($data['error']['message'] ?? '')) : $data['error']);
        } elseif (!is_array($data)) {
            $error = 'unexpected response';
        }
        return ['status' => $status, 'data' => is_array($data) ? $data : null, 'error' => $error];
    }

    private static function errorMessage(array $res): string
    {
        return match (true) {
            $res['status'] === 0 => 'تعذّر الاتصال بخدمة الذكاء الاصطناعي.',
            $res['status'] === 401 => 'مفتاح الذكاء الاصطناعي غير صحيح.',
            $res['status'] === 402 => 'رصيد حساب الذكاء الاصطناعي غير كافٍ.',
            $res['status'] === 403 => 'المفتاح لا يملك صلاحية استخدام هذا النموذج.',
            $res['status'] === 404 => 'النموذج المحدد غير متاح لهذا المفتاح.',
            $res['status'] === 413 => 'الطلب أكبر من الحد المسموح.',
            $res['status'] === 429 => 'تم تجاوز حد الطلبات. حاول بعد قليل.',
            $res['status'] >= 500 => 'خدمة الذكاء الاصطناعي مشغولة حالياً. حاول بعد قليل.',
            default => 'تعذّر تنفيذ الطلب: ' . mb_substr(self::scrub($res['error']), 0, 160),
        };
    }

    // ------------------------------------------------------------------
    // Gemini (Google)
    // ------------------------------------------------------------------

    private static function runGemini(string $feature, string $system, array $messages, array $opts): array
    {
        $model = self::model();
        $contents = self::geminiContents($messages);
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => max(256, min(32000, (int) ($opts['max_tokens'] ?? 16000)))],
        ];
        return self::finishGemini($feature, $system, $model, $body, $opts);
    }

    /** رسائل المحادثة بصيغة Gemini (النص والصور) */
    private static function geminiContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $m) {
            $parts = [];
            $content = $m['content'] ?? '';
            if (is_string($content)) {
                $parts[] = ['text' => $content];
            } else {
                foreach ((array) $content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    if (($block['type'] ?? '') === 'text') {
                        $parts[] = ['text' => (string) ($block['text'] ?? '')];
                    } elseif (($block['type'] ?? '') === 'image' && ($block['source']['type'] ?? '') === 'base64') {
                        $parts[] = ['inline_data' => ['mime_type' => (string) $block['source']['media_type'], 'data' => (string) $block['source']['data']]];
                    }
                }
            }
            if ($parts) {
                $contents[] = ['role' => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }
        }
        return $contents;
    }

    private static function finishGemini(string $feature, string $system, string $model, array $body, array $opts): array
    {
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        $timeout = max(10, min(300, (int) ($opts['timeout'] ?? 120)));
        @set_time_limit($timeout * 2 + 30);
        $tenantId = Tenant::id();
        $actor = isset($opts['actor']) ? mb_substr((string) $opts['actor'], 0, 120) : null;
        $url = self::geminiBase() . '/v1beta/models/' . rawurlencode($model) . ':generateContent';

        $res = self::geminiHttp('POST', $url, $body, $timeout);
        if ($res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500) {
            usleep(1200000);
            $res = self::geminiHttp('POST', $url, $body, $timeout);
        }
        $data = $res['data'];
        if ($res['status'] !== 200 || !is_array($data)) {
            log_message('warning', 'ai ' . $feature . ' (gemini): HTTP ' . $res['status'] . ' ' . self::scrub($res['error']));
            self::record($tenantId, $feature, $model, 0, 0, false, $actor);
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => self::geminiError($res)];
        }
        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        $in = (int) ($usage['promptTokenCount'] ?? 0);
        $out = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        $served = self::cleanGeminiModel((string) ($data['modelVersion'] ?? '')) ?: $model;
        $cand = (array) ($data['candidates'][0] ?? []);
        $text = '';
        foreach ((array) ($cand['content']['parts'] ?? []) as $part) {
            if (is_array($part) && !($part['thought'] ?? false) && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }
        $text = trim($text);
        $blocked = ($data['promptFeedback']['blockReason'] ?? '') !== ''
            || in_array((string) ($cand['finishReason'] ?? ''), ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION'], true);
        $ok = $text !== '';
        self::record($tenantId, $feature, $served, $in, $out, $ok, $actor);
        if (!$ok) {
            return ['ok' => false, 'state' => 'error', 'text' => '', 'message' => $blocked ? 'اعتذر النموذج عن الإجابة على هذا الطلب.' : 'لم يصل رد من النموذج.'];
        }
        return ['ok' => true, 'state' => 'ok', 'text' => $text, 'message' => '', 'model' => $served, 'input' => $in, 'output' => $out, 'cost' => self::cost($served, $in, $out)];
    }

    /** @return array{status:int, data:?array, error:string} */
    private static function geminiHttp(string $method, string $url, ?array $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'cURL غير متوفر'];
        }
        $ch = curl_init($url);
        $o = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . self::key('gemini')],
        ];
        if ($method === 'POST') {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        curl_setopt_array($ch, $o);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false) {
            return ['status' => 0, 'data' => null, 'error' => $err !== '' ? $err : 'connection failed'];
        }
        $data = json_decode((string) $raw, true);
        $error = '';
        if (is_array($data) && isset($data['error'])) {
            $error = is_array($data['error']) ? trim(($data['error']['status'] ?? '') . ': ' . ($data['error']['message'] ?? '')) : (string) $data['error'];
        } elseif (!is_array($data)) {
            $error = 'unexpected response';
        }
        return ['status' => $status, 'data' => is_array($data) ? $data : null, 'error' => $error];
    }

    private static function geminiError(array $res): string
    {
        $e = strtolower($res['error']);
        return match (true) {
            $res['status'] === 0 => 'تعذّر الاتصال بخدمة Gemini.',
            str_contains($e, 'api key not valid') || str_contains($e, 'api_key_invalid') || $res['status'] === 401 => 'مفتاح Gemini غير صحيح.',
            str_contains($e, 'location is not supported') => 'خدمة Gemini غير متاحة من موقع الخادم.',
            $res['status'] === 403 => 'المفتاح لا يملك صلاحية استخدام Gemini (فعّل Generative Language API لمشروع المفتاح).',
            $res['status'] === 404 => 'النموذج المحدد غير متاح لهذا المفتاح.',
            $res['status'] === 429 => 'تم تجاوز حد الطلبات أو الحصة المتاحة للمفتاح. حاول بعد قليل.',
            $res['status'] >= 500 => 'خدمة Gemini مشغولة حالياً. حاول بعد قليل.',
            default => 'تعذّر تنفيذ الطلب: ' . mb_substr(self::scrub($res['error']), 0, 160),
        };
    }

    /**
     * نماذج Gemini المتاحة للمفتاح والتي تكتب نصاً، الأحدث أولاً، مع أنسب نموذج افتراضي (أحدث Flash مستقر).
     * @param array{key?:string} $with مفتاح مكتوب قبل الحفظ
     * @return array{ok:bool, message:string, models:list<array{id:string, name:string}>, suggested:string}
     */
    public static function geminiModels(array $with = []): array
    {
        self::$override = ['provider' => 'gemini', 'key' => trim((string) ($with['key'] ?? '')), 'model' => ''];
        try {
            if (!Platform::enabled() || !self::hasKey('gemini')) {
                return ['ok' => false, 'message' => 'أدخل مفتاح Gemini.', 'models' => [], 'suggested' => ''];
            }
            $res = self::geminiHttp('GET', self::geminiBase() . '/v1beta/models?pageSize=1000', null, 20);
            if ($res['status'] !== 200 || !is_array($res['data'])) {
                return ['ok' => false, 'message' => self::geminiError($res), 'models' => [], 'suggested' => ''];
            }
            $models = [];
            foreach ((array) ($res['data']['models'] ?? []) as $m) {
                $id = self::cleanGeminiModel((string) ($m['name'] ?? ''));
                if ($id === '' || !str_starts_with($id, 'gemini') || !in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)
                    || preg_match('/(embedding|image|tts|audio|live|vision|robotics|computer-use|aqa)/', $id)) {
                    continue;
                }
                $models[$id] = ['id' => $id, 'name' => trim((string) ($m['displayName'] ?? '')) ?: $id];
            }
            uasort($models, static fn ($a, $b) => strnatcmp($b['id'], $a['id']));
            $models = array_values($models);
            return ['ok' => true, 'message' => $models ? 'وُجد ' . ar_plural(count($models), 'نموذج واحد', 'نموذجان', 'نماذج', 'نموذجاً') . '.' : 'لا توجد نماذج نصية متاحة لهذا المفتاح.', 'models' => $models, 'suggested' => self::suggestGemini(array_column($models, 'id'))];
        } finally {
            self::$override = null;
        }
    }

    /** أحدث نموذج Flash مستقر (بلا preview ولا exp ولا lite)، ثم أي Flash، ثم الاسم المستعار */
    public static function suggestGemini(array $ids): string
    {
        $flash = array_values(array_filter($ids, static fn ($id) => str_contains($id, 'flash') && !str_contains($id, 'lite') && !str_contains($id, 'latest')));
        $stable = array_values(array_filter($flash, static fn ($id) => !preg_match('/(preview|exp|\d{2}-\d{4}|-\d{3}$)/', $id)));
        usort($stable, 'strnatcmp');
        usort($flash, 'strnatcmp');
        return $stable ? end($stable) : ($flash ? end($flash) : self::GEMINI_DEFAULT);
    }

    private static function record(?int $tenantId, string $feature, string $model, int $in, int $out, bool $ok, ?string $actor, ?float $cost = null): void
    {
        try {
            Platform::exec(
                'INSERT INTO ai_usage (tenant_id, feature, model, input_tokens, output_tokens, cost, ok, actor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tenantId, mb_substr($feature, 0, 40), $model, $in, $out, $ok ? ($cost ?? self::cost($model, $in, $out)) : 0, $ok ? 1 : 0, $actor, date('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) {
            log_message('error', 'ai usage: ' . self::scrub($e->getMessage()));
        }
    }
}
