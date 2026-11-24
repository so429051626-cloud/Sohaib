<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Platform;
use App\Core\Settings;
use App\Core\Tenant;
use App\Core\Throttle;
use App\Platform\Support;

/**
 * أدوات بوت خدمة الزبائن للمنصة: قراءة فقط من المنصة ومن قواعد الشبكات (ومن MADIX Cloud إن كان مربوطاً).
 * الإجراءات الوحيدة: تحويل المحادثة لشخص، وفتح تذكرة دعم لصاحب الشبكة، وفتح تذكرة أو طلب تجديد في MADIX Cloud.
 * كل أداة تعرف صاحب المحادثة من رقمه الموثّق: صاحب الشبكة يرى شبكاته فقط، والمشترك حسابه فقط،
 * ولا تقبل أي أداة رقم هاتف أو حساباً آخر من المرسل.
 */
final class BotTools
{
    private const NONE = ['type' => 'object', 'properties' => []];

    /** أقصى طول لنتيجة أداة تُعاد للنموذج */
    private const MAX = 6000;

    private const NETWORK = ['type' => 'string', 'description' => 'اسم الشبكة أو اسم حسابها في الرابط كما كتبه المرسل؛ اتركه فارغاً إن كانت الشبكة معروفة من المحادثة'];

    /** @return list<array{name:string, description:string, input_schema:array}> */
    public static function definitions(array $chat): array
    {
        $id = BotAgent::identity($chat);
        $defs = [
            ['name' => 'platform_plans', 'description' => 'باقات الاشتراك في المنصة لأصحاب الشبكات: السعر والمدة والحدود والمزايا، ومدة التجربة المجانية ورابط التسجيل. بلا مدخلات.', 'input_schema' => self::NONE],
            ['name' => 'network_info', 'description' => 'معلومات شبكة للمشتركين: هاتفها وعنوانها، أسعار كروت الإنترنت وباقات الاشتراك الشهري، الانقطاعات الجارية الآن (وهل حساب المرسل ضمنها)، ونقاط بيع الكروت.',
             'input_schema' => ['type' => 'object', 'properties' => ['network' => self::NETWORK]]],
            ['name' => 'card_balance', 'description' => 'فحص كرت إنترنت برقمه أو رقمه المختصر في شبكة: الباقة، الحالة، الوقت والبيانات المتبقية، تاريخ الانتهاء. تحتاج اسم الشبكة إن لم تكن معروفة.',
             'input_schema' => ['type' => 'object', 'properties' => [
                 'code' => ['type' => 'string', 'description' => 'رقم الكرت (اسم المستخدم) أو الرقم المختصر كما كتبه المرسل'],
                 'password' => ['type' => 'string', 'description' => 'كلمة مرور الكرت، فقط إن كان رقماً مختصراً وطلبتها الأداة'],
                 'network' => self::NETWORK,
             ], 'required' => ['code']]],
            ['name' => 'handoff', 'description' => 'تحويل المحادثة لشخص: محادثة المشترك تذهب لصاحب شبكته، وغيرها لفريق المنصة؛ يتوقف المساعد عن الرد فيها حتى تُعاد له. اكتب السبب وملخصاً قصيراً لما يحتاجه المرسل.',
             'input_schema' => ['type' => 'object', 'properties' => [
                 'reason' => ['type' => 'string', 'description' => 'سبب التحويل باختصار'],
                 'summary' => ['type' => 'string', 'description' => 'ملخص طلب المرسل وما تم معه حتى الآن'],
             ], 'required' => ['reason', 'summary']]],
        ];
        if ($id['subs']) {
            $defs[] = ['name' => 'my_account', 'description' => 'حالة اشتراك المرسل نفسه في شبكته (الباقة، تاريخ الانتهاء، الاستهلاك، المبلغ المستحق) من رقمه الموثّق.',
                'input_schema' => ['type' => 'object', 'properties' => ['network' => self::NETWORK]]];
        }
        if ($id['owner']) {
            $defs = array_merge($defs, [
                ['name' => 'my_networks', 'description' => 'شبكات المرسل (صاحب الشبكة) واشتراك كل منها في المنصة: الحالة، الباقة، تاريخ الانتهاء والأيام المتبقية، الحدود، رابط النظام ورابط التجديد. بلا مدخلات.', 'input_schema' => self::NONE],
                ['name' => 'network_numbers', 'description' => 'أرقام شبكة المرسل الآن للقراءة فقط: المشتركون (الفعّالون، المنتهون، من ينتهي قريباً بأسمائهم)، مبيعات اليوم والتحصيل، الكروت، أجهزة MikroTik المتوقفة، الانقطاعات الجارية، التنبيهات غير المقروءة.',
                 'input_schema' => ['type' => 'object', 'properties' => ['network' => self::NETWORK]]],
                ['name' => 'support_tickets', 'description' => 'تذاكر الدعم بين شبكات المرسل وفريق المنصة: الحالة وآخر رد. بلا مدخلات.', 'input_schema' => self::NONE],
                ['name' => 'open_support_ticket', 'description' => 'فتح تذكرة دعم لفريق المنصة باسم شبكة المرسل، لمشكلة أو طلب لم يُحل بالشرح. اسأله عن التفاصيل أولاً.',
                 'input_schema' => ['type' => 'object', 'properties' => [
                     'subject' => ['type' => 'string', 'description' => 'موضوع قصير'],
                     'message' => ['type' => 'string', 'description' => 'التفاصيل كما شرحها المرسل'],
                     'category' => ['type' => 'string', 'enum' => array_keys(Support::CATEGORIES), 'description' => 'general استفسار، billing اشتراك ودفع، technical مشكلة تقنية، request طلب ميزة'],
                     'network' => self::NETWORK,
                 ], 'required' => ['subject', 'message']]],
            ]);
        }
        if (MadixCloud::enabled()) {
            $shop = ['type' => 'string', 'description' => 'رقم المحل (C-XXXXX)، فقط إن كان لرقم المرسل أكثر من محل وحدّد أيّها'];
            $defs = array_merge($defs, [
                ['name' => 'erp_my_account', 'description' => 'MADIX ERP (نظام الكاشير): محلات المرسل وحالة ترخيص كل محل (الباقة، الانتهاء، الأيام المتبقية، عدد الأجهزة، الميزات). تعتمد رقم واتساب الموثّق، فلا تعمل في نافذة الموقع. بلا مدخلات.', 'input_schema' => self::NONE],
                ['name' => 'erp_licenses', 'description' => 'MADIX ERP: تراخيص محل المرسل بالتفصيل لكل جهاز (الباقة، البداية، الانتهاء الفعلي، الحالة).',
                 'input_schema' => ['type' => 'object', 'properties' => ['customer' => $shop]]],
                ['name' => 'erp_devices', 'description' => 'MADIX ERP: أجهزة محل المرسل (رقم الجهاز، الاسم، ويندوز أو أندرويد، الإصدار المثبّت، آخر ظهور ومزامنة، متصل الآن، وهل يوجد تحديث).',
                 'input_schema' => ['type' => 'object', 'properties' => ['customer' => $shop]]],
                ['name' => 'erp_plans', 'description' => 'MADIX ERP: الباقات وأسعارها (شهري، سنوي، مدى الحياة) وميزات كل باقة ومدة التجربة المجانية. بلا مدخلات.', 'input_schema' => self::NONE],
                ['name' => 'erp_releases', 'description' => 'MADIX ERP: آخر إصدار لكل نظام مع رابط التحميل وما الجديد فيه.',
                 'input_schema' => ['type' => 'object', 'properties' => ['platform' => ['type' => 'string', 'enum' => ['windows', 'android', 'mac', 'ios'], 'description' => 'نظام واحد، أو اتركه للكل']]]],
                ['name' => 'erp_service_status', 'description' => 'MADIX ERP: حالة خدمة MADIX Cloud (تعمل، بطء، عطل، صيانة) والإعلانات الحالية. بلا مدخلات.', 'input_schema' => self::NONE],
                ['name' => 'erp_open_ticket', 'description' => 'MADIX ERP: فتح تذكرة دعم فني لمشكلة في نظام الكاشير لم تُحل بالشرح، تصل فريق الدعم في MADIX Cloud مع مقتطف المحادثة. لصاحب المحل على واتساب فقط.',
                 'input_schema' => ['type' => 'object', 'properties' => [
                     'subject' => ['type' => 'string', 'description' => 'موضوع قصير'],
                     'message' => ['type' => 'string', 'description' => 'وصف المشكلة كما شرحها المرسل، مع الجهاز والإصدار إن عُرفا'],
                     'category' => ['type' => 'string', 'enum' => ['problem', 'question', 'billing', 'feature', 'other'], 'description' => 'نوع التذكرة: مشكلة، سؤال، دفع واشتراك، طلب ميزة، أخرى'],
                     'device' => ['type' => 'string', 'description' => 'رقم الجهاز المعني إن عُرف من erp_devices'],
                     'customer' => $shop,
                 ], 'required' => ['subject', 'message']]],
                ['name' => 'erp_ticket_status', 'description' => 'MADIX ERP: حالة تذكرة دعم فُتحت من هذه المحادثة وآخر رد عليها من فريق الدعم.',
                 'input_schema' => ['type' => 'object', 'properties' => [
                     'id' => ['type' => 'string', 'description' => 'رقم التذكرة إن ذكره المرسل؛ اتركه فارغاً لآخر تذكرة'],
                 ]]],
                ['name' => 'erp_renewal_request', 'description' => 'MADIX ERP: تسجيل طلب تجديد ترخيص لجهاز في محل المرسل؛ ينتظر موافقة المدير بعد الدفع، ولا يجدد شيئاً بنفسه. إن كان للمحل أكثر من جهاز فحدّد الجهاز (من erp_devices) بعد سؤال المرسل.',
                 'input_schema' => ['type' => 'object', 'properties' => [
                     'plan' => ['type' => 'string', 'enum' => ['month', 'year', 'life'], 'description' => 'المدة المطلوبة إن ذكرها المرسل'],
                     'device' => ['type' => 'string', 'description' => 'رقم الجهاز المطلوب تجديده'],
                     'note' => ['type' => 'string', 'description' => 'ملاحظة المرسل (طريقة الدفع، وقت التواصل…)'],
                     'customer' => $shop,
                 ]]],
            ]);
        }
        return $defs;
    }

