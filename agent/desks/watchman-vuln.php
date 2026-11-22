<?php
declare(strict_types=1);

/*
 * The Night Watchman's «Known vulnerabilities» (1.96, the concept «Der Nachtwächter in 1.96», 11.3 A; area `vuln`).
 * Off until the user switches it on once (state.json `vuln_on`; the coordinator's decision, concept 12): «Nothing leaves
 * the server unless you set it up». Off is really off — nothing is fetched.
 *
 * On, once a day (WATCH_VULN_EVERY; a failed try again after WATCH_VULN_RETRY) a job of its own (`job watchman-feeds`,
 * started by his tick like his round, niced, never as the agent's child that must outlive it) fetches public lists —
 * the same for every office, nothing of this server goes along but the request itself (a fixed User-Agent without a
 * version, no name, no id), https only, no redirects, each with a size cap (WATCH_VULN_MAX):
 *   cisa.gov          CISA's «Known Exploited Vulnerabilities» (KEV), «only when newer than» (If-Modified-Since); kept:
 *                     only the entries whose vendor/product the name table knows (WATCH_VULN_PRODUCTS), whether or not
 *                     that product runs here — so the fetches look alike at every office
 *   raw.githubusercontent.com  the CVE record (CVE List V5) of each of those entries: the vendor's own affected versions
 *   releases.unraid.net        Unraid's list of releases with their support status (not Unraid's own update check: that
 *                              one sends an id of the server)
 * Kept in data/watchman/feeds/ (kev.json, cve/<id>.json reduced to the affected data, unraid.json, status.json): at
 * most WATCH_VULN_CVE_MAX records, each file small; a list missing, broken, too big or older than WATCH_VULN_OLD is «not
 * checked», never «all fine».
 *
 * Each round (the day's; the night shift has no data folder and no network) compares them with what is here — the
 * inventory, all local: Unraid's version (/etc/unraid-version), the kernel's (uname), the PHP the office runs on
 * (Unraid's own), Slackware's packages (/var/log/packages: openssh, openssl, samba, sudo, bash, polkit, nginx …).
 * Three verdicts, never more (watchmanVulnVerdict()):
 *   affected   (`vuln_known`, told) only when the versions compare cleanly and this one lies in an affected range —
 *              «in your branch 6.12 fixed from 6.12.48, here runs 6.12.24»
 *   maybe      (`vuln_maybe`, quiet; told only with the setting set.vuln.maybe) the product is here, the record can't be
 *              compared (no record, another version scheme, n/a)
 *   not        no entry; counted («31 checked, not affected»)
 * One entry a vulnerability, product and place (key vuln_known:<cve>:<product>:<where>), kept up to date wherever it
 * stands. Unraid's own version without security updates (eol, superseded): the advice `unraid_eol`.
 */

const WATCH_VULN_EVERY   = 86400;          // the lists once a day …
const WATCH_VULN_RETRY   = 6 * 3600;       // … a failed try again after this long
const WATCH_VULN_OLD     = 3 * 86400;      // a list older than this: «not checked»
const WATCH_VULN_RUN_MAX = 900;            // a fetch that takes longer is stopped
const WATCH_VULN_CVE_MAX = 300;            // CVE records kept at most
const WATCH_VULN_CVE_RUN = 60;             // fetched in one run at most (the rest the next day)
const WATCH_VULN_CVE_AGE = 7 * 86400;      // a kept record fetched anew after this long
const WATCH_VULN_UA      = 'UnraidSecretaryOffice';      // the same for every office: no version, no name
const WATCH_VULN_URLS    = [
    'kev'    => 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json',
    'cve'    => 'https://raw.githubusercontent.com/CVEProject/cvelistV5/main/cves/',
    'unraid' => 'https://releases.unraid.net/json',
];
const WATCH_VULN_MAX     = ['kev' => 8 << 20, 'cve' => 2 << 20, 'unraid' => 1 << 20, 'eol' => 1 << 20];     // bytes of an answer at most
const WATCH_VULN_TIMEOUT = 60;             // seconds a fetch may take
const WATCH_VULN_DESC    = 300;            // characters of a description kept

/**
 * The name table: how the lists name a product (lower case «vendor|product»: KEV's vendorProject/product, a CVE record's
 * affected vendor/product), and where its version is here. Hand-kept: the lists name things unevenly («PHP Group» and
 * «PHP», two spellings of Bash). `pkg`: Slackware's package name; `host`: unraid, kernel or php (the office's own).
 */
