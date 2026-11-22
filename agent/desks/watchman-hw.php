<?php
declare(strict_types=1);

/*
 * The Night Watchman's hardware (1.96, the concept «Der Nachtwächter in 1.96», 11 B «Hardware-Fehlerzähler»): what the
 * kernel counts about the machine itself, read from RAM (/proc, /sys) — no tool, no daemon, no command, no disk touched;
 * the night shift looks too.
 *
 *   hw_mce       machine checks: /proc/interrupts, the row MCE (the CPU raised a machine-check exception: it found a
 *                hardware error) and on AMD THR and DFR (threshold and deferred error interrupts) — any rise, told. Never
 *                MCP: it counts the kernel's routine polls and grows by itself.
 *   hw_ecc       memory errors (ECC): /sys/devices/system/edac/mc/mc*: ce_count, ue_count, per module (dimm* or rank*)
 *                dimm_ce_count, dimm_ue_count, dimm_label — one not corrected: told at once; corrected ones a quiet line,
 *                told once a module (or what its controller couldn't put on a module) has WATCH_HW_ECC_DAY in a day
 *   hw_aer       PCIe errors: /sys/bus/pci/devices/<device>/aer_dev_correctable, _nonfatal, _fatal (their TOTAL_ERR_ line)
 *                — one that couldn't be corrected: told; corrected ones are the link's own retries: a quiet line above
 *                WATCH_HW_AER_HOUR in an hour
 *   hw_throttle  the CPU slowed down for heat: cpu*\/thermal_throttle/core_ and package_throttle_total_time_ms (Intel's driver
 *                only) — more than WATCH_HW_THROTTLE_HOUR in an hour: a quiet line (the busiest CPU's: the most-throttled
 *                core's and package's counter, never a sum over the CPUs that share a package)
 *   hw_kernel    the kernel stumbled: /proc/sys/kernel/tainted, a flag newly set since this boot's first look — it died (D:
 *                an Oops or a BUG), a bad memory page (B), a machine check (M), a CPU stuck (L): told; a warning (W): quiet.
 *                The other flags (an out-of-tree or unsigned module …) are Unraid's normal and never looked at.
 *
 * Every counter starts at 0 at a boot and only grows until the next (a driver reloaded, EDAC's reset_counters: lower again
 * — counted on from there). What they said at the last look lives in RAM (WATCH_HW_RAM, this boot's): the day's rounds
 * and the night shift read and write the same file, so nothing is told twice across an array stop; with it the last hour's
 * samples for the AER and throttle rules, the day's corrected memory errors and the kernel's flags at this boot's first
 * look. Where a look starts (watchmanHwStart()):
 *   ram   the RAM file of this boot: on from there
 *   zero  a new boot after a watch (his hw.json names another boot), or the night shift's first round within
 *         WATCH_HW_BOOT_NEW of the start: every count since the start is news
 *   look  the first look ever (no hw.json — an update to 1.96 on a server up for weeks), taken over anew, or the night
 *         shift long after the start without a RAM file: what the counters say now is normal (concept 6: «der erste
 *         Blick ist normal»)
 * The kernel's flags are always measured from the boot's first look (concept 11 B: «neu gesetzt seit dem ersten Blick
 * dieses Starts») — a flag set before it (a warning at boot) is the server's way.
 *
 * What a server doesn't have is «not available», never «0 errors»: no EDAC folder (no ECC memory, or no driver for its
 * memory controller), no AER files (no capable device, or the firmware keeps AER for itself), no thermal_throttle (AMD,
 * or an older kernel). The page state's `hw` says which (watchmanHwPage()); his area's light: watchman-cats.php.
 *
 * The entries live in book-more.json beside his book (WATCH_KINDS_MORE: an office before 1.96 never sees them, so it
 * can't drop them), in the area `hw`, with no ATT&CK technique (no attack — concept 11.4), and never in a chain
 * (watchmanChains(): a machine check is no step of an intrusion).
 *
 * Also here: the known CPU faults (concept 11 C: the posture tips cpu_fault and old_microcode, below).
 *
 * Functions and constants only, loaded with his desk (the desk glob, and watchman.php's require_once).
 */

