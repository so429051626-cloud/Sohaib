<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * تحديث قاعدة البيانات تلقائياً عند رفع نسخة أحدث من النظام فوق تثبيت قائم.
 * كل خطوة آمنة للتكرار: لا تُنفَّذ إلا إن لم يكن التعديل موجوداً.
 */
final class Migrator
{
    /** نسخة البنية التي تتوقعها الشيفرة الحالية */
    public const VERSION = 15;

    private static bool $checked = false;

    /** إعادة الفحص عند الانتقال إلى قاعدة بيانات أخرى أو بعد استعادة نسخة */
    public static function reset(): void
    {
        self::$checked = false;
    }

    public static function run(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;
        $current = (int) Settings::get('app.schema_version', '1');
        if ($current >= self::VERSION) {
            return;
        }
        $lock = storage_path('locks/migrate.lock');
        if (!is_dir(dirname($lock))) {
            @mkdir(dirname($lock), 0775, true);
        }
        $fh = @fopen($lock, 'c');
        if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
            // تحديث آخر يجري الآن في طلب موازٍ
            fclose($fh);
            return;
        }
        try {
            for ($v = $current + 1; $v <= self::VERSION; $v++) {
                self::step($v);
                Settings::set('app.schema_version', (string) $v);
            }
        } catch (Throwable $e) {
            log_message('migrate', 'migration failed: ' . $e->getMessage());
        } finally {
            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    private static function step(int $version): void
    {
        switch ($version) {
            case 2:
                // كلمة المرور المختصرة المرافقة للرقم المختصر
                if (!self::hasColumn('cards', 'short_password')) {
                    DB::query('ALTER TABLE cards ADD COLUMN short_password VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER short_code');
                }
                break;
            case 3:
                // خريطة الشبكة والمخزون والأجهزة الموثوقة للبصمة
                self::createTables(['sites', 'site_links', 'inventory_items', 'inventory_units', 'inventory_movements', 'employee_devices']);
                foreach (['routers' => 'area_id', 'subscribers' => 'router_id'] as $table => $after) {
                    if (!self::hasColumn($table, 'site_id')) {
                        DB::query("ALTER TABLE $table ADD COLUMN site_id INT UNSIGNED NULL AFTER $after, ADD COLUMN lat DECIMAL(10,7) NULL AFTER site_id, ADD COLUMN lng DECIMAL(10,7) NULL AFTER lat, ADD KEY idx_{$table}_site (site_id)");
                        DB::query("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL");
                    }
                }
                break;
            case 4:
                // بوت الزبائن
                self::createTables(['telegram_links']);
                break;
            case 5:
                // الرسائل النصية
                self::createTables(['sms_messages', 'sms_requests', 'sms_recipients', 'sms_templates', 'sms_auto']);
                break;
            case 6:
                // تطبيق الجوال
                self::createTables(['app_tokens']);
                break;
            case 7:
                // مراقبة الطاقة، سجل الانقطاعات، وتعويض المشتركين
                self::createTables(['router_power', 'outages', 'outage_compensations']);
                foreach (['voltage_warn' => 'DECIMAL(5,1) NULL', 'voltage_crit' => 'DECIMAL(5,1) NULL', 'power_at' => 'DATETIME NULL', 'power_state' => 'VARCHAR(10) NULL'] as $column => $type) {
                    if (!self::hasColumn('routers', $column)) {
                        DB::query('ALTER TABLE routers ADD COLUMN ' . $column . ' ' . $type);
                    }
                }
                DB::query("ALTER TABLE subscriber_transactions MODIFY COLUMN type ENUM('renewal','payment','charge','discount','refund','compensation') NOT NULL");
                break;
            case 8:
                // الإصدار 2.3.0 (كل خطواته تُعاد بأمان): التحقق بخطوتين للمستخدمين
                self::addColumns('employees', ['totp_secret' => 'VARCHAR(255) NULL', 'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'recovery_codes' => 'TEXT NULL']);
                // المصاريف وصافي الربح
                self::createTables(['expenses', 'partners']);
                // مهام الصيانة
                self::createTables(['maintenance_tasks', 'maintenance_parts']);
                // فحص الكابلات
                self::createTables(['cable_checks']);
                break;
            case 9:
                // ربط دفعات الكروت بأجهزة MikroTik محددة
                self::createTables(['batch_routers']);
                break;
            case 10:
                // كانت جداول بوت خدمة الزبائن في كل شبكة؛ انتقل البوت إلى المنصة (الخطوة 12 تحذفها)
                break;
            case 11:
                // سرعة لوحة التحكم والباقات: إجماليات الكروت تُقرأ من فهرس صغير بدل قراءة كل الكروت
                self::addIndex('cards', 'idx_cards_state', '(status, is_disabled)');
                self::addIndex('cards', 'idx_cards_cat_state', '(category_id, status, is_disabled)');
                break;
            case 12:
                // بوت خدمة الزبائن صار للمنصة كلها (قاعدة المنصة): جداوله القديمة في الشبكة لم تعد تُستخدم
                DB::pdo()->exec('DROP TABLE IF EXISTS bot_messages');
                DB::pdo()->exec('DROP TABLE IF EXISTS bot_chats');
                break;
            case 13:
                // الحسابات الخاصة: حساب هوتسبوت باسم مستخدم وكلمة مرور وحدود خاصة به (باقة ودفعة مخفيتان لكل حساب)
                self::addColumns('categories', ['is_custom' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active']);
                self::addIndex('categories', 'idx_categories_custom', '(is_custom)');
                DB::query("ALTER TABLE batches MODIFY COLUMN source ENUM('generated','imported','router','custom') NOT NULL");
                // الوضع الفاتح افتراضياً بدل «حسب الجهاز»، ومن اختار الداكن يبقى عليه
                DB::query("ALTER TABLE employees MODIFY COLUMN theme ENUM('system','light','dark') NOT NULL DEFAULT 'light'");
                DB::query("UPDATE employees SET theme = 'light' WHERE theme = 'system'");
                break;
            case 14:
                // إعلانات صفحة الهوتسبوت
                self::createTables(['hotspot_ads']);
                break;
            case 15:
                // كشف الاستهلاك الزائد: السرعة الحالية لكل جلسة، وتحديد سرعة المستهلكين
                self::addColumns('sessions', [
                    'rate_down' => 'BIGINT UNSIGNED NULL', 'rate_up' => 'BIGINT UNSIGNED NULL',
                    'heavy_since' => 'DATETIME NULL', 'heavy_alerted' => 'TINYINT(1) NOT NULL DEFAULT 0',
                ]);
                self::createTables(['speed_caps']);
                break;
        }
    }

    /** إنشاء جداول بتعريفها الموجود في ملف البنية */
    private static function createTables(array $tables): void
    {
        $sql = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        foreach ($tables as $table) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \(.*?\) ENGINE=[^;]+;/su', $sql, $m)) {
                throw new \RuntimeException('تعريف الجدول ' . $table . ' غير موجود.');
            }
            DB::query($m[0]);
        }
    }

    /** إضافة أعمدة غير موجودة إلى جدول قائم */
    private static function addColumns(string $table, array $columns): void
    {
        foreach ($columns as $column => $type) {
            if (!self::hasColumn($table, $column)) {
                DB::query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $type);
            }
        }
    }

    /** إضافة فهرس غير موجود (يُبنى دون إيقاف القراءة والكتابة) */
    private static function addIndex(string $table, string $name, string $columns): void
    {
        $exists = (int) DB::value(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $name]
        );
        if (!$exists) {
            DB::query('ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` ' . $columns);
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return (bool) DB::value(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }
}
