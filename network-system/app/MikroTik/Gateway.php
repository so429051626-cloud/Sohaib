<?php
declare(strict_types=1);

namespace App\MikroTik;

use App\Core\Crypto;
use App\Core\Settings;

/**
 * العمليات عالية المستوى على جهاز MikroTik واحد.
 * كل الدوال ترمي MikroTikException عند الفشل.
 */
final class Gateway
{
    private ?Client $client = null;

    public function __construct(public readonly array $router, private ?float $timeout = null)
    {
    }

    public static function for(array $router, ?float $timeout = null): self
    {
        return new self($router, $timeout);
    }

    /** اتصال جديد ببيانات صريحة (لاختبار الإعداد قبل الحفظ) */
    public static function probe(string $host, int $port, bool $ssl, string $user, string $pass, float $timeout = 6.0): self
    {
        $gw = new self(['id' => 0, 'host' => $host, 'api_port' => $port, 'api_ssl' => $ssl ? 1 : 0, 'api_username' => $user, 'api_password' => ''], $timeout);
        $gw->client = new Client($host, $port, $ssl, $timeout);
        $gw->client->connect($user, $pass);
        return $gw;
    }

    public function client(): Client
    {
        if ($this->client === null || !$this->client->connected()) {
            $timeout = $this->timeout ?? (float) max(2, Settings::int('sync.api_timeout', 5));
            $client = new Client(
                (string) $this->router['host'],
                (int) $this->router['api_port'],
                (bool) $this->router['api_ssl'],
                $timeout
            );
            $password = (string) $this->router['api_password'];
            if ($password !== '' && (str_starts_with($password, 's1:') || str_starts_with($password, 'g1:'))) {
                $password = Crypto::decrypt($password);
            }
            $client->connect((string) $this->router['api_username'], $password);
            $this->client = $client;
        }
        return $this->client;
    }

    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    // ---------------------------------------------------------------------
    // معلومات النظام
    // ---------------------------------------------------------------------

    public function resource(): array
    {
        $r = $this->client()->first('/system/resource') ?? [];
        $version = (string) ($r['version'] ?? '');
        return [
            'uptime_seconds' => Duration::toSeconds($r['uptime'] ?? ''),
            'version'        => $version,
            'ros_major'      => (int) (preg_match('/^(\d+)/', $version, $m) ? $m[1] : 0),
            'cpu_load'       => isset($r['cpu-load']) ? (int) $r['cpu-load'] : null,
            'cpu_count'      => isset($r['cpu-count']) ? (int) $r['cpu-count'] : null,
            'memory_total'   => isset($r['total-memory']) ? (int) $r['total-memory'] : null,
            'memory_free'    => isset($r['free-memory']) ? (int) $r['free-memory'] : null,
            'hdd_total'      => isset($r['total-hdd-space']) ? (int) $r['total-hdd-space'] : null,
            'hdd_free'       => isset($r['free-hdd-space']) ? (int) $r['free-hdd-space'] : null,
            'architecture'   => $r['architecture-name'] ?? null,
            'board_name'     => $r['board-name'] ?? null,
            'cpu'            => $r['cpu'] ?? null,
            'cpu_frequency'  => $r['cpu-frequency'] ?? null,
        ];
    }

    /** الحرارة والجهد — صيغة RouterOS 6 (صف واحد) و 7 (صف لكل حساس) */
    public function health(): array
    {
        $out = ['temperature' => null, 'voltage' => null];
        try {
            $rows = $this->client()->print('/system/health');
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
            return $out;
        }
        foreach ($rows as $row) {
            if (isset($row['name'], $row['value'])) {
                $name = (string) $row['name'];
                if ($out['temperature'] === null && in_array($name, ['temperature', 'cpu-temperature', 'board-temperature1', 'sfp-temperature'], true)) {
                    $out['temperature'] = (float) $row['value'];
                } elseif ($name === 'voltage' || $name === 'psu1-voltage') {
                    $out['voltage'] ??= (float) $row['value'];
                }
            } else {
                if (isset($row['temperature'])) {
                    $out['temperature'] = (float) $row['temperature'];
                } elseif (isset($row['cpu-temperature'])) {
                    $out['temperature'] = (float) $row['cpu-temperature'];
                }
                if (isset($row['voltage'])) {
                    $out['voltage'] = (float) $row['voltage'];
                }
            }
        }
        return $out;
    }

    public function identity(): string
    {
        $r = $this->client()->first('/system/identity');
        return (string) ($r['name'] ?? '');
    }

    public function routerboard(): array
    {
        try {
            $r = $this->client()->first('/system/routerboard') ?? [];
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
            return [];
        }
        return [
            'model'         => $r['model'] ?? null,
            'serial_number' => $r['serial-number'] ?? null,
            'firmware'      => $r['current-firmware'] ?? null,
        ];
    }

    // ---------------------------------------------------------------------
    // الهوتسبوت
    // ---------------------------------------------------------------------

