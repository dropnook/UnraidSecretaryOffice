<?php
declare(strict_types=1);

/*
 * Jack Emby and Unraid's mover (2026-10-09 — a manual «mover start» on his server moved three films EmbyCache had
 * put on the pool back to the array). EmbyCache and the gather run for real only while Unraid's own mover schedule is
 * «Disabled» (⟦Settings⟧ → ⟦Scheduler⟧ → ⟦Mover Settings⟧) — always, one way (decision, 2026-10-09 evening);
 * otherwise only their dry runs go, and real ones (from the page and on schedule) are refused with the reason
 * (embyRunCheck() → embyMoverProblem()). Unraid 7.3.3 keeps «Disabled» as `shareMoverSchedule=""` in
 * /boot/config/share.cfg and removes /boot/config/plugins/dynamix/mover.cron (verified on a test server: «Daily» writes the file
 * with «… /usr/local/sbin/mover start …», «Disabled» deletes it; emhttpd deletes it again at every array start while the
 * key is empty, so «Disabled» lasts). Mover Tuning is no way around it: from Unraid 7.2.1 on Unraid's own schedule and
 * ⟦Move now⟧ run /usr/local/sbin/mover, which knows no list.
 *
 * Switching it off: his page offers «Switch the mover schedule off…» (emby.mover_off, embyMoverOff()) — after a confirm,
 * the agent does what Unraid's form does with «Disabled» + ⟦Apply⟧: `emcmd "shareMoverSchedule=&shareMoverLogging=<as it
 * is>&changeMover=Apply"`, then looks that share.cfg says "" and no mover.cron line is left. Or the user sets it himself.
 *
 * Mover Tuning (ca.mover.tuning, 2026.10.03 by masterwishx) stays optional — for those who want other files moved on its
 * own schedule. While it is installed and he is hired, Jack enters EmbyCache's exclude list there himself, without
 * asking, and again at every look when it was taken out or changed (embyTuningLook()), so its own schedule leaves his
 * files alone: the global cfg /boot/config/plugins/ca.mover.tuning/ca.mover.tuning.cfg, keys `filelistf="yes"`
 * (⟦Ignore files listed inside of a text file⟧) and `filelistv="<file>"` — written like its own page writes them
 * (Unraid's update.php: `key="value"` lines), but line by line: every other line, key and comment stays as it was; every
 * line of those two keys gets the new value (Unraid's parse_ini takes the last of a key, age_mover's cfg() the first); a
 * new file + rename; the cfg as it was copied next to it once (`<cfg>.before-jack-emby`). Nothing to reload: age_mover
 * reads the cfg at each run. With Unraid's schedule off, what still lets Mover Tuning's own schedule take his files
 * (found in its code and tried on a test server, 2026-10-09) — each refuses real runs too:
 *   - his list couldn't be entered → `tuning_list`;
 *   - its «Force move all files on a schedule» (force="yes", its own mover.cron: «mover.php force start») runs Unraid's
 *     mover → `tuning_force`;
 *   - a share override (shareOverrideConfig/<share>.cfg with moverOverride="yes") whose own filelistf is «No», or «Yes»
 *     with another file, for one of his shares → `tuning_override`.
 * Its «Move All from Primary->Secondary» (omovercfg) drops the filters above its threshold: said, not refused.
 * ⟦Move now⟧ in ⟦Mover Settings⟧ and on the Main page start Unraid's mover by hand — a hand's doing: the guards below
 * stop his run, his page says so.
 *
 * Guards while running: Unraid's mover at work (/var/run/mover.pid alive — Unraid's mover and Mover Tuning's age_mover
 * write it —, or a `mover`, `age_mover` or `move` process that isn't his own run's) → no real run starts; on schedule it
 * waits and looks again (embyRunGate(), like the wait for Emby's watchers); the mover starting during a run → the stop
 * file (EMBYCACHE_STOP, CONSOLIDATE_STOP): EmbyCache stops after the file it is on, the gather after its folder
 * (embyRunWatch()).
 *
 * The tests put stand-ins into $GLOBALS['embyMoverHost'] (embyMoverHost()) — emcmd always a stand-in (and
 * OFFICE_EMCMD_BIN for the whole suite): Unraid's own is never run by a test.
 */

