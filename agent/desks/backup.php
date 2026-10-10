<?php
declare(strict_types=1);

/*
 * Mr. Backup — runs the backup engine in backup/ (unraid-backup).
 *
 * The engine works on its own: the plugin's cron file starts it at night, it keeps its
 * settings in data/unraid-backup/settings.ini and writes its state to
 * data/unraid-backup/state/ (status.json, last-run.json, history.jsonl,
 * drift.json — see "Status fuer andere Programme" in backup/README.md).
 * Mr. Backup reads that, shows it, and starts, checks or stops runs. Since
 * engine 2.18 the backup place holds a package per app and VM (apps/, vms/,
 * server/, flash/ with a manifest.json each) — read by backupPackages(). Since 2.19 apps and VMs
 * at "local + Kopia" are Kopia sources of their own ([app|vm "<name>"] kopia = yes, backupKopiaItems()).
 *
 * Runs are handed to the host's atd ("at now"). A process started by the
 * agent itself would be stopped with it (the plugin's process group when
 * the array stops or the plugin is updated) —
 * a 10-hour backup must not depend on the office.
 *
 * Older script versions without state/status.json are shown from their log
 * files (read only); starting needs interface 1 or newer.
 *
 * Since engine 2.21 new things stay local and keep running until the user decided: backupWaiting() lists the new
 * folders the engine left out of Kopia (state/new-local.json), the new apps (containers not in [docker] known) and
 * the new VMs (no [vm] section) for the main page; the setup decides about them (kopia_known / kopia_ignore).
 * Since engine 2.37 a new folder inherits its share's level: only folders of the app/VM shares (backupAppShares())
 * can wait - in a data share they go with the share. Since 2.38 a folder of an app or VM set up before and only local
 * doesn't wait either (the engine keeps it local and out of new-local.json - an engine 2.37's file may still name one
 * until the next run).
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
const BACKUP_UPLOAD_LOOK = 180;          // a first upload's rate: from looks at least this far apart (seconds) …
const BACKUP_UPLOAD_KEEP = 900;          // … within the last 15 minutes; before that, the average since it started
const BACKUP_ASLEEP_NIGHTS = 7;          // engine 2.28: a share left out asleep so many nights in a row - the engine warned (UB_ASLEEP_NIGHTS)

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
        'setup_apply' => fn (array $r) => backupSetupApply($r['decisions'] ?? null, $r['plan_time'] ?? null),
        'setup_forget' => fn (array $r) => backupSetupForget(),
        'schedule'    => fn (array $r) => backupSetSchedule(cronField($r)),       // null: off — said, never a missing key
        // his let-go dialog's «Also clear away what he kept here» (agent/desks/backup-letgo.php): the look, then the clearing
        'letgo_look'  => fn (array $r) => backupLetGoLook(),
        'letgo_clear' => fn (array $r) => backupLetGoClear($r),          // hands the clearing to atd (job backup-letgo)
        'letgo_job'   => fn (array $r) => backupLetGoJobState($r),       // is a job that wrote nothing for a while still alive?
    ],
    'jobs'    => ['backup-letgo' => fn (array $args) => backupLetGoJob($args)],
    'checks' => fn () => backupChecks(),
    'metrics' => fn (): array => backupMetrics(),
]);

/**
 * Mr. Backupsy's numbers for Prometheus (lib/metrics.php, once a minute) —
 * only from the engine's state files: last-run.json (the last real backup
 * run), history.jsonl (the last one that went well), status.json and the lock
 * (a run going on now), skipped.json (a run that couldn't start because the
 * lock was busy, engine 2.20; read only when it is there and in its shape).
 */
function backupMetrics(?string $state = null): array
{
    $state ??= BACKUP_DATA_DIR . '/state';
    if (!is_dir($state)) {
        return [];
    }
    $word = fn (mixed $v, string $else = 'other') => is_string($v) && preg_match('/^[a-z0-9_]{1,40}$/D', $v) ? $v : $else;
    $out = [];
    $last = metricsCached("$state/last-run.json", fn (string $f) => readJson($f));
    if ($last && ($last['result'] ?? '') !== 'running') {
        $result = $word($last['result'] ?? null);
        $finished = (int) ($last['finished'] ?? 0);
        $ok = in_array($result, ['ok', 'warnings'], true);
        $out[] = metricsGauge('uso_backup_last_success', 'Whether the last real backup run went well (ok or with warnings)', $ok);
        $out[] = metricsGauge('uso_backup_last_result', 'The result of the last real backup run (ok, warnings, errors, failed, aborted)', [[['result' => $result], 1]]);
        if ($finished > 0) {
            $out[] = metricsGauge('uso_backup_last_run_end_timestamp_seconds', 'When the last real backup run ended', $finished);
            $out[] = metricsGauge('uso_backup_last_duration_seconds', 'How long the last real backup run took', max(0, $finished - (int) ($last['started'] ?? $finished)));
        }
        $out[] = metricsGauge('uso_backup_last_downtime_seconds', 'How long containers and VMs were held in the last real backup run', (int) ($last['downtime_s'] ?? 0));
        $out[] = metricsGauge('uso_backup_last_errors', 'Errors in the last real backup run', (int) ($last['errors'] ?? 0));
        $out[] = metricsGauge('uso_backup_last_warnings', 'Warnings in the last real backup run', (int) ($last['warnings'] ?? 0));
        if ($asleepRun = backupAsleepRun($last['asleep'] ?? null)) {
            $out[] = metricsGauge('uso_backup_last_asleep_shares', 'Shares the last real backup run left out because their pool slept (asleep_pools = skip)', $asleepRun['units']);
        }
        $kopia = is_array($last['kopia'] ?? null) ? $last['kopia'] : [];
        if (!empty($kopia['enabled'])) {
            $done = array_filter((array) ($kopia['done'] ?? []), 'is_array');
            $went = count(array_filter($done, fn ($d) => ($d['ok'] ?? false) === true));
            $planned = count((array) ($kopia['planned'] ?? []));
            $skipped = count(backupKopiaSkipped($kopia, false));       // engine 2.24: an array stop ended the run
            $asleep = count(backupKopiaSkipped($kopia, true));         // engine 2.28: their pool slept (never in the plan)
            $samples = [[['result' => 'ok'], $went], [['result' => 'failed'], max(0, max(count($done), $planned) - $went - $skipped)]];
            if ($skipped > 0) {
                $samples[] = [['result' => 'skipped'], $skipped];
            }
            if ($asleep > 0) {
                $samples[] = [['result' => 'asleep'], $asleep];
            }
            $out[] = metricsGauge('uso_backup_last_kopia_sources', 'Kopia sources of the last real backup run: copied (ok), not (failed, or never reached), skipped (the array was being stopped - the next run does them), asleep (their pool slept, asleep_pools = skip)',
                $samples);
        }
        $packages = is_array($last['packages'] ?? null) ? $last['packages'] : [];
        if (!empty($packages['written'])) {
            $out[] = metricsGauge('uso_backup_last_packages_bytes', 'What the last real backup run wrote into the packages of apps and VMs', (int) ($packages['written_bytes'] ?? 0));
            $each = [];
            foreach ((array) ($packages['list'] ?? []) as $p) {
                if (is_array($p) && is_string($p['name'] ?? null) && $p['name'] !== '' && ($p['result'] ?? '') !== 'planned') {
                    $each[] = [['kind' => $word($p['kind'] ?? null), 'name' => $p['name']], (int) ($p['bytes'] ?? 0)];
                }
            }
            $out[] = metricsGauge('uso_backup_package_bytes', 'The size of each package (app, VM, flash) in the backup place after the last real backup run', $each);
        }
    }
    $success = metricsCached("$state/history.jsonl", fn (string $f) => backupMetricsLastSuccess($f));
    if ($success > 0) {
        $out[] = metricsGauge('uso_backup_last_success_timestamp_seconds', 'When the last real backup run that went well (ok or with warnings) ended', $success);
    }

    // a run going on now: status.json says so, its process lives and the engine holds its lock (not a killed run's leftover)
    $status = metricsCached("$state/status.json", fn (string $f) => readJson($f));
    $running = is_array($status) && ($status['result'] ?? '') === 'running' && (int) ($status['pid'] ?? 0) > 1
        && posix_kill((int) $status['pid'], 0) && flockHeld("$state/lock");
    $out[] = metricsGauge('uso_backup_running', 'Whether the backup engine is running now (a backup, a check or a dry run)', $running);
    if ($running) {
        $out[] = metricsGauge('uso_backup_current_phase', 'The run going on now: its mode and phase', [[['mode' => $word($status['mode'] ?? null), 'phase' => $word($status['phase'] ?? null)], 1]]);
        $out[] = metricsGauge('uso_backup_current_started_timestamp_seconds', 'When the run going on now started', (int) ($status['started'] ?? 0));
    }

    $skipped = metricsCached("$state/skipped.json", fn (string $f) => readJson($f));
    if (is_array($skipped) && is_int($skipped['time'] ?? null) && $skipped['time'] > 0) {
        $out[] = metricsGauge('uso_backup_last_skipped_timestamp_seconds', 'When a run last could not start because the engine was busy, with its mode and reason',
            [[['mode' => $word($skipped['mode'] ?? null), 'reason' => $word($skipped['reason'] ?? null)], $skipped['time']]]);
    }
    return $out;
}

