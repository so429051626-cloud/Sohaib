<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * منصة MADIX NEXUS متعددة الزبائن: الإعداد الجذري، قاعدة المنصة، الإعدادات والنشاط.
 *
 * config/config.php:
 *   'platform' => [
 *       'enabled'   => true,
 *       'tenant_db' => ['host' => 'localhost', 'port' => 3306, 'user' => '...', 'pass' => '...', 'prefix' => 'mnx_'],
 *   ]
 */
final class Platform
{
    /** مسارات المنصة المحجوزة (لا تصلح أسماءً للحسابات) */
    public const RESERVED = [
        'admin', 'signup', 'login', 'logout', 'go', 'cron', 'assets', 'uploads', 'hotspot', 'api', 'install', 'manifest.webmanifest',
        'favicon.ico', 'robots.txt', 'about', 'terms', 'privacy', 'help', 'support', 'status', 'www', 'mail', 'app', 'apps', 'download',
        'downloads', 'platform', 'madix', 'nexus', 'root', 'system', 'public', 'static', 'telegram', 'auth', 'account', 'blog', 'docs',
        'health', 'server', 'lang',
    ];

    public const DEFAULTS = [
        'platform.name'          => 'MADIX NEXUS',
        'platform.whatsapp'      => '0595679770',
        'platform.whatsapp_intl' => '970595679770',
        'platform.facebook'      => 'https://www.facebook.com/share/19Ln63g4xe/',
        'platform.trial_days'    => '14',
        'platform.signup'        => '1',
        'platform.timezone'      => 'Asia/Gaza',
        'platform.announcement'  => '',
        'platform.announcement_level' => 'info',
        'platform.currency'      => '₪',
        // مسار الاشتراك: تذكير ثم سماح ثم قراءة فقط ثم إيقاف
        'billing.remind_days'    => '7,3,1',
        'billing.grace_days'     => '3',
        'billing.readonly_days'  => '4',
        'billing.remind_sms'     => '1',
        'billing.invoice_prefix' => 'INV',
        'billing.invoice_note'   => '',
        // الذكاء الاصطناعي
        'ai.enabled'             => '0',
        'ai.model'               => 'claude-opus-5-5',
        // الحسابات الجديدة: المساعد الذكي مفعّل لها تلقائياً، والحد الشهري الافتراضي حين لا تحدده الباقة ولا الحساب
        'ai.new_enabled'         => '1',
        'ai.default_limit'       => '50',
        // رسائل المنصة التلقائية (تُشغَّل وتُوقف من لوحة المسؤول ← الرسائل النصية، وتعمل عند ضبط بوابة الرسائل)
        'signup.verify_phone'    => '1',
        'sms.welcome'            => '1',
        'sms.password_reset'     => '1',
        // البريد الإلكتروني (يعمل عند ضبط خادم البريد من لوحة المسؤول ← البريد الإلكتروني)
        'mail.welcome'           => '1',
        'mail.invoice'           => '1',
        'mail.reminder'          => '1',
        'mail.expired'           => '1',
        'mail.transfer'          => '1',
        'mail.ticket'            => '1',
        'mail.secure'            => 'tls',
        'mail.port'              => '587',
        // إشعارات المسؤول على تيليجرام
        'notify.chat'            => '',
        'notify.signup'          => '1',
        'notify.transfer'        => '1',
        'notify.expired'         => '1',
        'notify.limit'           => '1',
        'notify.ticket'          => '1',
        'notify.weekly'          => '1',
        'notify.weekly_day'      => '6',
        'notify.bot'             => '1',
        // بوت خدمة الزبائن (واتساب ونافذة الموقع) لأصحاب الشبكات ومشتركيهم وأصحاب محلات MADIX ERP
        'bot.enabled'            => '0',
        'bot.name'               => '',
        'bot.knowledge'          => '',
        'bot.per_day'            => '40',
        'bot.web'                => '0',
        'bot.web_key'            => '',
        'bot.whatsapp'           => '0',
        'bot.wa_phone_id'        => '',
        'bot.wa_token'           => '',
        'bot.wa_secret'          => '',
        'bot.wa_verify'          => '',
        'bot.wa_hook'            => '',
        'bot.erp'                => '0',
        'bot.erp_url'            => 'https://cpanel.madix.org',
        'bot.erp_key'            => '',
        'bot.erp_cache'          => '',
        'bot.retention_days'     => '180',
    ];

