<?php
declare(strict_types=1);

/*
 * «Report a problem or a wish…» — the office's reports to its makers (briefs/uso-feedback-concept.md).
 *
 * The page's dialog (core.js Office.reportDialog()) asks the agent twice:
 *   office.report_preview {kind, desk, title, text, name?, lang, browser, error?}
 *       → everything that would be sent, part by part (the user's words, the versions, Unraid's version, the
 *         languages, the team, the desk's last error, the messenger's log lines of that desk — scrubbed), and a token.
 *         The preview is kept in RAM (RUN_DIR/report/<token>.json, 0600) for REPORT_TOKEN_TTL. NO network.
 *   office.report_send {token, kind, desk, title, text, name?, parts}
 *       → exactly the kept preview (the words must be the previewed ones), minus the parts not ticked, POSTed to the
 *         makers' inbox (OFFICE_FEEDBACK_URL, src/place.php — a Cloudflare Worker that opens an issue in a private
 *         GitHub repository) with curl through hostNet(): https only (http only when the plugin's .cfg says
 *         FEEDBACK_URL="http://…" — tests), 20 s, the body from a 0600 file in RAM (never on the command line). One
 *         token sends once: claimed by a rename, a second send of it gets the first one's answer, never a second POST.
 *   office.reports {}
 *       → «Your reports» (data/office/reports.json, 0600) and how many are left for now — each with where it stands
 *         (received | seen | done) and the public issue the makers opened for it, if any. For that it does ONE GET
 *         <inbox>/api/status?ids=<n>,… for the newest REPORT_STATUS_MAX reports not looked at for REPORT_STATUS_EVERY
 *         (the Worker checks they are this office's by the report ID, sent as header X-Office — never the GUID):
 *         curl through hostNet(), https only, REPORT_STATUS_TIMEOUT, address and header in a 0600 config file in RAM.
 *         Only the inbox's numbers and the report ID go along — nothing the user wrote. A failure is silent (the rows
 *         keep what was known). The inbox's numbers stay in reports.json for this; the page never gets them.
 * Nothing user-written leaves the server without the user's click on «Send». What goes is shown before, in full.
 *
 * The caps: REPORT_CAP_DAY reports in 24 hours per office (Benj, 2026-10-09; up to 1.47 it was 2 in 7 days), checked
 * here first (reports.json) — the Worker is binding and counts by the report ID: sha256("uso-report:" + GUID), 64 hex,
 * kept in data/office/report-id (0600, made once) — never the tip page's server ID (that one looks up a supporter's name). The Worker answers with its own keys; the
 * office shows only its own words (errors.report_*), never a text the Worker sends.
 *
 * The scrubber (reportScrub()) runs over every log line and the error's params — never over the user's own words
 * (they are theirs, and shown as typed; the preview hints at addresses, mails and tokens in them): control characters
 * out, known secrets (GUIDs, the csrf token, server and report IDs, Emby's API keys, partner and supporter keys) → •••,
 * mails → ‹mail›, paths by their structure (logsNormalizePaths() finds them; /mnt/user/<share>/… keeps Unraid's own
 * shares, every other share → ‹share-N›, every pool → ‹pool-N›, the rest → …), names (the server, partners, users,
 * shares, pools) → placeholders, then watchmanScrub() (URLs, secret settings, long tokens) and watchnetScrub() (every
 * address → …, MACs halved), UUIDs. The placeholders' meaning («‹share-1› = Media») is shown in the preview, never sent.
 *
 * Pictures (dropnook/UnraidSecretaryOffice#6): up to REPORT_IMG_COUNT_MAX screenshots. The page hands each one to the
 * web side alone (office.report_image, src/api.php apiReportImageStash(): its magic bytes, ≤ 2 MB, a 0600 file in the
 * RAM inbox officeInboxDir() — never the mailbox, which lies on the pool); the preview names them (`images`: the refs),
 * takes them out of the inbox at once and has each one drawn anew by src/reportimage.php in a process of its own (no
 * metadata, no bytes behind the picture, the longest side ≤ 2560, ≤ 1.5 MB each and ≤ 4 MB together) — kept beside the
 * preview in RAM (RUN_DIR/report/<token>.<n>.img, 0600) until sent or REPORT_TOKEN_TTL is over. Each has its own tick
 * (image1 …); the scrubber can't blank a picture, so a send with one needs `images_checked: true` (the page's required
 * tick «I've looked at the pictures»). They go base64 in the body (`images: [{type, data}]`, `parts` names `images`).
 */

require_once dirname(__DIR__, 2) . '/src/reportimage.php';      // shared with the web side (pure functions)

const REPORT_CAP_DAY     = 25;                 // reports per office in 24 hours (the Worker's CAP_OFFICE_DAY mirrored)
const REPORT_DAY         = 86400;
const REPORT_TOKEN_TTL   = 600;                // a preview may be sent within 10 minutes
const REPORT_CLOSED_FOR  = 86400;              // the Worker said «closed»: not asked again for a day
const REPORT_KINDS       = ['bug', 'wish', 'question'];
const REPORT_PARTS       = ['versions', 'unraid', 'language', 'team', 'error', 'log'];     // what the user may untick
const REPORT_IMAGE_PARTS = ['image1', 'image2', 'image3'];                                // … and each picture (REPORT_IMG_COUNT_MAX)
const REPORT_TITLE_MAX   = 100;                // characters
const REPORT_TEXT_MAX    = 4096;               // bytes (UTF-8)
const REPORT_TEXT_MIN    = 10;                 // characters
const REPORT_NAME_MAX    = 40;                 // characters: a forum name — the page no longer asks for one (1.45), the mailbox still takes it
const REPORT_LOG_LINES   = 40;                 // the desk's lines …
const REPORT_LOG_OWN     = 10;                 // … and the agent's own (its start, errors, PHP warnings)
const REPORT_LOG_MAX     = 16384;              // bytes of the log part
const REPORT_LINE_MAX    = 400;                // characters of a line
const REPORT_LOG_READ    = 2 * 1024 * 1024;    // at most this much of agent.log (and .1) is read, from the end
const REPORT_BODY_MAX    = 24576;              // the Worker's limit for a request (without its pictures)
const REPORT_IMG_RAM_MAX = 16 * 1024 * 1024;   // all previews' pictures in RAM together: the oldest go first
const REPORT_SEND_TIMEOUT = 20;                // seconds for a send …
const REPORT_SEND_TIMEOUT_IMAGES = 90;         // … and for one with pictures (≤ ~5.5 MB up)
const REPORT_KEEP        = 50;                 // «Your reports»: the newest kept
const REPORT_STATUS_MAX     = 10;          // «Your reports»: where the newest this many stand is asked (the Worker's batch) …
const REPORT_STATUS_EVERY   = 3600;        // … each at most once an hour …
const REPORT_STATUS_TIMEOUT = 8;           // … in one GET of at most this many seconds
const REPORT_STATUSES       = ['received', 'seen', 'done'];
// the public issue a report became — only one of the office's public repository, never anything else the Worker says
const REPORT_PUBLIC_RE      = '#^https://github\.com/dropnook/UnraidSecretaryOffice/issues/([0-9]{1,9})$#D';
const REPORT_LANGS       = ['en', 'de', 'it', 'fr', 'es'];
// Unraid's own shares and the office's: they stay in paths; every other share is the user's and becomes ‹share-N›
const REPORT_SHARES_KEPT = ['appdata', 'system', 'domains', 'isos', 'UnraidSecretaryOffice'];
const REPORT_HOSTS_KEPT  = ['github.com', 'api.github.com'];

/*
 * Which lines of agent.log are a desk's: its labels (the start of the text after the time) and «<desk id>:» (the
 * agent's own lines about a desk: its start, tick or checks failing). Desks tag their lines by habit; tests/run.php
 * testReport keeps this complete — every logLine() in agent/desks/<id>*.php (and the libraries that write for a desk)
 * starts with one of that desk's labels. Ms. Snapshotini's Deleted:/Released:/Renamed: stay as they are (the night
 * watchman reads them, their shape is his interface).
 */
const REPORT_LOG_LABELS = [
    'office'    => ['Agent ', 'Error: ', 'PHP: ', 'Office: ', 'Metrics: ', 'Mailbox: ', 'The ', 'No doorbell', 'Created the data folder',
                    'New agent code', 'Could not ', 'agent.json '],
    'caretaker' => ['Caretaker: ', 'Partner offices: ', 'Menu entry: '],
    'snapshot'  => ['Ms. Snapshotini: ', 'Snapshot schedules: ', 'Snapshot scan', 'Deleted: ', 'Released: ', 'Renamed: ', 'Held: '],
    'backup'    => ['Backup: ', 'Mr. Backup: '],
    'restore'   => ['Mr. Restori'],
    'emby'      => ['Jack Emby: '],
    'logs'      => ['Ms. Protocolli: '],
    'cleanup'   => ['Dustdevil', 'cleanup: ', 'Measured '],
    'watchman'  => ['Night watchman: '],
    'advisor'   => ['Consultant: '],
];
// the agent's own lines that go with every desk's: its starts and stops, errors, PHP warnings
const REPORT_LOG_OWN_LABELS = ['Agent started', 'Agent stopped', 'Error: ', 'PHP: '];

