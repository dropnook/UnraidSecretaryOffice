<?php
declare(strict_types=1);

/*
 * The network as the night watchman sees it (stage 1 of the router SOC: UniFi, briefs/brief-router-soc-stage1.md; MikroTik
 * RouterOS 7 lines since #2 — parsed and typed, its own kinds still to come: see «MikroTik RouterOS» below).
 * Read only, functions only — like partnerlook.php: the night watchman calls it from his round, the Team Lead for his
 * checks, the Consultant for his look at Unraid's syslog server, Ms. Protocolli for her sources, Mr. Backupsy's setup
 * only through the engine (setup.sh reads rsyslog.cfg itself).
 *
 * The office never listens: the router sends to Unraid's own syslog server (⟦Settings⟧ → ⟦Network Services⟧ →
 * ⟦Syslog Server⟧, saved in /boot/config/rsyslog.cfg), which writes one file per sender into a share
 * (`syslog-<ip>.log`, rotated by logrotate to `.1` … `.4`). He reads those files by offset and inode like his own
 * syslog — only while the array is started (rsyslog's file action is on only then), only when the share's disks are
 * awake, never during the night shift — and never writes into that folder. Never a router secret, never an API, never a
 * write to a router.
 *
 * The files are written by whoever sends to UDP 514 and lie in a share (nobody:users, 0644 at creation, 0666 after a
 * rotation): untrusted input. Lines are capped, parsed by fixed rules, never executed, never linked raw; a line that
 * doesn't parse is counted «other», never an entry; the vendor comes from the line, never from the file name; his own
 * `uso-watchman` lines (only there through a loop) are never counted; the files of this server's own addresses are
 * never read (⟦Remote syslog server⟧ pointed at itself).
 *
 * What is a witness's statement about THE SERVER becomes an entry (group `net`): a new sender, a new device on the LAN,
 * someone claiming the server's name or address, a router admin login or VPN login not seen before, a firewall/NAT/port
 * forward change, other config changes (a line a day), an IPS detection against or from the server, the gateway
 * blocking something FROM the server, the router's log going silent; the router's clock as a posture tip. Everything
 * else is a count in «What I keep an eye on» — never another client's address (the privacy rule: other clients'
 * addresses and names only in net_new_device and net_spoof; the «whole LAN» switch adds per-device counts, nothing more).
 *
 * Files: baseline.json `net` (what is normal: senders, devices, the server's own MACs, admins, VPN users, blocked keys,
 * inbound signatures — learned by the first round that sees a router), data/watchman/net.json (positions, per sender
 * counts and times, detections and dropped events per day, blocked counts, devices' last sight, the LAN counts) —
 * watchnetRound() reads the files outside his book's lock, watchnetCompare() judges inside it.
 */

const WATCHNET_CFG           = '/boot/config/rsyslog.cfg';
const WATCHNET_FILE          = '/^syslog-([A-Za-z0-9:\[][A-Za-z0-9._:%\[\]-]{0,127})\.log$/D';
const WATCHNET_SENDERS_MAX   = 64;                   // files (senders) looked at
const WATCHNET_LINE_MAX      = 8192;                 // a longer line is «other»
const WATCHNET_EVENTS_MAX    = 20000;                // typed events kept from one round (beyond: counted only)
const WATCHNET_DEVICES_MAX   = 500;
const WATCHNET_ADMINS_MAX    = 50;
const WATCHNET_VPN_MAX       = 50;
const WATCHNET_BLOCKED_MAX   = 200;
const WATCHNET_SIGS_MAX      = 200;
const WATCHNET_LAN_MAX       = 500;
const WATCHNET_DAYS          = 14;                   // per-day counts kept this long
const WATCHNET_GAP_DAYS      = 7;                    // the usual gap between a sender's lines: the longest of these days
const WATCHNET_SILENT_FACTOR = 3;                    // silent: no line for this many times the usual gap …
const WATCHNET_SILENT_FLOOR  = 6 * 3600;             // … and at least this long
const WATCHNET_CLOCK         = 300;                  // the router's clock off by more: a posture tip
const WATCHNET_CHAIN         = 600;                  // a new device and a login from its address this close: a chain
const WATCHNET_EVIDENCE      = 300;                  // characters of an entry's evidence line
const WATCHNET_ROTATION_MAX  = 2 * 1024 ** 3;        // size × files beyond this: the Team Lead's syslog_no_rotation
const WATCHNET_TAG           = '/\[([A-Z][A-Z0-9_]{0,47})-(?:(\d{1,10})-([ADR])|([ADR])-(\d{1,10}))\]/';
const WATCHNET_OWN           = '/\suso-watchman(?:\[\d+\])?:/';      // his own SIEM lines (WATCH_SYSLOG_OWN)
const WATCHNET_FIREWALL      = '/\b(?:firewall|nat|port[ -]?forward(?:ing|s)?|traffic rules?|polic(?:y|ies)|zones?)\b/i';
const WATCHNET_AREAS         = ['port_forward' => '/port[ -]?forward/i', 'nat' => '/\bnat\b/i', 'policy' => '/polic(?:y|ies)|traffic rules?|zones?/i',
                                'firewall' => '/firewall/i'];
// containers that listen on UDP 514 themselves (the Team Lead's syslog_port_taken; the neighbours note, 3): image => name
const WATCHNET_PORT_HOLDERS  = ['/unifi-insights-hub|firesight/i' => 'FireSight', '/grafana\/alloy|(?:^|\/)alloy(?::|$)/i' => 'Alloy',
                                '/syslog-ng/i' => 'syslog-ng', '/promtail/i' => 'Promtail', '/crowdsec/i' => 'CrowdSec',
                                '/rsyslog/i' => 'rsyslog', '/graylog/i' => 'Graylog', '/logstash/i' => 'Logstash'];

// ===================================================================== Unraid's syslog server (rsyslog.cfg)

/**
 * Unraid's syslog server as /boot/config/rsyslog.cfg says (SyslogSettings.page writes it; webGui/scripts/rsyslog_config
 * applies it): on (⟦Local syslog server⟧ «Enabled» and a folder), the folder and its share, how the files are named
 * (ip / host / dns), rotation, ⟦Remote syslog server⟧. A folder that isn't a clean absolute path counts as none.
 *
 * @return array{there: bool, local: bool, on: bool, folder: ?string, share: ?string, ident: ?string, protocol: string, port: int,
 *               rotation: bool, size: int, files: int, remote: ?string, remote_port: int}
 */
function watchnetConfig(string $file = WATCHNET_CFG): array
{
    $there = is_file($file);
    $c = $there ? readCfg($file) : [];
    $folder = rtrim((string) ($c['server_folder'] ?? ''), '/');
    $folder = watchnetFolderOk($folder) ? $folder : null;
    $ident = match ((string) ($c['server_filename'] ?? '')) {
        'syslog-%FROMHOST-IP%.log' => 'ip',
        'syslog-%HOSTNAME%.log'    => 'host',
        'syslog-%FROMHOST%.log'    => 'dns',
        default                    => null,
    };
    $remote = trim((string) ($c['remote_server'] ?? ''));
    $local = ($c['local_server'] ?? '') === '1';
    return [
        'there'       => $there,
        'local'       => $local,
        'on'          => $local && $folder !== null && $ident !== null,
        'folder'      => $folder,
        'share'       => $folder !== null && preg_match('#^/mnt/user/([^/]+)$#D', $folder, $m) ? $m[1] : null,
        'ident'       => $ident,
        'protocol'    => in_array($c['server_protocol'] ?? '', ['udp', 'tcp', 'both'], true) ? (string) $c['server_protocol'] : 'udp',
        'port'        => max(1, min(65535, (int) ($c['server_port'] ?? 514) ?: 514)),
        'rotation'    => ($c['log_rotation'] ?? '') === '1',
        'size'        => watchnetBytes((string) ($c['log_size'] ?? '')),
        'files'       => max(0, min(99, (int) ($c['log_files'] ?? 0))),
        'remote'      => $remote !== '' && preg_match('/^[A-Za-z0-9._:\[\]-]{1,253}$/D', $remote) ? $remote : null,
        'remote_port' => max(0, min(65535, (int) ($c['remote_port'] ?? 0))),
    ];
}

/** A folder rsyslog may write into: absolute, no `.`/`..` parts, no control characters, not too long */
function watchnetFolderOk(string $f): bool
{
    return $f !== '' && strlen($f) <= 255 && $f[0] === '/' && !preg_match('#[\x00-\x1F\x7F]|(?:^|/)\.\.?(?:/|$)#', $f) && !str_contains($f, '//');
}

/** "50M" → bytes (the page's sizes: 1M … 500M; a K or G by hand) */
function watchnetBytes(string $s): int
{
    if (!preg_match('/^(\d{1,6})\s*([KMG]?)B?$/iD', trim($s), $m)) {
        return 0;
    }
    return (int) $m[1] * match (strtoupper($m[2])) { 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, default => 1 };
}

/**
 * The disks and pools behind the syslog folder (shares.ini, disks.ini — RAM, nothing asked): /mnt/user/<share> by the
 * share's storage (an exclusive share: its pool; useCache only: the pool; no: the array's data disks — its include list
 * or all; yes/prefer: the pool and the secondary pool or the array), /mnt/<disk or pool>/… itself, anything else
 * (RAM, the flash) none. `known` false: a share emhttp doesn't list — not looked at.
 *
 * @return array{share: ?string, bases: list<string>, array: bool, known: bool}
 */
function watchnetBases(string $folder, array $shares, array $disks): array
{
    if (preg_match('#^/mnt/user0?/([^/]+)(?:/|$)#', $folder, $m)) {
        $s = $shares[$m[1]] ?? null;
        if (!is_array($s)) {
            return ['share' => $m[1], 'bases' => [], 'array' => false, 'known' => false];
        }
        $data = [];
        foreach ($disks as $name => $d) {
            if (($d['type'] ?? '') === 'Data' && preg_match('/^disk\d+$/D', (string) ($d['name'] ?? $name))) {
                $data[] = (string) ($d['name'] ?? $name);
            }
        }
        $include = array_values(array_filter(array_map('trim', explode(',', (string) ($s['include'] ?? '')))));
        $exclude = array_values(array_filter(array_map('trim', explode(',', (string) ($s['exclude'] ?? '')))));
        $arrayDisks = array_values(array_diff($include ? array_intersect($data, $include) : $data, $exclude));
        $pool = (string) ($s['cachePool'] ?? '');
        $pool2 = (string) ($s['cachePool2'] ?? '');
        $use = (string) ($s['useCache'] ?? 'no');
        if (($s['exclusive'] ?? '') === 'yes' && $pool !== '') {
            return ['share' => $m[1], 'bases' => [$pool], 'array' => false, 'known' => true];
        }
        $bases = match ($use) {
            'only'         => [$pool],
            'yes', 'prefer' => array_merge([$pool], $pool2 !== '' ? [$pool2] : $arrayDisks),
            default        => $arrayDisks,
        };
        $onArray = $use === 'no' || (in_array($use, ['yes', 'prefer'], true) && $pool2 === '');
        return ['share' => $m[1], 'bases' => array_values(array_unique(array_filter($bases))), 'array' => $onArray, 'known' => true];
    }
    if (preg_match('#^/mnt/([^/]+)(?:/|$)#', $folder, $m) && !in_array($m[1], ['addons', 'remotes', 'disks', 'rootshare'], true)) {
        return ['share' => null, 'bases' => [$m[1]], 'array' => (bool) preg_match('/^disk\d+$/D', $m[1]), 'known' => true];
    }
    return ['share' => null, 'bases' => [], 'array' => false, 'known' => true];
}

/**
 * Disk name => asleep now, from disks.ini as read: spun down AND rotating (an SSD/NVMe never sleeps for the office,
 * whatever its spundown says) — mounts.php's diskAsleep() where it exists, the same rule otherwise
 */
function watchnetSleeping(array $disks): array
{
    $out = [];
    foreach ($disks as $section => $d) {
        $d = (array) $d;
        $out[(string) ($d['name'] ?? $section)] = function_exists('diskAsleep') ? diskAsleep($d)
            : ($d['spundown'] ?? '0') === '1' && ($d['rotational'] ?? '1') !== '0';
    }
    return $out;
}

/**
 * The disks and pools that never spin down: every disk of it an SSD (rotational 0) or with ⟦Spin down delay⟧ «Never»
 * (spindownDelay 0; -1 = the default, var.ini's spindownDelay). A pool is the slot with a file system (fsType) and the
 * slots named like it with a number (master, master2 — as baseAsleep() groups them); an array disk is itself. Name => true.
 */
function watchnetAlwaysOn(array $disks, int $defaultDelay): array
{
    $byName = [];
    foreach ($disks as $section => $d) {
        $byName[(string) ($d['name'] ?? $section)] = $d;
    }
    $never = function (array $d) use ($defaultDelay): bool {
        $delay = (string) ($d['spindownDelay'] ?? '-1');
        return ($d['rotational'] ?? '1') === '0' || $delay === '0' || ($delay === '-1' && $defaultDelay === 0);
    };
    $out = [];
    foreach ($byName as $name => $d) {
        if ((string) ($d['fsType'] ?? '') === '' || in_array($d['type'] ?? '', ['Parity', 'Boot', 'Flash'], true) || $name === 'flash') {
            continue;
        }
        $members = preg_match('/^disk\d+$/D', $name) ? [$d]
            : array_values(array_filter($byName, fn ($x, $n) => preg_match('/^' . preg_quote($name, '/') . '\d*$/D', (string) $n) === 1
                && ($x['device'] ?? 'x') !== '', ARRAY_FILTER_USE_BOTH));
        if ($members && !array_filter($members, fn ($x) => !$never($x))) {
            $out[$name] = true;
        }
    }
    return $out;
}

// ===================================================================== this server: its addresses, MACs, name

/**
 * What is this server on the LAN: its addresses (every interface's, without loopback and link-local; the containers'
 * own from docker inspect — a br0/macvlan container has an address of its own), its MACs (/sys/class/net, the
 * containers'), its name (ident.cfg NAME). `own` — what a file name may say for this server (the loop: also
 * 127.0.0.1, ::1, localhost and the name): never read.
 *
 * @return array{ips: array<string, true>, macs: array<string, true>, name: string, own: array<string, true>}
 */
function watchnetServer(array $paths, ?array $containers = null): array
{
    $ips = $own = $macs = [];
    $ifaces = $GLOBALS['watchnetIfaces'] ?? (function_exists('net_get_interfaces') ? (@net_get_interfaces() ?: []) : []);
    foreach ((array) $ifaces as $if) {
        foreach ((array) ($if['unicast'] ?? []) as $u) {
            $ip = is_string($u['address'] ?? null) ? watchnetIp($u['address']) : null;
            if ($ip === null) {
                continue;
            }
            $own[$ip] = true;
            if (!watchnetLoopback($ip) && !str_starts_with($ip, 'fe80:')) {
                $ips[$ip] = true;
            }
        }
    }
    $net = (string) ($paths['net_class'] ?? '/sys/class/net');
    foreach (@scandir($net) ?: [] as $if) {
        if ($if === '.' || $if === '..' || $if === 'lo') {
            continue;
        }
        $mac = watchnetMac((string) @file_get_contents("$net/$if/address", false, null, 0, 64));
        if ($mac !== null && $mac !== '00:00:00:00:00:00') {
            $macs[$mac] = true;
        }
    }
    foreach ((array) $containers as $c) {
        foreach ((array) ($c['addrs'] ?? []) as $a) {
            if (is_string($a) && ($ip = watchnetIp($a)) !== null) {
                $ips[$ip] = true;
            }
        }
        foreach ((array) ($c['macs'] ?? []) as $a) {
            if (is_string($a) && ($m = watchnetMac($a)) !== null) {
                $macs[$m] = true;
            }
        }
    }
    $name = '';
    if (isset($paths['ident'])) {
        $name = (string) (readCfg((string) $paths['ident'])['NAME'] ?? '');
        $name = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/D', $name) ? strtolower($name) : '';
    }
    foreach (['127.0.0.1', '::1', 'localhost', 'localhost.localdomain'] as $x) {
        $own[$x] = true;
    }
    if ($name !== '') {
        $own[$name] = true;
        $own["$name.local"] = true;
    }
    return ['ips' => $ips, 'macs' => $macs, 'name' => $name, 'own' => $own];
}