const WATCH_HW_RAM            = RUN_DIR . '/watchman-hw.json';     // this boot's counters as the last look saw them (RUNDIR_FILES)
const WATCH_HW_ECC_DAY        = 10;          // corrected memory errors of one module in a day: told
const WATCH_HW_AER_HOUR       = 100;         // corrected PCIe errors of one device in an hour: a quiet line
const WATCH_HW_THROTTLE_HOUR  = 60000;       // ms the CPU slowed down for heat in an hour: a quiet line
const WATCH_HW_HOUR           = 3600;        // the window of those two
const WATCH_HW_SAMPLES        = 16;          // samples kept for it (a round every WATCH_EVERY: 12 an hour)
const WATCH_HW_BOOT_NEW       = 1800;        // the night shift this soon after the start counts from 0
const WATCH_HW_MAX            = ['mc' => 16, 'dimm' => 64, 'pci' => 512, 'cpu' => 1024];       // what one look reads at most
// /proc/sys/kernel/tainted: the flags he looks at, the worst first (docs.kernel.org admin-guide/tainted-kernels) — told or quiet
const WATCH_HW_TAINT          = ['D' => 128, 'B' => 32, 'M' => 16, 'L' => 16384, 'W' => 512];
const WATCH_HW_TAINT_TOLD     = ['D', 'B', 'M', 'L'];
const WATCH_HW_TAINT_VARIANT  = ['D' => null, 'B' => 'hw_kernel_page', 'M' => 'hw_kernel_mce', 'L' => 'hw_kernel_lockup', 'W' => 'hw_kernel_warn'];

/** Where he reads — fixed; the tests hand in fixture trees */
function watchmanHwPaths(): array
{
    return ['interrupts' => '/proc/interrupts', 'edac' => '/sys/devices/system/edac/mc', 'pci' => '/sys/bus/pci/devices',
            'cpus' => '/sys/devices/system/cpu', 'tainted' => '/proc/sys/kernel/tainted', 'ram' => WATCH_HW_RAM];
}

// ===================================================================== the look (RAM only)

/** A counter file: a whole number, or null (missing, unreadable, not a number) */
function watchmanHwInt(string $file): ?int
{
    $s = @file_get_contents($file, false, null, 0, 64);
    return is_string($s) && preg_match('/^\s*(\d{1,18})\s*$/D', $s, $m) ? (int) $m[1] : null;
}

/** A name from sysfs (a module's label, a controller's name, a driver), plain and short; '' when there is none */
function watchmanHwName(string $s, int $max = 40): string
{
    $s = trim((string) preg_replace('/[^\x20-\x7E]+/', ' ', $s));
    return substr((string) preg_replace('/\s+/', ' ', $s), 0, $max);
}

/**
 * The counters as the kernel shows them now. A part is null when this server doesn't have it (or its place isn't given);
 * never 0 for «not there».
 *
 * @return array{mce: ?array, edac: ?array, aer: ?array, throttle: ?array, taint: ?int}
 */
function watchmanHwLook(array $p): array
{
    return [
        'mce'      => isset($p['interrupts']) ? watchmanHwInterrupts((string) $p['interrupts']) : null,
        'edac'     => isset($p['edac']) ? watchmanHwEdac((string) $p['edac']) : null,
        'aer'      => isset($p['pci']) ? watchmanHwAer((string) $p['pci']) : null,
        'throttle' => isset($p['cpus']) ? watchmanHwThrottle((string) $p['cpus']) : null,
        'taint'    => isset($p['tainted']) ? watchmanHwInt((string) $p['tainted']) : null,
    ];
}

/**
 * /proc/interrupts: the rows MCE, THR and DFR summed over the CPUs (its columns). Null without an MCE row (no machine
 * checks on this CPU, or not readable); THR and DFR null where the kernel has none (only AMD's have them).
 *
 * @return array{mce: int, thr: ?int, dfr: ?int}|null
 */
function watchmanHwInterrupts(string $file): ?array
{
    $text = @file_get_contents($file, false, null, 0, 8 << 20);
    if (!is_string($text) || $text === '') {
        return null;
    }
    $out = ['mce' => null, 'thr' => null, 'dfr' => null];
    foreach (['MCE' => 'mce', 'THR' => 'thr', 'DFR' => 'dfr'] as $row => $k) {
        if (preg_match('/^\s*' . $row . ':((?:[ \t]+\d{1,18})+)/m', $text, $m)) {
            $out[$k] = array_sum(array_map('intval', preg_split('/\s+/', trim($m[1])) ?: []));
        }
    }
    return $out['mce'] === null ? null : $out;
}

