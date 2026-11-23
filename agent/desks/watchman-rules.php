<?php
declare(strict_types=1);

/*
 * The Night Watchman's exceptions and areas switched off (1.96 step 4, the concept «Der Nachtwächter in 1.96» 3.1).
 * Functions only, in a file of their own beside his desk (the agent's desk glob loads it, watchman.php require_once's
 * it; «rules» sorts before «php» in every locale). What the user decides, he does — at most he asks more clearly.
 *
 * data/watchman/rules.json — written only by the user's clicks (his page), never by a round:
 *   {"v": 1,
 *    "rules": [{"id": "r<10 hex>", "cat": "<area>", "kinds": ["<kind>", …], "on": {…}, "at": <time>, "from": "<entry id>"|"hand",
 *               "note": "<one line>"}],
 *    "off":   {"<area>": {"at": <time>}},
 *    …}                                       keys and rules it doesn't know are kept as they are (a newer office's)
 * A rule's `on`, the shapes (watchmanRuleOn()): {"key": "<an entry's key>"} — exactly this thing (every kind can have
 * it); {"ip": "<address>"}; {"net": "<a private /24 or /64>"}; {"user": "<name>", "net": "<net>"}; {"machine": "<SMB
 * machine name>"}; {"source": "<host>/<owner>"} — a plugin's source (6a); {"file": "<a file in /etc/cron.d>"} — cron
 * lines that all come from it; {"cmd": "<16 hex>", "user": "…", "owner": "…"} — an at job running exactly one clean
 * command (watchmanAtOnlyCommand(): its fingerprint), as this user, its program owned by that one; {"share": "<share>"}
 * and {"disk": "<disk>"} — the data flow's entries of that share or XFS/btrfs disk (6b, by the entry's key). A rule may
 * carry `what` (its words for the page, ≤ 300 bytes). Snapshots (6c, applied where the gone ones are listed — watchmanSnapRuled(),
 * never in the rules' pass): {"series": S} — a series may be thinned everywhere, {"series": S, "dataset": D} — on that
 * dataset (both only while a newer one of it stays); {"dataset": D} — on D snapshots may go altogether (snap_gone) or a hold
 * be released (snap_hold_released). Never a whole pool: D is pool/…
 * A rule with `opt` is no «let through» but a threshold where the data flow is judged (6b, watchmanFlowRule()): {"min":
 * <bytes>} or {"files": <n>} — unusual only from this much in the hour; {"kept": false} — what a snapshot still holds
 * doesn't count as gone; {"layers": false} — new Docker image layers don't count as written. Its hit: a round it kept
 * from being an entry. Never while «always tell» holds (WATCH_FLOW_MUST: half a share or 200 000 files in an hour). Only a rule in exactly its shape is applied; anything else stays in the
 * file and does nothing.
 *
 * What a rule does: a new entry it covers is noted at once (`by: rule`, `rule: <id>`) — never told, never counted for
 * the light; the same thing again (the same key) brings that line up to date instead of a new one. The baseline stays
 * as it is: removing a rule is honest — the next round tells what is still different. An area switched off: what comes
 * new there is made normal (watchmanAdopt(), the baseline kept up to date) and left out of his book — switched on again
 * he starts from now, no backlog. Hits per rule in state.json (`rule_hits`).
 * Never let through: an entry with `again` (the same as a serious incident) unless the rule or the switch came after
 * that incident (your newest decision counts); an entry that is part of a chain forming (two important things from two
 * areas within the hour — that chain is told). One place: watchmanRulesApply(), in the round after watchmanSeriousAgain()
 * and before anything is told; the night shift runs the same round over its RAM copy (rules.json in its folder,
 * from RUN_DIR/watchman-rules.json or the flash's, watchmanRulesNight()).
 * An older office on this data folder doesn't read rules.json: it tells everything again (louder, never quieter);
 * entries noted by a rule stay noted for it (watchmanOpen() looks at `noted`).
 */

const WATCH_RULES_FILE  = 'rules.json';
const WATCH_RULES_RAM   = RUN_DIR . '/watchman-rules.json';                                   // the night shift's copy (RAM)
const WATCH_RULES_FLASH = '/boot/config/plugins/' . OFFICE_PLUGIN . '/watchman-rules.json';  // … for a reboot (only when changed)
const WATCH_RULES_MAX   = 300;            // rules at most
const WATCH_RULES_CAT   = 50;             // … per area
const WATCH_RULE_ID     = '/^r[0-9a-f]{10}$/D';
const WATCH_RULE_USER   = '/^[A-Za-z0-9._@-]{1,64}$/D';
const WATCH_RULE_TEXT   = 300;            // bytes of a key or a machine name
const WATCH_RULE_LOGIN  = ['login_new_ip', 'smb_client', 'smb_hour'];     // what an address or a net covers (never failures, never a new SMB user)
const WATCH_RULE_SOURCE = '/^[a-z0-9][a-z0-9.-]{0,99}(\/[A-Za-z0-9._-]{1,100})?$/D';    // a plugin's source as watchmanPluginSource() writes it
const WATCH_RULE_CROND  = '/^[A-Za-z0-9._-]{1,100}$/D';                                  // a file in /etc/cron.d
const WATCH_RULE_CMD    = '/^[0-9a-f]{16}$/D';                                           // an at job's one command, its fingerprint
const WATCH_RULE_SHARE  = '/^[^\/\x00-\x1f]{1,100}$/D';                                  // a share's name (any but a slash)
const WATCH_RULE_DISK   = '/^[a-z][a-z0-9_-]{0,39}$/D';                                   // an array disk or a pool (disk1, cache …)
const WATCH_RULE_FLOW   = ['flow_client', 'flow_container', 'flow_written', 'flow_gone'];   // what a threshold (`opt`) may be on
const WATCH_RULE_PROG   = '/^[A-Za-z0-9._+-]{1,32}$/D';                                   // a program listening (as ss names it)
const WATCH_RULE_TUNNEL = '/^[A-Za-z0-9_.-]{1,40}$/D';                                    // a WireGuard tunnel (wg0 …)
const WATCH_RULE_SERIES = '/^[^\x00-\x1f]{1,255}$/D';                                    // a snapshot series (its name, digits as #)
const WATCH_RULE_DS     = '#^[A-Za-z0-9_.:-][^@\x00-\x1f]{0,254}$#D';                    // a ZFS dataset (pool/…, never the pool alone: watchmanRuleOn())
// the kinds he asks about more clearly before an exception (concept 3.3: what an attacker does first, or what points to
// damage — and comes rarely); every other kind is in WATCH_RULE_PLAIN (testWatchmanRules: each kind in exactly one)
const WATCH_RULE_ASK    = ['flash_go', 'flash_user', 'flash_password', 'flash_ssh_key', 'user_ram', 'log_cleared', 'plugin_source',
                           'cron_office', 'cron_twice', 'cron_file_foreign', 'notify_agent', 'api_key_new', 'api_key_changed', 'door_new',
                           'door_changed', 'door_refused', 'smb_user', 'login_failures', 'kopia_emptied', 'net_spoof', 'net_firewall_change',
                           'net_ips_server', 'net_log_silent', 'flow_written', 'flow_gone', 'snap_gone', 'snap_hold_released'];
const WATCH_RULE_PLAIN  = ['login_new_ip', 'smb_client', 'smb_hour', 'container_new', 'container_privileged', 'container_host', 'container_ports',
                           'container_rights', 'container_plain', 'drill_throwaway', 'plugin_new', 'plugin_version', 'flash_extra', 'share_public', 'cron_new', 'cron_file', 'script_new',
                           'script_changed', 'at_job', 'at_userscript', 'at_plugin', 'flow_client', 'flow_container', 'listen_new', 'proc_odd',
                           'array_stop', 'array_start', 'server_boot', 'parity_check', 'partner_paired', 'door_key_moved', 'net_sender_new',
                           'net_new_device', 'net_router_login', 'net_router_config', 'net_vpn_login', 'net_blocked_from_server',
                           'net_router_login_failures', 'net_link_down', 'net_outage', 'net_router_reboot'];

/** rules.json as it lies (tolerant: whatever it holds stays; not a JSON object: nothing) */
function watchmanRulesRaw(string $dir): array
{
    $j = readJson("$dir/" . WATCH_RULES_FILE);
    return is_array($j) && !array_is_list($j) ? $j : [];
}

/**
 * What applies: the rules in exactly their shape (the first 300, each area's first 50), the areas switched off.
 *
 * @return array{rules: list<array>, off: array<string, int>, known: list<array>, set: array<string, array<string, mixed>>}
 */
function watchmanRules(array $raw): array
{
    $rules = [];
    $per = [];
    $ids = [];
    foreach (is_array($raw['rules'] ?? null) && array_is_list($raw['rules']) ? $raw['rules'] : [] as $r) {
        $ok = watchmanRuleShaped($r);
        if ($ok === null || isset($ids[$ok['id']]) || count($rules) >= WATCH_RULES_MAX || ($per[$ok['cat']] ?? 0) >= WATCH_RULES_CAT) {
            continue;
        }
        $ids[$ok['id']] = true;
        $per[$ok['cat']] = ($per[$ok['cat']] ?? 0) + 1;
        $rules[] = $ok;
    }
    $off = [];
    foreach (is_array($raw['off'] ?? null) ? $raw['off'] : [] as $cat => $o) {
        if (is_string($cat) && isset(WATCH_CATS[$cat]) && is_int($o['at'] ?? null)) {
            $off[$cat] = $o['at'];
        }
    }
    return ['rules' => $rules, 'off' => $off, 'known' => watchmanKnown($raw), 'set' => watchmanSettings($raw)];
}

/** A rule in exactly its shape (normalized), else null */
function watchmanRuleShaped(mixed $r): ?array
{
    if (!is_array($r) || !is_string($r['id'] ?? null) || !preg_match(WATCH_RULE_ID, $r['id']) || !is_string($r['cat'] ?? null)
        || !isset(WATCH_CATS[$r['cat']]) || !is_array($r['kinds'] ?? null) || !$r['kinds'] || !array_is_list($r['kinds']) || !is_int($r['at'] ?? null)) {
        return null;
    }
    foreach ($r['kinds'] as $k) {
        if (!is_string($k) || !in_array($k, WATCH_CATS[$r['cat']]['kinds'], true)) {
            return null;
        }
    }
    $on = watchmanRuleOn($r['on'] ?? null);
    if ($on === null) {
        return null;
    }
    $opt = null;
    if (array_key_exists('opt', $r)) {
        $opt = watchmanRuleOpt($r['opt'], $r['kinds']);
        if ($opt === null) {
            return null;
        }
    }
    return ['id' => $r['id'], 'cat' => $r['cat'], 'kinds' => array_values(array_unique($r['kinds'])), 'on' => $on, 'at' => $r['at'],
            'from' => is_string($r['from'] ?? null) && (preg_match(WATCH_ID, $r['from']) || $r['from'] === 'hand') ? $r['from'] : 'hand',
            'note' => is_string($r['note'] ?? null) ? mb_substr($r['note'], 0, WATCH_NOTE_MAX) : '']
           + (is_string($r['what'] ?? null) && $r['what'] !== '' && strlen($r['what']) <= WATCH_RULE_TEXT ? ['what' => $r['what']] : [])
           + ($opt !== null ? ['opt' => $opt] : []);
}

