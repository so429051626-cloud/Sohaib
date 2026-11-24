<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Settings;
use Throwable;
use PDO;
use RuntimeException;

/**
 * نسخ احتياطي لقاعدة البيانات بصيغة SQL مضغوطة (gzip) دون الحاجة لـ mysqldump.
 */
final class BackupService
{
    /** جداول كبيرة يمكن استثناء بياناتها في النسخ التلقائية */
    private const HEAVY = ['router_metrics', 'login_log', 'audit_log', 'throttle'];

    public static function dir(): string
    {
        $dir = storage_path('backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** إنشاء ملف نسخة احتياطية وإرجاع اسمه */
    public static function create(bool $includeHeavy = true): string
    {
        @set_time_limit(300);
        $name = 'backup-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.sql.gz';
        $path = self::dir() . '/' . $name;
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('تعذّر إنشاء ملف النسخة الاحتياطية.');
        }
        $pdo = DB::pdo();
        gzwrite($gz, "-- نسخة احتياطية: " . Settings::networkName() . "\n-- التاريخ: " . date('Y-m-d H:i:s') . "\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . $create . ";\n\n");
            if (!$includeHeavy && in_array($table, self::HEAVY, true)) {
                continue;
            }
            $stmt = $pdo->query('SELECT * FROM `' . $table . '`', PDO::FETCH_ASSOC);
            $batch = [];
            $cols = null;
            while ($row = $stmt->fetch()) {
                if ($cols === null) {
                    $cols = '(' . implode(', ', array_map(static fn ($c) => "`$c`", array_keys($row))) . ')';
                }
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $batch[] = '(' . implode(', ', $vals) . ')';
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `$table` $cols VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `$table` $cols VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($gz);
        Settings::set('cron.last_backup', now());
        return $name;
    }

    /** @return array<int, array{name:string,size:int,time:int}> */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.sql.gz') ?: [] as $file) {
            $out[] = ['name' => basename($file), 'size' => (int) filesize($file), 'time' => (int) filemtime($file)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^backup-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}\.sql\.gz$/', $name)) {
            return null;
        }
        $path = self::dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function prune(int $keep): void
    {
        $list = self::list();
        foreach (array_slice($list, max(1, $keep)) as $b) {
            @unlink(self::dir() . '/' . $b['name']);
        }
    }

    /** استعادة من ملف SQL (مضغوط أو عادي) */
    public static function restore(string $file): int
    {
        @set_time_limit(600);
        $raw = (string) file_get_contents($file);
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = (string) gzdecode($raw);
        }
        if ($raw === '' || !str_contains($raw, 'CREATE TABLE')) {
            throw new RuntimeException('الملف لا يحتوي على نسخة احتياطية صالحة.');
        }
        $statements = self::split($raw);
        unset($raw);
        // يُفحص الملف كاملاً قبل تنفيذ أي عبارة حتى لا تُستبدل البيانات بملف مرفوض
        foreach ($statements as $statement) {
            if (!self::safe($statement)) {
                throw new RuntimeException('الملف ليس نسخة احتياطية أنشأها هذا النظام.');
            }
        }
        $pdo = DB::pdo();
        $count = 0;
        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }

    /**
     * هل العبارة من الأنواع التي يكتبها هذا النظام في نسخه فقط:
     * ضبط الترميز والمفاتيح الأجنبية، حذف جدول وإنشاؤه، وإدراج قيم نصية.
     * كل ما عدا ذلك (قواعد أخرى، استعلامات فرعية، دوال، تعليقات تنفيذية) مرفوض.
     */
    public static function safe(string $statement): bool
    {
        $s = trim($statement);
        if (preg_match('/^SET NAMES utf8mb4$/i', $s) || preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $s)) {
            return true;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `[A-Za-z0-9_]{1,64}`$/', $s)) {
            return true;
        }
        // النصوص بين علامات الاقتباس تُحذف أولاً ثم يُفحص ما بقي من العبارة
        $bare = preg_replace("/'(?:[^'\\\\]++|\\\\.|'')*+'/s", "''", $s);
        if ($bare === null || str_contains($bare, '/*') || str_contains($bare, '--') || str_contains($bare, '#') || str_contains($bare, ';')) {
            return false;
        }
        if (preg_match('/^INSERT INTO `[A-Za-z0-9_]{1,64}` \((?:`[A-Za-z0-9_]{1,64}`(?:, )?)+\) VALUES\s*(.*)$/s', $bare, $m)) {
            return (bool) preg_match("/^(?:\\((?:''|NULL)(?:, (?:''|NULL))*\\)(?:,\\s*)?)+$/", $m[1]);
        }
        if (preg_match('/^CREATE TABLE `[A-Za-z0-9_]{1,64}` \(/', $bare)) {
            $plain = preg_replace('/`[^`]*`/', ' x ', $bare);
            return !str_contains($bare, '`.`')
                && !preg_match('/`\s*\.|\.\s*`/', $bare)
                && !preg_match('/[A-Za-z_]\w*\s*\.\s*[A-Za-z_]/', (string) $plain)
                && !preg_match('/\b(SELECT|DIRECTORY|CONNECTION|UNION|TABLESPACE|PARTITION)\b/i', (string) $plain);
        }
        return false;
    }

