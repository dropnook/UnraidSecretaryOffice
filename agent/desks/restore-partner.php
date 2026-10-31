<?php
declare(strict_types=1);

/*
 * Mr. Restori across servers (stage 3 of the partner plan; the federation concept's §3.9) — functions
 * only, in a file of their own beside his desk: the agent's desk glob loads this file before restore.php, which
 * require_once's it; nothing here may use restore.php's constants at its top level.
 *
 *   «At <partner>»     what a partner office keeps of mine (a pair with my key that I send units to), or — on a new
 *                      server — what the holder of a gone server's copies hands out with a restore ticket: per unit its
 *                      moments (the partner's snapshots) with sizes. Asked through the door (`list <unit>`) by a job of
 *                      its own (php agent.php job restore-partner-look, handed to atd from his tick — never from a page
 *                      request), kept in data/partner/<id>/held.json with `looked`; unreachable: «as of …».
 *   «Bring back»       a plan of kind `partner`: `send-back <unit> <snap>` streamed into a NEW dataset — always beside,
 *                      never over: <dataset>.restored-<time> next to the unit's own on my pool (a pair), on a new server
 *                      <pool>/<share>.restored-<time> where that share exists, else <pool>/UnraidSecretaryOffice-restored/
 *                      <unit>. zfs recv -s -u, mountpoint=legacy, canmount=noauto, not shared, writable; then mounted
 *                      read-only under /mnt/addons/UnraidSecretaryOffice/restored/<name>, where his `files` restores
 *                      take it as a moment («at <partner>») and his `db`/`vm`/`config` restores a pulled backup place as
 *                      an earlier night's packages (or, on a new server without a place of its own, as his packages).
 *                      Resumable: an interrupted pull keeps its token here (zfs recv -s), the next plan of the same pull
 *                      continues it (`send-back <unit> -t <token>`). Progress from pv -n into the job file; the array stop
 *                      ends it like his other jobs (rsWatch). Its undo («Put back») destroys only what the pull made —
 *                      checked by name and guid —, never the original.
 *
 * The pull's datasets are found again through his journals (kind `partner`, `pulled`): mounted again at the agent's
 * start, unmounted by agent.sh at the array stop (restored_release).
 */

require_once dirname(__DIR__) . '/lib/partner.php';

const RSP_LOOK_EVERY   = 6 * 3600;        // what a partner holds of mine: asked again after this long
const RSP_RETRY_EVERY  = 1800;            // … after this long when it didn't answer
const RSP_WANT_EVERY   = 60;              // «Look again»: at most this often
const RSP_PEEK_MAX     = 120;             // kept moments read for the apps and VMs inside, per look job (newest first)
const RSP_PEEK_BUDGET  = 600;             // seconds one look job spends on them at most (the rest: the next look)
const RSP_MOUNT_ROOT   = '/mnt/addons/UnraidSecretaryOffice/restored';
const RSP_PARENT       = 'UnraidSecretaryOffice-restored';   // on a new server: <pool>/UnraidSecretaryOffice-restored/<unit>
const RSP_NAME_RE      = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/D';
const RSP_DS_RE        = '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}(?:\/[A-Za-z0-9_.: -]{1,200}){1,8}$/D';
const RSP_OWN_RE       = '/\.restored-\d{8}-\d{6}$/D';
// what is pulled from a partner is only read from: nothing in it runs, no device, no setuid (review 2026-10-15)
const RSP_MOUNT_OPTIONS = 'ro,noatime,nosuid,nodev,noexec';

// ===================================================================== places (tests point them elsewhere)

function rspMountRoot(): string
{
    return $GLOBALS['rs']['restored_root'] ?? RSP_MOUNT_ROOT;
}

function rspMountsFile(): string
{
    return $GLOBALS['rs']['mounts_file'] ?? '/proc/mounts';
}

function rspWantFile(): string
{
    return RUN_DIR . '/restore-partner.want';
}

function rspPidFile(): string
{
    return RUN_DIR . '/restore-partner-look.pid';
}

function rspZfs(): string
{
    return partnerBin('zfs') ?? 'zfs';
}

function rspHeldFile(string $id): string
{
    return partnerDir() . "/$id/held.json";
}

// ===================================================================== who keeps copies of mine

/**
 * The partners he can bring things back from: pairs I send to (my key, units), and — on a new server — ticket pairs
 * (a gone server's copies at its partner, until the ticket ends).
 *
 * @return list<array{id:string, name:string, kind:string, of:?string, units:list<string>, expires:?int, pair:array}>
 */
function rspSources(): array
{
    $out = [];
    $state = null;
    foreach (partnerPairs() as $p) {
        if ($p['my_key'] === null) {
            continue;
        }
        $state ??= partnerStateRead();
        $status = $state['pairs'][$p['id']]['status'] ?? null;
        $units = rspPairUnits(partnerSendUnits($p['id']), is_array($status) ? ($status['agreed'] ?? null) : null, rspHeld($p['id']));
        if ($units) {
            $out[] = ['id' => $p['id'], 'name' => $p['name'], 'kind' => 'pair', 'of' => null, 'units' => $units, 'expires' => null, 'pair' => $p];
        }
    }
    // a ticket: the units it was given for and what its door listed at the last look (a unit sent after it was written)
    foreach (partnerTicketPairs() as $t) {
        if ($t['expires'] > time()) {
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'kind' => 'ticket', 'of' => $t['of'], 'units' => rspPairUnits($t['units'], null, rspHeld($t['id'])),
                      'expires' => $t['expires'], 'pair' => $t];
        }
    }
    return $out;
}

/**
 * What a pair's partner may hold of mine: what I send (the levels in Mr. Backupsy's setup, partnerSendUnits()), what it
 * received or agreed to keep (its status' `agreed`, kept by the mutual watch — a unit I no longer send stays there and
 * its door still sends it back), and what its `list` actually
 * found at the last look (held.json: a unit with moments there is shown whatever the lists say). In that order, each once,
 * only in the unit's exact shape.
 *
 * @param list<string> $send
 * @return list<string>
 */
function rspPairUnits(array $send, mixed $agreed, ?array $held): array
{
    $units = [];
    foreach ([$send, partnerUnitList($agreed) ?? []] as $list) {
        foreach ($list as $u) {
            if (is_string($u) && preg_match(PARTNER_UNIT_RE, $u)) {
                $units[$u] = true;
            }
        }
    }
    foreach ((array) ($held['units'] ?? []) as $u => $snaps) {
        if (is_string($u) && preg_match(PARTNER_UNIT_RE, $u) && is_array($snaps) && $snaps) {
            $units[$u] = true;
        }
    }
    return array_slice(array_keys($units), 0, PARTNER_UNITS_MAX);
}

