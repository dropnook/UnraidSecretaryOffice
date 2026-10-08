<?php
declare(strict_types=1);

/*
 * Letting Mr. Backupsy go — and, only when the user ticks «Also clear away what he kept here» in his let-go dialog
 * (Benj, 2026-10-08, «Rückbauten ohne Altlasten»), clearing away what he kept. Letting him go alone keeps everything,
 * like every desk. With the tick the page asks `backup.letgo_look` (what would go, with Ms. Snapshotini's estimate)
 * and, on «Let go», `backup.letgo_clear {confirm: true}` — before it lets him go (an unhired desk gets no write
 * actions); he is let go whatever came of it, and the page says what was done.
 *
 *   - The packages in the backup place (apps/<app>, vms/<vm>, server/, flash/ — on each awake pool or disk its share
 *     lies on) go into Ms. Dustdevil's storeroom: her run folders and moves (clRunCreate(), clMove(),
 *     clManifestWrite()), renamed on their own filesystem inside the share (clLeftoverTrash()), kind `package`,
 *     `packages/<hash>/<name>` in the run — listed under «Tidying up», put back (clPackageHome()) or emptied there by
 *     hand, never deleted at once. A place on a sleeping disk is never woken: its packages stay.
 *   - The engine's own local snapshots are deleted: ZFS `<prefix>YYYYMMDD-HHMM` (backupIsEngineSnap() with the
 *     prefixes of settings.ini) and btrfs `<disk>/<btrfs_snap_dir>/YYYYMMDD-HHMM` — as Ms. Snapshotini sees them
 *     (snapshotScan()), with her estimate first (snapshotEstimate()). Never her plans' (`uso-plan-…`), never a
 *     partner's copies (anything under the partners' place), never what Mr. Restori pulled back from a partner
 *     (backupLetGoRestored()), never Docker's layers, never a parked dataset's in the storeroom; a held or cloned one
 *     and one on a sleeping pool stay (counted). Each deletion goes into her record
 *     (snapshotRecord()), so the night watchman knows it was the office.
 *   - settings.ini, the decisions and the schedule stay: hired again, his plan is there. Kopia's copies and the
 *     partners' are never touched.
 *
 * Nothing while the engine's lock is held (a run, the setup, a restore or drill) — and the clearing holds it itself
 * from the first move to the last deletion (review 2026-10-09): taken without waiting like Mr. Restori's jobs, noted in
 * state/lock-holder.json as {holder: backup, mode: letgo}, given back whatever happens; a nightly run starting meanwhile
 * is skipped (the engine: «busy, other»), never meets packages vanishing under it. What was moved and deleted, and what
 * failed, goes into data/backup/letgo-<time>.json (root only), written after every step — a clearing that stops
 * half-way leaves its record. Ms. Dustdevil reads the storerooms from there too (backupLetGoTrashRoots()): one on an
 * array disk or in a dataset of its own isn't among the pools' share tops she looks at by herself.
 */

const LETGO_JOURNAL  = '/^letgo-\d{8}-\d{6}(?:-\d+)?\.json$/D';
const LETGO_JOURNALS = 50;                 // journals Ms. Dustdevil reads, newest first
const LETGO_GROUPS   = ['apps', 'vms'];    // a package per app and per VM …
const LETGO_TOPS     = ['server', 'flash'];   // … and these two
const LETGO_BATCH    = 100;                // snapshots per zfs destroy
const LETGO_SHOWN    = 12;                 // names the look lists

/** His folder in the data folder: root only (tests point it elsewhere) */
function backupLetGoDir(): string
{
    return $GLOBALS['letgoDir'] ?? DATA_DIR . '/backup';
}

/** The engine's data folder, where its lock lies (tests point it elsewhere) */
function backupLetGoUbData(): string
{
    return $GLOBALS['letgoUbData'] ?? BACKUP_DATA_DIR;
}

/** zfs, btrfs, Ms. Snapshotini's look (fresh, and after the deletions) and Ms. Dustdevil's move — the tests put stand-ins in $GLOBALS['letgoHost'] */
function backupLetGoHost(): array
{
    $h = $GLOBALS['letgoHost'] ?? [];
    return [
        'zfs'    => $h['zfs'] ?? bin('zfs'),
        'btrfs'  => $h['btrfs'] ?? bin('btrfs'),
        'scan'   => $h['scan'] ?? fn (): array => snapshotScan(true),
        'rescan' => $h['rescan'] ?? fn () => snapshotScan(false),
        'move'   => $h['move'] ?? 'clMove',
    ];
}