/** An address written one way (IPv4 plain, also from ::ffff:a.b.c.d and [x]; IPv6 short), or null */
function watchnetIp(string $s): ?string
{
    $s = trim($s);
    $s = (string) preg_replace('/%[\w.-]+$/', '', trim($s, '[]'));
    if ($s === '' || strlen($s) > 64 || filter_var($s, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $bin = @inet_pton($s);
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    return inet_ntop($bin) ?: null;
}

function watchnetLoopback(string $ip): bool
{
    return $ip === '::1' || str_starts_with($ip, '127.');
}

/** A MAC written one way (lower case, colons), or null */
function watchnetMac(string $s): ?string
{
    $s = strtolower(trim($s));
    if (preg_match('/^[0-9a-f]{2}([:-])[0-9a-f]{2}(?:\1[0-9a-f]{2}){4}$/D', $s)) {
        return str_replace('-', ':', $s);
    }
    return null;
}

/** A MAC the device made up itself (locally administered: phones' private Wi-Fi addresses) */
function watchnetMacRandom(string $mac): bool
{
    return (bool) (hexdec(substr($mac, 0, 2)) & 2);
}

/** A file's sender as rsyslog named it: an address (one way) or a host name (lower case); null when it is neither */
function watchnetSender(string $s): ?string
{
    if (str_contains($s, ':') || preg_match('/^[\d.\[\]]+$/D', $s)) {
        return watchnetIp($s);
    }
    $s = strtolower($s);
    return preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $s) ? $s : null;
}

/** /proc/net/arp: the addresses whose MAC the kernel knows now (flags 0x2 — the neighbour answered) */
function watchnetArp(string $file = '/proc/net/arp'): array
{
    $out = [];
    foreach (array_slice(explode("\n", (string) @file_get_contents($file, false, null, 0, 1 << 20)), 1) as $line) {
        $f = preg_split('/\s+/', trim($line)) ?: [];
        if (count($f) >= 4 && ($ip = watchnetIp($f[0])) !== null && (hexdec($f[2]) & 2)) {
            $out[$ip] = true;
        }
    }
    return $out;
}

// ===================================================================== the files

/**
 * The senders' files in the syslog folder: per sender its current file and the rotated `.1` — plain files only (never
 * a link), the name as rsyslog writes it, at most WATCHNET_SENDERS_MAX senders. The files of this server's own names
 * ($own: the loop) are left out and named in `own`.
 *
 * @return array{readable: bool, files: array<string, array{cur?: array, old?: array}>, own: list<string>, more: int}
 */
function watchnetFiles(string $folder, array $own): array
{
    $dir = function_exists('officeUnraidPath') ? officeUnraidPath($folder) : $folder;
    $h = @opendir($dir);
    if (!$h) {
        return ['readable' => false, 'files' => [], 'own' => [], 'more' => 0];
    }
    $names = [];
    while (($n = readdir($h)) !== false && count($names) < 8 * WATCHNET_SENDERS_MAX) {
        if (str_starts_with($n, 'syslog-') && (str_ends_with($n, '.log') || str_ends_with($n, '.log.1'))) {
            $names[] = $n;
        }
    }
    closedir($h);
    sort($names);
    $files = $skipped = [];
    foreach ($names as $n) {
        $old = str_ends_with($n, '.1');
        if (!preg_match(WATCHNET_FILE, $old ? substr($n, 0, -2) : $n, $m) || ($sender = watchnetSender($m[1])) === null) {
            continue;
        }
        if (isset($own[$sender])) {
            $skipped[$sender] = true;
            continue;
        }
        clearstatcache(true, "$dir/$n");
        $st = @lstat("$dir/$n");
        if (!$st || ($st['mode'] & 0170000) !== 0100000) {
            continue;                       // a link or anything else: never followed
        }
        $files[$sender][$old ? 'old' : 'cur'] = ['path' => "$dir/$n", 'ino' => (int) $st['ino'], 'size' => (int) $st['size'], 'mtime' => (int) $st['mtime']];
    }
    $more = max(0, count($files) - WATCHNET_SENDERS_MAX);
    return ['readable' => true, 'files' => array_slice($files, 0, WATCHNET_SENDERS_MAX, true), 'own' => array_keys($skipped), 'more' => $more];
}

/**
 * What waits to be read of a sender since its position: [path, from, to] parts, oldest first — the rest of the rotated
 * file found by inode, then the current one. No position: the current file (its newest part; the round learns it).
 *
 * @return array{parts: list<array{0: string, 1: int, 2: int}>, rotated: bool}
 */
function watchnetPending(array $f, ?array $pos): array
{
    $cur = $f['cur'] ?? null;
    $old = $f['old'] ?? null;
    if ($pos === null) {
        return ['parts' => $cur ? [[$cur['path'], 0, $cur['size']]] : [], 'rotated' => false];
    }
    if ($cur && (int) ($pos['ino'] ?? -1) === $cur['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= $cur['size']) {
        return ['parts' => [[$cur['path'], (int) $pos['size'], $cur['size']]], 'rotated' => false];
    }
    $parts = [];
    if ($old && (int) ($pos['ino'] ?? -1) === $old['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= $old['size']) {
        $parts[] = [$old['path'], (int) $pos['size'], $old['size']];
    }
    if ($cur) {
        $parts[] = [$cur['path'], 0, $cur['size']];
    }
    return ['parts' => $parts, 'rotated' => true];
}

/**
 * Whole lines of $path from $from to $to — more than $max: the newest part —, each handed to $each (without its line
 * end). Where the last whole line ends; what was read and skipped.
 *
 * @return array{end: int, read: int, skipped: int}
 */
function watchnetReadPart(string $path, int $from, int $to, int $max, callable $each): array
{
    $h = @fopen($path, 'r');
    if (!$h) {
        return ['end' => $from, 'read' => 0, 'skipped' => 0];
    }
    $to = min($to, (int) (fstat($h)['size'] ?? $to));
    $start = max($from, $to - max(0, $max));
    $skipped = $start - $from;
    if ($start > $from && $start > 0) {
        fseek($h, $start - 1);
        if (fgetc($h) !== "\n") {
            $line = fgets($h);                  // began inside a line
            $start += $line === false ? 0 : strlen($line);
        }
    } else {
        fseek($h, $start);
    }
    $at = $start;
    while ($at < $to && ($line = fgets($h, WATCHNET_LINE_MAX + 2)) !== false) {
        if (!str_ends_with($line, "\n")) {
            if (strlen($line) > WATCHNET_LINE_MAX) {
                // too long for a line he reads: the rest of it is skipped, the line is «other»
                $at += strlen($line);
                while (($more = fgets($h, 65536)) !== false) {
                    $at += strlen($more);
                    if (str_ends_with($more, "\n")) {
                        break;
                    }
                }
                $each(null);
                continue;
            }
            break;                              // still being written: the next round reads it
        }
        $at += strlen($line);
        $each(rtrim($line, "\r\n"));
    }
    fclose($h);
    return ['end' => $at, 'read' => $at - $start, 'skipped' => max(0, $skipped)];
}

// ===================================================================== the lines

/**
 * One line of a router file → what it says, or a kind of nothing:
 *   ['kind' => 'own']   his own SIEM line (a loop) — never counted
 *   ['kind' => 'other'] not understood — counted
 *   ['kind' => 'cef', 'th' => header time, 'host' => …, 'utc' => ?int, 'cef' => [vendor, product, version, id, name, sev], 'x' => extension]
 *   ['kind' => 'nf', 'th' => …, 'host' => …, 'nf' => [zone, action, rule, in, out, src, dst, proto, spt, dpt]]
 *   ['kind' => 'ros', 'th' => …, 'host' => …, 'topics' => list, 'text' => …, 'fmt' => syslog|notopics|default|cef,
 *    'version' => RouterOS's version ('' unless the CEF header said it), 'board' => …]  a MikroTik RouterOS line
 */
function watchnetParse(?string $line, int $now): array
{
    if ($line === null || $line === '' || strlen($line) > WATCHNET_LINE_MAX) {
        return ['kind' => 'other'];
    }
    if (preg_match(WATCHNET_OWN, $line)) {
        return ['kind' => 'own'];
    }
    $line = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $line);
    if (!preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d\d:\d\d:\d\d|\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:?\d\d)?)\s+(\S{1,255})\s+(.*)$/D', $line, $m)) {
        return ['kind' => 'other'];
    }
    $th = watchnetHeaderTime($m[1], $now);
    $host = watchnetClean($m[2], 64);
    $body = $m[3];
    if (preg_match('/(?:^|\s)CEF:\s?(\d{1,2})\|/', $body, $c, PREG_OFFSET_CAPTURE)) {
        $cef = watchnetCef(substr($body, (int) $c[0][1] + (str_starts_with($c[0][0], ' ') ? 1 : 0)));
        if ($cef === null) {
            return ['kind' => 'other'];
        }
        if (strcasecmp($cef['h']['vendor'], 'MikroTik') === 0) {
            // RouterOS's CEF: the topics are the record's name, the plain text its msg (rsyslog keeps the CR as «#015»)
            $topics = explode(',', strtolower($cef['h']['name']));
            $text = (string) preg_replace('/(?:#015|\s)+$/D', '', (string) ($cef['x']['msg'] ?? ''));
            if ($text === '' || !preg_match('/^[a-z][a-z0-9-]{0,23}(?:,[a-z][a-z0-9-]{0,23}){0,7}$/D', $cef['h']['name'])) {
                return ['kind' => 'other'];
            }
            return ['kind' => 'ros', 'th' => $th, 'host' => $host, 'head' => watchnetClean($m[1], 32), 'topics' => $topics, 'text' => $text,
                    'fmt' => 'cef', 'version' => watchnetRosVersion($cef['h']['version']), 'board' => $cef['h']['product']];
        }
        $utc = null;
        if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d{1,9})?Z$/D', (string) ($cef['x']['UNIFIutcTime'] ?? ''), $u)) {
            $utc = strtotime($u[1] . ' UTC') ?: null;
        }
        return ['kind' => 'cef', 'th' => $th, 'host' => $host, 'utc' => $utc, 'cef' => $cef['h'], 'x' => $cef['x'], 'head' => watchnetClean($m[1], 32)];
    }
    if (preg_match(WATCHNET_TAG, $body, $t) && preg_match('/\bSRC=\S/', $body)) {
        $kv = [];
        $tail = substr($body, (int) strpos($body, $t[0]) + strlen($t[0]));
        foreach (preg_split('/\s+/', trim($tail)) ?: [] as $tok) {
            if (preg_match('/^(IN|OUT|SRC|DST|PROTO|SPT|DPT)=(\S{0,64})$/D', $tok, $kvm) && !isset($kv[$kvm[1]])) {
                $kv[$kvm[1]] = $kvm[2];
            }
        }
        $src = watchnetIp($kv['SRC'] ?? '');
        $dst = watchnetIp($kv['DST'] ?? '');
        if ($src === null || $dst === null) {
            return ['kind' => 'other'];
        }
        $action = $t[3] !== '' ? $t[3] : $t[4];
        return ['kind' => 'nf', 'th' => $th, 'host' => $host, 'head' => watchnetClean($m[1], 32),
                'nf' => ['zone' => $t[1], 'action' => $action, 'rule' => $t[2] !== '' ? $t[2] : $t[5],
                         'in' => watchnetClean($kv['IN'] ?? '', 16), 'out' => watchnetClean($kv['OUT'] ?? '', 16),
                         'src' => $src, 'dst' => $dst, 'proto' => strtoupper(watchnetClean($kv['PROTO'] ?? '', 8)),
                         'spt' => preg_match('/^\d{1,5}$/D', $kv['SPT'] ?? '') ? (int) $kv['SPT'] : null,
                         'dpt' => preg_match('/^\d{1,5}$/D', $kv['DPT'] ?? '') ? (int) $kv['DPT'] : null]];
    }
    $ros = watchnetRosBody(watchnetClean($body, WATCHNET_LINE_MAX));
    if ($ros !== null) {
        // the `default` format sends no header: rsyslog writes its own time and the sender's address as the host
        $fmt = $ros['fmt'] === 'syslog' && watchnetIp($m[2]) !== null ? 'default' : $ros['fmt'];
        return ['kind' => 'ros', 'th' => $th, 'host' => $host, 'head' => watchnetClean($m[1], 32), 'topics' => $ros['topics'],
                'text' => $ros['text'], 'fmt' => $fmt, 'version' => '', 'board' => ''];
    }
    return ['kind' => 'other'];
}

/** The time in a line's header: "Oct  8 09:18:55" (no year: this year, or last year for December read in January) or ISO */
function watchnetHeaderTime(string $s, int $now): ?int
{
    if (preg_match('/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)$/D', $s, $m)) {
        $year = (int) date('Y', $now);
        $t = strtotime("$m[1] $m[2] $year $m[3]");
        if ($t !== false && $t > $now + 86400) {
            $t = strtotime("$m[1] $m[2] " . ($year - 1) . " $m[3]");
        }
        return $t === false ? null : $t;
    }
    $t = strtotime($s);
    return $t === false ? null : $t;
}

/**
 * A CEF record: `CEF:<v>|<vendor>|<product>|<version>|<id>|<name>|<severity>|<extension>`; in the header `\|` and `\\`
 * are escapes; the extension is key=value pairs where a value runs to the next ` <key>=` (values may hold spaces —
 * UniFi's admin names, durations), `\=`, `\\`, `\n`, `\r` escaped. Null: not a CEF record.
 *
 * @return array{h: array{v: int, vendor: string, product: string, version: string, id: string, name: string, sev: string}, x: array<string, string>}|null
 */
function watchnetCef(string $s): ?array
{
    if (!preg_match('/^CEF:\s?(\d{1,2})\|/', $s, $m)) {
        return null;
    }
    $i = strlen($m[0]);
    $n = strlen($s);
    $fields = [];
    $cur = '';
    while ($i < $n && count($fields) < 6) {
        $ch = $s[$i];
        if ($ch === '\\' && $i + 1 < $n && ($s[$i + 1] === '|' || $s[$i + 1] === '\\')) {
            $cur .= $s[$i + 1];
            $i += 2;
            continue;
        }
        if ($ch === '|') {
            $fields[] = $cur;
            $cur = '';
            $i++;
            continue;
        }
        $cur .= $ch;
        $i++;
    }
    if (count($fields) < 6) {
        return null;
    }
    $h = ['v' => (int) $m[1], 'vendor' => watchnetClean($fields[0], 64), 'product' => watchnetClean($fields[1], 64),
          'version' => watchnetClean($fields[2], 32), 'id' => watchnetClean($fields[3], 64), 'name' => watchnetClean($fields[4], 128),
          'sev' => watchnetClean($fields[5], 16)];
    return ['h' => $h, 'x' => watchnetCefExt(substr($s, $i))];
}

