<?php
declare(strict_types=1);

/*
 * Round 6, part 1 (the owner, 2026-11-15): Fanboy watches every fan he does NOT steer — fans left with the BIOS, fans
 * before any setup, fans another controller steers. Notify only: nothing here writes a fan output, and no zone goes to
 * full because of such a fan. Every tach input he sees (a graphics card's left out: its own fan stops at idle by design,
 * the card's business) that was seen turning and now reads 0 rpm:
 *   · everything cool (no temperature at or above its warning value — fanboyWarmNow()): he learns silently «this fan
 *     stops when it is cool» (the BIOS's «fan stop», another controller's 0 %) — kept per tach in his data, shown on the
 *     page, a line in the log, never a notification;
 *   · something warm: standing for FANBOY_IDLE_WARM_AFTER (2 minutes) → ONE notification naming the fan as the page
 *     does; over when it turns again (told again only after it turned FANBOY_CLEAR_AFTER — the existing alarms' rule);
 *   · a CPU cooler or pump (its role in the setup): round 4's stricter rule — standing FANBOY_STAND_AFTER told whatever
 *     the temperatures, never learned as «stops when cool»;
 *   · a sentinel or insane rpm (round 5, fanboySaneRpm()) is no value: never «stands».
 * Fans the control steers, and the BIOS fans it watches itself, are left to the control while it runs. Paused while a
 * setup step or «Show me this fan» runs (they move fans on purpose). The agent's tick calls it (every 5 s, a few file
 * reads); a restart of the agent keeps its clocks and what it told (RAM, the monotonic clock of this boot).
 *
 *   <data folder>/fanboy/watch.json   {"v": 1, "on": true|false (absent: on), "boards": {"<board key>": {"tachs":
 *                                      {"<chip>@<address>#<n>": {"seen": <time first seen turning>, "quiet_stop": <time>|null}}}}}
 *   RUN_DIR/fanboy/watch-run.json     {"v": 1, "boot": "<boot id>", "tachs": {"<tach id>": {zero, warm, turn (monotonic s
 *                                      or null), told (time or null), state, rpm, since}}, "warm": {…}|null}
 * Not in settings.json on purpose (the control takes every fan anew when that file changes).
 */

const FANBOY_IDLE_WARM_AFTER = 120.0;           // standing while something is warm this long: told
const FANBOY_IDLE_QUIET_AFTER = 15.0;           // standing while everything is cool this long: learned «stops when cool»
const FANBOY_IDLE_KINDS = ['disk', 'ssd', 'nvme', 'cpu', 'board', 'ram', 'gpu'];    // what may count as «warm» («other»: unknown meaning)

function fanboyWatchFile(): string
{
    return FANBOY_DIR . '/watch.json';
}

function fanboyWatchRunFile(): string
{
    return fanboyRunDir() . '/watch-run.json';
}

/** The store as kept (no file, a broken one: on, nothing learned) */
function fanboyWatchRead(): array
{
    $j = readJson(fanboyWatchFile());
    return is_array($j) ? $j : [];
}

/** «Watch fans I don't steer»: on unless the owner switched it off */
function fanboyWatchOn(?array $j = null): bool
{
    return (($j ?? fanboyWatchRead())['on'] ?? true) !== false;
}

/** The action fanboy.watch {on}: read strictly, kept (unknown keys stay), the RAM state cleared when off */
function fanboyWatchSet(array $r): array
{
    $on = boolField($r, 'on');
    $j = fanboyWatchRead();
    $j['v'] = is_int($j['v'] ?? null) ? $j['v'] : 1;
    $j['on'] = $on;
    $j['at'] = time();
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic(fanboyWatchFile(), jsonEncode($j), 0600);
    fanboyMirrorKeep(true);         // his mirror in RAM carries the switch (fanboy-mirror.php)
    if (!$on) {
        @unlink(fanboyWatchRunFile());
    }
    logLine('Fanboy: watching the fans he doesn\'t steer switched ' . ($on ? 'on' : 'off') . ' on the user\'s click');
    return ['ok' => true, 'on' => $on, 'state' => fanboyScan()];
}

/** A tach input's id: «<chip name>@<address>#<n>» (no hwmonN) */
function fanboyTachId(string $chip, string $address, int $n): string
{
    return "$chip@$address#$n";
}

/** The chip a stored fan is on now: same address, else the only chip of that name (as fanboyResolve()) */
function fanboyIdleChipOf(string $name, string $address, array $chips): ?array
{
    $same = array_values(array_filter($chips, fn ($c) => $c['name'] === $name));
    foreach ($same as $c) {
        if ($c['address'] === $address) {
            return $c;
        }
    }
    return count($same) === 1 ? $same[0] : null;
}

