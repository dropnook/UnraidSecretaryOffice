<?php
declare(strict_types=1);

/*
 * Fanboy's one hand on the fans — the ONLY place in the office that writes a fan output (pwmN, pwmN_enable). The
 * control service (fanboy-control.php), the setup's steps (fanboy-setup.php) and the guard (agent/fanboy-guard.php:
 * the cron line and `agent.sh stop|release`) all write through here; testFanboyNoWrite holds it to this file, and here
 * every write goes through fanboyWriteFile(): only `<hwmon root>/hwmonN/pwmM` or `…/pwmM_enable`, a file that is
 * there already (never created), whose real place lies under /sys/devices.
 *
 * Before the first write to an output in this boot its state is taken down — the value and the exact pwmN_enable
 * number (it87: 1 a fixed value, 2 the chip's curve; nct6775: 5 …) — in the journal in RAM and in a copy in the data
 * folder; fanboyGiveBack() puts exactly that back and reads it back. The journal also says who holds each output now
 * (the control or a setup step: pid, the process's start time, a beat on the monotonic clock) and what was written
 * last («expect»): an output whose holder is gone (killed hard, hung) is given back by whoever looks next — the agent's
 * tick every few seconds, the guard's cron line every minute when the agent is gone too; a value or mode that changed
 * behind his back is someone else's write (fanboyForeign()).
 *
 *   RUN_DIR/fanboy/journal.json   {"v": 1, "boot": "<boot id>", "outputs": {"<fan id>": {chip, address, device, output,
 *                                  initial: {value, enable}, back: null | {value, enable}, at,
 *                                  owner: null | {kind, pid, start, since}, expect: null | {value, enable},
 *                                  given: null | {at, why, ok}}}}
 *                                 back (part 3): where «give back» goes when it isn't the state before — only for an
 *                                 output found on a fixed value someone else set (enable 1) whose owner chose «the
 *                                 mainboard's automatic» for it: the same value, the chip's own mode number. Set by the
 *                                 control at each take; a setup step's take clears it (a test always goes back exactly)
 *   RUN_DIR/fanboy/write.lock     every write and every change of the journal under its flock
 *   RUN_DIR/fanboy/beat-<pid>     a holder's beat: hrtime() in ns (CLOCK_MONOTONIC: the same for every process)
 *   <data folder>/fanboy/initial.json   {"v": 1, "boards": {"<board key>": {"<fan id>": {value, enable, boot, at}}}} —
 *                                  what the outputs had before Fanboy, kept per board (a copy: the journal counts)
 *
 * Needs only lib/util.php (readJson, writeAtomic, jsonEncode) and RUN_DIR — agent/fanboy-guard.php loads it without
 * the rest of the agent.
 */

/**
 * The zone the server's clock runs in — exactly agentZone() of agent/agent.php (glibc's /etc/localtime; a link to a
 * missing zone file or none: UTC), here for the guard, which loads no more of the agent: its lines in agent.log in the
 * agent's zone (round 5, the VM run: «23:31:22 the control ended» then «00:31:22 released» in the same second).
 */
function fanboyZone(string $link = '/etc/localtime'): string
{
    $zone = preg_replace('#^.*/zoneinfo/#', '', (string) @readlink($link));
    return in_array($zone, timezone_identifiers_list(), true) && is_file($link) ? $zone : 'UTC';
}

const FANBOY_FULL = 255;
const FANBOY_BACK_CHECK = 10;           // round 5: seconds after a give-back the fan is looked at (did the mainboard take it?)
const FANBOY_BACK_CHECK_UNTIL = 180;    // … no later than this (a look that comes too late says nothing)
const FANBOY_RPM_SANE = 20000;          // round 5 (forum 3.8): an rpm at or over this is a sentinel (65535), not a speed
const FANBOY_OWNER_STALE = 30;          // seconds without a beat: the holder is taken for hung, its outputs given back
const FANBOY_OWNER_STALE_STANDING = 10; // round 12: … when it holds an output standing at 0 (a CPU cooler may): sooner
const FANBOY_JOURNAL_MAX = 64;          // outputs in the journal at most (a board has 3–10)
const FANBOY_OWNER_KINDS = ['control', 'step'];

