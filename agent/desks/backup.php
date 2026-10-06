<?php
declare(strict_types=1);

/*
 * Mr. Backup — runs the backup engine in backup/ (unraid-backup).
 *
 * The engine works on its own: the plugin's cron file (in the stack a User
 * Scripts entry) starts it at night, it keeps its
 * settings in data/unraid-backup/settings.ini and writes its state to
 * data/unraid-backup/state/ (status.json, last-run.json, history.jsonl,
 * drift.json — see "Status fuer andere Programme" in backup/README.md).
 * Mr. Backup reads that, shows it, and starts, checks or stops runs. Since
 * engine 2.18 the backup place holds a package per app and VM (apps/, vms/,
 * server/, flash/ with a manifest.json each) — read by backupPackages(). Since 2.19 apps and VMs
 * at "local + Kopia" are Kopia sources of their own ([app|vm "<name>"] kopia = yes, backupKopiaItems()).
 *
 * Runs are handed to the host's atd ("at now"). A process started by the
 * agent itself would be stopped with it (the stack's container cgroup, the
 * plugin's process group when the array stops or the plugin is updated) —
 * a 10-hour backup must not depend on the office.
 *
 * Older script versions without state/status.json are shown from their log
 * files (read only); starting needs interface 1 or newer.
 *
 * Since engine 2.20 a run that finds the lock busy is skipped, not lost: state/skipped.json (the last
 * attempt) and a history.jsonl line with "result": "skipped" — kept apart from the runs (history,
 * estimates, the last run and its downtime never see them) and shown as "skips"; who holds the lock
 * comes from state/lock-holder.json (backupLockHolder()).
 */

const BACKUP_INTERFACE   = 1;
const BACKUP_MODES       = ['backup' => [], 'nokopia' => ['--no-kopia'], 'dryrun' => ['--dry-run'], 'check' => ['--check']];
const BACKUP_LOG_NAME    = '/^(?:(run|check|dryrun|setup)-(\d{8})-(\d{4})\.log|unmount\.log)$/';
const BACKUP_LOG_BYTES   = 512 * 1024;
const BACKUP_HISTORY     = 60;           // runs shown
const BACKUP_SCHEDULE    = '/boot/config/plugins/user.scripts/schedule.json';
const BACKUP_USER_SCRIPT = 'unraid-secretary-office_backup';   // was unraid-backup (userScriptsMigrate)

$GLOBALS['backup'] = null;
$GLOBALS['backupLogCache'] = [];         // legacy log file => [mtime, parsed]

desk('backup', [
    'fit'     => function (): array {
        $fs = houseSnapshotFilesystems();
        if (!$fs['zfs'] && !$fs['btrfs']) {
            return fit(false, 'no_cow');
        }
        foreach (houseContainers() as $c) {
            if (preg_match('/kopia/i', $c['image'] . ' ' . $c['name'])) {
                return fit(true, 'yes', ['kopia' => $c['name']]);
            }
        }
        return fit(true, 'no_kopia');
    },
    'start' => function (): void {
        $GLOBALS['backup'] = readJson(deskFile('backup'));
        backupScan();
    },
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => backupScan()],
        'start'   => fn (array $r) => backupStart(textField($r, 'mode')),
        'abort'   => fn (array $r) => backupAbort(),
        'unmount' => fn (array $r) => backupUnmount(),
        'log'     => fn (array $r) => backupLog(textField($r, 'log')),
        'setup_plan'  => fn (array $r) => backupSetupPlan(!empty($r['measure'])),
        'setup_get'   => fn (array $r) => backupSetupGet(),
        'setup_apply' => fn (array $r) => backupSetupApply($r['decisions'] ?? null),
        'setup_forget' => fn (array $r) => backupSetupForget(),
        'schedule'    => fn (array $r) => backupSetSchedule($r['cron'] ?? null),
    ],
    'checks' => fn () => backupChecks(),
]);

// ===================================================================== state

function backupScan(): array
{
    userScriptsMigrate();              // waits while a run uses the old entry; cheap otherwise
    backupUserScriptDescribe();
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    $state = ['time' => time(), 'found' => false, 'dir' => $dir, 'data' => $data, 'asleep' => false];

    if (backupHomeAsleep($data)) {
        // the script folder sits on a sleeping disk: keep what we knew
        $old = $GLOBALS['backup'] ?? [];
        $state = ['time' => time(), 'asleep' => true] + $old + $state;
        $GLOBALS['backup'] = $state;
        writeAtomic(deskFile('backup'), jsonEncode($state));
        return $state;
    }
    if (!is_file("$dir/backup.sh")) {
        $GLOBALS['backup'] = $state;
        writeAtomic(deskFile('backup'), jsonEncode($state));
        return $state;
    }

    $about = backupAbout($dir);
    $settings = backupReadSettings("$data/settings.ini");
    $setup = backupSetupStatus();
    // the lock is shared with setup.sh and Mr. Restori's restores: while they hold it, no backup is running
    // (an unknown holder - an engine before 2.20 writes no note - still counts as a run)
    $holder = backupLockHolder();
    $running = $holder !== null && !$setup['running'] && !in_array($holder['holder'], ['setup', 'restore'], true);
    $status = readJson("$data/state/status.json");
    if ($status && ($status['interface'] ?? 0) < BACKUP_INTERFACE) {
        $status = null;
    }
    // status.json says "running" but nobody holds the lock: killed hard (kill -9, reboot)
    if ($status && ($status['result'] ?? '') === 'running' && !$running) {
        $status['result'] = 'failed';
        $status['message'] = 'interrupted';
        $status['interrupted'] = true;
    }

    $logs = backupLogs();
    $history = backupHistory($logs, $running ? ($status['run'] ?? null) : null, $skips);

    $state += [
        'version'    => $about['version'] ?? null,
        'interface'  => $about['interface'] ?? 0,
        'compatible' => ($about['interface'] ?? 0) >= BACKUP_INTERFACE,
        'settings_found' => is_file("$data/settings.ini"),
        'running'    => $running,
        'status'     => $status,
        'step'       => $running && !$status ? lastLogStep("$data/logs/latest.log") : null,
        'since'      => $running ? (($holder['started'] ?? 0) ?: (@filemtime("$data/state/lock") ?: null)) : null,
        'holder'     => $holder,
        'paused'     => $running ? backupPaused($data) : null,
        'history'    => $history,
        'skips'      => $skips,                  // backup runs skipped because the lock was busy, newest first
        'skipped'    => backupSkipRow(readJson("$data/state/skipped.json")),   // the last attempt of any mode
        'estimates'  => backupEstimates($history),
        'drift'      => backupDrift(),
        'settings'   => backupSettingsSummary($settings),
        'shares'     => backupShares($settings, $history),
        'items'      => backupKopiaItems($settings, $history),
        'vms'        => backupVms($settings),
        'containers' => backupContainers($settings),
        'dumps'      => backupDumps(),           // run folders of engines before 2.18, until the first 2.18 run cleared them
        'packages'   => backupPackages($settings),
        'schedule'   => backupSchedule(),
        'logs'       => array_values(array_map(fn ($l) => ['name' => $l['name'], 'kind' => $l['kind'], 'time' => $l['time'], 'size' => $l['size']], $logs)),
        'mounted'    => backupMounted(backupSetting($settings, 'general', 'mount_root')),
        'setup'      => $setup,
    ];
    $state['found'] = true;
    $GLOBALS['backup'] = $state;
    writeAtomic(deskFile('backup'), jsonEncode($state));
    return $state;
}

/** Name, version and interface of the script ("backup.sh --about", since 2.5) */
function backupAbout(string $dir): array
{
    static $cache = [];
    $stamp = (int) @filemtime("$dir/lib/common.sh") . ':' . (int) @filemtime("$dir/backup.sh");
    if (($cache[$dir][0] ?? null) === $stamp) {
        return $cache[$dir][1];
    }
    $about = [];
    [$exit, $out] = run(['bash', "$dir/backup.sh", '--about'], 10);
    $j = $exit === 0 ? json_decode(trim($out), true) : null;
    if (is_array($j)) {
        $about = $j;
    } elseif (preg_match('/^UB_VERSION="([^"]+)"/m', (string) @file_get_contents("$dir/lib/common.sh"), $m)) {
        $about = ['version' => $m[1], 'interface' => 0];      // before 2.5
    }
    $cache[$dir] = [$stamp, $about];
    return $about;
}

/**
 * Is the script folder on a sleeping disk? Then reading it would wake the
 * disk up — every refresh. Only awake disks and pools are looked at.
 */
function backupHomeAsleep(string $dir): bool
{
    $asleep = sleepingDisks();
    if (!in_array(true, $asleep, true)) {
        return false;
    }
    if (preg_match('#^/mnt/([^/]+)/#', $dir, $m) && !in_array($m[1], ['user', 'user0'], true)) {
        return $asleep[$m[1]] ?? false;
    }
    if (!preg_match('#^/mnt/user0?/(.+)$#', $dir, $m)) {
        return false;
    }
    foreach (mountTable() as $mount) {
        if (preg_match('#^/mnt/([^/]+)$#', $mount['mount'], $x) && !in_array($x[1], ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true)
            && !($asleep[$x[1]] ?? false) && is_dir("/mnt/{$x[1]}/{$m[1]}")) {
            return false;
        }
    }
    return true;
}

