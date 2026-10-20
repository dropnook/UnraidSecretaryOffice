<?php
declare(strict_types=1);

/*
 * «Who fills /var/log?» — Ms. Protocolli watches Unraid's RAM disk for its logs (2026-10-21). Seen in the wild: /var/log
 * at 100 % from nginx «limiting requests … zone "authlimit", client: 127.0.0.1, … GET /login» with referrers
 * /nginx_status, /stub_status, /basic_status — Netdata's nginx collector looking for a status page on its own and Unraid
 * answering with its login. Unraid then breaks in odd ways (no logs, errors on its pages).
 * Functions only, in a file of their own beside her desk (logs.php require_once's it; the agent's desk glob loads it
 * first — «logs-flood» sorts before «logs.php» in every locale, as logs-diskwakes.php does).
 * Read only, RAM only: statvfs, lstat under /var/log, a tail of ONE file, /proc — never a disk, never a change.
 *
 * In her tick, every LOGS_FLOOD_EVERY s (nothing but a clock compare in between):
 *   always        the fill level (logsVarlog()) and every file's size under /var/log (lstat, links stay out); the last
 *                 LOGS_FLOOD_SAMPLES looks kept, so each file's growth over the last minutes is known at once
 *   from 80 %     (LOGS_VARLOG_FULL, the Team Lead's «still to do») the case: the file that grows fastest (none growing:
 *                 the biggest), its newest LOGS_FLOOD_TAIL bytes read — every LOGS_FLOOD_RESAMPLE s or when the file
 *                 changes — and its lines by kind (logsNormalize(): numbers, addresses, times as one), the most frequent
 *                 with their share; nginx's lines of a client on this server itself (127.0.0.1, ::1, its own addresses)
 *                 counted apart, with the addresses they asked for (path only, never a query)
 *                 a known poller by those addresses (LOGS_FLOOD_POLLERS) — a second hint; and, while such lines fill it,
 *                 the program behind them: a short job of its own (logsFloodCatchRun(), `php agent.php job
 *                 logs-flood-catch`) looks at /proc/net/tcp* every LOGS_FLOOD_CATCH_STEP s for a minute — a connection
 *                 from here to the web UI's port → its socket → the process holding it (/proc/<pid>/fd) → its container
 *                 (cgroup → docker's name). The connections are short: caught or plainly not.
 *   from 90 %     (LOGS_FLOOD_NOTIFY) one notification through the office's gate (officeNotify(), event varlog_full,
 *                 warning) — after the catch, so it can say who; once, until the level is below 80 % again
 * Her memory: RAM (RUN_DIR/logs-flood.json — what the page and the Team Lead's hint read; a restarted agent keeps the
 * growth and «told»; a reboot empties /var/log anyway). A line shown on her page is cut and scrubbed of what looks like
 * a key or a password (logsFloodScrub()); the agent's log names the file, never a line.
 */

const LOGS_FLOOD_EVERY      = 60;            // seconds between her looks
const LOGS_FLOOD_SAMPLES    = 11;            // looks kept: the growth over the last ten minutes
const LOGS_FLOOD_WALK       = 3000;          // files looked at under /var/log at most
const LOGS_FLOOD_KEEP       = 300;           // files kept per look (the biggest)
const LOGS_FLOOD_ROWS       = 5;             // fastest-growing files shown
const LOGS_FLOOD_NOTIFY     = 90;            // % full: one notification
const LOGS_FLOOD_TAIL       = 256 * 1024;    // bytes read of the file that fills it (its newest)
const LOGS_FLOOD_LINES      = 4000;          // … and lines of it at most
const LOGS_FLOOD_RESAMPLE   = 300;           // its lines read again after this many seconds
const LOGS_FLOOD_KINDS      = 5;             // kinds of lines shown
const LOGS_FLOOD_LINE       = 300;           // bytes of a sample line
const LOGS_FLOOD_PATHS      = 6;             // addresses asked for, shown
const LOGS_FLOOD_LOCAL      = 30;            // % of the lines from this server itself: look for the program behind them
const LOGS_FLOOD_CATCH_RUN  = 60;            // seconds the catch looks
const LOGS_FLOOD_CATCH_STEP = 2;             // … every this many seconds
const LOGS_FLOOD_CATCH_AGAIN = 900;          // a new catch at most this often while the case stays
const LOGS_FLOOD_CATCH_FDS  = 40000;         // open files read per look for a socket's owner at most
const LOGS_FLOOD_CATCH_MS   = 300;           // … or this many milliseconds
const LOGS_FLOOD_WHO        = 4;             // programs kept
/*
 * Known pollers — a second hint beside the catch. paths: what nginx's lines say it asked for (request or referrer, path
 * only); name: the caught program, its container's name or image. todo: a word of what helps, only where it is known
 * for sure (lang flood.todo.<id>).
 * Netdata's go.d nginx collector, with nothing set up, tries http://127.0.0.1|localhost/ basic_status, stub_status,
 * nginx_status and status by itself (its auto-detect) — Unraid's nginx answers each with a redirect to /login, and its
 * login's rate limit (zone authlimit) writes down every refusal. Glances and Telegraf ask a status page only when set up.
 */
