<?php
declare(strict_types=1);

/*
 * Mr. Restori — brings back what is gone, from what Mr. Backupsy's engine keeps:
 *
 *   packages   the backup place (backupDumpsPath()): per app apps/<app>/ (templates or compose
 *              files, database dumps, a media server's database copies, docker inspect of its
 *              containers) and per VM vms/<vm>/ (XML, NVRAM, TPM state), each with a
 *              manifest.json (engine 2.18+). Older nights' packages lie in the snapshots of the
 *              backup place's own share.
 *   snapshots  the local ZFS and btrfs snapshots holding an app's or a VM's folders: ZFS
 *              <dataset mountpoint>/.zfs/snapshot/<snap>/…, btrfs /mnt/<disk>/<btrfs_snap_dir>/<snap>/<share>/…
 *   Kopia      the offsite copies: per share, and per app or VM with a source of its own
 *              (<container path>/.apps/<app>, .vms/<vm>)
 *
 * His page is organised by app and VM: what can come back from where, with dates. He reads
 * his own packages (rsPackages) — desks know each other only through shared libraries.
 *
 * Never on a sleeping disk: a share whose disk sleeps is not looked into (its snapshots are
 * named "asleep"), the backup place on a sleeping disk keeps what was read before.
 *
 * And he brings things back himself (steps 2-6): a database from its dump, a media server's
 * database copies, templates and compose files, a folder from a local snapshot, a VM's
 * configuration, a Kopia snapshot into a writable folder. Every restore is planned here against
 * a fresh look at the server (rsPlan), shown as a preview, confirmed with the plan's token and
 * handed to the host's atd as "php agent.php job restore <id>" (rsJob) — never a child of the
 * agent. The job holds the engine's lock (state/lock: one restore at a time, no backup, check
 * or setup meanwhile — a backup run then is skipped visibly, engine 2.20) and notes itself in
 * state/lock-holder.json. Every step goes into data/restore/<id>/journal.json (+ log.txt), the
 * page polls data/restore-job.json. What a restore replaces is put aside first, never deleted;
 * «Put back» undoes a finished or failed restore from its journal, as a job of its own.
 */

const RS_TEMPLATES    = '/boot/config/plugins/dockerMan/templates-user';
const RS_COMPOSE_CFG  = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const RS_COMPOSE_DEF  = '/boot/config/plugins/compose.manager/projects';
const RS_LIBVIRT      = '/etc/libvirt';
const RS_SNAPS_MAX    = 60;          // snapshots kept per place (newest first)
const RS_NOT_BASES    = ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'];
const RS_DB_TYPES     = ['mariadb', 'postgres', 'mongodb'];
const RS_UUID         = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

// restoring
const RS_ID_PATTERN   = '/^\d{8}-\d{6}-[0-9a-f]{4}$/D';      // a restore: <time>-<random>
const RS_STAMP        = '/^\d{8}-\d{6}$/D';                   // the time in what he puts aside: <folder>.aside-<stamp>
const RS_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D';   // apps (package folders), containers, databases
// the only variables a command in a database container may name (credentials stay in the container, never here)
const RS_ENV_VARS     = ['MARIADB_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MARIADB_USER', 'MARIADB_PASSWORD', 'MYSQL_USER', 'MYSQL_PASSWORD',
                         'POSTGRES_USER', 'POSTGRES_PASSWORD', 'MONGO_INITDB_ROOT_USERNAME', 'MONGO_INITDB_ROOT_PASSWORD'];
const RS_FLASH_ASIDE  = '/boot/config/_UnraidSecretaryOffice-restore';   // templates and compose files he replaces (flash)
const RS_LIBVIRT_ASIDE = '_UnraidSecretaryOffice-restore';               // a VM's configuration he replaces, inside /etc/libvirt
const RS_KEEP         = 60;          // journals listed (the folders stay)
const RS_DU_PARALLEL  = 2;
// a share's parts and moments (several pools or disks, snapshots taken on all at once — or only on some)
const RS_SHARES_INI   = '/var/local/emhttp/shares.ini';   // emhttp's shares: which exist, exclusive or not
const RS_DISKS_INI    = '/var/local/emhttp/disks.ini';    // free space per pool and disk, read without touching a disk
const RS_MOMENTS_MAX  = 80;          // moments offered for one folder or share
const RS_HOLDS_CHECK  = 24;          // moments looked into (does it hold the folder?) — each look mounts a ZFS snapshot
const RS_ENTRIES_MAX  = 60;          // entries at the top of a share he brings back at once
const RS_STOREROOM    = '_UnraidSecretaryOffice-trash';   // Ms. Dustdevil's: never a share's own folder
const RS_OWN_LEFTOVER = '/\.(restored|aside|putback)-\d{8}-\d{6}$/D';   // what he left next to a folder himself
const RS_WAKE_TIMEOUT = 90;          // seconds a sleeping disk gets to spin up when «wake» is ticked

define('RS_DATA', DATA_DIR . '/restore');
define('RS_JOB_FILE', DATA_DIR . '/restore-job.json');       // the running (or last) restore, polled by the page (api part "job")
define('RS_SIZES_FILE', DATA_DIR . '/restore-sizes.json');   // sizes measured in the background (api part "sizes")

$GLOBALS['rs'] = ['state' => null, 'du' => ['queue' => [], 'running' => []]];

function rsSizesFile(): string
{
    return $GLOBALS['rs']['sizes_file'] ?? RS_SIZES_FILE;
}

/** Where his restores, the job file and the engine's lock live — tests point them to a temporary folder */
function rsData(): string
{
    return $GLOBALS['rs']['data'] ?? RS_DATA;
}

function rsJobFile(): string
{
    return $GLOBALS['rs']['job_file'] ?? RS_JOB_FILE;
}

function rsUbData(): string
{
    return $GLOBALS['rs']['ub_data'] ?? BACKUP_DATA_DIR;
}

desk('restore', [
    'fit'     => fn (): array => rsFit(),
    'start'   => function (): void {
        $GLOBALS['rs']['state'] = readJson(deskFile('restore'));
        rsScan();
    },
    'tick'    => fn () => rsDuTick(),
    'actions' => [
        'refresh'    => fn (array $r) => ['ok' => true, 'state' => rsScan()],
        'versions'   => fn (array $r) => rsVersions(textField($r, 'kind'), textField($r, 'id')),
        'preview'    => fn (array $r) => ['ok' => true, 'preview' => rsPlan($r)],
        'start'      => fn (array $r) => rsStart($r),
        'journal'    => fn (array $r) => rsJournalGet(textField($r, 'id')),
        'kopia_list' => fn (array $r) => rsKopiaList(textField($r, 'source')),
    ],
    'jobs'    => ['restore' => fn (array $args) => rsJob($args)],
]);

// ===================================================================== the server, once per look

/**
 * What every look needs: the file system of each pool and disk, the ZFS datasets with their
 * mountpoints and snapshots, which disks sleep, the engine's settings. One zfs call each.
 */
function rsContext(array $settings): array
{
    $fs = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !in_array($x[1], RS_NOT_BASES, true)) {
            $fs[$x[1]] = $m['fs'];
        }
    }
    $mounts = [];
    $snaps = [];
    if (in_array('zfs', $fs, true) && bin('zfs')) {
        $r = runAll([
            'ds'    => ['zfs', 'list', '-H', '-o', 'name,mountpoint', '-t', 'filesystem'],
            'snaps' => ['zfs', 'list', '-Hp', '-t', 'snapshot', '-o', 'name,creation', '-s', 'creation'],
        ], 60);
        foreach (rows($r['ds'][1]) as $f) {
            if (count($f) >= 2 && str_starts_with($f[1], '/mnt/')) {
                $mounts[rtrim($f[1], '/')] = $f[0];
            }
        }
        foreach (rows($r['snaps'][1]) as $f) {
            if (count($f) >= 2 && str_contains($f[0], '@')) {
                [$ds, $name] = explode('@', $f[0], 2);
                $snaps[$ds][] = ['name' => $name, 'time' => num($f[1])];
            }
        }
    }
    return [
        'fs'        => $fs,
        'zfs'       => $mounts,                 // mountpoint => dataset
        'snaps'     => $snaps,                  // dataset => [{name, time}] oldest first
        'asleep'    => sleepingDisks(),
        'prefixes'  => backupSnapPrefixes(backupSetting($settings, 'general', 'snap_prefix')),   // the engine's, old names too
        'btrfs_dir' => (string) backupSetting($settings, 'general', 'btrfs_snap_dir', '.btrfs-snap'),
        'settings'  => $settings,
        'cfg'       => [],
        'mnt'       => '/mnt',                  // where the pools and disks are mounted (tests: a temporary folder)
        'user'      => '/mnt/user',             // the user shares (shfs): where restores go, so Unraid places them
        'disks'     => readCfg(RS_DISKS_INI, true),
        'shares_ini' => readCfg(RS_SHARES_INI, true) ?: null,   // null: unknown (then the flash and the folders tell)
        'old_shares' => null,                   // <backup place>/server/shares: the shares' settings as the last run found them
        'now'       => [],                      // rsShareNow() per share, once per look
    ];
}

/**
 * A share's settings as Unraid's cfg keeps them, in words the page can show: primary and secondary storage (a pool's
 * name or "array"), which way the mover goes, allocation, split level, minimum free space, disks, exports — and which of
 * the named pools this server doesn't have. Values are only taken in a plain shape (the cfg may come from a package).
 */
function rsShareSettings(array $cfg, array $ctx): array
{
    $word = fn (string $k, string $re = '/^[\w.-]{1,64}$/D'): string => preg_match($re, (string) ($cfg[$k] ?? '')) ? (string) $cfg[$k] : '';
    $p1 = $word('shareCachePool') ?: 'cache';
    $p2 = $word('shareCachePool2') ?: 'array';
    [$primary, $secondary, $mover] = match ($word('shareUseCache')) {
        'only'   => [$p1, null, null],
        'yes'    => [$p1, $p2, 'to_secondary'],
        'prefer' => [$p1, $p2, 'to_primary'],
        default  => ['array', null, null],
    };
    $list = fn (string $k): array => array_values(array_filter(array_map('trim', explode(',', $word($k, '/^[\w.,\s-]{0,400}$/D'))), fn ($x) => $x !== ''));
    $split = $word('shareSplitLevel', '/^\d{0,2}$/D');
    $floor = $word('shareFloor', '/^\d{1,15}$/D');
    return [
        'primary'   => $primary,
        'secondary' => $secondary,
        'mover'     => $mover,
        'allocator' => $word('shareAllocator') ?: 'highwater',
        'split'     => $split === '' ? 'any' : ($split === '0' ? 'manual' : $split),
        'floor'     => $floor !== '' ? (int) $floor * 1024 : null,          // Unraid keeps KB
        'include'   => $list('shareInclude'),
        'exclude'   => $list('shareExclude'),
        'smb'       => ['e' => 'yes', 'eh' => 'hidden'][$word('shareExport')] ?? 'no',
        'security'  => in_array($word('shareSecurity'), ['public', 'secure', 'private'], true) ? $word('shareSecurity') : 'public',
        'nfs'       => $word('shareExportNFS') === 'e' ? 'yes' : 'no',
        'missing_pools' => array_values(array_filter([$primary, $secondary], fn ($p) => $p !== null && $p !== 'array' && !isset($ctx['fs'][$p]))),
    ];
}

/** A share's settings as the last backup run found them (the package's server/shares/<share>.cfg), or null */
function rsShareOld(string $share, array $ctx): ?array
{
    $dir = $ctx['old_shares'] ?? null;
    if (!is_string($dir) || !preg_match('/^[\w .-]+$/D', $share)) {
        return null;
    }
    $file = "$dir/$share.cfg";
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file) || (int) @filesize($file) > 65536) {
        return null;
    }
    return rsShareSettings(readCfg($file), $ctx);
}

/** Does a folder hold anything (Ms. Dustdevil's storeroom doesn't count)? Read only until the first entry */
function rsDirHasEntries(string $dir): bool
{
    $h = is_dir($dir) ? @opendir($dir) : false;
    if (!$h) {
        return false;
    }
    try {
        while (($n = readdir($h)) !== false) {
            if ($n !== '.' && $n !== '..' && $n !== '.zfs' && $n !== RS_STOREROOM) {      // .zfs: a snapshot folder made visible
                return true;
            }
        }
        return false;
    } finally {
        closedir($h);
    }
}

/**
 * Is a share there, and does it hold anything — from emhttp's list of shares and a look at the top folder on
 * each of its awake pools and disks (never through /mnt/user, never on a sleeping disk):
 *   data | empty | missing | unknown (nothing on the awake parts, but some sleep).
 * A missing one carries its settings from the package, as information: Mr. Restori never creates a share.
 */
function rsShareNow(string $share, array &$ctx): array
{
    if (isset($ctx['now'][$share])) {
        return $ctx['now'][$share];
    }
    $mnt = $ctx['mnt'] ?? '/mnt';
    $ini = $ctx['shares_ini'] ?? null;
    if (is_array($ini)) {
        $exists = isset($ini[$share]);
    } else {
        $exists = is_file("/boot/config/shares/$share.cfg");
        foreach (array_keys($ctx['fs']) as $base) {
            $exists = $exists || (!baseAsleep((string) $base, $ctx['asleep'] ?? []) && is_dir("$mnt/$base/$share"));
        }
    }
    $out = ['share' => $share, 'state' => 'missing', 'exclusive' => ($ini[$share]['exclusive'] ?? '') === 'yes', 'asleep' => [], 'old' => null, 'old_known' => false];
    if (!$exists) {
        $out['old'] = rsShareOld($share, $ctx);
        $out['old_known'] = is_string($ctx['old_shares'] ?? null);
        return $ctx['now'][$share] = $out;
    }
    $data = false;
    foreach (rsShareBases($share, $ctx) as $base) {
        if (baseAsleep($base, $ctx['asleep'] ?? [])) {
            $out['asleep'][] = $base;
        } elseif (!$data && rsDirHasEntries("$mnt/$base/$share")) {
            $data = true;
        }
    }
    $out['state'] = $data ? 'data' : ($out['asleep'] ? 'unknown' : 'empty');
    return $ctx['now'][$share] = $out;
}

/**
 * Where Unraid puts something new in a share, and how much room is there: its primary storage, then the
 * secondary one (an exclusive share: its pool only) — the free space emhttp keeps per pool and disk
 * (disks.ini, no disk touched), less the share's minimum free space. Bytes, null when not known.
 */
function rsShareSpace(string $share, array &$ctx): array
{
    $set = rsShareSettings(rsShareCfg($share, $ctx), $ctx);
    $now = rsShareNow($share, $ctx);
    $floor = $set['floor'] ?? 0;
    $free = function (string $name) use ($ctx, $set, $floor): ?int {
        $names = [$name];
        if ($name === 'array') {
            $names = [];
            foreach ($ctx['disks'] ?? [] as $sec => $d) {
                $n = (string) ($d['name'] ?? $sec);
                if (preg_match('/^disk\d+$/D', $n) && (!$set['include'] || in_array($n, $set['include'], true)) && !in_array($n, $set['exclude'], true)) {
                    $names[] = $n;
                }
            }
        }
        $sum = null;
        foreach ($names as $n) {
            $kb = $ctx['disks'][$n]['fsFree'] ?? null;
            if (is_string($kb) && ctype_digit($kb)) {
                $sum = ($sum ?? 0) + max(0, (int) $kb * 1024 - $floor);
            }
        }
        return $sum;
    };
    $secondary = $now['exclusive'] ? null : $set['secondary'];
    $a1 = $free($set['primary']);
    $a2 = $secondary !== null ? $free($secondary) : null;
    return ['primary' => $set['primary'], 'secondary' => $secondary, 'primary_free' => $a1, 'secondary_free' => $a2,
            'free' => $a1 === null && $a2 === null ? null : (int) ($a1 ?? 0) + (int) ($a2 ?? 0)];
}

/** Unraid's settings of a share (on the flash) */
function rsShareCfg(string $share, array &$ctx): array
{
    if (!preg_match('/^[\w .-]+$/', $share)) {
        return [];
    }
    return $ctx['cfg'][$share] ??= readCfg("/boot/config/shares/$share.cfg");
}

/**
 * The pools and disks a share may lie on: its pools, the array disks it may use (Unraid's share
 * config), and what the engine noted at the setup — looked up, never read from the disks. The
 * primary storage first: shfs shows its copy when a name lies on two of them, and so does a restore.
 */
function rsShareBases(string $share, array &$ctx): array
{
    $bases = [];
    $cfg = rsShareCfg($share, $ctx);
    foreach (['shareCachePool', 'shareCachePool2'] as $k) {
        if (($cfg[$k] ?? '') !== '' && ($cfg['shareUseCache'] ?? 'no') !== 'no') {
            $bases[] = $cfg[$k];
        }
    }
    // the array: primary storage (no cache) or secondary — not when the share is pool only or its secondary is a pool
    $use = $cfg['shareUseCache'] ?? 'no';
    if (!$cfg || $use === 'no' || (in_array($use, ['yes', 'prefer'], true) && ($cfg['shareCachePool2'] ?? '') === '')) {
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $exclude = array_filter(array_map('trim', explode(',', (string) ($cfg['shareExclude'] ?? ''))));
        $disks = $include ?: array_filter(array_keys($ctx['fs']), fn ($d) => preg_match('/^disk\d+$/', (string) $d));
        $bases = array_merge($bases, array_diff($disks, $exclude));
    }
    $bases = array_merge($bases, array_filter(array_map('trim', explode(',', (string) backupSetting($ctx['settings'], "share|$share", 'locations', '')))));
    return array_values(array_unique(array_filter($bases, fn ($b) => isset($ctx['fs'][$b]))));
}

/**
 * Wakes sleeping pools and disks — only ever because the user ticked «wake»: one block read straight from each of
 * their disks (a pool: all of them, cache, cache2 …), in parallel, waiting until each answers (≤ RS_WAKE_TIMEOUT s),
 * the way the other desks wake disks. Unraid's bookkeeping lags behind: the disks that answered are marked awake in
 * $ctx['asleep']; one that didn't stays asleep. A base without a known device is left to the read that follows.
 *
 * @return array{woken: list<string>, failed: list<string>}  the bases
 */
function rsWake(array $bases, array &$ctx): array
{
    $out = ['woken' => [], 'failed' => []];
    $disksOf = [];
    $commands = [];
    foreach ($bases as $base) {
        $base = (string) $base;
        $disksOf[$base] = [];
        foreach ($ctx['disks'] ?? [] as $sec => $d) {
            $name = (string) ($d['name'] ?? $sec);
            $mine = preg_match('/^disk\d+$/D', $base) ? $name === $base : (bool) preg_match('/^' . preg_quote($base, '/') . '\d*$/D', $name);
            $dev = (string) ($d['device'] ?? '');
            if ($mine && preg_match('/^[a-z0-9]{1,32}$/D', $dev) && file_exists("/dev/$dev")) {
                $disksOf[$base][] = $name;
                $commands[$name] = ['dd', "if=/dev/$dev", 'of=/dev/null', 'bs=4096', 'count=1', 'iflag=direct'];
            }
        }
    }
    $results = !$commands ? [] : (isset($GLOBALS['rs']['wake_run']) ? ($GLOBALS['rs']['wake_run'])($commands) : runAll($commands, RS_WAKE_TIMEOUT));
    foreach ($disksOf as $base => $disks) {
        $ok = !array_filter($disks, fn ($n) => ($results[$n][0] ?? 1) !== 0);
        $out[$ok ? 'woken' : 'failed'][] = $base;
        if ($ok) {
            foreach (array_keys($ctx['asleep'] ?? []) as $n) {
                if ($n === $base || (!preg_match('/^disk\d+$/D', $base) && preg_match('/^' . preg_quote($base, '/') . '\d*$/D', (string) $n))) {
                    $ctx['asleep'][$n] = false;
                }
            }
        }
    }
    if ($commands && !isset($GLOBALS['rs']['wake_run'])) {
        logLine('Mr. Restori woke ' . implode(', ', $bases) . ' («wake» ticked) to read its snapshots'
            . ($out['failed'] ? ' — did not answer: ' . implode(', ', $out['failed']) : ''));
    }
    return $out;
}

/** A path under /mnt as [share, rel] (/mnt/user/<share>/<rel> or /mnt/<pool or disk>/<share>/<rel>), null otherwise */
function rsSharePath(string $path, array $ctx): ?array
{
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', rtrim($path, '/'), $m)) {
        return null;
    }
    if (!in_array($m[1], ['user', 'user0'], true) && !isset($ctx['fs'][$m[1]])) {
        return null;
    }
    $rel = trim($m[3] ?? '', '/');
    if ($m[2] === '' || $m[2][0] === '.' || str_contains("/$rel/", '/../') || str_contains("/$rel/", '/./')) {
        return null;
    }
    return [$m[2], $rel, in_array($m[1], ['user', 'user0'], true) ? null : $m[1]];
}

/**
 * Where a share path really lies and which local snapshots hold it: per pool or disk the live
 * path (/mnt/<base>/<share>/<rel>), whether it is there, and its snapshots, newest first. ZFS:
 * the dataset holding it (a folder may be a dataset of its own; datasets inside it are named —
 * a copy from the parent's snapshot would miss them); btrfs: the disk's snapshot folders that
 * hold it. A sleeping base is named, never looked into.
 */
function rsLocate(string $path, array &$ctx): array
{
    $sp = rsSharePath($path, $ctx);
    if (!$sp) {
        return [];
    }
    [$share, $rel, $only] = $sp;
    $mnt = $ctx['mnt'] ?? '/mnt';
    $bases = $only !== null ? [$only] : rsShareBases($share, $ctx);
    $places = [];
    foreach ($bases as $base) {
        $live = "$mnt/$base/$share" . ($rel !== '' ? "/$rel" : '');
        $p = ['base' => $base, 'fs' => $ctx['fs'][$base] ?? '', 'live' => $live, 'asleep' => baseAsleep($base, $ctx['asleep']),
              'exists' => false, 'dataset' => null, 'own_dataset' => false, 'inner' => [], 'snaps' => []];
        if ($p['asleep']) {
            $places[] = $p;
            continue;
        }
        clearstatcache(true, $live);
        $p['exists'] = file_exists($live) || is_link($live);
        if (!$p['exists'] && !is_dir("$mnt/$base/$share")) {
            continue;                                   // the share doesn't lie there at all
        }
        if ($p['fs'] === 'zfs') {
            $best = null;
            foreach ($ctx['zfs'] as $mp => $ds) {
                if (under($live, $mp) && ($best === null || strlen($mp) > strlen($best))) {
                    $best = $mp;
                }
            }
            if ($best !== null) {
                $ds = $ctx['zfs'][$best];
                $p['dataset'] = $ds;
                $p['own_dataset'] = $best === $live;
                foreach ($ctx['zfs'] as $mp => $child) {
                    if ($mp !== $live && under($mp, $live)) {
                        $p['inner'][] = $child;
                    }
                }
                $inside = substr($live, strlen($best));
                foreach (array_reverse($ctx['snaps'][$ds] ?? []) as $s) {
                    $p['snaps'][] = ['id' => "$ds@{$s['name']}", 'name' => $s['name'], 'time' => $s['time'],
                                     'path' => "$best/.zfs/snapshot/{$s['name']}$inside", 'ours' => backupIsEngineSnap($s['name'], $ctx['prefixes'])];
                    if (count($p['snaps']) >= RS_SNAPS_MAX) {
                        break;
                    }
                }
            }
            // a dataset of its own he put aside (<folder>.aside-<time>, zfs rename) took its snapshots along: still
            // offered for this folder, as what they are — found by their mountpoint next to it. Only those taken
            // before it went aside: later ones (a backup run snapshots it too) hold the leftover, not the folder
            $aside = [];
            foreach ($ctx['zfs'] as $mp => $ads) {
                if (preg_match('/^' . preg_quote($live, '/') . '\.aside-(\d{8}-\d{6})$/D', (string) $mp, $am)) {
                    $asideAt = DateTimeImmutable::createFromFormat('!Ymd-His', $am[1]);
                    foreach (array_reverse($ctx['snaps'][$ads] ?? []) as $s) {
                        if ($asideAt && $s['time'] > $asideAt->getTimestamp()) {
                            continue;
                        }
                        $aside[] = ['id' => "$ads@{$s['name']}", 'name' => $s['name'], 'time' => $s['time'], 'path' => "$mp/.zfs/snapshot/{$s['name']}",
                                    'ours' => backupIsEngineSnap($s['name'], $ctx['prefixes']), 'aside' => (string) $mp];
                    }
                }
            }
            if ($aside) {
                $p['snaps'] = array_merge($p['snaps'], $aside);
                // newest first; at the same time the folder's own snapshot before the one of the folder put aside
                usort($p['snaps'], fn ($a, $b) => [$b['time'], isset($a['aside'])] <=> [$a['time'], isset($b['aside'])]);
                $p['snaps'] = array_slice($p['snaps'], 0, RS_SNAPS_MAX);
            }
        } elseif ($p['fs'] === 'btrfs') {
            // a snapshot of the whole disk: it covers the share when the share's folder is in it (the folder itself may not be)
            $dir = "$mnt/$base/" . $ctx['btrfs_dir'];
            $names = array_filter(@scandir($dir, SCANDIR_SORT_DESCENDING) ?: [], fn ($n) => $n[0] !== '.');
            foreach ($names as $n) {
                $in = "$dir/$n/$share" . ($rel !== '' ? "/$rel" : '');
                if (!is_dir("$dir/$n/$share")) {
                    continue;
                }
                $t = preg_match('/(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)/', $n, $z)
                    ? (int) mktime((int) $z[4], (int) $z[5], 0, (int) $z[2], (int) $z[3], (int) $z[1]) : (int) @filemtime("$dir/$n");
                $p['snaps'][] = ['id' => "$base:$n", 'name' => $n, 'time' => $t, 'path' => $in, 'ours' => backupIsEngineSnap($n, $ctx['prefixes'], 'btrfs')];
                if (count($p['snaps']) >= RS_SNAPS_MAX) {
                    break;
                }
            }
            usort($p['snaps'], fn ($a, $b) => $b['time'] <=> $a['time']);
        }
        $places[] = $p;
    }
    return $places;
}