function backupSettingsSummary(array $s): array
{
    $one = fn (string $section, string $key, ?string $default = null) => backupSetting($s, $section, $key, $default);
    $kopia = [];
    foreach (['latest', 'hourly', 'daily', 'weekly', 'monthly', 'annual'] as $k) {
        $kopia[$k] = $one('kopia', "keep_$k");
    }
    $names = fn (string $type) => array_values(array_map(fn ($k) => substr($k, strlen($type) + 1),
        array_filter(array_keys($s), fn ($k) => str_starts_with($k, "$type|"))));
    return [
        'server'        => $one('general', 'server'),
        'mount_root'    => $one('general', 'mount_root'),
        'view_root'     => $one('general', 'view_root'),
        'snap_prefix'   => backupSnapPrefixes($one('general', 'snap_prefix'))[0],     // what new snapshots are called
        'snap_prefixes' => backupSnapPrefixes($one('general', 'snap_prefix')),        // the engine's, older names too
        'btrfs_dir'     => $one('general', 'btrfs_snap_dir', '.btrfs-snap'),
        'dumps_share'   => $one('general', 'dumps_share'),
        'dumps_dir'     => backupDumpsPath((string) $one('general', 'dumps_share', '')),
        'keep_mounts'   => $one('general', 'keep_mounts', 'no') === 'yes',
        'zfs_retention' => $one('zfs', 'retention'),
        'btrfs_days'    => $one('btrfs', 'keep_days'),
        'docker_stop'   => $one('docker', 'stop', 'all'),
        'no_stop'       => array_values(array_unique(array_merge($s['docker']['no_stop'] ?? [], $s['docker']['skip'] ?? []))),   // skip: not backed up, keeps running too
        'flash'         => $one('flash', 'mode', 'off'),
        'libvirt'       => $one('libvirt', 'mode', 'tar'),
        'libvirt_img'   => readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? '/mnt/user/system/libvirt/libvirt.img',
        'kopia_enabled' => in_array(strtolower((string) $one('kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true),
        'kopia_container' => $one('kopia', 'container'),
        'kopia_keep'    => $kopia,
        'kopia_ignore'  => array_values($s['kopia']['ignore'] ?? []),      // inherited by every share
        'kopia_compression' => $one('kopia', 'compression', 'inherit'),
        'dumps'         => array_map(fn ($n) => ['container' => $n, 'type' => backupSetting($s, "dump|$n", 'type')], $names('dump')),
        'nextcloud'     => array_map(fn ($n) => ['container' => $n, 'preexisting' => backupSetting($s, "nextcloud|$n", 'preexisting_maintenance', 'abort')], $names('nextcloud')),
    ];
}

/** Shares as settings.ini knows them, with the last Kopia result of each */
function backupShares(array $s, array $history): array
{
    $last = [];
    foreach ($history as $run) {                       // newest first
        foreach ($run['kopia'] ?? [] as $k) {
            $last[$k['name']] ??= ['time' => $k['finished'] ?: $run['started'], 'ok' => $k['ok'], 'seconds' => $k['seconds']];
            if ($k['ok']) {
                $last[$k['name']]['good'] ??= $k['finished'] ?: $run['started'];
            }
        }
    }
    // file system of every pool and disk (/mnt/<name>): which local snapshots a share gets
    $baseFs = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x)) {
            $baseFs[$x[1]] = $m['fs'];
        }
    }
    $shares = [];
    foreach ($s as $key => $values) {
        if (!str_starts_with($key, 'share|')) {
            continue;
        }
        $name = substr($key, 6);
        $locations = array_values(array_filter(array_map('trim', explode(',', (string) backupSetting($s, $key, 'locations', '')))));
        $shares[] = [
            'name'       => $name,
            'mode'       => backupSetting($s, $key, 'mode', 'off'),
            'method'     => backupSetting($s, $key, 'method', 'auto'),
            'retention'  => backupSetting($s, $key, 'retention'),
            'kopia_retention' => backupSetting($s, $key, 'kopia_retention'),
            'ignores'    => $values['kopia_ignore'] ?? [],
            'excluded'   => $values['exclude_dataset'] ?? [],
            'locations'  => $locations,
            'fs'         => array_values(array_unique(array_map(fn ($l) => $baseFs[$l] ?? '', $locations))),
            'last'       => $last[$name] ?? null,
        ];
    }
    if (backupSetting($s, 'flash', 'mode') === 'snapshot') {
        $shares[] = ['name' => 'flash', 'mode' => 'kopia', 'method' => 'flash', 'retention' => null, 'kopia_retention' => null,
                     'ignores' => $s['flash']['kopia_ignore'] ?? [], 'excluded' => [], 'locations' => ['boot'], 'fs' => ['zfs'], 'last' => $last['flash'] ?? null, 'flash' => true];
    }
    usort($shares, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $shares;
}

/**
 * Apps and VMs with a Kopia source of their own (engine 2.19, lib/common.sh section 9): kind, name,
 * the folders it keeps, its own retention and rules, where Kopia finds it (<container path>/.apps|.vms/
 * <folder>, the container path from the policies the last check compared) and the last Kopia result
 * of "app:<name>" / "vm:<name>".
 */
function backupKopiaItems(array $s, array $history): array
{
    if (!in_array(strtolower((string) backupSetting($s, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true)) {
        return [];
    }
    $last = [];
    foreach ($history as $run) {                       // newest first
        foreach ($run['kopia'] ?? [] as $k) {
            if (preg_match('/^(app|vm):/', $k['name'])) {
                $last[$k['name']] ??= ['time' => $k['finished'] ?: $run['started'], 'ok' => $k['ok'], 'seconds' => $k['seconds']];
                if ($k['ok']) {
                    $last[$k['name']]['good'] ??= $k['finished'] ?: $run['started'];
                }
            }
        }
    }
    $paths = [];
    foreach (backupDrift()['policies'] ?? [] as $p) {
        if (in_array($p['kind'], ['app', 'vm'], true)) {
            $paths[$p['kind'] . ':' . $p['name']] = $p['path'];
        }
    }
    $items = [];
    foreach (['app', 'vm'] as $kind) {
        $names = array_map(fn ($k) => substr($k, strlen($kind) + 1), array_filter(array_keys($s), fn ($k) => str_starts_with($k, "$kind|")));
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (backupSetting($s, "$kind|$name", 'kopia', 'no') !== 'yes') {
                continue;
            }
            $items[] = [
                'kind'      => $kind,
                'name'      => $name,
                'folders'   => array_values($s["$kind|$name"]['folder'] ?? []),
                'retention' => backupSetting($s, "$kind|$name", 'kopia_retention'),
                'ignores'   => array_values($s["$kind|$name"]['kopia_ignore'] ?? []),
                'path'      => $paths["$kind:$name"] ?? null,
                'last'      => $last["$kind:$name"] ?? null,
            ];
        }
    }
    return $items;
}

/**
 * The containers for the overview: how many there are, how many run, which of the
 * running ones keep running for the snapshot (Kopia, docker|no_stop) — the engine
 * stops all other running ones (unless [docker] stop = none) — and the database dumps.
 */
function backupContainers(array $s): array
{
    $all = houseContainers();
    $running = array_keys(array_filter($all, fn ($c) => $c['running']));
    $keep = array_merge($s['docker']['no_stop'] ?? [], $s['docker']['skip'] ?? [], array_filter([backupSetting($s, 'kopia', 'container')]));
    $stopNone = backupSetting($s, 'docker', 'stop', 'all') === 'none';
    $kept = $stopNone ? $running : array_values(array_intersect($running, $keep));
    $kopia = (string) backupSetting($s, 'kopia', 'container', '');
    return ['total' => count($all), 'running' => count($running), 'kept' => count($kept),
            'stopped' => count($running) - count($kept), 'dumps' => count(array_filter(array_keys($s), fn ($k) => str_starts_with($k, 'dump|'))),
            'kopia' => $kopia === '' ? null : ['name' => $kopia, 'exists' => isset($all[$kopia]), 'running' => !empty($all[$kopia]['running'])]];
}

/**
 * The VMs and how the backup treats them: libvirt's list (live), [vm "<name>"] in
 * settings.ini, where their disks lie (from the last setup plan) and what the last
 * real run did with them (last-run.json, engine 2.16+). Empty while the VM service is off.
 */
function backupVms(array $s): array
{
    [$exit, $out] = run(['virsh', 'list', '--all', '--name'], 15);
    if ($exit !== 0) {
        return [];
    }
    $names = array_values(array_filter(array_map('trim', explode("\n", (string) $out)), fn ($n) => $n !== ''));
    $states = [];
    [$e2, $running] = run(['virsh', 'list', '--name'], 15);
    foreach (array_filter(array_map('trim', explode("\n", (string) $running))) as $n) {
        $states[$n] = 'running';
    }
    $plan = [];
    foreach (readJson(BACKUP_DATA_DIR . '/state/setup-plan.json')['vms'] ?? [] as $v) {
        if (is_array($v) && isset($v['name'])) {
            $plan[(string) $v['name']] = $v;
        }
    }
    $lastRun = readJson(BACKUP_DATA_DIR . '/state/last-run.json');
    $done = [];
    foreach ($lastRun['vms'] ?? [] as $v) {
        if (is_array($v) && isset($v['name'])) {
            $done[(string) $v['name']] = ['done' => (string) ($v['done'] ?? ''), 'seconds' => (int) ($v['seconds'] ?? 0),
                                          'snapshot' => !empty($v['snapshot']), 'time' => (int) ($lastRun['started'] ?? 0)];
        }
    }
    $vms = [];
    foreach ($names as $n) {
        $p = $plan[$n] ?? [];
        $share = '';
        foreach ($p['disks'] ?? [] as $d) {
            if (($d['share'] ?? '') !== '') {
                $share = (string) $d['share'];
                break;
            }
        }
        $vms[] = [
            'name'       => $n,
            'running'    => isset($states[$n]),
            'configured' => isset($s["vm|$n"]),
            'mode'       => backupSetting($s, "vm|$n", 'mode', 'snapshot'),
            'prepare'    => backupSetting($s, "vm|$n", 'prepare', 'none'),
            'retention'  => backupSetting($s, "vm|$n", 'retention'),
            'share'      => $share,
            'share_mode' => $share !== '' ? backupSetting($s, "share|$share", 'mode', 'off') : null,
            'snap'       => $p['snap'] ?? null,         // yes | block | live | missing | none - null: no plan yet
            'own'        => array_values($p['own'] ?? []),
            'disks'      => array_values(array_map(fn ($d) => ['target' => (string) ($d['target'] ?? ''), 'source' => (string) ($d['source'] ?? ''),
                                'fs' => (string) ($d['fs'] ?? ''), 'dataset' => (string) ($d['dataset'] ?? '')], $p['disks'] ?? [])),
            'agent'      => $p['agent'] ?? null,
            'hostdev'    => (int) ($p['hostdev'] ?? 0),
            'tpm'        => !empty($p['tpm']),
            'last'       => $done[$n] ?? null,
        ];
    }
    usort($vms, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $vms;
}

// ===================================================================== history

/** @return array<string, array{name:string, kind:string, time:int, size:int, path:string}> newest first */
function backupLogs(): array
{
    $data = BACKUP_DATA_DIR;
    $logs = [];
    foreach (@scandir("$data/logs") ?: [] as $name) {
        if (!preg_match(BACKUP_LOG_NAME, $name, $m)) {
            continue;
        }
        $path = "$data/logs/$name";
        $time = isset($m[2]) && $m[2] !== ''
            ? (int) (DateTime::createFromFormat('Ymd-Hi', "$m[2]-$m[3]")?->getTimestamp() ?: 0)
            : (int) @filemtime($path);
        $logs[$name] = ['name' => $name, 'kind' => $m[1] ?? 'unmount', 'time' => $time, 'size' => (int) @filesize($path), 'path' => $path];
    }
    uasort($logs, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $logs;
}

/**
 * Finished backup runs, newest first: from history.jsonl, and for runs of
 * older versions (or before 2.5) read from their logs. Runs skipped because
 * the lock was busy (engine 2.20) are no runs: they go to $skips (newest first).
 */
function backupHistory(array $logs, ?string $runningRun, ?array &$skips = null, ?string $file = null): array
{
    $file ??= BACKUP_DATA_DIR . '/state/history.jsonl';
    $runs = [];
    $skips = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $j = json_decode($line, true);
        if (is_array($j) && ($j['result'] ?? '') === 'skipped') {
            if ($skip = backupSkipRow($j)) {
                $skips[] = $skip;
            }
        } elseif (is_array($j) && !empty($j['run'])) {
            $runs[$j['run']] = backupRunFromStatus($j);
        }
    }
    usort($skips, fn ($a, $b) => $b['time'] <=> $a['time']);
    $skips = array_slice($skips, 0, 20);
    foreach ($logs as $log) {
        if ($log['kind'] !== 'run') {
            continue;
        }
        $run = substr($log['name'], 4, 13);
        if (isset($runs[$run]) || $run === $runningRun) {
            continue;
        }
        $cache = $GLOBALS['backupLogCache'][$log['path']] ?? null;
        $stamp = $log['size'] . ':' . @filemtime($log['path']);
        if (!$cache || $cache[0] !== $stamp) {
            $cache = [$stamp, backupRunFromLog($log['path'], $run, $log['time'])];
            $GLOBALS['backupLogCache'][$log['path']] = $cache;
        }
        $runs[$run] = $cache[1];
    }
    usort($runs, fn ($a, $b) => $b['started'] <=> $a['started']);
    return array_slice($runs, 0, BACKUP_HISTORY);
}

function backupRunFromStatus(array $j): array
{
    return [
        'run'        => (string) $j['run'],
        'started'    => (int) ($j['started'] ?? 0),
        'finished'   => (int) ($j['finished'] ?? 0),
        'result'     => (string) ($j['result'] ?? 'failed'),
        'message'    => (string) ($j['message'] ?? ''),
        'errors'     => (int) ($j['errors'] ?? 0),
        'warnings'   => (int) ($j['warnings'] ?? 0),
        'downtime'   => (int) ($j['downtime_s'] ?? 0),
        'dump_bytes' => (int) ($j['dump_bytes'] ?? 0),
        'packages'   => is_array($j['packages'] ?? null) ? ['apps' => (int) ($j['packages']['apps'] ?? 0), 'vms' => (int) ($j['packages']['vms'] ?? 0),
                            'errors' => (int) ($j['packages']['errors'] ?? 0), 'stale' => (int) ($j['packages']['stale'] ?? 0)] : null,
        'kopia'      => array_map(fn ($k) => ['name' => (string) $k['name'], 'ok' => (bool) $k['ok'], 'seconds' => (int) $k['seconds'], 'finished' => (int) ($k['finished'] ?? 0)],
                                  $j['kopia']['done'] ?? []),
        'kopia_first' => null,
        'log'        => (string) ($j['log'] ?? ''),
        'version'    => (string) ($j['version'] ?? ''),
        'source'     => 'status',
    ];
}

/**
 * A run of a script version without status files, from its log. The log
 * lines are only meant for people (German before 2.13, English since); this
 * is a fallback, nothing else depends on it.
 */
function backupRunFromLog(string $path, string $run, int $started): array
{
    $r = ['run' => $run, 'started' => $started, 'finished' => 0, 'result' => 'failed', 'message' => 'interrupted',
          'errors' => 0, 'warnings' => 0, 'downtime' => 0, 'dump_bytes' => 0, 'packages' => null, 'kopia' => [], 'kopia_first' => null,
          'log' => basename($path), 'version' => '', 'source' => 'log'];
    $h = @fopen($path, 'r');
    if (!$h) {
        return $r;
    }
    $current = null;
    $lastError = null;
    $last = $started;
    while (($line = fgets($h)) !== false) {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)  (.*)$/', rtrim($line), $m)) {
            continue;
        }
        $t = strtotime($m[1]) ?: $last;
        $last = $t;
        $text = $m[2];
        if (preg_match('/^=+ \S+ (\S+) - backup /', $text, $x)) {
            $r['version'] = $x[1];
        } elseif (preg_match('/^Kopia: (\S+)  \(/', $text, $x)) {
            $current = [$x[1], $t];
            $r['kopia_first'] ??= $t;
        } elseif ($current && preg_match('/^\s+ok \((\d+) s\)/', $text, $x)) {
            $r['kopia'][] = ['name' => $current[0], 'ok' => true, 'seconds' => (int) $x[1], 'finished' => $t];
            $current = null;
        } elseif ($current && (str_contains($text, "Kopia-Snapshot von '{$current[0]}' fehlgeschlagen") || str_contains($text, "Kopia snapshot of '{$current[0]}' failed"))) {
            $r['kopia'][] = ['name' => $current[0], 'ok' => false, 'seconds' => $t - $current[1], 'finished' => $t];
            $current = null;
        } elseif (preg_match('/(?:Unterbrechung|downtime) (\d+) s$/', $text, $x)) {
            $r['downtime'] = (int) $x[1];
        } elseif (preg_match('/^(?:FEHLER|ERROR): (.*)$/', $text, $x)) {
            $r['errors']++;
            $lastError = trim($x[1]);
        } elseif (preg_match('/^(?:WARNUNG|WARNING): /', $text)) {
            $r['warnings']++;
        } elseif (preg_match('/^Backup (erfolgreich|successful|mit .*beendet|finished with)/', $text, $x)) {
            $r['finished'] = $t;
            $r['message'] = '';
            $r['result'] = preg_match('/Fehler\(n\)|error\(s\)/', $text) ? 'errors' : (preg_match('/Warnung|warning/', $text) ? 'warnings' : 'ok');
        }
    }
    fclose($h);
    if ($r['result'] === 'failed') {
        $r['finished'] = $last;
        $r['message'] = $lastError ?? 'interrupted';     // die() logs its reason as the last error
    }
    return $r;
}

/**
 * Typical durations from the last good runs, for "ready at about …":
 * per Kopia source and for the part before Kopia starts. The newest runs
 * count: a first run uploads everything to Kopia and takes hours, the
 * incremental ones after it minutes — so the median of the last three, and
 * with fewer than three simply the newest.
 */
function backupEstimates(array $history): array
{
    $sources = [];
    $before = [];
    $total = [];
    foreach ($history as $run) {
        if ($run['result'] === 'failed' || $run['result'] === 'aborted') {
            continue;
        }
        foreach ($run['kopia'] as $k) {
            if ($k['ok'] && count($sources[$k['name']] ?? []) < 3) {
                $sources[$k['name']][] = $k['seconds'];
            }
        }
        $first = $run['kopia_first'];
        if ($first === null && $run['kopia']) {
            $k0 = $run['kopia'][0];
            $first = $k0['finished'] - $k0['seconds'];
        }
        if ($first && count($before) < 3) {
            $before[] = max(0, $first - $run['started']);
        }
        if ($run['finished'] && count($total) < 3) {
            $total[] = $run['finished'] - $run['started'];
        }
    }
    // $v is newest first (the history is sorted that way)
    $median = function (array $v): ?int {
        if (count($v) < 3) {
            return $v ? (int) $v[0] : null;
        }
        sort($v);
        return (int) $v[1];
    };
    return [
        'sources' => array_map($median, $sources),
        'before'  => $median($before),
        'total'   => $median($total),
    ];
}

// ===================================================================== more state

function backupDrift(): array
{
    $data = BACKUP_DATA_DIR;
    $j = readJson("$data/state/drift.json");
    if ($j) {
        return ['time' => (int) ($j['time'] ?? 0), 'items' => array_values(array_filter($j['items'] ?? [], 'is_array')),
                'policies' => backupPolicies($j['policies'] ?? null)];
    }
    // before 2.5: drift.txt with level words (German before 2.13)
    $items = [];
    foreach (@file("$data/state/drift.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(FEHLER|WARNUNG|ERROR|WARNING|INFO)\s+(.*)$/', $line, $m)) {
            $items[] = ['level' => ['FEHLER' => 'error', 'WARNUNG' => 'warn', 'ERROR' => 'error', 'WARNING' => 'warn', 'INFO' => 'info'][$m[1]], 'text' => $m[2]];
        }
    }
    return ['time' => (int) @filemtime("$data/state/drift.txt"), 'items' => $items, 'policies' => null];
}

/**
 * Per Kopia target whether its policy matches settings.ini (drift.json, engine
 * 2.14+): kind root|share|flash (app|vm since 2.19), the share, the name (the
 * share, app or VM; older engines: the share), ok, skipped (the run left it out)
 * and the differences as codes. null = not compared (older engine, Kopia off or
 * not reachable).
 */
function backupPolicies(mixed $list): ?array
{
    if (!is_array($list)) {
        return null;
    }
    $str = fn ($v) => is_scalar($v) ? (string) $v : '';
    $out = [];
    foreach ($list as $p) {
        if (!is_array($p) || !in_array($p['kind'] ?? '', ['root', 'share', 'flash', 'app', 'vm'], true)) {
            continue;
        }
        $out[] = [
            'kind'    => $p['kind'],
            'share'   => $str($p['share'] ?? ''),
            'name'    => $str($p['name'] ?? ($p['share'] ?? '')),
            'path'    => $str($p['path'] ?? ''),
            'ok'      => !empty($p['ok']),
            'skipped' => !empty($p['skipped']),
            'differences' => array_values(array_map(fn ($d) => [
                'what' => $str($d['what'] ?? ''), 'item' => $str($d['item'] ?? ''),
                'have' => $str($d['have'] ?? ''), 'want' => $str($d['want'] ?? ''),
            ], array_filter($p['differences'] ?? [], 'is_array'))),
        ];
    }
    return $out;
}

/**
 * Where the engine keeps dumps and archives: <dumps_share>/unraid-backup, in the
 * office's share <share>/backup. Until the next run moved them: the place before the
 * last setup (state/dumps-previous), or the old folder in appdata.
 */
function backupDumpsDir(): string
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $new = backupDumpsPath((string) (backupSettingsSummary($settings)['dumps_share'] ?? ''));
    $previous = trim((string) @file_get_contents(BACKUP_DATA_DIR . '/state/dumps-previous'));
    foreach ([$new, $previous, BACKUP_DATA_DIR . '/dumps'] as $dir) {
        if ($dir && is_dir($dir) && glob("$dir/[0-9]*-[0-9]*", GLOB_ONLYDIR)) {
            return $dir;
        }
    }
    return $new ?? BACKUP_DATA_DIR . '/dumps';
}

