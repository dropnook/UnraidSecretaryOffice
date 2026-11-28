<?php
declare(strict_types=1);

/*
 * «Re-learn this area…» and «Re-learn everything…» (1.96 step 17, the concept «Der Nachtwächter in 1.96» 12.2). Functions
 * only, in a file of its own beside his desk (watchman.php require_once's it).
 *
 * A re-learn is a take-over in small (watchmanTakeOver(), for one area): what is there now is normal from now on; what he
 * learned as usual for the area begins anew (the data flow: its 7 days again). Never keys simply deleted — an empty list of
 * known addresses would make the next login «new»: what needs the current look (the addresses in the syslog's history,
 * the containers as they are …) is learned by the round the action starts (state.json relearn_pending, watchmanRelearnApply());
 * what is learned without a look (the data flow's series, the router's files' positions — their history is read again in
 * learning mode —, the hardware's history, the snapshot series) is cleared by the action itself.
 *
 *   closed   the area's open entries: noted «re-learned» (by relearn) — nothing disappears
 *   gone     your lasting answers of the area; with the checkboxes its exceptions and its settings
 *   stays    switched off or on, the posture notes, serious incidents, the book's history
 *   aside    everything taken away, first into data/watchman/aside/<time>-<area|all>/ (never deleted); «Bring back…» reads
 *            it for WATCH_RELEARN_BACK and puts it back (the folder then renamed «…-back», still never deleted)
 *
 * The shared parts are cut by sub-part (12.2): the server itself — ports and programs, ld.so.preload; ways in — doors and
 * API keys; flash and accounts — the flash and the accounts in RAM. The router — admins, VPN users, its failed logins; the
 * network — devices, the server's own name and addresses, what was blocked, inbound detections, the router's MAC and DNS
 * (both re-read the router files from the start in learning mode). The data flow — clients and containers (pull), shares and
 * disks (data). Logins — the addresses, failed logins, SMB users and machines; the partner door its own lines and logins.
 * The night shift: the mirror (RAM and flash) and the copy of the rules written at once (watchmanMirrorKeep()).
 */

const WATCH_RELEARN_BACK = 7 * 86400;       // «Bring back…» this long
const WATCH_RELEARN_SHOW = 86400;           // the tile says «re-learning since …» this long (the data flow: its learning time)
const WATCH_RELEARN_DIR  = 'aside';
// the areas whose re-learn needs the round's look (the action marks them, the round learns them)
const WATCH_RELEARN_LOOK = ['login', 'container', 'plugin', 'flash', 'share', 'sched', 'host', 'door', 'partner', 'lan', 'hw'];

/** The areas a re-learn takes (all 19; «everything»: all of them) */
function watchmanRelearnCats(): array
{
    return array_keys(WATCH_CATS);
}

/** A baseline's value at a dotted path (null: not there) */
function watchmanRelearnGet(array $a, string $path): mixed
{
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return null;
        }
        $a = $a[$k];
    }
    return $a;
}

/** … set (null: the key removed) */
function watchmanRelearnPut(array &$a, string $path, mixed $v): void
{
    $keys = explode('.', $path);
    $last = array_pop($keys);
    $ref = &$a;
    foreach ($keys as $k) {
        if (!is_array($ref[$k] ?? null)) {
            if ($v === null) {
                return;
            }
            $ref[$k] = [];
        }
        $ref = &$ref[$k];
    }
    if ($v === null) {
        unset($ref[$last]);
    } else {
        $ref[$last] = $v;
    }
}

/** What of the baseline an area's re-learn takes (set aside, then learned anew) */
function watchmanRelearnParts(string $cat): array
{
    return match ($cat) {
        'login'     => ['ips', 'fail_ips', 'flow.smb_users', 'flow.smb_clients'],
        'container' => ['containers'],
        'plugin'    => ['plugins'],
        'flash'     => ['flash', 'host.users'],
        'share'     => ['shares'],
        'sched'     => ['sched'],
        'snap'      => ['snaps.series'],
        'host'      => ['host.listen', 'host.procs'],
        'door'      => ['host.doors', 'host.api'],
        'partner'   => ['partner'],
        'router'    => ['net.admins', 'net.vpn', 'net.fail_ips'],
        'lan'       => ['net.devices', 'net.self', 'net.blocked', 'net.ips_in', 'net.ips_in_fam', 'lan'],
        default     => [],
    };
}

