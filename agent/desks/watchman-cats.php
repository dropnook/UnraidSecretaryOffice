<?php
declare(strict_types=1);

/*
 * The Night Watchman's areas (1.96, docs: the concept «Der Nachtwächter in 1.96», step 1 «areas as data»). An area is a
 * plain assignment: every kind of his book (WATCH_KINDS) and every posture tip (WATCH_POSTURE) belongs to exactly one
 * area — testWatchmanCats says so for each. The kinds' `group` stays as it is (every SIEM line carries it); the area is
 * what his page shows as a tile with a traffic light: `state.cats` (watchmanCats()), and each book entry's `cat`.
 * Functions only, in a file of its own beside his desk (the agent's desk glob loads it, watchman.php require_once's it):
 * its name sorts before watchman.php in every locale («cats» before «php»), as watchman-diskwakes.php does.
 *
 * The light, the same rule for every area (worst first):
 *   danger  an open entry that is important (`tell`: it was or would be told) — «waits for your answer» — or a hard
 *           finding of the area (the shutdown time-out too short, an unclean stop for the second time, a check with errors)
 *   warn    open entries, none of them important; or the area couldn't look (the syslog full, the router's share asleep,
 *           ZFS failed); or a finding to act on (something holds the array, an unclean stop)
 *   none    grey: nothing to watch here or switched off (no router, no spinning disk, Docker not running, never looked)
 *   note    green with «!»: nothing open, but advice you don't know yet, or a part not checked in the last round, or it
 *           is still learning
 *   ok      green: looked, nothing open
 * Info tips (WATCH_POSTURE 'info') never colour an area. `why` lists the reasons as codes (the page's words:
 * cat.why.<code>); the light never claims anything newer than what it is made of (`hold` is as fresh as its last look).
 * The concept's new area `vuln` is here without kinds until its step brings them: grey. `hw` (watchman-hw.php, step 9):
 * grey until his first look at the hardware.
 */

// area => its kinds and its posture tips, in the order of his page
const WATCH_CATS = [
    'login'     => ['kinds' => ['login_new_ip', 'login_failures', 'smb_user', 'smb_client', 'smb_hour'], 'tips' => []],
    'container' => ['kinds' => ['container_new', 'container_privileged', 'container_host', 'container_ports', 'container_rights', 'container_plain', 'drill_throwaway'],
                    'tips' => ['privileged']],
    'plugin'    => ['kinds' => ['plugin_new', 'plugin_source', 'plugin_version'], 'tips' => []],
    'flash'     => ['kinds' => ['flash_go', 'flash_extra', 'flash_user', 'flash_password', 'flash_ssh_key', 'flash_boot', 'user_ram'], 'tips' => []],
    'share'     => ['kinds' => ['share_public'], 'tips' => ['flash', 'public']],
    'sched'     => ['kinds' => ['cron_new', 'cron_twice', 'cron_office', 'cron_file', 'cron_file_foreign', 'script_new', 'script_changed', 'at_job',
                                'at_userscript', 'at_plugin', 'notify_agent'], 'tips' => []],
    'pull'      => ['kinds' => ['flow_client', 'flow_container'], 'tips' => []],
    'data'      => ['kinds' => ['flow_written', 'flow_gone'], 'tips' => []],
    'snap'      => ['kinds' => ['snap_gone', 'snap_hold_released', 'kopia_emptied'], 'tips' => []],
    'host'      => ['kinds' => ['listen_new', 'proc_odd', 'log_cleared', 'host_oom', 'host_crash', 'host_flood', 'ld_preload'],
                    'tips' => ['mitigations_off', 'vmscape', 'mitigations_on']],
    'door'      => ['kinds' => ['door_new', 'api_key_new', 'api_key_changed'], 'tips' => ['telnet', 'upnp', 'ftp', 'api_admin', 'remote_access']],
    'partner'   => ['kinds' => ['partner_paired', 'door_changed', 'door_key_moved', 'door_refused'],
                    'tips' => ['partner_wide', 'partner_unknown', 'partner_public', 'partner_friend']],
    'router'    => ['kinds' => ['net_sender_new', 'net_router_login', 'net_router_login_failures', 'net_firewall_change', 'net_router_config',
                                'net_vpn_login', 'net_log_silent', 'net_link_down', 'net_outage', 'net_router_reboot'], 'tips' => ['router_clock']],
    'lan'       => ['kinds' => ['net_new_device', 'net_spoof', 'net_ips_server', 'net_blocked_from_server', 'net_gateway', 'net_dns'], 'tips' => []],
    'array'     => ['kinds' => ['array_stop', 'array_start', 'server_boot', 'parity_check'], 'tips' => []],
    'vuln'      => ['kinds' => ['vuln_known', 'vuln_maybe'], 'tips' => ['unraid_eol', 'software_eol']],   // «Known vulnerabilities» (concept 11 A, watchman-vuln.php)
    'hw'        => ['kinds' => ['hw_mce', 'hw_ecc', 'hw_aer', 'hw_throttle', 'hw_kernel', 'hw_wear', 'hw_hot', 'hw_io'],
                    'tips' => ['cpu_fault', 'old_microcode']],    // «Hardware» (concept 11 B–E, H): watchman-hw.php (B, C, E), hw_hot (D), hw_io (H)
    'hold'      => ['kinds' => [], 'tips' => []],          // «What holds the array?» (agent/lib/arrayhold.php): no entries, its own look
    'wakes'     => ['kinds' => [], 'tips' => []],          // «Who woke my disks?» (watchman-diskwakes.php): no entries, his wake list
];

