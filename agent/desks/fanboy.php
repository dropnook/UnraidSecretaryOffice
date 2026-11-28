<?php
declare(strict_types=1);

/*
 * Fanboy — the office's building services, the biggest fan of his fans (docs/DEVELOPMENT.md «The desks»).
 *
 * Part 1, the foundation: he looks (agent/desks/fanboy-detect.php), says the situation, keeps a store of settings that
 * never depends on a boot number, and writes a report for the makers' wish form.
 * Part 2, steering (each in its own file): fanboy-write.php — the ONE place that writes a fan output, its journal of
 * every output's state before him; fanboy-setup.php — the setup's steps as actions; fanboy-control.php — the control
 * service (one process for all zones); fanboy-drivers.php — «load drivers» on the user's click;
 * agent/fanboy-guard.php — what gives the fans back when nobody else can (scripts/agent.sh stop|release, his own cron
 * line while he is armed). testFanboyNoWrite holds who may write what. fanboy-mirror.php — what the control needs of
 * the data folder, mirrored in RAM by the agent (2026-11-22, «always on»).
 *
 * Who gives the fans back (the safety design, docs: fanboy-control-notes in the hand-over):
 *   · the control or a setup step itself — every way it ends (TERM, done, stop, the page gone, a hold), read back
 *   · the agent's tick every 5 s — a holder gone (killed hard) or hung (no beat for 30 s, ended for good first)
 *   · the guard's cron line every minute — the same, for when the agent is gone too
 *   · scripts/agent.sh stop (stop, restart, update) and release (removal) — every output, read back
 *   · the array stopping (2026-11-22, «always on»): the control steers on — agent.sh ends only what works in the data
 *     folder (a setup step, «show me this fan») and looks at the control from outside (fanboy-guard.php release-steps:
 *     a control found holding something on the array or a pool is ended, every output back); while the array isn't
 *     started the night shift looks after it as the agent's tick does (fanboy-night.php)
 *   · the server's shutdown: every process gets TERM, then 5 s (Unraid's rc.6) — the control gives back on the TERM
 *   An agent restarting itself in place touches none of it: the control runs in a session of its own, the agent finds
 *   it again by its pid file (no flap).
 *
 * A desk like the others (2026-11-16, the owner lets him out): listed, hired only on the user's click, steering nothing
 * until his setup is done and the user hands the fans to him. The switch file of his hidden time
 * (<data folder>/fanboy/show.json) means nothing any more — left where it lies, never read.
 *
 * The store (part 2 adds setup and control on top; keep this shape, add keys):
 *   <data folder>/fanboy/settings.json
 *   {"v": 1,
 *    "boards": {                                  keyed by fanboyBoardKey(): «vendor | model» — another board's entry
 *      "<board key>": {                           stays stored and counts again when that board comes back
 *        "board": {"vendor": "…", "model": "…"},
 *        "fans": {                                keyed by fanboyFanId(): «chip:address:pwmN» (no hwmonN ever)
 *          "<fan id>": {"chip": "it8696", "address": "it87.2624", "output": 4, "tach": 4 | null, …part 2's keys}
 *        },
 *        "aside": ["<source id>", …]              2026-11-27: heat sources set aside («Nicht beachten», fanboyAsideIds())
 *      }}}
 * Readers take only entries in exactly that shape and never rewrite or delete anything that doesn't match
 * (fanboyStoreRead(), fanboyResolve()): what fits nothing stays where it is, Fanboy says what is missing.
 */

// In the night shift (the array isn't started; «always on», 2026-11-22) his folder is a place that can never be there —
// as in a job without the data folder (lib/ramjob.php): whatever of his runs then reads his mirror in RAM, and a path of
// the data folder reached by mistake names nothing under /mnt.
define('FANBOY_DIR', (defined('NIGHT_MODE') && NIGHT_MODE ? OFFICE_NO_DATA : DATA_DIR) . '/fanboy');
const FANBOY_STORE = 'settings.json';
const FANBOY_REPORT_MAX = 3500;                  // bytes: the wish form's text takes 4096, the user's words go above it
const FANBOY_IPMI_FANS_MAX = 24;

desk('fanboy', [
    'fit'     => fn (): array => fanboyFit(fanboyDetect()),
    'start'   => fn () => fanboyStart(),
    'tick'    => fn () => fanboyTick(),
    'checks'  => fn (): array => fanboyChecks(fanboyBoardRaw(), readJson(fanboyStatusFile())),
    'metrics' => fn (): array => fanboyMetrics(readJson(fanboyStatusFile()), fanboyControlPid() !== null),
    // his guard's cron file, there only while he steers: the night watchman knows its line as the office's own
    'cron'    => fn (): array => [basename(FANBOY_GUARD_CRON) => [fanboyGuardCronLine()]],
    'jobs'    => [
        'fanboy-step'    => fn (array $a): int => fanboyStepMain(),
        'fanboy-show'    => fn (array $a): int => fanboyShowMain(),        // round 6: «show me this fan»
        'fanboy-restore' => fn (array $a): int => fanboyRestoreMain(),     // round 9 (H2): a safety full undone, with a kick
    ],
    // 2026-11-22 («always on»): the control runs without the data folder — `php agent.php ramjob fanboy-control`
    // (lib/ramjob.php): everything from his mirror in RAM, nothing under /mnt, whatever the array does. The ONE way it
    // runs: `job fanboy-control` is no more (fanboyLaunch())
    'ram_jobs' => [
        'fanboy-control' => fn (array $a): int => fanboyControlMain(),
    ],
    // while the array isn't started the night shift looks after him (fanboy-night.php): RAM only; true: «stay on for me»
    'night'   => fn (): bool => fanboyNight(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => fanboyScan()],
        // the text for the wish form: shown to the user in the form before anything goes (read only, like refresh)
        'report'  => fn (array $r) => ['ok' => true, 'report' => fanboyReportText($scan = fanboyDetect(), fanboyIpmiFans($scan))],
        // part 2: steering
        'look_now'      => fn (array $r) => fanboyLookNow(),
        'drivers_load'  => fn (array $r) => fanboyDriversLoad() + ['state' => fanboyScan()],
        'drivers_undo'  => fn (array $r) => fanboyDriversUndo() + ['state' => fanboyScan()],
        // 2026-11-20: the fan chip's id, on the click only; 2026-11-21 (the owner: «A+B, aber auch für Gigabyte»): on a board
        // never read by itself (maker, no_maker) only with confirm_risk: true after the page's warning
        'chip_read'     => fn (array $r) => fanboyChipRead(null, isset($r['confirm_risk']) && boolField($r, 'confirm_risk')) + ['state' => fanboyScan()],
        'chip_report'   => fn (array $r) => ['ok' => true, 'report' => fanboyChipReportText(fanboyDetect())],      // read only: the chip's id and the board's name
        'test_up'       => fn (array $r) => fanboyStepStart('test_up', idList($r, 'fans'), optBool($r, 'quiet', false), optBool($r, 'warm_ok', false)),     // round 8: quiet
        'measure'       => fn (array $r) => fanboyStepStart('measure', idList($r, 'fans'), false, optBool($r, 'warm_ok', false)),       // 2026-11-20: warm_ok, the user's tick
        'step_alive'    => function (array $r): array { fanboyStepAliveTouch(); return ['ok' => true, 'step' => fanboyStepRead()]; },
        'step_stop'     => fn (array $r) => fanboyStepStopAction(),
        'step_next'     => fn (array $r) => fanboyStepNext($r),        // round 8: «Gefunden — weiter»
        'hub_look'      => fn (array $r) => fanboyHubLookStart($r),     // round 14: several fans on one connector, looked at
        'answer'        => fn (array $r) => fanboyAnswer($r),
        'role'          => fn (array $r) => fanboyRole($r),
        'fan_name'      => fn (array $r) => fanboyFanName($r),         // round 8: «Wo sitzt er?» while testing
        'fan_save'      => fn (array $r) => fanboyFanSave($r),         // 2026-11-20: an output's details in one request
        'zones_propose' => fn (array $r) => ['ok' => true, 'zones' => fanboyZonesPropose($scan = fanboyDetect(), fanboyBoardRaw($scan['board']) ?? [])],
        'zones_save'    => fn (array $r) => fanboyZonesSave($r),
        'aside'         => fn (array $r) => fanboyAside($r),          // 2026-11-27: «Nicht beachten» / «Wieder beachten» per heat source
        'dry_run'       => fn (array $r) => fanboyDryRun(),
        'take_over'     => fn (array $r) => fanboyTakeOver($r) + ['state' => fanboyScan()],       // round 12 fix: uncovered_ok
        'hand_back'     => fn (array $r) => fanboyHandBack() + ['state' => fanboyScan()],
        // part 3: the page's own small actions — «give back to» for a fan found on a fixed value, a fan's look
        // (model, size, colour: pictures only), the history part for the curve view (read only)
        'back'          => fn (array $r) => fanboyBack($r),
        'fan_look'      => fn (array $r) => fanboyFanLook($r),
        'history'       => fn (array $r) => ['ok' => true, 'state' => fanboyHistoryPart(fanboyHistoryHours($r))],
        // his own tile on Unraid's Dashboard: off, speeds, speeds with history (fanboy-dash.php)
        'dashboard'     => fn (array $r) => fanboyDashSet($r),
        // round 6: «watch fans I don't steer» on or off (fanboy-watch.php)
        'watch'         => fn (array $r) => fanboyWatchSet($r),
        // round 6: «show me this fan» — an output to 100 % for 5 s, then back exactly (fanboy-show.php)
        'show'          => fn (array $r) => fanboyShowStart($r),
        'show_stop'     => fn (array $r) => fanboyShowStop($r),          // 4b: «Found it — stop»
        'safety_back'   => fn (array $r) => fanboyRestoreStart($r),      // round 9 (H2): «Zurück auf den Wert von vorher»
        'stop_all'      => fn (array $r) => fanboyStopAll(),             // round 9 (H4): «Alles stoppen»
    ],
]);

