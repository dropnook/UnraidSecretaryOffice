<?php
declare(strict_types=1);

/*
 * Ms. Snapshot — keeps track of every snapshot on the server.
 *
 *   ZFS     the awake pools (a pool sleeps when any of its disks does — it is never asked, it keeps what
 *           she last saw of it, marked asleep; the page's «wake» lists it too); Docker's image layers are
 *           recognised and left alone
 *   btrfs   array disks and pools (snapshots in <disk>/.btrfs-snap like the
 *           unraid-backup script); sleeping disks are only read on request
 *   VMs     Unraid's own snapshot list (snapshotdb) plus libvirt — read only
 *
 * She can create, delete (unmounting first if asked), rename, hold/release
 * and estimate how much space a deletion frees, and take snapshots on a
 * schedule with a simple retention (lib/snapshotplans.php). Every request is checked
 * against a fresh scan; commands run without a shell.
 *
 * A partner office's copies (<pool>/UnraidSecretaryOffice-partners/<pair>/<unit>, received by the partner door —
 * agent/lib/partnerlook.php) carry `partner` {id, name, gone}: shown as «partner's copy», never a target of hers
 * (create, schedules: partner_dataset), never deleted, renamed, held or released (partner_copy) — the door's own
 * retention keeps them — unless the pair is gone from the pairs (then they are leftovers, Ms. Dustdevil's room).
 */

require_once __DIR__ . '/../lib/partnerlook.php';

const SNAPSHOT_HOLD_TAG   = 'unraid-secretary-office';
const SNAPSHOT_HOLD_TAGS  = ['unraid-secretary-office', 'snapshots-webseite'];   // ours, incl. the old name
const SNAPSHOT_BTRFS_DIR  = '.btrfs-snap';
const SNAPSHOT_NAME       = '/^[A-Za-z0-9][A-Za-z0-9_.:+-]{0,79}$/D';   // D: a trailing newline doesn't pass
const VM_SNAPSHOT_DB      = '/etc/libvirt/qemu/snapshotdb';
const VM_XML_DIR          = '/etc/libvirt/qemu';

$GLOBALS['snapshot'] = null;          // last scan
$GLOBALS['dockerParent'] = null;      // dataset under which Docker keeps its layers

desk('snapshot', [
    'fit'     => function (): array {
        $fs = houseSnapshotFilesystems();
        $n = count($fs['zfs']) + count($fs['btrfs']);
        return $n ? fit(true, 'yes', ['places' => implode(', ', array_merge($fs['zfs'], $fs['btrfs']))]) : fit(false, 'no_cow');
    },
    'start' => function (): void {
        if (!snapshotRecordReady()) {          // her record of what she removes, for the night watchman (root only)
            logLine('Ms. Snapshotini: could not set up her record ' . snapshotRecordFile());
        }
        $old = readJson(deskFile('snapshot'));
        if ($old) {
            $GLOBALS['snapshot'] = $old;
            $GLOBALS['dockerParent'] = $old['zfs']['docker_parent'] ?? null;
        }
        snapshotScan(true);
        try {
            if (snapPlans()) {
                snapPlanRunner();      // the User Scripts entry as this version writes it
            }
        } catch (Throwable $e) {
            logLine('Snapshot schedules: ' . $e->getMessage());
        }
    },
    'actions' => [
        'refresh'  => fn (array $r) => ['ok' => true, 'state' => snapshotScan(false)],
        'scan'     => fn (array $r) => snapshotScanRequest(!empty($r['wake'])),
        'estimate' => fn (array $r) => ['ok' => true] + snapshotEstimate(idList($r, 'ids')),
        'create'   => fn (array $r) => snapshotCreate($r),
        // on a sleeping pool only with `wake` (the page asks for it explicitly) — never woken on her own
        'delete'   => fn (array $r) => snapshotDelete(idList($r, 'ids'), !empty($r['unmount']), !empty($r['wake'])),
        'rename'   => fn (array $r) => snapshotRename(textField($r, 'id'), textField($r, 'name'), !empty($r['wake'])),
        'hold'     => fn (array $r) => snapshotHold(textField($r, 'id'), true, !empty($r['wake'])),
        'release'  => fn (array $r) => snapshotHold(textField($r, 'id'), false, !empty($r['wake'])),
        'unmount'  => fn (array $r) => snapshotUnmountRequest(textField($r, 'id'), !empty($r['wake'])),
        'plan_save'   => fn (array $r) => snapPlanSave($r['plan'] ?? null),
        'plan_toggle' => fn (array $r) => snapPlanToggle(textField($r, 'id'), boolField($r, 'enabled')),
        'plan_delete' => fn (array $r) => snapPlanDelete(textField($r, 'id')),
        'plan_run'    => fn (array $r) => snapPlanRunNow(textField($r, 'id')),
    ],
    'checks'  => fn () => snapPlanChecks(),
    'metrics' => fn (): array => snapshotMetrics(),
]);

/**
 * Ms. Snapshotini's numbers for Prometheus (lib/metrics.php, once a minute):
 * from her state file (as of her last look — written by her scans and by the
 * schedules' job) and her plans' files. Docker's image layers don't count.
 */
function snapshotMetrics(?string $file = null): array
{
    $seen = metricsCached($file ?? deskFile('snapshot'), fn (string $f) => snapshotMetricsPools(readJson($f)));
    $out = [];
    if ($seen !== null) {
        $count = $newest = $used = [];
        foreach ($seen['pools'] as $p) {
            $labels = ['fs' => $p['fs'], 'pool' => $p['pool']];
            $count[] = [$labels, $p['n']];
            if ($p['t'] > 0) {
                $newest[] = [$labels, $p['t']];
            }
            if ($p['used'] !== null) {
                $used[] = [$labels, $p['used']];
            }
        }
        $out[] = metricsGauge('uso_snapshot_snapshots', 'Snapshots per ZFS pool, btrfs disk and of the VMs (without Docker\'s image layers)', $count);
        $out[] = metricsGauge('uso_snapshot_newest_timestamp_seconds', 'When the newest snapshot of a pool or disk was taken', $newest);
        $out[] = metricsGauge('uso_snapshot_used_bytes', 'Space held by the snapshots of a ZFS pool', $used);
        $out[] = metricsGauge('uso_snapshot_scanned_timestamp_seconds', 'When Ms. Snapshotini last looked (the numbers above are from then)', $seen['time']);
    }
    $states = snapPlanStates();
    $active = array_filter(snapPlans(), fn ($p) => !empty($p['enabled']) && is_string($p['id'] ?? null));
    if ($active) {
        $ok = $when = [];
        $failing = 0;
        foreach ($active as $p) {
            $st = is_array($states[$p['id']] ?? null) ? $states[$p['id']] : [];
            if (!isset($st['result'])) {
                continue;                       // not run yet
            }
            // gone = every target of the plan is gone: the run creates nothing, so it is no good run either
            $bad = in_array($st['result'], ['failed', 'partly', 'gone'], true);
            $failing += (int) $bad;
            $ok[] = [['plan' => $p['id']], !$bad];
            $when[] = [['plan' => $p['id']], (int) ($st['last_run'] ?? 0)];
        }
        $out[] = metricsGauge('uso_snapshot_plans_failing', 'Active snapshot plans whose last run failed, only partly worked or found every target gone', $failing);
        $out[] = metricsGauge('uso_snapshot_plan_ok', 'Whether an active snapshot plan\'s last run worked (1) or had problems (0: failed, partly, every target gone)', $ok);
        $out[] = metricsGauge('uso_snapshot_plan_last_run_timestamp_seconds', 'When an active snapshot plan last ran', $when);
    }
    return $out;
}

