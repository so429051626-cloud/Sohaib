<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * تتبع الجلسات من قوائم المتصلين في جهاز MikroTik وأحداث الدخول/الخروج.
 * الجلسة المفتوحة فريدة بـ (جهاز MikroTik، الخدمة، المستخدم، الجهاز).
 */
final class SessionTracker
{
    /** حلّ اسم مستخدم جهاز MikroTik إلى كرت أو مشترك */
    public static function owners(array $usernames): array
    {
        $usernames = array_values(array_unique(array_filter($usernames, static fn ($u) => $u !== '')));
        $out = [];
        foreach (array_chunk($usernames, 500) as $chunk) {
            foreach (DB::rows('SELECT login, owner_type, owner_id FROM logins WHERE owner_type IN (\'card\', \'subscriber\') AND login IN (' . DB::in($chunk) . ')', $chunk) as $r) {
                $out[$r['login']] = [$r['owner_type'], (int) $r['owner_id']];
            }
        }
        return $out;
    }

    /**
     * مزامنة جلسات خدمة على جهاز MikroTik من قائمة المتصلين الحالية.
     * @param array $actives صفوف موحّدة: user, mac, address, uptime, bytes_in, bytes_out, id, login_by
     * @return array{opened:int, closed:int, cards:int[], subscribers:int[]}
     */
    public static function sync(int $routerId, string $service, array $actives): array
    {
        $now = time();
        $nowStr = date('Y-m-d H:i:s', $now);
        $open = [];
        foreach (DB::rows('SELECT * FROM sessions WHERE router_id = ? AND service = ? AND stopped_at IS NULL', [$routerId, $service]) as $s) {
            $open[$s['username'] . '|' . ($s['mac'] ?? '')] = $s;
        }
        $owners = self::owners(array_column($actives, 'user'));
        // كشف الاستهلاك الزائد: حد سرعة التنزيل (بت/ثانية)، صفر = موقوف
        $heavy = UsageGuard::threshold();
        $seen = [];
        $opened = 0;
        $cards = [];
        $subs = [];

        foreach ($actives as $a) {
            $user = (string) $a['user'];
            if ($user === '') {
                continue;
            }
            $mac = $a['mac'] !== '' ? $a['mac'] : null;
            $key = $user . '|' . ($mac ?? '');
            $seen[$key] = true;
            [$type, $ownerId] = $owners[$user] ?? [null, null];
            $cardId = $type === 'card' ? $ownerId : null;
            $subId = $type === 'subscriber' ? $ownerId : null;
            if ($cardId) {
                $cards[$cardId] = $cardId;
            }
            if ($subId) {
                $subs[$subId] = $subId;
            }
            $uptime = (int) $a['uptime'];
            if (isset($open[$key])) {
                $s = $open[$key];
                // انخفاض مدة الجلسة يعني جلسة جديدة بعد خروج لم يُلتقط
                if ($uptime + 90 < (int) $s['uptime']) {
                    DB::update('sessions', ['stopped_at' => $s['updated_at'], 'stop_reason' => 'reconnect'], 'id = ?', [$s['id']]);
                    unset($open[$key]);
                } else {
                    $update = [
                        'ip'         => $a['address'] ?: $s['ip'],
                        'active_ref' => $a['id'] ?? $s['active_ref'],
                        'uptime'     => $uptime,
                        'bytes_in'   => (int) $a['bytes_in'],
                        'bytes_out'  => (int) $a['bytes_out'],
                        'updated_at' => $nowStr,
                        'card_id'    => $cardId ?? $s['card_id'],
                        'subscriber_id' => $subId ?? $s['subscriber_id'],
                    ];
                    // السرعة الحالية من فرق العدّادات منذ آخر مزامنة (bytes_out = ما نزل إلى المستخدم)
                    $elapsed = $now - (int) strtotime((string) $s['updated_at']);
                    $dOut = (int) $a['bytes_out'] - (int) $s['bytes_out'];
                    $dIn = (int) $a['bytes_in'] - (int) $s['bytes_in'];
                    if ($elapsed >= 15 && $elapsed <= 900 && $dOut >= 0 && $dIn >= 0) {
                        $update['rate_down'] = (int) round($dOut * 8 / $elapsed);
                        $update['rate_up'] = (int) round($dIn * 8 / $elapsed);
                        if ($heavy > 0 && $update['rate_down'] >= $heavy) {
                            $update['heavy_since'] = $s['heavy_since'] ?? $s['updated_at'];
                        } else {
                            $update['heavy_since'] = null;
                            $update['heavy_alerted'] = 0;
                        }
                    }
                    DB::update('sessions', $update, 'id = ?', [$s['id']]);
                    continue;
                }
            }
            DB::insert('sessions', [
                'router_id'     => $routerId,
                'service'       => $service,
                'username'      => $user,
                'card_id'       => $cardId,
                'subscriber_id' => $subId,
                'mac'           => $mac,
                'ip'            => $a['address'] ?: null,
                'active_ref'    => $a['id'] ?? null,
                'login_by'      => $a['login_by'] ?? null,
                'started_at'    => date('Y-m-d H:i:s', $now - $uptime),
                'updated_at'    => $nowStr,
                'uptime'        => $uptime,
                'bytes_in'      => (int) $a['bytes_in'],
                'bytes_out'     => (int) $a['bytes_out'],
            ]);
            $opened++;
            self::touchOwner($type, $ownerId, $routerId, $mac, $a['address'] ?: null, date('Y-m-d H:i:s', $now - $uptime));
            if ($mac) {
                self::touchDevice($mac, null, $a['address'] ?: null, $routerId);
            }
        }

        $closed = 0;
        foreach ($open as $key => $s) {
            if (!isset($seen[$key])) {
                DB::update('sessions', ['stopped_at' => $s['updated_at'] > $nowStr ? $nowStr : $s['updated_at'], 'stop_reason' => $s['stop_reason'] ?? 'logout'], 'id = ?', [$s['id']]);
                $closed++;
                if ($s['card_id']) {
                    $cards[(int) $s['card_id']] = (int) $s['card_id'];
                }
            }
        }
        return ['opened' => $opened, 'closed' => $closed, 'cards' => array_values($cards), 'subscribers' => array_values($subs)];
    }

