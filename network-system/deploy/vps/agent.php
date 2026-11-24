<?php
/*
 * MADIX NEXUS server agent.
 *
 * Runs as root every minute (cron) and on demand (systemd path unit). It is standalone on purpose:
 * it loads no application code, reads what the web user writes only after strict validation,
 * and writes only inside root-owned directories.
 *
 *   php agent.php tick     requests + tunnel + status + watchdog
 *   php agent.php queue    requests + tunnel only
 *   php agent.php collect  refresh the status file
 *   php agent.php tunnel   apply the tunnel peers now
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run as root.\n");
    exit(1);
}

const STATE_DIR  = '/var/lib/madix';
const ENV_FILE   = '/etc/madix/madix.env';
const BACKUP_DIR = '/var/backups/madix';
const TOOL       = '/usr/local/bin/madix';
const WG_NAME    = 'madix';
const WG_CONF    = '/etc/wireguard/madix.conf';
const WG_PORT    = 51820;
const WEB_USER   = 'www-data';
const REQUESTS   = ['backup', 'collect'];

// ------------------------------------------------------------------ helpers

/** @return array<string,string> */
function env(): array
{
    static $env = null;
    if ($env === null) {
        $env = [];
        foreach (@file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = $m[2];
            }
        }
    }
    return $env;
}

function app_dir(): string
{
    $dir = env()['APP_DIR'] ?? '/var/www/madix';
    return preg_match('#^/[A-Za-z0-9/_.-]+$#', $dir) ? rtrim($dir, '/') : '/var/www/madix';
}

/**
 * Run a command without a shell.
 * @param list<string> $cmd
 * @return array{code:int, out:string}
 */
function run(array $cmd, ?string $stdin = null, int $timeout = 30): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, '/', ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
    if (!is_resource($proc)) {
        return ['code' => 127, 'out' => ''];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $status = proc_get_status($proc);
        $out .= (string) stream_get_contents($pipes[1]);
        if (!$status['running']) {
            $code = $status['exitcode'];
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $code = 124;
            break;
        }
        usleep(50000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    return ['code' => (int) $code, 'out' => trim($out)];
}

function has(string $bin): bool
{
    static $cache = [];
    if (!isset($cache[$bin])) {
        $cache[$bin] = false;
        foreach (['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'] as $dir) {
            if (is_executable($dir . '/' . $bin)) {
                $cache[$bin] = true;
                break;
            }
        }
    }
    return $cache[$bin];
}

function systemd(): bool
{
    return is_dir('/run/systemd/system');
}

function service_active(string $name): bool
{
    if (systemd()) {
        return run(['systemctl', 'is-active', '--quiet', $name], null, 10)['code'] === 0;
    }
    return run(['service', $name, 'status'], null, 10)['code'] === 0;
}

function service_restart(string $name): bool
{
    $r = systemd() ? run(['systemctl', 'restart', $name], null, 90) : run(['service', $name, 'restart'], null, 90);
    if ($r['code'] !== 0) {
        sleep(3);
        $r = systemd() ? run(['systemctl', 'start', $name], null, 90) : run(['service', $name, 'start'], null, 90);
    }
    return $r['code'] === 0;
}

/** PHP-FPM service when Apache runs PHP through it, or '' */
function php_service(): string
{
    foreach (glob('/etc/apache2/conf-enabled/php*-fpm.conf') ?: [] as $f) {
        return basename($f, '.conf');
    }
    return '';
}

function db_service(): string
{
    static $name = null;
    if ($name === null) {
        $name = (is_file('/etc/init.d/mariadb') || is_file('/lib/systemd/system/mariadb.service') || is_file('/usr/lib/systemd/system/mariadb.service')) ? 'mariadb' : 'mysql';
    }
    return $name;
}

/** Write a file readable by the web user, atomically, inside the root-owned state directory. */
function put_state(string $name, array $data): void
{
    $file = STATE_DIR . '/' . $name;
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chgrp($tmp, WEB_USER);
    @chmod($tmp, 0640);
    rename($tmp, $file);
}

function get_json(string $file): array
{
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Private agent memory (root only). */
function memory(?array $set = null): array
{
    static $mem = null;
    $file = STATE_DIR . '/agent.json';
    if ($mem === null) {
        $mem = get_json($file);
    }
    if ($set !== null) {
        $mem = $set;
        file_put_contents($file . '.tmp', json_encode($mem));
        @chmod($file . '.tmp', 0600);
        rename($file . '.tmp', $file);
    }
    return $mem;
}

/** A regular file written by the web user: never a link, never large. */
function web_file(string $path, int $max = 2000000): ?string
{
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path) || filesize($path) > $max) {
        return null;
    }
    $raw = @file_get_contents($path, false, null, 0, $max);
    return $raw === false ? null : $raw;
}

