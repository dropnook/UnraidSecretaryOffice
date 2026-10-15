<?php
declare(strict_types=1);

/*
 * What the office is doing right now, for the tile on Unraid's Dashboard (`office.jobs`, src/dashboard.php): one entry
 * per running job of a hired desk — Mr. Backupsy's run, Mr. Restori's restore and drill, Ms. Snapshotini's plans,
 * Jack Streamer's runs, Ms. Moverelli's run and the night run. Read from what the jobs already write (their run markers,
 * status files, the progress in RAM) and the processes behind them — nothing written, no disk asked: every file lies in
 * the data folder or in RAM, the same the desks' own polls read. A desk whose look fails is left out (logged).
 */

/**
 * @return list<array{desk:string, kind:string}>  the running jobs, each with what its line needs
 */
function officeJobs(?array $hired = null): array
{
    $hired ??= staffHired();
    $parts = [
        'backup'    => fn () => officeJobsBackup(BACKUP_DATA_DIR),
        'restore'   => fn () => officeJobsRestore(),
        'snapshot'  => fn () => officeJobsSnapshot(RUN_DIR . '/snapshot-plans.lock'),
        'jack'      => fn () => officeJobsJack(),
        'moverelli' => fn () => defined('MOVERELLI_DATA') ? officeJobsMoverelli(MOVERELLI_DATA) : [],
    ];
    $out = [];
    foreach ($parts as $desk => $look) {
        if (!in_array($desk, $hired, true)) {
            continue;
        }
        try {
            array_push($out, ...$look());
        } catch (Throwable $e) {
            logLine("office.jobs: $desk: " . $e->getMessage());
        }
    }
    return $out;
}

/** Mr. Backupsy's run: the engine holds its lock as a backup (not setup, a restore or the recovery after a start) and says so in status.json */
function officeJobsBackup(string $data, ?array $holder = null, ?array $status = null): array
{
    $holder ??= backupLockHolder($data);
    if ($holder === null || in_array($holder['holder'], ['setup', 'restore'], true) || ($holder['holder'] === 'backup' && $holder['mode'] === 'recover')) {
        return [];
    }
    $status ??= readJson("$data/state/status.json") ?? [];
    $running = ($status['result'] ?? '') === 'running';
    $kopia = (array) ($status['kopia'] ?? []);
    $count = fn (mixed $v): ?int => is_array($v) ? count($v) : null;
    return [['desk' => 'backup', 'kind' => 'backup',
             'mode'    => $running ? (string) ($status['mode'] ?? '') : '',
             'phase'   => $running ? (string) ($status['phase'] ?? '') : '',
             'done'    => $running ? $count($kopia['done'] ?? null) : null,
             'total'   => $running ? $count($kopia['planned'] ?? null) : null,
             'current' => $running && is_string($kopia['current'] ?? null) ? $kopia['current'] : null,
             'partner' => $running && ($status['phase'] ?? '') === 'partner' && is_array($status['partner'] ?? null) ? $status['partner'] : null,
             'started' => (int) (($status['started'] ?? 0) ?: $holder['started'])]];
}

/** How far a job's steps are: the last one begun (running, ended or skipped) of all of them */
function officeJobsSteps(array $steps): array
{
    $at = 0;
    foreach (array_values($steps) as $i => $s) {
        if (is_array($s) && ($s['state'] ?? 'pending') !== 'pending') {
            $at = $i + 1;
        }
    }
    return ['step' => $at, 'steps' => count($steps)];
}

/** Mr. Restori: the restore going on (its job lives), the drill going on */
function officeJobsRestore(): array
{
    $out = [];
    if (function_exists('rsRunningJob') && ($j = rsRunningJob()) !== null) {
        $out[] = ['desk' => 'restore', 'kind' => 'restore', 'what' => $j['what'], 'step' => $j['step'], 'steps' => $j['steps']];
    }
    if (function_exists('drillRunning') && ($d = drillRunning()) !== null) {
        $out[] = ['desk' => 'restore', 'kind' => 'drill'] + officeJobsSteps((array) ($d['steps'] ?? []));
    }
    return $out;
}

