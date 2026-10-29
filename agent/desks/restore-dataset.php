<?php
declare(strict_types=1);

/*
 * «Make it a dataset of its own…» (1.60.1, a user; asked from Mr. Backupsy's setup, run as one of Mr. Restori's jobs —
 * the engine's lock, his journal and run card, never during a backup or a restore). Only a ZFS dataset of its own can
 * go to a partner on its own (snapshots and zfs send work per dataset); Unraid makes a VM's folder a dataset of its
 * own, an app's folder in appdata usually not. The job does what a user did by hand for appdata/drop on a test server:
 *
 *   stop what uses the folder (its containers / its VM) → rename the folder aside (<name>.before-dataset-<stamp>) →
 *   zfs create <parent dataset>/<name> (properties inherited, mounted where the folder was) → rsync -aHAX the aside
 *   folder into it → compare (files and bytes; owner and mode of the top folder as before) → start again what ran →
 *   the aside folder into Ms. Dustdevil's storeroom (a «leftover» of this job: put back or emptied there, her rules).
 *
 * A failure up to the comparison rolls back by itself (mdRollback(), from rsJob()): the new dataset destroyed (only the
 * one this job made), the folder renamed back, what was stopped started — and the journal says what happened.
 * The look (mdLook()) refuses: not on ZFS, a dataset already, child datasets inside, a name ZFS doesn't take, the
 * folder missing, the engine's lock held; and says when the last backup ran — the dialog keeps «Make it» grey without a
 * fresh one until the user says he knows.
 */

const MD_NAME  = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D';      // a folder name ZFS takes as a dataset's last part
const MD_PART  = '/^[A-Za-z0-9][A-Za-z0-9 _.@+-]{0,127}$/D';    // a share's or a folder's name in a request
const MD_RATE  = 150 * 1024 * 1024;                             // bytes per second the guess of the stop counts with
const MD_FRESH = 86400;                                         // a backup younger than this is «fresh»

/** The file systems ZFS has: dataset => mountpoint ($GLOBALS['md']['zfs'] for the tests) */
function mdZfs(): array
{
    if (isset($GLOBALS['md']['zfs'])) {
        return $GLOBALS['md']['zfs'];
    }
    [$exit, $out] = run(['zfs', 'list', '-H', '-o', 'name,mountpoint', '-t', 'filesystem'], 30);
    $out2 = [];
    foreach ($exit === 0 ? explode("\n", trim($out)) : [] as $l) {
        $p = explode("\t", $l);
        if (count($p) === 2 && str_starts_with($p[1], '/')) {
            $out2[$p[0]] = rtrim($p[1], '/');
        }
    }
    return $out2;
}

/**
 * Where a unit's folder lies and what stands in the way: {kind, name, share, dir, path (on its pool), user (in
 * /mnt/user), parent (its share's dataset), dataset (the one to make), blockers [{key, params}]}
 */
function mdTarget(array $r): array
{
    $kind = ($r['kind'] ?? '') === 'vm' ? 'vm' : 'app';
    $name = rsStr($r['name'] ?? '');
    $share = rsStr($r['share'] ?? '');
    $dir = rsStr($r['dir'] ?? '');
    if (!preg_match(MD_PART, $share) || !preg_match(MD_PART, $dir) || !preg_match(MD_PART, $name)) {
        throw new Problem('unknown_target', ['target' => "$share/$dir"]);
    }
    $stack = rsStr($r['stack'] ?? '');
    $t = ['kind' => $kind, 'name' => $name, 'share' => $share, 'dir' => $dir, 'path' => null, 'user' => "/mnt/user/$share/$dir",
          'parent' => null, 'dataset' => null, 'stack' => $kind === 'app' && preg_match(MD_PART, $stack) ? $stack : null, 'blockers' => []];
    $block = function (string $key, array $params = []) use (&$t): array {
        $t['blockers'][] = ['key' => $key, 'params' => $params + ['path' => $t['path'] ?? $t['user']]];
        return $t;
    };
    $zfs = mdZfs();
    $mnt = (string) ($GLOBALS['md']['mnt'] ?? '/mnt');          // the tests' own
    $tops = array_filter($zfs, fn ($mp) => (bool) preg_match('#^' . preg_quote($mnt, '#') . '/[^/]+/' . preg_quote($share, '#') . '$#D', $mp));
    $here = array_filter($tops, fn ($mp) => is_dir("$mp/$dir") && !is_link("$mp/$dir"));
    if (!$here) {
        return $block($tops ? 'md_missing' : 'md_not_zfs');
    }
    if (count($here) > 1) {
        return $block('md_split');
    }
    $t['parent'] = (string) array_key_first($here);
    $t['path'] = $here[$t['parent']] . "/$dir";
    if (in_array($t['path'], $zfs, true)) {
        return $block('md_already');
    }
    if (array_filter($zfs, fn ($mp) => str_starts_with($mp, $t['path'] . '/'))) {
        return $block('md_children');
    }
    if (!preg_match(MD_NAME, $dir)) {
        return $block('md_name', ['name' => $dir]);
    }
    $t['dataset'] = $t['parent'] . "/$dir";
    if (isset($zfs[$t['dataset']])) {
        return $block('md_already');
    }
    $holder = function_exists('backupLockHolder') ? backupLockHolder() : null;
    if ($holder !== null) {
        return $block('md_busy', ['holder' => (string) ($holder['holder'] ?? 'other')]);
    }
    return $t;
}