    /** مفتاح التشفير من نص ملف config.php لنظام آخر (يُقرأ كنص ولا يُنفَّذ) */
    public static function keyFromConfig(string $text): ?string
    {
        return preg_match("/'key'\\s*=>\\s*'(base64:[A-Za-z0-9+\\/=]{40,64})'/", $text, $m) ? $m[1] : null;
    }

    /**
     * إعادة تشفير الأسرار المخزّنة (كلمات مرور أجهزة MikroTik والمفاتيح في الإعدادات والأنفاق)
     * بمفتاح هذا النظام بعد استعادة نسخة شُفّرت بمفتاح نظام آخر. يرجع عدد القيم المحوّلة.
     */
    public static function rekey(string $oldKey): int
    {
        $current = (string) Config::get('app.key', '');
        if ($oldKey === '' || $oldKey === $current) {
            return 0;
        }
        $open = static function (string $value) use ($oldKey, $current): ?string {
            Config::set('app.key', $oldKey);
            try {
                return Crypto::decrypt($value);
            } catch (Throwable) {
                return null;
            } finally {
                Config::set('app.key', $current);
            }
        };
        $count = 0;
        foreach (DB::rows('SELECT id, api_password FROM routers') as $r) {
            $plain = $open((string) $r['api_password']);
            if ($plain !== null) {
                DB::update('routers', ['api_password' => Crypto::encrypt($plain)], 'id = ?', [$r['id']]);
                $count++;
            }
        }
        foreach (DB::rows("SELECT `key`, `value` FROM settings WHERE `value` LIKE 's1:%' OR `value` LIKE 'g1:%'") as $r) {
            $plain = $open((string) $r['value']);
            if ($plain !== null) {
                DB::query('UPDATE settings SET `value` = ? WHERE `key` = ?', [Crypto::encrypt($plain), $r['key']]);
                $count++;
            }
        }
        Settings::flush();
        return $count;
    }

    /** هل تُفك كلمات مرور أجهزة MikroTik بمفتاح النظام الحالي (null = لا توجد أجهزة MikroTik) */
    public static function secretsReadable(): ?bool
    {
        $rows = DB::rows('SELECT api_password FROM routers LIMIT 5');
        if (!$rows) {
            return null;
        }
        foreach ($rows as $r) {
            try {
                Crypto::decrypt((string) $r['api_password']);
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    /** تقسيم SQL مع مراعاة النصوص بين علامات الاقتباس */
    private static function split(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        $quote = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inStr) {
                $buf .= $ch;
                if ($ch === '\\') {
                    $buf .= $sql[++$i] ?? '';
                } elseif ($ch === $quote) {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inStr = true;
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && ($i === 0 || $sql[$i - 1] === "\n")) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if ($ch === ';') {
                $s = trim($buf);
                if ($s !== '') {
                    $statements[] = $s;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $s = trim($buf);
        if ($s !== '') {
            $statements[] = $s;
        }
        return $statements;
    }
}
