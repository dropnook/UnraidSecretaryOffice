<?php
declare(strict_types=1);

/*
 * The data folder's version, and the ONE place for migrations (2026-10-08, the upgrade audit).
 *
 * Users update the office through Unraid's plugin manager and read no notes: a new version meets the data folder an
 * older one left (an update; a downgrade = remove + install of an older .plg; a reinstall over a kept data folder).
 * Most files need nothing — every reader takes a missing key as its default and keeps keys it doesn't know (CLAUDE.md
 * «Updates»). Where a shape or a name has to change, a step here does it: once, at the agent's start (setUp(), before
 * any desk reads its state), idempotent (it may run again after a downgrade and back), logged in agent.log, never
 * deleting — what has to go is renamed aside (officeMigrateAside(): <name>.before-<version>). Not the night shift
 * (nothing under /mnt), not the jobs (cron, atd) and not the web side: they may meet the old shape for the moment
 * between an update and the agent's start, so they read tolerantly too.
 *
 * data/office.json — the marker (root writes it, nobody:users like the state files):
 *   version       the office's version that last ran here (AGENT_VERSION)
 *   since         when that version first ran here
 *   updated_from  [{version, at}] the versions before, oldest first (at most OFFICE_MARK_KEEP)
 *   done          ids of the steps whose change the data folder has
 *   pending       ids of steps that failed — tried again at the next start
 *
 * Where the folder comes from ($from, officeMigrateFrom()): the marker's version; without a marker the agent.json the
 * previous agent wrote (every version writes it at its start and stop); neither and no state files: a fresh folder
 * ('') — nothing to change, every step counts as done; state files but neither: '0' (unknown, older than all — every
 * step runs). An agent.json older than the marker means an office from before the marker ran here in between (a
 * downgrade and back): $from is that one and the steps newer than it run again. A step's `version` is the first
 * version that carries it: a folder last used by an older one needs it.
 *
 * Also at every start, beside the steps: officeCronBack() — the schedules the .plg's remove put aside, back after a
 * new install.
 *
 * The steps (officeMigrateSteps()): `marker` (1.42.0, nothing), `where-files` (1.44.0: Ms. Whereabouts' files taken
 * over and put aside — her start did it up to 1.43, deleting them), `staff-merged` (1.44.0: staff.json's merged desks
 * rewritten — the web side did it up to 1.43; it still reads them merged for the moment before the agent's start).
 */

const OFFICE_MARK_FILE = 'office.json';
const OFFICE_MARK_KEEP = 30;
const OFFICE_MARK_VERSION = '/^\d{1,4}(?:\.\d{1,4}){1,3}\z/';

/**
 * The steps, oldest first: id (unique, never reused), version (the first version that carries it), run(string $dir,
 * string $to): string — what it did, for the log; it throws when it couldn't (tried again at the next start).
 *
 * @return list<array{id: string, version: string, run: callable}>
 */
function officeMigrateSteps(): array
{
    return [
        // the first one changes nothing: the data folder gets its marker — the next real step goes below it
        ['id' => 'marker', 'version' => '1.42.0', 'run' => fn (string $dir, string $to): string => 'nothing to change — the data folder carries a version marker now (office.json)'],
        // Ms. Whereabouts' files (up to 1.30) are Ms. Dustdevil's — until 1.43 her start took them over and deleted them
        ['id' => 'where-files', 'version' => '1.44.0', 'run' => fn (string $dir, string $to): string
            => implode(', ', officeMigrateWhere($dir, $to)) ?: 'nothing to change'],
        // staff.json: a desk that went into another one written as that one — until 1.43 the web side rewrote it
        ['id' => 'staff-merged', 'version' => '1.44.0', 'run' => fn (string $dir, string $to): string => officeMigrateStaff($dir, $to)],
        // pairs.json without the units agreed per pair and the receiving window (the Team Lead only pairs; the levels
        // in Mr. Backupsy's setup say what goes, the door takes any unit up to the quota) — agent/lib/partner.php
        ['id' => 'partner-simple', 'version' => '1.60.0', 'run' => function (string $dir, string $to): string {
            require_once __DIR__ . '/partner.php';
            return partnerMigrateSimple($dir);
        }],
        // the pool, simply (agent/lib/poollevel.php, replaces 1.61's cake): her ini gets Y = X − 10 where it has a limit
        // without one, Jack Emby's old slice line goes; her schedule before his is said (the Team Lead's hint says it too)
        ['id' => 'pool-simple', 'version' => '1.62.0', 'run' => fn (string $dir, string $to): string => officeMigratePoolSimple($dir, $to)],
        // the night run (agent/lib/nightrun.php): Jack Emby and Ms. Moverelli on ONE schedule — her line (job moverelli);
        // his EmbyCache line goes into it, «right after Jack Emby» goes away
        ['id' => 'night-run', 'version' => '1.62.0', 'run' => fn (string $dir, string $to): string => officeMigrateNightRun($dir)],
        // only X (2026-10-14, replaces the halves): her ini loses move_until_used_below (global and per share),
        // jack_min_percent and an offset line — the copy before kept beside it
        ['id' => 'pool-x', 'version' => '1.63.0', 'run' => fn (string $dir, string $to): string => officeMigratePoolX($dir, $to)],
        // Jack Streamer's desk id `emby` → `jack` (2026-10-14): staff.json (STAFF_MERGED), his state file, the Team Lead's
        // notes under the old id — each copy before kept beside it
        ['id' => 'jack-id', 'version' => '1.63.0', 'run' => fn (string $dir, string $to): string => officeMigrateJackId($dir, $to)],
        // pairs.json: a partner renamed on its side kept its old name here — the name its last status said (state.json)
        ['id' => 'partner-names', 'version' => '1.64.0', 'run' => function (string $dir, string $to): string {
            require_once __DIR__ . '/partner.php';
            return partnerMigrateNames($dir);
        }],
        // Jack Streamer runs MediaCache (Emby, Jellyfin, Plex) instead of EmbyCache: data/embycache → data/mediacache with
        // MediaCache's file names, the one server typed «emby»; the lists' path in Ms. Moverelli's and the gather's ini
        // follows; the old folder kept as embycache.before-<version>. While an EmbyCache run goes: tried again later
        ['id' => 'mediacache', 'version' => '1.65.0', 'run' => fn (string $dir, string $to): string => officeMigrateMediaCache($dir, $to)],
    ];
}

