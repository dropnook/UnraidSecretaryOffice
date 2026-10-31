<?php
declare(strict_types=1);

/*
 * Ms. Protocolli — reads every log out loud. Understanding them is not her
 * job: she shows them, follows them like tail -f and lets the page filter.
 *
 * Only sources from a fixed list (logsSources()): the office's own logs,
 * Unraid's logs in /var/log (RAM — nothing wakes up), the flash's parity
 * history, User Scripts outputs and the containers' docker logs. A request
 * names a source id, never a path. Read only.
 *
 * Her tour ("tour": logsTourRun() in a process of its own, polled from tick)
 * counts and groups, it never judges:
 *   space    how full /var/log is (Unraid's RAM disk, 128 MB), its biggest
 *            files and how much they grew since the last tour; every
 *            container's docker log (only its size, by stat — never read
 *            here) and whether Docker's log rotation is set (docker.cfg, and
 *            per container the limit it was created with)
 *   errors   over her list (files in RAM, dmesg, every container's docker
 *            logs, a stopped one's too): lines that look like an error or a warning (logsLevel()),
 *            similar ones as one kind (logsNormalize()) with a count,
 *            first/last seen and the newest line as a sample — since the last
 *            tour (by file offset, docker logs --since), on the first tour the
 *            last 24 hours. At most LOGS_TOUR_BYTES of a file (a file read
 *            from its newest part that may hold older lines of that span, cut
 *            or in its older copy, is marked `partial`), LOGS_TOUR_DOCKER
 *            lines of a container.
 *            Unraid's syslog on top by her syslog rules (from Enhanced Log):
 *            every line read in a category, counted per category; noise
 *            ("skip") counts neither as an error nor as a warning.
 * The result goes to data/logs-tour.json (the page reads it as the part
 * "tour"), with what the next tour needs (offsets, sizes) under "memory".
 * The team lead's check (/var/log's fill level) only asks statvfs.
 * «Who fills /var/log?» (logs-flood.php): her look every minute in her tick — from 80 % which file fills it and with
 * what, who is behind nginx's lines of this server itself; at 90 % one notification.
 */

const LOGS_MAX_LINES = 10000;
const LOGS_MAX_BYTES = 8 * 1024 * 1024;     // read at most this much from the end of a file
const LOGS_MAX_CHUNK = 1024 * 1024;         // and at most this much new text per follow step

const LOGS_VARLOG        = '/var/log';
const LOGS_VARLOG_WARN   = 60;              // % full: recommended to look
const LOGS_VARLOG_FULL   = 80;              // % full: still to do
const LOGS_DOCKER_CFG    = '/boot/config/docker.cfg';
const LOGS_TOUR_BYTES    = 4 * 1024 * 1024; // a tour reads at most this much of a file (the newest part)
const LOGS_TOUR_DOCKER   = 3000;            // and at most this many lines of a container's docker logs
const LOGS_TOUR_FIRST    = 86400;           // the first tour looks back this far
const LOGS_TOUR_TOP      = 8;               // kinds of lines kept per source (the most frequent)
const LOGS_TOUR_KINDS    = 3000;            // kinds told apart per source while counting (beyond: "other")
const LOGS_TOUR_SAMPLE   = 400;             // bytes of a sample line
const LOGS_TOUR_FILES    = 12;              // biggest files of /var/log shown
const LOGS_TOUR_WALK     = 5000;            // files looked at in /var/log at most
const LOGS_TOUR_MAX_RUN  = 300;             // seconds: a tour that takes longer is stopped
const LOGS_TOUR_PARALLEL = 4;               // docker logs at once
// her tour on schedule (2026-10-29): the plugin's cron file calls `job.sh logs` → `php agent.php job logs-tour-due`, which
// leaves this mark in RAM; her tick starts the tour as «Tour» does, marked `scheduled`: a log on a sleeping disk is left out
const LOGS_TOUR_DUE     = 'logs-tour.due';
const LOGS_TOUR_DUE_MAX = 6 * 3600;         // a mark older than this (the agent away all that time) goes unread

/*
 * What looks like an error or a warning — she only recognises words (the
 * page colours lines the same way, public/desks/logs/desk.js):
 *   1. A line that names its own level goes by that name (in its first 300
 *      characters): level=warn, lvl=eror, "level":"info", [ERROR], [notice],
 *      | WARNING |, libvirt's ": error : ", the Unraid API's "[11:15:34 WARN",
 *      "PHP Warning:", and an upper-case word of its own (INF, WRN, ERR, LOG,
 *      INFO, WARNING, ERROR …) — error, err, eror, fatal, ftl, crit(ical),
 *      alert, emerg, panic, "fatal error", "parse error" count as error; warn,
 *      warning, wrn as warning; info, debug, notice, trace, log, verbose,
 *      deprecated not at all.
 *   2. Every other line by its words (case-insensitive, whole words):
 *      error   error, err, eror, fail, fails, failed, failure, fatal, panic,
 *              crit, critical, emerg, segfault, denied, oops, call trace,
 *              I/O error, traceback, exception, an exit code or status other
 *              than 0, and "Fehler" (Jack Emby's tools write German)
 *      warn    warn, warning, wrn, timeout, timed out, retry, retrying, "Warnung"
 *      Not counted (taken out before looking): "0 errors", "no warnings",
 *      "0 failed", "errors: 0", "failed=0", "error.log", "errors=remount-ro",
 *      and rsync's lists of files (">f+++++++++ name" — names, not news).
 *   3. dmesg on top: what the kernel itself marks as err (and worse) or warn.
 */
const LOGS_OWN_LEVEL = '/\b(?:level|lvl|severity)"?\s*[=:]\s*"?([a-z]+)|\[([a-z]+)\]|\|\s*([a-z]+)\s*\||:\s([a-z]+)\s:\s|^\[\d\d:\d\d:\d\d\s([a-z]+)\s|\bPHP (fatal error|parse error|warning|notice|deprecated)\b/i';
const LOGS_OWN_UPPER = '/(?:^|\s)(INF|WRN|ERR|DBG|FTL|TRC|LOG|INFO|WARN|WARNING|ERROR|DEBUG|FATAL|VERBOSE|NOTICE|CRITICAL)(?=[\s:\]])/';
const LOGS_LEVEL_NAMES = ['error' => 'error', 'err' => 'error', 'eror' => 'error', 'fatal' => 'error', 'ftl' => 'error', 'crit' => 'error',
                          'critical' => 'error', 'alert' => 'error', 'emerg' => 'error', 'panic' => 'error', 'fatal error' => 'error',
                          'parse error' => 'error', 'warn' => 'warn', 'warning' => 'warn', 'wrn' => 'warn',
                          'info' => '', 'inf' => '', 'debug' => '', 'dbug' => '', 'dbg' => '', 'notice' => '', 'trace' => '', 'trc' => '',
                          'log' => '', 'verbose' => '', 'deprecated' => ''];
const LOGS_NOT_COUNTED = '/^[<>ch.*][fdlsp][.+?a-z]{9,10}\s.*|\b(?:0|no|zero|without|keine?)\s+(?:errors?|warnings?|failures?|failed|fehler|warnungen)\b|\b(?:errors?|warnings?|failures?|failed|fehler|err|warn)\s*[=:]\s*(?:0|none|nil|null|false)\b|\berror\.log\b|\berrors=remount-ro\b/i';
const LOGS_ERROR = '/\b(?:error|err|eror|fail(?:s|ed|ure)?|fatal|panic|crit(?:ical)?|emerg|segfault|denied|oops|call trace|i\/o error|traceback|exception|fehler)\b|\bexit(?:ed)?(?: with)? (?:code|status)[ =:]*[1-9]/i';
const LOGS_WARN  = '/\b(?:warn(?:ing)?|wrn|timeout|timed out|retry|retrying|warnung)\b/i';

/*
 * Unraid's syslog on top: her syslog rules (logs-rules/syslog_match.conf, from Enhanced Log by dlandon and
 * Derek Kaser, GPL-3.0-or-later) sort every line into a category — the first rule that matches decides, as
 * there (logsRules()). Only for Unraid's own syslog (and its older part): the rules know Unraid's lines.
 *   skip              noise: neither an error nor a warning, whatever its words say
 *   error             an error, also when her words find nothing ("ata11.00: disabled")
 *   minor, warning    at least a warning (" limiting speed to UDMA/133")
 *   every other one   only says what the line is about; her words decide as before
 * The rule file's names => the office's ids (lang cat.<id>); a rule of another category is left out.
 */
const LOGS_RULES_FILE = __DIR__ . '/logs-rules/syslog_match.conf';

// Unraid's syslog of the last start, kept on the flash (read like the syslog, by her rules)
const LOGS_SYSLOG_PREVIOUS = '/boot/logs/syslog-previous';
/**
 * The plugins' own logs: what a plugin names for Enhanced Log (by dlandon and Derek Kaser) —
 * /usr/local/emhttp/plugins/<plugin>/enhanced-log.json, {"files": [globs]}, as its plugin logs page reads them — and the
 * ones she knows herself (the same globs from those plugins' files, for a version without one). Only plain files under
 * /var/log, never a link or a compressed one; at most LOGS_PLUGIN_MAX.
 */
const LOGS_PLUGIN_JSON     = 'enhanced-log.json';
const LOGS_PLUGIN_MAX      = 100;
const LOGS_PLUGIN_KNOWN    = [
    'tailscale'          => ['/var/log/tailscale.log', '/var/log/tailscale.log.*', '/var/log/tailscale-utils.log', '/var/log/tailscale-utils.log.*'],
    'plugin-diagnostics' => ['/var/log/plugin-diagnostics.log', '/var/log/plugin-diagnostics.log.*'],
    'file.activity'      => ['/var/log/fileactivity-watcher.log', '/var/log/fileactivity-watcher.log.*', '/var/log/fileactivity.log', '/var/log/fileactivity-error.log'],
    'labelman'           => ['/var/log/labelman*'],
];
const LOGS_RULE_CATS  = ['skip' => 'skip', 'error' => 'error', 'warning' => 'warning', 'minor issue' => 'minor',
                         'drive related' => 'drive', 'file system' => 'fs', 'array' => 'array', 'network' => 'network',
                         'login' => 'login', 'system' => 'system', 'lime tech' => 'limetech', 'emhttp' => 'emhttp'];
const LOGS_RULE_LEVEL = ['error' => 'error', 'warning' => 'warn', 'minor' => 'warn'];
const LOGS_RULE_KINDS = 15;             // kinds of the syslog kept per category for her «Syslog» (the newest, and the most frequent)
const LOGS_NO_RULE_KINDS = 1000;        // kinds of what no rule knows told apart while counting (they never crowd out the rest)

/*
 * Her own rules and hide words (her page «Set up», data/logs-mine.json): a rule is a regex (as Enhanced Log's, without
 * delimiters, case-insensitive) and one of her categories — her own rules come FIRST, then the shipped ones (as Enhanced
 * Log really applies its custom rules, LogReader.php, whatever its help says). A hide word: a syslog line whose message
 * contains it (case-sensitive, as rsyslog's «:msg, contains» there) counts as noise — it stays in the syslog, she only
 * hides it in her view; hide words come before every rule. Checked before saving (logsMineRuleWhy(), logsMineHideWhy()):
 * PCRE compiles it, it doesn't fit an empty line (or every line would be one kind), it doesn't run away on a long line,
 * a browser's RegExp reads it the same; never a shell. Only what the user types (2026-10-19: no take-over from another
 * plugin's settings — what an earlier version took over stays hers, its marks and keys kept in the file as they are).
 */