/** The data flow's series of an area (flow.json) and its «I know» marks (baseline flow.ack, by the entry's key) */
function watchmanRelearnFlow(string $cat): array
{
    return match ($cat) {
        'pull'  => [['clients', 'containers'], ['flow_client:', 'flow_container:']],
        'data'  => [['shares', 'gone', 'files', 'disk_w'], ['flow_written:', 'flow_gone:']],
        default => [[], []],
    };
}

/**
 * «Re-learn …» (his setup page, the action `relearn`): $cats one area or all of them; $rules, $settings: also its
 * exceptions, also back to the defaults. Everything taken is set aside first; the round it starts learns what needs a look.
 */
function watchmanRelearn(array $cats, bool $rules, bool $settings, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    $all = watchmanRelearnCats();
    if (!$cats || array_diff($cats, $all) || count($cats) !== count(array_unique($cats))) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    $name = date('Ymd-His', $now) . '-' . (count($cats) === count($all) ? 'all' : $cats[0]);
    $res = watchmanRulesChange($dir, function (array $raw, array $d) use ($cats, $rules, $settings, $dir, $now, $name): array {
        $aside = watchmanRelearnAsideDir($dir, $name);
        if ($aside === null) {
            throw new Problem('watch_relearn_aside');
        }
        $b = $d['baseline'];
        $st = $d['state'];
        $book = $d['book'];
        $keep = ['v' => 1, 'at' => $now, 'cats' => $cats, 'baseline' => [], 'flow' => [], 'net_pos' => null, 'closed' => [], 'known' => [], 'rules' => [],
                 'set' => [], 'state' => []];
        // the baseline's parts (those the round learns are kept as they are until it does)
        foreach ($cats as $cat) {
            foreach (watchmanRelearnParts($cat) as $path) {
                $keep['baseline'][$path] = watchmanRelearnGet($b, $path);
            }
            if ($cat === 'host') {
                $keep['baseline']['flash.boot.files.preload'] = watchmanRelearnGet($b, 'flash.boot.files.preload');
            }
        }
        // the data flow: its series and «I know» marks — learned anew from now (each series its learning time again)
        $flow = readJson("$dir/flow.json");
        $flowChanged = false;
        foreach ($cats as $cat) {
            [$parts, $prefixes] = watchmanRelearnFlow($cat);
            foreach ($parts as $part) {
                if (is_array($flow[$part] ?? null)) {
                    $keep['flow'][$part] = $flow[$part];
                    $flow[$part] = [];
                    $flowChanged = true;
                }
            }
            foreach ((array) ($b['flow']['ack'] ?? []) as $k => $v) {
                foreach ($prefixes as $p) {
                    if (str_starts_with((string) $k, $p)) {
                        $keep['baseline']["flow.ack.$k"] = $v;
                        unset($b['flow']['ack'][$k]);
                    }
                }
            }
        }
        // what needs no look: cleared here
        foreach ($cats as $cat) {
            if (in_array($cat, ['snap', 'router'], true) || $cat === 'lan') {
                foreach (watchmanRelearnParts($cat) as $path) {
                    if (str_starts_with($path, 'net.') || $path === 'snaps.series') {
                        watchmanRelearnPut($b, $path, is_array(watchmanRelearnGet($b, $path)) ? [] : null);
                    }
                }
            }
        }
        // the router and the network: the router files read again from the start, in learning mode (their history normal)
        $net = readJson("$dir/net.json");
        if (array_intersect($cats, ['router', 'lan']) && is_array($net['pos'] ?? null)) {
            $keep['net_pos'] = $net['pos'];
            $net['pos'] = [];
        }
        // the hardware: its history and this boot's counters — the round's first look normal again (a copy of hw.json aside)
        if (in_array('hw', $cats, true)) {
            $keep['state']['hot'] = $st['hot'] ?? null;
            unset($st['hot']);
            if (is_file("$dir/hw.json") && !is_link("$dir/hw.json")) {
                @copy("$dir/hw.json", "$aside/hw.json");
            }
        }
        // known vulnerabilities: what he found forgotten; the lists fetched again at the next tick
        if (in_array('vuln', $cats, true)) {
            $keep['state']['vuln_seen'] = $st['vuln_seen'] ?? null;
            unset($st['vuln_seen']);
            $status = readJson(watchmanVulnDir($dir) . '/status.json');
            if (is_array($status) && is_array($status['kev'] ?? null)) {
                $status['kev']['try'] = 0;
                writeAtomic(watchmanVulnDir($dir) . '/status.json', jsonEncode($status), 0600, 0, 0);
            }
        }
        // the logins: failed logins followed so far start anew
        if (in_array('login', $cats, true)) {
            $keep['state']['fails'] = $st['fails'] ?? null;
            $st['fails'] = [];
        }
        // the area's open entries: noted «re-learned» — nothing disappears
        foreach ($book as $i => $e) {
            if (watchmanOpen($e) && in_array(watchmanCatOf((string) $e['kind']), $cats, true)) {
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'relearn';
                $keep['closed'][] = (string) $e['id'];
            }
        }
        // your lasting answers of the area (after it, all that is there now is normal anyway); with the checkboxes its
        // exceptions and its settings
        $known = is_array($raw['known'] ?? null) && array_is_list($raw['known']) ? $raw['known'] : [];
        $raw['known'] = array_values(array_filter($known, function ($k) use ($cats, &$keep) {
            if (is_array($k) && in_array($k['cat'] ?? null, $cats, true)) {
                $keep['known'][] = $k;
                return false;
            }
            return true;
        }));
        if (!$raw['known'] && !$known) {
            unset($raw['known']);
        }
        if ($rules && is_array($raw['rules'] ?? null) && array_is_list($raw['rules'])) {
            $raw['rules'] = array_values(array_filter($raw['rules'], function ($r) use ($cats, &$keep) {
                if (is_array($r) && in_array($r['cat'] ?? null, $cats, true)) {
                    $keep['rules'][] = $r;
                    return false;
                }
                return true;
            }));
        }
        if ($settings && is_array($raw['set'] ?? null)) {
            foreach ($cats as $cat) {
                if (isset($raw['set'][$cat])) {
                    $keep['set'][$cat] = $raw['set'][$cat];
                    unset($raw['set'][$cat]);
                }
            }
            if (!$raw['set']) {
                unset($raw['set']);
            }
        }
        // one line in his book per area (with the counts «Took over the watch» has: an older page reads it as that line); the
        // round learns what needs its look; the tile says it
        foreach ($cats as $cat) {
            $book[] = ['noted' => $now, 'by' => 'relearn'] + watchmanEntry('watch', "watch:relearn:$cat:$now", $now, ['relearn' => $cat, 'aside' => $name] + watchmanCounts($b));
            $st['relearn'][$cat] = ['at' => $now, 'aside' => $name];
            if (in_array($cat, WATCH_RELEARN_LOOK, true)) {
                $st['relearn_pending'][$cat] = $now;
            }
        }
        writeAtomic("$aside/relearn.json", jsonEncode($keep), 0600, 0, 0);
        if ($flowChanged) {
            writeAtomic("$dir/flow.json", jsonEncode($flow));
        }
        if ($keep['net_pos'] !== null) {
            writeAtomic("$dir/net.json", jsonEncode($net));
        }
        $d2 = $d;
        $d2['baseline'] = $b;
        $d2['book'] = $book;
        $d2['state'] = $st;
        return [$raw, $d2, ['closed' => count($keep['closed']), 'aside' => $name]];
    });
    logLine(sprintf('Night watchman: re-learning %s — %d open entries noted, set aside in %s/%s', implode(', ', $cats), $res['closed'], WATCH_RELEARN_DIR, $res['aside']));
    if (!$page) {
        return ['ok' => true] + $res;
    }
    watchmanMirrorKeep($dir);                 // the night shift: the rules' copy now (the mirror again after the round)
    watchmanStart();                          // the round that learns what needs its look
    return ['ok' => true] + $res + ['state' => watchmanPageState()];
}

