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

require_once dirname(__DIR__) . '/lib/nightorder.php';    // Ms. Moverelli's run first, then the scheduled backup (shared with her)

const BACKUP_INTERFACE   = 1;
const BACKUP_MODES       = ['backup' => [], 'nokopia' => ['--no-kopia'], 'dryrun' => ['--dry-run'], 'check' => ['--check']];
const BACKUP_LOG_NAME    = '/^(?:(run|check|dryrun|setup)-(\d{8})-(\d{4}|\d{6})\.log|unmount\.log)$/';    // engine 2.45: tours, dry runs, setups by the second
const BACKUP_LOG_BYTES   = 512 * 1024;
const BACKUP_HISTORY     = 60;           // runs shown
const BACKUP_UPLOAD_LOOK = 180;          // a first upload's rate: from looks at least this far apart (seconds) …
const BACKUP_UPLOAD_KEEP = 900;          // … within the last 15 minutes; before that, the average since it started
const BACKUP_OFFICE_SHARE = 'UnraidSecretaryOffice';     // the office's own share - the backup place he offers to create (the engine's UB_OFFICE_SHARE)
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
        'start_cancel' => fn (array $r) => backupQueueCancel(),             // 1.60.1: a start queued for the next minute
        'abort'   => fn (array $r) => backupAbort(),
        'unmount' => fn (array $r) => backupUnmount(),
        'log'     => fn (array $r) => backupLog(textField($r, 'log')),
        'setup_plan'  => fn (array $r) => backupSetupPlan(!empty($r['measure'])),
        'setup_get'   => fn (array $r) => backupSetupGet(),
        'setup_apply' => fn (array $r) => backupSetupApply($r['decisions'] ?? null, $r['plan_time'] ?? null),
        'setup_forget' => fn (array $r) => backupSetupForget(),
        'place_make'  => fn (array $r) => backupPlaceMake(optText($r, 'pool')),       // 2026-10-21: the setup's first step - '' the array
        'orphans_look'   => fn (array $r) => backupOrphansLook(),                    // engine 2.51: old Kopia sources of gone units
        'orphans_get'    => fn (array $r) => ['ok' => true, 'orphans' => backupOrphans()],
        'orphans_delete' => fn (array $r) => backupOrphansDelete(idList($r, 'sources'), optText($r, 'listed')),
        'kopia_accept'   => fn (array $r) => backupKopiaAccept(textField($r, 'repo')),     // engine 2.56: «yes, this repository is new»
        'kopia_restart'  => fn (array $r) => backupKopiaRestart(),     // engine 2.68: the offsite storage takes nothing - «Restart Kopia…» after a confirmation
        'setup_decline' => fn (array $r) => backupDecline(textField($r, 'key'), $r['value'] ?? null),          // 1.60.1: a declined proposal stays declined
        'setup_decline_undo' => fn (array $r) => backupDeclineUndo(textField($r, 'key')),
        'schedule'    => fn (array $r) => backupSetSchedule(cronField($r)),       // null: off — said, never a missing key
        // his let-go dialog's «Also clear away what he kept here» (agent/desks/backup-letgo.php): the look, then the clearing
        'letgo_look'  => fn (array $r) => backupLetGoLook(),
        'letgo_clear' => fn (array $r) => backupLetGoClear($r),          // hands the clearing to atd (job backup-letgo)
        'letgo_job'   => fn (array $r) => backupLetGoJobState($r),       // is a job that wrote nothing for a while still alive?
        // a gone app's or VM's package into Ms. Dustdevil's storeroom (agent/desks/backup-gone.php)
        'package_aside' => fn (array $r) => backupPackageAside($r),
        // engine 2.62's nc_datadir_readable: Nextcloud's data folder root closed again after the dialog (agent/desks/backup-ncopen.php)
        'nc_open_close' => fn (array $r) => backupNcClose($r),
        // «Make it a dataset of its own…» (1.60.1): the dialog's facts, then one of Mr. Restori's jobs (agent/desks/restore-dataset.php)
        'dataset_look' => fn (array $r) => mdLook(['kind' => optText($r, 'kind'), 'name' => optText($r, 'name'), 'share' => optText($r, 'share'), 'dir' => optText($r, 'dir'), 'stack' => optText($r, 'stack')]),
        'dataset_make' => fn (array $r) => mdStart(['kind' => optText($r, 'kind'), 'name' => optText($r, 'name'), 'share' => optText($r, 'share'), 'dir' => optText($r, 'dir'), 'stack' => optText($r, 'stack'),
                                                    'no_fresh_ok' => boolField($r, 'no_fresh_ok')]),
    ],
    'jobs'    => ['backup-letgo' => fn (array $args) => backupLetGoJob($args),
                  // the scheduled backup (job.sh, before the engine): Ms. Moverelli's run first (agent/lib/nightorder.php)
                  'backup-order' => fn (array $args) => nightOrderBackupJob()],
    'tick'    => function (): void {
        backupLockTick();             // engine 2.56: Object Lock and Kopia's maintenance, asked a few times a day
        backupWatchTick();            // engine 2.68: the Kopia server's log - does the offsite storage still take writes?
    },
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
        // engine 2.49 refuses a folder or settings.ini that isn't root's alone: the page says it with the command (the
        // Team Lead's must engine_trust), and the agent starts nothing the engine would refuse (backupCheckReady())
        'engine_trust' => backupTrustState($data),
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
        'skips'      => $skips,
        'queued'     => backupQueued(),         // 1.60.1: a backup asked for within a taken minute - it starts at {at}                  // backup runs skipped because the lock was busy, newest first
        'skipped'    => backupSkipRow(readJson("$data/state/skipped.json")),   // the last attempt of any mode
        'estimates'  => backupEstimates($history),
        'upload'     => $running ? backupUpload($status, $history, $settings) : null,     // a first upload to Kopia going on now
        // the very first backup (no good run to go by): the sizes of its Kopia sources for a rough estimate (2026-10-15)
        'rough'      => $running && backupEstimates($history)['total'] === null ? backupRough($status, $settings) : null,
        'drift'      => backupDrift(),
        'settings'   => backupSettingsSummary($settings),
        'shares'     => backupShares($settings, $history),
        'items'      => backupKopiaItems($settings, $history),
        'vms'        => $vms = backupVms($settings),
        'waiting'    => backupWaiting($settings, $vms, backupDockerSilent() ? [] : null),   // new folders, apps and VMs waiting for a decision (engine 2.21); Docker silent: no apps asked (B15)
        'containers' => backupContainers($settings),
        'partners'   => backupPartners($settings, $history, $running ? $status : null),   // engine 2.27: the last transfer per partner
        'dumps'      => backupDumps(),           // run folders of engines before 2.18, until the first 2.18 run cleared them
        'packages'   => $pk = backupPackages($settings),
        'packages_gone' => backupGoneList($pk),     // 2026-10-17: packages whose app or VM is gone (agent/desks/backup-gone.php)
        'nc_open'    => backupNcOpen($history, $settings),    // 2026-10-19: Nextcloud data folders the last run found open, still open (backup-ncopen.php)
        'schedule'   => backupSchedule(),
        'logs'       => array_values(array_map(fn ($l) => ['name' => $l['name'], 'kind' => $l['kind'], 'time' => $l['time'], 'size' => $l['size']], $logs)),
        'mounted'    => backupMounted(backupSetting($settings, 'general', 'mount_root')),
        'setup'      => $setup,
        'drill'      => backupDrillLine(),       // Mr. Restori's drill: one line from its certificate
        'kopia_held' => backupKopiaHeld(),       // engine 2.56: the Kopia step held - the repository suddenly empty
        'offsite'    => backupOffsiteReach($settings),    // engine 2.66: «offsite unreachable since …» (B16)
        'kopia_writes' => backupKopiaWrites($settings),   // engine 2.68: does the offsite storage still take writes?
    ];
    $state['found'] = true;
    $GLOBALS['backup'] = $state;
    writeAtomic(deskFile('backup'), jsonEncode($state));
    return $state;
}

/**
 * Engine 2.27: the partner offices settings.ini sends to, each with its units and the last run that had it (from the
 * history, newest first: what went, how much, how fast, what was skipped or failed and why) - and, while a run sends
 * to it, what it sends now (status.json partner.current: the unit, since, bytes so far). 1.60.1: `ended`, `anew`, `apply`
 * (and `from`) - what waits for «Apply» (partnerApplyPending(); $wait for the tests), a pair with nothing yet as a row too
 */
function backupPartners(array $settings, array $history, ?array $status = null, ?array $wait = null): array
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
        $p['current'] = $cur && ($cur['id'] ?? '') === $p['id'] ? backupPartnerRate(['unit' => (string) ($cur['unit'] ?? ''), 'since' => (int) ($cur['since'] ?? 0),
            'bytes' => (int) ($cur['bytes'] ?? 0), 'estimate' => is_int($cur['estimate'] ?? null) && $cur['estimate'] > 0 ? $cur['estimate'] : null], $p['id']) : null;
        $p['ended'] = false;
        $p['anew'] = null;
        $p['apply'] = false;
        $out[] = $p;
    }
    // 1.60.1: what waits for «Apply» - a partner ended at the Team Lead (perhaps paired anew: `anew` gets its units), a
    // pair this office sends to that settings.ini gives nothing yet (a row of its own: nothing sent yet)
    $wait ??= function_exists('partnerApplyPending') ? partnerApplyPending($settings) : ['ended' => [], 'empty' => []];
    $ended = array_column($wait['ended'], null, 'id');
    foreach ($out as &$p) {
        if (isset($ended[$p['id']])) {
            $p['ended'] = true;
            $p['anew'] = $ended[$p['id']]['anew'];
            $p['apply'] = true;
        }
    }
    unset($p);
    foreach ($wait['empty'] as $e) {
        $i = array_search($e['id'], array_column($out, 'id'), true);
        if ($i !== false) {
            $out[$i]['apply'] = true;
            $out[$i]['from'] = $e['from'];
        } else {
            $out[] = ['id' => $e['id'], 'name' => $e['name'], 'address' => '', 'port' => 22, 'rate_mbit' => 0, 'units' => [], 'last' => null,
                      'current' => null, 'ended' => false, 'anew' => null, 'apply' => true, 'from' => $e['from']];
        }
    }
    return $out;
}

// ---- declined proposals (1.60.1, a user: a «Leave out» came back at every plan) -----------------------------------------
const BACKUP_DECLINE_KEY = '/^(?!partner\|)[a-z_]+(?:\|[^|\x00-\x1f]{1,128})?\|[a-z_]+$/D';     // a settings.ini key as the plan names it, never a [partner] one
const BACKUP_DECLINE_MAX = 500;

/** Where the declines lie: his folder in the data folder (root only; tests point it elsewhere) */
function backupDeclineFile(): string
{
    return ($GLOBALS['backupDeclineDir'] ?? DATA_DIR . '/backup') . '/declined.json';
}

/** key => {value, time}: only entries in exactly that shape */
function backupDeclined(): array
{
    $j = readJson(backupDeclineFile());
    $out = [];
    foreach (is_array($j['keys'] ?? null) ? $j['keys'] : [] as $k => $d) {
        if (is_string($k) && preg_match(BACKUP_DECLINE_KEY, $k) && is_array($d) && array_key_exists('value', $d) && backupDeclineValueOk($d['value']) && is_int($d['time'] ?? null)) {
            $out[$k] = ['value' => $d['value'], 'time' => $d['time']];
        }
    }
    return $out;
}

/** A proposal's value as the page has it: null, a string, or a list of strings */
function backupDeclineValueOk(mixed $v): bool
{
    if ($v === null || (is_string($v) && strlen($v) <= 400)) {
        return true;
    }
    return is_array($v) && array_is_list($v) && count($v) <= 200 && !array_filter($v, fn ($x) => !is_string($x) || strlen($x) > 400);
}

function backupDeclineWrite(array $all): void
{
    $dir = dirname(backupDeclineFile());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new Problem('setup_bad_decisions', ['detail' => $dir]);
    }
    writeAtomic(backupDeclineFile(), jsonEncode(['v' => 1, 'keys' => (object) $all]) . "\n", 0600, 0, 0);
}