/** CEF's extension: key=value, a value up to the next ` <key>=` (an unescaped =), the escapes undone; the first of a key counts */
function watchnetCefExt(string $x): array
{
    $out = [];
    if (!preg_match_all('/(?:^|(?<=\s))([A-Za-z][A-Za-z0-9_.]{0,63})=/', $x, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }
    $count = count($m[0]);
    for ($k = 0; $k < $count && count($out) < 200; $k++) {
        $key = $m[1][$k][0];
        $from = (int) $m[0][$k][1] + strlen($m[0][$k][0]);
        $to = $k + 1 < $count ? (int) $m[0][$k + 1][1] : strlen($x);
        $raw = rtrim(substr($x, $from, $to - $from));
        $val = strtr($raw, ['\\=' => '=', '\\\\' => '\\', '\\n' => ' ', '\\r' => ' ']);
        if (!isset($out[$key])) {
            $out[$key] = watchnetClean($val, 512);
        }
    }
    return $out;
}

/** Text from a router line made harmless: no control characters, valid UTF-8, at most $max characters */
function watchnetClean(string $s, int $max): string
{
    $s = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return trim(mb_strimwidth($s, 0, $max, '…', 'UTF-8'));
}

/**
 * What a UniFi line is about (its type and the fields that matter for it). Vendor from the line: CEF of Ubiquiti's
 * UniFi Network; netfilter lines from any sender (their shape is Linux's). Types: admin_login (544 «Network Accessed»,
 * «Admin Accessed UniFi Network»), config (an admin's change; `area` when it touches the firewall, NAT, port forwarding
 * or policies — before the rest: a change of VPN or client settings is a change), client (a client connected: its MAC,
 * address, name), client_other
 * (disconnected, roaming), vpn (a VPN client connected: user, remote address), detection (Security: IPS/IDS, honeypot), update (firmware,
 * restart, adoption), wan (failover, the internet), event (understood, nothing to say), blocked (netfilter drop/reject),
 * nf_other (netfilter accept).
 */
function watchnetType(array $p): array
{
    if ($p['kind'] === 'nf') {
        return ['type' => in_array($p['nf']['action'], ['D', 'R'], true) ? 'blocked' : 'nf_other', 'vendor' => 'netfilter'] + $p['nf'];
    }
    if ($p['kind'] === 'ros') {
        return watchnetRouterOs($p);
    }
    $h = $p['cef'];
    if (strcasecmp($h['vendor'], 'Ubiquiti') !== 0 || stripos($h['product'], 'UniFi') === false) {
        return ['type' => 'event', 'vendor' => 'cef'];
    }
    $x = $p['x'];
    $cat = (string) ($x['UNIFIcategory'] ?? '');
    $sub = (string) ($x['UNIFIsubCategory'] ?? '');
    $name = $h['name'];
    $msg = (string) ($x['msg'] ?? '');
    $admin = watchnetName((string) ($x['UNIFIadmin'] ?? ''));
    $src = watchnetIp((string) ($x['src'] ?? ''));
    $base = ['vendor' => 'unifi'];
    if ($h['id'] === '544' || ($admin !== '' && preg_match('/^(?:admin |network )?accessed|\baccessed unifi|\blogged in\b|\badmin log ?in\b/i', $name))) {
        return $base + ['type' => 'admin_login', 'admin' => $admin, 'ip' => $src, 'method' => watchnetClean((string) ($x['UNIFIaccessMethod'] ?? ''), 16)];
    }
    if ($admin !== '' && preg_match('/audit|admin|system/i', "$cat $sub")) {
        // an admin's change (Admin Activity): the firewall, NAT, port forwarding or policies by the words, else another setting
        $text = "$name $msg";
        $area = null;
        if (preg_match(WATCHNET_FIREWALL, $text)) {
            foreach (WATCHNET_AREAS as $a => $re) {
                if (preg_match($re, $text)) {
                    $area = $a;
                    break;
                }
            }
        }
        return $base + ['type' => 'config', 'admin' => $admin, 'ip' => $src, 'area' => $area, 'msg' => watchnetClean($msg !== '' ? $msg : $name, 200)];
    }
    if (preg_match('/client/i', $cat) || preg_match('/\bclient\b/i', $name) && !preg_match('/\bvpn\b/i', "$cat $name")) {
        $mac = watchnetMac((string) ($x['UNIFIclientMac'] ?? ''));
        if ($mac !== null && preg_match('/\bconnected\b/i', $name) && !preg_match('/disconnect/i', $name)) {
            $nm = (string) ($x['UNIFIclientAlias'] ?? '') !== '' ? $x['UNIFIclientAlias'] : (string) ($x['UNIFIclientHostname'] ?? '');
            return $base + ['type' => 'client', 'mac' => $mac, 'ip' => watchnetIp((string) ($x['UNIFIclientIp'] ?? '')),
                            'name' => watchnetName($nm), 'host' => watchnetName((string) ($x['UNIFIclientHostname'] ?? '')),
                            'network' => watchnetName((string) ($x['UNIFInetworkName'] ?? ''))];
        }
        return $base + ['type' => 'client_other', 'mac' => $mac];
    }
    if (preg_match('/\bvpn\b/i', "$cat $sub $name")) {
        if (preg_match('/connect|established|logged in|log ?in\b/i', $name) && !preg_match('/disconnect|fail/i', $name)) {
            $user = '';
            foreach (['UNIFIvpnUser', 'UNIFIvpnUsername', 'UNIFIuser', 'UNIFIusername', 'suser', 'duser'] as $k) {
                if (($x[$k] ?? '') !== '') {
                    $user = watchnetName((string) $x[$k]);
                    break;
                }
            }
            $remote = $src ?? watchnetIp((string) ($x['UNIFIvpnRemoteIp'] ?? $x['UNIFIremoteIp'] ?? ''));
            return $base + ['type' => 'vpn', 'user' => $user, 'ip' => $remote, 'how' => watchnetClean((string) ($x['UNIFIvpnType'] ?? ''), 32)];
        }
        return $base + ['type' => 'event'];
    }
    if (preg_match('/security|threat|intrusion|honeypot/i', "$cat $sub") || preg_match('/threat|intrusion|honeypot|\bIPS\b|\bIDS\b/i', $name)) {
        $sig = (string) ($x['UNIFIipsSignatureId'] ?? '');
        $sig = preg_match('/^\d{1,12}$/D', $sig) ? $sig : 'e' . preg_replace('/[^A-Za-z0-9]/', '', $h['id']);
        return $base + ['type' => 'detection', 'sig' => $sig, 'signature' => watchnetClean((string) ($x['UNIFIipsSignature'] ?? $name), 160),
                        'src' => $src, 'dst' => watchnetIp((string) ($x['dst'] ?? '')),
                        'spt' => preg_match('/^\d{1,5}$/D', (string) ($x['spt'] ?? '')) ? (int) $x['spt'] : null,
                        'dpt' => preg_match('/^\d{1,5}$/D', (string) ($x['dpt'] ?? '')) ? (int) $x['dpt'] : null,
                        'proto' => strtoupper(watchnetClean((string) ($x['proto'] ?? ''), 8)), 'risk' => watchnetClean((string) ($x['UNIFIrisk'] ?? ''), 16)];
    }
    if (preg_match('/\bwan\b|internet|failover|\bisp\b/i', "$cat $name")) {
        return $base + ['type' => 'wan'];
    }
    if (preg_match('/firmware|upgrad|updated|restart|reboot|adopt/i', $name)) {
        return $base + ['type' => 'update'];
    }
    return $base + ['type' => 'event'];
}

/** A name from a router line (an admin, a device, a VPN user): cleaned, short; '' when there is none */
function watchnetName(string $s): string
{
    return watchnetClean($s, 64);
}

// ===================================================================== MikroTik RouterOS (stage 2 of the router SOC, #2)

/*
 * RouterOS 7 sends plain text lines, one per log entry (System → Logging → Actions, target remote). What the file holds
 * depends on the action's format (lines from the lab, tests/fixtures/router/mikrotik-*.log):
 *   syslog, add-topics-string=yes  «Oct  9 13:41:35 lab-chr system,error,critical login failure for user admin from …»
 *   syslog, add-topics-string=no   «Oct  9 14:02:46 lab-chr ether2 link down»  (7.24's default: no topics)
 *   default                        «Oct  9 14:03:47 10.77.3.10 interface,info ether2 link down»  (Unraid's time, the
 *                                  sender's address as the host — no identity)
 *   iso8601                        rsyslog normalises the time: as bsd-syslog
 *   cef                            «CEF: 0|MikroTik|<board>|7.24.5 (stable)|<id>|<topics>|Low|dvchost=… msg=<text>»
 * A line is RouterOS's by its content, never by the file name: a topic list (a known facility word first, a severity
 * among the words), the CEF vendor MikroTik, or — without topics — one of the fixed phrases below. Unknown RouterOS text
 * is an `event` (counted, never an entry). RouterOS logs in English only (no locale in its logging).
 */
const WATCHNET_ROS_SEVERITY = ['info', 'warning', 'error', 'critical', 'debug'];
const WATCHNET_ROS_FACILITY = ['account', 'async', 'backup', 'bfd', 'bgp', 'bridge', 'calc', 'caps', 'certificate', 'clock', 'container',
                               'ddns', 'dhcp', 'disk', 'dns', 'dot1x', 'dude', 'e-mail', 'event', 'fetch', 'firewall', 'gps', 'gsm',
                               'health', 'hotspot', 'igmp-proxy', 'interface', 'ipsec', 'iscsi', 'isdn', 'kvm', 'l2tp', 'ldp', 'lora',
                               'lte', 'manager', 'mme', 'modem', 'mpls', 'netinstall', 'netwatch', 'ntp', 'ospf', 'ovpn', 'pim',
                               'poe-out', 'ppp', 'pppoe', 'pptp', 'ptp', 'queue', 'quickset', 'radius', 'radvd', 'rip', 'romon',
                               'route', 'rpki', 'rsvp', 'script', 'sertcp', 'simulator', 'smb', 'sms', 'snmp', 'socks', 'ssh', 'sstp',
                               'state', 'store', 'stp', 'system', 'telephony', 'tftp', 'timer', 'tr069', 'ups', 'upnp', 'vrrp',
                               'watchdog', 'web-proxy', 'wifi', 'wireguard', 'wireless', 'zerotier'];
// config changes: «<object> <added|removed|changed|moved> by <how>:<user>@<address>[/<action>] (<id> = <command>)» —
// the object's words → the area (the command in brackets is never kept: it holds values, addresses, secrets' names)
const WATCHNET_ROS_AREAS    = ['/^filter rule$/' => 'firewall', '/^nat rule$/' => 'nat',
                               '/^(?:mangle rule|raw rule|address list entry)$/' => 'policy'];
// a firewall log prefix that says the rule drops (a RouterOS firewall line names no action, only its prefix can)
const WATCHNET_ROS_BLOCK    = '/(?:^|[^a-z])(drop|reject|deny|block)(?:ed|s)?(?:$|[^a-z])/i';

/**
 * A line's body (after the header) as RouterOS's, or null: ['topics' => list, 'text' => …, 'fmt' => syslog|notopics].
 * With topics: «<facility>,<word>[,…] <text>» — the first word a RouterOS facility, a severity among them. Without: only
 * when the text is one of the phrases watchnetRouterOs() types (so a stranger's line stays «other»).
 */
function watchnetRosBody(string $body): ?array
{
    if (preg_match('/^([a-z][a-z0-9-]{0,23}(?:,[a-z][a-z0-9-]{0,23}){1,7}) (\S.*)$/D', $body, $m)) {
        $topics = explode(',', $m[1]);
        if (in_array($topics[0], WATCHNET_ROS_FACILITY, true) && array_intersect($topics, WATCHNET_ROS_SEVERITY)) {
            return ['topics' => $topics, 'text' => rtrim($m[2]), 'fmt' => 'syslog'];
        }
    }
    $text = rtrim($body);
    if ($text !== '' && watchnetRosPhrase($text) !== null) {
        return ['topics' => [], 'text' => $text, 'fmt' => 'notopics'];
    }
    return null;
}

/** Which fixed RouterOS phrase a text is (null: none) — anchored on fixed words, never on free text */
function watchnetRosPhrase(string $t): ?string
{
    static $re = [
        'login'     => '/^user (.{1,128}?) logged in(?: from (\S{1,64}))? via ([a-z][a-z0-9-]{0,15})$/D',
        'logout'    => '/^user (.{1,128}?) logged out(?: from (\S{1,64}))? via ([a-z][a-z0-9-]{0,15})$/D',
        'fail'      => '/^login failure for user (.{0,128}?)(?: from (\S{1,64}))? via ([a-z][a-z0-9-]{0,15})$/D',
        'ppp_fail'  => '/^<([^<>\s]{1,64})>: user (.{1,64}?) authentication failed$/D',
        'vpn_in'    => '/^(.{1,64}?) logged in, (\S{1,64}) from (\S{1,64})$/D',
        'vpn_out'   => '/^(.{1,64}?) logged out, [\d ]{1,80}from (\S{1,64})$/D',
        'config'    => '/^([A-Za-z0-9][A-Za-z0-9 ._<>\/-]{0,95}?) (added|removed|changed|moved) by (\S{1,160})(?: \(.*\))?$/D',
        'reboot'    => '/^router rebooted(?: by (\S{1,160}))?$/D',
        'unclean'   => '/^router was rebooted without proper shutdown(?:,.{0,80})?$/D',
        'installed' => '/^installed system-(\d{1,2}\.\d{1,3}(?:\.\d{1,3})?(?:beta\d{1,3}|rc\d{1,3})?)$/D',
        'upgraded'  => '/^RouterOS upgraded from (\S{1,24}) to (\d{1,2}\.\d{1,3}(?:\.\d{1,3})?\S{0,12})$/D',
        'link'      => '/^([A-Za-z0-9._<>\/-]{1,64}) link (up|down)(?: \(.{0,80}\))?$/D',
        'dhcp_c'    => '/^(\S{1,64}) on (\S{1,64}) (got|lost) IP address (\S{1,64})(?: - .{0,80})?$/D',
        'lease'     => '/^(\S{1,64}) (assigned|deassigned) (\S{1,64}) (?:for|to|from) ([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})(?: (.{1,128}))?$/D',
        'ppp_state' => '/^([A-Za-z0-9._\/-]{1,64}): (initializing\.\.\.|connecting\.\.\.|authenticated|connected|disconnected|disabled|terminating\.\.\.(?: - (.{1,80}))?)$/D',
        'firewall'  => '/^(?:(.{1,64}?) )?([a-z][a-z0-9_-]{0,31}): in:(\S{1,64}) out:(.{1,64}?), (?:connection-state:\S{1,32} )?(?:src-mac \S{1,32}, )?proto (.{1,200})$/D',
        'clock'     => '/^change time (\S{1,40} \S{1,16}) => (\S{1,40} \S{1,16})$/D',
        'sshkey'    => '/^publickey accepted for user: \S{1,64}, fingerprint: \S{1,128}$/D',
    ];
    foreach ($re as $what => $r) {
        if (preg_match($r, $t)) {
            return $what;
        }
    }
    return null;
}

/** «ssh-cmd:admin+ct@10.77.3.1/action:0», «api:lab@::», «admin» → how, user (login options «+ct» off), address */
function watchnetRosBy(string $by): array
{
    if (!preg_match('/^(?:([a-z][a-z0-9-]{0,15}):)?([^@\/\s]{1,64}?)(?:@([0-9A-Fa-f.:]{1,64}))?(?:\/\S{0,64})?$/D', $by, $m)) {
        return ['how' => '', 'user' => watchnetName($by), 'ip' => null];
    }
    $ip = watchnetIp($m[3] ?? '');
    return ['how' => (string) $m[1], 'user' => watchnetName((string) preg_replace('/\+[a-z0-9]{0,16}$/D', '', $m[2])),
            'ip' => $ip === '::' ? null : $ip];
}

/** An interface name from a line: capped, only [A-Za-z0-9._-] (a server-side PPP «<l2tp-alice>» loses its brackets) */
function watchnetRosIface(string $s): string
{
    return substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $s), 0, 32);
}

