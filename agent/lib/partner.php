<?php
declare(strict_types=1);

/*
 * Partner offices (the Team Lead's; the partner plan §3): two offices pair up, each may send the
 * engine's own ZFS snapshots of its datasets to the other (`zfs send` through SSH), the receiver keeps them with a
 * retention of its own. Shared by the agent (the Team Lead's pairing, cards and mutual watch) and the door
 * (agent/partner-door.php, the forced command of a pair's line in authorized_keys) — so nothing here runs at load,
 * and nothing here needs more than util.php, mounts.php and backupscript.php.
 *
 *   data/partner/pairs.json       the pairs (root, folder 0700, file 0600; trusted only in exactly the shape below)
 *   data/partner/pending.json     offers made with «Add a partner…» that wait for the partner's answer (≤ 7 days)
 *   data/partner/state.json       the mutual watch: what each partner last answered (php agent.php job partner-ping)
 *   data/partner/received/<id>.json   what the door received of a pair (unit → newest snapshot, bytes, time)
 *   data/partner/deletes.jsonl    what the door's retention destroyed (for the night watchman)
 *   data/partner/door.log         the door's lines (refusals among them); RAM when the data folder is away
 *   data/partner/wishes/<id>.json the units an older partner (up to 1.59) named at the door with its `offer`: {pair, units,
 *                                 time} — one file per pair, overwritten; the door takes them whole (status says so)
 *   data/partner/migrated.json    the units the pairs sent up to 1.59 ({v, pairs: {id: [unit…]}, time}, the migration step
 *                                 partner-simple) — Mr. Backupsy's setup proposes them at «local + partner» until applied
 *   /boot/config/plugins/unraid-secretary-office/partners/<id>.key (.pub)   my key for a pair (ssh-keygen ed25519)
 *   …/partners/<id>.known         the partner's host keys, pinned (ssh's UserKnownHostsFile for that pair)
 *   /boot/config/ssh/root/authorized_keys   Unraid's: the office writes and removes only lines marked uso-partner:<id>
 *   RUN_DIR/partner/              the door's locks and records, heard-<id> (the last time a pair knocked), refusals
 *
 * Exact shapes, tolerant writes: a reader takes only entries in exactly the shape below (anything else is no pair, no
 * ticket); a writer keeps every entry it doesn't recognise where it stood, as it is (partnerListWrite()) — a newer
 * office's pair survives a downgrade, unused, and is there again when the newer one comes back.
 *
 * pairs.json: {"v":1, "pairs":[{id, name, address, port, host_keys:[fingerprint…], my_key, their_key,
 *   send:{rate_mbit}, receive:{pool, quota_gb, retention, wake}|null, trust, paired, last_heard,
 *   last_answer:{array, night, v}|null}]} — since 1.60 (the concept «Partner einfach»): the Team Lead only pairs. What
 *   this office sends is the level per share and VM in Mr. Backupsy's setup (settings.ini `partner = <id>`,
 *   partnerSendUnits()); the receiver's consent is the pairing — its door takes any unit of the pair up to the quota, at
 *   any time, into its own pool with its own retention. Pairs written up to 1.59 carried send {units, rate_mbit[, wanted,
 *   offered]} (what the partner agreed to) and receive {…, window, units}: read as they are (partnerPairValid() takes both
 *   shapes), stripped by partnerPairUpgrade(), written in the new shape; the migration step `partner-simple` keeps a copy
 *   and the old units for Mr. Backupsy's setup (migrated.json). An older partner (its status without `takes_all`) still
 *   keeps only what its Team Lead agreed to: the mutual watch offers it the units Mr. Backupsy sends (the door's `offer`).
 */

const PARTNER_FLASH_DIR     = '/boot/config/plugins/unraid-secretary-office/partners';
const PARTNER_AUTH_KEYS     = '/boot/config/ssh/root/authorized_keys';
const PARTNER_HOST_KEY      = '/etc/ssh/ssh_host_ed25519_key.pub';
const PARTNER_DOOR          = '/usr/local/emhttp/plugins/unraid-secretary-office/scripts/partner-door.sh';
const PARTNER_IDENT         = '/boot/config/ident.cfg';
const PARTNER_PARENT        = 'UnraidSecretaryOffice-partners';     // <pool>/UnraidSecretaryOffice-partners/<id>/<unit>
const PARTNER_ID_RE         = '/^[0-9a-f]{8}$/D';
const PARTNER_NAME_RE       = '/^[A-Za-z0-9._-]{1,40}$/D';
const PARTNER_UNIT_RE       = '/^(?:(?:share|vm):[A-Za-z0-9][A-Za-z0-9._-]{0,63}|place)$/D';
const PARTNER_SNAP_PREFIX   = 'uso-backup-';
const PARTNER_SNAP_RE       = '/^uso-backup-\d{8}-\d{4}$/D';
const PARTNER_FP_RE         = '/^SHA256:[A-Za-z0-9+\/]{43}$/D';
const PARTNER_KEY_RE        = '/^ssh-ed25519 (AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+\/]{43})$/D';
const PARTNER_POOL_RE       = '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/D';
const PARTNER_WINDOW_RE     = '/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/D';
const PARTNER_RETENTION_RE  = '/^(\d{1,3}) (\d{1,3}) (\d{1,3})$/D';
const PARTNER_VERSION_RE    = '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D';
const PARTNER_TRUST         = ['mine', 'family', 'friend'];
const PARTNER_UNITS_MAX     = 64;
const PARTNER_QUOTA_MAX_GB  = 1000000;
const PARTNER_BLOCK_MAX     = 8192;
const PARTNER_SILENT_AFTER  = 6 * 3600;      // no answer for this long: partner_silent
const PARTNER_PING_EVERY    = 900;           // the mutual watch: every 15 minutes
const PARTNER_NOTIFY_AGAIN  = 86400;         // a silence is told once, then again every 24 h
const PARTNER_PENDING_KEEP  = 7 * 86400;     // an offer nobody answered goes after a week (and its key with it)
const PARTNER_SSH_TIMEOUT   = 30;            // seconds a ping or status may take at most (ssh's ConnectTimeout is 15)
const PARTNER_DEFAULTS      = ['quota_gb' => 0, 'retention' => '7 4 6', 'wake' => true];
const PARTNER_NEW_DAYS      = 7;             // a unit first received within so many days is «new» on the receiver's card
const PARTNER_STALE_DAYS    = 30;            // nothing received of a unit for so long: stale (the door's retention never touches it)
const PARTNER_MIGRATED_FILE = 'migrated.json';   // the units the pairs sent up to 1.59, for Mr. Backupsy's setup (the step partner-simple)
const PARTNER_RESCAN_FILE   = 'caretaker.rescan';  // in RUN_DIR: the door or the mutual watch asks the Team Lead for his cards anew
const PARTNER_RECORD_MAX    = 1024 * 1024;   // deletes.jsonl and door.log: rotated to .1 beyond this
// restore tickets (stage 3, plan §3.8 / concept §3.5): a temporary door line for a new server that pulls a gone server's copies
const PARTNER_TICKET_DAYS   = 7;             // a ticket's door line ends after a week (sshd's expiry-time and the door's own look)
const PARTNER_TICKET_ARG_RE = '/^ticket-([0-9a-f]{8})$/D';     // the door's argument on a ticket's line (command="… ticket-<id>")
const PARTNER_TICKET_VERBS  = ['ping', 'list', 'send-back', 'quota'];
const PARTNER_TICKET_TIDY   = 86400;         // expired ticket lines go at the agent's start and once a day
const PARTNER_TOKEN_RE      = '/^[0-9a-zA-Z-]{8,4000}$/D';     // a zfs receive_resume_token
// the client's options (plan §3.5) — the engine's sender uses the same
const PARTNER_SSH_OPTIONS   = ['-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                               '-o', 'Ciphers=aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com',
                               '-o', 'Compression=no', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=4'];

// ===================================================================== places (the tests point them elsewhere)

function partnerFlashDir(): string
{
    return rtrim(getenv('OFFICE_PARTNER_FLASH') ?: PARTNER_FLASH_DIR, '/');
}

function partnerAuthKeysFile(): string
{
    return getenv('OFFICE_PARTNER_AUTH_KEYS') ?: PARTNER_AUTH_KEYS;
}

function partnerHostKeyFile(): string
{
    return getenv('OFFICE_PARTNER_HOST_KEY') ?: PARTNER_HOST_KEY;
}

function partnerDir(): string
{
    return DATA_DIR . '/partner';
}

function partnerRunDir(): string
{
    return RUN_DIR . '/partner';
}

function partnerKeyFile(string $id): string
{
    return partnerFlashDir() . "/$id.key";
}

function partnerKnownFile(string $id): string
{
    return partnerFlashDir() . "/$id.known";
}

/** A program the office runs for partners: the real one — or, for the tests only, a stand-in from OFFICE_PARTNER_BIN */
function partnerBin(string $name): ?string
{
    $dir = getenv('OFFICE_PARTNER_BIN');
    if ($dir) {
        return is_executable("$dir/$name") ? "$dir/$name" : null;
    }
    if ($name === 'mbuffer') {
        return is_executable('/usr/bin/mbuffer') ? '/usr/bin/mbuffer' : null;
    }
    return bin($name);
}

/**
 * A folder of root's own, closed to others (data/partner, its received/, RUN_DIR/partner): made when missing, put
 * right when it isn't — never through a link. False: it can't be (then nothing is written there).
 */
function partnerDirReady(string $dir): bool
{
    clearstatcache(true, $dir);
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        return false;
    }
    if (!is_dir($dir)) {
        $old = umask(0077);
        $ok = @mkdir($dir, 0700, true);
        umask($old);
        if (!$ok && !is_dir($dir)) {
            return false;
        }
    }
    $st = @lstat($dir);
    if ($st && (($st['mode'] & 0077) || $st['uid'] !== 0)) {
        @lchown($dir, 0);
        @lchgrp($dir, 0);
        @chmod($dir, 0700);
        clearstatcache(true, $dir);
        $st = @lstat($dir);
    }
    return $st && ($st['mode'] & 0170000) === 0040000 && $st['uid'] === posix_geteuid() && !($st['mode'] & 0077);
}

/** A root-only JSON file of the partner folder: read only while it is a plain file of root's, closed to others */
function partnerReadPrivate(string $file): ?array
{
    clearstatcache(true, $file);
    $st = @lstat($file);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== posix_geteuid() || ($st['mode'] & 0077) || $st['nlink'] !== 1
        || $st['size'] > 1024 * 1024) {
        return null;
    }
    $dir = @lstat(dirname($file));
    if (!$dir || ($dir['mode'] & 0170000) !== 0040000 || $dir['uid'] !== posix_geteuid() || ($dir['mode'] & 0022)) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($file), true, 32);
    return is_array($j) ? $j : null;
}

function partnerWritePrivate(string $file, array $data): void
{
    if (!partnerDirReady(dirname($file))) {
        throw new Problem('partner_folder', ['path' => dirname($file)]);
    }
    writeAtomic($file, jsonEncode($data) . "\n", 0600, 0, 0);
}

// ===================================================================== shapes

function partnerUnitList(mixed $v, int $min = 0): ?array
{
    if (!is_array($v) || !array_is_list($v) || count($v) < $min || count($v) > PARTNER_UNITS_MAX) {
        return null;
    }
    foreach ($v as $u) {
        if (!is_string($u) || !preg_match(PARTNER_UNIT_RE, $u)) {
            return null;
        }
    }
    return count(array_unique($v)) === count($v) ? $v : null;
}

function partnerExact(mixed $v, array $keys): bool
{
    if (!is_array($v) || array_is_list($v) && $v !== []) {
        return false;
    }
    $have = array_keys($v);
    sort($have);
    sort($keys);
    return $have === $keys;
}

/**
 * The receiver's settings (what I keep of theirs) — in pairs.json and in BLOCK-B alike. Two shapes: {pool, quota_gb,
 * retention, wake} since 1.60 (the consent is the pairing: any unit, at any time), and the one up to 1.59 with the
 * receiving window and the units agreed (an older office's block or file; partnerPairUpgrade() strips both).
 */
function partnerReceiveValid(mixed $r): bool
{
    $old = partnerExact($r, ['pool', 'quota_gb', 'retention', 'window', 'wake', 'units']);
    if (!$old && !partnerExact($r, ['pool', 'quota_gb', 'retention', 'wake'])) {
        return false;
    }
    return is_string($r['pool']) && preg_match(PARTNER_POOL_RE, $r['pool'])
        && is_int($r['quota_gb']) && $r['quota_gb'] >= 0 && $r['quota_gb'] <= PARTNER_QUOTA_MAX_GB
        && is_string($r['retention']) && preg_match(PARTNER_RETENTION_RE, $r['retention'])
        && is_bool($r['wake'])
        && (!$old || is_string($r['window']) && preg_match(PARTNER_WINDOW_RE, $r['window']) && partnerUnitList($r['units'], 1) !== null);
}

/** One pair of pairs.json — exactly the shape the office writes (anything else: not a pair) */
function partnerPairValid(mixed $p): bool
{
    if (!partnerExact($p, ['id', 'name', 'address', 'port', 'host_keys', 'my_key', 'their_key', 'send', 'receive', 'trust', 'paired', 'last_heard', 'last_answer'])) {
        return false;
    }
    $fps = $p['host_keys'];
    if (!is_array($fps) || !array_is_list($fps) || !$fps || count($fps) > 4) {
        return false;
    }
    foreach ($fps as $fp) {
        if (!is_string($fp) || !preg_match(PARTNER_FP_RE, $fp)) {
            return false;
        }
    }
    $answer = $p['last_answer'];
    return is_string($p['id']) && preg_match(PARTNER_ID_RE, $p['id'])
        && is_string($p['name']) && preg_match(PARTNER_NAME_RE, $p['name'])
        && is_string($p['address']) && partnerAddressValid($p['address'])
        && is_int($p['port']) && $p['port'] >= 1 && $p['port'] <= 65535
        && ($p['my_key'] === null || is_string($p['my_key']) && preg_match(PARTNER_FP_RE, $p['my_key']))
        && ($p['their_key'] === null || is_string($p['their_key']) && preg_match(PARTNER_FP_RE, $p['their_key']))
        && partnerSendValid($p['send'], $p['my_key'] !== null)
        && ($p['receive'] === null ? $p['their_key'] === null : $p['their_key'] !== null && partnerReceiveValid($p['receive']))
        && in_array($p['trust'], PARTNER_TRUST, true)
        && is_int($p['paired']) && $p['paired'] > 0
        && ($p['last_heard'] === null || is_int($p['last_heard']) && $p['last_heard'] > 0)
        && ($answer === null || partnerExact($answer, ['array', 'night', 'v']) && in_array($answer['array'], ['started', 'stopped'], true)
            && is_bool($answer['night']) && is_string($answer['v']) && preg_match(PARTNER_VERSION_RE, $answer['v']));
}

/**
 * What I send (pairs.json send): since 1.60 only {rate_mbit} — what goes is the level in Mr. Backupsy's setup
 * (settings.ini), the partner's door takes any unit. The two older shapes are taken too — {units, rate_mbit} as written
 * before 2.29 and {units, rate_mbit, wanted, offered} up to 1.59 — because a pairs.json is read long before anything
 * writes it anew (the door reads it at every knock, the engine's jq); partnerPairUpgrade() strips them and every write
 * goes through it. The migration step `partner-simple` keeps the old units for Mr. Backupsy's setup (migrated.json).
 */