/**
 * A new look, kept as his state (data/fanboy.json): part 1's look, and on top who steers now and why (the control's
 * status in RAM), his setup on this board, a step running, the drivers he loaded
 */
function fanboyScan(): array
{
    $state = fanboyDetect();
    $settings = fanboyBoardRaw($state['board']);
    $control = fanboyControlState($settings);
    $state['control'] = $control;
    $state['who'] = fanboyWhoNow($state, $control, $settings);
    $state['setup'] = fanboySetupState($state, $settings);
    $state['step'] = fanboyStepRead();
    $state['drivers'] = fanboyDriversState($state['board']);
    $state['chip'] = fanboyChipState($state);           // the fan chip's id read on a click (fanboy-chipid.php): what it needs
    $state['suggest'] = fanboyChipSuggest($state['suggest'], $state['chip']);      // what fits, narrowed once the chip is known
    // 2026-11-22, the driver doctor (fanboy-doctor.php): a hint only where he sees its sign (read only)
    $state['doctor'] = fanboyDoctor($state, null, is_array($settings['fans'] ?? null) ? $settings['fans'] : [], $state['chip']);
    $state['dashboard'] = fanboyDashState();            // his Dashboard tile's setting (fanboy-dash.php), its mirror in RAM
    $state['show'] = fanboyShowRead();                  // round 6: «show me this fan» running or just done
    $state['safety'] = fanboySafetyFull() ?: new stdClass();     // round 9 (H2): outputs he set to full for safety
    $state['restore'] = fanboyRestoreRead();            // round 9 (H2): «back to the value before» running or just done
    $state['idle'] = fanboyIdleState();                 // round 6: the fans he doesn't steer, watched (fanboy-watch.php)
    $state['disk_allow'] = fanboyPollAllowance($state['poll'] ?? null);      // round 6: °C the zone logic adds to an old disk value
    // 2026-11-27: the warmest spots without the sources the user set aside (the look alone leaves out only a drive's further sensors)
    if (($aside = fanboyAsideIds($settings)) !== []) {
        $state['hottest'] = fanboyHottest($state['chips'], $state['disks'], $aside);
        $state['hottest_kinds'] = fanboyHottestKinds($state['chips'], $state['disks'], $aside);
        $state['hottest_lead'] = fanboyHottestLead($state['hottest_kinds']);
    }
    writeAtomic(deskFile('fanboy'), jsonEncode($state));
    return $state;
}

/** For the Team Lead: Fanboy fits every server (he can always say what he finds); the reason says what that is */
function fanboyFit(array $scan): array
{
    $code = $scan['situation']['code'];
    if ($code === 'no_output') {
        // the fan chip read on a click (fanboy-chipid.php): named, and what it needs — the Team Lead says the same
        $chip = fanboyChipState($scan);
        if (!empty($chip['chip']['family']) && $chip['plugin'] !== null) {
            // a board never read (Gigabyte): its maker's boards carry ITE — the plugin by name
            return fit(true, 'no_output_family', ['chip' => (string) $chip['chip']['maker'], 'plugin' => $chip['plugin']['name'], 'author' => $chip['plugin']['author']]);
        }
        if (($chip['chip']['name'] ?? null) !== null && in_array($chip['need'], ['unraid', 'plugin'], true)) {
            return fit(true, 'no_output_' . $chip['need'], ['chip' => $chip['chip']['maker'] . ' ' . $chip['chip']['name'],
                'module' => (string) $chip['chip']['module'], 'plugin' => (string) ($chip['plugin']['name'] ?? ''), 'author' => (string) ($chip['plugin']['author'] ?? '')]);
        }
    }
    return fit(true, $code === 'ok' ? 'yes' : $code, $code === 'other' ? ['who' => implode(', ', $scan['situation']['names'] ?? [])] : []);
}

// ===================================================================== the store: identity without the boot number

function fanboyStoreFile(?string $dir = null): string
{
    return ($dir ?? FANBOY_DIR) . '/' . FANBOY_STORE;
}

/** The board's key: «vendor | model», spaces folded — '' parts stay '' (a board saying nothing is one board too) */
function fanboyBoardKey(array $board): string
{
    $f = fn ($s) => trim((string) preg_replace('/\s+/', ' ', is_string($s) ? $s : ''));
    if ($f($board['vendor'] ?? '') === '' && $f($board['model'] ?? '') === '' && is_string($board['chip'] ?? null) && $board['chip'] !== '') {
        return ' | ' . ($f($board['product'] ?? '') ?: '?') . ' | ' . $f($board['chip']);     // round 5: no DMI board — product + chip
    }
    return $f($board['vendor'] ?? '') . ' | ' . $f($board['model'] ?? '');
}

/** A fan's id: the chip's name, its device's address and the output — «it8696:it87.2624:pwm4» */
function fanboyFanId(string $chip, string $address, int $output): string
{
    return "$chip:$address:pwm$output";
}

/**
 * A fan's test verdict as it counts — round 8 (G3): «ignored» saved before the round-8 fix without its reason (taken_back:
 * the BIOS took a write back; unpaired: judged over every output of the chip) may have come from the early judgement —
 * it counts as not yet tested (a retest asks nothing more than the click). null: not tested.
 */
function fanboyFound(mixed $f): ?string
{
    $found = is_array($f) && is_string($f['found'] ?? null) ? $f['found'] : null;
    return $found === 'ignored' && !in_array($f['why'] ?? null, ['taken_back', 'unpaired'], true) ? null : $found;
}

/** One stored fan in exactly its shape, else null (never repaired, never removed) */
function fanboyStoredFan(mixed $f): ?array
{
    if (!is_array($f) || !is_string($f['chip'] ?? null) || !preg_match(FANBOY_NAME_RE, $f['chip'])
        || !is_string($f['address'] ?? null) || !preg_match(FANBOY_NAME_RE, $f['address'])
        || !is_int($f['output'] ?? null) || $f['output'] < 1 || $f['output'] > 99
        || (array_key_exists('tach', $f) && $f['tach'] !== null && (!is_int($f['tach']) || $f['tach'] < 1 || $f['tach'] > 99))) {
        return null;
    }
    return $f + ['tach' => null];
}

/**
 * The store as kept: {v, boards: {key: {board, fans: {id: fan}}}} — only entries in exactly their shape; the file is
 * never written here (and nothing in it ever deleted because it doesn't match). No file or a broken one: no boards.
 */
function fanboyStoreRead(?string $file = null): array
{
    $j = readJson($file ?? fanboyStoreFile());
    $out = ['v' => 1, 'boards' => []];
    foreach (is_array($j['boards'] ?? null) ? $j['boards'] : [] as $key => $b) {
        if (!is_string($key) || !is_array($b) || !is_array($b['board'] ?? null)) {
            continue;
        }
        $fans = [];
        foreach (is_array($b['fans'] ?? null) ? $b['fans'] : [] as $id => $f) {
            if (is_string($id) && ($fan = fanboyStoredFan($f)) !== null) {
                $fans[$id] = $fan;
            }
        }
        $out['boards'][$key] = ['board' => ['vendor' => (string) ($b['board']['vendor'] ?? ''), 'model' => (string) ($b['board']['model'] ?? '')], 'fans' => $fans];
    }
    return $out;
}

/** This board's settings (null: none yet — a new board, the others' stay stored) and the other boards kept */
function fanboyBoardSettings(array $store, array $board): array
{
    $key = fanboyBoardKey($board);
    return ['key' => $key, 'here' => $store['boards'][$key] ?? null, 'others' => array_values(array_diff(array_keys($store['boards']), [$key]))];
}

/**
 * Where a stored fan is now, looked up in a fresh scan on every use (never a stored path):
 *   how: here (same chip, same address) · moved (the address changed, exactly one chip of that name: taken) ·
 *        missing (no chip of that name) · ambiguous (the address gone, several chips of that name: none taken) ·
 *        no_output (the chip is there, the output isn't)
 *   path: the output's file now (only for here and moved; the hwmon root of the scan + this boot's hwmonN), tach: the
 *   fingerprint — the paired tach input there now (true), gone (false), none stored (null).
 */
function fanboyResolve(array $fan, array $scan, string $hwmon = FANBOY_HWMON): array
{
    $same = array_values(array_filter($scan['chips'] ?? [], fn ($c) => $c['name'] === $fan['chip']));
    $at = array_values(array_filter($same, fn ($c) => $c['address'] === $fan['address']));
    $how = $at ? 'here' : (count($same) === 1 ? 'moved' : ($same ? 'ambiguous' : 'missing'));
    $chip = $at[0] ?? (count($same) === 1 ? $same[0] : null);
    if ($chip === null) {
        return ['how' => $how, 'path' => null, 'address' => null, 'tach' => null];
    }
    if (!in_array($fan['output'], array_column($chip['outputs'], 'n'), true)) {
        return ['how' => 'no_output', 'path' => null, 'address' => $chip['address'], 'tach' => null];
    }
    return [
        'how'     => $how,
        'path'    => rtrim($hwmon, '/') . '/' . $chip['hwmon'] . '/pwm' . $fan['output'],
        'address' => $chip['address'],
        'tach'    => $fan['tach'] === null ? null : in_array($fan['tach'], array_column($chip['fans'], 'n'), true),
    ];
}

