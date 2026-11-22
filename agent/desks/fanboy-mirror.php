<?php
declare(strict_types=1);

/*
 * Fanboy's mirror — what his control needs of the data folder, kept in RAM («always on», 2026-11-22, step S3).
 *
 * His control is to keep steering while the array stops and is stopped; nothing that does may open anything on the array
 * or a pool, and his settings lie in the data folder. So the agent — the only one with the data folder — keeps a mirror
 * of exactly what the control reads there, and whatever runs without the data folder reads only the mirror:
 *
 *   RUN_DIR/fanboy/mirror.json   {"v": 1, "board": "<board key>", "hired": true|false,
 *                                 "settings": <this board's entry of settings.json as written, or null>,
 *                                 "initial": <this board's entry of initial.json — each output's state before him, kept
 *                                             across boots: the control asks it for a chip's automatic mode>,
 *                                 "watch": true|false («watch fans I don't steer»), "sum": "<sha256 of the six>", "at": <time>}
 *
 * The data folder is the truth; the mirror is derived from it and never the other way round. It is a RECONCILIATION, not
 * a write-along: whoever changes a source (the agent, a setup step, the web side hiring or firing, a restore of appdata,
 * a hand) — the agent's tick sees the sources differ from what it mirrored and writes the mirror anew (a look at four
 * files' times and sizes every FANBOY_TICK_EVERY, the whole comparison once a minute; his own saves mirror at once).
 * Readers take it only in exactly its shape, for the board that is there now, its sum right — anything else is no mirror
 * (then the BIOS keeps the fans: never a guess). Never migrated: another `v` is no mirror, the agent writes a new one.
 * New keys of the board's entry come with it as they are (the entry is mirrored whole; readers take what they know).
 *
 * In this step nothing reads it yet. No secrets in it: the entry holds names, roles, curves and chip addresses; RAM, 0600.
 */

const FANBOY_MIRROR_MAX = 256 * 1024;       // bytes: a board's entry larger than this is not mirrored (said in the log)
const FANBOY_MIRROR_FULL_EVERY = 60.0;      // seconds between whole comparisons (the sources read and summed), whatever their times say

function fanboyMirrorFile(): string
{
    return fanboyRunDir() . '/mirror.json';
}

/** The sources as files: his store, the staff list, the watch's setting, the outputs' states before him */
function fanboyMirrorSources(): array
{
    return [fanboyStoreFile(), $GLOBALS['agentStaffFile'] ?? DATA_DIR . '/office/staff.json', fanboyWatchFile(), FANBOY_DIR . '/initial.json'];
}

/** The sources' times and sizes in one word (no inode: through a share's path it isn't steady) */
function fanboyMirrorStamp(): string
{
    $out = [];
    foreach (fanboyMirrorSources() as $f) {
        clearstatcache(true, $f);
        $st = @stat($f);
        $out[] = $st === false ? '-' : "{$st['size']}.{$st['mtime']}.{$st['ctime']}";
    }
    return implode('|', $out);
}

/** The sum over what a mirror says (never over `sum` and `at`) */
function fanboyMirrorSum(array $m): string
{
    return hash('sha256', jsonEncode(['v' => $m['v'] ?? null, 'board' => $m['board'] ?? null, 'hired' => $m['hired'] ?? null,
        'settings' => $m['settings'] ?? null, 'initial' => $m['initial'] ?? null, 'watch' => $m['watch'] ?? null]));
}

/** What the mirror has to say now, from the data folder (the agent's side only) */
function fanboyMirrorBuild(?array $board = null): array
{
    $board ??= fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $key = fanboyBoardKey($board);
    $initial = fanboyInitialRead()['boards'][$key] ?? null;
    $m = ['v' => 1, 'board' => $key, 'hired' => in_array('fanboy', staffHired($GLOBALS['agentStaffFile'] ?? null), true),
          'settings' => fanboyBoardRaw($board), 'initial' => is_array($initial) ? $initial : [], 'watch' => fanboyWatchOn()];
    return $m + ['sum' => fanboyMirrorSum($m)];
}