function partnerSendValid(mixed $s, bool $myKey): bool
{
    if (partnerExact($s, ['rate_mbit'])) {
        return is_int($s['rate_mbit']) && $s['rate_mbit'] >= 0 && $s['rate_mbit'] <= 100000;
    }
    $old = partnerExact($s, ['units', 'rate_mbit']);
    if (!$old && !partnerExact($s, ['units', 'rate_mbit', 'wanted', 'offered'])) {
        return false;
    }
    if (partnerUnitList($s['units']) === null || !is_int($s['rate_mbit']) || $s['rate_mbit'] < 0 || $s['rate_mbit'] > 100000
        || (!$myKey && $s['units'] !== [])) {                                   // nothing goes out without my key
        return false;
    }
    if ($old) {
        return true;
    }
    return partnerUnitList($s['wanted']) !== null && !array_diff($s['units'], $s['wanted'])
        && ($myKey || $s['wanted'] === [])
        && ($s['offered'] === null || is_int($s['offered']) && $s['offered'] > 0);
}

/** A pair of an older shape in the one of 1.60: send only its rate, receive without the window and the units agreed */
function partnerPairUpgrade(array $p): array
{
    if (is_array($p['send'] ?? null)) {
        $p['send'] = ['rate_mbit' => is_int($p['send']['rate_mbit'] ?? null) ? $p['send']['rate_mbit'] : 0];
    }
    if (is_array($p['receive'] ?? null)) {
        $p['receive'] = array_intersect_key($p['receive'], array_flip(['pool', 'quota_gb', 'retention', 'wake']));
    }
    return $p;
}

/** A pair still in a shape of up to 1.59 (the migration step reads the raw file, not partnerPairs()) */
function partnerPairOldShape(array $p): bool
{
    return is_array($p['send'] ?? null) && array_key_exists('units', $p['send'])
        || is_array($p['receive'] ?? null) && (array_key_exists('window', $p['receive']) || array_key_exists('units', $p['receive']));
}

/**
 * @return list<array> the pairs of pairs.json that are pairs — the rest left out: never used, but kept in the file
 *                     (partnerListWrite()); said in the agent's log once per process
 */
function partnerPairs(?string $file = null): array
{
    $j = partnerReadPrivate($file ?? partnerDir() . '/pairs.json');
    if ($j === null || ($j['v'] ?? null) !== 1 || !is_array($j['pairs'] ?? null)) {
        return [];
    }
    $out = $ids = [];
    foreach ($j['pairs'] as $p) {
        if (partnerPairValid($p) && !isset($ids[$p['id']])) {
            $ids[$p['id']] = true;
            $out[] = partnerPairUpgrade($p);
        } elseif (function_exists('logLine') && defined('AGENT_LOG') && !($GLOBALS['partnerPairsSaid'] ?? false)) {
            $GLOBALS['partnerPairsSaid'] = true;
            logLine('Partner offices: a pair in pairs.json isn\'t in this version\'s shape (a newer office\'s?) — not used, kept as it is');
        }
    }
    return $out;
}

function partnerPair(string $id, ?array $pairs = null): ?array
{
    foreach ($pairs ?? partnerPairs() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

function partnerPairsWrite(array $pairs, ?string $file = null): void
{
    foreach ($pairs as $i => $p) {
        if (!partnerPairValid($p)) {
            throw new Problem('partner_shape');
        }
        $pairs[$i] = partnerPairUpgrade($p);         // always written in the whole shape
    }
    partnerListWrite($file ?? partnerDir() . '/pairs.json', 'pairs', $pairs, 'partnerPairValid');
}

/**
 * Writes a list file of the partner folder — {"v":1, "<list>":[…]}: pairs.json, tickets.json, ticket-pairs.json — with
 * the entries the office manages ($items, each checked by the caller) and, where they stood, every entry of the file
 * as it is now that this version doesn't recognise ($valid false: e.g. a newer office's, read after a downgrade — remove
 * and install of an older .plg): carried through as they are, never used, never dropped (CLAUDE.md «Updates»). Keys of
 * the file beside v and the list are kept too. An entry this version does recognise is the office's: written as $items
 * has it (by its id), or gone when $items lacks it (ended, expired). A file of another `v` isn't this version's to
 * write: refused (partner_shape), left as it is. A file that can't be read (missing, not root's, broken) is written anew.
 */
function partnerListWrite(string $file, string $list, array $items, callable $valid): void
{
    $raw = partnerReadPrivate($file);
    if ($raw !== null && array_key_exists('v', $raw) && $raw['v'] !== 1) {
        throw new Problem('partner_shape');
    }
    $doc = $raw !== null && !array_is_list($raw) ? $raw : [];
    $byId = [];
    foreach (array_values($items) as $it) {
        $byId[$it['id']] = $it;
    }
    $out = [];
    $old = $doc[$list] ?? null;
    foreach (is_array($old) && array_is_list($old) ? $old : [] as $e) {
        if (!$valid($e)) {
            $out[] = $e;                                // not this version's: kept as it is
        } elseif (isset($byId[$e['id']])) {
            $out[] = $byId[$e['id']];                   // the office's: as it is now, where it was
            unset($byId[$e['id']]);
        }
    }
    array_push($out, ...array_values($byId));          // new ones at the end
    unset($doc['v'], $doc[$list]);
    partnerWritePrivate($file, ['v' => 1, $list => $out] + $doc);
}

/**
 * One pair changed in pairs.json — read, changed and written under a lock in RAM (the Team Lead's actions and the mutual
 * watch's job run in processes of their own). $change gets the pair as it stands and returns it changed (or null: no
 * change). Returns the pair as written (as it stands when nothing changed), null when there is no such pair.
 */
function partnerPairUpdate(string $id, callable $change): ?array
{
    $lock = partnerDirReady(partnerRunDir()) ? @fopen(partnerRunDir() . '/pairs.lock', 'c') : false;
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $pairs = partnerPairs();
        foreach ($pairs as $i => $p) {
            if ($p['id'] !== $id) {
                continue;
            }
            $new = $change($p);
            if (is_array($new) && $new !== $p) {
                $pairs[$i] = $new;
                partnerPairsWrite($pairs);
                return $new;
            }
            return $p;
        }
        return null;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** $a ⊆ $b (unit lists) */
function partnerSubset(array $a, array $b): bool
{
    return !array_diff($a, $b);
}

// ===================================================================== addresses

/** An address a partner can be reached at: IPv4, IPv6 (no link-local, no zone) or a host name */
function partnerAddressValid(string $a): bool
{
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return !str_starts_with($a, '0.') && $a !== '255.255.255.255';
    }
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($a);
        return $bin !== false && !(ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) && $bin !== str_repeat("\0", 16);
    }
    return strlen($a) <= 253 && preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D', $a) === 1;
}

/**
 * Of the private kinds (plan §3.8): RFC 1918, 100.64/10 (Tailscale, CGNAT), loopback, fd00::/8, ::1, a plain host name,
 * *.ts.net, *.local. Anything else faces the internet — a warning («use a tunnel»), never a refusal.
 */
function partnerAddressPrivate(string $a): bool
{
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($a);
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10], ['127.0.0.0', 8]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits) & 0xffffffff;
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return true;
            }
        }
        return false;
    }
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = (string) inet_pton($a);
        return ord($bin[0]) === 0xfd || $bin === str_repeat("\0", 15) . "\1";
    }
    $l = strtolower($a);
    return !str_contains($l, '.') || str_ends_with($l, '.ts.net') || str_ends_with($l, '.local');
}

/**
 * What goes into from="…" for a partner's address: sshd compares it with the client's IP (Unraid's sshd doesn't look up
 * names), so a host name is resolved now. Null: no address for it.
 */
function partnerFromList(string $address): ?string
{
    if (filter_var($address, FILTER_VALIDATE_IP)) {
        return $address;
    }
    $ips = @gethostbynamel($address) ?: [];
    $ips = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    return $ips ? implode(',', array_slice(array_unique($ips), 0, 8)) : null;
}

/** This server's own addresses a partner could reach it at (no Docker, VM or container bridges) */
function partnerMyAddresses(): array
{
    $out = $seen = [];
    [$exit, $text] = run(['ip', '-o', 'addr', 'show', 'scope', 'global'], 10);
    if ($exit !== 0) {
        return [];
    }
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\d+:\s+(\S+)\s+inet6?\s+([0-9a-fA-F.:]+)\//', $line, $m)) {
            continue;
        }
        $iface = $m[1];
        $ip = $m[2];
        if (preg_match('/^(docker\d*|virbr\d*|shim-|veth|br-|vhost|lo$)/', $iface) || isset($seen[$ip]) || !partnerAddressValid($ip)) {
            continue;
        }
        $seen[$ip] = true;
        $out[] = ['address' => $ip, 'iface' => $iface, 'private' => partnerAddressPrivate($ip)];
    }
    return $out;
}

/** The port this server's sshd listens on (Unraid's ⟦Management Access⟧, ident.cfg PORTSSH) */
function partnerMyPort(): int
{
    $port = (int) (readCfg(PARTNER_IDENT)['PORTSSH'] ?? 22);
    return $port >= 1 && $port <= 65535 ? $port : 22;
}

/** This office's name for its partners: the host name, in the blocks' characters */
function partnerMyName(): string
{
    $name = substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', hostname()), 0, 40);
    return preg_match(PARTNER_NAME_RE, $name) ? $name : 'Unraid';
}

// ===================================================================== keys

/** "ssh-ed25519 AAAA… [comment]" → "ssh-ed25519 AAAA…" when it is a well-formed ed25519 public key, else null */
function partnerKeyNorm(mixed $line): ?string
{
    if (!is_string($line) || !preg_match('/^(ssh-ed25519 [A-Za-z0-9+\/]+)(?: [\x21-\x7e]{1,100})?$/D', trim($line), $m)
        || !preg_match(PARTNER_KEY_RE, $m[1], $k)) {
        return null;
    }
    $blob = base64_decode($k[1], true);
    return $blob !== false && strlen($blob) === 51 && substr($blob, 0, 19) === "\0\0\0\x0bssh-ed25519\0\0\0\x20" ? $m[1] : null;
}

/** SHA256:… as ssh-keygen -l shows it */
function partnerFingerprint(string $key): ?string
{
    $norm = partnerKeyNorm($key);
    if ($norm === null) {
        return null;
    }
    return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode(substr($norm, 12), true), true)), '=');
}

/** @return list<string> this server's sshd host key (ed25519) — what partners pin */
function partnerHostKeys(): array
{
    $key = partnerKeyNorm(trim((string) @file_get_contents(partnerHostKeyFile(), false, null, 0, 4096)));
    return $key !== null ? [$key] : [];
}

/** A new key pair for a pair: the private key on the flash (0600), the public one returned (and beside it, .pub) */
function partnerKeyMake(string $id): string
{
    if (!preg_match(PARTNER_ID_RE, $id)) {
        throw new Problem('partner_shape');
    }
    $dir = partnerFlashDir();
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
        throw new Problem('partner_folder', ['path' => $dir]);
    }
    @chmod($dir, 0700);
    $file = partnerKeyFile($id);
    foreach ([$file, "$file.pub"] as $f) {
        if (file_exists($f) || is_link($f)) {
            @unlink($f);
        }
    }
    $keygen = partnerBin('ssh-keygen');
    if ($keygen === null) {
        throw new Problem('partner_keygen', ['detail' => 'ssh-keygen is missing']);
    }
    [$exit, , $err] = run([$keygen, '-q', '-t', 'ed25519', '-N', '', '-C', "uso-partner:$id", '-f', $file], 30);
    $pub = partnerKeyNorm(trim((string) @file_get_contents("$file.pub")));
    if ($exit !== 0 || $pub === null || !is_file($file)) {
        partnerKeyDrop($id);
        throw new Problem('partner_keygen', ['detail' => trim($err)]);
    }
    @chmod($file, 0600);
    return $pub;
}

/** My public key of a pair (from the .pub beside the key), or null */
function partnerKeyPublic(string $id): ?string
{
    return preg_match(PARTNER_ID_RE, $id) ? partnerKeyNorm(trim((string) @file_get_contents(partnerKeyFile($id) . '.pub', false, null, 0, 4096))) : null;
}

/** A pair's key, its public half and its known_hosts off the flash */
function partnerKeyDrop(string $id): void
{
    if (!preg_match(PARTNER_ID_RE, $id)) {
        return;
    }
    foreach ([partnerKeyFile($id), partnerKeyFile($id) . '.pub', partnerKnownFile($id)] as $f) {
        if (file_exists($f) || is_link($f)) {
            @unlink($f);
        }
    }
}

/**
 * The pair's known_hosts: the partner's full host keys under the name ssh looks up — the address for port 22,
 * «[address]:port» for any other (pinned keys, not only fingerprints).
 */
function partnerKnownText(string $address, int $port, array $hostKeys): string
{
    $name = $port === 22 ? $address : "[$address]:$port";
    $out = '';
    foreach ($hostKeys as $k) {
        $norm = partnerKeyNorm($k);
        if ($norm !== null) {
            $out .= "$name $norm\n";
        }
    }
    return $out;
}

function partnerKnownWrite(string $id, string $address, int $port, array $hostKeys): void
{
    $text = partnerKnownText($address, $port, $hostKeys);
    $dir = partnerFlashDir();
    if ($text === '' || !preg_match(PARTNER_ID_RE, $id) || is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) {
        throw new Problem('partner_folder', ['path' => $dir]);
    }
    writeAtomic(partnerKnownFile($id), $text, 0600, 0, 0);
}

// ===================================================================== the door's line in authorized_keys

function partnerDoorLine(string $id, string $from, string $pubKey): string
{
    return 'restrict,from="' . $from . '",command="' . PARTNER_DOOR . ' ' . $id . '" ' . $pubKey . ' uso-partner:' . $id;
}

/** Is this line of authorized_keys the office's for $id (null: for any pair)? By its comment only. */
function partnerLineIsOurs(string $line, ?string $id = null): bool
{
    $mark = $id === null ? '[0-9a-f]{8}' : preg_quote($id, '/');
    return preg_match('/(?:^|[ \t])uso-partner:' . $mark . '\r?\n?$/D', $line) === 1;
}

/** Is this line a restore ticket's (comment uso-ticket:<id>; null: any ticket)? By its comment only. */
function partnerTicketLineIsOurs(string $line, ?string $id = null): bool
{
    $mark = $id === null ? '[0-9a-f]{8}' : preg_quote($id, '/');
    return preg_match('/(?:^|[ \t])uso-ticket:' . $mark . '\r?\n?$/D', $line) === 1;
}

/**
 * Lines of the office's taken out of an authorized_keys text, the rest byte for byte: $id null — every line of the
 * office's (pairs and tickets: the .plg's remove); else that pair's line ($ticket: that ticket's).
 */
function partnerAuthKeysStrip(string $text, ?string $id = null, bool $ticket = false): string
{
    $out = '';
    foreach (preg_split('/(?<=\n)/', $text) ?: [] as $chunk) {
        $ours = $id === null ? partnerLineIsOurs($chunk) || partnerTicketLineIsOurs($chunk) : ($ticket ? partnerTicketLineIsOurs($chunk, $id) : partnerLineIsOurs($chunk, $id));
        if ($chunk !== '' && !$ours) {
            $out .= $chunk;
        }
    }
    return $out;
}

/**
 * Unraid's authorized_keys with the pair's line set ($line) or taken out (null): only lines marked uso-partner:<id>
 * are touched, every other byte stays; a new file + rename (writeAtomic()), never through a link — a link where the
 * file or its folder belongs is refused. True when something changed.
 */