    /** الأقسام التي يمكن تفعيلها أو إيقافها لكل باقة: المفتاح => [التسمية، بادئات الصلاحيات] */
    public const MODULES = [
        'cards'       => ['الكروت والطباعة', ['cards.', 'categories.', 'check.', 'print.']],
        'subscribers' => ['المشتركون', ['subscribers.', 'plans.']],
        'sales'       => ['المبيعات والموزعون', ['sales.', 'distributors.']],
        'hotspot'     => ['ربط الهوتسبوت', ['hotspot.']],
        'map'         => ['خريطة الشبكة والمواقع', ['map.']],
        'inventory'   => ['المخزون', ['inventory.']],
        'sms'         => ['الرسائل النصية', ['sms.']],
        'telegram'    => ['بوت تيليجرام', ['telegram.']],
        'reports'     => ['التقارير', ['reports.']],
        'power'       => ['مراقبة الطاقة', ['power.']],
        'outages'     => ['الانقطاعات والتعويض', ['outages.']],
        'expenses'    => ['المصاريف وصافي الربح', ['expenses.', 'profit.']],
        'maintenance' => ['مهام الصيانة', ['maintenance.']],
    ];

    /** أقسام أضيفت بعد إنشاء الباقات: تُضاف لكل باقة قائمة عند الترقية حتى لا تختفي الميزة الجديدة عن أصحابها (رقم البنية => الأقسام) */
    private const NEW_MODULES = [6 => ['expenses', 'maintenance']];

    private static array $plans = [];

