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

/** The address of Unraid's own web UI, for links (the agent has no network of its own, so from Unraid's config) */
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

/**
 * Hands a command to the host's atd, so it lives on without the agent: a
 * process started by the agent itself would be stopped with it (the stack's
 * container cgroup, the plugin's process group when the array stops). It also
 * gets the host's network (the agent in the stack has none).
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
    $file = RUN_DIR . "/$job.sh";
    $line = implode(' ', array_map('escapeshellarg', $args));
    $exports = '';
    foreach ($env as $k => $v) {
        $exports .= $k . '=' . escapeshellarg((string) $v) . "\nexport $k\n";
    }
    $out = $output !== null ? escapeshellarg($output) : '/dev/null';
    $script = "#!/bin/sh\n# written by the Unraid Secretary Office agent\n"
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

/** Runs a command right away in the host's network (the agent in the stack has none; the plugin's has it) */
function hostNet(array $command, int $timeout = 60): array
{
    $own = @readlink('/proc/self/ns/net');
    if ($own !== false && $own === @readlink('/proc/1/ns/net')) {
        return run($command, $timeout);
    }
    return run(array_merge(['nsenter', '--target', '1', '--net', '--'], $command), $timeout);
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
 * Does nothing without Unraid's notify script. Runs in the host's network, so
 * mail and push get out from the stack's agent too. OFFICE_NOTIFY_BIN in the
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
    // Unraid names a notification after its event and second: a second one within the same second would be lost
    if (time() === $last) {
        usleep((int) ((1 - fmod(microtime(true), 1)) * 1e6) + 10000);
    }
    $last = time();
    [$exit] = hostNet($args, 30);
    return $exit === 0;
}

/** Where a click on a notification leads: a page of the office ("#/caretaker") inside Unraid; null in the stack (its own address) */
function officeNotifyLink(string $hash = ''): ?string
{
    return AS_PLUGIN ? officeMenuUrl(officeMenuPlace()) . $hash : null;
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

// ===================================================================== User Scripts schedule

const US_DIR      = '/boot/config/plugins/user.scripts';
const US_SCHEDULE = US_DIR . '/schedule.json';
const US_CRON     = US_DIR . '/customSchedule.cron';
const US_RUNTIME  = '/tmp/user.scripts/schedule.json';
const US_START    = '/usr/local/emhttp/plugins/user.scripts/startCustom.php';

/** A cron expression as User Scripts' "Custom" takes it: five plain fields */
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

/**
 * Sets (cron) or switches off (null) the schedule of one User Scripts entry —
 * the way the plugin's own "Apply" does it: its entry in schedule.json (also
 * the plugin's copy in /tmp), its line in customSchedule.cron, update_cron.
 * Every other entry and line stays exactly as it is.
 * The paths are parameters so a test can run against copies; $apply = false
 * skips update_cron.
 *
 * @return bool  whether the line is in the live crontab afterwards (null cron: whether it is gone)
 */
function userScriptSchedule(string $name, ?string $cron, string $schedule = US_SCHEDULE, string $cronFile = US_CRON,
                            ?string $runtime = US_RUNTIME, bool $apply = true): bool
{
    $script = dirname($schedule) . "/scripts/$name/script";
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) || !is_file($script)) {
        throw new Problem('no_user_script', ['name' => $name]);
    }
    if ($cron !== null) {
        $cron = preg_replace('/\s+/', ' ', trim($cron));
        if (!cronValid($cron)) {
            throw new Problem('bad_cron', ['cron' => $cron]);
        }
    }
    $all = json_decode((string) @file_get_contents($schedule), true);
    if (!is_array($all)) {
        $all = [];
    }
    $old = is_array($all[$script] ?? null) ? $all[$script] : [];
    $all[$script] = [
        'script'    => $script,
        'frequency' => $cron !== null ? 'custom' : 'disabled',
        'id'        => $old['id'] ?? 'schedule' . $name,
        'custom'    => $cron ?? (string) ($old['custom'] ?? ''),     // keeps the last time, as the plugin does
    ];
    $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    writeAtomic($schedule, $json, 0600, 0, 0);
    if ($runtime !== null && is_dir(dirname($runtime))) {
        writeAtomic($runtime, $json, 0600, 0, 0);
    }

    $start = US_START;
    $mine = " $start $script ";
    $lines = [];
    foreach (explode("\n", (string) @file_get_contents($cronFile)) as $line) {
        if (trim($line) !== '' && !str_starts_with($line, '#') && !str_contains($line, $mine)) {
            $lines[] = $line;
        }
    }
    if ($cron !== null) {
        $lines[] = "$cron $start $script > /dev/null 2>&1";
    }
    if ($lines) {
        writeAtomic($cronFile, "# Generated cron schedule for user.scripts\n" . implode("\n", $lines) . "\n\n", 0600, 0, 0);
    } else {
        @unlink($cronFile);
    }
    if (!$apply) {
        return true;
    }
    // through bash, as the plugin does (via a shell): update_cron's first line is "#/bin/bash", not a shebang
    [$exit, , $err] = run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);
    if ($exit !== 0) {
        throw new Problem('command_failed', ['detail' => 'update_cron: ' . trim($err)]);
    }
    $live = (string) @file_get_contents('/etc/cron.d/root');
    return ($cron !== null) === str_contains($live, $mine);
}

