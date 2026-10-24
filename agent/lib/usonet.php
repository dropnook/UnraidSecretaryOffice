<?php
declare(strict_types=1);

/*
 * The USO network (the concept «uso-partner-tunnel», owner's decisions D1–D10, 2026-10-22): the office's own Tailscale
 * node per server, underneath the partner pairing only. Two or more offices join one tailnet as tagged devices
 * (tag:uso); then they pair exactly as today over the node's 100.x address — blocks, safety code, door line with its
 * from=, the key and the door unchanged. The host side is scripts/usonet.sh (the namespace uso-net, the helper, the
 * office's sshd in it, its cron line); this is the office's side: joining and leaving, fetching the pinned helper, the
 * status for the page, the Team Lead's checks, and the one rule the partner code asks (usonetWrap(): a pair whose
 * address is a peer of this node is called from inside the namespace).
 *
 *   /boot/config/plugins/unraid-secretary-office/usonet/   usonet.cfg (JOINED, NAME, CONTROL), the helper
 *                         (uso-net-<v>-linux-amd64, checked by USONET_HELPER's SHA-256 at every start), state/ (the
 *                         node's key — written when it logs in), authkey (0600, only until the node is in)
 *   RUN_DIR/usonet/       status.json (the helper's, every 10–30 s), join.json (the last join's outcome), the logs
 *
 * Nothing of the user's own Tailscale, Unraid's Tailscale integration, VPN Manager, routes, DNS, firewall or Unraid's
 * sshd is touched; leaving (or the plugin's remove) takes everything of it away (usonet.sh leave).
 * Everything here reads files only (RAM and the flash) — partner.php loads it, and so does the door.
 */

/** The helper the office runs — pinned: a new one only with a new office version (D7). helper/uso-net.sha256 and
 *  scripts/usonet.sh carry the same (testUsoNetPin). */
const USONET_HELPER = [
    'version' => '1',
    'file'    => 'uso-net-1-linux-amd64',
    'url'     => 'https://github.com/dropnook/UnraidSecretaryOffice/releases/download/uso-net-1/uso-net-1-linux-amd64',
    'sha256'  => 'efb987e2e33734b2f53e62d2f2bb17b27c88d0ec63af8c87bc2cc3e47ea0c9cd',
    'bytes'   => 22081660,
];
const USONET_NETNS      = 'uso-net';
const USONET_TAG        = 'tag:uso';
const USONET_UDP_PORT   = 41642;            // not 41641: the user's own Tailscale may use that one
const USONET_KEY_RE     = '/^(?:tskey-[A-Za-z0-9-]{10,200}|hskey-[A-Za-z0-9_-]{10,200}|[0-9a-f]{32,128})$/D';     // Tailscale's, Headscale's
const USONET_CONTROL_RE = '/^https?:\/\/[][A-Za-z0-9.:_-]{1,255}(\/[A-Za-z0-9._\/-]{0,200})?$/D';                // as usonet.sh's CONTROL_RE
const USONET_STALE      = 90;               // a status older than this: the helper doesn't answer
const USONET_DOWN_AFTER = 300;              // the helper away for so long: the Team Lead's must
const USONET_NO_CONTACT = 3600;             // no contact with the coordination server for so long: his recommendation
const USONET_JOIN_WAIT  = 90;               // the join job waits so long for the node to be in
const USONET_FETCH_MAX  = 64 << 20;         // the download is never larger
const USONET_SLOW_AFTER = 180;              // a join or leave under way for so long: the card says so, with the log's last lines
const USONET_JOB_QUEUED = 180;              // a job atd hasn't started within so long is given up
const USONET_JOB_MAX    = 900;              // no job lives longer (the fetch alone may take 300 s)
const USONET_PROBE_EVERY = 180;             // one small probe per relayed peer at most so often (the path goes direct with traffic)
const USONET_ANSWER_FOR = 900;              // a probe's answer counts so long (usonetAnswered(): which node of a name is the live one)

function usonetFlashDir(): string
{
    return rtrim(getenv('USONET_FLASH') ?: '/boot/config/plugins/unraid-secretary-office/usonet', '/');
}

function usonetRunDir(): string
{
    return rtrim(getenv('USONET_RUN') ?: officeRunDir() . '/usonet', '/');
}

function usonetScript(): string
{
    return getenv('USONET_SCRIPT') ?: dirname(__DIR__, 2) . '/scripts/usonet.sh';
}

/** usonet.cfg: JOINED, NAME, CONTROL (key="value" lines) */
function usonetConfig(): array
{
    $file = usonetFlashDir() . '/usonet.cfg';
    return is_file($file) && !is_link($file) ? readCfg($file) : [];
}

function usonetJoined(): bool
{
    return (usonetConfig()['JOINED'] ?? '') === 'yes';
}