/** A threshold's `opt` in exactly one of its shapes, for kinds of the data flow only (and kept/layers only for theirs), else null */
function watchmanRuleOpt(mixed $opt, array $kinds): ?array
{
    if (!is_array($opt) || count($opt) !== 1 || array_diff($kinds, WATCH_RULE_FLOW)) {
        return null;
    }
    $k = (string) array_key_first($opt);
    $v = $opt[$k];
    return match (true) {
        $k === 'min' && is_int($v) && $v > 0 && $v <= 1024 ** 5,
        $k === 'files' && is_int($v) && $v > 0 && $v <= 100_000_000 => [$k => $v],
        $k === 'kept' && $v === false && $kinds === ['flow_gone'],
        $k === 'layers' && $v === false && $kinds === ['flow_written'] => [$k => false],
        default => null,
    };
}

/**
 * What a data flow entry is about, by its key: ['share', <name>] (a ZFS share — written into <pool>/<share>, gone, files
 * deleted) or ['disk', <name>] (an XFS/btrfs disk or pool); null: another kind (a client, a container)
 *
 * @return array{0: string, 1: string}|null
 */
function watchmanFlowThing(string $key): ?array
{
    return match (true) {
        (bool) preg_match('#^flow_(?:written|gone(?::files)?):disk:(.+)$#D', $key, $m)  => ['disk', $m[1]],
        (bool) preg_match('#^flow_gone(?::files)?:share:(.+)$#D', $key, $m)            => ['share', $m[1]],
        (bool) preg_match('#^flow_written:[^/:]+/(.+)$#D', $key, $m)                   => ['share', $m[1]],
        default => null,
    };
}

/** A rule's `on` in exactly one of its shapes, else null */
function watchmanRuleOn(mixed $on): ?array
{
    if (!is_array($on)) {
        return null;
    }
    $keys = array_keys($on);
    sort($keys);
    $str = fn (string $k): bool => is_string($on[$k] ?? null) && $on[$k] !== '' && strlen($on[$k]) <= WATCH_RULE_TEXT;
    return match ($keys) {
        ['key']         => $str('key') ? ['key' => $on['key']] : null,
        ['ip']          => is_string($on['ip']) && filter_var($on['ip'], FILTER_VALIDATE_IP) !== false ? ['ip' => $on['ip']] : null,
        ['net']         => watchmanNetValid($on['net']) ? ['net' => $on['net']] : null,
        ['net', 'user'] => watchmanNetValid($on['net']) && is_string($on['user']) && preg_match(WATCH_RULE_USER, $on['user']) ? ['user' => $on['user'], 'net' => $on['net']] : null,
        ['machine']     => $str('machine') ? ['machine' => $on['machine']] : null,
        ['source']      => is_string($on['source']) && preg_match(WATCH_RULE_SOURCE, $on['source']) ? ['source' => $on['source']] : null,
        ['file']        => is_string($on['file']) && preg_match(WATCH_RULE_CROND, $on['file']) ? ['file' => $on['file']] : null,
        ['share']       => is_string($on['share']) && preg_match(WATCH_RULE_SHARE, $on['share']) ? ['share' => $on['share']] : null,
        ['disk']        => is_string($on['disk']) && preg_match(WATCH_RULE_DISK, $on['disk']) ? ['disk' => $on['disk']] : null,
        ['open', 'proto', 'share'] => is_string($on['share']) && preg_match(WATCH_RULE_SHARE, $on['share']) && in_array($on['proto'], ['smb', 'nfs'], true)
                                      && in_array($on['open'], ['read', 'write'], true) ? ['share' => $on['share'], 'proto' => $on['proto'], 'open' => $on['open']] : null,
        ['prog']        => is_string($on['prog']) && preg_match(WATCH_RULE_PROG, $on['prog']) ? ['prog' => $on['prog']] : null,
        ['tunnel']      => is_string($on['tunnel']) && preg_match(WATCH_RULE_TUNNEL, $on['tunnel']) ? ['tunnel' => $on['tunnel']] : null,
        ['series']      => is_string($on['series']) && preg_match(WATCH_RULE_SERIES, $on['series']) ? ['series' => $on['series']] : null,
        ['dataset']     => watchmanRuleDsOk($on['dataset']) ? ['dataset' => $on['dataset']] : null,
        ['dataset', 'series'] => watchmanRuleDsOk($on['dataset']) && is_string($on['series']) && preg_match(WATCH_RULE_SERIES, $on['series'])
                                 ? ['series' => $on['series'], 'dataset' => $on['dataset']] : null,
        ['cmd', 'owner', 'user'] => is_string($on['cmd']) && preg_match(WATCH_RULE_CMD, $on['cmd']) && is_string($on['user']) && is_string($on['owner'])
                                    && strlen($on['user']) <= 64 && strlen($on['owner']) <= 64
                                    ? ['cmd' => $on['cmd'], 'user' => $on['user'], 'owner' => $on['owner']] : null,
        default         => null,
    };
}

/** A ZFS dataset a snapshot rule may name: below a pool (pool/…), never the pool itself (concept 3.2: never a whole pool) */
function watchmanRuleDsOk(mixed $ds): bool
{
    return is_string($ds) && preg_match(WATCH_RULE_DS, $ds) && str_contains($ds, '/') && !str_ends_with($ds, '/') && !str_contains($ds, '//');
}

/** «a.b.c.0/24» or «x:x:x:x::/64» and the like: an address and a prefix that fits it, the address the net's own */
function watchmanNetValid(mixed $net): bool
{
    if (!is_string($net) || !preg_match('#^([0-9A-Fa-f:.]{2,45})/(\d{1,3})$#D', $net, $m)) {
        return false;
    }
    $bin = @inet_pton($m[1]);
    $bits = (int) $m[2];
    if ($bin === false || $bits > strlen($bin) * 8 || $bits < (strlen($bin) === 4 ? 8 : 16)) {
        return false;
    }
    return watchmanNetMask($bin, $bits) === $bin;
}

/** An address's first $bits bits, the rest 0 (packed) */
function watchmanNetMask(string $bin, int $bits): string
{
    $out = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        $keep = max(0, min(8, $bits - $i * 8));
        $out .= chr(ord($bin[$i]) & (0xFF << (8 - $keep)) & 0xFF);
    }
    return $out;
}

/** Does an address lie in a net? (IPv4 in IPv4, IPv6 in IPv6; any prefix — a rule's net is checked by watchmanNetValid()) */
function watchmanIpInNet(string $ip, string $net): bool
{
    if (!preg_match('#^([0-9A-Fa-f:.]{2,45})/(\d{1,3})$#D', $net, $m)) {
        return false;
    }
    $a = @inet_pton($ip);
    $n = @inet_pton($m[1]);
    $bits = (int) $m[2];
    return $a !== false && $n !== false && strlen($a) === strlen($n) && $bits <= strlen($n) * 8 && watchmanNetMask($a, $bits) === watchmanNetMask($n, $bits);
}

/** A private address (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7, fe80::/10) */
function watchmanIpPrivate(string $ip): bool
{
    foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7', 'fe80::/10'] as $net) {
        if (watchmanIpInNet($ip, $net)) {
            return true;
        }
    }
    return false;
}

/** The net a click may offer for an address: its /24 (IPv4) or /64 (IPv6) — only for a private one */
function watchmanNetOf(string $ip): ?string
{
    $bin = @inet_pton($ip);
    if ($bin === false || !watchmanIpPrivate($ip)) {
        return null;
    }
    $bits = strlen($bin) === 4 ? 24 : 64;
    return inet_ntop(watchmanNetMask($bin, $bits)) . "/$bits";
}

/** Does a rule cover an entry? (its kind, and exactly what its `on` names) */
function watchmanRuleCovers(array $rule, array $e): bool
{
    if (!in_array($e['kind'] ?? '', $rule['kinds'], true)) {
        return false;
    }
    $on = $rule['on'];
    $p = (array) ($e['p'] ?? []);
    $ip = is_string($p['ip'] ?? null) ? $p['ip'] : '';
    if (isset($on['key'])) {
        return ($e['key'] ?? null) === $on['key'];
    }
    if (isset($on['cmd'])) {
        // exactly this one clean command (never one with an odd environment or more commands: no fingerprint then), as
        // this user, its program owned by that one — and never a plugin's job no longer as he knew it
        return ($p['_one'] ?? null) === $on['cmd'] && (string) ($p['user'] ?? '') === $on['user'] && (string) ($p['owner'] ?? '') === $on['owner']
            && empty($p['_changed']);
    }
    if (isset($on['user'])) {
        $users = array_values(array_filter((array) ($p['users'] ?? []), 'is_string'));
        return $ip !== '' && watchmanIpInNet($ip, $on['net']) && $users && !array_diff($users, [$on['user']]);
    }
    if (isset($on['net'])) {
        return $ip !== '' && watchmanIpInNet($ip, $on['net']);
    }
    if (isset($on['ip'])) {
        return $ip !== '' && @inet_pton($ip) !== false && @inet_pton($ip) === @inet_pton($on['ip']);
    }
    if (isset($on['machine'])) {
        return ($p['machine'] ?? null) === $on['machine'];
    }
    if (isset($on['source'])) {
        return is_string($p['source'] ?? null) && $p['source'] === $on['source'];
    }
    if (isset($on['open'])) {
        // a share guests may read (⟦Secure⟧) or also write (⟦Public⟧) over this protocol (6d)
        return ($p['share'] ?? null) === $on['share'] && ($p['proto'] ?? null) === $on['proto']
            && (($p['level'] ?? null) === 'secure' || ($on['open'] === 'write' && ($p['level'] ?? null) === 'public'));
    }
    if (isset($on['share'])) {
        return watchmanFlowThing((string) ($e['key'] ?? '')) === ['share', $on['share']];
    }
    if (isset($on['prog'])) {
        return ($p['prog'] ?? null) === $on['prog'];            // a program may listen on any port (6d)
    }
    if (isset($on['tunnel'])) {
        // new peers in a known, open WireGuard tunnel — never the tunnel switched on, another port, an entry from before 1.96
        return ($p['key'] ?? null) === 'wg:' . $on['tunnel'] && ($p['_opened'] ?? null) === false && (int) ($p['new_peers'] ?? 0) > 0;
    }
    if (isset($on['disk'])) {
        return watchmanFlowThing((string) ($e['key'] ?? '')) === ['disk', $on['disk']];
    }
    if (isset($on['file'])) {
        // every new line from that file (the lines of an entry come from where watchmanSchedCompare() found them)
        return ($p['_from'] ?? null) === ['cron.d/' . $on['file']];
    }
    return false;
}

