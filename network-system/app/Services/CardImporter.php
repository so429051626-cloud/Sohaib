<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Support\Spreadsheet;
use RuntimeException;

/**
 * استيراد الكروت من ملفات Excel/CSV/نص أو لصق مباشر، على مرحلتين:
 * قراءة ومعاينة، ثم اعتماد بتحديد الأعمدة.
 */
final class CardImporter
{
    public const MAX_ROWS = 50000;

    private static function dir(): string
    {
        $dir = storage_path('imports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** قراءة المصدر وحفظه مؤقتاً؛ يعيد رمز الجلسة */
    public static function stage(?array $file, string $paste): string
    {
        if ($file) {
            if ($file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('حجم الملف يتجاوز 10 ميغابايت.');
            }
            $rows = Spreadsheet::read($file['tmp_name'], (string) $file['name']);
        } elseif (trim($paste) !== '') {
            $rows = Spreadsheet::readDelimited($paste);
        } else {
            throw new RuntimeException('اختر ملفاً أو الصق البيانات.');
        }
        $rows = array_values(array_filter($rows, static fn ($r) => implode('', $r) !== ''));
        if (!$rows) {
            throw new RuntimeException('لم يتم العثور على بيانات في المصدر.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('الحد الأقصى ' . self::MAX_ROWS . ' صف في المرة الواحدة.');
        }
        $token = bin2hex(random_bytes(12));
        file_put_contents(self::dir() . '/' . $token . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $token;
    }

    public static function load(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            throw new RuntimeException('جلسة الاستيراد غير صالحة.');
        }
        $file = self::dir() . '/' . $token . '.json';
        if (!is_file($file)) {
            throw new RuntimeException('انتهت جلسة الاستيراد. أعد رفع الملف.');
        }
        return json_decode((string) file_get_contents($file), true) ?: [];
    }

    public static function discard(string $token): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $token)) {
            @unlink(self::dir() . '/' . $token . '.json');
        }
    }

    /** اقتراح الأعمدة: الأطول رقماً = اسم المستخدم، التالي = كلمة المرور */
    public static function guessColumns(array $rows): array
    {
        $sample = array_slice($rows, 0, 50);
        $cols = max(array_map('count', $sample));
        $score = [];
        for ($c = 0; $c < $cols; $c++) {
            $lens = [];
            foreach ($sample as $r) {
                $v = latin_digits((string) ($r[$c] ?? ''));
                if (preg_match('/^[A-Za-z0-9._@-]+$/', $v)) {
                    $lens[] = strlen($v);
                }
            }
            $score[$c] = $lens ? (count($lens) * 100 + array_sum($lens) / count($lens)) : 0;
        }
        arsort($score);
        $ranked = array_keys(array_filter($score));
        $username = $ranked[0] ?? 0;
        $password = $ranked[1] ?? -1;
        // اسم المستخدم هو الأطول عادةً
        if ($password >= 0) {
            $avg = static function (int $c) use ($sample): float {
                $l = array_map(static fn ($r) => strlen((string) ($r[$c] ?? '')), $sample);
                return $l ? array_sum($l) / count($l) : 0;
            };
            if ($avg($password) > $avg($username)) {
                [$username, $password] = [$password, $username];
            }
        }
        $header = false;
        $first = $rows[0] ?? [];
        if ($first && !preg_match('/\d/', (string) ($first[$username] ?? ''))) {
            $header = true;
        }
        return ['username' => $username, 'password' => $password, 'serial' => -1, 'header' => $header, 'columns' => $cols];
    }

    /**
     * اعتماد الاستيراد.
     * @return array{batch_id:int, imported:int, duplicates:int, existing:int, invalid:int}
     */
    public static function commit(string $token, array $o, ?int $employeeId): array
    {
        $rows = self::load($token);
        $category = DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [(int) $o['category_id']]);
        if (!$category) {
            throw new RuntimeException('اختر باقة صالحة.');
        }
        $uc = (int) $o['username_col'];
        $pc = (int) $o['password_col'];
        $sc = (int) ($o['serial_col'] ?? -1);
        if (!empty($o['skip_header'])) {
            array_shift($rows);
        }
        @set_time_limit(300);