function rspSource(string $id): ?array
{
    foreach (rspSources() as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    return null;
}

/** A call through the source's door: a pair's or a ticket's key and pin */
function rspSsh(array $src, string $verb, array $args = [], int $timeout = PARTNER_SSH_TIMEOUT): array
{
    return $src['kind'] === 'ticket' ? partnerTicketSsh($src['pair'], $verb, $args, $timeout) : partnerSsh($src['pair'], $verb, $args, $timeout);
}

/** What a source holds of mine as last asked (held.json) — read back only in its shape */
function rspHeld(string $id): ?array
{
    if (!preg_match(PARTNER_ID_RE, $id)) {
        return null;
    }
    $j = partnerReadPrivate(rspHeldFile($id));
    if (!is_array($j) || ($j['v'] ?? null) !== 1) {
        return null;
    }
    $int = fn ($v) => is_int($v) && $v >= 0 ? $v : null;
    $units = [];
    foreach ((array) ($j['units'] ?? []) as $u => $list) {
        if (!is_string($u) || !preg_match(PARTNER_UNIT_RE, $u) || !is_array($list)) {
            continue;
        }
        $units[$u] = [];
        foreach ($list as $s) {
            if (is_array($s) && is_string($s['name'] ?? null) && preg_match(PARTNER_SNAP_RE, $s['name'])) {
                $units[$u][] = ['name' => $s['name'], 'used' => $int($s['used'] ?? null), 'referenced' => $int($s['referenced'] ?? null), 'creation' => $int($s['creation'] ?? null)];
            }
        }
    }
    $why = [];
    foreach ((array) ($j['unit_why'] ?? []) as $u => $w) {
        if (is_string($u) && preg_match(PARTNER_UNIT_RE, $u) && is_string($w) && preg_match('/^[a-z_]{1,32}$/D', $w)) {
            $why[$u] = $w;
        }
    }
    return ['looked' => $int($j['looked'] ?? null), 'tried' => $int($j['tried'] ?? null), 'reachable' => is_bool($j['reachable'] ?? null) ? $j['reachable'] : null,
            'why' => is_string($j['why'] ?? null) && preg_match('/^[a-z_]{1,32}$/D', $j['why']) ? $j['why'] : null, 'units' => $units, 'unit_why' => $why];
}

/** The look job running now? (its pid in RAM, living as agent.php) */
function rspLooking(): bool
{
    $pid = trim((string) @file_get_contents(rspPidFile(), false, null, 0, 16));
    return preg_match('/^[1-9][0-9]{0,9}$/D', $pid) === 1 && str_contains((string) @file_get_contents("/proc/$pid/cmdline", false, null, 0, 4096), 'agent.php');
}

/**
 * php agent.php job restore-partner-look: every source asked what it holds of mine (`list <unit>` per unit; the first
 * unanswered call ends the look of that source: unreachable — what was known stays, `tried` moves on). One at a time.
 * The pid file and the lock go whatever happens (a throw left the pid file, and a later agent.php under the same pid
 * would have read as «looking» for good — review 2026-10-09). $GLOBALS['rspLookOne']: the tests' stand-in.
 */
function rspLookJob(array $args = []): int
{
    if (!partnerDirReady(partnerRunDir())) {
        return 1;
    }
    $lock = @fopen(partnerRunDir() . '/look.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 75;
    }
    $look = $GLOBALS['rspLookOne'] ?? 'rspLookOne';
    try {
        @file_put_contents(rspPidFile(), (string) getmypid());
        @unlink(rspWantFile());
        $only = (string) ($args[0] ?? '');
        foreach (rspSources() as $src) {
            if ($only !== '' && $src['id'] !== $only) {
                continue;
            }
            $look($src);
        }
        // what the kept moments carry (the apps and VMs inside): read here, in the job, never in a tick
        try {
            ($GLOBALS['rspKeptPeek'] ?? 'rspKeptPeek')();
        } catch (Throwable $e) {
            logLine('Mr. Restori: could not read what the kept copies carry: ' . $e->getMessage());
        }
    } finally {
        if (trim((string) @file_get_contents(rspPidFile(), false, null, 0, 16)) === (string) getmypid()) {
            @unlink(rspPidFile());
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return 0;
}

/**
 * One source asked; its held.json written. A ticket's door is asked first which units there are now (`list` alone, a
 * door since 2026-10-28: what its pair holds, a unit sent after the ticket was written too); an older door refuses
 * that (malformed) — then the ticket's own units, as before.
 */
function rspLookOne(array $src): array
{
    $old = rspHeld($src['id']) ?? ['looked' => null, 'units' => [], 'unit_why' => []];
    $now = time();
    $units = [];
    $why = [];
    $reachable = true;
    $fail = null;
    $ask = $src['units'];
    if ($src['kind'] === 'ticket') {
        [$exit, $out] = rspSsh($src, 'list', [], 60);
        $j = partnerJsonLine($out);
        $listed = is_array($j) && ($j['ok'] ?? null) === true ? partnerUnitList($j['units'] ?? null) : null;
        $ask = $listed !== null ? array_slice(array_values(array_unique(array_merge($src['pair']['units'], $listed))), 0, PARTNER_UNITS_MAX) : $ask;
    }
    foreach ($ask as $u) {
        [$exit, $out, $err] = rspSsh($src, 'list', [$u], 60);
        $j = partnerJsonLine($out);
        if ($exit === 255 || $exit === 124 || $exit === 2 || !is_array($j)) {
            $reachable = false;
            $fail = $exit === 255 ? (preg_match('/Host key verification failed/i', $err) ? 'host_key' : (preg_match('/Permission denied/i', $err) ? 'denied' : 'unreachable'))
                : ($exit === 124 ? 'timeout' : ($exit === 2 ? 'no_key' : 'bad_answer'));
            break;
        }
        if (($j['ok'] ?? null) !== true) {
            $w = is_string($j['why'] ?? null) && preg_match('/^[a-z_]{1,32}$/D', $j['why']) ? $j['why'] : 'refused';
            if (in_array($w, ['array_stopped', 'ticket_expired', 'no_ticket', 'no_pair', 'refused_asleep'], true)) {
                $reachable = false;
                $fail = $w;
                break;
            }
            $units[$u] = [];
            $why[$u] = $w;
            continue;
        }
        $units[$u] = [];
        foreach ((array) ($j['snaps'] ?? []) as $s) {
            if (is_array($s) && is_string($s['name'] ?? null) && preg_match(PARTNER_SNAP_RE, $s['name'])) {
                $units[$u][] = ['name' => $s['name'], 'used' => is_int($s['used'] ?? null) ? $s['used'] : null,
                                'referenced' => is_int($s['referenced'] ?? null) ? $s['referenced'] : null, 'creation' => is_int($s['creation'] ?? null) ? $s['creation'] : null];
            }
        }
    }
    $held = $reachable
        ? ['v' => 1, 'looked' => $now, 'tried' => $now, 'reachable' => true, 'why' => null, 'units' => $units, 'unit_why' => $why]
        : ['v' => 1, 'looked' => $old['looked'], 'tried' => $now, 'reachable' => false, 'why' => $fail, 'units' => $old['units'], 'unit_why' => $old['unit_why']];
    try {
        partnerWritePrivate(rspHeldFile($src['id']), $held);
    } catch (Throwable $e) {
        logLine('Mr. Restori: could not keep what ' . $src['name'] . ' holds: ' . $e->getMessage());
    }
    return $held;
}

/**
 * From his tick, once a minute: the look job handed to atd when a source wasn't asked for RSP_LOOK_EVERY (after a
 * silence RSP_RETRY_EVERY) or «Look again» asked (a file in RAM) — and when a held.json changed, his state's
 * `partners` anew (files only).
 */
function rspTick(): void
{
    $now = time();
    if ($now < ($GLOBALS['rspNext'] ?? 0)) {
        return;
    }
    $GLOBALS['rspNext'] = $now + 60;
    if (!is_file(partnerDir() . '/pairs.json') && !is_file(partnerDir() . '/ticket-pairs.json')) {
        return;
    }
    $sources = rspSources();
    $sig = [];
    $due = false;
    foreach ($sources as $src) {
        clearstatcache(true, rspHeldFile($src['id']));
        $sig[] = $src['id'] . ':' . (int) @filemtime(rspHeldFile($src['id']));
        $h = rspHeld($src['id']);
        $age = $now - (int) ($h['tried'] ?? 0);
        $due = $due || $h === null || $age >= (($h['reachable'] ?? false) ? RSP_LOOK_EVERY : RSP_RETRY_EVERY);
    }
    // a backup run that just sent to a partner: look at once (the partner's list is what «At <partner>» shows — until
    // now it waited up to RSP_LOOK_EVERY after a transfer, 2026-10-12)
    if (defined('BACKUP_DATA_DIR')) {
        $lr = BACKUP_DATA_DIR . '/state/last-run.json';
        clearstatcache(true, $lr);
        $lrAt = (int) @filemtime($lr);
        if ($lrAt && $lrAt !== ($GLOBALS['rspLastRun'] ?? null)) {
            $sentNow = isset($GLOBALS['rspLastRun']) && (int) ((readJson($lr) ?? [])['partner_ok'] ?? 0) > 0;
            $GLOBALS['rspLastRun'] = $lrAt;
            if ($sentNow) {
                @touch(rspWantFile());
            }
        }
    }
    $want = is_file(rspWantFile());
    // a kept moment not read yet on an awake pool (his state's `kept`, files only): the look job reads it
    $peekDue = false;
    foreach ((array) ($GLOBALS['rs']['state']['kept'] ?? []) as $k) {
        foreach (empty($k['asleep']) ? (array) ($k['units'] ?? []) : [] as $u) {
            $peekDue = $peekDue || (($u['unit'] ?? 'place') !== 'place' && array_filter((array) ($u['snaps'] ?? []), fn ($sn) => ($sn['inside'] ?? null) === null && empty($sn['unread'])));
        }
    }
    if (($sources || $peekDue) && ($due || $want || $peekDue) && !rspLooking() && $now - (int) ($GLOBALS['rspLaunched'] ?? 0) >= ($want ? RSP_WANT_EVERY : 600)) {
        $GLOBALS['rspLaunched'] = $now;
        try {
            hostLaunch('restore-partner-look', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'restore-partner-look']);
        } catch (Throwable $e) {
            logLine('Mr. Restori: the look at the partners could not be started: ' . $e->getMessage());
        }
    }
    // what the door received of a partner (received/<id>.json, written at the end of every receive): the kept list
    // anew - its moments come from the datasets here (2026-10-12: the 11:21 transfer stood on the tile, the moments stayed old)
    $recv = [];
    foreach (partnerPairs() as $p) {
        if (is_array($p['receive'] ?? null)) {
            $f = partnerDir() . "/received/{$p['id']}.json";
            clearstatcache(true, $f);
            $recv[] = $p['id'] . ':' . (int) @filemtime($f);
        }
    }
    $sig = implode(',', $sig) . ($want ? '+' : '') . (rspLooking() ? '*' : '');
    clearstatcache(true, rspPeekFile());
    $recv[] = 'peek:' . (int) @filemtime(rspPeekFile());          // the look job read what the kept moments carry
    $recvSig = implode(',', $recv);
    if (($sig !== ($GLOBALS['rspSig'] ?? null) || $recvSig !== ($GLOBALS['rspRecvSig'] ?? null)) && is_array($GLOBALS['rs']['state'] ?? null)) {
        $kept = $recvSig !== ($GLOBALS['rspRecvSig'] ?? null);
        $GLOBALS['rspSig'] = $sig;
        $GLOBALS['rspRecvSig'] = $recvSig;
        $state = $GLOBALS['rs']['state'];
        $state['partners'] = rspState();
        if ($kept) {
            $state['kept'] = rspKept();
        }
        rsWrite($state);
    }
}

/** «Look again» on his partner tile: a file in RAM, his tick hands the look to atd */
function rspWantLook(): array
{
    @touch(rspWantFile());
    $GLOBALS['rspNext'] = 0;
    return ['ok' => true, 'looking' => true];
}

/**
 * His state's `partners`: per source what it holds of mine, newest moment first — never a key. A partner that answers
 * but no longer knows the pair (`no_pair`: set up anew, the Team Lead's partner_no_pair): what it said it keeps is from
 * then — its units unknown now, no moment offered (its door refuses every pull until the two are paired again).
 */
function rspState(): array
{
    $out = [];
    $looking = rspLooking() || is_file(rspWantFile());
    foreach (rspSources() as $src) {
        $h = rspHeld($src['id']);
        $gone = ($h['why'] ?? null) === 'no_pair';
        $units = [];
        foreach ($src['units'] as $u) {
            $snaps = $gone ? [] : array_reverse((array) ($h['units'][$u] ?? []));
            $units[] = ['unit' => $u, 'snaps' => $snaps, 'newest' => $snaps[0]['creation'] ?? null, 'why' => $h['unit_why'][$u] ?? null,
                        'known' => !$gone && isset($h['units'][$u])];
        }
        usort($units, fn ($a, $b) => [$a['unit'] !== 'place', $a['unit']] <=> [$b['unit'] !== 'place', $b['unit']]);
        $out[] = ['id' => $src['id'], 'name' => $src['name'], 'kind' => $src['kind'], 'of' => $src['of'], 'expires' => $src['expires'],
                  'looked' => $h['looked'] ?? null, 'tried' => $h['tried'] ?? null, 'reachable' => $h['reachable'] ?? null, 'why' => $h['why'] ?? null,
                  'looking' => $looking, 'units' => $units, 'pools' => $src['kind'] === 'ticket' ? partnerPools() : null];
    }
    return $out;
}

/**
 * The chip «at <partner>» of an app or VM row: the partners holding a unit its data lies in (its shares, its own VM
 * dataset, the app's own unit) — the newest moment each. A folder in a dataset of its own is no part of its share's
 * stream: the caller leaves its share out ('' in $shares), only the app's or VM's own unit counts (the truth review T06).
 *
 * @return list<array{id:string, name:string, unit:string, newest:?int, n:int}>
 */
function rspChips(array $partners, array $shares, ?string $vm = null, ?string $app = null): array
{
    $want = array_map(fn ($s) => "share:$s", array_values(array_unique(array_filter($shares, fn ($s) => is_string($s) && $s !== ''))));
    if ($vm !== null) {
        $want[] = "vm:$vm";
    }
    if ($app !== null) {
        $want[] = "app:$app";
    }
    $out = [];
    foreach ($partners as $p) {
        foreach ($p['units'] as $u) {
            if (in_array($u['unit'], $want, true) && $u['snaps']) {
                $out[] = ['id' => $p['id'], 'name' => $p['name'], 'unit' => $u['unit'], 'newest' => $u['newest'], 'n' => count($u['snaps'])];
            }
        }
    }
    return $out;
}

// ===================================================================== the plan

/** A unit's plain name: the share's, the VM's — the backup place's share for `place` */
function rspUnitName(string $unit, ?string $place = null): string
{
    return $unit === 'place' ? ($place ?: BACKUP_OFFICE_SHARE) : substr($unit, strpos($unit, ':') + 1);
}

function rspDsExists(string $ds): bool
{
    return run([rspZfs(), 'list', '-H', '-o', 'name', $ds], 20)[0] === 0;
}

function rspDsGet(string $ds, string $prop): ?string
{
    [$exit, $out] = run([rspZfs(), 'get', '-H', '-p', '-o', 'value', $prop, $ds], 20);
    $v = trim($out);
    return $exit === 0 && $v !== '' && $v !== '-' ? $v : null;
}

/** Free bytes of a pool (zfs avail of its root) */
function rspPoolFree(string $pool): ?int
{
    $v = rspDsGet($pool, 'available');
    return $v !== null && ctype_digit($v) ? (int) $v : null;
}

/** Is this a dataset a pull of his made (and only then may his undo destroy it)? */
function rspOwnTarget(string $ds): bool
{
    if (!preg_match(RSP_DS_RE, $ds) || str_contains($ds, '@')) {
        return false;
    }
    return preg_match(RSP_OWN_RE, $ds) === 1 || preg_match('#^[A-Za-z][A-Za-z0-9_.:-]{0,63}/' . RSP_PARENT . '/[A-Za-z0-9_.: -]{1,200}$#D', $ds) === 1;
}

/** A /mnt/<pool>/<share>[/<folder>] mountpoint as the share path /mnt/user/<share>[/<folder>] (null: not a share's) */
function rspUserPath(?string $mountpoint): ?string
{
    if ($mountpoint === null || !preg_match('#^/mnt/([^/]+)/([^/]+)(/[^/]+)?$#', $mountpoint, $m) || in_array($m[1], ['user', 'user0', 'addons', 'disks', 'remotes'], true)) {
        return null;
    }
    return "/mnt/user/$m[2]" . ($m[3] ?? '');
}

/**
 * Where a pull lands: always a NEW dataset, never over anything.
 *   a pair (my own copies come back): <the unit's dataset here>.restored-<time> beside it — the dataset from the
 *     engine's record of what it sent (state/partner-sent.json), else from its plan (partnerUnits())
 *   a ticket (a new server): a share that exists here with a dataset <pool>/<share> → <pool>/<share>.restored-<time>
 *     (swapped in by hand, said how); a share made here on this pool that has no dataset yet (Unraid makes
 *     /mnt/<pool>/<share> only at the first write — «create the shares first» never gave one, the drill 2026-10-28)
 *     with nothing at its place (no folder, or an empty plain one: rspShareFresh()) → the same, «Swap it in…» then
 *     makes it the share without a dataset going aside; else <pool>/UnraidSecretaryOffice-restored/<unit> (Unraid makes
 *     shares — never he), said why
 *
 * @return array{dataset:?string, pool:?string, parent:?string, make_parent:bool, path:?string, how:string, blockers:list<array>, notes:list<array>}
 */
function rspTarget(array $src, string $unit, ?string $pool, string $stamp, array $ctx = []): array
{
    $out = ['dataset' => null, 'pool' => null, 'parent' => null, 'make_parent' => false, 'path' => null, 'how' => 'beside', 'blockers' => [], 'notes' => []];
    $dashed = (string) preg_replace('/[^A-Za-z0-9_.:-]/', '_', str_replace(':', '-', $unit));
    if ($src['kind'] === 'pair') {
        $sent = readJson(rsUbData() . '/state/partner-sent.json');
        $orig = is_array($sent) ? ($sent[$src['id']][$unit]['dataset'] ?? null) : null;
        if (!is_string($orig) || !preg_match(RSP_DS_RE, $orig)) {
            $orig = null;
            foreach (partnerUnits() as $u) {
                $orig = $u['id'] === $unit && $u['dataset'] !== null ? $u['dataset'] : $orig;
            }
        }
        if ($orig === null) {
            $out['blockers'][] = ['key' => 'restore_partner_no_dataset', 'params' => ['unit' => $unit]];
            return $out;
        }
        $out['pool'] = explode('/', $orig)[0];
        $out['dataset'] = "$orig.restored-$stamp";
        $out['parent'] = substr($orig, 0, (int) strrpos($orig, '/'));
        $out['path'] = $unit === 'place' ? null : (rspUserPath(rspDsGet($orig, 'mountpoint')) ?? ($unit !== '' && str_starts_with($unit, 'share:') ? '/mnt/user/' . substr($unit, 6) : null));
        $out['orig'] = $orig;
        return $out;
    }
    // a ticket: the pool chosen (awake ZFS pools only; not chosen: the share's own pool when it is one of them), the share as it is here
    $pools = array_values(array_filter(partnerPools(), fn ($p) => !$p['asleep']));
    $shares = $ctx['shares_ini'] ?? readCfg(RS_SHARES_INI, true);
    $share = str_starts_with($unit, 'share:') ? substr($unit, 6) : null;
    $its = $share !== null && is_array($shares[$share] ?? null) ? rspSharePool($shares[$share]) : null;
    $pool ??= $its !== null && in_array($its, array_column($pools, 'name'), true) ? $its : ($pools[0]['name'] ?? null);
    if ($pool === null || !in_array($pool, array_column($pools, 'name'), true)) {
        $out['blockers'][] = ['key' => $pools ? 'restore_partner_pool' : 'restore_partner_no_pool', 'params' => ['pool' => (string) $pool]];
        return $out;
    }
    $out['pool'] = $pool;
    if ($share !== null) {
        $out['path'] = "/mnt/user/$share";
        $here = is_array($shares) && isset($shares[$share]);
        if ($here && rspDsExists("$pool/$share")) {
            $out['dataset'] = "$pool/$share.restored-$stamp";
            $out['parent'] = $pool;
            $out['how'] = 'share_exists';
            $out['notes'][] = ['key' => 'note.partner_share_exists', 'params' => ['share' => $share, 'dataset' => $out['dataset']]];
            return $out;
        }
        $fresh = $here ? rspShareFresh($share, $pool, (array) $shares[$share]) : 'missing';
        if ($fresh === null) {
            $out['dataset'] = "$pool/$share.restored-$stamp";
            $out['parent'] = $pool;
            $out['how'] = 'share_fresh';
            $out['notes'][] = ['key' => 'note.partner_share_fresh', 'params' => ['share' => $share, 'pool' => $pool, 'dataset' => $out['dataset']]];
            return $out;
        }
        // why not beside its place, said before the pull: the share lives elsewhere, or its folder there holds something
        $out['notes'][] = match ($fresh) {
            'missing' => is_array($shares) && rspShareUnlisted($share, $shares) !== null ? ['key' => 'note.partner_share_unlisted', 'params' => ['share' => $share]]
                : ['key' => 'note.partner_share_missing', 'params' => ['share' => $share]],
            'folder'  => ['key' => 'note.partner_share_folder', 'params' => ['share' => $share, 'pool' => $pool]],
            default   => ['key' => 'note.partner_share_elsewhere', 'params' => ['share' => $share]],
        };
    } elseif (str_starts_with($unit, 'vm:')) {
        $name = substr($unit, 3);
        foreach ((array) ($GLOBALS['rs']['state']['vms'] ?? []) as $v) {
            if (($v['name'] ?? null) === $name && is_string($v['folders'][0]['path'] ?? null)) {
                $out['path'] = $v['folders'][0]['path'];
            }
        }
    }
    $parent = "$pool/" . RSP_PARENT;
    $ds = "$parent/$dashed";
    if (rspDsExists($ds)) {
        $ds .= ".restored-$stamp";
    }
    $out['dataset'] = $ds;
    $out['parent'] = $parent;
    $out['make_parent'] = !rspDsExists($parent);
    $out['how'] = 'restored_parent';
    return $out;
}

/** The pool a share's data lives on first (shares.ini: useCache only/yes/prefer with its cachePool), null: the array */
function rspSharePool(array $ini): ?string
{
    $pool = (string) ($ini['cachePool'] ?? '');
    return in_array($ini['useCache'] ?? 'no', ['only', 'yes', 'prefer'], true) && preg_match(PARTNER_POOL_RE, $pool) ? $pool : null;
}

/** The pools' top folder (/mnt; the tests' stand-in) */
function rspPoolRoot(): string
{
    return $GLOBALS['rs']['pool_root'] ?? '/mnt';
}

/**
 * Can a pull become this share at its place on this pool although it has no dataset there (a share just made by Unraid:
 * /mnt/<pool>/<share> comes only at the first write)? Null: yes — the share's data lives on this pool first, no dataset
 * <pool>/<share>, nothing at its place (no folder, or an empty plain folder — never over data). Else why not: `elsewhere`
 * (the array or another pool), `folder` (something there: a plain folder with entries, a link, a file).
 */
function rspShareFresh(string $share, string $pool, array $ini): ?string
{
    if (rspSharePool($ini) !== $pool || rspDsExists("$pool/$share")) {
        return 'elsewhere';
    }
    return rspPlaceEmpty(rspPoolRoot() . "/$pool/$share") ? null : 'folder';
}

/** Unraid's share settings on the flash (tests: their stand-in) */
function rspSharesCfgDir(): string
{
    return $GLOBALS['rs']['shares_cfg'] ?? '/boot/config/shares';
}

/**
 * A share Unraid took off its list (2026-10-29, the drill's «Put back» of a share swapped in fresh): Unraid lists a
 * share only while a folder of it exists on a disk or pool. A put back that leaves none (the pull was its only folder)
 * drops it from shares.ini within seconds; its settings (/boot/config/shares/<share>.cfg) stay, and it is listed again
 * as soon as a folder exists — seen on test3 (7.4.0-rc.1) for a plain folder and a dataset alike. Null while it is
 * listed, when shares.ini can't be read ($ini: as read already), or when the flash has no settings of it (then it is no
 * share at all).
 * Else {share, pool (its first pool; null: the array), folder, can, why}: `can` — the one-click «Make its empty folder»
 * (rspShareFolder()): the first pool is a ZFS pool, awake, putting its datasets under /mnt/<pool>, and nothing of the
 * share is there (no dataset, no folder); `why` says what stands against it (array, not_zfs, asleep, mountpoint, there).
 */
function rspShareUnlisted(string $share, ?array $ini = null): ?array
{
    $ini ??= rspSharesHere();
    $cfgFile = rspSharesCfgDir() . "/$share.cfg";
    if (!preg_match('/^[\w .-]{1,200}$/D', $share) || $ini === null || isset($ini[$share]) || is_link($cfgFile) || !is_file($cfgFile)) {
        return null;
    }
    $cfg = readCfg($cfgFile);
    $pool = rspSharePool(['useCache' => (string) ($cfg['shareUseCache'] ?? 'no'), 'cachePool' => (string) ($cfg['shareCachePool'] ?? '')]);
    $out = ['share' => $share, 'pool' => $pool, 'folder' => $pool !== null ? "/mnt/$pool/$share" : null, 'can' => false, 'why' => null];
    if ($pool === null) {
        return array_replace($out, ['why' => 'array']);
    }
    if (!rspDsExists($pool)) {
        return array_replace($out, ['why' => 'not_zfs']);
    }
    if (baseAsleep($pool, rspAsleepDisks())) {
        return array_replace($out, ['why' => 'asleep']);            // never woken for it: said, the click waits
    }
    if ((rspDsProp($pool, 'mountpoint')['value'] ?? null) !== "/mnt/$pool") {
        return array_replace($out, ['why' => 'mountpoint']);
    }
    $place = rspPoolRoot() . "/$pool/$share";
    if (rspDsExists("$pool/$share") || @lstat($place) !== false) {
        return array_replace($out, ['why' => 'there']);
    }
    return array_replace($out, ['can' => true]);
}

/** A swap's row (rsJournals()): its share off Unraid's list once the swap is put back (rspShareUnlisted()), else null */
function rspSwapUnlisted(array $row): ?array
{
    $share = (string) ($row['target']['share'] ?? '');
    return $share !== '' && in_array($row['putback']['result'] ?? '', ['ok', 'warnings'], true) ? rspShareUnlisted($share) : null;
}

/**
 * «Make its empty folder» of a share Unraid took off its list (rspShareUnlisted()): the share's dataset on its first
 * pool, empty, made as Unraid makes one (`zfs create <pool>/<share>`, its mountpoint from the pool), its root
 * nobody:users 0777 as Unraid's own share roots are — Unraid lists the share again at once, with its settings. Not a
 * share made by the office: the share is the user's (its .cfg, never written here); only its folder comes back.
 * Looked at again right before; refused when anything is there or the pool sleeps.
 */
function rspShareFolder(array $r): array
{
    $share = textField($r, 'share');
    $u = rspShareUnlisted($share);
    if ($u === null) {
        throw new Problem('restore_share_listed', ['share' => $share]);
    }
    if (!$u['can']) {
        throw new Problem('restore_share_folder_not', ['share' => $share, 'pool' => (string) $u['pool']]);
    }
    $ds = "{$u['pool']}/$share";
    [$exit, , $err] = run([rspZfs(), 'create', $ds], 60);
    if ($exit !== 0 || rspDsGet($ds, 'mounted') !== 'yes' || rspDsGet($ds, 'mountpoint') !== $u['folder']) {
        logLine("Mr. Restori: the empty folder of the share $share ($ds) could not be made: " . trim((string) $err));
        throw new Problem('restore_share_folder_failed', ['share' => $share, 'path' => (string) $u['folder']]);
    }
    $place = rspPoolRoot() . "/{$u['pool']}/$share";
    @chown($place, 99);
    @chgrp($place, 100);
    @chmod($place, 0777);
    logLine("Mr. Restori: the share $share had no folder (off Unraid's list) — its empty dataset $ds made, nobody:users 0777; its settings untouched");
    return ['ok' => true, 'share' => $share, 'folder' => $u['folder']];
}

/** Nothing at a path, or an empty plain folder there (never a link) */
function rspPlaceEmpty(string $path): bool
{
    $st = @lstat($path);
    if ($st === false) {
        return true;
    }
    if (is_link($path) || !is_dir($path)) {
        return false;
    }
    $d = @opendir($path);
    if ($d === false) {
        return false;
    }
    while (($e = readdir($d)) !== false) {
        if ($e !== '.' && $e !== '..') {
            closedir($d);
            return false;
        }
    }
    closedir($d);
    return true;
}

/** An interrupted pull of the same unit and moment whose dataset still holds its resume token: its dataset (it goes on) */
function rspResumable(string $id, string $unit, string $snap): ?string
{
    foreach (rsJournals() as $row) {
        if ($row['kind'] !== 'partner' || !in_array($row['result'], ['failed', 'interrupted'], true) || $row['putback'] !== null) {
            continue;
        }
        $j = rsJournal($row['id']);
        foreach ((array) ($j['steps'] ?? []) as $s) {
            if (($s['do'] ?? '') === 'pull' && ($s['pair'] ?? '') === $id && ($s['unit'] ?? '') === $unit && ($s['snap'] ?? '') === $snap
                && is_string($s['dataset'] ?? null) && rspOwnTarget($s['dataset']) && rspDsGet($s['dataset'], 'receive_resume_token') !== null) {
                return $s['dataset'];
            }
        }
    }
    return null;
}

/** The folder under /mnt/addons/UnraidSecretaryOffice/restored a pull is mounted at (read-only) */
function rspMountPath(string $partner, string $unit, string $stamp): string
{
    $name = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '_', "$partner-" . str_replace(':', '-', $unit) . "-$stamp"), 0, 150);
    return rspMountRoot() . '/' . (preg_match(RSP_NAME_RE, $name) ? $name : "pull-$stamp");
}

/**
 * Kind `partner`: a unit's moment at a partner pulled into a new dataset beside (rspTarget()), mounted read-only — then
 * his restores take it from there (the plan says so in «Afterwards»). A VM's configuration lives in its package in the
 * backup place: is the place held there too, it is pulled along (that moment, or the newest); if not, the plan says
 * plainly that only the disks come back.
 */
function rsPlanPartner(array $r, string $stamp): array
{
    $id = textField($r, 'pair');
    $unit = textField($r, 'unit');
    $snap = textField($r, 'snap');
    $pool = is_string($r['pool'] ?? null) && preg_match(PARTNER_POOL_RE, $r['pool']) ? $r['pool'] : null;
    if (!preg_match(PARTNER_ID_RE, $id) || !preg_match(PARTNER_UNIT_RE, $unit) || !preg_match(PARTNER_SNAP_RE, $snap)) {
        throw new Problem('unknown_target', ['target' => "$id $unit $snap"]);
    }
    $src = rspSource($id);
    if ($src === null || !in_array($unit, $src['units'], true)) {
        throw new Problem('unknown_target', ['target' => "$id $unit"]);
    }
    $settings = backupReadSettings(rsUbData() . '/settings.ini');
    $placeShare = rsPlaceShare($settings)['share'];
    $plan = rsPlanBase('partner', rspUnitName($unit, $placeShare) . ' ← ' . $src['name'],
        ['pair' => $id, 'unit' => $unit, 'snap' => $snap, 'pool' => $pool, 'kind' => $src['kind'], 'partner' => $src['name']]);
    $held = rspHeld($id);
    $moment = null;
    foreach ((array) ($held['units'][$unit] ?? []) as $s) {
        $moment = $s['name'] === $snap ? $s : $moment;
    }
    $plan['options'] = ['pools' => $src['kind'] === 'ticket' ? partnerPools() : null, 'kind' => $src['kind']];
    if (($held['why'] ?? null) === 'no_pair') {
        // it no longer knows this pair: its door would refuse the pull — what it keeps is unknown now (the Team Lead's finding)
        $plan['blockers'][] = ['key' => 'restore_partner_no_pair', 'params' => ['name' => $src['name']]];
        return $plan;
    }
    if ($moment === null) {
        $plan['blockers'][] = ['key' => 'restore_partner_no_snap', 'params' => ['snap' => $snap, 'name' => $src['name'], 'when' => $held['looked'] ?? null]];
        return $plan;
    }
    $plan['source'] = ['path' => "{$src['name']}: $unit@$snap", 'time' => $moment['creation'], 'bytes' => $moment['referenced']];
    $steps = [];
    $need = 0;
    $pools = [];
    $pulls = [[$unit, $snap, $moment]];
    if (str_starts_with($unit, 'vm:')) {
        // the VM's XML, NVRAM and TPM state live in its package in the backup place
        $places = (array) ($held['units']['place'] ?? []);
        if (in_array('place', $src['units'], true) && $places) {
            $pick = null;
            foreach ($places as $s) {
                $pick = $s['name'] === $snap ? $s : $pick;
            }
            $pick ??= end($places);
            // that moment of the place pulled already and still mounted (a new server pulls it first): its packages are
            // at hand — never a second copy of it (the restore-ticket drill 2026-10-28 pulled the same 4 GB twice)
            $have = null;
            foreach (rspPulled() as $pp) {
                $have = $pp['unit'] === 'place' && $pp['mounted'] && $pp['pair'] === $id && $pp['snap'] === $pick['name'] ? $pp : $have;
            }
            if ($have !== null) {
                $plan['notes'][] = ['key' => 'note.partner_place_have', 'params' => ['name' => $src['name'], 'snap' => $pick['name'], 'path' => (string) $have['mount']]];
            } else {
                $pulls[] = ['place', $pick['name'], $pick];
                $plan['notes'][] = ['key' => 'note.partner_place_too', 'params' => ['name' => $src['name'], 'snap' => $pick['name']]];
            }
        } else {
            $plan['notes'][] = ['key' => 'note.partner_no_package', 'params' => ['name' => $src['name']], 'warn' => true];
        }
    }
    foreach ($pulls as $n => [$u, $s, $m]) {
        $resume = rspResumable($id, $u, $s);
        $t = $resume !== null ? ['dataset' => $resume, 'pool' => explode('/', $resume)[0], 'parent' => null, 'make_parent' => false, 'how' => 'resume',
            'path' => rspTarget($src, $u, $pool, $stamp)['path'], 'blockers' => [], 'notes' => []] : rspTarget($src, $u, $pool, $stamp);
        array_push($plan['blockers'], ...$t['blockers']);
        array_push($plan['notes'], ...$t['notes']);
        if ($t['dataset'] === null) {
            continue;
        }
        if ($resume !== null) {
            $plan['notes'][] = ['key' => 'note.partner_resume', 'params' => ['dataset' => $resume]];
        } elseif (rspDsExists($t['dataset'])) {
            $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $t['dataset']]];
        } elseif ($t['parent'] !== null && !$t['make_parent'] && !rspDsExists($t['parent'])) {
            $plan['blockers'][] = ['key' => 'restore_partner_no_parent', 'params' => ['dataset' => $t['parent']]];
        }
        $mnt = rspMountPath($src['name'], $u, $stamp);
        $steps[] = ['do' => 'pull', 'pair' => $id, 'unit' => $u, 'snap' => $s, 'dataset' => $t['dataset'], 'resume' => $resume !== null,
                    'parent' => $t['make_parent'] ? $t['parent'] : null, 'partner' => $src['name'], 'path' => $t['path'], 'time' => $m['creation'], 'mount' => $mnt];
        $steps[] = ['do' => 'mount', 'dataset' => $t['dataset'], 'path' => $mnt];
        $plan['aside'][] = ['what' => 'pulled', 'from' => "{$src['name']}: $u@$s", 'to' => $t['dataset']];
        $need += (int) ($m['referenced'] ?? 0);
        $pools[$t['pool']] = true;
        if ($n === 0) {
            $plan['target']['dataset'] = $t['dataset'];
            $plan['target']['how'] = $t['how'];
            $plan['target']['path'] = $t['path'];
        }
        $label = rspUnitName($u, $placeShare);
        if ($u === 'place') {
            $plan['after'][] = ['key' => $src['kind'] === 'ticket' && !rsPlaceHere() ? 'after.partner_place_new' : 'after.partner_place', 'params' => ['path' => $mnt, 'name' => $src['name']]];
        } elseif ($t['path'] !== null) {
            $plan['after'][] = ['key' => 'after.partner_then', 'params' => ['path' => $t['path'], 'name' => $src['name'], 'what' => $label]];
        } else {
            $plan['after'][] = ['key' => 'after.partner_mounted', 'params' => ['path' => $mnt]];
        }
        if ($t['how'] === 'share_exists') {
            $plan['after'][] = ['key' => 'after.partner_swap', 'params' => ['dataset' => $t['dataset'], 'share' => $label, 'pool' => $t['pool']]];
        } elseif ($t['how'] === 'share_fresh') {
            $plan['after'][] = ['key' => 'after.partner_swap_fresh', 'params' => ['dataset' => $t['dataset'], 'share' => $label, 'pool' => $t['pool']]];
        } elseif ($t['how'] === 'restored_parent' && str_starts_with($u, 'share:')) {
            $plan['after'][] = ['key' => 'after.partner_restored_parent', 'params' => ['dataset' => $t['dataset'], 'share' => $label]];
        }
    }
    $plan['steps'] = $steps;
    $plan['notes'][] = ['key' => 'note.partner_beside', 'params' => ['name' => $src['name']]];
    $plan['after'][] = ['key' => 'after.partner_drop', 'params' => []];
    $free = count($pools) === 1 ? rspPoolFree((string) array_key_first($pools)) : null;
    $plan['sizes'] = ['need' => $need, 'free' => $free, 'measuring' => false, 'compresses' => true];
    if ($free !== null && $need > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $need, 'free' => $free]];
    }
    if ($src['kind'] === 'ticket' && $src['expires'] - time() < 3600) {
        $plan['notes'][] = ['key' => 'note.partner_ticket_ends', 'params' => ['when' => $src['expires']], 'warn' => true];
    }
    $plan['downtime'] = 0;
    return $plan;
}