/** The office's actions the agent answers itself (agent.php handle(): «office.<action>») */
function officeAgentActions(): array
{
    return [
        'report_preview' => fn (array $r): array => reportPreview($r),
        'report_send'    => fn (array $r): array => reportSend($r),
        'reports'        => fn (array $r): array => reportsAnswer(),
        'supporter_claim' => fn (array $r): array => supporterClaim($r),     // lib/supporter.php: the key a tip made
    ];
}

function reportsFile(array $ctx = []): string
{
    return $ctx['reports'] ?? OFFICE_PRIVATE . '/reports.json';
}

function reportRunDir(array $ctx = []): string
{
    return $ctx['run_dir'] ?? RUN_DIR . '/report';
}

// ===================================================================== the report ID

/**
 * This office's report ID: sha256("uso-report:" + upper(GUID)) — regGUID, else flashGUID; neither: random — kept in
 * data/office/report-id (0600) from the first time on, so it stays when the stick changes. Never the tip page's server
 * ID (sha256 of "uso-supporter:" + GUID): that one is a lookup key for a supporter's name.
 */
function reportId(array $ctx = []): string
{
    $file = $ctx['report_id_file'] ?? OFFICE_PRIVATE . '/report-id';
    $kept = trim((string) @file_get_contents($file, false, null, 0, 200));
    if (!is_link($file) && preg_match('/^[0-9a-f]{64}$/D', $kept)) {
        return $kept;
    }
    $guid = reportGuid($ctx['var_ini'] ?? '/var/local/emhttp/var.ini');
    $id = $guid !== null ? hash('sha256', 'uso-report:' . strtoupper($guid)) : bin2hex(random_bytes(32));
    try {
        if (is_dir(dirname($file)) && !is_link(dirname($file))) {
            writeAtomic($file, "$id\n", 0600, WEB_UID, WEB_UID);
        }
    } catch (Throwable) {
        // not kept: the same GUID makes the same ID next time
    }
    return $id;
}

/** The GUID the licence is registered to, else the flash's; null when var.ini names none */
function reportGuid(string $varIni): ?string
{
    $var = @parse_ini_file($varIni, false, INI_SCANNER_RAW) ?: [];
    foreach (['regGUID', 'flashGUID'] as $k) {
        $guid = trim((string) ($var[$k] ?? ''), " \"'");
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,62}[A-Za-z0-9]$/D', $guid)) {
            return $guid;
        }
    }
    return null;
}

// ===================================================================== what the scrubber knows

/**
 * What must not leave the server, collected once per preview from files the office reads anyway (all on the flash,
 * in RAM or in the data folder — no disk woken): secrets (→ •••), the server's names, partners, users, shares and pools
 * (→ placeholders, numbered by their sorted order, so the same in every line). $ctx: the tests' own files.
 *
 * @return array{secrets: list<string>, names: array<string, array{0: string, 1: bool}>, shares: array<string, string>, pools: array<string, string>}
 */
function reportKnow(array $ctx = []): array
{
    $var = @parse_ini_file($ctx['var_ini'] ?? '/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
    $secrets = [];
    foreach (['regGUID', 'flashGUID', 'tpmGUID', 'csrf_token'] as $k) {
        $secrets[] = trim((string) ($var[$k] ?? ''), " \"'");
    }
    $guid = reportGuid($ctx['var_ini'] ?? '/var/local/emhttp/var.ini');
    if ($guid !== null) {
        $sid = strtoupper(substr(hash('sha256', 'uso-supporter:' . strtoupper($guid)), 0, 16));
        $secrets[] = $sid;
        $secrets[] = implode('-', str_split($sid, 4));       // the tip page's server ID, as the page shows it
    }
    $secrets[] = reportId($ctx);
    $supporter = readJson($ctx['supporter'] ?? OFFICE_PRIVATE . '/supporter.json') ?? [];
    $secrets[] = is_string($supporter['key'] ?? null) ? $supporter['key'] : '';      // a file from before 1.48
    foreach ((array) ($supporter['keys'] ?? []) as $k) {
        $secrets[] = is_array($k) && is_string($k['key'] ?? null) ? $k['key'] : '';
    }
    foreach ((array) ($supporter['codes'] ?? []) as $c) {                              // the claim codes (one-time)
        $secrets[] = is_array($c) && is_string($c['code'] ?? null) ? $c['code'] : '';
    }
    foreach ((array) ((readJson($ctx['emby_settings'] ?? DATA_DIR . '/embycache/embycache_settings.json') ?? [])['instances'] ?? []) as $i) {
        $secrets[] = is_array($i) && is_string($i['api_key'] ?? null) ? $i['api_key'] : '';
    }

    // names: [placeholder, case-insensitive?]
    $names = [];
    $host = $ctx['hostname'] ?? hostname();
    $ident = readCfg($ctx['ident'] ?? '/boot/config/ident.cfg');
    foreach ([$host, $ident['NAME'] ?? '', strtok($host, '.') ?: ''] as $n) {
        if (is_string($n) && mb_strlen($n) >= 2) {
            $names[$n] = ['‹server›', true];
        }
    }
    $partners = [];
    $partnerDir = $ctx['partner_dir'] ?? DATA_DIR . '/partner';
    foreach (['pairs.json' => 'pairs', 'tickets.json' => 'tickets', 'ticket-pairs.json' => 'pairs'] as $file => $list) {
        foreach ((array) ((readJson("$partnerDir/$file") ?? [])[$list] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            foreach (['name', 'address'] as $k) {
                if (is_string($p[$k] ?? null) && $p[$k] !== '' && !filter_var($p[$k], FILTER_VALIDATE_IP)) {
                    $partners[$p[$k]] = true;
                }
            }
            foreach (['my_key', 'their_key', 'key', 'pub_key', 'host_keys'] as $k) {
                foreach ((array) ($p[$k] ?? []) as $v) {
                    if (is_string($v)) {
                        $secrets[] = $v;
                        foreach (preg_split('/\s+/', $v) ?: [] as $word) {      // an ssh key's base64 part on its own
                            $secrets[] = strlen($word) >= 20 ? $word : '';
                        }
                    }
                }
            }
        }
    }
    $partnerNames = array_keys($partners);
    sort($partnerNames, SORT_STRING);
    foreach ($partnerNames as $i => $n) {
        $names[$n] ??= ['‹partner-' . ($i + 1) . '›', true];
    }
    $users = [];
    foreach (@file($ctx['passwd'] ?? '/boot/config/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(':', $line);
        if (count($f) >= 3 && ctype_digit($f[2]) && (int) $f[2] >= 1000 && $f[0] !== 'nobody' && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{1,31}$/D', $f[0])) {
            $users[] = $f[0];
        }
    }
    sort($users, SORT_STRING);
    foreach ($users as $i => $n) {
        $names[$n] ??= ['‹user-' . ($i + 1) . '›', false];
    }

    $shares = [];
    foreach (glob(($ctx['shares_dir'] ?? '/boot/config/shares') . '/*.cfg') ?: [] as $f) {
        $n = basename($f, '.cfg');
        if ($n !== '' && !in_array($n, REPORT_SHARES_KEPT, true)) {
            $shares[] = $n;
        }
    }
    sort($shares, SORT_STRING);
    $shareMap = [];
    foreach ($shares as $i => $n) {
        $shareMap[$n] = '‹share-' . ($i + 1) . '›';
    }
    $pools = [];
    foreach (glob(($ctx['pools_dir'] ?? '/boot/config/pools') . '/*.cfg') ?: [] as $f) {
        $pools[basename($f, '.cfg')] = true;
    }
    foreach ($ctx['mounts'] ?? array_column(mountTable(), 'mount') as $m) {
        if (preg_match('#^/mnt/([^/]+)$#D', (string) $m, $x) && !preg_match('/^(disk\d+|user0?|disks|remotes|addons|rootshare)$/D', $x[1])) {
            $pools[$x[1]] = true;
        }
    }
    $pools = array_keys(array_filter($pools, fn ($ok, $n) => $n !== '', ARRAY_FILTER_USE_BOTH));
    sort($pools, SORT_STRING);
    $poolMap = [];
    foreach ($pools as $i => $n) {
        $poolMap[(string) $n] = '‹pool-' . ($i + 1) . '›';
    }
    foreach ($shareMap + $poolMap as $n => $as) {
        if (mb_strlen((string) $n) >= 2) {
            $names[(string) $n] ??= [$as, false];
        }
    }
    $secrets = array_values(array_unique(array_filter($secrets, fn ($s) => is_string($s) && strlen($s) >= 6)));
    usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));       // a longer secret before a part of it
    return ['secrets' => $secrets, 'names' => $names, 'shares' => $shareMap, 'pools' => $poolMap];
}

// ===================================================================== the scrubber

/**
 * A line (or a value) as a report may carry it — see the head of this file. Idempotent: scrubbing twice is once.
 * $seen collects what was hidden: placeholder → the name (for the preview only, never sent).
 *
 * @param array<string, string> $seen
 */
function reportScrub(string $s, array $know, ?array &$seen = null): string
{
    $seen ??= [];
    $s = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $s);       // first: a secret split by one would slip through
    if (preg_match('//u', $s) !== 1) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');                    // invalid bytes become «?»
    }
    $s = advisorScrub(reportNoUnicodeControls($s), $know['secrets']);
    $s = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '‹mail›', $s);
    // paths: found as Ms. Protocolli finds them, each replaced by what its structure may keep — through a stand-in
    // (\x01n\x02, no slash) so the finder's later rules never meet a path it has already put right
    $paths = [];
    $s = logsNormalizePaths($s, function (string $p, string $kind) use (&$paths, $know, &$seen): string {
        $paths[] = reportPath($p, $kind, $know, $seen);
        return "\x01" . (count($paths) - 1) . "\x02";
    });
    $s = (string) preg_replace_callback('/\x01(\d+)\x02/', fn (array $m): string => $paths[(int) $m[1]] ?? '', $s);
    $s = reportNames($s, $know, $seen);
    $s = (string) preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $s);
    $s = watchmanScrub($s);
    $s = reportAddresses(watchnetScrub($s, []));
    return mb_strimwidth($s, 0, REPORT_LINE_MAX, '…', 'UTF-8');
}

