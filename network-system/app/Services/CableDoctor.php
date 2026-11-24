<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use RuntimeException;
use Throwable;

/**
 * فحص الكابلات ومنافذ Ethernet على أجهزة MikroTik (قراءة فقط، لا يغيّر شيئاً على الجهاز):
 *  - فحص الأزواج (cable-test / TDR): حالة كل زوج والمسافة بالمتر. الجهاز يقيس المسافات والرابط مفصول فقط،
 *    لذلك لا يُشغَّل على منفذ رابطه قائم (نتيجته معروفة: link-ok) حتى لا يتأثر الرابط على بعض الشرائح.
 *  - حالة الرابط (ethernet monitor): السرعة، الازدواج، التفاوض، وما يعلنه الطرفان (منفذ جيجا على 100 ميغا = زوج تالف غالباً).
 *  - عدّادات الأخطاء (rx-fcs-error وأخواتها): قراءتان بفاصل ثوانٍ لمعرفة هل تزيد الآن، ومقارنة بآخر فحص.
 *  - عدد مرات انقطاع الرابط وآخرها: يكشف التقطيع المتكرر.
 *  - الفحص الدوري (scan): قراءة كل منافذ الأجهزة ومقارنتها بآخر لقطة، وتنبيه على تيليجرام عند خلل.
 * التحليل (analyze) دالة خالصة على البيانات، فيُختبر دون جهاز.
 */
final class CableDoctor
{
    /** الأزواج بترتيب RouterOS: سلكا الزوج (رقما طرفيه في الرأس) ولونه في معيار T568B. «3 و6» لا «3-6» حتى لا ينقلب الرقمان في النص العربي */
    public const PAIRS = [
        1 => ['1 و2', 'برتقالي', '#f97316'],
        2 => ['3 و6', 'أخضر', '#16a34a'],
        3 => ['4 و5', 'أزرق', '#2563eb'],
        4 => ['7 و8', 'بني', '#92400e'],
    ];

    /**
     * الأسلاك الثمانية في الرأس بمعيار T568B: رقم الطرف => [اللون، رقم الزوج، مخطط بالأبيض، رقم السلك الشريك في الزوج].
     * الجهاز يقيس كل زوج (سلكين ملتفّين) معاً، فحالة السلك هي حالة زوجه: التماس بين سلكي الزوج، والقطع في أحدهما.
     */
    public const WIRES = [
        1 => ['أبيض برتقالي', 1, true, 2],
        2 => ['برتقالي', 1, false, 1],
        3 => ['أبيض أخضر', 2, true, 6],
        4 => ['أزرق', 3, false, 5],
        5 => ['أبيض أزرق', 3, true, 4],
        6 => ['أخضر', 2, false, 3],
        7 => ['أبيض بني', 4, true, 8],
        8 => ['بني', 4, false, 7],
    ];

    /** عدّادات الأخطاء: الاسم في RouterOS => [الاسم بالعربية، المجموعة (cable: كابل/رأس، duplex: ازدواج، other: لا تدل على الكابل)] */
    public const COUNTERS = [
        'rx-fcs-error'           => ['إطارات تالفة (FCS)', 'cable'],
        'rx-align-error'         => ['أخطاء المحاذاة', 'cable'],
        'rx-code-error'          => ['أخطاء الترميز', 'cable'],
        'rx-carrier-error'       => ['أخطاء الإشارة', 'cable'],
        'rx-fragment'            => ['إطارات مقطوعة', 'cable'],
        'rx-length-error'        => ['أخطاء الطول', 'cable'],
        'rx-too-short'           => ['إطارات قصيرة جداً', 'cable'],
        'rx-jabber'              => ['إشارة مشوّشة (jabber)', 'cable'],
        'rx-error-events'        => ['أحداث أخطاء الاستقبال', 'cable'],
        'rx-too-long'            => ['إطارات طويلة جداً', 'other'],
        'tx-collision'           => ['تصادمات الإرسال', 'duplex'],
        'tx-late-collision'      => ['تصادمات متأخرة', 'duplex'],
        'tx-excessive-collision' => ['تصادمات زائدة', 'duplex'],
    ];

    public const LEVELS = ['ok' => 'سليم', 'warn' => 'يحتاج انتباهاً', 'bad' => 'خلل'];
    private const RANK = ['info' => 0, 'ok' => 0, 'warn' => 1, 'bad' => 2];

    /** الفاصل بين قراءتي العدّادات بالثواني (يُصفَّر في الاختبارات) */
    public static int $gap = 3;

    // ------------------------------------------------------------------
    // القراءة من الجهاز
    // ------------------------------------------------------------------

    /**
     * قراءة منفذ من الجهاز.
     * @param bool $tdr فحص الأزواج إن كان الرابط مفصولاً
     * @param bool $twoReads قراءة ثانية للعدّادات بعد $gap ثوانٍ
     * @param ?array $info صف المنفذ من ethernetPorts إن كان مقروءاً
     * @param ?int $uptime مدة تشغيل الجهاز إن كانت مقروءة
     * @param bool $monitor قراءة حالة الرابط (ethernet monitor)
     */
    public static function collect(Gateway $gw, string $port, bool $tdr = true, bool $twoReads = true, ?array $info = null, ?int $uptime = null, bool $monitor = true): array
    {
        if ($info === null) {
            foreach ($gw->ethernetPorts() as $p) {
                if ($p['name'] === $port) {
                    $info = $p;
                    break;
                }
            }
        }
        if ($info === null) {
            throw new RuntimeException('المنفذ ' . $port . ' غير موجود على الجهاز، أو ليس منفذ Ethernet.');
        }
        if ($uptime === null) {
            try {
                $uptime = $gw->resource()['uptime_seconds'] ?? null;
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
            }
        }
        $d = ['port' => $port, 'comment' => $info['comment'], 'disabled' => $info['disabled'], 'running' => $info['running'], 'link_downs' => $info['link_downs'],
            'last_down' => $info['last_down'], 'last_up' => $info['last_up'], 'stats1' => $info['stats'], 'stats2' => $info['stats'], 'gap' => 0,
            'monitor' => null, 'tdr' => null, 'tdr_skipped' => null, 'uptime' => $uptime, 'boot' => $uptime !== null ? time() - (int) $uptime : null];
        if ($info['disabled']) {
            return $d;
        }
        if ($monitor) {
            try {
                $d['monitor'] = $gw->ethernetMonitor($port) ?: null;
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
            }
        }
        $status = (string) ($d['monitor']['status'] ?? ($info['running'] ? 'link-ok' : 'no-link'));
        $sfp = isset($d['monitor']['sfp-module-present']) || str_starts_with(strtolower($info['default_name'] ?: $port), 'sfp');
        if ($tdr) {
            if ($sfp) {
                $d['tdr_skipped'] = 'sfp';
            } elseif ($status === 'link-ok') {
                $d['tdr_skipped'] = 'link';
            } else {
                try {
                    $d['tdr'] = $gw->cableTest($port) ?: null;
                } catch (MikroTikException $e) {
                    if ($e->isConnection()) {
                        throw $e;
                    }
                    $d['tdr_skipped'] = 'unsupported';
                }
                if ($d['tdr'] !== null && ($d['tdr']['status'] ?? '') === 'link-ok' && ($d['tdr']['cable-pairs'] ?? '') === '') {
                    $d['tdr'] = null;
                    $d['tdr_skipped'] = 'link'; // عاد الرابط أثناء الفحص
                }
            }
        }
        if ($twoReads) {
            if (self::$gap > 0) {
                sleep(self::$gap);
            }
            $d['stats2'] = $gw->ethernetStats($port) ?: $d['stats1'];
            $d['gap'] = self::$gap;
        }
        return $d;
    }