    /** تحديث آخر ظهور للكرت/المشترك، وتسجيل أول دخول للكرت إن لم يُسجَّل */
    public static function touchOwner(?string $type, ?int $id, int $routerId, ?string $mac, ?string $ip, string $at): void
    {
        if (!$type || !$id) {
            return;
        }
        if ($type === 'card') {
            $card = DB::row('SELECT c.*, cat.validity, cat.lock_mac FROM cards c JOIN categories cat ON cat.id = c.category_id WHERE c.id = ?', [$id]);
            if (!$card) {
                return;
            }
            $update = ['last_login_at' => $at, 'last_mac' => $mac, 'last_ip' => $ip, 'last_router_id' => $routerId];
            if (!$card['first_login_at']) {
                $update['first_login_at'] = $at;
                $update['status'] = 'active';
                $validity = $card['validity'] !== null ? (int) $card['validity'] + (int) $card['bonus_validity'] : null;
                if ($validity !== null && !$card['expires_at']) {
                    $update['expires_at'] = date('Y-m-d H:i:s', strtotime($at) + $validity);
                }
            }
            if ($mac && (int) $card['lock_mac'] === 1 && !$card['bound_mac']) {
                $update['bound_mac'] = $mac;
            }
            DB::update('cards', $update, 'id = ?', [$id]);
        } else {
            $sub = DB::row('SELECT lock_mac, bound_mac FROM subscribers WHERE id = ?', [$id]);
            if (!$sub) {
                return;
            }
            $update = ['last_online_at' => $at, 'last_mac' => $mac, 'last_ip' => $ip, 'last_router_id' => $routerId];
            if ($mac && (int) $sub['lock_mac'] === 1 && !$sub['bound_mac']) {
                $update['bound_mac'] = $mac;
            }
            DB::update('subscribers', $update, 'id = ?', [$id]);
        }
    }

    public static function touchDevice(string $mac, ?string $hostname, ?string $ip, ?int $routerId): void
    {
        $now = now();
        DB::query(
            'INSERT INTO devices (mac, hostname, last_ip, router_id, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE hostname = COALESCE(VALUES(hostname), hostname), last_ip = COALESCE(VALUES(last_ip), last_ip),
                                     router_id = COALESCE(VALUES(router_id), router_id), last_seen = VALUES(last_seen)',
            [$mac, $hostname !== '' ? $hostname : null, $ip, $routerId, $now, $now]
        );
    }

    /** حدث دخول/خروج مُرسل من سكربت جهاز MikroTik */
    public static function event(int $routerId, string $event, string $user, ?string $mac, ?string $ip): void
    {
        $owners = self::owners([$user]);
        [$type, $ownerId] = $owners[$user] ?? [null, null];
        $nowStr = now();
        $open = DB::row(
            'SELECT * FROM sessions WHERE router_id = ? AND service = \'hotspot\' AND username = ? AND stopped_at IS NULL AND ' . ($mac ? 'mac = ?' : 'mac IS NULL') . ' ORDER BY id DESC LIMIT 1',
            $mac ? [$routerId, $user, $mac] : [$routerId, $user]
        );
        if ($event === 'in') {
            if (!$open) {
                DB::insert('sessions', [
                    'router_id' => $routerId, 'service' => 'hotspot', 'username' => $user,
                    'card_id' => $type === 'card' ? $ownerId : null, 'subscriber_id' => $type === 'subscriber' ? $ownerId : null,
                    'mac' => $mac, 'ip' => $ip, 'started_at' => $nowStr, 'updated_at' => $nowStr,
                ]);
            }
            self::touchOwner($type, $ownerId, $routerId, $mac, $ip, $nowStr);
            if ($mac) {
                self::touchDevice($mac, null, $ip, $routerId);
            }
        } elseif ($open) {
            DB::update('sessions', ['stopped_at' => $nowStr, 'stop_reason' => 'logout'], 'id = ?', [$open['id']]);
        }
        DB::insert('login_log', [
            'created_at' => $nowStr, 'router_id' => $routerId, 'source' => 'router', 'event' => $event === 'in' ? 'login' : 'logout',
            'login' => mb_substr($user, 0, 64), 'mac' => $mac, 'ip' => $ip, 'result' => 'info', 'reason' => null,
            'card_id' => $type === 'card' ? $ownerId : null, 'subscriber_id' => $type === 'subscriber' ? $ownerId : null,
        ]);
    }

    /** إغلاق كل الجلسات المفتوحة لجهاز MikroTik (عند انقطاعه أو إعادة تشغيله) */
    public static function closeAll(int $routerId, string $reason): void
    {
        DB::query('UPDATE sessions SET stopped_at = updated_at, stop_reason = ? WHERE router_id = ? AND stopped_at IS NULL', [$reason, $routerId]);
    }
}
