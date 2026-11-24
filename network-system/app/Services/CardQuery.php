<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * بناء شروط البحث عن الكروت (مشتركة بين القائمة والتصدير والطباعة والإجراءات الجماعية).
 */
final class CardQuery
{
    public const FILTERS = ['q', 'category', 'batch', 'status', 'distributor', 'printed', 'from', 'to', 'short'];

    public static function fromArray(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = trim(latin_digits((string) ($f['q'] ?? '')));
        if ($q !== '') {
            if (preg_match('/^#(\d+)$/', $q, $m)) {
                $where[] = 'c.id = ?';
                $params[] = (int) $m[1];
            } elseif (($mac = normalize_mac($q)) !== null) {
                $where[] = '(c.bound_mac = ? OR c.last_mac = ?)';
                array_push($params, $mac, $mac);
            } else {
                $where[] = '(c.username LIKE ? OR c.short_code = ? OR c.ext_serial = ? OR c.notes LIKE ?)';
                array_push($params, DB::like($q), $q, $q, DB::like($q));
            }
        }
        if (($f['category'] ?? '') === 'custom') {
            $where[] = 'c.category_id IN (SELECT id FROM categories WHERE is_custom = 1)';
        } elseif (!empty($f['category'])) {
            $where[] = 'c.category_id = ?';
            $params[] = (int) $f['category'];
        }
        if (!empty($f['batch'])) {
            $where[] = 'c.batch_id = ?';
            $params[] = (int) $f['batch'];
        }
        if (!empty($f['distributor'])) {
            $where[] = 'c.distributor_id = ?';
            $params[] = (int) $f['distributor'];
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'disabled') {
            $where[] = 'c.is_disabled = 1';
        } elseif ($status === 'available') {
            $where[] = "c.status = 'new' AND c.is_disabled = 0";
        } elseif ($status === 'ended') {
            $where[] = "c.status IN ('expired','depleted')";
        } elseif (isset(CardRules::STATUS[$status])) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        if (($f['printed'] ?? '') === 'yes') {
            $where[] = 'c.printed_at IS NOT NULL';
        } elseif (($f['printed'] ?? '') === 'no') {
            $where[] = 'c.printed_at IS NULL';
        }
        if (($f['short'] ?? '') === 'yes') {
            $where[] = 'c.short_code IS NOT NULL';
        } elseif (($f['short'] ?? '') === 'no') {
            $where[] = 'c.short_code IS NULL';
        }
        if (!empty($f['from']) && strtotime((string) $f['from'])) {
            $where[] = 'c.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', strtotime((string) $f['from']));
        }
        if (!empty($f['to']) && strtotime((string) $f['to'])) {
            $where[] = 'c.created_at <= ?';
            $params[] = date('Y-m-d 23:59:59', strtotime((string) $f['to']));
        }
        return [implode(' AND ', $where), $params];
    }

    /** معرّفات الكروت المطابقة (بحد أقصى) */
    public static function ids(array $filters, int $limit = 50000): array
    {
        [$where, $params] = self::fromArray($filters);
        return array_map('intval', DB::column("SELECT c.id FROM cards c WHERE $where ORDER BY c.id LIMIT " . (int) $limit, $params));
    }

    public static function exportRows(array $ids, bool $withPasswords): \Generator
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::rows(
                'SELECT c.*, cat.name AS category_name, b.name AS batch_name, d.name AS distributor_name
                 FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
                 LEFT JOIN distributors d ON d.id = c.distributor_id WHERE c.id IN (' . DB::in($chunk) . ') ORDER BY c.id',
                $chunk
            );
            foreach ($rows as $c) {
                yield [
                    $c['id'], $c['username'], $withPasswords ? $c['password'] : '', $c['short_code'] ?? '', $withPasswords ? ($c['short_password'] ?? '') : '', $c['ext_serial'] ?? '',
                    $c['category_name'], CardRules::STATUS[$c['status']][0] ?? $c['status'], $c['is_disabled'] ? 'موقوف' : '',
                    $c['batch_name'], $c['distributor_name'] ?? '', fmt_dt($c['created_at']), $c['first_login_at'] ? fmt_dt($c['first_login_at']) : '',
                    $c['expires_at'] ? fmt_dt($c['expires_at']) : '', (int) $c['used_seconds'] > 0 ? fmt_duration((int) $c['used_seconds'], true) : '',
                    CardRules::usedBytes($c) > 0 ? fmt_bytes(CardRules::usedBytes($c)) : '', $c['bound_mac'] ?? '', $c['printed_at'] ? 'نعم' : '',
                ];
            }
        }
    }

    public const EXPORT_HEADER = [
        'الرقم', 'اسم المستخدم', 'كلمة المرور', 'الرقم المختصر', 'كلمة المرور المختصرة', 'الرقم التسلسلي الخارجي', 'الباقة', 'الحالة', 'الإيقاف',
        'الدفعة', 'الموزع', 'تاريخ الإنشاء', 'أول دخول', 'الانتهاء', 'الوقت المستخدم', 'البيانات المستخدمة', 'الجهاز المرتبط', 'مطبوعة',
    ];
}
