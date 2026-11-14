<?php
declare(strict_types=1);

/*
 * A ZFS snapshot's content read through a mount of the office's own, never through `.zfs/snapshot/<name>` — functions
 * only, loaded by restore.php (task/drill-automount, 2026-11-14).
 *
 * Why: every look into `<mountpoint>/.zfs/snapshot/<name>/…` automounts that snapshot, and OpenZFS 2.4.4 (Unraid 7.4.0)
 * keeps each automounted snapshot in two trees, by full name and by objset. When a snapshot's full name changes while
 * the tree still holds it under the old one (a `zfs rename` of the snapshot or its dataset while it was automounted and
 * its superblock lived on — another mount of it, a container's copy of the mount), the first automount under the new
 * name adds the same objset a second time: `VERIFY(avl_find(...) == NULL)` in `zfsctl_snapshot_mount()`, a kernel panic
 * (Tower 2026-11-14 09:51: a drill's look at a snapshot the scenario base had renamed two minutes earlier). Only the
 * automount adds to those trees; a mount by hand (`mount -t zfs -o ro <dataset>@<snap> <dir>`) never does, so the
 * office reads snapshots only that way — docs/DEVELOPMENT.md «Snapshot automounts».
 *
 * The rules:
 *   - rsSnapReal() maps a path into a snapshot (`<mountpoint>/.zfs/snapshot/<name>/<rest>`, the form his lists keep and
 *     show) to the same file under the snapshot mounted read-only (RSP_MOUNT_OPTIONS) at
 *     `/mnt/addons/UnraidSecretaryOffice/snapread/p<pid>-<hash>` (RAM, nothing directly in /mnt). Any other path (a btrfs
 *     moment's folder, what Kopia brought back, a live path, a dataset mounted outside /mnt — the tests' trees) comes back
 *     as it is. null: it can't be read now (its pool asleep — never mounted then, the pool is never woken —, the
 *     dataset not mounted, the snapshot gone, the mount failed).
 *   - Each mount is held for what reads in it (a tag): `look` (a «does it hold» look, the moments, the versions, a
 *     browse — let go at the end of that function and in every round of his tick), `job` (a drill's step, a restore's
 *     step — let go when the step ends), `plan` (an earlier version's package while a plan is made — let go when the plan
 *     is made; the plan names the snapshot's paths again, rsSnapReadBack()), `du` (a size measured in the background —
 *     let go when its du ended). The last tag let go unmounts it; a shutdown function lets go of everything (a job
 *     stopped by TERM or USR1 ends the same way).
 *   - What a killed job or a crashed agent left (the pid in the folder's name no longer an agent.php): taken down by
 *     rsSnapReadSweep() at the agent's start, at every job's start and from his tick (once a minute, one look at a RAM
 *     folder); at the array stop agent.sh's snapread_release() unmounts everything under the folder (lazily when busy) —
 *     the stop never waits for one.
 */

const RS_SNAPREAD_ROOT  = '/mnt/addons/UnraidSecretaryOffice/snapread';
const RS_SNAPREAD_DIR   = '/^p([1-9]\d{0,9})-([0-9a-f]{16})$/D';             // p<pid>-<first 16 hex of sha256(snapshot)>
const RS_SNAPREAD_PATH  = '#^(/[^\0\n]+?)/\.zfs/snapshot/([^/\0\n]+)(/[^\0\n]*)?$#D';
const RS_SNAPREAD_NAME  = '/^[A-Za-z0-9_.:+ -]{1,255}$/D';                    // a snapshot's short name as ZFS allows it (no /, no @)
const RS_SNAPREAD_EVERY = 60;                                                 // s: his tick's look for leftovers

function rsSnapReadRoot(): string
{
    return $GLOBALS['rs']['snapread_root'] ?? RS_SNAPREAD_ROOT;
}

/** Only datasets mounted under this folder are read through a mount of the office's own (tests: their own /mnt) */
function rsSnapReadMnt(): string
{
    return $GLOBALS['rs']['snapread_mnt'] ?? '/mnt';
}

/** This process's mounts: snapshot => {dir, top (the snapshot's .zfs folder, as planned), tags: tag => true} */
function &rsSnapReadMine(): array
{
    static $mine = [];
    return $mine;
}

/**
 * The path $path (inside a ZFS snapshot as `.zfs/snapshot/<name>/…`) under a mount of the office's own, held for $tag;
 * any other path as it is; null when it can't be read now. $asleep: sleepingDisks() (read when not given).
 */
function rsSnapReal(string $path, string $tag = 'look', ?array $asleep = null): ?string
{
    $w = rsSnapReadWhich($path, $asleep);
    if (!is_array($w)) {
        return $w;
    }
    $dir = rsSnapReadMount($w['snapshot'], $w['top'], $tag);
    return $dir === null ? null : $dir . $w['rest'];
}