/**
 * The warmest place at or above its warning value now — null: everything cool. A disk (Unraid's disks.ini: a sleeping
 * one has no value, never «warm»): its own warning or Unraid's for its kind (hotTemp / hot / hotssd), else the kind's
 * «needs all the air» (FANBOY_SOURCE_DEFAULTS high); a sensor: its kind's «needs all the air». $allow: part 5's
 * allowance per disk name (an old disk value is a lower bound). {id, kind, c, warn, name}.
 */
function fanboyWarmNow(array $chips, array $disks, array $allow = [], ?string $lang = null): ?array
{
    $sources = fanboySources(['chips' => $chips, 'disks' => $disks]);
    $warnOf = [];
    foreach ($disks as $d) {
        $warnOf[$d['name']] = is_int($d['warn'] ?? null) ? $d['warn'] : null;
    }
    $best = null;
    foreach ($sources as $s) {
        // (2026-11-27: a drive's further sensor never — the drive isn't judged by it, fanboyDriveMain())
        if ($s['c'] === null || $s['asleep'] || !in_array($s['kind'], FANBOY_IDLE_KINDS, true) || !empty($s['further'])) {
            continue;
        }
        $disk = in_array($s['kind'], FANBOY_DISK_KINDS, true);
        $c = (float) $s['c'] + ($disk ? (float) ($allow[$s['id']] ?? 0) : 0.0);
        $warn = ($disk ? $warnOf[$s['id']] ?? null : null) ?? FANBOY_SOURCE_DEFAULTS[$s['kind']]['high'];
        if ($c >= $warn && ($best === null || $c - $warn > $best['c'] - $best['warn'])) {
            $best = ['id' => $s['id'], 'kind' => $s['kind'], 'c' => $c, 'warn' => (float) $warn];
        }
    }
    if ($best !== null) {
        $best['name'] = fanboySourceNames($sources, $lang ?? (function_exists('officeNotifyLang') ? officeNotifyLang() : 'en'))[$best['id']] ?? $best['id'];
    }
    return $best;
}

/**
 * Every tach input's name as the page says it: the setup's fan on it (its name, else «Output 4»), else the input's own
 * label, else «Fan input 4» — [tach id => name]
 */
function fanboyTachNames(array $chips, ?array $settings, ?string $lang = null): array
{
    $lang ??= function_exists('officeNotifyLang') ? officeNotifyLang() : 'en';
    $t = fn (string $k, array $p) => officeNotifyText('fanboy', $k, $p, $lang);
    $out = [];
    foreach ($chips as $c) {
        foreach ($c['fans'] as $f) {
            $out[fanboyTachId($c['name'], $c['address'], $f['n'])] = $f['label'] !== '' ? $f['label'] : $t('fans.row', ['n' => $f['n']]);
        }
    }
    foreach (is_array($settings['fans'] ?? null) ? $settings['fans'] : [] as $f) {
        if (!is_array($f) || fanboyStoredFan($f) === null || ($c = fanboyIdleChipOf($f['chip'], $f['address'], $chips)) === null) {
            continue;
        }
        $name = is_string($f['name'] ?? null) && $f['name'] !== '' ? $f['name'] : $t('out.name', ['n' => $f['output']]);
        foreach (is_array($f['tachs'] ?? null) ? $f['tachs'] : [] as $n) {
            if (is_int($n)) {
                $out[fanboyTachId($c['name'], $c['address'], $n)] = $name;
            }
        }
    }
    return $out;
}

/**
 * The tach inputs left to the control while it runs (the fans it holds, the BIOS fans it watches itself) and the
 * tach inputs of a CPU cooler or pump (the stricter rule) — [skip: [tach id => true], strict: [tach id => true]]
 */