// ===================================================================== the report for the wish form

/**
 * The names of a BMC's fan sensors (IPMI boards only), read with ipmitool when it is there (the IPMI plugin brings it):
 * `ipmitool sdr type Fan`, read only. [] when there is no BMC, no ipmitool or it says nothing usable.
 */
function fanboyIpmiFans(array $scan, ?string $bin = null): array
{
    if (($scan['ipmi'] ?? null) === null) {
        return [];
    }
    $bin ??= array_values(array_filter(['/usr/bin/ipmitool', '/usr/sbin/ipmitool', '/usr/local/bin/ipmitool', '/usr/local/sbin/ipmitool'], 'is_executable'))[0] ?? '';
    if ($bin === '' || !is_executable($bin)) {
        return [];
    }
    [$exit, $out] = run([$bin, 'sdr', 'type', 'Fan'], 15);
    $names = [];
    foreach ($exit === 0 ? explode("\n", $out) : [] as $line) {
        $name = trim(explode('|', $line)[0]);
        if (preg_match('/^[A-Za-z0-9 _.#-]{1,32}$/D', $name) && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return array_slice($names, 0, FANBOY_IPMI_FANS_MAX);
}

/**
 * The report for the makers' wish form — technical only (the concept's list): the board's model, chips and drivers,
 * how many outputs and tach inputs, the kinds of sensors, kernel and Unraid version, the situation; for an IPMI board
 * the BMC's manufacturer and product id, firmware revision and its fan sensors' names. NEVER: the server's name,
 * serial numbers, disk ids or names, paths, a device's address, names the user gave. English (it is read by the makers),
 * plain lines, no «/» at all (testFanboyReport).
 */
function fanboyReportText(array $scan, array $ipmiFans = []): string
{
    $clean = fn ($s) => trim((string) preg_replace('/[^A-Za-z0-9 ,.()_+:#-]+/', ' ', (string) $s));
    $b = $scan['board'] ?? [];
    $lines = ['Fanboy detection report v1'];
    $lines[] = 'Board: ' . ($clean($b['vendor'] ?? '') ?: '?') . ' - ' . ($clean($b['model'] ?? '') ?: '?');
    $lines[] = 'Kernel: ' . ($clean($scan['kernel'] ?? '') ?: '?') . ' · Unraid: ' . ($clean($scan['unraid'] ?? '') ?: '?');
    $s = $scan['situation'] ?? ['code' => 'unknown'];
    $what = $clean($s['code'] ?? '?');
    if (!empty($s['who'])) {
        $what .= ' (' . implode(', ', array_map($clean, $s['who'])) . ')';
    }
    if (!empty($s['plugins'])) {
        $what .= ' (plugin ' . implode(', ', array_map($clean, $s['plugins'])) . ', module ' . implode(', ', array_map($clean, $s['modules'] ?? [])) . ' not loaded)';
    }
    $lines[] = "Situation: $what";
    $lines[] = 'Chips:';
    $kinds = [];
    foreach ($scan['chips'] ?? [] as $c) {
        $turning = count(array_filter($c['fans'], fn ($f) => ($f['rpm'] ?? 0) > 0));
        $modes = array_count_values(array_map(fn ($o) => (string) ($o['mode'] ?? '?'), $c['outputs']));
        ksort($modes);
        $line = '- ' . $clean($c['name']) . ' (driver ' . ($c['module'] !== null ? $clean($c['module']) : '-') . ', ' . $clean($c['kind']) . '): '
            . 'outputs ' . count($c['outputs']);
        if ($c['outputs']) {
            $line .= ' (auto mode ' . (array_filter($c['outputs'], fn ($o) => $o['auto']) ? 'yes' : 'no') . '; now '
                . implode(', ', array_map(fn ($m, $n) => "$n $m", array_keys($modes), $modes)) . ')';
        }
        $line .= ', tach inputs ' . count($c['fans']) . " ($turning turning), temperatures " . count($c['temps']);
        $lines[] = $line;
        // round 5 (forum 3.18): per output what the test found (obeys / ignored / inverted …), no mode file, PWM or DC
        $mine = is_array($scan['board'] ?? null) ? ((fanboyBoardRaw($scan['board']) ?? [])['fans'] ?? []) : [];
        $dir = fanboyPaths()['hwmon'] . '/' . $c['hwmon'];
        foreach ($c['outputs'] as $o) {
            $found = $mine[fanboyFanId($c['name'], $c['address'], $o['n'])]['found'] ?? null;
            $pin = fanboyReadInt("$dir/pwm{$o['n']}_mode");
            $lines[] = "  pwm{$o['n']}: " . match ($found) { 'tach', 'silent' => 'obeys', null => 'not tested', default => $clean((string) $found) }
                . ($o['enable'] === null ? ', no enable' : '') . ($pin === null ? '' : ($pin === 0 ? ', DC' : ', PWM'));
        }
        if ($c['temps']) {
            $kinds[$c['kind']] = ($kinds[$c['kind']] ?? 0) + count($c['temps']);
        }
    }
    if (!($scan['chips'] ?? [])) {
        $lines[] = '- none' . (empty($scan['hwmon']) ? ' (no hwmon at all)' : '');
    }
    ksort($kinds);
    $lines[] = 'Sensor kinds: ' . ($kinds ? implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($kinds), $kinds)) : 'none');
    $disks = $scan['disks'] ?? [];
    $un = array_filter($disks, fn ($d) => ($d['type'] ?? '') === 'unassigned');
    $lines[] = 'Unraid disks: ' . (count($disks) - count($un)) . ' (' . count(array_filter($disks, fn ($d) => $d['rotational'] && ($d['type'] ?? '') !== 'unassigned')) . ' rotating)';
    // round 13b: outside array and pools (devs.ini) — counts only, never an id
    $lines[] = 'Disks outside array and pools: ' . count($un) . ($un ? ' (' . count(array_filter($un, fn ($d) => $d['rotational'])) . ' rotating, '
        . count(array_filter($un, fn ($d) => $d['asleep'])) . ' asleep, ' . count(array_filter($un, fn ($d) => !empty($d['in_use']))) . ' in use without a temperature)' : '');
    foreach ($scan['controllers'] ?? [] as $x) {
        // 2026-11-21: the outputs its settings name that aren't there — by their file name only (no path in the report)
        $gone = array_map(fn ($t) => $clean(basename((string) $t)), $x['missing'] ?? []);
        $lines[] = 'Other controller: ' . $clean($x['id']) . ' (settings ' . ($x['settings'] ? 'on' : 'off') . ', loop ' . ($x['running'] ? 'running' : 'not running') . ')'
            . ($gone ? ', set to ' . implode(', ', $gone) . ' which is not there' : '');
    }
    if (!empty($scan['no_fan_chip'])) {
        $lines[] = 'Fan chip driver: none loaded (no chip with a fan output or a fan input)';
    }
    // the driver doctor's signs, one line each (2026-11-22)
    $mine = is_array($scan['board'] ?? null) ? ((fanboyBoardRaw($scan['board']) ?? [])['fans'] ?? []) : [];
    array_push($lines, ...fanboyDoctorLines(is_array($scan['doctor'] ?? null) ? $scan['doctor'] : fanboyDoctor($scan, null, is_array($mine) ? $mine : []), $scan));
    if (!empty($scan['temps_missing'])) {
        $lines[] = 'Temperatures not seen: ' . implode(', ', array_map(fn ($k) => ['board' => 'board sensors', 'cpu' => 'CPU'][$k] ?? 'other', (array) $scan['temps_missing']));
    }
    $there = array_filter($scan['tools'] ?? [], fn ($t) => $t['installed'] || $t['active']);
    $lines[] = 'Plugins: ' . ($there ? implode(', ', array_map(fn ($t) => $clean($t['name']) . ' installed'
        . ($t['loaded'] === null ? '' : ($t['loaded'] ? ' (module loaded)' : ' (module not loaded)')) . ($t['active'] ? ' (steering)' : ''), $there)) : 'none of those Fanboy knows');
    $lines[] = 'Start option acpi_enforce_resources=lax: ' . (!empty($scan['acpi_lax']) ? 'set' : 'not set');
    // 2026-11-20: the fan chip's id as read on the user's click (ports, id, the family whose key opened it) — no serial
    $lines[] = $clean(fanboyChipReportLine(is_array($scan['chip'] ?? null) ? $scan['chip'] : fanboyChipState($scan)));
    // round 5 (forum 3.18): the driver plugins' versions (a regression was findable only by them), how fresh the disks'
    // temperatures are, a virtual machine, two drivers on one chip
    foreach ($there as $t) {
        $plg = is_link(fanboyPaths()['plugins'] . "/{$t['id']}.plg") ? '' : (string) @file_get_contents(fanboyPaths()['plugins'] . "/{$t['id']}.plg", false, null, 0, 8192);
        if ($t['kind'] === 'driver' && preg_match('/<PLUGIN\b[^>]*\bversion="([^"]{1,40})"/i', $plg, $m)) {
            $lines[] = 'Driver plugin ' . $clean($t['id']) . ': version ' . $clean($m[1]);
        }
    }
    $lines[] = 'Disk temperatures polled every: ' . (isset($scan['poll']) ? (int) $scan['poll'] . ' s (poll_attributes)' : 'unknown')
        . (fanboyPollAllowance($scan['poll'] ?? null) > 0 ? '; zone logic adds ' . fanboyPollAllowance($scan['poll']) . ' degrees to disk values' : '');
    $lines[] = 'Virtual machine: ' . (!empty($scan['vm']) ? 'yes' : 'no');
    if (!empty($scan['twins'])) {
        $lines[] = 'Two drivers on one chip: ' . implode(', ', array_map($clean, $scan['twins']));
    }
    if (($i = $scan['ipmi'] ?? null) !== null) {
        $lines[] = 'IPMI: BMC ' . ($i['bmc'] ? 'seen' : 'not seen') . '; manufacturer id ' . ($clean($i['manufacturer_id'] ?? '') ?: '?')
            . ', product id ' . ($clean($i['product_id'] ?? '') ?: '?') . ', device id ' . ($clean($i['device_id'] ?? '') ?: '?')
            . ', firmware ' . ($clean($i['firmware_revision'] ?? '') ?: '?') . ', IPMI ' . ($clean($i['ipmi_version'] ?? '') ?: '?');
        $lines[] = 'IPMI fan sensors: ' . ($ipmiFans ? implode(', ', array_map($clean, $ipmiFans)) : 'not read (no ipmitool)');
    }
    // round 5 (forum 3.9): outputs whose driver refused every value while he steered (the control's last status)
    $st = readJson(fanboyRunDir() . '/control.json');
    if (is_array($st['refuses'] ?? null) && $st['refuses']) {
        $lines[] = 'Driver refused values (EIO/EACCES) for: ' . implode(', ', array_map($clean, $st['refuses']));
    }
    // round 4: a CPU cooler steered by Fanboy on the owner's risk (no names: only that and since when)
    $mine = is_array($scan['board'] ?? null) ? (fanboyBoardRaw($scan['board']) ?? []) : [];
    $own = array_filter(is_array($mine['fans'] ?? null) ? $mine['fans'] : [], fn ($f) => is_array($f) && fanboyCpuOwn($f));
    if ($own) {
        $lines[] = 'CPU cooler: steered by Fanboy on the owner\'s own risk since ' . gmdate('Y-m-d', min(array_map(fn ($f) => $f['cpu_own']['at'], $own)));
    }
    $text = implode("\n", $lines);
    return strlen($text) > FANBOY_REPORT_MAX ? mb_strcut($text, 0, FANBOY_REPORT_MAX, 'UTF-8') : $text;
}