/**
 * <run>/ folders of engines before 2.18: database dumps (+ manifest, flash and libvirt archives).
 * The first 2.18 run clears them away once its packages are in place.
 */
function backupDumps(): array
{
    $dumps = [];
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    if (backupPlaceAsleep($settings)) {
        return $GLOBALS['backup']['dumps'] ?? [];     // never wake a disk to look
    }
    $dir = backupDumpsDir();
    foreach (@scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $run) {
        if (!preg_match('/^\d{8}-\d{4}$/', $run)) {
            continue;
        }
        $files = [];
        $bytes = 0;
        foreach (@scandir("$dir/$run/db") ?: [] as $f) {
            if ($f[0] === '.' || !is_file("$dir/$run/db/$f")) {
                continue;
            }
            $size = (int) @filesize("$dir/$run/db/$f");
            $bytes += $size;
            $files[] = ['name' => $f, 'bytes' => $size];
        }
        $flash = glob("$dir/$run/flash*.tar*") ?: [];
        $libvirt = is_file("$dir/$run/libvirt.tar.gz") ? "$dir/$run/libvirt.tar.gz" : null;
        $dumps[] = [
            'run'      => $run,
            'time'     => (int) (DateTime::createFromFormat('Ymd-Hi', $run)?->getTimestamp() ?: 0),
            'path'     => "$dir/$run",
            'files'    => $files,
            'bytes'    => $bytes,
            'manifest' => is_dir("$dir/$run/manifest"),
            'flash'    => $flash ? basename($flash[0]) : null,
            'libvirt'  => $libvirt,
            'libvirt_bytes' => $libvirt ? (int) @filesize($libvirt) : null,
            'libvirt_vms' => $libvirt ? backupLibvirtArchive($libvirt) : [],
        ];
    }
    return $dumps;
}

