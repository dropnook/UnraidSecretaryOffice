<?php
// tools/scenarios/truth-facts.php — INV-TRUTH's «truth» side: what really protects each unit of this server (share, VM, app,
// the backup place), read from the server itself and the engine's own files — never through the office's code (no
// agent/ or src/ file is loaded: the page's claim comes from there, the truth must not). Runs on a test VM as root;
// prints one JSON object (tools/scenarios/truth.mjs compares the pages with it on the Mac).
//
//   php truth-facts.php [--no-kopia] [--no-partner]
//
// Per unit:
//   * local: the engine's snapshots (<snap_prefix>YYYYMMDD-HHMM, settings.ini [general] snap_prefix) on the very dataset
//     that holds it (zfs list -t snapshot; btrfs: <disk>/<btrfs_snap_dir>), each place of a share on its own (a place on
//     XFS or a disk without snapshots holds none); `all` beside: snapshots of any name there (the user's, base.sh's
//     asides — what Mr. Restori's «n local snapshots» counts); a place on a sleeping disk is never looked at (`asleep`).
//   * offsite: Kopia's own snapshot list (docker exec <container> kopia snapshot list --all --json): the source the engine
//     names for it (/uso/<share>, /uso/.apps/<f>, /uso/.vms/<f>) has a complete snapshot; for a VM's or an app's folder
//     inside a share source, `kopia ls` of the share's newest snapshot holds that folder. The engine's kopia-held.json
//     (the repository suddenly empty) and kopia-watch.json (state stuck) are its own facts, beside.
//   * partner: the partner's door asked what it holds of each unit (`list <unit>`, the pair's own key, as the engine's
//     ssh does) — the receiver's zfs, not the sender's history. Never the release canary (USOPartner, 192.168.7.190) or
//     nostromo (192.168.7.20): «not asked».
//   * born: since when a share is there at the latest (its own dataset's creation, its folders, its cfg — the oldest):
//     TR-ROW forgives a share newer than the office's last look at the shares.
// Also: Docker answers or not (docker info, 20 s), the containers; libvirt and the VMs (virsh); the engine's runs
// (status.json, history.jsonl: how many, the last backup run and its snapshot).
// Nothing here writes anything or wakes a disk.

error_reporting(E_ALL);
$opt = getopt('', ['no-kopia', 'no-partner']);
$CFG = '/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg';
$FLASH = '/boot/config/plugins/unraid-secretary-office';
$CANARY = ['192.168.7.190', '192.168.7.20'];

function sh(array $cmd, int $t = 60): array
{
    $line = 'timeout -k 5 ' . $t . ' ' . implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null';
    exec($line, $out, $rc);
    return [$rc, $out];
}
function iniValue(string $file, string $key): ?string
{
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"?([^"]*)"?\s*$/', $l, $m)) {
            return $m[1];
        }
    }
    return null;
}
/** settings.ini as [section][name][key] => list of values ('' name for plain sections) */
function engineIni(string $file): array
{
    $out = [];
    $sec = '';
    $name = '';
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || $l[0] === ';') {
            continue;
        }
        if (preg_match('/^\[([a-z]+)(?:\s+"(.*)")?\]$/', $l, $m)) {
            [$sec, $name] = [$m[1], $m[2] ?? ''];
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $l, $m)) {
            $out[$sec][$name][$m[1]][] = $m[2];
        }
    }
    return $out;
}
function one(array $ini, string $sec, string $name, string $key, ?string $def = null): ?string
{
    return $ini[$sec][$name][$key][0] ?? $def;
}
/** Unraid's ini files (disks.ini, shares.ini): [name] => [key => value] */
function unraidIni(string $file): array
{
    $out = [];
    $sec = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\["?(.*?)"?\]$/', $l, $m)) {
            $sec = $m[1];
            $out[$sec] = [];
        } elseif ($sec !== null && preg_match('/^([A-Za-z0-9_]+)="(.*)"$/', $l, $m)) {
            $out[$sec][$m[1]] = $m[2];
        }
    }
    return $out;
}

$D = rtrim(iniValue($CFG, 'DATA_DIR') ?? '/mnt/user/appdata/UnraidSecretaryOffice/data', '/');
$UB = "$D/unraid-backup";
$ini = engineIni("$UB/settings.ini");
$prefix = one($ini, 'general', '', 'snap_prefix', 'uso-backup-');
$btrfsDir = one($ini, 'general', '', 'btrfs_snap_dir', '.btrfs-snap');
$dumps = one($ini, 'general', '', 'dumps_share');
$snapRe = '/^' . preg_quote($prefix, '/') . '\d{8}-\d{4}$/';
$now = time();