/** «Leave out» in the apply dialog: remembered with the value proposed (the newest BACKUP_DECLINE_MAX) */
function backupDecline(string $key, mixed $value): array
{
    if (!preg_match(BACKUP_DECLINE_KEY, $key) || !backupDeclineValueOk($value)) {
        throw new Problem('bad_request');
    }
    $all = backupDeclined();
    unset($all[$key]);
    $all[$key] = ['value' => $value, 'time' => time()];
    uasort($all, fn ($a, $b) => $b['time'] <=> $a['time']);
    backupDeclineWrite(array_slice($all, 0, BACKUP_DECLINE_MAX, true));
    return ['ok' => true, 'declined' => (object) backupDeclined()];
}

/** «Propose again»: the decline forgotten */
function backupDeclineUndo(string $key): array
{
    if (!preg_match(BACKUP_DECLINE_KEY, $key)) {
        throw new Problem('bad_request');
    }
    $all = backupDeclined();
    unset($all[$key]);
    backupDeclineWrite($all);
    return ['ok' => true, 'declined' => (object) backupDeclined()];
}

const BACKUP_PARTNER_RATE_FILE   = 'backup-partner-rate.json';   // in RUN_DIR: the samples of the transfer going on (RAM, gone with the agent)
const BACKUP_PARTNER_RATE_WINDOW = 600;                           // the median rate of the last 10 minutes (like Jack Emby's live panel)
const BACKUP_PARTNER_RATE_EVERY  = 10;                            // the engine writes current.bytes every 10 s (2.42)

/**
 * The rate of the transfer going on (engine 2.42: status.json partner.current.bytes every 10 s, current.estimate from
 * zfs send -nP before the send): every look keeps a sample [time, bytes] of the current unit in RAM, the speed is the
 * median of the rates between the samples of the last 10 minutes (Jack Emby's way, agent/desks/jack-progress.php
 * jackProgressSpeed()), the time left what is left of the estimate at that speed. Gives the current back with `rate`
 * (bytes/s, null before the second sample), `eta` (seconds, null without an estimate or a rate).
 */
function backupPartnerRate(array $cur, string $id): array
{
    $file = RUN_DIR . '/' . BACKUP_PARTNER_RATE_FILE;
    $key = "$id|{$cur['unit']}|{$cur['since']}";
    $now = time();
    $kept = readJson($file);
    $samples = is_array($kept) && ($kept['key'] ?? null) === $key && is_array($kept['samples'] ?? null) ? $kept['samples'] : [];
    $last = $samples ? $samples[count($samples) - 1] : null;
    // a new count only (the engine writes every 10 s; the page asks every few seconds — the same bytes twice tell nothing)
    if ($last === null || ($cur['bytes'] !== $last[1] && $now - $last[0] >= BACKUP_PARTNER_RATE_EVERY - 1)) {
        $samples[] = [$now, $cur['bytes']];
        $samples = array_values(array_filter($samples, fn ($x) => is_array($x) && $x[0] >= $now - BACKUP_PARTNER_RATE_WINDOW - BACKUP_PARTNER_RATE_EVERY));
        try {
            writeAtomic($file, jsonEncode(['key' => $key, 'samples' => array_slice($samples, -80)]), 0600, 0, 0);
        } catch (Throwable $e) {
            // RAM full or gone: no rate this round
        }
    }
    $rate = backupMedianRate($samples, $now);
    $cur['rate'] = $rate;
    $cur['eta'] = $rate !== null && $rate > 0 && $cur['estimate'] !== null ? (int) ceil(max(0, $cur['estimate'] - $cur['bytes']) / $rate) : null;
    return $cur;
}