/** The folder a re-learn sets aside into: data/watchman/aside/<name>, a real folder (never through a link), 0700 */
function watchmanRelearnAsideDir(string $dir, string $name): ?string
{
    $base = "$dir/" . WATCH_RELEARN_DIR;
    clearstatcache();
    if (is_link($base) || (file_exists($base) && !is_dir($base))) {
        return null;
    }
    if (!is_dir($base) && !@mkdir($base, 0700)) {
        return null;
    }
    $path = "$base/$name";
    for ($i = 2; file_exists($path) || is_link($path); $i++) {
        $path = "$base/$name-$i";
    }
    return @mkdir($path, 0700) ? $path : null;
}

/**
 * The round's part (in his lock, the non-fresh path, before anything is compared): the areas a re-learn marked are learned
 * from this round's look — as at his take-over, for these parts only. $events: the syslog from its start (login, partner).
 * Returns what was pending (the hardware's part is the round's).
 */
function watchmanRelearnApply(array &$b, array &$st, array $seen, array $events, ?array $look, int $now): array
{
    $pending = is_array($st['relearn_pending'] ?? null) ? $st['relearn_pending'] : [];
    if (!$pending) {
        return [];
    }
    $none = [];
    if (isset($pending['partner'])) {
        $b['partner'] = watchmanPartnerBase(null);
        foreach ((array) ($seen['partner']['lines'] ?? []) as $id => $l) {
            $b['partner']['lines'][$id] = watchmanPartnerLineKeep($l);
        }
        $b['partner']['pairs'] = watchmanPartnerDoors($b['partner'], $seen['partner'] ?? null);
        $st['partner_refused'] = array_map(fn ($r) => (int) max([0, ...$r['times']]), (array) ($seen['partner']['refused'] ?? []));
        $tmp = ['ips' => [], 'fail_ips' => [], 'partner' => $b['partner']];
        $noFails = [];
        watchmanLogins($tmp, $none, $noFails, $events, true, $b['partner']['pairs']);
        $b['partner']['logins'] = (array) ($tmp['partner']['logins'] ?? []);
    }
    if (isset($pending['login'])) {
        // the addresses of the syslog's history (the partner doors' logins stay theirs), the burst of failures so far
        $tmp = ['ips' => [], 'fail_ips' => [], 'partner' => is_array($b['partner'] ?? null) ? $b['partner'] : watchmanPartnerBase(null)];
        $fails = [];
        watchmanLogins($tmp, $none, $fails, $events, true, watchmanPartnerDoors($tmp['partner'], $seen['partner'] ?? null));
        $b['ips'] = $tmp['ips'];
        $b['fail_ips'] = $tmp['fail_ips'];
        $st['fails'] = [];
        // the partner doors' addresses (their own part: an address with its key you said is fine) known again (12.2)
        foreach ((array) ($b['partner']['logins'] ?? []) as $pair => $t) {
            $ip = explode('|', (string) $pair)[0];
            if ($ip !== '' && !isset($b['ips'][$ip])) {
                $b['ips'][$ip] = ['first' => (int) $t, 'last' => (int) $t, 'users' => [], 'services' => ['ssh:publickey']];
            }
        }
        // SMB: its users and machines as they are now (the machines' hours learned anew)
        if (is_array($look['smb'] ?? null) && !empty($look['smb']['on']) && is_array($b['flow'] ?? null)) {
            $b['flow']['smb_users'] = [];
            $b['flow']['smb_clients'] = [];
            foreach ((array) $look['smb']['sessions'] as $s) {
                $b['flow']['smb_users'][(string) $s['user']] = $now;
                $machine = (string) ($s['machine'] ?? '');
                $b['flow']['smb_clients'][(string) $s['ip']] = ['first' => $now, 'last' => $now, 'hours' => [watchmanHourOfWeek((int) ($s['start'] ?? $now))],
                                                                'name' => $machine !== (string) $s['ip'] ? $machine : ''];
            }
        }
    }
    if (isset($pending['container']) && $seen['containers'] !== null) {
        $b['containers'] = array_map(fn ($c) => ['tokens' => $c['tokens'], 'seen' => $now], $seen['containers']);
    }
    if (isset($pending['plugin'])) {
        $b['plugins'] = array_map(fn ($p) => $p + ['seen' => $now], $seen['plugins']);
    }
    if (isset($pending['share']) && $seen['shares'] !== null) {
        $b['shares'] = array_map(fn ($s) => $s + ['seen' => $now], $seen['shares']);
    }
    if (isset($pending['sched'])) {
        $b['sched'] = null;
        watchmanSchedCompare($b['sched'], $seen['sched'] ?? null, $seen['plugins'], $none, $now);
    }
    if (isset($pending['flash'])) {
        $preload = $b['flash']['boot']['files']['preload'] ?? null;        // ld.so.preload is the server itself's
        $flash = $seen['flash'];
        unset($flash['boot']);
        $boot = null;
        if (isset($seen['flash']['boot'])) {
            watchmanBootCompare($boot, $seen['flash']['boot'], $none, $now);
        }
        $b['flash'] = $flash + ($boot !== null ? ['boot' => $boot] : []);
        if (is_array($b['flash']['boot']['files'] ?? null)) {
            if ($preload === null) {
                unset($b['flash']['boot']['files']['preload']);
            } else {
                $b['flash']['boot']['files']['preload'] = $preload;
            }
        }
    }
    if (isset($pending['flash']) || isset($pending['host']) || isset($pending['door'])) {
        $host = null;
        watchmanHostCompare($host, $seen['host'] ?? null, null, (array) ($seen['flash']['users'] ?? []), $none, $now);
        if (is_array($host)) {
            $parts = array_merge(isset($pending['flash']) ? ['users'] : [], isset($pending['host']) ? ['listen', 'procs'] : [],
                isset($pending['door']) ? ['doors', 'api'] : []);
            foreach ($parts as $k) {
                if (array_key_exists($k, $host)) {
                    $b['host'][$k] = $host[$k];
                }
            }
        }
    }
    if (isset($pending['host']) && is_array($b['flash']['boot']['files'] ?? null)) {
        $now1 = $seen['flash']['boot']['files']['preload'] ?? null;
        if (is_array($now1)) {
            $b['flash']['boot']['files']['preload'] = watchmanBootKeep($now1);
        } else {
            unset($b['flash']['boot']['files']['preload']);
        }
        unset($b['flash']['boot']['wait']['preload']);
    }
    if (isset($pending['lan'])) {
        $b['lan'] = null;
        watchnetGatewayCompare($b['lan'], $seen['lan'] ?? null, $none, $now);
    }
    // the hardware: the round's own part (watchmanHwRound() as at his take-over: what it counts now is normal)
    unset($st['relearn_pending']);
    return $pending;
}

