<?php
declare(strict_types=1);

/*
 * The order of the scheduled runs (2026-10-21; seen on a real server: the backup and Ms. Moverelli's run both at 02:00,
 * side by side): **Ms. Moverelli first, then Mr. Backupsy.** The engine snapshots its bases one after another — a file the
 * mover moves between two of them is in neither (or in both); and a pool snapshot taken before she moves holds exactly what
 * she moves, so her run frees nothing. Moved first, then snapshotted, the pool has its room from that snapshot on.
 *
 *   the scheduled backup   (job.sh backup → `php agent.php job backup-order`, before the engine starts) waits while her run
 *                          goes (night.lock held, or her run waiting for Unraid's mover) or Unraid's mover is at work
 *                          (jackMoverRunning() — the look jackRunGate() has, Smart Mover included), and at a cron tie (her
 *                          line due in the same minute) gives her NIGHT_TIE_WAIT to take her lock; at most NIGHT_WAIT_MAX,
 *                          then it goes anyway. Its word for the run (the engine's UB_ORDER → status.json `order`, history):
 *                          waited_night | waited_mover | gave_up_night | gave_up_mover {seconds}.
 *   a backup started here  never waits; it says so when her run or the mover goes (beside_night | beside_mover).
 *   her run                (nightrun.php) waits only while a real backup takes its snapshots (the engine's status.json
 *                          `snapped`, 2.70 — containers stopped → snapshots → started again, and the btrfs disks nobody held
 *                          anything on after the restart); after that the snapshot is frozen and moving is safe. At most
 *                          NIGHT_BACKUP_WAIT_MAX; from her page refused at once (moverelli_backup_snapshots). Her word
 *                          (night.json `order`): waited_backup | gave_up_backup {seconds}.
 *   anyone else holding the engine (a restore, a drill, a let-go, the setup, a tour, a dry run): her run goes as before.
 *
 * The pages (nightOrderHint()): one quiet line where both schedules are set when the backup starts in the same minute as her
 * run or within the length of her last run.
 */

require_once __DIR__ . '/nightrun.php';

const NIGHT_WAIT_MAX        = 5400;    // s: the scheduled backup waits for her run (or the mover) at most 90 min
const NIGHT_BACKUP_WAIT_MAX = 1800;    // s: her run waits for the backup's snapshots at most 30 min
const NIGHT_TIE_WAIT        = 60;      // s: her line due in the backup's minute — the time she gets to take her lock
const NIGHT_ORDER_EVERY     = 15;      // s: how often a wait looks again
const NIGHT_ORDER_CODES     = ['waited_night', 'waited_mover', 'gave_up_night', 'gave_up_mover', 'beside_night', 'beside_mover'];
const NIGHT_ORDER_HER_CODES = ['waited_backup', 'gave_up_backup'];
// an engine before 2.70 (no `snapped`): the phases after all of a run's snapshots are taken
const NIGHT_ORDER_FROZEN    = ['partner', 'mounting', 'kopia', 'unmounting', 'cleanup', 'aborting', 'done'];

/** Where the order looks — the tests' stand-ins in $GLOBALS['nightOrderHost'] */
function nightOrderHost(?array $h = null): array
{
    $h ??= $GLOBALS['nightOrderHost'] ?? [];
    $dir = $h['night_dir'] ?? DATA_DIR . '/moverelli';
    $cron = $h['cron_file'] ?? OFFICE_CRON;
    return $h + [
        'night_dir'     => $dir,
        'cron_file'     => $cron,
        'backup_data'   => BACKUP_DATA_DIR,
        'waitdir'       => RUN_DIR,
        // her run going: its lock held, or it waits for Unraid's mover (jackRunGate()'s wait — it takes the lock before it lets go)
        'night_running' => fn (): bool => nightRunning($dir) || (function_exists('jackRunWaiting') && jackRunWaiting('moverelli') !== null),
        // her line (only while she is hired: a line left behind starts nothing)
        'night_cron'    => fn (): ?string => in_array('moverelli', staffHired($GLOBALS['agentStaffFile'] ?? null), true) ? officeJobSchedule('moverelli', $cron)['custom'] : null,
        'backup_cron'   => fn (): ?string => officeJobSchedule('backup', $cron)['custom'],
        'mover'         => fn (): bool => function_exists('jackMoverRunning') && jackMoverRunning(),
        'array'         => fn (): bool => arrayRunning(),
        'snapping'      => fn (): bool => nightOrderBackupSnapping($h['backup_data'] ?? BACKUP_DATA_DIR),
        'sleep'         => fn (int $s) => sleep($s),
        'now'           => fn (): int => time(),
        'max'           => NIGHT_WAIT_MAX,
        'her_max'       => NIGHT_BACKUP_WAIT_MAX,
        'tie'           => NIGHT_TIE_WAIT,
        'every'         => NIGHT_ORDER_EVERY,
    ];
}