/**
 * Which moment a snapshot belongs to: the engine's are taken on every pool and disk of a run at once (ZFS
 * <prefix>YYYYMMDD-HHMM, btrfs YYYYMMDD-HHMM — the run), anybody else's by their name (Ms. Snapshotini's
 * uso-plan-…, a manual one) — those may be taken on some parts only. A snapshot of a dataset he put aside is a
 * moment of its own (that folder's state, not the share's): aside:<the folder put aside>@<name>.
 */
function rsMomentKey(array $s): string
{
    if (!empty($s['aside'])) {
        return 'aside:' . basename((string) $s['aside']) . '@' . $s['name'];
    }
    return !empty($s['ours']) && preg_match('/(\d{8}-\d{4})$/D', (string) $s['name'], $m) ? "run:$m[1]" : 'name:' . $s['name'];
}

/**
 * A share's content is the union of its parts on all its pools and disks; so is a snapshot of it. The moments
 * of a folder (or a whole share), newest first: per moment the parts it covers (base => that part's path in the
 * snapshot), its time (the newest part's), whether it is the engine's. Folders Kopia brought back are moments of
 * their own (one part, "kopia"); so are the snapshots of a dataset he put aside (aside: its folder).
 *
 * @return list<array{id:string, key:string, name:string, time:int, ours:bool, kopia:bool, aside:?string, parts:array<string, array{path:string, snap:string, name:string, time:int}>}>
 */
function rsMoments(array $places, array $kopia = []): array
{
    $m = [];
    foreach ($places as $p) {
        foreach ($p['asleep'] ? [] : $p['snaps'] as $s) {
            $key = rsMomentKey($s);
            $m[$key] ??= ['id' => $key, 'key' => $key, 'name' => $s['name'], 'time' => 0, 'ours' => false, 'kopia' => false, 'parts' => [],
                          'aside' => $s['aside'] ?? null];
            if (!isset($m[$key]['parts'][$p['base']])) {
                $m[$key]['parts'][$p['base']] = ['path' => $s['path'], 'snap' => $s['id'], 'name' => $s['name'], 'time' => (int) $s['time']];
                $m[$key]['time'] = max($m[$key]['time'], (int) $s['time']);
                $m[$key]['ours'] = $m[$key]['ours'] || !empty($s['ours']);
                if (str_starts_with($key, 'run:') && ($p['fs'] ?? '') === 'zfs') {
                    $m[$key]['name'] = $s['name'];          // the ZFS name says whose it is (btrfs only the run)
                }
            }
        }
    }
    foreach ($kopia as $k) {
        $m[$k['id']] = ['id' => $k['id'], 'key' => $k['id'], 'name' => $k['name'], 'time' => (int) $k['time'], 'ours' => false, 'kopia' => true, 'aside' => null,
                        'parts' => ['kopia' => ['path' => $k['path'], 'snap' => $k['id'], 'name' => $k['name'], 'time' => (int) $k['time']]]];
    }
    $m = array_values($m);
    usort($m, fn ($a, $b) => [$b['time'], $a['id']] <=> [$a['time'], $b['id']]);
    return array_slice($m, 0, RS_MOMENTS_MAX);
}

/**
 * Which parts of a moment hold the folder (for a whole share: anything at its top) — looked into for the newest
 * RS_HOLDS_CHECK moments only (each look mounts a ZFS snapshot); 'holds' stays null for the others.
 */
function rsMomentHolds(array &$moments, bool $whole): void
{
    foreach ($moments as $i => &$mo) {
        if ($i >= RS_HOLDS_CHECK) {
            $mo['holds'] = null;
            continue;
        }
        $mo['holds'] = [];
        foreach ($mo['parts'] as $base => $part) {
            clearstatcache(true, $part['path']);
            if ($whole ? rsDirHasEntries($part['path']) : (file_exists($part['path']) || is_link($part['path']))) {
                $mo['holds'][] = (string) $base;
            }
        }
    }
    unset($mo);
}

/**
 * What lies at the top of a share in a moment: the union of its parts' top folders (Ms. Dustdevil's storeroom and
 * what he left next to folders himself left out), name => {kind, bases}; count = how many there are in all.
 */
function rsMomentEntries(array $moment): array
{
    $names = [];
    $count = 0;
    foreach ($moment['parts'] as $base => $part) {
        foreach (@scandir($part['path'], SCANDIR_SORT_ASCENDING) ?: [] as $n) {
            if ($n === '.' || $n === '..' || $n === '.zfs' || $n === RS_STOREROOM || preg_match(RS_OWN_LEFTOVER, $n) || preg_match('/[\x00-\x1f\x7f]/', $n)) {
                continue;
            }
            if (!isset($names[$n])) {
                $count++;
                if (count($names) >= RS_ENTRIES_MAX * 4) {
                    continue;                      // counted, not listed
                }
                $names[$n] = ['kind' => is_dir("{$part['path']}/$n") && !is_link("{$part['path']}/$n") ? 'dir' : 'file', 'bases' => []];
            }
            if (isset($names[$n])) {
                $names[$n]['bases'][] = (string) $base;
            }
        }
    }
    uksort($names, 'strnatcasecmp');
    return ['names' => $names, 'count' => $count];
}

/**
 * The places of one entry at the top of a share, from the share's places: its live path and its paths in the
 * snapshots. A ZFS dataset at or inside it (one of its own) is located on its own instead — its content lies in its
 * own snapshots, not in the share's.
 */
function rsItemPlaces(array $rootPlaces, string $share, string $name, array &$ctx): array
{
    foreach ($rootPlaces as $p) {
        foreach (array_keys($ctx['zfs']) as $mp) {
            if (!$p['asleep'] && under((string) $mp, "{$p['live']}/$name")) {
                return rsLocate("/mnt/user/$share/$name", $ctx);
            }
        }
    }
    $out = [];
    foreach ($rootPlaces as $p) {
        $q = $p;
        $q['live'] = "{$p['live']}/$name";
        if (!$p['asleep']) {
            clearstatcache(true, $q['live']);
            $q['exists'] = file_exists($q['live']) || is_link($q['live']);
        }
        $q['own_dataset'] = false;
        $q['inner'] = [];
        $q['snaps'] = array_map(fn ($s) => ['path' => "{$s['path']}/$name"] + $s, $p['snaps']);
        $out[] = $q;
    }
    return $out;
}

/**
 * Where a moment holds one item: per part that has the moment's snapshot and the item in it (the union, primary
 * storage first, as the places come); a Kopia moment's folder plus $kopiaSub. Also which parts the moment covers at all.
 *
 * @return array{0: list<array{base:string, path:string, snap:?string}>, 1: list<string>}  snap: the ZFS snapshot (dataset@name) it lies in
 */
function rsItemSources(array $moment, array $places, string $kopiaSub = ''): array
{
    if ($moment['kopia']) {
        $p = $moment['parts']['kopia']['path'] . $kopiaSub;
        clearstatcache(true, $p);
        return [file_exists($p) || is_link($p) ? [['base' => 'kopia', 'path' => $p, 'snap' => null]] : [], ['kopia']];
    }
    $sources = $covered = [];
    foreach ($places as $p) {
        foreach ($p['asleep'] ? [] : $p['snaps'] as $s) {
            if (rsMomentKey($s) === $moment['key']) {
                $covered[] = $p['base'];
                clearstatcache(true, $s['path']);
                if (file_exists($s['path']) || is_link($s['path'])) {
                    $sources[] = ['base' => $p['base'], 'path' => $s['path'], 'snap' => ($p['fs'] ?? '') === 'zfs' && str_contains((string) $s['id'], '@') ? (string) $s['id'] : null];
                }
                break;
            }
        }
    }
    return [$sources, $covered];
}

// ===================================================================== the engine and its place