/**
 * Every IP address → … — also where watchnetScrub() lets one pass: at a sentence's end («from 10.0.0.5.»), with a port, and
 * IPv6 written short («fd00::1», «[fe80::1]»); only what is an address (a time like 17:02:11 is none)
 */
function reportAddresses(string $s): string
{
    $s = (string) preg_replace_callback('/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?!\w|\.\d)/', fn (array $m): string => filter_var($m[0], FILTER_VALIDATE_IP) ? '…' : $m[0], $s);
    return (string) preg_replace_callback('/(?<![\w:.])[0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7}(?:%\w+)?(?![\w:])/',
        fn (array $m): string => filter_var(preg_replace('/%\w+$/', '', $m[0]), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '…' : $m[0], $s);
}

/** Is it a placeholder already (‹share-1›, ‹pool›, …)? */
function reportPlaceholder(string $s): bool
{
    return (bool) preg_match('/^‹[a-z]+(?:-\d+)?›$/uD', $s) || $s === '…';
}

/**
 * A path as the report may name it, by its structure: /mnt/<user|user0|diskN|pool>/<share>/… keeps Unraid's own shares
 * (REPORT_SHARES_KEPT), a pool → ‹pool-N›, another share → ‹share-N›, what lies below → …; /mnt/addons keeps only the
 * office's; the plugins' folders keep the plugin's name; system folders (/usr, /etc, /proc …) stay; a dataset
 * pool/share@snapshot keeps the office's own snapshot names; a URL its scheme and an allowed host; anything else <path>.
 *
 * @param array<string, string> $seen
 */
function reportPath(string $p, string $kind, array $know, array &$seen): string
{
    if ($kind === 'samba') {
        return '<path>';
    }
    if (preg_match('~^[a-z][a-z0-9+.-]{1,15}://~i', $p)) {
        return reportUrl($p);
    }
    $share = function (string $n) use ($know, &$seen): string {
        if (in_array($n, REPORT_SHARES_KEPT, true) || reportPlaceholder($n)) {
            return $n;
        }
        if (isset($know['shares'][$n])) {
            $seen[$know['shares'][$n]] = $n;
            return $know['shares'][$n];
        }
        return '…';
    };
    $pool = function (string $n) use ($know, &$seen): ?string {
        if (reportPlaceholder($n)) {
            return $n;
        }
        if (isset($know['pools'][$n])) {
            $seen[$know['pools'][$n]] = $n;
            return $know['pools'][$n];
        }
        return null;
    };
    if (str_starts_with($p, '/')) {
        $parts = explode('/', substr($p, 1));
        $more = static fn (int $from): string => count($parts) > $from && implode('', array_slice($parts, $from)) !== '' ? '/…' : '';
        $top = $parts[0];
        if ($top === 'mnt') {
            $root = $parts[1] ?? '';
            if ($root === '') {
                return '/mnt';
            }
            if (in_array($root, ['disks', 'remotes', 'rootshare'], true)) {
                return "/mnt/$root" . $more(2);
            }
            if ($root === 'addons') {
                $own = ($parts[2] ?? '') === 'UnraidSecretaryOffice';
                return '/mnt/addons' . ($own ? '/UnraidSecretaryOffice' . $more(3) : $more(2));
            }
            if (!preg_match('/^(user0?|disk\d+)$/D', $root)) {
                $root = $pool($root) ?? '‹pool›';
            }
            if (!isset($parts[2]) || $parts[2] === '') {
                return "/mnt/$root";
            }
            return "/mnt/$root/" . $share($parts[2]) . $more(3);
        }
        if ($top === 'boot' || $top === 'usr') {
            $plugin = $top === 'boot' ? ['config', 'plugins'] : ['local', 'emhttp', 'plugins'];
            $n = count($plugin);
            if (array_slice($parts, 1, $n) === $plugin && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $parts[$n + 1] ?? '')) {
                return "/$top/" . implode('/', $plugin) . '/' . $parts[$n + 1] . $more($n + 2);
            }
            return $top === 'usr' ? $p : '/boot' . $more(1);
        }
        if (in_array($top, ['etc', 'proc', 'sys', 'dev', 'run'], true)
            || ($top === 'var' && in_array($parts[1] ?? '', ['log', 'run', 'local'], true))) {
            return $p;                  // the system's own folders: the same on every Unraid
        }
        return '<path>';
    }
    // a dataset: pool/share[/…][@snapshot]
    [$path, $snap] = array_pad(explode('@', $p, 2), 2, null);
    $parts = explode('/', $path);
    $root = $pool($parts[0]);
    if ($root === null) {
        return '<path>';
    }
    $out = $root . (isset($parts[1]) && $parts[1] !== '' ? '/' . $share($parts[1]) : '') . (count($parts) > 2 ? '/…' : '');
    if ($snap !== null) {
        $out .= '@' . (preg_match('/^(?:uso|unraidbackup)-[A-Za-z0-9-]{1,80}$/D', $snap) ? $snap : '…');
    }
    return $out;
}

/** A URL: its scheme and host — github.com and its API as they are, any other host ‹host›; nothing of the path */
function reportUrl(string $url): string
{
    if (!preg_match('~^([a-z][a-z0-9+.-]{1,15})://(?:[^/@\s]*@)?(\[[^\]]*\]|[^/:?#\s]*)(?::\d+)?(.*)$~iD', $url, $m)) {
        return '<path>';
    }
    $host = in_array(strtolower($m[2]), REPORT_HOSTS_KEPT, true) || reportPlaceholder($m[2]) ? $m[2] : '‹host›';
    return strtolower($m[1]) . "://$host" . ($m[3] !== '' && $m[3] !== '/' ? '/…' : '');
}

/**
 * Known names (the server, partners, users, shares, pools) → their placeholders, as whole words (a name glued to
 * letters, digits, «_», «-», a placeholder's ‹›, or «.» and a letter — «backup.sh» for a share «backup» — stays)
 *
 * @param array<string, string> $seen
 */