/**
 * What a RouterOS line is about: the same types as UniFi's (admin_login, config, client, client_other, vpn, blocked,
 * nf_other, update, event) plus RouterOS's own — login_fail {admin, ip, method}, logout, link {iface, up}, wan_down /
 * wan_up {iface, via dhcp|pppoe, reason}, reboot {clean, by}, clock. Every value through watchnetClean() (names 64,
 * messages 200); a config `msg` is rebuilt from object + verb, never copied; interface names [A-Za-z0-9._-] ≤ 32.
 */
function watchnetRouterOs(array $p): array
{
    $t = (string) $p['text'];
    $topics = (array) $p['topics'];
    $base = ['vendor' => 'mikrotik', 'topics' => implode(',', $topics)];
    $has = fn (string $w): bool => in_array($w, $topics, true);
    $what = watchnetRosPhrase($t);
    $m = [];
    switch ($what) {
        case 'login':
        case 'fail':
            preg_match($what === 'login' ? '/^user (.{1,128}?) logged in(?: from (\S{1,64}))? via ([a-z][a-z0-9-]{0,15})$/D'
                                         : '/^login failure for user (.{0,128}?)(?: from (\S{1,64}))? via ([a-z][a-z0-9-]{0,15})$/D', $t, $m);
            $ip = watchnetIp($m[2] ?? '');
            if ($what === 'login' && $ip === null && $m[3] === 'api') {
                return $base + ['type' => 'event'];     // REST's inner session: the «via rest-api» line before it says who and from where
            }
            return $base + ['type' => $what === 'login' ? 'admin_login' : 'login_fail', 'admin' => watchnetName($m[1]), 'ip' => $ip,
                            'method' => watchnetClean($m[3], 16)];
        case 'ppp_fail':
            preg_match('/^<([^<>\s]{1,64})>: user (.{1,64}?) authentication failed$/D', $t, $m);
            return $base + ['type' => 'login_fail', 'admin' => watchnetName($m[2]), 'ip' => watchnetIp($m[1]),
                            'method' => watchnetClean((string) ($topics[0] ?? 'ppp'), 16), 'vpn' => true];
        case 'logout':
        case 'vpn_out':
            return $base + ['type' => 'logout'];
        case 'vpn_in':
            preg_match('/^(.{1,64}?) logged in, (\S{1,64}) from (\S{1,64})$/D', $t, $m);
            return $base + ['type' => 'vpn', 'user' => watchnetName($m[1]), 'ip' => watchnetIp($m[3]),
                            'how' => watchnetClean((string) ($topics[0] ?? ''), 32)];
        case 'config':
            preg_match('/^([A-Za-z0-9][A-Za-z0-9 ._<>\/-]{0,95}?) (added|removed|changed|moved) by (\S{1,160})(?: \(.*\))?$/D', $t, $m);
            $object = watchnetClean(trim((string) preg_replace(['/<[^>]*>/', '/\s+/'], ['', ' '], $m[1])), 64);
            $by = watchnetRosBy($m[3]);
            $area = null;
            foreach (WATCHNET_ROS_AREAS as $re => $a) {
                if (preg_match($re, $object)) {
                    $area = $a;
                    break;
                }
            }
            return $base + ['type' => 'config', 'admin' => $by['user'], 'ip' => $by['ip'], 'how' => $by['how'], 'area' => $area,
                            'object' => $object, 'verb' => $m[2], 'msg' => watchnetClean("$object $m[2]", 200)];
        case 'reboot':
            preg_match('/^router rebooted(?: by (\S{1,160}))?$/D', $t, $m);
            return $base + ['type' => 'reboot', 'clean' => true, 'by' => isset($m[1]) ? watchnetRosBy($m[1])['user'] : ''];
        case 'unclean':
            return $base + ['type' => 'reboot', 'clean' => false, 'by' => ''];
        case 'installed':
        case 'upgraded':
            preg_match($what === 'installed' ? '/^installed system-(\S{1,24})$/D' : '/ to (\S{1,24})$/D', $t, $m);
            return $base + ['type' => 'update', 'version' => watchnetClean($m[1], 24)];
        case 'link':
            preg_match('/^([A-Za-z0-9._<>\/-]{1,64}) link (up|down)\b/', $t, $m);
            return $base + ['type' => 'link', 'iface' => watchnetRosIface($m[1]), 'up' => $m[2] === 'up'];
        case 'dhcp_c':
            preg_match('/^(\S{1,64}) on (\S{1,64}) (got|lost) IP address (\S{1,64})/', $t, $m);
            return $base + ['type' => $m[3] === 'got' ? 'wan_up' : 'wan_down', 'iface' => watchnetRosIface($m[2]), 'via' => 'dhcp',
                            'client' => watchnetName($m[1]), 'ip' => watchnetIp($m[4]), 'reason' => ''];
        case 'lease':
            preg_match('/^(\S{1,64}) (assigned|deassigned) (\S{1,64}) (?:for|to|from) ([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})(?: (.{1,128}))?$/D', $t, $m);
            $mac = watchnetMac($m[4]);
            if ($m[2] !== 'assigned' || $mac === null) {
                return $base + ['type' => 'client_other', 'mac' => $mac];
            }
            $name = watchnetName((string) ($m[5] ?? ''));
            return $base + ['type' => 'client', 'mac' => $mac, 'ip' => watchnetIp($m[3]), 'name' => $name, 'host' => $name,
                            'network' => watchnetName($m[1])];
        case 'ppp_state':
            preg_match('/^([A-Za-z0-9._\/-]{1,64}): (\S+)(?: - (.{1,80}))?$/D', $t, $m);
            if ($has('pppoe') && in_array($m[2], ['connected', 'terminating...'], true)) {
                return $base + ['type' => $m[2] === 'connected' ? 'wan_up' : 'wan_down', 'iface' => watchnetRosIface($m[1]), 'via' => 'pppoe',
                                'client' => '', 'ip' => null, 'reason' => watchnetClean((string) ($m[3] ?? ''), 64)];
            }
            return $base + ['type' => 'event'];
        case 'firewall':
            return $base + watchnetRosFirewall($t);
        case 'clock':
            return $base + ['type' => 'clock'];
    }
    return $base + ['type' => 'event'];
}

/**
 * A firewall line: «[<prefix> ]<chain>: in:<if> out:<if>, [connection-state:x ][src-mac m, ]proto P[ (flags)],
 * <src>[:<port>]-><dst>[:<port>][, NAT …], len N». The action only from the prefix (drop/reject/deny/block): blocked
 * (D, R for reject), any other prefix or none → nf_other (counted). Addresses unparsable → event.
 */
function watchnetRosFirewall(string $t): array
{
    if (!preg_match('/^(?:(.{1,64}?) )?([a-z][a-z0-9_-]{0,31}): in:(\S{1,64}) out:(.{1,64}?), (?:connection-state:\S{1,32} )?(?:src-mac \S{1,32}, )?proto ([A-Za-z0-9-]{1,16})(?: \([^)]{0,64}\))?, (\S{1,80})->(\S{1,80}?)(?:,| |$)/', $t, $m)) {
        return ['type' => 'event'];
    }
    $end = function (string $s): array {
        if (preg_match('/^\[([0-9A-Fa-f:.]{2,64})\]:(\d{1,5})$/D', $s, $x) || preg_match('/^((?:\d{1,3}\.){3}\d{1,3}):(\d{1,5})$/D', $s, $x)) {
            return [watchnetIp($x[1]), (int) $x[2]];
        }
        return [watchnetIp(trim($s, '[]')), null];
    };
    [$src, $spt] = $end($m[6]);
    [$dst, $dpt] = $end($m[7]);
    if ($src === null || $dst === null) {
        return ['type' => 'event'];
    }
    $prefix = watchnetClean((string) $m[1], 64);
    $action = preg_match(WATCHNET_ROS_BLOCK, $prefix, $w) ? (strtolower($w[1]) === 'reject' ? 'R' : 'D') : 'A';
    return ['type' => $action === 'A' ? 'nf_other' : 'blocked', 'zone' => watchnetClean($m[2], 32), 'action' => $action, 'rule' => $prefix,
            'in' => watchnetRosIface($m[3]), 'out' => watchnetRosIface(str_replace('(unknown 0)', '', $m[4])), 'src' => $src, 'dst' => $dst,
            'proto' => strtoupper($m[5]), 'spt' => $spt, 'dpt' => $dpt];
}

/** A RouterOS version as the CEF header says it («7.24.5 (stable)») → «7.24.5»; '' when it isn't one */
function watchnetRosVersion(string $s): string
{
    return preg_match('/^(\d{1,2}\.\d{1,3}(?:\.\d{1,3})?(?:beta\d{1,3}|rc\d{1,3})?)(?:\s|$)/', trim($s), $m) ? $m[1] : '';
}

// ===================================================================== a round: reading (outside the book's lock)

/**
 * What the round reads of the router files (only while the array runs, the syslog server is on and the folder's disks
 * are awake; never in the night shift — $paths without `rsyslog_cfg`): per sender the typed events of what is new
 * since the last round (from net.json's positions), its counts, its newest line's time; WATCH_READ_MAX shared by all
 * files in proportion to what waits (beyond: the newest part, `skipped`). $server: watchnetServer().
 *
 * @return array{state: string, cfg: array, senders: array, own: list<string>, skipped: int, read: int, more: int, arp: array}
 */
function watchnetLook(array $paths, ?array $net, array $server, int $now, int $max = WATCH_READ_MAX): array
{
    $cfg = watchnetConfig((string) ($paths['rsyslog_cfg'] ?? WATCHNET_CFG));
    $look = ['state' => 'off', 'cfg' => $cfg, 'senders' => [], 'own' => [], 'skipped' => 0, 'read' => 0, 'more' => 0, 'arp' => [], 'bases' => [], 'since' => 0];
    if (!$cfg['on']) {
        return $look;
    }
    if (isset($paths['var_ini']) && function_exists('arrayRunning') && !arrayRunning((string) $paths['var_ini'])) {
        return ['state' => 'array'] + $look;            // rsyslog's file action is off while the array is stopped
    }
    $disks = readCfg((string) ($paths['disks_ini'] ?? '/var/local/emhttp/disks.ini'), true);
    $shares = readCfg((string) ($paths['shares_ini'] ?? '/var/local/emhttp/shares.ini'), true);
    $b = watchnetBases((string) $cfg['folder'], $shares, $disks);
    $look['bases'] = $b['bases'];
    if (!$b['known']) {
        return ['state' => 'unknown'] + $look;
    }
    $sleeping = watchnetSleeping($disks);
    foreach ($b['bases'] as $base) {
        if (baseAsleep($base, $sleeping)) {
            return ['state' => 'asleep'] + $look;       // never woken: read when it is awake again
        }
    }
    $files = watchnetFiles((string) $cfg['folder'], $server['own']);
    if (!$files['readable']) {
        return ['state' => 'unreadable'] + $look;
    }
    $look['state'] = 'on';
    $look['own'] = $files['own'];
    $look['more'] = $files['more'];
    $look['arp'] = isset($paths['arp']) ? watchnetArp((string) $paths['arp']) : [];
    $look['since'] = watchnetWriting($paths);
    $pos = (array) ($net['pos'] ?? []);
    $pending = $waiting = [];
    foreach ($files['files'] as $sender => $f) {
        $pending[$sender] = watchnetPending($f, is_array($pos[$sender] ?? null) ? $pos[$sender] : null);
        $waiting[$sender] = array_sum(array_map(fn ($p) => max(0, $p[2] - $p[1]), $pending[$sender]['parts']));
    }
    $total = array_sum($waiting);
    foreach ($files['files'] as $sender => $f) {
        $learn = !is_array($pos[$sender] ?? null);
        $share = $total > $max ? (int) floor($max * $waiting[$sender] / max(1, $total)) : $max;
        if ($learn) {
            $share = min($share, defined('WATCH_LEARN_MAX') ? WATCH_LEARN_MAX : 16 * 1024 * 1024);
        }
        $acc = watchnetAcc();
        $acc['learn'] = $learn;
        $acc['rotated'] = $pending[$sender]['rotated'];
        $acc['cur'] = isset($f['cur']) ? ['ino' => $f['cur']['ino'], 'size' => $f['cur']['size'], 'mtime' => $f['cur']['mtime']] : null;
        $end = null;
        $left = $share;
        // the newest part first in mind: the parts are oldest first, so the budget goes to the last ones
        $parts = $pending[$sender]['parts'];
        $sizes = array_map(fn ($p) => max(0, $p[2] - $p[1]), $parts);
        $allow = [];
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $allow[$i] = min($sizes[$i], $left);
            $left -= $allow[$i];
        }
        foreach ($parts as $i => [$path, $from, $to]) {
            $r = watchnetReadPart($path, $from, $to, $allow[$i], function (?string $line) use (&$acc, $now, $server): void {
                watchnetTake($acc, $line, $now, $server);
            });
            $acc['read'] += $r['read'];
            $acc['skipped'] += $r['skipped'];
            if ($i === count($parts) - 1 && isset($f['cur']) && $path === $f['cur']['path']) {
                $end = $r['end'];
            }
        }
        $acc['pos'] = isset($f['cur']) ? ['ino' => $f['cur']['ino'], 'size' => $end ?? $f['cur']['size']] : (is_array($pos[$sender] ?? null) ? $pos[$sender] : null);
        $acc['at_end'] = isset($f['cur']) && ($end ?? -1) === $f['cur']['size'];
        $look['senders'][$sender] = $acc;
        $look['read'] += $acc['read'];
        $look['skipped'] += $learn ? 0 : $acc['skipped'];       // a new sender's history is skipped on purpose
    }
    return $look;
}

/**
 * Since when rsyslog writes the files: the array's last start (the event scripts' lines, RAM) or the server's start —
 * while the array is stopped its file action is off and the router's lines are dropped, so silence counts from then.
 */
function watchnetWriting(array $paths): int
{
    $since = 0;
    if (isset($paths['array_events'])) {
        foreach (explode("\n", (string) @file_get_contents((string) $paths['array_events'], false, null, 0, 65536)) as $line) {
            if (preg_match('/^(\d{9,11}) start$/D', trim($line), $m)) {
                $since = max($since, (int) $m[1]);
            }
        }
    }
    if (isset($paths['stat']) && preg_match('/^btime (\d{9,11})$/m', (string) @file_get_contents((string) $paths['stat'], false, null, 0, 65536), $m)) {
        $since = max($since, (int) $m[1]);
    }
    return $since;
}