/** The engine: there at all, set up, busy (a run or its setup hold its lock), its Kopia */
function rsEngine(array $settings): array
{
    $data = BACKUP_DATA_DIR;
    $kopia = in_array(strtolower((string) backupSetting($settings, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    return [
        'found'    => is_file(BACKUP_SCRIPT_DIR . '/backup.sh'),
        'settings' => is_file("$data/settings.ini"),
        'busy'     => flockHeld("$data/state/lock"),
        'holder'   => backupLockHolder(),     // who holds it: a backup run, its check or dry run, the setup, one of his restores
        'kopia'    => $kopia,
        'kopia_container' => (string) backupSetting($settings, 'kopia', 'container', ''),
        'mount_root' => (string) backupSetting($settings, 'general', 'mount_root', '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/snapshots'),
        'view_root'  => (string) backupSetting($settings, 'general', 'view_root', '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/btrfs-snap'),
        'prefix'     => backupSnapPrefixes(backupSetting($settings, 'general', 'snap_prefix'))[0],
        'flash'      => (string) backupSetting($settings, 'flash', 'mode', 'off'),
    ];
}

/** The backup place: where, whether its disk sleeps, and the local snapshots of its share (older packages) */
function rsPlace(array $settings, array &$ctx): array
{
    $share = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $base = backupDumpsPath($share);
    $out = ['share' => $share, 'base' => $base, 'asleep' => false, 'found' => false, 'places' => []];
    if ($base === null) {
        return $out;
    }
    $out['places'] = rsLocate($base, $ctx);
    $out['asleep'] = (bool) array_filter($out['places'], fn ($p) => $p['asleep']);
    $out['found'] = !$out['asleep'] && (is_dir("$base/apps") || is_dir("$base/vms") || is_file("$base/server/run.json"));
    return $out;
}

/** The engine's history: per Kopia source when it last went well (history.jsonl, newest last) */
function rsKopiaLast(): array
{
    $last = [];
    $lines = @file(BACKUP_DATA_DIR . '/state/history.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -60) as $line) {
        $j = json_decode($line, true);
        foreach (is_array($j) ? (array) ($j['kopia']['done'] ?? []) : [] as $k) {
            if (is_array($k) && !empty($k['ok']) && is_string($k['name'] ?? null)) {
                $last[$k['name']] = max($last[$k['name']] ?? 0, (int) ($k['finished'] ?? 0) ?: (int) ($j['started'] ?? 0));
            }
        }
    }
    return $last;
}

/**
 * The Kopia container and where it sees the snapshots: the container path of mount_root (Kopia
 * names its sources after it), and a writable folder mapped for restores, if there is one.
 */
function rsKopia(array $engine, array $settings): array
{
    $out = ['enabled' => $engine['kopia'], 'container' => null, 'running' => false, 'root' => null, 'restore' => null];
    if (!$engine['kopia']) {
        return $out;
    }
    $all = houseContainers();
    $name = $engine['kopia_container'];
    if ($name === '' || !isset($all[$name])) {
        $name = '';
        foreach ($all as $c) {
            if (stripos($c['image'], 'kopia') !== false) {
                $name = $c['name'];
                break;
            }
        }
    }
    foreach ((array) (readJson(BACKUP_DATA_DIR . '/state/drift.json')['policies'] ?? []) as $p) {
        if (is_array($p) && ($p['kind'] ?? '') === 'root' && is_string($p['path'] ?? null) && $p['path'] !== '') {
            $out['root'] = rtrim($p['path'], '/');
        }
    }
    if ($name === '') {
        return $out;
    }
    $out['container'] = $name;
    $out['running'] = !empty($all[$name]['running']);
    $inspect = houseInspect($name);
    foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
        $src = rtrim((string) ($m['Source'] ?? ''), '/');
        $dst = rtrim((string) ($m['Destination'] ?? ''), '/');
        if ($src === rtrim($engine['mount_root'], '/')) {
            $out['root'] ??= $dst;
        } elseif (!empty($m['RW']) && ($m['Type'] ?? '') === 'bind' && str_starts_with($src, '/mnt/')
            && (stripos($dst, 'restore') !== false || stripos(basename($src), 'restore') !== false)) {
            $out['restore'] = ['source' => $src, 'dest' => $dst];
        }
    }
    return $out;                 // root null: not known (the page shows an example)
}

// ===================================================================== the packages

/**
 * The packages of a backup place (engine 2.18+), the way Mr. Restori needs them: per app its
 * containers with the folders they bind, files, dumps with the credentials' variable names,
 * templates, compose files; per VM its XML, NVRAM, TPM state and disks. Cached by mtimes.
 */
function rsPackages(string $base): array
{
    static $cache = [];
    $stamp = implode(':', array_map(fn ($p) => (int) @filemtime("$base/$p"), ['', 'apps', 'vms', 'server', 'server/run.json', 'flash']));
    if (($cache[$base][0] ?? null) === $stamp) {
        return $cache[$base][1];
    }
    $server = readJson("$base/server/run.json");
    $last = is_string($server['run'] ?? null) ? $server['run'] : '';
    $out = ['run' => $last ?: null, 'time' => rsRunTime($last), 'apps' => [], 'vms' => [], 'server' => null, 'flash' => null];
    foreach (['apps', 'vms'] as $sub) {
        foreach (@scandir("$base/$sub") ?: [] as $folder) {
            if ($folder[0] === '.' || !is_dir("$base/$sub/$folder")) {
                continue;
            }
            $m = readJson("$base/$sub/$folder/manifest.json");
            if (!$m) {
                continue;
            }
            $p = $sub === 'apps' ? rsAppPackage($m, $folder, "$base/$sub/$folder") : rsVmPackage($m, $folder, "$base/$sub/$folder");
            $p['stale'] = $last !== '' && $p['run'] !== '' && strcmp($p['run'], $last) < 0;
            $out[$sub][] = $p;
        }
    }
    usort($out['apps'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($out['vms'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    if ($server) {
        $files = rsFiles($server);
        $lv = array_values(array_filter($files, fn ($f) => $f['path'] === 'libvirt.tar.gz'))[0] ?? null;
        $out['server'] = [
            'path'      => "$base/server",
            'libvirt'   => $lv && is_file("$base/server/libvirt.tar.gz") ? "$base/server/libvirt.tar.gz" : null,
            'libvirt_time' => $lv['time'] ?? null,
            'libvirt_bytes' => $lv['bytes'] ?? null,
            'templates' => count(array_filter($files, fn ($f) => str_starts_with($f['path'], 'docker-templates/'))),
            'compose'   => array_values(array_unique(array_filter(array_map(fn ($f) => explode('/', $f['path'])[1] ?? '',
                               array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose/')))))),
            'shares'    => is_dir("$base/server/shares") ? "$base/server/shares" : null,
        ];
    }
    $flash = readJson("$base/flash/manifest.json");
    if ($flash && is_file("$base/flash/flash.tar.gz")) {
        $f = array_values(array_filter(rsFiles($flash), fn ($x) => $x['path'] === 'flash.tar.gz'))[0] ?? null;
        $out['flash'] = ['path' => "$base/flash/flash.tar.gz", 'time' => $f['time'] ?? rsRunTime((string) ($flash['run'] ?? '')),
                         'bytes' => (int) @filesize("$base/flash/flash.tar.gz")];
    }
    $cache = [$base => [$stamp, $out]];
    return $out;
}

function rsRunTime(string $run): ?int
{
    return preg_match('/^\d{8}-\d{4}$/', $run) ? ((DateTime::createFromFormat('Ymd-Hi', $run)?->getTimestamp()) ?: null) : null;
}

function rsStr(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** The files of a manifest: path, bytes, which run wrote it (a kept dump keeps its run), what it is */
function rsFiles(array $m): array
{
    $out = [];
    foreach ((array) ($m['files'] ?? []) as $f) {
        if (!is_array($f) || !is_string($f['path'] ?? null) || str_contains('/' . $f['path'] . '/', '/../')) {
            continue;
        }
        $run = rsStr($f['run'] ?? '');
        $out[] = ['path' => $f['path'], 'bytes' => (int) ($f['bytes'] ?? 0), 'run' => $run, 'time' => rsRunTime($run),
                  'what' => rsStr($f['what'] ?? ''), 'container' => rsStr($f['container'] ?? '')];
    }
    return $out;
}

/** The database type of a container, from its image and its environment (as the engine's setup reads it) */
function rsDbType(array $c): ?string
{
    $env = implode("\n", array_filter((array) ($c['inspect']['Config']['Env'] ?? []), 'is_string'));
    $image = strtolower(rsStr($c['image'] ?? ''));
    if (preg_match('/^(MARIADB_VERSION|MYSQL_VERSION|MARIADB_MAJOR|MYSQL_MAJOR)=/m', $env) || preg_match('#(^|/)(mariadb|mysql|percona)#', $image)) {
        return 'mariadb';
    }
    if (preg_match('/^(PG_MAJOR|PG_VERSION)=/m', $env) || preg_match('#(postgres|pgvecto|vectorchord|postgis|timescale)#', $image)) {
        return 'postgres';
    }
    if (preg_match('/^MONGO_VERSION=/m', $env) || preg_match('#(^|/)mongo#', $image)) {
        return 'mongodb';
    }
    if (preg_match('/^(REDIS_VERSION|VALKEY_VERSION)=/m', $env) || preg_match('#(^|/)(redis|valkey|keydb|memcached)#', $image)) {
        return 'cache';
    }
    return null;
}

function rsAppPackage(array $m, string $folder, string $path): array
{
    $files = rsFiles($m);
    $containers = [];
    foreach ((array) ($m['containers'] ?? []) as $c) {
        if (!is_array($c) || rsStr($c['name'] ?? '') === '') {
            continue;
        }
        $binds = [];
        foreach ((array) ($c['inspect']['Mounts'] ?? []) as $b) {
            if (is_array($b) && ($b['Type'] ?? '') === 'bind' && is_string($b['Source'] ?? null)) {
                $binds[] = ['source' => rtrim($b['Source'], '/'), 'dest' => rsStr($b['Destination'] ?? ''), 'rw' => !empty($b['RW'])];
            }
        }
        $containers[] = [
            'name'     => rsStr($c['name']),
            'image'    => rsStr($c['image'] ?? ''),
            'digest'   => rsStr(((array) ($c['digests'] ?? []))[0] ?? ''),
            'template' => rsStr($c['template'] ?? ''),
            'service'  => rsStr($c['service'] ?? ''),
            'db'       => rsDbType($c),
            'binds'    => $binds,
        ];
    }
    $byPath = [];
    foreach ($files as $f) {
        $byPath[$f['path']] = $f;
    }
    $dumps = [];
    foreach ((array) ($m['dumps'] ?? []) as $d) {
        if (!is_array($d) || !in_array($d['type'] ?? '', RS_DB_TYPES, true)) {
            continue;
        }
        $c = rsStr($d['container'] ?? '');
        foreach ($files as $f) {
            if ($f['what'] === 'dump' && $f['container'] === $c) {
                $dumps[] = ['file' => $f['path'], 'container' => $c, 'type' => $d['type'], 'bytes' => $f['bytes'], 'run' => $f['run'],
                            'time' => $f['time'], 'kept' => $f['run'] !== '' && $f['run'] !== rsStr($m['run'] ?? ''),
                            'state' => rsStr($d['state'] ?? ''), 'login' => rsStr($d['login'] ?? ''), 'user_var' => rsStr($d['user_var'] ?? ''),
                            'password_var' => rsStr($d['password_var'] ?? ''), 'client' => rsStr($d['client'] ?? ''),
                            'db' => $d['type'] === 'mariadb' && preg_match('/^db\/mariadb_' . preg_quote($c, '/') . '_(.+)\.sql\.gz$/', $f['path'], $x) ? $x[1] : null];
            }
        }
    }
    $compose = is_array($m['compose'] ?? null) ? [
        'project'      => rsStr($m['compose']['project'] ?? ''),
        'dir'          => rsStr($m['compose']['manager_dir'] ?? ''),
        'working_dir'  => rsStr($m['compose']['working_dir'] ?? ''),
        'config_files' => array_values(array_filter((array) ($m['compose']['config_files'] ?? []), 'is_string')),
        'files'        => array_values(array_map(fn ($f) => substr($f['path'], 8), array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose/')))),
        'indirect'     => (bool) array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose-files/')),
    ] : null;
    $run = rsStr($m['run'] ?? '');
    return [
        'id'         => $folder,
        'name'       => rsStr($m['name'] ?? '') ?: $folder,
        'type'       => rsStr($m['type'] ?? ''),
        'run'        => $run,
        'time'       => rsRunTime($run),
        'result'     => rsStr($m['result'] ?? ''),
        'path'       => $path,
        'containers' => $containers,
        'files'      => $files,
        'dumps'      => $dumps,
        'templates'  => array_values(array_map(fn ($f) => $f['path'], array_filter($files, fn ($f) => $f['what'] === 'template' && !str_contains($f['path'], '/')))),
        'compose'    => $compose,
        'nextcloud'  => array_values(array_map(fn ($n) => ['container' => rsStr($n['container'] ?? ''), 'occ' => rsStr($n['occ'] ?? '') ?: '/var/www/html/occ',
                            'user' => rsStr($n['user'] ?? '') ?: 'www-data', 'same_as' => rsStr($n['same_as'] ?? '')],
                            array_filter((array) ($m['nextcloud'] ?? []), 'is_array'))),
        'sqlite'     => array_values(array_map(fn ($q) => ['container' => rsStr($q['container'] ?? ''), 'file' => rsStr($q['file'] ?? ''),
                            'source' => rsStr($q['source'] ?? ''), 'check' => rsStr($q['check'] ?? ''),
                            'bytes' => (int) ($byPath[rsStr($q['file'] ?? '')]['bytes'] ?? 0), 'time' => $byPath[rsStr($q['file'] ?? '')]['time'] ?? null,
                            'kept' => ($byPath[rsStr($q['file'] ?? '')]['run'] ?? $run) !== $run],
                            array_filter((array) ($m['sqlite'] ?? []), fn ($q) => is_array($q) && !empty($q['present'])))),
        'own_backups' => array_values(array_map(fn ($o) => ['kind' => rsStr($o['kind'] ?? ''), 'container' => rsStr($o['container'] ?? ''),
                            'path' => rsStr($o['path'] ?? ''), 'files' => (int) ($o['files'] ?? 0), 'newest' => (int) ($o['newest'] ?? 0), 'asleep' => !empty($o['asleep'])],
                            array_filter((array) ($m['own_backups'] ?? []), fn ($o) => is_array($o) && in_array($o['kind'] ?? '', ['emby', 'jellyfin', 'plex', 'immich'], true)))),
    ];
}

function rsVmPackage(array $m, string $folder, string $path): array
{
    $files = rsFiles($m);
    $run = rsStr($m['run'] ?? '');
    $uuid = preg_match('/^' . RS_UUID . '$/i', rsStr($m['uuid'] ?? '')) ? strtolower(rsStr($m['uuid'])) : '';
    $xml = rsStr($m['xml'] ?? '');
    $xml = $xml !== '' && !str_contains($xml, '/') && is_file("$path/$xml") ? $xml : '';
    return [
        'id'        => $folder,
        'name'      => rsStr($m['name'] ?? '') ?: $folder,
        'run'       => $run,
        'time'      => rsRunTime($run),
        'result'    => rsStr($m['result'] ?? ''),
        'path'      => $path,
        'xml'       => $xml,
        'uuid'      => $uuid,
        'autostart' => !empty($m['autostart']),
        'prepare'   => rsStr($m['prepare'] ?? ''),
        'nvram'     => array_values(array_map(fn ($f) => basename($f['path']), array_filter($files, fn ($f) => $f['what'] === 'nvram' && substr_count($f['path'], '/') === 1))),
        'tpm'       => $uuid !== '' && is_dir("$path/tpm/$uuid"),
        'snapshotdb' => (bool) array_filter($files, fn ($f) => $f['path'] === 'snapshotdb/snapshots.db'),
        'hostdev'   => $xml !== '' ? substr_count((string) @file_get_contents("$path/$xml", false, null, 0, 1 << 20), '<hostdev') : 0,
        'disks'     => array_values(array_map(fn ($d) => ['target' => rsStr($d['target'] ?? ''), 'source' => rsStr($d['source'] ?? ''),
                           'snapshot' => rsStr($d['snapshot'] ?? ''), 'bytes' => isset($d['bytes']) ? (int) $d['bytes'] : null, 'share' => rsStr($d['share'] ?? '')],
                           array_filter((array) ($m['disks'] ?? []), 'is_array'))),
        'files'     => $files,
    ];
}

// ===================================================================== what is on the server now

/** The compose manager's projects folder */
function rsComposeRoot(): string
{
    $v = trim((string) (readCfg(RS_COMPOSE_CFG)['PROJECTS_FOLDER'] ?? ''));
    return rtrim($v !== '' ? $v : RS_COMPOSE_DEF, '/');
}

/** Is a file in place, and the same as the package's? same | differs | missing */
function rsCompare(string $package, string $live): string
{
    clearstatcache(true, $live);
    if (!is_file($live)) {
        return file_exists($live) ? 'differs' : 'missing';
    }
    return @filesize($package) === @filesize($live) && @md5_file($package) === @md5_file($live) ? 'same' : 'differs';
}

/** The VMs libvirt knows right now: name => state ('running', 'shut off' …); null while the VM service is off */
function rsVmStates(): ?array
{
    if (!is_dir(RS_LIBVIRT . '/qemu') || !bin('virsh')) {
        return null;
    }
    [$exit, $out] = run(['virsh', 'list', '--all'], 20);
    if ($exit !== 0) {
        return null;
    }
    $states = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\s*(\d+|-)\s+(.+?)\s{2,}(\S.*?)\s*$/', $line, $m) && $m[2] !== 'Name') {
            $states[$m[2]] = $m[3];
        }
    }
    return $states;
}

/**
 * The folders of an app as restore units: the first folder below a share that one of its
 * containers binds (appdata/<app>, a photo folder in another share …), and a whole share bound as
 * it is (drop's data, a media library) — that one comes back as a whole or by chosen folders at its top.
 */
function rsAppFolders(array $app, array &$ctx): array
{
    $units = [];
    $shares = [];
    $placeShare = (string) backupSetting($ctx['settings'], 'general', 'dumps_share', '');
    foreach ($app['containers'] as $c) {
        foreach ($c['binds'] as $b) {
            $sp = rsSharePath($b['source'], $ctx);
            if (!$sp || $sp[0] === $placeShare || $sp[0] === BACKUP_OFFICE_SHARE) {
                continue;                           // not in a share, or the backup place's / the office's own share
            }
            [$share, $rel] = $sp;
            if ($rel === '') {
                $shares[$share][$c['name']] = true;
                continue;
            }
            $first = explode('/', $rel)[0];
            $key = "/mnt/user/$share/$first";
            $units[$key] ??= ['path' => $key, 'share' => $share, 'rel' => $first, 'containers' => []];
            $units[$key]['containers'][$c['name']] = true;
        }
    }
    $out = [];
    foreach ($units as $u) {
        $out[] = rsFolder($u['path'], array_keys($u['containers']), $ctx) + ['share' => $u['share'], 'whole' => false];
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
    $whole = [];
    ksort($shares, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($shares as $share => $cts) {
        $whole[] = rsFolder("/mnt/user/$share", array_keys($cts), $ctx) + ['share' => (string) $share, 'whole' => true];
    }
    return [$out, $whole];
}

/**
 * The shares an app or VM keeps its data in, as they are now (rsShareNow): before data comes back they must be
 * there — a missing one the user creates in Unraid himself (its old settings shown as information).
 */
function rsNeeds(array $folders, array $whole, array &$ctx): array
{
    $shares = array_unique(array_merge(array_column($folders, 'share'), array_column($whole, 'share')));
    natcasesort($shares);
    return array_values(array_map(fn ($s) => rsShareNow((string) $s, $ctx), $shares));
}

/** A folder as the page shows it: where it lies, its protection, its local snapshots */
function rsFolder(string $path, array $containers, array &$ctx): array
{
    $places = rsLocate($path, $ctx);
    $snaps = array_merge(...array_map(fn ($p) => $p['snaps'], $places) ?: [[]]);
    return [
        'path'       => $path,
        'containers' => $containers,
        'protection' => backupProtection($path),
        'exists'     => (bool) array_filter($places, fn ($p) => $p['exists']),
        'asleep'     => (bool) array_filter($places, fn ($p) => $p['asleep']),
        'places'     => array_map(fn ($p) => array_diff_key($p, ['snaps' => 0]) + ['count' => count($p['snaps']), 'latest' => $p['snaps'][0] ?? null], $places),
        'snaps'      => count($snaps),
        'newest'     => $snaps ? max(array_column($snaps, 'time')) : null,
        'oldest'     => $snaps ? min(array_column($snaps, 'time')) : null,
    ];
}

// ===================================================================== state

function rsScan(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $engine = rsEngine($settings);
    $place = rsPlace($settings, $ctx);
    $old = $GLOBALS['rs']['state'] ?? [];
    $state = [
        'time'     => time(),
        'host'     => hostname(),
        'engine'   => $engine,
        'place'    => ['share' => $place['share'], 'base' => $place['base'], 'asleep' => $place['asleep'], 'found' => $place['found'],
                       'snaps' => array_sum(array_map(fn ($p) => count($p['snaps']), $place['places']))],
        'kopia'    => rsKopia($engine, $settings),
        'compose_root' => rsComposeRoot(),
        'templates_dir' => RS_TEMPLATES,
        'libvirt_img' => readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? '/mnt/user/system/libvirt/libvirt.img',
        'vm_service' => is_dir(RS_LIBVIRT . '/qemu'),
        'shares'   => [],
        'apps'     => [],
        'vms'      => [],
        'server'   => null,
        'flash'    => null,
        'run'      => null,
        'run_time' => null,
        'running'  => rsRunningJob(),          // a restore of his going on now
        'restores' => rsJournals(),            // his restores, newest first
    ];
    if ($place['asleep']) {
        // the backup place's disk sleeps: what was read before
        foreach (['apps', 'vms', 'server', 'flash', 'run', 'run_time', 'shares'] as $k) {
            $state[$k] = $old[$k] ?? $state[$k];
        }
        return rsWrite($state);
    }
    $pk = $place['found'] ? rsPackages($place['base']) : null;
    $ctx['old_shares'] = $pk['server']['shares'] ?? null;
    $kopiaLast = rsKopiaLast();
    $containers = houseContainers();
    $vmStates = rsVmStates();
    $kopiaShare = fn (string $share) => $engine['kopia'] && backupSetting($settings, "share|$share", 'mode', 'off') === 'kopia';
    $own = fn (string $kind, string $name) => $engine['kopia'] && backupSetting($settings, "$kind|$name", 'kopia', 'no') === 'yes';
    /*
     * Where Kopia has an app or a VM, and what each source holds of it (covers): its own source holds all of
     * it (its folders and its package); otherwise its data goes with the shares of the folders Kopia takes
     * (a folder Kopia leaves out stays only local), and its package with the backup place's share.
     */
    $sourcesOf = function (string $kind, string $name, string $id, array $folders, array $whole) use ($own, $kopiaShare, $kopiaLast, $place): array {
        if ($own($kind, $name)) {
            return [['source' => ".{$kind}s/$id", 'own' => true, 'covers' => 'all', 'last' => $kopiaLast["$kind:$name"] ?? null]];
        }
        $sources = [];
        $data = array_merge(array_map(fn ($f) => $f['protection'] === 'offsite' ? (explode('/', $f['path'])[3] ?? '') : '', $folders), array_column($whole, 'share'));
        foreach (array_unique(array_filter($data)) as $share) {
            if ($kopiaShare($share)) {
                $sources[] = ['source' => $share, 'own' => false, 'covers' => 'data', 'last' => $kopiaLast[$share] ?? null];
            }
        }
        if ($place['share'] !== '' && $kopiaShare($place['share'])) {
            $sources[] = ['source' => $place['share'], 'own' => false, 'covers' => 'package', 'last' => $kopiaLast[$place['share']] ?? null];
        }
        return $sources;
    };
    foreach ($pk['apps'] ?? [] as $a) {
        [$folders, $whole] = rsAppFolders($a, $ctx);
        foreach ($a['containers'] as &$c) {
            $c['now'] = isset($containers[$c['name']]) ? ($containers[$c['name']]['running'] ? 'running' : 'stopped') : 'missing';
            unset($c['binds']);
        }
        unset($c);
        $sources = $sourcesOf('app', $a['name'], $a['id'], $folders, $whole);
        $a['templates'] = array_map(fn ($t) => ['file' => $t, 'now' => rsCompare("{$a['path']}/$t", RS_TEMPLATES . "/$t")], $a['templates']);
        if ($a['compose']) {
            $root = $state['compose_root'];
            $dir = $a['compose']['dir'];
            $a['compose']['now'] = array_map(fn ($f) => ['file' => $f, 'now' => $dir !== '' ? rsCompare("{$a['path']}/compose/$f", "$root/$dir/$f") : 'missing'],
                $a['compose']['files']);
        }
        $a['immich'] = (bool) array_filter($a['containers'], fn ($c) => stripos($c['image'], 'immich') !== false);
        $a['db_only'] = $a['containers'] && !array_filter($a['containers'], fn ($c) => !in_array($c['db'], ['mariadb', 'postgres', 'mongodb', 'cache'], true));
        $a['present'] = (bool) array_filter($a['containers'], fn ($c) => $c['now'] !== 'missing');
        $a['folders'] = $folders;
        $a['shares'] = $whole;
        $a['needs'] = rsNeeds($folders, $whole, $ctx);
        $a['kopia'] = $sources;
        $a['package_protection'] = rsPackageProtection($a['path'], $own('app', $a['name']));
        $a['files'] = array_values(array_filter($a['files'], fn ($f) => $f['what'] !== 'error'));
        $state['apps'][] = $a;
    }
    foreach ($pk['vms'] ?? [] as $v) {
        $units = [];
        foreach ($v['disks'] as $d) {
            $sp = rsSharePath($d['source'], $ctx);
            if ($sp && $sp[1] !== '') {
                $first = explode('/', $sp[1])[0];
                $units["/mnt/user/{$sp[0]}/$first"] = true;
            }
        }
        $v['folders'] = array_map(fn ($p) => rsFolder($p, [], $ctx) + ['share' => explode('/', $p)[3], 'whole' => false], array_keys($units));
        $v['needs'] = rsNeeds($v['folders'], [], $ctx);
        $v['state'] = $vmStates === null ? null : ($vmStates[$v['name']] ?? 'missing');
        $v['kopia'] = $sourcesOf('vm', $v['name'], $v['id'], $v['folders'], []);
        unset($v['files']);
        $state['vms'][] = $v;
    }
    $state['server'] = $pk['server'] ?? null;
    $state['flash'] = $pk['flash'] ?? null;
    $state['run'] = $pk['run'] ?? null;
    $state['run_time'] = $pk['time'] ?? null;
    // the shares as the engine backs them up, for the Kopia guide
    foreach ($settings as $key => $_) {
        if (str_starts_with($key, 'share|')) {
            $name = substr($key, 6);
            $state['shares'][] = ['name' => $name, 'mode' => backupSetting($settings, $key, 'mode', 'off'), 'last' => $kopiaLast[$name] ?? null];
        }
    }
    if ($engine['flash'] === 'snapshot') {
        $state['shares'][] = ['name' => '_flash', 'mode' => 'kopia', 'last' => $kopiaLast['flash'] ?? null, 'flash' => true];
    }
    usort($state['shares'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return rsWrite($state);
}

/**
 * Where an app's package (its dumps and database copies with it) is kept: its own Kopia source takes it along,
 * otherwise it is protected like the backup place's share (backupProtection; null when not known)
 */
function rsPackageProtection(string $path, bool $ownKopia, ?array $settings = null): ?string
{
    return $ownKopia ? 'offsite' : backupProtection($path, 0, $settings);
}

function rsWrite(array $state): array
{
    $GLOBALS['rs']['state'] = $state;
    writeAtomic(deskFile('restore'), jsonEncode($state));
    return $state;
}

// ===================================================================== older packages

/**
 * The package of an app or VM as earlier nights left it: from the local snapshots of the backup
 * place's share (newest first) — each with its run and, for an app, its dumps. Read on demand
 * (the page asks when a row unfolds); never on a sleeping disk.
 */
function rsVersions(string $kind, string $id): array
{
    if (!in_array($kind, ['app', 'vm'], true)) {
        throw new Problem('unknown_target', ['target' => $kind]);
    }
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $place = rsPlace($settings, $ctx);
    if ($place['asleep']) {
        throw new Problem('restore_place_asleep');
    }
    $list = $place['found'] ? rsPackages($place['base'])[$kind . 's'] : [];
    $current = array_values(array_filter($list, fn ($p) => $p['id'] === $id))[0] ?? null;
    if (!$current) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    return ['ok' => true, 'kind' => $kind, 'id' => $id, 'versions' => rsVersionList($place, $kind, $id, $current['run'])];
}

/** @return list<array{snap:string, time:int, run:string, run_time:?int, path:string, dumps:list<array>, sqlite:list<array>, files:int}> */
function rsVersionList(array $place, string $kind, string $id, string $currentRun): array
{
    $out = [];
    $seen = [$currentRun => true];
    foreach ($place['places'] as $p) {
        foreach ($p['snaps'] as $s) {
            $dir = "{$s['path']}/{$kind}s/$id";
            $m = is_file("$dir/manifest.json") ? readJson("$dir/manifest.json") : null;
            if (!$m) {
                continue;
            }
            $pkg = $kind === 'app' ? rsAppPackage($m, $id, $dir) : rsVmPackage($m, $id, $dir);
            // the same package as a newer state (nothing new was written that night) is listed once
            $key = $pkg['run'] . ':' . implode(',', array_map(fn ($f) => $f['path'] . '@' . $f['run'], $pkg['files']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['snap' => $s['id'], 'time' => $s['time'], 'run' => $pkg['run'], 'run_time' => $pkg['time'], 'path' => $dir,
                      'dumps' => $kind === 'app' ? array_map(fn ($d) => array_intersect_key($d, array_flip(['file', 'container', 'type', 'bytes', 'time', 'kept'])), $pkg['dumps']) : [],
                      // a media server's database copies: that night's go back together (kind sqlite with this version)
                      'sqlite' => $kind === 'app' ? array_map(fn ($q) => array_intersect_key($q, array_flip(['file', 'container', 'bytes', 'time', 'kept'])), $pkg['sqlite']) : [],
                      'files' => count($pkg['files'])];
        }
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

// ===================================================================== restoring: journal and lock

/** The folder of a restore: data/restore/<id> — the id checked */
function rsDir(string $id): string
{
    if (!preg_match(RS_ID_PATTERN, $id)) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    return rsData() . "/$id";
}

function rsJournal(string $id): ?array
{
    return readJson(rsDir($id) . '/journal.json');
}

/**
 * Writes a journal (root only) and — for the restore going on — data/restore-job.json, which the
 * page polls straight from disk (api part "job"): it names paths and containers, never a value.
 */
function rsJournalWrite(array &$j, bool $public = true): void
{
    $j['heartbeat'] = time();
    writeAtomic(rsDir($j['id']) . '/journal.json', jsonEncode($j), 0600, 0, 0);
    if ($public) {
        writeAtomic(rsJobFile(), jsonEncode($j));
    }
}

/** A line in a restore's log (data/restore/<id>/log.txt, root only) */
function rsLog(string $id, string $line): void
{
    $file = rsDir($id) . '/log.txt';
    if (is_link($file)) {
        @unlink($file);
    }
    $new = !file_exists($file);
    @file_put_contents($file, date('Y-m-d H:i:s') . '  ' . str_replace("\r", '', rtrim($line)) . "\n", FILE_APPEND);
    if ($new) {
        @chmod($file, 0600);
    }
}

/** The folder data/restore and one restore's folder in it: root only, never through a link */
function rsPrivateDir(string $dir): void
{
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        throw new Problem('command_failed', ['detail' => "$dir is no folder"]);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700)) {
        throw new Problem('command_failed', ['detail' => "cannot create $dir"]);
    }
    @chown($dir, 0);
    @chgrp($dir, 0);
    @chmod($dir, 0700);
}

/** The restore going on now — its job lives — or null */
function rsRunningJob(): ?array
{
    $j = readJson(rsJobFile());
    if (!$j || !is_string($j['id'] ?? null) || !in_array($j['result'] ?? '', ['queued', 'running'], true)) {
        return null;
    }
    if ($j['result'] === 'queued') {
        return time() - (int) ($j['created'] ?? 0) < 120 ? rsJobSummary($j) : null;
    }
    return rsJobAlive($j) ? rsJobSummary($j) : null;
}

function rsJobAlive(array $j): bool
{
    $pid = (int) ($j['pid'] ?? 0);
    return $pid > 1 && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php');
}

function rsJobSummary(array $j): array
{
    $steps = (array) ($j['steps'] ?? []);
    $at = 0;
    foreach ($steps as $i => $s) {
        if (in_array($s['state'] ?? 'pending', ['running', 'ok', 'warning', 'failed'], true)) {
            $at = $i + 1;
        }
    }
    return ['id' => (string) $j['id'], 'kind' => (string) ($j['kind'] ?? ''), 'what' => (string) ($j['what'] ?? ''), 'result' => (string) ($j['result'] ?? ''),
            'started' => $j['started'] ?? null, 'step' => $at, 'steps' => count($steps)];
}

/**
 * His restores for the page, newest first: kind, what, when, how it went, what he put aside, and whether
 * «Put back» can undo it. A journal still "running" whose job is gone was interrupted (a reboot): said so.
 */
function rsJournals(): array
{
    $ids = array_values(array_filter(@scandir(rsData(), SCANDIR_SORT_DESCENDING) ?: [], fn ($n) => (bool) preg_match(RS_ID_PATTERN, $n)));
    $running = rsRunningJob();
    $out = [];
    foreach (array_slice($ids, 0, RS_KEEP) as $id) {
        $j = readJson(rsData() . "/$id/journal.json");
        if (!$j) {
            continue;
        }
        if (in_array($j['result'] ?? '', ['queued', 'running'], true) && ($running['id'] ?? '') !== $id
            && (($j['result'] === 'running' && !rsJobAlive($j)) || ($j['result'] === 'queued' && time() - (int) ($j['created'] ?? 0) > 120))) {
            $j['result'] = 'interrupted';
            $j['finished'] = $j['heartbeat'] ?? time();
            try {
                rsJournalWrite($j, false);
            } catch (Throwable) {
            }
        }
        $out[] = rsJournalRow($j);
    }
    return $out;
}

/** A journal as the page lists it */
function rsJournalRow(array $j): array
{
    $failed = null;
    foreach ((array) ($j['steps'] ?? []) as $i => $s) {
        if (($s['state'] ?? '') === 'failed') {
            $failed = ['n' => $i + 1, 'do' => (string) ($s['do'] ?? ''), 'note' => (string) ($s['note'] ?? ''), 'detail' => mb_substr((string) ($s['detail'] ?? ''), 0, 400)];
        }
    }
    return [
        'id'         => (string) $j['id'],
        'kind'       => (string) ($j['kind'] ?? ''),
        'what'       => (string) ($j['what'] ?? ''),
        'method'     => $j['method'] ?? null,
        'target'     => (array) ($j['target'] ?? []),
        'created'    => (int) ($j['created'] ?? 0),
        'started'    => $j['started'] ?? null,
        'finished'   => $j['finished'] ?? null,
        'result'     => (string) ($j['result'] ?? ''),
        'reason'     => $j['reason'] ?? null,
        'reason_params' => (array) ($j['reason_params'] ?? []),
        'failed'     => $failed,
        'aside'      => array_values((array) ($j['aside'] ?? [])),
        'putback_of' => $j['putback_of'] ?? null,
        'putback'    => rsPutbackInfo($j),
        'can_putback' => rsCanPutback($j),
        'after'      => (array) ($j['after'] ?? []),
    ];
}

/**
 * The put back of a restore as its journal row shows it: its id and how it went — and, from its own journal, when it
 * ended and where the restored state went (what it put aside, <x>.putback-<time>). Null when it was never put back.
 */
function rsPutbackInfo(array $j): ?array
{
    $pb = $j['putback'] ?? null;
    if (!is_array($pb) || !is_string($pb['id'] ?? null) || !preg_match(RS_ID_PATTERN, $pb['id'])) {
        return null;
    }
    $out = ['id' => $pb['id'], 'result' => (string) ($pb['result'] ?? ''), 'finished' => null, 'aside' => []];
    $other = readJson(rsData() . "/{$pb['id']}/journal.json");
    if ($other) {
        $out['finished'] = $other['finished'] ?? null;
        foreach ((array) ($other['aside'] ?? []) as $a) {
            if (is_array($a) && is_string($a['from'] ?? null) && is_string($a['to'] ?? null)) {
                $out['aside'][] = ['from' => $a['from'], 'to' => $a['to']];
            }
        }
    }
    return $out;
}

/** Can «Put back» undo this restore? Something of it was done and can be undone, and it wasn't put back yet */
function rsCanPutback(array $j): bool
{
    if (in_array($j['kind'] ?? '', ['putback', 'kopia'], true) || !in_array($j['result'] ?? '', ['ok', 'warnings', 'failed', 'interrupted'], true)) {
        return false;
    }
    if (is_array($j['putback'] ?? null) && !in_array($j['putback']['result'] ?? '', ['refused'], true)) {
        return false;
    }
    return rsUndoSteps($j) !== [] || !empty($j['stopped']);
}

/** A journal for the page: its steps and the end of its log */
function rsJournalGet(string $id): array
{
    $j = rsJournal($id);
    if (!$j) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    $log = @file(rsDir($id) . '/log.txt', FILE_IGNORE_NEW_LINES) ?: [];
    return ['ok' => true, 'journal' => $j, 'row' => rsJournalRow($j), 'log' => array_slice($log, -300)];
}

/**
 * The engine's lock (state/lock, engine 2.20), taken without waiting: opened for appending (never
 * truncating, like the engine's >>), touched, and noted in state/lock-holder.json (a new file + rename).
 * Busy: who holds it instead of a handle.
 *
 * @return resource|array
 */
function rsLockTake(string $what, string $mode): mixed
{
    $state = rsUbData() . '/state';
    if (!is_dir($state)) {
        @mkdir($state, 0700, true);
    }
    $file = "$state/lock";
    $h = is_link($file) ? false : @fopen($file, 'a');
    if (!$h) {
        return ['holder' => 'other', 'what' => '', 'run' => ''];
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return backupLockHolder(rsUbData()) ?? ['holder' => 'other', 'what' => '', 'run' => ''];
    }
    @touch($file);
    writeAtomic("$state/lock-holder.json", jsonEncode(['holder' => 'restore', 'mode' => $mode, 'what' => $what,
        'pid' => getmypid(), 'started' => time(), 'version' => AGENT_VERSION]), 0600, 0, 0);
    return $h;
}

/** Gives the lock back; the note goes only while it is still his own (same pid) */
function rsLockRelease(mixed $h): void
{
    $note = rsUbData() . '/state/lock-holder.json';
    if ((int) (readJson($note)['pid'] ?? 0) === getmypid()) {
        @unlink($note);
    }
    if (is_resource($h)) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/** Why no restore can start now — one at a time, never while the engine's lock is held — or null */
function rsBusy(): ?array
{
    $run = rsRunningJob();
    if ($run) {
        return ['key' => 'restore_busy_restore', 'params' => ['what' => $run['what']]];
    }
    $h = backupLockHolder(rsUbData());
    return $h ? ['key' => 'restore_busy_' . $h['holder'], 'params' => ['what' => $h['what'], 'run' => $h['run']]] : null;
}

// ===================================================================== restoring: the plans

/** The time in the names of what he puts aside — the preview's, when the page sends it back (≤ a day old) */
function rsStampOf(array $r): string
{
    $s = $r['stamp'] ?? null;
    if (is_string($s) && preg_match(RS_STAMP, $s)) {
        $t = DateTime::createFromFormat('Ymd-His', $s)?->getTimestamp();
        if ($t && $t <= time() + 60 && $t >= time() - 86400) {
            return $s;
        }
    }
    return date('Ymd-His');
}

function rsPlanBase(string $kind, string $what, array $target): array
{
    return ['kind' => $kind, 'what' => $what, 'target' => $target, 'method' => null, 'steps' => [], 'blockers' => [], 'notes' => [],
            'after' => [], 'aside' => [], 'stops' => [], 'downtime' => null, 'sizes' => null, 'options' => null,
            'putback_pre' => [], 'putback_post' => [], 'putback_of' => null];
}

/**
 * What a restore will do, from a fresh look at the server — every id and path checked against it. The
 * preview shows it; the start builds it again and runs it only when its token is the one the user saw
 * (same steps, same names of what goes aside) and nothing blocks it.
 */
function rsPlan(array $r): array
{
    $kind = textField($r, 'kind');
    $stamp = rsStampOf($r);
    $plan = match ($kind) {
        'db'      => rsPlanDb($r, $stamp),
        'sqlite'  => rsPlanSqlite($r, $stamp),
        'files'   => rsPlanFiles($r, $stamp),
        'config'  => rsPlanConfig($r, $stamp),
        'vm'      => rsPlanVm($r, $stamp),
        'kopia'   => rsPlanKopia($r, $stamp),
        'putback' => rsPlanPutback($r, $stamp),
        default   => throw new Problem('unknown_target', ['target' => $kind]),
    };
    return rsPlanSeal($plan, $stamp);
}

/** Who else holds the lock, and the token over what will be done */
function rsPlanSeal(array $plan, string $stamp): array
{
    $plan['stamp'] = $stamp;
    $busy = rsBusy();
    if ($busy) {
        array_unshift($plan['blockers'], $busy);
    }
    $plan['token'] = sha1(jsonEncode([$plan['kind'], $stamp, $plan['target'], $plan['steps']]));
    return $plan;
}

/** An app and its package (tonight's, or an earlier night's from the backup place's snapshots: "version") */
function rsPlanApp(array $r): array
{
    $id = textField($r, 'app');
    [$settings, $ctx, $place] = rsPlanPlace();
    $list = $place['found'] ? rsPackages($place['base'])['apps'] : [];
    $app = array_values(array_filter($list, fn ($p) => $p['id'] === $id))[0] ?? null;
    if (!$app) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    [$pkg, $version] = rsPlanVersion($r, $place, 'app', $app);
    return [$settings, $ctx, $place, $app, $pkg, $version];
}

/** The engine's settings, a fresh look at the server, the backup place — never on a sleeping disk */
function rsPlanPlace(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $place = rsPlace($settings, $ctx);
    if ($place['asleep']) {
        throw new Problem('restore_place_asleep');
    }
    return [$settings, $ctx, $place];
}

/** @return array{0: array, 1: ?array} the package to restore from, and the earlier night it comes from (null: tonight's) */
function rsPlanVersion(array $r, array $place, string $kind, array $current): array
{
    $want = $r['version'] ?? null;
    if (!is_string($want) || $want === '') {
        return [$current, null];
    }
    foreach (rsVersionList($place, $kind, $current['id'], $current['run']) as $v) {
        if ($v['snap'] === $want) {
            $m = readJson("{$v['path']}/manifest.json");
            if ($m) {
                return [$kind === 'app' ? rsAppPackage($m, $current['id'], $v['path']) : rsVmPackage($m, $current['id'], $v['path']), $v];
            }
        }
    }
    throw new Problem('unknown_target', ['target' => $want]);
}

/** The containers on the server right now: name => running (not cached: a plan needs the truth) */
function rsContainersNow(): array
{
    [$exit, $out] = run(['docker', 'ps', '-a', '--format', '{{.Names}}\t{{.State}}'], 20);
    $all = [];
    if ($exit === 0) {
        foreach (rows($out) as $f) {
            if (count($f) >= 2) {
                $all[$f[0]] = $f[1] === 'running';
            }
        }
    }
    return $all;
}

/** The uncompressed size of a .gz (its trailer; exact below 4 GB), 0 when unknown */
function rsGzSize(string $file): int
{
    $size = (int) @filesize($file);
    $h = $size > 18 ? @fopen($file, 'rb') : false;
    if (!$h) {
        return 0;
    }
    fseek($h, -4, SEEK_END);
    $isize = unpack('V', (string) fread($h, 4))[1] ?? 0;
    fclose($h);
    return $isize >= $size ? $isize : 0;          // a wrapped (> 4 GB) size is smaller than the file
}

/** Is a dataset's mountpoint inherited (so a rename moves it along)? */
function rsZfsInherited(string $ds): bool
{
    if (isset($GLOBALS['rs']['inherited'])) {
        return ($GLOBALS['rs']['inherited'])($ds);           // tests: no zfs
    }
    [$exit, $out] = run(['zfs', 'get', '-H', '-o', 'source', 'mountpoint', $ds], 20);
    $src = trim($out);
    return $exit === 0 && ($src === 'default' || str_starts_with($src, 'inherited'));
}

/** Free bytes where a place lies: ZFS by its dataset, else the file system */
function rsFree(array $place): ?int
{
    if (($place['dataset'] ?? null) !== null) {
        [$exit, $out] = run(['zfs', 'list', '-Hp', '-o', 'avail', $place['dataset']], 20);
        return $exit === 0 && ctype_digit(trim($out)) ? (int) trim($out) : null;
    }
    $free = @disk_free_space(dirname($place['live']));
    return $free === false ? null : (int) $free;
}

/** The variables a dump of the package logs in with — names only, and only those of RS_ENV_VARS */
function rsLogin(array $d): array
{
    $ok = fn (string $v): string => in_array($v, RS_ENV_VARS, true) ? $v : '';
    return match ($d['type']) {
        'postgres' => ['login' => 'user', 'user_var' => $ok($d['user_var'] ?? '') ?: 'POSTGRES_USER', 'password_var' => $ok($d['password_var'] ?? '') ?: 'POSTGRES_PASSWORD'],
        'mariadb'  => ($d['login'] ?? '') === 'user' && $ok($d['user_var'] ?? '') !== '' && $ok($d['password_var'] ?? '') !== ''
            ? ['login' => 'user', 'user_var' => $d['user_var'], 'password_var' => $d['password_var']]
            : ['login' => 'root', 'user_var' => '', 'password_var' => $ok($d['password_var'] ?? '') ?: 'MARIADB_ROOT_PASSWORD'],
        'mongodb'  => ($d['login'] ?? '') === 'none' ? ['login' => 'none', 'user_var' => '', 'password_var' => '']
            : ['login' => 'root', 'user_var' => $ok($d['user_var'] ?? '') ?: 'MONGO_INITDB_ROOT_USERNAME', 'password_var' => $ok($d['password_var'] ?? '') ?: 'MONGO_INITDB_ROOT_PASSWORD'],
        default    => throw new Problem('unknown_target', ['target' => (string) $d['type']]),
    };
}

/**
 * The host folder a Postgres container keeps its cluster in — the bind that holds PGDATA — as it lies
 * on its pool or disk, for the fresh way: ['path', 'source', 'base', 'dataset' (its own dataset, or null)],
 * or ['why' => inspect|inside|volume|share|asleep|spread|missing|inner|mountpoint] when there is none to
 * put aside.
 */
function rsDbFolder(string $c, array &$ctx): array
{
    $inspect = houseInspect($c);
    if (!$inspect) {
        return ['why' => 'inspect'];
    }
    $pgdata = '/var/lib/postgresql/data';
    foreach ((array) ($inspect['Config']['Env'] ?? []) as $e) {           // only PGDATA is looked at
        if (is_string($e) && str_starts_with($e, 'PGDATA=') && str_starts_with(substr($e, 7), '/')) {
            $pgdata = rtrim(substr($e, 7), '/');
        }
    }
    $best = null;
    foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
        $dst = rtrim((string) ($m['Destination'] ?? ''), '/');
        if ($dst !== '' && under($pgdata, $dst) && ($best === null || strlen($dst) > strlen(rtrim((string) $best['Destination'], '/')))) {
            $best = $m;
        }
    }
    if (!$best) {
        return ['why' => 'inside'];
    }
    if (($best['Type'] ?? '') !== 'bind') {
        return ['why' => 'volume'];
    }
    $src = rtrim((string) ($best['Source'] ?? ''), '/');
    $places = rsLocate($src, $ctx);
    if (!$places) {
        return ['why' => 'share', 'source' => $src];
    }
    if (array_filter($places, fn ($p) => $p['asleep'])) {
        return ['why' => 'asleep', 'source' => $src];
    }
    $there = array_values(array_filter($places, fn ($p) => $p['exists']));
    if (count($there) !== 1) {
        return ['why' => $there ? 'spread' : 'missing', 'source' => $src];
    }
    $p = $there[0];
    if ($p['inner']) {
        return ['why' => 'inner', 'source' => $src];
    }
    $ds = null;
    if ($p['own_dataset']) {
        if (!rsZfsInherited((string) $p['dataset'])) {
            return ['why' => 'mountpoint', 'source' => $src];
        }
        $ds = $p['dataset'];
    }
    return ['path' => $p['live'], 'source' => $src, 'base' => $p['base'], 'dataset' => $ds, 'fs' => $p['fs'], 'place' => $p];
}

/**
 * Step 2 — a database back from its dump. First a safety dump of what the database holds now (into
 * <backup place>/restore/<app>/<time>/), then the app's other containers stop (the database not).
 *
 * Postgres (the dumps are pg_dumpall of the whole cluster): the fresh way whenever the cluster's folder
 * can be put aside — the database container stops, its folder goes aside as it is, it starts on an empty
 * folder (initdb from its own environment), the dump goes in, then the app starts. That is how
 * PostgreSQL wants a pg_dumpall played back (into a fresh cluster), it is the only way Immich documents,
 * and the old cluster stays untouched aside: «Put back» swaps the folders back. pg_restore doesn't apply
 * (the dumps are plain SQL); dropping and recreating in place is the fallback where the folder can't be put
 * aside (a Docker volume, a folder spread over disks) — never for Immich.
 * MariaDB/MySQL and MongoDB: in place (their dumps drop and recreate what they hold; MariaDB's users live
 * outside the dumps, so a fresh server would lose them), the safety dump being the way back.
 */
function rsPlanDb(array $r, string $stamp): array
{
    [, $ctx, $place, $app, $pkg, $version] = rsPlanApp($r);
    return rsPlanDbFor($ctx, $place, $app, $pkg, $version, textField($r, 'file'), $stamp);
}

/** The plan for one dump of a package (the place: its base; the app: id, name, nextcloud) */
function rsPlanDbFor(array &$ctx, array $place, array $app, array $pkg, ?array $version, string $file, string $stamp): array
{
    $dump = array_values(array_filter($pkg['dumps'], fn ($d) => $d['file'] === $file))[0] ?? null;
    if (!$dump || !preg_match(RS_NAME_PATTERN, $dump['container'])) {
        throw new Problem('unknown_target', ['target' => $file]);
    }
    $c = $dump['container'];
    $type = $dump['type'];
    $path = "{$pkg['path']}/{$dump['file']}";
    $plan = rsPlanBase('db', $app['name'], ['app' => $app['id'], 'file' => $file, 'version' => $version['snap'] ?? null, 'container' => $c, 'type' => $type]);
    $plan['source'] = ['path' => $path, 'time' => $dump['time'], 'bytes' => $dump['bytes'], 'version' => $version['run_time'] ?? null];
    if (!is_file($path)) {
        $plan['blockers'][] = ['key' => 'restore_file_gone', 'params' => ['path' => $path]];
        return $plan;
    }
    if ($type === 'mariadb' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) $dump['db'])) {
        $plan['blockers'][] = ['key' => 'restore_db_name', 'params' => ['file' => $file]];
        return $plan;
    }
    $now = rsContainersNow();
    if (!isset($now[$c])) {
        $plan['blockers'][] = ['key' => 'restore_container_missing', 'params' => ['container' => $c]];
        return $plan;
    }
    $login = rsLogin($dump);
    $immich = (bool) array_filter($pkg['containers'], fn ($x) => stripos($x['image'], 'immich') !== false);
    $others = array_values(array_filter(array_column($pkg['containers'], 'name'), fn ($n) => $n !== $c && isset($now[$n]) && preg_match(RS_NAME_PATTERN, $n)));
    $method = 'inplace';
    $folder = null;
    if ($type === 'postgres') {
        $folder = rsDbFolder($c, $ctx);
        if (isset($folder['path'])) {
            $method = 'fresh';
        } elseif ($immich) {
            $plan['blockers'][] = ['key' => 'restore_immich_folder', 'params' => ['why' => $folder['why'], 'source' => $folder['source'] ?? '']];
        } else {
            $plan['notes'][] = ['key' => 'note.db_no_folder', 'params' => ['why' => $folder['why'], 'source' => $folder['source'] ?? '']];
        }
    }
    $plan['method'] = $method;
    $db = $type === 'mariadb' ? (string) $dump['db'] : null;
    $safety = "{$place['base']}/restore/{$app['id']}/$stamp/" . basename($dump['file']);
    $base = ['type' => $type, 'container' => $c, 'db' => $db] + $login;
    $steps = [];
    if (!$now[$c] && $method === 'inplace') {
        $steps[] = ['do' => 'start', 'containers' => [$c], 'need' => true];
        $steps[] = ['do' => 'ready'] + $base + ['timeout' => 180];
        $plan['notes'][] = ['key' => 'note.db_start', 'params' => ['container' => $c]];
    }
    if ($now[$c] || $method === 'inplace') {
        $steps[] = ['do' => 'dump', 'file' => $safety] + $base;
        $plan['aside'][] = ['what' => 'safety_dump', 'from' => $c, 'to' => $safety];
    } else {
        $plan['notes'][] = ['key' => 'note.db_stopped', 'params' => ['container' => $c]];
    }
    if ($others) {
        $steps[] = ['do' => 'stop', 'containers' => $others];
    }
    if ($method === 'fresh') {
        $aside = "{$folder['path']}.aside-$stamp";
        $ds = $folder['dataset'];
        $steps[] = ['do' => 'stop', 'containers' => [$c]];
        $steps[] = ['do' => 'aside', 'path' => $folder['path'], 'to' => $aside, 'dataset' => $ds, 'to_dataset' => $ds ? "$ds.aside-$stamp" : null];
        $steps[] = ['do' => 'fresh', 'path' => $folder['path'], 'like' => $aside, 'dataset' => $ds];
        $steps[] = ['do' => 'start', 'containers' => [$c], 'need' => true];
        $steps[] = ['do' => 'ready', 'tcp' => true] + $base + ['timeout' => 300];
        $plan['aside'][] = ['what' => 'db_folder', 'from' => $folder['path'], 'to' => $aside];
        $free = rsFree($folder['place']);
        $plan['sizes'] = ['need' => rsGzSize($path) ?: null, 'free' => $free, 'measuring' => false, 'what' => 'db'];
        $plan['putback_pre'] = array_values(array_filter([$others ? ['do' => 'stop', 'containers' => $others] : null, ['do' => 'stop', 'containers' => [$c]]]));
        $plan['putback_post'] = array_values(array_filter([['do' => 'start', 'containers' => [$c], 'need' => true], ['do' => 'ready'] + $base + ['timeout' => 300],
                                                           $others ? ['do' => 'start', 'containers' => $others, 'only_stopped' => true] : null]));
    } else {
        $plan['putback_pre'] = array_values(array_filter([['do' => 'start', 'containers' => [$c], 'need' => true], ['do' => 'ready'] + $base + ['timeout' => 180],
                                                          $others ? ['do' => 'stop', 'containers' => $others] : null]));
        $plan['putback_post'] = $others ? [['do' => 'start', 'containers' => $others, 'only_stopped' => true]] : [];
    }
    $steps[] = ['do' => 'play', 'file' => $path, 'immich' => $immich, 'method' => $method, 'safety' => $safety,
                'putback_file' => "{$place['base']}/restore/{$app['id']}/{T}/" . basename($dump['file'])] + $base;
    $steps[] = ['do' => 'verify', 'file' => $path] + $base;
    if ($others) {
        $steps[] = ['do' => 'start', 'containers' => $others, 'only_stopped' => true];
    }
    $plan['steps'] = $steps;
    $plan['stops'] = array_values(array_filter(array_merge($others, $method === 'fresh' ? [$c] : []), fn ($n) => $now[$n] ?? false));
    $raw = rsGzSize($path) ?: $dump['bytes'] * 5;
    $plan['downtime'] = (int) ($raw / ($type === 'postgres' ? 25e6 : 15e6)) + ($method === 'fresh' ? 60 : 20);
    $plan['notes'][] = ['key' => 'note.method_' . ($method === 'fresh' ? 'fresh' : "inplace_$type"), 'params' => []];
    if ($immich) {
        $plan['notes'][] = ['key' => 'note.immich', 'params' => []];
    }
    if (!array_filter($pkg['containers'], fn ($x) => !in_array($x['db'], ['mariadb', 'postgres', 'mongodb', 'cache'], true))) {
        $plan['notes'][] = ['key' => 'note.db_alone', 'params' => []];      // a database on its own: its apps are elsewhere
    }
    if ($version) {
        $plan['notes'][] = ['key' => 'note.earlier', 'params' => ['when' => $version['run_time'] ?? $version['time']]];
    }
    if ($dump['kept']) {
        $plan['notes'][] = ['key' => 'note.dump_kept', 'params' => ['when' => $dump['time']]];
    }
    if ($app['nextcloud']) {
        $n = array_values(array_filter($app['nextcloud'], fn ($x) => $x['same_as'] === ''))[0] ?? $app['nextcloud'][0];
        $plan['after'][] = ['key' => 'after.nextcloud', 'params' => ['container' => $n['container'], 'occ' => $n['occ'], 'user' => $n['user']]];
    }
    return $plan;
}

/**
 * A media server's database copies (engine 2.19) — all copies of one server together: the server stops,
 * each database and its -wal/-shm go aside next to it, the copy takes its place with the folder's owner,
 * the server starts again if it ran.
 */
function rsPlanSqlite(array $r, string $stamp): array
{
    [, $ctx, , $app, $pkg, $version] = rsPlanApp($r);
    return rsPlanSqliteFor($ctx, $app, $pkg, $version, textField($r, 'container'), $stamp);
}

function rsPlanSqliteFor(array &$ctx, array $app, array $pkg, ?array $version, string $c, string $stamp): array
{
    $copies = array_values(array_filter($pkg['sqlite'], fn ($q) => $q['container'] === $c));
    if (!$copies || !preg_match(RS_NAME_PATTERN, $c)) {
        throw new Problem('unknown_target', ['target' => $c]);
    }
    $plan = rsPlanBase('sqlite', $app['name'], ['app' => $app['id'], 'container' => $c, 'version' => $version['snap'] ?? null]);
    $now = rsContainersNow();
    if (!isset($now[$c])) {
        $plan['blockers'][] = ['key' => 'restore_container_missing', 'params' => ['container' => $c]];
        return $plan;
    }
    $steps = [['do' => 'stop', 'containers' => [$c]]];
    foreach ($copies as $q) {
        $from = "{$pkg['path']}/{$q['file']}";
        $name = basename($q['source']);
        if (!is_file($from) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $name) || !rsSharePath($q['source'], $ctx)) {
            $plan['blockers'][] = ['key' => 'restore_file_gone', 'params' => ['path' => $from]];
            continue;
        }
        // where its folder really lies (a pool or a disk)
        $dir = array_values(array_filter(rsLocate(dirname($q['source']), $ctx), fn ($p) => $p['exists'] || $p['asleep']));
        if (count($dir) !== 1 || $dir[0]['asleep']) {
            $plan['blockers'][] = ['key' => $dir && $dir[0]['asleep'] ? 'restore_asleep' : 'restore_folder_missing', 'params' => ['base' => $dir[0]['base'] ?? '', 'path' => dirname($q['source'])]];
            continue;
        }
        $db = $dir[0]['live'] . "/$name";
        foreach (['', '-wal', '-shm'] as $sfx) {
            $steps[] = ['do' => 'aside', 'path' => "$db$sfx", 'to' => "$db$sfx.aside-$stamp", 'optional' => true];
            if ($sfx === '' || file_exists("$db$sfx")) {
                $plan['aside'][] = ['what' => 'file', 'from' => "$db$sfx", 'to' => "$db$sfx.aside-$stamp"];
            }
        }
        $steps[] = ['do' => 'put', 'from' => $from, 'to' => $db, 'mode' => 0644, 'owner_like' => $dir[0]['live']];
    }
    $steps[] = ['do' => 'start', 'containers' => [$c], 'only_stopped' => true];
    $plan['steps'] = $steps;
    $plan['stops'] = $now[$c] ? [$c] : [];
    $plan['downtime'] = 15;
    $plan['putback_pre'] = [['do' => 'stop', 'containers' => [$c]]];
    $plan['putback_post'] = [['do' => 'start', 'containers' => [$c], 'only_stopped' => true]];
    if ($version) {
        $plan['notes'][] = ['key' => 'note.earlier', 'params' => ['when' => $version['run_time'] ?? $version['time']]];
    }
    $plan['notes'][] = ['key' => 'note.sqlite', 'params' => []];
    return $plan;
}

/** Which app or VM a folder unit (or a whole share it binds) belongs to (from a fresh look), or null */
function rsUnitOwner(array $state, string $path): ?array
{
    foreach ($state['apps'] ?? [] as $a) {
        foreach (array_merge($a['folders'], $a['shares'] ?? []) as $f) {
            if (($f['path'] ?? null) === $path) {
                return ['kind' => 'app', 'name' => $a['name'], 'id' => $a['id'], 'vm_state' => null];
            }
        }
    }
    foreach ($state['vms'] ?? [] as $v) {
        foreach ($v['folders'] as $f) {
            if ($f['path'] === $path) {
                return ['kind' => 'vm', 'name' => $v['name'], 'id' => $v['id'], 'vm_state' => $v['state']];
            }
        }
    }
    return null;
}

/**
 * Step 4 — a folder of an app or VM, or a whole share it binds (all of it or chosen entries at its top), from a
 * moment: a snapshot taken on the share's pools and disks (the engine's on all at once, others maybe on some) —
 * its source is the union of the parts holding it, primary storage first — or what Kopia brought back.
 * It goes back onto the share, not the pool: through /mnt/user/<share>/… (Unraid's shfs places it by the share's
 * settings; an exclusive share's /mnt/user/<share> is the link to its pool). Two ways: «copy» puts the state next
 * to it as <name>.restored-<time> and replaces nothing; «swap» copies it there first (while everything runs), then
 * stops what uses it, puts the live one aside as <name>.aside-<time> (shfs renames it on every pool and disk that
 * has it) and moves the copy into its place — the interruption is seconds. Nothing there (an empty share, a folder
 * that is gone): swap only puts it in place, and is the default. A folder that is a ZFS dataset of its own on one
 * pool stays one (zfs rename, its copy a dataset too). A missing share is never created here: Unraid does that.
 * A sleeping disk is only read with the explicit wake option (then woken first and waited for: rsWake); sizes are
 * measured in the background.
 */
function rsPlanFiles(array $r, string $stamp): array
{
    $path = textField($r, 'path');
    $state = rsScan();
    $owner = rsUnitOwner($state, $path);
    if (!$owner) {
        throw new Problem('unknown_target', ['target' => $path]);
    }
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $ctx['old_shares'] = $state['server']['shares'] ?? null;
    $items = null;
    if (is_array($r['items'] ?? null) && count($r['items']) <= RS_ENTRIES_MAX * 4) {
        $items = array_values(array_unique(array_filter($r['items'], fn ($x) => is_string($x) && $x !== '' && strlen($x) <= 255)));
    }
    $mode = in_array($r['mode'] ?? '', ['copy', 'swap'], true) ? (string) $r['mode'] : '';
    return rsPlanFilesFor($path, is_string($r['snap'] ?? null) ? $r['snap'] : '', $mode, !empty($r['wake']), $owner, $stamp, $ctx, $items);
}

/**
 * The plan of step 4 for a unit /mnt/user/<share>/<folder> or /mnt/user/<share> (whole): $mode '' = the default
 * (swap when nothing is there to replace, else copy); $items = the chosen entries of a whole share (null: all).
 */
function rsPlanFilesFor(string $path, string $momentId, string $mode, bool $wake, array $owner, string $stamp, array &$ctx, ?array $items = null): array
{
    $sp = rsSharePath($path, $ctx);
    if (!$sp || $sp[2] !== null || str_contains($sp[1], '/') || !str_starts_with($path, '/mnt/user/')) {
        throw new Problem('unknown_target', ['target' => $path]);
    }
    [$share, $rel] = $sp;
    $whole = $rel === '';
    $user = rtrim((string) ($ctx['user'] ?? '/mnt/user'), '/');
    $plan = rsPlanBase('files', $owner['name'], ['path' => $path, 'snap' => $momentId, 'mode' => $mode, 'wake' => $wake,
                                                 'owner' => $owner['kind'], 'id' => $owner['id'], 'items' => null]);
    $now = rsShareNow($share, $ctx);
    // asked for («wake» ticked): the share's sleeping parts are woken first — and waited for — then looked into
    $woke = null;
    if ($wake && $now['state'] !== 'missing') {
        $sleeping = array_values(array_filter(rsShareBases($share, $ctx), fn ($b) => baseAsleep((string) $b, $ctx['asleep'] ?? [])));
        $woke = rsWake($sleeping, $ctx);
        if ($woke['woken']) {
            $ctx['now'] = [];
            $now = rsShareNow($share, $ctx);         // its top folders were not looked into while it slept
        }
    }
    $plan['share_now'] = $now;
    $plan['woke'] = $woke;
    $places = rsLocate($path, $ctx);
    $asleep = array_values(array_map(fn ($p) => $p['base'], array_filter($places, fn ($p) => $p['asleep'])));
    // a part still asleep: tick «wake» — or, ticked, it didn't answer
    $sleepy = fn (): array => ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $asleep)]];
    $moments = rsMoments($places, rsKopiaRestored($path, $ctx));
    rsMomentHolds($moments, $whole);
    $plan['options'] = [
        'moments' => array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['name'], 'time' => $m['time'], 'ours' => $m['ours'], 'kopia' => $m['kopia'],
                                           'aside' => $m['aside'] ?? null, 'bases' => array_map('strval', array_keys($m['parts'])), 'holds' => $m['holds']], $moments),
        'parts'   => array_values(array_map(fn ($p) => $p['base'], $places)),
        'asleep'  => $asleep, 'woken' => $woke['woken'] ?? [], 'vm' => $owner['kind'] === 'vm', 'whole' => $whole, 'share' => $share,
        'entries' => null, 'nothing_live' => false,
    ];
    if ($now['state'] === 'missing') {
        $plan['blockers'][] = ['key' => 'restore_share_missing', 'params' => ['share' => $share]];
        $plan['options']['asleep'] = [];            // nothing to wake for a share that isn't there
        return $plan;
    }
    if (!is_dir($user)) {
        $plan['blockers'][] = ['key' => 'restore_no_user_shares', 'params' => []];
        return $plan;
    }

    // the moment: the one asked for, else the newest that holds something of it — never one whose parts hold nothing
    $moment = null;
    foreach ($momentId !== '' ? $moments : [] as $m) {
        $moment ??= $m['id'] === $momentId ? $m : null;
    }
    foreach ($momentId === '' ? [true, false] : [] as $checked) {
        foreach ($moments as $m) {
            $moment ??= ($checked ? ($m['holds'] ?? []) !== [] : $m['holds'] === null) ? $m : null;
        }
    }
    if (!$moment) {
        if ($momentId !== '' && !$asleep) {
            throw new Problem('unknown_target', ['target' => $momentId]);
        }
        // a part that sleeps may hold it: say so (with «wake»), never "nothing there"
        $plan['blockers'][] = $asleep ? $sleepy()
            : ($momentId === '' && $moments ? ['key' => 'restore_not_in_snapshot', 'params' => ['path' => $path]] : ['key' => 'restore_no_snapshot', 'params' => []]);
        return $plan;
    }
    if ($moment['holds'] === null) {
        $one = [$moment];
        rsMomentHolds($one, $whole);
        $moment = $one[0];
    }
    $plan['target']['snap'] = $moment['id'];
    $plan['moment'] = ['id' => $moment['id'], 'name' => $moment['name'], 'time' => $moment['time'], 'ours' => $moment['ours'], 'kopia' => $moment['kopia'],
                       'aside' => $moment['aside'] ?? null, 'parts' => []];
    foreach ($moment['kopia'] ? [] : $places as $p) {
        $b = (string) $p['base'];
        $plan['moment']['parts'][] = ['base' => $b, 'asleep' => $p['asleep'], 'covered' => isset($moment['parts'][$b]), 'holds' => in_array($b, $moment['holds'], true),
            'path' => $moment['parts'][$b]['path'] ?? null, 'content' => !$p['asleep'] && ($whole ? rsDirHasEntries($p['live']) : $p['exists'])];
    }
    if ($moment['kopia']) {
        $plan['moment']['parts'][] = ['base' => 'kopia', 'asleep' => false, 'covered' => true, 'holds' => true, 'path' => $moment['parts']['kopia']['path'], 'content' => false];
    }

    // what comes back: the folder, or the entries at the top of the share in this moment
    if ($whole) {
        $e = rsMomentEntries($moment);
        if ($e['count'] > RS_ENTRIES_MAX) {
            $plan['blockers'][] = ['key' => 'restore_too_many', 'params' => ['n' => $e['count'], 'max' => RS_ENTRIES_MAX]];
            return $plan;
        }
        $names = array_map('strval', array_keys($e['names']));
    } else {
        $names = [$rel];
    }
    $sizes = rsSizes();
    $infos = [];
    foreach ($names as $n) {
        $ip = $whole ? rsItemPlaces($places, $share, $n, $ctx) : $places;
        [$src, $covered] = rsItemSources($moment, $ip, $whole ? "/$n" : '');
        $live = array_values(array_filter($ip, fn ($p) => !$p['asleep'] && $p['exists']));
        // what holds something now: a file, or a folder with entries (an empty one — or one holding only what the
        // office leaves aside, Ms. Dustdevil's storeroom, a ZFS snapshot folder made visible — counts as nothing there)
        $content = array_values(array_map(fn ($p) => (string) $p['base'],
            array_filter($live, fn ($p) => !is_dir($p['live']) || is_link($p['live']) || rsDirHasEntries($p['live']))));
        $bytes = 0;
        $known = (bool) $src;
        foreach ($src as $s) {
            $b = rsSizeOf($sizes, $s['path'])['bytes'] ?? null;
            $known = $known && $b !== null;
            $bytes += (int) $b;
        }
        $infos[$n] = ['places' => $ip, 'sources' => $src, 'covered' => $covered, 'live' => $live, 'content' => $content,
                      'kind' => $src && (!is_dir($src[0]['path']) || is_link($src[0]['path'])) ? 'file' : 'dir', 'bytes' => $known ? $bytes : null];
    }
    if ($whole) {
        $plan['options']['entries'] = array_map(fn ($n) => ['name' => $n, 'kind' => $infos[$n]['kind'], 'bases' => array_column($infos[$n]['sources'], 'base'),
            'live' => (bool) $infos[$n]['content'], 'empty' => $infos[$n]['live'] && !$infos[$n]['content'], 'bytes' => $infos[$n]['bytes'],
            'paths' => array_column($infos[$n]['sources'], 'path')], $names);
        $chosen = $items === null ? $names : array_values(array_intersect($names, $items));
        $plan['target']['items'] = $chosen;
        if (!$names) {
            $plan['blockers'][] = $asleep ? $sleepy() : ['key' => 'restore_not_in_snapshot', 'params' => ['path' => $path]];
            return $plan;
        }
        if (!$chosen) {
            $plan['blockers'][] = ['key' => 'restore_nothing_chosen', 'params' => []];
            return $plan;
        }
    } else {
        $chosen = [$rel];
    }
    // nothing there to replace: gone, or only an empty folder (which goes aside like anything else, never deleted)
    $nothingLive = !array_filter($chosen, fn ($n) => (bool) $infos[$n]['content']);
    $plan['options']['nothing_live'] = $nothingLive;
    if ($mode === '') {
        $mode = $nothingLive || $now['state'] === 'empty' ? 'swap' : 'copy';
    }
    $plan['target']['mode'] = $mode;
    $plan['method'] = $mode;
    if ($now['state'] === 'empty') {
        $plan['notes'][] = ['key' => 'note.files_share_empty', 'params' => ['share' => $share]];
    }

    // per item: where it goes (onto the share, or as the dataset it is), what goes aside, what stops
    $copies = $swaps = $userPaths = $restored = $asides = $emptyAsides = $targets = $union = $uncovered = $paths = $snapOf = $itemPaths = [];
    $userItems = false;
    $dsPlace = null;
    foreach ($chosen as $n) {
        $it = $infos[$n];
        // every way the item is reached (the share, each pool or disk): for the VMs whose disks lie in it
        $itemPaths[] = "/mnt/user/$share/$n";
        foreach ($it['places'] as $p) {
            $itemPaths[] = "/mnt/{$p['base']}/$share/$n";
        }
        if (!$it['sources']) {
            $plan['blockers'][] = $asleep ? $sleepy() : ['key' => 'restore_not_in_snapshot', 'params' => ['path' => "/mnt/user/$share/$n"]];
            continue;
        }
        $own = count($it['live']) === 1 && $it['live'][0]['own_dataset'] ? $it['live'][0] : null;
        $inherited = $own !== null && rsZfsInherited((string) $own['dataset']);
        $dsMode = $own !== null && $inherited;
        $inside = [];
        foreach ($it['live'] as $p) {
            foreach ($ctx['zfs'] as $mp => $dsn) {
                if (under((string) $mp, $p['live']) && !($dsMode && $mp === $p['live'])) {
                    $inside[] = $dsn;
                }
            }
        }
        $T = $dsMode ? $own['live'] : "$user/$share/$n";
        $ds = $dsMode ? (string) $own['dataset'] : null;
        $rds = $ds !== null ? "$ds.restored-$stamp" : null;
        $to = "$T.restored-$stamp";
        $asideP = "$T.aside-$stamp";
        if (file_exists($to) || is_link($to)) {
            $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
        }
        if ($dsMode) {
            $plan['notes'][] = ['key' => 'note.files_dataset', 'params' => ['path' => $T, 'base' => (string) $own['base']]];
            $dsPlace ??= $own;
        } else {
            $userItems = true;
        }
        $src = array_column($it['sources'], 'path');
        $copies[] = ['do' => 'copy', 'from' => implode(' + ', $src), 'sources' => $src, 'to' => $to, 'dataset' => $rds] + ($it['kind'] === 'file' ? ['file' => true] : []);
        $restored[] = $to;
        $targets[] = $T;
        array_push($paths, ...$src);
        foreach ($it['sources'] as $x) {
            $snapOf[$x['path']] = $x['snap'] ?? null;
        }
        if (count($it['sources']) > 1) {
            $union = array_merge($union, array_column($it['sources'], 'base'));
        }
        foreach ($moment['kopia'] ? [] : array_diff($it['content'], $it['covered']) as $b) {
            $uncovered[$b][] = $n;
        }
        if ($mode === 'swap') {
            if ($own !== null && !$inherited) {
                $plan['blockers'][] = ['key' => 'restore_swap_mountpoint', 'params' => ['dataset' => (string) $own['dataset']]];
            } elseif ($inside) {
                $plan['blockers'][] = ['key' => 'restore_swap_inner', 'params' => ['list' => implode(', ', array_unique($inside))]];
            }
            if ($it['live']) {
                $swaps[] = ['do' => 'aside', 'path' => $T, 'to' => $asideP, 'dataset' => $ds, 'to_dataset' => $ds !== null ? "$ds.aside-$stamp" : null];
                $plan['aside'][] = ['what' => $it['content'] ? 'folder' : 'empty', 'from' => $T, 'to' => $asideP];
                $asides[] = $asideP;
                if (!$it['content']) {
                    $emptyAsides[] = $asideP;
                }
                // a dataset put aside takes its snapshots along (zfs rename): the restored one starts without any
                $ownSnaps = $ds !== null ? count($ctx['snaps'][$ds] ?? []) : 0;
                if ($ownSnaps) {
                    $plan['notes'][] = ['key' => 'note.files_dataset_snaps', 'warn' => true,
                                        'params' => ['n' => $ownSnaps, 'path' => $T, 'aside' => $asideP, 'dataset' => "$ds.aside-$stamp"]];
                }
            }
            $swaps[] = ['do' => 'move', 'from' => $to, 'to' => $T, 'dataset' => $rds, 'to_dataset' => $ds];
            $userPaths[] = "/mnt/user/$share/$n";
            foreach ($it['places'] as $p) {
                $userPaths[] = "/mnt/{$p['base']}/$share/$n";
            }
        } elseif ($inside) {
            $plan['notes'][] = ['key' => 'note.files_inner', 'params' => ['list' => implode(', ', array_unique($inside))]];
        }
    }
    if ($asleep && $userItems && !array_intersect(['restore_asleep', 'restore_wake_failed'], array_column($plan['blockers'], 'key'))) {
        // through /mnt/user shfs looks on every disk of the share: never on a sleeping one unasked
        $plan['blockers'][] = $sleepy();
    } elseif ($asleep) {
        $plan['notes'][] = ['key' => 'note.files_asleep', 'params' => ['base' => implode(', ', $asleep)]];
    }
    if ($union) {
        $plan['notes'][] = ['key' => 'note.files_union', 'params' => ['bases' => implode(' + ', array_unique($union))]];
    }
    if ($uncovered) {
        $plan['notes'][] = ['key' => 'note.files_uncovered', 'warn' => true,
                            'params' => ['bases' => implode(', ', array_keys($uncovered)), 'names' => implode(', ', array_unique(array_merge(...array_values($uncovered))))]];
    }
    if ($moment['aside'] ?? null) {
        $plan['notes'][] = ['key' => 'note.files_from_aside', 'params' => ['aside' => (string) $moment['aside']]];
    }

    // the sizes (measured in the background) and the room where Unraid puts it
    foreach ($plan['options']['entries'] ?? [] as $e) {
        foreach ($e['bytes'] === null ? $e['paths'] : [] as $p) {
            rsDuQueue($p);                    // the others listed, for the page's choice
        }
    }
    $space = $userItems ? rsShareSpace($share, $ctx) : null;
    $free = $space !== null ? $space['free'] : ($dsPlace !== null ? rsFree($dsPlace) : null);
    // where it goes: a ZFS dataset that compresses takes about what the source takes on ZFS; anywhere else the copy
    // takes the data's own size (a ZFS source's logicalreferenced share) — rsync --sparse keeps holes holes either way
    $intoDs = $userItems ? rsShareDataset($share, (string) ($space['primary'] ?? ''), $ctx) : ($dsPlace['dataset'] ?? null);
    $ratio = rsSnapRatios(array_values(array_unique(array_filter($snapOf))));
    $scale = [];
    foreach ($snapOf as $p => $snap) {
        if ($snap !== null && ($ratio[$snap] ?? 1.0) > 1.0) {
            $scale[$p] = $ratio[$snap];
        }
    }
    $plan['sizes'] = rsSizesNeed(array_values(array_unique($paths)), $sizes, $scale, $intoDs !== null && rsZfsCompresses($intoDs)) + ['free' => $free, 'what' => 'files'];
    foreach ($plan['sizes']['paths'] as $p) {
        if (rsSizeOf($sizes, $p) === null) {
            rsDuQueue($p);
        }
    }
    $need = (int) $plan['sizes']['need'];
    $measuring = $plan['sizes']['measuring'];
    if (!$measuring && $free !== null && $need > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $need, 'free' => $free]];
    }
    if ($space !== null) {
        $plan['notes'][] = ['key' => $space['secondary'] !== null ? 'note.files_place2' : 'note.files_place',
                            'params' => ['path' => "/mnt/user/$share", 'primary' => $space['primary'], 'secondary' => (string) $space['secondary']]];
        if (!$measuring && $space['secondary'] !== null && $space['primary_free'] !== null && $need > $space['primary_free']) {
            $plan['notes'][] = ['key' => 'note.files_overflow', 'params' => ['primary' => $space['primary'], 'secondary' => $space['secondary']]];
        }
    }

    // the VMs whose disks lie in it: never swapped under a VM that runs (he never stops a VM himself)
    $vms = $mode === 'swap' ? rsVmsUsing(array_values(array_unique($itemPaths))) : [];

    $steps = $copies;
    $plan['notes'][] = ['key' => $mode === 'swap' ? ($nothingLive ? ($emptyAsides ? 'note.files_place_empty' : 'note.files_place_in') : 'note.files_swap') : 'note.files_copy',
                        'params' => ['aside' => implode(', ', $emptyAsides)]];
    if ($mode === 'swap') {
        $blockedVm = [];
        if ($owner['kind'] === 'vm' && !in_array($owner['vm_state'], [null, 'shut off', 'missing'], true)) {
            $plan['blockers'][] = ['key' => 'restore_vm_running', 'params' => ['name' => $owner['name'], 'state' => (string) $owner['vm_state']]];
            $blockedVm[] = $owner['name'];
        }
        foreach ($vms as $v) {
            if ($v['state'] !== 'shut off' && !in_array($v['name'], $blockedVm, true)) {
                $plan['blockers'][] = ['key' => 'restore_vm_uses', 'params' => ['name' => $v['name'], 'state' => $v['state'], 'path' => $v['path']]];
            }
        }
        $off = array_values(array_map(fn ($v) => $v['name'], array_filter($vms, fn ($v) => $v['state'] === 'shut off')));
        if ($off) {
            $plan['notes'][] = ['key' => 'note.files_vms_off', 'params' => ['names' => implode(', ', $off)]];
        }
        // what binds an item (or, for a whole share, the share itself) stops; what binds a folder above it keeps running
        $roots = $whole ? array_merge(["/mnt/user/$share"], array_map(fn ($p) => "/mnt/{$p['base']}/$share", $places)) : [];
        [$users, $parents] = rsUsers(array_values(array_unique($userPaths)), $roots);
        if ($vms) {
            // the copy takes a while: right before anything is replaced, once more — a VM started meanwhile stops the restore
            $steps[] = ['do' => 'vms_off', 'paths' => array_values(array_unique($itemPaths)), 'names' => array_column($vms, 'name')];
        }
        if ($users) {
            $steps[] = ['do' => 'stop', 'containers' => $users];
        }
        $steps = array_merge($steps, $swaps);
        if ($users) {
            $steps[] = ['do' => 'start', 'containers' => $users, 'only_stopped' => true];
            $plan['putback_pre'] = [['do' => 'stop', 'containers' => $users]];
            $plan['putback_post'] = [['do' => 'start', 'containers' => $users, 'only_stopped' => true]];
        }
        if ($parents) {
            $plan['notes'][] = ['key' => 'note.files_parents', 'params' => ['names' => implode(', ', $parents)]];
        }
        $plan['stops'] = $users;
        $plan['downtime'] = $users ? 20 : 0;
        $plan['after'][] = $asides && !$nothingLive ? ['key' => 'after.files_swap', 'params' => ['aside' => implode(', ', $asides)]]
            : ['key' => 'after.files_place', 'params' => ['path' => implode(', ', $targets)]];
        if ($emptyAsides) {
            $plan['after'][] = ['key' => 'after.files_empty_aside', 'params' => ['aside' => implode(', ', $emptyAsides)]];
        }
    } else {
        $plan['after'][] = ['key' => 'after.files_copy', 'params' => ['path' => implode(', ', $restored)]];
    }
    if ($owner['kind'] === 'vm') {
        $plan['target']['vm'] = $owner['name'];
    }
    $plan['steps'] = $steps;
    return $plan;
}

/**
 * A measured size of a path — only one the current du measured (it carries the apparent size too; older entries came
 * from a du that skipped a ZFS snapshot's folder not mounted yet and said 1 KB), else null.
 */
function rsSizeOf(array $sizes, string $path): ?array
{
    $s = $sizes[$path] ?? null;
    return is_array($s) && array_key_exists('apparent', $s) && is_int($s['bytes'] ?? null) ? $s : null;
}

/**
 * What a copy of these sources needs, once all are measured: allocated (du) — on ZFS that is compressed, so for a
 * target that doesn't compress (XFS, btrfs, a ZFS dataset without compression) each ZFS source counts with its
 * snapshot's logicalreferenced/referenced ($scale). Also the apparent size (sparse files: their holes) and the
 * data's own size (logical), for the page; the page sums the same way when sizes come in later.
 */
function rsSizesNeed(array $paths, array $sizes, array $scale, bool $compresses): array
{
    $alloc = $logical = $apparent = 0;
    $measuring = false;
    foreach ($paths as $p) {
        $s = rsSizeOf($sizes, $p);
        if ($s === null) {
            $measuring = true;
            continue;
        }
        $alloc += $s['bytes'];
        $logical += (int) round($s['bytes'] * ($scale[$p] ?? 1.0));
        $apparent += (int) ($s['apparent'] ?? $s['bytes']);
    }
    return ['need' => $measuring ? null : ($compresses ? $alloc : $logical), 'logical' => $measuring ? null : $logical, 'apparent' => $measuring ? null : $apparent,
            'measuring' => $measuring, 'compresses' => $compresses, 'scale' => $scale ?: new stdClass(), 'path' => $paths[0] ?? '', 'paths' => $paths];
}

/** The ZFS dataset of a share on a pool (where Unraid puts something new in it), or null (not ZFS, not known) */
function rsShareDataset(string $share, string $pool, array $ctx): ?string
{
    if ($pool === '' || $pool === 'array' || ($ctx['fs'][$pool] ?? '') !== 'zfs') {
        return null;
    }
    return $ctx['zfs'][($ctx['mnt'] ?? '/mnt') . "/$pool/$share"] ?? null;
}

/** Does a dataset compress what is written into it? */
function rsZfsCompresses(string $ds): bool
{
    if (isset($GLOBALS['rs']['compresses'])) {
        return ($GLOBALS['rs']['compresses'])($ds);         // tests: no zfs
    }
    [$exit, $out] = run(['zfs', 'get', '-H', '-o', 'value', 'compression', $ds], 20);
    return $exit === 0 && !in_array(trim($out), ['', 'off', '-'], true);
}

/**
 * How much bigger the data of ZFS snapshots is than what they take compressed: logicalreferenced / referenced per
 * snapshot (dataset@name), one zfs call; ≥ 1, missing when not known.
 */
function rsSnapRatios(array $snaps): array
{
    $snaps = array_values(array_filter($snaps, fn ($s) => is_string($s) && preg_match('/^[\w.: \/-]+@[\w.: -]+$/D', $s)));
    if (!$snaps) {
        return [];
    }
    if (isset($GLOBALS['rs']['ratios'])) {
        return ($GLOBALS['rs']['ratios'])($snaps);           // tests: no zfs
    }
    [, $out] = run(array_merge(['zfs', 'get', '-Hp', '-o', 'name,property,value', 'referenced,logicalreferenced'], $snaps), 30);
    $v = [];
    foreach (rows($out) as $f) {
        if (count($f) === 3 && ctype_digit($f[2])) {
            $v[$f[0]][$f[1]] = (int) $f[2];
        }
    }
    $out = [];
    foreach ($v as $snap => $x) {
        if (($x['referenced'] ?? 0) > 0 && isset($x['logicalreferenced'])) {
            $out[$snap] = max(1.0, round($x['logicalreferenced'] / $x['referenced'], 3));
        }
    }
    return $out;
}

/**
 * The VMs whose disks (not CD-ROMs or floppies — read-only media) lie at or below one of these paths, however the
 * path is written (/mnt/user/<share>/…, /mnt/user0/…, /mnt/<pool or disk>/<share>/…): name, libvirt's state, the
 * disk. Every VM libvirt knows, also those shut off (the job checks again right before it replaces anything).
 *
 * @return list<array{name:string, state:string, path:string}>
 */
function rsVmsUsing(array $paths): array
{
    $keys = array_values(array_filter(array_map('rsShareKey', $paths)));
    $out = [];
    foreach ($keys ? rsVmDisks() : [] as $name => $vm) {
        foreach ($vm['disks'] as $disk) {
            $dk = rsShareKey($disk);
            foreach ($dk === null ? [] : $keys as $k) {
                if (under($dk, $k)) {
                    $out[$name] = ['name' => (string) $name, 'state' => (string) $vm['state'], 'path' => $disk];
                    continue 3;
                }
            }
        }
    }
    return array_values($out);
}

/** A path in a share as <share>/<rel>, whichever way it is reached (the share, a pool, a disk), null outside the shares */
function rsShareKey(string $path): ?string
{
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(/.*)?$#D', rtrim($path, '/'), $m) || in_array($m[1], ['disks', 'remotes', 'addons', 'rootshare'], true)) {
        return null;
    }
    return $m[2] . ($m[3] ?? '');
}

/**
 * Every VM libvirt knows with its state and the files of its disks (CD-ROMs and floppies left out, backing files of a
 * snapshot chain included): name => {state, disks}. Empty while the VM service is off.
 */
function rsVmDisks(): array
{
    if (isset($GLOBALS['rs']['vm_disks'])) {
        return ($GLOBALS['rs']['vm_disks'])();                // tests: no libvirt
    }
    $out = [];
    foreach (rsVmStates() ?? [] as $name => $state) {
        [$exit, $xml] = run(['virsh', 'dumpxml', (string) $name], 20);
        $dom = $exit === 0 ? @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET) : false;
        $disks = [];
        foreach ($dom ? $dom->xpath('/domain/devices/disk') ?: [] : [] as $d) {
            if (in_array((string) $d['device'], ['cdrom', 'floppy'], true)) {
                continue;
            }
            foreach ($d->xpath('.//source[@file]') ?: [] as $src) {
                $disks[] = (string) $src['file'];
            }
        }
        $out[(string) $name] = ['state' => (string) $state, 'disks' => array_values(array_unique($disks))];
    }
    return $out;
}

/**
 * Folders Kopia brought back (finished kopia restores of his) that hold this folder unit: a share's source
 * holds it at <restored>/<folder> (a whole share: <restored> itself), an app's or VM's own source at
 * <restored>/<share>/<folder>.
 *
 * @return list<array{id:string, name:string, time:int, path:string, ours:bool, kopia:bool}>
 */
function rsKopiaRestored(string $path, array $ctx): array
{
    $sp = rsSharePath($path, $ctx);
    if (!$sp) {
        return [];
    }
    [$share, $rel] = $sp;
    $sub = $rel !== '' ? "/$rel" : '';
    $out = [];
    foreach (rsJournals() as $r) {
        if ($r['kind'] !== 'kopia' || !in_array($r['result'], ['ok', 'warnings'], true)) {
            continue;
        }
        $j = rsJournal($r['id']);
        $host = (string) ($j['steps'][0]['host'] ?? '');
        $source = (string) ($r['target']['source'] ?? '');
        if ($host === '' || !rsCleanPath($host)) {
            continue;
        }
        $cand = str_starts_with($source, '.') ? "$host/$share$sub" : ($source === $share ? "$host$sub" : null);
        if ($cand !== null && is_dir($cand)) {
            $time = (int) (readJson(rsDir($r['id']) . '/plan.json')['source']['time'] ?? 0) ?: (int) $r['finished'];
            $out[] = ['id' => 'kopia:' . $r['id'], 'name' => 'Kopia ' . basename($host), 'time' => $time, 'path' => $cand, 'ours' => false, 'kopia' => true];
        }
    }
    return $out;
}

/**
 * The running containers that bind one of these paths or something inside (they stop for a swap), and
 * those that only bind a folder above it (they keep running, named in the preview) — except one that binds
 * one of $roots or above (a whole share swapped entry by entry: its data changes, it stops too).
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function rsUsers(array $paths, array $roots = []): array
{
    if (isset($GLOBALS['rs']['users'])) {
        return ($GLOBALS['rs']['users'])($paths, $roots);          // tests: no docker
    }
    [$exit, $out] = run(['docker', 'ps', '-q'], 20);
    $ids = array_values(array_filter(explode("\n", trim($out)), fn ($x) => (bool) preg_match('/^[0-9a-f]{6,64}$/D', $x)));
    if ($exit !== 0 || !$ids) {
        return [[], []];
    }
    [$exit, $out] = run(array_merge(['docker', 'inspect'], $ids), 30);
    $users = $parents = [];
    foreach ((array) json_decode($out, true) as $c) {
        $name = ltrim((string) ($c['Name'] ?? ''), '/');
        foreach ((array) ($c['Mounts'] ?? []) as $m) {
            $src = rtrim((string) ($m['Source'] ?? ''), '/');
            if (($m['Type'] ?? '') !== 'bind' || $src === '' || !preg_match(RS_NAME_PATTERN, $name)) {
                continue;
            }
            foreach ($paths as $p) {
                if (under($src, $p)) {
                    $users[$name] = true;
                } elseif (under($p, $src) && substr_count($src, '/') >= 3) {
                    // binds a share or a folder above it (not /mnt or /mnt/user): it keeps running — unless it binds
                    // one of the roots (the whole share being restored): then its data changes, it stops
                    $root = false;
                    foreach ($roots as $r) {
                        $root = $root || under($r, $src);
                    }
                    if ($root) {
                        $users[$name] = true;
                    } else {
                        $parents[$name] = true;
                    }
                }
            }
        }
    }
    return [array_keys($users), array_keys(array_diff_key($parents, $users))];
}

/** Where a file he replaces goes aside: on the flash into RS_FLASH_ASIDE/<stamp>/…, elsewhere next to it */
function rsAsideFor(string $to, string $stamp, string $suffix = 'restored-aside', string $flashRoot = RS_FLASH_ASIDE): string
{
    if (under($to, '/boot/config')) {
        return "$flashRoot/$stamp" . substr($to, strlen('/boot/config'));
    }
    if (under($to, '/boot')) {
        return "$flashRoot/$stamp/boot" . substr($to, strlen('/boot'));
    }
    return "$to.$suffix-$stamp";
}

/** A clean absolute path (no . or .. parts, no control characters) */
function rsCleanPath(string $p): bool
{
    return str_starts_with($p, '/') && !preg_match('#(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]|//#', $p) && strlen($p) < 4096;
}

/**
 * The files of a package that go back into place (step 3): the templates (my-*.xml) to Unraid's
 * templates-user, the Compose Manager's project folder (compose files, .env, Dockerfile, its own small
 * files), and compose files read elsewhere (an indirect stack: compose-files/, by their name where the
 * manifest names exactly one place). Each with how it compares to what is there now.
 *
 * @return list<array{from:string, to:string, what:string, now:string}>
 */
function rsConfigItems(array $pkg, string $composeRoot, string $templates): array
{
    $items = [];
    foreach ($pkg['templates'] as $t) {
        $f = is_array($t) ? $t['file'] : $t;
        if (preg_match('/^my-[^\/]+\.xml$/D', $f)) {
            $items[] = ['from' => "{$pkg['path']}/$f", 'to' => "$templates/$f", 'what' => 'template'];
        }
    }
    $cmp = $pkg['compose'] ?? null;
    if ($cmp && $cmp['dir'] !== '' && preg_match(RS_NAME_PATTERN, $cmp['dir'])) {
        foreach ($cmp['files'] as $f) {
            if (preg_match('/^[^\/]+$/D', $f) && $f !== '.' && $f !== '..') {
                $items[] = ['from' => "{$pkg['path']}/compose/$f", 'to' => "$composeRoot/{$cmp['dir']}/$f", 'what' => 'compose'];
            }
        }
    }
    if ($cmp) {
        $known = array_merge($cmp['config_files'], $cmp['working_dir'] !== '' ? [rtrim($cmp['working_dir'], '/') . '/.env'] : []);
        foreach ($pkg['files'] as $f) {
            if (!str_starts_with($f['path'], 'compose-files/') || substr_count($f['path'], '/') !== 1) {
                continue;
            }
            $name = substr($f['path'], 14);
            $where = array_values(array_unique(array_filter($known, fn ($k) => basename($k) === $name && rsCleanPath($k))));
            if (count($where) === 1 && !($cmp['dir'] !== '' && under($where[0], "$composeRoot/{$cmp['dir']}"))) {
                $items[] = ['from' => "{$pkg['path']}/{$f['path']}", 'to' => $where[0], 'what' => 'compose_file'];
            }
        }
    }
    foreach ($items as &$it) {
        $it['now'] = rsCompare($it['from'], $it['to']);
    }
    return $items;
}

/**
 * Step 3 — templates and compose files back into place: only what is missing or differs; what is there
 * goes aside first (on the flash into RS_FLASH_ASIDE/<time>/, elsewhere next to it as
 * <file>.restored-aside-<time>). He never starts or recreates a container — the preview says what to click.
 */
function rsPlanConfig(array $r, string $stamp): array
{
    [, , , $app, $pkg, $version] = rsPlanApp($r);
    return rsPlanConfigFor($app, $pkg, $version, rsConfigItems($pkg, rsComposeRoot(), RS_TEMPLATES), $stamp);
}

/** The plan for the files of a package (rsConfigItems()); on the flash what he replaces goes to $flashRoot */
function rsPlanConfigFor(array $app, array $pkg, ?array $version, array $items, string $stamp, string $flashRoot = RS_FLASH_ASIDE): array
{
    $plan = rsPlanBase('config', $app['name'], ['app' => $app['id'], 'version' => $version['snap'] ?? null]);
    $steps = [];
    $same = [];
    foreach ($items as $it) {
        if ($it['now'] === 'same') {
            $same[] = $it['to'];
            continue;
        }
        if (!is_file($it['from']) || is_link($it['to']) || is_dir($it['to'])) {
            $plan['blockers'][] = ['key' => 'restore_file_odd', 'params' => ['path' => $it['to']]];
            continue;
        }
        if ($it['now'] === 'differs') {
            $aside = rsAsideFor($it['to'], $stamp, 'restored-aside', $flashRoot);
            $steps[] = ['do' => 'aside', 'path' => $it['to'], 'to' => $aside, 'putback_to' => rsAsideFor($it['to'], '{T}', 'putback', $flashRoot)];
            $plan['aside'][] = ['what' => 'file', 'from' => $it['to'], 'to' => $aside];
        }
        $steps[] = ['do' => 'put', 'from' => $it['from'], 'to' => $it['to'], 'mode' => 0600, 'putback_to' => rsAsideFor($it['to'], '{T}', 'putback', $flashRoot)];
    }
    $plan['steps'] = $steps;
    $plan['options'] = ['same' => $same, 'items' => array_map(fn ($it) => ['to' => $it['to'], 'what' => $it['what'], 'now' => $it['now']], $items)];
    if (!$steps && !$plan['blockers']) {
        $plan['blockers'][] = ['key' => 'restore_config_same', 'params' => []];
    }
    $now = rsContainersNow();
    foreach ($pkg['containers'] as $c) {
        if ($c['template'] !== '' && array_filter($items, fn ($it) => $it['what'] === 'template' && basename($it['to']) === $c['template'] && $it['now'] !== 'same')) {
            $plan['after'][] = isset($now[$c['name']]) ? ['key' => 'after.tpl_apply', 'params' => ['container' => $c['name']]]
                : ['key' => 'after.tpl_add', 'params' => ['container' => $c['name'], 'template' => $c['template']]];
        }
    }
    if ($pkg['compose'] && array_filter($items, fn ($it) => $it['what'] !== 'template' && $it['now'] !== 'same')) {
        $plan['after'][] = ['key' => 'after.compose_up', 'params' => ['project' => $pkg['compose']['project'] ?: $app['name']]];
    }
    if ($version) {
        $plan['notes'][] = ['key' => 'note.earlier', 'params' => ['when' => $version['run_time'] ?? $version['time']]];
    }
    $plan['notes'][] = ['key' => 'note.config', 'params' => []];
    return $plan;
}

/**
 * Step 5 — a VM's configuration from its package: its XML (virsh define), UEFI variables and TPM state.
 * Only while the VM is shut off (or gone) — he never forces anything off. What is there now goes aside
 * inside libvirt.img (/etc/libvirt/_UnraidSecretaryOffice-restore/<time>/<vm>/); its disks come back
 * as a folder from a snapshot (step 4), also only while it is shut off.
 */
function rsPlanVm(array $r, string $stamp): array
{
    $id = textField($r, 'vm');
    [, , $place] = rsPlanPlace();
    $list = $place['found'] ? rsPackages($place['base'])['vms'] : [];
    $vm = array_values(array_filter($list, fn ($p) => $p['id'] === $id))[0] ?? null;
    if (!$vm) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    [$pkg, $version] = rsPlanVersion($r, $place, 'vm', $vm);
    $plan = rsPlanBase('vm', $vm['name'], ['vm' => $id, 'name' => $vm['name'], 'version' => $version['snap'] ?? null]);
    $states = rsVmStates();
    if ($states === null) {
        $plan['blockers'][] = ['key' => 'restore_vm_service_off', 'params' => []];
        return $plan;
    }
    $now = $states[$vm['name']] ?? 'missing';
    if (!in_array($now, ['shut off', 'missing'], true)) {
        $plan['blockers'][] = ['key' => 'restore_vm_running', 'params' => ['name' => $vm['name'], 'state' => $now]];
    }
    if ($pkg['xml'] === '') {
        $plan['blockers'][] = ['key' => 'restore_file_gone', 'params' => ['path' => "{$pkg['path']}/<vm>.xml"]];
        return $plan;
    }
    $lv = RS_LIBVIRT;
    $aside = "$lv/" . RS_LIBVIRT_ASIDE . "/$stamp/$id";
    $back = "$lv/" . RS_LIBVIRT_ASIDE . "/{T}/$id";
    $steps = [];
    if ($now !== 'missing') {
        $steps[] = ['do' => 'dumpxml', 'name' => $vm['name'], 'to' => "$aside/domain.xml"];
        $plan['aside'][] = ['what' => 'xml', 'from' => $vm['name'], 'to' => "$aside/domain.xml"];
    }
    foreach ($pkg['nvram'] as $n) {
        if (preg_match('/S\d{14}_VARS/', $n) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $n)) {
            continue;                                     // an Unraid VM snapshot's variables: only with its chain
        }
        $to = "$lv/qemu/nvram/$n";
        if (file_exists($to)) {
            $steps[] = ['do' => 'aside', 'path' => $to, 'to' => "$aside/nvram/$n", 'putback_to' => "$back/nvram/$n"];
            $plan['aside'][] = ['what' => 'file', 'from' => $to, 'to' => "$aside/nvram/$n"];
        }
        $steps[] = ['do' => 'put', 'from' => "{$pkg['path']}/nvram/$n", 'to' => $to, 'mode' => 0644, 'putback_to' => "$back/nvram/$n"];
    }
    if ($pkg['tpm'] && $pkg['uuid'] !== '') {
        $to = "$lv/qemu/swtpm/tpm-states/{$pkg['uuid']}";
        if (file_exists($to)) {
            $steps[] = ['do' => 'aside', 'path' => $to, 'to' => "$aside/tpm/{$pkg['uuid']}", 'putback_to' => "$back/tpm/{$pkg['uuid']}"];
            $plan['aside'][] = ['what' => 'folder', 'from' => $to, 'to' => "$aside/tpm/{$pkg['uuid']}"];
        }
        $steps[] = ['do' => 'copy', 'from' => "{$pkg['path']}/tpm/{$pkg['uuid']}", 'to' => $to, 'replaces' => true, 'putback_to' => "$back/tpm/{$pkg['uuid']}"];
    }
    $steps[] = ['do' => 'define', 'xml' => "{$pkg['path']}/{$pkg['xml']}", 'name' => $vm['name']];
    if ($pkg['autostart']) {
        $steps[] = ['do' => 'autostart', 'name' => $vm['name']];
    }
    $plan['steps'] = $steps;
    $plan['target']['vm_state'] = $now;
    $disks = array_map(fn ($d) => $d['snapshot'] !== '' ? "{$d['source']} ({$d['snapshot']})" : $d['source'], $pkg['disks']);
    if ($disks) {
        $plan['after'][] = ['key' => 'after.vm_disks', 'params' => ['list' => implode(', ', $disks)]];
    }
    $plan['after'][] = ['key' => 'after.vm_start', 'params' => ['name' => $vm['name']]];
    if ($pkg['hostdev']) {
        $plan['notes'][] = ['key' => 'note.vm_hostdev', 'params' => ['n' => $pkg['hostdev']]];
    }
    if ($version) {
        $plan['notes'][] = ['key' => 'note.earlier', 'params' => ['when' => $version['run_time'] ?? $version['time']]];
    }
    return $plan;
}

/**
 * «Put back»: undoes a finished or failed restore from its journal — what each step recorded when it
 * ran, newest first, between the restore's own handling of its containers (stop before, start after:
 * those that ran before the put back, and those the restore had stopped). Itself a restore: what it
 * replaces goes aside too (<x>.putback-<time>).
 */
function rsPlanPutback(array $r, string $stamp): array
{
    $id = textField($r, 'id');
    $j = rsJournal($id);
    $orig = readJson(rsDir($id) . '/plan.json');
    if (!$j || !$orig) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    $plan = rsPlanBase('putback', (string) $j['what'], ['id' => $id, 'of' => (string) $j['kind']]);
    $plan['putback_of'] = $id;
    if (!rsCanPutback($j)) {
        $plan['blockers'][] = ['key' => is_array($j['putback'] ?? null) ? 'restore_putback_done' : 'restore_putback_not', 'params' => []];
        return $plan;
    }
    $fill = function (mixed $v) use (&$fill, $stamp): mixed {
        return is_array($v) ? array_map($fill, $v) : (is_string($v) ? str_replace('{T}', $stamp, $v) : $v);
    };
    $undo = $fill(rsUndoSteps($j));
    $also = array_values((array) ($j['stopped'] ?? []));
    $withAlso = fn (array $s): array => $s['do'] === 'start' && !empty($s['only_stopped']) ? $s + ['also' => $also] : $s;
    if ($undo) {
        $plan['steps'] = array_merge($fill((array) ($orig['putback_pre'] ?? [])), $undo, array_map($withAlso, $fill((array) ($orig['putback_post'] ?? []))));
    } elseif ($also) {
        $plan['steps'] = [['do' => 'start', 'containers' => $also, 'only_stopped' => true, 'also' => $also]];
    }
    foreach ($undo as $s) {
        if ($s['do'] === 'move' && !file_exists($s['from']) && !rsDatasetExists($s['dataset'] ?? null)) {
            $plan['blockers'][] = ['key' => 'restore_aside_gone', 'params' => ['path' => $s['from']]];
        }
        if ($s['do'] === 'play' && !is_file($s['file'])) {
            $plan['blockers'][] = ['key' => 'restore_aside_gone', 'params' => ['path' => $s['file']]];
        }
        if ($s['do'] === 'aside') {
            $plan['aside'][] = ['what' => 'putback', 'from' => $s['path'], 'to' => $s['to']];
        }
        if ($s['do'] === 'dump') {
            $plan['aside'][] = ['what' => 'safety_dump', 'from' => $s['container'], 'to' => $s['file']];
        }
    }
    $vm = $orig['target']['vm'] ?? ($j['kind'] === 'vm' ? ($orig['target']['name'] ?? null) : null);
    $blockedVm = [];
    if (is_string($vm)) {
        $states = rsVmStates();
        $st = $states === null ? null : ($states[$vm] ?? 'missing');
        if (!in_array($st, [null, 'shut off', 'missing'], true)) {
            $plan['blockers'][] = ['key' => 'restore_vm_running', 'params' => ['name' => $vm, 'state' => $st]];
            $blockedVm[] = $vm;
        }
    }
    // folders put back: never under a VM whose disks lie there and that runs — checked again right before (vms_off)
    if ($j['kind'] === 'files' && $plan['steps']) {
        $touched = [];
        foreach ($undo as $s) {
            foreach ([$s['do'] === 'aside' ? ($s['path'] ?? null) : null, $s['do'] === 'move' ? ($s['to'] ?? null) : null] as $x) {
                if (is_string($x) && $x !== '') {
                    $touched[] = $x;
                }
            }
        }
        $vms = $touched ? rsVmsUsing(array_values(array_unique($touched))) : [];
        foreach ($vms as $v) {
            if ($v['state'] !== 'shut off' && !in_array($v['name'], $blockedVm, true)) {
                $plan['blockers'][] = ['key' => 'restore_vm_uses', 'params' => ['name' => $v['name'], 'state' => $v['state'], 'path' => $v['path']]];
            }
        }
        if ($vms) {
            array_unshift($plan['steps'], ['do' => 'vms_off', 'paths' => array_values(array_unique($touched)), 'names' => array_column($vms, 'name')]);
            $off = array_values(array_map(fn ($v) => $v['name'], array_filter($vms, fn ($v) => $v['state'] === 'shut off')));
            if ($off) {
                $plan['notes'][] = ['key' => 'note.files_vms_off', 'params' => ['names' => implode(', ', $off)]];
            }
        }
    }
    $now = rsContainersNow();
    $plan['stops'] = array_values(array_unique(array_merge(...array_map(fn ($s) => $s['do'] === 'stop' ? array_values(array_filter($s['containers'], fn ($n) => $now[$n] ?? false)) : [],
        $plan['steps'] ?: [['do' => '']]))));
    $plan['downtime'] = $plan['stops'] ? (int) ($orig['downtime'] ?? 30) : 0;
    $plan['method'] = $orig['method'] ?? null;
    if (!$plan['steps']) {
        $plan['blockers'][] = ['key' => 'restore_putback_not', 'params' => []];
    }
    return $plan;
}

/** What a journal's steps recorded to undo them, newest first — a failed step too when it may have changed something */
function rsUndoSteps(array $j): array
{
    $undo = [];
    foreach (array_reverse((array) ($j['steps'] ?? [])) as $s) {
        $u = (array) ($s['undo'] ?? []);
        if ($u && in_array($s['state'] ?? '', ['ok', 'warning', 'failed', 'running'], true)) {
            array_push($undo, ...$u);
        }
    }
    return $undo;
}

function rsDatasetExists(?string $ds): bool
{
    return $ds !== null && $ds !== '' && run(['zfs', 'list', '-H', '-o', 'name', $ds], 20)[0] === 0;
}

// ===================================================================== restoring: starting one

/**
 * Starts a restore the user saw in the preview: the plan is built again from a fresh look, must carry
 * the same token and nothing may block it; then its folder (data/restore/<id>, root only) gets the plan
 * and a journal, and the host's atd runs the job.
 */
function rsStart(array $r): array
{
    $plan = rsPlan($r);
    if (!hash_equals($plan['token'], textField($r, 'token'))) {
        throw new Problem('restore_changed');
    }
    $id = rsLaunch($plan);
    for ($i = 0; $i < 24; $i++) {
        usleep(250000);
        if ((rsJournal($id)['result'] ?? 'queued') !== 'queued') {
            break;
        }
    }
    return ['ok' => true, 'id' => $id, 'journal' => rsJournal($id), 'state' => rsScan()];
}

/** Writes a plan and its journal into a folder of its own (root only) and hands the job to the host's atd — the id; $run false: only written (tests) */
function rsLaunch(array $plan, bool $run = true): string
{
    if ($plan['blockers']) {
        throw new Problem($plan['blockers'][0]['key'], $plan['blockers'][0]['params'] ?? []);
    }
    if (!$plan['steps']) {
        throw new Problem('restore_putback_not');
    }
    $id = $plan['stamp'] . '-' . bin2hex(random_bytes(2));
    rsPrivateDir(rsData());
    rsPrivateDir(rsDir($id));
    writeAtomic(rsDir($id) . '/plan.json', jsonEncode($plan), 0600, 0, 0);
    $j = rsJournalNew($id, $plan);
    rsJournalWrite($j);
    if ($plan['putback_of']) {
        rsMarkPutback($plan['putback_of'], $id, 'queued');
    }
    if (!$run) {
        return $id;
    }
    try {
        hostLaunch('restore-job', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'restore', $id]);
    } catch (Problem $p) {
        $j['result'] = 'refused';
        $j['reason'] = $p->key;
        $j['finished'] = time();
        rsJournalWrite($j);
        if ($plan['putback_of']) {
            rsMarkPutback($plan['putback_of'], $id, 'refused');
        }
        throw $p;
    }
    logLine("Mr. Restori: started $id ({$plan['kind']}: {$plan['what']}) via at");
    return $id;
}

/** A new journal from a plan: every step pending */
function rsJournalNew(string $id, array $plan): array
{
    return [
        'id'         => $id,
        'kind'       => $plan['kind'],
        'what'       => $plan['what'],
        'target'     => $plan['target'],
        'method'     => $plan['method'],
        'created'    => time(),
        'started'    => null,
        'finished'   => null,
        'pid'        => null,
        'result'     => 'queued',
        'reason'     => null,
        'steps'      => array_map(fn ($s) => $s + ['state' => 'pending'], $plan['steps']),
        'aside'      => [],
        'stopped'    => [],
        'safety'     => [],
        'sizes'      => $plan['sizes'],
        'downtime'   => $plan['downtime'],
        'after'      => $plan['after'],
        'putback_of' => $plan['putback_of'],
        'putback'    => null,
    ];
}

/** Notes on a restore that a put back of it started, and how that went */
function rsMarkPutback(string $of, string $id, string $result): void
{
    $j = rsJournal($of);
    if ($j) {
        $j['putback'] = ['id' => $id, 'result' => $result];
        rsJournalWrite($j, false);
    }
}

// ===================================================================== restoring: the job

/**
 * "php agent.php job restore <id>" — run by the host's atd (rsStart), never as a child of the agent.
 * Takes the engine's lock or says who holds it (refused, exit 75 like the engine), then runs the plan's
 * steps one by one, journaling each. At the first step that fails it stops and touches nothing more:
 * «Put back» undoes what was done (each step recorded how).
 */
function rsJob(array $args): int
{
    $id = (string) ($args[0] ?? '');
    if (!preg_match(RS_ID_PATTERN, $id)) {
        fwrite(STDERR, "restore: no such restore\n");
        return 2;
    }
    $j = rsJournal($id);
    $plan = readJson(rsDir($id) . '/plan.json');
    if (!$j || !$plan || ($j['result'] ?? '') !== 'queued') {
        fwrite(STDERR, "restore $id: nothing to do\n");
        return 1;
    }
    $lock = rsLockTake((string) $j['what'], (string) $j['kind']);
    if (!is_resource($lock)) {
        $j['result'] = 'refused';
        $j['reason'] = 'restore_busy_' . (in_array($lock['holder'] ?? '', BACKUP_HOLDERS, true) ? $lock['holder'] : 'other');
        $j['reason_params'] = ['what' => (string) ($lock['what'] ?? ''), 'run' => (string) ($lock['run'] ?? '')];
        $j['finished'] = time();
        rsJournalWrite($j);
        if ($j['putback_of']) {
            rsMarkPutback($j['putback_of'], $id, 'refused');
        }
        rsLog($id, "Refused: the engine's lock is held ({$j['reason']})");
        return 75;
    }
    $GLOBALS['rsStop'] = false;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
            pcntl_signal($sig, function (): void { $GLOBALS['rsStop'] = true; });
        }
    }
    $j['result'] = 'running';
    $j['started'] = time();
    $j['pid'] = getmypid();
    rsJournalWrite($j);
    if ($j['putback_of']) {
        rsMarkPutback($j['putback_of'], $id, 'running');
    }
    rsLog($id, "Restore $id ({$j['kind']}: {$j['what']}), " . count($j['steps']) . ' steps');
    $failed = $warn = false;
    try {
        foreach (array_keys($j['steps']) as $i) {
            if ($failed || $GLOBALS['rsStop']) {
                $j['steps'][$i]['state'] = 'skipped';
                continue;
            }
            $j['steps'][$i]['state'] = 'running';
            $j['steps'][$i]['started'] = time();
            rsJournalWrite($j);
            rsLog($id, sprintf('Step %d/%d: %s', $i + 1, count($j['steps']), rsStepLine($j['steps'][$i])));
            try {
                $res = rsStep($j, $i);
            } catch (Throwable $e) {
                $res = ['state' => 'failed', 'detail' => $e->getMessage()];
            }
            $j['steps'][$i] = array_merge($j['steps'][$i], $res, ['finished' => time()]);
            rsLog($id, "  -> {$res['state']}" . (($res['detail'] ?? '') !== '' ? ': ' . mb_substr((string) $res['detail'], 0, 600) : ''));
            $failed = $res['state'] === 'failed';
            $warn = $warn || $res['state'] === 'warning';
            rsJournalWrite($j);
        }
    } finally {
        $stopped = $GLOBALS['rsStop'];
        $j['result'] = $failed || $stopped ? 'failed' : ($warn ? 'warnings' : 'ok');
        if ($stopped) {
            $j['reason'] = 'restore_stopped';
        }
        $j['finished'] = time();
        rsJournalWrite($j);
        if ($j['putback_of']) {
            rsMarkPutback($j['putback_of'], $id, $j['result']);
        }
        rsLockRelease($lock);
        rsLog($id, "Done: {$j['result']}");
    }
    logLine("Mr. Restori: $id ({$j['kind']}: {$j['what']}) - {$j['result']}");
    return $j['result'] === 'failed' ? 1 : 0;
}

/** A step in one line, for the log */
function rsStepLine(array $s): string
{
    $parts = [$s['do']];
    foreach (['containers', 'container', 'path', 'from', 'to', 'file', 'name', 'xml'] as $k) {
        if (isset($s[$k]) && $s[$k] !== '' && $s[$k] !== null) {
            $parts[] = "$k=" . (is_array($s[$k]) ? implode(',', $s[$k]) : (string) $s[$k]);
        }
    }
    return implode(' ', $parts);
}

function rsStep(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    return match ($s['do']) {
        'stop'      => rsDoStop($j, $s),
        'start'     => rsDoStart($j, $s),
        'dump'      => rsDoDump($j, $s),
        'ready'     => rsDoReady($j, $s),
        'play'      => rsDoPlay($j, $i),
        'verify'    => rsDoVerify($j, $s),
        'aside'     => rsDoAside($j, $s),
        'move'      => rsDoMove($j, $s),
        'fresh'     => rsDoFresh($j, $s),
        'copy'      => rsDoCopy($j, $i),
        'put'       => rsDoPut($j, $s),
        'dumpxml'   => rsDoDumpXml($j, $s),
        'define'    => rsDoDefine($j, $s),
        'undefine'  => rsDoVirsh($j, ['undefine', (string) $s['name'], '--keep-nvram', '--keep-tpm']),
        'autostart' => rsDoVirsh($j, ['autostart', (string) $s['name']]),
        'kopia'     => rsDoKopia($j, $i),
        'vms_off'   => rsDoVmsOff($s),
        default     => ['state' => 'failed', 'detail' => "unknown step {$s['do']}"],
    };
}

function rsEnv(): array
{
    return ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
}

/** A command of a step; what it says on stderr (and a failure) goes into the restore's log */
function rsRun(array $j, array $cmd, int $timeout = 120): array
{
    [$exit, $out, $err] = run($cmd, $timeout);
    if ($exit !== 0 || trim($err) !== '') {
        $short = implode(' ', array_map(fn ($a) => strlen((string) $a) > 100 ? substr((string) $a, 0, 97) . '...' : (string) $a, $cmd));
        rsLog($j['id'], "  $short -> exit $exit" . (trim($err) !== '' ? ': ' . mb_substr(trim($err), 0, 2000) : ''));
    }
    return [$exit, $out, $err];
}

function rsFail(string $note, array $params = [], string $detail = ''): array
{
    return ['state' => 'failed', 'note' => $note, 'params' => $params, 'detail' => $detail];
}

function rsIsRunning(string $c): bool
{
    [$exit, $out] = run(['docker', 'inspect', '-f', '{{.State.Running}}', $c], 20);
    return $exit === 0 && trim($out) === 'true';
}

/** Folders on the way to a file he writes: root only when he makes them */
function rsMkdirs(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0700, true);
}