const LOGS_FLOOD_STATUS_PATHS = '#^/(?:basic_status|stub_status|nginx_status|status|server_status|server-status)/?$#';
const LOGS_FLOOD_POLLERS = [
    'netdata'     => ['tool' => 'Netdata', 'name' => '/netdata|go\.d\.plugin/i', 'todo' => true],
    'glances'     => ['tool' => 'Glances', 'name' => '/glances/i', 'todo' => false],
    'telegraf'    => ['tool' => 'Telegraf', 'name' => '/telegraf/i', 'todo' => false],
    'uptime_kuma' => ['tool' => 'Uptime Kuma', 'name' => '/uptime[-_ ]?kuma/i', 'todo' => false],
    'homepage'    => ['tool' => 'Homepage', 'name' => '/gethomepage|\bhomepage\b/i', 'todo' => false],
    'homarr'      => ['tool' => 'Homarr', 'name' => '/homarr/i', 'todo' => false],
];
// nginx's error lines: who asked (client), what (request), coming from where (referrer)
const LOGS_FLOOD_CLIENT   = '/\bclient: ([0-9A-Fa-f.:]{2,45}),/';
const LOGS_FLOOD_REQUEST  = '/\brequest: "[A-Z]{3,10} ([^\s"]{1,500})/';
const LOGS_FLOOD_REFERRER = '/\breferrer: "([^"]{1,500})"/';

function logsFloodFile(): string
{
    return $GLOBALS['logsFloodFile'] ?? RUN_DIR . '/logs-flood.json';
}

function logsFloodCatchFile(): string
{
    return $GLOBALS['logsFloodCatchFile'] ?? RUN_DIR . '/logs-flood-catch.json';
}

/** /var/log (tests: a tree of their own) */
function logsFloodRoot(): string
{
    return $GLOBALS['logsFloodRoot'] ?? LOGS_VARLOG;
}

/** /proc (tests: a tree of their own) */
function logsFloodProc(): string
{
    return $GLOBALS['logsFloodProc'] ?? '/proc';
}

function logsFloodMono(): float
{
    return $GLOBALS['logsFloodMonoNow'] ?? hrtime(true) / 1e9;
}

// ===================================================================== the tick

/** Her memory: from RAM at the first call (a restarted agent still knows the growth and whether she told) */
function &logsFloodMem(): array
{
    if (!isset($GLOBALS['logsFlood'])) {
        $f = readJson(logsFloodFile()) ?? [];
        $GLOBALS['logsFlood'] = ['next' => -INF, 'job' => null,
            'samples' => array_values(array_filter(is_array($f['samples'] ?? null) ? $f['samples'] : [],
                fn ($s): bool => is_array($s) && is_int($s['t'] ?? null) && is_array($s['sizes'] ?? null))),
            'case' => is_array($f['case'] ?? null) ? $f['case'] : null, 'catch' => is_array($f['catch'] ?? null) ? $f['catch'] : null,
            'told' => is_int($f['told'] ?? null) ? $f['told'] : null, 'look' => is_array($f['look'] ?? null) ? $f['look'] : null];
    }
    return $GLOBALS['logsFlood'];
}

/** Her tick's part: a clock compare until the next look is due; the catch's end looked for every tick */
function logsFloodTick(): void
{
    $m = &logsFloodMem();
    if ($m['job'] !== null) {
        logsFloodCatchDone();
    }
    $mono = logsFloodMono();
    if ($mono < $m['next']) {
        return;
    }
    $m['next'] = $mono + LOGS_FLOOD_EVERY;
    if (!in_array('logs', staffHired($GLOBALS['agentStaffFile'] ?? null), true)) {
        return;
    }
    logsFloodLook(logsVarlog(), time());
}

/**
 * One look: the sizes, the growth, from 80 % the case (the file, its lines, nginx's own clients, the catch), from 90 %
 * the notification. $v: logsVarlog()'s answer. Returns what she keeps (also in RAM).
 */