/** A sender's tally for one round */
function watchnetAcc(): array
{
    return ['lines' => 0, 'other' => 0, 'own' => 0, 'events' => [], 'dropped' => 0, 'read' => 0, 'skipped' => 0, 'learn' => false, 'rotated' => false,
            'meta' => null, 'newest' => null, 'counts' => [], 'det_other' => [], 'det_other_n' => 0, 'nf_other' => 0, 'det_seen' => false, 'nf_seen' => false, 'cef_seen' => false,
            'ros_seen' => false, 'ros_fmt' => [], 'clock_off' => 0];
}

/**
 * One line into a sender's tally: his own lines left out, the rest counted; typed events about the server — and the
 * LAN's devices — kept (at most WATCHNET_EVENTS_MAX); what concerns other clients only as a count (the privacy rule).
 */
function watchnetTake(array &$acc, ?string $line, int $now, array $server): void
{
    $p = watchnetParse($line, $now);
    if ($p['kind'] === 'own') {
        $acc['own']++;
        return;
    }
    $acc['lines']++;
    if ($p['kind'] === 'other') {
        $acc['other']++;
        return;
    }
    $t = watchnetType($p);
    $time = $p['utc'] ?? $p['th'] ?? $now;
    $acc['newest'] = ['th' => $p['th'], 'utc' => $p['utc'] ?? null, 't' => $time];
    if ($p['kind'] === 'cef' && $t['vendor'] === 'unifi') {
        $acc['cef_seen'] = true;
        $acc['meta'] = ['vendor' => 'Ubiquiti', 'product' => $p['cef']['product'], 'version' => $p['cef']['version'], 'host' => $p['host']];
    } elseif ($p['kind'] === 'nf') {
        $acc['nf_seen'] = true;
        $acc['meta'] ??= ['vendor' => null, 'product' => null, 'version' => null, 'host' => $p['host']];
    } elseif ($p['kind'] === 'ros') {
        // MikroTik: the version only from a CEF header or an «installed system-…» line; no identity in the `default` format
        $acc['ros_seen'] = true;
        $acc['ros_fmt'][$p['fmt']] = (int) ($acc['ros_fmt'][$p['fmt']] ?? 0) + 1;
        $version = $p['version'] !== '' ? $p['version'] : (string) ($t['version'] ?? '');
        $acc['meta'] = ['vendor' => 'MikroTik', 'product' => 'RouterOS', 'version' => $version !== '' ? $version : ($acc['meta']['version'] ?? null),
                        'host' => $p['fmt'] === 'default' ? ($acc['meta']['host'] ?? null) : $p['host']];
        if ($p['th'] !== null && abs((int) $p['th'] - $now) > 86400) {
            // a router clock a day or more off (a board without a real-time clock after a power loss starts at its build
            // time — RouterOS refuses anything earlier): the event happened when it arrived
            $acc['clock_off']++;
            $time = $now;
        }
    }
    $type = $t['type'];
    $acc['counts'][$type] = ($acc['counts'][$type] ?? 0) + 1;
    $ips = $server['ips'];
    if ($type === 'detection') {
        $acc['det_seen'] = true;
        if (!isset($ips[$t['src'] ?? '']) && !isset($ips[$t['dst'] ?? ''])) {
            $acc['det_other_n']++;
            foreach (array_filter([$t['src'] ?? null, $t['dst'] ?? null]) as $ip) {
                if (count($acc['det_other']) < WATCHNET_LAN_MAX || isset($acc['det_other'][$ip])) {
                    $acc['det_other'][$ip] = ($acc['det_other'][$ip] ?? 0) + 1;     // for the «whole LAN» counts only
                }
            }
            return;
        }
    } elseif ($type === 'blocked') {
        if (!isset($ips[$t['src']])) {
            $acc['nf_other']++;
            return;
        }
    } elseif (in_array($type, ['client_other', 'nf_other', 'update', 'wan', 'event', 'logout', 'clock'], true)) {
        return;
    }
    if (count($acc['events']) >= WATCHNET_EVENTS_MAX) {
        $acc['dropped']++;
        return;
    }
    $acc['events'][] = ['t' => (int) $time, 'th' => $p['th'], 'head' => $p['head'] ?? '', 'host' => $p['host'],
                        'cef' => $p['kind'] === 'cef' ? ['id' => $p['cef']['id'], 'name' => $p['cef']['name']] : null,
                        'x' => $p['kind'] === 'cef' ? watchnetEvidenceFields($type, $p['x']) : null] + $t;
}

/** The extension fields an entry's evidence may show, per type (the privacy rule: no other client's fields elsewhere) */
function watchnetEvidenceFields(string $type, array $x): array
{
    $keep = match ($type) {
        'admin_login' => ['UNIFIcategory', 'UNIFIaccessMethod', 'UNIFIadmin', 'src', 'msg'],
        'client'      => ['UNIFIcategory', 'UNIFIclientAlias', 'UNIFIclientHostname', 'UNIFIclientIp', 'UNIFIclientMac', 'UNIFInetworkName', 'msg'],
        'config'      => ['UNIFIcategory', 'UNIFIsubCategory', 'UNIFIadmin', 'src', 'msg'],
        'vpn'         => ['UNIFIcategory', 'UNIFIvpnType', 'UNIFIvpnUser', 'src', 'msg'],
        'detection'   => ['UNIFIcategory', 'UNIFIsubCategory', 'UNIFIipsSignature', 'UNIFIipsSignatureId', 'UNIFIrisk', 'proto', 'src', 'spt', 'dst', 'dpt'],
        default       => [],
    };
    return array_intersect_key($x, array_flip($keep));
}

/**
 * An entry's evidence: the router's line as far as the entry may show it, ≤ WATCHNET_EVIDENCE characters — the header,
 * the CEF id and name (or the netfilter tag) and the fields of its type; every address not in $allow becomes «…», a MAC
 * keeps its first half only.
 */
function watchnetEvidence(array $ev, array $allow): string
{
    if (($ev['vendor'] ?? '') === 'mikrotik') {
        $s = sprintf('%s %s %s %s', $ev['head'], $ev['host'], $ev['topics'] ?? '', watchnetRosEvidence($ev));
    } elseif (($ev['vendor'] ?? '') === 'netfilter') {
        $s = sprintf('%s %s kernel: [%s-%s-%s] IN=%s OUT=%s SRC=%s DST=%s PROTO=%s SPT=%s DPT=%s', $ev['head'], $ev['host'], $ev['zone'], $ev['action'], $ev['rule'],
            $ev['in'], $ev['out'], $ev['src'], $ev['dst'], $ev['proto'], $ev['spt'] ?? '', $ev['dpt'] ?? '');
    } else {
        $parts = [];
        foreach ((array) ($ev['x'] ?? []) as $k => $v) {
            $parts[] = "$k=$v";
        }
        $s = sprintf('%s %s CEF %s|%s %s', $ev['head'], $ev['host'], $ev['cef']['id'] ?? '', $ev['cef']['name'] ?? '', implode(' ', $parts));
    }
    return mb_strimwidth(watchnetScrub($s, $allow), 0, WATCHNET_EVIDENCE, '…', 'UTF-8');
}

/** A RouterOS line rebuilt from its type's own fields (never the raw text: a config line's command holds values) */
function watchnetRosEvidence(array $ev): string
{
    $port = fn (?string $ip, ?int $p): string => $p === null ? (string) $ip : (str_contains((string) $ip, ':') ? "[$ip]:$p" : "$ip:$p");
    return match ((string) ($ev['type'] ?? '')) {
        'admin_login' => sprintf('user %s logged in%s via %s', $ev['admin'], $ev['ip'] !== null ? " from {$ev['ip']}" : '', $ev['method']),
        'login_fail'  => !empty($ev['vpn']) ? sprintf('<%s>: user %s authentication failed', $ev['ip'] ?? '?', $ev['admin'])
                       : sprintf('login failure for user %s%s via %s', $ev['admin'], $ev['ip'] !== null ? " from {$ev['ip']}" : '', $ev['method']),
        'config'      => sprintf('%s by %s%s', $ev['msg'], $ev['how'] !== '' ? "{$ev['how']}:" : '', $ev['admin']),
        'client'      => sprintf('%s assigned %s for %s %s', $ev['network'], $ev['ip'] ?? '?', $ev['mac'], $ev['name']),
        'vpn'         => sprintf('%s logged in, … from %s', $ev['user'], $ev['ip'] ?? '?'),
        'blocked', 'nf_other' => sprintf('%s %s: in:%s out:%s, proto %s, %s->%s', $ev['rule'], $ev['zone'], $ev['in'], $ev['out'], $ev['proto'],
                                         $port($ev['src'], $ev['spt']), $port($ev['dst'], $ev['dpt'])),
        'link'        => sprintf('%s link %s', $ev['iface'], $ev['up'] ? 'up' : 'down'),
        'wan_down', 'wan_up' => sprintf('%s %s (%s)', $ev['iface'], $ev['type'] === 'wan_up' ? 'up' : 'down', $ev['via']),
        'reboot'      => $ev['clean'] ? 'router rebooted' : 'router was rebooted without proper shutdown',
        default       => (string) ($ev['type'] ?? ''),
    };
}

/** Every address in $s that isn't in $allow becomes «…»; MACs keep their first half */
function watchnetScrub(string $s, array $allow): string
{
    $s = (string) preg_replace_callback('/\b[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}\b/', fn ($m) => strtolower(substr($m[0], 0, 8)) . ':…', $s);
    $s = (string) preg_replace_callback('/(?<![\w.:])(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?(?![\w.])/', function ($m) use ($allow) {
        $ip = watchnetIp((string) preg_replace('#/\d+$#', '', $m[0]));
        return $ip !== null && isset($allow[$ip]) ? $m[0] : '…';
    }, $s);
    return (string) preg_replace_callback('/(?<![\w:])(?:[0-9A-Fa-f]{1,4}:){2,7}[0-9A-Fa-f]{0,4}(?![\w:])/', function ($m) use ($allow) {
        $ip = watchnetIp($m[0]);
        return $ip === null ? $m[0] : (isset($allow[$ip]) ? $m[0] : '…');
    }, $s);
}

// ===================================================================== a round: judging (inside the book's lock)

/** The network part of his baseline, every key there */
function watchnetBase(mixed $b): array
{
    $b = is_array($b) ? $b : [];
    foreach (['senders', 'devices', 'self', 'admins', 'vpn', 'blocked', 'ips_in'] as $k) {
        $b[$k] = is_array($b[$k] ?? null) ? $b[$k] : [];
    }
    $b['time'] = (int) ($b['time'] ?? 0);
    return $b;
}

/** net.json in its shape */
function watchnetState(mixed $s): array
{
    $s = is_array($s) ? $s : [];
    foreach (['pos', 'senders', 'days', 'blocked', 'seen', 'lan'] as $k) {
        $s[$k] = is_array($s[$k] ?? null) ? $s[$k] : [];
    }
    return ['v' => 1] + $s;
}

/**
 * The round's look against what is normal: entries into $book, the baseline ($b, his baseline's `net`) and net.json
 * ($ns) brought up to date. $fresh: he takes over the watch anew — all of it normal. A router seen for the first time
 * (no position yet) is learned: its history in the file is normal, nothing of it told — and a sender that came after
 * the net watch began is `net_sender_new`. $lan: the «whole LAN» switch (per-device counts).
 *
 * @return list<string>  the kinds of new entries
 */
function watchnetCompare(?array &$b, array $look, array &$book, array &$ns, array $server, int $now, bool $fresh, bool $lan = false): array
{
    $ns = watchnetState($fresh ? null : $ns);
    $ns['look'] = ['time' => $now, 'state' => $look['state'], 'cfg' => array_intersect_key($look['cfg'], array_flip(['on', 'folder', 'share', 'ident', 'local', 'remote'])),
                   'own' => $look['own'], 'skipped' => $look['skipped'], 'read' => $look['read'], 'more' => $look['more']];
    if ($look['state'] !== 'on') {
        return [];                                  // off, asleep, the array stopped: as it was (nothing read, nothing forgotten)
    }
    $first = $fresh || !is_array($b);               // the first round that sees the syslog server on: all of it normal
    $b = watchnetBase($fresh ? null : $b);
    if ($first) {
        $b['time'] = $now;
    }
    $added = [];
    $today = date('Y-m-d', $now);
    foreach ($look['senders'] as $sender => $acc) {
        $sender = (string) $sender;
        $learn = $first || $acc['learn'];
        if (!isset($b['senders'][$sender])) {
            if (!$first) {
                $added[] = watchmanSet($book, 'net_sender_new', "net_sender_new:$sender", $now,
                    ['sender' => $sender, 'host' => (string) ($acc['meta']['host'] ?? ''), 'product' => (string) ($acc['meta']['product'] ?? ''),
                     'version' => (string) ($acc['meta']['version'] ?? ''), 'lines' => (int) $acc['lines']]);
            }
            $b['senders'][$sender] = $now;
        }
        $s = is_array($ns['senders'][$sender] ?? null) ? $ns['senders'][$sender] : ['first' => $now, 'lines' => 0, 'last' => null, 'gaps' => []];
        $s['lines'] = (int) $s['lines'] + (int) $acc['lines'];
        foreach (['meta'] as $k) {
            if (is_array($acc[$k])) {
                $old = is_array($s[$k] ?? null) ? $s[$k] : [];
                $s[$k] = $acc[$k];
                if (($acc[$k]['vendor'] ?? null) === 'MikroTik' && ($old['vendor'] ?? null) === 'MikroTik') {
                    // a RouterOS version is said once (a boot after an upgrade, or every CEF line): kept until another is said
                    $s[$k]['version'] ??= $old['version'] ?? null;
                    $s[$k]['host'] ??= $old['host'] ?? null;
                }
            }
        }
        foreach (['cef_seen' => 'cef', 'det_seen' => 'det', 'nf_seen' => 'nf', 'ros_seen' => 'ros'] as $from => $to) {
            $s[$to] = !empty($s[$to]) || !empty($acc[$from]);
        }
        foreach ((array) ($acc['ros_fmt'] ?? []) as $fmt => $n) {
            $s['fmt'][(string) $fmt] = $now;           // the RouterOS formats seen, and when last (the summary's hint, package 2)
        }
        $day = is_array($ns['days'][$today][$sender] ?? null) ? $ns['days'][$today][$sender] : [];
        $day['lines'] = (int) ($day['lines'] ?? 0) + (int) $acc['lines'];
        $day['other'] = (int) ($day['other'] ?? 0) + (int) $acc['other'];
        foreach ($acc['counts'] as $type => $n) {
            $day['c'][$type] = (int) ($day['c'][$type] ?? 0) + (int) $n;
        }
        $day['nf_other'] = (int) ($day['nf_other'] ?? 0) + (int) $acc['nf_other'];
        $day['det_other'] = (int) ($day['det_other'] ?? 0) + (int) $acc['det_other_n'];
        $ns['days'][$today][$sender] = $day;
        // it grew: when (the file's time), the gap since the last growth, the router's clock against the arrival
        $cur = $acc['cur'];
        $grew = $cur !== null && (int) $acc['lines'] + (int) $acc['own'] > 0;
        if ($grew) {
            $last = isset($s['last']) ? (int) $s['last'] : null;
            if ($last !== null && $cur['mtime'] > max($last, (int) ($look['since'] ?? 0))) {
                $gap = $cur['mtime'] - max($last, (int) ($look['since'] ?? 0));      // an array stop between them is no gap of the router's
                $s['gaps'][$today] = max((int) ($s['gaps'][$today] ?? 0), $gap);
            }
            $s['last'] = $cur['mtime'];
            if ($acc['at_end'] && is_array($acc['newest']) && $acc['newest']['t'] !== null && !$learn) {
                $s['skew'] = $cur['mtime'] - (int) $acc['newest']['t'];
                $s['zone'] = $acc['newest']['utc'] !== null && $acc['newest']['th'] !== null ? (int) $acc['newest']['th'] - (int) $acc['newest']['utc'] : null;
            }
        } elseif ($cur !== null && !isset($s['last'])) {
            $s['last'] = $cur['mtime'];
        }
        $s['gaps'] = array_slice(array_filter((array) $s['gaps'], fn ($k) => (string) $k >= date('Y-m-d', $now - WATCHNET_GAP_DAYS * 86400), ARRAY_FILTER_USE_KEY), -WATCHNET_GAP_DAYS, null, true);
        $ns['pos'][$sender] = $acc['pos'];
        $ns['senders'][$sender] = $s;
        // the events, oldest first
        foreach ($acc['events'] as $ev) {
            $more = watchnetEvent($b, $ns, $book, $sender, $ev, $server, $now, $learn, $lan);
            if ($more !== null) {
                $added[] = $more;
            }
        }
        if ($lan) {
            foreach ($acc['det_other'] as $ip => $n) {
                $mac = watchnetMacOf($ns, (string) $ip);
                if ($mac !== null) {
                    $ns['lan'][$mac]['d'] = (int) ($ns['lan'][$mac]['d'] ?? 0) + (int) $n;
                }
            }
        }
        if (!$learn) {
            $more = watchnetSilent($book, $ns, $sender, $grew, $look['arp'], $now, (int) ($look['since'] ?? 0));
            if ($more !== null) {
                $added[] = $more;
            }
        }
        if ((int) $acc['skipped'] > 0 && !$learn) {
            watchnetTooMuch($book, $sender, (int) $acc['skipped'], $now);
        }
    }
    // a sender whose file went (the folder emptied, another folder): nothing read of it, nothing forgotten — its position stays
    if (!$lan) {
        $ns['lan'] = [];
    }
    watchnetTidy($b, $ns, $now);
    return array_values(array_filter($added));
}