/** What uses the folder and runs: its containers (and the stack they belong to) or its VM */
function mdStops(array $t): array
{
    if ($t['kind'] === 'vm') {
        $states = array_key_exists('vm_states', $GLOBALS['rs'] ?? []) ? $GLOBALS['rs']['vm_states'] : rsVmStates();
        return ['containers' => [], 'vm' => ($states[$t['name']] ?? '') === 'running' ? $t['name'] : null];
    }
    [$users] = rsUsers(array_values(array_unique([$t['user'], $t['path']])));
    // a Compose stack: never stopped or started by me (its containers' order - depends_on, the database first - is
    // its own tool's); what of it still runs is said, «Make it» waits until the user stopped it there
    if ($t['stack'] !== null) {
        return ['containers' => [], 'vm' => null, 'stack' => $t['stack'], 'running' => array_values($users)];
    }
    return ['containers' => array_values($users), 'vm' => null];
}

/** The folder's size (bytes, null when du takes too long) — the stop's guess counts with it */
function mdBytes(string $path): ?int
{
    if (isset($GLOBALS['md']['bytes'])) {
        return $GLOBALS['md']['bytes'];
    }
    [$exit, $out] = run(['du', '-sxb', $path], 60);
    return $exit === 0 && preg_match('/^(\d+)/', $out, $m) ? (int) $m[1] : null;
}

/**
 * The last real backup (last-run.json): when, how; fresh = ok or warnings within MD_FRESH. With the target (app or VM):
 * `unit` = whether that backup holds it - its package in the run's list (packages.list, kind and name), so a unit new
 * since then isn't told «the last backup went well» as if it were in it (a user, 2026-10-16); null when the run lists no
 * packages (an old engine, a run that stopped early). Not in it = not fresh for this unit.
 */
function mdLastBackup(?array $t = null): ?array
{
    $l = $GLOBALS['md']['last'] ?? (defined('BACKUP_DATA_DIR') ? readJson(BACKUP_DATA_DIR . '/state/last-run.json') : null);
    if (!is_array($l)) {
        return null;
    }
    $time = (int) ($l['finished'] ?? 0) ?: (int) ($l['started'] ?? 0);
    $result = (string) ($l['result'] ?? '');
    $unit = null;
    $list = $l['packages']['list'] ?? null;
    if ($t !== null && is_array($list) && array_is_list($list)) {
        $unit = false;
        foreach ($list as $p) {
            if (is_array($p) && ($p['kind'] ?? null) === $t['kind'] && ($p['name'] ?? null) === $t['name'] && ($p['result'] ?? '') !== 'failed') {
                $unit = true;
                break;
            }
        }
    }
    return ['time' => $time ?: null, 'result' => $result, 'unit' => $unit,
            'fresh' => in_array($result, ['ok', 'warnings'], true) && $time > time() - MD_FRESH && $unit !== false];
}

/** The dialog's facts (backup.dataset_look): the target, what stops and for about how long, the last backup, blockers */
function mdLook(array $r): array
{
    $t = mdTarget($r);
    $stops = $t['blockers'] ? ['containers' => [], 'vm' => null] : mdStops($t);
    if (!empty($stops['running'])) {
        $t['blockers'][] = ['key' => 'md_stack_running', 'params' => ['stack' => (string) $stops['stack'], 'names' => implode(', ', $stops['running'])]];
    }
    $bytes = $t['blockers'] ? null : mdBytes((string) $t['path']);
    return ['ok' => true, 'target' => array_diff_key($t, ['blockers' => 1]), 'blockers' => $t['blockers'], 'stops' => $stops, 'bytes' => $bytes,
            'downtime' => $bytes !== null ? 30 + (int) ceil($bytes / MD_RATE) : null, 'last' => mdLastBackup($t)];
}