/**
 * Per ZFS pool, btrfs disk and for the VMs: how many snapshots, the newest
 * one's time, the space they hold (ZFS) — null without a state
 *
 * @return array{time:int, pools:list<array{fs:string, pool:string, n:int, t:int, used:?int}>}|null
 */
function snapshotMetricsPools(?array $s): ?array
{
    if (!$s) {
        return null;
    }
    $pools = [];
    foreach ((array) ($s['zfs']['pools'] ?? []) as $p) {
        if (is_array($p) && is_string($p['name'] ?? null)) {
            $pools["zfs\t{$p['name']}"] = ['fs' => 'zfs', 'pool' => $p['name'], 'n' => 0, 't' => 0, 'used' => (int) ($p['snapused'] ?? 0)];
        }
    }
    foreach ((array) ($s['btrfs']['devices'] ?? []) as $d) {
        // a disk she hasn't read yet (asleep since the agent started) is "not known", not "none"
        if (is_array($d) && is_string($d['name'] ?? null) && !empty($d['scanned']) && empty($d['error'])) {
            $pools["btrfs\t{$d['name']}"] = ['fs' => 'btrfs', 'pool' => $d['name'], 'n' => 0, 't' => 0, 'used' => null];
        }
    }
    if (!empty($s['vm']['available'])) {
        $pools["vm\tVMs"] = ['fs' => 'vm', 'pool' => 'VMs', 'n' => 0, 't' => 0, 'used' => null];
    }
    foreach (['zfs', 'btrfs', 'vm'] as $fs) {
        foreach ((array) ($s[$fs]['snapshots'] ?? []) as $x) {
            if (!is_array($x) || !empty($x['docker']) || !is_string($x['pool'] ?? null)) {
                continue;
            }
            $key = "$fs\t{$x['pool']}";
            $pools[$key] ??= ['fs' => $fs, 'pool' => $x['pool'], 'n' => 0, 't' => 0, 'used' => null];
            $pools[$key]['n']++;
            $pools[$key]['t'] = max($pools[$key]['t'], (int) ($x['t'] ?? 0));
        }
    }
    return ['time' => (int) ($s['time'] ?? 0), 'pools' => array_values($pools)];
}

// ===================================================================== scanning

/**
 * Reads ZFS (the awake pools — a sleeping pool keeps its last list) and VMs every time. btrfs only when
 * asked: the disks have to be read for that, and sleeping array disks would wake up. $wake: the sleeping
 * pools and disks too — only ever because the user asked for it.
 *
 * @param list<string> $btrfsOnly  read just these mounts (after a change there)
 */
function snapshotScan(bool $readBtrfs, bool $wake = false, array $btrfsOnly = []): array
{
    $t0 = microtime(true);
    $old = $GLOBALS['snapshot'];

    $zfs = snapshotPartnerMark(snapshotReadZfs($old, $wake));
    $vm = snapshotReadVms();
    $btrfs = snapshotBtrfsPart($old['btrfs'] ?? null, $readBtrfs, $wake, $btrfsOnly);

    // what is mounted where (cheap: /proc only)
    $backup = backupScriptState();
    $table = mountTable();
    $roots = backupScriptRoots($backup);
    $btrfs['snapshots'] = array_merge($btrfs['snapshots'], snapshotBtrfsFromMounts($btrfs['snapshots'], $table));
    $zfs['snapshots'] = snapshotAttachMounts($zfs['snapshots'], $table, $roots);
    $btrfs['snapshots'] = snapshotAttachMounts($btrfs['snapshots'], $table, $roots);
    unset($backup['settings']);

    $state = [
        'time'        => time(),
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'host'        => hostname(),
        'agent'       => AGENT_VERSION,
        'zfs'         => $zfs,
        'btrfs'       => $btrfs,
        'vm'          => $vm,
        'backup'      => $backup,
    ];
    $state['plans'] = snapPlansPublic($state);
    $GLOBALS['snapshot'] = $state;
    writeAtomic(deskFile('snapshot'), jsonEncode($state));
    return $state;
}

function snapshotScanRequest(bool $wake): array
{
    $before = snapshotVisibleIds($GLOBALS['snapshot']);
    $state = snapshotScan(true, $wake);
    $after = snapshotVisibleIds($state);
    $new = array_values(array_diff($after, $before));
    $gone = array_values(array_diff($before, $after));
    $asleep = snapshotPoolsAsleep($state);
    logLine(sprintf('Snapshot scan%s: %d snapshots, %d new, %d gone, %d ms%s',
        $wake ? ' (waking disks)' : '', count($after), count($new), count($gone), $state['duration_ms'],
        $asleep ? ' — asleep, kept as last seen: ' . implode(', ', $asleep) : ''));
    return ['ok' => true, 'state' => $state, 'new' => $new, 'gone' => $gone];
}

/** The ZFS pools a state shows as they were last seen — asleep, not asked (names) */
function snapshotPoolsAsleep(?array $state): array
{
    $out = [];
    foreach ((array) ($state['zfs']['pools'] ?? []) as $p) {
        if (is_array($p) && !empty($p['asleep']) && is_string($p['name'] ?? null)) {
            $out[] = $p['name'];
        }
    }
    return $out;
}

/** Snapshot ids without Docker's layers */
function snapshotVisibleIds(?array $state): array
{
    $ids = [];
    foreach (snapshotAll($state) as $s) {
        if (empty($s['docker'])) {
            $ids[] = $s['id'];
        }
    }
    return $ids;
}

function snapshotAll(?array $state): array
{
    if (!$state) {
        return [];
    }
    return array_merge($state['zfs']['snapshots'] ?? [], $state['btrfs']['snapshots'] ?? [], $state['vm']['snapshots'] ?? []);
}

function snapshotIndex(array $state): array
{
    $index = [];
    foreach (snapshotAll($state) as $s) {
        $index[$s['id']] = $s;
    }
    return $index;
}

// --------------------------------------------------------------------- ZFS

/**
 * ZFS: the pools (`zpool list` — the kernel's own bookkeeping, no disk asked), then the datasets and
 * snapshots of the AWAKE pools only (`zfs list … -r <pool>`; disks.ini says which sleep, a pool sleeps when
 * any of its disks does, `poolsBySleep()`). A sleeping pool is never asked: whether zfs would read its disks
 * depends on what the ARC still holds, and the office never risks waking one. It keeps what she last saw of
 * it ($old: her last scan — at her start the state file): its datasets and snapshots as they were, each
 * marked `asleep`, the pool `asleep` with `looked` = when that was (null: not looked at since the agent
 * started — its lists are then empty, which is «not known», not «none»). $wake lists the sleeping pools too —
 * only ever because the user asked for it. $host: stand-ins, tests only — `zfs`, `zpool` (paths), `docker`
 * (null: Docker isn't asked).
 */