/** The root of the hardware files: '' live — only the tests name a fake tree (OFFICE_FANBOY_ROOT) */
function fanboySysRoot(): string
{
    $r = getenv('OFFICE_FANBOY_ROOT');
    return is_string($r) && $r !== '' && $r[0] === '/' ? rtrim($r, '/') : '';
}

function fanboyHwmonRoot(): string
{
    return fanboySysRoot() . '/sys/class/hwmon';
}

function fanboyRunDir(): string
{
    return RUN_DIR . '/fanboy';
}

/** This boot's id (the kernel's) — a journal of another boot is no journal (the BIOS set every output anew) */
function fanboyBootId(): string
{
    $id = trim((string) @file_get_contents(fanboySysRoot() . '/proc/sys/kernel/random/boot_id', false, null, 0, 64));
    return preg_match('/^[0-9a-f-]{8,40}$/D', $id) ? $id : 'unknown';
}

/** Seconds on the monotonic clock (since boot, never stepped) */
function fanboyMono(): float
{
    return hrtime(true) / 1e9;
}

// ===================================================================== reading and writing one output

/** A number from an output's file (pwmN, pwmN_enable, fanN_input), null when it isn't one or isn't there */
function fanboyReadInt(string $file): ?int
{
    $s = @file_get_contents($file, false, null, 0, 32);
    return is_string($s) && preg_match('/^\s*(-?\d{1,9})\s*$/D', $s, $m) ? (int) $m[1] : null;
}

/**
 * The one write: $value into an output's pwmN or pwmN_enable — only a file named so directly in a hwmonN folder of the
 * hwmon root, there already, no link, really under /sys/devices. False when it isn't one or the kernel refused.
 */
function fanboyWriteFile(string $file, int $value): bool
{
    $root = fanboyHwmonRoot();
    if (!preg_match('#^' . preg_quote($root, '#') . '/hwmon\d{1,4}/pwm\d{1,2}(_enable)?$#D', $file) || $value < 0 || $value > 255) {
        return false;
    }
    clearstatcache(true, $file);
    $real = realpath($file);
    $devices = realpath(fanboySysRoot() . '/sys/devices');
    if ($real === false || $devices === false || !str_starts_with($real, "$devices/") || is_link($file) || !is_file($real)) {
        return false;
    }
    $f = @fopen($real, 'r+');                   // there already: never created, never a new file
    if ($f === false) {
        return false;
    }
    @ftruncate($f, 0);                          // sysfs ignores it; the tests' plain files need it
    $ok = @fwrite($f, "$value\n") !== false;
    return fclose($f) && $ok;
}

/** An output's files now: [pwm file, enable file|null] from its device and number — the hwmonN found anew, never stored */
function fanboyOutputFiles(string $device, int $output): ?array
{
    if (!preg_match('#^[A-Za-z0-9_.:/-]{1,200}$#D', $device) || str_contains($device, '..') || $output < 1 || $output > 99) {
        return null;
    }
    $sys = fanboySysRoot();
    $found = glob("$sys/sys/devices/$device/hwmon/hwmon*", GLOB_ONLYDIR) ?: [];
    if (count($found) !== 1) {
        return null;
    }
    $entry = basename($found[0]);
    $dir = fanboyHwmonRoot() . "/$entry";
    if (!preg_match('/^hwmon\d{1,4}$/D', $entry) || realpath($dir) !== realpath($found[0]) || !is_file("$dir/pwm$output")) {
        return null;
    }
    return ["$dir/pwm$output", is_file("$dir/pwm{$output}_enable") ? "$dir/pwm{$output}_enable" : null];
}