/** Who holds the engine's lock right now, as an error key (null: nobody) — the same words as backupCheckReady() */
function backupLetGoBusy(): ?string
{
    $holder = backupLockHolder(backupLetGoUbData());
    if ($holder === null && !backupSetupStatus()['running']) {
        return null;
    }
    return match (true) {
        backupSetupStatus()['running'] || ($holder['holder'] ?? '') === 'setup' => 'setup_running',
        ($holder['holder'] ?? '') === 'restore' => 'restore_running',
        default => 'backup_running',
    };
}

/** What the clearing goes by: the backup place, whether its share sleeps, the snapshot prefixes, the btrfs folder */
function backupLetGoFacts(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $share = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $dir = (string) backupSetting($settings, 'general', 'btrfs_snap_dir', '.btrfs-snap');
    return [
        'settings' => $settings,
        'place'    => backupDumpsPath($share),
        'asleep'   => $share !== '' && backupShareAsleep($share, $settings, sleepingDisks()),
        'prefixes' => backupSnapPrefixes(backupSetting($settings, 'general', 'snap_prefix')),
        'btrfs_dir' => preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $dir) && $dir !== '.' && $dir !== '..' ? $dir : '.btrfs-snap',
    ];
}

/**
 * The backup place (/mnt/user/<share>/<folder>) on each pool or disk its share lies on — awake ones only, never looked at
 * on a sleeping one. $roots: clRoots() (name => …), $asleep: sleepingDisks(); $mnt for the tests.
 *
 * @return list<array{root: string, top: string, place: string}>
 */
function backupLetGoParts(?string $place, array $roots, array $asleep, string $mnt = '/mnt'): array
{
    if ($place === null || !preg_match('#^/mnt/user/([^/]+)/([^/]+)$#D', $place, $m)) {
        return [];
    }
    $out = [];
    foreach (array_keys($roots) as $name) {
        $name = (string) $name;
        if (baseAsleep($name, $asleep)) {
            continue;
        }
        $top = "$mnt/$name/{$m[1]}";
        $p = "$top/{$m[2]}";
        if (!is_link($top) && !is_link($p) && is_dir($p)) {
            $out[] = ['root' => $name, 'top' => $top, 'place' => $p];
        }
    }
    return $out;
}

/**
 * The packages in one part of the place: apps/<app>, vms/<vm>, server, flash — real folders only (no links, nothing
 * hidden: a run's `.ub-…` leftovers are the engine's own business).
 *
 * @return list<array{group: string, name: string, path: string}>
 */
function backupLetGoPackages(string $place): array
{
    $list = [];
    foreach (LETGO_GROUPS as $g) {
        $dir = "$place/$g";
        if (is_link($dir) || !is_dir($dir)) {
            continue;
        }
        $names = array_filter(@scandir($dir) ?: [], fn ($n) => $n !== '' && $n[0] !== '.');
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $n) {
            if (!is_link("$dir/$n") && is_dir("$dir/$n")) {
                $list[] = ['group' => $g, 'name' => $n, 'path' => "$dir/$n"];
            }
        }
    }
    foreach (LETGO_TOPS as $t) {
        if (!is_link("$place/$t") && is_dir("$place/$t")) {
            $list[] = ['group' => $t, 'name' => $t, 'path' => "$place/$t"];
        }
    }
    return $list;
}

/** What the user calls a package: «apps/nextcloud», «server» */
function backupLetGoLabel(array $p): string
{
    return in_array($p['group'], LETGO_TOPS, true) ? $p['name'] : "{$p['group']}/{$p['name']}";
}

/**
 * The engine's own snapshots in Ms. Snapshotini's look: those that go (`take`), and how many of his stay — `held`
 * (a hold or a clone), `asleep` (a sleeping pool: never asked). Never hers, a partner's copies, Docker's, a parked
 * dataset's in the storeroom.
 *
 * @param list<string> $prefixes backupSnapPrefixes()
 */