/**
 * Is a place free for something new? Nothing there — or, for a dataset, the empty folder its old
 * mountpoint may leave behind (ZFS mounts over an empty folder).
 */
function rsFreePlace(string $path, bool $dataset = false): bool
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        return false;
    }
    if (!file_exists($path)) {
        return true;
    }
    return $dataset && is_dir($path) && count(@scandir($path) ?: ['x', 'y', 'z']) === 2;
}

/** Was something put aside from this path in this restore? */
function rsAsideOf(array $j, string $path): ?array
{
    foreach (array_reverse((array) $j['aside']) as $a) {
        if (($a['from'] ?? '') === $path) {
            return $a;
        }
    }
    return null;
}

/** Writes the heartbeat (and progress) every few seconds while a long step runs; true when asked to stop */
function rsBeat(array &$j, ?int $i = null, ?array $progress = null): bool
{
    static $last = 0;
    if ($i !== null && $progress !== null) {
        $j['steps'][$i]['progress'] = $progress;
    }
    if (time() - $last >= 2) {
        $last = time();
        rsJournalWrite($j);
    }
    return !empty($GLOBALS['rsStop']);
}

// --------------------------------------------------------------------- containers

/** Stops the running ones (docker stop: SIGTERM, 60 s); notes which ran, so they start again */
function rsDoStop(array &$j, array $s): array
{
    $stopped = [];
    foreach ((array) $s['containers'] as $c) {
        if (!rsIsRunning($c)) {
            continue;
        }
        [$exit, , $err] = rsRun($j, ['docker', 'stop', '-t', '60', $c], 150);
        if ($exit !== 0) {
            return rsFail('stop_failed', ['container' => $c], trim($err)) + ['stopped' => $stopped];
        }
        $stopped[] = $c;
        if (!in_array($c, $j['stopped'], true)) {
            $j['stopped'][] = $c;
        }
        rsBeat($j);
    }
    return ['state' => 'ok', 'stopped' => $stopped];
}

