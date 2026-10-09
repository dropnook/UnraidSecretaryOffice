<?php
declare(strict_types=1);

/*
 * Partner offices as the other desks see them (stage 2 of briefs/uso-partner-zfs-plan.md): read-only looks at what the
 * Team Lead's pairing and the door leave behind — for the night watchman, Ms. Snapshotini and Ms. Dustdevil. Nothing
 * here writes; nothing runs ssh; only partnerLookPlaces() runs one `zfs list` (never from a tick). The pairing, the
 * door and their files are agent/lib/partner.php's and agent/partner-door.php's — read here exactly as they write them:
 *
 *   data/partner/pairs.json            partnerPairs() — the pairs in the office's shape (root only)
 *   data/partner/received/<id>.json    what the door received of a pair (the newest time of its units)
 *   data/partner/deletes.jsonl         what the door's retention destroyed (read by the night watchman by offset)
 *   RUN_DIR/partner/door-<pid>.json    a transfer going on (the door's process, its pair, unit, dataset)
 *   RUN_DIR/partner/refused-<id>.json  the times of a pair's recent refusals (the last hour)
 *   authorized_keys                    the office's lines (comment uso-partner:<id>), parsed — never their key kept
 *   <pool>/UnraidSecretaryOffice-partners/<id>/<unit>   the copies kept here for a pair
 */

require_once __DIR__ . '/partner.php';

const PARTNER_LOOK_TRASH = OFFICE_STOREROOM;      // Ms. Dustdevil's storeroom (CL_TRASH): a dataset put away is renamed <it>-<stamp>-<name> (also by its old name)
const PARTNER_LOOK_LINE_MAX = 4096;                                // a line of authorized_keys longer than this is no line of the office's
const PARTNER_LOOK_FILE_MAX = 65536;                               // the door's small records

/**
 * A dataset or snapshot under a pool's partners' place: pool, the pair's id (null: the place itself or something not
 * of the office's shape), the unit's dataset name below it, whether it is one Ms. Dustdevil put away (then `id` is
 * the pair it was of). Null: not under UnraidSecretaryOffice-partners.
 *
 * @return array{pool: string, id: ?string, unit: ?string, trash: bool}|null
 */
function partnerLookDataset(string $name): ?array
{
    $ds = explode('@', $name, 2)[0];
    $parts = explode('/', $ds);
    if (count($parts) < 2 || $parts[1] !== PARTNER_PARENT || !preg_match(PARTNER_POOL_RE, $parts[0])) {
        return null;
    }
    $id = $parts[2] ?? null;
    $trash = false;
    if ($id !== null && (str_starts_with($id, PARTNER_LOOK_TRASH . '-') || str_starts_with($id, OFFICE_STOREROOM_OLD . '-'))) {
        $trash = true;
        $id = preg_match('/-([0-9a-f]{8})$/D', $id, $m) ? $m[1] : null;
    } elseif ($id !== null && !preg_match(PARTNER_ID_RE, $id)) {
        $id = null;
    }
    return ['pool' => $parts[0], 'id' => $id, 'unit' => count($parts) > 3 ? implode('/', array_slice($parts, 3)) : null, 'trash' => $trash];
}

/**
 * The pairs as the other desks need them — never a key: id => name, address, the addresses it is known by (an IP
 * address as it is; a host name: none here — the door's line names what it resolved to), the fingerprint of their
 * key in my authorized_keys (null: they send nothing here), whether I send there, whether I keep copies of theirs,
 * trust, paired, whether its address faces the internet. Empty: no pairs (or no pairs file in the office's shape).
 *
 * @return array<string, array{id: string, name: string, address: string, ips: list<string>, fp: ?string, sends: bool, receives: bool,
 *                              trust: string, paired: int, public: bool, pool: ?string}>
 */