function backupLetGoSnaps(?array $state, array $prefixes, string $btrfsDir): array
{
    $out = ['take' => [], 'held' => 0, 'asleep' => 0];
    foreach (snapshotAll($state) as $s) {
        if (!is_array($s) || !empty($s['docker']) || !empty($s['partner'])) {
            continue;
        }
        $name = (string) ($s['name'] ?? '');
        $ds = (string) ($s['ds'] ?? '');
        if (($s['fs'] ?? '') === 'zfs') {
            if (!backupIsEngineSnap($name, $prefixes) || !clZfsNameOk($ds)
                || str_contains("/$ds/", '/' . PARTNER_PARENT . '/') || str_contains($ds, CL_TRASH) || backupLetGoRestored($ds)) {
                continue;
            }
        } elseif (($s['fs'] ?? '') === 'btrfs') {
            if (!preg_match('/^\d{8}-\d{4}$/D', $name) || ($s['path'] ?? '') !== rtrim($ds, '/') . "/$btrfsDir/$name") {
                continue;
            }
        } else {
            continue;                         // VMs' snapshots are Unraid's
        }
        if (!empty($s['asleep'])) {
            $out['asleep']++;
        } elseif (!empty($s['holds']) || !empty($s['clones'])) {
            $out['held']++;
        } else {
            $out['take'][] = $s;
        }
    }
    return $out;
}

/**
 * A dataset Mr. Restori pulled back from a partner (agent/desks/restore-partner.php rspTarget()): <dataset>.restored-<time>
 * beside the original, or under <pool>/UnraidSecretaryOffice-restored/ on a new server. It arrives with the engine's
 * snapshot names (the partner received them from the engine), but it is his, not Mr. Backupsy's: never cleared away.
 */
function backupLetGoRestored(string $ds): bool
{
    return (bool) preg_match('#\.restored-\d{8}-\d{6}(?:/|$)#D', $ds) || str_contains("/$ds/", '/' . RSP_PARENT . '/');
}

/** «What would go?» — the dialog's look: nothing changes */
function backupLetGoLook(): array
{
    $f = backupLetGoFacts();
    $host = backupLetGoHost();
    $packages = [];
    if (!$f['asleep']) {
        foreach (backupLetGoParts($f['place'], clRoots(), sleepingDisks()) as $part) {
            array_push($packages, ...backupLetGoPackages($part['place']));
        }
    }
    $state = ($host['scan'])();
    $GLOBALS['snapshot'] = $state;            // her estimate reads her look
    $snaps = backupLetGoSnaps($state, $f['prefixes'], $f['btrfs_dir']);
    $estimate = snapshotEstimate(array_column($snaps['take'], 'id'));
    $cron = backupSchedule();
    return ['ok' => true] + backupLetGoLookOf(backupLetGoBusy(), $f['place'], $f['asleep'], $packages, $snaps, $estimate, $cron['enabled'] ? (string) $cron['custom'] : null);
}

/** The look's answer (apart, for the tests) */
function backupLetGoLookOf(?string $busy, ?string $place, bool $placeAsleep, array $packages, array $snaps, array $estimate, ?string $cron): array
{
    $labels = array_values(array_unique(array_map('backupLetGoLabel', $packages)));
    $btrfs = count(array_filter($snaps['take'], fn ($s) => $s['fs'] === 'btrfs'));
    return [
        'busy'      => $busy,
        'place'     => $place,
        'packages'  => ['n' => count($packages), 'names' => array_slice($labels, 0, LETGO_SHOWN), 'asleep' => $placeAsleep],
        'snapshots' => ['n' => count($snaps['take']), 'zfs' => count($snaps['take']) - $btrfs, 'btrfs' => $btrfs, 'bytes' => (int) $estimate['bytes'],
                        'unknown' => $btrfs + (int) $estimate['failed'], 'held' => $snaps['held'], 'asleep' => $snaps['asleep'] + (int) $estimate['asleep']],
        'schedule'  => $cron,
    ];
}

