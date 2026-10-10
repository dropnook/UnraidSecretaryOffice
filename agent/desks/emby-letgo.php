<?php
declare(strict_types=1);

/*
 * Letting Jack Emby go (2026-10-09: «why else would one let him go?»). Until then his let-go dialog only warned
 * that EmbyCache and the gather keep running on their schedules. Now, like Mr. Backupsy's let-go (backup-letgo.php,
 * core.js Office.fireDialog → the desk's letGo), his part of the dialog asks `emby.letgo_look` when it opens and, on
 * «Let go», `emby.letgo {confirm: true, release: bool}` — before he is let go (an unhired desk gets no write actions,
 * agentDeskMayAct()); he is let go whatever came of it, and the page says what was done.
 *
 *   - Always, no tick: both of his schedules go (jobs `embycache` and `gather`, their lines in the office's cron file —
 *     officeJobSetSchedule(), what his schedule action uses). A run that is going is never stopped: it finishes, and
 *     nothing starts it again. A scheduled gather that waits for Emby to be free (embyGatherGate()) gives up at its
 *     next look once its schedule is off.
 *   - One tick, off by default: «Also bring the prepared films back to the array» — a last EmbyCache run in mode
 *     `release` (embycache_run.py --release: its cleanup over the whole exclude list, the same way its cleanup takes
 *     them — origin disk, then rsync or the move binary per his settings; nothing filled), a job of the host's atd
 *     (`php agent.php job embycache release --letgo`, embyJob()) under the usual locks (the gather's lock held by the
 *     job, EmbyCache's own by EmbyCache), refused while someone watches Emby (asked here and again in the job), its
 *     result in his list of runs, the log and Unraid's notifications (he is gone by then, so a good end is told too).
 *     Without the tick the files stay on the pool.
 *   - Mover Tuning: while hired he keeps his list entered there (emby-mover.php). When its cfg names his list, the dialog
 *     offers a second tick, on by default: «Also take my list out of Mover Tuning again» (`unlist`) — the two keys back
 *     to what they were before his first change (his note office-mover.json), else «No»; every other line stays
 *     (embyLetGoUnlist()). Then Mover Tuning moves what is still on the pool back at its next run. A let-go noted in the
 *     last EMBY_LETGO_QUIET seconds keeps his enforcing away meanwhile (the page's look between the let-go and «fire»).
 *   - Unraid's mover schedule: whoever switched it off (his page's button, or by hand) — it stays «Disabled»; the dialog
 *     says so and where it is switched on again (⟦Mover Settings⟧). He never switches it back on.
 *   - His settings stay. What he switched off is noted in data/embycache/office-letgo.json: hired again, his page says
 *     once that the schedules stay off until switched on (embyLetGoNote(); seen → `shown`, also when a schedule is set).
 */

const EMBY_LETGO_NOTE  = 'office-letgo.json';
const EMBY_TUNING_CFG  = '/boot/config/plugins/ca.mover.tuning/ca.mover.tuning.cfg';
const EMBY_LETGO_JOBS  = ['embycache', 'gather'];
const EMBY_LETGO_QUIET = 600;       // seconds after a let-go in which he never enters his list into Mover Tuning again

/**
 * Where the let-go reads and writes, and what it asks — the tests put stand-ins into $GLOBALS['embyLetGoHost']: cron (the
 * office's cron file), apply (update_cron), dir (his data folder), tuning (Mover Tuning's cfg), settings, running, look
 * (who watches Emby), python, launch (hostLaunch()).
 */
function embyLetGoHost(): array
{
    $h = $GLOBALS['embyLetGoHost'] ?? [];
    return [
        'cron'     => $h['cron'] ?? OFFICE_CRON,
        'apply'    => $h['apply'] ?? true,
        'dir'      => $h['dir'] ?? EMBY_DATA,
        'tuning'   => $h['tuning'] ?? EMBY_TUNING_CFG,
        'lists'    => $h['lists'] ?? fn (): array => (embyMoverHost()['lists'])(),
        'settings' => $h['settings'] ?? fn (): ?array => embyReadSettings(),
        'running'  => $h['running'] ?? fn (): ?string => embyLetGoRunning(),
        'waiting'  => $h['waiting'] ?? fn (): bool => embyGatherWaiting() !== null,
        // asked in the agent's own loop: a short look (the job asks again, fully)
        'look'     => $h['look'] ?? fn (?array $s): array => embyWatching($s, fn (string $url, string $key) => embyWatchFetch($url, $key, EMBY_WATCH_PAGE)),
        'python'   => $h['python'] ?? fn (): bool => embyPython() !== null,
        'launch'   => $h['launch'] ?? 'hostLaunch',
        // Unraid's own mover schedule «Disabled»? (emby-mover.php) — it stays so after the let-go, the dialog says
        'mover_off' => $h['mover_off'] ?? fn (): bool => embyMoverScheduleOff(embyMoverOwn(embyMoverHost())),
    ];
}

