<?php
declare(strict_types=1);

/*
 * The data folder's version, and the ONE place for migrations (2026-10-08, briefs/upgrade-audit.md).
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

