<?php
/*
 * madix speed [HOURS] [APP_DIR]: where the time goes when a page opens.
 * 1) The server: how long it takes to build each page, from the Apache timing log
 *    (/var/log/apache2/madix-timing.log, written for every page but not for /assets files).
 *    Each line: unix-time status total-µs first-byte-µs method uri
 *      first byte = the time the server spent building the page (PHP and the database)
 *      total      = until the whole page was handed to the visitor's connection (includes slow networks)
 * 2) The phones and computers: what the browsers measured themselves, from APP_DIR/storage/logs/rum.log
 *    (one JSON line per opened page, sent by every page). From the tap on a link until the page is drawn:
 *      tap→start  the browser had not started loading yet (the previous page was still busy)
 *      connect    DNS, TCP and TLS to reach the server (zero when the connection was already open)
 *      server     the server building the page
 *      network    waiting for the answer beyond the server time (the distance and quality of the internet)
 *      download   receiving the page
 *      files      waiting for the style and script files the page needs before it can appear
 *      drawing    the phone running scripts, working out styles and drawing the page
 */
declare(strict_types=1);

$hours = max(1, min(24 * 30, (int) ($argv[1] ?? 24)));
$appDir = rtrim((string) ($argv[2] ?? '/var/www/madix'), '/');
$since = time() - $hours * 3600;
$files = glob('/var/log/apache2/madix-timing.log*') ?: [];
if (!$files) {
    echo "No server timing log yet. It starts after this update; open some pages, then run: madix speed\n";
    rum_report($appDir, $since);
    exit(0);
}

/** link without numbers and keys, so /net/subscribers/12 and /net/subscribers/13 count as one page */
function page_of(string $uri): string
{
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $path = (string) preg_replace('#/[0-9a-f]{24,}(?=/|$|\.)#i', '/{key}', $path);
    $path = (string) preg_replace('#/\d+(?=/|$)#', '/{id}', $path);
    return $path === '' ? '/' : $path;
}

function pct(array $sorted, float $p): float
{
    if (!$sorted) {
        return 0.0;
    }
    return $sorted[(int) min(count($sorted) - 1, floor($p * (count($sorted) - 1) + 0.5))];
}

$pages = [];
$all = [];
$slow = [];
$errors = 0;
$total = 0;
foreach ($files as $file) {
    $h = str_ends_with($file, '.gz') ? @gzopen($file, 'r') : @fopen($file, 'r');
    if (!$h) {
        continue;
    }
    while (($line = str_ends_with($file, '.gz') ? gzgets($h) : fgets($h)) !== false) {
        $p = explode(' ', rtrim($line), 6);
        if (count($p) < 6 || (int) $p[0] < $since) {
            continue;
        }
        [$ts, $status, $us, $fb, $method, $uri] = $p;
        $build = ((int) $fb > 0 ? (int) $fb : (int) $us) / 1000;
        $sent = (int) $us / 1000;
        $page = $method . ' ' . page_of($uri);
        $pages[$page]['b'][] = $build;
        $pages[$page]['s'][] = $sent;
        $all[] = $build;
        $total++;
        if ((int) $status >= 500) {
            $errors++;
        }
        $slow[] = [$build, (int) $ts, (int) $status, $method . ' ' . (string) parse_url($uri, PHP_URL_PATH)];
        if (count($slow) > 200) {
            usort($slow, static fn ($a, $b) => $b[0] <=> $a[0]);
            $slow = array_slice($slow, 0, 10);
        }
    }
    str_ends_with($file, '.gz') ? gzclose($h) : fclose($h);
}
if (!$total) {
    echo "No pages were opened in the last $hours hours.\n";
    rum_report($appDir, $since);
    exit(0);
}
sort($all);
usort($slow, static fn ($a, $b) => $b[0] <=> $a[0]);

$B = "\033[1m";
$N = "\033[0m";
$G = "\033[32m";
$Y = "\033[33m";
$R = "\033[31m";
$color = static fn (float $ms) => $ms < 300 ? $G : ($ms < 1000 ? $Y : $R);

printf("%sMADIX NEXUS speed%s  last %d hours, %d page requests\n", $B, $N, $hours, $total);
printf("Server time to build a page: half under %s%.0f ms%s, 95%% under %s%.0f ms%s, slowest %s%.0f ms%s\n",
    $color(pct($all, 0.5)), pct($all, 0.5), $N, $color(pct($all, 0.95)), pct($all, 0.95), $N, $color(end($all)), end($all), $N);
if ($errors) {
    printf("%sServer errors (5xx): %d%s  see: madix logs\n", $R, $errors, $N);
}