/** His two schedules as they stand: job => cron line or null */
function embyLetGoSchedules(string $cron): array
{
    $lines = officeCronLines($cron);
    return array_combine(EMBY_LETGO_JOBS, array_map(fn ($j) => $lines[$j] ?? null, EMBY_LETGO_JOBS));
}

/** Which of his runs is going: embycache, gather or null (his job, or the tool's own lock — also one started elsewhere) */
function embyLetGoRunning(): ?string
{
    // his jobs first: a real run holds the other tool's lock too
    foreach (EMBY_LETGO_JOBS as $tool) {
        if (embyJobInfo($tool)['running']) {
            return $tool;
        }
    }
    return match (true) {
        flockHeld(EMBY_DATA . '/embycache.lock') => 'embycache',
        flockHeld(GATHER_LOCK)                   => 'gather',
        default                                  => null,
    };
}

/**
 * What EmbyCache keeps on the pool, as far as it is still there: its exclude list's entries that exist under the pool
 * (EmbyCache's cleanup skips the rest too) — files and bytes.
 */
function embyLetGoOnPool(?array $settings, string $dir): array
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
 * Mover Tuning's cfg, read only: is it there, and does it ignore EmbyCache's list (filelistf «Yes», filelistv one of his
 * list's paths — $lists)? {there, listed, key, file} (`file` as the cfg names it — the dialog names it).
 */
function embyLetGoTuning(string $cfg, array $lists): array
{
    $text = @file_get_contents($cfg, false, null, 0, 1 << 20);
    if ($text === false) {
        return ['there' => false, 'listed' => false];
    }
    $v = embyTuningValues($text);
    $file = embyTuningFirst($v, 'filelistv');
    if (embyTuningFirst($v, 'filelistf') === 'yes' && in_array($file, $lists, true)) {
        return ['there' => true, 'listed' => true, 'key' => 'filelistv', 'file' => mb_substr($file, 0, 300)];
    }
    return ['there' => true, 'listed' => false];
}

/**
 * Takes his list out of Mover Tuning's cfg again (the let-go's second tick): filelistf and filelistv back to what they
 * were before his first change (his note), else filelistf «No» and no file — only while the cfg still names his list;
 * every other line stays; a new file + rename; logged; his note goes. {done, to?} or {done: false, error}.
 */
function embyLetGoUnlist(string $cfg, array $lists, string $dir): array
{
    try {
        $text = @file_get_contents($cfg, false, null, 0, 1 << 20);
        if ($text === false || is_link($cfg) || !embyLetGoTuning($cfg, $lists)['listed']) {
            return ['done' => false, 'error' => ['key' => 'emby_letgo_unlisted', 'params' => []]];
        }
        $before = (array) (embyMoverNote($dir)['before'] ?? []);
        $f = is_string($before['filelistf'] ?? null) && in_array($before['filelistf'], ['yes', 'no'], true) ? $before['filelistf'] : 'no';
        $v = is_string($before['filelistv'] ?? null) && ($before['filelistv'] === '' || embyTuningValueOk($before['filelistv'])) ? $before['filelistv'] : '';
        if ($f === 'yes' && ($v === '' || in_array($v, $lists, true))) {
            [$f, $v] = ['no', ''];
        }
        writeAtomic($cfg, embyTuningSet($text, ['filelistf' => $f, 'filelistv' => $v]), 0600, 0, 0);
        @unlink("$dir/" . EMBY_MOVER_NOTE);
        logLine("Jack Emby: let go — took his list out of Mover Tuning again (filelistf=$f, filelistv=" . ($v ?: '–') . ')');
        return ['done' => true, 'to' => ['filelistf' => $f, 'filelistv' => $v]];
    } catch (Throwable $e) {
        logLine('Jack Emby: could not take his list out of Mover Tuning: ' . $e->getMessage());
        return ['done' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => mb_substr($e->getMessage(), 0, 300)]]];
    }
}