/**
 * EDAC: per memory controller its counts and per module (dimm* or rank*, those with counters) its counts and label.
 * Null: no controller with counters — this server counts no memory errors.
 *
 * @return array<string, array{name: string, ce: int, ue: int, dimms: array<string, array{label: string, ce: int, ue: int}>}>|null
 */
function watchmanHwEdac(string $dir): ?array
{
    $out = [];
    foreach (array_slice(glob("$dir/mc[0-9]*", GLOB_ONLYDIR) ?: [], 0, WATCH_HW_MAX['mc']) as $mc) {
        $id = basename($mc);
        $ce = watchmanHwInt("$mc/ce_count");
        $ue = watchmanHwInt("$mc/ue_count");
        if (!preg_match('/^mc\d{1,3}$/D', $id) || ($ce === null && $ue === null)) {
            continue;
        }
        $dimms = [];
        $found = array_merge(glob("$mc/dimm[0-9]*", GLOB_ONLYDIR) ?: [], glob("$mc/rank[0-9]*", GLOB_ONLYDIR) ?: []);
        foreach (array_slice($found, 0, WATCH_HW_MAX['dimm']) as $d) {
            $did = basename($d);
            $dce = watchmanHwInt("$d/dimm_ce_count");
            $due = watchmanHwInt("$d/dimm_ue_count");
            if (!preg_match('/^(dimm|rank)\d{1,4}$/D', $did) || ($dce === null && $due === null)) {
                continue;
            }
            $dimms[$did] = ['label' => watchmanHwName((string) @file_get_contents("$d/dimm_label", false, null, 0, 256)), 'ce' => (int) $dce, 'ue' => (int) $due];
        }
        $out[$id] = ['name' => watchmanHwName((string) @file_get_contents("$mc/mc_name", false, null, 0, 256)), 'ce' => (int) $ce, 'ue' => (int) $ue,
                     'dimms' => $dimms];
    }
    return $out ?: null;
}

/**
 * PCIe AER: per device with the files its totals (the TOTAL_ERR_ line: the last number of it) and its driver. Null: no
 * device counts (none is capable, or the firmware keeps AER for itself).
 *
 * @return array<string, array{cor: int, nonfatal: int, fatal: int, driver: string}>|null
 */