    public function hotspotActive(): array
    {
        $rows = $this->client()->print('/ip/hotspot/active', [], [
            '.id', 'server', 'user', 'address', 'mac-address', 'login-by', 'uptime', 'idle-time',
            'session-time-left', 'bytes-in', 'bytes-out', 'comment',
        ]);
        return array_map(static fn ($r) => [
            'id'                => $r['.id'] ?? '',
            'server'            => $r['server'] ?? '',
            'user'              => $r['user'] ?? '',
            'address'           => $r['address'] ?? '',
            'mac'               => normalize_mac($r['mac-address'] ?? '') ?? '',
            'login_by'          => $r['login-by'] ?? '',
            'uptime'            => Duration::toSeconds($r['uptime'] ?? ''),
            'idle'              => Duration::toSeconds($r['idle-time'] ?? ''),
            'session_time_left' => isset($r['session-time-left']) ? Duration::toSeconds($r['session-time-left']) : null,
            'bytes_in'          => (int) ($r['bytes-in'] ?? 0),
            'bytes_out'         => (int) ($r['bytes-out'] ?? 0),
        ], $rows);
    }

    public function hotspotHosts(): array
    {
        $rows = $this->client()->print('/ip/hotspot/host', [], [
            '.id', 'mac-address', 'address', 'to-address', 'server', 'uptime', 'idle-time',
            'bytes-in', 'bytes-out', 'authorized', 'bypassed', 'comment',
        ]);
        return array_map(static fn ($r) => [
            'id'         => $r['.id'] ?? '',
            'mac'        => normalize_mac($r['mac-address'] ?? '') ?? '',
            'address'    => $r['address'] ?? '',
            'to_address' => $r['to-address'] ?? '',
            'server'     => $r['server'] ?? '',
            'uptime'     => Duration::toSeconds($r['uptime'] ?? ''),
            'idle'       => Duration::toSeconds($r['idle-time'] ?? ''),
            'bytes_in'   => (int) ($r['bytes-in'] ?? 0),
            'bytes_out'  => (int) ($r['bytes-out'] ?? 0),
            'authorized' => ($r['authorized'] ?? 'false') === 'true',
            'bypassed'   => ($r['bypassed'] ?? 'false') === 'true',
            'comment'    => $r['comment'] ?? '',
        ], $rows);
    }

    public function hotspotUsers(): array
    {
        $rows = $this->client()->print('/ip/hotspot/user', [], [
            '.id', 'server', 'name', 'password', 'profile', 'mac-address', 'limit-uptime', 'limit-bytes-total',
            'uptime', 'bytes-in', 'bytes-out', 'disabled', 'comment', 'dynamic',
        ]);
        return array_map([self::class, 'normalizeHotspotUser'], $rows);
    }

    public function hotspotUser(string $name): ?array
    {
        $rows = $this->client()->print('/ip/hotspot/user', ['name' => $name]);
        return $rows ? self::normalizeHotspotUser($rows[0]) : null;
    }

    public static function normalizeHotspotUser(array $r): array
    {
        $mac = normalize_mac($r['mac-address'] ?? '');
        return [
            'id'                => $r['.id'] ?? '',
            'server'            => $r['server'] ?? 'all',
            'name'              => $r['name'] ?? '',
            'password'          => $r['password'] ?? '',
            'profile'           => $r['profile'] ?? 'default',
            'mac'               => $mac === '00:00:00:00:00:00' ? null : $mac,
            'limit_uptime'      => Duration::toSeconds($r['limit-uptime'] ?? ''),
            'limit_bytes_total' => (int) ($r['limit-bytes-total'] ?? 0),
            'uptime'            => Duration::toSeconds($r['uptime'] ?? ''),
            'bytes_in'          => (int) ($r['bytes-in'] ?? 0),
            'bytes_out'         => (int) ($r['bytes-out'] ?? 0),
            'disabled'          => ($r['disabled'] ?? 'false') === 'true',
            'comment'           => $r['comment'] ?? '',
            'dynamic'           => ($r['dynamic'] ?? 'false') === 'true',
        ];
    }

    public function hotspotUserProfiles(): array
    {
        return $this->client()->print('/ip/hotspot/user/profile');
    }

    public function hotspotServers(): array
    {
        return $this->client()->print('/ip/hotspot');
    }

    public function hotspotServerProfiles(): array
    {
        return $this->client()->print('/ip/hotspot/profile');
    }

    /** مجلد صفحات الهوتسبوت الفعلي المستخدم في خوادم الهوتسبوت */
    public function hotspotHtmlDirectories(): array
    {
        $servers = $this->hotspotServers();
        $profiles = [];
        foreach ($this->hotspotServerProfiles() as $p) {
            $profiles[$p['name'] ?? ''] = $p;
        }
        $dirs = [];
        foreach ($servers as $s) {
            $p = $profiles[$s['profile'] ?? 'default'] ?? null;
            if (!$p) {
                continue;
            }
            $dir = trim((string) ($p['html-directory-override'] ?? '')) ?: trim((string) ($p['html-directory'] ?? ''));
            if ($dir !== '') {
                $dirs[$dir] = $dir;
            }
        }
        if (!$dirs) {
            foreach ($profiles as $p) {
                $dir = trim((string) ($p['html-directory'] ?? ''));
                if ($dir !== '') {
                    $dirs[$dir] = $dir;
                }
            }
        }
        return array_values($dirs);
    }

