<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\MikroTik\Duration;
use App\MikroTik\Gateway;

/**
 * إنشاء ملفات السرعة (Profiles) على جهاز MikroTik ومزامنتها مع باقات الكروت وباقات الاشتراك.
 * تُطبَّق فقط عند تغيّر الإعدادات (بصمة محفوظة لكل جهاز MikroTik).
 */
final class ProfileManager
{
    private static array $pppDefaults = [];

    public static function categoryProfile(int $id): string
    {
        return 'sn-c' . $id;
    }

    public static function planProfile(int $id): string
    {
        return 'sn-p' . $id;
    }

    /** سكربت إرسال حدث الدخول/الخروج من جهاز MikroTik إلى النظام */
    public static function eventScript(array $router, string $event): string
    {
        if (!Settings::bool('hotspot.events')) {
            return '';
        }
        $panel = Settings::panelUrl();
        if ($panel === '' || !preg_match('#^https?://[A-Za-z0-9.\-:/_]+$#', $panel)) {
            return '';
        }
        $key = preg_replace('/[^a-f0-9]/', '', (string) $router['router_key']);
        $out = ((int) ($router['ros_major'] ?? 0)) >= 7 ? 'output=none' : 'keep-result=no';
        return ':do {/tool fetch url=("' . $panel . '/api/router/event\\?k=' . $key . '&e=' . $event
            . '&u=" . $user . "&m=" . $"mac-address" . "&a=" . $address) ' . $out . '} on-error={}';
    }

    public static function categoryProps(array $router, array $category): array
    {
        $shared = (int) $category['shared_users'];
        return [
            'shared-users'       => $shared <= 0 ? 'unlimited' : (string) $shared,
            'rate-limit'         => Units::rateLimit($category) ?? '',
            'idle-timeout'       => $category['idle_timeout'] ? Duration::format((int) $category['idle_timeout']) : 'none',
            'add-mac-cookie'     => CookiePolicy::enabled() ? 'yes' : 'no',
            'mac-cookie-timeout' => CookiePolicy::duration(),
            'on-login'           => self::eventScript($router, 'in'),
            'on-logout'          => self::eventScript($router, 'out'),
        ];
    }

    public static function hotspotPlanProps(array $router, array $plan): array
    {
        $shared = (int) $plan['shared_users'];
        return [
            'shared-users'       => $shared <= 0 ? 'unlimited' : (string) $shared,
            'rate-limit'         => Units::rateLimit($plan) ?? '',
            'add-mac-cookie'     => CookiePolicy::enabled() ? 'yes' : 'no',
            'mac-cookie-timeout' => CookiePolicy::duration(),
            'on-login'           => self::eventScript($router, 'in'),
            'on-logout'          => self::eventScript($router, 'out'),
        ];
    }

    /** ملف السرعة المخفّض بعد تجاوز باقة البيانات (FUP) */
    public static function fupRate(array $plan): ?string
    {
        if (empty($plan['fup_rate_up']) && empty($plan['fup_rate_down'])) {
            return null;
        }
        return Units::rateLimit(['rate_up' => $plan['fup_rate_up'], 'rate_down' => $plan['fup_rate_down']]);
    }

    public static function pppPlanProps(Gateway $gw, array $router, array $plan, bool $fup = false): array
    {
        $rid = (int) $router['id'];
        if (!isset(self::$pppDefaults[$rid])) {
            $def = $gw->client()->first('/ppp/profile', ['name' => 'default']) ?? [];
            self::$pppDefaults[$rid] = $def;
        }
        $def = self::$pppDefaults[$rid];
        $props = [
            'rate-limit' => ($fup ? self::fupRate($plan) : null) ?? Units::rateLimit($plan) ?? '',
            'only-one'   => (int) $plan['shared_users'] <= 1 ? 'yes' : 'no',
        ];
        $local = $plan['local_address'] ?: ($def['local-address'] ?? '');
        $remote = $plan['address_pool'] ?: ($def['remote-address'] ?? '');
        $dns = $plan['dns_server'] ?: ($def['dns-server'] ?? '');
        if ($local !== '') {
            $props['local-address'] = $local;
        }
        if ($remote !== '') {
            $props['remote-address'] = $remote;
        }
        if ($dns !== '') {
            $props['dns-server'] = $dns;
        }
        return $props;
    }