const WATCH_VULN_PRODUCTS = [
    'unraid'   => ['label' => 'Unraid', 'kev' => ['unraid|unraid'], 'cna' => ['unraid|unraid', 'lime technology|unraid'], 'host' => 'unraid'],
    'kernel'   => ['label' => 'Linux kernel', 'kev' => ['linux|kernel'], 'cna' => ['linux|linux', 'linux|linux kernel'], 'host' => 'kernel'],
    'php'      => ['label' => 'PHP', 'kev' => ['php group|php', 'php|php', 'php|fastcgi process manager (fpm)'], 'cna' => ['php group|php', 'php|php'], 'host' => 'php'],
    'openssh'  => ['label' => 'OpenSSH', 'kev' => ['openbsd|openssh'], 'cna' => ['openbsd|openssh', '|openssh'], 'pkg' => 'openssh'],
    'openssl'  => ['label' => 'OpenSSL', 'kev' => ['openssl|openssl'], 'cna' => ['openssl|openssl'], 'pkg' => 'openssl'],
    'samba'    => ['label' => 'Samba', 'kev' => ['samba|samba'], 'cna' => ['samba|samba'], 'pkg' => 'samba'],
    'sudo'     => ['label' => 'sudo', 'kev' => ['sudo|sudo', 'todd miller|sudo'], 'cna' => ['sudo|sudo', 'sudo project|sudo'], 'pkg' => 'sudo'],
    'bash'     => ['label' => 'Bash', 'kev' => ['gnu|gnu bash', 'gnu|bourne-again shell (bash)', 'gnu|bash'], 'cna' => ['gnu|bash'], 'pkg' => 'bash'],
    'polkit'   => ['label' => 'polkit', 'kev' => ['red hat|polkit'], 'cna' => ['polkit|polkit', 'freedesktop|polkit'], 'pkg' => 'polkit'],
    'inetutils' => ['label' => 'GNU Inetutils', 'kev' => ['gnu|inetutils'], 'cna' => ['gnu|inetutils'], 'pkg' => 'inetutils'],
    'nginx'    => ['label' => 'nginx', 'kev' => ['f5|nginx', 'nginx|nginx'], 'cna' => ['f5|nginx', 'f5|nginx open source', 'nginx|nginx'], 'pkg' => 'nginx'],
];
// the places an entry names: the server itself, or (step 16) a container
const WATCH_VULN_HOST = 'host';

/** The feeds' folder (data/watchman/feeds) */
function watchmanVulnDir(string $dir): string
{
    return "$dir/feeds";
}

// ===================================================================== versions

/**
 * A version as the comparison takes it: 1–5 numbers and an OpenSSH-style patch level («9.7p1») — [numbers, p] — or null
 * (anything else: «8.1.*» is a branch, not a version; «2.6.32-rc1», a commit, «n/a» never compare)
 *
 * @return array{0: list<int>, 1: int}|null
 */
function watchmanVulnParse(string $v): ?array
{
    if (!preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,4})(?:p(\d{1,4}))?$/D', $v, $m)) {
        return null;
    }
    return [array_map('intval', explode('.', $m[1])), isset($m[2]) ? (int) $m[2] : 0];
}

/** -1, 0, 1 — numbers padded with zeros, then the patch level */
function watchmanVulnCmp(array $a, array $b): int
{
    $n = max(count($a[0]), count($b[0]));
    for ($i = 0; $i < $n; $i++) {
        $c = ($a[0][$i] ?? 0) <=> ($b[0][$i] ?? 0);
        if ($c !== 0) {
            return $c;
        }
    }
    return $a[1] <=> $b[1];
}

/**
 * A version as a source writes it → the plain version (or null: not clean). $from: kernel («6.12.54-Unraid»), pkg
 * (Slackware's: plain), env (step 16: «1:11.8.9+maria~ubu2404», «17.12-1.pgdg12+1», «jdk-21.0.13.1+2»), plain
 */
function watchmanVulnClean(string $raw, string $from = 'plain'): ?string
{
    $v = trim($raw);
    if ($from === 'kernel') {
        $v = preg_replace('/-Unraid$/Di', '', $v) ?? $v;
    } elseif ($from === 'env') {
        $v = preg_replace('/^(?:\d{1,3}:|jdk-|v)/', '', $v) ?? $v;          // an epoch, Temurin's «jdk-», a «v»
        $v = preg_split('/[+~-]/', $v)[0] ?? '';                            // a package's revision, a distribution's mark
    }
    return watchmanVulnParse($v) !== null ? $v : null;
}

/**
 * One range of a CVE record's affected versions → what the comparison needs, or null when it can't be compared (another
 * version scheme — git, rpm …; a «changes» list; a version that isn't clean). «X.*» as an end: up to the end of branch X.
 *
 * @return array{status: string, start: ?array, end: ?array, incl: bool, branch: ?list<int>, exact: bool}|null
 */
function watchmanVulnRange(array $r): ?array
{
    $status = $r['status'] ?? null;
    $type = $r['versionType'] ?? 'semver';
    if (!in_array($status, ['affected', 'unaffected', 'unknown'], true) || !in_array($type, ['semver', 'custom', 'original_commit_for_fix'], true)
        || isset($r['changes']) || !is_string($r['version'] ?? null)) {
        return null;
    }
    $ver = $r['version'];
    $start = preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.\*$/D', $ver, $m) ? watchmanVulnParse($m[1]) : watchmanVulnParse($ver);
    if ($start === null) {
        return null;
    }
    $end = $r['lessThan'] ?? $r['lessThanOrEqual'] ?? null;
    $incl = isset($r['lessThanOrEqual']) && !isset($r['lessThan']);
    if ($end === null) {
        return ['status' => $status, 'start' => $start, 'end' => null, 'incl' => false, 'branch' => null, 'exact' => !str_ends_with($ver, '.*')];
    }
    if (!is_string($end)) {
        return null;
    }
    if ($end === '*') {
        return ['status' => $status, 'start' => $start, 'end' => null, 'incl' => true, 'branch' => null, 'exact' => false];
    }
    if (preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.\*$/D', $end, $m)) {
        return ['status' => $status, 'start' => $start, 'end' => null, 'incl' => true, 'branch' => array_map('intval', explode('.', $m[1])), 'exact' => false];
    }
    $e = watchmanVulnParse($end);
    return $e === null ? null : ['status' => $status, 'start' => $start, 'end' => $e, 'incl' => $incl, 'branch' => null, 'exact' => false];
}