// Ms. Whereabouts' files (up to 1.30) => Ms. Dustdevil's (agent/lib/where.php WHERE_FILE, WHERE_SIZES_FILE) — names of
// the past, fixed here so the step needs no desk loaded
const OFFICE_MIGRATE_WHERE     = ['whereabouts.json' => 'cleanup-where.json', 'whereabouts-sizes.json' => 'cleanup-where-sizes.json'];
const OFFICE_MIGRATE_WHERE_MAX = 16 << 20;        // an old file larger than this isn't hers

/**
 * Step `where-files`: up to 1.30 Ms. Whereabouts kept her state in whereabouts.json and what du measured in
 * whereabouts-sizes.json; they are Ms. Dustdevil's now. An old file is read only as a plain file of the data folder
 * (never through a link, never a huge one) and written to its new name with writeAtomic(); then it is renamed aside
 * (officeMigrateAside(): <name>.before-<version>) — never deleted. The state goes over only while the new one isn't
 * there (her start reads it anew anyway); the measured sizes are never lost — merged, what was measured later wins. An
 * old file that isn't hers (a link, no JSON) stays where it is, said. Throws when the old file couldn't be put aside
 * (the step runs again at the next start: the merge gives the same result twice).
 *
 * @return list<string> what was done, for the log ("whereabouts.json → cleanup-where.json")
 */
function officeMigrateWhere(string $dir, string $to): array
{
    $done = [];
    foreach (OFFICE_MIGRATE_WHERE as $old => $new) {
        $from = "$dir/$old";
        $dest = "$dir/$new";
        clearstatcache(true, $from);
        $st = @lstat($from);
        if ($st === false) {
            continue;
        }
        if (($st['mode'] & 0170000) !== 0100000 || $st['size'] > OFFICE_MIGRATE_WHERE_MAX) {
            $done[] = "$old left alone (no plain file)";
            continue;
        }
        $data = json_decode((string) @file_get_contents($from), true);
        if (!is_array($data)) {
            $done[] = "$old left alone (no JSON)";
            continue;
        }
        $now = readJson($dest);
        if ($new === OFFICE_MIGRATE_WHERE['whereabouts-sizes.json']) {
            $sizes = [];
            foreach ([(array) ($data['sizes'] ?? []), (array) ($now['sizes'] ?? [])] as $list) {
                foreach ($list as $path => $size) {
                    if (is_string($path) && is_array($size) && (int) ($size['at'] ?? 0) >= (int) ($sizes[$path]['at'] ?? -1)) {
                        $sizes[$path] = $size;
                    }
                }
            }
            writeAtomic($dest, jsonEncode(['sizes' => $sizes, 'queue' => [], 'running' => []]));
        } elseif ($now === null) {
            writeAtomic($dest, jsonEncode($data));
        }
        $aside = officeMigrateAside($from, $to);
        if ($aside === null) {
            throw new RuntimeException("$old taken over, but it could not be put aside");
        }
        $done[] = "$old → $new (the old one kept as " . basename($aside) . ')';
    }
    return $done;
}

/**
 * Step `staff-merged`: data/office/staff.json with every desk that went into another one (src/staff.php
 * OFFICE_DESKS_MERGED, the agent's STAFF_MERGED) written as that one — officeStaffMerged(), the web side's own merge
 * (pure; src/staff.php has nothing but definitions), under the web side's lock (`.staff.lock`); the list as it was
 * kept beside it (<file>.before-<version>). Until 1.43 the web side rewrote the file itself at its first read; it
 * still reads the list merged without writing (officeStaff()) for the moment before this step has run. $desks for the
 * tests (default: the agent's desks()).
 */