// ===================================================================== the office's schedules

/*
 * Jobs run on a schedule even when nobody has the office open: Mr.
 * Backupsy's nightly run, Ms. Snapshotini's plans (every 5 minutes) and Jack
 * Emby's EmbyCache and media gather.
 * As a plugin the office writes them into its own cron file on the flash —
 * Unraid adds every installed plugin's *.cron to root's crontab (update_cron)
 * — and scripts/job.sh runs them only while the array is started. In the
 * stack they are User Scripts entries (userScriptSchedule()).
 */
const OFFICE_CRON = '/boot/config/plugins/' . OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cron';
const OFFICE_JOBS = ['backup' => 'unraid-secretary-office_backup', 'snapshots' => 'unraid-secretary-office_snapshots',
                     'embycache' => 'unraid-secretary-office_embycache', 'gather' => 'unraid-secretary-office_gather'];  // job => its User Scripts entry

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
        foreach (array_keys(OFFICE_JOBS) as $job) {
            if ($line !== '' && $line[0] !== '#' && str_ends_with($line, ' ' . officeJobCommand($job))) {
                $found[$job] = implode(' ', array_slice(preg_split('/\s+/', $line), 0, 5));
            }
        }
    }
    return $found;
}

/** A job's schedule: the plugin's cron file, in the stack its User Scripts entry */
function officeJobSchedule(string $job): array
{
    if (!AS_PLUGIN) {
        return ['via' => 'user_scripts'] + backupScheduleOf(OFFICE_JOBS[$job]);
    }
    $cron = officeCronLines()[$job] ?? null;
    return ['via' => 'office', 'script' => true, 'frequency' => $cron !== null ? 'custom' : 'disabled', 'custom' => $cron, 'enabled' => $cron !== null];
}

/**
 * Sets (cron) or switches off (null) a job's schedule. As a plugin: its line
 * in the cron file, the others stay; $file and $apply (update_cron) are there
 * for the tests.
 *
 * @return bool  whether the live crontab has it that way afterwards
 */