/** Does $have lie in range $r? */
function watchmanVulnIn(array $have, array $r): bool
{
    if ($r['exact']) {
        return watchmanVulnCmp($have, $r['start']) === 0;
    }
    if (watchmanVulnCmp($have, $r['start']) < 0) {
        return false;
    }
    if ($r['branch'] !== null) {
        return array_slice($have[0], 0, count($r['branch'])) === $r['branch'];
    }
    if ($r['end'] === null) {
        return true;
    }
    $c = watchmanVulnCmp($have, $r['end']);
    return $r['incl'] ? $c <= 0 : $c < 0;
}

/**
 * One affected item of a CVE record (its vendor/product the name table's) against the version here → [verdict, fixed]:
 * 'yes' | 'no' | 'maybe', and the fixed version of this branch when the record says one. The strict rule:
 *   - platforms named and none of them Linux (or Unix, all): not for this server → no
 *   - a clean range holding the version: unaffected → no, affected → yes (both → maybe)
 *   - none holds it: the record's defaultStatus — unaffected → no; affected → yes, unless the version is newer than every
 *     range the record names and none of them is open-ended (a newer branch the record didn't know: no); else maybe
 *   - a range that can't be compared (git, rpm, «n/a») anywhere in the item: never yes and never no from the default
 *     alone — maybe
 *
 * @return array{0: string, 1: ?string}
 */
function watchmanVulnItem(array $item, string $have): array
{
    $h = watchmanVulnParse($have);
    if ($h === null) {
        return ['maybe', null];
    }
    $platforms = array_map(fn ($p) => strtolower((string) $p), (array) ($item['platforms'] ?? []));
    if ($platforms && !array_filter($platforms, fn ($p) => preg_match('/linux|unix|all/', $p))) {
        return ['no', null];
    }
    $ranges = [];
    $bad = false;
    foreach ((array) ($item['versions'] ?? []) as $r) {
        $x = is_array($r) ? watchmanVulnRange($r) : null;
        if ($x === null) {
            $bad = true;
        } else {
            $ranges[] = $x;
        }
    }
    // the fixed version for the words: an unaffected range of this branch, else the end of the affected range holding it
    $fixed = null;
    foreach ($ranges as $x) {
        if ($x['status'] === 'unaffected' && $x['branch'] !== null && array_slice($h[0], 0, count($x['branch'])) === $x['branch']) {
            $fixed = implode('.', $x['start'][0]) . ($x['start'][1] ? 'p' . $x['start'][1] : '');
        }
    }
    $holds = array_values(array_filter($ranges, fn ($x) => watchmanVulnIn($h, $x)));
    $st = array_unique(array_column($holds, 'status'));
    if (in_array('unaffected', $st, true) && !in_array('affected', $st, true)) {
        return ['no', null];
    }
    if (in_array('affected', $st, true)) {
        if (in_array('unaffected', $st, true) || $bad) {
            return ['maybe', $fixed];
        }
        foreach ($holds as $x) {
            if ($x['status'] === 'affected' && $x['end'] !== null && $fixed === null) {
                $fixed = implode('.', $x['end'][0]) . ($x['end'][1] ? 'p' . $x['end'][1] : '');
            }
        }
        return ['yes', $fixed];
    }
    $default = $item['defaultStatus'] ?? 'unknown';
    if ($default === 'unaffected') {
        return [$bad ? 'maybe' : 'no', null];
    }
    if ($default !== 'affected' || $bad || !$ranges) {
        return ['maybe', $fixed];
    }
    // its own branch (major.minor) has an affected range, and this version is at or past that range's end: fixed in its
    // branch (PHP's «8.2.* < 8.2.20» for 8.2.25)
    foreach ($ranges as $x) {
        if ($x['status'] === 'affected' && $x['end'] !== null && array_slice($x['start'][0], 0, 2) === array_slice($h[0], 0, 2)
            && watchmanVulnCmp($h, $x['end']) >= ($x['incl'] ? 1 : 0)) {
            return ['no', null];
        }
    }
    // newer than everything the record names, nothing open-ended: a branch it didn't know of
    $open = array_filter($ranges, fn ($x) => !$x['exact'] && $x['end'] === null);
    $newer = !$open && !array_filter($ranges, fn ($x) => watchmanVulnCmp($h, $x['end'] ?? $x['start']) <= 0);
    return $newer ? ['no', null] : ['yes', $fixed];
}

/**
 * A CVE record's verdict for a product here: its affected items whose vendor/product the name table gives ($cna), each
 * judged (watchmanVulnItem()) — the clean items decide (any yes and no no: yes; any no and no yes: no; both: maybe), items
 * that couldn't be compared only when there is no clean one; no item of the product: maybe
 *
 * @return array{0: string, 1: ?string}
 */
