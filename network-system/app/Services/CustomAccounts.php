<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Settings;
use PDOException;
use RuntimeException;

/**
 * الحسابات الخاصة: حساب هوتسبوت باسم مستخدم (وكلمة مرور اختيارية) يحدده صاحب الشبكة، بسرعة ومدة وصلاحية خاصة به.
 *
 * كل حساب كرت عادي في باقة ودفعة مخفيتين خاصتين به (is_custom = 1 و source = custom)،
 * فيعمل بكل ما تعمله الكروت دون أي تغيير عليها: التجهيز على أجهزة MikroTik وملف السرعة،
 * والدخول من صفحة الهوتسبوت، والاستهلاك والانتهاء، والمتابعة والفحص والطباعة.
 * بلا كلمة مرور: كلمة المرور هي اسم المستخدم نفسه، فيدخل بالاسم وحده (كما تعمل الكروت ذات الرمز الواحد).
 */
final class CustomAccounts
{
    public const NAME_PREFIX = 'حساب خاص: ';
    public const COLOR = '#7c3aed';

    /** حقول ملف السرعة على جهاز MikroTik */
    public const PROFILE_KEYS = ['rate_up', 'rate_down', 'burst_rate_up', 'burst_rate_down', 'burst_threshold_up', 'burst_threshold_down', 'burst_time', 'shared_users', 'idle_timeout'];
    /** حدود الحساب (تُطبَّق على مستخدم الهوتسبوت نفسه) */
    public const LIMIT_KEYS = ['time_limit', 'validity', 'data_limit', 'lock_mac'];

    public const USERNAME_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';

    /** الحساب بكرته وباقته ودفعته، أو null إن لم يكن حساباً خاصاً */
    public static function find(int $cardId): ?array
    {
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
        if (!$card) {
            return null;
        }
        $cat = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 1', [(int) $card['category_id']]);
        if (!$cat) {
            return null;
        }
        $batch = DB::row('SELECT * FROM batches WHERE id = ?', [(int) $card['batch_id']]) ?? ['name' => '', 'id' => (int) $card['batch_id']];
        $routers = array_map('intval', DB::column('SELECT router_id FROM category_routers WHERE category_id = ? ORDER BY router_id', [(int) $cat['id']]));
        return ['card' => $card, 'category' => $cat, 'batch' => $batch, 'routers' => $routers];
    }

    /** معرّف كرت الحساب الخاص لباقته المخفية (للتحويل من صفحات الباقات والدفعات) */
    public static function cardForCategory(int $categoryId): ?int
    {
        $id = DB::value('SELECT k.id FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.id = ? AND c.is_custom = 1 ORDER BY k.id LIMIT 1', [$categoryId]);
        return $id !== null ? (int) $id : null;
    }

    public static function cardForBatch(int $batchId): ?int
    {
        $id = DB::value("SELECT k.id FROM cards k JOIN batches b ON b.id = k.batch_id WHERE b.id = ? AND b.source = 'custom' ORDER BY k.id LIMIT 1", [$batchId]);
        return $id !== null ? (int) $id : null;
    }

    /** اسم المستخدم كما يُكتب في صفحة الهوتسبوت: أرقام لاتينية، وحالة الأحرف حسب إعداد الهوتسبوت */
    public static function normalizeUsername(string $username): string
    {
        return PortalService::normalizeCode($username);
    }