/** Does the backup place's share lie on a disk that sleeps right now? */
function backupPlaceAsleep(array $settings): bool
{
    $share = (string) backupSetting($settings, 'general', 'dumps_share', '');
    return $share !== '' && backupShareAsleep($share, $settings, sleepingDisks());
}

/**
 * Does a share lie on a disk that sleeps? Its bases as settings.ini noted them at the setup and as
 * Unraid's share config allows them (pools, the array disks it may use) - looked up on the flash
 * and in Unraid's bookkeeping only, never on the disks themselves.
 */
function backupShareAsleep(string $share, array $settings, array $asleep): bool
{
    if (!in_array(true, $asleep, true)) {
        return false;
    }
    $bases = array_filter(array_map('trim', explode(',', (string) backupSetting($settings, "share|$share", 'locations', ''))));
    $cfg = preg_match('/^[\w .-]+$/', $share) ? readCfg("/boot/config/shares/$share.cfg") : [];
    foreach (['shareCachePool', 'shareCachePool2'] as $k) {
        if (($cfg[$k] ?? '') !== '' && ($cfg['shareUseCache'] ?? 'no') !== 'no') {
            $bases[] = $cfg[$k];
        }
    }
    if ($cfg && ($cfg['shareUseCache'] ?? 'no') !== 'only') {
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $bases = array_merge($bases, $include ?: array_filter(array_keys($asleep), fn ($d) => preg_match('/^disk\d+$/', (string) $d)));
    }
    foreach (array_unique($bases) as $base) {
        if (baseAsleep((string) $base, $asleep)) {
            return true;
        }
    }
    return false;
}

/** Does a path under /mnt (a share's or a disk's) lie on a disk that sleeps? */
function backupPathAsleep(string $path, array $settings, array $asleep): bool
{
    if (preg_match('#^/mnt/user0?/([^/]+)#', $path, $m)) {
        return backupShareAsleep($m[1], $settings, $asleep);
    }
    if (preg_match('#^/mnt/([^/]+)/#', $path, $m) && !in_array($m[1], ['addons', 'disks', 'remotes', 'rootshare'], true)) {
        return baseAsleep($m[1], $asleep);
    }
    return false;
}

/**
 * The packages in the backup place (engine 2.18+): per app and VM what is in it and from which run,
 * which are stale (written by an earlier run than the last one, e.g. an app that is gone or no
 * longer backed up — the engine never deletes them), the server's and the flash's package, and how
 * many run folders of older engines still lie there. Not read while the share's disk sleeps.
 */