function reportNames(string $s, array $know, array &$seen): string
{
    $names = $know['names'];
    if (!$names) {
        return $s;
    }
    $keys = array_keys($names);
    usort($keys, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
    $alt = implode('|', array_map(fn ($n) => ($names[$n][1] ? '(?i:' . preg_quote((string) $n, '/') . ')' : preg_quote((string) $n, '/')), $keys));
    $lower = [];
    foreach ($names as $n => $v) {
        $lower[mb_strtolower((string) $n)] ??= $v[1] ? $v[0] : null;
    }
    return (string) preg_replace_callback('/(?<![\p{L}\p{N}_\-‹]|[\p{L}\p{N}]\.)(?:' . $alt . ')(?![\p{L}\p{N}_\-›]|\.[\p{L}\p{N}])/u',
        function (array $m) use ($names, $lower, &$seen): string {
            $as = $names[$m[0]][0] ?? $lower[mb_strtolower($m[0])] ?? '…';
            $seen[$as] ??= $m[0];
            return $as;
        }, $s);
}

// ===================================================================== the desk's log lines

/** A desk's labels in agent.log (REPORT_LOG_LABELS) and «<desk id>:» */
function reportLabels(string $desk): array
{
    $labels = REPORT_LOG_LABELS[$desk] ?? [];
    if ($desk !== 'office') {
        $labels[] = "$desk: ";
    }
    return $labels;
}

/** The label a line's text starts with, or null */
function reportLabelOf(string $text, array $labels): ?string
{
    foreach ($labels as $l) {
        if (str_starts_with($text, $l)) {
            return $l;
        }
    }
    return null;
}

/**
 * The last ≤ $n lines of a desk in the messenger's log (agent.log, then agent.log.1 — at most REPORT_LOG_READ bytes
 * of both, from the end) and ≤ REPORT_LOG_OWN of the agent's own (its starts, errors, PHP warnings), in their order.
 * As they are: reportLog() scrubs them.
 *
 * @return list<string>
 */
function reportLogLines(string $desk, int $n = REPORT_LOG_LINES, ?string $log = null): array
{
    $log ??= AGENT_LOG;
    $n = max(0, min($n, REPORT_LOG_LINES));
    $labels = reportLabels($desk);
    $ownMax = $desk === 'office' ? 0 : REPORT_LOG_OWN;
    $mine = $own = [];
    $budget = REPORT_LOG_READ;
    $seq = 0;
    foreach ([$log, "$log.1"] as $file) {
        if (count($mine) >= $n && count($own) >= $ownMax || $budget <= 0) {
            break;
        }
        [$lines, $read] = reportLogTail($file, $budget);
        $budget -= $read;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $seq++;
            if (count($mine) >= $n && count($own) >= $ownMax) {
                break;
            }
            $text = preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  /', $lines[$i]) ? substr($lines[$i], 21) : null;
            if ($text === null) {
                continue;
            }
            if (count($mine) < $n && reportLabelOf($text, $labels) !== null) {
                $mine[$seq] = $lines[$i];
            } elseif (count($own) < $ownMax && reportLabelOf($text, REPORT_LOG_OWN_LABELS) !== null) {
                $own[$seq] = $lines[$i];
            }
        }
    }
    $all = $mine + $own;
    krsort($all);           // counted from the end: the highest is the oldest
    return array_values($all);
}

/**
 * The last $max bytes of a file as lines (the first, cut one dropped when the file is longer), and how many bytes were read
 *
 * @return array{0: list<string>, 1: int}
 */
function reportLogTail(string $file, int $max): array
{
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file)) {
        return [[], 0];
    }
    $size = (int) @filesize($file);
    $h = @fopen($file, 'r');
    if (!$h) {
        return [[], 0];
    }
    $from = max(0, $size - $max);
    fseek($h, $from);
    $text = (string) stream_get_contents($h, $max);
    fclose($h);
    $lines = explode("\n", rtrim($text, "\n"));
    if ($from > 0) {
        array_shift($lines);
    }
    return [array_values(array_filter($lines, fn ($l) => $l !== '')), strlen($text)];
}

/**
 * The log part: a desk's lines scrubbed (the time and the desk's label stay as they are — they are the office's own
 * words), ≤ REPORT_LINE_MAX characters each, ≤ REPORT_LOG_MAX bytes together (the oldest go first)
 *
 * @param array<string, string> $seen
 */
function reportLog(string $desk, array $know, array &$seen, ?string $log = null): string
{
    $out = [];
    $labels = array_merge(reportLabels($desk), REPORT_LOG_OWN_LABELS);
    foreach (reportLogLines($desk, REPORT_LOG_LINES, $log) as $line) {
        $head = substr($line, 0, 21);
        $text = substr($line, 21);
        $label = reportLabelOf($text, $labels) ?? '';
        $out[] = $head . $label . reportScrub(substr($text, strlen($label)), $know, $seen);
    }
    while ($out && strlen(implode("\n", $out)) > REPORT_LOG_MAX) {
        array_shift($out);
    }
    return implode("\n", $out);
}

// ===================================================================== the request's fields

/**
 * The user's words of a request, read strictly (never cast): kind, desk, title, text, name. A wrong type or an unknown
 * kind or desk: bad_request; too long: bad_request (the page keeps within the limits); empty title or a text shorter
 * than REPORT_TEXT_MIN: report_incomplete.
 *
 * @return array{kind: string, desk: string, title: string, text: string, name: string}
 */
function reportWords(array $r): array
{
    $str = static function (string $field, bool $lines) use ($r): string {
        $v = $r[$field] ?? null;
        if ($v === null) {
            return '';
        }
        if (!is_string($v) || strlen($v) > 2 * REPORT_TEXT_MAX || preg_match('//u', $v) !== 1) {
            throw new Problem('bad_request');
        }
        $v = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $v);
        $v = (string) preg_replace($lines ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]+/', $lines ? '' : ' ', $v);
        return trim(reportNoUnicodeControls($v));
    };
    $kind = $str('kind', false);
    $desk = $str('desk', false);
    if (!in_array($kind, REPORT_KINDS, true) || ($desk !== 'office' && !isset(desks()[$desk]))) {
        throw new Problem('bad_request');
    }
    $title = $str('title', false);
    $text = $str('text', true);
    $name = $str('name', false);
    if (mb_strlen($title) > REPORT_TITLE_MAX || strlen($text) > REPORT_TEXT_MAX || mb_strlen($name) > REPORT_NAME_MAX) {
        throw new Problem('bad_request');
    }
    if ($title === '' || mb_strlen($text) < REPORT_TEXT_MIN) {
        throw new Problem('report_incomplete', ['min' => REPORT_TEXT_MIN]);
    }
    return ['kind' => $kind, 'desk' => $desk, 'title' => $title, 'text' => $text, 'name' => $name];
}

/** Without C1 controls, bidi overrides and isolates, line and paragraph separators (the Worker refuses them) */
function reportNoUnicodeControls(string $s): string
{
    return (string) preg_replace('/[\x{80}-\x{9f}\x{2028}\x{2029}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', $s);
}

/** The desk's last error as the page saw it: {key, params?, at?} — or null; anything malformed: bad_request */
function reportErrorField(array $r): ?array
{
    $e = $r['error'] ?? null;
    if ($e === null) {
        return null;
    }
    if (!is_array($e) || !is_string($e['key'] ?? null) || !preg_match('/^[a-z0-9_.]{1,64}$/D', $e['key'])) {
        throw new Problem('bad_request');
    }
    $params = $e['params'] ?? [];
    if (!is_array($params) || count($params) > 8 || ($params && array_is_list($params))) {
        throw new Problem('bad_request');
    }
    $out = [];
    foreach ($params as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/D', $k) || !(is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null)
            || (is_string($v) && strlen($v) > 1000)) {
            throw new Problem('bad_request');
        }
        $out[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    }
    $at = $e['at'] ?? null;
    if ($at !== null && (!is_int($at) || $at < 0)) {
        throw new Problem('bad_request');
    }
    return ['key' => $e['key'], 'params' => $out, 'at' => $at];
}

/** A language of the request: two letters ('' when absent); $known: one of the office's five */
function reportLangField(array $r, string $field, bool $known): string
{
    $v = optText($r, $field);
    if ($v !== '' && (!preg_match('/^[a-z]{2}$/D', $v) || ($known && !in_array($v, REPORT_LANGS, true)))) {
        throw new Problem('bad_request');
    }
    return $v;
}

/** The parts the user left ticked: a list of REPORT_PARTS and REPORT_IMAGE_PARTS, each once; anything else bad_request */
function reportPartsField(array $r): array
{
    $all = array_merge(REPORT_PARTS, REPORT_IMAGE_PARTS);
    $v = $r['parts'] ?? null;
    if (!is_array($v) || !array_is_list($v) || count($v) > count($all)) {
        throw new Problem('bad_request');
    }
    foreach ($v as $p) {
        if (!is_string($p) || !in_array($p, $all, true)) {
            throw new Problem('bad_request');
        }
    }
    if (count(array_unique($v)) !== count($v)) {
        throw new Problem('bad_request');
    }
    return $v;
}

// ===================================================================== reports.json and the caps

/**
 * data/office/reports.json: {"v":1, "reports":[{number, url, kind, title, desk, sent, rid, status?, public?, checked?}],
 * "closed_until": null|<time>} — status/public/checked: where the report stood when last asked (reportStatusRefresh());
 * what a reader doesn't know (other keys, entries of another shape) is kept as it stood (a tolerant writer).
 */
function reportsRead(array $ctx = []): array
{
    $j = readJson(reportsFile($ctx)) ?? [];
    $j['v'] = 1;
    $j['reports'] = is_array($j['reports'] ?? null) && array_is_list($j['reports']) ? $j['reports'] : [];
    $j['closed_until'] = is_int($j['closed_until'] ?? null) ? $j['closed_until'] : null;
    return $j;
}

/** An entry in exactly the shape this office writes */
function reportEntryValid(mixed $e): bool
{
    return is_array($e) && is_int($e['number'] ?? null) && $e['number'] > 0 && is_string($e['url'] ?? null)
        && in_array($e['kind'] ?? null, REPORT_KINDS, true) && is_string($e['title'] ?? null) && is_string($e['desk'] ?? null)
        && is_int($e['sent'] ?? null)
        // where it stood (reportStatusRefresh()): each optional, but only in the shape written
        && (!array_key_exists('status', $e) || in_array($e['status'], REPORT_STATUSES, true))
        && (!array_key_exists('checked', $e) || is_int($e['checked']))
        && (!array_key_exists('public', $e) || $e['public'] === null || reportPublic($e['public']) === $e['public']);
}

