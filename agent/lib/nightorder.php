<?php
declare(strict_types=1);

/*
 * The scheduled backup and Ms. Moverelli's run (2026-10-21; seen on a real server: both at 02:00, side by side — a file
 * moved between two of the backup's snapshots is in neither or in both). The rule, simply:
 *
 *   never the same minute   saving one schedule on the other's minute is refused, naming the other's time
 *                           (nightOrderSameMinute(): the next 7 days of minutes of both lines); a collision from before
 *                           stays until the next save, said as a quiet hint on both schedule rows.
 *   whoever starts first    runs to its end; the other waits, with no cap:
 *                           - the scheduled backup (job.sh backup → `php agent.php job backup-order`, before the engine)
 *                             waits while her run goes (night.lock held) — for the whole run; its word to the engine:
 *                             UB_ORDER «waited_night:<seconds>» → status.json/history `order`
 *                           - her scheduled run (nightRun()) — the backup's line due in the same minute (a collision from
 *                             before): first up to NIGHT_ORDER_TIE for the engine's lock to show (both crons start at
 *                             once; the backup always goes first then) — waits while a real backup holds the engine and hasn't done
 *                             its local part (status.json `phase` not yet one of NIGHT_ORDER_LOCAL_DONE: the containers
 *                             are back, the snapshots taken, the packages written — partners and Kopia read only frozen
 *                             snapshots); for the whole run when it backs up a share read live that she moves
 *                             (nightOrderLiveMoved()); her word: night.json `order` «waited_backup»
 *   started by hand         never waits: refused while the other runs (backup_night_running, moverelli_backup_running,
 *                           jack_backup_running).
 * Anyone else holding the engine (a restore, a drill, a let-go, the setup, a tour, a dry run): her run goes as before.
 */

require_once __DIR__ . '/nightrun.php';

const NIGHT_ORDER_EVERY      = 15;     // s: how often a wait looks again
const NIGHT_ORDER_TIE        = 90;     // s: her run in the backup's minute gives the engine this long to take its lock
// the engine's phases (status.json `phase`) once its local part is done — from here it reads only frozen snapshots
const NIGHT_ORDER_LOCAL_DONE = ['partner', 'mounting', 'kopia', 'unmounting', 'cleanup', 'done'];

/** Where the order looks — the tests' stand-ins in $GLOBALS['nightOrderHost'] */
function nightOrderHost(?array $h = null): array
{
    $h ??= $GLOBALS['nightOrderHost'] ?? [];
    $dir = $h['night_dir'] ?? DATA_DIR . '/moverelli';
    $data = $h['backup_data'] ?? BACKUP_DATA_DIR;
    $shares = $h['shares_dir'] ?? '/boot/config/shares';
    $cron = $h['cron_file'] ?? OFFICE_CRON;
    return $h + [
        'night_dir'     => $dir,
        'backup_data'   => $data,
        'waitdir'       => RUN_DIR,
        'night_running' => fn (): bool => nightRunning($dir),
        'backup_holds'  => fn (): bool => nightOrderBackupHolds($data, $shares),
        'backup_cron'   => fn (): ?string => officeJobSchedule('backup', $cron)['custom'],
        'backup_locked' => fn (): bool => (backupLockHolder($data)['holder'] ?? '') === 'backup',
        'tie'           => NIGHT_ORDER_TIE,
        'array'         => fn (): bool => arrayRunning(),
        'sleep'         => fn (int $s) => sleep($s),
        'now'           => fn (): int => time(),
        'every'         => NIGHT_ORDER_EVERY,
    ];
}

/** A wait in words for the logs: «45 s», «21 min» */
function nightOrderSpan(int $seconds): string
{
    return $seconds < 60 ? "$seconds s" : intdiv($seconds + 30, 60) . ' min';
}

/** Wait while $busy() says so (a look every `every` s): the seconds waited, or null when the array stopped meanwhile */
function nightOrderWait(array $h, callable $busy): ?int
{
    $since = ($h['now'])();
    while ($busy()) {
        ($h['sleep'])((int) $h['every']);
        if (!($h['array'])()) {
            return null;
        }
    }
    return ($h['now'])() - $since;
}

/**
 * Her scheduled run in the backup's minute (both lines due at once — a collision from before the save check): wait up to
 * `tie` s for a backup to take the engine's lock, so that the backup always goes first. Returns the seconds waited.
 */
function nightOrderTie(array $h): int
{
    $since = ($h['now'])();
    $f = ($c = ($h['backup_cron'])()) !== null ? cronFields($c) : null;
    if ($f === null || !cronMatches($f, $since)) {
        return 0;
    }
    while (!($h['backup_locked'])() && ($h['now'])() - $since < (int) $h['tie']) {
        ($h['sleep'])(2);
    }
    return ($h['now'])() - $since;
}

/**
 * The job before the scheduled backup (`php agent.php job backup-order`, job.sh): waits while her run goes, then prints the
 * engine's word («waited_night:1260»; nothing when it didn't wait). Exit 0: start the backup; 3: not now (the array
 * stopped, or another scheduled start waits already — its wait lock RUN_DIR/backup-wait.lock).
 */