function backupPackages(array $settings): array
{
    static $cache = [];
    $base = backupDumpsPath((string) backupSetting($settings, 'general', 'dumps_share', ''));
    $empty = ['base' => $base, 'asleep' => false, 'found' => false, 'run' => null, 'time' => null, 'result' => null,
              'apps' => [], 'vms' => [], 'flash' => null, 'server' => null, 'old_runs' => 0];
    if ($base === null) {
        return $empty;
    }
    if (backupPlaceAsleep($settings)) {
        return ['asleep' => true] + ($GLOBALS['backup']['packages'] ?? $empty);
    }
    $stamp = implode(':', array_map(fn ($p) => (int) @filemtime("$base/$p"), ['', 'apps', 'vms', 'flash', 'server', 'server/run.json']));
    if (($cache[$base][0] ?? null) !== $stamp) {
        $cache = [$base => [$stamp, backupPackagesRead($base)]];
    }
    return $cache[$base][1];
}

/** Reads the packages of a backup place (see backupPackages) */
function backupPackagesRead(string $base): array
{
    $time = fn (string $run) => preg_match('/^\d{8}-\d{4}$/', $run) ? (int) (DateTime::createFromFormat('Ymd-Hi', $run)?->getTimestamp() ?: 0) : 0;
    $str = fn ($v) => is_scalar($v) ? (string) $v : '';
    $files = fn (array $m) => array_values(array_map(fn ($f) => [
        'path' => $str($f['path'] ?? ''), 'bytes' => (int) ($f['bytes'] ?? 0), 'run' => $str($f['run'] ?? ''),
        'time' => $time($str($f['run'] ?? '')), 'what' => $str($f['what'] ?? ''), 'container' => $str($f['container'] ?? ''),
    ], array_filter((array) ($m['files'] ?? []), 'is_array')));
    $server = readJson("$base/server/run.json");
    $last = $str($server['run'] ?? '');
    $out = ['base' => $base, 'asleep' => false, 'found' => is_dir("$base/apps") || is_dir("$base/vms") || $server !== null,
            'run' => $last ?: null, 'time' => $last ? $time($last) : null, 'result' => $server['result'] ?? null,
            'apps' => [], 'vms' => [], 'flash' => null, 'server' => null, 'old_runs' => 0];
    // stale: written by a run before the last one that wrote packages
    $stale = fn (string $run) => $last !== '' && $run !== '' && strcmp($run, $last) < 0;
    foreach (['apps', 'vms'] as $sub) {
        foreach (@scandir("$base/$sub") ?: [] as $folder) {
            if ($folder[0] === '.' || !is_dir("$base/$sub/$folder")) {
                continue;
            }
            $m = readJson("$base/$sub/$folder/manifest.json") ?? [];
            $run = $str($m['run'] ?? '');
            $list = $files($m);
            $p = [
                'name'   => $str($m['name'] ?? '') ?: $folder,
                'folder' => $folder,
                'path'   => "$base/$sub/$folder",
                'run'    => $run,
                'time'   => $time($run),
                'result' => $str($m['result'] ?? ''),
                'stale'  => $stale($run),
                'bytes'  => array_sum(array_column($list, 'bytes')),
                'files'  => $list,
            ];
            if ($sub === 'apps') {
                $p += [
                    'type'       => $str($m['type'] ?? ''),
                    'containers' => array_values(array_map(fn ($c) => ['name' => $str($c['name'] ?? ''), 'image' => $str($c['image'] ?? ''),
                                        'digest' => $str(($c['digests'] ?? [])[0] ?? ''), 'template' => $str($c['template'] ?? '')],
                                        array_filter((array) ($m['containers'] ?? []), 'is_array'))),
                    'dumps'      => array_values(array_map(fn ($d) => array_map($str, array_intersect_key($d, array_flip(
                                        ['container', 'type', 'state', 'login', 'user_var', 'password_var', 'client']))),
                                        array_filter((array) ($m['dumps'] ?? []), 'is_array'))),
                    'nextcloud'  => array_values(array_map(fn ($n) => ['container' => $str($n['container'] ?? ''), 'occ' => $str($n['occ'] ?? ''),
                                        'user' => $str($n['user'] ?? '') ?: 'www-data', 'same_as' => $str($n['same_as'] ?? '')],
                                        array_filter((array) ($m['nextcloud'] ?? []), 'is_array'))),
                    'compose_dir' => $str($m['compose']['manager_dir'] ?? ''),
                    // engine 2.19: consistent copies of the media servers' databases, the apps' own backups
                    'sqlite'     => array_values(array_map(fn ($q) => ['container' => $str($q['container'] ?? ''), 'file' => $str($q['file'] ?? ''),
                                        'source' => $str($q['source'] ?? ''), 'path' => $str($q['path'] ?? ''), 'state' => $str($q['state'] ?? ''),
                                        'check' => $str($q['check'] ?? ''), 'present' => !empty($q['present'])],
                                        array_filter((array) ($m['sqlite'] ?? []), 'is_array'))),
                    'own_backups' => array_values(array_map(fn ($o) => ['kind' => $str($o['kind'] ?? ''), 'container' => $str($o['container'] ?? ''),
                                        'path' => $str($o['path'] ?? ''), 'files' => (int) ($o['files'] ?? 0), 'newest' => (int) ($o['newest'] ?? 0),
                                        'asleep' => !empty($o['asleep'])],
                                        array_filter((array) ($m['own_backups'] ?? []), fn ($o) => is_array($o) && in_array($o['kind'] ?? '', ['emby', 'jellyfin', 'plex', 'immich'], true)))),
                ];
                $out['apps'][] = $p;
            } else {
                $nvram = array_values(array_filter(array_column($list, 'path'), fn ($x) => str_starts_with($x, 'nvram/')));
                $p += [
                    'xml'        => $str($m['xml'] ?? ''),
                    'uuid'       => $str($m['uuid'] ?? ''),
                    'autostart'  => !empty($m['autostart']),
                    'nvram'      => array_map('basename', $nvram),
                    'tpm'        => (bool) array_filter(array_column($list, 'path'), fn ($x) => str_starts_with($x, 'tpm/')),
                    'snapshotdb' => in_array('snapshotdb/snapshots.db', array_column($list, 'path'), true),
                    'held'       => $str($m['held'] ?? ''),
                    'disks'      => array_values(array_map(fn ($d) => ['source' => $str($d['source'] ?? ''), 'snapshot' => $str($d['snapshot'] ?? ''),
                                        'bytes' => isset($d['bytes']) ? (int) $d['bytes'] : null], array_filter((array) ($m['disks'] ?? []), 'is_array'))),
                ];
                $out['vms'][] = $p;
            }
        }
    }
    usort($out['apps'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($out['vms'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $flash = readJson("$base/flash/manifest.json");
    if ($flash && is_file("$base/flash/flash.tar.gz")) {
        $f = array_values(array_filter($files($flash), fn ($x) => $x['path'] === 'flash.tar.gz'))[0] ?? null;
        $out['flash'] = ['path' => "$base/flash/flash.tar.gz", 'bytes' => (int) @filesize("$base/flash/flash.tar.gz"),
                         'run' => $f['run'] ?? $str($flash['run'] ?? ''), 'time' => $f['time'] ?? $time($str($flash['run'] ?? ''))];
    }
    if ($server) {
        $list = $files($server);
        $lv = array_values(array_filter($list, fn ($x) => $x['path'] === 'libvirt.tar.gz'))[0] ?? null;
        $out['server'] = [
            'path'      => "$base/server",
            'libvirt'   => $lv ? "$base/server/libvirt.tar.gz" : null,
            'libvirt_bytes' => $lv['bytes'] ?? null,
            'libvirt_time'  => $lv['time'] ?? null,
            'templates' => count(array_filter($list, fn ($x) => str_starts_with($x['path'], 'docker-templates/'))),
            'compose'   => array_values(array_unique(array_map(fn ($x) => explode('/', $x['path'])[1] ?? '',
                               array_filter($list, fn ($x) => str_starts_with($x['path'], 'compose/'))))),
        ];
    }
    // run folders of older engines the next run clears away (the engine's rule: nothing else in them)
    $out['old_runs'] = count(array_filter(@scandir($base) ?: [], fn ($n) => preg_match('/^\d{8}-\d{4}$/', $n) && is_dir("$base/$n") && !is_link("$base/$n")
        && !array_filter(array_diff(@scandir("$base/$n") ?: [], ['.', '..']), fn ($e) => !preg_match('/^(db|manifest|libvirt\.tar\.gz|flash.*\.tar.*)$/', $e))));
    return $out;
}

/**
 * The VMs in a libvirt archive (libvirt.tar.gz from the dumps): name, UUID,
 * and which of their files are in it — for ready-made restore commands.
 * Read once per archive (a few hundred KB).
 */
function backupLibvirtArchive(string $file): array
{
    static $cache = [];
    $stamp = $file . ':' . (int) @filemtime($file);
    if (isset($cache[$stamp])) {
        return $cache[$stamp];
    }
    [$exit, $list] = run(['tar', '-tzf', $file], 30);
    if ($exit !== 0) {
        return $cache[$stamp] = [];
    }
    $names = array_flip(array_map(fn ($l) => rtrim($l, '/'), explode("\n", trim($list))));
    $vms = [];
    foreach (array_keys($names) as $entry) {
        if (!preg_match('#^libvirt/qemu/([^/]+)\.xml$#', $entry, $m)) {
            continue;
        }
        [$e, $xml] = run(['tar', '-xzOf', $file, $entry], 20);
        $uuid = $e === 0 && preg_match('#<uuid>([0-9a-f-]{36})</uuid>#', $xml, $u) ? $u[1] : null;
        $nvram = $uuid ? array_values(array_filter(array_keys($names), fn ($n) => preg_match('#^libvirt/qemu/nvram/' . preg_quote($uuid, '#') . '_VARS[^/]*\.fd$#', $n))) : [];
        $vms[] = [
            'name'      => $m[1],
            'uuid'      => $uuid,
            'nvram'     => array_map('basename', $nvram),
            'tpm'       => $uuid && isset($names["libvirt/qemu/swtpm/tpm-states/$uuid"]),
            'autostart' => isset($names["libvirt/qemu/autostart/{$m[1]}.xml"]),
        ];
    }
    usort($vms, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $cache = [$stamp => $vms];
    return $vms;
}

/**
 * The nightly run's schedule. As a plugin it lies in the plugin's cron file
 * and can be set once the setup is done; in the stack it is the User Scripts
 * entry setup.sh creates.
 */
function backupSchedule(): array
{
    if (AS_PLUGIN) {
        return ['script' => is_file(BACKUP_DATA_DIR . '/settings.ini')] + officeJobSchedule('backup');
    }
    $script = "/boot/config/plugins/user.scripts/scripts/" . BACKUP_USER_SCRIPT . '/script';
    $result = ['script' => is_file($script), 'frequency' => null, 'custom' => null, 'enabled' => false];
    foreach ((array) json_decode((string) @file_get_contents(BACKUP_SCHEDULE), true) as $entry) {
        if (is_array($entry) && ($entry['script'] ?? '') === $script) {
            $result['frequency'] = (string) ($entry['frequency'] ?? '');
            $result['custom'] = (string) ($entry['custom'] ?? '');
            $result['enabled'] = !in_array($result['frequency'], ['', 'disabled'], true);
        }
    }
    return $result;
}

/**
 * The entry's description as the office writes it today (setup.sh writes the
 * same for new entries) — only the #description line, nothing else.
 */
function backupUserScriptDescribe(): void
{
    if (AS_PLUGIN) {
        return;
    }
    $file = US_DIR . '/scripts/' . BACKUP_USER_SCRIPT . '/script';
    $text = (string) @file_get_contents($file);
    if (!preg_match('#^exec "([^"]+)/backup\.sh"#m', $text, $m)) {
        return;
    }
    $line = "#description=Unraid Secretary Office - Mr. Backupsy's nightly backup: snapshots, database dumps, Kopia offsite. "
          . "Set up and scheduled in the office. Code: {$m[1]}, data: " . dirname($m[1]) . '/data/unraid-backup';
    $new = preg_replace('/^#description=.*$/m', $line, $text, 1);
    if ($new !== null && $new !== $text) {
        writeAtomic($file, $new, 0755, 0, 0);
    }
}

/**
 * A Nextcloud container's data folder on the host: 'datadirectory' from its
 * config.php (read through the container's own mounts), mapped back to the
 * host. Null when it can't be found out - $asleep says when that is because
 * config.php or the folder lies on a sleeping disk (then nothing was read).
 */
function backupNextcloudDataDir(string $container, ?bool &$asleep = null, array $settings = []): ?string
{
    $asleep = false;
    $disks = sleepingDisks();
    $c = houseInspect($container);
    if (!$c) {
        return null;
    }
    $mounts = [];
    foreach ((array) ($c['Mounts'] ?? []) as $m) {
        if (!empty($m['Source']) && !empty($m['Destination'])) {
            $mounts[rtrim($m['Destination'], '/')] = rtrim($m['Source'], '/');
        }
    }
    $toHost = function (string $path) use ($mounts): ?string {
        $best = null;
        foreach ($mounts as $dest => $src) {
            if (($path === $dest || str_starts_with($path, "$dest/")) && ($best === null || strlen($dest) > strlen($best))) {
                $best = $dest;
            }
        }
        return $best === null ? null : $mounts[$best] . substr($path, strlen($best));
    };
    $config = $toHost('/var/www/html/config/config.php');
    if ($config && backupPathAsleep($config, $settings, $disks)) {
        $asleep = true;
        return null;
    }
    $text = $config ? (string) @file_get_contents($config, false, null, 0, 65536) : '';
    $dir = preg_match("/'datadirectory'\s*=>\s*'([^']+)'/", $text, $m) ? rtrim($m[1], '/') : '/var/www/html/data';
    $host = $toHost($dir);
    if ($host !== null && backupPathAsleep($host, $settings, $disks)) {
        $asleep = true;
        return null;
    }
    return $host !== null && is_dir($host) ? $host : null;
}

/**
 * What a running backup has paused right now — the engine notes it in state/
 * so it can put things back after a crash: stopped containers (state/stopped,
 * gone once they run again) and Nextclouds in maintenance mode.
 */
function backupPaused(string $data): array
{
    $read = function (string $file): array {
        $list = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $list), fn ($x) => $x !== '' && preg_match('/^[\w.@-]{1,128}$/', $x))));
    };
    $stopped = $read("$data/state/stopped");
    $maint = $read("$data/state/maintenance");
    return [
        'stopped'           => $stopped,
        'stopped_since'     => $stopped ? (@filemtime("$data/state/stopped") ?: null) : null,
        'maintenance'       => $maint,
        'maintenance_since' => $maint ? (@filemtime("$data/state/maintenance") ?: null) : null,
    ];
}

/** Sets the nightly run (cron) or switches it off (null / '') — plugin cron file or User Scripts */
function backupSetSchedule(mixed $cron): array
{
    $cron = is_string($cron) && trim($cron) !== '' ? trim($cron) : null;
    if (AS_PLUGIN && $cron !== null && !is_file(BACKUP_DATA_DIR . '/settings.ini')) {
        throw new Problem('backup_no_settings');
    }
    $live = officeJobSetSchedule('backup', $cron);
    logLine('Mr. Backup: schedule ' . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => backupScan()];
}

/** Mounts the script left under its mount root (keep_mounts, or a run that died) */
function backupMounted(?string $root): array
{
    if (!$root) {
        return [];
    }
    $found = [];
    foreach (mountTable() as $m) {
        if (under($m['mount'], $root) && $m['mount'] !== rtrim($root, '/')) {
            $found[] = $m['mount'];
        }
    }
    return $found;
}

// ===================================================================== actions

function backupCheckReady(): string
{
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh")) {
        throw new Problem('backup_missing', ['dir' => $dir]);
    }
    if ((backupAbout($dir)['interface'] ?? 0) < BACKUP_INTERFACE) {
        throw new Problem('backup_too_old', ['version' => backupAbout($dir)['version'] ?? '?']);
    }
    $holder = backupLockHolder();
    if ($holder !== null) {
        throw new Problem(match (true) {
            backupSetupStatus()['running'] || $holder['holder'] === 'setup' => 'setup_running',
            $holder['holder'] === 'restore' => 'restore_running',
            default => 'backup_running',
        });
    }
    return $dir;
}