function snapshotReadZfs(?array $old = null, bool $wake = false, ?array $host = null): array
{
    $host ??= $GLOBALS['snapshotHost'] ?? [];
    $zfs = $host['zfs'] ?? bin('zfs');
    $zpool = $host['zpool'] ?? bin('zpool');
    $empty = ['available' => false, 'pools' => [], 'asleep' => [], 'volumes' => [], 'snapshots' => [], 'docker_parent' => null, 'docker_datasets' => 0];
    if (!$zfs || !$zpool) {
        return $empty;
    }

    [$exit, $out, $err] = run([$zpool, 'list', '-Hp', '-o', 'name,size,alloc,free,cap,health,frag'], 90);
    if ($exit !== 0) {
        throw new Problem('command_failed', ['detail' => 'zpool list: ' . trim($err)]);
    }
    $poolRows = [];
    foreach (rows($out) as $f) {
        if (count($f) >= 7 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $f[0])) {
            $poolRows[$f[0]] = $f;
        }
    }
    $split = poolsBySleep(array_keys($poolRows));
    $look = $wake ? array_keys($poolRows) : $split['awake'];
    $kept = $wake ? [] : $split['asleep'];
    $keptSet = array_flip($kept);

    $r = ['ds' => [0, '', ''], 'snaps' => [0, '', '']];
    if ($look) {
        $r = runAll([
            'ds'    => [$zfs, 'list', '-Hp', '-t', 'filesystem,volume', '-o', 'name,type,used,avail,refer,usedbysnapshots,mountpoint', '-r', ...$look],
            'snaps' => [$zfs, 'list', '-Hp', '-t', 'snapshot', '-o', 'name,guid,creation,used,refer,written,userrefs,clones', '-r', ...$look],
        ], 90);
        foreach ($r as $part => [$exit, , $err]) {
            if ($exit !== 0) {
                throw new Problem('command_failed', ['detail' => "zfs list ($part): " . trim($err)]);
            }
        }
    }

    $parent = snapshotDockerParent($host);

    $volumes = [];
    $dockerDatasets = [];
    $poolSnapUsed = [];
    foreach (rows($r['ds'][1]) as $f) {
        if (count($f) < 7) {
            continue;
        }
        [$name, $type, $used, $avail, $refer, $snapused, $mountpoint] = $f;
        $pool = explode('/', $name)[0];
        $poolSnapUsed[$pool] = ($poolSnapUsed[$pool] ?? 0) + num($snapused);
        if (snapshotIsDockerLayer($name, $mountpoint, $parent)) {
            $dockerDatasets[$name] = true;
            continue;
        }
        $volumes[$name] = [
            'id'       => "zfs:$name",
            'fs'       => 'zfs',
            'pool'     => $pool,
            'name'     => $name,
            'type'     => $type,
            'used'     => num($used),
            'avail'    => num($avail),
            'refer'    => num($refer),
            'snapused' => num($snapused),
            'mount'    => str_starts_with($mountpoint, '/') ? $mountpoint : null,
            'children' => 0,
        ];
    }
    foreach ($volumes as $name => $_) {
        $up = dirname($name);
        if ($up !== '.' && isset($volumes[$up])) {
            $volumes[$up]['children']++;
        }
    }

    $snaps = [];
    $held = [];
    foreach (rows($r['snaps'][1]) as $f) {
        if (count($f) < 8) {
            continue;
        }
        [$full, $guid, $creation, $used, $refer, $written, $userrefs, $clones] = $f;
        [$ds, $short] = explode('@', $full, 2) + [1 => ''];
        $mount = $volumes[$ds]['mount'] ?? null;
        $snaps[$full] = [
            'id'      => "zfs:$full",
            'fs'      => 'zfs',
            'pool'    => explode('/', $ds)[0],
            'vol'     => "zfs:$ds",
            'ds'      => $ds,
            'name'    => $short,
            't'       => num($creation),
            'used'    => num($used),
            'refer'   => num($refer),
            'written' => $written === '-' ? null : num($written),
            'holds'   => [],
            'clones'  => ($clones === '' || $clones === '-') ? [] : explode(',', $clones),
            'guid'    => $guid,
            'path'    => $mount ? "$mount/.zfs/snapshot/$short" : null,
            'docker'  => isset($dockerDatasets[$ds]),
        ];
        if (num($userrefs) > 0) {
            $held[] = $full;
        }
    }
    foreach (array_chunk($held, 200) as $batch) {
        [, $out] = run(array_merge([$zfs, 'holds', '-H'], $batch), 60);
        foreach (rows($out) as $f) {
            if (count($f) >= 2 && isset($snaps[$f[0]])) {
                $snaps[$f[0]]['holds'][] = $f[1];
            }
        }
    }

    // the sleeping pools: what she last saw of them, as it was (never asked now)
    $oldPools = [];
    foreach ((array) ($old['zfs']['pools'] ?? []) as $p) {
        if (is_array($p) && is_string($p['name'] ?? null)) {
            $oldPools[$p['name']] = $p;
        }
    }
    if ($kept) {
        foreach ((array) ($old['zfs']['volumes'] ?? []) as $v) {
            if (is_array($v) && is_string($v['name'] ?? null) && isset($keptSet[$v['pool'] ?? '']) && !isset($volumes[$v['name']])) {
                $v['asleep'] = true;
                $volumes[$v['name']] = $v;
            }
        }
        foreach ((array) ($old['zfs']['snapshots'] ?? []) as $s) {
            if (is_array($s) && is_string($s['id'] ?? null) && str_starts_with($s['id'], 'zfs:') && isset($keptSet[$s['pool'] ?? ''])
                && !isset($snaps[substr($s['id'], 4)])) {
                $s['asleep'] = true;
                $s['holds'] = array_values(array_filter((array) ($s['holds'] ?? []), 'is_string'));
                $snaps[substr($s['id'], 4)] = $s;
            }
        }
    }

    $count = $dockerCount = [];
    foreach ($snaps as $s) {
        if ($s['docker']) {
            $dockerCount[$s['pool']] = ($dockerCount[$s['pool']] ?? 0) + 1;
        } else {
            $count[$s['pool']] = ($count[$s['pool']] ?? 0) + 1;
        }
    }
    $now = time();
    $pools = [];
    foreach ($poolRows as $name => $f) {
        [, $size, $alloc, $free, $cap, $health, $frag] = $f;
        $root = $volumes[$name] ?? null;
        $sleeping = isset($keptSet[$name]);
        $was = $oldPools[$name] ?? null;
        // when a sleeping pool was last looked at: carried along (a state from before 1.33 has no `looked` — her scan's time then)
        $looked = $now;
        if ($sleeping) {
            $looked = $was === null ? null : (array_key_exists('looked', $was) ? $was['looked'] : ($old['time'] ?? null));
            $looked = is_int($looked) && $looked > 0 ? $looked : null;
        }
        $pools[] = [
            'name'     => $name,
            'size'     => num($size),
            'alloc'    => num($alloc),
            'free'     => num($free),
            'cap'      => num($cap),
            'health'   => $health,
            'frag'     => $frag === '-' ? null : num($frag),
            'used'     => $root['used'] ?? null,     // usable space, parity excluded
            'avail'    => $root['avail'] ?? null,
            'snapused' => $sleeping ? (int) ($was['snapused'] ?? 0) : ($poolSnapUsed[$name] ?? 0),
            'count'    => $count[$name] ?? 0,
            'docker'   => $dockerCount[$name] ?? 0,
            'asleep'   => $sleeping,
            'looked'   => $looked,
        ];
    }

    return [
        'available'       => true,
        'pools'           => $pools,
        'asleep'          => $kept,
        'volumes'         => array_values($volumes),
        'snapshots'       => array_values($snaps),
        'docker_parent'   => $parent,
        'docker_datasets' => count($dockerDatasets),
    ];
}

