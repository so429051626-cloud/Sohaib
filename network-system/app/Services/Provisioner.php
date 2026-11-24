<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\MikroTik\Duration;
use App\MikroTik\Gateway;
use App\MikroTik\MikroTikException;

/**
 * تطبيق الكروت والمشتركين على أجهزة MikroTik (مستخدمو الهوتسبوت وحسابات PPP).
 */
final class Provisioner
{
    public const CARD_TAG = 'sn:c';
    public const SUB_TAG = 'sn:s';

    /** استخراج نوع ومعرّف الكيان من تعليق مستخدم جهاز MikroTik */
    public static function parseTag(string $comment): ?array
    {
        if (preg_match('/\bsn:([cs])(\d+)\b/', $comment, $m)) {
            return [$m[1] === 'c' ? 'card' : 'subscriber', (int) $m[2]];
        }
        return null;
    }

    /**
     * إنشاء/تحديث الكرت على جهاز MikroTik بحدود متبقية دقيقة.
     * يُحدّث استهلاك الكرت من عدّاد جهاز MikroTik قبل الحساب.
     */
    public static function provisionCard(Gateway $gw, array $router, int $cardId, ?string $bindMac = null): array
    {
        $card = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
        if (!$card) {
            throw new \RuntimeException('الكرت غير موجود.');
        }
        $category = DB::row('SELECT * FROM categories WHERE id = ?', [$card['category_id']]);
        $profile = ProfileManager::ensureCategory($gw, $router, $category);
        // سرعة محددة (استهلاك زائد): ملف مخفّض بدل ملف الباقة حتى يُرفع التحديد
        if ($cap = UsageGuard::activeCap('card', (int) $card['id'])) {
            $profile = ProfileManager::ensureCapped($gw, $router, 'category', $category, $cap);
        }

        $entry = $gw->hotspotUser($card['username']);
        if ($entry) {
            if (CardUsage::record((int) $card['id'], (int) $router['id'], $entry['uptime'], $entry['bytes_in'], $entry['bytes_out'])) {
                CardUsage::recompute([(int) $card['id']]);
                $card = DB::row('SELECT * FROM cards WHERE id = ?', [$cardId]);
            }
        }

        $mac = $card['bound_mac'];
        if ($mac === null && $bindMac !== null && (int) $category['lock_mac'] === 1) {
            $mac = $bindMac;
        }

        // للكرت الذي لم يُستخدم بعد: الصلاحية تبدأ من هذا الدخول
        $limits = CardRules::routerLimits(
            $card,
            $category,
            $entry['uptime'] ?? 0,
            ($entry['bytes_in'] ?? 0) + ($entry['bytes_out'] ?? 0)
        );

        $props = [
            'name'              => $card['username'],
            'password'          => $card['password'],
            'profile'           => $profile,
            'limit-uptime'      => Duration::format($limits['limit_uptime']),
            'limit-bytes-total' => (string) $limits['limit_bytes'],
            'mac-address'       => $mac ?? '00:00:00:00:00:00',
            'disabled'          => (int) $card['is_disabled'] === 1 ? 'yes' : 'no',
            'comment'           => self::CARD_TAG . $card['id'],
        ];
        try {
            $state = $gw->upsertHotspotUser($props);
        } catch (MikroTikException $e) {
            // ملف السرعة حُذف يدوياً من جهاز MikroTik: أعد إنشاءه ثم أعد المحاولة مرة واحدة
            if (!$e->isConnection() && stripos($e->getMessage(), 'profile') !== false) {
                ProfileManager::ensureCategory($gw, $router, $category, true);
                $state = $gw->upsertHotspotUser($props);
            } else {
                throw $e;
            }
        }
        CardUsage::record((int) $card['id'], (int) $router['id'], (int) ($state['uptime'] ?? 0), (int) ($state['bytes_in'] ?? 0), (int) ($state['bytes_out'] ?? 0));
        DB::update('card_routers', ['provisioned_at' => now()], 'card_id = ? AND router_id = ?', [$card['id'], $router['id']]);
        if ((int) $card['is_disabled'] === 1) {
            $gw->disconnectHotspotUser($card['username']);
            $gw->removeHotspotCookies($card['username']);
        }
        return $state;
    }

    /** حذف الكرت من جهاز MikroTik مع فصل جلساته */
    public static function removeCard(Gateway $gw, array $router, array $card): void
    {
        $entry = $gw->hotspotUser($card['username']);
        if ($entry) {
            CardUsage::record((int) $card['id'], (int) $router['id'], $entry['uptime'], $entry['bytes_in'], $entry['bytes_out'], 'removed');
            $gw->removeHotspotUser($card['username']);
        } else {
            CardUsage::markRemoved((int) $card['id'], (int) $router['id']);
        }
        $gw->disconnectHotspotUser($card['username']);
        $gw->removeHotspotCookies($card['username']);
    }

    // ---------------------------------------------------------------------
    // المشتركون
    // ---------------------------------------------------------------------

