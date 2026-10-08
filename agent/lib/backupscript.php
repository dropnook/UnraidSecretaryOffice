<?php
declare(strict_types=1);

/*
 * The backup engine (unraid-backup), part of the office: code in backup/,
 * settings, state and logs in data/unraid-backup — the same rule as UB_DATA in
 * backup/lib/common.sh; the packages of apps and VMs in the backup place
 * (backupDumpsPath()). Mr. Backup runs it (agent/desks/backup.php).
 *
 * While it runs it mounts its snapshots under <mount_root> so Kopia can read
 * them. Those must not be unmounted, deleted or renamed in the meantime.
 */

define('BACKUP_SCRIPT_DIR', OFFICE_DIR . '/backup');
define('BACKUP_DATA_DIR', DATA_DIR . '/unraid-backup');
// … as the engine finds it itself (the plugin's DATA_DIR, usually through /mnt/user): what its runs are told (UB_DATA),
// so the paths it keeps in its state are the same whoever started it (cron or the office)
define('BACKUP_DATA_DIR_USER', DATA_DIR_USER . '/unraid-backup');
define('BACKUP_OFFICE_SHARE', 'UnraidSecretaryOffice');   // the office's share: one folder per desk (lib/common.sh UB_OFFICE_SHARE)
define('BACKUP_DESK_DIR', 'backup');
const BACKUP_STAGE_DIR = '/run/unraid-backup-stage';   // its private staging area
// the engine's ZFS snapshots: <snap_prefix>YYYYMMDD-HHMM (lib/common.sh UB_SNAP_PREFIX, section 10)
const BACKUP_SNAP_PREFIX        = 'uso-backup-';      // the default since engine 2.20
const BACKUP_SNAP_PREFIX_LEGACY = 'unraidbackup-';    // the default of engines 2.9-2.19: still the engine's

/**
 * Is the backup script running right now? It holds an flock on state/lock for
 * the whole run. We only look that up in /proc/locks (flockHeld(), also through
 * shfs) and never take the lock ourselves — a run starting at that very moment
 * would give up otherwise.
 */
function backupScriptState(): array
{
    $state = ['found' => false, 'running' => false, 'since' => null, 'step' => null,
              'prefix' => null, 'prefixes' => [], 'mount_root' => null, 'keep_mounts' => false, 'dir' => null, 'settings' => []];
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh")) {
        return $state;
    }
    $general = readCfg("$data/settings.ini", true)['general'] ?? [];
    $state['found'] = true;
    $state['dir'] = BACKUP_SCRIPT_DIR;
    $state['prefixes'] = backupSnapPrefixes(is_string($general['snap_prefix'] ?? null) ? $general['snap_prefix'] : null);
    $state['prefix'] = $state['prefixes'][0];        // what new snapshots are called
    $state['mount_root'] = $general['mount_root'] ?? null;
    $state['keep_mounts'] = ($general['keep_mounts'] ?? 'no') === 'yes';
    $state['settings'] = $general;
    if (flockHeld("$data/state/lock")) {
        $state['running'] = true;
        $state['since'] = @filemtime("$data/state/lock") ?: null;   // the one that takes the lock touches it (before 2.20: "exec 9>" truncated it)
        $state['step'] = lastLogStep("$data/logs/latest.log");
    }
    return $state;
}

/**
 * The prefixes of the engine's ZFS snapshots — the same rule as snap_prefix_resolve() in
 * backup/lib/common.sh: the first is what new snapshots are called. The old default unraidbackup- counts
 * as the default (engine 2.20): new snapshots get uso-backup-, the old ones stay the engine's and age out
 * by its retention, so both are listed. A prefix of the user's own stays alone.
 *
 * @return list<string>
 */
function backupSnapPrefixes(?string $configured): array
{
    $p = trim((string) $configured);
    if ($p === '' || $p === BACKUP_SNAP_PREFIX || $p === BACKUP_SNAP_PREFIX_LEGACY) {
        return [BACKUP_SNAP_PREFIX, BACKUP_SNAP_PREFIX_LEGACY];
    }
    return [$p];
}

/** The prefixes of the engine's ZFS snapshots on this server (its settings.ini) */
function backupEngineSnapPrefixes(): array
{
    $general = readCfg(BACKUP_DATA_DIR . '/settings.ini', true)['general'] ?? [];
    return backupSnapPrefixes(is_string($general['snap_prefix'] ?? null) ? $general['snap_prefix'] : null);
}