const EMBY_MOVER_SHARE_CFG = '/boot/config/share.cfg';
const EMBY_MOVER_CRON      = '/boot/config/plugins/dynamix/mover.cron';
const EMBY_MOVER_PID       = '/var/run/mover.pid';
const EMBY_MOVER_PROCS     = ['mover', 'age_mover', 'move'];      // Unraid's mover script, Mover Tuning's, Unraid's move binary
const EMBY_TUNING_NAME     = 'ca.mover.tuning';
const EMBY_TUNING_DIR      = '/boot/config/plugins/ca.mover.tuning';
const EMBY_TUNING_BACKUP   = '.before-jack-emby';                // the cfg as it was before his first change, next to it
const EMBY_MOVER_NOTE      = 'office-mover.json';                // in his data folder: what he changed in Mover Tuning, what was there
const EMBY_MOVER_EVERY     = 300;      // a scheduled run that finds the mover at work looks again every 5 min …
const EMBY_MOVER_MAX       = 7200;     // … for up to 2 h, then that run is skipped
const EMBY_MOVER_LOOK      = 5;        // during a real run: is the mover at work? every 5 s
const EMBY_MOVER_VAR       = '/var/local/emhttp/var.ini';        // emhttpd's live settings (what ⟦Mover Settings⟧ shows)
const EMBY_EMCMD           = '/usr/local/sbin/emcmd';            // Unraid's way to send emhttpd a form, as its pages do

/** Where the rule reads and writes — the tests' stand-ins in $GLOBALS['embyMoverHost'] */
function embyMoverHost(): array
{
    $h = $GLOBALS['embyMoverHost'] ?? [];
    return [
        'share_cfg'  => $h['share_cfg'] ?? EMBY_MOVER_SHARE_CFG,
        'mover_cron' => $h['mover_cron'] ?? EMBY_MOVER_CRON,
        'tuning_dir' => $h['tuning_dir'] ?? EMBY_TUNING_DIR,
        'installed'  => $h['installed'] ?? fn (): bool => is_file('/var/log/plugins/' . EMBY_TUNING_NAME . '.plg'),
        // his list as Mover Tuning gets it (the path as set — what other programs get), and the forms that count as his
        'lists'      => $h['lists'] ?? fn (): array => array_values(array_unique([dataPathUser(EMBY_DATA . '/embycache_exclude.txt'), EMBY_DATA . '/embycache_exclude.txt'])),
        'dir'        => $h['dir'] ?? EMBY_DATA,
        'shares'     => $h['shares'] ?? fn (): array => ($s = embyReadSettings()) ? array_column(embyShares($s), 'share') : [],
        'hired'      => $h['hired'] ?? fn (): bool => in_array('emby', staffHired($GLOBALS['agentStaffFile'] ?? null), true),
        'pid'        => $h['pid'] ?? EMBY_MOVER_PID,
        'proc'       => $h['proc'] ?? '/proc',
        'var_ini'    => $h['var_ini'] ?? EMBY_MOVER_VAR,
        'emcmd'      => $h['emcmd'] ?? (getenv('OFFICE_EMCMD_BIN') ?: EMBY_EMCMD),
        'wait'       => $h['wait'] ?? fn (int $ms) => usleep($ms * 1000),
    ];
}

function embyTuningCfg(array $h): string
{
    return $h['tuning_dir'] . '/' . EMBY_TUNING_NAME . '.cfg';
}

/**
 * A `key="value"` file as Unraid's plugins keep it: every value of each key, in order (one outer pair of quotes taken
 * off, like age_mover's cfg()). Comment and other lines are left out.
 *
 * @return array<string, list<string>>
 */
function embyTuningValues(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if (preg_match('/^\s*([A-Za-z0-9_]{1,64})\s*=(.*)$/D', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                $v = substr($v, 1, -1);
            }
            $out[$m[1]][] = $v;
        }
    }
    return $out;
}