/** Was he let go a moment ago (his let-go note, EMBY_LETGO_QUIET)? Then he doesn't enter his list into Mover Tuning again */
function embyLetGoRecent(string $dir): bool
{
    $n = readJson("$dir/" . EMBY_LETGO_NOTE);
    return $n !== null && time() - (int) ($n['at'] ?? $n['time'] ?? 0) < EMBY_LETGO_QUIET;
}

/**
 * May the films be brought back now? null, or the error key why not (settings, Python, nothing on the pool, a run
 * going) — the watchers are asked apart (embyLetGoWatch()), only when nothing else stops it.
 */
function embyLetGoReleaseWhyNot(?array $settings, array $onPool, ?string $running, callable $python): ?string
{
    return match (true) {
        $settings === null || empty($settings['instances']) => 'emby_not_configured',
        $onPool['files'] === 0                               => 'emby_letgo_nothing',
        $running !== null                                    => 'emby_running',
        !$python()                                           => 'emby_letgo_python',
        default                                              => null,
    };
}

/** Who watches Emby, as a refusal: null (free, down, no server to ask) or the Problem (someone watches; an unusable answer) */
function embyLetGoWatchProblem(array $look): ?Problem
{
    if ($look['state'] === 'watching') {
        return new Problem('emby_release_watching', ['who' => $look['who']]);
    }
    return embyWatchProblem($look);
}

/**
 * emby.letgo_look — what letting him go does here: his schedules, a run going, the films on the pool, Mover Tuning, and
 * whether Unraid's mover schedule is off (`mover_off` — it stays off; the dialog says where to switch it on again)
 */
function embyLetGoLook(): array
{
    $h = embyLetGoHost();
    $settings = ($h['settings'])();
    $onPool = embyLetGoOnPool($settings, $h['dir']);
    $running = ($h['running'])();
    $why = embyLetGoReleaseWhyNot($settings, $onPool, $running, $h['python']);
    $watch = null;
    if ($why === null && ($p = embyLetGoWatchProblem(($h['look'])($settings)))) {
        $why = $p->key;
        $watch = $p->params;
    }
    return ['ok' => true, 'schedules' => embyLetGoSchedules($h['cron']), 'running' => $running, 'waiting' => ($h['waiting'])(),
            'pool' => $onPool, 'release' => ['ok' => $why === null, 'why' => $why, 'params' => $watch ?? []], 'mover_tuning' => embyLetGoTuning($h['tuning'], ($h['lists'])()),
            'mover_off' => ($h['mover_off'])()];
}

/**
 * emby.letgo {confirm: true, release: bool, unlist?: bool} — while he is still hired: both schedules off (always), with
 * `release` the last cleanup handed to atd (refused, and said, while a run is going, nothing lies on the pool or someone
 * watches Emby), with `unlist` his list out of Mover Tuning again (absent: false — a page from before 1.54 never asks).
 * The answer says what was switched off (`was`, `off`, `failed`), what is going on (`running`), what came of the release
 * and of `unlist`; the note for his page when he is hired again is written.
 */