const LOGS_MINE_MAX    = 100;           // own rules at most, and as many hide words
const LOGS_MINE_LEN    = 300;           // bytes of a rule's regex or a hide word at most
// what a browser's RegExp reads otherwise (inline flags, \A \z \h …, POSIX classes, possessive) — the page colours by the same rules
const LOGS_MINE_PCRE_ONLY = '~\(\?[a-z]|\\\\[AzZhGKQ]|\[\[:|[*+?}]\+~';
// a syslog line's message (what rsyslog calls msg): after its time, the server's name and its tag
const LOGS_SYSLOG_MSG = '/^[A-Z][a-z]{2}\s+\d{1,2}\s+\d\d:\d\d:\d\d\s+\S+\s+[^:]*:(.*)$/s';
// why a rule or a hide word is refused => its error (lang errors.logs_mine_<why>; the same words list what she left out)
const LOGS_MINE_PROBLEMS = ['empty' => 'logs_mine_empty', 'long' => 'logs_mine_long', 'form' => 'logs_mine_form', 'regex' => 'logs_mine_regex',
                            'every' => 'logs_mine_every', 'slow' => 'logs_mine_slow', 'browser' => 'logs_mine_browser', 'short' => 'logs_mine_short'];
// her instant sort («Syslog» without a fresh tour): the newest lines of the syslog, at once (RAM only)
const LOGS_SORT_LINES = 5000;

/*
 * A time at the start of a line (first/last seen, the first tour's 24 hours):
 * ISO-like (the office, the engine, EmbyCache, libvirt, nginx, Samba, docker
 * --timestamps), syslog's "Oct  5 22:52:00", dmesg -T's
 * "[Sun Oct  5 22:52:31 2026]", PHP's "[05-Oct-2026 20:52:40 Europe/Berlin]".
 */
const LOGS_TIME_ISO    = '/^\[?(\d{4})[-\/](\d\d)[-\/](\d\d)[T ](\d\d:\d\d:\d\d)(?:[.,]\d+)?(Z|\s?[+-]\d\d:?\d\d)?(?:,\s*\d+)?\]?\s*/';
const LOGS_TIME_SYSLOG = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s+/';
const LOGS_TIME_DMESG  = '/^\[(?:[A-Z][a-z]{2}\s+)?([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s+(\d{4})\]\s*/';
const LOGS_TIME_PHP    = '/^\[(\d\d)-([A-Z][a-z]{2})-(\d{4})\s+(\d\d:\d\d:\d\d)(?:\s+([A-Za-z_\/+-]+))?\]\s*/';

require_once __DIR__ . '/logs-filters.php';      // her saved filters (data/logs-filters.json), applied only by a click
require_once __DIR__ . '/logs-diskwakes.php';        // «Who woke my disks?» (data/logs-wakes.json): the sampler in her tick
require_once __DIR__ . '/logs-flood.php';          // «Who fills /var/log?» (RAM): her look every minute, from 80 % the case

desk('logs', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'start'   => fn () => logsTourRecover(),
    'tick'    => function (): void {
        logsScheduledTick();
        logsTourTick();
        logsWakeTick();
        logsFloodTick();
    },
    'actions' => [
        'refresh'     => fn (array $r) => ['ok' => true, 'state' => logsScan()],
        'read'        => fn (array $r) => logsRead(textField($r, 'source'), optInt($r, 'lines', 500, 1) ?? 500, optInt($r, 'offset', null)),
        'tour'        => fn (array $r) => logsTourStart(),
        'schedule'    => fn (array $r) => logsSetSchedule(cronField($r)),
        'sort'        => fn (array $r) => logsSortNow(),
        // her «Set up»: each answers with her new state (the page draws it, her rules count from now on)
        'rule_add'    => fn (array $r) => logsMineRuleAdd(textField($r, 're'), textField($r, 'cat')) + ['state' => logsScan()],
        'rule_remove' => fn (array $r) => logsMineRemove('rules', textField($r, 'id')) + ['state' => logsScan()],
        'hide_add'    => fn (array $r) => logsMineHideAdd(textField($r, 'text')) + ['state' => logsScan()],
        'hide_remove' => fn (array $r) => logsMineRemove('hide', textField($r, 'id')) + ['state' => logsScan()],
        // her saved filters: what a list's bar has set, under a name (logs-filters.php) — the answer brings her new state
        'filter_save'   => fn (array $r) => logsFilterSave(textField($r, 'list'), textField($r, 'name'), $r) + ['state' => logsScan()],
        'filter_rename' => fn (array $r) => logsFilterRename(textField($r, 'id'), textField($r, 'name')) + ['state' => logsScan()],
        'filter_remove' => fn (array $r) => logsFilterRemove(textField($r, 'id')) + ['state' => logsScan()],
        // «Who woke my disks?»: her switch (on unless switched off), kept on the server
        'wakes_watch'   => fn (array $r) => logsWakeWatchSet(boolField($r, 'on')) + ['state' => logsScan()],
    ],
    'jobs'    => ['logs-tour' => fn (array $args) => logsTourRun(in_array('scheduled', $args, true)), 'logs-tour-due' => fn (array $args) => logsTourDue(),
                  'logs-wake-name' => fn (array $args) => logsWakeNameRun(),
                  'logs-flood-catch' => fn (array $args) => logsFloodCatchRun()],
    'checks'  => fn (): array => array_merge(logsChecks(), logsFloodChecks()),
    'metrics' => fn (): array => array_merge(logsMetrics(), logsWakeMetrics()),
]);

/**
 * Ms. Protocolli's numbers for Prometheus (lib/metrics.php, once a minute):
 * from her last tour (data/logs-tour.json). /var/log's fill level comes from
 * the Node Exporter itself (node_filesystem_*{mountpoint="/var/log"}).
 */
function logsMetrics(?string $file = null): array
{
    $t = metricsCached($file ?? logsTourFile(), function (string $f): ?array {
        $j = readJson($f);
        return $j && isset($j['time']) ? ['time' => (int) $j['time'], 'errors' => (int) ($j['errors'] ?? 0), 'warnings' => (int) ($j['warnings'] ?? 0),
            'since' => (int) ($j['since'] ?? 0), 'docker' => !empty($j['docker']['enabled']) ? (int) ($j['docker']['total'] ?? 0) : null] : null;
    });
    if ($t === null) {
        return [];
    }
    $out = [
        metricsGauge('uso_logs_tour_lines', 'Lines that looked like an error or a warning in Ms. Protocolli\'s last tour (since the tour before)',
            [[['level' => 'error'], $t['errors']], [['level' => 'warning'], $t['warnings']]]),
        metricsGauge('uso_logs_tour_since_timestamp_seconds', 'Where her last tour started counting (the tour before, or 24 hours back)', $t['since']),
        metricsGauge('uso_logs_last_tour_timestamp_seconds', 'When Ms. Protocolli last made her tour', $t['time']),
    ];
    if ($t['docker'] !== null) {
        $out[] = metricsGauge('uso_logs_container_log_bytes', 'All containers\' docker logs together, at her last tour', $t['docker']);
    }
    return $out;
}

/**
 * Every source that can be read here: id => [group, label, kind, target, label param].
 * kind "file" (target: path) or "docker" (target: container) or "dmesg".
 */
function logsSources(): array
{
    $s = [];
    $file = function (string $id, string $group, string $label, string $path, string $param = '') use (&$s): void {
        if (is_file($path)) {
            $s[$id] = ['group' => $group, 'label' => $label, 'kind' => 'file', 'target' => $path, 'param' => $param];
        }
    };

    // the office
    $file('agent', 'office', 'agent', AGENT_LOG);
    $file('agent.1', 'office', 'agent_old', AGENT_LOG . '.1');
    $file('backup:latest', 'office', 'backup_latest', DATA_DIR . '/unraid-backup/logs/latest.log');   // the engine's link to its newest log
    $backupLogs = glob(DATA_DIR . '/unraid-backup/logs/*.log') ?: [];
    usort($backupLogs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice(array_filter($backupLogs, fn ($f) => basename($f) !== 'latest.log'), 0, 30) as $f) {
        $file('backup:' . basename($f), 'office', 'backup_log', $f, basename($f, '.log'));
    }
    $file('embycache', 'office', 'embycache', DATA_DIR . '/mediacache/logs/mediacache.log');
    $file('embycache-run', 'office', 'embycache_run', DATA_DIR . '/mediacache/office-output.txt');
    $file('gather', 'office', 'gather', DATA_DIR . '/gather/consolidate.log');
    // the partner door (agent/partner-door.php, agent/lib/partner.php partnerAppend()): its log, the older part, and what
    // it logged in RAM while the array was stopped
    $file('partner:door', 'office', 'partner_door', DATA_DIR . '/partner/door.log');
    $file('partner:door.1', 'office', 'partner_door_old', DATA_DIR . '/partner/door.log.1');
    $file('partner:door-ram', 'office', 'partner_door_ram', RUN_DIR . '/partner/door.log');

    // what routers send to Unraid's syslog server (agent/lib/watchnet.php): every sender's file, only while its share is awake
    if (function_exists('watchnetLogFiles')) {
        foreach (watchnetLogFiles() as $id => $f) {
            $file($id, 'unraid', $f['old'] ? 'router_old' : 'router', $f['path'], $f['sender']);
        }
    }

    // Unraid
    $file('syslog', 'unraid', 'syslog', '/var/log/syslog');
    $file('syslog.1', 'unraid', 'syslog_old', '/var/log/syslog.1');
    $file('syslog-previous', 'unraid', 'syslog_previous', LOGS_SYSLOG_PREVIOUS);      // the last start's syslog, kept on the flash
    $s['dmesg'] = ['group' => 'unraid', 'label' => 'dmesg', 'kind' => 'dmesg', 'target' => '', 'param' => ''];
    $file('docker', 'unraid', 'docker', '/var/log/docker.log');
    $file('libvirt', 'unraid', 'libvirt', '/var/log/libvirt/libvirtd.log');
    foreach (glob('/var/log/libvirt/qemu/*.log') ?: [] as $f) {
        $file('vm:' . basename($f, '.log'), 'unraid', 'vm', $f, basename($f, '.log'));
    }
    $file('nginx', 'unraid', 'nginx', '/var/log/nginx/error.log');
    foreach (glob('/var/log/samba/log.*') ?: [] as $f) {
        $file('samba:' . basename($f), 'unraid', 'samba', $f, basename($f));
    }
    $file('php', 'unraid', 'php', '/var/log/phplog');
    $file('graphql', 'unraid', 'api', '/var/log/graphql-api.log');
    $file('mail', 'unraid', 'mail', '/var/log/maillog');
    $file('compose', 'unraid', 'compose', '/var/log/compose.manager.log');
    $file('parity', 'unraid', 'parity', '/boot/config/parity-checks.log');

    // the plugins' own logs: what each plugin names in its enhanced-log.json (Enhanced Log's way), and the ones she knows
    foreach (logsPluginLogs() as $id => $f) {
        $s[$id] = ['group' => 'plugins', 'label' => 'plugin_log', 'kind' => 'file', 'target' => $f['path'], 'param' => $f['name']];
    }

    // User Scripts: what each script printed last
    foreach (glob('/tmp/user.scripts/tmpScripts/*/log.txt') ?: [] as $f) {
        $name = basename(dirname($f));
        $file('us:' . $name, 'userscripts', 'user_script', $f, $name);
    }

    // every container's docker logs
    foreach (houseContainers() as $c) {
        $s['container:' . $c['name']] ??= ['group' => 'containers', 'label' => 'container', 'kind' => 'docker', 'target' => $c['name'], 'param' => $c['name']];
    }
    return $s;
}