/** Starts them — with only_stopped just those this restore stopped (and those named in "also": a put back's) */
function rsDoStart(array &$j, array $s): array
{
    $want = (array) $s['containers'];
    if (!empty($s['only_stopped'])) {
        $want = array_values(array_filter($want, fn ($c) => in_array($c, $j['stopped'], true) || in_array($c, (array) ($s['also'] ?? []), true)));
    }
    $started = $bad = [];
    $detail = '';
    foreach ($want as $c) {
        if (rsIsRunning($c)) {
            continue;
        }
        [$exit, , $err] = rsRun($j, ['docker', 'start', $c], 120);
        if ($exit !== 0) {
            $bad[] = $c;
            $detail = trim($err);
            continue;
        }
        $started[] = $c;
    }
    if ($bad) {
        return ['state' => !empty($s['need']) ? 'failed' : 'warning', 'note' => 'start_failed', 'params' => ['containers' => implode(', ', $bad)],
                'detail' => $detail, 'started' => $started];
    }
    return ['state' => 'ok', 'started' => $started];
}

// --------------------------------------------------------------------- databases

/**
 * The script run inside a database container (sh -c): fixed text and variable names from RS_ENV_VARS —
 * the values never leave the container; a database's name comes as $1.
 */
function rsDbScript(string $what, array $s): string
{
    $u = (string) ($s['user_var'] ?? '');
    $p = (string) ($s['password_var'] ?? '');
    foreach ([$u, $p] as $v) {
        if ($v !== '' && !in_array($v, RS_ENV_VARS, true)) {
            throw new Problem('command_failed', ['detail' => 'variable name']);
        }
    }
    switch ($s['type']) {
        case 'postgres':
            $env = 'PGPASSWORD="${' . ($p ?: 'POSTGRES_PASSWORD') . ':-}"';
            $who = '-U "${' . ($u ?: 'POSTGRES_USER') . ':-postgres}"';
            return match ($what) {
                'dump'   => "$env exec pg_dumpall --clean --if-exists $who",
                'play'   => "$env exec psql -X -q -o /dev/null $who -d postgres",     // results away, errors stay
                // a fresh cluster over TCP: during its first start the image's init runs a server on the socket only
                'ready'  => "$env exec psql -X -q -tA" . (!empty($s['tcp']) ? ' -h 127.0.0.1' : '') . " $who -d postgres -c 'select 1'",
                'kick'   => "$env exec psql -X -q -tA $who -d postgres -c \"SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND backend_type = 'client backend'\"",
                // the name from the dump as PGDATABASE, never -d: psql reads a -d with "=" as a connection string
                'tables' => "PGDATABASE=\"\$1\" $env exec psql -X -q -tA $who -c \"SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')\"",
                default  => throw new Problem('command_failed', ['detail' => $what]),
            };
        case 'mariadb':
            $who = ($s['login'] ?? '') === 'user' ? "-u\"\$$u\" -p\"\$$p\"" : '-uroot -p"$' . ($p ?: 'MARIADB_ROOT_PASSWORD') . '"';
            $cli = 'B=mariadb; command -v mariadb >/dev/null 2>&1 || B=mysql; ';
            return match ($what) {
                'dump'   => 'B=mariadb-dump; command -v mariadb-dump >/dev/null 2>&1 || B=mysqldump; exec "$B" ' . $who
                            . ' --single-transaction --quick --hex-blob ' . (($s['login'] ?? '') === 'user' ? '--triggers' : '--routines --triggers --events')
                            . ' --default-character-set=utf8mb4 --add-drop-database --databases "$1"',
                'play'   => $cli . 'exec "$B" ' . $who,
                'ready'  => $cli . 'exec "$B" ' . $who . " -N -B -e 'SELECT 1'",
                'tables' => $cli . 'exec "$B" ' . $who . " -N -B -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\$1' AND table_type = 'BASE TABLE'\"",
                default  => throw new Problem('command_failed', ['detail' => $what]),
            };
        case 'mongodb':
            $who = ($s['login'] ?? '') === 'none' ? '' : " --username \"\$$u\" --password \"\$$p\" --authenticationDatabase admin";
            return match ($what) {
                'dump'  => "exec mongodump --quiet --archive --gzip$who",
                'play'  => "exec mongorestore --quiet --drop --archive --gzip$who",
                'ready' => 'exit 0',
                default => throw new Problem('command_failed', ['detail' => $what]),
            };
    }
    throw new Problem('command_failed', ['detail' => (string) $s['type']]);
}