function partnerAuthKeysEdit(string $id, ?string $line, ?string $file = null, bool $ticket = false): bool
{
    $file ??= partnerAuthKeysFile();
    if (!preg_match(PARTNER_ID_RE, $id) || ($line !== null && (str_contains($line, "\n") || str_contains($line, "\r")
        || !($ticket ? partnerTicketLineIsOurs($line, $id) : partnerLineIsOurs($line, $id))))) {
        throw new Problem('partner_shape');
    }
    clearstatcache();
    $dir = dirname($file);
    if (is_link($dir) || !is_dir($dir) || is_link($file) || (file_exists($file) && !is_file($file))) {
        throw new Problem('partner_authkeys_link', ['path' => $file]);
    }
    $old = file_exists($file) ? (string) @file_get_contents($file, false, null, 0, 1024 * 1024) : '';
    $new = partnerAuthKeysStrip($old, $id, $ticket);
    if ($line !== null) {
        $new .= ($new !== '' && !str_ends_with($new, "\n") ? "\n" : '') . $line . "\n";
    }
    if ($new === $old) {
        return false;
    }
    writeAtomic($file, $new, 0600, 0, 0);
    return true;
}

/** The pair's line in authorized_keys as it stands ($ticket: the ticket's; null: none) */
function partnerAuthKeysLine(string $id, ?string $file = null, bool $ticket = false): ?string
{
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($file ?? partnerAuthKeysFile(), false, null, 0, 1024 * 1024)) ?: [] as $l) {
        if ($l !== '' && ($ticket ? partnerTicketLineIsOurs($l, $id) : partnerLineIsOurs($l, $id))) {
            return $l;
        }
    }
    return null;
}

// ===================================================================== the two blocks and the safety code

/** A block to copy: base64 of its JSON, on one line */
function partnerBlockEncode(array $fields): string
{
    return base64_encode(jsonEncode($fields));
}

/**
 * BLOCK-A ({v, block:"A", id, name, address, port, host_keys, pub_key, trust, units}) or BLOCK-B ({v, block:"B", id,
 * name, address, port, host_keys, pub_key|null, receive|null, units}) as pasted — field by field; any refusal is a
 * Problem naming the field. host_keys and pub_key: the full «ssh-ed25519 AAAA…» lines.
 */
function partnerBlockDecode(mixed $text, string $kind): array
{
    $bad = fn (string $field) => new Problem('partner_block', ['field' => $field]);
    if (!is_string($text)) {
        throw $bad('block');
    }
    $text = (string) preg_replace('/\s+/', '', $text);
    if ($text === '' || strlen($text) > PARTNER_BLOCK_MAX) {
        throw $bad('block');
    }
    $raw = base64_decode($text, true);
    $b = $raw === false ? null : json_decode($raw, true, 6);
    if (!is_array($b) || array_is_list($b)) {
        throw $bad('block');
    }
    $keys = $kind === 'A' ? ['v', 'block', 'id', 'name', 'address', 'port', 'host_keys', 'pub_key', 'trust', 'units']
                          : ['v', 'block', 'id', 'name', 'address', 'port', 'host_keys', 'pub_key', 'receive', 'units'];
    if (($b['block'] ?? null) !== $kind) {
        throw $bad('kind');
    }
    if (!partnerExact($b, $keys)) {
        throw $bad('fields');
    }
    if ($b['v'] !== 1) {
        throw $bad('v');
    }
    if (!is_string($b['id']) || !preg_match(PARTNER_ID_RE, $b['id'])) {
        throw $bad('id');
    }
    if (!is_string($b['name']) || !preg_match(PARTNER_NAME_RE, $b['name'])) {
        throw $bad('name');
    }
    if (!is_string($b['address']) || !partnerAddressValid($b['address'])) {
        throw $bad('address');
    }
    if (!is_int($b['port']) || $b['port'] < 1 || $b['port'] > 65535) {
        throw $bad('port');
    }
    if (!is_array($b['host_keys']) || !array_is_list($b['host_keys']) || !$b['host_keys'] || count($b['host_keys']) > 4) {
        throw $bad('host_keys');
    }
    foreach ($b['host_keys'] as $i => $k) {
        $norm = partnerKeyNorm($k);
        if ($norm === null || $norm !== $k) {
            throw $bad('host_keys');
        }
    }
    if ($kind === 'A' || $b['pub_key'] !== null) {
        if (partnerKeyNorm($b['pub_key']) === null || partnerKeyNorm($b['pub_key']) !== $b['pub_key']) {
            throw $bad('pub_key');
        }
        if (in_array($b['pub_key'], $b['host_keys'], true)) {
            throw $bad('pub_key');          // a host key is no pair key
        }
    }
    if (partnerUnitList($b['units']) === null) {
        throw $bad('units');
    }
    if ($kind === 'A') {
        if (!in_array($b['trust'], PARTNER_TRUST, true)) {
            throw $bad('trust');
        }
    } else {
        if ($b['receive'] !== null && !partnerReceiveValid($b['receive'])) {
            throw $bad('receive');
        }
        if ($b['pub_key'] === null && $b['units'] !== []) {
            throw $bad('units');            // B names units it would send only with a key (since 1.60 a key may come with none: the levels decide)
        }
    }
    return $b;
}

/**
 * The SAFETY CODE both pages show (plan §3.8): sha256 of A's pair key, B's (empty when B sends nothing), A's host keys
 * and B's, one per line — the decimal of its first 4 bytes mod 1 000 000, six digits.
 */
function partnerSafetyCode(string $aPub, ?string $bPub, array $aHost, array $bHost): string
{
    $hash = hash('sha256', implode("\n", [$aPub, $bPub ?? '', implode(',', $aHost), implode(',', $bHost)]), true);
    return sprintf('%06d', unpack('N', substr($hash, 0, 4))[1] % 1000000);
}

// ===================================================================== what can be sent, where it can be kept

/**
 * The units this server could send (plan §3.2), from the engine's plan (state/setup-plan.json): shares that are a
 * dataset of their own on a ZFS pool, VMs with a dataset of their own, the backup place's dataset — each with ok and why
 * (not_dataset, name, asleep). Awake pools only (a sleeping pool's datasets are not looked at: «asleep»).
 *
 * @return list<array{id:string, label:string, dataset:?string, ok:bool, why:?string}>
 */
function partnerUnits(): array
{
    $plan = readJson(BACKUP_DATA_DIR . '/state/setup-plan.json');
    if (!is_array($plan)) {
        return [];
    }
    $zpools = [];
    foreach ((array) ($plan['bases'] ?? []) as $b) {
        if (is_array($b) && ($b['fs'] ?? '') === 'zfs' && is_string($b['name'] ?? null)) {
            $zpools[$b['name']] = true;
        }
    }
    $sleep = poolsBySleep(array_keys($zpools));
    $datasets = [];
    if ($sleep['awake'] && ($zfs = partnerBin('zfs')) !== null) {
        [$exit, $out] = run(array_merge([$zfs, 'list', '-H', '-o', 'name', '-t', 'filesystem', '-r'], $sleep['awake']), 60);
        if ($exit === 0) {
            foreach (explode("\n", trim($out)) as $n) {
                $datasets[$n] = true;
            }
        }
    }
    $place = (string) (readCfg(BACKUP_DATA_DIR . '/settings.ini', true)['general']['dumps_share'] ?? '');
    $look = function (string $id, string $label, ?string $ds, ?string $pool) use ($datasets, $sleep): array {
        $why = null;
        if (!preg_match(PARTNER_UNIT_RE, $id)) {
            $why = 'name';
        } elseif ($pool !== null && in_array($pool, $sleep['asleep'], true)) {
            $why = 'asleep';
        } elseif ($ds === null || !isset($datasets[$ds])) {
            $why = 'not_dataset';
        }
        return ['id' => $id, 'label' => $label, 'dataset' => $why === null ? $ds : null, 'ok' => $why === null, 'why' => $why];
    };
    $out = [];
    foreach ((array) ($plan['shares'] ?? []) as $s) {
        $name = is_array($s) ? (string) ($s['name'] ?? '') : '';
        if ($name === '') {
            continue;
        }
        $where = (string) ($s['locations'] ?? '');
        $pool = ($s['layout'] ?? '') === 'single' && isset($zpools[$where]) ? $where : null;
        $unit = $name === $place ? 'place' : "share:$name";
        $out[] = $look($unit, $name, $pool !== null ? "$pool/$name" : null, $pool);
    }
    foreach ((array) ($plan['vms'] ?? []) as $v) {
        $name = is_array($v) ? (string) ($v['name'] ?? '') : '';
        if ($name === '') {
            continue;
        }
        $own = array_values(array_filter((array) ($v['own'] ?? []), 'is_string'));
        $ds = count($own) === 1 ? $own[0] : null;
        $out[] = $look("vm:$name", $name, $ds, $ds !== null ? explode('/', $ds)[0] : null);
    }
    usort($out, fn ($a, $b) => [$a['id'] !== 'place', $a['id']] <=> [$b['id'] !== 'place', $b['id']]);
    return $out;
}

/** The pool the flash (or a boot pool) is on — never a place for a partner's copies */
function partnerBootPool(): ?string
{
    foreach (function_exists('mountTable') ? mountTable() : [] as $m) {
        if ($m['mount'] === '/boot' && $m['fs'] === 'zfs') {
            return explode('/', $m['source'])[0];
        }
    }
    return null;
}

/**
 * ZFS pools a partner's copies could go to: name, free bytes (awake ones), asleep.
 *
 * @return list<array{name:string, free:?int, asleep:bool}>
 */
function partnerPools(): array
{
    $zpool = partnerBin('zpool');
    $zfs = partnerBin('zfs');
    if ($zpool === null || $zfs === null) {
        return [];
    }
    [$exit, $out] = run([$zpool, 'list', '-H', '-o', 'name'], 20);
    if ($exit !== 0) {
        return [];
    }
    $boot = partnerBootPool();
    $names = array_values(array_filter(explode("\n", trim($out)), fn ($n) => $n !== '' && $n !== $boot && preg_match(PARTNER_POOL_RE, $n)));
    $sleep = poolsBySleep($names);
    $free = [];
    if ($sleep['awake']) {
        [$exit, $out] = run(array_merge([$zfs, 'list', '-H', '-p', '-o', 'name,avail'], $sleep['awake']), 20);
        foreach ($exit === 0 ? rows($out) : [] as $r) {
            $free[$r[0]] = num($r[1] ?? '');
        }
    }
    return array_map(fn ($n) => ['name' => $n, 'free' => $free[$n] ?? null, 'asleep' => in_array($n, $sleep['asleep'], true)], $names);
}

/** The receiver's dataset of a unit: <pool>/UnraidSecretaryOffice-partners/<id>/<unit, ':' → '-'> */
function partnerUnitDataset(string $pool, string $id, string $unit): string
{
    return "$pool/" . PARTNER_PARENT . "/$id/" . str_replace(':', '-', $unit);
}

// ===================================================================== retention

/**
 * The receiver's retention on one dataset (the engine's zfs_prune_select(), lib/common.sh): of the snapshots
 * named exactly uso-backup-YYYYMMDD-HHMM, keep the newest d, the newest of each of the last w weeks and of the last m
 * months; the rest may go — never the newest, never one with a hold. $snaps: name => holds (userrefs).
 *
 * @param array<string, int> $snaps
 * @return list<string>  the names to destroy, oldest first
 */
function partnerRetentionSelect(array $snaps, string $retention): array
{
    if (!preg_match(PARTNER_RETENTION_RE, $retention, $m)) {
        return [];
    }
    [, $d, $w, $mo] = array_map('intval', $m);
    $names = array_values(array_filter(array_keys($snaps), fn ($n) => is_string($n) && backupIsEngineSnap($n, [PARTNER_SNAP_PREFIX])));
    sort($names);                       // the time is in the name: lexical is chronological
    if (!$names) {
        return [];
    }
    $keep = [];
    $newest = array_reverse($names);
    foreach (array_slice($newest, 0, $d) as $n) {
        $keep[$n] = true;
    }
    $weeks = $months = [];
    foreach ($newest as $n) {
        $stamp = substr($n, -13);
        $day = mktime(12, 0, 0, (int) substr($stamp, 4, 2), (int) substr($stamp, 6, 2), (int) substr($stamp, 0, 4));
        $week = date('o-W', $day);
        $month = substr($stamp, 0, 6);
        if ($w > 0 && !isset($weeks[$week]) && count($weeks) < $w) {
            $weeks[$week] = true;
            $keep[$n] = true;
        }
        if ($mo > 0 && !isset($months[$month]) && count($months) < $mo) {
            $months[$month] = true;
            $keep[$n] = true;
        }
    }
    $keep[$newest[0]] = true;           // never the newest: the sender's next incremental starts from it
    return array_values(array_filter($names, fn ($n) => !isset($keep[$n]) && (int) $snaps[$n] === 0));
}

/**
 * One line appended to a root-only record of the partner folder (deletes.jsonl, door.log) under a lock; rotated to
 * .1 beyond PARTNER_RECORD_MAX; never through a link (its folder is root's own).
 */
function partnerAppend(string $file, string $line): bool
{
    if (!partnerDirReady(dirname($file)) || is_link($file)) {
        return false;
    }
    $old = umask(0077);
    $h = @fopen($file, 'a');
    umask($old);
    if (!$h) {
        return false;
    }
    flock($h, LOCK_EX);
    $st = fstat($h);
    if ($st && ($st['mode'] & 0170000) !== 0100000) {
        fclose($h);
        return false;
    }
    if ($st && $st['size'] + strlen($line) > PARTNER_RECORD_MAX && @rename($file, "$file.1")) {
        flock($h, LOCK_UN);
        fclose($h);
        $old = umask(0077);
        $h = @fopen($file, 'a');
        umask($old);
        if (!$h) {
            return false;
        }
        flock($h, LOCK_EX);
    }
    $ok = fwrite($h, $line) === strlen($line);
    flock($h, LOCK_UN);
    fclose($h);
    return $ok;
}

// ===================================================================== the client: ping, status

/** The ssh command of a pair's call (plan §3.5): its key, its known_hosts, the office's options */
function partnerSshArgs(array $pair, string $remote): array
{
    $ssh = partnerBin('ssh') ?? 'ssh';
    return array_merge([$ssh, '-i', partnerKeyFile($pair['id']), '-o', 'UserKnownHostsFile=' . partnerKnownFile($pair['id'])],
        PARTNER_SSH_OPTIONS, ['-p', (string) $pair['port'], 'root@' . $pair['address'], $remote]);
}

/**
 * A call through the partner's door: $verb and $args are words of the door's language (validated here too).
 *
 * @return array{0:int, 1:string, 2:string}  exit, stdout, stderr
 */
function partnerSsh(array $pair, string $verb, array $args = [], int $timeout = PARTNER_SSH_TIMEOUT): array
{
    $words = array_merge([$verb], $args);
    foreach ($words as $w) {
        if (!is_string($w) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,80}$/D', $w) && $w !== '-t') {
            return [2, '', 'bad word'];
        }
    }
    if (!partnerPairValid($pair) || $pair['my_key'] === null || !is_file(partnerKeyFile($pair['id'])) || !is_file(partnerKnownFile($pair['id']))) {
        return [2, '', 'no key for this pair'];
    }
    return run(partnerSshArgs($pair, implode(' ', $words)), $timeout);
}

/** The first line of an answer that is a JSON object */
function partnerJsonLine(string $text): ?array
{
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') {
            $j = json_decode($line, true, 8);
            return is_array($j) ? $j : null;
        }
    }
    return null;
}

/**
 * A partner's status as far as the office shows it: the expected fields only, cleaned. Its units are what it keeps of
 * mine (also one I no longer send); `agreed` its receive.units (what it agreed to keep — null from an office before
 * 2.29), `wish` the units of my `offer` its Team Lead hasn't decided on yet.
 */