    /** منافذ جهاز للقائمة: حالتها وعدّاداتها وآخر فحص لكل منها */
    public static function ports(array $router): array
    {
        $gw = Gateway::for($router, 8.0);
        try {
            $ports = $gw->ethernetPorts();
            $peers = $gw->portPeers(array_column($ports, 'name'));
        } finally {
            $gw->close();
        }
        $last = [];
        foreach (DB::rows('SELECT c.port, c.level, c.summary, c.snapshot, c.created_at FROM cable_checks c
                JOIN (SELECT port, MAX(id) AS id FROM cable_checks WHERE router_id = ? GROUP BY port) x ON x.id = c.id', [(int) $router['id']]) as $r) {
            $last[$r['port']] = $r;
        }
        $wan = (string) ($router['wan_interface'] ?? '');
        $out = [];
        foreach ($ports as $p) {
            $errors = 0;
            foreach (self::COUNTERS as $k => [, $group]) {
                $errors += $group !== 'other' ? (int) ($p['stats'][$k] ?? 0) : 0;
            }
            $l = $last[$p['name']] ?? null;
            $snapPeer = $l ? ((json_decode((string) $l['snapshot'], true) ?: [])['peer'] ?? null) : null;
            $out[] = ['name' => $p['name'], 'comment' => $p['comment'], 'running' => $p['running'], 'disabled' => $p['disabled'], 'link_downs' => $p['link_downs'],
                'errors' => $errors, 'wan' => $p['name'] === $wan, 'sfp' => str_starts_with(strtolower($p['default_name'] ?: $p['name']), 'sfp'),
                'peer' => self::peer($router, $peers[$p['name']] ?? null, is_array($snapPeer) ? $snapPeer : null),
                'last' => $l ? ['level' => $l['level'], 'summary' => $l['summary'], 'ago' => time_ago($l['created_at'])] : null];
        }
        return $out;
    }

    /** فحص يدوي لمنفذ وحفظه: يُعيد النتيجة المعروضة */
    public static function run(array $router, string $port): array
    {
        $gw = Gateway::for($router, 8.0);
        $offer = null;
        $prev = self::last((int) $router['id'], $port);
        try {
            $d = self::collect($gw, $port);
            $d['peer'] = self::peer($router, $gw->portPeers([$port])[$port] ?? null, $prev['peer'] ?? null);
            // والرابط قائم لا تُقاس الأزواج: يُعرض الفحص العميق إن كان آمناً على هذا المنفذ
            if (($d['tdr_skipped'] ?? null) === 'link') {
                $offer = self::deepOffer($gw, $router, $port, (array) $d['monitor']);
            }
        } finally {
            $gw->close();
        }
        $r = self::analyze($d, $prev);
        $r['wan'] = $port === (string) ($router['wan_interface'] ?? '');
        $r['router'] = (string) $router['name'];
        $r['deep_offer'] = $offer;
        $r['id'] = self::save((int) $router['id'], $port, $r, $d, 'manual');
        return $r;
    }

    // ------------------------------------------------------------------
    // الفحص العميق: قياس الطول ومكان القطع أو التماس على منفذ رابطه قائم
    // ------------------------------------------------------------------

    /** ثوانٍ بين تعطيل المنفذ وقياس الأزواج، وأقصى انتظار لعودة الرابط بعده (يُصفَّران في الاختبارات) */
    public static int $settle = 2;
    public static int $linkWait = 10;

    /**
     * هل يُسمح بالفحص العميق على المنفذ: لا يُعطَّل منفذ الإنترنت (WAN) ولا منفذ يمر عبره اتصال النظام بالجهاز.
     * @return array{ok:bool, reason:?string, poe:bool}
     */
    public static function deepOffer(Gateway $gw, array $router, string $port, array $monitor): array
    {
        $poe = ($monitor['poe-out-status'] ?? '') === 'powered-on';
        if ($port === (string) ($router['wan_interface'] ?? '')) {
            return ['ok' => false, 'reason' => 'هذا منفذ الإنترنت (WAN) للجهاز: فصله ولو ثوانيَ يقطع الإنترنت عن الشبكة، فلا يُفحص فحصاً عميقاً.', 'poe' => $poe];
        }
        if (in_array($port, $gw->managementPorts(), true)) {
            return ['ok' => false, 'reason' => 'اتصال النظام بالجهاز يمر عبر هذا المنفذ: فصله يقطع الاتصال بالجهاز، فلا يُفحص فحصاً عميقاً.', 'poe' => $poe];
        }
        return ['ok' => true, 'reason' => null, 'poe' => $poe];
    }

    /**
     * فحص عميق: يعطّل المنفذ ثانيتين ليقيس الجهاز الأزواج (الطول ومكان القطع أو التماس بالمتر) ثم يعيده فوراً.
     * قبل التعطيل يُضبط على الجهاز نفسه مؤقّت أمان يعيد تشغيل المنفذ خلال 45 ثانية حتى لو انقطع الاتصال بالنظام.
     */
    public static function deep(array $router, string $port): array
    {
        $gw = Gateway::for($router, 10.0);
        try {
            $info = null;
            foreach ($gw->ethernetPorts() as $p) {
                if ($p['name'] === $port) {
                    $info = $p;
                }
            }
            if ($info === null) {
                throw new RuntimeException('المنفذ ' . $port . ' غير موجود على الجهاز، أو ليس منفذ Ethernet.');
            }
            if ($info['disabled']) {
                throw new RuntimeException('المنفذ معطّل في إعدادات الجهاز. شغّله أولاً.');
            }
            $monitor = (array) $gw->ethernetMonitor($port);
            if (isset($monitor['sfp-module-present']) || str_starts_with(strtolower($info['default_name'] ?: $port), 'sfp')) {
                throw new RuntimeException('منفذ SFP لا يُفحص بالأزواج.');
            }
            $offer = self::deepOffer($gw, $router, $port, $monitor);
            if (!$offer['ok']) {
                throw new RuntimeException((string) $offer['reason']);
            }
            $prev = self::last((int) $router['id'], $port);
            $peer = self::peer($router, $gw->portPeers([$port])[$port] ?? null, $prev['peer'] ?? null);
            $uptime = null;
            try {
                $uptime = $gw->resource()['uptime_seconds'] ?? null;
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
            }
            $watch = $gw->addPortWatchdog($port, 45);
            $tdr = null;
            $skipped = null;
            try {
                $gw->setEthernetDisabled($port, true);
                if (self::$settle > 0) {
                    sleep(self::$settle);
                }
                try {
                    $tdr = $gw->cableTest($port) ?: null;
                } catch (MikroTikException $e) {
                    if ($e->isConnection()) {
                        throw $e;
                    }
                    $skipped = 'unsupported';
                }
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw new RuntimeException('انقطع الاتصال بالجهاز أثناء الفحص. سيعيد الجهاز تشغيل المنفذ ' . $port . ' تلقائياً خلال أقل من دقيقة.');
                }
                throw $e;
            } finally {
                try {
                    $gw->setEthernetDisabled($port, false);
                    $gw->removeScheduler($watch);
                } catch (Throwable $e) {
                    log_message('warning', 'cable deep restore ' . $port . ': ' . $e->getMessage() . ' (المؤقّت على الجهاز يعيده)');
                }
            }
            // انتظار عودة الرابط
            $post = $info;
            $back = false;
            $until = microtime(true) + self::$linkWait;
            do {
                foreach ($gw->ethernetPorts() as $p) {
                    if ($p['name'] === $port) {
                        $post = $p;
                    }
                }
                if ($post['running']) {
                    $back = true;
                    break;
                }
                if (self::$linkWait > 0) {
                    usleep(1_000_000);
                }
            } while (microtime(true) < $until);
        } finally {
            $gw->close();
        }
        $d = ['port' => $port, 'comment' => $info['comment'], 'disabled' => false, 'running' => true, 'link_downs' => $info['link_downs'],
            'last_down' => $info['last_down'], 'last_up' => $info['last_up'], 'stats1' => $info['stats'], 'stats2' => $info['stats'], 'gap' => 0,
            'monitor' => $monitor, 'tdr' => $tdr, 'tdr_skipped' => $skipped, 'uptime' => $uptime, 'boot' => $uptime !== null ? time() - (int) $uptime : null,
            'deep' => true, 'link_back' => $back, 'snap_link_downs' => $post['link_downs'], 'snap_stats' => $post['stats'], 'snap_running' => $post['running'], 'peer' => $peer];
        $r = self::analyze($d, $prev);
        $r['wan'] = false;
        $r['router'] = (string) $router['name'];
        $r['deep_offer'] = null;
        $r['id'] = self::save((int) $router['id'], $port, $r, $d, 'deep');
        return $r;
    }