/** The first value of a key (age_mover reads the first), or '' */
function embyTuningFirst(array $values, string $key): string
{
    return (string) ($values[$key][0] ?? '');
}

/** Does the cfg name his list — every line of both keys (so Unraid's page and age_mover read the same)? */
function embyTuningListed(array $values, array $lists): bool
{
    $f = $values['filelistf'] ?? [];
    $v = $values['filelistv'] ?? [];
    return $f && $v && !array_diff($f, ['yes']) && !array_diff($v, $lists);
}

/** A value fit for `key="value"` in such a file: a path without quotes, backslashes, $, `, | or control characters */
function embyTuningValueOk(string $v): bool
{
    return (bool) preg_match('~^/[^"\\\\`$|\x00-\x1f\x7f]{1,4000}$~D', $v);
}

/**
 * The text with $set written in: every line of such a key gets `key="value"` where it stands (its line ending kept), a
 * key not there is added at the end; every other byte stays.
 */
function embyTuningSet(string $text, array $set): string
{
    $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
    $lines = $text === '' ? [] : (preg_split('/(?<=\n)/', $text) ?: []);
    $seen = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*([A-Za-z0-9_]{1,64})\s*=/', $line, $m) && array_key_exists($m[1], $set)) {
            $end = str_ends_with($line, "\r\n") ? "\r\n" : (str_ends_with($line, "\n") ? "\n" : '');
            $lines[$i] = $m[1] . '="' . $set[$m[1]] . '"' . $end;
            $seen[$m[1]] = true;
        }
    }
    $out = implode('', $lines);
    foreach ($set as $key => $value) {
        if (!isset($seen[$key])) {
            if ($out !== '' && !str_ends_with($out, "\n")) {
                $out .= $eol;
            }
            $out .= $key . '="' . $value . '"' . $eol;
        }
    }
    return $out;
}

/** His note about Mover Tuning (what he entered, what was there before his first change), or null */
function embyMoverNote(string $dir): ?array
{
    $n = readJson("$dir/" . EMBY_MOVER_NOTE);
    return $n && ($n['v'] ?? null) === 1 && is_array($n['before'] ?? null) ? $n : null;
}

/**
 * Writes his list into Mover Tuning's cfg: the backup first (once), then the new file; logged; his note keeps what was
 * there before his first change (for his let-go). $text: the cfg as read (false: there is none).
 */