/** A list of strings of at most $max entries, each matching $re */
function usonetStrings(mixed $v, string $re, int $max = 16): bool
{
    if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
        return false;
    }
    foreach ($v as $x) {
        if (!is_string($x) || !preg_match($re, $x)) {
            return false;
        }
    }
    return true;
}

/** The helper's status.json in exactly its shape (helper/main.go `status`) — anything else is no status */
function usonetStatusValid(mixed $s): bool
{
    $ip = '/^[0-9a-fA-F.:]{2,45}$/D';
    $tag = '/^tag:[A-Za-z0-9-]{1,64}$/D';
    if (!is_array($s) || ($s['v'] ?? null) !== 1 || !is_int($s['time'] ?? null) || !is_string($s['backend'] ?? null)
        || !preg_match('/^[A-Za-z]{1,20}$/D', $s['backend']) || !is_string($s['name'] ?? null) || strlen($s['name']) > 100
        || !is_bool($s['control'] ?? null) || !is_bool($s['netns'] ?? null) || !usonetStrings($s['ips'] ?? null, $ip, 4)
        || !usonetStrings($s['tags'] ?? null, $tag) || !is_array($s['peers'] ?? null) || !array_is_list($s['peers'])
        || count($s['peers']) > 500) {
        return false;
    }
    foreach ($s['peers'] as $p) {
        if (!is_array($p) || !is_string($p['name'] ?? null) || strlen($p['name']) > 100 || !usonetStrings($p['ips'] ?? null, $ip, 4)
            || !usonetStrings($p['tags'] ?? null, $tag) || !is_bool($p['online'] ?? null) || !is_bool($p['direct'] ?? null)
            || !is_string($p['relay'] ?? null) || strlen($p['relay']) > 32 || !is_int($p['last_seen'] ?? null)) {
            return false;
        }
    }
    return true;
}

/** The helper's status, or null (not joined here, not running, or not in its shape) */
function usonetStatus(): ?array
{
    $file = usonetRunDir() . '/status.json';
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file)) {
        return null;
    }
    $s = json_decode((string) @file_get_contents($file, false, null, 0, 1 << 20), true);
    return usonetStatusValid($s) ? $s : null;
}

/** An address as one canonical form (100.064.0.1 is no IP; fd7a::0001 = fd7a::1) — null when it is no IP */
function usonetIp(string $a): ?string
{
    $bin = filter_var($a, FILTER_VALIDATE_IP) ? @inet_pton($a) : false;
    return $bin === false ? null : inet_ntop($bin);
}

/**
 * Is $address a peer of this office's node — so the call to it goes through the USO network? Asks the node's own
 * peer list (never the 100.64/10 range: the user's own tailnet uses the same addresses). Not joined, no status: no.
 */
function usonetPeerAddress(string $address, ?array $status = null): bool
{
    $ip = usonetIp($address);
    $status ??= usonetStatus();
    if ($ip === null || $status === null) {
        return false;
    }
    foreach ($status['peers'] as $p) {
        foreach ($p['ips'] as $a) {
            if (usonetIp($a) === $ip) {
                return true;
            }
        }
    }
    return false;
}

/**
 * The partner code's one question (partnerSshArgs(); the engine's partner_ssh_cmd asks the same of the same file): a
 * command to $address runs inside the namespace when the address is a peer of this node — else as it is.
 */
function usonetWrap(array $command, string $address): array
{
    if (!usonetPeerAddress($address)) {
        return $command;
    }
    $nsenter = getenv('USONET_NSENTER') ?: '/usr/bin/nsenter';
    return array_merge([$nsenter, '--net=/run/netns/' . USONET_NETNS], $command);
}

/** The node's IPv4 while it is in and its device is up (the address this server gives its partners) — else null */
function usonetMyIp4(?array $s = null): ?string
{
    $s ??= usonetStatus();
    if ($s === null || $s['backend'] !== 'Running' || !$s['netns']) {
        return null;
    }
    foreach ($s['ips'] as $a) {
        if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $a;
        }
    }
    return null;
}

/** The node's address for the pairing's picker (partnerMyAddresses()): its IPv4, only while it is in and its device is up */
function usonetMyAddresses(): array
{
    $ip = usonetMyIp4();
    return $ip === null ? [] : [['address' => $ip, 'iface' => USONET_NETNS, 'private' => true, 'usonet' => true]];
}

/** Of a tailnet's ranges (100.64.0.0/10, fd7a:115c:a1e0::/48) — the USO network's or the user's own; nothing else */
function usonetAddressRange(string $a): bool
{
    $bin = usonetIp($a) === null ? false : @inet_pton($a);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 4) {
        return (ord($bin[0]) === 100) && (ord($bin[1]) & 0xc0) === 0x40;
    }
    return substr($bin, 0, 6) === "\xfd\x7a\x11\x5c\xa1\xe0";
}