/**
 * Which snapshot a path lies in: {snapshot: <dataset>@<name>, top: its .zfs folder, rest: the path inside}; the path
 * itself when it isn't one to mount (not under .zfs/snapshot, or of a dataset outside /mnt); null when it can't be read
 * now (not a mounted dataset there, its pool asleep, a name ZFS wouldn't give).
 */
function rsSnapReadWhich(string $path, ?array $asleep = null): array|string|null
{
    if (!preg_match(RS_SNAPREAD_PATH, $path, $m) || !under($m[1], rsSnapReadMnt())) {
        return $path;
    }
    [, $mp, $name] = $m;
    $rest = $m[3] ?? '';
    if (!preg_match(RS_SNAPREAD_NAME, $name) || str_contains("$rest/", '/../') || str_contains("$rest/", '/./')) {
        return null;
    }
    $ds = null;
    foreach (rspMounts() as $d => $points) {
        if (!str_contains((string) $d, '@') && in_array($mp, $points, true)) {
            $ds = (string) $d;
            break;
        }
    }
    if ($ds === null || baseAsleep(explode('/', $ds)[0], $asleep ?? sleepingDisks())) {
        return null;                    // not a mounted dataset here, or its pool asleep: never mounted, never woken
    }
    return ['snapshot' => "$ds@$name", 'top' => "$mp/.zfs/snapshot/$name", 'rest' => $rest];
}

/** The snapshot mounted read-only at a folder of the office's own (once per process), held for $tag; its folder or null */
function rsSnapReadMount(string $snapshot, string $top, string $tag): ?string
{
    $mine = &rsSnapReadMine();
    if (isset($mine[$snapshot])) {
        $mine[$snapshot]['tags'][$tag] = true;
        return $mine[$snapshot]['dir'];
    }
    $root = rsSnapReadRoot();
    clearstatcache();
    if (!is_dir($root) && !is_link($root)) {
        @mkdir($root, 0700, true);
        clearstatcache();
    }
    if (is_link($root) || !is_dir($root)) {
        return null;
    }
    $dir = "$root/p" . getmypid() . '-' . substr(hash('sha256', $snapshot), 0, 16);
    if (is_link($dir) || file_exists($dir) && !is_dir($dir) || !is_dir($dir) && !@mkdir($dir, 0700)) {
        return null;
    }
    if (!in_array($dir, rspMounts()[$snapshot] ?? [], true)
        && run([partnerBin('mount') ?? 'mount', '-t', 'zfs', '-o', RSP_MOUNT_OPTIONS, $snapshot, $dir], 60)[0] !== 0) {
        @rmdir($dir);
        return null;                    // the snapshot gone (or no mount of it possible): nothing read, nothing left
    }
    if (!$mine) {
        register_shutdown_function(fn () => rsSnapReadRelease('*'));
    }
    $mine[$snapshot] = ['dir' => $dir, 'top' => $top, 'tags' => [$tag => true]];
    return $dir;
}

/** What $tag held let go ('*': everything); a mount nothing holds any more unmounted, its folder gone */
function rsSnapReadRelease(string $tag = 'look'): void
{
    $mine = &rsSnapReadMine();
    foreach ($mine as $snapshot => $x) {
        if ($tag === '*') {
            $x['tags'] = [];
        } else {
            unset($x['tags'][$tag]);
        }
        if ($x['tags']) {
            $mine[$snapshot]['tags'] = $x['tags'];
            continue;
        }
        rsSnapReadUnmount($x['dir']);
        unset($mine[$snapshot]);
    }
}

/** The mount a path of a snapshot is read under, let go by $tag (the measuring of a size ended) */
function rsSnapReadLetGo(string $path, string $tag): void
{
    $w = rsSnapReadWhich($path, []);
    $mine = &rsSnapReadMine();
    if (!is_array($w) || !isset($mine[$w['snapshot']])) {
        return;
    }
    unset($mine[$w['snapshot']]['tags'][$tag]);
    if (!$mine[$w['snapshot']]['tags']) {
        rsSnapReadUnmount($mine[$w['snapshot']]['dir']);
        unset($mine[$w['snapshot']]);
    }
}

/** This process's mounts => the snapshots' .zfs folders, for rsSnapReadBack() */
function rsSnapReadBackMap(): array
{
    return array_column(rsSnapReadMine(), 'top', 'dir');
}

