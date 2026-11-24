<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * سجل أسماء الدخول الموحّد: يضمن عدم تكرار اسم بين كرت ورقم مختصر ومشترك.
 */
final class LoginRegistry
{
    /** القيم المحجوزة من القائمة */
    public static function taken(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        $found = [];
        foreach (array_chunk($values, 1000) as $chunk) {
            foreach (DB::column('SELECT login FROM logins WHERE login IN (' . DB::in($chunk) . ')', $chunk) as $v) {
                $found[(string) $v] = true;
            }
        }
        return $found;
    }

    public static function isTaken(string $login, ?string $exceptType = null, ?int $exceptId = null): bool
    {
        $row = DB::row('SELECT owner_type, owner_id FROM logins WHERE login = ?', [$login]);
        if (!$row) {
            return false;
        }
        return !($exceptType !== null && $row['owner_type'] === $exceptType && (int) $row['owner_id'] === $exceptId);
    }

    public static function owner(string $login): ?array
    {
        $row = DB::row('SELECT owner_type, owner_id FROM logins WHERE login = ?', [$login]);
        return $row ? [$row['owner_type'], (int) $row['owner_id']] : null;
    }

    public static function add(string $login, string $type, int $id): void
    {
        DB::insert('logins', ['login' => $login, 'owner_type' => $type, 'owner_id' => $id]);
    }

    public static function addMany(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::insertMany('logins', $chunk);
        }
    }

    public static function remove(string $type, int $id): void
    {
        DB::delete('logins', 'owner_type = ? AND owner_id = ?', [$type, $id]);
    }

    public static function removeLogin(string $login): void
    {
        DB::delete('logins', 'login = ?', [$login]);
    }

    /** حذف كل سجلات كروت (الاسم والرقم المختصر) */
    public static function removeCards(array $cardIds): void
    {
        foreach (array_chunk(array_map('intval', $cardIds), 1000) as $chunk) {
            DB::query("DELETE FROM logins WHERE owner_type IN ('card','card_short') AND owner_id IN (" . DB::in($chunk) . ')', $chunk);
        }
    }

    /** استبدال اسم دخول (عند تغيير اسم مستخدم مشترك مثلاً) */
    public static function replace(string $type, int $id, string $newLogin): void
    {
        DB::delete('logins', 'owner_type = ? AND owner_id = ?', [$type, $id]);
        self::add($newLogin, $type, $id);
    }
}
