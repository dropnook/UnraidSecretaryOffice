<?php
declare(strict_types=1);

/*
 * What the server has: installed plugins, Docker containers, Unraid's own
 * settings. Desks use this for their checks (desk(..., ['checks' => …])),
 * the caretaker collects all checks and tells the user what is missing.
 */

const HOUSE_PLUGINS = '/boot/config/plugins';
const HOUSE_CACHE   = 20;          // seconds — the agent runs for weeks, one tour of checks shares one look

/**
 * One finding of a check.
 *   level   required     the desk can't do (part of) its job without it
 *           recommended  helps, but not a must
 *           hint         worth knowing, nothing to fix
 *   ok      true / false / null (could not tell)
 *   link    where to fix it: in Unraid's web UI (plugins, apps, docker,
 *           userscripts, notifications, settings — the caretaker builds the
 *           URL) or a page of the office itself ("#/backup/setup")
 * The text comes from the desk's language file: check.<id> (what should be
 * so) and check.<id>_how (what to do when it isn't), both with $params.
 */
function finding(string $id, string $level, ?bool $ok, array $params = [], ?string $link = null): array
{
    return ['id' => $id, 'level' => $level, 'ok' => $ok, 'params' => $params, 'link' => $link];
}

/** @return array<string, array{name:string, author:?string, version:?string}> installed plugins by .plg name */
function housePlugins(): array
{
    static $cache = null, $at = 0;
    if ($cache !== null && time() - $at < HOUSE_CACHE) {
        return $cache;
    }
    $cache = [];
    $at = time();
    foreach (glob(HOUSE_PLUGINS . '/*.plg') ?: [] as $file) {
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        $entity = fn (string $key) => preg_match('/<!ENTITY\s+' . $key . '\s+"([^"]*)"/', $head, $m) ? $m[1] : null;
        $name = basename($file, '.plg');
        $cache[$name] = ['name' => $name, 'author' => $entity('author'), 'version' => $entity('version')];
    }
    return $cache;
}

function housePlugin(string $name): bool
{
    return isset(housePlugins()[$name]);
}

/** @return array<string, array{name:string, image:string, running:bool}> all containers */
function houseContainers(): array
{
    static $cache = null, $at = 0;
    if ($cache !== null && time() - $at < HOUSE_CACHE) {
        return $cache;
    }
    $cache = [];
    $at = time();
    [$exit, $out] = run(['docker', 'ps', '-a', '--format', '{{.Names}}\t{{.Image}}\t{{.State}}'], 20);
    if ($exit === 0) {
        foreach (rows($out) as $f) {
            if (count($f) >= 3) {
                $cache[$f[0]] = ['name' => $f[0], 'image' => $f[1], 'running' => $f[2] === 'running'];
            }
        }
    }
    return $cache;
}

/** docker inspect of one container, or null */
function houseInspect(string $name): ?array
{
    [$exit, $out] = run(['docker', 'inspect', $name], 20);
    $j = $exit === 0 ? json_decode($out, true) : null;
    return is_array($j[0] ?? null) ? $j[0] : null;
}

const HOUSE_AUTOSTART        = '/var/lib/docker/unraid-autostart';                  // Unraid's Docker autostart list (RAM, in docker.img's folder)
const HOUSE_COMPOSE_CFG      = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const HOUSE_COMPOSE_PROJECTS = '/boot/config/plugins/compose.manager/projects';     // Compose Manager's PROJECTS_FOLDER by default

/**
 * Unraid's Docker autostart list: what rc.docker starts, in this order, whenever Docker comes up (after a boot or
 * an array start — every container was stopped at the array stop). One line per template container, `name` or
 * `name delay` (the seconds Unraid waits after starting it; the Docker page's Autostart switch writes it).
 *
 * @return array<string, int> container name => delay in seconds
 */
function houseAutostart(string $file = HOUSE_AUTOSTART): array
{
    $list = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (($parts[0] ?? '') !== '') {
            $list[$parts[0]] = (int) ($parts[1] ?? 0);
        }
    }
    return $list;
}