function officeJobSetSchedule(string $job, ?string $cron, string $file = OFFICE_CRON, bool $apply = true): bool
{
    if (!AS_PLUGIN && $file === OFFICE_CRON) {
        return userScriptSchedule(OFFICE_JOBS[$job], $cron);
    }
    if (!isset(OFFICE_JOBS[$job])) {
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
        foreach (array_keys(OFFICE_JOBS) as $j) {
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

/**
 * Moved from the stack to the plugin: the office's User Scripts entries hand
 * their schedule over to the plugin's cron file and go away (each is only a
 * three-line call into the old folder). Waits while one is running.
 */
function officeJobsFromUserScripts(): void
{
    if (!AS_PLUGIN) {
        return;
    }
    foreach (OFFICE_JOBS as $job => $name) {
        $dir = US_DIR . "/scripts/$name";
        if (!is_dir($dir)) {
            continue;
        }
        if (file_exists("/tmp/user.scripts/running/$name") || ($job === 'backup' && (backupScriptState()['running'] ?? false))) {
            continue;                              // next time
        }
        $old = backupScheduleOf($name);
        if ($old['enabled'] && $old['frequency'] === 'custom' && cronValid((string) $old['custom']) && !isset(officeCronLines()[$job])) {
            officeJobSetSchedule($job, $old['custom'], OFFICE_CRON, false);
        }
        if ($old['enabled']) {
            userScriptSchedule($name, null, US_SCHEDULE, US_CRON, US_RUNTIME, false);
        }
        foreach (glob("$dir/{,.}*", GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        logLine("User Scripts: $name handed its schedule (" . ($old['enabled'] ? $old['custom'] : 'off') . ') over to the plugin');
        run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);
    }
}

// ===================================================================== staff

/**
 * The desks that work in the office: data/office/staff.json (written by the
 * web part, see src/staff.php) plus those that are always there.
 *
 * @return list<string>
 */
function staffHired(): array
{
    $hired = array_keys((array) ((readJson(DATA_DIR . '/office/staff.json') ?? [])['hired'] ?? []));
    foreach (array_keys(desks()) as $id) {
        if (!empty(readJson(OFFICE_WEB . "/desks/$id/desk.json")['always'])) {
            $hired[] = $id;
        }
    }
    return array_values(array_unique(array_filter($hired, fn ($id) => is_string($id) && isset(desks()[$id]))));
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

/**
 * A path in appdata as /mnt/user/… when that is the same file: scripts that
 * point there keep working when the share moves to another pool.
 */
function userSharePath(string $path): string
{
    if (preg_match('#^/mnt/(?!user/)[^/]+/(.+)$#', $path, $m)) {
        $user = "/mnt/user/{$m[1]}";
        $a = @stat($path);
        $b = @stat($user);
        if ($a && $b && $a['ino'] === $b['ino'] && $a['size'] === $b['size']) {
            return $user;
        }
    }
    return $path;
}

// ===================================================================== User Scripts names

/** Every User Scripts entry of the office starts like this */
const US_PREFIX = 'unraid-secretary-office_';

/** Entries that had another name before (old => new) */
const US_RENAMED = [
    'unraid-backup'           => 'unraid-secretary-office_backup',
    'unraid-office-snapshots' => 'unraid-secretary-office_snapshots',
];

/**
 * Moves the office's User Scripts entries to their current names, once:
 * the folder, its schedule (schedule.json, also the plugin's copy in /tmp),
 * its cron line, and its place in a User Scripts Enhanced category. Waits
 * while an entry is running.
 */
function userScriptsMigrate(): void
{
    $base = US_DIR . '/scripts';
    foreach (US_RENAMED as $old => $new) {
        if (!is_dir("$base/$old") || file_exists("$base/$new")) {
            continue;
        }
        if (file_exists("/tmp/user.scripts/running/$old") || ($old === 'unraid-backup' && (backupScriptState()['running'] ?? false))) {
            continue;                              // next time
        }
        if (!@rename("$base/$old", "$base/$new")) {
            logLine("User Scripts: could not rename $old to $new");
            continue;
        }
        @file_put_contents("$base/$new/name", $new);
        $oldPath = "$base/$old/script";
        $newPath = "$base/$new/script";

        foreach (array_filter([US_SCHEDULE, is_file(US_RUNTIME) ? US_RUNTIME : null]) as $file) {
            $all = json_decode((string) @file_get_contents($file), true);
            if (!is_array($all) || !isset($all[$oldPath])) {
                continue;
            }
            $out = [];
            foreach ($all as $key => $entry) {
                if ($key === $oldPath) {
                    $entry['script'] = $newPath;
                    $entry['id'] = 'schedule' . str_replace(' ', '', $new);
                    $key = $newPath;
                }
                $out[$key] = $entry;
            }
            writeAtomic($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600, 0, 0);
        }
        $cron = (string) @file_get_contents(US_CRON);
        if (str_contains($cron, " $oldPath ")) {
            writeAtomic(US_CRON, str_replace(" $oldPath ", " $newPath ", $cron), 0600, 0, 0);
            run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);
        }
        // User Scripts Enhanced keeps its categories by "name<folder>"
        $cats = '/boot/config/plugins/user.scripts.enhanced/categories.json';
        $json = (string) @file_get_contents($cats);
        if ($json !== '' && str_contains($json, '"name' . $old . '"')) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $fix = function (array $list) use (&$fix, $old, $new): array {
                    foreach ($list as &$c) {
                        $c['scripts'] = array_map(fn ($s) => $s === "name$old" ? "name$new" : $s, (array) ($c['scripts'] ?? []));
                        $c['subcategories'] = $fix((array) ($c['subcategories'] ?? []));
                    }
                    return $list;
                };
                writeAtomic($cats, json_encode($fix($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0644, 0, 0);
            }
        }
        logLine("User Scripts: $old is now $new");
    }
}
