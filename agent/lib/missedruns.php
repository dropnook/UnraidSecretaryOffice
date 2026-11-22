<?php
declare(strict_types=1);

/*
 * Scheduled runs that did not start because the array wasn't started (2026-11-22, «always on» step S1d). scripts/job.sh
 * ends such a start at once — nothing may land in /mnt while it is a bare RAM folder — and until now it ended without
 * a trace: a backup that didn't run at 03:00 was simply missing from the history. Now job.sh leaves a mark in RAM,
 * RUN_DIR/missed-<job>: "<first time> <last time> <how often>" (and one line in the syslog at the first one); the agent
 * takes the marks at its next start and says them in its log. Ms. Snapshotini's schedules need none: what was due is
 * caught up at the start (snapPlansRunDue()).
 */

/** The jobs job.sh marks — and how the log names each */
const MISSED_JOBS = [
    'backup'    => 'the scheduled backup run',
    'embycache' => 'Jack Streamer\'s scheduled MediaCache run',
    'gather'    => 'Jack Streamer\'s scheduled gather',
    'moverelli' => 'Ms. Moverelli\'s scheduled run',
    'cleanup'   => 'Ms. Dustdevil\'s scheduled tour',
    'logs'      => 'Ms. Protocolli\'s scheduled tour',
];

/**
 * The marks read and removed: [job => [first, last, n]] — only a plain small file of ours in exactly its shape (anything
 * else there is removed unread: a link is never followed).
 *
 * @return array<string, array{0: int, 1: int, 2: int}>
 */
function officeMissedTake(?string $run = null): array
{
    $run ??= RUN_DIR;
    $out = [];
    foreach (array_keys(MISSED_JOBS) as $job) {
        $f = "$run/missed-$job";
        clearstatcache(true, $f);
        $st = @lstat($f);
        if ($st === false) {
            continue;
        }
        $raw = ($st['mode'] & 0170000) === 0100000 && $st['size'] <= 64 ? trim((string) @file_get_contents($f, false, null, 0, 64)) : '';
        @unlink($f);
        if (preg_match('/^(\d{1,12}) (\d{1,12}) (\d{1,6})$/D', $raw, $m) && (int) $m[3] >= 1 && (int) $m[1] <= (int) $m[2]) {
            $out[$job] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
    }
    return $out;
}

/** The agent's start: one line in its log per job that did not start meanwhile. Returns the lines (the tests read them). */
function officeMissedSay(?string $run = null): array
{
    $lines = [];
    foreach (officeMissedTake($run) as $job => [$first, $last, $n]) {
        $when = $n === 1 ? 'at ' . date('Y-m-d H:i', $first) : "$n times, " . date('Y-m-d H:i', $first) . ' to ' . date('Y-m-d H:i', $last);
        $lines[] = 'Office: ' . MISSED_JOBS[$job] . " did not start while the array wasn't started ($when)";
    }
    array_map('logLine', $lines);
    return $lines;
}