/** An output's state now: {value, enable} (null each when it says nothing), null when the output isn't there */
function fanboyOutputNow(string $device, int $output): ?array
{
    $files = fanboyOutputFiles($device, $output);
    return $files === null ? null : ['value' => fanboyReadInt($files[0]), 'enable' => $files[1] === null ? null : fanboyReadInt($files[1])];
}

/**
 * Round 7 (the owner's Z890, it8696 on it87): does an output read back as a fixed value $value written — the value
 * within 3 (chips round) and the mode 1 (a fixed value), or 0 when the value is full: the it87 driver reports full speed
 * as pwmN_enable 0 («full on») right after a fixed 255 — what was asked, not a take-back. «Taken back» is only a value
 * that changed or the chip's own automatic (≥ 2); a 0 for less than full is someone else's «full on», not ours.
 * No enable file (null): the value alone. $now null (the output gone): never obeys.
 */
function fanboyObeys(int $value, ?array $now): bool
{
    if ($now === null || !is_int($now['value'] ?? null) || abs($now['value'] - $value) > 3) {
        return false;
    }
    $e = $now['enable'] ?? null;
    return $e === null || $e === 1 || ($e === 0 && $value >= FANBOY_FULL - 3);
}

/**
 * Sets an output to a fixed value: pwmN_enable 1 first (when it isn't), then the value — and reads both back. The
 * read-back is what counts as «expect» (a chip may round the value). Null: not written (gone, refused).
 */
function fanboyWriteValue(string $device, int $output, int $value): ?array
{
    $files = fanboyOutputFiles($device, $output);
    if ($files === null) {
        return null;
    }
    [$pwm, $enable] = $files;
    if ($enable !== null && fanboyReadInt($enable) !== 1 && !fanboyWriteFile($enable, 1)) {
        return null;
    }
    if (!fanboyWriteFile($pwm, max(0, min(255, $value)))) {
        return null;
    }
    return ['value' => fanboyReadInt($pwm), 'enable' => $enable === null ? null : fanboyReadInt($enable)];
}

/**
 * Puts an output back to a state taken down before (value, exact enable number) and reads it back. A fixed value
 * (enable 1, or no enable file): the mode, then the value. The chip's own curve (≥ 2): the old value first (while the
 * output is still fixed — some chips refuse a value in their own mode), then the mode. Full (0): the mode.
 * ok = the mode reads back as it was (and, for a fixed value, the value within 3 — chips that round).
 */
function fanboyWriteState(string $device, int $output, array $initial): array
{
    $files = fanboyOutputFiles($device, $output);
    if ($files === null) {
        return ['ok' => false, 'why' => 'gone', 'now' => null];
    }
    [$pwm, $enable] = $files;
    $v = is_int($initial['value'] ?? null) ? max(0, min(255, $initial['value'])) : null;
    $e = is_int($initial['enable'] ?? null) ? $initial['enable'] : null;
    $tries = 0;
    do {
        if ($e === null || $e === 1) {
            if ($enable !== null && $e === 1 && fanboyReadInt($enable) !== 1) {
                fanboyWriteFile($enable, 1);
            }
            if ($v !== null) {
                fanboyWriteFile($pwm, $v);
            }
        } elseif ($e === 0) {
            fanboyWriteFile($enable ?? '', 0);
        } else {
            if ($v !== null && $enable !== null && fanboyReadInt($enable) === 1) {
                fanboyWriteFile($pwm, $v);
            }
            fanboyWriteFile($enable ?? '', $e);
        }
        $now = ['value' => fanboyReadInt($pwm), 'enable' => $enable === null ? null : fanboyReadInt($enable)];
        $ok = ($e === null || $now['enable'] === $e) && (!($e === null || $e === 1) || $v === null || ($now['value'] !== null && abs($now['value'] - $v) <= 3));
        if (!$ok && $e === 1 && $v !== null) {
            $ok = fanboyObeys($v, $now);        // round 7: a fixed full reads back as «full on» (enable 0) on it87 — as it was
        }
    } while (!$ok && ++$tries < 2);
    return ['ok' => $ok, 'why' => $ok ? null : 'read_back', 'now' => $now];
}