function logsFloodLook(array $v, int $now): array
{
    $m = &logsFloodMem();
    if (!$v['ok'] || !$v['own']) {
        // not Unraid's RAM disk of its own: nothing there runs full by itself (as her check)
        $m['samples'] = [];
        $m['case'] = $m['look'] = null;
        $m['told'] = null;
        logsFloodSave();
        return $m;
    }
    $walk = logsFloodSizes(logsFloodRoot());
    $m['samples'][] = ['t' => $now, 'used' => $v['used'], 'sizes' => $walk['sizes']];
    $m['samples'] = array_slice(array_values(array_filter($m['samples'], fn (array $s): bool => $s['t'] <= $now)), -LOGS_FLOOD_SAMPLES);
    $rows = logsFloodGrowth($m['samples']);
    $base = $m['samples'][0];
    $m['look'] = ['t' => $now, 'pct' => $v['pct'], 'used' => $v['used'], 'total' => $v['total'], 'span' => $now - $base['t'],
                  'grew' => $v['used'] - (int) $base['used'], 'rows' => array_slice($rows, 0, LOGS_FLOOD_ROWS), 'cut' => $walk['cut']];

    if ($v['pct'] < LOGS_VARLOG_FULL) {
        if ($m['case'] !== null) {
            logLine("Ms. Protocolli: /var/log is down to {$v['pct']} % again");
        }
        $m['case'] = null;
        $m['told'] = null;                  // resolved: the next time it fills, she tells again
        logsFloodSave();
        return $m;
    }

    // the case: the file growing fastest — none growing (her first look, a steady file): the biggest
    $top = null;
    foreach ($rows as $r) {
        if ($r['grew'] > 0) {
            $top = $r;
            break;
        }
    }
    if ($top === null && $walk['sizes']) {
        $path = (string) array_key_first($walk['sizes']);
        $top = ['path' => $path, 'size' => $walk['sizes'][$path], 'grew' => null, 'rate' => null];
    }
    if ($top === null) {
        $m['case'] = null;
        logsFloodSave();
        return $m;
    }
    $case = $m['case'];
    if ($case === null || $case['path'] !== $top['path'] || $now - (int) ($case['read'] ?? 0) >= LOGS_FLOOD_RESAMPLE || $now < (int) ($case['read'] ?? 0)) {
        if ($case === null || $case['path'] !== $top['path']) {
            logLine("Ms. Protocolli: /var/log is {$v['pct']} % full — {$top['path']} fills it");
        }
        $case = ['path' => $top['path'], 'read' => $now, 'since' => $case !== null && $case['path'] === $top['path'] ? (int) $case['since'] : $now]
            + logsFloodLines(logsFloodTail($top['path'], LOGS_FLOOD_TAIL), logsFloodOwnIps(logsFloodProc()));
    }
    $case['size'] = $top['size'];
    $case['grew'] = $top['grew'];
    $case['rate'] = $top['rate'];
    $m['case'] = $case;

    // nginx's lines of this server itself: who is behind them — a catch of a minute, at most every LOGS_FLOOD_CATCH_AGAIN s
    if (($case['local']['share'] ?? 0) >= LOGS_FLOOD_LOCAL && $m['job'] === null
        && (!$m['catch'] || $now - (int) ($m['catch']['t'] ?? 0) >= LOGS_FLOOD_CATCH_AGAIN || $now < (int) ($m['catch']['t'] ?? 0))) {
        logsFloodCatchStart();
    }
    if ($v['pct'] >= LOGS_FLOOD_NOTIFY && $m['told'] === null && $m['job'] === null) {
        logsFloodTell($v, $now);
    }
    logsFloodSave();
    return $m;
}

/** Into RAM: what the page and the Team Lead's hint read, and her memory for a restarted agent */
function logsFloodSave(): void
{
    $m = &logsFloodMem();
    $file = logsFloodFile();
    @mkdir(dirname($file), 0700, true);
    try {
        writeAtomic($file, jsonEncode(['v' => 1, 'look' => $m['look'], 'case' => $m['case'], 'catch' => $m['catch'], 'told' => $m['told'],
                                       'samples' => $m['samples']]), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Ms. Protocolli: her /var/log watch could not be kept — ' . $e->getMessage());
    }
}

// ===================================================================== sizes and growth

/**
 * Every regular file under /var/log (one filesystem; links, folders and the like stay out) => its size, the biggest
 * first, at most LOGS_FLOOD_KEEP of them; cut: the walk stopped at LOGS_FLOOD_WALK.
 *
 * @return array{sizes: array<string, int>, cut: bool}
 */
function logsFloodSizes(string $root): array
{
    $top = @lstat($root);
    $sizes = [];
    $seen = 0;
    $stack = $top && ($top['mode'] & 0170000) === 0040000 ? [$root] : [];
    while ($stack && $seen < LOGS_FLOOD_WALK) {
        $dir = array_pop($stack);
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (++$seen > LOGS_FLOOD_WALK) {
                break;
            }
            $path = "$dir/$name";
            $st = @lstat($path);
            if (!$st || $st['dev'] !== $top['dev']) {
                continue;
            }
            $type = $st['mode'] & 0170000;
            if ($type === 0040000) {
                $stack[] = $path;
            } elseif ($type === 0100000) {
                $sizes[$path] = (int) $st['size'];
            }
        }
    }
    uksort($sizes, fn (string $a, string $b): int => [$sizes[$b], $a] <=> [$sizes[$a], $b]);
    return ['sizes' => array_slice($sizes, 0, LOGS_FLOOD_KEEP, true), 'cut' => $seen > LOGS_FLOOD_WALK];
}