function partnerLookPairs(?string $file = null): array
{
    $out = [];
    foreach (partnerPairs($file) as $p) {
        $out[$p['id']] = ['id' => $p['id'], 'name' => $p['name'], 'address' => $p['address'],
                          'ips' => filter_var($p['address'], FILTER_VALIDATE_IP) ? [partnerLookIp($p['address'])] : [],
                          'fp' => $p['their_key'], 'sends' => $p['send']['units'] !== [], 'receives' => $p['receive'] !== null,
                          'trust' => $p['trust'], 'paired' => $p['paired'], 'public' => !partnerAddressPrivate($p['address']),
                          'pool' => $p['receive']['pool'] ?? null];
    }
    return $out;
}

/** An address written one way (IPv4 plain, also from ::ffff:a.b.c.d; IPv6 short) */
function partnerLookIp(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return $ip;
    }
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    return (string) inet_ntop($bin);
}

/**
 * The options in front of a key in an authorized_keys line (sshd's grammar: name or name="value", commas between,
 * a quote escaped by a backslash; the first unquoted blank ends them) and the rest. Null: a line that doesn't parse.
 *
 * @return array{0: list<array{0: string, 1: ?string}>, 1: string}|null
 */
function partnerLookOptions(string $line): ?array
{
    if (preg_match('/^(?:ssh-|ecdsa-|sk-)/', $line)) {
        return [[], $line];
    }
    $opts = [];
    $i = 0;
    $n = strlen($line);
    while ($i < $n) {
        if (!preg_match('/\G([A-Za-z0-9-]+)/', $line, $m, 0, $i)) {
            return null;
        }
        $name = strtolower($m[1]);
        $i += strlen($m[1]);
        $value = null;
        if ($i < $n && $line[$i] === '=') {
            if (++$i >= $n || $line[$i] !== '"') {
                return null;
            }
            $value = '';
            for ($i++; $i < $n && $line[$i] !== '"'; $i++) {
                if ($line[$i] === '\\' && $i + 1 < $n && $line[$i + 1] === '"') {
                    $i++;
                }
                $value .= $line[$i];
            }
            if ($i >= $n) {
                return null;                // an open quote
            }
            $i++;
        }
        $opts[] = [$name, $value];
        if ($i < $n && $line[$i] === ',') {
            $i++;
            continue;
        }
        if ($i < $n && ($line[$i] === ' ' || $line[$i] === "\t")) {
            return [$opts, ltrim(substr($line, $i))];
        }
        return null;
    }
    return null;
}

/**
 * The office's lines in an authorized_keys file (comment uso-partner:<id>), each as what it allows — never its key:
 * id => fingerprint, restrict, from (as written), command, the other options, whether it is exactly the line
 * partnerDoorLine() writes for that id, from and key (`exact`), a hash of the line (a change shows). The first line of
 * an id counts (a second one is `twice`). Missing or unreadable file: none.
 *
 * @return array<string, array{fp: ?string, restrict: bool, from: ?string, command: ?string, others: list<string>, exact: bool, h: string, twice: bool}>
 */
function partnerLookLines(string $file): array
{
    $text = @file_get_contents($file, false, null, 0, 1024 * 1024);
    $out = [];
    foreach (is_string($text) ? preg_split('/\r?\n/', $text) : [] as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#' || strlen($line) > PARTNER_LOOK_LINE_MAX || !partnerLineIsOurs($line)
            || !preg_match('/uso-partner:([0-9a-f]{8})$/D', $line, $m)) {
            continue;
        }
        $id = $m[1];
        if (isset($out[$id])) {
            $out[$id]['twice'] = true;
            continue;
        }
        $parsed = partnerLookOptions($line);
        $opts = $parsed[0] ?? [];
        $rest = $parsed[1] ?? '';
        $restrict = false;
        $from = $command = null;
        $others = [];
        foreach ($opts as [$name, $value]) {
            if ($name === 'restrict' && $value === null) {
                $restrict = true;
            } elseif ($name === 'from' && $value !== null && $from === null) {
                $from = $value;
            } elseif ($name === 'command' && $value !== null && $command === null) {
                $command = $value;
            } else {
                $others[] = $name;
            }
        }
        $key = preg_match('/^(ssh-ed25519 [A-Za-z0-9+\/]+=*) uso-partner:[0-9a-f]{8}$/D', $rest, $k) ? partnerKeyNorm($k[1]) : null;
        $fp = null;
        if (preg_match('/^((?:ssh|ecdsa|sk)-[a-z0-9@.-]{1,60}) ([A-Za-z0-9+\/]{20,}={0,3})(?: |$)/', $rest, $b) && ($blob = base64_decode($b[2], true)) !== false) {
            $fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        }
        $out[$id] = ['fp' => $fp, 'restrict' => $restrict, 'from' => $from, 'command' => $command, 'others' => $others,
                     'exact' => $parsed !== null && $key !== null && $from !== null && $line === partnerDoorLine($id, $from, $key),
                     'h' => substr(hash('sha256', $line), 0, 16), 'twice' => false];
    }
    return $out;
}