    /**
     * خريطة الكابلات: كل كابل بين جهاز من أجهزة النظام وجهاز آخر مكتشف على طرفه (MikroTik أو غيره)،
     * من آخر فحص لكل منفذ. الكابل بين جهازين من النظام يظهر مرة واحدة مهما فُحص من طرفيه، بأسوأ حالة وأحدث طول.
     * @param ?list<int> $routerIds الأجهزة المسموحة للمستخدم (null = الكل)
     * @return list<array>
     */
    public static function links(?array $routerIds = null): array
    {
        $sql = 'SELECT c.id, c.router_id, c.port, c.level, c.summary, c.length_m, c.snapshot, c.result, c.created_at, r.name AS router_name
            FROM cable_checks c JOIN (SELECT MAX(id) AS id FROM cable_checks GROUP BY router_id, port) x ON x.id = c.id
            JOIN routers r ON r.id = c.router_id';
        $params = [];
        if ($routerIds !== null) {
            if (!$routerIds) {
                return [];
            }
            $sql .= ' WHERE c.router_id IN (' . DB::in($routerIds) . ')';
            $params = $routerIds;
        }
        $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
        $links = [];
        foreach (DB::rows($sql . ' ORDER BY c.id DESC', $params) as $row) {
            $snap = json_decode((string) $row['snapshot'], true) ?: [];
            $peer = is_array($snap['peer'] ?? null) ? $snap['peer'] : null;
            if ($peer === null || !in_array($peer['kind'] ?? '', ['neighbor', 'host'], true)) {
                continue;
            }
            $length = $row['length_m'] !== null ? (int) $row['length_m'] : null;
            if ($length === null) {
                $length = DB::value('SELECT length_m FROM cable_checks WHERE router_id = ? AND port = ? AND length_m IS NOT NULL ORDER BY id DESC LIMIT 1', [(int) $row['router_id'], $row['port']]);
                $length = $length !== null ? (int) $length : null;
            }
            $sysId = isset($peer['system']['id']) ? (int) $peer['system']['id'] : null;
            $a = (int) $row['router_id'] . ':' . $row['port'];
            $b = $sysId !== null ? $sysId . ':' . (string) ($peer['remote_port'] ?? '') : 'x:' . strtolower((string) ($peer['mac'] ?: $peer['name']));
            $key = $sysId !== null ? implode('|', [min($a, $b), max($a, $b)]) : $a;
            $res = json_decode((string) $row['result'], true) ?: [];
            $pairs = [];
            foreach ((array) ($res['pairs'] ?? []) as $pr) {
                if (is_array($pr) && isset($pr['no'])) {
                    $pairs[] = ['no' => (int) $pr['no'], 'hex' => (string) ($pr['hex'] ?? ''), 'color' => (string) ($pr['color'] ?? ''), 'cls' => (string) ($pr['cls'] ?? 'muted'),
                        'text' => (string) ($pr['text'] ?? ''), 'm' => isset($pr['m']) ? (int) $pr['m'] : null, 'state' => (string) ($pr['state'] ?? '')];
                }
            }
            $side = ['check' => (int) $row['id'], 'level' => $row['level'], 'summary' => $row['summary'], 'at' => $row['created_at'], 'length' => $length, 'pairs' => $pairs];
            if (isset($links[$key])) {
                // الطرف الآخر من الكابل نفسه: تُدمج النتيجة (أسوأ حالة، وأي طول معروف)
                $l = &$links[$key];
                if ($rank[$side['level']] > $rank[$l['level']]) {
                    $l['level'] = $side['level'];
                    $l['summary'] = $side['summary'];
                    $l['check'] = $side['check'];
                    if ($side['pairs']) {
                        $l['pairs'] = $side['pairs'];
                    }
                }
                if (!$l['pairs'] && $side['pairs']) {
                    $l['pairs'] = $side['pairs'];
                }
                $l['length'] ??= $side['length'];
                $l['both'] = true;
                unset($l);
                continue;
            }
            $links[$key] = [
                'from' => ['id' => (int) $row['router_id'], 'name' => (string) $row['router_name'], 'port' => (string) $row['port']],
                'to' => ['id' => $sysId, 'name' => (string) ($peer['name'] ?? ''), 'port' => (string) ($peer['remote_port'] ?? ''), 'model' => (string) ($peer['model'] ?? ''),
                    'address' => (string) ($peer['address'] ?? ''), 'kind' => (string) $peer['kind']],
                'both' => false,
            ] + $side;
        }
        $links = array_values($links);
        usort($links, static fn ($x, $y) => [$rank[$y['level']], $x['from']['name'], $x['from']['port']] <=> [$rank[$x['level']], $y['from']['name'], $y['from']['port']]);
        return $links;
    }

    /** آخر فحص محفوظ لمنفذ (لمقارنة العدّادات وعدد الانقطاعات) */
    public static function last(int $routerId, string $port): ?array
    {
        $row = DB::row('SELECT * FROM cable_checks WHERE router_id = ? AND port = ? ORDER BY id DESC LIMIT 1', [$routerId, $port]);
        if (!$row) {
            return null;
        }
        $snap = json_decode((string) $row['snapshot'], true) ?: [];
        $len = DB::row('SELECT length_m, created_at FROM cable_checks WHERE router_id = ? AND port = ? AND length_m IS NOT NULL ORDER BY id DESC LIMIT 1', [$routerId, $port]);
        return ['at' => $row['created_at'], 'level' => $row['level'], 'summary' => $row['summary'], 'stats' => (array) ($snap['stats'] ?? []),
            'link_downs' => (int) ($snap['link_downs'] ?? 0), 'running' => (bool) ($snap['running'] ?? false), 'boot' => isset($snap['boot']) ? (int) $snap['boot'] : null,
            'length' => $len ? (int) $len['length_m'] : null, 'length_at' => $len['created_at'] ?? null, 'peer' => is_array($snap['peer'] ?? null) ? $snap['peer'] : null];
    }