function officeMigrateStaff(string $dir, string $to, ?array $desks = null): string
{
    require_once dirname(__DIR__, 2) . '/src/staff.php';
    $desks ??= desks();
    $file = "$dir/office/staff.json";
    clearstatcache(true, $file);
    $st = @lstat($file);
    if ($st === false) {
        return 'nothing to change (no staff list)';
    }
    if (($st['mode'] & 0170000) !== 0100000) {
        return 'office/staff.json left alone (no plain file)';
    }
    $h = @fopen("$dir/office/.staff.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        throw new RuntimeException('office/.staff.lock could not be taken');
    }
    try {
        $raw = (string) @file_get_contents($file);
        $staff = json_decode($raw, true);
        $merged = is_array($staff) ? officeStaffMerged($staff, $desks) : null;
        if ($merged === null) {
            return 'nothing to change';
        }
        $kept = officeMigrateKeep($file, $raw, $to);
        $merged['hired'] = (object) (array) ($merged['hired'] ?? []);
        writeAtomic($file, jsonEncode($merged), 0644, 0, 0);     // as the web side writes it (root, 0644)
        $named = array_merge(array_keys((array) ($staff['hired'] ?? [])), is_array($staff['order'] ?? null) ? $staff['order'] : []);
        $was = array_values(array_intersect(array_keys(OFFICE_DESKS_MERGED), $named));
        return 'office/staff.json: ' . implode(', ', array_map(fn ($o) => $o . ' → ' . OFFICE_DESKS_MERGED[$o], $was)) . ' (the list before kept as ' . basename($kept) . ')';
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function officeMarkFile(?string $dir = null): string
{
    return ($dir ?? DATA_DIR) . '/' . OFFICE_MARK_FILE;
}

/** The marker as far as it is the office's shape (anything else: left out) — [] when there is none */
function officeMarkRead(?string $dir = null): array
{
    $m = readJson(officeMarkFile($dir)) ?? [];
    $ids = fn ($l): array => is_array($l) ? array_values(array_unique(array_filter($l, fn ($x) => is_string($x) && $x !== ''))) : [];
    $from = [];
    foreach (is_array($m['updated_from'] ?? null) ? $m['updated_from'] : [] as $u) {
        if (is_array($u) && is_string($u['version'] ?? null) && preg_match(OFFICE_MARK_VERSION, $u['version'])) {
            $from[] = ['version' => $u['version'], 'at' => (int) ($u['at'] ?? 0)];
        }
    }
    return is_string($m['version'] ?? null) && preg_match(OFFICE_MARK_VERSION, $m['version'])
        ? ['version' => $m['version'], 'since' => (int) ($m['since'] ?? 0), 'updated_from' => $from, 'done' => $ids($m['done'] ?? null),
           'pending' => $ids($m['pending'] ?? null)]
        : [];
}

/**
 * Which version left the data folder: [$from, $between] — $between the older office (from before the marker) that ran
 * here since the marker was written, else null. '' a fresh folder, '0' state files of an unknown version.
 *
 * @return array{0: string, 1: ?string}
 */
function officeMigrateFrom(?string $dir = null): array
{
    $dir ??= DATA_DIR;
    $mark = officeMarkRead($dir)['version'] ?? '';
    $agent = readJson("$dir/agent.json")['version'] ?? null;
    $agent = is_string($agent) && preg_match(OFFICE_MARK_VERSION, $agent) ? $agent : '';
    if ($mark !== '') {
        return $agent !== '' && version_compare($agent, $mark, '<') ? [$agent, $agent] : [$mark, null];
    }
    if ($agent !== '') {
        return [$agent, null];
    }
    foreach (glob("$dir/*.json") ?: [] as $f) {
        if (basename($f) !== OFFICE_MARK_FILE) {
            return ['0', null];
        }
    }
    return ['', null];
}

/**
 * Brings the data folder in $dir from $from to $to: the steps it lacks, then the marker. $between: an office from
 * before the marker ran here since it was written (officeMigrateFrom()). $steps, $log and $now for the tests.
 *
 * @return array{from: string, to: string, ran: list<string>, failed: list<string>, downgrade: bool}
 */
function officeMigrate(string $from, string $to, ?string $dir = null, ?string $between = null, ?array $steps = null, ?callable $log = null, ?int $now = null): array
{
    $dir ??= DATA_DIR;
    $steps ??= officeMigrateSteps();
    $log ??= 'logLine';
    $now ??= time();
    $mark = officeMarkRead($dir);
    $known = array_column($steps, 'id');
    $downgrade = isset($mark['version']) && version_compare($to, $mark['version'], '<');
    // what the folder has: as the marker says — only steps this version knows (after a downgrade a newer one's
    // steps run again when it comes back), none newer than an older office that ran in between; without a marker
    // every step up to the version it comes from (a fresh folder: all of them)
    if ($mark) {
        $done = array_values(array_intersect($mark['done'], $known));
        if ($between !== null) {
            $done = array_values(array_filter($done, function (string $id) use ($steps, $between): bool {
                $s = $steps[array_search($id, array_column($steps, 'id'), true)];
                return version_compare($s['version'], $between, '<=');
            }));
        }
    } else {
        $done = array_column(array_filter($steps, fn ($s) => $from === '' || ($from !== '0' && version_compare($s['version'], $from, '<='))), 'id');
    }
    $done = array_values(array_unique(array_diff($done, $mark['pending'] ?? [])));

    if (!$mark) {
        $log('Data folder: version marker written — office ' . $to . ($from === '' ? ' (a fresh data folder)' : ($from === '0' ? ' (data of an unknown older version)' : " (the agent before: $from)")));
    } elseif ($between !== null) {
        $log("Data folder: office $between (from before the marker) ran here after {$mark['version']} — what came since $between is done again");
    } elseif ($downgrade) {
        $log("Data folder: last used by office {$mark['version']}, newer than this one ($to) — a downgrade: what $to doesn't know stays as it is");
    } elseif ($mark['version'] !== $to) {
        $log("Data folder: office {$mark['version']} → $to");
    }

    $ran = $failed = [];
    foreach ($steps as $s) {
        if (in_array($s['id'], $done, true)) {
            continue;
        }
        try {
            $what = ($s['run'])($dir, $to);
            $done[] = $s['id'];
            $ran[] = $s['id'];
            $log("Data folder: step {$s['id']} ({$s['version']}): $what");
        } catch (Throwable $e) {
            $failed[] = $s['id'];
            $log("Data folder: step {$s['id']} ({$s['version']}) failed: " . $e->getMessage() . ' — tried again at the next start');
        }
    }

    // the versions it came through: the marker's (when another runs now, or an older one ran in between), the one in
    // between; without a marker the agent before
    $history = $mark['updated_from'] ?? [];
    $came = $mark ? array_merge($mark['version'] !== $to || $between !== null ? [$mark['version']] : [], $between !== null ? [$between] : [])
                  : (in_array($from, ['', '0', $to], true) ? [] : [$from]);
    foreach ($came as $v) {
        if (($history ? $history[count($history) - 1]['version'] : null) !== $v) {
            $history[] = ['version' => $v, 'at' => $now];
        }
    }
    $new = [
        'version'      => $to,
        'since'        => ($mark['version'] ?? null) === $to && $between === null && ($mark['since'] ?? 0) > 0 ? $mark['since'] : $now,
        'updated_from' => array_slice($history, -OFFICE_MARK_KEEP),
        'done'         => array_values(array_intersect($known, $done)),
        'pending'      => $failed,
    ];
    if ($new !== $mark) {
        writeAtomic(officeMarkFile($dir), jsonEncode($new));
    }
    return ['from' => $from, 'to' => $to, 'ran' => $ran, 'failed' => $failed, 'downgrade' => $downgrade];
}

/** At the agent's start (setUp()): the folder brought to this version — never stops the agent */
function officeMigrateStart(?string $dir = null, string $to = AGENT_VERSION, ?array $steps = null, ?callable $log = null): ?array
{
    $log ??= 'logLine';
    try {
        [$from, $between] = officeMigrateFrom($dir);
        return officeMigrate($from, $to, $dir, $between, $steps, $log);
    } catch (Throwable $e) {
        $log('Data folder: the version marker could not be kept: ' . $e->getMessage());
        return null;
    }
}

/**
 * For a step: a file or folder that has to make way, renamed aside — <path>.before-<version> (-2, -3 … when taken),
 * never deleted, never through a link (rename() moves the link itself). The new name, or null (nothing there, or
 * the rename failed).
 */
function officeMigrateAside(string $path, string $to): ?string
{
    clearstatcache(true, $path);
    if (@lstat($path) === false) {
        return null;
    }
    $aside = officeMigrateAsideName($path, $to);
    return $aside !== null && @rename($path, $aside) ? $aside : null;
}

/** The free name beside $path for what a step keeps: <path>.before-<version>, -2, -3 … (null: 99 taken) */
function officeMigrateAsideName(string $path, string $to): ?string
{
    $aside = $base = "$path.before-$to";
    for ($n = 2; @lstat($aside) !== false; $n++) {
        if ($n > 99) {
            return null;
        }
        $aside = "$base-$n";
    }
    return $aside;
}

/**
 * For a step that rewrites a file in place: its content before, kept beside it (<path>.before-<version>, a new file of
 * its own — writeAtomic(), never through a link). The name it got; throws when it couldn't (the step runs again).
 */
function officeMigrateKeep(string $path, string $content, string $to): string
{
    $aside = officeMigrateAsideName($path, $to);
    if ($aside === null) {
        throw new RuntimeException('no free name beside ' . basename($path));
    }
    writeAtomic($aside, $content, 0644, 0, 0);
    return $aside;
}

/**
 * The office's schedules after a remove and a new install (2026-10-08): the .plg's remove puts the cron file aside
 * (<file>.removed-<YYYYMMDD-HHMMSS>, an earlier aside making way) instead of deleting it. At the agent's start
 * (setUp(), after the steps, before Ms. Snapshotini writes her line): no cron file but an aside → the aside back in
 * its place (renamed: byte for byte, nothing deleted), Unraid's crontab told (update_cron — right after a fresh
 * install the plugin may not be registered yet: the caretaker's watch-cron look runs update_cron again then), said in
 * agent.log. A cron file there (schedules set since) or no aside: nothing. $apply and $log for the tests.
 *
 * @return ?string  the aside put back, else null
 */
function officeCronBack(string $file = OFFICE_CRON, ?callable $apply = null, ?callable $log = null): ?string
{
    $log ??= 'logLine';
    clearstatcache();
    if (@lstat($file) !== false) {
        return null;
    }
    $asides = array_values(array_filter(glob($file . '.removed-*') ?: [], fn (string $f): bool
        => (bool) preg_match('/\.removed-\d{8}-\d{6}\z/', $f) && is_file($f) && !is_link($f)));
    if (!$asides) {
        return null;
    }
    sort($asides);
    $back = $asides[count($asides) - 1];
    if (!@rename($back, $file)) {
        $log('Schedules: ' . basename($back) . ' (put aside when the plugin was removed) could not be put back');
        return null;
    }
    try {
        ($apply ?? fn () => run(['/bin/bash', '/usr/local/sbin/update_cron'], 30))();      // its first line is no shebang
    } catch (Throwable $e) {
        $log('Schedules: update_cron failed: ' . $e->getMessage());
    }
    $jobs = array_keys(officeCronLines($file));
    $log('Schedules: the plugin was installed again — its schedules from before the removal are back ('
        . basename($back) . ': ' . ($jobs ? implode(', ', $jobs) : 'none the office knows') . ')');
    return $back;
}

/**
 * Step `pool-simple` (1.62.0): her smart_mover.ini in [GLOBAL] — a limit X without a target below it gets
 * move_until_used_below = X − 10; the cake's jack_slice_percent line goes (the whole ini kept beside it first,
 * <name>.before-<version>). Idempotent: an ini with both and no slice line is left alone. (The schedules: step `night-run`.)
 */
function officeMigratePoolSimple(string $dir, string $to): string
{
    $ini = "$dir/moverelli/smart_mover.ini";
    clearstatcache(true, $ini);
    $st = @lstat($ini);
    if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
        return 'nothing to change — Ms. Moverelli is not set up';
    }
    $text = (string) @file_get_contents($ini, false, null, 0, 4 << 20);
    $lines = preg_split('/(?<=\n)/', $text);
    $in = false;
    $x = $y = null;
    $at = null;                 // the line after which Y goes (her limit's)
    $out = [];
    $done = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[(.*)\]\s*$/', $line, $m)) {
            $in = trim($m[1]) === 'GLOBAL';
        } elseif ($in && preg_match('/^\s*jack_slice_percent\s*=/', $line)) {
            $done[] = 'the old slice line removed';
            continue;
        } elseif ($in && preg_match('/^\s*move_when_used_above\s*=\s*(\d{1,3})\s*$/', $line, $m)) {
            $x = (int) $m[1];
            $at = count($out);
        } elseif ($in && preg_match('/^\s*move_until_used_below\s*=\s*(\d{1,3})\s*$/', $line, $m)) {
            $y = (int) $m[1];
        }
        $out[] = $line;
    }
    if ($x !== null && $x > 0 && $x <= 100 && ($y === null || $y <= 0 || $y >= $x) && $at !== null) {
        $new = max(1, $x - 10);
        if ($y !== null) {
            $out = array_map(fn ($l) => preg_match('/^\s*move_until_used_below\s*=/', $l) ? "move_until_used_below=$new\n" : $l, $out);
        } else {
            $line = $out[$at];
            $out[$at] = (str_ends_with($line, "\n") ? $line : "$line\n") . "move_until_used_below=$new\n";
        }
        $done[] = "her target set to $new % (her limit $x % minus 10)";
    }
    $says = '';
    if (!$done) {
        return 'nothing to change' . $says;
    }
    $kept = officeMigrateKeep($ini, $text, $to);
    writeAtomic($ini, implode('', $out), 0600, 0, 0);
    return implode(', ', $done) . ' (the ini before: ' . basename($kept) . ')' . $says;
}