/**
 * The plugins' logs (LOGS_PLUGIN_JSON, LOGS_PLUGIN_KNOWN): id plugin:<path below /var/log> => path, «<plugin>: <file>»;
 * a glob is taken only as /var/log/…, a file only plain (lstat) and still below /var/log by its real path, never .gz
 */
function logsPluginLogs(string $plugins = '/usr/local/emhttp/plugins', string $varlog = LOGS_VARLOG): array
{
    $globs = [];
    foreach (glob("$plugins/*/" . LOGS_PLUGIN_JSON) ?: [] as $json) {
        $st = @lstat($json);
        $data = $st && ($st['mode'] & 0170000) === 0100000 && $st['size'] <= 65536 ? json_decode((string) @file_get_contents($json), true) : null;
        foreach (is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [] as $g) {
            if (is_string($g)) {
                $globs[basename(dirname($json))][] = $g;
            }
        }
    }
    foreach (LOGS_PLUGIN_KNOWN as $plugin => $list) {
        if (is_dir("$plugins/$plugin")) {
            $globs[$plugin] = array_merge($globs[$plugin] ?? [], array_map(fn ($g) => preg_replace('#^/var/log(?=/)#', $varlog, $g), $list));
        }
    }
    $out = [];
    $root = rtrim((string) realpath($varlog), '/') . '/';
    foreach ($globs as $plugin => $list) {
        foreach (array_unique($list) as $g) {
            $g = preg_replace('#^/var/log(?=/)#', $varlog, $g);
            if (!str_starts_with($g, "$varlog/") || str_contains($g, '..') || preg_match('/[\x00-\x1F\x7F]/', $g) || strlen($g) > 300) {
                continue;
            }
            foreach (glob($g) ?: [] as $f) {
                $st = @lstat($f);
                $real = (string) realpath($f);
                if (!$st || ($st['mode'] & 0170000) !== 0100000 || !str_starts_with($real, $root) || str_ends_with($f, '.gz') || count($out) >= LOGS_PLUGIN_MAX) {
                    continue;
                }
                $rel = substr($real, strlen($root));
                $out['plugin:' . $rel] ??= ['path' => $real, 'name' => mb_substr("$plugin: $rel", 0, 120)];
            }
        }
    }
    ksort($out);
    return $out;
}

/** The list for the page (no paths of anything but files), a word for the reception, /var/log's fill level */
function logsScan(): array
{
    $list = [];
    foreach (logsSources() as $id => $src) {
        $list[] = ['id' => $id, 'group' => $src['group'], 'label' => $src['label'], 'param' => $src['param'],
                   'path' => $src['kind'] === 'file' ? dataPathUser($src['target']) : null,     // the data folder as the user set it
                   'size' => $src['kind'] === 'file' ? (int) @filesize($src['target']) : null,
                   'time' => $src['kind'] === 'file' ? (@filemtime($src['target']) ?: null) : null];
    }
    logsRuleSet(true);
    // how loud is the syslog lately? (the last 1000 lines, by her words and her syslog rules; she counts, she doesn't judge)
    $errors = $warnings = 0;
    foreach (logsTail('/var/log/syslog', 1000) as $line) {
        [$level] = logsSyslogLevel($line);
        $errors += $level === 'error' ? 1 : 0;
        $warnings += $level === 'warn' ? 1 : 0;
    }
    // her syslog rules for the page (it colours and filters the syslog's lines by them, as the tour counts): regex,
    // category — her own first, then the shipped ones; the hide words before them all
    $set = logsRuleSet();
    $rules = array_map(fn (array $r): array => [substr($r[0], 1, -2), $r[1]], $set['rules']);
    $mine = logsMine();
    $state = ['time' => time(), 'sources' => $list, 'syslog' => ['errors' => $errors, 'warnings' => $warnings],
              'varlog' => logsVarlog(), 'rules' => $rules, 'hide' => $set['hide'],
              'mine' => ['rules' => array_map(fn (array $r): array => array_diff_key($r, ['raw' => 1]), $mine['rules']),
                         'hide' => array_map(fn (array $h): array => array_diff_key($h, ['raw' => 1]), $mine['hide']),
                         'time' => $mine['time'], 'max' => LOGS_MINE_MAX],
              // «Who woke my disks?»: the rotating disks there are, her switch, since when she watches, the wakes of the last days
              'wakes' => logsWakesForPage(),
              // «Who fills /var/log?»: her last look (growth per file), from 80 % the case and who is behind it
              'flood' => logsFloodForPage(),
              // her saved filters (never applied by themselves); for the time filter: the server's last start and its
              // offset from UTC (the reader's lines carry the server's local time)
              'filters' => logsFiltersForPage(), 'boot' => logsBootTime(), 'tz' => (int) date('Z'),
              // her tour on schedule: the cron line and the last one (Office.scheduleTile)
              'schedule' => logsSchedule()];
    writeAtomic(deskFile('logs'), jsonEncode($state));
    return $state;
}

/** error / warn / '' — by the line's own level or the word lists above (she doesn't know what they mean) */
function logsLevel(string $line): string
{
    $head = substr($line, 0, 300);
    foreach ([LOGS_OWN_LEVEL, LOGS_OWN_UPPER] as $pattern) {
        preg_match_all($pattern, $head, $hits, PREG_SET_ORDER);
        foreach ($hits as $hit) {
            $name = strtolower((string) end($hit));         // the alternative that matched is the last group filled
            if (isset(LOGS_LEVEL_NAMES[$name])) {
                return LOGS_LEVEL_NAMES[$name];
            }
        }
    }
    $text = preg_replace(LOGS_NOT_COUNTED, ' ', $line) ?? $line;
    if (preg_match(LOGS_ERROR, $text)) {
        return 'error';
    }
    return preg_match(LOGS_WARN, $text) ? 'warn' : '';
}

/**
 * Her syslog rules, in their order: [[pattern, category id], …] — read once. As Enhanced Log reads them
 * (LogReader.php): one rule a line, "<regex>", "<category>" in CSV quotes, # and empty lines aren't rules, a
 * regex PCRE refuses is left out; the pattern is /<regex>/i. A category the office doesn't know is left out.
 * @return list<array{0: string, 1: string}>
 */
function logsRules(?string $file = null): array
{
    static $cache = [];
    $file ??= LOGS_RULES_FILE;
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $rules = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $f = str_getcsv($line, ',', '"', '');
        $regex = (string) ($f[0] ?? '');
        $cat = LOGS_RULE_CATS[strtolower(trim((string) ($f[1] ?? '')))] ?? null;
        if ($regex === '' || $cat === null || @preg_match("/$regex/i", '') === false) {
            continue;
        }
        $rules[] = ["/$regex/i", $cat];
    }
    return $cache[$file] = $rules;
}

/** The category of a syslog line by her rules — her own first, then the shipped ones (the first that matches), '' when none does */
function logsRule(string $line, ?array $rules = null): string
{
    foreach ($rules ?? logsRuleSet()['rules'] as [$pattern, $cat]) {
        if (preg_match($pattern, $line)) {
            return $cat;
        }
    }
    return '';
}

/**
 * A syslog line: [level, category] — the category by her rules, the level by her words, raised by the
 * rules (error, minor/warning) and none at all for skip. She never lowers what her words found.
 * @return array{0: string, 1: string}
 */
function logsSyslogLevel(string $line, ?string $level = null): array
{
    $level ??= logsLevel($line);
    $set = logsRuleSet();
    if ($set['hide'] && logsHidden($line, $set['hide'])) {
        return ['', 'skip'];            // a hide word of hers: noise, before every rule
    }
    $cat = logsRule($line, $set['rules']);
    if ($cat === 'skip') {
        return ['', $cat];
    }
    $byRule = LOGS_RULE_LEVEL[$cat] ?? '';
    return [$level === 'error' || $byRule === '' ? $level : ($byRule === 'error' ? 'error' : ($level ?: 'warn')), $cat];
}

/** Which sources her syslog rules are for: Unraid's own syslog and its older part */
function logsRulesFor(string $id): bool
{
    return in_array($id, ['syslog', 'syslog.1', 'syslog-previous'], true);
}

// ===================================================================== her own rules and hide words

/** When the server started (its time filter's «since the last start»): from /proc/uptime — null when it can't be read */
function logsBootTime(?string $file = null): ?int
{
    $up = (string) @file_get_contents($file ?? '/proc/uptime');
    return preg_match('/^(\d+)(?:\.\d+)?\s/', $up, $m) ? time() - (int) $m[1] : null;
}

function logsMineFile(): string
{
    return $GLOBALS['logsMineFile'] ?? DATA_DIR . '/logs-mine.json';      // tests: a file of their own
}

/**
 * Every rule she sorts by — her own first, then the shipped ones ([[pattern, category], …]) — and her hide words:
 * built once a process (a tour, an instant sort, a look); $reset after her own changed (or to look again).
 * @return array{rules: list<array{0: string, 1: string}>, hide: list<string>}
 */
function logsRuleSet(bool $reset = false): array
{
    static $set = null;
    if ($reset) {
        $set = null;
    }
    if ($set === null) {
        $mine = logsMine();
        $own = array_map(fn (array $r): array => ['/' . $r['re'] . '/i', $r['cat']], $mine['rules']);
        $set = ['rules' => array_merge($own, logsRules()), 'hide' => array_column($mine['hide'], 'text')];
    }
    return $set;
}

/** Does a hide word fit this syslog line? In its message (after time, server and tag — rsyslog's msg), case-sensitive */
function logsHidden(string $line, array $hide): bool
{
    $msg = preg_match(LOGS_SYSLOG_MSG, $line, $m) ? $m[1] : $line;
    foreach ($hide as $h) {
        if (str_contains($msg, $h)) {
            return true;
        }
    }
    return false;
}

/** Why a regex can't be her rule ('' = it can): its code also names the error (errors.logs_mine_<why>) */
function logsMineRuleWhy(string $re): string
{
    if (trim($re) === '') {
        return 'empty';
    }
    if (strlen($re) > LOGS_MINE_LEN) {
        return 'long';
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $re) || !mb_check_encoding($re, 'UTF-8')) {
        return 'form';
    }
    $pattern = "/$re/i";
    $fits = @preg_match($pattern, '');
    if ($fits === false) {
        return 'regex';
    }
    if ($fits === 1 || @preg_match($pattern, ' ') === 1) {
        return 'every';             // it fits an empty line: every line would be of its kind
    }
    if (@preg_match($pattern, str_repeat('a', 3000) . str_repeat(' 0', 1000) . '!') === false) {
        return 'slow';              // it runs away on a long line (PCRE gives up)
    }
    return preg_match(LOGS_MINE_PCRE_ONLY, $re) ? 'browser' : '';
}

