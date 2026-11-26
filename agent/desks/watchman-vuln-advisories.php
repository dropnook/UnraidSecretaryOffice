<?php
declare(strict_types=1);

/*
 * The Night Watchman's «Known vulnerabilities», second source (2026-11-26, for 1.96): the projects' own security advisories
 * on GitHub (repository security advisories, api.github.com, public, no key). Required by watchman-vuln.php, which keeps
 * only the hooks: the source in WATCH_VULN_SOURCES/WATCH_VULN_URLS, the fetch in watchmanVulnFetch(), the judge in
 * watchmanVulnJudge(), the tip in watchmanVulnTips(), the page in watchmanVulnPage().
 *
 * Why: CISA's catalogue lists none of the home server's software (Vaultwarden, Paperless-ngx, Immich, Jellyfin, Home
 * Assistant, Nextcloud, AdGuard Home, n8n, Valkey, Redis) — «nothing found» was all he could say there, although these
 * projects publish serious vulnerabilities themselves.
 *
 * Asked only for what is found here: a product whose version his last look read in a container on this server (state.json
 * vuln_look.cts — containers only: Unraid's own PHP comes with Unraid, CISA's list judges it as before) and whose name
 * table entry says where its advisories are (`ghsa` => repo, package names). Nothing of this
 * server goes along but the request itself (the fixed User-Agent, the repository's name): which of those few repositories
 * an office asks says which of those programs run there — the same as the CVE records already say of the exploited ones.
 * The repository is checked by a strict pattern before it goes into a URL; the next page's cursor too (never the Link
 * header's address itself).
 *
 * Kept reduced (feeds/ghsa/<product>.json, `v` 1, root only, writeAtomic()): only «high» and «critical», only id, CVE,
 * severity, the ranges and fixed versions of the product's own packages, a summary cut to WATCH_VULN_DESC, the day it was
 * published — at most WATCH_VULN_GHSA_KEEP, newest first (asked sorted by publication). At most WATCH_VULN_GHSA_PAGES pages
 * of 100: 2026-11-26 Nextcloud's repository (the server and every app) and n8n's held more than 100, everything else less;
 * three pages keep a product at three of GitHub's 60 requests an hour without a key. A list cut (pages left, or more than
 * kept) says so (`cut`) and the lowest fixed version it kept (`floor`): a version below that one is «older than what he
 * kept» — never «nothing found» for it.
 * «Only when newer» (If-Modified-Since with the first page's Last-Modified) only for a list of one page: GitHub still counts
 * such a request without a key (tried 2026-11-26), but it carries nothing. One request without an answer is noted and the
 * next product asked, two in a row, a «slow down» (403, 429, 5xx) or WATCH_VULN_GHSA_TIME end the day's part.
 *
 * Judged with the existing machinery: `vulnerable_version_range` is the maintainers' text — «< 1.132.0», «<=10.8.12»,
 * «>= 1.33.0, <= 1.37.3», «v0.107.72», «≤ 1.36.0» — read only as at most one lower and one upper bound joined by commas
 * (<, <=, >, >=, =, ≤, ≥; a leading «v») or one version alone, and translated into a CVE record's range
 * (watchmanVulnRange(), «after» for an exclusive start) judged by watchmanVulnItem(): «affected» only when the versions
 * compare cleanly; a bound coarser than the version here: unsure (`rough`); a product that may update itself (`self`):
 * an «affected» unsure (`lower`). Everything else is not comparable — unsure, reason `ghsa` with the advisory's own words:
 * branch names, commit ids, «All», «TBD», several upper bounds («<= 9.1.1, <= 9.0.5»: one per release line), «2.13.0-2.20.6»,
 * a lower bound alone without a clean fixed version, a version named alone above the one here. The one exception, the
 * same as a CVE record's: a version at or past every fixed version the advisory names (and above every bound) is not
 * affected — a later release line.
 * Shown calmly: never an entry in the watch book, never a notification (those stay for the actively exploited); «affected»
 * rows make the tip `software_vuln` (advice, «I know, thanks»), the rows themselves fold in the area, the unsure ones join
 * «Can't be checked for sure». An advisory whose CVE CISA's list has for the product is left to that list.
 */

