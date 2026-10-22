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
    'sha256'  => '61bec7969716174cad94f2ea736f242db4d98a63b51c29e45e839a65e9d95b83',
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

/** The node's addresses for the pairing's picker (partnerMyAddresses()): only while it is in and its device is up */
function usonetMyAddresses(): array
{
    $s = usonetStatus();
    if ($s === null || $s['backend'] !== 'Running' || !$s['netns']) {
        return [];
    }
    return array_map(fn (string $a) => ['address' => $a, 'iface' => USONET_NETNS, 'private' => true, 'usonet' => true], $s['ips']);
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

/** The outcome of the last join (join.json, RAM): {time, ok, why} or null */
function usonetJoinOutcome(): ?array
{
    $j = readJson(usonetRunDir() . '/join.json');
    return is_array($j) && is_int($j['time'] ?? null) && is_bool($j['ok'] ?? null) && is_string($j['why'] ?? null) ? $j : null;
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
 * His state's `usonet` (the partner section's card): never a key. state: off · joining · leaving · starting · in ·
 * needs_key · no_contact · down · refused (the helper isn't the pinned one) · failed (the last join, its why).
 */
function usonetPublic(?int $now = null): array
{
    $now ??= time();
    $joined = usonetJoined();
    $s = usonetStatus();
    $job = usonetJoining();
    $out = ['joined' => $joined, 'state' => 'off', 'name' => '', 'ips' => [], 'tagged' => null, 'peers' => [], 'foreign' => 0,
        'since' => null, 'control' => (string) (usonetConfig()['CONTROL'] ?? ''), 'policy' => usonetPolicyLines(),
        'node' => usonetNodeName(), 'port' => USONET_UDP_PORT, 'helper' => USONET_HELPER['version'], 'why' => '', 'joining' => $job];
    $last = usonetJoinOutcome();
    if (!$joined) {
        $out['state'] = $job === 'leave' ? 'leaving' : ($job ? 'joining' : ($last && !$last['ok'] && $now - $last['time'] < 86400 ? 'failed' : 'off'));
        $out['why'] = $out['state'] === 'failed' ? $last['why'] : '';
        return $out;
    }
    $refused = trim((string) @file_get_contents(usonetRunDir() . '/helper.refused', false, null, 0, 32));
    $fresh = $s !== null && $now - $s['time'] <= USONET_STALE;
    if ($s !== null) {
        $out['name'] = $s['name'];
        $out['ips'] = $s['ips'];
        $out['tagged'] = in_array(USONET_TAG, $s['tags'], true);
        foreach ($s['peers'] as $p) {
            $tagged = in_array(USONET_TAG, $p['tags'], true);
            $out['foreign'] += $tagged ? 0 : 1;
            $out['peers'][] = ['name' => $p['name'], 'ips' => $p['ips'], 'online' => $p['online'], 'direct' => $p['direct'],
                'relay' => $p['relay'], 'tagged' => $tagged, 'last_seen' => $p['last_seen'] ?: null];
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

/** The join or leave job under way (its mark in RAM, at most USONET_JOIN_WAIT + 120 s old): 'join' | 'leave' | null */
function usonetJoining(): ?string
{
    $m = readJson(usonetRunDir() . '/job.json');
    if (!is_array($m) || !in_array($m['what'] ?? null, ['join', 'leave'], true) || !is_int($m['time'] ?? null)
        || time() - $m['time'] > USONET_JOIN_WAIT + 120) {
        return null;
    }
    return $m['what'];
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

function usonetJobMark(string $what): void
{
    @mkdir(usonetRunDir(), 0700, true);
    writeAtomic(usonetRunDir() . '/job.json', jsonEncode(['what' => $what, 'time' => time()]), 0600, 0, 0);
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
    return @rename($part, $file);
}

/** The join's job (atd): the helper fetched and checked, the node started, waited for — the key removed once it is in */
function usonetJoinJob(?int $wait = null): array
{
    $wait ??= USONET_JOIN_WAIT;
    $dir = usonetFlashDir();
    $result = function (bool $ok, string $why) use ($dir): array {
        @unlink("$dir/authkey");
        @unlink(usonetRunDir() . '/job.json');
        $out = ['time' => time(), 'ok' => $ok, 'why' => $why];
        @mkdir(usonetRunDir(), 0700, true);
        writeAtomic(usonetRunDir() . '/join.json', jsonEncode($out), 0600, 0, 0);
        logLine('USO network: ' . ($ok ? 'this server is in (' . implode(', ', usonetStatus()['ips'] ?? []) . ')' : "joining failed ($why)"));
        return $out;
    };
    $cfg = usonetConfig();
    if (!is_file("$dir/authkey")) {
        return $result(false, 'key_missing');
    }
    try {
        if (!usonetFetch()) {
            run(['bash', usonetScript(), 'leave'], 60);
            return $result(false, 'fetch');
        }
    } catch (Problem $e) {
        run(['bash', usonetScript(), 'leave'], 60);
        return $result(false, 'checksum');
    }
    usonetConfigWrite(['JOINED' => 'yes', 'NAME' => (string) ($cfg['NAME'] ?? usonetNodeName()), 'CONTROL' => (string) ($cfg['CONTROL'] ?? '')]);
    $end = time() + $wait;
    $why = 'no_contact';
    do {
        run(['bash', usonetScript(), 'check'], 30);
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
    run(['bash', usonetScript(), 'leave'], 60);       // nothing half-joined stays
    return $result(false, $why);
}

/** The leave's job (atd) */
function usonetLeaveJob(): array
{
    [$exit] = run(['bash', usonetScript(), 'leave'], 90);
    @mkdir(usonetRunDir(), 0700, true);
    @unlink(usonetRunDir() . '/job.json');
    logLine('USO network: this server left it' . ($exit === 0 ? '' : " (usonet.sh leave: $exit)"));
    return ['ok' => $exit === 0];
}