// ---------------------------------------------------------------------------------------------- disks, pools, asleep
$disks = unraidIni('/var/local/emhttp/disks.ini');
$mounted = [];
foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    $f = explode(' ', $l);
    if (preg_match('#^/mnt/([^/]+)$#', $f[1] ?? '', $m)) {
        $mounted[$m[1]] = $f[2];
    }
}
$bases = [];
$asleepDisks = [];
foreach ($disks as $n => $d) {
    if (($d['spundown'] ?? '') === '1' && ($d['rotational'] ?? '') !== '0') {
        $asleepDisks[] = $n;
    }
}
foreach ($disks as $n => $d) {
    if (!in_array($d['type'] ?? '', ['Data', 'Cache'], true) || !isset($mounted[$n])) {
        continue;
    }
    $asleep = false;
    foreach ($asleepDisks as $a) {
        if (preg_match('/^disk\d+$/', $n) ? $a === $n : preg_match('/^' . preg_quote($n, '/') . '\d*$/', $a)) {
            $asleep = true;
        }
    }
    $bases[$n] = ['fs' => $mounted[$n], 'asleep' => $asleep];
}

// ------------------------------------------------------------------------------------------ the engine's snapshots
$zsnaps = [];          // dataset => [[name, creation]…]: the engine's
$zall = [];            // dataset => how many snapshots of any name (the user's, an aside, the engine's)
[$rc, $out] = sh(['zfs', 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name,creation'], 60);
foreach ($rc === 0 ? $out : [] as $l) {
    [$full, $cr] = array_pad(explode("\t", $l), 2, '0');
    [$ds, $sn] = array_pad(explode('@', $full, 2), 2, '');
    $zall[$ds] = ($zall[$ds] ?? 0) + 1;
    if (preg_match($snapRe, $sn)) {
        $zsnaps[$ds][] = [$sn, (int) $cr];
    }
}
$zfsOk = $rc === 0;
/** where a path really lies: [fs, dataset|device] (findmnt -T) */
function placeOf(string $path): array
{
    [$rc, $out] = sh(['findmnt', '-n', '-o', 'SOURCE,FSTYPE', '-T', $path], 10);
    $f = preg_split('/\s+/', trim($out[0] ?? ''));
    return [$f[1] ?? '', $f[0] ?? ''];
}
/** the engine's snapshots holding a folder that lies on <base>: {fs, ds, snaps, newest} */
function snapsOf(string $base, string $path): array
{
    global $bases, $zsnaps, $zall, $btrfsDir, $snapRe, $zfsOk;
    $fs = $bases[$base]['fs'] ?? '';
    $r = ['base' => $base, 'fs' => $fs, 'ds' => null, 'snaps' => null, 'all' => null, 'newest' => null, 'asleep' => !empty($bases[$base]['asleep'])];
    if ($r['asleep']) {
        return $r;
    }
    if ($fs === 'zfs') {
        [, $ds] = placeOf($path);
        $r['ds'] = $ds;
        if ($zfsOk) {
            $l = $zsnaps[$ds] ?? [];
            $r['snaps'] = count($l);
            $r['all'] = $zall[$ds] ?? 0;
            $r['newest'] = $l ? max(array_column($l, 0)) : null;
        }
    } elseif ($fs === 'btrfs') {
        // the engine's btrfs snapshots: <disk>/<btrfs_snap_dir>/<prefix>… subvolumes of the whole disk or pool
        $l = array_values(array_filter(array_map('basename', glob("/mnt/$base/$btrfsDir/*") ?: []), fn ($n) => preg_match($snapRe, $n)));
        $r['snaps'] = count($l);
        $r['all'] = count(glob("/mnt/$base/$btrfsDir/*") ?: []);
        $r['newest'] = $l ? max($l) : null;
    } else {
        $r['snaps'] = $r['all'] = 0;        // XFS, a disk of the array without snapshots: nothing can hold it
    }
    return $r;
}
/** a folder (/mnt/user/<share>/<rest> or /mnt/<base>/<share>/<rest>) → the places it really has (awake ones looked at) */
function folderPlaces(string $path): array
{
    global $bases;
    if (preg_match('#^/mnt/(?:user|user0)/(.+)$#', $path, $m)) {
        $rel = $m[1];
    } elseif (preg_match('#^/mnt/([^/]+)/(.+)$#', $path, $m) && isset($bases[$m[1]])) {
        $rel = $m[2];
        $only = $m[1];
    } else {
        return [];
    }
    $out = [];
    foreach ($bases as $b => $x) {
        if (isset($only) && $b !== $only) {
            continue;
        }
        if ($x['asleep']) {
            $out[] = ['base' => $b, 'fs' => $x['fs'], 'asleep' => true, 'ds' => null, 'snaps' => null, 'all' => null, 'newest' => null];
            continue;
        }
        if (file_exists("/mnt/$b/$rel")) {
            $out[] = snapsOf($b, "/mnt/$b/$rel");
        }
    }
    return $out;
}
/** local truth over places: yes (every place holds a snapshot) · partial · no · unknown (a place asleep, zfs silent) */
function localOf(array $places): string
{
    if (!$places) {
        return 'no';
    }
    $with = 0;
    $unknown = 0;
    foreach ($places as $p) {
        if ($p['snaps'] === null) {
            $unknown++;
        } elseif ($p['snaps'] > 0) {
            $with++;
        }
    }
    if ($with === count($places)) {
        return 'yes';
    }
    if ($unknown && $with + $unknown === count($places)) {
        return 'unknown';
    }
    return $with ? 'partial' : ($unknown ? 'unknown' : 'no');
}

// --------------------------------------------------------------------------------------------------- the engine's runs
$hist = [];
foreach (array_slice(@file("$UB/state/history.jsonl", FILE_IGNORE_NEW_LINES) ?: [], -300) as $l) {
    $j = json_decode($l, true);
    if (is_array($j)) {
        $hist[] = $j;
    }
}
$status = json_decode((string) @file_get_contents("$UB/state/status.json"), true);
$lastBackup = null;
foreach (array_reverse($hist) as $h) {
    if (in_array($h['mode'] ?? '', ['backup', 'nokopia'], true)) {
        $lastBackup = $h;
        break;
    }
}
$runs = ['n' => count(array_filter($hist, fn ($h) => in_array($h['mode'] ?? '', ['backup', 'nokopia'], true))),
    'last' => $lastBackup === null ? null : ['run' => $lastBackup['run'] ?? null, 'mode' => $lastBackup['mode'] ?? null,
        'result' => $lastBackup['result'] ?? null, 'snapshot' => $lastBackup['snapshot'] ?? null, 'started' => $lastBackup['started'] ?? null],
    'running' => is_array($status) && ($status['result'] ?? '') === 'running'];

// ------------------------------------------------------------------------------------------------------------- Docker
[$rc, $out] = sh(['docker', 'info', '--format', '{{.ServerVersion}}'], 20);
$dockerUp = $rc === 0 && trim($out[0] ?? '') !== '';
$cts = [];
if ($dockerUp) {
    [$rc, $ids] = sh(['docker', 'ps', '-aq'], 20);
    if ($rc === 0 && $ids) {
        [$rc, $out] = sh(array_merge(['docker', 'inspect'], $ids), 30);
        foreach (json_decode(implode("\n", $out), true) ?: [] as $c) {
            $binds = [];
            foreach ($c['Mounts'] ?? [] as $m) {
                if (($m['Type'] ?? '') === 'bind') {
                    $binds[] = $m['Source'];
                }
            }
            $cts[ltrim($c['Name'] ?? '', '/')] = ['state' => $c['State']['Status'] ?? '', 'binds' => $binds,
                'project' => $c['Config']['Labels']['com.docker.compose.project'] ?? null];
        }
    }
}
$docker = ['answers' => $dockerUp, 'enabled' => iniValue('/boot/config/docker.cfg', 'DOCKER_ENABLED') === 'yes',
    'containers' => count($cts), 'running' => count(array_filter($cts, fn ($c) => $c['state'] === 'running'))];

// -------------------------------------------------------------------------------------------------------------- Kopia
$kc = one($ini, 'kopia', '', 'container', 'kopia');
$kopia = ['enabled' => one($ini, 'kopia', '', 'enabled', 'no') === 'yes', 'container' => $kc, 'up' => isset($cts[$kc]) && $cts[$kc]['state'] === 'running',
    'held' => is_file("$UB/state/kopia-held.json"), 'stuck' => null, 'asked' => false, 'sources' => []];
$kw = json_decode((string) @file_get_contents("$UB/state/kopia-watch.json"), true);
$kopia['stuck'] = is_array($kw) ? ($kw['state'] ?? null) === 'stuck' : null;
$kopiaLs = [];
if ($kopia['up'] && !isset($opt['no-kopia'])) {
    [$rc, $out] = sh(['docker', 'exec', $kc, 'kopia', 'snapshot', 'list', '--all', '--json'], 120);
    $list = $rc === 0 ? json_decode(implode("\n", $out), true) : null;
    if (is_array($list)) {
        $kopia['asked'] = true;
        foreach ($list as $s) {
            $p = $s['source']['path'] ?? '';
            if ($p === '' || !empty($s['incompleteReason'])) {
                continue;
            }
            $t = strtotime($s['startTime'] ?? '') ?: 0;
            $x = &$kopia['sources'][$p];
            $x['n'] = ($x['n'] ?? 0) + 1;
            if ($t >= ($x['last'] ?? 0)) {
                $x['last'] = $t;
                $x['obj'] = $s['rootEntry']['obj'] ?? null;
            }
            unset($x);
        }
    }
}
/** the top-level names of a folder in a Kopia snapshot (null: couldn't ask) */
function kopiaLs(string $obj): ?array
{
    global $kc, $kopiaLs;
    if (!array_key_exists($obj, $kopiaLs)) {
        [$rc, $out] = sh(['docker', 'exec', $kc, 'kopia', 'ls', $obj], 60);
        $kopiaLs[$obj] = $rc === 0 ? array_values(array_filter(array_map(fn ($l) => rtrim(trim($l), '/'), $out), 'strlen')) : null;
    }
    return $kopiaLs[$obj];
}
/** is <share>/<rel> in the newest Kopia snapshot of its share's source, non-empty? (null: couldn't ask) */
function kopiaHolds(string $share, string $rel): ?bool
{
    global $kopia;
    $src = $kopia['sources']["/uso/$share"] ?? null;
    if (!$src || !$src['obj']) {
        return false;
    }
    $obj = $src['obj'];
    foreach (array_filter(explode('/', $rel), 'strlen') as $part) {
        $ls = kopiaLs($obj);
        if ($ls === null) {
            return null;
        }
        if (!in_array($part, $ls, true)) {
            return false;
        }
        $obj .= '/' . $part;
    }
    $ls = kopiaLs($obj);
    return $ls === null ? null : count($ls) > 0;
}
/** the engine's safe folder name (pkg_folder): what isn't [A-Za-z0-9._-] becomes _ */
function pkgFolder(string $n): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $n);
}