/** Waits for processes; heartbeat meanwhile; ends them when the job is asked to stop. @return list<int> exit codes */
function rsWait(array &$j, array $procs, ?int $i = null, ?callable $progress = null): array
{
    $codes = array_fill(0, count($procs), -1);
    $open = array_keys($procs);
    $prog = null;
    while ($open) {
        foreach ($open as $k => $n) {
            $st = proc_get_status($procs[$n]);
            if (!$st['running']) {
                $codes[$n] = $st['exitcode'];
                $prog = $progress ? $progress() : null;        // what it said last, before proc_close() frees its pipes
                proc_close($procs[$n]);
                unset($open[$k]);
            }
        }
        $prog = $open && $progress ? $progress() : $prog;
        if ($open && rsBeat($j, $i, $prog)) {
            foreach ($open as $n) {
                proc_terminate($procs[$n]);
            }
        }
        if ($open) {
            usleep(200000);
        }
    }
    return $codes;
}

/**
 * A command's output into a new file, through a filter (gzip) or straight. Its stderr goes to the log.
 * Never over an existing file.
 */
function rsPipe(array &$j, array $cmd, ?array $filter, string $outFile): bool
{
    $log = rsDir($j['id']) . '/log.txt';
    $old = umask(0077);
    $out = @fopen($outFile, 'x');
    umask($old);
    if (!$out) {
        return false;
    }
    $p1 = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $filter ? ['pipe', 'w'] : $out, 2 => ['file', $log, 'a']], $pp1, '/', rsEnv());
    if (!is_resource($p1)) {
        fclose($out);
        return false;
    }
    $procs = [$p1];
    if ($filter) {
        $p2 = proc_open($filter, [0 => $pp1[1], 1 => $out, 2 => ['file', $log, 'a']], $pp2, '/', rsEnv());
        fclose($pp1[1]);
        if (!is_resource($p2)) {
            proc_terminate($p1);
            proc_close($p1);
            fclose($out);
            return false;
        }
        $procs[] = $p2;
    }
    fclose($out);
    $codes = rsWait($j, $procs);
    return !array_filter($codes, fn ($c) => $c !== 0) && empty($GLOBALS['rsStop']);
}