// ===================================================================== part 2: the service around the control

const FANBOY_TICK_EVERY = 5.0;          // the agent's look at the holders and the control, seconds
const FANBOY_CRASH_PAUSE = 30.0;        // a control that died: started again after this long …
const FANBOY_CRASH_WINDOW = 1800.0;     // … unless it died FANBOY_CRASH_MAX times within this: then the BIOS keeps them
const FANBOY_CRASH_MAX = 3;

/** At the agent's start: the drivers that helped on this board loaded again (before the control steers), holders gone given back */
function fanboyStart(): void
{
    // the guard ended the control at the array's stop because it held something there (fanboy-guard.php release-steps): no
    // agent ran to say so — said now, once
    $brake = readJson(fanboyBrakeFile());
    if (is_array($brake) && ($brake['by'] ?? null) === 'stop') {
        logLine('Fanboy: at the array\'s stop his control was ended — it must never keep the array from stopping, and '
            . officeHoldSay(['why' => $brake['why'] ?? '', 'what' => (string) ($brake['what'] ?? ''), 'mount' => (string) ($brake['mount'] ?? '')]
                + (is_int($brake['holder'] ?? null) ? ['pid' => $brake['holder']] : [])));
        fanboyTellOnce('holds', 'holds', [], 'warning');
    }
    @unlink(fanboyBrakeFile());     // a control that stopped itself (fanboyBrake()) may be started again: the agent starts anew
    @unlink(fanboyCrashFile());     // … and its deaths are counted anew (as when they were the agent's own memory)
    try {
        fanboyHandBackTake();       // «give the fans back» clicked while the array wasn't started: made true in his store now
    } catch (Throwable $e) {
        logLine('Fanboy: the hand-back asked for while the array wasn\'t started could not be kept: ' . $e->getMessage());
    }
    fanboyHistorySync(true);        // after a boot: the data folder's history into RAM, for the control and the page (fanboy-mirror.php)
    fanboyMirrorKeep(true);         // his mirror in RAM says what the data folder says (fanboy-mirror.php)
    fanboyMirrorFlashKeep(true);    // … and its copy on the flash, for the next boot (there only while he is hired and armed)
    fanboyDriversBoot();
    fanboyWatch();
    fanboyGuardCron((fanboyBoardRaw()['armed'] ?? false) === true);       // the guard's line only while he is armed
}

/**
 * The curve view's history: per zone of this board the points of the last $hours (a point a minute for 12 hours, older
 * ones a 5-minute span each — fanboyHistoryThin()) [time, warmest °C, level %, rpm], as the control keeps it in RAM (or
 * the data folder's copy after a restart or a boot). The part `history` (desk.json "parts": a look a minute) carries a
 * day — the tile «Today» and the periods up to 24 h; 3 days only when the page asks (`history {hours: 72}`). Read only.
 */