/** Is $cron due in the minute of $t? */
function nightOrderDue(?string $cron, int $t): bool
{
    $f = $cron !== null ? cronFields($cron) : null;
    return $f !== null && cronMatches($f, $t);
}

/**
 * Before the scheduled backup (job.sh backup): wait for her run and Unraid's mover — see the top. Holds only its own wait
 * lock in RAM (RUN_DIR/backup-wait.lock; a second scheduled start meanwhile: `already`) and shows itself in backup-wait.json.
 *
 * @return array{go: bool, code: ?string, seconds: int, result?: string}
 *   go false: nothing to start now — result `array` (the array stopped), `meanwhile` (a backup started here meanwhile),
 *   `already` (another scheduled start waits)
 */
function nightOrderBackupGate(?array $h = null): array
{
    $h = nightOrderHost($h);
    $now = $h['now'];
    $since = $now();
    $tie = nightOrderDue(($h['night_cron'])(), $since);
    $look = function () use ($h, $now, $since, $tie): ?string {
        if (($h['night_running'])()) {
            return 'night';
        }
        if (($h['mover'])()) {
            return 'mover';
        }
        return $tie && $now() - $since < (int) $h['tie'] ? 'tie' : null;
    };
    $w = $look();
    if ($w === null) {
        return ['go' => true, 'code' => null, 'seconds' => 0];
    }
    $waitDir = $h['waitdir'];
    @mkdir($waitDir, 0700, true);
    $lock = @fopen("$waitDir/backup-wait.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return ['go' => false, 'code' => null, 'seconds' => 0, 'result' => 'already'];
    }
    $why = $w === 'tie' ? null : $w;          // what it really waited for (a tie alone she never took: nothing to say)
    $end = function (array $v) use ($lock, $waitDir): array {
        @unlink("$waitDir/backup-wait.json");
        flock($lock, LOCK_UN);
        fclose($lock);
        return $v;
    };
    $status = fn (): array => readJson($h['backup_data'] . '/state/status.json') ?? [];
    while (true) {
        $waited = $now() - $since;
        if ($waited >= (int) $h['max']) {
            return $end(['go' => true, 'code' => 'gave_up_' . ($why ?? 'night'), 'seconds' => $waited]);
        }
        writeAtomic("$waitDir/backup-wait.json", jsonEncode(['pid' => getmypid(), 'since' => $since, 'until' => $since + (int) $h['max'], 'why' => $w]), 0600, 0, 0);
        ($h['sleep'])(max(1, min((int) $h['every'], $since + (int) $h['max'] - $now())));
        if (!($h['array'])()) {
            return $end(['go' => false, 'code' => null, 'seconds' => $now() - $since, 'result' => 'array']);
        }
        $s = $status();
        if (($s['mode'] ?? '') === 'backup' && (int) ($s['started'] ?? 0) >= $since) {
            return $end(['go' => false, 'code' => null, 'seconds' => $now() - $since, 'result' => 'meanwhile']);
        }
        $w = $look();
        if ($w === null) {
            return $end(['go' => true, 'code' => $why !== null ? "waited_$why" : null, 'seconds' => $now() - $since]);
        }
        $why = $w === 'tie' ? $why : $w;
    }
}

/**
 * The job (`php agent.php job backup-order`, job.sh before the engine): waits (nightOrderBackupGate()), then prints the
 * order's word for the engine («waited_night:1260», nothing when there is none). Exit 0: start the backup; 3: not now.
 */
function nightOrderBackupJob(?array $h = null): int
{
    $g = nightOrderBackupGate($h);
    $min = intdiv($g['seconds'] + 30, 60);
    if (!$g['go']) {
        logLine('Backup on schedule: not started — ' . match ($g['result'] ?? '') {
            'array'     => "the array stopped while it waited ($min min)",
            'meanwhile' => "a backup started here while it waited ($min min)",
            default     => 'another scheduled start waits already',
        });
        return 3;
    }
    if ($g['code'] !== null) {
        logLine('Backup on schedule: ' . match ($g['code']) {
            'waited_night'  => "waited $min min for Ms. Moverelli's run",
            'waited_mover'  => "waited $min min for Unraid's mover",
            'gave_up_night' => "starts after $min min although Ms. Moverelli's run still goes",
            default         => "starts after $min min although Unraid's mover is still at work",
        });
        echo "{$g['code']}:{$g['seconds']}\n";
    }
    return 0;
}

