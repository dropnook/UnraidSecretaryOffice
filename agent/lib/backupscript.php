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

/**
 * Which share holds the backup place: settings.ini's dumps_share — or, when the settings are gone (B42: settings.ini
 * deleted; a scheduled start then starts no run, engine 2.82) but the engine has run here, where its last runs wrote their
 * packages (status.json, last-run.json, then the newest of history.jsonl's last lines: packages.base, only in exactly the
 * shape backupDumpsPath() makes), else the office's own share. Never set up (no run either): none, as before. The one
 * rule for every reader of the place (Mr. Backupsy's packages, Mr. Restori's place, the shares the place isn't); what
 * writes there (setting a package aside, a let-go) still goes by the settings alone. $data: the engine's data folder.
 *
 * @return array{share: string, from: string}  from: settings | last_run | office_share
 */
function backupPlaceShare(array $settings, string $data = BACKUP_DATA_DIR): array
{
    $share = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $st = "$data/state";
    if ($share !== '' || (!is_file("$st/history.jsonl") && !is_file("$st/last-run.json"))) {
        return ['share' => $share, 'from' => 'settings'];
    }
    $bases = [readJson("$st/status.json")['packages']['base'] ?? null, readJson("$st/last-run.json")['packages']['base'] ?? null];
    $size = (int) @filesize("$st/history.jsonl");
    $tail = (string) @file_get_contents("$st/history.jsonl", false, null, max(0, $size - (256 << 10)));
    foreach (array_reverse(explode("\n", $tail)) as $line) {
        $b = json_decode($line, true)['packages']['base'] ?? null;
        if (is_string($b)) {
            $bases[] = $b;
            break;
        }
    }
    foreach ($bases as $b) {
        if (is_string($b) && preg_match('#^/mnt/user/([^/]+)/#', $b, $m) && preg_match('/^[\w .-]{1,64}$/uD', $m[1]) && $m[1][0] !== '.'
            && backupDumpsPath($m[1]) === $b) {
            return ['share' => $m[1], 'from' => 'last_run'];
        }
    }
    return ['share' => BACKUP_OFFICE_SHARE, 'from' => 'office_share'];
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
 * Through shfs (/mnt/user/… of a share that isn't exclusive — a partner test server's appdata, 2026-10-08) the lock lies on the
 * disk's or pool's file: FUSE hands the flock down, and /proc/locks names that file (`00:28:266`), never the inode
 * stat() shows through /mnt/user. shfs numbers its inodes (st_dev << 48) | st_ino of the file behind (Unraid 7.3.2,
 * seen on ZFS and btrfs, on two test servers), so that file's id is looked for too — when the device it names is mounted
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

/**
 * The running backup's step in the office's words (D-F6, 2026-10-19): the status file's coded phase (state/status.json
 * next to the log's folder) as Mr. Backupsy's step (his desk.js STEPS), from his words step.<step> in the office's last
 * language - null when the status file names no phase of a run going on. Never a raw log line: up to 1.66.8 this was the
 * log's last line, English on every page (Ms. Dustdevil's «Where is what»). The name stays for its callers.
 */
function lastLogStep(string $log): ?string
{
    static $steps = ['prepare' => ['start', 'inventory'], 'dumps' => ['vm_shutdown', 'maintenance', 'manifest', 'stopping_apps', 'dumps'],
                     'snapshots' => ['stopping', 'vms', 'snapshots', 'starting'], 'partner' => ['partner'], 'kopia' => ['mounting', 'kopia'],
                     'finish' => ['unmounting', 'cleanup', 'aborting', 'done']];
    $status = readJson(dirname($log, 2) . '/state/status.json');
    $phase = is_array($status) && ($status['result'] ?? null) === 'running' && is_string($status['phase'] ?? null) ? $status['phase'] : '';
    foreach ($steps as $step => $phases) {
        if (in_array($phase, $phases, true)) {
            $text = function_exists('officeNotifyText') ? officeNotifyText('backup', "step.$step", [], officeNotifyLang()) : '';
            return $text !== '' ? $text : null;
        }
    }
    return null;
}

/**
 * settings.ini: [section] or [type "name"] (key "type|name"), key = value,
 * keys may repeat (lists). Values are lists only where that makes sense.
 */
function backupReadSettings(string $file): array
{
    $result = [];
    $section = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        $line = trim($i === 0 && str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line);   // a byte order mark an editor put in front
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
 * Engine 2.37: the app/VM shares - the shares Unraid names as the place for app configs (docker.cfg
 * DOCKER_APP_CONFIG_PATH) and for VMs (domain.cfg DOMAINDIR), only when that path is the share itself
 * (/mnt/<user|pool>/<share>[/]); the engine's ub_app_shares_load(). Only there a new top-level folder waits for a
 * decision (or follows its app/VM); in every other share it goes with its share. A file that can't be read: none.
 * $boot for the tests (default /boot).
 *
 * @return array<string, list<string>>  share => ['docker'] / ['vm'] / both
 */
function backupAppShares(string $boot = '/boot'): array
{
    static $cache = [];
    $files = ['docker' => ["$boot/config/docker.cfg", 'DOCKER_APP_CONFIG_PATH'], 'vm' => ["$boot/config/domain.cfg", 'DOMAINDIR']];
    $stamp = $boot . '|' . implode('|', array_map(fn ($f) => (int) @filemtime($f[0]), $files));
    if (isset($cache[$stamp])) {
        return $cache[$stamp];
    }
    $out = [];
    foreach ($files as $kind => [$file, $key]) {
        $p = (string) (readCfg($file)[$key] ?? '');
        if (preg_match('#^/mnt/([^/]+)/([^/]+)/?$#D', $p, $m) && !in_array($m[1], ['disks', 'remotes', 'addons', 'rootshare'], true)
            && $m[2][0] !== '.' && !preg_match('/[@:"|\x00-\x1f]/', $m[2])) {
            $out[$m[2]][] = $kind;
        }
    }
    $cache = [$stamp => $out];
    return $out;
}

/**
 * How is a path protected by the backup? For any desk that shows paths: what is really there (the truth review, P1) —
 * the level settings.ini sets (backupProtectionSet()) lowered to what the engine's results show (backupProtectionInfo()).
 *   offsite  local snapshot + Kopia      local  local snapshot (or dump) only
 *   none     not backed up               null   no backup set up (or can't tell)
 * Tests pass their own settings (and $truth; without it the settings alone count, as before).
 */
function backupProtection(string $path, int $depth = 0, ?array $settings = null, ?array $appShares = null, ?array $truth = null): ?string
{
    return backupProtectionInfo($path, $settings, $appShares, $truth, $depth)['level'];
}

/**
 * A path's protection with its reasons, for a page that says why (Office.backupChip(level, note)):
 *   level     what is really there: offsite only once Kopia made a copy of it (its repository not held), local only where
 *             a run took a snapshot (its share's file system takes one), else lower
 *   set       what settings.ini sets
 *   why       codes, in this order: no_run (no backup run yet), cleared (his let-go cleared the engine's snapshots away,
 *             no backup run since), no_snaps (its share's file system takes no snapshot),
 *             kopia_held (the repository suddenly empty: the engine holds Kopia), kopia_never (Kopia made no copy of it
 *             yet), kopia_gone (Kopia's container is gone), kopia_docker_off (Docker switched off: no new copies),
 *             kopia_stuck / kopia_away (the storage takes nothing /
 *             can't be reached since `since`), kopia_failed (its last upload failed: an older copy is there)
 *   partners  the partner offices whose newest try received the unit it lies in (names)
 *   missed    the partner offices that should have it and don't: {name, why: never|skipped|failed, code: the engine's why}
 * backupProtectionNote() is the short form a state file carries.
 *
 * @return array{level: ?string, set: ?string, why: list<string>, since: ?int, partners: list<string>, missed: list<array{name: string, why: string, code: string}>}
 */
function backupProtectionInfo(string $path, ?array $settings = null, ?array $appShares = null, ?array $truth = null, int $depth = 0): array
{
    static $cache = [];
    if ($settings === null) {           // the engine's settings.ini and its results (tests pass their own)
        $file = BACKUP_DATA_DIR . '/settings.ini';
        $stamp = (int) @filemtime($file);
        if (($cache['stamp'] ?? null) !== $stamp) {
            $cache = ['stamp' => $stamp, 'settings' => $stamp ? backupReadSettings($file) : []];
        }
        $settings = $cache['settings'];
        $truth ??= $settings ? backupTruth() : null;
    }
    $at = [];
    $set = backupProtectionSet($path, $depth, $settings, $appShares, $at);
    $out = ['level' => $set, 'set' => $set, 'why' => [], 'since' => null, 'partners' => [], 'missed' => []];
    if ($set === null || $set === 'none') {
        return $out;
    }
    foreach (backupProtectionPartners($settings, $at, $truth) as $p) {
        if ($p['ok']) {
            $out['partners'][] = $p['name'];
        } else {
            $out['missed'][] = ['name' => $p['name'], 'why' => $p['why'], 'code' => $p['code']];
        }
    }
    if ($truth === null) {
        return $out;
    }
    $share = $at['share'] ?? null;
    $local = true;
    if (!$truth['runs']) {
        $out['why'][] = 'no_run';
        $local = false;
    } elseif (($truth['cleared'] ?? null) !== null) {
        $out['why'][] = 'cleared';          // his let-go cleared the engine's snapshots away, no run since
        $local = false;
    } elseif ($share !== null && (isset($truth['no_snaps'][$share]) || backupSetting($settings, "share|$share", 'method') === 'live')) {
        $out['why'][] = 'no_snaps';
        $local = false;
    }
    $out['level'] = $local ? 'local' : 'none';
    if ($set === 'offsite' && $truth['runs']) {
        $k = backupKopiaTruth($truth, $at['source'] ?? null);
        if ($k['why'] !== null) {
            $out['why'][] = $k['why'];
        }
        $out['since'] = $k['since'];
        if ($k['offsite']) {
            $out['level'] = 'offsite';
        }
    }
    if ($out['level'] === 'none') {
        $out['partners'] = [];
    }
    return $out;
}

/**
 * Is there an offsite copy of a Kopia source (the share, app:<name>, vm:<name>, flash)? → offsite (a good copy is there,
 * the repository not held) and the one reason worth saying (backupProtectionInfo()'s kopia_* codes, `since` with it).
 *
 * @return array{offsite: bool, why: ?string, since: ?int}
 */
function backupKopiaTruth(array $truth, ?string $src): array
{
    $k = $truth['kopia'];
    if ($k['held']) {
        return ['offsite' => false, 'why' => 'kopia_held', 'since' => null];
    }
    if ($src === null || !isset($k['good'][$src])) {
        return ['offsite' => false, 'why' => 'kopia_never', 'since' => null];
    }
    $why = $k['gone'] ? 'kopia_gone' : (($k['docker_off'] ?? false) ? 'kopia_docker_off' : ($k['stuck'] ? 'kopia_stuck' : ($k['away'] ? 'kopia_away' : (($k['last'][$src] ?? true) === false ? 'kopia_failed' : null))));
    return ['offsite' => true, 'why' => $why, 'since' => $why === 'kopia_stuck' ? $k['stuck'] : ($why === 'kopia_away' ? $k['away'] : null)];
}

/**
 * What a state file carries beside a level (backupProtection()): null when the level says it all — set as it is, no
 * reason, no partner — else {set, why, since, partners, missed} (backupProtectionInfo()). A page of an older agent has
 * none: its chip is the level alone.
 */
function backupProtectionNote(array $info): ?array
{
    if ($info['level'] === $info['set'] && !$info['why'] && !$info['partners'] && !$info['missed']) {
        return null;
    }
    return array_diff_key($info, ['level' => 0]);
}

/**
 * The level settings.ini sets for a path - the same rules as the engine: share mode, Kopia ignore rules, a new top-level
 * folder of an app/VM share (not in kopia_known, engine 2.21; since 2.37 only there - in a data share it goes with the
 * share) only local, flash mode for /boot, and /etc/libvirt lives in libvirt.img. A new folder of an app or VM going
 * offsite counts as local here (its owner needs Docker's view); one of an app or VM set up before and only local (engine
 * 2.38: it stays local with it, silently) is local here as it is in the run. $at gets where the path was judged: share,
 * rel (its path inside the share), source (the Kopia source that takes it: the share, app:<name>, vm:<name>, flash).
 * $appShares for the tests (backupAppShares()).
 */
function backupProtectionSet(string $path, int $depth, array $s, ?array $appShares, array &$at): ?string
{
    if (!$s || $depth > 3) {
        return null;
    }
    $at = ['share' => null, 'rel' => '', 'source' => null];
    $kopia = in_array(strtolower((string) backupSetting($s, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    $path = rtrim($path, '/');

    // archives and packages lie in the backup place: protected like its share
    $place = backupDumpsPath((string) backupSetting($s, 'general', 'dumps_share', ''));
    if ($path === '/boot' || str_starts_with($path, '/boot/')) {
        $at['source'] = 'flash';
        return match (backupSetting($s, 'flash', 'mode', 'off')) {
            'snapshot' => $kopia ? 'offsite' : 'local',
            'tar'      => $place ? backupProtectionSet($place, $depth + 1, $s, $appShares, $at) : 'none',
            default    => 'none',
        };
    }
    if ($path === '/etc/libvirt' || str_starts_with($path, '/etc/libvirt/')) {
        // the engine puts every VM's configuration into its package and all of libvirt.img into server/
        // (libvirt mode tar, the default); else only the image's share counts
        if ($place && backupSetting($s, 'libvirt', 'mode', 'tar') === 'tar') {
            return backupProtectionSet($place, $depth + 1, $s, $appShares, $at);
        }
        $img = readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? null;
        return $img ? backupProtectionSet($img, $depth + 1, $s, $appShares, $at) : null;
    }
    // /mnt/user/<share>/…, /mnt/<pool or disk>/<share>/…, or a resolved exclusive share
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', $path, $m) || in_array($m[1], ['disks', 'remotes', 'addons'], true)) {
        return str_starts_with($path, '/mnt/') ? null : 'none';
    }
    $share = $m[2];
    $rel = $m[3] ?? '';
    $at = ['share' => $share, 'rel' => $rel, 'source' => $share];
    // a folder of an app or VM with a Kopia source of its own (engine 2.19) goes offsite there,
    // whatever its share does - as long as the share takes a snapshot to read it from
    if ($kopia && $rel !== '' && backupSetting($s, "share|$share", 'mode', 'off') !== 'off') {
        foreach ($s as $section => $keys) {
            if (preg_match('/^(app|vm)\|(.+)$/D', (string) $section, $own) && ($keys['kopia'] ?? []) && end($keys['kopia']) === 'yes') {
                foreach ($keys['folder'] ?? [] as $f) {
                    if ($f === "$share/$rel" || str_starts_with("$share/$rel", rtrim($f, '/') . '/')) {
                        $at['source'] = "$own[1]:$own[2]";
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
    // (an app's or VM's own part went offsite above; the backup place's own folder always goes); since 2.37
    // only in an app/VM share - in a data share every folder goes with the share
    $top = explode('/', $rel, 2)[0];
    if ($top !== '' && isset(($appShares ?? backupAppShares())[$share]) && isset($s["share|$share"]['kopia_known']) && !array_intersect(["/$top/", '*'], $s["share|$share"]['kopia_known'])
        && !($share === backupSetting($s, 'general', 'dumps_share') && $top === ($share === BACKUP_OFFICE_SHARE ? BACKUP_DESK_DIR : 'unraid-backup'))) {
        return 'local';
    }
    return 'offsite';
}

/**
 * The partner offices that should keep a path (settings.ini's units, backupPartnersFromSettings()) and whether their
 * newest try received it ($truth: the history; null: as set). The unit a path travels in: its share (`share:<s>`, the
 * backup place's share also as `place`) — but a folder in a dataset of its own (a ZFS child of the share's dataset) is
 * no part of the share's stream (engine: `zfs send` without -R) and goes only as its app's (`app:<a>`, [app] folder) or
 * its VM's own unit (`vm:<v>`, its folder in a VM share). $at as backupProtectionSet() leaves it.
 *
 * @return list<array{name: string, ok: bool, why: string, code: string}>
 */
function backupProtectionPartners(array $s, array $at, ?array $truth): array
{
    $share = $at['share'] ?? null;
    if ($share === null) {
        return [];
    }
    $child = backupChildDataset($share, (string) ($at['rel'] ?? ''), $truth['children'] ?? null);
    $units = [];
    if ($child === null) {
        $units[] = "share:$share";
        if ($share === backupSetting($s, 'general', 'dumps_share')) {
            $units[] = 'place';
        }
    } else {
        foreach ($s as $section => $keys) {
            // the engine's app:<name> sends only its FIRST folder's dataset, <share>/<first dir> (app_own_ds) — another
            // folder of the app, a dataset of its own, isn't in that stream (review 1.72.0 H1: count what really goes)
            $first = ((array) ($keys['folder'] ?? []))[0] ?? null;
            if (preg_match('/^app\|(.+)$/D', (string) $section, $a) && is_string($first) && preg_match('#^([^/]+)/([^/]+)#', $first, $f)
                && "$f[1]/$f[2]" === "$share/$child") {
                $units[] = "app:$a[1]";
            } elseif (preg_match('/^vm\|(.+)$/D', (string) $section, $v) && explode('/', $child, 2)[0] === $v[1]) {
                $units[] = "vm:$v[1]";
            }
        }
    }
    $out = [];
    foreach (backupPartnersFromSettings($s) as $p) {
        $mine = array_values(array_intersect($units, $p['units']));
        if (!$mine) {
            continue;
        }
        $best = null;
        foreach ($mine as $u) {
            $o = $truth === null ? ['ok' => true, 'why' => '', 'code' => ''] : ($truth['partner'][$p['id']][$u] ?? ['ok' => false, 'why' => 'never', 'code' => '']);
            if ($best === null || ($o['ok'] && !$best['ok'])) {
                $best = $o;
            }
        }
        $out[] = ['name' => $p['name'], 'ok' => $best['ok'], 'why' => $best['ok'] ? '' : $best['why'], 'code' => $best['ok'] ? '' : (string) $best['code']];
    }
    return $out;
}

/**
 * Does a path inside a share lie in a ZFS dataset of its own (a child of the share's dataset, mounted at
 * /mnt/<pool>/<share>/<sub>)? → <sub> (the deepest), else null. $children ("<share>/<sub>") for the tests, else the
 * mount table (backupZfsChildren()).
 */
function backupChildDataset(string $share, string $rel, ?array $children = null): ?string
{
    $best = null;
    foreach ($children ?? backupZfsChildren() as $c) {
        if (!str_starts_with($c, "$share/")) {
            continue;
        }
        $sub = substr($c, strlen($share) + 1);
        if (($rel === $sub || str_starts_with($rel, "$sub/")) && ($best === null || strlen($sub) > strlen($best))) {
            $best = $sub;
        }
    }
    return $best;
}

/** The ZFS datasets mounted inside a share's folder on a pool or disk ("<share>/<sub>"), from the mount table, kept 10 s */
function backupZfsChildren(): array
{
    static $cache = ['t' => 0, 'list' => []];
    if (time() - $cache['t'] < 10) {
        return $cache['list'];
    }
    $list = [];
    foreach (mountTable() as $m) {
        if ($m['fs'] === 'zfs' && preg_match('#^/mnt/([^/]+)/([^/]+)/(.+)$#D', $m['mount'], $x)
            && !in_array($x[1], ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true)) {
            $list[] = "$x[2]/$x[3]";
        }
    }
    $cache = ['t' => time(), 'list' => array_values(array_unique($list))];
    return $cache['list'];
}

/**
 * What the engine's results say (its interface only — state/history.jsonl, drift.json, setup-plan.json, kopia-held.json,
 * kopia-watch.json, kopia-reach.json), read again only when one of them changed:
 *   runs      how many backup runs the history holds (skipped ones not)
 *   kopia     good: source => when its newest good copy was made · last: source => whether its newest upload went ·
 *             held (the repository suddenly empty) · gone (drift: Kopia's container gone) · stuck / away (since when the
 *             storage takes nothing / can't be reached, else null)
 *   no_snaps  share => true: its file system takes no snapshot (drift share_live / share_snapshot_no_fs; a newer plan's
 *             method decides for the shares it lists)
 *   new       share => true: new or renamed, not in settings.ini (drift share_new / share_renamed)
 *   partner   id => unit => {ok, why: skipped|failed, code: the engine's why, time} — its newest try
 *   cleared   when his let-go cleared the engine's snapshots away (backupLetGoCleared()), as long as no backup run came
 *             after — no local snapshot holds anything then, whatever the runs before made (else null)
 * Engine 2.80: state/results.json (backupResults()) fills in what the history no longer holds (it keeps 200 runs): a
 * source's newest good Kopia copy, a unit's newest partner try — the history decides wherever it says something.
 */
function backupTruth(?string $data = null): array
{
    static $cache = [];
    $data ??= BACKUP_DATA_DIR;
    $f = ['history' => "$data/state/history.jsonl", 'drift' => "$data/state/drift.json", 'plan' => "$data/state/setup-plan.json",
          'held' => "$data/state/kopia-held.json", 'watch' => "$data/state/kopia-watch.json", 'reach' => "$data/state/kopia-reach.json",
          'results' => "$data/state/results.json"];
    clearstatcache();
    $letgo = backupLetGoJournalDir($data);
    $journals = backupLetGoJournals($letgo);
    $stamp = $data . '|' . implode('|', array_map(fn ($x) => @filemtime($x) . ':' . @filesize($x), $f))
        . '|' . implode(',', array_map(fn ($n) => $n . ':' . @filemtime("$letgo/$n") . ':' . @filesize("$letgo/$n"), $journals));
    if (($cache['stamp'] ?? null) === $stamp) {
        return $cache['truth'];
    }
    $t = ['runs' => 0, 'kopia' => ['good' => [], 'last' => [], 'held' => false, 'gone' => false, 'docker_off' => false, 'stuck' => null, 'away' => null],
          'no_snaps' => [], 'new' => [], 'partner' => [], 'cleared' => null];
    $lastRun = 0;
    foreach (@file($f['history'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {      // oldest first
        $j = json_decode($line, true);
        if (!is_array($j) || !is_string($j['run'] ?? null) || ($j['result'] ?? '') === 'skipped') {
            continue;
        }
        $t['runs']++;
        // engine 2.75: Docker switched off, Kopia not asked - the newest run that looked at Kopia says (review 1.72.0 L9)
        $ks = $j['kopia']['state'] ?? '';
        if (is_string($ks) && $ks !== '' && $ks !== 'skip') {
            $t['kopia']['docker_off'] = $ks === 'docker_off';
        }
        $when = is_int($j['finished'] ?? null) && $j['finished'] > 0 ? $j['finished'] : (int) ($j['started'] ?? 0);
        $lastRun = max($lastRun, $when);
        foreach (is_array($j['kopia']['done'] ?? null) ? $j['kopia']['done'] : [] as $k) {
            if (is_array($k) && is_string($k['name'] ?? null)) {
                $t['kopia']['last'][$k['name']] = !empty($k['ok']);
                if (!empty($k['ok'])) {
                    $t['kopia']['good'][$k['name']] = is_int($k['finished'] ?? null) && $k['finished'] > 0 ? $k['finished'] : $when;
                }
            }
        }
        foreach (backupPartnerRun($j['partner'] ?? null) ?? [] as $p) {
            foreach ($p['units'] as $u) {
                $t['partner'][$p['id']][$u['unit']] = ['ok' => true, 'why' => '', 'code' => '', 'time' => $when];
            }
            foreach (['skipped', 'failed'] as $kind) {
                foreach ($p[$kind] as $u) {
                    $t['partner'][$p['id']][$u['unit']] = ['ok' => false, 'why' => $kind, 'code' => $u['why'], 'time' => $when];
                }
            }
            // a transfer the run's end interrupted didn't arrive in this run either (review 1.72.0 M2: every one of them)
            $code = in_array($j['message'] ?? '', ['array_stopping', 'signal'], true) ? $j['message'] : 'array_stopping';
            foreach ($p['interrupted_all'] as $u) {
                $t['partner'][$p['id']][$u] = ['ok' => false, 'why' => 'skipped', 'code' => $code, 'time' => $when];
            }
        }
    }
    // engine 2.80: what the 200 runs of the history no longer hold, from the result file (none: as before)
    $res = backupResults($data);
    foreach ($res['kopia'] ?? [] as $name => $r) {
        if (!array_key_exists($name, $t['kopia']['last'])) {
            $t['kopia']['last'][$name] = $r['result'] === 'ok';
        }
        if ($r['good'] > ($t['kopia']['good'][$name] ?? 0)) {
            $t['kopia']['good'][$name] = $r['good'];
        }
    }
    foreach ($res['partner'] ?? [] as $id => $units) {
        foreach ($units as $u => $r) {
            if (!isset($t['partner'][$id][$u])) {
                $t['partner'][$id][$u] = ['ok' => $r['result'] === 'ok', 'why' => $r['result'] === 'ok' ? '' : $r['result'], 'code' => $r['code'], 'time' => $r['time']];
            }
        }
    }
    $drift = readJson($f['drift']) ?? [];
    foreach (is_array($drift['items'] ?? null) ? $drift['items'] : [] as $d) {
        $code = is_array($d) && is_string($d['code'] ?? null) ? $d['code'] : '';
        $name = is_array($d) && is_string($d['params']['name'] ?? null) ? $d['params']['name'] : null;
        if ($name !== null && in_array($code, ['share_live', 'share_snapshot_no_fs'], true)) {
            $t['no_snaps'][$name] = true;
        } elseif ($name !== null && in_array($code, ['share_new', 'share_renamed'], true)) {
            $t['new'][$name] = true;
        } elseif (in_array($code, ['kopia_no_container', 'kopia_container_gone'], true)) {
            $t['kopia']['gone'] = true;
        }
    }
    $plan = readJson($f['plan']);
    if ($plan && (int) @filemtime($f['plan']) > (int) ($drift['time'] ?? 0)) {
        foreach (is_array($plan['shares'] ?? null) ? $plan['shares'] : [] as $sh) {
            if (is_array($sh) && is_string($sh['name'] ?? null) && in_array($sh['method'] ?? null, ['snap', 'live'], true)) {
                if ($sh['method'] === 'live') {
                    $t['no_snaps'][$sh['name']] = true;
                } else {
                    unset($t['no_snaps'][$sh['name']]);
                }
            }
        }
    }
    $held = readJson($f['held']);
    $t['kopia']['held'] = is_array($held) && ($held['v'] ?? null) === 1 && is_string($held['repo'] ?? null);
    $watch = readJson($f['watch']);
    if (is_array($watch) && ($watch['v'] ?? null) === 1 && ($watch['state'] ?? '') === 'stuck' && is_int($watch['since'] ?? null) && $watch['since'] > 0) {
        $t['kopia']['stuck'] = $watch['since'];
    }
    $reach = readJson($f['reach']);
    if (is_array($reach) && ($reach['v'] ?? null) === 1 && is_int($reach['since'] ?? null) && $reach['since'] > 0) {
        $t['kopia']['away'] = $reach['since'];
    }
    $cleared = backupLetGoCleared($letgo, $journals);
    $t['cleared'] = $t['runs'] && $cleared !== null && $cleared >= $lastRun ? $cleared : null;
    $cache = ['stamp' => $stamp, 'truth' => $t];
    return $t;
}

/**
 * The engine's result file (2.80, state/results.json, backup/README.md): per source and unit its newest result —
 * {local, package, kopia, partner} — each entry {result: ok|failed|skipped, code, time, run, good} (a partner's ok also
 * snap). Tolerant: a file of another version or shape is none (null — read history.jsonl as before); an entry not in
 * exactly that shape is left out, keys it doesn't know are kept in the entry; sections it doesn't know are kept too.
 *
 * @return array{v: int, engine: string, updated: int, run: string, local: array, package: array, kopia: array, partner: array}|null
 */
function backupResults(?string $data = null): ?array
{
    $file = ($data ?? BACKUP_DATA_DIR) . '/state/results.json';
    if (!is_file($file) || is_link($file) || (int) @filesize($file) > (8 << 20)) {
        return null;
    }
    $j = readJson($file);
    if (!is_array($j) || ($j['v'] ?? null) !== 1) {
        return null;
    }
    $ok = fn (mixed $r): bool => is_array($r) && in_array($r['result'] ?? null, ['ok', 'failed', 'skipped'], true) && is_string($r['code'] ?? null)
        && is_int($r['time'] ?? null) && is_string($r['run'] ?? null) && is_int($r['good'] ?? null) && $r['good'] >= 0;
    $out = ['v' => 1, 'engine' => is_string($j['engine'] ?? null) ? $j['engine'] : '', 'updated' => is_int($j['updated'] ?? null) ? $j['updated'] : 0,
            'run' => is_string($j['run'] ?? null) ? $j['run'] : ''] + $j;
    foreach (['local', 'package', 'kopia'] as $sec) {
        $out[$sec] = [];
        foreach (is_array($j[$sec] ?? null) ? $j[$sec] : [] as $k => $r) {
            if (is_string($k) && $k !== '' && $ok($r)) {
                $out[$sec][$k] = $r;
            }
        }
    }
    $out['partner'] = [];
    foreach (is_array($j['partner'] ?? null) ? $j['partner'] : [] as $id => $units) {
        foreach (is_string($id) && preg_match('/^[0-9a-f]{8}$/D', $id) && is_array($units) ? $units : [] as $u => $r) {
            if (is_string($u) && preg_match('/^(share:.+|vm:.+|app:.+|place)$/D', $u) && $ok($r)) {
                $out['partner'][$id][$u] = $r;
            }
        }
    }
    return $out;
}

/**
 * Mr. Backupsy's let-go journals (agent/desks/backup-letgo.php backupLetGoDir(): `backup/` in the data folder, beside the
 * engine's `unraid-backup/`) — for $data, the engine's data folder.
 */
function backupLetGoJournalDir(string $data): string
{
    return dirname($data) . '/backup';
}

/** The let-go's journals `letgo-<stamp>.json` in $dir, the newest 20 first (none through a link) */
function backupLetGoJournals(string $dir): array
{
    if (is_link($dir) || !is_dir($dir)) {
        return [];
    }
    $names = array_values(array_filter(@scandir($dir) ?: [], fn ($n) => preg_match('/^letgo-\d{8}-\d{6}(?:-\d+)?\.json$/D', $n) === 1));
    usort($names, fn ($a, $b) => strnatcmp($b, $a));
    return array_slice($names, 0, 20);
}

/**
 * When his let-go last cleared the engine's snapshots away (truth review INV-TRUTH, 2026-10-27: after «Also clear away»
 * every row still said «only local», as if the runs' snapshots were there): the newest journal (backupLetGoJournals())
 * that deleted at least one snapshot — its end (finished, else its last heartbeat; a clearing holds the engine's lock, so
 * no run lies inside it). Journals read in exactly their shape; null: none.
 */
function backupLetGoCleared(string $dir, ?array $journals = null): ?int
{
    foreach ($journals ?? backupLetGoJournals($dir) as $n) {
        $j = is_link("$dir/$n") ? null : readJson("$dir/$n");
        if (!is_array($j) || ($j['interface'] ?? null) !== 1 || !is_array($j['deleted'] ?? null) || !$j['deleted']) {
            continue;
        }
        foreach (['finished', 'heartbeat', 'started', 'created'] as $k) {
            if (is_int($j[$k] ?? null) && $j[$k] > 0) {
                return $j[$k];
            }
        }
    }
    return null;
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
            if (preg_match('/^(share|vm|app)\|(.+)$/D', (string) $k, $mm) && in_array($id, $keys['partner'] ?? [], true)) {
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
    $wall = [];
    foreach ($p['partners'] as $x) {
        $id = $str($x['id'] ?? null);
        if (preg_match('/^[0-9a-f]{8}$/D', $id)) {
            // engine 2.76: seconds = the wall time of its transfers (several at once), the Mbit/s over it (review L6)
            $wall[$id] = is_int($x['seconds'] ?? null) && $x['seconds'] >= 0 ? $x['seconds'] : null;
            $by[$id] = ['id' => $id, 'name' => $str($x['name'] ?? null) ?: $id, 'sent' => 0, 'bytes' => 0, 'seconds' => 0, 'mbit' => null,
                        'units' => [], 'skipped' => [], 'failed' => [], 'interrupted' => null, 'interrupted_all' => []];
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
    // engine 2.73: every transfer the array stop (or a stop by hand) ended - interrupted_all; an older engine names only
    // one, interrupted (review 1.72.0 M2)
    $all = is_array($p['interrupted_all'] ?? null) ? $p['interrupted_all'] : (is_array($i) ? [$i] : []);
    foreach ($all as $d) {
        $id = is_array($d) ? $str($d['id'] ?? null) : '';
        $u = is_array($d) ? $str($d['unit'] ?? null) : '';
        if (isset($by[$id]) && $u !== '' && !in_array($u, $by[$id]['interrupted_all'], true)) {
            $by[$id]['interrupted_all'][] = $u;
        }
    }
    foreach ($by as $id => &$x) {
        if ($wall[$id] !== null && $x['sent']) {
            $x['seconds'] = $wall[$id];
        }
        // the rate of what went, over the seconds it took (a transfer under a second counts as one)
        $x['mbit'] = $x['sent'] ? round($x['bytes'] * 8 / max(1, $x['seconds']) / 1e6, 1) : null;
    }
    unset($x);
    return array_values($by);
}
