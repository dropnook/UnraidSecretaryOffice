<?php
declare(strict_types=1);

/*
 * The partner door — the forced command of a pair's line in /boot/config/ssh/root/authorized_keys:
 *
 *   restrict,from="<partner>",command="…/scripts/partner-door.sh <pair id>" ssh-ed25519 AAAA… uso-partner:<pair id>
 *
 * Whatever the partner's ssh asks for, this runs (as root, cwd /, umask 077, an empty environment but PATH and the two
 * SSH_ variables — scripts/partner-door.sh). The request arrives as SSH_ORIGINAL_COMMAND and is taken word by word:
 *
 *   ping                              {"ok":true,"v","pair","array","night","time"} — from RAM and the flash only (the
 *                                     array may be stopped: nothing under /mnt is touched)
 *   status                            the public summary (needs the data folder)
 *   quota                             {"ok":true,"bytes","used_bytes"} of the pair's dataset here
 *   list <unit>                       the snapshots kept here of that unit
 *   resume <unit>                     {"ok":true,"token":<receive_resume_token>|null}
 *   recv <unit> <snap> [<from>|-t]    stdin → mbuffer → zfs recv -s -u into <pool>/UnraidSecretaryOffice-partners/<id>/<unit>;
 *                                     JSON on stderr before reading and after; the receiver's retention afterwards
 *   send-back <unit> <snap> [<from>]  stage 3 — not yet: {"ok":false,"why":"not_yet"}
 *
 * Never a path from the client, never -F, never a destroy but this office's own retention on the pair's datasets,
 * never a shell. Every unit is checked against the pair's agreement (pairs.json receive.units), every snapshot name
 * against exactly uso-backup-YYYYMMDD-HHMM. Refusals: one line in data/partner/door.log (RAM when the data folder is
 * away), their times in RUN_DIR/partner/refused-<id>.json (for the night watchman). Exit 0 ok, 1 refused or failed,
 * 2 a request that isn't one.
 *
 * Environment (tests only — partner-door.sh starts it with none of these): OFFICE_DATA_DIR, OFFICE_RUN_DIR,
 * OFFICE_PARTNER_BIN (stand-ins for zfs, zpool, mbuffer), OFFICE_VAR_INI, OFFICE_DISKS_INI, OFFICE_PARTNER_NOW.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
chdir('/');
umask(0077);

const DOOR_VAR_INI = '/var/local/emhttp/var.ini';
const DOOR_ARRAY_RUNNING = ['Started', 'Formatting', 'Clearing'];      // one definition with agent.php's ARRAY_RUNNING
const FILE_UID = 0;
const FILE_GID = 0;
const LOG_MAX = 512 * 1024;
const DOOR_REFUSED_KEEP = 3600;

require dirname(__DIR__) . '/src/place.php';
define('RUN_DIR', officeRunDir());
define('OFFICE_DIR', dirname(__DIR__));
define('DATA_DIR_USER', rtrim(getenv('OFFICE_DATA_DIR') ?: officePluginDataDir(), '/'));

/** The array runs? var.ini (RAM) — the door never looks under /mnt before it knows */
function doorArrayRunning(): bool
{
    $ini = getenv('OFFICE_VAR_INI') ?: DOOR_VAR_INI;
    return preg_match('/^fsState="?([A-Za-z]+)"?\s*$/m', (string) @file_get_contents($ini, false, null, 0, 65536), $m) === 1
        && in_array($m[1], DOOR_ARRAY_RUNNING, true);
}

$GLOBALS['doorWords'] = doorWords((string) getenv('SSH_ORIGINAL_COMMAND'));
$verb = $GLOBALS['doorWords'][0] ?? '';
// ping never touches /mnt: the data folder's path is only a string then
define('DATA_DIR', $verb !== 'ping' && doorArrayRunning() ? officeUnraidPath(DATA_DIR_USER) : DATA_DIR_USER);
if (getenv('OFFICE_DISKS_INI')) {
    $GLOBALS['disksIni'] = getenv('OFFICE_DISKS_INI');
}

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/mounts.php';
require __DIR__ . '/lib/backupscript.php';
require __DIR__ . '/lib/partner.php';

/**
 * The request's words: at most five, single spaces, nothing but [A-Za-z0-9._:-] — anything else (a newline, a ;, a
 * quote, a slash, a tab, two spaces) is no request. Null: not one.
 */
