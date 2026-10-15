<?php
declare(strict_types=1);

/*
 * Letting Jack Emby go (2026-10-09: «why else would one let him go?»). Until then his let-go dialog only warned
 * that EmbyCache and the gather keep running on their schedules. Now, like Mr. Backupsy's let-go (backup-letgo.php,
 * core.js Office.fireDialog → the desk's letGo), his part of the dialog asks `jack.letgo_look` when it opens and, on
 * «Let go», `jack.letgo {confirm: true, release: bool}` — before he is let go (an unhired desk gets no write actions,
 * agentDeskMayAct()); he is let go whatever came of it, and the page says what was done.
 *
 *   - Always, no tick: both of his schedules go (jobs `embycache` and `gather`, their lines in the office's cron file —
 *     officeJobSetSchedule(), what his schedule action uses). A run that is going is never stopped: it finishes, and
 *     nothing starts it again. A scheduled gather that waits for Emby to be free (jackGatherGate()) gives up at its
 *     next look once its schedule is off.
 *   - One tick, off by default: «Also bring the prepared films back to the array» — a last EmbyCache run in mode
 *     `release` (embycache_run.py --release: its cleanup over the whole exclude list, the same way its cleanup takes
 *     them — origin disk, then rsync or the move binary per his settings; nothing filled), a job of the host's atd
 *     (`php agent.php job embycache release --letgo`, jackJob()) under the usual locks (the gather's lock held by the
 *     job, EmbyCache's own by EmbyCache), refused while someone watches Emby (asked here and again in the job), its
 *     result in his list of runs, the log and Unraid's notifications (he is gone by then, so a good end is told too).
 *     Without the tick the files stay on the pool.
 *   - Unraid's mover schedule: it stays «Disabled» — Ms. Moverelli is still here (he never works without her) and moves;
 *     she brings it back when she is let go. He never switches it back on.
 *   - Mover Tuning: nothing (up to 1.60 he kept his list entered there; since he works only under Ms. Moverelli he
 *     never touches it).
 *   - His settings stay. What he switched off is noted in data/embycache/office-letgo.json: hired again, his page says
 *     once that the schedules stay off until switched on (jackLetGoNote(); seen → `shown`, also when a schedule is set).
 */

const JACK_LETGO_NOTE  = 'office-letgo.json';
const JACK_LETGO_JOBS  = ['embycache', 'gather'];

/**
 * Where the let-go reads and writes, and what it asks — the tests put stand-ins into $GLOBALS['jackLetGoHost']: cron (the
 * office's cron file), apply (update_cron), dir (his data folder), settings, running, look
 * (who watches Emby), python, launch (hostLaunch()).
 */
function jackLetGoHost(): array
{
    $h = $GLOBALS['jackLetGoHost'] ?? [];
    return [
        'cron'     => $h['cron'] ?? OFFICE_CRON,
        'apply'    => $h['apply'] ?? true,
        'dir'      => $h['dir'] ?? JACK_DATA,
        'settings' => $h['settings'] ?? fn (): ?array => jackReadSettings(),
        'running'  => $h['running'] ?? fn (): ?string => jackLetGoRunning(),
        'waiting'  => $h['waiting'] ?? fn (): bool => jackGatherWaiting() !== null,
        // asked in the agent's own loop: a short look (the job asks again, fully)
        'look'     => $h['look'] ?? fn (?array $s): array => jackWatching($s, fn (string $url, string $key) => jackWatchFetch($url, $key, JACK_WATCH_PAGE)),
        'python'   => $h['python'] ?? fn (): bool => jackPython() !== null,
        'launch'   => $h['launch'] ?? 'hostLaunch',
        // Unraid's own mover schedule «Disabled»? (jack-mover.php) — it stays so after the let-go, the dialog says
        'mover_off' => $h['mover_off'] ?? fn (): bool => jackMoverScheduleOff(jackMoverOwn(jackMoverHost())),
    ];
}

/** His two schedules as they stand: job => cron line or null */
function jackLetGoSchedules(string $cron): array
{
    $lines = officeCronLines($cron);
    return array_combine(JACK_LETGO_JOBS, array_map(fn ($j) => $lines[$j] ?? null, JACK_LETGO_JOBS));
}