const WATCH_CAT_LEVELS = ['danger', 'warn', 'none', 'note', 'ok'];      // worst first: a combination takes the first one met
const WATCH_CAT_HOLD_OLD = 86400;                                        // «What holds the array?» looked longer ago: green with «!»
const WATCH_CAT_HW_OLD = 3600;                                           // the hardware looked longer ago (rounds failing): yellow
// his files in data/watchman/ whose size the page state and the metric say (concept 5.1: measured, not guessed)
const WATCH_CAT_FILES = ['book', 'baseline', 'state', 'seen', 'flow', 'snaps', 'net', 'wakes', 'incidents', 'posture', 'book-more', 'hw'];

/** The area of a kind of his book (null: a take-over line `watch`, or a kind no area knows) */
function watchmanCatOf(string $kind): ?string
{
    static $of = null;
    if ($of === null) {
        $of = [];
        foreach (WATCH_CATS as $cat => $c) {
            foreach ($c['kinds'] as $k) {
                $of[$k] = $cat;
            }
        }
    }
    return $of[$kind] ?? null;
}

/** The area of a posture tip (null: none) */
function watchmanCatOfTip(string $id): ?string
{
    foreach (WATCH_CATS as $cat => $c) {
        if (in_array($id, $c['tips'], true)) {
            return $cat;
        }
    }
    return null;
}

/** The sizes of his files in bytes (a missing one: 0) — a stat each, nothing read */
function watchmanFileSizes(string $dir): array
{
    $out = [];
    foreach (WATCH_CAT_FILES as $f) {
        clearstatcache(true, "$dir/$f.json");
        $st = @lstat("$dir/$f.json");
        $out[$f] = $st !== false && ($st['mode'] & 0170000) === 0100000 ? (int) $st['size'] : 0;
    }
    return $out;
}

/** The worst of some levels (WATCH_CAT_LEVELS' order); none given: ok */
function watchmanCatWorst(array $levels): string
{
    foreach (WATCH_CAT_LEVELS as $l) {
        if (in_array($l, $levels, true)) {
            return $l;
        }
    }
    return 'ok';
}

/**
 * Every area's light for his page, from his page state as watchmanPageState() made it ($s: hired, on_watch, round, book
 * with `tell` and `open`, posture, flow, snaps, net, hold, wakes, hw) and the parity's state ($parity: state.json's
 * `parity`). Pure: the tests hand in states. Each area: {level, why: [codes], open, tell, tips (advice not known yet),
 * entries (in his book), last (the newest entry's last time)}. Plus two of the cross-cut tiles: `book` (the worst
 * area) and `schedule` (his last round failed or broke off).
 *
 * @return array{areas: array<string, array>, book: string, schedule: string}
 */