$rows = [];
foreach ($pages as $page => $v) {
    sort($v['b']);
    sort($v['s']);
    $rows[] = [$page, count($v['b']), pct($v['b'], 0.5), pct($v['b'], 0.95), end($v['b']), pct($v['s'], 0.95)];
}
usort($rows, static fn ($a, $b) => $b[3] <=> $a[3]);
echo "\n{$B}Slowest pages{$N} (server build time in ms; 'sent' includes sending to the visitor)\n";
printf("  %7s %7s %7s %9s %6s  %s\n", 'median', '95%', 'max', 'sent 95%', 'count', 'page');
foreach (array_slice($rows, 0, 15) as [$page, $n, $med, $p95, $max, $s95]) {
    printf("  %s%7.0f %7.0f %7.0f%s %9.0f %6d  %s\n", $color($p95), $med, $p95, $max, $N, $s95, $n, $page);
}

echo "\n{$B}Slowest single requests{$N}\n";
foreach (array_slice($slow, 0, 8) as [$ms, $ts, $status, $page]) {
    printf("  %s%7.0f ms%s  %s  %d  %s\n", $color($ms), $ms, $N, date('m-d H:i:s', $ts), $status, $page);
}

$p95 = pct($all, 0.95);
echo "\n";
if ($p95 < 300) {
    echo "{$G}The server builds pages fast.{$N} If pages still feel slow, the time goes to the network or the phone (see below).\n";
} elseif ($p95 < 1000) {
    echo "{$Y}Some pages take the server a noticeable time.{$N} The pages at the top of the list are the ones to look at.\n";
} else {
    echo "{$R}Some pages are slow on the server.{$N} Check memory and the pages at the top: madix status, madix doctor.\n";
}
rum_report($appDir, $since);

