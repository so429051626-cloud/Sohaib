<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use RuntimeException;

/**
 * الاستيراد الذكي للمشتركين (الميزة 5): تصوير دفتر المشتركين أو لصق قائمة من واتساب أو إكسل،
 * فيرتّبها النظام في جدول يراجعه المستخدم ويصحّحه، ولا يُحفظ شيء قبل موافقته.
 * مع الذكاء الاصطناعي تُقرأ الصور والقوائم غير المرتبة؛ ودونه يرتّب محلّل مدمج الجداول وأسطر «الاسم الهاتف».
 */
final class SmartImport
{
    public const FIELDS = ['name', 'phone', 'username', 'password', 'plan', 'expires', 'address', 'notes'];
    public const MAX_ROWS = 300;
    public const MAX_IMAGES = 3;
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** عناوين الأعمدة الشائعة بالعربية والإنجليزية */
    private const HEADERS = [
        'name'     => ['الاسم', 'اسم', 'الاسم الكامل', 'اسم المشترك', 'المشترك', 'name', 'full name', 'fullname', 'customer'],
        'phone'    => ['الهاتف', 'هاتف', 'الجوال', 'جوال', 'رقم الجوال', 'رقم الهاتف', 'الرقم', 'رقم', 'موبايل', 'phone', 'mobile', 'tel', 'number'],
        'username' => ['اسم المستخدم', 'اسم الدخول', 'المستخدم', 'يوزر', 'اليوزر', 'user', 'username', 'login'],
        'password' => ['كلمة المرور', 'كلمة السر', 'الباسورد', 'باسورد', 'الرمز السري', 'password', 'pass'],
        'plan'     => ['الباقة', 'باقة', 'الاشتراك', 'نوع الاشتراك', 'السرعة', 'plan', 'package', 'speed'],
        'expires'  => ['ينتهي', 'ينتهي في', 'الانتهاء', 'تاريخ الانتهاء', 'نهاية الاشتراك', 'expires', 'expiry', 'expire', 'end'],
        'address'  => ['العنوان', 'المنطقة', 'الحي', 'address', 'area'],
        'notes'    => ['ملاحظات', 'ملاحظة', 'notes', 'note'],
    ];