const WATCH_VULN_GHSA_PAGES = 3;           // pages of 100 asked at most per product and day (see above)
const WATCH_VULN_GHSA_KEEP  = 50;          // «high» and «critical» kept at most per product (newest first)
const WATCH_VULN_GHSA_MAX   = 2 << 20;     // bytes of one page at most (2026-11-26: 26 KB – 450 KB)
const WATCH_VULN_GHSA_TIME  = 750;         // no request starts that could end later than this many seconds after the job began
const WATCH_VULN_GHSA_SEV   = ['critical' => 2, 'high' => 1];
const WATCH_VULN_GHSA_REPO  = '/^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/D';
const WATCH_VULN_GHSA_ID    = '/^GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}$/D';
// one page's address: the repository (WATCH_VULN_GHSA_REPO), newest first, and the next page's cursor (from the Link header)
const WATCH_VULN_GHSA_URL   = '#^https://api\.github\.com/repos/[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_][A-Za-z0-9._-]{0,99}/security-advisories'
                            . '\?per_page=100&sort=published&direction=desc(?:&after=[A-Za-z0-9%_=-]{1,200})?$#D';

/** The products whose advisories are on GitHub: id => their `ghsa` (repository validated, package names lower case) */
function watchmanVulnGhsaProducts(): array
{
    $out = [];
    foreach (WATCH_VULN_PRODUCTS as $id => $p) {
        $g = $p['ghsa'] ?? null;
        if (is_array($g) && is_string($g['repo'] ?? null) && preg_match(WATCH_VULN_GHSA_REPO, $g['repo']) && !str_contains($g['repo'], '..')) {
            $out[$id] = $g;
        }
    }
    return $out;
}

/** One page's address for a repository (null: not in its shape), the next page's cursor or none */
function watchmanVulnGhsaUrl(string $repo, ?string $after = null): ?string
{
    if (!preg_match(WATCH_VULN_GHSA_REPO, $repo) || str_contains($repo, '..') || ($after !== null && !preg_match('/^[A-Za-z0-9%_=-]{1,200}$/D', $after))) {
        return null;
    }
    $url = WATCH_VULN_URLS['ghsa'] . $repo . '/security-advisories?per_page=100&sort=published&direction=desc' . ($after !== null ? "&after=$after" : '');
    return preg_match(WATCH_VULN_GHSA_URL, $url) ? $url : null;
}

/** The next page's cursor from a Link header («<…&after=…>; rel="next"»), or null — only the cursor, never the address */
function watchmanVulnGhsaNext(?string $link): ?string
{
    if ($link === null || strlen($link) > 2000) {
        return null;
    }
    foreach (explode(',', $link) as $part) {
        if (preg_match('/^\s*<https:\/\/api\.github\.com\/[^>\s]*[?&]after=([A-Za-z0-9%_=-]{1,200})(?:&[^>\s]*)?>\s*;\s*rel="next"\s*$/D', $part, $m)) {
            return $m[1];
        }
    }
    return null;
}

/**
 * One GET of a page through hostNet() (curl): as watchmanVulnGet() — https only, no redirects, the size cap, the fixed
 * User-Agent — with GitHub's Accept and API version, «only when newer» when $since is given → {code, body, lm, link, err}.
 * $ctx['get'] stands in for it (the tests).
 *
 * @return array{code: int, body: string, lm: ?string, link: ?string, err: ?string}
 */
function watchmanVulnGhsaGet(string $url, ?string $since, array $ctx = []): array
{
    if (isset($ctx['get'])) {
        return ($ctx['get'])($url, $since) + ['link' => null];
    }
    if (!preg_match(WATCH_VULN_GHSA_URL, $url)) {
        return ['code' => 0, 'body' => '', 'lm' => null, 'link' => null, 'err' => 'bad_url'];
    }
    [$exit, $out, $err] = hostNet(watchmanVulnGhsaCurl($url, $since), WATCH_VULN_TIMEOUT + 10);
    if ($exit !== 0) {
        return ['code' => 0, 'body' => '', 'lm' => null, 'link' => null, 'err' => 'curl ' . $exit . ($err !== '' ? ': ' . substr(trim($err), 0, 120) : '')];
    }
    $lines = explode("\n", $out);
    $link = trim((string) array_pop($lines));
    $a = watchmanVulnAnswer(0, implode("\n", $lines));
    return $a + ['link' => $link !== '' ? $link : null];
}