function watchmanVulnVerdict(?array $record, array $cna, string $have): array
{
    $items = [];
    foreach ((array) ($record['affected'] ?? []) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $key = strtolower(trim((string) ($a['vendor'] ?? ''))) . '|' . strtolower(trim((string) ($a['product'] ?? $a['packageName'] ?? '')));
        // a package name alone (no vendor) is the upstream's item (Red Hat's «OpenSSH»); a distribution's (vendor Red Hat,
        // product RHEL 9, packageName openssh, rpm versions) only by its vendor/product
        $pkgOnly = trim((string) ($a['vendor'] ?? '')) === '' ? '|' . strtolower(trim((string) ($a['packageName'] ?? ''))) : null;
        if (in_array($key, $cna, true) || ($pkgOnly !== null && in_array($pkgOnly, $cna, true))) {
            $items[] = $a;
        }
    }
    if (!$items) {
        return ['maybe', null];
    }
    $clean = $unclean = [];
    foreach ($items as $a) {
        [$v, $f] = watchmanVulnItem($a, $have);
        if ($v === 'maybe') {
            $unclean[] = [$v, $f];
        } else {
            $clean[] = [$v, $f];
        }
    }
    $say = array_unique(array_column($clean, 0));
    if ($say === ['yes']) {
        return ['yes', array_values(array_filter(array_column($clean, 1)))[0] ?? null];
    }
    if ($say === ['no']) {
        return ['no', null];
    }
    return ['maybe', array_values(array_filter(array_column(array_merge($clean, $unclean), 1)))[0] ?? null];
}

// ===================================================================== what is here

/**
 * The inventory, all local: list of {product, version (as found), clean (watchmanVulnClean(), or null), where}. $paths:
 * `unraid_version` (/etc/unraid-version), `packages` (/var/log/packages), `kernel` (a release string; else uname), `php`
 * (a version; else the PHP the office runs on — Unraid's own).
 *
 * @return list<array{product: string, version: string, clean: ?string, where: string}>
 */
function watchmanVulnInventory(array $paths): array
{
    $out = [];
    $add = function (string $product, string $raw, string $from) use (&$out): void {
        if ($raw !== '' && strlen($raw) <= 64) {
            $out[] = ['product' => $product, 'version' => $raw, 'clean' => watchmanVulnClean($raw, $from), 'where' => WATCH_VULN_HOST];
        }
    };
    if (isset($paths['unraid_version'])) {
        $u = (string) @file_get_contents((string) $paths['unraid_version'], false, null, 0, 256);
        if (preg_match('/^version="([0-9A-Za-z.+-]{1,40})"/m', $u, $m)) {
            $add('unraid', $m[1], 'plain');
        }
    }
    $kernel = (string) ($paths['kernel'] ?? php_uname('r'));
    if (preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $kernel)) {
        $add('kernel', $kernel, 'kernel');
    }
    $add('php', (string) ($paths['php'] ?? PHP_VERSION), 'plain');
    $pkgs = [];
    foreach (WATCH_VULN_PRODUCTS as $id => $p) {
        if (isset($p['pkg'])) {
            $pkgs[$p['pkg']] = $id;
        }
    }
    if (isset($paths['packages']) && is_dir((string) $paths['packages'])) {
        // Slackware: <name>-<version>-<arch>-<build>, the version without a dash
        foreach (scandir((string) $paths['packages']) ?: [] as $f) {
            if (preg_match('/^(.+)-([^-]+)-([^-]+)-([^-]+)$/D', $f, $m) && isset($pkgs[$m[1]])) {
                $add($pkgs[$m[1]], $m[2], 'plain');
            }
        }
    }
    return $out;
}

// ===================================================================== the lists: fetching

/**
 * One GET through the office's one door to the network (hostNet(): curl), as WATCH_VULN_* says — https only, no
 * redirects, the size cap, the fixed User-Agent and nothing else of this server; «only when newer than» when $since is
 * given → {code, body, lm (its Last-Modified), err}. $ctx['get'] stands in for it (the tests: recorded answers, never the
 * network).
 *
 * @return array{code: int, body: string, lm: ?string, err: ?string}
 */
function watchmanVulnGet(string $url, int $max, ?string $since, array $ctx = []): array
{
    if (isset($ctx['get'])) {
        return $ctx['get']($url, $since);
    }
    if (!str_starts_with($url, 'https://') || !preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/~%-]*$#D', $url)) {
        return ['code' => 0, 'body' => '', 'lm' => null, 'err' => 'bad_url'];
    }
    [$exit, $out, $err] = hostNet(watchmanVulnCurl($url, $max, $since), WATCH_VULN_TIMEOUT + 10);
    return watchmanVulnAnswer($exit, $out, $err);
}

/** curl's arguments for one fetch (a test reads them: nothing of this server in them) */
function watchmanVulnCurl(string $url, int $max, ?string $since): array
{
    $args = ['curl', '-s', '-S', '-m', (string) WATCH_VULN_TIMEOUT, '--proto', '=https', '--max-redirs', '0', '--max-filesize', (string) $max,
             '--compressed', '-A', WATCH_VULN_UA, '-H', 'Accept: application/json',
             '-w', '\n%{http_code}\n%header{last-modified}'];
    if ($since !== null && preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $since)) {
        array_push($args, '-H', "If-Modified-Since: $since");
    }
    $args[] = $url;
    return $args;
}