/**
 * Step `night-run` (1.62.0): Jack Emby's EmbyCache and Ms. Moverelli run as ONE night run on her line (job moverelli).
 * Only while she is set up (her ini there — else his line stays: without her he has no real runs anyway). His line and
 * «right after Jack Emby» → his time is the night's; his line and a time of hers → the earlier of the two (said in the
 * log); only his → his; only hers → hers. His line goes; office-schedule.json loses `after` (the file stays). Idempotent.
 * $cron, $apply: the tests' cron file (not applied).
 */
function officeMigrateNightRun(string $dir, string $cron = OFFICE_CRON, bool $apply = true): string
{
    $ini = "$dir/moverelli/smart_mover.ini";
    clearstatcache(true, $ini);
    if (!is_file($ini) || is_link($ini)) {
        return 'nothing to change — Ms. Moverelli is not set up';
    }
    $lines = officeCronLines($cron);
    $his = $lines['embycache'] ?? null;
    $hers = $lines['moverelli'] ?? null;
    $file = "$dir/moverelli/office-schedule.json";
    $sched = readJson($file);
    $after = is_array($sched) && ($sched['after'] ?? null) === 'emby';
    $done = [];
    if ($his !== null) {
        $time = $his;
        if ($hers !== null && !$after) {
            // the earlier of the two: by the time of day for daily lines, else by their next run
            $day = fn (string $c): ?int => preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/D', $c, $m) ? (int) $m[2] * 60 + (int) $m[1] : null;
            $now = time();
            $time = $day($hers) !== null && $day($his) !== null ? ($day($hers) < $day($his) ? $hers : $his)
                : ((cronNext($hers, $now) ?? PHP_INT_MAX) < (cronNext($his, $now) ?? PHP_INT_MAX) ? $hers : $his);
            $done[] = "two schedules merged into one: the earlier ($time)";
        } else {
            $done[] = "Jack Streamer's time ($his) is the night run's";
        }
        officeJobSetSchedule('moverelli', $time, $cron, $apply);
        officeJobSetSchedule('embycache', null, $cron, $apply);
    }
    if (is_array($sched) && array_key_exists('after', $sched)) {
        unset($sched['after']);
        if ($his === null && $after) {
            $done[] = '«right after Jack Streamer» without a time of his — no schedule';
        }
        writeAtomic($file, jsonEncode($sched), 0600, 0, 0);
        $done[] = '«right after Jack Streamer» removed';
    }
    return $done ? implode(', ', $done) : 'nothing to change';
}