/** curl's arguments for one page (a test reads them: nothing of this server in them) */
function watchmanVulnGhsaCurl(string $url, ?string $since): array
{
    $args = ['curl', '-s', '-S', '-m', (string) WATCH_VULN_TIMEOUT, '--proto', '=https', '--max-redirs', '0', '--max-filesize', (string) WATCH_VULN_GHSA_MAX,
             '--compressed', '-A', WATCH_VULN_UA, '-H', 'Accept: application/vnd.github+json', '-H', 'X-GitHub-Api-Version: 2022-11-28',
             '-w', '\n%{http_code}\n%header{last-modified}\n%header{link}'];
    if ($since !== null && preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $since)) {
        array_push($args, '-H', "If-Modified-Since: $since");
    }
    $args[] = $url;
    return $args;
}

/**
 * One advisory reduced to what the judge needs, for one product ($g: its `ghsa`), or null: not published (withdrawn), not
 * «high» or «critical», no package of the product's (a package name counts when it is one of `package`, lower case, or
 * matches `package_re` (php-src's «ext-session», «sapi-fpm»); an
 * empty one only when `unnamed` — a repository of this one program; an ecosystem in `skip` never: Nextcloud Enterprise's
 * versions are not the container's)
 *
 * @return array{id: string, cve: ?string, sev: string, ranges: list<string>, fixed: list<string>, sum: string, pub: ?string}|null
 */
function watchmanVulnGhsaReduce(mixed $a, array $g): ?array
{
    if (!is_array($a) || !is_string($a['ghsa_id'] ?? null) || !preg_match(WATCH_VULN_GHSA_ID, $a['ghsa_id'])
        || !isset(WATCH_VULN_GHSA_SEV[$a['severity'] ?? '']) || (($a['state'] ?? 'published') !== 'published') || !empty($a['withdrawn_at'])) {
        return null;
    }
    $names = array_map('strtolower', (array) ($g['package'] ?? []));
    $skip = array_map('strtolower', (array) ($g['skip'] ?? []));
    $ranges = $fixed = [];
    foreach (is_array($a['vulnerabilities'] ?? null) ? array_slice($a['vulnerabilities'], 0, 20) : [] as $v) {
        $pkg = is_array($v) && is_array($v['package'] ?? null) ? $v['package'] : [];
        $name = strtolower(trim((string) (is_string($pkg['name'] ?? null) ? $pkg['name'] : '')));
        $eco = strtolower(trim((string) (is_string($pkg['ecosystem'] ?? null) ? $pkg['ecosystem'] : '')));
        $named = in_array($name, $names, true) || (is_string($g['package_re'] ?? null) && preg_match($g['package_re'], $name) === 1);
        if (in_array($eco, $skip, true) || !($name === '' ? !empty($g['unnamed']) : $named)) {
            continue;
        }
        $ranges[] = mb_substr(is_string($v['vulnerable_version_range'] ?? null) ? trim($v['vulnerable_version_range']) : '', 0, 120);
        $fixed[] = mb_substr(is_string($v['patched_versions'] ?? null) ? trim($v['patched_versions']) : '', 0, 120);
    }
    if (!$ranges) {
        return null;
    }
    $cve = is_string($a['cve_id'] ?? null) && preg_match('/^CVE-\d{4}-\d{4,7}$/D', $a['cve_id']) ? $a['cve_id'] : null;
    $pub = substr(is_string($a['published_at'] ?? null) ? $a['published_at'] : '', 0, 10);
    return ['id' => $a['ghsa_id'], 'cve' => $cve, 'sev' => (string) $a['severity'], 'ranges' => $ranges, 'fixed' => $fixed,
            'sum' => mb_substr(is_string($a['summary'] ?? null) ? trim($a['summary']) : '', 0, WATCH_VULN_DESC),
            'pub' => preg_match('/^\d{4}-\d\d-\d\d$/D', $pub) ? $pub : null];
}

/**
 * The day's part of `job watchman-feeds` (called by watchmanVulnFetch() after the NVD part): for each product found here
 * ($products; else the last look's, state.json vuln_look) its repository's advisories, newest first, at most
 * WATCH_VULN_GHSA_PAGES pages; kept reduced in feeds/ghsa/<product>.json; files of products no longer here go. $mark: the
 * source's status (back-off); $left(): may one more request start (the job's time bound)? → [asked, written, error or null]
 *
 * @return array{0: int, 1: int, 2: ?string}
 */