/**
 * Is this one of the engine's snapshot names? ZFS (and anything else): exactly <prefix>YYYYMMDD-HHMM for
 * one of $prefixes (backupSnapPrefixes()) — never anything looser (snap_is_ours() in lib/common.sh);
 * btrfs: also its folders YYYYMMDD-HHMM. The name only, without dataset@.
 *
 * @param list<string> $prefixes
 */
function backupIsEngineSnap(string $name, array $prefixes, string $fs = 'zfs'): bool
{
    if ($fs === 'btrfs' && preg_match('/^\d{8}-\d{4}$/D', $name)) {
        return true;
    }
    foreach ($prefixes as $p) {
        if (is_string($p) && $p !== '' && str_starts_with($name, $p) && preg_match('/^\d{8}-\d{4}$/D', substr($name, strlen($p)))) {
            return true;
        }
    }
    return false;
}

/** Who may hold the engine's lock (engine 2.20): a run of the engine by its mode, the setup, a restore, anybody else */
const BACKUP_HOLDERS = ['backup', 'check', 'dryrun', 'setup', 'restore', 'other'];

/**
 * Who holds the engine's lock (state/lock) right now — null while nobody does. Since engine 2.20
 * whoever takes it notes itself in state/lock-holder.json (backup/README.md, "When the lock is
 * busy"): backup.sh, setup.sh, Mr. Restori's restore jobs. The lock stays the truth; the note
 * counts only while its pid lives — for backup.sh and setup.sh only while that pid runs the script.
 * Without a note that counts, a run status.json calls running while its pid runs backup.sh is the
 * holder (an engine before 2.20 writes no note); anybody else is "other".
 *
 * @return array{holder:string, mode:string, what:string, run:string, pid:int, started:int}|null
 *         holder: backup | check | dryrun (a run of the engine, by its mode) | setup | restore | other
 */
function backupLockHolder(?string $data = null): ?array
{
    $data ??= BACKUP_DATA_DIR;
    if (!flockHeld("$data/state/lock")) {
        return null;
    }
    $holder = backupHolderNote(readJson("$data/state/lock-holder.json"));
    if ($holder['pid'] === 0) {
        $status = readJson("$data/state/status.json");
        if (($status['result'] ?? '') === 'running') {
            $fromStatus = backupHolderNote(['holder' => 'backup'] + array_intersect_key($status, ['mode' => 1, 'run' => 1, 'pid' => 1, 'started' => 1]));
            if ($fromStatus['holder'] !== 'other') {
                return $fromStatus;
            }
        }
    }
    return $holder;
}

/** What a lock-holder.json says, checked against the running processes (see backupLockHolder()) */
function backupHolderNote(?array $note): array
{
    $out = ['holder' => 'other', 'mode' => '', 'what' => '', 'run' => '', 'pid' => 0, 'started' => 0];
    $pid = is_int($note['pid'] ?? null) ? $note['pid'] : 0;
    if ($pid <= 1 || !is_dir("/proc/$pid")) {
        return $out;
    }
    $text = fn (mixed $v): string => is_string($v) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v)), 0, 80) : '';
    $holder = (string) ($note['holder'] ?? '');
    $mode = $text($note['mode'] ?? '');
    $script = ['backup' => 'backup.sh', 'setup' => 'setup.sh'][$holder] ?? null;
    if ($script !== null) {
        $runs = false;
        foreach (array_slice(explode("\0", (string) @file_get_contents("/proc/$pid/cmdline")), 0, 4) as $arg) {
            $runs = $runs || $arg === $script || str_ends_with($arg, "/$script");
        }
        if (!$runs) {
            return $out;             // the pid lives on in another program: an old note
        }
    }
    $out['holder'] = match ($holder) {
        'backup'  => in_array($mode, ['check', 'dryrun'], true) ? $mode : 'backup',
        'setup', 'restore' => $holder,
        default   => 'other',
    };
    $out['mode'] = $mode;
    $out['what'] = $text($note['what'] ?? '');
    $out['run'] = is_string($note['run'] ?? null) && preg_match('/^\d{8}-\d{4}$/D', $note['run']) ? $note['run'] : '';
    $out['pid'] = $pid;
    $out['started'] = is_int($note['started'] ?? null) && $note['started'] > 0 ? $note['started'] : 0;
    return $out;
}

