<?php
declare(strict_types=1);

/*
 * «Always on» (2026-11-22): parts of the office that keep working while the array is stopped — Fanboy's fans first —
 * without ever holding the array's stop. Required by tests/run.php; the tests are in its part `alwayson`.
 *
 * Written to run without /proc where they can (the tests' own fake trees): the steps that need real processes say so.
 * The helpers (fanboyT(), fanboyTGuard() …) are tests/fanboy-control-tests.php's.
 */

/** An output of the fake board held by a process of the test's (a sleeper) at 250, as a control would hold it */
function alwaysOnTHold(array $t, int $n = 4, string $kind = 'control')
{
    $sleeper = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $pid = proc_get_status($sleeper)['pid'];
    $holder = ['kind' => $kind, 'pid' => $pid, 'start' => fanboyProcStart($pid), 'since' => time()];
    fanboyBeat($pid);
    fanboyTake(['id' => fanboyTId($n), 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => $n], $holder, FANBOY_T_KEY);
    fanboySet(fanboyTId($n), 250, $holder);
    return $sleeper;
}

function alwaysOnTEnd($sleeper): void
{
    if (is_resource($sleeper)) {
        @proc_terminate($sleeper, 9);
        proc_close($sleeper);
    }
}

/**
 * Step S1b: the guard's release hears why. `fanboy-guard.php release array_stopping` (agent.sh at the array's stop) gives
 * every output back as ever, and the status the page and the Dashboard tile read says «the array is stopped»; a plain
 * release (stop, restart, update, removal) and any other word say «released» as before.
 */
function testAlwaysOnStopWhy(): void
{
    $t = fanboyT('ao-why');
    $sleepers = [];
    try {
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        $run = function (string ...$args) use ($t, $data, &$sleepers): array {
            $sleepers[] = alwaysOnTHold($t);
            fanboyStatusWrite(['v' => 1, 'pid' => 4242, 'who' => 'fanboy', 'why' => null, 'phase' => 'steering', 'zones' => []]);
            $held = fanboyHeld();
            $out = fanboyTGuard($t, $data, 'release', ...$args);
            $s = readJson(fanboyStatusFile()) ?? [];
            return [$held, fanboyTOut($t, 4), fanboyHeld(), $s['who'] ?? null, $s['why'] ?? null, $s['phase'] ?? null, str_contains($out, 'released — 1 outputs back with the BIOS')];
        };
        same('always on S1b: release at the array\'s stop — the output back as before (128, fixed), nothing held, the status: the BIOS, the array is stopped; the log line as ever',
            [[fanboyTId(4)], [128, 1], [], 'bios', 'array_stopped', 'ended', true], $run('array_stopping'));
        same('always on S1b: a plain release (stop, restart, update, removal) — «released» as before',
            [[fanboyTId(4)], [128, 1], [], 'bios', 'released', 'ended', true], $run());
        same('always on S1b: any other word is no reason — «released»', 'released', $run('whatever')[4]);
        same('always on S1b: the guard\'s note in RAM keeps its shape (what: released)', 'released', readJson(fanboyRunDir() . '/guard.json')['what'] ?? null);
        // the words for it are there in all five languages (his page and his tile said them before only when the control saw the stop first)
        foreach (['en', 'de', 'it', 'fr', 'es'] as $lang) {
            $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$lang.json"), true) ?: [];
            check("always on S1b: $lang has the tile's and the page's words for it", isset($j['dash.why.array_stopped'], $j['why.array_stopped'], $j['control.array_stopped']));
        }
        $sh = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh');
        check('always on S1b: agent.sh — array_event names the reason, fanboy_release passes it on, and only that one',
            str_contains($sh, "        local STOP_WHY=array_stopping   #") && str_contains($sh, '[[ "${STOP_WHY:-}" == array_stopping ]] && why=(array_stopping)')
            && str_contains($sh, 'php "$DIR/agent/fanboy-guard.php" release "${why[@]}" </dev/null'));
    } finally {
        array_map('alwaysOnTEnd', $sleepers);
        fanboyTEnd();
    }
}
