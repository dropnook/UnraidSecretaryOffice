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

/** The last real backup (last-run.json): when, how; fresh = ok or warnings within MD_FRESH */
function mdLastBackup(): ?array
{
    $l = $GLOBALS['md']['last'] ?? (defined('BACKUP_DATA_DIR') ? readJson(BACKUP_DATA_DIR . '/state/last-run.json') : null);
    if (!is_array($l)) {
        return null;
    }
    $time = (int) ($l['finished'] ?? 0) ?: (int) ($l['started'] ?? 0);
    $result = (string) ($l['result'] ?? '');
    return ['time' => $time ?: null, 'result' => $result, 'fresh' => in_array($result, ['ok', 'warnings'], true) && $time > time() - MD_FRESH];
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
            'downtime' => $bytes !== null ? 30 + (int) ceil($bytes / MD_RATE) : null, 'last' => mdLastBackup()];
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
        return rsFail('md_compare_failed', ['files' => "$f1 / $f2", 'bytes' => "$b1 / $b2"]);
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