function watchmanCats(array $s, ?array $parity = null): array
{
    $areas = [];
    foreach (WATCH_CATS as $cat => $c) {
        $areas[$cat] = ['levels' => [], 'why' => [], 'open' => 0, 'tell' => 0, 'tips' => 0, 'entries' => 0, 'last' => null];
    }
    $add = function (string $cat, string $level, string $why) use (&$areas): void {
        $areas[$cat]['levels'][] = $level;
        if (!in_array($why, $areas[$cat]['why'], true)) {
            $areas[$cat]['why'][] = $why;
        }
    };
    $out = function (string $level) use (&$areas): array {
        $res = [];
        foreach ($areas as $cat => $a) {
            $res[$cat] = ['level' => $level === '' ? watchmanCatWorst($a['levels']) : $level, 'why' => $a['why'], 'open' => $a['open'],
                          'tell' => $a['tell'], 'tips' => $a['tips'], 'entries' => $a['entries'], 'last' => $a['last']];
        }
        return $res;
    };
    if (empty($s['hired'])) {
        foreach (array_keys($areas) as $cat) {
            $add($cat, 'none', 'not_hired');
        }
        return ['areas' => $out('none'), 'book' => 'none', 'schedule' => 'none'];
    }
    // his book: open, important, newest per area
    $parityLast = null;
    foreach ((array) ($s['book'] ?? []) as $e) {
        $cat = is_array($e) ? watchmanCatOf((string) ($e['kind'] ?? '')) : null;
        if ($cat === null) {
            continue;
        }
        $areas[$cat]['entries'] += 1;
        $areas[$cat]['last'] = max((int) $areas[$cat]['last'], (int) ($e['last'] ?? 0));
        if (!empty($e['open'])) {
            $areas[$cat]['open'] += 1;
            $areas[$cat]['tell'] += !empty($e['tell']) ? 1 : 0;
        }
        if ($e['kind'] === 'parity_check' && isset($e['p']['end']) && (!$parityLast || (int) $e['time'] > (int) $parityLast['time'])) {
            $parityLast = $e;
        }
    }
    foreach ($areas as $cat => $a) {
        if ($a['tell']) {
            $add($cat, 'danger', 'open_tell');
        } elseif ($a['open']) {
            $add($cat, 'warn', 'open');
        }
    }
    // advice not known yet (info tips never colour)
    foreach ((array) ($s['posture']['tips'] ?? []) as $t) {
        $cat = is_array($t) ? watchmanCatOfTip((string) ($t['id'] ?? '')) : null;
        if ($cat !== null && ($t['level'] ?? '') === 'advice' && empty($t['known'])) {
            $areas[$cat]['tips']++;
            $add($cat, 'note', 'tips');
        }
    }
    // before his first round: nothing looked at yet — grey, unless something is open from an earlier watch
    if (empty($s['on_watch'])) {
        foreach (array_keys($areas) as $cat) {
            $add($cat, 'none', 'never');
        }
        $res = $out('');
        return ['areas' => $res, 'book' => watchmanCatBook($res), 'schedule' => 'none'];
    }
    $r = (array) ($s['round'] ?? []);
    // what each area couldn't see in his last round, and its own findings
    if (!empty($r['syslog_full'])) {
        $add('login', 'warn', 'syslog_full');
    }
    if (!empty($r['syslog_full']) || (int) ($r['skipped'] ?? 0) > 0) {
        // the signs in the log (the server itself, the hardware) come from the same syslog: not all of it read (concept 12.1)
        $add('host', 'note', 'syslog_signs');
        $add('hw', 'note', 'syslog_signs');
    }
    if (($r['docker'] ?? null) === false) {
        $add('container', 'none', 'docker_off');
    }
    if (($r['shares'] ?? null) === false) {
        $add('share', 'note', 'shares_silent');
    }
    $flow = $s['flow'] ?? null;
    foreach (['pull', 'data'] as $cat) {
        if (($r['flow'] ?? null) === false) {
            $add($cat, 'note', 'flow_silent');
        } elseif (is_array($flow) && (int) ($flow['days'] ?? 0) < (int) ($flow['learn'] ?? 7)) {
            $add($cat, 'note', 'learning');
        }
    }
    $snaps = $s['snaps'] ?? null;
    if (is_array($snaps)) {
        if (!empty($snaps['failed'])) {
            $add('snap', 'warn', 'snaps_failed');
        }
        if (!empty($snaps['capped'])) {
            $add('snap', 'warn', 'snaps_capped');
        }
        if (!empty($snaps['asleep'])) {
            $add('snap', 'note', 'asleep');
        }
    } elseif (($r['snaps'] ?? null) === false) {
        $add('snap', 'note', 'snaps_silent');
    }
    $net = $s['net'] ?? null;
    $netState = is_array($net) ? (string) ($net['state'] ?? 'off') : 'off';
    foreach (['router', 'lan'] as $cat) {
        if ($netState === 'off' || !is_array($net) || !($net['senders'] ?? [])) {
            // the network still has the server's own router and DNS (no router log needed): only a part not looked at
            $cat === 'lan' && !empty($r['lan']) ? $add($cat, 'note', 'no_router') : $add($cat, 'none', 'no_router');
        } elseif (in_array($netState, ['asleep', 'unreadable', 'unknown'], true)) {
            $add($cat, 'warn', 'router_' . $netState);
        }
    }
    $todo = is_array($parity['todo'] ?? null) ? $parity['todo'] : null;
    if ($todo !== null) {
        (int) ($todo['streak'] ?? 1) >= 2 ? $add('array', 'danger', 'parity_again') : $add('array', 'warn', 'parity_todo');
    }
    if ($parityLast !== null && (int) ($parityLast['p']['errors'] ?? 0) > 0) {
        $add('array', 'danger', 'parity_errors');
    }
    $look = is_array($s['hold']['look'] ?? null) ? $s['hold']['look'] : null;
    $now = (int) ($s['time'] ?? time());
    // re-learned (step 17): green with «!» — a day long, the data flow's two areas for their learning time again (each its own:
    // the other one's tile never says «learning» for it)
    foreach (is_array($s['relearn'] ?? null) ? $s['relearn'] : [] as $cat => $rl) {
        $at = is_array($rl) ? (int) ($rl['at'] ?? 0) : 0;
        if (!isset($areas[$cat]) || $at <= 0) {
            continue;
        }
        if (in_array($cat, ['pull', 'data'], true)) {
            if ($now - $at < WATCH_FLOW_LEARN) {
                $add($cat, 'note', 'relearn_flow');
            }
        } elseif ($now - $at < WATCH_RELEARN_SHOW) {
            $add($cat, 'note', 'relearn');
        }
    }
    if ($look === null) {
        $add('hold', 'none', 'hold_never');
    } elseif (empty($look['started'])) {
        $add('hold', 'none', 'array_stopped');
    } else {
        if (($look['times']['fits'] ?? null) === false) {
            $add('hold', 'danger', 'hold_short');
        }
        // only what is still waiting for you colours: what your exceptions call known (6f: a User Script that may run, a VM
        // without the guest agent you know) stays in the list but doesn't colour (Benj on the test stand, 1.96)
        if (watchmanHoldWaiting($look, (array) ($s['rules'] ?? [])) > 0) {
            $add('hold', 'warn', 'hold_holds');
        }
        if ($now - (int) ($look['time'] ?? 0) > WATCH_CAT_HOLD_OLD) {
            $add('hold', 'note', 'hold_old');
        }
        if (!empty($look['cut'])) {
            $add('hold', 'note', 'hold_cut');
        }
    }
    $w = $s['wakes'] ?? null;
    if (!is_array($w)) {
        $add('wakes', 'none', 'wakes_never');
    } elseif (($w['on'] ?? true) === false) {
        $add('wakes', 'none', 'wakes_off');
    } elseif (is_array($w['hdds'] ?? null) && !$w['hdds'] && !($w['wakes'] ?? [])) {
        $add('wakes', 'none', 'no_hdd');
    } elseif (($n = (int) ($s['settings']['wakes']['warn_at']['value'] ?? 0)) > 0 && watchmanWakesMost($w, (array) ($s['rules'] ?? []), $now) >= $n) {
        $add('wakes', 'warn', 'wakes_many');        // his setting (6f, off by default): a disk woken this often in a day
    }
    // known vulnerabilities: off (until switched on) grey; on but the lists never fetched, or older than WATCH_VULN_OLD: «!»
    $v = is_array($s['vuln'] ?? null) ? $s['vuln'] : null;
    if ($v === null || empty($v['on'])) {
        // switched off: grey — also with a line still open from before (it says so; never red while he doesn't look: gap 5)
        $areas['vuln']['levels'] = [];
        $areas['vuln']['why'] = [];
        $add('vuln', 'none', 'vuln_off');
    } elseif (($v['checked_at'] ?? null) === null) {
        $add('vuln', 'note', 'vuln_never');
    } elseif (empty($v['fresh'])) {
        $add('vuln', 'note', 'vuln_old');
    }
    // the hardware (watchman-hw.php): grey before his first look at it; a look over an hour old (rounds failing): yellow;
    // a server without ECC counters says so with «!» (concept 2.2) — never «0 memory errors»
    $hw = is_array($s['hw'] ?? null) ? $s['hw'] : null;
    if ($hw === null || !is_int($hw['time'] ?? null)) {
        $add('hw', 'none', 'hw_never');
    } else {
        if ($now - $hw['time'] > WATCH_CAT_HW_OLD) {
            $add('hw', 'warn', 'hw_silent');
        }
        if (array_key_exists('ecc', $hw) && $hw['ecc'] === null) {
            $add('hw', 'note', 'hw_no_ecc');
        }
        // the disks' wear (Unraid's SMART reading): an NVMe's critical warning standing now red, 90 % of its life used yellow,
        // a disk Unraid has no reading of «!» (what grows comes as entries)
        foreach (is_array($hw['wear'] ?? null) ? $hw['wear'] : [] as $d) {
            if (!is_array($d)) {
                continue;
            }
            if ((int) ($d['cw'] ?? 0) !== 0) {
                $add('hw', 'danger', 'hw_nvme_warning');
            }
            if ((int) ($d['used'] ?? 0) >= (int) ($hw['worn'] ?? 90)) {        // his setting (hw.worn)
                $add('hw', 'warn', 'hw_worn');
            }
            if (($d['read'] ?? null) === null) {
                $add('hw', 'note', 'hw_unread');
            }
        }
    }
    $res = $out('');
    return ['areas' => $res, 'book' => watchmanCatBook($res), 'schedule' => !empty($r['failed']) || !empty($r['broken']) ? 'danger' : 'ok'];
}