function watchmanHwAer(string $dir): ?array
{
    $out = [];
    foreach (array_slice(glob("$dir/*/aer_dev_correctable") ?: [], 0, WATCH_HW_MAX['pci']) as $f) {
        $dev = dirname($f);
        $bdf = basename($dev);
        if (!preg_match('/^[0-9a-f]{4,8}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $bdf)) {
            continue;
        }
        $total = function (string $which, string $line) use ($dev): ?int {
            $text = (string) @file_get_contents("$dev/aer_dev_$which", false, null, 0, 16384);
            return preg_match('/^' . $line . '\s+(\d{1,18})\s*$/m', $text, $m) ? (int) $m[1] : null;
        };
        $cor = $total('correctable', 'TOTAL_ERR_COR');
        if ($cor === null) {
            continue;
        }
        $driver = @readlink("$dev/driver");
        $out[$bdf] = ['cor' => $cor, 'nonfatal' => (int) $total('nonfatal', 'TOTAL_ERR_NONFATAL'), 'fatal' => (int) $total('fatal', 'TOTAL_ERR_FATAL'),
                      'driver' => is_string($driver) ? watchmanHwName(basename($driver), 24) : ''];
    }
    return $out ?: null;
}

/**
 * Intel's thermal throttle counters: the most-throttled core's and package's total time (ms since the start). Null: no
 * CPU has them (AMD, or a kernel before the *_total_time_ms files).
 *
 * @return array{core: int, pkg: int}|null
 */
function watchmanHwThrottle(string $dir): ?array
{
    $core = $pkg = null;
    foreach (array_slice(glob("$dir/cpu[0-9]*/thermal_throttle", GLOB_ONLYDIR) ?: [], 0, WATCH_HW_MAX['cpu']) as $t) {
        $c = watchmanHwInt("$t/core_throttle_total_time_ms");
        $p = watchmanHwInt("$t/package_throttle_total_time_ms");
        $core = $c === null ? $core : max((int) $core, $c);
        $pkg = $p === null ? $pkg : max((int) $pkg, $p);
    }
    return $core === null && $pkg === null ? null : ['core' => (int) $core, 'pkg' => (int) $pkg];
}

// ===================================================================== the compare (in his lock)

/** The counters flat: name => value (edac per module and per controller, aer per device) — what is compared round to round */
function watchmanHwFlat(array $look): array
{
    $out = [];
    if (is_array($look['mce'] ?? null)) {
        foreach (['mce', 'thr', 'dfr'] as $k) {
            if ($look['mce'][$k] !== null) {
                $out["mce:$k"] = (int) $look['mce'][$k];
            }
        }
    }
    foreach ((array) ($look['edac'] ?? []) as $mc => $m) {
        foreach (['ce', 'ue'] as $k) {
            $out["edac:$mc:$k"] = (int) $m[$k];
            foreach ((array) $m['dimms'] as $d => $x) {
                $out["edac:$mc/$d:$k"] = (int) $x[$k];
            }
        }
    }
    foreach ((array) ($look['aer'] ?? []) as $bdf => $a) {
        foreach (['cor', 'nonfatal', 'fatal'] as $k) {
            $out["aer:$bdf:$k"] = (int) $a[$k];
        }
    }
    if (is_array($look['throttle'] ?? null)) {
        $out['thr:core'] = (int) $look['throttle']['core'];
        $out['thr:pkg'] = (int) $look['throttle']['pkg'];
    }
    return $out;
}

/** How much a counter grew since $was (lower than then: reset — counted on from 0) */
function watchmanHwGrew(int $now, ?int $was): int
{
    return $was === null ? $now : ($now >= $was ? $now - $was : $now);
}

/**
 * Where this round's look starts (see the head of this file): ram, zero or look. $ram: the RAM file; $pool: his hw.json
 * (null in the night shift: it can't read the pool); $day: a round of the agent (not the night shift).
 */
function watchmanHwStart(?array $ram, string $boot, bool $fresh, ?array $pool, bool $day, ?int $btime, int $now): string
{
    if ($fresh) {
        return 'look';
    }
    if (is_array($ram) && ($ram['v'] ?? null) === 1 && is_array($ram['last'] ?? null) && ($ram['boot'] ?? null) === $boot) {
        return 'ram';
    }
    if ($day) {
        return is_array($pool) && is_string($pool['boot'] ?? null) && $pool['boot'] !== $boot ? 'zero' : 'look';
    }
    return $btime !== null && $now - $btime >= 0 && $now - $btime < WATCH_HW_BOOT_NEW ? 'zero' : 'look';
}

/**
 * One round's hardware: this look against the last ($ram as watchmanHwStart() takes it), new entries into $book.
 *
 * @return array{0: list<string>, 1: array, 2: array}  the kinds added, the RAM state to keep, the page's summary (state.json hw)
 */
function watchmanHwCompare(?array $ram, string $start, array $look, string $boot, int $now, array &$book): array
{
    $flat = watchmanHwFlat($look);
    $taint = $look['taint'];
    if ($start === 'ram') {
        $last = (array) $ram['last'];
        $taint0 = is_int($ram['taint0'] ?? null) ? $ram['taint0'] : $taint;
        $lastTaint = is_int($ram['taint'] ?? null) ? $ram['taint'] : $taint0;
        $day = is_array($ram['day'] ?? null) ? $ram['day'] : [];
        $samples = array_values(array_filter((array) ($ram['hour'] ?? []), fn ($s) => is_array($s) && is_int($s[0] ?? null) && is_array($s[1] ?? null)));
    } else {
        $last = $start === 'look' ? $flat : [];          // zero: whatever counts now happened since the start
        $taint0 = $lastTaint = $taint;
        $day = [];
        $samples = [];
    }
    $grew = [];
    foreach ($flat as $k => $v) {
        $g = watchmanHwGrew($v, is_int($last[$k] ?? null) ? $last[$k] : null);
        if ($g > 0) {
            $grew[$k] = $g;
        }
    }
    $today = date('Y-m-d', $now);
    if (($day['d'] ?? null) !== $today) {
        $day = ['d' => $today, 'ce' => []];
    }
    // the last hour's samples (the correctable PCIe errors and the throttle counters): the oldest within the hour is the measure
    $samples = array_values(array_filter($samples, fn ($s) => $s[0] <= $now && $now - $s[0] <= WATCH_HW_HOUR));
    $hourAgo = $samples[0][1] ?? $last;
    // a sample holds the non-zero ones (missing: 0 then)
    $samples[] = [$now, array_filter($flat, fn ($v, $k) => $v > 0 && (str_ends_with($k, ':cor') || str_starts_with($k, 'thr:')), ARRAY_FILTER_USE_BOTH)];
    $samples = array_slice($samples, -WATCH_HW_SAMPLES);
    $inHour = fn (string $k): int => watchmanHwGrew((int) ($flat[$k] ?? 0), (int) ($hourAgo[$k] ?? 0));

    $b8 = substr($boot, 0, 8);
    $added = [];
    $note = function (string $kind, string $key, array $p, bool $important, int $quiet = 0) use (&$book, &$added, $now): void {
        $added[] = watchmanHwNote($book, $kind, $key, $p, $important, $now, $quiet);
    };
    // machine checks: any rise
    if (is_array($look['mce']) && (isset($grew['mce:mce']) || isset($grew['mce:thr']) || isset($grew['mce:dfr']))) {
        $note('hw_mce', "hw_mce:$b8", ['mce' => (int) $look['mce']['mce'], 'thr' => $look['mce']['thr'], 'dfr' => $look['mce']['dfr']], true);
    }
    // memory: per module, and what a controller counted beyond its modules
    foreach ((array) ($look['edac'] ?? []) as $mc => $m) {
        $where = [];
        foreach (['ce', 'ue'] as $k) {
            $sum = 0;
            foreach ((array) $m['dimms'] as $d => $x) {
                $g = $grew["edac:$mc/$d:$k"] ?? 0;
                $sum += $g;
                if ($g > 0) {
                    $where["$mc/$d"][$k] = $g;
                }
            }
            if (($grew["edac:$mc:$k"] ?? 0) > $sum) {
                $where[$mc][$k] = $grew["edac:$mc:$k"] - $sum;
            }
        }
        foreach ($where as $w => $g) {
            $day['ce'][$w] = (int) ($day['ce'][$w] ?? 0) + (int) ($g['ce'] ?? 0);
            $d = str_contains($w, '/') ? $m['dimms'][explode('/', $w)[1]] : null;
            $p = ['where' => $w, 'label' => $d !== null ? $d['label'] : $m['name'], 'ce' => $d !== null ? $d['ce'] : $m['ce'], 'ue' => $d !== null ? $d['ue'] : $m['ue'],
                  'day' => $day['ce'][$w]];
            $note('hw_ecc', "hw_ecc:$b8:$w", $p, isset($g['ue']) || $day['ce'][$w] >= WATCH_HW_ECC_DAY);
        }
    }
    $day['ce'] = array_slice((array) $day['ce'], 0, WATCH_HW_MAX['dimm'] + WATCH_HW_MAX['mc'], true);
    // PCIe: not correctable — told; corrected ones above the hour's measure — quiet, once an hour per device
    foreach ((array) ($look['aer'] ?? []) as $bdf => $a) {
        $bad = ($grew["aer:$bdf:nonfatal"] ?? 0) + ($grew["aer:$bdf:fatal"] ?? 0);
        $hour = $inHour("aer:$bdf:cor");
        if ($bad > 0 || $hour > WATCH_HW_AER_HOUR) {
            $note('hw_aer', "hw_aer:$b8:$bdf", ['dev' => $bdf, 'driver' => $a['driver'], 'cor' => $a['cor'], 'nonfatal' => $a['nonfatal'], 'fatal' => $a['fatal'],
                                               'hour' => $hour], $bad > 0, $bad > 0 ? 0 : WATCH_HW_HOUR);
        }
    }
    // the CPU slowed down for heat: quiet, once an hour
    if (is_array($look['throttle'] ?? null)) {
        $ms = max($inHour('thr:core'), $inHour('thr:pkg'));
        if ($ms > WATCH_HW_THROTTLE_HOUR) {
            $note('hw_throttle', "hw_throttle:$b8", ['minutes' => intdiv($ms, 60000), 'total' => intdiv(max($look['throttle']['core'], $look['throttle']['pkg']), 60000)],
                false, WATCH_HW_HOUR);
        }
    }
    // the kernel's flags set since this boot's first look; an entry when one more came since the last round
    if ($taint !== null && $taint0 !== null) {
        $flags = watchmanHwTaintFlags($taint & ~$taint0);
        $more = watchmanHwTaintFlags($taint & ~$taint0 & ~(int) $lastTaint);
        if ($more) {
            $note('hw_kernel', "hw_kernel:$b8", ['flags' => $flags], (bool) array_intersect($flags, WATCH_HW_TAINT_TOLD));
        }
    }
    $state = ['v' => 1, 'boot' => $boot, 'time' => $now, 'last' => $flat, 'taint0' => $taint0, 'taint' => $taint, 'day' => $day, 'hour' => $samples];
    return [array_values(array_filter($added)), $state, watchmanHwSummary($look, $taint0, $now)];
}

/** The flags he looks at that are set in $bits, the worst first */
function watchmanHwTaintFlags(int $bits): array
{
    return array_keys(array_filter(WATCH_HW_TAINT, fn (int $bit): bool => ($bits & $bit) !== 0));
}

/**
 * An entry of his hardware: the open one of its key brought up to date (important once it is), else a new one — unless the
 * same key was noted less than $quiet ago (a burst that goes on: once an hour). The kind when new, else null.
 */
function watchmanHwNote(array &$book, string $kind, string $key, array $p, bool $important, int $now, int $quiet = 0): ?string
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') !== $key) {
            continue;
        }
        if (watchmanOpen($e)) {
            if (($e['p'] ?? []) !== $p) {
                $book[$i]['p'] = $p;
                $book[$i]['last'] = $now;
            }
            if ($important) {
                $book[$i]['important'] = true;
            }
            return null;
        }
        if ($quiet > 0 && $now - (int) ($e['noted'] ?? 0) < $quiet) {
            return null;
        }
    }
    $e = watchmanEntry($kind, $key, $now, $p);
    if ($important) {
        $e['important'] = true;
    }
    $book[] = $e;
    return $kind;
}