/**
 * Each file's growth from the oldest look kept to the newest, the fastest first: path, size, grew (bytes; a file
 * that got shorter was emptied or rotated — what it has now counts), rate (bytes a minute; null with one look only)
 *
 * @return list<array{path: string, size: int, grew: ?int, rate: ?int}>
 */
function logsFloodGrowth(array $samples): array
{
    $now = end($samples);
    $base = reset($samples);
    if (!$now) {
        return [];
    }
    $span = $now['t'] - $base['t'];
    $out = [];
    foreach ($now['sizes'] as $path => $size) {
        $path = (string) $path;
        $was = $base['sizes'][$path] ?? null;
        $grew = $span <= 0 ? null : ($was === null || $was > $size ? $size : $size - (int) $was);
        $out[] = ['path' => $path, 'size' => (int) $size, 'grew' => $grew, 'rate' => $grew === null ? null : (int) round($grew * 60 / $span)];
    }
    usort($out, fn (array $a, array $b): int => [$b['grew'] ?? -1, $b['size'], $a['path']] <=> [$a['grew'] ?? -1, $a['size'], $b['path']]);
    return $out;
}

// ===================================================================== the file that fills it: its lines

/** The newest $budget bytes of a file as lines (the first, begun in the middle, left out); never through a link */
function logsFloodTail(string $path, int $budget): array
{
    $st = @lstat($path);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || !$st['size']) {
        return [];
    }
    $h = @fopen($path, 'r');
    if (!$h) {
        return [];
    }
    $size = (int) $st['size'];
    $want = min($size, $budget);
    fseek($h, $size - $want);
    $text = (string) fread($h, $want);
    fclose($h);
    if (substr_count($text, "\0") * 20 > strlen($text)) {
        return ['binary' => true];              // wtmp, lastlog …: no lines to tell
    }
    $lines = explode("\n", rtrim($text, "\n"));
    if ($want < $size) {
        array_shift($lines);
    }
    return array_slice($lines, -LOGS_FLOOD_LINES);
}

/**
 * What the lines say: the most frequent kinds with their share and the newest line of each (scrubbed, cut); nginx's lines
 * of a client on this server itself — their share, the client, the addresses asked for, a known poller by them.
 */
function logsFloodLines(array $lines, array $own): array
{
    if (($lines['binary'] ?? false) === true) {
        return ['lines' => 0, 'binary' => true, 'kinds' => [], 'local' => null];
    }
    $kinds = [];
    $local = 0;
    $clients = [];
    $paths = [];
    foreach ($lines as $line) {
        $line = logsClean((string) $line);
        if (trim($line) === '') {
            continue;
        }
        $k = logsNormalize($line);
        $kinds[$k] ??= ['n' => 0, 'line' => ''];
        $kinds[$k]['n']++;
        $kinds[$k]['line'] = $line;
        if (preg_match(LOGS_FLOOD_CLIENT, $line, $g) && in_array(logsFloodIp($g[1]), $own, true)) {
            $local++;
            $clients[$g[1]] = ($clients[$g[1]] ?? 0) + 1;
            $urls = [preg_match(LOGS_FLOOD_REQUEST, $line, $q) ? $q[1] : '', preg_match(LOGS_FLOOD_REFERRER, $line, $f) ? $f[1] : ''];
            foreach ($urls as $url) {
                $p = logsFloodPath($url);
                if ($p !== null) {
                    $paths[$p] = ($paths[$p] ?? 0) + 1;
                }
            }
        }
    }
    $n = array_sum(array_column($kinds, 'n'));
    uasort($kinds, fn (array $a, array $b): int => $b['n'] <=> $a['n']);
    $out = [];
    foreach (array_slice($kinds, 0, LOGS_FLOOD_KINDS) as $k) {
        $out[] = ['n' => $k['n'], 'share' => (int) round($k['n'] * 100 / max(1, $n)), 'line' => logsFloodScrub($k['line'])];
    }
    $nginx = null;
    if ($local) {
        arsort($clients);
        arsort($paths);
        $nginx = ['n' => $local, 'share' => (int) round($local * 100 / max(1, $n)), 'client' => (string) array_key_first($clients),
                  'paths' => array_slice(array_map('strval', array_keys($paths)), 0, LOGS_FLOOD_PATHS),
                  'poller' => logsFloodPollerByPaths(array_map('strval', array_keys($paths)))];
    }
    return ['lines' => $n, 'binary' => false, 'kinds' => $out, 'local' => $nginx];
}