/** A public issue in exactly the shape kept and shown: {number, url} of the office's public repository — else null */
function reportPublic(mixed $p): ?array
{
    if (!is_array($p) || !is_string($p['url'] ?? null) || !preg_match(REPORT_PUBLIC_RE, $p['url'], $m)
        || !is_int($p['number'] ?? null) || (string) $p['number'] !== $m[1]) {
        return null;
    }
    return ['number' => $p['number'], 'url' => $p['url']];
}

function reportsWrite(array $j, array $ctx = []): void
{
    $file = reportsFile($ctx);
    if (!is_dir(dirname($file)) || is_link(dirname($file))) {
        throw new Problem('office_storage');
    }
    $valid = array_keys(array_filter($j['reports'], 'reportEntryValid'));
    foreach (array_slice($valid, 0, max(0, count($valid) - REPORT_KEEP)) as $i) {
        unset($j['reports'][$i]);           // the oldest of ours go; entries of another shape stay
    }
    $j['reports'] = array_values($j['reports']);
    writeAtomic($file, jsonEncode($j), 0600, WEB_UID, WEB_UID);
}

/**
 * The cap as this office counts it: n sent in the last 24 hours, left, and when the next may go (null: now)
 *
 * @return array{n: int, left: int, cap: int, next: ?int}
 */
function reportCap(array $j, int $now): array
{
    $sent = [];
    foreach ($j['reports'] as $e) {
        if (reportEntryValid($e) && $e['sent'] > $now - REPORT_DAY && $e['sent'] <= $now + 300) {
            $sent[] = $e['sent'];
        }
    }
    sort($sent);
    $n = count($sent);
    $next = $n >= REPORT_CAP_DAY ? $sent[$n - REPORT_CAP_DAY] + REPORT_DAY : null;
    return ['n' => $n, 'left' => max(0, REPORT_CAP_DAY - $n), 'cap' => REPORT_CAP_DAY, 'next' => $next];
}

/**
 * «Your reports» for the dialog: the newest first — each with where it stands (status: received | seen | done, absent
 * while unknown) and its public issue ({number, url}, only the office's public repository) —, and the cap. Never the
 * private inbox's number or link: those stay in reports.json for asking the Worker.
 */
function reportsAnswer(array $ctx = []): array
{
    $now = $ctx['now'] ?? time();
    $j = reportStatusRefresh(reportsRead($ctx), $now, $ctx);
    $list = [];
    foreach (array_reverse(array_values(array_filter($j['reports'], 'reportEntryValid'))) as $e) {
        $row = ['kind' => $e['kind'], 'title' => $e['title'], 'desk' => $e['desk'], 'sent' => $e['sent']];
        if (isset($e['status'])) {
            $row['status'] = $e['status'];
        }
        if (($pub = reportPublic($e['public'] ?? null)) !== null) {
            $row['public'] = $pub;
        }
        $list[] = $row;
    }
    return ['ok' => true, 'reports' => $list, 'closed' => ($j['closed_until'] ?? 0) > $now] + reportCap($j, $now);
}

/**
 * Where the newest REPORT_STATUS_MAX reports stand, asked of the Worker for those not looked at within
 * REPORT_STATUS_EVERY — one GET for all (reportStatusAsk()). Each asked one gets `checked` (also when the ask failed:
 * at most one try an hour); one the Worker answered gets `status` and `public` — one it left out (not this office's,
 * gone) keeps what it had. Written back only when something was asked; a write that fails is no failure. No inbox
 * (FEEDBACK_URL / OFFICE_FEEDBACK_URL empty): nothing asked, nothing written.
 */
function reportStatusRefresh(array $j, int $now, array $ctx = []): array
{
    $due = [];
    foreach (array_reverse(array_keys($j['reports'])) as $i) {
        $e = $j['reports'][$i];
        if (!reportEntryValid($e)) {
            continue;
        }
        if (count($due) >= REPORT_STATUS_MAX) {
            break;
        }
        $checked = $e['checked'] ?? null;
        $due[$i] = $checked === null || $checked <= $now - REPORT_STATUS_EVERY || $checked > $now + 300;
    }
    $ask = array_keys(array_filter($due));
    $base = $ctx['url'] ?? officeFeedbackUrl();
    if (!$ask || $base === '') {
        return $j;
    }
    $answer = reportStatusAsk(array_map(fn ($i) => $j['reports'][$i]['number'], $ask), ['url' => $base] + $ctx);
    foreach ($ask as $i) {
        $e = &$j['reports'][$i];
        $e['checked'] = $now;
        $got = $answer[$e['number']] ?? null;
        if ($got !== null) {
            $e['status'] = $got['status'];
            $e['public'] = $got['public'];
        }
        unset($e);
    }
    try {
        reportsWrite($j, $ctx);
    } catch (Throwable) {
        // not kept: asked again next time
    }
    return $j;
}

/**
 * GET <inbox>/api/status?ids=<n>,… with the report ID as header X-Office: curl through hostNet() (no shell), address
 * and header in a 0600 curl config file in RAM (never on the command line), https only (http only when the plugin's
 * .cfg says FEEDBACK_URL="http://…" — tests), REPORT_STATUS_TIMEOUT, no redirects, at most 64 KB back. Nothing else
 * of this server goes along.
 *
 * @param int[] $numbers the inbox's numbers
 * @return array<int, array{status: string, public: ?array}> per number the Worker answered — [] on any failure
 */
function reportStatusAsk(array $numbers, array $ctx = []): array
{
    $base = $ctx['url'] ?? officeFeedbackUrl();
    $numbers = array_values(array_unique(array_filter($numbers, fn ($n) => is_int($n) && $n > 0 && $n < 1000000000)));
    $dir = reportRunDir($ctx);
    if ($base === '' || !$numbers || !reportRunDirReady($dir)) {
        return [];
    }
    $file = "$dir/status." . bin2hex(random_bytes(6)) . '.curl';
    $old = umask(0177);
    $h = @fopen($file, 'x');
    umask($old);
    $config = 'url = "' . $base . '/api/status?ids=' . implode(',', array_slice($numbers, 0, REPORT_STATUS_MAX)) . "\"\n"
        . 'header = "X-Office: ' . reportId($ctx) . "\"\n";
    if (!$h || @fwrite($h, $config) !== strlen($config) || !fclose($h)) {
        @unlink($file);
        return [];
    }
    try {
        [$exit, $out] = hostNet(['curl', '-s', '-S', '-m', (string) REPORT_STATUS_TIMEOUT, '--proto', str_starts_with($base, 'http://') ? '=http' : '=https',
            '--max-redirs', '0', '--max-filesize', '65536', '-H', 'Accept: application/json',
            '-A', 'UnraidSecretaryOffice/' . AGENT_VERSION, '-w', '\n%{http_code}', '-K', $file], REPORT_STATUS_TIMEOUT + 5);
    } catch (Throwable) {
        return [];
    } finally {
        @unlink($file);
    }
    return reportStatusAnswer($exit, $out, $numbers);
}

/**
 * The Worker's 200 {ok: true, reports: [{number, state: open|closed, answered, public: {number, url, state}|null}]} in the
 * office's words: closed → done; open and answered or with a public issue → seen; open → received. A public issue only
 * of the office's public repository (REPORT_PUBLIC_RE). Only numbers that were asked; anything else → [].
 *
 * @return array<int, array{status: string, public: ?array}>
 */
function reportStatusAnswer(int $exit, string $out, array $asked): array
{
    $cut = strrpos(rtrim($out), "\n");
    $code = (int) substr(rtrim($out), $cut === false ? 0 : $cut + 1);
    if ($exit !== 0 || $code !== 200 || $cut === false) {
        return [];
    }
    $body = json_decode(substr($out, 0, $cut), true, 8);
    if (!is_array($body) || ($body['ok'] ?? null) !== true || !is_array($body['reports'] ?? null) || !array_is_list($body['reports'])) {
        return [];
    }
    $got = [];
    foreach ($body['reports'] as $r) {
        if (!is_array($r) || !is_int($r['number'] ?? null) || !in_array($r['number'], $asked, true)
            || !in_array($r['state'] ?? null, ['open', 'closed'], true)) {
            continue;
        }
        $public = reportPublic($r['public'] ?? null);
        // the inbox issue is closed once triaged — «done» only when its public issue is closed (Benj 2026-10-10: #12 → #14 still open)
        $status = $public !== null
            ? ((($r['public']['state'] ?? null) === 'closed') ? 'done' : 'seen')
            : ($r['state'] === 'closed' || ($r['answered'] ?? null) === true ? 'seen' : 'received');
        $got[$r['number']] = ['status' => $status, 'public' => $public];
    }
    return $got;
}

/** A link to an issue the Worker answered — only https://github.com/<owner>/<repo>/issues/<n>; anything else '' */
function reportIssueUrl(mixed $url): string
{
    return is_string($url) && preg_match('#^https://github\.com/[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}/issues/[0-9]{1,9}$#D', $url) ? $url : '';
}