/** Does this server have a backup place of its own (with packages)? */
function rsPlaceHere(): bool
{
    $base = backupDumpsPath(rsPlaceShare(backupReadSettings(rsUbData() . '/settings.ini'))['share']);
    return $base !== null && (is_dir("$base/apps") || is_dir("$base/vms") || is_file("$base/server/run.json"));
}

// ===================================================================== the steps

/**
 * The pull: ssh (the door's send-back) | pv -n | mbuffer | zfs recv -s -u into the new dataset — or, an interrupted
 * one's token here, `send-back <unit> -t <token>` into it. Progress (bytes of the door's size) in the journal; the stop
 * (SIGTERM, the array stopping) ends the whole pipe — zfs recv keeps its token for the next try. Its undo: «drop»
 * exactly this dataset (name and, once there, guid).
 */
function rsDoPull(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $ds = (string) $s['dataset'];
    $unit = (string) $s['unit'];
    $snap = (string) $s['snap'];
    if (!rspOwnTarget($ds) || !preg_match(PARTNER_UNIT_RE, $unit) || !preg_match(PARTNER_SNAP_RE, $snap)) {
        return rsFail('pull_refused', ['dataset' => $ds]);
    }
    $src = rspSource((string) $s['pair']);
    if ($src === null) {
        return rsFail('partner_gone', ['name' => (string) ($s['partner'] ?? '')]);
    }
    $zfs = rspZfs();
    $undo = [['do' => 'drop', 'dataset' => $ds, 'path' => (string) ($s['mount'] ?? '')]];
    if (($s['parent'] ?? null) !== null && !rspDsExists((string) $s['parent'])) {
        if (rsRun($j, [$zfs, 'create', '-o', 'mountpoint=none', '-o', 'canmount=off', (string) $s['parent']], 60)[0] !== 0) {
            return rsFail('mkdir_failed', ['path' => (string) $s['parent']]);
        }
    }
    $token = rspDsGet($ds, 'receive_resume_token');
    if ($token !== null && !preg_match(PARTNER_TOKEN_RE, $token)) {
        return rsFail('pull_failed', ['dataset' => $ds], 'a resume token not in the shape of one');
    }
    if ($token === null && rspDsExists($ds)) {
        return rsFail('exists', ['path' => $ds]);
    }
    $words = $token !== null ? "send-back $unit -t $token" : "send-back $unit $snap";
    $recv = $token !== null ? [$zfs, 'recv', '-s', '-u', $ds]
        : [$zfs, 'recv', '-s', '-u', '-o', 'mountpoint=legacy', '-o', 'canmount=noauto', '-x', 'sharesmb', '-x', 'sharenfs', "$ds@$snap"];
    rsLog($j['id'], "  pull: $words (from {$src['name']}) -> " . implode(' ', $recv));
    $dir = rsDir($j['id']);
    $errFile = "$dir/pull-$i.door";
    $pvFile = "$dir/pull-$i.pv";
    foreach ([$errFile, $pvFile] as $f) {
        @unlink($f);
    }
    $log = "$dir/log.txt";
    $env = rsEnv();
    $procs = [];
    $close = function () use (&$procs): void {
        foreach ($procs as $p) {
            @proc_terminate($p);
            @proc_close($p);
        }
    };
    $ssh = proc_open(partnerSshArgs($src['pair'], $words), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $sp, '/', $env);
    if (!is_resource($ssh)) {
        return rsFail('pull_failed', ['dataset' => $ds], 'ssh did not start') + ['undo' => $undo];
    }
    $procs[] = $ssh;
    $prev = $sp[1];
    foreach (array_filter([partnerBin('pv') !== null ? [partnerBin('pv'), '-n', '-b', '-i', '2'] : null,
                           partnerBin('mbuffer') !== null ? [partnerBin('mbuffer'), '-q', '-s', '128k', '-m', '256M'] : null]) as $cmd) {
        $p = proc_open($cmd, [0 => $prev, 1 => ['pipe', 'w'], 2 => str_ends_with($cmd[0], 'pv') ? ['file', $pvFile, 'w'] : ['file', '/dev/null', 'w']], $pp, '/', $env);
        fclose($prev);
        if (!is_resource($p)) {
            $close();
            return rsFail('pull_failed', ['dataset' => $ds], basename($cmd[0]) . ' did not start') + ['undo' => $undo];
        }
        $procs[] = $p;
        $prev = $pp[1];
    }
    $r = proc_open($recv, [0 => $prev, 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a']], $rp, '/', $env);
    fclose($prev);
    if (!is_resource($r)) {
        $close();
        return rsFail('pull_failed', ['dataset' => $ds], 'zfs recv did not start') + ['undo' => $undo];
    }
    $procs[] = $r;
    $size = null;
    $progress = function () use ($errFile, $pvFile, &$size): array {
        if ($size === null) {
            foreach (rspDoorLines((string) @file_get_contents($errFile, false, null, 0, 65536)) as $l) {
                $size ??= is_int($l['size'] ?? null) && $l['size'] > 0 ? $l['size'] : null;
            }
        }
        $lines = preg_split('/\s+/', trim((string) @file_get_contents($pvFile, false, null, max(0, (int) @filesize($pvFile) - 256)))) ?: [];
        $done = (int) preg_replace('/\D/', '', (string) end($lines));
        return ['done' => $done, 'total' => $size, 'percent' => $size ? min(100, (int) floor(100 * $done / $size)) : null];
    };
    $codes = rsWait($j, $procs, $i, $progress);
    $prog = $progress();
    $j['steps'][$i]['progress'] = $prog;
    $door = rspDoorLines((string) @file_get_contents($errFile, false, null, 0, 65536));
    $said = trim((string) preg_replace('/[^\x20-\x7e\n]/', '?', (string) @file_get_contents($errFile, false, null, 0, 4096)));
    if ($said !== '') {
        rsLog($j['id'], '  the door: ' . str_replace("\n", ' | ', substr($said, 0, 1500)));
    }
    @unlink($errFile);
    @unlink($pvFile);
    $d1 = $door[0] ?? null;
    $d2 = $door[1] ?? null;
    $recvCode = (int) end($codes);
    $stopped = !empty($GLOBALS['rsStop']);
    if (!$stopped && $recvCode === 0 && ($d1['ok'] ?? null) === true && ($d2['ok'] ?? null) === true) {
        foreach (['canmount=noauto', 'mountpoint=legacy'] as $prop) {
            rsRun($j, [$zfs, 'set', $prop, $ds], 60);
        }
        $guid = rspDsGet($ds, 'guid');
        $undo[0]['guid'] = $guid;
        $j['pulled'] = array_values(array_merge((array) ($j['pulled'] ?? []), [['dataset' => $ds, 'guid' => $guid, 'pair' => $src['id'], 'partner' => $src['name'],
            'unit' => $unit, 'snap' => $snap, 'time' => $s['time'] ?? null, 'path' => $s['path'] ?? null, 'mount' => $s['mount'] ?? null, 'bytes' => $prog['done']]]));
        return ['state' => 'ok', 'undo' => $undo, 'bytes' => $prog['done']];
    }
    $resumable = rspDsGet($ds, 'receive_resume_token') !== null;
    $why = is_string($d1['why'] ?? null) && ($d1['ok'] ?? null) === false ? (string) $d1['why'] : (is_string($d2['why'] ?? null) ? (string) $d2['why']
        : (($d1['ok'] ?? null) === true && $d2 === null ? 'link_lost' : ''));          // the door began, then the link went
    $note = $stopped ? 'pull_stopped' : (($d1['ok'] ?? null) === false ? 'pull_refused_door' : ($d1 === null && (int) $codes[0] === 255 ? 'pull_unreachable' : 'pull_failed'));
    return rsFail($note, ['dataset' => $ds, 'name' => $src['name'], 'why' => preg_match('/^[a-z_]{1,32}$/D', $why) ? $why : 'other', 'resumable' => $resumable ? 'yes' : 'no'],
        "exit codes " . implode(' ', $codes)) + ['undo' => $undo, 'resumable' => $resumable];
}

/** The door's JSON lines in what ssh said on stderr (its own words may stand between) */
function rspDoorLines(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] === '{') {
            $j = json_decode($l, true, 8);
            if (is_array($j) && is_bool($j['ok'] ?? null)) {
                $out[] = $j;
            }
        }
    }
    return $out;
}

/** The mounts as the kernel lists them: dataset => [mount points] */
function rspMounts(): array
{
    $out = [];
    foreach (explode("\n", (string) @file_get_contents(rspMountsFile())) as $l) {
        $f = explode(' ', $l);
        if (count($f) >= 3 && $f[2] === 'zfs') {
            $out[str_replace('\040', ' ', $f[0])][] = str_replace('\040', ' ', $f[1]);
        }
    }
    return $out;
}

/** A pulled dataset mounted read-only under /mnt/addons/UnraidSecretaryOffice/restored/<name> */
function rsDoMount(array &$j, array $s): array
{
    $ds = (string) $s['dataset'];
    $path = (string) $s['path'];
    if (!rspOwnTarget($ds) || dirname($path) !== rspMountRoot() || !preg_match(RSP_NAME_RE, basename($path))) {
        return rsFail('mount_failed', ['path' => $path]);
    }
    return rspMount($j, $ds, $path) ? ['state' => 'ok'] : rsFail('mount_failed', ['path' => $path]);
}

function rspMount(?array $j, string $ds, string $path): bool
{
    if (in_array($path, rspMounts()[$ds] ?? [], true)) {
        return true;
    }
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_dir($path)) || (!is_dir($path) && !@mkdir($path, 0755, true))) {
        return false;
    }
    $cmd = [partnerBin('mount') ?? 'mount', '-t', 'zfs', '-o', RSP_MOUNT_OPTIONS, $ds, $path];
    return ($j !== null ? rsRun($j, $cmd, 60) : run($cmd, 60))[0] === 0;
}

/**
 * «Put back» of a pull: exactly the dataset it made goes — its name his own (.restored-<time> or under
 * UnraidSecretaryOffice-restored), its guid the one the pull recorded, no dataset inside it; unmounted first. Never
 * the original; nothing else is destroyed. Never one that holds more than the pull brought (rspDropHolds(), review
 * 1.81.0, M3): one that was the share for a while carries what was written then and the snapshots taken of it — it
 * stays as it is, said (the preview refuses first; this is the look right before).
 */
function rsDoDrop(array &$j, array $s): array
{
    $ds = (string) $s['dataset'];
    if (!rspOwnTarget($ds)) {
        return rsFail('drop_refused', ['dataset' => $ds]);
    }
    if (!rspDsExists($ds)) {
        return ['state' => 'skipped', 'note' => 'nothing_there'];
    }
    if (is_string($s['guid'] ?? null) && rspDsGet($ds, 'guid') !== $s['guid']) {
        return rsFail('drop_other', ['dataset' => $ds]);
    }
    [$exit, $out] = run([rspZfs(), 'list', '-H', '-o', 'name', '-r', '-t', 'filesystem,volume', $ds], 30);
    if ($exit !== 0 || array_values(array_filter(explode("\n", trim($out)))) !== [$ds]) {
        return rsFail('drop_children', ['dataset' => $ds]);
    }
    $pull = is_string($j['putback_of'] ?? null) && preg_match(RS_ID_PATTERN, $j['putback_of']) ? rsJournal($j['putback_of']) : null;
    $holds = rspDropHolds($pull, $ds);
    if ($holds !== null) {
        rsLog($j['id'], "  $ds holds more than the pull brought (" . json_encode($holds) . ') — left as it is');
        return rsFail('drop_holds', ['dataset' => $ds]);
    }
    foreach (rspMounts()[$ds] ?? [] as $mnt) {
        if (rsRun($j, [partnerBin('umount') ?? 'umount', $mnt], 60)[0] !== 0) {
            return rsFail('drop_busy', ['path' => $mnt]);
        }
        if (in_array(dirname($mnt), [rspMountRoot(), realpath(rspMountRoot())], true)) {
            @rmdir($mnt);
        }
    }
    [$exit, , $err] = rsRun($j, [rspZfs(), 'destroy', '-r', $ds], 300);
    if ($exit !== 0) {
        return rsFail('drop_failed', ['dataset' => $ds], trim($err));
    }
    return ['state' => 'ok'];
}

/**
 * Does a pulled dataset hold more than the pull brought (review 1.81.0, M3)? It was swapped in as its share once (the
 * pull's journal says so: `swapped`, written before the swap's rename and kept through its put back), it carries
 * snapshots other than the one pulled (the engine's or Ms. Snapshotini's of the share meanwhile), or bytes were written
 * since that snapshot (`written`). Null when it holds exactly the pull — else what: {swapped: the swap's entry|null,
 * snaps: the other snapshots, written: bytes}. Only the pull's own record is trusted for which snapshot is the pull's.
 */
function rspDropHolds(?array $pull, string $ds): ?array
{
    $swapped = is_array($pull['swapped'] ?? null) && is_string($pull['swapped']['by'] ?? null) ? (string) $pull['swapped']['by'] : null;
    $own = null;
    foreach ((array) ($pull['pulled'] ?? []) as $p) {
        if (is_array($p) && ($p['dataset'] ?? null) === $ds && is_string($p['snap'] ?? null) && preg_match(PARTNER_SNAP_RE, $p['snap'])) {
            $own = "$ds@{$p['snap']}";
        }
    }
    $snaps = [];
    if ($own !== null) {
        [$exit, $out] = run([rspZfs(), 'list', '-H', '-o', 'name', '-t', 'snapshot', '-d', '1', $ds], 30);
        $snaps = $exit === 0 ? array_values(array_filter(array_map(fn ($l) => explode("\t", $l)[0], explode("\n", trim($out))), fn ($n) => $n !== '' && $n !== $own)) : [];
    }
    $written = rspDsGet($ds, 'written');
    $written = $written !== null && ctype_digit($written) ? (int) $written : 0;
    if ($swapped === null && !$snaps && $written === 0) {
        return null;
    }
    return ['swapped' => $swapped, 'snaps' => $snaps, 'written' => $written];
}

/**
 * The pull's journal notes that its dataset is swapped in as the share (review 1.81.0, M3) — written before the swap's
 * rename and never taken back: «Remove what was pulled…» must never destroy what was the share for a while
 */
function rspPullSwapped(array $swap): void
{
    $id = (string) ($swap['target']['id'] ?? '');
    $pull = preg_match(RS_ID_PATTERN, $id) ? rsJournal($id) : null;
    if (!$pull) {
        rsLog($swap['id'], "  the pull's entry $id not found: its swap not noted there");
        return;
    }
    if (!is_array($pull['swapped'] ?? null)) {
        $pull['swapped'] = ['by' => (string) $swap['id'], 'time' => time()];
        try {
            rsJournalWrite($pull, false);
        } catch (Throwable $e) {
            rsLog($swap['id'], "  the pull's entry $id: its swap could not be noted (" . $e->getMessage() . ')');
        }
    }
}

// ===================================================================== what landed, for his other restores

/**
 * The pulls that landed and are still here: from his journals (kind partner, done, not put back), each pulled
 * dataset with its mount — only while it exists (the kernel's mount table names it, or zfs).
 *
 * @return list<array{journal:string, dataset:string, pair:string, partner:string, unit:string, snap:string, time:?int, path:?string, mount:?string, mounted:bool}>
 */
function rspPulled(): array
{
    if (!is_dir(rsData())) {
        return [];
    }
    $mounts = rspMounts();
    $out = [];
    foreach (rsJournals() as $row) {
        if (!in_array($row['kind'], ['partner', 'partner_start'], true) || !in_array($row['result'], ['ok', 'warnings', 'failed'], true)
            || (is_array($row['putback']) && in_array($row['putback']['result'], ['ok', 'warnings', 'running', 'queued'], true))) {
            continue;
        }
        $j = rsJournal($row['id']);
        foreach ((array) ($j['pulled'] ?? []) as $p) {
            if (!is_array($p) || !is_string($p['dataset'] ?? null) || !rspOwnTarget($p['dataset']) || !preg_match(PARTNER_UNIT_RE, (string) ($p['unit'] ?? ''))) {
                continue;
            }
            $mnt = is_string($p['mount'] ?? null) && dirname($p['mount']) === rspMountRoot() ? $p['mount'] : null;
            $out[] = ['journal' => $row['id'], 'dataset' => $p['dataset'], 'pair' => (string) ($p['pair'] ?? ''), 'partner' => (string) ($p['partner'] ?? ''), 'unit' => $p['unit'], 'snap' => (string) ($p['snap'] ?? ''),
                      'time' => is_int($p['time'] ?? null) ? $p['time'] : null, 'path' => is_string($p['path'] ?? null) && rsCleanPath($p['path']) ? $p['path'] : null,
                      'mount' => $mnt, 'mounted' => $mnt !== null && in_array($mnt, $mounts[$p['dataset']] ?? [], true)];
        }
    }
    return $out;
}

/** At the agent's start (= the array's): the pulls still here mounted again (a legacy mountpoint is never mounted by itself) */
function rspRemount(): void
{
    foreach (rspPulled() as $p) {
        if (!$p['mounted'] && $p['mount'] !== null && rspDsExists($p['dataset']) && rspDsGet($p['dataset'], 'receive_resume_token') === null) {
            if (rspMount(null, $p['dataset'], $p['mount'])) {
                logLine("Mr. Restori: {$p['dataset']} (pulled from {$p['partner']}) mounted again at {$p['mount']}");
            }
        }
    }
}

/**
 * Moments «at <partner>» for his files restores: a pulled share or VM dataset holding this path (the pull's `path` is
 * the share path the unit stands for) — the folder in its read-only mount, like what Kopia brought back.
 */
function rspRestored(string $path): array
{
    $out = [];
    foreach (rspPulled() as $p) {
        if (!$p['mounted'] || $p['path'] === null || $p['unit'] === 'place' || !under($path, $p['path'])) {
            continue;
        }
        $cand = $p['mount'] . substr($path, strlen($p['path']));
        clearstatcache(true, $cand);
        // there, and no link on the way to it from the pull's mount (review 1.81.0, S1: the partner's stream is its word)
        if (file_exists($cand) && !rsLinkBelow((string) $p['mount'], $cand)) {
            $out[] = ['id' => 'partner:' . $p['journal'] . ':' . $p['unit'], 'name' => $p['partner'] . ' ' . $p['snap'], 'time' => (int) ($p['time'] ?? 0),
                      'path' => $cand, 'ours' => false, 'kopia' => true, 'partner' => $p['partner'], 'top' => (string) $p['mount']];
        }
    }
    return $out;
}

/**
 * A pulled backup place as the packages of a night: per pull its mount and the folder of the packages in it
 * (unraid-backup/ or the office's backup/) — for rsVersionList() and, without a place here, rsPlace().
 *
 * @return list<array{id:string, name:string, time:int, path:string, partner:string}>
 */
