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
 * process started by the agent itself would be killed with the agent
 * container's cgroup. It also gets the host's network (the agent has none).
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

/** Runs a command right away in the host's network (the agent itself has none) */
function hostNet(array $command, int $timeout = 60): array
{
    return run(array_merge(['nsenter', '--target', '1', '--net', '--'], $command), $timeout);
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
        if (!empty(readJson(OFFICE_DIR . "/public/desks/$id/desk.json")['always'])) {
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