/** Hands a backup engine command to the host's atd (see hostLaunch()) */
function backupLaunch(array $args, array $env = []): void
{
    // the engine finds its data next to its code in the stack, not in the plugin: always say where
    hostLaunch('backup-job', array_merge(['/bin/bash'], $args), ['UB_DATA' => BACKUP_DATA_DIR] + $env);
}

function backupStart(string $mode): array
{
    if (!isset(BACKUP_MODES[$mode])) {
        throw new Problem('unknown_target', ['target' => $mode]);
    }
    $dir = backupCheckReady();
    $data = BACKUP_DATA_DIR;
    if (!is_file("$data/settings.ini")) {
        throw new Problem('backup_no_settings');
    }
    $before = (int) (readJson("$data/state/status.json")['started'] ?? 0);
    $skipBefore = readJson("$data/state/skipped.json");
    $t0 = time();
    backupLaunch(array_merge([$dir . '/backup.sh'], BACKUP_MODES[$mode]));
    logLine("Backup: started backup.sh ($mode) via at");

    // wait a moment until it took the lock and wrote its status - or found the lock taken after all
    // (something took it between our look and its start: engine 2.20 says so in skipped.json)
    $seen = false;
    $skipped = null;
    for ($i = 0; $i < 40 && !$seen && !$skipped; $i++) {
        usleep(250000);
        clearstatcache();
        $status = readJson("$data/state/status.json");
        $seen = (int) ($status['started'] ?? 0) > $before;
        $skip = readJson("$data/state/skipped.json");
        if ($skip && $skip !== $skipBefore && (int) ($skip['time'] ?? 0) >= $t0 - 1) {
            $skipped = backupSkipRow($skip);
        }
    }
    if ($skipped) {
        logLine("Backup: backup.sh ($mode) was skipped - $skipped[reason]");
    }
    return ['ok' => true, 'started' => $seen, 'skipped' => $skipped, 'state' => backupScan()];
}