function rspPlaceSnaps(): array
{
    $out = [];
    foreach (rspPulled() as $p) {
        if (!$p['mounted'] || $p['unit'] !== 'place') {
            continue;
        }
        foreach ([BACKUP_DESK_DIR, 'unraid-backup'] as $sub) {
            $dir = "{$p['mount']}/$sub";
            if (is_dir("$dir/apps") || is_dir("$dir/vms") || is_file("$dir/server/run.json")) {
                $out[] = ['id' => 'partner:' . $p['journal'], 'name' => $p['partner'] . ' ' . $p['snap'], 'time' => (int) ($p['time'] ?? 0), 'path' => $dir, 'partner' => $p['partner']];
                break;
            }
        }
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

// ===================================================================== «Kept for <partner>»: the receiver starts from a copy

/*
 * The other side of the door (2026-10-12, Benj: «a partner that has a backup, from wherever, must always be able to restore
 * it itself»): this office keeps a partner's copies under <pool>/UnraidSecretaryOffice-partners/<id>/<unit> (the door's
 * datasets - never mounted, never changed by anything but the door's own retention). A tile «Kept for <partner>» per
 * such partner shows what lies here (received/<id>.json, the moments on the datasets of an awake pool) and the way
 * back: a new server connects to this office and pulls (the Team Lead's «Start from a partner's copy…») - or this
 * server starts from a copy itself, «Start here…»: kind `partner_start`.
 *
 *   1. an INDEPENDENT copy of the chosen moment on the same pool - zfs send | zfs recv into the office's restored area
 *      (<pool>/UnraidSecretaryOffice-restored/<unit>.from-<partner>-<time>, mountpoint legacy, not shared; never a clone:
 *      a clone would pin the partner's snapshot against the door's retention and entangle the next receive), mounted
 *      read-only under /mnt/addons/UnraidSecretaryOffice/restored/ like a pull - the partner's dataset and its snapshots
 *      stay exactly as they were; from there it is a moment «pulled from <partner>» for his files restores too;
 *   2. then his normal steps from that moment: a share → into the share, which the user made in Unraid first (he never
 *      creates a share: missing = said, with the way); a VM → the files into domains/<vm> (or beside, the user's
 *      choice when the folder is taken) and, when the copy carries its configuration (engine 2.43: .uso-backup inside),
 *      the definition from it - XML with the disk paths pointed at the new folder, UEFI variables and TPM state put in
 *      place where nothing is yet; a VM of that name here = the definition refused, said in the preflight; an app (a
 *      share's copy carrying an app's package) → its template into Unraid's templates-user (never over one that is
 *      there), its dumps named - he never starts a container for it;
 *   3. the copy stays like a pull until the user removes it; «Put back» undoes exactly what the start made - the
 *      definition, the files put (aside, never deleted), the copy - never the partner's dataset.
 *
 * The preflight (the dialog) names the target and every conflict before anything runs: a taken folder offers «Cancel -
 * I'll tidy up first» or «Beside as <name>.from-<partner>» (a share with data: only the copy, then «Restore…» per folder);
 * a missing share says to make it under ⟦Shares⟧ first. Everything here acts on this office's own pools only, never
 * through the door, never at the partner.
 */

const RSP_INSIDE = '.uso-backup';      // the engine's package inside a unit's own dataset (UB_INSIDE_DIR, 2.43)

function rspUserRoot(): string
{
    return $GLOBALS['rs']['user_root'] ?? '/mnt/user';
}

function rspLibvirt(): string
{
    return $GLOBALS['rs']['libvirt'] ?? RS_LIBVIRT;
}

function rspTemplates(): string
{
    return $GLOBALS['rs']['templates'] ?? RS_TEMPLATES;
}

/** The user shares Unraid has right now (shares.ini); null: unknown (the array stopped) */
function rspSharesHere(): ?array
{
    if (array_key_exists('shares_ini', $GLOBALS['rs'] ?? [])) {
        return $GLOBALS['rs']['shares_ini'];
    }
    return readCfg(RS_SHARES_INI, true) ?: null;
}

/** The engine's snapshots of a dataset here (uso-backup-* only), newest first: name, creation, used, referenced */
function rspSnapsOf(string $ds): array
{
    [$exit, $out] = run([rspZfs(), 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name,userrefs,used,referenced,creation', '-s', 'creation', '-d', '1', $ds], 30);
    $snaps = [];
    foreach ($exit === 0 ? rows($out) : [] as $f) {
        $name = substr((string) ($f[0] ?? ''), strlen($ds) + 1);
        if (count($f) >= 5 && str_starts_with((string) $f[0], "$ds@") && preg_match(PARTNER_SNAP_RE, $name)) {
            $snaps[] = ['name' => $name, 'creation' => num($f[4]), 'used' => num($f[2]), 'referenced' => num($f[3])];
        }
    }
    return array_reverse($snaps);
}

/**
 * The partners whose copies this office keeps (pairs with `receive`), for his tile «Kept for <partner>»: per unit what
 * the door noted (received/<id>.json) and, on an awake pool, the moments on its dataset here. Nothing of a key.
 *
 * @return list<array{id:string, name:string, pool:string, asleep:bool, used_bytes:?int, quota_gb:int, time:?int, units:list<array>}>
 */
function rspKept(): array
{
    $pools = [];
    foreach (partnerPools() as $p) {
        $pools[$p['name']] = $p;
    }
    $now = time();
    $out = [];
    $cache = rspPeekCache();
    foreach (partnerPairs() as $p) {
        if (!is_array($p['receive'] ?? null) || !is_string($p['receive']['pool'] ?? null)) {
            continue;
        }
        $pool = $p['receive']['pool'];
        $asleep = !empty($pools[$pool]['asleep']) || !isset($pools[$pool]);
        $got = partnerReceived($p['id']);
        $units = [];
        foreach (partnerReceivedUnits($got, $now) as $u) {
            $ds = partnerUnitDataset($pool, $p['id'], $u['unit']);
            $snaps = $asleep ? [] : rspSnapsOf($ds);
            foreach ($snaps as $i => $sn) {
                $snaps[$i]['inside'] = $u['unit'] === 'place' ? null : rspInside($cache, $ds, $sn['name'], $sn['creation']);
                $snaps[$i]['unread'] = $u['unit'] !== 'place' && rspPeekFailed($cache, $ds, $sn['name'], (int) $sn['creation']);
            }
            // the apps and VMs inside its newest moment (asleep: what was read of the moment the door noted last)
            $inside = $u['unit'] === 'place' ? null : ($snaps ? $snaps[0]['inside'] : (is_string($u['snap'] ?? null) ? rspInside($cache, $ds, $u['snap'], null) : null));
            $units[] = ['unit' => $u['unit'], 'dataset' => $ds, 'snap' => $u['snap'], 'time' => $u['time'], 'new' => $u['new'], 'stale' => $u['stale'],
                        'snaps' => $snaps, 'n' => $snaps ? count($snaps) : $u['snaps'], 'newest' => $snaps[0]['creation'] ?? ($u['time'] ?: null),
                        'bytes' => $snaps[0]['referenced'] ?? null, 'inside' => $inside];
        }
        usort($units, fn ($a, $b) => [$a['unit'] !== 'place', $a['unit']] <=> [$b['unit'] !== 'place', $b['unit']]);
        $out[] = ['id' => $p['id'], 'name' => $p['name'], 'pool' => $pool, 'asleep' => $asleep, 'used_bytes' => is_int($got['used_bytes'] ?? null) ? $got['used_bytes'] : null,
                  'quota_gb' => (int) ($p['receive']['quota_gb'] ?? 0), 'time' => is_int($got['time'] ?? null) ? $got['time'] : null, 'units' => $units];
    }
    return $out;
}

function rspKeptOne(string $id): ?array
{
    foreach (rspKept() as $k) {
        if ($k['id'] === $id) {
            return $k;
        }
    }
    return null;
}

/** A folder here: absent | empty | data (a link or a file counts as data: never written into) */
function rspFolderState(string $path): string
{
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_dir($path))) {
        return 'data';
    }
    if (!is_dir($path)) {
        return 'absent';
    }
    return rsDirHasEntries($path) ? 'data' : 'empty';
}

/**
 * What a kept moment carries (1.60.1): its snapshot mounted read-only for a moment (RUN_DIR, never the pool), the
 * packages read — its own .uso-backup and those of its folders one level down — and unmounted again. Per folder
 * (''= the copy's top): {kind app|vm, name, dirs}. $GLOBALS['rs']['peek'] for the tests. Nothing found: [].
 */
function rspPeek(string $snapshot, ?bool &$read = null): array
{
    $read = false;
    if (isset($GLOBALS['rs']['peek'])) {
        $read = true;
        return ($GLOBALS['rs']['peek'])($snapshot);
    }
    $dir = rsPeekDir('k' . bin2hex(random_bytes(4)));      // in RAM (RUN_DIR), as the file browser's peek (review 1.81.0)
    if (!@mkdir($dir, 0700)) {
        return [];
    }
    $mounted = false;
    try {
        if (run([partnerBin('mount') ?? 'mount', '-t', 'zfs', '-o', RSP_MOUNT_OPTIONS, $snapshot, $dir], 60)[0] === 0) {
            $mounted = $read = true;
            return rspPeekDir($dir);
        }
        return [];
    } finally {
        // never left mounted (it would hold the pool at the array stop): whatever happened while reading
        if ($mounted && run([partnerBin('umount') ?? 'umount', $dir], 60)[0] !== 0) {
            run([partnerBin('umount') ?? 'umount', '-l', $dir], 60);
        }
        @rmdir($dir);
    }
}

/**
 * What a mounted copy carries (rspPeek()): its own .uso-backup and those of its folders one level down. The copy is the
 * partner's word (review 1.72.0, H5): only regular files reached without a link are read — a link may lead anywhere on
 * this server (/proc/kmsg, a device), a FIFO would wait for ever.
 */
function rspPeekDir(string $dir): array
{
    $out = [];
    foreach (array_merge([''], array_map('basename', glob("$dir/*", GLOB_ONLYDIR) ?: [])) as $sub) {
        $inside = ($sub === '' ? '' : "$sub/") . RSP_INSIDE;
        $m = rspPlainFile($dir, "$inside/manifest.json") && (int) @filesize("$dir/$inside/manifest.json") < (1 << 22)
            ? json_decode((string) @file_get_contents("$dir/$inside/manifest.json", false, null, 0, 1 << 22), true) : null;
        if (is_array($m) && in_array($m['kind'] ?? '', ['app', 'vm'], true)) {
            $out[$sub] = ['kind' => $m['kind'], 'name' => rsStr($m['name'] ?? '') ?: ($sub === '' ? basename($dir) : $sub),
                'dirs' => array_values(array_filter((array) ($m['dirs'] ?? []), fn ($d) => is_string($d) && preg_match('#^[^/]+/[^/]+$#D', $d))),
                'needs' => rspNeeds("$dir/$inside", $dir)];
        }
    }
    return $out;
}

/** $rel inside $base is a regular file, and no part of the way to it is a link (each part lstat'ed) */
function rspPlainFile(string $base, string $rel): bool
{
    $cur = rtrim($base, '/');
    $parts = explode('/', trim($rel, '/'));
    foreach ($parts as $i => $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
        $cur .= "/$p";
        clearstatcache(true, $cur);
        $st = @lstat($cur);
        $type = $st === false ? 0 : $st['mode'] & 0170000;
        if ($type !== ($i === count($parts) - 1 ? 0100000 : 0040000)) {
            return false;
        }
    }
    return true;
}

/**
 * The shares an app's package binds (1.60.1, a user: dropnook needs the share drop): its compose files (anchors, the long
 * and the short syntax - rspFitCompose) and its templates' paths, /mnt/user/<share> (a pool path's share too)
 */
function rspNeeds(string $inside, ?string $base = null): array
{
    $needs = [];
    // read only what is a regular file reached without a link from $base (the mounted copy: rspPlainFile())
    $plain = fn (string $f): bool => $base === null || (str_starts_with($f, rtrim($base, '/') . '/') && rspPlainFile($base, substr($f, strlen(rtrim($base, '/')) + 1)));
    foreach (array_filter(array_merge(glob("$inside/compose/*.y*ml") ?: [], glob("$inside/compose/.env") ?: []), $plain) as $f) {
        [, , $miss] = rspFitCompose((string) @file_get_contents($f, false, null, 0, 1 << 20), fn () => true, fn () => false, fn () => true);
        array_push($needs, ...rspSharesMissing($miss));
    }
    foreach (array_filter(glob("$inside/my-*.xml") ?: [], $plain) as $f) {
        preg_match_all('#>/mnt/(?:user|[A-Za-z0-9_.-]+)/([A-Za-z0-9_.@ -]+?)(?:/|<)#', (string) @file_get_contents($f, false, null, 0, 1 << 20), $m);
        array_push($needs, ...$m[1]);
    }
    return array_values(array_unique(array_filter($needs, fn ($x) => preg_match(RS_NAME_PATTERN, $x))));
}

/**
 * What the kept moments carry, read once per moment (2026-10-25, a user: «where are the apps and the VM?» — they lay
 * inside the share units, seen only in «Start here…»'s dialog): «<dataset>@<snap>» => {creation, found: [{folder, kind,
 * name}]}, written by the look job (rspKeptPeek()), never by a tick or a page request; a moment is the same only with the
 * same creation (a snapshot made anew under an old name is read again). Read only in exactly this shape.
 */
function rspPeekFile(): string
{
    return partnerDir() . '/kept-peek.json';
}

/** @return array<string, array{creation:int, found:list<array{folder:string, kind:string, name:string}>, failed:?int}> failed: when it couldn't be mounted */
function rspPeekCache(): array
{
    $out = [];
    foreach ((array) ((readJson(rspPeekFile()) ?? [])['snaps'] ?? []) as $k => $v) {
        if (!is_string($k) || !is_array($v) || !is_int($v['creation'] ?? null) || !is_array($v['found'] ?? null)) {
            continue;
        }
        $found = [];
        foreach ($v['found'] as $f) {
            if (is_array($f) && in_array($f['kind'] ?? null, ['app', 'vm'], true) && is_string($f['name'] ?? null) && is_string($f['folder'] ?? null)) {
                $found[] = ['folder' => $f['folder'], 'kind' => $f['kind'], 'name' => $f['name']];
            }
        }
        $out[$k] = ['creation' => $v['creation'], 'found' => $found, 'failed' => is_int($v['failed'] ?? null) ? $v['failed'] : null];
    }
    return $out;
}

/** The apps and VMs inside a kept moment, as the look job read them — null: not read (yet, or it couldn't be: rspPeekFailed()) */
function rspInside(array $cache, string $ds, string $snap, ?int $creation): ?array
{
    $c = $cache["$ds@$snap"] ?? null;
    return $c !== null && $c['failed'] === null && ($creation === null || $c['creation'] === $creation) ? $c['found'] : null;
}

/** A kept moment the look job couldn't mount: tried again after a day, not at every look */
function rspPeekFailed(array $cache, string $ds, string $snap, int $creation): bool
{
    $c = $cache["$ds@$snap"] ?? null;
    return $c !== null && $c['failed'] !== null && $c['creation'] === $creation && time() - $c['failed'] < 86400;
}

/**
 * The look job's second part: every kept moment not read yet peeked (rspPeek(): mounted read-only in RAM, the packages'
 * manifests read, unmounted) — newest first, at most RSP_PEEK_MAX per job, never on a pool asleep (rspKept() lists no
 * moments there; what was read of it stays). Moments gone are forgotten. Returns how many were read now.
 */
function rspKeptPeek(): int
{
    $cache = rspPeekCache();
    $keep = [];
    $n = 0;
    // a time budget, and never a mount once the array is being stopped (review 1.72.0, H5): what isn't read stays unread
    $until = hrtime(true) / 1e9 + (int) ($GLOBALS['rs']['peek_budget'] ?? RSP_PEEK_BUDGET);
    $go = fn (): bool => hrtime(true) / 1e9 < $until && !rsArrayStopping()
        && in_array((string) (readCfg($GLOBALS['rs']['var_ini'] ?? '/var/local/emhttp/var.ini')['fsState'] ?? 'Started'), ARRAY_RUNNING, true);
    foreach (rspKept() as $k) {
        foreach ($k['units'] as $u) {
            if ($k['asleep']) {
                foreach ($cache as $key => $v) {
                    if (str_starts_with($key, $u['dataset'] . '@')) {
                        $keep[$key] = $v;
                    }
                }
                continue;
            }
            if ($u['unit'] === 'place') {
                continue;                      // the backup place's packages are read as a pulled place (rspPlaceSnaps)
            }
            foreach ($u['snaps'] as $sn) {
                $key = "{$u['dataset']}@{$sn['name']}";
                if (rspInside($cache, $u['dataset'], $sn['name'], (int) $sn['creation']) !== null || rspPeekFailed($cache, $u['dataset'], $sn['name'], (int) $sn['creation'])) {
                    $keep[$key] = $cache[$key];
                    continue;
                }
                if ($n >= RSP_PEEK_MAX || !$go()) {
                    continue;
                }
                $n++;
                $found = [];
                $read = false;
                foreach (rspPeek($key, $read) as $folder => $p) {
                    $found[] = ['folder' => (string) $folder, 'kind' => (string) $p['kind'], 'name' => (string) $p['name']];
                }
                $keep[$key] = ['creation' => (int) $sn['creation'], 'found' => $found, 'failed' => $read ? null : time()];
            }
        }
    }
    partnerWritePrivate(rspPeekFile(), ['v' => 1, 'time' => time(), 'snaps' => $keep]);
    return $n;
}

/**
 * Kind `partner_start`: {pair, unit, snap, beside?} - the preflight. Steps: pull_local (the independent copy), mount,
 * then for a VM or a share whose folder is free: copy (the files into their place) and start_from (what the copy's
 * .uso-backup allows: the VM's definition, an app's template); a share holding data with `beside`: only the copy
 * (then «Restore…» per folder from that moment). Every conflict is a blocker the dialog turns into the two choices.
 */
function rsPlanPartnerStart(array $r, string $stamp): array
{
    $id = textField($r, 'pair');
    $unit = textField($r, 'unit');
    $snap = textField($r, 'snap');
    $beside = !empty($r['beside']) && $r['beside'] !== 'false' && $r['beside'] !== '0';
    if (!preg_match(PARTNER_ID_RE, $id) || !preg_match(PARTNER_UNIT_RE, $unit) || !preg_match(PARTNER_SNAP_RE, $snap)) {
        throw new Problem('unknown_target', ['target' => "$id $unit $snap"]);
    }
    $kept = rspKeptOne($id);
    $u = null;
    foreach ((array) ($kept['units'] ?? []) as $x) {
        $u = $x['unit'] === $unit ? $x : $u;
    }
    if ($kept === null || $u === null) {
        throw new Problem('unknown_target', ['target' => "$id $unit"]);
    }
    $name = $kept['name'];
    $label = rspUnitName($unit);
    $plan = rsPlanBase('partner_start', "$label ← $name", ['pair' => $id, 'unit' => $unit, 'snap' => $snap, 'partner' => $name, 'beside' => $beside, 'pool' => $kept['pool']]);
    $kind = $unit === 'place' ? 'place' : (str_starts_with($unit, 'vm:') ? 'vm' : (str_starts_with($unit, 'app:') ? 'app' : 'share'));
    $plan['options'] = ['kind' => $kind, 'beside' => $beside, 'conflict' => null, 'found' => []];
    // 1.60.1: a share's copy - the apps and VMs it carries, to pick from (default: all); `only` the folders ticked
    $only = is_array($r['only'] ?? null) ? array_values(array_filter($r['only'], fn ($x) => is_string($x) && preg_match(RS_NAME_PATTERN, $x))) : null;
    if ($kept['asleep']) {
        $plan['blockers'][] = ['key' => 'restore_partner_pool', 'params' => ['pool' => $kept['pool']]];
        return $plan;
    }
    $moment = null;
    foreach ($u['snaps'] as $s) {
        $moment = $s['name'] === $snap ? $s : $moment;
    }
    if ($moment === null) {
        $plan['blockers'][] = ['key' => 'restore_partner_no_snap', 'params' => ['snap' => $snap, 'name' => $name, 'when' => $kept['time']]];
        return $plan;
    }
    $bytes = (int) ($moment['referenced'] ?? 0);
    $plan['source'] = ['path' => "$name: $unit@$snap", 'time' => $moment['creation'], 'bytes' => $bytes];
    $peek = in_array($kind, ['share', 'app'], true) ? rspPeek("{$u['dataset']}@$snap") : [];
    // 1.60.1 (a user): an app whose shares aren't here would not run - refused before anything is copied (the office never
    // creates a share: the user does, then «Look again»), or only its own folder on an explicit tick (`only_folder`); a
    // needed share whose copy this office keeps too comes along on a tick (`with`), once the share exists
    $onlyFolder = !empty($r['only_folder']) && $r['only_folder'] !== 'false';
    $with = is_array($r['with'] ?? null) ? array_values(array_filter($r['with'], fn ($x) => is_string($x) && preg_match(RS_NAME_PATTERN, $x))) : [];
    $here = rspSharesHere();
    $keptUnits = array_column($kept['units'] ?? [], null, 'unit');
    $needs = [];
    foreach ($peek as $folder => $p) {
        if (($p['kind'] ?? '') !== 'app' || ($kind === 'share' && $folder === '') || ($only !== null && $folder !== '' && !in_array((string) $folder, $only, true))) {
            continue;
        }
        foreach ((array) ($p['needs'] ?? []) as $sh) {
            if ($kind === 'share' && $sh === $label) {
                continue;                          // the share this copy fills
            }
            $needs[$sh] ??= ['share' => $sh, 'apps' => [], 'exists' => $here === null || isset($here[$sh]), 'kept' => isset($keptUnits["share:$sh"]),
                             'with' => in_array($sh, $with, true)];
            $needs[$sh]['apps'][] = (string) $p['name'];
        }
    }
    $plan['options']['needs'] = array_values($needs);
    $plan['options']['only_folder'] = $onlyFolder;
    foreach ($needs as $n) {
        if (!$n['exists'] && !$onlyFolder) {
            $plan['blockers'][] = ['key' => 'restore_partner_app_shares', 'params' => ['name' => implode(', ', $n['apps']), 'share' => $n['share'], 'partner' => $name]];
        }
    }
    if ($kind === 'share') {
        foreach ($peek as $folder => $p) {
            if ($folder !== '') {
                $plan['options']['found'][] = ['folder' => (string) $folder, 'kind' => $p['kind'], 'name' => $p['name'], 'on' => $only === null || in_array((string) $folder, $only, true)];
            }
        }
    }
    // 1. the independent copy on the same pool, mounted read-only like a pull
    $pool = $kept['pool'];
    $parent = "$pool/" . RSP_PARENT;
    $ds = "$parent/" . preg_replace('/[^A-Za-z0-9_.:-]/', '_', str_replace(':', '-', $unit)) . '.from-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . "-$stamp";
    $mnt = rspMountPath($name, $unit, $stamp);
    if (rspDsExists($ds)) {
        $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $ds]];
    }
    $steps = [
        ['do' => 'pull_local', 'source' => "{$u['dataset']}@$snap", 'dataset' => $ds, 'parent' => rspDsExists($parent) ? null : $parent, 'mount' => $mnt,
         'pair' => $id, 'partner' => $name, 'unit' => $unit, 'snap' => $snap, 'time' => $moment['creation'], 'bytes' => $bytes, 'path' => null],
        ['do' => 'mount', 'dataset' => $ds, 'path' => $mnt],
    ];
    $plan['aside'][] = ['what' => 'pulled', 'from' => "$name: $unit@$snap", 'to' => $ds];
    $plan['notes'][] = ['key' => 'note.partner_start_copy', 'params' => ['pool' => $pool, 'dataset' => $ds, 'name' => $name, 'bytes' => $bytes]];
    $need = $bytes;
    // 2. where it goes
    $user = rspUserRoot();
    $shares = rspSharesHere();
    $to = null;
    if ($kind === 'place') {
        // the backup place of the partner: only the copy - his packages can read it as a pulled place (rsPlacePulled)
        $plan['after'][] = ['key' => 'after.partner_place', 'params' => ['path' => $mnt, 'name' => $name]];
    } elseif ($kind === 'share') {
        $share = $label;
        $steps[0]['path'] = "$user/$share";
        if ($shares !== null && !isset($shares[$share])) {
            $plan['blockers'][] = ['key' => 'restore_partner_share_needed', 'params' => ['share' => $share]];
            return $plan;
        }
        $state = rspFolderState("$user/$share");
        if ($state === 'data') {
            $plan['options']['conflict'] = ['path' => "$user/$share", 'kind' => 'share', 'beside_as' => null];
            if (!$beside) {
                $plan['blockers'][] = ['key' => 'restore_partner_start_exists', 'params' => ['path' => "$user/$share"]];
            } else {
                $plan['notes'][] = ['key' => 'note.partner_start_copy_only', 'params' => ['path' => "$user/$share", 'name' => $name]];
                $steps[] = ['do' => 'start_from', 'kind' => 'look', 'to' => null, 'mount' => $mnt, 'partner' => $name, 'templates' => rspTemplates(), 'libvirt' => rspLibvirt()];
                $plan['after'][] = ['key' => 'after.partner_start_copy_only', 'params' => ['path' => "$user/$share", 'name' => $name]];
            }
        } else {
            $to = "$user/$share";
            if ($state === 'empty') {
                $plan['notes'][] = ['key' => 'note.partner_start_empty', 'params' => ['path' => $to]];
            }
            $plan['after'][] = ['key' => 'after.partner_start_share', 'params' => ['share' => $share, 'path' => $to]];
            $plan['notes'][] = ['key' => 'note.partner_start_share_scan', 'params' => []];
        }
    } elseif ($kind === 'app') {
        // an app's own folder dataset (engine 2.45): back into <share>/<folder> as its package names it (dirs), else
        // appdata/<app> - never over a folder holding data (beside as …from-<partner>), then its template or stack
        [$share, $dir] = explode('/', ($peek['']['dirs'][0] ?? '') ?: "appdata/$label", 2);
        $steps[0]['path'] = "$user/$share/$dir";
        if ($shares !== null && !isset($shares[$share])) {
            $plan['blockers'][] = ['key' => 'restore_partner_share_needed', 'params' => ['share' => $share]];
            return $plan;
        }
        $to = "$user/$share/$dir";
        $besideAs = "$to.from-" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        $state = rspFolderState($to);
        if ($state === 'data') {
            $plan['options']['conflict'] = ['path' => $to, 'kind' => 'app', 'beside_as' => $besideAs];
            if (!$beside) {
                $plan['blockers'][] = ['key' => 'restore_partner_start_exists', 'params' => ['path' => $to]];
                $to = null;
            } else {
                $to = $besideAs;
                if (rspFolderState($to) !== 'absent') {
                    $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
                }
                $plan['notes'][] = ['key' => 'note.partner_start_beside', 'params' => ['path' => "$user/$share/$dir", 'beside' => $to]];
            }
        } elseif ($state === 'empty') {
            $plan['notes'][] = ['key' => 'note.partner_start_empty', 'params' => ['path' => $to]];
        }
        $plan['target']['app'] = $label;
        $plan['target']['name'] = $label;
        if ($to !== null) {
            $plan['after'][] = ['key' => 'after.partner_start_app', 'params' => ['name' => $label, 'path' => $to]];
        }
        $appStep = ['do' => 'start_from', 'kind' => 'app', 'name' => $label, 'partner' => $name, 'mount' => $mnt, 'libvirt' => rspLibvirt(), 'templates' => rspTemplates()];
    } else {
        $vm = $label;
        $steps[0]['path'] = "$user/domains/$vm";
        if ($shares !== null && !isset($shares['domains'])) {
            $plan['blockers'][] = ['key' => 'restore_partner_share_needed', 'params' => ['share' => 'domains']];
            return $plan;
        }
        $to = "$user/domains/$vm";
        $besideAs = "$to.from-" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        $state = rspFolderState($to);
        if ($state === 'data') {
            $plan['options']['conflict'] = ['path' => $to, 'kind' => 'vm', 'beside_as' => $besideAs];
            if (!$beside) {
                $plan['blockers'][] = ['key' => 'restore_partner_start_exists', 'params' => ['path' => $to]];
                $to = null;
            } else {
                $to = $besideAs;
                if (rspFolderState($to) !== 'absent') {
                    $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
                }
                $plan['notes'][] = ['key' => 'note.partner_start_beside', 'params' => ['path' => "$user/domains/$vm", 'beside' => $to]];
            }
        } elseif ($state === 'empty') {
            $plan['notes'][] = ['key' => 'note.partner_start_empty', 'params' => ['path' => $to]];
        }
        $states = array_key_exists('vm_states', $GLOBALS['rs'] ?? []) ? $GLOBALS['rs']['vm_states'] : rsVmStates();
        $define = true;
        $why = null;
        if ($states === null) {
            $define = false;
            $why = 'start_vm_service_off';
            $plan['notes'][] = ['key' => 'note.partner_start_vm_service_off', 'params' => ['name' => $vm], 'warn' => true];
        } elseif (isset($states[$vm])) {
            $define = false;
            $why = 'start_vm_exists';
            $plan['notes'][] = ['key' => 'note.partner_start_vm_exists', 'params' => ['name' => $vm, 'state' => $states[$vm]], 'warn' => true];
        }
        $plan['target']['vm'] = $vm;
        $plan['target']['name'] = $vm;
        if ($to !== null) {
            $plan['after'][] = ['key' => $define ? 'after.partner_start_vm' : 'after.partner_start_vm_by_hand', 'params' => ['name' => $vm, 'path' => $to]];
        }
        $vmStep = ['do' => 'start_from', 'kind' => 'vm', 'name' => $vm, 'define' => $define, 'define_why' => $why, 'partner' => $name, 'mount' => $mnt, 'libvirt' => rspLibvirt(), 'templates' => rspTemplates()];
    }
    if ($to !== null) {
        $skip = $only !== null ? array_values(array_diff(array_column($plan['options']['found'], 'folder'), $only)) : [];
        $steps[] = ['do' => 'copy', 'from' => $mnt, 'to' => $to, 'into_empty' => true, 'replaces' => true, 'putback_to' => "$to.putback-{T}", 'partner' => $name]
            + ($skip ? ['skip' => $skip] : []);
        $plan['aside'][] = ['what' => 'started', 'from' => "$name: $label", 'to' => $to];
        $steps[] = isset($vmStep) ? $vmStep + ['to' => $to] : (isset($appStep) ? $appStep + ['to' => $to]
            : ['do' => 'start_from', 'kind' => 'share', 'to' => $to, 'mount' => $mnt, 'partner' => $name, 'templates' => rspTemplates(), 'libvirt' => rspLibvirt()]
              + ($only !== null ? ['only' => $only] : []));
        $need += $bytes;
    }
    // the needed shares ticked to come along (their copy kept here too, the share made by the user, its folder free)
    foreach ($needs as $n) {
        if (!$n['with'] || !$n['exists'] || !$n['kept'] || $onlyFolder) {
            continue;
        }
        $su = $keptUnits["share:{$n['share']}"];
        $ssnap = null;
        foreach ($su['snaps'] as $x) {                           // newest first: the app's moment, else the newest before it
            if ($x['name'] <= $snap) {
                $ssnap = $x;
                break;
            }
        }
        $ssnap ??= $su['snaps'][0] ?? null;
        $sto = "$user/{$n['share']}";
        if ($ssnap === null || rspFolderState($sto) === 'data') {
            $plan['notes'][] = ['key' => 'note.partner_share_has_data', 'params' => ['share' => $n['share'], 'partner' => $name], 'warn' => true];
            continue;
        }
        $sds = "$parent/share-" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $n['share']) . '.from-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . "-$stamp";
        $smnt = rspMountPath($name, "share:{$n['share']}", $stamp);
        $sb = (int) ($ssnap['referenced'] ?? 0);
        array_push($steps,
            ['do' => 'pull_local', 'source' => "{$su['dataset']}@{$ssnap['name']}", 'dataset' => $sds, 'parent' => null, 'mount' => $smnt, 'pair' => $id, 'partner' => $name,
             'unit' => "share:{$n['share']}", 'snap' => $ssnap['name'], 'time' => $ssnap['creation'], 'bytes' => $sb, 'path' => $sto],
            ['do' => 'mount', 'dataset' => $sds, 'path' => $smnt],
            ['do' => 'copy', 'from' => $smnt, 'to' => $sto, 'into_empty' => true, 'replaces' => true, 'putback_to' => "$sto.putback-{T}", 'partner' => $name]);
        $plan['aside'][] = ['what' => 'started', 'from' => "$name: share {$n['share']}", 'to' => $sto];
        $need += 2 * $sb;
    }
    $plan['steps'] = $steps;
    $plan['notes'][] = ['key' => 'note.partner_start_package', 'params' => ['name' => $name]];
    // review 2026-10-15 F5: a partner's templates and Compose files go in place as they are — what they ask of this server
    // (--privileged, host paths, the Docker socket) Unraid's form and the Compose tool show before anything starts; said here
    if (array_filter($steps, fn ($s) => ($s['do'] ?? '') === 'start_from' && in_array($s['kind'] ?? '', ['app', 'share', 'look'], true))) {
        $plan['notes'][] = ['key' => 'note.partner_start_foreign', 'params' => ['name' => $name], 'warn' => true];
    }
    $plan['after'][] = ['key' => 'after.partner_start_keep', 'params' => ['name' => $name]];
    $free = rspPoolFree($pool);
    $plan['sizes'] = ['need' => $need, 'free' => $free, 'measuring' => false, 'compresses' => true];
    if ($free !== null && $need > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $need, 'free' => $free]];
    }
    $plan['downtime'] = 0;
    return $plan;
}