/** backup.letgo_clear {confirm: true} — the clearing (see the head of this file); refused while the engine's lock is held */
function backupLetGoClear(array $r): array
{
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $out = backupLetGoUnderLock(function (): array {
        $f = backupLetGoFacts();
        $parts = $f['asleep'] ? [] : backupLetGoParts($f['place'], clRoots(), sleepingDisks());
        return backupLetGoDo($f['place'], $f['asleep'], $parts, $f['prefixes'], $f['btrfs_dir']);
    });
    try {
        backupScan();                          // his packages are gone from his page
    } catch (Throwable $e) {
        logLine('Mr. Backup: look after clearing away failed: ' . $e->getMessage());
    }
    return ['ok' => true] + $out;
}

/**
 * The engine's lock for the clearing, like Mr. Restori's rsLockTake(): state/lock opened for appending (never truncating),
 * taken without waiting, touched, and noted in state/lock-holder.json as {holder: backup, mode: letgo} (a new file +
 * rename). Busy — or the setup running — the busy key in backupLetGoBusy()'s words instead of a handle.
 *
 * @return resource|string
 */
function backupLetGoLockTake(): mixed
{
    if ($busy = backupLetGoBusy()) {
        return $busy;
    }
    $state = backupLetGoUbData() . '/state';
    if (!is_dir($state)) {
        @mkdir($state, 0700, true);
    }
    $file = "$state/lock";
    $h = is_link($file) || is_link($state) ? false : @fopen($file, 'a');
    if (!$h) {
        return 'backup_running';
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return backupLetGoBusy() ?? 'backup_running';
    }
    @touch($file);
    try {
        writeAtomic("$state/lock-holder.json", jsonEncode(['holder' => 'backup', 'mode' => 'letgo', 'what' => '', 'run' => '',
            'pid' => getmypid(), 'started' => time(), 'version' => AGENT_VERSION]), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Mr. Backup: could not note the lock\'s holder: ' . $e->getMessage());     // the lock itself stays the truth
    }
    return $h;
}

/** Gives the lock back; the note goes only while it is still his own (same pid) */
function backupLetGoLockRelease(mixed $h): void
{
    $note = backupLetGoUbData() . '/state/lock-holder.json';
    if ((int) (readJson($note)['pid'] ?? 0) === getmypid()) {
        @unlink($note);
    }
    if (is_resource($h)) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/** $work under the engine's lock, given back on every path; busy — refused (Problem) before anything is done */
function backupLetGoUnderLock(callable $work): mixed
{
    $lock = backupLetGoLockTake();
    if (!is_resource($lock)) {
        throw new Problem($lock);
    }
    try {
        return $work();
    } finally {
        backupLetGoLockRelease($lock);
    }
}

/** A new journal file (root only, never through a link); null when it can't be */
function backupLetGoJournalNew(): ?string
{
    $dir = backupLetGoDir();
    clearstatcache(true, $dir);
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir)) || (!is_dir($dir) && !@mkdir($dir, 0700))) {
        return null;
    }
    $st = @lstat($dir);
    if (!$st || $st['uid'] !== 0 && posix_geteuid() === 0) {
        return null;                           // somebody else's folder: nothing of his goes there
    }
    @chmod($dir, 0700);
    $stamp = date('Ymd-His');
    $file = "$dir/letgo-$stamp.json";
    for ($i = 2; file_exists($file); $i++) {
        $file = "$dir/letgo-$stamp-$i.json";
    }
    return $file;
}