/**
 * The page's summary (state.json `hw`): when he looked, what this server has (null: not available — never «0 errors»),
 * the counts since the start, the kernel's flags set since this boot's first look.
 */
function watchmanHwSummary(array $look, ?int $taint0, int $now): array
{
    $edac = is_array($look['edac'] ?? null) ? $look['edac'] : null;
    $aer = is_array($look['aer'] ?? null) ? $look['aer'] : null;
    return [
        'time' => $now,
        'mce' => is_array($look['mce'] ?? null) ? $look['mce'] : null,
        'ecc' => $edac === null ? null : ['ce' => array_sum(array_column($edac, 'ce')), 'ue' => array_sum(array_column($edac, 'ue')),
                                         'modules' => array_sum(array_map(fn ($m) => count($m['dimms']), $edac)), 'controllers' => count($edac)],
        'aer' => $aer === null ? null : ['devices' => count($aer), 'cor' => array_sum(array_column($aer, 'cor')),
                                        'bad' => array_sum(array_column($aer, 'nonfatal')) + array_sum(array_column($aer, 'fatal'))],
        'throttle' => is_array($look['throttle'] ?? null) ? ['minutes' => intdiv(max($look['throttle']['core'], $look['throttle']['pkg']), 60000)] : null,
        'kernel' => $look['taint'] === null ? null : ['flags' => watchmanHwTaintFlags($look['taint'] & ~(int) $taint0)],
    ];
}