/** The addresses a from="…" names that are IP addresses (a pattern or a name is not one) */
function partnerLookFromIps(?string $from): array
{
    $out = [];
    foreach ($from === null ? [] : explode(',', $from) as $x) {
        if (filter_var($x, FILTER_VALIDATE_IP)) {
            $out[] = partnerLookIp($x);
        }
    }
    return array_values(array_unique($out));
}

/** A small record of the door's: a plain file of root's (or the process's own user), closed to others, at most a few KB */
function partnerLookRecord(string $file): ?array
{
    clearstatcache(true, $file);
    $st = @lstat($file);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 0022) || $st['size'] > PARTNER_LOOK_FILE_MAX
        || ($st['uid'] !== 0 && $st['uid'] !== posix_geteuid())) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($file, false, null, 0, PARTNER_LOOK_FILE_MAX), true);
    return is_array($j) ? $j : null;
}

/**
 * The door's transfers going on now (RUN_DIR/partner/door-<pid>.json while its process lives).
 *
 * @return list<array{pid: int, pair: string, unit: string, dataset: string, since: int}>
 */
function partnerLookDoors(string $runDir, ?callable $alive = null): array
{
    $alive ??= fn (int $pid): bool => $pid > 1 && is_dir("/proc/$pid");
    $out = [];
    foreach (glob("$runDir/door-*.json") ?: [] as $f) {
        $r = preg_match('/door-(\d{1,10})\.json$/D', $f, $m) ? partnerLookRecord($f) : null;
        if ($r === null || ($r['pid'] ?? null) !== (int) $m[1] || !is_string($r['pair'] ?? null) || !preg_match(PARTNER_ID_RE, $r['pair'])
            || !$alive((int) $m[1])) {
            continue;
        }
        $out[] = ['pid' => (int) $m[1], 'pair' => $r['pair'], 'unit' => is_string($r['unit'] ?? null) ? $r['unit'] : '',
                  'dataset' => is_string($r['dataset'] ?? null) ? $r['dataset'] : '', 'since' => (int) ($r['since'] ?? 0)];
    }
    return $out;
}

/**
 * Each pair's recent refusals at the door (RUN_DIR/partner/refused-<id>.json: their times within the last hour, the
 * last reason) — id => [times (sorted), last].
 *
 * @return array<string, array{times: list<int>, last: string}>
 */
function partnerLookRefused(string $runDir): array
{
    $out = [];
    foreach (glob("$runDir/refused-*.json") ?: [] as $f) {
        if (!preg_match('/refused-([0-9a-f]{8})\.json$/D', $f, $m) || ($r = partnerLookRecord($f)) === null || ($r['pair'] ?? null) !== $m[1]) {
            continue;
        }
        $times = array_values(array_filter((array) ($r['times'] ?? []), 'is_int'));
        sort($times);
        $last = is_string($r['last'] ?? null) && preg_match('/^[a-z_]{1,40}$/D', $r['last']) ? $r['last'] : '';
        $out[$m[1]] = ['times' => array_slice($times, -200), 'last' => $last];
    }
    return $out;
}