function partnerStatusClean(mixed $s): ?array
{
    if (!is_array($s) || ($s['ok'] ?? null) !== true) {
        return null;
    }
    $int = fn ($v) => is_int($v) && $v >= 0 ? $v : null;
    $out = [
        'name'    => is_string($s['name'] ?? null) && preg_match(PARTNER_NAME_RE, $s['name']) ? $s['name'] : null,
        'array'   => in_array($s['array'] ?? null, ['started', 'stopped'], true) ? $s['array'] : null,
        'version' => is_string($s['version'] ?? null) && preg_match(PARTNER_VERSION_RE, $s['version']) ? $s['version'] : null,
        'lead'    => ['must' => $int($s['lead']['must'] ?? null), 'recommended' => $int($s['lead']['recommended'] ?? null)],
        'last_run' => null,
        'pool'    => ['free_bytes' => $int($s['pool']['free_bytes'] ?? null), 'asleep' => ($s['pool']['asleep'] ?? false) === true],
        'quota'   => ['bytes' => $int($s['quota']['bytes'] ?? null), 'used_bytes' => $int($s['quota']['used_bytes'] ?? null)],
        'units'   => [],
        'agreed'  => partnerUnitList($s['agreed'] ?? null),
        'wish'    => partnerUnitList($s['wish'] ?? null),
        'takes_all' => ($s['takes_all'] ?? null) === true,      // a door since 1.60: any unit of mine up to its quota (an older one keeps `agreed` only)
    ];
    $run = $s['last_run'] ?? null;
    if (is_array($run) && is_string($run['run'] ?? null) && preg_match('/^\d{8}-\d{4}$/D', $run['run'])) {
        $out['last_run'] = ['run' => $run['run'], 'result' => is_string($run['result'] ?? null) && preg_match('/^[a-z_]{1,24}$/D', $run['result']) ? $run['result'] : null,
                            'time' => $int($run['time'] ?? null)];
    }
    foreach ((array) ($s['units'] ?? []) as $u => $info) {
        if (!is_string($u) || !preg_match(PARTNER_UNIT_RE, $u) || !is_array($info) || count($out['units']) >= PARTNER_UNITS_MAX) {
            continue;
        }
        $snaps = array_values(array_filter((array) ($info['snaps'] ?? []), fn ($n) => is_string($n) && preg_match(PARTNER_SNAP_RE, $n)));
        $out['units'][$u] = ['snaps' => count($snaps), 'newest' => $snaps ? max($snaps) : null, 'used_bytes' => $int($info['used_bytes'] ?? null)];
    }
    return $out;
}

/**
 * Asks a partner how it is: ping, then status while its array runs. Null when this office has no key there (it only
 * receives: it hears of the partner when the partner knocks — heard-<id> in RAM).
 *
 * @return array{time:int, reachable:bool, answer:?array, status:?array, why:?string}|null
 */
function partnerAsk(array $pair): ?array
{
    if ($pair['my_key'] === null) {
        return null;
    }
    $now = time();
    [$exit, $out, $err] = partnerSsh($pair, 'ping');
    $j = partnerJsonLine($out);
    $ok = $exit === 0 && is_array($j) && ($j['ok'] ?? null) === true && ($j['pair'] ?? null) === $pair['id']
        && in_array($j['array'] ?? null, ['started', 'stopped'], true) && is_bool($j['night'] ?? null)
        && is_string($j['v'] ?? null) && preg_match(PARTNER_VERSION_RE, $j['v']);
    if (!$ok) {
        $why = $exit === 255 ? (preg_match('/Host key verification failed|REMOTE HOST IDENTIFICATION/i', $err) ? 'host_key'
            : (preg_match('/Permission denied/i', $err) ? 'denied' : 'unreachable')) : ($exit === 124 ? 'timeout' : 'bad_answer');
        return ['time' => $now, 'reachable' => false, 'answer' => null, 'status' => null, 'why' => $why];
    }
    $answer = ['array' => $j['array'], 'night' => $j['night'], 'v' => $j['v'], 'time' => is_int($j['time'] ?? null) ? $j['time'] : null];
    $status = null;
    if ($j['array'] === 'started') {
        [$exit, $out] = partnerSsh($pair, 'status');
        $status = $exit === 0 ? partnerStatusClean(partnerJsonLine($out)) : null;
    }
    return ['time' => $now, 'reachable' => true, 'answer' => $answer, 'status' => $status, 'why' => null];
}

// ===================================================================== the mutual watch

function partnerStateRead(): array
{
    $j = partnerReadPrivate(partnerDir() . '/state.json');
    return is_array($j['pairs'] ?? null) ? $j : ['pairs' => []];
}

/** When the partner knocked at my door last (the door touches RUN_DIR/partner/heard-<id>, RAM) */
function partnerHeardAtDoor(string $id): ?int
{
    clearstatcache(true, partnerRunDir() . "/heard-$id");
    $t = @filemtime(partnerRunDir() . "/heard-$id");
    return $t ?: null;
}

/** The last time a partner was heard of — its answer to my ping, or its knock at my door */
function partnerLastHeard(array $pair, array $entry): ?int
{
    $t = max((int) ($pair['last_heard'] ?? 0), (int) ($entry['last_heard'] ?? 0), (int) partnerHeardAtDoor($pair['id']));
    return $t > 0 ? $t : null;
}

/** Silent: nothing heard for PARTNER_SILENT_AFTER (since the pairing when never) */
function partnerSilent(array $pair, array $entry, int $now): bool
{
    return $now - (partnerLastHeard($pair, $entry) ?? $pair['paired']) > PARTNER_SILENT_AFTER;
}

/** A pair's entry of state.json after one look (partnerAsk()) */
function partnerStateEntry(array $old, ?array $ask, array $pair): array
{
    $entry = [
        'last_heard' => isset($old['last_heard']) && is_int($old['last_heard']) ? $old['last_heard'] : null,
        'last_try'   => isset($old['last_try']) && is_int($old['last_try']) ? $old['last_try'] : null,
        'reachable'  => isset($old['reachable']) && is_bool($old['reachable']) ? $old['reachable'] : null,
        'why'        => isset($old['why']) && is_string($old['why']) ? $old['why'] : null,
        'answer'     => isset($old['answer']) && is_array($old['answer']) ? $old['answer'] : null,
        'status'     => isset($old['status']) && is_array($old['status']) ? $old['status'] : null,
        'told'       => isset($old['told']) && is_array($old['told']) ? $old['told'] : null,
        'tailnet'    => isset($old['tailnet']) && is_bool($old['tailnet']) ? $old['tailnet'] : null,
        'status_time' => isset($old['status_time']) && is_int($old['status_time']) ? $old['status_time'] : null,
        'offer'      => isset($old['offer']) && is_array($old['offer']) ? $old['offer'] : null,      // the last `offer` (partnerOfferKeep())
    ];
    if ($ask !== null) {
        $entry['last_try'] = $ask['time'];
        $entry['reachable'] = $ask['reachable'];
        $entry['why'] = $ask['why'];
        if ($ask['reachable']) {
            $entry['last_heard'] = $ask['time'];
            $entry['answer'] = $ask['answer'];
            if ($ask['status'] !== null) {
                $entry['status'] = $ask['status'];
                $entry['status_time'] = $ask['time'];
            }
        }
    }
    $door = partnerHeardAtDoor($pair['id']);
    if ($door !== null && $door > (int) $entry['last_heard']) {
        $entry['last_heard'] = $door;
    }
    return $entry;
}

/** The finding of a silent partner (the Team Lead's partner_silent) — its params stay the same within one silence */
function partnerSilentParams(array $pair, array $entry): array
{
    $since = partnerLastHeard($pair, $entry);
    return ['name' => $pair['name'], 'pair' => $pair['id'], 'since' => date('Y-m-d H:i', $since ?? $pair['paired']), 'heard' => $since !== null ? 'yes' : 'no'];
}

/**
 * The mutual watch (php agent.php job partner-ping, every 15 minutes from the Team Lead's tick, one at a time): each
 * pair asked (ping, then status), the answers into state.json; a silent partner told to Unraid's notifications once
 * per silence and again every 24 h — not when the user said «I know, thanks» to it ($muted: sig → true).
 *
 * @param callable(array $pair, array $entry): bool $muted
 * @param callable(array $pair, array $entry): bool $tell  sends the notification
 */
function partnerWatch(callable $muted, callable $tell, ?int $now = null): array
{
    $now ??= time();
    $pairs = partnerPairs();
    $state = partnerStateRead();
    $next = ['pairs' => []];
    $tailnet = false;           // asked once, only when someone is silent
    foreach ($pairs as $p) {
        $old = is_array($state['pairs'][$p['id']] ?? null) ? $state['pairs'][$p['id']] : [];
        $ask = partnerAsk($p);
        $entry = partnerStateEntry($old, $ask, $p);
        [$p, $entry] = partnerUnitsFollow($p, $ask, $entry);
        $entry['tailnet'] = null;
        if (partnerSilent($p, $entry, $now)) {
            if ($tailnet === false) {
                $tailnet = partnerTailnet();
            }
            $entry['tailnet'] = partnerTailnetSays($tailnet, $p['address']);
            $since = partnerLastHeard($p, $entry) ?? $p['paired'];
            $told = $entry['told'];
            $due = !is_array($told) || ($told['since'] ?? null) !== $since || $now - (int) ($told['at'] ?? 0) >= PARTNER_NOTIFY_AGAIN;
            if ($due && !$muted($p, $entry)) {
                $tell($p, $entry);
                $entry['told'] = ['since' => $since, 'at' => $now];
            }
        } else {
            $entry['told'] = null;
        }
        $next['pairs'][$p['id']] = $entry;
    }
    $next['time'] = $now;
    if ($pairs || is_file(partnerDir() . '/state.json')) {
        partnerWritePrivate(partnerDir() . '/state.json', $next);
    }
    return $next;
}

// ===================================================================== what I send: the levels decide, an older partner agrees

/**
 * The units this office sends a pair, as Mr. Backupsy's setup has them (settings.ini: [general] partner_place and the
 * units' `partner = <id>` lines — backupPartnersFromSettings()). The Team Lead only pairs; the level per share and VM
 * says what goes.
 *
 * @return list<string>
 */
function partnerSendUnits(string $id): array
{
    $s = readCfg(BACKUP_DATA_DIR . '/settings.ini', true);
    foreach (backupPartnersFromSettings($s) as $p) {
        if ($p['id'] === $id) {
            return array_values(array_filter($p['units'], fn ($u) => is_string($u) && preg_match(PARTNER_UNIT_RE, $u)));
        }
    }
    return [];
}

/**
 * After a look at a partner (partnerAsk()): a partner of today takes any unit of this office up to its quota (its
 * status says `takes_all`); an older one (up to 1.59) keeps only what its Team Lead agreed to (status.agreed) — so
 * while something Mr. Backupsy sends there isn't agreed, the door's `offer` names it and that Team Lead sees the wish
 * on its card («Keep it too»). Nothing of it is written into pairs.json: the card reads status.agreed. Returns the pair
 * and its state entry as they are now.
 *
 * @return array{0: array, 1: array}
 */
function partnerUnitsFollow(array $p, ?array $ask, array $entry): array
{
    if ($ask === null || !$ask['reachable'] || $p['my_key'] === null || !is_array($ask['status'])) {
        return [$p, $entry];
    }
    if (($ask['answer']['array'] ?? null) === 'started' && partnerOfferDue($p, $ask['status'])) {
        $res = partnerOffer($p, partnerSendUnits($p['id']));
        $entry['offer'] = partnerOfferEntry($res);
        partnerLog($res['ok'] ? "asked {$p['name']} ({$p['id']}, an older office) to keep " . implode(', ', $res['pending'] ?: ['nothing more']) . ' too — its Team Lead decides'
            : "{$p['name']} ({$p['id']}) didn't take the question about the units it hasn't agreed to: {$res['why']}");
    }
    return [$p, $entry];
}

/** An older partner (its status without `takes_all`) whose agreed list lacks something Mr. Backupsy sends it */
function partnerOfferDue(array $p, array $status): bool
{
    if (($status['takes_all'] ?? false) === true || !is_array($status['agreed'] ?? null)) {
        return false;
    }
    return !partnerSubset(partnerSendUnits($p['id']), $status['agreed']);
}

/**
 * The door's `offer <unit>…` (the pair's ssh, like ping): every unit this office sends — an older partner keeps a wish
 * of those it hasn't agreed to and answers what it agreed to keep; a partner of today answers «all agreed». Never
 * changes anything there by itself.
 *
 * @return array{time:int, reachable:bool, ok:bool, why:?string, agreed:?array, pending:?array}
 */
function partnerOffer(array $pair, array $units): array
{
    $now = time();
    $res = ['time' => $now, 'reachable' => false, 'ok' => false, 'why' => null, 'agreed' => null, 'pending' => null];
    if ($pair['my_key'] === null || !$units) {
        return ['why' => 'no_key'] + $res;
    }
    [$exit, $out, $err] = partnerSsh($pair, 'offer', $units);
    $j = partnerJsonLine($out);
    if ($exit === 255 || $exit === 124 || !is_array($j)) {
        $why = $exit === 255 ? (preg_match('/Host key verification failed|REMOTE HOST IDENTIFICATION/i', $err) ? 'host_key'
            : (preg_match('/Permission denied/i', $err) ? 'denied' : 'unreachable')) : ($exit === 124 ? 'timeout' : 'bad_answer');
        return ['why' => $why, 'reachable' => $exit !== 255 && $exit !== 124] + $res;
    }
    if (($j['ok'] ?? null) !== true) {
        $why = is_string($j['why'] ?? null) && preg_match('/^[a-z_]{1,40}$/D', $j['why']) ? $j['why'] : 'bad_answer';
        return ['reachable' => true, 'why' => $why] + $res;
    }
    $agreed = partnerUnitList($j['agreed'] ?? null);
    $pending = partnerUnitList($j['pending'] ?? null);
    if ($agreed === null || $pending === null) {
        return ['reachable' => true, 'why' => 'bad_answer'] + $res;
    }
    return ['reachable' => true, 'ok' => true, 'agreed' => $agreed, 'pending' => $pending] + $res;
}

/** The last offer as state.json keeps it (for the card) */
function partnerOfferEntry(array $res): array
{
    return ['time' => $res['time'], 'ok' => $res['ok'], 'why' => $res['why'], 'pending' => $res['pending']];
}

/** The wish a partner left at my door (wishes/<id>.json, written by the door's `offer`) */
function partnerWishFile(string $id): string
{
    return partnerDir() . "/wishes/$id.json";
}

/**
 * The units a partner named at my door with its `offer` (wishes/<id>.json, exactly the door's shape) — an older
 * sender's wish, which a door of today takes whole: the status answers them as agreed before the first byte came.
 * Null: none.
 *
 * @return array{units: list<string>, time: int}|null
 */
function partnerWish(array $pair): ?array
{
    if ($pair['receive'] === null) {
        return null;
    }
    $j = partnerReadPrivate(partnerWishFile($pair['id']));
    if (!partnerExact($j, ['pair', 'units', 'time']) || $j['pair'] !== $pair['id'] || partnerUnitList($j['units'], 1) === null
        || !is_int($j['time']) || $j['time'] <= 0) {
        return null;
    }
    return ['units' => $j['units'], 'time' => $j['time']];
}

/** The units named at my door written anew (or the file removed when none) — never through a link */
function partnerWishWrite(string $id, array $units, int $time): void
{
    $file = partnerWishFile($id);
    if ($units) {
        partnerWritePrivate($file, ['pair' => $id, 'units' => array_values($units), 'time' => $time]);
    } elseif (is_link($file) || is_file($file)) {
        @unlink($file);
    }
}