function alert(string $code, string $level, array $args = [], int $cooldown = 3600): bool
{
    $mem = memory();
    $key = $code . ':' . ($args['name'] ?? '');
    $now = time();
    if (($mem['alerted'][$key] ?? 0) > $now - $cooldown) {
        return false;
    }
    $mem['alerted'][$key] = $now;
    memory($mem);
    $file = STATE_DIR . '/alerts.json';
    $data = get_json($file);
    $id = (int) ($data['last_id'] ?? 0) + 1;
    $items = $data['items'] ?? [];
    array_unshift($items, ['id' => $id, 'time' => $now, 'level' => $level, 'code' => $code, 'args' => $args]);
    put_state('alerts.json', ['last_id' => $id, 'items' => array_slice($items, 0, 100)]);
    $GLOBALS['new_alerts'] = true;
    return true;
}

// ------------------------------------------------------------------ tunnel

function wg_up(): bool
{
    return has('wg') && run(['wg', 'show', WG_NAME], null, 10)['code'] === 0;
}

function wg_private(): string
{
    foreach (@file(WG_CONF, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('#^PrivateKey\s*=\s*([A-Za-z0-9+/]{43}=)\s*$#', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

/** Peers requested by the system: public key => address. */
function wanted_peers(): array
{
    $raw = web_file(app_dir() . '/storage/server/tunnel.peers');
    $peers = [];
    $used = [];
    foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
        if (!preg_match('#^([A-Za-z0-9+/]{43}=) (10\.77\.(\d{1,3})\.(\d{1,3}))$#', $line, $m)) {
            continue;
        }
        $a = (int) $m[3];
        $b = (int) $m[4];
        if ($a > 255 || $b < 1 || $b > 254 || ($a === 0 && $b === 1) || isset($peers[$m[1]]) || isset($used[$m[2]])) {
            continue;
        }
        $peers[$m[1]] = $m[2];
        $used[$m[2]] = true;
        if (count($peers) >= 60000) {
            break;
        }
    }
    return $peers;
}

function tunnel_sync(bool $force = false): void
{
    if (!has('wg') || !has('wg-quick')) {
        return;
    }
    $mem = memory();
    $peers = wanted_peers();
    $hash = sha1(json_encode($peers));
    $private = wg_private();
    $up = wg_up();
    if (!$force && $private !== '' && $up && $hash === ($mem['tunnel_hash'] ?? '')) {
        return;
    }
    if ($private === '') {
        $private = run(['wg', 'genkey'])['out'];
        if (!preg_match('#^[A-Za-z0-9+/]{43}=$#', $private)) {
            return;
        }
    }
    $wg = "PrivateKey = $private\nListenPort = " . WG_PORT . "\n";
    $body = '';
    foreach ($peers as $key => $ip) {
        $body .= "\n[Peer]\nPublicKey = $key\nAllowedIPs = $ip/32\n";
    }
    @mkdir(dirname(WG_CONF), 0700, true);
    $tmp = WG_CONF . '.tmp';
    file_put_contents($tmp, "# Managed by MADIX NEXUS. Peers are set from the system.\n[Interface]\nAddress = 10.77.0.1/16\n" . $wg . $body);
    chmod($tmp, 0600);
    rename($tmp, WG_CONF);

    if ($up) {
        $strip = '/run/madix-wg.' . getmypid();
        file_put_contents($strip, "[Interface]\n" . $wg . $body);
        chmod($strip, 0600);
        $ok = run(['wg', 'syncconf', WG_NAME, $strip], null, 20)['code'] === 0;
        @unlink($strip);
    } else {
        if (systemd()) {
            run(['systemctl', 'enable', 'wg-quick@' . WG_NAME], null, 20);
            $ok = run(['systemctl', 'restart', 'wg-quick@' . WG_NAME], null, 40)['code'] === 0;
        } else {
            $ok = run(['wg-quick', 'up', WG_NAME], null, 40)['code'] === 0;
        }
    }
    if ($ok) {
        $mem = memory();
        $mem['tunnel_hash'] = $hash;
        memory($mem);
    }
}

// ------------------------------------------------------------------ requests

/** @return list<string> accepted request names (their files are removed) */
function queue(): array
{
    $dir = app_dir() . '/storage/server/requests';
    $accepted = [];
    foreach (REQUESTS as $name) {
        $file = $dir . '/' . $name;
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file)) {
            continue;
        }
        @unlink($file);
        $accepted[] = $name;
    }
    tunnel_sync();
    return $accepted;
}

// ------------------------------------------------------------------ status

function public_ip(): string
{
    $mem = memory();
    if (($mem['ip_time'] ?? 0) > time() - 21600 && ($mem['ip'] ?? '') !== '') {
        return $mem['ip'];
    }
    $ip = '';
    if (has('curl')) {
        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $url) {
            $out = run(['curl', '-4', '-fsS', '--max-time', '6', $url], null, 10)['out'];
            if (filter_var($out, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $out;
                break;
            }
        }
    }
    if ($ip === '') {
        $ip = explode(' ', run(['hostname', '-I'])['out'])[0] ?? '';
    }
    $mem['ip'] = $ip;
    $mem['ip_time'] = time();
    memory($mem);
    return $ip;
}

function cpu_usage(): int
{
    $read = static function (): array {
        $line = (string) (@file('/proc/stat')[0] ?? '');
        $p = array_map('floatval', array_slice(preg_split('/\s+/', trim($line)) ?: [], 1, 8));
        return ['total' => array_sum($p), 'idle' => ($p[3] ?? 0) + ($p[4] ?? 0)];
    };
    $mem = memory();
    $now = $read();
    $prev = $mem['cpu'] ?? null;
    if (!is_array($prev) || $now['total'] <= ($prev['total'] ?? 0)) {
        usleep(400000);
        $prev = $now;
        $now = $read();
    }
    $mem['cpu'] = $now;
    memory($mem);
    $dt = $now['total'] - $prev['total'];
    return $dt > 0 ? (int) max(0, min(100, round(100 * (1 - ($now['idle'] - $prev['idle']) / $dt)))) : 0;
}

function pending_updates(): array
{
    $mem = memory();
    if (($mem['upd_time'] ?? 0) < time() - 21600) {
        $all = $sec = 0;
        if (is_executable('/usr/lib/update-notifier/apt-check')) {
            $proc = @proc_open(['/usr/lib/update-notifier/apt-check'], [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                $out = trim((string) stream_get_contents($pipes[2]));
                fclose($pipes[2]);
                proc_close($proc);
                if (preg_match('/^(\d+);(\d+)$/', $out, $m)) {
                    [$all, $sec] = [(int) $m[1], (int) $m[2]];
                }
            }
        } else {
            $out = run(['apt-get', '-s', '-o', 'Debug::NoLocking=true', 'upgrade'], null, 60)['out'];
            $all = preg_match_all('/^Inst /m', $out);
            $sec = preg_match_all('/^Inst .*security/mi', $out);
        }
        $mem['upd'] = ['pending' => (int) $all, 'security' => (int) $sec];
        $mem['upd_time'] = time();
        memory($mem);
    }
    return ($mem['upd'] ?? ['pending' => 0, 'security' => 0]) + ['reboot' => is_file('/var/run/reboot-required')];
}

function backups(): array
{
    $list = [];
    foreach (glob(BACKUP_DIR . '/20*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (!preg_match('/^(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)(\d\d)$/', $name, $m)) {
            continue;
        }
        $size = 0;
        $dbs = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            $size += (int) @filesize($f);
            $dbs += str_ends_with($f, '.sql.gz') ? 1 : 0;
        }
        $list[] = ['name' => $name, 'time' => (int) mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]), 'size' => $size, 'dbs' => $dbs];
    }
    usort($list, static fn ($a, $b) => $b['time'] <=> $a['time']);
    return $list;
}