function backupLetGoJournalWrite(string $file, array $j): void
{
    try {
        writeAtomic($file, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Mr. Backup: could not write ' . basename($file) . ': ' . $e->getMessage());
    }
}

/**
 * The clearing itself: the packages of $parts into the storeroom, then the engine's own snapshots deleted — each step
 * journalled; a step that fails is noted and the rest goes on.
 *
 * @param list<array{root: string, top: string, place: string}> $parts backupLetGoParts()
 * @return array{moved: int, deleted: int, failed: list<array>, failed_n: int, kept: int, place_asleep: bool, journal: string, runs: list<string>}
 */
function backupLetGoDo(?string $place, bool $placeAsleep, array $parts, array $prefixes, string $btrfsDir): array
{
    $file = backupLetGoJournalNew() ?? throw new Problem('backup_letgo_journal', ['path' => backupLetGoDir()]);
    $j = ['interface' => 1, 'written_by' => 'Unraid Secretary Office (Mr. Backupsy, let go)', 'started' => time(), 'finished' => null,
          'host' => hostname(), 'place' => $place, 'place_asleep' => $placeAsleep,
          'kept' => ['settings.ini', 'decisions', 'schedule', 'kopia', 'partners'],
          'trash' => [], 'moved' => [], 'deleted' => [], 'failed' => [], 'snapshots_kept' => 0];
    backupLetGoJournalWrite($file, $j);
    $fail = function (string $what, string $desk, Problem|array $e) use (&$j, $file): void {
        $err = $e instanceof Problem ? $e->toArray() : $e;
        $j['failed'][] = ['what' => $what, 'desk' => $desk] + $err;
        backupLetGoJournalWrite($file, $j);
        logLine("Mr. Backup: could not clear away $what: " . $err['key'] . ' ' . json_encode($err['params'] ?? [], JSON_UNESCAPED_SLASHES));
    };

    // the packages: into Ms. Dustdevil's storeroom on their own filesystem, one run per storeroom
    $host = backupLetGoHost();
    $runs = [];
    foreach ($parts as $part) {
        foreach (backupLetGoPackages($part['place']) as $p) {
            $label = backupLetGoLabel($p);
            $trash = clLeftoverTrash($p['path'], $part['top']);
            if ($trash === null || (@stat($p['path'])['dev'] ?? null) !== (@stat(dirname($trash))['dev'] ?? null)) {
                $fail($p['path'], 'cleanup', new Problem('cleanup_own_fs', ['name' => $label]));     // a filesystem of its own: never copied
                continue;
            }
            try {
                if (!isset($runs[$trash])) {
                    $runs[$trash] = clRunCreate($trash);
                    $j['trash'] = array_values(array_unique(array_merge($j['trash'], [$trash])));
                }
                $as = 'packages/' . substr(md5(dirname($p['path'])), 0, 8) . '/' . basename($p['path']);
                ($host['move'])($p['path'], $runs[$trash]['path'] . "/$as");
                $runs[$trash]['items'][] = ['kind' => 'package', 'name' => $label, 'label' => $part['root'], 'from' => $p['path'], 'as' => $as];
                clManifestWrite($runs[$trash]);
                $j['moved'][] = ['from' => $p['path'], 'to' => $runs[$trash]['path'] . "/$as", 'name' => $label];
                backupLetGoJournalWrite($file, $j);
            } catch (Throwable $e) {
                // anything (her manifest not written: the pool full) ends this package only — noted, the rest goes on
                $fail($p['path'], 'cleanup', backupLetGoError($e));
            }
        }
    }
    foreach ($runs as $run) {
        if (!$run['items']) {
            clRunTidy($run['path'], $run['root']);
        }
    }

    // the engine's own snapshots, as Ms. Snapshotini sees them now
    $snaps = ['take' => [], 'held' => 0, 'asleep' => 0];
    try {
        $snaps = backupLetGoSnaps(($host['scan'])(), $prefixes, $btrfsDir);
    } catch (Throwable $e) {
        $fail('snapshots', 'backup', backupLetGoError($e));
    }
    $j['snapshots_kept'] = $snaps['held'] + $snaps['asleep'];
    $zfs = [];
    foreach ($snaps['take'] as $s) {
        if (snapshotFixedMounts($s)) {
            try {
                snapshotUnmount($s);           // a mount the engine left (keep_mounts, a killed run): zfs destroy won't
            } catch (Throwable $e) {
                $fail($s['id'], 'snapshot', backupLetGoError($e));
                continue;
            }
        }
        if ($s['fs'] === 'zfs') {
            $zfs[$s['ds']][] = $s['name'];
        } else {
            [$exit, , $err] = run([(string) $host['btrfs'], 'subvolume', 'delete', $s['path']], 300);
            if ($exit === 0) {
                snapshotRecord(['do' => 'deleted', 'fs' => 'btrfs', 'path' => $s['path']]);
                logLine("Mr. Backup: deleted {$s['path']} (btrfs, let go)");
                $j['deleted'][] = $s['path'];
                backupLetGoJournalWrite($file, $j);
            } else {
                $fail($s['path'], 'backup', ['key' => 'backup_letgo_delete_failed', 'params' => ['name' => $s['path'], 'detail' => trim($err)]]);
            }
        }
    }
    foreach ($zfs as $ds => $names) {
        foreach (array_chunk($names, LETGO_BATCH) as $batch) {
            // one command per batch; one that fails (a busy snapshot) is tried name by name, so the others still go
            foreach (backupLetGoDestroy((string) $host['zfs'], $ds, $batch) as [$gone, $err]) {
                if ($err === null) {
                    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => $ds, 'names' => $gone]);
                    foreach ($gone as $n) {
                        $j['deleted'][] = "$ds@$n";
                    }
                    backupLetGoJournalWrite($file, $j);
                } else {
                    $fail("$ds@{$gone[0]}", 'backup', ['key' => 'backup_letgo_delete_failed', 'params' => ['name' => "$ds@{$gone[0]}", 'detail' => $err]]);
                }
            }
        }
    }
    if ($snaps['take']) {
        try {
            ($host['rescan'])();               // her page without them
        } catch (Throwable $e) {
            logLine('Mr. Backup: Ms. Snapshotini\'s look after clearing away failed: ' . $e->getMessage());
        }
    }

    $j['finished'] = time();
    backupLetGoJournalWrite($file, $j);
    logLine(sprintf('Mr. Backup: let go and cleared away: %d packages into the storeroom, %d snapshots deleted, %d failed (%s)',
        count($j['moved']), count($j['deleted']), count($j['failed']), basename($file)));
    return ['moved' => count($j['moved']), 'deleted' => count($j['deleted']), 'failed' => array_slice($j['failed'], 0, 20), 'failed_n' => count($j['failed']),
            'kept' => $j['snapshots_kept'], 'place_asleep' => $placeAsleep, 'journal' => basename($file), 'runs' => array_column($runs, 'path')];
}