/** The end of a .gz (it reads through: gzip -t first) — for the closing line of a dump */
function rsGzTail(string $file, int $bytes = 8192): ?string
{
    if (run(['gzip', '-t', $file], 7200)[0] !== 0) {
        return null;
    }
    $h = @gzopen($file, 'rb');
    if (!$h) {
        return null;
    }
    $tail = '';
    while (!gzeof($h)) {
        $chunk = gzread($h, 1 << 20);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $tail = substr($tail . $chunk, -$bytes);
    }
    gzclose($h);
    return $tail;
}

/** The safety dump: what the database holds now, made like the engine makes its dumps, checked like them */
function rsDoDump(array &$j, array $s): array
{
    $file = $s['file'];
    if (file_exists($file) || is_link($file)) {
        return rsFail('exists', ['path' => $file]);
    }
    if (!rsMkdirs(dirname($file))) {
        return rsFail('mkdir_failed', ['path' => dirname($file)]);
    }
    $cmd = ['docker', 'exec', $s['container'], 'sh', '-c', rsDbScript('dump', $s), 'sh'];
    if ($s['type'] === 'mariadb') {
        $cmd[] = (string) $s['db'];
    }
    $ok = rsPipe($j, $cmd, $s['type'] === 'mongodb' ? null : ['gzip', '-6'], $file);
    $size = (int) @filesize($file);
    if ($ok && $s['type'] !== 'mongodb') {
        $tail = rsGzTail($file);
        $ok = $tail !== null && str_contains($tail, $s['type'] === 'postgres' ? 'PostgreSQL database cluster dump complete' : 'Dump completed');
    }
    if (!$ok || $size === 0) {
        if (is_file($file)) {
            @rename($file, "$file.incomplete");          // kept, but never taken for a good one
        }
        return rsFail('dump_failed', ['container' => $s['container']]);
    }
    $j['safety'][$s['container']] = $file;
    $j['aside'][] = ['from' => 'db:' . $s['container'], 'to' => $file, 'what' => 'safety_dump'];
    return ['state' => 'ok', 'bytes' => $size];
}

/** Until the database answers (after its first start an image may take a while to set itself up) */
function rsDoReady(array &$j, array $s): array
{
    $script = rsDbScript('ready', $s);
    $t0 = time();
    $until = $t0 + max(10, (int) ($s['timeout'] ?? 180));
    do {
        [$exit, $out] = run(['docker', 'exec', $s['container'], 'sh', '-c', $script], 30);
        if ($exit === 0 && ($s['type'] === 'mongodb' || trim($out) === '1')) {
            return ['state' => 'ok', 'seconds' => time() - $t0];
        }
        if (rsBeat($j)) {
            break;
        }
        sleep(2);
    } while (time() < $until);
    return rsFail('not_ready', ['container' => $s['container'], 'seconds' => time() - $t0]);
}

/**
 * What a dump holds per database: the CREATE TABLE lines per database — Postgres by its \connect lines
 * (pg_dumpall: "\connect -reuse-previous=on "dbname='x'"", older ones "\connect x"), MariaDB one database.
 * Fed with complete lines.
 */
function rsDumpCount(string $lines, ?string &$db, array &$count): void
{
    if (!preg_match_all('/^(\\\\connect\s+.*|CREATE TABLE .*)$/m', $lines, $m)) {
        return;
    }
    foreach ($m[1] as $line) {
        if ($line[0] === '\\') {
            $arg = trim((string) preg_replace('/^\\\\connect\s+(?:-reuse-previous=on\s+)?/', '', $line));
            if (preg_match('/^"dbname=\'((?:[^\']|\'\')*)\'"$/D', $arg, $x)) {
                $db = str_replace("''", "'", $x[1]);
            } elseif (preg_match('/^"((?:[^"]|"")*)"$/D', $arg, $x)) {
                $db = str_replace('""', '"', $x[1]);
            } else {
                $db = $arg;
            }
            continue;
        }
        $key = $db ?? '';
        $count[$key] = ($count[$key] ?? 0) + 1;
    }
}

/**
 * Plays a dump into its container: streamed from the .gz into docker exec -i (for Immich through its
 * documented replacement of the search_path line, done here line by line instead of sed). Postgres in
 * place first ends the other connections (the dump drops and recreates each database). Counts the
 * tables per database on the way for the check. In place, with a safety dump made, it records how
 * «Put back» plays that one back — also when it fails (the database may be half restored).
 */