function fanboyHistoryPart(int $hours = 24, ?int $wall = null): array
{
    $t = $wall ?? time();
    $key = fanboyBoardKey(fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']));
    $zones = [];
    foreach (fanboyHistoryLoad($key, $t, $curves) as $zid => $pts) {
        $in = array_values(array_filter($pts, fn ($p) => $p[0] >= $t - $hours * 3600));
        if ($in) {
            $zones[$zid] = $in;
        }
    }
    // each curve's line (2026-11-20): the spans that reach into the period (one before its start: the line comes in from the edge)
    foreach ($curves as $zid => $h) {
        $curves[$zid]['rows'] = array_filter($h['rows'], fn ($at) => $at > $t - $hours * 3600 - 2 * FANBOY_HISTORY_CURVE, ARRAY_FILTER_USE_KEY);
    }
    // `now`: the moment the points are read against (the page draws «the last hours» back from it, never the browser's clock);
    // the points of four as before, who led beside them (`src`, `leads`), each curve's line (`curves`: fanboyHistoryPack())
    return ['v' => 1, 'time' => $t, 'now' => $t, 'hours' => $hours, 'every' => (int) FANBOY_HISTORY_EVERY, 'thin' => FANBOY_HISTORY_THIN,
        'full' => FANBOY_HISTORY_FULL] + fanboyHistoryPack($zones, array_filter($curves, fn ($h) => $h['rows'] !== []));
}

/** The period the page asks for — hours, one of FANBOY_HISTORY_PERIODS (read strictly); none: a day (the part's look) */
function fanboyHistoryHours(array $r): int
{
    $h = optInt($r, 'hours', 24, 1);
    if (!in_array($h, FANBOY_HISTORY_PERIODS, true)) {
        throw new Problem('fanboy_bad_period');
    }
    return $h;
}

/**
 * The agent's tick (every FANBOY_TICK_EVERY s on the monotonic clock, a few file reads): outputs whose holder is gone
 * given back; the control started when it should run and doesn't (after a death: a pause; too many deaths: not again)
 */
function fanboyTick(?float $now = null): void
{
    static $next = 0.0;
    $now ??= fanboyMono();
    if ($now < $next) {
        return;
    }
    $next = $now + FANBOY_TICK_EVERY;
    try {
        fanboyInitialSync();                // the outputs' states before him from the journal into the data folder's copy …
        fanboyHistorySync(false, $now);     // … and his history, every ten minutes (what a control without the data folder can't write itself)
        fanboyMirrorKeep(false, $now);      // the mirror follows the data folder, whoever changed it (fanboy-mirror.php)
        fanboyMirrorFlashKeep(false, $now); // … and its copy on the flash follows the mirror (for a boot: before the array starts)
    } catch (Throwable $e) {
        logLine('Fanboy: his mirror in RAM could not be kept: ' . $e->getMessage());
    }
    fanboyWatch();
    fanboyBackCheck();
    fanboyEnsureControl($now);
    fanboyIdleTick($now);           // round 6: the fans he doesn't steer, watched (notify only)
    fanboyStopAllTick();            // round 9 (H4): «Alles stoppen» — the safety fulls back once nothing else runs
}

/**
 * Round 5 (forum 3.4): a fan the mainboard didn't take back after a give-back is at full speed now (fanboyGivenCheck()) —
 * said once per output and boot, an alert, and in the log. $wall: the tests' clock.
 */
function fanboyBackCheck(?int $wall = null): array
{
    $full = fanboyGivenCheck($wall);
    if (!fanboyRamMode()) {
        fanboyAutoSeenKeep();       // into his store — the agent's part (the journal keeps what the night shift saw until then)
    }
    // 2026-11-22: what the guard found while no agent ran (its look after a release — the array's stop —, its cron line):
    // the journal says so (`given.full`, still at full: fanboySafetyFull()); told here, once per output and boot like his own
    $found = array_filter(fanboySafetyFull(), fn ($x) => $x['why'] === 'not_back');
    $told = $found ? (readJson(fanboyRunDir() . '/told.json') ?? []) : [];
    $late = array_filter($found, fn ($id) => !isset($full[$id]) && !isset($told["not_back:$id"]), ARRAY_FILTER_USE_KEY);
    if (!$full && !$late) {
        return [];
    }
    $fans = (fanboyBoardRaw() ?? [])['fans'] ?? [];
    $name = fn (string $id, mixed $output) => is_string($fans[$id]['name'] ?? null) && $fans[$id]['name'] !== '' ? $fans[$id]['name']
        : officeNotifyText('fanboy', 'out.name', ['n' => is_int($output) ? $output : (int) preg_replace('/^.*:pwm/', '', $id)], officeNotifyLang());
    foreach ($full as $id => $x) {
        logLine("Fanboy: $id not taken back by the mainboard ({$x['rpm']} rpm, {$x['before']} before Fanboy) — set to full speed" . ($x['ok'] ? '' : ' — NOT written'));
        fanboyTellOnce("not_back:$id", 'not_back', ['fan' => $name($id, $x['output'])], 'alert');
    }
    foreach ($late as $id => $x) {
        logLine("Fanboy: $id not taken back by the mainboard — the guard set it to full speed while the agent was away" . ($x['at'] !== null ? ' (' . date('Y-m-d H:i:s', $x['at']) . ')' : ''));
        fanboyTellOnce("not_back:$id", 'not_back', ['fan' => $name($id, null)], 'alert');
    }
    return $full;
}

/**
 * Round 12 correction: «darf stehen» for the setup's fan as the control will take it — the stand plan, and its zone's
 * curve must be able to ask for nothing (curve_never_zero). [in effect, why not]
 */
function fanboyFanStandState(array $f, ?array $settings): array
{
    // the fan's plan (round 14: a hub's margin; round 12: the standstill part) — and its zone's curve must be able to ask
    // for nothing (fanboyCurveNeverZero())
    $p = fanboyFanPlan($f);
    if ($p['off_ok']) {
        foreach (is_array($settings['zones'] ?? null) ? $settings['zones'] : [] as $z) {
            if (is_array($z) && in_array($f['id'] ?? null, is_array($z['fans'] ?? null) ? $z['fans'] : [], true) && fanboyCurveNeverZero($z, fanboyCpuOwn($f))) {
                return [false, 'curve_never_zero'];
            }
        }
    }
    return [$p['off_ok'], $p['off_why']];
}

/**
 * For the setup's fan, as numbers (the page shows these, mirrors nothing): `floor` (the control's floor for it), `start`
 * (its start from standing; null outside a zone), `curve` (fanboyFanCurve(): null outside a zone or in an rpm zone) and
 * `fallback` — what it goes back to if he is not there: the journal's state for this output when he took it this boot
 * (from: journal), else what a take would keep (from: planned — the state found now, or the chip's automatic where «give
 * back» says so and the chip has one), and the seconds for each way while it turns and while it stands at 0.
 */
function fanboySetupFanPlan(string $id, array $f, array $ctl, ?array $before, ?array $journal): array
{
    $fan = $ctl['fans'][$id] ?? null;
    $z = $fan !== null ? ($ctl['zones'][$fan['zone']] ?? null) : null;
    $o = $journal;
    $from = 'journal';
    if (!is_array($o) || !is_array($o['initial'] ?? null)) {
        $from = 'planned';
        $choice = fanboyCpuOwn($f) ? 'auto' : (in_array($f['back'] ?? null, FANBOY_BACKS, true) ? $f['back'] : FANBOY_BACK_DEFAULT);
        $initial = $before === null ? null : ['value' => $before['value'], 'enable' => $before['enable']];
        $o = $initial === null ? null : ['initial' => $initial,
            'back' => $choice === 'auto' && is_int($before['auto'] ?? null) && ($before['enable'] ?? null) === 1 ? ['value' => $before['value'], 'enable' => $before['auto']] : null];
    }
    $turning = fanboyFallback($o, ['value' => null]);
    $standing = fanboyFallback($o, ['value' => 0]);
    return [
        'floor' => $fan['floor'] ?? (fanboyFanOurs($f) ? fanboyFloorOf($f) : null),
        'start' => $fan['start'] ?? null,
        'curve' => $fan !== null && $z !== null ? fanboyFanCurve($z, $fan) : null,
        'fallback' => $turning === null ? null : ['from' => $from, 'to' => $turning['to'], 'within' => $turning['within'],
            'within_standing' => $standing['within'] ?? null, 'check' => $turning['check']],
    ];
}

/**
 * Round 12 correction: a give-back to the chip's automatic SEEN to work (fanboyGivenCheck(): read back as automatic, the
 * fan turning) — kept per board in the store as `auto_back_seen {at, output}`, the newest. Never set by anything else.
 */
function fanboyAutoSeenKeep(): void
{
    $seen = null;
    foreach (fanboyJournal()['outputs'] as $id => $o) {
        $at = is_array($o['given'] ?? null) && is_int($o['given']['auto_ok'] ?? null) ? $o['given']['auto_ok'] : null;
        if ($at !== null && ($seen === null || $at > $seen['at'])) {
            $seen = ['at' => $at, 'output' => (string) $id];
        }
    }
    if ($seen === null || ((fanboyBoardRaw() ?? [])['auto_back_seen']['at'] ?? 0) >= $seen['at']) {
        return;
    }
    fanboyStoreUpdate(function (array $e) use ($seen): array {
        $e['auto_back_seen'] = $seen;
        return $e;
    });
}

/** Holders gone (killed hard) or hung: their outputs back with the BIOS, said in the log */
function fanboyWatch(): array
{
    if (!is_file(fanboyRunDir() . '/journal.json') || !fanboyHeld()) {
        return [];
    }
    $back = fanboyGiveBack(null, 'holder_gone', null, true);
    if ($back) {
        $bad = array_keys(array_filter($back, fn ($x) => !$x['ok'] && $x['why'] !== 'gone'));
        $who = array_values(array_unique(array_map(fn ($x) => ($x['owner']['kind'] ?? '?') . ' PID ' . ($x['owner']['pid'] ?? '?') . ' ' . ($x['holder'] ?? ''), $back)));
        logLine('Fanboy: a holder was gone (' . implode(', ', $who) . ') — ' . count($back) . ' outputs back with the BIOS' . ($bad ? ', NOT read back as before: ' . implode(', ', $bad) : ''));
    }
    return $back;
}

/** This board's settings, read again only when the store changed */
function fanboySettingsCached(): ?array
{
    static $stamp = null, $settings = null;
    clearstatcache(true, fanboyStoreFile());
    $m = @filemtime(fanboyStoreFile());
    if ($m !== $stamp) {
        $stamp = $m;
        $settings = fanboyBoardRaw();
    }
    return $settings;
}

/** The control's deaths and starts as the agent and the night shift count them, one after the other: in RAM, this boot's */
function fanboyCrashFile(): string
{
    return fanboyRunDir() . '/crashes.json';
}

/**
 * {deaths: [seconds on the monotonic clock — the same for every process of this boot], seen: the pid of the dead control
 * counted last (never twice), launched: when a start was tried last} — another boot's file, a broken one: nothing yet
 *
 * @return array{deaths: list<float>, seen: ?int, launched: ?float}
 */
function fanboyCrashRead(): array
{
    $j = readJson(fanboyCrashFile());
    $ok = is_array($j) && ($j['v'] ?? null) === 1 && ($j['boot'] ?? null) === fanboyBootId();
    $num = fn ($t) => is_float($t) || is_int($t);
    return ['deaths' => $ok && is_array($j['deaths'] ?? null) ? array_map('floatval', array_values(array_filter($j['deaths'], $num))) : [],
            'seen' => $ok && is_int($j['seen'] ?? null) ? $j['seen'] : null,
            'launched' => $ok && $num($j['launched'] ?? null) ? (float) $j['launched'] : null];
}

function fanboyCrashKeep(array $change): array
{
    $m = array_merge(fanboyCrashRead(), array_intersect_key($change, ['deaths' => 1, 'seen' => 1, 'launched' => 1]));
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/crashes.json', jsonEncode(['v' => 1, 'boot' => fanboyBootId()] + $m), 0600, 0, 0);       // fanboyCrashFile(), spelled out: RAM
    return $m;
}

/**
 * Should the control run and doesn't? Then it starts — after a death only after a pause, and not after too many. The
 * agent's tick while the array runs, the night shift's while it doesn't (fanboy-night.php: then everything from his mirror
 * in RAM, never the data folder); what they know of its deaths lies in RAM for both (fanboyCrashRead()).
 */
function fanboyEnsureControl(float $now): void
{
    $m = fanboyCrashRead();
    // steering again for half an hour without a death: a next death is told again
    if ($m['deaths'] && $now - end($m['deaths']) > FANBOY_CRASH_WINDOW) {
        $m = fanboyCrashKeep(['deaths' => []]);
        fanboyUntellOnce('crashed');
    }
    $ram = fanboyRamMode();
    $settings = $ram ? fanboyBoardRaw() : fanboySettingsCached();
    // (the array's state is no condition any more, 2026-11-22: the control steers whatever the array does)
    if ($settings === null || ($settings['armed'] ?? false) !== true || !fanboyControlNew($settings, '', ['pid' => 0])['zones']
        || !($ram ? fanboyHiredNow() : in_array('fanboy', staffHired($GLOBALS['agentStaffFile'] ?? null), true))
        || fanboyStepRunning() || fanboyControlPid() !== null
        || is_file(fanboyBrakeFile())           // it stopped itself (fanboyBrake()): not again by itself
        || fanboyHandBackAsked()) {             // the user gave the fans back while no agent could hear it (fanboy-night.php)
        return;
    }
    $status = readJson(fanboyStatusFile());
    $crashes = $m['deaths'];
    // it died without ending (killed hard, a PHP error) — or the guard's cron look found it so first (why «crashed»): its
    // outputs were given back already; counted once per process, said once per run of deaths
    if (is_array($status) && (($status['phase'] ?? '') !== 'ended' || ($status['why'] ?? '') === 'crashed') && is_int($status['pid'] ?? null)
        && $m['seen'] !== $status['pid']) {
        $crashes[] = $now;
        $m = fanboyCrashKeep(['deaths' => $crashes, 'seen' => $status['pid']]);
        logLine("Fanboy: the control (PID {$status['pid']}) ended without giving back — the BIOS steers; started again in " . (int) FANBOY_CRASH_PAUSE . ' s');
        $status['who'] = 'bios';
        $status['why'] = 'crashed';
        $status['phase'] = 'ended';
        fanboyStatusWrite($status);
        fanboyTellOnce('crashed', 'crashed', ['pause' => (int) FANBOY_CRASH_PAUSE, 'max' => FANBOY_CRASH_MAX, 'window' => (int) round(FANBOY_CRASH_WINDOW / 60)], 'warning');
    }
    $crashes = array_values(array_filter($crashes, fn ($t) => $now - $t < FANBOY_CRASH_WINDOW));
    if (count($crashes) >= FANBOY_CRASH_MAX || ($crashes && $now - end($crashes) < FANBOY_CRASH_PAUSE)
        || $now - ($m['launched'] ?? -INF) < FANBOY_CRASH_PAUSE) {
        return;
    }
    fanboyCrashKeep(['deaths' => $crashes, 'launched' => $now]);
    // the control reads only his mirror in RAM (2026-11-22): made sure of right before a start (never more often than a
    // start is tried) — none for this board (his settings too large to mirror): no control, and the page says why
    // instead of «about to take over». Only the agent writes the mirror: the night shift takes what is there.
    if (!$ram) {
        fanboyMirrorKeep(true);
    }
    if (fanboyMirrorRead(fanboyBoardKey(fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']))) === null) {
        if ((readJson(fanboyStatusFile())['why'] ?? null) !== 'no_mirror') {
            fanboyStatusWrite(['v' => 1, 'pid' => null, 'who' => 'bios', 'why' => 'no_mirror', 'phase' => 'ended', 'time' => time()]);
            logLine('Fanboy: no mirror of his settings in RAM for this board — the control is not started');
        }
        return;
    }
    fanboyLaunch('fanboy-control');
}

/**
 * The seconds (and percents) the page's sentences state, each from its constant — one place (the setup's state `times`):
 * clear_after (an alarm's zone stays at full after its reason), up_hold / up_hold_quiet / up_min (a test's full phase:
 * its ceilings without an answer, its least), show_for, lease (the
 * page's sign of life), tell_after (the chip missing → told), idle_warm_after (a watched fan standing while warm → told),
 * stand_after (a fan of his at 0 rpm → stands), history_every, history_periods (h), poll_live (a disk value counts as it
 * is up to this poll), crash_pause / crash_window / crash_max, net (the net for the sources in no zone)
 */
function fanboyTimes(): array
{
    return [
        'clear_after' => (int) FANBOY_CLEAR_AFTER, 'up_hold' => (int) FANBOY_UP_HOLD, 'up_hold_quiet' => (int) FANBOY_UP_HOLD_QUIET, 'measure_max' => fanboyMeasureMaxSecs(), 'measure_usual' => fanboyMeasureUsualSecs(), 'up_min' => (int) FANBOY_UP_MIN, 'show_for' => (int) FANBOY_SHOW_FOR,
        'lease' => (int) FANBOY_STEP_LEASE, 'tell_after' => (int) FANBOY_TELL_AFTER, 'idle_warm_after' => (int) FANBOY_IDLE_WARM_AFTER,
        'stand_after' => (int) FANBOY_STAND_AFTER, 'history_every' => (int) FANBOY_HISTORY_EVERY, 'history_periods' => FANBOY_HISTORY_PERIODS,
        'poll_live' => FANBOY_POLL_LIVE, 'crash_pause' => (int) FANBOY_CRASH_PAUSE, 'crash_window' => (int) FANBOY_CRASH_WINDOW, 'crash_max' => FANBOY_CRASH_MAX,
        'net' => ['warm_pct' => FANBOY_NET_WARM_PCT, 'hot_pct' => FANBOY_NET_HOT_PCT, 'gap' => FANBOY_NET_GAP, 'clear' => (int) FANBOY_NET_CLEAR],
    ];
}

/**
 * The control's deaths within FANBOY_CRASH_WINDOW as the agent and the night shift count them (fanboyEnsureControl()): {deaths, max, window,
 * given_up} — given_up: FANBOY_CRASH_MAX reached, the agent doesn't start it again by itself (the BIOS keeps the fans)
 */
function fanboyRestarts(?float $now = null): array
{
    $now ??= fanboyMono();
    $deaths = count(array_filter(fanboyCrashRead()['deaths'], fn ($t) => $now - $t < FANBOY_CRASH_WINDOW));
    return ['deaths' => $deaths, 'max' => FANBOY_CRASH_MAX, 'window' => (int) FANBOY_CRASH_WINDOW, 'given_up' => $deaths >= FANBOY_CRASH_MAX];
}

/** One notification from the agent's side, once until fanboyUntellOnce() — the marks in RAM (a restart in place keeps them) */
function fanboyTellOnce(string $once, string $key, array $params, string $level): void
{
    $told = readJson(fanboyRunDir() . '/told.json') ?? [];
    if (isset($told[$once])) {
        return;
    }
    $told[$once] = time();
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/told.json', jsonEncode($told), 0600, 0, 0);
    $lang = officeNotifyLang();
    officeNotify(officeNotifyText('fanboy', "notify.{$key}_subject", $params, $lang), officeNotifyText('fanboy', "notify.$key", $params, $lang),
        $level, '', officeNotifyLink('#/fanboy'), 'fanboy', $key);
}

function fanboyUntellOnce(string $once): void
{
    $told = readJson(fanboyRunDir() . '/told.json') ?? [];
    if (isset($told[$once])) {
        unset($told[$once]);
        writeAtomic(fanboyRunDir() . '/told.json', jsonEncode($told), 0600, 0, 0);
    }
}

/**
 * A job of his in a process of its own: its own session (setsid -f — an agent restarting itself in place, or stopping,
 * never takes it along; agent.sh's stop asks it to end through the guard), nothing open to the agent
 */
function fanboyLaunch(string $job): void
{
    if (isset($GLOBALS['fanboyLauncher'])) {
        ($GLOBALS['fanboyLauncher'])($job);         // the tests' own
        return;
    }
    $p = @proc_open(fanboyLaunchCommand($job),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (is_resource($p)) {
        proc_close($p);
    }
}

/**
 * The command a job of his starts with. A job that runs without the data folder (his desk's 'ram_jobs': the control,
 * 2026-11-22) starts as a RAM job — and with nothing open that the agent has open at this moment: PHP's files are not
 * close-on-exec, so a file of the data folder open right now would be the control's for its whole life and keep the pool
 * busy at an array stop (the shell closes everything from 3 on, as at the agent's own restart: closeInheritedFds()).
 *
 * @return list<string>
 */
function fanboyLaunchCommand(string $job): array
{
    if (isset((desks()['fanboy'] ?? [])['ram_jobs'][$job])) {
        return ['setsid', '-f', '/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'ramjob', $job];
    }
    return ['setsid', '-f', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', $job];
}

/** The control's pid when it runs (its pid file: pid and the process's start time — never another process later), else null */
function fanboyControlPid(): ?int
{
    $raw = trim((string) @file_get_contents(fanboyRunDir() . '/control.pid', false, null, 0, 64));
    if (!preg_match('/^(\d{1,9}) (\d{1,20})$/D', $raw, $m)) {
        return null;
    }
    $pid = (int) $m[1];
    return $pid > 1 && fanboyProcStart($pid) === $m[2] ? $pid : null;
}

/** The control asked to end (TERM; it gives back itself), at most 5 s — then whatever it still holds given back here */
function fanboyControlStop(string $why): array
{
    $raw = trim((string) @file_get_contents(fanboyRunDir() . '/control.pid', false, null, 0, 64));
    $pid = fanboyControlPid();
    $t0 = time();
    if ($pid !== null) {
        @posix_kill($pid, SIGTERM);
        $until = fanboyMono() + 5;
        while (fanboyControlPid() !== null && fanboyMono() < $until) {
            usleep(100000);
        }
    }
    $given = [];
    if (preg_match('/^(\d{1,9}) (\d{1,20})$/D', $raw, $m)) {
        $given = fanboyGiveBack(null, $why, ['pid' => (int) $m[1], 'start' => $m[2]]);
    }
    foreach (fanboyJournal()['outputs'] as $id => $o) {
        if (is_array($o['given']) && ($o['given']['at'] ?? 0) >= $t0 && !isset($given[$id])) {
            $given[$id] = ['ok' => (bool) ($o['given']['ok'] ?? false)];
        }
    }
    return $given;
}

/** «Look now»: the control looks for the chip at once (when it waits for it), and a new look for the page */
function fanboyLookNow(): array
{
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/look-now', (string) time(), 0600, 0, 0);
    @unlink(fanboyBrakeFile());                 // a control that stopped itself may try again on the user's click
    fanboyCrashKeep(['launched' => null]);      // and the control started at the next tick when it should run
    return ['ok' => true, 'state' => fanboyScan()];
}

/** The control's status for the page: as it wrote it, «running» from its pid; one that died without ending: crashed */
function fanboyControlState(?array $settings): array
{
    $s = readJson(fanboyStatusFile());
    $running = fanboyControlPid() !== null;
    $out = ['running' => $running, 'armed' => ($settings['armed'] ?? false) === true, 'who' => 'bios', 'why' => null, 'zones' => [], 'alarm' => null,
            'missing' => null, 'notes' => [], 'watch' => [], 'time' => null];
    if (is_array($s)) {
        $out = array_merge($out, array_intersect_key($s, array_flip(['who', 'why', 'zones', 'alarm', 'missing', 'notes', 'watch', 'time', 'phase'])));
        if (!$running) {
            $out['who'] = 'bios';
            if (($s['phase'] ?? '') !== 'ended') {
                $out['why'] = 'crashed';
            }
            $out['alarm'] = null;
        }
    }
    $out['guard'] = readJson(fanboyRunDir() . '/guard.json');
    return $out;
}

/**
 * Round 7 (F3, the owner's server after another controller was uninstalled): the outputs that stand on a fixed value
 * someone left (pwmN_enable 1, or 0 «full on») — nobody steers them, not the BIOS. Offered outputs only, those the test
 * found empty, ignored or inverted left out, one without a mode file unknown. [fixed: [{id, output, pct}], auto: n]
 */
function fanboyFixedOutputs(array $scan, ?array $settings): array
{
    $fixed = [];
    $auto = 0;
    foreach ($scan['chips'] ?? [] as $c) {
        if (!fanboyOffers($c)) {
            continue;
        }
        foreach ($c['outputs'] as $o) {
            $id = fanboyFanId($c['name'], $c['address'], $o['n']);
            if (in_array(fanboyFound($settings['fans'][$id] ?? null), ['empty', 'ignored', 'inverted'], true) || !is_int($o['enable'])) {
                continue;
            }
            if ($o['enable'] >= 2) {
                $auto++;
            } else {
                $fixed[] = ['id' => $id, 'output' => $o['n'], 'pct' => $o['enable'] === 0 ? 100 : (int) $o['pct']];
            }
        }
    }
    return ['fixed' => $fixed, 'auto' => $auto];
}

/**
 * Who steers now and why — Fanboy (his zones), or the BIOS / another plugin / the board with the reason. Round 7 (F3):
 * the BIOS only where it does — every output with a fan on a fixed value someone left: «nobody» (why «fixed»), some of
 * them: the BIOS with those listed (`fixed`). Never while a setup step or a show moves an output on purpose.
 */
function fanboyWhoNow(array $scan, array $control, ?array $settings): array
{
    $who = fanboyWhoBase($scan, $control, $settings);
    $safety = fanboySafetyFull();       // round 9 (H2): set to full by him for safety — never «left by someone»
    if (($who['who'] ?? '') !== 'bios' || in_array($who['why'] ?? '', ['array_stopped', 'chip_missing', 'chip_gone', 'no_output', 'starting'], true)
        || fanboyStepRunning() || fanboyShowRunning()) {
        return $who;
    }
    $fx = fanboyFixedOutputs($scan, $settings);
    $fx['fixed'] = array_values(array_filter($fx['fixed'], fn ($x) => !isset($safety[$x['id']])));
    if ($fx['fixed'] && !$fx['auto']) {
        return ['who' => 'nobody', 'why' => 'fixed', 'fixed' => $fx['fixed'], 'was' => $who['why'] ?? null];
    }
    return $fx['fixed'] ? $who + ['fixed' => $fx['fixed']] : $who;
}

/** Who steers by the control and his look (before round 7's look at outputs left on a fixed value) */
function fanboyWhoBase(array $scan, array $control, ?array $settings): array
{
    if ($control['running'] && $control['who'] === 'fanboy') {
        return ['who' => 'fanboy', 'why' => $control['alarm'] !== null ? 'alarm' : 'steers', 'zones' => count($control['zones'])];
    }
    if ($control['running'] && $control['who'] === 'nobody') {
        return ['who' => 'nobody', 'why' => 'chip_gone'];      // round 5: the chip went while he steered — the fans stay as they were
    }
    $who = $scan['who'];
    if ($who['who'] !== 'bios') {
        return $who;                   // another plugin, the board's BMC: as part 1 says
    }
    if ($settings !== null && ($settings['armed'] ?? false) === true) {
        return ['who' => 'bios', 'why' => is_string($control['why']) && $control['why'] !== '' ? $control['why'] : 'starting'];
    }
    if ($settings !== null && fanboyControlNew($settings, '', ['pid' => 0])['zones']) {
        return ['who' => 'bios', 'why' => 'not_armed'];
    }
    return $who;
}

/**
 * His setup on this board for the page (and the wizard): each fan he knows (found, role, measured …), the zones, armed,
 * the outputs there now, stored fans not found now (nothing is ever discarded), how many other boards are kept
 */
function fanboySetupState(array $scan, ?array $settings): array
{
    $fans = [];
    $missing = [];
    $ctl = fanboyControlNew($settings ?? [], '', ['pid' => 0]);         // what the control would make of these settings
    $before = fanboyBefore($scan);
    $journal = fanboyJournal()['outputs'];
    foreach (is_array($settings['fans'] ?? null) ? $settings['fans'] : [] as $id => $f) {
        $stored = fanboyStoredFan($f);
        if (!is_string($id) || $stored === null) {
            continue;
        }
        $m = is_array($f['measured'] ?? null) ? $f['measured'] : null;
        $model = is_array($f['model'] ?? null) ? $f['model'] : null;
        $fans[] = ['id' => $id, 'chip' => $stored['chip'], 'output' => $stored['output'], 'tachs' => array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')),
            // part 3: its look for the pictures, «give back to» (null: not chosen — the default counts)
            'model' => $model === null ? null : ['id' => is_string($model['id'] ?? null) ? $model['id'] : null, 'mm' => is_int($model['mm'] ?? null) ? $model['mm'] : null,
                'blades' => is_int($model['blades'] ?? null) ? $model['blades'] : null],
            'colour' => is_string($f['colour'] ?? null) && in_array($f['colour'], FANBOY_COLOURS, true) ? $f['colour'] : null,
            // round 12: the user's own look — frame and wheel colour, blade style (the page's keys; an old `colour` stays as it is)
            'frame' => is_string($f['frame'] ?? null) && preg_match(FANBOY_LOOK_KEY_RE, $f['frame']) ? $f['frame'] : null,
            'wheel' => is_string($f['wheel'] ?? null) && preg_match(FANBOY_LOOK_KEY_RE, $f['wheel']) ? $f['wheel'] : null,
            'style' => is_string($f['style'] ?? null) && preg_match(FANBOY_LOOK_KEY_RE, $f['style']) ? $f['style'] : null,
            // 2026-11-20: a reverse-blade fan (the user's own tick; null: the model's says)
            'reverse' => is_bool($f['reverse'] ?? null) ? $f['reverse'] : null,
            'back' => in_array($f['back'] ?? null, FANBOY_BACKS, true) ? $f['back'] : null,
            // round 3: fans on this one connector (the user says; one speed for all of them) — none stored: one
            'count' => is_int($f['count'] ?? null) && $f['count'] >= 1 && $f['count'] <= FANBOY_COUNT_MAX ? $f['count'] : 1,
            'found' => fanboyFound($f), 'role' => is_string($f['role'] ?? null) ? $f['role'] : null,
            'name' => is_string($f['name'] ?? null) ? $f['name'] : '', 'place' => is_string($f['place'] ?? null) ? $f['place'] : null,
            'off_ok' => ($f['off_ok'] ?? false) === true, 'measured' => $m !== null,
            // round 4: a CPU cooler given to him on the owner's risk — since when (null: the BIOS's)
            'cpu_own' => fanboyCpuOwn($f) ? $f['cpu_own']['at'] : null, 'min_pct' => $m['min_pct'] ?? null, 'start_pct' => $m['start_pct'] ?? null,
            'max_rpm' => $m['max_rpm'] ?? null, 'table' => $m['table'] ?? null, 'no_turn' => ($f['no_turn'] ?? false) === true,
            // round 9 (H1): it didn't start from standing at this value (0–255) — it needed a kick
            'kick_start' => is_int($f['kick_start']['value'] ?? null) ? $f['kick_start']['value'] : null,
            // round 12: the measurement's standstill part (null: not tried — measured before round 12, or a CPU cooler/pump)
            'stand' => $m !== null && array_key_exists('stops', $m) ? array_intersect_key($m + ['floor_rpm' => null, 'start_from_stop' => null, 'kick_start' => null, 'stops_safe' => null, 'start_tries' => null, 'start_secs' => null],
                array_flip(['stops', 'floor_rpm', 'start_from_stop', 'kick_start', 'stops_safe', 'start_tries', 'start_secs'])) : null,
            // round 12 fix: the standstill part was cut — {why: warm|stopped|page_gone|…, source, c, at} («Stillstand nicht geprüft: CPU 53 °C»)
            'stand_cut' => $m !== null && !array_key_exists('stops', $m) && is_array($m['stand_cut'] ?? null) ? $m['stand_cut'] : null,
            // … and «darf stehen» chosen but not in effect: why (measure_again, never_stops, not_safe, not_measured)
            // the floor, the start from standing and the curve as the control applies them (null outside a zone, the curve
            // also in rpm zones), and what it falls back to (fanboySetupFanPlan())
            ...fanboySetupFanPlan($id, $f, $ctl, $before[$id] ?? null, $journal[$id] ?? null),
            'off_why' => ($stand = fanboyFanStandState(['id' => $id] + $f, $settings))[1],
            // … in effect (it may stand), and a CPU cooler's second tick — since when (null: none)
            'may_stand' => $stand[0], 'cpu_off' => fanboyCpuOwn($f) && is_int($f['cpu_off']['at'] ?? null) ? $f['cpu_off']['at'] : null,
            // round 14: several fans on it — the same model or not (null: not said), and what that means for the control
            'alike' => is_bool($f['alike'] ?? null) ? $f['alike'] : null,
            'hub' => is_int($f['count'] ?? null) && $f['count'] > 1 ? ['mixed' => ($f['alike'] ?? null) !== true, 'one_fan_table' => true,
                'ok' => is_int($f['hub_ok']['at'] ?? null) ? $f['hub_ok']['at'] : null, 'not_safe' => is_int($f['hub_not_safe']['at'] ?? null) ? $f['hub_not_safe']['at'] : null,
                'extra' => is_int($f['hub_extra'] ?? null) ? $f['hub_extra'] : 0, 'floor' => ($plan = fanboyFanPlan($f))['floor'], 'start' => $plan['start']] : null];
        $r = fanboyResolve($stored, $scan);
        if ($r['path'] === null) {
            $missing[] = ['id' => $id, 'how' => $r['how']];
        }
    }
    $store = fanboyStoreRead();
    $key = fanboyBoardKey($scan['board']);
    $zones = is_array($settings['zones'] ?? null) ? array_values(array_filter($settings['zones'], 'is_array')) : [];
    // 2026-11-22: a USB stick a zone still names is no heat source — shown without it (and saved so, quietly)
    $sticks = fanboyUsbStickIds(fanboyPaths()['disks']);
    $zones = array_map(fn ($z) => fanboyZoneSourcesClean($z, $sticks), $zones);
    // each zone's curve as the control reads it, its scale and the three presets on that scale (the page draws them)
    $curves = [];
    foreach ($zones as $z) {
        if (is_string($z['id'] ?? null)) {
            [$lo, $hi] = fanboyZoneScale($z);
            // round 13: every kind of its sources with its own curve (or the derived one), on its own scale, and the presets there
            $kinds = [];
            foreach (fanboyZoneKindCurves($z) as $k => $kc) {
                $kv = fanboyKindValues($z, $k);
                $kl = (float) $kv['low'];
                $kh = (float) $kv['high'] > $kl ? (float) $kv['high'] : $kl + 10;
                $kinds[$k] = $kc + ['scale' => [$kl, $kh], 'presets' => array_combine(FANBOY_PRESETS, array_map(fn ($p) => fanboyKindPreset($p, $k), FANBOY_PRESETS))];
            }
            $curves[$z['id']] = ['points' => fanboyZoneCurve($z), 'scale' => [$lo, $hi],
                'presets' => array_combine(FANBOY_PRESETS, array_map(fn ($p) => fanboyKindPreset($p, $z['sources'][0]['kind'] ?? 'disk'), FANBOY_PRESETS)), 'kinds' => $kinds ?: new stdClass()];
        }
    }
    return [
        // round 12 correction: has a give-back to the chip's automatic been SEEN to work on this board? {at, output}|null
        'auto_back_seen' => is_array($settings['auto_back_seen'] ?? null) && is_int($settings['auto_back_seen']['at'] ?? null) ? $settings['auto_back_seen'] : null,
        // round 12 fix: the heat sources in no zone — [{kind, id, name, c, asleep}] (the zones step and «take over» warn)
        'uncovered' => fanboyUncovered($scan, $settings),
        // 2026-11-27: the heat sources he set aside («Nicht beachten») — ids as the zones keep them (fanboyAsideIds())
        'aside' => fanboyAsideIds($settings),
        'board' => $key, 'others' => count(array_diff(array_keys($store['boards']), [$key])),
        'fans' => $fans, 'zones' => $zones, 'armed' => ($settings['armed'] ?? false) === true,
        'outputs' => array_keys(fanboyOutputs($scan)), 'missing' => $missing, 'sources' => fanboySources($scan),
        // part 3: the curves, each output's state before Fanboy, «give back» still open, the defaults the page shows
        'curves' => $curves ?: new stdClass(), 'before' => $before ?: new stdClass(),
        'back_default' => FANBOY_BACK_DEFAULT,
        'scales' => FANBOY_SOURCE_DEFAULTS, 'down' => FANBOY_DOWN_PROFILES, 'round' => FANBOY_ROUND, 'presets_of' => array_combine(array_keys(FANBOY_SOURCE_DEFAULTS),
            array_map(fn ($k) => array_combine(FANBOY_PRESETS, array_map(fn ($p) => fanboyKindPreset($p, $k), FANBOY_PRESETS)), array_keys(FANBOY_SOURCE_DEFAULTS))),
        // 2026-11-16 (the page's sentence audit): every number a sentence of the page states, from the code (testFanboyTimes)
        'times' => fanboyTimes(), 'limits' => ['name_max' => FANBOY_NAME_MAX, 'count_max' => FANBOY_COUNT_MAX, 'zones_max' => FANBOY_ZONES_MAX, 'sources_max' => FANBOY_SOURCES_MAX],
        // the control's deaths in a row (the agent's memory): `given_up` — not started again by itself any more
        'restarts' => fanboyRestarts(),
    ];
}

/**
 * For the Team Lead, only once he was given fans and took them over: does he steer them (not: driver missing, another
 * plugin, given back), and no alarm (a fan standing, too warm, a sensor silent). From the status in RAM — cheap.
 */
function fanboyChecks(?array $settings, ?array $status): array
{
    if ($settings === null || ($settings['armed'] ?? false) !== true) {
        return [];
    }
    $running = fanboyControlPid() !== null;
    $steers = $running && ($status['who'] ?? '') === 'fanboy';
    $starting = $running && in_array($status['why'] ?? '', ['starting'], true);
    $alarm = $running && is_array($status['alarm'] ?? null);
    return [
        finding('fanboy_steers', 'recommended', $steers ? true : ($starting ? null : false), [], '#/fanboy'),
        finding('fanboy_alarm', 'recommended', $running ? !$alarm : null, ['min' => (int) round(FANBOY_CLEAR_AFTER / 60)], '#/fanboy'),
    ];
}

/** His numbers for Prometheus, from the status in RAM: steering, alarm, each zone's level and warmest, each fan's rpm */
function fanboyMetrics(?array $s, bool $running): array
{
    if (!is_array($s)) {
        return [];
    }
    $steering = $running && ($s['who'] ?? '') === 'fanboy';
    $zones = array_values(array_filter(is_array($s['zones'] ?? null) ? $s['zones'] : [], fn ($z) => is_array($z) && is_string($z['id'] ?? null)));
    $level = $temp = $rpm = $curve = [];
    foreach ($zones as $z) {
        // the zone's and the fan's names as the page shows them, for a dashboard's legends (an id alone says little)
        $zone = ['zone' => $z['id'], 'name' => is_string($z['name'] ?? null) && $z['name'] !== '' ? $z['name'] : $z['id']];
        if ($steering && is_numeric($z['level'] ?? null) && ($z['unit'] ?? 'pct') === 'pct') {
            $level[] = [$zone, (float) $z['level']];
        }
        if (is_numeric($z['warmest']['c'] ?? null)) {
            $temp[] = [$zone, (float) $z['warmest']['c']];
        }
        // a zone of two or more curves (one per kind of its sources): each curve's warmest source now, as the history
        // records it (by_kind; a kind whose sources all sleep has none). One curve: the zone's line above is that curve.
        // The leader needs no series of its own: the zone's warmest place is the leading curve's source.
        if (is_array($z['curves'] ?? null) && count($z['curves']) >= 2) {
            foreach (is_array($z['by_kind'] ?? null) ? $z['by_kind'] : [] as $kind => $b) {
                if (is_string($kind) && isset(FANBOY_KIND_BOUNDS[$kind]) && is_numeric($b['c'] ?? null)) {
                    $curve[] = [$zone + ['kind' => $kind], (float) $b['c']];
                }
            }
        }
        foreach (is_array($z['fans'] ?? null) ? $z['fans'] : [] as $f) {
            if (is_int($f['rpm'] ?? null) && is_int($f['output'] ?? null)) {
                $name = is_string($f['name'] ?? null) && $f['name'] !== '' ? $f['name'] : 'pwm' . $f['output'];
                $rpm[] = [['zone' => $z['id'], 'output' => 'pwm' . $f['output'], 'name' => $name], $f['rpm']];
            }
        }
    }
    return array_values(array_filter([
        metricsGauge('uso_fanboy_steering', 'Fanboy steers the fans he was given (1), or the BIOS does (0)', $steering ? 1 : 0),
        metricsGauge('uso_fanboy_alarm', 'An alarm at Fanboy\'s fans: a fan stands, too warm at full speed, a sensor silent, the chip gone (1)', $running && is_array($s['alarm'] ?? null) ? 1 : 0),
        $level ? metricsGauge('uso_fanboy_zone_level_percent', 'Each zone\'s level, percent (zones steered by percent), by its id and name', $level) : null,
        $temp ? metricsGauge('uso_fanboy_zone_temperature_celsius', 'Each zone\'s warmest heat source (the place that leads), °C, by the zone\'s id and name', $temp) : null,
        $curve ? metricsGauge('uso_fanboy_curve_temperature_celsius', 'Zones of two or more curves: each curve\'s warmest source (by kind: disk, nvme, cpu …), °C — the one asking the most leads', $curve) : null,
        $rpm ? metricsGauge('uso_fanboy_fan_rpm', 'Each fan Fanboy steers, rpm, by its zone, output and name', $rpm) : null,
    ]));
}