/** A step's failure for the journal: a Problem as it is, anything else as `internal` with its message */
function backupLetGoError(Throwable $e): Problem|array
{
    return $e instanceof Problem ? $e : ['key' => 'internal', 'params' => ['detail' => mb_substr($e->getMessage(), 0, 400)]];
}

/**
 * zfs destroy <ds>@a,b,c — and when that fails, each name alone.
 *
 * @return list<array{0: list<string>, 1: ?string}> what went (or the one that didn't) and the error (null: gone)
 */
function backupLetGoDestroy(string $zfs, string $ds, array $names): array
{
    [$exit, , $err] = run([$zfs, 'destroy', "$ds@" . implode(',', $names)], 600);
    if ($exit === 0) {
        logLine("Mr. Backup: deleted $ds@" . implode(',', $names) . ' (let go)');
        return [[$names, null]];
    }
    if (count($names) === 1) {
        return [[$names, trim($err) ?: "zfs exit $exit"]];
    }
    $out = [];
    foreach ($names as $n) {
        array_push($out, ...backupLetGoDestroy($zfs, $ds, [$n]));
    }
    return $out;
}

/**
 * The storerooms the let-go journals name — for Ms. Dustdevil (clScan()), who doesn't look for them on array disks or
 * in a share's own datasets: only a path in the shape written here (`/mnt/<pool or disk>/<share>/…/<storeroom>`),
 * never on a sleeping disk, from the newest LETGO_JOURNALS journals.
 *
 * @return list<string>
 */
function backupLetGoTrashRoots(array $asleep, ?string $dir = null): array
{
    $dir ??= backupLetGoDir();
    if (is_link($dir) || !is_dir($dir)) {
        return [];
    }
    $files = array_values(preg_grep(LETGO_JOURNAL, @scandir($dir) ?: []));
    rsort($files, SORT_STRING);
    $roots = [];
    foreach (array_slice($files, 0, LETGO_JOURNALS) as $f) {
        if (is_link("$dir/$f")) {
            continue;
        }
        foreach ((array) ((readJson("$dir/$f") ?? [])['trash'] ?? []) as $t) {
            if (is_string($t) && clTrashPathOk($t) && basename($t) === CL_TRASH && preg_match('#^/mnt/([^/]+)/[^/]+/#', $t, $m)
                && !in_array($m[1], ['user', 'user0', 'addons', 'disks', 'remotes', 'rootshare'], true) && !baseAsleep($m[1], $asleep)) {
                $roots[$t] = true;
            }
        }
    }
    return array_keys($roots);
}