/** An address as she compares it: IPv4 as is, IPv6 lower-case, ::ffff:a.b.c.d as a.b.c.d */
function logsFloodIp(string $ip): string
{
    $ip = strtolower($ip);
    return str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? substr($ip, 7) : $ip;
}

/** A request's or referrer's path — scheme, host and query left out; only plain characters, else null */
function logsFloodPath(string $url): ?string
{
    if ($url === '' || $url === '-') {
        return null;
    }
    $p = preg_match('#^[a-z][a-z0-9+.-]{1,15}://[^/\s]*(/[^?\#\s]*)?#i', $url, $m) ? ($m[1] ?? '/') : (string) strtok($url, '?#');
    $p = $p === '' ? '/' : $p;
    return preg_match('#^/[A-Za-z0-9._~/%-]{0,99}$#D', $p) ? $p : null;
}

/** A known poller by the addresses asked for: Netdata when it tries two or more of its auto-detect's status pages */
function logsFloodPollerByPaths(array $paths): ?string
{
    $status = array_values(array_filter($paths, fn (string $p): bool => preg_match(LOGS_FLOOD_STATUS_PATHS, $p) === 1));
    return count($status) >= 2 ? 'netdata' : ($status ? 'status' : null);
}

/** A known poller by a caught program: its container's name or image, its command, its process name */
function logsFloodPollerByName(array $who): ?string
{
    $text = implode(' ', [(string) ($who['name'] ?? ''), (string) ($who['image'] ?? ''), (string) ($who['prog'] ?? ''), (string) ($who['cmd'] ?? '')]);
    foreach (LOGS_FLOOD_POLLERS as $id => $p) {
        if (preg_match($p['name'], $text)) {
            return $id;
        }
    }
    return null;
}

/** A line for her page: what looks like a key, a token or a password left out, cut to LOGS_FLOOD_LINE bytes */
function logsFloodScrub(string $line): string
{
    $line = preg_replace([
        '/((?:[?&;\s]|^)(?:csrf_token|token|access_token|api[_-]?key|apikey|key|pass(?:word|wd)?|secret|auth|session|sid)=)[^&\s"\']+/i',
        '/\b(bearer|basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
    ], ['$1…', '$1 …'], $line) ?? '';
    return mb_strcut($line, 0, LOGS_FLOOD_LINE, 'UTF-8');
}

/**
 * This server's own addresses: 127.0.0.1, ::1 and what the kernel calls LOCAL in /proc/net/fib_trie (IPv4), every address
 * of /proc/net/if_inet6 (IPv6) — RAM, no command
 *
 * @return list<string>
 */
function logsFloodOwnIps(string $proc): array
{
    $own = ['127.0.0.1', '::1'];
    $last = null;
    foreach (@file("$proc/net/fib_trie", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^[\s|+-]*-- (\d{1,3}(?:\.\d{1,3}){3})\s*$/', $line, $m)) {
            $last = $m[1];
        } elseif ($last !== null && preg_match('#^\s*/32 host LOCAL\b#', $line)) {
            $own[] = $last;
        }
    }
    foreach (@file("$proc/net/if_inet6", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([0-9a-f]{32})\s/', $line, $m)) {
            $ip = @inet_ntop((string) hex2bin($m[1]));
            if (is_string($ip)) {
                $own[] = $ip;
            }
        }
    }
    return array_values(array_unique($own));
}

// ===================================================================== the catch: who is behind nginx's own clients

/** The web UI's ports (Unraid's ident.cfg: PORT, PORTSSL — 80 and 443 when not set) */
function logsFloodPorts(?string $ident = null): array
{
    $cfg = readCfg($ident ?? ($GLOBALS['logsFloodIdent'] ?? '/boot/config/ident.cfg'));
    $ports = [];
    foreach (['PORT' => 80, 'PORTSSL' => 443] as $key => $default) {
        $p = (string) ($cfg[$key] ?? '');
        $ports[] = preg_match('/^\d{1,5}$/D', $p) && (int) $p > 0 && (int) $p < 65536 ? (int) $p : $default;
    }
    return array_values(array_unique($ports));
}