/** One folder of ours unmounted (lazily when something still reads in it — never left holding the pool) and removed */
function rsSnapReadUnmount(string $dir): bool
{
    if (dirname($dir) !== rsSnapReadRoot() || !preg_match(RS_SNAPREAD_DIR, basename($dir))) {
        return false;
    }
    $umount = partnerBin('umount') ?? 'umount';
    $ok = true;
    foreach (rspMounts() as $points) {
        if (in_array($dir, $points, true) && run([$umount, $dir], 60)[0] !== 0 && run([$umount, '-l', $dir], 60)[0] !== 0) {
            $ok = false;
        }
    }
    if ($ok) {
        @rmdir($dir);
    }
    return $ok;
}

/** Is the process that mounted there still one of the office's (agent.php: the agent or one of its jobs)? */
function rsSnapReadAlive(int $pid): bool
{
    if (isset($GLOBALS['rs']['snapread_alive'])) {
        return ($GLOBALS['rs']['snapread_alive'])($pid);
    }
    return $pid === getmypid() || str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php');
}

/**
 * What a killed job or a crashed agent left mounted there taken down (lazily when busy) and its folders removed — never
 * one of a live job's. The number taken down; one line in the agent's log when there were any.
 */
function rsSnapReadSweep(): int
{
    $root = rsSnapReadRoot();
    clearstatcache(true, $root);
    if (is_link($root) || !is_dir($root)) {
        return 0;
    }
    $dirs = [];
    foreach (@scandir($root) ?: [] as $n) {
        if (preg_match(RS_SNAPREAD_DIR, $n)) {
            $dirs[$n] = true;
        }
    }
    foreach (rspMounts() as $points) {
        foreach ($points as $p) {
            if (dirname($p) === $root && preg_match(RS_SNAPREAD_DIR, basename($p))) {
                $dirs[basename($p)] = true;
            }
        }
    }
    $mine = array_column(rsSnapReadMine(), 'dir');
    $n = 0;
    foreach (array_keys($dirs) as $d) {
        preg_match(RS_SNAPREAD_DIR, (string) $d, $m);
        $pid = (int) $m[1];
        if ($pid === getmypid() ? in_array("$root/$d", $mine, true) : rsSnapReadAlive($pid)) {
            continue;
        }
        if (rsSnapReadUnmount("$root/$d")) {
            $n++;
        }
    }
    if ($n) {
        logLine("Mr. Restori: $n snapshot mount(s) a stopped job left under $root taken down");
    }
    return $n;
}

/** His tick: the sweep once a minute, and only when the folder has anything in it (one look at a RAM folder) */
function rsSnapReadTick(): void
{
    $now = hrtime(true) / 1e9;
    if ($now - ($GLOBALS['rs']['snapread_swept'] ?? -INF) < RS_SNAPREAD_EVERY) {
        return;
    }
    $GLOBALS['rs']['snapread_swept'] = $now;
    $root = rsSnapReadRoot();
    if (is_dir($root) && !is_link($root) && count(@scandir($root) ?: []) > 2) {
        rsSnapReadSweep();
    }
}

/**
 * A restore step with every path into a ZFS snapshot (`<mountpoint>/.zfs/snapshot/<name>/…`, in any field, also in a
 * list) under a mount of the office's own, held for `job` (let go when the step ends); $back maps those mounts to the
 * snapshots' folders as planned (rsSnapReadBack()); $gone: the first path that can't be read now (its pool asleep, the
 * snapshot gone — nothing mounted for it).
 *
 * @param-out array<string, string> $back
 */
function rsSnapReadStep(array $s, ?array &$back, ?string &$gone): array
{
    $back = [];
    $gone = null;
    $walk = function (mixed $v) use (&$walk, &$back, &$gone): mixed {
        if (is_array($v)) {
            return array_map($walk, $v);
        }
        if (!is_string($v) || $gone !== null || !preg_match(RS_SNAPREAD_PATH, $v, $m)) {
            return $v;
        }
        $top = "$m[1]/.zfs/snapshot/$m[2]";
        $real = rsSnapReal($top, 'job');
        if ($real === null) {
            $gone = $v;
            return $v;
        }
        if ($real !== $top) {
            $back[$real] = $top;
        }
        return $real . ($m[3] ?? '');
    };
    return $walk($s);
}

/** Paths under the office's snapshot mounts named again as his plans name them (the snapshot's `.zfs` folder) */
function rsSnapReadBack(mixed $v, array $back): mixed
{
    if (is_array($v)) {
        return array_map(fn ($x) => rsSnapReadBack($x, $back), $v);
    }
    if (is_string($v)) {
        foreach ($back as $real => $top) {
            if ($v === $real || str_starts_with($v, "$real/")) {
                return $top . substr($v, strlen((string) $real));
            }
        }
    }
    return $v;
}