/** Why a text can't be her hide word ('' = it can) */
function logsMineHideWhy(string $text): string
{
    if (strlen(trim($text)) < 2) {
        return 'short';
    }
    if (strlen($text) > LOGS_MINE_LEN) {
        return 'long';
    }
    return preg_match('/[\x00-\x1f\x7f]/', $text) || !mb_check_encoding($text, 'UTF-8') ? 'form' : '';
}

function logsMineId(string $kind, string $text, string $cat = ''): string
{
    return substr(md5("$kind\n$text\n$cat"), 0, 12);
}

/**
 * Her own, as kept: rules [{id, re, cat}], hide words [{id, text}] — only entries in exactly their shape (and a rule
 * only while it still passes her check); what she doesn't know stays in the file ($mine['keep'], logsMineWrite()), so do
 * an entry's other keys (raw: an earlier version's `from`) and the file's (an earlier version's `el`); time: the last change.
 */
function logsMine(): array
{
    $raw = readJson(logsMineFile()) ?? [];
    $mine = ['rules' => [], 'hide' => [], 'keep' => ['rules' => [], 'hide' => []],
             'time' => is_int($raw['time'] ?? null) ? $raw['time'] : null, 'raw' => $raw];
    $cats = array_values(LOGS_RULE_CATS);
    $ids = [];
    foreach (is_array($raw['rules'] ?? null) ? array_values($raw['rules']) : [] as $r) {
        $ok = is_array($r) && is_string($r['re'] ?? null) && is_string($r['cat'] ?? null) && in_array($r['cat'], $cats, true)
            && logsMineRuleWhy($r['re']) === '';
        $id = $ok ? logsMineId('rule', $r['re']) : '';
        if (!$ok) {
            $mine['keep']['rules'][] = $r;     // not hers to judge: kept as it is
            continue;
        }
        if (isset($ids[$id])) {
            continue;                   // twice: once is enough
        }
        $ids[$id] = true;
        $mine['rules'][] = ['id' => $id, 're' => $r['re'], 'cat' => $r['cat'], 'raw' => $r];
    }
    foreach (is_array($raw['hide'] ?? null) ? array_values($raw['hide']) : [] as $h) {
        $ok = is_array($h) && is_string($h['text'] ?? null) && logsMineHideWhy($h['text']) === '';
        $id = $ok ? logsMineId('hide', $h['text']) : '';
        if (!$ok) {
            $mine['keep']['hide'][] = $h;     // not hers to judge: kept as it is
            continue;
        }
        if (isset($ids[$id])) {
            continue;                   // twice: once is enough
        }
        $ids[$id] = true;
        $mine['hide'][] = ['id' => $id, 'text' => $h['text'], 'raw' => $h];
    }
    return $mine;
}

/** Write her own back: what she knows in its shape, what she doesn't as it was, every other key kept */
function logsMineWrite(array $mine): void
{
    $raw = $mine['raw'];
    $raw['v'] = 1;
    $raw['time'] = time();
    $raw['rules'] = array_merge(array_map(fn (array $r): array => ['re' => $r['re'], 'cat' => $r['cat']] + ($r['raw'] ?? []),
        $mine['rules']), $mine['keep']['rules']);
    $raw['hide'] = array_merge(array_map(fn (array $h): array => ['text' => $h['text']] + ($h['raw'] ?? []),
        $mine['hide']), $mine['keep']['hide']);
    writeAtomic(logsMineFile(), jsonEncode($raw));
    logsRuleSet(true);
}

/** «Set up»: a rule of her own (at the end of hers — still before every shipped one) */
function logsMineRuleAdd(string $re, string $cat): array
{
    if (!in_array($cat, array_values(LOGS_RULE_CATS), true)) {
        throw new Problem('logs_mine_cat');
    }
    $why = logsMineRuleWhy($re);
    if ($why !== '') {
        throw new Problem(LOGS_MINE_PROBLEMS[$why]);
    }
    $mine = logsMine();                 // now, not what the page showed
    $id = logsMineId('rule', $re);
    if (in_array($id, array_column($mine['rules'], 'id'), true)) {
        throw new Problem('logs_mine_twice');
    }
    if (count($mine['rules']) >= LOGS_MINE_MAX) {
        throw new Problem('logs_mine_full', ['n' => LOGS_MINE_MAX]);
    }
    $mine['rules'][] = ['id' => $id, 're' => $re, 'cat' => $cat, 'raw' => []];
    logsMineWrite($mine);
    logLine("Ms. Protocolli: a rule of her own ($cat)");
    return ['ok' => true, 'id' => $id];
}

/** «Set up»: a hide word of her own */
function logsMineHideAdd(string $text): array
{
    $why = logsMineHideWhy($text);
    if ($why !== '') {
        throw new Problem(LOGS_MINE_PROBLEMS[$why]);
    }
    $mine = logsMine();
    $id = logsMineId('hide', $text);
    if (in_array($id, array_column($mine['hide'], 'id'), true)) {
        throw new Problem('logs_mine_twice');
    }
    if (count($mine['hide']) >= LOGS_MINE_MAX) {
        throw new Problem('logs_mine_full', ['n' => LOGS_MINE_MAX]);
    }
    $mine['hide'][] = ['id' => $id, 'text' => $text, 'raw' => []];
    logsMineWrite($mine);
    logLine('Ms. Protocolli: a hide word of her own');
    return ['ok' => true, 'id' => $id];
}

/** «Set up»: a rule or a hide word out — by its id, as it is kept now */
function logsMineRemove(string $list, string $id): array
{
    $mine = logsMine();
    $left = array_values(array_filter($mine[$list], fn (array $x): bool => $x['id'] !== $id));
    if (count($left) === count($mine[$list])) {
        throw new Problem('logs_mine_gone');
    }
    $mine[$list] = $left;
    logsMineWrite($mine);
    logLine('Ms. Protocolli: ' . ($list === 'rules' ? 'a rule' : 'a hide word') . ' of her own removed');
    return ['ok' => true];
}

// ===================================================================== her instant sort

/**
 * «Syslog» without a fresh tour: the newest LOGS_SORT_LINES lines of Unraid's syslog (RAM — /var/log), sorted at once by
 * the same rules the tour uses, in the shape of the tour's syslog part — nothing kept, nothing that wakes a disk.
 */
function logsSortNow(?string $path = null): array
{
    $t0 = microtime(true);
    $path ??= '/var/log/syslog';
    logsRuleSet(true);
    $lines = array_map('logsClean', logsTail($path, LOGS_SORT_LINES));
    $acc = logsTally();
    logsTallyLines($acc, $lines, 'syslog', null, [], true);
    $acc['lines'] = count(array_filter($lines, fn (string $l): bool => trim($l) !== ''));
    $since = null;
    foreach ($lines as $l) {
        if (($since = logsLineTime($l)[0]) !== null) {
            break;
        }
    }
    $entry = logsTourEntry('syslog', ['group' => 'unraid', 'label' => 'syslog', 'param' => '', 'kind' => 'file'], $acc);
    return ['ok' => true, 'sorted' => $entry + ['time' => time(), 'since' => $since, 'max' => LOGS_SORT_LINES,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000)]];
}

/** The last $n lines of a file, reading at most LOGS_MAX_BYTES from its end */
function logsTail(string $path, int $n): array
{
    $size = (int) @filesize($path);
    $h = @fopen($path, 'r');
    if (!$h || !$size) {
        return [];
    }
    $want = min($size, LOGS_MAX_BYTES, max(65536, $n * 400));
    fseek($h, $size - $want);
    $text = (string) fread($h, $want);
    fclose($h);
    $lines = explode("\n", rtrim($text, "\n"));
    if ($want < $size) {
        array_shift($lines);            // started in the middle of a line
    }
    return array_slice($lines, -$n);
}

/** Colour codes out (containers love them) — the reader and the tour clean lines the same way */
function logsClean(string $line): string
{
    return str_contains($line, "\e") ? (preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line) ?? $line) : $line;
}

/**
 * Read a source: the last $lines lines, or — with $offset for a file — only
 * what was added since (tail -f). A file that got shorter was rotated: then
 * the last lines again, with "reset".
 */
function logsRead(string $id, int $lines, ?int $offset): array
{
    $src = logsSources()[$id] ?? null;
    if (!$src && str_starts_with($id, 'container:') && houseDockerSilent()) {
        throw new Problem('docker_silent');          // the containers' list is empty only because Docker is silent (B15)
    }
    if (!$src) {
        throw new Problem('logs_unknown', ['source' => $id]);
    }
    $lines = max(10, min(LOGS_MAX_LINES, $lines));

    if ($src['kind'] === 'file') {
        clearstatcache(true, $src['target']);
        $size = (int) @filesize($src['target']);
        if ($offset !== null && $offset <= $size) {
            $h = @fopen($src['target'], 'r');
            if (!$h) {
                throw new Problem('logs_unreadable', ['source' => $id]);
            }
            $len = min($size - $offset, LOGS_MAX_CHUNK);
            fseek($h, $size - $len);      // more than a chunk? then the newest part
            $text = $len > 0 ? (string) fread($h, $len) : '';
            fclose($h);
            // only whole lines; the rest comes with the next step
            $cut = strrpos($text, "\n");
            $text = $cut === false ? '' : substr($text, 0, $cut + 1);
            $new = $text === '' ? [] : explode("\n", rtrim($text, "\n"));
            return ['ok' => true, 'lines' => array_map('logsClean', $new), 'offset' => $size - $len + strlen($text),
                    'size' => $size, 'time' => @filemtime($src['target']) ?: null, 'follow' => 'append'];
        }
        $tail = logsTail($src['target'], $lines);
        return ['ok' => true, 'lines' => array_map('logsClean', $tail), 'offset' => $size, 'size' => $size,
                'time' => @filemtime($src['target']) ?: null, 'follow' => 'append', 'reset' => $offset !== null];
    }

    if ($src['kind'] === 'dmesg') {
        [$exit, $out, $err] = run(['dmesg', '-T'], 20);
        if ($exit !== 0) {
            throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
        }
        $all = explode("\n", rtrim($out, "\n"));
        return ['ok' => true, 'lines' => array_map('logsClean', array_slice($all, -$lines)), 'follow' => 'replace'];
    }

    // docker logs: stdout and stderr, merged by their timestamps. A page's request, answered in the agent's loop:
    // through houseDocker() — a big log may take its 30 s, but only once Docker answers (its short look first, B15)
    [$exit, $out, $err] = houseDocker(['logs', '--tail', (string) $lines, '--timestamps', $src['target']], 30);
    if ($exit === 124 && houseDockerSilent() && trim($out) === '') {
        throw new Problem('docker_silent');
    }
    if ($exit !== 0 && trim($out) === '') {
        throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
    }
    return ['ok' => true, 'lines' => array_map('logsClean', array_slice(logsDockerLines($out, $err), -$lines)), 'follow' => 'replace'];
}