function watchmanVulnGhsaFetch(string $dir, int $now, array $ctx, callable $left, ?array $products = null): array
{
    $f = watchmanVulnDir($dir) . '/ghsa';
    if (!is_dir($f)) {
        @mkdir($f, 0700, true);
    }
    $all = watchmanVulnGhsaProducts();
    $here = $products ?? watchmanVulnGhsaHere(readJson("$dir/state.json"));
    $here = array_values(array_filter(array_unique($here), fn ($id) => isset($all[$id])));
    foreach (glob("$f/*.json") ?: [] as $file) {
        if (!in_array(basename($file, '.json'), $here, true)) {
            @unlink($file);          // no longer found here: never asked, never kept
        }
    }
    sort($here);
    $asked = $written = $miss = 0;
    $err = null;
    foreach ($here as $id) {
        $g = $all[$id];
        $kept = readJson("$f/$id.json");
        $kept = is_array($kept) && ($kept['v'] ?? null) === 1 && ($kept['repo'] ?? null) === $g['repo'] ? $kept : null;
        // «only when newer» only for a list of one page whose packages were read as today (the name table's mark)
        $since = $kept !== null && empty($kept['cut']) && ($kept['for'] ?? null) === watchmanVulnGhsaFor($g) && is_string($kept['lm'] ?? null) ? $kept['lm'] : null;
        $items = [];
        $looked = 0;
        $cursor = null;
        $lm = null;
        $ok = false;
        $more = false;
        for ($page = 0; $page < WATCH_VULN_GHSA_PAGES; $page++) {
            $url = watchmanVulnGhsaUrl($g['repo'], $cursor);
            if ($url === null || !$left()) {
                $more = $page > 0;
                break;
            }
            $asked++;
            $a = watchmanVulnGhsaGet($url, $page === 0 ? $since : null, $ctx);
            if ((int) $a['code'] === 0) {
                $err = (string) ($a['err'] ?? 'no answer');
                ++$miss;
                $more = $page > 0;          // a later page: what came is kept, the list cut
                break;
            }
            $miss = 0;
            if (watchmanVulnSourceDown((int) $a['code'])) {
                return [$asked, $written, 'HTTP ' . $a['code']];       // «slow down» (GitHub's limit without a key) or away: the rest another day
            }
            if ($page === 0 && (int) $a['code'] === 304 && $kept !== null) {
                writeAtomic("$f/$id.json", jsonEncode(['fetched' => $now] + $kept), 0600, 0, 0);
                $written++;
                continue 2;
            }
            $j = (int) $a['code'] === 200 && strlen($a['body']) <= WATCH_VULN_GHSA_MAX ? json_decode($a['body'], true, 32) : null;
            if (!is_array($j) || !array_is_list($j)) {
                $err = $a['err'] ?? 'shape';
                $more = $page > 0;          // not the list (a page, an error): the first page — what was kept stays
                break;
            }
            $ok = true;
            $lm = $page === 0 ? $a['lm'] : $lm;
            $looked += count($j);
            foreach ($j as $adv) {
                $r = watchmanVulnGhsaReduce($adv, $g);
                if ($r !== null) {
                    $items[] = $r;
                }
            }
            $cursor = count($j) >= 100 ? watchmanVulnGhsaNext($a['link']) : null;
            if ($cursor === null) {
                break;
            }
            $more = $page + 1 >= WATCH_VULN_GHSA_PAGES;      // a next page past the cap: the list is cut
        }
        if ($ok) {
            usort($items, fn ($x, $y) => strcmp((string) $y['pub'], (string) $x['pub']));
            $cut = $more || count($items) > WATCH_VULN_GHSA_KEEP;
            $items = array_slice($items, 0, WATCH_VULN_GHSA_KEEP);
            writeAtomic("$f/$id.json", jsonEncode(['v' => 1, 'fetched' => $now, 'repo' => $g['repo'], 'for' => watchmanVulnGhsaFor($g),
                'lm' => $cut ? null : $lm, 'looked' => $looked, 'cut' => $cut] + ($cut ? ['floor' => watchmanVulnGhsaFloor($items)] : []) + ['items' => $items]),
                0600, 0, 0);
            $written++;
        }
        if ($miss >= WATCH_VULN_NVD_MISS) {
            break;                  // twice in a row no answer: away — the rest another day
        }
    }
    return [$asked, $written, $written ? null : $err];
}

/** The name table's packages of a product as a short mark (a list kept for other names is asked whole once) */
function watchmanVulnGhsaFor(array $g): string
{
    return substr(hash('sha256', jsonEncode([$g['repo'] ?? '', $g['package'] ?? [], !empty($g['unnamed']), $g['skip'] ?? []])), 0, 12);
}