/** curl's output (the body, then the code and the Last-Modified header on lines of their own) → the answer */
function watchmanVulnAnswer(int $exit, string $out, string $err = ''): array
{
    if ($exit !== 0) {
        return ['code' => 0, 'body' => '', 'lm' => null, 'err' => 'curl ' . $exit . ($err !== '' ? ': ' . substr(trim($err), 0, 120) : '')];
    }
    $lines = explode("\n", $out);
    $lm = trim((string) array_pop($lines));
    $code = (int) array_pop($lines);
    return ['code' => $code, 'body' => implode("\n", $lines), 'lm' => preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $lm) ? $lm : null,
            'err' => $code === 200 || $code === 304 ? null : "HTTP $code"];
}

/** The address of a CVE's record in CVE List V5 (cves/<year>/<n>xxx/CVE-….json), or null for a malformed id */
function watchmanVulnCveUrl(string $cve): ?string
{
    if (!preg_match('/^CVE-(\d{4})-(\d{4,7})$/D', $cve, $m)) {
        return null;
    }
    return WATCH_VULN_URLS['cve'] . $m[1] . '/' . intdiv((int) $m[2], 1000) . 'xxx/' . $cve . '.json';
}

/** The name table's product for a KEV entry (vendorProject|product, lower case), or null */
function watchmanVulnKevProduct(string $vendor, string $product): ?string
{
    $key = strtolower(trim($vendor)) . '|' . strtolower(trim($product));
    foreach (WATCH_VULN_PRODUCTS as $id => $p) {
        if (in_array($key, $p['kev'], true)) {
            return $id;
        }
    }
    return null;
}

/** A CVE record reduced to what the comparison needs: its CNA's affected items (vendor, product, platforms, versions …) */
function watchmanVulnReduce(array $rec): ?array
{
    $id = $rec['cveMetadata']['cveId'] ?? null;
    if (!is_string($id) || !preg_match('/^CVE-\d{4}-\d{4,7}$/D', $id)) {
        return null;
    }
    $affected = [];
    foreach ((array) ($rec['containers']['cna']['affected'] ?? []) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $versions = [];
        foreach (array_slice((array) ($a['versions'] ?? []), 0, 100) as $v) {
            if (is_array($v)) {
                $versions[] = array_intersect_key($v, array_flip(['version', 'lessThan', 'lessThanOrEqual', 'status', 'versionType', 'changes']));
            }
        }
        $affected[] = array_filter(['vendor' => $a['vendor'] ?? null, 'product' => $a['product'] ?? null, 'packageName' => $a['packageName'] ?? null,
            'platforms' => $a['platforms'] ?? null, 'defaultStatus' => $a['defaultStatus'] ?? null, 'versions' => $versions], fn ($x) => $x !== null);
        if (count($affected) >= 50) {
            break;
        }
    }
    return ['id' => $id, 'affected' => $affected];
}

/**
 * The day's fetch (`job watchman-feeds`): KEV («only when newer»; kept: the entries the name table knows), the CVE
 * records of those (new ones, and kept ones older than WATCH_VULN_CVE_AGE — at most WATCH_VULN_CVE_RUN a run, the rest
 * the next day; records of entries that left the list go), Unraid's release list. status.json says, per list, when it
 * was tried, when it last worked, and what went wrong — never a body. A list that failed keeps what it had (it ages into
 * «not checked»). $ctx: `get` (the tests' answers), `now`.
 *
 * @return array{kev: ?string, cve: int, unraid: ?string}  what went wrong per list (null: fine), the records fetched
 */