/** Did the node at one of $ips answer the office's probe lately (usonetProbe(): its mark RUN_DIR/usonet/probe/<ip>.ok)? */
function usonetAnswered(array $ips, ?int $now = null): bool
{
    $now ??= time();
    foreach ($ips as $a) {
        $mark = usonetRunDir() . "/probe/$a.ok";
        clearstatcache(true, $mark);
        if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && is_file($mark) && $now - (int) @filemtime($mark) < USONET_ANSWER_FOR) {
            return true;
        }
    }
    return false;
}

/**
 * How live a node is among those of its name — the larger, the more (2026-10-24, nostromo ↔ StedyUnraid: every leave and
 * join anew leaves the old node listed — Tailscale's logout only expires it, the machine stays until it is removed in
 * the tailnet's console; one of them still said «online» and answered nothing, while the live one was direct): online,
 * then direct, then answered the office's probe lately, then seen last (an online node has no «last seen»: now).
 */
function usonetNodeRank(array $p, ?int $now = null): array
{
    return [$p['online'], $p['direct'], usonetAnswered($p['ips'], $now), $p['online'] ? PHP_INT_MAX : (int) $p['last_seen']];
}

/**
 * The peers grouped by name, the live node of each first (usonetNodeRank()): [name => [peer, …]]. One name, one office —
 * the others are its earlier nodes (left and joined anew), never a second partner.
 */
function usonetByName(array $peers, ?int $now = null): array
{
    $out = [];
    foreach ($peers as $p) {
        $out[$p['name']][] = $p + ['rank' => usonetNodeRank($p, $now)];
    }
    foreach ($out as &$nodes) {
        usort($nodes, fn ($a, $b) => [$b['rank'], $a['ips']] <=> [$a['rank'], $b['ips']]);
    }
    unset($nodes);
    return $out;
}

/** A peer's IPv4 (the address a pairing takes) — null when it has none */
function usonetPeerIp4(array $p): ?string
{
    foreach ($p['ips'] as $a) {
        if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $a;
        }
    }
    return null;
}

/** Every node named $node (the tagged ones with an IPv4), the live one first — as a pick's row each (name, address, online, direct, last_seen) */
function usonetNodesOf(string $node, ?array $s = null, ?int $now = null): array
{
    $s ??= usonetStatus();
    if ($s === null || usonetMyIp4($s) === null) {
        return [];
    }
    $mine = array_filter($s['peers'], fn ($p) => strtolower($p['name']) === $node && in_array(USONET_TAG, $p['tags'], true) && usonetPeerIp4($p) !== null);
    $out = [];
    foreach (usonetByName($mine, $now) as $nodes) {
        foreach ($nodes as $p) {
            $out[] = ['name' => $p['name'], 'address' => usonetPeerIp4($p), 'online' => $p['online'], 'direct' => $p['direct'],
                'last_seen' => $p['last_seen'] ?: null, 'rank' => $p['rank']];
        }
    }
    usort($out, fn ($a, $b) => $b['rank'] <=> $a['rank']);
    return $out;
}

/**
 * The USO peers a pairing can pick from: the tagged ones with an IPv4, the online ones first — name, address, whether
 * the path is direct right now; one per name, its live node (usonetNodeRank()), never this server's own earlier node.
 * Not in the network: none.
 */
function usonetPeersToPick(?array $s = null, ?int $now = null): array
{
    $s ??= usonetStatus();
    if ($s === null || usonetMyIp4($s) === null) {
        return [];
    }
    $ok = array_filter($s['peers'], fn ($p) => $p['name'] !== $s['name'] && in_array(USONET_TAG, $p['tags'], true) && usonetPeerIp4($p) !== null);
    $out = [];
    foreach (usonetByName($ok, $now) as $nodes) {
        $p = $nodes[0];
        $out[] = ['name' => $p['name'], 'address' => usonetPeerIp4($p), 'online' => $p['online'], 'direct' => $p['direct'], 'last_seen' => $p['last_seen'] ?: null];
    }
    usort($out, fn ($a, $b) => [$b['online'], $a['name']] <=> [$a['online'], $b['name']]);
    return $out;
}

/**
 * The online USO peer that is the partner named $name (a block's or a pair's name — the partner's host name, partnerMyName()):
 * its node is called as usonetNodeName() makes it of that host name (StedyUnraid → uso-stedyunraid); of several nodes of
 * that name the live one (usonetNodeRank()). Null: none online.
 */
function usonetPeerNamed(string $name, ?array $s = null): ?array
{
    foreach (usonetPeersToPick($s) as $p) {
        if ($p['online'] && strtolower($p['name']) === usonetNodeName($name)) {
            return $p;
        }
    }
    return null;
}