/**
 * Step `pool-x` (1.63.0): Ms. Moverelli keeps only her limit X — in her smart_mover.ini every move_until_used_below
 * (in [GLOBAL] and in a share's section), jack_min_percent and offset line goes (Smart Mover then fills down to below
 * X); every age_stat goes and [GLOBAL] gets age_stat=newest (always the younger date); the whole ini kept beside it first (<name>.before-<version>). Every other byte stays. Idempotent: an ini without
 * those lines is left alone. Jack Emby's own settings are his step's.
 */
function officeMigratePoolX(string $dir, string $to): string
{
    $ini = "$dir/moverelli/smart_mover.ini";
    clearstatcache(true, $ini);
    $st = @lstat($ini);
    if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
        return 'nothing to change — Ms. Moverelli is not set up';
    }
    $text = (string) @file_get_contents($ini, false, null, 0, 4 << 20);
    $out = [];
    $gone = [];
    $newest = (bool) preg_match('/^\s*age_stat\s*=\s*newest\s*$/m', $text);     // the office's value there already: kept, once
    $once = false;
    foreach (preg_split('/(?<=\n)/', $text) as $line) {
        if ($newest && !$once && preg_match('/^\s*age_stat\s*=\s*newest\s*$/', $line)) {
            $once = true;
        } elseif (preg_match('/^\s*(move_until_used_below|jack_min_percent|offset|age_stat)\s*=/', $line, $m)) {
            $gone[$m[1]] = ($gone[$m[1]] ?? 0) + 1;
            continue;
        }
        $out[] = $line;
        // always the younger date (2026-10-14): age_stat=newest right under [GLOBAL]
        if (!$newest && preg_match('/^\s*\[GLOBAL\]\s*$/', $line)) {
            $out[] = "age_stat=newest\n";
            $newest = true;
            $gone['age_stat=newest added'] = 1;
        }
    }
    if (!$gone) {
        return 'nothing to change';
    }
    $kept = officeMigrateKeep($ini, $text, $to);
    writeAtomic($ini, implode('', $out), 0600, 0, 0);
    return 'only her limit X stays — removed: ' . implode(', ', array_map(fn ($k, $n) => $n > 1 ? "$k (×$n)" : $k, array_keys($gone), $gone))
        . ' (the ini before: ' . basename($kept) . ')';
}