/** what the browsers measured: from the tap on a link until the page was drawn, split into its parts */
function rum_report(string $appDir, int $since): void
{
    $B = "\033[1m";
    $N = "\033[0m";
    $G = "\033[32m";
    $Y = "\033[33m";
    $R = "\033[31m";
    $color = static fn (float $ms) => $ms < 1000 ? $G : ($ms < 2500 ? $Y : $R);
    $rows = [];
    foreach ([$appDir . '/storage/logs/rum.log.1', $appDir . '/storage/logs/rum.log'] as $file) {
        $h = @fopen($file, 'r');
        if (!$h) {
            continue;
        }
        while (($line = fgets($h)) !== false) {
            $d = json_decode($line, true);
            if (is_array($d) && (int) ($d['ts'] ?? 0) >= $since && isset($d['fcp'])) {
                $rows[] = $d;
            }
        }
        fclose($h);
    }
    echo "\n{$B}Measured on the phones and computers{$N} (from the browser of each person who opened a page)\n";
    if (!$rows) {
        echo "  Nothing measured yet. It starts after this update: open some pages on your phone (move between pages as usual),\n";
        echo "  then run: madix speed\n";
        return;
    }
    $med = static function (array $list, float $p = 0.5): float {
        $list = array_values(array_filter($list, static fn ($v) => $v !== null));
        if (!$list) {
            return 0.0;
        }
        sort($list);
        return (float) $list[(int) min(count($list) - 1, floor($p * (count($list) - 1) + 0.5))];
    };
    $parts = static function (array $d): array {
        $fcp = (int) ($d['fcp'] ?? 0);
        $end = (int) ($d['end'] ?? 0);
        $ready = max($end, (int) ($d['rb'] ?? 0));
        $srv = max(0, (int) ($d['s'] ?? 0));
        $ttfb = (int) ($d['ttfb'] ?? 0);
        $prefetched = ($d['dt'] ?? '') === 'navigational-prefetch';
        return [
            'tap' => (int) ($d['tap'] ?? -1) >= 0 ? (int) $d['tap'] : null,
            'connect' => (int) ($d['pre'] ?? 0),
            'server' => $prefetched ? 0 : min($srv, $ttfb),
            'network' => $prefetched ? 0 : max(0, $ttfb - $srv),
            'download' => (int) ($d['dl'] ?? 0),
            'files' => $fcp > 0 ? max(0, min($fcp, $ready) - $end) : null,
            'drawing' => $fcp > 0 ? max(0, $fcp - $ready) : null,
            'shown' => $fcp > 0 ? $fcp + max(0, (int) ($d['tap'] ?? -1)) : null,
        ];
    };
    $labels = [
        'tap' => 'tap→start (previous page busy)', 'connect' => 'connect (DNS, TCP, TLS)', 'server' => 'server (building the page)',
        'network' => 'network (internet delay)', 'download' => 'download', 'files' => 'files (styles/scripts to download)',
        'drawing' => 'drawing (phone scripts and styles)',
    ];
    $areas = ['net' => 'network system', 'admin' => 'admin panel', 'login' => 'login pages', 'landing' => 'home page'];
    $all = array_map($parts, $rows);
    $col = static fn (string $k) => array_map(static fn ($x) => $x[$k], $all);
    $shown = $med($col('shown'));
    $shown75 = $med($col('shown'), 0.75);
    printf("  %d pages measured. Tap to page shown: half under %s%.1f s%s, 3 in 4 under %s%.1f s%s\n",
        count($rows), $color($shown), $shown / 1000, $N, $color($shown75), $shown75 / 1000, $N);
    echo "  Where the time goes (median ms of each part):\n";
    $sizes = [];
    foreach ($labels as $k => $label) {
        $sizes[$k] = $med($col($k));
        printf("    %-34s %6.0f   (3 in 4 under %.0f)\n", $label, $sizes[$k], $med($col($k), 0.75));
    }
    $mobile = array_values(array_filter($rows, static fn ($d) => (int) ($d['m'] ?? 0) === 1));
    $proto = [];
    $net = [];
    $prefetch = 0;
    $refetch = 0;
    $early = 0;
    foreach ($rows as $d) {
        $proto[(string) ($d['h'] ?? '?') ?: '?'] = ($proto[(string) ($d['h'] ?? '?') ?: '?'] ?? 0) + 1;
        if (($d['net'] ?? '') !== '') {
            $net[$d['net']] = ($net[$d['net']] ?? 0) + 1;
        }
        $prefetch += ($d['dt'] ?? '') === 'navigational-prefetch' ? 1 : 0;
        $refetch += (int) ($d['rf'] ?? 0);
        $early += (int) ($d['early'] ?? 0);
    }
    arsort($proto);
    arsort($net);
    $mem = $med(array_map(static fn ($d) => (int) ($d['mem'] ?? 0) ?: null, $mobile));
    $rtt = $med(array_map(static fn ($d) => (int) ($d['rtt'] ?? -1) >= 0 ? (int) $d['rtt'] : null, $rows));
    printf("  Phones: %d of %d pages%s. Connection: %s%s. Protocol: %s\n", count($mobile), count($rows),
        $mem > 0 ? ', memory about ' . $mem . ' GB' : '',
        $net ? implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($net), $net)) : 'unknown',
        $rtt > 0 ? ', round trip about ' . (int) $rtt . ' ms' : '',
        implode(', ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($proto), $proto)));
    printf("  Loaded before the tap (prefetch): %d · loaded twice (old prefetch): %d · left before the page finished: %d · long script time median %.0f ms\n",
        $prefetch, $refetch, $early, $med(array_map(static fn ($d) => (int) ($d['lt'] ?? 0), $rows)));

    // by area and the slowest pages
    echo "  By area (median tap to shown, ms):";
    foreach ($areas as $a => $name) {
        $list = array_map($parts, array_values(array_filter($rows, static fn ($d) => ($d['a'] ?? '') === $a)));
        if ($list) {
            printf("  %s %.0f (%d)", $name, $med(array_map(static fn ($x) => $x['shown'], $list)), count($list));
        }
    }
    echo "\n";
    $pages = [];
    foreach ($rows as $d) {
        $pages[($d['a'] ?? '') . ' ' . ($d['p'] ?? '')][] = $parts($d)['shown'];
    }
    $list = [];
    foreach ($pages as $page => $v) {
        $list[] = [$page, count($v), $med($v), $med($v, 0.75)];
    }
    usort($list, static fn ($a, $b) => $b[3] <=> $a[3]);
    echo "  Slowest pages to show (median, 3 in 4, count):\n";
    foreach (array_slice($list, 0, 8) as [$page, $n, $m, $p75]) {
        printf("    %s%6.0f %6.0f%s %5d  %s\n", $color($p75), $m, $p75, $N, $n, $page);
    }
    $slowRes = [];
    foreach ($rows as $d) {
        if (($d['sn'] ?? '') !== '' && (int) ($d['sr'] ?? 0) > 300) {
            $slowRes[$d['sn']][] = (int) $d['sr'];
        }
    }
    if ($slowRes) {
        uasort($slowRes, static fn ($a, $b) => count($b) <=> count($a));
        echo "  Files that were slow to load (count, median ms):\n";
        foreach (array_slice($slowRes, 0, 5, true) as $name => $v) {
            printf("    %5d %6.0f  %s\n", count($v), $med($v), $name);
        }
    }

    // the verdict: the biggest part
    arsort($sizes);
    $top = (string) array_key_first($sizes);
    $why = [
        'tap' => 'The time is lost before the next page even starts loading: the previous page is still busy (scripts) when you tap.',
        'connect' => 'The time goes to opening the connection to the server (DNS, TCP, TLS). The server is far or the connection is not reused.',
        'server' => 'The time goes to the server building the page. Check the server part above.',
        'network' => 'The time goes to the internet between the phone and the server (distance or a weak connection), not to the server or the phone.',
        'download' => 'The time goes to downloading the page itself (a big page or a slow connection).',
        'files' => 'The time goes to downloading the style and script files the page needs before it appears (first visit, after an update, or a slow connection).',
        'drawing' => 'The time goes to the phone itself: running scripts and drawing the page after everything has arrived.',
    ];
    echo "\n  {$B}Biggest part: {$labels[$top]}{$N}\n  " . $why[$top] . "\n";
}