/**
 * The mirror as a reader may take it: a plain file of the right size, `v` 1, every field of its type, the sum right —
 * and, when $boardKey is given, for that board. Anything else: null (no mirror).
 *
 * @return array{v: int, board: string, hired: bool, settings: ?array, initial: array, watch: bool, sum: string, at?: int}|null
 */
function fanboyMirrorRead(?string $boardKey = null, ?string $file = null): ?array
{
    $file ??= fanboyMirrorFile();
    clearstatcache(true, $file);
    $st = @lstat($file);
    if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > FANBOY_MIRROR_MAX + 4096) {
        return null;
    }
    $m = readJson($file);
    if (!is_array($m) || ($m['v'] ?? null) !== 1 || !is_string($m['board'] ?? null) || !is_bool($m['hired'] ?? null) || !is_bool($m['watch'] ?? null)
        || !array_key_exists('settings', $m) || !($m['settings'] === null || is_array($m['settings'])) || !is_array($m['initial'] ?? null)
        || !is_string($m['sum'] ?? null) || !hash_equals(fanboyMirrorSum($m), $m['sum'])) {
        return null;
    }
    return $boardKey !== null && $m['board'] !== $boardKey ? null : $m;
}

/**
 * The agent's reconciliation (his tick, his start, right after his own saves with $force): the mirror says what the data
 * folder says, or it is written anew. Cheap while nothing changed — the sources' times and sizes and the mirror's own —,
 * the whole comparison when they differ, when the mirror isn't the one this process wrote, every FANBOY_MIRROR_FULL_EVERY
 * and with $force. Under a lock in RAM (a setup step saving at the same moment mirrors too): never two writers at once,
 * and whoever comes second compares against what the first wrote.
 *
 * @return string same (as it should be) · written · too_big (not mirrored: the entry is larger than FANBOY_MIRROR_MAX;
 *                the old mirror is removed) · busy (the lock is held: the next tick) · failed (not writable)
 */