    public function removeHotspotActive(string $id): void
    {
        $this->client()->remove('/ip/hotspot/active', $id);
    }

    /** فصل كل جلسات مستخدم هوتسبوت (اختيارياً بجهاز محدد) */
    public function disconnectHotspotUser(string $user, ?string $mac = null): int
    {
        $where = ['user' => $user];
        $rows = $this->client()->print('/ip/hotspot/active', $where, ['.id', 'mac-address']);
        $ids = [];
        foreach ($rows as $r) {
            if ($mac === null || normalize_mac($r['mac-address'] ?? '') === $mac) {
                $ids[] = $r['.id'];
            }
        }
        if ($ids) {
            $this->client()->remove('/ip/hotspot/active', $ids);
        }
        return count($ids);
    }

    public function disconnectHotspotMac(string $mac): int
    {
        $ids = [];
        foreach ($this->client()->print('/ip/hotspot/active', [], ['.id', 'mac-address']) as $r) {
            if (normalize_mac($r['mac-address'] ?? '') === $mac) {
                $ids[] = $r['.id'];
            }
        }
        if ($ids) {
            $this->client()->remove('/ip/hotspot/active', $ids);
        }
        return count($ids);
    }

    /** حذف ملفات تعريف الارتباط (MAC Cookie) لمستخدم كي لا يدخل تلقائياً */
    public function removeHotspotCookies(string $user): void
    {
        try {
            $rows = $this->client()->print('/ip/hotspot/cookie', ['user' => $user], ['.id']);
            if ($rows) {
                $this->client()->remove('/ip/hotspot/cookie', array_column($rows, '.id'));
            }
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
        }
    }

    /**
     * إنشاء/تحديث مستخدم هوتسبوت محلي بالاسم.
     * @return array الحالة بعد التطبيق (من جهاز MikroTik)
     */
    public function upsertHotspotUser(array $props): array
    {
        $name = (string) $props['name'];
        $existing = $this->client()->print('/ip/hotspot/user', ['name' => $name], ['.id']);
        if ($existing) {
            $this->client()->set('/ip/hotspot/user', $existing[0]['.id'], array_diff_key($props, ['name' => 1]));
        } else {
            $this->client()->add('/ip/hotspot/user', $props);
        }
        return $this->hotspotUser($name) ?? [];
    }

    public function setHotspotUser(string $name, array $props): bool
    {
        $existing = $this->client()->print('/ip/hotspot/user', ['name' => $name], ['.id']);
        if (!$existing) {
            return false;
        }
        $this->client()->set('/ip/hotspot/user', $existing[0]['.id'], $props);
        return true;
    }

    public function removeHotspotUser(string $name): bool
    {
        $existing = $this->client()->print('/ip/hotspot/user', ['name' => $name], ['.id']);
        if (!$existing) {
            return false;
        }
        $this->client()->remove('/ip/hotspot/user', array_column($existing, '.id'));
        return true;
    }

    /** إنشاء أو تحديث ملف مستخدمي هوتسبوت بالاسم */
    public function upsertHotspotProfile(string $name, array $props): void
    {
        $existing = $this->client()->print('/ip/hotspot/user/profile', ['name' => $name], ['.id']);
        if ($existing) {
            $this->client()->set('/ip/hotspot/user/profile', $existing[0]['.id'], $props);
        } else {
            $this->client()->add('/ip/hotspot/user/profile', ['name' => $name] + $props);
        }
    }

    public function removeHotspotProfile(string $name): bool
    {
        $existing = $this->client()->print('/ip/hotspot/user/profile', ['name' => $name], ['.id']);
        if (!$existing) {
            return false;
        }
        $this->client()->remove('/ip/hotspot/user/profile', array_column($existing, '.id'));
        return true;
    }

    public function ipBindings(): array
    {
        return $this->client()->print('/ip/hotspot/ip-binding');
    }

    /** ربط عنوان MAC: blocked (حظر) أو bypassed (تجاوز صفحة الدخول) */
    public function setBinding(string $mac, string $type, string $comment = ''): void
    {
        $rows = $this->client()->print('/ip/hotspot/ip-binding', ['mac-address' => $mac], ['.id']);
        if ($rows) {
            $this->client()->set('/ip/hotspot/ip-binding', $rows[0]['.id'], ['type' => $type, 'comment' => $comment, 'disabled' => 'no']);
        } else {
            $this->client()->add('/ip/hotspot/ip-binding', ['mac-address' => $mac, 'type' => $type, 'comment' => $comment]);
        }
    }

    public function removeBinding(string $mac): int
    {
        $rows = $this->client()->print('/ip/hotspot/ip-binding', ['mac-address' => $mac], ['.id']);
        if ($rows) {
            $this->client()->remove('/ip/hotspot/ip-binding', array_column($rows, '.id'));
        }
        return count($rows);
    }