/**
 * A small probe to every online peer still reached over a relay (at most once per USONET_PROBE_EVERY per peer, in the
 * background): Tailscale starts every path over its relay and goes direct once traffic flows — so the card's «relayed»
 * is true only for a moment, and a copy that follows starts on the direct path. Three pings inside the namespace,
 * nothing else; nothing when the node isn't in. An answer is marked (usonet.sh probe): an earlier node still listed
 * «online» never answers, the live one does — usonetNodeRank() tells them apart.
 */
function usonetProbe(array $public, ?int $now = null): void
{
    if (($public['state'] ?? '') !== 'in') {
        return;
    }
    $now ??= time();
    $dir = usonetRunDir() . '/probe';
    foreach ($public['peers'] ?? [] as $p) {
        if (!$p['online'] || $p['direct'] || !$p['tagged']) {
            continue;
        }
        $ip = null;
        foreach ($p['ips'] as $a) {
            if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $a;
                break;
            }
        }
        if ($ip === null) {
            continue;
        }
        $stamp = "$dir/$ip";
        clearstatcache(true, $stamp);
        if ($now - (int) @filemtime($stamp) < USONET_PROBE_EVERY) {
            continue;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        @touch($stamp, $now);
        $probe = getenv('USONET_PROBE');
        // usonet.sh probe: three pings inside the namespace, an answer leaves its mark (usonetAnswered())
        $cmd = $probe ? [$probe, $ip] : ['setsid', '-f', 'bash', usonetScript(), 'probe', $ip];
        run($cmd, 5);          // setsid -f: the agent goes on at once, the pings finish on their own
    }
}

/** The node's name in the tailnet: uso-<server name> (letters, digits, dashes, ≤ 63) */
function usonetNodeName(?string $host = null): string
{
    $n = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($host ?? hostname())), '-');
    return substr('uso-' . ($n !== '' ? $n : 'unraid'), 0, 63);
}

/** What the tailnet's owner pastes into its policy (D6: shown and checked here, never edited) */
function usonetPolicyLines(): array
{
    return [
        'tailscale' => "\"tagOwners\": { \"" . USONET_TAG . "\": [\"autogroup:admin\"] },\n\"grants\": [ { \"src\": [\"" . USONET_TAG . "\"], \"dst\": [\"" . USONET_TAG . "\"], \"ip\": [\"*\"] } ]",
        'headscale' => "\"tagOwners\": { \"" . USONET_TAG . "\": [\"<user>@\"] },\n\"acls\": [ { \"action\": \"accept\", \"src\": [\"" . USONET_TAG . "\"], \"dst\": [\"" . USONET_TAG . ":*\"] } ]",
    ];
}

/** The outcome of the last join (join.json, RAM): {time, ok, why, log} or null — `log`: the logs' last lines of a failed join (the leave that follows takes the logs themselves) */
function usonetJoinOutcome(): ?array
{
    $j = readJson(usonetRunDir() . '/join.json');
    if (!is_array($j) || !is_int($j['time'] ?? null) || !is_bool($j['ok'] ?? null) || !is_string($j['why'] ?? null)) {
        return null;
    }
    $j['log'] = is_array($j['log'] ?? null) ? array_values(array_filter($j['log'], 'is_string')) : [];
    return $j;
}

/** Since when the node had no contact with its coordination server (RAM, kept by usonetPublic()) — null: it has */
function usonetNoContactSince(?array $s, int $now): ?int
{
    $file = usonetRunDir() . '/no-contact';
    if ($s !== null && $s['control'] && $now - $s['time'] <= USONET_STALE) {
        @unlink($file);
        return null;
    }
    $since = (int) @file_get_contents($file);
    if ($since <= 0 || $since > $now) {
        $since = $now;
        if (is_dir(usonetRunDir())) {
            @file_put_contents($file, (string) $since);
        }
    }
    return $since;
}

/**
 * The last lines of the network's logs (usonet.sh's and the helper's, RAM) for the card when a join drags on or
 * failed — scrubbed: never a key, each line short.
 */
function usonetLogTail(int $lines = 8): array
{
    $out = [];
    foreach (['usonet.log' => 3, 'helper.log' => $lines - 3] as $file => $n) {
        $path = usonetRunDir() . "/$file";
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            continue;
        }
        $size = (int) filesize($path);
        $text = (string) @file_get_contents($path, false, null, max(0, $size - 16384), 16384);
        $got = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        foreach (array_slice($got, -$n) as $l) {
            $l = (string) preg_replace(['/\b(tskey|hskey)-[A-Za-z0-9_-]+/', '/\b[0-9a-f]{32,}\b/'], ['$1-…', '…'], $l);
            $out[] = mb_substr($l, 0, 160);
        }
    }
    return $out;
}

/**
 * His state's `usonet` (the partner section's card): never a key. state: off · joining · leaving · starting · in ·
 * needs_key · no_contact · down · refused (the helper isn't the pinned one) · failed (the last join, its why).
 * `job`: the join or leave under way — its step and start (the card shows the wave, the step and the time); `slow`
 * and `log` once it drags on (USONET_SLOW_AFTER), `log` with a failed join too.
 */
