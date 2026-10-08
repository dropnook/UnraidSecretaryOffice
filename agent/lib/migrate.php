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
    ];
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
    $aside = $base = "$path.before-$to";
    for ($n = 2; @lstat($aside) !== false; $n++) {
        if ($n > 99) {
            return null;
        }
        $aside = "$base-$n";
    }
    return @rename($path, $aside) ? $aside : null;
}
