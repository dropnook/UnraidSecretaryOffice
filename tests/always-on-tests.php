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

/** The journal's give-backs made $seconds older (the tests never wait the 10 s a look comes after) */
function alwaysOnTAgeGiven(int $seconds): void
{
    fanboyLocked(function (array $j) use ($seconds): array {
        foreach ($j['outputs'] as $id => $o) {
            if (is_array($o['given'] ?? null) && is_int($o['given']['at'] ?? null)) {
                $j['outputs'][$id]['given']['at'] -= $seconds;
            }
        }
        return [$j, null];
    });
}

/**
 * Step S1c: after a release nobody asked «did the mainboard take the fans back?» — the agent is gone (the array's stop) and
 * the guard's cron line looks only while the journal names a holder. `fanboy-guard.php check-back` (agent.sh's
 * fanboy_release starts it in a session of its own) waits for the moment and looks once: a fan clearly slower than before
 * Fanboy and not rising goes to full speed; the agent tells it at its next start, once.
 */
function testAlwaysOnBackCheck(): void
{
    $t = fanboyT('ao-back');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        unset($settings['fans'][fanboyTId(4)]['back']);
        fanboyTStore($settings);
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        // a control steers the disk fan, then everything is released (the array's stop); the mainboard's answer: $after
        $case = function (array $after, int $age) use ($t, $settings, $data): array {
            @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
            @unlink(fanboyRunDir() . '/told.json');
            file_put_contents("{$t['hw']}/pwm4", "30\n");             // the mainboard's own curve, its register low
            file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
            $before = fanboyTSpec(10, [4 => ['auto' => 1100]]);
            fanboyTSettle($t, $before, 20);
            // the holder: a process of the test's that only waits (the release asks the holder to end — never the suite itself)
            $sleeper = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $spid = proc_get_status($sleeper)['pid'];
            fanboyBeat($spid);
            $c = fanboyControlNew($settings, FANBOY_T_KEY, ['kind' => 'control', 'pid' => $spid, 'start' => fanboyProcStart($spid), 'since' => time()]);
            $now = 1000.0;
            fanboyTRounds($c, $now, $t, $before, 10);
            $rel = fanboyTGuard($t, $data, 'release', 'array_stopping');
            alwaysOnTEnd($sleeper);
            fanboyTSettle($t, $after, 12);
            alwaysOnTAgeGiven($age);
            $t0 = hrtime(true);
            $out = fanboyTGuard($t, $data, 'check-back');
            $o = fanboyJournal()['outputs'][fanboyTId(4)];
            return ['released' => str_contains($rel, 'released — '), 'out' => fanboyTOut($t, 4), 'checked' => isset($o['given']['checked']), 'full' => $o['given']['full']['before'] ?? null,
                    'said' => str_contains($out, 'not taken back by the mainboard'), 'secs' => (hrtime(true) - $t0) / 1e9, 'log' => is_file("$data/agent.log") ? (string) file_get_contents("$data/agent.log") : ''];
        };
        $r = $case(fanboyTSpec(10, [4 => ['auto' => 1100]]), FANBOY_BACK_CHECK + 1);
        same('always on S1c: the mainboard took the fan back — looked at once, left as given back (30, automatic), nothing said',
            [true, [30, 2], true, null, false], [$r['released'], $r['out'], $r['checked'], $r['full'], $r['said']]);
        $n0 = count(fanboyTNotified($t));
        $r = $case(fanboyTSpec(10, [4 => ['auto' => 1100, 'auto_dead' => true]]), FANBOY_BACK_CHECK + 1);
        same('always on S1c: a chip that ignores its own mode — after the release the fan stops: the guard\'s look sets it to full speed, fixed, noted in the journal, a line for agent.sh\'s log',
            [true, [255, 1], true, 1100, true], [$r['released'], $r['out'], $r['checked'], $r['full'], $r['said']]);
        check('always on S1c: … the look writes nothing into the data folder (the array may be unmounting): no line of it in agent.log', !str_contains($r['log'], 'not taken back'));
        same('always on S1c: … the guard itself tells nobody', $n0, count(fanboyTNotified($t)));
        // the agent at its next start: told once (an alert naming the fan), in its log; the next ticks say nothing more
        fanboyBackCheck();
        fanboyBackCheck();
        $alerts = array_values(array_filter(array_slice(fanboyTNotified($t), $n0), fn ($l) => str_contains($l, '-i alert')));
        check('always on S1c: … the agent tells it at its next start — one alert naming the fan (' . ($alerts[0] ?? '') . ')', count($alerts) === 1 && str_contains($alerts[0], "didn't take Cage back"));
        check('always on S1c: … and says in its log that the guard found it', str_contains((string) @file_get_contents(AGENT_LOG), 'the guard set it to full speed while the agent was away'));
        same('always on S1c: … the page\'s state: at full for safety, with the way back', ['not_back'], array_values(array_column(fanboySafetyFull(), 'why')));
        // too early by 2 s: it waits for the moment, then looks (never a look that says nothing because it came too soon)
        $r = $case(fanboyTSpec(10, [4 => ['auto' => 1100, 'auto_dead' => true]]), FANBOY_BACK_CHECK - 2);
        check('always on S1c: 2 s before the moment — it waits, then looks: full speed (' . round($r['secs'], 1) . ' s)', $r['out'] === [255, 1] && $r['checked'] && $r['secs'] >= 1.0 && $r['secs'] < 15);
        // nothing given back (or all looked at already): ends at once, changes nothing
        $t0 = hrtime(true);
        $out = fanboyTGuard($t, $data, 'check-back');
        check('always on S1c: nothing to look at — it ends at once without a word', trim($out) === '' && (hrtime(true) - $t0) / 1e9 < 3 && fanboyTOut($t, 4) === [255, 1]);
        @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
        same('always on S1c: no journal — nothing', '', trim(fanboyTGuard($t, $data, 'check-back')));
        // an output somebody holds again (the control took it back after an update's restart) is never touched
        $sleeper = alwaysOnTHold($t);
        alwaysOnTAgeGiven(60);
        fanboyTGuard($t, $data, 'check-back');
        same('always on S1c: an output held again (a restart after an update) — left alone', [[250, 1], [fanboyTId(4)]], [fanboyTOut($t, 4), fanboyHeld()]);
        alwaysOnTEnd($sleeper);
        // agent.sh: every release ends with the look, in a session of its own, never waited for
        $sh = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh');
        check('always on S1c: agent.sh — fanboy_release ends with fanboy_back_check: its own session, cwd /, not waited for, bounded',
            (bool) preg_match('/^fanboy_release\(\) \{\n(?:.*\n)*?    timeout -k 1 15 php "\$DIR\/agent\/fanboy-guard\.php" release "\$\{why\[@\]\}" <\/dev\/null >>"\$LOG" 2>&1\n    fanboy_back_check\n    return 0\n\}/m', $sh)
            && str_contains($sh, '( cd / && setsid "${CLEAN_ENV[@]}" timeout -k 1 60 php "$DIR/agent/fanboy-guard.php" check-back </dev/null >>"$LOG" 2>&1 & )'));
        check('always on S1c: the guard\'s check-back never writes the agent\'s log (no logLine in it)',
            (bool) preg_match('/function fanboyGuardCheckBack\(\): array\n\{(?:(?!\n\}\n).)*\n\}\n/s', (string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php'), $m) && !str_contains($m[0], 'logLine('));
    } finally {
        fanboyTEnd();
    }
}

/**
 * Step S1d: a scheduled run that doesn't start because the array isn't started leaves a trace — job.sh's mark in RAM
 * (first time, last time, how often) and one line in the syslog at the first one; the agent takes the marks at its next
 * start and says them in its log. Snapshots leave none (caught up at the start), nor the watch.
 */
function testAlwaysOnMissedRuns(): void
{
    $tmp = hardeningTmp('ao-missed');
    @mkdir("$tmp/plugin/scripts", 0700, true);
    @mkdir("$tmp/run", 0700, true);
    $script = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/job.sh');
    $script = str_replace(['DIR=/usr/local/emhttp/plugins/unraid-secretary-office', 'RUN=/var/run/unraid-secretary-office', 'logger -t unraid-secretary-office'],
                          ["DIR=$tmp/plugin", "RUN=$tmp/run", "echo >> $tmp/syslog"], $script, $n);
    same('always on S1d: the copy of job.sh points to the test folder', 4, $n);
    file_put_contents("$tmp/job.sh", $script);
    // agent.sh's stand-in: the array as the file says; a job that would start says so instead (the copy's php is never reached)
    file_put_contents("$tmp/plugin/scripts/agent.sh", "array_started() { [[ -e $tmp/started ]]; }\n");
    $run = function (string $job) use ($tmp): int {
        @unlink("$tmp/run/job-$job.minute");            // each start its own minute (the guard against a second start is testJobGuard's)
        exec('PATH=' . escapeshellarg("$tmp/bin:" . getenv('PATH')) . ' bash ' . escapeshellarg("$tmp/job.sh") . ' ' . escapeshellarg($job) . ' >/dev/null 2>&1', $o, $code);
        return $code;
    };
    // php, bash's backup.sh: stand-ins that only note they ran
    @mkdir("$tmp/bin", 0700, true);
    file_put_contents("$tmp/bin/php", "#!/bin/bash\necho \"php \$*\" >> $tmp/ran\nexit 0\n");
    chmod("$tmp/bin/php", 0755);
    $codes = [];
    foreach (['backup', 'backup', 'backup', 'moverelli', 'snapshots', 'cleanup'] as $job) {
        $codes[] = $run($job);
    }
    $mark = fn (string $job) => is_file("$tmp/run/missed-$job") ? trim((string) file_get_contents("$tmp/run/missed-$job")) : null;
    check('always on S1d: the array not started — nothing starts, every start ends with 0', $codes === [0, 0, 0, 0, 0, 0] && !is_file("$tmp/ran"));
    check('always on S1d: a mark per job: first time, last time, how often (backup 3, moverelli 1, cleanup 1)',
        (bool) preg_match('/^(\d{10}) (\d{10}) 3$/D', (string) $mark('backup'), $m) && $m[1] <= $m[2] && abs((int) $m[2] - time()) < 60
        && (bool) preg_match('/^\d{10} \d{10} 1$/D', (string) $mark('moverelli')) && (bool) preg_match('/^\d{10} \d{10} 1$/D', (string) $mark('cleanup')), json_encode([$mark('backup'), $mark('moverelli'), $mark('cleanup')]));
    same('always on S1d: none for the snapshots (caught up at the start)', null, $mark('snapshots'));
    same('always on S1d: one line in the syslog per job, at its first one only',
        ["job backup: not started - the array isn't started", "job moverelli: not started - the array isn't started", "job cleanup: not started - the array isn't started"],
        array_values(array_filter(explode("\n", (string) @file_get_contents("$tmp/syslog")))));
    same('always on S1d: no temporary file left, nothing else written', ['missed-backup', 'missed-cleanup', 'missed-moverelli'],
        array_values(array_filter(scandir("$tmp/run") ?: [], fn ($f) => str_starts_with($f, 'missed-'))));
    // a mark that is a link is never written through
    file_put_contents("$tmp/elsewhere", "keep\n");
    @symlink("$tmp/elsewhere", "$tmp/run/missed-gather");
    $run('gather');
    same('always on S1d: a link in the mark\'s place — never written through', "keep\n", file_get_contents("$tmp/elsewhere"));
    @unlink("$tmp/run/missed-gather");
    // the array started: the job starts as ever, no mark made or touched
    touch("$tmp/started");
    $before = $mark('moverelli');
    $run('moverelli');
    same('always on S1d: the array started — the job starts (php agent.php job moverelli), its mark untouched',
        ["php $tmp/plugin/agent/agent.php job moverelli\n", $before], [@file_get_contents("$tmp/ran"), $mark('moverelli')]);

    // the agent's start: the marks taken (removed) and said in its log
    $lines = officeMissedSay("$tmp/run");
    check('always on S1d: the agent says each at its start — which run, how often, from when to when', count($lines) === 3
        && (bool) preg_match('/^Office: the scheduled backup run did not start while the array wasn\'t started \(3 times, \d{4}-\d\d-\d\d \d\d:\d\d to \d{4}-\d\d-\d\d \d\d:\d\d\)$/D', $lines[0])
        && (bool) preg_match('/^Office: Ms\. Moverelli\'s scheduled run did not start while the array wasn\'t started \(at \d{4}-\d\d-\d\d \d\d:\d\d\)$/D', $lines[1])
        && str_starts_with($lines[2], 'Office: Ms. Dustdevil\'s scheduled tour did not start'), json_encode($lines));
    same('always on S1d: … the marks are gone, a second start says nothing', [[], []],
        [array_values(array_filter(scandir("$tmp/run") ?: [], fn ($f) => str_starts_with($f, 'missed-'))), officeMissedSay("$tmp/run")]);
    // only a mark in exactly its shape counts; anything else is removed unread (a link never followed)
    file_put_contents("$tmp/run/missed-backup", "1 2 x\n");
    file_put_contents("$tmp/run/missed-logs", str_repeat('9', 200));
    file_put_contents("$tmp/run/missed-gather", "200 100 1\n");
    @symlink("$tmp/elsewhere", "$tmp/run/missed-cleanup");
    file_put_contents("$tmp/run/missed-other", "1 2 3\n");
    same('always on S1d: marks not in their shape (words, too long, last before first, a link) say nothing and go; a name job.sh never writes stays untouched',
        [[], ['missed-other'], "keep\n"], [officeMissedTake("$tmp/run"), array_values(array_filter(scandir("$tmp/run") ?: [], fn ($f) => str_starts_with($f, 'missed-'))), file_get_contents("$tmp/elsewhere")]);
    // every job job.sh marks has its words; the RAM folder's audit knows the marks
    preg_match('/^job_missed\(\) \{.*?^        ([a-z|]+)\) ;;$/ms', (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/job.sh'), $m);
    same('always on S1d: job.sh marks exactly the jobs the agent has words for', array_keys(MISSED_JOBS), explode('|', $m[1] ?? ''));
    check('always on S1d: the marks are in the RAM folder\'s audit (RUNDIR_FILES)', (bool) array_filter(array_keys(RUNDIR_FILES), fn ($p) => preg_match('#^(?:' . $p . ')$#D', 'missed-backup') === 1 && preg_match('#^(?:' . $p . ')$#D', 'missed-backup.tmp') === 1));
    check('always on S1d: the agent takes them at its start (setUp())', str_contains((string) file_get_contents(OFFICE_DIR . '/agent/agent.php'), "    officeMissedSay();"));
    hardeningRm($tmp);
}