function ssl_info(): ?array
{
    $domain = env()['DOMAIN'] ?? '';
    $file = '/etc/letsencrypt/live/' . $domain . '/cert.pem';
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain) || !is_file($file) || !function_exists('openssl_x509_parse')) {
        return null;
    }
    $cert = @openssl_x509_parse((string) file_get_contents($file));
    return $cert ? ['domain' => $domain, 'expires' => (int) $cert['validTo_time_t']] : null;
}

/** Disk use of the root filesystem in MB, counted the way df does (used against used + available). */
function disk_usage(): array
{
    $line = explode("\n", run(['df', '-Pk', '/'], null, 10)['out'])[1] ?? '';
    $p = preg_split('/\s+/', trim($line)) ?: [];
    if (count($p) >= 4 && ctype_digit($p[2]) && ctype_digit($p[3])) {
        return ['total' => (int) round(((int) $p[2] + (int) $p[3]) / 1024), 'used' => (int) round((int) $p[2] / 1024)];
    }
    $total = (float) @disk_total_space('/');
    $free = (float) @disk_free_space('/');
    return ['total' => (int) round($total / 1048576), 'used' => (int) round(($total - $free) / 1048576)];
}

function collect(): array
{
    $env = env();
    $meminfo = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $meminfo[$m[1]] = (int) round((int) $m[2] / 1024);
        }
    }
    $os = '';
    foreach (@file('/etc/os-release') ?: [] as $line) {
        if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/', $line, $m)) {
            $os = $m[1];
        }
    }
    $mem = memory();
    if (($mem['dbv_time'] ?? 0) < time() - 86400 || ($mem['dbv'] ?? '') === '') {
        $v = run(['mysql', '--protocol=socket', '-uroot', '-N', '-e', 'SELECT VERSION()'], null, 10)['out'];
        $mem = memory();
        $mem['dbv'] = preg_match('/^[\w.+~:-]{3,80}$/', $v) ? $v : '';
        $mem['dbv_time'] = time();
        memory($mem);
    }
    $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
    $list = backups();

    $tunnel = ['available' => false, 'port' => WG_PORT, 'endpoint' => ($env['DOMAIN'] ?? '') !== '' ? $env['DOMAIN'] : public_ip(), 'public_key' => '', 'peers' => []];
    if (wg_up()) {
        $tunnel['available'] = true;
        $tunnel['public_key'] = run(['wg', 'show', WG_NAME, 'public-key'], null, 10)['out'];
        foreach (array_slice(explode("\n", run(['wg', 'show', WG_NAME, 'dump'], null, 10)['out']), 1) as $line) {
            $p = explode("\t", $line);
            if (count($p) >= 7) {
                $tunnel['peers'][$p[0]] = ['handshake' => (int) $p[4], 'rx' => (int) $p[5], 'tx' => (int) $p[6]];
            }
        }
    }
    $beat = app_dir() . '/storage/server/cron.beat';
    clearstatcache(true, $beat);

    $status = [
        'time' => time(),
        'version' => $env['MADIX_VERSION'] ?? '',
        'mode' => $env['MODE'] ?? '',
        'host' => [
            'os' => $os, 'kernel' => php_uname('r'), 'name' => php_uname('n'), 'ip' => public_ip(), 'domain' => $env['DOMAIN'] ?? '',
            'uptime' => (int) (float) (@file_get_contents('/proc/uptime') ?: '0'),
        ],
        'cpu' => ['cores' => max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'))), 'usage' => cpu_usage(), 'load' => array_map(static fn ($v) => round((float) $v, 2), array_slice($load, 0, 3))],
        'mem' => [
            'total' => $meminfo['MemTotal'] ?? 0, 'used' => max(0, ($meminfo['MemTotal'] ?? 0) - ($meminfo['MemAvailable'] ?? 0)),
            'swap_total' => $meminfo['SwapTotal'] ?? 0, 'swap_used' => max(0, ($meminfo['SwapTotal'] ?? 0) - ($meminfo['SwapFree'] ?? 0)),
        ],
        'disk' => disk_usage(),
        'services' => [
            'web' => service_active('apache2'),
            'db' => service_active(db_service()),
            'cron' => service_active('cron'),
            'firewall' => has('ufw') ? str_contains(run(['ufw', 'status'], null, 15)['out'], 'Status: active') : null,
            'guard' => has('fail2ban-client') ? service_active('fail2ban') : null,
            'tunnel' => has('wg') ? $tunnel['available'] : null,
        ],
        'ssl' => ssl_info(),
        'updates' => pending_updates(),
        'backup' => ['last' => $list[0]['time'] ?? 0, 'count' => count($list), 'size' => array_sum(array_column($list, 'size')), 'list' => array_slice($list, 0, 14)],
        'tunnel' => $tunnel,
        'cron' => ['last' => (!is_link($beat) && is_file($beat)) ? (int) filemtime($beat) : 0],
        'software' => ['php' => PHP_VERSION, 'db' => memory()['dbv'] ?? ''],
    ];
    put_state('status.json', $status);
    return $status;
}

