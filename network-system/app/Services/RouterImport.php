<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\MikroTik\Duration;
use App\MikroTik\Gateway;

/**
 * استيراد المستخدمين الموجودين على جهاز MikroTik إلى النظام (كروت هوتسبوت أو مشتركي PPP).
 */
final class RouterImport
{
    private const CHUNK = 100;

    private static function router(int $id): array
    {
        $r = DB::row('SELECT * FROM routers WHERE id = ?', [$id]);
        if (!$r) {
            throw new \RuntimeException('جهاز MikroTik غير موجود.');
        }
        return $r;
    }

    /** قائمة ملفات المستخدمين على جهاز MikroTik مع عدد المستخدمين غير المُدارين لكل ملف */
    public static function hotspotProfiles(Gateway $gw): array
    {
        $out = [];
        foreach ($gw->hotspotUsers() as $u) {
            if ($u['dynamic'] || Provisioner::parseTag($u['comment']) || $u['name'] === 'default-trial') {
                continue;
            }
            $out[$u['profile']] = ($out[$u['profile']] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    public static function pppProfiles(Gateway $gw): array
    {
        $out = [];
        foreach ($gw->pppSecrets() as $s) {
            if (Provisioner::parseTag((string) ($s['comment'] ?? ''))) {
                continue;
            }
            $p = (string) ($s['profile'] ?? 'default');
            $out[$p] = ($out[$p] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }

    public static function stepHotspot(array $p, array &$state, float $deadline, int &$errors): bool
    {
        $router = self::router((int) $p['router_id']);
        $gw = Gateway::for($router);
        $map = array_map('intval', (array) $p['map']);
        if (!isset($state['users'])) {
            $users = [];
            foreach ($gw->hotspotUsers() as $u) {
                if ($u['dynamic'] || Provisioner::parseTag($u['comment']) || $u['name'] === '' || ($map[$u['profile']] ?? 0) <= 0) {
                    continue;
                }
                $users[] = [$u['name'], $u['password'], $u['profile'], $u['uptime'], $u['bytes_in'], $u['bytes_out'], $u['mac'], $u['disabled'] ? 1 : 0];
            }
            $state['users'] = $users;
            $state['total'] = count($users);
            $state['batches'] = [];
            $state['imported'] = 0;
            $state['existing'] = 0;
        }
        $total = count($state['users']);
        $categories = [];
        while ((int) $state['i'] < $total && microtime(true) < $deadline) {
            $slice = array_slice($state['users'], (int) $state['i'], self::CHUNK);
            $names = array_column($slice, 0);
            $taken = LoginRegistry::taken($names);
            foreach ($slice as [$name, $password, $profile, $uptime, $bin, $bout, $mac, $disabled]) {
                $state['i']++;
                if (isset($taken[$name]) || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', (string) $name)) {
                    $state['existing']++;
                    continue;
                }
                $catId = $map[$profile];
                $categories[$catId] ??= DB::row('SELECT * FROM categories WHERE id = ? AND is_custom = 0', [$catId]);
                $cat = $categories[$catId];
                if (!$cat) {
                    $errors++;
                    continue;
                }
                if (!isset($state['batches'][$catId])) {
                    $state['batches'][$catId] = DB::insert('batches', [
                        'name' => mb_substr('من جهاز MikroTik ' . $router['name'] . ' — ' . $cat['name'], 0, 120),
                        'category_id' => $catId, 'source' => 'router', 'quantity' => 0,
                        'options' => json_encode(['router_id' => (int) $router['id'], 'profile' => $profile], JSON_UNESCAPED_UNICODE),
                        'created_by' => null, 'created_at' => now(),
                    ]);
                }
                $now = now();
                $used = (int) $uptime > 0 || (int) $bin + (int) $bout > 0;
                $cardId = DB::transaction(static function () use ($state, $catId, $name, $password, $used, $uptime, $bin, $bout, $mac, $disabled, $now, $cat, $router) {
                    $id = DB::insert('cards', [
                        'batch_id' => $state['batches'][$catId], 'category_id' => $catId, 'username' => $name,
                        'password' => $password !== '' ? $password : $name, 'status' => $used ? 'active' : 'new',
                        'is_disabled' => $disabled, 'first_login_at' => $used ? $now : null,
                        'expires_at' => $used && $cat['validity'] !== null ? date('Y-m-d H:i:s', time() + (int) $cat['validity']) : null,
                        'used_seconds' => (int) $uptime, 'used_bytes_in' => (int) $bin, 'used_bytes_out' => (int) $bout,
                        'bound_mac' => $mac, 'last_router_id' => $router['id'], 'created_at' => $now,
                    ]);
                    LoginRegistry::add($name, 'card', $id);
                    DB::insert('card_routers', [
                        'card_id' => $id, 'router_id' => $router['id'], 'state' => 'present',
                        'uptime' => (int) $uptime, 'bytes_in' => (int) $bin, 'bytes_out' => (int) $bout,
                        'carry_uptime' => 0, 'carry_bytes_in' => 0, 'carry_bytes_out' => 0,
                        'provisioned_at' => $now, 'synced_at' => $now,
                    ]);
                    return $id;
                });
                $state['imported']++;
                if (!empty($p['retag'])) {
                    try {
                        $profileName = ProfileManager::ensureCategory($gw, $router, $cat);
                        $gw->setHotspotUser($name, ['comment' => Provisioner::CARD_TAG . $cardId, 'profile' => $profileName]);
                    } catch (\Throwable $e) {
                        $errors++;
                        $state['log'][] = $name . ': ' . $e->getMessage();
                    }
                }
            }
        }
        $gw->close();
        if ((int) $state['i'] >= $total) {
            foreach ($state['batches'] ?? [] as $batchId) {
                DB::query('UPDATE batches SET quantity = (SELECT COUNT(*) FROM cards WHERE batch_id = ?) WHERE id = ?', [$batchId, $batchId]);
            }
            $state['log'][] = 'تم استيراد ' . $state['imported'] . ' مستخدم، وتخطي ' . $state['existing'] . ' موجود مسبقاً.';
            return true;
        }
        return false;
    }

    public static function stepPpp(array $p, array &$state, float $deadline, int &$errors): bool
    {
        $router = self::router((int) $p['router_id']);
        $gw = Gateway::for($router);
        $map = array_map('intval', (array) $p['map']);
        if (!isset($state['users'])) {
            $users = [];
            foreach ($gw->pppSecrets() as $s) {
                $profile = (string) ($s['profile'] ?? 'default');
                if (Provisioner::parseTag((string) ($s['comment'] ?? '')) || ($map[$profile] ?? 0) <= 0) {
                    continue;
                }
                $users[] = [
                    (string) $s['name'], (string) ($s['password'] ?? ''), $profile, (string) ($s['remote-address'] ?? ''),
                    normalize_mac((string) ($s['caller-id'] ?? '')), ($s['disabled'] ?? 'false') === 'true' ? 1 : 0, (string) ($s['comment'] ?? ''),
                ];
            }
            $state['users'] = $users;
            $state['total'] = count($users);
            $state['imported'] = 0;
            $state['existing'] = 0;
        }
        $expires = !empty($p['expires_at']) ? date('Y-m-d 23:59:59', strtotime((string) $p['expires_at'])) : null;
        $total = count($state['users']);
        $plans = [];
        while ((int) $state['i'] < $total && microtime(true) < $deadline) {
            [$name, $password, $profile, $remote, $mac, $disabled, $comment] = $state['users'][(int) $state['i']];
            $state['i']++;
            if (LoginRegistry::isTaken($name) || !preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $name)) {
                $state['existing']++;
                continue;
            }
            $planId = $map[$profile];
            $plans[$planId] ??= DB::row('SELECT * FROM plans WHERE id = ?', [$planId]);
            $plan = $plans[$planId];
            if (!$plan) {
                $errors++;
                continue;
            }
            if (\App\Core\Tenant::limitReached('max_subscribers', 'SELECT COUNT(*) FROM subscribers')) {
                $errors++;
                continue;
            }
            $subExpires = $expires ?? date('Y-m-d H:i:s', time() + (int) $plan['duration_days'] * 86400);
            $subId = DB::transaction(static function () use ($name, $password, $comment, $router, $planId, $disabled, $subExpires, $remote, $mac) {
                $id = DB::insert('subscribers', [
                    'full_name' => mb_substr($comment !== '' ? $comment : $name, 0, 120), 'username' => $name,
                    'password' => $password !== '' ? $password : $name, 'plan_id' => $planId, 'router_id' => $router['id'],
                    'status' => $disabled ? 'suspended' : 'active', 'expires_at' => $subExpires,
                    'static_ip' => filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null,
                    'lock_mac' => $mac ? 1 : 0, 'bound_mac' => $mac, 'period_started_at' => now(),
                    'router_state' => $disabled ? 'disabled' : 'enabled', 'created_at' => now(),
                ]);
                LoginRegistry::add($name, 'subscriber', $id);
                DB::insert('subscriber_routers', ['subscriber_id' => $id, 'router_id' => $router['id'], 'state' => 'present', 'provisioned_at' => now(), 'synced_at' => now()]);
                return $id;
            });
            $state['imported']++;
            if (!empty($p['retag'])) {
                try {
                    Provisioner::provisionSubscriber($gw, $router, $subId);
                } catch (\Throwable $e) {
                    $errors++;
                    $state['log'][] = $name . ': ' . $e->getMessage();
                }
            }
        }
        $gw->close();
        if ((int) $state['i'] >= $total) {
            $state['log'][] = 'تم استيراد ' . $state['imported'] . ' مشترك، وتخطي ' . $state['existing'] . ' موجود مسبقاً.';
            return true;
        }
        return false;
    }

    /** وصف حدود مستخدم جهاز MikroTik لعرضه في المعاينة */
    public static function describeUser(array $u): string
    {
        $parts = [];
        if ($u['limit_uptime'] > 0) {
            $parts[] = Duration::format($u['limit_uptime']);
        }
        if ($u['limit_bytes_total'] > 0) {
            $parts[] = fmt_bytes($u['limit_bytes_total']);
        }
        return $parts ? implode(' · ', $parts) : '—';
    }
}