/** The products his last look found in a container here (state.json vuln_look.cts) */
function watchmanVulnGhsaHere(?array $st): array
{
    $look = is_array($st['vuln_look'] ?? null) ? $st['vuln_look'] : [];
    $ids = [];
    foreach (is_array($look['cts'] ?? null) ? $look['cts'] : [] as $x) {
        if (is_array($x) && is_string($x['product'] ?? null) && is_string($x['version'] ?? null)) {
            $ids[] = $x['product'];
        }
    }
    return array_values(array_unique($ids));
}

/** The lowest fixed version a cut list kept (below it, older advisories it didn't keep may concern a version), or null */
function watchmanVulnGhsaFloor(array $items): ?string
{
    $low = null;
    foreach ($items as $it) {
        foreach (array_merge(watchmanVulnGhsaFixed((array) ($it['fixed'] ?? [])) ?? [], watchmanVulnGhsaEnds((array) ($it['ranges'] ?? []))) as $v) {
            $p = watchmanVulnParse($v);
            if ($p !== null && ($low === null || watchmanVulnCmp($p, watchmanVulnParse($low)) < 0)) {
                $low = $v;
            }
        }
    }
    return $low;
}

/** The clean upper ends of ranges (for a cut list's floor) */
function watchmanVulnGhsaEnds(array $ranges): array
{
    $out = [];
    foreach ($ranges as $r) {
        $b = is_string($r) ? watchmanVulnGhsaBounds($r) : null;
        if ($b !== null && isset($b['hi'])) {
            $out[] = $b['hi'];
        } elseif ($b !== null && isset($b['eq'])) {
            $out[] = $b['eq'];
        }
    }
    return $out;
}

// ===================================================================== reading the maintainers' words

/**
 * A range as the maintainers write it → its bounds, or null (not comparable): at most one lower (`lo`, `lo_incl`) and one
 * upper (`hi`, `hi_incl`) joined by commas, or one version alone (`eq`); each «<», «<=», «>», «>=», «=», «≤», «≥» and a
 * version (watchmanVulnParse(): 1–5 numbers, «p1»; a leading «v»). Anything else — a branch, a commit, «All», «TBD», an
 * «-rc2», «2.13.0-2.20.6», a second upper bound («<= 9.1.1, <= 9.0.5»: one per release line) — null.
 *
 * @return array{lo?: string, lo_incl?: bool, hi?: string, hi_incl?: bool, eq?: string}|null
 */
function watchmanVulnGhsaBounds(string $range): ?array
{
    $range = str_replace(['≤', '≥'], ['<=', '>='], trim($range));
    if ($range === '' || strlen($range) > 120) {
        return null;
    }
    $b = [];
    foreach (explode(',', $range) as $part) {
        if (!preg_match('/^\s*(<=|>=|<|>|=)?\s*v?(\d{1,9}(?:\.\d{1,9}){0,4}(?:p\d{1,4})?)\s*$/D', $part, $m) || watchmanVulnParse($m[2]) === null) {
            return null;
        }
        $op = $m[1];
        $v = $m[2];
        if ($op === '' || $op === '=') {
            if ($b) {
                return null;            // a version alone, nothing beside it
            }
            $b['eq'] = $v;
            continue;
        }
        if (isset($b['eq'])) {
            return null;
        }
        $key = $op[0] === '<' ? 'hi' : 'lo';
        if (isset($b[$key])) {
            return null;                // two upper (or lower) bounds: one per release line — not one range
        }
        $b[$key] = $v;
        $b[$key . '_incl'] = strlen($op) === 2;
    }
    if (isset($b['lo'], $b['hi']) && watchmanVulnCmp(watchmanVulnParse($b['lo']), watchmanVulnParse($b['hi'])) > 0) {
        return null;                    // «>= 3, < 2»: no range
    }
    return $b;
}

/**
 * The fixed versions as the maintainers write them («1.37.4», «>= v1.132.0», «9.2.0, 9.1.4») → the clean versions, or null
 * when one part isn't one («N/A», «TBD», «6.2.X», «2025.10.2 and newer», nothing)
 *
 * @return list<string>|null
 */
