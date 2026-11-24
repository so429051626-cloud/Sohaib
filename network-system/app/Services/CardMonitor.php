<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;

/**
 * متابعة الكروت: النشطة، والمنتهية، والأرقام المُدخلة خطأ في صفحة الهوتسبوت، والبحث عن كرت وتفاصيله.
 */
final class CardMonitor
{
    public const TABS = ['active' => 'النشطة', 'ended' => 'المنتهية', 'wrong' => 'المُدخلة خطأ'];

    /** فلاتر كل تبويب */
    public const FILTERS = [
        'active' => ['q', 'category', 'online', 'sort'],
        'ended'  => ['q', 'category', 'status', 'from', 'to'],
        'wrong'  => ['q', 'reason', 'router', 'from', 'to'],
    ];

    /** حالات الكرت المنتهي */
    public const ENDED = ['expired', 'depleted'];

    /** أسباب الرفض التي تعني أن الرقم أو كلمة المرور أُدخلا خطأ */
    public const WRONG_REASONS = ['not_found', 'wrong_password'];

    public const SORTS = [
        'recent'   => 'آخر دخول',
        'expiring' => 'الأقرب انتهاءً',
        'first'    => 'أول دخول',
    ];

    // ---------------------------------------------------------------------
    // شروط البحث
    // ---------------------------------------------------------------------

    /** البحث النصي في الكروت: #الرقم، MAC، IP، رقم الكرت، المختصر، الرقم التسلسلي، الملاحظات */
    private static function search(string $q, array &$where, array &$params): void
    {
        $q = trim(latin_digits($q));
        if ($q === '') {
            return;
        }
        if (preg_match('/^#(\d{1,10})$/', $q, $m)) {
            $where[] = 'c.id = ?';
            $params[] = (int) $m[1];
        } elseif (($mac = normalize_mac($q)) !== null) {
            $where[] = '(c.bound_mac = ? OR c.last_mac = ?)';
            array_push($params, $mac, $mac);
        } elseif (is_valid_ip($q)) {
            $where[] = 'c.last_ip = ?';
            $params[] = $q;
        } else {
            $where[] = '(c.username LIKE ? OR c.short_code = ? OR c.ext_serial = ? OR c.notes LIKE ?)';
            array_push($params, DB::like($q), $q, $q, DB::like($q));
        }
    }

    private static function category(array $f, array &$where, array &$params): void
    {
        $cat = (string) ($f['category'] ?? '');
        if ($cat !== '' && ctype_digit($cat)) {
            $where[] = 'c.category_id = ?';
            $params[] = (int) $cat;
        }
    }

