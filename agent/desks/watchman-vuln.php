<?php
declare(strict_types=1);

/*
 * The Night Watchman's «Known vulnerabilities» (1.96, the concept «Der Nachtwächter in 1.96», 11.3 A; area `vuln`).
 * Off until the user switches it on once (state.json `vuln_on`; the coordinator's decision, concept 12): «Nothing leaves
 * the server unless you set it up». Off is really off — nothing is fetched.
 *
 * On, once a day (WATCH_VULN_EVERY; a source that failed waits longer: watchmanVulnBackoff()) a job of its own (`job watchman-feeds`,
 * started by his tick like his round, niced, never as the agent's child that must outlive it) fetches public lists —
 * the same for every office, nothing of this server goes along but the request itself (a fixed User-Agent without a
 * version, no name, no id), https only, no redirects, each with a size cap (WATCH_VULN_MAX):
 *   cisa.gov          CISA's «Known Exploited Vulnerabilities» (KEV), «only when newer than» (If-Modified-Since); kept:
 *                     only the entries whose vendor/product the name table knows (WATCH_VULN_PRODUCTS), whether or not
 *                     that product runs here — so the fetches look alike at every office
 *   raw.githubusercontent.com  the CVE record (CVE List V5) of each of those entries: the vendor's own affected versions
 *   releases.unraid.net        Unraid's list of releases with their support status (not Unraid's own update check: that
 *                              one sends an id of the server)
 *   services.nvd.nist.gov      (2026-11-23) NIST's NVD, CVE API 2.0 without a key: only for the CVEs that concern a program
 *                              here and stayed «can't be checked for sure» — its CPE configurations (versionStart…/
 *                              versionEnd…) judge them cleanly. Nothing goes along but the CVE id; NVD's limit without a key
 *                              (5 requests in 30 s) kept by one request every WATCH_VULN_NVD_GAP s, at most WATCH_VULN_NVD_RUN
 *                              a day, each answer kept (nvd/<id>.json) and asked anew after WATCH_VULN_CVE_AGE
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
 *   maybe      no entry, never told, never a colour (1.96 «einfacher», 2026-11-23: 39 «maybe» lines of kernel CVEs from
 *              2010–2024 on a current kernel were noise, not something waiting): the product is here, the record can't be
 *              compared (no record, another version scheme, n/a) — the page's calm list «can't be checked for sure»
 *              (vuln_look.unsure); an older office's open `vuln_maybe` lines are closed quietly (watchmanVulnCompare()).
 *              Since 2026-11-25 also, each with its reason on the row: an inclusive bound coarser than the version here
 *              («≤ 4.3», Bash 4.3.011: watchmanVulnRough()), and a «not affected» that rests only on a release line the
 *              record never names while that line was past its end when the vulnerability became known (endoflife.date;
 *              PHP 7.2 against a record of the 8.x lines: watchmanVulnOutside())
 *   not        no entry; counted («31 checked, not affected»), the page's list «checked: doesn't concern you»
 * One entry a vulnerability, product and place (key vuln_known:<cve>:<product>:<where>), kept up to date wherever it
 * stands. Unraid's own version without security updates (eol, superseded): the advice `unraid_eol`.
 */

const WATCH_VULN_EVERY   = 86400;          // the lists once a day …
// a source that doesn't answer (2026-11-23): tried again the next day, each further failure doubles the wait up to a week,
// one answer resets it — never told, never a colour; after this many failures in a row the page says so in a calm line
const WATCH_VULN_BACKOFF_MAX = 7 * 86400;
const WATCH_VULN_DOWN_FAILS  = 3;
const WATCH_VULN_SOURCES     = ['kev', 'cve', 'nvd', 'unraid', 'eol'];
const WATCH_VULN_OLD     = 3 * 86400;      // a list older than this: «not checked»
const WATCH_VULN_RUN_MAX = 900;            // a fetch that takes longer is stopped
const WATCH_VULN_CVE_MAX = 300;            // CVE records kept at most
const WATCH_VULN_CVE_RUN = 60;             // fetched in one run at most (the rest the next day)
const WATCH_VULN_CVE_AGE = 7 * 86400;      // a kept record fetched anew after this long
const WATCH_VULN_UA      = 'UnraidSecretaryOffice';      // the same for every office: no version, no name
const WATCH_VULN_NVD_RUN = 40;             // NVD (2026-11-23): records asked in one run at most …
const WATCH_VULN_NVD_GAP = 6.5;            // … one every this many seconds (NVD without a key: 5 requests in 30 s)
// every Unraid the office installs on (the .plg's min, a test holds them equal) runs this kernel line or newer: a
// vulnerability whose every range the record names ends below it was fixed long before — «long fixed» on the page
const WATCH_VULN_FLOOR_UNRAID = '7.3.2';
const WATCH_VULN_FLOOR = ['kernel' => '6.12'];
const WATCH_VULN_URLS    = [
    'kev'    => 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json',
    'nvd'    => 'https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=',
    'cve'    => 'https://raw.githubusercontent.com/CVEProject/cvelistV5/main/cves/',
    'unraid' => 'https://releases.unraid.net/json',
    'eol'    => 'https://endoflife.date/api/v1/products/',      // step 16: <slug> of a fixed list (WATCH_VULN_PRODUCTS' eol)
];
// step 16: the only names read from a container image's environment — the official images set them (their Dockerfiles);
// never any other value of an image's or a container's environment (passwords live there)
const WATCH_VULN_ENV = ['PHP_VERSION', 'NGINX_VERSION', 'MARIADB_VERSION', 'PG_VERSION', 'REDIS_VERSION', 'NODE_VERSION', 'PYTHON_VERSION',
                        'GOLANG_VERSION', 'JAVA_VERSION', 'UNIFI_VERSION'];
// the one label read (2026-11-23), and only of an image whose name says «unifi» (linuxserver's UniFi Network Application
// names its version only there: «9.0.114-ls78»): kept as OCI_VERSION, judged as the UniFi Network Application
const WATCH_VULN_OCI_IMAGE = '/unifi/i';
const WATCH_VULN_IMAGES_EVERY = 3600;      // the images' versions looked at anew at most this often (or when the images change)
const WATCH_VULN_IMAGES_MAX   = 100;       // images asked at most
const WATCH_VULN_IMAGES_ASK   = 30;        // seconds one query of them may take, its asks one image at a time included
const WATCH_VULN_MAX     = ['kev' => 8 << 20, 'cve' => 2 << 20, 'unraid' => 1 << 20, 'eol' => 1 << 20, 'nvd' => 4 << 20];     // bytes of an answer at most
const WATCH_VULN_TIMEOUT = 60;             // seconds a fetch may take
const WATCH_VULN_DESC    = 300;            // characters of a description kept
const WATCH_VULN_NOT_MAX = 100;            // «checked: doesn't concern you» kept for his page (state.json vuln_look.not)
const WATCH_VULN_LOOK_MAX = 100;           // «what he checked» (2026-11-25): rows of this server, of the containers, names unread — each at most

/**
 * The name table: how the lists name a product (lower case «vendor|product»: KEV's vendorProject/product, a CVE record's
 * affected vendor/product), and where its version is here. Hand-kept: the lists name things unevenly («PHP Group» and
 * «PHP», two spellings of Bash). `pkg`: Slackware's package name; `host`: unraid, kernel or php (the office's own).
 */