function rsDoPlay(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $c = $s['container'];
    $file = $s['file'];
    if (!is_file($file)) {
        return rsFail('gone', ['path' => $file]);
    }
    $undo = [];
    $safety = $j['safety'][$c] ?? null;
    if (($s['method'] ?? '') !== 'fresh' && is_string($safety) && is_file($safety) && ($s['putback_file'] ?? '') !== '') {
        $login = array_intersect_key($s, array_flip(['type', 'container', 'db', 'login', 'user_var', 'password_var']));
        $undo = [
            ['do' => 'dump', 'file' => $s['putback_file']] + $login,
            ['do' => 'play', 'file' => $safety, 'immich' => false, 'method' => 'inplace', 'safety' => '', 'putback_file' => ''] + $login,
            ['do' => 'verify', 'file' => $safety] + $login,
        ];
    }
    if ($s['type'] === 'postgres' && ($s['method'] ?? '') !== 'fresh') {
        rsRun($j, ['docker', 'exec', $c, 'sh', '-c', rsDbScript('kick', $s)], 60);
    }
    $log = rsDir($j['id']) . '/log.txt';
    clearstatcache(true, $log);
    $before = (int) @filesize($log);
    $gz = $s['type'] !== 'mongodb';
    $in = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
    if (!$in) {
        return rsFail('gone', ['path' => $file]) + ['undo' => $undo];
    }
    $total = $gz ? rsGzSize($file) : (int) filesize($file);
    $p = proc_open(['docker', 'exec', '-i', $c, 'sh', '-c', rsDbScript('play', $s), 'sh'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, '/', rsEnv());
    if (!is_resource($p)) {
        $gz ? gzclose($in) : fclose($in);
        return rsFail('play_failed', ['container' => $c]) + ['undo' => $undo];
    }
    $from = "SELECT pg_catalog.set_config('search_path', '', false);";
    $to = "SELECT pg_catalog.set_config('search_path', 'public, pg_catalog', true);";
    $done = 0;
    $carry = '';
    $db = null;
    $count = [];
    $broken = false;
    while (true) {
        $data = $gz ? gzread($in, 1 << 20) : fread($in, 1 << 20);
        $end = $data === false || $data === '';
        if (!$end) {
            $done += strlen($data);
        }
        if ($gz) {
            // whole lines only (the replacement and the count work on lines); the rest waits for the next block
            $data = $carry . ($end ? '' : $data);
            $nl = $end ? strlen($data) - 1 : strrpos($data, "\n");
            if ($nl === false) {
                $carry = $data;
                continue;
            }
            $block = substr($data, 0, $nl + 1);
            $carry = substr($data, $nl + 1);
            if (!empty($s['immich'])) {
                $block = str_replace($from, $to, $block);
            }
            rsDumpCount($block, $db, $count);
        } else {
            $block = $end ? '' : $data;
        }
        while ($block !== '') {
            $n = @fwrite($pipes[0], $block);
            if ($n === false || $n === 0) {
                $broken = true;
                break 2;
            }
            $block = (string) substr($block, $n);
        }
        if ($end) {
            break;
        }
        if (rsBeat($j, $i, ['done' => $done, 'total' => $total])) {
            $broken = true;
            break;
        }
    }
    @fclose($pipes[0]);
    $gz ? gzclose($in) : fclose($in);
    $exit = rsWait($j, [$p])[0];
    $j['steps'][$i]['progress'] = ['done' => $done, 'total' => $total];
    if ($gz) {
        $j['expect'][$file] = $count;
    }
    // what the client said: errors that only say something already exists are expected (pg_dumpall into a cluster)
    $said = (string) @file_get_contents($log, false, null, $before);
    $errors = array_values(array_filter(explode("\n", $said), fn ($l) => str_contains($l, 'ERROR') || str_contains($l, 'error')));
    $real = array_values(array_filter($errors, fn ($l) => !preg_match('/already exists|current user cannot be dropped|cannot drop the currently open database/', $l)));
    $detail = implode("\n", array_slice($real, 0, 6));
    if ($broken || $exit !== 0) {
        return rsFail('play_failed', ['container' => $c, 'exit' => $exit], $detail) + ['undo' => $undo, 'errors' => count($real)];
    }
    if ($real) {
        return ['state' => 'warning', 'note' => 'play_errors', 'params' => ['n' => count($real)], 'detail' => $detail, 'undo' => $undo, 'errors' => count($real)];
    }
    return ['state' => 'ok', 'undo' => $undo, 'errors' => 0, 'expected' => count($errors)];
}

/** Counts a dump's tables per database (when the play didn't already) */
function rsDumpTables(string $file): ?array
{
    $h = @gzopen($file, 'rb');
    if (!$h) {
        return null;
    }
    $carry = '';
    $db = null;
    $count = [];
    while (!gzeof($h)) {
        $data = gzread($h, 1 << 20);
        if ($data === false || $data === '') {
            break;
        }
        $data = $carry . $data;
        $nl = strrpos($data, "\n");
        if ($nl === false) {
            $carry = $data;
            continue;
        }
        rsDumpCount(substr($data, 0, $nl + 1), $db, $count);
        $carry = substr($data, $nl + 1);
    }
    rsDumpCount($carry, $db, $count);
    gzclose($h);
    return $count;
}

/**
 * Checks the database against its dump: per database as many tables as the dump creates. None where
 * the dump had some: failed; another number: a warning (unlogged or foreign tables count differently).
 */
function rsDoVerify(array &$j, array $s): array
{
    if ($s['type'] === 'mongodb') {
        return ['state' => 'ok', 'note' => 'verify_none'];
    }
    $expect = $j['expect'][$s['file']] ?? rsDumpTables($s['file']);
    if ($expect === null) {
        return ['state' => 'warning', 'note' => 'verify_unread'];
    }
    if ($s['type'] === 'mariadb') {
        $expect = [(string) $s['db'] => array_sum($expect)];
    }
    $expect = array_filter($expect, fn ($n, $db) => $n > 0 && $db !== '' && !in_array($db, ['template0', 'template1'], true), ARRAY_FILTER_USE_BOTH);
    $seen = $empty = $differ = [];
    foreach ($expect as $db => $n) {
        if (preg_match('#[=\x00-\x1f]|://#', (string) $db)) {
            $differ[] = "$db: ?/$n";            // no plain name: not asked for
            continue;
        }
        [$exit, $out] = rsRun($j, ['docker', 'exec', $s['container'], 'sh', '-c', rsDbScript('tables', $s), 'sh', (string) $db], 120);
        $have = $exit === 0 && ctype_digit(trim($out)) ? (int) trim($out) : null;
        $seen[] = "$db: " . ($have ?? '?') . "/$n";
        if (!$have) {
            $empty[] = "$db: " . ($have ?? '?') . "/$n";
        } elseif ($have !== $n) {
            $differ[] = "$db: $have/$n";
        }
    }
    $params = ['tables' => implode(', ', $seen)];
    if ($empty) {
        return rsFail('verify_empty', ['tables' => implode(', ', $empty)]);
    }
    return $differ ? ['state' => 'warning', 'note' => 'verify_differs', 'params' => ['tables' => implode(', ', $differ)]]
        : ['state' => 'ok', 'note' => 'verify_ok', 'params' => $params];
}

// --------------------------------------------------------------------- files, folders, datasets

/** A dataset's local settings worth keeping on a new one beside it (recordsize, compression …) */
function rsZfsCreate(array $j, string $ds, ?string $like): bool
{
    $opts = [];
    if ($like !== null && $like !== '') {
        [$exit, $out] = run(['zfs', 'get', '-H', '-s', 'local', '-o', 'property,value',
            'recordsize,compression,atime,xattr,logbias,sync,primarycache,secondarycache,acltype,aclinherit,dnodesize,special_small_blocks', $like], 20);
        foreach ($exit === 0 ? rows($out) : [] as $f) {
            if (count($f) === 2 && preg_match('/^[a-z_]+$/D', $f[0]) && preg_match('/^[A-Za-z0-9._-]+$/D', $f[1])) {
                array_push($opts, '-o', "$f[0]=$f[1]");
            }
        }
    }
    return rsRun($j, array_merge(['zfs', 'create'], $opts, [$ds]), 60)[0] === 0;
}

/**
 * Puts something aside: renamed on the same file system (a dataset with zfs rename, its snapshots go
 * along) — never copied and deleted. Records how «Put back» brings it back: whatever is at its place
 * then goes aside itself (<x>.putback-<time>), and this goes back.
 */
function rsDoAside(array &$j, array $s): array
{
    $from = $s['path'];
    $to = $s['to'];
    $ds = $s['dataset'] ?? null;
    $tds = $s['to_dataset'] ?? null;
    $isDs = $ds !== null && $tds !== null && rsDatasetExists($ds);
    clearstatcache();
    if (!$isDs && !file_exists($from) && !is_link($from)) {
        return !empty($s['optional']) ? ['state' => 'skipped', 'note' => 'nothing_there'] : rsFail('gone', ['path' => $from]);
    }
    if (!rsFreePlace($to, $isDs) || ($isDs && rsDatasetExists($tds))) {
        return rsFail('exists', ['path' => $to]);
    }
    if ($isDs) {
        [$exit, , $err] = rsRun($j, ['zfs', 'rename', $ds, $tds], 300);
        if ($exit !== 0) {
            return rsFail('rename_failed', ['path' => $from], trim($err));
        }
    } else {
        if (!rsMkdirs(dirname($to))) {
            return rsFail('mkdir_failed', ['path' => dirname($to)]);
        }
        if (!@rename($from, $to)) {
            return rsFail('rename_failed', ['path' => $from], (string) (error_get_last()['message'] ?? ''));
        }
    }
    $j['aside'][] = ['from' => $from, 'to' => $to, 'dataset' => $isDs ? $ds : null, 'to_dataset' => $isDs ? $tds : null];
    $back = ($s['putback_to'] ?? '') !== '' ? $s['putback_to'] : "$from.putback-{T}";
    return ['state' => 'ok', 'undo' => [
        ['do' => 'aside', 'path' => $from, 'to' => $back, 'dataset' => $isDs ? $ds : null, 'to_dataset' => $isDs ? "$ds.putback-{T}" : null, 'optional' => true],
        ['do' => 'move', 'from' => $to, 'to' => $from, 'dataset' => $isDs ? $tds : null, 'to_dataset' => $isDs ? $ds : null],
    ]];
}

/** Moves something into a place that is free (a restored copy into the live folder's place, an aside back) */
function rsDoMove(array &$j, array $s): array
{
    $from = $s['from'];
    $to = $s['to'];
    $fds = $s['dataset'] ?? null;
    $tds = $s['to_dataset'] ?? null;
    $isDs = $fds !== null && $tds !== null && rsDatasetExists($fds);
    clearstatcache();
    if (!$isDs && !file_exists($from)) {
        return rsFail('gone', ['path' => $from]);
    }
    if (!rsFreePlace($to, $isDs) || ($isDs && rsDatasetExists($tds))) {
        return rsFail('exists', ['path' => $to]);
    }
    if ($isDs) {
        [$exit, , $err] = rsRun($j, ['zfs', 'rename', $fds, $tds], 300);
        if ($exit !== 0) {
            return rsFail('rename_failed', ['path' => $from], trim($err));
        }
    } elseif (!@rename($from, $to)) {
        return rsFail('rename_failed', ['path' => $from], (string) (error_get_last()['message'] ?? ''));
    }
    // something new where nothing was put aside in this restore: «Put back» puts it aside
    $undo = rsAsideOf($j, $to) ? [] : [['do' => 'aside', 'path' => $to, 'to' => "$to.putback-{T}", 'dataset' => $isDs ? $tds : null,
                                         'to_dataset' => $isDs ? "$tds.putback-{T}" : null, 'optional' => true]];
    return ['state' => 'ok', 'undo' => $undo];
}

/** An empty folder (or dataset) where one was put aside, with its owner and mode (a database starts afresh in it) */
function rsDoFresh(array &$j, array $s): array
{
    $path = $s['path'];
    $ds = $s['dataset'] ?? null;
    if (!rsFreePlace($path, $ds !== null)) {
        return rsFail('exists', ['path' => $path]);
    }
    $like = rsAsideOf($j, $path);
    if ($ds !== null) {
        if (rsDatasetExists($ds) || !rsZfsCreate($j, $ds, $like['to_dataset'] ?? null) || !is_dir($path)) {
            return rsFail('mkdir_failed', ['path' => $path]);
        }
    } elseif (!is_dir($path) && !@mkdir($path, 0700)) {
        return rsFail('mkdir_failed', ['path' => $path]);
    }
    $st = @stat((string) ($s['like'] ?? ''));
    if ($st) {
        @chown($path, $st['uid']);
        @chgrp($path, $st['gid']);
        @chmod($path, $st['mode'] & 07777);
    }
    $undo = $like ? [] : [['do' => 'aside', 'path' => $path, 'to' => "$path.putback-{T}", 'dataset' => $ds, 'to_dataset' => $ds ? "$ds.putback-{T}" : null, 'optional' => true]];
    return ['state' => 'ok', 'undo' => $undo];
}

/**
 * Copies a folder (a snapshot's state, a package's TPM state) to a new place with rsync — owners,
 * modes, times, hard links and extended attributes kept; its progress in the journal. Several sources
 * (the parts of a share on its pools and disks) are put together into one: rsync merges the folders, and
 * for a name in two of them the first source's wins (the primary storage's, as shfs shows it). A single
 * file (an entry at the top of a share) is copied from its first source. As a dataset of its own when the
 * folder it stands in for is one (with that one's local settings).
 */
function rsDoCopy(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $file = !empty($s['file']);
    $sources = array_values(array_map(fn ($x) => rtrim((string) $x, '/'), (array) ($s['sources'] ?? [$s['from']])));
    $to = $s['to'];
    $ds = $file ? null : ($s['dataset'] ?? null);
    clearstatcache();
    foreach ($sources as $from) {
        if ($from === '' || ($file ? !file_exists($from) && !is_link($from) : !is_dir($from))) {
            return rsFail('gone', ['path' => $from]);
        }
    }
    if (!$sources) {
        return rsFail('gone', ['path' => (string) ($s['from'] ?? '')]);
    }
    if (!rsFreePlace($to, $ds !== null)) {
        return rsFail('exists', ['path' => $to]);
    }
    if ($file) {
        if (!rsMkdirs(dirname($to))) {
            return rsFail('mkdir_failed', ['path' => dirname($to)]);
        }
    } elseif ($ds !== null) {
        $like = preg_replace('/\.restored-\d{8}-\d{6}$/D', '', $ds);
        if (rsDatasetExists($ds) || !rsZfsCreate($j, $ds, rsDatasetExists($like) ? $like : null) || !is_dir($to)) {
            return rsFail('mkdir_failed', ['path' => $to]);
        }
    } elseif (!rsMkdirs(dirname($to)) || !@mkdir($to, 0700)) {
        return rsFail('mkdir_failed', ['path' => $to]);
    }
    $log = rsDir($j['id']) . '/log.txt';
    $args = $file ? [$sources[0], $to] : array_merge(array_map(fn ($x) => "$x/", $sources), ["$to/"]);
    // --sparse: a VM disk's holes stay holes (without it a 108 GB vdisk holding 16 GB is written out in full on XFS or
    // btrfs); a local copy is a whole-file copy, so it always works — never together with --inplace
    $p = proc_open(array_merge(['rsync', '-aHX', '--sparse', '--numeric-ids', '--info=progress2', '--no-inc-recursive'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']], $pipes, '/', rsEnv());
    if (!is_resource($p)) {
        return rsFail('copy_failed', ['path' => $to]);
    }
    stream_set_blocking($pipes[1], false);
    $total = (int) ($j['sizes']['need'] ?? 0) ?: null;
    $prog = ['done' => 0, 'total' => $total, 'percent' => null];
    $buf = '';
    $read = function () use ($pipes, &$buf, &$prog): array {
        $chunk = is_resource($pipes[1]) ? (string) @fread($pipes[1], 65536) : '';
        if ($chunk !== '') {
            $buf = substr($buf . $chunk, -4096);
            $parts = preg_split('/[\r\n]+/', trim($buf)) ?: [];
            if (preg_match('/^\s*([\d,.]+)\s+(\d+)%/', (string) end($parts), $m)) {
                $prog['done'] = (int) str_replace([',', '.'], '', $m[1]);
                $prog['percent'] = (int) $m[2];
            }
        }
        return $prog;
    };
    $exit = rsWait($j, [$p], $i, $read)[0];
    $j['steps'][$i]['progress'] = $prog;
    $undo = !empty($s['replaces']) && !rsAsideOf($j, $to)
        ? [['do' => 'aside', 'path' => $to, 'to' => ($s['putback_to'] ?? '') !== '' ? $s['putback_to'] : "$to.putback-{T}", 'optional' => true]] : [];
    if ($exit === 24) {
        return ['state' => 'warning', 'note' => 'copy_vanished', 'undo' => $undo];
    }
    if ($exit !== 0) {
        return rsFail('copy_failed', ['path' => $to, 'exit' => $exit], 'rsync exit ' . $exit) + ['undo' => $undo];
    }
    return ['state' => 'ok', 'undo' => $undo];
}

/**
 * A file from a package into its place (which is free: what was there went aside first) — through a new
 * file beside it and a rename, never through a link. Owner and mode like what was there before, the
 * owner of its folder where asked (a media server's database), else root.
 */
function rsDoPut(array &$j, array $s): array
{
    $from = $s['from'];
    $to = $s['to'];
    clearstatcache();
    if (!is_file($from) || is_link($from)) {
        return rsFail('gone', ['path' => $from]);
    }
    if (file_exists($to) || is_link($to)) {
        return rsFail('exists', ['path' => $to]);
    }
    if (!rsMkdirs(dirname($to))) {
        return rsFail('mkdir_failed', ['path' => dirname($to)]);
    }
    $tmp = dirname($to) . '/.' . basename($to) . '.uso-' . bin2hex(random_bytes(4));
    $old = umask(0077);
    $ok = @copy($from, $tmp);
    umask($old);
    if (!$ok) {
        @unlink($tmp);
        return rsFail('copy_failed', ['path' => $to]);
    }
    $aside = rsAsideOf($j, $to);
    $was = $aside ? @stat($aside['to']) : false;
    $own = ($s['owner_like'] ?? '') !== '' ? @stat($s['owner_like']) : $was;
    @chown($tmp, $own ? $own['uid'] : 0);
    @chgrp($tmp, $own ? $own['gid'] : 0);
    @chmod($tmp, $was ? ($was['mode'] & 0777) : (int) ($s['mode'] ?? 0600));
    @touch($tmp, (int) @filemtime($from));
    if (!@rename($tmp, $to)) {
        @unlink($tmp);
        return rsFail('rename_failed', ['path' => $to]);
    }
    $undo = $aside ? [] : [['do' => 'aside', 'path' => $to, 'to' => ($s['putback_to'] ?? '') !== '' ? $s['putback_to'] : "$to.putback-{T}", 'optional' => true]];
    return ['state' => 'ok', 'undo' => $undo];
}

// --------------------------------------------------------------------- VMs

/**
 * Right before a folder holding VM disks is replaced (or put back): no VM using it may run — he never stops a VM
 * himself, so a VM started during the copy stops the restore here, before anything is replaced.
 */
function rsDoVmsOff(array $s): array
{
    $on = array_values(array_filter(rsVmsUsing((array) ($s['paths'] ?? [])), fn ($v) => $v['state'] !== 'shut off'));
    if ($on) {
        return rsFail('vm_running', ['names' => implode(', ', array_map(fn ($v) => "{$v['name']} ({$v['state']})", $on))]);
    }
    return ['state' => 'ok'];
}

/** A VM's definition as libvirt keeps it, put aside before another one is defined */
function rsDoDumpXml(array &$j, array $s): array
{
    [$exit, $out, $err] = rsRun($j, ['virsh', 'dumpxml', '--inactive', '--security-info', (string) $s['name']], 60);
    if ($exit !== 0 || !str_contains($out, '<domain')) {
        return rsFail('virsh_failed', ['name' => $s['name']], trim($err));
    }
    $to = $s['to'];
    if (file_exists($to) || is_link($to) || !rsMkdirs(dirname($to))) {
        return rsFail('exists', ['path' => $to]);
    }
    $old = umask(0077);
    $h = @fopen($to, 'x');
    umask($old);
    if (!$h || fwrite($h, $out) !== strlen($out)) {
        return rsFail('copy_failed', ['path' => $to]);
    }
    fclose($h);
    $j['aside'][] = ['from' => 'vm:' . $s['name'], 'to' => $to, 'what' => 'xml'];
    $j['xml_aside'][(string) $s['name']] = $to;
    return ['state' => 'ok'];
}

/** virsh define: «Put back» defines the one put aside again, or removes the definition when there was none */
function rsDoDefine(array &$j, array $s): array
{
    $res = rsDoVirsh($j, ['define', (string) $s['xml']]);
    $name = (string) $s['name'];
    $res['undo'] = isset($j['xml_aside'][$name]) ? [['do' => 'define', 'xml' => $j['xml_aside'][$name], 'name' => $name]] : [['do' => 'undefine', 'name' => $name]];
    return $res;
}

function rsDoVirsh(array &$j, array $args): array
{
    [$exit, , $err] = rsRun($j, array_merge(['virsh'], $args), 60);
    return $exit === 0 ? ['state' => 'ok'] : rsFail('virsh_failed', ['name' => (string) ($args[1] ?? '')], trim($err));
}

// --------------------------------------------------------------------- Kopia

/** A Kopia snapshot into the writable folder of the Kopia container — its output as progress */
function rsDoKopia(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    clearstatcache();
    if (file_exists($s['host']) || is_link($s['host'])) {
        return rsFail('exists', ['path' => $s['host']]);
    }
    $log = rsDir($j['id']) . '/log.txt';
    $p = proc_open(rsKopiaCmd((string) $s['container'], (int) ($s['uid'] ?? 0), ['snapshot', 'restore', (string) $s['snapshot'], (string) $s['dest']]),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', rsEnv());
    if (!is_resource($p)) {
        return rsFail('kopia_failed', ['snapshot' => $s['snapshot']]);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $line = '';
    $read = function () use ($pipes, &$line, $log): array {
        foreach ([1, 2] as $n) {
            $chunk = is_resource($pipes[$n]) ? (string) @fread($pipes[$n], 65536) : '';
            if ($chunk !== '') {
                @file_put_contents($log, str_replace("\r", "\n", $chunk), FILE_APPEND);
                $parts = preg_split('/[\r\n]+/', trim($chunk)) ?: [];
                $last = trim((string) end($parts));
                if ($last !== '') {
                    $line = mb_substr($last, 0, 200);
                }
            }
        }
        return ['line' => $line];
    };
    $exit = rsWait($j, [$p], $i, $read)[0];
    $j['steps'][$i]['progress'] = ['line' => $line];
    return $exit === 0 ? ['state' => 'ok'] : rsFail('kopia_failed', ['snapshot' => $s['snapshot'], 'exit' => $exit], $line);
}

/**
 * Kopia inside its container always as the user its server runs as (like the engine's kopia_x): a call as
 * root next to a server of another user leaves root-owned cache folders the server can't open any more.
 */
function rsKopiaUid(string $container): int
{
    [$exit, $out] = run(['docker', 'top', $container, '-eo', 'pid,uid,args'], 20);
    foreach ($exit === 0 ? explode("\n", $out) : [] as $line) {
        if (preg_match('#^\s*\d+\s+(\d+)\s+(?:\S*/)?kopia\s+server\b#', $line, $m)) {
            return (int) $m[1];
        }
    }
    [$exit, $out] = run(['docker', 'exec', $container, 'sh', '-c', 'stat -c %u "${KOPIA_CONFIG_PATH:-$HOME/.config/kopia/repository.config}"'], 20);
    return $exit === 0 && ctype_digit(trim($out)) ? (int) trim($out) : 0;
}

/** docker exec of a kopia command as that user */
function rsKopiaCmd(string $container, int $uid, array $args): array
{
    return array_merge(['docker', 'exec', '-u', (string) $uid], $uid !== 0 ? ['-e', 'HOME=/tmp'] : [], [$container, 'kopia'], $args);
}

/** The Kopia sources Mr. Restori offers: shares that go to Kopia, apps and VMs with a source of their own (relative to the root) */
function rsKopiaSources(array $state): array
{
    $out = [];
    foreach ((array) ($state['shares'] ?? []) as $s) {
        if (($s['mode'] ?? '') === 'kopia' && empty($s['flash']) && preg_match('/^[\w .-]+$/D', (string) $s['name'])) {
            $out[] = (string) $s['name'];
        }
    }
    foreach (['apps', 'vms'] as $k) {
        foreach ((array) ($state[$k] ?? []) as $x) {
            foreach ((array) ($x['kopia'] ?? []) as $src) {
                if (!empty($src['own'])) {
                    $out[] = (string) $src['source'];
                }
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * `kopia snapshot list --json` as the page needs it, newest first. What a snapshot holds is its root's summary
 * (rootEntry.summ: files, dirs, size) — stats.fileCount counts only the files read anew in that run (cached ones not),
 * 2 files for a night that changed little; it is only the fallback, with cachedFiles + nonCachedFiles before it.
 */
function rsKopiaParse(string $json): array
{
    $out = [];
    $int = fn (mixed $v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    foreach ((array) json_decode($json, true) as $s) {
        $id = is_array($s) ? (string) ($s['id'] ?? '') : '';
        if (!preg_match('/^[0-9a-f]{16,64}$/D', $id)) {
            continue;
        }
        $text = fn (mixed $v): string => is_string($v) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v)), 0, 120) : '';
        $summ = is_array($s['rootEntry']['summ'] ?? null) ? $s['rootEntry']['summ'] : [];
        $st = is_array($s['stats'] ?? null) ? $s['stats'] : [];
        $read = $int($st['cachedFiles'] ?? null) !== null && $int($st['nonCachedFiles'] ?? null) !== null
            ? $int($st['cachedFiles']) + $int($st['nonCachedFiles']) : null;
        $out[] = ['id' => $id, 'time' => (int) (strtotime((string) ($s['startTime'] ?? '')) ?: 0), 'end' => (int) (strtotime((string) ($s['endTime'] ?? '')) ?: 0),
                  'bytes' => $int($summ['size'] ?? null) ?? $int($st['totalSize'] ?? null) ?? 0,
                  'files' => $int($summ['files'] ?? null) ?? $read ?? $int($st['fileCount'] ?? null) ?? 0,
                  'dirs' => $int($summ['dirs'] ?? null) ?? $int($st['dirCount'] ?? null),
                  'failed' => $int($summ['numFailed'] ?? null) ?? $int($st['errorCount'] ?? null) ?? 0,
                  'description' => $text($s['description'] ?? ''), 'incomplete' => $text($s['incompleteReason'] ?? '')];
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** The Kopia container and the snapshots of one source (it asks the repository: seconds) */
function rsKopiaSnapshots(array $k, string $source): array
{
    if (!$k['container'] || !$k['running']) {
        throw new Problem($k['container'] ? 'restore_kopia_stopped' : 'restore_kopia_none', ['name' => (string) $k['container']]);
    }
    if (!$k['root']) {
        throw new Problem('restore_kopia_root');
    }
    [$exit, $out, $err] = run(rsKopiaCmd($k['container'], rsKopiaUid($k['container']), ['--no-progress', 'snapshot', 'list', "{$k['root']}/$source", '--json']), 180);
    if ($exit !== 0) {
        throw new Problem('restore_kopia_list', ['detail' => mb_substr(trim($err), 0, 300)]);
    }
    return rsKopiaParse($out);
}

function rsKopiaList(string $source): array
{
    $state = $GLOBALS['rs']['state'] ?? rsScan();
    if (!in_array($source, rsKopiaSources($state), true)) {
        throw new Problem('unknown_target', ['target' => $source]);
    }
    return ['ok' => true, 'source' => $source, 'snapshots' => array_slice(rsKopiaSnapshots($state['kopia'], $source), 0, 200)];
}

/**
 * Step 6 — a Kopia snapshot into the Kopia container's writable restore folder (the engine's mapping is
 * read-only by design; without such a folder the preview says how to add one in Unraid's template —
 * he never changes the container): <folder>/<source>-<time>/. From there a folder goes back by hand, or
 * — next — with step 4's copy or swap.
 */
function rsPlanKopia(array $r, string $stamp): array
{
    $source = textField($r, 'source');
    $snapshot = textField($r, 'snapshot');
    $state = rsScan();
    if (!in_array($source, rsKopiaSources($state), true) || !preg_match('/^[0-9a-f]{16,64}$/D', $snapshot)) {
        throw new Problem('unknown_target', ['target' => "$source $snapshot"]);
    }
    $k = $state['kopia'];
    $plan = rsPlanBase('kopia', ltrim($source, '.'), ['source' => $source, 'snapshot' => $snapshot]);
    if (!$k['restore']) {
        $plan['blockers'][] = ['key' => 'restore_kopia_no_mapping', 'params' => ['name' => (string) $k['container']]];
        return $plan;
    }
    $snaps = rsKopiaSnapshots($k, $source);
    $snap = array_values(array_filter($snaps, fn ($x) => $x['id'] === $snapshot))[0] ?? null;
    if (!$snap) {
        throw new Problem('unknown_target', ['target' => $snapshot]);
    }
    $name = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', ltrim($source, '.')), '_.') ?: 'restore';
    $host = rtrim($k['restore']['source'], '/') . "/$name-$stamp";
    $dest = rtrim($k['restore']['dest'], '/') . "/$name-$stamp";
    if (!rsCleanPath($host) || !rsCleanPath($dest)) {
        throw new Problem('unknown_target', ['target' => $host]);
    }
    if (file_exists($host)) {
        $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $host]];
    }
    $free = @disk_free_space($k['restore']['source']);
    $plan['sizes'] = ['need' => $snap['bytes'] ?: null, 'free' => $free === false ? null : (int) $free, 'measuring' => false, 'what' => 'kopia'];
    if ($snap['bytes'] && $free !== false && $snap['bytes'] > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $snap['bytes'], 'free' => (int) $free]];
    }
    $plan['source'] = ['path' => "{$k['root']}/$source", 'time' => $snap['time'], 'bytes' => $snap['bytes'], 'files' => $snap['files']];
    $plan['steps'] = [['do' => 'kopia', 'container' => $k['container'], 'uid' => rsKopiaUid($k['container']), 'snapshot' => $snapshot, 'dest' => $dest, 'host' => $host,
                       'source' => "{$k['root']}/$source"]];
    $plan['notes'][] = ['key' => 'note.kopia', 'params' => []];
    $plan['after'][] = ['key' => 'after.kopia', 'params' => ['path' => $host]];
    return $plan;
}

// ===================================================================== restoring: sizes in the background

function rsSizes(): array
{
    return (array) (readJson(rsSizesFile())['sizes'] ?? []);
}

/** Measures a path in the background (du in the agent's tick) — only paths a plan checked */
function rsDuQueue(string $path): void
{
    $du = &$GLOBALS['rs']['du'];
    if (isset($du['running'][$path]) || in_array($path, $du['queue'], true)) {
        return;
    }
    $du['queue'][] = $path;
    rsSizesSave([]);
}

/**
 * The du of a path: what it takes on disk (allocated blocks), or with $apparent the files' own sizes (a sparse VM disk:
 * its full size). A folder is measured as "<path>/." — a ZFS snapshot's folder is mounted only when something is looked
 * up inside it, and du -x would otherwise take the unmounted stub's device and skip all of it (1 KB for 108 GB).
 */
function rsDuCommand(string $path, bool $apparent): array
{
    $arg = is_dir($path) && !is_link($path) ? rtrim($path, '/') . '/.' : $path;
    return array_merge(['nice', '-n', '10', 'du', '-s', '-B1', '-x'], $apparent ? ['--apparent-size'] : [], [$arg]);
}

/**
 * Sizes in the background: per path two du runs one after the other — allocated, then apparent (the second one finds
 * the metadata in the cache) — saved once both are there: {bytes, apparent, at, seconds}.
 */
function rsDuTick(): void
{
    $du = &$GLOBALS['rs']['du'];
    if (!$du['running'] && !$du['queue']) {
        return;
    }
    $changed = null;
    $open = function (string $path, bool $apparent): mixed {
        $process = proc_open(rsDuCommand($path, $apparent), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
        if (!is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        return ['process' => $process, 'out' => $pipes[1]];
    };
    foreach ($du['running'] as $path => $job) {
        $chunk = (string) @stream_get_contents($job['out']);
        $du['running'][$path]['buffer'] .= $chunk;
        $st = proc_get_status($job['process']);
        if ($st['running']) {
            continue;
        }
        $buffer = $du['running'][$path]['buffer'] . (string) @stream_get_contents($job['out']);
        fclose($job['out']);
        proc_close($job['process']);
        $bytes = preg_match('/^(\d+)\s/', $buffer, $m) ? (int) $m[1] : null;
        if (empty($job['apparent']) && $bytes !== null) {
            $next = $open((string) $path, true);
            if ($next) {
                $du['running'][$path] = $next + ['since' => $job['since'], 'buffer' => '', 'apparent' => true, 'bytes' => $bytes];
                continue;
            }
        }
        $alloc = empty($job['apparent']) ? $bytes : $job['bytes'];
        $changed[$path] = ['bytes' => $alloc, 'apparent' => empty($job['apparent']) ? null : $bytes, 'at' => time(), 'seconds' => time() - $job['since']];
        unset($du['running'][$path]);
    }
    while (count($du['running']) < RS_DU_PARALLEL && $du['queue']) {
        $path = array_shift($du['queue']);
        $job = $open($path, false);
        if (!$job) {
            continue;
        }
        $du['running'][$path] = $job + ['since' => time(), 'buffer' => '', 'apparent' => false, 'bytes' => null];
        $changed ??= [];
    }
    if ($changed !== null) {
        rsSizesSave($changed);
    }
}

function rsSizesSave(array $changed): void
{
    $sizes = rsSizes();
    foreach ($changed as $path => $v) {
        $sizes[$path] = $v;
    }
    if (count($sizes) > 200) {
        uasort($sizes, fn ($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
        $sizes = array_slice($sizes, 0, 200, true);
    }
    writeAtomic(rsSizesFile(), jsonEncode(['sizes' => $sizes ?: new stdClass(), 'queue' => array_values($GLOBALS['rs']['du']['queue']),
                                           'running' => array_keys($GLOBALS['rs']['du']['running'])]));
}

// ===================================================================== hiring

/** Suits a server whose backups he can bring back: packages in the backup place, or local snapshots */
function rsFit(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $base = backupDumpsPath((string) backupSetting($settings, 'general', 'dumps_share', ''));
    $state = $GLOBALS['rs']['state'] ?? null;
    $n = count($state['apps'] ?? []) + count($state['vms'] ?? []);
    if ($base !== null && $n) {
        return fit(true, 'packages', ['n' => $n]);
    }
    $fs = houseSnapshotFilesystems();
    if ($fs['zfs'] || $fs['btrfs']) {
        return fit(true, 'snapshots', ['places' => implode(', ', array_merge($fs['zfs'], $fs['btrfs']))]);
    }
    return fit(false, 'nothing');
}