/**
 * Your exceptions for «What holds the array?» and «Who woke my disks?» (1.96 step 6f; rules on the side kinds `hold` and
 * `wakes`): vm, script; cause (<kind>:<name>), disk
 *
 * @return array<string, array<string, true>>
 */
function watchmanHoldKnown(array $rules): array
{
    $out = [];
    foreach ($rules as $r) {
        if (!is_array($r) || !in_array($r['cat'] ?? null, ['hold', 'wakes'], true)) {
            continue;
        }
        foreach (['vm', 'script', 'cause', 'disk'] as $k) {
            if (is_string($r['on'][$k] ?? null)) {
                $out[$k][$r['on'][$k]] = true;
            }
        }
    }
    return $out;
}

/**
 * What would hold the array up and is still waiting for you (the light of «What holds the array?»): the look's count —
 * things holding a file there, VMs without a connected guest agent — less those your exceptions name (a User Script that
 * may run, a VM you know). Things beyond the look's list (`more`) are never known: they count.
 */
function watchmanHoldWaiting(array $look, array $rules): int
{
    $known = watchmanHoldKnown($rules);
    $mine = count(array_filter((array) ($look['holds'] ?? []), fn ($h) => is_array($h) && ($h['kind'] ?? null) === 'userscript'
        && isset($known['script'][(string) ($h['name'] ?? '')])));
    $mine += count(array_filter((array) ($look['vms'] ?? []), fn ($v) => is_array($v) && ($v['agent'] ?? null) !== 'connected'
        && isset($known['vm'][(string) ($v['name'] ?? '')])));
    return max(0, (int) ($look['count'] ?? 0) - $mine);
}