/**
 * Whether Compose Manager brings a stack up by itself when Docker starts (its docker_started event runs
 * `compose up` for every stack whose folder under PROJECTS_FOLDER has `autostart` = true): the folder whose
 * `project_name` — the containers' label com.docker.compose.project — is $project (older folders only have
 * `name`). null = Compose Manager doesn't know that stack, or isn't installed: nothing the office knows starts it.
 *
 * @param string|null $root  the projects folder (tests); null = Compose Manager's own setting
 */
function houseComposeAutostart(string $project, ?string $root = null): ?bool
{
    if ($root === null) {
        if (!housePlugin('compose.manager')) {
            return null;
        }
        $root = (string) (readCfg(HOUSE_COMPOSE_CFG)['PROJECTS_FOLDER'] ?? '');
        if ($root === '' || $root[0] !== '/') {
            $root = HOUSE_COMPOSE_PROJECTS;
        }
    }
    foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = trim((string) @file_get_contents("$dir/project_name"));
        if ($name === '') {
            $name = trim((string) @file_get_contents("$dir/name")) ?: basename($dir);
        }
        if (strcasecmp($name, $project) === 0) {                 // compose lower-cases a project's name
            return trim((string) @file_get_contents("$dir/autostart")) === 'true';
        }
    }
    return null;
}

/** The address of Unraid's own web UI (from Unraid's config), e.g. for the server's IP in a ready-made config */
function houseGuiUrl(): ?string
{
    $ident = readCfg('/boot/config/ident.cfg');
    $ssl = in_array(strtolower($ident['USE_SSL'] ?? 'no'), ['yes', 'auto'], true);
    $ip = null;
    foreach (@file('/var/local/emhttp/network.ini', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^IPADDR:0="(\d+\.\d+\.\d+\.\d+)"/', trim($line), $m)) {
            $ip = $m[1];          // first interface, first address: the one the web UI listens on
            break;
        }
    }
    if (!$ip) {
        return null;
    }
    $port = $ssl ? ($ident['PORTSSL'] ?? '443') : ($ident['PORT'] ?? '80');
    $default = $ssl ? '443' : '80';
    return ($ssl ? 'https://' : 'http://') . $ip . ($port !== $default ? ":$port" : '');
}

const HOST_LAUNCH_MARK = '# written by the Unraid Secretary Office agent';     // in every job it hands to atd (the night watchman knows them by it)

/**
 * Hands a command to the host's atd, so it lives on without the agent: a
 * process started by the agent itself would be stopped with it (agent.sh
 * stops the agent's whole session when the array stops or the plugin updates).
 * Scripts should be run through their interpreter (bash, python3): right
 * after an edit over SMB, Samba may still hold the file open ("Text file busy").
 *
 * @param string      $job     name of the job file in the run directory
 * @param list<string> $args   the command, no shell
 * @param array<string,string> $env  extra environment
 * @param string|null $output  file for stdout and stderr (default: discarded)
 */
function hostLaunch(string $job, array $args, array $env = [], ?string $output = null, string $cwd = '/'): void
{
    // the job file's name and the variables' names go into the script unquoted: only plain names
    if (!preg_match('/^[a-z][a-z0-9_-]{0,63}\z/', $job) || !$args) {
        throw new Problem('command_failed', ['detail' => "bad job $job"]);
    }
    $file = RUN_DIR . "/$job.sh";
    $line = implode(' ', array_map(fn ($a) => escapeshellarg((string) $a), $args));
    $exports = '';
    foreach ($env as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Z_][A-Z0-9_]{0,63}\z/', $k)) {
            throw new Problem('command_failed', ['detail' => "bad variable $k"]);
        }
        $exports .= $k . '=' . escapeshellarg((string) $v) . "\nexport $k\n";
    }
    $out = $output !== null ? escapeshellarg($output) : '/dev/null';
    $script = "#!/bin/sh\n" . HOST_LAUNCH_MARK . "\n"
            . "PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin\nexport PATH\n$exports"
            . 'cd ' . escapeshellarg($cwd) . "\n"
            . "exec $line </dev/null >$out 2>&1\n";
    @mkdir(RUN_DIR, 0700, true);
    if (@file_put_contents($file, $script) === false) {
        throw new Problem('command_failed', ['detail' => "cannot write $file"]);
    }
    [$exit, , $err] = run(['at', '-M', '-f', $file, 'now'], 20);
    if ($exit !== 0) {
        throw new Problem('host_launch_failed', ['detail' => trim($err)]);
    }
}

