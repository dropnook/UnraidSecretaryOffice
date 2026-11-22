<?php
declare(strict_types=1);

/*
 * «Load drivers yourself» (Treiber selbst laden) — only on the user's click: the fan-chip drivers Unraid's own kernel
 * ships (FANBOY_DRIVERS), tried one after the other with modprobe; nothing is downloaded, nothing written to
 * /boot/config. A driver that finds no chip doesn't load at all; one that loads but brings no output he could steer is
 * unloaded again (said); the kernel refusing because the BIOS keeps the chip (ACPI: «Device or resource busy») is only
 * explained — he sets no start option, no driver option. What helped is remembered (<data folder>/fanboy/drivers.json,
 * per board) and loaded at every agent start before the control steers; «undo» unloads it and forgets it.
 *
 * The list, checked with `modinfo` on test4 (Unraid 7.4.0, kernel 6.18.60-Unraid) on 2026-11-14: every one is in
 * /lib/modules/<kernel>/kernel/drivers/hwmon/. Only Super-I/O chips that find themselves (the board's fan chip) — no
 * i2c/USB devices (those load by themselves when their bus sees them). nct6687 and the newer it87 for IT86xx chips are
 * NOT Unraid's (the driver plugins bring them): Fanboy never loads a module from anywhere else.
 * Seen there too: a refused modprobe leaves the helpers it pulled in loaded (nct6775 → nct6775_core, hwmon_vid) — whatever
 * a try brought that isn't kept is unloaded again (fanboyModulesNew()).
 */

const FANBOY_DRIVERS = ['nct6775', 'it87', 'nct6683', 'f71882fg', 'f71805f', 'w83627ehf', 'w83627hf', 'sch5627', 'sch5636',
                        'smsc47m1', 'pc87360', 'pc87427', 'vt1211', 'dell_smm_hwmon'];
const FANBOY_DRIVERS_FILE = 'drivers.json';

/** modprobe (tests: OFFICE_MODPROBE_BIN, a stand-in) */
function fanboyModprobe(): ?string
{
    $b = getenv('OFFICE_MODPROBE_BIN');
    return is_string($b) && $b !== '' ? (is_executable($b) ? $b : null) : bin('modprobe');
}

/** The modules loaded now (/sys/module) */
function fanboyModules(): array
{
    return array_values(array_filter(@scandir(fanboyPaths(fanboySysRoot() ?: null)['modules']) ?: [], fn ($m) => preg_match('/^[a-z0-9_]{1,48}$/D', $m) === 1));
}

/** What a try brought besides what was there before it: unloaded again (modprobe -r refuses one in use) */
function fanboyModulesNew(string $bin, array $before): array
{
    $new = array_values(array_diff(fanboyModules(), $before));
    foreach ($new as $m) {
        run([$bin, '-r', $m], 30);
    }
    return $new;
}

function fanboyModuleLoaded(string $m): bool
{
    return is_dir(fanboyPaths(fanboySysRoot() ?: null)['modules'] . '/' . $m);
}

function fanboyDriversRead(): array
{
    $j = readJson(FANBOY_DIR . '/' . FANBOY_DRIVERS_FILE);
    $out = ['v' => 1, 'boards' => []];
    foreach (is_array($j['boards'] ?? null) ? $j['boards'] : [] as $key => $b) {
        $mods = is_array($b['modules'] ?? null) ? array_values(array_filter($b['modules'], fn ($m) => is_string($m) && in_array($m, FANBOY_DRIVERS, true))) : [];
        if (is_string($key)) {
            $out['boards'][$key] = ['modules' => $mods, 'at' => is_int($b['at'] ?? null) ? $b['at'] : null] + (is_array($b) ? $b : []);
        }
    }
    return $out + (is_array($j) ? $j : []);
}

function fanboyDriversWrite(array $j): void
{
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic(FANBOY_DIR . '/' . FANBOY_DRIVERS_FILE, jsonEncode($j), 0600);
}

/** The outputs he could steer that a module brings now (its chips') */
function fanboyModuleOutputs(array $scan, string $m): int
{
    return array_sum(array_map(fn ($c) => fanboyOffers($c) && $c['module'] === $m ? count($c['outputs']) : 0, $scan['chips']));
}

/**
 * Tries the list: each not loaded yet — modprobe; refused: «no chip» or, «Device or resource busy», the BIOS keeps it
 * (acpi); loaded: outputs? kept and remembered, the rest of the list not tried — none: unloaded again. Steps:
 * [{module, result: there|no_chip|acpi|no_output|kept|failed, outputs}]
 */