function watchmanVulnFetch(string $dir, array $ctx = []): array
{
    $now = $ctx['now'] ?? time();
    $f = watchmanVulnDir($dir);
    foreach ([$f, "$f/cve"] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
    }
    $status = readJson("$f/status.json") ?? [];
    $mark = function (string $list, ?string $err) use (&$status, $now): void {
        $s = is_array($status[$list] ?? null) ? $status[$list] : [];
        $s['try'] = $now;
        if ($err === null) {
            $s['ok'] = $now;
            unset($s['err']);
        } else {
            $s['err'] = substr($err, 0, 120);
        }
        $status[$list] = $s;
    };
    $res = ['kev' => null, 'cve' => 0, 'unraid' => null];

    // KEV: only when newer than what is kept
    $kept = readJson("$f/kev.json");
    $since = is_array($kept) && is_string($kept['lm'] ?? null) ? $kept['lm'] : null;
    $a = watchmanVulnGet(WATCH_VULN_URLS['kev'], WATCH_VULN_MAX['kev'], $since, $ctx);
    if ($a['code'] === 304 && is_array($kept)) {
        $mark('kev', null);
    } elseif ($a['code'] === 200 && strlen($a['body']) <= WATCH_VULN_MAX['kev']) {
        $j = json_decode($a['body'], true, 16);
        $list = is_array($j) && is_array($j['vulnerabilities'] ?? null) && array_is_list($j['vulnerabilities']) ? $j['vulnerabilities'] : null;
        if ($list === null || count($list) < 100) {
            $res['kev'] = 'shape';           // not the catalogue (a page, an error, a cut file): what was kept stays
        } else {
            $entries = [];
            foreach ($list as $e) {
                if (!is_array($e) || !is_string($e['cveID'] ?? null) || !preg_match('/^CVE-\d{4}-\d{4,7}$/D', $e['cveID'])) {
                    continue;
                }
                $product = watchmanVulnKevProduct((string) ($e['vendorProject'] ?? ''), (string) ($e['product'] ?? ''));
                if ($product === null) {
                    continue;
                }
                $entries[] = ['cve' => $e['cveID'], 'product' => $product, 'name' => mb_substr((string) ($e['vulnerabilityName'] ?? ''), 0, 160),
                              'added' => preg_match('/^\d{4}-\d\d-\d\d$/D', (string) ($e['dateAdded'] ?? '')) ? $e['dateAdded'] : null,
                              'ransom' => ($e['knownRansomwareCampaignUse'] ?? '') === 'Known',
                              'desc' => mb_substr((string) ($e['shortDescription'] ?? ''), 0, WATCH_VULN_DESC)];
            }
            writeAtomic("$f/kev.json", jsonEncode(['v' => 1, 'fetched' => $now, 'lm' => $a['lm'], 'count' => count($list),
                'catalog' => is_string($j['catalogVersion'] ?? null) ? mb_substr($j['catalogVersion'], 0, 20) : null, 'entries' => $entries]), 0600, 0, 0);
            $kept = readJson("$f/kev.json");
            $mark('kev', null);
        }
    } else {
        $res['kev'] = $a['err'] ?? 'HTTP ' . $a['code'];
    }
    if ($res['kev'] !== null) {
        $mark('kev', $res['kev']);
    }

    // the CVE records of the kept entries: new ones first, then the oldest
    $want = array_values(array_unique(array_column(is_array($kept) ? (array) ($kept['entries'] ?? []) : [], 'cve')));
    $want = array_slice($want, 0, WATCH_VULN_CVE_MAX);
    $have = [];
    foreach (glob("$f/cve/CVE-*.json") ?: [] as $file) {
        $id = basename($file, '.json');
        if (!in_array($id, $want, true)) {
            @unlink($file);                  // left the list
            continue;
        }
        $have[$id] = (int) (readJson($file)['fetched'] ?? 0);
    }
    $due = array_values(array_filter($want, fn ($id) => !isset($have[$id])));
    asort($have);
    foreach ($have as $id => $t) {
        if ($t <= $now - WATCH_VULN_CVE_AGE) {
            $due[] = $id;
        }
    }
    $errors = 0;
    foreach (array_slice($due, 0, WATCH_VULN_CVE_RUN) as $id) {
        $url = watchmanVulnCveUrl($id);
        $b = $url === null ? ['code' => 0, 'body' => '', 'err' => 'bad_id'] : watchmanVulnGet($url, WATCH_VULN_MAX['cve'], null, $ctx);
        $rec = $b['code'] === 200 ? json_decode($b['body'], true, 64) : null;
        $red = is_array($rec) ? watchmanVulnReduce($rec) : null;
        if ($red === null || $red['id'] !== $id) {
            $errors++;
            continue;
        }
        writeAtomic("$f/cve/$id.json", jsonEncode(['v' => 1, 'fetched' => $now] + $red), 0600, 0, 0);
        $res['cve']++;
    }
    $mark('cve', $errors ? "$errors failed" : null);

    // Unraid's releases: version, support status, date
    $u = watchmanVulnGet(WATCH_VULN_URLS['unraid'], WATCH_VULN_MAX['unraid'], null, $ctx);
    $rel = $u['code'] === 200 ? json_decode($u['body'], true, 16) : null;
    $rows = [];
    foreach (is_array($rel) && array_is_list($rel) ? $rel : [] as $r) {
        if (is_array($r) && is_string($r['version'] ?? null) && preg_match('/^[0-9A-Za-z.+-]{1,40}$/D', $r['version'])
            && in_array($r['supportStatus'] ?? null, ['supported', 'security-updates', 'superseded', 'eol'], true)) {
            $rows[] = ['version' => $r['version'], 'status' => $r['supportStatus'], 'date' => is_string($r['date'] ?? null) ? mb_substr($r['date'], 0, 20) : null];
        }
    }
    if ($rows) {
        writeAtomic("$f/unraid.json", jsonEncode(['v' => 1, 'fetched' => $now, 'releases' => $rows]), 0600, 0, 0);
        $mark('unraid', null);
    } else {
        $res['unraid'] = $u['err'] ?? 'shape';
        $mark('unraid', $res['unraid']);
    }
    writeAtomic("$f/status.json", jsonEncode($status), 0600, 0, 0);
    return $res;
}

/** The job: only while he is on watch and the switch is on (one at a time: a lock in RAM) */
function watchmanVulnFetchRun(): int
{
    $dir = watchmanDir();
    $st = readJson("$dir/state.json") ?? [];
    if (watchmanHiredSince() === null || ($st['vuln_on'] ?? false) !== true) {
        return 0;
    }
    @mkdir(RUN_DIR, 0700, true);
    $lock = @fopen(RUN_DIR . '/watchman-feeds.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $r = watchmanVulnFetch($dir);
    $bad = array_filter(['kev' => $r['kev'], 'unraid' => $r['unraid']]);
    logLine('Night watchman: the lists of known vulnerabilities fetched — ' . ($bad ? 'not all: ' . implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($bad), $bad)) : 'fine')
        . " ({$r['cve']} records)");
    return 0;
}

/** Is a fetch due? On, and the last try a day ago (a failed one: WATCH_VULN_RETRY) — a clock set back: due */
function watchmanVulnDue(array $st, ?array $status, int $now): bool
{
    if (($st['vuln_on'] ?? false) !== true) {
        return false;
    }
    $kev = is_array($status['kev'] ?? null) ? $status['kev'] : [];
    $try = (int) ($kev['try'] ?? 0);
    $wait = isset($kev['err']) ? WATCH_VULN_RETRY : WATCH_VULN_EVERY;
    return $try === 0 || $now < $try || $now - $try >= $wait;
}