/**
 * A run that found the lock busy (engine 2.20: state/skipped.json, and the history.jsonl lines with
 * "result": "skipped"), in the shape the office shows — null for anything else
 *
 * @return array{run:string, mode:string, time:int, reason:string, holder:array}|null
 */
function backupSkipRow(mixed $j): ?array
{
    if (!is_array($j) || ($j['result'] ?? '') !== 'skipped') {
        return null;
    }
    $text = fn (mixed $v): string => is_string($v) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v)), 0, 80) : '';
    $h = is_array($j['holder'] ?? null) ? $j['holder'] : [];
    $kind = in_array($h['kind'] ?? null, BACKUP_HOLDERS, true) ? $h['kind'] : 'other';
    $run = fn (mixed $v): string => is_string($v) && preg_match('/^\d{8}-\d{4}$/D', $v) ? $v : '';
    return [
        'run'    => $run($j['run'] ?? null),
        'mode'   => in_array($j['mode'] ?? null, ['backup', 'check', 'dryrun'], true) ? $j['mode'] : 'backup',
        'time'   => (int) ($j['time'] ?? $j['started'] ?? 0),
        'reason' => "skipped_busy_$kind",
        'holder' => [
            'kind'    => $kind,
            'mode'    => $text($h['mode'] ?? ''),
            'what'    => $text($h['what'] ?? ''),
            'run'     => $run($h['run'] ?? null),
            'started' => (int) ($h['started'] ?? 0),
            'phase'   => $text($h['phase'] ?? ''),
            'current' => $text($h['current'] ?? ''),
        ],
    ];
}

/**
 * The backup place in a share — the same rule as the engine's dumps_path(): <share>/unraid-backup,
 * in the office's share <share>/backup. Since engine 2.18 it holds the packages (apps/, vms/,
 * server/, flash/), before that one folder per run.
 */
function backupDumpsPath(string $share): ?string
{
    if ($share === '') {
        return null;
    }
    return $share === BACKUP_OFFICE_SHARE ? "/mnt/user/$share/" . BACKUP_DESK_DIR : "/mnt/user/$share/unraid-backup";
}

/** Where the backup script mounts things */
function backupScriptRoots(array $state): array
{
    return array_values(array_filter([$state['mount_root'] ?? null, BACKUP_STAGE_DIR]));
}

function inBackupRoots(string $path, array $roots): bool
{
    foreach ($roots as $root) {
        if (under($path, $root)) {
            return true;
        }
    }
    return false;
}

/**
 * Does anybody hold an flock on this file? Looked up in /proc/locks, never by taking the lock: a run of the engine
 * starting that very moment takes it with `flock -n` and would skip the night. The PID in /proc/locks is the one that
 * took the lock — it may be long gone while backup.sh keeps the inherited handle. So only the entry itself counts.
 *
 * Through shfs (/mnt/user/… of a share that isn't exclusive — USOPartner's appdata, 2026-10-08) the lock lies on the
 * disk's or pool's file: FUSE hands the flock down, and /proc/locks names that file (`00:28:266`), never the inode
 * stat() shows through /mnt/user. shfs numbers its inodes (st_dev << 48) | st_ino of the file behind (Unraid 7.3.2,
 * seen on ZFS and btrfs: Tower, USOPartner), so that file's id is looked for too — when the device it names is mounted
 * (/proc/self/mountinfo). A FUSE file whose number doesn't name a mounted device (another scheme) is probed instead:
 * a non-blocking shared flock on a read-only handle, let go at once (flockProbe()).
 * $st and $mountinfo: the tests' (a file as shfs would show it).
 */
function flockHeld(string $file, ?array $st = null, ?string $mountinfo = null): bool
{
    $st ??= @stat($file) ?: null;
    if (!$st) {
        return false;
    }
    $look = flockIds($st, $mountinfo);
    foreach (@file('/proc/locks', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\d+:\s+FLOCK\s+\S+\s+\S+\s+\d+\s+(\S+)\s/', $line, $m) && in_array($m[1], $look['ids'], true)) {
            return true;
        }
    }
    return $look['probe'] && flockProbe($file);
}

