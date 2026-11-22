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
 * machine name>"}. Only a rule in exactly its shape is applied; anything else stays in the file and does nothing.
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
// the kinds he asks about more clearly before an exception (concept 3.3: what an attacker does first, or what points to
// damage — and comes rarely); every other kind is in WATCH_RULE_PLAIN (testWatchmanRules: each kind in exactly one)
const WATCH_RULE_ASK    = ['flash_go', 'flash_user', 'flash_password', 'flash_ssh_key', 'user_ram', 'log_cleared', 'plugin_source',
                           'cron_office', 'cron_twice', 'cron_file_foreign', 'notify_agent', 'api_key_new', 'api_key_changed', 'door_new',
                           'door_changed', 'door_refused', 'smb_user', 'login_failures', 'kopia_emptied', 'net_spoof', 'net_firewall_change',
                           'net_ips_server', 'net_log_silent', 'flow_written', 'flow_gone', 'snap_gone', 'snap_hold_released'];
const WATCH_RULE_PLAIN  = ['login_new_ip', 'smb_client', 'smb_hour', 'container_new', 'container_privileged', 'container_host', 'container_ports',
                           'container_rights', 'drill_throwaway', 'plugin_new', 'flash_extra', 'share_public', 'cron_new', 'cron_file', 'script_new',
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
 * @return array{rules: list<array>, off: array<string, int>}
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
    return ['rules' => $rules, 'off' => $off];
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
    return ['id' => $r['id'], 'cat' => $r['cat'], 'kinds' => array_values(array_unique($r['kinds'])), 'on' => $on, 'at' => $r['at'],
            'from' => is_string($r['from'] ?? null) && (preg_match(WATCH_ID, $r['from']) || $r['from'] === 'hand') ? $r['from'] : 'hand',
            'note' => is_string($r['note'] ?? null) ? mb_substr($r['note'], 0, WATCH_NOTE_MAX) : ''];
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
        default         => null,
    };
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
    return false;
}

/**
 * What a click on an open entry may offer — the narrow ones only (concept 3.1); broader ones by hand in his setup. The
 * first is the narrowest that covers more than «I know, thanks» would. Each: {type, on, kinds, ask, covers (open
 * entries it would note, this one too)}. Every kind has `key` (exactly this thing); the logins (the pilot) more.
 *
 * @return list<array{type: string, on: array, kinds: list<string>, ask: bool, covers: int}>
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
    $out[] = ['type' => 'key', 'on' => ['key' => $e['key']], 'kinds' => [$kind]];
    foreach ($out as $i => $o) {
        $rule = ['kinds' => $o['kinds'], 'on' => $o['on']];
        $out[$i]['ask'] = (bool) array_intersect($o['kinds'], WATCH_RULE_ASK);
        $out[$i]['covers'] = count(array_filter($book, fn ($x) => is_array($x) && watchmanOpen($x) && watchmanRuleCovers($rule, $x)));
    }
    return $out;
}

/**
 * The pass of a round (and of the night shift's): the entries new in this round ($before: the ids before it) in an area
 * switched off are made normal and left out; those a rule covers are noted by it — or bring its earlier line up to date.
 * Never an entry with `again` older than the decision, never one of a chain forming. Hits counted in $st['rule_hits'].
 *
 * @param array<string, int> $incidents  serious incident id => when it was marked
 * @return array{noted: int, merged: int, off: int}
 */
function watchmanRulesApply(array &$book, array $before, array &$b, array $seen, array $rules, array $incidents, array &$st, int $now): array
{
    $res = ['noted' => 0, 'merged' => 0, 'off' => 0];
    if (!$rules['rules'] && !$rules['off']) {
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
        if (isset($chained[$e['id']])) {
            continue;
        }
        foreach ($rules['rules'] as $r) {
            if (!watchmanRuleCovers($r, $e) || !$newer($r['at'], $e)) {
                continue;
            }
            $hits[$r['id']] = ['n' => (int) ($hits[$r['id']]['n'] ?? 0) + 1, 'last' => $now, 'key' => mb_strcut((string) $e['key'], 0, WATCH_RULE_TEXT, 'UTF-8')];
            $line = null;
            foreach ($book as $j => $x) {
                if ($j !== $i && !isset($drop[$j]) && ($x['by'] ?? null) === 'rule' && ($x['key'] ?? null) === $e['key'] && !watchmanOpen($x)) {
                    $line = $j;
                    break;
                }
            }
            if ($line !== null) {
                $x = $book[$line];
                $x['count'] = (int) $x['count'] + (int) $e['count'];
                $x['time'] = min((int) $x['time'], (int) $e['time']);
                $x['last'] = max((int) $x['last'], (int) $e['last']);
                $x['p'] = in_array($e['kind'], WATCH_BUMP_KINDS, true) ? watchmanMerge((array) ($x['p'] ?? []), (array) ($e['p'] ?? [])) : (array) ($e['p'] ?? []);
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
        $rule = ['id' => watchmanRuleNewId($raw), 'cat' => $cat, 'kinds' => $offer['kinds'], 'on' => $offer['on'], 'at' => $now, 'from' => $id, 'note' => ''];
        $raw['rules'] = array_merge(is_array($raw['rules'] ?? null) && array_is_list($raw['rules']) ? $raw['rules'] : [], [$rule]);
        $book = $d['book'];
        $noted = 0;
        foreach ($book as $i => $x) {
            if (watchmanOpen($x) && watchmanRuleCovers($rule, $x)) {
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
function watchmanCatSet(string $cat, bool $on, bool $sure, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    if (!isset(WATCH_CATS[$cat])) {
        throw new Problem('bad_request');
    }
    if (!$on && !$sure) {
        throw new Problem('watch_rule_ask');
    }
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
        $copy = jsonEncode(['v' => 1, 'rules' => array_map(fn ($x) => array_diff_key($x, ['note' => 1]), $r['rules']), 'off' => $r['off'] ?: new stdClass()]);
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
            writeAtomic("$night/" . WATCH_RULES_FILE, jsonEncode(['v' => 1, 'rules' => (array) ($j['rules'] ?? []), 'off' => $j['off'] ?? new stdClass()]), 0600, 0, 0);
            return;
        }
    }
    @unlink("$night/" . WATCH_RULES_FILE);
}

/**
 * For his page: the rules (each with its hits), the areas switched off, and per open entry what a click may offer
 * (watchmanRuleOffers(); covers counted on the book as it is now)
 *
 * @return array{rules: list<array>, off: array<string, int>, offers: array<string, list<array>>}
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
    return ['rules' => $rules, 'off' => $r['off'], 'offers' => $offers];
}