function usonetPublic(?int $now = null): array
{
    $now ??= time();
    $joined = usonetJoined();
    $s = usonetStatus();
    $mark = usonetJob($now);
    $job = $mark['what'] ?? null;
    $out = ['joined' => $joined, 'state' => 'off', 'name' => '', 'ips' => [], 'tagged' => null, 'peers' => [], 'own_older' => [], 'foreign' => 0,
        'since' => null, 'control' => (string) (usonetConfig()['CONTROL'] ?? ''), 'policy' => usonetPolicyLines(),
        'node' => usonetNodeName(), 'port' => USONET_UDP_PORT, 'helper' => USONET_HELPER['version'], 'why' => '', 'joining' => $job, 'time' => $now,
        'job' => $mark === null ? null : ['what' => $mark['what'], 'step' => $mark['step'], 'since' => $mark['time']],
        'slow' => $mark !== null && $now - $mark['time'] >= USONET_SLOW_AFTER, 'log' => []];
    $last = usonetJoinOutcome();
    if (!$joined) {
        $out['state'] = $job === 'leave' ? 'leaving' : ($job ? 'joining' : ($last && !$last['ok'] && $now - $last['time'] < 86400 ? 'failed' : 'off'));
        $out['why'] = $out['state'] === 'failed' ? $last['why'] : '';
        if ($out['slow'] || $out['state'] === 'failed') {
            $out['log'] = usonetLogTail() ?: ($last['log'] ?? []);      // a failed join's leave took the logs: the lines kept with its outcome
        }
        return $out;
    }
    if ($out['slow']) {
        $out['log'] = usonetLogTail();
    }
    $refused = trim((string) @file_get_contents(usonetRunDir() . '/helper.refused', false, null, 0, 32));     // «update»: the old one runs on
    $fresh = $s !== null && $now - $s['time'] <= USONET_STALE;
    if ($s !== null) {
        $out['name'] = $s['name'];
        $out['ips'] = $s['ips'];
        $out['tagged'] = in_array(USONET_TAG, $s['tags'], true);
        // one row per name, its live node (usonetNodeRank()); the earlier nodes of that name folded under it (`older`):
        // a server that left and joined anew leaves its old node listed — Tailscale's logout only expires it, Headscale
        // never expires a tagged one — until it is removed in the tailnet's console (2026-10-24)
        $old = fn (array $p) => ['ip' => usonetPeerIp4($p) ?? $p['ips'][0] ?? '', 'online' => $p['online'], 'last_seen' => $p['last_seen'] ?: null];
        foreach (usonetByName($s['peers'], $now) as $nodes) {
            if ($nodes[0]['name'] === $s['name']) {
                $out['own_older'] = array_map($old, $nodes);    // this server's own earlier nodes: never a partner office
                continue;
            }
            foreach ($nodes as $p) {
                $out['foreign'] += in_array(USONET_TAG, $p['tags'], true) ? 0 : 1;
            }
            $p = $nodes[0];
            $out['peers'][] = ['name' => $p['name'], 'ips' => $p['ips'], 'online' => $p['online'], 'direct' => $p['direct'],
                'relay' => $p['relay'], 'tagged' => in_array(USONET_TAG, $p['tags'], true), 'last_seen' => $p['last_seen'] ?: null,
                'older' => array_map($old, array_slice($nodes, 1))];
        }
    }
    $since = usonetNoContactSince($fresh ? $s : null, $now);
    if ($refused === 'checksum' || $refused === 'missing') {
        $out['state'] = 'refused';
        $out['why'] = $refused;
    } elseif ($job) {
        $out['state'] = $job === 'leave' ? 'leaving' : 'joining';
    } elseif (!$fresh) {
        $out['state'] = 'down';
        $out['since'] = $since;
    } elseif ($s['backend'] === 'Running' && $s['control'] && $s['netns']) {
        $out['state'] = 'in';
    } elseif ($s['backend'] === 'NeedsLogin') {
        $out['state'] = 'needs_key';
    } elseif ($s['backend'] === 'Running') {
        $out['state'] = 'no_contact';
        $out['since'] = $since;
    } else {
        $out['state'] = 'starting';
    }
    return $out;
}

/**
 * The join or leave job under way — its mark in RAM {what, time (its start), step, pid (the job's, once it runs)} —
 * or null. A mark whose job is gone (its process dead; never started within USONET_JOB_QUEUED; older than
 * USONET_JOB_MAX) is a job that died: the mark goes, and a join that never wrote its outcome gets «job_gone».
 */