function fanboyDriversLoad(): array
{
    $bin = fanboyModprobe();
    if ($bin === null) {
        throw new Problem('fanboy_no_modprobe');
    }
    $before = fanboyDetect();
    if ($before['counts']['outputs'] > 0) {
        throw new Problem('fanboy_drivers_there');        // he sees outputs already: nothing to load
    }
    $steps = [];
    $kept = null;
    foreach (FANBOY_DRIVERS as $m) {
        if (fanboyModuleLoaded($m)) {
            $steps[] = ['module' => $m, 'result' => 'there', 'outputs' => fanboyModuleOutputs($before, $m)];
            continue;
        }
        $was = fanboyModules();
        [$code, , $err] = run([$bin, $m], 30);
        if ($code !== 0) {
            fanboyModulesNew($bin, $was);           // the helpers a refused try pulled in
            $steps[] = ['module' => $m, 'result' => stripos($err, 'resource busy') !== false ? 'acpi' : (stripos($err, 'no such device') !== false ? 'no_chip' : 'failed'), 'outputs' => 0];
            continue;
        }
        $after = fanboyDetect();
        $n = fanboyModuleOutputs($after, $m);
        if ($n === 0) {
            run([$bin, '-r', $m], 30);
            fanboyModulesNew($bin, $was);           // and whatever of it is still there
            $steps[] = ['module' => $m, 'result' => 'no_output', 'outputs' => 0];
            continue;
        }
        $steps[] = ['module' => $m, 'result' => 'kept', 'outputs' => $n];
        $kept = $m;
        break;
    }
    if ($kept !== null) {
        $key = fanboyBoardKey($before['board']);
        $j = fanboyDriversRead();
        $mods = $j['boards'][$key]['modules'] ?? [];
        $j['boards'][$key] = ['modules' => array_values(array_unique(array_merge($mods, [$kept]))), 'at' => time()];
        fanboyDriversWrite($j);
    }
    $acpi = (bool) array_filter($steps, fn ($s) => $s['result'] === 'acpi');
    logLine('Fanboy: drivers tried on the user\'s click — ' . implode(', ', array_map(fn ($s) => "{$s['module']} {$s['result']}", $steps)));
    $result = ['ok' => true, 'steps' => $steps, 'kept' => $kept, 'acpi' => $acpi, 'at' => time()];
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/drivers-last.json', jsonEncode($result), 0644, 0, 0);
    return $result;
}

/**
 * At the agent's start, before the control steers: what helped on this board, loaded again (when not loaded). $mods: the
 * night shift after a boot, before the array started (2026-11-22) — the modules the copy of his mirror on the flash
 * names for this board (fanboyMirrorFromFlash(): his store in the data folder isn't there yet); only modules he knows.
 */
function fanboyDriversBoot(?array $mods = null): array
{
    $bin = fanboyModprobe();
    $board = fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $mods = $mods !== null ? array_values(array_filter($mods, fn ($m) => is_string($m) && in_array($m, FANBOY_DRIVERS, true)))
        : (fanboyDriversRead()['boards'][fanboyBoardKey($board)]['modules'] ?? []);
    $out = [];
    foreach ($mods as $m) {
        if ($bin === null || fanboyModuleLoaded($m)) {
            continue;
        }
        [$code, , $err] = run([$bin, $m], 30);
        $out[$m] = $code === 0;
        logLine("Fanboy: driver $m loaded at the start" . ($code === 0 ? '' : ' — refused: ' . trim(substr($err, 0, 200))));
    }
    return $out;
}

/** «Undo»: what he loaded unloaded and forgotten — refused while he steers (hand back first) */
function fanboyDriversUndo(): array
{
    if (fanboyControlPid() !== null || fanboyStepRunning()) {
        throw new Problem('fanboy_steering');
    }
    $bin = fanboyModprobe();
    $board = fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $key = fanboyBoardKey($board);
    $j = fanboyDriversRead();
    $mods = $j['boards'][$key]['modules'] ?? [];
    $out = [];
    foreach ($mods as $m) {
        $out[$m] = !fanboyModuleLoaded($m) || ($bin !== null && run([$bin, '-r', $m], 30)[0] === 0);
    }
    unset($j['boards'][$key]);
    fanboyDriversWrite($j);
    @unlink(fanboyRunDir() . '/drivers-last.json');
    logLine('Fanboy: drivers he had loaded let go on the user\'s click — ' . ($out ? implode(', ', array_map(fn ($m, $ok) => $m . ($ok ? '' : ' (still loaded)'), array_keys($out), $out)) : 'none'));
    return ['ok' => true, 'unloaded' => $out];
}

/** For the state: what he loaded on this board and the last try's steps */
function fanboyDriversState(array $board): array
{
    return [
        'mine' => fanboyDriversRead()['boards'][fanboyBoardKey($board)]['modules'] ?? [],
        'last' => readJson(fanboyRunDir() . '/drivers-last.json'),
        'can'  => fanboyModprobe() !== null,
    ];
}