// ===================================================================== the journal

/** Under the write lock: $fn(journal) → [journal to keep (null: unchanged), result] */
function fanboyLocked(callable $fn, float $wait = 5.0): mixed
{
    $dir = fanboyRunDir();
    @mkdir($dir, 0700, true);
    if (is_link("$dir/write.lock")) {
        @unlink("$dir/write.lock");
    }
    $lock = @fopen("$dir/write.lock", 'c');
    if ($lock === false) {
        throw new RuntimeException('Fanboy: the write lock can\'t be opened');
    }
    try {
        $until = fanboyMono() + $wait;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (fanboyMono() > $until) {
                throw new RuntimeException('Fanboy: the write lock is held too long');
            }
            usleep(20000);
        }
        $j = fanboyJournal();
        [$keep, $out] = $fn($j);
        if ($keep !== null) {
            writeAtomic("$dir/journal.json", jsonEncode($keep), 0600, 0, 0);
        }
        return $out;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** The journal as kept — another boot's or a broken one: an empty one of this boot */
function fanboyJournal(): array
{
    $j = readJson(fanboyRunDir() . '/journal.json');
    $boot = fanboyBootId();
    $out = ['v' => 1, 'boot' => $boot, 'outputs' => []];
    if (!is_array($j) || ($j['boot'] ?? null) !== $boot || !is_array($j['outputs'] ?? null)) {
        return $out;
    }
    foreach ($j['outputs'] as $id => $o) {
        if (is_string($id) && is_array($o) && is_string($o['device'] ?? null) && is_int($o['output'] ?? null) && is_array($o['initial'] ?? null)) {
            $out['outputs'][$id] = $o + ['owner' => null, 'expect' => null, 'given' => null, 'back' => null];
        }
    }
    return $out;
}

/** Does anything hold an output now? (the guard's cron line asks this first — one small file) */
function fanboyHeld(?array $j = null): array
{
    return array_keys(array_filter(($j ?? fanboyJournal())['outputs'], fn ($o) => is_array($o['owner'] ?? null)));
}

/**
 * A process's start time (field 22 of /proc/<pid>/stat) — with the pid it names one process, never a later one. A
 * zombie (ended, not yet waited for) has none: it holds nothing any more.
 */
function fanboyProcStart(int $pid): ?string
{
    $s = @file_get_contents("/proc/$pid/stat", false, null, 0, 1024);
    if (!is_string($s) || ($p = strrpos($s, ')')) === false) {
        return null;
    }
    $f = preg_split('/\s+/', trim(substr($s, $p + 1)));
    return ($f[0] ?? '') !== 'Z' && isset($f[19]) && ctype_digit($f[19]) ? $f[19] : null;
}

/** This process as a holder */
function fanboyMe(string $kind): array
{
    $pid = getmypid() ?: 0;
    return ['kind' => $kind, 'pid' => $pid, 'start' => fanboyProcStart($pid), 'since' => time()];
}

/** A holder's beat: now, on the monotonic clock (every round of the control, every step of a setup step) */
function fanboyBeat(?int $pid = null): void
{
    $pid ??= getmypid() ?: 0;
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . "/beat-$pid", (string) hrtime(true), 0600, 0, 0);
}

/**
 * Is a holder still at work? Its process there (same pid, same start time) and its beat fresh (≤ FANBOY_OWNER_STALE s
 * on the monotonic clock). 'dead' (gone), 'hung' (there, no beat), 'alive'.
 */
function fanboyOwnerState(array $owner, ?float $now = null, int $stale = FANBOY_OWNER_STALE): string
{
    $pid = is_int($owner['pid'] ?? null) ? $owner['pid'] : 0;
    if ($pid <= 1 || !is_dir("/proc/$pid") || ($owner['start'] ?? null) !== fanboyProcStart($pid)) {
        return 'dead';
    }
    $raw = trim((string) @file_get_contents(fanboyRunDir() . "/beat-$pid", false, null, 0, 32));
    $beat = ctype_digit($raw) ? (int) $raw : 0;
    $age = ($now ?? fanboyMono()) - $beat / 1e9;
    return $beat > 0 && $age <= $stale ? 'alive' : 'hung';
}