/**
 * Runs a command that needs the network (curl, git, notify's mail and push) right away. The agent
 * runs on the host, so this is run() — kept as the one name for "this goes out to the network".
 */
function hostNet(array $command, int $timeout = 60): array
{
    return run($command, $timeout);
}

// ===================================================================== Unraid's notifications

/*
 * What the office has to tell even when nobody looks at the page goes to
 * Unraid's notifications (the bell, and mail or push as set under Settings →
 * Notifications), in the style of the backup engine (ub_notify in
 * backup/lib/common.sh): the event "Unraid Secretary Office", the subject
 * behind "Unraid Secretary Office: " (Unraid puts the server's name in front).
 * Texts come from the desks' language files, in Unraid's language when the
 * office speaks it (officeNotifyText(), officeNotifyLang()).
 */
const OFFICE_NOTIFY_BIN   = '/usr/local/emhttp/webGui/scripts/notify';
const OFFICE_NOTIFY_EVENT = 'Unraid Secretary Office';

/**
 * Sends one notification. $level normal|warning|alert; $message the long
 * text (its lines become Unraid's "\n", which mail, push agents and the
 * archive turn into line breaks); $link where a click leads (officeNotifyLink()).
 * Does nothing without Unraid's notify script. OFFICE_NOTIFY_BIN in the
 * environment points to a stand-in — for the tests only.
 */
function officeNotify(string $subject, string $description, string $level = 'normal', string $message = '', ?string $link = null): bool
{
    static $last = 0;
    $bin = getenv('OFFICE_NOTIFY_BIN') ?: OFFICE_NOTIFY_BIN;
    if (!is_executable($bin)) {
        return false;
    }
    $flat = fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', $s));
    $args = [$bin, '-e', OFFICE_NOTIFY_EVENT, '-s', OFFICE_NOTIFY_EVENT . ': ' . $flat($subject), '-d', $flat($description),
             '-i', in_array($level, ['normal', 'warning', 'alert'], true) ? $level : 'normal'];
    if (trim($message) !== '') {
        $args = [...$args, '-m', str_replace(["\r\n", "\r", "\n"], '\n', trim($message))];
    }
    if ($link !== null && $link !== '') {
        $args = [...$args, '-l', $link];
    }
    // Unraid names a notification after its event and the second its script reads the clock — somewhere between
    // our call's start and its end: one more in that second would overwrite it (the night shift lost one of three
    // sent in a row on 2026-10-07). $last is the second the last call ENDED in; a new one starts only after it.
    if (time() <= $last) {
        usleep((int) ((1 - fmod(microtime(true), 1)) * 1e6) + 10000);
    }
    [$exit] = hostNet($args, 30);
    $last = time();
    return $exit === 0;
}

/** Where a click on a notification leads: a page of the office ("#/caretaker") inside Unraid */
function officeNotifyLink(string $hash = ''): string
{
    return officeMenuUrl(officeMenuPlace()) . $hash;
}

/** Unraid's language (Settings → Display settings), when the office speaks it — otherwise English */
function officeNotifyLang(string $cfg = '/boot/config/plugins/dynamix/dynamix.cfg'): string
{
    $locale = (string) (readCfg($cfg, true)['display']['locale'] ?? '');
    $code = strtolower((string) strtok($locale, '_-'));
    return preg_match('/^[a-z]{2,3}$/', $code) && is_file(OFFICE_WEB . "/lang/$code.json") ? $code : 'en';
}