/**
 * /proc/net/tcp and tcp6: sockets of a connection from this server to its own web UI — the client's side (its far end
 * one of $own on one of $ports, its own port none of them), not listening, with a socket inode (TIME_WAIT has none)
 *
 * @return list<int> inodes
 */
function logsFloodSockets(string $proc, array $ports, array $own): array
{
    $out = [];
    foreach (['tcp', 'tcp6'] as $f) {
        foreach (@file("$proc/net/$f", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $c = preg_split('/\s+/', trim($line));
            if (count($c) < 10 || !preg_match('/^([0-9A-F]{8}|[0-9A-F]{32}):([0-9A-F]{4})$/D', $c[1], $l)
                || !preg_match('/^([0-9A-F]{8}|[0-9A-F]{32}):([0-9A-F]{4})$/D', $c[2], $r) || $c[3] === '0A' || !ctype_digit($c[9]) || $c[9] === '0') {
                continue;
            }
            if (in_array(hexdec($r[2]), $ports, true) && !in_array(hexdec($l[2]), $ports, true) && in_array(logsFloodHexIp($r[1]), $own, true)) {
                $out[] = (int) $c[9];
            }
        }
    }
    return array_values(array_unique($out));
}

/** An address as /proc/net/tcp* writes it (each 32-bit word in the host's order — little-endian here) */
function logsFloodHexIp(string $hex): string
{
    $bin = '';
    foreach (str_split($hex, 8) as $word) {
        $bin .= strrev((string) hex2bin($word));
    }
    return logsFloodIp((string) @inet_ntop($bin));
}

/**
 * Who holds these sockets: /proc/<pid>/fd links «socket:[inode]» — inode => pid, within LOGS_FLOOD_CATCH_FDS links and
 * LOGS_FLOOD_CATCH_MS (she says so: cut)
 *
 * @return array{owners: array<int, int>, cut: bool}
 */
function logsFloodOwners(string $proc, array $inodes): array
{
    $want = array_fill_keys($inodes, true);
    $owners = [];
    $fds = 0;
    $t0 = hrtime(true);
    $self = getmypid();
    foreach (@scandir($proc) ?: [] as $pid) {
        if (!ctype_digit($pid) || (int) $pid === $self) {
            continue;
        }
        foreach (@scandir("$proc/$pid/fd") ?: [] as $fd) {
            if (!ctype_digit($fd)) {
                continue;
            }
            if (++$fds > LOGS_FLOOD_CATCH_FDS || hrtime(true) - $t0 > LOGS_FLOOD_CATCH_MS * 1e6) {
                return ['owners' => $owners, 'cut' => true];
            }
            $link = (string) @readlink("$proc/$pid/fd/$fd");
            if (preg_match('/^socket:\[(\d+)\]$/D', $link, $m) && isset($want[(int) $m[1]])) {
                $owners[(int) $m[1]] = (int) $pid;
                unset($want[(int) $m[1]]);
                if (!$want) {
                    return ['owners' => $owners, 'cut' => false];
                }
            }
        }
    }
    return ['owners' => $owners, 'cut' => false];
}

/** What a process is: its name, its command (cut), its container's id (cgroup) — null when it went meanwhile */
function logsFloodWho(string $proc, int $pid): ?array
{
    $stat = (string) @file_get_contents("$proc/$pid/stat", false, null, 0, 1024);
    if (!preg_match('/^\d+ \((.{0,64})\) /s', $stat, $s)) {
        return null;
    }
    $cmd = trim(str_replace("\0", ' ', (string) @file_get_contents("$proc/$pid/cmdline", false, null, 0, 4096)));
    $who = ['kind' => 'program', 'prog' => mb_strcut($s[1], 0, 64, 'UTF-8'), 'cmd' => logsFloodScrub(mb_strcut($cmd, 0, 200, 'UTF-8'))];
    if (preg_match('#/docker[/-]([0-9a-f]{12,64})(?:\.scope)?\b#', (string) @file_get_contents("$proc/$pid/cgroup", false, null, 0, 8192), $c)) {
        $who = ['kind' => 'container', 'id' => $c[1]] + $who;
    }
    return $who;
}

/** The catch, unless one runs */
function logsFloodCatchStart(): void
{
    $m = &logsFloodMem();
    if ($m['job'] !== null) {
        return;
    }
    if (!empty($GLOBALS['logsFloodNoJob'])) {          // tests catch by calling logsFloodCatchRun()
        $m['job'] = ['process' => null, 'since' => time()];
        return;
    }
    @unlink(logsFloodCatchFile());
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'logs-flood-catch'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (is_resource($process)) {
        $m['job'] = ['process' => $process, 'since' => time()];
        logLine('Ms. Protocolli: looking for the program behind nginx\'s lines of this server itself (a minute)');
    }
}

/** Every tick while the catch runs: done? Its answer (RAM) into her memory; the notification waits for it */
function logsFloodCatchDone(): void
{
    $m = &logsFloodMem();
    $p = $m['job']['process'];
    if ($p !== null) {
        $status = proc_get_status($p);
        if ($status['running']) {
            if (time() - $m['job']['since'] > LOGS_FLOOD_CATCH_RUN + 30) {
                proc_terminate($p);
            }
            return;
        }
        proc_close($p);
    } elseif (!is_file(logsFloodCatchFile())) {
        return;                                         // tests: until their logsFloodCatchRun() wrote its answer
    }
    $m['job'] = null;
    $c = readJson(logsFloodCatchFile());
    @unlink(logsFloodCatchFile());
    $m['catch'] = is_array($c) && is_int($c['t'] ?? null) ? $c : ['t' => time(), 'looks' => 0, 'seen' => 0, 'who' => [], 'failed' => true];
    $who = $m['catch']['who'][0] ?? null;
    logLine('Ms. Protocolli: the program behind nginx\'s lines of this server itself — '
        . (is_array($who) ? ($who['kind'] === 'container' ? 'a container' : 'a program') . ' caught' : 'not caught'));
    logsFloodSave();
}

/**
 * php agent.php job logs-flood-catch: for LOGS_FLOOD_CATCH_RUN s, every LOGS_FLOOD_CATCH_STEP s, the connections from
 * here to the web UI and who holds them; then docker's names for the containers caught; the answer into RAM.
 * $o (tests): looks (how many), docker (id => [name, image]).
 */
function logsFloodCatchRun(array $o = []): int
{
    $proc = logsFloodProc();
    $ports = logsFloodPorts();
    $own = logsFloodOwnIps($proc);
    $looks = (int) ($o['looks'] ?? intdiv(LOGS_FLOOD_CATCH_RUN, LOGS_FLOOD_CATCH_STEP));
    $seen = [];
    $who = [];
    $cut = false;
    for ($i = 0; $i < $looks; $i++) {
        if ($i > 0) {
            usleep(LOGS_FLOOD_CATCH_STEP * 1000000);
        }
        $new = array_values(array_filter(logsFloodSockets($proc, $ports, $own), fn (int $ino): bool => !isset($seen[$ino])));
        if (!$new) {
            continue;
        }
        foreach ($new as $ino) {
            $seen[$ino] = true;
        }
        $found = logsFloodOwners($proc, $new);
        $cut = $cut || $found['cut'];
        foreach ($found['owners'] as $pid) {
            $w = logsFloodWho($proc, $pid);
            if ($w === null) {
                continue;
            }
            $key = $w['kind'] === 'container' ? 'c:' . $w['id'] : 'p:' . $w['prog'] . ':' . $w['cmd'];
            $who[$key] ??= $w + ['n' => 0];
            $who[$key]['n']++;
        }
    }
    if (array_filter($who, fn (array $w): bool => $w['kind'] === 'container')) {
        $docker = $o['docker'] ?? logsFloodDocker();
        foreach ($who as &$w) {
            if ($w['kind'] === 'container') {
                $d = $docker[$w['id']] ?? $docker[substr($w['id'], 0, 12)] ?? null;
                if ($d !== null) {
                    $w['name'] = $d[0];
                    $w['image'] = $d[1];
                }
            }
        }
        unset($w);
    }
    usort($who, fn (array $a, array $b): int => $b['n'] <=> $a['n']);
    $who = array_map(fn (array $w): array => $w + ['poller' => logsFloodPollerByName($w)], array_slice($who, 0, LOGS_FLOOD_WHO));
    $file = logsFloodCatchFile();
    @mkdir(dirname($file), 0700, true);
    writeAtomic($file, jsonEncode(['t' => time(), 'looks' => $looks, 'seen' => count($seen), 'who' => $who, 'cut' => $cut]), 0600, 0, 0);
    return 0;
}

/** Docker's containers: id (and its first 12) => [name, image] — nothing when Docker is off */
function logsFloodDocker(): array
{
    $out = [];
    if (bin('docker') === null || !file_exists('/var/run/docker.sock')) {
        return $out;
    }
    [$exit, $text] = run(['docker', 'ps', '-a', '--no-trunc', '--format', '{{.ID}}\t{{.Names}}\t{{.Image}}'], 20);
    foreach ($exit === 0 ? explode("\n", trim((string) $text)) : [] as $line) {
        $f = explode("\t", $line);
        if (count($f) === 3 && preg_match('/^[0-9a-f]{64}$/D', $f[0])) {
            $out[$f[0]] = $out[substr($f[0], 0, 12)] = [mb_strcut($f[1], 0, 100, 'UTF-8'), mb_strcut($f[2], 0, 200, 'UTF-8')];
        }
    }
    return $out;
}

// ===================================================================== what she says: the notification, the page, the Team Lead

/** The poller she names: the caught program's, else the one the addresses point to */
function logsFloodPoller(?array $case, ?array $catch): ?string
{
    $who = $catch['who'][0] ?? null;
    return (is_array($who) ? ($who['poller'] ?? null) : null) ?? ($case['local']['poller'] ?? null);
}

/** At 90 %: one notification (warning) — which file, with what, who is behind it, what helps; again only after it was below 80 % */
function logsFloodTell(array $v, int $now): void
{
    $m = &logsFloodMem();
    $case = $m['case'];
    $lang = officeNotifyLang();
    $tx = fn (string $key, array $p = []): string => officeNotifyText('logs', $key, $p, $lang);
    $parts = [$case['rate'] !== null && $case['rate'] > 0
        ? $tx('notify.flood_file', ['file' => $case['path'], 'rate' => logsHuman((int) $case['rate'])])
        : $tx('notify.flood_big', ['file' => $case['path'], 'size' => logsHuman((int) $case['size'])])];
    if ($case['kinds']) {
        $parts[] = $tx('notify.flood_kind', ['share' => $case['kinds'][0]['share'], 'line' => mb_strcut($case['kinds'][0]['line'], 0, 160, 'UTF-8')]);
    }
    $local = $case['local'] ?? null;
    if ($local) {
        $who = $m['catch']['who'][0] ?? null;
        $parts[] = !is_array($who) ? $tx('notify.flood_who_none', ['share' => $local['share']])
            : ($who['kind'] === 'container' ? $tx('notify.flood_who_container', ['share' => $local['share'], 'name' => $who['name'] ?? substr($who['id'], 0, 12)])
                : $tx('notify.flood_who_program', ['share' => $local['share'], 'prog' => $who['prog']]));
    }
    $poller = logsFloodPoller($case, $m['catch']);
    if ($poller !== null && (LOGS_FLOOD_POLLERS[$poller]['todo'] ?? false)) {
        $parts[] = $tx("flood.todo.$poller");
    }
    $parts[] = $tx('notify.flood_see');
    $sent = officeNotify($tx('notify.flood_subject', ['pct' => $v['pct']]), implode(' ', $parts), 'warning', '',
        officeNotifyLink('#/logs/varlog'), 'logs', 'varlog_full');
    $m['told'] = $now;                      // once: also when Unraid's notify wasn't there (nothing to send through)
    if ($sent) {
        logLine("Ms. Protocolli: told Unraid's notifications — /var/log is {$v['pct']} % full");
    }
}

/** For her page (state part «flood»): the last look, the case, the catch, when she told — never her memory's sizes */
function logsFloodForPage(): array
{
    $m = isset($GLOBALS['logsFlood']) ? $GLOBALS['logsFlood'] : (readJson(logsFloodFile()) ?? []);
    $job = $m['job'] ?? null;
    return ['look' => $m['look'] ?? null, 'case' => $m['case'] ?? null, 'catch' => $m['catch'] ?? null, 'told' => $m['told'] ?? null,
            'catching' => $job !== null, 'every' => LOGS_FLOOD_EVERY, 'full' => LOGS_VARLOG_FULL, 'notify' => LOGS_FLOOD_NOTIFY,
            'pollers' => array_map(fn (array $p): array => ['tool' => $p['tool'], 'todo' => $p['todo']], LOGS_FLOOD_POLLERS)];
}

/** For the Team Lead: from 80 %, a hint beside her «/var/log has room» — which file fills it and with what */
function logsFloodChecks(): array
{
    $f = readJson(logsFloodFile()) ?? [];
    $case = is_array($f['case'] ?? null) ? $f['case'] : null;
    if ($case === null || !is_string($case['path'] ?? null)) {
        return [];
    }
    $top = $case['kinds'][0] ?? null;
    return [finding('varlog_flood', 'hint', false, [
        'file' => $case['path'],
        'line' => is_array($top) ? mb_strcut((string) $top['line'], 0, 160, 'UTF-8') : '–',
        'share' => is_array($top) ? (int) $top['share'] : 0,
    ], '#/logs/varlog')];
}