/** Docker's zfs storage driver keeps every image layer as its own dataset. ($host: tests — `docker` null: not asked) */
function snapshotDockerParent(?array $host = null): ?string
{
    $docker = array_key_exists('docker', $host ?? []) ? $host['docker'] : bin('docker');
    if ($docker && file_exists('/var/run/docker.sock')) {
        [$exit, $out] = run([$docker, 'info', '--format', '{{json .DriverStatus}}'], 15);
        if ($exit === 0) {
            foreach ((array) json_decode(trim($out), true) as $pair) {
                if (is_array($pair) && ($pair[0] ?? '') === 'Parent Dataset' && is_string($pair[1] ?? null)) {
                    $GLOBALS['dockerParent'] = $pair[1];
                }
            }
        }
    }
    return $GLOBALS['dockerParent'];
}

function snapshotIsDockerLayer(string $name, string $mountpoint, ?string $parent): bool
{
    if ($mountpoint !== 'legacy') {
        return false;
    }
    if ($parent !== null && dirname($name) === $parent) {
        return true;
    }
    return (bool) preg_match('/^([0-9a-f]{64}|[a-z0-9]{25})(-init)?$/', basename($name));
}

// --------------------------------------------------------------------- btrfs

function snapshotBtrfsPart(?array $old, bool $read, bool $wake, array $only): array
{
    if (!bin('btrfs')) {
        return ['available' => false, 'devices' => [], 'snapshots' => []];
    }
    $devices = btrfsDevices();
    $asleep = sleepingDisks();

    $oldDevices = [];
    foreach ($old['devices'] ?? [] as $d) {
        $oldDevices[$d['mount']] = $d;
    }
    $oldSnaps = [];
    foreach ($old['snapshots'] ?? [] as $s) {
        if (empty($s['detected'])) {     // only what was really read, not guessed from mounts
            $oldSnaps[$s['ds']][] = $s;
        }
    }

    $toRead = [];
    foreach ($devices as $mount => $name) {
        if ($only) {
            if (in_array($mount, $only, true)) {
                $toRead[] = $mount;
            }
        } elseif ($read && (!($asleep[$name] ?? false) || $wake)) {
            $toRead[] = $mount;
        }
    }
    $fresh = $toRead ? snapshotReadBtrfs($toRead) : [];

    $out = ['available' => true, 'devices' => [], 'snapshots' => []];
    foreach ($devices as $mount => $name) {
        $d = [
            'mount'   => $mount,
            'name'    => $name,
            'size'    => (int) @disk_total_space($mount),
            'free'    => (int) @disk_free_space($mount),
            'asleep'  => $asleep[$name] ?? false,
            'scanned' => $oldDevices[$mount]['scanned'] ?? null,
            'error'   => $oldDevices[$mount]['error'] ?? null,
        ];
        $snaps = $oldSnaps[$mount] ?? [];
        if (isset($fresh[$mount])) {
            $d['error'] = $fresh[$mount]['error'];
            if ($d['error'] === null) {
                $d['scanned'] = time();
                $d['asleep'] = false;
                $snaps = $fresh[$mount]['snapshots'];
            }
        }
        $out['devices'][] = $d;
        array_push($out['snapshots'], ...$snaps);
    }
    return $out;
}

/** @param list<string> $mounts  @return array<string, array{snapshots: list<array>, error: ?array}> */
function snapshotReadBtrfs(array $mounts): array
{
    $btrfs = bin('btrfs');
    $commands = [];
    foreach ($mounts as $i => $mount) {
        $commands[$i] = [$btrfs, 'subvolume', 'list', '-s', $mount];
    }
    $lists = runAll($commands, 120);     // in parallel: sleeping disks wake up together

    $commands = [];
    foreach ($mounts as $i => $mount) {
        $commands["ro$i"] = [$btrfs, 'subvolume', 'list', '-s', '-r', $mount];
        $commands["q$i"] = [$btrfs, 'qgroup', 'show', '--raw', $mount];
    }
    $more = runAll($commands, 60);

    $result = [];
    foreach ($mounts as $i => $mount) {
        [$exit, $out, $err] = $lists[$i];
        if ($exit !== 0) {
            $result[$mount] = ['snapshots' => [], 'error' => ['key' => 'command_failed', 'params' => ['detail' => trim($err) ?: "btrfs exit $exit"]]];
            continue;
        }
        $readonly = [];
        foreach (explode("\n", $more["ro$i"][1]) as $line) {
            if (preg_match('/^ID (\d+) /', $line, $m)) {
                $readonly[$m[1]] = true;
            }
        }
        $sizes = [];
        if ($more["q$i"][0] === 0) {
            foreach (explode("\n", $more["q$i"][1]) as $line) {
                if (preg_match('#^0/(\d+)\s+(\d+)\s+(\d+)#', trim($line), $m)) {
                    $sizes[$m[1]] = [(int) $m[2], (int) $m[3]];
                }
            }
        }
        $list = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^ID (\d+) .*?otime (\S+ \S+) path (.+)$/', $line, $m)) {
                continue;
            }
            [, $sid, $otime, $rel] = $m;
            $path = rtrim($mount, '/') . '/' . ltrim($rel, '/');
            $list[] = [
                'id'       => "btrfs:$path",
                'fs'       => 'btrfs',
                'pool'     => basename($mount),
                'vol'      => "btrfs:$mount",
                'ds'       => $mount,
                'name'     => basename($rel),
                'rel'      => $rel,
                't'        => (int) strtotime($otime),
                'used'     => $sizes[$sid][1] ?? null,
                'refer'    => $sizes[$sid][0] ?? null,
                'written'  => null,
                'holds'    => [],
                'clones'   => [],
                'readonly' => isset($readonly[$sid]),
                'path'     => $path,
                'docker'   => false,
            ];
        }
        $result[$mount] = ['snapshots' => $list, 'error' => null];
    }
    return $result;
}

/**
 * btrfs snapshots that are mounted but in no list — because their disk has
 * been asleep ever since. The mount table knows them anyway, no wake-up needed.
 */
function snapshotBtrfsFromMounts(array $known, array $table): array
{
    $have = array_flip(array_column($known, 'id'));
    $disks = btrfsDisks($table);
    $found = [];
    foreach ($table as $m) {
        $id = snapshotOfMount($m, $disks);
        if ($id === null || !str_starts_with($id, 'btrfs:') || isset($have[$id]) || isset($found[$id])) {
            continue;
        }
        $mount = $disks[$m['dev']];
        $path = substr($id, 6);
        $name = basename($path);
        $t = preg_match('/(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)$/', $name, $z)
            ? (int) mktime((int) $z[4], (int) $z[5], 0, (int) $z[2], (int) $z[3], (int) $z[1]) : 0;
        $found[$id] = [
            'id'       => $id,
            'fs'       => 'btrfs',
            'pool'     => basename($mount),
            'vol'      => "btrfs:$mount",
            'ds'       => $mount,
            'name'     => $name,
            'rel'      => ltrim(substr($path, strlen($mount)), '/'),
            't'        => $t,
            'used'     => null,
            'refer'    => null,
            'written'  => null,
            'holds'    => [],
            'clones'   => [],
            'readonly' => null,
            'path'     => $path,
            'docker'   => false,
            'detected' => 'mount',
        ];
    }
    return array_values($found);
}

// --------------------------------------------------------------------- VMs

/**
 * VM snapshots — read only, Unraid's VM manager is where they are handled.
 *
 * Unraid 7 keeps them in its own list (snapshotdb/<VM>/snapshots.db), not in
 * libvirt: external qcow2 overlays (vdiskN.S<time>qcow2) or ZFS. Both sources
 * are read; Unraid's list wins.
 */