/**
 * A text for a notification from a desk's language file ('' = the office's
 * own): in $lang, else English, else ''. {placeholders} from $params, plurals
 * ({"one": …, "other": …}) by $params['n'].
 */
function officeNotifyText(string $desk, string $key, array $params = [], string $lang = 'en'): string
{
    if ($desk !== '' && !preg_match('/^[a-z][a-z0-9_-]*$/', $desk)) {
        return '';
    }
    if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $lang)) {
        $lang = 'en';
    }
    $base = OFFICE_WEB . ($desk === '' ? '' : "/desks/$desk") . '/lang';
    $text = null;
    foreach (array_unique([$lang, 'en']) as $code) {
        $text = (readJson("$base/$code.json") ?? [])[$key] ?? null;
        if ($text !== null) {
            break;
        }
    }
    if (is_array($text)) {
        $text = (($params['n'] ?? null) === 1 ? ($text['one'] ?? null) : null) ?? $text['other'] ?? '';
    }
    return (string) preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0],
        is_string($text) ? $text : '');
}

// ===================================================================== cron

/** The User Scripts plugin's places on the flash — the user's scripts (Ms. Dustdevil, Jack Emby look at them) */
const US_DIR      = '/boot/config/plugins/user.scripts';
const US_SCHEDULE = US_DIR . '/schedule.json';

/** A cron expression of five plain fields (as crond and User Scripts' "Custom" take it) */
function cronValid(string $cron): bool
{
    $f = preg_split('/\s+/', trim($cron));
    if (count($f) !== 5) {
        return false;
    }
    // minute, hour, day of month, month, day of week (0 and 7 = Sunday)
    foreach ([[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]] as $i => [$lo, $hi]) {
        foreach (explode(',', $f[$i]) as $part) {
            if (!preg_match('#^(\*|(\d+)(-(\d+))?)(/(\d+))?$#', $part, $m)) {
                return false;
            }
            foreach ([$m[2] ?? '', $m[4] ?? ''] as $n) {
                if ($n !== '' && ((int) $n < $lo || (int) $n > $hi)) {
                    return false;
                }
            }
            if (isset($m[6]) && $m[6] !== '' && ((int) $m[6] < 1 || (int) $m[6] > $hi)) {
                return false;
            }
        }
    }
    return true;
}

// ===================================================================== the office's schedules

/*
 * Jobs run on a schedule even when nobody has the office open: Mr.
 * Backupsy's nightly run, Ms. Snapshotini's plans (every 5 minutes) and Jack
 * Emby's EmbyCache and media gather.
 * The office writes them into its own cron file on the flash — Unraid adds
 * every installed plugin's *.cron to root's crontab (update_cron) — and
 * scripts/job.sh runs them only while the array is started.
 */
const OFFICE_CRON = '/boot/config/plugins/' . OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cron';
const OFFICE_JOBS = ['backup', 'snapshots', 'embycache', 'gather'];     // in this order in the cron file

function officeJobCommand(string $job): string
{
    return 'bash ' . OFFICE_DIR . "/scripts/job.sh $job > /dev/null 2>&1";
}

/** @return array<string,string>  job => cron, as the plugin's cron file has them */
function officeCronLines(string $file = OFFICE_CRON): array
{
    $found = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        $line = trim($line);
        foreach (OFFICE_JOBS as $job) {
            if ($line !== '' && $line[0] !== '#' && str_ends_with($line, ' ' . officeJobCommand($job))) {
                $found[$job] = implode(' ', array_slice(preg_split('/\s+/', $line), 0, 5));
            }
        }
    }
    return $found;
}

/** A job's schedule: its line in the plugin's cron file */
function officeJobSchedule(string $job): array
{
    $cron = officeCronLines()[$job] ?? null;
    return ['script' => true, 'frequency' => $cron !== null ? 'custom' : 'disabled', 'custom' => $cron, 'enabled' => $cron !== null];
}

