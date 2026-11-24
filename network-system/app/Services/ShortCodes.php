<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use RuntimeException;

/**
 * مختصِر الأرقام: رقم قصير فريد مربوط بكل كرت طويل.
 * الأنماط: random (عشوائي)، tail (آخر أرقام الكرت)، sequence (تسلسلي).
 */
final class ShortCodes
{
    public const MODES = [
        'random'   => 'عشوائي',
        'tail'     => 'آخر أرقام اسم المستخدم',
        'sequence' => 'تسلسلي',
    ];

    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 16;

    /** حدود طول كلمة المرور المختصرة (0 = بدون كلمة مرور) */
    public const MIN_PASSWORD = 3;
    public const MAX_PASSWORD = 16;

    /** التحقق من طول كلمة المرور المختصرة */
    public static function checkPasswordLength(int $length): void
    {
        if ($length !== 0 && ($length < self::MIN_PASSWORD || $length > self::MAX_PASSWORD)) {
            throw new RuntimeException('طول كلمة المرور المختصرة يجب أن يكون 0 أو بين ' . self::MIN_PASSWORD . ' و' . self::MAX_PASSWORD . '.');
        }
    }

    /**
     * كلمات مرور مختصرة (بنفس المفاتيح). النمط tail يأخذ آخر أرقام كلمة المرور الأصلية إن كفت.
     * @param array $passwords كلمات المرور الأصلية
     * @return array<string|null>
     */
    public static function passwords(array $passwords, int $length, string $mode): array
    {
        self::checkPasswordLength($length);
        $out = [];
        foreach ($passwords as $k => $p) {
            if ($length === 0) {
                $out[$k] = null;
                continue;
            }
            $digits = preg_replace('/\D/', '', (string) $p);
            if ($mode === 'tail' && strlen($digits) >= $length) {
                $out[$k] = substr($digits, -$length);
                continue;
            }
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= (string) random_int(0, 9);
            }
            $out[$k] = $code;
        }
        return $out;
    }

    /**
     * تخصيص أرقام مختصرة لقائمة أسماء مستخدمين (بنفس الترتيب والمفاتيح).
     * @param array $exclude قيم ممنوعة إضافية (مفاتيح)
     */
    public static function allocate(array $usernames, int $length, string $mode, array $exclude = [], ?int $start = null): array
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new RuntimeException('طول الرقم المختصر يجب أن يكون بين ' . self::MIN_LENGTH . ' و' . self::MAX_LENGTH . '.');
        }
        $count = count($usernames);
        if ($count === 0) {
            return [];
        }
        $space = 10 ** $length;
        $existing = (int) DB::value('SELECT COUNT(*) FROM logins');
        if ($mode !== 'tail' && $space < ($count + $existing) * ($mode === 'random' ? 2 : 1)) {
            throw new RuntimeException('الطول ' . $length . ' لا يكفي لهذا العدد من الكروت. زد طول الرقم المختصر.');
        }

        $result = [];
        $used = $exclude;

        if ($mode === 'sequence') {
            $n = $start ?? (int) ('1' . str_repeat('0', $length - 1));
            $maxN = $space - 1;
            foreach ($usernames as $k => $_) {
                while (true) {
                    if ($n > $maxN) {
                        throw new RuntimeException('نفدت الأرقام المتاحة لهذا الطول. زد طول الرقم المختصر أو غيّر رقم البداية.');
                    }
                    $code = str_pad((string) $n, $length, '0', STR_PAD_LEFT);
                    $n++;
                    if (!isset($used[$code])) {
                        $result[$k] = $code;
                        $used[$code] = true;
                        break;
                    }
                }
            }
            self::resolveTaken($result, $length, $used);
            return $result;
        }

        if ($mode === 'tail') {
            $fallback = [];
            foreach ($usernames as $k => $u) {
                $digits = preg_replace('/\D/', '', (string) $u);
                $code = strlen($digits) >= $length ? substr($digits, -$length) : null;
                if ($code !== null && !isset($used[$code])) {
                    $result[$k] = $code;
                    $used[$code] = true;
                } else {
                    $fallback[] = $k;
                }
            }
            foreach ($fallback as $k) {
                $result[$k] = self::randomCode($length, $used);
            }
            self::resolveTaken($result, $length, $used);
            return $result;
        }

        foreach ($usernames as $k => $_) {
            $result[$k] = self::randomCode($length, $used);
        }
        self::resolveTaken($result, $length, $used);
        return $result;
    }

    private static function randomCode(int $length, array &$used): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $code = (string) random_int(1, 9);
            for ($j = 1; $j < $length; $j++) {
                $code .= (string) random_int(0, 9);
            }
            if (!isset($used[$code])) {
                $used[$code] = true;
                return $code;
            }
        }
        throw new RuntimeException('تعذّر إيجاد رقم مختصر متاح. زد الطول.');
    }

    /** استبدال الأرقام المحجوزة مسبقاً في قاعدة البيانات */
    private static function resolveTaken(array &$result, int $length, array &$used): void
    {
        for ($round = 0; $round < 20; $round++) {
            $taken = LoginRegistry::taken(array_values($result));
            if (!$taken) {
                return;
            }
            foreach ($result as $k => $code) {
                if (isset($taken[$code])) {
                    $result[$k] = self::randomCode($length, $used);
                }
            }
        }
        throw new RuntimeException('تعذّر تخصيص أرقام مختصرة فريدة.');
    }

    /**
     * تخصيص أرقام مختصرة لكروت موجودة.
     * @return int عدد الكروت التي حصلت على رقم
     */
    public static function assign(array $cardIds, int $length, string $mode, bool $overwrite, ?int $start = null, int $passwordLength = 0): int
    {
        self::checkPasswordLength($passwordLength);
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        if (!$cardIds) {
            return 0;
        }
        @set_time_limit(300);
        $cards = [];
        foreach (array_chunk($cardIds, 1000) as $chunk) {
            $sql = 'SELECT id, username, password, short_code FROM cards WHERE id IN (' . DB::in($chunk) . ')' . ($overwrite ? '' : ' AND short_code IS NULL') . ' ORDER BY id';
            foreach (DB::rows($sql, $chunk) as $c) {
                $cards[(int) $c['id']] = $c;
            }
        }
        if (!$cards) {
            return 0;
        }
        return DB::transaction(static function () use ($cards, $length, $mode, $start, $passwordLength) {
            // تحرير الأرقام القديمة أولاً كي يمكن إعادة استخدامها
            $ids = array_keys($cards);
            foreach (array_chunk($ids, 1000) as $chunk) {
                DB::query("DELETE FROM logins WHERE owner_type = 'card_short' AND owner_id IN (" . DB::in($chunk) . ')', $chunk);
                DB::query('UPDATE cards SET short_code = NULL, short_password = NULL WHERE id IN (' . DB::in($chunk) . ')', $chunk);
            }
            $usernames = $originals = [];
            foreach ($cards as $id => $c) {
                $usernames[$id] = $c['username'];
                $originals[$id] = $c['password'];
            }
            $codes = self::allocate($usernames, $length, $mode, [], $start);
            $passwords = self::passwords($originals, $passwordLength, $mode);
            $logins = [];
            foreach ($codes as $id => $code) {
                DB::update('cards', ['short_code' => $code, 'short_password' => $passwords[$id] ?? null, 'updated_at' => now()], 'id = ?', [$id]);
                $logins[] = ['login' => $code, 'owner_type' => 'card_short', 'owner_id' => $id];
            }
            LoginRegistry::addMany($logins);
            Audit::log('cards.short', 'تخصيص ' . count($codes) . ' رقماً مختصراً بطول ' . $length . ($passwordLength ? ' وكلمة مرور بطول ' . $passwordLength : ''), 'card', null, ['mode' => $mode]);
            return count($codes);
        });
    }

    /** إزالة الأرقام المختصرة */
    public static function clear(array $cardIds): int
    {
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        $n = 0;
        foreach (array_chunk($cardIds, 1000) as $chunk) {
            DB::query("DELETE FROM logins WHERE owner_type = 'card_short' AND owner_id IN (" . DB::in($chunk) . ')', $chunk);
            $n += DB::query('UPDATE cards SET short_code = NULL, short_password = NULL WHERE short_code IS NOT NULL AND id IN (' . DB::in($chunk) . ')', $chunk)->rowCount();
        }
        Audit::log('cards.short', 'إزالة ' . $n . ' رقماً مختصراً', 'card');
        return $n;
    }
}