    /** حذف الكوكيز المحفوظة (الدخول التلقائي) من الهوتسبوت؛ يعيد عددها */
    public function clearHotspotCookies(): int
    {
        $rows = $this->client()->print('/ip/hotspot/cookie', [], ['.id']);
        $ids = array_values(array_filter(array_column($rows, '.id')));
        foreach (array_chunk($ids, 200) as $chunk) {
            $this->client()->remove('/ip/hotspot/cookie', $chunk);
        }
        return count($ids);
    }

    public function walledGarden(): array
    {
        return $this->client()->print('/ip/hotspot/walled-garden');
    }

    public function walledGardenIp(): array
    {
        return $this->client()->print('/ip/hotspot/walled-garden/ip');
    }

    // ---------------------------------------------------------------------
    // PPP
    // ---------------------------------------------------------------------

    public function pppActive(): array
    {
        $rows = $this->client()->print('/ppp/active', [], ['.id', 'name', 'service', 'caller-id', 'address', 'uptime', 'encoding', 'comment']);
        return array_map(static fn ($r) => [
            'id'        => $r['.id'] ?? '',
            'name'      => $r['name'] ?? '',
            'service'   => $r['service'] ?? '',
            'caller_id' => $r['caller-id'] ?? '',
            'mac'       => normalize_mac($r['caller-id'] ?? '') ?? '',
            'address'   => $r['address'] ?? '',
            'uptime'    => Duration::toSeconds($r['uptime'] ?? ''),
            'encoding'  => $r['encoding'] ?? '',
        ], $rows);
    }

    /** عدّادات واجهات PPPoE الديناميكية: اسم المستخدم ← البايتات */
    public function pppTraffic(): array
    {
        $out = [];
        try {
            $rows = $this->client()->print('/interface', ['type' => 'pppoe-in'], ['name', 'rx-byte', 'tx-byte']);
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
            return [];
        }
        foreach ($rows as $r) {
            $name = (string) ($r['name'] ?? '');
            if (preg_match('/^<pppoe-(.+)>$/', $name, $m)) {
                $out[$m[1]] = ['rx' => (int) ($r['rx-byte'] ?? 0), 'tx' => (int) ($r['tx-byte'] ?? 0)];
            }
        }
        return $out;
    }

    public function pppSecrets(): array
    {
        return $this->client()->print('/ppp/secret', [], [
            '.id', 'name', 'password', 'service', 'profile', 'caller-id', 'remote-address', 'local-address',
            'disabled', 'comment', 'last-logged-out', 'last-caller-id',
        ]);
    }

    public function pppProfiles(): array
    {
        return $this->client()->print('/ppp/profile');
    }

    public function upsertPppSecret(array $props): void
    {
        $name = (string) $props['name'];
        $existing = $this->client()->print('/ppp/secret', ['name' => $name], ['.id']);
        if ($existing) {
            $this->client()->set('/ppp/secret', $existing[0]['.id'], array_diff_key($props, ['name' => 1]));
        } else {
            $this->client()->add('/ppp/secret', $props);
        }
    }

    public function setPppSecret(string $name, array $props): bool
    {
        $existing = $this->client()->print('/ppp/secret', ['name' => $name], ['.id']);
        if (!$existing) {
            return false;
        }
        $this->client()->set('/ppp/secret', $existing[0]['.id'], $props);
        return true;
    }

    public function removePppSecret(string $name): bool
    {
        $existing = $this->client()->print('/ppp/secret', ['name' => $name], ['.id']);
        if (!$existing) {
            return false;
        }
        $this->client()->remove('/ppp/secret', array_column($existing, '.id'));
        return true;
    }

    public function disconnectPpp(string $name): int
    {
        $rows = $this->client()->print('/ppp/active', ['name' => $name], ['.id']);
        if ($rows) {
            $this->client()->remove('/ppp/active', array_column($rows, '.id'));
        }
        return count($rows);
    }

    public function upsertPppProfile(string $name, array $props): void
    {
        $existing = $this->client()->print('/ppp/profile', ['name' => $name], ['.id']);
        if ($existing) {
            $this->client()->set('/ppp/profile', $existing[0]['.id'], $props);
        } else {
            $this->client()->add('/ppp/profile', ['name' => $name] + $props);
        }
    }

    // ---------------------------------------------------------------------
    // الشبكة
    // ---------------------------------------------------------------------

