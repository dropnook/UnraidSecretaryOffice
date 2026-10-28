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
 *   ping                              {"ok":true,"v","pair","array","night","time","streams"} — from RAM and the flash only (the
 *                                     array may be stopped: nothing under /mnt is touched); with the array started and
 *                                     the data folder there, a pair it doesn't know (partnerKnownIds()) is refused
 *                                     no_pair — a line left from before the server was set up anew with its flash kept
 *   status                            the public summary (needs the data folder; `quota` with `aside_bytes` since 2026-10-28:
 *                                     what the door's earlier copies <unit>.old-<time> hold of it); `takes_all` true (a door since 1.60 takes
 *                                     any unit of the pair up to its quota), `agreed` = every unit received so far and
 *                                     every unit the partner named with `offer` (for a sender up to 1.59), `wish` = []
 *   quota                             {"ok":true,"bytes","used_bytes"} of the pair's dataset here (bytes: the rule in pairs.json, doorQuotaBytes())
 *   list <unit>                       the snapshots kept here of that unit
 *   resume <unit>                     {"ok":true,"token":<receive_resume_token>|null}
 *   recv <unit> <snap> [<from>|-t]    stdin → mbuffer → zfs recv -s -u into <pool>/UnraidSecretaryOffice-partners/<id>/<unit>;
 *                                     a full stream onto an existing unit puts the old dataset aside (<ds>.old-<time>),
 *                                     a stale partial receive is aborted (zfs recv -A) unless the call is a -t resume;
 *                                     JSON on stderr before reading and after; the receiver's retention afterwards;
 *                                     since 1.72 up to DOOR_RECV_STREAMS units of a pair at once (ping says `streams`;
 *                                     a sender that doesn't see it sends one after another), never one unit twice
 *   offer <unit> [<unit>…]            what an older partner (up to 1.59) would like to send (≤ 64 units, each a unit word,
 *                                     no twice): kept as what it named (data/partner/wishes/<id>.json, {pair, units, time},
 *                                     overwritten) and answered {"ok":true,"agreed":[…all of them…],"pending":[]} — the
 *                                     consent is the pairing, nothing to decide. Never makes a dataset (needs the data
 *                                     folder like status)
 *   send-back <unit> <snap> [<from>]  the pair's own copy of a unit back to its sender: zfs send -L -c [-i <from>] of
 *   send-back <unit> -t <token>       <pool>/UnraidSecretaryOffice-partners/<id>/<unit>@<snap> to stdout (through mbuffer) —
 *                                     or, resumed, zfs send -t <token> (the token is the puller's: its zfs recv -s kept
 *                                     it; the door sends only when the token names this unit's dataset). JSON on stderr
 *                                     before ({"ok":true,"size"}) and after; one transfer per pair; registered for the
 *                                     array stop like recv
 *
 * A restore ticket (stage 3, partner.php «restore tickets») has a line of its own — command="…/partner-door.sh
 * ticket-<id>", comment uso-ticket:<id>: a new server pulls a gone server's copies with it. Its verbs are ping, list,
 * send-back and quota on the copies of the pair it was given for (tickets.json `of`), until `expires` (then
 * {"ok":false,"why":"ticket_expired","time":<now>}; sshd's expiry-time ends the key too).
 *
 * Never a path from the client, never -F, never a destroy but this office's own retention on the pair's datasets,
 * never a shell. Every unit name is checked against exactly its shape (a ticket's against the units it was given for),
 * every snapshot name against exactly uso-backup-YYYYMMDD-HHMM; a pair gets at most PARTNER_UNITS_MAX unit datasets
 * here (refused_units) and what its quota allows (refused_quota) — since 1.60 any unit of a paired partner is taken, at
 * any time (no receiving window: the sender's schedule is the schedule). Refusals: one line in data/partner/door.log (RAM when the data folder is
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
// the server's time zone (its log lines are local time): its environment is empty, like agent.php's start
$zone = preg_replace('#^.*/zoneinfo/#', '', (string) @readlink('/etc/localtime'));
date_default_timezone_set(in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');

const DOOR_VAR_INI = '/var/local/emhttp/var.ini';
const DOOR_ARRAY_RUNNING = ['Started', 'Formatting', 'Clearing'];      // one definition with agent.php's ARRAY_RUNNING
const FILE_UID = 0;
const FILE_GID = 0;
const LOG_MAX = 512 * 1024;
const DOOR_REFUSED_KEEP = 3600;
const DOOR_RECV_STREAMS = 4;            // 1.72: receives of one pair at once (different units, each its own ordered stream)

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
 * quote, a slash, a tab, two spaces) is no request. At most 256 characters — but «send-back <unit> -t <token>», whose
 * resume token is longer (only [0-9a-zA-Z-]), and «offer <unit>…» with up to PARTNER_UNITS_MAX units of at most 70
 * characters each. Null: not one.
 */
function doorWords(string $cmd): ?array
{
    if (strlen($cmd) <= 4600 && preg_match('/^offer(?: [A-Za-z0-9._:-]{1,70}){1,65}$/D', $cmd)) {
        return explode(' ', $cmd);              // 65: one too many is still a request — refused as malformed by its count
    }
    if ($cmd === '' || !preg_match('/^[A-Za-z0-9._:-]+(?: [A-Za-z0-9._:-]+){0,4}$/D', $cmd)) {
        return null;
    }
    if (strlen($cmd) > 256 && (strlen($cmd) > 4200 || !preg_match('/^send-back [A-Za-z0-9:._-]{1,80} -t [0-9a-zA-Z-]{8,4000}$/D', $cmd))) {
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
            writeAtomic($file, jsonEncode(['pair' => $id, 'times' => array_slice($times, -200), 'last' => $why]), 0600, 0, 0);
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
            $first = !file_exists($f);
            @touch($f);
            if ($first) {
                partnerRescanAsk();         // the pairing landed from the other side: the Team Lead's cards anew at once
            }
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
    partnerQuotaSync($pairDs, $quotaGb);         // the Team Lead's «Change the space…» sets it at once when it can, this at every receive
    return true;
}

/**
 * The quota a partner is told (status, quota): the one the door holds it to — the receiver's rule in pairs.json. The
 * dataset's own ZFS quota follows it (doorParents()), but ZFS takes none below what is used: a quota lowered below what
 * is kept stays the rule here at once (refused_quota) while ZFS keeps the old one until enough is freed (2026-10-28,
 * test4: the sender read the old 100 GB from ZFS after the receiver had set 50). Null: no limit.
 */
function doorQuotaBytes(array $receive): ?int
{
    return $receive['quota_gb'] > 0 ? $receive['quota_gb'] * 1024 ** 3 : null;
}

// ===================================================================== the verbs

function doorMain(array $argv): int
{
    $arg = (string) ($argv[1] ?? '');
    // a pair's line names the pair; a restore ticket's line «ticket-<id>»
    $ticketId = preg_match(PARTNER_TICKET_ARG_RE, $arg, $tm) ? $tm[1] : null;
    $id = $ticketId ?? $arg;
    if (!preg_match(PARTNER_ID_RE, $id)) {
        return doorRefuse('-', 'no_pair');
    }
    $w = $GLOBALS['doorWords'];
    if ($w === null) {
        return doorRefuse($id, 'malformed');
    }
    $verb = $w[0];
    $n = count($w);
    $arity = ['ping' => [1, 1], 'status' => [1, 1], 'quota' => [1, 1], 'list' => [2, 2], 'resume' => [2, 2], 'recv' => [3, 4], 'send-back' => [3, 4],
              'offer' => [2, 1 + PARTNER_UNITS_MAX]];
    if (!isset($arity[$verb])) {
        return doorRefuse($id, 'unknown_verb');
    }
    $err = $verb === 'recv' || $verb === 'send-back';        // the verbs whose stdout or stdin is a stream answer on stderr
    if ($n < $arity[$verb][0] || $n > $arity[$verb][1]) {
        return doorRefuse($id, 'malformed', $err);
    }
    if ($ticketId !== null && !in_array($verb, PARTNER_TICKET_VERBS, true)) {
        return doorRefuse($id, 'ticket_verb', $err);
    }
    if ($verb === 'ping') {
        // a ticket's ping: its expiry looked at while the data folder is there (the array stopped: RAM and flash only)
        if ($ticketId !== null && doorArrayRunning() && is_dir(DATA_DIR) && ($why = doorTicketWhy($ticketId)) !== null) {
            return doorRefuse($id, $why, false, $why === 'ticket_expired' ? ['time' => doorNow()] : []);
        }
        // a pair's ping (2026-10-16): a line left from before this server was set up anew (its flash kept) — said as
        // no_pair while the data folder is there and knows its pairs; the array stopped: answered from RAM and flash
        if ($ticketId === null && doorArrayRunning() && is_dir(DATA_DIR) && ($known = partnerKnownIds()) !== null && !in_array($id, $known, true)) {
            return doorRefuse($id, 'no_pair');
        }
        doorHeard($id);
        doorSay(['ok' => true, 'v' => doorVersion(), 'pair' => $id, 'array' => doorArrayRunning() ? 'started' : 'stopped', 'night' => doorNight(), 'time' => doorNow(),
                 'streams' => DOOR_RECV_STREAMS]);
        return 0;
    }
    // offer: every word a unit, none twice
    if ($verb === 'offer' && partnerUnitList(array_slice($w, 1), 1) === null) {
        return doorRefuse($id, 'malformed');
    }
    // every other verb: the unit and snapshot names first — exactly their shapes
    if ($verb !== 'offer' && $n >= 2 && !preg_match(PARTNER_UNIT_RE, $w[1])) {
        return doorRefuse($id, 'bad_unit', $err);
    }
    $resumeBack = $verb === 'send-back' && $n === 4 && $w[2] === '-t';
    foreach ($verb === 'offer' ? [] : array_slice($w, 2) as $i => $s) {
        if ($resumeBack) {
            if ($i === 1 && !preg_match(PARTNER_TOKEN_RE, $s)) {
                return doorRefuse($id, 'bad_token', true);
            }
            continue;
        }
        if (!preg_match(PARTNER_SNAP_RE, $s) && !($i === 1 && $s === '-t' && $verb === 'recv')) {
            return doorRefuse($id, 'bad_snap', $err);
        }
    }
    if (!doorArrayRunning() || !is_dir(DATA_DIR)) {
        return doorRefuse($id, 'array_stopped', $err);
    }
    $units = null;
    if ($ticketId !== null) {
        // a ticket: its own record (not expired), the pair it was given for, the units of that pair the ticket names
        if (($why = doorTicketWhy($ticketId)) !== null) {
            return doorRefuse($id, $why, $err, $why === 'ticket_expired' ? ['time' => doorNow()] : []);
        }
        $ticket = partnerTicket($ticketId);
        $pair = partnerPair($ticket['of']);
        if ($pair === null) {
            return doorRefuse($id, 'no_pair', $err);
        }
        $units = $pair['receive'] !== null ? $ticket['units'] : [];
    } else {
        $pair = partnerPair($id);
        if ($pair === null) {
            return doorRefuse($id, 'no_pair', $err);
        }
    }
    doorHeard($id);
    if ($verb === 'status') {
        return doorStatus($pair);
    }
    if ($verb === 'offer') {
        return doorOffer($pair, array_slice($w, 1));
    }
    $r = $pair['receive'];
    if ($r === null) {
        return doorRefuse($id, 'not_receiving', $err);
    }
    // a ticket reads only the units it was given for; a pair's own knock may name any unit (the consent is the pairing)
    if ($units !== null && $n >= 2 && !in_array($w[1], $units, true)) {
        return doorRefuse($id, 'unit_not_agreed', $err);
    }
    if (!doorPoolThere($r['pool'])) {
        return doorRefuse($id, 'no_pool', $err);
    }
    // 1.60.2: a sleeping pool is never a refusal - the receive wakes it (the pairing asks for a ZFS pool; whoever puts it
    // on rotating disks chose so). A pair's old `wake` value is read and ignored; an older door may still refuse
    // (refused_asleep), the sender warns that in the same run.
    $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/{$pair['id']}";       // a ticket reads the copies of the pair it was given for
    switch ($verb) {
        case 'quota':
            $q = doorZfsGet($pairDs, 'used');
            doorSay(['ok' => true, 'bytes' => doorQuotaBytes($r), 'used_bytes' => $q !== null ? num($q['used'] ?? '') : 0]);
            return 0;
        case 'list':
            $ds = partnerUnitDataset($r['pool'], $pair['id'], $w[1]);
            doorSay(['ok' => true, 'unit' => $w[1], 'snaps' => array_map(fn ($s) => ['name' => $s['name'], 'used' => $s['used'], 'referenced' => $s['referenced'],
                'creation' => $s['creation']], array_values(array_filter(doorSnaps($ds), fn ($s) => preg_match(PARTNER_SNAP_RE, $s['name']))))]);
            return 0;
        case 'resume':
            $t = doorZfsGet(partnerUnitDataset($r['pool'], $pair['id'], $w[1]), 'receive_resume_token')['receive_resume_token'] ?? '-';
            doorSay(['ok' => true, 'token' => preg_match('/^[0-9a-zA-Z-]{8,4096}$/D', $t) && $t !== '-' ? $t : null]);
            return 0;
        case 'send-back':
            return doorSendBack($pair, $id, $w[1], $resumeBack ? null : $w[2], $resumeBack ? null : ($w[3] ?? null), $resumeBack ? $w[3] : null);
    }
    return doorRecv($pair, $w[1], $w[2], $w[3] ?? null);
}

/** Why a ticket can't be used (null: it can): gone from tickets.json, or expired */
function doorTicketWhy(string $ticketId): ?string
{
    $t = partnerTicket($ticketId);
    if ($t === null) {
        return 'no_ticket';
    }
    return $t['expires'] <= doorNow() ? 'ticket_expired' : null;
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
            'pool' => null, 'quota' => null, 'units' => (object) [],
            'agreed' => doorAgreed($pair), 'wish' => [], 'takes_all' => true];
    $r = $pair['receive'];
    if ($r !== null) {
        $asleep = in_array($r['pool'], poolsBySleep([$r['pool']])['asleep'], true);
        $out['pool'] = ['name' => $r['pool'], 'free_bytes' => null, 'asleep' => $asleep];
        if (!$asleep && doorPoolThere($r['pool'])) {
            $out['pool']['free_bytes'] = num(doorZfsGet($r['pool'], 'available')['available'] ?? '');
            $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/{$pair['id']}";
            $q = doorZfsGet($pairDs, 'used');
            // 2026-10-28: and how much of it the door's earlier copies hold (<unit>.old-<time>, only the receiver lets them go)
            $aside = partnerAside($r['pool'], $pair['id'], array_keys((array) (partnerReceived($pair['id'])['units'] ?? [])));
            $out['quota'] = ['bytes' => doorQuotaBytes($r), 'used_bytes' => $q !== null ? num($q['used'] ?? '') : 0,
                             'aside_bytes' => $aside['bytes'] ?? 0];
            $units = [];
            foreach (array_keys((array) (partnerReceived($pair['id'])['units'] ?? [])) as $u) {
                if (!is_string($u) || !preg_match(PARTNER_UNIT_RE, $u)) {
                    continue;
                }
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
 * `offer <unit>…`: an older partner (up to 1.59) would like to send these. Kept as what it named (wishes/<id>.json, one
 * file per pair, overwritten) and answered as agreed whole — the consent is the pairing, its door takes any unit up to
 * the quota. Never a dataset, never a refusal for a unit.
 */
function doorOffer(array $pair, array $units): int
{
    $id = $pair['id'];
    $r = $pair['receive'];
    if ($r === null) {
        return doorRefuse($id, 'not_receiving');
    }
    $before = partnerWish($pair);
    try {
        partnerWishWrite($id, $units, doorNow());
    } catch (Throwable $e) {
        return doorRefuse($id, 'busy');
    }
    if (($before['units'] ?? null) !== $units) {
        doorLog($id, 'offer: would like to send ' . implode(' ', $units) . ' - taken (any unit up to the quota)');
    }
    doorSay(['ok' => true, 'agreed' => $units, 'pending' => []]);
    return 0;
}

/** `status`'s `agreed` for a sender up to 1.59: every unit received so far and every unit it named with `offer` */
function doorAgreed(array $pair): array
{
    $units = [];
    foreach (array_keys((array) (partnerReceived($pair['id'])['units'] ?? [])) as $u) {
        if (is_string($u) && preg_match(PARTNER_UNIT_RE, $u)) {
            $units[$u] = true;
        }
    }
    foreach (partnerWish($pair)['units'] ?? [] as $u) {
        $units[$u] = true;
    }
    return array_slice(array_keys($units), 0, PARTNER_UNITS_MAX);
}

/**
 * How many unit datasets the pair has here (zfs list -r of its dataset, one level below it) — 1.72: and the new ones on
 * their way (another receive of the pair into a dataset not there yet, its record in RAM): asked under the pair's write
 * lock, so several receives at once never take a pair past PARTNER_UNITS_MAX
 */
function doorUnitCount(string $pairDs, string $id = ''): int
{
    [$exit, $out] = run([partnerBin('zfs') ?? 'zfs', 'list', '-H', '-o', 'name', '-t', 'filesystem', '-r', $pairDs], 60);
    $have = [];
    foreach ($exit === 0 ? explode("\n", trim($out)) : [] as $name) {
        if (str_starts_with($name, "$pairDs/") && !str_contains(substr($name, strlen($pairDs) + 1), '/')) {
            $have[$name] = true;
        }
    }
    if ($id !== '') {
        foreach (glob(partnerRunDir() . '/door-*.json') ?: [] as $f) {
            $pid = preg_match('/door-(\d{1,10})\.json$/D', $f, $m) ? (int) $m[1] : 0;
            $d = $pid > 1 && $pid !== getmypid() && is_dir("/proc/$pid") && !is_link($f) ? json_decode((string) @file_get_contents($f, false, null, 0, 8192), true) : null;
            if (is_array($d) && ($d['pair'] ?? null) === $id && empty($d['send_back']) && is_string($d['dataset'] ?? null) && str_starts_with($d['dataset'], "$pairDs/")) {
                $have[$d['dataset']] = true;
            }
        }
    }
    return count($have);
}

/**
 * 1.72: the locks of a receive — the pair's lock shared (a send-back, and a door before 1.72, hold it alone: then
 * `busy`), the unit's alone (never one unit twice at once: its stream is one ordered chain), one of DOOR_RECV_STREAMS
 * slots. Null: busy. The handles are kept until the process ends.
 */
function doorRecvLocks(string $id, string $unit): ?array
{
    $dir = partnerRunDir();
    $pair = @fopen("$dir/$id.lock", 'c');
    if (!$pair || !flock($pair, LOCK_SH | LOCK_NB)) {
        return null;
    }
    $one = @fopen("$dir/$id.unit-" . str_replace(':', '-', $unit) . '.lock', 'c');
    if (!$one || !flock($one, LOCK_EX | LOCK_NB)) {
        return null;
    }
    for ($i = 0; $i < DOOR_RECV_STREAMS; $i++) {
        $slot = @fopen("$dir/$id.slot$i.lock", 'c');
        if ($slot && flock($slot, LOCK_EX | LOCK_NB)) {
            return [$pair, $one, $slot];
        }
    }
    return null;
}

/** 1.72: the pair's write lock — its parents, the count of its units, received/<id>.json: one receive at a time */
function doorWriteLock(string $id)
{
    $h = @fopen(partnerRunDir() . "/$id.write.lock", 'c');
    if ($h) {
        flock($h, LOCK_EX);
    }
    return $h;
}

/**
 * The receiving end of a transfer. Checks (the quota, the count of units, the chain) before a byte is read; since 1.72
 * up to DOOR_RECV_STREAMS units of a pair at once, never one unit twice (doorRecvLocks(); before: one transfer per pair),
 * what they share — the parents, the count of units, received/<id>.json — under the pair's write lock; the process
 * registered for the array stop (agent.sh partner_release); JSON on stderr before and after.
 */
function doorRecv(array $pair, string $unit, string $snap, ?string $from): int
{
    $id = $pair['id'];
    $r = $pair['receive'];
    if (!partnerDirReady(partnerRunDir())) {
        return doorRefuse($id, 'busy', true);
    }
    $locks = doorRecvLocks($id, $unit);
    if ($locks === null) {
        return doorRefuse($id, 'busy', true);
    }
    $pairDs = "{$r['pool']}/" . PARTNER_PARENT . "/$id";
    $ds = partnerUnitDataset($r['pool'], $id, $unit);
    $record = partnerRunDir() . '/door-' . getmypid() . '.json';
    register_shutdown_function(fn () => @unlink($record));
    $w = doorWriteLock($id);
    if (!doorParents($r['pool'], $id, $r['quota_gb'])) {
        return doorRefuse($id, 'recv_failed', true, ['detail' => 'parents']);
    }
    if ($r['quota_gb'] > 0) {
        $used = num(doorZfsGet($pairDs, 'used')['used'] ?? '');
        if ($used >= $r['quota_gb'] * 1024 ** 3) {
            // 2026-10-28: what of it the door's own earlier copies hold — the sender says so («let them go at the partner»)
            $aside = partnerAside($r['pool'], $id, array_keys((array) (partnerReceived($id)['units'] ?? [])));
            return doorRefuse($id, 'refused_quota', true, ['used_bytes' => $used, 'bytes' => $r['quota_gb'] * 1024 ** 3, 'aside_bytes' => $aside['bytes'] ?? 0]);
        }
    }
    $zfs = partnerBin('zfs') ?? 'zfs';
    $info = doorZfsGet($ds, 'type,receive_resume_token');
    if ($info === null && doorUnitCount($pairDs, $id) >= PARTNER_UNITS_MAX) {
        return doorRefuse($id, 'refused_units', true, ['max' => PARTNER_UNITS_MAX]);        // a new unit beyond what a pair may keep here
    }
    // 1.72: this receive is on its way from here (the count above sees it) - the record again below with its children
    try {
        writeAtomic($record, jsonEncode(['pid' => getmypid(), 'pair' => $id, 'unit' => $unit, 'snap' => $snap, 'dataset' => $ds, 'since' => time(), 'children' => []]), 0600, 0, 0);
    } catch (Throwable $e) {
    }
    if ($w) {
        flock($w, LOCK_UN);
    }
    $resume = $from === '-t';
    $token = $info !== null ? ($info['receive_resume_token'] ?? '-') : '-';
    $hasToken = $token !== '-' && $token !== '';
    if ($resume && !$hasToken) {
        return doorRefuse($id, 'no_token', true);
    }
    if (!$resume && $hasToken) {
        // a stale partial receive: the sender starts anew (it no longer has what was interrupted) — its partial state
        // goes (zfs recv -A: only the unfinished part; a first receive that never finished leaves no dataset behind)
        [$exit, , $err] = run([$zfs, 'recv', '-A', $ds], 120);
        if ($exit !== 0) {
            return doorRefuse($id, 'recv_failed', true, ['detail' => 'abort: ' . substr(trim($err), 0, 200)]);
        }
        doorLog($id, "recv $unit $snap: a stale partial receive on $ds aborted (zfs recv -A) - the sender starts anew");
        $info = doorZfsGet($ds, 'type,receive_resume_token');
    }
    $snaps = $info !== null ? array_column(doorSnaps($ds), 'name') : [];
    if (in_array($snap, $snaps, true)) {
        return doorRefuse($id, 'exists', true);
    }
    if (!$resume && $from !== null && !in_array($from, $snaps, true)) {
        return doorRefuse($id, 'need_full', true);
    }
    $aside = null;
    if (!$resume && $from === null && $info !== null) {
        // a full stream onto a dataset that holds snapshots the sender doesn't share (zfs recv without -F refuses it):
        // the old one is put aside — renamed, never destroyed by the door: the receiver's Team Lead shows it and lets it go
        // («Let go…», partner_aside_letgo()) — and the stream goes into a fresh dataset with the first receive's properties.
        // Never trimmed on its own (2026-10-28): a full stream is what a sender set up anew or broken sends — two of them
        // would let a sender wipe the history kept here, which the door never allows a sender
        $base = "$ds.old-" . date('Ymd-Hi', doorNow());
        $aside = $base;
        for ($i = 2; doorZfsGet($aside, 'type') !== null; $i++) {
            $aside = "$base-$i";
        }
        [$exit, , $err] = run([$zfs, 'rename', $ds, $aside], 120);
        if ($exit !== 0) {
            return doorRefuse($id, 'recv_failed', true, ['detail' => 'aside: ' . substr(trim($err), 0, 200)]);
        }
        doorLog($id, "recv $unit $snap (full) onto $ds, which shares no snapshot with it: the old one put aside as $aside");
    }
    $cmd = [$zfs, 'recv', '-s', '-u'];
    if (!$resume && $from === null) {
        // the first receive: never mounted, never shared, read-only — legacy: shfs and Unraid never see it (with a -R
        // stream its descendants inherit mountpoint=legacy too)
        array_push($cmd, '-o', 'mountpoint=legacy', '-o', 'canmount=noauto', '-o', 'readonly=on', '-x', 'sharesmb', '-x', 'sharenfs');
    } else {
        // later ones change nothing of that: the stream's own mountpoint/canmount/shares are left out — for the dataset
        // (its local values win anyway) and for any descendant a -R stream would bring (else a mountpoint=/etc there
        // would be mounted at the next boot)
        array_push($cmd, '-x', 'mountpoint', '-x', 'canmount', '-x', 'sharesmb', '-x', 'sharenfs');
    }
    $cmd[] = $resume ? $ds : "$ds@$snap";

    doorSay(['ok' => true], true);
    doorLog($id, "recv $unit $snap" . ($resume ? ' (resumed)' : ($from !== null ? " from $from" : ' (full)')) . " -> $ds");
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
        $detail = substr(trim((string) preg_replace('/[^\x20-\x7e\n]/', '?', $errText)), 0, 400);
        $token = doorZfsGet($ds, 'receive_resume_token')['receive_resume_token'] ?? '-';
        doorLog($id, "recv $unit $snap failed" . ($stopped ? ' (stopped)' : '') . ': ' . str_replace("\n", ' | ', $detail));
        if ($aside !== null && doorZfsGet($ds, 'type') === null && run([$zfs, 'rename', $aside, $ds], 120)[0] === 0) {
            doorLog($id, "recv $unit $snap: nothing received - $aside back as $ds");
            $aside = null;
        }
        doorSay(['ok' => false, 'why' => $stopped ? 'stopped' : 'recv_failed', 'detail' => $detail, 'resumable' => $token !== '-' && $token !== '']
            + ($aside !== null ? ['aside' => $aside] : []), true);
        return 1;
    }
    if ($resume) {
        // a resumed first receive never got its -o: set them now (canmount first — nothing is mounted on the way)
        foreach (['canmount=noauto', 'mountpoint=legacy', 'readonly=on'] as $prop) {
            run([$zfs, 'set', $prop, $ds], 60);
        }
    }
    $bytes = num(doorZfsGet("$ds@$snap", 'written')['written'] ?? '');
    $kept = doorRetention($pair, $ds);
    $w = doorWriteLock($id);
    doorReceived($pair, $unit, $snap, $bytes, $seconds, $kept);
    if ($w) {
        flock($w, LOCK_UN);
    }
    doorLog($id, "recv $unit $snap done: $bytes bytes in {$seconds} s");
    partnerRescanAsk();         // the Team Lead's card shows the unit at once, not at his next tour (2026-10-23, the lab)
    doorSay(['ok' => true, 'bytes' => $bytes, 'seconds' => $seconds] + ($aside !== null ? ['aside' => $aside] : []), true);
    return 0;
}

/**
 * The way back: the pair's own copy of a unit to stdout — `zfs send -L -c [-i <ds>@<from>] <ds>@<snap>` (or, resumed,
 * `zfs send -t <token>` when the token names exactly this unit's dataset and a snapshot of the engine's shape) through
 * mbuffer. Only this pair's dataset of the unit; the snapshots must be there. One transfer per pair ($callerId's lock —
 * a ticket has its own); registered for the array stop (agent.sh partner_release); JSON on stderr before ({"ok":true,
 * "size":<bytes zfs says it sends>}) and after. Nothing here writes or destroys anything.
 */
function doorSendBack(array $pair, string $callerId, string $unit, ?string $snap, ?string $from, ?string $token): int
{
    $r = $pair['receive'];
    $ds = partnerUnitDataset($r['pool'], $pair['id'], $unit);
    $zfs = partnerBin('zfs') ?? 'zfs';
    if (doorZfsGet($ds, 'type') === null) {
        return doorRefuse($callerId, 'no_snapshot', true);
    }
    if ($token !== null) {
        // the token says what it continues: it must be this dataset — never another one of this server
        [$exit, $out, $e] = run([$zfs, 'send', '-nvP', '-t', $token], 60);
        $text = $out . "\n" . $e;
        $to = preg_match('/^\s*toname = (\S+)\s*$/m', $text, $m) ? $m[1] : '';
        $toSnap = str_contains($to, '@') ? explode('@', $to, 2)[1] : '';
        if ($exit !== 0 || !str_starts_with($to, "$ds@") || !preg_match(PARTNER_SNAP_RE, $toSnap)) {
            return doorRefuse($callerId, $exit !== 0 ? 'bad_token' : 'token_other', true);
        }
        $snap = $toSnap;
        $cmd = [$zfs, 'send', '-t', $token];
        $size = preg_match('/^size\s+(\d+)\s*$/m', $text, $m) ? (int) $m[1] : null;
    } else {
        $snaps = array_column(doorSnaps($ds), 'name');
        if (!in_array($snap, $snaps, true)) {
            return doorRefuse($callerId, 'no_snapshot', true);
        }
        if ($from !== null && (!in_array($from, $snaps, true) || strcmp($from, (string) $snap) >= 0)) {
            return doorRefuse($callerId, 'need_full', true);
        }
        $cmd = array_merge([$zfs, 'send', '-L', '-c'], $from !== null ? ['-i', "$ds@$from"] : [], ["$ds@$snap"]);
        [$exit, $out] = run(array_merge([$zfs, 'send', '-nP', '-L', '-c'], $from !== null ? ['-i', "$ds@$from"] : [], ["$ds@$snap"]), 60);
        $size = $exit === 0 && preg_match('/^size\s+(\d+)\s*$/m', $out, $m) ? (int) $m[1] : null;
    }
    if (!partnerDirReady(partnerRunDir())) {
        return doorRefuse($callerId, 'busy', true);
    }
    $lock = @fopen(partnerRunDir() . "/$callerId.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return doorRefuse($callerId, 'busy', true);
    }
    doorSay(['ok' => true, 'size' => $size], true);
    doorLog($callerId, "send-back $unit $snap" . ($token !== null ? ' (resumed)' : ($from !== null ? " from $from" : ' (whole)')) . " <- $ds");
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $t0 = microtime(true);
    $mbuffer = partnerBin('mbuffer');
    $zs = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $mbuffer !== null ? ['pipe', 'w'] : STDOUT, 2 => ['pipe', 'w']], $zp, '/', $env);
    if (!is_resource($zs)) {
        doorSay(['ok' => false, 'why' => 'send_failed', 'detail' => 'could not start zfs'], true);
        return 1;
    }
    $mb = null;
    if ($mbuffer !== null) {
        $mb = proc_open([$mbuffer, '-q', '-s', '128k', '-m', '256M'], [0 => $zp[1], 1 => STDOUT, 2 => ['file', '/dev/null', 'w']], $mp, '/', $env);
        fclose($zp[1]);                 // the pipe is mbuffer's now
        if (!is_resource($mb)) {
            @proc_terminate($zs);
            proc_close($zs);
            doorSay(['ok' => false, 'why' => 'send_failed', 'detail' => 'could not start mbuffer'], true);
            return 1;
        }
    }
    $children = array_values(array_filter([proc_get_status($zs)['pid'] ?? null, $mb !== null ? (proc_get_status($mb)['pid'] ?? null) : null]));
    $record = partnerRunDir() . '/door-' . getmypid() . '.json';
    try {
        writeAtomic($record, jsonEncode(['pid' => getmypid(), 'pair' => $callerId, 'unit' => $unit, 'snap' => $snap, 'dataset' => $ds, 'since' => time(),
            'children' => $children, 'send_back' => true]), 0600, 0, 0);
    } catch (Throwable $e) {
    }
    $stopped = false;
    pcntl_async_signals(true);
    $stop = function () use (&$stopped, $zs, $mb) {
        $stopped = true;            // the array stops (partner_release), or the puller went away
        @proc_terminate($zs, SIGTERM);
        if ($mb !== null) {
            @proc_terminate($mb, SIGTERM);
        }
    };
    foreach ([SIGTERM, SIGINT, SIGHUP, SIGPIPE] as $sig) {
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
    $code = proc_close($zs);
    $mcode = 0;
    if ($mb !== null) {
        if ($code !== 0) {
            @proc_terminate($mb, SIGTERM);
        }
        $mcode = proc_close($mb);
    }
    @unlink($record);
    flock($lock, LOCK_UN);
    $seconds = (int) round(microtime(true) - $t0);
    if ($code !== 0 || $mcode !== 0 || $stopped) {
        $detail = substr(trim((string) preg_replace('/[^\x20-\x7e\n]/', '?', $errText)), 0, 400);
        doorLog($callerId, "send-back $unit $snap failed" . ($stopped ? ' (stopped)' : '') . ': ' . str_replace("\n", ' | ', $detail));
        doorSay(['ok' => false, 'why' => $stopped ? 'stopped' : 'send_failed', 'detail' => $detail], true);
        return 1;
    }
    doorLog($callerId, "send-back $unit $snap done in {$seconds} s");
    doorSay(['ok' => true, 'seconds' => $seconds], true);
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

/** data/partner/received/<id>.json: per unit the newest snapshot, its bytes, when, how many are kept, when it first came (the card's «new») */
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
    $first = is_int($units[$unit]['first'] ?? null) ? $units[$unit]['first'] : (is_int($units[$unit]['time'] ?? null) ? $units[$unit]['time'] : time());
    $units[$unit] = ['snap' => $snap, 'bytes' => $bytes, 'seconds' => $seconds, 'time' => time(), 'snaps' => $kept, 'first' => $first];
    $pairDs = "{$pair['receive']['pool']}/" . PARTNER_PARENT . "/{$pair['id']}";
    try {
        partnerWritePrivate($file, ['pair' => $pair['id'], 'units' => $units, 'used_bytes' => num(doorZfsGet($pairDs, 'used')['used'] ?? ''), 'time' => time()]);
    } catch (Throwable $e) {
        doorLog($pair['id'], 'could not write ' . $file);
    }
}

exit(doorMain($argv));