// ------------------------------------------------------------------ watchdog

function watchdog(array $s): void
{
    $mem = memory();
    $installed = (int) ($mem['since'] ?? 0);
    if ($installed === 0) {
        $mem['since'] = $installed = time();
        memory($mem);
    }
    foreach (['web' => 'apache2', 'db' => db_service(), 'cron' => 'cron'] as $key => $service) {
        if ($s['services'][$key] === false) {
            $ok = service_restart($service) && service_active($service);
            alert($ok ? 'service_restarted' : 'service_down', $ok ? 'warning' : 'danger', ['name' => $key], 600);
        }
    }
    // PHP-FPM: when it stops, every page answers 503 although Apache runs
    if (($php = php_service()) !== '' && !service_active($php)) {
        $ok = service_restart($php) && service_active($php);
        alert($ok ? 'service_restarted' : 'service_down', $ok ? 'warning' : 'danger', ['name' => 'web'], 600);
    }

    // the site itself must answer
    if ($s['services']['web'] && has('curl')) {
        $host = ($s['host']['domain'] ?? '') !== '' ? $s['host']['domain'] : 'localhost';
        $cmd = ['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '12'];
        $cmd = $s['ssl'] !== null
            ? [...$cmd, '--resolve', $host . ':443:127.0.0.1', 'https://' . $host . '/health']
            : [...$cmd, '-H', 'Host: ' . $host, 'http://127.0.0.1/health'];
        $code = run($cmd, null, 15)['out'];
        $mem = memory();
        $fails = in_array($code, ['200', '301', '302', '308'], true) ? 0 : (int) ($mem['web_fails'] ?? 0) + 1;
        if ($fails >= 3) {
            if (($php = php_service()) !== '') {
                service_restart($php);
            }
            $ok = service_restart('apache2');
            alert($ok ? 'web_recovered' : 'web_unresponsive', $ok ? 'warning' : 'danger', [], 900);
            $fails = 0;
        }
        $mem['web_fails'] = $fails;
        memory($mem);
    }

    $pct = $s['disk']['total'] > 0 ? (int) round(100 * $s['disk']['used'] / $s['disk']['total']) : 0;
    if ($pct >= 90) {
        alert('disk_high', $pct >= 96 ? 'danger' : 'warning', ['percent' => $pct], 43200);
    }

    if ($s['ssl'] !== null) {
        $days = (int) floor(($s['ssl']['expires'] - time()) / 86400);
        $mem = memory();
        if ($days < 15 && has('certbot') && ($mem['renew_time'] ?? 0) < time() - 43200) {
            $mem['renew_time'] = time();
            memory($mem);
            run(['certbot', 'renew', '--quiet'], null, 300);
        }
        if ($days < 7) {
            alert('ssl_expiring', 'danger', ['days' => max(0, $days)], 86400);
        }
    }

    if ($installed < time() - 129600 && $s['backup']['last'] < time() - 129600) {
        alert('backup_stale', 'warning', [], 86400);
    }

    if ($s['cron']['last'] > 0 && $s['cron']['last'] < time() - 600 && $installed < time() - 900) {
        service_restart('cron');
        alert('tasks_stale', 'warning', ['minutes' => (int) round((time() - $s['cron']['last']) / 60)], 21600);
    }

    if (!empty($s['updates']['reboot'])) {
        alert('reboot_required', 'info', [], 604800);
    }
}

function push_alerts(): void
{
    $php = PHP_BINARY ?: 'php';
    $script = app_dir() . '/cli/server.php';
    if (is_file($script) && has('runuser')) {
        run(['runuser', '-u', WEB_USER, '--', $php, $script, 'alerts'], null, 120);
    }
}

// ------------------------------------------------------------------ main

$command = $argv[1] ?? 'tick';
if (!is_dir(STATE_DIR)) {
    mkdir(STATE_DIR, 0750, true);
}
@chgrp(STATE_DIR, WEB_USER);
@chmod(STATE_DIR, 0750);

$lock = fopen(STATE_DIR . '/agent.lock', 'c');
// the minute tick skips when busy; an explicit request waits for its turn
if (!$lock || !flock($lock, $command === 'tick' ? LOCK_EX | LOCK_NB : LOCK_EX)) {
    exit(0);
}
$GLOBALS['new_alerts'] = false;
$requests = [];

try {
    switch ($command) {
        case 'tick':
            $requests = queue();
            watchdog(collect());
            break;
        case 'queue':
            $requests = queue();
            collect();
            break;
        case 'collect':
            collect();
            break;
        case 'tunnel':
            tunnel_sync(true);
            collect();
            break;
        default:
            fwrite(STDERR, "Usage: agent.php tick|queue|collect|tunnel\n");
            exit(1);
    }
    if ($GLOBALS['new_alerts']) {
        push_alerts();
    }
} finally {
    flock($lock, LOCK_UN);
}

// a requested backup can take minutes: it runs outside the lock so the status keeps refreshing
if (in_array('backup', $requests, true) && is_executable(TOOL)) {
    $r = run([TOOL, 'backup', '--quiet', '--offsite'], null, 7200);
    flock($lock, LOCK_EX);
    if ($r['code'] !== 0) {
        alert('backup_failed', 'danger', [], 600);
        push_alerts();
    }
    collect();
    flock($lock, LOCK_UN);
}
fclose($lock);
