<?php
declare(strict_types=1);

/*
 * Round 6, part 2 (the owner, 2026-11-15): an IPMI board's fan thresholds — READ ONLY, and explained. The concept leaves
 * steering through IPMI out, and so does this: no write to the BMC, never `ipmitool sensor thresh` or any other command
 * that sets something — the one command here is `ipmitool sensor` (list).
 *
 * The classic fault on server boards: a slow (quiet) fan drops below the BMC's lower threshold, the BMC takes it for
 * failed and spins every fan to full, they speed up, the slow one is over the threshold again, the BMC lets go — in waves.
 * The cure is lower thresholds in the BMC, which the user sets himself (his BMC's web page or ipmitool); Fanboy shows each
 * fan's speed beside its lower thresholds (LNR, LCR, LNC) and flags one within FANBOY_IPMI_NEAR of its lower critical.
 *
 * When: only where a BMC is there (part 1's look) and ipmitool is (a plugin brings it — Fanboy installs nothing); only on
 * demand (his page's look, «refresh»), never in the control round, never in the agent's tick: a process of its own
 * (`php agent.php job fanboy-ipmi`, a session of its own) runs it with a hard timeout of FANBOY_IPMI_TIMEOUT seconds, at
 * most once per FANBOY_IPMI_EVERY, and keeps what it read in RAM:
 *   RUN_DIR/fanboy/ipmi-sensors.json   {v: 1, at, ok, why: null|timeout|error|empty, fans: [{name, rpm, status, lnr, lcr, lnc, flag}]}
 * Not yet checked on real hardware (the page says so): fixtures after Supermicro's and ASRock Rack's tables.
 */

const FANBOY_IPMI_EVERY = 600;          // seconds: ipmitool asked at most once in this
const FANBOY_IPMI_TIMEOUT = 10;         // seconds: a BMC that doesn't answer is cut off
const FANBOY_IPMI_NEAR = 0.20;          // a fan within 20 % over its lower critical threshold: flagged
const FANBOY_IPMI_LAUNCH_PAUSE = 60.0;  // seconds between two starts of the reading process (one that failed included)

function fanboyIpmiCacheFile(): string
{
    return fanboyRunDir() . '/ipmi-sensors.json';
}

/** ipmitool, when it is there: the tests' tree names it; live the usual places (the IPMI plugin's) — '' none */
function fanboyIpmiTool(): string
{
    $p = fanboyPaths(fanboySysRoot() ?: null)['ipmitool'];
    if ($p !== '') {
        return is_file($p) && is_executable($p) ? $p : '';
    }
    foreach (['/usr/bin/ipmitool', '/usr/sbin/ipmitool', '/usr/local/bin/ipmitool', '/usr/local/sbin/ipmitool'] as $f) {
        if (is_file($f) && is_executable($f)) {
            return $f;
        }
    }
    return '';
}

/** A threshold or reading of ipmitool's table: a number (rounded to whole rpm), null for «na» or anything else */
function fanboyIpmiNum(string $v): ?int
{
    $v = trim($v);
    return preg_match('/^-?\d{1,6}(\.\d{1,6})?$/D', $v) ? (int) round((float) $v) : null;
}

/**
 * The fans of `ipmitool sensor`'s table (name | reading | unit | status | LNR | LCR | LNC | UNC | UCR | UNR): only rows in
 * RPM, in exactly that shape; names as the BMC gives them (printable, short). Each with its flag: below (at or under its
 * lower critical — LCR, else LNR), near (within FANBOY_IPMI_NEAR over it), null.
 */
function fanboyIpmiParse(string $out): array
{
    $fans = [];
    foreach (explode("\n", $out) as $line) {
        $col = array_map('trim', explode('|', $line));
        if (count($col) < 10 || strcasecmp($col[2], 'RPM') !== 0 || !preg_match('/^[A-Za-z0-9 _.#-]{1,32}$/D', $col[0])) {
            continue;
        }
        $rpm = fanboyIpmiNum($col[1]);
        [$lnr, $lcr, $lnc] = [fanboyIpmiNum($col[4]), fanboyIpmiNum($col[5]), fanboyIpmiNum($col[6])];
        $crit = $lcr ?? $lnr;
        $flag = null;
        if ($rpm !== null && $crit !== null && $crit > 0) {
            $flag = $rpm <= $crit ? 'below' : ($rpm <= $crit * (1 + FANBOY_IPMI_NEAR) ? 'near' : null);
        }
        $fans[] = ['name' => $col[0], 'rpm' => $rpm, 'status' => preg_match('/^[a-z]{1,4}$/D', $col[3]) ? $col[3] : null,
            'lnr' => $lnr, 'lcr' => $lcr, 'lnc' => $lnc, 'flag' => $flag];
        if (count($fans) >= FANBOY_IPMI_FANS_MAX) {
            break;
        }
    }
    return $fans;
}