/**
 * The independent copy: zfs send -L -c <partner's dataset>@<snap> | pv -n | zfs recv -u (legacy, noauto, not shared)
 * into the restored area of the same pool - the source only ever a dataset under UnraidSecretaryOffice-partners, the
 * target only a name of his own. Its undo is `drop` of exactly that dataset (name and guid). Recorded as `pulled` so
 * his files restores see it as a moment «pulled from <partner>» and the agent mounts it again at its start.
 */
function rsDoPullLocal(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $ds = (string) $s['dataset'];
    $src = (string) $s['source'];
    [$srcDs, $snap] = explode('@', $src, 2) + [1 => ''];
    if (!rspOwnTarget($ds) || !preg_match(RSP_DS_RE, $srcDs) || !preg_match(PARTNER_SNAP_RE, $snap)
        || !preg_match('#^[A-Za-z][A-Za-z0-9_.:-]{0,63}/' . PARTNER_PARENT . '/[0-9a-f]{8}/[A-Za-z0-9_.:-]{1,70}$#D', $srcDs) || explode('/', $srcDs)[0] !== explode('/', $ds)[0]) {
        return rsFail('pull_refused', ['dataset' => $ds]);
    }
    $zfs = rspZfs();
    $undo = [['do' => 'drop', 'dataset' => $ds, 'path' => (string) ($s['mount'] ?? '')]];
    if (($s['parent'] ?? null) !== null && !rspDsExists((string) $s['parent'])) {
        if (rsRun($j, [$zfs, 'create', '-o', 'mountpoint=none', '-o', 'canmount=off', (string) $s['parent']], 60)[0] !== 0) {
            return rsFail('mkdir_failed', ['path' => (string) $s['parent']]);
        }
    }
    if (rspDsExists($ds)) {
        return rsFail('exists', ['path' => $ds]);
    }
    if (rspDsGet($src, 'type') !== 'snapshot') {
        return rsFail('gone', ['path' => $src]);
    }
    $send = [$zfs, 'send', '-L', '-c', $src];
    $recv = [$zfs, 'recv', '-u', '-o', 'mountpoint=legacy', '-o', 'canmount=noauto', '-x', 'sharesmb', '-x', 'sharenfs', "$ds@$snap"];
    rsLog($j['id'], '  copy: ' . implode(' ', $send) . ' | ' . implode(' ', $recv));
    $dir = rsDir($j['id']);
    $pvFile = "$dir/copy-$i.pv";
    @unlink($pvFile);
    $log = "$dir/log.txt";
    $env = rsEnv();
    $procs = [];
    $close = function () use (&$procs): void {
        foreach ($procs as $p) {
            @proc_terminate($p);
            @proc_close($p);
        }
    };
    $p = proc_open($send, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']], $pp, '/', $env);
    if (!is_resource($p)) {
        return rsFail('pull_failed', ['dataset' => $ds, 'name' => (string) $s['partner'], 'why' => 'other', 'resumable' => 'no'], 'zfs send did not start') + ['undo' => $undo];
    }
    $procs[] = $p;
    $prev = $pp[1];
    if (partnerBin('pv') !== null) {
        $p = proc_open([partnerBin('pv'), '-n', '-b', '-i', '2'], [0 => $prev, 1 => ['pipe', 'w'], 2 => ['file', $pvFile, 'w']], $pp, '/', $env);
        fclose($prev);
        if (!is_resource($p)) {
            $close();
            return rsFail('pull_failed', ['dataset' => $ds, 'name' => (string) $s['partner'], 'why' => 'other', 'resumable' => 'no'], 'pv did not start') + ['undo' => $undo];
        }
        $procs[] = $p;
        $prev = $pp[1];
    }
    $r = proc_open($recv, [0 => $prev, 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a']], $rp, '/', $env);
    fclose($prev);
    if (!is_resource($r)) {
        $close();
        return rsFail('pull_failed', ['dataset' => $ds, 'name' => (string) $s['partner'], 'why' => 'other', 'resumable' => 'no'], 'zfs recv did not start') + ['undo' => $undo];
    }
    $procs[] = $r;
    $total = (int) ($s['bytes'] ?? 0) ?: null;
    $progress = function () use ($pvFile, $total): array {
        $lines = preg_split('/\s+/', trim((string) @file_get_contents($pvFile, false, null, max(0, (int) @filesize($pvFile) - 256)))) ?: [];
        $done = (int) preg_replace('/\D/', '', (string) end($lines));
        return ['done' => $done, 'total' => $total, 'percent' => $total ? min(100, (int) floor(100 * $done / $total)) : null];
    };
    $codes = rsWait($j, $procs, $i, $progress);
    $prog = $progress();
    $j['steps'][$i]['progress'] = $prog;
    @unlink($pvFile);
    $stopped = !empty($GLOBALS['rsStop']);
    if (!$stopped && !array_filter($codes, fn ($c) => (int) $c !== 0)) {
        foreach (['canmount=noauto', 'mountpoint=legacy'] as $prop) {
            rsRun($j, [$zfs, 'set', $prop, $ds], 60);
        }
        $guid = rspDsGet($ds, 'guid');
        $undo[0]['guid'] = $guid;
        $j['pulled'] = array_values(array_merge((array) ($j['pulled'] ?? []), [['dataset' => $ds, 'guid' => $guid, 'pair' => (string) $s['pair'], 'partner' => (string) $s['partner'],
            'unit' => (string) $s['unit'], 'snap' => $snap, 'time' => $s['time'] ?? null, 'path' => $s['path'] ?? null, 'mount' => $s['mount'] ?? null, 'bytes' => $prog['done'], 'local' => true]]));
        return ['state' => 'ok', 'undo' => $undo, 'bytes' => $prog['done']];
    }
    return rsFail($stopped ? 'pull_stopped' : 'pull_failed', ['dataset' => $ds, 'name' => (string) $s['partner'], 'why' => 'other', 'resumable' => 'no'],
        'exit codes ' . implode(' ', $codes)) + ['undo' => $undo];
}

/** A VM's XML from the copy with its disk paths pointed at the folder the files went to (a disk that is there by name) */
function rspRewriteXml(string $xml, string $to): array
{
    $n = 0;
    $out = preg_replace_callback('/(<source\s+(?:file|dev)=)([\'"])([^\'"]+)\2/', function (array $m) use ($to, &$n): string {
        $base = basename($m[3]);
        if (rtrim(dirname($m[3]), '/') !== rtrim($to, '/') && is_file("$to/$base")) {
            $n++;
            return $m[1] . $m[2] . htmlspecialchars("$to/$base", ENT_QUOTES | ENT_XML1) . $m[2];
        }
        return $m[0];
    }, $xml);
    return [(string) $out, $n];
}

/**
 * What a VM's definition needs of this host (1.60.1): its CPUs, its memory (KiB), its bridges and libvirt networks.
 * $GLOBALS['rs']['host'] overrides it (the tests).
 *
 * @return array{cpus:int, mem_kib:int, bridges:list<string>, networks:list<string>, host:string}
 */
function rspHostFacts(): array
{
    if (is_array($GLOBALS['rs']['host'] ?? null)) {
        return $GLOBALS['rs']['host'];
    }
    $cpus = 0;
    foreach (preg_split('/\n/', (string) @file_get_contents('/proc/cpuinfo')) ?: [] as $l) {
        $cpus += str_starts_with($l, 'processor') ? 1 : 0;
    }
    $mem = preg_match('/^MemTotal:\s+(\d+)\s+kB/m', (string) @file_get_contents('/proc/meminfo'), $m) ? (int) $m[1] : 0;
    $bridges = [];
    foreach (glob('/sys/class/net/*/bridge', GLOB_ONLYDIR) ?: [] as $b) {
        $bridges[] = basename(dirname($b));
    }
    [$exit, $out] = run([partnerBin('virsh') ?? 'virsh', 'net-list', '--all', '--name'], 20);
    $nets = $exit === 0 ? array_values(array_filter(array_map('trim', explode("\n", $out)))) : [];
    return ['cpus' => $cpus, 'mem_kib' => $mem, 'bridges' => $bridges, 'networks' => $nets, 'host' => strtolower(explode('.', (string) gethostname())[0])];
}

/** A cpuset of libvirt («1-3,^2,6») as the CPU numbers it names */
function rspCpuset(string $set): array
{
    $on = $off = [];
    foreach (explode(',', $set) as $part) {
        $part = trim($part);
        $neg = str_starts_with($part, '^');
        $part = ltrim($part, '^');
        if (preg_match('/^(\d+)(?:-(\d+))?$/D', $part, $m)) {
            $range = range((int) $m[1], (int) ($m[2] ?? $m[1]));
            $neg ? array_push($off, ...$range) : array_push($on, ...$range);
        }
    }
    return array_values(array_diff($on, $off));
}

/** A libvirt memory value in KiB (its unit attribute: b, bytes, KB, k, KiB, MB, M, MiB, GB, G, GiB) */
function rspKib(string $value, string $unit): int
{
    $f = ['b' => 1 / 1024, 'bytes' => 1 / 1024, 'KB' => 1000 / 1024, 'k' => 1, 'KiB' => 1, 'MB' => 1e6 / 1024, 'M' => 1024, 'MiB' => 1024,
          'GB' => 1e9 / 1024, 'G' => 1048576, 'GiB' => 1048576][$unit === '' ? 'KiB' : $unit] ?? 1;
    return (int) floor((float) $value * $f);
}

/**
 * A VM's XML from another server fitted to this one (1.60.1, a user: a VM of 8 GiB, 3 pinned CPUs and two ISOs that
 * aren't here would not start on a host of 2 CPUs and 3.9 GiB): CPU pins to CPUs that don't exist removed, more vCPUs
 * than the host has capped (the topology with them), memory over 75 % of the host's capped to Unraid's form steps
 * (128 MB, 256 MB, then 512 MB each - so the form shows it), passthrough devices removed, a bridge or network that isn't
 * here the host's default bridge, an ISO that isn't here emptied (its drive kept). Never a disk image: a disk whose file
 * isn't here refuses the definition. $exists: does a path exist here (the tests). Returns [xml, changes, refuse|null] —
 * changes: [{code, params}] for the journal (sa.<code>)
 */
function rspAdaptXml(string $xml, array $host, ?callable $exists = null): array
{
    $exists ??= fn (string $p): bool => file_exists($p);
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    if (trim($xml) === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
        return [$xml, [], null];
    }
    $x = new DOMXPath($doc);
    $changes = [];
    $cpus = max(1, (int) ($host['cpus'] ?? 1));
    // CPU pinning to CPUs that don't exist here
    $pins = 0;
    foreach (iterator_to_array($x->query('/domain/cputune/vcpupin | /domain/cputune/emulatorpin | /domain/cputune/iothreadpin')) as $n) {
        if (array_filter(rspCpuset($n->getAttribute('cpuset')), fn ($c) => $c >= $cpus)) {
            $n->parentNode->removeChild($n);
            $pins++;
        }
    }
    foreach (iterator_to_array($x->query('/domain/cputune[not(*)]')) as $n) {
        $n->parentNode->removeChild($n);
    }
    if ($pins) {
        $changes[] = ['code' => 'pins', 'params' => ['n' => (string) $pins, 'cpus' => (string) $cpus]];
    }
    // more vCPUs than the host has
    $v = $x->query('/domain/vcpu')->item(0);
    if ($v && (int) trim($v->textContent) > $cpus) {
        $changes[] = ['code' => 'vcpus', 'params' => ['from' => trim($v->textContent), 'to' => (string) $cpus]];
        $v->textContent = (string) $cpus;
        if ($v->hasAttribute('current') && (int) $v->getAttribute('current') > $cpus) {
            $v->setAttribute('current', (string) $cpus);
        }
        $t = $x->query('/domain/cpu/topology')->item(0);
        if ($t) {
            $t->setAttribute('sockets', '1');
            if ($t->hasAttribute('dies')) {
                $t->setAttribute('dies', '1');
            }
            $t->setAttribute('cores', (string) $cpus);
            $t->setAttribute('threads', '1');
        }
    }
    // memory over 75 % of the host's: capped to Unraid's form steps
    $hostKib = (int) ($host['mem_kib'] ?? 0);
    if ($hostKib > 0) {
        $cap = (int) floor($hostKib * 0.75 / 524288) * 524288;
        $cap = $cap ?: ($hostKib * 0.75 >= 262144 ? 262144 : 131072);
        $mem = $x->query('/domain/memory')->item(0);
        $from = $mem ? rspKib(trim($mem->textContent), $mem->getAttribute('unit')) : 0;
        foreach (['/domain/memory', '/domain/currentMemory'] as $q) {
            $n = $x->query($q)->item(0);
            if ($n && rspKib(trim($n->textContent), $n->getAttribute('unit')) > $cap) {
                $n->textContent = (string) $cap;
                $n->setAttribute('unit', 'KiB');
            }
        }
        if ($from > $cap) {
            $changes[] = ['code' => 'memory', 'params' => ['from' => (string) round($from / 1048576, 1), 'to' => (string) round($cap / 1048576, 1)]];
        }
    }
    // passthrough devices: not this host's
    $hd = 0;
    foreach (iterator_to_array($x->query('/domain/devices/hostdev')) as $n) {
        $n->parentNode->removeChild($n);
        $hd++;
    }
    if ($hd) {
        $changes[] = ['code' => 'hostdev', 'params' => ['n' => (string) $hd]];
    }
    // a bridge or a network that isn't here: the host's default bridge
    $bridges = array_values((array) ($host['bridges'] ?? []));
    $default = in_array('br0', $bridges, true) ? 'br0' : (in_array('virbr0', $bridges, true) ? 'virbr0' : ($bridges[0] ?? 'virbr0'));
    foreach (iterator_to_array($x->query('/domain/devices/interface')) as $n) {
        $src = $x->query('source', $n)->item(0);
        if (!$src) {
            continue;
        }
        if ($n->getAttribute('type') === 'bridge' && !in_array($src->getAttribute('bridge'), $bridges, true)) {
            $changes[] = ['code' => 'bridge', 'params' => ['from' => $src->getAttribute('bridge'), 'to' => $default]];
            $src->setAttribute('bridge', $default);
        } elseif ($n->getAttribute('type') === 'network' && !in_array($src->getAttribute('network'), (array) ($host['networks'] ?? []), true)) {
            $changes[] = ['code' => 'bridge', 'params' => ['from' => $src->getAttribute('network'), 'to' => $default]];
            $n->setAttribute('type', 'bridge');
            $src->removeAttribute('network');
            $src->setAttribute('bridge', $default);
        }
    }
    // an ISO that isn't here: its source emptied, the drive kept; a disk image that isn't here: no definition
    foreach (iterator_to_array($x->query('/domain/devices/disk')) as $n) {
        $src = $x->query('source', $n)->item(0);
        $path = $src ? ($src->getAttribute('file') ?: $src->getAttribute('dev')) : '';
        if ($path === '' || $exists($path)) {
            continue;
        }
        if (in_array($n->getAttribute('device'), ['cdrom', 'floppy'], true)) {
            $src->parentNode->removeChild($src);
            $changes[] = ['code' => 'cdrom', 'params' => ['path' => $path]];
        } else {
            return [$xml, $changes, ['path' => $path]];
        }
    }
    return [$changes ? (string) $doc->saveXML($doc->documentElement) : $xml, $changes, null];
}

/** The emulators a VM's XML may name here (Unraid's own); anything else becomes the first */
const RSP_XML_EMULATORS = ['/usr/local/sbin/qemu', '/usr/bin/qemu-system-x86_64'];
/** Places of this server a VM's XML from a partner's copy may name besides the VM's own folder: firmware, UEFI variables, libvirt's sockets */
const RSP_XML_PREFIXES  = ['/usr/share/qemu/', '/etc/libvirt/qemu/nvram/', '/var/lib/libvirt/qemu/'];
const RSP_XML_EXACT     = ['/dev/urandom', '/dev/random'];
const RSP_XML_QEMU_NS   = 'http://libvirt.org/schemas/domain/qemu/1.0';

/**
 * A VM's XML from a partner's copy (a partner, or a gone server's copy through a ticket) before `virsh define` here
 * (review 2026-10-15, R1): the copy is the partner's word, and libvirt runs the <emulator> it names as root already
 * at the definition (it asks the binary what it can). So:
 *   removed, said in the step (sa.<code>): QEMU command-line additions (qemu: namespace), shared host folders
 *     (<filesystem>, virtiofsd runs as root), network scripts (<script>, <downscript>), passed-through host devices
 *     (<hostdev>, an interface of type hostdev, <smartcard>, evdev/passthrough <input>); an emulator that isn't
 *     Unraid's → Unraid's
 *   refused (nothing defined, the files stay): another VM's name than the package's (it would replace that VM's
 *     definition), a disk of a kind that reaches this server's storage (volume, nvme, vhost-user), and any path of this
 *     server outside $roots (the VM's own folder), RSP_XML_PREFIXES and RSP_XML_EXACT — an ISO (a cdrom's *.iso under
 *     /mnt) excepted. A path is any value beginning with '/' (outside <title>, <description>, <metadata>) and every
 *     file/dev/dir/path/socket of a <source> and the text of <loader>, <nvram>, <kernel>, <initrd>, <dtb>: absolute,
 *     without . or .. parts.
 * Returns [xml, changes, refuse|null] — refuse: {note, params} (sn.<note>).
 *
 * @param list<string> $roots
 */
function rspXmlForeign(string $xml, string $name, array $roots): array
{
    $no = fn (string $note, array $params = []): array => [$xml, [], ['note' => $note, 'params' => $params]];
    $doc = new DOMDocument();
    if (trim($xml) === '' || !@$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement?->localName !== 'domain') {
        return $no('vm_xml_unreadable');               // never «?» as a path in its text
    }
    $x = new DOMXPath($doc);
    $x->registerNamespace('q', RSP_XML_QEMU_NS);
    $named = trim((string) $x->query('/domain/name')->item(0)?->textContent);
    if ($named !== $name) {
        return $no('vm_foreign_name', ['other' => mb_substr($named, 0, 64)]);
    }
    $changes = [];
    $drop = function (string $query, string $code) use ($x, &$changes): void {
        $n = 0;
        foreach (iterator_to_array($x->query($query)) as $node) {
            $node->parentNode?->removeChild($node);
            $n++;
        }
        if ($n) {
            $changes[] = ['code' => $code, 'params' => ['n' => (string) $n]];
        }
    };
    $drop('//q:*[not(ancestor::q:*)]', 'qemu');
    $drop('/domain/devices/filesystem', 'filesystem');
    $drop('/domain/devices/interface/script | /domain/devices/interface/downscript', 'net_script');
    $drop('/domain/devices/hostdev | /domain/devices/interface[@type="hostdev"] | /domain/devices/smartcard'
        . ' | /domain/devices/input[@type="evdev" or @type="passthrough"]', 'passthrough');
    foreach (iterator_to_array($x->query('/domain/devices/emulator')) as $e) {
        if (!in_array(trim($e->textContent), RSP_XML_EMULATORS, true)) {
            $changes[] = ['code' => 'emulator', 'params' => ['path' => mb_substr(trim($e->textContent), 0, 200)]];
            $e->textContent = RSP_XML_EMULATORS[0];
        }
    }
    foreach ($x->query('/domain/devices/disk') as $d) {
        if (!in_array($d->getAttribute('type'), ['', 'file', 'block', 'dir', 'network'], true)) {
            return $no('vm_foreign_path', ['path' => $d->getAttribute('type')]);
        }
    }
    $ok = function (string $p, DOMNode $at) use ($roots): bool {
        if ($p === '' || $p[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $p)) {
            return false;
        }
        foreach ($roots as $r) {
            if ($r !== '' && str_starts_with($p, rtrim($r, '/') . '/')) {
                return true;
            }
        }
        foreach (RSP_XML_PREFIXES as $r) {
            if (str_starts_with($p, $r)) {
                return true;
            }
        }
        if (in_array($p, RSP_XML_EXACT, true) || in_array($p, RSP_XML_EMULATORS, true)) {
            return true;
        }
        $disk = $at->parentNode?->parentNode;           // the attribute → its <source> → its <disk>
        return preg_match('#^/mnt/[^/]+/.+\.iso$#iD', $p) === 1 && $disk instanceof DOMElement && $disk->localName === 'disk'
            && $disk->getAttribute('device') === 'cdrom';
    };
    foreach ($x->query("//@*[not(ancestor::*[self::title or self::description or self::metadata][parent::domain])]") as $a) {
        $v = trim($a->nodeValue);
        $must = $a->parentNode?->localName === 'source' && in_array($a->localName, ['file', 'dev', 'dir', 'path', 'socket'], true)
            || ($a->parentNode?->localName === 'nvram' && $a->localName === 'template');
        if (($must || str_starts_with($v, '/')) && !$ok($v, $a)) {
            return $no('vm_foreign_path', ['path' => mb_substr($v, 0, 200)]);
        }
    }
    foreach ($x->query('//text()[normalize-space()]') as $t) {
        if ($x->query('ancestor::*[self::title or self::description or self::metadata][parent::domain]', $t)->length) {
            continue;
        }
        $v = trim($t->nodeValue);
        $must = in_array($t->parentNode?->localName, ['loader', 'nvram', 'kernel', 'initrd', 'dtb', 'emulator'], true);
        if (($must || str_starts_with($v, '/')) && !$ok($v, $t)) {
            return $no('vm_foreign_path', ['path' => mb_substr($v, 0, 200)]);
        }
    }
    return [$changes ? (string) $doc->saveXML($doc->documentElement) : $xml, $changes, null];
}

/** A small file of the copy into its place - only where nothing is: through a new file beside and a rename */
function rspPutFile(string $from, string $to): bool
{
    clearstatcache();
    if (!is_file($from) || is_link($from) || file_exists($to) || is_link($to) || !rsMkdirs(dirname($to))) {
        return false;
    }
    $tmp = dirname($to) . '/.' . basename($to) . '.uso-' . bin2hex(random_bytes(4));
    $old = umask(0077);
    $ok = @copy($from, $tmp);
    umask($old);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $to)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * A VM's definition from a package inside the copy: XML with the disk paths pointed at the folder the files went to,
 * UEFI variables and TPM state put only where nothing is. $states: libvirt's VMs now (null: the service is off).
 * Returns ['state' => ok|skipped|failed, 'why' => …, params] and appends to $undo.
 */
function rspStartVm(array &$j, int $i, array $m, string $folder, string $lv, ?array $states, array &$undo): array
{
    $dir = "$folder/" . RSP_INSIDE;
    $pkg = rsVmPackage($m, basename($folder), $dir);
    $name = $pkg['name'];
    if ($states === null) {
        return ['state' => 'skipped', 'why' => 'start_vm_service_off', 'name' => $name];
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/D', $name)) {
        return ['state' => 'skipped', 'why' => 'start_vm_odd_name', 'name' => $name];
    }
    if (isset($states[$name])) {
        return ['state' => 'skipped', 'why' => 'start_vm_exists', 'name' => $name];
    }
    if ($pkg['xml'] === '') {
        return ['state' => 'failed', 'note' => 'restore_file_gone', 'params' => ['path' => "$dir/<vm>.xml"], 'name' => $name];
    }
    $original = (string) @file_get_contents("$dir/{$pkg['xml']}", false, null, 0, 1 << 22);
    [$xml, $rewritten] = rspRewriteXml($original, $folder);
    // 1.60.1: from another server - fitted to this host (what changed said in the step), the original kept beside it
    $host = rspHostFacts();
    $adapted = [];
    if (strtolower(rsStr($m['host'] ?? '')) !== ($host['host'] ?? '')) {
        [$xml, $adapted, $refuse] = rspAdaptXml($xml, $host);
        if ($refuse !== null) {
            return ['state' => 'failed', 'note' => 'start_vm_disk_missing', 'params' => ['name' => $name, 'path' => $refuse['path']], 'name' => $name];
        }
    }
    // the copy is the partner's word: nothing of this server but the VM's own folder, before anything is put in place
    [$xml, $safe, $refuse] = rspXmlForeign($xml, $name, [$folder]);
    if ($refuse !== null) {
        return ['state' => 'failed', 'note' => $refuse['note'], 'params' => ['name' => $name] + $refuse['params'], 'name' => $name];
    }
    $adapted = array_merge($adapted, $safe);
    $keep = "$dir/$name.xml.original";
    if ($adapted && !file_exists($keep) && !is_link($keep)) {
        @file_put_contents($keep, $original);
    }
    $back = "$lv/" . RS_LIBVIRT_ASIDE . "/{T}/$name";
    $put = $skipped = [];
    foreach ($pkg['nvram'] as $n) {
        if (preg_match('/S\d{14}_VARS/', $n) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $n)) {
            continue;
        }
        $to = "$lv/qemu/nvram/$n";
        if (file_exists($to) || is_link($to)) {
            $skipped[] = $n;
            continue;
        }
        if (!rspPutFile("$dir/nvram/$n", $to)) {
            return ['state' => 'failed', 'note' => 'copy_failed', 'params' => ['path' => $to], 'name' => $name];
        }
        $undo[] = ['do' => 'aside', 'path' => $to, 'to' => "$back/nvram/$n", 'optional' => true];
        $put[] = $n;
    }
    $tpm = 'none';
    if ($pkg['tpm']) {
        $to = "$lv/qemu/swtpm/tpm-states/{$pkg['uuid']}";
        if (file_exists($to) || is_link($to)) {
            $tpm = 'kept';
        } elseif (!rsMkdirs(dirname($to)) || rsRun($j, ['cp', '-a', "$dir/tpm/{$pkg['uuid']}", $to], 120)[0] !== 0) {
            return ['state' => 'failed', 'note' => 'copy_failed', 'params' => ['path' => $to], 'name' => $name];
        } else {
            $undo[] = ['do' => 'aside', 'path' => $to, 'to' => "$back/tpm/{$pkg['uuid']}", 'optional' => true];
            $tpm = 'put';
        }
    }
    $file = rsDir($j['id']) . "/start-$i-" . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '.xml';
    if (!@file_put_contents($file, $xml)) {
        return ['state' => 'failed', 'note' => 'copy_failed', 'params' => ['path' => $file], 'name' => $name];
    }
    [$exit, , $err] = rsRun($j, [partnerBin('virsh') ?? 'virsh', 'define', $file], 60);
    if ($exit !== 0) {
        return ['state' => 'failed', 'note' => 'virsh_failed', 'params' => ['name' => $name], 'detail' => trim($err), 'name' => $name];
    }
    array_unshift($undo, ['do' => 'undefine', 'name' => $name]);        // the definition first, then its files aside
    return ['state' => 'ok', 'name' => $name, 'rewritten' => $rewritten, 'nvram' => count($put), 'nvram_kept' => count($skipped), 'tpm' => $tpm, 'adapted' => $adapted];
}

/** An app's package inside the copy: its template(s) into Unraid's templates-user (never over one that is there), its dumps named */
function rspStartApp(array $m, string $folder, string $templates, bool $place, array &$undo): array
{
    $dir = "$folder/" . RSP_INSIDE;
    $name = rsStr($m['name'] ?? '') ?: basename($folder);
    $put = $skipped = [];
    foreach (glob("$dir/my-*.xml") ?: [] as $t) {
        $f = basename($t);
        if (!preg_match('/^my-[A-Za-z0-9_.-]+\.xml$/D', $f)) {
            continue;
        }
        $to = "$templates/$f";
        if (file_exists($to) || is_link($to) || !$place) {
            $skipped[] = $f;          // there already, or only the copy was made: the template waits in the copy
            continue;
        }
        if (!rspPutFile($t, $to)) {
            return ['state' => 'failed', 'note' => 'copy_failed', 'params' => ['path' => $to], 'name' => $name];
        }
        $undo[] = ['do' => 'aside', 'path' => $to, 'to' => rsAsideFor($to, '{T}', 'putback'), 'optional' => true];
        $put[] = $f;
    }
    $dumps = array_values(array_map(fn ($d) => "$dir/db/" . basename($d), array_filter(glob("$dir/db/*") ?: [], 'is_file')));
    $stack = ($m['type'] ?? '') === 'compose' && $place ? rspStartStack($m, $dir, $undo) : null;
    if (($stack['state'] ?? '') === 'failed') {
        return ['state' => 'failed', 'note' => 'copy_failed', 'params' => ['path' => $stack['path']], 'name' => $name];
    }
    return ['state' => 'ok', 'name' => $name, 'templates' => $put, 'skipped' => $skipped, 'dumps' => $dumps, 'stack' => $stack];
}

/** The shares a stack binds that aren't here (its missing /mnt/user/<share> paths): its data didn't come with the copy */
function rspSharesMissing(array $missing): array
{
    $out = [];
    foreach ($missing as $p) {
        if (preg_match('#^/mnt/user/([^/]+)$#D', (string) $p, $m)) {
            $out[] = $m[1];
        }
    }
    return array_values(array_unique($out));
}

/** A folder of our own making gone again (only its plain files: what rspStartStack wrote) */
function rspDropTmp(string $tmp): void
{
    foreach (glob("$tmp/{,.}*", GLOB_BRACE) ?: [] as $f) {
        if (is_file($f) && !is_link($f)) {
            @unlink($f);
        }
    }
    @rmdir($tmp);
}

/** Is the Compose Manager here (its plugin installed)? $GLOBALS['rs']['compose_here'] for the tests */
function rspComposeHere(): bool
{
    return (bool) ($GLOBALS['rs']['compose_here'] ?? is_dir('/usr/local/emhttp/plugins/compose.manager'));
}

/**
 * A stack's compose files fitted to this host (1.60.1): a bind path on a pool or disk that isn't here
 * (/mnt/<pool>/<share>/…) points at /mnt/user/<share>/… when that share is here, else it is named as missing.
 * Returns [text, mapped [[from, to]], missing [path]]
 */
function rspFitCompose(string $text, callable $poolHere, callable $shareHere, ?callable $pathHere = null): array
{
    $pathHere ??= fn (string $p): bool => file_exists($p);
    $mapped = $missing = [];
    $out = preg_replace_callback('#/mnt/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.@ -]*[A-Za-z0-9_.@-])#', function (array $m) use ($poolHere, $shareHere, &$mapped, &$missing): string {
        [$all, $pool, $share] = $m;
        if (in_array($pool, ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true) || $poolHere($pool)) {
            return $all;
        }
        if ($shareHere($share)) {
            $mapped["/mnt/$pool/$share"] = "/mnt/user/$share";
            return "/mnt/user/$share";
        }
        $missing[$all] = true;
        return $all;
    }, $text);
    // 1.60.1 (a user, dropnook on Backup2: /mnt/user/drop/... and no share drop there): every bind source - a share that
    // isn't here, any other absolute host path that isn't there - named as missing (never made by me)
    // host paths only (the dropnook: x-settings anchors «data: &data /mnt/user/drop», volumes «- type: bind / source:
    // *data»): an anchor's path, a long syntax's source, a short syntax's part before its first colon - never a target
    $srcs = [];
    foreach (['~&[A-Za-z0-9_-]+\h+["\']?(/[^\s"\'#,]+)~', '~^\h*source:\h*["\']?(/[^\s"\'#,]+)~m', '~^\h*-\h*["\']?(/[^\s"\':#,]+):~m'] as $re) {
        preg_match_all($re, (string) $out, $mm);
        array_push($srcs, ...$mm[1]);
    }
    foreach (array_unique($srcs) as $src) {
        $src = rtrim($src, '/');
        if (preg_match('#^/mnt/(?:user0?|disks|remotes|addons)/([^/]+)#', $src, $u)) {
            if (str_starts_with($src, '/mnt/user') && !$shareHere($u[1])) {
                $missing["/mnt/user/{$u[1]}"] = true;
            } elseif (str_starts_with($src, '/mnt/user/') && !$pathHere($src)) {
                $missing[$src] = true;                 // the share is here, that folder isn't (another app's, not restored)
            }
        } elseif (!preg_match('#^/mnt/[^/]+/#', $src) && !$pathHere($src)) {
            $missing[$src] = true;
        }
    }
    return [(string) $out, array_map(fn ($f, $t) => [$f, $t], array_keys($mapped), $mapped), array_keys($missing)];
}

/**
 * A Compose stack from another server's copy (1.60.1: a user's stack «dropnook»): its Compose Manager project made
 * here from the package's compose/ under the same folder name - never over a project of that name (left, said), never
 * without the Compose Manager (nothing made, said: install it first). Its compose files fitted (rspFitCompose), the
 * originals beside them as *.original; its autostart as it was, never started by the restore. The undo puts it aside.
 */
function rspStartStack(array $m, string $dir, array &$undo): array
{
    $c = is_array($m['compose'] ?? null) ? $m['compose'] : [];
    $name = rsStr($c['project'] ?? $m['name'] ?? '');
    $folder = rsStr($c['manager_dir'] ?? '') ?: $name;
    if (!is_dir("$dir/compose") || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/D', $folder)) {
        return ['state' => 'no_files', 'name' => $name];
    }
    $poolHere = $GLOBALS['rs']['pool_here'] ?? fn (string $p): bool => is_dir("/mnt/$p");
    $shares = rspSharesHere();
    $shareHere = fn (string $sh): bool => is_array($shares) ? isset($shares[$sh]) : is_dir("/mnt/user/$sh");
    // without the Compose Manager (a user: Dockge, Portainer or docker compose by hand are ways too) - a hint, never a block:
    // the copy's own compose files fitted where they are (the originals beside as *.original), nothing created
    if (!rspComposeHere()) {
        [$mapped, $missing] = rspFitComposeDir("$dir/compose", "$dir/compose", $poolHere, $shareHere);
        return ['state' => rspComposeCli() ? 'no_manager_cli' : 'no_manager', 'name' => $name, 'path' => "$dir/compose", 'mapped' => $mapped, 'missing' => $missing,
                'shares_missing' => rspSharesMissing($missing)];
    }
    $root = (string) ($GLOBALS['rs']['compose_root'] ?? rsComposeRoot());
    $to = "$root/$folder";
    if (file_exists($to) || is_link($to)) {
        return ['state' => 'exists', 'name' => $name, 'path' => "$dir/compose"];
    }
    $tmp = "$root/.$folder.uso-" . bin2hex(random_bytes(4));
    if (!rsMkdirs($root) || !@mkdir($tmp, 0755)) {
        return ['state' => 'failed', 'path' => $to];
    }
    $fit = rspFitComposeDir("$dir/compose", $tmp, $poolHere, $shareHere);
    if ($fit === null || !@rename($tmp, $to)) {
        rspDropTmp($tmp);
        return ['state' => 'failed', 'path' => $to];
    }
    [$mapped, $missing] = $fit;
    $undo[] = ['do' => 'aside', 'path' => $to, 'to' => rsAsideFor($to, '{T}', 'putback'), 'optional' => true];
    return ['state' => 'created', 'name' => $name, 'path' => $to, 'autostart' => trim((string) @file_get_contents("$to/autostart")) === 'true',
            'mapped' => $mapped, 'missing' => $missing, 'shares_missing' => rspSharesMissing($missing)];
}

/**
 * «Create the stack now» (1.60.1): a finished restore whose stack waited for the Compose Manager (no_manager…) - once it
 * is here, only the stack part from the copy the journal recorded (its .uso-backup/compose), by the same rules (never
 * over a project of that name, fitted, never started); the stack's line and the step's undo in the journal anew.
 */
function rspStackLater(string $id, string $name): array
{
    if (!preg_match(RS_ID_PATTERN, $id)) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    $j = rsJournal($id);
    if (!is_array($j) || in_array($j['result'] ?? '', ['queued', 'running'], true)) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    if (!rspComposeHere()) {
        throw new Problem('restore_compose_missing');
    }
    foreach ((array) $j['steps'] as $i => $st) {
        foreach ((array) ($st['params']['stacks'] ?? []) as $k => $stack) {
            if (($stack['name'] ?? '') !== $name || !in_array($stack['state'] ?? '', ['no_manager', 'no_manager_cli'], true)) {
                continue;
            }
            $dir = dirname((string) ($stack['path'] ?? ''));
            $m = is_file("$dir/manifest.json") ? readJson("$dir/manifest.json") : null;
            if (basename($dir) !== RSP_INSIDE || !is_array($m)) {
                throw new Problem('restore_file_gone', ['path' => "$dir/manifest.json"]);
            }
            $undo = [];
            $r = rspStartStack($m, $dir, $undo);
            if (($r['state'] ?? '') === 'failed') {
                throw new Problem('restore_file_gone', ['path' => (string) ($r['path'] ?? '')]);
            }
            $j['steps'][$i]['params']['stacks'][$k] = $r + ['later' => time()];
            $j['steps'][$i]['undo'] = array_merge((array) ($j['steps'][$i]['undo'] ?? []), $undo);
            rsJournalWrite($j);
            logLine("Mr. Restori: $id - stack $name " . ($r['state'] ?? '?') . ' afterwards');
            return ['ok' => true, 'stack' => $r, 'state' => rsScan()];
        }
    }
    throw new Problem('unknown_target', ['target' => "$id $name"]);
}

/** Is `docker compose` here (without the Compose Manager: a stack then won't show in Unraid's Docker tab)? */
function rspComposeCli(): bool
{
    return (bool) ($GLOBALS['rs']['compose_cli'] ?? (houseDocker(['compose', 'version'])[0] === 0));      // a plan's look, in the agent's round (B15)
}

/**
 * A stack's files from $from into $to (the same folder: fitted in place), its compose files and .env fitted
 * (rspFitCompose), each changed one's original beside it as <name>.original (never over one there). [mapped, missing],
 * null when a file couldn't be written
 */
function rspFitComposeDir(string $from, string $to, callable $poolHere, callable $shareHere): ?array
{
    $mapped = $missing = [];
    foreach (glob("$from/{,.}*", GLOB_BRACE) ?: [] as $f) {
        $b = basename($f);
        if (!is_file($f) || is_link($f) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $b)) {
            continue;
        }
        if (str_ends_with($b, '.original')) {          // kept as it is - carried into a new project beside its fitted file
            if ($to !== $from && !file_exists("$to/$b") && @copy($f, "$to/$b") === false) {
                return null;
            }
            continue;
        }
        $text = (string) @file_get_contents($f, false, null, 0, 1 << 20);
        $out = $text;
        if (preg_match('/(\.ya?ml|^\.env)$/', $b)) {
            [$out, $mp, $ms] = rspFitCompose($text, $poolHere, $shareHere, $GLOBALS['rs']['path_here'] ?? null);
            $mapped = array_merge($mapped, $mp);
            $missing = array_merge($missing, $ms);
            if ($out !== $text && !file_exists("$to/$b.original")) {
                @file_put_contents("$to/$b.original", $text);
            }
        }
        if (($to !== $from || $out !== $text) && @file_put_contents("$to/$b", $out) === false) {
            return null;
        }
    }
    return [array_values(array_unique($mapped, SORT_REGULAR)), array_values(array_unique($missing))];
}

/**
 * What the copy's .uso-backup allows, once the files are in place: a VM's definition (XML pointed at the new folder,
 * UEFI variables and TPM state put where nothing is - never over), an app's template into Unraid's templates (never
 * over one that is there) with its dumps named. A share's copy (2.44: the engine writes every VM's and app's package
 * into its folder) is scanned one level deep - every VM found is defined by the same rules (a VM of that name here:
 * refused, said), every app's template put; the step says what it found. Without .uso-backup anywhere (an older
 * sender): said, nothing more - the files are there, the VM or app is the user's to create. Each thing put records
 * its undo.
 */
function rsDoStartFrom(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $place = is_string($s['to'] ?? null) && $s['to'] !== '';
    $base = $place ? rtrim((string) $s['to'], '/') : rtrim((string) ($s['mount'] ?? ''), '/');
    $lv = (string) ($s['libvirt'] ?? rspLibvirt());
    $templates = (string) ($s['templates'] ?? rspTemplates());
    $states = array_key_exists('vm_states', $GLOBALS['rs'] ?? []) ? $GLOBALS['rs']['vm_states'] : rsVmStates();
    clearstatcache();
    $undo = [];
    $manifest = fn (string $folder): ?array => is_file("$folder/" . RSP_INSIDE . '/manifest.json') ? readJson("$folder/" . RSP_INSIDE . '/manifest.json') : null;
    if (($s['kind'] ?? '') === 'vm') {
        $m = $manifest($base);
        if (!is_array($m) || ($m['kind'] ?? '') !== 'vm') {
            return ['state' => 'warning', 'note' => 'start_no_package', 'params' => ['path' => $base]];
        }
        $name = (string) ($s['name'] ?? rsStr($m['name'] ?? ''));
        if (!$place) {
            return ['state' => 'warning', 'note' => 'start_vm_in_copy', 'params' => ['name' => rsStr($m['name'] ?? ''), 'path' => "$base/" . RSP_INSIDE]];
        }
        if (empty($s['define'])) {
            return ['state' => 'warning', 'note' => (string) ($s['define_why'] ?? 'start_vm_exists'), 'params' => ['name' => $name, 'path' => "$base/" . RSP_INSIDE]];
        }
        $r = rspStartVm($j, $i, $m, $base, $lv, $states, $undo);
        if ($r['state'] === 'failed') {
            return rsFail($r['note'], $r['params'], (string) ($r['detail'] ?? '')) + ['undo' => $undo];
        }
        if ($r['state'] === 'skipped') {
            return ['state' => 'warning', 'note' => $r['why'], 'params' => ['name' => $name, 'path' => "$base/" . RSP_INSIDE], 'undo' => $undo];
        }
        return ['state' => 'ok', 'undo' => $undo, 'note' => 'start_vm', 'params' => array_diff_key($r, ['state' => 1])];
    }
    // a share's copy: the app at its root (a share of its own), the VMs' and apps' folders one level below - only
    // those ticked (`only`, 1.60.1); an app's own dataset (kind app, engine 2.45): its folder alone
    $folders = [$base];
    $only = is_array($s['only'] ?? null) ? $s['only'] : null;
    foreach (($s['kind'] ?? '') === 'app' ? [] : (glob("$base/*", GLOB_ONLYDIR) ?: []) as $d) {
        if (!is_link($d) && basename($d) !== RSP_INSIDE && ($only === null || in_array(basename($d), $only, true))) {
            $folders[] = $d;
        }
    }
    $vms = $refused = $apps = $notes = $stacks = [];
    foreach ($folders as $folder) {
        $m = $manifest($folder);
        if (!is_array($m)) {
            continue;
        }
        if (($m['kind'] ?? '') === 'vm') {
            if (!$place) {
                $refused[] = rsStr($m['name'] ?? basename($folder)) . ' (copy only)';
                continue;
            }
            $r = rspStartVm($j, $i, $m, $folder, $lv, $states, $undo);
            if ($r['state'] === 'failed') {
                return rsFail($r['note'], $r['params'], (string) ($r['detail'] ?? '')) + ['undo' => $undo];
            }
            if ($r['state'] === 'skipped') {
                $refused[] = $r['name'] . ' (' . ($r['why'] === 'start_vm_exists' ? 'a VM of that name is here' : ($r['why'] === 'start_vm_service_off' ? 'VM service off' : 'odd name')) . ')';
            } else {
                $vms[] = $r['name'] . ($r['adapted'] ? ' (fitted to this server: ' . implode(', ', array_column($r['adapted'], 'code')) . ')' : '');
            }
        } elseif (($m['kind'] ?? '') === 'app') {
            $r = rspStartApp($m, $folder, $templates, $place, $undo);
            if ($r['state'] === 'failed') {
                return rsFail($r['note'], $r['params']) + ['undo' => $undo];
            }
            if ($r['stack'] !== null) {
                $stacks[] = $r['stack'];
            }
            $apps[] = $r['name'] . ($r['templates'] ? ' (template put)' : ($r['skipped'] ? ' (template left as it is)' : ($r['stack'] ? ' (a Compose stack)' : ' (no template)')))
                . ($r['dumps'] ? ' · dumps: ' . implode(', ', array_map('basename', $r['dumps'])) : '');
            $notes = array_merge($notes, $r['dumps']);
        }
    }
    if (!$vms && !$refused && !$apps) {
        return ['state' => 'warning', 'note' => 'start_no_package', 'params' => ['path' => $base], 'undo' => $undo];
    }
    $stackTrouble = array_filter($stacks, fn ($k) => in_array($k['state'], ['no_manager', 'no_manager_cli', 'exists'], true) || !empty($k['missing']));
    return ['state' => $refused || $stackTrouble ? 'warning' : 'ok', 'undo' => $undo, 'note' => 'start_share_found',
            'params' => ['stacks' => $stacks, 'n_vms' => count($vms), 'vms' => $vms ?: ['–'], 'refused' => $refused ?: ['–'], 'n_apps' => count($apps), 'apps' => $apps ?: ['–'], 'dumps' => $notes, 'path' => $base]];
}

// ===================================================================== a pulled share swapped in (the restore matrix's gap 6)

/**
 * The swap's own journal of a pull, if one made it the share and isn't put back: its id (a pull swapped in is the share's
 * dataset now — it has no read-only copy beside any more, «Remove what was pulled…» waits for the swap's put back).
 */
function rspSwappedBy(string $pullId, ?array $rows = null): ?string
{
    foreach ($rows ?? rsJournals() as $row) {
        if (($row['kind'] ?? '') === 'partner_swap' && ($row['target']['id'] ?? '') === $pullId
            && in_array($row['result'] ?? '', ['queued', 'running', 'ok', 'warnings', 'failed', 'interrupted'], true)
            && !in_array($row['putback']['result'] ?? '', ['ok', 'warnings'], true)
            && (($row['result'] ?? '') !== 'failed' || ($row['can_putback'] ?? false))) {
            return (string) $row['id'];
        }
    }
    return null;
}

/** The disks asleep as his plans see them (tests: $GLOBALS['rs']['asleep_disks']) */
function rspAsleepDisks(): array
{
    return $GLOBALS['rs']['asleep_disks'] ?? sleepingDisks();
}

/**
 * A moment of this unit newer than the one pulled that the partner keeps (review of task/restore-gaps, H1): the newest
 * the engine sent there since (state/partner-sent.json) or the partner named at its last look (held.json). Swapping in
 * an older moment would stop the copy there: the engine would send from the pulled moment, the partner's newest is
 * another, and its door never receives with -F. Null: the pulled moment is the newest there.
 */
function rspNewerAtPartner(string $pair, string $unit, string $snap, ?int $time): ?string
{
    $newer = [];
    if (isset($GLOBALS['rs']['held_newer'])) {
        $newer = ($GLOBALS['rs']['held_newer'])($pair, $unit, $snap);            // tests: the partner's last look
    } else {
        foreach ((array) ((rspHeld($pair) ?? [])['units'][$unit] ?? []) as $x) {
            $later = is_int($x['creation'] ?? null) && $time !== null ? $x['creation'] > $time : strcmp((string) $x['name'], $snap) > 0;
            if ($x['name'] !== $snap && $later) {
                $newer[] = (string) $x['name'];
            }
        }
    }
    $sent = (readJson(rsUbData() . '/state/partner-sent.json') ?? [])[$pair][$unit]['snap'] ?? null;
    if (is_string($sent) && preg_match(PARTNER_SNAP_RE, $sent) && $sent !== $snap && strcmp($sent, $snap) > 0) {
        $newer[] = $sent;
    }
    rsort($newer, SORT_STRING);
    return $newer[0] ?? null;
}

/**
 * The swap standing on this share's dataset (another pull swapped in and not put back): its entry's id, or null —
 * a second swap over it would make the first one's put back fail (review L3)
 */
function rspSwapStanding(string $dataset, string $exceptPull): ?string
{
    foreach (rsJournals() as $row) {
        if (($row['kind'] ?? '') === 'partner_swap' && ($row['target']['dataset'] ?? '') === $dataset && ($row['target']['id'] ?? '') !== $exceptPull
            && rspSwappedBy((string) ($row['target']['id'] ?? '')) === $row['id']) {
            return (string) $row['id'];
        }
    }
    return null;
}

/** A dataset's property as zfs says it (value and where it comes from), null when not there */
function rspDsProp(string $ds, string $prop): ?array
{
    [$exit, $out] = run([rspZfs(), 'get', '-H', '-o', 'value,source', $prop, $ds], 20);
    $f = explode("\t", rtrim($out, "\n"));
    return $exit === 0 && count($f) >= 2 ? ['value' => $f[0], 'source' => $f[1]] : null;
}

/** The datasets inside one (filesystems and volumes, itself left out) */
function rspDsInside(string $ds): array
{
    [$exit, $out] = run([rspZfs(), 'list', '-H', '-o', 'name', '-r', '-t', 'filesystem,volume', $ds], 30);
    return $exit === 0 ? array_values(array_filter(explode("\n", trim($out)), fn ($n) => $n !== '' && $n !== $ds)) : [];
}

/**
 * Kind `partner_swap`: a pull of a whole share (beside it, read-only: <pool>/<share>.restored-<time>) becomes the share —
 * the share's dataset <pool>/<share> goes aside whole with its snapshots (zfs rename to a hidden name at the pool's top,
 * <pool>/.UnraidSecretaryOffice-restore-<time>-<share>: never a share of its own, never destroyed), the pull takes its
 * name and mountpoint. Nothing is copied: seconds, while what uses the share is stopped. Refused while a program holds a
 * file of it open, a VM with disks in it runs, Docker or an image runs from it (rsLoopHolders), for the office's own folders
 * (rsGuardPaths), a share that is no dataset of its own at a pool's top, or datasets inside either. «Put back» swaps them
 * back: the pull read-only beside again, the share its old dataset.
 * A share made here that has no dataset yet on the pull's pool (a new server: Unraid makes /mnt/<pool>/<share> only at
 * the first write) and nothing at its place (rspShareFresh(): no folder, or an empty plain one): the pull simply takes
 * that place — nothing goes aside, the share's settings untouched; «Put back» makes it the read-only pull again.
 */
function rsPlanPartnerSwap(array $r, string $stamp): array
{
    $id = textField($r, 'id');
    $j = preg_match(RS_ID_PATTERN, $id) ? rsJournal($id) : null;
    if (!$j || ($j['kind'] ?? '') !== 'partner') {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    $unit = (string) ($j['target']['unit'] ?? '');
    $share = str_starts_with($unit, 'share:') ? substr($unit, 6) : '';
    $partner = (string) ($j['target']['partner'] ?? '');
    $plan = rsPlanBase('partner_swap', "$share ← $partner", ['id' => $id, 'unit' => $unit, 'share' => $share, 'partner' => $partner]);
    $pull = null;
    foreach ((array) ($j['pulled'] ?? []) as $p) {
        $pull = is_array($p) && ($p['unit'] ?? '') === $unit && is_string($p['dataset'] ?? null) ? $p : $pull;
    }
    $done = in_array($j['putback']['result'] ?? '', ['ok', 'warnings', 'running', 'queued'], true);
    if ($share === '' || $pull === null || !in_array($j['result'] ?? '', ['ok', 'warnings'], true) || $done || !preg_match('/^[\w .-]{1,200}$/D', $share)) {
        $plan['blockers'][] = ['key' => 'restore_swap_not', 'params' => []];
        return $plan;
    }
    if (rspSwappedBy($id) !== null) {
        $plan['blockers'][] = ['key' => 'restore_swap_done', 'params' => ['share' => $share]];
        return $plan;
    }
    $dn = (string) $pull['dataset'];
    $do = (string) preg_replace(RSP_OWN_RE, '', $dn);
    $pool = explode('/', $do)[0];
    $live = "/mnt/$pool/$share";
    $mount = is_string($pull['mount'] ?? null) && dirname($pull['mount']) === rspMountRoot() ? (string) $pull['mount'] : '';
    // a pull under UnraidSecretaryOffice-restored (no dataset of the share here then), or a share deeper than a pool's top
    if (!rspOwnTarget($dn) || $do === $dn || $do !== "$pool/$share") {
        $plan['blockers'][] = ['key' => 'restore_swap_no_dataset', 'params' => ['share' => $share]];
        return $plan;
    }
    $plan['target'] += ['dataset' => $do, 'pulled' => $dn, 'pool' => $pool, 'path' => $live];
    if (!rspDsExists($dn)) {
        $plan['blockers'][] = ['key' => 'restore_aside_gone', 'params' => ['path' => $dn]];
        return $plan;
    }
    $guid = rspDsGet($dn, 'guid');
    if (is_string($pull['guid'] ?? null) && $guid !== $pull['guid']) {
        $plan['blockers'][] = ['key' => 'restore_swap_not', 'params' => []];
        return $plan;
    }
    if (rspDsGet($dn, 'receive_resume_token') !== null) {
        $plan['blockers'][] = ['key' => 'restore_swap_not', 'params' => []];
        return $plan;
    }
    if (baseAsleep($pool, rspAsleepDisks())) {
        $plan['blockers'][] = ['key' => 'restore_asleep', 'params' => ['base' => $pool]];
        return $plan;
    }
    // no dataset at the share's place: a share made here, nothing there yet (2026-10-28) — else no swap
    $fresh = false;
    if (!rspDsExists($do)) {
        $ini = rspSharesHere()[$share] ?? null;
        $fresh = is_array($ini) && rspShareFresh($share, $pool, $ini) === null;
        if (!$fresh) {
            $plan['blockers'][] = ['key' => 'restore_swap_no_dataset', 'params' => ['share' => $share]];
            return $plan;
        }
        // its folder comes where the pool puts its datasets: /mnt/<pool>/<share>
        $pmp = rspDsProp($pool, 'mountpoint');
        if ($pmp === null || $pmp['value'] !== "/mnt/$pool") {
            $plan['blockers'][] = ['key' => 'restore_swap_mountpoint', 'params' => ['dataset' => $pool]];
            return $plan;
        }
    }
    $plan['target']['fresh'] = $fresh;         // its put back leaves the share no folder here (rspSwapUnlisted())
    $other = rspSwapStanding($do, $id);
    if ($other !== null) {
        $plan['blockers'][] = ['key' => 'restore_swap_other', 'params' => ['share' => $share, 'entry' => $other]];
        return $plan;
    }
    // the copy at the partner must go on from what is swapped in: only its newest moment (H1)
    $pair = ($j['target']['kind'] ?? 'pair') === 'pair' ? (string) ($j['target']['pair'] ?? '') : '';
    $snap = (string) ($pull['snap'] ?? '');
    $newer = $pair !== '' ? rspNewerAtPartner($pair, $unit, $snap, is_int($pull['time'] ?? null) ? $pull['time'] : null) : null;
    if ($newer !== null) {
        $plan['blockers'][] = ['key' => 'restore_swap_not_newest', 'params' => ['name' => $partner, 'snap' => $snap, 'newest' => $newer, 'share' => $share]];
        return $plan;
    }
    if ($pair !== '') {
        $plan['notes'][] = ['key' => 'note.swap_partner_goes_on', 'params' => ['name' => $partner, 'snap' => $snap]];
    }
    // the share's dataset: its mountpoint where Unraid has it, taken from the pool (a rename moves it along)
    $mp = $fresh ? null : rspDsProp($do, 'mountpoint');
    if (!$fresh && ($mp === null || $mp['value'] !== $live || !($mp['source'] === 'default' || str_starts_with($mp['source'], 'inherited')))) {
        $plan['blockers'][] = ['key' => 'restore_swap_mountpoint', 'params' => ['dataset' => $do]];
    }
    $inner = array_merge($fresh ? [] : rspDsInside($do), rspDsInside($dn));
    if ($inner) {
        $plan['blockers'][] = ['key' => 'restore_swap_inner', 'params' => ['list' => implode(', ', $inner)]];
    }
    // the office's own folders never (its data, the engine's, Kopia's restore folder): a share holding them stays
    $settings = backupReadSettings(rsUbData() . '/settings.ini');
    $ctx = rsContext($settings);
    $kopiaFolder = (($GLOBALS['rs']['state'] ?? null) ?: (readJson(deskFile('restore')) ?? []))['kopia']['restore']['source'] ?? null;   // his last look
    $office = rsGuardHit($live, rsGuardPaths(is_string($kopiaFolder) ? $kopiaFolder : null)) ?? rsGuardHit("/mnt/user/$share", rsGuardPaths(null));
    if ($office !== null) {
        $plan['blockers'][] = ['key' => 'restore_office_data', 'params' => ['path' => "/mnt/user/$share", 'office' => $office]];
    }
    $da = "$pool/" . OFFICE_ASIDE . "-$stamp-$share";
    $asideP = "/mnt/$pool/" . OFFICE_ASIDE . "-$stamp-$share";
    if (!$fresh && (rspDsExists($da) || file_exists($asideP))) {
        $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $asideP]];
    }
    // the share's other parts (another pool, the array) stay as they are: the pull is this pool's dataset alone
    $others = [];
    foreach (rsLocate("/mnt/user/$share", $ctx) as $p) {
        if ((string) $p['base'] !== $pool && !empty($p['exists'])) {
            $others[] = (string) $p['base'];
        }
    }
    if ($others) {
        $plan['notes'][] = ['key' => 'note.swap_other_parts', 'warn' => true, 'params' => ['pool' => $pool, 'bases' => implode(', ', $others)]];
    }
    [$exit, $out] = $fresh ? [1, ''] : run([rspZfs(), 'list', '-H', '-o', 'name', '-t', 'snapshot', '-d', '1', $do], 30);
    $snaps = $exit === 0 ? count(array_filter(explode("\n", trim($out)))) : 0;
    if ($snaps) {
        $plan['notes'][] = ['key' => 'note.swap_snaps', 'params' => ['n' => $snaps, 'dataset' => $do, 'aside' => $da]];
    }
    // what uses it: containers stop and start again; a running VM, an open file, an attached image refuse
    $paths = ["/mnt/user/$share", $live];
    [$users] = rsUsers($paths, $paths);
    $vms = rsVmsUsing($paths);
    foreach ($vms as $v) {
        if ($v['state'] !== 'shut off') {
            $plan['blockers'][] = ['key' => 'restore_vm_uses', 'params' => ['name' => $v['name'], 'state' => $v['state'], 'path' => $v['path']]];
        }
    }
    $open = array_values(array_filter([$live, $mount]));
    $holders = rsOpenHolders($open);
    if ($holders) {
        $plan['blockers'][] = ['key' => 'restore_in_use', 'params' => ['names' => implode(', ', $holders), 'path' => implode(', ', $open)]];
    }
    foreach (rsLoopHolders([$live]) as $h) {
        $plan['blockers'][] = rsLoopBlocker($h);
    }
    $steps = [];
    if ($vms) {
        $steps[] = ['do' => 'vms_off', 'paths' => $paths, 'names' => array_column($vms, 'name')];
    }
    $steps[] = ['do' => 'not_open', 'paths' => $open];
    if ($users) {
        $steps[] = ['do' => 'stop', 'containers' => $users];
    }
    if ($mount !== '') {
        $steps[] = ['do' => 'pull_umount', 'dataset' => $dn, 'path' => $mount];
    }
    if (!$fresh) {
        $steps[] = ['do' => 'swap_out', 'dataset' => $do, 'to' => $da, 'path' => $live, 'aside' => $asideP, 'pulled' => $dn, 'pulled_guid' => $guid, 'mount' => $mount];
    }
    $steps[] = ['do' => 'swap_in', 'dataset' => $dn, 'to' => $do, 'guid' => $guid, 'mountpoint' => $live, 'mount' => $mount];
    if ($users) {
        $steps[] = ['do' => 'start', 'containers' => $users, 'only_stopped' => true];
        $plan['putback_pre'] = [['do' => 'stop', 'containers' => $users]];
        $plan['putback_post'] = [['do' => 'start', 'containers' => $users, 'only_stopped' => true]];
    }
    $plan['steps'] = $steps;
    $plan['source'] = ['path' => "$partner: $unit@" . (string) ($pull['snap'] ?? ''), 'time' => $pull['time'] ?? null, 'bytes' => $pull['bytes'] ?? null];
    if (!$fresh) {
        $plan['aside'][] = ['what' => 'share', 'from' => $live, 'to' => $asideP];
    }
    array_unshift($plan['notes'], ['key' => $fresh ? 'note.swap_fresh' : 'note.swap', 'params' => ['share' => $share, 'pool' => $pool, 'partner' => $partner, 'when' => $pull['time'] ?? null]]);   // what happens first
    $plan['after'][] = $fresh ? ['key' => 'after.swap_fresh_done', 'params' => ['share' => $share, 'pool' => $pool]]
        : ['key' => 'after.swap_done', 'params' => ['share' => $share, 'aside' => $asideP, 'dataset' => $da]];
    $plan['stops'] = $users;
    $plan['downtime'] = $users ? 20 : 0;
    return $plan;
}