/**
 * Asked more clearly for this one though its kind isn't on the list (concept 3.2): a container's --privileged, the Docker
 * socket or the whole server (-v /) for good; a program from memory or /dev/shm on the server itself; the flash for guests
 */
function watchmanRuleAskThis(array $e, array $o): bool
{
    $p = (array) ($e['p'] ?? []);
    return match ((string) ($e['kind'] ?? '')) {
        'container_privileged' => true,
        'container_rights'     => (bool) array_filter((array) ($p['tokens'] ?? []), fn ($t) => preg_match('#^-v (?:/var)?/run/docker\.sock|^-v /$#', (string) $t)),
        'proc_odd'             => str_starts_with((string) ($e['key'] ?? ''), 'proc_odd:host:') && (bool) preg_match('#/memfd:|^proc_odd:host:/dev/shm/#', (string) $e['key']),
        'share_public'         => ($p['share'] ?? null) === 'flash',
        default                => false,
    };
}

/**
 * What a click on an open entry may offer — the narrow ones only (concept 3.1); broader ones by hand in his setup. The
 * first is the narrowest that covers more than «I know, thanks» would. Each: {type, on, kinds, ask, covers (open
 * entries it would note, this one too)}. Every kind has `key` (exactly this thing); the logins (the pilot) more.
 *
 * @return list<array{type: string, on: array, kinds: list<string>, ask: bool, covers: int, what?: string, opt?: array}>
 */
function watchmanRuleOffers(array $e, array $book): array
{
    $kind = (string) ($e['kind'] ?? '');
    $cat = watchmanCatOf($kind);
    if ($cat === null || !watchmanOpen($e) || !is_string($e['key'] ?? null) || $e['key'] === '' || strlen($e['key']) > WATCH_RULE_TEXT) {
        return [];
    }
    $p = (array) ($e['p'] ?? []);
    $ip = is_string($p['ip'] ?? null) && filter_var($p['ip'], FILTER_VALIDATE_IP) !== false ? $p['ip'] : '';
    $out = [];
    if ($cat === 'login' && $ip !== '' && in_array($kind, WATCH_RULE_LOGIN, true)) {
        $net = watchmanNetOf($ip);
        $users = array_values(array_filter((array) ($p['users'] ?? []), 'is_string'));
        if ($net !== null) {
            if ($kind === 'login_new_ip' && count($users) === 1 && preg_match(WATCH_RULE_USER, $users[0])) {
                $out[] = ['type' => 'user_net', 'on' => ['user' => $users[0], 'net' => $net], 'kinds' => ['login_new_ip']];
            }
            $out[] = ['type' => 'net', 'on' => ['net' => $net], 'kinds' => WATCH_RULE_LOGIN];
        } else {
            $out[] = ['type' => 'ip', 'on' => ['ip' => $ip], 'kinds' => WATCH_RULE_LOGIN];        // a public address: only itself
        }
        if ($kind === 'smb_hour' && is_string($p['machine'] ?? null) && $p['machine'] !== '' && strlen($p['machine']) <= WATCH_RULE_TEXT) {
            array_unshift($out, ['type' => 'machine', 'on' => ['machine' => $p['machine']], 'kinds' => ['smb_hour']]);
        }
    }
    if ($kind === 'plugin_new' && is_string($p['source'] ?? null) && str_contains($p['source'], '/') && preg_match(WATCH_RULE_SOURCE, $p['source'])) {
        $out[] = ['type' => 'source', 'on' => ['source' => $p['source']], 'kinds' => ['plugin_new']];      // github.com/<owner>: a code host's owner
    }
    if ($kind === 'cron_new' && is_array($p['_from'] ?? null) && count($p['_from']) === 1 && is_string($p['_from'][0])
        && str_starts_with($p['_from'][0], 'cron.d/') && preg_match(WATCH_RULE_CROND, substr($p['_from'][0], 7))) {
        $out[] = ['type' => 'file', 'on' => ['file' => substr($p['_from'][0], 7)], 'kinds' => ['cron_new']];
    }
    if ($kind === 'at_job' && is_string($p['_one'] ?? null) && preg_match(WATCH_RULE_CMD, $p['_one']) && empty($p['_changed'])) {
        $out[] = ['type' => 'cmd', 'on' => ['cmd' => $p['_one'], 'user' => (string) ($p['user'] ?? ''), 'owner' => (string) ($p['owner'] ?? '')],
                  'kinds' => ['at_job'], 'what' => mb_strcut((string) ($p['cmd'] ?? ''), 0, 200, 'UTF-8')];
    }
    $thing = in_array($kind, ['flow_written', 'flow_gone'], true) ? watchmanFlowThing($e['key']) : null;
    if ($thing !== null && preg_match($thing[0] === 'share' ? WATCH_RULE_SHARE : WATCH_RULE_DISK, $thing[1])) {
        // the data in a share or on a disk (6b): don't judge its deletions / its writing; from an amount; the share's snapshots
        $files = str_starts_with($e['key'], 'flow_gone:files:');
        $out[] = ['type' => $thing[0], 'on' => [$thing[0] => $thing[1]], 'kinds' => [$kind]];
        $peak = max((int) ($p['peak'] ?? 0), (int) ($p['hour'] ?? 0));
        if ($peak > 0) {
            $step = $files ? 1000 : 1024 ** 3;          // twice the busiest hour seen, up to whole thousands of files or GB
            $out[] = ['type' => 'min', 'on' => [$thing[0] => $thing[1]], 'kinds' => [$kind], 'opt' => [$files ? 'files' : 'min' => (int) (ceil(2 * $peak / $step) * $step)]];
        }
        if ($kind === 'flow_gone' && !$files && $thing[0] === 'share' && (int) ($p['kept'] ?? 0) > 0) {
            $out[] = ['type' => 'kept', 'on' => ['share' => $thing[1]], 'kinds' => ['flow_gone'], 'opt' => ['kept' => false]];
        }
        if ($kind === 'flow_written' && $thing[0] === 'share' && array_filter(array_keys((array) ($p['src'] ?? [])), fn ($k) => str_starts_with((string) $k, 'ly:'))) {
            $out[] = ['type' => 'layers', 'on' => ['share' => $thing[1]], 'kinds' => ['flow_written'], 'opt' => ['layers' => false]];
        }
    } elseif ($kind === 'flow_client' && (int) ($p['peak'] ?? 0) > 0) {
        $out[] = ['type' => 'min', 'on' => ['key' => $e['key']], 'kinds' => ['flow_client'], 'opt' => ['min' => (int) (ceil(2 * (int) $p['peak'] / 1024 ** 3) * 1024 ** 3)]];
    }
    if ($kind === 'snap_gone') {
        // a series (only one named in the entry): on its dataset (only one), or everywhere — never the pool's key (6c)
        $series = array_values(array_filter((array) ($p['series'] ?? []), 'is_string'));
        $ds = array_values(array_filter((array) ($p['datasets'] ?? []), 'is_string'));
        if (count($series) === 1 && preg_match(WATCH_RULE_SERIES, $series[0])) {
            if (count($ds) === 1 && watchmanRuleDsOk($ds[0])) {
                $out[] = ['type' => 'series_ds', 'on' => ['series' => $series[0], 'dataset' => $ds[0]], 'kinds' => ['snap_gone']];
            }
            $out[] = ['type' => 'series', 'on' => ['series' => $series[0]], 'kinds' => ['snap_gone']];
        }
    } elseif ($kind === 'share_public' && in_array($p['proto'] ?? null, ['smb', 'nfs'], true) && is_string($p['share'] ?? null)
              && preg_match(WATCH_RULE_SHARE, $p['share']) && $p['share'] !== 'flash') {
        // the share may be open to guests (reading, or also writing — as it is now); the flash only by its key, asked clearly
        $out[] = ['type' => 'open', 'on' => ['share' => $p['share'], 'proto' => $p['proto'], 'open' => ($p['level'] ?? '') === 'public' ? 'write' : 'read'], 'kinds' => ['share_public']];
    } elseif ($kind === 'door_new' && ($p['_opened'] ?? null) === false && preg_match('/^wg:(.+)$/D', (string) ($p['key'] ?? ''), $m) && preg_match(WATCH_RULE_TUNNEL, $m[1])) {
        $out[] = ['type' => 'tunnel', 'on' => ['tunnel' => $m[1]], 'kinds' => ['door_new']];
        $out[] = ['type' => 'key', 'on' => ['key' => $e['key']], 'kinds' => [$kind]];
    } elseif ($kind === 'snap_hold_released') {
        $ds = array_values(array_filter((array) ($p['datasets'] ?? []), 'is_string'));
        if (count($ds) === 1 && watchmanRuleDsOk($ds[0])) {
            $out[] = ['type' => 'hold', 'on' => ['dataset' => $ds[0]], 'kinds' => ['snap_hold_released']];
        }
    } elseif ($thing === null) {
        if ($kind === 'listen_new' && is_string($p['prog'] ?? null) && $p['prog'] !== '?' && preg_match(WATCH_RULE_PROG, $p['prog']) && ($p['port'] ?? null) !== null) {
            $out[] = ['type' => 'prog', 'on' => ['prog' => $p['prog']], 'kinds' => ['listen_new']];    // this program on any port
        }
        $out[] = ['type' => 'key', 'on' => ['key' => $e['key']], 'kinds' => [$kind]];
    }
    foreach ($out as $i => $o) {
        $rule = ['kinds' => $o['kinds'], 'on' => $o['on']];
        $out[$i]['ask'] = (bool) array_intersect($o['kinds'], WATCH_RULE_ASK) || watchmanRuleAskThis($e, $o);
        // a threshold or a snapshot rule notes nothing open at once (it works where the flow is judged, the snapshots listed): this one only
        $out[$i]['covers'] = isset($o['opt']) || isset($o['on']['series']) || isset($o['on']['dataset']) ? 1 : count(array_filter($book, fn ($x) => is_array($x) && watchmanOpen($x) && watchmanRuleCovers($rule, $x)));
    }
    return $out;
}