function usonetJob(?int $now = null): ?array
{
    $now ??= time();
    $file = usonetRunDir() . '/job.json';
    $m = readJson($file);
    if (!is_array($m) || !in_array($m['what'] ?? null, ['join', 'leave'], true) || !is_int($m['time'] ?? null)) {
        return null;
    }
    $pid = is_int($m['pid'] ?? null) && $m['pid'] > 1 ? $m['pid'] : null;
    $step = is_string($m['step'] ?? null) && preg_match('/^[a-z_]{1,20}$/D', $m['step']) ? $m['step'] : 'queued';
    $alive = $pid !== null ? (@posix_kill($pid, 0) || posix_get_last_error() === 1) : $now - $m['time'] <= USONET_JOB_QUEUED;     // EPERM: it lives
    if (!$alive || $now - $m['time'] > USONET_JOB_MAX) {
        @unlink($file);
        $last = usonetJoinOutcome();
        if ($m['what'] === 'join' && ($last === null || $last['time'] < $m['time'])) {
            writeAtomic(usonetRunDir() . '/join.json', jsonEncode(['time' => $now, 'ok' => false, 'why' => 'job_gone']), 0600, 0, 0);
            logLine('USO network: the join stopped half-way (its job is gone) — join again');
        }
        return null;
    }
    return ['what' => $m['what'], 'time' => $m['time'], 'step' => $step, 'pid' => $pid];
}

/** The join or leave job under way: 'join' | 'leave' | null */
function usonetJoining(): ?string
{
    return usonetJob()['what'] ?? null;
}

/**
 * The Team Lead's checks while this server is in the USO network (D6): the node carries tag:uso; it sees only tag:uso
 * peers (else the tailnet's policy lets more than the USO nodes reach each other); its helper runs and is the pinned
 * one; it has contact. Cheap: RAM and the flash only.
 */
function usonetChecks(?int $now = null): array
{
    if (!usonetJoined()) {
        return [];
    }
    $p = usonetPublic($now);
    $link = '#/caretaker/partner';
    $out = [];
    $out[] = finding('usonet_helper', 'required', $p['state'] === 'refused' ? false : true, ['why' => $p['why']], $link);
    $out[] = finding('usonet_up', 'required', $p['state'] === 'down' && $p['since'] !== null && ($now ?? time()) - $p['since'] >= USONET_DOWN_AFTER ? false
        : ($p['state'] === 'down' ? null : true), [], $link);
    $out[] = finding('usonet_tagged', 'required', $p['tagged'], ['tag' => USONET_TAG], $link);
    $out[] = finding('usonet_policy', 'required', $p['tagged'] === null ? null : $p['foreign'] === 0,
        ['tag' => USONET_TAG, 'n' => $p['foreign']], $link);
    $out[] = finding('usonet_contact', 'recommended', $p['state'] === 'no_contact' && ($now ?? time()) - (int) $p['since'] >= USONET_NO_CONTACT ? false : true,
        ['since' => (int) ($p['since'] ?? 0)], $link);
    return $out;
}

// ===================================================================== joining and leaving (the Team Lead's actions)

/**
 * Takes the auth key the web side left in the RAM inbox for this request (apiSecretStash()): a plain 0600 file of
 * root's, removed at once whatever it held.
 */