/** Runs `ipmitool sensor` once (read only, hard timeout) and keeps what it read in RAM. $timeout: the tests' shorter one */
function fanboyIpmiRead(?int $timeout = null, ?int $wall = null): array
{
    $tool = fanboyIpmiTool();
    $got = ['v' => 1, 'at' => $wall ?? time(), 'ok' => false, 'why' => 'no_tool', 'fans' => []];
    if ($tool !== '') {
        [$exit, $out] = run([$tool, 'sensor'], $timeout ?? FANBOY_IPMI_TIMEOUT);
        $fans = $exit === 0 ? fanboyIpmiParse($out) : [];
        $got = ['v' => 1, 'at' => $wall ?? time(), 'ok' => $exit === 0 && $fans !== [], 'why' => $exit === 124 ? 'timeout' : ($exit !== 0 ? 'error' : ($fans ? null : 'empty')), 'fans' => $fans];
        logLine('Fanboy: the BMC\'s fan sensors read (ipmitool sensor): ' . ($got['ok'] ? count($fans) . ' fans, '
            . count(array_filter($fans, fn ($f) => $f['flag'] !== null)) . ' near or under their lower threshold' : 'nothing (' . $got['why'] . ')'));
    }
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyIpmiCacheFile(), jsonEncode($got), 0644, 0, 0);
    return $got;
}

/** The reading process: `php agent.php job fanboy-ipmi` — once, only when what is kept is older than FANBOY_IPMI_EVERY */
function fanboyIpmiJob(): int
{
    @mkdir(fanboyRunDir(), 0700, true);
    $lock = @fopen(fanboyRunDir() . '/ipmi.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $kept = readJson(fanboyIpmiCacheFile());
    if (!is_int($kept['at'] ?? null) || time() - $kept['at'] >= FANBOY_IPMI_EVERY || $kept['at'] > time()) {
        fanboyIpmiRead();
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return 0;
}

/**
 * For his state (every look of his page): what the BMC's fan sensors said last — null where there is no BMC or no
 * ipmitool. Older than FANBOY_IPMI_EVERY (or never read): the reading process started, never waited for (the page sees it
 * at its next look). $now: the tests' monotonic clock.
 */
function fanboyIpmiSensors(array $scan, ?float $now = null, ?int $wall = null): ?array
{
    if (($scan['ipmi'] ?? null) === null || fanboyIpmiTool() === '') {
        return null;
    }
    $now ??= fanboyMono();
    $wall ??= time();
    $kept = readJson(fanboyIpmiCacheFile());
    $fresh = is_int($kept['at'] ?? null) && $wall - $kept['at'] < FANBOY_IPMI_EVERY && $kept['at'] <= $wall;
    if (!$fresh && $now - ($GLOBALS['fanboyIpmiLaunched'] ?? -INF) >= FANBOY_IPMI_LAUNCH_PAUSE) {
        $GLOBALS['fanboyIpmiLaunched'] = $now;
        fanboyLaunch('fanboy-ipmi');
    }
    $fans = [];
    foreach (is_array($kept['fans'] ?? null) ? $kept['fans'] : [] as $f) {
        if (is_array($f) && is_string($f['name'] ?? null)) {
            $fans[] = $f;
        }
    }
    return ['at' => is_int($kept['at'] ?? null) ? $kept['at'] : null, 'ok' => ($kept['ok'] ?? false) === true,
        'why' => is_string($kept['why'] ?? null) ? $kept['why'] : null, 'fans' => $fans, 'reading' => !$fresh];
}

/** The report's lines (English, plain, no «/»): each fan's speed beside its lower thresholds and its flag */
function fanboyIpmiReportLines(): array
{
    $kept = readJson(fanboyIpmiCacheFile());
    if (!is_array($kept) || !is_array($kept['fans'] ?? null)) {
        return ['IPMI fan thresholds: not read'];
    }
    if (!($kept['ok'] ?? false)) {
        return ['IPMI fan thresholds: not read (' . (is_string($kept['why'] ?? null) ? preg_replace('/[^a-z_]/', '', $kept['why']) : '?') . ')'];
    }
    $n = fn ($v) => is_int($v) ? (string) $v : 'na';
    $lines = ['IPMI fan thresholds (rpm, lower: LNR LCR LNC):'];
    foreach ($kept['fans'] as $f) {
        if (is_array($f) && is_string($f['name'] ?? null) && preg_match('/^[A-Za-z0-9 _.#-]{1,32}$/D', $f['name'])) {
            $lines[] = "  {$f['name']}: " . (is_int($f['rpm'] ?? null) ? "{$f['rpm']} rpm" : 'no reading') . " (LNR {$n($f['lnr'] ?? null)}, LCR {$n($f['lcr'] ?? null)}, LNC {$n($f['lnc'] ?? null)})"
                . match ($f['flag'] ?? null) { 'below' => ' - at or under its lower critical', 'near' => ' - within 20 percent of its lower critical', default => '' };
        }
    }
    return $lines;
}