/**
 * The round's part (watchmanRound(), in his lock): the look against this boot's last, the RAM file kept, his hw.json
 * naming this boot (only the day's rounds: $paths' hw_file lies on the pool). $btime: when the server was started.
 *
 * @return array{0: list<string>, 1: array}  the kinds added, the summary for state.json
 */
function watchmanHwRound(array $paths, array $look, string $boot, ?int $btime, bool $fresh, int $now, array &$book): array
{
    $ramFile = (string) ($paths['hw']['ram'] ?? WATCH_HW_RAM);
    $poolFile = isset($paths['hw_file']) ? (string) $paths['hw_file'] : null;
    $ram = readJson($ramFile);
    $pool = $poolFile !== null ? readJson($poolFile) : null;
    $start = watchmanHwStart($ram, $boot, $fresh, $pool, $poolFile !== null, $btime, $now);
    [$added, $state, $summary] = watchmanHwCompare($start === 'ram' ? $ram : null, $start, $look, $boot, $now, $book);
    @mkdir(dirname($ramFile), 0700, true);
    writeAtomic($ramFile, jsonEncode($state), 0600, 0, 0);
    if ($poolFile !== null && ($pool['boot'] ?? null) !== $boot) {
        if (!is_dir(dirname($poolFile))) {
            @mkdir(dirname($poolFile), 0755, true);         // his very first round: before watchmanSave() makes his folder
        }
        writeAtomic($poolFile, jsonEncode(['v' => 1, 'boot' => $boot] + array_diff_key((array) $pool, ['v' => 1, 'boot' => 1])));
    }
    return [$added, $summary + ['start' => $start]];
}