/** A line in the pair's door.log written by the Team Lead (the door's own format, «-» for the client) */
function partnerDoorLogLine(string $id, string $text): void
{
    partnerAppend(partnerDir() . '/door.log', date('Y-m-d H:i:s') . "  $id - " . substr((string) preg_replace('/[^\x20-\x7e]/', '?', $text), 0, 300) . "\n");
}

// ===================================================================== what the page gets

/** What the door received of a pair (received/<id>.json, written by the door) */
function partnerReceived(string $id): ?array
{
    $j = partnerReadPrivate(partnerDir() . "/received/$id.json");
    return is_array($j['units'] ?? null) ? $j : null;
}

/**
 * The units received of a pair for its card: each with when it last came, how many snapshots are kept, `new` (first
 * received within PARTNER_NEW_DAYS — the receiver's consent is the pairing, so a unit it never saw before is said) and
 * `stale` (nothing received for PARTNER_STALE_DAYS: the door's retention never touches it any more — Ms. Dustdevil's).
 *
 * @return list<array{unit:string, snap:?string, snaps:int, time:int, first:int, new:bool, stale:bool}>
 */
function partnerReceivedUnits(?array $got, int $now): array
{
    $out = [];
    foreach ((array) ($got['units'] ?? []) as $u => $v) {
        if (!is_string($u) || !preg_match(PARTNER_UNIT_RE, $u) || !is_array($v)) {
            continue;
        }
        $time = is_int($v['time'] ?? null) ? $v['time'] : 0;
        $first = is_int($v['first'] ?? null) ? $v['first'] : $time;        // records up to 1.59: the last time counts as the first
        $out[] = ['unit' => $u, 'snap' => is_string($v['snap'] ?? null) ? $v['snap'] : null, 'snaps' => (int) ($v['snaps'] ?? 0), 'time' => $time,
                  'first' => $first, 'new' => $first > 0 && $now - $first < PARTNER_NEW_DAYS * 86400, 'stale' => $time > 0 && $now - $time > PARTNER_STALE_DAYS * 86400];
    }
    usort($out, fn ($a, $b) => strcmp($a['unit'], $b['unit']));
    return $out;
}

/** The engine's last transfer to a pair (state/status.json partner.done, engine 2.27): bytes and Mbit/s — null before */
function partnerLastTransfer(string $id): ?array
{
    $s = readJson(BACKUP_DATA_DIR . '/state/status.json');
    $last = null;
    foreach ((array) ($s['partner']['done'] ?? []) as $d) {
        if (is_array($d) && ($d['id'] ?? null) === $id && is_numeric($d['mbit'] ?? null)) {
            $last = ['bytes' => is_int($d['bytes'] ?? null) ? $d['bytes'] : null, 'mbit' => (float) $d['mbit']];
        }
    }
    return $last;
}

/**
 * Tailscale's second opinion on a silent partner (where its plugin is): `tailscale status --json`, the peers by their
 * Tailscale addresses and DNS names → online. Null: no Tailscale, or it didn't answer.
 *
 * @return array<string, bool>|null
 */
function partnerTailnet(): ?array
{
    $bin = partnerBin('tailscale');
    if ($bin === null) {
        return null;
    }
    [$exit, $out] = run([$bin, 'status', '--json'], 10);
    $j = $exit === 0 ? json_decode($out, true, 16) : null;
    if (!is_array($j) || !is_array($j['Peer'] ?? null)) {
        return null;
    }
    $map = [];
    foreach ($j['Peer'] as $peer) {
        if (!is_array($peer) || !is_bool($peer['Online'] ?? null)) {
            continue;
        }
        foreach (array_merge((array) ($peer['TailscaleIPs'] ?? []), [rtrim((string) ($peer['DNSName'] ?? ''), '.'), (string) ($peer['HostName'] ?? '')]) as $name) {
            if (is_string($name) && $name !== '') {
                $map[strtolower($name)] = $peer['Online'];
            }
        }
    }
    return $map;
}

/** The tailnet's word on a pair's address: true online, false offline, null not in a tailnet (or no Tailscale) */
function partnerTailnetSays(?array $tailnet, string $address): ?bool
{
    if ($tailnet === null) {
        return null;
    }
    $a = strtolower($address);
    return $tailnet[$a] ?? $tailnet[explode('.', $a)[0]] ?? null;
}

/** The pending offers (pending.json), those older than a week gone with their keys */
function partnerPending(bool $tidy = false): array
{
    $j = partnerReadPrivate(partnerDir() . '/pending.json');
    $out = [];
    $gone = false;
    foreach ((array) ($j['offers'] ?? []) as $o) {
        if (!partnerExact($o, ['id', 'created', 'address', 'port', 'trust', 'units', 'pub_key', 'host_keys']) || !is_string($o['id']) || !preg_match(PARTNER_ID_RE, $o['id'])
            || !is_int($o['created']) || !is_string($o['address']) || !partnerAddressValid($o['address']) || !is_int($o['port'])
            || !in_array($o['trust'], PARTNER_TRUST, true) || partnerUnitList($o['units']) === null || partnerKeyNorm($o['pub_key']) !== $o['pub_key']
            || !is_array($o['host_keys'])) {
            $gone = true;
            continue;
        }
        if (time() - $o['created'] > PARTNER_PENDING_KEEP) {
            if ($tidy) {
                partnerKeyDrop($o['id']);
            }
            $gone = true;
            continue;
        }
        $out[] = $o;
    }
    if ($tidy && $gone) {
        partnerWritePrivate(partnerDir() . '/pending.json', ['offers' => $out]);
    }
    return $out;
}

/**
 * The Team Lead's partner cards (his state's `partners`): from pairs.json, state.json and received/ — never a key, never
 * a block. Cheap: files only (authorized_keys on the flash read once).
 */
function partnerPublic(): array
{
    $pairs = partnerPairs();
    $pending = partnerPending();
    $tickets = partnerTicketsPublic();          // restore tickets: given here (per pair), held here (a new server), asked for
    if (!$pairs && !$pending) {
        return ['pairs' => [], 'pending' => [], 'silent_after' => PARTNER_SILENT_AFTER] + $tickets['lists'];
    }
    $state = partnerStateRead();
    $keys = (string) @file_get_contents(partnerAuthKeysFile(), false, null, 0, 1024 * 1024);
    $now = time();
    $cards = [];
    foreach ($pairs as $p) {
        $e = is_array($state['pairs'][$p['id']] ?? null) ? $state['pairs'][$p['id']] : [];
        $answer = $e['answer'] ?? $p['last_answer'];
        $door = null;
        if ($p['receive'] !== null) {
            $door = 'closed';
            foreach (preg_split('/\r?\n/', $keys) ?: [] as $l) {
                if (partnerLineIsOurs($l, $p['id'])) {
                    $door = str_starts_with($l, 'restrict,from="') && str_contains($l, 'command="' . PARTNER_DOOR . ' ' . $p['id'] . '"') ? 'open' : 'changed';
                }
            }
        }
        $got = partnerReceived($p['id']);
        $status = $e['status'] ?? null;
        $cards[] = [
            'id'         => $p['id'],
            'name'       => $p['name'],
            'address'    => $p['address'],
            'port'       => $p['port'],
            'public'     => !partnerAddressPrivate($p['address']),
            'trust'      => $p['trust'],
            'paired'     => $p['paired'],
            'last_heard' => partnerLastHeard($p, $e),
            'last_try'   => $e['last_try'] ?? null,
            'reachable'  => $p['my_key'] === null ? null : ($e['reachable'] ?? null),
            'why'        => $e['why'] ?? null,
            'array'      => is_array($answer) ? ($answer['array'] ?? null) : null,
            'night'      => is_array($answer) ? (bool) ($answer['night'] ?? false) : null,
            'version'    => is_array($answer) ? ($answer['v'] ?? null) : null,
            'silent'     => partnerSilent($p, $e, $now),
            'tailnet'    => $e['tailnet'] ?? null,
            'sends'      => $p['my_key'] !== null,
            'send_units' => $p['my_key'] !== null ? partnerSendUnits($p['id']) : [],       // the levels in Mr. Backupsy's setup (settings.ini)
            'offer'      => $e['offer'] ?? null,                 // the last offer to an older partner: time, ok, why, pending
            'status_time' => $e['status_time'] ?? null,          // when they_keep (and its agreed) was said
            'older'      => is_array($status) && is_array($status['agreed'] ?? null) && empty($status['takes_all']),    // up to 1.59: keeps `agreed` only
            'named'      => partnerWish($p),                     // what an older sender named at my door (taken whole)
            'receive'    => $p['receive'],
            'door'       => $door,
            'they_keep'  => $status,
            'i_keep'     => $got,
            'i_keep_units' => partnerReceivedUnits($got, $now),  // each with new/stale
            'last_transfer' => partnerLastTransfer($p['id']),
            'tickets'    => $tickets['given'][$p['id']] ?? [],
        ];
    }
    return ['pairs' => $cards, 'pending' => array_map(fn ($o) => ['id' => $o['id'], 'created' => $o['created'], 'address' => $o['address'],
        'units' => $o['units'], 'trust' => $o['trust']], $pending), 'silent_after' => PARTNER_SILENT_AFTER] + $tickets['lists'];
}

// ===================================================================== the Team Lead's actions

/**
 * A request's receive settings checked: pool among $pools, quota, retention, wake. The consent is the pairing: any
 * unit of the partner's up to the quota, at any time (no units, no window since 1.60 — a request that still carries
 * them is taken, they are ignored).
 */
function partnerReceiveFrom(mixed $r, array $pools): array
{
    if (!is_array($r)) {
        throw new Problem('partner_receive', ['field' => 'receive']);
    }
    $receive = [
        'pool'      => is_string($r['pool'] ?? null) ? $r['pool'] : '',            // another type: refused below, naming the field
        'quota_gb'  => is_numeric($r['quota_gb'] ?? null) ? (int) $r['quota_gb'] : -1,
        'retention' => is_string($r['retention'] ?? null) ? trim((string) preg_replace('/\s+/', ' ', $r['retention'])) : '',
        'wake'      => ($r['wake'] ?? null) === true,
    ];
    foreach (['pool' => in_array($receive['pool'], array_column($pools, 'name'), true), 'quota_gb' => $receive['quota_gb'] >= 0 && $receive['quota_gb'] <= PARTNER_QUOTA_MAX_GB,
              'retention' => (bool) preg_match(PARTNER_RETENTION_RE, $receive['retention'])] as $field => $ok) {
        if (!$ok) {
            throw new Problem('partner_receive', ['field' => $field]);
        }
    }
    return $receive;
}

/**
 * My receive settings in the shape an older office (up to 1.59) reads in BLOCK-B: with the window «always» and the
 * units the partner's BLOCK-A named — an office of today ignores both (partnerBlockDecode() takes either shape).
 */
function partnerReceiveForBlock(array $receive, array $theirUnits): array
{
    return ['pool' => $receive['pool'], 'quota_gb' => $receive['quota_gb'], 'retention' => $receive['retention'], 'window' => '00:00-00:00',
            'wake' => $receive['wake'], 'units' => $theirUnits ?: ['place']];
}

/** A new pair's send: only the rate; what goes is the level in Mr. Backupsy's setup */
function partnerSendNew(): array
{
    return ['rate_mbit' => 0];
}

/** The units this server could send (partnerUnits() ok): named in BLOCK-A and -B so an older partner knows what to tick */
function partnerUnitsOk(): array
{
    return array_values(array_column(array_filter(partnerUnits(), fn ($u) => $u['ok']), 'id'));
}

function partnerNewId(): string
{
    $taken = array_merge(array_column(partnerPairs(), 'id'), array_column(partnerPending(), 'id'), array_column(partnerTickets(), 'id'),
        array_column(partnerTicketPairs(), 'id'), array_column(partnerTicketPending(), 'id'));
    do {
        $id = bin2hex(random_bytes(4));
    } while (in_array($id, $taken, true));
    return $id;
}

/** «Add a partner…»: look (what this server offers), do (an offer: id, key, BLOCK-A), show (BLOCK-A again) */
function partner_add(array $r): array
{
    $step = optText($r, 'step', 'look');
    if ($step === 'look') {
        return ['ok' => true, 'name' => partnerMyName(), 'addresses' => partnerMyAddresses(), 'port' => partnerMyPort(),
                'units' => partnerUnits(), 'host_key' => partnerHostKeys() !== []];
    }
    if ($step === 'show') {
        foreach (partnerPending(true) as $o) {
            if ($o['id'] === ($r['id'] ?? null)) {
                return ['ok' => true, 'id' => $o['id'], 'block' => partnerBlockA($o), 'public' => !partnerAddressPrivate($o['address'])];
            }
        }
        throw new Problem('partner_unknown');
    }
    if ($step !== 'do') {
        throw new Problem('bad_request');
    }
    [$address, $port] = partnerAddressFrom($r);
    $trust = optText($r, 'trust');
    if (!in_array($trust, PARTNER_TRUST, true)) {
        throw new Problem('partner_receive', ['field' => 'trust']);
    }
    $host = partnerHostKeys();
    if (!$host) {
        throw new Problem('partner_host_key');
    }
    // the units this server could send, for an older partner's dialog (one of today takes any unit; what goes is the
    // level in Mr. Backupsy's setup — the Team Lead only pairs since 1.60)
    $units = partnerUnitsOk();
    $pending = partnerPending(true);
    $id = partnerNewId();
    $pub = partnerKeyMake($id);
    $offer = ['id' => $id, 'created' => time(), 'address' => $address, 'port' => $port, 'trust' => $trust, 'units' => $units,
              'pub_key' => $pub, 'host_keys' => $host];
    try {
        partnerWritePrivate(partnerDir() . '/pending.json', ['offers' => array_merge($pending, [$offer])]);
    } catch (Throwable $e) {
        partnerKeyDrop($id);
        throw $e;
    }
    partnerLog("offer $id made (address $address:$port)");
    return ['ok' => true, 'id' => $id, 'block' => partnerBlockA($offer), 'public' => !partnerAddressPrivate($address)];
}

function partnerBlockA(array $offer): string
{
    return partnerBlockEncode(['v' => 1, 'block' => 'A', 'id' => $offer['id'], 'name' => partnerMyName(), 'address' => $offer['address'], 'port' => $offer['port'],
        'host_keys' => $offer['host_keys'], 'pub_key' => $offer['pub_key'], 'trust' => $offer['trust'], 'units' => $offer['units']]);
}

/** The address and port this office gives its partner (a request's), checked */
function partnerAddressFrom(array $r): array
{
    $address = trim(optText($r, 'address'));
    $port = is_numeric($r['port'] ?? null) ? (int) $r['port'] : 0;
    if (!partnerAddressValid($address)) {
        throw new Problem('partner_address');
    }
    if ($port < 1 || $port > 65535) {
        throw new Problem('partner_receive', ['field' => 'port']);
    }
    return [$address, $port];
}

/** A partner's facts for the page (from its block) */
function partnerBlockFacts(array $b): array
{
    return ['id' => $b['id'], 'name' => $b['name'], 'address' => $b['address'], 'port' => $b['port'], 'public' => !partnerAddressPrivate($b['address']),
            'host_keys' => array_map('partnerFingerprint', $b['host_keys']), 'key' => $b['pub_key'] !== null ? partnerFingerprint($b['pub_key']) : null,
            'units' => $b['units'], 'trust' => $b['trust'] ?? null, 'receive' => $b['receive'] ?? null];
}