    private static function dates(array $f, string $column, array &$where, array &$params): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? ''))) {
            $where[] = $column . ' >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? ''))) {
            $where[] = $column . ' <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
    }

    /** الكروت النشطة: بدأ استخدامها وما زالت صالحة وغير موقوفة */
    public static function activeWhere(array $f): array
    {
        $where = ["c.status = 'active'", 'c.is_disabled = 0'];
        $params = [];
        self::search((string) ($f['q'] ?? ''), $where, $params);
        self::category($f, $where, $params);
        $open = 'SELECT 1 FROM sessions s WHERE s.card_id = c.id AND s.stopped_at IS NULL';
        if (($f['online'] ?? '') === 'yes') {
            $where[] = "EXISTS ($open)";
        } elseif (($f['online'] ?? '') === 'no') {
            $where[] = "NOT EXISTS ($open)";
        }
        return [implode(' AND ', $where), $params];
    }

    public static function activeOrder(string $sort): string
    {
        return match ($sort) {
            'expiring' => 'c.expires_at IS NULL, c.expires_at ASC, c.id DESC',
            'first'    => 'c.first_login_at DESC, c.id DESC',
            default    => 'c.last_login_at IS NULL, c.last_login_at DESC, c.id DESC',
        };
    }

    /** الكروت المنتهية: انتهت صلاحيتها أو نفد رصيدها */
    public static function endedWhere(array $f): array
    {
        $where = ["c.status IN ('expired','depleted')"];
        $params = [];
        $status = (string) ($f['status'] ?? '');
        if (in_array($status, self::ENDED, true)) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        self::search((string) ($f['q'] ?? ''), $where, $params);
        self::category($f, $where, $params);
        self::dates($f, 'c.ended_at', $where, $params);
        return [implode(' AND ', $where), $params];
    }

    /** محاولات صفحة الهوتسبوت برقم كرت غير موجود أو بكلمة مرور خاطئة */
    public static function wrongWhere(array $f): array
    {
        $where = ["l.source = 'portal'", "l.result = 'reject'", "l.reason IN ('not_found','wrong_password')", 'l.subscriber_id IS NULL'];
        $params = [];
        $q = trim(latin_digits((string) ($f['q'] ?? '')));
        if ($q !== '') {
            if (($mac = normalize_mac($q)) !== null) {
                $where[] = 'l.mac = ?';
                $params[] = $mac;
            } elseif (is_valid_ip($q)) {
                $where[] = 'l.ip = ?';
                $params[] = $q;
            } else {
                $where[] = 'l.login LIKE ?';
                $params[] = addcslashes($q, '%_\\') . '%';
            }
        }
        $reason = (string) ($f['reason'] ?? '');
        if (in_array($reason, self::WRONG_REASONS, true)) {
            $where[] = 'l.reason = ?';
            $params[] = $reason;
        }
        $router = (string) ($f['router'] ?? '');
        if ($router !== '' && ctype_digit($router)) {
            $where[] = 'l.router_id = ?';
            $params[] = (int) $router;
        }
        self::dates($f, 'l.created_at', $where, $params);
        // نطاق أجهزة MikroTik المسموحة للموظف (شرطه في آخر الاستعلام، فمعاملاته في آخر القائمة)
        [$scope, $scopeParams] = Auth::routerScope('l.router_id', true);
        return [implode(' AND ', $where) . $scope, array_merge($params, $scopeParams)];
    }

    // ---------------------------------------------------------------------
    // القوائم
    // ---------------------------------------------------------------------

    private const CARD_COLUMNS = 'c.*, cat.name AS category_name, cat.color AS category_color, cat.price AS category_price,
        cat.time_limit AS cat_time_limit, cat.data_limit AS cat_data_limit, cat.validity AS cat_validity,
        b.name AS batch_name, d.name AS distributor_name, r.name AS last_router_name, dev.hostname AS last_hostname';

    private const CARD_JOINS = 'FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
        LEFT JOIN distributors d ON d.id = c.distributor_id LEFT JOIN routers r ON r.id = c.last_router_id
        LEFT JOIN devices dev ON dev.mac = c.last_mac';

    public static function activeRows(array $f, string $limitSql): array
    {
        [$where, $params] = self::activeWhere($f);
        $rows = DB::rows(
            'SELECT ' . self::CARD_COLUMNS . ', (SELECT COUNT(*) FROM sessions s WHERE s.card_id = c.id AND s.stopped_at IS NULL) AS online_count
             ' . self::CARD_JOINS . " WHERE $where ORDER BY " . self::activeOrder((string) ($f['sort'] ?? '')) . $limitSql,
            $params
        );
        return array_map([self::class, 'withRemaining'], $rows);
    }

    public static function endedRows(array $f, string $limitSql): array
    {
        [$where, $params] = self::endedWhere($f);
        $rows = DB::rows(
            'SELECT ' . self::CARD_COLUMNS . ' ' . self::CARD_JOINS . " WHERE $where ORDER BY c.ended_at IS NULL, c.ended_at DESC, c.id DESC" . $limitSql,
            $params
        );
        return array_map([self::class, 'withRemaining'], $rows);
    }

    public static function wrongRows(array $f, string $limitSql): array
    {
        [$where, $params] = self::wrongWhere($f);
        $rows = DB::rows(
            "SELECT l.*, r.name AS router_name, dev.hostname, c.username AS card_username, c.status AS card_status, c.is_disabled AS card_disabled
             FROM login_log l LEFT JOIN routers r ON r.id = l.router_id LEFT JOIN devices dev ON dev.mac = l.mac
             LEFT JOIN cards c ON c.id = l.card_id
             WHERE $where ORDER BY l.id DESC" . $limitSql,
            $params
        );
        $macs = array_values(array_unique(array_filter(array_column($rows, 'mac'))));
        $attempts = $blocked = [];
        if ($macs) {
            [$scope, $sp] = Auth::routerScope('router_id', true);
            $attempts = DB::pairs(
                "SELECT mac, COUNT(*) FROM login_log WHERE mac IN (" . DB::in($macs) . ")
                   AND source = 'portal' AND result = 'reject' AND reason IN ('not_found','wrong_password') AND subscriber_id IS NULL$scope
                 GROUP BY mac",
                array_merge($macs, $sp)
            );
            $blocked = array_flip(DB::column('SELECT mac FROM blocked_macs WHERE mac IN (' . DB::in($macs) . ')', $macs));
        }
        foreach ($rows as &$row) {
            $row['mac_attempts'] = $row['mac'] ? (int) ($attempts[$row['mac']] ?? 0) : 0;
            $row['mac_blocked'] = $row['mac'] ? isset($blocked[$row['mac']]) : false;
        }
        return $rows;
    }

    /** إضافة المتبقي (الوقت، البيانات، حتى الانتهاء) لصف كرت مع حدود باقته */
    private static function withRemaining(array $c): array
    {
        $cat = ['time_limit' => $c['cat_time_limit'], 'data_limit' => $c['cat_data_limit'], 'validity' => $c['cat_validity']];
        [$c['limit_time'], $c['limit_data'], $c['limit_validity']] = CardRules::limits($c, $cat);
        $c['remaining'] = CardRules::remaining($c, $cat);
        return $c;
    }

    public static function counts(): array
    {
        [$scope, $params] = Auth::routerScope('router_id', true);
        return [
            'active' => (int) DB::value("SELECT COUNT(*) FROM cards WHERE status = 'active' AND is_disabled = 0"),
            'online' => (int) DB::value(
                "SELECT COUNT(DISTINCT s.card_id) FROM sessions s JOIN cards c ON c.id = s.card_id
                 WHERE s.stopped_at IS NULL AND c.status = 'active' AND c.is_disabled = 0"
            ),
            'ended'  => (int) DB::value("SELECT COUNT(*) FROM cards WHERE status IN ('expired','depleted')"),
            'wrong'  => (int) DB::value(
                "SELECT COUNT(*) FROM login_log WHERE created_at >= ? AND source = 'portal' AND result = 'reject'
                   AND reason IN ('not_found','wrong_password') AND subscriber_id IS NULL$scope",
                array_merge([date('Y-m-d H:i:s', time() - 86400)], $params)
            ),
        ];
    }

    // ---------------------------------------------------------------------
    // البحث عن كرت وتفاصيله
    // ---------------------------------------------------------------------

    /** معرّفات الكروت المطابقة لنص البحث (المطابقة التامة للرقم أو المختصر أولاً) */
    public static function find(string $q, int $limit = 30): array
    {
        $q = trim(latin_digits($q));
        if ($q === '' || mb_strlen($q) > 64) {
            return [];
        }
        // المطابقة التامة كما تُدخل في صفحة الهوتسبوت (مع إعداد حالة الأحرف)
        foreach (array_unique([preg_replace('/\s+/', '', $q), PortalService::normalizeCode($q)]) as $exact) {
            $owner = LoginRegistry::owner((string) $exact);
            if ($owner && in_array($owner[0], ['card', 'card_short'], true)) {
                return [(int) $owner[1]];
            }
        }
        $where = [];
        $params = [];
        self::search($q, $where, $params);
        return array_map('intval', DB::column(
            'SELECT c.id FROM cards c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.last_login_at IS NULL, c.last_login_at DESC, c.id DESC LIMIT ' . (int) $limit,
            $params
        ));
    }

    /** ملخص قصير لعدة كروت (نتائج البحث) */
    public static function summaries(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return DB::rows(
            'SELECT c.id, c.username, c.short_code, c.status, c.is_disabled, c.expires_at, c.last_login_at, c.last_mac, c.bound_mac,
                    cat.name AS category_name, cat.color AS category_color
             FROM cards c JOIN categories cat ON cat.id = c.category_id WHERE c.id IN (' . DB::in($ids) . ')
             ORDER BY c.last_login_at IS NULL, c.last_login_at DESC, c.id DESC',
            $ids
        );
    }

    /** كل تفاصيل الكرت: البيانات، الحدود، المتبقي، الاتصال الحالي، وآخر محاولات الدخول */
    public static function details(int $id): ?array
    {
        $card = DB::row(
            'SELECT c.*, cat.name AS category_name, cat.color AS category_color, cat.price AS category_price, b.name AS batch_name,
                    d.name AS distributor_name, r.name AS last_router_name, dev.hostname AS last_hostname, bdev.hostname AS bound_hostname
             FROM cards c JOIN categories cat ON cat.id = c.category_id JOIN batches b ON b.id = c.batch_id
             LEFT JOIN distributors d ON d.id = c.distributor_id LEFT JOIN routers r ON r.id = c.last_router_id
             LEFT JOIN devices dev ON dev.mac = c.last_mac LEFT JOIN devices bdev ON bdev.mac = c.bound_mac
             WHERE c.id = ?',
            [$id]
        );
        if (!$card) {
            return null;
        }
        $category = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
        [$timeLimit, $dataLimit, $validity] = CardRules::limits($card, $category);
        $online = DB::rows(
            'SELECT s.*, r.name AS router_name, dv.hostname FROM sessions s LEFT JOIN routers r ON r.id = s.router_id LEFT JOIN devices dv ON dv.mac = s.mac
             WHERE s.card_id = ? AND s.stopped_at IS NULL ORDER BY s.started_at DESC',
            [$id]
        );
        $logins = array_values(array_filter([$card['username'], $card['short_code']], static fn ($x) => $x !== null && $x !== ''));
        [$scope, $sp] = Auth::routerScope('l.router_id', true);
        $attempts = DB::rows(
            'SELECT l.*, r.name AS router_name, dv.hostname FROM login_log l LEFT JOIN routers r ON r.id = l.router_id LEFT JOIN devices dv ON dv.mac = l.mac
             WHERE (l.card_id = ? OR (l.card_id IS NULL AND l.subscriber_id IS NULL AND l.login IN (' . DB::in($logins) . ")))$scope
             ORDER BY l.id DESC LIMIT 20",
            array_merge([$id], $logins, $sp)
        );
        return [
            'card'      => $card,
            'category'  => $category,
            'limits'    => ['time' => $timeLimit, 'data' => $dataLimit, 'validity' => $validity],
            'remaining' => CardRules::remaining($card, $category),
            'reason'    => CardRules::blockReason($card, $category),
            'online'    => $online,
            'sessions'  => (int) DB::value('SELECT COUNT(*) FROM sessions WHERE card_id = ?', [$id]),
            'attempts'  => $attempts,
            'wrong'     => (int) DB::value(
                "SELECT COUNT(*) FROM login_log WHERE card_id = ? AND result = 'reject' AND reason = 'wrong_password'",
                [$id]
            ),
        ];
    }

    /** عدد المحاولات الخاطئة لرقم مُدخل (للبحث عن رقم غير موجود) */
    public static function wrongCountFor(string $login): int
    {
        $login = trim(latin_digits($login));
        if ($login === '') {
            return 0;
        }
        [$scope, $params] = Auth::routerScope('router_id', true);
        return (int) DB::value(
            "SELECT COUNT(*) FROM login_log WHERE login = ? AND source = 'portal' AND result = 'reject'
               AND reason IN ('not_found','wrong_password') AND subscriber_id IS NULL$scope",
            array_merge([preg_replace('/\s+/', '', $login)], $params)
        );
    }

    // ---------------------------------------------------------------------
    // حذف المنتهية
    // ---------------------------------------------------------------------

    /** معرّفات الكروت المنتهية المطابقة للتصفية */
    public static function endedIds(array $f, int $limit = 50000): array
    {
        [$where, $params] = self::endedWhere($f);
        return array_map('intval', DB::column("SELECT c.id FROM cards c WHERE $where ORDER BY c.id LIMIT " . (int) $limit, $params));
    }

    /**
     * حذف الكروت المنتهية فقط: أي معرّف لكرت غير منتهٍ يُتجاهل.
     * @return array{count:int, skipped:int, job:?int}
     */
    public static function deleteEnded(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($x) => $x > 0)));
        $ended = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::column("SELECT id FROM cards WHERE status IN ('expired','depleted') AND id IN (" . DB::in($chunk) . ')', $chunk) as $id) {
                $ended[] = (int) $id;
            }
        }
        if (!$ended) {
            return ['count' => 0, 'skipped' => count($ids), 'job' => null];
        }
        $r = CardService::delete($ended);
        return ['count' => (int) $r['count'], 'skipped' => count($ids) - count($ended), 'job' => $r['job'] ?? null];
    }
}
