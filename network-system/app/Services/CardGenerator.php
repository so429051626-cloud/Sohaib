<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use RuntimeException;

/**
 * توليد الكروت بأرقام فريدة غير قابلة للتخمين (CSPRNG).
 */
final class CardGenerator
{
    /** الأحرف المتشابهة (0/O، 1/l/I) مستبعدة من مجموعات الأحرف */
    public const CHARSETS = [
        'digits'      => ['label' => 'أرقام فقط', 'chars' => '0123456789'],
        'lower'       => ['label' => 'أحرف إنجليزية صغيرة', 'chars' => 'abcdefghjkmnpqrstuvwxyz'],
        'upper'       => ['label' => 'أحرف إنجليزية كبيرة', 'chars' => 'ABCDEFGHJKMNPQRSTUVWXYZ'],
        'alnum_lower' => ['label' => 'أرقام وأحرف صغيرة', 'chars' => '23456789abcdefghjkmnpqrstuvwxyz'],
        'alnum'       => ['label' => 'أرقام وأحرف صغيرة وكبيرة', 'chars' => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'],
    ];

    public const MAX_QUANTITY = 20000;

    public static function code(int $length, string $charset): string
    {
        $chars = self::CHARSETS[$charset]['chars'] ?? self::CHARSETS['digits']['chars'];
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            // الرقم الأول لا يكون صفراً كي لا يُحذف عند فتح الملفات في Excel
            $c = $chars[random_int(0, $max)];
            if ($i === 0 && $charset === 'digits' && $c === '0') {
                $c = (string) random_int(1, 9);
            }
            $out .= $c;
        }
        return $out;
    }

    /** عدد الرموز الممكنة لطول ونوع */
    public static function space(int $length, string $charset): float
    {
        $n = strlen(self::CHARSETS[$charset]['chars'] ?? '0123456789');
        return $charset === 'digits' ? 9 * (10 ** max(0, $length - 1)) : $n ** $length;
    }

    /**
     * توليد رموز فريدة غير محجوزة.
     * @param array $exclude قيم إضافية ممنوعة (مفاتيح المصفوفة)
     */
    public static function uniqueCodes(int $count, int $length, string $charset, string $prefix = '', array $exclude = []): array
    {
        $space = self::space($length, $charset);
        $existing = (int) DB::value('SELECT COUNT(*) FROM logins');
        if ($space < ($count + $existing) * 3) {
            throw new RuntimeException('طول الرمز لا يكفي لتوليد هذا العدد دون تكرار. زد الطول أو غيّر نوع الرموز.');
        }
        $codes = [];
        $guard = 0;
        while (count($codes) < $count) {
            $need = $count - count($codes);
            $batch = [];
            while (count($batch) < min($need * 2, $need + 2000)) {
                $c = $prefix . self::code($length, $charset);
                if (!isset($codes[$c]) && !isset($batch[$c]) && !isset($exclude[$c])) {
                    $batch[$c] = true;
                }
            }
            $taken = LoginRegistry::taken(array_keys($batch));
            foreach ($batch as $c => $_) {
                if (!isset($taken[$c])) {
                    $codes[$c] = true;
                    if (count($codes) >= $count) {
                        break;
                    }
                }
            }
            if (++$guard > 50) {
                throw new RuntimeException('تعذّر توليد رموز فريدة كافية. زد طول الرمز.');
            }
        }
        return array_keys($codes);
    }

    /**
     * توليد دفعة كروت.
     * @return array{batch_id:int, count:int}
     */
    public static function generate(array $o, ?int $employeeId): array
    {
        $category = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [(int) $o['category_id']]);
        if (!$category) {
            throw new RuntimeException('الباقة غير موجودة.');
        }
        $qty = (int) $o['quantity'];
        if ($qty < 1 || $qty > self::MAX_QUANTITY) {
            throw new RuntimeException('العدد يجب أن يكون بين 1 و' . self::MAX_QUANTITY . '.');
        }
        @set_time_limit(300);
        $prefix = (string) ($o['prefix'] ?? '');
        $usernames = self::uniqueCodes($qty, (int) $o['username_length'], (string) $o['charset'], $prefix);
        $samePassword = ($o['password_mode'] ?? 'same') === 'same';
        $shortCodes = [];
        $shortPassLen = !empty($o['short_length']) ? (int) ($o['short_password_length'] ?? 0) : 0;
        ShortCodes::checkPasswordLength($shortPassLen);
        if (!empty($o['short_length'])) {
            $exclude = array_fill_keys($usernames, true);
            $shortCodes = ShortCodes::allocate($usernames, (int) $o['short_length'], (string) ($o['short_mode'] ?? 'random'), $exclude);
        }

        return DB::transaction(static function () use ($o, $category, $qty, $usernames, $samePassword, $shortCodes, $shortPassLen, $employeeId) {
            $now = now();
            $name = trim((string) ($o['batch_name'] ?? '')) ?: ($category['name'] . ' — ' . date('Y-m-d H:i'));
            $batchId = DB::insert('batches', [
                'name'        => mb_substr($name, 0, 120),
                'category_id' => $category['id'],
                'source'      => 'generated',
                'quantity'    => $qty,
                'options'     => json_encode([
                    'username_length' => (int) $o['username_length'], 'charset' => $o['charset'], 'prefix' => $o['prefix'] ?? '',
                    'password_mode' => $o['password_mode'] ?? 'same', 'password_length' => (int) ($o['password_length'] ?? 0),
                    'short_length' => (int) ($o['short_length'] ?? 0), 'short_mode' => $o['short_mode'] ?? null, 'short_password_length' => $shortPassLen,
                ], JSON_UNESCAPED_UNICODE),
                'notes'       => $o['notes'] ?? null,
                'created_by'  => $employeeId,
                'created_at'  => $now,
            ]);
            $firstId = null;
            foreach (array_chunk($usernames, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $i => $u) {
                    $password = $samePassword ? $u : self::code(max(3, (int) $o['password_length']), 'digits');
                    $rows[] = [
                        'batch_id'    => $batchId,
                        'category_id' => $category['id'],
                        'username'    => $u,
                        'password'    => $password,
                        'short_code'  => $shortCodes[$i] ?? null,
                        'short_password' => isset($shortCodes[$i]) && $shortPassLen ? ShortCodes::passwords([$password], $shortPassLen, (string) ($o['short_mode'] ?? 'random'))[0] : null,
                        'status'      => 'new',
                        'created_at'  => $now,
                    ];
                }
                DB::insertMany('cards', $rows);
                $first = (int) DB::pdo()->lastInsertId();
                $firstId ??= $first;
                // MySQL يعيد معرّف أول صف في الإدراج المتعدد
                $ids = DB::rows('SELECT id, username, short_code FROM cards WHERE batch_id = ? AND id >= ? ORDER BY id LIMIT ' . count($rows), [$batchId, $first]);
                $logins = [];
                foreach ($ids as $c) {
                    $logins[] = ['login' => $c['username'], 'owner_type' => 'card', 'owner_id' => (int) $c['id']];
                    if ($c['short_code'] !== null) {
                        $logins[] = ['login' => $c['short_code'], 'owner_type' => 'card_short', 'owner_id' => (int) $c['id']];
                    }
                }
                LoginRegistry::addMany($logins);
            }
            Audit::log('cards.generate', 'توليد ' . $qty . ' كرت من باقة ' . $category['name'], 'batch', $batchId, ['quantity' => $qty]);
            return ['batch_id' => $batchId, 'count' => $qty];
        });
    }
}