function doorWords(string $cmd): ?array
{
    if ($cmd === '' || strlen($cmd) > 256 || !preg_match('/^[A-Za-z0-9._:-]+(?: [A-Za-z0-9._:-]+){0,4}$/D', $cmd)) {
        return null;
    }
    return explode(' ', $cmd);
}

function doorNow(): int
{
    $t = getenv('OFFICE_PARTNER_NOW');
    return $t !== false && ctype_digit($t) ? (int) $t : time();
}

/** One JSON line on stdout (or stderr: recv's answers) */
function doorSay(array $answer, bool $err = false): void
{
    fwrite($err ? STDERR : STDOUT, jsonEncode($answer) . "\n");
}

function doorClient(): string
{
    $c = explode(' ', (string) getenv('SSH_CONNECTION'))[0];
    return filter_var($c, FILTER_VALIDATE_IP) ? $c : '-';
}

/** A line of the door's log: data/partner/door.log while the data folder is there, else RAM */
function doorLog(string $id, string $text): void
{
    $dir = doorArrayRunning() && is_dir(DATA_DIR) ? partnerDir() : partnerRunDir();
    $clean = substr((string) preg_replace('/[^\x20-\x7e]/', '?', $text), 0, 300);
    partnerAppend("$dir/door.log", date('Y-m-d H:i:s') . "  $id " . doorClient() . " $clean\n");
}

/** A refusal: said, logged, its time kept for the night watchman (refused-<id>.json, the last hour) */
function doorRefuse(string $id, string $why, bool $err = false, array $more = []): int
{
    doorSay(['ok' => false, 'why' => $why] + $more, $err);
    $cmd = (string) getenv('SSH_ORIGINAL_COMMAND');
    doorLog($id, "refused $why: " . ($cmd === '' ? '(nothing)' : $cmd));
    if (preg_match(PARTNER_ID_RE, $id) && partnerDirReady(partnerRunDir())) {
        $file = partnerRunDir() . "/refused-$id.json";
        $old = json_decode((string) @file_get_contents($file, false, null, 0, 65536), true);
        $times = array_values(array_filter((array) ($old['times'] ?? []), fn ($t) => is_int($t) && $t > doorNow() - DOOR_REFUSED_KEEP));
        $times[] = doorNow();
        try {
            writeAtomic($file, jsonEncode(['pair' => $id, 'times' => array_slice($times, -50), 'last' => $why]), 0600, 0, 0);
        } catch (Throwable $e) {
        }
    }
    return $why === 'malformed' || $why === 'unknown_verb' ? 2 : 1;
}

/** The partner knocked (any verb that came through): RUN_DIR/partner/heard-<id>, RAM — for the mutual watch */
function doorHeard(string $id): void
{
    if (partnerDirReady(partnerRunDir())) {
        $f = partnerRunDir() . "/heard-$id";
        if (!is_link($f)) {
            @touch($f);
        }
    }
}

/** The office's version, from the agent's code in RAM */
function doorVersion(): string
{
    return preg_match("/^const AGENT_VERSION = '([0-9.]+)';/m", (string) @file_get_contents(OFFICE_DIR . '/agent/agent.php', false, null, 0, 16384), $m) ? $m[1] : '0.0.0';
}

/** Is the night shift on? Its lock's pid living as agent.php nightshift (like officeNightShift(), RAM only) */
function doorNight(): bool
{
    $pid = trim((string) @file_get_contents(RUN_DIR . '/nightshift.lock', false, null, 0, 16));
    return preg_match('/^[1-9][0-9]{0,9}$/D', $pid) === 1
        && str_contains((string) @file_get_contents("/proc/$pid/cmdline", false, null, 0, 4096), "agent.php\0nightshift");
}

// ===================================================================== zfs

/** @return array<string, string>|null  property => value of a dataset (or snapshot), null: it isn't there */
function doorZfsGet(string $target, string $props): ?array
{
    [$exit, $out] = run([partnerBin('zfs') ?? 'zfs', 'get', '-H', '-p', '-o', 'property,value', $props, $target], 60);
    if ($exit !== 0) {
        return null;
    }
    $got = [];
    foreach (rows($out) as $r) {
        $got[$r[0]] = $r[1] ?? '';
    }
    return $got;
}