/** The job's plan (rsLaunch()): the steps in their order; what stops is started again */
function mdPlan(array $r, bool $noFreshOk = false): array
{
    $look = mdLook($r);
    $t = $look['target'];
    $blockers = $look['blockers'];
    if (!$blockers && !($look['last']['fresh'] ?? false) && !$noFreshOk) {
        $blockers[] = ['key' => 'md_no_fresh_backup', 'params' => []];
    }
    $stamp = date('Ymd-His');
    $aside = $t['path'] !== null ? $t['path'] . ".before-dataset-$stamp" : null;
    $steps = [];
    $stops = $look['stops'];
    if (!empty($stops['stack'])) {
        $steps[] = ['do' => 'md_stopped', 'stack' => $stops['stack'], 'paths' => array_values(array_unique([$t['user'], $t['path']]))];
    }
    if ($stops['containers']) {
        $steps[] = ['do' => 'stop', 'containers' => $stops['containers']];
    }
    if ($stops['vm']) {
        $steps[] = ['do' => 'md_vm_stop', 'name' => $stops['vm']];
    }
    $steps[] = ['do' => 'md_aside', 'path' => $t['path'], 'to' => $aside];
    $steps[] = ['do' => 'md_create', 'dataset' => $t['dataset'], 'path' => $t['path']];
    $steps[] = ['do' => 'md_copy', 'from' => $aside, 'to' => $t['path'], 'bytes' => $look['bytes']];
    $steps[] = ['do' => 'md_compare', 'from' => $aside, 'to' => $t['path']];
    if ($stops['containers']) {
        $steps[] = ['do' => 'start', 'containers' => $stops['containers'], 'only_stopped' => true];
    }
    if ($stops['vm']) {
        $steps[] = ['do' => 'md_vm_start', 'name' => $stops['vm']];
    }
    $steps[] = ['do' => 'md_store', 'path' => $aside, 'from' => $t['path'], 'root' => dirname((string) $t['path'])];
    return ['kind' => 'make_dataset', 'what' => "{$t['share']}/{$t['dir']}", 'target' => $t, 'method' => 'make_dataset', 'stamp' => $stamp,
            'steps' => $steps, 'blockers' => $blockers, 'sizes' => ['need' => $look['bytes']], 'downtime' => $look['downtime'],
            'after' => !empty($stops['stack']) ? [['key' => 'after.md_stack_start', 'params' => ['stack' => $stops['stack']]]] : [],
            'putback_of' => null, 'notes' => [], 'options' => []];
}

/** backup.dataset_make: the plan handed to atd as one of Mr. Restori's jobs */
function mdStart(array $r): array
{
    $plan = mdPlan($r, !empty($r['no_fresh_ok']));
    $id = rsLaunch($plan);
    return ['ok' => true, 'id' => $id];
}

// ---------------------------------------------------------------------------- the job's steps

/** A stack's job: nothing of it may run (the user stopped it in his Compose tool) - looked at again right before */
function mdDoStopped(array &$j, array $s): array
{
    [$users] = rsUsers((array) $s['paths']);
    return $users ? rsFail('md_stack_running', ['stack' => (string) $s['stack'], 'names' => implode(', ', $users)]) : ['state' => 'ok'];
}

function mdDoAside(array &$j, array $s): array
{
    clearstatcache();
    if (!is_dir($s['path']) || is_link($s['path']) || file_exists($s['to'])) {
        return rsFail('md_aside_failed', ['path' => $s['path']]);
    }
    if (!@rename($s['path'], $s['to'])) {
        return rsFail('md_aside_failed', ['path' => $s['path']], (string) (error_get_last()['message'] ?? ''));
    }
    return ['state' => 'ok'];
}

function mdDoCreate(array &$j, array $s): array
{
    [$exit, , $err] = rsRun($j, ['zfs', 'create', (string) $s['dataset']], 120);
    clearstatcache();
    if ($exit !== 0 || !is_dir($s['path'])) {
        if ($exit === 0) {
            rsRun($j, ['zfs', 'destroy', (string) $s['dataset']], 300);      // made but not where it should be: gone again
        }
        return rsFail('md_create_failed', ['dataset' => $s['dataset']], trim($err));
    }
    return ['state' => 'ok', 'created' => true];
}