/**
 * Sets (cron) or switches off (null) a job's schedule: its line in the cron
 * file, the others stay; $file and $apply (update_cron) are there for the
 * tests.
 *
 * @return bool  whether the live crontab has it that way afterwards
 */
function officeJobSetSchedule(string $job, ?string $cron, string $file = OFFICE_CRON, bool $apply = true): bool
{
    if (!in_array($job, OFFICE_JOBS, true)) {
        throw new Problem('unknown_target', ['target' => $job]);
    }
    if ($cron !== null) {
        $cron = preg_replace('/\s+/', ' ', trim($cron));
        if (!cronValid($cron)) {
            throw new Problem('bad_cron', ['cron' => $cron]);
        }
    }
    $lines = officeCronLines($file);
    if ($cron === null) {
        unset($lines[$job]);
    } else {
        $lines[$job] = $cron;
    }
    if ($lines) {
        $text = "# Unraid Secretary Office - written by the office, change it there\n";
        foreach (OFFICE_JOBS as $j) {
            if (isset($lines[$j])) {
                $text .= $lines[$j] . ' ' . officeJobCommand($j) . "\n";
            }
        }
        writeAtomic($file, $text, 0600, 0, 0);
    } else {
        @unlink($file);
    }
    if (!$apply) {
        return true;
    }
    [$exit, , $err] = run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);   // its first line is no shebang
    if ($exit !== 0) {
        throw new Problem('command_failed', ['detail' => 'update_cron: ' . trim($err)]);
    }
    return ($cron !== null) === str_contains((string) @file_get_contents('/etc/cron.d/root'), officeJobCommand($job));
}

// ===================================================================== staff

// desks that went into another one: old id => the desk that does their work now — the same as
// OFFICE_DESKS_MERGED in src/staff.php, which rewrites staff.json once (tests compare the two)
const STAFF_MERGED = ['whereabouts' => 'cleanup'];        // 2026-10: Ms. Whereabouts' work is Ms. Dustdevil's

/**
 * The desks that work in the office: data/office/staff.json (written by the
 * web part, see src/staff.php) plus those that are always there. A desk that
 * went into another one counts as that one until the web part rewrote the list.
 *
 * @return list<string>
 */
function staffHired(): array
{
    $hired = staffMergedIds(array_keys((array) ((readJson(DATA_DIR . '/office/staff.json') ?? [])['hired'] ?? [])), desks());
    foreach (array_keys(desks()) as $id) {
        if (!empty(readJson(OFFICE_WEB . "/desks/$id/desk.json")['always'])) {
            $hired[] = $id;
        }
    }
    return array_values(array_unique(array_filter($hired, fn ($id) => is_string($id) && isset(desks()[$id]))));
}

/** Hired ids with each desk that went into another one (and is gone) as that one — STAFF_MERGED */
function staffMergedIds(array $ids, array $desks): array
{
    return array_map(fn ($id) => is_string($id) && !isset($desks[$id]) && isset(STAFF_MERGED[$id]) ? STAFF_MERGED[$id] : $id, $ids);
}

/**
 * Would a desk fit this server? A desk's 'fit' says so:
 * ['ok' => bool, 'why' => code, 'params' => [...]] — the texts are the desk's
 * own (fit.<why>), told by the caretaker when he suggests whom to hire.
 */
function fit(bool $ok, string $why, array $params = []): array
{
    return ['ok' => $ok, 'why' => $why, 'params' => $params];
}

/** Pools and disks that can take snapshots (ZFS datasets mounted under /mnt, btrfs under /mnt) */
function houseSnapshotFilesystems(): array
{
    $found = ['zfs' => [], 'btrfs' => []];
    foreach (mountTable() as $m) {
        if (isset($found[$m['fs']]) && preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !preg_match('/^(user0?|disks|remotes|addons|rootshare)$/', $x[1])) {
            $found[$m['fs']][$x[1]] = true;
        }
    }
    return ['zfs' => array_keys($found['zfs']), 'btrfs' => array_keys($found['btrfs'])];
}
