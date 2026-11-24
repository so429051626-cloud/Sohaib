<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;

/**
 * قوالب طباعة الكروت وبيانات العناصر.
 */
final class PrintTemplates
{
    public const PAPERS = [
        'A4'     => ['A4', 210, 297],
        'A5'     => ['A5', 148, 210],
        'A3'     => ['A3', 297, 420],
        'Letter' => ['Letter', 215.9, 279.4],
        'custom' => ['مقاس مخصص', 0, 0],
    ];

    /** المتغيرات المتاحة داخل نصوص الكرت */
    public const FIELDS = [
        'username' => 'رقم الكرت',
        'password' => 'كلمة المرور',
        'short'    => 'الرقم المختصر',
        'short_password' => 'كلمة المرور المختصرة',
        'serial'   => 'الرقم التسلسلي',
        'ext'      => 'الرقم الخارجي',
        'price'    => 'السعر',
        'category' => 'الباقة',
        'time'     => 'مدة الاستخدام',
        'validity' => 'الصلاحية',
        'data'     => 'البيانات',
        'speed'    => 'السرعة',
        'network'  => 'اسم الشبكة',
        'phone'    => 'هاتف الشبكة',
        'batch'    => 'اسم الدفعة',
    ];

    public static function defaultDesign(): array
    {
        return [
            'card' => ['bg' => '#ffffff', 'image' => null, 'border' => true, 'borderColor' => '#94a3b8', 'radius' => 2, 'cutMarks' => false],
            'elements' => [
                ['id' => 'e1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 26, 'bg' => '#2453d6', 'radius' => 0],
                ['id' => 'e2', 'type' => 'text', 'text' => '{network}', 'x' => 40, 'y' => 3, 'w' => 56, 'size' => 7, 'weight' => 700, 'color' => '#ffffff', 'align' => 'right', 'font' => 'sans'],
                ['id' => 'e3', 'type' => 'text', 'text' => '{price}', 'x' => 4, 'y' => 3, 'w' => 34, 'size' => 7, 'weight' => 700, 'color' => '#ffffff', 'align' => 'left', 'font' => 'sans'],
                ['id' => 'e4', 'type' => 'text', 'text' => '{username}', 'x' => 4, 'y' => 36, 'w' => 92, 'size' => 13, 'weight' => 700, 'color' => '#0f172a', 'align' => 'center', 'font' => 'mono', 'ls' => 1],
                ['id' => 'e5', 'type' => 'text', 'text' => '{category} · {validity}', 'x' => 4, 'y' => 72, 'w' => 92, 'size' => 6, 'weight' => 500, 'color' => '#475569', 'align' => 'center', 'font' => 'sans'],
            ],
        ];
    }

    /** إنشاء القالب الأساسي عند عدم وجود قوالب */
    public static function ensureDefault(): void
    {
        if ((int) DB::value('SELECT COUNT(*) FROM print_templates') > 0) {
            return;
        }
        DB::insert('print_templates', [
            'name' => 'القالب الأساسي', 'paper' => 'A4', 'paper_width' => 210, 'paper_height' => 297,
            'cols' => 4, 'rows' => 10, 'margin_top' => 8, 'margin_right' => 8, 'margin_bottom' => 8, 'margin_left' => 8,
            'gap_x' => 2, 'gap_y' => 2, 'design' => json_encode(self::defaultDesign(), JSON_UNESCAPED_UNICODE),
            'is_default' => 1, 'created_at' => now(),
        ]);
    }

    /** تنظيف تصميم وارد من المتصفح */
    public static function sanitizeDesign(mixed $design): array
    {
        $d = is_array($design) ? $design : [];
        $card = is_array($d['card'] ?? null) ? $d['card'] : [];
        $color = static fn ($v, $def) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $def;
        $image = static fn ($v) => is_string($v) && preg_match('#^uploads/(t/[a-z0-9-]+/)?[A-Za-z0-9._-]+$#', $v) ? $v : null;
        $num = static fn ($v, float $min, float $max, float $def) => is_numeric($v) ? max($min, min($max, (float) $v)) : $def;
        $out = [
            'card' => [
                'bg' => $color($card['bg'] ?? null, '#ffffff'),
                'image' => $image($card['image'] ?? null),
                'border' => !empty($card['border']),
                'borderColor' => $color($card['borderColor'] ?? null, '#94a3b8'),
                'radius' => $num($card['radius'] ?? 0, 0, 20, 0),
                'cutMarks' => !empty($card['cutMarks']),
            ],
            'elements' => [],
        ];
        foreach (array_slice((array) ($d['elements'] ?? []), 0, 60) as $i => $e) {
            if (!is_array($e) || !in_array($e['type'] ?? '', ['text', 'qr', 'image', 'logo', 'rect'], true)) {
                continue;
            }
            $el = [
                'id' => is_string($e['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', $e['id']) ? $e['id'] : 'e' . ($i + 1),
                'type' => $e['type'],
                'x' => $num($e['x'] ?? 0, -50, 150, 0),
                'y' => $num($e['y'] ?? 0, -50, 150, 0),
                'w' => $num($e['w'] ?? 50, 1, 200, 50),
            ];
            switch ($e['type']) {
                case 'text':
                    $el += [
                        'text' => mb_substr((string) ($e['text'] ?? ''), 0, 300),
                        'size' => $num($e['size'] ?? 9, 3, 72, 9),
                        'weight' => in_array((int) ($e['weight'] ?? 400), [400, 500, 600, 700], true) ? (int) $e['weight'] : 400,
                        'color' => $color($e['color'] ?? null, '#111111'),
                        'align' => in_array($e['align'] ?? '', ['right', 'center', 'left'], true) ? $e['align'] : 'center',
                        'font' => ($e['font'] ?? '') === 'mono' ? 'mono' : 'sans',
                        'ls' => $num($e['ls'] ?? 0, -2, 10, 0),
                        'bg' => isset($e['bg']) && $e['bg'] !== '' ? $color($e['bg'], '') : '',
                        'radius' => $num($e['radius'] ?? 0, 0, 20, 0),
                        'fit' => !array_key_exists('fit', $e) || !empty($e['fit']),
                    ];
                    break;
                case 'qr':
                    $el += ['data' => mb_substr((string) ($e['data'] ?? '{username}'), 0, 300), 'color' => $color($e['color'] ?? null, '#000000')];
                    break;
                case 'image':
                    $el += ['src' => $image($e['src'] ?? null)];
                    break;
                case 'logo':
                    break;
                case 'rect':
                    $el += ['h' => $num($e['h'] ?? 20, 0.5, 200, 20), 'bg' => $color($e['bg'] ?? null, '#e2e8f0'), 'radius' => $num($e['radius'] ?? 0, 0, 50, 0)];
                    break;
            }
            $out['elements'][] = $el;
        }
        return $out;
    }

    /** بيانات الكروت للعرض والطباعة */
    public static function cardData(array $ids): array
    {
        $network = Settings::networkName();
        $phone = (string) Settings::get('general.phone', '');
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::rows(
                'SELECT c.id, c.username, c.password, c.short_code, c.short_password, c.ext_serial, cat.name AS category, cat.price, cat.time_limit, cat.validity, cat.data_limit,
                        cat.rate_up, cat.rate_down, b.name AS batch
                 FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
                 WHERE c.id IN (' . DB::in($chunk) . ') ORDER BY c.id',
                $chunk
            );
            foreach ($rows as $r) {
                $out[] = [
                    'username' => $r['username'],
                    'password' => $r['password'],
                    'short' => $r['short_code'] ?? '',
                    'short_password' => $r['short_password'] ?? '',
                    'serial' => str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT),
                    'ext' => $r['ext_serial'] ?? '',
                    'price' => money($r['price']),
                    'category' => $r['category'],
                    'time' => Units::describeSeconds($r['time_limit'] !== null ? (int) $r['time_limit'] : null),
                    'validity' => Units::describeSeconds($r['validity'] !== null ? (int) $r['validity'] : null),
                    'data' => Units::describeBytes($r['data_limit'] !== null ? (int) $r['data_limit'] : null),
                    'speed' => Units::describeRate($r),
                    'network' => $network,
                    'phone' => $phone,
                    'batch' => $r['batch'],
                ];
            }
        }
        return $out;
    }

    public static function sample(): array
    {
        $id = DB::value('SELECT id FROM cards ORDER BY id DESC LIMIT 1');
        if ($id) {
            return self::cardData([(int) $id])[0];
        }
        return [
            'username' => '83920571', 'password' => '83920571', 'short' => '40271', 'short_password' => '615', 'serial' => '000001', 'ext' => '',
            'price' => money(5), 'category' => 'باقة يوم', 'time' => 'غير محدود', 'validity' => 'يوم', 'data' => '5 GB',
            'speed' => '6 Mbps / 2 Mbps', 'network' => Settings::networkName(), 'phone' => (string) Settings::get('general.phone', ''), 'batch' => '',
        ];
    }
}