function fanboyIdleRoles(array $chips, ?array $settings, ?array $status, bool $controlRuns): array
{
    $tachsOf = function (string $id) use ($settings, $chips): array {
        $f = $settings['fans'][$id] ?? null;
        if (!is_array($f) || fanboyStoredFan($f) === null || ($c = fanboyIdleChipOf($f['chip'], $f['address'], $chips)) === null) {
            return [];
        }
        return array_map(fn ($n) => fanboyTachId($c['name'], $c['address'], $n), array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')));
    };
    $skip = $strict = [];
    if ($controlRuns && is_array($status)) {
        foreach (is_array($status['zones'] ?? null) ? $status['zones'] : [] as $z) {
            foreach (is_array($z['fans'] ?? null) ? $z['fans'] : [] as $f) {
                if (is_array($f) && is_string($f['id'] ?? null) && ($f['held'] ?? false) === true) {
                    $skip += array_fill_keys($tachsOf($f['id']), true);
                }
            }
        }
        foreach (is_array($status['watch'] ?? null) ? $status['watch'] : [] as $w) {
            if (is_array($w) && is_string($w['id'] ?? null)) {
                $skip += array_fill_keys($tachsOf($w['id']), true);
            }
        }
    }
    foreach (is_array($settings['fans'] ?? null) ? $settings['fans'] : [] as $id => $f) {
        if (is_string($id) && is_array($f) && in_array($f['role'] ?? null, ['cpu', 'pump'], true) && ($f['found'] ?? null) === 'tach') {
            $strict += array_fill_keys($tachsOf($id), true);
        }
    }
    return [$skip, $strict];
}

/**
 * One look (the agent's tick, every FANBOY_TICK_EVERY s): each tach input's state — turning · stops_cool (standing,
 * everything cool: learned) · stands_warm (standing, something warm, not yet 2 minutes) · alarm (told) · never (never
 * seen turning: an empty connector) · no_value · steered (the control's) — the notifications and log lines it brings.
 * $now: monotonic seconds, $wall: unix seconds (the tests' clocks). Returns the RAM state written.
 */
function fanboyIdleWatch(?float $now = null, ?int $wall = null, ?array $allow = null): array
{
    $now ??= fanboyMono();
    $wall ??= time();
    $store = fanboyWatchRead();
    if (!fanboyWatchOn($store)) {
        @unlink(fanboyWatchRunFile());
        return [];
    }
    $p = fanboyPaths(fanboySysRoot() ?: null);
    $run = readJson(fanboyWatchRunFile());
    $boot = fanboyBootId();
    $prev = is_array($run) && ($run['boot'] ?? null) === $boot && is_array($run['tachs'] ?? null) ? $run['tachs'] : [];
    // a setup step or «Show me this fan» moves fans on purpose: no clock runs meanwhile (nothing told, nothing learned)
    $paused = fanboyStepRunning() || (function_exists('fanboyShowRunning') && fanboyShowRunning());
    $chips = array_values(array_filter(fanboyChips($p['hwmon']), fn ($c) => $c['kind'] !== 'gpu' && $c['fans']));
    $disks = fanboyDisks($p['disks'], fanboyDiskLimits($p));
    $allow ??= function_exists('fanboyDiskAllowance') ? fanboyDiskAllowance(fanboyPollAttributes($p), $disks) : [];
    $warm = fanboyWarmNow($chips, $disks, $allow);
    $board = fanboyBoard($p['dmi']);
    $key = fanboyBoardKey($board);
    $settings = fanboyBoardRaw($board);
    $controlRuns = fanboyControlPid() !== null;
    [$skip, $strict] = fanboyIdleRoles($chips, $settings, $controlRuns ? readJson(fanboyStatusFile()) : null, $controlRuns);
    $names = fanboyTachNames($chips, $settings);
    $kept = is_array($store['boards'][$key]['tachs'] ?? null) ? $store['boards'][$key]['tachs'] : [];
    $learn = [];
    $out = [];
    foreach ($chips as $c) {
        foreach ($c['fans'] as $f) {
            $id = fanboyTachId($c['name'], $c['address'], $f['n']);
            $was = is_array($prev[$id] ?? null) ? $prev[$id] : [];
            $x = ['zero' => null, 'warm' => null, 'turn' => $was['turn'] ?? null, 'told' => $was['told'] ?? null, 'rpm' => $f['rpm'], 'state' => 'turning',
                  'since' => $was['since'] ?? null, 'strict' => isset($strict[$id])];
            $seen = is_int($kept[$id]['seen'] ?? null) || isset($learn[$id]['seen']);
            if (isset($skip[$id])) {
                $x['state'] = 'steered';
                $x['told'] = null;          // the control tells for it now
            } elseif ($f['rpm'] === null) {
                $x['state'] = 'no_value';   // a sentinel or nothing: never «stands» — the clocks wait as they were
                $x['zero'] = $was['zero'] ?? null;
                $x['warm'] = $was['warm'] ?? null;
            } elseif ($f['rpm'] > 0) {
                if (!$seen) {
                    $learn[$id]['seen'] = $wall;
                }
                $x['state'] = 'turning';
                $x['since'] = null;
                if ($x['told'] !== null) {
                    $x['turn'] ??= $now;
                    if ($now - $x['turn'] >= FANBOY_CLEAR_AFTER) {
                        $x['told'] = null;          // over: a next stand is told again
                        $x['turn'] = null;
                    }
                    if (($was['state'] ?? '') === 'alarm') {
                        logLine("Fanboy: {$names[$id]} turns again ({$f['rpm']} rpm) — the watch's alarm is over");
                    }
                } else {
                    $x['turn'] = null;
                }
            } elseif (!$seen) {
                $x['state'] = 'never';      // never seen turning: an empty connector (or a fan that never ran yet)
            } elseif ($paused) {
                $x['state'] = 'paused';
            } else {
                $x['zero'] = is_float($was['zero'] ?? null) || is_int($was['zero'] ?? null) ? (float) $was['zero'] : $now;
                $x['since'] ??= $wall;
                $x['turn'] = null;
                $standing = $now - $x['zero'];
                if ($x['strict']) {
                    // round 4's rule for the CPU cooler and the pump: standing is told, whatever the temperatures
                    $x['state'] = $standing >= FANBOY_STAND_AFTER ? 'alarm' : 'stands';
                    if ($x['state'] === 'alarm' && $x['told'] === null) {
                        $x['told'] = $wall;
                        logLine("Fanboy: {$names[$id]} (CPU cooler or pump, not his) stands — told");
                        fanboyIdleTell('alarm_bios_stands', ['fan' => $names[$id]]);
                    }
                } elseif ($warm === null) {
                    $x['state'] = 'stops_cool';
                    if ($standing >= FANBOY_IDLE_QUIET_AFTER && !is_int($kept[$id]['quiet_stop'] ?? null)) {
                        $learn[$id]['quiet_stop'] = $wall;
                        logLine("Fanboy: {$names[$id]} stops while everything is cool (the BIOS's fan stop or another controller) — noted, nothing told");
                    }
                } else {
                    $x['warm'] = is_float($was['warm'] ?? null) || is_int($was['warm'] ?? null) ? (float) $was['warm'] : $now;
                    $x['state'] = $now - $x['warm'] >= FANBOY_IDLE_WARM_AFTER ? 'alarm' : 'stands_warm';
                    if ($x['state'] === 'alarm' && $x['told'] === null) {
                        $x['told'] = $wall;
                        logLine("Fanboy: {$names[$id]} stands although {$warm['name']} is at {$warm['c']} °C — told (he doesn't steer it)");
                        fanboyIdleTell('idle_stands', ['fan' => $names[$id], 'source' => $warm['name'], 'c' => fanboyIdleDeg($warm['c']), 'min' => (int) round(FANBOY_IDLE_WARM_AFTER / 60)]);
                    }
                }
                if ($x['told'] !== null && $x['state'] !== 'alarm') {
                    $x['state'] = 'alarm';          // told and still standing (cool again): the alarm holds until it turns
                }
            }
            $x['quiet_stop'] = $learn[$id]['quiet_stop'] ?? (is_int($kept[$id]['quiet_stop'] ?? null) ? $kept[$id]['quiet_stop'] : null);
            $x['name'] = $names[$id];
            $out[$id] = $x;
        }
    }
    if ($learn) {
        fanboyWatchLearn($key, $learn);
    }
    $state = ['v' => 1, 'boot' => $boot, 'time' => $wall, 'warm' => $warm, 'paused' => $paused, 'tachs' => $out ?: new stdClass()];
    $j = jsonEncode($state);
    if (@file_get_contents(fanboyWatchRunFile()) !== $j) {
        @mkdir(fanboyRunDir(), 0700, true);
        writeAtomic(fanboyWatchRunFile(), $j, 0644, 0, 0);
    }
    return $state;
}

/** °C for a text: «47», «47.5» */
function fanboyIdleDeg(float $c): string
{
    return rtrim(rtrim(number_format($c, 1, '.', ''), '0'), '.');
}

/** What was learned into the store (other boards, unknown keys kept) */
function fanboyWatchLearn(string $key, array $learn): void
{
    $j = fanboyWatchRead();
    $j['v'] = is_int($j['v'] ?? null) ? $j['v'] : 1;
    $j['boards'] = is_array($j['boards'] ?? null) ? $j['boards'] : [];
    $tachs = is_array($j['boards'][$key]['tachs'] ?? null) ? $j['boards'][$key]['tachs'] : [];
    foreach ($learn as $id => $x) {
        $tachs[$id] = (is_array($tachs[$id] ?? null) ? $tachs[$id] : []);
        if (isset($x['seen']) && !is_int($tachs[$id]['seen'] ?? null)) {
            $tachs[$id]['seen'] = $x['seen'];
        }
        if (isset($x['quiet_stop'])) {
            $tachs[$id]['seen'] ??= $x['quiet_stop'];
            $tachs[$id]['quiet_stop'] = $x['quiet_stop'];
        }
    }
    $j['boards'][$key] = (is_array($j['boards'][$key] ?? null) ? $j['boards'][$key] : []);
    $j['boards'][$key]['tachs'] = $tachs;
    if (!is_dir(FANBOY_DIR)) {
        return;                         // no data folder (never made here)
    }
    writeAtomic(fanboyWatchFile(), jsonEncode($j), 0600);
}

/** A notification of the watch, through the office's one gate (his texts, the office's language) */
function fanboyIdleTell(string $key, array $params): void
{
    $lang = officeNotifyLang();
    officeNotify(officeNotifyText('fanboy', "notify.{$key}_subject", $params, $lang), officeNotifyText('fanboy', "notify.$key", $params, $lang),
        'alert', '', officeNotifyLink('#/fanboy'), 'fanboy', $key);
}

/** For his state (every look): the setting and what the last watch saw — the RAM file as written, read only */
function fanboyIdleState(): array
{
    $on = fanboyWatchOn();
    $run = $on ? readJson(fanboyWatchRunFile()) : null;
    $tachs = [];
    foreach (is_array($run['tachs'] ?? null) ? $run['tachs'] : [] as $id => $x) {
        if (is_string($id) && is_array($x)) {
            $tachs[$id] = ['state' => is_string($x['state'] ?? null) ? $x['state'] : 'turning', 'quiet_stop' => is_int($x['quiet_stop'] ?? null) ? $x['quiet_stop'] : null,
                'since' => is_int($x['since'] ?? null) ? $x['since'] : null, 'name' => is_string($x['name'] ?? null) ? $x['name'] : '', 'strict' => ($x['strict'] ?? false) === true];
        }
    }
    return ['on' => $on, 'warm' => is_array($run['warm'] ?? null) ? $run['warm'] : null, 'tachs' => $tachs ?: new stdClass(), 'time' => is_int($run['time'] ?? null) ? $run['time'] : null];
}

/** The agent's tick: the watch while he is hired and the array started (his tick runs only then: the data folder is there) */
function fanboyIdleTick(?float $now = null): void
{
    if (!in_array('fanboy', staffHired($GLOBALS['agentStaffFile'] ?? null), true) || !arrayRunning(fanboyVarIni())) {
        return;
    }
    fanboyIdleWatch($now);
}

const FANBOY_DEV_STAT_KEEP = 300;       // round 13b: seconds a disk's I/O counters are compared over (RAM: devstat.json)

/**
 * Round 13b: is a disk in use? Its kernel I/O counters (/sys/block/<dev>/stat — the kernel's bookkeeping, no disk asked):
 * requests in flight now, or reads/writes completed changed since the last look (kept in RAM, devstat.json, at most
 * FANBOY_DEV_STAT_KEEP s old). The first look knows only «in flight».
 */
function fanboyDevInUse(string $stat, string $dev): bool
{
    $raw = is_file($stat) && !is_link($stat) ? (string) @file_get_contents($stat, false, null, 0, 512) : '';
    $f = preg_split('/\s+/', trim($raw)) ?: [];
    if (count($f) < 9 || !ctype_digit($f[0]) || !ctype_digit($f[4]) || !ctype_digit($f[8])) {
        return false;
    }
    $io = $f[0] . ':' . $f[4];
    $memo = function_exists('fanboyRunDir') ? fanboyRunDir() . '/devstat.json' : null;
    $seen = $memo !== null ? (readJson($memo) ?? []) : [];
    $was = is_array($seen[$dev] ?? null) ? $seen[$dev] : null;
    $moved = $was !== null && ($was['io'] ?? null) !== $io && time() - (int) ($was['at'] ?? 0) <= FANBOY_DEV_STAT_KEEP;
    if ($memo !== null && ($was === null || ($was['io'] ?? null) !== $io || time() - (int) ($was['at'] ?? 0) > 60)) {
        $seen[$dev] = ['io' => $io, 'at' => time(), 'moved' => $moved ? time() : ($was['moved'] ?? null)];
        @mkdir(dirname($memo), 0700, true);
        writeAtomic($memo, jsonEncode($seen), 0600, 0, 0);
    }
    $recently = is_int($was['moved'] ?? null) && time() - $was['moved'] <= FANBOY_DEV_STAT_KEEP;
    return (int) $f[8] > 0 || $moved || $recently;
}