/** docker logs --timestamps: stdout and stderr as one list, in the order of their timestamps */
function logsDockerLines(string $out, string $err): array
{
    $all = array_merge(array_filter(explode("\n", $out), 'strlen'), array_filter(explode("\n", $err), 'strlen'));
    sort($all, SORT_STRING);
    return $all;
}

// ===================================================================== /var/log and the team lead

/** How full /var/log is — statvfs and the mount table only, cheap enough for every check */
function logsVarlog(): array
{
    $total = @disk_total_space(LOGS_VARLOG);
    $free = @disk_free_space(LOGS_VARLOG);
    if (!$total || $free === false) {
        return ['ok' => false];
    }
    $fs = null;
    foreach (mountTable() as $m) {
        if ($m['mount'] === LOGS_VARLOG) {
            $fs = $m['fs'];
        }
    }
    $used = (int) ($total - $free);
    return ['ok' => true, 'own' => $fs !== null, 'fs' => $fs, 'total' => (int) $total, 'used' => $used,
            'pct' => (int) round($used * 100 / $total), 'warn' => LOGS_VARLOG_WARN, 'full' => LOGS_VARLOG_FULL];
}

/** For the team lead: /var/log over LOGS_VARLOG_FULL % is still to do, over LOGS_VARLOG_WARN % recommended */
function logsChecks(?array $v = null): array
{
    $v ??= logsVarlog();
    if (!$v['ok'] || !$v['own']) {
        return [];          // not a filesystem of its own (not Unraid's RAM disk): nothing that runs full by itself
    }
    $params = ['pct' => $v['pct'], 'used' => logsHuman($v['used']), 'total' => logsHuman($v['total'])];
    if ($v['pct'] >= LOGS_VARLOG_FULL) {
        return [finding('varlog', 'required', false, $params, '#/logs')];
    }
    if ($v['pct'] >= LOGS_VARLOG_WARN) {
        return [finding('varlog', 'recommended', false, $params, '#/logs')];
    }
    return [finding('varlog', 'required', true, $params, '#/logs')];
}

function logsHuman(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $x = (float) $bytes;
    while ($x >= 1024 && $i < count($units) - 1) {
        $x /= 1024;
        $i++;
    }
    return ($i ? number_format($x, $x < 10 ? 1 : 0, '.', '') : (string) $bytes) . ' ' . $units[$i];
}

// ===================================================================== the tour: running it

function logsTourFile(): string
{
    return DATA_DIR . '/logs-tour.json';
}

function logsTourErr(): string
{
    return RUN_DIR . '/logs-tour.err';
}

/** Her schedule's record (DATA_DIR/logs-schedule.json; tests: `$GLOBALS['logsScheduleFile']`): {v, last: {at, how, asleep}} */
function logsScheduleFile(): string
{
    return $GLOBALS['logsScheduleFile'] ?? DATA_DIR . '/logs-schedule.json';
}

/** php agent.php job logs-tour-due — her line in the plugin's cron file: a mark in RAM for her tick (nothing else here) */
function logsTourDue(): int
{
    @mkdir(RUN_DIR, 0700, true);
    writeAtomic(RUN_DIR . '/' . LOGS_TOUR_DUE, (string) time(), 0600, 0, 0);
    return 0;
}

/**
 * Every tick: her tour on schedule, when the cron line left its mark — one stat in RAM otherwise. Started as «Tour»
 * starts it (a process of its own, polled by logsTourTick()), marked `scheduled`; one running already (a click's): that one
 * is the tour, nothing more. Not hired, or a mark from long ago: taken away, no tour. $start, $hired: the tests' stand-ins.
 */
function logsScheduledTick(?callable $start = null, ?bool $hired = null): void
{
    $mark = RUN_DIR . '/' . LOGS_TOUR_DUE;
    if (!is_file($mark)) {
        return;
    }
    $at = (int) trim((string) @file_get_contents($mark));
    @unlink($mark);
    if (!($hired ?? in_array('logs', staffHired(), true))) {
        return;
    }
    if ($at <= 0 || time() - $at > LOGS_TOUR_DUE_MAX) {
        logLine('Ms. Protocolli: her tour on schedule from ' . ($at > 0 ? date('Y-m-d H:i', $at) : '?') . ' came too late - skipped, the next one comes');
        return;
    }
    if (!empty($GLOBALS['logsTour']) || !empty($GLOBALS['logsTourOrphan'])) {
        logLine('Ms. Protocolli: her tour on schedule - one is going already');
        return;
    }
    try {
        ($start ?? fn () => logsTourStart(true))();
    } catch (Throwable $e) {
        logsScheduleRecord(['at' => time(), 'how' => 'failed', 'asleep' => []]);
        logLine('Ms. Protocolli: her tour on schedule could not start - ' . $e->getMessage());
    }
}

/** Writes her schedule's record: the last tour on schedule (unknown keys kept) */
function logsScheduleRecord(array $last): void
{
    $file = logsScheduleFile();
    $rec = ['v' => 1, 'last' => $last] + (readJson($file) ?? []);
    writeAtomic($file, jsonEncode($rec), 0600, 0, 0);
}

/** Her schedule for the page: her line in the plugin's cron file (officeJobSchedule('logs')) and her last tour on schedule */
function logsSchedule(?string $cron = null): array
{
    $last = readJson(logsScheduleFile())['last'] ?? null;
    $ok = is_array($last) && is_int($last['at'] ?? null) && in_array($last['how'] ?? null, ['ok', 'failed', 'stopped'], true);
    return officeJobSchedule('logs', $cron ?? OFFICE_CRON)
        + ['last' => $ok ? ['at' => $last['at'], 'how' => $last['how'], 'asleep' => array_values(array_filter((array) ($last['asleep'] ?? []), 'is_string'))] : null];
}

/** Her tour on schedule set (cron) or off (null): her line in the plugin's cron file; the answer brings her state */
function logsSetSchedule(?string $cron, ?string $file = null, bool $apply = true): array
{
    $live = officeJobSetSchedule('logs', $cron, $file ?? OFFICE_CRON, $apply);
    logLine('Ms. Protocolli: her tour on schedule ' . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => logsScan()];
}

/**
 * What a tour on schedule leaves out because it sleeps (sleepingDisks(), never a look at the disk): her files on a disk or
 * pool that sleeps (watchnetBases(): /mnt/user/<share> by the share's storage, /mnt/<disk or pool>/… itself; RAM and
 * the flash never sleep) and Docker's logs while the disk under Docker's image or folder sleeps. $disks, $shares,
 * $dockerCfg: disks.ini, shares.ini, docker.cfg as read (the tests' own).
 *
 * @return array{ids: list<string>, docker: bool, bases: list<string>}
 */
function logsTourAsleep(array $sources, ?array $disks = null, ?array $shares = null, ?array $dockerCfg = null): array
{
    $disks ??= readCfg(disksIniFile(), true);
    $sleeping = watchnetSleeping($disks);
    $out = ['ids' => [], 'docker' => false, 'bases' => []];
    if (!in_array(true, $sleeping, true)) {
        return $out;                                    // nothing sleeps: nothing to leave out
    }
    $shares ??= readCfg('/var/local/emhttp/shares.ini', true);
    $asleep = function (string $path) use ($shares, $disks, $sleeping, &$out): bool {
        $b = watchnetBases($path, $shares, $disks);
        $hit = array_values(array_filter($b['bases'], fn ($base) => baseAsleep((string) $base, $sleeping)));
        $out['bases'] = array_values(array_unique(array_merge($out['bases'], $hit)));
        return (bool) $hit || !$b['known'];             // a share emhttp doesn't list: not looked at
    };
    foreach ($sources as $id => $src) {
        if ($src['kind'] === 'file' && str_starts_with((string) $src['target'], '/mnt/') && $asleep(dirname((string) $src['target']))) {
            $out['ids'][] = (string) $id;
        }
    }
    $cfg = $dockerCfg ?? readCfg(LOGS_DOCKER_CFG);
    $image = (string) ($cfg['DOCKER_IMAGE_FILE'] ?? '');
    $out['docker'] = str_starts_with($image, '/mnt/') && $asleep(rtrim($image, '/'));
    sort($out['bases'], SORT_NATURAL);
    return $out;
}

/** "tour": start one in the background (unless one runs); the page polls the part "tour" until job.running is false */
function logsTourStart(bool $scheduled = false): array
{
    $tour = readJson(logsTourFile()) ?? [];
    if (!empty($GLOBALS['logsTour']) || !empty($GLOBALS['logsTourOrphan'])) {
        return ['ok' => true, 'tour' => $tour];
    }
    $tour['job'] = ['running' => true, 'since' => time(), 'pid' => null] + ($scheduled ? ['scheduled' => true] : []);
    writeAtomic(logsTourFile(), jsonEncode($tour));
    @mkdir(RUN_DIR, 0700, true);
    $pipes = [];
    // a process of its own (niced), without the agent's open files (its lock among them)
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'logs-tour', ...($scheduled ? ['scheduled'] : [])],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', logsTourErr(), 'w']], $pipes, '/');
    if (!is_resource($process)) {
        logsTourFailed($tour['job']);
        throw new Problem('logs_tour_failed');
    }
    $GLOBALS['logsTour'] = ['process' => $process, 'since' => time(), 'scheduled' => $scheduled];
    $tour['job']['pid'] = proc_get_status($process)['pid'];
    writeAtomic(logsTourFile(), jsonEncode($tour));
    return ['ok' => true, 'tour' => $tour];
}

/** Every tick: nothing to do unless a tour runs — then a look whether it is done (or takes too long) */
function logsTourTick(): void
{
    $job = $GLOBALS['logsTour'] ?? null;
    if (!$job) {
        $pid = $GLOBALS['logsTourOrphan'] ?? null;      // a tour started before the agent restarted
        if ($pid && time() >= ($GLOBALS['logsTourOrphanLook'] ?? 0)) {
            $GLOBALS['logsTourOrphanLook'] = time() + 5;
            if (!logsTourAlive($pid)) {
                $GLOBALS['logsTourOrphan'] = null;
                logsTourRecover();
            }
        }
        return;
    }
    $status = proc_get_status($job['process']);
    if ($status['running']) {
        if (time() - $job['since'] > LOGS_TOUR_MAX_RUN && empty($job['stopped'])) {
            proc_terminate($job['process']);
            $GLOBALS['logsTour']['stopped'] = true;
            logLine('Ms. Protocolli: her tour took longer than ' . LOGS_TOUR_MAX_RUN . ' s — stopped');
        }
        return;
    }
    proc_close($job['process']);
    $GLOBALS['logsTour'] = null;
    $tour = readJson(logsTourFile()) ?? [];
    if (!empty($tour['job']['running'])) {          // it ended without writing its result
        $err = trim((string) @file_get_contents(logsTourErr(), false, null, 0, 4096));
        logLine('Ms. Protocolli: tour failed (exit ' . $status['exitcode'] . ')' . ($err !== '' ? ': ' . strtok($err, "\n") : ''));
        logsTourFailed($tour['job']);
        if (!empty($job['scheduled'])) {
            logsScheduleRecord(['at' => time(), 'how' => empty($job['stopped']) ? 'failed' : 'stopped', 'asleep' => []]);
        }
        return;
    }
    logLine(sprintf('Ms. Protocolli: tour done in %d ms (%d errors, %d warnings)',
        (int) ($tour['duration_ms'] ?? 0), (int) ($tour['errors'] ?? 0), (int) ($tour['warnings'] ?? 0)));
}