function usonetSecretTake(mixed $ref, string $dir): string
{
    if (!is_string($ref) || !preg_match('/^[0-9a-f]{32}$/D', $ref)) {
        throw new Problem('usonet_key_missing');
    }
    $file = "$dir/$ref.secret";
    clearstatcache();
    $d = @lstat($dir);
    $st = @lstat($file);
    $ok = $d && ($d['mode'] & 0170000) === 0040000 && $d['uid'] === 0 && ($d['mode'] & 0077) === 0
        && $st && ($st['mode'] & 0170000) === 0100000 && $st['uid'] === 0 && ($st['mode'] & 0077) === 0 && $st['nlink'] === 1
        && $st['size'] <= 4096;
    $raw = $ok ? @file_get_contents($file, false, null, 0, 4096) : false;
    if ($st) {
        @unlink($file);
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    unset($raw);
    $key = is_array($data) && is_string($data['key'] ?? null) ? trim($data['key']) : '';
    if (!preg_match(USONET_KEY_RE, $key)) {
        throw new Problem('usonet_key_bad');
    }
    return $key;
}

/** usonet.cfg written anew (key="value"; only these keys) */
function usonetConfigWrite(array $cfg): void
{
    $text = '';
    foreach (['JOINED', 'NAME', 'CONTROL'] as $k) {
        $v = (string) ($cfg[$k] ?? '');
        if (!preg_match('/^[A-Za-z0-9.:\/\[\]_-]{0,300}$/D', $v)) {
            throw new Problem('bad_request');
        }
        $text .= "$k=\"$v\"\n";
    }
    writeAtomic(usonetFlashDir() . '/usonet.cfg', $text, 0600, 0, 0);
}

/** The job's mark at its start (the agent, before atd has it): queued, no pid yet */
function usonetJobMark(string $what): void
{
    @mkdir(usonetRunDir(), 0700, true);
    writeAtomic(usonetRunDir() . '/job.json', jsonEncode(['what' => $what, 'time' => time(), 'step' => 'queued', 'pid' => null]), 0600, 0, 0);
}

/** The job's step as it goes (the card shows it): the start kept, the job's own pid written — so a dead job is seen */
function usonetJobStep(string $step): void
{
    $m = readJson(usonetRunDir() . '/job.json');
    if (!is_array($m) || !in_array($m['what'] ?? null, ['join', 'leave'], true)) {
        return;
    }
    @mkdir(usonetRunDir(), 0700, true);
    writeAtomic(usonetRunDir() . '/job.json', jsonEncode(['what' => $m['what'], 'time' => is_int($m['time'] ?? null) ? $m['time'] : time(),
        'step' => $step, 'pid' => getmypid()]), 0600, 0, 0);
}

/**
 * «Join the USO network»: the auth key (a secret: through RAM, then the flash's 0600 file the helper reads at its first
 * login — removed once the node is in), the optional coordination server (Headscale). The rest is a job of atd's
 * (usonetJoinJob()): fetching the helper takes a while.
 */
function usonet_join(array $r, ?string $inbox = null): array
{
    $key = usonetSecretTake($r['secret_ref'] ?? null, $inbox ?? officeInboxDir());
    $control = trim(optText($r, 'control'));
    if ($control !== '' && !preg_match(USONET_CONTROL_RE, $control)) {
        throw new Problem('usonet_control_bad');
    }
    if (usonetJoined()) {
        throw new Problem('usonet_joined');
    }
    if (usonetJoining() !== null) {
        throw new Problem('usonet_busy');
    }
    $dir = usonetFlashDir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new Problem('usonet_flash');
    }
    @unlink(usonetRunDir() . '/join.json');
    writeAtomic("$dir/authkey", $key . "\n", 0600, 0, 0);
    unset($key);
    usonetConfigWrite(['JOINED' => 'no', 'NAME' => usonetNodeName(), 'CONTROL' => $control]);
    usonetJobMark('join');
    hostLaunch('usonet-join', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'usonet-join']);
    return ['ok' => true, 'usonet' => usonetPublic()];
}

/** «Leave the USO network»: the node logs out, everything of it goes (usonet.sh leave, through atd: up to 40 s) */
function usonet_leave(array $r): array
{
    if (!usonetJoined() && !is_dir(usonetFlashDir())) {
        return ['ok' => true, 'usonet' => usonetPublic()];
    }
    if (usonetJoining() !== null) {
        throw new Problem('usonet_busy');
    }
    usonetJobMark('leave');
    hostLaunch('usonet-leave', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'usonet-leave']);
    return ['ok' => true, 'usonet' => usonetPublic()];
}

/** The URL the helper comes from: the pinned one; a test server may name another (USONET_URL in the plugin's .cfg) — the
 *  SHA-256 is checked either way */
function usonetHelperUrl(): string
{
    $cfg = readCfg('/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg');
    $dev = (string) ($cfg['USONET_URL'] ?? '');
    return preg_match('/^https?:\/\/[A-Za-z0-9.:_\/-]{1,300}$/D', $dev) ? $dev : USONET_HELPER['url'];
}

/** The pinned helper on the flash — fetched when missing or not the pinned one; true when it is there now */
function usonetFetch(): bool
{
    $file = usonetFlashDir() . '/' . USONET_HELPER['file'];
    if (is_file($file) && !is_link($file) && hash_file('sha256', $file) === USONET_HELPER['sha256']) {
        return true;
    }
    $part = "$file.part";
    @unlink($part);
    $url = usonetHelperUrl();
    $curl = getenv('USONET_CURL') ?: 'curl';
    [$exit, , $err] = hostNet([$curl, '-fsSL', '--proto', str_starts_with($url, 'https:') ? '=https' : '=http,https', '--max-redirs', '5',
        '--max-filesize', (string) USONET_FETCH_MAX, '--retry', '2', '--connect-timeout', '20', '-o', $part, $url], 300);
    if ($exit !== 0 || !is_file($part)) {
        @unlink($part);
        logLine('USO network: the helper could not be fetched (' . trim(substr((string) $err, 0, 200)) . ')');
        return false;
    }
    if (hash_file('sha256', $part) !== USONET_HELPER['sha256']) {
        @unlink($part);
        logLine('USO network: the helper fetched is not the pinned one (SHA-256) — removed, not used');
        throw new Problem('usonet_checksum');
    }
    @chmod($part, 0600);
    if (!@rename($part, $file)) {
        return false;
    }
    foreach (glob(usonetFlashDir() . '/uso-net-*-linux-amd64') ?: [] as $old) {     // an earlier version's helper
        if ($old !== $file && preg_match('#/uso-net-\d{1,4}-linux-amd64$#D', $old)) {
            @unlink($old);
        }
    }
    return true;
}