    public static function save(int $routerId, string $port, array $r, array $d, string $source): int
    {
        // اللقطة للمقارنة في الفحص التالي: بعد الفحص العميق تُؤخذ بعد إعادة المنفذ حتى لا يُحسب انقطاعه المقصود تقطيعاً
        $snap = ['stats' => $d['snap_stats'] ?? $d['stats2'], 'link_downs' => $d['snap_link_downs'] ?? $d['link_downs'], 'running' => $d['snap_running'] ?? $d['running'], 'boot' => $d['boot'],
            'peer' => $d['peer'] ?? null];
        return DB::insert('cable_checks', [
            'router_id' => $routerId, 'port' => mb_substr($port, 0, 60), 'level' => $r['level'], 'summary' => mb_substr($r['headline'], 0, 255),
            'result' => json_encode($r, JSON_UNESCAPED_UNICODE), 'snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE),
            'length_m' => isset($r['length']) && $r['length'] !== null ? min(65535, max(0, (int) $r['length'])) : null,
            'source' => $source, 'employee_id' => $source !== 'auto' ? Auth::id() : null, 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // الفحص الدوري
    // ------------------------------------------------------------------

    /**
     * يفحص الأجهزة التي حان دورها (كل cable.interval_hours ساعة): قراءة واحدة لكل المنافذ، وحالة الرابط للعاملة منها،
     * وفحص الأزواج لمنفذ كان يعمل في آخر لقطة وانقطع رابطه (عندها فقط يقيس الجهاز المسافة).
     * المنفذ الذي لم يعمل قط لا يُحفظ له شيء. التنبيه عند الخلل فقط، مرة في اليوم لكل منفذ وسبب.
     * @return array{routers:int, ports:int, alerts:int, failed:int}
     */
    public static function scan(?float $deadline = null, ?int $now = null): array
    {
        $out = ['routers' => 0, 'ports' => 0, 'alerts' => 0, 'failed' => 0];
        if (!Settings::bool('cable.auto')) {
            return $out;
        }
        $now ??= time();
        $every = max(1, min(168, Settings::int('cable.interval_hours', 6))) * 3600;
        $done = json_decode(Settings::get('cable.scanned', '{}'), true);
        $done = is_array($done) ? $done : [];
        $routers = DB::rows("SELECT * FROM routers WHERE is_active = 1 AND status = 'online' ORDER BY id");
        $done = array_intersect_key($done, array_flip(array_map(static fn ($r) => (string) $r['id'], $routers))); // نسيان الأجهزة المحذوفة
        foreach ($routers as $router) {
            if ($deadline !== null && microtime(true) > $deadline - 8) {
                break;
            }
            $id = (string) $router['id'];
            if ($now - (int) ($done[$id] ?? 0) < $every - 300) {
                continue;
            }
            $done[$id] = $now;
            $out['routers']++;
            try {
                foreach (self::scanRouter($router) as $alerted) {
                    $out['ports']++;
                    $out['alerts'] += $alerted ? 1 : 0;
                }
            } catch (Throwable $e) {
                $out['failed']++;
                log_message('warning', 'cable scan ' . $router['name'] . ': ' . $e->getMessage());
            }
        }
        Settings::set('cable.scanned', json_encode($done));
        return $out;
    }

    /** @return list<bool> لكل منفذ فُحص: هل أُرسل له تنبيه */
    private static function scanRouter(array $router): array
    {
        $gw = Gateway::for($router, 8.0);
        $out = [];
        try {
            $ports = $gw->ethernetPorts();
            $peers = $gw->portPeers(array_column($ports, 'name'));
            $uptime = null;
            try {
                $uptime = $gw->resource()['uptime_seconds'] ?? null;
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
            }
            foreach ($ports as $p) {
                if ($p['disabled']) {
                    continue;
                }
                $prev = self::last((int) $router['id'], $p['name']);
                $wasUp = $prev !== null && $prev['running'];
                if (!$p['running'] && !$wasUp) {
                    continue; // منفذ غير مستخدم، أو انقطاعه سُجّل في الفحص السابق
                }
                $d = self::collect($gw, $p['name'], !$p['running'] && $wasUp, false, $p, $uptime, $p['running']);
                $d['peer'] = self::peer($router, $peers[$p['name']] ?? null, $prev['peer'] ?? null);
                $r = self::analyze($d, $prev);
                self::save((int) $router['id'], $p['name'], $r, $d, 'auto');
                $out[] = $r['level'] === 'bad' && self::alert($router, $r);
            }
        } finally {
            $gw->close();
        }
        return $out;
    }

    /** تنبيه بخلل منفذ (داخل النظام وعلى تيليجرام)، مرة في اليوم لكل منفذ ومجموعة أسباب */
    public static function alert(array $router, array $r): bool
    {
        $bad = array_values(array_filter($r['findings'], static fn ($x) => $x['level'] === 'bad'));
        if (!$bad) {
            return false;
        }
        $codes = array_values(array_unique(array_map(static fn ($x) => $x['code'] !== '' ? $x['code'] : 'x', $bad)));
        sort($codes);
        $peer = $r['peer'] ?? null;
        $title = !empty($peer['name'])
            ? 'خلل في الكابل من ' . $router['name'] . ' (' . $r['port'] . ') إلى ' . $peer['name'] . (($peer['remote_port'] ?? '') !== '' ? ' (' . $peer['remote_port'] . ')' : '')
            : 'خلل في المنفذ ' . $r['port'] . ($r['comment'] !== '' ? ' (' . $r['comment'] . ')' : '') . ' على ' . $router['name'];
        $id = Notifier::alert('cable', 'danger', $title,
            implode("\n", array_map(static fn ($x) => '• ' . $x['text'], array_slice($bad, 0, 3))),
            (int) $router['id'], 'cable:' . $router['id'] . ':' . $r['port'] . ':' . implode('+', $codes), 1440);
        return $id !== null;
    }

    /**
     * وصف الطرف الآخر للمنفذ من نتيجة portPeers: الجهاز المكتشف (ويُطابَق مع أجهزة النظام بالاسم أو العنوان)،
     * أو الجهاز الوحيد خلف المنفذ، أو عدد الأجهزة خلفه. إن لم يظهر شيء الآن (الكابل مقطوع) يُعاد آخر جهاز عُرف عليه.
     * @param ?array $info صف المنفذ من Gateway::portPeers
     * @param ?array $prev الطرف الآخر المحفوظ في آخر فحص
     */
    public static function peer(array $router, ?array $info, ?array $prev = null): ?array
    {
        $info ??= ['peers' => [], 'macs' => 0, 'host' => null];
        $n = $info['peers'][0] ?? null;
        if ($n !== null) {
            $system = null;
            if ($n['identity'] !== '' || $n['address'] !== '') {
                $row = DB::row('SELECT id, name FROM routers WHERE id <> ? AND ((identity <> \'\' AND LOWER(identity) = LOWER(?)) OR (host <> \'\' AND host = ?))
                    ORDER BY (LOWER(identity) = LOWER(?)) DESC LIMIT 1', [(int) $router['id'], $n['identity'], $n['address'], $n['identity']]);
                $system = $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
            }
            $via = array_map(static fn ($x) => strtoupper(trim($x)), array_filter(explode(',', $n['via'])));
            return ['kind' => 'neighbor', 'name' => $system['name'] ?? ($n['identity'] !== '' ? $n['identity'] : ($n['address'] !== '' ? $n['address'] : $n['mac'])),
                'identity' => $n['identity'], 'system' => $system, 'remote_port' => $n['remote_port'], 'address' => $n['address'], 'mac' => $n['mac'],
                'model' => $n['board'], 'platform' => $n['platform'], 'version' => $n['version'], 'via' => implode('، ', $via), 'others' => count($info['peers']) - 1,
                'macs' => (int) $info['macs'], 'remembered' => false, 'seen' => now()];
        }
        if (!empty($info['host'])) {
            $h = $info['host'];
            return ['kind' => 'host', 'name' => $h['name'] !== '' ? $h['name'] : ($h['address'] !== '' ? $h['address'] : $h['mac']), 'identity' => '', 'system' => null, 'remote_port' => '',
                'address' => $h['address'], 'mac' => $h['mac'], 'model' => '', 'platform' => '', 'version' => '', 'via' => 'DHCP', 'others' => 0, 'macs' => 1, 'remembered' => false, 'seen' => now()];
        }
        if ($info['macs'] > 1) {
            return ['kind' => 'many', 'name' => null, 'identity' => '', 'system' => null, 'remote_port' => '', 'address' => '', 'mac' => '', 'model' => '', 'platform' => '', 'version' => '',
                'via' => '', 'others' => 0, 'macs' => (int) $info['macs'], 'remembered' => false, 'seen' => now()];
        }
        if ($prev !== null && !empty($prev['name'])) {
            return ['remembered' => true, 'macs' => 0] + $prev;
        }
        return null;
    }

    /** حذف القديم: اللقطات الدورية السليمة بعد 14 يوماً، وكل الفحوص بعد 180 يوماً، مع إبقاء آخر فحص لكل منفذ */
    public static function prune(?int $now = null): void
    {
        $now ??= time();
        $keep = 'id NOT IN (SELECT id FROM (SELECT MAX(id) AS id FROM cable_checks GROUP BY router_id, port) k)';
        DB::query("DELETE FROM cable_checks WHERE source = 'auto' AND level = 'ok' AND created_at < ? AND $keep", [date('Y-m-d H:i:s', $now - 14 * 86400)]);
        DB::query("DELETE FROM cable_checks WHERE created_at < ? AND $keep", [date('Y-m-d H:i:s', $now - 180 * 86400)]);
    }

    // ------------------------------------------------------------------
    // التحليل (بلا جهاز)
    // ------------------------------------------------------------------

    /**
     * حالة كل سلك من الثمانية من نتيجة زوجه، بالترتيب الذي يُرى في الرأس (1 إلى 8).
     * @param list<array> $pairs نتيجة الأزواج من analyze
     * @return list<array{pin:int, color:string, pair:int, striped:bool, hex:string, partner:int, state:string, cls:string, text:string, m:?int}>
     */
    public static function wires(array $pairs): array
    {
        if (!$pairs) {
            return [];
        }
        $by = array_column($pairs, null, 'no');
        $out = [];
        foreach (self::WIRES as $pin => [$color, $pairNo, $striped, $partner]) {
            $p = $by[$pairNo] ?? null;
            $m = $p['m'] ?? null;
            $at = $m !== null ? ' على بعد ' . self::metres((int) $m) : '';
            [$cls, $text] = match (true) {
                $p === null => ['muted', 'لم يُقَس'],
                $p['cls'] === 'muted' && $p['state'] !== 'short' => ['muted', str_starts_with((string) $p['text'], 'غير مستخدم') ? 'غير مستخدم في منفذ 100 ميغا' : 'تعذّر القياس'],
                $p['state'] === 'short' => ['bad', 'تماس مع السلك ' . $partner . $at],
                $p['state'] === 'open' && $p['cls'] === 'bad' => ['bad', ($p['text'] === 'غير موصول بالرأس' ? 'غير موصول بالرأس البعيد' : 'مقطوع' . $at) . ' — هو أو السلك ' . $partner],
                $p['state'] === 'open' && $p['cls'] === 'warn' => ['warn', 'مفتوح' . $at],
                default => ['ok', 'سليم'],
            };
            $out[] = ['pin' => $pin, 'color' => $color, 'pair' => $pairNo, 'striped' => $striped, 'hex' => self::PAIRS[$pairNo][2], 'partner' => $partner,
                'state' => (string) ($p['state'] ?? ''), 'cls' => $cls, 'text' => $text, 'm' => $m];
        }
        return $out;
    }

    /** «open:37,normal:0,short:12» → [[state, metres|null], ...] */
    public static function pairs(string $raw): array
    {
        $out = [];
        foreach (array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($x) => $x !== '')) as $i => $part) {
            if ($i >= 4) {
                break;
            }
            [$state, $m] = array_pad(explode(':', $part, 2), 2, null);
            $out[] = [strtolower(trim((string) $state)), $m !== null && is_numeric(trim($m)) ? (int) trim($m) : null];
        }
        return $out;
    }

    /** أعلى سرعة في قائمة إعلانات (10M-baseT-full, 100M-full, 1G-baseT-full, 2.5G..., 10G...) بالميغابت */
    public static function maxSpeed(string $advertising): ?int
    {
        $max = null;
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*([MG])/i', $advertising, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $max = max($max ?? 0, (int) round((float) $x[1] * (strtoupper($x[2]) === 'G' ? 1000 : 1)));
            }
        }
        return $max;
    }