/** The median of the rates between consecutive samples [time, bytes] within the window (null before two samples) */
function backupMedianRate(array $samples, int $now): ?float
{
    $rates = [];
    for ($i = 1; $i < count($samples); $i++) {
        [$a, $b] = [$samples[$i - 1], $samples[$i]];
        if ($b[0] > $a[0] && $a[0] >= $now - BACKUP_PARTNER_RATE_WINDOW - BACKUP_PARTNER_RATE_EVERY) {
            $rates[] = max(0, $b[1] - $a[1]) / ($b[0] - $a[0]);
        }
    }
    if (!$rates) {
        return null;
    }
    sort($rates);
    $n = count($rates);
    return $n % 2 ? $rates[intdiv($n, 2)] : ($rates[$n / 2 - 1] + $rates[$n / 2]) / 2;
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

const BACKUP_DOCKER_LOOK   = 5;      // seconds Docker may take to say its version (B15)
const BACKUP_DOCKER_KEEP   = 30;     // an answer kept so long; a silence the house keeps (houseDockerSilent(), HOUSE_DOCKER_QUIET)

/**
 * Docker switched on but silent (B15, 2026-10-20: dockerd hung — every docker call of the desk waited its 20 s, a request
 * of the page held the agent's round for a minute)? The house's memo first (houseDockerSilent(): a docker call of the
 * house timed out lately), else `docker version` through houseDocker() with a short limit — a time-out there starts the
 * house's quiet time for every desk; an answer is kept a while (the monotonic clock). While it is silent the desk asks
 * Docker nothing else and says so. Docker switched off or not there: false (that is «Docker isn't running», not
 * silence). $GLOBALS['backupDockerProbe'] stands in for the tests.
 */
function backupDockerSilent(): bool
{
    static $until = 0.0;
    if (isset($GLOBALS['backupDockerProbe'])) {
        return !($GLOBALS['backupDockerProbe'])();
    }
    if (houseDockerSilent()) {
        return true;
    }
    if (bin('docker') === null || !file_exists('/var/run/docker.sock')) {
        return false;
    }
    $now = hrtime(true) / 1e9;
    if ($now < $until) {
        return false;
    }
    $until = $now + BACKUP_DOCKER_KEEP;
    return houseDocker(['version', '--format', '{{.Server.Version}}'], BACKUP_DOCKER_LOOK)[0] === 124;
}

/**
 * The containers for the overview: how many there are, how many run, which of the
 * running ones keep running for the snapshot (Kopia, docker|no_stop) — the engine
 * stops all other running ones (unless [docker] stop = none) — and the database dumps.
 */
function backupContainers(array $s): array
{
    if (backupDockerSilent()) {          // B15: said, never counted as none
        return ['up' => false, 'silent' => true, 'total' => 0, 'running' => 0, 'kept' => 0, 'stopped' => 0,
                'dumps' => count(array_filter(array_keys($s), fn ($k) => str_starts_with($k, 'dump|'))), 'kopia' => null];
    }
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
            ? (int) (DateTime::createFromFormat(strlen($m[3]) === 6 ? 'Ymd-His' : 'Ymd-Hi', "$m[2]-$m[3]")?->getTimestamp() ?: 0)
            : (int) @filemtime($path);
        $logs[$name] = ['name' => $name, 'kind' => $m[1] ?? 'unmount', 'time' => $time, 'size' => (int) @filesize($path), 'path' => $path];
    }
    uasort($logs, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $logs;
}

/** The newest run in history.jsonl that checked the repository: its word («yes» / «no»), null when none did */
function backupKopiaLastWord(string $file): ?string
{
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_reverse(array_slice($lines, -60)) as $line) {
        $j = json_decode($line, true);
        $state = is_array($j) ? ($j['kopia']['state'] ?? null) : null;
        if (in_array($state, ['yes', 'no'], true)) {
            return $state;
        }
    }
    return null;
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
    // newest = finished last: history.jsonl gets a run's line when it ends, so a line is never older than the lines
    // before it — a run made after the clock was set back (B43: its start and name earlier) is still the last one
    $order = [];
    $key = PHP_INT_MIN;
    foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $j = json_decode($line, true);
        if (is_array($j) && ($j['result'] ?? '') === 'skipped') {
            if ($skip = backupSkipRow($j)) {
                $skips[] = $skip;
            }
        } elseif (is_array($j) && !empty($j['run'])) {
            $runs[$j['run']] = backupRunFromStatus($j);
            $key = max($key === PHP_INT_MIN ? PHP_INT_MIN : $key + 1, $runs[$j['run']]['started']);
            $order[$j['run']] = $key;
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
    uksort($runs, fn ($a, $b) => ($order[$b] ?? $runs[$b]['started']) <=> ($order[$a] ?? $runs[$a]['started']));
    return array_slice(array_values($runs), 0, BACKUP_HISTORY);
}

function backupRunFromStatus(array $j): array
{
    return [
        'run'        => (string) $j['run'],
        'started'    => (int) ($j['started'] ?? 0),
        'finished'   => (int) ($j['finished'] ?? 0),
        'result'     => (string) ($j['result'] ?? 'failed'),
        'message'    => (string) ($j['message'] ?? ''),
        // engine 2.65: the coded message's params (no_space_packages: place, free, need) - the shape of a warning's
        'message_params' => backupWarningItems([['code' => 'm', 'params' => $j['message_params'] ?? null]])[0]['params'],
        'errors'     => (int) ($j['errors'] ?? 0),
        'warnings'   => (int) ($j['warnings'] ?? 0),
        // engine 2.42: the warnings the office says in its own words (the VMs held otherwise than set up)
        'warning_items' => backupWarningItems($j['warning_items'] ?? null),
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
        // engine 2.70: what the run waited for before it started (Ms. Moverelli's run, Unraid's mover - agent/lib/nightorder.php)
        'order'      => nightOrderRow($j['order'] ?? null),
        // engine 2.27: the partner phase per partner (null: the run sent to no partner)
        'partner'    => backupPartnerRun($j['partner'] ?? null),
        // engine 2.63: per btrfs disk or pool what holds after the retention and the emergency brake (null: not looked)
        'btrfs'      => backupBtrfsRun($j['btrfs'] ?? null),
        'log'        => (string) ($j['log'] ?? ''),
        'version'    => (string) ($j['version'] ?? ''),
        'source'     => 'status',
    ];
}

/**
 * A run's coded warnings (engine 2.42, status.json warning_items: [{code, params: {key: value}}]) - the page says them
 * as warn_code.<code>. Only codes and params in the shape the engine writes, at most 20; everything else dropped.
 *
 * @return list<array{code: string, params: array<string, string>}>
 */
function backupWarningItems(mixed $items): array
{
    $out = [];
    foreach (is_array($items) ? array_slice($items, 0, 20) : [] as $w) {
        if (!is_array($w) || !is_string($w['code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $w['code'])) {
            continue;
        }
        $params = [];
        foreach (is_array($w['params'] ?? null) ? $w['params'] : [] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $k) && is_string($v) && preg_match('/^[^\x00-\x1f]{0,200}$/uD', $v)) {
                $params[$k] = $v;
            }
        }
        $out[] = ['code' => $w['code'], 'params' => $params];
    }
    return $out;
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
 * Per btrfs disk or pool what holds after a run's retention and emergency brake (engine 2.63, status.json "btrfs"):
 * {base, asleep: true} for one not looked at, else the retention (keep_days), the brake's floor, what is free, how many
 * of the engine's snapshots are left and the oldest, how many the brake released in this run, whether none was left
 * to release, the newest it ever released there and whether the retention holds. Only entries in exactly that shape,
 * at most 40; null when the run never got to its retention (or an older engine)
 *
 * @return list<array<string, mixed>>|null
 */
function backupBtrfsRun(mixed $b): ?array
{
    if (!is_array($b) || !array_is_list($b)) {
        return null;
    }
    $snap = fn ($v) => is_string($v) && preg_match('/^(\d{8}-\d{4})?$/D', $v);
    $out = [];
    foreach (array_slice($b, 0, 40) as $e) {
        if (!is_array($e) || !is_string($e['base'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $e['base'])) {
            continue;
        }
        if (($e['asleep'] ?? null) === true) {
            $out[] = ['base' => $e['base'], 'asleep' => true];
            continue;
        }
        $ints = ['keep_days', 'floor_gb', 'free_gb', 'kept', 'released'];
        if (($e['asleep'] ?? null) !== false || array_filter($ints, fn ($k) => !is_int($e[$k] ?? null) || $e[$k] < 0)
            || !$snap($e['oldest'] ?? null) || !$snap($e['brake_last'] ?? null) || !is_bool($e['none_left'] ?? null) || !is_bool($e['held'] ?? null)) {
            continue;
        }
        $out[] = ['base' => $e['base'], 'asleep' => false] + array_intersect_key($e, array_flip([...$ints, 'oldest', 'brake_last', 'none_left', 'held']));
    }
    return $out;
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
          'errors' => 0, 'warnings' => 0, 'warning_items' => [], 'downtime' => 0, 'dump_bytes' => 0, 'packages' => null, 'kopia' => [], 'kopia_skipped' => [], 'kopia_asleep' => [], 'asleep' => null, 'btrfs' => null,
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
 * Partners (2026-10-12, Benj: 99 % in the step Snapshots, the partner step not yet counted): `partner` = per
 * "<id>|<unit>" the seconds of its newest transfer (history's partner block, engine 2.27: done[].seconds; skipped
 * there = 0 only without a real transfer, a failed one says nothing), `prepare` = the part before the partner step (before Kopia less the
 * run's partner seconds; a run without Kopia: up to its end) — the page adds the units this run plans on its own.
 */
function backupEstimates(array $history): array
{
    $sources = [];
    $before = [];
    $prepare = [];
    $total = [];
    $partner = [];
    $skippedOnly = [];
    foreach ($history as $run) {
        // a unit's newest transfer counts, whatever became of the run after it — a real one before a newer skip (2026-10-17:
        // skipped yesterday, it sends two runs' worth today, never «0 s»); a unit only ever skipped: 0 (it likely is again)
        foreach ($run['partner'] ?? [] as $p) {
            foreach ($p['units'] ?? [] as $u) {
                $partner[$p['id'] . '|' . $u['unit']] ??= (int) $u['seconds'];
            }
            foreach ($p['skipped'] ?? [] as $u) {
                $skippedOnly[$p['id'] . '|' . $u['unit']] = 0;
            }
        }
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
        $sent = array_sum(array_map(fn ($p) => (int) ($p['seconds'] ?? 0), $run['partner'] ?? []));
        if (($first ?: $run['finished']) && count($prepare) < 3) {
            $prepare[] = max(0, ($first ?: $run['finished']) - $run['started'] - $sent);
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
        'prepare' => $median($prepare),
        'total'   => $median($total),
        'partner' => $partner + $skippedOnly,
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
 * The very first backup has no earlier run to go by (2026-10-15: «No estimate yet» for hours): the page reckons a rough
 * one from the sizes of its Kopia sources and the rate measured in this run (the sources done: their real seconds; the
 * one going up: what it has read). This gives the sizes — of this run's snapshot (backupSourceSize()), during the Kopia
 * step only, at most ONE new source per call (the tick stays cheap), kept per run in RAM. {sizes: {source: bytes|null},
 * complete: every planned source looked at}; null outside the Kopia step. The caller asks only while no good run exists.
 * $size: the measure (tests pass their own).
 */
function backupRough(?array $status, array $settings, ?string $cache = null, ?callable $size = null): ?array
{
    $k = is_array($status['kopia'] ?? null) ? $status['kopia'] : [];
    $run = (string) ($status['run'] ?? '');
    $planned = array_values(array_filter((array) ($k['planned'] ?? []), fn ($n) => is_string($n) && $n !== ''));
    if (!in_array($status['phase'] ?? '', ['mounting', 'kopia'], true) || $run === '' || !$planned) {
        return null;
    }
    $cache ??= RUN_DIR . '/backup-rough.json';
    $size ??= fn (string $source): ?int => backupSourceSize($source, $settings, (string) ($status['snapshot'] ?? ''));
    $c = readJson($cache) ?? [];
    if (($c['run'] ?? null) !== $run || !is_array($c['sizes'] ?? null)) {
        $c = ['run' => $run, 'sizes' => []];
    }
    foreach ($planned as $n) {
        if (!array_key_exists($n, $c['sizes'])) {
            $c['sizes'][$n] = $size($n);
            try {
                @mkdir(dirname($cache), 0700, true);
                writeAtomic($cache, jsonEncode($c), 0600, 0, 0);
            } catch (Throwable $e) {
                // RAM only - without it the sizes are looked at again
            }
            break;
        }
    }
    $sizes = [];
    foreach ($planned as $n) {
        $v = $c['sizes'][$n] ?? null;
        $sizes[$n] = is_int($v) && $v >= 0 ? $v : null;
    }
    return ['sizes' => $sizes, 'complete' => count(array_intersect($planned, array_keys($c['sizes']))) === count($planned)];
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
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name) || backupDockerSilent()) {
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
            [$exit, $outp] = houseDocker(['ps', '-a', '--format', '{{.Names}}' . "\t" . '{{.Label "com.docker.compose.project"}}']);    // B15: the house's limit and memo
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
                // the engine's own: its manifest in exactly the engine's shape (interface 1, this kind, this folder) - only
                // such a package is ever moved aside by the office (backupPackageAside())
                'engine_written' => ($m['interface'] ?? null) === 1 && ($m['kind'] ?? null) === substr($sub, 0, -1) && ($m['folder'] ?? null) === $folder
                    && is_string($m['engine'] ?? null) && is_string($m['run'] ?? null),
                'bytes'  => array_sum(array_column($list, 'bytes')),
                'files'  => $list,
            ];
            if ($sub === 'apps') {
                $p += [
                    'type'       => $str($m['type'] ?? ''),
                    'containers' => array_values(array_map(fn ($c) => ['name' => $str($c['name'] ?? ''), 'image' => $str($c['image'] ?? ''),
                                        'image_id' => $str($c['image_id'] ?? ''),
                                        'digest' => $str(($c['digests'] ?? [])[0] ?? ''), 'template' => $str($c['template'] ?? '')],
                                        array_filter((array) ($m['containers'] ?? []), 'is_array'))),
                    'dumps'      => array_values(array_map(fn ($d) => array_map($str, array_intersect_key($d, array_flip(
                                        ['container', 'type', 'state', 'login', 'user_var', 'password_var', 'client']))),
                                        array_filter((array) ($m['dumps'] ?? []), 'is_array'))),
                    'nextcloud'  => array_values(array_map(fn ($n) => ['container' => $str($n['container'] ?? ''), 'occ' => $str($n['occ'] ?? ''),
                                        'user' => $str($n['user'] ?? '') ?: 'www-data', 'same_as' => $str($n['same_as'] ?? '')],
                                        array_filter((array) ($m['nextcloud'] ?? []), 'is_array'))),
                    'compose_dir' => $str($m['compose']['manager_dir'] ?? ''),
                    // the folders it binds (<share>/<folder>, or a whole <share>) as the engine named them - who it is (backupGonePackages())
                    'dirs'       => array_values(array_filter(array_map($str, (array) ($m['dirs'] ?? [])), fn ($d) => $d !== '' && !preg_match('/[\x00-\x1f]/', $d))),
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
    // order_hint: it starts where Ms. Moverelli's run goes - she first, he waits (agent/lib/nightorder.php; the page's quiet line)
    return ['script' => is_file(BACKUP_DATA_DIR . '/settings.ini')] + officeJobSchedule('backup') + ['order_hint' => nightOrderHint()];
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
    $c = backupDockerSilent() ? null : houseInspect($container);       // B15: a silent Docker is not asked
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
    // the engine (2.49) would refuse at once (exit 4, an alert) and write no status: «Look again» or Apply would answer
    // ok and show the old plan as fresh (scenario B14 perms, 2026-10-19) - refused here with what to do
    $trust = backupTrustState($data);
    if ($trust !== null) {
        throw new Problem('engine_untrusted', ['path' => $trust['path'], 'cmd' => $trust['cmd']]);
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
    // one a minute only for a real backup (its snapshots carry the minute, 1.60.1): taken - it starts at the next minute,
    // queued through atd (backupQueue()), cancellable; the engine refuses a second one in a minute all the same
    if (($wait = backupMinuteTaken($mode, $data)) !== null) {
        return backupQueue($mode, $dir, $wait);
    }
    $before = (int) (readJson("$data/state/status.json")['started'] ?? 0);
    $skipBefore = readJson("$data/state/skipped.json");
    $t0 = time();
    // started here, it never waits for Ms. Moverelli's run or Unraid's mover - but says so (agent/lib/nightorder.php)
    $beside = in_array($mode, ['backup', 'nokopia'], true) ? nightOrderBeside() : null;
    backupLaunch(array_merge([$dir . '/backup.sh'], BACKUP_MODES[$mode]), $beside !== null ? ['UB_ORDER' => "$beside:0"] : []);
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
    return ['ok' => true, 'started' => $seen, 'skipped' => $skipped, 'beside' => $beside, 'state' => backupScan()];
}

const BACKUP_QUEUE_FILE = 'backup-queued.json';     // in RUN_DIR: a backup asked for within a taken minute, {at, mode}

/** The queued start, while it waits (null once it ran, was cancelled or is long past) */
function backupQueued(): ?array
{
    $q = readJson(RUN_DIR . '/' . BACKUP_QUEUE_FILE);
    if (!is_array($q) || !is_int($q['at'] ?? null) || !isset(BACKUP_MODES[$q['mode'] ?? '']) || $q['at'] < time() - 30 || file_exists(RUN_DIR . '/backup-queued.cancel')) {
        return null;
    }
    return ['at' => $q['at'], 'mode' => $q['mode']];
}

/**
 * A real backup within a taken minute: handed to atd for the next minute (a shell that sleeps, then looks for a cancel).
 * It lives in atd's queue, not in the agent: an agent restart meanwhile doesn't lose it (its note in RUN_DIR stays too);
 * a reboot within that minute does (atd's queue and RUN_DIR are RAM) - then nothing starts and nothing is said.
 */
function backupQueue(string $mode, string $dir, int $wait): array
{
    if (backupQueued() !== null) {
        throw new Problem('backup_queued', ['time' => date('H:i', backupQueued()['at'])]);
    }
    $at = time() + $wait + 1;
    $cancel = RUN_DIR . '/backup-queued.cancel';
    $file = RUN_DIR . '/' . BACKUP_QUEUE_FILE;
    @unlink($cancel);
    writeAtomic($file, jsonEncode(['at' => $at, 'mode' => $mode]), 0600, 0, 0);
    $cmd = implode(' ', array_map('escapeshellarg', array_merge(['/bin/bash', "$dir/backup.sh"], BACKUP_MODES[$mode])));
    $script = 'sleep ' . ($wait + 1) . '; if [ -e ' . escapeshellarg($cancel) . ' ]; then rm -f ' . escapeshellarg($cancel) . '; exit 0; fi; rm -f '
        . escapeshellarg($file) . "; exec $cmd";
    hostLaunch('backup-queued', ['/bin/sh', '-c', $script], ['UB_DATA' => BACKUP_DATA_DIR_USER]);
    logLine("Backup: $mode asked for within a taken minute - queued for " . date('H:i', $at));
    return ['ok' => true, 'started' => false, 'queued' => ['at' => $at, 'mode' => $mode], 'state' => backupScan()];
}

/** «Cancel»: the queued start won't run */
function backupQueueCancel(): array
{
    if (backupQueued() !== null) {
        @touch(RUN_DIR . '/backup-queued.cancel');
        @unlink(RUN_DIR . '/' . BACKUP_QUEUE_FILE);
        logLine('Backup: the queued start was cancelled');
    }
    return ['ok' => true, 'state' => backupScan()];
}

/**
 * One run a minute (QA 2026-10-08, finding 3): the engine's run id, its log and its snapshots' name carry the minute
 * (`uso-backup-YYYYMMDD-HHMM`) — a second run in the same minute failed with «ZFS snapshot … failed» and left two
 * history lines with one id. Taken: the last run (status.json, any mode) has the id the engine would take now, or the
 * log this mode would write is there (backup.sh refuses then too, engine 2.34). The seconds until the next minute, or null.
 */
function backupMinuteTaken(string $mode, string $data, ?int $now = null): ?int
{
    // 1.60.1: a tour or a dry run makes no snapshot - never held back (its log carries the second, engine 2.45)
    if (!in_array($mode, ['backup', 'nokopia'], true)) {
        return null;
    }
    $now ??= time();
    $id = date('Ymd-Hi', $now);
    $status = readJson("$data/state/status.json");
    if ((($status['run'] ?? null) === $id && in_array($status['mode'] ?? 'backup', ['backup', ''], true)) || is_file("$data/logs/run-$id.log")) {
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
/**
 * As the engine's ub_data_trusted() (backup/lib/common.sh, 2.49) sees its folder: a real folder of root's that nobody
 * else can write, settings.ini a plain root-owned file likewise — else it refuses to run. ok null: no folder yet.
 *
 * @return array{ok: ?bool, path: string, cmd: string}
 */
function backupEngineTrust(string $data): array
{
    clearstatcache();
    $mine = fn (array|false $st, int $type): bool => $st !== false && ($st['mode'] & 0170000) === $type && $st['uid'] === 0 && !($st['mode'] & 0022);
    $dir = @lstat($data);
    if ($dir === false) {
        return ['ok' => null, 'path' => '', 'cmd' => ''];
    }
    $bad = !$mine($dir, 0040000) ? $data : null;
    if ($bad === null && ($ini = @lstat("$data/settings.ini")) !== false && !$mine($ini, 0100000)) {
        $bad = "$data/settings.ini";
    }
    $q = $bad !== null ? escapeshellarg($bad) : '';
    return ['ok' => $bad === null, 'path' => (string) $bad, 'cmd' => $bad !== null ? "chown root:root $q && chmod go-w $q" : ''];
}

/**
 * The engine's refusal of its folder as Mr. Backupsy's state carries it: null while the engine trusts it (or there is no
 * folder yet), else {code: engine_untrusted, path, cmd} - the same path and command as the Team Lead's must engine_trust.
 *
 * @return array{code: string, path: string, cmd: string}|null
 */
function backupTrustState(string $data): ?array
{
    $t = backupEngineTrust($data);
    return $t['ok'] === false ? ['code' => 'engine_untrusted', 'path' => $t['path'], 'cmd' => $t['cmd']] : null;
}

/**
 * The shares settings.ini keeps at «local» (mode snapshot) while the last plan found no snapshot possible there (method
 * live: XFS or btrfs-less disks, a share over XFS and ZFS, a locked dataset) or the user set method live - the run backs
 * them up with nothing (its drift share_snapshot_no_fs). Settings and plan only, nothing on the disks.
 *
 * @return list<string>
 */
function backupLocalWithoutSnapshot(array $settings, array $plan): array
{
    $method = [];
    foreach (is_array($plan['shares'] ?? null) ? $plan['shares'] : [] as $sh) {
        if (is_array($sh) && is_string($sh['name'] ?? null) && is_string($sh['method'] ?? null)) {
            $method[$sh['name']] = $sh['method'];
        }
    }
    $out = [];
    foreach ($settings as $section => $_) {
        if (!str_starts_with((string) $section, 'share|') || backupSetting($settings, (string) $section, 'mode') !== 'snapshot') {
            continue;
        }
        $name = substr((string) $section, 6);
        $m = $method[$name] ?? '';
        if ($m === 'live' || ($m === 'snap' && backupSetting($settings, (string) $section, 'method') === 'live')) {
            $out[] = $name;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Apps whose data shares go less far than the app (a user, app «s3» to Kopia, its bucket share s3-tom only local): a
 * restore elsewhere brings the app back without its data. The apps as the setup page sees them (setupModel in desk.js:
 * a compose stack or a single container, Kopia's and the office's own left out), from the last plan's containers and
 * their binds (setup-plan.json, nothing asked of Docker) and settings.ini. A data share: a writable bind into a share
 * that is no app home (Unraid's app/VM shares, a share with folders of three or more containers), not appdata, system,
 * domains, isos, the office's share or the backup place, a share that exists ($exists); read-only binds and a media
 * server's libraries are not «its data». The app's level as the setup reads it (setupInitLevels): skipped = not backed
 * up, Kopia when it has a source of its own or all its folders' shares go there; its partners its own and those of its
 * folders' shares (a folder that is no dataset of its own travels with its share). Settings and plan only.
 *
 * @param list<string> $exists  the shares there are now (null: every share in the plan)
 * @return array{kopia: array<string, list<string>>, off: array<string, list<string>>, partner: array<string, array<string, list<string>>>}
 *         app => its shares; partner: partner id => app => shares
 */
function backupAppShareGaps(array $settings, array $plan, ?array $exists = null): array
{
    $out = ['kopia' => [], 'off' => [], 'partner' => []];
    $shares = [];
    foreach (is_array($plan['shares'] ?? null) ? $plan['shares'] : [] as $sh) {
        if (is_array($sh) && is_string($sh['name'] ?? null)) {
            $shares[$sh['name']] = $sh;
        }
    }
    $list = fn (string $section, string $key): array => array_values(array_filter($settings[$section][$key] ?? [], 'is_string'));
    $mode = fn (string $s): string => (string) backupSetting($settings, "share|$s", 'mode', 'off');
    $kopiaOn = backupSetting($settings, 'kopia', 'enabled') === 'yes';
    $skip = $list('docker', 'skip');
    $place = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $never = array_map('strtolower', ['appdata', 'system', 'domains', 'isos', BACKUP_OFFICE_SHARE, $place]);
    // an app's home: Unraid names it for app configs or VMs, or three or more containers keep folders in it
    $home = [];
    foreach ($shares as $n => $sh) {
        $cts = array_unique(array_map(fn ($f) => is_array($f) ? (string) ($f['container'] ?? '') : '', is_array($sh['folders'] ?? null) ? $sh['folders'] : []));
        $home[$n] = !empty($sh['app_share']) || count($cts) >= 3;
    }
    $apps = [];
    foreach (is_array($plan['containers'] ?? null) ? $plan['containers'] : [] as $c) {
        if (!is_array($c) || !is_string($c['name'] ?? null) || !empty($c['kopia']) || ($c['why'] ?? '') === 'office') {
            continue;
        }
        $app = is_string($c['project'] ?? null) && $c['project'] !== '' ? $c['project'] : $c['name'];
        $apps[$app][] = $c;
    }
    ksort($apps, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($apps as $app => $members) {
        if (!array_diff(array_column($members, 'name'), $skip)) {
            continue;                                   // not backed up at all: nothing goes less far than it
        }
        $folders = [];
        $data = [];
        foreach ($members as $c) {
            $media = is_string($c['media'] ?? null) && $c['media'] !== '';
            foreach (is_array($c['binds'] ?? null) ? $c['binds'] : [] as $b) {
                $s = is_array($b) && is_string($b['share'] ?? null) ? $b['share'] : null;
                if ($s === null || !isset($shares[$s]) || strtolower($s) === 'system') {
                    continue;
                }
                if ($home[$s]) {
                    $dir = explode('/', (string) ($b['path'] ?? ''))[0];
                    if ($dir !== '') {
                        $folders["$s|$dir"] = [$s, $dir];
                    }
                } elseif (in_array(strtolower($s), $never, true)) {
                    continue;
                } elseif (($b['rw'] ?? false) === true && !$media && ($exists === null || in_array($s, $exists, true))) {
                    $data[$s] = true;
                }
            }
        }
        if (!$data) {
            continue;
        }
        $ignored = fn (string $s, string $dir): bool => in_array("/$dir/", $list("share|$s", 'kopia_ignore'), true);
        $onKopia = $folders && !array_filter($folders, fn ($f) => $mode($f[0]) !== 'kopia' || $ignored($f[0], $f[1]));
        $kopia = $kopiaOn && (backupSetting($settings, "app|$app", 'kopia') === 'yes' || $onKopia);
        $partners = $list("app|$app", 'partner');
        foreach ($folders as [$s, $dir]) {
            $own = array_filter(is_array($shares[$s]['children'] ?? null) ? $shares[$s]['children'] : [], fn ($k) => basename((string) $k) === $dir);
            if (!$own) {
                array_push($partners, ...$list("share|$s", 'partner'));
            }
        }
        $partners = array_values(array_unique($partners));
        $names = array_keys($data);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $s) {
            $has = $list("share|$s", 'partner');
            if ($mode($s) === 'off' && !$has) {
                $out['off'][$app][] = $s;               // the app backed up, its data not at all - said once, the strongest
                continue;
            }
            if ($kopia && $mode($s) !== 'kopia') {
                $out['kopia'][$app][] = $s;
            }
            foreach (array_diff($partners, $has) as $p) {
                $out['partner'][$p][$app][] = $s;
            }
        }
    }
    ksort($out['partner']);
    return $out;
}

/** «s3 (s3-tom), dropnook (drop, photos)»: app => shares as one line, the same in every language */
function backupAppShareList(array $apps): string
{
    return implode(', ', array_map(fn ($a, $s) => "$a (" . implode(', ', $s) . ')', array_keys($apps), $apps));
}

/**
 * The Team Lead's word on backupAppShareGaps(): one finding per kind (not offsite with Kopia, not to a partner - one per
 * partner, not backed up at all), each listing every app concerned; all in place: one finding in place. Recommended,
 * never a must: the user decides about the share (owner 2026-10-17). The link: the setup's step with the shares.
 */
function backupAppShareFindings(array $settings, array $plan, ?array $exists = null): array
{
    $gaps = backupAppShareGaps($settings, $plan, $exists);
    $out = [];
    foreach (['off', 'kopia'] as $kind) {
        if ($gaps[$kind]) {
            $out[] = finding("app_share_$kind", 'recommended', false, ['apps' => backupAppShareList($gaps[$kind]), 'n' => count($gaps[$kind])], '#/backup/setup/shares');
        }
    }
    foreach ($gaps['partner'] as $id => $apps) {
        $name = (string) backupSetting($settings, "partner|$id", 'name', (string) $id);
        $out[] = finding('app_share_partner', 'recommended', false, ['apps' => backupAppShareList($apps), 'n' => count($apps), 'partner' => $name], '#/backup/setup/shares');
    }
    return $out ?: [finding('app_share', 'recommended', true, [], '#/backup/setup/shares')];
}

function backupChecks(): array
{
    $data = BACKUP_DATA_DIR;
    $out = [];
    $settings = backupReadSettings("$data/settings.ini");
    $summary = backupSettingsSummary($settings);
    $kopiaOn = $summary['kopia_enabled'];

    $out[] = finding('setup', 'required', is_file("$data/settings.ini"), ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    // the engine (2.49) refuses a folder or settings.ini that isn't root's alone (ub_data_trusted) - said here, the command shown
    // no folder yet (nothing set up): nothing to trust or distrust, no «couldn't check» with an empty path (7.4 test 2026-10-16)
    $trust = backupEngineTrust($data);
    if ($trust['ok'] !== null) {
        $out[] = finding('engine_trust', 'required', $trust['ok'], ['path' => $trust['path'], 'cmd' => $trust['cmd']]);
    }
    $schedule = backupSchedule();
    if ($schedule['script']) {
        $out[] = finding('schedule', 'required', $schedule['enabled'], [], '#/backup/schedule');
    }

    // Kopia: a must for offsite copies, otherwise a recommendation
    $level = $kopiaOn ? 'required' : 'recommended';
    // B15: Docker silent - Kopia's container can't be looked at: «couldn't check», never «missing» or «stopped»
    $silent = backupDockerSilent();
    $containers = $silent ? [] : houseContainers();
    $name = $silent ? (($summary['kopia_container'] ?? '') ?: null) : $summary['kopia_container'];
    if (!$silent && (!$name || !isset($containers[$name]))) {
        $name = null;
        foreach ($containers as $c) {
            if (stripos($c['image'], 'kopia') !== false) {
                $name = $c['name'];
                break;
            }
        }
    }
    $out[] = finding('kopia', $level, $silent ? null : $name !== null, ['name' => $name ?? 'kopia'], 'apps');
    // Kopia runs in Docker, and Docker's image lies in Unraid's share system: missing, the Team Lead says what to do
    if (!$silent && $name === null && in_array('system', array_column(homeMissing()['missing'], 'share'), true)) {
        $out[] = finding('docker_system', $level, false, ['share' => 'system'], '#/caretaker/todo');
    }
    if ($name !== null) {
        $c = $containers[$name] ?? null;
        $out[] = finding('kopia_running', $level, $c === null ? null : $c['running'], ['name' => $name], 'docker');
        $inspect = $silent ? null : houseInspect($name);
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
            if (!in_array($state, ['yes', 'no'], true)) {
                // the last run said nothing about it (a check, a --no-kopia run): the newest run that did counts — a
                // night that uploaded 32 sources is not «couldn't check» because a check or a --no-kopia run came since
                $state = backupKopiaLastWord("$data/state/history.jsonl");
            }
            $out[] = finding('kopia_repo', 'required', in_array($state, ['yes', 'no'], true) ? $state === 'yes' : null, ['name' => $name]);
            // engine 2.56: a repository suddenly empty holds the Kopia step until the user decides; Object Lock still extended
            $held = backupKopiaHeld();
            $out[] = finding('kopia_emptied', 'required', $held === null, $held === null ? [] : backupHeldParams($held), '#/backup');
            array_push($out, ...backupKopiaLockJudge(backupKopiaLockRead(), time(), $name));
            // engine 2.68: the offsite storage takes nothing (Kopia's server log, or a run's upload ended by its stall
            // watchdog) - said only once the engine looked; «unknown» (not the maintenance owner, no log) says nothing
            if ($f = backupWritesFinding(backupKopiaWrites($settings), $name)) {
                $out[] = $f;
            }
        }
    }

    if (is_file("$data/settings.ini")) {
        $drift = array_filter(backupDrift()['items'], fn ($d) => in_array($d['level'] ?? '', ['warn', 'error'], true));
        $out[] = finding('drift', 'recommended', !$drift, ['n' => count($drift)], '#/backup/setup');

        // 2026-10-17: a package whose app or VM is gone (the last look's, backupGoneList()) - a hint, «Into the storeroom» there
        $gone = $GLOBALS['backup']['packages_gone'] ?? null;
        if (is_array($gone)) {
            // a package whose app is now backed up under another name (moved_to) is no gone app - only said on the page
            $names = array_values(array_unique(array_map(fn ($g) => (string) ($g['name'] ?? ''), array_filter($gone, fn ($g) => is_array($g) && !isset($g['moved_to'])))));
            $out[] = finding('package_gone', 'hint', !$names, ['names' => implode(', ', array_slice($names, 0, 8)), 'n' => count($names)], '#/backup/prot');
        }

        // engine 2.55: a share at «local» where no snapshot is possible (the plan's method live) is backed up by nothing
        $plan = readJson("$data/state/setup-plan.json") ?? [];
        $noSnap = backupLocalWithoutSnapshot($settings, $plan);
        $out[] = finding('share_no_snapshots', 'recommended', !$noSnap, ['shares' => implode(', ', $noSnap), 'n' => count($noSnap)], '#/backup/setup');

        // an app's data share backed up less far than the app (the last plan's binds; the shares there now: Unraid's
        // config on the flash, so a share renamed since that plan isn't named)
        $cfgs = glob('/boot/config/shares/*.cfg') ?: [];
        array_push($out, ...backupAppShareFindings($settings, $plan,
            $cfgs ? array_map(fn ($f) => basename($f, '.cfg'), $cfgs) : null));

        // the packages in their own backup share, never in appdata — without one the engine refuses to run;
        // Unraid's share config (on the flash) says it exists, the share itself is only looked at while its disks are awake
        $ds = (string) ($summary['dumps_share'] ?? '');
        $dsName = $ds !== '' && !in_array(strtolower($ds), ['appdata', 'system', 'domains'], true);
        $dsCfg = $dsName && preg_match('/^[\w .-]+$/', $ds) && is_file("/boot/config/shares/$ds.cfg");
        $dsSleeps = $dsName && !$dsCfg && backupShareAsleep($ds, $settings, sleepingDisks());
        $dsBad = !$dsName || (!$dsCfg && !$dsSleeps && !is_dir("/mnt/user/$ds"));
        // its disks asleep: not looked at, so neither judged nor counted as something to do - said at the next look
        // while they are awake (2026-10-17: a must «couldn't check» stood under «Required» on the Team Lead's page)
        if (!$dsSleeps) {
            $out[] = finding('dumps_share', 'required', !$dsBad, ['share' => $ds], '#/backup/setup');
        }

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
        array_push($out, ...backupNextcloudFindings($summary['nextcloud'], function (string $ct) use ($settings): array {
            $dir = backupNextcloudDataDir($ct, $sleeps, $settings);
            if ($sleeps || $dir === null) {
                return [null, null];
            }
            $mode = @fileperms($dir);
            return [$dir, $mode === false ? null : $mode];
        }));
    }

    return $out;
}

/**
 * Nextcloud's data folder readable by others (it refuses to work then): one finding per data folder (app and cron of
 * one Nextcloud share it). $look(container) → [the folder on the host, its permission bits] - [null, null] when it
 * wasn't looked at (its disk sleeps, no folder found), a folder with null bits when they couldn't be read: neither
 * judged nor counted as something to do (a must «couldn't check» with «?» in its text stood under «Required» on the
 * Team Lead's page, once per container - 2026-10-17); the next look while it's awake says it.
 *
 * @param list<array{container: string}> $ncs
 */
function backupNextcloudFindings(array $ncs, callable $look): array
{
    $out = [];
    $seen = [];
    foreach ($ncs as $nc) {
        [$dir, $mode] = $look((string) $nc['container']);
        if ($dir === null || $mode === null || isset($seen[$dir])) {
            continue;
        }
        $seen[$dir] = true;
        $out[] = finding('nextcloud_datadir', 'required', ($mode & 0007) === 0,
            ['name' => $nc['container'], 'path' => $dir, 'mode' => sprintf('%o', $mode & 0777)], 'userscripts');
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

// ===================================================================== the offsite repository guarded (engine 2.56)

const BACKUP_GUARD_ENGINE = '2.56';
const BACKUP_LOCK_EVERY   = 6 * 3600;      // Object Lock on: Kopia's maintenance asked anew at most every 6 hours …
const BACKUP_LOCK_PLAIN   = 3 * 86400;     // … a repository without: every 3 days (a run writes it after its Kopia phase too)
const BACKUP_LOCK_RETRY   = 3600;          // Kopia couldn't be asked: again after an hour
const BACKUP_LOCK_LOOK    = 600;           // the tick looks at the file's age at most every 10 minutes
const BACKUP_LOCK_LATE    = 12 * 3600;     // a full maintenance later than its interval by more than this: late
const BACKUP_LOCK_MARGIN  = 2 * 86400;     // the locks run out within the larger of 2 days and a quarter of the period (at most half): a must
const BACKUP_REPO_ID      = '/^[0-9a-f]{16,128}$/D';

/** The hold of the Kopia step (state/kopia-held.json) in exactly the engine's shape — else null */
function backupKopiaHeld(?string $file = null): ?array
{
    $j = readJson($file ?? BACKUP_DATA_DIR . '/state/kopia-held.json');
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !is_string($j['repo'] ?? null) || !preg_match(BACKUP_REPO_ID, $j['repo'])
        || !is_string($j['identity'] ?? null) || !preg_match('/^[^\x00-\x1f]{1,200}$/uD', $j['identity'])) {
        return null;
    }
    foreach (['before', 'now', 'when', 'time'] as $k) {
        if (!is_int($j[$k] ?? null) || $j[$k] < 0) {
            return null;
        }
    }
    return ['repo' => $j['repo'], 'identity' => $j['identity'], 'before' => $j['before'], 'now' => $j['now'], 'when' => $j['when'],
            'time' => $j['time'], 'gone' => ($j['gone'] ?? false) === true];
}

/**
 * Engine 2.66 (B16): «offsite unreachable since …» — state/kopia-reach.json: the first real run whose Kopia check couldn't
 * reach the repository (kept from run to run, removed by the first run that reaches it) and the last one, in exactly the
 * engine's shape — else null. Only while Kopia is switched on in settings.ini.
 */
function backupOffsiteReach(array $settings, ?string $file = null): ?array
{
    if (!in_array(strtolower((string) backupSetting($settings, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true)) {
        return null;
    }
    $j = readJson($file ?? BACKUP_DATA_DIR . '/state/kopia-reach.json');
    if (!is_array($j) || ($j['v'] ?? null) !== 1) {
        return null;
    }
    foreach (['since', 'last'] as $k) {
        if (!is_int($j[$k] ?? null) || $j[$k] <= 0) {
            return null;
        }
    }
    foreach (['run', 'last_run'] as $k) {
        if (!is_string($j[$k] ?? null) || !preg_match('/^\d{8}-\d{4}$/D', $j[$k])) {
            return null;
        }
    }
    return ['unreachable_since' => $j['since'], 'run' => $j['run'], 'last' => $j['last'], 'last_run' => $j['last_run']];
}

/**
 * Engine 2.68: does the offsite storage still take what Kopia writes? state/kopia-watch.json (backup.sh --kopia-watch from
 * Kopia's server log, or a run's stall watchdog) in exactly the engine's shape — else null. Only while Kopia is switched
 * on in settings.ini. `state` ok | stuck | unknown; `since` (stuck) the first moment it took nothing; `by` server | run.
 */
function backupKopiaWrites(array $settings, ?string $file = null): ?array
{
    if (!in_array(strtolower((string) backupSetting($settings, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true)) {
        return null;
    }
    $j = readJson($file ?? BACKUP_DATA_DIR . '/state/kopia-watch.json');
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !in_array($j['state'] ?? null, ['ok', 'stuck', 'unknown'], true) || !is_int($j['time'] ?? null)) {
        return null;
    }
    $stuck = $j['state'] === 'stuck';
    if ($stuck && (!is_int($j['since'] ?? null) || $j['since'] <= 0)) {
        return null;
    }
    $storage = is_string($j['storage'] ?? null) && preg_match('/^[^\x00-\x1f<>]{0,120}$/uD', $j['storage']) ? $j['storage'] : '';
    return ['state' => $j['state'], 'since' => $stuck ? $j['since'] : null, 'by' => $stuck && ($j['by'] ?? '') === 'run' ? 'run' : ($stuck ? 'server' : null),
            'storage' => $storage, 'time' => $j['time'],
            'why' => is_string($j['why'] ?? null) && preg_match('/^[a-z_]{0,20}$/D', $j['why']) ? $j['why'] : ''];
}

/** The finding's words of an offsite that takes nothing: since when (as the page says it), where, which container */
function backupWritesParams(array $w, ?string $name): array
{
    return ['since' => date('Y-m-d H:i', (int) $w['since']), 'since_t' => (int) $w['since'],
            'storage' => $w['storage'] !== '' ? $w['storage'] : '–', 'name' => $name ?? 'kopia'];
}

/**
 * The finding kopia_writes from the watch's record (backupKopiaWrites()): a must only while the storage takes nothing —
 * it clears by itself once the engine sees a write go through (the record says ok); «unknown» (not the maintenance owner,
 * no log, the container stopped before anything was seen) and no record say nothing at all. Pure.
 */
function backupWritesFinding(?array $w, ?string $name): ?array
{
    if ($w === null || !in_array($w['state'] ?? null, ['ok', 'stuck'], true)) {
        return null;
    }
    $stuck = $w['state'] === 'stuck';
    return finding('kopia_writes', 'required', !$stuck, $stuck ? backupWritesParams($w, $name) : ['name' => $name ?? 'kopia'], '#/backup');
}

const BACKUP_WATCH_ENGINE = '2.68';
const BACKUP_WATCH_EVERY  = 600;         // the Kopia server's log looked at (backup.sh --kopia-watch through atd) every 10 minutes

/**
 * His tick (engine 2.68): every 10 minutes the engine reads the Kopia server's own log (backup.sh --kopia-watch through
 * atd: one docker exec, the repository never opened) — only while Kopia is on, never with the engine's folder on a sleeping
 * disk; a run going on doesn't matter (nothing is locked or written in the repository).
 */
function backupWatchTick(?int $now = null): ?string
{
    static $launched = 0;
    $now ??= time();
    if ($now - $launched < BACKUP_WATCH_EVERY && $now >= $launched) {
        return null;
    }
    $launched = $now;
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh") || !is_file("$data/settings.ini") || backupHomeAsleep($data) || backupDockerSilent()
        || !backupSettingsSummary(backupReadSettings("$data/settings.ini"))['kopia_enabled']
        || version_compare((string) (backupAbout($dir)['version'] ?? '0'), BACKUP_WATCH_ENGINE, '<')) {
        return null;
    }
    backupLaunch(["$dir/backup.sh", '--kopia-watch']);
    return 'launched';
}

/**
 * «Restart Kopia…» (engine 2.68, on his page while the offsite storage takes nothing, after a confirmation): `docker
 * restart` of exactly the container settings.ini names — never by himself, never while the engine's lock is held (a run,
 * the setup, a restore or drill: backupCheckReady()). Afterwards the watch looks again; «stuck» ends only when a write
 * goes through (the restart alone proves nothing).
 */
function backupKopiaRestart(): array
{
    $dir = backupCheckReady();
    if (version_compare((string) (backupAbout($dir)['version'] ?? '0'), BACKUP_WATCH_ENGINE, '<')) {
        throw new Problem('backup_too_old', ['version' => backupAbout($dir)['version'] ?? '?']);
    }
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $name = backupSettingsSummary($settings)['kopia_container'] ?? null;
    if (!backupSettingsSummary($settings)['kopia_enabled'] || !is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)
        || !isset(houseContainers(true)[$name])) {
        throw new Problem('kopia_restart_gone');
    }
    [$exit, , $err] = houseDocker(['restart', '-t', '30', $name], 120);
    if ($exit !== 0) {
        logLine("Backup: Kopia's container $name could not be restarted (exit $exit): " . trim((string) $err));
        throw new Problem('kopia_restart_failed', ['name' => $name]);
    }
    logLine("Backup: Kopia's container $name restarted on the user's click (the offsite storage took nothing since "
        . (($w = backupKopiaWrites($settings)) && $w['since'] ? date('Y-m-d H:i', $w['since']) : '–') . ')');
    try {
        backupLaunch(["$dir/backup.sh", '--kopia-watch']);
    } catch (Throwable $e) {
        logLine('Backup: the look at Kopia after its restart could not start: ' . $e->getMessage());
    }
    return ['ok' => true, 'name' => $name, 'state' => backupScan()];
}

/** The finding's words of a hold: counts and the dates as the page says them */
function backupHeldParams(array $h): array
{
    return ['before' => (string) $h['before'], 'now' => (string) $h['now'], 'when' => date('Y-m-d H:i', $h['when']),
            'since' => date('Y-m-d H:i', $h['time']), 'when_t' => $h['when'], 'since_t' => $h['time']];
}

/**
 * «Yes, this repository is new» (one click on his page): the engine ends the hold of exactly the repository the page
 * showed and takes the count as it is (kopia-count.json by accepted) — behind its lock, so never during a run, setup or
 * restore. Another hold meanwhile (another repository) is refused: the page shows the new one first.
 */
function backupKopiaAccept(string $repo): array
{
    $dir = backupCheckReady();
    if (version_compare((string) (backupAbout($dir)['version'] ?? '0'), BACKUP_GUARD_ENGINE, '<')) {
        throw new Problem('backup_too_old', ['version' => backupAbout($dir)['version'] ?? '?']);
    }
    $held = backupKopiaHeld();
    if ($held === null || $held['repo'] !== $repo) {
        throw new Problem('kopia_held_stale');
    }
    [, $out] = run(['env', 'UB_DATA=' . BACKUP_DATA_DIR_USER, 'bash', "$dir/backup.sh", "--kopia-accept=$repo"], 30);
    $j = json_decode(trim((string) $out), true);
    if (!is_array($j) || ($j['ok'] ?? null) !== true) {
        $why = is_array($j) && is_string($j['why'] ?? null) && preg_match('/^[a-z_]{1,20}$/D', $j['why']) ? $j['why'] : 'no_answer';
        if ($why === 'busy') {
            throw new Problem('backup_running');
        }
        throw new Problem('command_failed', ['detail' => "backup.sh --kopia-accept: $why"]);
    }
    logLine("Backup: the user said the Kopia repository is new - the hold of the Kopia step ended ($held[before] snapshots on "
        . date('Y-m-d H:i', $held['when']) . ", $held[now] then; repository " . substr($repo, 0, 16) . ')');
    return ['ok' => true, 'state' => backupScan()];
}

/**
 * Object Lock and Kopia's maintenance as the engine kept them (state/kopia-lock.json) — the facts in exactly their
 * shape: those of the file, or when Kopia couldn't be asked the last ones it kept (`last`); `looked` the file's time,
 * `ok` whether Kopia answered then. Null: nothing known.
 */
function backupKopiaLockRead(?string $file = null): ?array
{
    $j = readJson($file ?? BACKUP_DATA_DIR . '/state/kopia-lock.json');
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !is_int($j['time'] ?? null)) {
        return null;
    }
    $facts = ($j['ok'] ?? null) === true ? $j : (is_array($j['last'] ?? null) && ($j['last']['ok'] ?? null) === true ? $j['last'] : null);
    $out = ['looked' => $j['time'], 'ok' => ($j['ok'] ?? null) === true, 'lock' => null, 'maintenance' => null, 'identity' => ''];
    if ($facts === null) {
        return $out;
    }
    $l = $facts['lock'] ?? null;
    if (is_array($l) && in_array($l['mode'] ?? null, ['COMPLIANCE', 'GOVERNANCE'], true) && is_int($l['period_s'] ?? null) && $l['period_s'] > 0) {
        $out['lock'] = ['mode' => $l['mode'], 'period_s' => $l['period_s']];
    }
    $m = $facts['maintenance'] ?? null;
    if (is_array($m) && is_bool($m['extend'] ?? null) && is_bool($m['full_enabled'] ?? null) && is_int($m['full_interval_s'] ?? null)) {
        $times = fn (mixed $l): array => array_values(array_filter(array_map('backupKopiaTime', is_array($l) ? $l : []), 'is_int'));
        $out['maintenance'] = ['owner' => is_string($m['owner'] ?? null) && preg_match('/^[^\x00-\x1f]{0,200}$/uD', $m['owner']) ? $m['owner'] : '',
            'extend' => $m['extend'], 'full_enabled' => $m['full_enabled'], 'full_interval_s' => max(0, $m['full_interval_s']),
            'next_full' => backupKopiaTime($m['next_full'] ?? null),
            'last_full' => ($t = $times($m['full_runs'] ?? null)) ? max($t) : null,
            'last_extend' => ($t = $times($m['extend_runs'] ?? null)) ? max($t) : null];
    }
    $out['identity'] = is_string($facts['identity'] ?? null) && preg_match('/^[^\x00-\x1f]{1,200}$/uD', $facts['identity']) ? $facts['identity'] : '';
    return $out;
}

/** One of Kopia's times (RFC 3339 with up to nanoseconds, «2026-10-17T22:23:35.88171268+02:00») as a Unix time — else null */
function backupKopiaTime(mixed $s): ?int
{
    if (!is_string($s) || !preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d{1,9})?(Z|[+-]\d\d:\d\d)$/D', $s, $m)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $m[1] . ($m[2] === 'Z' ? '+00:00' : $m[2]));
    return $d === false ? null : $d->getTimestamp();
}

/**
 * Whether Kopia still extends the Object Lock (only at its full maintenance): nothing without a lock. A must:
 * extension or full maintenance off (`kopia_lock_extend`); the locks run out within the margin (`kopia_lock_lapse`:
 * the last extension + the period — Kopia's maintenance owner another user@host, the Kopia server stopped …).
 * A recommendation: the full maintenance late (`kopia_lock_late`: later than its interval by more than 12 hours, or never
 * `kopia_lock_never`: never extended yet, its first full maintenance long overdue) while the locks still hold. Facts once known stay the measure while Kopia can't be asked.
 *
 * @return list<array> the findings
 */
function backupKopiaLockJudge(?array $j, int $now, ?string $name = null): array
{
    $m = $j['maintenance'] ?? null;
    if (!is_array($j) || $j['lock'] === null || !is_array($m)) {
        return [];
    }
    $period = $j['lock']['period_s'];
    $base = ['name' => $name ?? 'kopia', 'mode' => $j['lock']['mode'],
             'owner' => $m['owner'] !== '' ? $m['owner'] : '–', 'me' => $j['identity'] !== '' ? $j['identity'] : '–'];
    $out = [finding('kopia_lock_extend', 'required', $m['extend'] && $m['full_enabled'], $base, 'docker')];
    if (!$m['extend'] || !$m['full_enabled']) {
        return $out;
    }
    $last = $m['last_extend'];
    $interval = $m['full_interval_s'] > 0 ? $m['full_interval_s'] : 86400;
    if ($last !== null) {
        $until = $last + $period;
        $margin = min(max(BACKUP_LOCK_MARGIN, intdiv($period, 4)), intdiv($period, 2));
        // the dates written out for a notification; *_t the times the Team Lead's page writes in the reader's language
        $p = $base + ['last' => date('Y-m-d H:i', $last), 'until' => date('Y-m-d H:i', $until), 'last_t' => $last, 'until_t' => $until];
        $out[] = finding('kopia_lock_lapse', 'required', $until - $now >= $margin, $p, 'docker');
        if ($until - $now >= $margin) {
            $out[] = finding('kopia_lock_late', 'recommended', $now - $last <= $interval + BACKUP_LOCK_LATE, $p, 'docker');
        }
    } elseif ($m['next_full'] !== null) {
        // never extended yet (a fresh repository): said only once its first full maintenance is long overdue
        $out[] = finding('kopia_lock_never', 'recommended', $now - $m['next_full'] <= BACKUP_LOCK_LATE,
            $base + ['next' => date('Y-m-d H:i', $m['next_full']), 'next_t' => $m['next_full']], 'docker');
    }
    return $out;
}

/** Is a new look at Object Lock and Kopia's maintenance due (backup.sh --kopia-lock)? $j: backupKopiaLockRead() */
function backupLockDue(?array $j, int $now): bool
{
    if ($j === null || $j['looked'] > $now) {
        return true;
    }
    $age = $now - $j['looked'];
    return $age >= (!$j['ok'] ? BACKUP_LOCK_RETRY : ($j['lock'] !== null ? BACKUP_LOCK_EVERY : BACKUP_LOCK_PLAIN));
}

/**
 * His tick: a few times a day the engine asks Kopia about Object Lock and its maintenance (backup.sh --kopia-lock through
 * atd, read only for Kopia) — only while Kopia is on, never while the engine's lock is held (a run asks itself after its
 * Kopia phase), never with the engine's folder on a sleeping disk. Looked at every 10 minutes, launched at most hourly.
 */
function backupLockTick(?int $now = null): ?string
{
    static $looked = 0, $launched = 0;
    $now ??= time();
    if ($now - $looked < BACKUP_LOCK_LOOK || $now - $launched < BACKUP_LOCK_RETRY) {
        return null;
    }
    $looked = $now;
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh") || backupHomeAsleep($data) || !is_file("$data/settings.ini")
        || !backupSettingsSummary(backupReadSettings("$data/settings.ini"))['kopia_enabled']
        || !backupLockDue(backupKopiaLockRead(), $now) || flockHeld("$data/state/lock")
        || version_compare((string) (backupAbout($dir)['version'] ?? '0'), BACKUP_GUARD_ENGINE, '<')) {
        return null;
    }
    $launched = $now;
    backupLaunch(["$dir/backup.sh", '--kopia-lock']);
    return 'launched';
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
        // a plan that failed writes no setup-plan.json: its messages are all the page gets (text as the engine says it)
        'messages' => array_values(array_slice(array_filter(array_map(
            fn ($m) => is_array($m) && is_string($m['text'] ?? null)
                ? ['level' => in_array($m['level'] ?? '', ['ok', 'hint', 'warn', 'error'], true) ? $m['level'] : 'hint',
                   'step' => preg_replace('/[^a-z_]/', '', (string) ($m['step'] ?? '')), 'text' => mb_substr(strip_tags($m['text']), 0, 300)]
                : null,
            is_array($s['messages'] ?? null) ? $s['messages'] : []
        )), 0, 20)),
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
    $st = backupSetupStatus();
    // never a plan the engine refuses; never over an Apply that ended «errors» (engine 2.67): its messages stay until the user acts
    $free = $holder === null && !$st['running'] && backupTrustState($data) === null
        && !($st['mode'] === 'apply' && $st['result'] === 'errors' && $st['written']);
    $replan = backupSetupReplan($plan, $state, $note, $free, time());
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
    // 1.63.0: a partnership ended and paired anew at the Team Lead since the plan — the plan names the old pair, the page
    // shows no change while settings.ini still sends to the gone one: made anew too (backupSetupPairsReplan())
    if (!$replan['start'] && function_exists('partnerPairs') && @lstat(partnerDir() . '/pairs.json') !== false) {
        $pairsFile = "$data/state/" . BACKUP_REPLAN_PAIRS_FILE;
        $pairsNote = readJson($pairsFile);
        $sending = array_column(array_filter(partnerPairs(), fn ($p) => $p['my_key'] !== null), 'id');
        $again = backupSetupPairsReplan($replan['plan'], $state, $sending, $pairsNote, $free, time());
        if ($again['start']) {
            try {
                writeAtomic($pairsFile, jsonEncode($again['note']), 0600, 0, 0);
                backupLaunch([BACKUP_SCRIPT_DIR . '/setup.sh', '--plan'], ['UB_SIZE_TIMEOUT' => 0]);
                logLine('Backup: the partnerships changed at the Team Lead since the plan (' . implode(', ', $sending) . ') — setup.sh --plan started via at (once)');
                backupSetupWait();
                $replan['plan'] = null;
            } catch (Throwable $e) {
                logLine('Backup: the plan anew after the partnerships changed could not start: ' . $e->getMessage() . ' (not again for these pairs)');
            }
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
        $plan['place_offer'] = backupPlaceOffer();      // the office's share while Unraid has none: the setup's first step offers it
        $plan['notes'] = backupSetupNotes($plan['messages'] ?? null);   // the messages grouped, for the page
        $plan = backupPlanVolumeCaches($plan);
        // the units the pairs sent up to 1.59 (the step partner-simple): proposed at «local + partner» until applied
        $plan['migrated'] = function_exists('partnerMigratedPending') ? (object) partnerMigratedPending() : (object) [];
        $plan['declined'] = (object) backupDeclined();     // 1.60.1: the proposals the user declined, with the value proposed
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
 * the English). Docker volumes are judged here (`backupVolumeItems()`): cache or real data. Engine 2.58 codes the OK and
 * error lines too. A message without a code (an older engine's OK and error lines or plan, a code of an unknown shape)
 * stays an entry of its own with its text — never lost. Pure.
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
            // 2026-10-17: a cache that fills itself again is nothing to fix - a hint, never counted as a warning
            $lv = $c === 'volumes_cache' && $level === 'warn' ? 'hint' : $level;
            $k = "$lv|$c";
            if (!isset($at[$k])) {
                $out[] = ['level' => $lv, 'step' => $step, 'code' => $c, 'items' => []];
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

/**
 * The plan's containers with their cache volumes (2026-10-17, a user scared his photos were not backed up: the app row's
 * red chip «Docker volumes: /cache» was immich's model cache): `volumes_cache` lists the paths of `volumes` (paths in the
 * container) that are caches by `backupVolumeIsCache()` - the one rule; a volume's name comes from the plan's
 * `docker_volumes` message of the same app (the plan's container lists paths only). The page shows those grey, the rest
 * red. Pure.
 */
function backupPlanVolumeCaches(array $plan): array
{
    if (!is_array($plan['containers'] ?? null)) {
        return $plan;
    }
    $names = [];            // app → path → volume name
    foreach (is_array($plan['messages'] ?? null) ? $plan['messages'] : [] as $m) {
        if (!is_array($m) || ($m['code'] ?? '') !== 'docker_volumes' || !is_array($m['params']['volumes'] ?? null) || !is_scalar($m['params']['app'] ?? null)) {
            continue;
        }
        foreach ($m['params']['volumes'] as $v) {
            if (is_array($v) && is_string($v['target'] ?? null) && is_string($v['name'] ?? null)) {
                $names[(string) $m['params']['app']][$v['target']] = $v['name'];
            }
        }
    }
    foreach ($plan['containers'] as &$ct) {
        if (!is_array($ct)) {
            continue;
        }
        $name = is_string($ct['name'] ?? null) ? $ct['name'] : '';
        $ct['volumes_cache'] = array_values(array_filter(is_array($ct['volumes'] ?? null) ? $ct['volumes'] : [],
            fn ($t) => is_string($t) && $t !== '' && backupVolumeIsCache($t, $names[$name][$t] ?? '')));
    }
    unset($ct);
    return $plan;
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

const BACKUP_REPLAN_PAIRS_FILE = 'setup-replan-pairs.json';   // in the engine's state/: the pairs the office last planned anew for

/**
 * 1.63.0 (a re-pairing on the test servers): the setup's plan names the partners as the Team Lead paired them when it was
 * made (plan.partners). A partnership ended and paired anew since — the plan still has the old id, the draft equals
 * settings.ini, the page shows no change and offers no Apply, while the Team Lead says units wait. When the pairs this
 * office sends to ($sending, ids) aren't the plan's, the plan is made anew on the next look — its setup.sh hands the old
 * pair's units to the new one (hint partner_anew) and the page proposes it. Once per set of pairs, whatever comes of it:
 * the note {pairs, at} is written before setup.sh starts (a failing plan is not tried again at every look). Only for a
 * plan of an engine that names its partners (2.27+), found, compatible, nothing running, the lock free.
 * Pure: $plan as read, $state the desk's, $note the last one.
 *
 * @return array{start: bool, note: ?array}
 */
function backupSetupPairsReplan(?array $plan, array $state, array $sending, ?array $note, bool $free, int $now): array
{
    if (!$plan || !is_array($plan['partners'] ?? null) || empty($state['found']) || empty($state['compatible']) || !empty($state['running']) || !$free) {
        return ['start' => false, 'note' => $note];
    }
    $planned = [];
    foreach ($plan['partners'] as $p) {
        if (is_string($p['id'] ?? null) && preg_match('/^[0-9a-f]{8}$/D', $p['id'])) {
            $planned[] = $p['id'];
        }
    }
    $planned = array_values(array_unique($planned));
    $paired = array_values(array_unique(array_filter($sending, fn ($id) => is_string($id) && preg_match('/^[0-9a-f]{8}$/D', $id))));
    sort($planned);
    sort($paired);
    $fp = implode(',', $paired);
    if ($planned === $paired || (is_array($note) && ($note['pairs'] ?? null) === $fp)) {
        return ['start' => false, 'note' => $note];
    }
    return ['start' => true, 'note' => ['pairs' => $fp, 'at' => $now]];
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
 * The office's own share as the backup place, while Unraid has none of that name (owner 2026-10-21, a fresh server: the
 * missing place was a hint among everything else — the setup now starts with it). Where it would go, the five points in
 * order: a ZFS pool first (the packages' earlier backups in snapshots; only a dataset travels to a partner), one appdata
 * doesn't lie on when there is another, a mirror before a single drive; no ZFS pool: another pool (btrfs first); no pool:
 * the array. Only on that pool, no secondary storage — exclusive when Unraid permits exclusive shares (`exclusive`,
 * ⟦Global Share Settings⟧). `why` says what is second best: '' none,
 * `same_pool` (appdata lies on every ZFS pool), `no_zfs` (no ZFS pool here), `no_pool` (no pool: the array). `fresh`
 * false: Unraid knew the share (its cfg on the flash, Docker's or the VMs' paths) — nothing offered, what was there may
 * come back (lib/firsthome.php). `asleep`: the pool (or a disk of the array) sleeps — a click is refused, never woken.
 * Cheap: shares.ini, the flash's cfgs, disks.ini — never a disk.
 *
 * @return array{share: string, fresh: bool, pool: ?string, fs: string, why: string, mirror: bool, asleep: bool, exclusive: bool, appdata: ?string}|null
 */
function backupPlaceOffer(?array $h = null): ?array
{
    $h ??= homeHost();
    $state = homeShareState(BACKUP_OFFICE_SHARE, $h);
    if ($state === null) {
        return null;
    }
    $disks = readCfg($h['disks_ini'], true);
    $asleep = [];
    foreach ($disks as $id => $d) {
        $asleep[(string) ($d['name'] ?? $id)] = diskAsleep($d);
    }
    // appdata's pools: its primary and secondary storage as its cfg on the flash says (any case, like Unraid's names)
    $appdata = [];
    foreach (glob($h['shares_dir'] . '/*.cfg') ?: [] as $f) {
        if (strcasecmp(basename($f, '.cfg'), 'appdata') === 0) {
            $c = readCfg($f);
            if (strtolower((string) ($c['shareUseCache'] ?? 'no')) !== 'no') {
                $appdata[] = (string) ($c['shareCachePool'] ?? '');
            }
            $appdata[] = (string) ($c['shareCachePool2'] ?? '');
        }
    }
    $appdata = array_values(array_filter($appdata));
    $best = null;
    foreach (homePools($h) as $pool) {
        $fs = preg_replace('/^luks:/', '', strtolower((string) ($disks[$pool]['fsType'] ?? '')));
        $mirror = (bool) preg_match('/^(mirror|raidz|draid|raid1|raid5|raid6|raid10)/i', (string) ($disks[$pool]['fsProfile'] ?? ''));
        $rank = [$fs === 'zfs' ? 2 : ($fs === 'btrfs' ? 1 : 0), in_array($pool, $appdata, true) ? 0 : 1, $mirror ? 1 : 0];
        if ($best === null || $rank > $best['rank']) {
            $best = ['rank' => $rank, 'pool' => $pool, 'fs' => $fs, 'mirror' => $mirror];
        }
    }
    $arrayAsleep = false;
    foreach ($asleep as $id => $sleeping) {
        $arrayAsleep = $arrayAsleep || ($sleeping && preg_match('/^disk\d+$/D', (string) $id));
    }
    $appdataPool = $appdata[0] ?? null;
    if ($best === null) {
        return ['share' => BACKUP_OFFICE_SHARE, 'fresh' => $state['fresh'], 'pool' => null, 'fs' => '', 'why' => 'no_pool', 'mirror' => false,
            'asleep' => $arrayAsleep, 'exclusive' => false, 'appdata' => $appdataPool];
    }
    $why = $best['rank'][0] < 2 ? 'no_zfs' : ($best['rank'][1] === 0 ? 'same_pool' : '');
    return ['share' => BACKUP_OFFICE_SHARE, 'fresh' => $state['fresh'], 'pool' => $best['pool'], 'fs' => $best['fs'], 'why' => $why,
        'mirror' => $best['mirror'], 'asleep' => baseAsleep($best['pool'], $asleep),
        'exclusive' => strtolower((string) (readCfg($h['share_cfg'])['shareUserExclusive'] ?? '')) === 'yes', 'appdata' => $appdataPool];
}

/**
 * «Create UnraidSecretaryOffice…» on the setup's first step: the office's share made by Unraid's own «Add Share» (lib/
 * firsthome.php homeMake(): emcmd, the fields Unraid's form sends; only on the pool, no secondary storage, or the array
 * for '') — the one share the office makes besides Unraid's standard ones, only on this click, never by
 * mkdir. Looked at again first: refused when the share is there (place_exists) or Unraid knew it (place_known), the pool
 * isn't one of Unraid's (place_pool_unknown) or it sleeps (place_pool_asleep, place_array_asleep: never woken). The page
 * then plans anew — the engine proposes the new office's share as the backup place.
 */
function backupPlaceMake(string $pool, ?array $h = null): array
{
    $h ??= homeHost();
    $offer = backupPlaceOffer($h);
    if ($offer === null) {
        throw new Problem('place_exists', ['share' => BACKUP_OFFICE_SHARE]);
    }
    if (!$offer['fresh']) {
        throw new Problem('place_known', ['share' => BACKUP_OFFICE_SHARE]);
    }
    if ($pool !== '' && !in_array($pool, homePools($h), true)) {
        throw new Problem('place_pool_unknown', ['pool' => mb_substr($pool, 0, 40)]);
    }
    $asleep = [];
    foreach (readCfg($h['disks_ini'], true) as $id => $d) {
        $asleep[(string) ($d['name'] ?? $id)] = diskAsleep($d);
    }
    $sleeps = $pool !== '' ? baseAsleep($pool, $asleep)
        : (bool) array_filter($asleep, fn ($s, $id) => $s && preg_match('/^disk\d+$/D', (string) $id), ARRAY_FILTER_USE_BOTH);
    if ($sleeps) {
        throw $pool !== '' ? new Problem('place_pool_asleep', ['pool' => $pool]) : new Problem('place_array_asleep');
    }
    $r = homeMake(BACKUP_OFFICE_SHARE, $pool !== '' ? $pool : null, $h);
    if (!$r['ok']) {
        logLine('Backup: Unraid did not create the share ' . BACKUP_OFFICE_SHARE . ' (' . ($r['error']['key'] ?? '?') . ')');
        throw new Problem($r['error']['key'], $r['error']['params']);
    }
    logLine('Backup: created the share ' . BACKUP_OFFICE_SHARE . " on the user's click (Unraid's «Add Share», "
        . ($pool !== '' ? "only on the pool $pool" : 'on the array') . ') as the backup place');
    return ['ok' => true, 'made' => BACKUP_OFFICE_SHARE, 'pool' => $pool];
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
            // 2.45: an app's own folder dataset too
            || ((preg_match('/^(share|vm|app)\|(.+)\|partner$/D', $key, $m) && in_array($m[2], $m[1] === 'share' ? $shares : ($m[1] === 'vm' ? $vms : $apps), true)
                 || $key === 'general|partner_place')
                && is_array($value) && !array_diff($value, $partners));
        // engine 2.28: sleeping pools - woken for the snapshot, or left out that night
        if ($key === 'general|asleep_pools' && !in_array($value, ['wake', 'skip'], true)) {
            $ok = false;
        }
        // engine 2.31: the default for new things - my proposals, local only, local + Kopia; 2.42 local + partner, both
        if ($key === 'general|preset_new' && !in_array($value, ['auto', 'local', 'kopia', 'partner', 'kopia_partner'], true)) {
            $ok = false;
        }
        $plain = fn ($v) => is_string($v) && strlen($v) <= 500 && !preg_match('/[\x00-\x1f]/', $v);
        $valid = $plain($value) || (is_array($value) && array_is_list($value) && count($value) <= 1000 && !in_array(false, array_map($plain, $value), true));
        if (!$ok || !$valid) {
            throw new Problem('setup_bad_decisions', ['detail' => $key]);
        }
        $clean[$key] = $value;
    }
    // engine 2.50: what goes to a partner keeps at least one local snapshot (said here before the engine refuses it)
    if (($unit = backupZeroPartner($clean)) !== null) {
        throw new Problem('setup_zero_partner', ['name' => $unit]);
    }
    $file = "$data/state/setup-decisions.json";
    writeAtomic($file, jsonEncode($clean), 0600, 0, 0);
    backupLaunch(["$dir/setup.sh", "--apply=$file"], ['UB_SIZE_TIMEOUT' => 0]);
    logLine('Backup: setup.sh --apply started via at (' . count($clean) . ' decisions)');
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/**
 * Engine 2.50 (review 2026-10-15, R3-8): «0 0 0» local snapshots only for what goes to no partner office — a partner gets
 * only what changed since the snapshot both sides still have, and with none kept everything would go over in full every
 * time. The same rule as the engine's cfg_validate (unit_retention): a VM's own retention, else its share's (an app's
 * first folder's, the backup place's), else [zfs] retention (default «7 4 6»). The name of the first such unit, else null.
 *
 * @param array<string, mixed> $d settings.ini keys as the setup's decisions carry them
 */
function backupZeroPartner(array $d): ?string
{
    $str = fn (string $k): string => is_string($d[$k] ?? null) ? trim($d[$k]) : '';
    $ret = function (string $share) use ($str): string {
        $own = $share !== '' ? $str("share|$share|retention") : '';
        return $own !== '' ? $own : ($str('zfs|retention') !== '' ? $str('zfs|retention') : '7 4 6');
    };
    foreach ($d as $key => $value) {
        if (!is_array($value) || !preg_grep('/^[0-9a-f]{8}$/D', array_filter($value, 'is_string'))) {
            continue;
        }
        if ($key === 'general|partner_place') {
            [$name, $r] = [$str('general|dumps_share'), $ret($str('general|dumps_share'))];
        } elseif (preg_match('/^share\|(.+)\|partner$/D', (string) $key, $m)) {
            [$name, $r] = [$m[1], $ret($m[1])];
        } elseif (preg_match('/^vm\|(.+)\|partner$/D', (string) $key, $m)) {
            [$name, $r] = [$m[1], $str("vm|{$m[1]}|retention") !== '' ? $str("vm|{$m[1]}|retention") : $ret('')];
        } elseif (preg_match('/^app\|(.+)\|partner$/D', (string) $key, $m)) {
            $folders = $d["app|{$m[1]}|folder"] ?? [];
            $first = is_array($folders) ? (string) (array_values(array_filter($folders, 'is_string'))[0] ?? '') : (is_string($folders) ? $folders : '');
            [$name, $r] = [$m[1], $ret(explode('/', $first)[0])];
        } else {
            continue;
        }
        if (preg_match('/^(\d+) (\d+) (\d+)$/D', $r, $n) && (int) $n[1] + (int) $n[2] + (int) $n[3] === 0) {
            return $name;
        }
    }
    return null;
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

// ===================================================================== old Kopia sources (setup.sh --orphans, engine 2.51)

const BACKUP_ORPHANS_ENGINE = '2.51';
const BACKUP_ORPHANS_FILE = 'kopia-orphans.json';
const BACKUP_ORPHANS_CHOSEN = 'kopia-orphans-chosen.json';

/**
 * The engine's list of the office's own Kopia sources whose share, app or VM is gone or (engine 2.59) no longer goes
 * offsite (state/kopia-orphans.json) — only entries in exactly its shape; `running` only while its pid lives and the
 * engine's lock is held (else a run killed hard: failed). Null before the first look.
 *
 * @return ?array{mode: string, result: string, code: string, running: bool, started: int, finished: int, identity: string,
 *                sources: list<array{source: string, path: string, kind: string, name: string, snapshots: int, bytes: int, newest: string, why: string}>,
 *                deleted: list<string>, failed: list<string>, refused: list<string>}
 */
function backupOrphans(): ?array
{
    $data = BACKUP_DATA_DIR;
    $j = readJson("$data/state/" . BACKUP_ORPHANS_FILE);
    if (!is_array($j) || ($j['v'] ?? null) !== 1) {
        return null;
    }
    $result = in_array($j['result'] ?? '', ['running', 'ok', 'failed', 'busy'], true) ? $j['result'] : 'failed';
    $running = $result === 'running' && posix_kill((int) ($j['pid'] ?? 0), 0) && flockHeld("$data/state/lock");
    if ($result === 'running' && !$running) {
        $result = 'failed';
    }
    $str = fn ($v) => is_string($v) && $v !== '' && strlen($v) <= 1000 && !preg_match('/[\x00-\x1f]/', $v);
    $strs = fn ($l) => is_array($l) ? array_values(array_filter($l, $str)) : [];
    $sources = [];
    foreach (is_array($j['sources'] ?? null) ? $j['sources'] : [] as $x) {
        // engine 2.59: why gone | not_offsite (the unit is there, only its older snapshots lie in Kopia); an older engine
        // lists only gone ones and writes no why; the flash is only ever not_offsite
        $why = is_array($x) && array_key_exists('why', $x) ? $x['why'] : 'gone';
        if (is_array($x) && $str($x['source'] ?? null) && $str($x['path'] ?? null) && in_array($x['kind'] ?? '', ['share', 'app', 'vm', 'flash'], true)
            && $str($x['name'] ?? null) && is_int($x['snapshots'] ?? null) && is_int($x['bytes'] ?? null) && is_string($x['newest'] ?? null)
            && in_array($why, ['gone', 'not_offsite'], true) && !($x['kind'] === 'flash' && $why !== 'not_offsite')) {
            $sources[] = ['source' => $x['source'], 'path' => $x['path'], 'kind' => $x['kind'], 'name' => $x['name'],
                'snapshots' => $x['snapshots'], 'bytes' => $x['bytes'], 'newest' => $x['newest'], 'why' => $why];
        }
    }
    return [
        'mode' => ($j['mode'] ?? '') === 'delete' ? 'delete' : 'list',
        'result' => $result,
        'code' => is_string($j['code'] ?? null) ? preg_replace('/[^a-z_]/', '', $j['code']) : '',
        'running' => $running,
        'started' => (int) ($j['started'] ?? 0),
        'finished' => (int) ($j['finished'] ?? 0),
        'identity' => is_string($j['identity'] ?? null) ? $j['identity'] : '',
        'sources' => $sources,
        'deleted' => $strs($j['deleted'] ?? null),
        'failed' => $strs($j['failed'] ?? null),
        'refused' => $strs($j['refused'] ?? null),
    ];
}

/** The engine's setup.sh, refused while anything holds its lock (a backup run, a setup, a restore) or too old for --orphans */
function backupOrphansReady(): string
{
    $dir = backupCheckReady();
    if (version_compare((string) (backupAbout($dir)['version'] ?? '0'), BACKUP_ORPHANS_ENGINE, '<')) {
        throw new Problem('backup_too_old', ['version' => backupAbout($dir)['version'] ?? '?']);
    }
    return $dir;
}

/** Asks the engine which old sources of the office's are in the Kopia repository — writes nothing but its list */
function backupOrphansLook(): array
{
    $dir = backupOrphansReady();
    backupLaunch(["$dir/setup.sh", '--orphans']);
    logLine('Backup: setup.sh --orphans started via at (old Kopia sources)');
    return ['ok' => true, 'started' => backupOrphansWait(), 'orphans' => backupOrphans()];
}

/**
 * The ticked sources, when every one is in the list the page showed (`listed`: that list's start) — else orphans_stale.
 * idList() gives a plain list (until 1.65.12 its keys were read: 0, 1 … never offered, so every delete was refused).
 *
 * @param list<string> $ids
 * @return list<string>
 */
function backupOrphansChosen(array $ids, string $listed): array
{
    $o = backupOrphans();
    if ($o === null || $o['mode'] !== 'list' || $o['result'] !== 'ok' || (string) $o['started'] !== $listed) {
        throw new Problem('orphans_stale');
    }
    $chosen = array_values(array_unique(array_map('strval', $ids)));
    if (!$chosen || array_diff($chosen, array_column($o['sources'], 'source'))) {
        throw new Problem('orphans_stale');
    }
    return $chosen;
}

/**
 * Deletes the old sources the user ticked — every one of them must be in the list the page showed (`listed`: that list's
 * start, so a list made anew meanwhile is refused) and the engine lists anew and deletes only what it offers right then.
 * For good: all their snapshots in the Kopia repository.
 *
 * @param list<string> $ids the sources (user@host:path), as idList() gives them
 */
function backupOrphansDelete(array $ids, string $listed): array
{
    $dir = backupOrphansReady();
    $data = BACKUP_DATA_DIR;
    $chosen = backupOrphansChosen($ids, $listed);
    writeAtomic("$data/state/" . BACKUP_ORPHANS_CHOSEN, jsonEncode(array_values($chosen)), 0600, 0, 0);
    backupLaunch(["$dir/setup.sh", "--orphans-delete=$data/state/" . BACKUP_ORPHANS_CHOSEN]);
    logLine('Backup: setup.sh --orphans-delete started via at — deleting in the Kopia repository: ' . implode(', ', $chosen));
    return ['ok' => true, 'started' => backupOrphansWait(), 'orphans' => backupOrphans()];
}

/** Until setup.sh's list or deletion reports that it runs (a moment), or gives up */
function backupOrphansWait(): bool
{
    $data = BACKUP_DATA_DIR;
    $before = time() - 1;
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        clearstatcache();
        $j = readJson("$data/state/" . BACKUP_ORPHANS_FILE);
        if ((int) ($j['started'] ?? 0) >= $before) {
            return true;
        }
    }
    return false;
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