// ===================================================================== the preview

/**
 * office.report_preview: everything that would be sent, part by part, kept in RAM under a new token. No network.
 * The answer: {token, ttl, parts: {versions, unraid, language, team, error?, log}, ticked: [the parts ticked by default],
 * id (the report ID's start, shown), hidden ({placeholder: name} — shown, never sent), hints (what the user's own text
 * seems to hold: address, mail, token), «Your reports»' cap and closed}.
 */
function reportPreview(array $r, array $ctx = []): array
{
    $now = $ctx['now'] ?? time();
    $words = reportWords($r);
    $error = reportErrorField($r);
    $lang = reportLangField($r, 'lang', true);
    $browser = reportLangField($r, 'browser', false);
    $refs = reportImageRefs($r);
    $know = $ctx['know'] ?? reportKnow($ctx);
    $seen = [];

    $versions = ['office' => AGENT_VERSION];
    if (in_array($words['desk'], ['backup', 'restore'], true) && ($engine = reportEngineVersion($ctx)) !== null) {
        $versions['engine'] = $engine;
    }
    $parts = [
        'versions' => $versions,
        'unraid'   => reportUnraidVersion($ctx),
        'language' => ['lang' => $lang, 'browser' => $browser],
        'team'     => $ctx['hired'] ?? staffHired(),
    ];
    sort($parts['team'], SORT_STRING);
    if ($error !== null) {
        foreach ($error['params'] as $k => $v) {
            $error['params'][$k] = mb_strimwidth(reportScrub($v, $know, $seen), 0, 300, '…', 'UTF-8');      // the Worker's limit
        }
        $parts['error'] = $error;
    }
    $parts['log'] = reportLog($words['desk'], $know, $seen, $ctx['log'] ?? null);
    $ticked = array_values(array_filter(array_keys($parts), fn ($p) => $p !== 'log' || $words['kind'] === 'bug'));
    if ($parts['log'] === '') {
        $ticked = array_values(array_diff($ticked, ['log']));
    }

    $dir = reportRunDir($ctx);
    $token = bin2hex(random_bytes(16));
    // the pictures leave the inbox now, whatever comes of them
    $pictures = reportImagesTake($refs, $ctx['inbox'] ?? officeInboxDir());
    if (!reportRunDirReady($dir)) {
        throw new Problem('office_storage');
    }
    reportTidy($dir, $now);
    $images = reportImagesPrepare($pictures, $dir, $token);
    unset($pictures);
    $kept = ['time' => $now, 'words' => $words, 'rid' => bin2hex(random_bytes(16)), 'report_id' => reportId($ctx), 'parts' => $parts];
    if ($images) {
        $kept['images'] = $images;
        $ticked = array_merge($ticked, array_slice(REPORT_IMAGE_PARTS, 0, count($images)));
    }
    try {
        writeAtomic("$dir/$token.json", jsonEncode($kept), 0600, 0, 0);
    } catch (Throwable $e) {
        reportImagesDrop($dir, $token);
        throw $e;
    }

    $hints = [];
    $text = $words['title'] . "\n" . $words['text'];
    if (reportAddresses(watchnetScrub($text, [])) !== $text) {
        $hints[] = 'address';
    }
    if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $text)) {
        $hints[] = 'mail';
    }
    if (preg_match('/[A-Za-z0-9_+=-]{28,}/', $text)) {
        $hints[] = 'token';
    }
    ksort($seen, SORT_NATURAL);
    $j = reportsRead($ctx);
    $answer = ['ok' => true, 'token' => $token, 'ttl' => REPORT_TOKEN_TTL, 'parts' => $parts, 'ticked' => $ticked,
               'id' => substr($kept['report_id'], 0, 8), 'hidden' => (object) $seen, 'hints' => $hints,
               'closed' => ($j['closed_until'] ?? 0) > $now] + reportCap($j, $now);
    if ($images) {
        // what each picture became (the page shows its own copy beside it: the same picture, the pixels unchanged
        // but for the size); never the bytes — they stay in RAM here
        $answer['images'] = array_map(fn (array $i): array => ['type' => $i['type'], 'width' => $i['width'], 'height' => $i['height'],
            'bytes' => $i['bytes'], 'scaled' => $i['scaled']], $images);
    }
    return $answer;
}

// ===================================================================== the pictures

/** The preview's `images`: absent → none; else a list of ≤ REPORT_IMG_COUNT_MAX distinct inbox refs (32 hex) */
function reportImageRefs(array $r): array
{
    $v = $r['images'] ?? null;
    if ($v === null) {
        return [];
    }
    if (!is_array($v) || !array_is_list($v)) {
        throw new Problem('bad_request');
    }
    if (count($v) > REPORT_IMG_COUNT_MAX) {
        throw new Problem('report_images_many', ['n' => REPORT_IMG_COUNT_MAX]);
    }
    foreach ($v as $ref) {
        if (!is_string($ref) || !preg_match('/^[0-9a-f]{32}$/D', $ref)) {
            throw new Problem('bad_request');
        }
    }
    if (count(array_unique($v)) !== count($v)) {
        throw new Problem('bad_request');
    }
    return $v;
}

/**
 * The pictures the web side left in the RAM inbox for this preview: each a plain 0600 file of ours (no link, one name)
 * in a folder of ours only, ≤ REPORT_IMG_IN_MAX bytes — every one removed at once, usable or not. One missing (the
 * inbox swept it, another preview took it): report_image_gone.
 *
 * @return list<string>
 */
function reportImagesTake(array $refs, string $inbox): array
{
    if (!$refs) {
        return [];
    }
    $me = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    clearstatcache();
    $d = @lstat($inbox);
    $dirOk = $d && ($d['mode'] & 0170000) === 0040000 && $d['uid'] === $me && ($d['mode'] & 0077) === 0;
    $out = [];
    $missing = null;
    foreach ($refs as $n => $ref) {
        $file = "$inbox/$ref.image";
        $st = @lstat($file);
        $ok = $dirOk && $st && ($st['mode'] & 0170000) === 0100000 && $st['uid'] === $me && ($st['mode'] & 0077) === 0 && $st['nlink'] === 1
            && $st['size'] <= REPORT_IMG_IN_MAX;
        $raw = $ok ? @file_get_contents($file, false, null, 0, REPORT_IMG_IN_MAX + 1) : false;
        if ($st) {
            @unlink($file);
        }
        if (!is_string($raw) || $raw === '') {
            $missing ??= $n + 1;
            continue;
        }
        $out[] = $raw;
    }
    if ($missing !== null) {
        throw new Problem('report_image_gone', ['n' => $missing]);
    }
    return $out;
}

/**
 * Each picture drawn anew by src/reportimage.php in a process of its own (`php -n`, its own memory limit, 60 s): kept as
 * RUN_DIR/report/<token>.<n>.img (0600). Their share of the bytes: ≤ REPORT_IMG_OUT_MAX each, ≤ REPORT_IMG_TOTAL_MAX
 * together. One refused: all of this preview's go, and the page says which (n) and why.
 *
 * @param list<string> $pictures
 * @return list<array{type: string, width: int, height: int, bytes: int, sha256: string, scaled: bool}>
 */
function reportImagesPrepare(array $pictures, string $dir, string $token): array
{
    if (!$pictures) {
        return [];
    }
    $max = min(REPORT_IMG_OUT_MAX, intdiv(REPORT_IMG_TOTAL_MAX, count($pictures)));
    $images = [];
    foreach ($pictures as $i => $bytes) {
        $n = $i + 1;
        $in = "$dir/$token.$n.in";
        $out = "$dir/$token.$n.img";
        @unlink($in);
        @unlink($out);
        try {
            $old = umask(0177);
            $h = @fopen($in, 'x');
            umask($old);
            if (!$h || @fwrite($h, $bytes) !== strlen($bytes) || !fclose($h)) {
                throw new Problem('office_storage');
            }
            [, $stdout] = run([PHP_BINARY, '-n', '-d', 'memory_limit=' . REPORT_IMG_MEMORY, '-d', 'display_errors=stderr', '-d', 'max_execution_time=0',
                dirname(__DIR__, 2) . '/src/reportimage.php', $in, $out, (string) $max], 60);
            $a = json_decode(trim((string) strrchr("\n" . trim($stdout), "\n")), true);
            if (!is_array($a) || ($a['ok'] ?? null) !== true) {
                $key = is_array($a) && in_array($a['key'] ?? null, ['report_image_type', 'report_image_big', 'report_image_bad', 'office_storage'], true) ? $a['key'] : 'report_image_bad';
                throw new Problem($key, ['n' => $n]);
            }
            clearstatcache(true, $out);
            $data = is_file($out) && !is_link($out) ? (string) @file_get_contents($out, false, null, 0, REPORT_IMG_OUT_MAX + 1) : '';
            $type = reportImageType($data);
            if ($data === '' || strlen($data) > $max || strlen($data) !== ($a['bytes'] ?? -1) || $type !== ($a['type'] ?? null)
                || !in_array($type, ['png', 'jpeg'], true) || hash('sha256', $data) !== ($a['sha256'] ?? '')
                || !is_int($a['width'] ?? null) || !is_int($a['height'] ?? null)) {
                throw new Problem('report_image_bad', ['n' => $n]);
            }
            $images[] = ['type' => $type, 'width' => $a['width'], 'height' => $a['height'], 'bytes' => strlen($data), 'sha256' => $a['sha256'],
                         'scaled' => ($a['scaled'] ?? false) === true];
        } catch (Throwable $e) {
            @unlink($in);
            reportImagesDrop($dir, $token);
            throw $e;
        }
        @unlink($in);
    }
    return $images;
}