// ------------------------------------------------------------------------------------------------------------- shares
/**
 * Since when a share is on the server at the latest: the oldest of its own datasets' `creation` (<base>/<share>), its
 * awake places' folders (birth time where the file system keeps one, else the older of mtime and ctime) and its cfg on
 * the flash — the oldest trace wins (an older one keeps TR-ROW red). → [time|null, what said it]
 */
function shareBorn(string $s, array $places): array
{
    $t = [];
    foreach ($places as $p) {
        if (!empty($p['asleep'])) {
            continue;
        }
        if ($p['fs'] === 'zfs' && $p['ds'] === "{$p['base']}/$s") {
            [$rc, $out] = sh(['zfs', 'get', '-H', '-p', '-o', 'value', 'creation', $p['ds']], 10);
            if ($rc === 0 && ctype_digit(trim($out[0] ?? ''))) {
                $t["zfs creation {$p['ds']}"] = (int) trim($out[0]);
                continue;
            }
        }
        [$rc, $out] = sh(['stat', '-c', '%W %Y %Z', "/mnt/{$p['base']}/$s"], 10);
        $f = array_map('intval', preg_split('/\s+/', trim($out[0] ?? '')));
        if ($rc === 0 && count($f) === 3) {
            $t["/mnt/{$p['base']}/$s " . ($f[0] > 0 ? 'birth' : 'mtime/ctime')] = $f[0] > 0 ? $f[0] : min($f[1], $f[2]);
        }
    }
    $cfg = "/boot/config/shares/$s.cfg";
    if (is_file($cfg)) {
        $t["$cfg mtime"] = (int) filemtime($cfg);
    }
    $t = array_filter($t, fn ($x) => $x > 0);
    if (!$t) {
        return [null, null];
    }
    asort($t);
    return [reset($t), key($t)];
}