function embyTuningWrite(string $cfg, string|false $text, string $want, string $dir): void
{
    if (!embyTuningValueOk($want)) {
        throw new Problem('emby_mover_tuning_list', ['detail' => 'path']);
    }
    if (is_link($cfg) || ($text === false && file_exists($cfg))) {
        throw new Problem('emby_mover_tuning_list', ['detail' => 'not a plain file']);
    }
    if (!is_dir(dirname($cfg))) {
        throw new Problem('emby_mover_tuning_list', ['detail' => 'no folder']);
    }
    $old = $text === false ? '' : $text;
    $values = embyTuningValues($old);
    $backup = $cfg . EMBY_TUNING_BACKUP;
    if ($text !== false && !file_exists($backup) && !is_link($backup)) {
        writeAtomic($backup, $old, 0600, 0, 0);
    }
    writeAtomic($cfg, embyTuningSet($old, ['filelistf' => 'yes', 'filelistv' => $want]), 0600, 0, 0);
    $was = 'filelistf=' . (embyTuningFirst($values, 'filelistf') ?: '–') . ', filelistv=' . (embyTuningFirst($values, 'filelistv') ?: '–');
    logLine("Jack Emby: entered his list in Mover Tuning («Ignore files listed inside of a text file»: $want) — before: $was"
        . (file_exists($backup) ? ' (the cfg as it was: ' . basename($backup) . ')' : ''));
    $note = embyMoverNote($dir);
    try {
        embyDataDir($dir);
        writeAtomic("$dir/" . EMBY_MOVER_NOTE, jsonEncode([
            'v'       => 1,
            'entered' => (int) ($note['entered'] ?? time()),
            'last'    => time(),
            'times'   => (int) ($note['times'] ?? 0) + 1,
            'file'    => $want,
            // what the user had before his first change — his let-go puts it back
            'before'  => $note['before'] ?? ['filelistf' => $values['filelistf'][0] ?? null, 'filelistv' => $values['filelistv'][0] ?? null],
        ]), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Jack Emby: could not note his change in Mover Tuning: ' . $e->getMessage());
    }
}

/**
 * Mover Tuning as it stands for him: installed? his list in place (entered now when $enforce and it wasn't)? what else
 * would let the mover take his files (its forced move, a share's override), its «Move All» threshold.
 */
function embyTuningLook(array $h, bool $enforce): array
{
    if (!($h['installed'])()) {
        return ['installed' => false];
    }
    $cfg = embyTuningCfg($h);
    $lists = ($h['lists'])();
    $text = @file_get_contents($cfg, false, null, 0, 1 << 20);
    $values = embyTuningValues($text === false ? '' : $text);
    $out = ['installed' => true, 'listed' => embyTuningListed($values, $lists), 'file' => $lists[0], 'changed' => false];
    if (!$out['listed'] && $enforce && !embyLetGoRecent($h['dir'])) {      // not in the moment of his let-go (emby-letgo.php)
        try {
            embyTuningWrite($cfg, $text, $lists[0], $h['dir']);
            $text = @file_get_contents($cfg, false, null, 0, 1 << 20);
            $values = embyTuningValues($text === false ? '' : $text);
            $out['listed'] = embyTuningListed($values, $lists);
            $out['changed'] = true;
        } catch (Throwable $e) {
            $out['error'] = mb_substr($e instanceof Problem ? (string) ($e->params['detail'] ?? $e->key) : $e->getMessage(), 0, 300);
            logLine('Jack Emby: could not enter his list in Mover Tuning: ' . $out['error']);
        }
    }
    $force = embyTuningFirst($values, 'force') === 'yes'
        && embyMoverCronLine((string) @file_get_contents($h['tuning_dir'] . '/mover.cron', false, null, 0, 65536), '/mover\.php\s+force\b/') !== null;
    $out += [
        'force'     => $force,
        'overrides' => embyTuningOverrides($h['tuning_dir'], ($h['shares'])(), $lists),
        'move_all'  => embyTuningFirst($values, 'omovercfg') === 'yes' ? (embyTuningFirst($values, 'omoverthresh') ?: null) : false,
        'by_jack'   => $out['listed'] && embyMoverNote($h['dir']) !== null,
    ];
    return $out;
}

/** His shares whose Mover Tuning override switches his list off: filelistf «No», or «Yes» with another file */
function embyTuningOverrides(string $dir, array $shares, array $lists): array
{
    $off = [];
    foreach ($shares as $share) {
        if (!is_string($share) || !preg_match('/^[^\/\x00-\x1f]{1,255}$/D', $share) || $share === '.' || $share === '..') {
            continue;
        }
        $text = @file_get_contents("$dir/shareOverrideConfig/$share.cfg", false, null, 0, 65536);
        if ($text === false || !str_contains($text, 'moverOverride="yes"')) {
            continue;                                   // age_mover applies an override only with exactly that line
        }
        $v = embyTuningValues($text);
        $f = embyTuningFirst($v, 'filelistf');
        if ($f === 'no' || ($f === 'yes' && !in_array(embyTuningFirst($v, 'filelistv'), $lists, true))) {
            $off[] = $share;
        }
    }
    return $off;
}

/** The first active line of a cron file matching $pattern (no comment lines), its five time fields; or null */
function embyMoverCronLine(string $text, string $pattern): ?string
{
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && preg_match($pattern, $line)) {
            return implode(' ', array_slice(preg_split('/\s+/', $line) ?: [], 0, 5));
        }
    }
    return null;
}

/** Unraid's own mover schedule: as set (share.cfg, '' = «Disabled») and as it runs (mover.cron's line, or null) */
function embyMoverOwn(array $h): array
{
    $set = trim((string) (readCfg($h['share_cfg'])['shareMoverSchedule'] ?? ''), " \t\"");
    $cron = embyMoverCronLine((string) @file_get_contents($h['mover_cron'], false, null, 0, 65536), '#/usr/local/sbin/mover(\.old)?\s+start\b|(^|\s)mover\s+start\b#');
    return ['set' => $set, 'cron' => $cron];
}