// ===================================================================== words

/** An entry's words (entry.<kind>, check.<kind>, notify.<kind>): numbers, a module's or a device's name — the same in every language */
function watchmanHwText(array $e): array
{
    $p = (array) ($e['p'] ?? []);
    return match ((string) $e['kind']) {
        'hw_mce'      => ['counts' => implode(' · ', array_map(fn ($k) => strtoupper($k) . ' ' . (int) $p[$k],
                             array_values(array_filter(['mce', 'thr', 'dfr'], fn ($k) => is_int($p[$k] ?? null)))))],
        'hw_ecc'      => ['where' => (string) ($p['label'] ?? '') !== '' ? "{$p['label']} ({$p['where']})" : (string) ($p['where'] ?? ''),
                          'ce' => (int) ($p['ce'] ?? 0), 'ue' => (int) ($p['ue'] ?? 0), 'day' => (int) ($p['day'] ?? 0)],
        'hw_aer'      => ['dev' => (string) ($p['dev'] ?? '') . ((string) ($p['driver'] ?? '') !== '' ? " ({$p['driver']})" : ''),
                          'cor' => (int) ($p['cor'] ?? 0), 'bad' => (int) ($p['nonfatal'] ?? 0) + (int) ($p['fatal'] ?? 0),
                          'fatal' => (int) ($p['fatal'] ?? 0), 'hour' => (int) ($p['hour'] ?? 0)],
        'hw_throttle' => ['minutes' => (int) ($p['minutes'] ?? 0), 'total' => (int) ($p['total'] ?? 0)],
        'hw_kernel'   => ['flags' => implode(', ', array_map('strval', (array) ($p['flags'] ?? [])))],
        default       => [],
    };
}

/**
 * The shape an entry's words have now (entry.<variant>): memory errors that ECC couldn't correct, PCIe errors that
 * couldn't be, the kernel's worst flag. Null: entry.<kind>.
 */
function watchmanHwVariant(array $e): ?string
{
    $p = (array) ($e['p'] ?? []);
    return match ((string) ($e['kind'] ?? '')) {
        'hw_ecc'    => (int) ($p['ue'] ?? 0) > 0 ? 'hw_ecc_ue' : null,
        'hw_aer'    => (int) ($p['nonfatal'] ?? 0) + (int) ($p['fatal'] ?? 0) > 0 ? 'hw_aer_bad' : null,
        'hw_kernel' => WATCH_HW_TAINT_VARIANT[array_values(array_intersect(array_keys(WATCH_HW_TAINT), (array) ($p['flags'] ?? [])))[0] ?? 'D'],
        default     => null,
    };
}

// ===================================================================== known CPU faults (concept 11 C, step 10)