const WATCH_VULN_PRODUCTS = [
    'unraid'   => ['label' => 'Unraid', 'kev' => ['unraid|unraid'], 'cna' => ['unraid|unraid', 'lime technology|unraid', 'lime-technology|unraid'], 'host' => 'unraid'],
    'kernel'   => ['label' => 'Linux kernel', 'kev' => ['linux|kernel'], 'cna' => ['linux|linux', 'linux|linux kernel'], 'host' => 'kernel'],
    'php'      => ['label' => 'PHP', 'kev' => ['php group|php', 'php|php', 'php|fastcgi process manager (fpm)'], 'cna' => ['php group|php', 'php|php'], 'host' => 'php',
                   'env' => 'PHP_VERSION', 'eol' => 'php'],
    'openssh'  => ['label' => 'OpenSSH', 'kev' => ['openbsd|openssh'], 'cna' => ['openbsd|openssh', '|openssh'], 'pkg' => 'openssh'],
    // `letters` (2026-11-25): its versions up to 1.1.1 carry a letter («1.0.1g») — read as a patch level for it alone
    'openssl'  => ['label' => 'OpenSSL', 'kev' => ['openssl|openssl'], 'cna' => ['openssl|openssl'], 'pkg' => 'openssl', 'letters' => true],
    'samba'    => ['label' => 'Samba', 'kev' => ['samba|samba'], 'cna' => ['samba|samba'], 'pkg' => 'samba'],
    'sudo'     => ['label' => 'sudo', 'kev' => ['sudo|sudo', 'todd miller|sudo'], 'cna' => ['sudo|sudo', 'sudo project|sudo'], 'pkg' => 'sudo'],
    'bash'     => ['label' => 'Bash', 'kev' => ['gnu|gnu bash', 'gnu|bourne-again shell (bash)', 'gnu|bash'], 'cna' => ['gnu|bash'], 'pkg' => 'bash'],
    'polkit'   => ['label' => 'polkit', 'kev' => ['red hat|polkit'], 'cna' => ['polkit|polkit', 'freedesktop|polkit', 'polkit project|polkit'], 'pkg' => 'polkit'],
    'inetutils' => ['label' => 'GNU Inetutils', 'kev' => ['gnu|inetutils'], 'cna' => ['gnu|inetutils'], 'pkg' => 'inetutils'],
    'nginx'    => ['label' => 'nginx', 'kev' => ['f5|nginx', 'nginx|nginx'], 'cna' => ['f5|nginx', 'f5|nginx open source', 'nginx|nginx'], 'pkg' => 'nginx',
                   'env' => 'NGINX_VERSION', 'eol' => 'nginx'],
    // step 16: software inside containers, by the official images' own ENV (WATCH_VULN_ENV)
    'mariadb'  => ['label' => 'MariaDB', 'kev' => [], 'cna' => ['mariadb|mariadb', 'mariadb|mariadb server'], 'env' => 'MARIADB_VERSION', 'eol' => 'mariadb'],
    'postgresql' => ['label' => 'PostgreSQL', 'kev' => ['postgresql|postgresql'], 'cna' => ['postgresql|postgresql'], 'env' => 'PG_VERSION', 'eol' => 'postgresql'],
    'redis'    => ['label' => 'Redis', 'kev' => ['redis|redis'], 'cna' => ['redis|redis'], 'env' => 'REDIS_VERSION', 'eol' => 'redis'],
    'nodejs'   => ['label' => 'Node.js', 'kev' => ['nodejs|node.js', 'node.js|node.js'], 'cna' => ['nodejs|node', 'nodejs|node.js'], 'env' => 'NODE_VERSION', 'eol' => 'nodejs'],
    'python'   => ['label' => 'Python', 'kev' => ['python software foundation|python'], 'cna' => ['python software foundation|cpython', 'python|python'], 'env' => 'PYTHON_VERSION', 'eol' => 'python'],
    'go'       => ['label' => 'Go', 'kev' => ['golang|go'], 'cna' => ['go standard library|crypto/tls', 'golang|go'], 'env' => 'GOLANG_VERSION', 'eol' => 'go'],
    'java'     => ['label' => 'Java', 'kev' => [], 'cna' => [], 'env' => 'JAVA_VERSION'],
    // 2026-11-23, passive only (never a login to a router): RouterOS by the version its own log says (watchnet.php keeps it
    // per sender: «installed system-…», «RouterOS upgraded from … to …», a CEF header); the UniFi Network Application by
    // its CEF header's version, or as a container here (UNIFI_VERSION, or an image named «unifi»: its OCI version label)
    'routeros' => ['label' => 'MikroTik RouterOS', 'kev' => ['mikrotik|routeros'], 'cna' => ['mikrotik|routeros'], 'net' => 'MikroTik'],
    'unifi'    => ['label' => 'UniFi Network', 'kev' => ['ubiquiti|unifi network application', 'ubiquiti|unifi network', 'ubiquiti|unifi'],
                   'cna' => ['ubiquiti|unifi network application', 'ubiquiti inc.|unifi network application', 'ubiquiti inc|unifi network application',
                             'ui|unifi network application', 'ubiquiti|unifi network'], 'env' => 'UNIFI_VERSION', 'net' => 'Ubiquiti'],
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
 * (anything else: «8.1.*» is a branch, not a version; «2.6.32-rc1», a commit, «n/a» never compare).
 * $letters (2026-11-25, only for a product whose name table says `letters`: OpenSSL up to 1.1.1): instead of «p…» one
 * lowercase letter as the patch level («1.0.1g»: a = 1 … z = 26) or «z» and one more («0.9.8za» = 27 …) — [numbers, p,
 * true], the third for the words (watchmanVulnText1())
 *
 * @return array{0: list<int>, 1: int, 2?: true}|null
 */
function watchmanVulnParse(string $v, bool $letters = false): ?array
{
    if ($letters) {
        if (!preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,4})(z[a-z]|[a-z])?$/D', $v, $m)) {
            return null;
        }
        $l = $m[2] ?? '';
        $n = array_map('intval', explode('.', $m[1]));
        return $l === '' ? [$n, 0] : [$n, strlen($l) === 2 ? 26 + ord($l[1]) - 96 : ord($l) - 96, true];
    }
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
function watchmanVulnClean(string $raw, string $from = 'plain', bool $letters = false): ?string
{
    $v = trim($raw);
    if ($from === 'kernel') {
        $v = preg_replace('/-Unraid$/Di', '', $v) ?? $v;
    } elseif ($from === 'env') {
        $v = preg_replace('/^(?:\d{1,3}:|jdk-|v)/', '', $v) ?? $v;          // an epoch, Temurin's «jdk-», a «v»
        $v = preg_split('/[+~-]/', $v)[0] ?? '';                            // a package's revision, a distribution's mark
    }
    return watchmanVulnParse($v, $letters) !== null ? $v : null;
}

/**
 * One range of a CVE record's affected versions → what the comparison needs, or null when it can't be compared (another
 * version scheme — git, rpm …; a «changes» list; a version that isn't clean). «X.*» as an end: up to the end of branch X.
 *
 * @return array{status: string, start: ?array, end: ?array, incl: bool, branch: ?list<int>, exact: bool}|null
 */
function watchmanVulnRange(array $r, bool $letters = false): ?array
{
    $status = $r['status'] ?? null;
    $type = $r['versionType'] ?? 'semver';
    if (!in_array($status, ['affected', 'unaffected', 'unknown'], true) || !in_array($type, ['semver', 'custom', 'original_commit_for_fix'], true)
        || isset($r['changes']) || !is_string($r['version'] ?? null)) {
        return null;
    }
    // «8.1.*» and (PHP Group's records, CVE-2019-11043) «7.1.x»: the branch
    $ver = preg_replace('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.x$/Di', '$1.*', $r['version']) ?? $r['version'];
    $start = preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.\*$/D', $ver, $m) ? watchmanVulnParse($m[1]) : watchmanVulnParse($ver, $letters);
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
    $end = preg_replace('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.x$/Di', '$1.*', $end) ?? $end;
    if ($end === '*') {
        return ['status' => $status, 'start' => $start, 'end' => null, 'incl' => true, 'branch' => null, 'exact' => false];
    }
    if (preg_match('/^(\d{1,9}(?:\.\d{1,9}){0,3})\.\*$/D', $end, $m)) {
        return ['status' => $status, 'start' => $start, 'end' => null, 'incl' => true, 'branch' => array_map('intval', explode('.', $m[1])), 'exact' => false];
    }
    $e = watchmanVulnParse($end, $letters);
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
function watchmanVulnItem(array $item, string $have, bool $letters = false): array
{
    $v = watchmanVulnItemJudge($item, $have, $letters);
    $h = watchmanVulnParse($have, $letters);
    if ($v[0] !== 'no' || $h === null) {
        return $v;
    }
    // 2026-11-25: a version named alone, coarser than the one here (NVD's exact CPE «bash:4.3» for Bash 4.3.011) and equal
    // on its own parts — whether this patch level is that version, the record doesn't say: a «no» becomes maybe (never a
    // yes). Not when the platform rules it out, nor when a range marks this version unaffected itself.
    $platforms = array_map(fn ($p) => strtolower((string) $p), (array) ($item['platforms'] ?? []));
    if ($platforms && !array_filter($platforms, fn ($p) => preg_match('/linux|unix|all/', $p))) {
        return $v;
    }
    $exact = null;
    foreach ((array) ($item['versions'] ?? []) as $r) {
        $x = is_array($r) ? watchmanVulnRange($r, $letters) : null;
        if ($x === null) {
            continue;
        }
        if ($x['status'] === 'unaffected' && watchmanVulnIn($h, $x)) {
            return $v;
        }
        if ($x['status'] === 'affected' && $x['exact'] && watchmanVulnCoarser($h, $x['start'])) {
            $exact ??= watchmanVulnText1($x['start']);
        }
    }
    return $exact === null ? $v : ['maybe', null, 'rough', $exact];
}

/** The strict rule of watchmanVulnItem() (see there), before a coarse version named alone is looked at */
function watchmanVulnItemJudge(array $item, string $have, bool $letters = false): array
{
    $h = watchmanVulnParse($have, $letters);
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
        $x = is_array($r) ? watchmanVulnRange($r, $letters) : null;
        if ($x === null) {
            // 2026-11-25: NVD's range «after X, before Y» (versionStartExcluding, kept as `start_excluding` beside its
            // lessThan): its start isn't said, its exclusive end is — at or past that end it holds this version no more
            // (a range holding nothing, its end kept for «fixed from»); below it, as before: can't be said
            $end = is_array($r) && isset($r['start_excluding']) && is_string($r['lessThan'] ?? null) ? watchmanVulnParse($r['lessThan'], $letters) : null;
            if ($end !== null && watchmanVulnCmp($h, $end) >= 0) {
                $ranges[] = ['status' => (string) ($r['status'] ?? 'affected'), 'start' => $end, 'end' => $end, 'incl' => false, 'branch' => null, 'exact' => false];
            } else {
                $bad = true;
            }
        } else {
            $ranges[] = $x;
        }
    }
    // the fixed version for the words: an unaffected range of this branch, else the end of the affected range holding it
    $fixed = null;
    foreach ($ranges as $x) {
        if ($x['status'] === 'unaffected' && $x['branch'] !== null && array_slice($h[0], 0, count($x['branch'])) === $x['branch']) {
            $fixed = watchmanVulnText1($x['start']);
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
                $fixed = watchmanVulnText1($x['end']);
            }
        }
        return ['yes', $fixed];
    }
    // 1.96 (2026-11-25): an inclusive bound coarser than the version here («≤ 4.3» for Bash 4.3.011, «≤ 9.7» for 9.7p1) —
    // equal on the bound's own parts, the record doesn't say whether this patch level still belongs to it: maybe, never
    // «not affected» (an exclusive bound «< 4.3» stays clearly outside; a bound as precise as the version compares as before)
    foreach ($ranges as $x) {
        if ($x['status'] === 'affected' && watchmanVulnRough($h, $x)) {
            return ['maybe', null, 'rough', watchmanVulnText1($x['end'])];
        }
    }
    $default = $item['defaultStatus'] ?? 'unknown';
    // past everything the record names, nothing open-ended: «fixed from» its highest end (null when that is a version
    // named alone — «never affected»)
    $past = function () use ($ranges, $h): ?array {
        if (!$ranges || array_filter($ranges, fn ($x) => !$x['exact'] && $x['end'] === null)
            || array_filter($ranges, fn ($x) => watchmanVulnCmp($h, $x['end'] ?? $x['start']) <= 0)) {
            return null;
        }
        $top = null;
        $exactTop = false;      // the highest bound is a version named alone, or affected itself («≤ 9.7p1»): no «fixed from» to name
        foreach ($ranges as $x) {
            $b = $x['end'] ?? $x['start'];
            $c = $top === null ? 1 : watchmanVulnCmp($b, $top);
            if ($c > 0) {
                $top = $b;
                $exactTop = $x['exact'] || $x['incl'];
            } elseif ($c === 0 && ($x['exact'] || $x['incl'])) {
                $exactTop = true;
            }
        }
        return ['no', $top === null || $exactTop ? null : watchmanVulnText1($top)];
    };
    if ($default === 'unaffected') {
        return $bad ? ['maybe', null] : ($past() ?? ['no', null]);
    }
    if ($bad || !$ranges || ($default !== 'affected' && !in_array('affected', array_column($ranges, 'status'), true))) {
        return ['maybe', $fixed];
    }
    // the record says nothing of the versions outside its ranges (defaultStatus unknown — the ADP's ranges, «0 < 2.6.36»):
    // past its fix (in its branch, or past everything it names) is no; else it can't be said — maybe, never yes
    $unsure = $default !== 'affected';
    // its own branch (major.minor) has an affected range, and this version is at or past that range's end: fixed in its
    // branch (PHP's «8.2.* < 8.2.20» for 8.2.25)
    foreach ($ranges as $x) {
        if ($x['status'] === 'affected' && $x['end'] !== null && array_slice($x['start'][0], 0, 2) === array_slice($h[0], 0, 2)
            && watchmanVulnCmp($h, $x['end']) >= ($x['incl'] ? 1 : 0)) {
            return ['no', watchmanVulnText1($x['end'])];
        }
    }
    // newer than everything the record names, nothing open-ended: a branch it didn't know of — «fixed from» the highest end
    return $past() ?? [$unsure ? 'maybe' : 'yes', $fixed];
}