$shini = unraidIni('/var/local/emhttp/shares.ini');
$shares = [];
foreach (array_keys($shini) as $s) {
    $places = folderPlaces("/mnt/user/$s");
    $src = $kopia['sources']["/uso/$s"] ?? null;
    [$born, $bornBy] = shareBorn($s, $places);
    $shares[$s] = ['set' => one($ini, 'share', $s, 'mode'), 'places' => $places, 'local' => localOf($places),
        'kopia' => $src ? ['n' => $src['n'], 'last' => $src['last']] : null,
        'partner_set' => $ini['share'][$s]['partner'] ?? [], 'born' => $born, 'born_by' => $bornBy];
}

// --------------------------------------------------------------------------------------------------------------- VMs
$vms = [];
[$rc, $out] = sh(['virsh', 'list', '--all', '--name'], 20);
$libvirt = $rc === 0;
foreach ($libvirt ? array_filter(array_map('trim', $out), 'strlen') : [] as $v) {
    [, $st] = sh(['virsh', 'domstate', $v], 10);
    [, $bl] = sh(['virsh', 'domblklist', '--details', $v], 10);
    $disks = [];
    foreach ($bl as $l) {
        $f = preg_split('/\s+/', trim($l));
        if (count($f) >= 4 && $f[1] === 'disk' && str_starts_with($f[3], '/mnt/')) {
            $path = $f[3];
            $share = preg_match('#^/mnt/[^/]+/([^/]+)/(.+)$#', $path, $m) ? $m[1] : null;
            $rel = $share !== null ? (dirname($m[2]) === '.' ? '' : dirname($m[2])) : '';
            $places = folderPlaces($path);
            $own = null;
            foreach ($places as $p) {
                if ($p['ds'] !== null && $share !== null) {
                    $own = !preg_match('#/' . preg_quote($share, '#') . '$#', $p['ds']);   // a child dataset of the share's, or another
                }
            }
            $off = null;
            if ($kopia['asked'] && $share !== null) {
                $off = isset($kopia['sources']['/uso/.vms/' . pkgFolder($v)]) ? true : kopiaHolds($share, $rel);
            }
            $disks[] = ['path' => $path, 'share' => $share, 'folder' => $rel, 'places' => $places, 'own_dataset' => $own,
                'local' => localOf($places), 'offsite' => $off];
        }
    }
    $loc = array_column($disks, 'local');
    $vms[$v] = ['state' => trim($st[0] ?? ''), 'disks' => $disks,
        'local' => !$disks ? 'no' : (count(array_unique($loc)) === 1 ? $loc[0] : (in_array('yes', $loc, true) || in_array('partial', $loc, true) ? 'partial' : 'unknown')),
        'offsite' => !$disks ? false : (in_array(false, array_column($disks, 'offsite'), true) ? false : (in_array(null, array_column($disks, 'offsite'), true) ? null : true)),
        'set' => one($ini, 'vm', $v, 'mode'), 'prepare' => one($ini, 'vm', $v, 'prepare'), 'partner_set' => $ini['vm'][$v]['partner'] ?? []];
}