    public function leases(): array
    {
        $rows = $this->client()->print('/ip/dhcp-server/lease', [], [
            '.id', 'address', 'mac-address', 'host-name', 'server', 'status', 'last-seen', 'expires-after', 'dynamic', 'disabled', 'comment', 'active-address',
        ]);
        return array_map(static fn ($r) => [
            'id'        => $r['.id'] ?? '',
            'address'   => ($r['active-address'] ?? '') !== '' ? $r['active-address'] : ($r['address'] ?? ''),
            'mac'       => normalize_mac($r['mac-address'] ?? '') ?? '',
            'host_name' => $r['host-name'] ?? '',
            'server'    => $r['server'] ?? '',
            'status'    => $r['status'] ?? '',
            'last_seen' => Duration::toSeconds($r['last-seen'] ?? ''),
            'expires'   => Duration::toSeconds($r['expires-after'] ?? ''),
            'dynamic'   => ($r['dynamic'] ?? 'false') === 'true',
            'disabled'  => ($r['disabled'] ?? 'false') === 'true',
            'comment'   => $r['comment'] ?? '',
        ], $rows);
    }

    public function interfaces(): array
    {
        $rows = $this->client()->print('/interface', [], [
            '.id', 'name', 'type', 'mac-address', 'running', 'disabled', 'comment', 'rx-byte', 'tx-byte', 'last-link-up-time', 'link-downs', 'dynamic',
        ]);
        return array_map(static fn ($r) => [
            'id'        => $r['.id'] ?? '',
            'name'      => $r['name'] ?? '',
            'type'      => $r['type'] ?? '',
            'mac'       => $r['mac-address'] ?? '',
            'running'   => ($r['running'] ?? 'false') === 'true',
            'disabled'  => ($r['disabled'] ?? 'false') === 'true',
            'comment'   => $r['comment'] ?? '',
            'rx_byte'   => (int) ($r['rx-byte'] ?? 0),
            'tx_byte'   => (int) ($r['tx-byte'] ?? 0),
            'link_up'   => $r['last-link-up-time'] ?? '',
            'link_downs'=> (int) ($r['link-downs'] ?? 0),
            'dynamic'   => ($r['dynamic'] ?? 'false') === 'true',
        ], $rows);
    }