/** Which of his runs is going: embycache, gather or null (his job, or the tool's own lock — also one started elsewhere) */
function jackLetGoRunning(): ?string
{
    // his jobs first: a real run holds the other tool's lock too
    foreach (JACK_LETGO_JOBS as $tool) {
        if (jackJobInfo($tool)['running']) {
            return $tool;
        }
    }
    return match (true) {
        flockHeld(JACK_DATA . '/embycache.lock') => 'embycache',
        flockHeld(GATHER_LOCK)                   => 'gather',
        default                                  => null,
    };
}

/**
 * What EmbyCache keeps on the pool, as far as it is still there: its exclude list's entries that exist under the pool
 * (EmbyCache's cleanup skips the rest too) — files and bytes.
 */
function jackLetGoOnPool(?array $settings, string $dir): array
{
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    $files = 0;
    $bytes = 0;
    if ($cache !== '') {
        foreach (@file("$dir/embycache_exclude.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $path) {
            $path = trim($path);
            if (str_starts_with($path, "$cache/") && is_file($path)) {
                $files++;
                $bytes += (int) @filesize($path);
            }
        }
    }
    return ['files' => $files, 'bytes' => $bytes, 'pool' => $cache];
}

/**
 * May the films be brought back now? null, or the error key why not (settings, Python, nothing on the pool, a run
 * going) — the watchers are asked apart (jackLetGoWatch()), only when nothing else stops it.
 */
function jackLetGoReleaseWhyNot(?array $settings, array $onPool, ?string $running, callable $python): ?string
{
    return match (true) {
        $settings === null || empty($settings['instances']) => 'jack_not_configured',
        $onPool['files'] === 0                               => 'jack_letgo_nothing',
        $running !== null                                    => 'jack_running',
        !$python()                                           => 'jack_letgo_python',
        default                                              => null,
    };
}

/** Who watches Emby, as a refusal: null (free, down, no server to ask) or the Problem (someone watches; an unusable answer) */
function jackLetGoWatchProblem(array $look): ?Problem
{
    if ($look['state'] === 'watching') {
        return new Problem('jack_release_watching', ['who' => $look['who']]);
    }
    return jackWatchProblem($look);
}

/**
 * jack.letgo_look — what letting him go does here: his schedules, a run going, the films on the pool, and
 * whether Unraid's mover schedule is off (`mover_off` — it stays off; the dialog says where to switch it on again)
 */
function jackLetGoLook(): array
{
    $h = jackLetGoHost();
    $settings = ($h['settings'])();
    $onPool = jackLetGoOnPool($settings, $h['dir']);
    $running = ($h['running'])();
    $why = jackLetGoReleaseWhyNot($settings, $onPool, $running, $h['python']);
    $watch = null;
    if ($why === null && ($p = jackLetGoWatchProblem(($h['look'])($settings)))) {
        $why = $p->key;
        $watch = $p->params;
    }
    return ['ok' => true, 'schedules' => jackLetGoSchedules($h['cron']), 'running' => $running, 'waiting' => ($h['waiting'])(),
            'pool' => $onPool, 'release' => ['ok' => $why === null, 'why' => $why, 'params' => $watch ?? []],
            'mover_off' => ($h['mover_off'])()];
}

/**
 * jack.letgo {confirm: true, release: bool} — while he is still hired: both schedules off (always), with `release` the
 * last cleanup handed to atd (refused, and said, while a run is going, nothing lies on the pool or someone watches Emby).
 * The answer says what was switched off (`was`, `off`, `failed`), what is going on (`running`) and what came of the
 * release; the note for his page when he is hired again is written.
 */
function jackLetGo(array $r): array
{
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $release = boolField($r, 'release');
    $h = jackLetGoHost();
    $was = jackLetGoSchedules($h['cron']);
    $off = [];
    $failed = null;
    foreach ($was as $job => $cron) {
        if ($cron === null) {
            continue;
        }
        try {
            officeJobSetSchedule($job, null, $h['cron'], $h['apply']);
            $off[] = $job;
        } catch (Throwable $e) {
            $failed = $e instanceof Problem ? $e->toArray() : ['key' => 'internal', 'params' => ['detail' => mb_substr($e->getMessage(), 0, 400)]];
        }
    }
    // the cron file as it is now: a line that is still there (a failed write) still runs
    $left = array_keys(array_filter(jackLetGoSchedules($h['cron']), fn ($c) => $c !== null));
    $off = array_values(array_diff($off, $left));
    logLine('Jack Streamer: let go — ' . ($off ? 'schedules switched off: ' . implode(', ', $off) : 'no schedule was on')
        . ($left ? ' — still scheduled: ' . implode(', ', $left) : ''));
    $running = ($h['running'])();
    $rel = null;
    if ($release) {
        $rel = jackLetGoRelease($h, $running);
    }
    // the note for his page when he is hired again — an earlier one not yet shown stays when nothing was on this time
    if ($off || jackLetGoNote($h['dir']) === null) {
        jackLetGoNoteWrite($h['dir'], ['time' => time(), 'at' => time(), 'was' => $was, 'off' => $off, 'left' => $left,
                                        'release' => $rel === null ? null : ['started' => $rel['started'], 'why' => $rel['error']['key'] ?? null], 'shown' => false]);
    }
    return ['ok' => true, 'was' => $was, 'off' => $off, 'left' => $left, 'failed' => $failed, 'running' => $running, 'release' => $rel];
}

/** The last cleanup: checked like the look, then `php agent.php job embycache release --letgo` handed to atd */
function jackLetGoRelease(array $h, ?string $running): array
{
    try {
        $settings = ($h['settings'])();
        $onPool = jackLetGoOnPool($settings, $h['dir']);
        if ($why = jackLetGoReleaseWhyNot($settings, $onPool, $running, $h['python'])) {
            throw new Problem($why);
        }
        $look = ($h['look'])($settings);
        if ($p = jackLetGoWatchProblem($look)) {
            logLine('Jack Streamer: the films are not brought back — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . jackWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            throw $p;
        }
        ($h['launch'])('jack-embycache', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'embycache', 'release', '--letgo']);
        logLine("Jack Streamer: bringing {$onPool['files']} files back to the array — started via at");
        return ['started' => true, 'files' => $onPool['files'], 'bytes' => $onPool['bytes']];
    } catch (Problem $p) {
        if ($p->key !== 'jack_release_watching' && $p->key !== 'jack_watch_key' && $p->key !== 'jack_watch_answer') {
            logLine("Jack Streamer: the films are not brought back ($p->key)");
        }
        $error = $p->toArray();
        if ($p->key !== 'jack_letgo_nothing' && $p->key !== 'jack_not_configured') {
            jackRemember(['tool' => 'embycache', 'mode' => 'release', 'by' => 'letgo', 'started' => time(), 'finished' => time(), 'result' => 'refused',
                          'why' => $p->key] + ($p->key === 'jack_release_watching' ? ['who' => $p->params['who'] ?? []] : []), $h['dir']);
        }
        return ['started' => false, 'error' => $error];
    }
}

function jackLetGoNoteWrite(string $dir, array $note): void
{
    try {
        jackDataDir($dir);
        writeAtomic("$dir/" . JACK_LETGO_NOTE, jsonEncode($note), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Jack Streamer: could not note the let-go: ' . $e->getMessage());
    }
}

/**
 * Hired again: what he switched off when he was let go — once (null when there is nothing to say or it was shown).
 * {time, was: {job: cron|null}, off: [job], release}
 */
function jackLetGoNote(?string $dir = null): ?array
{
    $n = readJson(($dir ?? JACK_DATA) . '/' . JACK_LETGO_NOTE);
    if (!$n || !empty($n['shown']) || !is_array($n['off'] ?? null) || !$n['off']) {
        return null;
    }
    $was = is_array($n['was'] ?? null) ? $n['was'] : [];
    $off = array_values(array_filter($n['off'], fn ($j) => in_array($j, JACK_LETGO_JOBS, true)));
    return $off ? ['time' => (int) ($n['time'] ?? 0), 'off' => $off,
                   'was' => array_combine($off, array_map(fn ($j) => is_string($was[$j] ?? null) ? $was[$j] : null, $off))] : null;
}

/** jack.letgo_seen — his page showed the note (or a schedule was set): it isn't said again */
function jackLetGoSeen(?string $dir = null): array
{
    $file = ($dir ?? JACK_DATA) . '/' . JACK_LETGO_NOTE;
    $n = readJson($file);
    if ($n && empty($n['shown'])) {
        $n['shown'] = true;
        writeAtomic($file, jsonEncode($n), 0600, 0, 0);
    }
    return ['ok' => true];
}
