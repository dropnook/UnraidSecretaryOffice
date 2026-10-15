<?php
declare(strict_types=1);

/*
 * Jack Emby and Unraid's mover (2026-10-09 — a manual «mover start» on his server moved three films EmbyCache had
 * put on the pool back to the array). EmbyCache and the gather run for real only while Unraid's own mover schedule is
 * «Disabled» (⟦Settings⟧ → ⟦Scheduler⟧ → ⟦Mover Settings⟧) — always, one way (decision, 2026-10-09 evening);
 * otherwise only their dry runs go, and real ones (from the page and on schedule) are refused with the reason
 * (jackRunCheck() → jackMoverProblem()). Unraid 7.3.3 keeps «Disabled» as `shareMoverSchedule=""` in
 * /boot/config/share.cfg and removes /boot/config/plugins/dynamix/mover.cron (verified on a test server: «Daily» writes the file
 * with «… /usr/local/sbin/mover start …», «Disabled» deletes it; emhttpd deletes it again at every array start while the
 * key is empty, so «Disabled» lasts). Mover Tuning is no way around it: from Unraid 7.2.1 on Unraid's own schedule and
 * ⟦Move now⟧ run /usr/local/sbin/mover, which knows no list.
 *
 * Switching it off: his page offers «Switch the mover schedule off…» (jack.mover_off, jackMoverOff()) — after a confirm,
 * the agent does what Unraid's form does with «Disabled» + ⟦Apply⟧: `emcmd "shareMoverSchedule=&shareMoverLogging=<as it
 * is>&changeMover=Apply"`, then looks that share.cfg says "" and no mover.cron line is left. Or the user sets it himself.
 *
 * Jack is an intern: he works only under Ms. Moverelli (2026-10-12, the owner's decision — desk.json `needs`, he can't be
 * hired without her). Real runs go only while she is hired and set up (her smart_mover.ini there) and her ini keeps his
 * list out for each of his shares — the file in `global_excludes` or in the share's `excludes=`, or the share `skip=yes`
 * — else she would carry his films back (`moverelli_list`); not hired or not set up → `moverelli`. Mover Tuning is no
 * way for him any more (up to 1.60 he entered his list there): while it is installed with an active schedule of its
 * own (moverelliTuning(), her own refusal `moverelli_tuning_schedule`) it would move his films back — `tuning_schedule`,
 * switched off by the user in Mover Tuning, never by him. Her runs and his never run at the same time
 * (jackRunCheck(), moverelliRunCheck()); their starts take one lock (jackMoversLock()), so two cron jobs of the same
 * minute can't both pass the check.
 * ⟦Move now⟧ in ⟦Mover Settings⟧ and on the Main page start Unraid's mover by hand — a hand's doing: the guards below
 * stop his run, his page says so.
 *
 * Guards while running: Unraid's mover at work (/var/run/mover.pid alive — Unraid's mover and Mover Tuning's age_mover
 * write it —, or a `mover`, `age_mover` or `move` process that isn't his own run's) → no real run starts; on schedule it
 * waits and looks again (jackRunGate(), like the wait for Emby's watchers); the mover starting during a run → the stop
 * file (EMBYCACHE_STOP, CONSOLIDATE_STOP): EmbyCache stops after the file it is on, the gather after its folder
 * (jackRunWatch()).
 *
 * The tests put stand-ins into $GLOBALS['jackMoverHost'] (jackMoverHost()) — emcmd always a stand-in (and
 * OFFICE_EMCMD_BIN for the whole suite): Unraid's own is never run by a test.
 */

const JACK_MOVER_SHARE_CFG = '/boot/config/share.cfg';
const JACK_MOVER_CRON      = '/boot/config/plugins/dynamix/mover.cron';
const JACK_MOVER_PID       = '/var/run/mover.pid';
const JACK_MOVER_PROCS     = ['mover', 'age_mover', 'move'];      // Unraid's mover script, Mover Tuning's, Unraid's move binary
const JACK_TUNING_NAME     = 'ca.mover.tuning';                  // Mover Tuning: only looked at (Ms. Moverelli's moverelliTuning())
const JACK_TUNING_DIR      = '/boot/config/plugins/ca.mover.tuning';
const JACK_MOVER_EVERY     = 300;      // a scheduled run that finds the mover at work looks again every 5 min …
const JACK_MOVER_MAX       = 7200;     // … for up to 2 h, then that run is skipped
const JACK_MOVER_LOOK      = 5;        // during a real run: is the mover at work? every 5 s
const JACK_MOVER_VAR       = '/var/local/emhttp/var.ini';        // emhttpd's live settings (what ⟦Mover Settings⟧ shows)
const JACK_EMCMD           = '/usr/local/sbin/emcmd';            // Unraid's way to send emhttpd a form, as its pages do
const JACK_MOVERS_LOCK     = 'movers-start.lock';                 // in RUN_DIR: his and Ms. Moverelli's run starts, one at a time