/** The pull's read-only mount let go (it becomes the share); its undo mounts it again */
function rsDoPullUmount(array &$j, array $s): array
{
    $ds = (string) $s['dataset'];
    $path = (string) $s['path'];
    if (!rspOwnTarget($ds) || dirname($path) !== rspMountRoot()) {
        return rsFail('pull_refused', ['dataset' => $ds]);
    }
    foreach (rspMounts()[$ds] ?? [] as $mnt) {
        if (rsRun($j, [partnerBin('umount') ?? 'umount', $mnt], 60)[0] !== 0) {
            return rsFail('drop_busy', ['path' => $mnt]);
        }
    }
    return ['state' => 'ok', 'undo' => [['do' => 'mount', 'dataset' => $ds, 'path' => $path]]];
}

/**
 * A step's undo written into the journal before the command it undoes (review L1): a power cut between them leaves the way
 * back recorded — each undo of the swap also works when its rename never happened
 */
function rspUndoFirst(array &$j, int $i, array $undo): void
{
    $j['steps'][$i]['undo'] = $undo;
    try {
        rsJournalWrite($j);
    } catch (Throwable) {
        // the job's own write after the step says it; the undo is in the step's answer too
    }
}

/** The share's dataset aside, whole, with its snapshots (zfs rename; its folder follows the name) */
function rsDoSwapOut(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $do = (string) $s['dataset'];
    $da = (string) $s['to'];
    if (!preg_match(RSP_DS_RE, $do) || !preg_match('#^[A-Za-z][A-Za-z0-9_.:-]{0,63}/' . preg_quote(OFFICE_ASIDE, '#') . '-\d{8}-\d{6}-[\w .-]{1,200}$#D', $da)) {
        return rsFail('rename_failed', ['path' => $do]);
    }
    if (!rspDsExists($do)) {
        return rsFail('gone', ['path' => $do]);
    }
    if (rspDsExists($da)) {
        return rsFail('exists', ['path' => $da]);
    }
    // the way back: the old dataset in place again — and, should that rename fail, the pull made the share again (H2)
    $undo = [['do' => 'swap_back', 'dataset' => $da, 'to' => $do, 'path' => (string) $s['path'], 'guid' => rspDsGet($do, 'guid'),
              'aside' => (string) ($s['aside'] ?? ''), 'pulled' => $s['pulled'] ?? null, 'pulled_guid' => $s['pulled_guid'] ?? null, 'mount' => $s['mount'] ?? '']];
    rspUndoFirst($j, $i, $undo);
    [$exit, , $err] = rsRun($j, [rspZfs(), 'rename', $do, $da], 300);
    if ($exit !== 0) {
        return rsFail('rename_failed', ['path' => (string) $s['path']], trim($err));
    }
    $j['aside'][] = ['from' => (string) $s['path'], 'to' => (string) ($s['aside'] ?? ''), 'dataset' => $do, 'to_dataset' => $da];
    @rmdir((string) $s['path']);            // the empty folder ZFS may leave at the old mountpoint (L6)
    return ['state' => 'ok', 'undo' => $undo];
}