/** SIGTERM to the running backup.sh — its trap cleans up (Kopia, containers, mounts) */
function backupAbort(): array
{
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!flockHeld("$data/state/lock")) {
        throw new Problem('backup_not_running');
    }
    $pid = (int) (readJson("$data/state/status.json")['pid'] ?? 0);
    if (!backupIsScript($pid, $dir)) {
        $pid = 0;
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $proc) {
            $p = (int) basename($proc);
            if (backupIsScript($p, $dir) && !backupIsScript((int) backupParent($p), $dir)) {
                $pid = $p;
                break;
            }
        }
    }
    if (!$pid) {
        throw new Problem('backup_no_process');
    }
    if (!posix_kill($pid, SIGTERM)) {
        throw new Problem('command_failed', ['detail' => "kill $pid"]);
    }
    logLine("Backup: sent SIGTERM to backup.sh (PID $pid)");
    usleep(500000);
    return ['ok' => true, 'pid' => $pid, 'state' => backupScan()];
}

function backupIsScript(int $pid, string $dir): bool
{
    if ($pid <= 1) {
        return false;
    }
    $args = explode("\0", (string) @file_get_contents("/proc/$pid/cmdline"));
    foreach (array_slice($args, 0, 3) as $arg) {
        if ($arg === "$dir/backup.sh" || ($arg !== '' && @realpath($arg) === @realpath("$dir/backup.sh"))) {
            return true;
        }
    }
    return false;
}

function backupParent(int $pid): int
{
    return preg_match('/^PPid:\s+(\d+)/m', (string) @file_get_contents("/proc/$pid/status"), $m) ? (int) $m[1] : 0;
}

/** Releases leftover snapshot mounts ("backup.sh --unmount") — never during a run */
function backupUnmount(): array
{
    $dir = backupCheckReady();
    $root = backupSetting(backupReadSettings(BACKUP_DATA_DIR . '/settings.ini'), 'general', 'mount_root');
    if (!backupMounted($root)) {
        return ['ok' => true, 'state' => backupScan()];
    }
    backupLaunch([$dir . '/backup.sh', '--unmount']);
    logLine('Backup: started backup.sh --unmount via at');
    for ($i = 0; $i < 60 && backupMounted($root); $i++) {
        usleep(500000);
    }
    $left = backupMounted($root);
    if ($left) {
        throw new Problem('backup_unmount_left', ['n' => count($left), 'path' => $left[0]]);
    }
    return ['ok' => true, 'state' => backupScan()];
}

function backupLog(string $name): array
{
    $logs = backupLogs();
    if (!isset($logs[$name])) {
        throw new Problem('unknown_target', ['target' => $name]);
    }
    $path = $logs[$name]['path'];
    $size = (int) @filesize($path);
    $h = @fopen($path, 'r');
    if (!$h) {
        throw new Problem('command_failed', ['detail' => "cannot read $name"]);
    }
    if ($size > BACKUP_LOG_BYTES) {
        fseek($h, $size - BACKUP_LOG_BYTES);
        fgets($h);              // start at a full line
    }
    $text = (string) stream_get_contents($h);
    fclose($h);
    return ['ok' => true, 'name' => $name, 'size' => $size, 'cut' => $size > BACKUP_LOG_BYTES, 'text' => $text];
}

// ===================================================================== checks (for the caretaker)