/** Where the rule reads and writes — the tests' stand-ins in $GLOBALS['jackMoverHost'] */
function jackMoverHost(): array
{
    $h = $GLOBALS['jackMoverHost'] ?? [];
    return [
        'share_cfg'  => $h['share_cfg'] ?? JACK_MOVER_SHARE_CFG,
        'mover_cron' => $h['mover_cron'] ?? JACK_MOVER_CRON,
        'tuning_dir' => $h['tuning_dir'] ?? JACK_TUNING_DIR,
        'installed'  => $h['installed'] ?? fn (): bool => is_file('/var/log/plugins/' . JACK_TUNING_NAME . '.plg'),
        // his list as other programs get it (the path as set), and the forms that count as his
        'lists'      => $h['lists'] ?? fn (): array => array_values(array_unique([dataPathUser(JACK_DATA . '/embycache_exclude.txt'), JACK_DATA . '/embycache_exclude.txt'])),
        'dir'        => $h['dir'] ?? JACK_DATA,
        'shares'     => $h['shares'] ?? fn (): array => ($s = jackReadSettings()) ? array_column(jackShares($s), 'share') : [],
        'hired'      => $h['hired'] ?? fn (): bool => in_array('jack', staffHired($GLOBALS['agentStaffFile'] ?? null), true),
        'pid'        => $h['pid'] ?? JACK_MOVER_PID,
        'proc'       => $h['proc'] ?? '/proc',
        'var_ini'    => $h['var_ini'] ?? JACK_MOVER_VAR,
        'emcmd'      => $h['emcmd'] ?? (getenv('OFFICE_EMCMD_BIN') ?: JACK_EMCMD),
        'wait'       => $h['wait'] ?? fn (int $ms) => usleep($ms * 1000),
        // what the schedule was before it is switched off — noted once for Ms. Moverelli's let-go (moverelli-unraid.php)
        'note'       => $h['note'] ?? fn (string $was, string $logging) => moverelliUnraidNoteKeep($was, $logging),
        // Ms. Moverelli while hired and set up: what her smart_mover.ini keeps out (moverelliExcludeLook()), else null
        'moverelli'  => $h['moverelli'] ?? fn (): ?array => function_exists('moverelliExcludeLook') ? moverelliExcludeLook() : null,
        // another mover on a schedule of its own ({file, cron, name}): Mover Tuning's, else any other (Ms. Moverelli's
        // moverelliTuning(), moverelliMovers()) — it would carry his films back; null when none. name: what his refusal
        // calls it (Mover Tuning, the plugin's folder, the cron file, «User Scripts: <script>»)
        'tuning'     => $h['tuning'] ?? function (): ?array {
            $mh = moverelliHost();
            $m = moverelliTuning($mh)['lines'][0] ?? null;
            if ($m !== null) {
                $m['name'] = 'Mover Tuning';
            } elseif ($o = moverelliMovers($mh)[0] ?? null) {
                $m = ['file' => $o['name'], 'cron' => $o['when'], 'name' => $o['name']];
            }
            return $m;
        },
    ];
}

/**
 * The start lock Jack's and Ms. Moverelli's runs share: held only from the check «nothing of the other going» until the
 * run's own marker (office-run.json, its lock) is written — waited for up to $wait s; null when it couldn't be had.
 */
function jackMoversLock(int $wait = 30, string $dir = RUN_DIR): mixed
{
    @mkdir($dir, 0700, true);
    $f = @fopen("$dir/" . JACK_MOVERS_LOCK, 'c');
    if (!$f) {
        return null;
    }
    $until = microtime(true) + $wait;
    do {
        if (flock($f, LOCK_EX | LOCK_NB)) {
            return $f;
        }
        usleep(100000);
    } while (microtime(true) < $until);
    fclose($f);
    return null;
}