// --------------------------------------------------------------------------------------------------------------- apps
// an app = a compose project (all its containers) or a single container; its folders = its binds under a share
$apps = [];
foreach ($cts as $name => $c) {
    $app = $c['project'] ?: $name;
    $apps[$app]['containers'][] = $name;
    foreach ($c['binds'] as $b) {
        if (!preg_match('#^/mnt/(?:user|user0|[^/]+)/([^/]+)/(.+)$#', $b, $m) || str_starts_with($b, '/mnt/addons/')) {
            continue;
        }
        $apps[$app]['binds'][$b] = [$m[1], $m[2]];
    }
}
foreach ($apps as $app => &$a) {
    $folders = [];
    foreach ($a['binds'] ?? [] as $path => [$share, $rel]) {
        if ($share !== 'appdata') {
            continue;           // its data in another share (media, photos): the share's row says that
        }
        $top = explode('/', $rel)[0];
        $places = folderPlaces("/mnt/user/appdata/$top");
        $own = null;
        foreach ($places as $p) {
            if ($p['ds'] !== null) {
                $own = !preg_match('#/appdata$#', $p['ds']);
            }
        }
        $off = null;
        if ($kopia['asked']) {
            $off = isset($kopia['sources']['/uso/.apps/' . pkgFolder($app)]) ? true : kopiaHolds('appdata', $top);
        }
        $folders["appdata/$top"] = ['share' => 'appdata', 'folder' => $top, 'places' => $places, 'own_dataset' => $own,
            'local' => localOf($places), 'offsite' => $off];
    }
    $a = ['containers' => $a['containers'], 'folders' => array_values($folders),
        'local' => !$folders ? null : (count(array_unique(array_column($folders, 'local'))) === 1 ? array_values($folders)[0]['local'] : 'partial'),
        'offsite' => !$folders ? null : (in_array(false, array_column($folders, 'offsite'), true) ? false : (in_array(null, array_column($folders, 'offsite'), true) ? null : true)),
        'partner_set' => $ini['app'][$app]['partner'] ?? []];
}
unset($a);

