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
        // while another holds the lock: not now, the next tick
        $lock = fopen(fanboyRunDir() . '/mirror.lock', 'c');
        flock($lock, LOCK_EX);
        same('always on S3: the lock held by another writer — «busy», nothing written, the next tick', ['busy', $ino()], [fanboyMirrorKeep(true), $ino()]);
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