function watchmanVulnGhsaFixed(array $fixed): ?array
{
    $out = [];
    foreach ($fixed as $f) {
        if (!is_string($f) || trim($f) === '') {
            return null;
        }
        foreach (explode(',', $f) as $part) {
            if (!preg_match('/^\s*(?:>=|≥)?\s*v?(\d{1,9}(?:\.\d{1,9}){0,4}(?:p\d{1,4})?)\s*$/D', $part, $m) || watchmanVulnParse($m[1]) === null) {
                return null;
            }
            $out[] = $m[1];
        }
    }
    return $out ?: null;
}

/**
 * One advisory against the version here → [verdict, fixed, why?, bound?]: 'yes' | 'no' | 'maybe'. Its ranges translated
 * into a CVE record's item (defaultStatus unaffected: an advisory names what is affected) and judged by watchmanVulnItem();
 * what can't be translated: maybe, why `ghsa`, bound the advisory's own words — unless the version is at or past every
 * fixed version and above every bound it names (a later release line: no). «Fixed from» the advisory's one clean fixed
 * version, else the judge's.
 *
 * @return array{0: string, 1: ?string, 2?: string, 3?: string}
 */
function watchmanVulnGhsaVerdict(array $it, string $have): array
{
    $h = watchmanVulnParse($have);
    $ranges = array_values(array_filter((array) ($it['ranges'] ?? []), 'is_string'));
    $words = implode(' · ', array_map(fn ($r) => $r === '' ? '–' : $r, $ranges));
    if ($h === null || !$ranges) {
        return ['maybe', null, 'ghsa', $words];
    }
    $fixed = watchmanVulnGhsaFixed((array) ($it['fixed'] ?? []));
    $oneFix = $fixed !== null && count(array_unique($fixed)) === 1 ? $fixed[0] : null;
    $bounds = array_map('watchmanVulnGhsaBounds', $ranges);
    // past everything it names: every fixed version (all clean) and every bound — whatever the ranges' words
    $past = function () use ($fixed, $bounds, $ranges, $h): ?array {
        $all = $fixed ?? [];
        foreach ($bounds as $b) {
            if ($b !== null && isset($b['lo']) && !isset($b['hi'])) {
                return null;            // open above: nothing lies past it
            }
            foreach ($b === null ? [] : array_filter([$b['hi'] ?? null, $b['eq'] ?? null]) as $v) {
                $all[] = $v;
            }
        }
        if ($fixed === null || !$all) {
            return null;
        }
        $top = null;
        foreach ($all as $v) {
            $p = watchmanVulnParse($v);
            if ($p === null || watchmanVulnCmp($h, $p) < 0) {
                return null;
            }
            $top = $top === null || watchmanVulnCmp($p, watchmanVulnParse($top)) > 0 ? $v : $top;
        }
        // an inclusive bound equal to the version here is no «past»
        foreach ($bounds as $b) {
            if ($b !== null && ((isset($b['hi']) && !empty($b['hi_incl']) && watchmanVulnCmp($h, watchmanVulnParse($b['hi'])) === 0)
                || (isset($b['eq']) && watchmanVulnCmp($h, watchmanVulnParse($b['eq'])) === 0))) {
                return null;
            }
        }
        return ['no', count($ranges) === 1 ? $fixed[0] : null];
    };
    $loose = count(array_filter($bounds, fn ($b) => $b !== null && !isset($b['lo']) && !isset($b['eq']))) > 1;     // several lines, each «< X»
    if (in_array(null, $bounds, true) || $loose) {
        return $past() ?? ['maybe', null, 'ghsa', $words];
    }
    $versions = [];
    foreach ($bounds as $b) {
        if (isset($b['eq'])) {
            // a version named alone: the one it was found in — here that one: affected; at or past its fix (or above it
            // with none named): not; below it the advisory doesn't say
            $e = watchmanVulnParse($b['eq']);
            if (count($bounds) === 1 && watchmanVulnCmp($h, $e) < 0) {
                return ['maybe', null, 'ghsa', $words];
            }
            $versions[] = ['version' => $b['eq'], 'status' => 'affected', 'versionType' => 'custom'];
            continue;
        }
        $hi = $b['hi'] ?? null;
        $incl = !empty($b['hi_incl']);
        if ($hi === null) {
            // a lower bound alone: affected from there until its one clean fix — no fix named, nothing to compare
            if ($oneFix === null || watchmanVulnCmp(watchmanVulnParse($oneFix), watchmanVulnParse($b['lo'])) <= 0) {
                return $past() ?? ['maybe', null, 'ghsa', $words];
            }
            [$hi, $incl] = [$oneFix, false];
        }
        $versions[] = ['version' => $b['lo'] ?? '0', 'status' => 'affected', 'versionType' => 'custom', ($incl ? 'lessThanOrEqual' : 'lessThan') => $hi]
            + (isset($b['lo']) && empty($b['lo_incl']) ? ['after' => true] : []);
    }
    $v = watchmanVulnItem(['defaultStatus' => 'unaffected', 'versions' => $versions], $have);
    if ($v[0] === 'maybe') {
        return isset($v[2]) ? [$v[0], $v[1], (string) $v[2], (string) $v[3]] : ['maybe', null, 'ghsa', $words];
    }
    if ($v[0] === 'yes' && $oneFix !== null && watchmanVulnCmp($h, (array) watchmanVulnParse($oneFix)) >= 0) {
        return ['maybe', null, 'ghsa', $words];      // the range holds it, yet it is at or past the fix they name: their words disagree
    }
    return [$v[0], $oneFix ?? $v[1]];
}