/** The pull takes the share's name and mountpoint (from the pool, as the old one had it) and is mounted there */
function rsDoSwapIn(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $dn = (string) $s['dataset'];
    $do = (string) $s['to'];
    $mp = (string) $s['mountpoint'];
    if (!rspOwnTarget($dn) || preg_replace(RSP_OWN_RE, '', $dn) !== $do) {
        return rsFail('pull_refused', ['dataset' => $dn]);
    }
    if (!rspDsExists($dn)) {
        return rsFail('gone', ['path' => $dn]);
    }
    if (is_string($s['guid'] ?? null) && rspDsGet($dn, 'guid') !== $s['guid']) {
        return rsFail('drop_other', ['dataset' => $dn]);
    }
    if (rspDsExists($do)) {
        return rsFail('exists', ['path' => $do]);
    }
    // never mounted over something: the share's place holds nothing (the old dataset's folder went with it; a share
    // without a dataset yet has none, or an empty one — looked at again right before)
    if (str_starts_with($mp, '/mnt/') && !rspPlaceEmpty(rspPoolRoot() . substr($mp, 4))) {
        return rsFail('exists', ['path' => $mp]);
    }
    if (rspMounts()[$dn] ?? []) {
        return rsFail('drop_busy', ['path' => implode(', ', rspMounts()[$dn])]);
    }
    $undo = [['do' => 'swap_unsettle', 'dataset' => $do, 'to' => $dn, 'guid' => $s['guid'] ?? null, 'mountpoint' => $mp]];
    rspUndoFirst($j, $i, $undo);
    rspPullSwapped($j);                     // the pull's own entry knows for good (M3)
    [$exit, , $err] = rsRun($j, [rspZfs(), 'rename', $dn, $do], 300);
    if ($exit !== 0) {
        return rsFail('rename_failed', ['path' => $dn], trim($err));
    }
    // the pull's own properties go: `readonly=on` the pull set at its receive (rspPull); the stream carried none — a
    // `zfs send -L -c` without -p/-R sends no properties (checked 2026-10-31: `zfs get -s received` lists nothing after
    // such a receive), so mountpoint, canmount and readonly are all a pull has of its own (review 1.81.0, N3)
    foreach ([['inherit', 'mountpoint', $do], ['inherit', 'readonly', $do], ['set', 'canmount=on', $do]] as $args) {
        [$exit, , $err] = rsRun($j, array_merge([rspZfs()], $args), 60);
        if ($exit !== 0) {
            return rsFail('swap_mount_failed', ['dataset' => $do, 'path' => $mp], trim($err)) + ['undo' => $undo];
        }
    }
    if (rspDsGet($do, 'mounted') !== 'yes') {
        rsRun($j, [rspZfs(), 'mount', $do], 120);
    }
    if (rspDsGet($do, 'mounted') !== 'yes' || rspDsGet($do, 'mountpoint') !== $mp) {
        return rsFail('swap_mount_failed', ['dataset' => $do, 'path' => $mp]) + ['undo' => $undo];
    }
    return ['state' => 'ok', 'undo' => $undo];
}