/**
 * The pass of a round (and of the night shift's): the entries new in this round ($before: the ids before it) in an area
 * switched off are made normal and left out; those a rule covers are noted by it — or bring its earlier line up to date.
 * Never an entry with `again` older than the decision, never one of a chain forming. Hits counted in $st['rule_hits'].
 *
 * @param array<string, int> $incidents  serious incident id => when it was marked
 * @return array{noted: int, merged: int, off: int, known: int}
 */
function watchmanRulesApply(array &$book, array $before, array &$b, array $seen, array $rules, array $incidents, array &$st, int $now): array
{
    $res = ['noted' => 0, 'merged' => 0, 'off' => 0, 'known' => 0, 'quiet' => 0];
    $rules += ['known' => [], 'set' => []];
    $modes = watchmanKindModes((array) $rules['set']);
    if (!$rules['rules'] && !$rules['off'] && !$rules['known'] && !$modes) {
        return $res;
    }
    $chained = [];
    foreach (watchmanChains($book, $now) as $c) {
        foreach ((array) ($c['ids'] ?? []) as $id) {
            $chained[$id] = true;
        }
    }
    $newer = function (int $at, array $e) use ($incidents): bool {
        return !isset($e['again']) || $at >= (int) ($incidents[(string) $e['again']] ?? PHP_INT_MAX);
    };
    $hits = is_array($st['rule_hits'] ?? null) ? $st['rule_hits'] : [];
    $drop = [];
    foreach ($book as $i => $e) {
        if (isset($before[$e['id']]) || !watchmanOpen($e)) {
            continue;
        }
        $cat = watchmanCatOf((string) $e['kind']);
        if ($cat !== null && isset($rules['off'][$cat]) && $newer($rules['off'][$cat], $e)) {
            watchmanAdopt($b, $e, $seen, $now);       // what is there now is normal: switched on again, he starts from here
            $drop[$i] = true;
            $res['off']++;
            continue;
        }
        // a kind set to «not at all» (WATCH_SETTINGS, a choice with `kinds`): normal at once, like an area switched off —
        // never the same as a serious incident, never part of a chain forming
        $mode = $modes[(string) $e['kind']] ?? null;
        if ($mode === 'none' && !isset($e['again']) && !isset($chained[$e['id']])) {
            watchmanAdopt($b, $e, $seen, $now);
            $drop[$i] = true;
            $res['quiet']++;
            continue;
        }
        if (isset($chained[$e['id']])) {
            continue;
        }
        // an answer you gave to exactly this, as it is now (4b): noted again, no question
        $mine = array_values(array_filter($rules['known'], fn ($k) => $newer($k['at'], $e)));
        if ($mine && watchmanAckLasts((string) $e['kind']) && watchmanKnownMeet($book, $i, $b, $seen, $mine, $now)) {
            $res['known']++;
            continue;
        }
        $ruled = false;
        foreach ($rules['rules'] as $r) {
            // a threshold (`opt`) works where the data flow is judged, never here; «always tell» (half a share, 200 000 files
            // in an hour) is let through by none
            if (isset($r['opt']) || !empty($e['p']['_must']) || !watchmanRuleCovers($r, $e) || !$newer($r['at'], $e)) {
                continue;
            }
            $ruled = true;
            $line = null;
            foreach ($book as $j => $x) {
                if ($j !== $i && !isset($drop[$j]) && ($x['by'] ?? null) === 'rule' && ($x['key'] ?? null) === $e['key'] && !watchmanOpen($x)) {
                    $line = $j;
                    break;
                }
            }
            // a state (not an event that counts up) the rule lets through stays a new entry each round — the baseline never
            // learns it: the same state again is no hit and counts nothing, only a changed one (6a)
            $bump = in_array($e['kind'], WATCH_BUMP_KINDS, true);
            $same = $line !== null && !$bump && (array) ($book[$line]['p'] ?? []) === (array) ($e['p'] ?? []);
            // a pull of the data flow going on (its line seen within WATCH_FLOW_GOING): the same pull, brought up to date (6b)
            $going = $line !== null && str_starts_with((string) $e['kind'], 'flow_') && $now - (int) $book[$line]['last'] <= WATCH_FLOW_GOING;
            $same = $same || $going;
            if (!$same) {
                $hits[$r['id']] = ['n' => (int) ($hits[$r['id']]['n'] ?? 0) + 1, 'last' => $now, 'key' => mb_strcut((string) $e['key'], 0, WATCH_RULE_TEXT, 'UTF-8')];
            }
            if ($line !== null) {
                $x = $book[$line];
                $x['count'] = (int) $x['count'] + ($bump ? (int) $e['count'] : ($same ? 0 : 1));
                $x['time'] = min((int) $x['time'], (int) $e['time']);
                $x['last'] = max((int) $x['last'], (int) $e['last']);
                $x['p'] = $bump ? watchmanMerge((array) ($x['p'] ?? []), (array) ($e['p'] ?? [])) : (array) ($e['p'] ?? []);
                $x['noted'] = $now;           // seen again: its line stays as long as the rule meets it
                $x['rule'] = $r['id'];
                $book[$line] = $x;
                $drop[$i] = true;
                $res['merged']++;
            } else {
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'rule';
                $book[$i]['rule'] = $r['id'];
                $res['noted']++;
            }
            break;
        }
        if (!$ruled && $mode === 'tell') {
            $book[$i]['important'] = true;          // a quiet kind set to «tell me»: told like an important one
        }
    }
    if ($drop) {
        $book = array_values(array_diff_key($book, $drop));
    }
    $known = array_flip(array_column($rules['rules'], 'id'));
    $st['rule_hits'] = array_intersect_key($hits, $known);          // a rule gone: its count with it
    return $res;
}

/**
 * rules.json changed by a click, under his book's lock: $change gets the file as it lies (tolerant) and the book, and
 * returns both; written only when it changed.
 */
function watchmanRulesChange(string $dir, callable $change): mixed
{
    $out = watchmanLocked($dir, function () use ($dir, $change): mixed {
        $raw = watchmanRulesRaw($dir);
        $d = watchmanLoad($dir);
        if (!is_array($d['baseline'])) {
            throw new Problem('watch_gone');
        }
        [$new, $d2, $res] = $change($raw, $d);
        if ($new !== $raw) {
            $new['v'] = 1;
            writeAtomic("$dir/" . WATCH_RULES_FILE, jsonEncode($new));
        }
        if ($d2 !== null) {
            $d2['state']['open'] = watchmanOpenCounts($d2['book']);
            watchmanSave($dir, $d, ['baseline' => $d2['baseline'], 'book' => $d2['book'], 'state' => $d2['state']]);
        }
        return $res;
    });
    // the night shift's copies follow from the page's path (watchmanMirrorKeep() → watchmanRulesCopy()), never a test's
    return $out;
}

/** A rule's id: r + 10 hex, not one of the file's */
function watchmanRuleNewId(array $raw): string
{
    $taken = array_flip(array_map(fn ($r) => is_array($r) ? (string) ($r['id'] ?? '') : '', (array) ($raw['rules'] ?? [])));
    do {
        $id = 'r' . bin2hex(random_bytes(5));
    } while (isset($taken[$id]));
    return $id;
}

/**
 * «… always okay» on an open entry (his page): the offer of that type looked up anew from the entry as it is now (the
 * page sends only the entry and the type — never a net or a name), $sure for a kind he asks about more clearly; the
 * rule written, every open entry it covers noted by it at once.
 */