/** At the start: a tour that was running when the agent stopped is either still running (it writes its result) or gone */
function logsTourRecover(): void
{
    $tour = readJson(logsTourFile());
    if (empty($tour['job']['running'])) {
        return;
    }
    $pid = (int) ($tour['job']['pid'] ?? 0);
    if (logsTourAlive($pid)) {
        $GLOBALS['logsTourOrphan'] = $pid;
        return;
    }
    logsTourFailed($tour['job']);
}

function logsTourAlive(int $pid): bool
{
    return $pid > 1 && @posix_kill($pid, 0) && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'logs-tour');
}

/** The last result stays; only the job is marked as failed */
function logsTourFailed(array $job): void
{
    $tour = readJson(logsTourFile()) ?? [];
    $tour['job'] = ['running' => false, 'since' => $job['since'] ?? null, 'ended' => time(), 'failed' => true];
    writeAtomic(logsTourFile(), jsonEncode($tour));
}

// ===================================================================== the tour: what she does

/**
 * php agent.php job logs-tour [scheduled] — the tour itself, in a process of its own (started by "tour", or by her
 * schedule: `scheduled` — then what lies on a sleeping disk is left out, its place in the memory kept for the next tour)
 */
function logsTourRun(bool $scheduled = false): int
{
    $t0 = microtime(true);
    $prev = readJson(logsTourFile()) ?? [];
    $mem = is_array($prev['memory'] ?? null) ? $prev['memory'] : [];
    $now = time();
    $first = !$mem || empty($prev['time']);
    $since = $first ? $now - LOGS_TOUR_FIRST : (int) $prev['time'];
    $sources = logsSources();
    $next = ['files' => [], 'sizes' => [], 'containers' => []];
    $skip = $scheduled ? logsTourAsleep($sources) : ['ids' => [], 'docker' => false, 'bases' => []];

    $varlog = logsTourVarlog($mem['sizes'] ?? [], $next['sizes'], $sources);
    if ($skip['docker']) {
        $docker = is_array($prev['docker'] ?? null) ? $prev['docker'] : ['ok' => false, 'containers' => [], 'total' => 0];
        $next['containers'] = (array) ($mem['containers'] ?? []);       // the next awake tour counts their growth from here
    } else {
        $docker = logsTourDocker($mem['containers'] ?? [], $next['containers']);
    }

    $found = [];
    foreach ($sources as $id => $src) {
        if (in_array((string) $id, $skip['ids'], true)) {
            if (isset($mem['files'][$id])) {
                $next['files'][$id] = $mem['files'][$id];               // read from there once it is awake
            }
            continue;
        }
        if ($src['kind'] === 'file' && logsTourWanted($id)) {
            // a file she knows from the last tour: from where it ended; one she doesn't (first tour, new file): by its lines' times
            $last = $mem['files'][$id] ?? null;
            $found[] = logsTourFileSource($id, $src, $last, $last === null ? $since : null, $since, $sources, $next['files']);
        } elseif ($src['kind'] === 'dmesg') {
            $found[] = logsTourDmesg($since);
        }
    }
    // Docker's logs left out while their disk sleeps: not read — never «Docker didn't answer» (null, the page says why)
    [$read, $entries] = $skip['docker'] ? [null, []] : logsTourContainers($sources, $since, houseContainersKnown());
    $found = array_merge($found, $entries);
    usort($found, fn ($a, $b) => [$b['errors'], $b['warnings'], $a['id']] <=> [$a['errors'], $a['warnings'], $b['id']]);

    $tour = [
        'time'        => $now,
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'since'       => $since,
        'first'       => $first,
        'varlog'      => $varlog,
        'docker'      => $docker,
        'containers_read' => $read,
        'sources'     => $found,
        'errors'      => array_sum(array_column($found, 'errors')),
        'warnings'    => array_sum(array_column($found, 'warnings')),
        'job'         => ['running' => false, 'since' => $prev['job']['since'] ?? $now, 'ended' => time(), 'failed' => false],
        'memory'      => $next,
    ];
    if ($scheduled) {
        // on schedule: what slept and was left out (said on her page), never read for it
        $tour['scheduled'] = true;
        $tour['asleep'] = ['sources' => $skip['ids'], 'docker' => $skip['docker'], 'bases' => $skip['bases']];
    }
    writeAtomic(logsTourFile(), jsonEncode($tour));
    if ($scheduled) {
        logsScheduleRecord(['at' => time(), 'how' => 'ok', 'asleep' => $skip['bases']]);
    }
    return 0;
}

/**
 * Every container's docker logs since $since — running or not: a stopped or restarting one's last lines (why it stopped)
 * count too (truth review T28: «nothing like that» while a crash-looping container was never read). $known: the containers
 * (houseContainersKnown()); null = Docker didn't answer: none read, and the tour says so.
 * @return array{0: bool, 1: list<array>}  read at all, the entries (a stopped one marked `stopped`)
 */
function logsTourContainers(array $sources, int $since, ?array $known): array
{
    if ($known === null) {
        return [false, []];
    }
    $running = array_column($known, 'running', 'name');
    $ids = array_keys(array_filter($sources, fn ($s) => $s['kind'] === 'docker' && isset($running[$s['target']])));
    $found = [];
    foreach (array_chunk($ids, LOGS_TOUR_PARALLEL) as $chunk) {
        $commands = [];
        foreach ($chunk as $id) {
            $commands[$id] = ['docker', 'logs', '--since', (string) $since, '--tail', (string) LOGS_TOUR_DOCKER, '--timestamps', $sources[$id]['target']];
        }
        foreach (runAll($commands, 60) as $id => [$exit, $out, $err]) {
            $acc = logsTally();
            if ($exit === 0) {
                $lines = array_map('logsClean', logsDockerLines($out, $err));
                logsTallyLines($acc, $lines, $id, null);
                $acc['lines'] = count($lines);
                $acc['partial'] = count($lines) >= LOGS_TOUR_DOCKER;
            } else {
                $acc['unreadable'] = true;
            }
            $found[] = logsTourEntry($id, $sources[$id], $acc) + ($running[$sources[$id]['target']] ? [] : ['stopped' => true]);
        }
    }
    return [true, $found];
}

/**
 * Which of her files the tour reads: every log of its own. Older copies
 * (syslog.1, agent.log.1) come with their file when it was rotated since the
 * last tour, Mr. Backupsy's older runs aren't news, the parity history on
 * the flash has no error lines.
 */
function logsTourWanted(string $id): bool
{
    return !in_array($id, ['agent.1', 'syslog.1', 'syslog-previous', 'parity'], true)
        && !preg_match('/^backup:(?!latest$)|^router\.1:|^plugin:.*\.\d+$/', $id);
}

/** An empty tally: counts, the kinds of lines (normalised => group) and what didn't fit */
function logsTally(): array
{
    return ['errors' => 0, 'warnings' => 0, 'groups' => [], 'other' => 0, 'lines' => 0, 'partial' => false,
            'skipped' => 0, 'rotated' => false, 'unreadable' => false, 'grew' => null, 'cats' => null, 'sorted' => [], 'sorted_other' => 0,
            'sorted_seq' => 0, 'sorted_no_rule' => 0, 'span_cut' => false];
}

/**
 * Count the lines that look like an error or a warning. $after: only lines
 * with a time after it (lines without one count too); $kernel: line => level
 * the kernel gave it (dmesg). The newest line of a kind is its sample; "back"
 * is how many lines followed it in what was read (to find it in the reader).
 * $rules (Unraid's syslog): every line read gets its category by her syslog
 * rules (logsSyslogLevel()), counted in "cats" (no rule: "other"); a kind
 * keeps its line's category; every line with a category (not skip) also goes
 * into "sorted" — similar ones as one kind per category, found or not.
 */
function logsTallyLines(array &$acc, array $lines, string $src, ?int $after, array $kernel = [], bool $rules = false): void
{
    $n = count($lines);
    if ($rules) {
        $acc['cats'] ??= [];
    }
    foreach ($lines as $i => $line) {
        $level = logsLevel($line);
        if ($level !== 'error' && isset($kernel[$line])) {
            $level = $kernel[$line];
        }
        $cat = null;
        if ($rules) {
            if (trim($line) === '') {
                continue;
            }
            [$time, $prefix] = logsLineTime($line);
            if ($after !== null && $time !== null && $time <= $after) {
                continue;
            }
            [$level, $cat] = logsSyslogLevel($line, $level);
            $acc['cats'][$cat ?: 'other'] = ($acc['cats'][$cat ?: 'other'] ?? 0) + 1;
            if ($cat !== 'skip') {
                logsTallySorted($acc, $line, $cat ?: 'other', $level, $time, $prefix, $src, $n - 1 - $i);      // no rule: «other», its own list
            }
            if ($level === '') {
                continue;
            }
        } else {
            if ($level === '') {
                continue;
            }
            [$time, $prefix] = logsLineTime($line);
            if ($after !== null && $time !== null && $time <= $after) {
                continue;
            }
        }
        $acc[$level === 'error' ? 'errors' : 'warnings']++;
        $key = md5(logsNormalize($line, $prefix));
        if (!isset($acc['groups'][$key])) {
            if (count($acc['groups']) >= LOGS_TOUR_KINDS) {
                $acc['other']++;
                continue;
            }
            $acc['groups'][$key] = ['level' => $level, 'count' => 0, 'first' => null, 'last' => null] + ($cat !== null ? ['cat' => $cat] : []);
        }
        $g = &$acc['groups'][$key];
        $g['count']++;
        if ($level === 'error') {
            $g['level'] = 'error';
        }
        if ($time !== null) {
            $g['first'] ??= $time;
            $g['last'] = $time;
        }
        $g['line'] = $line;
        $g['skip'] = $prefix;           // its time: the page shows the rest in the row, the whole line unfolded
        $g['src'] = $src;
        $g['back'] = $n - 1 - $i;
        unset($g);
    }
}

/** A syslog line with a category into "sorted": similar lines of one category as one kind, its newest line the sample */
function logsTallySorted(array &$acc, string $line, string $cat, string $level, ?int $time, int $prefix, string $src, int $back): void
{
    $key = $cat . ':' . md5(logsNormalize($line, $prefix));
    if (!isset($acc['sorted'][$key])) {
        if ($cat === 'other' && ($acc['sorted_no_rule'] ?? 0) >= LOGS_NO_RULE_KINDS) {
            return;                 // what no rule knows: counted in «cats», its kinds only so many
        }
        if (count($acc['sorted']) >= LOGS_TOUR_KINDS) {
            $acc['sorted_other']++;
            return;
        }
        $acc['sorted_no_rule'] = ($acc['sorted_no_rule'] ?? 0) + ($cat === 'other' ? 1 : 0);
        $acc['sorted'][$key] = ['cat' => $cat, 'level' => '', 'count' => 0, 'first' => null, 'last' => null, 'n' => 0];
    }
    $g = &$acc['sorted'][$key];
    $g['count']++;
    if ($level === 'error' || ($level === 'warn' && $g['level'] === '')) {
        $g['level'] = $level;
    }
    if ($time !== null) {
        $g['first'] ??= $time;
        $g['last'] = $time;
    }
    $g['n'] = ++$acc['sorted_seq'];     // read order: the newest first, also for lines without a time
    $g['line'] = $line;
    $g['skip'] = $prefix;
    $g['src'] = $src;
    $g['back'] = $back;
}