/** A wake's cause as an exception names it: «<kind>:<its name, desk or program>» */
function watchmanWakeCauseKey(array $c): string
{
    $name = (string) ($c['name'] ?? '') ?: (string) ($c['desk'] ?? '') ?: (string) ($c['prog'] ?? '');
    return (string) ($c['kind'] ?? '') . ':' . $name;
}

/**
 * The most wakes of one disk in the last day — leaving out a disk you don't count and a wake all of whose causes you
 * expect (a wake with no cause named always counts)
 */
function watchmanWakesMost(array $w, array $rules, int $now): int
{
    $known = watchmanHoldKnown($rules);
    $per = [];
    foreach ((array) ($w['wakes'] ?? []) as $x) {
        if (!is_array($x) || (int) ($x['time'] ?? 0) < $now - 86400) {
            continue;
        }
        $who = (array) ($x['who'] ?? []);
        if ($who && !array_filter($who, fn ($c) => is_array($c) && !isset($known['cause'][watchmanWakeCauseKey($c)]))) {
            continue;
        }
        foreach ((array) ($x['disks'] ?? []) as $d) {
            if (is_string($d) && !isset($known['disk'][$d])) {
                $per[$d] = ($per[$d] ?? 0) + 1;
            }
        }
    }
    return $per ? max($per) : 0;
}

/** The watch book's light: the worst of the areas that look at something (grey only when all are grey) */
function watchmanCatBook(array $areas): string
{
    $seen = array_values(array_diff(array_column($areas, 'level'), ['none']));
    return $seen ? watchmanCatWorst($seen) : 'none';
}