/**
 * Takes an output for a holder: its state taken down first when this boot hasn't yet (then also into the data
 * folder's copy, per board), the holder noted. Refused (null) when another live holder has it or the output isn't there.
 *
 * $fan['auto']: the chip's own mode number to give back to instead of a fixed value found there (the owner's choice
 * «the mainboard's automatic», only the control passes it) — null or missing: back exactly to the state before.
 *
 * @param array{id:string, chip:string, address:string, device:string, output:int, auto?:?int} $fan
 */
function fanboyTake(array $fan, array $owner, string $boardKey = ''): ?array
{
    $now = fanboyOutputNow($fan['device'], $fan['output']);
    if ($now === null) {
        return null;
    }
    $taken = fanboyLocked(function (array $j) use ($fan, $owner, $now): array {
        $o = $j['outputs'][$fan['id']] ?? null;
        if ($o !== null && is_array($o['owner']) && !fanboySameOwner($o['owner'], $owner) && fanboyOwnerState($o['owner']) !== 'dead') {
            return [null, null];
        }
        if (count($j['outputs']) >= FANBOY_JOURNAL_MAX && $o === null) {
            return [null, null];
        }
        $first = $o === null;
        $o ??= ['chip' => $fan['chip'], 'address' => $fan['address'], 'device' => $fan['device'], 'output' => $fan['output'],
                'initial' => $now, 'at' => time(), 'expect' => null, 'given' => null];
        $o['device'] = $fan['device'];          // the device path is this boot's (an address may have moved)
        // round 5: its tach inputs and how fast the mainboard ran it before his first take this boot (the give-back's check)
        if (is_array($fan['paired'] ?? null) && $fan['paired']) {
            $o['tachs'] = array_values(array_filter($fan['paired'], 'is_int'));
            if ($first) {
                $o['rpm'] = fanboyTachNow($fan['device'], $o['tachs']);
            }
        }
        $o['owner'] = $owner;
        // round 9 (H2): a fan he set to full for safety is his again — that state ends here (it is given back anew later)
        if (is_array($o['given'] ?? null) && isset($o['given']['full'])) {
            unset($o['given']['full']);
        }
        $auto = $fan['auto'] ?? null;
        $o['back'] = is_int($auto) && $auto >= 2 && $auto <= 9 && ($o['initial']['enable'] ?? null) === 1
            ? ['value' => $o['initial']['value'] ?? null, 'enable' => $auto] : null;
        $j['outputs'][$fan['id']] = $o;
        return [$j, ['first' => $first, 'initial' => $o['initial']]];
    });
    if ($taken !== null && $taken['first'] && $boardKey !== '') {
        fanboyInitialKeep($boardKey, $fan['id'], $taken['initial']);
    }
    return $taken;
}

function fanboySameOwner(array $a, array $b): bool
{
    return ($a['pid'] ?? null) === ($b['pid'] ?? null) && ($a['start'] ?? null) === ($b['start'] ?? null);
}

/** The fastest of these tach inputs of an output's chip now (rpm; a sentinel ≥ FANBOY_RPM_SANE is no reading), null: none */
function fanboyTachNow(string $device, array $tachs): ?int
{
    $found = preg_match('#^[A-Za-z0-9_.:/-]{1,200}$#D', $device) && !str_contains($device, '..')
        ? (glob(fanboySysRoot() . "/sys/devices/$device/hwmon/hwmon*", GLOB_ONLYDIR) ?: []) : [];
    $dir = count($found) === 1 ? $found[0] : null;
    $best = null;
    foreach ($tachs as $n) {
        $r = is_int($n) && $dir !== null ? fanboyReadInt("$dir/fan{$n}_input") : null;
        if ($r !== null && $r >= 0 && $r < FANBOY_RPM_SANE) {
            $best = max($best ?? 0, $r);
        }
    }
    return $best;
}