// ----------------------------------------------------------------------------------------------------------- partners
// the pairs this office sends to (pairs.json, a pair with my_key), the units that name them (settings.ini partner keys,
// the pair's agreed units), what the partner's door holds of each
$partners = [];
$pairs = json_decode((string) @file_get_contents("$D/partner/pairs.json"), true);
foreach (is_array($pairs['pairs'] ?? null) ? $pairs['pairs'] : [] as $p) {
    if (!is_string($p['my_key'] ?? null) || !preg_match('/^[0-9a-f]{8}$/', $p['id'] ?? '')) {
        continue;
    }
    $id = $p['id'];
    $units = [];
    foreach (['share', 'vm', 'app'] as $k) {
        foreach ($ini[$k] ?? [] as $n => $keys) {
            if (in_array($id, $keys['partner'] ?? [], true)) {
                $units[] = "$k:$n";
            }
        }
    }
    foreach ($p['send']['units'] ?? [] as $u) {
        $units[] = $u;
    }
    if ($dumps !== null && isset($ini['share'][$dumps]) && in_array($id, $ini['share'][$dumps]['partner'] ?? [], true)) {
        $units[] = 'place';
    }
    $units = array_values(array_unique($units));
    $one = ['id' => $id, 'name' => $p['name'] ?? $id, 'address' => $p['address'] ?? '', 'asked' => false, 'why' => null, 'units' => []];
    if (in_array($p['address'] ?? '', $CANARY, true)) {
        $one['why'] = 'canary';
    } elseif (isset($opt['no-partner'])) {
        $one['why'] = 'not asked';
    } elseif (!is_file("$FLASH/partners/$id.key")) {
        $one['why'] = 'no key';
    } else {
        $one['asked'] = true;
        foreach ($units as $u) {
            [$rc, $out] = sh(['ssh', '-i', "$FLASH/partners/$id.key", '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
                '-o', "UserKnownHostsFile=$FLASH/partners/$id.known", '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10',
                '-p', (string) ($p['port'] ?? 22), 'root@' . $p['address'], "list $u"], 30);
            $j = null;
            foreach ($out as $l) {
                $j = json_decode($l, true);
                if (is_array($j)) {
                    break;
                }
            }
            $one['units'][$u] = is_array($j) && ($j['ok'] ?? false) === true
                ? ['n' => count($j['snaps'] ?? []), 'newest' => ($j['snaps'] ?? []) ? max(array_column($j['snaps'], 'name')) : null]
                : ['n' => null, 'error' => is_array($j) ? ($j['error'] ?? 'refused') : "no answer (ssh $rc)"];
        }
    }
    $partners[] = $one;
}

echo json_encode(['v' => 1, 'time' => $now, 'host' => gethostname(), 'prefix' => $prefix, 'dumps_share' => $dumps,
    'settings' => is_file("$UB/settings.ini"), 'runs' => $runs, 'docker' => $docker, 'libvirt' => $libvirt, 'kopia' => array_merge($kopia,
    ['sources' => array_map(fn ($x) => ['n' => $x['n'], 'last' => $x['last']], $kopia['sources'])]),
    'shares' => $shares, 'vms' => $vms, 'apps' => $apps, 'partners' => $partners, 'zfs' => $zfsOk,
    'bases' => $bases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
