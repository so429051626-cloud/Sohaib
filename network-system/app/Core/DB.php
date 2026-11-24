<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * طبقة وصول بسيطة فوق PDO. كل الاستعلامات مُهيّأة (prepared).
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'], (int) $c['port'], $c['name'], $c['charset'] ?? 'utf8mb4'
            );
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $offset = (new \DateTime('now'))->format('P');
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . $offset . "'");
        }
        return self::$pdo;
    }

    /** مزامنة المنطقة الزمنية لجلسة MySQL مع PHP */
    public static function syncTimezone(): void
    {
        if (self::$pdo !== null) {
            $offset = (new \DateTime('now'))->format('P');
            self::$pdo->exec("SET time_zone = '" . $offset . "'");
        }
    }

    public static function reconnect(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function rows(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** خريطة مفتاح ← قيمة من عمودين */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** إدراج عدة صفوف دفعة واحدة؛ كل الصفوف بنفس الأعمدة */
    public static function insertMany(string $table, array $rows, string $suffix = ''): int
    {
        if (!$rows) {
            return 0;
        }
        $cols = array_keys(reset($rows));
        $placeholder = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $values = [];
        foreach ($rows as $row) {
            foreach ($cols as $c) {
                $values[] = $row[$c];
            }
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s %s',
            $table,
            implode(', ', array_map(static fn ($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($rows), $placeholder)),
            $suffix
        );
        return self::query($sql, $values)->rowCount();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $sets, $where);
        return self::query($sql, array_merge(array_values($data), $params))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::query(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    /**
     * تنفيذ دالة داخل معاملة. المعاملات المتداخلة تُدمج في المعاملة الأم.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            self::$depth++;
            try {
                return $fn();
            } finally {
                self::$depth--;
            }
        }
        $pdo->beginTransaction();
        self::$depth = 1;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::$depth = 0;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /** بناء IN (?, ?, ?) آمن */
    public static function in(array $values): string
    {
        return $values ? implode(', ', array_fill(0, count($values), '?')) : 'NULL';
    }

    /** هروب نص LIKE */
    public static function like(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