/** A device and an inode the way /proc/locks writes them (MAJOR:MINOR:INODE, the numbers in hex but the inode) */
function flockId(int $dev, int $ino): string
{
    return sprintf('%02x:%02x:%d', (($dev >> 8) & 0xfff) | (($dev >> 32) & ~0xfff), ($dev & 0xff) | (($dev >> 12) & ~0xff), $ino);
}

/**
 * What to look for in /proc/locks for a file stat() saw: its own id, and for a file on shfs the one of the file behind it
 * (see flockHeld()); `probe` when it lies on FUSE and its number names no mounted device.
 *
 * @param  array{dev:int, ino:int} $st
 * @param  ?string $mountinfo  /proc/self/mountinfo (the tests' own)
 * @return array{ids: list<string>, probe: bool}
 */
function flockIds(array $st, ?string $mountinfo = null): array
{
    $ids = [flockId($st['dev'], $st['ino'])];
    $major = (($st['dev'] >> 8) & 0xfff) | (($st['dev'] >> 32) & ~0xfff);
    if ($major !== 0) {
        return ['ids' => $ids, 'probe' => false];    // a block device: never FUSE
    }
    $mounts = [];
    foreach (explode("\n", $mountinfo ?? (string) @file_get_contents('/proc/self/mountinfo')) as $line) {
        // 59 44 0:52 / /mnt/user rw,… shared:15 - fuse.shfs shfs rw,…
        if (preg_match('/^\d+ \d+ (\d+):(\d+) .* - (\S+) /', $line, $m)) {
            $mounts[sprintf('%02x:%02x', (int) $m[1], (int) $m[2])] = $m[3];
        }
    }
    $fs = $mounts[substr($ids[0], 0, strrpos($ids[0], ':'))] ?? '';
    if (!str_starts_with($fs, 'fuse')) {
        return ['ids' => $ids, 'probe' => false];
    }
    $behind = flockId(($st['ino'] >> 48) & 0xffff, $st['ino'] & 0xffffffffffff);
    if ($st['ino'] >> 48 !== 0 && isset($mounts[substr($behind, 0, strrpos($behind, ':'))])) {
        $ids[] = $behind;
        return ['ids' => $ids, 'probe' => false];
    }
    return ['ids' => $ids, 'probe' => true];
}

/**
 * Is the flock on this file held? Asked by taking a shared one without waiting on a read-only handle, and letting go
 * at once — only where /proc/locks can't tell (flockIds()): a run taking the lock at that very moment would find it busy.
 */
function flockProbe(string $file): bool
{
    $h = @fopen($file, 'r');
    if (!$h) {
        return false;
    }
    $free = flock($h, LOCK_SH | LOCK_NB, $busy);
    if ($free) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$free && (bool) $busy;
}

/** Last main line of the running backup's log */
function lastLogStep(string $log): ?string
{
    $h = @fopen($log, 'r');
    if (!$h) {
        return null;
    }
    fseek($h, max(0, (int) @filesize($log) - 8192));
    $tail = (string) stream_get_contents($h);
    fclose($h);
    $step = null;
    foreach (explode("\n", $tail) as $line) {
        if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  (\S.*)$/', $line, $m)) {
            $step = preg_replace('/\s+/', ' ', trim($m[1]));
        }
    }
    return $step;
}

/**
 * settings.ini: [section] or [type "name"] (key "type|name"), key = value,
 * keys may repeat (lists). Values are lists only where that makes sense.
 */
function backupReadSettings(string $file): array
{
    $result = [];
    $section = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (preg_match('/^\[\s*([A-Za-z0-9_-]+)(?:\s+"(.*)")?\s*\]$/', $line, $m)) {
            $section = isset($m[2]) ? "$m[1]|$m[2]" : $m[1];
            $result[$section] ??= [];
            continue;
        }
        if ($section !== null && preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/', $line, $m)) {
            $result[$section][$m[1]][] = $m[2];
        }
    }
    return $result;
}

function backupSetting(array $settings, string $section, string $key, ?string $default = null): ?string
{
    $values = $settings[$section][$key] ?? [];
    return $values ? end($values) : $default;
}