/** The end of the newest real backup run in history.jsonl that went well (ok or with warnings), 0 when there is none */
function backupMetricsLastSuccess(string $file): int
{
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $run = json_decode($lines[$i], true);
        if (is_array($run) && ($run['mode'] ?? 'backup') === 'backup' && in_array($run['result'] ?? '', ['ok', 'warnings'], true)) {
            return (int) ($run['finished'] ?? 0);
        }
    }
    return 0;
}

// ===================================================================== state

function backupScan(): array
{
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
    // (an unknown holder - an engine before 2.20 writes no note - still counts as a run); nor while
    // backup.sh --recover brings back what a stopped run left, right after the array start (engine 2.25)
    $holder = backupLockHolder();
    $running = $holder !== null && !$setup['running'] && !in_array($holder['holder'], ['setup', 'restore'], true)
        && !($holder['holder'] === 'backup' && $holder['mode'] === 'recover');
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
        'abort_asked' => backupAbortAsked($running, $status),     // «Abort» sent: the page says so until the run ends
        // a run the array stop ended leaves what it stopped noted for the next run (engine 2.24)
        'left'       => !$running && ($status['result'] ?? '') === 'aborted' && ($status['message'] ?? '') === 'array_stopping' ? backupPaused($data) : null,
        'history'    => $history,
        'skips'      => $skips,                  // backup runs skipped because the lock was busy, newest first
        'skipped'    => backupSkipRow(readJson("$data/state/skipped.json")),   // the last attempt of any mode
        'estimates'  => backupEstimates($history),
        'upload'     => $running ? backupUpload($status, $history, $settings) : null,     // a first upload to Kopia going on now
        'drift'      => backupDrift(),
        'settings'   => backupSettingsSummary($settings),
        'shares'     => backupShares($settings, $history),
        'items'      => backupKopiaItems($settings, $history),
        'vms'        => $vms = backupVms($settings),
        'waiting'    => backupWaiting($settings, $vms),   // new folders, apps and VMs waiting for a decision (engine 2.21)
        'containers' => backupContainers($settings),
        'partners'   => backupPartners($settings, $history, $running ? $status : null),   // engine 2.27: the last transfer per partner
        'dumps'      => backupDumps(),           // run folders of engines before 2.18, until the first 2.18 run cleared them
        'packages'   => backupPackages($settings),
        'schedule'   => backupSchedule(),
        'logs'       => array_values(array_map(fn ($l) => ['name' => $l['name'], 'kind' => $l['kind'], 'time' => $l['time'], 'size' => $l['size']], $logs)),
        'mounted'    => backupMounted(backupSetting($settings, 'general', 'mount_root')),
        'setup'      => $setup,
        'drill'      => backupDrillLine(),       // Mr. Restori's drill: one line from its certificate
    ];
    $state['found'] = true;
    $GLOBALS['backup'] = $state;
    writeAtomic(deskFile('backup'), jsonEncode($state));
    return $state;
}

/**
 * Engine 2.27: the partner offices settings.ini sends to, each with its units and the last run that had it (from the
 * history, newest first: what went, how much, how fast, what was skipped or failed and why) - and, while a run sends
 * to it, what it sends now (status.json partner.current: the unit, since, bytes so far)
 */
function backupPartners(array $settings, array $history, ?array $status = null): array
{
    $out = [];
    $cur = is_array($status['partner']['current'] ?? null) ? $status['partner']['current'] : null;
    foreach (backupPartnersFromSettings($settings) as $p) {
        $last = null;
        foreach ($history as $run) {
            foreach ($run['partner'] ?? [] as $x) {
                if ($x['id'] === $p['id']) {
                    $last = $x + ['run' => $run['run'], 'time' => $run['finished'] ?: $run['started']];
                    break 2;
                }
            }
        }
        $p['last'] = $last;
        $p['current'] = $cur && ($cur['id'] ?? '') === $p['id'] ? ['unit' => (string) ($cur['unit'] ?? ''), 'since' => (int) ($cur['since'] ?? 0),
                                                                    'bytes' => (int) ($cur['bytes'] ?? 0)] : null;
        $out[] = $p;
    }
    return $out;
}

/**
 * Mr. Restori's restore drill as Mr. Backupsy's one line shows it — read from its certificate (data/restore-drill.json,
 * interface 1) only: how the last complete drill went, when, how many items it proved of how many, what failed
 */
function backupDrillLine(?array $cert = null): ?array
{
    $cert ??= readJson(DATA_DIR . '/restore-drill.json');
    $last = ($cert['interface'] ?? 0) === 1 && is_array($cert['last'] ?? null) ? $cert['last'] : null;
    if (!$last || !in_array($last['result'] ?? '', ['passed', 'failed'], true)) {
        return null;
    }
    $failed = [];
    foreach ((array) ($cert['items'] ?? []) as $it) {
        if (is_array($it) && ($it['result'] ?? '') === 'failed' && is_string($it['name'] ?? null)) {
            $failed[$it['name']] = true;
        }
    }
    return ['result' => $last['result'], 'ended' => (int) ($last['ended'] ?? 0), 'proven' => (int) ($last['proven'] ?? 0),
            'total' => (int) ($last['items'] ?? 0), 'failed' => array_slice(array_keys($failed), 0, 4)];
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
    // up: Docker answers (its socket) — stopped, the page says so instead of nothing (1.43)
    return ['up' => bin('docker') !== null && file_exists('/var/run/docker.sock'),
            'total' => count($all), 'running' => count($running), 'kept' => count($kept),
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
        // engine 2.24: an array stop ended the run - the sources it didn't do are skipped (never failed), one maybe interrupted;
        // engine 2.28: those skipped because their pool slept (kopia.skipped_why) are apart - kopia_asleep
        'kopia_skipped' => backupKopiaSkipped($j['kopia'] ?? null, false),
        'kopia_asleep' => backupKopiaSkipped($j['kopia'] ?? null, true),
        // engine 2.28: what the run left out because it slept (asleep_pools = skip; null: not chosen, or an older engine)
        'asleep'     => backupAsleepRun($j['asleep'] ?? null),
        'kopia_interrupted' => is_string($j['kopia']['interrupted'] ?? null) && $j['kopia']['interrupted'] !== '' ? $j['kopia']['interrupted'] : null,
        'kopia_first' => null,
        // engine 2.27: the partner phase per partner (null: the run sent to no partner)
        'partner'    => backupPartnerRun($j['partner'] ?? null),
        'log'        => (string) ($j['log'] ?? ''),
        'version'    => (string) ($j['version'] ?? ''),
        'source'     => 'status',
    ];
}

/**
 * A run's skipped Kopia sources (status.json kopia.skipped): those the array stop skipped (engine 2.24 - no entry in
 * kopia.skipped_why), or with $asleep those skipped because their pool slept (engine 2.28 - why "asleep")
 */