/** When the door last received something of each pair (data/partner/received/<id>.json, root only): id => time */
function partnerLookReceived(string $dir): array
{
    $out = [];
    foreach (glob("$dir/*.json") ?: [] as $f) {
        if (!preg_match('/\/([0-9a-f]{8})\.json$/D', $f, $m) || ($r = partnerReadPrivate($f)) === null || ($r['pair'] ?? null) !== $m[1]) {
            continue;
        }
        $t = 0;
        foreach ((array) ($r['units'] ?? []) as $u) {
            $t = max($t, is_array($u) ? (int) ($u['time'] ?? 0) : 0);
        }
        $out[$m[1]] = max($t, (int) ($r['time'] ?? 0));
    }
    return $out;
}

/**
 * The partners' places on the pools named (awake ones only — the caller asks): one `zfs list` of their datasets
 * three levels deep. Per pool: the place's own size, per pair id what its copies take (with their snapshots) and
 * its units (`snaps`: what the snapshots of it and its units hold; `unit_snaps`: per unit), what Ms. Dustdevil put away
 * there. Null: zfs didn't answer.
 *
 * @param list<string> $pools
 * @return array<string, array{dataset: string, used: int, ids: array<string, array{dataset: string, used: int, snaps: int, units: array<string, int>, unit_snaps: array<string, int>}>,
 *                             trash: array<string, array{id: ?string, used: int, snaps: int}>}>|null
 */
function partnerLookPlaces(array $pools, ?callable $zfs = null): ?array
{
    $pools = array_values(array_filter($pools, fn ($p) => is_string($p) && preg_match(PARTNER_POOL_RE, $p)));
    if (!$pools) {
        return [];
    }
    $zfs ??= function (array $args): array {
        $bin = partnerBin('zfs');
        return $bin === null ? [127, ''] : run(array_merge([$bin], $args), 60);
    };
    [$exit, $text] = $zfs(array_merge(['list', '-Hp', '-t', 'filesystem', '-o', 'name,used,usedbysnapshots', '-d', '3'], $pools));
    if ($exit !== 0 && trim((string) $text) === '') {
        return null;
    }
    $out = [];
    foreach (preg_split('/\n/', (string) $text) ?: [] as $line) {
        $f = explode("\t", trim($line));
        if (count($f) < 3 || !ctype_digit($f[1]) || !ctype_digit($f[2]) || ($d = partnerLookDataset($f[0])) === null || !in_array($d['pool'], $pools, true)) {
            continue;
        }
        $pool = $d['pool'];
        $out[$pool] ??= ['dataset' => "$pool/" . PARTNER_PARENT, 'used' => 0, 'ids' => [], 'trash' => []];
        $used = (int) $f[1];
        $snaps = (int) $f[2];
        $top = implode('/', array_slice(explode('/', $f[0]), 0, 3));
        if ($f[0] === "$pool/" . PARTNER_PARENT) {
            $out[$pool]['used'] = $used;
        } elseif ($d['trash'] && $d['unit'] === null) {
            $out[$pool]['trash'][$f[0]] = ['id' => $d['id'], 'used' => $used, 'snaps' => $snaps];
        } elseif (!$d['trash'] && $d['id'] !== null && $d['unit'] === null) {
            $out[$pool]['ids'][$d['id']] = ['dataset' => $f[0], 'used' => $used, 'snaps' => $snaps] + ($out[$pool]['ids'][$d['id']] ?? ['units' => [], 'unit_snaps' => []]);
        } elseif (!$d['trash'] && $d['id'] !== null && !str_contains((string) $d['unit'], '/')) {
            $out[$pool]['ids'][$d['id']] ??= ['dataset' => $top, 'used' => 0, 'snaps' => 0, 'units' => [], 'unit_snaps' => []];
            $out[$pool]['ids'][$d['id']]['units'][(string) $d['unit']] = $used;
            $out[$pool]['ids'][$d['id']]['unit_snaps'][(string) $d['unit']] = $snaps;
            $out[$pool]['ids'][$d['id']]['snaps'] += $snaps;         // what its units' snapshots hold (`used` has them already)
        }
    }
    ksort($out);
    return $out;
}

/** The unit a received dataset holds, in the words of the pairing: share-appdata → share:appdata, vm-Debian → vm:Debian, place */
function partnerLookUnit(string $datasetUnit): string
{
    return preg_match('/^(share|vm)-(.+)$/D', $datasetUnit, $m) ? "$m[1]:$m[2]" : $datasetUnit;
}