// ===================================================================== judging what is here

/**
 * The products found here against their kept advisories ($inv: watchmanVulnInventory(); $kev: product => the CVEs CISA's
 * list has for it — those are left to it) → rows (`ghsa`: affected and unsure, critical first, at most
 * WATCH_VULN_LOOK_MAX) and per product and place what he read (`ghsa_here`: kept, looked at, cut, `older` — the version
 * below what a cut list kept — or `stale`: not checked right now)
 *
 * @return array{ghsa: list<array>, ghsa_here: list<array>}
 */
function watchmanVulnGhsaJudge(string $dir, array $inv, int $now, array $kev = []): array
{
    $f = watchmanVulnDir($dir) . '/ghsa';
    $all = watchmanVulnGhsaProducts();
    $rows = $here = $files = $seen = [];
    foreach ($inv as $x) {
        $id = $x['product'];
        // containers only: Unraid's own PHP is updated with Unraid (CISA's list and the CVE records judge it as before)
        if (!isset($all[$id]) || $x['where'] === WATCH_VULN_HOST || isset($seen["{$x['where']}|$id"])) {
            continue;
        }
        $seen["{$x['where']}|$id"] = true;
        $files[$id] ??= readJson("$f/$id.json");
        $file = $files[$id];
        $fresh = is_array($file) && ($file['v'] ?? null) === 1 && is_int($file['fetched'] ?? null) && $now - $file['fetched'] <= WATCH_VULN_OLD
            && $file['fetched'] <= $now + 300 && is_array($file['items'] ?? null);
        $shown = watchmanVulnShown($id, $x['where'], $x['version']);
        $line = ['product' => $id, 'where' => $x['where'], 'have' => $shown];
        if (!$fresh || $x['clean'] === null) {
            $here[] = $line + ['stale' => !$fresh, 'kept' => 0, 'looked' => 0] + ($x['clean'] === null && $fresh ? ['unread' => true] : []);
            continue;
        }
        $floor = is_string($file['floor'] ?? null) ? watchmanVulnParse($file['floor']) : null;
        $older = !empty($file['cut']) && ($floor === null || watchmanVulnCmp(watchmanVulnParse($x['clean']), $floor) < 0);
        $here[] = $line + ['kept' => count($file['items']), 'looked' => (int) ($file['looked'] ?? 0), 'cut' => !empty($file['cut'])]
            + ($older ? ['older' => true, 'floor' => is_string($file['floor'] ?? null) ? $file['floor'] : null] : []);
        foreach ($file['items'] as $it) {
            if (!is_array($it) || !is_string($it['id'] ?? null) || !preg_match(WATCH_VULN_GHSA_ID, $it['id']) || !isset(WATCH_VULN_GHSA_SEV[$it['sev'] ?? ''])) {
                continue;
            }
            $cve = is_string($it['cve'] ?? null) && preg_match('/^CVE-\d{4}-\d{4,7}$/D', $it['cve']) ? $it['cve'] : null;
            if ($cve !== null && in_array($cve, $kev[$id] ?? [], true)) {
                continue;           // CISA's list has it: the exploited one wins, never twice
            }
            $v = watchmanVulnGhsaVerdict($it, $x['clean']);
            if ($v[0] === 'yes' && !empty(WATCH_VULN_PRODUCTS[$id]['self'])) {
                $v = ['maybe', $v[1], 'lower', $shown];
            }
            if ($v[0] === 'no') {
                continue;
            }
            $rows[] = ['id' => $it['id'], 'cve' => $cve, 'product' => $id, 'where' => $x['where'], 'have' => $shown, 'sev' => $it['sev'], 'verdict' => $v[0],
                       'fixed' => $v[1], 'name' => mb_strcut((string) ($it['sum'] ?? ''), 0, 120, 'UTF-8'), 'pub' => is_string($it['pub'] ?? null) ? $it['pub'] : null]
                + (isset($v[2]) ? ['why' => $v[2], 'bound' => mb_substr((string) $v[3], 0, 120)] : []);
        }
    }
    usort($rows, fn ($a, $b) => ($b['verdict'] === 'yes') <=> ($a['verdict'] === 'yes') ?: WATCH_VULN_GHSA_SEV[$b['sev']] <=> WATCH_VULN_GHSA_SEV[$a['sev']]
        ?: strcmp((string) $b['pub'], (string) $a['pub']) ?: strcmp($a['id'], $b['id']));
    return ['ghsa' => array_slice($rows, 0, WATCH_VULN_LOOK_MAX), 'ghsa_here' => array_slice($here, 0, WATCH_VULN_LOOK_MAX)];
}