/**
 * «Bring back…» (his setup page, the action `relearn_back`, within WATCH_RELEARN_BACK): what the re-learn set aside is put
 * back — the baseline's parts, the data flow's series, the hardware's history, your answers,
 * exceptions and settings; the entries it closed are open again. The folder stays, renamed «…-back».
 */
function watchmanRelearnBack(string $name, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    if (!preg_match('/^\d{8}-\d{6}-[a-z]+(?:-\d+)?$/D', $name)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    $path = "$dir/" . WATCH_RELEARN_DIR . "/$name";
    $res = watchmanRulesChange($dir, function (array $raw, array $d) use ($path, $dir, $now): array {
        $keep = is_dir($path) && !is_link($path) ? readJson("$path/relearn.json") : null;
        if (!is_array($keep) || ($keep['v'] ?? null) !== 1 || !is_int($keep['at'] ?? null) || !is_array($keep['cats'] ?? null)) {
            throw new Problem('watch_relearn_gone');
        }
        if ($now - $keep['at'] > WATCH_RELEARN_BACK) {
            throw new Problem('watch_relearn_old');
        }
        $b = $d['baseline'];
        $st = $d['state'];
        $book = $d['book'];
        foreach ((array) $keep['baseline'] as $p => $v) {
            watchmanRelearnPut($b, (string) $p, $v);
        }
        if ($keep['flow']) {
            $flow = readJson("$dir/flow.json") ?? [];
            foreach ((array) $keep['flow'] as $part => $series) {
                $flow[$part] = $series;
            }
            writeAtomic("$dir/flow.json", jsonEncode($flow));
        }
        // the router files' positions stay where the rounds read since (read again from the old place, what came since would be
        // told a second time); they lie aside only as a record
        foreach ((array) $keep['state'] as $k => $v) {
            if ($v === null) {
                unset($st[$k]);
            } else {
                $st[$k] = $v;
            }
        }
        if (is_file("$path/hw.json") && !is_link("$path/hw.json")) {
            @copy("$path/hw.json", "$dir/hw.json");
        }
        $closed = array_flip((array) $keep['closed']);
        $reopened = 0;
        foreach ($book as $i => $e) {
            if (isset($closed[(string) $e['id']]) && ($e['by'] ?? null) === 'relearn') {
                $book[$i]['noted'] = null;
                $book[$i]['by'] = null;
                $reopened++;
            }
        }
        $ids = fn (array $list) => array_flip(array_map(fn ($x) => is_array($x) ? (string) ($x['id'] ?? '') : '', $list));
        $have = $ids(is_array($raw['known'] ?? null) ? $raw['known'] : []);
        foreach ((array) $keep['known'] as $k) {
            if (!isset($have[(string) ($k['id'] ?? '')])) {
                $raw['known'][] = $k;
            }
        }
        $have = $ids(is_array($raw['rules'] ?? null) ? $raw['rules'] : []);
        foreach ((array) $keep['rules'] as $r) {
            if (!isset($have[(string) ($r['id'] ?? '')])) {
                $raw['rules'][] = $r;
            }
        }
        foreach ((array) $keep['set'] as $cat => $s) {
            $raw['set'][$cat] = $s;
        }
        foreach ((array) $keep['cats'] as $cat) {
            unset($st['relearn'][$cat], $st['relearn_pending'][$cat]);
            $book[] = ['noted' => $now, 'by' => 'relearn'] + watchmanEntry('watch', "watch:relearn_back:$cat:$now", $now, ['relearn_back' => $cat] + watchmanCounts($b));
        }
        if (empty($st['relearn'])) {
            unset($st['relearn']);
        }
        if (empty($st['relearn_pending'])) {
            unset($st['relearn_pending']);
        }
        @rename($path, "$path-back");
        $d2 = $d;
        $d2['baseline'] = $b;
        $d2['book'] = $book;
        $d2['state'] = $st;
        return [$raw, $d2, ['reopened' => $reopened, 'cats' => $keep['cats']]];
    });
    logLine(sprintf('Night watchman: re-learning of %s brought back (%s), %d entries open again', implode(', ', $res['cats']), $name, $res['reopened']));
    if (!$page) {
        return ['ok' => true] + $res;
    }
    watchmanMirrorKeep($dir);
    return ['ok' => true] + $res + ['state' => watchmanPageState()];
}

/** For his page: per area re-learned when, until when it can be brought back, the folder's name */
function watchmanRelearnPage(array $st, int $now): array
{
    $out = [];
    foreach (is_array($st['relearn'] ?? null) ? $st['relearn'] : [] as $cat => $r) {
        if (is_array($r) && is_int($r['at'] ?? null) && is_string($r['aside'] ?? null) && $now - $r['at'] <= WATCH_RELEARN_BACK) {
            $out[(string) $cat] = ['at' => $r['at'], 'aside' => $r['aside'], 'until' => $r['at'] + WATCH_RELEARN_BACK,
                                   'pending' => isset($st['relearn_pending'][$cat])];
        }
    }
    return $out;
}