/**
 * Range $r's inclusive end is coarser than $have (fewer numbers, or no patch level where $have has one) and $have equals it
 * on the end's own parts, past its start: «≤ 4.3» and 4.3.11 — the comparison would call it newer, the record meant the
 * whole 4.3 maybe
 */
function watchmanVulnRough(array $have, array $r): bool
{
    if (!$r['incl'] || $r['end'] === null || $r['exact'] || $r['branch'] !== null || watchmanVulnCmp($have, $r['start']) < 0) {
        return false;
    }
    return watchmanVulnCoarser($have, $r['end']);
}

/** $bound is coarser than $have (fewer numbers, or no patch level where $have has one), equal on its own parts, below it */
function watchmanVulnCoarser(array $have, array $bound): bool
{
    $n = count($bound[0]);
    // OpenSSL's letter is a release of its own («1.0.1» comes before «1.0.1a»), not a finer patch level
    $coarser = $n < count($have[0]) || ($bound[1] === 0 && $have[1] > 0 && empty($have[2]));
    return $coarser && array_slice($have[0], 0, $n) === $bound[0] && watchmanVulnCmp($have, $bound) > 0;
}

/** A parsed version as text («2.6.36», «9.8p1») */
function watchmanVulnText1(array $v): string
{
    if (!empty($v[2]) && $v[1] > 0) {
        return implode('.', $v[0]) . ($v[1] > 26 ? 'z' . chr(96 + $v[1] - 26) : chr(96 + $v[1]));      // OpenSSL's letter
    }
    return implode('.', $v[0]) . ($v[1] ? 'p' . $v[1] : '');
}

/**
 * NVD's answer (CVE API 2.0) reduced to what the comparison needs, in the shape of a record's affected items: per
 * vendor/product of its vulnerable CPE matches one item, defaultStatus unaffected (NVD lists what is vulnerable), the
 * ranges from versionStartIncluding / versionEndExcluding / versionEndIncluding, an exact version from the CPE itself.
 * A pre-release (an update field: «6.8:rc1») never runs here and is left out; what can't be said cleanly (a start
 * excluding, every version without a bound, a negated node) is kept as a range that can't be compared — the item stays
 * «maybe». null: not NVD's answer for this CVE.
 *
 * @return array{id: string, affected: list<array>, published?: string}|null
 */
function watchmanVulnNvdReduce(array $j, string $cve): ?array
{
    $v = $j['vulnerabilities'][0]['cve'] ?? null;
    if (!is_array($v) || ($v['id'] ?? null) !== $cve) {
        return is_array($j['vulnerabilities'] ?? null) && $j['vulnerabilities'] === [] ? ['id' => $cve, 'affected' => []] : null;
    }
    $items = [];
    foreach ((array) ($v['configurations'] ?? []) as $conf) {
        foreach (is_array($conf) ? (array) ($conf['nodes'] ?? []) : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            foreach ((array) ($node['cpeMatch'] ?? []) as $m) {
                if (!is_array($m) || ($m['vulnerable'] ?? false) !== true || !is_string($m['criteria'] ?? null) || str_contains($m['criteria'], '\\')) {
                    continue;
                }
                $c = explode(':', $m['criteria']);
                if (count($c) < 7 || $c[0] !== 'cpe' || $c[1] !== '2.3') {
                    continue;
                }
                $key = "{$c[3]}|{$c[4]}";
                $items[$key] ??= ['vendor' => $c[3], 'product' => $c[4], 'defaultStatus' => 'unaffected', 'versions' => []];
                $r = null;
                if (!empty($node['negate'])) {
                    $r = ['version' => 'n/a', 'status' => 'affected'];
                } elseif (isset($m['versionStartExcluding'])) {
                    // a start excluding can't be said; an exclusive end kept beside it (watchmanVulnItemJudge(): past that end, no)
                    $r = ['version' => 'n/a', 'status' => 'affected'] + (isset($m['versionEndExcluding'])
                        ? ['lessThan' => (string) $m['versionEndExcluding'], 'start_excluding' => (string) $m['versionStartExcluding']] : []);
                } elseif ($c[5] !== '*' && $c[5] !== '-') {
                    if ($c[6] !== '*' && $c[6] !== '-' && $c[6] !== '') {
                        continue;           // a pre-release («rc1»): never what runs here
                    }
                    $r = ['version' => $c[5], 'status' => 'affected', 'versionType' => 'custom'];
                } elseif (isset($m['versionEndExcluding']) || isset($m['versionEndIncluding'])) {
                    $r = ['version' => (string) ($m['versionStartIncluding'] ?? '0'), 'status' => 'affected', 'versionType' => 'custom']
                        + (isset($m['versionEndExcluding']) ? ['lessThan' => (string) $m['versionEndExcluding']] : ['lessThanOrEqual' => (string) $m['versionEndIncluding']]);
                } elseif (isset($m['versionStartIncluding'])) {
                    $r = ['version' => (string) $m['versionStartIncluding'], 'lessThanOrEqual' => '*', 'status' => 'affected', 'versionType' => 'custom'];
                } else {
                    $r = ['version' => 'n/a', 'status' => 'affected'];      // every version, no bound: not said cleanly
                }
                if (count($items[$key]['versions']) < 200) {
                    $items[$key]['versions'][] = $r;
                }
            }
        }
    }
    $pub = substr((string) ($v['published'] ?? ''), 0, 10);
    return ['id' => $cve, 'affected' => array_slice(array_values($items), 0, 50)] + (preg_match('/^\d{4}-\d\d-\d\d$/D', $pub) ? ['published' => $pub] : []);
}

/**
 * A CVE record's verdict for a product here: its affected items whose vendor/product the name table gives ($cna), each
 * judged (watchmanVulnItem()) — the clean items decide (any yes and no no: yes; any no and no yes: no; both: maybe), items
 * that couldn't be compared only when there is no clean one; no item of the product: maybe
 *
 * @return array{0: string, 1: ?string}
 */
function watchmanVulnVerdict(?array $record, array $cna, string $have, bool $letters = false): array
{
    $items = watchmanVulnItems($record, $cna);
    if (!$items) {
        return ['maybe', null];
    }
    $clean = $unclean = [];
    $why = null;            // why an item can't say (1.96, 2026-11-25): a bound coarser than the version here
    foreach ($items as $a) {
        $r = watchmanVulnItem($a, $have, $letters);
        if ($r[0] === 'maybe') {
            $unclean[] = [$r[0], $r[1]];
            $why ??= isset($r[2]) ? [2 => $r[2], 3 => $r[3]] : null;
        } else {
            $clean[] = [$r[0], $r[1]];
        }
    }
    $say = array_unique(array_column($clean, 0));
    if ($say === ['yes']) {
        return ['yes', array_values(array_filter(array_column($clean, 1)))[0] ?? null];
    }
    if ($say === ['no']) {
        return ['no', array_values(array_filter(array_column($clean, 1)))[0] ?? null];      // fixed from (the page's «checked» list, 1.96)
    }
    return ['maybe', array_values(array_filter(array_column(array_merge($clean, $unclean), 1)))[0] ?? null] + ($why ?? []);
}

