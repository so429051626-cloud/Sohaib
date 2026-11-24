<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;
use Throwable;

/**
 * مزامنة جهاز MikroTik واحد: الحالة، المتصلون، الاستهلاك، أسماء الأجهزة، المهام المعلّقة.
 */
final class RouterSync
{
    /**
     * @return array{ok:bool, error:?string, stats:array}
     */
    public static function run(array $router, bool $full = false): array
    {
        $started = microtime(true);
        $now = time();
        $stats = [];
        $gw = Gateway::for($router);
        try {
            $res = $gw->resource();
            $update = [
                'uptime_seconds' => $res['uptime_seconds'],
                'version'        => $res['version'] ?: $router['version'],
                'ros_major'      => $res['ros_major'] ?: $router['ros_major'],
                'cpu_load'       => $res['cpu_load'],
                'cpu_count'      => $res['cpu_count'],
                'memory_total'   => $res['memory_total'],
                'memory_free'    => $res['memory_free'],
                'hdd_total'      => $res['hdd_total'],
                'hdd_free'       => $res['hdd_free'],
                'architecture'   => $res['architecture'],
                'board_name'     => $res['board_name'],
            ];
            $router['ros_major'] = $update['ros_major'];

            $health = $gw->health();
            $update['temperature'] = $health['temperature'];
            $update['voltage'] = $health['voltage'];

            $infoAge = $router['info_synced_at'] ? $now - (int) strtotime($router['info_synced_at']) : PHP_INT_MAX;
            if ($full || $infoAge > 3600 || !$router['identity']) {
                $update['identity'] = $gw->identity();
                $rb = $gw->routerboard();
                $update['model'] = $rb['model'] ?? $router['model'];
                $update['serial_number'] = $rb['serial_number'] ?? $router['serial_number'];
                $update['info_synced_at'] = now();
                $dirs = [];
                try {
                    $dirs = $gw->hotspotHtmlDirectories();
                } catch (MikroTikException $e) {
                    if ($e->isConnection()) {
                        throw $e;
                    }
                }
                if ($dirs) {
                    $update['hotspot_dir'] = $dirs[0];
                }
            }

            // المتصلون
            $hsActive = self::safe(static fn () => $gw->hotspotActive());
            $pppActive = self::safe(static fn () => $gw->pppActive());
            $pppTraffic = $pppActive ? self::safe(static fn () => $gw->pppTraffic()) : [];
            $update['active_hotspot'] = count($hsActive);
            $update['active_ppp'] = count($pppActive);

            $hs = SessionTracker::sync((int) $router['id'], 'hotspot', $hsActive);
            $pppRows = array_map(static fn ($p) => [
                'id' => $p['id'], 'user' => $p['name'], 'mac' => $p['mac'], 'address' => $p['address'], 'uptime' => $p['uptime'],
                'bytes_in' => $pppTraffic[$p['name']]['rx'] ?? 0, 'bytes_out' => $pppTraffic[$p['name']]['tx'] ?? 0, 'login_by' => $p['service'],
            ], $pppActive);
            $ppp = SessionTracker::sync((int) $router['id'], 'ppp', $pppRows);
            $stats['sessions'] = ['hotspot' => $hs, 'ppp' => $ppp];

            // استهلاك الكروت من عدّادات مستخدمي الهوتسبوت
            $changedCards = self::syncHotspotUsers($gw, $router);
            $update['users_synced_at'] = now();
            $stats['cards_changed'] = count($changedCards);

            // أسماء الأجهزة من DHCP
            $leasesAge = $router['leases_synced_at'] ? $now - (int) strtotime($router['leases_synced_at']) : PHP_INT_MAX;
            if ($full || $leasesAge >= max(1, Settings::int('sync.leases_interval', 5)) * 60 - 20) {
                $leases = self::safe(static fn () => $gw->leases());
                self::storeLeases((int) $router['id'], $leases);
                $update['leases_synced_at'] = now();
                $stats['leases'] = count($leases);
            }

            // سرعة واجهة الإنترنت
            if ($router['wan_interface']) {
                $t = self::safe(static fn () => $gw->traffic((string) $router['wan_interface']), ['rx' => null, 'tx' => null]);
                $update['rx_bps'] = $t['rx'];
                $update['tx_bps'] = $t['tx'];
            }

            // إنفاذ الانتهاء للكروت المتغيرة فوراً على هذا الجهاز
            Enforcer::cards(array_merge($changedCards, $hs['cards']), [(int) $router['id'] => $gw]);

            // المهام المعلّقة
            $stats['tasks'] = RouterTasks::processRouter($gw, $router, 15.0);

            $wasOffline = $router['status'] === 'offline';
            $update += [
                'status'         => 'online',
                'fail_count'     => 0,
                'last_seen_at'   => now(),
                'last_polled_at' => now(),
                'last_error'     => null,
                'next_poll_at'   => date('Y-m-d H:i:s', $now + max(1, Settings::int('sync.interval', 1)) * 60 - 10),
                'updated_at'     => now(),
            ];
            if ($router['status'] !== 'online') {
                $update['status_changed_at'] = now();
            }
            self::metrics($router, $update, true);
            DB::update('routers', $update, 'id = ?', [$router['id']]);
            // كشف الاستهلاك الزائد بعد تحديث سرعات الجلسات وحمل الجهاز
            self::guard(static fn () => UsageGuard::check($update + $router));
            self::guard(static fn () => PowerMonitor::record($router, $update['voltage'] !== null ? (float) $update['voltage'] : null, $update['temperature'] !== null ? (float) $update['temperature'] : null));

            if ($wasOffline) {
                self::guard(static fn () => OutageService::closeAuto($router));
            }
            if ($wasOffline && Settings::bool('notify.router_up')) {
                Notifier::alert('router_up', 'success', 'عاد جهاز MikroTik ' . $router['name'] . ' للعمل', null, (int) $router['id']);
            }
            self::checkLoad($router, $update);
            $gw->close();
            $stats['duration'] = round(microtime(true) - $started, 2);
            return ['ok' => true, 'error' => null, 'stats' => $stats];
        } catch (MikroTikException $e) {
            $gw->close();
            self::markFailure($router, $e->friendly(), $e->isConnection());
            return ['ok' => false, 'error' => $e->friendly(), 'stats' => $stats];
        } catch (Throwable $e) {
            $gw->close();
            log_message('error', 'RouterSync ' . $router['id'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            DB::update('routers', ['last_error' => mb_substr($e->getMessage(), 0, 255), 'last_polled_at' => now(), 'next_poll_at' => date('Y-m-d H:i:s', $now + 120)], 'id = ?', [$router['id']]);
            return ['ok' => false, 'error' => $e->getMessage(), 'stats' => $stats];
        }
    }

    /** خطوة جانبية لا يجوز أن تُفشل المزامنة (سجل الطاقة والانقطاعات) */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            log_message('error', 'RouterSync side step: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /** تنفيذ قراءة اختيارية: الأخطاء المنطقية (قائمة غير مدعومة) تعيد قيمة افتراضية */
    private static function safe(callable $fn, mixed $default = []): mixed
    {
        try {
            return $fn();
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
            return $default;
        }
    }

    /**
     * قراءة عدّادات مستخدمي الهوتسبوت المُدارة من النظام وتحديث استهلاك الكروت.
     * @return int[] الكروت التي تغيّر استهلاكها
     */
    public static function syncHotspotUsers(Gateway $gw, array $router): array
    {
        $routerId = (int) $router['id'];
        $users = self::safe(static fn () => $gw->hotspotUsers());
        $cardUsers = [];
        $subUsers = [];
        foreach ($users as $u) {
            $tag = Provisioner::parseTag((string) $u['comment']);
            if (!$tag) {
                continue;
            }
            if ($tag[0] === 'card') {
                $cardUsers[$tag[1]] = $u;
            } else {
                $subUsers[$tag[1]] = $u;
            }
        }

        // كروت حُذفت من النظام وما زالت على جهاز MikroTik
        $validIds = [];
        foreach (array_chunk(array_keys($cardUsers), 500) as $chunk) {
            foreach (DB::column('SELECT id FROM cards WHERE id IN (' . DB::in($chunk) . ')', $chunk) as $id) {
                $validIds[(int) $id] = true;
            }
        }
        foreach ($cardUsers as $cardId => $u) {
            if (!isset($validIds[$cardId])) {
                try {
                    $gw->disconnectHotspotUser($u['name']);
                    $gw->removeHotspotUser($u['name']);
                } catch (MikroTikException $e) {
                    if ($e->isConnection()) {
                        throw $e;
                    }
                }
                unset($cardUsers[$cardId]);
            }
        }

        $existing = [];
        foreach (DB::rows('SELECT * FROM card_routers WHERE router_id = ?', [$routerId]) as $r) {
            $existing[(int) $r['card_id']] = $r;
        }
        $changed = [];
        $now = now();
        $inserts = [];
        DB::transaction(static function () use ($cardUsers, $existing, $routerId, $now, &$changed, &$inserts) {
            foreach ($cardUsers as $cardId => $u) {
                $row = $existing[$cardId] ?? null;
                $uptime = (int) $u['uptime'];
                $in = (int) $u['bytes_in'];
                $out = (int) $u['bytes_out'];
                if (!$row) {
                    $inserts[] = [
                        'card_id' => $cardId, 'router_id' => $routerId, 'state' => 'present',
                        'uptime' => $uptime, 'bytes_in' => $in, 'bytes_out' => $out,
                        'carry_uptime' => 0, 'carry_bytes_in' => 0, 'carry_bytes_out' => 0,
                        'provisioned_at' => $now, 'synced_at' => $now,
                    ];
                    $changed[] = $cardId;
                    continue;
                }
                $same = $uptime === (int) $row['uptime'] && $in === (int) $row['bytes_in'] && $out === (int) $row['bytes_out'];
                if ($same && $row['state'] === 'present') {
                    continue;
                }
                $carry = [(int) $row['carry_uptime'], (int) $row['carry_bytes_in'], (int) $row['carry_bytes_out']];
                if ($uptime < (int) $row['uptime'] || $in < (int) $row['bytes_in'] || $out < (int) $row['bytes_out']) {
                    $carry[0] += (int) $row['uptime'];
                    $carry[1] += (int) $row['bytes_in'];
                    $carry[2] += (int) $row['bytes_out'];
                }
                DB::update('card_routers', [
                    'state' => 'present', 'uptime' => $uptime, 'bytes_in' => $in, 'bytes_out' => $out,
                    'carry_uptime' => $carry[0], 'carry_bytes_in' => $carry[1], 'carry_bytes_out' => $carry[2], 'synced_at' => $now,
                ], 'card_id = ? AND router_id = ?', [$cardId, $routerId]);
                if (!$same) {
                    $changed[] = $cardId;
                }
            }
            foreach (array_chunk($inserts, 300) as $chunk) {
                DB::insertMany('card_routers', $chunk);
            }
            // كروت كانت على جهاز MikroTik واختفت (حُذفت يدوياً مثلاً)
            foreach ($existing as $cardId => $row) {
                if ($row['state'] === 'present' && !isset($cardUsers[$cardId])) {
                    DB::update('card_routers', ['state' => 'removed', 'synced_at' => $now], 'card_id = ? AND router_id = ?', [$cardId, $routerId]);
                }
            }
        });
        if ($changed) {
            CardUsage::recompute($changed);
        }

        // مشتركو الهوتسبوت
        if ($subUsers) {
            $ids = array_keys($subUsers);
            $valid = array_flip(array_map('intval', DB::column('SELECT id FROM subscribers WHERE id IN (' . DB::in($ids) . ')', $ids)));
            foreach ($subUsers as $subId => $u) {
                if (!isset($valid[$subId])) {
                    continue;
                }
                DB::query(
                    'INSERT INTO subscriber_routers (subscriber_id, router_id, state, uptime, bytes_in, bytes_out, synced_at) VALUES (?, ?, \'present\', ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE state = \'present\', uptime = VALUES(uptime), bytes_in = VALUES(bytes_in), bytes_out = VALUES(bytes_out), synced_at = VALUES(synced_at)',
                    [$subId, $routerId, (int) $u['uptime'], (int) $u['bytes_in'], (int) $u['bytes_out'], $now]
                );
            }
        }
        return array_values(array_unique($changed));
    }

    public static function storeLeases(int $routerId, array $leases): void
    {
        $now = now();
        $rows = [];
        foreach ($leases as $l) {
            if (!$l['mac']) {
                continue;
            }
            $rows[$l['mac']] = [
                'mac' => $l['mac'],
                'hostname' => $l['host_name'] !== '' ? mb_substr($l['host_name'], 0, 128) : null,
                'last_ip' => $l['address'] !== '' ? $l['address'] : null,
                'router_id' => $routerId,
                'first_seen' => $now,
                'last_seen' => $now,
            ];
        }
        foreach (array_chunk(array_values($rows), 300) as $chunk) {
            DB::insertMany('devices', $chunk, 'ON DUPLICATE KEY UPDATE hostname = COALESCE(VALUES(hostname), hostname), last_ip = COALESCE(VALUES(last_ip), last_ip), router_id = VALUES(router_id), last_seen = VALUES(last_seen)');
        }
    }

    private static function metrics(array $router, array $update, bool $online): void
    {
        $interval = max(1, Settings::int('sync.metrics_interval', 5)) * 60;
        $last = $router['metrics_at'] ? (int) strtotime($router['metrics_at']) : 0;
        if (time() - $last < $interval - 20) {
            return;
        }
        $memPct = null;
        if (!empty($update['memory_total']) && isset($update['memory_free'])) {
            $memPct = (int) round(100 - ($update['memory_free'] / $update['memory_total']) * 100);
        }
        DB::insert('router_metrics', [
            'router_id'       => $router['id'],
            'created_at'      => now(),
            'online'          => $online ? 1 : 0,
            'cpu_load'        => $update['cpu_load'] ?? null,
            'memory_used_pct' => $memPct,
            'active_hotspot'  => $update['active_hotspot'] ?? null,
            'active_ppp'      => $update['active_ppp'] ?? null,
            'rx_bps'          => $update['rx_bps'] ?? null,
            'tx_bps'          => $update['tx_bps'] ?? null,
        ]);
        DB::update('routers', ['metrics_at' => now()], 'id = ?', [$router['id']]);
    }

    private static function checkLoad(array $router, array $update): void
    {
        $threshold = Settings::int('notify.cpu_threshold', 90);
        if (($update['cpu_load'] ?? 0) >= $threshold) {
            Notifier::alert('router_cpu', 'warning', 'حِمل المعالج مرتفع على ' . $router['name'], 'الحِمل الحالي ' . $update['cpu_load'] . '%', (int) $router['id'], 'cpu:' . $router['id'], 60);
        }
        if (!empty($update['memory_total']) && isset($update['memory_free']) && $update['memory_free'] / $update['memory_total'] < 0.08) {
            Notifier::alert('router_mem', 'warning', 'الذاكرة المتاحة منخفضة على ' . $router['name'], fmt_bytes($update['memory_free']) . ' متاحة', (int) $router['id'], 'mem:' . $router['id'], 180);
        }
    }

    private static function markFailure(array $router, string $error, bool $connection): void
    {
        $fails = (int) $router['fail_count'] + 1;
        $threshold = max(1, Settings::int('sync.offline_after', 2));
        $interval = max(1, Settings::int('sync.interval', 1)) * 60;
        $backoff = min(600, $interval * (2 ** min(4, max(0, $fails - $threshold))));
        $update = [
            'fail_count'     => $fails,
            'last_error'     => mb_substr($error, 0, 255),
            'last_polled_at' => now(),
            'next_poll_at'   => date('Y-m-d H:i:s', time() + (int) $backoff - 10),
        ];
        if ($connection && $fails >= $threshold && $router['status'] !== 'offline') {
            $update['status'] = 'offline';
            $update['status_changed_at'] = now();
            $update['active_hotspot'] = 0;
            $update['active_ppp'] = 0;
            SessionTracker::closeAll((int) $router['id'], 'router_offline');
            if (Settings::bool('notify.router_down')) {
                Notifier::alert('router_down', 'danger', 'انقطع الاتصال بجهاز MikroTik ' . $router['name'], $error, (int) $router['id']);
            }
            DB::insert('router_metrics', ['router_id' => $router['id'], 'created_at' => now(), 'online' => 0]);
            self::guard(static fn () => OutageService::openAuto($update + $router));
        }
        DB::update('routers', $update, 'id = ?', [$router['id']]);
    }
}