/** His tick: the fetch in a process of its own when due (one at a time; stopped after WATCH_VULN_RUN_MAX) */
function watchmanVulnTick(array $st, int $now): void
{
    $job = $GLOBALS['wmFeeds'] ?? null;
    if ($job) {
        $s = proc_get_status($job['process']);
        if ($s['running']) {
            if (hrtime(true) / 1e9 - $job['since'] > WATCH_VULN_RUN_MAX && empty($job['stopped'])) {
                proc_terminate($job['process']);
                $GLOBALS['wmFeeds']['stopped'] = true;
                logLine('Night watchman: fetching the lists took longer than ' . WATCH_VULN_RUN_MAX . ' s — stopped');
            }
            return;
        }
        proc_close($job['process']);
        $GLOBALS['wmFeeds'] = null;
        return;
    }
    if (!watchmanVulnDue($st, readJson(watchmanVulnDir(watchmanDir()) . '/status.json'), $now)) {
        return;
    }
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'watchman-feeds'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (is_resource($process)) {
        $GLOBALS['wmFeeds'] = ['process' => $process, 'since' => hrtime(true) / 1e9];
    }
}

/** The switch (the page's one click, the setup's later): on fetches from the next tick, off fetches nothing more */
function watchmanVulnSet(mixed $on, ?string $dir = null, bool $page = true): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    watchmanLocked($dir, function () use ($dir, $on): void {
        $d = watchmanLoad($dir);
        $st = $d['state'];
        $st['vuln_on'] = $on;
        $st['vuln_since'] = $on ? time() : null;
        watchmanSave($dir, $d, ['state' => $st]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine('Night watchman: known vulnerabilities ' . ($on ? 'on — the public lists once a day' : 'off — nothing fetched any more'));
    return ['ok' => true, 'state' => watchmanPageState()];
}

// ===================================================================== the lists: judging

/**
 * The lists against what is here ($inv: watchmanVulnInventory()) → what the round books and the page says: found
 * (verdict yes or maybe: {cve, product, where, verdict, have, fixed, name, ransom, added}), how many were checked and
 * not affected, whether the lists are there and fresh, Unraid's support status. Reads feeds/ only.
 *
 * @return array{found: list<array>, checked: int, fresh: bool, kev: ?int, unraid: ?array{version: string, status: string, newest: ?string}}
 */
function watchmanVulnJudge(string $dir, array $inv, int $now): array
{
    $f = watchmanVulnDir($dir);
    $kev = readJson("$f/kev.json");
    $fetched = is_array($kev) && is_int($kev['fetched'] ?? null) ? $kev['fetched'] : null;
    $status = readJson("$f/status.json") ?? [];
    $ok = is_int($status['kev']['ok'] ?? null) ? $status['kev']['ok'] : $fetched;
    $out = ['found' => [], 'checked' => 0, 'fresh' => $ok !== null && $now - $ok <= WATCH_VULN_OLD && $ok <= $now + 300, 'kev' => $ok, 'unraid' => null];
    $byProduct = [];
    foreach ($inv as $x) {
        $byProduct[$x['product']][] = $x;
    }
    foreach (is_array($kev) ? (array) ($kev['entries'] ?? []) : [] as $e) {
        if (!is_array($e) || !isset($byProduct[$e['product'] ?? ''])) {
            continue;
        }
        $rec = readJson("$f/cve/{$e['cve']}.json");
        foreach ($byProduct[$e['product']] as $x) {
            if ($x['clean'] === null) {
                $verdict = ['maybe', null];
            } else {
                $verdict = watchmanVulnVerdict(is_array($rec) ? $rec : null, WATCH_VULN_PRODUCTS[$e['product']]['cna'], $x['clean']);
                // Unraid's own entries: their records name no version («n/a»); a version released long after the entry
                // was listed (and fixed) isn't the one it was about
                if ($verdict[0] === 'maybe' && $e['product'] === 'unraid' && watchmanVulnUnraidLater($f, $x['version'], (string) ($e['added'] ?? ''))) {
                    $verdict = ['no', null];
                }
            }
            if ($verdict[0] === 'no') {
                $out['checked']++;
                continue;
            }
            $out['found'][] = ['cve' => $e['cve'], 'product' => $e['product'], 'where' => $x['where'], 'verdict' => $verdict[0], 'have' => $x['version'],
                               'fixed' => $verdict[1], 'name' => (string) ($e['name'] ?? ''), 'ransom' => !empty($e['ransom']), 'added' => $e['added'] ?? null];
        }
    }
    $rel = readJson("$f/unraid.json");
    foreach ($inv as $x) {
        if ($x['product'] !== 'unraid') {
            continue;
        }
        $newest = null;
        foreach ((array) ($rel['releases'] ?? []) as $r) {
            if (($r['status'] ?? '') === 'supported' && ($newest === null || version_compare((string) $r['version'], $newest, '>'))) {
                $newest = (string) $r['version'];
            }
            if (($r['version'] ?? null) === $x['version']) {
                $out['unraid'] = ['version' => $x['version'], 'status' => (string) $r['status'], 'newest' => null];
            }
        }
        if ($out['unraid'] !== null) {
            $out['unraid']['newest'] = $newest;
        }
    }
    return $out;
}

/** Unraid: this version was released more than a year after the entry was listed (Unraid's own list says when) */
function watchmanVulnUnraidLater(string $f, string $version, string $added): bool
{
    $t = strtotime($added);
    if ($t === false) {
        return false;
    }
    foreach ((array) (readJson("$f/unraid.json")['releases'] ?? []) as $r) {
        if (($r['version'] ?? null) === $version && is_string($r['date'] ?? null) && ($d = strtotime($r['date'])) !== false) {
            return $d > $t + 365 * 86400;
        }
    }
    return false;
}

/**
 * The verdicts into his book: one entry a vulnerability, product and place — `vuln_known` (affected, told) or `vuln_maybe`
 * (quiet; `important` with set.vuln.maybe) — kept up to date wherever it stands, never a second one for the same.
 *
 * @return list<string>  the kinds booked new
 */
function watchmanVulnCompare(array &$book, array $found, bool $tellMaybe, int $now): array
{
    $added = [];
    foreach ($found as $x) {
        $kind = $x['verdict'] === 'yes' ? 'vuln_known' : 'vuln_maybe';
        $key = "$kind:{$x['cve']}:{$x['product']}:{$x['where']}";
        $p = ['cve' => $x['cve'], 'product' => $x['product'], 'where' => $x['where'], 'have' => $x['have'], 'fixed' => $x['fixed'],
              'name' => $x['name'], 'ransom' => $x['ransom'], 'added' => $x['added']];
        $at = null;
        foreach ($book as $i => $e) {
            if (($e['key'] ?? '') === $key) {
                $at = $i;
            }
        }
        if ($at !== null) {
            if (($book[$at]['p'] ?? []) !== $p) {
                $book[$at]['p'] = $p;
                $book[$at]['last'] = $now;
            }
            continue;
        }
        $book[] = watchmanEntry($kind, $key, $now, $p) + ($kind === 'vuln_maybe' && $tellMaybe ? ['important' => true] : []);
        $added[] = $kind;
    }
    return $added;
}

/** set.vuln of his rules file (concept 3.1), only in exactly that shape: `maybe` (also tell «maybe affected») */
function watchmanVulnSettings(string $dir): array
{
    $r = readJson("$dir/rules.json");
    $set = is_array($r) && ($r['v'] ?? null) === 1 && is_array($r['set']['vuln'] ?? null) ? $r['set']['vuln'] : [];
    return ['maybe' => ($set['maybe'] ?? false) === true];
}

/** The advice for Unraid without security updates (posture tip unraid_eol): its version, its status, the newest supported */
function watchmanVulnTips(?array $judged, callable $add): void
{
    $u = is_array($judged['unraid'] ?? null) ? $judged['unraid'] : null;
    if ($u !== null && in_array($u['status'], ['eol', 'superseded'], true)) {
        $add('unraid_eol', ['version' => $u['version'], 'status' => $u['status'], 'newest' => (string) ($u['newest'] ?? '') ?: '–'],
            "{$u['version']}|{$u['status']}", ['to' => 'update', 'path' => '/Tools/Update']);
    }
}

/** What the page shows of it: the switch, since when, the lists' freshness, the counts */
function watchmanVulnPage(array $st, ?array $judged): array
{
    $on = ($st['vuln_on'] ?? false) === true;
    $j = is_array($judged) ? $judged : [];
    return ['on' => $on, 'since' => is_int($st['vuln_since'] ?? null) ? $st['vuln_since'] : null, 'checked_at' => $j['kev'] ?? null,
            'fresh' => !empty($j['fresh']), 'checked' => (int) ($j['checked'] ?? 0),
            'affected' => count(array_filter((array) ($j['found'] ?? []), fn ($x) => ($x['verdict'] ?? '') === 'yes')),
            'maybe' => count(array_filter((array) ($j['found'] ?? []), fn ($x) => ($x['verdict'] ?? '') === 'maybe'))];
}

/** An entry's words */
function watchmanVulnText(array $e): array
{
    $p = (array) ($e['p'] ?? []);
    $label = WATCH_VULN_PRODUCTS[$p['product'] ?? '']['label'] ?? (string) ($p['product'] ?? '?');
    return ['cve' => (string) ($p['cve'] ?? ''), 'product' => $label, 'have' => (string) ($p['have'] ?? ''),
            'fixed' => (string) ($p['fixed'] ?? '') ?: '–', 'name' => (string) ($p['name'] ?? ''),
            'where' => ($p['where'] ?? WATCH_VULN_HOST) === WATCH_VULN_HOST ? '' : (string) $p['where']];
}

/** Its words' shape: in a container (entry.vuln_known_ct …), with the fixed version (…_fixed) */
function watchmanVulnVariant(array $e): ?string
{
    $kind = (string) ($e['kind'] ?? '');
    if (!in_array($kind, ['vuln_known', 'vuln_maybe'], true)) {
        return null;
    }
    $p = (array) ($e['p'] ?? []);
    $ct = ($p['where'] ?? WATCH_VULN_HOST) !== WATCH_VULN_HOST;
    $fixed = $kind === 'vuln_known' && is_string($p['fixed'] ?? null) && $p['fixed'] !== '';
    return $ct || $fixed ? $kind . ($ct ? '_ct' : '') . ($fixed ? '_fixed' : '') : null;
}