/**
 * The tip software_vuln: per container and product one line — its version, how many known serious vulnerabilities
 * (affected rows), from which version all are closed (the highest fixed version, when every row names one)
 */
function watchmanVulnGhsaTip(?array $judged, callable $add): void
{
    $by = [];
    foreach (is_array($judged['ghsa'] ?? null) ? $judged['ghsa'] : [] as $x) {
        if (!is_array($x) || ($x['verdict'] ?? null) !== 'yes' || !isset(WATCH_VULN_PRODUCTS[$x['product'] ?? ''])) {
            continue;
        }
        $k = "{$x['where']}|{$x['product']}";
        $by[$k] ??= ['product' => (string) $x['product'], 'label' => WATCH_VULN_PRODUCTS[$x['product']]['label'], 'where' => (string) $x['where'],
                     'have' => (string) $x['have'], 'n' => 0, 'fixed' => null, 'open' => false, 'ids' => []];
        $by[$k]['n']++;
        $by[$k]['ids'][] = (string) $x['id'];
        $fp = is_string($x['fixed'] ?? null) ? watchmanVulnParse($x['fixed']) : null;
        if ($fp === null) {
            $by[$k]['open'] = true;
        } elseif ($by[$k]['fixed'] === null || watchmanVulnCmp($fp, watchmanVulnParse($by[$k]['fixed'])) > 0) {
            $by[$k]['fixed'] = $x['fixed'];
        }
    }
    if (!$by) {
        return;
    }
    ksort($by);
    $items = array_map(fn ($x) => ['product' => $x['label'], 'where' => $x['where'], 'have' => $x['have'], 'n' => $x['n'],
                                   'fixed' => $x['open'] ? null : $x['fixed']], array_values($by));
    $names = array_map(fn ($x) => "{$x['product']} {$x['have']} ({$x['where']})", $items);
    $about = implode(',', array_map(fn ($x) => "{$x['where']}|{$x['product']}|{$x['have']}|" . implode('+', $x['ids']), array_values($by)));
    $add('software_vuln', ['names' => watchmanNames($names), 'n' => count($items), 'items' => array_slice($items, 0, 20)], $about,
        ['to' => 'docker', 'path' => '/Docker']);
}

/** The page's rows (watchmanVulnPage()): only in their shape, each with its product's name — an older state has none */
function watchmanVulnGhsaPage(array $j): array
{
    $ok = fn ($x) => is_array($x) && is_string($x['product'] ?? null) && isset(WATCH_VULN_PRODUCTS[$x['product']]) && is_string($x['where'] ?? null);
    $label = fn ($x) => $x + ['label' => WATCH_VULN_PRODUCTS[$x['product']]['label']];
    return ['ghsa' => array_map($label, array_values(array_filter((array) ($j['ghsa'] ?? []), fn ($x) => $ok($x) && is_string($x['id'] ?? null)
                && preg_match(WATCH_VULN_GHSA_ID, $x['id']) && in_array($x['verdict'] ?? null, ['yes', 'maybe'], true)))),
            'ghsa_here' => array_map($label, array_values(array_filter((array) ($j['ghsa_here'] ?? []), $ok)))];
}