/**
 * Round 5 (forum 3.4, it87 issue #56: a chip that ignores its own mode once written): FANBOY_BACK_CHECK seconds after a
 * give-back, the fan against what it turned before Fanboy first took it this boot — clearly slower (under half) and not
 * rising again: the mainboard didn't take it back — full speed, fixed, noted in the journal (`given.full`). A fan the
 * BIOS keeps standing (0 before) is fine. $wall: the tests' clock. Returns the outputs set to full now.
 */
function fanboyGivenCheck(?int $wall = null): array
{
    $wall ??= time();
    if (!is_file(fanboyRunDir() . '/journal.json')) {
        return [];
    }
    return fanboyLocked(function (array $j) use ($wall): array {
        $full = [];
        $changed = false;
        foreach ($j['outputs'] as $id => $o) {
            $g = $o['given'] ?? null;
            if (is_array($o['owner']) || !is_array($g) || isset($g['checked']) || !is_int($g['at'] ?? null) || $wall - $g['at'] < FANBOY_BACK_CHECK) {
                continue;
            }
            $j['outputs'][$id]['given']['checked'] = $wall;
            $changed = true;
            // round 12 correction: proof that the chip's automatic takes over on this board — given back to it (enable ≥ 2),
            // read back so, and its fan turning at the check (the agent keeps it per board: auto_back_seen)
            $back = is_array($o['back'] ?? null) ? $o['back'] : (is_array($o['initial'] ?? null) ? $o['initial'] : null);     // what was written back
            if ($back !== null && is_int($back['enable'] ?? null) && $back['enable'] >= 2 && $wall - $g['at'] <= FANBOY_BACK_CHECK_UNTIL) {
                $read = fanboyOutputNow($o['device'], $o['output']);
                $rpm = fanboyTachNow($o['device'], is_array($o['tachs'] ?? null) ? $o['tachs'] : []);
                if (is_int($read['enable'] ?? null) && $read['enable'] >= 2 && $rpm !== null && $rpm >= 300) {
                    $j['outputs'][$id]['given']['auto_ok'] = $wall;
                }
            }
            $before = $o['rpm'] ?? null;
            if ($wall - $g['at'] > FANBOY_BACK_CHECK_UNTIL || in_array($g['why'] ?? '', ['foreign', 'gone'], true) || !is_int($before) || $before < 300) {
                continue;
            }
            $now = fanboyTachNow($o['device'], is_array($o['tachs'] ?? null) ? $o['tachs'] : []);
            if ($now !== null && $now < $before * 0.5 && $now <= ($g['rpm'] ?? $now) * 1.1 + 50) {
                $w = fanboyWriteValue($o['device'], $o['output'], FANBOY_FULL);
                $j['outputs'][$id]['given']['full'] = ['at' => $wall, 'rpm' => $now, 'before' => $before, 'ok' => $w !== null];
                $full[$id] = $j['outputs'][$id]['given']['full'] + ['chip' => $o['chip'], 'output' => $o['output']];
            }
        }
        return [$changed ? $j : null, $full];
    }) ?? [];
}

/**
 * Round 8 (G1): outputs nobody holds to full speed, fixed — a fan that didn't turn again after the quiet test: its
 * output stays at full (more air never harms) until Fanboy steers it or Unraid restarts; noted in the journal
 * (`given.full`, as round 5's give-back check notes it). Returns the outputs written.
 */
