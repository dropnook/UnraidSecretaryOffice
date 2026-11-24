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
        // (since step S6a agent.sh no longer releases the control at the array's stop — it calls release-steps there,
        // testAlwaysOnThroughStop; the guard still takes the reason: an older agent.sh in memory at an update)
        check('always on S1b: agent.sh — array_event names the reason for everything release() calls', str_contains($sh, "        local STOP_WHY=array_stopping   #"));
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
            (bool) preg_match('/^fanboy_release\(\) \{\n(?:.*\n)*?    timeout -k 1 15 php "\$DIR\/agent\/fanboy-guard\.php" "\$cmd" <\/dev\/null >>"\$LOG" 2>&1\n    fanboy_back_check\n    return 0\n\}/m', $sh)
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

// ===================================================================== step S2: «holds nothing» (agent/lib/holdsnothing.php)

/** A mount table as an Unraid server shows it (invented): RAM, the flash and its loop images, array, pools, shares, images on a pool */
function alwaysOnTMountinfo(): string
{
    return implode("\n", [
        '22 1 0:2 / / rw - rootfs rootfs rw,size=16000000k',
        '23 22 0:22 / /proc rw,relatime - proc proc rw',
        '24 22 0:23 / /sys rw,relatime - sysfs sysfs rw',
        '25 22 0:5 / /dev rw,relatime - devtmpfs devtmpfs rw',
        '28 22 0:26 / /run rw,nosuid shared:5 - tmpfs tmpfs rw,size=131072k',
        '30 22 8:1 / /boot rw,noatime shared:6 - vfat /dev/sda1 rw',
        '31 22 7:0 / /usr ro - squashfs /dev/loop0 ro',
        '32 22 7:1 / /lib ro - squashfs /dev/loop1 ro',
        '40 22 0:30 / /mnt rw shared:10 - tmpfs tmpfs rw,size=1024k',
        '41 40 0:31 / /mnt/disks rw shared:11 - tmpfs tmpfs rw,size=1024k',
        '42 40 0:32 / /mnt/remotes rw shared:12 - tmpfs tmpfs rw,size=1024k',
        '43 40 0:33 / /mnt/addons rw shared:13 - tmpfs tmpfs rw,size=1024k',
        '50 40 9:1 / /mnt/disk1 rw,noatime shared:20 - xfs /dev/md1p1 rw',
        '51 40 0:40 / /mnt/cache rw,noatime shared:21 - btrfs /dev/nvme0n1p1 rw',
        '52 40 0:45 / /mnt/tank rw shared:22 - zfs tank rw',
        '53 52 0:46 / /mnt/tank/appdata rw shared:23 - zfs tank/appdata rw',
        '54 40 0:50 / /mnt/user rw,nosuid shared:24 - fuse.shfs shfs rw',
        '55 40 0:51 / /mnt/user0 rw,nosuid shared:25 - fuse.shfs shfs rw',
        '60 22 7:2 / /var/lib/docker rw,noatime shared:30 - btrfs /dev/loop2 rw',
        '61 22 7:3 / /etc/libvirt rw,noatime shared:31 - btrfs /dev/loop3 rw',
        '62 43 0:60 / /mnt/addons/UnraidSecretaryOffice/snapread/p1 ro shared:32 - zfs tank/appdata@uso-read ro',
        '63 41 8:33 / /mnt/disks/My\\040Disk rw shared:33 - ntfs3 /dev/sdc1 rw',
        'a broken line',
        '',
    ]);
}

/** A fake /proc and /sys under $tmp: the mount table for $pids, init's namespace, the loop devices' files */
function alwaysOnTProc(string $tmp, array $procs): array
{
    $proc = "$tmp/proc";
    $sys = "$tmp/sys";
    foreach (['loop0' => '/boot/bzfirmware', 'loop1' => '/boot/bzmodules', 'loop2' => '/mnt/cache/system/docker/docker.img', 'loop3' => '/mnt/user/system/libvirt/libvirt.img'] as $l => $file) {
        @mkdir("$sys/block/$l/loop", 0700, true);
        file_put_contents("$sys/block/$l/loop/backing_file", "$file\n");
    }
    @mkdir("$proc/1/ns", 0700, true);
    @symlink('mnt:[4026531841]', "$proc/1/ns/mnt");
    file_put_contents("$proc/1/stat", "1 (init) S 0 1 1 0 -1 4194560 0 0\n");
    foreach ($procs as $pid => $p) {
        $p += ['ns' => 'mnt:[4026531841]', 'cwd' => '/', 'root' => '/', 'fds' => [0 => '/dev/null', 1 => '/dev/null', 2 => '/dev/null'], 'maps' => [], 'ppid' => 1, 'name' => 'php', 'mountinfo' => alwaysOnTMountinfo()];
        @mkdir("$proc/$pid/ns", 0700, true);
        @mkdir("$proc/$pid/fd", 0700, true);
        if ($p['ns'] !== null) {
            @symlink($p['ns'], "$proc/$pid/ns/mnt");
        }
        foreach (['cwd', 'root'] as $k) {
            if ($p[$k] !== null) {
                @symlink($p[$k], "$proc/$pid/$k");
            }
        }
        foreach ($p['fds'] as $n => $target) {
            @symlink($target, "$proc/$pid/fd/$n");
        }
        $maps = "55d0c0000000-55d0c0200000 r--p 00000000 07:00 1234                       /usr/bin/php\n7ffc00000000-7ffc00021000 rw-p 00000000 00:00 0                          [stack]\n";
        foreach ($p['maps'] as $m) {
            $maps .= "7f0000000000-7f0000100000 r--s 00000000 {$m[0]} 77                         {$m[1]}\n";
        }
        if ($p['maps'] !== null) {
            file_put_contents("$proc/$pid/maps", $maps);
        }
        if ($p['mountinfo'] !== null) {
            file_put_contents("$proc/$pid/mountinfo", $p['mountinfo']);
        }
        file_put_contents("$proc/$pid/stat", "$pid ({$p['name']}) S {$p['ppid']} $pid $pid 0 -1 4194560 0 0\n");
    }
    return [$proc, $sys];
}

/**
 * Step S2: what must not be held — from the mount table and the loop devices, never from a path's first letters alone:
 * array disks, pools, datasets, the shares, a snapshot mounted under /mnt/addons, a disk outside array and pools, and the
 * loop mounts of images that lie on a pool (/etc/libvirt, /var/lib/docker); never RAM under /mnt, the flash or its images.
 */
function testAlwaysOnHoldBarred(): void
{
    $tmp = hardeningTmp('ao-barred');
    [$proc, $sys] = alwaysOnTProc($tmp, [100 => []]);
    $b = officeHoldBarred(100, $proc, $sys);
    same('always on S2: barred mounts — array, pools, datasets, shares, a mounted snapshot, a disk outside (its name with a space), the images on a pool',
        ['/mnt/disk1' => '9:1', '/mnt/cache' => '0:40', '/mnt/tank' => '0:45', '/mnt/tank/appdata' => '0:46', '/mnt/user' => '0:50', '/mnt/user0' => '0:51',
         '/var/lib/docker' => '7:2', '/etc/libvirt' => '7:3', '/mnt/addons/UnraidSecretaryOffice/snapread/p1' => '0:60', '/mnt/disks/My Disk' => '8:33'], $b['mounts'] ?? null);
    same('always on S2: … their devices', ['9:1', '0:40', '0:45', '0:46', '0:50', '0:51', '7:2', '7:3', '0:60', '8:33'], array_map('strval', array_keys($b['devs'] ?? [])));
    check('always on S2: never barred — the root, /run, the flash, its loop images (/usr, /lib), /mnt itself and RAM folders under it (/mnt/addons, /mnt/disks)',
        !array_intersect(['/', '/run', '/boot', '/usr', '/lib', '/mnt', '/mnt/disks', '/mnt/remotes', '/mnt/addons', '/proc', '/sys', '/dev'], array_keys($b['mounts'] ?? [])));
    same('always on S2: no mount table (the process gone, no /proc) — no list, never an empty one', [null, null], [officeHoldBarred(999, $proc, $sys), officeHoldBarred(100, "$tmp/nowhere", $sys)]);
    // a loop device whose file can't be read is not barred by guess; the same device mounted under /mnt is
    @unlink("$sys/block/loop3/loop/backing_file");
    $b = officeHoldBarred(100, $proc, $sys);
    check('always on S2: a loop device without a readable file is not barred by guess (the flash\'s are loops too)', !isset($b['mounts']['/etc/libvirt']) && isset($b['mounts']['/var/lib/docker']));
    same('always on S2: the device number as the mount table writes it, from stat()\'s (small and large numbers)',
        ['8:1', '0:45', '259:3', '7:2', '8:65537', '4660:5'], array_map('officeHoldDev', [(8 << 8) | 1, 45, (259 << 8) | 3, (7 << 8) | 2, (8 << 8) | 1 | (0x10000 << 12), (0x234 << 8) | 5 | (0x1000 << 32)]));
    hardeningRm($tmp);
}

/** Step S2: a look judged — a process's namespace, cwd, root, open files and mapped files against what is barred (pure) */
function testAlwaysOnHoldJudge(): void
{
    $tmp = hardeningTmp('ao-judge');
    [$proc, $sys] = alwaysOnTProc($tmp, [100 => []]);
    $barred = officeHoldBarred(100, $proc, $sys);
    $clean = ['ns' => 'mnt:[1]', 'ns1' => 'mnt:[1]', 'cwd' => ['path' => '/', 'dev' => '0:2'], 'root' => ['path' => '/', 'dev' => '0:2'],
        'files' => [['fd' => 0, 'path' => '/dev/null', 'dev' => '0:5'], ['fd' => 3, 'path' => '/run/unraid-secretary-office/fanboy/control.lock', 'dev' => '0:26'],
                    ['fd' => 4, 'path' => '/mnt/addons/UnraidSecretaryOffice/metrics/office.prom', 'dev' => '0:33'], ['fd' => 5, 'path' => '/boot/config/plugins/x.cfg', 'dev' => '8:1']],
        'maps' => [['path' => '/usr/bin/php', 'dev' => '7:0'], ['path' => '/lib64/libc.so.6', 'dev' => '7:1']], 'ok' => true];
    same('always on S2: cwd /, files in RAM (also RAM under /mnt/addons), on the flash, code from the flash\'s images — holds nothing', null, officeHoldJudge($clean, $barred));
    $with = fn (array $x) => officeHoldJudge(array_replace($clean, $x), $barred);
    $short = fn (?array $f) => $f === null ? null : [$f['why'], $f['what'], $f['mount']];
    same('always on S2: a mount namespace of its own — held, whatever else it says (2026-11-22: cwd /, no file open, the stop hung)',
        ['namespace', 'mnt:[2] ≠ mnt:[1]', null], $short($with(['ns' => 'mnt:[2]'])));
    same('always on S2: the working directory on a pool', ['cwd', '/mnt/cache/appdata', '/mnt/cache'], $short($with(['cwd' => ['path' => '/mnt/cache/appdata', 'dev' => '0:40']])));
    same('always on S2: … through the share', ['cwd', '/mnt/user/appdata/UnraidSecretaryOffice/data', '/mnt/user'], $short($with(['cwd' => ['path' => '/mnt/user/appdata/UnraidSecretaryOffice/data', 'dev' => '0:50']])));
    same('always on S2: a root on a pool (chroot)', ['root', '/mnt/tank/jail', '/mnt/tank'], $short($with(['root' => ['path' => '/mnt/tank/jail', 'dev' => '0:45']])));
    same('always on S2: an open file on an array disk', ['file', '/mnt/disk1/x.log', '/mnt/disk1'], $short($with(['files' => [['fd' => 7, 'path' => '/mnt/disk1/x.log', 'dev' => '9:1']]])));
    same('always on S2: … in a dataset: the deepest mount is named', ['file', '/mnt/tank/appdata/a/b.json', '/mnt/tank/appdata'], $short($with(['files' => [['fd' => 7, 'path' => '/mnt/tank/appdata/a/b.json', 'dev' => '0:46']]])));
    same('always on S2: an open file in /etc/libvirt — an image on a pool behind it', ['file', '/etc/libvirt/qemu/vm.xml', '/etc/libvirt'], $short($with(['files' => [['fd' => 7, 'path' => '/etc/libvirt/qemu/vm.xml', 'dev' => '7:3']]])));
    same('always on S2: a btrfs file whose device is not its mount\'s (a subvolume) — found by its path', ['file', '/mnt/cache/sub/x', '/mnt/cache'], $short($with(['files' => [['fd' => 7, 'path' => '/mnt/cache/sub/x', 'dev' => '0:99']]])));
    same('always on S2: a file seen under another path (a bind mount) — found by its device', ['file', '/srv/elsewhere/x', '/mnt/disk1'], $short($with(['files' => [['fd' => 7, 'path' => '/srv/elsewhere/x', 'dev' => '9:1']]])));
    same('always on S2: a deleted file still open on a pool — held', ['file', '/mnt/cache/gone.tmp', '/mnt/cache'],
        $short(officeHoldJudge(['files' => [['fd' => 7, 'path' => '/mnt/cache/gone.tmp', 'dev' => null]]] + $clean, $barred)));
    same('always on S2: a mapped file on a pool', ['map', '/mnt/user/appdata/x/lib.so', '/mnt/user'], $short($with(['maps' => [['path' => '/mnt/user/appdata/x/lib.so', 'dev' => '0:50']]])));
    same('always on S2: names that only begin alike are not on the mount (/mnt/cache2 is not /mnt/cache, /mnt/disk10 not /mnt/disk1)', null,
        $with(['files' => [['fd' => 7, 'path' => '/mnt/cache2/x', 'dev' => '0:77'], ['fd' => 8, 'path' => '/mnt/disk10/x', 'dev' => '0:78'], ['fd' => 9, 'path' => '/mnt/userdata', 'dev' => '0:30']]]));
    // a look that could not be made is never «fine»
    same('always on S2: no list of what is barred — «no look», never fine', ['no_look', 'mountinfo', null], $short(officeHoldJudge($clean, null)));
    same('always on S2: the namespace not readable — «no look»', ['no_look', 'ns/mnt', null], $short($with(['ns1' => null])));
    same('always on S2: a part of /proc not readable — «no look»', ['no_look', 'proc', null], $short($with(['ok' => false])));
    check('always on S2: each finding has its line for a log', str_contains(officeHoldSay(['why' => 'file', 'what' => '/mnt/disk1/x', 'mount' => '/mnt/disk1', 'pid' => 7]), 'an open file (PID 7): /mnt/disk1/x on /mnt/disk1')
        && str_contains(officeHoldSay(['why' => 'namespace', 'what' => 'a ≠ b', 'mount' => null]), 'a mount namespace of its own: a ≠ b') && str_contains(officeHoldSay(['why' => 'no_look', 'what' => 'proc', 'mount' => null]), 'the look itself failed (proc)'));
    hardeningRm($tmp);
}

/** Step S2: the look itself, from a /proc of the test's — and a process with everything it started */
function testAlwaysOnHoldLook(): void
{
    $tmp = hardeningTmp('ao-look');
    [$proc, $sys] = alwaysOnTProc($tmp, [
        100 => ['fds' => [0 => '/dev/null', 1 => '/dev/null', 2 => '/dev/null', 3 => '/run/unraid-secretary-office/fanboy/control.lock', 4 => 'pipe:[5551]', 5 => 'socket:[77]', 6 => 'anon_inode:[eventpoll]']],
        101 => ['ppid' => 100, 'name' => 'sh) S 1 (x'],                // its name can hold anything: the parent is read after the last «)»
        102 => ['ppid' => 101, 'cwd' => '/mnt/cache/appdata/x'],
        200 => ['cwd' => '/mnt/user/appdata'],
        201 => ['fds' => [0 => '/dev/null', 9 => '/mnt/disk1/share/file.bin']],
        202 => ['fds' => [0 => '/dev/null', 9 => '/mnt/cache/tmp/x (deleted)']],
        203 => ['maps' => [['00:32', '/mnt/user/appdata/x/lib.so'], ['00:1a', '/run/x.shm']]],
        204 => ['ns' => 'mnt:[4026532999]'],
        205 => ['fds' => [0 => '/dev/null', 9 => '/etc/libvirt/qemu/vm.xml']],
        206 => ['ns' => null],
        207 => ['mountinfo' => null],
        208 => ['root' => '/mnt/tank/jail'],
        300 => ['ppid' => 1], 301 => ['ppid' => 300], 302 => ['ppid' => 301, 'fds' => [0 => '/dev/null', 5 => '/mnt/tank/appdata/held']],
    ]);
    $look = officeHoldLook(100, $proc);
    same('always on S2: the look — its namespace and init\'s, cwd, root, only what has a path among its open files (no pipe, socket, anonymous), its mapped files',
        ['mnt:[4026531841]', 'mnt:[4026531841]', '/', '/', ['/dev/null', '/dev/null', '/dev/null', '/run/unraid-secretary-office/fanboy/control.lock'], ['/usr/bin/php'], true],
        [$look['ns'], $look['ns1'], $look['cwd']['path'] ?? null, $look['root']['path'] ?? null, array_column($look['files'], 'path'), array_column($look['maps'], 'path'), $look['ok']]);
    same('always on S2: a mapped file\'s device, hex in /proc/<pid>/maps, as the mount table writes it', ['0:50', '0:26'], array_column(officeHoldLook(203, $proc)['maps'], 'dev', 'path') === [] ? [] :
        array_values(array_intersect_key(array_column(officeHoldLook(203, $proc)['maps'], 'dev', 'path'), ['/mnt/user/appdata/x/lib.so' => 1, '/run/x.shm' => 1])));
    same('always on S2: a process and what it started (child, grandchild) — the tree; another tree stays out', [[100, 101, 102], [300, 301, 302], [200]],
        [officeHoldTree(100, $proc), officeHoldTree(300, $proc), officeHoldTree(200, $proc)]);
    $short = fn (?array $f) => $f === null ? null : [$f['why'], $f['what'], $f['mount'], $f['pid']];
    $got = [];
    foreach ([200, 201, 202, 203, 204, 205, 206, 207, 208] as $pid) {
        $got[$pid] = $short(officeHoldsNothing($pid, $proc, $sys));
    }
    same('always on S2: each way to hold a pool is found and named — cwd on the share, a file on an array disk, a deleted file still open, a mapped file, a namespace of its own, a file in /etc/libvirt; a namespace or mount table that can\'t be read is «no look»; a root on a pool',
        [200 => ['cwd', '/mnt/user/appdata', '/mnt/user', 200], 201 => ['file', '/mnt/disk1/share/file.bin', '/mnt/disk1', 201], 202 => ['file', '/mnt/cache/tmp/x', '/mnt/cache', 202],
         203 => ['map', '/mnt/user/appdata/x/lib.so', '/mnt/user', 203], 204 => ['namespace', 'mnt:[4026532999] ≠ mnt:[4026531841]', null, 204],
         205 => ['file', '/etc/libvirt/qemu/vm.xml', '/etc/libvirt', 205], 206 => ['no_look', 'ns/mnt', null, 206], 207 => ['no_look', 'mountinfo', null, 207],
         208 => ['root', '/mnt/tank/jail', '/mnt/tank', 208]], $got);
    same('always on S2: a clean process whose grandchild sits on a pool — held, the grandchild named', ['cwd', '/mnt/cache/appdata/x', '/mnt/cache', 102], $short(officeHoldsNothing(100, $proc, $sys)));
    same('always on S2: … and one whose great-grandchild holds a file in a dataset', ['file', '/mnt/tank/appdata/held', '/mnt/tank/appdata', 302], $short(officeHoldsNothing(300, $proc, $sys)));
    exec('rm -rf ' . escapeshellarg("$proc/102"));
    same('always on S2: the grandchild gone — the process holds nothing', null, officeHoldsNothing(100, $proc, $sys));
    same('always on S2: a process that isn\'t there — «no look», never fine', 'no_look', officeHoldsNothing(999, $proc, $sys)['why'] ?? null);
    // for real, where there is a /proc (never on the Mac): this test process. On the server the suite runs in a mount
    // namespace of its own (the guard of the live RAM folder, tests/run.php) — exactly what the look must find
    if (is_link('/proc/self/ns/mnt') && is_link('/proc/1/ns/mnt') && @readlink('/proc/1/ns/mnt') !== false) {
        $real = officeHoldsNothing();
        $own = readlink('/proc/self/ns/mnt') !== readlink('/proc/1/ns/mnt');
        check('always on S2: for real — this process ' . ($own ? 'runs in a mount namespace of its own: found' : 'in init\'s namespace: nothing, or what it really holds, by name')
            . ' (' . ($real === null ? 'holds nothing' : officeHoldSay($real)) . ')',
            $own ? ($real['why'] ?? null) === 'namespace' : ($real === null || in_array($real['why'], ['cwd', 'file', 'map'], true)));
        $b = officeHoldBarred();
        check('always on S2: for real — the mount table is read, and nothing barred lies outside /mnt but loop mounts', is_array($b)
            && !array_filter(array_keys($b['mounts']), fn ($m) => !str_starts_with((string) $m, '/mnt/') && !in_array((string) $m, ['/var/lib/docker', '/etc/libvirt'], true) && !str_starts_with((string) $m, '/var/lib/docker/')), json_encode($b));
    }
    check('always on S2: the library needs nothing of the agent (the guard and the control load it alone)',
        !preg_match('/\b(logLine|readJson|writeAtomic|RUN_DIR|DATA_DIR|run\()\b/', (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(OFFICE_DIR . '/agent/lib/holdsnothing.php'))));
    hardeningRm($tmp);
}

// ===================================================================== step S3: Fanboy's mirror in RAM (agent/desks/fanboy-mirror.php)

/** Fanboy hired or not for the time of a test: his desk there, a staff list of the test's; returns what to put back */
function alwaysOnTHire(array $t, bool $hired): array
{
    $keep = ['desk' => $GLOBALS['desks']['fanboy'] ?? null, 'staff' => $GLOBALS['agentStaffFile'] ?? null];
    $GLOBALS['desks']['fanboy'] ??= ['actions' => [], 'start' => null, 'tick' => null, 'checks' => null, 'fit' => null, 'metrics' => null];
    file_put_contents("{$t['tmp']}/staff.json", json_encode(['hired' => $hired ? ['fanboy' => ['at' => 1]] : new stdClass()]));
    $GLOBALS['agentStaffFile'] = "{$t['tmp']}/staff.json";
    return $keep;
}

function alwaysOnTHireEnd(array $keep): void
{
    if ($keep['desk'] === null) {
        unset($GLOBALS['desks']['fanboy']);
    }
    if ($keep['staff'] === null) {
        unset($GLOBALS['agentStaffFile']);
    } else {
        $GLOBALS['agentStaffFile'] = $keep['staff'];
    }
    unset($GLOBALS['fanboyMirrorSeen']);
}