/** A preview's pictures in RAM gone (sent, refused, stale) */
function reportImagesDrop(string $dir, string $token): void
{
    foreach (glob("$dir/$token.*.{img,in}", GLOB_BRACE) ?: [] as $f) {
        if (preg_match('/^[0-9a-f]{32}\.[1-9]\.(?:img|in)$/D', basename($f))) {
            @unlink($f);
        }
    }
}

/**
 * The bytes of a kept preview's picture n (1 …) as previewed — the same length and hash, else null
 */
function reportImageKept(string $dir, string $token, array $kept, int $n): ?string
{
    $meta = $kept['images'][$n - 1] ?? null;
    $file = "$dir/$token.$n.img";
    if (!is_array($meta) || is_link($file) || !is_file($file)) {
        return null;
    }
    $data = (string) @file_get_contents($file, false, null, 0, REPORT_IMG_OUT_MAX + 1);
    return strlen($data) === ($meta['bytes'] ?? -1) && hash('sha256', $data) === ($meta['sha256'] ?? '') ? $data : null;
}

/** The backup engine's version (backup/lib/common.sh UB_VERSION), or null */
function reportEngineVersion(array $ctx = []): ?string
{
    $text = (string) @file_get_contents($ctx['common_sh'] ?? OFFICE_DIR . '/backup/lib/common.sh', false, null, 0, 8192);
    return preg_match('/^UB_VERSION="(\d{1,4}(?:\.\d{1,4}){1,3})"$/m', $text, $m) ? $m[1] : null;
}

/** Unraid's version (/etc/unraid-version), a beta's suffix too (7.2.0-rc.1) — '' when unknown */
function reportUnraidVersion(array $ctx = []): string
{
    $text = (string) @file_get_contents($ctx['unraid_version'] ?? '/etc/unraid-version', false, null, 0, 512);
    return preg_match('/version="?(\d{1,4}(?:\.\d{1,4}){1,3}(?:[-+][0-9A-Za-z.-]{1,24})?)"?\s*$/m', $text, $m) ? $m[1] : '';
}

/** RUN_DIR/report: a folder of root's own (0700), never through a link */
function reportRunDirReady(string $dir): bool
{
    clearstatcache(true, $dir);
    if (!file_exists($dir) && !is_link($dir)) {
        @mkdir($dir, 0700, true);
        clearstatcache(true, $dir);
    }
    $st = @lstat($dir);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    return $st !== false && ($st['mode'] & 0170000) === 0040000 && $st['uid'] === $me && ($st['mode'] & 0077) === 0;
}

/**
 * Previews older than their time and what a send left go (a sent token's answer stays an hour); pictures as soon as
 * their preview can't be sent any more, and the oldest while all of them together hold more than REPORT_IMG_RAM_MAX
 */
function reportTidy(string $dir, int $now): void
{
    foreach (glob("$dir/*.{json,sending,sent,body}", GLOB_BRACE) ?: [] as $f) {
        if (is_file($f) && !is_link($f) && (int) @filemtime($f) < $now - 3600) {
            @unlink($f);
        }
    }
    $pictures = [];
    foreach (glob("$dir/*.{img,in}", GLOB_BRACE) ?: [] as $f) {
        $st = @lstat($f);
        if (!$st) {
            continue;
        }
        if (($st['mode'] & 0170000) !== 0100000 || $st['mtime'] < $now - REPORT_TOKEN_TTL - 60) {
            @unlink($f);
            continue;
        }
        $pictures[$f] = [$st['mtime'], $st['size']];
    }
    uasort($pictures, fn ($a, $b) => $a[0] <=> $b[0]);
    $total = array_sum(array_column($pictures, 1));
    foreach ($pictures as $f => [, $size]) {
        if ($total <= REPORT_IMG_RAM_MAX) {
            break;
        }
        @unlink($f);
        $total -= $size;
    }
}

// ===================================================================== the send

/**
 * office.report_send {token, kind, desk, title, text, name?, parts}: the kept preview — its words must be the ones the
 * page sends now (a word changed: report_stale, show it again) — minus the parts not ticked, to the makers' inbox.
 * Refused before any request: the local cap (report_day), the Worker said «closed» within a day (report_closed), the
 * preview older than REPORT_TOKEN_TTL or never made (report_stale). One token sends once: a second send gets the
 * first one's answer.
 */
function reportSend(array $r, array $ctx = []): array
{
    $now = $ctx['now'] ?? time();
    $token = optText($r, 'token');
    if (!preg_match('/^[0-9a-f]{32}$/D', $token)) {
        throw new Problem('bad_request');
    }
    $words = reportWords($r);
    $parts = reportPartsField($r);
    // the scrubber can't blank a picture: one goes only with the user's tick «I've looked at the pictures»
    $checked = $r['images_checked'] ?? false;
    if (!is_bool($checked)) {
        throw new Problem('bad_request');
    }
    if (array_intersect($parts, REPORT_IMAGE_PARTS) && !$checked) {
        throw new Problem('report_images_unchecked');
    }
    $dir = reportRunDir($ctx);
    if (!reportRunDirReady($dir)) {
        throw new Problem('office_storage');
    }
    $sent = readJson("$dir/$token.sent");
    if ($sent !== null && is_int($sent['number'] ?? null) && !is_link("$dir/$token.sent")) {
        return ['ok' => true, 'number' => $sent['number'], 'url' => reportIssueUrl($sent['url'] ?? ''), 'again' => true]
            + reportCap(reportsRead($ctx), $now);
    }
    $j = reportsRead($ctx);
    if (($j['closed_until'] ?? 0) > $now) {
        throw new Problem('report_closed');
    }
    $cap = reportCap($j, $now);
    if ($cap['left'] <= 0) {
        throw new Problem('report_day', ['n' => $cap['n'], 'next' => $cap['next']]);
    }
    // claimed by a rename: a second send of the same token at the same time finds nothing
    if (is_link("$dir/$token.json") || !@rename("$dir/$token.json", "$dir/$token.sending")) {
        throw new Problem('report_stale');
    }
    $kept = readJson("$dir/$token.sending");
    $giveBack = static fn () => @rename("$dir/$token.sending", "$dir/$token.json");
    if (!is_array($kept) || !is_int($kept['time'] ?? null) || $now - $kept['time'] > REPORT_TOKEN_TTL || $now < $kept['time'] - 60) {
        @unlink("$dir/$token.sending");
        reportImagesDrop($dir, $token);
        throw new Problem('report_stale');
    }
    if (($kept['words'] ?? null) !== $words) {
        $giveBack();
        throw new Problem('report_stale');
    }
    try {
        $body = reportBody($kept, $parts, fn (int $n): ?string => reportImageKept($dir, $token, $kept, $n));
    } catch (Problem $e) {
        @unlink("$dir/$token.sending");             // a picture isn't what was previewed (or gone): show it again
        reportImagesDrop($dir, $token);
        throw $e;
    }
    $pictures = count($body['images'] ?? []);
    try {
        $answer = reportPost($body, $dir, $token, $ctx);
    } catch (Throwable $e) {
        $giveBack();                    // the body couldn't be written, curl couldn't run: nothing went — the preview stays
        throw $e;
    }
    if (!$answer['ok']) {
        $giveBack();
        if ($answer['key'] === 'report_closed') {
            $j['closed_until'] = $now + REPORT_CLOSED_FOR;
            try {
                reportsWrite($j, $ctx);
            } catch (Throwable) {
                // not remembered: the next send asks again
            }
        }
        reportLogLine("Office: a report could not be sent ({$answer['key']})", $ctx);
        throw new Problem($answer['key'], $answer['params']);
    }
    $entry = ['number' => $answer['number'], 'url' => $answer['url'], 'kind' => $words['kind'], 'title' => $words['title'],
              'desk' => $words['desk'], 'sent' => $now, 'rid' => (string) $kept['rid']];
    try {
        writeAtomic("$dir/$token.sent", jsonEncode(['number' => $answer['number'], 'url' => $answer['url']]), 0600, 0, 0);
    } catch (Throwable) {
        // the next send of this token finds no answer — and no preview: report_stale, never a second report
    }
    @unlink("$dir/$token.sending");
    reportImagesDrop($dir, $token);
    $j = reportsRead($ctx);         // anew: written by nobody else, but read as late as possible
    $j['reports'][] = $entry;
    $j['closed_until'] = null;
    reportsWrite($j, $ctx);
    reportLogLine("Office: sent a report (#{$answer['number']}, {$words['kind']}, {$words['desk']}"
        . ($pictures ? ", $pictures picture" . ($pictures === 1 ? '' : 's') : '') . ')', $ctx);
    return ['ok' => true, 'number' => $answer['number'], 'url' => $answer['url']] + reportCap($j, $now);
}