/** The MAC a LAN address had at the newest client line (net.json seen) — for the «whole LAN» counts only */
function watchnetMacOf(array $ns, string $ip): ?string
{
    foreach ((array) $ns['seen'] as $mac => $x) {
        if (($x['ip'] ?? null) === $ip) {
            return (string) $mac;
        }
    }
    return null;
}

/**
 * One event of a sender. $learn: its history (the first round that sees it) — what is in it is normal: devices,
 * admins and their addresses, VPN users, what the gateway blocked, signatures against the server; nothing told.
 * @return string|null  the kind of a new entry
 */
function watchnetEvent(array &$b, array &$ns, array &$book, string $sender, array $ev, array $server, int $now, bool $learn, bool $lan): ?string
{
    $t = min((int) $ev['t'], $now + 60);
    $type = (string) $ev['type'];
    $router = (string) ($ns['senders'][$sender]['meta']['host'] ?? $ev['host'] ?? $sender);
    $date = date('Y-m-d', $t ?: $now);
    $allow = $server['ips'];
    switch ($type) {
        case 'admin_login':
            $admin = (string) $ev['admin'];
            $ip = $ev['ip'];
            if ($admin === '') {
                return null;
            }
            $known = isset($b['admins'][$admin]);
            if ($learn || ($known && ($ip === null || isset($b['admins'][$admin]['ips'][$ip])))) {
                $b['admins'][$admin] ??= ['first' => $t, 'ips' => []];
                if ($ip !== null) {
                    $b['admins'][$admin]['ips'][$ip] = $t;
                }
                $b['admins'][$admin]['last'] = $t;
                return null;
            }
            return watchmanBump($book, 'net_router_login', "net_router_login:$sender:$admin:" . ($ip ?? '-'), $t, 1,
                ['sender' => $sender, 'router' => $router, 'admin' => $admin, 'ip' => $ip, 'method' => (string) $ev['method'], 'new_admin' => !$known,
                 'evidence' => watchnetEvidence($ev, $allow + ($ip !== null ? [$ip => true] : []))]);
        case 'client':
            $mac = (string) $ev['mac'];
            $ip = $ev['ip'];
            $name = (string) $ev['name'];
            $host = strtolower((string) $ev['host']);
            $ns['seen'][$mac] = ['t' => $t, 'ip' => $ip];
            if ($lan) {
                $ns['lan'][$mac]['c'] = (int) ($ns['lan'][$mac]['c'] ?? 0) + 1;
                $ns['lan'][$mac]['n'] = $name;
            }
            // someone claims the server's name or one of its addresses with a MAC that isn't the server's
            $claims = [];
            if ($server['name'] !== '' && ($host === $server['name'] || strtolower($name) === $server['name'])) {
                $claims['name:' . $server['name']] = ['what' => 'name', 'name' => $server['name']];
            }
            if ($ip !== null && isset($server['ips'][$ip])) {
                $claims["address:$ip"] = ['what' => 'address', 'ip' => $ip];
            }
            $out = null;
            foreach ($claims as $key => $c) {
                $known = array_merge(array_keys($server['macs']), array_keys((array) ($b['self'][$key] ?? [])));
                if (in_array($mac, $known, true)) {
                    continue;
                }
                if ($learn) {
                    $b['self'][$key][$mac] = $t;
                    continue;
                }
                $out = watchmanSet($book, 'net_spoof', "net_spoof:$key", $now, $c + ['sender' => $sender, 'router' => $router, 'mac' => $mac,
                    'device' => $name, 'device_ip' => $ip, 'known' => array_slice($known, 0, WATCH_LIST_MAX),
                    'evidence' => watchnetEvidence($ev, $allow + ($ip !== null ? [$ip => true] : []))]) ?? $out;
            }
            if ($out !== null || isset($server['macs'][$mac])) {
                return $out;                    // the server itself, or someone taking its place (that entry says it): no device of the LAN
            }
            if (isset($b['devices'][$mac])) {
                if ($name !== '' && ($b['devices'][$mac]['n'] ?? '') === '') {
                    $b['devices'][$mac]['n'] = $name;
                }
                return $out;
            }
            // a MAC never seen: learned at once (one entry per MAC); taking a known device's name is worth more
            $taken = null;
            if ($name !== '') {
                foreach ($b['devices'] as $other => $d) {
                    if (strcasecmp((string) ($d['n'] ?? ''), $name) === 0) {
                        $taken = (string) $other;
                        break;
                    }
                }
            }
            $b['devices'][$mac] = ['n' => $name, 'f' => $t];
            if ($learn) {
                return $out;
            }
            $kind = watchmanSet($book, 'net_new_device', "net_new_device:$mac", $now, ['sender' => $sender, 'router' => $router, 'mac' => $mac,
                'ip' => $ip, 'name' => $name, 'network' => (string) $ev['network'], 'taken' => $taken,
                'random' => watchnetMacRandom($mac), 'evidence' => watchnetEvidence($ev, $allow + ($ip !== null ? [$ip => true] : []))]);
            // a known device's name on a new MAC: told at once — unless both are a phone's made-up addresses (they change by themselves)
            if ($kind !== null && $taken !== null && !(watchnetMacRandom($mac) && watchnetMacRandom($taken))) {
                $book[array_key_last($book)]['important'] = true;
            }
            return $kind ?? $out;
        case 'vpn':
            $user = (string) $ev['user'] !== '' ? (string) $ev['user'] : '?';
            $ip = $ev['ip'];
            if ($learn || (isset($b['vpn'][$user]) && ($ip === null || isset($b['vpn'][$user]['ips'][$ip])))) {
                $b['vpn'][$user] ??= ['first' => $t, 'ips' => []];
                if ($ip !== null) {
                    $b['vpn'][$user]['ips'][$ip] = $t;
                }
                return null;
            }
            return watchmanBump($book, 'net_vpn_login', "net_vpn_login:$sender:$user:" . ($ip ?? '-'), $t, 1,
                ['sender' => $sender, 'router' => $router, 'user' => $user, 'ip' => $ip, 'how' => (string) $ev['how'], 'new_user' => !isset($b['vpn'][$user]),
                 'evidence' => watchnetEvidence($ev, $allow + ($ip !== null ? [$ip => true] : []))]);
        case 'config':
            if ($learn) {
                return null;
            }
            if ($ev['area'] !== null) {
                return watchmanBump($book, 'net_firewall_change', "net_firewall_change:$sender:{$ev['area']}", $t, 1,
                    ['sender' => $sender, 'router' => $router, 'area' => (string) $ev['area'], 'admins' => [(string) $ev['admin']],
                     'msgs' => [watchnetScrub((string) $ev['msg'], $allow)], 'evidence' => watchnetEvidence($ev, $allow + ($ev['ip'] !== null ? [$ev['ip'] => true] : []))]);
            }
            return watchnetDayLine($book, $b, $sender, $router, $ev, $date, $t, $now, $allow);
        case 'detection':
            $in = isset($server['ips'][$ev['dst'] ?? '']);
            $dir = $in ? 'in' : 'out';
            $remote = $in ? $ev['src'] : $ev['dst'];
            $mine = $in ? $ev['dst'] : $ev['src'];
            $ns['days'][date('Y-m-d', $now)]['_det'][$dir] = (int) ($ns['days'][date('Y-m-d', $now)]['_det'][$dir] ?? 0) + 1;
            if ($learn) {
                if ($in) {
                    $b['ips_in'][(string) $ev['sig']] = $t;
                }
                return null;
            }
            if ($in && isset($b['ips_in'][(string) $ev['sig']])) {
                return null;                    // inbound, this signature known: counted only
            }
            return watchmanBump($book, 'net_ips_server', "net_ips_server:{$ev['sig']}:$dir", $t, 1,
                ['sender' => $sender, 'router' => $router, 'sig' => (string) $ev['sig'], 'signature' => (string) $ev['signature'], 'dir' => $dir,
                 'remote' => $remote, 'server' => $mine, 'dpt' => $ev['dpt'], 'proto' => (string) $ev['proto'], 'risk' => (string) $ev['risk'],
                 'evidence' => watchnetEvidence($ev, $allow + ($remote !== null ? [$remote => true] : []))]);
        case 'blocked':
            $key = watchnetBlockedKey((string) $ev['dst'], (string) $ev['proto'], $ev['dpt']);
            $bk = is_array($ns['blocked'][$key] ?? null) ? $ns['blocked'][$key] : ['n' => 0, 'first' => $t];
            $bk['n'] = (int) $bk['n'] + 1;
            $bk['last'] = $t;
            $bk['dst'] = (string) $ev['dst'];
            $ns['blocked'][$key] = $bk;
            $ns['days'][date('Y-m-d', $now)]['_blocked'] = (int) ($ns['days'][date('Y-m-d', $now)]['_blocked'] ?? 0) + 1;
            if ($learn || isset($b['blocked'][$key])) {
                $b['blocked'][$key] ??= $t;
                return null;
            }
            return watchmanBump($book, 'net_blocked_from_server', "net_blocked_from_server:$key", $t, 1,
                ['sender' => $sender, 'router' => $router, 'key' => $key, 'dst' => (string) $ev['dst'], 'dpt' => $ev['dpt'], 'proto' => (string) $ev['proto'],
                 'server' => (string) $ev['src'], 'zone' => (string) $ev['zone'], 'rule' => (string) $ev['rule'], 'action' => (string) $ev['action'],
                 'evidence' => watchnetEvidence($ev, $allow + [(string) $ev['dst'] => true])]);
    }
    return null;
}

/** What the gateway blocked from the server, as a key: the destination's /24 (IPv4) or /48 (IPv6) — they rotate —, protocol and port */
function watchnetBlockedKey(string $dst, string $proto, ?int $port): string
{
    if (str_contains($dst, ':')) {
        $bin = (string) @inet_pton($dst);
        $net = strlen($bin) === 16 ? inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48' : $dst;
    } else {
        $net = (string) preg_replace('/\.\d+$/', '.0', $dst) . '/24';
    }
    return $net . ' ' . strtolower($proto !== '' ? $proto : 'ip') . ($port !== null ? "/$port" : '');
}

/**
 * Other config changes: one line a day per router — noted by himself (`by` router) while every admin making them is one
 * he knows (in his baseline), else open (told in the book, not as a notification: not important).
 */
function watchnetDayLine(array &$book, array $b, string $sender, string $router, array $ev, string $date, int $t, int $now, array $allow): ?string
{
    $admin = (string) $ev['admin'];
    $known = isset($b['admins'][$admin]);
    $key = "net_router_config:$sender:$date:" . ($known ? 'k' : 'u');
    $msg = watchnetScrub((string) $ev['msg'], $allow);
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && ($e['kind'] ?? '') === 'net_router_config' && (watchmanOpen($e) || ($e['by'] ?? null) === 'router')) {
            $book[$i]['count'] = (int) $e['count'] + 1;
            $book[$i]['last'] = max((int) $e['last'], $t);
            $book[$i]['p'] = watchmanMerge((array) $e['p'], ['admins' => [$admin], 'msgs' => [$msg]]);
            return null;
        }
    }
    $e = watchmanEntry('net_router_config', $key, $t, ['sender' => $sender, 'router' => $router, 'date' => $date, 'admins' => [$admin], 'msgs' => [$msg],
        'evidence' => watchnetEvidence($ev, $allow + ($ev['ip'] !== null ? [$ev['ip'] => true] : []))]);
    if ($known) {
        $e['noted'] = $now;
        $e['by'] = 'router';
    }
    $book[] = $e;
    return $known ? null : 'net_router_config';
}

/**
 * The router's file stopped growing for more than WATCHNET_SILENT_FACTOR × its usual gap (the longest of the last
 * WATCHNET_GAP_DAYS days; at least WATCHNET_SILENT_FLOOR) while the router still answers (ARP): its logging was
 * switched off or the path broke. Counted from the array's start at the earliest ($since: lines are dropped while it is
 * stopped). Once; a line again closes it by itself (`by` router).
 */
function watchnetSilent(array &$book, array &$ns, string $sender, bool $grew, array $arp, int $now, int $since = 0): ?string
{
    $key = "net_log_silent:$sender";
    if ($grew) {
        foreach ($book as $i => $e) {
            if (($e['key'] ?? '') === $key && watchmanOpen($e)) {
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'router';
                $book[$i]['p']['back'] = $now;
            }
        }
        return null;
    }
    $s = (array) ($ns['senders'][$sender] ?? []);
    $last = isset($s['last']) ? (int) $s['last'] : null;
    if ($last === null || empty($s['cef']) && empty($s['nf']) && empty($s['ros'])) {
        return null;                            // not known as a router (yet)
    }
    $usual = max(array_map('intval', (array) ($s['gaps'] ?? [])) ?: [0]);
    $limit = max(WATCHNET_SILENT_FLOOR, WATCHNET_SILENT_FACTOR * $usual);
    if ($now - max($last, $since) <= $limit || !isset($arp[$sender])) {
        return null;
    }
    return watchmanSet($book, 'net_log_silent', $key, $now, ['sender' => $sender, 'router' => (string) ($s['meta']['host'] ?? $sender), 'last' => $last,
        'usual' => $usual, 'hours' => (int) floor(($now - $last) / 3600)]);
}