/**
 * Step `jack-id` (1.63.0): Jack Streamer's desk id was `emby` (EmbyCache and «Emby» the media server keep theirs).
 * staff.json: hired and the order as `jack` (officeMigrateStaff(), STAFF_MERGED); the desk's state file emby.json →
 * jack.json (the old one put aside, never over a jack.json there); the Team Lead's notes (caretaker/acks.json): a sig
 * `emby:<id>:<hash>` → `jack:…`, a finding id `emby_<x>` (his and Ms. Moverelli's) → `jack_<x>` — the hash stays (it
 * is over the texts, not their keys). Run twice it finds nothing left to change.
 */
function officeMigrateJackId(string $dir, string $to): string
{
    $done = [];
    $staff = officeMigrateStaff($dir, $to);
    if (!str_starts_with($staff, 'nothing') && !str_contains($staff, 'left alone')) {
        $done[] = $staff;
    }
    $old = "$dir/emby.json";
    $new = "$dir/jack.json";
    clearstatcache();
    $st = @lstat($old);
    if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
        if (@lstat($new) === false) {
            writeAtomic($new, (string) @file_get_contents($old), 0644, 0, 0);
        }
        $done[] = 'emby.json → jack.json (the old one put aside as ' . basename((string) officeMigrateAside($old, $to)) . ')';
    }
    $file = "$dir/caretaker/acks.json";
    $st = @lstat($file);
    $raw = $st !== false && ($st['mode'] & 0170000) === 0100000 ? (string) @file_get_contents($file) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($data) && is_array($data['acks'] ?? null)) {
        $acks = [];
        $n = 0;
        foreach ($data['acks'] as $sig => $a) {
            $to2 = preg_replace(['/^emby:/', '/^([a-z][a-z0-9_-]*):emby_/'], ['jack:', '$1:jack_'], (string) $sig);
            if (is_array($a)) {
                if (($a['desk'] ?? null) === 'emby') {
                    $a['desk'] = 'jack';
                }
                if (is_string($a['id'] ?? null) && str_starts_with($a['id'], 'emby_')) {
                    $a['id'] = 'jack_' . substr($a['id'], 5);
                }
            }
            $n += $to2 !== (string) $sig ? 1 : 0;
            $acks[$to2] = $a;
        }
        if ($n > 0) {
            $kept = officeMigrateKeep($file, $raw, $to);
            $data['acks'] = $acks;
            writeAtomic($file, jsonEncode($data), 0644, 0, 0);
            $done[] = "caretaker/acks.json: $n note(s) under jack (the file before kept as " . basename($kept) . ')';
        }
    }
    return implode('; ', $done) ?: 'nothing to change';
}