    public static function usernameError(string $username): ?string
    {
        if ($username === '') {
            return 'أدخل اسم المستخدم.';
        }
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return 'اسم المستخدم بالأحرف الإنجليزية والأرقام والرموز . _ @ - فقط، دون مسافات، وحتى 64 حرفاً.';
        }
        return null;
    }

    public static function normalizePassword(string $password): string
    {
        return trim(latin_digits($password));
    }

    /** كلمة المرور: فارغة (الدخول بالاسم فقط) أو أحرف وأرقام ورموز إنجليزية دون مسافات أو علامات اقتباس */
    public static function passwordError(string $password): ?string
    {
        if ($password === '') {
            return null;
        }
        if (!preg_match('/^[\x21-\x7E]{1,64}$/', $password) || preg_match('/["\'\\\\`]/', $password)) {
            return 'كلمة المرور بالأحرف الإنجليزية والأرقام والرموز، دون مسافات أو علامات اقتباس، وحتى 64 حرفاً.';
        }
        return null;
    }

    /** هل الحساب يدخل باسم المستخدم فقط */
    public static function usernameOnly(array $card): bool
    {
        return hash_equals((string) $card['username'], (string) $card['password']);
    }

    /** هل الحساب بلا أي حد (سرعة ووقت وصلاحية وبيانات وأجهزة) */
    public static function isUnlimited(array $cat): bool
    {
        foreach (['time_limit', 'validity', 'data_limit', 'rate_up', 'rate_down', 'idle_timeout'] as $k) {
            if ($cat[$k] !== null && $cat[$k] !== '') {
                return false;
            }
        }
        return (int) $cat['shared_users'] === 0;
    }

    /** اسم الباقة المخفية: فريد، يبدأ بـ «حساب خاص:» */
    private static function categoryName(string $username, int $exceptId = 0): string
    {
        $base = mb_substr(self::NAME_PREFIX . $username, 0, 90);
        $name = $base;
        for ($i = 2; DB::value('SELECT id FROM categories WHERE name = ? AND id <> ?', [$name, $exceptId]); $i++) {
            $name = $base . ' (' . $i . ')';
        }
        return $name;
    }

    /** بيانات الباقة المخفية من مدخلات النموذج المنقّحة */
    private static function categoryData(array $d): array
    {
        return [
            'time_limit'           => $d['time_limit'],
            'validity'             => $d['validity'],
            'data_limit'           => $d['data_limit'],
            'rate_up'              => $d['rate_up'],
            'rate_down'            => $d['rate_down'],
            'burst_rate_up'        => $d['burst_rate_up'],
            'burst_rate_down'      => $d['burst_rate_down'],
            'burst_threshold_up'   => $d['burst_threshold_up'],
            'burst_threshold_down' => $d['burst_threshold_down'],
            'burst_time'           => $d['burst_time'],
            'shared_users'         => (int) $d['shared_users'],
            'lock_mac'             => (int) $d['lock_mac'],
            'idle_timeout'         => $d['idle_timeout'],
        ];
    }

    private static function syncRouters(int $categoryId, array $routerIds): void
    {
        DB::delete('category_routers', 'category_id = ?', [$categoryId]);
        if ($routerIds) {
            DB::insertMany('category_routers', array_map(static fn ($r) => ['category_id' => $categoryId, 'router_id' => (int) $r], $routerIds));
        }
    }

    private static function batchName(array $d): string
    {
        $holder = trim((string) ($d['holder'] ?? ''));
        return mb_substr($holder !== '' ? $holder : self::NAME_PREFIX . $d['username'], 0, 120);
    }

    /**
     * إنشاء حساب خاص.
     * @param array $d username, password ('' = الدخول بالاسم فقط), holder, notes, enabled, router_ids
     *                 وحدود الباقة: time_limit, validity, data_limit, rate_*, burst_*, shared_users, lock_mac, idle_timeout
     * @return int رقم الكرت
     */
    public static function create(array $d, ?int $employeeId): int
    {
        $username = (string) $d['username'];
        if ($err = self::usernameError($username)) {
            throw new RuntimeException($err);
        }
        $password = (string) $d['password'];
        if ($err = self::passwordError($password)) {
            throw new RuntimeException($err);
        }
        if (LoginRegistry::isTaken($username)) {
            throw new RuntimeException('اسم المستخدم مستخدم لكرت أو مشترك آخر. اختر اسماً غيره.');
        }
        try {
            $id = DB::transaction(static function () use ($d, $username, $password, $employeeId) {
                $now = now();
                $catId = DB::insert('categories', self::categoryData($d) + [
                    'name' => self::categoryName($username), 'price' => 0, 'color' => self::COLOR,
                    'show_on_hotspot' => 0, 'low_stock' => null, 'is_active' => 1, 'is_custom' => 1, 'sort' => 0,
                    'notes' => null, 'created_at' => $now,
                ]);
                self::syncRouters($catId, $d['router_ids']);
                $batchId = DB::insert('batches', [
                    'name' => self::batchName($d), 'category_id' => $catId, 'source' => 'custom', 'quantity' => 1,
                    'options' => json_encode(['account' => true], JSON_UNESCAPED_UNICODE),
                    'notes' => null, 'created_by' => $employeeId, 'created_at' => $now,
                ]);
                // الحساب مُسلَّم لصاحبه: «مباع» فيعمل حتى لو مُنعت الكروت غير المباعة، ولا يُحسب في المخزون ولا يُباع
                $cardId = DB::insert('cards', [
                    'batch_id' => $batchId, 'category_id' => $catId, 'username' => $username,
                    'password' => $password !== '' ? $password : $username,
                    'status' => 'sold', 'sold_at' => $now, 'is_disabled' => !empty($d['enabled']) ? 0 : 1,
                    'disabled_reason' => null, 'notes' => $d['notes'], 'created_at' => $now,
                ]);
                LoginRegistry::add($username, 'card', $cardId);
                return $cardId;
            });
        } catch (PDOException $e) {
            // إنشاء متزامن بنفس الاسم: المفتاح الفريد يمنع التكرار
            if (($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate')) {
                throw new RuntimeException('اسم المستخدم مستخدم لكرت أو مشترك آخر. اختر اسماً غيره.');
            }
            throw $e;
        }
        Audit::log('cards.account', 'إنشاء الحساب الخاص ' . $username, 'card', $id, [
            'username_only' => $password === '', 'routers' => $d['router_ids'],
        ]);
        return $id;
    }

    /**
     * تعديل حساب خاص وتطبيق التغيير على أجهزة MikroTik التي يوجد عليها.
     * اسم المستخدم ثابت (هو اسم المستخدم على أجهزة MikroTik وفي سجل الاستهلاك).
     * @return array{applied:int, disconnected:bool, removed:int, ended:bool}
     */
    public static function update(int $cardId, array $d): array
    {
        $acc = self::find($cardId);
        if (!$acc) {
            throw new RuntimeException('الحساب غير موجود.');
        }
        ['card' => $card, 'category' => $cat] = $acc;
        $password = (string) $d['password'];
        if ($err = self::passwordError($password)) {
            throw new RuntimeException($err);
        }
        $newPassword = $password !== '' ? $password : (string) $card['username'];
        $catData = self::categoryData($d);
        $profileChanged = $limitsChanged = false;
        foreach (self::PROFILE_KEYS as $k) {
            if ((string) $cat[$k] !== (string) $catData[$k]) {
                $profileChanged = true;
            }
        }
        foreach (self::LIMIT_KEYS as $k) {
            if ((string) $cat[$k] !== (string) $catData[$k]) {
                $limitsChanged = true;
            }
        }
        $disabled = !empty($d['enabled']) ? 0 : 1;
        $stateChanged = $disabled !== (int) $card['is_disabled'];
        $passwordChanged = !hash_equals((string) $card['password'], $newPassword);

        $cardData = ['password' => $newPassword, 'notes' => $d['notes'], 'updated_at' => now()];
        if ($stateChanged) {
            $cardData['is_disabled'] = $disabled;
            $cardData['disabled_reason'] = null;
        }
        // الصلاحية تُحسب من أول دخول: تغييرها لحساب بدأ استخدامه يعيد حساب موعد انتهائه
        if ((string) $cat['validity'] !== (string) $catData['validity'] && $card['first_login_at']) {
            $cardData['expires_at'] = $catData['validity'] !== null
                ? date('Y-m-d H:i:s', (int) strtotime((string) $card['first_login_at']) + (int) $catData['validity'] + (int) $card['bonus_validity'])
                : null;
        }
        // إلغاء ربط الحساب بأول جهاز: يُفك الجهاز المرتبط كي لا يبقى مقيداً به
        if ((int) $cat['lock_mac'] === 1 && (int) $catData['lock_mac'] === 0 && $card['bound_mac']) {
            $cardData['bound_mac'] = null;
        }

        DB::transaction(static function () use ($cat, $card, $catData, $cardData, $d) {
            DB::update('categories', $catData + ['name' => self::categoryName((string) $card['username'], (int) $cat['id']), 'updated_at' => now()], 'id = ?', [$cat['id']]);
            self::syncRouters((int) $cat['id'], $d['router_ids']);
            DB::update('cards', $cardData, 'id = ?', [$card['id']]);
            DB::update('batches', ['name' => self::batchName($d + ['username' => $card['username']])], 'id = ?', [$card['batch_id']]);
        });

        // الحالة بعد تغيّر الحدود: المنتهي يُنهى ويُحذف/يُوقف على الأجهزة، والممدَّد يعود صالحاً
        Enforcer::cards([$cardId]);

        $present = array_map('intval', DB::column("SELECT router_id FROM card_routers WHERE card_id = ? AND state = 'present'", [$cardId]));
        $allowed = $d['router_ids'];
        $keep = $allowed ? array_values(array_intersect($present, $allowed)) : $present;
        $drop = array_values(array_diff($present, $keep));
        $fresh = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
        $freshCat = DB::row('SELECT * FROM categories WHERE id = ?', [$cat['id']]);
        // انتهى بالحدود الجديدة: Enforcer أوقفه أو حذفه من الأجهزة، فلا يُعاد تجهيزه
        $ended = in_array(CardRules::blockReason($fresh, $freshCat), ['expired', 'time_depleted', 'data_depleted'], true);
        $target = 'card:' . $cardId;
        $applied = 0;
        $disconnect = ($profileChanged || $limitsChanged) && !$disabled;
        if (!$ended && ($profileChanged || $limitsChanged || $stateChanged || $passwordChanged)) {
            foreach ($keep as $rid) {
                // التجهيز يحدّث ملف السرعة (إن تغيّر) وكلمة المرور والحدود المتبقية والإيقاف، ويفصل الحساب الموقوف
                RouterTasks::dispatch($rid, 'card_provision', ['card_id' => $cardId], $target);
                if ($disconnect) {
                    // الجلسة الحالية تحمل الحدود القديمة: تُفصل فتعود بالجديدة (تلقائياً مع الدخول التلقائي)
                    RouterTasks::dispatch($rid, 'hs_disconnect', ['user' => $fresh['username']], $target);
                }
                $applied++;
            }
        }
        // أجهزة لم يعد مسموحاً له العمل عليها: يُحذف منها
        foreach ($drop as $rid) {
            RouterTasks::dispatch($rid, 'card_remove', ['card_id' => $cardId, 'username' => $fresh['username']], $target);
        }
        Audit::log('cards.account', 'تعديل الحساب الخاص ' . $card['username'], 'card', $cardId, [
            'speed' => $profileChanged, 'limits' => $limitsChanged, 'password' => $passwordChanged,
            'state' => $stateChanged ? ($disabled ? 'disabled' : 'enabled') : null, 'removed_from' => $drop,
        ]);
        return ['applied' => $applied, 'disconnected' => $disconnect && $applied > 0, 'removed' => count($drop), 'ended' => $ended];
    }

    /** إيقاف/تفعيل سريع من القائمة */
    public static function setEnabled(int $cardId, bool $enabled): array
    {
        $acc = self::find($cardId);
        if (!$acc) {
            throw new RuntimeException('الحساب غير موجود.');
        }
        $r = $enabled ? CardService::enable([$cardId]) : CardService::disable([$cardId]);
        Audit::log('cards.account', ($enabled ? 'تفعيل' : 'إيقاف') . ' الحساب الخاص ' . $acc['card']['username'], 'card', $cardId);
        return $r;
    }

    /**
     * حذف حساب خاص: من أجهزة MikroTik، ثم كرته وباقته ودفعته، وملف سرعته من الأجهزة.
     * @return int|null رقم العملية الطويلة إن أُنشئت
     */
    public static function delete(int $cardId): ?int
    {
        $acc = self::find($cardId);
        if (!$acc) {
            throw new RuntimeException('الحساب غير موجود.');
        }
        $r = CardService::delete([$cardId]);
        Audit::log('cards.account', 'حذف الحساب الخاص ' . $acc['card']['username'], 'card', $cardId);
        return $r['job'] ?? null;
    }

    /** الباقات المخفية لكروت ستُحذف: [category_id => card_id] (تُستدعى قبل الحذف) */
    public static function categoriesOf(array $cardIds): array
    {
        $out = [];
        foreach (array_chunk(array_map('intval', $cardIds), 1000) as $chunk) {
            foreach (DB::rows('SELECT k.category_id, k.id FROM cards k JOIN categories c ON c.id = k.category_id WHERE c.is_custom = 1 AND k.id IN (' . DB::in($chunk) . ')', $chunk) as $r) {
                $out[(int) $r['category_id']] = (int) $r['id'];
            }
        }
        return $out;
    }

    /**
     * تنظيف الباقات المخفية التي لم يبق فيها كرت (بعد حذف حساب من أي مكان: القائمة، حذف كروت، حذف دفعة).
     * ملف السرعة يُحذف من أجهزة MikroTik بمهمة مؤجلة بعد حذف مستخدم الهوتسبوت، ولا يُحذف إن كان ما زال مستخدماً.
     * @param array $pairs [category_id => card_id]
     */
    public static function purge(array $pairs): void
    {
        foreach ($pairs as $catId => $cardId) {
            $catId = (int) $catId;
            if (DB::value('SELECT 1 FROM cards WHERE category_id = ? LIMIT 1', [$catId])) {
                continue;
            }
            $routers = array_map('intval', DB::column("SELECT router_id FROM router_profiles WHERE kind = 'category' AND ref_id = ?", [$catId]));
            try {
                DB::transaction(static function () use ($catId) {
                    DB::delete('batches', "category_id = ? AND source = 'custom'", [$catId]);
                    DB::delete('router_profiles', "kind = 'category' AND ref_id = ?", [$catId]);
                    DB::delete('categories', 'id = ? AND is_custom = 1', [$catId]);
                });
            } catch (PDOException $e) {
                // مرتبطة بشيء آخر (لا يحدث عادة): تبقى الباقة المخفية دون ضرر
                log_message('cards', 'custom account category ' . $catId . ' kept: ' . $e->getMessage());
                continue;
            }
            foreach ($routers as $rid) {
                RouterTasks::queue($rid, 'profile_remove', ['name' => ProfileManager::categoryProfile($catId)], 'card:' . (int) $cardId);
            }
        }
    }

    /** نص مختصر لحدود الحساب */
    public static function describeLimits(array $cat): array
    {
        return [
            'speed'    => Units::describeRate($cat),
            'time'     => Units::describeSeconds($cat['time_limit'] !== null ? (int) $cat['time_limit'] : null),
            'validity' => $cat['validity'] !== null ? Units::describeSeconds((int) $cat['validity']) : 'غير محدودة',
            'data'     => Units::describeBytes($cat['data_limit'] !== null ? (int) $cat['data_limit'] : null),
            'devices'  => (int) $cat['shared_users'] <= 0 ? 'غير محدود' : num((int) $cat['shared_users']),
        ];
    }

    /** حالة الهوتسبوت: هل يمنع إعداد حالة الأحرف اسماً بأحرف مختلطة */
    public static function codeCase(): string
    {
        return (string) Settings::get('hotspot.code_case', 'none');
    }
}