function jackMoversUnlock(mixed $f): void
{
    if (is_resource($f)) {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/** The first active line of a cron file matching $pattern (no comment lines), its five time fields; or null */
function jackMoverCronLine(string $text, string $pattern): ?string
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
function jackMoverOwn(array $h): array
{
    $set = trim((string) (readCfg($h['share_cfg'])['shareMoverSchedule'] ?? ''), " \t\"");
    $cron = jackMoverCronLine((string) @file_get_contents($h['mover_cron'], false, null, 0, 65536), '#/usr/local/sbin/mover(\.old)?\s+start\b|(^|\s)mover\s+start\b#');
    return ['set' => $set, 'cron' => $cron];
}

/** Is Unraid's own schedule «Disabled» — the key empty and no `mover start` line that runs? */
function jackMoverScheduleOff(array $own): bool
{
    return $own['set'] === '' && $own['cron'] === null;
}

/**
 * His shares Ms. Moverelli's smart_mover.ini doesn't keep his list away from: neither the share's `excludes` nor
 * `global_excludes` names one of $lists, and the share isn't `skip=yes`. $look: moverelliExcludeLook()'s
 * {global: [files], shares: {share: {skip, excludes: [files]}}}.
 */
function jackMoverelliUncovered(array $look, array $lists, array $shares): array
{
    $out = [];
    $global = array_values(array_filter((array) ($look['global'] ?? []), 'is_string'));
    foreach ($shares as $share) {
        if (!is_string($share)) {
            continue;
        }
        $own = (array) ($look['shares'][$share] ?? []);
        if (!empty($own['skip']) || array_intersect($lists, array_merge($global, array_values(array_filter((array) ($own['excludes'] ?? []), 'is_string'))))) {
            continue;
        }
        $out[] = $share;
    }
    return $out;
}

/**
 * The rule: may EmbyCache and the gather run for real? {ok, way: moverelli|null, why: null|schedule|moverelli|
 * moverelli_list|tuning_schedule, schedule: Unraid's own (cron, else as set) or null, moverelli: null (not hired, or no
 * smart_mover.ini yet) or {uncovered: his shares her ini doesn't keep his list away from, file}, tuning: Mover Tuning's
 * schedule {file, cron} or null}. Unraid's own schedule «Disabled», Ms. Moverelli supervising with his list kept out,
 * and no schedule of Mover Tuning's own.
 */
function jackMoverRule(?array $h = null): array
{
    $h ??= jackMoverHost();
    $own = jackMoverOwn($h);
    $m = ($h['moverelli'])();
    $lists = ($h['lists'])();
    $uncovered = $m === null ? [] : jackMoverelliUncovered($m, $lists, ($h['shares'])());
    $tuning = ($h['tuning'])();
    $why = match (true) {
        !jackMoverScheduleOff($own) => 'schedule',
        $m === null                 => 'moverelli',
        (bool) $uncovered           => 'moverelli_list',
        $tuning !== null            => 'tuning_schedule',
        default                     => null,
    };
    return ['ok' => $why === null, 'way' => $why === null ? 'moverelli' : null, 'why' => $why,
            'schedule' => $own['cron'] ?? ($own['set'] !== '' ? $own['set'] : null), 'tuning' => $tuning,
            'moverelli' => $m === null ? null : ['uncovered' => $uncovered, 'file' => $lists[0] ?? '']];
}

/** The other mover's name as his refusal says it (an older finding without one: Mover Tuning) */
function jackMoverName(?array $tuning): string
{
    return ($tuning['name'] ?? '') !== '' ? (string) $tuning['name'] : 'Mover Tuning';
}

/** The rule as a refusal of a real run, or null */
function jackMoverProblem(array $rule): ?Problem
{
    if ($rule['ok']) {
        return null;
    }
    $key = match ($rule['why']) {
        'moverelli'       => 'jack_mover_moverelli',
        'moverelli_list'  => 'jack_mover_moverelli_list',
        'tuning_schedule' => 'jack_mover_tuning_schedule',
        default           => 'jack_mover_schedule',
    };
    return new Problem($key, array_filter([
        'schedule' => $rule['why'] === 'tuning_schedule' ? ($rule['tuning']['cron'] ?? null) : ($rule['schedule'] ?? null),
        'name'     => $rule['why'] === 'tuning_schedule' ? jackMoverName($rule['tuning']) : null,
        'file'     => $rule['why'] === 'moverelli_list' ? ($rule['moverelli']['file'] ?? null) : null,
        'shares'   => $rule['why'] === 'moverelli_list' ? implode(', ', $rule['moverelli']['uncovered'] ?? []) : null,
    ], fn ($v) => $v !== null));
}

/** For his page and the Team Lead: the rule and whether the mover is at work */
function jackMoverState(?array $rule = null): array
{
    $rule ??= jackMoverRule();
    return ['ok' => $rule['ok'], 'way' => $rule['way'], 'why' => $rule['why'], 'schedule' => $rule['schedule'], 'running' => jackMoverRunning()]
         + ($rule['tuning'] !== null ? ['tuning' => $rule['tuning']] : [])
         + (($rule['moverelli'] ?? null) !== null ? ['moverelli' => $rule['moverelli']] : []);     // only while Ms. Moverelli is hired and set up
}

/**
 * ⟦Mover logging⟧ as it stands — what Unraid's form sends along when it applies the schedule (its select shows emhttpd's
 * live value, var.ini; share.cfg keeps it): yes | no. Neither says it: «no», Unraid's default. Anything else: null.
 */
function jackMoverLogging(array $h): ?string
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
 * jack.mover_off {confirm: true} — switches Unraid's own mover schedule off, exactly as ⟦Mover Settings⟧ does with
 * ⟦Mover schedule⟧ «Disabled» + ⟦Apply⟧: `emcmd "shareMoverSchedule=&shareMoverLogging=<as it is>&changeMover=Apply"`
 * (the form's fields — its logging kept; emhttpd empties the key in share.cfg and deletes mover.cron). Looked at
 * afterwards (emhttpd answers when it is done; up to 3 s more): share.cfg says "" and no mover.cron line runs — else
 * refused with what is still there (`jack_mover_off_failed`). Already off: nothing is sent. Logged. Only while he is
 * hired (the agent's gate, agentDeskMayAct()). ⟦Move now⟧ by hand stays possible — his guards stop a run then.
 * Ms. Moverelli's «Switch Unraid's mover schedule off…» is the same (moverelliMoverOff(); $log writes her name in the log).
 * {ok, already, was: the schedule it had, or null}
 */
function jackMoverOff(array $r, ?array $h = null, ?callable $log = null): array
{
    $log ??= fn (string $text) => logLine("Jack Streamer: $text");
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $h ??= jackMoverHost();
    $own = jackMoverOwn($h);
    if (jackMoverScheduleOff($own)) {
        return ['ok' => true, 'already' => true, 'was' => null];
    }
    $was = $own['cron'] ?? $own['set'];
    $logging = jackMoverLogging($h);
    if ($logging === null) {
        throw new Problem('jack_mover_off_failed', ['detail' => 'shareMoverLogging has a value Unraid\'s form doesn\'t know']);
    }
    $bin = $h['emcmd'];
    if (!is_file($bin) || !is_executable($bin)) {
        throw new Problem('jack_mover_off_failed', ['detail' => "$bin not found"]);
    }
    ($h['note'])($own['set'], $logging);      // the first schedule switched off is the one Ms. Moverelli's let-go brings back
    [$exit, $out, $err] = run([$bin, "shareMoverSchedule=&shareMoverLogging=$logging&changeMover=Apply"], 30);
    for ($i = 0; $i < 10 && !jackMoverScheduleOff($own = jackMoverOwn($h)); $i++) {
        ($h['wait'])(300);
    }
    $said = mb_substr(trim($out . ' ' . $err), 0, 300);
    if (!jackMoverScheduleOff($own)) {
        $left = $own['set'] !== '' ? "share.cfg still says shareMoverSchedule=\"{$own['set']}\"" : "mover.cron still runs «{$own['cron']}»";
        $log("could not switch Unraid's mover schedule off — $left" . ($exit !== 0 ? " (emcmd: exit $exit $said)" : ''));
        throw new Problem('jack_mover_off_failed', ['detail' => $left . ($exit !== 0 && $said !== '' ? " — $said" : '')]);
    }
    $log("switched Unraid's mover schedule off (Mover Settings → Disabled; it was «{$was}», mover logging kept: $logging)"
        . ($exit !== 0 ? " — emcmd said: exit $exit $said" : ''));
    return ['ok' => true, 'already' => false, 'was' => $was];
}

/**
 * Is Unraid's mover at work? /var/run/mover.pid naming a living mover (Unraid's script and Mover Tuning's age_mover write
 * it), or a `mover`, `age_mover` or `move` process — not one below $self (EmbyCache's own use of the move binary).
 */
function jackMoverRunning(?array $h = null, ?int $self = null): bool
{
    $h ??= jackMoverHost();
    $pid = (int) trim((string) @file_get_contents($h['pid'], false, null, 0, 32));
    if ($pid > 1 && str_contains((string) @file_get_contents("{$h['proc']}/$pid/cmdline", false, null, 0, 4096), 'mover')) {
        return true;
    }
    foreach (glob("{$h['proc']}/[0-9]*", GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $d) {
        if (in_array(trim((string) @file_get_contents("$d/comm", false, null, 0, 64)), JACK_MOVER_PROCS, true)
            && ($self === null || !jackProcUnder((int) basename($d), $self, $h['proc']))) {
            return true;
        }
    }
    return false;
}

/** Is $pid $self or one of its descendants? (the parents from /proc/<pid>/stat, at most 64 steps) */
function jackProcUnder(int $pid, int $self, string $proc = '/proc'): bool
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