function fanboyFullNow(array $ids, string $why): array
{
    if (!$ids) {
        return [];
    }
    return fanboyLocked(function (array $j) use ($ids, $why): array {
        $done = [];
        foreach ($ids as $id) {
            $o = $j['outputs'][$id] ?? null;
            if (!is_array($o) || is_array($o['owner'] ?? null)) {
                continue;
            }
            $w = fanboyWriteValue($o['device'], $o['output'], FANBOY_FULL);
            $j['outputs'][$id]['given'] = (is_array($o['given'] ?? null) ? $o['given'] : ['at' => time(), 'why' => $why, 'ok' => true])
                + ['checked' => time()];
            $j['outputs'][$id]['given']['full'] = ['at' => time(), 'why' => $why, 'ok' => $w !== null];
            $done[] = $id;
        }
        return [$done ? $j : null, $done];
    }) ?? [];
}

/**
 * Round 9 (H2): the outputs Fanboy himself set to full for safety and nobody holds since — a fan that didn't start again
 * after the quiet test (no_restart), one the mainboard didn't take back (round 5's give-back check) — while they still read
 * full: [id => {why, at, before: {value, enable}}]. Read only.
 */
function fanboySafetyFull(): array
{
    $out = [];
    foreach (fanboyJournal()['outputs'] as $id => $o) {
        $full = is_array($o['given'] ?? null) && is_array($o['given']['full'] ?? null) ? $o['given']['full'] : null;
        if ($full === null || is_array($o['owner'] ?? null) || ($full['ok'] ?? true) === false) {
            continue;
        }
        $now = fanboyOutputNow($o['device'], $o['output']);
        if ($now === null || !fanboyObeys(FANBOY_FULL, $now)) {
            continue;                   // changed since (someone else, a restart): the state is over
        }
        $out[$id] = ['why' => is_string($full['why'] ?? null) ? $full['why'] : 'not_back', 'at' => is_int($full['at'] ?? null) ? $full['at'] : null,
            'before' => ['value' => $o['initial']['value'] ?? null, 'enable' => $o['initial']['enable'] ?? null]];
    }
    return $out;
}

/** Round 12: an output held standing — its holder wrote 0 last (a fixed 0, read back) */
function fanboyStanding(array $o): bool
{
    return is_array($o['owner'] ?? null) && ($o['expect']['value'] ?? null) === 0 && ($o['expect']['enable'] ?? 1) === 1;
}

/** Round 12: is any output held standing now? (the guard looks again within its minute while one is) */
function fanboyStandingHeld(): bool
{
    return (bool) array_filter(fanboyJournal()['outputs'], fn ($o) => is_array($o) && fanboyStanding($o));
}

/** Writes a value to an output this holder has (pct of 255 already turned into 0–255), read back into «expect» */
function fanboySet(string $id, int $value, array $owner): ?array
{
    return fanboyLocked(function (array $j) use ($id, $value, $owner): array {
        $o = $j['outputs'][$id] ?? null;
        if ($o === null || !is_array($o['owner']) || !fanboySameOwner($o['owner'], $owner)) {
            return [null, null];
        }
        $back = fanboyWriteValue($o['device'], $o['output'], $value);
        if ($back === null) {
            return [null, null];
        }
        $j['outputs'][$id]['expect'] = $back;
        return [$j, $back];
    });
}

/**
 * Gives outputs back: each to the state taken down before Fanboy, read back; the holder gone from the journal.
 * $ids null = every output held (by anyone: the stop, the release); $only = only those of this holder; $dead = only
 * those whose holder is gone or hung (the watch). Returns [id => {ok, why, now, owner}].
 */