    /**
     * تطبيق المشترك على جهازه: PPP secret أو مستخدم هوتسبوت.
     * المشترك المنتهي أو الموقوف يُطبَّق معطّلاً.
     */
    public static function provisionSubscriber(Gateway $gw, array $router, int $subscriberId): void
    {
        $sub = DB::row('SELECT * FROM subscribers WHERE id = ?', [$subscriberId]);
        if (!$sub) {
            throw new \RuntimeException('المشترك غير موجود.');
        }
        $plan = DB::row('SELECT * FROM plans WHERE id = ?', [$sub['plan_id']]);
        $enabled = SubscriberRules::blockReason($sub, $plan) === null;

        $cap = UsageGuard::activeCap('subscriber', (int) $sub['id']);
        if ($plan['service'] === 'ppp') {
            $profile = ProfileManager::ensurePppPlan($gw, $router, $plan, false);
            if ((int) $sub['fup_active'] === 1 && ProfileManager::fupRate($plan) !== null) {
                $profile = ProfileManager::ensurePppPlan($gw, $router, $plan, true);
            }
            if ($cap) {
                $profile = ProfileManager::ensureCapped($gw, $router, 'plan_ppp', $plan, $cap);
            }
            $props = [
                'name'      => $sub['username'],
                'password'  => $sub['password'],
                'service'   => 'pppoe',
                'profile'   => $profile,
                'disabled'  => $enabled ? 'no' : 'yes',
                'comment'   => self::SUB_TAG . $sub['id'],
                'caller-id' => ((int) $sub['lock_mac'] === 1 && $sub['bound_mac']) ? $sub['bound_mac'] : '',
            ];
            $remote = $sub['static_ip'] ?: null;
            if ($remote !== null) {
                $props['remote-address'] = $remote;
            }
            $existing = $gw->client()->print('/ppp/secret', ['name' => $sub['username']], ['.id', 'remote-address']);
            try {
                $gw->upsertPppSecret($props);
                if ($existing && $remote === null && ($existing[0]['remote-address'] ?? '') !== '') {
                    // إزالة عنوان ثابت سابق
                    $gw->client()->exec('/ppp/secret/unset', ['.id' => $existing[0]['.id'], 'value-name' => 'remote-address']);
                }
            } catch (MikroTikException $e) {
                if (!$e->isConnection() && stripos($e->getMessage(), 'profile') !== false) {
                    ProfileManager::ensurePppPlan($gw, $router, $plan, (int) $sub['fup_active'] === 1, true);
                    $gw->upsertPppSecret($props);
                } else {
                    throw $e;
                }
            }
            if (!$enabled) {
                $gw->disconnectPpp($sub['username']);
            }
        } else {
            $profile = ProfileManager::ensureHotspotPlan($gw, $router, $plan);
            if ($cap) {
                $profile = ProfileManager::ensureCapped($gw, $router, 'plan', $plan, $cap);
            }
            $props = [
                'name'        => $sub['username'],
                'password'    => $sub['password'],
                'profile'     => $profile,
                'limit-uptime' => '0s',
                'limit-bytes-total' => '0',
                'mac-address' => ((int) $sub['lock_mac'] === 1 && $sub['bound_mac']) ? $sub['bound_mac'] : '00:00:00:00:00:00',
                'disabled'    => $enabled ? 'no' : 'yes',
                'comment'     => self::SUB_TAG . $sub['id'],
            ];
            try {
                $gw->upsertHotspotUser($props);
            } catch (MikroTikException $e) {
                if (!$e->isConnection() && stripos($e->getMessage(), 'profile') !== false) {
                    ProfileManager::ensureHotspotPlan($gw, $router, $plan, true);
                    $gw->upsertHotspotUser($props);
                } else {
                    throw $e;
                }
            }
            if (!$enabled) {
                $gw->disconnectHotspotUser($sub['username']);
                $gw->removeHotspotCookies($sub['username']);
            }
        }

        DB::query(
            'INSERT INTO subscriber_routers (subscriber_id, router_id, state, provisioned_at, synced_at) VALUES (?, ?, \'present\', ?, ?)
             ON DUPLICATE KEY UPDATE state = \'present\', provisioned_at = VALUES(provisioned_at)',
            [$sub['id'], $router['id'], now(), now()]
        );
        if ((int) $router['id'] === (int) $sub['router_id']) {
            DB::update('subscribers', ['router_state' => $enabled ? 'enabled' : 'disabled'], 'id = ?', [$sub['id']]);
        }
    }

    public static function removeSubscriber(Gateway $gw, array $router, array $sub, string $service): void
    {
        if ($service === 'ppp') {
            $gw->disconnectPpp($sub['username']);
            $gw->removePppSecret($sub['username']);
        } else {
            $gw->disconnectHotspotUser($sub['username']);
            $gw->removeHotspotUser($sub['username']);
            $gw->removeHotspotCookies($sub['username']);
        }
        DB::update('subscriber_routers', ['state' => 'removed', 'synced_at' => now()], 'subscriber_id = ? AND router_id = ?', [$sub['id'], $router['id']]);
    }
}