function embyLetGo(array $r): array
{
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $release = boolField($r, 'release');
    $unlist = array_key_exists('unlist', $r) ? boolField($r, 'unlist') : false;
    $h = embyLetGoHost();
    $was = embyLetGoSchedules($h['cron']);
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
    $left = array_keys(array_filter(embyLetGoSchedules($h['cron']), fn ($c) => $c !== null));
    $off = array_values(array_diff($off, $left));
    logLine('Jack Emby: let go — ' . ($off ? 'schedules switched off: ' . implode(', ', $off) : 'no schedule was on')
        . ($left ? ' — still scheduled: ' . implode(', ', $left) : ''));
    $running = ($h['running'])();
    $rel = null;
    if ($release) {
        $rel = embyLetGoRelease($h, $running);
    }
    // the note for his page when he is hired again — an earlier one not yet shown stays when nothing was on this time
    if ($off || embyLetGoNote($h['dir']) === null) {
        embyLetGoNoteWrite($h['dir'], ['time' => time(), 'at' => time(), 'was' => $was, 'off' => $off, 'left' => $left,
                                        'release' => $rel === null ? null : ['started' => $rel['started'], 'why' => $rel['error']['key'] ?? null], 'shown' => false]);
    } else {
        // the moment counts (embyLetGoRecent()); the note itself stays as it was
        embyLetGoNoteWrite($h['dir'], ['at' => time()] + (readJson($h['dir'] . '/' . EMBY_LETGO_NOTE) ?? []));
    }
    $un = $unlist ? embyLetGoUnlist($h['tuning'], ($h['lists'])(), $h['dir']) : null;
    return ['ok' => true, 'was' => $was, 'off' => $off, 'left' => $left, 'failed' => $failed, 'running' => $running, 'release' => $rel, 'unlist' => $un];
}

/** The last cleanup: checked like the look, then `php agent.php job embycache release --letgo` handed to atd */
function embyLetGoRelease(array $h, ?string $running): array
{
    try {
        $settings = ($h['settings'])();
        $onPool = embyLetGoOnPool($settings, $h['dir']);
        if ($why = embyLetGoReleaseWhyNot($settings, $onPool, $running, $h['python'])) {
            throw new Problem($why);
        }
        $look = ($h['look'])($settings);
        if ($p = embyLetGoWatchProblem($look)) {
            logLine('Jack Emby: the films are not brought back — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . embyWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            throw $p;
        }
        ($h['launch'])('emby-embycache', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'embycache', 'release', '--letgo']);
        logLine("Jack Emby: bringing {$onPool['files']} files back to the array — started via at");
        return ['started' => true, 'files' => $onPool['files'], 'bytes' => $onPool['bytes']];
    } catch (Problem $p) {
        if ($p->key !== 'emby_release_watching' && $p->key !== 'emby_watch_key' && $p->key !== 'emby_watch_answer') {
            logLine("Jack Emby: the films are not brought back ($p->key)");
        }
        $error = $p->toArray();
        if ($p->key !== 'emby_letgo_nothing' && $p->key !== 'emby_not_configured') {
            embyRemember(['tool' => 'embycache', 'mode' => 'release', 'by' => 'letgo', 'started' => time(), 'finished' => time(), 'result' => 'refused',
                          'why' => $p->key] + ($p->key === 'emby_release_watching' ? ['who' => $p->params['who'] ?? []] : []), $h['dir']);
        }
        return ['started' => false, 'error' => $error];
    }
}

function embyLetGoNoteWrite(string $dir, array $note): void
{
    try {
        embyDataDir($dir);
        writeAtomic("$dir/" . EMBY_LETGO_NOTE, jsonEncode($note), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Jack Emby: could not note the let-go: ' . $e->getMessage());
    }
}

/**
 * Hired again: what he switched off when he was let go — once (null when there is nothing to say or it was shown).
 * {time, was: {job: cron|null}, off: [job], release}
 */
function embyLetGoNote(?string $dir = null): ?array
{
    $n = readJson(($dir ?? EMBY_DATA) . '/' . EMBY_LETGO_NOTE);
    if (!$n || !empty($n['shown']) || !is_array($n['off'] ?? null) || !$n['off']) {
        return null;
    }
    $was = is_array($n['was'] ?? null) ? $n['was'] : [];
    $off = array_values(array_filter($n['off'], fn ($j) => in_array($j, EMBY_LETGO_JOBS, true)));
    return $off ? ['time' => (int) ($n['time'] ?? 0), 'off' => $off,
                   'was' => array_combine($off, array_map(fn ($j) => is_string($was[$j] ?? null) ? $was[$j] : null, $off))] : null;
}

/** emby.letgo_seen — his page showed the note (or a schedule was set): it isn't said again */
function embyLetGoSeen(?string $dir = null): array
{
    $file = ($dir ?? EMBY_DATA) . '/' . EMBY_LETGO_NOTE;
    $n = readJson($file);
    if ($n && empty($n['shown'])) {
        $n['shown'] = true;
        writeAtomic($file, jsonEncode($n), 0600, 0, 0);
    }
    return ['ok' => true];
}