/**
 * Every minute from the Team Lead's tick (RAM only): when usonet.sh asks for the pinned helper (helper.refused: missing,
 * checksum, or «update» — an office update pinned a newer one while the old one runs), a job fetches and checks it —
 * at most once an hour; usonet.sh swaps it in at its next look.
 */
function usonetTick(?int $now = null): void
{
    $now ??= time();
    $run = usonetRunDir();
    $why = trim((string) @file_get_contents("$run/helper.refused", false, null, 0, 32));
    if (!in_array($why, ['missing', 'checksum', 'update'], true) || !usonetJoined() || usonetJoining() !== null
        || $now - (int) @filemtime("$run/fetch.tried") < 3600) {
        return;
    }
    @touch("$run/fetch.tried");
    try {
        hostLaunch('usonet-fetch', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'usonet-fetch']);
    } catch (Throwable $e) {
        logLine('USO network: the helper\'s fetch could not be started: ' . $e->getMessage());
    }
}

/** The fetch's job (atd): the pinned helper onto the flash, then usonet.sh's look (it swaps or starts it) */
function usonetFetchJob(): array
{
    try {
        $ok = usonetFetch();
    } catch (Problem $e) {
        $ok = false;
    }
    if ($ok) {
        @unlink(usonetRunDir() . '/helper.refused');
        run(['bash', usonetScript(), 'check'], 60);
    }
    logLine('USO network: the pinned helper ' . ($ok ? 'is on the flash (version ' . USONET_HELPER['version'] . ')' : 'could not be fetched — tried again in an hour'));
    return ['ok' => $ok];
}

/** The join's job (atd): the helper fetched and checked, the node started, waited for — the key removed once it is in */
function usonetJoinJob(?int $wait = null): array
{
    $wait ??= USONET_JOIN_WAIT;
    $dir = usonetFlashDir();
    $result = function (bool $ok, string $why, array $log = []) use ($dir): array {
        @unlink("$dir/authkey");
        @unlink(usonetRunDir() . '/job.json');
        $out = ['time' => time(), 'ok' => $ok, 'why' => $why, 'log' => $log];
        @mkdir(usonetRunDir(), 0700, true);
        writeAtomic(usonetRunDir() . '/join.json', jsonEncode($out), 0600, 0, 0);
        logLine('USO network: ' . ($ok ? 'this server is in (' . implode(', ', usonetStatus()['ips'] ?? []) . ')' : "joining failed ($why)"));
        return $out;
    };
    $cfg = usonetConfig();
    if (!is_file("$dir/authkey")) {
        return $result(false, 'key_missing');
    }
    usonetJobStep('fetch');
    try {
        if (!usonetFetch()) {
            $log = usonetLogTail();         // the leave takes the logs: their last lines stay with the outcome (the card shows them)
            run(['bash', usonetScript(), 'leave'], 60);
            return $result(false, 'fetch', $log);
        }
    } catch (Problem $e) {
        $log = usonetLogTail();
        run(['bash', usonetScript(), 'leave'], 60);
        return $result(false, 'checksum', $log);
    }
    usonetJobStep('start');
    usonetConfigWrite(['JOINED' => 'yes', 'NAME' => (string) ($cfg['NAME'] ?? usonetNodeName()), 'CONTROL' => (string) ($cfg['CONTROL'] ?? '')]);
    $end = time() + $wait;
    $why = 'no_contact';
    do {
        run(['bash', usonetScript(), 'check'], 30);
        usonetJobStep('login');
        $s = usonetStatus();
        if ($s !== null && $s['backend'] === 'Running' && $s['netns'] && $s['ips']) {
            run(['bash', usonetScript(), 'check'], 30);         // the sshd on the node's addresses, right away
            return $result(true, '');
        }
        if ($s !== null && $s['backend'] === 'NeedsLogin' && time() - (int) @filemtime(usonetRunDir() . '/helper.pid') > 20) {
            $why = 'key_refused';
            break;
        }
        usleep(2000000);
    } while (time() < $end);
    $log = usonetLogTail();
    run(['bash', usonetScript(), 'leave'], 60);       // nothing half-joined stays
    return $result(false, $why, $log);
}

/** The leave's job (atd): usonet.sh leave says in one line whether the node could log out (its RAM folder goes with it) */
function usonetLeaveJob(): array
{
    usonetJobStep('leave');
    [$exit, $out] = run(['bash', usonetScript(), 'leave'], 90);
    @unlink(usonetRunDir() . '/job.json');
    logLine('USO network: this server left it — ' . (trim((string) $out) ?: "usonet.sh leave: $exit"));
    return ['ok' => $exit === 0];
}