function fanboyMirrorKeep(bool $force = false, ?float $now = null): string
{
    $now ??= fanboyMono();
    $file = fanboyMirrorFile();
    $seen = &$GLOBALS['fanboyMirrorSeen'];
    $seen = is_array($seen) ? $seen : ['stamp' => null, 'own' => null, 'full' => -INF, 'file' => null];
    $stamp = fanboyMirrorStamp();
    $ownNow = function () use ($file): string {
        clearstatcache(true, $file);
        $st = @lstat($file);
        return $st === false ? 'none' : "{$st['ino']}.{$st['size']}.{$st['mtime']}";
    };
    if (!$force && $seen['file'] === $file && $seen['stamp'] === $stamp && $seen['own'] !== null && $seen['own'] === $ownNow() && $now - $seen['full'] < FANBOY_MIRROR_FULL_EVERY) {
        return 'same';
    }
    @mkdir(fanboyRunDir(), 0700, true);
    $lock = @fopen(fanboyRunDir() . '/mirror.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock !== false) {
            fclose($lock);
        }
        return 'busy';
    }
    try {
        $stamp = fanboyMirrorStamp();           // as the sources are now, under the lock
        $m = fanboyMirrorBuild();
        $json = jsonEncode($m + ['at' => time()]);
        $result = 'same';
        if (strlen($json) > FANBOY_MIRROR_MAX) {
            if (@lstat($file) !== false) {
                @unlink($file);
                logLine('Fanboy: his settings on this board are too large to mirror in RAM (' . strlen($json) . ' bytes, at most ' . FANBOY_MIRROR_MAX . ') — no mirror');
            }
            $seen = ['stamp' => $stamp, 'own' => $ownNow(), 'full' => $now, 'file' => $file];
            return 'too_big';
        }
        $have = fanboyMirrorRead(null, $file);
        if ($have === null || $have['sum'] !== $m['sum']) {
            if (is_link($file)) {
                @unlink($file);                 // never through a link
            }
            writeAtomic($file, $json, 0600, 0, 0);
            $result = fanboyMirrorRead(null, $file) !== null ? 'written' : 'failed';
        }
        $seen = ['stamp' => $stamp, 'own' => $result === 'failed' ? null : $ownNow(), 'full' => $now, 'file' => $file];
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ===================================================================== what the control wrote into the data folder itself (step S4a)

/*
 * Until now the control wrote two things into the data folder itself: each output's state before him at its first take of
 * a boot (initial.json, a copy of the journal's) and its history every ten minutes. A control without the data folder
 * can't — so the agent, who has it, carries both over from RAM (his tick). While a control still writes them itself both
 * are no-ops: the copy is there already, the data folder's history never ten minutes behind.
 */

const FANBOY_HISTORY_FILE_MAX = 2 << 20;        // bytes of a history file carried over at most (his RAM folder's own bound)
const FANBOY_HISTORY_SYNC_EVERY = 60.0;         // seconds between looks at the two history files

/**
 * The journal's states before him into the data folder's copy (initial.json, per board): every output of this boot's
 * journal that the copy doesn't have from this boot. Each output once per boot and agent (remembered), so a tick reads
 * only the journal in RAM. Returns how many were kept now.
 */
function fanboyInitialSync(): int
{
    if (fanboyInitialFile() === null) {
        return 0;
    }
    $j = fanboyJournal();
    $done = &$GLOBALS['fanboyInitialDone'];
    if (!is_array($done) || ($done['boot'] ?? null) !== $j['boot'] || ($done['dir'] ?? null) !== FANBOY_DIR . '|' . fanboySysRoot()) {
        $done = ['boot' => $j['boot'], 'dir' => FANBOY_DIR . '|' . fanboySysRoot(), 'ids' => []];
    }
    $new = array_diff_key($j['outputs'], $done['ids']);
    if (!$new) {
        return 0;
    }
    $key = fanboyBoardKey(fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']));
    $kept = fanboyInitialRead()['boards'][$key] ?? [];
    $n = 0;
    foreach ($new as $id => $o) {
        if (is_array($o['initial'] ?? null) && (!is_array($kept[$id] ?? null) || ($kept[$id]['boot'] ?? null) !== $j['boot'])) {
            fanboyInitialKeep($key, (string) $id, $o['initial']);
            $n++;
        }
        $done['ids'][$id] = true;
    }
    return $n;
}

/**
 * The history between RAM and the data folder, a look every FANBOY_HISTORY_SYNC_EVERY ($force: now):
 *   seeded — none in RAM (a boot): the data folder's copy put there, for the control to start from and the page to show
 *   kept   — RAM's is FANBOY_HISTORY_KEEP or more ahead of the data folder's copy (or there is none): copied there
 * Only a plain file of a sane size that is JSON, byte for byte; never through a link. null: nothing to do.
 */
function fanboyHistorySync(bool $force = false, ?float $now = null): ?string
{
    $now ??= fanboyMono();
    if (!$force && $now < ($GLOBALS['fanboyHistorySyncNext'] ?? -INF)) {
        return null;
    }
    $GLOBALS['fanboyHistorySyncNext'] = $now + FANBOY_HISTORY_SYNC_EVERY;
    if (!is_dir(FANBOY_DIR)) {
        return null;
    }
    $ram = fanboyRunDir() . '/history.json';
    $data = FANBOY_DIR . '/history.json';
    $look = function (string $f) {
        clearstatcache(true, $f);
        return @lstat($f);
    };
    $plain = fn ($st): bool => $st !== false && ($st['mode'] & 0170000) === 0100000 && $st['size'] > 0 && $st['size'] <= FANBOY_HISTORY_FILE_MAX;
    $read = function (string $f): ?string {
        $raw = @file_get_contents($f, false, null, 0, FANBOY_HISTORY_FILE_MAX + 1);
        return is_string($raw) && is_array(json_decode($raw, true)) ? $raw : null;
    };
    $r = $look($ram);
    $d = $look($data);
    if ($r === false) {
        if (!$plain($d) || ($raw = $read($data)) === null) {
            return null;
        }
        @mkdir(fanboyRunDir(), 0700, true);
        writeAtomic($ram, $raw, 0644, 0, 0);
        return 'seeded';
    }
    if (!$plain($r) || ($d !== false && (($d['mode'] & 0170000) !== 0100000 || $r['mtime'] - $d['mtime'] < FANBOY_HISTORY_KEEP)) || ($raw = $read($ram)) === null) {
        return null;
    }
    writeAtomic($data, $raw);
    return 'kept';
}