function snapshotReadVms(): array
{
    $virsh = bin('virsh');
    $domains = [];
    $libvirt = $virsh && file_exists('/var/run/libvirt/libvirt-sock');
    if ($libvirt) {
        [$exit, $out] = run([$virsh, 'list', '--all', '--name'], 20);
        $libvirt = $exit === 0;
        if ($libvirt) {
            $domains = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
        }
    }
    $base = ['fs' => 'vm', 'pool' => 'VMs', 'used' => null, 'refer' => null, 'written' => null,
             'holds' => [], 'clones' => [], 'path' => null, 'docker' => false];

    $snaps = [];
    foreach (glob(VM_SNAPSHOT_DB . '/*/snapshots.db') ?: [] as $db) {
        $vm = basename(dirname($db));
        $entries = json_decode((string) @file_get_contents($db), true);
        if (!is_array($entries)) {
            continue;
        }
        $inUse = vmDiskFiles($vm);
        foreach ($entries as $key => $e) {
            if (!is_array($e)) {
                continue;
            }
            $name = (string) ($e['name'] ?? $key);
            $files = [];
            foreach ((array) ($e['disks'] ?? []) as $disk) {
                $file = $disk['source']['@attributes']['file'] ?? null;
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
            $overlay = null;
            foreach ($files as $file) {
                $st = @stat($file);
                if ($st) {
                    $overlay = ($overlay ?? 0) + $st['blocks'] * 512;   // really allocated, not nominal
                }
            }
            $snaps["vm:$vm/$name"] = [
                'id'          => "vm:$vm/$name",
                'vol'         => "vm:$vm",
                'ds'          => $vm,
                'name'        => $name,
                't'           => (int) ($e['creationtime'] ?? 0),
                'state'       => is_string($e['state'] ?? null) ? $e['state'] : null,
                'description' => is_string($e['desc'] ?? null) && trim($e['desc']) !== '' ? trim($e['desc']) : null,
                'method'      => is_string($e['method'] ?? null) ? $e['method'] : null,
                'parent'      => is_string($e['parent'] ?? null) && $e['parent'] !== '' && $e['parent'] !== 'Base' ? $e['parent'] : null,
                'files'       => $files,
                'overlay'     => $overlay,
                'active'      => (bool) array_intersect($files, $inUse),     // the VM writes into it right now
                'orphaned'    => $libvirt && !in_array($vm, $domains, true),  // the VM is gone
            ] + $base;
        }
    }

    if ($libvirt && $domains) {
        $commands = [];
        foreach ($domains as $i => $vm) {
            $commands[$i] = [$virsh, 'snapshot-list', '--domain', $vm];
        }
        $lists = runAll($commands, 30);
        foreach ($domains as $i => $vm) {
            if (($lists[$i][0] ?? 1) !== 0) {
                continue;
            }
            foreach (explode("\n", $lists[$i][1]) as $line) {
                if (!preg_match('/^\s*(\S.*?)\s+(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?: [+-]\d{4})?)\s+(\S+)\s*$/', $line, $m)) {
                    continue;
                }
                $snaps["vm:$vm/$m[1]"] ??= [
                    'id'    => "vm:$vm/$m[1]",
                    'vol'   => "vm:$vm",
                    'ds'    => $vm,
                    'name'  => $m[1],
                    't'     => (int) strtotime($m[2]),
                    'state' => $m[3],
                ] + $base;
            }
        }
    }

    return ['available' => $libvirt || $snaps, 'domains' => $domains, 'snapshots' => array_values($snaps)];
}

/** Files a VM currently uses as disks (from its XML) */
function vmDiskFiles(string $vm): array
{
    $xml = (string) @file_get_contents(VM_XML_DIR . '/' . basename($vm) . '.xml');
    preg_match_all("#<source file=['\"]([^'\"]+)['\"]#", $xml, $m);
    return $m[1];
}

// ===================================================================== mounts

/**
 * Attaches to every snapshot where it is mounted:
 *   fixed    mount -t zfs … / a btrfs bind (blocks deleting)
 *   overlay  an overlay is built on it (blocks too)
 *   auto     .zfs/snapshot automount, e.g. from a file browser (zfs handles it)
 */
function snapshotAttachMounts(array $snaps, array $table, array $backupRoots): array
{
    $disks = btrfsDisks($table);
    $bySnap = [];
    $mountToSnap = [];
    foreach ($table as $m) {
        $id = snapshotOfMount($m, $disks);
        if ($id === null) {
            continue;
        }
        $kind = str_contains($m['mount'], '/.zfs/snapshot/') ? 'auto' : 'fixed';
        $bySnap[$id][] = ['path' => $m['mount'], 'kind' => $kind, 'backup' => inBackupRoots($m['mount'], $backupRoots)];
        $mountToSnap[$m['mount']] = $id;
    }
    foreach ($table as $m) {
        if ($m['fs'] !== 'overlay') {
            continue;
        }
        $ids = [];
        foreach (overlayLayers($m['options']) as $layer) {
            $best = null;
            $len = -1;
            foreach ($mountToSnap as $mount => $id) {
                if (under($layer, $mount) && strlen($mount) > $len) {
                    [$best, $len] = [$id, strlen($mount)];
                }
            }
            if ($best !== null) {
                $ids[$best] = true;
            }
        }
        foreach (array_keys($ids) as $id) {
            $bySnap[$id][] = ['path' => $m['mount'], 'kind' => 'overlay', 'backup' => inBackupRoots($m['mount'], $backupRoots)];
        }
    }
    foreach ($snaps as &$s) {
        $s['mounts'] = $bySnap[$s['id']] ?? [];
    }
    unset($s);
    return $snaps;
}

/** @return list<string> mount points that have to go before deleting */
function snapshotFixedMounts(array $s): array
{
    $paths = [];
    foreach ($s['mounts'] ?? [] as $m) {
        if ($m['kind'] !== 'auto') {
            $paths[] = $m['path'];
        }
    }
    return $paths;
}

/**
 * Everything that has to go to free the snapshot: its mounts, whatever is
 * mounted below them, overlays built on top. Order: last mounted first.
 */
function snapshotUnmountPlan(string $id): array
{
    $table = mountTable();
    $disks = btrfsDisks($table);
    $out = [];
    foreach ($table as $i => $m) {
        if (snapshotOfMount($m, $disks) === $id) {
            $out[$i] = true;
        }
    }
    do {
        $grew = false;
        $paths = array_map(fn ($i) => $table[$i]['mount'], array_keys($out));
        foreach ($table as $i => $m) {
            if (isset($out[$i])) {
                continue;
            }
            $depends = false;
            foreach ($paths as $p) {
                if ($m['mount'] !== $p && under($m['mount'], $p)) {
                    $depends = true;
                    break;
                }
            }
            if (!$depends && $m['fs'] === 'overlay') {
                foreach (overlayLayers($m['options']) as $layer) {
                    foreach ($paths as $p) {
                        if (under($layer, $p)) {
                            $depends = true;
                            break 2;
                        }
                    }
                }
            }
            if ($depends) {
                $out[$i] = $grew = true;
            }
        }
    } while ($grew);

    $plan = [];
    foreach (array_reverse(array_keys($out)) as $i) {
        $plan[] = $table[$i];
    }
    return $plan;
}

/** @return list<string> unmounted paths */
function snapshotUnmount(array $s): array
{
    $plan = snapshotUnmountPlan($s['id']);
    if (!$plan) {
        return [];
    }
    $backup = backupScriptState();
    $roots = backupScriptRoots($backup);
    if ($backup['running']) {
        foreach ($plan as $m) {
            if (inBackupRoots($m['mount'], $roots)) {
                throw new Problem('backup_uses', ['path' => $m['mount']]);
            }
        }
    }
    $umount = bin('umount');
    $done = [];
    foreach ($plan as $m) {
        [$exit, , $err] = run([$umount, $m['mount']], 60);
        if ($exit !== 0) {
            usleep(1500000);
            [$exit, , $err] = run([$umount, $m['mount']], 60);
        }
        if ($exit !== 0) {
            logLine("Ms. Snapshotini: unmount failed: {$m['mount']} — " . trim($err));
            throw new Problem('unmount_failed', ['path' => $m['mount'], 'detail' => trim($err), 'done' => implode(', ', $done)]);
        }
        logLine("Ms. Snapshotini: unmounted {$m['mount']} ({$m['fs']} {$m['source']})");
        $done[] = $m['mount'];
        // tidy up empty mount points in the backup script's area, like it does itself
        if (inBackupRoots($m['mount'], $roots) && is_dir($m['mount']) && !(new FilesystemIterator($m['mount']))->valid()) {
            @rmdir($m['mount']);
        }
    }
    return $done;
}

function snapshotUnmountRequest(string $id, bool $wake = false): array
{
    $s = snapshotIndex(snapshotScan(false, $wake))[$id] ?? null;
    if (!$s) {
        throw new Problem('snapshot_gone', ['name' => snapshotShortId($id)]);
    }
    snapshotRefuseAsleep($s);
    $done = snapshotUnmount($s);
    if (!$done) {
        throw new Problem('not_mounted');
    }
    return ['ok' => true, 'unmounted' => $done, 'state' => snapshotScan(false, $wake)];
}

// ===================================================================== actions

/**
 * A partner office's copies among her ZFS datasets and snapshots (under <pool>/UnraidSecretaryOffice-partners): each
 * gets `partner` = {id, name (the pair's, null: none), gone (no pair of that id any more — a leftover), place (the
 * partners' place itself)}. $pairs: the pairs file (tests: `$GLOBALS['snapshotPartnerPairs']`).
 */
function snapshotPartnerMark(array $zfs, ?string $pairs = null): array
{
    $file = $pairs ?? ($GLOBALS['snapshotPartnerPairs'] ?? null);
    $known = null;
    $mark = function (string $name) use (&$known, $file): ?array {
        $d = partnerLookDataset($name);
        if ($d === null) {
            return null;
        }
        $known ??= partnerLookPairs($file);
        if ($d['id'] === null && !$d['trash']) {
            return ['id' => null, 'name' => null, 'gone' => $known === [], 'place' => true];
        }
        $pair = !$d['trash'] && $d['id'] !== null ? ($known[$d['id']] ?? null) : null;
        return ['id' => $d['id'], 'name' => $pair['name'] ?? null, 'gone' => $pair === null, 'place' => false];
    };
    foreach (['volumes' => 'name', 'snapshots' => 'ds'] as $list => $field) {
        foreach ((array) ($zfs[$list] ?? []) as $i => $x) {
            if (is_array($x) && is_string($x[$field] ?? null) && ($p = $mark($x[$field])) !== null) {
                $zfs[$list][$i]['partner'] = $p;
            }
        }
    }
    return $zfs;
}

/** A partner's copy she keeps her hands off (its pair is still there) */
function snapshotPartnerLocked(array $x): bool
{
    return is_array($x['partner'] ?? null) && empty($x['partner']['gone']);
}

/** Refuses an action on a partner's copy: the door's retention keeps those (a leftover of an ended pair is no longer locked) */
function snapshotRefusePartner(array $s): void
{
    if (snapshotPartnerLocked($s)) {
        throw new Problem('partner_copy', ['name' => snapshotShortId((string) ($s['id'] ?? '')),
            'partner' => (string) ($s['partner']['name'] ?? $s['partner']['id'] ?? PARTNER_PARENT)]);
    }
}

function snapshotShortId(string $id): string
{
    return (string) preg_replace('/^[a-z]+:/', '', $id);
}

function snapshotCheckName(string $name): void
{
    if (!preg_match(SNAPSHOT_NAME, $name)) {
        throw new Problem('invalid_name');
    }
}

/**
 * A snapshot she only knows as last seen — its pool sleeps (the scan before the action was asked without
 * `wake`): nothing is done to it. Deleting, renaming, holding would wake the pool; the page asks for that
 * explicitly and sends `wake`, which lists the pool fresh first (then nothing is marked asleep any more).
 */
function snapshotRefuseAsleep(array $s): void
{
    if (!empty($s['asleep'])) {
        throw new Problem('pool_asleep', ['name' => snapshotShortId((string) ($s['id'] ?? '')), 'pool' => (string) ($s['pool'] ?? '')]);
    }
}

/** btrfs mounts an id belongs to (to read them fresh first) */
function snapshotBtrfsMountsOf(array $ids): array
{
    $mounts = [];
    foreach ($ids as $id) {
        if (str_starts_with($id, 'btrfs:')) {
            foreach (btrfsDevices() as $mount => $_) {
                if (str_starts_with(substr($id, 6), "$mount/")) {
                    $mounts[$mount] = true;
                }
            }
        }
    }
    return array_keys($mounts);
}

/** What would deleting free up? (zfs destroy -n, changes nothing; a snapshot on a sleeping pool isn't asked — `asleep` counts them) */
function snapshotEstimate(array $ids): array
{
    $zfs = $GLOBALS['snapshotHost']['zfs'] ?? bin('zfs');
    $index = snapshotIndex($GLOBALS['snapshot'] ?? []);
    $perDataset = [];
    $unknown = 0;
    $asleep = 0;
    foreach ($ids as $id) {
        $s = $index[$id] ?? null;
        if (!$s || $s['docker'] || $s['holds'] || snapshotPartnerLocked($s)) {
            continue;                               // a partner's copy isn't hers to delete
        }
        if (!empty($s['asleep'])) {
            $asleep++;                              // its pool sleeps: not even a dry run touches it
        } elseif ($s['fs'] === 'zfs') {
            $perDataset[$s['ds']][] = $s['name'];
        } else {
            $unknown++;
        }
    }
    $commands = [];
    foreach ($perDataset as $ds => $names) {
        $commands[$ds] = [$zfs, 'destroy', '-nvp', "$ds@" . implode(',', $names)];
    }
    $bytes = 0;
    $failed = 0;
    foreach ($commands ? runAll($commands, 60) : [] as [$exit, $out]) {
        if ($exit === 0 && preg_match('/^reclaim\t(\d+)/m', $out, $m)) {
            $bytes += (int) $m[1];
        } else {
            $failed++;
        }
    }
    return ['bytes' => $bytes, 'unknown' => $unknown, 'failed' => $failed, 'asleep' => $asleep];
}

function snapshotCreate(array $r): array
{
    $name = textField($r, 'name');
    snapshotCheckName($name);
    $targets = idList($r, 'targets');
    $recursive = !empty($r['recursive']);
    $hold = !empty($r['hold']);
    $wake = !empty($r['wake']);                   // a target on a sleeping pool: listed fresh first (the page and the plans say so)

    $state = snapshotScan(false, $wake);
    $volumes = [];
    foreach ($state['zfs']['volumes'] as $v) {
        $volumes[$v['id']] = $v;
    }
    $devices = [];
    foreach ($state['btrfs']['devices'] as $d) {
        $devices["btrfs:{$d['mount']}"] = $d;
    }

    $zfsPerPool = [];
    $btrfsTargets = [];
    foreach ($targets as $id) {
        if (isset($volumes[$id])) {
            $v = $volumes[$id];
            if (snapshotPartnerLocked($v)) {
                // a snapshot of her own on a partner's copy would stop the next receive (zfs recv refuses a target changed since)
                throw new Problem('partner_dataset', ['target' => $v['name'], 'partner' => (string) ($v['partner']['name'] ?? $v['partner']['id'] ?? PARTNER_PARENT)]);
            }
            $zfsPerPool[$v['pool']][$v['name']] = true;
            if ($recursive) {
                foreach ($volumes as $w) {
                    if (str_starts_with($w['name'], $v['name'] . '/') && !snapshotPartnerLocked($w)) {
                        $zfsPerPool[$w['pool']][$w['name']] = true;   // Docker's layers aren't in $volumes at all; a partner's copies never
                    }
                }
            }
        } elseif (isset($devices[$id])) {
            $btrfsTargets[] = $devices[$id]['mount'];
        } else {
            throw new Problem('unknown_target', ['target' => $id]);
        }
    }

    $zfs = bin('zfs');
    $created = [];
    $failures = [];
    foreach ($zfsPerPool as $pool => $datasets) {
        $args = array_map(fn ($ds) => "$ds@$name", array_keys($datasets));
        [$exit, , $err] = run(array_merge([$zfs, 'snapshot'], $args), 120);   // atomic per pool
        if ($exit !== 0) {
            $failures[] = ['key' => 'create_failed', 'params' => ['target' => $pool, 'detail' => trim($err)]];
            logLine("Ms. Snapshotini: create on $pool failed: " . trim($err));
            continue;
        }
        logLine('Ms. Snapshotini: created ' . implode(', ', $args));
        foreach ($args as $x) {
            $created[] = "zfs:$x";
        }
        if ($hold) {
            [$exit, , $err] = run(array_merge([$zfs, 'hold', SNAPSHOT_HOLD_TAG], $args), 60);
            if ($exit !== 0) {
                $failures[] = ['key' => 'hold_failed', 'params' => ['target' => $pool, 'detail' => trim($err)]];
            }
        }
    }
    foreach ($btrfsTargets as $mount) {
        $dir = "$mount/" . SNAPSHOT_BTRFS_DIR;
        $target = "$dir/$name";
        if (!is_dir($dir) && !@mkdir($dir, 0755)) {
            $failures[] = ['key' => 'mkdir_failed', 'params' => ['path' => $dir]];
            continue;
        }
        if (file_exists($target)) {
            $failures[] = ['key' => 'name_exists', 'params' => ['name' => $name, 'target' => $mount]];
            continue;
        }
        [$exit, , $err] = run([bin('btrfs'), 'subvolume', 'snapshot', '-r', $mount, $target], 120);
        if ($exit !== 0) {
            $failures[] = ['key' => 'create_failed', 'params' => ['target' => $mount, 'detail' => trim($err)]];
            logLine("Ms. Snapshotini: btrfs snapshot $target failed: " . trim($err));
            continue;
        }
        logLine("Ms. Snapshotini: created $target (btrfs)");
        $created[] = "btrfs:$target";
    }

    $state = snapshotScan(false, $wake, $btrfsTargets);
    return ['ok' => true, 'created' => $created, 'failures' => $failures, 'state' => $state];
}

/** $wake: the sleeping pools are listed fresh first (asked for on the page) — without it a snapshot on one is refused (`pool_asleep`) */
function snapshotDelete(array $ids, bool $unmount = false, bool $wake = false): array
{
    // read btrfs targets fresh, so only what really exists gets deleted
    $mounts = snapshotBtrfsMountsOf($ids);
    $index = snapshotIndex(snapshotScan(false, $wake, $mounts));

    $failures = [];
    $perDataset = [];
    $btrfsPaths = [];
    foreach ($ids as $id) {
        $s = $index[$id] ?? null;
        $short = snapshotShortId($id);
        if (!$s) {
            $failures[] = ['key' => 'snapshot_gone', 'params' => ['name' => $short]];
        } elseif (!empty($s['asleep'])) {
            $failures[] = ['key' => 'pool_asleep', 'params' => ['name' => $short, 'pool' => (string) $s['pool']]];
        } elseif ($s['docker']) {
            $failures[] = ['key' => 'docker_layer', 'params' => ['name' => $short]];
        } elseif ($s['fs'] === 'vm') {
            $failures[] = ['key' => 'vm_managed_by_unraid', 'params' => ['name' => $short]];
        } elseif (snapshotPartnerLocked($s)) {
            $failures[] = ['key' => 'partner_copy', 'params' => ['name' => $short, 'partner' => (string) ($s['partner']['name'] ?? $s['partner']['id'] ?? PARTNER_PARENT)]];
        } elseif ($s['holds']) {
            $failures[] = ['key' => 'held', 'params' => ['name' => $short, 'holds' => implode(', ', $s['holds'])]];
        } elseif ($s['clones']) {
            $failures[] = ['key' => 'has_clones', 'params' => ['name' => $short, 'clones' => implode(', ', $s['clones'])]];
        } else {
            // zfs destroy handles .zfs/snapshot automounts itself, fixed mounts it doesn't
            $fixed = snapshotFixedMounts($s);
            if ($fixed && !$unmount) {
                $failures[] = ['key' => 'mounted', 'params' => ['name' => $short, 'paths' => implode(', ', $fixed)]];
                continue;
            }
            if ($fixed) {
                try {
                    snapshotUnmount($s);
                } catch (Problem $p) {
                    $failures[] = ['key' => $p->key, 'params' => $p->params + ['name' => $short]];
                    continue;
                }
            }
            if ($s['fs'] === 'zfs') {
                $perDataset[$s['ds']][] = $s['name'];
            } elseif ($s['fs'] === 'btrfs') {
                $btrfsPaths[] = $s['path'];
            }
        }
    }

    $zfs = bin('zfs');
    foreach ($perDataset as $ds => $names) {
        foreach (array_chunk($names, 100) as $batch) {
            $arg = "$ds@" . implode(',', $batch);
            [$exit, , $err] = run([$zfs, 'destroy', $arg], 600);
            if ($exit !== 0) {
                $failures[] = ['key' => 'command_failed', 'params' => ['detail' => trim($err)]];
                logLine("Ms. Snapshotini: delete failed: $arg — " . trim($err));
            } else {
                snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => $ds, 'names' => array_values($batch)]);
                logLine("Deleted: $arg");
            }
        }
    }
    foreach ($btrfsPaths as $path) {
        [$exit, , $err] = run([bin('btrfs'), 'subvolume', 'delete', $path], 300);
        if ($exit !== 0) {
            $failures[] = ['key' => 'command_failed', 'params' => ['detail' => "$path: " . trim($err)]];
            logLine("Ms. Snapshotini: delete failed: $path — " . trim($err));
        } else {
            snapshotRecord(['do' => 'deleted', 'fs' => 'btrfs', 'path' => $path]);
            logLine("Deleted: $path (btrfs)");
        }
    }

    $state = snapshotScan(false, $wake, $mounts);
    $still = snapshotIndex($state);
    $deleted = [];
    foreach ($ids as $id) {
        if (isset($index[$id]) && empty($index[$id]['asleep']) && !isset($still[$id])) {
            $deleted[] = $id;
        }
    }
    return ['ok' => true, 'deleted' => $deleted, 'failures' => $failures, 'state' => $state];
}