/**
 * What the page gets of a source: its counts and the most frequent kinds of lines, each with its newest line.
 * With her syslog rules on top (Unraid's syslog): per category the lines read ("cats") and the kinds ("sorted_kinds"),
 * and per category its newest LOGS_RULE_KINDS kinds and its most frequent as many ("sorted", newest first — her «Syslog»;
 * «other»: what no rule knows, its own list there).
 */
function logsTourEntry(string $id, array $src, array $acc): array
{
    $groups = array_values($acc['groups']);
    usort($groups, fn ($a, $b) => [$b['count'], $b['last'] ?? 0] <=> [$a['count'], $a['last'] ?? 0]);
    $top = [];
    foreach (array_slice($groups, 0, LOGS_TOUR_TOP) as $g) {
        [$g['sample'], $g['cut']] = logsCut($g['line'], LOGS_TOUR_SAMPLE);
        $g['key'] = substr(md5($id . "\n" . $g['line']), 0, 12);
        unset($g['line']);
        $top[] = $g;
    }
    $out = ['id' => $id, 'group' => $src['group'], 'label' => $src['label'], 'param' => $src['param'], 'kind' => $src['kind'],
            'errors' => $acc['errors'], 'warnings' => $acc['warnings'], 'lines' => $acc['lines'],
            'kinds' => count($groups), 'other' => $acc['other'], 'partial' => $acc['partial'], 'skipped' => $acc['skipped'],
            'rotated' => $acc['rotated'], 'span_cut' => $acc['span_cut'], 'unreadable' => $acc['unreadable'], 'grew' => $acc['grew'] ?? null, 'groups' => $top];
    if ($acc['cats'] === null) {
        return $out;
    }
    $sorted = array_values($acc['sorted']);
    // per category its newest LOGS_RULE_KINDS kinds and its most frequent as many (her «most frequent first»)
    $often = [];
    $byCount = $sorted;
    usort($byCount, fn ($a, $b) => [$b['count'], $b['n']] <=> [$a['count'], $a['n']]);
    foreach ($byCount as $g) {
        $c = $g['cat'];
        if (count($often[$c] ?? []) < LOGS_RULE_KINDS) {
            $often[$c][$g['n']] = true;
        }
    }
    usort($sorted, fn ($a, $b) => $b['n'] <=> $a['n']);
    $kinds = [];
    $kept = [];
    foreach ($sorted as $g) {
        $kinds[$g['cat']] = ($kinds[$g['cat']] ?? 0) + 1;
        if ($kinds[$g['cat']] > LOGS_RULE_KINDS && !isset($often[$g['cat']][$g['n']])) {
            continue;
        }
        [$g['sample'], $g['cut']] = logsCut($g['line'], LOGS_TOUR_SAMPLE);
        $g['key'] = substr(md5("sorted\n" . $id . "\n" . $g['line']), 0, 12);
        unset($g['line'], $g['n']);
        $kept[] = $g;
    }
    return $out + ['cats' => $acc['cats'], 'sorted' => $kept, 'sorted_kinds' => $kinds, 'sorted_other' => $acc['sorted_other']];
}

/**
 * A file since the last tour: from where it ended then (same inode, not
 * shorter) — or, when it was rotated, the rest of the old file (now
 * "<file>.1", found by its inode) and the new one from its start. A file
 * without a position (the first tour, a new file) is read from its newest
 * part, its lines counted by their time ($after: the first tour's 24 hours,
 * else since the last tour). Never more than LOGS_TOUR_BYTES per file.
 */
function logsTourFileSource(string $id, array $src, ?array $last, ?int $after, int $since, array $sources, array &$memory): array
{
    $acc = logsTally();
    $path = $src['target'];
    clearstatcache(true, $path);
    $st = @stat($path);
    if (!$st) {
        $acc['unreadable'] = true;
        return logsTourEntry($id, $src, $acc);
    }
    $from = 0;
    if ($last !== null) {
        if ((int) ($last['ino'] ?? -1) === (int) $st['ino'] && (int) ($last['size'] ?? PHP_INT_MAX) <= $st['size']) {
            $from = (int) $last['size'];
            $acc['grew'] = (int) $st['size'] - $from;          // bytes since where the last tour ended
        } else {
            $acc['rotated'] = true;
            $after = $since;            // whatever is in the new file: only what came after the last tour
            $old = @stat("$path.1");
            if ($old && (int) $old['ino'] === (int) ($last['ino'] ?? -1) && (int) ($last['size'] ?? PHP_INT_MAX) <= $old['size']) {
                $acc['grew'] = (int) $old['size'] - (int) $last['size'] + (int) $st['size'];      // the old one's rest and the new one
                $sibling = $id;         // in the reader: syslog.1, agent.1 — if it lists it
                foreach ($sources as $sid => $s) {
                    if ($s['kind'] === 'file' && $s['target'] === "$path.1") {
                        $sibling = (string) $sid;
                    }
                }
                [$lines, , $partial, $skipped] = logsTourSlice("$path.1", (int) $last['size'], (int) $old['size']);
                logsTallyLines($acc, $lines, $sibling, null, [], logsRulesFor($id));
                $acc['lines'] += count($lines);
                $acc['partial'] = $partial;
                $acc['skipped'] += $skipped;
            }
        }
    }
    [$lines, $end, $partial, $skipped] = logsTourSlice($path, $from, (int) $st['size']);
    logsTallyLines($acc, $lines, $id, $after, [], logsRulesFor($id));
    $acc['lines'] += count($lines);
    if ($last !== null) {                // the first tour reads the newest part on purpose
        $acc['partial'] = $acc['partial'] || $partial;
        $acc['skipped'] += $skipped;
    } elseif ($after !== null && logsTourSpanCut($path, $lines, $partial, $after)) {
        $acc['partial'] = true;          // … but says so when lines of its span stayed unread (W10)
        $acc['span_cut'] = true;
    }
    $memory[$id] = ['ino' => (int) $st['ino'], 'size' => $end];
    return logsTourEntry($id, $src, $acc);
}

/**
 * A file read from its newest part (the first tour, a new file): did lines of the span after $after stay unread? Either the
 * cut (more than LOGS_TOUR_BYTES) began inside the span — the first line read with a time is newer than $after — or the
 * older copy (<file>.1, never read on a tour) was written after $after (truth review W10: «the last 24 hours» while only the
 * newest 4 MB were read and syslog.1 never).
 */
function logsTourSpanCut(string $path, array $lines, bool $cut, int $after): bool
{
    if ($cut) {
        foreach ($lines as $line) {
            [$t] = logsLineTime($line);
            if ($t !== null) {
                if ($t > $after) {
                    return true;
                }
                break;
            }
        }
    }
    $older = @filemtime("$path.1");
    return $older !== false && $older > $after;
}

/**
 * Whole lines of $path from byte $from to $size — at most LOGS_TOUR_BYTES
 * (then the newest part) — and where the last whole line ends.
 * @return array{0: list<string>, 1: int, 2: bool, 3: int}  lines, end, partial, bytes skipped
 */
function logsTourSlice(string $path, int $from, int $size): array
{
    $start = max($from, $size - LOGS_TOUR_BYTES);
    $h = $size > $start ? @fopen($path, 'r') : false;
    if (!$h) {
        return [[], $from, false, 0];
    }
    fseek($h, $start);
    $text = (string) fread($h, $size - $start);
    fclose($h);
    $cut = strrpos($text, "\n");
    if ($cut === false) {               // no whole line yet: the next tour starts here again
        return [[], $start, $start > $from, $start - $from];
    }
    $lines = explode("\n", substr($text, 0, $cut));
    if ($start > $from) {
        array_shift($lines);            // began inside a line
    }
    return [array_map('logsClean', $lines), $start + $cut + 1, $start > $from, $start - $from];
}

/** dmesg -T (as the reader shows it), with the kernel's own levels on top of the words */
function logsTourDmesg(int $since): array
{
    $src = ['group' => 'unraid', 'label' => 'dmesg', 'kind' => 'dmesg', 'target' => '', 'param' => ''];
    $acc = logsTally();
    $r = runAll(['all' => ['dmesg', '-T'], 'error' => ['dmesg', '-T', '--level=emerg,alert,crit,err'], 'warn' => ['dmesg', '-T', '--level=warn']], 20);
    if ($r['all'][0] !== 0) {
        $acc['unreadable'] = true;
        return logsTourEntry('dmesg', $src, $acc);
    }
    $kernel = [];
    foreach (['warn', 'error'] as $level) {
        foreach ($r[$level][0] === 0 ? explode("\n", $r[$level][1]) : [] as $line) {
            if ($line !== '') {
                $kernel[logsClean($line)] = $level;
            }
        }
    }
    // only what came since: the newest lines (dmesg is in order, so "back" stays right for the reader)
    $lines = array_values(array_filter(array_map('logsClean', explode("\n", rtrim($r['all'][1], "\n"))),
        fn ($l) => (logsLineTime($l)[0] ?? PHP_INT_MAX) > $since));
    logsTallyLines($acc, $lines, 'dmesg', null, $kernel);
    $acc['lines'] = count($lines);
    return logsTourEntry('dmesg', $src, $acc);
}

/** /var/log: how full, the biggest files and how much they grew since the last tour (sizes by lstat; links stay out) */
function logsTourVarlog(array $before, array &$memory, array $sources): array
{
    $byPath = [];
    foreach ($sources as $id => $s) {
        if ($s['kind'] === 'file') {
            $byPath[$s['target']] ??= $id;
        }
    }
    $root = @lstat(LOGS_VARLOG);
    $files = [];
    $sum = 0;
    $stack = $root ? [LOGS_VARLOG] : [];
    while ($stack && count($files) < LOGS_TOUR_WALK) {
        $dir = array_pop($stack);
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "$dir/$name";
            $st = @lstat($path);
            if (!$st) {
                continue;
            }
            $type = $st['mode'] & 0170000;
            if ($type === 0040000 && $st['dev'] === $root['dev']) {
                $stack[] = $path;
            }
            if ($type !== 0100000 || count($files) >= LOGS_TOUR_WALK) {
                continue;               // folders, links (packages → /var/lib/pkgtools) and the like
            }
            $files[] = ['path' => $path, 'size' => (int) $st['size'], 'time' => (int) $st['mtime']];
            $sum += (int) $st['size'];
            $memory[$path] = (int) $st['size'];
        }
    }
    usort($files, fn ($a, $b) => [$b['size'], $a['path']] <=> [$a['size'], $b['path']]);
    $top = [];
    foreach (array_slice($files, 0, LOGS_TOUR_FILES) as $f) {
        $f['grew'] = isset($before[$f['path']]) ? $f['size'] - (int) $before[$f['path']] : null;
        $f['source'] = $byPath[$f['path']] ?? null;
        $top[] = $f;
    }
    return logsVarlog() + ['files' => $top, 'count' => count($files), 'sum' => $sum];
}