/** More than he reads in a round waited: a plain line in the book (once a day per sender, `watch`) — set the gateway to Blocked only */
function watchnetTooMuch(array &$book, string $sender, int $skipped, int $now): void
{
    $key = "net_too_much:$sender:" . date('Y-m-d', $now);
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key) {
            $book[$i]['p']['skipped'] = (int) ($e['p']['skipped'] ?? 0) + $skipped;
            $book[$i]['last'] = $now;
            return;
        }
    }
    $book[] = watchmanEntry('watch', $key, $now, ['too_much' => 1, 'skipped' => $skipped, 'sender' => $sender]);
}

/** His memory of the network kept small: the lists capped, the days 14, devices by their newest sight */
function watchnetTidy(array &$b, array &$ns, int $now): void
{
    $cut = date('Y-m-d', $now - WATCHNET_DAYS * 86400);
    $ns['days'] = array_filter($ns['days'], fn ($k) => (string) $k > $cut, ARRAY_FILTER_USE_KEY);
    ksort($ns['days']);
    $cap = function (array $list, int $max, callable $by): array {
        if (count($list) <= $max) {
            return $list;
        }
        uasort($list, fn ($x, $y) => $by($y) <=> $by($x));
        return array_slice($list, 0, $max, true);
    };
    if (count($b['devices']) > WATCHNET_DEVICES_MAX) {
        $when = [];
        foreach ($b['devices'] as $mac => $d) {
            $when[$mac] = (int) ($ns['seen'][$mac]['t'] ?? $d['f'] ?? 0);
        }
        arsort($when);
        $b['devices'] = array_intersect_key($b['devices'], array_slice($when, 0, WATCHNET_DEVICES_MAX, true));
    }
    $ns['seen'] = array_intersect_key($ns['seen'], $b['devices']);
    $b['admins'] = $cap($b['admins'], WATCHNET_ADMINS_MAX, fn ($a) => (int) ($a['last'] ?? $a['first'] ?? 0));
    $b['vpn'] = $cap($b['vpn'], WATCHNET_VPN_MAX, fn ($a) => max(array_map('intval', (array) ($a['ips'] ?? [])) ?: [(int) ($a['first'] ?? 0)]));
    foreach (['admins', 'vpn'] as $k) {
        foreach ($b[$k] as $name => $a) {
            if (count((array) ($a['ips'] ?? [])) > WATCH_LIST_MAX * 4) {
                arsort($b[$k][$name]['ips']);
                $b[$k][$name]['ips'] = array_slice($b[$k][$name]['ips'], 0, WATCH_LIST_MAX * 4, true);
            }
        }
    }
    foreach (['blocked' => WATCHNET_BLOCKED_MAX, 'ips_in' => WATCHNET_SIGS_MAX] as $k => $max) {
        if (count($b[$k]) > $max) {
            arsort($b[$k]);
            $b[$k] = array_slice($b[$k], 0, $max, true);
        }
    }
    $ns['blocked'] = $cap($ns['blocked'], WATCHNET_BLOCKED_MAX, fn ($x) => (int) ($x['last'] ?? 0));
    $ns['lan'] = array_slice($ns['lan'], 0, WATCHNET_LAN_MAX, true);
    if (count($ns['senders']) > WATCHNET_SENDERS_MAX * 2) {
        $ns['senders'] = $cap($ns['senders'], WATCHNET_SENDERS_MAX * 2, fn ($s) => (int) ($s['last'] ?? 0));
        $ns['pos'] = array_intersect_key($ns['pos'], $ns['senders']);
    }
}

/**
 * «I know, thanks» on a network entry: what becomes normal — the MAC that claims the server's name or address, the
 * admin's address, the VPN user's address, an inbound signature, a blocked destination; an admin changing settings is
 * known from now on. A new sender, a new device: learned already; a firewall change, an outbound signature, silence:
 * nothing to learn (an outbound hit is told again — never learned away).
 */
function watchnetAdopt(array &$b, array $e, int $now): void
{
    $p = (array) ($e['p'] ?? []);
    $net = watchnetBase($b['net'] ?? null);
    switch ((string) $e['kind']) {
        case 'net_spoof':
            // this MAC may carry the server's name or address (a second NIC, a VM, a container) — and is a device of the LAN then
            $key = ($p['what'] ?? '') === 'name' ? 'name:' . (string) ($p['name'] ?? '') : 'address:' . (string) ($p['ip'] ?? '');
            if (is_string($p['mac'] ?? null)) {
                $net['self'][$key][$p['mac']] = $now;
                $net['devices'][$p['mac']] ??= ['n' => (string) ($p['device'] ?? ''), 'f' => $now];
            }
            break;
        case 'net_router_login':
            $admin = (string) ($p['admin'] ?? '');
            if ($admin !== '') {
                $net['admins'][$admin] ??= ['first' => (int) $e['time'], 'ips' => []];
                if (is_string($p['ip'] ?? null)) {
                    $net['admins'][$admin]['ips'][$p['ip']] = $now;
                }
                $net['admins'][$admin]['last'] = $now;
            }
            break;
        case 'net_router_config':
            foreach ((array) ($p['admins'] ?? []) as $admin) {
                if (is_string($admin) && $admin !== '') {
                    $net['admins'][$admin] ??= ['first' => $now, 'ips' => [], 'last' => $now];
                }
            }
            break;
        case 'net_vpn_login':
            $user = (string) ($p['user'] ?? '?');
            $net['vpn'][$user] ??= ['first' => (int) $e['time'], 'ips' => []];
            if (is_string($p['ip'] ?? null)) {
                $net['vpn'][$user]['ips'][$p['ip']] = $now;
            }
            break;
        case 'net_ips_server':
            if (($p['dir'] ?? '') === 'in' && is_string($p['sig'] ?? null)) {
                $net['ips_in'][$p['sig']] = $now;
            }
            break;
        case 'net_blocked_from_server':
            if (is_string($p['key'] ?? null)) {
                $net['blocked'][$p['key']] = $now;
            }
            break;
    }
    $b['net'] = $net;
}

// ===================================================================== words, chains, the page, metrics

/** The words an entry's texts take (entry.<kind>, check.<kind>, notify.<kind>): names, addresses, numbers — the firewall's area in $lang's words (null: its code, the page says it); [] for other kinds */
function watchnetText(array $e, ?string $lang = null): array
{
    $p = (array) ($e['p'] ?? []);
    $router = (string) ($p['router'] ?? $p['sender'] ?? '');
    $area = 'net_area.' . (in_array($p['area'] ?? '', array_keys(WATCHNET_AREAS), true) ? $p['area'] : 'firewall');     // its words: the page's and notifications'
    return match ((string) ($e['kind'] ?? '')) {
        'net_sender_new'          => ['sender' => (string) ($p['sender'] ?? ''), 'host' => (string) ($p['host'] ?? '') ?: (string) ($p['sender'] ?? '')],
        'net_new_device'          => ['name' => (string) ($p['name'] ?? '') ?: (string) ($p['mac'] ?? ''), 'mac' => (string) ($p['mac'] ?? ''),
                                      'ip' => (string) ($p['ip'] ?? '') ?: '–', 'router' => $router],
        'net_spoof'               => ['what' => ($p['what'] ?? '') === 'name' ? (string) ($p['name'] ?? '') : (string) ($p['ip'] ?? ''),
                                      'mac' => (string) ($p['mac'] ?? ''), 'device' => (string) ($p['device'] ?? '') ?: '?', 'router' => $router],
        'net_router_login'        => ['admin' => (string) ($p['admin'] ?? ''), 'ip' => (string) ($p['ip'] ?? '') ?: '?', 'router' => $router],
        'net_firewall_change'     => ['area' => $lang === null ? (string) ($p['area'] ?? 'firewall') : officeNotifyText('watchman', $area, [], $lang),
                                      'admin' => implode(', ', array_map('strval', (array) ($p['admins'] ?? []))),
                                      'router' => $router],
        'net_router_config'       => ['admin' => implode(', ', array_map('strval', (array) ($p['admins'] ?? []))), 'router' => $router, 'date' => (string) ($p['date'] ?? '')],
        'net_vpn_login'           => ['user' => (string) ($p['user'] ?? '?'), 'ip' => (string) ($p['ip'] ?? '') ?: '?', 'router' => $router],
        'net_ips_server'          => ['signature' => (string) ($p['signature'] ?? '') ?: (string) ($p['sig'] ?? ''), 'remote' => (string) ($p['remote'] ?? '') ?: '?',
                                      'server' => (string) ($p['server'] ?? ''), 'router' => $router],
        'net_blocked_from_server' => ['dst' => (string) ($p['dst'] ?? ''), 'port' => isset($p['dpt']) ? strtolower((string) ($p['proto'] ?? '')) . '/' . (int) $p['dpt'] : '–',
                                      'server' => (string) ($p['server'] ?? ''), 'router' => $router],
        'net_log_silent'          => ['router' => $router, 'hours' => (int) ($p['hours'] ?? 0)],
        'net_too_much'            => ['sender' => (string) ($p['sender'] ?? ''), 'skipped' => (int) ($p['skipped'] ?? 0),
                                      'size' => function_exists('watchmanSize') ? watchmanSize((int) ($p['skipped'] ?? 0)) : (string) (int) ($p['skipped'] ?? 0)],
        default                   => [],
    };
}

/**
 * A new device on the LAN and, within WATCHNET_CHAIN, a login on Unraid or an SMB session from that address — one chain
 * (the concept's «a new device on the LAN and 10 minutes later a login on Unraid»), joined to a chain the other entry is
 * in already. $chains: watchmanChains()'s; returns them with these.
 */
function watchnetChains(array $book, array $chains, int $now): array
{
    $open = array_values(array_filter($book, fn ($e) => watchmanOpen($e) && (int) $e['time'] >= $now - (defined('WATCH_CHAIN_KEEP') ? WATCH_CHAIN_KEEP : 7 * 86400)));
    $byId = array_column($open, null, 'id');
    foreach ($open as $d) {
        if ($d['kind'] !== 'net_new_device' || !is_string($d['p']['ip'] ?? null)) {
            continue;
        }
        foreach ($open as $o) {
            if (!in_array($o['kind'], ['login_new_ip', 'smb_client', 'smb_user'], true) || ($o['p']['ip'] ?? null) !== $d['p']['ip']
                || abs((int) $o['time'] - (int) $d['time']) > WATCHNET_CHAIN) {
                continue;
            }
            $at = null;
            foreach ($chains as $i => $c) {
                if (in_array($o['id'], $c['ids'], true) || in_array($d['id'], $c['ids'], true)) {
                    $at = $i;
                    break;
                }
            }
            if ($at === null) {
                $chains[] = ['key' => (string) $d['id'], 'ids' => [], 'groups' => [], 'first' => 0, 'last' => 0];
                $at = array_key_last($chains);
            }
            $ids = array_values(array_unique(array_merge($chains[$at]['ids'], [$d['id'], $o['id']])));
            $list = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
            usort($list, fn ($a, $b) => [(int) $a['time'], $a['id']] <=> [(int) $b['time'], $b['id']]);
            $chains[$at]['ids'] = array_column($list, 'id');
            $chains[$at]['groups'] = array_values(array_unique(array_map(fn ($e) => WATCH_KINDS[$e['kind']][0] ?? 'watch', $list)));
            $chains[$at]['first'] = (int) $list[0]['time'];
            $chains[$at]['last'] = (int) end($list)['time'];
        }
    }
    return $chains;
}

/** The router's clock (a posture tip): a sender whose newest line was off its arrival by more than WATCHNET_CLOCK */
function watchnetClock(?array $ns): ?array
{
    $off = [];
    foreach ((array) ($ns['senders'] ?? []) as $sender => $s) {
        if (isset($s['skew']) && abs((int) $s['skew']) > WATCHNET_CLOCK) {
            $off[] = ['sender' => (string) $sender, 'router' => (string) ($s['meta']['host'] ?? $sender), 'minutes' => (int) round((int) $s['skew'] / 60),
                      'zone' => isset($s['zone']) && $s['zone'] !== null && abs((int) $s['zone']) > WATCHNET_CLOCK];
        }
    }
    return $off ?: null;
}

/**
 * «What I keep an eye on» of the network: Unraid's syslog server, the senders (the router's name and version from its
 * lines, the age of its last line, lines a day, lines not understood), devices known (a count), admins and VPN users
 * (counts), security detections this week (against or from the server; the rest a count, never the client), what the
 * gateway blocked from the server (a count and the top destinations), what was dropped (updates, the WAN). With the
 * «whole LAN» switch on: per device the counts. Null: never looked.
 */
function watchnetSummary(?array $b, ?array $ns, bool $lan, int $now): ?array
{
    $ns = is_array($ns) ? watchnetState($ns) : null;
    if ($ns === null || !is_array($ns['look'] ?? null)) {
        return null;
    }
    $b = watchnetBase($b);
    $week = array_filter($ns['days'], fn ($k) => (string) $k > date('Y-m-d', $now - 7 * 86400), ARRAY_FILTER_USE_KEY);
    $senders = [];
    foreach ($ns['senders'] as $sender => $s) {
        $days = array_filter(array_map(fn ($d) => is_array($d[$sender] ?? null) ? $d[$sender] : null, $ns['days']));
        $lines = array_sum(array_map(fn ($d) => (int) ($d['lines'] ?? 0), $days));
        $other = array_sum(array_map(fn ($d) => (int) ($d['other'] ?? 0), $days));
        $span = max(1, (int) ceil(($now - (int) ($s['first'] ?? $now)) / 86400));
        $senders[] = ['sender' => (string) $sender, 'host' => (string) ($s['meta']['host'] ?? ''), 'product' => (string) ($s['meta']['product'] ?? ''),
                      'version' => (string) ($s['meta']['version'] ?? ''), 'vendor' => (string) ($s['meta']['vendor'] ?? ''),
                      'last' => isset($s['last']) ? (int) $s['last'] : null, 'per_day' => (int) round($lines / min($span, max(1, count($days)))),
                      'lines' => (int) ($s['lines'] ?? 0), 'other_pct' => $lines > 0 ? round(100 * $other / $lines, 1) : 0.0,
                      'cef' => !empty($s['cef']), 'detections' => !empty($s['det']), 'firewall' => !empty($s['nf']),
                      'skew' => isset($s['skew']) ? (int) $s['skew'] : null];
    }
    usort($senders, fn ($x, $y) => [$y['last'] ?? 0, $x['sender']] <=> [$x['last'] ?? 0, $y['sender']]);
    $det = ['in' => 0, 'out' => 0, 'other' => 0];
    $blocked = 0;
    $dropped = ['update' => 0, 'wan' => 0];
    foreach ($week as $d) {
        $det['in'] += (int) ($d['_det']['in'] ?? 0);
        $det['out'] += (int) ($d['_det']['out'] ?? 0);
        $blocked += (int) ($d['_blocked'] ?? 0);
        foreach ($d as $k => $x) {
            if (is_string($k) && !str_starts_with($k, '_') && is_array($x)) {
                $det['other'] += (int) ($x['det_other'] ?? 0);
                $dropped['update'] += (int) ($x['c']['update'] ?? 0);
                $dropped['wan'] += (int) ($x['c']['wan'] ?? 0);
            }
        }
    }
    $top = $ns['blocked'];
    uasort($top, fn ($x, $y) => (int) ($y['n'] ?? 0) <=> (int) ($x['n'] ?? 0));
    $tops = [];
    foreach (array_slice($top, 0, 5, true) as $key => $x) {
        $tops[] = ['key' => (string) $key, 'n' => (int) ($x['n'] ?? 0), 'last' => (int) ($x['last'] ?? 0), 'known' => isset($b['blocked'][$key])];
    }
    $lanList = null;
    if ($lan) {
        $lanList = [];
        foreach ($ns['lan'] as $mac => $x) {
            $lanList[] = ['mac' => (string) $mac, 'name' => (string) ($x['n'] ?? ($b['devices'][$mac]['n'] ?? '')), 'connects' => (int) ($x['c'] ?? 0),
                          'detections' => (int) ($x['d'] ?? 0)];
        }
        usort($lanList, fn ($x, $y) => [$y['connects'] + $y['detections'], $x['name']] <=> [$x['connects'] + $x['detections'], $y['name']]);
        $lanList = array_slice($lanList, 0, 100);
    }
    $look = (array) $ns['look'];
    return [
        'state'      => (string) ($look['state'] ?? 'off'),
        'looked'     => (int) ($look['time'] ?? 0),
        'cfg'        => (array) ($look['cfg'] ?? []),
        'own'        => array_values((array) ($look['own'] ?? [])),
        'skipped'    => (int) ($look['skipped'] ?? 0),
        'more'       => (int) ($look['more'] ?? 0),
        'since'      => $b['time'] ?: null,
        'senders'    => $senders,
        'devices'    => count($b['devices']),
        'admins'     => count($b['admins']),
        'vpn'        => count($b['vpn']),
        'detections' => $det,
        'blocked'    => ['week' => $blocked, 'top' => $tops],
        'dropped'    => $dropped,
        'lan'        => $lanList,
    ];
}