    /** «1Gbps» «100Mbps» «2.5Gbps» → بالميغابت */
    public static function rate(string $rate): ?int
    {
        return preg_match('/(\d+(?:\.\d+)?)\s*([MG])bps/i', $rate, $m) ? (int) round((float) $m[1] * (strtoupper($m[2]) === 'G' ? 1000 : 1)) : null;
    }

    public static function speedLabel(int $mbps): string
    {
        return $mbps >= 1000 ? rtrim(rtrim(number_format($mbps / 1000, 1, '.', ''), '0'), '.') . ' جيجا' : $mbps . ' ميغا';
    }

    public static function pairName(int $i): string
    {
        [$pins, $color] = self::PAIRS[$i] ?? ['?', '?'];
        return 'الزوج ' . $i . ' (' . $color . '، السلكان ' . $pins . ')';
    }

    public static function metres(int $m): string
    {
        return match (true) {
            $m === 1 => 'متر واحد',
            $m === 2 => 'مترين',
            $m >= 3 && $m <= 10 => $m . ' أمتار',
            default => $m . ' متراً',
        };
    }

    private static function times(int $n): string
    {
        return ar_plural($n, 'مرة واحدة', 'مرتين', 'مرات', 'مرة');
    }

    /**
     * @param array $d البيانات من collect
     * @param ?array $prev آخر فحص محفوظ (last)
     * @return array{level:string, headline:string, findings:list<array{level:string, text:string, code:string}>, pairs:list<array>, link:list<array>, counters:list<array>, links:list<array>, sfp:list<array>, port:string, comment:string, running:bool, at:string}
     */
    public static function analyze(array $d, ?array $prev = null): array
    {
        $f = [];
        $add = static function (string $level, string $text, string $code = '') use (&$f): void {
            $f[] = ['level' => $level, 'text' => $text, 'code' => $code];
        };
        $disabled = !empty($d['disabled']);
        $mon = (array) ($d['monitor'] ?? []);
        $linkOk = !$disabled && ($mon['status'] ?? (!empty($d['running']) ? 'link-ok' : 'no-link')) === 'link-ok';
        $own = self::maxSpeed((string) ($mon['advertising'] ?? ''));
        $partner = self::maxSpeed((string) ($mon['link-partner-advertising'] ?? ''));
        $speed = self::rate((string) ($mon['rate'] ?? ''));
        $neg = (string) ($mon['auto-negotiation'] ?? '');
        $full = ($mon['full-duplex'] ?? 'true') === 'true';

        // هل أُعيد تشغيل الجهاز منذ الفحص السابق (فتصفّرت العدّادات ولا تصح المقارنة)
        $rebooted = $prev !== null && $prev['boot'] !== null && ($d['boot'] ?? null) !== null && abs((int) $d['boot'] - (int) $prev['boot']) > 300;
        $comparable = $prev !== null && !$rebooted;
        $hours = $prev !== null ? max(0, (time() - (int) strtotime((string) $prev['at'])) / 3600) : null;
        $ago = $prev !== null ? time_ago((string) $prev['at']) : '';

        if ($disabled) {
            $add('warn', 'المنفذ معطّل في إعدادات جهاز MikroTik، فلا يعمل عليه أي رابط.', 'disabled');
        }
        if ($rebooted) {
            $add('info', 'أُعيد تشغيل جهاز MikroTik منذ الفحص السابق، فبدأت العدّادات من الصفر ولا تُقارن به.');
        }

        // ---------- فحص الأزواج ----------
        // كل زوج: «normal» سليم ومنتهٍ بجهاز، «open» مفتوح (نهاية الكابل أو قطع) على بعد X متر، «short» تماس بين سلكيه على بعد X متر.
        // الطول: أبعد مسافة مقاسة. زوج يُفتح قبل الباقي = قطع عند مسافته. وفي الفحص العميق كان الرابط قائماً قبله،
        // فالأزواج المفتوحة معاً عند المسافة نفسها هي نهاية الكابل (الطرف الآخر انفصل لأن المنفذ عُطّل أو أطفئت تغذيته).
        $deep = !empty($d['deep']);
        $prevLen = $prev['length'] ?? null;
        $length = null;
        $pairsOut = [];
        $tdr = (array) ($d['tdr'] ?? []);
        $raw = (string) ($tdr['cable-pairs'] ?? '');
        if ($raw !== '') {
            $pairs = self::pairs($raw);
            // منفذ 100 ميغا يستخدم الزوجين 1 و2 فقط
            $unused = $own !== null && $own <= 100 ? [3, 4] : [];
            $used = [];
            foreach ($pairs as $i => $p) {
                if (!in_array($i + 1, $unused, true)) {
                    $used[$i + 1] = $p;
                }
            }
            $known = array_values(array_filter(array_map(static fn ($p) => $p[1], $used), static fn ($m) => $m !== null));
            $far = $known ? max($known) : null;
            $tol = $far !== null ? max(2, (int) ceil($far * 0.05)) : 2;
            $opens = array_filter($used, static fn ($p) => $p[0] === 'open' && $p[1] !== null);
            $normals = array_filter($used, static fn ($p) => $p[0] === 'normal');
            $openM = array_map(static fn ($p) => (int) $p[1], $opens);
            $allOpenSame = $used && count($opens) === count($used) && (max($openM) - min($openM)) <= $tol;
            // الأزواج كلها مفتوحة معاً: نهاية الكابل، أو قطع للكابل كله (يُعرف بالطول المقاس سابقاً)
            $same = $allOpenSame ? (int) max($openM) : null;
            $mode = 'mixed';
            if ($same !== null) {
                $mode = match (true) {
                    $same <= 2 => $deep ? 'short-cable' : 'no-cable',
                    $deep => 'end',
                    $prevLen !== null && $same < $prevLen - max(2, (int) ceil($prevLen * 0.05)) => 'whole-cut',
                    $prevLen !== null && abs($same - $prevLen) <= max(2, (int) ceil($prevLen * 0.05)) => 'end-known',
                    default => 'open-same',
                };
            }
            foreach ($pairs as $i => [$state, $m]) {
                $no = $i + 1;
                [$pins, $color, $hex] = self::PAIRS[$no] ?? ['?', '?', '#94a3b8'];
                $cls = 'ok';
                $text = 'سليم';
                if (in_array($no, $unused, true) && $state !== 'short') {
                    $cls = 'muted';
                    $text = 'غير مستخدم في منفذ 100 ميغا';
                } elseif ($state === 'open') {
                    if ($same !== null) {
                        [$cls, $text] = match ($mode) {
                            'end', 'short-cable' => ['ok', 'سليم حتى نهايته'],
                            'end-known' => ['ok', 'سليم، طرفه الآخر مفصول'],
                            'whole-cut' => ['bad', 'مقطوع'],
                            'no-cable' => ['warn', 'لا كابل'],
                            default => ['warn', 'مفتوح عند نهايته'],
                        };
                    } elseif ($m !== null && $far !== null && $m < $far - $tol) {
                        $cls = 'bad';
                        $text = 'مقطوع';
                        $add('bad', self::pairName($no) . ' مقطوع على بعد ' . self::metres($m) . ' من الجهاز (طول الكابل نحو ' . self::metres($far) . ').', 'cut');
                    } elseif ($normals) {
                        $cls = 'bad';
                        $text = 'غير موصول بالرأس';
                        $add('bad', self::pairName($no) . ' غير موصول في الرأس على الطرف الآخر' . ($m !== null ? ' (على بعد ' . self::metres($m) . ')' : '') . ': أعد تركيب الرأس.', 'cut');
                    } elseif ($deep) {
                        // كان الرابط قائماً: مفتوح بطول الكابل = نهايته
                        $text = 'سليم حتى نهايته';
                    } else {
                        $cls = 'warn';
                        $text = 'مفتوح عند نهايته';
                    }
                } elseif ($state === 'short') {
                    $cls = 'bad';
                    $text = 'تماس';
                    $add('bad', 'تماس كهربائي في ' . self::pairName($no) . ($m !== null ? ' على بعد ' . self::metres($m) . ' من الجهاز' : '')
                        . ': سلكا الزوج متلامسان (قشرة مجروحة أو كابل مضغوط أو مبلول)، أو رأس مضغوط بترتيب خاطئ' . ($m !== null && $m <= 2 ? ' — والمسافة القريبة تعني الرأس عند الجهاز' : '') . '.', 'short');
                } elseif ($state !== 'normal') {
                    $cls = 'muted';
                    $text = 'تعذّر القياس (' . $state . ')';
                }
                $pairsOut[] = ['no' => $no, 'pins' => $pins, 'color' => $color, 'hex' => $hex, 'state' => $state, 'text' => $text, 'm' => $m, 'cls' => $cls];
            }
            $len = static fn (int $m): string => self::metres($m);
            if ($same !== null) {
                $count = count($used) === 2 ? 'الزوجان المستخدمان' : 'الأزواج الأربعة';
                match ($mode) {
                    'end' => $add('info', 'طول الكابل نحو ' . $len($same) . '، و' . $count . ' سليمة على طوله كاملاً بلا قطع ولا تماس.'),
                    'short-cable' => $add('info', 'الكابل قصير جداً (مترين أو أقل) فلا يقيس الجهاز طوله بدقة، ولا قطع ولا تماس فيه.'),
                    'end-known' => $add('warn', 'الكابل سليم حتى نهايته (نحو ' . $len($same) . ' كما قيس سابقاً) والطرف الآخر غير موصول أو جهازه مطفأ.', 'nolink'),
                    'whole-cut' => $add('bad', 'الكابل مقطوع كله على بعد ' . $len($same) . ' من الجهاز (طوله المقاس سابقاً نحو ' . $len((int) $prevLen) . ').', 'cut'),
                    'no-cable' => $add('warn', 'لا يوجد كابل موصول بالمنفذ، أو الكابل مقطوع عند الرأس مباشرة.', 'open'),
                    default => $add('warn', 'الأزواج كلها مفتوحة على المسافة نفسها (نحو ' . $len($same) . '): الطرف الآخر غير موصول أو جهازه مطفأ. إن كان طول الكابل أطول من '
                        . $len($same) . ' فهو مقطوع كله عند هذه المسافة. للتأكد افحص المنفذ فحصاً عميقاً وهو يعمل لحفظ طوله.', 'open'),
                };
                $length = in_array($mode, ['end', 'end-known'], true) ? $same : null;
            } else {
                // طول الكابل من الأزواج السليمة أو المنتهية بجهاز (لا من زوج مقطوع)
                $whole = array_filter($used, static fn ($p) => $p[1] !== null && ($p[0] === 'normal' || ($p[0] === 'open' && $far !== null && $p[1] >= $far - $tol)));
                $length = $whole ? max(array_map(static fn ($p) => (int) $p[1], $whole)) : null;
                $length = $length !== null && $length > 2 ? $length : null;
                if ($deep && $length !== null && count($normals) !== count($used)) {
                    $add('info', 'طول الكابل نحو ' . $len($length) . '.');
                }
                if ($normals && count($normals) === count($used)) {
                    if ($deep) {
                        $add('info', 'الأزواج كلها سليمة ومنتهية بجهاز يعمل' . ($length ? '، وطول الكابل نحو ' . $len($length) : ' (الجهاز في الطرف الآخر يعمل بتغذية مستقلة، فلم يُقَس الطول)') . '.');
                    } else {
                        $add('warn', 'الأزواج سليمة' . ($length ? ' وطول الكابل نحو ' . $len($length) : '') . ' لكن لا يوجد رابط: الجهاز على الطرف الآخر مطفأ أو منفذه معطّل.', 'nolink');
                    }
                }
            }
        } elseif ($deep) {
            $add('warn', 'لم يقِس الجهاز الأزواج والمنفذ معطّل مؤقتاً: شريحة هذا الجهاز تقيس فقط والكابل مفصول من طرفه الآخر. افصل الكابل من الجهاز الآخر (أو أطفئه) ثم افحص المنفذ من جديد.', 'tdr');
        } elseif (($d['tdr_skipped'] ?? null) === 'link') {
            $add('info', 'الرابط قائم، والجهاز لا يقيس الأزواج والمسافات إلا والرابط مفصول؛ لذلك بُني التشخيص على حالة الرابط والأخطاء.');
        } elseif (($d['tdr_skipped'] ?? null) === 'sfp') {
            $add('info', 'منفذ SFP: لا يُفحص بالأزواج، والتشخيص من حالة الوحدة والإشارة والأخطاء.');
        } elseif (($d['tdr_skipped'] ?? null) === 'unsupported') {
            $add('info', 'شريحة هذا المنفذ لا تدعم فحص الأزواج، والتشخيص من حالة الرابط والأخطاء.');
        }

        // ---------- حالة الرابط ----------
        $link = [];
        $sfp = [];
        if (!$disabled) {
            $link[] = ['الحالة', $linkOk ? 'متصل' : 'لا يوجد رابط'];
        }
        if ($linkOk && $mon) {
            if ($speed !== null) {
                $link[] = ['السرعة', self::speedLabel($speed) . ($own !== null && $own > $speed ? ' من أصل ' . self::speedLabel($own) : '')];
            }
            $link[] = ['الازدواج', $full ? 'كامل (full)' : 'نصف (half)'];
            if ($neg !== '') {
                $link[] = ['التفاوض التلقائي', ['done' => 'تمّ', 'failed' => 'فشل', 'disabled' => 'معطّل (سرعة يدوية)'][$neg] ?? $neg];
            }
            if ($partner !== null) {
                $link[] = ['أقصى سرعة للجهاز الآخر', self::speedLabel($partner)];
            }
            if ($neg === 'disabled') {
                $add('info', 'السرعة مضبوطة يدوياً على المنفذ (التفاوض التلقائي معطّل)، فلا تُقارن بما يدعمه.');
            } elseif ($speed !== null && $own !== null && $speed < $own) {
                if ($partner !== null && $partner >= 1000 && $own >= 1000 && $speed <= 100) {
                    $add('bad', 'المنفذ والجهاز الآخر يدعمان ' . self::speedLabel(min($own, $partner)) . ' لكن الرابط يعمل على ' . self::speedLabel($speed)
                        . ': غالباً الزوج 3 (الأزرق) أو الزوج 4 (البني) تالف أو الرأس سيء التركيب، فالجيجا تحتاج الأزواج الأربعة و100 ميغا تعمل على زوجين.', 'slow');
                } elseif ($partner !== null && $partner <= $speed) {
                    $add('info', 'الجهاز على الطرف الآخر يدعم ' . self::speedLabel($partner) . ' فقط، فالسرعة الحالية طبيعية.');
                } elseif ($speed <= 10) {
                    $add('bad', 'الرابط يعمل على 10 ميغا فقط: كابل تالف أو طويل جداً أو رأس سيء.', 'slow');
                } else {
                    $add('warn', 'المنفذ يدعم ' . self::speedLabel($own) . ' ويعمل على ' . self::speedLabel($speed) . ': إما أن الجهاز الآخر لا يدعم أكثر، أو أن زوجاً في الكابل تالف.', 'slow');
                }
            }
            if (!$full) {
                $add('bad', 'الرابط يعمل بنصف ازدواج (half-duplex): بطء وتصادمات، غالباً تفاوض فاشل أو سرعة مضبوطة يدوياً تختلف بين الطرفين.', 'half');
            } elseif ($neg === 'failed') {
                $add('warn', 'فشل التفاوض التلقائي على السرعة بين الطرفين.', 'neg');
            }
        }
        if (!$disabled && (isset($mon['sfp-module-present']) || isset($mon['sfp-rx-power']))) {
            if (($mon['sfp-module-present'] ?? 'true') === 'false') {
                $add('warn', 'لا توجد وحدة SFP في المنفذ.', 'sfp');
            } else {
                if (($mon['sfp-rx-loss'] ?? 'false') === 'true') {
                    $add('bad', 'لا تصل إشارة إلى وحدة SFP: انقطاع في الألياف أو الطرف الآخر مطفأ.', 'sfp');
                }
                $rx = isset($mon['sfp-rx-power']) && is_numeric($mon['sfp-rx-power']) ? (float) $mon['sfp-rx-power'] : null;
                if ($rx !== null && $rx < -25 && ($mon['sfp-rx-loss'] ?? 'false') !== 'true') {
                    $add('warn', 'قوة الإشارة الواصلة ضعيفة (' . $rx . ' dBm): وصلة ألياف متسخة أو مثنية أو طويلة.', 'sfp');
                }
            }
            foreach (['sfp-type' => 'النوع', 'sfp-vendor-name' => 'الشركة', 'sfp-temperature' => 'الحرارة', 'sfp-rx-power' => 'الإشارة الواصلة (dBm)',
                'sfp-tx-power' => 'الإشارة المرسلة (dBm)', 'sfp-wavelength' => 'الطول الموجي'] as $k => $label) {
                if (isset($mon[$k]) && $mon[$k] !== '') {
                    $sfp[] = [$label, (string) $mon[$k]];
                }
            }
        }

        // ---------- تغذية PoE ----------
        // poe-out-status من RouterOS: powered-on، waiting-for-load، short-circuit، overload، voltage-too-low، current-too-low، off
        $poe = (string) ($mon['poe-out-status'] ?? '');
        if (!$disabled && $poe !== '') {
            $v = isset($mon['poe-out-voltage']) && is_numeric($mon['poe-out-voltage']) ? (float) $mon['poe-out-voltage'] : null;
            $a = isset($mon['poe-out-current']) && is_numeric($mon['poe-out-current']) ? (int) $mon['poe-out-current'] : null;
            $w = isset($mon['poe-out-power']) && is_numeric($mon['poe-out-power']) ? (float) $mon['poe-out-power'] : null;
            $label = ['powered-on' => 'تعمل', 'waiting-for-load' => 'تنتظر جهازاً', 'short-circuit' => 'تماس كهربائي — موقوفة', 'overload' => 'حمل زائد — موقوفة',
                'voltage-too-low' => 'جهد منخفض', 'current-too-low' => 'تيار ضعيف', 'off' => 'مطفأة'][$poe] ?? $poe;
            $parts = array_filter([$v !== null ? $v . ' فولت' : null, $a !== null ? $a . ' ملي أمبير' : null, $w !== null ? $w . ' واط' : null]);
            $link[] = ['تغذية الكهرباء عبر الكابل (PoE)', $label . ($parts ? ' — ' . implode('، ', $parts) : '')];
            match ($poe) {
                'short-circuit' => $add('bad', 'تماس كهربائي على خط تغذية PoE: أوقف الجهاز التغذية حمايةً للمنفذ. السبب سلكان متلامسان في الكابل، أو رأس مبلول أو مضغوط خطأ، أو عطل في الجهاز الآخر.', 'poe'),
                'overload' => $add('bad', 'حمل زائد على تغذية PoE: الجهاز الآخر يسحب أكثر من قدرة المنفذ، أو في الكابل تسريب كهربائي.', 'poe'),
                'voltage-too-low' => $add('warn', 'جهد تغذية PoE منخفض: مزوّد الكهرباء للجهاز ضعيف، أو الكابل طويل جداً أو أسلاكه رفيعة (ليست نحاساً خالصاً).', 'poe'),
                'current-too-low' => $add('warn', 'التيار المسحوب عبر PoE ضعيف جداً: الجهاز الآخر لا يعمل، أو في الكابل قطع يمنع التغذية.', 'poe'),
                'waiting-for-load' => $linkOk ? null : $add('info', 'المنفذ جاهز لتغذية جهاز بالكهرباء وينتظر أن يُوصل.'),
                default => null,
            };
        }

        // ---------- نتيجة الفحص العميق على الرابط ----------
        if ($deep) {
            if (!empty($d['link_back'])) {
                $add('info', 'أُعيد تشغيل المنفذ وعاد الرابط بعد الفحص مباشرة.');
            } elseif ($poe === 'powered-on') {
                $add('info', 'أُعيد تشغيل المنفذ، والجهاز الآخر يقلع من جديد لأن المنفذ يغذّيه بالكهرباء (PoE): يعود رابطه عادة خلال دقيقة أو دقيقتين.');
            } else {
                $add('warn', 'أُعيد تشغيل المنفذ لكن الرابط لم يعد بعد: تحقّق منه بعد دقيقة بفحص عادي.', 'back');
            }
        }

        // ---------- عدّادات الأخطاء ----------
        $s1 = (array) ($d['stats1'] ?? []);
        $s2 = (array) ($d['stats2'] ?? []);
        $prevStats = $comparable ? (array) $prev['stats'] : [];
        $gap = (int) ($d['gap'] ?? 0);
        $counters = [];
        $now = ['cable' => [], 'duplex' => []];
        $nowSum = ['cable' => 0, 'duplex' => 0];
        $since = ['cable' => 0, 'duplex' => 0];
        $total = 0;
        foreach (self::COUNTERS as $k => [$label, $group]) {
            if (!array_key_exists($k, $s2) && !array_key_exists($k, $s1)) {
                continue;
            }
            $v2 = (int) ($s2[$k] ?? $s1[$k]);
            $dNow = $gap > 0 && array_key_exists($k, $s1) && array_key_exists($k, $s2) ? max(0, (int) $s2[$k] - (int) $s1[$k]) : null;
            $dSince = array_key_exists($k, $prevStats) && (int) $prevStats[$k] <= $v2 ? $v2 - (int) $prevStats[$k] : null;
            $counters[] = ['key' => $k, 'label' => $label, 'group' => $group, 'total' => $v2, 'now' => $dNow, 'since' => $dSince];
            if ($group === 'other') {
                continue;
            }
            $total += $v2;
            if ($dNow) {
                $now[$group][] = $label . ' +' . num($dNow);
                $nowSum[$group] += $dNow;
            }
            $since[$group] += (int) $dSince;
        }
        $min = max(1, Settings::int('cable.errors_min', 20));
        if ($nowSum['cable'] >= 3) {
            $add('bad', 'أخطاء الاستقبال تزيد الآن أثناء الفحص (' . implode('، ', $now['cable']) . ' خلال ' . $gap . ' ثوانٍ): المشكلة غالباً في الكابل أو الرأس حتى لو بدت الأزواج سليمة.', 'errors');
        } elseif ($since['cable'] >= $min) {
            $add('bad', 'زادت أخطاء الاستقبال ' . num($since['cable']) . ' منذ الفحص السابق (' . $ago . '): المشكلة غالباً في الكابل أو الرأس حتى لو بدت الأزواج سليمة.', 'errors');
        } elseif ($nowSum['cable'] > 0) {
            $add('warn', 'ظهرت أخطاء استقبال قليلة أثناء الفحص (' . implode('، ', $now['cable']) . '): افحص الرأس وأعد الفحص بعد قليل.', 'errors');
        } elseif ($since['cable'] > 0) {
            $add('warn', 'زادت أخطاء الاستقبال قليلاً منذ الفحص السابق (' . num($since['cable']) . ').', 'errors');
        } elseif ($total > 0) {
            $still = array_filter([$gap > 0 ? 'أثناء الفحص' : null, $comparable ? 'منذ الفحص السابق' : null]);
            $add('info', 'يوجد ' . ar_plural($total, 'خطأ واحد', 'خطآن', 'أخطاء', 'خطأً') . ' مسجّل منذ تشغيل الجهاز' . ($still ? '، ولم تزد ' . implode(' ولا ', $still) : '') . '.');
        }
        if ($linkOk && $full && ($nowSum['duplex'] > 0 || $since['duplex'] > 0)) {
            $add('bad', 'تصادمات على رابط بازدواج كامل: إعداد الازدواج مختلف بين الطرفين (أحدهما مضبوط يدوياً).', 'collisions');
        }
        $rxPackets = (int) ($s2['rx-packet'] ?? 0);
        $fcs = (int) ($s2['rx-fcs-error'] ?? 0);
        if ($rxPackets >= 10000 && $fcs > 0 && $fcs / $rxPackets >= 0.001) {
            $add('warn', 'نسبة الإطارات التالفة ' . number_format($fcs / $rxPackets * 100, 2) . '% من كل ما استقبله المنفذ منذ تشغيل الجهاز.', 'errors');
        }

        // ---------- انقطاعات الرابط ----------
        $downs = (int) ($d['link_downs'] ?? 0);
        $sinceDowns = $comparable && $downs >= (int) $prev['link_downs'] ? $downs - (int) $prev['link_downs'] : null;
        $uptime = isset($d['uptime']) ? (int) $d['uptime'] : null;
        $links = [['مرات الانقطاع منذ تشغيل الجهاز', num($downs)]];
        if ($uptime) {
            $links[] = ['الجهاز يعمل منذ', fmt_duration($uptime)];
        }
        if ($sinceDowns !== null) {
            $links[] = ['منذ الفحص السابق (' . $ago . ')', num($sinceDowns)];
        }
        if (($d['last_down'] ?? '') !== '') {
            $links[] = ['آخر انقطاع', (string) $d['last_down']];
        }
        if (($d['last_up'] ?? '') !== '') {
            $links[] = ['آخر عودة', (string) $d['last_up']];
        }
        $lastDown = ($d['last_down'] ?? '') !== '' ? '، آخرها ' . $d['last_down'] : '';
        $perDay = $uptime ? $downs / max(1.0, $uptime / 86400) : null;
        $downsMin = max(1, Settings::int('cable.downs_min', 5));
        if ($sinceDowns !== null && $sinceDowns >= 3 && $hours !== null && $hours <= 168) {
            $add('bad', 'انقطع الرابط ' . self::times($sinceDowns) . ' منذ الفحص السابق (' . $ago . ')' . $lastDown . ': تقطيع متكرر — رأس مرتخٍ أو كابل متضرر أو ضعف في تغذية PoE.', 'flaps');
        } elseif ($downs >= $downsMin && $perDay !== null && $perDay >= 3) {
            $add('bad', 'انقطع الرابط ' . self::times($downs) . ' خلال ' . fmt_duration((int) $uptime) . ' (نحو ' . num($perDay) . ' يومياً)' . $lastDown . ': تقطيع متكرر — رأس مرتخٍ أو كابل متضرر أو ضعف في تغذية PoE.', 'flaps');
        } elseif ($sinceDowns !== null && $sinceDowns > 0 && $linkOk) {
            $add('warn', 'انقطع الرابط ' . self::times($sinceDowns) . ' منذ الفحص السابق' . $lastDown . ' ثم عاد.', 'downs');
        } elseif ($downs >= $downsMin) {
            $add('warn', 'انقطع الرابط ' . self::times($downs) . ' منذ تشغيل الجهاز' . $lastDown . '.', 'downs');
        }

        if (!$linkOk && !$disabled && $raw === '') {
            $add('warn', 'لا يوجد رابط على المنفذ: لا كابل موصول، أو الجهاز على الطرف الآخر مطفأ.', 'nolink');
        }

        usort($f, static fn ($a, $b) => self::RANK[$b['level']] <=> self::RANK[$a['level']]);
        $level = 'ok';
        foreach ($f as $x) {
            if (self::RANK[$x['level']] > self::RANK[$level]) {
                $level = $x['level'];
            }
        }
        $headline = match (true) {
            $level !== 'ok' => $f[0]['text'],
            $length !== null => 'الكابل سليم وطوله نحو ' . self::metres($length) . ': لا قطع ولا تماس' . ($speed !== null && $linkOk ? '، والرابط على ' . self::speedLabel($speed) : '') . '.',
            default => 'المنفذ سليم' . ($speed !== null ? ' ويعمل على ' . self::speedLabel($speed) : '') . '، بلا أخطاء تزيد ولا تقطيع.',
        };
        return ['level' => $level, 'headline' => $headline, 'findings' => $f, 'pairs' => $pairsOut, 'wires' => self::wires($pairsOut), 'link' => $link, 'counters' => $counters, 'links' => $links,
            'sfp' => $sfp, 'port' => (string) $d['port'], 'comment' => (string) ($d['comment'] ?? ''), 'running' => (bool) ($d['running'] ?? false),
            'length' => $length, 'prev_length' => $prevLen, 'deep' => $deep, 'peer' => $d['peer'] ?? null, 'at' => now()];
    }
}