function nightOrderBackupJob(?array $h = null): int
{
    $h = nightOrderHost($h);
    if (!($h['night_running'])()) {
        return 0;
    }
    @mkdir($h['waitdir'], 0700, true);
    $lock = @fopen($h['waitdir'] . '/backup-wait.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        logLine("Backup on schedule: not started — another scheduled start waits already for Ms. Moverelli's run");
        return 3;
    }
    logLine("Backup on schedule: Ms. Moverelli's run goes — waiting until it is done");
    $waited = nightOrderWait($h, $h['night_running']);
    flock($lock, LOCK_UN);
    fclose($lock);
    if ($waited === null) {
        logLine('Backup on schedule: not started — the array stopped while it waited');
        return 3;
    }
    logLine('Backup on schedule: waited ' . nightOrderSpan($waited) . " for Ms. Moverelli's run");
    echo "waited_night:$waited\n";
    return 0;
}

/**
 * Must her run wait for the engine now? A real backup holds the engine's lock (never a tour, a dry run, the setup, a
 * restore) and its local part isn't done (status.json `phase`) — or it is, but the run backs up a share read live that
 * she moves (then the whole run).
 */
function nightOrderBackupHolds(string $data = BACKUP_DATA_DIR, string $sharesDir = '/boot/config/shares'): bool
{
    $holder = backupLockHolder($data);
    if ($holder === null || $holder['holder'] !== 'backup') {
        return false;
    }
    $s = readJson("$data/state/status.json") ?? [];
    if (($s['result'] ?? '') !== 'running' || !in_array($s['phase'] ?? '', NIGHT_ORDER_LOCAL_DONE, true)) {
        return true;
    }
    return nightOrderLiveMoved($data, $sharesDir) !== [];
}

/**
 * The shares the backup reads live (no snapshot — its setup's plan, setup-plan.json: method `live`, mode not `off`) that
 * she moves (Unraid's share config: shareUseCache yes or prefer)
 *
 * @return list<string>
 */
function nightOrderLiveMoved(string $data = BACKUP_DATA_DIR, string $sharesDir = '/boot/config/shares'): array
{
    $out = [];
    foreach ((array) ((readJson("$data/state/setup-plan.json") ?? [])['shares'] ?? []) as $s) {
        $name = is_array($s) && is_string($s['name'] ?? null) ? $s['name'] : '';
        if ($name === '' || !preg_match('/^[^\/\x00-\x1f]+$/D', $name) || ($s['method'] ?? '') !== 'live' || ($s['mode'] ?? 'off') === 'off') {
            continue;
        }
        if (in_array(readCfg("$sharesDir/$name.cfg")['shareUseCache'] ?? '', ['yes', 'prefer'], true)) {
            $out[] = $name;
        }
    }
    return $out;
}

/** An order's word as a record keeps it ({code, seconds}), read back exactly in its shape — else null */
function nightOrderRow(mixed $o, string $code): ?array
{
    if (!is_array($o) || ($o['code'] ?? null) !== $code || !is_int($o['seconds'] ?? null) || $o['seconds'] < 0) {
        return null;
    }
    return ['code' => $code, 'seconds' => $o['seconds']];
}

/** The first minute of the next 7 days both lines start in (pure), or null — any cron line, not only a daily one */
function nightOrderSameMinute(?string $a, ?string $b, int $now): ?int
{
    $fa = $a !== null ? cronFields($a) : null;
    $fb = $b !== null ? cronFields($b) : null;
    if ($fa === null || $fb === null) {
        return null;
    }
    for ($t = intdiv($now, 60) * 60 + 60, $end = $t + 7 * 86400; $t < $end; $t += 60) {
        if (cronMatches($fa, $t) && cronMatches($fb, $t)) {
            return $t;
        }
    }
    return null;
}

/**
 * Saving $job's schedule ('backup' | 'moverelli') on the other's minute: refused, naming the other's time. Her line counts
 * only while she is hired ($herHired: the tests'); the backup's always.
 */
function nightOrderSaveCheck(string $job, ?string $cron, string $cronFile = OFFICE_CRON, ?bool $herHired = null): void
{
    $other = $job === 'backup' ? 'moverelli' : 'backup';
    if ($cron === null || ($other === 'moverelli' && !($herHired ?? in_array('moverelli', staffHired($GLOBALS['agentStaffFile'] ?? null), true)))) {
        return;
    }
    $at = nightOrderSameMinute($cron, officeJobSchedule($other, $cronFile)['custom'], time());
    if ($at !== null) {
        throw new Problem($job === 'backup' ? 'backup_same_minute' : 'moverelli_same_minute', ['time' => date('H:i', $at)]);
    }
}

/** The pages' quiet line: both lines start in the same minute now (a collision from before the save check) */
function nightOrderHint(string $cronFile = OFFICE_CRON, ?int $now = null): bool
{
    return nightOrderSameMinute(officeJobSchedule('backup', $cronFile)['custom'], officeJobSchedule('moverelli', $cronFile)['custom'], $now ?? time()) !== null;
}