/** Is Unraid's own schedule «Disabled» — the key empty and no `mover start` line that runs? */
function embyMoverScheduleOff(array $own): bool
{
    return $own['set'] === '' && $own['cron'] === null;
}

/**
 * The rule: may EmbyCache and the gather run for real? {ok, way: disabled|null, why: null|schedule|tuning_list|
 * tuning_force|tuning_override, schedule: Unraid's own (cron, else as set) or null, tuning: embyTuningLook()}.
 * Unraid's own schedule «Disabled» always; with Mover Tuning installed also nothing of its own that takes his files.
 * $enforce: his list is entered into Mover Tuning when it isn't (only while he is hired).
 */
function embyMoverRule(bool $enforce = true, ?array $h = null): array
{
    $h ??= embyMoverHost();
    $own = embyMoverOwn($h);
    $t = embyTuningLook($h, $enforce && ($h['hired'])());
    $why = match (true) {
        !embyMoverScheduleOff($own)                  => 'schedule',
        !$t['installed']                             => null,
        !$t['listed']                                => 'tuning_list',
        $t['force']                                  => 'tuning_force',
        (bool) $t['overrides']                       => 'tuning_override',
        default                                      => null,
    };
    return ['ok' => $why === null, 'way' => $why === null ? 'disabled' : null, 'why' => $why,
            'schedule' => $own['cron'] ?? ($own['set'] !== '' ? $own['set'] : null), 'tuning' => $t];
}

/** The rule as a refusal of a real run, or null */
function embyMoverProblem(array $rule): ?Problem
{
    if ($rule['ok']) {
        return null;
    }
    $key = match ($rule['why']) {
        'tuning_list'     => 'emby_mover_tuning_list',
        'tuning_force'    => 'emby_mover_tuning_force',
        'tuning_override' => 'emby_mover_tuning_override',
        default           => 'emby_mover_schedule',
    };
    return new Problem($key, array_filter([
        'schedule' => $rule['schedule'] ?? null,
        'file'     => $rule['why'] === 'tuning_list' ? ($rule['tuning']['file'] ?? null) : null,
        'shares'   => $rule['why'] === 'tuning_override' ? implode(', ', $rule['tuning']['overrides']) : null,
        'detail'   => $rule['tuning']['error'] ?? null,
    ], fn ($v) => $v !== null));
}

/** For his page and the Team Lead: the rule (without the list's changes said again) and whether the mover is at work */
function embyMoverState(?array $rule = null): array
{
    $rule ??= embyMoverRule();
    $t = $rule['tuning'];
    return ['ok' => $rule['ok'], 'way' => $rule['way'], 'why' => $rule['why'], 'schedule' => $rule['schedule'], 'running' => embyMoverRunning(),
            'tuning' => $t['installed'] ? array_intersect_key($t, array_flip(['installed', 'listed', 'file', 'changed', 'error', 'force', 'overrides', 'move_all', 'by_jack']))
                                        : ['installed' => false]];
}

/**
 * ⟦Mover logging⟧ as it stands — what Unraid's form sends along when it applies the schedule (its select shows emhttpd's
 * live value, var.ini; share.cfg keeps it): yes | no. Neither says it: «no», Unraid's default. Anything else: null.
 */
function embyMoverLogging(array $h): ?string
{
    foreach ([$h['var_ini'], $h['share_cfg']] as $file) {
        $v = readCfg($file)['shareMoverLogging'] ?? null;
        if ($v !== null) {
            $v = trim((string) $v, " \t\"");
            return in_array($v, ['yes', 'no'], true) ? $v : null;
        }
    }
    return 'no';
}