/** What Mr. Backup needs from the server, and what the user still has to do */
function backupChecks(): array
{
    $data = BACKUP_DATA_DIR;
    $out = [];
    $settings = backupReadSettings("$data/settings.ini");
    $summary = backupSettingsSummary($settings);
    $kopiaOn = $summary['kopia_enabled'];

    if (!AS_PLUGIN) {           // the plugin schedules the run itself
        $out[] = finding('user_scripts', 'required', housePlugin('user.scripts'), [], 'apps');
    }
    $out[] = finding('setup', 'required', is_file("$data/settings.ini"), ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    $schedule = backupSchedule();
    if (!AS_PLUGIN) {
        $out[] = finding('user_script', 'required', $schedule['script'], ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    }
    if ($schedule['script']) {
        $out[] = finding('schedule', 'required', $schedule['enabled'], [], '#/backup/schedule');
    }

    // Kopia: a must for offsite copies, otherwise a recommendation
    $level = $kopiaOn ? 'required' : 'recommended';
    $containers = houseContainers();
    $name = $summary['kopia_container'];
    if (!$name || !isset($containers[$name])) {
        $name = null;
        foreach ($containers as $c) {
            if (stripos($c['image'], 'kopia') !== false) {
                $name = $c['name'];
                break;
            }
        }
    }
    $out[] = finding('kopia', $level, $name !== null, ['name' => $name ?? 'kopia'], 'apps');
    if ($name !== null) {
        $c = $containers[$name];
        $out[] = finding('kopia_running', $level, $c['running'], ['name' => $name], 'docker');
        $inspect = houseInspect($name);
        if ($inspect) {
            $env = [];
            foreach ($inspect['Config']['Env'] ?? [] as $e) {
                [$k, $v] = explode('=', $e, 2) + [1 => ''];
                $env[$k] = $v;
            }
            // linuxserver-style images run the server as PUID; without PUID the image decides (often root)
            $asRoot = !isset($env['PUID']) || $env['PUID'] === '0';
            $out[] = finding('kopia_root', $level, $asRoot, ['name' => $name, 'puid' => $env['PUID'] ?? '–'], 'docker');
            $root = rtrim($summary['mount_root'] ?: '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/snapshots', '/');
            $mapping = null;
            foreach ($inspect['Mounts'] ?? [] as $m) {
                if (rtrim((string) ($m['Source'] ?? ''), '/') === $root) {
                    $mapping = $m;
                }
            }
            $ok = $mapping && empty($mapping['RW']) && in_array($mapping['Propagation'] ?? '', ['slave', 'rslave'], true);
            $out[] = finding('kopia_mapping', $level, $ok, ['name' => $name, 'path' => $root,
                'now' => $mapping ? (($mapping['RW'] ? 'rw' : 'ro') . ',' . ($mapping['Propagation'] ?: 'private')) : '–'], 'docker');
        }
        if ($kopiaOn) {
            // the engine finds out whether the repository answers; we read its last word
            $status = readJson("$data/state/status.json");
            $state = $status['kopia']['state'] ?? null;
            $out[] = finding('kopia_repo', 'required', $state === null ? null : $state === 'yes', ['name' => $name]);
        }
    }

    if (is_file("$data/settings.ini")) {
        $drift = array_filter(backupDrift()['items'], fn ($d) => in_array($d['level'] ?? '', ['warn', 'error'], true));
        $out[] = finding('drift', 'recommended', !$drift, ['n' => count($drift)], '#/backup/setup');

        // the packages in their own backup share, never in appdata — without one the engine refuses to run;
        // Unraid's share config (on the flash) says it exists, the share itself is only looked at while its disks are awake
        $ds = (string) ($summary['dumps_share'] ?? '');
        $dsName = $ds !== '' && !in_array(strtolower($ds), ['appdata', 'system', 'domains'], true);
        $dsCfg = $dsName && preg_match('/^[\w .-]+$/', $ds) && is_file("/boot/config/shares/$ds.cfg");
        $dsSleeps = $dsName && !$dsCfg && backupShareAsleep($ds, $settings, sleepingDisks());
        $dsBad = !$dsName || (!$dsCfg && !$dsSleeps && !is_dir("/mnt/user/$ds"));
        $out[] = finding('dumps_share', 'required', $dsSleeps ? null : !$dsBad, ['share' => $ds], '#/backup/setup');

        // nothing of ours directly in /mnt (Fix Common Problems rightly complains): the mounts go to
        // /mnt/addons; the setup moves them once Kopia's mapping follows, the next run tidies up
        $legacy = array_values(array_filter(['/mnt/backup-snapshots', '/mnt/btrfs-snap'], 'is_dir'));
        $mountRoot = (string) ($summary['mount_root'] ?? '');
        $inMnt = $legacy || preg_match('#^/mnt/[^/]+$#', $mountRoot) || preg_match('#^/mnt/[^/]+$#', (string) ($summary['view_root'] ?? ''));
        $out[] = finding('mnt_folders', 'recommended', !$inMnt, ['folders' => implode(', ', $legacy ?: [$mountRoot]),
            'path' => '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/snapshots', 'name' => $summary['kopia_container'] ?: 'kopia'], '#/backup/setup');
        if (!$dsBad && $kopiaOn) {
            $mode = backupSetting($settings, "share|$ds", 'mode', 'off');
            $out[] = finding('dumps_offsite', 'recommended', $mode === 'kopia', ['share' => $ds, 'mode' => $mode], '#/backup/setup');
        }

        // Nextcloud refuses to work (and so its maintenance mode) when others can read its data folder —
        // and Unraid resets a share's root to 0777 nobody:users on saving share settings unless its group is
        // "users" (lasting fix: 33:100, 0750)
        $seen = [];
        foreach ($summary['nextcloud'] as $nc) {
            $dir = backupNextcloudDataDir($nc['container'], $sleeps, $settings);
            if ($sleeps) {                                  // its disk sleeps: not looked at, not judged
                $out[] = finding('nextcloud_datadir', 'required', null, ['name' => $nc['container'], 'path' => '?', 'mode' => '?'], 'userscripts');
                continue;
            }
            if ($dir !== null && !isset($seen[$dir])) {     // app and cron of one Nextcloud share it
                $seen[$dir] = true;
                $mode = @fileperms($dir);
                $out[] = finding('nextcloud_datadir', 'required', $mode === false ? null : ($mode & 0007) === 0,
                    ['name' => $nc['container'], 'path' => $dir, 'mode' => $mode === false ? '?' : sprintf('%o', $mode & 0777)], 'userscripts');
            }
        }

        // the backup stops running containers for its snapshots — the office too, unless told otherwise
        $office = array_values(array_filter(array_keys($containers), fn ($n) => str_starts_with($n, 'UnraidSecretaryOffice')));
        if ($office && $summary['docker_stop'] !== 'none') {
            $stopped = array_values(array_diff($office, $summary['no_stop']));
            $out[] = finding('office_keeps_running', 'recommended', !$stopped, ['names' => implode(', ', $stopped)], '#/backup/setup');
        }
    }

    return $out;
}

// ===================================================================== setup (setup.sh --plan / --apply)

const BACKUP_SETUP_KEYS = ['mode', 'retention', 'kopia_retention', 'method', 'kopia_ignore', 'exclude_dataset'];
const BACKUP_ITEM_KEYS  = ['kopia', 'folder', 'kopia_retention', 'kopia_ignore'];     // [app|vm "<name>"], engine 2.19

/** setup.sh's progress (state/setup-status.json), without the messages */
function backupSetupStatus(): array
{
    $data = BACKUP_DATA_DIR;
    $s = readJson("$data/state/setup-status.json");
    $running = $s && ($s['result'] ?? '') === 'running' && posix_kill((int) ($s['pid'] ?? 0), 0)
        && flockHeld("$data/state/lock");
    if ($s && ($s['result'] ?? '') === 'running' && !$running) {
        $s['result'] = 'failed';               // killed hard
    }
    return [
        'running' => $running,
        'mode'    => $s['mode'] ?? null,
        'result'  => $s['result'] ?? null,
        'started' => $s['started'] ?? null,
        'finished' => $s['finished'] ?? null,
        'written' => $s['written'] ?? false,
        'errors'  => $s['errors'] ?? 0,
        'warnings' => $s['warnings'] ?? 0,
        'plan_time' => @filemtime("$data/state/setup-plan.json") ?: null,
    ];
}

/** Looks at the server and makes proposals — writes nothing but state/setup-plan.json */
function backupSetupPlan(bool $measure): array
{
    $dir = backupCheckReady();
    backupLaunch(["$dir/setup.sh", '--plan'], ['UB_SIZE_TIMEOUT' => $measure ? 120 : 0]);
    logLine('Backup: setup.sh --plan started via at' . ($measure ? ' (measuring sizes)' : ''));
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/** The last plan plus progress and messages of the last plan/apply */
function backupSetupGet(): array
{
    $data = BACKUP_DATA_DIR;
    $plan = readJson("$data/state/setup-plan.json");
    if (is_array($plan['shares'] ?? null)) {
        $asleep = sleepingDisks();
        foreach ($plan['shares'] as &$share) {
            $share += backupShareTop($share, $asleep);
        }
        unset($share);
    }
    return [
        'ok'     => true,
        'status' => backupSetupStatus(),
        'run'    => readJson("$data/state/setup-status.json"),
        'plan'   => $plan,
    ];
}

/**
 * The folders at the top of a share, so Kopia can be told to leave one out with
 * a click. Only disks and pools that are awake are looked at (the others are
 * named in top_asleep); a share with very many (one folder per film) gets none.
 */
function backupShareTop(array $share, array $asleep): array
{
    $name = (string) ($share['name'] ?? '');
    $dirs = [];
    $sleeping = [];
    $many = false;
    if ($name === '' || empty($share['exists']) || str_contains($name, '/')) {
        return ['top' => [], 'top_asleep' => [], 'top_many' => false];
    }
    foreach (array_filter(array_map('trim', explode(',', (string) ($share['locations'] ?? '')))) as $base) {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $base)) {
            continue;
        }
        if (baseAsleep($base, $asleep)) {
            $sleeping[] = $base;
            continue;
        }
        $names = @scandir("/mnt/$base/$name") ?: [];
        if (count($names) > 302) {
            $many = true;
            continue;
        }
        foreach ($names as $n) {
            if ($n[0] !== '.' && !str_starts_with($n, '_UnraidSecretaryOffice-trash') && is_dir("/mnt/$base/$name/$n")) {
                $dirs[$n] = true;
            }
        }
    }
    $dirs = array_keys($dirs);
    if (count($dirs) > 60) {
        $many = true;
    }
    natcasesort($dirs);
    return ['top' => $many ? [] : array_values($dirs), 'top_asleep' => $sleeping, 'top_many' => $many];
}

/**
 * Applies the user's decisions: settings.ini keys as in the plan's P. Only
 * keys the plan knows (or per-share/-database keys of things it listed) get
 * through; setup.sh validates the values again before it writes.
 */
function backupSetupApply(mixed $decisions): array
{
    $dir = backupCheckReady();
    $data = BACKUP_DATA_DIR;
    $plan = readJson("$data/state/setup-plan.json");
    if (!$plan || !is_array($decisions) || !$decisions || array_is_list($decisions) || count($decisions) > 5000) {
        throw new Problem('setup_bad_decisions', ['detail' => $plan ? 'decisions' : 'no plan']);
    }
    $shares = array_column($plan['shares'] ?? [], 'name');
    $dbs = array_column(array_filter($plan['databases'] ?? [], fn ($d) => !empty($d['dumpable'])), 'container');
    $ncs = array_merge(...array_map(fn ($n) => $n['members'] ?? [], $plan['nextcloud'] ?? []) ?: [[]]);
    $vms = array_column($plan['vms'] ?? [], 'name');
    // apps as the office groups them: a compose project, or a single container
    $apps = array_values(array_unique(array_map(fn ($c) => (string) (($c['project'] ?? '') !== '' ? $c['project'] : ($c['name'] ?? '')), $plan['containers'] ?? [])));
    $clean = [];
    foreach ($decisions as $key => $value) {
        $key = (string) $key;
        $ok = $key === '_retire_sources' || array_key_exists($key, $plan['P'] ?? [])
            || (preg_match('/^share\|(.+)\|([a-z_]+)$/D', $key, $m) && in_array($m[1], $shares, true) && in_array($m[2], BACKUP_SETUP_KEYS, true))
            || (preg_match('/^dump\|(.+)\|type$/D', $key, $m) && in_array($m[1], $dbs, true))
            || (preg_match('/^nextcloud\|(.+)\|preexisting_maintenance$/D', $key, $m) && in_array($m[1], $ncs, true))
            || (preg_match('/^vm\|(.+)\|(mode|prepare|retention)$/D', $key, $m) && in_array($m[1], $vms, true))
            || (preg_match('/^vm\|(.+)\|([a-z_]+)$/D', $key, $m) && in_array($m[1], $vms, true) && in_array($m[2], BACKUP_ITEM_KEYS, true))
            || (preg_match('/^app\|(.+)\|([a-z_]+)$/D', $key, $m) && in_array($m[1], $apps, true) && in_array($m[2], BACKUP_ITEM_KEYS, true));
        $plain = fn ($v) => is_string($v) && strlen($v) <= 500 && !preg_match('/[\x00-\x1f]/', $v);
        $valid = $plain($value) || (is_array($value) && array_is_list($value) && count($value) <= 1000 && !in_array(false, array_map($plain, $value), true));
        if (!$ok || !$valid) {
            throw new Problem('setup_bad_decisions', ['detail' => $key]);
        }
        $clean[$key] = $value;
    }
    $file = "$data/state/setup-decisions.json";
    writeAtomic($file, jsonEncode($clean), 0600, 0, 0);
    backupLaunch(["$dir/setup.sh", "--apply=$file"], ['UB_SIZE_TIMEOUT' => 0]);
    logLine('Backup: setup.sh --apply started via at (' . count($clean) . ' decisions)');
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/**
 * Starts the setup anew: setup.sh --forget puts settings.ini, the decisions and
 * the last plan aside (state/reset-<time>/). Nothing backed up is touched.
 */
function backupSetupForget(): array
{
    $dir = backupCheckReady();
    backupLaunch(["$dir/setup.sh", '--forget', '--yes']);
    logLine('Backup: setup.sh --forget started via at');
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/** Until setup.sh reports that it runs (a moment), or gives up */
function backupSetupWait(): bool
{
    $data = BACKUP_DATA_DIR;
    $before = time() - 1;
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        clearstatcache();
        $s = readJson("$data/state/setup-status.json");
        if ((int) ($s['started'] ?? 0) >= $before) {
            return true;
        }
    }
    return false;
}