function watchmanRuleAdd(string $id, string $type, bool $sure, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    if (!preg_match(WATCH_ID, $id) || !preg_match('/^[a-z_]{1,16}$/D', $type)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    $res = watchmanRulesChange($dir, function (array $raw, array $d) use ($id, $type, $sure, $now): array {
        $e = null;
        foreach ($d['book'] as $x) {
            if (($x['id'] ?? null) === $id && watchmanOpen($x)) {
                $e = $x;
            }
        }
        if ($e === null) {
            throw new Problem('watch_gone');
        }
        $offer = array_values(array_filter(watchmanRuleOffers($e, $d['book']), fn ($o) => $o['type'] === $type))[0] ?? null;
        if ($offer === null) {
            throw new Problem('bad_request');
        }
        if ($offer['ask'] && !$sure) {
            throw new Problem('watch_rule_ask');
        }
        $cat = (string) watchmanCatOf((string) $e['kind']);
        $have = watchmanRules($raw);
        if (count($have['rules']) >= WATCH_RULES_MAX || count(array_filter($have['rules'], fn ($r) => $r['cat'] === $cat)) >= WATCH_RULES_CAT) {
            throw new Problem('watch_rules_full');
        }
        $rule = ['id' => watchmanRuleNewId($raw), 'cat' => $cat, 'kinds' => $offer['kinds'], 'on' => $offer['on'], 'at' => $now, 'from' => $id, 'note' => '']
              + (isset($offer['what']) && $offer['what'] !== '' ? ['what' => $offer['what']] : []) + (isset($offer['opt']) ? ['opt' => $offer['opt']] : []);
        $raw['rules'] = array_merge(is_array($raw['rules'] ?? null) && array_is_list($raw['rules']) ? $raw['rules'] : [], [$rule]);
        $book = $d['book'];
        $noted = 0;
        foreach ($book as $i => $x) {
            // a threshold notes only the entry it was made from (it works where the flow is judged); «always tell» stays open
            $own = isset($rule['opt']) || isset($rule['on']['series']) || isset($rule['on']['dataset']);
            if (watchmanOpen($x) && ($own ? $x['id'] === $id : watchmanRuleCovers($rule, $x)) && empty($x['p']['_must'])) {
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'rule';
                $book[$i]['rule'] = $rule['id'];
                $noted++;
            }
        }
        $d['book'] = $book;
        return [$raw, $d, ['rule' => $rule, 'noted' => $noted]];
    });
    watchmanRuleSay('add', $res['rule']);
    if (!$page) {
        return ['ok' => true, 'rule' => $res['rule']['id'], 'noted' => $res['noted']];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'rule' => $res['rule']['id'], 'noted' => $res['noted'], 'state' => watchmanPageState()];
}

/**
 * «Lift» an exception (it goes; the next round tells what is still different) — with $undo («Undo» right after it was
 * made) the entries it noted are open again too. A rule not in the file (gone meanwhile): watch_rule_gone.
 */
function watchmanRuleRemove(string $rid, bool $undo, ?string $dir = null, bool $page = true): array
{
    if (!preg_match(WATCH_RULE_ID, $rid)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $res = watchmanRulesChange($dir, function (array $raw, array $d) use ($rid, $undo): array {
        $list = is_array($raw['rules'] ?? null) && array_is_list($raw['rules']) ? $raw['rules'] : [];
        $gone = array_values(array_filter($list, fn ($r) => is_array($r) && ($r['id'] ?? null) === $rid));
        if (!$gone) {
            throw new Problem('watch_rule_gone');
        }
        $raw['rules'] = array_values(array_filter($list, fn ($r) => !is_array($r) || ($r['id'] ?? null) !== $rid));
        $reopened = 0;
        if ($undo) {
            foreach ($d['book'] as $i => $x) {
                if (($x['by'] ?? null) === 'rule' && ($x['rule'] ?? null) === $rid) {
                    $d['book'][$i]['noted'] = null;
                    $d['book'][$i]['by'] = null;
                    unset($d['book'][$i]['rule']);
                    $reopened++;
                }
            }
        }
        return [$raw, $undo ? $d : null, ['rule' => $gone[0], 'reopened' => $reopened]];
    });
    watchmanRuleSay($undo ? 'undo' : 'remove', $res['rule']);
    if (!$page) {
        return ['ok' => true, 'reopened' => $res['reopened']];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'reopened' => $res['reopened'], 'state' => watchmanPageState()];
}

/**
 * An area switched off (always with $sure: his page asked more clearly and said what you won't see) or on again.
 * Off: nothing new of it in his book, nothing told — he keeps what is normal up to date underneath. On: from now.
 */
function watchmanCatOff(string $cat, bool $off, bool $sure, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    if (!isset(WATCH_CATS[$cat])) {
        throw new Problem('bad_request');
    }
    if ($off && !$sure) {
        throw new Problem('watch_rule_ask');
    }
    $on = !$off;
    $dir ??= watchmanDir();
    $now ??= time();
    watchmanRulesChange($dir, function (array $raw, array $d) use ($cat, $on, $now): array {
        $off = is_array($raw['off'] ?? null) && !array_is_list($raw['off']) ? $raw['off'] : [];
        if ($on) {
            unset($off[$cat]);
        } elseif (!is_int($off[$cat]['at'] ?? null)) {
            $off[$cat] = ['at' => $now];
        }
        $raw['off'] = $off;
        if (!$off) {
            unset($raw['off']);
        }
        return [$raw, null, null];
    });
    watchmanRuleSay($on ? 'on' : 'off', ['cat' => $cat]);
    if (!$page) {
        return ['ok' => true];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** Each change in the messenger's log and, with the SIEM switch on, as a line in the syslog (never a note's words) */
function watchmanRuleSay(string $what, array $rule): void
{
    $on = (array) ($rule['on'] ?? []);
    $named = $on ? ' ' . implode(' ', array_map(fn ($k, $v) => "$k=" . mb_strcut((string) $v, 0, 80, 'UTF-8'), array_keys($on), $on)) : '';
    logLine(sprintf('Night watchman: exception %s — area %s%s%s', $what, (string) ($rule['cat'] ?? ''), isset($rule['id']) ? " ({$rule['id']})" : '', $named));
    $st = readJson(watchmanDir() . '/state.json') ?? [];
    $logger = watchmanPaths()['logger'] ?? null;
    if (!empty($st['siem']) && is_string($logger) && is_executable($logger)) {
        run([$logger, '-t', 'uso-watchman', '-p', 'user.notice', '--', jsonEncode(['v' => 1, 'kind' => 'rule', 'what' => $what,
            'category' => (string) ($rule['cat'] ?? ''), 'rule' => $rule['id'] ?? null, 'kinds' => $rule['kinds'] ?? null, 'on' => $on ?: null,
            'time' => date('c')])], 5);
    }
}

/**
 * The night shift's copy: what applies (no notes), {v, rules, off} — in RAM after every change and when it differs, on
 * the flash only when it changed (a reboot's night). Never in the way: a failure is logged.
 */
function watchmanRulesCopy(string $dir, string $ram = WATCH_RULES_RAM, ?string $flash = WATCH_RULES_FLASH): void
{
    try {
        $r = watchmanRules(watchmanRulesRaw($dir));
        $raw = watchmanRulesRaw($dir);
        $copy = jsonEncode(['v' => 1, 'rules' => array_map(fn ($x) => array_diff_key($x, ['note' => 1]), $r['rules']), 'off' => $r['off'] ?: new stdClass(),
            'set' => is_array($raw['set'] ?? null) && $raw['set'] ? $raw['set'] : new stdClass(),           // his settings (step 5)
            // your answers as short fingerprints (4b): the night tells nothing you already know
            'known' => array_map(fn ($k) => array_intersect_key($k, ['id' => 1, 'kind' => 1, 'key' => 1, 'sig' => 1, 'at' => 1]), $r['known'])]);
        foreach ([$ram, $flash] as $f) {
            if ($f === null || !is_dir(dirname($f)) || @file_get_contents($f) === $copy) {
                continue;
            }
            writeAtomic($f, $copy, 0600, 0, 0);
        }
    } catch (Throwable $e) {
        logLine('Night watchman: could not write his exceptions for the night shift: ' . $e->getMessage());
    }
}

/** The night's rules: the RAM copy, else the flash's — in exactly their shape (`v` 1), else none (louder, never quieter) */
function watchmanRulesNight(string $night, string $ram = WATCH_RULES_RAM, ?string $flash = WATCH_RULES_FLASH): void
{
    foreach ([$ram, $flash] as $f) {
        $j = $f !== null ? readJson($f) : null;
        if (is_array($j) && ($j['v'] ?? null) === 1) {
            writeAtomic("$night/" . WATCH_RULES_FILE, jsonEncode(['v' => 1, 'rules' => (array) ($j['rules'] ?? []), 'off' => $j['off'] ?? new stdClass(),
                'known' => array_values((array) ($j['known'] ?? [])), 'set' => $j['set'] ?? new stdClass()]), 0600, 0, 0);
            return;
        }
    }
    @unlink("$night/" . WATCH_RULES_FILE);
}

/**
 * For his page: the rules (each with its hits), the areas switched off, and per open entry what a click may offer
 * (watchmanRuleOffers(); covers counted on the book as it is now)
 *
 * @return array{rules: list<array>, off: array<string, int>, offers: array<string, list<array>>, known: list<array>, known_full: bool, settings: array, hand: array, hand_amount: array}
 */
function watchmanRulesPage(string $dir, array $book, array $st): array
{
    $r = watchmanRules(watchmanRulesRaw($dir));
    $hits = is_array($st['rule_hits'] ?? null) ? $st['rule_hits'] : [];
    $rules = array_map(fn ($x) => $x + ['hits' => (int) ($hits[$x['id']]['n'] ?? 0), 'hit' => is_int($hits[$x['id']]['last'] ?? null) ? $hits[$x['id']]['last'] : null],
        $r['rules']);
    $offers = [];
    foreach ($book as $e) {
        if (is_array($e) && watchmanOpen($e) && is_string($e['id'] ?? null) && ($o = watchmanRuleOffers($e, $book))) {
            $offers[$e['id']] = $o;
        }
    }
    // the page never sees a fingerprint or an internal word (`_h`)
    $known = array_map(fn ($k) => ['p' => array_filter($k['p'], fn ($x) => !str_starts_with((string) $x, '_'), ARRAY_FILTER_USE_KEY)] + array_diff_key($k, ['sig' => 1]),
        $r['known']);
    $raw = watchmanRulesRaw($dir);
    return ['rules' => $rules, 'off' => $r['off'], 'offers' => $offers, 'known' => $known, 'known_full' => is_int($raw['known_full'] ?? null),
            'settings' => watchmanSettingsPage($raw), 'hand' => WATCH_HAND, 'hand_amount' => WATCH_HAND_AMOUNT];
}

// ===================================================================== «I know, thanks» holds until taken back (1.96 step 4b)

/*
 * Concept 3.4: an answer to a STATE (an address, a container's rights, a plugin and its source, a file, a key, a port …)
 * is kept a second time in rules.json `known` — the kind, the key, a fingerprint of the thing as it was when you answered
 * (taken from what his round saw then, never from the entry's words: a password changed again is a new fingerprint), when.
 * No 30 days, nothing dropped quietly: at WATCH_KNOWN_MAX he says it is full. When the same thing comes back new (it was
 * gone over 30 days and forgotten, a list was full, a data flow's series went), with the same fingerprint, he notes it
 * himself (`by: known`) and makes it normal again — he doesn't ask; with another fingerprint the entry says it differs
 * from what you knew. An EVENT (a log emptied, refusals at the door, a hold released …) is «this time» only, as before.
 * «Take back»: the answer goes and what it made normal comes out of the baseline again (watchmanUnadopt()) — still there,
 * his next round tells it. An older office doesn't read `known`: it behaves as before.
 */
const WATCH_KNOWN_MAX  = 5000;
const WATCH_KNOWN_ID   = '/^k[0-9a-f]{10}$/D';
// what happens rather than is: «I know, thanks» there is for this time (and what he notes himself never asks)
const WATCH_ACK_EVENTS = ['log_cleared', 'door_refused', 'snap_hold_released', 'kopia_emptied', 'net_firewall_change', 'net_link_down', 'net_outage',
                          'net_router_reboot', 'net_log_silent', 'net_sender_new', 'net_new_device', 'net_router_config', 'array_stop', 'array_start',
                          'server_boot', 'parity_check', 'drill_throwaway', 'partner_paired', 'at_userscript', 'at_plugin', 'smb_hour',
                          // a fault in root's crontab (doubled lines, the office's own there): fixed and back is news again
                          'cron_twice', 'cron_office'];
// the words of an entry kept with its answer (what its line and «take back» need), nothing else
const WATCH_KNOWN_KEEP = ['ip', 'name', 'file', 'user', 'fp', 'share', 'proto', 'series', 'id', 'job', 'key', 'mac', 'what', 'admin', 'sig', 'dir',
                          'source', 'tokens', 'users', 'services', 'machine', 'peak', 'line', '_h', 'device'];

/** Is this kind's answer a lasting one? (a state — else an event: this time only) */
function watchmanAckLasts(string $kind): bool
{
    return isset(WATCH_KINDS[$kind]) && !in_array($kind, WATCH_ACK_EVENTS, true) && !($kind === 'net_ips_server');
}

/**
 * The fingerprint of the thing an entry tells of, as his round sees it now ($seen): the state that, changed, makes it
 * news again. '' for a thing its key names whole (an address, a port, a cron line by its hash); null: an event.
 */
function watchmanKnownSig(array $e, array $seen): ?string
{
    $kind = (string) ($e['kind'] ?? '');
    if (!watchmanAckLasts($kind)) {
        return null;
    }
    $p = (array) ($e['p'] ?? []);
    $v = match ($kind) {
        'container_new', 'container_privileged', 'container_host', 'container_ports', 'container_rights'
            => (function () use ($seen, $p) { $t = (array) ($seen['containers'][(string) ($p['name'] ?? '')]['tokens'] ?? []); sort($t); return $t; })(),
        'plugin_new', 'plugin_source' => $seen['plugins'][(string) ($p['name'] ?? '')]['source'] ?? null,
        'flash_go'                    => $seen['flash']['go'] ?? null,
        'flash_extra'                 => $seen['flash']['extra'][(string) ($p['file'] ?? '')] ?? null,
        'flash_user', 'flash_password' => [in_array((string) ($p['user'] ?? ''), (array) ($seen['flash']['users'] ?? []), true), $seen['flash']['pw'][(string) ($p['user'] ?? '')] ?? null],
        'flash_ssh_key'               => $seen['flash']['keys'][(string) ($p['user'] ?? '')][(string) ($p['fp'] ?? '')] ?? null,
        'share_public'                => $seen['shares'][(string) ($p['share'] ?? '')][($p['proto'] ?? '') === 'nfs' ? 'nfs' : 'smb'] ?? null,
        'user_ram'                    => $seen['host']['users'][(string) ($p['user'] ?? '')] ?? null,
        'door_new'                    => $seen['host']['doors'][(string) ($p['key'] ?? '')] ?? null,
        'api_key_new', 'api_key_changed' => $seen['host']['api'][(string) ($p['id'] ?? '')] ?? null,
        'door_changed'                => $seen['partner']['lines'][(string) ($p['id'] ?? '')] ?? null,
        'cron_file', 'cron_file_foreign' => $seen['sched']['files'][(string) ($p['file'] ?? '')]['h'] ?? null,
        'script_new', 'script_changed' => $seen['sched']['scripts'][(string) ($p['name'] ?? '')]['h'] ?? null,
        'notify_agent'                => $seen['sched']['agents'][(string) ($p['name'] ?? '')]['h'] ?? null,
        // the series that went: another series on the same dataset is another thing
        'snap_gone'                   => (function () use ($p) { $x = array_map('strval', (array) ($p['series'] ?? [])); sort($x); return $x; })(),
        default                       => '',
    };
    return $v === '' ? '' : substr(hash('sha256', (string) json_encode($v)), 0, 16);
}

/** The answers kept (`known` in rules.json), in exactly their shape */
function watchmanKnown(array $raw): array
{
    $out = [];
    foreach (is_array($raw['known'] ?? null) && array_is_list($raw['known']) ? array_slice($raw['known'], 0, WATCH_KNOWN_MAX) : [] as $k) {
        if (is_array($k) && is_string($k['id'] ?? null) && preg_match(WATCH_KNOWN_ID, $k['id']) && is_string($k['kind'] ?? null) && watchmanAckLasts($k['kind'])
            && is_string($k['key'] ?? null) && is_string($k['sig'] ?? null) && is_int($k['at'] ?? null)) {
            $out[] = ['id' => $k['id'], 'kind' => $k['kind'], 'key' => $k['key'], 'sig' => $k['sig'], 'at' => $k['at'],
                      'cat' => (string) watchmanCatOf($k['kind']), 'p' => is_array($k['p'] ?? null) ? $k['p'] : [], 't' => is_array($k['t'] ?? null) ? $k['t'] : []];
        }
    }
    return $out;
}

/**
 * The answers just given ($book's entries noted at $now by the page or the team lead, lasting kinds) into `known` —
 * one per kind and key (a newer answer replaces the older's fingerprint); called inside his book's lock. Full: none
 * added, `known_full` in the file says so.
 */
function watchmanKnownNote(string $dir, array $book, array $seen, int $now): void
{
    $raw = watchmanRulesRaw($dir);
    $list = is_array($raw['known'] ?? null) && array_is_list($raw['known']) ? $raw['known'] : [];
    $at = [];
    foreach ($list as $i => $k) {
        if (is_array($k)) {
            $at[($k['kind'] ?? '') . '|' . ($k['key'] ?? '')] = $i;
        }
    }
    $changed = false;
    foreach ($book as $e) {
        if ((int) ($e['noted'] ?? 0) !== $now || !in_array($e['by'] ?? null, ['page', 'teamlead'], true) || !empty($e['serious'])
            || !is_string($e['key'] ?? null) || ($sig = watchmanKnownSig($e, $seen)) === null) {
            continue;
        }
        $p = array_intersect_key((array) ($e['p'] ?? []), array_flip(WATCH_KNOWN_KEEP));
        if (strlen(jsonEncode($p)) > 600) {
            $p = array_intersect_key($p, array_flip(['ip', 'name', 'file', 'user', 'fp', 'share', 'proto', 'id', 'job', 'key', '_h']));
        }
        $rec = ['kind' => $e['kind'], 'key' => $e['key'], 'sig' => $sig, 'at' => $now, 'p' => $p, 't' => watchmanText($e)];
        if (($e['kind'] ?? '') !== '' && str_starts_with((string) $e['kind'], 'flow_')) {
            $rec['bytes'] = (int) ($e['p']['peak'] ?? 0);
        }
        $k = $e['kind'] . '|' . $e['key'];
        if (isset($at[$k])) {
            $list[$at[$k]] = ['id' => (string) ($list[$at[$k]]['id'] ?? watchmanKnownNewId($list))] + $rec;
        } elseif (count($list) < WATCH_KNOWN_MAX) {
            $list[] = ['id' => watchmanKnownNewId($list)] + $rec;
            $at[$k] = count($list) - 1;
        } else {
            $raw['known_full'] = $now;
        }
        $changed = true;
    }
    if ($changed) {
        $raw['v'] = 1;
        $raw['known'] = $list;
        writeAtomic("$dir/" . WATCH_RULES_FILE, jsonEncode($raw));
    }
}

/** A known answer's id: k + 10 hex, not one of the list's */
function watchmanKnownNewId(array $list): string
{
    $taken = array_flip(array_map(fn ($k) => is_array($k) ? (string) ($k['id'] ?? '') : '', $list));
    do {
        $id = 'k' . bin2hex(random_bytes(5));
    } while (isset($taken[$id]));
    return $id;
}

/**
 * Recognition in the pass (watchmanRulesApply()): a new entry of a lasting kind whose kind and key you answered — with
 * the same fingerprint now: made normal again and noted (`by: known`, `known`: the answer's id, when you knew it); a data
 * flow only while it stays within what you answered (WATCH_FLOW_FACTOR times its peak then). Another fingerprint: the
 * entry stays open and says when you knew it as it was then (`p.known_at`). Returns true when noted.
 */
function watchmanKnownMeet(array &$book, int $i, array &$b, array $seen, array $known, int $now): bool
{
    $e = $book[$i];
    foreach ($known as $k) {
        if ($k['kind'] !== $e['kind'] || $k['key'] !== ($e['key'] ?? null)) {
            continue;
        }
        $sig = watchmanKnownSig($e, $seen);
        $within = !isset($k['bytes']) || (int) ($e['p']['peak'] ?? 0) <= max(1, (int) $k['bytes']) * WATCH_FLOW_FACTOR;
        if ($sig !== null && $sig === $k['sig'] && $within) {
            watchmanAdopt($b, $e, $seen, $now);
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'known';
            $book[$i]['known'] = $k['id'];
            $book[$i]['p']['known_at'] = $k['at'];
            return true;
        }
        $book[$i]['p']['known_at'] = $k['at'];        // you knew it — as it was then: it says so
        return false;
    }
    return false;
}

/**
 * The counterpart of watchmanAdopt(): what an answer made normal comes out of the baseline again — per kind, only what
 * that answer put there. Still there, the next round tells it; gone, nothing. Events change nothing.
 */
function watchmanUnadopt(array &$b, string $kind, array $p): void
{
    $str = fn (string $k): string => is_scalar($p[$k] ?? null) ? (string) $p[$k] : '';
    switch ($kind) {
        case 'login_new_ip':
            unset($b['ips'][$str('ip')]);
            break;
        case 'login_failures':
            unset($b['fail_ips'][$str('ip')]);
            break;
        case 'container_new':
        case 'container_privileged':
        case 'container_host':
        case 'container_ports':
        case 'container_rights':
            unset($b['containers'][$str('name')]);
            break;
        case 'plugin_new':
        case 'plugin_source':
            unset($b['plugins'][$str('name')]);
            break;
        case 'flash_go':
            $b['flash']['go'] = null;
            break;
        case 'flash_extra':
            unset($b['flash']['extra'][$str('file')]);
            break;
        case 'flash_user':
        case 'flash_password':
            $b['flash']['users'] = array_values(array_diff((array) ($b['flash']['users'] ?? []), [$str('user')]));
            unset($b['flash']['pw'][$str('user')]);
            break;
        case 'flash_ssh_key':
            unset($b['flash']['keys'][$str('user')][$str('fp')]);
            break;
        case 'share_public':
            if (isset($b['shares'][$str('share')])) {
                $b['shares'][$str('share')][$str('proto') === 'nfs' ? 'nfs' : 'smb'] = 0;
            }
            break;
        case 'flow_client':
        case 'flow_container':
        case 'flow_written':
        case 'flow_gone':
            unset($b['flow']['ack'][$str('key')]);
            break;
        case 'smb_user':
            unset($b['flow']['smb_users'][$str('user')]);
            break;
        case 'smb_client':
            unset($b['flow']['smb_clients'][$str('ip')]);
            break;
        case 'snap_gone':
            foreach ((array) ($p['series'] ?? []) as $s) {
                unset($b['snaps']['series'][(string) $s]);
            }
            break;
        case 'user_ram':
            unset($b['host']['users'][$str('user')]);
            break;
        case 'door_new':
            unset($b['host']['doors'][$str('key')]);
            break;
        case 'api_key_new':
        case 'api_key_changed':
            unset($b['host']['api'][$str('id')]);
            break;
        case 'door_changed':
            unset($b['partner']['lines'][$str('id')]);
            break;
        case 'door_key_moved':
            unset($b['partner']['logins'][$str('ip') . '|' . $str('fp')]);
            break;
        case 'listen_new':
        case 'proc_odd':
            unset($b['host'][$kind === 'listen_new' ? 'listen' : 'procs'][$str('key')]);
            break;
        case 'cron_new':
        case 'cron_twice':
        case 'cron_office':
            $field = ['cron_new' => 'lines', 'cron_twice' => 'twice', 'cron_office' => 'office'][$kind];
            foreach ((array) ($p['_h'] ?? []) as $h) {
                unset($b['sched']['crontab'][$field][(string) $h]);
            }
            break;
        case 'cron_file':
        case 'cron_file_foreign':
            unset($b['sched']['files'][$str('file')]);
            break;
        case 'script_new':
        case 'script_changed':
            unset($b['sched']['scripts'][$str('name')]);
            break;
        case 'at_job':
            unset($b['sched']['at'][$str('job')]);
            break;
        case 'notify_agent':
            unset($b['sched']['agents'][$str('name')]);
            break;
        case 'net_spoof':
            $key = $str('what') === 'name' ? 'name:' . $str('name') : 'address:' . $str('ip');
            unset($b['net']['self'][$key][$str('mac')]);
            break;
        case 'net_router_login':
            unset($b['net']['admins'][$str('admin')]['ips'][$str('ip')]);
            break;
        case 'net_vpn_login':
            unset($b['net']['vpn'][$str('user')]);
            break;
        case 'net_blocked_from_server':
            unset($b['net']['blocked'][$str('key')]);
            break;
        case 'net_router_login_failures':
            unset($b['net']['fail_ips'][$str('ip') !== '' ? $str('ip') : 'local']);
            break;
    }
}

/** «Take back» an answer (its area's list «Noted»): it goes, and what it made normal comes out of the baseline */
function watchmanKnownRemove(string $kid, ?string $dir = null, bool $page = true): array
{
    if (!preg_match(WATCH_KNOWN_ID, $kid)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $k = watchmanRulesChange($dir, function (array $raw, array $d) use ($kid): array {
        $list = is_array($raw['known'] ?? null) && array_is_list($raw['known']) ? $raw['known'] : [];
        $gone = array_values(array_filter($list, fn ($k) => is_array($k) && ($k['id'] ?? null) === $kid));
        if (!$gone) {
            throw new Problem('watch_known_gone');
        }
        $raw['known'] = array_values(array_filter($list, fn ($k) => !is_array($k) || ($k['id'] ?? null) !== $kid));
        unset($raw['known_full']);
        $b = $d['baseline'];
        watchmanUnadopt($b, (string) ($gone[0]['kind'] ?? ''), (array) ($gone[0]['p'] ?? []) + ['key' => (string) ($gone[0]['key'] ?? '')]);
        $d['baseline'] = $b;
        return [$raw, $d, $gone[0]];
    });
    logLine(sprintf('Night watchman: an answer taken back — %s', (string) ($k['kind'] ?? '')));
    if (!$page) {
        return ['ok' => true];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'state' => watchmanPageState()];
}

// ===================================================================== settings per area (1.96 step 5)

/*
 * His settings, per area — in rules.json `set` only what differs from today's value (the default is what the code did
 * up to 1.95, with fixed bounds: a threshold never becomes «never» — that is what an exception or switching the area
 * off is for). The pilot is «Logins»: a burst of failed logins. More come with their areas' steps; a key here and its
 * words setting.<area>.<key> ×5 is all a new one needs (testWatchmanSettings).
 */
const WATCH_SETTINGS = [
    // each key: a number (def, min, max, unit), a switch (def: bool) or a choice (def, of); a choice with `kinds` says how
    // new entries of those kinds go: quiet (open, not told — what he did up to 1.95), tell (told like an important one),
    // none (normal at once, like an area switched off) — watchmanRulesApply()
    'login' => [
        // this file loads before watchman.php: the defaults as numbers — WATCH_FAIL_BURST and WATCH_FAIL_WINDOW (a test holds them equal)
        'burst'  => ['def' => 5, 'min' => 3, 'max' => 50, 'unit' => 'n'],            // failed logins from one address …
        'window' => ['def' => 600, 'min' => 60, 'max' => 3600, 'unit' => 's'],       // … within this many seconds: a burst
    ],
    'container' => [
        'ports' => ['def' => 'quiet', 'of' => ['quiet', 'tell', 'none'], 'kinds' => ['container_ports']],   // new ports of a known container
        'plain' => ['def' => false],        // a new container without special rights: a line noted by himself (container_plain)
    ],
    'plugin' => [
        'versions' => ['def' => false],     // a plugin's new version from the same source: a line noted by himself (plugin_version)
    ],
    'sched' => [
        'scripts' => ['def' => 'quiet', 'of' => ['quiet', 'tell'], 'kinds' => ['script_new', 'script_changed']],    // User Scripts
        'at_late' => ['def' => 2, 'min' => 1, 'max' => 14, 'unit' => 'd'],          // an at job still waiting this many days past its time: stuck (WATCH_AT_LATE)
    ],
    // the data flow (6b; watchmanFlowCompare()): what a client or container sends, what is written into or gone from the shares
    'pull' => [
        'factor' => ['def' => 4, 'min' => 2, 'max' => 20, 'unit' => 'x'],          // unusual: over this many times the normal (WATCH_FLOW_FACTOR) …
        'min'    => ['def' => 2, 'min' => 1, 'max' => 500, 'unit' => 'gb'],        // … and at least this much in the hour (WATCH_FLOW_MIN)
        'new'    => ['def' => 50, 'min' => 10, 'max' => 1000, 'unit' => 'gb'],     // still learning: one round over this (WATCH_FLOW_NEW)
    ],
    'data' => [
        'factor'      => ['def' => 4, 'min' => 2, 'max' => 20, 'unit' => 'x'],     // WATCH_FLOW_FACTOR, for the shares and disks
        'gone_min'    => ['def' => 2, 'min' => 1, 'max' => 500, 'unit' => 'gb'],   // gone: at least this much in the hour (WATCH_FLOW_MIN)
        'written_min' => ['def' => 2, 'min' => 1, 'max' => 500, 'unit' => 'gb'],   // written: likewise
        'files_min'   => ['def' => 2000, 'min' => 100, 'max' => 200000, 'unit' => 'n'],    // files deleted (WATCH_FILES_MIN)
        'kept'        => ['def' => true],       // what a snapshot still holds counts as gone (off: it is taken off the loss)
        'must'        => ['def' => true],       // always tell, whatever exception: half a share gone or 200 000 files deleted in an hour
    ],
    'snap' => [
        'offsite' => ['def' => 50, 'min' => 25, 'max' => 75, 'unit' => 'pct'],      // offsite: told when less than this part of the snapshots is left
    ],
    'flash' => [
        'go_blank' => ['def' => false],     // changes in go that touch only blanks and comments: not told (its commands the same)
    ],
    'share' => [
        'secure_ok' => ['def' => false],    // guests may read (⟦Secure⟧): normal — only ⟦Public⟧ told (never the flash)
    ],
];

/** A setting's value as the file has it, when it fits its key (type, bounds, choices) */
function watchmanSettingOk(array $d, mixed $v): bool
{
    return match (true) {
        is_bool($d['def'])   => is_bool($v),
        is_string($d['def']) => is_string($v) && in_array($v, $d['of'], true),
        default              => is_int($v) && $v >= $d['min'] && $v <= $d['max'],
    };
}

/** The settings in effect: every area's every key, the file's value where it fits, else the default */
function watchmanSettings(array $raw): array
{
    $out = [];
    foreach (WATCH_SETTINGS as $cat => $keys) {
        foreach ($keys as $k => $d) {
            $v = $raw['set'][$cat][$k] ?? null;
            $out[$cat][$k] = watchmanSettingOk($d, $v) ? $v : $d['def'];
        }
    }
    return $out;
}

/** The kinds a choice sets to «tell» or «not at all» (the settings in effect, watchmanSettings()): kind => tell|none */
function watchmanKindModes(array $set): array
{
    $out = [];
    foreach (WATCH_SETTINGS as $cat => $keys) {
        foreach ($keys as $k => $d) {
            $v = $set[$cat][$k] ?? $d['def'];
            if (isset($d['kinds']) && ($v === 'tell' || $v === 'none')) {
                foreach ($d['kinds'] as $kind) {
                    $out[$kind] = $v;
                }
            }
        }
    }
    return $out;
}

/**
 * «Apply» on an area's settings (his setup page): each key it knows, an integer in its bounds, a switch true or false, a
 * choice one of its own — anything else refused,
 * nothing half saved; a value equal to the default isn't kept (the file holds only differences). $reset: «Back to the
 * defaults» — the area's entry goes.
 */
function watchmanCatSettings(string $cat, mixed $set, bool $reset, ?string $dir = null, bool $page = true): array
{
    if (!isset(WATCH_SETTINGS[$cat]) || (!$reset && (!is_array($set) || array_is_list($set) && $set !== []))) {
        throw new Problem('bad_request');
    }
    $keep = [];
    foreach ($reset ? [] : (array) $set as $k => $v) {
        $d = WATCH_SETTINGS[$cat][(string) $k] ?? null;
        if ($d === null || (is_int($d['def']) ? !is_int($v) : !watchmanSettingOk($d, $v))) {
            throw new Problem('bad_request');
        }
        if (is_int($d['def']) && ($v < $d['min'] || $v > $d['max'])) {
            throw new Problem('watch_setting_range', ['min' => $d['min'], 'max' => $d['max']]);
        }
        if ($v !== $d['def']) {
            $keep[(string) $k] = $v;
        }
    }
    $dir ??= watchmanDir();
    watchmanRulesChange($dir, function (array $raw, array $d) use ($cat, $keep, $reset): array {
        $all = is_array($raw['set'] ?? null) && !array_is_list($raw['set']) ? $raw['set'] : [];
        $now = $reset ? [] : $keep + array_diff_key(is_array($all[$cat] ?? null) ? $all[$cat] : [], WATCH_SETTINGS[$cat]);
        if ($now) {
            $all[$cat] = $now;
        } else {
            unset($all[$cat]);
        }
        $raw['set'] = $all;
        if (!$all) {
            unset($raw['set']);
        }
        return [$raw, null, null];
    });
    logLine(sprintf('Night watchman: settings of the area %s %s', $cat, $reset ? 'back to the defaults' : 'changed: ' . (jsonEncode($keep) ?: '{}')));
    if (!$page) {
        return ['ok' => true];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'state' => watchmanPageState()];
}

/**
 * The exceptions made by hand in his setup, per area: type => what is typed. Logins: a net (a private one of any size from
 * /8; a public one at most a /24, only with the clear yes), an address (a public one with the clear yes), a user from a
 * net — covering new addresses and SMB machines, never failures, never a new SMB user. Containers: «the ports of X may
 * change», «X may change devices and capabilities». Plugins: «new plugins from <host>/<owner>» (a whole host only with the
 * clear yes). What starts by itself: «the User Script X may change», «the .cron file <plugin>/<file> may change» (only
 * while its plugin is installed: cron_file), «lines from /etc/cron.d/<file> are mine».
 */
const WATCH_HAND = [
    'login'     => ['net', 'ip', 'user_net'],
    'container' => ['ports', 'rights'],
    'plugin'    => ['source'],
    'sched'     => ['script', 'cron_file', 'crond'],
    // the data flow (6b): a computer may pull any amount, or from an amount an hour; a container always; a share's deletions or
    // writing not judged, or from an amount; its snapshots, new Docker images not counted; an XFS/btrfs disk likewise
    'pull'      => ['client', 'client_min', 'container'],
    'data'      => ['gone', 'written', 'gone_min', 'written_min', 'files_min', 'kept', 'layers', 'disk_gone', 'disk_written'],
    // snapshots (6c): a series may be thinned (everywhere, or on a dataset: the second field), on a dataset they may go
    // altogether, on a dataset a hold may be released — never a whole pool
    'snap'      => ['series', 'series_ds', 'dataset', 'hold'],
    // 6d: a file in /boot/extra may change; a share open to guests (reading, or also writing — the second field: SMB or NFS);
    // a port, a program on any port; new peers of a WireGuard tunnel
    'flash'     => ['extra'],
    'share'     => ['guest_read', 'guest_write'],
    'host'      => ['port', 'prog'],
    'door'      => ['tunnel'],
];
const WATCH_HAND_AMOUNT = ['client_min' => 'gb', 'gone_min' => 'gb', 'written_min' => 'gb', 'files_min' => 'n'];      // the types with an amount
const WATCH_HAND_NAME = '/^[A-Za-z0-9][A-Za-z0-9 _.+()-]{0,99}$/D';        // a container's or a User Script's name

/**
 * An exception made by hand (his setup page, WATCH_HAND): the type's value checked, the rule written; $user only for a
 * user from a net. A wide one only with the clear yes ($sure).
 *
 * @return array{cat: string, kinds: list<string>, on: array, wide: bool}
 */
function watchmanRuleHandMake(string $cat, string $type, string $value, string $user, int $amount = 0): array
{
    if (!in_array($type, WATCH_HAND[$cat] ?? [], true) || strlen($value) > 210) {
        throw new Problem('bad_request');
    }
    $value = trim($value);
    if (isset(WATCH_HAND_AMOUNT[$type]) && ($amount < 1 || $amount > (WATCH_HAND_AMOUNT[$type] === 'gb' ? 100_000 : 100_000_000))) {
        throw new Problem('watch_amount_bad');
    }
    $flow = function (string $what, array $kinds, ?array $opt) use ($cat, $value): array {
        if (!preg_match($what === 'share' ? WATCH_RULE_SHARE : WATCH_RULE_DISK, $value)) {
            throw new Problem('watch_value_bad');
        }
        return ['cat' => $cat, 'kinds' => $kinds, 'on' => [$what => $value], 'wide' => false] + ($opt !== null ? ['opt' => $opt] : []);
    };
    $gb = $amount * 1024 ** 3;
    $name = fn (string $kind) => preg_match(WATCH_HAND_NAME, $value) ? ['cat' => $cat, 'kinds' => [$kind], 'on' => ['key' => "$kind:$value"], 'wide' => false]
        : throw new Problem('watch_value_bad');
    switch ($type) {
        case 'ip':
            if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                throw new Problem('watch_net_bad');
            }
            return ['cat' => $cat, 'kinds' => WATCH_RULE_LOGIN, 'on' => ['ip' => $value], 'wide' => !watchmanIpPrivate($value)];
        case 'net':
        case 'user_net':
            if (!watchmanNetValid($value)) {
                throw new Problem('watch_net_bad');
            }
            [$addr, $bits] = explode('/', $value);
            $public = !watchmanIpPrivate($addr);
            if ($public && (int) $bits < (str_contains($addr, ':') ? 48 : 24)) {
                throw new Problem('watch_net_wide');
            }
            if ($type === 'net') {
                return ['cat' => $cat, 'kinds' => WATCH_RULE_LOGIN, 'on' => ['net' => $value], 'wide' => $public];
            }
            if (!preg_match(WATCH_RULE_USER, $user)) {
                throw new Problem('watch_net_bad');
            }
            return ['cat' => $cat, 'kinds' => ['login_new_ip'], 'on' => ['user' => $user, 'net' => $value], 'wide' => $public];
        case 'ports':
            return $name('container_ports');
        case 'rights':
            return $name('container_rights');
        case 'script':
            return $name('script_changed');
        case 'source':
            $value = strtolower(preg_replace('#^https?://#', '', $value) ?? '');
            if (!preg_match(WATCH_RULE_SOURCE, $value)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['plugin_new'], 'on' => ['source' => $value], 'wide' => !str_contains($value, '/')];
        case 'cron_file':
            if (!preg_match('#^[A-Za-z0-9._+-]{1,100}/[A-Za-z0-9._+-]{1,100}$#D', $value)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['cron_file'], 'on' => ['key' => "cron_file:$value"], 'wide' => false];
        case 'client':
        case 'client_min':
            if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                throw new Problem('watch_net_bad');
            }
            return ['cat' => $cat, 'kinds' => ['flow_client'], 'on' => ['ip' => $value], 'wide' => false] + ($type === 'client_min' ? ['opt' => ['min' => $gb]] : []);
        case 'container':
            return $name('flow_container');
        case 'gone':
            return $flow('share', ['flow_gone'], null);
        case 'written':
            return $flow('share', ['flow_written'], null);
        case 'gone_min':
            return $flow('share', ['flow_gone'], ['min' => $gb]);
        case 'written_min':
            return $flow('share', ['flow_written'], ['min' => $gb]);
        case 'files_min':
            return $flow('share', ['flow_gone'], ['files' => $amount]);
        case 'kept':
            return $flow('share', ['flow_gone'], ['kept' => false]);
        case 'layers':
            return $flow('share', ['flow_written'], ['layers' => false]);
        case 'disk_gone':
            return $flow('disk', ['flow_gone'], null);
        case 'disk_written':
            return $flow('disk', ['flow_written'], null);
        case 'extra':
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,119}$/D', $value)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['flash_extra'], 'on' => ['key' => "flash_extra:$value"], 'wide' => false];
        case 'guest_read':
        case 'guest_write':
            $proto = strtolower(trim($user)) ?: 'smb';
            if (!preg_match(WATCH_RULE_SHARE, $value) || $value === 'flash' || !in_array($proto, ['smb', 'nfs'], true)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['share_public'], 'on' => ['share' => $value, 'proto' => $proto, 'open' => $type === 'guest_write' ? 'write' : 'read'],
                    'wide' => $type === 'guest_write'];
        case 'port':
            if (!preg_match('/^[1-9][0-9]{0,4}$/D', $value) || (int) $value > 65535) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['listen_new'], 'on' => ['key' => "listen_new:tcp:$value"], 'wide' => false];
        case 'prog':
            if (!preg_match(WATCH_RULE_PROG, $value)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['listen_new'], 'on' => ['prog' => $value], 'wide' => false];
        case 'tunnel':
            if (!preg_match(WATCH_RULE_TUNNEL, $value)) {
                throw new Problem('watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => ['door_new'], 'on' => ['tunnel' => $value], 'wide' => false];
        case 'series':
        case 'series_ds':
            $s = $type === 'series' ? $value : trim($user);
            if (!preg_match(WATCH_RULE_SERIES, $s)) {
                throw new Problem('watch_value_bad');
            }
            if ($type === 'series') {
                return ['cat' => $cat, 'kinds' => ['snap_gone'], 'on' => ['series' => $s], 'wide' => true];
            }
            if (!watchmanRuleDsOk($value)) {
                throw new Problem(str_contains($value, '/') ? 'watch_value_bad' : 'watch_pool_whole');
            }
            return ['cat' => $cat, 'kinds' => ['snap_gone'], 'on' => ['series' => $s, 'dataset' => $value], 'wide' => true];
        case 'dataset':
        case 'hold':
            if (!watchmanRuleDsOk($value)) {
                throw new Problem(preg_match(WATCH_RULE_DS, $value) && !str_contains($value, '/') ? 'watch_pool_whole' : 'watch_value_bad');
            }
            return ['cat' => $cat, 'kinds' => [$type === 'dataset' ? 'snap_gone' : 'snap_hold_released'], 'on' => ['dataset' => $value], 'wide' => true];
        default:        // crond
            $value = preg_replace('#^/?etc/cron\.d/#', '', $value) ?? '';
            if (!preg_match(WATCH_RULE_CROND, $value) || $value === 'root') {
                throw new Problem('watch_value_bad');       // /etc/cron.d/root is Unraid's own: its lines twice are cron_twice, never «mine»
            }
            return ['cat' => $cat, 'kinds' => ['cron_new'], 'on' => ['file' => $value], 'wide' => false];
    }
}

/** «Add an exception…» (his setup page): watchmanRuleHandMake()'s rule written; a wide one only with the clear yes */
function watchmanRuleHand(string $cat, string $type, string $value, string $user, bool $sure, ?string $dir = null, ?int $now = null, bool $page = true,
                           int $amount = 0): array
{
    $made = watchmanRuleHandMake($cat, $type, $value, $user, $amount);
    // the data flow's let-through (a share's deletions, its writing, a computer's any amount) is what he asks about more clearly
    $made['wide'] = $made['wide'] || (bool) array_intersect($made['kinds'], WATCH_RULE_ASK);
    if ($made['wide'] && !$sure) {
        throw new Problem('watch_rule_ask');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    $rule = watchmanRulesChange($dir, function (array $raw, array $d) use ($made, $now): array {
        $have = watchmanRules($raw);
        if (count($have['rules']) >= WATCH_RULES_MAX || count(array_filter($have['rules'], fn ($r) => $r['cat'] === $made['cat'])) >= WATCH_RULES_CAT) {
            throw new Problem('watch_rules_full');
        }
        $rule = ['id' => watchmanRuleNewId($raw), 'cat' => $made['cat'], 'kinds' => $made['kinds'], 'on' => $made['on'], 'at' => $now, 'from' => 'hand', 'note' => '']
              + (isset($made['opt']) ? ['opt' => $made['opt']] : []);
        $raw['rules'] = array_merge(is_array($raw['rules'] ?? null) && array_is_list($raw['rules']) ? $raw['rules'] : [], [$rule]);
        return [$raw, null, $rule];
    });
    watchmanRuleSay('add', $rule);
    if (!$page) {
        return ['ok' => true, 'rule' => $rule['id']];
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'rule' => $rule['id'], 'state' => watchmanPageState()];
}

/** For his setup page: per area of WATCH_SETTINGS each key with its value now, its default, its bounds and unit or its choices */
function watchmanSettingsPage(array $raw): array
{
    $now = watchmanSettings($raw);
    $out = [];
    foreach (WATCH_SETTINGS as $cat => $keys) {
        foreach ($keys as $k => $d) {
            $out[$cat][$k] = ['value' => $now[$cat][$k], 'def' => $d['def']] + array_intersect_key($d, ['min' => 1, 'max' => 1, 'unit' => 1, 'of' => 1]);
        }
    }
    return $out;
}