/**
 * The restore tickets' lines in authorized_keys (comment uso-ticket:<id>, stage 3) — like partnerLookLines(): what each
 * allows, a hash, the key's fingerprint, whether it is exactly as partnerTicketLine() writes it (its expiry-time read
 * back); never the key kept.
 *
 * @return array<string, array{fp:?string, restrict:bool, from:?string, command:?string, others:list<string>, expires:?int, exact:bool, h:string, twice:bool}>
 */
function partnerLookTicketLines(string $file): array
{
    $text = @file_get_contents($file, false, null, 0, 1024 * 1024);
    $out = [];
    foreach (is_string($text) ? preg_split('/\r?\n/', $text) : [] as $raw) {
        $line = trim($raw);
        if ($line === '' || strlen($line) > PARTNER_LOOK_LINE_MAX || !preg_match('/uso-ticket:([0-9a-f]{8})$/D', $line, $m)) {
            continue;
        }
        $id = $m[1];
        if (isset($out[$id])) {
            $out[$id]['twice'] = true;
            continue;
        }
        $parsed = partnerLookOptions($line);
        $restrict = false;
        $from = $command = $expiry = null;
        $others = [];
        foreach ($parsed[0] ?? [] as [$name, $value]) {
            if ($name === 'restrict' && $value === null) {
                $restrict = true;
            } elseif ($name === 'from' && $value !== null && $from === null) {
                $from = $value;
            } elseif ($name === 'command' && $value !== null && $command === null) {
                $command = $value;
            } elseif ($name === 'expiry-time' && $value !== null && $expiry === null) {
                $expiry = $value;
            } else {
                $others[] = $name;
            }
        }
        $rest = $parsed[1] ?? '';
        $expires = $expiry !== null && preg_match('/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)Z$/D', $expiry, $e) ? (int) gmmktime((int) $e[4], (int) $e[5], 0, (int) $e[2], (int) $e[3], (int) $e[1]) : null;
        $key = preg_match('/^(ssh-ed25519 [A-Za-z0-9+\/]+=*) uso-ticket:[0-9a-f]{8}$/D', $rest, $k) ? partnerKeyNorm($k[1]) : null;
        $fp = null;
        if (preg_match('/^((?:ssh|ecdsa|sk)-[a-z0-9@.-]{1,60}) ([A-Za-z0-9+\/]{20,}={0,3})(?: |$)/', $rest, $b) && ($blob = base64_decode($b[2], true)) !== false) {
            $fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        }
        $out[$id] = ['fp' => $fp, 'restrict' => $restrict, 'from' => $from, 'command' => $command, 'others' => $others, 'expires' => $expires,
                     'exact' => $parsed !== null && $key !== null && $from !== null && $expires !== null && $line === partnerTicketLine($id, $from, $key, $expires),
                     'h' => substr(hash('sha256', $line), 0, 16), 'twice' => false];
    }
    return $out;
}

/**
 * The restore tickets this office gave (data/partner/tickets.json, root only, in its shape) — never a key: id => name,
 * the addresses it may come from, its key's fingerprint, the pair whose copies it hands out (name), created, expires.
 *
 * @return array<string, array{id:string, name:string, address:string, ips:list<string>, fp:string, of:string, of_name:string, created:int, expires:int}>
 */
function partnerLookTickets(string $file, array $pairs = []): array
{
    $j = partnerReadPrivate($file);
    $out = [];
    foreach (is_array($j) && ($j['v'] ?? null) === 1 ? (array) ($j['tickets'] ?? []) : [] as $t) {
        if (!partnerTicketValid($t)) {
            continue;
        }
        $out[$t['id']] = ['id' => $t['id'], 'name' => $t['name'], 'address' => $t['address'], 'ips' => partnerLookFromIps($t['from']), 'fp' => $t['key'],
                          'of' => $t['of'], 'of_name' => (string) ($pairs[$t['of']]['name'] ?? $t['of']), 'created' => $t['created'], 'expires' => $t['expires']];
    }
    return $out;
}