/**
 * The request's body (briefs/uso-feedback-concept.md §5.2): the kept preview with only the ticked parts —
 * {v, rid, report_id, kind, desk, title, text, name?, facts: {office, engine?, unraid, lang, browser, hired}, error?,
 * log?, images?, parts}. Without its pictures never larger than REPORT_BODY_MAX: the log's oldest lines go first.
 * $image(n): the bytes of the kept picture n as previewed (reportImageKept()), null when they aren't — report_stale.
 */
function reportBody(array $kept, array $parts, ?callable $image = null): array
{
    $w = $kept['words'];
    $p = $kept['parts'];
    $on = array_flip($parts);
    $facts = [];
    if (isset($on['versions'])) {
        $facts += $p['versions'];
    }
    if (isset($on['unraid']) && $p['unraid'] !== '') {
        $facts['unraid'] = $p['unraid'];
    }
    if (isset($on['language'])) {
        $facts += array_filter(['lang' => $p['language']['lang'], 'browser' => $p['language']['browser']], fn ($v) => $v !== '');
    }
    if (isset($on['team'])) {
        $facts['hired'] = $p['team'];
    }
    $body = ['v' => 1, 'rid' => $kept['rid'], 'report_id' => $kept['report_id'], 'kind' => $w['kind'], 'desk' => $w['desk'],
             'title' => $w['title'], 'text' => $w['text']];
    if ($w['name'] !== '') {
        $body['name'] = $w['name'];
    }
    $body['facts'] = (object) $facts;
    if (isset($on['error'], $p['error'])) {
        $e = $p['error'];
        $body['error'] = ['key' => $e['key'], 'params' => (object) $e['params']] + ($e['at'] !== null ? ['at' => gmdate('Y-m-d\TH:i:s\Z', $e['at'])] : []);
    }
    $sentParts = array_values(array_filter(REPORT_PARTS, fn ($x) => isset($on[$x]) && ($x !== 'error' || isset($p['error'])) && ($x !== 'log' || $p['log'] !== '')));
    if (in_array('log', $sentParts, true)) {
        $body['log'] = $p['log'];
    }
    $images = [];
    foreach (is_array($kept['images'] ?? null) ? $kept['images'] : [] as $i => $meta) {
        if (!isset($on[REPORT_IMAGE_PARTS[$i] ?? '']) || !is_array($meta)) {
            continue;
        }
        $data = $image !== null ? $image($i + 1) : null;
        if ($data === null) {
            throw new Problem('report_stale');
        }
        $images[] = ['type' => REPORT_IMG_MIME[$meta['type']] ?? 'image/png', 'data' => base64_encode($data)];
    }
    if ($images) {
        $sentParts[] = 'images';
    }
    $body['parts'] = $sentParts;
    while (isset($body['log']) && strlen(jsonEncode($body)) > REPORT_BODY_MAX) {
        $lines = explode("\n", $body['log']);
        array_shift($lines);
        $body['log'] = implode("\n", $lines);
    }
    if ($images) {
        $parts = $body['parts'];
        unset($body['parts']);
        $body += ['images' => $images, 'parts' => $parts];       // the Worker's field order: parts last
    }
    return $body;
}

/**
 * POST the body to <inbox>/api/report: curl through hostNet() (no shell), the body from a 0600 file in RAM (never on the
 * command line), https only — http only when the plugin's .cfg says so (FEEDBACK_URL, tests) —, 20 s, no redirects, at
 * most 64 KB back, a fixed User-Agent, nothing else of this server. The answer mapped to the office's own keys.
 *
 * @return array{ok: true, number: int, url: string}|array{ok: false, key: string, params: array}
 */
function reportPost(array $body, string $dir, string $token, array $ctx = []): array
{
    $base = $ctx['url'] ?? officeFeedbackUrl();
    if ($base === '') {
        return ['ok' => false, 'key' => 'report_offline', 'params' => []];
    }
    $file = "$dir/$token.body";
    @unlink($file);
    $old = umask(0177);
    $h = @fopen($file, 'x');
    umask($old);
    $json = jsonEncode($body);
    if (!$h || @fwrite($h, $json) !== strlen($json) || !fclose($h)) {
        @unlink($file);
        throw new Problem('office_storage');
    }
    $limit = isset($body['images']) ? REPORT_SEND_TIMEOUT_IMAGES : REPORT_SEND_TIMEOUT;     // pictures: a few MB up
    try {
        [$exit, $out, $err] = hostNet(['curl', '-s', '-S', '-m', (string) $limit, '--proto', str_starts_with($base, 'http://') ? '=http' : '=https',
            '--max-redirs', '0', '--max-filesize', '65536', '-H', 'Content-Type: application/json', '-H', 'Accept: application/json',
            '-A', 'UnraidSecretaryOffice/' . AGENT_VERSION, '--data-binary', "@$file", '-w', '\n%{http_code}', "$base/api/report"], $limit + 10);
    } finally {
        @unlink($file);
    }
    return reportAnswer($exit, $out, $ctx['now'] ?? time());
}

/**
 * The Worker's answer in the office's words — by its `error` (uso-support/feedback/SETUP.md «The contract as built»),
 * never by the HTTP status (`closed` is a 403):
 *   ok:true + number                        → sent (the url only when it is a GitHub issue's; again:true = it was one already)
 *   closed                                  → report_closed
 *   week {next, retry_after, cap?}          → report_day (the office's cap — 25 a day since 2026-10-09; the Worker keeps the
 *                                             name `week` for the offices up to 1.47; next: when a slot frees)
 *   busy {retry_after}                      → report_busy
 *   busy {why: images} key report_images_busy → report_images_busy (the inbox's bytes of pictures for the day are used up)
 *   refused {why, field?}                   → report_refused
 *   github | config | internal | anything else, an answer that isn't the Worker's → report_failed
 *   no answer at all (curl failed)          → report_offline
 * The Worker's `key` is taken when it is one of these. Never a text the Worker sends: only keys.
 *
 * @return array{ok: true, number: int, url: string}|array{ok: false, key: string, params: array}
 */
function reportAnswer(int $exit, string $out, int $now): array
{
    $cut = strrpos(rtrim($out), "\n");
    $code = (int) substr(rtrim($out), $cut === false ? 0 : $cut + 1);
    if ($exit !== 0 || $code === 0) {
        return ['ok' => false, 'key' => 'report_offline', 'params' => []];
    }
    $body = json_decode($cut === false ? '' : substr($out, 0, $cut), true, 8);
    $body = is_array($body) ? $body : [];
    if ($code >= 200 && $code < 300 && ($body['ok'] ?? null) === true && is_int($body['number'] ?? null) && $body['number'] > 0) {
        return ['ok' => true, 'number' => $body['number'], 'url' => reportIssueUrl($body['url'] ?? null)];
    }
    $keys = ['closed' => 'report_closed', 'week' => 'report_day', 'day' => 'report_day', 'busy' => 'report_busy', 'refused' => 'report_refused',
             'github' => 'report_failed', 'config' => 'report_failed', 'internal' => 'report_failed'];
    $wireKeys = ['report_closed', 'report_week', 'report_day', 'report_busy', 'report_images_busy', 'report_refused', 'report_failed'];
    $key = is_string($body['key'] ?? null) && in_array($body['key'], $wireKeys, true) ? ($body['key'] === 'report_week' ? 'report_day' : $body['key'])
        : $keys[is_string($body['error'] ?? null) ? $body['error'] : ''] ?? 'report_failed';
    if ($key === 'report_busy' && ($body['why'] ?? null) === 'images') {
        $key = 'report_images_busy';
    }
    if ($key !== 'report_day') {
        return ['ok' => false, 'key' => $key, 'params' => []];
    }
    $retry = is_int($body['retry_after'] ?? null) && $body['retry_after'] > 0 ? $body['retry_after'] : null;
    $next = is_int($body['next'] ?? null) ? $body['next'] : (is_string($body['next'] ?? null) ? (strtotime($body['next']) ?: null) : null);
    $cap = is_int($body['cap'] ?? null) && $body['cap'] > 0 && $body['cap'] <= 1000 ? $body['cap'] : REPORT_CAP_DAY;
    return ['ok' => false, 'key' => 'report_day', 'params' => ['n' => $cap, 'next' => $next ?? ($retry !== null ? $now + $retry : null)]];
}

/** A line in agent.log — never a report's words; the tests keep theirs */
function reportLogLine(string $text, array $ctx = []): void
{
    if (isset($ctx['log_lines'])) {
        $ctx['log_lines']($text);
        return;
    }
    logLine($text);
}