function fanboyGiveBack(?array $ids, string $why, ?array $only = null, bool $dead = false): array
{
    return fanboyLocked(function (array $j) use ($ids, $why, $only, $dead): array {
        $out = [];
        $mono = fanboyMono();
        foreach ($j['outputs'] as $id => $o) {
            if (!is_array($o['owner']) || ($ids !== null && !in_array($id, $ids, true))
                || ($only !== null && !fanboySameOwner($o['owner'], $only))) {
                continue;
            }
            // round 12: a holder that keeps an output standing at 0 counts as hung sooner (a standing fan never waits 30 s)
            $state = $dead ? fanboyOwnerState($o['owner'], $mono, fanboyStanding($o) ? FANBOY_OWNER_STALE_STANDING : FANBOY_OWNER_STALE) : null;
            if ($dead && $state === 'alive') {
                continue;
            }
            if ($state === 'hung') {
                fanboyKillHung($o['owner']);
            }
            $r = fanboyWriteState($o['device'], $o['output'], is_array($o['back'] ?? null) ? $o['back'] : $o['initial']);
            $j['outputs'][$id]['owner'] = null;
            $j['outputs'][$id]['expect'] = null;
            $j['outputs'][$id]['given'] = ['at' => time(), 'why' => $why, 'ok' => $r['ok'],
                'rpm' => fanboyTachNow($o['device'], is_array($o['tachs'] ?? null) ? $o['tachs'] : [])];
            $out[$id] = $r + ['owner' => $o['owner'], 'holder' => $state];
        }
        return [$out ? $j : null, $out];
    });
}

/**
 * A holder that is there but beats no more (hung — it could wake and write again): ended for good before its outputs
 * are given back — only when it is the office's own php (agent.php in its command line)
 */
function fanboyKillHung(array $owner): void
{
    $pid = (int) ($owner['pid'] ?? 0);
    $cmd = (string) @file_get_contents("/proc/$pid/cmdline", false, null, 0, 4096);
    if ($pid > 1 && $pid !== getmypid() && str_contains($cmd, 'agent.php') && ($owner['start'] ?? null) === fanboyProcStart($pid)) {
        @posix_kill($pid, 9);
    }
}

/**
 * Someone else's write: the outputs this holder has whose value or mode isn't what it wrote last (and read back) —
 * [id => {expect, now}]. An output gone is not someone else's write (the chip went: fanboyOutputNow() null).
 */
function fanboyForeign(array $owner): array
{
    $out = [];
    foreach (fanboyJournal()['outputs'] as $id => $o) {
        if (!is_array($o['owner']) || !fanboySameOwner($o['owner'], $owner) || !is_array($o['expect'])) {
            continue;
        }
        $now = fanboyOutputNow($o['device'], $o['output']);
        // round 7: what was written still holds when it reads back as that fixed value — a full 255 reading «full on»
        // (enable 0, the it87 way) is ours, not someone else's
        $wrote = $o['expect']['value'] ?? null;
        $same = $now !== null && $now['enable'] === $o['expect']['enable'] && $now['value'] === $wrote;
        if ($now !== null && !$same && !(is_int($wrote) && ($o['expect']['enable'] ?? null) !== null && $o['expect']['enable'] <= 1 && fanboyObeys($wrote, $now))) {
            $out[$id] = ['expect' => $o['expect'], 'now' => $now];
        }
    }
    return $out;
}

// ===================================================================== the copy in the data folder

/** The data folder's copy of what the outputs had before Fanboy (only where DATA_DIR is known — never from the guard) */
function fanboyInitialFile(): ?string
{
    return defined('DATA_DIR') && is_dir(DATA_DIR) ? DATA_DIR . '/fanboy/initial.json' : null;
}

function fanboyInitialRead(): array
{
    $f = fanboyInitialFile();
    $j = $f === null ? null : readJson($f);
    return is_array($j) && is_array($j['boards'] ?? null) ? $j : ['v' => 1, 'boards' => []];
}

/** One output's state before Fanboy into the copy (per board; another board's entries and unknown keys stay) */
function fanboyInitialKeep(string $boardKey, string $id, array $initial): void
{
    $f = fanboyInitialFile();
    if ($f === null) {
        return;
    }
    @mkdir(dirname($f), 0700, true);
    $j = fanboyInitialRead();
    $b = is_array($j['boards'][$boardKey] ?? null) ? $j['boards'][$boardKey] : [];
    $b[$id] = ['value' => $initial['value'] ?? null, 'enable' => $initial['enable'] ?? null, 'boot' => fanboyBootId(), 'at' => time()];
    $j['boards'][$boardKey] = $b;
    writeAtomic($f, jsonEncode($j), 0600);
}