/** Ms. Snapshotini's plans at work (their round holds its lock in RAM while it takes the due ones) */
function officeJobsSnapshot(string $lock): array
{
    return is_file($lock) && flockHeld($lock) ? [['desk' => 'snapshot', 'kind' => 'plans']] : [];
}

/**
 * Jack Streamer: EmbyCache at work (a real run — fetch or give back — with its live panel: the whole run's percent by
 * bytes and the file it is on; other modes only by name) and the gather
 */
function officeJobsJack(?array $jobs = null, ?array $progress = null): array
{
    if ($jobs === null && !function_exists('jackJobInfo')) {
        return [];
    }
    $jobs ??= ['embycache' => jackJobInfo('embycache'), 'gather' => jackJobInfo('gather')];
    $out = [];
    $e = (array) ($jobs['embycache'] ?? []);
    if (!empty($e['running'])) {
        $progress ??= jackProgressState($e)['progress'];
        $p = is_array($progress) ? $progress : null;
        $total = (array) ($p['total'] ?? []);
        $bytes = (int) ($total['bytes'] ?? 0);
        $cur = is_array($p['current'] ?? null) ? $p['current'] : null;
        $out[] = ['desk' => 'jack', 'kind' => 'embycache', 'mode' => (string) ($e['mode'] ?? ''), 'by' => (string) ($e['by'] ?? ''),
                  'phase'   => (string) ($p['phase'] ?? ''),
                  'percent' => $p !== null && $bytes > 0 ? min(100, (int) floor(100 * (int) ($total['done_bytes'] ?? 0) / $bytes)) : null,
                  'file'    => $cur !== null && ($cur['rel'] ?? '') !== '' ? basename((string) $cur['rel']) : null,
                  'back'    => $cur !== null && ($cur['phase'] ?? '') === 'back'];
    }
    $g = (array) ($jobs['gather'] ?? []);
    if (!empty($g['running'])) {
        $out[] = ['desk' => 'jack', 'kind' => 'gather', 'mode' => (string) ($g['mode'] ?? '')];
    }
    return $out;
}

/**
 * Ms. Moverelli: her own run (Smart Mover's live status: the step — looking around, a share, tidying up — and how many
 * files it moved so far) or the night run (its step, who is at work; her step with her live status too)
 */
function officeJobsMoverelli(string $dir, ?array $job = null, ?array $night = null): array
{
    $job ??= moverelliJobInfo($dir);
    $night ??= nightState($dir) ?? [];
    $nightOn = !empty($night['running']);
    if (empty($job['running']) && !$nightOn) {
        return [];
    }
    $mode = $nightOn ? (string) ($night['mode'] ?? '') : (string) ($job['mode'] ?? '');
    $herStep = $nightOn ? ($night['who'] ?? null) === 'her' : !empty($job['running']);
    $st = $herStep ? readJson("$dir/status.json") : null;
    $live = is_array($st) && ($st['result'] ?? '') === 'running' ? $st : null;
    $moved = null;
    if ($live !== null) {
        $moved = (int) ($live['share_done_files'] ?? 0);
        foreach ((array) ($live['shares'] ?? []) as $s) {
            $moved += is_array($s) ? (int) ($s['moved'] ?? 0) : 0;
        }
    }
    return [['desk' => 'moverelli', 'kind' => $nightOn ? 'night' : 'run', 'mode' => $mode,
             'phase' => $nightOn ? (string) ($night['phase'] ?? '') : (string) ($live['phase'] ?? ''),
             'share' => $live !== null && is_string($live['share'] ?? null) ? $live['share'] : null,
             'moved' => $mode === 'run' ? $moved : null]
             // the night: whose step (jack, her, null between) and whether Jack Streamer is in it — the step's name as on her
             // card (core.js Office.nightSteps(), 2026-10-15)
             + ($nightOn ? ['who' => in_array($night['who'] ?? null, ['jack', 'her'], true) ? $night['who'] : null,
                            'with_jack' => !empty($night['jack']) && empty($night['jack_out'])] : [])];
}