/**
 * Docker's container logs: per container the size of its log file(s) by
 * stat (never read here), how much they grew since the last tour, and the
 * limit it was created with; Unraid's setting (Settings → Docker → Docker
 * LOG rotation) from docker.cfg. Only while Docker answers.
 */
function logsTourDocker(array $before, array &$memory): array
{
    $cfg = readCfg(LOGS_DOCKER_CFG);
    $rotation = ['on' => strtolower($cfg['DOCKER_LOG_ROTATION'] ?? '') === 'yes',
                 'size' => ($cfg['DOCKER_LOG_SIZE'] ?? '') !== '' ? $cfg['DOCKER_LOG_SIZE'] : null,
                 'files' => ($cfg['DOCKER_LOG_FILES'] ?? '') !== '' ? $cfg['DOCKER_LOG_FILES'] : null];
    $out = ['ok' => false, 'enabled' => strtolower($cfg['DOCKER_ENABLED'] ?? 'yes') !== 'no', 'rotation' => $rotation,
            'root' => null, 'containers' => [], 'total' => 0];
    [$exit, $ids] = run(['docker', 'ps', '-aq', '--no-trunc'], 20);
    if ($exit !== 0) {
        return $out;
    }
    $out['ok'] = true;
    $ids = array_slice(array_values(array_filter(explode("\n", trim($ids)), fn ($x) => (bool) preg_match('/^[0-9a-f]{12,64}$/', $x))), 0, 500);
    if (!$ids) {
        return $out;
    }
    [, $text] = run(array_merge(['docker', 'inspect', '--format',
        "{{json .Name}}\t{{json .State.Status}}\t{{json .LogPath}}\t{{json .HostConfig.LogConfig}}"], $ids), 30);
    foreach (explode("\n", trim($text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 4) {
            continue;
        }
        $name = ltrim((string) json_decode($f[0]), '/');
        $log = (string) json_decode($f[2]);
        $config = json_decode($f[3], true);
        $opts = is_array($config['Config'] ?? null) ? $config['Config'] : [];
        $size = null;
        $time = null;
        if ($log !== '' && $log[0] === '/' && !str_contains($log, '/../')) {
            $size = 0;
            foreach (array_merge([$log], glob($log . '.*') ?: []) as $p) {      // rotated parts: <id>-json.log.1, .2.gz …
                $size += (int) @filesize($p);
                $time = max($time ?? 0, (int) @filemtime($p)) ?: null;          // its last write (the time filter)
            }
            $out['root'] ??= preg_replace('#/containers/[^/]+/[^/]+$#', '', $log);
            $memory[$name] = $size;
        }
        $out['containers'][] = [
            'name'     => $name,
            'running'  => json_decode($f[1]) === 'running',
            'driver'   => is_array($config) ? (string) ($config['Type'] ?? '') : '',
            'size'     => $size,
            'time'     => $time,
            'grew'     => $size !== null && isset($before[$name]) ? $size - (int) $before[$name] : null,
            'max_size' => is_string($opts['max-size'] ?? null) ? $opts['max-size'] : null,
            'max_file' => is_string($opts['max-file'] ?? null) ? $opts['max-file'] : null,
        ];
        $out['total'] += (int) $size;
    }
    usort($out['containers'], fn ($a, $b) => [$b['size'] ?? -1, $a['name']] <=> [$a['size'] ?? -1, $b['name']]);
    return $out;
}

// ===================================================================== the tour: lines

/**
 * The time at the start of a line, if it has one she knows, and how long
 * that part is (it stays out of the comparison).
 * @return array{0: ?int, 1: int}
 */
function logsLineTime(string $line): array
{
    $t = false;
    if (preg_match(LOGS_TIME_ISO, $line, $m)) {
        $t = strtotime("$m[1]-$m[2]-$m[3] $m[4]" . (isset($m[5]) && $m[5] !== '' ? ' ' . trim($m[5]) : ''));
    } elseif (preg_match(LOGS_TIME_SYSLOG, $line, $m)) {
        $t = strtotime("$m[1] $m[2] $m[3]");
        if ($t !== false && $t > time() + 86400) {          // no year in the syslog: December's lines read in January
            $t = strtotime("$m[1] $m[2] " . ((int) date('Y') - 1) . " $m[3]");
        }
    } elseif (preg_match(LOGS_TIME_DMESG, $line, $m)) {
        $t = strtotime("$m[1] $m[2] $m[4] $m[3]");
    } elseif (preg_match(LOGS_TIME_PHP, $line, $m)) {
        $t = strtotime("$m[1]-$m[2]-$m[3] $m[4]" . (isset($m[5]) && $m[5] !== '' ? " $m[5]" : ''));
    } else {
        return [null, 0];
    }
    return [$t === false ? null : $t, strlen($m[0])];
}

/**
 * The line as she compares it: without its leading time; file names and paths
 * (logsNormalizePaths()), UUIDs, MAC and IP addresses, hex ids, and then every
 * number replaced by a placeholder; lower case, spaces squeezed, the first 240
 * characters. Lines that only differ there are one kind.
 */
function logsNormalize(string $line, int $prefix = 0): string
{
    $s = logsNormalizePaths(substr($line, $prefix));
    $s = preg_replace([
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',     // UUIDs
        '/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i',                                     // MAC addresses
        '/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b/',                                // IPv4 (and a port)
        '/\b[0-9a-f]{1,4}(?::[0-9a-f]{0,4}){2,7}\b/i',                               // IPv6
        '/\b0x[0-9a-f]+\b/i',                                                        // hex numbers
        '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i',                      // hex ids (containers, hashes)
        '/\d+/',                                                                     // every other number: times, PIDs, sizes
        '/\s+/',
    ], ['<uuid>', '<mac>', '<ip>', '<ip>', '<hex>', '<hex>', '#', ' '], $s) ?? $s;
    return substr(strtolower(trim($s)), 0, 240);
}

/**
 * File names and paths as one placeholder, so lines that only name another file are one kind:
 * addresses with a scheme (http://…), quoted ones ("…", '…', […], `…`, “…”, ‘…’, «…») when what is
 * inside looks like a path or a file name (starts like a path; a slash and no space, or a slash and a
 * file ending; name.ext), absolute paths outside quotes — with the words up to their last slash and a
 * file name with an ending, as names may hold spaces (/mnt/user/My Film (2020)/My Film.mkv) —, relative ones with a slash
 * (Windows/System32/x.dll; not HTTP/1.1, dates or I/O) and Samba's "… for <name> with NT_STATUS_…".
 * $replace: what a path becomes — fn (string $path, string $kind): string, kind samba | url | quoted | absolute |
 * relative (the report's scrubber keeps a path's structure, agent/lib/report.php); none = '<path>' (her comparing).
 */
function logsNormalizePaths(string $s, ?callable $replace = null): string
{
    $replace ??= static fn (string $path, string $kind): string => '<path>';
    $s = preg_replace_callback('~(?<=\bfor ).+?(?= with NT_STATUS_[A-Z_]+)~', fn (array $m): string => $replace($m[0], 'samba'), $s) ?? $s;   // Samba
    $s = preg_replace_callback('~\b[a-z][a-z0-9+.-]{1,15}://[^\s"\'<>\[\]]+~i', fn (array $m): string => $replace($m[0], 'url'), $s) ?? $s;   // URLs
    $s = preg_replace_callback('~"([^"\n]{1,1000})"|(?<![\w\'])\'([^\'\n]{1,1000})\'(?![\w\'])|\[([^\[\]\n]{1,1000})\]|`([^`\n]{1,1000})`'
        . '|\xe2\x80\x9c(.{1,1000}?)\xe2\x80\x9d|\xe2\x80\x98(.{1,1000}?)\xe2\x80\x99|\xc2\xab(.{1,1000}?)\xc2\xbb~',
        function (array $m) use ($replace): string {
            $inner = implode('', array_slice($m, 1));       // the one group that matched
            return logsLooksLikePath($inner) ? str_replace($inner, $replace($inner, 'quoted'), $m[0]) : $m[0];
        }, $s) ?? $s;
    // absolute: with the words up to the last one with a slash, until a word with a colon (the message goes on
    // there), and then a file name of a few words with an ending (/mnt/cache/Filme/Der Name der Rose.mkv)
    $s = preg_replace_callback('~(?<![^\s=(:,])/(?=[^\s/])[^\s"<>]*(?:(?:\s+[^\s"<>:/]+)*\s+[^\s"<>:]*/[^\s"<>]*)*'
        . '(?:(?:\s+[^\s"<>:/]+){0,5}?\s+[^\s"<>:/]+\.[a-z][a-z0-9]{1,4}(?=[\s:,;)]|$))?~i',
        function (array $m) use ($replace): string {
            $tail = logsTrailing($m[0]);
            return $replace(substr($m[0], 0, strlen($m[0]) - strlen($tail)), 'absolute') . $tail;
        }, $s) ?? $s;
    // relative: a word with a slash, letters before and after its last slash, at least 4 characters
    return preg_replace_callback('~(?<![^\s=(:,])[^\s"<>/\[\]]+/[^\s"<>]+~', function (array $m) use ($replace): string {
        $tail = logsTrailing($m[0]);
        $word = substr($m[0], 0, strlen($m[0]) - strlen($tail));
        $cut = (int) strrpos($word, '/');
        return strlen($word) >= 4 && preg_match('~[a-z]~i', substr($word, $cut + 1)) && preg_match('~[a-z]~i', substr($word, 0, $cut))
            ? $replace($word, 'relative') . $tail : $m[0];
    }, $s) ?? $s;
}

/** Does what stands in quotes look like a path or a file name? Starts like one, or a slash and no space, or a slash and a file ending, or name.ext */
function logsLooksLikePath(string $c): bool
{
    if (preg_match('~^(?:[a-z]:[\\\\/]|\~?/|\.\.?/)~i', $c)) {
        return true;
    }
    $ext = '\.[a-z0-9]*[a-z][a-z0-9]*$';
    if (str_contains(strtr($c, '\\', '/'), '/') && preg_match('~[a-z]~i', $c)) {
        return !preg_match('~\s~', $c) || preg_match("~$ext~i", $c);
    }
    return (bool) preg_match('~^[^\s/\\\\]+\.[a-z][a-z0-9]{0,5}$~i', $c);
}

/** The punctuation a path doesn't end with (the sentence's: "…/x.mkv:", "(/x)") */
function logsTrailing(string $s): string
{
    return preg_match('~[.,:;)\]\']+$~', $s, $m) ? $m[0] : '';
}

/**
 * At most $max bytes, not ending inside a UTF-8 character (a sample is the
 * beginning of the line the reader shows).
 * @return array{0: string, 1: bool}
 */
function logsCut(string $s, int $max): array
{
    if (strlen($s) <= $max) {
        return [$s, false];
    }
    $t = substr($s, 0, $max);
    return [preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $t) ?? $t, true];
}