function snapshotRename(string $id, string $new, bool $wake = false): array
{
    snapshotCheckName($new);
    $mounts = snapshotBtrfsMountsOf([$id]);
    $s = snapshotIndex(snapshotScan(false, $wake, $mounts))[$id] ?? null;
    if (!$s) {
        throw new Problem('snapshot_gone', ['name' => snapshotShortId($id)]);
    }
    snapshotRefuseAsleep($s);
    snapshotRefusePartner($s);
    if ($s['docker'] || $s['fs'] === 'vm') {
        throw new Problem('cannot_rename');
    }
    if ($s['name'] === $new) {
        throw new Problem('same_name');
    }
    if ($fixed = snapshotFixedMounts($s)) {
        throw new Problem('rename_mounted', ['paths' => implode(', ', $fixed)]);
    }

    if ($s['fs'] === 'zfs') {
        [$exit, , $err] = run([bin('zfs'), 'rename', "{$s['ds']}@{$s['name']}", "{$s['ds']}@$new"], 60);
        if ($exit !== 0) {
            throw new Problem('command_failed', ['detail' => trim($err)]);
        }
        $newId = "zfs:{$s['ds']}@$new";
    } else {
        $target = dirname($s['path']) . "/$new";
        if (file_exists($target)) {
            throw new Problem('name_exists', ['name' => $new, 'target' => $s['ds']]);
        }
        if (!@rename($s['path'], $target)) {
            throw new Problem('rename_failed');
        }
        $newId = "btrfs:$target";
    }
    snapshotRecord(['do' => 'renamed', 'where' => $s['ds'], 'from' => $s['name'], 'to' => $new]);
    logLine("Renamed: {$s['ds']} {$s['name']} → $new");
    return ['ok' => true, 'id' => $newId, 'state' => snapshotScan(false, $wake, $mounts)];
}