// ===================================================================== EmbyCache → MediaCache (1.65.0)

// the files of EmbyCache's data folder that MediaCache names its own way; anything else keeps its name (office-*.json,
// status.json, the progress, an import's or an update's copies of other files)
const OFFICE_MIGRATE_MC_NAMES = ['embycache_settings.json' => 'mediacache_settings.json', 'embycache_exclude.txt' => 'mediacache_exclude.txt',
                                 'embycache_origin.json' => 'mediacache_origin.json'];
const OFFICE_MIGRATE_MC_SKIP  = ['embycache.lock', '.embycache_settings.trial.json', 'app'];   // a lock, a half-written trial, a clone of long ago
const OFFICE_MIGRATE_MC_LISTS = ['moverelli/smart_mover.ini', 'gather/consolidate.ini', 'gather/measure.ini'];   // name Jack's list (and log)

/**
 * Step `mediacache` (1.65.0). Jack Streamer's data folder was data/embycache (EmbyCache's files embycache_*), now it is
 * data/mediacache (mediacache_*). An install that never had Jack's folder: nothing. Otherwise, once: the old folder copied
 * (plain files and folders only, never through a link; the current log as logs/mediacache.log — the rotated ones stay in
 * the old folder), the settings' servers typed `emby` (MediaCache's default, written out), the list's path in Ms.
 * Moverelli's and the gather's ini rewritten (each file before kept beside it), then the old folder renamed aside
 * (embycache.before-<version>) — never deleted. While an EmbyCache run goes (its lock held, its process there) it throws:
 * the step stays pending and runs again (the agent's next start, or Jack's next look — officeMigrateRetry()). A copy left
 * half done by a crash (mediacache.partial) is put aside, never reused.
 *
 * $o (the tests): running (callable: does a run of EmbyCache go?)
 */
function officeMigrateMediaCache(string $dir, string $to, array $o = []): string
{
    $old = "$dir/embycache";
    $new = "$dir/mediacache";
    clearstatcache();
    $st = @lstat($old);
    if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
        return 'nothing to change (Jack Streamer had no EmbyCache folder here)';
    }
    if (@lstat($new) !== false) {
        // done before (a downgrade and back, or a crash after the copy): the old folder only put aside
        $aside = officeMigrateAside($old, $to);
        return 'data/mediacache is there already — data/embycache put aside as ' . basename((string) $aside);
    }
    $running = $o['running'] ?? function () use ($old): bool {
        if (function_exists('flockHeld') && is_file("$old/embycache.lock") && flockHeld("$old/embycache.lock")) {
            return true;
        }
        [$exit] = run(['pgrep', '-f', '[e]mbycache_run\.py'], 10);
        return $exit === 0;
    };
    if ($running()) {
        throw new RuntimeException('an EmbyCache run is going — tried again once it has ended');
    }
    $partial = "$dir/mediacache.partial";
    if (@lstat($partial) !== false && officeMigrateAside($partial, $to) === null) {
        throw new RuntimeException('a half-done copy (mediacache.partial) could not be put aside');
    }
    $n = officeMigrateCopyTree($old, $partial, '');
    // the settings: every server typed (MediaCache's default «emby», written out — the setup lists them by type)
    $cfgFile = "$partial/mediacache_settings.json";
    $servers = 0;
    if (is_file($cfgFile) && !is_link($cfgFile) && is_array($cfg = json_decode((string) file_get_contents($cfgFile), true))) {
        // the give-back through MediaCache's own way (Unraid's move binary): EmbyCache's «rsync» was Jack's force, never a
        // choice on his page (owner, 2026-10-15); «back to the disk they came from» is gone, on or off (owner, 2026-10-16) —
        // his old origin file stays as it was, unused
        if (($cfg['cleanup_tool'] ?? null) === 'rsync') {
            $cfg['cleanup_tool'] = 'mover';
        }
        unset($cfg['return_to_origin']);
        foreach ((array) ($cfg['instances'] ?? []) as $k => $i) {
            if (is_array($i)) {
                $cfg['instances'][$k]['type'] = is_string($i['type'] ?? null) && $i['type'] !== '' ? $i['type'] : 'emby';
                $servers++;
            }
        }
        writeAtomic($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0600, 0, 0);
    }
    if (!@rename($partial, $new)) {
        throw new RuntimeException('the copy could not be renamed to data/mediacache');
    }
    // the list's (and the log's) path where other files name it: every form of it (the data folder's, the share's)
    $pairs = [];
    foreach (['embycache_exclude.txt' => 'mediacache_exclude.txt', 'logs/embycache.log' => 'logs/mediacache.log'] as $a => $b) {
        foreach (array_unique(["$old/$a", function_exists('dataPathUser') ? dataPathUser("$old/$a") : "$old/$a"]) as $form) {
            $pairs[$form] = substr($form, 0, -strlen("embycache/$a")) . "mediacache/$b";
        }
    }
    $rewritten = [];
    foreach (OFFICE_MIGRATE_MC_LISTS as $rel) {
        $f = "$dir/$rel";
        $fs = @lstat($f);
        if ($fs === false || ($fs['mode'] & 0170000) !== 0100000) {
            continue;
        }
        $text = (string) file_get_contents($f);
        $next = strtr($text, $pairs);
        if ($next !== $text) {
            officeMigrateKeep($f, $text, $to);
            writeAtomic($f, $next, $fs['mode'] & 0777, $fs['uid'], $fs['gid']);
            $rewritten[] = $rel;
        }
    }
    $aside = officeMigrateAside($old, $to);
    return "data/embycache → data/mediacache ($n files, " . ($servers === 1 ? '1 server' : "$servers servers") . ' typed emby'
        . ($rewritten ? '; the list\'s path in ' . implode(', ', $rewritten) : '') . '; the old folder kept as '
        . basename((string) $aside) . ')';
}

