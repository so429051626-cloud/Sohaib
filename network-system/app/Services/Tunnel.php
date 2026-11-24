<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Platform;
use App\Core\Server;
use App\Core\Tenant;
use PDO;
use RuntimeException;
use Throwable;

/**
 * الأنفاق الآمنة (WireGuard) بين الخادم وأجهزة MikroTik التي لا تملك عنواناً عاماً.
 * القائمة تُحفظ في قاعدة الخادم، ومحرك الخادم يطبّقها على واجهة النفق.
 */
final class Tunnel
{
    public const NET = '10.77.';
    public const SERVER_ADDRESS = '10.77.0.1';
    public const INTERFACE = 'madix-nexus';
    /** أقصى عدد أنفاق للحساب الواحد */
    public const LIMIT = 100;

    private static bool $ready = false;
    private static ?array $info = null;

    /** نسيان الحالة المحفوظة في الذاكرة (عند تغيّر قاعدة البيانات أو حالة الخادم) */
    public static function reset(): void
    {
        self::$ready = false;
        self::$info = null;
    }

    private static function pdo(): PDO
    {
        $pdo = Platform::enabled() ? Platform::pdo() : DB::pdo();
        if (!self::$ready) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS tunnel_peers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NULL,
                name VARCHAR(100) NOT NULL,
                address VARCHAR(15) NOT NULL,
                public_key CHAR(44) NOT NULL,
                private_key TEXT NOT NULL,
                created_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tunnel_address (address),
                UNIQUE KEY uq_tunnel_key (public_key),
                KEY idx_tunnel_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            self::$ready = true;
        }
        return $pdo;
    }

    /** بيانات النفق من حالة الخادم: التوفر، المفتاح العام، العنوان والمنفذ */
    public static function info(): array
    {
        if (self::$info === null) {
            $t = Server::managed() ? (Server::status()['tunnel'] ?? []) : [];
            self::$info = [
                'available' => !empty($t['available']) && preg_match('#^[A-Za-z0-9+/]{43}=$#', (string) ($t['public_key'] ?? '')) && function_exists('sodium_crypto_scalarmult_base'),
                'public_key' => (string) ($t['public_key'] ?? ''),
                'endpoint' => (string) ($t['endpoint'] ?? ''),
                'port' => (int) ($t['port'] ?? 51820),
                'peers' => is_array($t['peers'] ?? null) ? $t['peers'] : [],
            ];
        }
        return self::$info;
    }

    public static function available(): bool
    {
        return (bool) self::info()['available'];
    }

    private static function scope(): array
    {
        $id = Tenant::id();
        return $id === null ? ['tenant_id IS NULL', []] : ['tenant_id = ?', [$id]];
    }

    /** أنفاق الحساب الحالي */
    public static function all(): array
    {
        [$where, $params] = self::scope();
        $st = self::pdo()->prepare("SELECT * FROM tunnel_peers WHERE $where ORDER BY id");
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        [$where, $params] = self::scope();
        $st = self::pdo()->prepare("SELECT * FROM tunnel_peers WHERE id = ? AND $where");
        $st->execute(array_merge([$id], $params));
        return $st->fetch() ?: null;
    }

    /** زوج مفاتيح WireGuard (Curve25519) بترميز Base64: [الخاص، العام] */
    public static function keypair(): array
    {
        $sk = random_bytes(32);
        $sk[0] = chr(ord($sk[0]) & 248);
        $sk[31] = chr((ord($sk[31]) & 127) | 64);
        return [base64_encode($sk), base64_encode(sodium_crypto_scalarmult_base($sk))];
    }

    private static function freeAddress(PDO $pdo): string
    {
        $used = array_flip($pdo->query('SELECT address FROM tunnel_peers')->fetchAll(PDO::FETCH_COLUMN));
        for ($a = 0; $a <= 255; $a++) {
            for ($b = 1; $b <= 254; $b++) {
                $ip = self::NET . $a . '.' . $b;
                if ($ip !== self::SERVER_ADDRESS && !isset($used[$ip])) {
                    return $ip;
                }
            }
        }
        throw new RuntimeException('لا توجد عناوين متاحة للأنفاق.');
    }

    public static function create(string $name, ?string $by = null): array
    {
        if (!self::available()) {
            throw new RuntimeException('الأنفاق الآمنة غير متاحة على هذا الخادم.');
        }
        $pdo = self::pdo();
        [$where, $params] = self::scope();
        $st = $pdo->prepare("SELECT COUNT(*) FROM tunnel_peers WHERE $where");
        $st->execute($params);
        if ((int) $st->fetchColumn() >= self::LIMIT) {
            throw new RuntimeException('بلغت الحد الأقصى لعدد الأنفاق.');
        }
        [$private, $public] = self::keypair();
        for ($try = 0; $try < 5; $try++) {
            try {
                $address = self::freeAddress($pdo);
                $pdo->prepare('INSERT INTO tunnel_peers (tenant_id, name, address, public_key, private_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([Tenant::id(), mb_substr($name, 0, 100), $address, $public, Crypto::encrypt($private), $by !== null ? mb_substr($by, 0, 120) : null, date('Y-m-d H:i:s')]);
                $id = (int) $pdo->lastInsertId();
                self::export();
                return (array) self::find($id);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('تعذّر إنشاء النفق. حاول مرة أخرى.');
    }

    /** مفاتيح جديدة لنفق موجود (العنوان يبقى كما هو) */
    public static function regenerate(int $id): ?array
    {
        if (!self::find($id)) {
            return null;
        }
        [$private, $public] = self::keypair();
        self::pdo()->prepare('UPDATE tunnel_peers SET public_key = ?, private_key = ? WHERE id = ?')->execute([$public, Crypto::encrypt($private), $id]);
        self::export();
        return self::find($id);
    }

    public static function delete(int $id): bool
    {
        if (!self::find($id)) {
            return false;
        }
        self::pdo()->prepare('DELETE FROM tunnel_peers WHERE id = ?')->execute([$id]);
        self::export();
        return true;
    }

    /** حذف كل أنفاق حساب (عند حذف الحساب نهائياً) */
    public static function purgeTenant(int $tenantId): void
    {
        try {
            $st = self::pdo()->prepare('DELETE FROM tunnel_peers WHERE tenant_id = ?');
            $st->execute([$tenantId]);
            if ($st->rowCount() > 0) {
                self::export();
            }
        } catch (Throwable $e) {
            log_message('error', 'tunnel purge: ' . $e->getMessage());
        }
    }

    /** كتابة قائمة كل الأنفاق لمحرك الخادم (مفتاح عام + عنوان في كل سطر) */
    public static function export(): void
    {
        $rows = self::pdo()->query('SELECT public_key, address FROM tunnel_peers ORDER BY id')->fetchAll();
        $out = '';
        foreach ($rows as $r) {
            if (preg_match('#^[A-Za-z0-9+/]{43}=$#', (string) $r['public_key']) && preg_match('/^10\.77\.\d{1,3}\.\d{1,3}$/', (string) $r['address'])) {
                $out .= $r['public_key'] . ' ' . $r['address'] . "\n";
            }
        }
        $dir = Server::spool();
        $tmp = $dir . '/tunnel.peers.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $out, LOCK_EX) !== false) {
            @chmod($tmp, 0660);
            @rename($tmp, $dir . '/tunnel.peers');
        }
        @file_put_contents($dir . '/queue.flag', (string) microtime(true), LOCK_EX);
    }

    /** حالة اتصال النفق من آخر مصافحة سجّلها الخادم */
    public static function state(array $peer): array
    {
        $p = self::info()['peers'][$peer['public_key']] ?? null;
        $hs = (int) ($p['handshake'] ?? 0);
        return ['connected' => $hs > 0 && time() - $hs < 200, 'handshake' => $hs, 'rx' => (int) ($p['rx'] ?? 0), 'tx' => (int) ($p['tx'] ?? 0)];
    }

    /** أوامر RouterOS 7 لإنشاء النفق على جهاز MikroTik */
    public static function script(array $peer): string
    {
        $info = self::info();
        $if = self::INTERFACE;
        $private = Crypto::decrypt((string) $peer['private_key']);
        $rule = '/ip firewall filter add chain=input in-interface=' . $if . ' action=accept comment="MADIX NEXUS"';
        return implode("\r\n", [
            ':do { /ip address remove [find where interface="' . $if . '"] } on-error={}',
            ':do { /interface wireguard peers remove [find where interface="' . $if . '"] } on-error={}',
            ':do { /interface wireguard remove [find where name="' . $if . '"] } on-error={}',
            ':do { /ip firewall filter remove [find where comment="MADIX NEXUS"] } on-error={}',
            '/interface wireguard add name=' . $if . ' listen-port=51877 private-key="' . $private . '"',
            '/interface wireguard peers add interface=' . $if . ' public-key="' . $info['public_key'] . '" endpoint-address=' . $info['endpoint'] . ' endpoint-port=' . $info['port'] . ' allowed-address=' . self::SERVER_ADDRESS . '/32 persistent-keepalive=25s',
            '/ip address add address=' . $peer['address'] . '/32 network=' . self::SERVER_ADDRESS . ' interface=' . $if,
            ':local r [/ip firewall filter find where dynamic=no]; :if ([:len $r] > 0) do={ ' . $rule . ' place-before=[:pick $r 0] } else={ ' . $rule . ' }',
        ]) . "\r\n";
    }

    /** عنوان داخل شبكة الأنفاق لا يتبع الحساب الحالي */
    public static function foreign(string $host): bool
    {
        if (!str_starts_with($host, self::NET) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if ($host === self::SERVER_ADDRESS) {
            return true;
        }
        try {
            [$where, $params] = self::scope();
            $st = self::pdo()->prepare("SELECT COUNT(*) FROM tunnel_peers WHERE address = ? AND $where");
            $st->execute(array_merge([$host], $params));
            return (int) $st->fetchColumn() === 0;
        } catch (Throwable) {
            return true;
        }
    }
}