function snapshotHold(string $id, bool $on, bool $wake = false): array
{
    $s = snapshotIndex(snapshotScan(false, $wake))[$id] ?? null;
    if (!$s || $s['fs'] !== 'zfs' || $s['docker']) {
        throw new Problem('hold_zfs_only');
    }
    snapshotRefuseAsleep($s);
    snapshotRefusePartner($s);
    $full = "{$s['ds']}@{$s['name']}";
    $ours = array_values(array_intersect($s['holds'], SNAPSHOT_HOLD_TAGS));
    if ($on && $ours) {
        throw new Problem('already_held');
    }
    if (!$on && !$ours) {
        throw new Problem('foreign_hold', ['holds' => implode(', ', $s['holds'])]);
    }
    $commands = $on
        ? [[bin('zfs'), 'hold', SNAPSHOT_HOLD_TAG, $full]]
        : array_map(fn ($tag) => [bin('zfs'), 'release', $tag, $full], $ours);
    foreach ($commands as $command) {
        [$exit, , $err] = run($command, 60);
        if ($exit !== 0) {
            throw new Problem('command_failed', ['detail' => trim($err)]);
        }
    }
    if (!$on) {
        snapshotRecord(['do' => 'released', 'ds' => $s['ds'], 'name' => $s['name']]);
    }
    logLine(($on ? 'Held: ' : 'Released: ') . $full);
    return ['ok' => true, 'state' => snapshotScan(false, $wake)];
}