/**
 * Step S3: his mirror in RAM says what the data folder says — written by the agent as a reconciliation (whoever changed a
 * source), read only in exactly its shape, for this board, its sum right. Nothing reads it yet.
 */
function testAlwaysOnMirror(): void
{
    $t = fanboyT('ao-mirror');
    $keep = alwaysOnTHire($t, false);
    try {
        unset($GLOBALS['fanboyMirrorSeen']);
        $file = fanboyMirrorFile();
        $ino = function () use ($file): ?int {
            clearstatcache(true, $file);
            return @lstat($file)['ino'] ?? null;
        };
        // nothing set up, not hired: a mirror all the same — it says exactly that
        same('always on S3: the first look writes it', 'written', fanboyMirrorKeep());
        $m = fanboyMirrorRead(FANBOY_T_KEY);
        same('always on S3: nothing set up, not hired — the mirror says so: this board, not hired, no settings, nothing kept from before him, the watch on (its default)',
            [1, FANBOY_T_KEY, false, true, [], true], [$m['v'] ?? null, $m['board'] ?? null, $m['hired'] ?? null, is_array($m) && array_key_exists('settings', $m) && $m['settings'] === null, $m['initial'] ?? null, $m['watch'] ?? null]);
        same('always on S3: in RAM, his folder, root only (0600), nothing temporary left', [fanboyRunDir() . '/mirror.json', 0600, []],
            [$file, fileperms($file) & 0777, array_values(array_filter(scandir(fanboyRunDir()) ?: [], fn ($f) => str_ends_with($f, '.tmp')))]);
        $first = $ino();
        same('always on S3: nothing changed — the same, and the file is not written again', ['same', 'same', $first], [fanboyMirrorKeep(), fanboyMirrorKeep(true), $ino()]);

        // the store written past the agent (a restore of appdata, a hand): the next look mirrors it
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        same('always on S3: the store changed behind the agent\'s back (a restore, a hand) — mirrored at the next look', 'written', fanboyMirrorKeep());
        $m = fanboyMirrorRead(FANBOY_T_KEY);
        same('always on S3: … the board\'s entry as it is written: what the control would make of it is the same from the mirror as from the store',
            [fanboyBoardRaw(), true, array_keys(fanboyControlNew(fanboyBoardRaw() ?? [], FANBOY_T_KEY, ['pid' => 0])['zones'])],
            [$m['settings'] ?? null, ($m['settings']['armed'] ?? null) === true, array_keys(fanboyControlNew($m['settings'] ?? [], FANBOY_T_KEY, ['pid' => 0])['zones'])]);
        // his own saves mirror at once (no tick in between)
        fanboyStoreUpdate(function (array $e): array {
            $e['armed'] = false;
            return $e;
        });
        same('always on S3: his own save («hand back») is in the mirror at once', false, fanboyMirrorRead(FANBOY_T_KEY)['settings']['armed'] ?? null);
        // what an output had before him, kept across boots (initial.json): mirrored too — the control asks it for a chip's automatic mode
        fanboyInitialKeep(FANBOY_T_KEY, fanboyTId(4), ['value' => 77, 'enable' => 2]);
        fanboyInitialKeep('Other Boards Inc. | X1', fanboyTId(4), ['value' => 1, 'enable' => 5]);
        $m = fanboyMirrorKeep() === 'written' ? fanboyMirrorRead(FANBOY_T_KEY) : null;
        same('always on S3: an output\'s state before him (kept across boots) — this board\'s in the mirror, another board\'s not',
            [[fanboyTId(4)], 77, 2], [array_keys($m['initial'] ?? []), $m['initial'][fanboyTId(4)]['value'] ?? null, $m['initial'][fanboyTId(4)]['enable'] ?? null]);
        // hired on the web side (it writes the staff list, never the agent): the next look
        alwaysOnTHire($t, true);
        same('always on S3: hired on the page (the web side writes the staff list) — mirrored at the next look', ['written', true], [fanboyMirrorKeep(), fanboyMirrorRead(FANBOY_T_KEY)['hired'] ?? null]);
        // the watch's switch
        @mkdir(FANBOY_DIR, 0700, true);
        file_put_contents(fanboyWatchFile(), json_encode(['v' => 1, 'on' => false]));
        same('always on S3: «watch fans I don\'t steer» switched off — mirrored', ['written', false], [fanboyMirrorKeep(), fanboyMirrorRead(FANBOY_T_KEY)['watch'] ?? null]);
        // another writer left an older mirror there (a setup step saving at the same moment): not this process's file — compared, written anew
        $good = (string) file_get_contents($file);
        $stale = json_decode($good, true);
        $stale['hired'] = false;
        $stale['sum'] = fanboyMirrorSum($stale);
        file_put_contents("$file.new", json_encode($stale));
        rename("$file.new", $file);
        same('always on S3: a mirror someone else wrote that says something older — seen (not this process\'s file), written anew', ['written', true], [fanboyMirrorKeep(), fanboyMirrorRead(FANBOY_T_KEY)['hired'] ?? null]);
        // the whole comparison once a minute, whatever the times say
        $seen = $GLOBALS['fanboyMirrorSeen'];
        same('always on S3: within the minute a quiet look reads nothing; after it the whole comparison (and still the same)',
            ['same', $seen['full'], 'same', true], [fanboyMirrorKeep(false, $seen['full'] + 30), $GLOBALS['fanboyMirrorSeen']['full'], fanboyMirrorKeep(false, $seen['full'] + FANBOY_MIRROR_FULL_EVERY + 1),
             $GLOBALS['fanboyMirrorSeen']['full'] > $seen['full']]);
        // while another holds the lock (2026-11-24): waited for a moment, then kept without it — nothing changed, nothing written
        $lock = fopen(fanboyRunDir() . '/mirror.lock', 'c');
        flock($lock, LOCK_EX);
        same('always on S3: the lock held by another writer — waited, then kept without it (the same: nothing written)', ['same', $ino()], [fanboyMirrorKeep(true), $ino()]);
        flock($lock, LOCK_UN);
        fclose($lock);

        // readers: only exactly its shape, this board, the sum right
        $good = (string) file_get_contents($file);
        $j = json_decode($good, true);
        $try = function (callable $change, ?string $board = FANBOY_T_KEY) use ($file, $j): ?array {
            $x = $change($j);
            file_put_contents($file, is_string($x) ? $x : json_encode($x));
            return fanboyMirrorRead($board);
        };
        $resum = function (array $x): array {
            $x['sum'] = fanboyMirrorSum($x);
            return $x;
        };
        check('always on S3: read — as written: yes; with a key it doesn\'t know (a newer agent\'s): yes, the rest as it is',
            $try(fn ($x) => $x) !== null && ($try(fn ($x) => $x + ['later' => ['x' => 1]])['hired'] ?? null) === true);
        same('always on S3: no mirror — another `v` (never migrated), another board\'s, a field of the wrong type, no settings key, a changed value (the sum), a cut file, an empty one, no JSON',
            array_fill(0, 10, null),
            [$try(fn ($x) => $resum(['v' => 2] + $x)), $try(fn ($x) => $x, 'Other Boards Inc. | X1'), $try(fn ($x) => $resum(['hired' => 'yes'] + $x)), $try(fn ($x) => $resum(['watch' => 1] + $x)), $try(fn ($x) => $resum(['initial' => null] + $x)),
             $try(function ($x) use ($resum) { unset($x['settings']); return $resum($x); }), $try(fn ($x) => ['hired' => false] + $x), $try(fn ($x) => substr((string) json_encode($x), 0, -40)), $try(fn ($x) => ''), $try(fn ($x) => 'not json')]);
        same('always on S3: … any board when the reader names none (the agent\'s own comparison)', FANBOY_T_KEY, $try(fn ($x) => $x, null)['board'] ?? null);
        file_put_contents("{$t['tmp']}/elsewhere.json", $good);
        @unlink($file);
        @symlink("{$t['tmp']}/elsewhere.json", $file);
        same('always on S3: a link in its place is no mirror — and the agent writes a real file there, never through the link', [null, 'written', false, $good],
            [fanboyMirrorRead(FANBOY_T_KEY), fanboyMirrorKeep(true), is_link($file), file_get_contents("{$t['tmp']}/elsewhere.json")]);
        // a mirror of the version before (the same `v`, a board entry without the keys added since): read, and the control makes of it what it made then
        $old = $settings;
        foreach ($old['zones'] as $i => $z) {
            unset($old['zones'][$i]['curves'], $old['zones'][$i]['kinds'], $old['zones'][$i]['react']);
        }
        $x = $resum(['settings' => $old] + $j);
        file_put_contents($file, json_encode($x));
        $read = fanboyMirrorRead(FANBOY_T_KEY);
        same('always on S3: a mirror whose board entry lacks keys added since (an update with the array stopped) — read, its zones and fans as the control takes them',
            [['disks', 'case'], 3], [array_keys(fanboyControlNew($read['settings'] ?? [], FANBOY_T_KEY, ['pid' => 0])['zones']), count(fanboyControlNew($read['settings'] ?? [], FANBOY_T_KEY, ['pid' => 0])['fans'])]);

        // too large to mirror: no mirror (never half of one), said once
        $big = $settings;
        $big['note'] = str_repeat('x', FANBOY_MIRROR_MAX);
        fanboyTStore($big);
        $log0 = substr_count((string) @file_get_contents(AGENT_LOG), 'too large to mirror in RAM');
        same('always on S3: an entry larger than the mirror may be — not mirrored: the mirror is gone (never half of one), said in the log once',
            ['too_big', false, null, 'too_big', 1], [fanboyMirrorKeep(), is_file($file), fanboyMirrorRead(), fanboyMirrorKeep(true), substr_count((string) @file_get_contents(AGENT_LOG), 'too large to mirror in RAM') - $log0]);
        fanboyTStore($settings);
        same('always on S3: … small again: mirrored again', ['written', true], [fanboyMirrorKeep(), fanboyMirrorRead(FANBOY_T_KEY) !== null]);

        // who keeps it: the agent's start and tick, his saves — and nothing reads the data folder for it but the agent
        $src = fn (string $f) => (string) file_get_contents(OFFICE_DIR . "/agent/desks/$f");
        check('always on S3: kept at the agent\'s start, in his tick, at his own saves and the watch\'s switch',
            str_contains($src('fanboy.php'), "    fanboyMirrorKeep(true);         // his mirror in RAM says what the data folder says")
            && str_contains($src('fanboy.php'), '        fanboyMirrorKeep(false, $now);') && str_contains($src('fanboy-setup.php'), "            fanboyMirrorKeep(true);\n")
            && str_contains($src('fanboy-watch.php'), '    fanboyMirrorKeep(true);'));
        check('always on S3: the guard never writes it (it has no data folder to mirror)', !str_contains((string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php'), 'fanboyMirror'));
        check('always on S3: his RAM folder\'s line in the audit names the mirror and its bound', str_contains(RUNDIR_FILES['fan[b]oy']['bound'], 'the mirror of its settings ≤ 256 KB')
            && FANBOY_MIRROR_MAX === 256 * 1024 && 2 * FANBOY_MIRROR_MAX + 1100 * 1024 < RUNDIR_FILES['fan[b]oy']['max']);
    } finally {
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

/**
 * Step S4a: what the control wrote into the data folder itself, carried over by the agent from RAM — each output's state
 * before him (the journal's, into initial.json) and the history (seeded into RAM after a boot, kept to the data folder
 * when its copy is ten minutes behind). While a control still writes them itself, both are no-ops.
 */
function testAlwaysOnAgentKeeps(): void
{
    $t = fanboyT('ao-keeps');
    $sleepers = [];
    try {
        unset($GLOBALS['fanboyInitialDone'], $GLOBALS['fanboyHistorySyncNext']);
        $file = (string) fanboyInitialFile();
        $stat = function (string $f): ?string {
            clearstatcache(true, $f);
            $st = @lstat($f);
            return $st === false ? null : "{$st['ino']}.{$st['size']}.{$st['mtime']}";
        };
        // a holder that took an output without keeping its state in the data folder (as a control without the data folder does)
        file_put_contents("{$t['hw']}/pwm4", "30\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        $sleeper = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $sleepers[] = $sleeper;
        $spid = proc_get_status($sleeper)['pid'];
        $holder = ['kind' => 'control', 'pid' => $spid, 'start' => fanboyProcStart($spid), 'since' => time()];
        fanboyBeat($spid);
        $fan = fn (int $n) => ['id' => fanboyTId($n), 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => $n];
        fanboyTake($fan(4), $holder, '');
        fanboySet(fanboyTId(4), 200, $holder);
        same('always on S4a: taken without the data folder — the journal has its state before him (30, the chip\'s automatic), the data folder\'s copy nothing',
            [['value' => 30, 'enable' => 2], false], [fanboyJournal()['outputs'][fanboyTId(4)]['initial'] ?? null, is_file($file)]);
        same('always on S4a: the agent\'s tick carries it over — one output kept', 1, fanboyInitialSync());
        $k = fanboyInitialRead()['boards'][FANBOY_T_KEY][fanboyTId(4)] ?? null;
        same('always on S4a: … in the data folder\'s copy under this board: the value, the exact mode, this boot', [30, 2, FANBOY_T_BOOT], [$k['value'] ?? null, $k['enable'] ?? null, $k['boot'] ?? null]);
        $was = $stat($file);
        same('always on S4a: the next ticks keep nothing more and never touch the file again (the journal changes every round)', [0, 0, $was],
            [fanboySet(fanboyTId(4), 210, $holder) === null ? -1 : fanboyInitialSync(), fanboyInitialSync(), $stat($file)]);
        // another output taken later this boot: only that one
        fanboyTake($fan(5), $holder, '');
        same('always on S4a: another output taken later — only that one is kept now', [1, [fanboyTId(4), fanboyTId(5)]],
            [fanboyInitialSync(), array_keys(fanboyInitialRead()['boards'][FANBOY_T_KEY] ?? [])]);
        // a holder that keeps the copy itself (a control with the data folder, a setup step): nothing left to do
        unset($GLOBALS['fanboyInitialDone']);
        $was = $stat($file);
        same('always on S4a: what a holder kept itself this boot is not written again (a control that still has the data folder)', [0, $was], [fanboyInitialSync(), $stat($file)]);
        // the copy from an earlier boot is replaced by this boot's (as the first take of a boot always did)
        $j = fanboyInitialRead();
        $j['boards'][FANBOY_T_KEY][fanboyTId(4)] = ['value' => 99, 'enable' => 1, 'boot' => 'bbbbbbbb-0000-4000-8000-000000000002', 'at' => 5];
        $j['boards']['Other Boards Inc. | X1'] = [fanboyTId(4) => ['value' => 1, 'enable' => 5, 'boot' => 'x', 'at' => 1]];
        file_put_contents($file, json_encode($j));
        unset($GLOBALS['fanboyInitialDone']);
        $n = fanboyInitialSync();
        $now = fanboyInitialRead()['boards'];
        same('always on S4a: a copy from an earlier boot — replaced by this boot\'s state; another board\'s entry stays as it is',
            [1, 30, FANBOY_T_BOOT, ['value' => 1, 'enable' => 5, 'boot' => 'x', 'at' => 1]],
            [$n, $now[FANBOY_T_KEY][fanboyTId(4)]['value'] ?? null, $now[FANBOY_T_KEY][fanboyTId(4)]['boot'] ?? null, $now['Other Boards Inc. | X1'][fanboyTId(4)] ?? null]);
        same('always on S4a: … and the mirror carries it at the same tick', 30, fanboyMirrorKeep(true) === 'written' ? (fanboyMirrorRead(FANBOY_T_KEY)['initial'][fanboyTId(4)]['value'] ?? null) : 'not written');

        // the history: nothing in RAM (a boot), a copy in the data folder — seeded, byte for byte
        $ram = fanboyRunDir() . '/history.json';
        $data = FANBOY_DIR . '/history.json';
        $hist = json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => 60, 'zones' => ['disks' => [[time() - 120, 38.0, 40.0, 900]]], 'src' => [], 'leads' => new stdClass()]);
        same('always on S4a: no history anywhere — nothing to do', null, fanboyHistorySync(true));
        file_put_contents($data, $hist);
        same('always on S4a: after a boot (none in RAM) the data folder\'s history is put into RAM, byte for byte — the control starts from it',
            ['seeded', $hist, ['disks']], [fanboyHistorySync(true), @file_get_contents($ram), array_keys(fanboyHistoryLoad(FANBOY_T_KEY))]);
        same('always on S4a: … both there, the same age: nothing', null, fanboyHistorySync(true));
        // RAM ahead: kept once it is ten minutes ahead, not before (a control that writes the data folder itself is never that far ahead)
        $newer = json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => 60, 'zones' => ['disks' => [[time() - 120, 38.0, 40.0, 900], [time() - 60, 39.0, 45.0, 950]]], 'src' => [], 'leads' => new stdClass()]);
        file_put_contents($ram, $newer);
        touch($data, time() - (int) FANBOY_HISTORY_KEEP + 30);
        same('always on S4a: RAM\'s history 9½ minutes ahead of the data folder\'s — not yet', [null, $hist], [fanboyHistorySync(true), file_get_contents($data)]);
        touch($data, time() - (int) FANBOY_HISTORY_KEEP - 5);
        same('always on S4a: ten minutes ahead — copied into the data folder, byte for byte', ['kept', $newer], [fanboyHistorySync(true), file_get_contents($data)]);
        same('always on S4a: … and then nothing until RAM is ten minutes ahead again', null, fanboyHistorySync(true));
        @unlink($data);
        same('always on S4a: no copy in the data folder at all — made from RAM\'s', ['kept', $newer], [fanboyHistorySync(true), @file_get_contents($data)]);
        // a look once a minute only; junk and links are never carried
        $GLOBALS['fanboyHistorySyncNext'] = 1000.0;
        @unlink($data);
        same('always on S4a: a look a minute — within it nothing is read, after it the copy is made', [null, false, 'kept'], [fanboyHistorySync(false, 990.0), is_file($data), fanboyHistorySync(false, 1001.0)]);
        file_put_contents($ram, 'not json');
        @unlink($data);
        same('always on S4a: a broken history in RAM is never copied', [null, false], [fanboyHistorySync(true), is_file($data)]);
        @unlink($ram);
        file_put_contents("{$t['tmp']}/else.json", $hist);
        @symlink("{$t['tmp']}/else.json", $data);
        same('always on S4a: a link in the data folder\'s place is never read into RAM', [null, false], [fanboyHistorySync(true), is_file($ram)]);
        @unlink($data);
        check('always on S4a: the agent does both in his tick before the mirror, and seeds the history at his start',
            (bool) preg_match('/fanboyInitialSync\(\);[^\n]*\n\s+fanboyHistorySync\(false, \$now\);[^\n]*\n\s+fanboyMirrorKeep\(false, \$now\);/', (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy.php'))
            && (bool) preg_match('/fanboyHistorySync\(true\);[^\n]*\n\s+fanboyMirrorKeep\(true\);/', (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy.php')));
    } finally {
        array_map('alwaysOnTEnd', $sleepers);
        unset($GLOBALS['fanboyInitialDone'], $GLOBALS['fanboyHistorySyncNext']);
        fanboyTEnd();
    }
}

// ===================================================================== step S4b: a job without the data folder (agent/lib/ramjob.php)

/** The agent's code in a process of its own as a RAM job named $job would have it: runs $code, returns [exit, stdout, stderr] */
function alwaysOnTRamProc(string $job, string $code, array $env): array
{
    $php = 'define("AGENT_LIBRARY_ONLY", 1); define("AGENT_RAM_JOB", ' . var_export($job, true) . '); define("OFFICE_WEB", ' . var_export(OFFICE_WEB, true) . ');'
        . ' require ' . var_export(OFFICE_DIR . '/agent/agent.php', true) . '; ' . $code;
    $p = proc_open([PHP_BINARY, '-r', $php], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env + ['PATH' => (string) getenv('PATH'), 'TZ' => 'UTC']);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    // what the code printed is its last line (a PHP that shows warnings — never Unraid's — may print one of its own before it)
    $lines = array_values(array_filter(explode("\n", $out), fn ($l) => trim($l) !== ''));
    return [proc_close($p), (string) end($lines), $err];
}

/** Every file under $dir with its size and time — to show a folder was not touched */
function alwaysOnTTree(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $st = @lstat((string) $f);
        $out[substr((string) $f, strlen($dir))] = $st === false ? null : [$st['size'], $st['mtime'], $st['ino']];
    }
    ksort($out);
    return $out;
}

/**
 * Step S4b: `php agent.php ramjob <name>` — a desk's job without the data folder. In its process the data folder is a
 * place that can never be there, whatever the array does and whatever lies in the real one; its log lines go to RAM, and
 * the agent takes them into its own log: whole lines, once, across a rotation.
 */
function testAlwaysOnRamJob(): void
{
    $tmp = hardeningTmp('ao-ramjob');
    @mkdir("$tmp/run", 0700, true);
    // a data folder that is THERE (the array runs), with what a job could trip over
    @mkdir("$tmp/data/fanboy", 0700, true);
    @mkdir("$tmp/data/office", 0700, true);
    file_put_contents("$tmp/data/agent.log", "2026-01-01 00:00:00  the agent's own line\n");
    file_put_contents("$tmp/data/fanboy/settings.json", '{"v":1,"boards":{}}');
    file_put_contents("$tmp/data/office/staff.json", '{"hired":{"fanboy":{"at":1}}}');
    $before = alwaysOnTTree("$tmp/data");
    $env = ['OFFICE_DATA_DIR' => "$tmp/data", 'OFFICE_RUN_DIR' => "$tmp/run"];
    [$code, $out, $err] = alwaysOnTRamProc('test-job', 'logLine("hello from the job\nsecond line"); $made = @mkdir(DATA_DIR . "/x", 0700, true); $wrote = @file_put_contents(DATA_DIR . "/y", "1");'
        . ' echo json_encode(["job" => RAM_JOB, "data" => DATA_DIR, "user" => DATA_DIR_USER, "log" => AGENT_LOG, "is_dir" => is_dir(DATA_DIR), "made" => $made, "wrote" => $wrote,'
        . ' "desk_dir" => is_dir(DATA_DIR . "/fanboy"), "store" => readJson(DATA_DIR . "/fanboy/settings.json"), "staff" => staffHired(), "mailbox" => MAILBOX, "night" => NIGHT_MODE]);', $env);
    $j = json_decode($out, true) ?: [];
    same('always on S4b: in a RAM job the data folder is a place that can never be — not a folder, nothing made, written or read there, though the real one is there and full',
        ['test-job', OFFICE_NO_DATA, "$tmp/data", "$tmp/run/ramjob-test-job.log", false, false, false, false, true, false, OFFICE_NO_DATA . '/mailbox', false],
        [$j['job'] ?? null, $j['data'] ?? null, $j['user'] ?? null, $j['log'] ?? null, $j['is_dir'] ?? null, $j['made'] ?? null, $j['wrote'] ?? null, $j['desk_dir'] ?? null, array_key_exists('store', $j) && $j['store'] === null,
         in_array('fanboy', $j['staff'] ?? ['fanboy'], true), $j['mailbox'] ?? null, $j['night'] ?? null]);
    same('always on S4b: … it ended well, without a word on stderr', [0, ''], [$code, trim((string) preg_replace('/^.*Constant BACKUP_OFFICE_SHARE already defined.*$/m', '', $err))]);
    same('always on S4b: … the real data folder is exactly as it was: no file made, none touched (its agent.log too)', $before, alwaysOnTTree("$tmp/data"));
    check('always on S4b: … its log line is in its own log in RAM: one line, a time, the line break folded', (bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  hello from the job \| second line\n$/D', (string) @file_get_contents("$tmp/run/ramjob-test-job.log")));
    check('always on S4b: OFFICE_NO_DATA lies under /dev/null (nothing can ever be made there)', str_starts_with(OFFICE_NO_DATA, '/dev/null/') && !@mkdir(OFFICE_NO_DATA, 0700, true) && !is_dir(OFFICE_NO_DATA));

    // the command: a desk's ram_jobs by name, cwd /, its arguments, its exit code; anything else is refused
    [$code, $out] = alwaysOnTRamProc('test-job', 'desk("aotest", ["ram_jobs" => ["test-job" => fn (array $a): int => (print json_encode([getcwd(), $a, sprintf("%o", umask())])) ? 7 : 1]]);'
        . ' chdir(sys_get_temp_dir()); exit(main(["agent.php", "ramjob", "test-job", "one", "two"]));', $env);
    same('always on S4b: `agent.php ramjob <name> …` runs the desk\'s job — cwd /, its arguments, root-only files, its exit code', [7, ['/', ['one', 'two'], '77']], [$code, json_decode($out, true)]);
    [$code] = alwaysOnTRamProc('other-job', 'desk("aotest", ["ram_jobs" => ["test-job" => fn (array $a): int => 7]]); exit(main(["agent.php", "ramjob", "other-job"]));', $env);
    same('always on S4b: a name no desk registered as a RAM job — refused (2)', 2, $code);
    $real = function (array $args) use ($env): array {
        $p = proc_open(array_merge([PHP_BINARY, OFFICE_DIR . '/agent/agent.php'], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/',
            $env + ['PATH' => (string) getenv('PATH'), 'OFFICE_WEB' => OFFICE_WEB]);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($p), str_contains($err, 'Usage: php agent.php ramjob')];
    };
    same('always on S4b: for real — an unknown name, a name that is none, no name: refused with the usage line; an ordinary job is not a RAM job',
        [[2, true], [2, true], [2, true], [2, true]], [$real(['ramjob', 'no-such-job']), $real(['ramjob', '../x']), $real(['ramjob']), $real(['ramjob', 'embycache'])]);
    same('always on S4b: … and none of these made or touched anything in the real data folder', $before, alwaysOnTTree("$tmp/data"));

    // the agent takes the jobs' lines into its own log: whole lines, once
    $taken = '';
    $append = function (string $lines) use (&$taken): void { $taken .= $lines; };
    $log = "$tmp/run/ramjob-test-job.log";
    $first = (string) file_get_contents($log);
    same('always on S4b: the fold takes what is there — the job\'s line as it stands, with its own time', [1, $first], [ramLogFold("$tmp/run", $append), $taken]);
    same('always on S4b: … a second look takes nothing', [0, $first], [ramLogFold("$tmp/run", $append), $taken]);
    file_put_contents($log, "2026-11-22 10:00:01  line two\n2026-11-22 10:00:02  half a li", FILE_APPEND);
    same('always on S4b: a line still being written waits; the whole one before it is taken', [1, $first . "2026-11-22 10:00:01  line two\n"], [ramLogFold("$tmp/run", $append), $taken]);
    file_put_contents($log, "ne, now whole\n", FILE_APPEND);
    same('always on S4b: … and comes whole at the next look', [1, true], [ramLogFold("$tmp/run", $append), str_ends_with($taken, "2026-11-22 10:00:02  half a line, now whole\n")]);
    // put aside meanwhile (the job's log reached its bound): the rest of the old one first, then the new one from its start
    file_put_contents($log, "2026-11-22 10:00:03  last of the old\n", FILE_APPEND);
    rename($log, "$log.1");
    file_put_contents($log, "2026-11-22 10:00:04  first of the new\n");
    $taken = '';
    same('always on S4b: the log put aside between two looks — the rest of the old file, then the new one, nothing twice, nothing lost',
        [2, "2026-11-22 10:00:03  last of the old\n2026-11-22 10:00:04  first of the new\n"], [ramLogFold("$tmp/run", $append), $taken]);
    // a second job's log beside it; a link and a name that is none are never read
    file_put_contents("$tmp/run/ramjob-other.log", "2026-11-22 10:00:05  the other job\n");
    file_put_contents("$tmp/secret", "2026-11-22 10:00:06  never read\n");
    @symlink("$tmp/secret", "$tmp/run/ramjob-linked.log");
    file_put_contents("$tmp/run/ramjob-Bad_Name.log", "2026-11-22 10:00:07  never read either\n");
    $taken = '';
    same('always on S4b: each job\'s log on its own; a link and a name that is none are never read', [1, "2026-11-22 10:00:05  the other job\n"], [ramLogFold("$tmp/run", $append), $taken]);
    $state = readJson("$tmp/run/ramjob-fold.json") ?? [];
    same('always on S4b: how far each was taken is kept in RAM (a restarting agent goes on from there)', [['other', 'test-job'], filesize($log), 0600],
        [(function (array $k) { sort($k); return $k; })(array_keys($state)), $state['test-job']['pos'] ?? null, fileperms("$tmp/run/ramjob-fold.json") & 0777]);
    // the job's log is put aside at its bound, one file back
    $n0 = RAMJOB_LOG_MAX;
    file_put_contents($log, str_repeat("x", $n0 + 10) . "\n", FILE_APPEND);
    [$code] = alwaysOnTRamProc('test-job', 'logLine("after the bound");', $env);
    check('always on S4b: the job\'s log past its bound — put aside as .1, the new line in a fresh file', $code === 0 && filesize("$log.1") > $n0 && str_ends_with((string) file_get_contents($log), "  after the bound\n") && filesize($log) < 200);
    // into the agent's own log: for real, with logAppend() — the lines as they stand, between the agent's own
    $keepLog = (string) @file_get_contents(AGENT_LOG);
    logLine('always-on test: before');
    file_put_contents("$tmp/run/ramjob-third.log", "2026-11-22 10:00:08  from the third job\n");
    ramLogFold("$tmp/run");
    $now = (string) @file_get_contents(AGENT_LOG);
    // (the tests' agent log may be put aside at its bound right here: looked for in what is there now, not behind what was)
    $at = strrpos($now, "  always-on test: before\n");
    check('always on S4b: into the agent\'s log for real — the job\'s line as it stands, after the agent\'s own', $at !== false && strlen($keepLog) >= 0
        && str_ends_with($now, "2026-11-22 10:00:08  from the third job\n") && $at < strrpos($now, "2026-11-22 10:00:08  from the third job\n"));
    // the wiring: the agent folds every few seconds; the audit knows the files; the libraries name no desk
    $agent = (string) file_get_contents(OFFICE_DIR . '/agent/agent.php');
    check('always on S4b: the agent takes the logs over in its loop, and a RAM job never asks for the data folder\'s real path',
        str_contains($agent, '                ramLogFold();') && str_contains($agent, "define('DATA_DIR', RAM_JOB !== '' ? OFFICE_NO_DATA : (NIGHT_MODE ? DATA_DIR_USER : officeUnraidPath(DATA_DIR_USER)));"));
    check('always on S4b: the RAM folder\'s audit knows the jobs\' logs and the fold\'s record', (bool) array_filter(array_keys(RUNDIR_FILES), fn ($p) => preg_match('#^(?:' . $p . ')$#D', 'ramjob-fanboy-control.log') === 1
        && preg_match('#^(?:' . $p . ')$#D', 'ramjob-fanboy-control.log.1') === 1 && preg_match('#^(?:' . $p . ')$#D', 'ramjob-fold.json') === 1));
    hardeningRm($tmp);
}

// ===================================================================== step S4c: the control without the data folder

/** A mirror as the agent would write it for the fake board, changed by $change (the sum made right again) */
function alwaysOnTMirror(callable $change): void
{
    $m = $change(fanboyMirrorBuild());
    unset($m['sum']);
    $m['sum'] = fanboyMirrorSum($m);
    @mkdir(fanboyRunDir(), 0700, true);
    file_put_contents(fanboyMirrorFile(), json_encode($m + ['at' => time()]));
}

/**
 * Step S4c: in a RAM job everything the control knows of the data folder comes from his mirror — the settings, hired or
 * not, the outputs' states kept from before him — whatever the data folder says meanwhile; no mirror in its shape for
 * this board: he steers nothing («no_mirror»). And the brake: a control that finds itself holding something on the array
 * or a pool ends, says so once, and is not started again by itself.
 */
function testAlwaysOnControlRam(): void
{
    $t = fanboyT('ao-ram');
    $keep = alwaysOnTHire($t, true);
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyInitialKeep(FANBOY_T_KEY, fanboyTId(4), ['value' => 77, 'enable' => 2]);
        same('always on S4c: the agent mirrors the board (hired, armed, two zones)', 'written', fanboyMirrorKeep(true));
        // the data folder says something else now (and the agent has not looked yet): not armed, no zones, not hired, nothing kept
        $other = ['armed' => false, 'zones' => []] + $settings;
        file_put_contents(fanboyStoreFile(), json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => $other]], JSON_UNESCAPED_SLASHES));
        file_put_contents("{$t['tmp']}/staff.json", json_encode(['hired' => new stdClass()]));
        @unlink((string) fanboyInitialFile());
        $look = fn () => [(fanboyBoardRaw()['armed'] ?? null), count(fanboyBoardRaw()['zones'] ?? []), fanboyHiredNow(), fanboyInitialKept(FANBOY_T_KEY, fanboyTId(4))['value'] ?? null, fanboyHoldReason(fanboyBoardRaw())];
        $GLOBALS['fanboyRamMode'] = false;
        same('always on S4c: with the data folder (as before) he reads it: not armed, no zones, not hired, nothing kept — he may not steer', [false, 0, false, null, 'not_hired'], $look());
        $GLOBALS['fanboyRamMode'] = true;
        same('always on S4c: without it (a RAM job) he reads only his mirror: armed, two zones, hired, the state kept from before him — he steers', [true, 2, true, 77, null], $look());
        same('always on S4c: … the mode is the process\'s (a RAM job), never a setting: here the test names it', [false, 'fanboy-control'],
            [defined('RAM_JOB') && RAM_JOB !== '', array_keys($GLOBALS['desks']['fanboy']['ram_jobs'] ?? ['fanboy-control' => 1])[0]]);
        // the settings changed: the mirror's file tells the running control (written only when what it says changed)
        $s0 = fanboyControlStamp();
        $GLOBALS['fanboyRamMode'] = false;
        $same = fanboyMirrorKeep(true);          // the agent's look: the data folder says «not armed» now — mirrored
        $GLOBALS['fanboyRamMode'] = true;
        $s1 = fanboyControlStamp();
        same('always on S4c: the agent mirrors the change — the control\'s stamp changes, and now he may not steer (not hired: he ends, gives back)',
            ['written', true, 'not_hired'], [$same, $s0 !== $s1, fanboyHoldReason(fanboyBoardRaw())]);
        $GLOBALS['fanboyRamMode'] = false;
        same('always on S4c: … nothing changed: the mirror is not written again, the stamp stays (no reload for nothing)', ['same', $s1],
            [fanboyMirrorKeep(true), (function () { $GLOBALS['fanboyRamMode'] = true; return fanboyControlStamp(); })()]);
        // no mirror, another board's, a broken one: he steers nothing and says why
        alwaysOnTMirror(fn ($m) => ['hired' => true, 'settings' => fanboyTSettings()] + $m);
        same('always on S4c: a mirror in its shape for this board — he steers', [null, true], [fanboyHoldReason(fanboyBoardRaw()), fanboyHiredNow()]);
        alwaysOnTMirror(fn ($m) => ['board' => 'Other Boards Inc. | X1', 'hired' => true, 'settings' => fanboyTSettings()] + $m);
        same('always on S4c: another board\'s mirror (a stick moved to another server) — no settings, «no_mirror»', [null, 'no_mirror'], [fanboyBoardRaw(), fanboyHoldReason(fanboyBoardRaw())]);
        file_put_contents(fanboyMirrorFile(), substr((string) file_get_contents(fanboyMirrorFile()), 0, 200));
        same('always on S4c: a cut mirror — «no_mirror», never half of one', [null, 'no_mirror', false], [fanboyBoardRaw(), fanboyHoldReason(null), fanboyHiredNow()]);
        @unlink(fanboyMirrorFile());
        same('always on S4c: no mirror at all — «no_mirror»; the stamp says «none»', ['no_mirror', 'none'], [fanboyHoldReason(null), fanboyControlStamp()]);
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        // (step S6a: the array's state is no reason for a control without the data folder any more — only without a mirror)
        same('always on S4c: the array stopped, no mirror — «no_mirror» (the array itself is no reason: testAlwaysOnThroughStop)', 'no_mirror', fanboyHoldReason(null));
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Started\"\n");
        unset($GLOBALS['fanboyRamMode']);

        // the brake
        fanboyTStore($settings);
        alwaysOnTHire($t, true);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $n0 = count(fanboyTNotified($t));
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        fanboyBrake($c, ['why' => 'file', 'what' => '/mnt/cache/appdata/x.log', 'mount' => '/mnt/cache', 'pid' => 4242]);
        fanboyBrake($c, ['why' => 'file', 'what' => '/mnt/cache/appdata/x.log', 'mount' => '/mnt/cache', 'pid' => 4242]);
        $b = readJson(fanboyBrakeFile()) ?? [];
        same('always on S4c: the brake leaves its mark in RAM — what was held, on which mount, by which process', [1, 'file', '/mnt/cache/appdata/x.log', '/mnt/cache', 4242, 0600],
            [$b['v'] ?? null, $b['why'] ?? null, $b['what'] ?? null, $b['mount'] ?? null, $b['holder'] ?? null, fileperms(fanboyBrakeFile()) & 0777]);
        $told = array_slice(fanboyTNotified($t), $n0);
        check('always on S4c: … told once (a warning naming what happened), and the log says what it held (' . ($told[0] ?? '') . ')', count($told) === 1 && str_contains($told[0], '-i warning')
            && str_contains($told[0], 'his control stopped itself') && str_contains(substr((string) @file_get_contents(AGENT_LOG), $log0), 'an open file (PID 4242): /mnt/cache/appdata/x.log on /mnt/cache'));
        // the agent never starts it again by itself while the mark stands; its start, «Take over» and «Look now» take the mark away
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(100.0);
        same('always on S4c: while the mark stands the agent\'s tick starts no control', [], $GLOBALS['fanboyLaunched']);
        @unlink(fanboyBrakeFile());
        fanboyEnsureControl(200.0);
        same('always on S4c: … without it: started as ever', ['fanboy-control'], $GLOBALS['fanboyLaunched']);
        $src = fn (string $f) => (string) file_get_contents(OFFICE_DIR . "/agent/desks/$f");
        check('always on S4c: the agent\'s start, «Take over» and «Look now» take the mark away — nothing else does',
            substr_count($src('fanboy.php') . $src('fanboy-setup.php') . $src('fanboy-control.php') . $src('fanboy-mirror.php'), '@unlink(fanboyBrakeFile());') === 3
            && (bool) preg_match('/function fanboyStart\(\): void\n\{(?:(?!\n\}\n).)*@unlink\(fanboyBrakeFile\(\)\);/s', $src('fanboy.php'))
            && (bool) preg_match('/function fanboyLookNow\(\): array\n\{(?:(?!\n\}\n).)*@unlink\(fanboyBrakeFile\(\)\);/s', $src('fanboy.php'))
            && (bool) preg_match('/function fanboyTakeOver\(array \$r = \[\]\): array\n\{(?:(?!\n\}\n).)*@unlink\(fanboyBrakeFile\(\)\);/s', $src('fanboy-setup.php')));
        // the control looks at itself before its first write and every minute — only without the data folder
        check('always on S4c: the control\'s loop — the look before anything is taken, every minute after, the brake ends it with «holds»',
            (bool) preg_match('/fanboyBeat\(\);\n(?:\s*\/\/[^\n]*\n)*\s+if \(fanboyRamMode\(\) && \$now >= \$nextSelf\) \{\n\s+\$nextSelf = \$now \+ FANBOY_HOLD_CHECK_EVERY;\n\s+if \(\(\$found = fanboyHoldCheck\(\)\) !== null\) \{\n\s+fanboyBrake\(\$c, \$found\);\n\s+\$exit = \'holds\';\n\s+break;/', $src('fanboy-control.php'))
            && FANBOY_HOLD_CHECK_EVERY === 60.0 && strpos($src('fanboy-control.php'), '$nextSelf = -INF;') < strpos($src('fanboy-control.php'), 'fanboyRound($c, $now);'));
        // the words for the two new reasons, in all five languages (the page and the tile show a reason only when it has words)
        foreach (['en', 'de', 'it', 'fr', 'es'] as $lang) {
            $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$lang.json"), true) ?: [];
            check("always on S4c: $lang has the words for «no mirror» and «stopped itself» (page, tile, notification)",
                isset($j['why.no_mirror'], $j['why.holds'], $j['dash.why.no_mirror'], $j['dash.why.holds'], $j['notify.holds_subject'], $j['notify.holds']));
        }
        $tile = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
        check('always on S4c: his Dashboard tile takes both reasons from the control\'s status and shows them as it shows a control that stopped (orange)',
            str_contains($tile, "['array_stopped', 'crashed', 'released', 'hand_back', 'stopped', 'holds', 'no_mirror']") && str_contains($tile, "['crashed', 'chip_missing', 'array_stopped', 'fixed', 'holds', 'no_mirror']"));
        // a job without the data folder speaks the office's last language from RAM (the data folder's lang.json is not there for it)
        check('always on S4c: notifications of a job without the data folder take the language from RAM, as the night shift\'s do',
            str_contains((string) file_get_contents(OFFICE_DIR . '/agent/lib/house.php'), "(NIGHT_MODE || (defined('RAM_JOB') && RAM_JOB !== '') ? OFFICE_NOTIFY_LANG_RAM : OFFICE_LANG_FILE)"));
    } finally {
        unset($GLOBALS['fanboyRamMode']);
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

/**
 * 2026-11-24 (the owner on nostromo, a release blocker): a zone changed and saved on the page while the control runs as a RAM
 * job — the store was written, the mirror in RAM stayed as it was, and the control kept steering by the old zones. A save
 * reaches the running control within a round or two: the save mirrors at once (also when the mirror's lock is held by
 * someone who doesn't let go — a writer is never stopped by it for good), the control looks at its mirror every round.
 */
function testAlwaysOnSaveReachesControl(): void
{
    $t = fanboyT('ao-save');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    $holder = null;
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyMirrorKeep(true);
        $data = "{$t['tmp']}/data";
        @mkdir("$data/fanboy", 0700, true);
        $zones = fn (array $ids) => array_values(array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['name'], 'fans' => $z['fans'], 'unit' => $z['unit'],
            'target' => $z['target'], 'sources' => array_map(fn ($s) => ['id' => $s['id']], $z['sources'])], array_filter($settings['zones'], fn ($z) => in_array($z['id'], $ids, true))));
        $running = fn () => array_column(readJson(fanboyStatusFile())['zones'] ?? [], 'id');
        $procs[] = alwaysOnTControl($t, $data);
        $steers = fanboyTWait(fn () => $running() === ['disks', 'case'], 20);
        check('always on save: the control runs as a RAM job with both zones', $steers, json_encode($running()) . ' ' . (string) @file_get_contents("{$t['tmp']}/proc.out"));

        // 1. the page saves the zones (the case zone removed): the mirror says it at once, the control takes it within a round or two
        $sum0 = fanboyMirrorRead(FANBOY_T_KEY)['sum'] ?? null;
        $t0 = hrtime(true);
        fanboyZonesSave(['zones' => $zones(['disks'])]);
        $m = fanboyMirrorRead(FANBOY_T_KEY);
        same('always on save: «Übernehmen» on the zones — the mirror in RAM says the new zones at once (its sum changed)',
            [true, ['disks']], [($m['sum'] ?? null) !== $sum0, array_column($m['settings']['zones'] ?? [], 'id')]);
        $took = fanboyTWait(fn () => $running() === ['disks'], 6);
        $secs = round((hrtime(true) - $t0) / 1e9, 1);
        check("always on save: … and the running control steers by them within a round or two ($secs s; at most 5)", $took && $secs <= 5.0, json_encode($running()));

        // 2. the mirror's lock held by a process that never lets go (how the mirror stood still on nostromo): the save still
        // reaches the mirror — after a short wait it is written without the lock (both writers write what the data folder says)
        $holder = proc_open([PHP_BINARY, '-r', '$f = fopen(' . var_export(fanboyRunDir() . '/mirror.lock', true) . ', "c"); flock($f, LOCK_EX); fwrite(STDOUT, "held\n"); fflush(STDOUT); sleep(60);'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $held = trim((string) fgets($pipes[1])) === 'held';
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        unset($GLOBALS['fanboyMirrorLockSaid']);            // (said at most every ten minutes: an earlier test may have said it)
        $t1 = hrtime(true);
        fanboyZonesSave(['zones' => $zones(['disks', 'case'])]);
        $wait = round((hrtime(true) - $t1) / 1e9, 1);
        same('always on save: the lock held by another process for good — the save still writes the mirror (after at most a few seconds), and says so in the log',
            [true, ['disks', 'case'], true, true], [$held, array_column(fanboyMirrorRead(FANBOY_T_KEY)['settings']['zones'] ?? [], 'id'), $wait <= 4.0,
             str_contains(substr((string) @file_get_contents(AGENT_LOG), $log0), 'the lock of his mirror in RAM is held')]);
        $took = fanboyTWait(fn () => $running() === ['disks', 'case'], 6);
        check('always on save: … and the control takes both zones again', $took, json_encode($running()));
        // the agent's tick, while the lock stays held: a change behind its back reaches the mirror too (not «busy» forever)
        $settings2 = fanboyTSettings();
        $settings2['zones'] = [$settings['zones'][1]];
        fanboyTStore($settings2);
        // the page (2026-11-24: Benj's zone change never reached the store — a draft, and «Übernehmen» doesn't save drafts): the
        // «Übernehmen» step says unsaved zone changes with «Save zones» right there; «Übernehmen» saves them first
        $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
        $take = substr($js, (int) strpos($js, 'function takeStep()'), 9000);
        same('always on save: the page — «Übernehmen» shows unsaved zone changes with their «Save», and saves them before taking over', [true, true, true],
            [str_contains($take, 'zonesUnsavedBox(on)'), str_contains($take, "if (zonesPending() && !(await zonesSave(b2))) return true;"),
             str_contains($js, "const zonesSave = (b) => act('zones_save'")]);
        same('always on save: … the tick too (a change behind its back, the lock still held): written, not «busy» for good', ['written', ['case']],
            [fanboyMirrorKeep(false, fanboyMono() + 3600), array_column(fanboyMirrorRead(FANBOY_T_KEY)['settings']['zones'] ?? [], 'id')]);
    } finally {
        if (is_resource($holder)) {
            proc_terminate($holder, 9);
            proc_close($holder);
        }
        foreach ($procs as $p) {
            alwaysOnTStop($p);
        }
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

/** The control as a RAM job in a process of its own: the fake board, the tests' RAM folder, $data as the data folder that is THERE; $seam: PHP for the look at itself (null: the real one) */
function alwaysOnTControl(array $t, string $data, ?string $seam = 'fn () => null')
{
    $php = 'define("AGENT_LIBRARY_ONLY", 1); define("AGENT_RAM_JOB", "fanboy-control"); define("OFFICE_WEB", ' . var_export(OFFICE_WEB, true) . ');'
        . ' require ' . var_export(OFFICE_DIR . '/agent/agent.php', true) . '; date_default_timezone_set("UTC"); chdir("/");'
        . ($seam !== null ? ' $GLOBALS["fanboyHoldCheck"] = ' . $seam . ';' : '') . ' exit(fanboyControlMain());';
    return proc_open([PHP_BINARY, '-r', $php], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$t['tmp']}/proc.out", 'a'], 2 => ['file', "{$t['tmp']}/proc.out", 'a']], $pipes, '/',
        ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'OFFICE_NOTIFY_BIN' => "{$t['tmp']}/notify",
         'OFFICE_NOTIFY_STAMP' => "{$t['tmp']}/notify.second", 'PATH' => (string) getenv('PATH'), 'TZ' => 'UTC']);
}

/** A process of the test's ended (TERM, then for good) and waited for; true when it ended by itself or on the TERM within $seconds */
function alwaysOnTStop($p, float $seconds = 8.0, bool $term = true): bool
{
    if (!is_resource($p)) {
        return false;
    }
    if ($term) {
        proc_terminate($p, 15);
    }
    $gone = fanboyTWait(fn () => !proc_get_status($p)['running'], $seconds);
    if (!$gone) {
        proc_terminate($p, 9);
    }
    proc_close($p);
    return $gone;
}

/**
 * Step S4c, for real: the control as a RAM job in a process of its own. Its data folder is THERE and says the opposite of
 * the mirror (not armed, not hired, no zones) — it steers by the mirror alone, and nothing in the data folder is made,
 * written or touched: its log lines lie in RAM, its history in RAM. The look at itself finds something: it ends, every fan
 * as before, the mark and one notification. No mirror: it takes nothing.
 */
function testAlwaysOnControlProc(): void
{
    $t = fanboyT('ao-proc');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyMirrorKeep(true);
        // the data folder the process could reach: there, and it says the opposite
        $data = "{$t['tmp']}/data";
        @mkdir("$data/fanboy", 0700, true);
        @mkdir("$data/office", 0700, true);
        file_put_contents("$data/fanboy/settings.json", json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => ['armed' => false, 'zones' => []] + $settings]], JSON_UNESCAPED_SLASHES));
        file_put_contents("$data/office/staff.json", json_encode(['hired' => new stdClass()]));
        file_put_contents("$data/agent.log", "2026-01-01 00:00:00  the agent's own line\n");
        $before = alwaysOnTTree($data);
        $ramLog = ramLogFile('fanboy-control', TESTS_RUN_DIR);
        @unlink($ramLog);
        $was = fanboyTOut($t, 4);

        // 1. it steers by the mirror
        $procs[] = $p = alwaysOnTControl($t, $data);
        $steers = fanboyTWait(fn () => in_array(fanboyTId(4), fanboyHeld(), true) && fanboyTOut($t, 4) !== $was && (readJson(fanboyStatusFile())['who'] ?? '') === 'fanboy', 20);
        $out = (string) @file_get_contents("{$t['tmp']}/proc.out");
        check('always on S4c proc: the control without the data folder steers by his mirror — it holds the disk fan and wrote it, the status says Fanboy' . ($steers ? '' : " ($out)"), $steers);
        $st = readJson(fanboyStatusFile()) ?? [];
        same('always on S4c proc: … his two zones, as the mirror says (the data folder says none)', ['disks', 'case'], array_column($st['zones'] ?? [], 'id'));
        same('always on S4c proc: … nothing in the data folder was made, written or touched while it steers (no log line, no history, no copy of the states before him)', $before, alwaysOnTTree($data));
        check('always on S4c proc: … its start is in its own log in RAM, saying how it runs', (bool) preg_match('/Fanboy: the control started \(PID \d+, 2 zones, 3 fans, without the data folder: from his mirror in RAM\)/', (string) @file_get_contents($ramLog)));
        // the settings change (the agent mirrors them): taken within its next look, the fans that stay never handed over in between
        $changed = $settings;
        $changed['zones'] = [$settings['zones'][0]];            // the case zone gone: its fans go back, the disk fan stays his
        fanboyTStore($changed);
        fanboyMirrorKeep(true);
        $reloaded = fanboyTWait(fn () => array_column(readJson(fanboyStatusFile())['zones'] ?? [], 'id') === ['disks'] && fanboyHeld() === [fanboyTId(4)], 25);
        check('always on S4c proc: the mirror changed (a zone removed) — taken anew within its next look: one zone, only the disk fan held', $reloaded, json_encode([array_column(readJson(fanboyStatusFile())['zones'] ?? [], 'id'), fanboyHeld()]));
        // ended (TERM, as every stop does): every fan back as before, said in RAM, the data folder still untouched
        $ended = alwaysOnTStop($p);
        $st = readJson(fanboyStatusFile()) ?? [];
        same('always on S4c proc: asked to end — it ends, the fan back as before, nothing held, the status: the BIOS', [true, $was, [], 'bios', 'ended'],
            [$ended, fanboyTOut($t, 4), fanboyHeld(), $st['who'] ?? null, $st['phase'] ?? null]);
        check('always on S4c proc: … its end is in its log in RAM, the history in RAM', str_contains((string) @file_get_contents($ramLog), 'Fanboy: the control ended (stop)') && is_file(fanboyRunDir() . '/history.json'));
        same('always on S4c proc: … and still nothing in the data folder was made, written or touched', $before, alwaysOnTTree($data));
        // the agent takes its lines into its own log
        $taken = '';
        ramLogFold(TESTS_RUN_DIR, function (string $l) use (&$taken): void { $taken .= $l; });
        check('always on S4c proc: the agent takes its lines into its own log (start and end, with their own times)', str_contains($taken, 'Fanboy: the control started (PID') && str_contains($taken, 'Fanboy: the control ended (stop)'));

        // 2. the look at itself finds something before the first write: nothing is taken, the mark, one warning
        fanboyTStore($settings);
        fanboyMirrorKeep(true);
        @unlink(fanboyStatusFile());
        $n0 = count(fanboyTNotified($t));
        $procs[] = $p = alwaysOnTControl($t, $data, 'fn () => ["why" => "cwd", "what" => "/mnt/user/appdata", "mount" => "/mnt/user", "pid" => 77]');
        $ended = alwaysOnTStop($p, 15, false);
        $st = readJson(fanboyStatusFile()) ?? [];
        $b = readJson(fanboyBrakeFile()) ?? [];
        same('always on S4c proc: it finds itself holding the share — it ends by itself before taking anything: no fan written, nothing held, the status «holds», the mark with what it held',
            [true, $was, [], 'bios', 'holds', 'cwd', '/mnt/user'], [$ended, fanboyTOut($t, 4), fanboyHeld(), $st['who'] ?? null, $st['why'] ?? null, $b['why'] ?? null, $b['mount'] ?? null]);
        $told = array_slice(fanboyTNotified($t), $n0);
        check('always on S4c proc: … one warning through the office\'s gate (' . ($told[0] ?? '') . '), and its log in RAM says what it held',
            count($told) === 1 && str_contains($told[0], '-i warning') && str_contains($told[0], 'his control stopped itself')
            && str_contains((string) @file_get_contents($ramLog), 'its working directory (PID 77) is /mnt/user/appdata on /mnt/user'));
        same('always on S4c proc: … nothing in the data folder', $before, alwaysOnTTree($data));
        @unlink(fanboyBrakeFile());

        // 3. no mirror: it takes nothing and says why
        @unlink(fanboyMirrorFile());
        @unlink(fanboyStatusFile());
        $procs[] = $p = alwaysOnTControl($t, $data);
        $ended = alwaysOnTStop($p, 15, false);
        $st = readJson(fanboyStatusFile()) ?? [];
        same('always on S4c proc: no mirror — it ends by itself, takes nothing, the status says «no_mirror» (the data folder\'s settings are never read instead)',
            [true, $was, [], 'no_mirror', $before], [$ended, fanboyTOut($t, 4), fanboyHeld(), $st['why'] ?? null, alwaysOnTTree($data)]);

        // 4. the real look at itself. On a server the suite runs in a mount namespace of its own (the guard of the live RAM
        // folder): a control started from here is exactly what the brake is for — it must end with «namespace». Without a
        // /proc (never an Unraid) the look can't be made: «no_look», the brake too. In init's namespace: it may steer.
        fanboyMirrorKeep(true);
        @unlink(fanboyStatusFile());
        $own = is_link('/proc/self/ns/mnt') && is_link('/proc/1/ns/mnt') ? (@readlink('/proc/self/ns/mnt') !== @readlink('/proc/1/ns/mnt') ? 'namespace' : null) : 'no_look';
        $procs[] = $p = alwaysOnTControl($t, $data, null);
        if ($own !== null) {
            $ended = alwaysOnTStop($p, 15, false);
            $b = readJson(fanboyBrakeFile()) ?? [];
            same("always on S4c proc: the real look — here «{$own}»: the brake for real, nothing taken", [true, $own, $was, [], 'holds'],
                [$ended, $b['why'] ?? null, fanboyTOut($t, 4), fanboyHeld(), readJson(fanboyStatusFile())['why'] ?? null]);
        } else {
            $steers = fanboyTWait(fn () => in_array(fanboyTId(4), fanboyHeld(), true) || is_file(fanboyBrakeFile()), 20);
            $b = readJson(fanboyBrakeFile());
            check('always on S4c proc: the real look in init\'s namespace — it steers, or names exactly what it holds (' . ($b === null ? 'steers' : json_encode($b)) . ')',
                $steers && ($b === null || in_array($b['why'] ?? '', ['cwd', 'file', 'map'], true)));
            alwaysOnTStop($p);
        }
        same('always on S4c proc: … and after all of it the data folder is exactly as it was', $before, alwaysOnTTree($data));
    } finally {
        foreach ($procs as $p) {
            if (is_resource($p)) {
                @proc_terminate($p, 9);
                @proc_close($p);
            }
        }
        @unlink(fanboyBrakeFile());
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

/**
 * Step S4d, the switch: the agent starts the control as a RAM job — `php agent.php ramjob fanboy-control`, with nothing
 * open that the agent had open — and no other way is left (`job fanboy-control` is refused). His other jobs start as
 * before. Before a start the mirror is made sure of; none for this board: no control, and the page says why.
 */
function testAlwaysOnLaunch(): void
{
    $t = fanboyT('ao-launch');
    $keep = alwaysOnTHire($t, true);
    try {
        // (his real desk: the stand-in of alwaysOnTHire() has no jobs)
        $cmd = fanboyLaunchCommand('fanboy-control');
        $real = isset(desks()['fanboy']['ram_jobs']['fanboy-control']);
        if ($real) {
            same('always on S4d: the control starts as a RAM job, in a session of its own, through a shell that closes every file from 3 on',
                [['setsid', '-f', '/bin/sh', '-c'], ['sh', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'ramjob', 'fanboy-control'], true, true],
                [array_slice($cmd, 0, 4), array_slice($cmd, 5), str_starts_with($cmd[4], 'exec "$@" 3>&- 4>&- 5>&-'), str_ends_with($cmd[4], ' 63>&-')]);
            same('always on S4d: his other jobs (a setup step, «show me», «back to the value before») start as before, with the data folder',
                array_fill(0, 3, ['setsid', '-f', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job']),
                array_map(fn ($j) => array_slice(fanboyLaunchCommand($j), 0, 5), ['fanboy-step', 'fanboy-show', 'fanboy-restore']));
            same('always on S4d: his desk — the control is a RAM job only (one way to run it), the others jobs with the data folder',
                [['fanboy-control'], ['fanboy-step', 'fanboy-show', 'fanboy-restore']], [array_keys(desks()['fanboy']['ram_jobs']), array_keys(desks()['fanboy']['jobs'])]);
        }
        check('always on S4d: his real desk is there to ask (else the three checks above did not run)', $real);
        // for real, the whole command line: with a data folder that is there and says «armed» but no mirror — it takes nothing («no_mirror»)
        fanboyTDisks($t, 35, null, 33);
        $data = "{$t['tmp']}/data";
        @mkdir("$data/fanboy", 0700, true);
        @mkdir("$data/office", 0700, true);
        file_put_contents("$data/fanboy/settings.json", json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => fanboyTSettings()]], JSON_UNESCAPED_SLASHES));
        file_put_contents("$data/office/staff.json", json_encode(['hired' => ['fanboy' => ['at' => 1]]]));
        $before = alwaysOnTTree($data);
        $was = fanboyTOut($t, 4);
        $run = function (array $args) use ($t, $data): array {
            $p = proc_open(array_merge([PHP_BINARY, OFFICE_DIR . '/agent/agent.php'], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/',
                ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'OFFICE_WEB' => OFFICE_WEB, 'OFFICE_NOTIFY_BIN' => "{$t['tmp']}/notify",
                 'OFFICE_NOTIFY_STAMP' => "{$t['tmp']}/notify.second", 'PATH' => (string) getenv('PATH'), 'TZ' => 'UTC']);
            stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            return [proc_close($p), $err];
        };
        @unlink(fanboyStatusFile());
        [$code] = $run(['ramjob', 'fanboy-control']);
        same('always on S4d: `agent.php ramjob fanboy-control` for real, the data folder there and armed, no mirror — it ends at once, takes nothing, «no_mirror»; the data folder untouched',
            [0, 'no_mirror', $was, [], $before], [$code, readJson(fanboyStatusFile())['why'] ?? null, fanboyTOut($t, 4), fanboyHeld(), alwaysOnTTree($data)]);
        [$code, $err] = $run(['job', 'fanboy-control']);
        same('always on S4d: `agent.php job fanboy-control` (the way before) is refused — one way to run it', [2, true], [$code, str_contains($err, 'Usage: php agent.php job')]);

        // the agent's tick: the mirror made sure of right before a start
        $settings = fanboyTSettings();
        fanboyTStore($settings);                    // written past the agent: no mirror yet
        @unlink(fanboyMirrorFile());
        @unlink(fanboyStatusFile());
        unset($GLOBALS['fanboyMirrorSeen']);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(10.0);
        same('always on S4d: the tick makes sure of the mirror, then starts the control', [['fanboy-control'], true], [$GLOBALS['fanboyLaunched'], fanboyMirrorRead(FANBOY_T_KEY)['settings']['armed'] ?? null]);
        // his settings too large to mirror: no control, the page's status says why (not «about to take over»), said once
        $big = $settings;
        $big['note'] = str_repeat('x', FANBOY_MIRROR_MAX);
        fanboyTStore($big);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        $log0 = substr_count((string) @file_get_contents(AGENT_LOG), 'no mirror of his settings in RAM for this board');
        fanboyEnsureControl(100.0);
        fanboyEnsureControl(200.0);
        $control = fanboyControlState($big);
        same('always on S4d: no mirror for this board (his settings too large) — no control is started, the status says «no_mirror», the log once',
            [[], 'bios', 'no_mirror', 1], [$GLOBALS['fanboyLaunched'], $control['who'], $control['why'], substr_count((string) @file_get_contents(AGENT_LOG), 'no mirror of his settings in RAM for this board') - $log0]);
        same('always on S4d: … the page words it: who «the BIOS», why «no_mirror» (never «starting»)', ['bios', 'no_mirror'], array_values(array_intersect_key(fanboyWhoBase(['who' => ['who' => 'bios', 'why' => 'not_set_up']], $control, $big), ['who' => 1, 'why' => 1])));
        fanboyTStore($settings);
        fanboyCrashKeep(['launched' => null]);
        fanboyEnsureControl(300.0);
        same('always on S4d: … small again: mirrored and started', ['fanboy-control'], $GLOBALS['fanboyLaunched']);
    } finally {
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

// ===================================================================== step S5a: the guard's release-steps (the array stops, the control stays)

/**
 * Step S5a: `fanboy-guard.php release-steps` — what agent.sh will call at the array's stop once the control steers through
 * it. Everything of his but the control ends and gives back (a step works in the data folder: asked to end, ended for
 * good when it is still there after 3 s); the control stays and keeps its output — unless the look at it from outside
 * finds it holding something on the array or a pool, or can't be made: then it ends like a full release, every output
 * goes back, and the brake's mark keeps everybody from starting it again by itself (the agent tells at its next start).
 * The holders' processes are looked up in the real /proc: where there is none (a Mac) only the parts without them run.
 */
function testAlwaysOnReleaseSteps(): void
{
    $t = fanboyT('ao-steps');
    $data = "{$t['tmp']}/data";
    @mkdir($data, 0700, true);
    $live = is_dir('/proc/' . getmypid());
    $procs = [];
    $pidOf = fn ($p) => (int) proc_get_status($p)['pid'];
    $owner = fn (int $n) => fanboyJournal()['outputs'][fanboyTId($n)]['owner'] ?? null;
    $given = fn (int $n) => fanboyJournal()['outputs'][fanboyTId($n)]['given']['why'] ?? null;
    try {
        $before = [fanboyTOut($t, 2), fanboyTOut($t, 4)];
        same('always on S5a: nothing held, no control — release-steps does nothing and says nothing', '', fanboyTGuard($t, $data, 'release-steps'));
        // a step holds output 2: given back whoever else is there
        $procs[] = $step = alwaysOnTHold($t, 2, 'step');
        $procs[] = $control = alwaysOnTHold($t, 4, 'control');
        $cp = $pidOf($control);
        same('always on S5a: (the test\'s board: a step holds output 2 at 250, the control output 4)', [[250, 1], [250, 1]], [fanboyTOut($t, 2), fanboyTOut($t, 4)]);
        if ($live) {
            file_put_contents(fanboyRunDir() . '/control.pid', "$cp " . fanboyProcStart($cp));
            alwaysOnTProc($t['root'], [$cp => []]);         // as the kernel would show a clean control: cwd /, RAM only, init's namespace
        }
        $out = fanboyTGuard($t, $data, 'release-steps');
        same('always on S5a: the step\'s output is back as before him, its holder gone from the journal, given back as a release', [$before[0], null, 'release'],
            [fanboyTOut($t, 2), $owner(2), $given(2)]);
        check("always on S5a: … said for agent.sh's log in RAM, never the agent's log in the data folder ($out)", str_contains($out, '1 outputs of a setup step back with the BIOS')
            && !is_file("$data/agent.log"));
        if ($live) {
            same('always on S5a: the control stays: its output as it wrote it, still its own, no brake, its status untouched', [[250, 1], $cp, false, false],
                [fanboyTOut($t, 4), $owner(4)['pid'] ?? null, is_file(fanboyBrakeFile()), is_file(fanboyStatusFile())]);
            check("always on S5a: … and the line says it steers on ($out)", str_contains($out, "the control (PID $cp) holds nothing on the array or a pool and steers on")
                && (bool) proc_get_status($control)['running'] && !proc_get_status($step)['running']);
            // the same control, now with its working directory on a pool: it must not outlive the stop
            fanboyStatusWrite(['v' => 1, 'pid' => $cp, 'who' => 'fanboy', 'why' => null, 'phase' => 'run', 'time' => time()]);
            exec('rm -rf ' . escapeshellarg("{$t['root']}/proc/$cp"));
            alwaysOnTProc($t['root'], [$cp => ['cwd' => '/mnt/tank/appdata/UnraidSecretaryOffice']]);
            $out = fanboyTGuard($t, $data, 'release-steps');
            usleep(300000);
            $b = readJson(fanboyBrakeFile()) ?? [];
            $s = readJson(fanboyStatusFile()) ?? [];
            same('always on S5a: a control with its working directory on a pool is ended at the stop: its output back as before him, nobody holds it',
                [$before[1], null, false], [fanboyTOut($t, 4), $owner(4), (bool) proc_get_status($control)['running']]);
            same('always on S5a: … the brake\'s mark says who found what (the stop, the working directory, the mount), the status says why the BIOS has the fans',
                [1, 'stop', 'cwd', '/mnt/tank/appdata', $cp, 'bios', 'holds', 'ended'],
                [$b['v'] ?? null, $b['by'] ?? null, $b['why'] ?? null, $b['mount'] ?? null, $b['pid'] ?? null, $s['who'] ?? null, $s['why'] ?? null, $s['phase'] ?? null]);
            check("always on S5a: … said in agent.sh's log ($out)", str_contains($out, "the control (PID $cp) must not outlive it: its working directory") && str_contains($out, 'released'));
            // a control that can't be looked at (nothing of it in /proc): the same — never a guess
            @unlink(fanboyBrakeFile());
            $procs[] = $blind = alwaysOnTHold($t, 4, 'control');
            $bp = $pidOf($blind);
            file_put_contents(fanboyRunDir() . '/control.pid', "$bp " . fanboyProcStart($bp));
            $out = fanboyTGuard($t, $data, 'release-steps');
            usleep(300000);
            same('always on S5a: a control the look can\'t see is ended too (no look counts as held)', [$before[1], null, 'no_look', false],
                [fanboyTOut($t, 4), $owner(4), readJson(fanboyBrakeFile())['why'] ?? null, (bool) proc_get_status($blind)['running']]);
            // a step that doesn't end on the TERM: ended for good after the wait — it works in the data folder
            @unlink(fanboyBrakeFile());
            @unlink(fanboyRunDir() . '/control.pid');
            $deaf = proc_open([PHP_BINARY, '-r', 'pcntl_signal(SIGTERM, SIG_IGN); pcntl_signal(SIGHUP, SIG_IGN); sleep(60);', '--', 'agent.php', 'job', 'fanboy-step'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $procs[] = $deaf;
            $dp = $pidOf($deaf);
            usleep(500000);
            $holder = ['kind' => 'step', 'pid' => $dp, 'start' => fanboyProcStart($dp), 'since' => time()];
            fanboyBeat($dp);
            fanboyTake(['id' => fanboyTId(2), 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => 2], $holder, FANBOY_T_KEY);
            fanboySet(fanboyTId(2), 120, $holder);
            $t0 = fanboyMono();
            fanboyTGuard($t, $data, 'release-steps');
            $took = fanboyMono() - $t0;
            usleep(300000);
            check('always on S5a: a step deaf to the TERM is waited for 3 s, then ended for good, its output back (' . round($took, 1) . ' s)',
                $took >= 2.9 && $took < 8 && !proc_get_status($deaf)['running'] && fanboyTOut($t, 2) === $before[0] && $owner(2) === null);
        }
        // the agent's next start tells what the guard's brake found, once, and takes the mark away
        $keep = alwaysOnTHire($t, true);
        try {
            fanboyTStore(fanboyTSettings());
            @mkdir(fanboyRunDir(), 0700, true);
            file_put_contents(fanboyBrakeFile(), json_encode(['v' => 1, 'at' => time(), 'pid' => 4711, 'by' => 'stop', 'why' => 'cwd', 'what' => '/mnt/tank/appdata/x', 'mount' => '/mnt/tank/appdata', 'holder' => 4711]));
            $n0 = count(fanboyTNotified($t));
            $log0 = strlen((string) @file_get_contents(AGENT_LOG));
            fanboyStart();
            $told = array_slice(fanboyTNotified($t), $n0);
            check('always on S5a: the agent\'s next start says what the guard found at the stop (a warning, the log names the working directory) and takes the mark away',
                count($told) === 1 && str_contains($told[0], '-i warning') && !is_file(fanboyBrakeFile())
                && str_contains(substr((string) @file_get_contents(AGENT_LOG), $log0), 'its working directory (PID 4711) is /mnt/tank/appdata/x on /mnt/tank/appdata'));
            // the control's own brake was told by the control: the start says nothing more
            file_put_contents(fanboyBrakeFile(), json_encode(['v' => 1, 'at' => time(), 'pid' => 4711, 'why' => 'file', 'what' => '/mnt/x', 'mount' => '/mnt/x', 'holder' => 4711]));
            fanboyUntellOnce('holds');
            $n0 = count(fanboyTNotified($t));
            fanboyStart();
            same('always on S5a: … a brake the control pulled itself was told by it: the start only takes the mark away', [0, false], [count(fanboyTNotified($t)) - $n0, is_file(fanboyBrakeFile())]);
        } finally {
            alwaysOnTHireEnd($keep);
        }
        // the plain release is what it was: everybody ends, the control too
        $procs[] = alwaysOnTHold($t, 2, 'step');
        $procs[] = alwaysOnTHold($t, 4, 'control');
        fanboyTGuard($t, $data, 'release');
        same('always on S5a: `release` (stop, restart, update, removal) still gives every output back — the control\'s too', [$before[0], $before[1], null, null],
            [fanboyTOut($t, 2), fanboyTOut($t, 4), $owner(2), $owner(4)]);
        $src = (string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php');
        check('always on S5a: release-steps never writes the agent\'s log (the data folder is about to go)',
            (bool) preg_match('/function fanboyGuardReleaseSteps\(\): array\n\{(?:(?!\n\}\n).)*\n\}\n/s', $src, $m) && !str_contains($m[0], 'logLine(') && str_contains($m[0], "fanboyGuardSay('released', \$all, 'holds', false)"));
    } finally {
        foreach ($procs as $p) {
            alwaysOnTEnd($p);
        }
        fanboyTEnd();
    }
}

// ===================================================================== step S5b: looked after without the data folder (the night shift)

/** The real night shift in a process of its own: the fake board, the tests' RAM folder, $data as the data folder setting (never to be touched) */
function alwaysOnTNightShift(array $t, string $data)
{
    return proc_open([PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'nightshift'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$t['tmp']}/night.out", 'a'], 2 => ['file', "{$t['tmp']}/night.out", 'a']],
        $pipes, '/', ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'OFFICE_WEB' => OFFICE_WEB, 'OFFICE_NOTIFY_BIN' => "{$t['tmp']}/notify",
            'OFFICE_NOTIFY_STAMP' => "{$t['tmp']}/notify.second", 'PATH' => (string) getenv('PATH'), 'TZ' => 'UTC']);
}

/**
 * Step S5b: his control is looked after without the data folder. What the agent's tick knows of the control's deaths lies
 * in RAM (this boot's), so the night shift goes on where the agent stopped and the other way round; in the night shift
 * («RAM mode») everything he knows comes from his mirror — the data folder is never read or written; the night shift
 * calls every desk's 'night' and stays on for a desk that asks, without the night watchman's mirror too.
 */
function testAlwaysOnNight(): void
{
    $t = fanboyT('ao-night');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        // 1. the deaths' memory in RAM
        same('always on S5b: nothing counted yet', ['deaths' => [], 'seen' => null, 'launched' => null], fanboyCrashRead());
        fanboyCrashKeep(['deaths' => [10.5, 20], 'seen' => 4242]);
        fanboyCrashKeep(['launched' => 30.25, 'other' => 1]);
        same('always on S5b: kept in RAM (0600), a change keeps the rest, nothing unknown gets in', [['deaths' => [10.5, 20.0], 'seen' => 4242, 'launched' => 30.25], 0600, ['v', 'boot', 'deaths', 'seen', 'launched']],
            [fanboyCrashRead(), fileperms(fanboyCrashFile()) & 0777, array_keys(readJson(fanboyCrashFile()) ?? [])]);
        $raw = readJson(fanboyCrashFile());
        file_put_contents(fanboyCrashFile(), json_encode(['boot' => 'another-boot-0000'] + $raw));
        same('always on S5b: another boot\'s memory is none (the clock it counts on started anew)', ['deaths' => [], 'seen' => null, 'launched' => null], fanboyCrashRead());
        file_put_contents(fanboyCrashFile(), '{"v":1,"boot"');
        same('always on S5b: a broken one neither', [], fanboyCrashRead()['deaths']);
        @unlink(fanboyCrashFile());

        // 2. the agent counts a death, the night shift sees it — and the other way round
        same('always on S5b: (the agent mirrors: hired, armed, two zones)', 'written', fanboyMirrorKeep(true));
        $dead = fn (int $pid) => fanboyStatusWrite(['v' => 1, 'pid' => $pid, 'who' => 'fanboy', 'why' => null, 'phase' => 'run', 'time' => time()]);
        $GLOBALS['fanboyLaunched'] = [];
        $dead(900001);
        fanboyEnsureControl(1000.0);            // the agent's tick: a control died without ending
        fanboyEnsureControl(1005.0);
        same('always on S5b: the agent counts a control that died once, and waits before a new start', [1, 900001, []], [count(fanboyCrashRead()['deaths']), fanboyCrashRead()['seen'], $GLOBALS['fanboyLaunched']]);
        $GLOBALS['fanboyRamMode'] = true;       // … now the night shift looks (another process: nothing in memory but RAM's files)
        fanboyEnsureControl(1010.0);
        same('always on S5b: the night shift knows that death and its pause (30 s) — no start yet', [1, []], [fanboyRestarts(1010.0)['deaths'], $GLOBALS['fanboyLaunched']]);
        fanboyEnsureControl(1031.0);
        same('always on S5b: … after the pause it starts the control', ['fanboy-control'], $GLOBALS['fanboyLaunched']);
        $dead(900002);
        fanboyEnsureControl(1100.0);
        $dead(900003);
        fanboyEnsureControl(1200.0);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(1300.0);
        unset($GLOBALS['fanboyRamMode']);
        fanboyEnsureControl(1400.0);            // the agent again
        same('always on S5b: three deaths within the window, whoever counted them: given up — neither starts it again by itself', [3, true, []],
            [fanboyRestarts(1400.0)['deaths'], fanboyRestarts(1400.0)['given_up'], $GLOBALS['fanboyLaunched']]);
        fanboyStart();
        same('always on S5b: the agent\'s start counts anew (as when the deaths were its own memory)', ['deaths' => [], 'seen' => null, 'launched' => null], fanboyCrashRead());
        fanboyStatusWrite(['v' => 1, 'pid' => 900003, 'who' => 'bios', 'why' => 'stopped', 'phase' => 'ended', 'time' => time()]);

        // 3. in the night shift everything comes from the mirror — the data folder says something else and is not touched
        fanboyTStore(['armed' => false, 'zones' => []] + $settings);
        file_put_contents("{$t['tmp']}/staff.json", json_encode(['hired' => new stdClass()]));
        $tree = alwaysOnTTree(FANBOY_DIR);
        $mirror = @lstat(fanboyMirrorFile());
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(2000.0);
        same('always on S5b: the agent reads the data folder: not armed, not hired — no control', [], $GLOBALS['fanboyLaunched']);
        // (the agent's look above mirrored nothing: it starts nothing, so it never got to the mirror — the mirror still says «armed»)
        $GLOBALS['fanboyRamMode'] = true;
        same('always on S5b: the night shift reads his mirror: he would steer — it stays on for him', true, fanboyNightWanted());
        unset($GLOBALS['fanboyNightNext'], $GLOBALS['fanboyNightWanted']);
        $w1 = fanboyNight(3000.0);
        same('always on S5b: … and its look starts the control from the mirror (the array runs in this test)', [true, ['fanboy-control']], [$w1, $GLOBALS['fanboyLaunched']]);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        fanboyNight(3001.0);
        fanboyNight(3004.9);
        same('always on S5b: … paced: a look every ' . (int) FANBOY_TICK_EVERY . ' s, whatever the loop does', [], $GLOBALS['fanboyLaunched']);
        fanboyNight(3005.0);
        same('always on S5b: … the next one after that', ['fanboy-control'], $GLOBALS['fanboyLaunched']);
        clearstatcache();
        $m2 = @lstat(fanboyMirrorFile());
        same('always on S5b: the night shift wrote nothing into the data folder and never the mirror (the agent\'s alone)', [$tree, $mirror['ino'] ?? null, $mirror['mtime'] ?? null],
            [alwaysOnTTree(FANBOY_DIR), $m2['ino'] ?? null, $m2['mtime'] ?? null]);
        // the array not started — where the night shift really works (since step S6a it starts the control then: he steers through the stop)
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        fanboyNight(4000.0);
        same('always on S5b: the array stopped — the night shift starts the control from the mirror all the same', ['fanboy-control'], $GLOBALS['fanboyLaunched']);
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Started\"\n");
        // a mirror that says «not armed», nothing held: nothing to look after
        alwaysOnTMirror(fn ($m) => ['hired' => true, 'settings' => ['armed' => false] + $settings] + $m);
        same('always on S5b: his mirror says «not armed», nothing holds an output — the night shift need not stay for him', false, fanboyNightWanted());
        $procs[] = alwaysOnTHold($t, 4, 'step');
        same('always on S5b: … but while something holds an output it stays (a holder that dies is given back within seconds)', true, fanboyNightWanted());
        @unlink(fanboyMirrorFile());
        same('always on S5b: no mirror at all: he steers nothing, «no_mirror» — and no look under the data folder for it', [null, false, 'no_mirror'], [fanboyBoardRaw(), fanboyHiredNow(), fanboyHoldReason(null)]);
        unset($GLOBALS['fanboyRamMode']);

        // 4. the code: who is in «RAM mode», where his folder lies in the night, what the night shift's loop does
        $src = fn (string $f) => (string) file_get_contents(OFFICE_DIR . "/agent/$f");
        check('always on S5b: in the night shift he is in RAM mode, and his folder of the data folder names a place that can never be there',
            str_contains($src('desks/fanboy-control.php'), "return \$GLOBALS['fanboyRamMode'] ?? ((defined('RAM_JOB') && RAM_JOB !== '') || (defined('NIGHT_MODE') && NIGHT_MODE));")
            && str_contains($src('desks/fanboy.php'), "define('FANBOY_DIR', (defined('NIGHT_MODE') && NIGHT_MODE ? OFFICE_NO_DATA : DATA_DIR) . '/fanboy');"));
        check('always on S5b: the give-back seen to work goes into his store by the agent only; the mirror is made sure of by the agent only',
            (bool) preg_match('/if \(!fanboyRamMode\(\)\) \{\n\s+fanboyAutoSeenKeep\(\);/', $src('desks/fanboy.php'))
            && (bool) preg_match('/if \(!\$ram\) \{\n\s+fanboyMirrorKeep\(true\);\n\s+\}/', $src('desks/fanboy.php')));
        check('always on S5b: what a desk says in the night shift goes to RAM — never a look for the data folder under /mnt',
            (bool) preg_match('/if \(defined\(\'NIGHT_MODE\'\) && NIGHT_MODE && function_exists\(\'ramLogLine\'\)\) \{\n\s+ramLogLine\(\$text, \'night\'\);\n\s+return;\n\s+\}\n\s+logAppend\(/', $src('lib/util.php')));
        $night = $src('agent.php');
        check('always on S5b: the night shift\'s loop calls every desk\'s night work each pass, a failing one never ends it, and it stays while a desk asks',
            (bool) preg_match('/foreach \(desks\(\) as \$id => \$desk\) \{\n\s+if \(isset\(\$desk\[\'night\'\]\)\) \{\n\s+try \{\n\s+if \(\$desk\[\'night\'\]\(\) === true\) \{\n\s+\$wanted\[\] = \$id;/', $night)
            && str_contains($night, "if (!\$watch && !\$wanted) {") && str_contains($night, "if (!\$wanted) {\n                        \$why = 'no mirror of his baseline")
            && is_callable($GLOBALS['desks']['fanboy']['night'] ?? fn () => false));
        // (step S5e) the night shift looks at itself too: found holding something on the array or a pool it ends (exit 0: not
        // started again); a look that can't be made is said once and ends nothing
        check('always on S5e: the night shift looks at itself before its first round and every minute — a finding ends it, «no look» is said once and ends nothing',
            (bool) preg_match('/if \(agentClock\(\) >= \$nextSelf\) \{\n\s+\$nextSelf = agentClock\(\) \+ 60;\n\s+\$held = officeHoldsNothing\(\);\n\s+if \(\$held !== null && \(\$held\[\'why\'\] \?\? \'\'\) !== \'no_look\'\) \{\n\s+\$why = [^\n]+\n\s+break;/', $night)
            && strpos($night, '$nextSelf = -INF;') < strpos($night, 'watchmanNightRound(watchmanNightPaths())') && strpos($night, 'if (agentClock() >= $nextSelf) {') < strpos($night, "foreach (desks() as \$id => \$desk) {\n            if (isset(\$desk['night']))"));
        $guard = $src('fanboy-guard.php');
        check('always on S5b: the guard writes the agent\'s log in the data folder only while the array is plainly started — else its line waits in RAM',
            (bool) preg_match('/function fanboyGuardLog\(string \$line\): void\n\{\n\s+try \{\n\s+if \(\(readCfg\(fanboySysRoot\(\) \. \'\/var\/local\/emhttp\/var\.ini\'\)\[\'fsState\'\] \?\? \'\'\) === \'Started\'\) \{\n\s+logLine\(\$line\);\n\s+\} else \{\n\s+ramLogLine\(\$line, \'fanboy-guard\'\);/', $guard)
            && substr_count($guard, 'logLine(') - substr_count($guard, 'ramLogLine(') - substr_count($guard, 'Never logLine()') === 1);

        // 5. the guard's cron look with the array stopped: its line waits in RAM, the data folder is not touched
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        foreach ($procs as $p) {
            alwaysOnTEnd($p);
        }
        $procs = [];
        usleep(200000);
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        $before = alwaysOnTTree($data);
        @unlink(ramLogFile('fanboy-guard'));
        $out = fanboyTGuard($t, $data, 'watch');
        same('always on S5b: the guard\'s look with the array stopped gives the dead holder\'s output back, says it in RAM, and leaves the data folder alone',
            [null, true, $before], [fanboyJournal()['outputs'][fanboyTId(4)]['owner'] ?? null, str_contains((string) @file_get_contents(ramLogFile('fanboy-guard')), 'a holder was gone'), alwaysOnTTree($data)], $out);
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Started\"\n");
        $procs[] = alwaysOnTHold($t, 4, 'step');
        alwaysOnTEnd(array_pop($procs));
        usleep(200000);
        fanboyTGuard($t, $data, 'watch');
        check('always on S5b: … with the array started it writes the agent\'s log as before', str_contains((string) @file_get_contents("$data/agent.log"), 'a holder was gone'));

        // 6. the real night shift, where this machine has no array of its own to be «started» (a server's own var.ini would end it at once)
        if (!is_file('/var/local/emhttp/var.ini')) {
            file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
            $GLOBALS['fanboyRamMode'] = false;
            fanboyTStore($settings);
            alwaysOnTHire($t, true);
            fanboyMirrorKeep(true);
            unset($GLOBALS['fanboyRamMode']);
            exec('rm -rf ' . escapeshellarg($data) . ' ' . escapeshellarg(TESTS_RUN_DIR . '/nightshift') . ' ' . escapeshellarg(TESTS_RUN_DIR . '/nightshift.lock'));
            @unlink(ramLogFile('night'));
            $gone = alwaysOnTHold($t, 4, 'control');
            alwaysOnTEnd($gone);                // its holder is gone: nobody but the night shift is there to give it back
            $shift = alwaysOnTNightShift($t, $data);
            $procs[] = $shift;
            for ($i = 0; $i < 100 && (fanboyJournal()['outputs'][fanboyTId(4)]['owner'] ?? null) !== null; $i++) {
                usleep(100000);
            }
            $log = (string) @file_get_contents(TESTS_RUN_DIR . '/nightshift/nightshift.log');
            same('always on S5b: the real night shift stays on for him without the night watchman\'s mirror, and gives a dead holder\'s output back',
                [true, null, true], [(bool) proc_get_status($shift)['running'], fanboyJournal()['outputs'][fanboyTId(4)]['owner'] ?? null, str_contains($log, 'the night shift stays for fanboy')], $log . @file_get_contents("{$t['tmp']}/night.out"));
            if (!is_dir('/proc/self')) {
                same('always on S5e: … on a machine without /proc its look at itself can\'t be made: said once, and it goes on', 1, substr_count($log, 'The look at itself could not be made'));
            }
            check('always on S5b: … what it says of him waits in RAM for the agent\'s log; the data folder was never made or touched',
                str_contains((string) @file_get_contents(ramLogFile('night')), 'a holder was gone') && !file_exists($data), (string) @file_get_contents(ramLogFile('night')));
            // nothing left to look after: it ends by itself
            @unlink(fanboyMirrorFile());
            for ($i = 0; $i < 120 && proc_get_status($shift)['running']; $i++) {
                usleep(100000);
            }
            check('always on S5b: … with nothing left to look after (no mirror, nothing held) it ends by itself',
                !proc_get_status($shift)['running'] && str_contains((string) @file_get_contents(TESTS_RUN_DIR . '/nightshift/nightshift.log'), 'nothing left to look after'));
        }
    } finally {
        unset($GLOBALS['fanboyRamMode']);
        foreach ($procs as $p) {
            alwaysOnTEnd($p);
        }
        exec('rm -rf ' . escapeshellarg(TESTS_RUN_DIR . '/nightshift') . ' ' . escapeshellarg(TESTS_RUN_DIR . '/nightshift.lock'));
        @unlink(ramLogFile('night'));
        @unlink(ramLogFile('fanboy-guard'));
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

// ===================================================================== step S5c: «give the fans back to the mainboard» without the agent

/**
 * Step S5c: the one wish heard while no agent is there — a mark in RAM. While it stands the control ends (every output
 * back) and nobody starts it; the night shift keeps a copy on the flash (a reboot) and brings it back from there; the
 * agent's next start makes it true in his store («Hand back») and takes both away. A mark counts by being there.
 */
function testAlwaysOnHandBack(): void
{
    $t = fanboyT('ao-handback');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyMirrorKeep(true);
        $flash = fanboyHandBackFlash();
        same('always on S5c: the copy lies in the plugin\'s folder on the flash (the tests\' tree here), the mark in his RAM folder',
            ["{$t['root']}/boot/config/plugins/unraid-secretary-office/fanboy-handback.json", TESTS_RUN_DIR . '/fanboy/handback.json'], [$flash, fanboyHandBackFile()]);
        same('always on S5c: no mark — nothing asked, nothing to keep, nothing for the agent to take', [false, null, false], [fanboyHandBackAsked(), fanboyHandBackKeep(), fanboyHandBackTake()]);

        // nobody starts the control while the mark stands
        fanboyHandBackMark(1700000000);
        same('always on S5c: the mark: in RAM, 0600, {v, at}', [true, 0600, ['v' => 1, 'at' => 1700000000]], [fanboyHandBackAsked(), fileperms(fanboyHandBackFile()) & 0777, readJson(fanboyHandBackFile())]);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(100.0);
        $GLOBALS['fanboyRamMode'] = true;
        fanboyEnsureControl(200.0);
        unset($GLOBALS['fanboyRamMode']);
        same('always on S5c: while it stands neither the agent\'s tick nor the night shift starts the control', [], $GLOBALS['fanboyLaunched']);

        // the night shift: the copy on the flash, and back from there after a reboot
        same('always on S5c: no folder of the plugin on the flash: no copy (never made here)', [null, false], [fanboyHandBackKeep(), file_exists($flash)]);
        @mkdir(dirname($flash), 0700, true);
        same('always on S5c: the night shift copies the mark to the flash, once', ['flash', null, ['v' => 1, 'at' => 1700000000]], [fanboyHandBackKeep(), fanboyHandBackKeep(), readJson($flash)]);
        @unlink(fanboyHandBackFile());              // a reboot: RAM is empty, the flash is not
        same('always on S5c: after a reboot (RAM empty) it brings the mark back from the flash — with its time', ['ram', true, 1700000000, null],
            [fanboyHandBackKeep(), fanboyHandBackAsked(), readJson(fanboyHandBackFile())['at'] ?? null, fanboyHandBackKeep()]);
        file_put_contents(fanboyHandBackFile(), '{"v":1,"a');
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        fanboyEnsureControl(300.0);
        same('always on S5c: a cut mark is a mark (the BIOS has the fans — never a guess the other way)', [true, []], [fanboyHandBackAsked(), $GLOBALS['fanboyLaunched']]);

        // the running control hears it within a round and gives back
        @unlink(fanboyHandBackFile());
        @unlink($flash);
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        $was = fanboyTOut($t, 4);
        $procs[] = $p = alwaysOnTControl($t, $data);
        $steers = fanboyTWait(fn () => in_array(fanboyTId(4), fanboyHeld(), true) && fanboyTOut($t, 4) !== $was, 20);
        check('always on S5c: (the control steers: it holds the disk fan)', $steers, (string) @file_get_contents("{$t['tmp']}/proc.out"));
        $t0 = fanboyMono();
        fanboyHandBackMark();
        $ended = alwaysOnTStop($p, 12, false);
        $st = readJson(fanboyStatusFile()) ?? [];
        same('always on S5c: the mark set — the control ends by itself within a round or two, every fan back as before, the status «handed back»',
            [true, true, $was, [], 'bios', 'hand_back', 'ended'], [$ended, fanboyMono() - $t0 < 8, fanboyTOut($t, 4), fanboyHeld(), $st['who'] ?? null, $st['why'] ?? null, $st['phase'] ?? null]);
        $procs[] = $p = alwaysOnTControl($t, $data);
        $ended = alwaysOnTStop($p, 12, false);
        same('always on S5c: … and one started while the mark stands takes nothing and ends («handed back»)', [true, $was, [], 'hand_back'],
            [$ended, fanboyTOut($t, 4), fanboyHeld(), readJson(fanboyStatusFile())['why'] ?? null]);

        // the agent's start makes it true in his store and takes both marks away
        fanboyHandBackKeep();
        same('always on S5c: (armed in his store, the mark in RAM and on the flash)', [true, true, true], [fanboyBoardRaw()['armed'] ?? null, fanboyHandBackAsked(), is_file($flash)]);
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        fanboyStart();
        $log = substr((string) @file_get_contents(AGENT_LOG), $log0);
        same('always on S5c: the agent\'s next start hands back in his store (not armed), mirrors it, and takes both marks away',
            [false, false, false, false], [fanboyBoardRaw()['armed'] ?? null, fanboyMirrorRead(FANBOY_T_KEY)['settings']['armed'] ?? null, fanboyHandBackAsked(), file_exists($flash)]);
        check('always on S5c: … said in the log, with when it was asked — one line, never «on the user\'s click (0 outputs)» (the control gave them back when the mark was set)',
            str_contains($log, 'the fans were given back to the mainboard while the array wasn\'t started (') && str_contains($log, 'handed back, until «Take over»') && !str_contains($log, 'on the user\'s click'), $log);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        fanboyEnsureControl(400.0);
        same('always on S5c: … and he stays handed back: no control until «Take over»', [], $GLOBALS['fanboyLaunched']);
        // a mark left on the flash alone (the array started before the night shift looked — or a reboot): the start takes it too
        file_put_contents($flash, '{"v":1,"at":1700000001}');
        same('always on S5c: a copy on the flash alone is taken by the agent\'s start as well', [true, false], [fanboyHandBackTake(), file_exists($flash)]);
        // the code: the control looks every round; «Take over» ends a hand-back asked for before
        $src = fn (string $f) => (string) file_get_contents(OFFICE_DIR . "/agent/desks/$f");
        check('always on S5c: the control looks for the mark every round (not only at its 10-second look)',
            (bool) preg_match('/if \(fanboyHandBackAsked\(\)\) \{\n\s+\$exit = \'hand_back\';\n\s+break;\n\s+\}\n\s+fanboyRound\(\$c, \$now\);/', $src('fanboy-control.php')));
        check('always on S5c: «Take over» takes both marks away; the night shift keeps mark and copy alike at every look',
            (bool) preg_match('/function fanboyTakeOver\(array \$r = \[\]\): array\n\{(?:(?!\n\}\n).)*@unlink\(fanboyHandBackFile\(\)\);[^\n]*\n\s+@unlink\(fanboyHandBackFlash\(\)\);/s', $src('fanboy-setup.php'))
            && (bool) preg_match('/function fanboyNight\(\?float \$now = null\): bool\n\{(?:(?!\n\}\n).)*fanboyHandBackKeep\(\);/s', $src('fanboy-night.php')));
    } finally {
        unset($GLOBALS['fanboyRamMode']);
        foreach ($procs as $p) {
            if (is_resource($p)) {
                @proc_terminate($p, 9);
                @proc_close($p);
            }
        }
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

// ===================================================================== step S5d: disks of unknown temperature (decision E4)

/**
 * Step S5d: while the array isn't started Unraid's disks.ini is not «now» (measured on 7.4: the stop spins every drive up,
 * the file is rewritten for the last time within two minutes and keeps those values for hours) — and a file not
 * rewritten for twice the poll, or missing, is not either. Such disks are UNKNOWN: not asleep (the fans would stand), no
 * silent sensor (an alarm). A zone with one never lets a fan stand, keeps at least each fan's floor, and holds its last
 * level — 30 minutes with the array stopped, as long as it lasts with the array running. Its live sources still count.
 */
function testAlwaysOnUnknown(): void
{
    $t = fanboyT('ao-unknown');
    try {
        $var = "{$t['root']}/var/local/emhttp/var.ini";
        $ini = "{$t['root']}/var/local/emhttp/disks.ini";
        $p = fanboyPaths($t['root']);
        $state = fn (string $s, string $more = '') => file_put_contents($var, "fsState=\"$s\"\n$more");
        // 1. when are the values «now»?
        fanboyTDisks($t, 35, null, 44);
        $w = (int) filemtime($ini);
        same('always on S5d: the array runs, the file is fresh — known', [null, null, null], [fanboyDisksUnknown($p, 30, $w + 5), fanboyDisksUnknown($p, 1800, $w + 1800), fanboyDisksUnknown($p, null, $w + 3600)]);
        same('always on S5d: … not rewritten for twice the poll (never under 10 minutes): stale; poll unknown: Unraid\'s own 30 minutes, twice',
            [null, 'stale', null, 'stale', 'stale'], [fanboyDisksUnknown($p, 30, $w + 600), fanboyDisksUnknown($p, 30, $w + 601), fanboyDisksUnknown($p, 1800, $w + 3600), fanboyDisksUnknown($p, 1800, $w + 3601), fanboyDisksUnknown($p, null, $w + 3601)]);
        same('always on S5d: … a file «from the future» (the clock set back) is taken as fresh — the next poll rewrites it', null, fanboyDisksUnknown($p, 30, $w - 7200));
        $got = [];
        foreach (['Stopping', 'Stopped', 'Starting', '', 'Formatting', 'Clearing', 'Started'] as $s) {
            $state($s);
            $got[$s] = fanboyDisksUnknown($p, 30, $w + 5);
        }
        same('always on S5d: the array not started (stopping, stopped, starting, nothing said) — unknown, however fresh the file; formatting and clearing are a running array',
            ['Stopping' => 'stopped', 'Stopped' => 'stopped', 'Starting' => 'stopped', '' => 'stopped', 'Formatting' => null, 'Clearing' => null, 'Started' => null], $got);
        rename($ini, "$ini.aside");
        same('always on S5d: no disks.ini at all (emhttpd not up yet) — unknown', 'missing', fanboyDisksUnknown($p, 30, $w + 5));
        rename("$ini.aside", $ini);

        // 2. the control: a disk fan that may stand, its curve asking nothing under 40 °C
        $settings = fanboyTSettings();
        $settings['fans'][fanboyTId(4)]['off_ok'] = true;
        $settings['fans'][fanboyTId(4)]['measured'] += ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => 30, 'kick_start' => null, 'stops_safe' => true];
        $settings['zones'][0]['target'] = ['points' => [[30, 0], [40, 0], [45, 60], [50, 100]]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTDisks($t, 35, null, 45);                 // disk2 45 °C: 60 % = 153
        fanboyTRounds($c, $now, $t, $spec, 40);
        same('always on S5d: (the array runs: disk2 at 45 °C — the disk fan at 60 %)', [153, null, 0], [fanboyTOut($t, 4)[0], $c['disks_unknown'], fanboyStatus($c)['zones'][0]['unknown']]);
        // the array stops: the file keeps saying 45 °C … and then «cooler», which nobody knows
        $state('Stopping');
        fanboyTDisks($t, 30, null, 30);
        fanboyTRounds($c, $now, $t, $spec, 8);
        $z = $c['zones']['disks'];
        $st = fanboyStatus($c);
        same('always on S5d: the array stops — his disks are «unknown» (not asleep, not silent), the zone keeps its level, no alarm',
            ['stopped', ['parity' => 'unknown', 'disk1' => 'unknown', 'disk2' => 'unknown'], 153, null, 'stopped', 3, 0],
            [$c['disks_unknown'], $z['state'], fanboyTOut($t, 4)[0], $c['alarm'], $st['disks_unknown'], $st['zones'][0]['unknown'], $st['zones'][0]['asleep']]);
        $state('Stopped');
        fanboyTDisks($t, null, null, null);             // the file even says «all asleep» now: unknown all the same
        $now += 300;
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('always on S5d: stopped, five minutes on, the file says «asleep» — still the level it had (the file is not «now»)', [153, 'unknown'], [fanboyTOut($t, 4)[0], $c['zones']['disks']['state']['parity']]);
        $now += 120.0;                                  // past the silent sensor's two minutes: no alarm
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('always on S5d: … and never the silent sensor\'s alarm', [null, []], [$c['alarm'], array_keys($c['reasons'])]);
        $now = 1000.0 + 80 + FANBOY_UNKNOWN_HOLD + 20;  // 30 minutes after the stop
        fanboyTRounds($c, $now, $t, $spec, 120);
        same('always on S5d: 30 minutes after the stop it comes down — to the fan\'s floor (30 % = 77), never to a stand, though he «may stand» and the curve asks nothing',
            [77, null, null], [fanboyTOut($t, 4)[0], $c['fans'][fanboyTId(4)]['stand_since'], $c['alarm']]);
        // the array starts again: fresh values count as ever — the fan may stand again
        $state('Started');
        fanboyTDisks($t, 35, null, 35);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('always on S5d: the array runs again, 35 °C: known, the fan stands as its curve says', [null, 'ok', 0], [$c['disks_unknown'], $c['zones']['disks']['state']['parity'], fanboyTOut($t, 4)[0]]);

        // 3. the array runs but the file is old (emhttpd hangs): unknown too — and the level stays for as long as it lasts
        fanboyTDisks($t, 35, null, 45);
        fanboyTRounds($c, $now, $t, $spec, 45);
        same('always on S5d: (running, disk2 at 45 °C: 60 % again)', 153, fanboyTOut($t, 4)[0]);
        fanboyTDisks($t, 30, null, 30);
        touch($ini, time() - 2 * FANBOY_POLL_DEFAULT - 60);
        fanboyTRounds($c, $now, $t, $spec, 8);
        $now += FANBOY_UNKNOWN_HOLD + 600;
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('always on S5d: the array runs, disks.ini not rewritten for over an hour — unknown («stale»), and the level stays well past 30 minutes (the drives may be at work)',
            ['stale', 153, null], [$c['disks_unknown'], fanboyTOut($t, 4)[0], $c['alarm']]);
        touch($ini);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('always on S5d: … rewritten: known again (30 °C: within the gap\'s hold it starts down)', [null, 'ok'], [$c['disks_unknown'], $c['zones']['disks']['state']['disk2']]);

        // 4. a control that starts while the array is stopped (a boot, a restart): no level to keep — the floor, never a stand
        fanboyLetGo($c, 'test');
        $state('Stopped');
        fanboyTDisks($t, 35, null, 44);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('always on S5d: started with the array stopped — the disk fan at its floor (no level to keep, never a stand), the case zone by its live sensors as ever',
            [77, 'unknown', [102, 1]], [fanboyTOut($t, 4)[0], $c['zones']['disks']['state']['disk2'], fanboyTOut($t, 5)]);
        // a settings reload keeps the clock of the 30 minutes
        $c['zones']['disks']['unknown_since'] = 123.0;
        fanboyControlReload($c, $settings, FANBOY_T_KEY, $c['owner']);
        same('always on S5d: a settings reload keeps since when the zone\'s disks are unknown', 123.0, $c['zones']['disks']['unknown_since']);
        fanboyLetGo($c, 'test');
        // 5. the net for sources in no zone takes an unknown disk for no value (never a frozen one)
        $s2 = $settings;
        $s2['zones'][0]['sources'] = [['kind' => 'disk', 'id' => 'parity']];
        $state('Started');
        fanboyTDisks($t, 35, null, 60);                 // disk2 in no zone, hot: the net lifts every fan
        $c = fanboyControlNew($s2, FANBOY_T_KEY, fanboyMe('control'));
        fanboyTRounds($c, $now, $t, $spec, 8);
        $net = $c['net']['level'] ?? null;
        $state('Stopped');
        fanboyTRounds($c, $now, $t, $spec, 8);
        check('always on S5d: a hot disk in no zone lifts every fan while the array runs (the net: ' . json_encode($net) . ') — stopped, its value is unknown: no value for the net',
            $net !== null && $net > 0 && array_key_exists('disk2', $c['loose']) && $c['loose']['disk2']['c'] === null);
        fanboyLetGo($c, 'test');
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== step S6a: through the array's stop (decision E1)

/** agent.sh's fanboy_release as the event script runs it ($set: shell before it, e.g. STOP_WHY=array_stopping;) — its log's text */
function alwaysOnTRelease(array $t, string $data, string $set): string
{
    $log = "{$t['tmp']}/agent.sh.log";
    @unlink($log);
    $p = proc_open(['bash', '-c', 'source "$1"; DIR="$2"; RUN="$3"; FANBOY_JOURNAL="$3/fanboy/journal.json"; LOG="$4"; ' . $set . ' fanboy_release',
        'x', OFFICE_DIR . '/plugin/scripts/agent.sh', OFFICE_DIR, TESTS_RUN_DIR, $log], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, '/', ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'PATH' => (string) getenv('PATH')]);
    proc_close($p);
    return (string) @file_get_contents($log);
}

/**
 * Step S6a, the switch: «taken over» means he steers for as long as the server runs. The array's state is no reason for
 * the control to end any more (it runs without the data folder), nobody waits for the array to start it, and at the
 * array's stop scripts/agent.sh ends only what works in the data folder and looks at the control from outside
 * (fanboy-guard.php release-steps); every other stop of the office still gives every output back.
 */
function testAlwaysOnThroughStop(): void
{
    $t = fanboyT('ao-through');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    $var = "{$t['root']}/var/local/emhttp/var.ini";
    $state = fn (string $s) => file_put_contents($var, "fsState=\"$s\"\n");
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyMirrorKeep(true);
        // 1. the one place that decides
        $got = [];
        foreach (['Started', 'Stopping', 'Stopped', 'Starting'] as $s) {
            $state($s);
            $GLOBALS['fanboyRamMode'] = true;
            $ram = fanboyHoldReason(fanboyBoardRaw());
            $GLOBALS['fanboyRamMode'] = false;
            $got[$s] = [$ram, fanboyHoldReason($settings) === 'array_stopped'];
        }
        unset($GLOBALS['fanboyRamMode']);
        same('always on S6a: a control without the data folder may steer whatever the array does; one WITH it (none is started so any more) still ends when the array isn\'t started',
            ['Started' => [null, false], 'Stopping' => [null, true], 'Stopped' => [null, true], 'Starting' => [null, true]], $got);
        // 2. nobody waits for the array to start it: the agent's tick in its last seconds of a stop, the night shift after it
        $state('Stopping');
        $GLOBALS['fanboyLaunched'] = [];
        fanboyEnsureControl(100.0);
        $state('Stopped');
        $GLOBALS['fanboyRamMode'] = true;
        fanboyCrashKeep(['launched' => null]);
        fanboyEnsureControl(200.0);
        unset($GLOBALS['fanboyRamMode']);
        same('always on S6a: a control that is gone is started again while the array stops (the agent\'s tick) and while it is stopped (the night shift)', ['fanboy-control', 'fanboy-control'], $GLOBALS['fanboyLaunched']);
        $state('Started');

        // 3. for real: the control steers, the array stops and is stopped — it steers on, the same process, the data folder untouched
        $data = "{$t['tmp']}/data";
        @mkdir("$data/fanboy", 0700, true);
        file_put_contents("$data/agent.log", "2026-01-01 00:00:00  the agent's own line\n");
        $before = alwaysOnTTree($data);
        $was = fanboyTOut($t, 4);
        $procs[] = $p = alwaysOnTControl($t, $data);
        $steers = fanboyTWait(fn () => in_array(fanboyTId(4), fanboyHeld(), true) && fanboyTOut($t, 4) !== $was && (readJson(fanboyStatusFile())['who'] ?? '') === 'fanboy', 20);
        check('always on S6a: (the control steers while the array runs)', $steers, (string) @file_get_contents("{$t['tmp']}/proc.out"));
        $pid = readJson(fanboyStatusFile())['pid'] ?? null;
        $wrote = fanboyTOut($t, 4);
        $state('Stopping');
        usleep(7000000);
        $state('Stopped');
        $unknown = fanboyTWait(fn () => (readJson(fanboyStatusFile())['disks_unknown'] ?? null) === 'stopped', 15);
        usleep(6000000);            // well past its 10-second look at whether it may steer
        $st = readJson(fanboyStatusFile()) ?? [];
        same('always on S6a: the array stopped under it — the same control steers on: it holds the disk fan at what it wrote, the status says Fanboy, the disks\' temperatures «unknown», no alarm',
            [true, true, $pid, 'fanboy', 'run', $wrote, [fanboyTId(4), fanboyTId(5), fanboyTId(2)], true, null],
            [(bool) proc_get_status($p)['running'], time() - (int) ($st['time'] ?? 0) <= 6, $st['pid'] ?? null, $st['who'] ?? null, $st['phase'] ?? null, fanboyTOut($t, 4), array_values(array_intersect([fanboyTId(4), fanboyTId(5), fanboyTId(2)], fanboyHeld())), $unknown, $st['alarm'] ?? null]);
        same('always on S6a: … and nothing in the data folder was made, written or touched through it', $before, alwaysOnTTree($data));
        $state('Started');
        $known = fanboyTWait(fn () => array_key_exists('disks_unknown', $s = readJson(fanboyStatusFile()) ?? ['disks_unknown' => 'x']) && $s['disks_unknown'] === null, 15);
        same('always on S6a: the array runs again — the same process still, the disks known again', [true, $pid, true], [$known, readJson(fanboyStatusFile())['pid'] ?? null, (bool) proc_get_status($p)['running']]);
        $ended = alwaysOnTStop($p);
        same('always on S6a: asked to end (the office\'s stop, an update, the server\'s shutdown: TERM) — it gives every fan back as ever', [true, $was, []], [$ended, fanboyTOut($t, 4), fanboyHeld()]);

        // 4. agent.sh at the array's stop: only release-steps; any other stop: the full release
        $sh = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh');
        check('always on S6a: fanboy_release — the guard\'s release-steps at the array\'s stop, its release at every other stop; it looks even when only a control runs (no output taken yet)',
            (bool) preg_match('/^fanboy_release\(\) \{\n    local cmd=release\n    \[\[ -f "\$DIR\/agent\/fanboy-guard\.php" \]\] \|\| return 0\n    \[\[ -s "\$FANBOY_JOURNAL" \|\| -s "\$\{FANBOY_JOURNAL%\/\*\}\/control\.pid" \]\] \|\| return 0\n'
                . '    \[\[ "\$\{STOP_WHY:-\}" == array_stopping \]\] && cmd=release-steps\n    timeout -k 1 15 php "\$DIR\/agent\/fanboy-guard\.php" "\$cmd" <\/dev\/null >>"\$LOG" 2>&1\n    fanboy_back_check\n    return 0\n\}/m', $sh));
        check('always on S6a: only array_event names that reason — stop, restart, the update and the removal give every output back',
            substr_count($sh, 'local STOP_WHY=array_stopping') === 1 && !str_contains($sh, 'export STOP_WHY') && (bool) preg_match('/^stop\(\) \{\n    night_stop\n    agent_stop\n    fanboy_release\n\}/m', $sh)
            && (bool) preg_match('/if \[\[ "\$what" == stop \]\]; then\n        local STOP_WHY=array_stopping[^\n]*\n        release[^\n]*\n        night_start/', $sh));
        $guard = (string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php');
        check('always on S6a: the guard looks at the control at release-steps even without a journal (a control that never took an output)',
            strpos($guard, "if (\$cmd === 'release-steps') {") < strpos($guard, "if (!is_file(fanboyRunDir() . '/journal.json')) {\n        return 0;") && strpos($guard, "if (\$cmd === 'release-steps') {") !== false);
        // … for real where a shell can run it (bash's `timeout`, a /proc for the holders)
        $live = is_dir('/proc/' . getmypid()) && trim((string) @shell_exec('command -v timeout 2>/dev/null')) !== '';
        if ($live) {
            $before4 = fanboyTOut($t, 4);
            $before2 = fanboyTOut($t, 2);
            $procs[] = $step = alwaysOnTHold($t, 2, 'step');
            $procs[] = $control = alwaysOnTHold($t, 4, 'control');
            $cp = (int) proc_get_status($control)['pid'];
            file_put_contents(fanboyRunDir() . '/control.pid', "$cp " . fanboyProcStart($cp));
            alwaysOnTProc($t['root'], [$cp => []]);
            $log = alwaysOnTRelease($t, $data, 'STOP_WHY=array_stopping;');
            same('always on S6a: agent.sh at the array\'s stop — the step\'s output back, the control\'s kept and still its own, the control alive, no brake',
                [$before2, [250, 1], $cp, true, false], [fanboyTOut($t, 2), fanboyTOut($t, 4), fanboyJournal()['outputs'][fanboyTId(4)]['owner']['pid'] ?? null, (bool) proc_get_status($control)['running'], is_file(fanboyBrakeFile())]);
            check("always on S6a: … its log says both ($log)", str_contains($log, '1 outputs of a setup step back with the BIOS') && str_contains($log, "the control (PID $cp) holds nothing on the array or a pool and steers on"));
            $log = alwaysOnTRelease($t, $data, '');
            usleep(300000);
            same('always on S6a: agent.sh at any other stop (update, restart, removal) — the control ends too, every output back',
                [$before4, [], false], [fanboyTOut($t, 4), fanboyHeld(), (bool) proc_get_status($control)['running']], $log);
            // a control that never took an output (no journal at all) is looked at too: one the look can't see is ended
            exec('rm -rf ' . escapeshellarg(TESTS_RUN_DIR . '/fanboy'));
            @mkdir(fanboyRunDir(), 0700, true);
            $procs[] = $idle = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $ip = (int) proc_get_status($idle)['pid'];
            file_put_contents(fanboyRunDir() . '/control.pid', "$ip " . fanboyProcStart($ip));
            $log = alwaysOnTRelease($t, $data, 'STOP_WHY=array_stopping;');
            usleep(300000);
            same('always on S6a: a control without any output, which the look from outside can\'t see — ended at the array\'s stop, the brake\'s mark by the stop',
                [false, 'stop', 'no_look'], [(bool) proc_get_status($idle)['running'], readJson(fanboyBrakeFile())['by'] ?? null, readJson(fanboyBrakeFile())['why'] ?? null], $log);
        }
    } finally {
        unset($GLOBALS['fanboyRamMode']);
        foreach ($procs as $p) {
            if (is_resource($p)) {
                @proc_terminate($p, 9);
                @proc_close($p);
            }
        }
        @unlink(fanboyBrakeFile());
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

// ===================================================================== step S6b: after a boot, before the array starts (decisions E2, E7)

/** The copy on the flash changed by the test and summed anew (as a copy another server, another version would have written) */
function alwaysOnTFlash(callable $change): void
{
    $f = $change(array_diff_key(readJson(fanboyMirrorFlashFile()) ?? [], ['sum' => 1]));
    file_put_contents(fanboyMirrorFlashFile(), json_encode($f + ['sum' => fanboyMirrorFlashSum($f)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Step S6b: while he is hired and armed the agent keeps a copy of his mirror on the flash; after a boot the night shift
 * takes it into RAM only when it is this board's, in its shape, written under this Unraid version and kernel, and emhttpd
 * runs — then he steers before the array starts. Else the BIOS keeps the fans until the array starts, as ever. The
 * history from before the boot is handed to a control that started before the agent; a fixed value found where the
 * chip's automatic stood before him never becomes «the state before him».
 */
function testAlwaysOnBoot(): void
{
    $t = fanboyT('ao-boot');
    $keep = alwaysOnTHire($t, true);
    $procs = [];
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings() + ['auto_back_seen' => ['at' => 5, 'output' => 'x'], 'note' => 'not for the flash'];
        fanboyTStore($settings);
        fanboyInitialKeep(FANBOY_T_KEY, fanboyTId(4), ['value' => 77, 'enable' => 2]);
        fanboyMirrorKeep(true);
        $flash = fanboyMirrorFlashFile();
        $ino = function () use ($flash): string {
            clearstatcache(true, $flash);
            $st = @lstat($flash);
            return $st === false ? 'none' : "{$st['ino']}.{$st['size']}";
        };
        // 1. the agent keeps the copy
        same('always on S6b: the copy lies in the plugin\'s folder on the flash; without that folder nothing is written (never made here)',
            ["{$t['root']}/boot/config/plugins/unraid-secretary-office/fanboy-mirror.json", 'no_flash', false], [$flash, fanboyMirrorFlashKeep(true), file_exists($flash)]);
        @mkdir(dirname($flash), 0700, true);
        same('always on S6b: hired, armed, zones — the copy is written, once', ['written', 'same', 'same'], [fanboyMirrorFlashKeep(false, 100.0), fanboyMirrorFlashKeep(false, 101.0), fanboyMirrorFlashKeep(true, 102.0)]);
        $f = readJson($flash) ?? [];
        same('always on S6b: what it says: this board, hired, of his settings only what the control reads, each output\'s state before him without what changes at every boot, the system it was written under',
            [['v', 'board', 'hired', 'settings', 'initial', 'watch', 'drivers', 'unraid', 'kernel', 'sum', 'at'], FANBOY_T_KEY, true, ['board', 'fans', 'zones', 'armed'], [fanboyTId(4) => ['value' => 77, 'enable' => 2]], [], '7.3.2', 'test'],
            [array_keys($f), $f['board'] ?? null, $f['hired'] ?? null, array_keys($f['settings'] ?? []), $f['initial'] ?? null, $f['drivers'] ?? null, $f['unraid'] ?? null, $f['kernel'] ?? null]);
        check('always on S6b: … read back only in exactly its shape, its sum right', fanboyMirrorFlashRead() !== null && fanboyMirrorFlashRead()['settings']['armed'] === true);
        // what changes at every boot (the boot an output's state is from, its time) rewrites the mirror in RAM, never the flash
        $i0 = $ino();
        $ini = readJson((string) fanboyInitialFile());
        $ini['boards'][FANBOY_T_KEY][fanboyTId(4)] = ['value' => 77, 'enable' => 2, 'boot' => 'another-boot', 'at' => 12345];
        file_put_contents((string) fanboyInitialFile(), json_encode($ini));
        same('always on S6b: another boot (the same states before him) — the mirror in RAM is written anew, the copy on the flash is not', ['written', 'same', $i0], [fanboyMirrorKeep(true), fanboyMirrorFlashKeep(true, 200.0), $ino()]);
        // a change of his settings: at most once a minute
        $s2 = $settings;
        $s2['zones'][0]['name'] = 'Disks, renamed';
        fanboyTStore($s2);
        fanboyMirrorKeep(true);
        same('always on S6b: his settings change — the copy follows, but at most once a minute (the flash is spared)', ['waits', 'waits', 'written', 'Disks, renamed'],
            [fanboyMirrorFlashKeep(false, 110.0), fanboyMirrorFlashKeep(false, 159.0), fanboyMirrorFlashKeep(false, 160.5), readJson($flash)['settings']['zones'][0]['name'] ?? null]);
        // handed back, fired: the copy goes at once — taken over again: back at once
        fanboyTStore(['armed' => false] + $s2);
        fanboyMirrorKeep(true);
        same('always on S6b: handed back — the copy is taken away at once (a boot must not find «armed»)', ['removed', false, 'same'], [fanboyMirrorFlashKeep(false, 161.0), file_exists($flash), fanboyMirrorFlashKeep(false, 162.0)]);
        fanboyTStore($s2);
        fanboyMirrorKeep(true);
        same('always on S6b: taken over again — written at once', ['written', true], [fanboyMirrorFlashKeep(false, 163.0), file_exists($flash)]);
        alwaysOnTHire($t, false);
        fanboyMirrorKeep(true);
        same('always on S6b: fired — taken away', ['removed', false], [fanboyMirrorFlashKeep(false, 164.0), file_exists($flash)]);
        alwaysOnTHire($t, true);
        fanboyMirrorKeep(true);
        fanboyMirrorFlashKeep(true);
        $src = fn (string $f) => (string) file_get_contents(OFFICE_DIR . "/agent/desks/$f");
        check('always on S6b: the agent keeps it at its start, in his tick and with every save of his settings',
            (bool) preg_match('/fanboyMirrorKeep\(true\);[^\n]*\n\s+fanboyMirrorFlashKeep\(true\);/', $src('fanboy.php')) && str_contains($src('fanboy.php'), 'fanboyMirrorFlashKeep(false, $now);')
            && (bool) preg_match('/fanboyMirrorKeep\(true\);\n\s+fanboyMirrorFlashKeep\(\);/', $src('fanboy-setup.php')));

        // 2. the copy as a reader takes it
        $good = (string) file_get_contents($flash);
        alwaysOnTFlash(fn ($f) => ['drivers' => ['it87', 'evil_module']] + $f);
        same('always on S6b: a copy naming a module he doesn\'t know is no copy', null, fanboyMirrorFlashRead());
        alwaysOnTFlash(fn ($f) => ['hired' => false] + $f);
        same('always on S6b: a copy that says «not hired» is no copy (there is none while he isn\'t)', null, fanboyMirrorFlashRead());
        file_put_contents($flash, str_replace('"armed":true', '"armed":false', $good));
        same('always on S6b: a copy changed by hand (its sum wrong) is no copy', null, fanboyMirrorFlashRead());
        file_put_contents($flash, $good);

        // 3. after a boot: when it may count
        $mirror = fanboyMirrorFile();
        same('always on S6b: a mirror in RAM is there (no boot): the copy is never put over it', ['ram', 'ram'], [fanboyMirrorFlashFits(), fanboyMirrorFromFlash()]);
        @unlink($mirror);
        $GLOBALS['fanboyRamMode'] = true;
        same('always on S6b: a boot (RAM empty), the copy fits — but emhttpd isn\'t up: nothing yet, no mirror', [true, 'emhttpd', false], [is_array(fanboyMirrorFlashFits()), fanboyMirrorFromFlash(), file_exists($mirror)]);
        same('always on S6b: … the night shift stays on for him meanwhile (the copy waits for emhttpd)', true, fanboyNightWanted());
        @mkdir("{$t['root']}/var/run", 0700, true);
        file_put_contents("{$t['root']}/var/run/emhttpd.socket", '');
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        same('always on S6b: emhttpd up (its socket, its var.ini saying a state) — the mirror comes from the flash: hired, armed, the state before him; he may steer',
            ['restored', true, true, 2, 'flash', null],
            [fanboyMirrorFromFlash(), fanboyHiredNow(), fanboyBoardRaw()['armed'] ?? null, fanboyInitialKept(FANBOY_T_KEY, fanboyTId(4))['enable'] ?? null, readJson($mirror)['from'] ?? null, fanboyHoldReason(fanboyBoardRaw())]);
        same('always on S6b: … once: a second look finds the mirror', 'ram', fanboyMirrorFromFlash());
        @unlink("{$t['root']}/var/run/emhttpd.socket");
        @unlink($mirror);
        same('always on S6b: no socket, no process of that name: not up', 'emhttpd', fanboyMirrorFromFlash());
        @mkdir("{$t['root']}/proc/4711", 0700, true);
        file_put_contents("{$t['root']}/proc/4711/comm", "emhttpd\n");
        same('always on S6b: a process named emhttpd counts too', 'restored', fanboyMirrorFromFlash());
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "csrf_token=\"x\"\n");
        @unlink($mirror);
        same('always on S6b: … but not while its var.ini says no state yet', 'emhttpd', fanboyMirrorFromFlash());
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        // … and when it may not
        $why = [];
        file_put_contents("{$t['root']}/etc/unraid-version", "version=\"7.4.0\"\n");
        $why['unraid'] = fanboyMirrorFromFlash();
        file_put_contents("{$t['root']}/etc/unraid-version", "version=\"7.3.2\"\n");
        alwaysOnTFlash(fn ($f) => ['kernel' => '6.12.99-Unraid'] + $f);
        $why['kernel'] = fanboyMirrorFromFlash();
        file_put_contents($flash, $good);
        alwaysOnTFlash(fn ($f) => ['board' => 'Other Boards Inc. | X1'] + $f);
        $why['board'] = fanboyMirrorFromFlash();
        file_put_contents($flash, substr($good, 0, 300));
        $why['shape'] = fanboyMirrorFromFlash();
        same('always on S6b: … cut: the night shift need not stay for him', false, fanboyNightWanted());
        @unlink($flash);
        $why['none'] = fanboyMirrorFromFlash();
        same('always on S6b: another Unraid version, another kernel, another board, a cut copy, none — no mirror, each with its reason (the BIOS keeps the fans until the array starts)',
            ['unraid' => 'unraid', 'kernel' => 'kernel', 'board' => 'board', 'shape' => 'shape', 'none' => 'none', 'mirror' => false], $why + ['mirror' => file_exists($mirror)]);

        // 4. the night shift after a boot: the mirror, the drivers he had loaded, the control — said once
        file_put_contents("{$t['tmp']}/modprobe", "#!/bin/bash\necho \"\$*\" >> " . escapeshellarg("{$t['tmp']}/modprobe.log") . "\nmkdir -p " . escapeshellarg("{$t['root']}/sys/module") . "/\"\$1\"\n");
        chmod("{$t['tmp']}/modprobe", 0755);
        putenv("OFFICE_MODPROBE_BIN={$t['tmp']}/modprobe");
        file_put_contents($flash, $good);
        alwaysOnTFlash(fn ($f) => ['drivers' => ['nct6683']] + $f);
        unset($GLOBALS['fanboyNightNext'], $GLOBALS['fanboyNightWanted'], $GLOBALS['fanboyNightBootSaid']);
        $GLOBALS['fanboyLaunched'] = [];
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        $wanted = fanboyNight(1000.0);
        fanboyNight(1005.0);
        $log = substr((string) @file_get_contents(AGENT_LOG), $log0);
        same('always on S6b: the night shift\'s look after a boot — the driver he had loaded on this board loaded again, the mirror from the flash, the control started; it stays on for him',
            [true, "nct6683\n", true, ['fanboy-control']], [$wanted, (string) @file_get_contents("{$t['tmp']}/modprobe.log"), fanboyMirrorRead(FANBOY_T_KEY) !== null, $GLOBALS['fanboyLaunched']]);
        same('always on S6b: … said once', 1, substr_count($log, 'after this boot his settings come from their copy on the flash — he steers before the array starts'));
        // a hand-back asked for before the reboot: the mirror comes, the control doesn't
        @unlink($mirror);
        file_put_contents(fanboyHandBackFlash(), '{"v":1,"at":1700000000}');
        unset($GLOBALS['fanboyNightNext'], $GLOBALS['fanboyNightBootSaid']);
        $GLOBALS['fanboyLaunched'] = [];
        fanboyCrashKeep(['launched' => null]);
        fanboyNight(2000.0);
        same('always on S6b: the fans were given back before the reboot (the mark\'s copy on the flash) — no control after it either', [[], true], [$GLOBALS['fanboyLaunched'], fanboyHandBackAsked()]);
        @unlink(fanboyHandBackFlash());
        @unlink(fanboyHandBackFile());
        // a copy from another Unraid version: said once, the night shift need not stay
        @unlink($mirror);
        file_put_contents("{$t['root']}/etc/unraid-version", "version=\"7.4.0\"\n");
        unset($GLOBALS['fanboyNightNext'], $GLOBALS['fanboyNightBootSaid']);
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        $w1 = fanboyNight(3000.0);
        $w2 = fanboyNight(3005.0);
        same('always on S6b: Unraid was updated since the copy was written — no mirror, no control, the night shift need not stay; said once why',
            [false, false, false, 1], [$w1, $w2, file_exists($mirror), substr_count(substr((string) @file_get_contents(AGENT_LOG), $log0), 'was written under another Unraid version — the BIOS runs the fans until the array starts')]);
        file_put_contents("{$t['root']}/etc/unraid-version", "version=\"7.3.2\"\n");
        unset($GLOBALS['fanboyRamMode']);
        putenv('OFFICE_MODPROBE_BIN');

        // 5. the history from before the boot: a control that started before the agent takes what is older than its own
        $wall = 1700000000;
        $c = fanboyControlNew($s2, FANBOY_T_KEY, fanboyMe('control'));
        $c['hist'] = ['disks' => [[$wall - 120, 40.0, 30.0, 800], [$wall - 60, 41.0, 31.0, 810]]];
        $older = ['disks' => [[$wall - 7200, 35.0, 20.0, 600], [$wall - 3600, 36.0, 21.0, 610], [$wall - 60, 99.0, 99.0, 999]], 'gone-zone' => [[$wall - 3600, 30.0, 10.0, 500]]];
        @mkdir(fanboyRunDir(), 0700, true);
        file_put_contents(fanboyRunDir() . '/history-before.json', json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($older)));
        $took = fanboyHistoryBefore($c, $wall);
        same('always on S6b: the history handed over — the points older than its own first one come in front, its own stay as they are, a zone it doesn\'t have is left out, the file is gone',
            [true, [$wall - 7200, $wall - 3600, $wall - 120, $wall - 60], 41.0, false, false],
            [$took, array_column($c['hist']['disks'], 0), end($c['hist']['disks'])[1], isset($c['hist']['gone-zone']), file_exists(fanboyRunDir() . '/history-before.json')]);
        file_put_contents(fanboyRunDir() . '/history-before.json', json_encode(['v' => 1, 'board' => 'Other Boards Inc. | X1', 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($older)));
        same('always on S6b: another board\'s history is not taken (and removed); none there: nothing', [false, false, false, 4],
            [fanboyHistoryBefore($c, $wall), file_exists(fanboyRunDir() . '/history-before.json'), fanboyHistoryBefore($c, $wall), count($c['hist']['disks'])]);
        check('always on S6b: the control looks for it at its 10-second look (only without the data folder)', str_contains($src('fanboy-control.php'), 'if (fanboyRamMode() && fanboyHistoryBefore($c)) {'));
        // the agent's side: this boot's first look at the data folder
        $ramH = fanboyRunDir() . '/history.json';
        $dataH = FANBOY_DIR . '/history.json';
        $pack = fn (array $h) => json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($h));
        @unlink(fanboyRunDir() . '/history-looked');
        @unlink($ramH);
        file_put_contents($dataH, $pack($older));
        same('always on S6b: a boot without a control before the agent — the data folder\'s history is put into RAM as ever', ['seeded', true], [fanboyHistorySync(true), is_file(fanboyRunDir() . '/history-looked')]);
        if (is_dir('/proc/' . getmypid())) {
            // (a control that runs: its pid file names a process of the test's — only where a /proc says when a process started)
            @unlink(fanboyRunDir() . '/history-looked');
            file_put_contents($ramH, $pack($c['hist']));
            touch($dataH, time() - 3600);
            $procs[] = $sleeper = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $sp = (int) proc_get_status($sleeper)['pid'];
            file_put_contents(fanboyRunDir() . '/control.pid', "$sp " . fanboyProcStart($sp));
            $dataBefore = md5_file($dataH);
            same('always on S6b: a boot with a control that started before the agent — at the first look the data folder\'s history is handed to it, not overwritten',
                ['before', true, $dataBefore, md5_file($dataH)], [fanboyHistorySync(true), is_file(fanboyRunDir() . '/history-before.json'), md5_file($dataH), md5((string) file_get_contents(fanboyRunDir() . '/history-before.json'))]);
            same('always on S6b: … once: the next look keeps RAM\'s (ten minutes ahead) in the data folder as ever', 'kept', fanboyHistorySync(true));
            @unlink(fanboyRunDir() . '/control.pid');
            @unlink(fanboyRunDir() . '/history-before.json');
        }

        // 6. decision E7: a fixed value found at a boot's first take where the chip's automatic stood before him
        exec('rm -rf ' . escapeshellarg(TESTS_RUN_DIR . '/fanboy/journal.json'));
        unset($GLOBALS['fanboyInitialDone']);
        file_put_contents((string) fanboyInitialFile(), json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => [
            fanboyTId(4) => ['value' => 77, 'enable' => 2, 'boot' => 'the-boot-before', 'at' => 1],
            fanboyTId(2) => ['value' => 90, 'enable' => 1, 'boot' => 'the-boot-before', 'at' => 1]]]]));
        $log0 = strlen((string) @file_get_contents(AGENT_LOG));
        $procs[] = alwaysOnTHold($t, 4, 'control');         // the boot's first take of each (128, fixed, in the tests' tree)
        $procs[] = alwaysOnTHold($t, 2, 'control');
        $kept = fanboyInitialRead()['boards'][FANBOY_T_KEY];
        same('always on S6b: found fixed (128) where the automatic stood before him — the automatic stays noted (its mode is what «give back» needs), with what was found; an output that was on a fixed value before is kept as found',
            [['value' => 77, 'enable' => 2], 128, ['value' => 128, 'enable' => 1], false],
            [array_intersect_key($kept[fanboyTId(4)], ['value' => 1, 'enable' => 1]), $kept[fanboyTId(4)]['left']['value'] ?? null, array_intersect_key($kept[fanboyTId(2)], ['value' => 1, 'enable' => 1]), isset($kept[fanboyTId(2)]['left'])]);
        // the agent's own carrying over (a control without the data folder took them): the same rule, nothing said twice
        same('always on S6b: … the agent\'s carrying over from the journal goes by the same rule and adds nothing', [0, ['value' => 77, 'enable' => 2]],
            [fanboyInitialSync(), array_intersect_key(fanboyInitialRead()['boards'][FANBOY_T_KEY][fanboyTId(4)], ['value' => 1, 'enable' => 1])]);
        same('always on S6b: … said once in the log, for that output', 1, substr_count(substr((string) @file_get_contents(AGENT_LOG), $log0), fanboyTId(4) . " was found on a fixed value (128) at this boot's first take, where the mainboard's automatic (mode 2) stood before him"));
        unset($GLOBALS['fanboyInitialDone']);
        fanboyInitialSync();
        same('always on S6b: … not again by the agent (an agent started anew at the same boot neither)', 1, substr_count(substr((string) @file_get_contents(AGENT_LOG), $log0), 'was found on a fixed value'));
        // the next boot finds the automatic again (a hand-back at the shutdown, the BIOS at its start): kept as ever, the note gone
        $j = readJson((string) fanboyInitialFile());
        $j['boards'][FANBOY_T_KEY][fanboyTId(4)]['left']['boot'] = 'the-boot-before';
        file_put_contents((string) fanboyInitialFile(), json_encode($j));
        same('always on S6b: … an output found in the automatic is kept as found, the note of the fixed value gone', [true, ['value' => 60, 'enable' => 2], false],
            [fanboyInitialKeep(FANBOY_T_KEY, fanboyTId(4), ['value' => 60, 'enable' => 2]), array_intersect_key(fanboyInitialRead()['boards'][FANBOY_T_KEY][fanboyTId(4)], ['value' => 1, 'enable' => 1]),
             isset(fanboyInitialRead()['boards'][FANBOY_T_KEY][fanboyTId(4)]['left'])]);
        // 7. the plugin's removal takes the copy and the mark's copy off the flash
        check('always on S6b: the plugin\'s removal takes his mirror\'s copy and the hand-back mark\'s copy off the flash',
            str_contains((string) file_get_contents(OFFICE_DIR . '/plugin/unraid-secretary-office.plg'), 'rm -f "$flash"/$name-*.txz "$flash/watchman-mirror.json" "$flash/fanboy-mirror.json" "$flash/fanboy-handback.json"')
            && FANBOY_MIRROR_FLASH === '/boot/config/plugins/unraid-secretary-office/fanboy-mirror.json' && FANBOY_HANDBACK_FLASH === '/boot/config/plugins/unraid-secretary-office/fanboy-handback.json');
    } finally {
        unset($GLOBALS['fanboyRamMode'], $GLOBALS['fanboyFlashSeen'], $GLOBALS['fanboyNightBootSaid'], $GLOBALS['fanboyInitialDone']);
        putenv('OFFICE_MODPROBE_BIN');
        foreach ($procs as $p) {
            alwaysOnTEnd($p);
        }
        alwaysOnTHireEnd($keep);
        fanboyTEnd();
    }
}

// ===================================================================== step S7: what the user sees while the array isn't started

/**
 * The page's «array stopped» notice in node (core.js with a small stand-in DOM, as testUpdateNotice does): the desk's line,
 * its button, the question first, the request, and the notice anew from the answer. Skipped (said) where there is no node.
 */
function alwaysOnTStillNotice(): void
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('always on S7 notice: node is missing here - skipped', true);
        return;
    }
    $tmp = hardeningTmp('ao-still');
    file_put_contents("$tmp/t.js", <<<'JS'
const fs = require('fs');
const byId = {};
const mk = (tag) => {
  const n = { tag, id: '', className: '', style: {}, dataset: {}, hidden: true, textContent: '', offsetHeight: 0, children: [], onclick: null, type: '', disabled: false,
    classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
    appendChild(c) { this.children.push(c); return c; }, append(...c) { this.children.push(...c); }, after() {},
    remove() {}, prepend() {}, setAttribute() {}, removeAttribute() {}, getAttribute: () => null, hasAttribute: () => false, addEventListener() {},
    querySelector: () => null, querySelectorAll: () => [], contains: () => false, closest: () => null, matches: () => false,
    getBoundingClientRect: () => ({ top: 0, bottom: 0, left: 0, width: 0, height: 0 }), focus() {}, select() {} };
  Object.defineProperty(n, 'innerHTML', { get: () => '', set() { n.children = []; n.textContent = ''; } });
  Object.defineProperty(n, 'childNodes', { get: () => n.children });
  return n;
};
const find = (id) => (byId[id] = byId[id] || mk('div'));
const store = {};
const posts = [];
let answer = { ok: false };
globalThis.window = globalThis;
globalThis.innerHeight = 800; globalThis.scrollY = 0; globalThis.scrollBy = () => {}; globalThis.scrollTo = () => {};
globalThis.requestAnimationFrame = () => 0; globalThis.cancelAnimationFrame = () => {};
globalThis.localStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } };
globalThis.navigator = { languages: ['en'] };
globalThis.history = { replaceState() {} };
globalThis.location = { hash: '', reload() {} };
const CONFIG = { version: '1.0.0', desks: [], languages: [{ code: 'en' }], base: '', staff_order: [], csrf: 'token' };
globalThis.document = { getElementById: (id) => (id === 'sso-config' ? { textContent: JSON.stringify(CONFIG) } : find(id)),
  querySelector: (s) => (s[0] === '#' ? find(s.slice(1)) : mk('div')), querySelectorAll: () => [], createElement: (tag) => mk(tag), addEventListener() {}, removeEventListener() {},
  documentElement: { scrollHeight: 0, clientWidth: 1200, clientHeight: 800 }, activeElement: null, hidden: false, body: mk('body') };
globalThis.fetch = async (url, opt) => {
  if (opt && opt.method === 'POST') { posts.push({ body: JSON.parse(opt.body), office: opt.headers['X-Office'], csrf: opt.headers['X-CSRF-Token'] }); }
  const j = opt && opt.method === 'POST' ? answer : { ok: false };
  return { redirected: false, url, ok: true, status: 200, json: async () => j, text: async () => JSON.stringify(j) };
};
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const O = globalThis.Office;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const text = (n) => (typeof n === 'string' ? n : [n.textContent || '', ...(n.children || []).map(text)].join(''));
const buttons = (n) => (typeof n === 'string' ? [] : [...(n.tag === 'button' ? [n] : []), ...(n.children || []).flatMap(buttons)]);
const notice = () => ({ hidden: byId['sso-notice'].hidden, text: text(byId['sso-notice']), buttons: buttons(byId['sso-notice']).map((b) => b.textContent) });
(async () => {
  await sleep(50);
  Object.assign(O.strings, { 'agent.array_stopped_title': 'The array is stopped.', 'agent.array_stopped_text': 'The office waits ({dir}).', 'common.cancel': 'Cancel',
    'agent.night_text': 'Night shift since {since} ({facts}).', 'agent.night_nothing': 'nothing new',
    'fans.still': 'He keeps steering.', 'fans.still_back': 'He has given the fans back.', 'fans.words': 'Give back…', 'fans.words_title': 'Give back?', 'fans.words_text': 'Every fan goes back.', 'fans.words_yes': 'Give back',
    'other.still': 'Another desk works on.' });
  const stopped = { running: false, no_data: { array: 'Stopped', dir: '/x' } };
  const out = {};
  O.setAgent({ ...stopped });
  out.plain = notice();
  O.setAgent({ ...stopped, still: [{ desk: 'fans', ask: { action: 'hand_back', words: 'words' } }, { desk: 'other' }, { desk: 'nostring' }, { desk: 'Bad Name' }, 7, null] });
  out.asks = notice();
  // a desk whose words are not all there gets its sentence, no button; an action that is no name: none either
  O.setAgent({ ...stopped, still: [{ desk: 'other', ask: { action: 'hand_back', words: 'words' } }, { desk: 'fans', ask: { action: 'x.y', words: 'words' } }] });
  out.noWords = notice();
  // with the night watchman on night shift: the same line and button in his notice
  O.setAgent({ running: false, no_data: { array: 'Stopped', dir: '/x' }, night: { since: 1, rounds: 0, new: 0 }, still: [{ desk: 'fans', ask: { action: 'hand_back', words: 'words' } }] });
  out.night = notice();
  // the click: asked first — nothing is sent before the answer
  O.setAgent({ ...stopped, still: [{ desk: 'fans', ask: { action: 'hand_back', words: 'words' } }] });
  buttons(byId['sso-notice'])[0].onclick();
  out.dialog = { title: byId['sso-dialog-title'].textContent, body: text(byId['sso-dialog-body']), buttons: buttons(byId['sso-dialog-foot']).map((b) => b.textContent), sent: posts.length };
  // «Cancel»: nothing sent
  await buttons(byId['sso-dialog-foot'])[0].onclick();
  out.cancelled = posts.length;
  // «Give back»: the request, and the notice anew from the answer's messenger info
  buttons(byId['sso-notice'])[0].onclick();
  answer = { ok: true, handed_back: true, agent: { ...stopped, still: [{ desk: 'fans', state: 'back' }] } };
  await buttons(byId['sso-dialog-foot'])[1].onclick();
  out.posts = posts.slice();
  out.after = notice();
  // refused: said, the dialog stays (act() answered false), the notice as it was
  O.setAgent({ ...stopped, still: [{ desk: 'fans', ask: { action: 'hand_back', words: 'words' } }] });
  buttons(byId['sso-notice'])[0].onclick();
  answer = { ok: false, error: { key: 'bad_request' } };
  const toasts0 = byId['sso-toasts'] ? byId['sso-toasts'].children.length : 0;
  await buttons(byId['sso-dialog-foot'])[1].onclick();
  out.refused = { posts: posts.length, toasts: (byId['sso-toasts'] ? byId['sso-toasts'].children.length : 0) - toasts0, notice: notice().buttons };
  // the array started without the data folder (another notice): no line of this kind
  O.setAgent({ running: false, no_data: { array: 'Started', dir: '/x' }, still: [{ desk: 'fans' }] });
  out.started = notice().text.includes('He keeps steering.');
  console.log(JSON.stringify(out));
  process.exit(0);
})();
JS);
    [, $out, $err] = run([$node, "$tmp/t.js", OFFICE_WEB . '/assets/core.js'], 60);
    $o = json_decode(trim((string) $out), true);
    hardeningRm($tmp);
    if (!is_array($o)) {
        check('always on S7: the notice in node runs', false, substr($out . $err, 0, 600));
        return;
    }
    same('always on S7 notice: the array stopped, nothing of a desk — the notice as before, no button', [false, [], false], [$o['plain']['hidden'], $o['plain']['buttons'], str_contains($o['plain']['text'], 'keeps steering')]);
    same('always on S7 notice: a desk that keeps working gets its sentence, and its button where it may be asked something; a desk without the string, a name that is none, junk: nothing',
        [true, true, ['Give back…'], false], [str_contains($o['asks']['text'], 'The array is stopped. The office waits (/x). He keeps steering.'), str_contains($o['asks']['text'], 'Another desk works on.'), $o['asks']['buttons'], str_contains($o['asks']['text'], 'nostring')]);
    same('always on S7 notice: … words missing or an action that is no name: the sentence, no button', [[], true], [$o['noWords']['buttons'], str_contains($o['noWords']['text'], 'He keeps steering.')]);
    same('always on S7 notice: … the same in the night watchman\'s notice', [['Give back…'], true], [$o['night']['buttons'], str_contains($o['night']['text'], 'He keeps steering.')]);
    same('always on S7 notice: the click asks first — the desk\'s title and text, «Cancel» and its own yes; nothing sent yet, nothing on «Cancel»',
        ['Give back?', 'Every fan goes back.', ['Cancel', 'Give back'], 0, 0], [$o['dialog']['title'], $o['dialog']['body'], $o['dialog']['buttons'], $o['dialog']['sent'], $o['cancelled']]);
    same('always on S7 notice: the yes sends exactly the desk\'s action (X-Office, the csrf token), once', [['body' => ['a' => 'fans.hand_back'], 'office' => '1', 'csrf' => 'token']], $o['posts']);
    same('always on S7 notice: … and the notice follows the answer: «given back», no button any more', [true, []], [str_contains($o['after']['text'], 'He has given the fans back.'), $o['after']['buttons']]);
    same('always on S7 notice: a refusal is said and changes nothing', [2, 1, ['Give back…']], [$o['refused']['posts'], $o['refused']['toasts'], $o['refused']['notice']]);
    same('always on S7 notice: only in the «array stopped» notice — the array started without the data folder says nothing of it', false, $o['started']);
}

/**
 * Step S7: with the array stopped there is no data folder, no agent, no page of his — his Dashboard tile is the view: it
 * shows the running control's numbers (as before), says plainly that the array isn't running and he steers on, that the
 * disks' temperatures are unknown meanwhile, and offers the one thing to ask of him then: «give the fans back to the
 * mainboard» — a mark the web side leaves in his RAM folder, nothing else. The office's «array stopped» notice gets a
 * line of his. (The helper fanboyDashWeb() is tests/fanboy-dash-tests.php's.)
 */
function testAlwaysOnStoppedView(): void
{
    $now = 1795400000;
    $zone = fn (int $unknown, ?array $warm) => ['id' => 'disks', 'name' => 'Disks', 'unit' => 'pct', 'level' => 45.0, 'full' => false, 'warmest' => $warm, 'asleep' => 0, 'unknown' => $unknown,
        'fans' => [['id' => 'it8696:it87.2608:pwm4', 'name' => 'Cage', 'output' => 4, 'pct' => 45, 'rpm' => 900, 'held' => true, 'foreign' => false, 'stands' => false]]];
    $status = fn (?string $disks, array $zones, array $more = []) => $more + ['v' => 1, 'pid' => 4242, 'time' => $now - 2, 'board' => 'Example Boards Inc. | EB-Z100 PRO', 'who' => 'fanboy', 'why' => null, 'phase' => 'run',
        'zones' => $zones, 'alarm' => null, 'watch' => [], 'disks_unknown' => $disks];
    $dash = ['fanboy/dash.json' => ['v' => 1, 'show' => 'speeds']];
    // the web side's own look and its one write, tried in the web side's process (its src directory found by a function of it)
    $more = '(function () { $src = dirname((new ReflectionFunction("officeReadJson"))->getFileName()); require_once "$src/mailbox.php"; require_once "$src/fanboy-stopped.php";'
        . ' $run = officeRunDir(); $stopped = ["running" => false, "no_data" => ["array" => "Stopped", "dir" => "/x"]]; $out = [];'
        . ' $out["running"] = officeFanboyStopped(["running" => true], $run, ' . $now . ');'
        . ' $out["started"] = officeFanboyStopped(["running" => false, "no_data" => ["array" => "Started", "dir" => "/x"]], $run, ' . $now . ');'
        . ' $out["with_data"] = officeFanboyStopped(["running" => false], $run, ' . $now . ');'
        . ' $out["stopped"] = officeFanboyStopped($stopped, $run, ' . $now . ');'
        . ' $out["old"] = officeFanboyStopped($stopped, $run, ' . ($now + 600) . ');'
        . ' $out["info"] = agentInfo()["still"] ?? null; $out["array"] = (string) ((@parse_ini_file("/var/local/emhttp/var.ini") ?: [])["fsState"] ?? "");'
        . ' $out["refused"] = [officeFanboyStoppedHandBack(["running" => true], $run, ' . $now . ')["ok"], officeFanboyStoppedHandBack(["running" => false, "no_data" => ["array" => "Started", "dir" => "/x"]], $run, ' . $now . ')["ok"]];'
        . ' $out["before"] = file_exists("$run/fanboy/handback.json");'
        . ' chmod("$run/fanboy", 0755); $out["open_folder"] = officeFanboyStoppedHandBack($stopped, $run, ' . $now . ')["ok"]; chmod("$run/fanboy", 0700);'
        . ' $out["asked"] = officeFanboyStoppedHandBack($stopped, $run, ' . $now . '); clearstatcache();'
        . ' $out["mark"] = [officeReadJson("$run/fanboy/handback.json"), fileperms("$run/fanboy/handback.json") & 0777, count(glob("$run/fanboy/.*.tmp") ?: [])];'
        . ' $out["again"] = officeFanboyStoppedHandBack($stopped, $run, ' . $now . ')["ok"]; $out["after"] = officeFanboyStopped($stopped, $run, ' . $now . ');'
        . ' $out["info_after"] = agentInfo()["still"] ?? null;'
        . ' return $out; })()';
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => $status('stopped', [$zone(3, null)])], $now, $more);
    $m = $o['model'] ?? [];
    $rows = (string) ($o['rows'] ?? '');
    same('always on S7: the array stopped, the data folder away, his control running — the tile is there and says who steers: Fanboy; no error', ['speeds', true, 'green', ''],
        [$o['gate'] ?? null, $m['steers'] ?? null, $m['tone'] ?? null, $o['err'] ?? null], $o['raw'] ?? '');
    same('always on S7: … the model says «stopped»: the button offered, three disks of unknown temperature', ['back' => true, 'unknown' => 3], $m['stopped'] ?? null);
    check('always on S7: … the rows say it plainly: the array isn\'t running and he steers on, the disks\' temperatures unknown meanwhile (their fans keep a minimum, none stands)',
        str_contains($rows, '<div class="sso-fb-note">The array isn&#039;t running — he steers on. Nobody knows the disks&#039; temperatures meanwhile: their fans keep a minimum speed, none stands.</div>'), $rows);
    check('always on S7: … the zone with only disks: «temperature not known while the array is stopped» — never «asleep», never «its quiet level»; its level and its fan\'s speed as ever',
        ($m['zones'][0]['warmest'] ?? null) === 'temperature not known while the array is stopped' && str_contains($rows, '900 rpm'), json_encode($m['zones'][0] ?? null));
    check('always on S7: … and the one thing to ask of him: the button, with the words the question is asked with',
        (bool) preg_match('/<button type="button" class="sso-fb-back" data-title="Give the fans back to the BIOS\?" data-text="Every fan he steers goes back to the BIOS at once\.[^"]*" data-yes="Give back" data-no="Cancel">Give the fans back to the BIOS…<\/button>/', $rows), $rows);
    same('always on S7: … in German too', ['Temperatur unbekannt, solange das Array gestoppt ist'], [$o['de']['zones'][0]['warmest'] ?? null]);
    // the web side's look
    $w = $o['more'] ?? [];
    same('always on S7: the web side\'s look — nothing to say while the agent works, while the array runs, or with the data folder there', [true, null, null, null], [array_key_exists('with_data', $w), $w['running'], $w['started'], $w['with_data']]);
    same('always on S7: … the array stopped and his control running (its status a few seconds old): he steers', ['desk' => 'fanboy', 'runs' => true, 'steers' => true, 'handed_back' => false], $w['stopped'] ?? null);
    same('always on S7: … a status ten minutes old is no running control: nothing to say', [true, null], [array_key_exists('old', $w), $w['old']]);
    // (on a server whose own array runs the messenger's info has nothing of this: the look goes by Unraid's real var.ini)
    $real = ($w['array'] ?? '') !== 'Started';
    same('always on S7: the office\'s notice gets his line while the array isn\'t started, with the one thing to ask of him — the tile\'s words (here: ' . json_encode($w['array'] ?? null) . ')',
        $real ? [['desk' => 'fanboy', 'ask' => ['action' => 'hand_back', 'words' => 'dash.back']]] : null, $w['info'] ?? null);
    same('always on S7: … once the mark stands the notice says «given back» and asks nothing more', $real ? [['desk' => 'fanboy', 'state' => 'back']] : null, $w['info_after'] ?? null);
    // the one write
    same('always on S7: «give back» is refused while the agent works or the array runs (then it is the agent\'s action), and into a folder that isn\'t root\'s alone — nothing written',
        [[false, false], false, false], [$w['refused'] ?? null, $w['before'] ?? null, $w['open_folder'] ?? null]);
    same('always on S7: … the array stopped, his control running: the mark — a new file in his RAM folder, 0600, {v, at}, nothing left beside it; asked twice is asked',
        [['ok' => true, 'handed_back' => true], 1, 0600, 0, true], [$w['asked'] ?? null, $w['mark'][0]['v'] ?? null, $w['mark'][1] ?? null, $w['mark'][2] ?? null, $w['again'] ?? null]);
    same('always on S7: … and the look says it: the mark stands', true, $w['after']['handed_back'] ?? null);

    // the other states the tile can meet
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => $status('stopped', [$zone(1, ['id' => 'x', 'label' => 'CPU', 'c' => 51.0, 'kind' => 'cpu'])])], $now);
    check('always on S7: a zone with a live source beside its disks shows that source as ever (the note still names the disks)',
        str_contains((string) ($o['model']['zones'][0]['warmest'] ?? ''), '51') && ($o['model']['stopped'] ?? null) === ['back' => true, 'unknown' => 1], json_encode($o['model']['zones'][0]['warmest'] ?? null));
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => $status('stopped', [$zone(0, ['id' => 'x', 'label' => 'CPU', 'c' => 51.0, 'kind' => 'cpu'])])], $now);
    check('always on S7: no disk among his sources: the note without the sentence about disks', str_contains((string) ($o['rows'] ?? ''), '<div class="sso-fb-note">The array isn&#039;t running — he steers on.</div>'), (string) ($o['rows'] ?? ''));
    $o = fanboyDashWeb(['fanboy.json' => ['time' => $now - 5, 'setup' => ['board' => 'Example Boards Inc. | EB-Z100 PRO', 'zones' => [], 'fans' => []], 'who' => ['who' => 'fanboy']],
        'fanboy/dashboard.json' => ['show' => 'speeds'], 'office/staff.json' => ['hired' => ['fanboy' => ['at' => 1]]]], ['fanboy/control.json' => $status('stopped', [$zone(3, null)])], $now);
    same('always on S7: the seconds of a stop with the data folder still there: the note, but no button (his page has «Hand back»)', [['back' => false, 'unknown' => 3], false],
        [$o['model']['stopped'] ?? null, str_contains((string) ($o['rows'] ?? ''), 'sso-fb-back"')], $o['err'] ?? '');
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => $status(null, [$zone(0, null)])], $now);
    same('always on S7: a control that says nothing of unknown disks (the array runs, an older control): no note, no button, the zone\'s words as before',
        [true, null, false, 'no temperature — its quiet level'], [array_key_exists('stopped', $o['model'] ?? []), $o['model']['stopped'], str_contains((string) ($o['rows'] ?? ''), 'sso-fb-note'), $o['model']['zones'][0]['warmest'] ?? null]);
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => $status('stale', [$zone(3, null)])], $now);
    same('always on S7: the array runs but Unraid\'s disk values are old — «temperature not known right now», no note about a stopped array', [true, null, 'temperature not known right now'],
        [array_key_exists('stopped', $o['model'] ?? []), $o['model']['stopped'], $o['model']['zones'][0]['warmest'] ?? null]);
    $o = fanboyDashWeb(null, $dash + ['fanboy/control.json' => ['phase' => 'ended', 'why' => 'hand_back', 'who' => 'bios', 'time' => $now - 3, 'board' => 'x', 'zones' => []]], $now);
    check('always on S7: after «give back»: the BIOS, handed back — no note, no button', ($o['model']['who'] ?? '') === 'The BIOS · handed back' && array_key_exists('stopped', $o['model'] ?? []) && $o['model']['stopped'] === null
        && !str_contains((string) ($o['rows'] ?? ''), 'sso-fb-back"'), json_encode($o['model']['who'] ?? null));

    // the wiring
    $api = (string) file_get_contents(OFFICE_DIR . '/src/api.php');
    check('always on S7: the API hears fanboy.hand_back itself only while there is something of his to say with the array stopped — before «hired» is asked (the staff list lies in the data folder), else the agent\'s action as ever',
        (bool) preg_match('/if \(\$action === \'fanboy\.hand_back\'\) \{\n(?:\s*\/\/[^\n]*\n)*\s+require_once __DIR__ \. \'\/fanboy-stopped\.php\';\n\s+if \(officeFanboyStopped\(\) !== null\) \{\n\s+answer\(officeFanboyStoppedHandBack\(\) \+ \[\'agent\' => agentInfo\(\)\]\);/', $api)
        && strpos($api, "if (\$action === 'fanboy.hand_back') {") < strpos($api, "if (!\$office && !officeIsHired(\$desk)"));
    $tile = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    check('always on S7: the tile\'s script asks first (Unraid\'s dialog where the page has it, the browser\'s else), then sends the wish with the csrf token and loads the rows anew',
        str_contains($tile, 'closest("#sso-fb-dash .sso-fb-back")') && str_contains($tile, 'body:JSON.stringify({a:"fanboy.hand_back"})') && str_contains($tile, 'if(typeof swal==="function"){swal({title:ti,text:tx,type:"warning",showCancelButton:true')
        && str_contains($tile, '}else if(window.confirm(ti+"\\n\\n"+tx)){go();}'));
    $web = (string) file_get_contents(OFFICE_DIR . '/src/fanboy-stopped.php');
    check('always on S7: the web side writes exactly one thing there — the mark (a new file, renamed into place); it starts nothing, signals nobody, touches no fan',
        substr_count($web, 'fopen(') === 1 && substr_count($web, 'rename(') === 1 && !preg_match('/\b(exec|shell_exec|system|passthru|popen|proc_open|posix_kill|file_put_contents)\s*\(/', $web) && stripos($web, 'pwm') === false);
    $core = (string) file_get_contents(OFFICE_WEB . '/assets/core.js');
    check('always on S7: the page\'s «array stopped» notice takes a line from each desk the messenger\'s info names (`still`) — in both notices (with and without the night watchman), no desk named in core.js',
        substr_count($core, 'stillLines(') === 3 && stripos($core, 'fanboy') === false);
    alwaysOnTStillNotice();
    foreach (['en', 'de', 'it', 'fr', 'es'] as $lang) {
        $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$lang.json"), true) ?: [];
        check("always on S7: $lang has his line for the notice and the tile's words (the note, unknown temperatures, the button and its question)",
            isset($j['still'], $j['still_back'], $j['dash.stopped'], $j['dash.stopped_disks'], $j['pic.unknown_stopped'], $j['pic.unknown_now'], $j['dash.back'], $j['dash.back_title'], $j['dash.back_text'], $j['dash.back_yes'], $j['dash.back_no']));
    }
}
