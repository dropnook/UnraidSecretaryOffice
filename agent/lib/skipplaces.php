<?php
declare(strict_types=1);

/*
 * «Leave these places out» — the user's one list of shares, pools and the array the office doesn't look into
 * (1.95.0; a forum wish: «a way to adjust which shares are actually watched and to remove them from the scans»).
 *
 * The list: DATA_DIR/office/skip-places.json, {"v":1, "shares":[…], "pools":[…], "array":bool}. Read with defaults —
 * no file, another shape or a wrong type is the empty list, which is exactly the office before 1.95.0; an older office
 * never reads the file. Written only by Ms. Dustdevil's action `skip_set` (officeSkipSave(): keys it doesn't know kept).
 * A name that no longer exists stays on the list (the share may come back): it is the user's word, not the server's.
 *
 * officeSkipWhy() is the ONE rule; every reader asks it, through it or the thin askers below — never a copy. Today
 * Ms. Dustdevil is its only reader (her du, find, map and folder lists, her rooms, waking for her tour); it lives here,
 * office-wide, so a later desk asks the same thing. docs/DEVELOPMENT.md, «`cleanup` — Ms. Dustdevil»: «Leave places out».
 */

const OFFICE_SKIP_FILE   = 'skip-places.json';            // in DATA_DIR/office
const OFFICE_SKIP_NAME   = '/^(?!\.\.?$)[^\/\x00-\x1f]{1,255}$/D';    // a share or pool name as the list keeps it
const OFFICE_SKIP_MAX    = 500;                            // names per group

function officeSkipFile(): string
{
    return $GLOBALS['officeSkipFile'] ?? DATA_DIR . '/office/' . OFFICE_SKIP_FILE;
}

/**
 * The list, read with defaults: only names in exactly the list's shape count, anything else is left out of it.
 * @return array{shares: list<string>, pools: list<string>, array: bool}
 */
function officeSkipList(?string $file = null): array
{
    $raw = readJson($file ?? officeSkipFile()) ?? [];
    $names = function ($v): array {
        $out = [];
        foreach (is_array($v) && array_is_list($v) ? $v : [] as $n) {
            if (is_string($n) && preg_match(OFFICE_SKIP_NAME, $n) && count($out) < OFFICE_SKIP_MAX) {
                $out[$n] = true;
            }
        }
        $out = array_map('strval', array_keys($out));
        natcasesort($out);
        return array_values($out);
    };
    return ['shares' => $names($raw['shares'] ?? null), 'pools' => $names($raw['pools'] ?? null), 'array' => ($raw['array'] ?? false) === true];
}

/** Nothing left out: the office as before */
function officeSkipEmpty(?array $list = null): bool
{
    $list ??= officeSkipList();
    return !$list['shares'] && !$list['pools'] && !$list['array'];
}

/**
 * THE rule. For a path: why it is left out — 'share' (its share is on the list), 'pool' (it lies on a listed pool),
 * 'array' (on an array disk while the array is listed) — or null. A path through /mnt/user or /mnt/user0 is left out only
 * when its share is (a share partly on a left-out place: the caller knows its parts, see waShareSkip()). Anything outside
 * /mnt (the flash, Docker's folder, RAM) is no place: null. $list: the tests' stand-in, else the file.
 */
function officeSkipWhy(string $path, ?array $list = null): ?string
{
    if (!preg_match('#^/mnt/([^/]+)(?:/([^/]+))?#', $path, $m)) {
        return null;
    }
    $list ??= officeSkipList();
    [$root, $share] = [$m[1], $m[2] ?? null];
    if ($root === 'user' || $root === 'user0') {
        return $share !== null && in_array($share, $list['shares'], true) ? 'share' : null;
    }
    if (in_array($root, ['disks', 'remotes', 'addons', 'rootshare'], true)) {
        return null;                    // Unassigned Devices' mounts and the office's own: no place of Unraid's shares
    }
    if (preg_match('/^disk\d+$/D', $root)) {
        if ($list['array']) {
            return 'array';
        }
    } elseif (in_array($root, $list['pools'], true)) {
        return 'pool';
    }
    return $share !== null && in_array($share, $list['shares'], true) ? 'share' : null;
}

/** Is this share on the list itself? */
function officeShareSkipped(string $share, ?array $list = null): bool
{
    return $share !== '' && officeSkipWhy("/mnt/user/$share", $list) === 'share';
}

/** Is this pool, or this array disk (disk1 …), left out as a whole? */
function officeRootSkipped(string $root, ?array $list = null): bool
{
    return $root !== '' && !str_contains($root, '/') && in_array(officeSkipWhy("/mnt/$root", $list), ['pool', 'array'], true);
}

/**
 * A disk of disks.ini (disk3, parity, cache, cache2 …): does it hold only a left-out place? Its pool is the longest pool name it
 * starts with (cache2 belongs to cache — unless a pool named cache2 exists); $pools: the pool names Unraid knows.
 */
function officeDiskSkipped(string $disk, array $pools, ?array $list = null): bool
{
    if (preg_match('/^disk\d+$/D', $disk)) {
        return officeRootSkipped($disk, $list);
    }
    if (preg_match('/^parity\d*$/D', $disk)) {
        return officeRootSkipped('disk1', $list);         // the array's parity: left out with the array (any array disk stands for it)
    }
    $best = null;
    foreach ($pools as $p) {
        $p = (string) $p;
        if (preg_match('/^' . preg_quote($p, '/') . '\d*$/D', $disk) && ($best === null || strlen($p) > strlen($best))) {
            $best = $p;
        }
    }
    return $best !== null && officeRootSkipped($best, $list);
}

/** The pool names Unraid knows (its pool settings on the flash — also for a pool whose disks sleep) */
function officeSkipPoolNames(string $dir = '/boot/config/pools'): array
{
    $out = [];
    foreach (glob("$dir/*.cfg") ?: [] as $file) {
        $name = basename($file, '.cfg');
        if (preg_match(OFFICE_SKIP_NAME, $name)) {
            $out[] = $name;
        }
    }
    natcasesort($out);
    return array_values($out);
}

/**
 * The request's list (Ms. Dustdevil's `skip_set`): every key there and of its type — a missing key or a wrong type is
 * refused, never read as «nothing left out». Names in the list's shape only.
 * @return array{shares: list<string>, pools: list<string>, array: bool}
 */
function officeSkipRequest(array $r): array
{
    $out = ['array' => boolField($r, 'array')];
    foreach (['shares', 'pools'] as $k) {
        $v = $r[$k] ?? null;
        if (!is_array($v) || !array_is_list($v) || count($v) > OFFICE_SKIP_MAX) {
            throw new Problem('bad_request');
        }
        foreach ($v as $n) {
            if (!is_string($n) || !preg_match(OFFICE_SKIP_NAME, $n)) {
                throw new Problem('bad_request');
            }
        }
        $out[$k] = array_values(array_unique(array_map('strval', $v)));
    }
    return $out;
}

/** Writes the list (keys the office doesn't know kept: a newer office's) and returns it as read back */
function officeSkipSave(array $list, ?string $file = null): array
{
    $file ??= officeSkipFile();
    $old = readJson($file) ?? [];
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    writeAtomic($file, jsonEncode(['v' => 1, 'shares' => array_values($list['shares']), 'pools' => array_values($list['pools']),
                                   'array' => (bool) $list['array']] + $old));
    return officeSkipList($file);
}