// ===================================================================== her record of what she removed

/*
 * The night watchman takes what she deleted, released and renamed for no news. Her lines in the
 * office's log (Deleted:/Released:/Renamed: — they stay as they are, their shape is his interface too)
 * lie in the data folder, which the web server's user may write: a forged line there would hide a
 * deletion. So the same facts also go into a record only root can write — data/snapshot/deletes.jsonl
 * (a folder of root's own, 0700; the file 0600, one JSON object per line), appended under a lock (her
 * schedules run as a job of their own), never through a link; beyond SNAPSHOT_RECORD_MAX the file
 * becomes deletes.jsonl.1 (one older file kept). A record someone else could have written (Unraid's
 * «New Permissions» on appdata, say) is no record: it is set aside (deletes.jsonl.untrusted-<time>)
 * and a new one begins — the watchman never reads that one.
 *   {"t": <time>, "do": "deleted", "fs": "zfs", "ds": "<dataset>", "names": ["<snapshot>", …]}
 *   {"t": <time>, "do": "deleted", "fs": "btrfs", "path": "<snapshot folder>"}
 *   {"t": <time>, "do": "released", "ds": "<dataset>", "name": "<snapshot>"}
 *   {"t": <time>, "do": "renamed", "where": "<dataset or disk>", "from": "<old>", "to": "<new>"}
 */
const SNAPSHOT_RECORD_MAX = 1024 * 1024;

/** Her record (tests point it elsewhere) */
function snapshotRecordFile(): string
{
    return $GLOBALS['snapshotRecordFile'] ?? DATA_DIR . '/snapshot/deletes.jsonl';
}

/**
 * The record's folder of root's own and the record in it (created empty when missing, so the watchman
 * knows from now on that only the record counts). False when it can't be — then nothing is written.
 */
function snapshotRecordReady(?string $file = null): bool
{
    $file ??= snapshotRecordFile();
    $dir = dirname($file);
    clearstatcache();
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        return false;                       // never through a link
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700)) {
        return false;
    }
    $st = @lstat($dir);
    if ($st && ($st['uid'] !== 0 || $st['gid'] !== 0 || ($st['mode'] & 0077))) {
        @lchown($dir, 0);
        @lchgrp($dir, 0);
        if (!is_link($dir)) {
            @chmod($dir, 0700);
        }
        clearstatcache();
        $st = @lstat($dir);
    }
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || $st['uid'] !== 0 || ($st['mode'] & 0077)) {
        return false;
    }
    $f = @lstat($file);
    if ($f && (($f['mode'] & 0170000) !== 0100000 || $f['uid'] !== 0 || ($f['mode'] & 0077) || $f['nlink'] !== 1)) {
        // others could have written it: set aside, a new one begins
        $aside = "$file.untrusted-" . date('Ymd-His');
        for ($i = 2; file_exists($aside) || is_link($aside); $i++) {
            $aside = "$file.untrusted-" . date('Ymd-His') . "-$i";
        }
        if (!@rename($file, $aside)) {
            return false;
        }
        logLine("Ms. Snapshotini: her record $file could be written by others — set aside as " . basename($aside) . ', a new one begins');
        $f = false;
    }
    if (!$f) {
        $old = umask(0077);
        $h = @fopen($file, 'x');            // new, root's, 0600 from the start
        umask($old);
        if (!$h) {
            return false;
        }
        fclose($h);
    }
    return true;
}

/** One line in her record (see above); a record that can't be written is said in the log, the deletion stands */
function snapshotRecord(array $entry): void
{
    $file = snapshotRecordFile();
    if (!snapshotRecordReady($file)) {
        logLine("Ms. Snapshotini: could not write her record $file");
        return;
    }
    $line = jsonEncode(['t' => time()] + $entry) . "\n";
    $h = @fopen($file, 'a');                // in a folder only root can write: no link can be in the way
    if (!$h) {
        logLine("Ms. Snapshotini: could not write her record $file");
        return;
    }
    flock($h, LOCK_EX);
    $st = fstat($h);
    if ($st && $st['size'] + strlen($line) > SNAPSHOT_RECORD_MAX && @rename($file, "$file.1")) {
        flock($h, LOCK_UN);
        fclose($h);
        $old = umask(0077);
        $h = @fopen($file, 'a');            // a new one; whoever still holds the old one writes on into .1, which the watchman reads too
        umask($old);
        if (!$h) {
            logLine("Ms. Snapshotini: could not write her record $file");
            return;
        }
        flock($h, LOCK_EX);
    }
    fwrite($h, $line);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}