    /**
     * الواجهات التي لا يجوز تعطيلها من النظام لأن تعطيلها يقطع اتصال النظام بالجهاز أو الإنترنت عن الشبكة:
     * واجهة الإنترنت (WAN) المحددة للجهاز، ونفق MADIX، والمنافذ التي يمر عبرها اتصال النظام (managementPorts)،
     * والواجهة التي تحمل عنوان الجهاز الذي يتصل به النظام، والواجهة التي تقع في شبكتها عناوين جلسات API.
     * @return array<string, string> اسم الواجهة => سبب المنع
     */
    public function lockedInterfaces(): array
    {
        $client = $this->client();
        $read = static function (callable $fn): array {
            try {
                return $fn();
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
                return [];
            }
        };
        $mgmt = 'اتصال النظام بالجهاز يمر عبر هذه الواجهة: تعطيلها يقطع الاتصال بالجهاز.';
        $out = [];
        $wan = (string) ($this->router['wan_interface'] ?? '');
        if ($wan !== '') {
            $out[$wan] = 'واجهة الإنترنت (WAN) للجهاز: تعطيلها يقطع الإنترنت عن الشبكة واتصال النظام بالجهاز.';
        }
        $out[\App\Services\Tunnel::INTERFACE] = 'نفق الاتصال بالنظام: تعطيله يقطع الاتصال بالجهاز.';
        foreach ($this->managementPorts() as $port) {
            $out[$port] ??= $mgmt;
        }
        $host = (string) ($this->router['host'] ?? '');
        $sessions = [];
        foreach ($read(fn () => $client->print('/user/active', [], ['address', 'via'])) as $r) {
            if (str_starts_with((string) ($r['via'] ?? ''), 'api') && filter_var($r['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $sessions[] = (string) $r['address'];
            }
        }
        foreach ($read(fn () => $client->print('/ip/address', [], ['address', 'network', 'interface', 'disabled'])) as $a) {
            $if = (string) ($a['interface'] ?? '');
            [$ip, $bits] = array_pad(explode('/', (string) ($a['address'] ?? ''), 2), 2, '32');
            if ($if === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            if ($ip === $host) {
                $out[$if] ??= $mgmt;
                continue;
            }
            // عنوان نقطة لنقطة (مثل /32 network=10.77.0.1) أو شبكة عادية تضم عنوان جلسة API
            $peer = (string) ($a['network'] ?? '');
            foreach ($sessions as $s) {
                if ($s === $peer || self::inSubnet($s, $ip, (int) $bits)) {
                    $out[$if] ??= $mgmt;
                }
            }
        }
        return $out;
    }

    private static function inSubnet(string $ip, string $net, int $bits): bool
    {
        if ($bits <= 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 32 ? -1 : ~((1 << (32 - $bits)) - 1);
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }

    /** تعطيل واجهة أو تشغيلها بمعرّفها (.id) من قائمة /interface */
    public function setInterfaceDisabled(string $id, bool $disabled): void
    {
        $this->client()->set('/interface', $id, ['disabled' => $disabled ? 'yes' : 'no']);
    }

    // ---------------------------------------------------------------------
    // منافذ Ethernet وفحص الكابلات (قراءة فقط)
    // ---------------------------------------------------------------------

    /**
     * منافذ Ethernet مع عدّادات الأخطاء (print stats)، ومعها من /interface: هل يعمل، وعدد مرات انقطاع الرابط وآخره.
     * @return list<array{name:string, default_name:string, comment:string, disabled:bool, running:bool, link_downs:int, last_down:string, last_up:string, mac:string, stats:array<string,int>}>
     */
    public function ethernetPorts(): array
    {
        $client = $this->client();
        $links = [];
        foreach ($client->print('/interface', [], ['name', 'running', 'disabled', 'link-downs', 'last-link-down-time', 'last-link-up-time']) as $r) {
            $links[(string) ($r['name'] ?? '')] = $r;
        }
        $out = [];
        foreach ($client->command('/interface/ethernet/print', ['stats' => '']) as $r) {
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $stats = [];
            foreach ($r as $k => $v) {
                if ((str_starts_with($k, 'rx-') || str_starts_with($k, 'tx-')) && is_numeric($v)) {
                    $stats[$k] = (int) $v;
                }
            }
            $l = $links[$name] ?? [];
            $out[] = [
                'name' => $name, 'default_name' => (string) ($r['default-name'] ?? $name), 'comment' => (string) ($r['comment'] ?? ''),
                'disabled' => ($r['disabled'] ?? ($l['disabled'] ?? 'false')) === 'true', 'running' => ($l['running'] ?? ($r['running'] ?? 'false')) === 'true',
                'link_downs' => (int) ($l['link-downs'] ?? 0), 'last_down' => (string) ($l['last-link-down-time'] ?? ''), 'last_up' => (string) ($l['last-link-up-time'] ?? ''),
                'mac' => (string) ($r['mac-address'] ?? ''), 'stats' => $stats,
            ];
        }
        return $out;
    }

    /** عدّادات منفذ واحد فقط (للقراءة الثانية أثناء الفحص) */
    public function ethernetStats(string $port): array
    {
        $rows = $this->client()->command('/interface/ethernet/print', ['stats' => ''], ['name' => $port]);
        $stats = [];
        foreach ($rows[0] ?? [] as $k => $v) {
            if ((str_starts_with($k, 'rx-') || str_starts_with($k, 'tx-')) && is_numeric($v)) {
                $stats[$k] = (int) $v;
            }
        }
        return $stats;
    }

    /** حالة الرابط من ethernet monitor: الحالة، السرعة، الازدواج، التفاوض، ما يعلنه المنفذ والجهاز الآخر، وبيانات SFP */
    public function ethernetMonitor(string $port): array
    {
        $client = $this->client();
        $client->setReadTimeout(12);
        $rows = $client->command('/interface/ethernet/monitor', ['numbers' => $port, 'once' => '']);
        return $rows[0] ?? [];
    }

    /** فحص الكابل (TDR): الحالة وأزواج الكابل «الحالة:المسافة بالمتر» لكل زوج. لا يقيس المسافات والرابط قائم. */
    public function cableTest(string $port): array
    {
        $client = $this->client();
        $client->setReadTimeout(20);
        // يتجدد على RouterOS حتى يُوقف: أول نتيجة فيها الأزواج (أو link-ok) ثم يُوقف الأمر
        return $client->firstRow('/interface/ethernet/cable-test', ['numbers' => $port],
            static fn (array $r) => isset($r['cable-pairs']) || ($r['status'] ?? '') === 'link-ok', 8);
    }

    /**
     * ما على الطرف الآخر من كل منفذ:
     *  - الأجهزة المكتشفة بالبروتوكولات (MNDP من MikroTik، وLLDP وCDP من غيرها): الاسم والطراز والعنوان ومنفذها هي.
     *  - عدد العناوين (MAC) التي تعلّمها الجسر خلف المنفذ، واسم الجهاز إن كان وحيداً (من عقود DHCP).
     * @param list<string> $ports
     * @return array<string, array{peers:list<array>, macs:int, host:?array}>
     */
    public function portPeers(array $ports): array
    {
        $client = $this->client();
        $out = [];
        foreach ($ports as $p) {
            $out[$p] = ['peers' => [], 'macs' => 0, 'host' => null];
        }
        $read = static function (callable $fn): array {
            try {
                return $fn();
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
                return [];
            }
        };
        foreach ($read(fn () => $client->print('/ip/neighbor', [], ['interface', 'address', 'mac-address', 'identity', 'platform', 'version', 'board', 'interface-name', 'discovered-by', 'system-description'])) as $n) {
            // «ether2» أو «ether2,bridge» حين يكون المنفذ ضمن جسر
            foreach (array_map('trim', explode(',', (string) ($n['interface'] ?? ''))) as $if) {
                if (isset($out[$if])) {
                    $out[$if]['peers'][] = [
                        'identity' => (string) ($n['identity'] ?? ''), 'address' => (string) ($n['address'] ?? ''), 'mac' => strtoupper((string) ($n['mac-address'] ?? '')),
                        'platform' => (string) ($n['platform'] ?? ''), 'version' => (string) ($n['version'] ?? ''), 'board' => (string) ($n['board'] ?? ''),
                        'remote_port' => (string) ($n['interface-name'] ?? ''), 'via' => (string) ($n['discovered-by'] ?? ''), 'description' => (string) ($n['system-description'] ?? ''),
                    ];
                    break;
                }
            }
        }
        $single = [];
        foreach ($read(fn () => $client->print('/interface/bridge/host', [], ['mac-address', 'on-interface', 'interface', 'local', 'external'])) as $h) {
            $on = (string) ($h['on-interface'] ?? ($h['interface'] ?? ''));
            if (!isset($out[$on]) || ($h['local'] ?? 'false') === 'true') {
                continue;
            }
            $out[$on]['macs']++;
            $single[$on] = strtoupper((string) ($h['mac-address'] ?? ''));
        }
        foreach ($out as $port => $row) {
            if ($row['macs'] !== 1 || $row['peers'] || empty($single[$port])) {
                continue;
            }
            $lease = $read(fn () => $client->print('/ip/dhcp-server/lease', ['mac-address' => $single[$port]], ['address', 'host-name', 'comment']))[0] ?? null;
            $out[$port]['host'] = ['mac' => $single[$port], 'address' => (string) ($lease['address'] ?? ''), 'name' => (string) (($lease['comment'] ?? '') !== '' ? $lease['comment'] : ($lease['host-name'] ?? ''))];
        }
        return $out;
    }

    /** تعطيل منفذ Ethernet أو تشغيله (للفحص العميق فقط) */
    public function setEthernetDisabled(string $port, bool $disabled): void
    {
        $client = $this->client();
        $row = $client->first('/interface/ethernet', ['name' => $port], ['.id']);
        if (!$row) {
            throw new MikroTikException('no such item', MikroTikException::TRAP, 'no such item');
        }
        $client->set('/interface/ethernet', (string) $row['.id'], ['disabled' => $disabled ? 'yes' : 'no']);
    }

    /**
     * مؤقّت أمان على الجهاز نفسه يعيد تشغيل المنفذ بعد $seconds ثانية حتى لو انقطع الاتصال بالنظام أثناء الفحص،
     * ثم يحذف نفسه. يُحذف فور إعادة تشغيل المنفذ بنجاح. @return string اسم المؤقّت
     */
    public function addPortWatchdog(string $port, int $seconds = 45): string
    {
        $client = $this->client();
        $name = 'madix-cable-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $port);
        foreach ($client->print('/system/scheduler', ['name' => $name], ['.id']) as $old) {
            $client->remove('/system/scheduler', (string) $old['.id']);
        }
        $q = addcslashes($port, '"\\$');
        $props = [
            'name' => $name,
            'on-event' => '/interface ethernet enable [find where name="' . $q . '"]; /system scheduler remove [find where name="' . $name . '"]',
            'comment' => 'MADIX NEXUS cable test: re-enable port',
        ];
        $at = $this->clockAfter($seconds);
        $props += $at !== null ? ['start-date' => $at[0], 'start-time' => $at[1]] : ['start-time' => 'startup', 'interval' => $seconds . 's'];
        $client->add('/system/scheduler', $props);
        return $name;
    }

    public function removeScheduler(string $name): void
    {
        $client = $this->client();
        foreach ($client->print('/system/scheduler', ['name' => $name], ['.id']) as $row) {
            $client->remove('/system/scheduler', (string) $row['.id']);
        }
    }

    /** ساعة الجهاز بعد $seconds ثانية بصيغته هو (nov/15/2026 أو 2026-11-15): [التاريخ، الوقت] */
    public function clockAfter(int $seconds): ?array
    {
        try {
            $c = $this->client()->first('/system/clock');
        } catch (MikroTikException $e) {
            if ($e->isConnection()) {
                throw $e;
            }
            return null;
        }
        $date = strtolower((string) ($c['date'] ?? ''));
        if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', (string) ($c['time'] ?? ''), $t)) {
            return null;
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match('#^([a-z]{3})/(\d{2})/(\d{4})$#', $date, $m) && isset($months[$m[1]])) {
            $ts = gmmktime((int) $t[1], (int) $t[2], (int) $t[3], $months[$m[1]], (int) $m[2], (int) $m[3]) + $seconds;
            return [strtolower(gmdate('M', $ts)) . gmdate('/d/Y', $ts), gmdate('H:i:s', $ts)];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            $ts = gmmktime((int) $t[1], (int) $t[2], (int) $t[3], (int) $m[2], (int) $m[3], (int) $m[1]) + $seconds;
            return [gmdate('Y-m-d', $ts), gmdate('H:i:s', $ts)];
        }
        return null;
    }

    /**
     * المنافذ التي يمر عبرها اتصال النظام بالجهاز: عناوين جلسات API والبوابة الافتراضية، من جدول ARP،
     * ثم المنفذ الفعلي داخل الجسر (bridge host). لا يُعطَّل أي منها في الفحص العميق.
     * @return list<string>
     */
    public function managementPorts(): array
    {
        $client = $this->client();
        $read = static function (callable $fn): array {
            try {
                return $fn();
            } catch (MikroTikException $e) {
                if ($e->isConnection()) {
                    throw $e;
                }
                return [];
            }
        };
        $ips = [];
        $ports = [];
        foreach ($read(fn () => $client->print('/user/active', [], ['address', 'via'])) as $r) {
            if (str_starts_with((string) ($r['via'] ?? ''), 'api') && filter_var($r['address'] ?? '', FILTER_VALIDATE_IP)) {
                $ips[] = $r['address'];
            }
        }
        foreach ($read(fn () => $client->print('/ip/route', ['dst-address' => '0.0.0.0/0'], ['gateway', 'immediate-gw', 'active', 'disabled'])) as $r) {
            if (($r['active'] ?? 'true') !== 'true' || ($r['disabled'] ?? 'false') === 'true') {
                continue;
            }
            foreach (array_merge(explode(',', (string) ($r['gateway'] ?? '')), explode(',', (string) ($r['immediate-gw'] ?? ''))) as $g) {
                [$ip, $via] = array_pad(explode('%', trim($g), 2), 2, '');
                if ($via !== '') {
                    $ports[$via] = true;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                } elseif ($ip !== '') {
                    $ports[$ip] = true; // البوابة اسم واجهة
                }
            }
        }
        foreach (array_unique($ips) as $ip) {
            foreach ($read(fn () => $client->print('/ip/arp', ['address' => $ip], ['interface', 'mac-address'])) as $a) {
                if (($a['interface'] ?? '') !== '') {
                    $ports[(string) $a['interface']] = true;
                }
                $mac = (string) ($a['mac-address'] ?? '');
                if ($mac === '') {
                    continue;
                }
                foreach ($read(fn () => $client->print('/interface/bridge/host', ['mac-address' => $mac], ['on-interface', 'interface'])) as $h) {
                    $on = (string) ($h['on-interface'] ?? ($h['interface'] ?? ''));
                    if ($on !== '') {
                        $ports[$on] = true;
                    }
                }
            }
        }
        return array_keys($ports);
    }

    /** السرعة اللحظية لواجهة: [rx, tx] بالبت/ثانية */
    public function traffic(string $interface): array
    {
        $rows = $this->client()->command('/interface/monitor-traffic', ['interface' => $interface, 'once' => '']);
        $r = $rows[0] ?? [];
        return [
            'rx' => (int) ($r['rx-bits-per-second'] ?? 0),
            'tx' => (int) ($r['tx-bits-per-second'] ?? 0),
        ];
    }

    public function queues(): array
    {
        return $this->client()->print('/queue/simple', [], ['.id', 'name', 'target', 'max-limit', 'limit-at', 'rate', 'bytes', 'disabled', 'dynamic', 'comment']);
    }

    public function logs(int $limit = 200): array
    {
        $rows = $this->client()->print('/log', [], ['time', 'topics', 'message']);
        return array_slice(array_reverse($rows), 0, $limit);
    }

    public function neighbors(): array
    {
        return $this->client()->print('/ip/neighbor', [], ['interface', 'address', 'mac-address', 'identity', 'platform', 'version', 'board', 'uptime']);
    }

    public function services(): array
    {
        return $this->client()->print('/ip/service');
    }

    public function ping(string $address, int $count = 4): array
    {
        $client = $this->client();
        $client->setReadTimeout($count + 6);
        $rows = $client->command('/ping', ['address' => $address, 'count' => $count]);
        return $rows;
    }

    public function reboot(): void
    {
        try {
            $this->client()->command('/system/reboot');
        } catch (MikroTikException $e) {
            // جهاز MikroTik يقطع الاتصال فور بدء إعادة التشغيل
            if (!$e->isConnection()) {
                throw $e;
            }
        }
        $this->close();
    }

    public function fetch(string $url, string $dstPath): void
    {
        $client = $this->client();
        $client->setReadTimeout(40);
        $args = ['url' => $url, 'dst-path' => $dstPath];
        if (str_starts_with($url, 'https://')) {
            $args['mode'] = 'https';
            $args['check-certificate'] = 'no';
        }
        $client->command('/tool/fetch', $args);
    }

    public function files(): array
    {
        return $this->client()->print('/file', [], ['.id', 'name', 'type', 'size', 'creation-time']);
    }

    public function removeFile(string $id): void
    {
        $this->client()->remove('/file', $id);
    }

    public function backup(string $name): void
    {
        $client = $this->client();
        $client->setReadTimeout(60);
        try {
            $client->command('/system/backup/save', ['name' => $name, 'dont-encrypt' => 'yes']);
        } catch (MikroTikException $e) {
            if ($e->isConnection() || stripos($e->getMessage(), 'unknown parameter') === false) {
                throw $e;
            }
            $client->command('/system/backup/save', ['name' => $name]);
        }
    }

    public function export(string $name): void
    {
        $client = $this->client();
        $client->setReadTimeout(60);
        $client->command('/export', ['file' => $name]);
    }
}