/** @return list<array{name:string, holds:int, used:int, referenced:int, creation:int}>  a dataset's snapshots, oldest first */
function doorSnaps(string $ds): array
{
    [$exit, $out] = run([partnerBin('zfs') ?? 'zfs', 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name,userrefs,used,referenced,creation', '-s', 'creation', '-d', '1', $ds], 60);
    $snaps = [];
    foreach ($exit === 0 ? rows($out) : [] as $r) {
        if (count($r) >= 5 && str_starts_with($r[0], "$ds@")) {
            $snaps[] = ['name' => substr($r[0], strlen($ds) + 1), 'holds' => num($r[1]), 'used' => num($r[2]), 'referenced' => num($r[3]), 'creation' => num($r[4])];
        }
    }
    return $snaps;
}

function doorPoolThere(string $pool): bool
{
    [$exit, $out] = run([partnerBin('zpool') ?? 'zpool', 'list', '-H', '-o', 'name'], 20);
    return $exit === 0 && in_array($pool, explode("\n", trim($out)), true);
}

/** <pool>/UnraidSecretaryOffice-partners and <pool>/…/<id>: plain datasets, never mounted; the quota on the pair's */
function doorParents(string $pool, string $id, int $quotaGb): bool
{
    $zfs = partnerBin('zfs') ?? 'zfs';
    $base = "$pool/" . PARTNER_PARENT;
    $pairDs = "$base/$id";
    foreach ([$base, $pairDs] as $ds) {
        if (doorZfsGet($ds, 'type') !== null) {
            continue;
        }
        $cmd = [$zfs, 'create', '-o', 'mountpoint=none', '-o', 'canmount=off'];
        if ($ds === $pairDs && $quotaGb > 0) {
            array_push($cmd, '-o', "quota={$quotaGb}G");
        }
        $cmd[] = $ds;
        if (run($cmd, 60)[0] !== 0) {
            return false;
        }
    }
    $want = $quotaGb > 0 ? (string) ($quotaGb * 1024 ** 3) : '0';
    $have = doorZfsGet($pairDs, 'quota')['quota'] ?? null;
    if ($have !== null && $have !== $want) {
        run([$zfs, 'set', 'quota=' . ($quotaGb > 0 ? "{$quotaGb}G" : 'none'), $pairDs], 60);
    }
    return true;
}

// ===================================================================== the verbs

function doorMain(array $argv): int
{
    $id = (string) ($argv[1] ?? '');
    if (!preg_match(PARTNER_ID_RE, $id)) {
        return doorRefuse('-', 'no_pair');
    }
    $w = $GLOBALS['doorWords'];
    if ($w === null) {
        return doorRefuse($id, 'malformed');
    }
    $verb = $w[0];
    $n = count($w);
    $arity = ['ping' => [1, 1], 'status' => [1, 1], 'quota' => [1, 1], 'list' => [2, 2], 'resume' => [2, 2], 'recv' => [3, 4], 'send-back' => [3, 4]];
    if (!isset($arity[$verb])) {
        return doorRefuse($id, 'unknown_verb');
    }
    if ($n < $arity[$verb][0] || $n > $arity[$verb][1]) {
        return doorRefuse($id, 'malformed', $verb === 'recv');
    }
    if ($verb === 'ping') {
        doorHeard($id);
        doorSay(['ok' => true, 'v' => doorVersion(), 'pair' => $id, 'array' => doorArrayRunning() ? 'started' : 'stopped', 'night' => doorNight(), 'time' => doorNow()]);
        return 0;
    }
    $err = $verb === 'recv';
    // every other verb: the unit and snapshot names first — exactly their shapes
    if ($n >= 2 && !preg_match(PARTNER_UNIT_RE, $w[1])) {
        return doorRefuse($id, 'bad_unit', $err);
    }
    foreach (array_slice($w, 2) as $i => $s) {
        if (!preg_match(PARTNER_SNAP_RE, $s) && !($i === 1 && $s === '-t' && $verb === 'recv')) {
            return doorRefuse($id, 'bad_snap', $err);
        }
    }
    if (!doorArrayRunning() || !is_dir(DATA_DIR)) {
        return doorRefuse($id, 'array_stopped', $err);
    }
    $pair = partnerPair($id);
    if ($pair === null) {
        return doorRefuse($id, 'no_pair', $err);
    }
    doorHeard($id);
    if ($verb === 'status') {
        return doorStatus($pair);
    }
    if ($verb === 'send-back') {
        return doorRefuse($id, 'not_yet');
    }
    $r = $pair['receive'];
    if ($r === null) {
        return doorRefuse($id, 'not_receiving', $err);
    }
    if ($n >= 2 && !in_array($w[1], $r['units'], true)) {
        return doorRefuse($id, 'unit_not_agreed', $err);
    }
    if (!doorPoolThere($r['pool'])) {
        return doorRefuse($id, 'no_pool', $err);
    }
    if (in_array($r['pool'], poolsBySleep([$r['pool']])['asleep'], true) && !$r['wake']) {
        return doorRefuse($id, 'refused_asleep', $err);
    }
    $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/$id";
    switch ($verb) {
        case 'quota':
            $q = doorZfsGet($pairDs, 'quota,used');
            doorSay(['ok' => true, 'bytes' => $q !== null && num($q['quota'] ?? '') > 0 ? num($q['quota']) : null, 'used_bytes' => $q !== null ? num($q['used'] ?? '') : 0]);
            return 0;
        case 'list':
            $ds = partnerUnitDataset($r['pool'], $id, $w[1]);
            doorSay(['ok' => true, 'unit' => $w[1], 'snaps' => array_map(fn ($s) => ['name' => $s['name'], 'used' => $s['used'], 'referenced' => $s['referenced'],
                'creation' => $s['creation']], array_values(array_filter(doorSnaps($ds), fn ($s) => preg_match(PARTNER_SNAP_RE, $s['name']))))]);
            return 0;
        case 'resume':
            $t = doorZfsGet(partnerUnitDataset($r['pool'], $id, $w[1]), 'receive_resume_token')['receive_resume_token'] ?? '-';
            doorSay(['ok' => true, 'token' => preg_match('/^[0-9a-zA-Z-]{8,4096}$/D', $t) && $t !== '-' ? $t : null]);
            return 0;
    }
    return doorRecv($pair, $w[1], $w[2], $w[3] ?? null);
}

function doorStatus(array $pair): int
{
    $lead = ['must' => 0, 'recommended' => 0];
    $ct = readJson(DATA_DIR . '/caretaker.json');
    $hired = array_flip((array) ($ct['hired'] ?? []));
    foreach ((array) ($ct['checks'] ?? []) as $desk => $list) {
        foreach (isset($hired[$desk]) ? (array) $list : [] as $f) {
            if (!is_array($f) || ($f['ok'] ?? null) === true || !empty($f['acked'])) {
                continue;
            }
            if (($f['level'] ?? '') === 'required' && ($f['ok'] ?? null) === false) {
                $lead['must']++;
            } elseif (($f['level'] ?? '') === 'recommended') {
                $lead['recommended']++;
            }
        }
    }
    $last = readJson(BACKUP_DATA_DIR . '/state/last-run.json');
    $out = ['ok' => true, 'name' => partnerMyName(), 'array' => 'started', 'version' => doorVersion(), 'lead' => $lead,
            'last_run' => is_array($last) && is_string($last['run'] ?? null) ? ['run' => $last['run'], 'result' => (string) ($last['result'] ?? ''),
                'time' => (int) ($last['finished'] ?? $last['updated'] ?? 0)] : null,
            'pool' => null, 'quota' => null, 'units' => (object) []];
    $r = $pair['receive'];
    if ($r !== null) {
        $asleep = in_array($r['pool'], poolsBySleep([$r['pool']])['asleep'], true);
        $out['pool'] = ['name' => $r['pool'], 'free_bytes' => null, 'asleep' => $asleep];
        if (!$asleep && doorPoolThere($r['pool'])) {
            $out['pool']['free_bytes'] = num(doorZfsGet($r['pool'], 'available')['available'] ?? '');
            $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/{$pair['id']}";
            $q = doorZfsGet($pairDs, 'quota,used');
            $out['quota'] = ['bytes' => $q !== null && num($q['quota'] ?? '') > 0 ? num($q['quota']) : null, 'used_bytes' => $q !== null ? num($q['used'] ?? '') : 0];
            $units = [];
            foreach ($r['units'] as $u) {
                $ds = partnerUnitDataset($r['pool'], $pair['id'], $u);
                $snaps = array_values(array_filter(array_column(doorSnaps($ds), 'name'), fn ($s) => preg_match(PARTNER_SNAP_RE, $s)));
                if ($snaps) {
                    $units[$u] = ['snaps' => $snaps, 'used_bytes' => num(doorZfsGet($ds, 'used')['used'] ?? ''), 'newest' => end($snaps)];
                }
            }
            $out['units'] = (object) $units;
        }
    }
    doorSay($out);
    return 0;
}

/**
 * The receiving end of a transfer. Checks (window, quota, the chain) before a byte is read; one transfer per pair
 * (its lock in RAM); the process registered for the array stop (agent.sh partner_release); JSON on stderr before
 * and after.
 */
function doorRecv(array $pair, string $unit, string $snap, ?string $from): int
{
    $id = $pair['id'];
    $r = $pair['receive'];
    if (!partnerWindowOpen($r['window'], doorNow())) {
        return doorRefuse($id, 'refused_window', true, ['window' => $r['window']]);
    }
    if (!partnerDirReady(partnerRunDir())) {
        return doorRefuse($id, 'busy', true);
    }
    $lock = @fopen(partnerRunDir() . "/$id.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return doorRefuse($id, 'busy', true);
    }
    $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/$id";
    $ds = partnerUnitDataset($r['pool'], $id, $unit);
    if (!doorParents($r['pool'], $id, $r['quota_gb'])) {
        return doorRefuse($id, 'recv_failed', true, ['detail' => 'parents']);
    }
    if ($r['quota_gb'] > 0) {
        $used = num(doorZfsGet($pairDs, 'used')['used'] ?? '');
        if ($used >= $r['quota_gb'] * 1024 ** 3) {
            return doorRefuse($id, 'refused_quota', true, ['used_bytes' => $used, 'bytes' => $r['quota_gb'] * 1024 ** 3]);
        }
    }
    $info = doorZfsGet($ds, 'type,receive_resume_token');
    $snaps = $info !== null ? array_column(doorSnaps($ds), 'name') : [];
    if (in_array($snap, $snaps, true)) {
        return doorRefuse($id, 'exists', true);
    }
    $resume = $from === '-t';
    $token = $info !== null ? ($info['receive_resume_token'] ?? '-') : '-';
    if ($resume && ($info === null || $token === '-' || $token === '')) {
        return doorRefuse($id, 'no_token', true);
    }
    if (!$resume && $info !== null && $token !== '-' && $token !== '') {
        return doorRefuse($id, 'resume_first', true);       // an interrupted receive waits: the sender asks `resume`
    }
    if (!$resume && $from !== null && !in_array($from, $snaps, true)) {
        return doorRefuse($id, 'need_full', true);
    }
    if (!$resume && $from === null && $info !== null) {
        return doorRefuse($id, 'need_incremental', true, ['newest' => $snaps ? end($snaps) : null]);
    }
    $zfs = partnerBin('zfs') ?? 'zfs';
    $cmd = [$zfs, 'recv', '-s', '-u'];
    if (!$resume && $from === null) {
        // the first receive: never mounted, never shared, read-only — legacy: shfs and Unraid never see it
        array_push($cmd, '-o', 'mountpoint=legacy', '-o', 'canmount=noauto', '-o', 'readonly=on', '-x', 'sharesmb', '-x', 'sharenfs');
    }
    $cmd[] = $resume ? $ds : "$ds@$snap";

    doorSay(['ok' => true], true);
    doorLog($id, "recv $unit $snap" . ($from !== null ? " from $from" : ' (full)') . " → $ds");
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $t0 = microtime(true);
    $mb = null;
    $in = STDIN;
    $mbuffer = partnerBin('mbuffer');
    if ($mbuffer !== null) {
        $mb = proc_open([$mbuffer, '-q', '-s', '128k', '-m', '256M'], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $mp, '/', $env);
        if (is_resource($mb)) {
            $in = $mp[1];
        } else {
            $mb = null;
        }
    }
    $zr = proc_open($cmd, [0 => $in, 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $zp, '/', $env);
    if ($mb !== null) {
        fclose($mp[1]);                 // the pipe is zfs's now
    }
    if (!is_resource($zr)) {
        if ($mb !== null) {
            proc_terminate($mb);
            proc_close($mb);
        }
        doorSay(['ok' => false, 'why' => 'recv_failed', 'detail' => 'could not start zfs'], true);
        return 1;
    }
    $children = array_values(array_filter([proc_get_status($zr)['pid'] ?? null, $mb !== null ? (proc_get_status($mb)['pid'] ?? null) : null]));
    $record = partnerRunDir() . '/door-' . getmypid() . '.json';
    try {
        writeAtomic($record, jsonEncode(['pid' => getmypid(), 'pair' => $id, 'unit' => $unit, 'snap' => $snap, 'dataset' => $ds, 'since' => time(), 'children' => $children]), 0600, 0, 0);
    } catch (Throwable $e) {
    }
    $stopped = false;
    pcntl_async_signals(true);
    $stop = function () use (&$stopped, $zr, $mb) {
        $stopped = true;            // the array stops (agent.sh partner_release): zfs recv -s keeps its resume token
        @proc_terminate($zr, SIGTERM);
        if ($mb !== null) {
            @proc_terminate($mb, SIGTERM);
        }
    };
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
        pcntl_signal($sig, $stop);
    }
    $errText = '';
    while (!feof($zp[2])) {
        $chunk = fread($zp[2], 8192);
        if ($chunk === false) {
            break;
        }
        $errText = substr($errText . $chunk, -4096);
    }
    fclose($zp[2]);
    $code = proc_close($zr);
    if ($mb !== null) {
        if ($code !== 0) {
            @proc_terminate($mb, SIGTERM);
        }
        proc_close($mb);
    }
    @unlink($record);
    $seconds = (int) round(microtime(true) - $t0);
    if ($code !== 0 || $stopped) {
        $detail = substr(trim((string) preg_replace('/[^\x20-\x7e\n]/', '?', $errText)), -400);
        doorLog($id, "recv $unit $snap failed" . ($stopped ? ' (stopped)' : '') . ': ' . str_replace("\n", ' | ', $detail));
        doorSay(['ok' => false, 'why' => $stopped ? 'stopped' : 'recv_failed', 'detail' => $detail], true);
        return 1;
    }
    $bytes = num(doorZfsGet("$ds@$snap", 'written')['written'] ?? '');
    $kept = doorRetention($pair, $ds);
    doorReceived($pair, $unit, $snap, $bytes, $seconds, $kept);
    doorLog($id, "recv $unit $snap done: $bytes bytes in {$seconds} s");
    doorSay(['ok' => true, 'bytes' => $bytes, 'seconds' => $seconds], true);
    flock($lock, LOCK_UN);
    return 0;
}

/**
 * The receiver's retention on the dataset just received into (the agreement's «d w m»): only uso-backup-* names, never
 * the newest, never one with a hold; what goes is recorded in deletes.jsonl first. The number kept is returned.
 */
function doorRetention(array $pair, string $ds): int
{
    $snaps = [];
    foreach (doorSnaps($ds) as $s) {
        $snaps[$s['name']] = $s['holds'];
    }
    $go = partnerRetentionSelect($snaps, $pair['receive']['retention']);
    if ($go) {
        partnerAppend(partnerDir() . '/deletes.jsonl', jsonEncode(['t' => time(), 'pair' => $pair['id'], 'dataset' => $ds, 'snaps' => $go]) . "\n");
        $zfs = partnerBin('zfs') ?? 'zfs';
        foreach ($go as $name) {
            [$exit, , $err] = run([$zfs, 'destroy', "$ds@$name"], 120);
            if ($exit !== 0) {
                doorLog($pair['id'], "retention: $ds@$name not destroyed: " . trim($err));
            }
        }
        doorLog($pair['id'], "retention on $ds: " . count($go) . ' snapshot(s) let go (' . implode(', ', $go) . ')');
    }
    return count($snaps) - count($go);
}

/** data/partner/received/<id>.json: per unit the newest snapshot, its bytes, when, how many are kept */
function doorReceived(array $pair, string $unit, string $snap, int $bytes, int $seconds, int $kept): void
{
    $file = partnerDir() . "/received/{$pair['id']}.json";
    $old = partnerReceived($pair['id']) ?? ['units' => []];
    $units = [];
    foreach ((array) $old['units'] as $u => $v) {
        if (is_string($u) && preg_match(PARTNER_UNIT_RE, $u) && is_array($v)) {
            $units[$u] = $v;
        }
    }
    $units[$unit] = ['snap' => $snap, 'bytes' => $bytes, 'seconds' => $seconds, 'time' => time(), 'snaps' => $kept];
    $pairDs = "{$pair['receive']['pool']}/" . PARTNER_PARENT . "/{$pair['id']}";
    try {
        partnerWritePrivate($file, ['pair' => $pair['id'], 'units' => $units, 'used_bytes' => num(doorZfsGet($pairDs, 'used')['used'] ?? ''), 'time' => time()]);
    } catch (Throwable $e) {
        doorLog($pair['id'], 'could not write ' . $file);
    }
}

exit(doorMain($argv));