/** «Put back» of the swap, first: the share's dataset (the pull swapped in) becomes the read-only pull again */
function rsDoSwapUnsettle(array &$j, array $s): array
{
    $do = (string) $s['dataset'];
    $dn = (string) $s['to'];
    if (!rspOwnTarget($dn) || preg_replace(RSP_OWN_RE, '', $dn) !== $do) {
        return rsFail('pull_refused', ['dataset' => $dn]);
    }
    $guid = is_string($s['guid'] ?? null) ? $s['guid'] : null;
    // done before (an earlier put back that stopped later on, or the rename it undoes never happened): nothing to do
    if (rspDsExists($dn) && ($guid === null || rspDsGet($dn, 'guid') === $guid)) {
        foreach (['canmount=noauto', 'mountpoint=legacy', 'readonly=on'] as $kv) {
            rsRun($j, [rspZfs(), 'set', $kv, $dn], 60);
        }
        return ['state' => 'ok', 'note' => 'already'];
    }
    if (!rspDsExists($do)) {
        return rsFail('gone', ['path' => $do]);
    }
    if ($guid !== null && rspDsGet($do, 'guid') !== $guid) {
        return rsFail('drop_other', ['dataset' => $do]);         // not the dataset the swap put there: left as it is
    }
    if (rspDsExists($dn)) {
        return rsFail('exists', ['path' => $dn]);
    }
    if (rspDsGet($do, 'mounted') === 'yes' && rsRun($j, [rspZfs(), 'umount', $do], 120)[0] !== 0) {
        return rsFail('drop_busy', ['path' => (string) ($s['mountpoint'] ?? $do)]);
    }
    // a pull again: read-only as its receive made it (review 1.81.0, N3)
    foreach ([['set', 'canmount=noauto', $do], ['set', 'mountpoint=legacy', $do], ['set', 'readonly=on', $do], ['rename', $do, $dn]] as $args) {
        [$exit, , $err] = rsRun($j, array_merge([rspZfs()], $args), 300);
        if ($exit !== 0) {
            // never a share left unmounted (H2): back as it was, mounted where the share is
            rspResettle($j, $do, (string) ($s['mountpoint'] ?? ''));
            return rsFail('rename_failed', ['path' => $do], trim($err));
        }
    }
    return ['state' => 'ok'];
}

/** A dataset made the share's again: its mountpoint from the pool, canmount on, mounted — true when it is mounted */
function rspResettle(array &$j, string $ds, string $mountpoint): bool
{
    foreach ([['inherit', 'mountpoint', $ds], ['inherit', 'readonly', $ds], ['set', 'canmount=on', $ds]] as $args) {
        rsRun($j, array_merge([rspZfs()], $args), 60);
    }
    if (rspDsGet($ds, 'mounted') !== 'yes') {
        rsRun($j, [rspZfs(), 'mount', $ds], 120);
    }
    return rspDsGet($ds, 'mounted') === 'yes' && ($mountpoint === '' || rspDsGet($ds, 'mountpoint') === $mountpoint);
}

/** «Put back» of the swap, then: the share's old dataset back in its place (its folder follows the name) */
function rsDoSwapBack(array &$j, array $s): array
{
    $da = (string) $s['dataset'];
    $do = (string) $s['to'];
    $guid = is_string($s['guid'] ?? null) ? $s['guid'] : null;
    // done before, or the rename it undoes never happened: the share's own dataset is in its place
    if (!rspDsExists($da) && rspDsExists($do) && ($guid === null || rspDsGet($do, 'guid') === $guid)) {
        if (rspDsGet($do, 'mounted') !== 'yes') {
            rsRun($j, [rspZfs(), 'mount', $do], 120);
        }
        return ['state' => 'ok', 'note' => 'already'];
    }
    if (!rspDsExists($da)) {
        return rsFail('gone', ['path' => $da]);
    }
    if (rspDsExists($do)) {
        return rsFail('exists', ['path' => $do]);
    }
    [$exit, , $err] = rsRun($j, [rspZfs(), 'rename', $da, $do], 300);
    if ($exit !== 0) {
        // the set-aside dataset busy (a shell or an SMB client in its folder): never a share left missing (H2) — the pull,
        // just turned back into the read-only copy, is made the share again; «Put back» is offered once more
        $dn = is_string($s['pulled'] ?? null) ? $s['pulled'] : '';
        $back = false;
        if ($dn !== '' && rspOwnTarget($dn) && rspDsExists($dn) && !rspDsExists($do)
            && (!is_string($s['pulled_guid'] ?? null) || rspDsGet($dn, 'guid') === $s['pulled_guid'])) {
            foreach (rspMounts()[$dn] ?? [] as $mnt) {
                rsRun($j, [partnerBin('umount') ?? 'umount', $mnt], 60);
            }
            $back = rsRun($j, [rspZfs(), 'rename', $dn, $do], 300)[0] === 0 && rspResettle($j, $do, (string) ($s['path'] ?? ''));
        }
        return rsFail($back ? 'swap_back_busy' : 'rename_failed', ['path' => $back ? (string) ($s['aside'] ?? $da) : $da], trim($err));
    }
    @rmdir((string) ($s['aside'] ?? ''));          // the empty folder ZFS may leave at the old mountpoint (L6)
    if (rspDsGet($do, 'mounted') !== 'yes') {
        rsRun($j, [rspZfs(), 'mount', $do], 120);
    }
    return rspDsGet($do, 'mounted') === 'yes' ? ['state' => 'ok'] : rsFail('swap_mount_failed', ['dataset' => $do, 'path' => (string) ($s['path'] ?? '')]);
}
