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