function mdDoCopy(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $total = (int) ($s['bytes'] ?? 0) ?: null;
    $p = proc_open(['rsync', '-aHAX', '--info=progress2', '--no-inc-recursive', rtrim($s['from'], '/') . '/', rtrim($s['to'], '/') . '/'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', rsDir($j['id']) . '/log.txt', 'a']], $pipes);
    if (!is_resource($p)) {
        return rsFail('copy_failed', ['path' => $s['to']]);
    }
    stream_set_blocking($pipes[1], false);
    $prog = ['done' => 0, 'total' => $total, 'percent' => null];
    $buf = '';
    $read = function () use ($pipes, &$buf, &$prog, $total): array {
        $chunk = is_resource($pipes[1]) ? (string) @fread($pipes[1], 65536) : '';
        if ($chunk !== '') {
            $buf = substr($buf . $chunk, -4096);
            $parts = preg_split('/[\r\n]+/', trim($buf)) ?: [];
            if (preg_match('/^\s*([\d,.]+)\s+(\d+)%/', (string) end($parts), $m)) {
                $prog['done'] = (int) str_replace([',', '.'], '', $m[1]);
                $prog['percent'] = $total ? min(100, (int) floor(100 * $prog['done'] / $total)) : (int) $m[2];
            }
        }
        return $prog;
    };
    $exit = rsWait($j, [$p], $i, $read)[0];
    $j['steps'][$i]['progress'] = $prog;
    return $exit === 0 ? ['state' => 'ok'] : rsFail('copy_failed', ['path' => $s['to'], 'exit' => $exit], 'rsync exit ' . $exit);
}

/** Files and bytes alike on both sides (find, apparent sizes); the top folder's owner and mode as before */
function mdCount(string $dir): array
{
    [$e1, $files] = run(['sh', '-c', 'find "$1" | wc -l', 'sh', $dir], 600);
    [$e2, $bytes] = run(['du', '-sb', '--apparent-size', $dir], 600);
    return [$e1 === 0 ? (int) trim($files) : -1, $e2 === 0 && preg_match('/^(\d+)/', $bytes, $m) ? (int) $m[1] : -1];
}

function mdDoCompare(array &$j, array $s): array
{
    [$f1, $b1] = mdCount((string) $s['from']);
    [$f2, $b2] = mdCount((string) $s['to']);
    $st = @stat((string) $s['from']);
    if ($st) {
        @chown((string) $s['to'], $st['uid']);
        @chgrp((string) $s['to'], $st['gid']);
        @chmod((string) $s['to'], $st['mode'] & 07777);
    }
    // the new dataset's top folder counts once more on its side only when ZFS adds nothing: equal is equal
    if ($f1 < 0 || $f1 !== $f2 || abs($b1 - $b2) > 4096 * max(1, $f1 / 1000)) {
        return rsFail((string) ($s['fail'] ?? 'md_compare_failed'), ['files' => "$f1 / $f2", 'bytes' => "$b1 / $b2"]);
    }
    return ['state' => 'ok', 'params' => ['files' => (string) $f1, 'bytes' => (string) $b1]];
}

function mdDoVm(array &$j, array $s, bool $start): array
{
    if ($start) {
        [$exit, , $err] = rsRun($j, ['virsh', 'start', (string) $s['name']], 60);
        return $exit === 0 ? ['state' => 'ok'] : ['state' => 'warning', 'note' => 'virsh_failed', 'params' => ['name' => $s['name']], 'detail' => trim($err)];
    }
    rsRun($j, ['virsh', 'shutdown', (string) $s['name']], 30);
    for ($t = 0; $t < 120; $t++) {
        $states = rsVmStates();
        if (($states[$s['name']] ?? '') !== 'running') {
            $j['md_vm'] = $s['name'];
            return ['state' => 'ok'];
        }
        if (rsBeat($j)) {
            break;
        }
        sleep(2);
    }
    return rsFail('md_vm_stop_failed', ['name' => $s['name']]);
}

/** The aside folder into Ms. Dustdevil's storeroom on its own filesystem (a «leftover» of this job: put back there) */
function mdDoStore(array &$j, array $s): array
{
    try {
        $run = clRunCreate(rtrim((string) $s['root'], '/') . '/' . CL_TRASH);
        $as = 'restore/' . substr(md5(dirname((string) $s['path'])), 0, 8) . '/' . basename((string) $s['path']);
        clMove((string) $s['path'], $run['path'] . "/$as");
        $run['items'][] = ['kind' => 'leftover', 'name' => basename((string) $s['from']), 'label' => $j['id'], 'from' => $s['path'], 'as' => $as,
                           'dataset' => null, 'bytes' => (int) ($j['sizes']['need'] ?? 0)];
        clManifestWrite($run);
    } catch (Throwable $e) {
        return ['state' => 'warning', 'note' => 'md_store_failed', 'params' => ['path' => $s['path']], 'detail' => $e->getMessage()];
    }
    return ['state' => 'ok', 'params' => ['path' => $run['path'] . "/$as"]];
}

/**
 * After a failure up to the comparison: the job's own dataset destroyed, the folder back in its place, what was
 * stopped started — each said in $j['rollback'] (the journal).
 */
function mdRollback(array &$j): void
{
    $done = fn (string $do) => array_values(array_filter($j['steps'], fn ($s) => $s['do'] === $do && ($s['state'] ?? '') === 'ok'))[0] ?? null;
    $store = $done('md_store');
    if ($store) {
        return;              // the new dataset holds it all: nothing to roll back
    }
    $said = [];
    $create = $done('md_create');
    $aside = $done('md_aside');
    if ($create) {
        [$exit, , $err] = rsRun($j, ['zfs', 'destroy', (string) $create['dataset']], 300);
        $said[] = ['what' => 'destroy', 'ok' => $exit === 0, 'detail' => trim($err)];
    }
    if ($aside) {
        clearstatcache();
        $empty = is_dir($aside['path']) && count(array_diff(@scandir($aside['path']) ?: [], ['.', '..'])) === 0;
        if ($empty) {
            @rmdir($aside['path']);
        }
        $ok = !file_exists($aside['path']) && @rename($aside['to'], $aside['path']);
        $said[] = ['what' => 'back', 'ok' => $ok, 'path' => $aside['path']];
    }
    if ($j['stopped']) {
        $r = rsDoStart($j, ['containers' => $j['stopped']]);
        $said[] = ['what' => 'start', 'ok' => $r['state'] === 'ok'];
    }
    if (!empty($j['md_vm'])) {
        $r = mdDoVm($j, ['name' => $j['md_vm']], true);
        $said[] = ['what' => 'start', 'ok' => $r['state'] === 'ok'];
    }
    $j['rollback'] = $said;
    $j['reason'] = array_filter($said, fn ($x) => !$x['ok']) ? 'md_rollback_incomplete' : 'md_rolled_back';
    rsJournalWrite($j);
}

// ============================================================================ the way back (2026-10-29)

/*
 * «Back into a plain folder…» (asked from Mr. Backupsy's setup beside «Make it a dataset of its own…», run as one of Mr.
 * Restori's jobs like it): an app's folder that is a ZFS dataset of its own becomes a plain folder in its share's dataset
 * again — the data kept all the way:
 *
 *   stop what uses it (its containers; a stack: the user stops it) → zfs rename the dataset aside (<name>.aside-<stamp>,
 *   mounted beside) → rsync -aHAX it into a plain folder in its place → compare (files and bytes; the top folder's owner
 *   and mode) → start again what ran → the dataset aside into Ms. Dustdevil's storeroom (a «leftover» of this job, next to
 *   it with zfs rename — put back there beside the folder, or destroyed only when she empties it).
 *
 * A failure up to the comparison rolls back by itself (mfRollback()): the job's copy into her storeroom (never deleted),
 * the dataset renamed back into its place, what was stopped started. Only an app's folder: a VM's folder in `domains` is a
 * dataset by Unraid's own making and holds the VM manager's snapshots. The look (mfLook()) refuses: not a dataset of its
 * own (a plain folder already), datasets below it (named), a mountpoint of its own, not mounted, not directly in its
 * share's dataset, no room for the copy, a backup, the setup, a restore or Ms. Moverelli's run going on; it names the
 * dataset's snapshots (they go into the storeroom with it — its own snapshots end, the share's carry its files on).
 */

const MF_MARGIN = 1 << 30;              // free room the copy leaves on the pool at least (bytes)

/** The dataset's properties (parsable) — $GLOBALS['md']['props'][$ds] for the tests */
function mfProps(string $ds): array
{
    if (isset($GLOBALS['md']['props'])) {
        return $GLOBALS['md']['props'][$ds] ?? [];
    }
    [$exit, $out] = run(['zfs', 'get', '-Hp', '-o', 'property,value,source', 'mountpoint,mounted,referenced,logicalreferenced,available', $ds], 30);
    $props = [];
    foreach ($exit === 0 ? explode("\n", trim($out)) : [] as $l) {
        $p = explode("\t", $l);
        if (count($p) === 3) {
            $props[$p[0]] = ['value' => $p[1], 'source' => $p[2]];
        }
    }
    return $props;
}

/** The dataset's own snapshots (names after the @) — $GLOBALS['md']['snaps'][$ds] for the tests */
function mfSnapshots(string $ds): array
{
    if (isset($GLOBALS['md']['snaps'])) {
        return $GLOBALS['md']['snaps'][$ds] ?? [];
    }
    [$exit, $out] = run(['zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', '-d', '1', $ds], 30);
    $out2 = [];
    foreach ($exit === 0 ? explode("\n", trim($out)) : [] as $l) {
        if (str_starts_with($l, "$ds@")) {
            $out2[] = substr($l, strlen($ds) + 1);
        }
    }
    return $out2;
}

/** Is a run of Ms. Moverelli's going on (she moves the share's files)? $GLOBALS['md']['moverelli'] for the tests */
function mfMoverelliBusy(): bool
{
    return (bool) ($GLOBALS['md']['moverelli'] ?? (function_exists('moverelliBusy') && moverelliBusy()));
}

/**
 * Where the app's folder lies and what stands in the way: {kind, name, share, dir, path (its mountpoint), user, parent
 * (its share's dataset), dataset (the folder's), bytes (what the copy writes), need (room it takes), snapshots, blockers}
 */
function mfTarget(array $r): array
{
    $name = rsStr($r['name'] ?? '');
    $share = rsStr($r['share'] ?? '');
    $dir = rsStr($r['dir'] ?? '');
    if (($r['kind'] ?? '') !== 'app' || !preg_match(MD_PART, $share) || !preg_match(MD_PART, $dir) || !preg_match(MD_PART, $name)) {
        throw new Problem('unknown_target', ['target' => "$share/$dir"]);
    }
    $stack = rsStr($r['stack'] ?? '');
    $t = ['kind' => 'app', 'name' => $name, 'share' => $share, 'dir' => $dir, 'path' => null, 'user' => "/mnt/user/$share/$dir", 'parent' => null,
          'dataset' => null, 'stack' => preg_match(MD_PART, $stack) ? $stack : null, 'bytes' => null, 'need' => null, 'snapshots' => [], 'blockers' => []];
    $block = function (string $key, array $params = []) use (&$t): array {
        $t['blockers'][] = ['key' => $key, 'params' => $params + ['path' => $t['path'] ?? $t['user']]];
        return $t;
    };
    $zfs = mdZfs();
    $mnt = preg_quote((string) ($GLOBALS['md']['mnt'] ?? '/mnt'), '#');
    $q = preg_quote($share, '#');
    $mine = array_filter($zfs, fn ($mp) => (bool) preg_match("#^$mnt/[^/]+/$q/" . preg_quote($dir, '#') . '$#D', $mp));
    if (!$mine) {
        $tops = array_filter($zfs, fn ($mp) => (bool) preg_match("#^$mnt/[^/]+/$q$#D", $mp));
        $plain = array_filter($tops, fn ($mp) => is_dir("$mp/$dir") && !is_link("$mp/$dir"));
        return $block($plain ? 'mf_plain' : ($tops ? 'md_missing' : 'md_not_zfs'));
    }
    if (count($mine) > 1) {
        return $block('md_split');
    }
    $t['dataset'] = (string) array_key_first($mine);
    $t['path'] = $mine[$t['dataset']];
    $t['parent'] = dirname($t['dataset']);
    if (($zfs[$t['parent']] ?? null) !== dirname($t['path'])) {
        return $block('mf_parent', ['dataset' => $t['dataset']]);
    }
    $kids = array_values(array_filter(array_keys($zfs), fn ($d) => str_starts_with((string) $d, $t['dataset'] . '/')));
    if ($kids) {
        $names = array_map(fn ($d) => substr((string) $d, strlen($t['dataset']) + 1), $kids);
        return $block('mf_children', ['names' => implode(', ', array_slice($names, 0, 8)), 'n' => count($names)]);
    }
    $props = mfProps($t['dataset']);
    if (!preg_match('/^(inherited|default)/', (string) ($props['mountpoint']['source'] ?? 'local'))) {
        return $block('mf_mountpoint', ['dataset' => $t['dataset']]);
    }
    if (($props['mounted']['value'] ?? '') !== 'yes') {
        return $block('mf_unmounted', ['dataset' => $t['dataset']]);
    }
    $t['snapshots'] = mfSnapshots($t['dataset']);
    $ref = (int) ($props['referenced']['value'] ?? 0);
    $t['bytes'] = max($ref, (int) ($props['logicalreferenced']['value'] ?? 0));
    $t['need'] = $ref + MF_MARGIN;
    $free = (int) (mfProps($t['parent'])['available']['value'] ?? 0);
    if ($t['need'] > $free) {
        return $block('mf_room', ['need' => (string) $ref, 'free' => (string) $free]);
    }
    if ($busy = (function_exists('rsBusy') ? rsBusy() : null)) {
        return $block('md_busy', $busy['params'] ?? []);
    }
    if (mfMoverelliBusy()) {
        return $block('mf_moverelli');
    }
    return $t;
}

/** The dialog's facts (backup.folder_look): the target, its snapshots, what stops and for about how long, the last backup */
function mfLook(array $r): array
{
    $t = mfTarget($r);
    $stops = $t['blockers'] ? ['containers' => [], 'vm' => null] : mdStops($t);
    if (!empty($stops['running'])) {
        $t['blockers'][] = ['key' => 'md_stack_running', 'params' => ['stack' => (string) $stops['stack'], 'names' => implode(', ', $stops['running'])]];
    }
    $snaps = $t['snapshots'];
    return ['ok' => true, 'target' => array_diff_key($t, ['blockers' => 1, 'snapshots' => 1]), 'blockers' => $t['blockers'], 'stops' => $stops,
            'bytes' => $t['bytes'], 'downtime' => $t['bytes'] !== null ? 30 + (int) ceil($t['bytes'] / MD_RATE) : null, 'last' => mdLastBackup($t),
            'snapshots' => ['n' => count($snaps), 'engine' => count(array_filter($snaps, fn ($n) => str_starts_with((string) $n, 'uso-backup-'))),
                            'newest' => $snaps ? (string) end($snaps) : null]];
}

/** The job's plan (rsLaunch()): the steps in their order; what stops is started again */
function mfPlan(array $r, bool $noFreshOk = false): array
{
    $look = mfLook($r);
    $t = $look['target'];
    $blockers = $look['blockers'];
    if (!$blockers && !($look['last']['fresh'] ?? false) && !$noFreshOk) {
        $blockers[] = ['key' => 'md_no_fresh_backup', 'params' => []];
    }
    $stamp = date('Ymd-His');
    $steps = [];
    $stops = $look['stops'];
    if (!empty($stops['stack'])) {
        $steps[] = ['do' => 'md_stopped', 'stack' => $stops['stack'], 'paths' => array_values(array_unique([$t['user'], $t['path']]))];
    }
    if ($stops['containers']) {
        $steps[] = ['do' => 'stop', 'containers' => $stops['containers']];
    }
    $aside = $t['path'] !== null ? $t['path'] . ".aside-$stamp" : null;
    $asideDs = $t['dataset'] !== null ? $t['dataset'] . ".aside-$stamp" : null;
    $steps[] = ['do' => 'mf_aside', 'dataset' => $t['dataset'], 'to' => $asideDs, 'path' => $t['path'], 'aside' => $aside];
    $steps[] = ['do' => 'md_copy', 'from' => $aside, 'to' => $t['path'], 'bytes' => $look['bytes']];
    $steps[] = ['do' => 'md_compare', 'from' => $aside, 'to' => $t['path'], 'fail' => 'mf_compare_failed'];
    if ($stops['containers']) {
        $steps[] = ['do' => 'start', 'containers' => $stops['containers'], 'only_stopped' => true];
    }
    $steps[] = ['do' => 'mf_store', 'dataset' => $asideDs, 'path' => $aside, 'root' => $t['path'] !== null ? dirname($t['path']) : null];
    return ['kind' => 'make_folder', 'what' => "{$t['share']}/{$t['dir']}", 'target' => $t, 'method' => 'make_folder', 'stamp' => $stamp,
            'steps' => $steps, 'blockers' => $blockers, 'sizes' => ['need' => $look['bytes']], 'downtime' => $look['downtime'],
            'after' => !empty($stops['stack']) ? [['key' => 'after.md_stack_start', 'params' => ['stack' => $stops['stack']]]] : [],
            'putback_of' => null, 'notes' => [], 'options' => []];
}

/** backup.folder_make: the plan handed to atd as one of Mr. Restori's jobs */
function mfStart(array $r): array
{
    return ['ok' => true, 'id' => rsLaunch(mfPlan($r, !empty($r['no_fresh_ok'])))];
}

/** Is a folder empty (or not there)? */
function mfEmpty(string $path): bool
{
    clearstatcache();
    return !file_exists($path) || (is_dir($path) && !is_link($path) && count(array_diff(@scandir($path) ?: ['x'], ['.', '..'])) === 0);
}

/**
 * The dataset renamed aside (mounted beside as <name>.aside-<stamp>), a plain folder made in its place — looked at again
 * right before: still that dataset there, nothing below it, nothing of that name aside, Ms. Moverelli not moving
 */
function mfDoAside(array &$j, array $s): array
{
    if (mfMoverelliBusy()) {
        return rsFail('mf_moverelli', ['path' => $s['path']]);
    }
    $zfs = mdZfs();
    $kids = array_filter(array_keys($zfs), fn ($d) => str_starts_with((string) $d, $s['dataset'] . '/'));
    if (($zfs[$s['dataset']] ?? null) !== $s['path'] || $kids || isset($zfs[$s['to']]) || file_exists((string) $s['aside'])) {
        return rsFail('mf_aside_failed', ['path' => $s['path']]);
    }
    [$exit, , $err] = rsRun($j, ['zfs', 'rename', (string) $s['dataset'], (string) $s['to']], 120);
    if ($exit !== 0) {
        return rsFail('mf_aside_failed', ['path' => $s['path']], trim($err));
    }
    // back into its place at once (a failure here leaves nothing for mfRollback() to do) - when that fails too, said where it is
    $back = function (string $note) use (&$j, $s): array {
        [$exit, , $err] = rsRun($j, ['zfs', 'rename', (string) $s['to'], (string) $s['dataset']], 120);
        if ($exit === 0 && isset($GLOBALS['md']['zfs'])) {
            $GLOBALS['md']['zfs'][$s['dataset']] = (string) $s['path'];
            unset($GLOBALS['md']['zfs'][$s['to']]);
        }
        return $exit === 0 ? rsFail($note, ['path' => $s['path'], 'dataset' => $s['dataset']])
            : rsFail('mf_back_failed', ['path' => $s['path'], 'to' => $s['to']], trim($err));
    };
    if (isset($GLOBALS['md']['zfs'])) {          // the tests: the stand-in renames the folder, the map follows
        $GLOBALS['md']['zfs'][$s['to']] = (string) $s['aside'];
        unset($GLOBALS['md']['zfs'][$s['dataset']]);
    }
    $now = mdZfs();
    clearstatcache();
    if (($now[$s['to']] ?? null) !== $s['aside'] || !is_dir((string) $s['aside']) || in_array($s['path'], $now, true)) {
        return $back('mf_aside_failed');
    }
    // the folder ZFS left at the old mountpoint: empty - else something lay hidden under the dataset (said, rolled back)
    if (!mfEmpty((string) $s['path'])) {
        return $back('mf_hidden');
    }
    $st = @stat((string) $s['aside']);
    if (!is_dir((string) $s['path']) && !@mkdir((string) $s['path'], $st ? $st['mode'] & 07777 : 0755)) {
        return $back('mf_aside_failed');
    }
    $j['mf_aside'] = ['dataset' => $s['dataset'], 'to' => $s['to'], 'path' => $s['path'], 'aside' => $s['aside']];
    rsJournalWrite($j);
    return ['state' => 'ok', 'params' => ['to' => $s['to']]];
}

/** The dataset aside into Ms. Dustdevil's storeroom of its share, next to it (zfs rename, its snapshots along) */
function mfDoStore(array &$j, array $s): array
{
    try {
        $run = clRunCreate(rtrim((string) $s['root'], '/') . '/' . CL_TRASH);
        $to = dirname((string) $s['dataset']) . '/' . CL_TRASH . '-' . $run['stamp'] . '-' . basename((string) $s['dataset']);
        clZfsRename((string) $s['dataset'], $to, (string) $s['path']);
        $run['items'][] = ['kind' => 'leftover', 'name' => (string) preg_replace('/\.aside-\d{8}-\d{6}$/D', '', basename((string) $s['path'])), 'label' => $j['id'], 'from' => $s['path'], 'as' => "@$to",
                           'dataset' => $s['dataset'], 'bytes' => (int) ($j['sizes']['need'] ?? 0)];
        clManifestWrite($run);
    } catch (Throwable $e) {
        if (isset($run) && !$run['items']) {
            clRunTidy($run['path'], $run['root']);
        }
        return ['state' => 'warning', 'note' => 'mf_store_failed', 'params' => ['path' => $s['path']], 'detail' => $e->getMessage()];
    }
    return ['state' => 'ok', 'params' => ['path' => $to]];
}

/**
 * After a failure up to the comparison: the job's copy into Ms. Dustdevil's storeroom (an empty folder simply gone),
 * the dataset renamed back into its place, what was stopped started — each said in $j['rollback'] (the journal).
 */
function mfRollback(array &$j): void
{
    $done = fn (string $do) => array_values(array_filter($j['steps'], fn ($s) => $s['do'] === $do && ($s['state'] ?? '') === 'ok'))[0] ?? null;
    if ($done('mf_store')) {
        return;              // the plain folder holds it all: nothing to roll back
    }
    $said = [];
    $a = $j['mf_aside'] ?? null;
    if (is_array($a)) {
        $path = (string) $a['path'];
        clearstatcache();
        if (is_dir($path) && !is_link($path) && mfEmpty($path)) {
            @rmdir($path);
        } elseif (file_exists($path)) {
            try {
                $run = clRunCreate(dirname($path) . '/' . CL_TRASH);
                $as = 'restore/' . substr(md5(dirname($path)), 0, 8) . '/' . basename($path) . '.copy-' . $j['id'];
                clMove($path, $run['path'] . "/$as");
                $run['items'][] = ['kind' => 'leftover', 'name' => basename($path), 'label' => $j['id'], 'from' => $path, 'as' => $as, 'dataset' => null, 'bytes' => null];
                clManifestWrite($run);
                $said[] = ['what' => 'copy', 'ok' => true, 'path' => $run['path'] . "/$as"];
            } catch (Throwable $e) {
                $said[] = ['what' => 'copy', 'ok' => false, 'path' => $path, 'detail' => $e->getMessage()];
            }
        }
        clearstatcache();
        $ok = false;
        if (!file_exists($path)) {
            [$exit, , $err] = rsRun($j, ['zfs', 'rename', (string) $a['to'], (string) $a['dataset']], 120);
            if ($exit === 0 && isset($GLOBALS['md']['zfs'])) {
                $GLOBALS['md']['zfs'][$a['dataset']] = $path;
                unset($GLOBALS['md']['zfs'][$a['to']]);
            }
            $ok = $exit === 0 && (mdZfs()[$a['dataset']] ?? null) === $path;
        }
        $said[] = ['what' => 'back', 'ok' => $ok, 'path' => $path];
    }
    if ($j['stopped']) {
        $r = rsDoStart($j, ['containers' => $j['stopped']]);
        $said[] = ['what' => 'start', 'ok' => $r['state'] === 'ok'];
    }
    if (!$said) {
        return;              // nothing changed before the failure (its step put the dataset back itself): the step says it
    }
    $j['rollback'] = $said;
    $j['reason'] = array_filter($said, fn ($x) => !$x['ok']) ? 'mf_rollback_incomplete' : 'mf_rolled_back';
    rsJournalWrite($j);
}