        $items = [];
        $duplicates = $invalid = 0;
        foreach ($rows as $r) {
            $u = trim(latin_digits((string) ($r[$uc] ?? '')));
            $p = $pc >= 0 ? trim(latin_digits((string) ($r[$pc] ?? ''))) : $u;
            $s = $sc >= 0 ? trim((string) ($r[$sc] ?? '')) : null;
            if ($u === '' || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $u) || $p === '' || strlen($p) > 64 || preg_match('/[\s"\'\\\\]/', $p)) {
                $invalid++;
                continue;
            }
            if (isset($items[$u])) {
                $duplicates++;
                continue;
            }
            $items[$u] = ['password' => $p, 'serial' => $s !== '' ? mb_substr((string) $s, 0, 64) : null];
        }
        $taken = LoginRegistry::taken(array_keys($items));
        $existing = 0;
        foreach ($taken as $u => $_) {
            unset($items[$u]);
            $existing++;
        }
        if (!$items) {
            return ['batch_id' => 0, 'imported' => 0, 'duplicates' => $duplicates, 'existing' => $existing, 'invalid' => $invalid];
        }

        $usernames = array_keys($items);
        $shortCodes = [];
        $shortPassLen = !empty($o['short_length']) ? (int) ($o['short_password_length'] ?? 0) : 0;
        ShortCodes::checkPasswordLength($shortPassLen);
        if (!empty($o['short_length'])) {
            $shortCodes = ShortCodes::allocate($usernames, (int) $o['short_length'], (string) ($o['short_mode'] ?? 'random'), array_fill_keys($usernames, true), isset($o['short_start']) && $o['short_start'] !== '' ? (int) $o['short_start'] : null);
        }

        $result = DB::transaction(static function () use ($items, $usernames, $shortCodes, $shortPassLen, $category, $o, $employeeId, $duplicates, $existing, $invalid) {
            $now = now();
            $name = trim((string) ($o['batch_name'] ?? '')) ?: ('استيراد — ' . $category['name'] . ' — ' . date('Y-m-d H:i'));
            $batchId = DB::insert('batches', [
                'name' => mb_substr($name, 0, 120), 'category_id' => $category['id'], 'source' => 'imported',
                'quantity' => count($items),
                'options' => json_encode(['short_length' => (int) ($o['short_length'] ?? 0), 'short_mode' => $o['short_mode'] ?? null, 'short_password_length' => $shortPassLen], JSON_UNESCAPED_UNICODE),
                'notes' => $o['notes'] ?? null, 'created_by' => $employeeId, 'created_at' => $now,
            ]);
            foreach (array_chunk($usernames, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $i => $u) {
                    $rows[] = [
                        'batch_id' => $batchId, 'category_id' => $category['id'], 'username' => $u,
                        'password' => $items[$u]['password'], 'short_code' => $shortCodes[$i] ?? null,
                        'short_password' => isset($shortCodes[$i]) && $shortPassLen ? ShortCodes::passwords([$items[$u]['password']], $shortPassLen, (string) ($o['short_mode'] ?? 'random'))[0] : null,
                        'ext_serial' => $items[$u]['serial'], 'status' => 'new', 'created_at' => $now,
                    ];
                }
                DB::insertMany('cards', $rows);
                $first = (int) DB::pdo()->lastInsertId();
                $logins = [];
                foreach (DB::rows('SELECT id, username, short_code FROM cards WHERE batch_id = ? AND id >= ? ORDER BY id LIMIT ' . count($rows), [$batchId, $first]) as $c) {
                    $logins[] = ['login' => $c['username'], 'owner_type' => 'card', 'owner_id' => (int) $c['id']];
                    if ($c['short_code'] !== null) {
                        $logins[] = ['login' => $c['short_code'], 'owner_type' => 'card_short', 'owner_id' => (int) $c['id']];
                    }
                }
                LoginRegistry::addMany($logins);
            }
            Audit::log('cards.import', 'استيراد ' . count($items) . ' كرت إلى باقة ' . $category['name'], 'batch', $batchId, [
                'duplicates' => $duplicates, 'existing' => $existing, 'invalid' => $invalid,
            ]);
            return ['batch_id' => $batchId, 'imported' => count($items), 'duplicates' => $duplicates, 'existing' => $existing, 'invalid' => $invalid];
        });
        self::discard($token);
        return $result;
    }
}