function backupKopiaSkipped(mixed $kopia, bool $asleep): array
{
    if (!is_array($kopia)) {
        return [];
    }
    $why = is_array($kopia['skipped_why'] ?? null) ? $kopia['skipped_why'] : [];
    return array_values(array_filter((array) ($kopia['skipped'] ?? []),
        fn ($n) => is_string($n) && $n !== '' && (($why[$n] ?? null) === 'asleep') === $asleep));
}

/**
 * What a run left out because it slept (engine 2.28, status.json "asleep" - [general] asleep_pools = skip): the pools
 * and disks, the shares with a part there (units: how many), the VMs not held, the containers kept running, the pools
 * woken all the same (the backup place's), per share the nights in a row it was left out, and those at or past
 * BACKUP_ASLEEP_NIGHTS (the engine warned once). Null when the run didn't leave anything out by choice (wake, older).
 * Only names in the shape the engine writes; everything else dropped.
 */
function backupAsleepRun(mixed $a): ?array
{
    if (!is_array($a) || ($a['mode'] ?? '') !== 'skip') {
        return null;
    }
    $names = fn ($l) => array_values(array_filter(is_array($l) ? $l : [], fn ($n) => is_string($n) && preg_match('/^[^\x00-\x1f\/]{1,120}$/D', $n)));
    $nights = [];
    foreach (is_array($a['nights'] ?? null) ? $a['nights'] : [] as $share => $n) {
        $share = (string) $share;            // a share called "2024" comes back from json_decode as an int key
        if (preg_match('/^[^\x00-\x1f\/]{1,120}$/D', $share) && is_int($n) && $n > 0) {
            $nights[$share] = $n;
        }
    }
    $shares = $names($a['shares'] ?? null);
    return [
        'pools'      => $names($a['pools'] ?? null),
        'shares'     => $shares,
        'units'      => count($shares),
        'vms'        => $names($a['vms'] ?? null),
        'containers' => $names($a['containers'] ?? null),
        'woken'      => $names($a['woken'] ?? null),
        'nights'     => $nights,
        'long'       => array_map('strval', array_keys(array_filter($nights, fn ($n) => $n >= BACKUP_ASLEEP_NIGHTS))),
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
          'errors' => 0, 'warnings' => 0, 'downtime' => 0, 'dump_bytes' => 0, 'packages' => null, 'kopia' => [], 'kopia_skipped' => [], 'kopia_asleep' => [], 'asleep' => null,
          'kopia_interrupted' => null, 'kopia_first' => null,
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

// ===================================================================== a first upload to Kopia

/**
 * The Kopia source going up now, when it goes to Kopia for the first time — nothing of it in the
 * repository yet, so all of it goes up once: hours, where other nights took minutes. Earlier runs
 * can't tell how long then; this does: the source's size (the ZFS snapshot Kopia reads,
 * backupSourceSize()), what the Kopia process has read so far (/proc/<pid>/io rchar) and its rate
 * between looks a few minutes apart (the page asks every few seconds while it is open; before a
 * second look: the average since the source started). Checked on a large server, 2026-10-06 (2.36 TB,
 * 9 h): at 13:21 it had read 2.39 TB of 2.41 TB (the files' own sizes; holes of sparse files are
 * read too, so it may run a little past logicalreferenced — then "any moment now"), ~38 MB/s, done
 * at 13:28 as reckoned; wchar (what it sent: 1.18 TB) was no measure — compression and content the
 * repository already had. So the rate is what Kopia reads; what it really sent so far (wchar) is told
 * beside it ('sent'), never used for the estimate. The looks live in RAM ($cache, RUN_DIR). Null while
 * no Kopia source is going up, or the one going up was there before.
 *
 * @return array{source: string, first: true, since: int, size: ?int, read: ?int, sent: ?int, rate: ?int, left: ?int, time: int}|null
 */
function backupUpload(?array $status, array $history, array $settings, ?string $cache = null): ?array
{
    $k = is_array($status['kopia'] ?? null) ? $status['kopia'] : [];
    $source = is_string($k['current'] ?? null) ? $k['current'] : '';
    $since = (int) ($k['current_since'] ?? 0);
    $run = (string) ($status['run'] ?? '');
    if (($status['phase'] ?? '') !== 'kopia' || $source === '' || $since <= 0 || $run === '') {
        return null;
    }
    $cache ??= RUN_DIR . '/backup-upload.json';
    $keep = function (array $c) use ($cache): void {
        try {
            @mkdir(dirname($cache), 0700, true);
            writeAtomic($cache, jsonEncode($c), 0600, 0, 0);
        } catch (Throwable $e) {
            // RAM only - without it the rate is the average since the source started
        }
    };
    $c = readJson($cache) ?? [];
    if (($c['run'] ?? null) !== $run) {
        $c = ['run' => $run, 'repo' => backupKopiaRepoSince($settings)];      // once per run (docker inspect)
        $keep($c);
    }
    if (!backupFirstUpload($source, $history, isset($c['repo']) ? (int) $c['repo'] : null)) {
        return null;
    }
    if (($c['source'] ?? null) !== $source) {
        $c = ['run' => $run, 'repo' => $c['repo'] ?? null, 'source' => $source, 'pid' => null, 'looks' => [],
              'size' => backupSourceSize($source, $settings, (string) ($status['snapshot'] ?? ''))];      // once per source
    }
    [$exact, $suffix] = backupKopiaSourcePath($source, backupDrift()['policies'] ?? null);
    $pid = (int) ($c['pid'] ?? 0);
    if ($pid <= 1 || !backupKopiaIsSnapshot($pid, $exact, $suffix)) {
        $pid = backupKopiaPid($exact, $suffix) ?? 0;
        if ($pid !== (int) ($c['pid'] ?? 0)) {
            $c['looks'] = [];                           // another process: its counter starts anew
        }
        $c['pid'] = $pid > 1 ? $pid : null;
    }
    [$read, $sent] = $pid > 1 ? backupProcIo($pid) : [null, null];
    [$c, $out] = backupUploadStep($c, $since, $read, time(), $sent);
    $keep($c);
    return $out;
}

/**
 * One look at a first upload: what the Kopia process has read now ($read), the looks before ([time,
 * read], oldest first) give the rate — from the oldest look of the last BACKUP_UPLOAD_KEEP that is at
 * least BACKUP_UPLOAD_LOOK old (or the newest older one), else the average since the source started
 * (after a minute). Left = what is not read yet of its size at that rate (0: any moment now). Keeps a
 * look every 20 seconds, one beyond BACKUP_UPLOAD_KEEP. $sent (what it wrote so far: wchar) only goes
 * along to the page.
 *
 * @return array{0: array, 1: array}  the cache to keep, what the page gets
 */
function backupUploadStep(array $c, int $since, ?int $read, int $now, ?int $sent = null): array
{
    $looks = array_values(array_filter((array) ($c['looks'] ?? []), fn ($l) => is_array($l) && count($l) === 2 && $l[0] < $now));
    $rate = null;
    if ($read !== null) {
        $within = $beyond = null;
        foreach ($looks as $l) {                                        // oldest first
            if ($now - $l[0] >= BACKUP_UPLOAD_LOOK && $l[1] <= $read) {
                if ($now - $l[0] <= BACKUP_UPLOAD_KEEP) {
                    $within ??= $l;                                     // the oldest within the window
                } else {
                    $beyond = $l;                                       // the newest one before it
                }
            }
        }
        $base = $within ?? $beyond;
        if ($base !== null) {
            $rate = ($read - $base[1]) / ($now - $base[0]);
        } elseif ($now - $since >= 60) {
            $rate = $read / ($now - $since);
        }
        if (!$looks || $now - end($looks)[0] >= 20) {
            $looks[] = [$now, $read];
        }
        $old = array_keys(array_filter($looks, fn ($l) => $now - $l[0] > BACKUP_UPLOAD_KEEP));
        if (count($old) > 1) {
            $looks = array_values(array_slice($looks, (int) end($old)));     // one look beyond the window is enough
        }
    }
    $c['looks'] = $looks;
    $size = isset($c['size']) ? (int) $c['size'] : null;
    $rate = $rate !== null && $rate > 0 ? (int) round($rate) : null;
    $left = $size !== null && $read !== null && $rate !== null ? (int) ceil(max(0, $size - $read) / $rate) : null;
    return [$c, ['source' => (string) ($c['source'] ?? ''), 'first' => true, 'since' => $since, 'size' => $size, 'read' => $read,
                 'sent' => $sent, 'rate' => $rate, 'left' => $left, 'time' => $now]];
}

/**
 * Has this Kopia source no snapshot in the repository yet? No earlier run copied it — counting only
 * runs since Kopia connected to the repository it uses now ($repoSince, backupKopiaRepoSince(); null
 * = unknown, every run counts): a new repository (another bucket) starts every source anew.
 */
function backupFirstUpload(string $source, array $history, ?int $repoSince): bool
{
    foreach ($history as $run) {
        if ($repoSince !== null && (int) ($run['started'] ?? 0) < $repoSince) {
            continue;
        }
        foreach ($run['kopia'] ?? [] as $k) {
            if (($k['name'] ?? null) === $source && !empty($k['ok'])) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Since when the Kopia container uses its repository: the time of its repository.config
 * (KOPIA_CONFIG_PATH, else ~/.config/kopia/repository.config — written when Kopia connects to or
 * creates a repository, never by a snapshot), on the host through the container's mappings. Only
 * those two variables are read of its environment (the rest may hold keys). Null when it can't be
 * told (no container, the file not mapped, its disk asleep).
 */
function backupKopiaRepoSince(array $settings): ?int
{
    $name = (string) backupSetting($settings, 'kopia', 'container', '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)) {
        return null;
    }
    $i = houseInspect($name);
    if (!$i) {
        return null;
    }
    $env = [];
    foreach ((array) ($i['Config']['Env'] ?? []) as $e) {
        if (is_string($e) && preg_match('/^(KOPIA_CONFIG_PATH|HOME)=(\/[^\x00-\x1f]*)$/D', $e, $m)) {
            $env[$m[1]] = $m[2];
        }
    }
    $config = $env['KOPIA_CONFIG_PATH'] ?? rtrim($env['HOME'] ?? '/root', '/') . '/.config/kopia/repository.config';
    $host = backupContainerHostPath((array) ($i['Mounts'] ?? []), $config);
    if ($host === null || backupPathAsleep($host, $settings, sleepingDisks())) {
        return null;
    }
    clearstatcache(true, $host);
    return @filemtime($host) ?: null;
}

/** A path inside a container on the host, through its mappings (the longest that holds it), or null */
function backupContainerHostPath(array $mounts, string $path): ?string
{
    if (!str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        return null;
    }
    $best = null;
    foreach ($mounts as $m) {
        $dst = rtrim((string) ($m['Destination'] ?? ''), '/');
        $src = rtrim((string) ($m['Source'] ?? ''), '/');
        if ($src === '' || !str_starts_with((string) ($m['Source'] ?? ''), '/') || !($dst === '' || under($path, $dst))) {
            continue;
        }
        if ($best === null || strlen($dst) > strlen($best[0])) {
            $best = [$dst, $src];
        }
    }
    return $best === null ? null : $best[1] . substr($path, strlen($best[0]));
}

/**
 * Where Kopia reads a source, in the container: the path the last check compared the policy at
 * (drift.json), and the end every such path has (<root>/<share>, /.apps/<app>, /.vms/<vm>, /_flash)
 * @return array{0: ?string, 1: string}
 */
function backupKopiaSourcePath(string $source, ?array $policies): array
{
    [$kind, $name] = preg_match('/^(app|vm):(.+)$/D', $source, $m) ? [$m[1], $m[2]] : ($source === 'flash' ? ['flash', ''] : ['share', $source]);
    $suffix = match ($kind) { 'app' => "/.apps/$name", 'vm' => "/.vms/$name", 'flash' => '/_flash', default => "/$name" };
    foreach ($policies ?? [] as $p) {
        if (($p['kind'] ?? '') === $kind && ($kind === 'flash' || ($p['name'] ?? '') === $name) && str_ends_with((string) ($p['path'] ?? ''), $suffix)) {
            return [(string) $p['path'], $suffix];
        }
    }
    return [null, $suffix];
}

/** Is $pid a "kopia … snapshot create <the source>" (the engine's docker exec, as the host sees it)? */
function backupKopiaIsSnapshot(int $pid, ?string $exact, string $suffix, string $proc = '/proc'): bool
{
    $argv = explode("\0", rtrim((string) @file_get_contents("$proc/$pid/cmdline", false, null, 0, 8192), "\0"));
    if (basename($argv[0]) !== 'kopia' || !in_array('snapshot', $argv, true) || !in_array('create', $argv, true)) {
        return false;
    }
    foreach (array_slice($argv, 1) as $a) {
        if ($exact !== null ? $a === $exact : str_ends_with($a, $suffix)) {
            return true;
        }
    }
    return false;
}

/** The Kopia process snapshotting the source now, or null */
function backupKopiaPid(?string $exact, string $suffix, string $proc = '/proc'): ?int
{
    foreach (@scandir($proc) ?: [] as $d) {
        if (ctype_digit($d) && (int) $d > 1 && backupKopiaIsSnapshot((int) $d, $exact, $suffix, $proc)) {
            return (int) $d;
        }
    }
    return null;
}

/** What a process has read so far (rchar of /proc/<pid>/io: files, plus a little from sockets and its cache) */
function backupProcRead(int $pid, string $proc = '/proc'): ?int
{
    return backupProcIo($pid, $proc)[0];
}

/**
 * What a process has read and written so far, in one look at /proc/<pid>/io: [rchar, wchar], each null
 * when not there. For a Kopia upload wchar is what it sent (to the repository, plus a little cache and
 * log) — about half of what it read on a large server (compression, content the repository already had).
 *
 * @return array{0: ?int, 1: ?int}
 */
function backupProcIo(int $pid, string $proc = '/proc'): array
{
    $io = (string) @file_get_contents("$proc/$pid/io");
    $get = fn (string $k): ?int => preg_match('/^' . $k . ':\s*(\d+)$/m', $io, $m) ? (int) $m[1] : null;
    return [$get('rchar'), $get('wchar')];
}

/**
 * How big a source is in the snapshot Kopia reads: logicalreferenced of every dataset of this run's
 * ZFS snapshot under its share (an app's folders: under each folder that is a dataset), on each place
 * settings.ini names for the share. A VM's own source goes by the apparent size of the files in its
 * folders instead (backupApparentSize(): one stat per file, never a read) — Kopia reads a sparse vdisk
 * whole, its holes as zeros, so a 1.6 TB vdisk holding 21 GB is 1.6 TB of reading, while its snapshot's
 * logicalreferenced says 21 GB (a Windows VM with a 1.6 TB sparse disk, 2026-10-07: 382 GB by the snapshot, 2 TB
 * read, 2.7 h — the estimate was off by that). Its package (XML, NVRAM, TPM: a few MB) isn't counted.
 * Null when it can't be told (a place that isn't ZFS or sleeps, a folder that is no dataset, the flash).
 * $mnt: where the places are mounted (/mnt/<place>/<share>; tests pass a folder of their own).
 */
function backupSourceSize(string $source, array $settings, string $snap, string $mnt = '/mnt'): ?int
{
    if (!preg_match('/^[A-Za-z0-9_.:-]{1,200}$/D', $snap) || $source === 'flash') {
        return null;
    }
    $parts = [];
    $vm = false;
    if (preg_match('/^(app|vm):(.+)$/D', $source, $m)) {
        $vm = $m[1] === 'vm';
        foreach ((array) ($settings["$m[1]|$m[2]"]['folder'] ?? []) as $f) {
            $parts[] = explode('/', (string) $f, 2) + [1 => ''];
        }
    } else {
        $parts[] = [$source, ''];
    }
    $asleep = sleepingDisks();
    $total = 0;
    foreach ($parts as [$share, $sub]) {
        $places = array_filter(array_map('trim', explode(',', (string) backupSetting($settings, "share|$share", 'locations', ''))));
        if (!$places) {
            return null;
        }
        foreach ($places as $place) {
            $ds = "$place/$share" . ($sub !== '' ? '/' . trim($sub, '/') : '');
            if (baseAsleep($place, $asleep) || !preg_match('#^[A-Za-z0-9][\w .:/-]{0,250}$#D', $ds) || str_contains($ds, '//')) {
                return null;
            }
            if ($vm) {
                $n = backupApparentSize("$mnt/$ds");
            } else {
                [$exit, $out] = run(['zfs', 'list', '-Hp', '-t', 'snapshot', '-r', '-o', 'name,logicalreferenced', $ds], 30);
                $n = $exit === 0 ? backupZfsSnapSum($out, $snap) : null;
            }
            if ($n === null) {
                return null;
            }
            $total += $n;
        }
    }
    return $parts ? $total : null;
}

/**
 * The apparent size of every regular file under a folder (st_size — a sparse file's full size, what
 * Kopia reads), one lstat per entry, links not followed, at most $max entries (more: null — a VM's
 * folder holds a few disk images, anything bigger isn't one). Null when the folder isn't there. A
 * missing folder, a dataset's hidden .zfs (never listed by readdir) and a sleeping place are the
 * caller's to think of.
 */
function backupApparentSize(string $dir, int $max = 5000): ?int
{
    if (!is_dir($dir) || is_link($dir)) {
        return null;
    }
    $total = 0;
    $seen = 0;
    $queue = [$dir];
    while ($queue) {
        $d = array_shift($queue);
        $h = @opendir($d);
        if ($h === false) {
            return null;
        }
        while (($e = readdir($h)) !== false) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            if (++$seen > $max) {
                closedir($h);
                return null;
            }
            $p = "$d/$e";
            $st = @lstat($p);
            if ($st === false || ($st['mode'] & 0170000) === 0120000) {       // gone meanwhile, or a link
                continue;
            }
            if (($st['mode'] & 0170000) === 0040000) {
                $queue[] = $p;
            } elseif (($st['mode'] & 0170000) === 0100000) {
                $total += (int) $st['size'];
            }
        }
        closedir($h);
    }
    return $total;
}

/** The sum of a size column of "zfs list -Hp -o name,<size>" for the snapshots named @$snap, null when there is none */
function backupZfsSnapSum(string $out, string $snap): ?int
{
    $sum = null;
    foreach (explode("\n", $out) as $line) {
        $f = explode("\t", $line);
        if (count($f) === 2 && str_ends_with($f[0], "@$snap") && ctype_digit($f[1])) {
            $sum = ($sum ?? 0) + (int) $f[1];
        }
    }
    return $sum;
}

// ===================================================================== more state

/**
 * What is new and waits for the user's decision — only local and kept running so far (engine 2.21):
 *   folders  the engine's state/new-local.json (the last run that reached Kopia), without those decided since:
 *            in kopia_known or kopia_ignore of their share now, or the share no longer goes to Kopia; and (engine
 *            2.37) only in the app/VM shares - a data share's folder goes with its share (an engine 2.36's file
 *            may still name one until the next run)
 *   apps     containers not in [docker] known, grouped like the setup (a compose project or a single container);
 *            an app counts when none of its containers is known
 *   vms      VMs without a [vm "<name>"] section
 * Nothing before the first setup (no settings.ini) — then everything is still to be set up anyway.
 * $containers (name => compose project), $file and $appShares for the tests; otherwise docker ps, the engine's state
 * and backupAppShares().
 */
function backupWaiting(array $s, array $vms, ?array $containers = null, ?string $file = null, ?array $appShares = null, ?callable $there = null): array
{
    $out = ['folders' => [], 'apps' => [], 'vms' => []];
    // a folder the last run noted that is gone by now waits for nothing (2026-10-10: the callout stayed until the next
    // run and «Decide…» found nothing to decide); looked at only where the share's disks are awake - never woken for it
    $there ??= function (string $share, string $folder) use ($s): bool {
        static $asleep = null;
        $asleep ??= sleepingDisks();
        return backupShareAsleep($share, $s, $asleep) || file_exists("/mnt/user/$share/$folder");
    };
    if (!$s) {
        return $out;
    }
    $kopiaOn = in_array(strtolower((string) backupSetting($s, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    $j = readJson($file ?? BACKUP_DATA_DIR . '/state/new-local.json');
    $appShares ??= backupAppShares();
    foreach (is_array($j['folders'] ?? null) ? $j['folders'] : [] as $f) {
        $share = is_array($f) && is_string($f['share'] ?? null) ? $f['share'] : '';
        $folder = is_array($f) && is_string($f['folder'] ?? null) ? $f['folder'] : '';
        if ($share === '' || $folder === '' || preg_match('/[\x00-\x1f\/]/', $folder) || !$kopiaOn || !isset($appShares[$share])
            || backupSetting($s, "share|$share", 'mode') !== 'kopia' || !isset($s["share|$share"]['kopia_known'])
            || in_array('*', $s["share|$share"]['kopia_known'], true)) {
            continue;
        }
        $rule = '/' . $folder . '/';
        $escaped = '/' . preg_replace('/[*?\[\]\\\\]/', '?', $folder) . '/';
        if (in_array($rule, $s["share|$share"]['kopia_known'], true)
            || array_intersect([$rule, $escaped, rtrim($rule, '/'), rtrim($escaped, '/')], $s["share|$share"]['kopia_ignore'] ?? [])) {
            continue;
        }
        if (!$there($share, $folder)) {
            continue;
        }
        $out['folders'][] = ['share' => $share, 'folder' => $folder, 'bytes' => is_int($f['bytes'] ?? null) ? $f['bytes'] : null,
                             'first_seen' => is_int($f['first_seen'] ?? null) ? $f['first_seen'] : null];
    }
    $known = $s['docker']['known'] ?? [];
    if ($known) {
        if ($containers === null) {
            $containers = [];
            [$exit, $outp] = run(['docker', 'ps', '-a', '--format', '{{.Names}}' . "\t" . '{{.Label "com.docker.compose.project"}}'], 20);
            foreach ($exit === 0 ? rows($outp) : [] as $r) {
                if (($r[0] ?? '') !== '') {
                    $containers[$r[0]] = (string) ($r[1] ?? '');
                }
            }
        }
        $kopia = (string) backupSetting($s, 'kopia', 'container', '');
        $apps = [];
        foreach ($containers as $name => $project) {
            if ($name === $kopia) {
                continue;
            }
            $app = $project !== '' ? $project : $name;
            $apps[$app] = ($apps[$app] ?? true) && !in_array($name, $known, true);
        }
        $out['apps'] = array_keys(array_filter($apps));
        natcasesort($out['apps']);
        $out['apps'] = array_values($out['apps']);
    }
    foreach ($vms as $v) {
        if (empty($v['configured'])) {
            $out['vms'][] = (string) $v['name'];
        }
    }
    return $out;
}

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

/** The nightly run's schedule: a line in the plugin's cron file, to be set once the setup is done */
function backupSchedule(): array
{
    return ['script' => is_file(BACKUP_DATA_DIR . '/settings.ini')] + officeJobSchedule('backup');
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
    $vms = [];
    foreach (@file("$data/state/vms", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $vm = explode('|', $l, 2)[0];
        if (($l[strlen($vm)] ?? '') === '|' && preg_match('/^[\w .@()-]{1,128}$/D', $vm)) {
            $vms[$vm] = true;
        }
    }
    return [
        'vms'               => array_keys($vms),      // VMs the run froze, paused or shut down (name|how)
        'stopped'           => $stopped,
        'stopped_since'     => $stopped ? (@filemtime("$data/state/stopped") ?: null) : null,
        'maintenance'       => $maint,
        'maintenance_since' => $maint ? (@filemtime("$data/state/maintenance") ?: null) : null,
    ];
}

/** Sets the nightly run (cron) or switches it off (null / '') — the plugin's cron file */
function backupSetSchedule(?string $cron): array
{
    if ($cron !== null && !is_file(BACKUP_DATA_DIR . '/settings.ini')) {
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
    // always say where its data lies: the folder the office reads, as the user set it (the engine would find the
    // plugin's DATA_DIR itself — the same path, never the pool path the agent may use)
    hostLaunch('backup-job', array_merge(['/bin/bash'], $args), ['UB_DATA' => BACKUP_DATA_DIR_USER] + $env);
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
    if (($wait = backupMinuteTaken($mode, $data)) !== null) {
        throw new Problem('one_run_a_minute', ['seconds' => $wait]);
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

/**
 * One run a minute (QA 2026-10-08, finding 3): the engine's run id, its log and its snapshots' name carry the minute
 * (`uso-backup-YYYYMMDD-HHMM`) — a second run in the same minute failed with «ZFS snapshot … failed» and left two
 * history lines with one id. Taken: the last run (status.json, any mode) has the id the engine would take now, or the
 * log this mode would write is there (backup.sh refuses then too, engine 2.34). The seconds until the next minute, or null.
 */
function backupMinuteTaken(string $mode, string $data, ?int $now = null): ?int
{
    $now ??= time();
    $id = date('Ymd-Hi', $now);
    $log = ['dryrun' => 'dryrun', 'check' => 'check'][$mode] ?? 'run';
    if ((readJson("$data/state/status.json")['run'] ?? null) === $id || is_file("$data/logs/$log-$id.log")) {
        return 60 - (int) date('s', $now);
    }
    return null;
}

/** Where the agent notes an abort it sent (RAM): {pid, at} */
function backupAbortFile(): string
{
    return RUN_DIR . '/backup-abort.json';
}

/** When the running backup.sh was asked to stop, or null (none asked, or that run is over) */
function backupAbortAsked(bool $running, ?array $status): ?int
{
    $a = readJson(backupAbortFile());
    if (!$running || !is_array($a) || (int) ($a['pid'] ?? 0) <= 0 || (int) ($a['pid'] ?? 0) !== (int) ($status['pid'] ?? -1)) {
        return null;
    }
    return (int) ($a['at'] ?? 0) ?: null;
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
    // the abort said on the page until the run ends — the engine's trap runs only once its current command returns
    // (a «docker stop -t 60» takes its minute; 2026-10-10: «hängt es ohne Welle»); in RAM, for this PID only
    writeAtomic(backupAbortFile(), jsonEncode(['pid' => $pid, 'at' => time()]), 0600);
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

    $out[] = finding('setup', 'required', is_file("$data/settings.ini"), ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    $schedule = backupSchedule();
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
            $auto = backupKopiaAutostart($summary, $name, $inspect);
            if ($auto !== null) {
                $out[] = $auto;
            }
        }
        if ($kopiaOn) {
            // the engine finds out whether the repository answers; we read its last word
            // only "yes" and "no" are a word about the repository: a run ended before its check (engine 2.24 says "" then -
            // an array stop at its start; older engines said "no"), a --no-kopia run ("skip") says nothing
            $status = readJson("$data/state/status.json");
            $state = $status['kopia']['state'] ?? null;
            $out[] = finding('kopia_repo', 'required', in_array($state, ['yes', 'no'], true) ? $state === 'yes' : null, ['name' => $name]);
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
    }

    return $out;
}

/**
 * Whether the Kopia container comes back by itself after a reboot or an array stop: Unraid stops every container
 * at the array stop and, when Docker comes up again, starts only what is on its autostart list — a Kopia left off
 * makes the next run skip the whole offsite part, and `kopia_running` only notices it afterwards (a large server,
 * 2026-10-07). A template container: a line in Unraid's autostart file, or Docker's own restart policy "always"
 * (Docker starts those itself when it comes up; "unless-stopped" doesn't — Unraid stopped it); a Compose stack's
 * container: Compose Manager's autostart of that stack. A must like `kopia_running`: without it the offsite backup
 * ends silently at the next reboot. The office only warns — it never starts Kopia itself (decided so).
 *
 * @param array $summary  backupSettingsSummary(): only while Kopia is on, and only for the container the settings name
 * @param array $inspect  docker inspect of that container
 * @return ?array  the finding, or null: Kopia off, another container, or a stack Compose Manager doesn't know
 *                 (nothing the office knows starts it — then nothing is said)
 */
function backupKopiaAutostart(array $summary, string $name, array $inspect, string $autostartFile = HOUSE_AUTOSTART, ?string $composeRoot = null): ?array
{
    if (empty($summary['kopia_enabled']) || $name !== ($summary['kopia_container'] ?? null)) {
        return null;
    }
    if (($inspect['HostConfig']['RestartPolicy']['Name'] ?? '') === 'always') {
        $auto = true;
    } else {
        $project = $inspect['Config']['Labels']['com.docker.compose.project'] ?? null;
        $auto = is_string($project) && $project !== ''
            ? houseComposeAutostart($project, $composeRoot)
            : isset(houseAutostart($autostartFile)[$name]);
    }
    return $auto === null ? null : finding('kopia_autostart', 'required', $auto, ['name' => $name], 'docker');
}

// ===================================================================== setup (setup.sh --plan / --apply)

const BACKUP_SETUP_KEYS = ['mode', 'retention', 'kopia_retention', 'method', 'kopia_ignore', 'kopia_known', 'exclude_dataset'];
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

/** The last plan plus progress and messages of the last plan/apply — planned anew once after an engine update */
function backupSetupGet(): array
{
    $data = BACKUP_DATA_DIR;
    $plan = readJson("$data/state/setup-plan.json");
    $state = $GLOBALS['backup'] ?? backupScan();
    $noteFile = "$data/state/" . BACKUP_REPLAN_FILE;
    $note = readJson($noteFile);
    $holder = backupLockHolder();
    $replan = backupSetupReplan($plan, $state, $note, $holder === null && !backupSetupStatus()['running'], time());
    if ($replan['note'] !== $note && $replan['note'] !== null) {
        try {
            writeAtomic($noteFile, jsonEncode($replan['note']), 0600, 0, 0);
        } catch (Throwable $e) {
            logLine('Backup: could not note the plan made anew after the engine update: ' . $e->getMessage());
            $replan['start'] = false;       // without the note it could start again at every look: not at all then
        }
    }
    if ($replan['start']) {
        try {
            backupLaunch([BACKUP_SCRIPT_DIR . '/setup.sh', '--plan'], ['UB_SIZE_TIMEOUT' => 0]);
            logLine("Backup: the plan was made by engine {$replan['note']['from']}, this is {$replan['note']['to']} — setup.sh --plan started via at (once)");
            backupSetupWait();
        } catch (Throwable $e) {
            logLine('Backup: the plan anew after the engine update could not start: ' . $e->getMessage() . ' (not again for this engine)');
            $replan['plan'] = $plan;        // the old one then, as before
        }
    }
    $plan = $replan['plan'];
    if (is_array($plan['shares'] ?? null)) {
        $asleep = sleepingDisks();
        $place = backupPlaceFacts($plan['shares']);
        foreach ($plan['shares'] as &$share) {
            $share += backupShareTop($share, $asleep);
            $share['place'] = $place[(string) ($share['name'] ?? '')] ?? [];
        }
        unset($share);
    }
    $run = readJson("$data/state/setup-status.json");
    if (is_array($plan)) {
        $plan['notes'] = backupSetupNotes($plan['messages'] ?? null);   // the messages grouped, for the page
    }
    if (is_array($run)) {
        $run['notes'] = backupSetupNotes($run['messages'] ?? null);
    }
    return [
        'ok'     => true,
        'status' => backupSetupStatus(),
        'run'    => $run,
        'plan'   => $plan,
    ];
}

/**
 * The setup's messages (a plan's or the last apply's) as the page shows them — `notes` beside `messages`. Engine 2.39
 * gives every hint and warning a `code` and `params` (2.34: the «not agreed» hints); the page says a code in its own
 * words (`setup.msg.<code>`, desk.js `setupNoteItems()`). Messages of the same level and code make ONE entry with an
 * item each (three apps with Docker volumes → one entry listing them), in the order they came; a message with the code
 * `more` is the second line of the one before it (kept as that item's `more`, shown only where the page falls back to
 * the English). Docker volumes are judged here (`backupVolumeItems()`): cache or real data. A message without a code
 * (ok and error lines, an older engine's plan, a code of an unknown shape) stays an entry of its own with its text —
 * never lost. Pure.
 *
 * @return list<array{level: string, step: string, code?: string, items: list<array{text: string, params?: array, more?: list<string>}>}>
 */
function backupSetupNotes(mixed $messages): array
{
    if (!is_array($messages)) {
        return [];
    }
    $str = fn ($v) => is_scalar($v) ? (string) $v : '';
    $out = [];
    $at = [];           // "<level>|<code>" → its entry
    $last = null;       // [entry, item] the next `more` line belongs to
    foreach ($messages as $m) {
        if (!is_array($m)) {
            continue;
        }
        $level = $str($m['level'] ?? '') ?: 'info';
        $step = $str($m['step'] ?? '');
        $text = $str($m['text'] ?? '');
        $code = $str($m['code'] ?? '');
        if ($code === 'more' && $last !== null) {
            $out[$last[0]]['items'][$last[1]]['more'][] = trim($text);
            continue;
        }
        if ($code === '' || $code === 'more' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code)) {
            $out[] = ['level' => $level, 'step' => $step, 'items' => [['text' => $text]]];
            $last = [array_key_last($out), 0];
            continue;
        }
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        $items = $code === 'docker_volumes' ? backupVolumeItems($params, $text)
            : [[$code, ['text' => $text, 'params' => backupNoteParams($params)]]];
        foreach ($items as [$c, $item]) {
            $k = "$level|$c";
            if (!isset($at[$k])) {
                $out[] = ['level' => $level, 'step' => $step, 'code' => $c, 'items' => []];
                $at[$k] = array_key_last($out);
            }
            $out[$at[$k]]['items'][] = $item;
            $last = [$at[$k], array_key_last($out[$at[$k]]['items'])];
        }
    }
    return $out;
}

/** A coded message's params as the page fills them in: names of a placeholder's shape, values plain text (≤ 500 characters) */
function backupNoteParams(array $params): array
{
    $out = [];
    foreach ($params as $k => $v) {
        if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $k) && (is_scalar($v) || $v === null)) {
            $out[$k] = mb_substr((string) $v, 0, 500);
        }
    }
    return $out;
}

/**
 * The Docker volumes warning (engine 2.39: `docker_volumes` {app, volumes: [{name, target}]}) as items of
 * `volumes_cache` (only caches that fill themselves again — `backupVolumeIsCache()` — harmless) and `volumes_data`
 * (real data, not in the backup: bind-mount it into appdata); an app with both gets one of each. `paths` lists the
 * paths in the container, a named volume's name beside its path; `n` how many. Without a usable list (another shape):
 * the message as it came, under its own code — the page then shows the engine's text.
 *
 * @return list<array{0: string, 1: array}>
 */
function backupVolumeItems(array $params, string $text): array
{
    $app = is_scalar($params['app'] ?? null) ? mb_substr((string) $params['app'], 0, 200) : '';
    $kinds = ['volumes_data' => [], 'volumes_cache' => []];
    foreach (is_array($params['volumes'] ?? null) ? $params['volumes'] : [] as $v) {
        if (!is_array($v)) {
            continue;
        }
        $name = is_scalar($v['name'] ?? null) ? mb_substr((string) $v['name'], 0, 200) : '';
        $target = is_scalar($v['target'] ?? null) ? mb_substr((string) $v['target'], 0, 300) : '';
        if ($name === '' && $target === '') {
            continue;
        }
        $label = $target === '' ? $name : ($name === '' ? $target : "$target ($name)");
        $kinds[backupVolumeIsCache($target, $name) ? 'volumes_cache' : 'volumes_data'][] = $label;
    }
    $items = [];
    foreach ($kinds as $code => $labels) {
        if ($labels) {
            $items[] = [$code, ['text' => $text, 'params' => ['app' => $app, 'paths' => implode(', ', $labels), 'n' => count($labels)]]];
        }
    }
    return $items ?: [['docker_volumes', ['text' => $text, 'params' => backupNoteParams($params)]]];
}

/**
 * Whether a Docker volume looks like a cache that fills itself again (downloaded models, thumbnails, temporary files):
 * a part of its path in the container is cache/caches/.cache/tmp/temp/transcode(s) (`/cache`, `/root/.cache/huggingface`,
 * `/var/tmp`), or its name has cache/tmp/temp as a word of its own (`model-cache`). Everything else counts as real data —
 * when in doubt the page says «data» and how to keep it.
 */
function backupVolumeIsCache(string $target, string $name): bool
{
    foreach (explode('/', strtolower($target)) as $part) {
        if (preg_match('/^\.?(cache|caches|tmp|temp|transcode|transcodes|transcoding)$/D', $part)) {
            return true;
        }
    }
    return $name !== '' && (bool) preg_match('/(^|[-_.])(cache|caches|tmp|temp)([-_.]|$)/D', strtolower($name));
}

const BACKUP_REPLAN_FILE = 'setup-replan.json';     // in the engine's state/: the office's note of its plan anew after an update

/**
 * After an engine update the setup's plan is the old engine's (2.33's plan lacks 2.34's place rows, …): it is made anew
 * on the next look (upgrade audit proposal 5) — when the plan's `version` (setup.sh writes the engine's version into
 * every plan) isn't the running engine's, the engine is compatible and nothing holds its lock. Once per engine version,
 * whatever comes of it: the note `state/setup-replan.json` {from, to, at} is written before setup.sh starts, and a note
 * for this engine (`to`) means «done» — a plan that fails or never starts is not tried again at every look (the page's
 * «Look at the server again» stays the user's), only after the next engine update. While it runs the page gets no plan
 * (the old one's shape may not be the new page's): it shows the setup as planning. The plan it made — the first of this
 * engine's after the note — carries a message `replanned` (setup.msg.replanned, «planned anew after the update») in its
 * list; the note then remembers that plan's `time`, so a later plan says nothing of it.
 * Pure: $plan as read, $state the desk's (version, compatible, found, running), $free = nothing holds the lock.
 *
 * @return array{plan: ?array, start: bool, note: ?array}  the plan to answer, start setup.sh --plan, the note to keep
 */
function backupSetupReplan(?array $plan, array $state, ?array $note, bool $free, int $now): array
{
    $engine = is_string($state['version'] ?? null) ? $state['version'] : '';
    $made = is_string($plan['version'] ?? null) ? $plan['version'] : '';      // a plan without one: older than all
    $noted = is_array($note) && is_string($note['to'] ?? null) && $note['to'] === $engine;
    if (!$plan || $engine === '') {
        return ['plan' => $plan, 'start' => false, 'note' => $note];
    }
    if ($made !== $engine) {
        if ($noted || empty($state['found']) || empty($state['compatible']) || !empty($state['running']) || !$free) {
            return ['plan' => $plan, 'start' => false, 'note' => $note];        // done once already, or not now
        }
        return ['plan' => null, 'start' => true, 'note' => ['from' => $made !== '' ? $made : '?', 'to' => $engine, 'at' => $now]];
    }
    // this engine's plan: the first one after the note is the plan made anew — said in its messages
    if (!$noted) {
        return ['plan' => $plan, 'start' => false, 'note' => $note];
    }
    $time = (int) ($plan['time'] ?? 0);
    if (!isset($note['plan']) && $time >= (int) ($note['at'] ?? 0) - 5) {
        $note['plan'] = $time;
    }
    if (($note['plan'] ?? null) === $time) {
        $plan['messages'] = array_merge(is_array($plan['messages'] ?? null) ? $plan['messages'] : [], [[
            'level' => 'info', 'step' => 'plan', 'code' => 'replanned', 'params' => ['from' => (string) $note['from'], 'to' => $engine],
            'text' => "Planned anew after the update (engine {$note['from']} → $engine)",
        ]]);
    }
    return ['plan' => $plan, 'start' => false, 'note' => $note];
}

/**
 * What speaks against each share as the backup place — the five points, warnings only: the office never
 * blocks or changes the user's choice (the setup's step 0 shows them for the share chosen):
 *   same_pool      on a pool appdata lies on too: a failing pool takes the apps and their dumps together
 *   no_history     a part without snapshots (no ZFS/btrfs, or the plan found it "live"): the packages keep
 *                  only the last state, no earlier nights
 *   secondary      a secondary storage is set: the mover moves the packages between drives
 *   no_redundancy  (info) a pool without mirror/raidz/raid1…, or the array without parity
 * `where` names the pool or disk ('' = the array). From the shares' cfg on the flash, Unraid's disks.ini
 * and where the plan found each share's folders — never from the disks themselves. Paths for the tests.
 *
 * @return array<string, list<array{code: string, where: string}>>  share name => its warnings
 */
function backupPlaceFacts(array $shares, string $cfgDir = '/boot/config/shares', string $disksIni = '/var/local/emhttp/disks.ini'): array
{
    $disks = readCfg($disksIni, true);
    $bases = [];
    $cfgs = [];
    foreach ($shares as $sh) {
        $name = (string) ($sh['name'] ?? '');
        if (!preg_match('/^[\w .-]+$/D', $name) || $name[0] === '.') {
            continue;
        }
        $cfgs[$name] = readCfg("$cfgDir/$name.cfg");
        $bases[$name] = backupPlaceBases((string) ($sh['locations'] ?? ''), $cfgs[$name], $disks);
    }
    $appdata = null;
    foreach (array_keys($bases) as $name) {
        if (strtolower((string) $name) === 'appdata') {
            $appdata = $bases[$name];
        }
    }
    $parity = false;
    foreach ($disks as $d) {
        $parity = $parity || (($d['type'] ?? '') === 'Parity' && ($d['device'] ?? '') !== '');
    }
    $fs = fn (string $base) => preg_replace('/^luks:/', '', strtolower((string) ($disks[$base]['fsType'] ?? '')));
    $out = [];
    foreach ($shares as $sh) {
        $name = (string) ($sh['name'] ?? '');
        if (!isset($bases[$name])) {
            continue;
        }
        $b = $bases[$name];
        $w = [];
        $same = $appdata !== null && strtolower($name) !== 'appdata' ? array_values(array_intersect($b['pools'], $appdata['pools'])) : [];
        if ($same) {
            $w[] = ['code' => 'same_pool', 'where' => $same[0]];
        }
        $flat = array_values(array_filter([...$b['pools'], ...$b['disks']], fn ($x) => !in_array($fs($x), ['', 'zfs', 'btrfs'], true)));
        if ($flat || ($sh['method'] ?? '') === 'live') {
            $w[] = ['code' => 'no_history', 'where' => $flat[0] ?? ''];
        }
        $use = strtolower((string) ($cfgs[$name]['shareUseCache'] ?? ''));
        if ($use === 'yes' || $use === 'prefer') {
            $w[] = ['code' => 'secondary', 'where' => (string) ($cfgs[$name]['shareCachePool2'] ?? '')];
        }
        $single = array_values(array_filter($b['pools'], fn ($p) => isset($disks[$p])
            && !preg_match('/^(mirror|raidz|draid|raid1|raid5|raid6)/i', (string) ($disks[$p]['fsProfile'] ?? ''))));
        if ($single) {
            $w[] = ['code' => 'no_redundancy', 'where' => $single[0]];
        } elseif ($b['array'] && !$parity && $disks) {
            $w[] = ['code' => 'no_redundancy', 'where' => ''];
        }
        $out[$name] = $w;
    }
    return $out;
}

/**
 * Where a share lies or may put files: pools and array disks where the plan found its folders, plus what
 * its cfg allows (primary and secondary storage; the array's disks by shareInclude/shareExclude).
 *
 * @return array{pools: list<string>, disks: list<string>, array: bool}
 */
function backupPlaceBases(string $locations, array $cfg, array $disks): array
{
    $pools = [];
    $onDisks = [];
    $array = false;
    foreach (array_map('trim', explode(',', $locations)) as $base) {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $base)) {
            continue;                                    // "-": no folder anywhere yet
        }
        if (preg_match('/^disk\d+$/D', $base)) {
            $onDisks[] = $base;
            $array = true;
        } else {
            $pools[] = $base;
        }
    }
    $use = strtolower((string) ($cfg['shareUseCache'] ?? ''));
    $primary = (string) ($cfg['shareCachePool'] ?? '');
    $secondary = (string) ($cfg['shareCachePool2'] ?? '');
    if ($use === 'no') {
        $array = true;
    } elseif ($use === 'only' && $primary !== '') {
        $pools[] = $primary;
    } elseif ($use === 'yes' || $use === 'prefer') {
        if ($primary !== '') {
            $pools[] = $primary;
        }
        if ($secondary !== '') {
            $pools[] = $secondary;
        } else {
            $array = true;
        }
    }
    if ($array && $cfg) {
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $exclude = array_filter(array_map('trim', explode(',', (string) ($cfg['shareExclude'] ?? ''))));
        foreach ($disks as $id => $d) {
            if (($d['type'] ?? '') === 'Data' && preg_match('/^disk\d+$/D', (string) $id) && ($d['fsType'] ?? '') !== ''
                && (!$include || in_array($id, $include, true)) && !in_array($id, $exclude, true)) {
                $onDisks[] = (string) $id;
            }
        }
    }
    return ['pools' => array_values(array_unique($pools)), 'disks' => array_values(array_unique($onDisks)), 'array' => $array];
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
            if ($n[0] !== '.' && !storeroomName($n) && is_dir("/mnt/$base/$name/$n")) {        // the storeroom (hidden now, issue #7) is no folder of the share's
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
function backupSetupApply(mixed $decisions, mixed $planTime = null): array
{
    $dir = backupCheckReady();
    $data = BACKUP_DATA_DIR;
    $plan = readJson("$data/state/setup-plan.json");
    if (!$plan || !is_array($decisions) || !$decisions || array_is_list($decisions) || count($decisions) > 5000) {
        throw new Problem('setup_bad_decisions', ['detail' => $plan ? 'decisions' : 'no plan']);
    }
    // decisions made on a plan older than settings.ini would put back what was set since (another tab, a terminal)
    if (backupSetupPlanOld(is_int($planTime) ? $planTime : (int) ($plan['time'] ?? 0), "$data/settings.ini")) {
        throw new Problem('setup_plan_old');
    }
    $shares = array_column($plan['shares'] ?? [], 'name');
    $dbs = array_column(array_filter($plan['databases'] ?? [], fn ($d) => !empty($d['dumpable'])), 'container');
    $ncs = array_merge(...array_map(fn ($n) => $n['members'] ?? [], $plan['nextcloud'] ?? []) ?: [[]]);
    $vms = array_column($plan['vms'] ?? [], 'name');
    $partners = array_values(array_filter(array_column(array_filter($plan['partners'] ?? [], 'is_array'), 'id'), fn ($id) => is_string($id) && preg_match('/^[0-9a-f]{8}$/D', $id)));
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
            || (preg_match('/^app\|(.+)\|([a-z_]+)$/D', $key, $m) && in_array($m[1], $apps, true) && in_array($m[2], BACKUP_ITEM_KEYS, true))
            // engine 2.27: the partners a unit goes to - a list of the plan's partners only
            || ((preg_match('/^(share|vm)\|(.+)\|partner$/D', $key, $m) && in_array($m[2], $m[1] === 'share' ? $shares : $vms, true)
                 || $key === 'general|partner_place')
                && is_array($value) && !array_diff($value, $partners));
        // engine 2.28: sleeping pools - woken for the snapshot, or left out that night
        if ($key === 'general|asleep_pools' && !in_array($value, ['wake', 'skip'], true)) {
            $ok = false;
        }
        // engine 2.31: the default for new things - my proposals, local only, local + Kopia
        if ($key === 'general|preset_new' && !in_array($value, ['auto', 'local', 'kopia'], true)) {
            $ok = false;
        }
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
 * Is the plan the page decided on older than settings.ini? (2026-10-08 on a large server: its «skip» for sleeping pools
 * went back to «wake» at an Apply that never listed it.) The page sends every key of its plan's draft, and its dialog
 * compares with that plan's picture of settings.ini (`O`): made before settings.ini was last written - another tab
 * applied meanwhile, the same tab right after its own Apply, a terminal setup, a hand edit - it would put back what was
 * set since, unseen. Then refused (`setup_plan_old`), the page looks again and keeps the user's choices. The page's own
 * plan time counts (`plan_time`; the plan on disk may be newer than the page's), else the plan on disk's.
 * No settings.ini (a first setup, after --forget): never old.
 */
function backupSetupPlanOld(int $planTime, string $settings): bool
{
    clearstatcache(true, $settings);
    $written = is_file($settings) ? (int) @filemtime($settings) : 0;
    return $written > 0 && $planTime < $written;
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