/*
 * Two posture tips of the area `hw` (advice, never in the book, never told — watchmanPosture()):
 *   cpu_fault      a CPU with a fault its maker documented and microcode older than the correction — WATCH_HW_CPU_FAULTS,
 *                  shipped with the office (no network): today Intel's 13th/14th Gen desktop «Vmin Shift Instability»,
 *                  microcode below 0x12B. Its words: update the BIOS (Intel: 0x12B «must be loaded via BIOS update»), a
 *                  microcode doesn't heal a CPU already harmed, the warranty, the signs (and the machine checks he counted)
 *   old_microcode  the kernel's own word: /sys/devices/system/cpu/vulnerabilities/old_microcode says «Vulnerable» when the
 *                  CPU booted with microcode older than the kernel's list (kernels from 6.16; no file: nothing said)
 */
const WATCH_HW_CPU_FAULTS = __DIR__ . '/watchman-cpu-faults.json';

/**
 * The known fault this CPU has with its microcode ($cpu: watchmanCpu() — vendor id, family, model number, model name,
 * microcode), or null. A table entry in exactly its shape only; the model name must hold one of its names as a whole
 * word (i9-13900K is not i9-13900KF, i9-13900 not i9-13900T).
 *
 * @return array{id: string, tip: string, name: string, min: string}|null
 */
function watchmanHwCpuFault(?array $cpu, string $table = WATCH_HW_CPU_FAULTS): ?array
{
    if ($cpu === null || !is_int($cpu['family'] ?? null) || !is_int($cpu['model_no'] ?? null) || !is_int($cpu['microcode'] ?? null)) {
        return null;
    }
    $t = readJson($table);
    foreach (($t['v'] ?? null) === 1 ? (array) ($t['faults'] ?? []) : [] as $f) {
        if (!is_array($f) || !is_string($f['id'] ?? null) || !is_string($f['tip'] ?? null) || !isset(WATCH_POSTURE[$f['tip']]) || !is_string($f['name'] ?? null)
            || !is_string($f['vendor'] ?? null) || !is_int($f['family'] ?? null) || !is_array($f['models'] ?? null)
            || !is_string($f['microcode_below'] ?? null) || !preg_match('/^0x[0-9a-f]{1,8}$/iD', $f['microcode_below'])) {
            continue;
        }
        if (($cpu['vendor_id'] ?? null) !== $f['vendor'] || $cpu['family'] !== $f['family'] || !in_array($cpu['model_no'], $f['models'], true)) {
            continue;
        }
        $names = is_array($f['names'] ?? null) ? array_filter($f['names'], 'is_string') : null;
        if ($names !== null && !array_filter($names, fn (string $n): bool => preg_match('/(?<![\w-])' . preg_quote($n, '/') . '(?![\w-])/i', (string) ($cpu['model'] ?? '')) === 1)) {
            continue;
        }
        if ($cpu['microcode'] < (int) hexdec(substr($f['microcode_below'], 2))) {
            return ['id' => $f['id'], 'tip' => $f['tip'], 'name' => watchmanHwName($f['name'], 60), 'min' => strtoupper(substr($f['microcode_below'], 2))];
        }
    }
    return null;
}

/** /proc/cpuinfo's facts a known fault needs: family, model number, the lowest microcode of its CPUs (null: not there) */
function watchmanHwCpuInfo(string $info): array
{
    $codes = preg_match_all('/^microcode\s*:\s*0x([0-9a-f]{1,8})\s*$/mi', $info, $m) ? array_map('hexdec', $m[1]) : [];
    return [
        'vendor_id' => preg_match('/^vendor_id\s*:\s*(\S+)/m', $info, $v) ? $v[1] : null,
        'family'    => preg_match('/^cpu family\s*:\s*(\d{1,4})\s*$/m', $info, $f) ? (int) $f[1] : null,
        'model_no'  => preg_match('/^model\s*:\s*(\d{1,4})\s*$/m', $info, $n) ? (int) $n[1] : null,
        'microcode' => $codes ? (int) min($codes) : null,
    ];
}

/** The microcode as people read it (0x12F), or '?' */
function watchmanHwMicrocode(?array $cpu): string
{
    return is_int($cpu['microcode'] ?? null) ? '0x' . strtoupper(dechex($cpu['microcode'])) : '?';
}

/** The page's part `hw` from his state (state.json hw) — null before his first look at the hardware */
function watchmanHwPage(mixed $hw): ?array
{
    return is_array($hw) && is_int($hw['time'] ?? null) ? array_intersect_key($hw, array_flip(['time', 'mce', 'ecc', 'aer', 'throttle', 'kernel', 'start'])) : null;
}