/**
 * emby.mover_off {confirm: true} — switches Unraid's own mover schedule off, exactly as ⟦Mover Settings⟧ does with
 * ⟦Mover schedule⟧ «Disabled» + ⟦Apply⟧: `emcmd "shareMoverSchedule=&shareMoverLogging=<as it is>&changeMover=Apply"`
 * (the form's fields — its logging kept; emhttpd empties the key in share.cfg and deletes mover.cron). Looked at
 * afterwards (emhttpd answers when it is done; up to 3 s more): share.cfg says "" and no mover.cron line runs — else
 * refused with what is still there (`emby_mover_off_failed`). Already off: nothing is sent. Logged. Only while he is
 * hired (the agent's gate, agentDeskMayAct()). ⟦Move now⟧ by hand stays possible — his guards stop a run then.
 * {ok, already, was: the schedule it had, or null}
 */
function embyMoverOff(array $r, ?array $h = null): array
{
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $h ??= embyMoverHost();
    $own = embyMoverOwn($h);
    if (embyMoverScheduleOff($own)) {
        return ['ok' => true, 'already' => true, 'was' => null];
    }
    $was = $own['cron'] ?? $own['set'];
    $logging = embyMoverLogging($h);
    if ($logging === null) {
        throw new Problem('emby_mover_off_failed', ['detail' => 'shareMoverLogging has a value Unraid\'s form doesn\'t know']);
    }
    $bin = $h['emcmd'];
    if (!is_file($bin) || !is_executable($bin)) {
        throw new Problem('emby_mover_off_failed', ['detail' => "$bin not found"]);
    }
    [$exit, $out, $err] = run([$bin, "shareMoverSchedule=&shareMoverLogging=$logging&changeMover=Apply"], 30);
    for ($i = 0; $i < 10 && !embyMoverScheduleOff($own = embyMoverOwn($h)); $i++) {
        ($h['wait'])(300);
    }
    $said = mb_substr(trim($out . ' ' . $err), 0, 300);
    if (!embyMoverScheduleOff($own)) {
        $left = $own['set'] !== '' ? "share.cfg still says shareMoverSchedule=\"{$own['set']}\"" : "mover.cron still runs «{$own['cron']}»";
        logLine("Jack Emby: could not switch Unraid's mover schedule off — $left" . ($exit !== 0 ? " (emcmd: exit $exit $said)" : ''));
        throw new Problem('emby_mover_off_failed', ['detail' => $left . ($exit !== 0 && $said !== '' ? " — $said" : '')]);
    }
    logLine("Jack Emby: switched Unraid's mover schedule off (Mover Settings → Disabled; it was «{$was}», mover logging kept: $logging)"
        . ($exit !== 0 ? " — emcmd said: exit $exit $said" : ''));
    return ['ok' => true, 'already' => false, 'was' => $was];
}

/**
 * Is Unraid's mover at work? /var/run/mover.pid naming a living mover (Unraid's script and Mover Tuning's age_mover write
 * it), or a `mover`, `age_mover` or `move` process — not one below $self (EmbyCache's own use of the move binary).
 */
function embyMoverRunning(?array $h = null, ?int $self = null): bool
{
    $h ??= embyMoverHost();
    $pid = (int) trim((string) @file_get_contents($h['pid'], false, null, 0, 32));
    if ($pid > 1 && str_contains((string) @file_get_contents("{$h['proc']}/$pid/cmdline", false, null, 0, 4096), 'mover')) {
        return true;
    }
    foreach (glob("{$h['proc']}/[0-9]*", GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
        if (in_array(trim((string) @file_get_contents("$d/comm", false, null, 0, 64)), EMBY_MOVER_PROCS, true)
            && ($self === null || !embyProcUnder((int) basename($d), $self, $h['proc']))) {
            return true;
        }
    }
    return false;
}

/** Is $pid $self or one of its descendants? (the parents from /proc/<pid>/stat, at most 64 steps) */
function embyProcUnder(int $pid, int $self, string $proc = '/proc'): bool
{
    for ($i = 0; $i < 64 && $pid > 1; $i++) {
        if ($pid === $self) {
            return true;
        }
        $stat = (string) @file_get_contents("$proc/$pid/stat", false, null, 0, 1024);
        $pid = preg_match('/\)\s+\S\s+(\d+)/', $stat, $m) ? (int) $m[1] : 0;
    }
    return false;
}