/** A record's affected items of the product ($cna: the name table's spellings) — the CNA's, else the ADP's */
function watchmanVulnItems(?array $record, array $cna): array
{
    // a name as the lists write it: lower case, the ADP's CPE spelling («linux_kernel», «sudo_project») with spaces
    $name = fn (mixed $x): string => strtolower(trim(str_replace('_', ' ', (string) $x)));
    $items = [];
    foreach (['affected', 'adp'] as $from) {
        foreach ((array) ($record[$from] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $key = $name($a['vendor'] ?? '') . '|' . $name($a['product'] ?? $a['packageName'] ?? '');
            // a package name alone (no vendor) is the upstream's item (Red Hat's «OpenSSH»); a distribution's (vendor Red Hat,
            // product RHEL 9, packageName openssh, rpm versions) only by its vendor/product
            $pkgOnly = trim((string) ($a['vendor'] ?? '')) === '' ? '|' . $name($a['packageName'] ?? '') : null;
            if (in_array($key, $cna, true) || ($pkgOnly !== null && in_array($pkgOnly, $cna, true))) {
                $items[] = $a;
            }
        }
        if ($items) {
            break;          // the CNA's own items decide; the ADP's only when the CNA named none of the product
        }
    }
    return $items;
}

/**
 * The record never looked at this release line (1.96, 2026-11-25): of the product's items for this server (platforms),
 * no range holds $have — whatever its status — and none names the line $cycle (a range's start, end or branch beginning
 * with it: «8.2.* < 8.2.20» names 8.2, «0 < 5.3.12» names no 7.2). Such a «not affected» rests only on what the vendor
 * left out — PHP's records list the lines it still maintains. Only when an affected range starts above $have: the record
 * names newer lines only.
 */
function watchmanVulnOutside(?array $record, array $cna, string $have, string $cycle): bool
{
    $h = watchmanVulnParse($have);
    $line = watchmanVulnParse($cycle);
    if ($h === null || $line === null) {
        return false;
    }
    $line = $line[0];
    $names = fn (?array $parts): bool => $parts !== null && count($parts) >= count($line) && array_slice($parts, 0, count($line)) === $line;
    $newer = false;         // an affected range starting above this version: the record names newer lines (2026-11-25)
    foreach (watchmanVulnItems($record, $cna) as $item) {
        $platforms = array_map(fn ($p) => strtolower((string) $p), (array) ($item['platforms'] ?? []));
        if ($platforms && !array_filter($platforms, fn ($p) => preg_match('/linux|unix|all/', $p))) {
            continue;           // not for this server: its «no» is the platform's, not a line left out
        }
        foreach ((array) ($item['versions'] ?? []) as $r) {
            $x = is_array($r) ? watchmanVulnRange($r) : null;
            if ($x === null) {
                continue;
            }
            if (watchmanVulnIn($h, $x) || $names($x['start'][0]) || $names($x['end'][0] ?? null) || $names($x['branch'])) {
                return false;
            }
            $newer = $newer || ($x['status'] === 'affected' && watchmanVulnCmp($x['start'], $h) > 0);
        }
    }
    // only newer lines named: the vendor looked past this one. A version above every range (fixed before its line
    // existed: PHP 7.2 against «0 < 5.3.12») keeps «not affected», whatever the dates say
    return $newer;
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
            $out[] = ['product' => $product, 'version' => $raw, 'clean' => watchmanVulnClean($raw, $from, !empty(WATCH_VULN_PRODUCTS[$product]['letters'])),
                      'where' => WATCH_VULN_HOST];
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
    // step 16: the containers' software, by their images' own ENV ($paths['vuln_images']: container => [name => value],
    // watchmanVulnImages() — never another value of an environment)
    $envProduct = ['OCI_VERSION' => 'unifi'];      // only an image named «unifi» keeps it (watchmanVulnImagesParse())
    foreach (WATCH_VULN_PRODUCTS as $id => $p) {
        if (isset($p['env'])) {
            $envProduct[$p['env']] = $id;
        }
    }
    foreach (is_array($paths['vuln_images'] ?? null) ? $paths['vuln_images'] : [] as $ct => $env) {
        foreach (is_array($env) ? $env : [] as $name => $value) {
            if (isset($envProduct[$name]) && is_string($value) && strlen($value) <= 64) {
                $out[] = ['product' => $envProduct[$name], 'version' => $value, 'clean' => watchmanVulnClean($value, 'env'), 'where' => (string) $ct];
            }
        }
    }
    // the routers whose log comes here (2026-11-23, $paths['vuln_routers']: watchmanVulnRouters()) — by the version they said
    // themselves; one without a version is no line here (the page says «version unknown»)
    $seenNet = [];
    foreach (is_array($paths['vuln_routers'] ?? null) ? $paths['vuln_routers'] : [] as $r) {
        $id = is_array($r) ? ($r['product'] ?? null) : null;
        $v = is_array($r) && is_string($r['version'] ?? null) ? $r['version'] : '';
        if (is_string($id) && isset(WATCH_VULN_PRODUCTS[$id]) && $v !== '' && strlen($v) <= 64 && !isset($seenNet["$id|$v"])) {
            $seenNet["$id|$v"] = true;
            $out[] = ['product' => $id, 'version' => $v, 'clean' => watchmanVulnClean($v, 'plain'), 'where' => WATCH_VULN_HOST];
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

/**
 * The routers his net.json knows by their log (watchnet.php: per sender its vendor, product, version and since when that
 * version) → the name table's product, the version ('' when the log never said one), since when, the sender
 *
 * @return list<array{product: string, version: string, at: ?int, sender: string}>
 */
function watchmanVulnRouters(?array $net): array
{
    $byVendor = [];
    foreach (WATCH_VULN_PRODUCTS as $id => $p) {
        if (isset($p['net'])) {
            $byVendor[$p['net']] = $id;
        }
    }
    $out = [];
    foreach (is_array($net['senders'] ?? null) ? $net['senders'] : [] as $sender => $s) {
        $m = is_array($s['meta'] ?? null) ? $s['meta'] : [];
        $id = $byVendor[(string) ($m['vendor'] ?? '')] ?? null;
        if ($id === null) {
            continue;
        }
        $v = is_string($m['version'] ?? null) && preg_match('/^[0-9A-Za-z.+_-]{1,40}$/D', $m['version']) ? $m['version'] : '';
        $out[] = ['product' => $id, 'version' => $v, 'at' => is_int($m['version_at'] ?? null) ? $m['version_at'] : null, 'sender' => (string) $sender];
    }
    return array_slice($out, 0, 20);
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
    if (!str_starts_with($url, 'https://') || !preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9._/~%-]*(?:\?cveId=CVE-\d{4}-\d{4,7})?$#D', $url)) {
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
    $items = function (array $list): array {
        $affected = [];
        foreach ($list as $a) {
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
        return $affected;
    };
    // the ADP's items (CISA's «vulnrichment» adds affected ranges to records whose CNA named none — old kernel and Bash
    // CVEs: vendor «n/a»): read only when the CNA has no item of the product (watchmanVulnVerdict())
    $adp = [];
    foreach ((array) ($rec['containers']['adp'] ?? []) as $c) {
        if (is_array($c) && is_array($c['affected'] ?? null)) {
            $adp = array_merge($adp, $items($c['affected']));
        }
    }
    // when it became known (1.96, 2026-11-25: whether a release line was already past its end then — watchmanVulnJudge())
    $pub = substr((string) ($rec['cveMetadata']['datePublished'] ?? ''), 0, 10);
    return ['id' => $id, 'affected' => $items((array) ($rec['containers']['cna']['affected'] ?? []))] + ($adp ? ['adp' => array_slice($adp, 0, 50)] : [])
        + (preg_match('/^\d{4}-\d\d-\d\d$/D', $pub) ? ['published' => $pub] : []);
}

/**
 * The day's fetch (`job watchman-feeds`): KEV («only when newer»; kept: the entries the name table knows), the CVE
 * records of those (new ones, and kept ones older than WATCH_VULN_CVE_AGE — at most WATCH_VULN_CVE_RUN a run, the rest
 * the next day; records of entries that left the list go), Unraid's release list. status.json says, per list, when it
 * was tried, when it last worked, and what went wrong — never a body. A list that failed keeps what it had (it ages into
 * «not checked»). $ctx: `get` (the tests' answers), `now`.
 *
 * @return array{kev: ?string, cve: int, unraid: ?string, nvd: int}  what went wrong per list (null: fine), the records fetched
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
    $status['run'] = $now;          // the job's day (watchmanVulnDue()): a source waiting out its back-off isn't tried
    // each source: try, ok, err, and while it fails: fails (in a row), since (the first of them), next (not before)
    $mark = function (string $list, ?string $err) use (&$status, $now): void {
        $status[$list] = watchmanVulnBackoff(is_array($status[$list] ?? null) ? $status[$list] : [], $err, $now);
    };
    $may = fn (string $list): bool => watchmanVulnSourceDue($status, $list, $now);
    $res = ['kev' => null, 'cve' => 0, 'unraid' => null, 'nvd' => 0];

    // KEV: only when newer than what is kept
    $kept = readJson("$f/kev.json");
    $since = is_array($kept) && is_string($kept['lm'] ?? null) ? $kept['lm'] : null;
    $a = $may('kev') ? watchmanVulnGet(WATCH_VULN_URLS['kev'], WATCH_VULN_MAX['kev'], $since, $ctx) : ['code' => -1, 'body' => '', 'lm' => null, 'err' => null];
    if ($a['code'] === -1) {
        // waiting out its back-off: what was kept stays
    } elseif ($a['code'] === 304 && is_array($kept)) {
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
    $undated = [];          // kept by an older office, reduced without its publication date (2026-11-25): asked anew first
    foreach (glob("$f/cve/CVE-*.json") ?: [] as $file) {
        $id = basename($file, '.json');
        if (!in_array($id, $want, true)) {
            @unlink($file);                  // left the list
            continue;
        }
        $rec = readJson($file);
        $have[$id] = (int) ($rec['fetched'] ?? 0);
        if (is_array($rec) && !array_key_exists('published', $rec)) {
            $undated[] = $id;
        }
    }
    $due = array_values(array_filter($want, fn ($id) => !isset($have[$id])));
    asort($have);
    $due = array_merge($due, array_values(array_intersect(array_keys($have), $undated)));
    foreach ($have as $id => $t) {
        if ($t <= $now - WATCH_VULN_CVE_AGE && !in_array($id, $undated, true)) {
            $due[] = $id;
        }
    }
    $errors = 0;
    $down = 0;              // the source itself not answering (no connection, «slow down», a server error): back-off
    // what was asked and didn't come (no such record, an answer not in its shape): judged without it from then on — an entry
    // nobody asked about yet is never judged («maybe» for every entry of a first fetch: concept 12.1, gap 3)
    $tried = readJson("$f/cve-tried.json");
    $tried = is_array($tried) ? array_intersect_key(array_filter($tried, 'is_int'), array_flip($want)) : [];
    foreach ($may('cve') ? array_slice($due, 0, WATCH_VULN_CVE_RUN) : [] as $id) {
        $url = watchmanVulnCveUrl($id);
        $b = $url === null ? ['code' => 0, 'body' => '', 'err' => 'bad_id'] : watchmanVulnGet($url, WATCH_VULN_MAX['cve'], null, $ctx);
        if ($url !== null && watchmanVulnSourceDown((int) $b['code'])) {
            $down++;
            if ($down >= 3 && !$res['cve']) {
                break;      // three in a row and nothing came: the source is away — the rest another day
            }
            continue;
        }
        $rec = $b['code'] === 200 ? json_decode($b['body'], true, 64) : null;
        $red = is_array($rec) ? watchmanVulnReduce($rec) : null;
        if ($red === null || $red['id'] !== $id) {
            $errors++;
            $tried[$id] = $now;
            continue;
        }
        unset($tried[$id]);
        // `published` always written — null when the record names no date (the day CISA listed it counts then), so a
        // record without one is never asked again before WATCH_VULN_CVE_AGE
        writeAtomic("$f/cve/$id.json", jsonEncode(['v' => 1, 'fetched' => $now] + $red + ['published' => null]), 0600, 0, 0);
        $res['cve']++;
    }
    writeAtomic("$f/cve-tried.json", (string) json_encode((object) $tried), 0600, 0, 0);
    if ($may('cve')) {
        $mark('cve', $down && !$res['cve'] ? "$down not answered" : null);
        if ($errors || ($down && $res['cve'])) {
            $status['cve']['err'] = ($errors ? "$errors failed" : '') . ($errors && $down ? ', ' : '') . ($down ? "$down not answered" : '');
        }
    }

    // NVD (2026-11-23): only the CVEs that concern a program here and stayed «can't be checked for sure» (his last round's
    // vuln_look.unsure), and those asked before while still on the list — new ones first, then the oldest; one request every
    // WATCH_VULN_NVD_GAP s, at most WATCH_VULN_NVD_RUN; each answer written at once (a stopped job keeps what it had)
    $res['nvd'] = 0;
    if (!is_dir("$f/nvd")) {
        @mkdir("$f/nvd", 0700, true);
    }
    $unsure = $ctx['unsure'] ?? array_column(array_filter((array) ((readJson("$dir/state.json") ?? [])['vuln_look']['unsure'] ?? []), 'is_array'), 'cve');
    $unsure = array_values(array_unique(array_filter($unsure, fn ($x) => is_string($x) && in_array($x, $want, true))));
    $nvdHave = [];
    $nvdUndated = [];       // an older office's answer, kept without its publication date: asked anew after the new ones
    foreach (glob("$f/nvd/CVE-*.json") ?: [] as $file) {
        $id = basename($file, '.json');
        if (!in_array($id, $want, true)) {
            @unlink($file);                  // left the list
            continue;
        }
        $rec = readJson($file);
        $nvdHave[$id] = (int) ($rec['fetched'] ?? 0);
        if (is_array($rec) && !array_key_exists('published', $rec)) {
            $nvdUndated[] = $id;
        }
    }
    $nvdDue = array_values(array_filter($unsure, fn ($id) => !isset($nvdHave[$id])));
    asort($nvdHave);
    $nvdDue = array_merge($nvdDue, array_values(array_intersect(array_keys($nvdHave), $nvdUndated)));
    foreach ($nvdHave as $id => $t) {
        if ($t <= $now - WATCH_VULN_CVE_AGE && !in_array($id, $nvdUndated, true)) {
            $nvdDue[] = $id;
        }
    }
    $nvdErr = null;
    $nvdAsk = $may('nvd') ? array_slice(array_values(array_unique($nvdDue)), 0, WATCH_VULN_NVD_RUN) : [];
    $sleep = $ctx['sleep'] ?? fn (float $s) => usleep((int) ($s * 1e6));
    foreach ($nvdAsk as $i => $id) {
        if ($i > 0) {
            $sleep(WATCH_VULN_NVD_GAP);
        }
        $b = watchmanVulnGet(WATCH_VULN_URLS['nvd'] . $id, WATCH_VULN_MAX['nvd'], null, $ctx);
        if (watchmanVulnSourceDown((int) $b['code'])) {
            $nvdErr = $b['code'] ? 'HTTP ' . $b['code'] : (string) ($b['err'] ?? 'no answer');   // NVD says slow down (or is away): the rest another day
            break;
        }
        $j = $b['code'] === 200 ? json_decode($b['body'], true, 64) : null;
        $red = is_array($j) ? watchmanVulnNvdReduce($j, $id) : null;
        if ($red === null) {
            $nvdErr = $b['err'] ?? ('HTTP ' . $b['code']);
            continue;
        }
        writeAtomic("$f/nvd/$id.json", jsonEncode(['v' => 1, 'fetched' => $now] + $red + ['published' => null]), 0600, 0, 0);
        $res['nvd']++;
    }
    if ($nvdAsk) {
        $mark('nvd', $nvdErr !== null && !$res['nvd'] ? $nvdErr : null);       // one answer is an answer: the rest the next day
        if ($nvdErr !== null && $res['nvd']) {
            $status['nvd']['err'] = $nvdErr;
        }
    }

    // Unraid's releases: version, support status, date
    $u = $may('unraid') ? watchmanVulnGet(WATCH_VULN_URLS['unraid'], WATCH_VULN_MAX['unraid'], null, $ctx) : null;
    $rel = $u !== null && $u['code'] === 200 ? json_decode($u['body'], true, 16) : null;
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
    } elseif ($u !== null) {
        $res['unraid'] = $u['err'] ?? 'shape';
        $mark('unraid', $res['unraid']);
    }
    // endoflife.date (step 16): each slug of the fixed list — the same for every office
    if (!is_dir("$f/eol")) {
        @mkdir("$f/eol", 0700, true);
    }
    $eolErr = 0;
    $slugs = $may('eol') ? array_unique(array_filter(array_column(WATCH_VULN_PRODUCTS, 'eol'))) : [];
    foreach ($slugs as $slug) {
        $e = watchmanVulnGet(WATCH_VULN_URLS['eol'] . $slug, WATCH_VULN_MAX['eol'], null, $ctx);
        $j = $e['code'] === 200 ? json_decode($e['body'], true, 16) : null;
        $cycles = [];
        foreach (is_array($j['result']['releases'] ?? null) ? $j['result']['releases'] : [] as $r) {
            if (is_array($r) && is_string($r['name'] ?? null) && preg_match('/^\d{1,4}(?:\.\d{1,4}){0,2}$/D', $r['name'])) {
                $latest = is_array($r['latest'] ?? null) ? ($r['latest']['name'] ?? null) : null;
                $cycles[] = ['name' => $r['name'], 'eol' => is_bool($r['isEol'] ?? null) ? $r['isEol'] : null,
                             'eol_from' => is_string($r['eolFrom'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d$/D', $r['eolFrom']) ? $r['eolFrom'] : null,
                             'latest' => is_string($latest) && watchmanVulnParse($latest) !== null ? $latest : null];
            }
        }
        if ($cycles) {
            writeAtomic("$f/eol/$slug.json", jsonEncode(['v' => 1, 'fetched' => $now, 'cycles' => array_slice($cycles, 0, 200)]), 0600, 0, 0);
        } else {
            $eolErr++;
        }
    }
    if ($slugs) {
        $mark('eol', $eolErr === count($slugs) ? "$eolErr failed" : null);       // one list that came is an answer
        if ($eolErr && $eolErr < count($slugs)) {
            $status['eol']['err'] = "$eolErr failed";
        }
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
        . " ({$r['cve']} records, {$r['nvd']} from NVD)");
    return 0;
}

/** Is a fetch due? On, and the last run a day ago (an older status: KEV's last try) — a clock set back: due */
function watchmanVulnDue(array $st, ?array $status, int $now): bool
{
    if (($st['vuln_on'] ?? false) !== true) {
        return false;
    }
    $run = is_int($status['run'] ?? null) ? $status['run'] : (int) ($status['kev']['try'] ?? 0);
    return $run === 0 || $now < $run || $now - $run >= WATCH_VULN_EVERY;
}

/**
 * A source's status after a try: an answer — ok, its failures forgotten; none — err, fails in a row, since (the first),
 * next: the next day, each further failure double the wait, at most WATCH_VULN_BACKOFF_MAX
 */
function watchmanVulnBackoff(array $s, ?string $err, int $now): array
{
    $s['try'] = $now;
    if ($err === null) {
        $s['ok'] = $now;
        unset($s['err'], $s['fails'], $s['since'], $s['next']);
        return $s;
    }
    $fails = (is_int($s['fails'] ?? null) ? $s['fails'] : 0) + 1;
    $s['err'] = substr($err, 0, 120);
    $s['fails'] = $fails;
    $s['since'] = is_int($s['since'] ?? null) ? $s['since'] : $now;
    $s['next'] = $now + min(WATCH_VULN_EVERY << min($fails - 1, 10), WATCH_VULN_BACKOFF_MAX);
    return $s;
}

/** May this source be asked now? (not while it waits out its back-off; a clock set back, or a wait past the bound: yes) */
function watchmanVulnSourceDue(array $status, string $src, int $now): bool
{
    $next = $status[$src]['next'] ?? null;
    return !is_int($next) || $now >= $next - 300 || $next - $now > WATCH_VULN_BACKOFF_MAX;
}

/** An answer that says the source itself is away (no connection, «slow down», forbidden, a server error) — not «no such record» */
function watchmanVulnSourceDown(int $code): bool
{
    return $code === 0 || $code === 403 || $code === 429 || $code >= 500;
}

/**
 * The sources that haven't answered WATCH_VULN_DOWN_FAILS times in a row (the page's calm line): src, since, next, ok
 *
 * @return list<array{src: string, since: int, next: ?int, ok: ?int, fails: int}>
 */
function watchmanVulnDown(?array $status): array
{
    $out = [];
    foreach (WATCH_VULN_SOURCES as $src) {
        $s = is_array($status[$src] ?? null) ? $status[$src] : [];
        if (is_int($s['fails'] ?? null) && $s['fails'] >= WATCH_VULN_DOWN_FAILS && is_int($s['since'] ?? null)) {
            $out[] = ['src' => $src, 'since' => $s['since'], 'next' => is_int($s['next'] ?? null) ? $s['next'] : null,
                      'ok' => is_int($s['ok'] ?? null) ? $s['ok'] : null, 'fails' => $s['fails']];
        }
    }
    return $out;
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
 * Step 16: the containers' software against endoflife.date (watchmanVulnEol(); $images: container => its versions).
 *
 * @return array{found: list<array>, checked: int, fresh: bool, kev: ?int, pending: list<string>, not: list<array>, unsure: list<array>,
 *               kev_total: ?int, kev_known: int, kev_here: int, kev_products: array<string, int>, down: list<array>, routers: list<array>, unraid: ?array{version: string, status: string, newest: ?string},
 *               software: list<array>, unknown: list<string>, host: list<array>, cts: list<array>, ct_total: ?int, ct_read: int,
 *               unread: list<string>, unread_n: int}
 *
 * What he looked at (1.96, 2026-11-25: «Was er geprüft hat» on the page): this server's rows of the inventory (host), the
 * containers' (cts: container, product, version), and of $cts (every container's name; null: Docker didn't answer) how
 * many there are, how many had a version he could read, and the others by name — each list at most WATCH_VULN_LOOK_MAX.
 */
function watchmanVulnJudge(string $dir, array $inv, int $now, array $images = [], array $routers = [], ?array $cts = null): array
{
    $f = watchmanVulnDir($dir);
    $kev = readJson("$f/kev.json");
    $fetched = is_array($kev) && is_int($kev['fetched'] ?? null) ? $kev['fetched'] : null;
    $status = readJson("$f/status.json") ?? [];
    $ok = is_int($status['kev']['ok'] ?? null) ? $status['kev']['ok'] : $fetched;
    $out = ['found' => [], 'checked' => 0, 'fresh' => $ok !== null && $now - $ok <= WATCH_VULN_OLD && $ok <= $now + 300, 'kev' => $ok, 'unraid' => null,
            'pending' => [], 'not' => [], 'unsure' => [],
            // what he looked at (2026-11-23: an empty result must be believable): the whole list, the entries of products
            // the name table knows, those of products running here — by product
            'kev_total' => is_array($kev) && is_int($kev['count'] ?? null) ? $kev['count'] : null,
            'kev_known' => is_array($kev) ? count((array) ($kev['entries'] ?? [])) : 0, 'kev_here' => 0, 'kev_products' => [],
            'down' => watchmanVulnDown($status),
            // the routers by their log (2026-11-23): each with its version and since when, or none known yet
            'routers' => array_map(fn ($r) => ['label' => WATCH_VULN_PRODUCTS[$r['product']]['label'] ?? $r['product'], 'version' => $r['version'], 'at' => $r['at']], $routers)]
        + watchmanVulnEol($dir, $inv, $images) + watchmanVulnLook($inv, $cts);
    $out['unknown'] = array_slice($out['unknown'], 0, WATCH_VULN_LOOK_MAX);
    $tried = readJson("$f/cve-tried.json");
    $tried = is_array($tried) ? $tried : [];
    $eolCycles = [];            // endoflife.date's lines per slug, read once
    $byProduct = [];
    foreach ($inv as $x) {
        $byProduct[$x['product']][] = $x;
    }
    foreach (is_array($kev) ? (array) ($kev['entries'] ?? []) : [] as $e) {
        if (!is_array($e) || !isset($byProduct[$e['product'] ?? ''])) {
            continue;
        }
        $out['kev_here']++;
        $out['kev_products'][$e['product']] = ($out['kev_products'][$e['product']] ?? 0) + 1;
        $rec = readJson("$f/cve/{$e['cve']}.json");
        if (!is_array($rec) && !isset($tried[$e['cve']])) {
            $out['pending'][] = (string) $e['cve'];         // its record not fetched yet (a first fetch, over 60 a day): not judged yet
            continue;
        }
        $cna = WATCH_VULN_PRODUCTS[$e['product']]['cna'];
        $slug = WATCH_VULN_PRODUCTS[$e['product']]['eol'] ?? null;
        // a record's verdict, and (1.96, 2026-11-25) a «not affected» that rests only on a release line the record never
        // looked at, while endoflife.date says that line was past its end when the vulnerability became known (the record's
        // publication, else the day CISA listed it): can't be checked for sure — the vendor no longer looked at such lines.
        // A line still maintained then, a product without end-of-life data, a range that names the version: as before.
        $letters = !empty(WATCH_VULN_PRODUCTS[$e['product']]['letters']);
        $judge = function (?array $r, string $clean) use ($cna, $slug, $e, $f, &$eolCycles, $letters): array {
            $v = watchmanVulnVerdict($r, $cna, $clean, $letters);
            if ($v[0] !== 'no' || $r === null || $slug === null) {
                return $v;
            }
            $eolCycles[$slug] ??= (array) (readJson("$f/eol/$slug.json")['cycles'] ?? []);
            $line = watchmanVulnCycle($eolCycles[$slug], $clean);
            $known = is_string($r['published'] ?? null) ? $r['published'] : (is_string($e['added'] ?? null) ? $e['added'] : null);
            $end = is_array($line) && is_string($line['eol_from'] ?? null) ? $line['eol_from'] : null;
            if ($end !== null && $known !== null && $end < $known && watchmanVulnOutside($r, $cna, $clean, (string) $line['name'])) {
                return ['maybe', null, 'eol', (string) $line['name']];
            }
            return $v;
        };
        foreach ($byProduct[$e['product']] as $x) {
            $src = 'cve';
            if ($x['clean'] === null) {
                $verdict = ['maybe', null];
            } else {
                $verdict = $judge(is_array($rec) ? $rec : null, $x['clean']);
                // Unraid's own entries: their records name no version («n/a»); a version released long after the entry
                // was listed (and fixed) isn't the one it was about
                if ($verdict[0] === 'maybe' && $e['product'] === 'unraid' && watchmanVulnUnraidLater($f, $x['version'], (string) ($e['added'] ?? ''))) {
                    $verdict = ['no', null];
                }
                // still «maybe»: NVD's CPE ranges, when it was asked (2026-11-23) — only a clean verdict counts; its reason
                // why it can't say either, when the record gave none
                if ($verdict[0] === 'maybe') {
                    $nvd = readJson("$f/nvd/{$e['cve']}.json");
                    $byNvd = is_array($nvd) ? $judge($nvd, $x['clean']) : ['maybe', null];
                    if ($byNvd[0] !== 'maybe') {
                        $verdict = $byNvd;
                        $src = 'nvd';
                    } elseif (!isset($verdict[2]) && isset($byNvd[2])) {
                        $verdict = $byNvd;
                    }
                }
            }
            if ($verdict[0] === 'no') {
                $out['checked']++;
                // «Checked: doesn't concern you» on his page (1.96): what runs here, the vulnerability, fixed from — at most WATCH_VULN_NOT_MAX
                if (count($out['not']) < WATCH_VULN_NOT_MAX) {
                    $out['not'][] = ['cve' => $e['cve'], 'product' => $e['product'], 'where' => $x['where'], 'have' => $x['version'], 'fixed' => $verdict[1],
                                     'name' => mb_strcut((string) ($e['name'] ?? ''), 0, 120, 'UTF-8')]
                        + (watchmanVulnAncient($e['product'], $verdict[1]) ? ['ancient' => true] : []) + ($src === 'nvd' ? ['src' => 'nvd'] : []);
                }
                continue;
            }
            if ($verdict[0] === 'maybe') {
                // can't be checked for sure (1.96, 2026-11-23): never an entry — the page's calm list, at most WATCH_VULN_NOT_MAX
                if (count($out['unsure']) < WATCH_VULN_NOT_MAX) {
                    $out['unsure'][] = ['cve' => $e['cve'], 'product' => $e['product'], 'where' => $x['where'], 'have' => $x['version'],
                                        'name' => mb_strcut((string) ($e['name'] ?? ''), 0, 120, 'UTF-8'),
                                        'nvd' => is_file("$f/nvd/{$e['cve']}.json")]      // NVD asked already (and it couldn't say either)
                        // why (2026-11-25): «rough» — the list names the version only to {bound}; «eol» — the line {bound}
                        // was past its end when it became known
                        + (in_array($verdict[2] ?? null, ['rough', 'eol'], true) ? ['why' => $verdict[2], 'bound' => (string) $verdict[3]] : []);
                }
                continue;
            }
            $out['found'][] = ['cve' => $e['cve'], 'product' => $e['product'], 'where' => $x['where'], 'verdict' => $verdict[0], 'have' => $x['version'],
                               'src' => $src,
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

/**
 * What he looked at (1.96, 2026-11-25): this server's inventory rows (not the routers': they are said on their own), the
 * containers' rows, and of the containers ($cts: their names; null: Docker didn't answer) the total, those read and the
 * others — lists capped at WATCH_VULN_LOOK_MAX, the counts whole
 *
 * @return array{host: list<array{product: string, version: string}>, cts: list<array{where: string, product: string, version: string}>,
 *               ct_total: ?int, ct_read: int, unread: list<string>, unread_n: int}
 */
function watchmanVulnLook(array $inv, ?array $cts): array
{
    $host = $rows = $read = $seen = [];
    foreach ($inv as $x) {
        $key = "{$x['where']}|{$x['product']}|{$x['version']}";
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if ($x['where'] === WATCH_VULN_HOST) {
            if (!isset(WATCH_VULN_PRODUCTS[$x['product']]['net']) && count($host) < WATCH_VULN_LOOK_MAX) {
                $host[] = ['product' => $x['product'], 'version' => $x['version']];
            }
            continue;
        }
        $read[$x['where']] = true;
        if (count($rows) < WATCH_VULN_LOOK_MAX) {
            $rows[] = ['where' => $x['where'], 'product' => $x['product'], 'version' => $x['version']];
        }
    }
    usort($rows, fn ($a, $b) => strcasecmp($a['where'], $b['where']) ?: strcmp($a['product'], $b['product']));
    $names = $cts === null ? [] : array_values(array_unique(array_map('strval', $cts)));
    $unread = array_values(array_filter($names, fn ($n) => !isset($read[$n])));
    sort($unread);
    return ['host' => $host, 'cts' => $rows, 'ct_total' => $cts === null ? null : count($names),
            'ct_read' => $cts === null ? count($read) : count(array_intersect_key($read, array_flip($names))),
            'unread' => array_slice($unread, 0, WATCH_VULN_LOOK_MAX), 'unread_n' => count($unread)];
}

/**
 * Fixed long before anything the office runs on (2026-11-23): the record's highest bound («fixed from») lies below the line
 * every Unraid from WATCH_VULN_FLOOR_UNRAID on runs (WATCH_VULN_FLOOR) — «long fixed» on the page, folded apart
 */
function watchmanVulnAncient(string $product, ?string $fixed): bool
{
    $floor = isset(WATCH_VULN_FLOOR[$product]) ? watchmanVulnParse(WATCH_VULN_FLOOR[$product]) : null;
    $f = $fixed !== null ? watchmanVulnParse($fixed) : null;
    return $floor !== null && $f !== null && watchmanVulnCmp($f, $floor) < 0;
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
 * The verdicts into his book: one entry a vulnerability, product and place — `vuln_known` (affected, told) — kept up to
 * date wherever it stands, never a second one for the same. «Maybe» is never an entry (1.96, 2026-11-23): an open
 * `vuln_maybe` line an older office wrote is closed quietly at once (noted by himself, never told); its vulnerability
 * stands in the page's list «can't be checked for sure».
 *
 * @return list<string>  the kinds booked new
 */
function watchmanVulnCompare(array &$book, array $found, int $now, array &$seen = [], bool $fresh = false, array $pending = []): array
{
    $added = [];
    $foundNow = [];
    watchmanVulnMaybeClose($book, $now);
    foreach (array_keys($seen) as $k) {
        if (str_starts_with((string) $k, 'vuln_maybe:')) {
            unset($seen[$k]);
        }
    }
    foreach ($found as $x) {
        if (($x['verdict'] ?? '') !== 'yes') {
            continue;
        }
        $kind = 'vuln_known';
        $key = "$kind:{$x['cve']}:{$x['product']}:{$x['where']}";
        $foundNow[$key] = true;
        $p = ['cve' => $x['cve'], 'product' => $x['product'], 'where' => $x['where'], 'have' => $x['have'], 'fixed' => $x['fixed'],
              'name' => $x['name'], 'ransom' => $x['ransom'], 'added' => $x['added']];
        $at = null;
        foreach ($book as $i => $e) {
            if (($e['key'] ?? '') === $key && !isset($e['p']['resolved'])) {
                $at = $i;           // a line he closed as resolved: one that comes back is news again
            }
        }
        if ($at !== null) {
            if (($book[$at]['p'] ?? []) !== $p) {
                $book[$at]['p'] = $p;
                $book[$at]['last'] = $now;
            }
            $seen[$key] ??= $now;
            continue;
        }
        if (isset($seen[$key])) {
            continue;       // still found, its line gone from the book (pruned after 90 days, into his history): no second one
        }
        $book[] = watchmanEntry($kind, $key, $now, $p);
        $seen[$key] = $now;
        $added[] = $kind;
    }
    if ($fresh) {
        // the lists are fresh: what isn't found any more (fixed, updated, a «maybe» now judged) is closed — noted by himself
        // with when; never one whose record is still to come
        $wait = array_flip($pending);
        foreach ($book as $i => $e) {
            $k = (string) ($e['key'] ?? '');
            if (in_array($e['kind'] ?? '', ['vuln_known', 'vuln_maybe'], true) && watchmanOpen($e) && !isset($foundNow[$k])
                && !isset($wait[(string) ($e['p']['cve'] ?? '')])) {
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'auto';
                $book[$i]['p']['resolved'] = $now;
            }
        }
        foreach (array_keys($seen) as $k) {
            if (!isset($foundNow[$k]) && !isset($wait[explode(':', (string) $k)[1] ?? ''])) {
                unset($seen[$k]);   // gone: should it come back, it is news again
            }
        }
    }
    if (count($seen) > 2000) {
        asort($seen);
        $seen = array_slice($seen, -2000, null, true);
    }
    return $added;
}

/** An older office's open «maybe affected» lines (`vuln_maybe`): closed quietly — noted by himself, never told */
function watchmanVulnMaybeClose(array &$book, int $now): void
{
    foreach ($book as $i => $e) {
        if (($e['kind'] ?? '') === 'vuln_maybe' && watchmanOpen($e)) {
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'auto';
        }
    }
}

/**
 * set.vuln of his rules file (concept 3.1), only in exactly that shape: `maybe` («maybe affected» told too) — kept in the
 * file's shape, read by nothing since «maybe» is never an entry (1.96, 2026-11-23); his setup no longer shows it
 */
function watchmanVulnSettings(string $dir): array
{
    $r = readJson("$dir/rules.json");
    $set = is_array($r) && ($r['v'] ?? null) === 1 && is_array($r['set']['vuln'] ?? null) ? $r['set']['vuln'] : [];
    return ['maybe' => ($set['maybe'] ?? false) === true];
}

/**
 * The advice: Unraid without security updates (posture tip unraid_eol: its version, its status, the newest supported);
 * step 16: software in containers whose release line is past its end (software_eol: «PHP 8.1 (nextcloud)» …)
 */
function watchmanVulnTips(?array $judged, callable $add): void
{
    $old = array_values(array_filter((array) ($judged['software'] ?? []), fn ($x) => is_array($x) && ($x['eol'] ?? null) === true));
    if ($old) {
        $names = array_map(fn ($x) => (WATCH_VULN_PRODUCTS[$x['product']]['label'] ?? $x['product']) . " {$x['cycle']} ({$x['where']})", $old);
        $add('software_eol', ['names' => watchmanNames($names), 'n' => count($old)], implode(',', $names), ['to' => 'docker', 'path' => '/Docker']);
    }
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
            'maybe' => count((array) ($j['unsure'] ?? [])),
            // step 16: the containers' software — past its end, or a newer release of its line; images that name no version
            'software' => array_values(array_filter((array) ($j['software'] ?? []), fn ($x) => is_array($x) && (($x['eol'] ?? null) === true || ($x['latest'] ?? null) !== null))),
            'unknown' => array_values(array_filter((array) ($j['unknown'] ?? []), 'is_string')),
            // checked against what runs here, not affected (1.96): the calm proof he looked — an older state has none
            'not' => array_map(fn ($x) => $x + ['label' => WATCH_VULN_PRODUCTS[$x['product']]['label'] ?? $x['product']],
                array_values(array_filter((array) ($j['not'] ?? []), fn ($x) => is_array($x) && is_string($x['cve'] ?? null) && is_string($x['product'] ?? null)))),
            // what he looked at (2026-11-23): the list's entries, those of products here (by product, named), records still to come
            'kev_total' => is_int($j['kev_total'] ?? null) ? $j['kev_total'] : null, 'kev_here' => (int) ($j['kev_here'] ?? 0),
            'kev_products' => array_map(fn ($id, $n) => ['product' => (string) $id, 'label' => WATCH_VULN_PRODUCTS[$id]['label'] ?? (string) $id, 'n' => (int) $n],
                array_keys(array_filter((array) ($j['kev_products'] ?? []), 'is_int')), array_filter((array) ($j['kev_products'] ?? []), 'is_int')),
            'pending' => is_array($j['pending'] ?? null) ? count($j['pending']) : 0, 'floor' => WATCH_VULN_FLOOR_UNRAID,
            // the sources that haven't answered for days (2026-11-23): a calm line each, never a colour, never told
            'down' => array_values(array_filter((array) ($j['down'] ?? []), fn ($x) => is_array($x) && in_array($x['src'] ?? null, WATCH_VULN_SOURCES, true))),
            'routers' => array_values(array_filter((array) ($j['routers'] ?? []), fn ($x) => is_array($x) && is_string($x['label'] ?? null))),
            // what he looked at (2026-11-25): this server's programs, the containers read and not — an older state has none (null)
            'host' => is_array($j['host'] ?? null) ? array_map(fn ($x) => $x + ['label' => WATCH_VULN_PRODUCTS[$x['product']]['label'] ?? $x['product']],
                array_values(array_filter($j['host'], fn ($x) => is_array($x) && is_string($x['product'] ?? null) && is_string($x['version'] ?? null)))) : null,
            'cts' => array_map(fn ($x) => $x + ['label' => WATCH_VULN_PRODUCTS[$x['product']]['label'] ?? $x['product']],
                array_values(array_filter((array) ($j['cts'] ?? []), fn ($x) => is_array($x) && is_string($x['where'] ?? null) && is_string($x['product'] ?? null)
                    && is_string($x['version'] ?? null)))),
            'ct_total' => is_int($j['ct_total'] ?? null) ? $j['ct_total'] : null, 'ct_read' => (int) ($j['ct_read'] ?? 0),
            'unread' => array_values(array_filter((array) ($j['unread'] ?? []), 'is_string')), 'unread_n' => (int) ($j['unread_n'] ?? 0),
            'vms' => is_int($j['vms'] ?? null) ? $j['vms'] : null,
            // can't be checked for sure (1.96, 2026-11-23): the record names no version he can compare — calm, never an entry
            'unsure' => array_map(fn ($x) => $x + ['label' => WATCH_VULN_PRODUCTS[$x['product']]['label'] ?? $x['product']],
                array_values(array_filter((array) ($j['unsure'] ?? []), fn ($x) => is_array($x) && is_string($x['cve'] ?? null) && is_string($x['product'] ?? null))))];
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

// ===================================================================== step 16: software inside containers

/**
 * docker's format for the images' versions: the image's id, then only the WATCH_VULN_ENV names with their values — docker
 * itself leaves every other variable out, so no other value of an environment ever reaches the office. It never touches
 * .Config.Labels (2026-11-25, Docker 29: an image without labels has no such key there, and the template fails for it):
 * the one label has a query of its own (watchmanVulnImagesLabelFormat())
 */
function watchmanVulnImagesFormat(): string
{
    $names = implode(' ', array_map(fn ($n) => '"' . $n . '"', WATCH_VULN_ENV));
    return '{{.Id}}{{range .Config.Env}}{{$k := index (split . "=") 0}}{{if eq $k ' . $names . '}} {{.}}{{end}}{{end}}';
}

/**
 * docker's format for the one label (the OCI version), asked only of the images whose name matches WATCH_VULN_OCI_IMAGE:
 * the image's id and, if set, «OCI_VERSION=…» — never another label. An image without labels fails it on Docker 29 (no
 * «Labels» key): that image alone has no OCI version (watchmanVulnImagesAsk())
 */
function watchmanVulnImagesLabelFormat(): string
{
    return '{{.Id}}{{with .Config.Labels}}{{with index . "org.opencontainers.image.version"}} OCI_VERSION={{.}}{{end}}{{end}}';
}

/**
 * Asks docker one format for $refs → ref => its line («sha256:… …»). One call for all; docker answers in the order asked,
 * but leaves out the line of an image it can't answer (gone meanwhile, a template that fails on it) and then exits ≠ 0 —
 * so its lines are paired with the refs by position only when every ref has its line and the exit is 0. Else, when it
 * answered some, each ref is asked on its own (one ref, one call: its line is its own, whatever the exit), until
 * WATCH_VULN_IMAGES_ASK seconds have passed; a ref without a line is missing here — «unknown» this round, never another's
 * version. No line at all (Docker silent): nothing, not a call for each.
 * $docker(refs, format, timeout): [exit, stdout] — run() or the tests' stand-in.
 *
 * @param list<string> $refs
 * @return array<string, string>
 */
function watchmanVulnImagesAsk(array $refs, string $format, callable $docker): array
{
    $lines = fn ($out) => array_values(array_filter(explode("\n", (string) $out), fn ($l) => str_starts_with($l, 'sha256:')));
    if (!$refs) {
        return [];
    }
    $deadline = hrtime(true) + WATCH_VULN_IMAGES_ASK * 1e9;
    [$exit, $out] = $docker($refs, $format, WATCH_VULN_IMAGES_ASK);
    $got = $lines($out);
    if (count($refs) === 1 || ($exit === 0 && count($got) === count($refs))) {
        return count($got) === count($refs) ? array_combine($refs, $got) : [];
    }
    $res = [];
    foreach ($got ? $refs : [] as $ref) {
        $left = (int) floor(($deadline - hrtime(true)) / 1e9);
        if ($left < 1) {
            break;
        }
        $one = $lines($docker([$ref], $format, $left)[1] ?? '');
        if (count($one) === 1) {
            $res[$ref] = $one[0];
        }
    }
    return $res;
}

/**
 * docker's answers (ref => line: $env by watchmanVulnImagesFormat(), $labels by watchmanVulnImagesLabelFormat()) → image
 * => [name => value] for each of $images; only the WATCH_VULN_ENV names from $env, only OCI_VERSION from $labels and only
 * for an image named like WATCH_VULN_OCI_IMAGE whose label line names the same image id; only values in a version's
 * shape — anything else on a line is dropped, never kept. An image without an answer: [] («version unknown»).
 *
 * @param array<string, string> $env
 * @param array<string, string> $labels
 * @param list<string> $images
 * @return array<string, array<string, string>>
 */
function watchmanVulnImagesParse(array $env, array $labels, array $images): array
{
    $tokens = function (string $line, array $allowed): array {
        $kept = [];
        foreach (array_slice(explode(' ', $line), 1) as $tok) {
            [$k, $v] = array_pad(explode('=', $tok, 2), 2, '');
            if (in_array($k, $allowed, true) && preg_match('/^[0-9A-Za-z.:+~_-]{1,64}$/D', $v)) {
                $kept[$k] = $v;
            }
        }
        return $kept;
    };
    $id = fn (string $l) => explode(' ', $l, 2)[0];
    $res = [];
    foreach ($images as $img) {
        $line = (string) ($env[$img] ?? '');
        $kept = $tokens($line, WATCH_VULN_ENV);
        $label = (string) ($labels[$img] ?? '');
        if ($label !== '' && preg_match(WATCH_VULN_OCI_IMAGE, $img) && ($line === '' || $id($line) === $id($label))) {
            $kept += $tokens($label, ['OCI_VERSION']);
        }
        $res[$img] = $kept;
    }
    return $res;
}

/**
 * The containers' versions ($containers: the round's look, name => {image}): asked of docker at most once an hour, or when
 * the images changed — one `docker image inspect` for all (the environment), one more for the images named «unifi» (the
 * label), WATCH_VULN_IMAGES_MAX at most; an image docker didn't answer is «version unknown» until the next ask
 * (watchmanVulnImagesAsk()). $cache: the last answer (state.json vuln_images). $inspect: the tests' stand-in for docker
 * (refs, format, timeout) → [exit, stdout].
 *
 * @return array{0: array<string, array<string, string>>, 1: ?array}  container => [name => value], the cache to keep
 */
function watchmanVulnImages(?array $containers, ?array $cache, int $now, ?callable $inspect = null): array
{
    if ($containers === null) {
        return [[], $cache];          // Docker didn't answer: nothing judged in containers this round
    }
    $images = [];
    foreach ($containers as $name => $c) {
        $img = (string) ($c['image'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9._\/:@-]{0,254}$/Di', $img)) {
            $images[(string) $name] = $img;
        }
    }
    $refs = array_slice(array_values(array_unique($images)), 0, WATCH_VULN_IMAGES_MAX);
    sort($refs);
    $byImage = is_array($cache['images'] ?? null) ? $cache['images'] : null;
    $fresh = $byImage !== null && is_int($cache['time'] ?? null) && $now - $cache['time'] < WATCH_VULN_IMAGES_EVERY && $now >= $cache['time']
        && array_keys($byImage) === $refs;
    if (!$fresh) {
        $byImage = [];
        if ($refs) {
            $docker = $inspect ?? fn (array $r, string $format, int $timeout): array
                => run(array_merge(['docker', 'image', 'inspect', '--format', $format], $r), $timeout);
            $env = watchmanVulnImagesAsk($refs, watchmanVulnImagesFormat(), $docker);
            $oci = array_values(array_filter($refs, fn ($r) => preg_match(WATCH_VULN_OCI_IMAGE, $r) === 1));
            $labels = watchmanVulnImagesAsk($oci, watchmanVulnImagesLabelFormat(), $docker);
            $byImage = watchmanVulnImagesParse($env, $labels, $refs);
        }
        ksort($byImage);
        $cache = ['time' => $now, 'images' => $byImage];
    }
    $byContainer = [];
    foreach ($images as $name => $img) {
        if (isset($byImage[$img])) {
            $byContainer[$name] = $byImage[$img];
        }
    }
    return [$byContainer, $cache];
}

/**
 * The containers' software against endoflife.date (feeds/eol/<slug>.json): each with its release line (the longest
 * name that is a prefix of its version: «8.4» for 8.4.28, «17» for PostgreSQL 17.12), whether that line is past its end,
 * and the newest release of the line — and the containers that name none of WATCH_VULN_ENV («version unknown»)
 *
 * @return array{software: list<array>, unknown: list<string>}
 */
function watchmanVulnEol(string $dir, array $inv, array $images): array
{
    $f = watchmanVulnDir($dir);
    $rows = [];
    foreach ($inv as $x) {
        $slug = WATCH_VULN_PRODUCTS[$x['product']]['eol'] ?? null;
        if ($x['where'] === WATCH_VULN_HOST || $x['clean'] === null) {
            continue;
        }
        $line = $slug === null ? null : watchmanVulnCycle((array) (readJson("$f/eol/$slug.json")['cycles'] ?? []), $x['clean']);
        $newer = $line !== null && is_string($line['latest'] ?? null) && watchmanVulnCmp(watchmanVulnParse($x['clean']), watchmanVulnParse($line['latest'])) < 0;
        $rows[] = ['product' => $x['product'], 'where' => $x['where'], 'version' => $x['clean'], 'cycle' => $line['name'] ?? null,
                   'eol' => $line === null ? null : ($line['eol'] ?? null), 'eol_from' => $line['eol_from'] ?? null, 'latest' => $newer ? $line['latest'] : null];
    }
    $unknown = array_keys(array_filter($images, fn ($env) => !$env));
    sort($unknown);
    return ['software' => $rows, 'unknown' => $unknown];
}

/** The release line of $clean among endoflife.date's $cycles: the longest name that is a prefix of it («8.4» for 8.4.28), or null */
function watchmanVulnCycle(array $cycles, string $clean): ?array
{
    $line = null;
    foreach ($cycles as $c) {
        $n = is_array($c) ? (string) ($c['name'] ?? '') : '';
        if ($n !== '' && ($clean === $n || str_starts_with($clean, "$n.")) && ($line === null || strlen($n) > strlen((string) $line['name']))) {
            $line = $c;
        }
    }
    return $line;
}