/**
 * For the office's metrics: open network entries per kind, lines read per sender (counter since he watches), the newest
 * line's arrival per sender — from his files only (state.json, net.json).
 */
function watchnetMetrics(array $st, ?array $ns): array
{
    $open = [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        if (str_starts_with($kind, 'net_')) {
            $open[] = [['kind' => $kind], (int) ($st['open'][$kind] ?? 0)];
        }
    }
    $lines = $last = [];
    foreach (array_slice((array) ($ns['senders'] ?? []), 0, 16, true) as $sender => $s) {
        $lines[] = [['sender' => (string) $sender], (int) ($s['lines'] ?? 0)];
        if (isset($s['last'])) {
            $last[] = [['sender' => (string) $sender], (int) $s['last']];
        }
    }
    return [
        ['name' => 'uso_watchman_net_entries', 'type' => 'gauge', 'help' => 'Night watchman: network entries (the router\'s lines) not noted yet, per kind', 'samples' => $open],
        ['name' => 'uso_watchman_net_lines_total', 'type' => 'counter', 'help' => 'Lines the night watchman read of each sender\'s file in Unraid\'s syslog server folder', 'samples' => $lines],
        ['name' => 'uso_watchman_net_last_line_timestamp_seconds', 'type' => 'gauge', 'help' => 'When each sender\'s newest line arrived in Unraid\'s syslog server folder (unix time)', 'samples' => $last],
    ];
}

// ===================================================================== the other desks: the Team Lead, the Consultant, Ms. Protocolli

/**
 * The share, its storage and its exports as the Team Lead and the Consultant need them (RAM and the flash only — nothing
 * under /mnt is asked). $o: paths for the tests (cfg, shares_ini, disks_ini, var_ini, sec, sec_nfs).
 */
function watchnetFacts(array $o = []): array
{
    $cfg = watchnetConfig((string) ($o['cfg'] ?? WATCHNET_CFG));
    $disks = readCfg((string) ($o['disks_ini'] ?? '/var/local/emhttp/disks.ini'), true);
    $shares = readCfg((string) ($o['shares_ini'] ?? '/var/local/emhttp/shares.ini'), true);
    $var = readCfg((string) ($o['var_ini'] ?? '/var/local/emhttp/var.ini'));
    $default = (int) ($var['spindownDelay'] ?? 0);
    $always = watchnetAlwaysOn($disks, $default);
    $bases = $cfg['folder'] !== null ? watchnetBases($cfg['folder'], $shares, $disks) : ['share' => null, 'bases' => [], 'array' => false, 'known' => true];
    $sleepy = array_values(array_filter($bases['bases'], fn ($x) => !isset($always[$x])));
    $exports = [];
    if ($cfg['share'] !== null) {
        foreach (['smb' => (string) ($o['sec'] ?? '/var/local/emhttp/sec.ini'), 'nfs' => (string) ($o['sec_nfs'] ?? '/var/local/emhttp/sec_nfs.ini')] as $proto => $file) {
            $sec = readCfg($file, true)[$cfg['share']] ?? null;
            if (is_array($sec) && ($sec['export'] ?? '-') !== '-' && ($sec['export'] ?? '') !== '') {
                $exports[$proto] = (string) ($sec['security'] ?? 'public');
            }
        }
    }
    // where a new share `syslog` belongs (the Consultant's guide, Benj 2026-10-08): the pools that never sleep - every disk
    // an SSD or never spun down (watchnetAlwaysOn) -, never the boot pool (a pool on the device of a Boot slot: Unraid boots
    // from it; the partner door keeps its copies off it too); the array only when every data disk is an SSD
    $bootDev = [];
    foreach ($disks as $d) {
        if (in_array($d['type'] ?? '', ['Boot', 'Flash'], true) && (string) ($d['device'] ?? '') !== '') {
            $bootDev[(string) $d['device']] = true;
        }
    }
    $pools = [];
    foreach (array_keys($always) as $name) {
        if (preg_match('/^disk\d+$/D', (string) $name)) {
            continue;
        }
        $members = array_filter($disks, fn ($x, $n) => preg_match('/^' . preg_quote((string) $name, '/') . '\d*$/D', (string) ($x['name'] ?? $n)) === 1, ARRAY_FILTER_USE_BOTH);
        if (!array_filter($members, fn ($x) => isset($bootDev[(string) ($x['device'] ?? '')]))) {
            $pools[] = (string) $name;
        }
    }
    sort($pools);
    $data = array_filter($disks, fn ($x, $n) => ($x['type'] ?? '') === 'Data' && (string) ($x['fsType'] ?? '') !== ''
        && preg_match('/^disk\d+$/D', (string) ($x['name'] ?? $n)) === 1, ARRAY_FILTER_USE_BOTH);
    $arraySsd = $data !== [] && !array_filter($data, fn ($x) => ($x['rotational'] ?? '1') !== '0');
    return ['cfg' => $cfg, 'bases' => $bases, 'sleepy' => $sleepy, 'exports' => $exports, 'pools' => $pools, 'array_ssd' => $arraySsd, 'always' => $always];
}

/**
 * The Team Lead's checks of Unraid's syslog server for the router (agent/desks/caretaker.php): the share sleeps, no
 * rotation, the share exported, a container holds UDP 514, ⟦Remote syslog server⟧ is this server (a loop), and — a
 * hint — the server off although the night watchman is hired and the Consultant's router guide was opened. Cheap: the
 * flash, emhttp's ini files, `docker ps` (cached) and an inspect of a known syslog image only.
 * $o: the tests' paths and stand-ins — facts' paths, server (watchnetServer()), hired (bool), guide (time or null),
 * containers (list of name, image, running), inspect (callable name → docker inspect array), link.
 *
 * @return list<array>  findings
 */
function watchnetChecks(array $o = []): array
{
    $f = watchnetFacts($o);
    $cfg = $f['cfg'];
    $link = (string) ($o['link'] ?? 'settings');
    $out = [];
    $server = $o['server'] ?? watchnetServer(['ident' => '/boot/config/ident.cfg']);
    if ($cfg['remote'] !== null) {
        $r = watchnetSender($cfg['remote']);
        $loop = $r !== null && (isset($server['own'][$r]) || isset($server['ips'][$r]));
        $out[] = finding('syslog_loop', 'recommended', !$loop, ['remote' => $cfg['remote']], $link);
    }
    if (!$cfg['on']) {
        if (!empty($o['hired']) && !empty($o['guide'])) {
            $out[] = finding('syslog_off', 'hint', false, [], $link);
        }
        return $out;
    }
    $share = $cfg['share'] ?? (string) $cfg['folder'];
    $sleepy = $f['sleepy'];
    $out[] = finding('syslog_share_sleeps', $f['bases']['array'] && !array_filter($f['bases']['bases'], fn ($x) => !preg_match('/^disk\d+$/D', $x)) ? 'required' : 'recommended',
        !$sleepy, ['share' => $share, 'disks' => implode(', ', $sleepy) ?: '–', 'pools' => implode(', ', $f['pools']) ?: '–'], $link);
    $total = $cfg['size'] * max(1, $cfg['files']);
    $out[] = finding('syslog_no_rotation', 'required', $cfg['rotation'] && $cfg['size'] > 0 && $total <= WATCHNET_ROTATION_MAX,
        ['share' => $share, 'size' => $cfg['size'] > 0 ? (int) round($cfg['size'] / 1024 ** 2) . ' MB' : '–', 'files' => $cfg['files']], $link);
    if ($cfg['share'] !== null) {
        $out[] = finding('syslog_share_public', 'recommended', !$f['exports'],
            ['share' => $cfg['share'], 'proto' => strtoupper(implode(', ', array_keys($f['exports']))) ?: '–'], $f['exports'] ? 'settings' : $link);
    }
    // a container that listens on UDP 514 itself (FireSight, Alloy, syslog-ng …): one receiver only
    $holders = [];
    $containers = $o['containers'] ?? (function_exists('houseContainers') ? houseContainers() : []);
    $inspect = $o['inspect'] ?? (function_exists('houseInspect') ? 'houseInspect' : null);
    foreach ($containers as $c) {
        foreach (WATCHNET_PORT_HOLDERS as $re => $what) {
            if (!preg_match($re, (string) ($c['image'] ?? '')) || $inspect === null) {
                continue;
            }
            $i = $inspect((string) $c['name']);
            if (is_array($i) && watchnetHolds514($i, (int) $cfg['port'])) {
                $holders[] = (string) $c['name'] . " ($what)";
            }
            break;
        }
    }
    $out[] = finding('syslog_port_taken', 'recommended', !$holders, ['port' => $cfg['port'], 'names' => implode(', ', $holders) ?: '–'], $holders ? 'docker' : $link);
    return $out;
}

/** Does this container take the syslog port: published as UDP, or on the host's network (where such images listen on it by default)? */
function watchnetHolds514(array $i, int $port): bool
{
    foreach ((array) ($i['HostConfig']['PortBindings'] ?? []) as $spec => $binds) {
        if (preg_match('#^(\d+)/udp$#D', (string) $spec, $m)) {
            foreach ((array) $binds as $bnd) {
                if ((int) ($bnd['HostPort'] ?? 0) === $port) {
                    return true;
                }
            }
        }
    }
    if (($i['HostConfig']['NetworkMode'] ?? '') === 'host') {
        foreach ((array) ($i['Config']['Env'] ?? []) as $env) {
            if (preg_match('/^(?:SYSLOG_UDP_PORT|SYSLOG_PORT)=(\d+)$/D', (string) $env, $m)) {
                return (int) $m[1] === $port;
            }
        }
        return true;
    }
    return false;
}

/**
 * For the Consultant's «Unraid's syslog server»: what is set (from the flash), the loop, where a share `syslog` of its
 * own belongs (the pools that never sleep, the array when it is all SSDs), and — only while the folder's disks are awake
 * — which senders have files there and the age of their newest line (a stat per file). Never a line's text.
 */
function watchnetAdvisor(array $o = []): array
{
    $f = watchnetFacts($o);
    $cfg = $f['cfg'];
    $server = $o['server'] ?? watchnetServer(['ident' => '/boot/config/ident.cfg']);
    $r = $cfg['remote'] !== null ? watchnetSender($cfg['remote']) : null;
    $senders = null;
    $asleep = false;
    if ($cfg['on']) {
        $disks = readCfg((string) ($o['disks_ini'] ?? '/var/local/emhttp/disks.ini'), true);
        $sleeping = watchnetSleeping($disks);
        $asleep = (bool) array_filter($f['bases']['bases'], fn ($x) => baseAsleep($x, $sleeping)) || !$f['bases']['known'];
        if (!$asleep) {
            $senders = [];
            foreach (watchnetFiles((string) $cfg['folder'], $server['own'])['files'] as $sender => $x) {
                if (isset($x['cur'])) {
                    $senders[] = ['sender' => (string) $sender, 'mtime' => $x['cur']['mtime'], 'size' => $x['cur']['size']];
                }
            }
        }
    }
    return ['on' => $cfg['on'], 'local' => $cfg['local'], 'there' => $cfg['there'], 'folder' => $cfg['folder'], 'share' => $cfg['share'],
            'ident' => $cfg['ident'], 'protocol' => $cfg['protocol'], 'port' => $cfg['port'], 'rotation' => $cfg['rotation'],
            'size' => $cfg['size'], 'files' => $cfg['files'], 'remote' => $cfg['remote'],
            'loop' => $r !== null && (isset($server['own'][$r]) || isset($server['ips'][$r])), 'sleepy' => $f['sleepy'],
            'exported' => array_keys($f['exports']), 'pools' => $f['pools'], 'array_ssd' => $f['array_ssd'], 'asleep' => $asleep, 'senders' => $senders];
}

/**
 * Ms. Protocolli's sources: every sender's file in the syslog folder (ids `router:<sender>`, the rotated one
 * `router.1:<sender>` — a sender's address may end in «.1» itself; never a path from a request) — only while the server is on and the folder's disks are awake; the files of this server's own
 * names (the loop) too, they are logs like the others. id => [path, sender].
 */
function watchnetLogFiles(array $o = []): array
{
    $cfg = watchnetConfig((string) ($o['cfg'] ?? WATCHNET_CFG));
    if (!$cfg['on']) {
        return [];
    }
    $disks = readCfg((string) ($o['disks_ini'] ?? '/var/local/emhttp/disks.ini'), true);
    $shares = readCfg((string) ($o['shares_ini'] ?? '/var/local/emhttp/shares.ini'), true);
    $b = watchnetBases((string) $cfg['folder'], $shares, $disks);
    $sleeping = watchnetSleeping($disks);
    if (!$b['known'] || array_filter($b['bases'], fn ($x) => baseAsleep($x, $sleeping))) {
        return [];
    }
    $out = [];
    foreach (watchnetFiles((string) $cfg['folder'], [])['files'] as $sender => $x) {
        foreach (['cur' => 'router', 'old' => 'router.1'] as $k => $prefix) {
            if (isset($x[$k])) {
                $out["$prefix:$sender"] = ['path' => $x[$k]['path'], 'sender' => (string) $sender, 'old' => $k === 'old'];
            }
        }
    }
    return $out;
}

/** The Consultant's router guide was opened (his page, `network_seen`): when — the Team Lead's syslog_off hint */
function watchnetGuideFile(): string
{
    return DATA_DIR . '/advisor/network.json';
}

function watchnetGuideSeen(?string $file = null): ?int
{
    $t = (int) ((readJson($file ?? watchnetGuideFile()) ?? [])['seen'] ?? 0);
    return $t > 0 ? $t : null;
}