/** A backup started here: her run or Unraid's mover going now — its word for the engine (beside_night | beside_mover), or null */
function nightOrderBeside(?array $h = null): ?string
{
    $h = nightOrderHost($h);
    return ($h['night_running'])() ? 'beside_night' : (($h['mover'])() ? 'beside_mover' : null);
}

/**
 * Is a real backup taking its snapshots now (or about to: from its start until all its snapshots are taken)? The engine's
 * lock held by a run of mode backup (never a tour, a dry run, the setup, a restore) and its status.json `snapped` still 0;
 * an engine before 2.70 (no `snapped`): its phase not yet one of NIGHT_ORDER_FROZEN.
 */
function nightOrderBackupSnapping(string $data = BACKUP_DATA_DIR): bool
{
    $holder = backupLockHolder($data);
    if ($holder === null || $holder['holder'] !== 'backup') {
        return false;
    }
    $s = readJson("$data/state/status.json") ?? [];
    if (($s['result'] ?? '') !== 'running' || ($s['mode'] ?? '') !== 'backup') {
        return false;
    }
    if (array_key_exists('snapped', $s)) {
        return !is_int($s['snapped']) || $s['snapped'] <= 0;
    }
    return !in_array($s['phase'] ?? '', NIGHT_ORDER_FROZEN, true);
}

/**
 * Before her real run (nightRun()): wait while a backup takes its snapshots — see the top. $by `office` (her page, Jack
 * Streamer's «Run now»): never waits — go false, result `refused`.
 *
 * @return array{go: bool, code: ?string, seconds: int, result?: string}  result: refused | array
 */
function nightOrderHerGate(string $by, ?array $h = null): array
{
    $h = nightOrderHost($h);
    if (!($h['snapping'])()) {
        return ['go' => true, 'code' => null, 'seconds' => 0];
    }
    if ($by !== 'schedule') {
        return ['go' => false, 'code' => null, 'seconds' => 0, 'result' => 'refused'];
    }
    $now = $h['now'];
    $since = $now();
    while (true) {
        $waited = $now() - $since;
        if ($waited >= (int) $h['her_max']) {
            return ['go' => true, 'code' => 'gave_up_backup', 'seconds' => $waited];
        }
        ($h['sleep'])(max(1, min((int) $h['every'], $since + (int) $h['her_max'] - $now())));
        if (!($h['array'])()) {
            return ['go' => false, 'code' => null, 'seconds' => $now() - $since, 'result' => 'array'];
        }
        if (!($h['snapping'])()) {
            return ['go' => true, 'code' => 'waited_backup', 'seconds' => $now() - $since];
        }
    }
}

/** An order's word as a record keeps it ({code, seconds}), read back exactly in its shape — else null */
function nightOrderRow(mixed $o, array $codes = NIGHT_ORDER_CODES): ?array
{
    if (!is_array($o) || !in_array($o['code'] ?? null, $codes, true) || !is_int($o['seconds'] ?? null) || $o['seconds'] < 0) {
        return null;
    }
    return ['code' => $o['code'], 'seconds' => $o['seconds']];
}

/**
 * Does the backup start where her run goes (pure)? Over the next 7 days: `same` — a backup start in the same minute as one
 * of hers; `inside` — one within $usual seconds after hers (the length of her last run; capped at 12 h); null — neither, or
 * either schedule off.
 */
function nightOrderOverlap(?string $backup, ?string $night, int $usual, int $now): ?string
{
    $bf = $backup !== null ? cronFields($backup) : null;
    $nf = $night !== null ? cronFields($night) : null;
    if ($bf === null || $nf === null) {
        return null;
    }
    $span = max(0, min($usual, 43200));
    $start = null;                  // her last start up to this minute
    $inside = false;
    for ($t = intdiv($now, 60) * 60 + 60, $end = $t + 7 * 86400; $t < $end; $t += 60) {
        if (cronMatches($nf, $t)) {
            $start = $t;
        }
        if ($start !== null && $t - $start <= $span && cronMatches($bf, $t)) {
            if ($t === $start) {
                return 'same';
            }
            $inside = true;
        }
    }
    return $inside ? 'inside' : null;
}

/** The pages' quiet line (Mr. Backupsy's schedule, hers): same | inside | null — nightOrderOverlap() of the two lines */
function nightOrderHint(?array $h = null, ?int $now = null): ?string
{
    $h = nightOrderHost($h);
    $n = readJson($h['night_dir'] . '/night.json') ?? [];
    $usual = ($n['mode'] ?? '') === 'run' && is_int($n['started'] ?? null) && is_int($n['finished'] ?? null) ? max(0, $n['finished'] - $n['started']) : 0;
    return nightOrderOverlap(($h['backup_cron'])(), ($h['night_cron'])(), $usual, $now ?? time());
}