    public static function ensureCategory(Gateway $gw, array $router, array $category, bool $force = false): string
    {
        $name = self::categoryProfile((int) $category['id']);
        $props = self::categoryProps($router, $category);
        self::apply((int) $router['id'], 'category', (int) $category['id'], $props, $force, static fn () => $gw->upsertHotspotProfile($name, $props));
        return $name;
    }

    public static function ensureHotspotPlan(Gateway $gw, array $router, array $plan, bool $force = false): string
    {
        $name = self::planProfile((int) $plan['id']);
        $props = self::hotspotPlanProps($router, $plan);
        self::apply((int) $router['id'], 'plan', (int) $plan['id'], $props, $force, static fn () => $gw->upsertHotspotProfile($name, $props));
        return $name;
    }

    /** ملف PPP للباقة، وملف مخفّض اختياري للاستهلاك العادل */
    public static function ensurePppPlan(Gateway $gw, array $router, array $plan, bool $fup = false, bool $force = false): string
    {
        $name = self::planProfile((int) $plan['id']) . ($fup ? '-fup' : '');
        $props = self::pppPlanProps($gw, $router, $plan, $fup);
        $ref = (int) $plan['id'] + ($fup ? 1000000 : 0);
        self::apply((int) $router['id'], 'plan_ppp', $ref, $props, $force, static fn () => $gw->upsertPppProfile($name, $props));
        return $name;
    }

    /**
     * ملف سرعة مخفّض لمستخدم حُددت سرعته: نسخة من ملف باقته بسرعة التحديد.
     * @param string $kind category | plan | plan_ppp
     */
    public static function ensureCapped(Gateway $gw, array $router, string $kind, array $row, array $cap): string
    {
        $name = self::cappedName($kind === 'category' ? self::categoryProfile((int) $row['id']) : self::planProfile((int) $row['id']), $cap);
        $rate = UsageGuard::rateLimit($cap);
        if ($kind === 'plan_ppp') {
            $props = ['rate-limit' => $rate] + self::pppPlanProps($gw, $router, $row);
            $gw->upsertPppProfile($name, $props);
        } else {
            $props = ['rate-limit' => $rate] + ($kind === 'category' ? self::categoryProps($router, $row) : self::hotspotPlanProps($router, $row));
            $gw->upsertHotspotProfile($name, $props);
        }
        return $name;
    }

    /** اسم ملف السرعة المخفّض: sn-c5-cap-3M-512k */
    public static function cappedName(string $base, array $cap): string
    {
        $slug = static fn (string $r): string => (string) preg_replace('/[^0-9A-Za-z]/', '', $r);
        return $base . '-cap-' . $slug((string) $cap['rate_down']) . '-' . $slug((string) $cap['rate_up']);
    }

    private static function apply(int $routerId, string $kind, int $refId, array $props, bool $force, callable $fn): void
    {
        $hash = crc32(json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$force) {
            $current = DB::value('SELECT version FROM router_profiles WHERE router_id = ? AND kind = ? AND ref_id = ?', [$routerId, $kind, $refId]);
            if ($current !== null && (int) $current === $hash) {
                return;
            }
        }
        $fn();
        DB::query(
            'INSERT INTO router_profiles (router_id, kind, ref_id, version, synced_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version), synced_at = VALUES(synced_at)',
            [$routerId, $kind, $refId, $hash, now()]
        );
    }

    /** إلغاء البصمات لإعادة تطبيق الملفات (مثلاً بعد إعادة ضبط جهاز MikroTik) */
    public static function forget(int $routerId, ?string $kind = null, ?int $refId = null): void
    {
        if ($kind === null) {
            DB::delete('router_profiles', 'router_id = ?', [$routerId]);
            return;
        }
        DB::delete('router_profiles', 'router_id = ? AND kind = ? AND ref_id = ?', [$routerId, $kind, (int) $refId]);
    }

    public static function forgetAll(): void
    {
        DB::query('DELETE FROM router_profiles');
    }
}
