<?php
declare(strict_types=1);

/*
 * Mr. Restori across servers (stage 3 of briefs/uso-partner-zfs-plan.md; the federation concept's §3.9) — functions
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
const RSP_MOUNT_ROOT   = '/mnt/addons/UnraidSecretaryOffice/restored';
const RSP_PARENT       = 'UnraidSecretaryOffice-restored';   // on a new server: <pool>/UnraidSecretaryOffice-restored/<unit>
const RSP_NAME_RE      = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/D';
const RSP_DS_RE        = '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}(?:\/[A-Za-z0-9_.: -]{1,200}){1,8}$/D';
const RSP_OWN_RE       = '/\.restored-\d{8}-\d{6}$/D';

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
        $units = rspPairUnits($p['send']['units'], is_array($status) ? ($status['agreed'] ?? null) : null, rspHeld($p['id']));
        if ($units) {
            $out[] = ['id' => $p['id'], 'name' => $p['name'], 'kind' => 'pair', 'of' => null, 'units' => $units, 'expires' => null, 'pair' => $p];
        }
    }
    foreach (partnerTicketPairs() as $t) {
        if ($t['expires'] > time()) {
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'kind' => 'ticket', 'of' => $t['of'], 'units' => $t['units'], 'expires' => $t['expires'], 'pair' => $t];
        }
    }
    return $out;
}

/**
 * What a pair's partner may hold of mine: what I send (send.units), what it agreed to keep (its status' `agreed`, kept by
 * the mutual watch — a unit I no longer send stays there and its door still sends it back), and what its `list` actually
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
    } finally {
        if (trim((string) @file_get_contents(rspPidFile(), false, null, 0, 16)) === (string) getmypid()) {
            @unlink(rspPidFile());
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return 0;
}

/** One source asked; its held.json written */
function rspLookOne(array $src): array
{
    $old = rspHeld($src['id']) ?? ['looked' => null, 'units' => [], 'unit_why' => []];
    $now = time();
    $units = [];
    $why = [];
    $reachable = true;
    $fail = null;
    foreach ($src['units'] as $u) {
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
    $want = is_file(rspWantFile());
    if ($sources && ($due || $want) && !rspLooking() && $now - (int) ($GLOBALS['rspLaunched'] ?? 0) >= ($want ? RSP_WANT_EVERY : 600)) {
        $GLOBALS['rspLaunched'] = $now;
        try {
            hostLaunch('restore-partner-look', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'restore-partner-look']);
        } catch (Throwable $e) {
            logLine('Mr. Restori: the look at the partners could not be started: ' . $e->getMessage());
        }
    }
    $sig = implode(',', $sig) . ($want ? '+' : '') . (rspLooking() ? '*' : '');
    if ($sig !== ($GLOBALS['rspSig'] ?? null) && is_array($GLOBALS['rs']['state'] ?? null)) {
        $GLOBALS['rspSig'] = $sig;
        $state = $GLOBALS['rs']['state'];
        $state['partners'] = rspState();
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

/** His state's `partners`: per source what it holds of mine, newest moment first — never a key */
function rspState(): array
{
    $out = [];
    $looking = rspLooking() || is_file(rspWantFile());
    foreach (rspSources() as $src) {
        $h = rspHeld($src['id']);
        $units = [];
        foreach ($src['units'] as $u) {
            $snaps = array_reverse((array) ($h['units'][$u] ?? []));
            $units[] = ['unit' => $u, 'snaps' => $snaps, 'newest' => $snaps[0]['creation'] ?? null, 'why' => $h['unit_why'][$u] ?? null, 'known' => isset($h['units'][$u])];
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
 * dataset) — the newest moment each.
 *
 * @return list<array{id:string, name:string, unit:string, newest:?int, n:int}>
 */
function rspChips(array $partners, array $shares, ?string $vm = null): array
{
    $want = array_map(fn ($s) => "share:$s", array_values(array_unique(array_filter($shares, 'is_string'))));
    if ($vm !== null) {
        $want[] = "vm:$vm";
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
 *     (swapped in by hand, said how); else <pool>/UnraidSecretaryOffice-restored/<unit> (Unraid makes shares — never he)
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
    // a ticket: the pool chosen (awake ZFS pools only), the share as it is here
    $pools = array_values(array_filter(partnerPools(), fn ($p) => !$p['asleep']));
    $pool ??= $pools[0]['name'] ?? null;
    if ($pool === null || !in_array($pool, array_column($pools, 'name'), true)) {
        $out['blockers'][] = ['key' => $pools ? 'restore_partner_pool' : 'restore_partner_no_pool', 'params' => ['pool' => (string) $pool]];
        return $out;
    }
    $out['pool'] = $pool;
    $shares = $ctx['shares_ini'] ?? readCfg(RS_SHARES_INI, true);
    if (str_starts_with($unit, 'share:')) {
        $share = substr($unit, 6);
        $out['path'] = "/mnt/user/$share";
        if (is_array($shares) && isset($shares[$share]) && rspDsExists("$pool/$share")) {
            $out['dataset'] = "$pool/$share.restored-$stamp";
            $out['parent'] = $pool;
            $out['how'] = 'share_exists';
            $out['notes'][] = ['key' => 'note.partner_share_exists', 'params' => ['share' => $share, 'dataset' => $out['dataset']]];
            return $out;
        }
        $out['notes'][] = ['key' => is_array($shares) && isset($shares[$share]) ? 'note.partner_share_elsewhere' : 'note.partner_share_missing', 'params' => ['share' => $share]];
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
    $placeShare = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $plan = rsPlanBase('partner', rspUnitName($unit, $placeShare) . ' ← ' . $src['name'],
        ['pair' => $id, 'unit' => $unit, 'snap' => $snap, 'pool' => $pool, 'kind' => $src['kind'], 'partner' => $src['name']]);
    $held = rspHeld($id);
    $moment = null;
    foreach ((array) ($held['units'][$unit] ?? []) as $s) {
        $moment = $s['name'] === $snap ? $s : $moment;
    }
    $plan['options'] = ['pools' => $src['kind'] === 'ticket' ? partnerPools() : null, 'kind' => $src['kind']];
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
            $pulls[] = ['place', $pick['name'], $pick];
            $plan['notes'][] = ['key' => 'note.partner_place_too', 'params' => ['name' => $src['name'], 'snap' => $pick['name']]];
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
    $base = backupDumpsPath((string) backupSetting(backupReadSettings(rsUbData() . '/settings.ini'), 'general', 'dumps_share', ''));
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
    $cmd = [partnerBin('mount') ?? 'mount', '-t', 'zfs', '-o', 'ro,noatime', $ds, $path];
    return ($j !== null ? rsRun($j, $cmd, 60) : run($cmd, 60))[0] === 0;
}

/**
 * «Put back» of a pull: exactly the dataset it made goes — its name his own (.restored-<time> or under
 * UnraidSecretaryOffice-restored), its guid the one the pull recorded, no dataset inside it; unmounted first. Never
 * the original; nothing else is destroyed.
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

// ===================================================================== what landed, for his other restores

/**
 * The pulls that landed and are still here: from his journals (kind partner, done, not put back), each pulled
 * dataset with its mount — only while it exists (the kernel's mount table names it, or zfs).
 *
 * @return list<array{journal:string, dataset:string, partner:string, unit:string, snap:string, time:?int, path:?string, mount:?string, mounted:bool}>
 */
function rspPulled(): array
{
    if (!is_dir(rsData())) {
        return [];
    }
    $mounts = rspMounts();
    $out = [];
    foreach (rsJournals() as $row) {
        if ($row['kind'] !== 'partner' || !in_array($row['result'], ['ok', 'warnings', 'failed'], true)
            || (is_array($row['putback']) && in_array($row['putback']['result'], ['ok', 'warnings', 'running', 'queued'], true))) {
            continue;
        }
        $j = rsJournal($row['id']);
        foreach ((array) ($j['pulled'] ?? []) as $p) {
            if (!is_array($p) || !is_string($p['dataset'] ?? null) || !rspOwnTarget($p['dataset']) || !preg_match(PARTNER_UNIT_RE, (string) ($p['unit'] ?? ''))) {
                continue;
            }
            $mnt = is_string($p['mount'] ?? null) && dirname($p['mount']) === rspMountRoot() ? $p['mount'] : null;
            $out[] = ['journal' => $row['id'], 'dataset' => $p['dataset'], 'partner' => (string) ($p['partner'] ?? ''), 'unit' => $p['unit'], 'snap' => (string) ($p['snap'] ?? ''),
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
        if (file_exists($cand)) {
            $out[] = ['id' => 'partner:' . $p['journal'] . ':' . $p['unit'], 'name' => $p['partner'] . ' ' . $p['snap'], 'time' => (int) ($p['time'] ?? 0),
                      'path' => $cand, 'ours' => false, 'kopia' => true, 'partner' => $p['partner']];
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
