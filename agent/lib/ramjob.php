<?php
declare(strict_types=1);

/*
 * A job without the data folder — `php agent.php ramjob <name>` («always on», 2026-11-22, step S4b).
 *
 * A desk may have a job that keeps working whatever the array does (desk(…, ['ram_jobs' => [<name> => fn]])). Such a job
 * must never open anything on the array or a pool, and the data folder lies there. So in its process the data folder is a
 * place that can never be there — OFFICE_NO_DATA, under /dev/null: nothing can be read, made or written there — and every
 * look for the data folder in the code it runs says «not there», at any array state (the night shift only relies on the
 * array being stopped). What it has to say goes to a log of its own in RAM:
 *
 *   RUN_DIR/ramjob-<name>.log   its logLine()s (RAMJOB_LOG_MAX, then one file .1 — like the night shift's log)
 *   RUN_DIR/ramjob-fold.json    how far the agent has taken each of them into its own log: {"<name>": {ino, pos}}
 *
 * The agent takes the new lines into agent.log every few seconds while it runs (ramLogFold(): whole lines, with the times
 * they were written at; across a rotation; never twice, never through a link) — so there is ONE log to read. Lines written
 * while no agent ran wait in RAM for the next one (a reboot loses them, as the night shift's).
 */

const OFFICE_NO_DATA = '/dev/null/no-data-folder';
const RAMJOB_NAME_RE = '/^[a-z][a-z0-9-]{0,39}$/D';
const RAMJOB_LOG_MAX = 64 * 1024;           // bytes of a RAM job's log before it is put aside as .1
const RAMJOB_FOLD_MAX = 256 * 1024;         // bytes taken into the agent's log at one fold at most (the rest at the next)

function ramLogFile(string $job, ?string $run = null): string
{
    return ($run ?? RUN_DIR) . "/ramjob-$job.log";
}

/** A line in a RAM job's own log (logLine() in such a job) */
function ramLogLine(string $text, ?string $job = null): void
{
    $job ??= defined('RAM_JOB') ? (string) RAM_JOB : '';
    if (!preg_match(RAMJOB_NAME_RE, $job)) {
        return;
    }
    $log = ramLogFile($job);
    clearstatcache(true, $log);
    if (is_link($log)) {
        @unlink($log);                  // appending would write through it into another file
    }
    if (@filesize($log) > RAMJOB_LOG_MAX) {
        if (is_link("$log.1")) {
            @unlink("$log.1");
        }
        @rename($log, "$log.1");
    }
    @file_put_contents($log, date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ['', ' | '], trim($text)) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * The agent: what the RAM jobs wrote since its last look, into its own log — whole lines as they stand (their own times).
 * A log put aside meanwhile (.1): the rest of it first. A line still being written waits for the next look. Returns the
 * number of lines taken. $append: the tests' own (else logAppend()).
 */
function ramLogFold(?string $run = null, ?callable $append = null): int
{
    $run ??= RUN_DIR;
    $append ??= 'logAppend';
    // the jobs' logs by their names (never a pattern handed to the shell's rules: a name is exactly «ramjob-<name>.log»)
    $logs = [];
    foreach (@scandir($run) ?: [] as $entry) {
        if (preg_match('/^ramjob-([a-z][a-z0-9-]{0,39})\.log$/D', $entry, $m)) {
            $logs[] = ramLogFile($m[1], $run);
        }
    }
    if (!$logs) {
        return 0;
    }
    $stateFile = "$run/ramjob-fold.json";
    $state = is_link($stateFile) ? [] : (readJson($stateFile) ?? []);
    $before = $state;
    $lines = 0;
    $plain = function (string $f) {
        clearstatcache(true, $f);
        $st = @lstat($f);
        return $st !== false && ($st['mode'] & 0170000) === 0100000 ? $st : null;
    };
    foreach ($logs as $log) {
        if (!preg_match('/^ramjob-([a-z][a-z0-9-]{0,39})\.log$/D', basename($log), $m) || ($st = $plain($log)) === null) {
            continue;
        }
        $name = $m[1];
        $was = is_array($state[$name] ?? null) && is_int($state[$name]['ino'] ?? null) && is_int($state[$name]['pos'] ?? null) ? $state[$name] : null;
        $parts = [];
        if ($was !== null && $was['ino'] !== $st['ino']) {
            // put aside since the last look: what was left of it, then the new one from its start
            $old = $plain("$log.1");
            if ($old !== null && $old['ino'] === $was['ino'] && $old['size'] > $was['pos']) {
                $parts[] = ["$log.1", $was['pos'], $old['size'], false];
            }
            $was = null;
        }
        $pos = $was === null ? 0 : min($was['pos'], $st['size']);
        $parts[] = [$log, $pos, $st['size'], true];
        foreach ($parts as [$file, $from, $to, $current]) {
            $raw = $to > $from ? (string) @file_get_contents($file, false, null, $from, min($to - $from, RAMJOB_FOLD_MAX)) : '';
            $cut = strrpos($raw, "\n");
            $whole = $cut === false ? '' : substr($raw, 0, $cut + 1);
            if ($whole !== '') {
                $append($whole);
                $lines += substr_count($whole, "\n");
            }
            if ($current) {
                $state[$name] = ['ino' => $st['ino'], 'pos' => $from + strlen($whole)];
            }
        }
    }
    if ($state !== $before) {
        if (is_link($stateFile)) {
            @unlink($stateFile);
        }
        writeAtomic($stateFile, jsonEncode($state), 0600, 0, 0);
    }
    return $lines;
}