/**
 * How is a path protected by the backup? For any desk that shows paths.
 *   offsite  local snapshot + Kopia      local  local snapshot (or dump) only
 *   none     not backed up               null   no backup set up (or can't tell)
 * Follows the same rules as the engine: share mode, Kopia ignore rules, a new top-level
 * folder (not in kopia_known, engine 2.21) only local, flash mode for /boot, and
 * /etc/libvirt lives in libvirt.img.
 */
function backupProtection(string $path, int $depth = 0, ?array $settings = null): ?string
{
    static $cache = [];
    if ($settings === null) {           // the engine's settings.ini (tests pass their own)
        $file = BACKUP_DATA_DIR . '/settings.ini';
        $stamp = (int) @filemtime($file);
        if (($cache['stamp'] ?? null) !== $stamp) {
            $cache = ['stamp' => $stamp, 'settings' => $stamp ? backupReadSettings($file) : []];
        }
        $settings = $cache['settings'];
    }
    $s = $settings;
    if (!$s || $depth > 3) {
        return null;
    }
    $kopia = in_array(strtolower((string) backupSetting($s, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    $path = rtrim($path, '/');

    // archives and packages lie in the backup place: protected like its share
    $place = backupDumpsPath((string) backupSetting($s, 'general', 'dumps_share', ''));
    if ($path === '/boot' || str_starts_with($path, '/boot/')) {
        return match (backupSetting($s, 'flash', 'mode', 'off')) {
            'snapshot' => $kopia ? 'offsite' : 'local',
            'tar'      => $place ? backupProtection($place, $depth + 1, $s) : 'none',
            default    => 'none',
        };
    }
    if ($path === '/etc/libvirt' || str_starts_with($path, '/etc/libvirt/')) {
        // the engine puts every VM's configuration into its package and all of libvirt.img into server/
        // (libvirt mode tar, the default); else only the image's share counts
        if ($place && backupSetting($s, 'libvirt', 'mode', 'tar') === 'tar') {
            return backupProtection($place, $depth + 1, $s);
        }
        $img = readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? null;
        return $img ? backupProtection($img, $depth + 1, $s) : null;
    }
    // /mnt/user/<share>/…, /mnt/<pool or disk>/<share>/…, or a resolved exclusive share
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', $path, $m) || in_array($m[1], ['disks', 'remotes', 'addons'], true)) {
        return str_starts_with($path, '/mnt/') ? null : 'none';
    }
    $share = $m[2];
    $rel = $m[3] ?? '';
    // a folder of an app or VM with a Kopia source of its own (engine 2.19) goes offsite there,
    // whatever its share does - as long as the share takes a snapshot to read it from
    if ($kopia && $rel !== '' && backupSetting($s, "share|$share", 'mode', 'off') !== 'off') {
        foreach ($s as $section => $keys) {
            if (preg_match('/^(app|vm)\|/', $section) && ($keys['kopia'] ?? []) && end($keys['kopia']) === 'yes') {
                foreach ($keys['folder'] ?? [] as $f) {
                    if ($f === "$share/$rel" || str_starts_with("$share/$rel", rtrim($f, '/') . '/')) {
                        return 'offsite';
                    }
                }
            }
        }
    }
    $mode = backupSetting($s, "share|$share", 'mode');
    if ($mode === null || $mode === 'off') {
        return 'none';
    }
    if ($mode === 'snapshot' || !$kopia) {
        return 'local';
    }
    foreach ($s["share|$share"]['kopia_ignore'] ?? [] as $rule) {
        $r = trim($rule, '/');
        if (str_starts_with($rule, '/') && $r !== '' && !preg_match('/[*?\[]/', $r) && ($rel === $r || str_starts_with($rel, "$r/"))) {
            return 'local';
        }
    }
    // engine 2.21: a top-level folder that is not in kopia_known is new - only local until the user decides
    // (an app's or VM's own part went offsite above; the backup place's own folder always goes)
    $top = explode('/', $rel, 2)[0];
    if ($top !== '' && isset($s["share|$share"]['kopia_known']) && !array_intersect(["/$top/", '*'], $s["share|$share"]['kopia_known'])
        && !($share === backupSetting($s, 'general', 'dumps_share') && $top === ($share === BACKUP_OFFICE_SHARE ? BACKUP_DESK_DIR : 'unraid-backup'))) {
        return 'local';
    }
    return 'offsite';
}

/**
 * Engine 2.27: the partner offices settings.ini sends to - [partner "<id>"] name, address, port, rate_mbit - and per
 * partner its units (place, share:<s>, vm:<v>: [general] partner_place, [share|vm "<n>"] partner = <id>).
 *
 * @return list<array{id:string, name:string, address:string, port:int, rate_mbit:int, units:list<string>}>
 */
function backupPartnersFromSettings(array $s): array
{
    $out = [];
    foreach (array_keys($s) as $sec) {
        if (!preg_match('/^partner\|([0-9a-f]{8})$/D', (string) $sec, $m)) {
            continue;
        }
        $id = $m[1];
        $units = in_array($id, $s['general']['partner_place'] ?? [], true) ? ['place'] : [];
        foreach ($s as $k => $keys) {
            if (preg_match('/^(share|vm)\|(.+)$/D', (string) $k, $mm) && in_array($id, $keys['partner'] ?? [], true)) {
                $units[] = "$mm[1]:$mm[2]";
            }
        }
        $out[] = ['id' => $id, 'name' => (string) backupSetting($s, $sec, 'name', $id), 'address' => (string) backupSetting($s, $sec, 'address', ''),
                  'port' => (int) backupSetting($s, $sec, 'port', '22'), 'rate_mbit' => (int) backupSetting($s, $sec, 'rate_mbit', '0'), 'units' => $units];
    }
    return $out;
}

/**
 * Engine 2.27: a run's partner phase (status.json / a history line "partner") per partner - what went, how much, how
 * fast, what didn't and why. Only the engine's shape is taken; anything else is null.
 *
 * @return ?list<array{id:string, name:string, sent:int, bytes:int, seconds:int, mbit:?float, units:list<array>, skipped:list<array>, failed:list<array>, interrupted:?string}>
 */
function backupPartnerRun(mixed $p): ?array
{
    if (!is_array($p) || !is_array($p['partners'] ?? null)) {
        return null;
    }
    $str = fn ($v) => is_string($v) ? substr(preg_replace('/[\x00-\x1f]/', '', $v), 0, 120) : '';
    $by = [];
    foreach ($p['partners'] as $x) {
        $id = $str($x['id'] ?? null);
        if (preg_match('/^[0-9a-f]{8}$/D', $id)) {
            $by[$id] = ['id' => $id, 'name' => $str($x['name'] ?? null) ?: $id, 'sent' => 0, 'bytes' => 0, 'seconds' => 0, 'mbit' => null,
                        'units' => [], 'skipped' => [], 'failed' => [], 'interrupted' => null];
        }
    }
    foreach ((array) ($p['done'] ?? []) as $d) {
        $id = $str($d['id'] ?? null);
        if (!isset($by[$id]) || !is_array($d)) {
            continue;
        }
        $b = max(0, (int) ($d['bytes'] ?? 0));
        $sec = max(0, (int) ($d['seconds'] ?? 0));
        $by[$id]['sent']++;
        $by[$id]['bytes'] += $b;
        $by[$id]['seconds'] += $sec;
        $by[$id]['units'][] = ['unit' => $str($d['unit'] ?? null), 'snap' => $str($d['snap'] ?? null), 'from' => is_string($d['from'] ?? null) ? $str($d['from']) : null,
                               'bytes' => $b, 'seconds' => $sec, 'resumed' => !empty($d['resumed'])];
    }
    foreach (['skipped', 'failed'] as $kind) {
        foreach ((array) ($p[$kind] ?? []) as $d) {
            $id = is_array($d) ? $str($d['id'] ?? null) : '';
            if (isset($by[$id])) {
                $by[$id][$kind][] = ['unit' => $str($d['unit'] ?? null), 'why' => $str($d['why'] ?? null)];
            }
        }
    }
    $i = $p['interrupted'] ?? null;
    if (is_array($i) && isset($by[$str($i['id'] ?? null)])) {
        $by[$str($i['id'])]['interrupted'] = $str($i['unit'] ?? null);
    }
    foreach ($by as &$x) {
        // the rate of what went, over the seconds it took (a transfer under a second counts as one)
        $x['mbit'] = $x['sent'] ? round($x['bytes'] * 8 / max(1, $x['seconds']) / 1e6, 1) : null;
    }
    unset($x);
    return array_values($by);
}