    // ------------------------------------------------------------------
    // الترتيب في جدول
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{mime:string, data:string}> $images صور بصيغة base64
     * @return array{rows: list<array>, source: string, message: string}
     */
    public static function parse(string $text, array $images = []): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", latin_digits($text)));
        if ($text === '' && !$images) {
            throw new RuntimeException('الصق القائمة أو أضف صورة الدفتر.');
        }
        if (Ai::available()) {
            $r = self::withAi($text, $images);
            if ($r !== null) {
                return ['rows' => $r, 'source' => 'ai', 'message' => ''];
            }
            if ($images && $text === '') {
                throw new RuntimeException('تعذّرت قراءة الصورة الآن. حاول مجدداً أو الصق القائمة نصاً.');
            }
        } elseif ($images) {
            throw new RuntimeException('قراءة الصور تحتاج الذكاء الاصطناعي: ' . Ai::stateLabel(Ai::status()));
        }
        return ['rows' => self::parseText($text), 'source' => 'text', 'message' => ''];
    }

    /** المحلّل المدمج: جدول بعناوين (إكسل، CSV) أو أسطر حرة فيها اسم وهاتف وتاريخ */
    public static function parseText(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", latin_digits($text))), static fn ($l) => $l !== ''));
        if (!$lines) {
            return [];
        }
        $delim = self::delimiter($lines);
        $rows = [];
        if ($delim !== null) {
            $first = array_map('trim', self::split($lines[0], $delim));
            $map = self::headerMap($first);
            if ($map) {
                array_shift($lines);
            }
            foreach ($lines as $line) {
                $cells = array_map('trim', self::split($line, $delim));
                if (!array_filter($cells, static fn ($c) => $c !== '')) {
                    continue;
                }
                $row = $map ? array_fill_keys(self::FIELDS, '') : self::guess(implode(' ', $cells));
                foreach ($map as $i => $field) {
                    $row[$field] = (string) ($cells[$i] ?? '');
                }
                $rows[] = self::clean($row);
            }
        } else {
            foreach ($lines as $line) {
                $row = self::guess($line);
                if ($row['name'] !== '' || $row['phone'] !== '') {
                    $rows[] = self::clean($row);
                }
            }
        }
        return array_slice(array_values(array_filter($rows, static fn ($r) => $r['name'] !== '' || $r['phone'] !== '' || $r['username'] !== '')), 0, self::MAX_ROWS);
    }

    private static function delimiter(array $lines): ?string
    {
        $sample = array_slice($lines, 0, 8);
        foreach (["\t", ';', '|', ','] as $d) {
            // عدد الحقول في كل سطر (مع احترام علامات الاقتباس في CSV)
            $counts = array_map(static fn ($l) => count(self::split($l, $d)) - 1, $sample);
            if (min($counts) >= 1 && (count(array_unique($counts)) === 1 || $d === "\t")) {
                return $d;
            }
        }
        return null;
    }

    private static function split(string $line, string $delim): array
    {
        return $delim === ',' || $delim === ';' ? str_getcsv($line, $delim, '"', '\\') : explode($delim, $line);
    }

    /** @return array<int,string> رقم العمود => الحقل، أو [] إن لم يكن السطر عناوين */
    private static function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $c = mb_strtolower(trim($cell, " :.*#\t"));
            foreach (self::HEADERS as $field => $names) {
                if (in_array($c, $names, true) && !in_array($field, $map, true)) {
                    $map[$i] = $field;
                    break;
                }
            }
        }
        return count($map) >= 1 && (in_array('name', $map, true) || in_array('phone', $map, true) || in_array('username', $map, true)) ? $map : [];
    }

    /** سطر حر: «1- أحمد محمد 0599123456 ينتهي 2026-12-01» */
    private static function guess(string $line): array
    {
        $row = array_fill_keys(self::FIELDS, '');
        $line = (string) preg_replace('/^\s*(?:\d{1,4}\s*[-.)]\s*|[-•*]\s*)/u', '', $line);
        if (preg_match('/(?:\+|00)?(?:970|972)?[\s-]?0?5\d(?:[\s-]?\d){7}/u', $line, $m)) {
            $row['phone'] = $m[0];
            $line = str_replace($m[0], ' ', $line);
        }
        if (preg_match('/\b(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})\b/u', $line, $m)) {
            $row['expires'] = $m[1];
            $line = str_replace($m[0], ' ', $line);
        }
        $line = (string) preg_replace(['/(ينتهي في|ينتهي|الانتهاء|هاتف|جوال|رقم)\s*[:：]?/u', '/[|،,;:]+/u', '/\s+/u'], [' ', ' ', ' '], $line);
        $row['name'] = trim($line, " -–_.\t");
        return $row;
    }

    /** تنظيف صف: أرقام إنجليزية، هاتف بصيغة 05xxxxxxxx، تاريخ Y-m-d، قص الأطوال */
    public static function clean(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($row[$f] ?? ''));
            $out[$f] = $f === 'name' || $f === 'address' || $f === 'notes' || $f === 'plan' ? $v : latin_digits($v);
        }
        $out['phone'] = self::phone($out['phone']);
        $out['expires'] = self::date($out['expires']) ?? $out['expires'];
        $out['name'] = mb_substr((string) preg_replace('/\s+/u', ' ', $out['name']), 0, 120);
        foreach (['username' => 64, 'password' => 64, 'plan' => 100, 'address' => 255, 'notes' => 500] as $f => $max) {
            $out[$f] = mb_substr($out[$f], 0, $max);
        }
        return $out;
    }

    public static function phone(string $raw): string
    {
        $d = (string) preg_replace('/\D+/', '', latin_digits($raw));
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (preg_match('/^(?:970|972)(5\d{8})$/', $d, $m)) {
            return '0' . $m[1];
        }
        if (preg_match('/^5\d{8}$/', $d)) {
            return '0' . $d;
        }
        return $d;
    }

    /** تاريخ بصيغ شائعة → Y-m-d، وغير الصالح null */
    public static function date(string $raw): ?string
    {
        $s = trim(latin_digits($raw));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** @return list<array>|null الصفوف من الذكاء الاصطناعي، أو null إن تعذّر */
    private static function withAi(string $text, array $images): ?array
    {
        $plans = DB::column('SELECT name FROM plans WHERE is_active = 1 ORDER BY sort, price LIMIT 40');
        $system = 'أنت تحوّل قوائم المشتركين لشبكة إنترنت إلى جدول. المدخل نص ملصوق من واتساب أو إكسل أو صورة دفتر مكتوب بخط اليد. '
            . 'أعد JSON فقط بلا أي نص آخر وبلا علامات تنسيق، بهذه الصيغة: {"rows":[{"name":"","phone":"","username":"","password":"","plan":"","expires":"","address":"","notes":""}]}. '
            . 'صف لكل مشترك. الأرقام بالإنجليزية. التاريخ بصيغة YYYY-MM-DD. اترك الحقل فارغاً إن لم يُذكر، ولا تخترع بيانات. '
            . 'تجاهل العناوين والمجاميع والأسطر التي ليست مشتركين. أقصى عدد ' . self::MAX_ROWS . ' صفاً.'
            . ($plans ? ' الباقات المعروفة: ' . implode('، ', $plans) . '؛ إن ذُكرت باقة تشبه إحداها فاكتب اسمها كما هو هنا.' : '');
        $content = [];
        foreach (array_slice($images, 0, self::MAX_IMAGES) as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $text !== '' ? 'القائمة:' . "\n" . mb_substr($text, 0, 30000) : 'استخرج المشتركين من الصورة.'];
        $r = Ai::ask('smart_import', $system, [['role' => 'user', 'content' => $content]], ['effort' => 'low', 'max_tokens' => 16000, 'timeout' => 150, 'actor' => (string) (Auth::user()['name'] ?? '')]);
        if (!$r['ok']) {
            return null;
        }
        $json = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $r['text']));
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($json, $start, $end - $start + 1), true) : null;
        if (!is_array($data) || !is_array($data['rows'] ?? null)) {
            return null;
        }
        $rows = [];
        foreach (array_slice($data['rows'], 0, self::MAX_ROWS) as $row) {
            if (is_array($row)) {
                $c = self::clean(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $row));
                if ($c['name'] !== '' || $c['phone'] !== '' || $c['username'] !== '') {
                    $rows[] = $c;
                }
            }
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // المراجعة والحفظ
    // ------------------------------------------------------------------

    /** الباقة بالاسم (مطابقة تامة ثم جزئية) أو بالرقم، وإلا الافتراضية */
    public static function planId(string $name, int $default): int
    {
        $name = trim($name);
        if ($name !== '') {
            $id = (int) DB::value('SELECT id FROM plans WHERE is_active = 1 AND (name = ? OR id = ?) ORDER BY name = ? DESC LIMIT 1', [$name, ctype_digit($name) ? (int) $name : 0, $name]);
            if (!$id) {
                $id = (int) DB::value('SELECT id FROM plans WHERE is_active = 1 AND (name LIKE ? OR ? LIKE CONCAT(\'%\', name, \'%\')) ORDER BY LENGTH(name) DESC LIMIT 1', [DB::like($name), $name]);
            }
            if ($id) {
                return $id;
            }
        }
        return $default;
    }

    /**
     * التحقق من كل الصفوف قبل الحفظ.
     * @return array<int, array<string,string>> رقم الصف => [الحقل => الخطأ]
     */
    public static function validate(array $rows, int $defaultPlan): array
    {
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $e = [];
            if ($r['name'] === '') {
                $e['name'] = 'الاسم مطلوب.';
            }
            if ($r['phone'] !== '' && !preg_match('/^\+?\d{7,15}$/', $r['phone'])) {
                $e['phone'] = 'رقم غير صالح.';
            }
            if ($r['username'] !== '') {
                if (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $r['username'])) {
                    $e['username'] = 'أحرف إنجليزية وأرقام و . _ - @ فقط.';
                } elseif (isset($seen[mb_strtolower($r['username'])])) {
                    $e['username'] = 'مكرر في الجدول.';
                } elseif (LoginRegistry::isTaken($r['username'])) {
                    $e['username'] = 'مستخدم لكرت أو مشترك آخر.';
                }
                $seen[mb_strtolower($r['username'])] = true;
            }
            if ($r['password'] !== '' && !preg_match('/^[\x21-\x7E]{1,64}$/', $r['password'])) {
                $e['password'] = 'أحرف إنجليزية وأرقام ورموز فقط.';
            }
            if (!self::planId($r['plan'], $defaultPlan)) {
                $e['plan'] = 'اختر الباقة.';
            }
            if ($r['expires'] !== '' && self::date($r['expires']) === null) {
                $e['expires'] = 'تاريخ غير صالح.';
            }
            if ($e) {
                $errors[$i] = $e;
            }
        }
        return $errors;
    }

    /**
     * حفظ الصفوف بعد موافقة المستخدم (كلها أو لا شيء). من له تاريخ انتهاء يُفعَّل حتى نهايته.
     * @return list<int> أرقام المشتركين الجدد
     */
    public static function save(array $rows, int $defaultPlan, ?int $routerId): array
    {
        $prefix = (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) Settings::get('subscribers.username_prefix', ''));
        $ids = DB::transaction(static function () use ($rows, $defaultPlan, $routerId, $prefix): array {
            $ids = [];
            foreach ($rows as $r) {
                $username = $r['username'];
                for ($i = 0; $username === '' && $i < 60; $i++) {
                    $candidate = $prefix . random_int(100000, 999999);
                    $username = LoginRegistry::isTaken($candidate) ? '' : $candidate;
                }
                if ($username === '') {
                    throw new RuntimeException('تعذّر توليد اسم مستخدم فريد.');
                }
                $expires = self::date($r['expires']);
                $id = DB::insert('subscribers', [
                    'full_name' => $r['name'], 'phone' => $r['phone'] !== '' ? $r['phone'] : null, 'address' => $r['address'] !== '' ? $r['address'] : null,
                    'notes' => $r['notes'] !== '' ? $r['notes'] : null, 'username' => $username, 'password' => $r['password'] !== '' ? $r['password'] : (string) random_int(10000000, 99999999),
                    'plan_id' => self::planId($r['plan'], $defaultPlan), 'router_id' => $routerId, 'expires_at' => $expires !== null ? $expires . ' 23:59:59' : null,
                    'period_started_at' => $expires !== null ? now() : null, 'status' => 'active', 'created_by' => Auth::id(), 'created_at' => now(),
                ]);
                LoginRegistry::add($username, 'subscriber', $id);
                $ids[] = $id;
            }
            return $ids;
        });
        // التطبيق على جهاز MikroTik في الخلفية (لا ينتظر الاستيراد اتصال الجهاز)
        if ($routerId) {
            foreach ($ids as $id) {
                RouterTasks::queue($routerId, 'sub_provision', ['subscriber_id' => $id], 'sub:' . $id);
            }
        }
        Audit::log('subscriber.import', 'استيراد ذكي: ' . ar_plural(count($ids), 'مشترك واحد', 'مشتركان', 'مشتركين', 'مشتركاً'), 'subscriber', null, ['count' => count($ids)]);
        return $ids;
    }
}