/**
 * «Accept a partner…» (the side that got BLOCK-A): look (the partner, the pools, the line that would be written), do
 * (the pair stored, A's line into authorized_keys when I keep what A sends — any unit, up to my quota —, my key when I
 * send too, BLOCK-B and the SAFETY CODE). No units to tick since 1.60: the consent is the pairing; what each side
 * sends is the level in its Mr. Backupsy's setup.
 */
function partner_accept(array $r): array
{
    $a = partnerBlockDecode($r['block'] ?? null, 'A');
    foreach (partnerPending(true) as $o) {
        if ($o['id'] === $a['id'] && $o['pub_key'] === $a['pub_key']) {
            return partnerAcceptSelf($a, $o, $r);       // this office's own offer: a pairing with itself (a test)
        }
    }
    if (partnerPair($a['id']) !== null || in_array($a['id'], array_column(partnerPending(), 'id'), true)) {
        throw new Problem('partner_known', ['name' => $a['name']]);
    }
    if (in_array($a['pub_key'], partnerHostKeys(), true)) {
        throw new Problem('partner_block', ['field' => 'pub_key']);
    }
    $from = partnerFromList($a['address']);
    $step = optText($r, 'step', 'look');
    if ($step === 'look') {
        return ['ok' => true, 'partner' => partnerBlockFacts($a), 'pools' => partnerPools(), 'defaults' => PARTNER_DEFAULTS,
                'line' => $from !== null ? partnerDoorLine($a['id'], $from, $a['pub_key']) : null,
                'name' => partnerMyName(), 'addresses' => partnerMyAddresses(), 'port' => partnerMyPort(), 'units' => partnerUnits(),
                'host_key' => partnerHostKeys() !== []];
    }
    if ($step !== 'do' || ($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $host = partnerHostKeys();
    if (!$host) {
        throw new Problem('partner_host_key');
    }
    $receive = null;
    if (($r['receive'] ?? null) !== null) {
        $receive = partnerReceiveFrom($r['receive'], partnerPools());
        if ($from === null) {
            throw new Problem('partner_unresolved', ['address' => $a['address']]);
        }
    }
    $sendToo = ($r['send_too'] ?? null) === true;
    if ($receive === null && !$sendToo) {
        throw new Problem('partner_nothing');
    }
    [$address, $port] = partnerAddressFrom($r);
    $trust = optText($r, 'trust');
    if (!in_array($trust, PARTNER_TRUST, true)) {
        throw new Problem('partner_receive', ['field' => 'trust']);
    }
    $myUnits = $sendToo ? partnerUnitsOk() : [];          // for an older partner's dialog only (see partner_add)
    $myPub = $sendToo ? partnerKeyMake($a['id']) : null;
    $pairs = partnerPairs();
    $pair = ['id' => $a['id'], 'name' => $a['name'], 'address' => $a['address'], 'port' => $a['port'],
             'host_keys' => array_values(array_map('partnerFingerprint', $a['host_keys'])), 'my_key' => $myPub !== null ? partnerFingerprint($myPub) : null,
             'their_key' => $receive !== null ? partnerFingerprint($a['pub_key']) : null, 'send' => partnerSendNew(),
             'receive' => $receive, 'trust' => $trust, 'paired' => time(), 'last_heard' => null, 'last_answer' => null];
    try {
        if ($myPub !== null) {
            partnerKnownWrite($a['id'], $a['address'], $a['port'], $a['host_keys']);
        }
        partnerPairsWrite(array_merge($pairs, [$pair]));
        if ($receive !== null) {
            partnerAuthKeysEdit($a['id'], partnerDoorLine($a['id'], (string) $from, $a['pub_key']));
        }
    } catch (Throwable $e) {
        partnerKeyDrop($a['id']);
        partnerPairsWrite($pairs);
        throw $e;
    }
    partnerLog("paired with {$a['name']} ({$a['id']}, {$a['address']}:{$a['port']}) — accepted their offer"
        . ($receive !== null ? ', their line in authorized_keys' : '') . ($myPub !== null ? ', I send too' : ''));
    $block = partnerBlockEncode(['v' => 1, 'block' => 'B', 'id' => $a['id'], 'name' => partnerMyName(), 'address' => $address, 'port' => $port,
        'host_keys' => $host, 'pub_key' => $myPub, 'receive' => $receive !== null ? partnerReceiveForBlock($receive, $a['units']) : null, 'units' => $myUnits]);
    return ['ok' => true, 'block' => $block, 'code' => partnerSafetyCode($a['pub_key'], $myPub, $a['host_keys'], $host),
            'public' => !partnerAddressPrivate($address), 'partners' => partnerPublic()];
}

/**
 * This office's own BLOCK-A pasted under «Accept a partner…»: it pairs with itself — for a test on one server (the
 * coordinator's self-pairing, the throughput of the path). One pair: my offer's key sends, and the same key's line lets
 * it in (from= the offer's own address); its host key pinned at that address. No BLOCK-B, no code: nobody is in
 * between. Look: what the dialog needs; do: stored, the line written, a ping.
 */
function partnerAcceptSelf(array $a, array $offer, array $r): array
{
    $from = partnerFromList($a['address']);
    if (($r['step'] ?? 'look') === 'look') {
        return ['ok' => true, 'self' => true, 'partner' => partnerBlockFacts($a), 'pools' => partnerPools(), 'defaults' => PARTNER_DEFAULTS,
                'line' => $from !== null ? partnerDoorLine($a['id'], $from, $a['pub_key']) : null, 'units' => [], 'addresses' => [], 'port' => $a['port'],
                'name' => partnerMyName(), 'host_key' => partnerHostKeys() !== []];
    }
    if (($r['step'] ?? '') !== 'do' || ($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $receive = partnerReceiveFrom($r['receive'] ?? null, partnerPools());
    if ($from === null) {
        throw new Problem('partner_unresolved', ['address' => $a['address']]);
    }
    $fp = (string) partnerFingerprint($offer['pub_key']);
    $pairs = partnerPairs();
    $pair = ['id' => $a['id'], 'name' => partnerMyName(), 'address' => $a['address'], 'port' => $a['port'],
             'host_keys' => array_values(array_map('partnerFingerprint', $offer['host_keys'])), 'my_key' => $fp, 'their_key' => $fp,
             'send' => partnerSendNew(), 'receive' => $receive, 'trust' => 'mine', 'paired' => time(), 'last_heard' => null, 'last_answer' => null];
    try {
        partnerKnownWrite($a['id'], $a['address'], $a['port'], $offer['host_keys']);
        partnerPairsWrite(array_merge($pairs, [$pair]));
        partnerAuthKeysEdit($a['id'], partnerDoorLine($a['id'], $from, $offer['pub_key']));
    } catch (Throwable $e) {
        partnerPairsWrite($pairs);
        @unlink(partnerKnownFile($a['id']));
        throw $e;
    }
    partnerWritePrivate(partnerDir() . '/pending.json', ['offers' => array_values(array_filter(partnerPending(), fn ($o) => $o['id'] !== $a['id']))]);
    partnerLog("paired with itself ({$a['id']}, {$a['address']}:{$a['port']}) — a test: the line in authorized_keys, its own host key pinned");
    return ['ok' => true, 'self' => true, 'ask' => partnerAskAndKeep($a['id']), 'partners' => partnerPublic()];
}

/**
 * «Paste the partner's answer» (the side that made the offer): look (the code, what they keep, what they send — and the
 * line for them when they send), do («They match»: the pair stored, their known_hosts, their line when I keep something,
 * then a ping).
 */
function partner_finish(array $r): array
{
    $b = partnerBlockDecode($r['block'] ?? null, 'B');
    $offer = null;
    foreach (partnerPending(true) as $o) {
        if ($o['id'] === $b['id']) {
            $offer = $o;
        }
    }
    if ($offer === null) {
        throw new Problem(partnerPair($b['id']) !== null ? 'partner_known' : 'partner_unknown', ['name' => $b['name']]);
    }
    if ($b['pub_key'] !== null && ($b['pub_key'] === $offer['pub_key'] || in_array($b['pub_key'], $offer['host_keys'], true))) {
        throw new Problem('partner_block', ['field' => 'pub_key']);
    }
    $code = partnerSafetyCode($offer['pub_key'], $b['pub_key'], $offer['host_keys'], $b['host_keys']);
    $from = partnerFromList($b['address']);
    $step = optText($r, 'step', 'look');
    if ($step === 'look') {
        return ['ok' => true, 'partner' => partnerBlockFacts($b), 'code' => $code, 'pools' => $b['pub_key'] !== null ? partnerPools() : [],
                'defaults' => PARTNER_DEFAULTS, 'line' => $b['pub_key'] !== null && $from !== null ? partnerDoorLine($b['id'], $from, (string) $b['pub_key']) : null];
    }
    if ($step !== 'do' || ($r['confirm'] ?? null) !== true || ($r['code'] ?? null) !== $code) {
        throw new Problem('bad_request');
    }
    $receive = null;
    if ($b['pub_key'] !== null && ($r['receive'] ?? null) !== null) {        // B sends (it brought a key): I may keep what comes
        $receive = partnerReceiveFrom($r['receive'], partnerPools());
        if ($from === null) {
            throw new Problem('partner_unresolved', ['address' => $b['address']]);
        }
    }
    $sends = $b['receive'] !== null;    // they keep what I send: my key is needed there
    if (!$sends && $receive === null) {
        throw new Problem('partner_nothing');
    }
    $pairs = partnerPairs();
    $pair = ['id' => $b['id'], 'name' => $b['name'], 'address' => $b['address'], 'port' => $b['port'],
             'host_keys' => array_values(array_map('partnerFingerprint', $b['host_keys'])), 'my_key' => $sends ? partnerFingerprint($offer['pub_key']) : null,
             'their_key' => $receive !== null ? partnerFingerprint((string) $b['pub_key']) : null, 'send' => partnerSendNew(),
             'receive' => $receive, 'trust' => $offer['trust'], 'paired' => time(), 'last_heard' => null, 'last_answer' => null];
    try {
        if ($sends) {
            partnerKnownWrite($b['id'], $b['address'], $b['port'], $b['host_keys']);
        }
        partnerPairsWrite(array_merge($pairs, [$pair]));
        if ($receive !== null) {
            partnerAuthKeysEdit($b['id'], partnerDoorLine($b['id'], (string) $from, (string) $b['pub_key']));
        }
    } catch (Throwable $e) {
        partnerPairsWrite($pairs);
        @unlink(partnerKnownFile($b['id']));
        throw $e;
    }
    if (!$sends) {
        @unlink(partnerKeyFile($b['id']));
        @unlink(partnerKeyFile($b['id']) . '.pub');
    }
    partnerWritePrivate(partnerDir() . '/pending.json', ['offers' => array_values(array_filter(partnerPending(), fn ($o) => $o['id'] !== $b['id']))]);
    partnerLog("paired with {$b['name']} ({$b['id']}, {$b['address']}:{$b['port']}) — their answer taken, the codes matched"
        . ($receive !== null ? ', their line in authorized_keys' : ''));
    $ask = $sends ? partnerAskAndKeep($b['id']) : null;
    return ['ok' => true, 'ask' => $ask, 'partners' => partnerPublic()];
}

/** Asks one partner now and keeps the answer (state.json, the pair's last_heard/last_answer) */
function partnerAskAndKeep(string $id): ?array
{
    $pair = partnerPair($id);
    if ($pair === null) {
        throw new Problem('partner_unknown');
    }
    $ask = partnerAsk($pair);
    $state = partnerStateRead();
    $entry = partnerStateEntry(is_array($state['pairs'][$id] ?? null) ? $state['pairs'][$id] : [], $ask, $pair);
    if ($ask !== null && $ask['reachable']) {
        partnerPairUpdate($id, function (array $p) use ($ask) {
            $p['last_heard'] = $ask['time'];
            $p['last_answer'] = ['array' => $ask['answer']['array'], 'night' => $ask['answer']['night'], 'v' => $ask['answer']['v']];
            return $p;
        });
        [, $entry] = partnerUnitsFollow(partnerPair($id) ?? $pair, $ask, $entry);
    }
    $state['pairs'][$id] = $entry;
    partnerWritePrivate(partnerDir() . '/state.json', $state);
    return $ask === null ? null : ['reachable' => $ask['reachable'], 'why' => $ask['why'], 'array' => $ask['answer']['array'] ?? null];
}

/** The card's «Ask now» */
function partner_ping(array $r): array
{
    $id = optText($r, 'id');
    if (!preg_match(PARTNER_ID_RE, $id)) {
        throw new Problem('bad_request');
    }
    $pair = partnerPair($id);
    if ($pair !== null && $pair['my_key'] === null) {
        // a pair that only receives here: no key of mine to knock with — said, never an «ok» that asked nobody (QA 2026-10-08)
        throw new Problem('partner_not_sending', ['name' => $pair['name']]);
    }
    return ['ok' => true, 'ask' => partnerAskAndKeep($id), 'partners' => partnerPublic()];
}

/**
 * «End the partnership» (also for an offer nobody answered): my line for them out of authorized_keys, my key and their
 * known_hosts off the flash, the pair gone. Their copies here stay (the user removes them; Ms. Dustdevil, stage 2).
 */
function partner_end(array $r): array
{
    $id = optText($r, 'id');
    if (!preg_match(PARTNER_ID_RE, $id)) {
        throw new Problem('bad_request');
    }
    $pending = partnerPending();
    $pairs = partnerPairs();
    $pair = partnerPair($id, $pairs);
    $offer = in_array($id, array_column($pending, 'id'), true);
    if ($pair === null && !$offer) {
        throw new Problem('partner_unknown');
    }
    partnerAuthKeysEdit($id, null);
    partnerKeyDrop($id);
    if ($pair !== null) {
        partnerPairsWrite(array_values(array_filter($pairs, fn ($p) => $p['id'] !== $id)));
        $state = partnerStateRead();
        if (isset($state['pairs'][$id])) {
            unset($state['pairs'][$id]);
            partnerWritePrivate(partnerDir() . '/state.json', $state);
        }
        @unlink(partnerRunDir() . "/heard-$id");
        partnerWishWrite($id, [], 0);
        partnerLog("partnership with {$pair['name']} ($id) ended — line, key and known_hosts removed; their copies here stay");
    }
    if ($offer) {
        partnerWritePrivate(partnerDir() . '/pending.json', ['offers' => array_values(array_filter($pending, fn ($o) => $o['id'] !== $id))]);
        if ($pair === null) {
            partnerLog("offer $id withdrawn");
        }
    }
    return ['ok' => true, 'partners' => partnerPublic()];
}

function partner_state(array $r): array
{
    return ['ok' => true, 'partners' => partnerPublic()];
}

/** A line in the agent's log about partners */
function partnerLog(string $text): void
{
    if (function_exists('logLine') && defined('AGENT_LOG')) {
        logLine("Partner offices: $text");
    }
}

/**
 * The Team Lead's cards anew, soon: the door (root, a process of its own) and the mutual watch's job leave a marker in
 * RAM, his tick runs caretakerScan() at its next round (a file_exists, microseconds) — so a pairing that landed from
 * the other side shows at once, not at the next tour (until 1.59 a reload said «No partner yet» for up to 30 minutes).
 */
function partnerRescanAsk(): void
{
    $dir = dirname(partnerRunDir());
    if (is_dir($dir) && !is_link("$dir/" . PARTNER_RESCAN_FILE)) {
        @touch("$dir/" . PARTNER_RESCAN_FILE);
    }
}

/** True once (the marker taken): the Team Lead's tick asks it every round */
function partnerRescanDue(): bool
{
    $f = dirname(partnerRunDir()) . '/' . PARTNER_RESCAN_FILE;
    return file_exists($f) && !is_link($f) && @unlink($f);
}

// ===================================================================== the step partner-simple (1.60)

/**
 * The migration step `partner-simple` (officeMigrateSteps(), 1.60.0): up to 1.59 a pair carried what the partner
 * agreed to keep (send.units, send.wanted, send.offered) and what this office keeps of theirs (receive.units) with a
 * receiving window (receive.window). Since 1.60 the Team Lead only pairs: the levels in Mr. Backupsy's setup say what
 * goes, the door takes any unit of a pair up to its quota, at any time. The step: a copy pairs.json.before-partner-simple
 * beside the file (once, never overwritten), every pair written in the new shape (the reader takes both), and the units
 * the pairs sent so far (send.wanted ∪ send.units) into migrated.json — Mr. Backupsy's setup proposes them at
 * «local + partner» where settings.ini lacks their line (partnerMigratedPending(); the user applies). Idempotent: a file
 * in the new shape changes nothing. Returns a line for the log.
 */
function partnerMigrateSimple(string $dir): string
{
    $file = "$dir/partner/pairs.json";
    clearstatcache(true, $file);
    if (@lstat($file) === false) {
        return 'no pairs.json — nothing to change';
    }
    $raw = partnerReadPrivate($file);
    if (!is_array($raw) || ($raw['v'] ?? null) !== 1 || !is_array($raw['pairs'] ?? null)) {
        return 'pairs.json is not this version\'s file (or not root\'s own) — left as it is';
    }
    $old = [];
    $n = 0;
    foreach ($raw['pairs'] as $p) {
        if (!is_array($p) || !partnerPairValid($p) || !partnerPairOldShape($p)) {
            continue;
        }
        $n++;
        $units = partnerUnitList(array_values(array_unique(array_merge((array) ($p['send']['units'] ?? []), (array) ($p['send']['wanted'] ?? [])))));
        if ($units) {
            $old[$p['id']] = $units;
        }
    }
    if ($n === 0) {
        return 'the pairs are in the shape of 1.60 already';
    }
    $copy = "$file.before-partner-simple";
    if (@lstat($copy) === false) {
        if (!@copy($file, $copy)) {
            throw new RuntimeException("could not keep a copy of pairs.json as $copy");
        }
        @chmod($copy, 0600);
    }
    partnerPairsWrite(partnerPairs($file), $file);         // read in either shape, written in the new one (the rest kept as it is)
    if ($old) {
        partnerWritePrivate("$dir/partner/" . PARTNER_MIGRATED_FILE, ['v' => 1, 'pairs' => $old, 'time' => time()]);
    }
    return "$n pair(s) written without their unit lists and window (a copy: " . basename($copy) . ')'
        . ($old ? '; the units sent so far go to Mr. Backupsy\'s setup as «local + partner» (' . implode('; ', array_map(fn ($id, $u) => "$id: " . implode(', ', $u), array_keys($old), $old)) . ')' : '');
}

/**
 * The units of migrated.json still waiting for Mr. Backupsy's setup: per pair that still exists and this office sends
 * to, those settings.ini doesn't name for it yet. For the plan (`migrated`): the page proposes them at «local + partner».
 *
 * @return array<string, list<string>>  id => units
 */
function partnerMigratedPending(?string $dir = null): array
{
    $j = partnerReadPrivate(($dir ?? partnerDir()) . '/' . PARTNER_MIGRATED_FILE);
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !is_array($j['pairs'] ?? null)) {
        return [];
    }
    $out = [];
    foreach (partnerPairs() as $p) {
        if ($p['my_key'] === null) {
            continue;
        }
        $units = partnerUnitList($j['pairs'][$p['id']] ?? null) ?? [];
        $left = array_values(array_diff($units, partnerSendUnits($p['id'])));
        if ($left) {
            $out[$p['id']] = $left;
        }
    }
    return $out;
}

// ===================================================================== restore tickets (stage 3)

/*
 * A server is gone; its partner (the holder) keeps its copies. A NEW server (only the plugin installed, no pairs)
 * pulls them with a restore ticket — two pastes and a code, no secret in any block:
 *
 *   new server  Team Lead «Start from a partner's copy…» → a key of its own (partners/<id>.key) and
 *               BLOCK-N {v, block:"N", id, name, address, pub_key}
 *   holder      the card of the gone server «Hand <name>'s copies to a new server…» → paste BLOCK-N → a ticket line in
 *               authorized_keys (restrict, expiry-time, from=, command="…/partner-door.sh ticket-<id>", comment
 *               uso-ticket:<id> — the door allows ping, list, send-back and quota on the gone server's copies only), valid
 *               7 days (tickets.json) → BLOCK-T {v, block:"T", id, name, of, address, port, host_keys, units, expires}
 *               and the SAFETY CODE (partnerSafetyCode(N's key, -, -, the holder's host keys))
 *   new server  «Paste the ticket» → the same code → a ticket pair (ticket-pairs.json, kind ticket: it never sends and
 *               never receives — it only pulls, through Mr. Restori's «Onto a new server»)
 *
 * Expired tickets go by themselves: sshd refuses the key after expiry-time, the door after `expires`, and the agent
 * removes the line (and the new server its ticket pair) at its start and once a day (partnerTicketsTidy()).
 *
 *   data/partner/tickets.json          (holder)     {"v":1,"tickets":[{id, of, name, address, from, key, created, expires, units}]}
 *   data/partner/ticket-pairs.json     (new server) {"v":1,"pairs":[{id, kind:"ticket", name, of, address, port, host_keys, my_key,
 *                                                    units, expires, paired, last_heard}]}
 *   data/partner/ticket-pending.json   (new server) {"requests":[{id, created, name, address, pub_key}]}
 */

/** A from="…" list as the office writes it: IP addresses, commas between, at most 8 */
function partnerFromValid(mixed $from): bool
{
    if (!is_string($from) || $from === '' || strlen($from) > 400) {
        return false;
    }
    $ips = explode(',', $from);
    return count($ips) <= 8 && !array_filter($ips, fn ($ip) => !filter_var($ip, FILTER_VALIDATE_IP));
}

/** A ticket the holder gave (tickets.json) — exactly the shape the office writes */
function partnerTicketValid(mixed $t): bool
{
    return partnerExact($t, ['id', 'of', 'name', 'address', 'from', 'key', 'created', 'expires', 'units'])
        && is_string($t['id']) && preg_match(PARTNER_ID_RE, $t['id']) && is_string($t['of']) && preg_match(PARTNER_ID_RE, $t['of']) && $t['id'] !== $t['of']
        && is_string($t['name']) && preg_match(PARTNER_NAME_RE, $t['name']) && is_string($t['address']) && partnerAddressValid($t['address'])
        && partnerFromValid($t['from']) && is_string($t['key']) && preg_match(PARTNER_FP_RE, $t['key'])
        && is_int($t['created']) && $t['created'] > 0 && is_int($t['expires']) && $t['expires'] > $t['created']
        && $t['expires'] <= $t['created'] + PARTNER_TICKET_DAYS * 86400 + 60 && partnerUnitList($t['units'], 1) !== null;
}

/** @return list<array> the tickets this office gave (holder), in the office's shape only */
function partnerTickets(): array
{
    $j = partnerReadPrivate(partnerDir() . '/tickets.json');
    if ($j === null || ($j['v'] ?? null) !== 1 || !is_array($j['tickets'] ?? null)) {
        return [];
    }
    $out = $ids = [];
    foreach ($j['tickets'] as $t) {
        if (partnerTicketValid($t) && !isset($ids[$t['id']])) {
            $ids[$t['id']] = true;
            $out[] = $t;
        }
    }
    return $out;
}

function partnerTicketsWrite(array $tickets): void
{
    foreach ($tickets as $t) {
        if (!partnerTicketValid($t)) {
            throw new Problem('partner_shape');
        }
    }
    partnerListWrite(partnerDir() . '/tickets.json', 'tickets', $tickets, 'partnerTicketValid');
}

function partnerTicket(string $id): ?array
{
    foreach (partnerTickets() as $t) {
        if ($t['id'] === $id) {
            return $t;
        }
    }
    return null;
}

/** A ticket pair of a new server (ticket-pairs.json) — exactly the shape the office writes */
function partnerTicketPairValid(mixed $p): bool
{
    if (!partnerExact($p, ['id', 'kind', 'name', 'of', 'address', 'port', 'host_keys', 'my_key', 'units', 'expires', 'paired', 'last_heard'])
        || !is_array($p['host_keys']) || !array_is_list($p['host_keys']) || !$p['host_keys'] || count($p['host_keys']) > 4) {
        return false;
    }
    foreach ($p['host_keys'] as $fp) {
        if (!is_string($fp) || !preg_match(PARTNER_FP_RE, $fp)) {
            return false;
        }
    }
    return $p['kind'] === 'ticket' && is_string($p['id']) && preg_match(PARTNER_ID_RE, $p['id'])
        && is_string($p['name']) && preg_match(PARTNER_NAME_RE, $p['name']) && is_string($p['of']) && preg_match(PARTNER_NAME_RE, $p['of'])
        && is_string($p['address']) && partnerAddressValid($p['address']) && is_int($p['port']) && $p['port'] >= 1 && $p['port'] <= 65535
        && is_string($p['my_key']) && preg_match(PARTNER_FP_RE, $p['my_key']) && partnerUnitList($p['units'], 1) !== null
        && is_int($p['expires']) && is_int($p['paired']) && $p['paired'] > 0 && ($p['last_heard'] === null || is_int($p['last_heard']));
}

/** @return list<array> this (new) server's ticket pairs — the copies of a gone server it may pull */
function partnerTicketPairs(): array
{
    $j = partnerReadPrivate(partnerDir() . '/ticket-pairs.json');
    if ($j === null || ($j['v'] ?? null) !== 1 || !is_array($j['pairs'] ?? null)) {
        return [];
    }
    $out = $ids = [];
    foreach ($j['pairs'] as $p) {
        if (partnerTicketPairValid($p) && !isset($ids[$p['id']])) {
            $ids[$p['id']] = true;
            $out[] = $p;
        }
    }
    return $out;
}

function partnerTicketPairsWrite(array $pairs): void
{
    foreach ($pairs as $p) {
        if (!partnerTicketPairValid($p)) {
            throw new Problem('partner_shape');
        }
    }
    partnerListWrite(partnerDir() . '/ticket-pairs.json', 'pairs', $pairs, 'partnerTicketPairValid');
}

function partnerTicketPair(string $id): ?array
{
    foreach (partnerTicketPairs() as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** The requests this (new) server made with «Start from a partner's copy…» (≤ 7 days; older ones go with their keys) */
function partnerTicketPending(bool $tidy = false): array
{
    $j = partnerReadPrivate(partnerDir() . '/ticket-pending.json');
    $out = [];
    $gone = false;
    foreach ((array) ($j['requests'] ?? []) as $q) {
        if (!partnerExact($q, ['id', 'created', 'name', 'address', 'pub_key']) || !is_string($q['id']) || !preg_match(PARTNER_ID_RE, $q['id'])
            || !is_int($q['created']) || !is_string($q['name']) || !preg_match(PARTNER_NAME_RE, $q['name']) || !is_string($q['address'])
            || !partnerAddressValid($q['address']) || partnerKeyNorm($q['pub_key']) !== $q['pub_key']) {
            $gone = true;
            continue;
        }
        if (time() - $q['created'] > PARTNER_PENDING_KEEP) {
            if ($tidy) {
                partnerKeyDrop($q['id']);
            }
            $gone = true;
            continue;
        }
        $out[] = $q;
    }
    if ($tidy && $gone) {
        partnerWritePrivate(partnerDir() . '/ticket-pending.json', ['requests' => $out]);
    }
    return $out;
}

/** The ticket's line in authorized_keys: sshd's own expiry-time (UTC) besides the door's look at `expires` */
function partnerTicketLine(string $id, string $from, string $pubKey, int $expires): string
{
    return 'restrict,expiry-time="' . gmdate('YmdHi', $expires) . 'Z",from="' . $from . '",command="' . PARTNER_DOOR . ' ticket-' . $id . '" '
        . $pubKey . ' uso-ticket:' . $id;
}

/**
 * BLOCK-N ({v, block:"N", id, name, address, pub_key}) or BLOCK-T ({v, block:"T", id, name, of, address, port, host_keys,
 * units, expires}) as pasted — field by field like partnerBlockDecode(); a refusal names the field.
 */
function partnerTicketBlockDecode(mixed $text, string $kind): array
{
    $bad = fn (string $field) => new Problem('partner_block', ['field' => $field]);
    $text = is_string($text) ? (string) preg_replace('/\s+/', '', $text) : '';
    if ($text === '' || strlen($text) > PARTNER_BLOCK_MAX) {
        throw $bad('block');
    }
    $raw = base64_decode($text, true);
    $b = $raw === false ? null : json_decode($raw, true, 6);
    if (!is_array($b) || array_is_list($b)) {
        throw $bad('block');
    }
    if (($b['block'] ?? null) !== $kind) {
        throw $bad('kind');
    }
    $keys = $kind === 'N' ? ['v', 'block', 'id', 'name', 'address', 'pub_key'] : ['v', 'block', 'id', 'name', 'of', 'address', 'port', 'host_keys', 'units', 'expires'];
    if (!partnerExact($b, $keys)) {
        throw $bad('fields');
    }
    if ($b['v'] !== 1) {
        throw $bad('v');
    }
    if (!is_string($b['id']) || !preg_match(PARTNER_ID_RE, $b['id'])) {
        throw $bad('id');
    }
    if (!is_string($b['name']) || !preg_match(PARTNER_NAME_RE, $b['name'])) {
        throw $bad('name');
    }
    if (!is_string($b['address']) || !partnerAddressValid($b['address'])) {
        throw $bad('address');
    }
    if ($kind === 'N') {
        if (partnerKeyNorm($b['pub_key']) === null || partnerKeyNorm($b['pub_key']) !== $b['pub_key']) {
            throw $bad('pub_key');
        }
        return $b;
    }
    if (!is_string($b['of']) || !preg_match(PARTNER_NAME_RE, $b['of'])) {
        throw $bad('of');
    }
    if (!is_int($b['port']) || $b['port'] < 1 || $b['port'] > 65535) {
        throw $bad('port');
    }
    if (!is_array($b['host_keys']) || !array_is_list($b['host_keys']) || !$b['host_keys'] || count($b['host_keys']) > 4) {
        throw $bad('host_keys');
    }
    foreach ($b['host_keys'] as $k) {
        if (partnerKeyNorm($k) === null || partnerKeyNorm($k) !== $k) {
            throw $bad('host_keys');
        }
    }
    if (partnerUnitList($b['units'], 1) === null) {
        throw $bad('units');
    }
    if (!is_int($b['expires']) || $b['expires'] <= 0) {
        throw $bad('expires');
    }
    return $b;
}

/** The ticket's safety code: the new server's key and the holder's host keys (both pages compute it alike) */
function partnerTicketCode(string $newPub, array $holderHostKeys): string
{
    return partnerSafetyCode($newPub, null, [], $holderHostKeys);
}

/** A call through a ticket's door (the new server's side): like partnerSsh(), with the ticket pair's key and pin */
function partnerTicketSsh(array $tp, string $verb, array $args = [], int $timeout = PARTNER_SSH_TIMEOUT): array
{
    foreach (array_merge([$verb], $args) as $w) {
        if (!is_string($w) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,80}$/D', $w)) {
            return [2, '', 'bad word'];
        }
    }
    if (!partnerTicketPairValid($tp) || !in_array($verb, PARTNER_TICKET_VERBS, true) || !is_file(partnerKeyFile($tp['id'])) || !is_file(partnerKnownFile($tp['id']))) {
        return [2, '', 'no key for this ticket'];
    }
    return run(partnerSshArgs($tp, implode(' ', array_merge([$verb], $args))), $timeout);
}

/** Pings a ticket's door now: reachable, why, its array (and keeps last_heard) */
function partnerTicketAsk(string $id): array
{
    $pairs = partnerTicketPairs();
    $tp = null;
    foreach ($pairs as $p) {
        $tp = $p['id'] === $id ? $p : $tp;
    }
    if ($tp === null) {
        throw new Problem('partner_unknown');
    }
    [$exit, $out, $err] = partnerTicketSsh($tp, 'ping');
    $j = partnerJsonLine($out);
    if ($exit === 0 && is_array($j) && ($j['ok'] ?? null) === true && in_array($j['array'] ?? null, ['started', 'stopped'], true)) {
        foreach ($pairs as $i => $p) {
            if ($p['id'] === $id) {
                $pairs[$i]['last_heard'] = time();
            }
        }
        partnerTicketPairsWrite($pairs);
        return ['reachable' => true, 'why' => null, 'array' => $j['array']];
    }
    $why = is_array($j) && is_string($j['why'] ?? null) && preg_match('/^[a-z_]{1,32}$/D', $j['why']) ? $j['why']
        : ($exit === 255 ? (preg_match('/Host key verification failed|REMOTE HOST IDENTIFICATION/i', $err) ? 'host_key'
            : (preg_match('/Permission denied/i', $err) ? 'denied' : 'unreachable')) : ($exit === 124 ? 'timeout' : 'bad_answer'));
    return ['reachable' => false, 'why' => $why, 'array' => null];
}

/**
 * Tickets that ended: on the holder each expired ticket's line out of authorized_keys and its record gone (and a
 * uso-ticket line no ticket names); on a new server each expired ticket pair gone with its key and pin; requests older
 * than a week gone with their keys. Cheap when there is nothing: a stat or three. The number of what went.
 */
function partnerTicketsTidy(?int $now = null): int
{
    $now ??= time();
    $gone = 0;
    $dir = partnerDir();
    if (is_file("$dir/tickets.json") || str_contains((string) @file_get_contents(partnerAuthKeysFile(), false, null, 0, 1024 * 1024), 'uso-ticket:')) {
        $keep = [];
        foreach (partnerTickets() as $t) {
            if ($t['expires'] > $now) {
                $keep[$t['id']] = $t;
                continue;
            }
            partnerAuthKeysEdit($t['id'], null, null, true);
            partnerLog("ticket {$t['id']} for {$t['name']} (the copies of pair {$t['of']}) ended - its line removed");
            $gone++;
        }
        foreach (preg_split('/\r?\n/', (string) @file_get_contents(partnerAuthKeysFile(), false, null, 0, 1024 * 1024)) ?: [] as $l) {
            if (partnerTicketLineIsOurs($l) && preg_match('/uso-ticket:([0-9a-f]{8})$/D', trim($l), $m) && !isset($keep[$m[1]])) {
                partnerAuthKeysEdit($m[1], null, null, true);
                partnerLog("a ticket line (uso-ticket:{$m[1]}) without its ticket - removed");
                $gone++;
            }
        }
        if (is_file("$dir/tickets.json") && count($keep) !== count(partnerTickets())) {
            partnerTicketsWrite(array_values($keep));
        }
    }
    if (is_file("$dir/ticket-pairs.json")) {
        $pairs = partnerTicketPairs();
        $keep = array_values(array_filter($pairs, fn ($p) => $p['expires'] > $now));
        foreach ($pairs as $p) {
            if ($p['expires'] <= $now) {
                partnerKeyDrop($p['id']);
                partnerLog("the ticket of {$p['name']} (copies of {$p['of']}) ended - its key gone");
                $gone++;
            }
        }
        if (count($keep) !== count($pairs)) {
            partnerTicketPairsWrite($keep);
        }
    }
    if (is_file("$dir/ticket-pending.json")) {
        partnerTicketPending(true);
    }
    return $gone;
}

/** The tickets for the Team Lead's cards — never a key: given (holder, per pair), held (new server), asked for */
function partnerTicketsPublic(): array
{
    $given = [];
    $keys = null;
    foreach (partnerTickets() as $t) {
        $keys ??= (string) @file_get_contents(partnerAuthKeysFile(), false, null, 0, 1024 * 1024);
        $line = 'closed';
        foreach (preg_split('/\r?\n/', $keys) ?: [] as $l) {
            if (partnerTicketLineIsOurs($l, $t['id'])) {
                $line = 'open';
            }
        }
        $given[$t['of']][] = ['id' => $t['id'], 'name' => $t['name'], 'address' => $t['address'], 'created' => $t['created'], 'expires' => $t['expires'],
                              'units' => $t['units'], 'door' => $line, 'expired' => $t['expires'] <= time()];
    }
    $held = array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'of' => $p['of'], 'address' => $p['address'], 'port' => $p['port'], 'units' => $p['units'],
        'expires' => $p['expires'], 'paired' => $p['paired'], 'last_heard' => $p['last_heard'], 'expired' => $p['expires'] <= time()], partnerTicketPairs());
    $asked = array_map(fn ($q) => ['id' => $q['id'], 'created' => $q['created'], 'address' => $q['address']], partnerTicketPending());
    return ['given' => $given, 'lists' => ['ticket_pairs' => $held, 'ticket_requests' => $asked]];
}

/** «Start from a partner's copy…» (the new server): look, do (a key and BLOCK-N), show (BLOCK-N again) */
function partner_ticket_start(array $r): array
{
    $step = optText($r, 'step', 'look');
    if ($step === 'look') {
        return ['ok' => true, 'name' => partnerMyName(), 'addresses' => partnerMyAddresses()];
    }
    if ($step === 'show') {
        foreach (partnerTicketPending(true) as $q) {
            if ($q['id'] === ($r['id'] ?? null)) {
                return ['ok' => true, 'id' => $q['id'], 'block' => partnerTicketBlockN($q)];
            }
        }
        throw new Problem('partner_unknown');
    }
    if ($step !== 'do') {
        throw new Problem('bad_request');
    }
    $address = trim(optText($r, 'address'));
    if (!partnerAddressValid($address)) {
        throw new Problem('partner_address');
    }
    $pending = partnerTicketPending(true);
    $id = partnerNewId();
    $pub = partnerKeyMake($id);
    $q = ['id' => $id, 'created' => time(), 'name' => partnerMyName(), 'address' => $address, 'pub_key' => $pub];
    try {
        partnerWritePrivate(partnerDir() . '/ticket-pending.json', ['requests' => array_merge($pending, [$q])]);
    } catch (Throwable $e) {
        partnerKeyDrop($id);
        throw $e;
    }
    partnerLog("asked for a restore ticket ($id, address $address)");
    return ['ok' => true, 'id' => $id, 'block' => partnerTicketBlockN($q), 'partners' => partnerPublic()];
}

function partnerTicketBlockN(array $q): string
{
    return partnerBlockEncode(['v' => 1, 'block' => 'N', 'id' => $q['id'], 'name' => $q['name'], 'address' => $q['address'], 'pub_key' => $q['pub_key']]);
}

/**
 * «Hand <name>'s copies to a new server…» (the holder, on the card of the gone server): look (the new server, the
 * line, the units, until when), do (the ticket and its line, BLOCK-T and the code; with `close_door` the gone server's
 * own line goes too — its pair and its copies stay).
 */
function partner_ticket_make(array $r): array
{
    $pairId = optText($r, 'pair');
    $pair = preg_match(PARTNER_ID_RE, $pairId) ? partnerPair($pairId) : null;
    if ($pair === null) {
        throw new Problem('partner_unknown');
    }
    if ($pair['receive'] === null) {
        throw new Problem('partner_ticket_nothing', ['name' => $pair['name']]);
    }
    $n = partnerTicketBlockDecode($r['block'] ?? null, 'N');
    // this office's own request (a walk-through on one server: it plays the new server too) — its id is taken here by itself
    $own = (bool) array_filter(partnerTicketPending(), fn ($q) => $q['id'] === $n['id'] && $q['pub_key'] === $n['pub_key']);
    $taken = array_merge(array_column(partnerPairs(), 'id'), array_column(partnerPending(), 'id'), array_column(partnerTickets(), 'id'),
        array_column(partnerTicketPairs(), 'id'), $own ? [] : array_column(partnerTicketPending(), 'id'));
    if (in_array($n['id'], $taken, true)) {
        throw new Problem('partner_known', ['name' => $n['name']]);
    }
    if (in_array($n['pub_key'], partnerHostKeys(), true)) {
        throw new Problem('partner_block', ['field' => 'pub_key']);
    }
    $from = partnerFromList($n['address']);
    $now = time();
    $expires = $now + PARTNER_TICKET_DAYS * 86400;
    $step = optText($r, 'step', 'look');
    if ($step === 'look') {
        return ['ok' => true, 'new' => ['id' => $n['id'], 'name' => $n['name'], 'address' => $n['address'], 'public' => !partnerAddressPrivate($n['address']),
                'key' => partnerFingerprint($n['pub_key'])], 'of' => $pair['name'], 'units' => $pair['receive']['units'], 'expires' => $expires,
                'line' => $from !== null ? partnerTicketLine($n['id'], $from, $n['pub_key'], $expires) : null, 'name' => partnerMyName(),
                'addresses' => partnerMyAddresses(), 'port' => partnerMyPort(), 'host_key' => partnerHostKeys() !== []];
    }
    if ($step !== 'do' || ($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    if ($from === null) {
        throw new Problem('partner_unresolved', ['address' => $n['address']]);
    }
    $host = partnerHostKeys();
    if (!$host) {
        throw new Problem('partner_host_key');
    }
    [$address, $port] = partnerAddressFrom($r);
    $ticket = ['id' => $n['id'], 'of' => $pair['id'], 'name' => $n['name'], 'address' => $n['address'], 'from' => $from,
               'key' => (string) partnerFingerprint($n['pub_key']), 'created' => $now, 'expires' => $expires, 'units' => $pair['receive']['units']];
    $tickets = partnerTickets();
    // the record first, then the line: the night watchman takes a line written right after its ticket for the office's own
    partnerTicketsWrite(array_merge($tickets, [$ticket]));
    try {
        partnerAuthKeysEdit($n['id'], partnerTicketLine($n['id'], $from, $n['pub_key'], $expires), null, true);
    } catch (Throwable $e) {
        partnerTicketsWrite($tickets);
        throw $e;
    }
    $closed = false;
    if (($r['close_door'] ?? null) === true && partnerAuthKeysEdit($pair['id'], null)) {
        $closed = true;
    }
    partnerLog("ticket {$n['id']} for {$n['name']} ({$n['address']}): the copies of {$pair['name']} ({$pair['id']}) until " . date('Y-m-d H:i', $expires)
        . ($closed ? " - {$pair['name']}'s own line removed" : ''));
    $block = partnerBlockEncode(['v' => 1, 'block' => 'T', 'id' => $n['id'], 'name' => partnerMyName(), 'of' => $pair['name'], 'address' => $address,
        'port' => $port, 'host_keys' => $host, 'units' => $pair['receive']['units'], 'expires' => $expires]);
    return ['ok' => true, 'block' => $block, 'code' => partnerTicketCode($n['pub_key'], $host), 'expires' => $expires, 'closed' => $closed,
            'public' => !partnerAddressPrivate($address), 'partners' => partnerPublic()];
}

/** «Paste the ticket» (the new server): look (the code, the holder, the units), do («They match»: the ticket pair, a ping) */
function partner_ticket_finish(array $r): array
{
    $t = partnerTicketBlockDecode($r['block'] ?? null, 'T');
    $q = null;
    foreach (partnerTicketPending(true) as $x) {
        $q = $x['id'] === $t['id'] ? $x : $q;
    }
    if ($q === null) {
        throw new Problem(partnerTicketPair($t['id']) !== null ? 'partner_known' : 'partner_unknown', ['name' => $t['name']]);
    }
    if ($t['expires'] <= time()) {
        throw new Problem('partner_ticket_expired', ['when' => date('Y-m-d H:i', $t['expires'])]);
    }
    if (in_array($q['pub_key'], $t['host_keys'], true)) {
        throw new Problem('partner_block', ['field' => 'host_keys']);
    }
    $code = partnerTicketCode($q['pub_key'], $t['host_keys']);
    $facts = ['id' => $t['id'], 'name' => $t['name'], 'of' => $t['of'], 'address' => $t['address'], 'port' => $t['port'], 'public' => !partnerAddressPrivate($t['address']),
              'host_keys' => array_map('partnerFingerprint', $t['host_keys']), 'units' => $t['units'], 'expires' => $t['expires']];
    if (($r['step'] ?? 'look') === 'look') {
        return ['ok' => true, 'ticket' => $facts, 'code' => $code];
    }
    if (($r['step'] ?? '') !== 'do' || ($r['confirm'] ?? null) !== true || ($r['code'] ?? null) !== $code) {
        throw new Problem('bad_request');
    }
    $pairs = partnerTicketPairs();
    $tp = ['id' => $t['id'], 'kind' => 'ticket', 'name' => $t['name'], 'of' => $t['of'], 'address' => $t['address'], 'port' => $t['port'],
           'host_keys' => array_values(array_map('partnerFingerprint', $t['host_keys'])), 'my_key' => (string) partnerFingerprint($q['pub_key']),
           'units' => $t['units'], 'expires' => $t['expires'], 'paired' => time(), 'last_heard' => null];
    partnerKnownWrite($t['id'], $t['address'], $t['port'], $t['host_keys']);
    try {
        partnerTicketPairsWrite(array_merge($pairs, [$tp]));
    } catch (Throwable $e) {
        @unlink(partnerKnownFile($t['id']));
        throw $e;
    }
    partnerWritePrivate(partnerDir() . '/ticket-pending.json', ['requests' => array_values(array_filter(partnerTicketPending(), fn ($x) => $x['id'] !== $t['id']))]);
    partnerLog("ticket {$t['id']} from {$t['name']} taken - the copies of {$t['of']} until " . date('Y-m-d H:i', $t['expires']) . ', the codes matched');
    return ['ok' => true, 'ask' => partnerTicketAsk($t['id']), 'partners' => partnerPublic()];
}

/** «End» a ticket: the holder's (its line and record), a new server's ticket pair (its key and pin), or a request */
function partner_ticket_end(array $r): array
{
    $id = optText($r, 'id');
    if (!preg_match(PARTNER_ID_RE, $id)) {
        throw new Problem('bad_request');
    }
    $done = false;
    $tickets = partnerTickets();
    if (partnerTicket($id) !== null) {
        partnerAuthKeysEdit($id, null, null, true);
        partnerTicketsWrite(array_values(array_filter($tickets, fn ($t) => $t['id'] !== $id)));
        partnerLog("ticket $id ended by hand - its line removed");
        $done = true;
    }
    $pairs = partnerTicketPairs();
    if (partnerTicketPair($id) !== null) {
        partnerKeyDrop($id);
        partnerTicketPairsWrite(array_values(array_filter($pairs, fn ($p) => $p['id'] !== $id)));
        partnerLog("the ticket $id ended by hand - its key gone");
        $done = true;
    }
    $pending = partnerTicketPending();
    if (in_array($id, array_column($pending, 'id'), true)) {
        partnerKeyDrop($id);
        partnerWritePrivate(partnerDir() . '/ticket-pending.json', ['requests' => array_values(array_filter($pending, fn ($q) => $q['id'] !== $id))]);
        partnerLog("the request for a ticket $id withdrawn");
        $done = true;
    }
    if (!$done) {
        throw new Problem('partner_unknown');
    }
    return ['ok' => true, 'partners' => partnerPublic()];
}