    /** باقة بمعرّفها (من قاعدة المنصة، مع تخزين مؤقت للطلب) */
    public static function plan(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$plans)) {
            try {
                self::$plans[$id] = self::row('SELECT * FROM platform_plans WHERE id = ?', [$id]);
            } catch (\Throwable) {
                self::$plans[$id] = null;
            }
        }
        return self::$plans[$id];
    }

    public static function flushPlans(): void
    {
        self::$plans = [];
    }

    /** الأقسام المفعّلة في باقة (null = كل الأقسام) */
    public static function planModules(?array $plan): ?array
    {
        if (!$plan || $plan['modules'] === null || $plan['modules'] === '') {
            return null;
        }
        $list = json_decode((string) $plan['modules'], true);
        return is_array($list) ? array_values(array_intersect(array_keys(self::MODULES), $list)) : null;
    }

    /** هل الصلاحية ضمن قسم أوقفته باقة الحساب الحالي */
    public static function permissionBlocked(string $permission): bool
    {
        $tenant = Tenant::current();
        if ($tenant === null || empty($tenant['plan_id'])) {
            return false;
        }
        $enabled = self::planModules(self::plan((int) $tenant['plan_id']));
        if ($enabled === null) {
            return false;
        }
        foreach (self::MODULES as $key => [, $prefixes]) {
            if (in_array($key, $enabled, true)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** مدّتا السماح والقراءة فقط بالثواني بعد انتهاء التجربة أو الاشتراك */
    public static function billingWindows(): array
    {
        $grace = max(0, min(60, (int) self::setting('billing.grace_days')));
        $readonly = max(0, min(90, (int) self::setting('billing.readonly_days')));
        return [$grace * 86400, $readonly * 86400];
    }

    /** تشفير سرّ بمفتاح المنصة الجذري (مستقل عن سياق أي حساب) */
    public static function seal(string $plain): string
    {
        return $plain === '' ? '' : Crypto::encrypt($plain, self::rootKey());
    }

    public static function unseal(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return Crypto::decrypt($value, self::rootKey());
        } catch (\Throwable) {
            return '';
        }
    }

    /** قراءة سرّ مخزّن مشفّراً في إعدادات المنصة */
    public static function secret(string $key): string
    {
        return self::unseal(self::setting($key, ''));
    }

    private static ?array $root = null;
    private static ?PDO $pdo = null;
    private static ?array $settings = null;
    private static bool $migrated = false;

    /** نسيان كل ما خُزّن عن قاعدة المنصة (عند تبديلها أو إعادة إنشائها) */
    public static function reset(): void
    {
        self::$root = null;
        self::$pdo = null;
        self::$settings = null;
        self::$plans = [];
        self::$migrated = false;
    }

    public static function enabled(): bool
    {
        return (bool) Config::get('platform.enabled', false);
    }

    /** حفظ الإعداد الجذري قبل الدخول في سياق أي زبون */
    public static function boot(): void
    {
        if (self::$root === null) {
            self::$root = [
                'db' => Config::get('db'),
                'key' => (string) Config::get('app.key', ''),
                'cron_key' => (string) Config::get('app.cron_key', ''),
                'url' => rtrim((string) Config::get('app.url', ''), '/'),
            ];
        }
    }

    public static function rootDb(): array
    {
        self::boot();
        return self::$root['db'];
    }

    public static function rootKey(): string
    {
        self::boot();
        return self::$root['key'];
    }

    public static function cronKey(): string
    {
        self::boot();
        return self::$root['cron_key'];
    }

    public static function rootUrl(): string
    {
        self::boot();
        return self::$root['url'];
    }

    public static function tenantDb(): array
    {
        $t = (array) Config::get('platform.tenant_db', []);
        $root = self::rootDb();
        return [
            'host' => $t['host'] ?? $root['host'], 'port' => (int) ($t['port'] ?? $root['port'] ?? 3306),
            'user' => $t['user'] ?? $root['user'], 'pass' => $t['pass'] ?? $root['pass'], 'prefix' => $t['prefix'] ?? 'mnx_',
        ];
    }

    /** اتصال قاعدة المنصة (مستقل عن سياق الزبون) */
    public static function pdo(): PDO
    {
        if (Tenant::current() === null) {
            return DB::pdo();
        }
        if (self::$pdo === null) {
            $c = self::rootDb();
            self::$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']), $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . (new \DateTime('now'))->format('P') . "'");
        }
        return self::$pdo;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', array_map(static fn ($c) => '`' . $c . '`', $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $pdo = self::pdo();
        $pdo->prepare($sql)->execute(array_values($data));
        return (int) $pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => '`' . $c . '` = ?', array_keys($data)));
        return self::exec('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $where, array_merge(array_values($data), $params));
    }

    /** إصدار جداول المنصة — يُرفع عند إضافة جداول جديدة إلى platform.sql */
    public const SCHEMA = 9;

    /** أعمدة تُضاف إلى الجداول القائمة عند الترقية */
    private const COLUMNS = [
        'tenants' => [
            'max_routers'       => 'INT UNSIGNED NULL',
            'max_subscribers'   => 'INT UNSIGNED NULL',
            'max_employees'     => 'INT UNSIGNED NULL',
            'plan_id'           => 'INT UNSIGNED NULL',
            'group_id'          => 'INT UNSIGNED NULL',
            'ai_enabled'        => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ai_limit'          => 'INT UNSIGNED NULL',
            'phone_verified_at' => 'DATETIME NULL',
            'reminder_key'      => 'VARCHAR(40) NULL',
            'limit_key'         => 'VARCHAR(60) NULL',
            'stat_routers'      => 'INT UNSIGNED NULL',
            'stat_subscribers'  => 'INT UNSIGNED NULL',
            'stat_employees'    => 'INT UNSIGNED NULL',
            'stat_sales'        => 'DECIMAL(12,2) NULL',
            'stat_login_at'     => 'DATETIME NULL',
            'stats_at'          => 'DATETIME NULL',
        ],
        'tenant_payments' => [
            'invoice_no'  => 'VARCHAR(30) NULL',
            'plan_name'   => 'VARCHAR(80) NULL',
            'transfer_id' => 'INT UNSIGNED NULL',
        ],
        'phone_codes' => [
            'purpose' => "VARCHAR(20) NOT NULL DEFAULT 'signup'",
        ],
        'platform_admins' => [
            'totp_secret'    => 'VARCHAR(255) NULL',
            'totp_enabled'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'recovery_codes' => 'TEXT NULL',
            'is_owner'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'permissions'    => 'TEXT NULL',
            'email'          => 'VARCHAR(190) NULL',
        ],
    ];

    /** إنشاء جداول المنصة أو استكمالها عند الحاجة */
    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }
        self::$migrated = true;
        $exists = (int) DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'platform_settings'");
        if ($exists > 0 && (int) self::setting('platform.schema', '0') >= self::SCHEMA) {
            return;
        }
        foreach (Installer::statements((string) file_get_contents(BASE_PATH . '/database/platform.sql')) as $sql) {
            DB::pdo()->exec($sql);
        }
        // أعمدة أضيفت بعد الإصدار الأول
        foreach (self::COLUMNS as $table => $columns) {
            $have = array_flip(array_map('strtolower', DB::column('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table])));
            foreach ($columns as $column => $type) {
                if (!isset($have[$column])) {
                    DB::pdo()->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $type);
                }
            }
        }
        self::flushSettings();
        $from = $exists > 0 ? (int) self::setting('platform.schema', '0') : self::SCHEMA;
        foreach (self::NEW_MODULES as $version => $keys) {
            if ($from < $version) {
                self::addPlanModules($keys);
            }
        }
        self::ensureOwner();
        self::setSettings(['platform.schema' => (string) self::SCHEMA]);
    }

    /** مالك المنصة: أول مسؤول فعّال إن لم يكن هناك مالك (عند الترقية ومن مثبّت المنصة) */
    public static function ensureOwner(): void
    {
        if (!(int) DB::value('SELECT COUNT(*) FROM platform_admins WHERE is_owner = 1')) {
            DB::query('UPDATE platform_admins SET is_owner = 1, permissions = NULL WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM platform_admins WHERE is_active = 1) x)');
        }
    }

    private static function addPlanModules(array $keys): void
    {
        foreach (self::rows('SELECT id, modules FROM platform_plans WHERE modules IS NOT NULL') as $p) {
            $mods = json_decode((string) $p['modules'], true);
            if (!is_array($mods)) {
                continue;
            }
            $merged = array_values(array_unique(array_merge($mods, $keys)));
            if ($merged !== $mods) {
                self::exec('UPDATE platform_plans SET modules = ? WHERE id = ?', [count($merged) === count(self::MODULES) ? null : json_encode($merged), $p['id']]);
            }
        }
        self::$plans = [];
    }

    // ------------------------------------------------------------------
    // الإعدادات
    // ------------------------------------------------------------------

    public static function setting(string $key, ?string $default = null): string
    {
        if (self::$settings === null) {
            self::$settings = [];
            try {
                $st = self::pdo()->query('SELECT `key`, `value` FROM platform_settings');
                foreach ($st->fetchAll() as $r) {
                    self::$settings[$r['key']] = (string) $r['value'];
                }
            } catch (\Throwable) {
                // قبل إنشاء الجداول
            }
        }
        return self::$settings[$key] ?? $default ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function setSettings(array $values): void
    {
        $st = self::pdo()->prepare('INSERT INTO platform_settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)');
        foreach ($values as $k => $v) {
            $st->execute([$k, (string) $v, date('Y-m-d H:i:s')]);
        }
        self::$settings = null;
    }

    public static function flushSettings(): void
    {
        self::$settings = null;
    }

    // ------------------------------------------------------------------
    // الزبائن والنشاط
    // ------------------------------------------------------------------

    public static function tenantBySlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) {
            return null;
        }
        return self::row('SELECT * FROM tenants WHERE slug = ?', [$slug]);
    }

    /** الحساب الذي كان هذا اسمه قبل تغييره (الروابط القديمة تبقى تعمل) */
    public static function tenantByAlias(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) {
            return null;
        }
        try {
            return self::row('SELECT t.* FROM tenant_aliases a JOIN tenants t ON t.id = a.tenant_id WHERE a.slug = ?', [$slug]);
        } catch (\Throwable) {
            return null; // قبل ترحيل قاعدة المنصة
        }
    }

    /** @return list<string> الأسماء السابقة لحساب */
    public static function aliases(int $tenantId): array
    {
        try {
            return array_map('strval', array_column(self::rows('SELECT slug FROM tenant_aliases WHERE tenant_id = ? ORDER BY created_at DESC', [$tenantId]), 'slug'));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function log(?int $tenantId, string $actor, string $action, string $description, ?string $actorName = null): void
    {
        try {
            $ip = Request::current()?->ip();
            self::exec(
                'INSERT INTO platform_activity (tenant_id, actor, actor_name, action, description, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$tenantId, $actor, $actorName !== null ? mb_substr($actorName, 0, 120) : null, $action, mb_substr($description, 0, 255), $ip, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            log_message('error', 'platform log: ' . $e->getMessage());
        }
    }

    /** إصدار تذكرة دخول لمرة واحدة صالحة لدقيقتين */
    public static function ticket(int $tenantId, int $employeeId, string $purpose, ?int $adminId = null): string
    {
        $token = bin2hex(random_bytes(32));
        self::exec(
            'INSERT INTO login_tickets (token_hash, tenant_id, employee_id, purpose, admin_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [hash('sha256', $token), $tenantId, $employeeId, $purpose, $adminId, date('Y-m-d H:i:s', time() + 120), date('Y-m-d H:i:s')]
        );
        return $token;
    }

    /** استهلاك تذكرة (مرة واحدة) */
    public static function redeem(string $token, int $tenantId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $t = self::row('SELECT * FROM login_tickets WHERE token_hash = ? AND tenant_id = ?', [hash('sha256', $token), $tenantId]);
        if (!$t || $t['used_at'] !== null || strtotime($t['expires_at']) < time()) {
            return null;
        }
        if (self::exec('UPDATE login_tickets SET used_at = ? WHERE id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), $t['id']]) !== 1) {
            return null;
        }
        return $t;
    }

    public static function whatsappLink(string $text = ''): string
    {
        $n = preg_replace('/\D/', '', self::setting('platform.whatsapp_intl'));
        return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    public static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new RuntimeException('وضع المنصة غير مفعّل.');
        }
    }
}