    /** تنفيذ أداة لمحادثة */
    public static function run(string $name, array $input, int $chatId): mixed
    {
        $chat = BotAgent::find($chatId);
        if (!$chat) {
            return ['ok' => false, 'message' => 'المحادثة غير موجودة.'];
        }
        $net = (string) ($input['network'] ?? '');
        $out = match ($name) {
            'platform_plans' => self::platformPlans(),
            'network_info' => self::networkInfo($chat, $net),
            'card_balance' => self::cardBalance($chat, (string) ($input['code'] ?? ''), (string) ($input['password'] ?? ''), $net),
            'my_account' => self::myAccount($chat, $net),
            'my_networks' => self::myNetworks($chat),
            'network_numbers' => self::networkNumbers($chat, $net),
            'support_tickets' => self::supportTickets($chat),
            'open_support_ticket' => self::openSupportTicket($chat, $input),
            'handoff' => self::handoff($chat, (string) ($input['reason'] ?? ''), (string) ($input['summary'] ?? '')),
            'erp_my_account' => self::erpMyAccount($chat),
            'erp_licenses' => self::erpFor($chat, 'licenses', (string) ($input['customer'] ?? '')),
            'erp_devices' => self::erpFor($chat, 'devices', (string) ($input['customer'] ?? '')),
            'erp_plans' => self::erpPublic('plans'),
            'erp_releases' => self::erpPublic('releases', in_array($input['platform'] ?? '', ['windows', 'android', 'mac', 'ios'], true) ? ['platform' => $input['platform']] : []),
            'erp_service_status' => self::erpPublic('status'),
            'erp_open_ticket' => self::erpTicket($chat, $input),
            'erp_ticket_status' => self::erpTicketStatus($chat, (string) ($input['id'] ?? '')),
            'erp_renewal_request' => self::erpRenewal($chat, $input),
            default => ['ok' => false, 'message' => 'أداة غير معروفة.'],
        };
        $json = (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return mb_strlen($json) > self::MAX ? mb_substr($json, 0, self::MAX) . '…(مختصر)' : $json;
    }

    // ------------------------------------------------------------------
    // الشبكة المقصودة
    // ------------------------------------------------------------------

    private static function norm(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /** المطابق لما كتبه المرسل من قائمة شبكات: اسم الحساب، أو الاسم كاملاً، أو جزء منه يطابق شبكة واحدة */
    private static function pick(array $rows, string $wanted): ?array
    {
        $w = self::norm($wanted);
        if ($w === '') {
            return null;
        }
        foreach ($rows as $t) {
            if ($t['slug'] === $w || self::norm((string) $t['name']) === $w) {
                return $t;
            }
        }
        $hits = array_values(array_filter($rows, static fn ($t) => str_contains(self::norm((string) $t['name']), $w) || str_contains($t['slug'], $w)));
        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * الشبكة التي تعمل عليها الأداة، وتصبح الشبكة المعنية بالمحادثة.
     * owner: من شبكات صاحب الرقم فقط · subscriber: من شبكات اشتراكه فقط · any: الشبكة المعروفة من المحادثة أو التي يسمّيها المرسل
     * @return array{ok:bool, tenant?:array, message?:string}
     */
    private static function network(array $chat, string $wanted, string $scope): array
    {
        $id = BotAgent::identity($chat);
        $focus = (int) ($chat['tenant_id'] ?? 0);
        if ($scope !== 'any') {
            $allowed = $scope === 'owner' ? $id['owner'] : array_values(array_unique(array_map(static fn ($x) => (int) $x['t'], $id['subs'])));
            if (!$allowed) {
                return ['ok' => false, 'message' => $scope === 'owner'
                    ? 'هذا الرقم ليس رقم صاحب شبكة مسجّلاً في المنصة؛ يراسلنا صاحب الشبكة من رقمه المسجّل في حسابه.'
                    : 'هذا الرقم غير مسجّل لمشترك؛ يراسلنا المشترك من رقمه المسجّل لدى شبكته.'];
            }
            $rows = Platform::rows('SELECT * FROM tenants WHERE id IN (' . DB::in($allowed) . ') ORDER BY name', $allowed);
            $hit = self::pick($rows, $wanted);
            if (!$hit && count($rows) === 1) {
                $hit = $rows[0];
            }
            if (!$hit && trim($wanted) === '') {
                foreach ($rows as $t) {
                    if ((int) $t['id'] === $focus) {
                        $hit = $t;
                    }
                }
            }
            if (!$hit) {
                return ['ok' => false, 'choose_network' => true, 'networks' => array_map(static fn ($t) => ['name' => $t['name'], 'account' => $t['slug']], $rows),
                    'message' => 'للمرسل أكثر من شبكة؛ اسأله أيّها يقصد ثم أعد الطلب باسمها في network.'];
            }
            self::focus($chat, $hit, $scope === 'subscriber' ? $id['subs'] : []);
            return ['ok' => true, 'tenant' => $hit];
        }
        if (trim($wanted) === '' && $focus) {
            $t = Platform::row('SELECT * FROM tenants WHERE id = ?', [$focus]);
            if ($t) {
                return Tenant::usable($t) ? ['ok' => true, 'tenant' => $t] : ['ok' => false, 'message' => 'خدمة هذه الشبكة متوقفة مؤقتاً في المنصة.'];
            }
        }
        if (trim($wanted) === '') {
            return ['ok' => false, 'needs_network' => true, 'message' => 'اسأل المرسل عن اسم شبكته (كما يظهر على الكرت أو صفحة الدخول) ثم أعد الطلب في network.'];
        }
        $t = self::findNetwork($wanted);
        if (!$t) {
            return ['ok' => false, 'found' => false, 'message' => 'لا توجد شبكة بهذا الاسم في المنصة. اطلب من المرسل الاسم كما يظهر على الكرت أو في رابط صفحة الدخول.'];
        }
        self::focus($chat, $t, $id['subs']);
        return ['ok' => true, 'tenant' => $t];
    }

    /** شبكة عاملة باسم حسابها أو اسمها (تطابق واحد فقط، فلا تُكشف قائمة الشبكات) */
    private static function findNetwork(string $wanted): ?array
    {
        $w = self::norm($wanted);
        $usable = static fn (?array $t): ?array => $t && Tenant::usable($t) ? $t : null;
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $w) && ($t = $usable(Platform::tenantBySlug($w) ?? Platform::tenantByAlias($w)))) {
            return $t;
        }
        $rows = Platform::rows("SELECT * FROM tenants WHERE status IN ('trial', 'active') AND name LIKE ? LIMIT 20", ['%' . DB::like(trim($wanted)) . '%']);
        $rows = array_values(array_filter($rows, static fn ($t) => Tenant::usable($t)));
        foreach ($rows as $t) {
            if (self::norm((string) $t['name']) === $w) {
                return $t;
            }
        }
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** الشبكة المختارة تصبح الشبكة المعنية بالمحادثة (ومعها المشترك إن كان للرقم حساب واحد فيها) */
    private static function focus(array $chat, array $tenant, array $subs): void
    {
        $set = ['tenant_id' => (int) $tenant['id'], 'subscriber_id' => BotAgent::subscriberIn($subs, (int) $tenant['id'], (int) ($chat['subscriber_id'] ?? 0))];
        if ((int) $chat['tenant_id'] !== $set['tenant_id'] || (int) $chat['subscriber_id'] !== (int) $set['subscriber_id']) {
            Platform::update('platform_bot_chats', $set, 'id = ?', [$chat['id']]);
        }
    }

    /** أرقام مشتركي المرسل في شبكة */
    private static function subscriberIds(array $chat, int $tenantId): array
    {
        $subs = BotAgent::identity($chat)['subs'];
        return array_values(array_map(static fn ($x) => (int) $x['s'], array_filter($subs, static fn ($x) => (int) $x['t'] === $tenantId)));
    }

    private static function pmoney(float|int|string|null $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.') . ' ' . Platform::setting('platform.currency');
    }

    // ------------------------------------------------------------------
    // المنصة
    // ------------------------------------------------------------------

    private static function platformPlans(): array
    {
        $plans = Platform::rows('SELECT * FROM platform_plans WHERE is_active = 1 AND is_public = 1 ORDER BY sort, price');
        $limit = static fn ($v): string => $v === null || $v === '' ? 'غير محدود' : (string) (int) $v;
        return [
            'ok' => true,
            'trial_days' => (int) Platform::setting('platform.trial_days'),
            'signup' => BotAgent::publicUrl() . '/signup',
            'plans' => array_map(static fn ($p) => array_filter([
                'name' => $p['name'], 'about' => $p['tagline'] ?: null,
                'price' => self::pmoney($p['price']) . ' لكل ' . ((int) $p['months'] === 1 ? 'شهر' : (int) $p['months'] . ' أشهر'),
                'routers' => $limit($p['max_routers']), 'subscribers' => $limit($p['max_subscribers']), 'employees' => $limit($p['max_employees']),
                'ai_messages_per_month' => $p['ai_messages'] === null ? null : (int) $p['ai_messages'],
                'features' => trim((string) $p['features']) !== '' ? array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $p['features']) ?: []))) : null,
                'modules' => ($m = Platform::planModules($p)) === null ? 'كل الأقسام' : implode('، ', array_map(static fn ($k) => Platform::MODULES[$k][0], $m)),
            ], static fn ($v) => $v !== null), $plans),
        ];
    }

    private static function myNetworks(array $chat): array
    {
        $ids = BotAgent::identity($chat)['owner'];
        if (!$ids) {
            return ['ok' => false, 'message' => 'هذا الرقم ليس رقم صاحب شبكة مسجّلاً في المنصة.'];
        }
        $states = ['trial' => 'تجربة مجانية', 'active' => 'مشترك', 'suspended' => 'موقوف', 'expired' => 'منتهي', 'cancelled' => 'ملغى'];
        $phases = ['ok' => 'يعمل', 'grace' => 'انتهى وفي فترة سماح', 'readonly' => 'انتهى والنظام للقراءة فقط حتى التجديد', 'blocked' => 'متوقف حتى التجديد'];
        $base = BotAgent::publicUrl();
        return ['ok' => true, 'networks' => array_map(static function (array $t) use ($states, $phases, $base): array {
            $plan = Platform::plan(isset($t['plan_id']) ? (int) $t['plan_id'] : null);
            $end = $t['status'] === 'trial' ? $t['trial_ends_at'] : $t['paid_until'];
            $phase = Tenant::phase($t);
            return array_filter([
                'name' => $t['name'], 'account' => $t['slug'], 'link' => $base . '/' . $t['slug'] . '/',
                'subscription' => $states[$t['status']] ?? $t['status'], 'state' => $phases[$phase] ?? $phase,
                'reason' => $t['status_reason'] ?: null,
                'plan' => $plan ? $plan['name'] : null,
                'ends_at' => $end ? substr((string) $end, 0, 10) : null,
                'days_left' => Tenant::daysLeft($t),
                'phase_ends' => in_array($phase, ['grace', 'readonly'], true) && ($pe = Tenant::phaseEnds($t)) ? date('Y-m-d', $pe) : null,
                'renew' => $base . '/' . $t['slug'] . '/billing',
                'limits' => array_filter(['routers' => $t['max_routers'], 'subscribers' => $t['max_subscribers'], 'employees' => $t['max_employees']], static fn ($v) => $v !== null),
                'usage' => array_filter(['routers' => $t['stat_routers'], 'subscribers' => $t['stat_subscribers'], 'employees' => $t['stat_employees']], static fn ($v) => $v !== null),
            ], static fn ($v) => $v !== null && $v !== []);
        }, Platform::rows('SELECT * FROM tenants WHERE id IN (' . DB::in($ids) . ') ORDER BY name', $ids))];
    }

    private static function supportTickets(array $chat): array
    {
        $ids = BotAgent::identity($chat)['owner'];
        if (!$ids) {
            return ['ok' => false, 'message' => 'هذا الرقم ليس رقم صاحب شبكة مسجّلاً في المنصة.'];
        }
        $rows = Platform::rows(
            'SELECT k.*, t.name AS network FROM support_tickets k JOIN tenants t ON t.id = k.tenant_id WHERE k.tenant_id IN (' . DB::in($ids) . ') ORDER BY k.last_message_at DESC LIMIT 10',
            $ids
        );
        return ['ok' => true, 'tickets' => array_map(static function (array $k): array {
            $last = Platform::row('SELECT sender, body, created_at FROM support_messages WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$k['id']]);
            return [
                'id' => (int) $k['id'], 'network' => $k['network'], 'subject' => $k['subject'],
                'status' => Support::STATUS[$k['status']][0] ?? $k['status'], 'opened' => substr((string) $k['created_at'], 0, 16),
                'last' => $last ? ($last['sender'] === 'admin' ? 'فريق المنصة' : 'صاحب الشبكة') . ' (' . substr((string) $last['created_at'], 0, 16) . '): ' . mb_substr((string) $last['body'], 0, 400) : null,
            ];
        }, $rows)];
    }

    private static function openSupportTicket(array $chat, array $in): array
    {
        $n = self::network($chat, (string) ($in['network'] ?? ''), 'owner');
        if (!$n['ok']) {
            return $n;
        }
        $subject = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 160);
        $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 4500);
        if ($subject === '' || mb_strlen($message) < 5) {
            return ['ok' => false, 'message' => 'اكتب موضوع التذكرة وتفاصيل واضحة.'];
        }
        if (!Throttle::rate('pbot:ticket:' . $chat['id'], 5, 86400)) {
            return ['ok' => false, 'message' => 'فُتحت تذاكر كثيرة من هذه المحادثة اليوم؛ الفريق سيتابعها.'];
        }
        $t = $n['tenant'];
        $category = (string) ($in['category'] ?? 'general');
        try {
            $id = Support::open($t, $subject, isset(Support::CATEGORIES[$category]) ? $category : 'general',
                $message . "\n\n— فُتحت من محادثة " . (BotAgent::CHANNELS[$chat['channel']] ?? $chat['channel']) . ' مع المساعد الآلي (+' . BotAgent::identity($chat)['phone'] . ')',
                trim((string) $t['owner_name']) . ' (المساعد الآلي)');
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        BotAgent::store($chat, 'note', 'فُتحت تذكرة دعم رقم ' . $id . ' لشبكة ' . $t['name'] . ': ' . $subject);
        return ['ok' => true, 'ticket' => $id, 'network' => $t['name'], 'message' => 'فُتحت التذكرة، ويرد فريق المنصة عليها ويظهر الرد في صفحة الدعم في النظام.', 'link' => BotAgent::publicUrl() . '/' . $t['slug'] . '/support/' . $id];
    }

    // ------------------------------------------------------------------
    // الشبكات ومشتركوها
    // ------------------------------------------------------------------

    private static function networkNumbers(array $chat, string $wanted): array
    {
        $n = self::network($chat, $wanted, 'owner');
        if (!$n['ok']) {
            return $n;
        }
        $t = $n['tenant'];
        if (!Tenant::usable($t)) {
            return ['ok' => false, 'network' => $t['name'], 'message' => 'نظام هذه الشبكة متوقف حتى تجديد الاشتراك، فلا تُقرأ أرقامه الآن.'];
        }
        return BotAgent::inTenant($t, static function () use ($t): array {
            $today = date('Y-m-d 00:00:00');
            $soon = date('Y-m-d H:i:s', time() + max(1, Settings::int('subscribers.expiry_alert_days', 3)) * 86400);
            $subs = DB::row(
                "SELECT COUNT(*) AS total, SUM(status = 'active' AND expires_at > ?) AS active, SUM(expires_at IS NOT NULL AND expires_at <= ?) AS expired,
                        SUM(status = 'active' AND expires_at BETWEEN ? AND ?) AS expiring FROM subscribers",
                [now(), now(), now(), $soon]
            ) ?? [];
            $expiring = DB::rows(
                "SELECT s.full_name, s.phone, s.expires_at, p.name AS plan FROM subscribers s JOIN plans p ON p.id = s.plan_id
                 WHERE s.status = 'active' AND s.expires_at BETWEEN ? AND ? ORDER BY s.expires_at LIMIT 10",
                [now(), $soon]
            );
            $sales = DB::row("SELECT COALESCE(SUM(total), 0) AS total, COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(paid), 0) AS paid FROM sales WHERE status = 'completed' AND created_at >= ?", [$today]) ?? [];
            $subPay = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM subscriber_transactions WHERE type = 'payment' AND created_at >= ?", [$today]);
            $distPay = (float) DB::value('SELECT COALESCE(SUM(amount), 0) FROM distributor_payments WHERE created_at >= ?', [$today]);
            $cards = DB::pairs('SELECT status, COUNT(*) FROM cards WHERE is_disabled = 0 GROUP BY status');
            $routers = DB::row("SELECT COUNT(*) AS total, SUM(status = 'online') AS online FROM routers WHERE is_active = 1") ?? [];
            $down = DB::rows("SELECT name, last_seen_at FROM routers WHERE is_active = 1 AND status = 'offline' ORDER BY last_seen_at DESC LIMIT 10");
            $outages = DB::rows('SELECT o.started_at, o.cause, a.name AS area FROM outages o LEFT JOIN areas a ON a.id = o.area_id WHERE o.ended_at IS NULL ORDER BY o.started_at DESC LIMIT 5');
            return [
                'ok' => true, 'network' => $t['name'], 'at' => date('Y-m-d H:i'),
                'subscribers' => [
                    'total' => (int) ($subs['total'] ?? 0), 'active' => (int) ($subs['active'] ?? 0), 'expired' => (int) ($subs['expired'] ?? 0),
                    'expiring_soon' => (int) ($subs['expiring'] ?? 0),
                    'expiring_list' => array_map(static fn ($s) => $s['full_name'] . ' · ' . $s['plan'] . ' · ينتهي ' . fmt_dt((string) $s['expires_at']), $expiring),
                ],
                'today' => [
                    'card_sales' => money($sales['total'] ?? 0), 'cards_sold' => (int) ($sales['qty'] ?? 0),
                    'collected' => money((float) ($sales['paid'] ?? 0) + $subPay + $distPay),
                    'cards_activated' => (int) DB::value('SELECT COUNT(*) FROM cards WHERE first_login_at >= ?', [$today]),
                ],
                'cards' => ['available' => (int) ($cards['new'] ?? 0), 'sold_not_used' => (int) ($cards['sold'] ?? 0), 'active' => (int) ($cards['active'] ?? 0)],
                'online_now' => (int) DB::value('SELECT COUNT(*) FROM sessions WHERE stopped_at IS NULL'),
                'mikrotik' => [
                    'total' => (int) ($routers['total'] ?? 0), 'online' => (int) ($routers['online'] ?? 0),
                    'offline' => array_map(static fn ($r) => $r['name'] . ($r['last_seen_at'] ? ' · آخر ظهور ' . fmt_dt((string) $r['last_seen_at']) : ''), $down),
                ],
                'outages_now' => array_map(static fn ($o) => ($o['area'] ?: 'منطقة غير محددة') . ' منذ ' . fmt_dt((string) $o['started_at']), $outages),
                'unread_alerts' => (int) DB::value('SELECT COUNT(*) FROM alerts WHERE is_read = 0'),
            ];
        });
    }

    private static function networkInfo(array $chat, string $wanted): array
    {
        $n = self::network($chat, $wanted, 'any');
        if (!$n['ok']) {
            return $n;
        }
        $t = $n['tenant'];
        $mine = self::subscriberIds($chat, (int) $t['id']);
        return BotAgent::inTenant($t, static function () use ($t, $mine): array {
            $contact = [];
            foreach (['general.phone' => 'phone', 'general.whatsapp' => 'whatsapp', 'general.address' => 'address'] as $k => $label) {
                $v = trim((string) Settings::get($k, ''));
                if ($v !== '') {
                    $contact[$label] = $v;
                }
            }
            $cards = DB::rows('SELECT * FROM categories WHERE is_active = 1 AND show_on_hotspot = 1 AND is_custom = 0 ORDER BY sort, price LIMIT 30');
            $plans = DB::rows('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort, price LIMIT 30');
            $causes = ['unknown' => 'غير معروف بعد', 'power' => 'انقطاع كهرباء', 'fault' => 'عطل', 'damage' => 'أضرار', 'maintenance' => 'صيانة'];
            $outages = DB::rows('SELECT o.*, a.name AS area FROM outages o LEFT JOIN areas a ON a.id = o.area_id WHERE o.ended_at IS NULL ORDER BY o.started_at DESC LIMIT 10');
            $own = $mine ? DB::rows('SELECT router_id, area_id FROM subscribers WHERE id IN (' . DB::in($mine) . ')', $mine) : [];
            $affected = false;
            foreach ($outages as $o) {
                foreach ($own as $s) {
                    if (($o['router_id'] && (int) $o['router_id'] === (int) $s['router_id']) || ($o['area_id'] && (int) $o['area_id'] === (int) $s['area_id'])) {
                        $affected = true;
                    }
                }
            }
            $points = DB::rows('SELECT d.name, d.address, a.name AS area FROM distributors d LEFT JOIN areas a ON a.id = d.area_id WHERE d.is_active = 1 ORDER BY a.name, d.name LIMIT 25');
            return [
                'ok' => true, 'network' => Settings::networkName(), 'contact' => $contact,
                'card_prices' => array_map(static fn ($c) => [
                    'name' => $c['name'], 'price' => money($c['price']),
                    'time' => Units::describeSeconds($c['time_limit'] !== null ? (int) $c['time_limit'] : null),
                    'validity' => Units::describeSeconds($c['validity'] !== null ? (int) $c['validity'] : null),
                    'data' => Units::describeBytes($c['data_limit'] !== null ? (int) $c['data_limit'] : null),
                    'speed' => Units::describeRate($c),
                ], $cards),
                'subscription_plans' => array_map(static fn ($p) => [
                    'name' => $p['name'], 'price' => money($p['price']), 'days' => (int) $p['duration_days'],
                    'speed' => Units::describeRate($p),
                    'data' => Units::describeBytes($p['data_limit'] !== null ? (int) $p['data_limit'] : null),
                ], $plans),
                'outages_now' => array_map(static fn ($o) => array_filter([
                    'area' => $o['area'] ?: 'غير محددة', 'cause' => $causes[$o['cause']] ?? $o['cause'],
                    'since' => fmt_dt((string) $o['started_at']), 'note' => $o['source'] === 'manual' ? $o['title'] : null,
                ]), $outages),
                'sender_affected' => $own ? $affected : null,
                'sale_points' => array_map(static fn ($d) => array_filter(['name' => $d['name'], 'area' => $d['area'], 'address' => $d['address']]), $points),
            ];
        });
    }

    private static function cardBalance(array $chat, string $code, string $password, string $wanted): array
    {
        $key = 'pbotcard:' . $chat['id'];
        if (($wait = Throttle::lockedFor($key)) > 0) {
            return ['ok' => false, 'message' => 'محاولات كثيرة لفحص الكروت من هذه المحادثة. أعد المحاولة بعد ' . max(1, (int) ceil($wait / 60)) . ' دقيقة.'];
        }
        $code = PortalService::normalizeCode(trim(latin_digits($code)));
        if ($code === '' || mb_strlen($code) > 64) {
            return ['ok' => false, 'message' => 'اكتب رقم الكرت كما هو مطبوع.'];
        }
        $n = self::network($chat, $wanted, 'any');
        if (!$n['ok']) {
            return $n;
        }
        $password = trim(latin_digits($password));
        $r = BotAgent::inTenant($n['tenant'], static function () use ($code, $password): array {
            $owner = LoginRegistry::owner($code);
            if ($owner && $owner[0] === 'subscriber') {
                return ['ok' => false, 'message' => 'هذا اسم مستخدم اشتراك وليس كرتاً؛ حالة الاشتراك تُعرف لصاحبه فقط عبر my_account من رقمه المسجّل.'];
            }
            $card = $owner && in_array($owner[0], ['card', 'card_short'], true) ? DB::row('SELECT * FROM cards WHERE id = ?', [$owner[1]]) : null;
            if (!$card) {
                return ['ok' => false, 'found' => false, 'fail' => true, 'message' => 'لا يوجد كرت بهذا الرقم في هذه الشبكة. تأكد من الرقم واسم الشبكة.'];
            }
            if ($owner[0] === 'card_short' && (string) $card['short_password'] !== '') {
                if ($password === '') {
                    return ['ok' => false, 'found' => true, 'needs_password' => true, 'message' => 'هذا رقم مختصر ويحتاج كلمة مرور الكرت المطبوعة معه.'];
                }
                if (!hash_equals((string) $card['short_password'], $password) && !hash_equals((string) $card['password'], $password)) {
                    return ['ok' => false, 'found' => true, 'fail' => true, 'message' => 'كلمة مرور الكرت غير صحيحة.'];
                }
            }
            $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
            if (!$cat) {
                return ['ok' => false, 'message' => 'تعذّر قراءة باقة الكرت.'];
            }
            $info = PortalService::cardInfo($card, $cat);
            $states = ['new' => 'جديد لم يُستخدم', 'sold' => 'جديد لم يُستخدم', 'active' => 'فعّال', 'expired' => 'منتهي الصلاحية', 'depleted' => 'نفد الرصيد'];
            $state = CardRules::lifecycleStatus($card, $cat);
            return [
                'ok' => true, 'found' => true, 'network' => Settings::networkName(), 'card' => $card['username'], 'package' => $info['category'],
                'state' => $states[$state] ?? $state, 'stopped_by_network' => (bool) (int) $card['is_disabled'],
                'remaining_time' => $info['remaining_time'] !== null ? fmt_duration((int) $info['remaining_time']) : 'غير محدود',
                'remaining_data' => $info['remaining_data'] !== null ? fmt_bytes((int) $info['remaining_data']) : 'غير محدودة',
                'expires_at' => $info['expires_at'] ? fmt_dt((string) $info['expires_at']) : null,
                'used' => fmt_duration((int) $info['used_time']) . '، ' . fmt_bytes((int) $info['used_data']),
                'speed' => $info['speed'],
            ];
        });
        // محاولات التخمين تُحسب في المنصة (لا في كل شبكة وحدها)
        if (!empty($r['fail'])) {
            Throttle::fail($key, 8, 900, 1800);
            unset($r['fail']);
        }
        return $r;
    }

    private static function myAccount(array $chat, string $wanted): array
    {
        $n = self::network($chat, $wanted, 'subscriber');
        if (!$n['ok']) {
            return $n;
        }
        $t = $n['tenant'];
        $ids = self::subscriberIds($chat, (int) $t['id']);
        if (!$ids) {
            return ['ok' => false, 'linked' => false, 'message' => 'رقم واتساب هذا غير مسجّل لأي مشترك في هذه الشبكة.'];
        }
        return BotAgent::inTenant($t, static function () use ($ids): array {
            $states = ['active' => 'فعّال', 'expiring' => 'ينتهي قريباً', 'expired' => 'منتهي', 'suspended' => 'موقوف', 'new' => 'بانتظار التفعيل'];
            $rows = DB::rows('SELECT s.*, p.name AS plan, p.data_limit FROM subscribers s JOIN plans p ON p.id = s.plan_id WHERE s.id IN (' . DB::in($ids) . ') ORDER BY s.full_name', $ids);
            return ['ok' => true, 'network' => Settings::networkName(), 'accounts' => array_map(static function (array $s) use ($states): array {
                $left = $s['expires_at'] ? (int) strtotime((string) $s['expires_at']) - time() : null;
                return [
                    'name' => $s['full_name'], 'username' => $s['username'],
                    'state' => $states[SubscriberRules::displayState($s)] ?? '',
                    'package' => $s['plan'],
                    'expires_at' => $s['expires_at'] ? fmt_dt((string) $s['expires_at']) : null,
                    'time_left' => $left !== null && $left > 0 ? fmt_duration($left) : null,
                    'usage' => $s['data_limit'] ? fmt_bytes((int) $s['period_bytes']) . ' من ' . fmt_bytes((int) $s['data_limit']) : null,
                    'amount_due' => (float) $s['balance'] > 0 ? money($s['balance']) : null,
                ];
            }, $rows)];
        });
    }

    private static function handoff(array $chat, string $reason, string $summary): array
    {
        BotAgent::handoff($chat, 'حوّلها المساعد: ' . mb_substr(trim($reason), 0, 300), mb_substr(trim($summary), 0, 700));
        $chat = BotAgent::find((int) $chat['id']) ?? $chat;
        if ($chat['handler'] === 'network') {
            $name = (string) Platform::value('SELECT name FROM tenants WHERE id = ?', [$chat['tenant_id']]);
            return ['ok' => true, 'to' => 'network', 'message' => 'حُوّلت المحادثة لصاحب شبكة ' . $name . '، وسيرد على المشترك هنا.'];
        }
        return ['ok' => true, 'to' => 'platform', 'message' => 'حُوّلت المحادثة لفريق المنصة، وسيرد على المرسل هنا.'];
    }

    // ------------------------------------------------------------------
    // MADIX ERP (لوحة MADIX Cloud)
    // ------------------------------------------------------------------

    private static function erpOff(): array
    {
        return ['ok' => false, 'message' => 'الربط مع MADIX Cloud غير مفعّل.'];
    }

    /** نتيجة طلب فاشل للنموذج: الرسالة ورمز الخطأ */
    private static function erpFail(array $r): array
    {
        return array_filter(['ok' => false, 'code' => $r['code'] ?? null, 'message' => $r['message']], static fn ($v) => $v !== null);
    }

    /**
     * محلات صاحب الرقم الموثّق في MADIX Cloud (تُحفظ في المحادثة ساعة).
     * @return array{ok:bool, shops?:list<array{customer:string, shop:string}>, data?:mixed, message?:string}
     */
    private static function erpShops(array $chat, bool $fresh = false): array
    {
        if (!MadixCloud::enabled()) {
            return self::erpOff();
        }
        $phone = BotAgent::verifiedPhone($chat);
        if ($phone === '') {
            return ['ok' => false, 'verified' => false, 'message' => 'بيانات المحل تُعطى فقط على واتساب من رقم صاحب المحل المسجّل في MADIX ERP.'];
        }
        $meta = BotAgent::meta($chat);
        if (!$fresh && !empty($meta['erp']['shops']) && ($meta['erp']['phone'] ?? '') === $phone && (int) ($meta['erp']['at'] ?? 0) > time() - 3600) {
            return ['ok' => true, 'shops' => $meta['erp']['shops']];
        }
        $r = MadixCloud::call('lookup', ['phone' => '+' . $phone]);
        if (!$r['ok']) {
            return $r['status'] === 404 ? ['ok' => false, 'found' => false, 'message' => 'هذا الرقم غير مسجّل لأي محل في MADIX ERP.'] : self::erpFail($r);
        }
        $shops = MadixCloud::shops($r['data']);
        if (!$shops) {
            return ['ok' => false, 'found' => false, 'message' => 'هذا الرقم غير مسجّل لأي محل في MADIX ERP.'];
        }
        $meta['erp'] = ['shops' => $shops, 'phone' => $phone, 'at' => time()];
        BotAgent::setMeta((int) $chat['id'], $meta);
        return ['ok' => true, 'shops' => $shops, 'data' => $r['data']];
    }

    /** المحل المقصود: ما حدّده المرسل من محلاته، أو محله الوحيد */
    private static function erpCustomer(array $chat, string $wanted): array
    {
        $acc = self::erpShops($chat);
        if (!$acc['ok']) {
            return $acc;
        }
        $ids = array_column($acc['shops'], 'customer');
        $wanted = strtoupper(trim($wanted));
        if ($wanted !== '' && in_array($wanted, $ids, true)) {
            return ['ok' => true, 'id' => $wanted];
        }
        if (count($ids) === 1) {
            return ['ok' => true, 'id' => $ids[0]];
        }
        return ['ok' => false, 'choose_shop' => true, 'shops' => $acc['shops'], 'message' => 'لهذا الرقم أكثر من محل؛ اسأل المرسل أي محل يقصد ثم أعد الطلب برقم المحل في customer.'];
    }

    private static function erpMyAccount(array $chat): array
    {
        $acc = self::erpShops($chat, true);
        return $acc['ok'] ? ['ok' => true, 'data' => $acc['data']] : $acc;
    }

    private static function erpFor(array $chat, string $route, string $wanted): array
    {
        $c = self::erpCustomer($chat, $wanted);
        if (!$c['ok']) {
            return $c;
        }
        $r = MadixCloud::call($route, ['customer' => $c['id'], 'limit' => 20]);
        return $r['ok'] ? ['ok' => true, 'customer' => $c['id'], 'data' => $r['data']] : self::erpFail($r);
    }

    private static function erpPublic(string $route, array $query = []): array
    {
        if (!MadixCloud::enabled()) {
            return self::erpOff();
        }
        $r = MadixCloud::call($route, $query);
        return $r['ok'] ? ['ok' => true, 'data' => $r['data']] : self::erpFail($r);
    }

    /** آخر رسائل المحادثة نصاً، لتُرفق بالتذكرة */
    private static function excerpt(int $chatId): string
    {
        $rows = array_reverse(Platform::rows("SELECT role, body FROM platform_bot_messages WHERE chat_id = ? AND role IN ('user','bot','staff') ORDER BY id DESC LIMIT 12", [$chatId]));
        $who = ['user' => 'الزبون', 'bot' => 'المساعد', 'staff' => 'الفريق'];
        return mb_substr(implode("\n", array_map(static fn ($r) => $who[$r['role']] . ': ' . $r['body'], $rows)), 0, 8000);
    }

    private static function erpTicket(array $chat, array $in): array
    {
        $c = self::erpCustomer($chat, (string) ($in['customer'] ?? ''));
        if (!$c['ok']) {
            return $c;
        }
        $subject = mb_substr(trim((string) ($in['subject'] ?? '')), 0, 200);
        $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 5000);
        if ($subject === '' || mb_strlen($message) < 5) {
            return ['ok' => false, 'message' => 'اكتب موضوع التذكرة ووصفاً واضحاً للمشكلة.'];
        }
        if (!Throttle::rate('pbot:erpticket:' . $chat['id'], 5, 86400)) {
            return ['ok' => false, 'message' => 'فُتحت تذاكر كثيرة من هذه المحادثة اليوم؛ الفريق سيتابعها.'];
        }
        $category = (string) ($in['category'] ?? '');
        $device = trim((string) ($in['device'] ?? ''));
        $r = MadixCloud::call('ticket', [], array_filter([
            'customer' => $c['id'], 'subject' => $subject, 'message' => $message, 'channel' => 'whatsapp',
            'transcript' => self::excerpt((int) $chat['id']),
            'contact_name' => $chat['name'] ?: null, 'contact' => '+' . BotAgent::verifiedPhone($chat),
            'category' => in_array($category, ['problem', 'question', 'billing', 'feature', 'other'], true) ? $category : null,
            'device' => $device !== '' ? mb_substr($device, 0, 40) : null,
        ], static fn ($v) => $v !== null));
        if (!$r['ok']) {
            return self::erpFail($r);
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        $id = isset($d['id']) && is_scalar($d['id']) ? (string) $d['id'] : '';
        if ($id !== '') {
            $meta = BotAgent::meta(BotAgent::find((int) $chat['id']) ?? $chat);
            $mine = array_values(array_filter($meta['tickets'] ?? [], static fn ($t) => is_array($t) && ($t['id'] ?? '') !== $id));
            $mine[] = ['id' => $id, 'customer' => $c['id']];
            $meta['tickets'] = array_slice($mine, -10);
            BotAgent::setMeta((int) $chat['id'], $meta);
            BotAgent::store($chat, 'note', 'فُتحت تذكرة دعم في MADIX Cloud رقم ' . $id . ' للمحل ' . $c['id'] . ': ' . $subject);
        }
        return ['ok' => true, 'ticket' => $id !== '' ? $id : null, 'data' => $r['data']];
    }

    private static function erpTicketStatus(array $chat, string $id): array
    {
        if (!MadixCloud::enabled()) {
            return self::erpOff();
        }
        $mine = array_values(array_filter(BotAgent::meta($chat)['tickets'] ?? [], 'is_array'));
        if (!$mine) {
            return ['ok' => false, 'message' => 'لم تُفتح أي تذكرة من هذه المحادثة.'];
        }
        // تذاكر هذه المحادثة فقط، وتُطلب دائماً مع رقم المحل فلا تُكشف تذكرة زبون لآخر
        $id = trim($id, " #\t");
        $pick = end($mine);
        foreach ($mine as $t) {
            if ((string) ($t['id'] ?? '') === $id) {
                $pick = $t;
            }
        }
        $r = MadixCloud::call('ticket', ['id' => (string) $pick['id'], 'customer' => (string) ($pick['customer'] ?? '')]);
        return $r['ok'] ? ['ok' => true, 'ticket' => (string) $pick['id'], 'data' => $r['data']] : self::erpFail($r);
    }

    private static function erpRenewal(array $chat, array $in): array
    {
        $c = self::erpCustomer($chat, (string) ($in['customer'] ?? ''));
        if (!$c['ok']) {
            return $c;
        }
        if (!Throttle::rate('pbot:renew:' . $chat['id'], 4, 86400)) {
            return ['ok' => false, 'message' => 'طلبات التجديد مسجّلة اليوم، والفريق سيتواصل مع المرسل.'];
        }
        $plan = (string) ($in['plan'] ?? '');
        $device = trim((string) ($in['device'] ?? ''));
        $r = MadixCloud::call('renewal_request', [], array_filter([
            'customer' => $c['id'], 'device' => $device !== '' ? mb_substr($device, 0, 40) : null,
            'plan' => in_array($plan, ['month', 'year', 'life'], true) ? $plan : null,
            'channel' => 'whatsapp', 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000) ?: null,
        ], static fn ($v) => $v !== null));
        if (!$r['ok']) {
            $fail = self::erpFail($r);
            if (($r['code'] ?? '') === 'device_required') {
                $fail['next'] = 'اعرض على المرسل أجهزته من erp_devices واسأله أيّها، ثم أعد الطلب مع device.';
            }
            return $fail;
        }
        $d = is_array($r['data']) ? $r['data'] : [];
        if (empty($d['already_pending'])) {
            BotAgent::store($chat, 'note', 'سُجّل طلب تجديد ترخيص MADIX ERP للمحل ' . $c['id'] . ($plan !== '' ? ' (' . $plan . ')' : '') . '.');
        }
        return ['ok' => true, 'message' => !empty($d['already_pending']) ? 'يوجد طلب تجديد سابق بانتظار الموافقة لهذا الجهاز.' : 'سُجّل طلب التجديد، ويؤكده الفريق بعد الدفع.', 'data' => $r['data']];
    }
}