/** A folder copied for a step: plain files and folders (mode, owner, times kept), never a link; EmbyCache's names → MediaCache's. Count of files */
function officeMigrateCopyTree(string $from, string $to, string $rel): int
{
    $st = lstat($from);
    if (!@mkdir($to, $st['mode'] & 07777) && !is_dir($to)) {
        throw new RuntimeException("cannot make $to");
    }
    @chown($to, $st['uid']);
    @chgrp($to, $st['gid']);
    $n = 0;
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || ($rel === '' && in_array($name, OFFICE_MIGRATE_MC_SKIP, true))) {
            continue;
        }
        $src = "$from/$name";
        $s = @lstat($src);
        if ($s === false) {
            continue;
        }
        $type = $s['mode'] & 0170000;
        if ($type === 0040000) {
            $n += officeMigrateCopyTree($src, "$to/$name", "$rel$name/");
            continue;
        }
        if ($type !== 0100000) {
            continue;                                   // a link, a socket …: never followed
        }
        if ($rel === 'logs/') {
            if ($name !== 'embycache.log') {
                continue;                               // the rotated logs stay in the old folder
            }
            $target = 'mediacache.log';
        } else {
            $target = OFFICE_MIGRATE_MC_NAMES[$name] ?? null;
            if ($target === null) {
                // an import's copy (embycache_exclude.txt.before-import-…) keeps its tail, else the name stays
                $target = preg_match('/^embycache_(settings\.json|exclude\.txt|origin\.json)(\..+)$/D', $name, $m) ? "mediacache_$m[1]$m[2]" : $name;
            }
        }
        $dst = "$to/$target";
        if (!@copy($src, $dst)) {
            throw new RuntimeException("cannot copy $rel$name");
        }
        @chmod($dst, $s['mode'] & 07777);
        @chown($dst, $s['uid']);
        @chgrp($dst, $s['gid']);
        @touch($dst, $s['mtime']);
        $n++;
    }
    return $n;
}

/**
 * A step that is pending (it threw: e.g. `mediacache` while an EmbyCache run went) run again outside the agent's start —
 * Jack's look calls it, so his data comes over once the run has ended, not only at the next start. Nothing when the step
 * isn't pending. True when it ran now.
 */
function officeMigrateRetry(string $id, ?string $dir = null, string $to = AGENT_VERSION, ?array $steps = null, ?callable $log = null): bool
{
    $dir ??= DATA_DIR;
    $log ??= 'logLine';
    $mark = officeMarkRead($dir);
    if (!$mark || !in_array($id, $mark['pending'] ?? [], true)) {
        return false;
    }
    $steps ??= officeMigrateSteps();
    $s = $steps[array_search($id, array_column($steps, 'id'), true)] ?? null;
    if ($s === null) {
        return false;
    }
    try {
        $what = ($s['run'])($dir, $to);
    } catch (Throwable $e) {
        return false;                                   // still pending; said once at the start already
    }
    $mark['pending'] = array_values(array_diff($mark['pending'], [$id]));
    $mark['done'] = array_values(array_unique(array_merge($mark['done'], [$id])));
    writeAtomic(officeMarkFile($dir), jsonEncode($mark));
    $log("Data folder: step {$s['id']} ({$s['version']}): $what");
    return true;
}
